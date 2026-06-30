<?php
// admin/controllers/AdminAuthController.php

class AdminAuthController extends Controller {

    public function loginForm(): void {
        if (Session::isAdminLogado()) {
            $this->redirect(ADMIN_URL . '/dashboard');
        }

        // Renderiza a view diretamente sem layout do front-end
        $this->renderAdmin('auth/login', [
            'pageTitle' => 'Login — Admin',
        ]);
    }

    public function login(): void {
        $this->verifyCsrf();

        $email = SecurityHelper::sanitizeEmail($_POST['email'] ?? '');
        $senha = $_POST['senha'] ?? '';

        if (SecurityHelper::rateLimitExceeded('admin_login_' . md5($email), 5, 900)) {
            Session::flash('error', 'Muitas tentativas. Aguarde 15 minutos.');
            $this->redirect(ADMIN_URL . '/login');
        }

        $userModel = new User();
        $result    = $userModel->authenticate($email, $senha);

        if (!$result['ok']) {
            Session::flash('error', $result['msg']);
            $this->redirect(ADMIN_URL . '/login');
        }

        $user = $result['user'];

        if ($user['tipo'] !== 'admin' || empty($user['admin_id'])) {
            Session::flash('error', 'Acesso não autorizado.');
            $this->redirect(ADMIN_URL . '/login');
        }

        $admin = [
            'id'          => $user['admin_id'],
            'nivel'       => $user['nivel'],
            'permissoes'  => $user['permissoes'],
        ];
        Session::loginAdmin($user, $admin);
        SecurityHelper::clearRateLimit('admin_login_' . md5($email));

        // Log de acesso
        try {
            $db = Database::getInstance()->getConnection();
            $db->prepare(
                "INSERT INTO logs (nivel, canal, mensagem, usuario_id, ip)
                 VALUES ('info', 'admin', ?, ?, ?)"
            )->execute([
                "Login admin: {$user['email']}",
                $user['id'],
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        } catch (Exception) {}

        $this->redirect(ADMIN_URL . '/dashboard');
    }

    public function logout(): void {
        Session::logoutAdmin();
        $this->redirect(ADMIN_URL . '/login');
    }

    /**
     * Renderiza uma view do admin com layout próprio do painel.
     * Evita usar o sistema de layouts do front-end público.
     */
    private function renderAdmin(string $view, array $data = []): void {
        $data['csrf_token'] = SecurityHelper::generateCsrf();

        // Procura a view em admin/views/
        $viewFile = ADMIN_PATH . '/views/' . str_replace('.', '/', $view) . '.php';

        if (!file_exists($viewFile)) {
            throw new RuntimeException("View admin '{$view}' não encontrada em {$viewFile}.");
        }

        extract($data, EXTR_SKIP);
        include $viewFile;
        exit;
    }
}