<?php
// app/controllers/SearchController.php

class SearchController extends Controller {

    private Product $productModel;

    public function __construct() {
        $this->productModel = new Product();
    }

    /**
     * Valida 'ordem' contra whitelist — mesmos valores aceitos por
     * Product::buildOrder(). Mesmo padrão usado em CategoryController
     * e MotoController, para evitar o bug de SecurityHelper::sanitizeSlug()
     * removendo "_" (menor_preco virava menorpreco).
     */
    private function sanitizeOrdem(string $valor): string {
        static $permitidos = [
            'relevancia', 'novidades', 'menor_preco', 'maior_preco',
            'maior_desconto', 'mais_vendidos', 'mais_vistos', 'destaque',
        ];
        return in_array($valor, $permitidos, true) ? $valor : 'relevancia';
    }

    public function results(): void {
        $q = SecurityHelper::sanitizeSearch($_GET['q'] ?? '');

        // Se não houver termo, mostra página de busca vazia em vez de redirecionar
        if (mb_strlen($q) < 2) {
            SeoHelper::setTitle('Busca');
            SeoHelper::setRobots('noindex, follow');

            $this->render('products/catalog', [
                'products'     => [],
                'filters'      => [
                    'q' => $q, 'ordem' => 'relevancia', 'marcas' => [],
                    'preco_min' => '', 'preco_max' => '',
                    'em_promocao' => false, 'com_estoque' => false,
                    'atributos' => [], 'caracteristicas' => [],
                ],
                'priceRange'   => ['min_price' => 0, 'max_price' => 0],
                'brands'       => [],
                'categories'   => [],
                'subcats'      => [],
                'atributosFilter'       => [],
                'caracteristicasFilter' => [],
                'title'        => 'Busca',
                'suggestions'  => [],
                'total'        => 0,
                'per_page'     => ITEMS_PER_PAGE,
                'current_page' => 1,
                'total_pages'  => 0,
                'offset'       => 0,
                'has_pages'    => false,
                'prev'         => null,
                'next'         => null,
                'pages'        => [],
                'pagination'   => null,
                'breadcrumb'   => [
                    ['label' => 'Início', 'url' => BASE_URL],
                    ['label' => 'Busca',  'url' => null],
                ],
                'produtosCompativeis' => [],
                'mostrarVeiculoBar'   => false,
            ]);
            return;
        }

        // Registra busca no histórico
        (new History())->record('busca', null, ['termo_busca' => $q]);

        $page  = max(1, (int)($_GET['pagina'] ?? 1));
        $ordem = $this->sanitizeOrdem($_GET['ordem'] ?? 'relevancia');

        $filters = [
            'q'           => $q,
            'ordem'       => $ordem,
            'marcas'      => isset($_GET['marcas']) && is_array($_GET['marcas'])
                            ? array_map('intval', $_GET['marcas']) : [],
            'preco_min'   => SecurityHelper::sanitizeFloat($_GET['preco_min'] ?? ''),
            'preco_max'   => SecurityHelper::sanitizeFloat($_GET['preco_max'] ?? ''),
            'em_promocao' => !empty($_GET['em_promocao']),
            'com_estoque' => !empty($_GET['com_estoque']),

            // Atributos: /busca?atributos[Tamanho][]=42&atributos[Cor][]=Preto
            'atributos'   => isset($_GET['atributos']) && is_array($_GET['atributos'])
                            ? array_map(
                                fn($vals) => array_filter((array)$vals, fn($v) => $v !== ''),
                                $_GET['atributos']
                              )
                            : [],

            // Características: /busca?caracteristicas[Idade][]=Adultos
            'caracteristicas' => isset($_GET['caracteristicas']) && is_array($_GET['caracteristicas'])
                            ? array_map(
                                fn($vals) => array_filter((array)$vals, fn($v) => $v !== ''),
                                $_GET['caracteristicas']
                              )
                            : [],
        ];

        // search()/countSearch() agora são wrappers sobre getCatalog()/
        // countCatalog() — herdam favoritado, preco_min/max, marca_slug,
        // prioridade de estoque e ordenação por relevância automaticamente.
        $total    = $this->productModel->countSearch($q, $filters);
        $pag      = new PaginationHelper($total, $page, BASE_URL . '/busca');
        $products = $this->productModel->search($q, $pag->getPerPage(), $pag->offset(), $filters);

        $suggestions = [];
        if (empty($products)) {
            $suggestions = $this->getSuggestions($q);
        }

        // Filtros disponíveis — mesmo padrão do CategoryController
        $atributosFilter       = $this->productModel->getAttributesForFilter($filters);
        $caracteristicasFilter = $this->productModel->getCaracteristicasForFilter($filters);

        $ids        = array_column($products, 'id');
        $idsComClip = (new Clip())->produtosComClip($ids);
        foreach ($products as &$p) {
            $p['_tem_clip'] = in_array((int)$p['id'], $idsComClip, true);
        }
        unset($p);

        SeoHelper::setTitle("Busca: {$q}");
        SeoHelper::setDescription("Resultados para \"{$q}\" na " . ConfigHelper::get('site_nome', ''));
        SeoHelper::setRobots('noindex, follow');

        TrackingService::registrar('busca', null, null, [
            'q'          => mb_substr($q, 0, 120),
            'resultados' => (int)$total,
        ]);

        // ── CONVERSÃO: Search (Fase 1) ────────────────────
        // No controller (server-side) = mais preciso que via JS.
        try {
            (new ConversionService())->search(mb_substr($q, 0, 120));
        } catch (\Throwable $e) {
            LogService::error('[Search] Conversion tracking: ', [$e]);
        }

        $this->render('products/catalog', array_merge($pag->toArray(), [
            'products'    => $products,
            'filters'     => $filters,
            'priceRange'  => !empty($products)
                            ? $this->productModel->getPriceRange(['q' => $q])
                            : ['min_price' => 0, 'max_price' => 0],
            'brands'      => !empty($products)
                            ? $this->productModel->getBrandsForFilter(['q' => $q])
                            : [],
            'categories'  => [],
            'subcats'     => [],
            'atributosFilter'       => $atributosFilter,
            'caracteristicasFilter' => $caracteristicasFilter,
            'title'       => "Busca: \"{$q}\"",
            'suggestions' => $suggestions,
            'breadcrumb'  => [
                ['label' => 'Início', 'url' => BASE_URL],
                ['label' => "Busca: {$q}", 'url' => null],
            ],
            // Busca não tem contexto de moto específica — vehicle bar
            // não se aplica aqui da mesma forma que em categoria/moto.
            'produtosCompativeis' => [],
            'mostrarVeiculoBar'   => false,
            'idsComClip'          => $idsComClip,
        ]));
    }

    public function autocomplete(): void {
        $q     = SecurityHelper::sanitizeSearch($_GET['q'] ?? '');
        $catId = SecurityHelper::sanitizeInt($_GET['categoria_id'] ?? 0);

        if (mb_strlen($q) < 2) {
            $this->json(['ok' => true, 'items' => [], 'categorias' => []]);
        }

        // Se categoria selecionada, filtra por ela
        $filters = $catId ? ['categoria_id' => $catId] : [];
        $items   = $this->productModel->autocomplete($q, 6, $filters);

        $result = array_map(function ($item) {
            return [
                'id'              => $item['id'],
                'nome'            => $item['nome'],
                'slug'            => $item['slug'],
                'preco_fmt'       => PriceHelper::format((float)$item['preco']),
                'preco_promo'     => $item['preco_promo'],
                'preco_promo_fmt' => !empty($item['preco_promo'])
                                    ? PriceHelper::format((float)$item['preco_promo'])
                                    : null,
                'categoria'       => $item['categoria'] ?? '',
                'imagem'          => !empty($item['imagem'])
                                    ? $item['imagem']
                                    : BASE_URL . '/assets/images/placeholder.jpg',
            ];
        }, $items);

        // Categorias só aparecem quando buscando em "Todas"
        $categorias = [];
        if (!$catId) {
            $db   = Database::getInstance()->getConnection();
            $stmt = $db->prepare(
                "SELECT id, nome, slug FROM categorias
                WHERE nome LIKE ? AND ativo = 1 ORDER BY nome LIMIT 3"
            );
            $stmt->execute(['%' . $q . '%']);
            $categorias = $stmt->fetchAll();
        }

        $this->json(['ok' => true, 'items' => $result, 'categorias' => $categorias]);
    }

    private function getSuggestions(string $q): array {
        $db   = Database::getInstance()->getConnection();
        $like = '%' . substr($q, 0, 5) . '%';
        $stmt = $db->prepare(
            "SELECT DISTINCT nome, slug
             FROM produtos
             WHERE ativo = 1 AND deleted_at IS NULL
               AND (nome LIKE ? OR SOUNDEX(nome) = SOUNDEX(?))
             LIMIT 5"
        );
        $stmt->execute([$like, $q]);
        return $stmt->fetchAll();
    }
}