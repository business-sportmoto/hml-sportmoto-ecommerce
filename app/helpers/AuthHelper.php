<?php
// app/helpers/AuthHelper.php
// Centraliza verificações de autenticação para proteger rotas.

class AuthHelper {

    /**
     * Exige que o cliente esteja logado.
     * Se não estiver, salva a URL de destino e redireciona para o login.
     */
    public static function requireCustomer(): void {
        // Logout remoto: se a sessão persistente foi revogada no painel
        // "sessões ativas", esta chamada encerra a sessão PHP na hora.
        TokenService::validateActiveSession();

        if (!Session::isClienteLogado()) {
            // Salva para onde o cliente queria ir
            Session::flash('redirect_after_login', $_SERVER['REQUEST_URI']);
            Session::flash('info', 'Faça login para continuar.');
            header('Location: ' . BASE_URL . '/login');
            exit;
        }
    }

    /**
     * Exige que o admin esteja logado.
     * Redireciona para o login do admin.
     */
    public static function requireAdmin(): void {
        if (!Session::isAdminLogado()) {
            Session::flash('error', 'Acesso restrito. Faça login como administrador.');
            header('Location: ' . ADMIN_URL . '/login');
            exit;
        }
    }

    /**
     * Exige um nível específico de admin.
     */
    public static function requireAdminLevel(string ...$levels): void {
        self::requireAdmin();
        $nivel = Session::get('admin_nivel');
        if (!in_array($nivel, $levels, true) && $nivel !== 'super') {
            http_response_code(403);
            View::render('errors/403', [], 'minimal');
            exit;
        }
    }

    /**
     * Exige uma permissão específica do admin.
     */
    public static function requirePermission(string $permissao): void {
        self::requireAdmin();
        if (!Session::adminTemPermissao($permissao)) {
            http_response_code(403);
            View::render('errors/403', [], 'minimal');
            exit;
        }
    }

    /**
     * Verifica se a requisição atual é Ajax (XMLHttpRequest).
     */
    public static function isAjax(): bool {
        return (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    }

    /**
     * Verifica se a requisição é POST.
     */
    public static function isPost(): bool {
        return $_SERVER['REQUEST_METHOD'] === 'POST';
    }

    /**
     * Retorna a URL para redirecionar após login.
     */
    public static function getRedirectAfterLogin(): string {
        $url = Session::get('after_login_url');
    
        // One-shot: consome as chaves independente de serem válidas
        Session::remove('after_login_url');
        Session::remove('after_login_origem');
    
        if (
            is_string($url)
            && $url !== ''
            && str_starts_with($url, '/')
            && !str_starts_with($url, '//')
            && !preg_match('#^/[^/]*:#', $url)   // bloqueia /javascript:..., /data:...
        ) {
            return BASE_URL . $url;
        }
    
        return BASE_URL . '/minha-conta';
    }
}