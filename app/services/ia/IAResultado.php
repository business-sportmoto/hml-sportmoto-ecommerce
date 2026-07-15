<?php
/**
 * IAResultado — contrato único de retorno dos adapters e do orquestrador.
 * erro_codigo é SEMPRE string (lição Malga: provedores devolvem int).
 */
class IAResultado
{
    public bool $ok = false;

    /** Conteúdo textual gerado (capacidade texto). */
    public ?string $texto = null;

    public ?string $erroCodigo = null;
    public ?string $erro = null;

    /** true = o fallback deve tentar o próximo modelo. */
    public bool $retryable = true;

    public ?int $tokensIn = null;
    public ?int $tokensOut = null;
    public int $tempoMs = 0;

    /** Preenchidos pelo orquestrador ao concluir. */
    public ?int $modeloId = null;
    public ?string $provedorCodigo = null;
    public ?string $modeloCodigo = null;
    public ?float $custoRealUsd = null;

    /** JSON bruto da resposta do provedor (auditoria — vai para storage). */
    public ?string $respostaBruta = null;

    public static function sucesso(string $texto): self
    {
        $r = new self();
        $r->ok = true;
        $r->texto = $texto;
        $r->retryable = false;
        return $r;
    }

    public static function falha(string $codigo, string $mensagem, bool $retryable = true): self
    {
        $r = new self();
        $r->ok = false;
        $r->erroCodigo = (string) $codigo;
        $r->erro = mb_substr($mensagem, 0, 600);
        $r->retryable = $retryable;
        return $r;
    }
}
