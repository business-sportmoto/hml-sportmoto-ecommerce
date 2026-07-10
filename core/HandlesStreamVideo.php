<?php
declare(strict_types=1);

/**
 * app/traits/HandlesStreamVideo.php  (ou core/, conforme tua estrutura)
 *
 * Trait reutilizavel para QUALQUER controller que lide com video no
 * Cloudflare Stream (Banners, Clips, e futuros). Single source of truth
 * da validacao de UID e limpeza de video — evita divergencia entre modulos.
 *
 * USO:
 *   class ClipsController extends Controller {
 *       use HandlesStreamVideo;
 *       ...
 *       $uid = $this->videoUidFromPost('video_uid'); // valida
 *       $this->deleteVideoStream($uidAntigo);         // limpa orfao
 *   }
 *
 * @see StreamService
 */
trait HandlesStreamVideo
{
    private ?StreamService $streamSvc = null;

    /** Service do Stream (token do .getenv), memoizado por request. */
    protected function streamService(): StreamService
    {
        if ($this->streamSvc === null) {
            $this->streamSvc = new StreamService(
                getenv('CF_ACCOUNT_ID'),
                getenv('CF_STREAM_TOKEN'),
                getenv('CF_STREAM_CUSTOMER_CODE') ?? ''
            );
        }
        return $this->streamSvc;
    }

    /**
     * Valida e retorna um UID de video vindo do POST (hidden preenchido pelo
     * frontend apos upload ao Stream). Retorna null se nada foi getenviado.
     * UID do Stream = 32 chars hex.
     *
     * @throws \RuntimeException se o valor existir mas nao for um UID valido.
     */
    protected function videoUidFromPost(string $campo): ?string
    {
        $uid = $_POST[$campo] ?? '';
        $uid = is_string($uid) ? trim($uid) : '';
        if ($uid === '') {
            return null;
        }
        if (!$this->isStreamUid($uid)) {
            throw new \RuntimeException('UID de video invalido.');
        }
        return $uid;
    }

    /** Remove um video do Stream (ao trocar/excluir). Idempotente. */
    protected function deleteVideoStream(?string $uid): void
    {
        if (empty($uid) || !$this->isStreamUid($uid)) {
            return; // vazio ou nao-UID (arquivo legado) -> ignora
        }
        $this->streamService()->deleteVideo($uid);
    }

    /** True se a string tem o formato de UID do Stream (32 hex). */
    protected function isStreamUid(string $v): bool
    {
        return (bool) preg_match('/^[a-f0-9]{32}$/i', $v);
    }
}

/*
Como usar:

$thumb = $this->streamService()->thumbnailFromApi($uid);

// poster estático do card
$poster = $stream->thumbnailUrl($uid, ['width'=>400,'height'=>700,'fit'=>'crop']);
// preview animado no hover
$preview = $stream->animatedThumbnailUrl($uid, ['duration'=>'3s','width'=>400']);

*/ 