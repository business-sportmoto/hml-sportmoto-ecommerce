<?php
// core/Router.php
// Mapeia URLs amigáveis para controllers e métodos.
// Suporta parâmetros dinâmicos na URL (ex: /produto/{slug}).

class Router {

    private static array $routes = [];

    /**
     * Registra uma rota.
     *
     * @param string $method  HTTP method: GET, POST, ou ANY
     * @param string $pattern URL pattern com parâmetros em {chaves}
     * @param string $handler "ControllerClass@metodo"
     */
    public static function add(string $method, string $pattern, string $handler): void {
        self::$routes[] = [
            'method'  => strtoupper($method),
            'pattern' => $pattern,
            'handler' => $handler,
        ];
    }

    public static function get(string $pattern, string $handler): void {
        self::add('GET', $pattern, $handler);
    }

    public static function post(string $pattern, string $handler): void {
        self::add('POST', $pattern, $handler);
    }

    public static function any(string $pattern, string $handler): void {
        self::add('ANY', $pattern, $handler);
    }

    /**
     * Processa a requisição atual e despacha para o controller correto.
     */
    public static function dispatch(): void {
        // Carrega as definições de rotas
        require_once ROOT_PATH . '/config/routes.php';

        $method = strtoupper($_SERVER['REQUEST_METHOD']);
        $uri    = self::getUri();

        foreach (self::$routes as $route) {
            // Verifica método HTTP
            if ($route['method'] !== 'ANY' && $route['method'] !== $method) {
                continue;
            }

            // Converte o pattern em regex
            // Ex: /produto/{slug} → #^/produto/([^/]+)$#
            $regex = self::patternToRegex($route['pattern']);

            if (preg_match($regex, $uri, $matches)) {
                array_shift($matches); // remove o match completo
                self::callHandler($route['handler'], $matches);
                return;
            }
        }

        // Nenhuma rota encontrada → 404
        self::notFound();
    }

    /**
     * Obtém a URI limpa da requisição atual.
     */
    private static function getUri(): string {
        // Pega a URL sem query string
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        // Remove o base path se a aplicação não está na raiz
        $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        if ($basePath && strpos($uri, $basePath) === 0) {
            $uri = substr($uri, strlen($basePath));
        }

        // Garante que começa com /
        $uri = '/' . ltrim($uri, '/');

        // Remove trailing slash (exceto raiz)
        if ($uri !== '/') {
            $uri = rtrim($uri, '/');
        }

        return $uri;
    }

    /**
     * Converte pattern de rota em regex.
     * {slug}  → captura qualquer coisa exceto /
     * {id:\d+} → captura somente números
     */
    private static function patternToRegex(string $pattern): string {
        $pattern = preg_replace('/\{([a-z_]+):([^}]+)\}/', '($2)', $pattern);
        $pattern = preg_replace('/\{([a-z_]+)\}/', '([^/]+)', $pattern);
        $pattern = str_replace('/', '\/', $pattern);
        return '#^' . $pattern . '$#i';
    }

    /**
     * Instancia o controller e chama o método com os parâmetros.
     */
    private static function callHandler(string $handler, array $params = []): void {
        [$controllerClass, $method] = explode('@', $handler);

        if (!class_exists($controllerClass)) {
            throw new RuntimeException("Controller '{$controllerClass}' não encontrado.");
        }

        $controller = new $controllerClass();

        if (!method_exists($controller, $method)) {
            throw new RuntimeException("Método '{$method}' não existe em '{$controllerClass}'.");
        }

        call_user_func_array([$controller, $method], $params);
    }

    /**
     * Resposta 404 padrão.
     */
    private static function notFound(): void {
        http_response_code(404);
        // Tenta renderizar uma view 404 customizada
        $view404 = VIEW_PATH . '/errors/404.php';
        if (file_exists($view404)) {
            include $view404;
        } else {
            echo '<h1>404 — Página não encontrada</h1>';
        }
        exit;
    }
}