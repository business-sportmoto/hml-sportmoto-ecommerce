<?php
declare(strict_types=1);

// admin/controllers/AdminAuthController.php

/**
 * Controlador de autenticacao do painel administrativo (endurecido).
 *
 * CAMADAS (defense in depth):
 *  1. WAF/rate-limit da Cloudflare na borda (config no painel, fora deste codigo).
 *  2. AuthLogService::loginGate() — rate-limit por IP real + circuit breaker global.
 *  3. User::authenticate em tempo constante, retorno NEUTRO (anti-enumeracao).
 *  4. AuthLogService::registrar() — auditoria estruturada com IP real.
 *  5. Sessao regenerada por Session::loginAdmin (anti-fixation) — ja existente.
 *
 * Vocabulario login_status: 'fail' / 'success'.
 *
 * @see OWASP A01 (Broken Access Control), A07 (Auth Failures), A09 (Logging)
 */
final class AdminAuthController extends Controller
{
    /** Piso de tempo (ms) por tentativa — nivela ruido residual de timing. */
    private const MIN_RESPONSE_MS = 350;

    public function loginForm(): void
    {
        if (Session::isAdminLogado()) {
            $this->redirect(ADMIN_URL . '/dashboard');
        }
        $this->renderAdmin('auth/login', [
            'pageTitle' => 'Acesso — Painel Administrativo',
        ]);
    }

    public function login(): void
    {
        $startedAt = hrtime(true);
        $this->verifyCsrf();

        $email = SecurityHelper::sanitizeEmail($_POST['email'] ?? '');
        $senha = (string) ($_POST['senha'] ?? '');
        $ip    = AuthLogService::clientIp();

        // ── Camada 2: rate-limit por IP + circuit breaker global ──────────
        $gate = AuthLogService::loginGate();
        if (!$gate['allowed']) {
            AuthLogService::registrar(
                null, 'admin_login', 'fail', 'local',
                ['motivo' => 'throttled:' . $gate['reason'], 'ip_real' => $ip]
            );
            $mins = (int) ceil($gate['retry_after'] / 60);
            $this->finishWithError($startedAt, "Muitas tentativas. Tente novamente em {$mins} minuto(s).");
        }

        // ── Camada 3: autenticacao em tempo constante, retorno neutro ─────
        $result = (new User())->authenticate($email, $senha);

        $isAdmin =
            ($result['ok'] ?? false) === true
            && (($result['user']['tipo'] ?? null) === 'admin')
            && !empty($result['user']['admin_id']);

        if (!$isAdmin) {
            // Registra falha (alimenta o throttle por IP). NUNCA distinguir
            // 'invalid'/'locked'/'inactive'/'nao-admin' ao usuario.
            AuthLogService::registrar(
                null, 'admin_login', 'fail', 'local',
                ['motivo' => $result['reason'] ?? 'invalid', 'ip_real' => $ip]
            );
            $this->finishWithError($startedAt, 'Credenciais invalidas.');
        }

        $user  = $result['user'];
        $admin = [
            'id'         => $user['admin_id'],
            'nivel'      => $user['nivel'],
            'permissoes' => $user['permissoes'],
        ];

        Session::loginAdmin($user, $admin);

        AuthLogService::registrar(
            null, 'admin_login', 'success', 'local',
            ['admin_id' => (int) $user['admin_id'], 'email' => $user['email'], 'ip_real' => $ip]
        );

        $this->normalizeTiming($startedAt);
        $this->redirect(ADMIN_URL . '/dashboard');
    }

    public function logout(): void
    {
        $adminId = Session::getAdminId();
        Session::logoutAdmin();
        AuthLogService::registrar(
            null, 'admin_logout', 'success', 'local',
            ['admin_id' => $adminId, 'ip_real' => AuthLogService::clientIp()]
        );
        $this->redirect(ADMIN_URL . '/login');
    }

    // ── internos ──────────────────────────────────────────────────────────

    /** Finaliza tentativa falha com tempo minimo e mensagem generica. */
    private function finishWithError(int $startedAt, string $message): never
    {
        $this->normalizeTiming($startedAt);
        Session::flash('error', $message);
        $this->redirect(ADMIN_URL . '/login');
    }

    /** Garante MIN_RESPONSE_MS desde o inicio da request. */
    private function normalizeTiming(int $startedAt): void
    {
        $elapsedMs = (hrtime(true) - $startedAt) / 1_000_000;
        $remaining = self::MIN_RESPONSE_MS - $elapsedMs;
        if ($remaining > 0) {
            usleep((int) ($remaining * 1000));
        }
    }

    /** Renderiza view do painel sem layout do front; dados controlados. */
    private function renderAdmin(string $view, array $data = []): void
    {
        $data['csrf_token'] = SecurityHelper::generateCsrf();
        $viewFile = ADMIN_PATH . '/views/' . str_replace('.', '/', $view) . '.php';
        if (!file_exists($viewFile)) {
            throw new RuntimeException("View admin '{$view}' nao encontrada.");
        }
        extract($data, EXTR_SKIP);
        include $viewFile;
        exit;
    }
}