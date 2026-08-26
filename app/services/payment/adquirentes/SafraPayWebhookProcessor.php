<?php
declare(strict_types=1);

/**
 * app/services/payment/adquirentes/SafraPayWebhookProcessor.php
 *
 * Aplica o efeito de uma notificação da Safra Pay: atualiza a tentativa, a
 * transação e o pedido.
 *
 * REGRA CENTRAL — NÃO CONFIAR NO CORPO DA NOTIFICAÇÃO:
 *   A Safra autentica o webhook com o merchant token em Base64, que é segredo
 *   COMPARTILHADO, não assinatura do corpo. Ninguém prova que o payload não
 *   foi alterado no caminho. Por isso o valor e o status usados aqui vêm de
 *   uma RECONSULTA a GET /v2/charge/{id}. O webhook diz "algo mudou"; quem diz
 *   "o que mudou" é a API.
 *
 * SEGUNDA REGRA — CONFERIR O VALOR:
 *   Antes de aprovar, o valor reconsultado é comparado ao total do pedido.
 *   Divergência não aprova: vira alerta. Sem isso, um pedido de R$ 2.000 podia
 *   ser liberado por um Pix de R$ 1,00.
 *
 * Segue o precedente do MalgaWebhookProcessor: mudança de status de pedido
 * passa por AdminPedidoService::mudarStatus(), nunca por UPDATE solto.
 */
class SafraPayWebhookProcessor
{
    /**
     * Safra → pedidos.status_pagamento.
     *
     * O ENUM da coluna aceita SOMENTE: pendente, aguardando, aprovado,
     * recusado, estornado, reembolsado, erro, falhou. Valores fora disso
     * são recusados pelo MySQL — mapear para eles quebra a gravação.
     */
    private const MAPA_STATUS = [
        'Captured'      => 'aprovado',
        'Paid'          => 'aprovado',       // boleto pago
        'PreAuthorized' => 'aguardando',
        'Pending'       => 'aguardando',
        'PendingPayment'=> 'aguardando',
        'PendingCancel' => 'aguardando',
        'Denied'        => 'recusado',
        'ErrorCreation' => 'erro',
        'Canceled'      => 'estornado',
        'Expired'       => 'falhou',
    ];

    /** Hierarquia para barrar evento atrasado que regride o status. */
    private const HIERARQUIA = [
        'pendente'   => 0, 'aguardando' => 1, 'aprovado' => 2,
        'estornado'  => 3, 'reembolsado' => 4,
        'recusado'   => 9, 'erro' => 9, 'falhou' => 9,
    ];

    private PDO $db;
    private SafraPayAdapter $adapter;

    public function __construct(?PDO $db = null, ?SafraPayAdapter $adapter = null)
    {
        $this->db      = $db ?? Database::getInstance()->getConnection();
        $this->adapter = $adapter ?? new SafraPayAdapter();
    }

    /**
     * @return array{ok:bool, motivo:string, status:?string}
     */
    public function processarPorLogId(int $webhookLogId): array
    {
        $log = $this->carregarLog($webhookLogId);
        if (!$log) {
            return ['ok' => false, 'motivo' => 'log inexistente', 'status' => null];
        }
        if ((int) $log['processado'] === 1) {
            return ['ok' => true, 'motivo' => 'ja processado', 'status' => null];
        }
        if ((int) $log['assinatura_valida'] !== 1) {
            $this->marcarErro($webhookLogId, 'autenticacao invalida — nao processado');
            return ['ok' => false, 'motivo' => 'autenticacao invalida', 'status' => null];
        }

        try {
            $payload = json_decode((string) $log['payload'], true);
            if (!is_array($payload)) {
                throw new RuntimeException('payload ilegivel');
            }

            $evento = SafraPayWebhookEvento::daPayload($payload);
            if (!$evento->chargeId) {
                throw new RuntimeException('evento sem ChargeId');
            }

            // ── Reconsulta: a fonte da verdade ──────────────────────────
            $atual = $this->adapter->consultar($evento->chargeId);

            // ── A reconsulta precisa ter LIDO a cobrança ────────────────
            //
            // Só um resultado vindo do corpo da cobrança pode virar status.
            // Qualquer falha de transporte — timeout, 5xx, credencial, e
            // principalmente bloqueio de WAF (403 HTML do Akamai) — significa
            // "não sei", não "negado".
            //
            // Sem esta guarda, um bloqueio de infraestrutura entre nós e a
            // Safra marcava pagamentos legítimos como RECUSADOS: o default do
            // statusDaReconsulta() era Denied. Um IP bloqueado derrubaria os
            // pedidos do dia inteiro.
            $reconsultaFalhou = in_array($atual->porta, [
                PagamentoClassificacao::ERRO_TECNICO,
                PagamentoClassificacao::INDISPONIVEL,
                PagamentoClassificacao::INCERTO,
            ], true);

            if ($reconsultaFalhou || $atual->exigeConsulta) {
                $motivo = 'reconsulta falhou (' . $atual->classeErro . ', HTTP '
                        . ($atual->httpStatus ?? 0) . ') — evento nao aplicado';

                LogService::error('[Safra webhook] reconsulta indisponivel', [
                    'charge_id' => $evento->chargeId,
                    'classe'    => $atual->classeErro,
                    'http'      => $atual->httpStatus,
                    'detalhe'   => $atual->mensagemAdquirente,
                ], 'pagamento');

                // NÃO marca processado: a reentrega da Safra ou a
                // reconciliação tentam de novo quando a conexão voltar.
                $this->registrarTentativa($webhookLogId, $motivo);
                return ['ok' => false, 'motivo' => $motivo, 'status' => null];
            }

            $statusSafra = $this->statusDaReconsulta($atual);
            $statusNovo  = self::MAPA_STATUS[$statusSafra] ?? 'aguardando';

            // Divergência entre o que a notificação disse e o que a API diz.
            // Não impede o processamento (a API vence), mas é sinal de
            // notificação adulterada ou de corrida — precisa aparecer.
            if ($evento->transactionStatus && $evento->transactionStatus !== $statusSafra) {
                LogService::warning('[Safra webhook] notificacao divergente da reconsulta', [
                    'charge_id'      => $evento->chargeId,
                    'status_webhook' => $evento->transactionStatus,
                    'status_api'     => $statusSafra,
                ], 'pagamento');
            }

            $resultado = $this->aplicar($evento, $atual, $statusNovo);

            // Só marca processado o que realmente foi aplicado. Um evento
            // bloqueado (valor divergente, por exemplo) que ficasse marcado
            // como processado sumiria de qualquer fila de revisão — e é
            // exatamente o que alguém precisa olhar.
            if ($resultado['ok']) {
                $this->marcarProcessado($webhookLogId, $resultado['motivo']);
            } else {
                $this->marcarErro($webhookLogId, $resultado['motivo']);
            }

            return $resultado;

        } catch (\Throwable $e) {
            LogService::exception($e, 'error', 'pagamento', [
                'webhook_log_id' => $webhookLogId,
                'charge_id'      => $log['charge_id'] ?? null,
            ]);
            $this->marcarErro($webhookLogId, $e->getMessage());
            return ['ok' => false, 'motivo' => $e->getMessage(), 'status' => null];
        }
    }

    // =========================================================================

    private function aplicar(SafraPayWebhookEvento $ev, PagamentoClassificacao $atual, string $statusNovo): array
    {
        $tx = $this->buscarTransacao($ev->chargeId, $ev->merchantChargeId);

        // Sem transação nossa: cobrança criada fora deste fluxo (ou pedido
        // ainda não gravado). Registra e sai — nunca inventa pedido.
        if (!$tx) {
            LogService::warning('[Safra webhook] cobranca sem transacao local', [
                'charge_id' => $ev->chargeId,
                'pedido'    => $ev->merchantChargeId,
            ], 'pagamento');
            return ['ok' => true, 'motivo' => 'sem transacao local', 'status' => $statusNovo];
        }

        $statusAtual = (string) ($tx['status'] ?? 'pendente');

        // Evento atrasado que regrediria o status: ignora.
        if (!$this->deveAplicar($statusAtual, $statusNovo)) {
            return ['ok' => true, 'motivo' => "regressao ignorada ({$statusAtual} -> {$statusNovo})", 'status' => $statusAtual];
        }

        $pedidoId = (int) ($tx['pedido_id'] ?? 0);

        // ── Conferência de valor antes de aprovar ───────────────────
        if ($statusNovo === 'aprovado' && $pedidoId > 0) {
            $erro = $this->conferirValor($pedidoId, $ev, $atual);
            if ($erro !== null) {
                LogService::critical('[Safra webhook] valor divergente — aprovacao bloqueada', [
                    'charge_id' => $ev->chargeId,
                    'pedido_id' => $pedidoId,
                    'detalhe'   => $erro,
                ], 'pagamento');
                return ['ok' => false, 'motivo' => 'valor divergente: ' . $erro, 'status' => $statusAtual];
            }
        }

        $this->db->beginTransaction();
        try {
            $this->atualizarTransacao((int) $tx['id'], $statusNovo, $ev);
            $this->atualizarTentativa($ev, $statusNovo);

            if ($pedidoId > 0) {
                $this->atualizarPedido($pedidoId, $statusNovo);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        LogService::audit('Pagamento atualizado por webhook', [
            'gateway'    => 'safrapay',
            'charge_id'  => $ev->chargeId,
            'pedido_id'  => $pedidoId,
            'status'     => $statusNovo,
            'metodo'     => $ev->metodo,
        ]);

        return ['ok' => true, 'motivo' => 'aplicado', 'status' => $statusNovo];
    }

    /** Rótulo de status vindo da reconsulta (não do webhook). */
    private function statusDaReconsulta(PagamentoClassificacao $c): string
    {
        return match ($c->porta) {
            PagamentoClassificacao::APROVADO => 'Captured',
            PagamentoClassificacao::PENDENTE => $c->classeErro === 'cancelamento_pendente'
                                                ? 'PendingCancel' : 'PendingPayment',
            default => $c->classeErro === 'cancelado' ? 'Canceled' : 'Denied',
        };
    }

    /**
     * O valor confirmado bate com o pedido? Devolve null quando está tudo
     * certo, ou a descrição da divergência.
     */
    private function conferirValor(int $pedidoId, SafraPayWebhookEvento $ev, PagamentoClassificacao $atual): ?string
    {
        $st = $this->db->prepare("SELECT total FROM pedidos WHERE id = ? LIMIT 1");
        $st->execute([$pedidoId]);
        $total = $st->fetchColumn();
        if ($total === false) return 'pedido inexistente';

        $esperado = (int) round(((float) $total) * 100);
        $pago     = $ev->valorCentavos;
        if ($pago <= 0) return null;   // notificação sem valor: nada a conferir

        // Tolerância de 1 centavo para arredondamento.
        if (abs($esperado - $pago) > 1) {
            return "pedido {$esperado} centavos, pago {$pago}";
        }
        return null;
    }

    private function deveAplicar(string $atual, string $novo): bool
    {
        if ($atual === $novo) return true;
        $a = self::HIERARQUIA[$atual] ?? 0;
        $n = self::HIERARQUIA[$novo]  ?? 0;
        // Terminal (9) sempre pode entrar; regressão de nível não.
        return $n >= $a;
    }

    private function carregarLog(int $id): ?array
    {
        $st = $this->db->prepare("SELECT * FROM pgto_webhook_log WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Localiza a transação por charge_id ou, como fallback, pelo pedido. */
    private function buscarTransacao(string $chargeId, ?string $orderIdLoja): ?array
    {
        $st = $this->db->prepare(
            "SELECT * FROM pgto_transacoes WHERE charge_id = ? ORDER BY id DESC LIMIT 1"
        );
        $st->execute([$chargeId]);
        $tx = $st->fetch(PDO::FETCH_ASSOC);
        if ($tx) return $tx;

        if ($orderIdLoja) {
            $st = $this->db->prepare(
                "SELECT * FROM pgto_transacoes WHERE order_id_loja = ? ORDER BY id DESC LIMIT 1"
            );
            $st->execute([$orderIdLoja]);
            $tx = $st->fetch(PDO::FETCH_ASSOC);
            if ($tx) return $tx;
        }
        return null;
    }

    private function atualizarTransacao(int $id, string $status, SafraPayWebhookEvento $ev): void
    {
        $pagoEm = $status === 'aprovado' ? date('Y-m-d H:i:s') : null;
        $this->db->prepare(
            "UPDATE pgto_transacoes
                SET status        = :st,
                    pago_em       = COALESCE(:pago, pago_em),
                    atualizado_em = NOW()
              WHERE id = :id"
        )->execute([':st' => $status, ':pago' => $pagoEm, ':id' => $id]);
    }

    /**
     * Fecha a tentativa correspondente. merchantOrderId é o
     * merchantTransactionId que enviamos — ou seja, a nossa tentativa_ref.
     */
    private function atualizarTentativa(SafraPayWebhookEvento $ev, string $status): void
    {
        $resultado = match ($status) {
            'aprovado'                       => 'aprovado',
            'aguardando'                     => 'pendente',
            'estornado', 'reembolsado'       => 'cancelado',
            default                          => 'negado',
        };

        if ($ev->merchantOrderId) {
            $this->db->prepare(
                "UPDATE pgto_tentativas
                    SET resultado = :r, charge_id = COALESCE(charge_id, :cid)
                  WHERE idempotency_key = :k OR no_ref = :k2
                  ORDER BY id DESC LIMIT 1"
            )->execute([
                ':r' => $resultado, ':cid' => $ev->chargeId,
                ':k' => $ev->merchantOrderId, ':k2' => $ev->merchantOrderId,
            ]);
            return;
        }

        if ($ev->merchantChargeId) {
            $this->db->prepare(
                "UPDATE pgto_tentativas SET resultado = :r
                  WHERE order_id_loja = :o AND adquirente_codigo = 'safrapay'
                  ORDER BY id DESC LIMIT 1"
            )->execute([':r' => $resultado, ':o' => $ev->merchantChargeId]);
        }
    }

    /**
     * Mudança de status do pedido passa por AdminPedidoService, igual ao
     * processor da Malga — é ele que dispara histórico e notificação.
     */
    private function atualizarPedido(int $pedidoId, string $statusPgto): void
    {
        $mapaPedido = [
            'aprovado'    => 'pagamento_aprovado',
            'aguardando'  => 'aguardando_pagamento',
            'pendente'    => 'aguardando_pagamento',
            'recusado'    => 'aguardando_pagamento',
            'erro'        => 'aguardando_pagamento',
            'falhou'      => 'aguardando_pagamento',
            'estornado'   => 'cancelado',
            'reembolsado' => 'cancelado',
        ];
        $slug   = $mapaPedido[$statusPgto] ?? 'aguardando_pagamento';
        $pagoEm = $statusPgto === 'aprovado' ? date('Y-m-d H:i:s') : null;

        $this->db->prepare(
            "UPDATE pedidos
                SET status_pagamento = :sp,
                    status_pedido    = :spd,
                    pago_em          = COALESCE(:pago, pago_em)
              WHERE id = :id"
        )->execute([':sp' => $statusPgto, ':spd' => $slug, ':pago' => $pagoEm, ':id' => $pedidoId]);

        // Histórico e notificação ao cliente. Best-effort: uma falha aqui não
        // pode desfazer o pagamento já registrado acima.
        try {
            (new AdminPedidoService())->mudarStatus(
                $pedidoId, $slug,
                "Status atualizado pelo webhook da Safra Pay: {$statusPgto}.",
                0, true
            );
        } catch (\Throwable $e) {
            LogService::exception($e, 'warning', 'pagamento', [
                'pedido_id' => $pedidoId,
                'acao'      => 'mudarStatus',
            ]);
        }
    }

    private function marcarProcessado(int $id, string $motivo): void
    {
        $this->db->prepare(
            "UPDATE pgto_webhook_log
                SET processado = 1, processado_em = NOW(), erro = NULL,
                    tentativas = tentativas + 1
              WHERE id = ?"
        )->execute([$id]);
    }

    private function marcarErro(int $id, string $erro): void
    {
        $this->db->prepare(
            "UPDATE pgto_webhook_log
                SET erro = :e, tentativas = tentativas + 1
              WHERE id = :id"
        )->execute([':e' => mb_substr($erro, 0, 2000), ':id' => $id]);
    }

    private function registrarTentativa(int $id, string $motivo): void
    {
        $this->marcarErro($id, $motivo);
    }
}
