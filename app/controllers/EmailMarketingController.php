<?php
/**
 * app/controllers/EmailMarketingController.php
 *
 * Controller PÚBLICO de descadastro. Não requer login.
 * Renderiza página simples confirmando o opt-out.
 */
class EmailMarketingController extends Controller
{
    public function unsubscribe($token)
    {
        $token = preg_replace('/[^a-f0-9]/i', '', (string)$token);
        $contato = (new EmailContact())->findByUnsubToken($token);

        $this->render('email-marketing/unsubscribe', [
            'token' => $token,
            'contato' => $contato,
            'confirmado' => false,
            'titulo' => 'Descadastrar',
        ]);
    }

    public function unsubscribeConfirm($token)
    {
        $token = preg_replace('/[^a-f0-9]/i', '', (string)$token);
        $contato = (new EmailContact())->findByUnsubToken($token);

        if ($contato) {
            try {
                $svc = new EmailConsentService();
                $svc->optOut($contato['id'], [
                    'origem' => 'link',
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                    'referencia' => 'unsubscribe-link',
                ]);
                (new EmailSuppression())->adicionar(
                    $contato['email'], 'descadastro', 'link', null
                );
                if (class_exists('LogService')) {
                    LogService::audit('email_unsubscribe', ['email' => $contato['email']]);
                }
            } catch (Throwable $e) {
                if (class_exists('LogService')) {
                    LogService::error('unsubscribe: ' . $e->getMessage());
                }
            }
        }

        $this->render('email-marketing/unsubscribe', [
            'token' => $token,
            'contato' => $contato,
            'confirmado' => true,
            'titulo' => 'Descadastrar',
        ]);
    }
}
