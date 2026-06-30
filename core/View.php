<?php
// core/View.php
// Renderiza views PHP dentro de layouts, injetando dados como variáveis.
// Suporta partial includes e composição de layouts.

class View {

    private static array $sections = [];
    private static ?string $currentSection = null;
    private static array $globalData = [];

    private static string $basePath = '';
    private static string $assetPath = '';

    /**
     * Renderiza uma view dentro de um layout.
     *
     * @param string $view   Caminho relativo da view (ex: 'products/detail')
     * @param array  $data   Dados disponíveis na view como variáveis
     * @param string $layout Nome do layout (ex: 'main', 'customer', 'minimal')
     */
    public static function render(string $view, array $data = [], string $layout = 'main'): void {
        $data    = array_merge(self::$globalData, $data);
        $content = self::capture(self::resolvePath($view), $data);  // ← resolvePath

        // Layout também do admin se basePath estiver definido
        $layoutDir  = self::$basePath ?: VIEW_PATH;
        $layoutFile = $layoutDir . '/layouts/' . $layout . '.php';

        if (!file_exists($layoutFile)) {
            throw new RuntimeException("Layout '{$layout}' não encontrado em {$layoutFile}.");
        }

        extract(array_merge($data, ['content' => $content]), EXTR_SKIP);
        include $layoutFile;
    }

    /**
     * Renderiza somente a view, sem layout.
     * Útil para partials carregados via Ajax.
     */
    public static function renderPartial(string $view, array $data = []): void {
        $data = array_merge(self::$globalData, $data);
        extract($data, EXTR_SKIP);
        include VIEW_PATH . '/' . str_replace('.', '/', $view) . '.php';
    }

    /**
     * Inclui um partial (fragmento reutilizável).
     * Deve ser chamado dentro de uma view ou layout.
     *
     * @param string $partial Ex: 'partials/product-card'
     * @param array  $data    Dados extras para o partial
     */
    public static function partial(string $partial, array $data = []): void {
        // Tenta no basePath atual, fallback para VIEW_PATH principal
        $file = (self::$basePath ? self::$basePath : VIEW_PATH)
                . '/' . str_replace('.', '/', $partial) . '.php';

        // Se não achar no admin, tenta no views principal (para partials compartilhados)
        if (!file_exists($file)) {
            $file = VIEW_PATH . '/' . str_replace('.', '/', $partial) . '.php';
        }

        if (file_exists($file)) {
            extract(array_merge(self::$globalData, $data), EXTR_SKIP);
            include $file;
        }
    }

    /**
     * Captura a saída de um arquivo PHP em uma string.
     */
    public static function capture(string $file, array $data = []): string {
        if (!file_exists($file)) {
            throw new RuntimeException("View não encontrada: {$file}");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        include $file;
        return ob_get_clean();
    }

    /**
     * Define dados globais acessíveis em todas as views.
     * Usado para injetar configurações da loja, dados do usuário logado, etc.
     */
    public static function share(string $key, mixed $value): void {
        self::$globalData[$key] = $value;
    }

    public static function shareMany(array $data): void {
        self::$globalData = array_merge(self::$globalData, $data);
    }

    // ── Método que estava faltando ────────────────────────────
    public static function getShared(string $key, mixed $default = null): mixed {
        return self::$shared[$key] ?? $default;
    }

    /**
     * Escapa string para exibição segura em HTML (previne XSS).
     * Usar em todas as views: <?= View::e($variavel) ?>
     */
    public static function e(mixed $value): string {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Gera URL completa a partir de um caminho.
     */
    public static function url(string $path = ''): string {
        return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
    }

    /**
     * Gera URL de asset (CSS, JS, imagens).
     */
    // public static function asset(string $path): string {
    //     return rtrim(ASSET_URL, '/') . '/' . ltrim($path, '/');
    // }
    public static function setAssetPath(string $path): void {
        self::$assetPath = $path;
    }

    public static function asset(string $path): string {
        $base = self::$assetPath ?: (defined('ASSET_URL') ? ASSET_URL : BASE_URL . '/assets');
        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }

    /**
     * Gera URL de upload (imagens de produtos, banners, etc.).
     */
    public static function upload(string $path): string {
        if (empty($path)) {
            return self::asset('images/placeholder.jpg');
        }
        return rtrim(UPLOAD_URL, '/') . '/' . ltrim($path, '/');
    }

    public static function setBasePath(string $path): void {
        self::$basePath = $path;
    }

    private static function resolvePath(string $view): string {
        $base = self::$basePath ?: VIEW_PATH;
        return $base . '/' . str_replace('.', '/', $view) . '.php';
    }

    
}