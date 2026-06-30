<?php
/**
 * app/services/email/providers/SmtpEmailProvider.php
 *
 * Envia via SMTP autenticado. Usa PHPMailer se disponível; se não, usa mail()
 * como fallback (não recomendado para volume).
 *
 * Credenciais esperadas em $config['credenciais'] (já decriptografado):
 *   host, port, username, password, encryption ('tls'|'ssl'|''), timeout
 */
class SmtpEmailProvider implements EmailProviderInterface
{
    /** @var array Configuração do provedor (já decriptografada) */
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function send(array $payload)
    {
        $creds = $this->config['credenciais_decoded'] ?? [];
        $host  = $creds['host']     ?? 'localhost';
        $port  = (int)($creds['port'] ?? 587);
        $user  = $creds['username'] ?? '';
        $pass  = $creds['password'] ?? '';
        $enc   = $creds['encryption'] ?? 'tls';

        if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            try {
                $mailerClass = 'PHPMailer\\PHPMailer\\PHPMailer';
                $mail = new $mailerClass(true);
                $mail->isSMTP();
                $mail->Host = $host;
                $mail->Port = $port;
                $mail->SMTPAuth = (bool)$user;
                $mail->Username = $user;
                $mail->Password = $pass;
                if ($enc) $mail->SMTPSecure = $enc;
                $mail->CharSet  = 'UTF-8';
                $mail->Timeout  = (int)($creds['timeout'] ?? 30);

                $mail->setFrom($payload['from_email'], $payload['from_name'] ?? '');
                if (!empty($payload['reply_to'])) {
                    $mail->addReplyTo($payload['reply_to']);
                }
                $mail->addAddress($payload['to_email'], $payload['to_name'] ?? '');
                $mail->Subject = $payload['subject'];
                $mail->isHTML(true);
                $mail->Body    = $payload['html'];
                if (!empty($payload['text'])) $mail->AltBody = $payload['text'];

                if (!empty($payload['headers']) && is_array($payload['headers'])) {
                    foreach ($payload['headers'] as $hk => $hv) {
                        $mail->addCustomHeader($hk, $hv);
                    }
                }

                $mail->send();
                $msgId = method_exists($mail, 'getLastMessageID') ? $mail->getLastMessageID() : null;
                return EmailSendResult::ok($msgId ?: ('smtp-' . uniqid()), ['driver' => 'phpmailer']);

            } catch (Throwable $e) {
                $msg = $e->getMessage();
                $perm = (bool)preg_match('/invalid|reject|user unknown|no such user|5\.1\.\d/i', $msg);
                return EmailSendResult::fail($msg, !$perm, $perm);
            }
        }

        // Fallback mínimo via mail() (não recomendado em volume)
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= 'From: ' . $this->fmtAddr($payload['from_email'], $payload['from_name'] ?? '') . "\r\n";
        if (!empty($payload['reply_to'])) {
            $headers .= 'Reply-To: ' . $payload['reply_to'] . "\r\n";
        }
        if (!empty($payload['headers']) && is_array($payload['headers'])) {
            foreach ($payload['headers'] as $hk => $hv) {
                $headers .= "$hk: $hv\r\n";
            }
        }
        $ok = @mail($payload['to_email'], $payload['subject'], $payload['html'], $headers);
        if ($ok) {
            return EmailSendResult::ok('mail-' . uniqid(), ['driver' => 'mail()']);
        }
        return EmailSendResult::fail('mail() falhou', true, false);
    }

    public function validateWebhook(array $headers, $body)
    {
        return true; // SMTP não possui webhook
    }

    public function parseWebhook(array $headers, $body)
    {
        return [];
    }

    private function fmtAddr($email, $name)
    {
        $name = trim((string)$name);
        if ($name === '') return $email;
        return '=?UTF-8?B?' . base64_encode($name) . '?= <' . $email . '>';
    }
}
