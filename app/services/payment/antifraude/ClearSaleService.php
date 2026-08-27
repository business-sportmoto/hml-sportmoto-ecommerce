<?php
declare(strict_types=1);

/**
 * app/services/payment/antifraude/ClearSaleService.php
 *
 * Integração com a ClearSale — Total / Total Garantido.
 *
 * Contrato conferido contra a documentação oficial (março/2026):
 *   POST {base}/v1/authenticate   {name, password}         → {Token, ExpirationDate}
 *   POST {base}/v1/orders/        Authorization: Bearer …  → {packageID, orders:[…]}
 *
 * Bases (o `/api` só existe em homologação — não é sufixo, é caminho do host):
 *   sandbox  https://homologacao.clearsale.com.br/api
 *   prod     https://api.clearsale.com.br
 *
 * O PEDIDO NÃO VEM EMBRULHADO. É um objeto único, camelCase, na raiz do
 * corpo — não um array `Orders`. A resposta é que traz `orders[]`.
 *
 * O QUE LIGA ISTO AO NAVEGADOR:
 *   `sessionID` é obrigatório e é a MESMA string que o SDK de fingerprint
 *   recebeu na página (ver ClearSaleFingerprint). Sem essa correspondência a
 *   ClearSale analisa o pedido sem nenhum dado de dispositivo — a consulta é
 *   cobrada igual e vale muito menos.
 *
 * CREDENCIAIS (.env, nunca aqui):
 *   CLEARSALE_AMBIENTE  sandbox | prod
 *   CLEARSALE_LOGIN     usuário da API
 *   CLEARSALE_PASSWORD  senha da API
 *   CLEARSALE_APP_KEY   chave do fingerprint (usada no navegador)
 *   CLEARSALE_TIMEOUT   segundos (padrão 15)
 */
class ClearSaleService
{
    private const BASES = [
        'sandbox' => 'https://homologacao.clearsale.com.br/api',
        'prod'    => 'https://api.clearsale.com.br',
    ];

    /**
     * Status de análise → desfecho no nosso domínio.
     *
     * Cuidado com os pares parecidos: `APM` é aprovação manual (um analista
     * liberou), `AMA` é análise manual (ainda na fila deles). Tratar AMA como
     * aprovado libera mercadoria de pedido que a ClearSale ainda não julgou.
     */
    private const MAPA_STATUS = [
        'APA' => 'aprovado',    // aprovação automática por regra
        'APM' => 'aprovado',    // aprovação manual por analista
        'APP' => 'aprovado',    // aprovação por política
        'NVO' => 'revisao',     // recebido, ainda sem parecer
        'AMA' => 'revisao',     // na fila de análise manual deles
        'SUS' => 'revisao',     // suspensão manual — suspeita de fraude
        'FRD' => 'fraude',      // fraude confirmada
        'RPA' => 'reprovado',   // reprovado automaticamente
        'RPP' => 'reprovado',   // reprovado por política
        'RPM' => 'reprovado',   // reprovado sem suspeita (contato/CPF)
        'CAN' => 'reprovado',   // cancelado pelo cliente
    ];

    /**
     * Faixa de risco POR STATUS, não por score.
     *
     * A documentação não fixa a direção da escala do score, e errar o sentido
     * aprovaria justamente os pedidos mais arriscados. O status é categórico e
     * não tem essa ambiguidade. O score continua sendo gravado, para quando a
     * direção estiver confirmada com a ClearSale.
     */
    private const MAPA_RISCO = [
        'APA' => 'baixo', 'APM' => 'baixo', 'APP' => 'baixo',
        'NVO' => 'medio', 'AMA' => 'medio', 'SUS' => 'alto',
        'FRD' => 'alto',  'RPA' => 'alto',  'RPP' => 'alto',
        'RPM' => 'alto',  'CAN' => 'alto',
    ];

    /**
     * Forma de pagamento → código da ClearSale.
     * Só o cartão está confirmado; hoje é o único método cujo fluxo consulta
     * antifraude (Pix e boleto encerram antes, sem análise).
     */
    private const MAPA_PAGAMENTO = ['cartao_credito' => 1, 'boleto' => 2, 'pix' => 7];

    private string $ambiente;
    private string $login;
    private string $senha;
    private int    $timeout;

    /** Token vive ~horas; guardar por request evita reautenticar a cada pedido. */
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
     * erro é o fluxo desenhado no canvas.
     *
     * @param array $pedido codigo, session_id, valor_centavos, frete_centavos,
     *                      parcelas, metodo, ip, cliente[], entrega[], itens[],
     *                      cartao[]
     * @return array{status:string, score:?float, risco:string, analise_id:?string,
     *               codigo_status:?string, motivo:?string, bruto:array}
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

        $resp = $this->http('POST', '/v1/orders/', $this->montarPedido($pedido), [
            'Authorization: Bearer ' . $token,
        ]);

        if ($resp['erro'] !== null) {
            return $this->falha('rede: ' . $resp['erro']);
        }
        if ($resp['http'] < 200 || $resp['http'] >= 300) {
            return $this->falha('HTTP ' . $resp['http'] . ' — ' . mb_substr($resp['raw'], 0, 200));
        }

        return $this->interpretar(
            $resp['body']['orders'][0] ?? [],
            $resp['body'],
            (string) ($pedido['codigo'] ?? '')
        );
    }

    /**
     * Parecer bruto -> desfecho do nosso dominio.
     *
     * Serve tanto o envio (`orders[0]`) quanto a consulta (corpo na raiz):
     * os dois trazem os mesmos `status` e `score`.
     */
    private function interpretar(array $ordem, array $bruto, string $pedidoCodigo): array
    {
        $codigo = strtoupper((string) ($ordem['status'] ?? ''));
        $score  = $ordem['score'] ?? null;

        if ($codigo === '') {
            return $this->falha('resposta sem status: ' . mb_substr(json_encode($bruto) ?: '', 0, 200));
        }

        // Status fora do mapa e status novo do lado deles. Reter e o unico
        // desfecho seguro — aprovar um codigo desconhecido e aprovar no escuro.
        $conhecido = isset(self::MAPA_STATUS[$codigo]);
        if (!$conhecido) {
            LogService::warning('Status desconhecido da ClearSale', [
                'status' => $codigo, 'pedido' => $pedidoCodigo,
            ], 'pagamento');
        }

        return [
            'status'        => self::MAPA_STATUS[$codigo] ?? 'revisao',
            'score'         => is_numeric($score) ? (float) $score : null,
            'risco'         => self::MAPA_RISCO[$codigo] ?? 'medio',
            'analise_id'    => (string) ($bruto['packageID'] ?? '') ?: null,
            'codigo_status' => $codigo,
            'motivo'        => 'ClearSale: ' . $codigo
                             . ($conhecido ? '' : ' (codigo nao mapeado - retido)'),
            'bruto'         => $bruto,
        ];
    }

    /** Aguardando parecer: continua na fila, nao e desfecho. */
    public static function aguardandoParecer(?string $codigoStatus): bool
    {
        return in_array((string) $codigoStatus, ['NVO', 'AMA'], true);
    }

    /**
     * Parecer final de um pedido ja enviado.
     *
     * A ANALISE E ASSINCRONA. O envio sempre devolve `NVO` — recebido, sem
     * julgamento. O veredito so existe depois, e so chega por aqui (ou por
     * notificacao, que avisa que mudou mas nao diz para o que).
     *
     * Quem trata isso e o worker: enquanto o parecer nao vem, o pedido fica
     * retido na fila. Aprovar na hora seria aprovar sem analise nenhuma.
     *
     * @return array mesmo formato de analisar()
     */
    public function consultarStatus(string $codigoPedido): array
    {
        if (!$this->configurado()) {
            return $this->falha('CLEARSALE_LOGIN/PASSWORD ausentes');
        }
        if (trim($codigoPedido) === '') {
            return $this->falha('codigo do pedido vazio');
        }

        try {
            $token = $this->token();
        } catch (\Throwable $e) {
            return $this->falha('autenticacao: ' . $e->getMessage());
        }

        $resp = $this->http('GET', '/v1/orders/' . rawurlencode($codigoPedido) . '/status', null, [
            'Authorization: Bearer ' . $token,
        ]);

        if ($resp['erro'] !== null) {
            return $this->falha('rede: ' . $resp['erro']);
        }
        if ($resp['http'] === 404) {
            return $this->falha('pedido nao encontrado na ClearSale');
        }
        if ($resp['http'] < 200 || $resp['http'] >= 300) {
            return $this->falha('HTTP ' . $resp['http'] . ' - ' . mb_substr($resp['raw'], 0, 200));
        }

        return $this->interpretar($resp['body'], $resp['body'], $codigoPedido);
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
            'name'     => $this->login,
            'password' => $this->senha,
        ], []);

        $token = (string) ($r['body']['Token'] ?? $r['body']['token'] ?? '');
        if ($r['http'] !== 200 || $token === '') {
            throw new RuntimeException('ClearSale recusou a autenticação (HTTP ' . $r['http'] . ')');
        }

        // Eles mandam a validade; respeitar evita reautenticar à toa e evita
        // usar token vencido. Sem data legível, cai num prazo curto e seguro.
        $exp   = strtotime((string) ($r['body']['ExpirationDate'] ?? ''));
        $limite = ($exp && $exp > time()) ? $exp : time() + 1800;

        self::$tokenCache = ['chave' => $chave, 'token' => $token, 'expira' => $limite];
        return $token;
    }

    /** Traduz o nosso pedido para o corpo que a ClearSale espera. */
    private function montarPedido(array $p): array
    {
        $cli     = $p['cliente'] ?? [];
        $entrega = $p['entrega'] ?? [];
        $doc     = self::digitos((string) ($cli['documento'] ?? ''));
        $email   = (string) ($cli['email'] ?? '');

        $itens = [];
        foreach ($p['itens'] ?? [] as $i) {
            $itens[] = [
                'code'   => (string) ($i['sku'] ?? $i['id'] ?? ''),
                'name'   => mb_substr((string) ($i['nome'] ?? ''), 0, 120),
                'value'  => self::reais((int) ($i['valor_centavos'] ?? 0)),
                'amount' => max(1, (int) ($i['quantidade'] ?? 1)),
            ];
        }

        $pessoa = [
            'clientID'        => (string) ($p['cliente_id'] ?? $doc),
            'type'            => strlen($doc) > 11 ? 2 : 1,   // 1 PF, 2 PJ
            'primaryDocument' => $doc,
            'name'            => (string) ($cli['nome'] ?? ''),
            'email'           => $email,
            'address'         => self::endereco($cli['endereco'] ?? []),
            'phones'          => self::telefones((string) ($cli['telefone'] ?? '')),
        ];

        // Sem endereço de entrega separado, a cobrança serve — é o caso da
        // retirada e o campo é obrigatório do lado deles.
        $shipping = $entrega
            ? array_merge($pessoa, [
                'name'    => (string) ($entrega['nome'] ?? $cli['nome'] ?? ''),
                'address' => self::endereco($entrega),
                'price'   => self::reais((int) ($p['frete_centavos'] ?? 0)),
              ])
            : $pessoa;

        $total = (int) ($p['valor_centavos'] ?? 0);

        return [
            'code'                 => (string) ($p['codigo'] ?? ''),
            'sessionID'            => $this->sessionId($p),
            'date'                 => date('c'),
            'email'                => $email,
            'b2bB2c'               => 'B2C',
            'totalValue'           => self::reais($total),
            'itemValue'            => self::reais($total - (int) ($p['frete_centavos'] ?? 0)),
            'numberOfInstallments' => max(1, (int) ($p['parcelas'] ?? 1)),
            'ip'                   => (string) ($p['ip'] ?? ''),
            // 0 = novo, para eles analisarem. Outros códigos pulam a análise,
            // que é justamente o contrário do que queremos aqui.
            'status'               => 0,
            'country'              => 'Brasil',
            'billing'              => $pessoa,
            'shipping'             => $shipping,
            'payments'             => [$this->pagamento($p)],
            'items'                => $itens,
        ];
    }

    /**
     * O sessionID precisa casar com o que o navegador mandou ao fingerprint.
     *
     * Faltando, o pedido ainda é enviado — reter todo mundo porque o front
     * não gerou a sessão seria pior. Mas fica um aviso alto: a análise sai
     * cega para dispositivo, custando o mesmo e valendo menos.
     */
    private function sessionId(array $p): string
    {
        $sid = trim((string) ($p['session_id'] ?? ''));

        if ($sid === '' || mb_strlen($sid) < 6) {
            $sid = 'sem-fp-' . (string) ($p['codigo'] ?? bin2hex(random_bytes(6)));
            LogService::warning('Pedido enviado à ClearSale sem sessionID do fingerprint', [
                'pedido' => $p['codigo'] ?? null,
            ], 'pagamento');
        }

        return mb_substr($sid, 0, 128);
    }

    private function pagamento(array $p): array
    {
        $cartao = $p['cartao'] ?? [];
        $metodo = (string) ($p['metodo'] ?? 'cartao_credito');

        $pg = [
            'sequential'   => 1,
            'date'         => date('c'),
            'value'        => self::reais((int) ($p['valor_centavos'] ?? 0)),
            'type'         => self::MAPA_PAGAMENTO[$metodo] ?? 1,
            'installments' => max(1, (int) ($p['parcelas'] ?? 1)),
            'currency'     => 986,   // BRL, ISO 4217
        ];

        // Só o que a loja pode guardar: BIN, últimos 4, validade e titular.
        // O PAN completo nunca sai daqui — foi a premissa do projeto.
        if ($cartao) {
            $pg['card'] = array_filter([
                'bin'          => (string) ($cartao['bin'] ?? ''),
                'end'          => (string) ($cartao['ultimos4'] ?? ''),
                'validityDate' => (string) ($cartao['validade'] ?? ''),
                'ownerName'    => (string) ($cartao['titular'] ?? ''),
                'document'     => self::digitos((string) ($cartao['documento'] ?? '')),
                'nsu'          => (string) ($cartao['nsu'] ?? ''),
            ], static fn($v) => $v !== '');
        }

        return $pg;
    }

    private static function endereco(array $e): array
    {
        return [
            'street'                => (string) ($e['logradouro'] ?? $e['rua'] ?? ''),
            'number'                => (string) ($e['numero'] ?? ''),
            'additionalInformation' => (string) ($e['complemento'] ?? ''),
            'county'                => (string) ($e['bairro'] ?? ''),
            'city'                  => (string) ($e['cidade'] ?? ''),
            'state'                 => mb_strtoupper(mb_substr((string) ($e['uf'] ?? $e['estado'] ?? ''), 0, 2)),
            'zipcode'               => self::digitos((string) ($e['cep'] ?? '')),
            'country'               => 'Brasil',
        ];
    }

    /** "(11) 98888-7777" → [{type, ddi, ddd, number}] */
    private static function telefones(string $bruto): array
    {
        $d = self::digitos($bruto);
        if (strlen($d) < 10) return [];

        if (str_starts_with($d, '55') && strlen($d) > 11) {
            $d = substr($d, 2);
        }

        return [[
            'type'   => strlen($d) >= 11 ? 2 : 1,   // 2 celular, 1 fixo
            'ddi'    => 55,
            'ddd'    => (int) substr($d, 0, 2),
            'number' => (int) substr($d, 2),
        ]];
    }

    private static function reais(int $centavos): float
    {
        return round($centavos / 100, 2);
    }

    private static function digitos(string $v): string
    {
        return preg_replace('/\D/', '', $v) ?? '';
    }

    /** @return array{http:int, body:array, raw:string, erro:?string} */
    private function http(string $metodo, string $recurso, ?array $payload, array $headers): array
    {
        $url = self::BASES[$this->ambiente] . $recurso;
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $metodo,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 6,
        ];
        // GET com corpo vazio faz alguns WAF recusarem antes de chegar na API.
        if ($payload !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, $opts);

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
        return ['status' => 'erro', 'score' => null, 'risco' => 'medio', 'analise_id' => null,
                'codigo_status' => null, 'motivo' => $motivo, 'bruto' => []];
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
