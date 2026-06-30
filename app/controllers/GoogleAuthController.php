<?php
// ════════════════════════════════════════════════════════
// app/controllers/GoogleAuthController.php — v2
// ════════════════════════════════════════════════════════
class GoogleAuthController extends Controller {
 
    private GoogleAuthService $google;
    private PDO $db;
 
    public function __construct() {
        $this->google = new GoogleAuthService();
        $this->db     = Database::getInstance()->getConnection();
    }
 
    // ── POST /auth/google ──────────────────────────────────
    public function callback(): void {
        $this->verifyCsrf();
 
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (SecurityHelper::rateLimitExceeded('google_auth_' . md5($ip), 10, 60)) {
            $this->log(null, 'login_blocked', 'blocked', ['motivo' => 'rate_limit']);
            $this->json(['ok' => false, 'msg' => 'Muitas tentativas. Aguarde um momento.']);
        }
 
        $idToken = trim((string)($_POST['credential'] ?? ''));
        if (empty($idToken)) {
            $this->json(['ok' => false, 'msg' => 'Token ausente.']);
        }
 
        try {
            $payload = $this->google->validarToken($idToken);
        } catch (\Throwable $e) {
            $this->log(null, 'login_fail', 'failed', ['motivo' => $e->getMessage()]);
            $this->json(['ok' => false, 'msg' => 'Não foi possível validar sua conta Google.']);
        }
 
        try {
            $veredicto = $this->google->avaliarCenario($payload);
        } catch (\Throwable $e) {
            $this->log(null, 'login_fail', 'failed', ['motivo' => $e->getMessage()]);
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        }
 
        switch ($veredicto['cenario']) {
 
            case 'login_direto':
                $user = $this->google->buscarUsuario($veredicto['usuario_id']);
                if (!$user) {
                    $this->json(['ok' => false, 'msg' => 'Conta não encontrada.']);
                }
                $this->autenticar($user);
                $this->log((int)$user['id'], 'login_ok', 'success');
                $this->json(['ok' => true, 'redirect' => BASE_URL . '/minha-conta']);
                break;
 
            case 'criar_conta':
                // Guarda payload em sessão temporária (5 min)
                // e pede CPF + WhatsApp antes de criar a conta
                $_SESSION['pending_google'] = [
                    'payload'   => $payload,
                    'expira_em' => time() + 300,
                ];
                $this->json([
                    'ok'     => true,
                    'action' => 'completar_perfil',
                    'nome'   => $payload['given_name'] ?? '',
                    'email'  => $payload['email'],
                    'avatar' => $payload['picture']    ?? null,
                ]);
                break;
        }
    }
 
    // ── POST /auth/google/completar-perfil ─────────────────
    // Recebe CPF + telefone após o Google sign-in
    public function completarPerfil(): void {
        $this->verifyCsrf();
 
        $pending = $_SESSION['pending_google'] ?? null;
        if (!$pending || $pending['expira_em'] < time()) {
            unset($_SESSION['pending_google']);
            $this->json(['ok' => false, 'msg' => 'Sessão expirada. Tente novamente.']);
        }
 
        $cpf      = preg_replace('/\D/', '', $_POST['cpf']       ?? '');
        $telefone = preg_replace('/\D/', '', $_POST['telefone']  ?? '');
 
        // Validações
        if (!empty($cpf) && !SecurityHelper::validateCpf($cpf)) {
            $this->json(['ok' => false, 'campo' => 'cpf', 'msg' => 'CPF inválido.']);
        }
        if (empty($telefone) || strlen($telefone) < 10) {
            $this->json(['ok' => false, 'campo' => 'telefone', 'msg' => 'Informe um WhatsApp válido.']);
        }
 
        $db = Database::getInstance()->getConnection();
 
        // Verifica duplicidade de CPF
        if (!empty($cpf)) {
            $stmt = $db->prepare("SELECT id FROM clientes WHERE cpf = ? LIMIT 1");
            $stmt->execute([$cpf]);
            if ($stmt->fetchColumn()) {
                $this->json([
                    'ok'    => false,
                    'campo' => 'cpf',
                    'msg'   => 'Este CPF já está cadastrado. Faça login na conta existente e conecte o Google nas configurações.',
                ]);
            }
        }
 
        // Verifica duplicidade de e-mail (edge-case: alguém criou conta com esse email enquanto o pendente estava aberto)
        $payload = $pending['payload'];
        $email   = mb_strtolower($payload['email']);
        $stmt    = $db->prepare("SELECT id FROM usuarios WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        if ($stmt->fetchColumn()) {
            unset($_SESSION['pending_google']);
            $this->json([
                'ok'  => false,
                'msg' => 'Este e-mail já possui uma conta. Faça login normalmente e conecte o Google nas configurações da conta.',
            ]);
        }
 
        try {
            $usuarioId = $this->google->criarConta($payload, [
                'cpf'      => $cpf,
                'telefone' => $telefone,
            ]);
 
            unset($_SESSION['pending_google']);
 
            $user = $this->google->buscarUsuario($usuarioId);
            $this->autenticar($user);
            $this->log($usuarioId, 'signup_ok', 'success');
 
            $this->json([
                'ok'       => true,
                'redirect' => BASE_URL . '/minha-conta',
                'msg'      => 'Conta criada com sucesso! Bem-vindo(a)!',
            ]);
        } catch (\Throwable $e) {
            error_log('Google completarPerfil error: ' . $e->getMessage());
            $this->json(['ok' => false, 'msg' => 'Erro ao criar conta. Tente novamente.']);
        }
    }
 
    // ── POST /auth/google/cancelar ─────────────────────────
    public function cancelar(): void {
        unset($_SESSION['pending_google']);
        $this->json(['ok' => true]);
    }
 
    // ── POST /auth/google/vincular-conta ──────────────────
    // Vincula Google à conta já logada (em /minha-conta/sessoes)
    public function vincularConta(): void {
        AuthHelper::requireCustomer();
        $this->verifyCsrf();
 
        $idToken = trim((string)($_POST['credential'] ?? ''));
        if (empty($idToken)) {
            $this->json(['ok' => false, 'msg' => 'Token ausente.']);
        }
 
        try {
            $payload = $this->google->validarToken($idToken);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'msg' => 'Não foi possível validar o Google.']);
        }
 
        $usuarioId = (int)Session::get('usuario_id');
        $sub       = $payload['sub'];
 
        // Verifica se este sub já está vinculado a OUTRA conta
        $stmt = $this->db->prepare(
            "SELECT cliente_id FROM social_accounts
             WHERE provider = 'google' AND provider_id = ? LIMIT 1"
        );
        $stmt->execute([$sub]);
        $existente = $stmt->fetchColumn();
 
        if ($existente && (int)$existente !== $usuarioId) {
            $this->json([
                'ok'  => false,
                'msg' => 'Esta conta Google já está vinculada a outro usuário.',
            ]);
        }
        if ((int)$existente === $usuarioId) {
            $this->json([
                'ok'  => false,
                'msg' => 'Esta conta Google já está vinculada à sua conta.',
            ]);
        }
 
        $this->google->vincular($usuarioId, $payload);
        $this->google->atualizarAvatar($usuarioId, $payload['picture'] ?? '');
 
        $this->log($usuarioId, 'link_ok', 'success');
        $this->json(['ok' => true, 'msg' => 'Conta Google conectada com sucesso!']);
    }
 
    // ── POST /auth/google/desvincular-conta ───────────────
    public function desvincularConta(): void {
        AuthHelper::requireCustomer();
        $this->verifyCsrf();
 
        $usuarioId = (int)Session::get('usuario_id');
 
        try {
            $this->google->desvincular($usuarioId);
            $this->log($usuarioId, 'unlink_ok', 'success');
            $this->json(['ok' => true, 'msg' => 'Conta Google desconectada.']);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }
 
    // ── Autenticar (session) ───────────────────────────────
    private function autenticar(array $user): void {
        session_regenerate_id(true);
 
        $db      = Database::getInstance()->getConnection();
        $cliente = ['id' => $user['cliente_id']];
 
        Session::loginCliente($user, $cliente, false);
 
        $stmt = $db->prepare("SELECT verificado FROM clientes WHERE id = ? LIMIT 1");
        $stmt->execute([$user['cliente_id']]);
        Session::set('cliente_verificado', (bool)$stmt->fetchColumn());
        Session::set('login_provider', 'google');
 
        // Audit session
        $this->registrarSessaoAuditoria((int)$user['id'], $db);
 
        if (class_exists('VeiculoService')) {
            (new VeiculoService())->carregarDoCliente((int)$user['cliente_id']);
        }
    }
 
    private function registrarSessaoAuditoria(int $usuarioId, PDO $db): void {
        $ip    = $_SERVER['REMOTE_ADDR']     ?? null;
        $ua    = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $token = hash('sha256', session_id() . $usuarioId . time());
 
        try {
            $db->prepare(
                "INSERT INTO sessoes_persistentes
                 (usuario_id, token, ip, user_agent, ultima_atividade, expira_em)
                 VALUES (?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR))"
            )->execute([$usuarioId, $token, $ip, $ua]);
            Session::set('_audit_session_token', $token);
        } catch (\Throwable $e) {
            error_log('Audit session error: ' . $e->getMessage());
        }
    }
 
    private function log(?int $usuarioId, string $event, string $status, array $meta = []): void {
        if (class_exists('AuthLogService')) {
            AuthLogService::registrar($usuarioId, $event, $status, 'google', $meta);
        }
    }
}