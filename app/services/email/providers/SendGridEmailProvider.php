<?php
/**
 * app/services/email/providers/SendGridEmailProvider.php
 *
 * Adapter para SendGrid v3 API.
 *
 * Credenciais: api_key
 * Verificação de webhook (Event Webhook signed) usa public_key + headers
 *   X-Twilio-Email-Event-Webhook-Signature
 *   X-Twilio-Email-Event-Webhook-Timestamp
 */
class SendGridEmailProvider implements EmailProviderInterface
{
    /** @var array */
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function send(array $payload)
    {
        $creds  = $this->config['credenciais_decoded'] ?? [];
        $apiKey = $creds['api_key'] ?? '';
        if (!$apiKey) {
            return EmailSendResult::fail('Credenciais SendGrid inválidas', false, true);
        }

        $url = 'https://api.sendgrid.com/v3/mail/send';

        $custom = [];
        if (!empty($payload['campanha_id']))     $custom['campanha_id']     = (string)$payload['campanha_id'];
        if (!empty($payload['destinatario_id'])) $custom['destinatario_id'] = (string)$payload['destinatario_id'];

        $body = [
            'personalizations' => [[
                'to' => [[
                    'email' => $payload['to_email'],
                    'name'  => $payload['to_name'] ?? '',
                ]],
                'custom_args' => $custom,
            ]],
            'from' => [
                'email' => $payload['from_email'],
                'name'  => $payload['from_name'] ?? '',
            ],
            'subject' => $payload['subject'],
            'content' => [],
        ];
        if (!empty($payload['text'])) {
            $body['content'][] = ['type' => 'text/plain', 'value' => $payload['text']];
        }
        $body['content'][] = ['type' => 'text/html', 'value' => $payload['html']];

        if (!empty($payload['reply_to'])) {
            $body['reply_to'] = ['email' => $payload['reply_to']];
        }
        if (!empty($payload['headers']) && is_array($payload['headers'])) {
            $body['headers'] = $payload['headers'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HEADER         => true,
        ]);
        $resp     = curl_exec($ch);
        $code     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hsize    = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $err      = curl_error($ch);
        $rawHead  = $resp !== false ? substr($resp, 0, $hsize) : '';
        $rawBody  = $resp !== false ? substr($resp, $hsize)    : '';
        curl_close($ch);

        if ($resp === false) {
            return EmailSendResult::fail('cURL: ' . $err, true);
        }
        if ($code >= 200 && $code < 300) {
            $msgId = null;
            foreach (explode("\r\n", $rawHead) as $line) {
                if (stripos($line, 'X-Message-Id:') === 0) {
                    $msgId = trim(substr($line, 13));
                    break;
                }
            }
            return EmailSendResult::ok($msgId ?: 'sg-' . uniqid(), ['code' => $code]);
        }
        $perm = ($code >= 400 && $code < 500 && $code !== 429);
        return EmailSendResult::fail("HTTP $code: " . substr($rawBody, 0, 300), !$perm, $perm);
    }

    public function validateWebhook(array $headers, $body)
    {
        $publicKey = $this->config['credenciais_decoded']['public_key'] ?? '';
        if (!$publicKey) {
            // Se admin não configurou public_key, falha por padrão.
            return false;
        }
        $sig = $this->h($headers, 'X-Twilio-Email-Event-Webhook-Signature');
        $ts  = $this->h($headers, 'X-Twilio-Email-Event-Webhook-Timestamp');
        if (!$sig || !$ts) return false;

        if (!function_exists('openssl_verify')) return false;

        $payload = $ts . $body;
        $sigBin  = base64_decode($sig);
        $pem     = $this->ensurePem($publicKey);
        $pubRes  = @openssl_pkey_get_public($pem);
        if (!$pubRes) return false;
        $ok = openssl_verify($payload, $sigBin, $pubRes, OPENSSL_ALGO_SHA256);
        return $ok === 1;
    }

    public function parseWebhook(array $headers, $body)
    {
        $events = json_decode($body, true);
        if (!is_array($events)) return [];
        $out = [];
        foreach ($events as $ev) {
            $tipo = $this->mapEvent($ev['event'] ?? '');
            if (!$tipo) continue;
            $sgMsg = $ev['sg_message_id'] ?? null;
            if ($sgMsg && strpos($sgMsg, '.') !== false) {
                $sgMsg = explode('.', $sgMsg)[0];
            }
            $dedupe = hash('sha256', ($ev['sg_event_id'] ?? '') . '|' . $tipo);
            $out[] = [
                'tipo' => $tipo,
                'subtipo' => $ev['type'] ?? ($ev['reason'] ?? null),
                'provider_message_id' => $sgMsg,
                'email' => $ev['email'] ?? null,
                'dedupe_key' => $dedupe,
                'ip' => $ev['ip'] ?? null,
                'user_agent' => $ev['useragent'] ?? null,
                'campanha_id' => isset($ev['campanha_id']) ? (int)$ev['campanha_id'] : null,
                'destinatario_id' => isset($ev['destinatario_id']) ? (int)$ev['destinatario_id'] : null,
                'payload' => $ev,
            ];
        }
        return $out;
    }

    private function h(array $headers, $name)
    {
        foreach ($headers as $k => $v) {
            if (strcasecmp($k, $name) === 0) return is_array($v) ? $v[0] : $v;
        }
        return null;
    }

    private function ensurePem($key)
    {
        if (strpos($key, '-----BEGIN') !== false) return $key;
        $key = trim($key);
        $wrapped = chunk_split($key, 64, "\n");
        return "-----BEGIN PUBLIC KEY-----\n" . $wrapped . "-----END PUBLIC KEY-----\n";
    }

    private function mapEvent($e)
    {
        switch (strtolower((string)$e)) {
            case 'processed':  return 'enviado';
            case 'delivered':  return 'entregue';
            case 'open':       return 'aberto';
            case 'click':      return 'clicado';
            case 'bounce':
            case 'dropped':    return 'bounce';
            case 'spamreport': return 'complaint';
            case 'unsubscribe':
            case 'group_unsubscribe': return 'descadastro';
            default: return null;
        }
    }
}
