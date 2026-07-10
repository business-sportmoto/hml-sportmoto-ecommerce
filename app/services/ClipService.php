<?php
declare(strict_types=1);

/**
 * app/services/ClipService.php  — v3 (Cloudflare Stream)
 *
 * O ffmpeg foi REMOVIDO (decisao de arquitetura do runbook: Stream faz todo
 * o transcode e gera thumbnails). Este service agora:
 *   - Nao processa video no servidor (upload vai direto do browser pro Stream).
 *   - Monta URLs de reproducao (HLS) e thumbnail a partir do UID do Stream,
 *     via customer code (O(1), sem chamada de API) — critico para o feed.
 *   - Limpa o video no Stream ao excluir.
 *
 * @see StreamService
 */
class ClipService {

    use HandlesStreamVideo;  // videoUidFromPost(), deleteVideoStream(), streamService()

    /**
     * URL HLS para o player custom (<video> + hls.js) do feed.
     */
    public function hlsUrl(string $uid): string
    {
        return $this->streamService()->hlsUrl($uid);
    }

    /**
     * Poster do card (thumbnail estatico) via customer code — 0 chamadas API.
     * Formato vertical 9:16 do feed de reels.
     */
    public function posterUrl(string $uid): string
    {
        return $this->streamService()->thumbnailUrl($uid, [
            'width'  => 720,
            'height' => 1280,
            'fit'    => 'crop',
        ]);
    }

    /**
     * Preview animado (GIF) — opcional, para hover no grid do admin.
     */
    public function previewUrl(string $uid): string
    {
        return $this->streamService()->animatedThumbnailUrl($uid, [
            'duration' => '3s',
            'width'    => 400,
        ]);
    }

    /**
     * Remove o video do Stream ao excluir o clip. Idempotente.
     * Aceita o array do clip (compat com a assinatura antiga do deletar()).
     */
    public function deletar(array $clip): void
    {
        $uid = $clip['arquivo_video'] ?? '';
        $this->deleteVideoStream(is_string($uid) ? $uid : '');
    }
}