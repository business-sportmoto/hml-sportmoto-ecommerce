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

        // ── SEO ──────────────────────────────────────────────────────
        // A página não definia nada: título era o nome da loja e não havia
        // canonical. E o conteúdo dela é o que assistente de IA mais cita —
        // pergunta e resposta prontas. Por isso vai como FAQPage.
        $pares = [];
        foreach ($agrupadas as $lista) {
            foreach ($lista as $p) {
                $pares[] = ['pergunta' => $p['pergunta'] ?? '', 'resposta' => $p['resposta'] ?? ''];
            }
        }

        SeoHelper::setTitle('Central de ajuda');
        SeoHelper::setDescription(
            'Dúvidas sobre compra, entrega, pagamento, troca e devolução na '
            . ConfigHelper::get('site_nome', 'loja') . '.'
        );
        SeoHelper::setCanonical(BASE_URL . '/ajuda');
        SeoHelper::setFaq($pares, 30);
        SeoHelper::setBreadcrumb([
            ['label' => 'Início', 'url' => BASE_URL],
            ['label' => 'Central de ajuda', 'url' => BASE_URL . '/ajuda'],
        ]);

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
            $this->naoEncontrado('main');
            return;
        }

        $perguntas  = $this->faqModel->getByCategoriaId((int) $categoria['id']);
        $categorias = $this->categoriaModel->getAllAtivas();

        SeoHelper::setTitle($categoria['nome'] . ' — dúvidas frequentes');
        SeoHelper::setDescription(
            'Respostas sobre ' . mb_strtolower((string) $categoria['nome']) . ' na '
            . ConfigHelper::get('site_nome', 'loja') . '.'
        );
        SeoHelper::setCanonical(BASE_URL . '/ajuda/categoria/' . $categoria['slug']);
        SeoHelper::setFaq(array_map(
            static fn(array $p) => ['pergunta' => $p['pergunta'] ?? '', 'resposta' => $p['resposta'] ?? ''],
            $perguntas
        ), 30);
        SeoHelper::setBreadcrumb([
            ['label' => 'Início',           'url' => BASE_URL],
            ['label' => 'Central de ajuda', 'url' => BASE_URL . '/ajuda'],
            ['label' => (string) $categoria['nome'], 'url' => BASE_URL . '/ajuda/categoria/' . $categoria['slug']],
        ]);

        $this->render('help/categoria', [
            'categoria'  => $categoria,
            'perguntas'  => $perguntas,
            'categorias' => $categorias,
        ]);
    }
}