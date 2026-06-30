<?php
declare(strict_types=1);

class BannerController extends Controller {

    private Banner $bannerModel;

    public function __construct() {
        $this->bannerModel = new Banner();
    }

    // POST /banner/impressao — registra impressões em batch
    public function impressao(): void {
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids)) $this->json(['ok' => false]);

        foreach ($ids as $id) {
            $this->bannerModel->registrarImpressao((int)$id);
        }
        $this->json(['ok' => true]);
    }

    // GET /banner/click/{id}?url={destino}
    public function click(int $id): void {
        $url = $_GET['url'] ?? '/';

        $this->bannerModel->registrarClique(
            $id,
            $_SERVER['REMOTE_ADDR']        ?? null,
            $_SERVER['HTTP_USER_AGENT']    ?? null,
            $_SERVER['HTTP_REFERER']       ?? null
        );

        // Valida URL antes de redirecionar
        if (filter_var($url, FILTER_VALIDATE_URL) || str_starts_with($url, '/')) {
            header('Location: ' . $url);
            exit;
        }

        header('Location: ' . BASE_URL);
        exit;
    }
}

//<!-- Hero principal (slider) -->
//<?php View::partial('partials/banner-render', ['zona' => 'home_hero']) 

//<!-- Categorias (sua seção existente) -->
//<?php View::partial('partials/section-categorias') 

// <!-- Banner intermediário 1 -->
// <div class="container">
//   <?php View::partial('partials/banner-render', ['zona' => 'home_mid_1']) 
// </div>

// <!-- Produtos em destaque -->
// <?php View::partial('partials/produtos-destaque') 

// <!-- Banner duplo (grid) -->
// <div class="container">
//   <?php View::partial('partials/banner-render', ['zona' => 'home_mid_2']) 
// </div>

// <!-- Banner full-width -->
// <?php View::partial('partials/banner-render', ['zona' => 'home_categorias']) 

// <!-- views/products/catalog.php — topo da categoria -->
// <div class="container">
//   <?php View::partial('partials/banner-render', ['zona' => 'categoria_top']) 
// </div>

// <!-- views/products/show.php — sidebar do produto -->
// <aside class="produto-sidebar">
//   <?php View::partial('partials/banner-render', ['zona' => 'produto_lateral']) 
// </aside>