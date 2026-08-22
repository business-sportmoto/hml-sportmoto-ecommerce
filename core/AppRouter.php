<?php
// core/AppRouter.php
// Router exclusivo da API do app mobile (/api/app/v1).
//
// Existe como classe separada de core/Router.php de propósito: a loja web não
// pode sofrer nenhum risco de regressão por causa do app. As diferenças:
//   - suporta PUT, PATCH, DELETE e OPTIONS (o Router web só tem GET/POST/ANY)
//   - 404 e 405 respondem JSON, nunca HTML
//   - carrega só config/routes.app.php, então o catch-all /{slug} da loja
//     (última linha de config/routes.php) nunca interfere
//
// patternToRegex() e getUri() são cópias fiéis do Router web — mesmo
// comportamento de {slug} e {id:\d+}, mesma normalização de URI.

class AppRouter
{
    private static array $routes = [];

    public static function add(string $method, string $pattern, string $handler): void
    {
        self::$routes[] = [
            'method'  => strtoupper($method),
            'pattern' => $pattern,
            'handler' => $handler,
        ];
    }

    public static function get(string $p, string $h): void    { self::add('GET', $p, $h); }
    public static function post(string $p, string $h): void   { self::add('POST', $p, $h); }
    public static function put(string $p, string $h): void    { self::add('PUT', $p, $h); }
    public static function patch(string $p, string $h): void  { self::add('PATCH', $p, $h); }
    public static function delete(string $p, string $h): void { self::add('DELETE', $p, $h); }
    public static function any(string $p, string $h): void    { self::add('ANY', $p, $h); }

    /**
     * Casa a requisição atual com uma rota e despacha.
     * Se o path casa mas o método não, responde 405 com header Allow — útil
     * para depurar o app sem confundir com rota inexistente.
     */
    public static function dispatch(): void
    {
        require_once ROOT_PATH . '/config/routes.app.php';

        $method = self::metodo();
        $uri    = self::getUri();

        $permitidos = [];

        foreach (self::$routes as $route) {
            $regex = self::patternToRegex($route['pattern']);
            if (!preg_match($regex, $uri, $matches)) {
                continue;
            }

            if ($route['method'] === 'ANY' || $route['method'] === $method) {
                array_shift($matches);
                self::callHandler($route['handler'], $matches);
                return;
            }

            $permitidos[] = $route['method'];
        }

        if ($permitidos) {
            self::metodoNaoPermitido(array_unique($permitidos));
        }

        self::notFound();
    }

    /**
     * Método HTTP efetivo. Aceita X-HTTP-Method-Override em POST como fallback
     * para proxies/WAFs que bloqueiam PUT/PATCH/DELETE.
     */
    private static function metodo(): string
    {
        $m = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($m === 'POST' && !empty($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'])) {
            $override = strtoupper(trim((string)$_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']));
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                return $override;
            }
        }
        return $m;
    }

    /** Cópia fiel de Router::getUri(). */
    private static function getUri(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        if ($basePath && strpos($uri, $basePath) === 0) {
            $uri = substr($uri, strlen($basePath));
        }

        $uri = '/' . ltrim($uri, '/');

        if ($uri !== '/') {
            $uri = rtrim($uri, '/');
        }

        return $uri;
    }

    /** Cópia fiel de Router::patternToRegex(). {slug} → ([^/]+), {id:\d+} → (\d+) */
    private static function patternToRegex(string $pattern): string
    {
        $pattern = preg_replace('/\{([a-z_]+):([^}]+)\}/', '($2)', $pattern);
        $pattern = preg_replace('/\{([a-z_]+)\}/', '([^/]+)', $pattern);
        $pattern = str_replace('/', '\/', $pattern);
        return '#^' . $pattern . '$#i';
    }

    private static function callHandler(string $handler, array $params = []): void
    {
        [$controllerClass, $method] = explode('@', $handler);

        if (!class_exists($controllerClass)) {
            self::erro(500, 'controller_ausente', "Controller '{$controllerClass}' não encontrado.");
        }

        $controller = new $controllerClass();

        if (!method_exists($controller, $method)) {
            self::erro(500, 'metodo_ausente', "Método '{$method}' não existe em '{$controllerClass}'.");
        }

        call_user_func_array([$controller, $method], $params);
    }

    private static function metodoNaoPermitido(array $permitidos): void
    {
        header('Allow: ' . implode(', ', $permitidos));
        self::erro(405, 'metodo_nao_permitido', 'Método HTTP não aceito nesta rota.', [
            'permitidos' => array_values($permitidos),
        ]);
    }

    private static function notFound(): void
    {
        self::erro(404, 'nao_encontrado', 'Rota não encontrada.');
    }

    /** Erro em JSON, no mesmo envelope de ApiKeyService::envelope(). */
    private static function erro(int $status, string $codigo, string $mensagem, array $meta = []): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            ApiKeyService::envelope(false, null, ['codigo' => $codigo, 'mensagem' => $mensagem], $meta),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }
}
