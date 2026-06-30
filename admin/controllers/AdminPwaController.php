<?php
declare(strict_types=1);

/**
 * app/controllers/AdminPwaController.php
 */
class AdminPwaController extends Controller
{
    private PwaConfigService $service;

    public function __construct()
    {
        // parent::__construct();
        AuthHelper::requireAdmin();
        AuthHelper::requireAdminLevel('super', 'gerente');
        $this->service = new PwaConfigService();
    }

    // ── GET /admin/configuracoes/pwa ──────────────────────
    public function index(): void
    {
        $config = $this->service->getConfig();
        $this->render('configuracoes/pwa', [
            'config'  => $config,
            'icones'  => $this->listarIconesGerados(),
            'titulo'  => 'Configurações do PWA',
        ], 'admin');
    }

    // ── POST /admin/configuracoes/pwa/salvar ──────────────
    public function salvar(): void
    {
        $this->verifyCsrf();

        $nome      = SecurityHelper::sanitizeString($_POST['app_name']        ?? '');
        $nomeShort = SecurityHelper::sanitizeString($_POST['app_short_name']  ?? '');
        $descricao = SecurityHelper::sanitizeString($_POST['app_description'] ?? '');
        $theme     = SecurityHelper::sanitizeString($_POST['theme_color']     ?? '#0f172a');
        $bg        = SecurityHelper::sanitizeString($_POST['background_color']?? '#0f172a');

        if (empty($nome) || empty($nomeShort)) {
            $this->json(['ok' => false, 'msg' => 'Nome e nome curto são obrigatórios.']);
        }

        $this->service->salvarCampos($nome, $nomeShort, $descricao, $theme, $bg);

        // Se a cor de fundo mudou e já há ícones, regenera automaticamente
        $config = $this->service->getConfig();
        if ($config['icones_gerados'] && $config['background_color'] !== $bg) {
            try {
                $this->service->regenerarComNovaCor($bg);
            } catch (\Throwable) {
                // não bloqueia o save, apenas avisa
            }
        }

        $this->json(['ok' => true, 'msg' => 'Configurações salvas.']);
    }

    // ── POST /admin/configuracoes/pwa/gerar-icones ────────
    public function gerarIcones(): void
    {
        $this->verifyCsrf();

        if (empty($_FILES['icone']['tmp_name'])) {
            $this->json(['ok' => false, 'msg' => 'Nenhum arquivo enviado.']);
        }

        try {
            $gerados = $this->service->gerarIcones($_FILES['icone']);
            $teste = [
                'ok'     => true,
                'msg'    => count($gerados) . ' ícones gerados com sucesso.',
                'icones' => $this->listarIconesGerados(),
            ];
            LogService::info('sucesso no icone', $teste);
            //teste
            $this->json($teste);
        } catch (\Throwable $e) {
            LogService::error('erro no icone', [$e]);
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }

    // ── POST /admin/configuracoes/pwa/publicar ────────────
    public function publicar(): void
    {
        $this->verifyCsrf();

        $config = $this->service->getConfig();
        if (!$config['icones_gerados']) {
            $this->json(['ok' => false, 'msg' => 'Gere os ícones antes de publicar.']);
        }

        try {
            $versao = $this->service->publicar();
            $this->json([
                'ok'     => true,
                'msg'    => "Publicado! Cache version atualizado para {$versao}.",
                'versao' => $versao,
            ]);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }

    // ── GET /manifest.json (rota pública) ─────────────────
    public function manifest(): void
    {
        header('Content-Type: application/manifest+json; charset=UTF-8');
        header('Cache-Control: public, max-age=3600');
        echo json_encode(
            $this->service->getManifest(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }

    // ─────────────────────────────────────────────────────
    private function listarIconesGerados(): array
    {
        $dir = ROOT_PATH . '/assets/images';
        if (!is_dir($dir)) return [];

        $icones = [];
        $mapa   = [
            'icon-192.png'          => ['label' => 'Android 192×192',     'size' => '192×192'],
            'icon-512.png'          => ['label' => 'Android 512×512',     'size' => '512×512'],
            'icon-maskable-192.png' => ['label' => 'Maskable 192×192',    'size' => '192×192'],
            'icon-maskable-512.png' => ['label' => 'Maskable 512×512',    'size' => '512×512'],
            'apple-touch-icon.png'  => ['label' => 'iOS 180×180',         'size' => '180×180'],
            'shortcut-pedidos.png'  => ['label' => 'Shortcut Pedidos',    'size' => '96×96'],
            'shortcut-carrinho.png' => ['label' => 'Shortcut Carrinho',   'size' => '96×96'],
            'shortcut-garagem.png'  => ['label' => 'Shortcut Garagem',    'size' => '96×96'],
        ];

        foreach ($mapa as $file => $info) {
            $path = $dir . '/' . $file;
            $icones[] = [
                'file'   => $file,
                'label'  => $info['label'],
                'size'   => $info['size'],
                'existe' => file_exists($path),
                'url'    => BASE_URL . '/assets/images/' . $file . '?t=' . (file_exists($path) ? filemtime($path) : 0),
            ];
        }
        return $icones;
    }
}