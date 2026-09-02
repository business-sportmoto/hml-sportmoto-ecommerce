<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// admin/controllers/AdminBlingProdutoController.php
//
// Catálogo do Bling dentro do painel: lista, mostra o que já
// existe no site e importa sob demanda.
//
// Toda a regra vive no BlingProdutoImportService — aqui só
// entram validação de entrada, permissão e resposta.
// ════════════════════════════════════════════════════════

class AdminBlingProdutoController extends Controller
{
    private BlingProdutoImportService $svc;

    public function __construct()
    {
        AuthHelper::requireAdmin();
        // Mesmo nível das outras telas de integração: importar produto
        // cria catálogo e mexe em custo.
        AuthHelper::requireAdminLevel('super', 'gerente');
        $this->svc = new BlingProdutoImportService();
    }

    /** GET /admin/bling/produtos */
    public function index(): void
    {
        $conectado = (new BlingAuthService())->estaConectado();
        $this->render('bling/produtos', compact('conectado'), 'admin');
    }

    /** GET /admin/bling/produtos/listar?pagina=&termo=&campo= */
    public function listar(): void
    {
        $pagina = max(1, (int)($_GET['pagina'] ?? 1));
        $termo  = SecurityHelper::sanitizeString($_GET['termo'] ?? '');
        $campo  = SecurityHelper::sanitizeString($_GET['campo'] ?? 'nome');

        if (!in_array($campo, ['nome', 'codigo', 'ean'], true)) {
            $campo = 'nome';
        }

        $this->json($this->svc->listar($pagina, $termo, $campo));
    }

    /** POST /admin/bling/produtos/importar */
    public function importar(): void
    {
        $this->verifyCsrf();

        $blingId = SecurityHelper::sanitizeString($_POST['bling_id'] ?? '');
        if ($blingId === '') $this->json(['ok' => false, 'msg' => 'ID do Bling inválido.']);

        // Operação com 1 chamada de detalhe + gravação; folga para o
        // rate limit de 3 req/s não estourar o tempo padrão.
        set_time_limit(120);

        $this->json($this->svc->importar($blingId));
    }

    /** GET /admin/bling/produtos/diff?produto_id= */
    public function diff(): void
    {
        $produtoId = SecurityHelper::sanitizeInt($_GET['produto_id'] ?? 0);
        if (!$produtoId) $this->json(['ok' => false, 'msg' => 'Produto inválido.']);

        $this->json($this->svc->diff($produtoId));
    }

    /** POST /admin/bling/produtos/sincronizar */
    public function sincronizar(): void
    {
        $this->verifyCsrf();

        $produtoId = SecurityHelper::sanitizeInt($_POST['produto_id'] ?? 0);
        if (!$produtoId) $this->json(['ok' => false, 'msg' => 'Produto inválido.']);

        // Lista de colunas marcadas pelo admin. O service revalida contra
        // o próprio diff — nada aqui vira SQL direto.
        $campos = $_POST['campos'] ?? [];
        if (!is_array($campos)) $campos = [];
        $campos = array_values(array_filter(array_map(
            fn($c) => SecurityHelper::sanitizeString((string)$c), $campos
        )));

        $this->json($this->svc->aplicar($produtoId, $campos));
    }
}
