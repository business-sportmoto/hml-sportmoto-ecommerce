<?php
declare(strict_types=1);

/**
 * app/services/payment/antifraude/ClearSaleService.php
 *
 * Integração com a ClearSale (API Start / Behavior Analytics).
 *
 * ESTADO: escrito contra o contrato público da ClearSale, mas **ainda não
 * validado contra o ambiente real** — não há credenciais no .env. Enquanto
 * CLEARSALE_* estiver vazio, `configurado()` devolve false e o nó de
 * antifraude segue por um caminho seguro (ver AntifraudeExecutor), sem
 * inventar aprovação.
 *
 * Quando as credenciais chegarem, o roteiro é o mesmo que usei na Safra:
 * autenticar, disparar um caso de teste, e corrigir o contrato pelo que a
 * API responder de verdade. Presumir contrato de gateway sem validar é como
 * a integração da Comtele quase entrou no ar apontando para o host errado.
 *
 * CONTRATO PRESUMIDO:
 *   POST {base}/api/v1/authenticate  {Login, Password}      → {Token}
 *   POST {base}/api/v1/orders        Authorization: Token   → {Orders:[{Status, Score}]}
 *
 * CREDENCIAIS (.env):
 *   CLEARSALE_AMBIENTE  sandbox | prod
 *   CLEARSALE_LOGIN
 *   CLEARSALE_PASSWORD
 *   CLEARSALE_TIMEOUT   segundos (padrão 15)
 */
class ClearSaleService
{
    private const BASES = [
        'sandbox' => 'https://homologacao.clearsale.com.br/api',
        'prod'    => 'https://api.clearsale.com.br/api',
    ];

    /** Status da ClearSale → recomendação canônica do nosso domínio. */
    private const MAPA_STATUS = [
        'APA' => 'aprovado',    // aprovado automaticamente
        'APM' => 'aprovado',    // aprovado manualmente
        'AMA' => 'aprovado',    // aprovado por análise
        'RPM' => 'reprovado',   // reprovado manualmente
        'RPA' => 'reprovado',   // reprovado automaticamente
        'SUS' => 'revisao',     // suspenso para análise
        'AGU' => 'revisao',     // aguardando
        'ERR' => 'erro',
        'NVO' => 'revisao',     // novo, ainda sem parecer
        'FRD' => 'fraude',      // fraude confirmada
        'CAN' => 'reprovado',   // cancelado
    ];

    private string $ambiente;
    private string $login;
    private string $senha;
    private int    $timeout;

    private static ?array $tokenCache = null;

    public function __construct(string $login = '', string $senha = '', string $ambiente = '', int $timeout = 0)
    {
        $this->login = $login !== '' ? $login : self::cfg('CLEARSALE_LOGIN');
        $this->senha = $senha !== '' ? $senha : self::cfg('CLEARSALE_PASSWORD');

        $amb = strtolower($ambiente !== '' ? $ambiente : (self::cfg('CLEARSALE_AMBIENTE') ?: 'sandbox'));
        $this->ambiente = isset(self::BASES[$amb]) ? $amb : 'sandbox';

        $t = $timeout > 0 ? $timeout : (int) (self::cfg('CLEARSALE_TIMEOUT') ?: 15);
        // Teto baixo: isto roda com o cliente esperando no checkout.
        $this->timeout = max(5, min($t, 30));
    }

    public function configurado(): bool
    {
        return $this->login !== '' && $this->senha !== '';
    }

    public function ambiente(): string
    {
        return $this->ambiente;
    }

    /**
     * Envia o pedido para análise.
     *
     * NUNCA lança por falha da ClearSale — devolve status 'erro'. Antifraude
     * fora do ar não pode derrubar o checkout; quem decide o que fazer com o
     * erro é o fluxo.
     *
     * @param array $pedido codigo, valor_centavos, ip, cliente[], itens[], pagamento[]
     * @return array{status:string, score:?float, risco:string, analise_id:?string,
     *               motivo:?string, bruto:array}
     */
    public function analisar(array $pedido): array
    {
        if (!$this->configurado()) {
            return $this->falha('CLEARSALE_LOGIN/PASSWORD ausentes');
        }

        try {
            $token = $this->token();
        } catch (\Throwable $e) {
            return $this->falha('autenticação: ' . $e->getMessage());
        }

        $resp = $this->http('POST', '/v1/orders', $this->montarPedido($pedido), [
            'Authorization: Token ' . $token,
        ]);

        if ($resp['erro'] !== null) {
            return $this->falha('rede: ' . $resp['erro']);
        }
        if ($resp['http'] < 200 || $resp['http'] >= 300) {
            return $this->falha('HTTP ' . $resp['http'] . ' — ' . mb_substr($resp['raw'], 0, 160));
        }

        $ordem  = $resp['body']['Orders'][0] ?? ($resp['body']['orders'][0] ?? []);
        $codigo = strtoupper((string) ($ordem['Status'] ?? $ordem['status'] ?? ''));
        $score  = $ordem['Score'] ?? ($ordem['score'] ?? null);

        return [
            'status'     => self::MAPA_STATUS[$codigo] ?? 'revisao',
            'score'      => is_numeric($score) ? (float) $score : null,
            'risco'      => self::risco(is_numeric($score) ? (float) $score : null),
            'analise_id' => $ordem['ID'] ?? ($ordem['id'] ?? null),
            'motivo'     => $codigo !== '' ? 'ClearSale: ' . $codigo : null,
            'bruto'      => $resp['body'],
        ];
    }

    /**
     * Score da ClearSale → faixa de risco.
     *
     * Na escala deles, quanto MAIOR o score, maior o risco — o inverso do
     * nosso score de cliente. Trocar os dois é o erro fácil aqui, e ele
     * aprovaria justamente os pedidos mais arriscados.
     */
    public static function risco(?float $score): string
    {
        if ($score === null) return 'medio';
        if ($score >= 70)    return 'alto';
        if ($score >= 40)    return 'medio';
        return 'baixo';
    }

    // =========================================================================

    private function token(): string
    {
        $chave = $this->ambiente . ':' . substr(hash('sha256', $this->login), 0, 12);

        if (self::$tokenCache
            && self::$tokenCache['chave'] === $chave
            && self::$tokenCache['expira'] > time() + 60) {
            return self::$tokenCache['token'];
        }

        $r = $this->http('POST', '/v1/authenticate', [
            'Login'    => $this->login,
            'Password' => $this->senha,
        ], []);

        $token = (string) ($r['body']['Token'] ?? $r['body']['token'] ?? '');
        if ($r['http'] !== 200 || $token === '') {
            throw new RuntimeException('ClearSale recusou a autenticação (HTTP ' . $r['http'] . ')');
        }

        self::$tokenCache = ['chave' => $chave, 'token' => $token, 'expira' => time() + 1800];
        return $token;
    }

    /** Traduz o nosso pedido para o formato da ClearSale. */
    private function montarPedido(array $p): array
    {
        $cli = $p['cliente'] ?? [];
        $doc = preg_replace('/\D/', '', (string) ($cli['documento'] ?? '')) ?? '';

        $itens = [];
        foreach ($p['itens'] ?? [] as $i) {
            $itens[] = [
                'ID'        => (string) ($i['sku'] ?? $i['id'] ?? ''),
                'Name'      => mb_substr((string) ($i['nome'] ?? ''), 0, 120),
                'ItemValue' => round(((int) ($i['valor_centavos'] ?? 0)) / 100, 2),
                'Quantity'  => (int) ($i['quantidade'] ?? 1),
            ];
        }

        return ['Orders' => [[
            'ID'          => (string) ($p['codigo'] ?? ''),
            'Date'        => date('c'),
            'TotalOrder'  => round(((int) ($p['valor_centavos'] ?? 0)) / 100, 2),
            'IP'          => (string) ($p['ip'] ?? ''),
            'Billing'     => [
                'ID'       => $doc,
                'Type'     => strlen($doc) > 11 ? 2 : 1,   // 1 PF, 2 PJ
                'Name'     => (string) ($cli['nome'] ?? ''),
                'Email'    => (string) ($cli['email'] ?? ''),
                'Document' => $doc,
            ],
            'Payments'    => $p['pagamento'] ?? [],
            'Items'       => $itens,
        ]]];
    }

    /** @return array{http:int, body:array, raw:string, erro:?string} */
    private function http(string $metodo, string $recurso, array $payload, array $headers): array
    {
        $url = self::BASES[$this->ambiente] . $recurso;
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $metodo,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 6,
        ]);

        $raw  = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['http' => 0, 'body' => [], 'raw' => '', 'erro' => $err ?: 'sem resposta'];
        }

        $body = json_decode((string) $raw, true);
        return [
            'http' => $http,
            'body' => is_array($body) ? $body : [],
            'raw'  => mb_substr((string) $raw, 0, 2000),
            'erro' => null,
        ];
    }

    private function falha(string $motivo): array
    {
        LogService::error('Falha no antifraude', ['detalhe' => $motivo], 'pagamento');
        return ['status' => 'erro', 'score' => null, 'risco' => 'medio',
                'analise_id' => null, 'motivo' => $motivo, 'bruto' => []];
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
