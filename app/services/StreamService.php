<?php
declare(strict_types=1);

/**
 * app/services/StreamService.php
 *
 * Integração com Cloudflare Stream para vídeos (banners, clips, ...).
 * Vídeos públicos. Fornece upload direto, status e THUMBNAILS por dois
 * caminhos:
 *   - thumbnailFromApi(uid): pega a URL que a API retorna (1 chamada). Simples,
 *     bom para BANNER (poucos vídeos).
 *   - thumbnailUrl(uid, opts): monta a URL direto via customer code (0 chamadas).
 *     Ideal para CLIPS/feed de alto volume (O(1) por card).
 *
 * @see https://developers.cloudflare.com/stream/
 */
final class StreamService
{
    private string $accountId;
    private string $token;
    private string $customerCode; // subdomínio do Stream: customer-<CODE>....

    private const MAX_DURATION_SECONDS = 300;

    public function __construct(string $accountId, string $token, string $customerCode = '')
    {
        $this->accountId    = $accountId;
        $this->token        = $token;
        $this->customerCode = $customerCode;
    }

    /**
     * Solicita URL de upload direto + UID reservado.
     * @return array{uid:string, uploadURL:string}
     */
    public function createDirectUpload(array $meta = []): array
    {
        $res = $this->request('POST', "/accounts/{$this->accountId}/stream/direct_upload", [
            'maxDurationSeconds' => self::MAX_DURATION_SECONDS,
            'requireSignedURLs'  => false,
            'meta'               => ['name' => $meta['name'] ?? ('media-' . date('YmdHis'))],
        ]);

        if (empty($res['result']['uid']) || empty($res['result']['uploadURL'])) {
            throw new RuntimeException('Stream nao retornou uid/uploadURL.');
        }
        return [
            'uid'       => $res['result']['uid'],
            'uploadURL' => $res['result']['uploadURL'],
        ];
    }

    /**
     * Estado + metadados de um vídeo.
     * @return array{uid:string,status:string,ready:bool,thumbnail:?string,
     *               duration:?float,playback:array{hls:?string,dash:?string}}
     */
    public function getVideo(string $uid): array
    {
        $res = $this->request('GET', "/accounts/{$this->accountId}/stream/{$uid}");
        $r   = $res['result'] ?? [];
        $state = $r['status']['state'] ?? 'unknown';

        return [
            'uid'       => $uid,
            'status'    => $state,
            'ready'     => $state === 'ready',
            'thumbnail' => $r['thumbnail'] ?? null,
            'duration'  => isset($r['duration']) ? (float) $r['duration'] : null,
            'playback'  => [
                'hls'  => $r['playback']['hls']  ?? null,
                'dash' => $r['playback']['dash'] ?? null,
            ],
        ];
    }

    /** Remove um vídeo do Stream. Idempotente. */
    public function deleteVideo(string $uid): void
    {
        if ($uid === '') return;
        try {
            $this->request('DELETE', "/accounts/{$this->accountId}/stream/{$uid}");
        } catch (RuntimeException $e) {
            error_log('[STREAM] delete falhou: ' . $e->getMessage());
        }
    }

    // ── Thumbnails ────────────────────────────────────────────────────────

    /**
     * URL de thumbnail estático via CUSTOMER CODE (0 chamadas à API).
     * Use para CLIPS/feed. Requer customerCode configurado.
     *
     * @param array{time?:string,width?:int,height?:int,fit?:string} $opts
     *        time: '2s' ou '30%' | fit: crop|clip|scale|fill
     */
    public function thumbnailUrl(string $uid, array $opts = []): string
    {
        $this->assertCustomerCode();
        $base = "https://customer-{$this->customerCode}.cloudflarestream.com/{$uid}/thumbnails/thumbnail.jpg";
        $q = array_filter([
            'time'   => $opts['time']   ?? null,
            'width'  => $opts['width']  ?? null,
            'height' => $opts['height'] ?? null,
            'fit'    => $opts['fit']    ?? null,
        ], fn($v) => $v !== null && $v !== '');
        return $q ? $base . '?' . http_build_query($q) : $base;
    }

    /**
     * URL de thumbnail ANIMADO (GIF) — preview de reels no hover.
     * @param array{time?:string,duration?:string,width?:int,fps?:int} $opts
     */
    public function animatedThumbnailUrl(string $uid, array $opts = []): string
    {
        $this->assertCustomerCode();
        $base = "https://customer-{$this->customerCode}.cloudflarestream.com/{$uid}/thumbnails/thumbnail.gif";
        $q = array_filter([
            'time'     => $opts['time']     ?? '0s',
            'duration' => $opts['duration'] ?? '3s',
            'width'    => $opts['width']    ?? 300,
            'fps'      => $opts['fps']      ?? null,
        ], fn($v) => $v !== null && $v !== '');
        return $base . '?' . http_build_query($q);
    }

    /** Thumbnail default fornecido pela API (1 chamada). Use para BANNER. */
    public function thumbnailFromApi(string $uid): ?string
    {
        return $this->getVideo($uid)['thumbnail'] ?? null;
    }

    /** URL do player iframe (público). */
    public function iframeUrl(string $uid): string
    {
        return "https://iframe.cloudflarestream.com/{$uid}";
    }

    /** URL HLS para <video>/hls.js custom player (feed). */
    public function hlsUrl(string $uid): string
    {
        $this->assertCustomerCode();
        return "https://customer-{$this->customerCode}.cloudflarestream.com/{$uid}/manifest/video.m3u8";
    }

    private function assertCustomerCode(): void
    {
        if ($this->customerCode === '') {
            throw new RuntimeException('CF_STREAM_CUSTOMER_CODE nao configurado (necessario para thumbnails/HLS diretos).');
        }
    }

    // ── HTTP interno ──────────────────────────────────────────────────────

    /** @param array<string,mixed>|null $body @return array<string,mixed> */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init("https://api.cloudflare.com/client/v4{$path}");
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->token,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 20,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
        }

        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException("Falha de conexao com Stream: {$err}");
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Resposta invalida do Stream.');
        }
        if (($data['success'] ?? false) !== true) {
            $msg = $data['errors'][0]['message'] ?? "HTTP {$code}";
            throw new RuntimeException("Stream API: {$msg}");
        }
        return $data;
    }


    /**
     * Valida e retorna um UID de vídeo vindo do POST (hidden preenchido pelo
     * frontend após upload direto ao Stream). Retorna null se nada enviado.
     * UID do Stream = 32 hex.
     *
     * (Antes vivia no trait HandlesStreamVideo; movido para cá — o controller
     *  injeta o StreamService e chama $this->stream->uidFromPost('campo').)
     *
     * @throws \RuntimeException se o valor existir mas não for um UID válido.
     */
    public function uidFromPost(string $campo): ?string
    {
        $uid = $_POST[$campo] ?? '';
        $uid = is_string($uid) ? trim($uid) : '';
        if ($uid === '') {
            return null;
        }
        if (!$this->isUid($uid)) {
            throw new \RuntimeException('UID de vídeo inválido.');
        }
        return $uid;
    }

    /** True se a string tem o formato de UID do Stream (32 hex). */
    public function isUid(string $v): bool
    {
        return (bool) preg_match('/^[a-f0-9]{32}$/i', $v);
    }

    /**
     * Remove um vídeo do Stream SE o valor for um UID válido (ignora nomes de
     * arquivo legados). Idempotente. Wrapper seguro sobre deleteVideo().
     */
    public function deleteIfUid(?string $uid): void
    {
        if (!empty($uid) && is_string($uid) && $this->isUid($uid)) {
            $this->deleteVideo($uid);
        }
    }
}