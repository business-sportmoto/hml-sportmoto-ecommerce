<?php
declare(strict_types=1);


/**
 * app/services/ImageProcessor.php
 *
 * Validacao e processamento de imagens de produto usando GD.
 *
 * SEGURANCA (ver "Analise de Seguranca"):
 *  - Valida o tipo REAL por magic bytes (getimagesize/finfo), NUNCA por
 *    extensao do nome (um .jpg pode ser PHP disfarçado).
 *  - Rejeita qualquer coisa que nao seja imagem raster suportada.
 *  - Limita dimensoes e tamanho (anti-DoS de memoria/custo).
 *  - Reprocessa a imagem (decode -> resize -> re-encode), o que DESTROI
 *    qualquer payload embutido (polyglot, EXIF malicioso, PHP em metadados).
 *  - Converte para WebP (peso menor, suporte universal atual).
 *
 * @see OWASP A03 (Injection via upload), A05, "Unrestricted File Upload"
 */
final class ImageProcessor
{
    /** MIMEs de entrada aceitos (validados por conteudo, nao extensao). */
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];

    /** Tamanho maximo do arquivo de entrada (bytes). 10 MB. */
    private const MAX_BYTES = 10 * 1024 * 1024;

    /** Dimensao maxima de entrada (px) — evita "decompression bomb". */
    private const MAX_INPUT_DIMENSION = 8000;

    /** Qualidade WebP de saida (0-100). */
    private const WEBP_QUALITY = 82;

    /**
     * Variantes geradas: nome => largura maxima (px). Altura proporcional.
     * @var array<string,int>
     */
    private const VARIANTS = [
        'full'  => 1200,
        'thumb' => 300,
    ];

    /**
     * Valida um upload e retorna o caminho temporario validado.
     *
     * @param array{tmp_name:string,size:int,error:int} $file Item de $_FILES.
     * @throws RuntimeException se invalido.
     */
    public function validateUpload(array $file): void
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Falha no upload (codigo ' . ($file['error'] ?? '?') . ').');
        }
        if (($file['size'] ?? 0) <= 0 || $file['size'] > self::MAX_BYTES) {
            throw new RuntimeException('Arquivo vazio ou maior que o limite de 10 MB.');
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('Origem de upload invalida.');
        }

        // Tipo REAL por conteudo (magic bytes), nao pela extensao.
        $info = @getimagesize($file['tmp_name']);
        if ($info === false || !in_array($info['mime'] ?? '', self::ALLOWED_MIME, true)) {
            throw new RuntimeException('Arquivo nao e uma imagem valida (JPEG/PNG/WebP).');
        }
        if ($info[0] > self::MAX_INPUT_DIMENSION || $info[1] > self::MAX_INPUT_DIMENSION) {
            throw new RuntimeException('Dimensoes excedem o maximo permitido.');
        }
    }

    /**
     * Processa a imagem validada em variantes WebP.
     *
     * @param string $tmpPath Caminho temporario (ja validado).
     * @param array<string,int>|null $variants Presets customizados
     *        (nome => largura maxima). Se null, usa self::VARIANTS (produto).
     *        Para BANNER, passe ['desktop'=>1920, 'mobile'=>768].
     * @return array<string,string> variante => bytes WebP.
     * @throws RuntimeException
     */
    public function toWebpVariants(string $tmpPath, ?array $variants = null): array
    {
        $variants = $variants ?? self::VARIANTS;
        $src = $this->loadImage($tmpPath);
        try {
            $out = [];
            foreach ($variants as $name => $maxW) {
                $resized = $this->resizeToWidth($src, $maxW);
                try {
                    $out[$name] = $this->encodeWebp($resized);
                } finally {
                    if ($resized !== $src) {
                        imagedestroy($resized);
                    }
                }
            }
            return $out;
        } finally {
            imagedestroy($src);
        }
    }

    /** Carrega a imagem em um recurso GD a partir do tipo real detectado. */
    private function loadImage(string $path)
    {
        $info = getimagesize($path);
        $img = match ($info['mime']) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png'  => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            default      => false,
        };
        if ($img === false) {
            throw new RuntimeException('Nao foi possivel decodificar a imagem.');
        }
        return $img;
    }

    /** Redimensiona para uma largura maxima, mantendo proporcao. */
    private function resizeToWidth($src, int $maxWidth)
    {
        $w = imagesx($src);
        $h = imagesy($src);

        if ($w <= $maxWidth) {
            return $src; // nao amplia; usa o original
        }

        $newW = $maxWidth;
        $newH = (int) round($h * ($maxWidth / $w));

        $dst = imagecreatetruecolor($newW, $newH);
        // Preserva transparencia (PNG/WebP)
        imagealphablending($dst, false);
        imagesavealpha($dst, true);

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
        return $dst;
    }

    /** Codifica um recurso GD como WebP e retorna os bytes. */
    private function encodeWebp($img): string
    {
        ob_start();
        $ok = imagewebp($img, null, self::WEBP_QUALITY);
        $bytes = ob_get_clean();
        if (!$ok || $bytes === false || $bytes === '') {
            throw new RuntimeException('Falha ao codificar WebP.');
        }
        return $bytes;
    }
}