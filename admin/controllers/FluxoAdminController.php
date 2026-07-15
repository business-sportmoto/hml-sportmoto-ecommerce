<?php
/**
 * admin/controllers/FluxoAdminController.php
 *
 * Rotas:
 *   GET  /admin/fluxos                → listagem
 *   GET  /admin/fluxos/{id}           → editor (JSON)
 *   POST /admin/fluxos/criar          → novo fluxo
 *   POST /admin/fluxos/{id}/salvar    → salva rascunho
 *   POST /admin/fluxos/{id}/publicar  → valida + publica
 *   POST /admin/fluxos/{id}/status    → pausar/reativar/arquivar
 */
class FluxoAdminController extends Controller
{
    /** @var FluxoAdminService */
    private $svc;

    public function __construct()
    {
        // parent::__construct();
        if (method_exists('AuthHelper', 'requirePermission')) {
            try { AuthHelper::requirePermission('automacao'); }
            catch (Throwable $e) { AuthHelper::requireAdminLevel(); }
        } else {
            AuthHelper::requireAdmin();
        }
        $this->svc = new FluxoAdminService();
    }

    public function index(): void
    {
        $this->render('fluxos/index', [
            'fluxos'   => $this->svc->listar(),
            'catalogo' => FluxoNoRegistry::catalogo(),
            'titulo'   => 'Automação v2 — Fluxos',
        ], 'admin');
    }

    public function editor(int $id): void
    {
        
        $fluxo = $this->svc->carregar($id);
        if (!$fluxo) { http_response_code(404); echo 'Fluxo não encontrado. '.json_encode($_GET) ; return; }

        // Templates de email para o select do nó acao_email
        $emailTemplates = [];
        try {
            $db = Database::getInstance()->getConnection();
            $st = $db->query("SELECT id, nome FROM email_templates ORDER BY id DESC LIMIT 200");
            $emailTemplates = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            // sem templates o canvas ainda funciona; o select fica vazio
        }

        $this->render('fluxos/editor', [
            'fluxo'          => $fluxo,
            'catalogo'       => FluxoNoRegistry::catalogo(),
            'emailTemplates' => $emailTemplates,
            'titulo'         => 'Fluxo — ' . $fluxo['nome'],
        ], 'admin');
    }

    public function criar(): void
    {
        $this->verifyCsrf();
        $nome = trim($_POST['nome'] ?? '');
        if ($nome === '') { $this->json(['ok' => false, 'erro' => 'Informe o nome.']); return; }
        $id = $this->svc->criar($nome, trim($_POST['descricao'] ?? '') ?: null);
        $this->json(['ok' => true, 'id' => $id]);
    }

    public function salvar(): void
    {
        $this->verifyCsrf();
        $id = (int)($_POST['fluxo_id'] ?? 0);

        $grafo = json_decode($_POST['grafo_json'] ?? '', true);
        if (!is_array($grafo)) {
            $this->json(['ok' => false, 'erros' => ['JSON do grafo inválido.']]); return;
        }

        $meta = null;
        if (isset($_POST['config_json'])) {
            $cfg = json_decode($_POST['config_json'], true);
            $meta = ['config' => is_array($cfg) ? $cfg : []];
        }

        $r = $this->svc->salvarRascunho($id, $grafo, $meta);
        $this->json($r);
    }

    public function publicar(): void
    {
        $this->verifyCsrf();
        $id = (int)($_POST['fluxo_id'] ?? 0);
        $this->json($this->svc->publicar($id));
    }

    public function status(): void
    {
        $this->verifyCsrf();
        $id     = (int)($_POST['fluxo_id'] ?? 0);
        $status = (string)($_POST['status'] ?? '');
        $ok = $this->svc->mudarStatus($id, $status);
        $this->json(['ok' => $ok]);
    }
}
