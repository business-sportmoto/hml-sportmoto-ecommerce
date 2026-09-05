<?php
/**
 * admin/controllers/EmailContactAdminController.php
 */
class EmailContactAdminController extends Controller
{
    /** @var EmailContact */
    private $model;
    /** @var EmailConsentService */
    private $consents;

    public function __construct()
    {
        // parent::__construct();
        $this->requirePermission();
        $this->model = new EmailContact();
        $this->consents = new EmailConsentService();
    }

    private function requirePermission(): void
    {
        // A cascata mora no AuthHelper agora — ver o porquê lá.
        AuthHelper::requirePermissaoOuNivel('email_marketing', 'super', 'gerente');
    }

    public function index()
    {
        $filtros = [
            'status' => $_GET['status'] ?? '',
            'origem' => $_GET['origem'] ?? '',
            'busca'  => trim((string)($_GET['busca'] ?? '')),
        ];
        $pagina = max(1, (int)($_GET['pagina'] ?? 1));
        $resultado = $this->model->listar($filtros, $pagina, 50);

        $this->render('email-marketing/contatos/index', [
            'resultado' => $resultado,
            'filtros'   => $filtros,
            'titulo'    => 'Contatos de Email',
        ], 'admin');
    }

    public function sincronizar()
    {
        $this->verifyCsrf();
        try {
            $r = $this->consents->sincronizarTudo();
            if (class_exists('LogService')) {
                LogService::audit('email_contatos_sincronizar', $r);
            }
            return $this->json(['ok' => true, 'resultado' => $r]);
        } catch (Throwable $e) {
            return $this->json(['ok' => false, 'erro' => $e->getMessage()]);
        }
    }

    public function bloquear()
    {
        $this->verifyCsrf();
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) return $this->json(['ok' => false, 'erro' => 'ID inválido']);
        try {
            $this->consents->bloquearAdmin($id, [
                'origem' => 'admin',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]);
            return $this->json(['ok' => true]);
        } catch (Throwable $e) {
            return $this->json(['ok' => false, 'erro' => $e->getMessage()]);
        }
    }

    public function desbloquear()
    {
        $this->verifyCsrf();
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) return $this->json(['ok' => false, 'erro' => 'ID inválido']);
        try {
            $this->consents->desbloquearAdmin($id, [
                'origem' => 'admin',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]);
            return $this->json(['ok' => true]);
        } catch (Throwable $e) {
            return $this->json(['ok' => false, 'erro' => $e->getMessage()]);
        }
    }
}
