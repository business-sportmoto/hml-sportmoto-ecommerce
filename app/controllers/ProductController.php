<?php
// app/controllers/ProductController.php

class ProductController extends Controller {

    private Product  $productModel;
    private Category $categoryModel;

    public function __construct() {
        $this->productModel  = new Product();
        $this->categoryModel = new Category();
    }

    // ── Catálogo ──────────────────────────────────────────────

    public function catalog(): void {
        $filters = $this->parseFilters();
        $page    = max(1, (int)($_GET['pagina'] ?? 1));
        $total   = $this->productModel->countCatalog($filters);
        $pag     = new PaginationHelper($total, $page, BASE_URL . '/busca');

        $products = $this->productModel->getCatalog($filters, $pag->getPerPage(), $pag->offset());
        $priceRange  = $this->productModel->getPriceRange($filters);
        $brands      = $this->productModel->getBrandsForFilter($filters);
        $categories  = $this->categoryModel->getActive();

        // Título dinâmico da página
        $title = 'Produtos';
        if (!empty($filters['q'])) {
            $title = 'Busca: ' . SecurityHelper::sanitizeString($filters['q']);
        }

        SeoHelper::setTitle($title);
        SeoHelper::setDescription("Encontre os melhores produtos na " . ConfigHelper::get('site_nome', 'Loja') . ".");

        $this->render('products/catalog', array_merge($pag->toArray(), [
            'filters'    => $filters,
            'products'   => $products,
            'priceRange' => $priceRange,
            'brands'     => $brands,
            'categories' => $categories,
            'title'      => $title,
        ]));
    }

    // Adicionar ao ProductController

    public function cardImages(): void {
        $id = SecurityHelper::sanitizeInt($_GET['id'] ?? 0);
        if (!$id) {
            $this->json(['ok' => false, 'images' => []]);
        }

        $images = ImageHelper::getUrls($id);

        $this->json(['ok' => true, 'images' => $images]);
    }

    // ── Página de produto ─────────────────────────────────────

    public function detail(string $slug): void {
        $product = $this->productModel->findBySlug($slug);

        if (!$product) {
            http_response_code(404);
            $this->render('errors/404', [], 'minimal');
            return;
        }

        // Registra no histórico
        (new History())->record('produto', (int)$product['id']);

        // Incrementa visualizações de forma assíncrona-like (ignora erros)
        try { $this->productModel->incrementViews((int)$product['id']); } catch (Exception) {}

        $images         = $this->productModel->getImages((int)$product['id']);
        $caracteristicas = $this->productModel->getCaracteristicas((int)$product['id']);
        $stockMatrix = $this->productModel->getStockMatrix((int)$product['id']);
        $related     = $this->productModel->getRelated(
            (int)$product['id'],
            (int)($product['categoria_id'] ?? 0),
            6
        );
        // $reviewStats = $this->productModel->getReviewStats((int)$product['id']);
        // $reviews     = $this->productModel->getReviews((int)$product['id'], 5);
        $installments = PriceHelper::installments(PriceHelper::currentPrice($product));

        // Breadcrumb
        $breadcrumb = $this->buildBreadcrumb($product);

        
        // JSON-LD para rich snippets do Google
        $jsonLd = $this->buildProductJsonLd($product, [], $images);

        // $variation  = $this->productModel->getVariationsWithOptions((int)$product['id']);
        // Variante pré-selecionada via ?variant_id=
        // No método detail(), ao buscar a variante pré-selecionada:
        $variantIdLegado = SecurityHelper::sanitizeString($_GET['variant_id'] ?? '');
        $skuPreSelecionado = null;

        if ($variantIdLegado) {
            $db   = Database::getInstance()->getConnection();

            // Tenta por id_legado primeiro, depois por sku_id como fallback
            $stmt = $db->prepare(
                "SELECT s.*, JSON_OBJECTAGG(at.slug, sa.valor) AS atributos
                FROM produto_skus s
                JOIN sku_atributos sa ON sa.sku_id = s.id
                JOIN atributo_tipos at ON at.id = sa.atributo_tipo_id
                WHERE (s.id_legado = ? OR s.id = ?)
                AND s.produto_id = ?
                AND s.ativo = 1
                GROUP BY s.id
                LIMIT 1"
            );
            // Tenta como int para o fallback por sku_id
            $skuIdFallback = is_numeric($variantIdLegado) ? (int)$variantIdLegado : 0;
            $stmt->execute([$variantIdLegado, $skuIdFallback, $product['id']]);
            $row = $stmt->fetch();

            if ($row) {
                $row['atributos']   = json_decode($row['atributos'], true) ?? [];
                $row['preco_final'] = (float)($row['preco_promo'] ?: $row['preco']);
                $row['preco_fmt']   = PriceHelper::format($row['preco_final']);
                $skuPreSelecionado  = $row;
            }
        }

        // Dados de variação
        $variation = new ProductVariation();
        $vdata     = $variation->getProductData((int)$product['id']);

        // $canReview = false;
        // if (Session::isClienteLogado()) {
        //     $canReview = $this->productModel->clientePodeAvaliar(
        //         (int)Session::getClienteId(),
        //         (int)$product['id']
        //     );
        // }

        // SEO do produto
        SeoHelper::setProduct(array_merge($product, [
            'imagem_principal' => $images[0]['arquivo'] ?? null,
        ]));

        // Canonical inclui o variant_id se houver
        $canonical = BASE_URL . '/produto/' . $product['slug'];
        if ($skuPreSelecionado) {
            $canonical .= '?variant_id=' . urlencode($variantIdLegado);
        }
        SeoHelper::setCanonical($canonical);

        $clienteId  = Session::isClienteLogado() ? (int)Session::get('cliente_id') : null;
        $sessionKey = PersonalizationService::getSessionKey();
        $svc        = new PersonalizationService($clienteId, $sessionKey);
        $sections   = $svc->buildHomeSectionsPrepare();

        TrackingService::registrar('produto_visto', 'produto', (int)$product['id'], [
            'categoria_id' => (int)($product['categoria_id'] ?? 0),
            'preco'        => (float)($product['preco'] ?? 0),
        ]);

        $this->render('products/detail', [
            'product'         => $product,
            'images'          => $images,
            'caracteristicas' => $caracteristicas,
            'variation'   => $variation,
            'vdata'             => $vdata,
            'skuPreSelecionado' => $skuPreSelecionado,
            'variant_id_atual'  => $variantIdLegado,
            'stockMatrix'  => $stockMatrix,
            'stockJson'    => json_encode($stockMatrix),
            'variationsJson' => json_encode($variation),
            'related'      => $related,
            // 'reviewStats'  => $reviewStats,
            // 'reviews'      => $reviews,
            // 'canReview'    => true,//$canReview,
            'installments' => $installments,
            'breadcrumb'   => $breadcrumb,
            'jsonLd'       => $jsonLd,            
            'extra_js' => [BASE_URL . '/assets/js/product.js'], // ← adicionar

            'produtos_destaque'  => $sections['sectionDestaque'] ?? [],
            'produtos_promocao'  => $sections['sectionPromocoes'] ?? [], //$productModel->getOnSale(15),
            'mais_vendidos'      => $sections['sectionMaisVendidos'] ?? [],//$productModel->getBestSellers(15),
            'lancamentos'        => $sections['sectionNovidades'] ?? [], //$productModel->getRecent(15),

            'sectionFavoritos'           => $sections['sectionFavoritos'] ?? [],    #        
            'sectionPorFavoritos'           => $sections['sectionPorFavoritos'] ?? [], #
            'sectionPorHistorico'           => $sections['sectionPorHistorico'] ?? [], #
            'sectionPorCategorias'           => $sections['sectionPorCategorias'] ?? [],
            'sectionPorBuscas'           => $sections['sectionPorBuscas'] ?? [], #
            'sectionPorClips'           => $sections['sectionPorClips'] ?? [], #
            'sectionPorMarcas'           => $sections['sectionPorMarcas'] ?? [],
            'sectionPorCarrinho'           => $sections['sectionPorCarrinho'] ?? [], #
        ]);
    }

    public function variantData(): void {
        $variantId = SecurityHelper::sanitizeString($_GET['variant_id'] ?? '');
        $productId = SecurityHelper::sanitizeInt($_GET['product_id'] ?? 0);

        if (!$variantId || !$productId) {
            $this->json(['ok' => false, 'msg' => 'Parâmetros inválidos.']);
        }

        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare(
            "SELECT
                s.id AS sku_id, s.sku, s.ean, s.id_legado,
                s.preco, s.preco_promo, s.estoque,
                JSON_OBJECTAGG(at.slug, sa.valor) AS atributos,
                -- Imagem específica do SKU (se existir), senão a principal do produto
                COALESCE(
                    (SELECT arquivo FROM produto_imagens
                    WHERE sku_id = s.id ORDER BY ordem ASC LIMIT 1),
                    (SELECT arquivo FROM produto_imagens
                    WHERE produto_id = s.produto_id AND principal = 1 LIMIT 1)
                ) AS imagem
            FROM produto_skus s
            JOIN sku_atributos sa ON sa.sku_id = s.id
            JOIN atributo_tipos at ON at.id = sa.atributo_tipo_id
            WHERE s.id_legado = ?
            AND s.produto_id = ?
            AND s.ativo = 1
            LIMIT 1"
        );
        $stmt->execute([$variantId, $productId]);
        $sku = $stmt->fetch();

        if (!$sku) {
            $this->json(['ok' => false, 'msg' => 'Variante não encontrada.']);
        }

        $atributos  = json_decode($sku['atributos'], true) ?? [];
        $precoFinal = (float)($sku['preco_promo'] ?: $sku['preco']);

        $this->json([
            'ok'         => true,
            'sku_id'     => $sku['sku_id'],
            'sku'        => $sku['sku'],
            'id_legado'  => $sku['id_legado'],
            'preco'      => $precoFinal,
            'preco_fmt'  => PriceHelper::format($precoFinal),
            'preco_original_fmt' => $sku['preco_promo']
                                    ? PriceHelper::format((float)$sku['preco'])
                                    : null,
            'tem_promo'  => !empty($sku['preco_promo']) && $sku['preco_promo'] < $sku['preco'],
            'estoque'    => (int)$sku['estoque'],
            'sem_estoque'=> (int)$sku['estoque'] === 0,
            'atributos'  => $atributos,
            'imagem'     => ImageHelper::getPrincipal($productId, (int)$sku['sku_id']),
        ]);
    }

    // ── Registrar visualização (Ajax) ─────────────────────────

    public function registerView(): void {
        $id = SecurityHelper::sanitizeInt($_POST['product_id'] ?? 0);
        if ($id > 0) {
            try { $this->productModel->incrementViews($id); } catch (Exception) {}
        }
        $this->json(['ok' => true]);
    }

    

    // ── Salvar avaliação ──────────────────────────────────────

    public function saveReview(): void {
        AuthHelper::requireCustomer();
        $this->verifyCsrf();

        $data = [
            'produto_id'  => SecurityHelper::sanitizeInt($_POST['produto_id'] ?? 0),
            'cliente_id'  => Session::getClienteId(),
            'pedido_id'   => SecurityHelper::sanitizeInt($_POST['pedido_id'] ?? 0) ?: null,
            'nota'        => SecurityHelper::sanitizeInt($_POST['nota'] ?? 5),
            'titulo'      => SecurityHelper::sanitizeString($_POST['titulo'] ?? ''),
            'comentario'  => SecurityHelper::sanitizeString($_POST['comentario'] ?? ''),
        ];

        if ($data['produto_id'] <= 0 || $data['nota'] < 1 || $data['nota'] > 5) {
            $this->json(['ok' => false, 'msg' => 'Dados inválidos.']);
        }
        if (empty(trim($data['comentario']))) {
            $this->json(['ok' => false, 'msg' => 'Escreva um comentário.']);
        }

        try {
            $this->productModel->saveReview($data);
            $this->json(['ok' => true, 'msg' => 'Avaliação enviada! Será publicada após aprovação.']);
        } catch (RuntimeException $e) {
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }

    // ── Produtos por categoria (Ajax — tabs da home) ──────────

    public function byCategory(): void {
        $catId  = SecurityHelper::sanitizeInt($_GET['categoria_id'] ?? 0);
        $limite = min(8, SecurityHelper::sanitizeInt($_GET['limite'] ?? 8));

        $products = ($catId == 'all') ? $this->productModel->getBestSellers(15) : $this->productModel->getCatalog(['categoria_id' => $catId], $limite);

        ob_start();
        foreach ($products as $product) {
            View::partial('partials/product-card', ['product' => $product]);
        }
        $html = ob_get_clean();

        if(empty($html)){
            $html = 'Nenhum produto encontrado...';
        }

        $this->json(['ok' => true, 'html' => $html]);
    }

    // ── Helpers privados ──────────────────────────────────────

    private function parseFilters(): array {
        return [
            'q'           => SecurityHelper::sanitizeString($_GET['q']           ?? ''),
            'categoria_id'=> SecurityHelper::sanitizeInt(  $_GET['categoria_id'] ?? 0),
            'marca_id'    => SecurityHelper::sanitizeInt(  $_GET['marca_id']     ?? 0),
            'marcas'      => isset($_GET['marcas']) && is_array($_GET['marcas'])
                             ? array_map('intval', $_GET['marcas']) : [],
            'preco_min'   => SecurityHelper::sanitizeFloat($_GET['preco_min']    ?? ''),
            'preco_max'   => SecurityHelper::sanitizeFloat($_GET['preco_max']    ?? ''),
            'em_promocao' => !empty($_GET['em_promocao']),
            'com_estoque' => !empty($_GET['com_estoque']),
            'ordem'       => SecurityHelper::sanitizeSlug( $_GET['ordem']        ?? 'relevancia'),
        ];
    }

    private function buildBreadcrumb(array $product): array {
        $crumbs = [['label' => 'Início', 'url' => BASE_URL]];

        if (!empty($product['categoria_slug'])) {
            $crumbs[] = [
                'label' => $product['categoria_nome'],
                'url'   => BASE_URL . '/categoria/' . $product['categoria_slug'],
            ];
        }
        $crumbs[] = ['label' => $product['nome'], 'url' => null];

        return $crumbs;
    }

    private function buildProductJsonLd(array $product, array $stats, array $images): string {
        $preco     = PriceHelper::currentPrice($product);
        $available = ((int)$product['estoque_total']) > 0
                     ? 'https://schema.org/InStock'
                     : 'https://schema.org/OutOfStock';

        $imageUrls = array_map(
            fn($img) => UPLOAD_URL . '/products/' . $img['arquivo'],
            array_slice($images, 0, 5)
        );

        $ld = [
            '@context'    => 'https://schema.org/',
            '@type'       => 'Product',
            'name'        => $product['nome'],
            'description' => strip_tags($product['descricao_curta'] ?? $product['nome']),
            'sku'         => $product['sku_legado'],
            'image'       => $imageUrls,
            'brand'       => ['@type' => 'Brand', 'name' => $product['marca_nome'] ?? ''],
            'offers'      => [
                '@type'         => 'Offer',
                'url'           => BASE_URL . '/produto/' . $product['slug'],
                'priceCurrency' => 'BRL',
                'price'         => number_format($preco, 2, '.', ''),
                'availability'  => $available,
                'seller'        => ['@type' => 'Organization', 'name' => ConfigHelper::get('site_nome', '')],
            ],
        ];

        if (!empty($stats['total']) && (int)$stats['total'] > 0) {
            $ld['aggregateRating'] = [
                '@type'       => 'AggregateRating',
                'ratingValue' => $stats['media'],
                'reviewCount' => $stats['total'],
                'bestRating'  => '5',
                'worstRating' => '1',
            ];
        }

        return '<script type="application/ld+json">' . json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
    }

    /**
     * Retorna as variações de um produto para o mini modal do card.
     */
    // public function cardVariations(): void {
    //     $id = SecurityHelper::sanitizeInt($_GET['id'] ?? 0);
    //     if (!$id) $this->json(['ok' => false]);

    //     $variation = new ProductVariation();
    //     $vdata     = $variation->getProductData($id);

    //     if (empty($vdata)) {
    //         $this->json(['ok' => false, 'sem_variacao' => true]);
    //     }

    //     $temVariacao = !empty($vdata['tipos_variacao'])
    //                 || !empty($vdata['atributos_agrupadores'])
    //                 && count($vdata['produtos_familia']) > 1;

    //     $this->json([
    //         'ok'             => true,
    //         'tem_variacao'   => $temVariacao,
    //         'tipos_slug'     => $vdata['tipos_slug'],
    //         'tipos_variacao' => $vdata['tipos_variacao'],
    //         'agrupadores'    => $vdata['atributos_agrupadores'],
    //         'familia'        => $vdata['produtos_familia'],
    //         'matriz'         => $vdata['matriz_skus'],
    //         'chave_map'      => array_reduce(
    //             $vdata['skus'] ?? [],
    //             function ($carry, $sku) use ($vdata) {
    //                 $partes = [];
    //                 foreach ($vdata['tipos_slug'] as $slug) {
    //                     $partes[] = $sku['atributos'][$slug] ?? '';
    //                 }
    //                 $chave = implode('|', $partes);
    //                 if ($chave) {
    //                     $carry[$chave] = !empty($sku['id_legado'])
    //                                     ? $sku['id_legado']
    //                                     : (string)$sku['sku_id'];
    //                 }
    //                 return $carry;
    //             }, []
    //         ),
    //         'vdata'      => $vdata['preco_min'],
    //         'preco_min'      => $vdata['preco_min'],
    //         'preco_max'      => $vdata['preco_max'],
    //         'preco_min_fmt'  => $vdata['preco_min_fmt'],
    //         'preco_max_fmt'  => $vdata['preco_max_fmt'],
    //         'tem_range'      => $vdata['tem_range_preco'],
    //     ]);
    // }

    public function cardVariations(): void {
        $id = SecurityHelper::sanitizeInt($_GET['id'] ?? 0);
        if (!$id) $this->json(['ok' => false]);

        $variation = new ProductVariation();
        $vdata     = $variation->getProductData($id);

        if (empty($vdata)) {
            $this->json(['ok' => false, 'sem_variacao' => true]);
        }

        $temVariacao = !empty($vdata['tipos_variacao'])
                    || (!empty($vdata['atributos_agrupadores'])
                        && count($vdata['produtos_familia']) > 1);

        // Corrige URLs das imagens dos membros da família
        $familia = array_map(function ($membro) {
            // Imagem principal do membro via helper
            $membro['imagem_url'] = ImageHelper::getPrincipal((int)$membro['id']);

            // Corrige valor_img dos agrupadores (adiciona URL base)
            if (!empty($membro['agrupadores_img'])) {
                foreach ($membro['agrupadores_img'] as $slug => &$img) {
                    if ($img && !str_starts_with($img, 'http')) {
                        $img = UPLOAD_URL . '/products/' . $img;
                    }
                }
                unset($img);
            }

            return $membro;
        }, $vdata['produtos_familia'] ?? []);

        // Monta chave_map
        $chaveMap = array_reduce(
            $vdata['skus'] ?? [],
            function ($carry, $sku) use ($vdata) {
                $partes = [];
                foreach ($vdata['tipos_slug'] as $slug) {
                    $partes[] = $sku['atributos'][$slug] ?? '';
                }
                $chave = implode('|', $partes);
                if ($chave) {
                    $carry[$chave] = !empty($sku['id_legado'])
                                    ? $sku['id_legado']
                                    : (string)$sku['sku_id'];
                }
                return $carry;
            }, []
        );

        $this->json([
            'ok'             => true,
            'tem_variacao'   => $temVariacao,
            'tipos_slug'     => $vdata['tipos_slug']          ?? [],
            'tipos_variacao' => $vdata['tipos_variacao']      ?? [],
            'agrupadores'    => $vdata['atributos_agrupadores'] ?? [],
            'familia'        => $familia,
            'matriz'         => $vdata['matriz_skus']         ?? [],
            'chave_map'      => $chaveMap,
            'preco_min'      => $vdata['preco_min']           ?? 0,
            'preco_max'      => $vdata['preco_max']           ?? 0,
            'preco_min_fmt'  => $vdata['preco_min_fmt']       ?? '',
            'preco_max_fmt'  => $vdata['preco_max_fmt']       ?? '',
            'tem_range'      => $vdata['tem_range_preco']     ?? false,
        ]);
    }

    public function avisarEstoque(): void
    {
        // público — não exige login, mas valida CSRF se vier do form
        $produtoId = SecurityHelper::sanitizeInt($_POST['produto_id'] ?? 0);
        $skuId     = !empty($_POST['sku_id']) ? SecurityHelper::sanitizeInt($_POST['sku_id']) : null;
        $email     = trim($_POST['email'] ?? '');
        $nome      = trim($_POST['nome']  ?? '') ?: null;

        // Se cliente logado, usa dados da sessão
        $clienteId = null;
        if (class_exists('Session') && Session::isClienteLogado()) {
            $clienteId = (int)Session::get('cliente_id');
            if ($email === '') {
                // pega email do cliente logado, se não informado
                $db = Database::getInstance()->getConnection();
                $st = $db->prepare("SELECT email, nome FROM clientes WHERE id = ? LIMIT 1");
                $st->execute([$clienteId]);
                if ($c = $st->fetch(PDO::FETCH_ASSOC)) {
                    $email = $c['email'];
                    $nome  = $nome ?: $c['nome'];
                }
            }
        }

        $gatilho = new ProdutoGatilhoService();
        $r = $gatilho->inscreverAviso($produtoId, $email, $nome, $clienteId, $skuId);
        $this->json($r);
    }
}