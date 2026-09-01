<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// admin/controllers/PowerBIController.php
// ════════════════════════════════════════════════════════

/**
 * Painel de BI do admin.
 *
 * Antes: 9 linhas que renderizavam uma view de 0 byte, por uma rota
 * registrada como POST (portanto inalcançável por navegação). O
 * layout rico (`powerbi/pwb-dashboard.php`) existia mas nunca era
 * renderizado, e dependia de um service que nunca foi escrito.
 *
 * Agora: rota GET, layout certo, dados vindos das views `bi_*`.
 */
class PowerBIController extends Controller {

    private PwbDashboardAnalyticsService $analytics;

    public function __construct() {
        AuthHelper::requireAdmin();
        // O painel expõe custo, margem e lucro — mesmo nível de
        // Promoções e Cupons (CLAUDE.md §4.6).
        AuthHelper::requireAdminLevel('super', 'gerente');
        $this->analytics = new PwbDashboardAnalyticsService();
    }

    // ── GET /admin/power-bi ───────────────────────────────
    public function index(): void {
        $periodo = $this->periodoValido($_GET['periodo'] ?? '30d');

        $this->render('powerbi/pwb-dashboard', [
            'pwb_dashboard_data'   => $this->analytics->getDashboardData($periodo),
            'pwb_dashboard_config' => [
                // A própria rota serve o JSON — a troca de período no
                // topo não precisa recarregar a página inteira.
                'api_url'       => BASE_URL . '/admin/power-bi/dados',
                'settings_url'  => BASE_URL . '/admin/bi/metas',
                'user_initials' => $this->iniciais(),
            ],
        ], 'admin');
    }

    // ── GET /admin/power-bi/dados ─────────────────────────
    // Mesmo payload da página, em JSON, para troca de período.
    public function dados(): void {
        $this->json([
            'ok'    => true,
            'dados' => $this->analytics->getDashboardData(
                $this->periodoValido($_GET['periodo'] ?? '30d')
            ),
        ]);
    }

    /**
     * Whitelist do período. Sem isto o valor cairia direto no
     * BiService e um período inventado devolveria janela vazia — que
     * na tela parece "a loja não vendeu nada".
     */
    private function periodoValido(string $p): string {
        return array_key_exists($p, BiService::PERIODOS) ? $p : '30d';
    }

    private function iniciais(): string {
        $adm   = AuthHelper::adminDisplay();
        $nome  = trim((string)($adm['nome'] ?? ''));
        if ($nome === '') return 'AD';

        $partes = preg_split('/\s+/', $nome);
        $ini    = mb_substr($partes[0], 0, 1);
        if (count($partes) > 1) $ini .= mb_substr(end($partes), 0, 1);

        return mb_strtoupper($ini);
    }
}
