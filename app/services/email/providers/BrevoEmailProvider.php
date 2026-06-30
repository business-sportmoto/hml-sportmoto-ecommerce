<?php
/**
 * app/services/email/providers/BrevoEmailProvider.php
 *
 * Adapter para Brevo (Sendinblue) — API v3.
 * Credenciais: api_key.
 * Webhook não é assinado por default — recomenda-se exigir webhook_secret no path
 * ou validar IP da Brevo.
 */
class BrevoEmailProvider implements EmailProviderInterface
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
            return EmailSendResult::fail('Credenciais Brevo inválidas', false, true);
        }

        $body = [
            'sender' => [
                'email' => $payload['from_email'],
                'name'  => $payload['from_name'] ?? '',
            ],
            'to' => [[
                'email' => $payload['to_email'],
                'name'  => $payload['to_name'] ?? '',
            ]],
            'subject'     => $payload['subject'],
            'htmlContent' => $payload['html'],
        ];
        if (!empty($payload['text']))     $body['textContent'] = $payload['text'];
        if (!empty($payload['reply_to'])) $body['replyTo']     = ['email' => $payload['reply_to']];
        if (!empty($payload['headers']))  $body['headers']     = $payload['headers'];

        $params = [];
        if (!empty($payload['campanha_id']))     $params['campanha_id']     = (int)$payload['campanha_id'];
        if (!empty($payload['destinatario_id'])) $params['destinatario_id'] = (int)$payload['destinatario_id'];
        if ($params) $body['params'] = $params;

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'api-key: ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => 30,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            return EmailSendResult::fail('cURL: ' . $err, true);
        }
        $json = json_decode($resp, true) ?: [];
        if ($code >= 200 && $code < 300 && !empty($json['messageId'])) {
            return EmailSendResult::ok($json['messageId'], $json);
        }
        $perm = ($code >= 400 && $code < 500 && $code !== 429);
        return EmailSendResult::fail("HTTP $code: " . substr($resp, 0, 300), !$perm, $perm, $json);
    }

    public function validateWebhook(array $headers, $body)
    {
        // Brevo não assina o webhook por padrão — exigimos secret via path/header.
        $secret = $this->config['webhook_secret'] ?? '';
        if (!$secret) return true; // operador optou por não validar
        foreach ($headers as $k => $v) {
            if (strcasecmp($k, 'X-Webhook-Secret') === 0) {
                $val = is_array($v) ? $v[0] : $v;
                return hash_equals($secret, (string)$val);
            }
        }
        return false;
    }

    public function parseWebhook(array $headers, $body)
    {
        $data = json_decode($body, true);
        if (!is_array($data)) return [];
        $events = isset($data[0]) ? $data : [$data];
        $out = [];
        foreach ($events as $ev) {
            $tipo = $this->mapEvent($ev['event'] ?? '');
            if (!$tipo) continue;
            $dedupe = hash('sha256', ($ev['message-id'] ?? '') . '|' . ($ev['date'] ?? '') . '|' . $tipo);
            $params = $ev['params'] ?? [];
            $out[] = [
                'tipo' => $tipo,
                'subtipo' => $ev['reason'] ?? null,
                'provider_message_id' => $ev['message-id'] ?? null,
                'email' => $ev['email'] ?? null,
                'dedupe_key' => $dedupe,
                'ip' => $ev['ip'] ?? null,
                'user_agent' => $ev['user-agent'] ?? null,
                'campanha_id' => isset($params['campanha_id']) ? (int)$params['campanha_id'] : null,
                'destinatario_id' => isset($params['destinatario_id']) ? (int)$params['destinatario_id'] : null,
                'payload' => $ev,
            ];
        }
        return $out;
    }

    private function mapEvent($e)
    {
        switch (strtolower((string)$e)) {
            case 'sent':
            case 'request':       return 'enviado';
            case 'delivered':     return 'entregue';
            case 'opened':
            case 'unique_opened': return 'aberto';
            case 'click':         return 'clicado';
            case 'hard_bounce':
            case 'soft_bounce':
            case 'invalid_email': return 'bounce';
            case 'spam':          return 'complaint';
            case 'unsubscribed':  return 'descadastro';
            case 'blocked':
            case 'deferred':      return 'falhou';
            default: return null;
        }
    }
}
