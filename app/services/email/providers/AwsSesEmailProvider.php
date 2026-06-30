<?php
/**
 * app/services/email/providers/AwsSesEmailProvider.php
 *
 * Adapter AWS SES via API SendRawEmail com assinatura Signature V4 sem
 * SDK. Suficiente para envio direto. Para uso em larga escala recomenda-se SDK
 * oficial, mas funcionalmente este adapter atende.
 *
 * Credenciais: access_key, secret_key, region (default us-east-1)
 *
 * Webhooks: SES envia eventos via SNS. validateWebhook checa o tipo SNS e
 * normaliza para chamada interna; aqui validamos apenas a estrutura — para
 * verificação criptográfica de SNS (X-Amz-Sns-Message-Signature) recomenda-se
 * implementação adicional usando os certificados públicos da AWS.
 */
class AwsSesEmailProvider implements EmailProviderInterface
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
        $access = $creds['access_key'] ?? '';
        $secret = $creds['secret_key'] ?? '';
        $region = $creds['region'] ?? ($this->config['regiao'] ?? 'us-east-1');

        if (!$access || !$secret) {
            return EmailSendResult::fail('Credenciais SES inválidas', false, true);
        }

        $host    = "email.$region.amazonaws.com";
        $service = 'ses';

        $raw  = $this->buildRawEmail($payload);
        $b64  = base64_encode($raw);
        $body = http_build_query([
            'Action'         => 'SendRawEmail',
            'Version'        => '2010-12-01',
            'RawMessage.Data' => $b64,
        ]);

        $headers = $this->sigV4Sign($access, $secret, $region, $service, $host, $body);

        $ch = curl_init("https://$host/");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            return EmailSendResult::fail('cURL: ' . $err, true);
        }
        if ($code >= 200 && $code < 300) {
            $msgId = null;
            if (preg_match('#<MessageId>([^<]+)</MessageId>#', $resp, $m)) {
                $msgId = $m[1];
            }
            return EmailSendResult::ok($msgId ?: 'ses-' . uniqid(), ['code' => $code]);
        }
        $perm = ($code >= 400 && $code < 500 && $code !== 429);
        return EmailSendResult::fail("HTTP $code: " . substr($resp, 0, 300), !$perm, $perm);
    }

    public function validateWebhook(array $headers, $body)
    {
        // Validação mínima: precisa ser SNS Notification ou SubscriptionConfirmation
        $data = json_decode($body, true);
        if (!is_array($data)) return false;
        $type = $data['Type'] ?? '';
        return in_array($type, ['Notification', 'SubscriptionConfirmation', 'UnsubscribeConfirmation'], true);
        // OBS: para verificação completa de assinatura SNS,
        // baixar SigningCertURL e validar payload — recomendado em produção.
    }

    public function parseWebhook(array $headers, $body)
    {
        $data = json_decode($body, true);
        if (!is_array($data)) return [];

        if (($data['Type'] ?? '') === 'SubscriptionConfirmation') {
            // Notifica para o controller fazer curl no SubscribeURL — não gera evento.
            return [[
                'tipo' => 'outro',
                'subtipo' => 'sns_subscribe',
                'dedupe_key' => hash('sha256', ($data['MessageId'] ?? uniqid()) . 'sub'),
                'payload' => $data,
            ]];
        }

        $message = $data['Message'] ?? '';
        $msgArr  = is_string($message) ? (json_decode($message, true) ?: []) : (array)$message;
        if (!$msgArr) return [];

        $notifType = $msgArr['notificationType'] ?? ($msgArr['eventType'] ?? '');
        $mail      = $msgArr['mail'] ?? [];
        $msgId     = $mail['messageId'] ?? null;
        $tags      = $mail['tags'] ?? [];

        $campanhaId      = isset($tags['campanha_id'][0])     ? (int)$tags['campanha_id'][0]     : null;
        $destinatarioId  = isset($tags['destinatario_id'][0]) ? (int)$tags['destinatario_id'][0] : null;

        $tipo = $this->mapEvent($notifType);
        if (!$tipo) return [];

        $email = null;
        if (isset($msgArr['bounce']['bouncedRecipients'][0]['emailAddress'])) {
            $email = $msgArr['bounce']['bouncedRecipients'][0]['emailAddress'];
        } elseif (isset($msgArr['complaint']['complainedRecipients'][0]['emailAddress'])) {
            $email = $msgArr['complaint']['complainedRecipients'][0]['emailAddress'];
        } elseif (isset($msgArr['delivery']['recipients'][0])) {
            $email = $msgArr['delivery']['recipients'][0];
        }

        $subtipo = null;
        if ($tipo === 'bounce') {
            $subtipo = $msgArr['bounce']['bounceType'] ?? null;
        }

        $dedupe = hash('sha256', ($data['MessageId'] ?? uniqid()) . '|' . $tipo);

        return [[
            'tipo' => $tipo,
            'subtipo' => $subtipo,
            'provider_message_id' => $msgId,
            'email' => $email,
            'dedupe_key' => $dedupe,
            'ip' => null,
            'user_agent' => null,
            'campanha_id' => $campanhaId,
            'destinatario_id' => $destinatarioId,
            'payload' => $msgArr,
        ]];
    }

    private function mapEvent($e)
    {
        switch (strtolower((string)$e)) {
            case 'delivery':  return 'entregue';
            case 'send':      return 'enviado';
            case 'open':      return 'aberto';
            case 'click':     return 'clicado';
            case 'bounce':    return 'bounce';
            case 'complaint': return 'complaint';
            case 'reject':    return 'falhou';
            default: return null;
        }
    }

    // ------------ Email RAW (MIME) -----------------------------------
    private function buildRawEmail(array $p)
    {
        $boundary = '=_Part_' . bin2hex(random_bytes(8));
        $fromName = (string)($p['from_name'] ?? '');
        $from     = $fromName !== ''
            ? '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $p['from_email'] . '>'
            : $p['from_email'];

        $h  = "From: $from\r\n";
        $h .= 'To: ' . $p['to_email'] . "\r\n";
        if (!empty($p['reply_to']))     $h .= 'Reply-To: ' . $p['reply_to'] . "\r\n";
        $h .= 'Subject: =?UTF-8?B?' . base64_encode($p['subject']) . "?=\r\n";
        $h .= "MIME-Version: 1.0\r\n";
        if (!empty($p['headers']) && is_array($p['headers'])) {
            foreach ($p['headers'] as $hk => $hv) $h .= "$hk: $hv\r\n";
        }
        $h .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n\r\n";

        $body = '';
        if (!empty($p['text'])) {
            $body .= "--$boundary\r\n";
            $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $body .= chunk_split(base64_encode($p['text'])) . "\r\n";
        }
        $body .= "--$boundary\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($p['html'])) . "\r\n";
        $body .= "--$boundary--\r\n";

        return $h . $body;
    }

    // ------------ AWS Signature v4 -----------------------------------
    private function sigV4Sign($access, $secret, $region, $service, $host, $body)
    {
        $amzDate   = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        $contentType = 'application/x-www-form-urlencoded; charset=utf-8';

        $canonHeaders = "content-type:$contentType\nhost:$host\nx-amz-date:$amzDate\n";
        $signedHeaders = 'content-type;host;x-amz-date';
        $payloadHash = hash('sha256', $body);

        $canonical = "POST\n/\n\n$canonHeaders\n$signedHeaders\n$payloadHash";
        $algorithm = 'AWS4-HMAC-SHA256';
        $credScope = "$dateStamp/$region/$service/aws4_request";
        $stringToSign = "$algorithm\n$amzDate\n$credScope\n" . hash('sha256', $canonical);

        $kSecret  = 'AWS4' . $secret;
        $kDate    = hash_hmac('sha256', $dateStamp, $kSecret, true);
        $kRegion  = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $auth = "$algorithm Credential=$access/$credScope, SignedHeaders=$signedHeaders, Signature=$signature";

        return [
            "Content-Type: $contentType",
            "Host: $host",
            "X-Amz-Date: $amzDate",
            "Authorization: $auth",
        ];
    }
}
