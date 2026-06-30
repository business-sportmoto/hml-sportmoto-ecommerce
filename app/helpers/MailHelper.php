<?php
/**
 * app/helpers/MailHelper.php  (v2)
 *
 * SUBSTITUI o existente. Interface pública 100% retrocompatível.
 *
 * Internamente tenta enviar via EmailTransacionalService (Mailgun + template
 * editável no admin + log). Se o service não estiver disponível ou falhar,
 * faz fallback automático para PHPMailer/mail() (comportamento original).
 *
 * Para adicionar um novo tipo de email:
 *   1. Crie o template no admin (tipo='transacional')
 *   2. Adicione o mapeamento em EmailTransacionalService::$mapa
 *   3. Adicione o método público aqui chamando self::viaService()
 */
class MailHelper
{
    // =========================================================================
    // EMAILS DE CONTA
    // =========================================================================

    public static function sendVerificationEmail(string $email, string $nome, string $token): bool
    {
        $url = BASE_URL . '/verificar-email/' . $token;
        $ok = self::viaService('verificacao_email', $email, $nome, [
            'url_acao' => $url,
            'token'    => $token,
        ]);
        if ($ok) return true;

        // Fallback PHPMailer
        $siteName = defined('SITE_NAME') ? SITE_NAME : 'SportMoto';
        $content = "
            <h2>Confirme seu e-mail</h2>
            <p>Olá, <strong>{$nome}</strong>!</p>
            <p>Clique no botão abaixo para confirmar seu endereço de e-mail.</p>
            <div style='text-align:center;margin:32px 0;'>
                <a href='{$url}' style='display:inline-block;background:#e63946;color:#fff;padding:14px 32px;border-radius:6px;text-decoration:none;font-weight:700;'>Confirmar e-mail</a>
            </div>
            <p style='color:#999;font-size:13px;'>Link: <a href='{$url}'>{$url}</a><br>Expira em 1 hora.</p>
        ";
        return self::send($email, $nome, "Confirme seu e-mail — {$siteName}", self::wrap($content));
    }

    public static function sendPasswordReset(string $email, string $nome, string $token): bool
    {
        $url = BASE_URL . '/redefinir-senha/' . $token;
        $ok = self::viaService('redefinicao_senha', $email, $nome, [
            'url_acao' => $url,
            'token'    => $token,
        ]);
        if ($ok) return true;

        $siteName = defined('SITE_NAME') ? SITE_NAME : 'SportMoto';
        $content = "
            <h2>Redefinir senha</h2>
            <p>Olá, <strong>{$nome}</strong>!</p>
            <p>Clique no botão abaixo para criar uma nova senha.</p>
            <div style='text-align:center;margin:32px 0;'>
                <a href='{$url}' style='display:inline-block;background:#e63946;color:#fff;padding:14px 32px;border-radius:6px;text-decoration:none;font-weight:700;'>Redefinir senha</a>
            </div>
            <p style='color:#999;font-size:13px;'>Expira em 1 hora.</p>
        ";
        return self::send($email, $nome, "Redefinir senha — {$siteName}", self::wrap($content));
    }

    public static function send2FACode(string $email, string $nome, string $code): bool
    {
        $ok = self::viaService('codigo_2fa', $email, $nome, [
            'codigo' => $code,
        ]);
        if ($ok) return true;

        $siteName = defined('SITE_NAME') ? SITE_NAME : 'SportMoto';
        $content = "
            <h2>Código de verificação</h2>
            <p>Olá, <strong>{$nome}</strong>! Seu código:</p>
            <div style='text-align:center;margin:32px 0;'>
                <span style='display:inline-block;background:#f8f8f8;border:2px dashed #e63946;border-radius:8px;padding:16px 40px;font-size:36px;font-weight:700;letter-spacing:8px;'>{$code}</span>
            </div>
            <p style='color:#999;font-size:13px;'>Expira em 1 hora. Não compartilhe este código.</p>
        ";
        return self::send($email, $nome, "Código de verificação — {$siteName}", self::wrap($content));
    }

    public static function sendLoginCode(string $email, string $nome, string $code): bool
    {
        $ok = self::viaService('codigo_login', $email, $nome, [
            'codigo' => $code,
        ]);
        if ($ok) return true;

        $content = "
            <h2>Seu código de acesso</h2>
            <p>Olá, <strong>{$nome}</strong>!</p>
            <div style='text-align:center;margin:32px 0;'>
                <span style='display:inline-block;background:#f8f8f8;border:2px dashed #e63946;border-radius:8px;padding:16px 40px;font-size:36px;font-weight:700;letter-spacing:8px;'>{$code}</span>
            </div>
            <p style='color:#999;font-size:13px;'>Expira em 10 minutos. Não compartilhe.</p>
        ";
        $siteName = defined('SITE_NAME') ? SITE_NAME : 'SportMoto';
        return self::send($email, $nome, "Seu código de acesso — {$siteName}", self::wrap($content));
    }

    public static function sendWelcome(string $email, string $nome): bool
    {
        $ok = self::viaService('boas_vindas', $email, $nome, []);
        if ($ok) return true;

        $siteName = defined('SITE_NAME') ? SITE_NAME : 'SportMoto';
        $url = BASE_URL . '/minha-conta';
        $content = "
            <h2>Bem-vindo(a) à {$siteName}!</h2>
            <p>Olá, <strong>{$nome}</strong>!</p>
            <p>Sua conta foi criada com sucesso.</p>
            <div style='text-align:center;margin:32px 0;'>
                <a href='{$url}' style='display:inline-block;background:#e63946;color:#fff;padding:14px 32px;border-radius:6px;text-decoration:none;font-weight:700;'>Acessar minha conta</a>
            </div>
        ";
        return self::send($email, $nome, "Bem-vindo(a) à {$siteName}!", self::wrap($content));
    }

    // =========================================================================
    // EMAILS DE PEDIDO
    // =========================================================================

    public static function sendOrderConfirmation(string $email, string $nome, array $pedido): bool
    {
        $formas = [
            'pix'            => 'PIX',
            'boleto'         => 'Boleto Bancário',
            'cartao_credito' => 'Cartão de Crédito',
            'cartao_debito'  => 'Cartão de Débito',
        ];
        $ok = self::viaService('pedido_confirmado', $email, $nome, [
            'pedido_codigo'           => $pedido['codigo'] ?? ('#' . $pedido['id']),
            'pedido_total'            => is_numeric($pedido['total'] ?? null)
                ? number_format((float)$pedido['total'], 2, ',', '.') : ($pedido['total'] ?? ''),
            'forma_pagamento'         => $formas[$pedido['forma_pagamento'] ?? ''] ?? ($pedido['forma_pagamento'] ?? ''),
            'pedido_url'              => BASE_URL . '/minha-conta/pedido/' . $pedido['id'],
            'instrucoes_pagamento'    => $pedido['instrucoes_pagamento'] ?? '',
        ]);
        if ($ok) return true;

        // Fallback
        $siteName = defined('SITE_NAME') ? SITE_NAME : 'SportMoto';
        $url      = BASE_URL . '/minha-conta/pedido/' . $pedido['id'];
        $codigo   = $pedido['codigo'] ?? ('#' . $pedido['id']);
        $content  = "
            <h2>Pedido confirmado!</h2>
            <p>Olá, <strong>{$nome}</strong>!</p>
            <p>Seu pedido <strong>{$codigo}</strong> foi recebido e está sendo processado.</p>
            <p><strong>Total:</strong> R$ {$pedido['total']}</p>
            <div style='text-align:center;margin:28px 0;'>
                <a href='{$url}' style='display:inline-block;background:#e63946;color:#fff;padding:14px 32px;border-radius:6px;text-decoration:none;font-weight:700;'>Acompanhar pedido</a>
            </div>
        ";
        return self::send($email, $nome, "Pedido {$codigo} confirmado — {$siteName}", self::wrap($content));
    }

    public static function sendOrderShipped(string $email, string $nome, array $pedido): bool
    {
        $ok = self::viaService('pedido_enviado', $email, $nome, [
            'pedido_codigo'   => $pedido['codigo'] ?? ('#' . $pedido['id']),
            'pedido_url'      => BASE_URL . '/minha-conta/pedido/' . $pedido['id'],
            'rastreio_codigo' => $pedido['rastreio_codigo'] ?? '',
            'rastreio_url'    => $pedido['rastreio_url'] ?? '',
        ]);
        if ($ok) return true;

        $siteName = defined('SITE_NAME') ? SITE_NAME : 'SportMoto';
        $codigo   = $pedido['codigo'] ?? ('#' . $pedido['id']);
        $content  = "
            <h2>Seu pedido foi enviado! 🚚</h2>
            <p>Olá, <strong>{$nome}</strong>! O pedido <strong>{$codigo}</strong> está a caminho.</p>
        ";
        if (!empty($pedido['rastreio_codigo'])) {
            $content .= "<p><strong>Rastreamento:</strong> {$pedido['rastreio_codigo']}</p>";
        }
        return self::send($email, $nome, "Pedido {$codigo} enviado — {$siteName}", self::wrap($content));
    }

    public static function sendOrderCancelled(string $email, string $nome, array $pedido): bool
    {
        $ok = self::viaService('pedido_cancelado', $email, $nome, [
            'pedido_codigo'       => $pedido['codigo'] ?? ('#' . $pedido['id']),
            'motivo_cancelamento' => $pedido['motivo'] ?? '',
            'reembolso_valor'     => isset($pedido['reembolso']) && $pedido['reembolso'] > 0
                ? number_format((float)$pedido['reembolso'], 2, ',', '.') : '',
        ]);
        if ($ok) return true;

        $siteName = defined('SITE_NAME') ? SITE_NAME : 'SportMoto';
        $codigo   = $pedido['codigo'] ?? ('#' . $pedido['id']);
        $content  = "
            <h2>Pedido cancelado</h2>
            <p>Olá, <strong>{$nome}</strong>. O pedido <strong>{$codigo}</strong> foi cancelado.</p>
        ";
        return self::send($email, $nome, "Pedido {$codigo} cancelado — {$siteName}", self::wrap($content));
    }

    // =========================================================================
    // ENVIO GENÉRICO (usado pelos métodos acima como fallback)
    // =========================================================================

    public static function send(string $to, string $toName, string $subject, string $htmlBody): bool
    {
        $phpmailerPath = defined('VENDOR_PATH') ? VENDOR_PATH . '/phpmailer/src/PHPMailer.php' : null;
        if ($phpmailerPath && file_exists($phpmailerPath)) {
            return self::sendSmtp($to, $toName, $subject, $htmlBody);
        }
        return self::sendNative($to, $subject, $htmlBody);
    }

    // =========================================================================
    // INTERNO
    // =========================================================================

    /**
     * Tenta enviar via EmailTransacionalService.
     * Retorna false se o service não estiver disponível ou se não houver template.
     */
    private static function viaService(string $tipo, string $email, string $nome, array $vars): bool
    {
        if (!class_exists('EmailTransacionalService')) return false;
        try {
            $svc = new EmailTransacionalService();
            return $svc->enviar($tipo, $email, $nome, $vars);
        } catch (Throwable $e) {
            if (class_exists('LogService')) {
                try { LogService::warning("MailHelper viaService[$tipo]: " . $e->getMessage()); } catch (Throwable $ex) {}
            }
            return false;
        }
    }

    private static function sendSmtp(string $to, string $toName, string $subject, string $body): bool
    {
        require_once VENDOR_PATH . '/phpmailer/src/PHPMailer.php';
        require_once VENDOR_PATH . '/phpmailer/src/SMTP.php';
        require_once VENDOR_PATH . '/phpmailer/src/Exception.php';

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = defined('MAIL_HOST') ? MAIL_HOST : '';
            $mail->SMTPAuth   = true;
            $mail->Username   = defined('MAIL_USER') ? MAIL_USER : '';
            $mail->Password   = defined('MAIL_PASS') ? MAIL_PASS : '';
            $mail->SMTPSecure = 'tls';
            $mail->Port       = defined('MAIL_PORT') ? MAIL_PORT : 587;
            $mail->CharSet    = 'UTF-8';
            $mail->setFrom(defined('MAIL_FROM') ? MAIL_FROM : '', defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : '');
            $mail->addAddress($to, $toName);
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body    = $body;
            $mail->AltBody = strip_tags($body);
            $mail->send();
            return true;
        } catch (Throwable $e) {
            if (class_exists('LogService')) {
                try { LogService::error('MailHelper SMTP: ' . $e->getMessage()); } catch (Throwable $ex) {}
            }
            return false;
        }
    }

    private static function sendNative(string $to, string $subject, string $body): bool
    {
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
        $from = defined('MAIL_FROM') ? MAIL_FROM : '';
        $fromName = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : '';
        $headers .= "From: {$fromName} <{$from}>\r\n";
        return mail($to, $subject, $body, $headers);
    }

    private static function wrap(string $content, string $preheader = ''): string
    {
        $siteName = defined('SITE_NAME') ? SITE_NAME : 'SportMoto';
        $year     = date('Y');
        return "<!DOCTYPE html><html lang='pt-BR'><head><meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width,initial-scale=1'>
            </head><body style='margin:0;padding:0;background:#f4f4f4;font-family:Arial,sans-serif;'>
            <div style='display:none;max-height:0;overflow:hidden;'>{$preheader}</div>
            <table width='100%' cellpadding='0' cellspacing='0' style='background:#f4f4f4;padding:32px 0;'>
            <tr><td align='center'><table width='600' cellpadding='0' cellspacing='0'
              style='max-width:600px;width:100%;background:#fff;border-radius:8px;overflow:hidden;'>
            <tr><td style='background:#1a1a1a;padding:28px 40px;text-align:center;'>
              <span style='color:#fff;font-size:22px;font-weight:700;'>{$siteName}</span></td></tr>
            <tr><td style='padding:40px 40px 24px;'>{$content}</td></tr>
            <tr><td style='padding:24px 40px;background:#f8f8f8;text-align:center;border-top:1px solid #e8e8e8;'>
              <p style='margin:0;font-size:12px;color:#999;'>&copy; {$year} {$siteName}.</p>
            </td></tr></table></td></tr></table></body></html>";
    }

    /**
     * Email transacional genérico — use para qualquer micro-email do site.
     *
     * @param string $para         Email do destinatário
     * @param string $nome         Nome do destinatário
     * @param string $assunto      Assunto do email
     * @param string $mensagem     Corpo em HTML (aceita tags simples)
     * @param array  $opcoes       Opções extras:
     *                               'botao_texto' → texto do botão CTA
     *                               'botao_url'   → URL do botão CTA
     *                               'preheader'   → texto de prévia no inbox
     *                               'rodape'      → texto adicional no rodapé
     */
    public static function sendSimples(
        string $para,
        string $nome,
        string $assunto,
        string $mensagem,
        array $opcoes = []
    ): bool {
        $botaoHtml = '';
        if (!empty($opcoes['botao_texto']) && !empty($opcoes['botao_url'])) {
            $url   = htmlspecialchars($opcoes['botao_url']);
            $texto = htmlspecialchars($opcoes['botao_texto']);
            $botaoHtml = "
                <div style='text-align:center;margin:28px 0;'>
                    <a href='{$url}'
                    style='display:inline-block;background:#e53935;color:#fff;
                            font-size:15px;font-weight:700;text-decoration:none;
                            padding:13px 36px;border-radius:6px;'>
                        {$texto}
                    </a>
                </div>
            ";
        }

        $rodapeExtra = '';
        if (!empty($opcoes['rodape'])) {
            $rodapeExtra = "<p style='color:#999;font-size:12px;margin:16px 0 0;'>"
                        . htmlspecialchars($opcoes['rodape']) . "</p>";
        }

        $content = "
            <p style='color:#555;font-size:15px;line-height:1.7;margin:0 0 16px;'>
                {$mensagem}
            </p>
            {$botaoHtml}
            {$rodapeExtra}
        ";

        $preheader = $opcoes['preheader'] ?? '';
        return self::send($para, $nome, $assunto, self::wrap($content, $preheader));
    }


    /**
     * SNIPPET — adicionar este método ao app/helpers/MailHelper.php
     *
     * Chamado pelo TokenService quando um reuso de remember token é
     * detectado (cookie roubado). Todas as sessões já foram revogadas
     * antes do envio — o e-mail só avisa o dono.
     */
    public static function sendSecurityAlert(string $email, string $nome): void {
        $assunto = 'Alerta de segurança — sessões encerradas';
    
        $corpo = "
            <p>Olá, {$nome}!</p>
            <p>Detectamos uma atividade suspeita na sua conta: um acesso
            tentou usar uma credencial de \"lembrar de mim\" que já havia
            sido renovada — um possível sinal de cookie copiado.</p>
            <p><strong>Por segurança, encerramos todas as sessões ativas
            em todos os dispositivos.</strong></p>
            <p>O que fazer agora:</p>
            <ul>
                <li>Entre novamente com sua senha;</li>
                <li>Se você não reconhece essa atividade, troque sua senha
                    imediatamente;</li>
                <li>Ative a verificação em duas etapas em
                    Minha conta &rarr; Segurança.</li>
            </ul>
            <p>Se foi você em dois dispositivos ao mesmo tempo, pode ignorar
            este aviso e apenas entrar de novo.</p>
        ";
        
        // Ajuste para o método de envio padrão do seu MailHelper:
        self::send($email, $nome, $assunto, $corpo);
    }
    
}
