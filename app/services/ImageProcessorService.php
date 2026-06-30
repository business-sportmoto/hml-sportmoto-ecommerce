<?php
declare(strict_types=1);

/**
 * Pipeline de upload de imagem com sanitização completa.
 *
 * Proteções implementadas:
 * - Validação de MIME real (não confia em extensão)
 * - Re-encoding via GD (anula payload malicioso em metadados)
 * - Remove EXIF (GPS, câmera, etc) — LGPD
 * - Gera 3 tamanhos pra economizar banda
 * - Converte tudo pra WebP
 */
class ImageProcessorService {

    private const MIMES_PERMITIDOS = ['image/jpeg', 'image/png', 'image/webp', 'image/heic'];
    private const MAX_BYTES        = 8 * 1024 * 1024;
    private const MIN_LARGURA      = 320;
    private const MIN_ALTURA       = 240;

    public const SIZE_THUMB  = 300;
    public const SIZE_MEDIUM = 800;
    public const SIZE_FULL   = 1600;
    public const QUALITY     = 82;

    /**
     * Processa um upload e retorna os 3 arquivos gerados.
     *
     * @return array{thumb:string, medium:string, full:string, largura:int, altura:int, bytes:int}
     * @throws RuntimeException
     */
    public function processar(array $file, string $subdir): array {
        $this->validarUpload($file);
        $caminhoTmp = $file['tmp_name'];

        $info = @getimagesize($caminhoTmp);
        if (!$info) {
            throw new \RuntimeException('Arquivo não é uma imagem válida.');
        }

        if (!in_array($info['mime'], self::MIMES_PERMITIDOS, true)) {
            throw new \RuntimeException('Formato não permitido.');
        }

        [$larguraOrig, $alturaOrig] = $info;

        if ($larguraOrig < self::MIN_LARGURA || $alturaOrig < self::MIN_ALTURA) {
            throw new \RuntimeException(
                "Imagem muito pequena. Mínimo " . self::MIN_LARGURA . "×" . self::MIN_ALTURA . "px."
            );
        }

        // Carrega imagem (GD anula qualquer payload escondido)
        $img = $this->carregarComGd($caminhoTmp, $info['mime']);

        // Corrige orientação EXIF antes de descartar metadados
        if ($info['mime'] === 'image/jpeg' && function_exists('exif_read_data')) {
            $img = $this->corrigirOrientacao($img, $caminhoTmp);
        }

        // Gera diretório
        $dir = UPLOAD_PATH . '/' . trim($subdir, '/') . '/';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $hash = bin2hex(random_bytes(8));
        $arquivos = [];
        $tamanhos = [
            'thumb'  => self::SIZE_THUMB,
            'medium' => self::SIZE_MEDIUM,
            'full'   => self::SIZE_FULL,
        ];

        $totalBytes = 0;

        foreach ($tamanhos as $label => $maxLado) {
            $resized = $this->redimensionar($img, $larguraOrig, $alturaOrig, $maxLado);
            $arquivo = "{$hash}_{$label}.webp";

            if (!imagewebp($resized, $dir . $arquivo, self::QUALITY)) {
                imagedestroy($resized);
                $this->limparArquivos($dir, $arquivos);
                throw new \RuntimeException('Falha ao salvar imagem.');
            }

            $totalBytes += filesize($dir . $arquivo);
            $arquivos[$label] = $arquivo;
            imagedestroy($resized);
        }

        imagedestroy($img);

        return [
            'thumb'   => $arquivos['thumb'],
            'medium'  => $arquivos['medium'],
            'full'    => $arquivos['full'],
            'largura' => $larguraOrig,
            'altura'  => $alturaOrig,
            'bytes'   => $totalBytes,
        ];
    }

    public function deletar(string $subdir, array $nomes): void {
        $dir = UPLOAD_PATH . '/' . trim($subdir, '/') . '/';
        foreach ($nomes as $nome) {
            if ($nome && file_exists($dir . $nome)) {
                @unlink($dir . $nome);
            }
        }
    }

    // ── Helpers privados ──────────────────────────────────
    private function validarUpload(array $file): void {
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Erro no upload do arquivo.');
        }
        if ($file['size'] <= 0 || $file['size'] > self::MAX_BYTES) {
            throw new \RuntimeException('Arquivo excede o tamanho máximo de 8MB.');
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            throw new \RuntimeException('Upload inválido.');
        }
    }

    private function carregarComGd(string $caminho, string $mime) {
        $img = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($caminho),
            'image/png'  => @imagecreatefrompng($caminho),
            'image/webp' => @imagecreatefromwebp($caminho),
            default      => false,
        };
        if (!$img) {
            throw new \RuntimeException('Falha ao processar imagem.');
        }
        return $img;
    }

    private function corrigirOrientacao($img, string $caminho) {
        $exif = @exif_read_data($caminho);
        if (!$exif || empty($exif['Orientation'])) return $img;

        return match ((int)$exif['Orientation']) {
            3       => imagerotate($img, 180, 0),
            6       => imagerotate($img, -90, 0),
            8       => imagerotate($img,  90, 0),
            default => $img,
        };
    }

    private function redimensionar($src, int $larguraOrig, int $alturaOrig, int $maxLado) {
        if ($larguraOrig <= $maxLado && $alturaOrig <= $maxLado) {
            // Mesmo no tamanho original, recria pra anular EXIF
            $dst = imagecreatetruecolor($larguraOrig, $alturaOrig);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagecopy($dst, $src, 0, 0, 0, 0, $larguraOrig, $alturaOrig);
            return $dst;
        }

        $razao = $larguraOrig / $alturaOrig;
        if ($larguraOrig > $alturaOrig) {
            $novaLargura = $maxLado;
            $novaAltura  = (int)round($maxLado / $razao);
        } else {
            $novaAltura  = $maxLado;
            $novaLargura = (int)round($maxLado * $razao);
        }

        $dst = imagecreatetruecolor($novaLargura, $novaAltura);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled(
            $dst, $src,
            0, 0, 0, 0,
            $novaLargura, $novaAltura,
            $larguraOrig, $alturaOrig
        );
        return $dst;
    }

    private function limparArquivos(string $dir, array $arquivos): void {
        foreach ($arquivos as $a) {
            if (file_exists($dir . $a)) @unlink($dir . $a);
        }
    }
}