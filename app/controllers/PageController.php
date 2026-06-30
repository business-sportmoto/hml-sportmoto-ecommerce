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

        if (!is_dir($pageDir) || !file_exists($pageDir . '/index.php')) {
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
     * Retorna todas as páginas ativas para exibir no menu ou sitemap.
     */
    public static function getAllPages(): array {
        $pagesDir = ROOT_PATH . '/pages';
        if (!is_dir($pagesDir)) return [];

        $pages = [];
        foreach (glob($pagesDir . '/*/page.json') as $jsonFile) {
            $config = json_decode(file_get_contents($jsonFile), true);
            if (!is_array($config) || !($config['ativa'] ?? true)) continue;

            $slug    = basename(dirname($jsonFile));
            $pages[] = array_merge($config, ['slug' => $slug]);
        }

        // Ordena pelo campo menu_ordem
        usort($pages, fn($a, $b) => ($a['menu_ordem'] ?? 99) <=> ($b['menu_ordem'] ?? 99));

        return $pages;
    }

    // Adicionar ao CartController existente

    public function mini(): void {
        $cartModel = new Cart();
        $clienteId = Session::getClienteId();
        $carrinhoId = $this->getCarrinhoId();

        if (!$carrinhoId) {
            $this->json(['ok' => true, 'items' => [], 'count' => 0,
                        'subtotal_fmt' => 'R$ 0,00', 'total_fmt' => 'R$ 0,00',
                        'desconto' => 0, 'frete' => 0]);
        }

        $items  = $cartModel->getItems($carrinhoId);
        $totals = $cartModel->getTotals($carrinhoId);
        $cart   = $cartModel->find($carrinhoId);

        // Monta itens formatados
        $itemsFormatted = array_map(function ($item) {
            return [
                'id'          => $item['id'],
                'produto_id'  => $item['produto_id'],
                'nome'        => $item['nome_produto'],
                'slug'        => $item['produto_slug'] ?? '',
                'imagem'      => $item['imagem']        ?? null,
                'quantidade'  => (int)$item['quantidade'],
                'estoque'     => (int)($item['estoque_total'] ?? 99),
                'preco_fmt'   => PriceHelper::format((float)$item['preco_unitario']),
                'subtotal_fmt'=> PriceHelper::format((float)$item['subtotal']),
                'opcoes'      => !empty($item['opcoes_snapshot'])
                                ? implode(', ', array_map(
                                    fn($k, $v) => "{$k}: {$v}",
                                    array_keys(json_decode($item['opcoes_snapshot'], true)),
                                    json_decode($item['opcoes_snapshot'], true)
                                ))
                                : null,
            ];
        }, $items);

        // Melhor parcela
        $melhorParcela = null;
        if ($totals['total'] > 0) {
            $parcelas      = PriceHelper::installments($totals['total']);
            $ultima        = end($parcelas);
            if ($ultima && $ultima['parcelas'] > 1) {
                $melhorParcela = $ultima['label'];
            }
        }

        $this->json([
            'ok'              => true,
            'items'           => $itemsFormatted,
            'count'           => count($items),
            'subtotal_fmt'    => PriceHelper::format((float)$totals['subtotal']),
            'desconto'        => (float)$totals['desconto'],
            'desconto_fmt'    => PriceHelper::format((float)$totals['desconto']),
            'frete'           => (float)$totals['frete'],
            'frete_fmt'       => PriceHelper::format((float)$totals['frete']),
            'total_fmt'       => PriceHelper::format((float)$totals['total']),
            'total'           => (float)$totals['total'],
            'cupom_codigo'    => $cart['cupom_codigo']    ?? null,
            'vendedor_codigo' => $cart['vendedor_codigo'] ?? null,
            'melhor_parcela'  => $melhorParcela,
        ]);
    }
}