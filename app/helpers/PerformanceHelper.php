<?php
// app/helpers/PerformanceHelper.php
// Utilitários de performance: lazy loading, critical CSS inline,
// preload de assets e compressão de output.

class PerformanceHelper {

    /**
     * Ativa compressão gzip do output se disponível.
     * Chamar no início do bootstrap.
     */
    public static function enableGzip(): void {
        if (!ob_get_level() && extension_loaded('zlib') && !ini_get('zlib.output_compression')) {
            ob_start('ob_gzhandler');
        }
    }

    /**
     * Gera atributos de imagem otimizados.
     * Adiciona loading="lazy", decoding="async" e dimensões.
     */
    public static function imgAttrs(string $src, string $alt, int $w = 0, int $h = 0,
                                     bool $lazy = true, bool $fetchPriority = false): string {
        $attrs  = 'src="' . View::e($src) . '"';
        $attrs .= ' alt="' . View::e($alt) . '"';
        if ($w)            $attrs .= " width=\"{$w}\"";
        if ($h)            $attrs .= " height=\"{$h}\"";
        if ($lazy)         $attrs .= ' loading="lazy" decoding="async"';
        if ($fetchPriority) $attrs .= ' fetchpriority="high"';
        return $attrs;
    }

    /**
     * Gera tag <link rel="preload"> para assets críticos.
     */
    public static function preload(string $href, string $as, string $type = ''): string {
        $type = $type ? " type=\"{$type}\"" : '';
        return "<link rel=\"preload\" href=\"{$href}\" as=\"{$as}\"{$type}>\n";
    }

    /**
     * Gera srcset para imagens responsivas.
     * Assumindo que o UploadHelper gerou thumbs em tamanhos padrão.
     */
    public static function srcset(string $filename, string $folder = 'products'): string {
        $base = UPLOAD_URL . '/' . $folder . '/';
        // Em produção: gerar tamanhos via UploadHelper e ajustar aqui
        return "{$base}{$filename} 800w, {$base}{$filename} 400w";
    }

    // Substitui só o método assetVersion() e adiciona os três privados logo abaixo dele

    /**
     * Retorna URL com cache busting eficiente.
     * Desenvolvimento: md5_file() em tempo real (reflete mudanças imediatamente).
     * Produção: lê manifest pré-gerado no deploy (zero I/O adicional por request).
     * /usr/local/lsws/lsphp82/bin/php script/generate-asset-manifest.php
     */
    public static function assetVersion(string $path): string
    {
        $path    = '/' . ltrim($path, '/');
        $version = self::resolverHash($path);
        return ASSET_URL . $path . '?v=' . $version;
    }

    private static $assetManifest = null;

    private static function resolverHash(string $path): string
    {
        $env = defined('APP_ENV') ? APP_ENV : 'development';
        if ($env === 'production') {
            return self::carregarManifest()[$path] ?? '1';
        }
        $fullPath = ASSET_PATH . $path;
        return file_exists($fullPath) ? substr(md5_file($fullPath), 0, 8) : '1';
    }

    private static function carregarManifest(): array
    {
        if (self::$assetManifest !== null) return self::$assetManifest;
        $f = (defined('STORAGE_PATH') ? STORAGE_PATH : ROOT_PATH . '/storage') . '/asset-manifest.json';
        self::$assetManifest = file_exists($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
        return self::$assetManifest;
    }

    /**
     * Minifica HTML removendo espaços em branco desnecessários.
     * Usar com cuidado — não aplicar em blocos <pre> ou <textarea>.
     */
    public static function minifyHtml(string $html): string {
        // Remove comentários HTML (exceto IEs condicionais)
        $html = preg_replace('/<!--(?!\[if).*?-->/s', '', $html);
        // Remove espaço entre tags
        $html = preg_replace('/>\s+</', '><', $html);
        // Remove espaços múltiplos
        $html = preg_replace('/\s{2,}/', ' ', $html);
        return trim($html);
    }
}