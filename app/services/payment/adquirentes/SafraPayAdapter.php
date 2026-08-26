<?php
declare(strict_types=1);

/**
 * app/services/payment/adquirentes/SafraPayAdapter.php
 *
 * Traduz o vocabulário do nosso checkout para o da Safra Pay e devolve uma
 * PagamentoClassificacao — nunca um array cru da adquirente. Quem decide
 * rotear é o motor; este arquivo só sabe falar Safra.
 *
 * ENDPOINT DE CARTÃO — captura automática:
 *   POST {gateway}/v2/charge/authorization
 *   Autoriza E captura numa única chamada; o gateway força capture=true.
 *   Para o modo "autoriza → antifraude → captura" existem pré-autorização e
 *   PUT /v2/charge/capture/{chargeId} (ainda não implementados aqui).
 *
 * COMO A SAFRA SINALIZA RECUSA — importa muito:
 *   Recusa de emissor vem em HTTP 200 com success:true, dentro de
 *   charge.transactions[].transactionStatus = "Denied". HTTP 400 é SEMPRE
 *   validação nossa ou configuração do merchant (a tabela "Erros comuns (400)"
 *   da doc não lista nenhuma recusa de emissor). É por isso que o
 *   PagamentoErroClassifier pode liberar fallback no 400 e nunca no 200.
 *
 * PCI: `temporaryCardToken` é o caminho sem PAN no backend — o browser
 * tokeniza e manda um token de 15 minutos. `cartao` com PAN existe aqui para
 * o caso de já haver escopo PCI; o redator garante que nem PAN nem CVV vão
 * para log em nenhum dos dois casos.
 *
 * SANDBOX: o simulador das bandeiras só responde das 09:30 às 17:30
 * (Brasília). Fora disso a autorização falha por indisponibilidade — não é
 * erro de integração.
 */
class SafraPayAdapter
{
    // ── Enumeradores (doc: /primeiros-passos#enumeradores) ──────────────────
    private const BRAND = [
        'undefined'  => 0,
        'visa'       => 1,
        'mastercard' => 2,
        'amex'       => 3,
        'elo'        => 4,
    ];

    private const PAYMENT_TYPE_CREDITO = 2;   // 1=Debit 2=Credit 3=Voucher 4=Boleto 8=Pix

    // InstallmentType — 0=None(à vista) 1=Merchant(sem juros) 2=Issuer(com juros)
    private const INSTALLMENT_NONE     = 0;
    private const INSTALLMENT_MERCHANT = 1;
    private const INSTALLMENT_ISSUER   = 2;
    private const DOCUMENT_TYPE_CPF    = 1;   // 1=Cpf 2=Cnpj 3=Passport
    private const DOCUMENT_TYPE_CNPJ   = 2;

    /**
     * PaymentSource. A doc de autorização exemplifica `source: 1` chamando-o
     * de "e-commerce de terceiros", mas a tabela PaymentSource diz 1=Gateway
     * e 8=ThirdPartyEcommerce. Configurável por isso — o padrão segue o
     * exemplo oficial da própria página de autorização.
     */
    private const SOURCE_PADRAO = 1;

    /** O exemplo oficial do boleto usa 7; cartao e Pix usam 1. */
    private const SOURCE_BOLETO = 7;

    private SafraPayClient $client;
    private int $source;

    public function __construct(?SafraPayClient $client = null, int $source = 0)
    {
        $this->client = $client ?? new SafraPayClient();
        $this->source = $source > 0 ? $source : self::SOURCE_PADRAO;
    }

    public function codigo(): string
    {
        return 'safrapay';
    }

    public function configurado(): bool
    {
        return $this->client->configurado();
    }

    // =========================================================================
    // CARTÃO DE CRÉDITO
    // =========================================================================

    /**
     * Autoriza e captura em uma chamada.
     *
     * @param array $d Estrutura normalizada:
     *   order_id_loja        string  (vira merchantChargeId)
     *   tentativa_ref        string  (vira merchantTransactionId — ÚNICO por tentativa)
     *   valor_centavos       int
     *   parcelas             int
     *   session_id           string  (antifraude)
     *   ip_cliente           string  (antifraude)
     *   descricao_fatura     string  (softDescriptor)
     *   cliente              array   nome,email,documento,telefone,endereco[]
     *   cartao               array   numero,cvv,titular,documento_titular,validade_mes,validade_ano,bandeira
     *   token_temporario     string  (alternativa ao cartao — sem PAN no backend)
     *   metadata             array   pares chave=>valor
     */
    public function autorizarCartao(array $d): PagamentoClassificacao
    {
        $payload = $this->montarPayloadCartao($d);

        $resp = $this->client->chamar('POST', '/v2/charge/authorization', $payload);

        // Camada de transporte primeiro: rede, 5xx, timeout, 4xx de contrato.
        // Devolve null quando a resposta chegou íntegra e precisa ser lida.
        $c = PagamentoErroClassifier::porTransporte($resp);
        if ($c !== null) {
            $c->mensagemAdquirente ??= SafraPayClient::primeiroErro($resp['body'] ?? []);
            return $c;
        }

        $charge = $resp['body']['charge'] ?? [];

        // Envelope válido mas sem charge: contrato inesperado. Não é recusa —
        // tratar como técnico, e o log carrega o traceKey para o suporte.
        if (!is_array($charge) || $charge === []) {
            $c = new PagamentoClassificacao();
            $c->porta              = PagamentoClassificacao::ERRO_TECNICO;
            $c->classeErro         = 'resposta_inesperada';
            $c->podeCairParaOutra  = true;
            $c->httpStatus         = (int) $resp['http'];
            $c->duracaoMs          = (int) $resp['duracao_ms'];
            $c->traceKey           = $resp['traceKey'] ?? null;
            $c->mensagemAdquirente = SafraPayClient::primeiroErro($resp['body'] ?? []);
            return $c;
        }

        return PagamentoErroClassifier::porAutorizacaoSafra($charge, $resp);
    }

    /**
     * Consulta uma cobrança. É o que resolve o caso `incerto`: depois de um
     * timeout, perguntar à Safra o que aconteceu antes de tentar outra
     * adquirente — sem isto, retentar duplica a cobrança.
     */
    public function consultar(string $chargeId): PagamentoClassificacao
    {
        $resp = $this->client->chamar('GET', '/v2/charge/' . rawurlencode($chargeId));

        $c = PagamentoErroClassifier::porTransporte($resp);
        if ($c !== null) return $c;

        $charge = $resp['body']['charge'] ?? [];
        if (!is_array($charge) || $charge === []) {
            $c = new PagamentoClassificacao();
            $c->porta      = PagamentoClassificacao::ERRO_TECNICO;
            $c->classeErro = 'consulta_sem_charge';
            $c->traceKey   = $resp['traceKey'] ?? null;
            return $c;
        }

        return PagamentoErroClassifier::porAutorizacaoSafra($charge, $resp);
    }

    // =========================================================================
    // PIX
    // =========================================================================

    /**
     * Cria uma cobrança Pix. Devolve porta PENDENTE com o QR code — o
     * pagamento em si chega depois, por webhook.
     */
    public function criarPix(array $d): PagamentoClassificacao
    {
        $valor = (int) ($d['valor_centavos'] ?? 0);
        if ($valor <= 0) {
            throw new InvalidArgumentException('Safra Pay: valor_centavos deve ser maior que zero.');
        }

        $charge = [
            'merchantChargeId' => (string) ($d['order_id_loja'] ?? ''),
            'source'           => $this->source,
            'transactions'     => [[
                'amount'                => $valor,
                'merchantTransactionId' => (string) ($d['tentativa_ref'] ?? ''),
            ]],
        ];
        if (!empty($d['cliente'])) {
            $charge['customer'] = $this->montarCliente($d['cliente']);
        }

        return $this->criarCobranca('/v2/charge/pix', $charge, $d, 'pix');
    }

    // =========================================================================
    // BOLETO
    // =========================================================================

    /**
     * Cria um boleto. `deadline` (vencimento) é do CHARGE, não da transação.
     * O exemplo oficial usa source 7 aqui e 1 no cartão/Pix — configurável
     * por isso.
     */
    public function criarBoleto(array $d): PagamentoClassificacao
    {
        $valor = (int) ($d['valor_centavos'] ?? 0);
        if ($valor <= 0) {
            throw new InvalidArgumentException('Safra Pay: valor_centavos deve ser maior que zero.');
        }

        $transacao = [
            'amount'                => $valor,
            'merchantTransactionId' => (string) ($d['tentativa_ref'] ?? ''),
        ];
        if (!empty($d['instrucoes'])) {
            $transacao['instructions'] = self::apenasAscii((string) $d['instrucoes']);
        }

        $charge = [
            'merchantChargeId' => (string) ($d['order_id_loja'] ?? ''),
            'deadline'         => (string) ($d['vencimento'] ?? date('Y-m-d', strtotime('+3 days'))),
            'source'           => (int) ($d['source_boleto'] ?? self::SOURCE_BOLETO),
            'transactions'     => [$transacao],
        ];

        // Boleto exige endereço e entityType do sacado.
        if (!empty($d['cliente'])) {
            $cli = $this->montarCliente($d['cliente']);
            $doc = (string) ($cli['document'] ?? '');
            $cli['entityType']  = strlen($doc) > 11 ? 2 : 1;   // 1=PF 2=PJ
            $charge['customer'] = $cli;
        }

        return $this->criarCobranca('/v2/charge/boleto', $charge, $d, 'boleto');
    }

    private function criarCobranca(string $recurso, array $charge, array $d, string $metodo): PagamentoClassificacao
    {
        $payload = ['charge' => $charge];
        if (!empty($d['ip_cliente'])) {
            $payload['remoteIp'] = (string) $d['ip_cliente'];
        }

        $resp = $this->client->chamar('POST', $recurso, $payload);

        $c = PagamentoErroClassifier::porTransporte($resp);
        if ($c !== null) {
            $c->mensagemAdquirente ??= SafraPayClient::primeiroErro($resp['body'] ?? []);
            return $c;
        }

        $chargeResp = $resp['body']['charge'] ?? [];
        if (!is_array($chargeResp) || $chargeResp === []) {
            $c = new PagamentoClassificacao();
            $c->porta              = PagamentoClassificacao::ERRO_TECNICO;
            $c->classeErro         = 'resposta_inesperada';
            $c->podeCairParaOutra  = true;
            $c->httpStatus         = (int) $resp['http'];
            $c->traceKey           = $resp['traceKey'] ?? null;
            $c->mensagemAdquirente = SafraPayClient::primeiroErro($resp['body'] ?? []);
            return $c;
        }

        return PagamentoErroClassifier::porCriacaoCobranca($chargeResp, $resp, $metodo);
    }

    // =========================================================================
    // ESTORNO E CANCELAMENTO
    // =========================================================================

    /**
     * Estorna (D+0) ou cancela (D+N) — a Safra usa o MESMO endpoint para os
     * dois; a diferença é operacional, decidida pelo estado da transação.
     *
     * @param int|null $valorCentavos null = total. Parcial só vale em D+1+ no
     *                 cartão; em Pix, a qualquer momento após a confirmação.
     * @param bool $porAntifraude Marca a cobrança como negada por antifraude
     *                 (NotAuthorized/Denied) em vez de Canceled. É o que o nó
     *                 de antifraude usa quando a ClearSale reprova — preserva
     *                 o motivo correto no histórico da adquirente.
     */
    public function cancelar(string $chargeId, ?int $valorCentavos = null, bool $porAntifraude = false): PagamentoClassificacao
    {
        $corpo = [];
        if ($valorCentavos !== null && $valorCentavos >= 1) {
            $corpo['amount'] = $valorCentavos;
        }
        if ($porAntifraude) {
            $corpo['antifraudCancel'] = true;
        }

        $resp = $this->client->chamar('PUT', '/v2/charge/cancelation/' . rawurlencode($chargeId), $corpo);

        $c = PagamentoErroClassifier::porTransporte($resp);
        if ($c !== null) {
            $c->mensagemAdquirente ??= SafraPayClient::primeiroErro($resp['body'] ?? []);
            return $c;
        }

        $body      = $resp['body'];
        $chargeRet = $body['charge'] ?? [];
        $tx        = $chargeRet['transactions'][0] ?? [];
        $status    = (string) ($tx['transactionStatus'] ?? ($chargeRet['chargeStatus'] ?? ''));

        $c = new PagamentoClassificacao();
        $c->httpStatus = (int) $resp['http'];
        $c->duracaoMs  = (int) $resp['duracao_ms'];
        $c->traceKey   = $resp['traceKey'] ?? null;
        $c->chargeId   = $chargeRet['id'] ?? $chargeId;

        if (!empty($body['canceled']) || $status === 'Canceled') {
            $c->porta           = PagamentoClassificacao::APROVADO;
            $c->classeErro      = 'cancelado';
            $c->mensagemCliente = 'Cancelamento concluído.';
            return $c;
        }

        // ATENÇÃO: HTTP 200 com canceled:false e PendingCancel NÃO é erro — o
        // pedido foi aceito e a adquirente ainda processa (D+N). Tratar como
        // falha faria o sistema pedir o cancelamento de novo.
        if ($status === 'PendingCancel') {
            $c->porta                = PagamentoClassificacao::PENDENTE;
            $c->classeErro           = 'cancelamento_pendente';
            $c->cancelamentoPendente = true;
            $c->mensagemCliente      = 'Cancelamento solicitado. A confirmação chega em instantes.';
            return $c;
        }

        $c->porta              = PagamentoClassificacao::ERRO_TECNICO;
        $c->classeErro         = 'cancelamento_recusado';
        $c->mensagemAdquirente = SafraPayClient::primeiroErro($body) . ' | status: ' . ($status ?: '-');
        $c->mensagemCliente    = 'Não foi possível cancelar a cobrança.';
        return $c;
    }


    // =========================================================================
    // MONTAGEM DO PAYLOAD
    // =========================================================================

    private function montarPayloadCartao(array $d): array
    {
        $valor    = (int) ($d['valor_centavos'] ?? 0);
        $parcelas = max(1, (int) ($d['parcelas'] ?? 1));

        if ($valor <= 0) {
            throw new InvalidArgumentException('Safra Pay: valor_centavos deve ser maior que zero.');
        }

        // installmentType é OBRIGATÓRIO junto com installmentNumber > 1 —
        // sem ele a Safra devolve errorCode 135 "Informações de parcelamento
        // inválidas", mesmo com o número de parcelas dentro do limite.
        //   0 None     à vista
        //   1 Merchant parcelado pelo lojista  (sem juros — a loja absorve)
        //   2 Issuer   parcelado pelo emissor  (com juros — o cliente paga)
        // O default espelha pgto_metodos.parcelas_sem_juros: até o limite
        // configurado é Merchant, acima disso é Issuer.
        $tipoParcelamento = match ((string) ($d['parcelamento_tipo'] ?? '')) {
            'lojista' => self::INSTALLMENT_MERCHANT,
            'emissor' => self::INSTALLMENT_ISSUER,
            default   => $parcelas <= 1
                ? self::INSTALLMENT_NONE
                : ($parcelas <= (int) ($d['parcelas_sem_juros'] ?? 1)
                    ? self::INSTALLMENT_MERCHANT
                    : self::INSTALLMENT_ISSUER),
        };

        $transacao = [
            'paymentType'           => self::PAYMENT_TYPE_CREDITO,
            'amount'                => $valor,
            'installmentNumber'     => $parcelas,
            'installmentType'       => $tipoParcelamento,
            'merchantTransactionId' => (string) ($d['tentativa_ref'] ?? ''),
        ];

        $descritor = self::softDescriptor((string) ($d['descricao_fatura'] ?? ''));
        if ($descritor !== '') {
            $transacao['softDescriptor'] = $descritor;
        }

        // Caminho sem PAN no backend (checkout transparente). Preferencial.
        if (!empty($d['token_temporario'])) {
            $transacao['temporaryCardToken'] = (string) $d['token_temporario'];
            // O CVV não fica guardado no token temporário; se o fluxo o
            // capturar de novo, vai aqui — e nunca em log.
            if (!empty($d['cartao']['cvv'])) {
                $transacao['card'] = ['cvv' => (string) $d['cartao']['cvv']];
            }
        } elseif (!empty($d['cartao_id'])) {
            // Cartão do cofre da Safra.
            $transacao['card'] = ['id' => (string) $d['cartao_id']];
        } else {
            $transacao['card'] = $this->montarCartao($d['cartao'] ?? [], $d['cliente'] ?? []);
        }

        $charge = [
            'merchantChargeId' => (string) ($d['order_id_loja'] ?? ''),
            'transactions'     => [$transacao],
            'source'           => $this->source,
        ];

        // sessionId e remoteIp são obrigatórios com antifraude ativo no
        // merchant. Enviar sempre evita um 400 que só apareceria no dia em
        // que o antifraude fosse ligado.
        if (!empty($d['session_id'])) $charge['sessionId'] = (string) $d['session_id'];

        if (!empty($d['cliente_id_gateway'])) {
            $charge['customer'] = ['id' => (string) $d['cliente_id_gateway']];
        } elseif (!empty($d['cliente'])) {
            $charge['customer'] = $this->montarCliente($d['cliente']);
        }

        if (!empty($d['metadata']) && is_array($d['metadata'])) {
            // Request usa lista de {key,value}; a resposta devolve objeto.
            $charge['metadata'] = array_map(
                static fn($k, $v): array => ['key' => (string) $k, 'value' => (string) $v],
                array_keys($d['metadata']),
                array_values($d['metadata'])
            );
        }

        $payload = ['charge' => $charge];
        if (!empty($d['ip_cliente'])) $payload['remoteIp'] = (string) $d['ip_cliente'];

        return $payload;
    }

    private function montarCartao(array $cartao, array $cliente): array
    {
        $numero = preg_replace('/\D/', '', (string) ($cartao['numero'] ?? '')) ?? '';
        if ($numero === '') {
            throw new InvalidArgumentException('Safra Pay: cartão sem número e sem token.');
        }

        $out = [
            'cardNumber'      => $numero,
            'cvv'             => (string) ($cartao['cvv'] ?? ''),
            'brand'           => self::brandId((string) ($cartao['bandeira'] ?? ''), $numero),
            'cardholderName'  => self::apenasAscii((string) ($cartao['titular'] ?? '')),
            'expirationMonth' => (int) ($cartao['validade_mes'] ?? 0),
            'expirationYear'  => self::ano4((string) ($cartao['validade_ano'] ?? '')),
        ];

        $doc = preg_replace('/\D/', '', (string) ($cartao['documento_titular'] ?? $cliente['documento'] ?? '')) ?? '';
        if ($doc !== '') $out['cardholderDocument'] = $doc;

        // Exigido pelo antifraude quando o cartão não vem do cofre.
        $end = $cartao['endereco_cobranca'] ?? $cliente['endereco'] ?? null;
        if (is_array($end) && $end !== []) $out['billingAddress'] = self::montarEndereco($end);

        return $out;
    }

    private function montarCliente(array $c): array
    {
        $doc = preg_replace('/\D/', '', (string) ($c['documento'] ?? '')) ?? '';

        $out = [
            'name'         => self::apenasAscii((string) ($c['nome'] ?? '')),
            'email'        => (string) ($c['email'] ?? ''),
            'document'     => $doc,
            'documentType' => strlen($doc) > 11 ? self::DOCUMENT_TYPE_CNPJ : self::DOCUMENT_TYPE_CPF,
        ];

        $fone = preg_replace('/\D/', '', (string) ($c['telefone'] ?? '')) ?? '';
        if ($fone !== '') {
            // Sem DDI: a Safra separa DDD e número.
            $fone = strlen($fone) > 11 && str_starts_with($fone, '55') ? substr($fone, 2) : $fone;
            $out['phone'] = [
                'type'        => 5,     // 5 = Mobile
                // countryCode é OBRIGATÓRIO e não aparece no exemplo da doc —
                // sem ele a autorização volta 400 "Propriedade 'CountryCode'
                // está ausente", mesmo com todo o resto correto.
                'countryCode' => '55',
                'areaCode'    => substr($fone, 0, 2),
                // `number`, NÃO `phoneNumber` — este devolve 400
                // "Propriedade 'Number' está ausente". Ambos os nomes foram
                // testados contra o sandbox; só `number` é aceito.
                'number'      => substr($fone, 2),
            ];
        }

        if (!empty($c['endereco']) && is_array($c['endereco'])) {
            $out['address'] = self::montarEndereco($c['endereco']);
        }

        return $out;
    }

    private static function montarEndereco(array $e): array
    {
        return [
            'street'       => self::apenasAscii((string) ($e['logradouro'] ?? $e['street'] ?? '')),
            'number'       => (string) ($e['numero'] ?? $e['number'] ?? 'S/N'),
            'complement'   => self::apenasAscii((string) ($e['complemento'] ?? $e['complement'] ?? '')),
            'neighborhood' => self::apenasAscii((string) ($e['bairro'] ?? $e['neighborhood'] ?? '')),
            'city'         => self::apenasAscii((string) ($e['cidade'] ?? $e['city'] ?? '')),
            'state'        => strtoupper(substr((string) ($e['uf'] ?? $e['state'] ?? ''), 0, 2)),
            'country'      => 'BR',
            'zipCode'      => preg_replace('/\D/', '', (string) ($e['cep'] ?? $e['zipCode'] ?? '')) ?? '',
        ];
    }

    // =========================================================================
    // NORMALIZAÇÃO
    // =========================================================================

    /**
     * softDescriptor aceita SOMENTE /^[a-zA-Z0-9*]+$/ — sem espaço e sem
     * acento. Mandar "Loja X" derruba a autorização inteira num 400, então a
     * limpeza é obrigatória, não cosmética.
     */
    public static function softDescriptor(string $texto): string
    {
        $t = self::apenasAscii($texto);
        $t = preg_replace('/[^a-zA-Z0-9*]/', '', $t) ?? '';
        return substr($t, 0, 22);
    }

    /** Bandeira pelo nome; se não reconhecer, deduz pelo BIN do número. */
    public static function brandId(string $bandeira, string $numero = ''): int
    {
        $b = strtolower(preg_replace('/[^a-z]/i', '', $bandeira) ?? '');
        $b = match ($b) {
            'master', 'mc'          => 'mastercard',
            'americanexpress', 'ae' => 'amex',
            default                 => $b,
        };
        if (isset(self::BRAND[$b])) return self::BRAND[$b];

        if ($numero !== '') {
            if (str_starts_with($numero, '4'))                       return self::BRAND['visa'];
            if (preg_match('/^3[47]/', $numero))                     return self::BRAND['amex'];
            if (preg_match('/^(5[1-5]|2[2-7])/', $numero))           return self::BRAND['mastercard'];
            if (preg_match('/^(4011|4312|4389|5041|627780|6363)/', $numero)) return self::BRAND['elo'];
        }
        return self::BRAND['undefined'];
    }

    /** "30" ou "2030" → 2030. */
    private static function ano4(string $ano): int
    {
        $a = preg_replace('/\D/', '', $ano) ?? '';
        if ($a === '') return 0;
        return strlen($a) <= 2 ? 2000 + (int) $a : (int) $a;
    }

    private static function apenasAscii(string $t): string
    {
        $conv = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t);
        if ($conv !== false) $t = $conv;
        return trim(preg_replace('/[^\x20-\x7E]/', '', $t) ?? $t);
    }

    // =========================================================================
    // REDAÇÃO — nada de PAN/CVV em log
    // =========================================================================

    /**
     * Versão do payload segura para gravar em pgto_tentativas.request_json.
     * PAN vira BIN+últimos 4; CVV e token temporário somem por completo.
     */
    public static function redigir(array $payload): array
    {
        $tx = &$payload['charge']['transactions'];
        if (!is_array($tx)) return $payload;

        foreach ($tx as &$t) {
            if (!empty($t['temporaryCardToken'])) $t['temporaryCardToken'] = '[REDIGIDO]';
            if (!isset($t['card']) || !is_array($t['card'])) continue;

            if (!empty($t['card']['cardNumber'])) {
                $n = (string) $t['card']['cardNumber'];
                $t['card']['cardNumber'] = strlen($n) >= 10
                    ? substr($n, 0, 6) . str_repeat('*', max(0, strlen($n) - 10)) . substr($n, -4)
                    : '[REDIGIDO]';
            }
            if (isset($t['card']['cvv'])) $t['card']['cvv'] = '[REDIGIDO]';
        }
        unset($t, $tx);

        return $payload;
    }
}
