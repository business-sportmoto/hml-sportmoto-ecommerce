<?php
/**
 * app/controllers/EmailWebhookController.php
 *
 * Recebe webhooks dos provedores, valida assinatura via adapter e processa.
 *
 * IMPORTANTE: cada endpoint pode receber um query string `?provedor_id=N` (ou
 * a rota pode ser configurada por provider) para identificar exatamente qual
 * provedor (e portanto qual webhook_secret/public_key) usar. Se omitido,
 * pega o primeiro provedor ativo daquele tipo.
 */
class EmailWebhookController extends Controller
{
    public function awsSes()    { $this->processar('ses'); }
    public function mailgun()   { $this->processar('mailgun'); }
    public function sendgrid()  { $this->processar('sendgrid'); }
    public function brevo()     { $this->processar('brevo'); }

    private function processar($tipo)
    {
        // payload bruto + headers
        $body = file_get_contents('php://input');
        $headers = $this->getAllHeaders();

        try {
            $cfg = $this->resolverConfig($tipo);
            if (!$cfg) {
                http_response_code(404);
                echo 'Provedor não encontrado';
                return;
            }
            /** @var EmailProviderInterface $adapter */
            $adapter = (new EmailProviderService())->build($cfg);

            // AWS SES SubscriptionConfirmation
            if ($tipo === 'ses') {
                $data = json_decode($body, true);
                if (is_array($data) && ($data['Type'] ?? '') === 'SubscriptionConfirmation') {
                    if (!empty($data['SubscribeURL'])) {
                        @file_get_contents($data['SubscribeURL']);
                    }
                    http_response_code(200); echo 'ok'; return;
                }
            }

            $valid = $adapter->validateWebhook($headers, $body);
            if (!$valid) {
                http_response_code(401);
                echo 'invalid signature';
                if (class_exists('LogService')) {
                    LogService::warning('email_webhook: assinatura inválida', ['tipo' => $tipo]);
                }
                return;
            }

            $eventos = $adapter->parseWebhook($headers, $body);
            LogService::info('webhook e-mail testing',[$eventos, $body]);
            if ($eventos) {
                $stats = (new EmailWebhookService())->processarEventos($eventos);
                if (class_exists('LogService')) {
                    LogService::info('email_webhook ' . $tipo, $stats);
                }
            }

            http_response_code(200);
            echo 'ok';
        } catch (Throwable $e) {
            if (class_exists('LogService')) LogService::error('webhook ' . $tipo . ': ' . $e->getMessage());
            http_response_code(500);
            echo 'erro';
        }
    }

    /** Identifica o provedor a usar para validar este webhook. */
    private function resolverConfig($tipo)
    {
        $db = Database::getInstance()->getConnection();
        $provedorId = isset($_GET['provedor_id']) ? (int)$_GET['provedor_id'] : 0;
        if ($provedorId) {
            $st = $db->prepare("SELECT * FROM email_provedores WHERE id = :id AND tipo = :t LIMIT 1");
            $st->execute([':id' => $provedorId, ':t' => $tipo]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $st = $db->prepare("SELECT * FROM email_provedores
            WHERE tipo = :t AND ativo = 1 ORDER BY padrao DESC, id ASC LIMIT 1");
        $st->execute([':t' => $tipo]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function getAllHeaders()
    {
        if (function_exists('getallheaders')) {
            $h = getallheaders();
            if (is_array($h)) return $h;
        }
        $out = [];
        foreach ($_SERVER as $k => $v) {
            if (strpos($k, 'HTTP_') === 0) {
                $name = str_replace(' ', '-',
                    ucwords(strtolower(str_replace('_', ' ', substr($k, 5)))));
                $out[$name] = $v;
            }
        }
        foreach (['CONTENT_TYPE', 'CONTENT_LENGTH'] as $sk) {
            if (isset($_SERVER[$sk])) {
                $name = str_replace('_', '-', ucwords(strtolower($sk), '_'));
                $out[$name] = $_SERVER[$sk];
            }
        }
        return $out;
    }
}
