<?php
// core/Session.php
// Gerencia sessões PHP com configurações de segurança reforçadas.
// Suporta sessões normais e sessões persistentes (lembrar login).

class Session {

    private static bool $started = false;

    /**
     * Inicia a sessão com configurações seguras.
     * Deve ser chamado UMA VEZ no bootstrap (index.php).
     */
    public static function start(): void {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        // Configurações de segurança antes de session_start()
        session_name(SESSION_NAME);

        session_set_cookie_params([
            'lifetime' => 0,                // expira ao fechar o browser (sessão normal)
            'path'     => '/',
            'domain'   => '',
            // isset($_SERVER['HTTPS']) errava nos DOIS sentidos: atrás do
            // Cloudflare a variável não vem e o cookie saía sem `secure`;
            // em servidores que definem HTTPS='off' o isset() dava true e
            // marcava `secure` em HTTP puro — o browser recusa o cookie e
            // a sessão se perde a cada request. SecurityHelper::isHttps()
            // é a mesma detecção proxy-aware usada pelos demais cookies.
            'secure'   => SecurityHelper::isHttps(),
            'httponly' => true,             // inacessível via JavaScript
            'samesite' => 'Lax',            // proteção CSRF básica
        ]);

        session_start();
        self::$started = true;

        // Regenera o ID de sessão periodicamente para evitar session fixation
        // if (!self::has('_session_init')) {
        //     session_regenerate_id(true);
        //     self::set('_session_init', time());
        // } elseif ((time() - self::get('_session_init')) > 1800) {
        //     // Regenera a cada 30 minutos
        //     session_regenerate_id(true);
        //     self::set('_session_init', time());
        // }
        // Anti-session-fixation: regenera UMA vez ao nascer a sessão.
        // - false (não true): NÃO apaga a sessão antiga na hora. Com true,
        //   um request AJAX que regenerava apagava a sessão enquanto outros
        //   requests paralelos ainda mandavam o cookie antigo → logout.
        // - Regeneração PERIÓDICA (30min) REMOVIDA: era a causa do logout
        //   aleatório em uso ativo. O fixation já é tratado no login
        //   (loginAdmin/loginCliente usam true, e lá NÃO há concorrência).
        if (!self::has('_session_init')) {
            session_regenerate_id(false);
            self::set('_session_init', time());
        }

        // Valida a sessão por User-Agent para dificultar session hijacking
        self::validateFingerprint();
    }

    /** Verifica se a sessão pertence ao mesmo navegador */
    private static function validateFingerprint(): void {
        // A API do app não tem navegador: o User-Agent varia entre WebView,
        // Metro em dev e o runtime nativo, e o vínculo de confiança já é o
        // Bearer token do dispositivo. Sem este guard, a sessão do app seria
        // destruída e o carrinho perdido a cada troca de User-Agent.
        if (defined('APP_API')) {
            return;
        }

        $fingerprint = hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . 'ec_salt_2024');

        if (!self::has('_fingerprint')) {
            self::set('_fingerprint', $fingerprint);
        } elseif (self::get('_fingerprint') !== $fingerprint) {
            // Sessão suspeita — destrói e recria
            self::destroy();
            self::start();
        }
    }

    // ── Métodos básicos ───────────────────────────────────────

    public static function set(string $key, mixed $value): void {
        $_SESSION[$key] = $value;
    }

    public static function get(string $key, mixed $default = null): mixed {
        return $_SESSION[$key] ?? $default;
    }

    public static function has(string $key): bool {
        return isset($_SESSION[$key]);
    }

    public static function remove(string $key): void {
        unset($_SESSION[$key]);
    }

    public static function destroy(): void {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        session_destroy();
        self::$started = false;
    }

    // ── Flash messages (mensagens que somem após leitura) ─────

    public static function flash(string $key, mixed $value): void {
        $_SESSION['_flash'][$key] = $value;
    }

    public static function getFlash(string $key, mixed $default = null): mixed {
        $value = $_SESSION['_flash'][$key] ?? $default;
        unset($_SESSION['_flash'][$key]);
        return $value;
    }

    public static function hasFlash(string $key): bool {
        return isset($_SESSION['_flash'][$key]);
    }

    // ── Autenticação de cliente ───────────────────────────────

    public static function loginCliente(array $usuario, array $cliente, bool $lembrar = false): void {
        session_regenerate_id(true); // previne session fixation no login

        self::set('cliente_logado', true);
        self::set('cliente_id', $cliente['id']);
        self::set('cliente_nome', $usuario['nome']);
        self::set('cliente_email', $usuario['email']);
        self::set('usuario_id', $usuario['id']);
        self::set('login_em', time());

        if ($lembrar) {
            // O cookie persistente é gerenciado por TokenService
            self::set('lembrar_sessao', true);
        }
    }

    public static function isClienteLogado(): bool {
        return self::get('cliente_logado') === true;
    }

    public static function getClienteId(): ?int {
        return self::isClienteLogado() ? (int) self::get('cliente_id') : null;
    }

    public static function logoutCliente(): void {
        self::remove('cliente_logado');
        self::remove('cliente_id');
        self::remove('cliente_nome');
        self::remove('cliente_email');
        self::remove('usuario_id');
        self::remove('login_em');
        self::remove('lembrar_sessao');

        // Estado derivado do login anterior. Deixar para trás fazia a
        // sessão seguinte herdar decisões da conta antiga: o guard de
        // e-mail lia um `email_verificado` que não era mais dele, e a
        // validação remota apontava para uma linha de outro dono.
        self::remove('email_verificado');
        self::remove('_session_verified_at');
        self::remove('_sessao_persistente_id');
        self::remove('_audit_session_token');
        self::remove('_last_activity_touch');

        session_regenerate_id(true);

        LogService::info('logoutCliente');
    }

    // ── Autenticação de admin ─────────────────────────────────

    public static function loginAdmin(array $usuario, array $admin): void {
        session_regenerate_id(true);

        self::set('admin_logado', true);
        self::set('admin_id', $admin['id']);
        // self::set('admin_user_id', $usuario['usuario_id']);
        self::set('admin_user_id', (int)($usuario['id'] ?? $admin['usuario_id'] ?? $usuario['usuario_id'] ?? 0));
        self::set('admin_nivel', $admin['nivel']);
        self::set('admin_permissoes', json_decode($admin['permissoes'] ?? '{}', true));
        self::set('admin_nome', $usuario['nome']);
        self::set('admin_email', $usuario['email']);
        self::set('admin_login_em', time());
    }

    public static function isAdminLogado(): bool {
        return self::get('admin_logado') === true;
    }

    public static function getAdminId(): ?int {
        return self::isAdminLogado() ? (int) self::get('admin_id') : null;
    }

    public static function adminTemPermissao(string $permissao): bool {
        if (!self::isAdminLogado()) return false;
        if (self::get('admin_nivel') === 'super') return true;

        $permissoes = self::get('admin_permissoes', []);
        return ($permissoes[$permissao] ?? false) === true;
    }

    public static function logoutAdmin(): void {
        self::remove('admin_logado');
        self::remove('admin_id');
        self::remove('admin_user_id');
        self::remove('admin_nivel');
        self::remove('admin_permissoes');
        self::remove('admin_nome');
        self::remove('admin_email');
        self::remove('admin_login_em');
        session_regenerate_id(true);
    }

    // ── Carrinho ──────────────────────────────────────────────

    public static function getCarrinhoId(): ?int {
        return self::get('carrinho_id');
    }

    public static function setCarrinhoId(int $id): void {
        self::set('carrinho_id', $id);
    }

    public static function setCarrinhoCount(int $count): void {
        self::set('carrinho_count', $count);
    }

    public static function getCarrinhoCount(): int {
        return (int) self::get('carrinho_count', 0);
    }
}