<?php
/**
 * admin/controllers/EmailSegmentAdminController.php
 */
class EmailSegmentAdminController extends Controller
{
    /** @var EmailSegment */
    private $model;
    /** @var EmailSegmentService */
    private $svc;

    public function __construct()
    {
        // parent::__construct();
        $this->requirePermission();
        $this->model = new EmailSegment();
        $this->svc = new EmailSegmentService();
    }

    private function requirePermission()
    {
        if (method_exists('AuthHelper', 'requirePermission')) {
            try { AuthHelper::requirePermission('email_marketing'); return; } catch (Throwable $e) {}
        }
        if (method_exists('AuthHelper', 'requireAdminLevel')) {
            AuthHelper::requireAdminLevel(); return;
        }
        AuthHelper::requireAdmin();
    }

    public function index()
    {
        $itens = $this->model->all(false);
        $cfg = require dirname(__DIR__, 2) . '/config/email-marketing.php';

        $this->render('email-marketing/segmentos/index', [
            'itens' => $itens,
            'campos_permitidos' => $cfg['segment_whitelist'],
            'titulo' => 'Segmentos de Email',
        ], 'admin');
    }

    public function salvar()
    {
        $this->verifyCsrf();
        $id = (int)($_POST['id'] ?? 0);
        $nome = trim((string)($_POST['nome'] ?? ''));
        $regras = $_POST['regras_json'] ?? '';
        if ($nome === '' || $regras === '') {
            return $this->json(['ok' => false, 'erro' => 'Nome e regras são obrigatórios']);
        }
        $arr = json_decode($regras, true);
        if (!is_array($arr)) {
            return $this->json(['ok' => false, 'erro' => 'Regras: JSON inválido']);
        }
        try {
            $novoId = $this->model->save([
                'id'   => $id,
                'nome' => $nome,
                'descricao'   => trim((string)($_POST['descricao'] ?? '')) ?: null,
                'regras_json' => $arr,
                'ativo' => !empty($_POST['ativo']) ? 1 : 0,
            ]);
            $total = $this->svc->estimar($arr);
            $this->model->atualizarEstimativa($novoId, $total);
            return $this->json(['ok' => true, 'id' => $novoId, 'estimativa' => $total]);
        } catch (Throwable $e) {
            return $this->json(['ok' => false, 'erro' => $e->getMessage()]);
        }
    }

    public function preview()
    {
        $this->verifyCsrf();
        $regras = $_POST['regras_json'] ?? '';
        $arr = json_decode($regras, true);
        if (!is_array($arr)) {
            return $this->json(['ok' => false, 'erro' => 'JSON inválido']);
        }
        try {
            $total = $this->svc->estimar($arr);
            return $this->json(['ok' => true, 'estimativa' => $total]);
        } catch (Throwable $e) {
            return $this->json(['ok' => false, 'erro' => $e->getMessage()]);
        }
    }

    public function excluir()
    {
        $this->verifyCsrf();
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) return $this->json(['ok' => false, 'erro' => 'ID inválido']);
        try {
            $this->model->delete($id);
            return $this->json(['ok' => true]);
        } catch (Throwable $e) {
            return $this->json(['ok' => false, 'erro' => $e->getMessage()]);
        }
    }
}
