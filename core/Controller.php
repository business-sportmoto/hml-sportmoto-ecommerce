<?php
// core/Controller.php

abstract class Controller {
    protected array $data = []; // dados passados para a view

    // Renderiza uma view dentro de um layout
    protected function render(string $view, array $data = [],
                               string $layout = 'main'): void {
        $this->data = array_merge($this->data, $data);
        View::render($view, $this->data, $layout);
    }

    // Resposta JSON para requisições Ajax
    protected function json(array $payload, int $status = 200): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Redireciona para uma URL
    protected function redirect(string $url, bool $permanent = false): void {
        header('Location: ' . $url, true, $permanent ? 301 : 302);
        exit;
    }

    // Verifica token CSRF e aborta se inválido
    protected function verifyCsrf(): void {
        $token = $_POST[CSRF_TOKEN_NAME] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!SecurityHelper::validateCsrf($token)) {
            $this->json(['error' => 'Token inválido.'], 403);
        }
    }
    // ════════════════════════════════════════════════════════════════════
    // PATCH 2 — Controller (classe base em core/)
    //
    // Adicione este método à classe Controller base. Qualquer controller
    // poderá chamar $this->redirecionarParaLogin('checkout') quando o
    // usuário não estiver logado.
    // ════════════════════════════════════════════════════════════════════
    
    /**
     * Redireciona para o login guardando na sessão de onde o usuário veio
     * e para onde deve voltar após autenticar.
     *
     * @param string $origem     Identificador do contexto ('checkout',
     *                           'favoritos', 'minha-conta'...). A view de
     *                           login usa isso pra personalizar mensagens.
     * @param string $returnUrl  Path relativo para retornar pós-login.
     *                           Se vazio, usa a URL atual da request.
     *
     * Funciona tanto em requests normais (302) quanto AJAX (JSON com
     * flag `login: true` para o front redirecionar).
     */
    protected function redirecionarParaLogin(string $origem = '', string $returnUrl = ''): void {
        // Default: volta para a URL atual
        if ($returnUrl === '') {
            $returnUrl = $_SERVER['REQUEST_URI'] ?? '/';
            // Remove o BASE_URL path se o site roda em subdiretório
            $basePath = parse_url(BASE_URL, PHP_URL_PATH) ?: '';
            if ($basePath && $basePath !== '/' && str_starts_with($returnUrl, $basePath)) {
                $returnUrl = substr($returnUrl, strlen($basePath)) ?: '/';
            }
        }
    
        // Só aceita paths relativos seguros
        if (!str_starts_with($returnUrl, '/') || str_starts_with($returnUrl, '//')) {
            $returnUrl = '/';
        }
    
        Session::set('after_login_url', $returnUrl);
        if ($origem !== '') {
            Session::set('after_login_origem', $origem);
        }
    
        // AJAX → JSON com flag de login (o JS do front decide redirecionar)
        if (class_exists('AuthHelper') && AuthHelper::isAjax()) {
            $this->json([
                'ok'       => false,
                'login'    => true,
                'redirect' => BASE_URL . '/login',
                'msg'      => 'Faça login para continuar.',
            ]);
        }
    
        header('Location: ' . BASE_URL . '/login');
        exit;
    }
}