<?php
// app/controllers/PageController.php
// Serve qualquer página da pasta /pages/ automaticamente.

class PageController extends Controller {

    private string $pagesDir;

    public function __construct() {
        $this->pagesDir = ROOT_PATH . '/pages';
    }

    /**
     * Detecta a pasta correspondente ao slug e renderiza.
     * Rota: qualquer /{slug} que não casou com rotas anteriores.
     */
    public function show(string $slug): void {
        $slug    = preg_replace('/[^a-z0-9\-]/', '', strtolower($slug));
        $pageDir = $this->pagesDir . '/' . $slug;

        // Ordem: arquivo primeiro, banco depois.
        //
        // As landing pages montadas em /pages tem CSS e JS proprios e sao
        // versionadas no git; elas ganham do banco de proposito, para que criar
        // uma pagina no painel nunca possa derrubar uma pagina de campanha. O
        // PaginaService recusa slug que ja exista em arquivo, entao o conflito
        // e barrado na origem — isto aqui e a segunda linha de defesa.
        if (!is_dir($pageDir) || !file_exists($pageDir . '/index.php')) {
            if ($this->mostrarDoBanco($slug)) return;

            http_response_code(404);
            $this->render('errors/404', [], 'main');
            return;
        }

        $config = $this->loadConfig($pageDir);

        if (!($config['ativa'] ?? true)) {
            http_response_code(404);
            $this->render('errors/404', [], 'main');
            return;
        }

        SeoHelper::setTitle($config['titulo'] ?? $slug);
        if (!empty($config['descricao'])) SeoHelper::setDescription($config['descricao']);
        SeoHelper::setCanonical(BASE_URL . '/' . $slug);

        // Breadcrumb só se habilitado no page.json (padrão: false)
        $usaBreadcrumb = (bool)($config['breadcrumb'] ?? false);
        $breadcrumb    = [];

        if ($usaBreadcrumb) {
            $breadcrumb = [
                ['label' => 'Início', 'url' => BASE_URL],
                ['label' => $config['titulo'] ?? $slug, 'url' => null],
            ];
            SeoHelper::setBreadcrumb($breadcrumb);
        }

        $assets      = $this->resolveAssets($slug, $pageDir, $config);
        $layout      = $config['layout'] ?? 'main';
        $pageContent = $this->capturePageContent($pageDir . '/index.php', [
            'config'  => $config,
            'slug'    => $slug,
            'pageDir' => $pageDir,
            'pageUrl' => BASE_URL . '/' . $slug,
            'imgsUrl' => BASE_URL . '/pages/' . $slug . '/imgs',
        ]);

        $this->render('page-wrapper', [
            'page_content'   => $pageContent, 
            'page_config'    => $config,
            'page_assets'    => $assets,
            'page_slug'      => $slug,
            'usa_breadcrumb' => $usaBreadcrumb,
            'breadcrumb'     => $breadcrumb,
            'extra_css'      => isset($assets['css']) ? (is_array($assets['css']) ? $assets['css'] : [$assets['css']]) : [],
            'extra_js'       => isset($assets['js'])  ? (is_array($assets['js']) ? $assets['js'] : [$assets['js']]) : [],
        ], $layout);
    }

    /**
     * Renderiza uma página de conteúdo vinda da tabela `paginas`.
     *
     * Devolve false quando não existe, para o chamador seguir para o 404 — em
     * vez de renderizar o 404 aqui e deixar duas telas de erro no mesmo fluxo.
     */
    private function mostrarDoBanco(string $slug): bool
    {
        $pagina = (new PaginaService())->porSlug($slug);
        if (!$pagina) return false;

        SeoHelper::setTitle(($pagina['meta_title'] ?? '') !== '' ? $pagina['meta_title'] : $pagina['titulo']);
        if (!empty($pagina['meta_description'])) SeoHelper::setDescription($pagina['meta_description']);
        SeoHelper::setCanonical(BASE_URL . '/' . $slug);
        if (!empty($pagina['noindex'])) SeoHelper::setRobots('noindex, follow');

        $breadcrumb = [
            ['label' => 'Início', 'url' => BASE_URL],
            ['label' => $pagina['titulo'], 'url' => null],
        ];
        SeoHelper::setBreadcrumb($breadcrumb);

        $conteudo = $this->capturePageContent(ROOT_PATH . '/views/pagina-conteudo.php', ['pagina' => $pagina]);

        // Mesmo wrapper das páginas de arquivo: uma página do painel tem que
        // sair visualmente igual a uma página montada à mão.
        $this->render('page-wrapper', [
            'page_content'   => $conteudo,
            'page_config'    => $pagina,
            'page_assets'    => ['css' => [], 'js' => []],
            'page_slug'      => $slug,
            'usa_breadcrumb' => true,
            'breadcrumb'     => $breadcrumb,
            'extra_css'      => [],
            'extra_js'       => [],
        ], 'main');

        return true;
    }

    /**
     * Lê e faz parse do page.json da página.
     */
    private function loadConfig(string $pageDir): array {
        $jsonFile = $pageDir . '/page.json';
        if (!file_exists($jsonFile)) return [];

        $json = json_decode(file_get_contents($jsonFile), true);
        return is_array($json) ? $json : [];
    }

    /**
     * Verifica quais assets opcionais existem.
     */
    private function resolveAssets(string $slug, string $pageDir, array $config): array
{
    $assets = [
        'css' => [],
        'js'  => []
    ];

    $baseUrl = BASE_URL . '/pages/' . $slug;

    // CSS vindo do JSON
    if (!empty($config['css']) && is_array($config['css'])) {
        foreach ($config['css'] as $cssFile) {
            $path = $pageDir . '/' . $cssFile;

            if (file_exists($path)) {
                $v = filemtime($path);
                $assets['css'][] = $baseUrl . '/' . $cssFile . '?v=' . $v;
            }
        }
    } else {
        // CSS padrão apenas se NÃO existir css no JSON
        $path = $pageDir . '/style.css';

        if (file_exists($path)) {
            $v = filemtime($path);
            $assets['css'][] = $baseUrl . '/style.css?v=' . $v;
        }
    }

    // JS vindo do JSON
    if (!empty($config['js']) && is_array($config['js'])) {
        foreach ($config['js'] as $jsFile) {
            $path = $pageDir . '/' . $jsFile;

            if (file_exists($path)) {
                $v = filemtime($path);
                $assets['js'][] = $baseUrl . '/' . $jsFile . '?v=' . $v;
            }
        }
    } else {
        // JS padrão apenas se NÃO existir js no JSON
        $path = $pageDir . '/script.js';

        if (file_exists($path)) {
            $v = filemtime($path);
            $assets['js'][] = $baseUrl . '/script.js?v=' . $v;
        }
    }

    return $assets;
}

    /**
     * Executa o index.php da página em escopo isolado e retorna o HTML.
     */
    private function capturePageContent(string $file, array $vars): string {
        extract($vars, EXTR_SKIP);
        ob_start();
        include $file;
        return ob_get_clean();
    }

    /**
     * Todas as páginas ativas — arquivo E banco — para menu, rodapé e sitemap.
     *
     * Uma fonte só para quem consome. O rodapé e o menu não têm por que saber
     * que existem dois lugares onde uma página pode morar; quando souberem,
     * cada um vai reimplementar a união do seu jeito.
     *
     * Arquivo vence em caso de slug repetido, igual à resolução da URL.
     */
    public static function getAllPages(): array {
        $pagesDir = ROOT_PATH . '/pages';
        $pages    = [];

        // Sem a pasta /pages a função seguia devolvendo [] — e levava junto as
        // páginas do banco, que não dependem dela.
        foreach (glob($pagesDir . '/*/page.json') ?: [] as $jsonFile) {
            $config = json_decode(file_get_contents($jsonFile), true);
            if (!is_array($config) || !($config['ativa'] ?? true)) continue;

            $slug    = basename(dirname($jsonFile));
            $pages[] = array_merge($config, ['slug' => $slug]);
        }

        // Banco: só o que não colide com arquivo, e traduzido para o mesmo
        // formato do page.json — quem consome não distingue a origem.
        $slugsEmArquivo = array_column($pages, 'slug');

        try {
            foreach ((new Pagina())->publicadas() as $p) {
                if (in_array($p['slug'], $slugsEmArquivo, true)) continue;

                $pages[] = [
                    'slug'          => $p['slug'],
                    'titulo'        => $p['titulo'],
                    'descricao'     => $p['meta_description'] ?? '',
                    'menu_label'    => $p['menu_label'] ?: $p['titulo'],
                    'menu_ordem'    => $p['ordem_menu'] !== null ? (int) $p['ordem_menu'] : 99,
                    'no_menu'       => (bool) $p['no_menu'],
                    'no_rodape'     => (bool) $p['no_rodape'],
                    'noindex'       => (bool) $p['noindex'],
                    'ativa'         => true,
                    'origem'        => 'banco',
                    'atualizado_em' => $p['atualizado_em'] ?? null,
                ];
            }
        } catch (Throwable $e) {
            // Banco fora do ar não pode derrubar o menu inteiro: as páginas de
            // arquivo continuam listadas e a loja segue navegável.
            if (class_exists('LogService')) {
                LogService::exception($e, 'warning', 'app', ['onde' => 'PageController::getAllPages']);
            }
        }

        // Ordena pelo campo menu_ordem
        usort($pages, fn($a, $b) => ($a['menu_ordem'] ?? 99) <=> ($b['menu_ordem'] ?? 99));

        return $pages;
    }

}