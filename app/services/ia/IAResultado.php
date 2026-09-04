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

    /** Assíncrono: provedor aceitou o job e processa em background (Replicate). */
    public bool $aguardando = false;

    /** Referência do job no provedor (id da prediction) — vai para ia_geracoes.external_id. */
    public ?string $externalId = null;

    /**
     * Imagens já baixadas (capacidade imagem).
     * Cada item: ['binario' => string, 'mime' => string, 'extensao' => string]
     */
    public array $imagens = [];

    /* ---- Conversa com ferramentas (capacidade agente) ---------------- */

    /** end_turn | tool_use | max_tokens | refusal — o loop decide por ele. */
    public ?string $stopReason = null;

    /** content[] cru da resposta (text + tool_use), para reenviar como turno assistant. */
    public array $blocos = [];

    /** Tokens lidos/gravados no cache de prompt — informativos (o rollup é por token pleno). */
    public ?int $tokensCacheLeitura = null;
    public ?int $tokensCacheCriacao = null;

    /** Preenchidos pelo orquestrador ao fim do loop. */
    public int $rodadas = 0;
    /** Transcrição completa da rodada (mensagens novas: assistant + tool_result). */
    public array $mensagens = [];
    /** Cada item: ['nome'=>, 'parametros'=>, 'ok'=>, 'ms'=>, 'cache'=>, 'dados'=>|'erro'=>] */
    public array $ferramentasUsadas = [];

    public static function sucesso(string $texto): self
    {
        $r = new self();
        $r->ok = true;
        $r->texto = $texto;
        $r->retryable = false;
        return $r;
    }

    /** Sucesso de imagem síncrona (binários já em mãos). */
    public static function sucessoImagem(array $imagens): self
    {
        $r = new self();
        $r->ok = true;
        $r->imagens = $imagens;
        $r->retryable = false;
        return $r;
    }

    /** Provedor assíncrono aceitou — a conclusão virá por webhook ou varredura. */
    public static function pendente(string $externalId): self
    {
        $r = new self();
        $r->ok = false;
        $r->aguardando = true;
        $r->retryable = false;
        $r->externalId = $externalId;
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
