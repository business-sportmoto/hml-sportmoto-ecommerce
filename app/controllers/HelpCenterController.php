<?php

class HelpCenterController extends Controller {

    private HelpFaqCategoria $categoriaModel;
    private HelpFaq          $faqModel;

    public function __construct() {
        $this->categoriaModel = new HelpFaqCategoria();
        $this->faqModel       = new HelpFaq();
    }

    // GET /ajuda  ou  GET /help-center
    public function index(): void {
        $categorias = $this->categoriaModel->getAllAtivas();
        $agrupadas  = $this->faqModel->getAllAtivasAgrupadas();

        $this->render('help/index', [
            'categorias' => $categorias,
            'agrupadas'  => $agrupadas,
            'termo'      => '',
            'resultados' => [],
        ]);
    }

    // GET /ajuda/busca?q=...
    public function busca(): void {
        $termo      = trim($_GET['q'] ?? '');
        $resultados = [];
        $categorias = $this->categoriaModel->getAllAtivas();

        if (strlen($termo) >= 3) {
            $resultados = $this->faqModel->search($termo);
        }

        $this->render('help/index', [
            'categorias' => $categorias,
            'agrupadas'  => [],
            'termo'      => htmlspecialchars($termo, ENT_QUOTES),
            'resultados' => $resultados,
        ]);
    }

    // GET /ajuda/categoria/:slug
    public function categoria(string $slug): void {
        $categoria = $this->categoriaModel->getBySlug($slug);
        if (!$categoria) {
            http_response_code(404);
            $this->render('errors/404');
            return;
        }

        $perguntas  = $this->faqModel->getByCategoriaId((int) $categoria['id']);
        $categorias = $this->categoriaModel->getAllAtivas();

        $this->render('help/categoria', [
            'categoria'  => $categoria,
            'perguntas'  => $perguntas,
            'categorias' => $categorias,
        ]);
    }
}