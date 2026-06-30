<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/controllers/AdminCreditoScoreController.php
// Gerencia score e crédito de clientes no painel admin
// ════════════════════════════════════════════════════════

class AdminCreditoScoreController extends Controller {

    private ScoreService   $score;
    private CreditoService $credito;
    private PDO            $db;

    public function __construct() {
        // parent::__construct();
        AuthHelper::requireAdmin();
        $this->score   = new ScoreService();
        $this->credito = new CreditoService();
        $this->db      = Database::getInstance()->getConnection();
    }

    // ── GET /admin/clientes/{id}/score-credito ────────────
    public function index(int $clienteId): void {
        AuthHelper::requireAdminLevel('super','gerente');

        $cliente   = $this->getCliente($clienteId);
        // if (!$cliente) $this->notFound();

        // Garante que o score existe
        $scoreRow  = $this->score->getRow($clienteId)
                  ?? ($this->score->recalcular($clienteId) && $this->score->getRow($clienteId));

        $saldo     = $this->credito->getSaldoDisponivel($clienteId);
        $historico = $this->credito->getHistorico($clienteId, 30);
        $expirando = $this->credito->getProximosExpirando($clienteId, 60);
        $tiers     = ScoreService::TIERS;

        $this->render('clientes/score-credito', compact(
            'cliente','scoreRow','saldo','historico','expirando','tiers'
        ), 'admin');
    }

    // ── POST /admin/clientes/{id}/score/override ──────────
    public function overrideScore(int $clienteId): void {
        AuthHelper::requireAdminLevel('super');
        $this->verifyCsrf();

        $novoScore = (int)($_POST['score']  ?? 0);
        $motivo    = SecurityHelper::sanitizeString($_POST['motivo'] ?? '');
        $adminId   = (int)Session::get('admin_id');

        if (empty($motivo)) {
            $this->json(['ok' => false, 'msg' => 'Informe o motivo do ajuste.']);
        }
        if ($novoScore < 0) {
            $this->json(['ok' => false, 'msg' => 'Score não pode ser negativo.']);
        }

        $this->score->overrideManual($clienteId, $novoScore, $motivo, $adminId);
        $tier = $this->score->getTierByScore($novoScore);

        $this->json([
            'ok'    => true,
            'msg'   => 'Score ajustado manualmente.',
            'score' => $novoScore,
            'tier'  => $tier,
            'tier_label' => ScoreService::TIERS[$tier]['label'],
        ]);
    }

    // ── POST /admin/clientes/{id}/score/remover-override ──
    public function removerOverride(int $clienteId): void {
        AuthHelper::requireAdminLevel('super');
        $this->verifyCsrf();

        $this->score->removerOverride($clienteId);
        $row = $this->score->getRow($clienteId);

        $this->json([
            'ok'    => true,
            'msg'   => 'Override removido. Score recalculado automaticamente.',
            'score' => $row['score_total'] ?? 0,
            'tier'  => $row['tier'] ?? 'bronze',
        ]);
    }

    // ── POST /admin/clientes/{id}/credito/lancar ──────────
    public function lancarCredito(int $clienteId): void {
        AuthHelper::requireAdminLevel('super','gerente');
        $this->verifyCsrf();

        $valor       = (float)str_replace(',','.', $_POST['valor']       ?? '0');
        $descricao   = SecurityHelper::sanitizeString($_POST['descricao']  ?? '');
        $dias        = !empty($_POST['dias_expiracao']) ? (int)$_POST['dias_expiracao'] : null;
        $adminId     = (int)Session::get('admin_id');

        if ($valor <= 0) {
            $this->json(['ok' => false, 'msg' => 'Valor deve ser maior que zero.']);
        }
        if (empty($descricao)) {
            $this->json(['ok' => false, 'msg' => 'Informe a descrição do crédito.']);
        }

        try {
            $txId = $this->credito->creditar(
                $clienteId,
                $valor,
                'credito_manual',
                $descricao,
                $dias,
                'manual',
                null,
                $adminId
            );

            $novoSaldo = $this->credito->getSaldoDisponivel($clienteId);

            $this->json([
                'ok'          => true,
                'msg'         => 'Crédito lançado com sucesso.',
                'tx_id'       => $txId,
                'novo_saldo'  => $novoSaldo,
                'saldo_fmt'   => PriceHelper::format($novoSaldo),
                'cliente'=>$clienteId
            ]);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }

    // ── POST /admin/clientes/{id}/credito/debitar ─────────
    // Débito manual (ex: estorno de crédito lançado errado)
    public function debitarCredito(int $clienteId): void {
        AuthHelper::requireAdminLevel('super');
        $this->verifyCsrf();

        $valor     = (float)str_replace(',','.', $_POST['valor']     ?? '0');
        $descricao = SecurityHelper::sanitizeString($_POST['descricao'] ?? '');

        if ($valor <= 0) {
            $this->json(['ok' => false, 'msg' => 'Valor inválido.']);
        }

        try {
            $this->credito->debitar(
                $clienteId, $valor, 'debito_estorno',
                $descricao ?: 'Débito manual admin', 'manual'
            );
            $novoSaldo = $this->credito->getSaldoDisponivel($clienteId);
            $this->json([
                'ok'        => true,
                'msg'       => 'Débito realizado.',
                'novo_saldo'=> $novoSaldo,
                'saldo_fmt' => PriceHelper::format($novoSaldo),
            ]);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }

    // ── POST /admin/clientes/{id}/score/recalcular ────────
    public function recalcularScore(int $clienteId): void {
        AuthHelper::requireAdminLevel('super','gerente');
        $this->verifyCsrf();

        $result = $this->score->recalcular($clienteId);
        $this->json([
            'ok'         => true,
            'score'      => $result['score'],
            'tier'       => $result['tier'],
            'tier_label' => ScoreService::TIERS[$result['tier']]['label'],
        ]);
    }

    // ── Privado ───────────────────────────────────────────
    private function getCliente(int $id): ?array {
        $stmt = $this->db->prepare(
            "SELECT c.*, u.nome, u.email, u.criado_em AS conta_criada_em
             FROM clientes c JOIN usuarios u ON u.id = c.usuario_id
             WHERE u.id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
}