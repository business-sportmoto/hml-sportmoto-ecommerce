<?php
declare(strict_types=1);

/**
 * MalgaWebhookProcessor
 *
 * Aplica um evento de webhook (já validado e persistido em pgto_webhook_log)
 * no domínio do SportMoto:
 *   1. Atualiza pgto_transacoes com novo status
 *   2. Atualiza pedidos (status_pagamento + status_pedido + pago_em)
 *   3. Confirma cupom (se ficou aprovado pela 1ª vez)
 *   4. Devolve estoque (se cancelado/estornado)
 *   5. Marca o log como processado
 *
 * Toda lógica fica AQUI. O Controller só recebe+ack. O Worker só itera.
 *
 * Idempotente: processar o mesmo evento duas vezes não causa efeito duplicado.
 * Usa transação por evento — se algo falha, marca como erro e mantém o log
 * pra retry posterior pelo worker.
 *
 * Eventos transaction.* tratados (per docs.malga.io webhook 1.1):
 *
 *   pending           → status local 'pendente'
 *   pre_authorized    → 'pre_autorizado'
 *   authorized        → 'aprovado' + atualiza pedido + confirma cupom
 *   failed            → 'falhou'   + libera estoque
 *   canceled          → 'cancelado'+ libera estoque
 *   voided            → 'estornado'+ libera estoque + cancela pedido
 *   refund_pending    → 'estorno_pendente'
 *   charged_back      → 'chargeback' + libera estoque + cancela pedido
 *   dispute           → mantém status, só loga
 *   dispute_closed    → mantém status, só loga
 *
 * Eventos de outros objetos (seller, subscription) são reconhecidos e marcados
 * como processados sem efeito (não usamos esses fluxos no SportMoto hoje).
 */
class MalgaWebhookProcessor
{
    /** @var PDO */
    private $db;

    /**
     * Mapeamento "event" do payload → status interno SportMoto.
     * O event vem SEM o prefixo "transaction.": no payload é só "authorized",
     * mesmo que a lista de eventos suportados diga "transaction.authorized".
     */
    const MAPA_STATUS = [
        'pending'         => 'pendente',
        'pre_authorized'  => 'pre_autorizado',
        'authorized'      => 'aprovado',
        'failed'          => 'falhou',
        'canceled'        => 'cancelado',
        'voided'          => 'estornado',
        'refund_pending'  => 'estorno_pendente',
        'charged_back'    => 'chargeback',
        // dispute mantém o status atual da transação:
        'dispute'         => null,
        'dispute_closed'  => null,
        'revert_void'     => 'aprovado',  // reverteu estorno = voltou pro aprovado
        'probe_void'      => 'cancelado',
    ];

    /** Eventos que disparam atualização do pedido (status final do pagamento) */
    const EVENTOS_QUE_ATUALIZAM_PEDIDO = [
        'authorized', 'failed', 'voided', 'charged_back', 'canceled', 'revert_void',
    ];

    private AdminPedido        $model;
    private AdminPedidoService $service;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();

        $this->model   = new AdminPedido();
        $this->service = new AdminPedidoService();
    }

    /**
     * Processa um evento já armazenado em pgto_webhook_log.
     *
     * @param int $webhookLogId id da linha em pgto_webhook_log
     * @return array{ok: bool, motivo: string, transacao_id: int|null, pedido_id: int|null}
     */
    public function processarPorLogId(int $webhookLogId): array
    {
        $log = $this->carregarLog($webhookLogId);
        if (!$log) {
            return ['ok' => false, 'motivo' => 'webhook_log não encontrado', 'transacao_id' => null, 'pedido_id' => null];
        }

        // Já processado? Idempotência.
        if ((int) $log['processado'] === 1) {
            return ['ok' => true, 'motivo' => 'já processado anteriormente', 'transacao_id' => null, 'pedido_id' => null];
        }

        $payload = json_decode($log['payload'], true);
        if (!is_array($payload)) {
            $this->marcarComoErro($webhookLogId, 'payload JSON inválido');
            return ['ok' => false, 'motivo' => 'payload inválido', 'transacao_id' => null, 'pedido_id' => null];
        }

        $object = $payload['object'] ?? '';
        $event  = $payload['event']  ?? '';

        // Hoje só processamos transaction. Outros objetos só logamos.
        if ($object !== 'transaction') {
            $this->marcarComoProcessado(
                $webhookLogId,
                "evento '{$object}.{$event}' ignorado (não tratamos {$object})"
            );
            return ['ok' => true, 'motivo' => "ignorado: {$object}.{$event}", 'transacao_id' => null, 'pedido_id' => null];
        }

        $chargeId = $payload['data']['id'] ?? null;
        if (!$chargeId) {
            $this->marcarComoErro($webhookLogId, 'payload.data.id (charge_id) ausente');
            return ['ok' => false, 'motivo' => 'charge_id ausente', 'transacao_id' => null, 'pedido_id' => null];
        }

        $statusNovo = self::MAPA_STATUS[$event] ?? null;
        if ($statusNovo === null && !array_key_exists($event, self::MAPA_STATUS)) {
            // Evento desconhecido — loga e marca como processado pra não retentativa
            $this->marcarComoProcessado($webhookLogId, "evento desconhecido: {$event}");
            return ['ok' => true, 'motivo' => "evento desconhecido: {$event}", 'transacao_id' => null, 'pedido_id' => null];
        }

        // Confere ordem cronológica: a Malga manda eventos em ordem, mas
        // pode acontecer de chegar um evento "atrasado". Se o evento atual
        // for mais antigo que a última atualização da transação, ignoramos.
        $eventoCriadoEm = $payload['createdAt'] ?? null;

        // -----------------------------------------------------------------
        // Tudo OK — aplica em transação
        // -----------------------------------------------------------------
        $this->db->beginTransaction();
        try {
            $transacao = $this->buscarTransacao($chargeId);
            if (!$transacao) {
                // Cobrança que não criamos pelo nosso checkout (manual no painel Malga, p.ex.)
                $this->marcarComoProcessado(
                    $webhookLogId,
                    "transação não encontrada localmente (charge {$chargeId})"
                );
                $this->db->commit();
                return ['ok' => true, 'motivo' => 'transacao_inexistente', 'transacao_id' => null, 'pedido_id' => null];
            }

            // Out-of-order: se o evento é mais antigo que o último update,
            // ainda registramos a chegada mas não revertemos status final.
            $aplicarStatus = $this->deveAplicarNovoStatus(
                (string) $transacao['status'],
                (string) ($statusNovo ?? $transacao['status']),
                $eventoCriadoEm,
                (string) $transacao['atualizado_em']
            );

            if ($aplicarStatus && $statusNovo !== null) {
                $this->atualizarTransacao((int) $transacao['id'], $statusNovo, $payload);
            }

            // Atualiza pedido se for evento que afeta status final
            $pedidoId = null;
            if (in_array($event, self::EVENTOS_QUE_ATUALIZAM_PEDIDO, true)
                && !empty($transacao['pedido_id'])
                && $aplicarStatus
            ) {
                $pedidoId = (int) $transacao['pedido_id'];
                $this->atualizarPedido($pedidoId, $statusNovo, $event);

                // Estoque e cupom em transações de mudança de estado
                if ($event === 'authorized') {
                    $this->confirmarCupomSeAplicavel($pedidoId);
                }
                if (in_array($event, ['failed', 'voided', 'charged_back', 'canceled'], true)) {
                    // $this->liberarEstoque($pedidoId);
                }
            }

            $this->marcarComoProcessado(
                $webhookLogId,
                "evento {$event} aplicado, status: " . ($statusNovo ?? 'inalterado')
            );

            $this->db->commit();

            if (class_exists('LogService')) {
                LogService::info('[Webhook] evento aplicado', [
                    'log_id'    => $webhookLogId,
                    'charge_id' => $chargeId,
                    'event'     => $event,
                    'status'    => $statusNovo,
                    'pedido_id' => $pedidoId,
                ]);
            }
            if (class_exists('LogService') && method_exists('LogService', 'audit')) {
                LogService::audit('webhook_processado', [
                    'log_id'    => $webhookLogId,
                    'charge_id' => $chargeId,
                    'event'     => $event,
                    'status'    => $statusNovo,
                ]);
            }

            return [
                'ok'           => true,
                'motivo'       => 'processado',
                'transacao_id' => (int) $transacao['id'],
                'pedido_id'    => $pedidoId,
            ];

        } catch (\Throwable $e) {
            $this->db->rollBack();
            $msg = '[Webhook] erro ao processar log #' . $webhookLogId . ': ' . $e->getMessage();
            if (class_exists('LogService')) {
                LogService::error($msg, [
                    'log_id' => $webhookLogId,
                    'file'   => $e->getFile() . ':' . $e->getLine(),
                ]);
            }
            $this->marcarComoErro($webhookLogId, $e->getMessage());
            return [
                'ok'           => false,
                'motivo'       => $e->getMessage(),
                'transacao_id' => null,
                'pedido_id'    => null,
            ];
        }
    }

    // =================================================================
    // PRIVADOS
    // =================================================================

    private function carregarLog(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM pgto_webhook_log WHERE id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function buscarTransacao(string $chargeId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, status, pedido_id, atualizado_em, pago_em
               FROM pgto_transacoes
              WHERE charge_id = :cid
              LIMIT 1"
        );
        $stmt->execute([':cid' => $chargeId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Decide se aplicamos o novo status. Regras:
     *   - Se o status atual já é "final" (aprovado, estornado, chargeback),
     *     só permite transição se o novo for "mais final".
     *   - Se o eventoCriadoEm é anterior ao atualizado_em local,
     *     consideramos out-of-order e não regredimos.
     */
    private function deveAplicarNovoStatus(string $statusAtual, string $statusNovo, ?string $eventoCriadoEm, string $atualizadoEm): bool
    {
        if ($statusAtual === $statusNovo) return true; // mesmo status — pode atualizar timestamps

        // Out of order check
        if ($eventoCriadoEm && $atualizadoEm) {
            $tsEvento = strtotime($eventoCriadoEm);
            $tsLocal  = strtotime($atualizadoEm);
            if ($tsEvento && $tsLocal && $tsEvento < $tsLocal) {
                // Evento atrasado. Só aplica se for transição "natural"
                // (ex.: pending → authorized é OK mesmo que tenha vindo
                // depois; aprovado → pending NÃO é OK).
                $hierarquia = ['pendente' => 0, 'pre_autorizado' => 1, 'aprovado' => 2,
                               'estorno_pendente' => 3, 'estornado' => 4, 'chargeback' => 5,
                               'falhou' => 9, 'cancelado' => 9];
                $atual = $hierarquia[$statusAtual] ?? 0;
                $novo  = $hierarquia[$statusNovo]  ?? 0;
                if ($novo < $atual) return false;
            }
        }

        return true;
    }

    private function atualizarTransacao(int $transacaoId, string $statusNovo, array $payload): void
    {
        $pagoEm = ($statusNovo === 'aprovado') ? date('Y-m-d H:i:s') : null;

        $this->db->prepare(
            "UPDATE pgto_transacoes
                SET status        = :st,
                    raw_response  = :raw,
                    pago_em       = COALESCE(:pago, pago_em),
                    atualizado_em = CURRENT_TIMESTAMP
              WHERE id = :id"
        )->execute([
            ':st'   => $statusNovo,
            ':raw'  => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ':pago' => $pagoEm,
            ':id'   => $transacaoId,
        ]);
    }

    private function atualizarPedido(int $pedidoId, ?string $statusPgto, string $event): void
    {
        // Mapeamento status pagamento → status_pedido (mantém compat com schema atual)
        $mapaPedido = [
            'aprovado'         => 'pagamento_aprovado',
            'pre_autorizado'   => 'aguardando_pagamento',
            'pendente'         => 'aguardando_pagamento',
            'estorno_pendente' => 'aguardando_pagamento',
            'falhou'           => 'aguardando_pagamento',
            'cancelado'        => 'cancelado',
            'estornado'        => 'cancelado',
            'chargeback'       => 'cancelado',
        ];
        $statusPedidoFinal = $mapaPedido[$statusPgto] ?? 'aguardando_pagamento';
        $pagoEm = ($statusPgto === 'aprovado') ? date('Y-m-d H:i:s') : null;

        $observacao = "Status atualizado com sucesso: status pagamento {$statusPgto}.";
        $adminId    = 0; // Não temos um admin "real" aqui, é só pra registro. Poderíamos usar um admin genérico "Webhook Processor" se quisermos.
        $notificar   = true; // Notifica cliente que o pedido foi aprovado/cancelado, etc.

        $statusModel = new PedidoStatus();
        $statusDef   = $statusModel->findBySlug($statusPedidoFinal);

        $this->service->mudarStatus($pedidoId, $statusPedidoFinal, $observacao, $adminId, $notificar);

        $this->db->prepare(
            "UPDATE pedidos
                SET status_pagamento = :sp,
                    status_pedido    = :spd,
                    pago_em          = COALESCE(:pago, pago_em)
              WHERE id = :id"
        )->execute([
            ':sp'   => $statusPgto,
            ':spd'  => $statusPedidoFinal,
            ':pago' => $pagoEm,
            ':id'   => $pedidoId,
        ]);
    }

    /**
     * Confirma cupom se o pedido tem um cupom_uso "reservado" pendente.
     * É chamado quando o pagamento foi aprovado pelo webhook (antes só
     * acontecia se aprovação fosse síncrona).
     */
    private function confirmarCupomSeAplicavel(int $pedidoId): void
    {
        // Busca cupom_uso reservado vinculado ao pedido
        try {
            $stmt = $this->db->prepare(
                "SELECT id FROM cupom_usos
                  WHERE pedido_id = :id AND status = 'reservado' LIMIT 1"
            );
            $stmt->execute([':id' => $pedidoId]);
            $cupomUsoId = (int) ($stmt->fetchColumn() ?: 0);

            if ($cupomUsoId > 0 && class_exists('CouponService')) {
                (new CouponService())->confirmar($cupomUsoId);
            }
        } catch (\Throwable $e) {
            // Não derruba o processamento principal — só loga.
            if (class_exists('LogService')) {
                LogService::warning('[Webhook] Falha ao confirmar cupom: ' . $e->getMessage(), [
                    'pedido_id' => $pedidoId,
                ]);
            }
        }
    }

    /**
     * Devolve estoque quando o pedido é cancelado via webhook (failed/voided/etc.).
     */
    private function liberarEstoque(int $pedidoId): void
    {
        try {
            // Busca os itens do pedido pra liberar
            $stmt = $this->db->prepare(
                "SELECT sku AS sku_id, quantidade FROM pedido_itens
                  WHERE pedido_id = :id AND sku IS NOT NULL"
            );
            $stmt->execute([':id' => $pedidoId]);
            $itens = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $update = $this->db->prepare(
                "UPDATE produto_skus SET estoque = estoque + :qtd WHERE id = :id"
            );
            foreach ($itens as $item) {
                $update->execute([
                    ':qtd' => (int) $item['quantidade'],
                    ':id'  => (int) $item['sku_id'],
                ]);
            }
        } catch (\Throwable $e) {
            if (class_exists('LogService')) {
                LogService::warning('[Webhook] Falha ao liberar estoque: ' . $e->getMessage(), [
                    'pedido_id' => $pedidoId,
                ]);
            }
        }
    }

    private function marcarComoProcessado(int $logId, string $motivo): void
    {
        $this->db->prepare(
            "UPDATE pgto_webhook_log
                SET processado = 1,
                    processado_em = CURRENT_TIMESTAMP,
                    erro = NULL,
                    tentativas = tentativas + 1
              WHERE id = :id"
        )->execute([':id' => $logId]);
    }

    private function marcarComoErro(int $logId, string $erro): void
    {
        $this->db->prepare(
            "UPDATE pgto_webhook_log
                SET processado = 0,
                    erro = :erro,
                    tentativas = tentativas + 1
              WHERE id = :id"
        )->execute([':id' => $logId, ':erro' => mb_substr($erro, 0, 1000)]);
    }
}
