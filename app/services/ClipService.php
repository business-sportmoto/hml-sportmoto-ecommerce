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
     * Poster do clip com PRECEDÊNCIA:
     *   1. Poster customizado (URL do R2, salvo em arquivo_poster)
     *   2. Thumbnail gerado pelo Stream a partir do vídeo (via UID)
     *
     * @param array $clip Linha da tabela clips (precisa de arquivo_poster e arquivo_video)
     */
    public function posterFor(array $clip): ?string
    {
        // 1. Poster customizado (URL completa do R2)
        $custom = $clip['arquivo_poster'] ?? '';
        if (is_string($custom) && str_starts_with($custom, 'http')) {
            return $custom;
        }

        // 2. Fallback: thumbnail do próprio vídeo no Stream
        $uid = $clip['arquivo_video'] ?? '';
        if (is_string($uid) && preg_match('/^[a-f0-9]{32}$/i', $uid)) {
            return $this->posterFromStream($uid);
        }

        return null;
    }

    // ** Thumbnail 9:16 gerado pelo Stream (0 chamadas de API). */
    public function posterFromStream(string $uid): string
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