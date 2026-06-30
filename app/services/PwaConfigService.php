<?php
declare(strict_types=1);

/**
 * app/services/PwaConfigService.php
 *
 * Gerencia a configuração do PWA: ícones, manifest.json e versão do sw.js.
 */
class PwaConfigService
{
    private PDO    $db;
    private string $publicDir;  // caminho absoluto para public/
    private string $iconsDir;   // public/icons/

    // Splash screens iOS — [filename, width, height]
    private const SPLASH_SIZES = [
        ['splash-640x1136.png',   640,  1136],
        ['splash-750x1334.png',   750,  1334],
        ['splash-1242x2208.png', 1242,  2208],
        ['splash-1125x2436.png', 1125,  2436],
        ['splash-828x1792.png',   828,  1792],
        ['splash-1242x2688.png', 1242,  2688],
        ['splash-1170x2532.png', 1170,  2532],
        ['splash-1284x2778.png', 1284,  2778],
        ['splash-1179x2556.png', 1179,  2556],
        ['splash-1290x2796.png', 1290,  2796],
    ];

    // Ícones do app — [filename, size, padding, maskable]
    private const ICON_SIZES = [
        ['icon-192.png',          192, 0.00, false],
        ['icon-512.png',          512, 0.00, false],
        ['icon-maskable-192.png', 192, 0.20, true ],
        ['icon-maskable-512.png', 512, 0.20, true ],
        ['apple-touch-icon.png',  180, 0.10, false],
        ['shortcut-pedidos.png',   96, 0.10, false],
        ['shortcut-carrinho.png',  96, 0.10, false],
        ['shortcut-garagem.png',   96, 0.10, false],
    ];

    public function __construct()
    {
        $this->db        = Database::getInstance()->getConnection();
        $this->publicDir = rtrim(ROOT_PATH, '/') . '';
        $this->iconsDir  = $this->publicDir . '/assets/images';
    }

    // ════════════════════════════════════════════════════
    // CONFIG
    // ════════════════════════════════════════════════════

    public function getConfig(): array
    {
        $stmt = $this->db->query("SELECT * FROM pwa_config WHERE id = 1 LIMIT 1");
        return $stmt->fetch() ?: [
            'app_name'        => 'Minha Loja',
            'app_short_name'  => 'Loja',
            'app_description' => '',
            'theme_color'     => '#0f172a',
            'background_color'=> '#0f172a',
            'icone_original'  => null,
            'icones_gerados'  => 0,
            'cache_version'   => 'v1.0.0',
        ];
    }

    public function salvarCampos(
        string $nome,
        string $nomeShort,
        string $descricao,
        string $themeColor,
        string $bgColor
    ): void {
        // Valida hexadecimal
        $themeColor = $this->validarHex($themeColor, '#0f172a');
        $bgColor    = $this->validarHex($bgColor,    '#0f172a');

        $this->db->prepare(
            "UPDATE pwa_config SET
               app_name         = ?,
               app_short_name   = ?,
               app_description  = ?,
               theme_color      = ?,
               background_color = ?
             WHERE id = 1"
        )->execute([
            mb_substr(strip_tags($nome),      0, 80),
            mb_substr(strip_tags($nomeShort), 0, 20),
            mb_substr(strip_tags($descricao), 0, 255),
            $themeColor,
            $bgColor,
        ]);
    }

    // ════════════════════════════════════════════════════
    // ÍCONES
    // ════════════════════════════════════════════════════

    public function gerarIcones(array $uploadFile): array
    {
        if (!extension_loaded('gd')) {
            throw new \RuntimeException('Extensão GD não está disponível.');
        }

        // Valida upload
        if (($uploadFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Erro no upload do arquivo.');
        }
        if ($uploadFile['size'] > 5 * 1024 * 1024) {
            throw new \RuntimeException('Arquivo deve ter no máximo 5 MB.');
        }

        // Valida MIME
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $uploadFile['tmp_name']);
        finfo_close($finfo);
        $suportados = ['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'];
        if (!in_array($mime, $suportados, true)) {
            throw new \RuntimeException('Formato não suportado. Use PNG, JPEG ou WEBP.');
        }

        // Carrega imagem
        $src = $this->carregarImagem($uploadFile['tmp_name'], $mime);
        if (!$src) {
            throw new \RuntimeException('Não foi possível processar a imagem.');
        }

        // Valida tamanho mínimo
        $srcW = imagesx($src);
        $srcH = imagesy($src);
        if ($srcW < 256 || $srcH < 256) {
            imagedestroy($src);
            throw new \RuntimeException("Imagem muito pequena ({$srcW}×{$srcH}). Use ao menos 256×256 px.");
        }

        // Garante diretório
        if (!is_dir($this->iconsDir)) {
            mkdir($this->iconsDir, 0755, true);
        }

        // Busca cor de fundo atual
        $config = $this->getConfig();
        [$r, $g, $b] = $this->hexToRgb($config['background_color']);

        // Salva o arquivo original
        $nomeOriginal = 'icon-source.' . ($mime === 'image/png' ? 'png' : 'jpg');
        move_uploaded_file($uploadFile['tmp_name'], $this->iconsDir . '/' . $nomeOriginal);

        // Gera cada tamanho
        foreach (self::ICON_SIZES as [$filename, $size, $padding]) {
            $this->renderIcone(
                $src, $srcW, $srcH,
                $size, $padding,
                $r, $g, $b,
                $this->iconsDir . '/' . $filename
            );
        }

        imagedestroy($src);

        // Marca como gerado no banco
        $this->db->prepare(
            "UPDATE pwa_config SET icone_original = ?, icones_gerados = 1 WHERE id = 1"
        )->execute([$nomeOriginal]);

        return array_map(fn($s) => $s[0], self::ICON_SIZES);
    }

    /**
     * Regenera ícones com uma nova cor de fundo (sem novo upload).
     */
    public function regenerarComNovaCor(string $bgColor): void
    {
        $config = $this->getConfig();
        if (empty($config['icone_original'])) {
            throw new \RuntimeException('Nenhum ícone fonte encontrado. Faça o upload primeiro.');
        }

        $srcPath = $this->iconsDir . '/' . $config['icone_original'];
        if (!file_exists($srcPath)) {
            throw new \RuntimeException('Arquivo fonte não encontrado em ' . $this->iconsDir . '/');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $srcPath);
        finfo_close($finfo);

        $src = $this->carregarImagem($srcPath, $mime);
        [$r, $g, $b] = $this->hexToRgb($bgColor);

        foreach (self::ICON_SIZES as [$filename, $size, $padding]) {
            $this->renderIcone(
                $src, imagesx($src), imagesy($src),
                $size, $padding,
                $r, $g, $b,
                $this->iconsDir . '/' . $filename
            );
        }

        imagedestroy($src);
    }

    // ════════════════════════════════════════════════════
    // MANIFEST
    // ════════════════════════════════════════════════════

    public function getManifest(): array
    {
        $c = $this->getConfig();

        return [
            'name'        => $c['app_name'],
            'short_name'  => $c['app_short_name'],
            'description' => $c['app_description'] ?: null,
            'id'          => '/?source=pwa',
            'start_url'   => '/?source=pwa',
            'scope'       => '/',
            'display'     => 'standalone',
            'display_override' => ['standalone', 'minimal-ui'],
            'orientation' => 'portrait',
            'background_color' => $c['background_color'],
            'theme_color'      => $c['theme_color'],
            'lang'        => 'pt-BR',
            'dir'         => 'ltr',
            'categories'  => ['shopping', 'lifestyle'],
            'icons'       => [
                ['src' => '/assets/images/icon-192.png',          'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/assets/images/icon-512.png',          'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/assets/images/icon-maskable-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
                ['src' => '/assets/images/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
            'shortcuts' => [
                [
                    'name'      => 'Meus pedidos',
                    'short_name'=> 'Pedidos',
                    'url'       => '/minha-conta/pedidos?source=pwa_shortcut',
                    'icons'     => [['src' => '/assets/images/shortcut-pedidos.png', 'sizes' => '96x96']],
                ],
                [
                    'name'      => 'Meu carrinho',
                    'short_name'=> 'Carrinho',
                    'url'       => '/carrinho?source=pwa_shortcut',
                    'icons'     => [['src' => '/assets/images/shortcut-carrinho.png', 'sizes' => '96x96']],
                ],
                [
                    'name'      => 'Minha garagem',
                    'short_name'=> 'Garagem',
                    'url'       => '/minha-conta/garagem?source=pwa_shortcut',
                    'icons'     => [['src' => '/assets/images/shortcut-garagem.png', 'sizes' => '96x96']],
                ],
            ],
            'prefer_related_applications' => false,
        ];
    }

    // ════════════════════════════════════════════════════
    // PUBLICAR — escreve manifest.json + bump sw.js
    // ════════════════════════════════════════════════════

    public function publicar(): string
    {
        // 1. Escreve manifest.json
        $manifest = $this->getManifest();
        $json     = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents($this->publicDir . '/manifest.json', $json);

        // 2. Gera splash screens iOS
        $this->gerarSplashScreens();

        // 3. Incrementa CACHE_VERSION no sw.js
        $novaVersao = $this->incrementarCacheVersion();

        // 4. Salva no banco
        $this->db->prepare(
            "UPDATE pwa_config SET cache_version = ? WHERE id = 1"
        )->execute([$novaVersao]);

        return $novaVersao;
    }

    /**
     * Gera todas as splash screens para iOS.
     * Fundo sólido (background_color) com o logo centralizado.
     * Chamado automaticamente por publicar().
     */
    public function gerarSplashScreens(): void
    {
        $config = $this->getConfig();
        if (empty($config['icone_original'])) return;

        $srcPath = $this->iconsDir . '/' . $config['icone_original'];
        if (!file_exists($srcPath)) return;

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $srcPath);
        finfo_close($finfo);

        $src = $this->carregarImagem($srcPath, $mime);
        if (!$src) return;

        [$r, $g, $b] = $this->hexToRgb($config['background_color']);
        $srcW = imagesx($src);
        $srcH = imagesy($src);

        foreach (self::SPLASH_SIZES as [$filename, $w, $h]) {
            $canvas = imagecreatetruecolor($w, $h);
            $fundo  = imagecolorallocate($canvas, $r, $g, $b);
            imagefill($canvas, 0, 0, $fundo);

            // Logo ocupa 25% da menor dimensão da tela
            $logoSize = (int)(min($w, $h) * 0.25);
            $escala   = min($logoSize / $srcW, $logoSize / $srcH);
            $novoW    = (int)($srcW * $escala);
            $novoH    = (int)($srcH * $escala);
            $x        = (int)(($w - $novoW) / 2);
            $y        = (int)(($h - $novoH) / 2) - (int)($h * 0.05); // levemente acima do centro

            imagecopyresampled($canvas, $src, $x, $y, 0, 0, $novoW, $novoH, $srcW, $srcH);
            imagepng($canvas, $this->iconsDir . '/' . $filename, 9);
            imagedestroy($canvas);
        }

        imagedestroy($src);
    }

    // ════════════════════════════════════════════════════
    // PRIVADOS
    // ════════════════════════════════════════════════════

    private function incrementarCacheVersion(): string
    {
        $swPath = $this->publicDir . '/sw.js';
        if (!file_exists($swPath)) { 
            LogService::info('incrementarCacheVersion');
            return 'v1.0.0';
        }

        LogService::info('incrementarCacheVersion', [$swPath]);

        $content = file_get_contents($swPath);

        // Encontra: const CACHE_VERSION = 'vX.Y.Z';
        if (preg_match("/const CACHE_VERSION = '(v(\d+)\.(\d+)\.(\d+))'/", $content, $m)) {
            
            $nova    = "v{$m[2]}.{$m[3]}." . ((int)$m[4] + 1);
            LogService::info('incrementarCacheVersion version', [$swPath, $nova, $m[1]]);
            
            $content = str_replace(
                "const CACHE_VERSION = '{$m[1]}'",
                "const CACHE_VERSION = '{$nova}'",
                $content
            );
            file_put_contents($swPath, $content);
            return $nova;
        }

        return 'v1.0.0';
    }

    private function renderIcone(
        \GdImage $src,
        int $srcW, int $srcH,
        int $size, float $padding,
        int $r, int $g, int $b,
        string $destino
    ): void {
        $canvas = imagecreatetruecolor($size, $size);
        $fundo  = imagecolorallocate($canvas, $r, $g, $b);
        imagefill($canvas, 0, 0, $fundo);

        $area  = (int)($size * (1 - 2 * $padding));
        $off   = (int)($size * $padding);
        $escala= min($area / $srcW, $area / $srcH);
        $novoW = (int)($srcW * $escala);
        $novoH = (int)($srcH * $escala);
        $x     = $off + (int)(($area - $novoW) / 2);
        $y     = $off + (int)(($area - $novoH) / 2);

        imagecopyresampled($canvas, $src, $x, $y, 0, 0, $novoW, $novoH, $srcW, $srcH);
        imagepng($canvas, $destino, 9);
        imagedestroy($canvas);
    }

    private function carregarImagem(string $path, string $mime): \GdImage|false
    {
        return match ($mime) {
            'image/png'  => imagecreatefrompng($path),
            'image/webp' => imagecreatefromwebp($path),
            default      => imagecreatefromjpeg($path),
        };
    }

    private function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    private function validarHex(string $cor, string $fallback): string
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $cor) ? strtolower($cor) : $fallback;
    }
}