<?php
/**
 * app/services/sms/SmsSendResult.php
 *
 * DTO retornado por todos os provedores de SMS.
 * Espelha o EmailSendResult para que quem já conhece a camada de e-mail
 * leia esta sem reaprender nada.
 *
 * A distinção temporário/permanente existe para o chamador decidir se
 * vale reenviar: número inexistente é permanente (reenviar só queima
 * crédito), 5xx e timeout são temporários.
 */
class SmsSendResult
{
    public bool    $success   = false;
    public ?string $messageId = null;
    public ?string $error     = null;

    /** Erro passageiro: rate limit, 5xx, timeout de rede. */
    public bool $temporary = false;

    /** Erro definitivo: número inválido, conta sem crédito, bloqueio. */
    public bool $permanent = false;

    /** Resposta bruta do provedor — nunca contém o código, ver SmsService. */
    public array $raw = [];

    public static function ok(?string $messageId = null, array $raw = []): self
    {
        $r = new self();
        $r->success   = true;
        $r->messageId = $messageId;
        $r->raw       = $raw;
        return $r;
    }

    public static function fail(
        string $error,
        bool   $temporary = true,
        bool   $permanent = false,
        array  $raw = []
    ): self {
        $r = new self();
        $r->success   = false;
        $r->error     = $error;
        $r->temporary = $temporary;
        $r->permanent = $permanent;
        $r->raw       = $raw;
        return $r;
    }
}
