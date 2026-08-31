<?php
declare(strict_types=1);

/**
 * app/services/payment/CieloRetornoService.php
 *
 * Aplica o "Post de Notificação" da Cielo.
 *
 * A NOTIFICAÇÃO NÃO PROVA NADA. O corpo é
 * `{"PaymentId": "...", "ChangeType": 1}` e não vem assinado — a Cielo só
 * oferece headers fixos configuráveis no painel dela, que qualquer um que os
 * descubra pode repetir. Então o aviso serve para UMA coisa: dizer que algo
 * mudou. O status real vem de consultar a API, nunca do que chegou no corpo.
 *
 * Sem isso, um POST forjado com um PaymentId qualquer aprovaria pedido.
 *
 * Espelha o MercadoPagoRetornoService de propósito: dois caminhos aplicando
 * retorno de formas diferentes divergem, e divergir aqui é liberar mercadoria
 * de pagamento que não entrou.
 */
class CieloRetornoService
{
    /** ChangeType 1 = mudança de status da transação. É o que nos interessa. */
    public const MUDANCA_DE_STATUS = 1;

    private PDO $db;
    private CieloAdapter $cielo;
    private PagamentoNotificador $notificador;

    public function __construct(
        ?PDO $db = null,
        ?CieloAdapter $cielo = null,
        ?PagamentoNotificador $n = null
    ) {
        $this->db          = $db ?? Database::getInstance()->getConnection();
        $this->cielo       = $cielo ?? new CieloAdapter();
        $this->notificador = $n ?? new PagamentoNotificador($this->db);
    }

    /**
     * @return array{acao:string, status:?string, porta:?string}
     */
    public function aplicar(string $paymentId): array
    {
        // O ÚNICO dado confiável da notificação é o id. Tudo mais vem daqui.
        $c = $this->cielo->consultar($paymentId);

        if ($c->porta === PagamentoClassificacao::ERRO_TECNICO
            || $c->porta === PagamentoClassificacao::INDISPONIVEL) {
            LogService::warning('Nao foi possivel consultar pagamento na Cielo', [
                'payment_id' => $paymentId, 'motivo' => $c->mensagemAdquirente,
            ], 'pagamento');
            return ['acao' => 'erro', 'status' => null, 'porta' => $c->porta];
        }

        $novo = $this->statusLocal($c->porta);

        $st = $this->db->prepare(
            'SELECT id, pedido_id, order_id_loja, cliente_id, valor_centavos, metodo, parcelas, status
               FROM pgto_transacoes WHERE charge_id = ? LIMIT 1'
        );
        $st->execute([$paymentId]);
        $tx = $st->fetch(PDO::FETCH_ASSOC);

        if (!$tx) {
            // Janela entre a Cielo criar a cobrança e nós gravarmos a
            // transação. Eles reenviam a cada 30 minutos, mais três
            // tentativas — a próxima encontra. Registrar importa para o caso
            // de NUNCA encontrar, que seria dinheiro sem rastro.
            LogService::warning('Retorno da Cielo sem transacao local', [
                'payment_id' => $paymentId, 'porta' => $c->porta, 'status' => $novo,
            ], 'pagamento');
            return ['acao' => 'sem_transacao', 'status' => $novo, 'porta' => $c->porta];
        }

        if ((string) $tx['status'] === $novo) {
            return ['acao' => 'inalterado', 'status' => $novo, 'porta' => $c->porta];
        }

        $this->db->prepare(
            'UPDATE pgto_transacoes
                SET status = ?, declined_code = ?, declined_message = ?,
                    pago_em = CASE WHEN ? = "aprovado" THEN NOW() ELSE pago_em END,
                    atualizado_em = NOW()
              WHERE id = ?'
        )->execute([
            $novo,
            $c->codigoAdquirente,
            mb_substr((string) ($c->mensagemAdquirente ?? ''), 0, 255),
            $novo,
            (int) $tx['id'],
        ]);

        LogService::audit('Transacao atualizada por retorno da Cielo', [
            'payment_id' => $paymentId,
            'pedido_id'  => (int) $tx['pedido_id'],
            'de'         => $tx['status'],
            'para'       => $novo,
            'detalhe'    => $c->codigoAdquirente,
        ]);

        // Só o dinheiro que ENTROU move o pedido. Um boleto vencido não deve
        // cancelar um pedido que o cliente ainda pode pagar de outra forma.
        if ($novo === 'aprovado') {
            if ((int) $tx['pedido_id'] > 0) {
                $this->moverPedido((int) $tx['pedido_id'], $paymentId);
            }

            $this->notificador->pedidoAprovado([
                'order_id_loja'  => (string) $tx['order_id_loja'],
                'pedido_id'      => (int) $tx['pedido_id'],
                'cliente_id'     => (int) $tx['cliente_id'],
                'valor_centavos' => (int) $tx['valor_centavos'],
                'metodo'         => (string) $tx['metodo'],
                'parcelas'       => (int) $tx['parcelas'],
            ], $c);
        }

        return ['acao' => 'aplicado', 'status' => $novo, 'porta' => $c->porta];
    }

    /** Porta do domínio → pgto_transacoes.status */
    private function statusLocal(string $porta): string
    {
        return match ($porta) {
            PagamentoClassificacao::APROVADO => 'aprovado',
            PagamentoClassificacao::PENDENTE => 'pendente',
            PagamentoClassificacao::INCERTO  => 'pendente',
            default                          => 'recusado',
        };
    }

    private function moverPedido(int $pedidoId, string $paymentId): void
    {
        $this->db->prepare(
            'UPDATE pedidos SET status_pedido = ?, atualizado_em = NOW() WHERE id = ?'
        )->execute(['pagamento_aprovado', $pedidoId]);

        try {
            $this->db->prepare(
                'INSERT INTO pedido_historico (pedido_id, status_novo, observacao, criado_em)
                 VALUES (?, ?, ?, NOW())'
            )->execute([$pedidoId, 'pagamento_aprovado', 'Confirmado pela Cielo (' . $paymentId . ')']);
        } catch (\Throwable $e) {
            // Histórico é auditoria: não pode derrubar a baixa do pagamento.
            LogService::exception($e, 'error', 'pagamento', ['acao' => 'historico_cielo']);
        }
    }
}
