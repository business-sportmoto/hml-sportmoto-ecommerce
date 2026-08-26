<?php
declare(strict_types=1);

/**
 * app/services/payment/adquirentes/SafraPayWebhookValidator.php
 *
 * Autentica a notificação recebida da Safra Pay.
 *
 * COMO A SAFRA AUTENTICA (doc: /webhook#payload-de-notificacao):
 *   "Valide o cabeçalho Authorization com o merchant token em Base64."
 *
 * É SEGREDO COMPARTILHADO, não assinatura HMAC. Consequências que valem
 * registrar, porque mudam o que este arquivo pode e não pode garantir:
 *
 *   - NÃO há prova de integridade do corpo. Um proxy no meio poderia alterar
 *     o payload sem invalidar o header. Por isso o processor NUNCA confia no
 *     valor recebido para liberar pedido: reconsulta a cobrança na Safra
 *     (GET /v2/charge/{id}) antes de qualquer efeito financeiro.
 *   - NÃO há timestamp assinado, então não dá para detectar replay pelo
 *     header. A defesa é a idempotência por event_id em pgto_webhook_log.
 *   - O segredo viaja em toda notificação. Se vazar, qualquer um consegue
 *     forjar chamadas — daí o segundo fator opcional abaixo.
 *
 * SEGUNDO FATOR (recomendado): o cadastro em POST /v1/webhook/bulk aceita
 * `customHeaders`, repassados na notificação. Registrando um header próprio
 * com um segredo só nosso, uma notificação forjada precisa de DOIS segredos.
 * Configure em SAFRAPAY_WEBHOOK_HEADER e SAFRAPAY_WEBHOOK_SECRET.
 */
class SafraPayWebhookValidator
{
    private string $merchantToken;
    private string $headerExtra;
    private string $segredoExtra;

    public function __construct(string $merchantToken = '', string $headerExtra = '', string $segredoExtra = '')
    {
        $this->merchantToken = $merchantToken !== '' ? $merchantToken : self::cfg('SAFRAPAY_MERCHANT_TOKEN');
        $this->headerExtra   = $headerExtra   !== '' ? $headerExtra   : self::cfg('SAFRAPAY_WEBHOOK_HEADER');
        $this->segredoExtra  = $segredoExtra  !== '' ? $segredoExtra  : self::cfg('SAFRAPAY_WEBHOOK_SECRET');
    }

    /**
     * @param array $headers Cabeçalhos da requisição (chave => valor)
     * @return array{valida:bool, motivo:?string}
     */
    public function validar(array $headers): array
    {
        if ($this->merchantToken === '') {
            return ['valida' => false, 'motivo' => 'SAFRAPAY_MERCHANT_TOKEN não configurado'];
        }

        $recebido = self::header($headers, 'Authorization');
        if ($recebido === '') {
            return ['valida' => false, 'motivo' => 'header Authorization ausente'];
        }

        // A Safra pode mandar com ou sem o prefixo "Basic". Normaliza antes
        // de comparar para não recusar notificação legítima por causa disso.
        $recebido = preg_replace('/^\s*(Basic|Bearer)\s+/i', '', $recebido) ?? $recebido;
        $recebido = trim($recebido);

        $esperado = base64_encode($this->merchantToken);

        // hash_equals: comparação em tempo constante. Com == daria para
        // descobrir o segredo byte a byte medindo o tempo de resposta.
        $ok = hash_equals($esperado, $recebido);

        // Tolera o token puro (sem base64) — algumas contas enviam assim.
        // Registrado como aceito para que a diferença apareça no log.
        if (!$ok && hash_equals($this->merchantToken, $recebido)) {
            $ok = true;
        }

        if (!$ok) {
            return ['valida' => false, 'motivo' => 'Authorization não confere'];
        }

        // Segundo fator, quando configurado.
        if ($this->headerExtra !== '' && $this->segredoExtra !== '') {
            $extra = self::header($headers, $this->headerExtra);
            if ($extra === '' || !hash_equals($this->segredoExtra, trim($extra))) {
                return ['valida' => false, 'motivo' => 'header customizado ausente ou inválido'];
            }
        }

        return ['valida' => true, 'motivo' => null];
    }

    /** Busca cabeçalho sem depender de caixa (HTTP header é case-insensitive). */
    private static function header(array $headers, string $nome): string
    {
        $alvo = strtolower($nome);
        foreach ($headers as $k => $v) {
            if (strtolower((string) $k) === $alvo) {
                return is_array($v) ? (string) reset($v) : (string) $v;
            }
        }
        return '';
    }

    private static function cfg(string $chave): string
    {
        if (defined($chave)) {
            $v = constant($chave);
            if (is_string($v) && $v !== '') return $v;
        }
        $v = getenv($chave);
        if ($v !== false && $v !== '') return (string) $v;
        if (isset($_ENV[$chave])    && $_ENV[$chave]    !== '') return (string) $_ENV[$chave];
        if (isset($_SERVER[$chave]) && $_SERVER[$chave] !== '') return (string) $_SERVER[$chave];
        return '';
    }
}
