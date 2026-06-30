<?php
/**
 * app/controllers/EmailTrackingController.php
 */
class EmailTrackingController extends Controller
{
    public function open($token)
    {
        // Sempre devolve a imagem, mesmo em caso de erro silencioso
        $token = preg_replace('/[^a-f0-9]/i', '', (string)$token);
        try {
            $destinatario = (new EmailCampaignRecipient())->findByTokenOpen($token);
            if ($destinatario) {
                (new EmailTrackingService())->registrarAbertura(
                    $destinatario,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null
                );
            }
        } catch (Throwable $e) {
            if (class_exists('LogService')) LogService::error('email_open: ' . $e->getMessage());
        }
        $this->servirPixel1x1();
    }

    public function click($destinatarioId, $linkId, $token)
    {
        $destinatarioId = (int)$destinatarioId;
        $linkId = (int)$linkId;
        $token = preg_replace('/[^a-f0-9]/i', '', (string)$token);

        $url = (defined('BASE_URL') ? BASE_URL : '/');

        try {
            $destinatario = (new EmailCampaignRecipient())->find($destinatarioId);
            $link = (new EmailLink())->find($linkId);

            // valida token contra o destinatário
            if ($destinatario && $link
                && hash_equals((string)$destinatario['token_open'], $token)
                && (int)$link['campanha_id'] === (int)$destinatario['campanha_id']) {

                (new EmailTrackingService())->registrarClique(
                    $destinatario, $link,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null
                );
                $url = $link['url_destino'];
            }
        } catch (Throwable $e) {
            if (class_exists('LogService')) LogService::error('email_click: ' . $e->getMessage());
        }

        header('Location: ' . $url, true, 302);
        exit;
    }

    private function servirPixel1x1()
    {
        // PNG 1x1 transparente
        $bin = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABAQMAAAAl21bKAAAAA1BMVEX///+nxBvIAAAAC0lEQVQI12NgAAIAAAUAAeImBZsAAAAASUVORK5CYII=');
        header('Content-Type: image/png');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Content-Length: ' . strlen($bin));
        echo $bin;
        exit;
    }
}
