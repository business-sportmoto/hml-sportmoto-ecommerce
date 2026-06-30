<?php
/**
 * admin/controllers/CanalLogAdminController.php
 *
 * Controller unificado de logs de canais de comunicação.
 * Atende: /admin/configuracoes/logs/canais (todos os canais)
 *         /admin/configuracoes/logs/whatsapp (filtrado por canal)
 *         /admin/configuracoes/logs/email-transacional (filtrado por canal)
 */
class CanalLogAdminController extends Controller
{
    private const PER_PAGE = 30;

    public function __construct()
    {
        // parent::__construct();
        $this->requirePermission();
    }

    private function requirePermission(): void
    {
        if (method_exists('AuthHelper', 'requirePermission')) {
            try { AuthHelper::requirePermission('email_marketing'); return; } catch (Throwable $e) {}
        }
        if (method_exists('AuthHelper', 'requireAdminLevel')) { AuthHelper::requireAdminLevel(); return; }
        AuthHelper::requireAdmin();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LISTA UNIFICADA
    // ─────────────────────────────────────────────────────────────────────────

    public function index(): void
    {
        $this->renderLog(null);
    }

    /** Filtrado por canal — /admin/configuracoes/logs/whatsapp */
    public function whatsapp(): void
    {
        $this->renderLog('whatsapp');
    }

    /** Filtrado por canal email */
    public function emailTransacional(): void
    {
        $this->renderLog('email');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DETALHE DE UM REGISTRO
    // ─────────────────────────────────────────────────────────────────────────

    public function detalhe(): void
    {
        $id  = (int)($_GET['id'] ?? 0);
        $db  = Database::getInstance()->getConnection();
        $st  = $db->prepare("SELECT * FROM canal_log WHERE id = :id LIMIT 1");
        $st->execute([':id' => $id]);
        $log = $st->fetch(PDO::FETCH_ASSOC);

        if (!$log) {
            $this->render('logs/canal/not-found', ['titulo' => 'Log não encontrado'], 'admin');
            return;
        }

        // Histórico do pedido (todos os canais)
        $historico = [];
        if (!empty($log['pedido_id'])) {
            $historico = CanalLogService::porPedido((int)$log['pedido_id']);
        }

        // Contexto decodificado
        $contexto = [];
        if (!empty($log['contexto_json'])) {
            $contexto = json_decode($log['contexto_json'], true) ?: [];
        }

        $this->render('logs/canal/detalhe', [
            'log'       => $log,
            'historico' => $historico,
            'contexto'  => $contexto,
            'titulo'    => 'Detalhe do log #' . $id,
        ], 'admin');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // BUSCA POR PEDIDO (chamada via jQuery $.ajax)
    // ─────────────────────────────────────────────────────────────────────────

    public function buscaPedido()
    {
        $codigo = trim($_GET['q'] ?? '');
        if ($codigo === '') {
            return $this->json(['ok' => false, 'erro' => 'Informe um código de pedido']);
        }

        $db = Database::getInstance()->getConnection();

        // Tenta pedido_codigo primeiro, depois busca na mensagem/preview
        $st = $db->prepare(
            "SELECT * FROM canal_log
             WHERE pedido_codigo LIKE :q
                OR preview LIKE :q2
             ORDER BY id DESC LIMIT 50"
        );
        $st->execute([':q' => '%' . $codigo . '%', ':q2' => '%' . $codigo . '%']);
        $itens = $st->fetchAll(PDO::FETCH_ASSOC);

        return $this->json(['ok' => true, 'itens' => $itens, 'total' => count($itens)]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // EXPORTAÇÃO CSV
    // ─────────────────────────────────────────────────────────────────────────

    public function exportar()
    {
        $filtros = $this->filtrosFromRequest();
        $canal   = trim($_GET['canal'] ?? '');
        if ($canal !== '') $filtros['canal'] = $canal;

        $dados = CanalLogService::buscar($filtros, 5000, 0);

        if (class_exists('LogService')) {
            try {
                LogService::audit('canal_log_exportar', [
                    'canal'   => $canal,
                    'filtros' => $filtros,
                    'total'   => $dados['total'],
                ]);
            } catch (Throwable $e) {}
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="canal_log_' . date('Ymd_His') . '.csv"');
        header('Cache-Control: no-cache');

        echo "\xEF\xBB\xBF"; // BOM UTF-8
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID', 'Canal', 'Tipo', 'Destinatário', 'Pedido', 'Status', 'Preview', 'Erro', 'Data'], ';');

        foreach ($dados['itens'] as $row) {
            fputcsv($out, [
                $row['id'],
                $row['canal'],
                $row['tipo'],
                $row['destinatario'],
                $row['pedido_codigo'] ?? '',
                $row['status'],
                $row['preview'] ?? '',
                $row['erro_detalhe'] ?? '',
                $row['criado_em'],
            ], ';');
        }
        fclose($out);
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS INTERNOS
    // ─────────────────────────────────────────────────────────────────────────

    private function renderLog(?string $canalFixo): void
    {
        $filtros = $this->filtrosFromRequest();
        if ($canalFixo !== null) {
            $filtros['canal'] = $canalFixo;
        }

        $pag    = max(1, (int)($_GET['pag'] ?? 1));
        $offset = ($pag - 1) * self::PER_PAGE;

        $resultado = CanalLogService::buscar($filtros, self::PER_PAGE, $offset);
        $kpis      = CanalLogService::kpis(30, $canalFixo ?? '');
        $kpiCanais = CanalLogService::porCanal(30);
        $canais    = CanalLogService::canaisDistintos();
        $tipos     = CanalLogService::tiposDistintos($canalFixo);

        $titulos = [
            'whatsapp' => 'Logs do WhatsApp',
            'email'    => 'Logs de Email Transacional',
            null       => 'Logs de Canais',
        ];

        $this->render('logs/canal/index', [
            'itens'      => $resultado['itens'],
            'total'      => $resultado['total'],
            'pag'        => $pag,
            'per_pag'    => self::PER_PAGE,
            'paginas'    => (int)ceil($resultado['total'] / self::PER_PAGE),
            'kpis'       => $kpis,
            'kpi_canais' => $kpiCanais,
            'canais'     => $canais,
            'tipos'      => $tipos,
            'filtros'    => $filtros,
            'canal_fixo' => $canalFixo,
            'titulo'     => $titulos[$canalFixo] ?? $titulos[null],
        ], 'admin');
    }

    private function filtrosFromRequest(): array
    {
        $campos = ['canal','tipo','status','destinatario','busca','pedido_codigo','data_inicio','data_fim','via'];
        $filtros = [];
        foreach ($campos as $c) {
            $v = trim($_GET[$c] ?? '');
            if ($v !== '') $filtros[$c] = $v;
        }
        return $filtros;
    }
}
