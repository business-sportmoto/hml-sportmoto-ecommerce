<?php
/**
 * admin/controllers/EmailSuppressionAdminController.php
 */
class EmailSuppressionAdminController extends Controller
{
    /** @var EmailSuppression */
    private $model;

    public function __construct()
    {
        // parent::__construct();
        $this->requirePermission();
        $this->model = new EmailSuppression();
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
        $filtros = [
            'motivo' => $_GET['motivo'] ?? '',
            'busca'  => trim((string)($_GET['busca'] ?? '')),
        ];
        $pagina = max(1, (int)($_GET['pagina'] ?? 1));
        $resultado = $this->model->listar($filtros, $pagina, 100);

        $this->render('email-marketing/supressoes/index', [
            'resultado' => $resultado,
            'filtros'   => $filtros,
            'titulo'    => 'Supressões',
        ], 'admin');
    }

    public function adicionar()
    {
        $this->verifyCsrf();
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $motivo = $_POST['motivo'] ?? 'manual';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['ok' => false, 'erro' => 'Email inválido']);
        }
        try {
            $this->model->adicionar($email, $motivo, 'admin', trim((string)($_POST['observacao'] ?? '')));
            return $this->json(['ok' => true]);
        } catch (Throwable $e) {
            return $this->json(['ok' => false, 'erro' => $e->getMessage()]);
        }
    }

    public function remover()
    {
        $this->verifyCsrf();
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['ok' => false, 'erro' => 'Email inválido']);
        }
        try {
            $this->model->remover($email);
            return $this->json(['ok' => true]);
        } catch (Throwable $e) {
            return $this->json(['ok' => false, 'erro' => $e->getMessage()]);
        }
    }
}
