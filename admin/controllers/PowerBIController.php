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
                'ia'            => $this->configIa(),
            ],
        ], 'admin');
    }

    /**
     * O que o botão "Analisar com IA" precisa saber: qual agente atende
     * cada página, o que sugerir, e se existe modelo de agente
     * configurado — sem isso o botão avisa em vez de falhar no clique.
     */
    private function configIa(): array {
        $disponivel = false;
        try {
            $disponivel = (new IAOrchestrator())->modelosDaCapacidade('agente', null) !== [];
        } catch (\Throwable $e) { /* catálogo ausente = indisponível */ }

        return [
            'perguntar_url' => BASE_URL . '/admin/power-bi/ia/perguntar',
            'conversa_url'  => BASE_URL . '/admin/power-bi/ia/conversa',
            'conversas_url' => BASE_URL . '/admin/power-bi/ia/conversas',
            'paginas'       => IAAgenteGateway::mapaPaginas(),
            'padrao'        => IAAgenteGateway::mapaPadroes(),
            'agentes'       => IAAgenteService::catalogoParaTela(),
            'disponivel'    => $disponivel,
            'permitido'     => (new IAPermissaoService())->pode('marketing_ia'),
            // "Resumo Executivo de Hoje": a última rodada agendada de cada
            // agente. Leitura pura — abrir o painel nunca chama IA.
            'resumos'       => (new IAAgenteService())->resumosParaTela(),
        ];
    }

    // ── POST /admin/power-bi/ia/perguntar ─────────────────
    // Uma pergunta ao agente da página. Síncrono: o drawer espera.
    public function iaPerguntar(): void {
        $this->verifyCsrf();
        if (!(new IAPermissaoService())->pode('marketing_ia')) {
            $this->json(['ok' => false, 'msg' => 'Sem permissão para consultar a IA.'], 403);
        }
        // Autoria e dono da conversa são a PESSOA (usuarios.id), nunca
        // admins.id — CLAUDE.md §4.1. Admin sem vínculo não conversa.
        $usuarioId = AuthHelper::usuarioId();
        if ($usuarioId <= 0) {
            $this->json(['ok' => false, 'msg' => 'Acesso não vinculado a um usuário do sistema.'], 403);
        }

        $agente   = preg_replace('/[^a-z_]/', '', (string)($_POST['agente'] ?? ''));
        $pergunta = (string)($_POST['pergunta'] ?? '');
        $conversa = preg_replace('/[^0-9a-f-]/', '', (string)($_POST['conversa'] ?? '')) ?: null;
        $ctx = [
            'pagina'  => preg_replace('/[^a-z_]/', '', (string)($_POST['pagina'] ?? '')),
            'periodo' => $this->periodoValido((string)($_POST['periodo'] ?? '30d')),
        ];

        $this->json((new IAAgenteService())->perguntar($agente, $pergunta, $ctx, $conversa, $usuarioId));
    }

    // ── GET /admin/power-bi/ia/conversas?agente= ──────────
    // O histórico do botão: as conversas da pessoa + as do sistema.
    public function iaConversas(): void {
        $agente = preg_replace('/[^a-z_]/', '', (string)($_GET['agente'] ?? ''));
        $this->json([
            'ok'        => true,
            'conversas' => (new IAAgenteService())->listarConversas($agente, AuthHelper::usuarioId(), 20),
        ]);
    }

    // ── GET /admin/power-bi/ia/conversa?uuid= ─────────────
    // Reabre uma conversa. 404 uniforme para conversa alheia.
    public function iaConversa(): void {
        $uuid = preg_replace('/[^0-9a-f-]/', '', (string)($_GET['uuid'] ?? ''));
        $h = $uuid !== '' ? (new IAAgenteService())->historico($uuid, AuthHelper::usuarioId()) : null;
        if ($h === null) {
            $this->json(['ok' => false, 'msg' => 'Conversa não encontrada.'], 404);
        }
        $this->json(['ok' => true] + $h);
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
