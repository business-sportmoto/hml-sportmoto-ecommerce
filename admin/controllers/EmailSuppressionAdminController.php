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

    private function requirePermission(): void
    {
        // A cascata mora no AuthHelper agora — ver o porquê lá.
        AuthHelper::requirePermissaoOuNivel('email_marketing', 'super', 'gerente');
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
