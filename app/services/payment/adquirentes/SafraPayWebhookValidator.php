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

    private string $merchantId;

    public function __construct(string $merchantToken = '', string $headerExtra = '', string $segredoExtra = '')
    {
        // MESMA fonte do SafraPayClient: cadastro do admin primeiro, .env como
        // retaguarda. Antes isto lia só o .env — se a credencial fosse trocada
        // pelo admin, as cobranças passariam a usar a nova e o webhook
        // continuaria validando contra a antiga, recusando tudo.
        $cliente = new SafraPayClient();

        $this->merchantToken = $merchantToken !== '' ? $merchantToken : $cliente->merchantTokenEmUso();
        $this->merchantId    = $cliente->merchantId();
        $this->headerExtra   = $headerExtra   !== '' ? $headerExtra   : self::cfg('SAFRAPAY_WEBHOOK_HEADER');
        $this->segredoExtra  = $segredoExtra  !== '' ? $segredoExtra  : self::cfg('SAFRAPAY_WEBHOOK_SECRET');
    }

    /**
     * Formas de codificar o segredo que a Safra pode enviar no Authorization.
     *
     * A doc diz apenas "o merchant token em Base64", o que é ambíguo: pode ser
     * o token isolado ou o par no formato clássico do HTTP Basic
     * (base64 de "usuario:senha"). Aceitar as variações é seguro — todas
     * derivam do MESMO segredo, e quem não o tem não produz nenhuma delas.
     * Recusar notificação legítima por diferença de formatação é o pior
     * desfecho: o pagamento fica pendente com o dinheiro pago.
     *
     * @return array<string,string> rótulo => valor esperado
     */
    private function candidatos(): array
    {
        $t   = $this->merchantToken;
        $mid = $this->merchantId;

        $c = [
            'base64(token)' => base64_encode($t),
            'token'         => $t,
        ];
        if ($mid !== '') {
            $c['base64(merchantId:token)'] = base64_encode($mid . ':' . $t);
            $c['base64(token:merchantId)'] = base64_encode($t . ':' . $mid);
            $c['merchantId:token']         = $mid . ':' . $t;
        }
        return $c;
    }

    /**
     * Formas que identificam o estabelecimento SEM PROVAR NADA.
     *
     * VERIFICADO EM HOMOLOGAÇÃO: a Safra envia `base64(merchantId)`, não o
     * merchant token — a documentação diz "o merchant token em Base64" e está
     * errada. Confirmado casando o fingerprint do valor recebido.
     *
     * O problema: o MerchantId NÃO É SEGREDO. Ele aparece no portal, vai no
     * corpo do JWT e é enviado como header em chamadas de API. Qualquer um que
     * o conheça consegue forjar uma notificação.
     *
     * Aceitar é necessário para o webhook funcionar, mas isto é IDENTIFICAÇÃO,
     * não autenticação. Duas coisas seguram o risco:
     *   1. O processor RECONSULTA a cobrança antes de qualquer efeito
     *      financeiro — notificação forjada não cria pagamento.
     *   2. O segundo fator (customHeaders), que é segredo de verdade.
     *
     * @return array<string,string>
     */
    private function candidatosFracos(): array
    {
        if ($this->merchantId === '') return [];
        return [
            'base64(merchantId)' => base64_encode($this->merchantId),
            'merchantId'         => $this->merchantId,
        ];
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

        // hash_equals: comparação em tempo constante. Com == daria para
        // descobrir o segredo byte a byte medindo o tempo de resposta.
        // Percorre TODOS os candidatos mesmo após achar — sair no primeiro
        // acerto reintroduziria o vazamento por tempo.
        $ok      = false;
        $formato = null;
        $fraco   = false;

        foreach ($this->candidatos() as $rotulo => $esperado) {
            if (hash_equals($esperado, $recebido)) {
                $ok      = true;
                $formato = $formato ?? $rotulo;
            }
        }

        if (!$ok) {
            foreach ($this->candidatosFracos() as $rotulo => $esperado) {
                if (hash_equals($esperado, $recebido)) {
                    $ok      = true;
                    $fraco   = true;
                    $formato = $formato ?? $rotulo;
                }
            }
        }

        if (!$ok) {
            // DIAGNÓSTICO SEM VAZAR O SEGREDO.
            //
            // "Authorization não confere" sozinho é indepurável: não dá para
            // saber se o token está errado, se o formato é outro, ou se veio
            // truncado. O hash curto permite comparar o recebido com o
            // esperado sem que nenhum dos dois apareça em log.
            return [
                'valida' => false,
                'motivo' => sprintf(
                    'Authorization não confere (recebido: %d chars, fp %s; esperados: %s)',
                    strlen($recebido),
                    substr(hash('sha256', $recebido), 0, 8),
                    implode(', ', array_map(
                        static fn(string $r, string $v): string => $r . ' fp ' . substr(hash('sha256', $v), 0, 8),
                        array_keys($this->candidatos()),
                        array_values($this->candidatos())
                    ))
                ),
            ];
        }

        // Segundo fator, quando configurado.
        $comSegundoFator = false;
        if ($this->headerExtra !== '' && $this->segredoExtra !== '') {
            $extra = self::header($headers, $this->headerExtra);
            if ($extra === '' || !hash_equals($this->segredoExtra, trim($extra))) {
                return ['valida' => false, 'motivo' => 'header customizado ausente ou inválido'];
            }
            $comSegundoFator = true;
        }

        // Só o MerchantId autenticando e nenhum segundo fator: a notificação
        // está sendo aceita com um dado que não é secreto. Não bloqueia — o
        // processor reconsulta antes de qualquer efeito — mas precisa doer
        // no log até alguém configurar o customHeaders.
        if ($fraco && !$comSegundoFator && class_exists('LogService')) {
            LogService::warning(
                'Webhook Safra aceito só pelo MerchantId — configure SAFRAPAY_WEBHOOK_SECRET',
                ['formato' => $formato], 'pagamento'
            );
        }

        return ['valida' => true, 'motivo' => null, 'fraca' => $fraco, 'formato' => $formato];
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
