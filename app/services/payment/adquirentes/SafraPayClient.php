<?php
declare(strict_types=1);

/**
 * app/services/payment/adquirentes/SafraPayClient.php
 *
 * Transporte da API Safra Pay: autenticação JWT, cache do token e execução
 * das chamadas. NÃO conhece regra de negócio de pagamento — quem traduz
 * pedido em payload é o SafraPayAdapter.
 *
 * AUTENTICAÇÃO (verificada contra o sandbox real):
 *   POST {gateway}/v2/merchant/auth
 *     header: Authorization: mk_...      ← valor DIRETO, sem "Bearer", sem body
 *     → {accessToken, refreshToken, success, errors, traceKey}
 *   Demais rotas: Authorization: Bearer {accessToken}
 *   O JWT dura 60 minutos.
 *
 * BASES (documentação /primeiros-passos#endpoints):
 *   hml   payment-hml.safrapay.com.br  · webhook-hml.safrapay.com.br
 *   prod  payment.safrapay.com.br      · webhook.safrapay.com.br
 *
 * ENVELOPE: toda resposta traz {traceKey, success, errors[]}. O traceKey é o
 * identificador que o suporte da Safra pede em investigação — por isso ele é
 * propagado para o log de tentativas, não descartado.
 *
 * CREDENCIAIS (.env): SAFRAPAY_AMBIENTE, SAFRAPAY_MERCHANT_ID,
 * SAFRAPAY_MERCHANT_TOKEN, SAFRAPAY_TIMEOUT.
 * O MerchantToken é segredo: nunca vai para log, nem para o front.
 */
class SafraPayClient
{
    private const BASES = [
        'hml'  => ['gateway' => 'https://payment-hml.safrapay.com.br', 'webhook' => 'https://webhook-hml.safrapay.com.br'],
        'prod' => ['gateway' => 'https://payment.safrapay.com.br',     'webhook' => 'https://webhook.safrapay.com.br'],
    ];

    /** Renova o JWT antes de expirar de fato — evita perder uma autorização
     *  por 3 segundos de folga num checkout. */
    private const MARGEM_RENOVACAO_SEG = 300;

    private string $ambiente;
    private string $merchantId;
    private string $merchantToken;
    private int    $timeout;

    /** Cache do JWT em memória (por request) — [token, expira_em] */
    private static array $tokenCache = [];

    public function __construct(
        string $merchantId = '',
        string $merchantToken = '',
        string $ambiente = '',
        int    $timeout = 0
    ) {
        $this->merchantId    = $merchantId    !== '' ? $merchantId    : self::cfg('SAFRAPAY_MERCHANT_ID');
        $this->merchantToken = $merchantToken !== '' ? $merchantToken : self::cfg('SAFRAPAY_MERCHANT_TOKEN');

        $amb = strtolower($ambiente !== '' ? $ambiente : (self::cfg('SAFRAPAY_AMBIENTE') ?: 'hml'));
        $this->ambiente = isset(self::BASES[$amb]) ? $amb : 'hml';

        $t = $timeout > 0 ? $timeout : (int) (self::cfg('SAFRAPAY_TIMEOUT') ?: 20);
        // Teto de 45s: isto roda com o cliente esperando no checkout.
        $this->timeout = max(5, min($t, 45));
    }

    public function configurado(): bool
    {
        return $this->merchantToken !== '' && $this->merchantId !== '';
    }

    public function ambiente(): string
    {
        return $this->ambiente;
    }

    public function merchantId(): string
    {
        return $this->merchantId;
    }

    public function baseGateway(): string
    {
        return self::BASES[$this->ambiente]['gateway'];
    }

    public function baseWebhook(): string
    {
        return self::BASES[$this->ambiente]['webhook'];
    }

    // =========================================================================
    // AUTENTICAÇÃO
    // =========================================================================

    /**
     * JWT válido, autenticando ou reaproveitando o cache.
     * @throws RuntimeException se a autenticação falhar
     */
    public function accessToken(): string
    {
        $chave = $this->ambiente . ':' . substr(hash('sha256', $this->merchantToken), 0, 16);

        $cached = self::$tokenCache[$chave] ?? null;
        if ($cached && $cached['expira_em'] > (time() + self::MARGEM_RENOVACAO_SEG)) {
            return $cached['token'];
        }

        if (!$this->configurado()) {
            throw new RuntimeException('Safra Pay sem credenciais (SAFRAPAY_MERCHANT_TOKEN/ID).');
        }

        // O MerchantToken vai DIRETO no Authorization, sem "Bearer" e sem
        // corpo JSON. Mandar como Bearer devolve 401.
        $resp = $this->executar(
            'POST',
            $this->baseGateway() . '/v2/merchant/auth',
            null,
            ['Authorization: ' . $this->merchantToken],
            false
        );

        $token = (string) ($resp['body']['accessToken'] ?? '');
        if ($resp['http'] !== 200 || empty($resp['body']['success']) || $token === '') {
            throw new RuntimeException(
                'Safra Pay: falha na autenticação — ' . self::primeiroErro($resp['body'])
                . ' (HTTP ' . $resp['http'] . ')'
            );
        }

        self::$tokenCache[$chave] = [
            'token'     => $token,
            'expira_em' => self::expiracaoJwt($token) ?: (time() + 3300),
        ];

        return $token;
    }

    /** Descarta o JWT em cache (401 inesperado, troca de credencial, testes). */
    public static function limparTokenCache(): void
    {
        self::$tokenCache = [];
    }

    // =========================================================================
    // CHAMADAS AUTENTICADAS
    // =========================================================================

    /**
     * Chamada autenticada ao gateway.
     *
     * @param string $recurso Caminho a partir da base (ex.: '/v2/charge/authorization')
     * @return array{http:int, body:array, traceKey:?string, sucesso:bool, duracao_ms:int, erro_rede:?string}
     */
    public function chamar(string $metodo, string $recurso, ?array $payload = null): array
    {
        $resp = $this->executar(
            $metodo,
            $this->baseGateway() . $recurso,
            $payload,
            ['Authorization: Bearer ' . $this->accessToken()]
        );

        // 401 com JWT em cache = token invalidado do outro lado. Uma única
        // reautenticação, sem laço: se falhar de novo, é credencial.
        if ($resp['http'] === 401) {
            self::limparTokenCache();
            $resp = $this->executar(
                $metodo,
                $this->baseGateway() . $recurso,
                $payload,
                ['Authorization: Bearer ' . $this->accessToken()]
            );
        }

        return $resp;
    }

    // =========================================================================
    // INTERNO
    // =========================================================================

    /**
     * @return array{http:int, body:array, traceKey:?string, sucesso:bool, duracao_ms:int, erro_rede:?string, raw:string}
     */
    private function executar(
        string  $metodo,
        string  $url,
        ?array  $payload,
        array   $headers,
        bool    $json = true
    ): array {
        $headers[] = 'Accept: application/json';
        $corpo     = '';

        if ($payload !== null) {
            $corpo     = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $headers[] = 'Content-Type: application/json';
        } elseif ($json) {
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $metodo,
            CURLOPT_POSTFIELDS     => $corpo,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);

        $inicio = microtime(true);
        $raw    = curl_exec($ch);
        $http   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlEr = curl_error($ch);
        curl_close($ch);

        $duracao = (int) round((microtime(true) - $inicio) * 1000);

        if ($raw === false) {
            // Sem resposta: NÃO é o mesmo que negada. O motor precisa dessa
            // distinção — timeout pode significar autorização aprovada do
            // outro lado, e retentar às cegas duplica cobrança.
            return [
                'http' => 0, 'body' => [], 'traceKey' => null, 'sucesso' => false,
                'duracao_ms' => $duracao, 'erro_rede' => $curlEr ?: 'sem resposta', 'raw' => '',
            ];
        }

        $body = json_decode((string) $raw, true);
        if (!is_array($body)) $body = [];

        return [
            'http'       => $http,
            'body'       => $body,
            'traceKey'   => $body['traceKey'] ?? null,
            'sucesso'    => $http >= 200 && $http < 300 && !empty($body['success']),
            'duracao_ms' => $duracao,
            'erro_rede'  => null,
            'raw'        => mb_substr((string) $raw, 0, 4000),
        ];
    }

    /** Primeira mensagem de errors[] do envelope, para log e diagnóstico. */
    public static function primeiroErro(array $body): string
    {
        $e = $body['errors'][0] ?? null;
        if (is_array($e)) {
            $msg   = (string) ($e['message'] ?? 'erro sem mensagem');
            $codigo = isset($e['errorCode']) ? ' [errorCode ' . $e['errorCode'] . ']' : '';
            $campo  = !empty($e['field']) ? ' (' . $e['field'] . ')' : '';
            return $msg . $codigo . $campo;
        }
        return is_string($e) && $e !== '' ? $e : 'sem detalhe';
    }

    /** exp do JWT, sem validar assinatura (só para saber quando renovar). */
    private static function expiracaoJwt(string $jwt): ?int
    {
        $p = explode('.', $jwt);
        if (count($p) !== 3) return null;
        $payload = json_decode((string) base64_decode(strtr($p[1], '-_', '+/')), true);
        $exp = is_array($payload) ? ($payload['exp'] ?? null) : null;
        return is_numeric($exp) ? (int) $exp : null;
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
