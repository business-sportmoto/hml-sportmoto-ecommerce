<?php
// app/controllers/CategoryController.php

class CategoryController extends Controller {

    private Category $categoryModel;
    private Product  $productModel;

    public function __construct() {
        $this->categoryModel = new Category();
        $this->productModel  = new Product();
    }

    
// app/controllers/CategoryController.php
// Método show() — substitua o existente

    public function show(string $slug): void {
        $category = $this->categoryModel->findBySlug($slug);

        if (!$category) {
            http_response_code(404);
            $this->render('errors/404', [], 'minimal');
            return;
        }

        // Histórico de navegação
        (new History())->record('categoria', (int)$category['id']);

        // ── CONVERSÃO: ViewCategory (Fase 1) ──────────────
        try {
            $cid = (int)(Session::get('cliente_id') ?? 0) ?: null;
            (new ConversionService())->viewCategory([
                'id'   => (int)$category['id'],
                'nome' => $category['nome'] ?? '',
            ], $cid);
        } catch (\Throwable $e) {
            LogService::error('[Category] ViewCategory tracking: ' . $e->getMessage(), [$e]);
        }

        // ── Filtros padrão ────────────────────────────────────
        $filters               = $this->parseFilters();
        $filters['categoria_id'] = (int)$category['id'];

        // ── Filtros de compatibilidade por moto ───────────────
        $montadoraId = (int)($_GET['montadora_id'] ?? 0);
        $modeloId    = (int)($_GET['modelo_id']    ?? 0);
        $ano         = (int)($_GET['ano']           ?? 0);

        if ($montadoraId > 0) {
            $filters['montadora_id'] = $montadoraId;
            if ($modeloId > 0) $filters['modelo_id'] = $modeloId;
            if ($ano      > 0) $filters['ano']        = $ano;
        }

        // ── Paginação e dados ─────────────────────────────────
        $page       = max(1, (int)($_GET['pagina'] ?? 1));
        $total      = $this->productModel->countCatalog($filters);
        $pag        = new PaginationHelper($total, $page, BASE_URL . '/categoria/' . $slug);
        $products   = $this->productModel->getCatalog($filters, $pag->getPerPage(), $pag->offset());
        $priceRange = $this->productModel->getPriceRange($filters);
        $brands     = $this->productModel->getBrandsForFilter($filters);
        $subcats    = $this->categoryModel->getActive((int)$category['id']);

        // ── Busca por moto: verifica se categoria tem ativa ───
        $db         = Database::getInstance()->getConnection();
        $temBuscaMoto = false;
        $montadorasCat= [];

        // Verifica na categoria e nos ancestrais
        $atual = (int)$category['id'];
        while ($atual) {
            $stmt = $db->prepare(
                "SELECT busca_moto, parent_id FROM categorias WHERE id = ? LIMIT 1"
            );
            $stmt->execute([$atual]);
            $row = $stmt->fetch();
            if (!$row) break;
            if ((int)$row['busca_moto'] === 1) { $temBuscaMoto = true; break; }
            $atual = (int)($row['parent_id'] ?? 0);
        }

        // Se tem busca por moto, carrega montadoras com produtos nesta categoria
        $montadoras = [];
        if ($temBuscaMoto) {
            $stmt = $db->prepare(
                "SELECT mm.id, mm.nome, mm.slug
                FROM moto_montadoras mm
                WHERE mm.ativo = 1
                AND EXISTS (
                    SELECT 1 FROM produto_compatibilidade pc
                    JOIN produtos p ON p.id = pc.produto_id
                    JOIN produto_categorias pccat ON pccat.produto_id = p.id
                    WHERE pc.montadora_id = mm.id
                        AND p.ativo = 1
                        AND p.deleted_at IS NULL
                        AND pccat.categoria_id = ?
                )
                ORDER BY mm.nome ASC"
            );
            $stmt->execute([$category['id']]);
            $montadorasCat = $stmt->fetchAll();
            // $montadoras = $stmt->fetchAll();
        }

        if ($temBuscaMoto) {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare(
                "SELECT mm.id, mm.nome, mm.slug, mm.logo,
                        COUNT(DISTINCT pc.produto_id) AS total_produtos
                FROM moto_montadoras mm
                JOIN produto_compatibilidade pc ON pc.montadora_id = mm.id
                JOIN produtos p ON p.id = pc.produto_id
                JOIN produto_categorias pccat ON pccat.produto_id = p.id
                WHERE mm.ativo = 1
                AND p.ativo = 1
                AND p.deleted_at IS NULL
                AND pccat.categoria_id = ?
                GROUP BY mm.id
                HAVING total_produtos > 0
                ORDER BY mm.nome ASC"
            );
            $stmt->execute([$category['id']]);
            $montadoras = $stmt->fetchAll();
        }

        // ── Info da moto selecionada (para exibir no filtro ativo) ──
        $motoSelecionada = null;
        if ($montadoraId > 0) {
            $stmt = $db->prepare(
                "SELECT mm.nome AS montadora_nome, mm.slug AS montadora_slug,
                        mo.nome AS modelo_nome,    mo.slug AS modelo_slug
                FROM moto_montadoras mm
                LEFT JOIN moto_modelos mo ON mo.id = ?
                WHERE mm.id = ? LIMIT 1"
            );
            $stmt->execute([$modeloId ?: null, $montadoraId]);
            $motoSelecionada = $stmt->fetch() ?: null;
        }

        $produtosCompativeis = [];
        $veiculoAtivo        = $_SESSION['meu_veiculo'] ?? null;
        $mostrarVeiculoBar   = false;

        if ($veiculoAtivo && !empty($products)) {
            $svc = new VeiculoService();
            $ids = array_column($products, 'id');
            $produtosCompativeis = $svc->getProdutosCompativeisLote($ids);
        }

        // ── Barra de veículo: só exibe se a moto tiver produtos nesta categoria ──
        // Evita mostrar "seus produtos compatíveis" quando não há nenhum.
        if ($veiculoAtivo && !empty($veiculoAtivo['modelo_id'])) {
            $stmtVeiculo = $db->prepare(
                "SELECT 1 FROM produto_compatibilidade pc
                 JOIN produtos p           ON p.id = pc.produto_id
                 JOIN produto_categorias pccat ON pccat.produto_id = p.id
                 WHERE pc.modelo_id = ?
                   AND p.ativo = 1 AND p.deleted_at IS NULL
                   AND pccat.categoria_id = ?
                 LIMIT 1"
            );
            $stmtVeiculo->execute([(int)$veiculoAtivo['modelo_id'], (int)$category['id']]);
            $mostrarVeiculoBar = (bool)$stmtVeiculo->fetchColumn();
        }

        // ── Atributos disponíveis para o filtro de características ──
        $atributos = $this->productModel->getAttributesForFilter($filters);
        $caracteristicasFilter = $this->productModel->getCaracteristicasForFilter($filters);

        // ── SEO ───────────────────────────────────────────────
        $pageTitle       = $category['meta_title']       ?: $category['nome'];
        $pageDescription = $category['meta_description'] ?: ($category['descricao'] ?? '');

        $ids        = array_column($products, 'id');
        $idsComClip = (new Clip())->produtosComClip($ids);
        foreach ($products as &$p) {
            $p['_tem_clip'] = in_array((int)$p['id'], $idsComClip, true);
        }
        unset($p);

        TrackingService::registrar('categoria_vista', 'categoria', (int)$category['id']);

        // ── Render ────────────────────────────────────────────
        $this->render('products/catalog', array_merge($pag->toArray(), [
            'category'        => $category,
            'subcats'         => $subcats,
            'filters'         => $filters,
            'products'        => $products,
            'priceRange'      => $priceRange,
            'brands'          => $brands,
            'title'           => $category['nome'],
            'pageTitle'       => $pageTitle,
            'pageDescription' => $pageDescription,
            'breadcrumb'      => [
                ['label' => 'Início',           'url' => BASE_URL],
                ['label' => $category['nome'],  'url' => null],
            ],
            // Dados de busca por moto
            'temBuscaMoto'    => $temBuscaMoto,
            'montadorasCat'   => $montadorasCat,            
            'motoFiltro'      => [
                'montadora_id' => $montadoraId,
                'modelo_id'    => $modeloId,
                'ano'          => $ano,
            ],
            'motoSelecionada' => $motoSelecionada,
            'montadoras'      => $montadoras,
            'produtosCompativeis' => $produtosCompativeis,
            'idsComClip'          => $idsComClip,
            'mostrarVeiculoBar'   => $mostrarVeiculoBar,
            'veiculoAtivo'        => $veiculoAtivo,
            'atributosFilter'     => $atributos,
            'caracteristicasFilter' => $caracteristicasFilter,
        ]));
    }

    public function brand(string $slug): void {
        $db   = Database::getInstance()->getConnection();

        $stmt = $db->prepare("SELECT * FROM marcas WHERE slug = ? AND ativo = 1 LIMIT 1");
        $stmt->execute([$slug]);
        $marca = $stmt->fetch();

        if (!$marca) {
            http_response_code(404);
            $this->render('errors/404', [], 'minimal');
            return;
        }

        // Histórico
        (new History())->record('marca', (int)$marca['id']);

        // Filtros — injeta marca_id automaticamente
        $filters             = $this->parseFilters();
        $filters['marca_id'] = (int)$marca['id'];

        $page       = max(1, (int)($_GET['pagina'] ?? 1));
        $total      = $this->productModel->countCatalog($filters);
        $pag        = new PaginationHelper($total, $page, BASE_URL . '/marca/' . $slug);
        $products   = $this->productModel->getCatalog($filters, $pag->getPerPage(), $pag->offset());
        $priceRange = $this->productModel->getPriceRange($filters);
        $brands     = []; // não faz sentido filtrar marcas dentro de uma marca
        $subcats    = $this->categoryModel->getActive(); // todas as cats, ou filtradas pela marca abaixo

        // Categorias disponíveis nesta marca (para o filtro lateral)
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
        SeoHelper::setBreadcrumb([
            ['label' => 'Início', 'url' => BASE_URL],
            ['label' => 'Marcas', 'url' => BASE_URL . '/marcas'],
            ['label' => $marca['nome'], 'url' => null],
        ]);

        

        // ── Reutiliza exatamente a mesma view de categoria ──
        $this->render('products/catalog', array_merge($pag->toArray(), [
            'category'   => null,       // não é categoria
            'marca'      => $marca,     // contexto extra para o hero
            'subcats'    => $subcats,
            'filters'    => $filters,
            'products'   => $products,
            'priceRange' => $priceRange,
            'brands'     => $brands,
            'title'      => $marca['nome'],
            'breadcrumb' => [
                ['label' => 'Início', 'url' => BASE_URL],
                ['label' => 'Marcas', 'url' => BASE_URL . '/marcas'],
                ['label' => $marca['nome'], 'url' => null],
            ],
        ]));
    }

    public function search(): void {
        (new ProductController())->catalog();
    }

    /**
     * Valida 'ordem' contra whitelist — mesmos valores aceitos por
     * Product::buildOrder(). Qualquer valor fora da lista cai em
     * 'relevancia' (comportamento padrão seguro).
     */
    private function sanitizeOrdem(string $valor): string {
        static $permitidos = [
            'relevancia', 'novidades', 'menor_preco', 'maior_preco',
            'maior_desconto', 'mais_vendidos', 'mais_vistos', 'destaque',
        ];
        return in_array($valor, $permitidos, true) ? $valor : 'relevancia';
    }

    private function parseFilters(): array {
        return [
            'q'           => SecurityHelper::sanitizeString($_GET['q']           ?? ''),
            'marca_id'    => SecurityHelper::sanitizeInt(  $_GET['marca_id']     ?? 0),
            'marcas'      => isset($_GET['marcas']) && is_array($_GET['marcas'])
                             ? array_map('intval', $_GET['marcas']) : [],
            'preco_min'  => ($_GET['preco_min'] ?? '') !== '' ? (float)$_GET['preco_min'] : '',
            'preco_max'  => ($_GET['preco_max'] ?? '') !== '' ? (float)$_GET['preco_max'] : '',
            'em_promocao' => !empty($_GET['em_promocao']),
            'com_estoque' => !empty($_GET['com_estoque']),
            // sanitizeSlug() removia o "_" (menor_preco virava menorpreco).
            // 'ordem' é um valor fixo de whitelist, não um slug de URL —
            // validar contra a lista de valores aceitos pelo Product::buildOrder().
            'ordem'       => $this->sanitizeOrdem($_GET['ordem'] ?? 'relevancia'),

            'montadora_id' => (int)($_GET['montadora_id'] ?? 0),
            'modelo_id'    => (int)($_GET['modelo_id']    ?? 0),
            'ano'          => (int)($_GET['ano']           ?? 0),

            // Atributos: /busca?atributos[Tamanho][]=42&atributos[Cor][]=Preto
            'atributos'    => isset($_GET['atributos']) && is_array($_GET['atributos'])
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
    }
}