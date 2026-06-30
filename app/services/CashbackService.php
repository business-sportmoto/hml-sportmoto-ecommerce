<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/services/CashbackService.php
//
// Gerencia o ciclo de vida dos cashbacks de promoção:
//
//   1. agendarCashback($pedidoId)
//      → chamado quando order.status_pedido = 'entregue'
//      → marca cashback_liberado_em = NOW() + 7 DAYS
//
//   2. processarPendentes()
//      → chamado no bootstrap ou via cron diário
//      → credita via CreditoService os cashbacks vencidos
//
//   3. cancelarCashback($pedidoId)
//      → chamado em cancelamento/estorno
//      → marca como cancelado (não processa)
//
// Tabela: promocao_aplicacoes — colunas adicionadas pela migration:
//   cashback_liberado_em  DATETIME NULL
//   cashback_creditado_em DATETIME NULL
//   cashback_cancelado    TINYINT(1) DEFAULT 0
// ════════════════════════════════════════════════════════

class CashbackService {

    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    // ══════════════════════════════════════════════════
    // AGENDAMENTO — chamado ao marcar pedido como entregue
    // ══════════════════════════════════════════════════

    /**
     * Agenda o crédito de cashback para 7 dias após a entrega.
     * Respeita o prazo do CDC (7 dias para devolução) — o cliente
     * só recebe o crédito depois que o prazo de devolução encerra.
     *
     * Chamada obrigatória: adicionar no AdminPedidoService (ou onde
     * o status do pedido muda para 'entregue'):
     *
     *   if ($novoStatus === 'entregue') {
     *       (new CashbackService())->agendarCashback($pedidoId);
     *   }
     */
    public function agendarCashback(int $pedidoId): int {
        // Só agenda cashbacks ainda não agendados/cancelados
        $stmt = $this->db->prepare(
            "UPDATE promocao_aplicacoes
             SET    cashback_liberado_em = DATE_ADD(NOW(), INTERVAL 7 DAY)
             WHERE  pedido_id = ?
               AND  tipo_beneficio = 'cashback'
               AND  cashback_liberado_em  IS NULL
               AND  cashback_cancelado    = 0
               AND  cashback_creditado_em IS NULL"
        );
        $stmt->execute([$pedidoId]);
        return $stmt->rowCount();
    }

    // ══════════════════════════════════════════════════
    // PROCESSAMENTO — chamado no bootstrap ou cron diário
    // ══════════════════════════════════════════════════

    /**
     * Processa todos os cashbacks cujo prazo de liberação venceu.
     * Deve ser chamado uma vez por dia — adicionar no bootstrap ou
     * em uma rota de cron protegida por token:
     *
     *   // Em bootstrap.php ou similar:
     *   if (rand(1, 100) === 1) {  // ~1% das requisições
     *       (new CashbackService())->processarPendentes();
     *   }
     *
     * Retorna o número de cashbacks creditados.
     */
    public function processarPendentes(): int {
        $pendentes = $this->db->query(
            "SELECT pa.id,
                    pa.cliente_id,
                    pa.pedido_id,
                    pa.detalhes,
                    p.nome AS promocao_nome
             FROM   promocao_aplicacoes pa
             JOIN   promocoes p ON p.id = pa.promocao_id
             WHERE  pa.tipo_beneficio        = 'cashback'
               AND  pa.cashback_liberado_em  IS NOT NULL
               AND  pa.cashback_liberado_em  <= NOW()
               AND  pa.cashback_creditado_em IS NULL
               AND  pa.cashback_cancelado    = 0
               AND  pa.cliente_id            IS NOT NULL
             ORDER  BY pa.cashback_liberado_em ASC
             LIMIT  50"
        )->fetchAll();

        $creditados = 0;
        foreach ($pendentes as $row) {
            $this->creditarUm($row) && $creditados++;
        }

        return $creditados;
    }

    // ══════════════════════════════════════════════════
    // CANCELAMENTO — chamado em cancelamento/estorno
    // ══════════════════════════════════════════════════

    /**
     * Cancela cashbacks pendentes de um pedido (ainda não creditados).
     * Chamado quando um pedido é cancelado ou estornado.
     *
     * Chamada sugerida em AdminPedidoService onde o status muda para
     * 'cancelado' ou 'troca_devolucao':
     *
     *   (new CashbackService())->cancelarCashback($pedidoId);
     */
    public function cancelarCashback(int $pedidoId): void {
        $this->db->prepare(
            "UPDATE promocao_aplicacoes
             SET    cashback_cancelado    = 1,
                    cashback_creditado_em = NULL
             WHERE  pedido_id = ?
               AND  tipo_beneficio = 'cashback'
               AND  cashback_creditado_em IS NULL"
        )->execute([$pedidoId]);
    }

    // ══════════════════════════════════════════════════
    // CONSULTA — resumo de cashback de um pedido
    // ══════════════════════════════════════════════════

    /**
     * Retorna o estado dos cashbacks de um pedido para exibição no admin.
     */
    public function getStatusPedido(int $pedidoId): array {
        $stmt = $this->db->prepare(
            "SELECT pa.id, pa.detalhes,
                    pa.cashback_liberado_em,
                    pa.cashback_creditado_em,
                    pa.cashback_cancelado,
                    p.nome AS promocao_nome
             FROM   promocao_aplicacoes pa
             JOIN   promocoes p ON p.id = pa.promocao_id
             WHERE  pa.pedido_id    = ?
               AND  pa.tipo_beneficio = 'cashback'"
        );
        $stmt->execute([$pedidoId]);
        return array_map(function (array $row): array {
            if (!empty($row['detalhes']) && is_string($row['detalhes'])) {
                $row['detalhes'] = json_decode($row['detalhes'], true) ?? [];
            }
            $row['estado'] = $this->calcularEstado($row);
            return $row;
        }, $stmt->fetchAll());
    }

    // ══════════════════════════════════════════════════
    // PRIVADO
    // ══════════════════════════════════════════════════

    private function creditarUm(array $row): bool {
        $detalhes = is_string($row['detalhes'])
            ? (json_decode($row['detalhes'], true) ?? [])
            : ($row['detalhes'] ?? []);

        $valor      = (float)($detalhes['cashback_valor'] ?? 0);
        $validaDias = max(1, (int)($detalhes['cashback_validade_dias'] ?? 90));
        $clienteId  = (int)$row['cliente_id'];

        if ($valor <= 0 || !$clienteId) return false;

        // Busca usuario_id via cliente (CreditoService opera com usuario_id)
        $stmt = $this->db->prepare(
            "SELECT usuario_id FROM clientes WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$clienteId]);
        $usuarioId = (int)$stmt->fetchColumn();
        if (!$usuarioId) return false;

        try {
            $this->db->beginTransaction();

            // Credita via CreditoService
            $creditoService = new CreditoService();
            $creditoService->creditar(
                usuarioId:  $usuarioId,
                valor:      $valor,
                descricao:  'Cashback: ' . $row['promocao_nome'],
                referencia: 'pedido_' . $row['pedido_id'],
                validade:   (new \DateTime())->modify("+{$validaDias} days")->format('Y-m-d H:i:s'),
            );

            // Marca como creditado
            $this->db->prepare(
                "UPDATE promocao_aplicacoes
                 SET cashback_creditado_em = NOW()
                 WHERE id = ?"
            )->execute([$row['id']]);

            $this->db->commit();
            return true;

        } catch (\Throwable $e) {
            $this->db->rollBack();
            error_log("[CashbackService] Erro ao creditar id={$row['id']}: " . $e->getMessage());
            return false;
        }
    }

    private function calcularEstado(array $row): string {
        if ($row['cashback_cancelado']) return 'cancelado';
        if ($row['cashback_creditado_em']) return 'creditado';
        if ($row['cashback_liberado_em']) {
            return strtotime($row['cashback_liberado_em']) <= time()
                ? 'pronto'       // venceu, aguarda próximo processamento
                : 'agendado';    // ainda dentro dos 7 dias
        }
        return 'pendente';       // pedido ainda não foi marcado como entregue
    }
}