<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/services/ScoreService.php
// ════════════════════════════════════════════════════════

class ScoreService {

    private PDO $db;

    // ── Fórmula de tiers ─────────────────────────────────
    public const TIER_BRONZE   = 0;
    public const TIER_SILVER   = 100;
    public const TIER_GOLD     = 250;
    public const TIER_PLATINUM = 450;

    public const TIERS = [
        'bronze'   => ['min' => 0,   'max' => 99,  'label' => '🟤 Bronze',   'auto_aprovacao' => false],
        'silver'   => ['min' => 100, 'max' => 249, 'label' => '⚪ Silver',   'auto_aprovacao' => false],
        'gold'     => ['min' => 250, 'max' => 449, 'label' => '🟡 Gold',     'auto_aprovacao' => true],
        'platinum' => ['min' => 450, 'max' => PHP_INT_MAX, 'label' => '💎 Platinum', 'auto_aprovacao' => true],
    ];

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    // ════════════════════════════════════════════════════
    // CÁLCULO
    // ════════════════════════════════════════════════════

    /**
     * Recalcula e persiste o score de um cliente.
     * Idempotente — pode ser chamado múltiplas vezes.
     */
    public function recalcular(int $clienteId): array {
        $fatores = $this->coletarFatores($clienteId);
        $score   = $this->calcularScore($fatores);
        $tier    = $this->getTierByScore($score);

        // Se há override manual, usa o score overrideado para o tier
        $row = $this->getRow($clienteId);
        if ($row && $row['override_manual'] && $row['override_score'] !== null) {
            $tier  = $this->getTierByScore((int)$row['override_score']);
            $score = (int)$row['override_score'];
        }

        $this->persistir($clienteId, $score, $tier, $fatores);

        return compact('score', 'tier', 'fatores');
    }

    /**
     * Recalcula todos os clientes — para o cron noturno.
     */
    public function recalcularTodos(): int {
        $stmt = $this->db->query(
            "SELECT DISTINCT c.id FROM clientes c
             LEFT JOIN clientes_score cs ON cs.cliente_id = c.id
             WHERE cs.calculado_em IS NULL
                OR cs.calculado_em < DATE_SUB(NOW(), INTERVAL 12 HOUR)"
        );
        $ids   = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $count = 0;
        foreach ($ids as $id) {
            try {
                $this->recalcular((int)$id);
                $count++;
            } catch (\Throwable $e) {
                error_log("[ScoreService] recalcularTodos cliente {$id}: " . $e->getMessage());
            }
        }
        return $count;
    }

    /**
     * Override manual pelo admin (congela o score até ser revertido).
     */
    public function overrideManual(
        int     $clienteId,
        int     $novoScore,
        string  $motivo,
        int     $adminId
    ): void {
        $tier = $this->getTierByScore($novoScore);
        $this->db->prepare(
            "INSERT INTO clientes_score
             (cliente_id, score_total, tier, override_manual, override_score,
              override_motivo, override_admin_id, override_em, calculado_em)
             VALUES (?,?,?,1,?,?,?,NOW(),NOW())
             ON DUPLICATE KEY UPDATE
                score_total        = VALUES(score_total),
                tier               = VALUES(tier),
                override_manual    = 1,
                override_score     = VALUES(override_score),
                override_motivo    = VALUES(override_motivo),
                override_admin_id  = VALUES(override_admin_id),
                override_em        = NOW(),
                calculado_em       = NOW()"
        )->execute([$clienteId, $novoScore, $tier, $novoScore, $motivo, $adminId]);
    }

    /**
     * Remove o override — score volta ao calculado automaticamente.
     */
    public function removerOverride(int $clienteId): void {
        $this->db->prepare(
            "UPDATE clientes_score
             SET override_manual = 0, override_score = NULL,
                 override_motivo = NULL, override_admin_id = NULL,
                 override_em = NULL
             WHERE cliente_id = ?"
        )->execute([$clienteId]);
        $this->recalcular($clienteId);
    }

    // ════════════════════════════════════════════════════
    // LEITURA
    // ════════════════════════════════════════════════════

    public function getRow(int $clienteId): ?array {
        $stmt = $this->db->prepare(
            "SELECT * FROM clientes_score WHERE cliente_id = ? LIMIT 1"
        );
        $stmt->execute([$clienteId]);
        return $stmt->fetch() ?: null;
    }

    public function getTier(int $clienteId): string {
        $row = $this->getRow($clienteId);
        if (!$row) {
            // Ainda não calculado — calcula agora
            $result = $this->recalcular($clienteId);
            return $result['tier'];
        }
        return $row['tier'];
    }

    public function podeAutoAprovar(int $clienteId): bool {
        $tier = $this->getTier($clienteId);
        return self::TIERS[$tier]['auto_aprovacao'] ?? false;
    }

    // ════════════════════════════════════════════════════
    // HELPERS
    // ════════════════════════════════════════════════════

    public function getTierByScore(int $score): string {
        if ($score >= self::TIER_PLATINUM) return 'platinum';
        if ($score >= self::TIER_GOLD)     return 'gold';
        if ($score >= self::TIER_SILVER)   return 'silver';
        return 'bronze';
    }

    /**
     * Coleta todos os dados brutos necessários para o cálculo.
     */
    private function coletarFatores(int $clienteId): array {
        // LTV + pedidos
        $stmt = $this->db->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN status_pagamento = 'aprovado' THEN total END), 0) AS ltv,
                COUNT(*) AS total_pedidos,
                SUM(CASE WHEN status_pedido = 'entregue' THEN 1 ELSE 0 END) AS concluidos,
                SUM(CASE WHEN status_pagamento = 'aprovado' THEN 1 ELSE 0 END) AS pgto_aprovados
             FROM pedidos WHERE cliente_id = ?"
        );
        $stmt->execute([$clienteId]);
        $pedidos = $stmt->fetch();

        // Devoluções
        $stmt = $this->db->prepare(
            "SELECT
                COUNT(*) AS total_dev,
                SUM(CASE WHEN inspecao_resultado = 'reprovado' THEN 1 ELSE 0 END) AS reprovadas,
                SUM(CASE WHEN status = 'concluido' THEN 1 ELSE 0 END) AS concluidas
             FROM solicitacoes_devolucao
             WHERE cliente_id = ? AND status NOT IN ('cancelado','expirado')"
        );
        $stmt->execute([$clienteId]);
        $devs = $stmt->fetch();

        // Chargebacks (pedidos com status_pagamento = 'estornado' por iniciativa do banco)
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS chargebacks
             FROM pedidos
             WHERE cliente_id = ? AND status_pagamento = 'estornado'"
        );
        $stmt->execute([$clienteId]);
        $cb = $stmt->fetch();

        // Idade da conta
        $stmt = $this->db->prepare(
            "SELECT DATEDIFF(NOW(), criado_em) AS dias FROM usuarios
             WHERE id = (SELECT usuario_id FROM clientes WHERE id = ?)"
        );
        $stmt->execute([$clienteId]);
        $conta = $stmt->fetch();

        $totalPedidos = max(1, (int)$pedidos['total_pedidos']);
        $totalDev     = (int)($devs['total_dev'] ?? 0);

        return [
            'ltv_total'          => (float)($pedidos['ltv']         ?? 0),
            'total_pedidos'      => (int)$pedidos['total_pedidos'],
            'total_concluidos'   => (int)($pedidos['concluidos']     ?? 0),
            'taxa_aprovacao_pag' => round((int)($pedidos['pgto_aprovados'] ?? 0) / $totalPedidos, 4),
            'dias_conta'         => (int)($conta['dias']             ?? 0),
            'total_devolucoes'   => $totalDev,
            'total_reprovadas'   => (int)($devs['reprovadas']        ?? 0),
            'total_chargebacks'  => (int)($cb['chargebacks']         ?? 0),
            'taxa_devolucao'     => $totalDev > 0
                ? round($totalDev / $totalPedidos, 4)
                : 0.0,
        ];
    }

    /**
     * Aplica a fórmula de score.
     * Máximo teórico: ~600 pts.
     */
    private function calcularScore(array $f): int {
        // Pontos positivos
        $ltv      = min($f['ltv_total']       / 50,   300);  // max 300 com R$15k
        $pedidos  = min($f['total_concluidos'] * 8,   150);  // max 150 com 19 pedidos
        $conta    = min($f['dias_conta']       / 20,  100);  // max 100 com ~5.5 anos
        $pagto    = $f['taxa_aprovacao_pag']   * 50;         // max 50

        // Penalidades
        $pDevol   = min($f['taxa_devolucao']   * 200, 150);  // max -150
        $pReprov  = $f['total_reprovadas']     * 50;          // -50 cada
        $pCharge  = $f['total_chargebacks']    * 150;         // -150 cada

        $score = ($ltv + $pedidos + $conta + $pagto) - ($pDevol + $pReprov + $pCharge);

        return max(0, (int)round($score));
    }

    private function persistir(
        int    $clienteId,
        int    $score,
        string $tier,
        array  $f
    ): void {
        $this->db->prepare(
            "INSERT INTO clientes_score
             (cliente_id, score_total, tier,
              ltv_total, total_pedidos, total_pedidos_concluidos,
              dias_conta, taxa_aprovacao_pag,
              total_devolucoes, total_reprovadas, total_chargebacks,
              taxa_devolucao, calculado_em)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE
                score_total               = IF(override_manual, score_total, VALUES(score_total)),
                tier                      = IF(override_manual, tier,        VALUES(tier)),
                ltv_total                 = VALUES(ltv_total),
                total_pedidos             = VALUES(total_pedidos),
                total_pedidos_concluidos  = VALUES(total_pedidos_concluidos),
                dias_conta                = VALUES(dias_conta),
                taxa_aprovacao_pag        = VALUES(taxa_aprovacao_pag),
                total_devolucoes          = VALUES(total_devolucoes),
                total_reprovadas          = VALUES(total_reprovadas),
                total_chargebacks         = VALUES(total_chargebacks),
                taxa_devolucao            = VALUES(taxa_devolucao),
                calculado_em              = NOW()"
        )->execute([
            $clienteId, $score, $tier,
            $f['ltv_total'], $f['total_pedidos'], $f['total_concluidos'],
            $f['dias_conta'], $f['taxa_aprovacao_pag'],
            $f['total_devolucoes'], $f['total_reprovadas'], $f['total_chargebacks'],
            $f['taxa_devolucao'],
        ]);
    }
}