<?php
// app/helpers/UploadHelper.php
// Gerencia upload, resize e exclusão de imagens.

class UploadHelper {

    /**
     * Salva e redimensiona uma imagem enviada via formulário.
     * Retorna o nome do arquivo gerado ou null em caso de erro.
     */
    public function saveImage(array $file, string $pasta, int $w, int $h): ?string {
        $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $nome    = bin2hex(random_bytes(10)) . '.' . $ext;
        $destDir = UPLOAD_PATH . '/' . $pasta;
        $dest    = $destDir . '/' . $nome;

        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            return null;
        }

        // Redimensiona se as funções GD estiverem disponíveis
        if (extension_loaded('gd')) {
            $this->resize($dest, $w, $h, $ext);
        }

        return $nome;
    }

    private function resize(string $path, int $maxW, int $maxH, string $ext): void {
        $src = match($ext) {
            'jpg', 'jpeg' => @imagecreatefromjpeg($path),
            'png'         => @imagecreatefrompng($path),
            'webp'        => @imagecreatefromwebp($path),
            default       => null,
        };

        if (!$src) return;

        [$ow, $oh] = getimagesize($path);
        $ratio = min($maxW / $ow, $maxH / $oh, 1);
        $nw    = (int)round($ow * $ratio);
        $nh    = (int)round($oh * $ratio);

        $dst = imagecreatetruecolor($nw, $nh);

        // Preserva transparência para PNG/WEBP
        if (in_array($ext, ['png', 'webp'])) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $ow, $oh);

        match($ext) {
            'jpg', 'jpeg' => imagejpeg($dst, $path, 88),
            'png'         => imagepng($dst, $path, 8),
            'webp'        => imagewebp($dst, $path, 88),
            default       => null,
        };

        imagedestroy($src);
        imagedestroy($dst);
    }

    public function resizeExistente(string $path, int $maxW, int $maxH, ?string $ext = null): void {
        if (!$ext) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        }
        $this->resize($path, $maxW, $maxH, $ext);
    }
}