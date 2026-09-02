<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// admin/controllers/PageAdminController.php
//
// Criador de páginas de conteúdo (termos, privacidade, trocas, FAQ…).
//
// O arquivo existia vazio e as rotas já apontavam para cá desde abril — abrir
// /admin/paginas dava erro fatal de classe não encontrada.
//
// Nível: editor entra, porque isto é conteúdo do site. Excluir é gerente+:
// apagar uma página publicada quebra links externos e é irreversível.
// ════════════════════════════════════════════════════════

class PageAdminController extends Controller
{
    private PaginaService $service;

    public function __construct()
    {
        AuthHelper::requireAdmin();
        AuthHelper::requireAdminLevel('super', 'gerente', 'editor');
        $this->service = new PaginaService();
    }

    // ── GET /admin/paginas ───────────────────────────────
    public function index(): void
    {
        $filtros = [
            'busca'  => SecurityHelper::sanitizeString($_GET['busca']  ?? ''),
            'status' => SecurityHelper::sanitizeString($_GET['status'] ?? ''),
        ];

        $this->render('paginas/index', [
            'page_title' => 'Páginas',
            'paginas'    => $this->service->listar($filtros),
            'filtros'    => $filtros,
            'emArquivo'  => $this->paginasEmArquivo(),
        ], 'admin');
    }

    // ── GET /admin/paginas/nova ──────────────────────────
    public function nova(): void
    {
        $this->render('paginas/form', [
            'page_title' => 'Nova página',
            'pagina'     => null,
        ], 'admin');
    }

    // ── GET /admin/paginas/editar/{id} ───────────────────
    public function edit(int $id): void
    {
        $pagina = $this->service->porId($id);
        if (!$pagina) $this->notFound();

        $this->render('paginas/form', [
            'page_title' => 'Editar: ' . $pagina['titulo'],
            'pagina'     => $pagina,
        ], 'admin');
    }

    // ── POST /admin/paginas/salvar ───────────────────────
    public function save(): void
    {
        $this->verifyCsrf();

        $id = (int) ($_POST['id'] ?? 0);
        $r  = $this->service->salvar($_POST, $id);

        if (!empty($r['ok'])) {
            LogService::audit($id > 0 ? 'Página atualizada' : 'Página criada', [
                'pagina_id'  => $r['id'],
                'slug'       => $r['slug'],
                'usuario_id' => AuthHelper::usuarioId(),
            ]);
            $r['redirect'] = ADMIN_URL . '/paginas/editar/' . $r['id'];
        }

        $this->json($r);
    }

    // ── POST /admin/paginas/alternar ─────────────────────
    public function alternar(): void
    {
        $this->verifyCsrf();
        $this->json($this->service->alternarAtivo((int) ($_POST['id'] ?? 0)));
    }

    // ── POST /admin/paginas/excluir ──────────────────────
    public function excluir(): void
    {
        $this->verifyCsrf();
        AuthHelper::requireAdminLevel('super', 'gerente');
        $this->json($this->service->excluir((int) ($_POST['id'] ?? 0)));
    }

    /**
     * Páginas montadas em arquivo, só para a lista mostrá-las.
     *
     * Entram como leitura, nunca como edição: o conteúdo delas é PHP executado,
     * e um painel que grava PHP no disco é execução remota de código por
     * construção. Aparecem na lista porque quem procura "quem-somos" no painel
     * precisa descobrir onde ela mora, em vez de criar uma duplicata.
     */
    private function paginasEmArquivo(): array
    {
        $out = [];
        foreach (PageController::getAllPages() as $p) {
            if (($p['origem'] ?? '') === 'banco') continue;
            $out[] = [
                'slug'   => $p['slug'],
                'titulo' => $p['titulo'] ?? $p['slug'],
                'ativa'  => (bool) ($p['ativa'] ?? true),
            ];
        }
        return $out;
    }
}
