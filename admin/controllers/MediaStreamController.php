<?php
declare(strict_types=1);

/**
 * admin/controllers/MediaStreamController.php
 *
 * Endpoints GENERICOS de upload/status de video no Cloudflare Stream,
 * usados por QUALQUER modulo (banners, clips, ...). Single source of truth
 * dos endpoints — em vez de duplicar streamUploadUrl em cada controller.
 *
 * Rotas sugeridas (admin/config/routes.php):
 *   AdminRouter::post('/media/stream-upload-url', 'MediaStreamController@uploadUrl');
 *   AdminRouter::get('/media/stream-status',      'MediaStreamController@status');
 *
 * SEGURANCA:
 *   - requireAdmin no construtor (so admin gera upload URL).
 *   - CSRF no POST.
 *   - RATE LIMIT por admin: cada upload URL reserva recurso no CF; sem limite,
 *     um loop geraria uploads infinitos (custo). Critico no clips (alto volume).
 */
final class MediaStreamController extends Controller
{
    use HandlesStreamVideo;

    /** Máx. de upload URLs por admin na janela (anti-abuso de custo). */
    private const RATE_MAX     = 30;
    private const RATE_WINDOW  = 300; // 5 min

    public function __construct()
    {
        AuthHelper::requireAdmin();
    }

    /**
     * POST /media/stream-upload-url
     * Body: _csrf_token, [name] (rotulo opcional do video)
     * Resp: { ok, uid, uploadURL }
     */
    public function uploadUrl(): void
    {
        $this->verifyCsrf();

        // Rate limit por admin (reusa o SecurityHelper do projeto).
        $adminId = Session::getAdminId() ?? 0;
        $rlKey   = 'stream_upload_' . $adminId;
        if (SecurityHelper::rateLimitExceeded($rlKey, self::RATE_MAX, self::RATE_WINDOW)) {
            $this->json(['ok' => false, 'msg' => 'Muitos envios em sequencia. Aguarde alguns minutos.']);
        }

        try {
            $nome = SecurityHelper::sanitizeString($_POST['name'] ?? '') ?: 'media';
            $upload = $this->streamService()->createDirectUpload(['name' => $nome]);

            $this->json([
                'ok'        => true,
                'uid'       => $upload['uid'],
                'uploadURL' => $upload['uploadURL'],
            ]);
        } catch (\RuntimeException $e) {
            error_log('[STREAM-URL] ' . $e->getMessage());
            $this->json(['ok' => false, 'msg' => 'Nao foi possivel iniciar o upload.']);
        }
    }

    /**
     * GET /media/stream-status?uid=...
     * Resp: { ok, ready, status }
     */
    public function status(): void
    {
        $uid = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['uid'] ?? '');
        if ($uid === '' || !$this->isStreamUid($uid)) {
            $this->json(['ok' => false, 'msg' => 'UID invalido.']);
        }

        try {
            $video = $this->streamService()->getVideo($uid);
            $this->json([
                'ok'     => true,
                'ready'  => $video['ready'],
                'status' => $video['status'],
            ]);
        } catch (\RuntimeException $e) {
            $this->json(['ok' => false, 'msg' => 'Erro ao consultar status.']);
        }
    }
}