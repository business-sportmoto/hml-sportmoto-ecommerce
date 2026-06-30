<?php
/**
 * app/services/email/providers/EmailProviderInterface.php
 *
 * Contrato comum para todos os provedores de envio.
 * Cada implementação deve ser stateless quanto à campanha — recebe payload e envia.
 */
interface EmailProviderInterface
{
    /**
     * Envia um email.
     *
     * @param array $payload Estrutura esperada:
     *   [
     *     'from_email'    => string,
     *     'from_name'     => string|null,
     *     'reply_to'      => string|null,
     *     'to_email'      => string,
     *     'to_name'       => string|null,
     *     'subject'       => string,
     *     'html'          => string,
     *     'text'          => string|null,
     *     'headers'       => array<string,string>   // List-Unsubscribe etc.
     *     'campanha_id'   => int|null,
     *     'destinatario_id' => int|null,
     *   ]
     * @return EmailSendResult
     */
    public function send(array $payload);

    /**
     * Valida assinatura/secret de webhook do provedor.
     */
    public function validateWebhook(array $headers, $body);

    /**
     * Converte payload bruto do webhook em lista de eventos normalizados:
     *   [
     *     [
     *       'tipo' => 'enviado|entregue|aberto|clicado|bounce|complaint|descadastro|falhou',
     *       'subtipo' => string|null,
     *       'provider_message_id' => string|null,
     *       'email' => string|null,
     *       'dedupe_key' => string,
     *       'ip' => string|null,
     *       'user_agent' => string|null,
     *       'payload' => array,
     *     ],
     *     ...
     *   ]
     */
    public function parseWebhook(array $headers, $body);
}
