<?php
declare(strict_types=1);

/**
 * app/services/payment/MercadoPagoRetornoService.php
 *
 * Aplica no banco o estado atual de um pedido do Mercado Pago.
 *
 * POR QUE ISTO EXISTE SEPARADO DO WEBHOOK:
 *   O mesmo trabalho é preciso em três situações — a notificação chegou, o
 *   checkout terminou em `incerto` e precisa reconsultar, ou alguém abriu a
 *   transação no painel e mandou atualizar. Deixar a regra no controller
 *   faria as três divergirem, e a que diverge é a que libera pedido errado.
 *
 * É IDEMPOTENTE DE PROPÓSITO:
 *   O Mercado Pago reenvia a notificação a cada 15 minutos até receber 200, e
 *   manda várias por pedido (criado, atualizado, aprovado). Aplicar o mesmo
 *   estado duas vezes não pode gerar dois avisos nem mover o pedido de novo —
 *   por isso só age quando o status MUDA.
 */
class MercadoPagoRetornoService
{
    private PDO $db;
    private MercadoPagoAdapter $mp;
    private PagamentoNotificador $notificador;

    public function __construct(?PDO $db = null, ?MercadoPagoAdapter $mp = null, ?PagamentoNotificador $n = null)
    {
        $this->db          = $db ?? Database::getInstance()->getConnection();
        $this->mp          = $mp ?? new MercadoPagoAdapter();
        $this->notificador = $n  ?? new PagamentoNotificador($this->db);
    }

    /**
     * Consulta o pedido na adquirente e reflete o resultado.
     *
     * @return array{acao:string, status:?string, porta:?string}
     *         acao: aplicado | inalterado | sem_transacao | erro
     */
    public function aplicar(string $orderId): array
    {
        $c = $this->mp->consultar($orderId);

        if ($c->porta === PagamentoClassificacao::ERRO_TECNICO
            || $c->porta === PagamentoClassificacao::INDISPONIVEL) {
            LogService::warning('Nao foi possivel consultar pedido no Mercado Pago', [
                'order_id' => $orderId, 'motivo' => $c->mensagemAdquirente,
            ], 'pagamento');
            return ['acao' => 'erro', 'status' => null, 'porta' => $c->porta];
        }

        $novo = $this->statusLocal($c->porta);

        // A notificacao traz o id do pedido NO MERCADO PAGO, que e o nosso
        // charge_id. Nao adianta procurar por order_id_loja: sao numeracoes
        // diferentes, nunca casariam.
        $st = $this->db->prepare(
            'SELECT id, pedido_id, order_id_loja, cliente_id, valor_centavos, metodo, parcelas, status
               FROM pgto_transacoes WHERE charge_id = ? LIMIT 1'
        );
        $st->execute([$orderId]);
        $tx = $st->fetch(PDO::FETCH_ASSOC);

        if (!$tx) {
            // Acontece na janela entre o Mercado Pago criar a cobranca e nos
            // gravarmos a transacao. Nao ha o que fazer aqui: eles reenviam a
            // cada 15 minutos, e a proxima encontra. Registrar importa para o
            // caso de NUNCA encontrar — que seria dinheiro sem rastro.
            LogService::warning('Retorno do Mercado Pago sem transacao local', [
                'order_id' => $orderId, 'porta' => $c->porta, 'status' => $novo,
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

        LogService::audit('Transacao atualizada por retorno do Mercado Pago', [
            'order_id'  => $orderId,
            'pedido_id' => (int) $tx['pedido_id'],
            'de'        => $tx['status'],
            'para'      => $novo,
            'detalhe'   => $c->codigoAdquirente,
        ]);

        // Só o dinheiro que ENTROU move o pedido. Pendente já era pendente, e
        // recusado num Pix expirado não deve cancelar um pedido que o cliente
        // ainda pode pagar por outra forma.
        if ($novo === 'aprovado') {
            $ctx = [
                'order_id_loja'  => (string) $tx['order_id_loja'],
                'pedido_id'      => (int) $tx['pedido_id'],
                'cliente_id'     => (int) $tx['cliente_id'],
                'valor_centavos' => (int) $tx['valor_centavos'],
                'metodo'         => (string) $tx['metodo'],
                'parcelas'       => (int) $tx['parcelas'],
            ];

            if ((int) $tx['pedido_id'] > 0) {
                $this->moverPedido((int) $tx['pedido_id'], $orderId);
            }

            $this->notificador->pedidoAprovado($ctx, $c);
        }

        return ['acao' => 'aplicado', 'status' => $novo, 'porta' => $c->porta];
    }

    // =========================================================================

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

    private function moverPedido(int $pedidoId, string $orderId): void
    {
        $this->db->prepare(
            'UPDATE pedidos SET status_pedido = ?, atualizado_em = NOW() WHERE id = ?'
        )->execute(['pagamento_aprovado', $pedidoId]);

        try {
            $this->db->prepare(
                'INSERT INTO pedido_historico (pedido_id, status_novo, observacao, admin_id, criado_em)
                 VALUES (?,?,?,NULL,NOW())'
            )->execute([$pedidoId, 'pagamento_aprovado',
                        'Pagamento confirmado pelo Mercado Pago (' . $orderId . ')']);
        } catch (\Throwable $e) {
            // Histórico é registro, não o fato. Não desfaz a aprovação.
            LogService::exception($e, 'warning', 'pagamento',
                ['acao' => 'historico_mercadopago', 'pedido_id' => $pedidoId]);
        }
    }
}
