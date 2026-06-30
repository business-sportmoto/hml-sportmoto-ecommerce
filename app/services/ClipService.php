<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/services/ClipService.php
// ════════════════════════════════════════════════════════
class ClipService {

    private const MAX_BYTES    = 200 * 1024 * 1024; // 200MB antes do processamento
    private const MAX_DURATION = 180;                // segundos
    private const MIMES_VIDEO  = ['video/mp4', 'video/quicktime', 'video/x-msvideo'];
    private const SUBDIR       = 'clips';
    private const POSTER_DIR   = 'clips/posters';

    /**
     * Processa upload de vídeo:
     * 1. Valida MIME real via finfo
     * 2. Usa ffmpeg pra comprimir, converter pra H.264 + AAC, 720p máx
     * 3. Gera poster (thumbnail) no segundo 1
     * 4. Retorna metadados
     */
    public function processar(array $file): array {
        $this->validar($file);

        $tmp  = $file['tmp_name'];
        $hash = bin2hex(random_bytes(10));

        $dirVideo  = UPLOAD_PATH . '/' . self::SUBDIR . '/';
        $dirPoster = UPLOAD_PATH . '/' . self::POSTER_DIR . '/';
        foreach ([$dirVideo, $dirPoster] as $d) {
            if (!is_dir($d)) mkdir($d, 0755, true);
        }

        $videoFinal  = "clip_{$hash}.mp4";
        $posterFinal = "poster_{$hash}.webp";

        $pathVideo  = $dirVideo  . $videoFinal;
        $pathPoster = $dirPoster . $posterFinal;

        // Copia para local temporário (evita problemas com tmp_name)
        $tmpCopy = sys_get_temp_dir() . "/clip_{$hash}_orig.mp4";
        move_uploaded_file($tmp, $tmpCopy);

        if (!$this->ffmpegDisponivel()) {
            // Sem ffmpeg: copia direto (dev local)
            copy($tmpCopy, $pathVideo);
            @unlink($tmpCopy);
            return [
                'arquivo_video'   => $videoFinal,
                'arquivo_poster'  => null,
                'duracao_segundos'=> null,
                'tamanho_bytes'   => filesize($pathVideo),
                'resolucao'       => null,
            ];
        }

        // Comprime e converte (9:16 centered crop se necessário)
        $ffmpegCmd = sprintf(
            'ffmpeg -i %s ' .
            '-vf "scale=iw*min(720/iw\,1280/ih):ih*min(720/iw\,1280/ih),' .
            'pad=720:1280:(720-iw*min(720/iw\,1280/ih))/2:(1280-ih*min(720/iw\,1280/ih))/2" ' .
            '-c:v libx264 -preset fast -crf 28 -movflags +faststart ' .
            '-c:a aac -b:a 128k ' .
            '-t %d ' .           // máximo de duração
            '-y %s 2>&1',
            escapeshellarg($tmpCopy),
            self::MAX_DURATION,
            escapeshellarg($pathVideo)
        );
        exec($ffmpegCmd, $output, $returnCode);

        if ($returnCode !== 0 || !file_exists($pathVideo)) {
            @unlink($tmpCopy);
            throw new \RuntimeException('Falha ao processar vídeo. Verifique o formato.');
        }

        // Gera poster no segundo 1
        $posterCmd = sprintf(
            'ffmpeg -i %s -ss 00:00:01 -vframes 1 ' .
            '-vf "scale=720:1280:force_original_aspect_ratio=decrease,pad=720:1280:(720-iw)/2:(1280-ih)/2" ' .
            '-y %s 2>&1',
            escapeshellarg($pathVideo),
            escapeshellarg($pathPoster)
        );
        exec($posterCmd);

        // Metadados via ffprobe
        $duracao    = null;
        $resolucao  = null;
        $probeCmd   = sprintf(
            'ffprobe -v error -select_streams v:0 ' .
            '-show_entries stream=width,height,duration ' .
            '-of default=noprint_wrappers=1 %s 2>&1',
            escapeshellarg($pathVideo)
        );
        exec($probeCmd, $probeOut);
        foreach ($probeOut as $linha) {
            if (str_starts_with($linha, 'duration=')) $duracao = (int)floatval(substr($linha, 9));
            if (str_starts_with($linha, 'width='))    $w = (int)substr($linha, 6);
            if (str_starts_with($linha, 'height='))   $h = (int)substr($linha, 7);
        }
        if (isset($w, $h)) $resolucao = "{$w}x{$h}";

        @unlink($tmpCopy);

        return [
            'arquivo_video'    => $videoFinal,
            'arquivo_poster'   => file_exists($pathPoster) ? $posterFinal : null,
            'duracao_segundos' => $duracao,
            'tamanho_bytes'    => filesize($pathVideo),
            'resolucao'        => $resolucao,
        ];
    }

    public function deletar(array $clip): void {
        $paths = [
            UPLOAD_PATH . '/' . self::SUBDIR   . '/' . $clip['arquivo_video'],
            UPLOAD_PATH . '/' . self::POSTER_DIR . '/' . ($clip['arquivo_poster'] ?? ''),
        ];
        foreach ($paths as $p) if ($p && file_exists($p)) @unlink($p);
    }

    private function validar(array $file): void {
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Erro no upload.');
        }
        if ($file['size'] <= 0 || $file['size'] > self::MAX_BYTES) {
            throw new \RuntimeException('Arquivo excede 200MB.');
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            throw new \RuntimeException('Upload inválido.');
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
        if (!in_array($mime, self::MIMES_VIDEO, true)) {
            throw new \RuntimeException('Formato não permitido. Use MP4, MOV ou AVI.');
        }
    }

    private function ffmpegDisponivel(): bool {
        exec('which ffmpeg 2>&1', $out, $code);
        return $code === 0;
    }
}


