<?php
/**
 * app/services/email/providers/EmailSendResult.php
 *
 * DTO simples retornado por todos os provedores.
 */
class EmailSendResult
{
    /** @var bool */
    public $success = false;
    /** @var string|null */
    public $providerMessageId = null;
    /** @var string|null */
    public $error = null;
    /** @var bool true para erros temporários (rate limit, 5xx, timeout) */
    public $temporary = false;
    /** @var bool true para erros permanentes (email inválido, supressão) */
    public $permanent = false;
    /** @var array */
    public $raw = [];

    public static function ok($providerMessageId, array $raw = [])
    {
        $r = new self();
        $r->success = true;
        $r->providerMessageId = $providerMessageId;
        $r->raw = $raw;
        return $r;
    }

    public static function fail($error, $temporary = true, $permanent = false, array $raw = [])
    {
        $r = new self();
        $r->success = false;
        $r->error = $error;
        $r->temporary = $temporary;
        $r->permanent = $permanent;
        $r->raw = $raw;
        return $r;
    }
}
