<?php

class BrandController extends Controller {

    private Product $productModel;

    public function __construct() {
        $this->productModel = new Product();
    }

    /**
     * Valida 'ordem' contra whitelist — mesmo padrão usado em
     * CategoryController/MotoController/SearchController.
     */
    private function sanitizeOrdem(string $valor): string {
        static $permitidos = [
            'relevancia', 'novidades', 'menor_preco', 'maior_preco',
            'maior_desconto', 'mais_vendidos', 'mais_vistos', 'destaque',
        ];
        return in_array($valor, $permitidos, true) ? $valor : 'relevancia';
    }

    // ── Página principal /marcas ──────────────────────────
    public function index(): void {
        $db = Database::getInstance()->getConnection();

        // total_produtos agora conta só produtos COM estoque disponível
        // (saldo - reservado > 0), mesma definição usada na prioridade
        // de estoque do catálogo — decisão confirmada: contagem deve
        // refletir disponibilidade real, não apenas "produto ativo".
        $stmt = $db->query(
            "SELECT m.id, m.nome, m.slug, m.logo, m.bg_cor,
                    COUNT(DISTINCT p.id) AS total_produtos
             FROM marcas m
             LEFT JOIN produtos p
                    ON p.marca_id = m.id
                   AND p.ativo = 1 AND p.deleted_at IS NULL
                   AND EXISTS (
                       SELECT 1 FROM estoque_saldo es
                       WHERE es.produto_id = p.id
                         AND (es.saldo - es.reservado) > 0
                   )
             WHERE m.ativo = 1
             GROUP BY m.id
             ORDER BY m.destaque DESC, m.nome ASC"
        );
        $marcas = $stmt->fetchAll();

        // Apenas marcas COM produtos disponíveis (para os sliders)
        $marcasComProduto = array_values(
            array_filter($marcas, fn($m) => (int)$m['total_produtos'] > 0)
        );

        // Primeiras 4 para SSR
        $marcasInicial = array_slice($marcasComProduto, 0, 4);

        $this->render('brands/index', [
            'marcas'          => $marcas,           // todas para o grid
            'marcasInicial'   => $marcasInicial,    // primeiras 4 com produto para sliders
            'totalMarcas'     => count($marcas),    // total para o grid
            'totalComProduto' => count($marcasComProduto), // total para os sliders
        ]);
    }

    // ── Ajax: produtos de uma marca (slider) ──────────────
    public function produtos(): void {
        $marcaId = SecurityHelper::sanitizeInt($_GET['marca_id'] ?? 0);
        $offset  = SecurityHelper::sanitizeInt($_GET['offset']   ?? 0);
        $limit   = 7; // primeiros 7, depois mais via scroll

        if (!$marcaId) $this->json(['ok' => false]);

        // Reaproveita o mesmo getCatalog() do catálogo completo — herda
        // favoritado, preco_min/max, prioridade de estoque e ordenação
        // padrão, em vez de manter uma query manual isolada.
        $filters  = ['marca_id' => $marcaId];
        $produtos = $this->productModel->getCatalog($filters, $limit, $offset);
        $total    = $this->productModel->countCatalog($filters);

        $items = array_map(fn($p) => [
            'id'        => (int)$p['id'],
            'nome'      => $p['nome'],
            'slug'      => $p['slug'],
            'favoritado'=> (bool)($p['favoritado'] ?? false),
            'preco_fmt' => PriceHelper::format(
                (float)($p['preco_min'] ?: ($p['preco_promo'] ?: $p['preco']))
            ),
            'preco_original_fmt' => !empty($p['preco_promo']) || !empty($p['preco_max'])
                ? PriceHelper::format((float)($p['preco_max'] ?: $p['preco']))
                : null,
            'tem_promo' => !empty($p['preco_promo']),
            'imagem'    => !empty($p['imagem_principal'])
                ? UPLOAD_URL . '/products/' . $p['imagem_principal']
                : BASE_URL . '/assets/images/placeholder.jpg',
        ], $produtos);

        $this->json([
            'ok'       => true,
            'items'    => $items,
            'total'    => $total,
            'offset'   => $offset,
            'has_more' => ($offset + $limit) < min($total, 20),
            'has_page' => $total > 20,
        ]);
    }

    // ── Ajax: próximo lote de marcas (load more) ──────────
    public function loadMore(): void {
        $offset = SecurityHelper::sanitizeInt($_GET['offset'] ?? 0);
        $limit  = 4;

        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare(
            "SELECT m.id, m.nome, m.slug, m.logo, m.bg_cor,
                    COUNT(DISTINCT p.id) AS total_produtos
             FROM marcas m
             JOIN produtos p
                    ON p.marca_id = m.id
                   AND p.ativo = 1 AND p.deleted_at IS NULL
                   AND EXISTS (
                       SELECT 1 FROM estoque_saldo es
                       WHERE es.produto_id = p.id
                         AND (es.saldo - es.reservado) > 0
                   )
             WHERE m.ativo = 1
             GROUP BY m.id
             HAVING total_produtos > 0
             ORDER BY m.destaque DESC, m.nome ASC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute([$limit, $offset]);
        $marcas = $stmt->fetchAll();

        $this->json([
            'ok'      => true,
            'marcas'  => array_map(fn($m) => [
                'id'     => (int)$m['id'],
                'nome'   => $m['nome'],
                'slug'   => $m['slug'],
                'bg_cor' => $m['bg_cor'] ?? '#f8fafc',
                'logo'   => $m['logo']
                            ? UPLOAD_URL . '/brands/' . $m['logo']
                            : null,
            ], $marcas),
            'has_more' => count($marcas) === $limit,
        ]);
    }

    /**
     * /marca/{slug} — agora é uma página de catálogo completa, no
     * mesmo padrão de CategoryController::show(): paginação, ordenar,
     * filtros de preço/atributos/características, favoritos, etc.
     */
    public function detail(string $slug): void {
        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare(
            "SELECT * FROM marcas WHERE slug = ? AND ativo = 1 LIMIT 1"
        );
        $stmt->execute([$slug]);
        $marca = $stmt->fetch();

        if (!$marca) {
            http_response_code(404);
            $this->render('errors/404', [], 'minimal');
            return;
        }

        $filters             = $this->parseFilters();
        $filters['marca_id'] = (int)$marca['id'];

        $page       = max(1, (int)($_GET['pagina'] ?? 1));
        $total      = $this->productModel->countCatalog($filters);
        $pag        = new PaginationHelper($total, $page, BASE_URL . '/marca/' . $slug);
        $produtos   = $this->productModel->getCatalog($filters, $pag->getPerPage(), $pag->offset());
        $priceRange = $this->productModel->getPriceRange($filters);

        // Não filtra marca dentro da própria página de marca
        $brands = [];

        // Categorias disponíveis nesta marca (sidebar)
        $stmtCats = $db->prepare(
            "SELECT c.id, c.nome, c.slug, COUNT(p.id) AS total
             FROM categorias c
             JOIN produtos p ON p.categoria_id = c.id
                AND p.marca_id   = ?
                AND p.ativo      = 1
                AND p.deleted_at IS NULL
             GROUP BY c.id
             ORDER BY c.nome ASC"
        );
        $stmtCats->execute([$marca['id']]);
        $subcats = $stmtCats->fetchAll();

        // Filtros de atributos/características — mesmo padrão do catálogo
        $atributosFilter       = $this->productModel->getAttributesForFilter($filters);
        $caracteristicasFilter = $this->productModel->getCaracteristicasForFilter($filters);

        $ids        = array_column($produtos, 'id');
        $idsComClip = (new Clip())->produtosComClip($ids);
        foreach ($produtos as &$p) {
            $p['_tem_clip'] = in_array((int)$p['id'], $idsComClip, true);
        }
        unset($p);

        // SEO
        $metaTitle = $marca['meta_title'] ?: $marca['nome'] . ' — Produtos e peças';
        $metaDesc  = $marca['meta_description']
                ?: 'Confira todos os produtos ' . $marca['nome'] . '. ' . $total . ' produtos disponíveis.';

        SeoHelper::setTitle($metaTitle);
        SeoHelper::setDescription($metaDesc);
        SeoHelper::setCanonical(BASE_URL . '/marca/' . $marca['slug']);
        SeoHelper::setRobots('index, follow');
        SeoHelper::setJsonLd([
            '@context'    => 'https://schema.org',
            '@type'       => 'Brand',
            'name'        => $marca['nome'],
            'url'         => BASE_URL . '/marca/' . $marca['slug'],
            'logo'        => !empty($marca['logo']) ? UPLOAD_URL . '/brands/' . $marca['logo'] : null,
            'description' => $marca['descricao'] ?? null,
            'sameAs'      => $marca['site'] ?? null,
        ]);

        // Reutiliza a mesma view de categoria — já suporta marca via $marca
        $this->render('products/catalog', array_merge($pag->toArray(), [
            'category'   => null,       // não é categoria
            'marca'      => $marca,     // contexto extra para o hero
            'subcats'    => $subcats,
            'filters'    => $filters,
            'products'   => $produtos,
            'priceRange' => $priceRange,
            'brands'     => $brands,
            'atributosFilter'       => $atributosFilter,
            'caracteristicasFilter' => $caracteristicasFilter,
            'title'      => $marca['nome'],
            'breadcrumb' => [
                ['label' => 'Início', 'url' => BASE_URL],
                ['label' => 'Marcas', 'url' => BASE_URL . '/marcas'],
                ['label' => $marca['nome'], 'url' => null],
            ],
            // Marca não tem contexto de moto específica — vehicle bar
            // não se aplica aqui da mesma forma que em categoria/moto.
            'produtosCompativeis' => [],
            'mostrarVeiculoBar'   => false,
            'idsComClip'          => $idsComClip,
        ]));
    }

    private function parseFilters(): array {
        return [
            'q'           => SecurityHelper::sanitizeString($_GET['q'] ?? ''),
            'preco_min'   => ($_GET['preco_min'] ?? '') !== '' ? (float)$_GET['preco_min'] : '',
            'preco_max'   => ($_GET['preco_max'] ?? '') !== '' ? (float)$_GET['preco_max'] : '',
            'em_promocao' => !empty($_GET['em_promocao']),
            'com_estoque' => !empty($_GET['com_estoque']),
            'ordem'       => $this->sanitizeOrdem($_GET['ordem'] ?? 'relevancia'),

            'atributos'   => isset($_GET['atributos']) && is_array($_GET['atributos'])
                            ? array_map(
                                fn($vals) => array_filter((array)$vals, fn($v) => $v !== ''),
                                $_GET['atributos']
                              )
                            : [],

            'caracteristicas' => isset($_GET['caracteristicas']) && is_array($_GET['caracteristicas'])
                            ? array_map(
                                fn($vals) => array_filter((array)$vals, fn($v) => $v !== ''),
                                $_GET['caracteristicas']
                              )
                            : [],
        ];
    }
}