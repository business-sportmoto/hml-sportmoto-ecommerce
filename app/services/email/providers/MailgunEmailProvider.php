<?php
/**
 * app/services/email/providers/MailgunEmailProvider.php
 *
 * Adapter para Mailgun usando a API HTTP (cURL, sem dependências externas).
 *
 * Credenciais esperadas:
 *   api_key, domain, base_url (default https://api.mailgun.net)
 * webhook_secret (signing key) é validado em validateWebhook().
 */
class MailgunEmailProvider implements EmailProviderInterface
{
    /** @var array */
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function send(array $payload)
    {
        $creds = $this->config['credenciais_decoded'] ?? [];
        // $apiKey = $creds['api_key'] ?? '';
        $apiKey = $creds['api_key'] ?? '';
        if (is_array($apiKey)) $apiKey = trim($apiKey[0] ?? '');
        $apiKey = trim($apiKey);
        
        $domain = $creds['domain']  ?? ($this->config['dominio'] ?? '');
        $base   = rtrim($creds['base_url'] ?? 'https://api.mailgun.net', '/');

        if (!$apiKey || !$domain) {
            return EmailSendResult::fail('Credenciais Mailgun inválidas', false, true);
        }

        $url = $base . '/v3/' . urlencode($domain) . '/messages';

        $fromName = $payload['from_name'] ?? '';
        $fromAddr = $payload['from_email'];
        $from = $fromName !== '' ? sprintf('%s <%s>', $fromName, $fromAddr) : $fromAddr;

        $fields = [
            'from'    => $from,
            'to'      => $payload['to_email'],
            'subject' => $payload['subject'],
            'html'    => $payload['html'],
        ];
        if (!empty($payload['text']))     $fields['text']     = $payload['text'];
        if (!empty($payload['reply_to'])) $fields['h:Reply-To'] = $payload['reply_to'];

        if (!empty($payload['headers']) && is_array($payload['headers'])) {
            foreach ($payload['headers'] as $hk => $hv) {
                $fields['h:' . $hk] = $hv;
            }
        }
        if (!empty($payload['campanha_id'])) {
            $fields['v:campanha_id'] = (string)$payload['campanha_id'];
        }
        if (!empty($payload['destinatario_id'])) {
            $fields['v:destinatario_id'] = (string)$payload['destinatario_id'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_USERPWD        => 'api:' . $apiKey,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
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
        if ($code >= 200 && $code < 300 && !empty($json['id'])) {
            return EmailSendResult::ok($json['id'], $json);
        }
        $perm = ($code >= 400 && $code < 500 && $code !== 429);
        return EmailSendResult::fail("HTTP $code: ".json_encode($payload)." - " . substr($resp, 0, 300), !$perm, $perm, $json);
    }

    public function validateWebhook(array $headers, $body)
    {
        $secret = $this->config['webhook_secret'] ?? '';
        if (!$secret) return false;

        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['signature'])) return false;

        $sig   = $data['signature'];
        $token = $sig['token']     ?? '';
        $ts    = $sig['timestamp'] ?? '';
        $hash  = $sig['signature'] ?? '';
        $expected = hash_hmac('sha256', $ts . $token, $secret);
        return hash_equals($expected, $hash);
    }

    public function parseWebhook(array $headers, $body)
    {
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['event-data'])) return [];

        $ev   = $data['event-data'];
        $tipo = $this->mapEvent($ev['event'] ?? '');
        if (!$tipo) return [];

        $email = $ev['recipient'] ?? null;
        $msgId = $ev['message']['headers']['message-id'] ?? null;
        $vars  = $ev['user-variables'] ?? [];

        $dedupe = hash('sha256', ($ev['id'] ?? '') . '|' . ($ev['timestamp'] ?? '') . '|' . $tipo);

        return [[
            'tipo' => $tipo,
            'subtipo' => $ev['severity'] ?? ($ev['reason'] ?? null),
            'provider_message_id' => $msgId,
            'email' => $email,
            'dedupe_key' => $dedupe,
            'ip' => $ev['ip'] ?? null,
            'user_agent' => isset($ev['client-info']['user-agent']) ? $ev['client-info']['user-agent'] : null,
            'campanha_id' => isset($vars['campanha_id']) ? (int)$vars['campanha_id'] : null,
            'destinatario_id' => isset($vars['destinatario_id']) ? (int)$vars['destinatario_id'] : null,
            'payload' => $ev,
        ]];
    }

    private function mapEvent($e)
    {
        switch (strtolower((string)$e)) {
            case 'accepted':   return 'enviado';
            case 'delivered':  return 'entregue';
            case 'opened':     return 'aberto';
            case 'clicked':    return 'clicado';
            case 'failed':
            case 'rejected':   return 'bounce';
            case 'complained': return 'complaint';
            case 'unsubscribed': return 'descadastro';
            default: return null;
        }
    }
}
