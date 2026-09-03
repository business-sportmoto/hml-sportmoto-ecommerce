<?php
declare(strict_types=1);

/**
 * app/services/payment/adquirentes/CieloAdapter.php
 *
 * Cielo — API E-commerce 3.0.
 *
 * ESCRITO SÓ CONTRA DOCUMENTAÇÃO. A conta não tem sandbox aplicável, então
 * nada aqui foi validado contra a API real. O contrato levantado está em
 * `md/cielo-api-30.md`, com o link de cada página — quando algo divergir em
 * produção, é lá que se confere primeiro.
 *
 * Por causa disso, este adapter é mais desconfiado que o do Mercado Pago:
 *   - lê as duas grafias de cada campo que a doc mostra inconsistente
 *     (`PaymentId`/`Paymentid`, `QrCodeString`/`QrcodeBase64Image`);
 *   - registra a resposta inteira quando não reconhece o desfecho, em vez de
 *     adivinhar;
 *   - fecha o desconhecido como recusa de emissor, que é a leitura que NÃO
 *     retenta em outra adquirente.
 *
 * ⚠️ CARTÃO: esta API recebe o PAN no corpo da requisição — não há
 * tokenização no navegador. Rotear cartão para cá muda o escopo PCI da loja.
 * Pix e boleto não têm essa implicação. Ver o md.
 */
class CieloAdapter implements AdquirenteInterface
{
    private const BASE_PROD      = 'https://api.cieloecommerce.cielo.com.br';
    private const BASE_SANDBOX   = 'https://apisandbox.cieloecommerce.cielo.com.br';
    private const QUERY_PROD     = 'https://apiquery.cieloecommerce.cielo.com.br';
    private const QUERY_SANDBOX  = 'https://apiquerysandbox.cieloecommerce.cielo.com.br';

    /** Payment.Status — tabela da doc. */
    private const ST_NAO_FINALIZADO = 0;
    private const ST_AUTORIZADO     = 1;
    private const ST_PAGO           = 2;
    private const ST_NEGADO         = 3;
    private const ST_CANCELADO      = 10;
    private const ST_ESTORNADO      = 11;
    private const ST_PIX_GERADO     = 12;
    private const ST_ABORTADO       = 13;

    private const MENSAGEM = [
        PagamentoClassificacao::APROVADO     => 'Pagamento aprovado.',
        PagamentoClassificacao::PENDENTE     => 'Aguardando o pagamento.',
        PagamentoClassificacao::ERRO_TECNICO => 'Tivemos um problema ao processar. Tente novamente.',
        PagamentoClassificacao::INDISPONIVEL => 'Pagamento indisponível no momento. Tente outra forma.',
        PagamentoClassificacao::INCERTO      => 'Estamos confirmando seu pagamento. Avisaremos em instantes.',
    ];

    private string $merchantId;
    private string $merchantKey;
    private string $ambiente;
    private int    $timeout;
    private array  $config = [];

    public function __construct(
        ?string $merchantId = null,
        ?string $merchantKey = null,
        ?string $ambiente = null
    ) {
        $cred = PagamentoCredencialService::para('cielo');

        // merchant_id e api_key são os nomes das colunas; na Cielo eles
        // guardam MerchantId e MerchantKey.
        $this->merchantId  = (string) ($merchantId  ?? $cred['merchant_id']  ?? '');
        // O service normaliza todo segredo em `access_token`, seja qual for
        // o nome que a adquirente dá. Na Cielo, é o MerchantKey.
        $this->merchantKey = (string) ($merchantKey ?? $cred['access_token'] ?? '');
        $this->ambiente    = (string) ($ambiente    ?? (($cred['sandbox'] ?? true) ? 'sandbox' : 'producao'));
        $this->config      = (array)  ($cred['config'] ?? []);
        $this->timeout     = (int) (($this->opcao('timeout') ?: 0) ?: 30);
    }

    public function codigo(): string { return 'cielo'; }

    public function configurado(): bool
    {
        return $this->merchantId !== '' && $this->merchantKey !== '';
    }

    // =========================================================================
    // Silent Order Post — tokenização NO NAVEGADOR
    // =========================================================================

    /**
     * Credencial de 20 minutos para o script do Silent Order Post.
     *
     * É o que permite salvar o cartão no Cartão Protegido SEM o número passar
     * pelo nosso servidor: o script lê os inputs da página e posta direto na
     * Cielo. Duas chamadas, as duas daqui de dentro (o par OAuth2 é segredo
     * e nunca vai para o navegador):
     *
     *   1. OAuth2 client_credentials (Braspag)   → bearer
     *   2. POST /post/api/public/v2/accesstoken  → AccessToken do SOP
     *
     * O SOP é infraestrutura Braspag (dona da Cielo E-commerce): o OAuth2 e o
     * accesstoken vivem em `*.braspag.com.br` / `*.pagador.com.br`, e o script
     * com `provider: "cielo"` posta o cartão em `transaction.cieloecommerce`.
     * Está assim na doc; se a conta responder 401 no accesstoken, o suporte
     * da Cielo é quem libera o par OAuth2 de produção.
     *
     * @return array{accessToken:string, environment:string, provider:string}|null
     */
    public function sopAcesso(): ?array
    {
        $clientId = PagamentoCredencialService::envExtra('cielo', 'SOP_CLIENT_ID');
        $secret   = PagamentoCredencialService::envExtra('cielo', 'SOP_CLIENT_SECRET');

        if ($clientId === '' || $secret === '' || $this->merchantId === '') {
            LogService::warning('Cielo SOP sem credencial OAuth2', [
                'tem_client_id' => $clientId !== '', 'tem_secret' => $secret !== '',
            ], 'pagamento');
            return null;
        }

        $sandbox = $this->ambiente !== 'producao';
        $oauth   = $sandbox ? 'https://authsandbox.braspag.com.br/oauth2/token'
                            : 'https://auth.braspag.com.br/oauth2/token';
        $sop     = ($sandbox ? 'https://transactionsandbox.pagador.com.br'
                             : 'https://transaction.pagador.com.br')
                 . '/post/api/public/v2/accesstoken';

        // 1. bearer
        $r = $this->httpCru('POST', $oauth, 'grant_type=client_credentials', [
            'Authorization: Basic ' . base64_encode($clientId . ':' . $secret),
            'Content-Type: application/x-www-form-urlencoded',
        ]);
        $bearer = (string) ($r['body']['access_token'] ?? '');
        if ($r['erro'] !== null || $r['http'] !== 200 || $bearer === '') {
            LogService::error('Cielo SOP: OAuth2 recusou', [
                'http' => $r['http'], 'erro' => $r['erro'],
                'motivo' => mb_substr((string) ($r['body']['error_description'] ?? $r['raw']), 0, 200),
            ], 'pagamento');
            return null;
        }

        // 2. AccessToken do SOP
        $r = $this->httpCru('POST', $sop, '', [
            'Authorization: Bearer ' . $bearer,
            'MerchantId: ' . $this->merchantId,
            'Content-Type: application/json',
        ]);
        $token = (string) ($r['body']['AccessToken'] ?? '');
        if ($r['erro'] !== null || !in_array($r['http'], [200, 201], true) || $token === '') {
            LogService::error('Cielo SOP: accesstoken recusou', [
                'http' => $r['http'], 'erro' => $r['erro'],
                'motivo' => mb_substr((string) ($r['raw'] ?? ''), 0, 200),
            ], 'pagamento');
            return null;
        }

        return [
            'accessToken' => $token,
            'environment' => $sandbox ? 'sandbox' : 'production',
            'provider'    => 'cielo',
        ];
    }

    /** HTTP para hosts fora da API 3.0 (OAuth2/SOP), sem os headers Merchant*. */
    protected function httpCru(string $metodo, string $url, string $corpo, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $metodo,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $corpo,
        ]);
        $raw  = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $erro = curl_errno($ch) ? curl_error($ch) : null;
        curl_close($ch);

        return [
            'http' => $http, 'erro' => $erro,
            'raw'  => is_string($raw) ? $raw : '',
            'body' => is_string($raw) ? (json_decode($raw, true) ?: []) : [],
        ];
    }

    private function opcao(string $chave): mixed
    {
        return $this->config[$chave] ?? null;
    }

    private function base(): string
    {
        return $this->ambiente === 'producao' ? self::BASE_PROD : self::BASE_SANDBOX;
    }

    private function baseConsulta(): string
    {
        return $this->ambiente === 'producao' ? self::QUERY_PROD : self::QUERY_SANDBOX;
    }

    // =========================================================================
    // Pagamentos
    // =========================================================================

    public function autorizarCartao(array $d): PagamentoClassificacao
    {
        $cartao  = $d['cartao'] ?? [];
        $pan     = preg_replace('/\D/', '', (string) ($cartao['numero'] ?? ''));
        $cardRef = trim((string) ($d['card_ref'] ?? ''));

        // CARTÃO SALVO NO CARTÃO PROTEGIDO: cobra pelo CardToken (36 chars),
        // que o Silent Order Post gerou no navegador com enableTokenize. O
        // CVV é obrigatório na doc do cartão tokenizado — vem do checkout, a
        // cada compra, e não é guardado por ninguém.
        if ($cardRef !== '') {
            $cvv = preg_replace('/\D/', '', (string) ($d['cvv'] ?? ($cartao['cvv'] ?? '')));

            $pagamento = array_filter([
                'Type'           => 'CreditCard',
                'Amount'         => (int) ($d['valor_centavos'] ?? 0),
                'Currency'       => 'BRL',
                'Country'        => 'BRA',
                'Installments'   => max(1, (int) ($d['parcelas'] ?? 1)),
                'Interest'       => 'ByMerchant',
                'Capture'        => ($d['captura'] ?? '') !== 'manual',
                'Authenticate'   => false,
                'SoftDescriptor' => self::descritor($d),
                'CreditCard'     => array_filter([
                    'CardToken'    => $cardRef,
                    'SecurityCode' => $cvv,
                    'Brand'        => self::bandeiraParaCielo((string) ($d['bandeira'] ?? '')),
                ], static fn($v) => $v !== null && $v !== ''),
            ], static fn($v) => $v !== null && $v !== '');

            return $this->criar($d, $pagamento, 'cartao');
        }

        // ESTA API NÃO ACEITA TOKEN DE NAVEGADOR de outra adquirente. Se só
        // veio isso, não há como cobrar aqui — e mandar assim mesmo devolve
        // uma recusa sem sentido, gastando uma tentativa no emissor.
        if ($pan === '') {
            $c = $this->classificar(PagamentoClassificacao::ERRO_TECNICO, 'sem_dados_do_cartao');
            $c->mensagemAdquirente = 'A Cielo exige os dados do cartão ou um CardToken dela; só havia token de outra adquirente.';
            return $c;
        }

        [$mes, $ano] = self::validade((string) ($cartao['validade'] ?? ''));

        $pagamento = array_filter([
            'Type'           => 'CreditCard',
            'Amount'         => (int) ($d['valor_centavos'] ?? 0),
            'Currency'       => 'BRL',
            'Country'        => 'BRA',
            'Installments'   => max(1, (int) ($d['parcelas'] ?? 1)),
            'Interest'       => 'ByMerchant',
            // manual = pré-autoriza sem capturar. É o que o modo pre_captura
            // do antifraude precisa para reprovar sem custo de estorno.
            'Capture'        => ($d['captura'] ?? '') !== 'manual',
            'Authenticate'   => false,
            'SoftDescriptor' => self::descritor($d),
            'CreditCard'     => array_filter([
                'CardNumber'     => $pan,
                'Holder'         => (string) ($cartao['titular'] ?? ''),
                'ExpirationDate' => $mes . '/' . $ano,
                'SecurityCode'   => (string) ($cartao['cvv'] ?? ''),
                'Brand'          => self::bandeiraParaCielo((string) ($d['bandeira'] ?? $cartao['bandeira'] ?? '')),
                'SaveCard'       => false,
            ], static fn($v) => $v !== null && $v !== ''),
        ], static fn($v) => $v !== null && $v !== '');

        return $this->criar($d, $pagamento, 'cartao');
    }

    public function criarPix(array $d): PagamentoClassificacao
    {
        return $this->criar($d, [
            'Type'   => 'Pix',
            'Amount' => (int) ($d['valor_centavos'] ?? 0),
        ], 'pix');
    }

    public function criarBoleto(array $d): PagamentoClassificacao
    {
        $dias = max(1, (int) ($this->opcao('boleto_dias') ?: 3));

        // Provider depende do banco contratado — não existe padrão. Sem ele
        // configurado a Cielo recusa, e o motivo não é óbvio na mensagem.
        $provider = (string) ($this->opcao('boleto_provider') ?: '');

        // "Simulado" É SÓ DO SANDBOX. Em produção ele não existe, e a Cielo
        // recusaria com uma mensagem que não explica nada — o boleto pararia
        // de sair no dia da virada e ninguém ligaria uma coisa à outra.
        if ($this->ambiente === 'producao' && strcasecmp($provider, 'Simulado') === 0) {
            $c = $this->classificar(PagamentoClassificacao::INDISPONIVEL, 'boleto_provider_de_teste');
            $c->mensagemAdquirente = 'O boleto está com Provider "Simulado", que só vale no sandbox. '
                                   . 'Troque pelo banco contratado (ex.: Bradesco2).';
            LogService::error('Cielo: boleto com provider de sandbox em producao', [
                'pedido' => $d['order_id_loja'] ?? null,
            ], 'pagamento');
            return $c;
        }

        if ($provider === '') {
            $c = $this->classificar(PagamentoClassificacao::INDISPONIVEL, 'boleto_sem_provider');
            $c->mensagemAdquirente = 'Boleto da Cielo sem "Provider" configurado (ex.: Bradesco2).';
            LogService::error('Cielo: boleto sem provider configurado', [
                'pedido' => $d['order_id_loja'] ?? null,
            ], 'pagamento');
            return $c;
        }

        return $this->criar($d, array_filter([
            'Type'           => 'Boleto',
            'Amount'         => (int) ($d['valor_centavos'] ?? 0),
            'Provider'       => $provider,
            'ExpirationDate' => date('Y-m-d', strtotime("+{$dias} days")),
            'Assignor'       => (string) ($this->opcao('boleto_cedente') ?: ''),
            'Identification' => preg_replace('/\D/', '', (string) ($this->opcao('boleto_cnpj') ?: '')),
            'Demonstrative'  => mb_substr((string) ($d['descricao_fatura'] ?? 'Compra'), 0, 255),
            'Instructions'   => (string) ($this->opcao('boleto_instrucoes')
                                 ?: 'Não receber após o vencimento.'),
        ], static fn($v) => $v !== null && $v !== ''), 'boleto');
    }

    // =========================================================================

    /** Envelope comum: monta a venda e interpreta a resposta. */
    private function criar(array $d, array $pagamento, string $tipo): PagamentoClassificacao
    {
        $t0 = microtime(true);

        $corpo = array_filter([
            'MerchantOrderId' => self::referencia($d),
            'Customer'        => $this->cliente($d, $tipo),
            'Payment'         => $pagamento,
        ], static fn($v) => $v !== null && $v !== []);

        // A CHAVE DE IDEMPOTÊNCIA É O QUE IMPEDE COBRANÇA DUPLA. A Cielo não
        // tem header próprio para isso; o que ela oferece é o MerchantOrderId,
        // que já vai derivado da tentativa. O RequestId serve ao suporte
        // deles para achar a chamada.
        $r = $this->http('POST', '/1/sales', $corpo);

        if ($r['erro'] !== null) {
            // Não sabemos se chegou a criar. Perguntar antes de tentar outra
            // adquirente é o que evita cobrar o cliente duas vezes.
            $c = $this->classificar(PagamentoClassificacao::INCERTO, 'timeout');
            $c->mensagemAdquirente = $r['erro'];
            $c->exigeConsulta      = true;
            $c->duracaoMs          = self::ms($t0);
            LogService::error('Cielo sem resposta', [
                'tipo' => $tipo, 'pedido' => $d['order_id_loja'] ?? null, 'erro' => $r['erro'],
            ], 'pagamento');
            return $c;
        }

        $c = $this->interpretar($r, $tipo);
        $c->duracaoMs = self::ms($t0);
        return $c;
    }

    /**
     * Traduz a resposta da Cielo para a classificação do motor.
     *
     * A ORDEM DAS CHECAGENS IMPORTA. Uma venda estornada continua trazendo o
     * ReturnCode da autorização original, que foi aprovada — checar o código
     * antes do Status faria uma reconsulta de pedido estornado voltar como
     * "aprovado", liberando mercadoria de dinheiro devolvido.
     */
    private function interpretar(array $r, string $tipo): PagamentoClassificacao
    {
        $b    = $r['body'];
        $http = $r['http'];

        // Erro de validação vem como LISTA, não como venda:
        //   [{"Code":126,"Message":"Credit Card Expiration Date is invalid"}]
        if (isset($b[0]['Code']) || isset($b[0]['Message'])) {
            return $this->erroDeRequisicao($r, $tipo, $b);
        }

        $p = $b['Payment'] ?? null;

        if (!is_array($p)) {
            return $this->erroDeRequisicao($r, $tipo, $b);
        }

        $status  = isset($p['Status']) ? (int) $p['Status'] : -1;
        $retorno = strtoupper(trim((string) ($p['ReturnCode'] ?? '')));
        $texto   = (string) ($p['ReturnMessage'] ?? '');

        // `Paymentid` no Pix, `PaymentId` no resto. A doc mostra as duas.
        $chargeId = (string) ($p['PaymentId'] ?? $p['Paymentid'] ?? '');

        $c = $this->porStatus($status, $retorno, $texto, $tipo);

        $c->chargeId           = $chargeId !== '' ? $chargeId : null;
        $c->httpStatus         = $http;
        $c->codigoAdquirente   = $retorno !== '' ? $retorno : null;
        $c->mensagemAdquirente = $texto !== '' ? mb_substr($texto, 0, 200) : null;
        $c->traceKey           = (string) ($p['Tid'] ?? '') ?: null;
        $c->bandeira           = (string) ($p['CreditCard']['Brand'] ?? '') ?: null;

        // ── Instrumentos ────────────────────────────────────────────
        // Grafias inconsistentes de propósito: a doc mostra `QrCodeString`
        // com C maiúsculo e `QrcodeBase64Image` com c minúsculo. Ler só uma
        // devolve campo vazio sem erro nenhum.
        $emv = (string) ($p['QrCodeString'] ?? $p['QrcodeString'] ?? '');
        if ($emv !== '') {
            $c->pixQrCode       = $emv;
            $c->pixQrCodeBase64 = self::comoImagem(
                $p['QrcodeBase64Image'] ?? $p['QrCodeBase64Image'] ?? null
            );
            $c->pixExpiraEm     = (string) ($p['ExpirationDate'] ?? '') ?: null;
        }

        $linha = (string) ($p['DigitableLine'] ?? '');
        $barra = (string) ($p['BarCodeNumber'] ?? '');
        if ($linha !== '' || $barra !== '') {
            $c->boletoLinhaDigitavel = $linha ?: null;
            $c->boletoCodigoBarras   = $barra ?: null;
            $c->boletoUrl            = (string) ($p['Url'] ?? '') ?: null;
            $c->boletoVencimento     = (string) ($p['ExpirationDate'] ?? '') ?: null;
        }

        // Desfecho que o mapa não conhece: registra a resposta inteira. Sem
        // sandbox, é o primeiro caso real que vai ensinar o que falta aqui.
        if ($c->classeErro === 'status_desconhecido') {
            LogService::error('Cielo devolveu status fora do mapa', [
                'tipo' => $tipo, 'http' => $http, 'status' => $status,
                'return_code' => $retorno, 'mensagem' => $texto,
                'resposta' => mb_substr(json_encode($b, JSON_UNESCAPED_UNICODE) ?: '', 0, 1500),
            ], 'pagamento');
        }

        return $c;
    }

    /**
     * Status da venda → porta do motor.
     *
     * O TIPO IMPORTA: `Status 1` quer dizer coisas opostas conforme o meio.
     * No cartão é "autorizado" — o emissor aprovou, e o motor pode seguir ao
     * antifraude. No boleto é "emitido, aguardando pagamento" — ninguém pagou
     * nada ainda. Tratar os dois igual liberaria mercadoria contra um boleto
     * que talvez nunca seja pago.
     */
    private function porStatus(
        int $status,
        string $retorno,
        string $texto,
        string $tipo = 'cartao'
    ): PagamentoClassificacao {
        switch ($status) {
            case self::ST_PAGO:
                return $this->classificar(PagamentoClassificacao::APROVADO, 'aprovado');

            case self::ST_AUTORIZADO:
                if ($tipo === 'boleto') {
                    return $this->classificar(PagamentoClassificacao::PENDENTE, 'boleto_emitido');
                }
                // Cartão autorizado e ainda não capturado é APROVADO para o
                // motor: a autorização passou, e é esse o desfecho que o
                // fluxo espera. A captura vem depois, na fila.
                return $this->classificar(PagamentoClassificacao::APROVADO, 'aprovado');

            case self::ST_PIX_GERADO:
                return $this->classificar(PagamentoClassificacao::PENDENTE, 'pix_gerado');

            case self::ST_NEGADO:
                // Recusa de emissor: quem classifica é a tabela ABECS, que
                // já existe no projeto. Ver PagamentoErroClassifier.
                return PagamentoErroClassifier::porCodigoAbecs($retorno, $texto);

            case self::ST_CANCELADO:
            case self::ST_ESTORNADO:
                $c = $this->classificar(PagamentoClassificacao::NEGADO_GENERICO, 'cancelado');
                $c->mensagemCliente = 'Esta cobrança foi cancelada.';
                return $c;

            case self::ST_ABORTADO:
                return $this->classificar(PagamentoClassificacao::NEGADO_GENERICO, 'abortado');

            case self::ST_NAO_FINALIZADO:
                // Nem aprovada nem negada: perguntar é o único caminho certo.
                // Recusar aqui recusaria pagamento que ia passar; retentar
                // noutra adquirente arriscaria cobrar duas vezes.
                $c = $this->classificar(PagamentoClassificacao::INCERTO, 'nao_finalizado');
                $c->exigeConsulta = true;
                return $c;
        }

        // Boleto emitido volta com Status 1, já tratado acima. Chegar aqui é
        // status que a doc não lista.
        return $this->classificar(PagamentoClassificacao::INCERTO, 'status_desconhecido');
    }

    /**
     * Resposta que não é uma venda: transporte, credencial ou requisição
     * malformada. Nenhum desses é decisão do emissor, então todos permitem
     * cair para outra adquirente.
     */
    private function erroDeRequisicao(array $r, string $tipo, array $b): PagamentoClassificacao
    {
        $http = (int) $r['http'];

        $msgs = [];
        foreach ((array) $b as $e) {
            if (is_array($e) && isset($e['Message'])) {
                $msgs[] = trim(($e['Code'] ?? '') . ' ' . $e['Message']);
            }
        }
        $msg = $msgs ? implode(' · ', $msgs) : (string) ($r['raw'] ?? '');

        // 5xx e 429 são "eles fora do ar"; 4xx é "nós mandamos errado".
        // Separar importa no sino: um avisa sobre a adquirente, o outro avisa
        // que a integração está quebrada.
        $foraDoAr = $http >= 500 || $http === 429 || $http === 0;

        $c = $this->classificar(
            $foraDoAr ? PagamentoClassificacao::INDISPONIVEL : PagamentoClassificacao::ERRO_TECNICO,
            $foraDoAr ? 'indisponivel' : 'requisicao_recusada'
        );
        $c->httpStatus         = $http;
        $c->mensagemAdquirente = mb_substr($msg, 0, 200);

        LogService::error('Cielo recusou a requisicao', [
            'tipo' => $tipo, 'http' => $http, 'motivo' => mb_substr($msg, 0, 300),
        ], 'pagamento');

        return $c;
    }

    // =========================================================================
    // Consulta, captura e cancelamento
    // =========================================================================

    public function consultar(string $chargeId): PagamentoClassificacao
    {
        $r = $this->http('GET', '/1/sales/' . rawurlencode($chargeId), null, true);

        if ($r['erro'] !== null) {
            $c = $this->classificar(PagamentoClassificacao::INDISPONIVEL, 'consulta_falhou');
            $c->mensagemAdquirente = $r['erro'];
            return $c;
        }

        return $this->interpretar($r, 'consulta');
    }

    /** Captura o que estava só autorizado. */
    public function capturar(string $chargeId, ?int $valorCentavos = null): PagamentoClassificacao
    {
        $qs = $valorCentavos !== null ? '?amount=' . $valorCentavos : '';
        $r  = $this->http('PUT', '/1/sales/' . rawurlencode($chargeId) . '/capture' . $qs, null);

        if ($r['erro'] !== null) {
            $c = $this->classificar(PagamentoClassificacao::INCERTO, 'captura_sem_resposta');
            $c->mensagemAdquirente = $r['erro'];
            $c->exigeConsulta      = true;
            return $c;
        }

        $p       = $r['body']['Payment'] ?? $r['body'];
        $status  = isset($p['Status']) ? (int) $p['Status'] : -1;
        $retorno = strtoupper(trim((string) ($p['ReturnCode'] ?? '')));

        // A captura devolve a venda inteira; Status 2 é o desfecho esperado.
        if ($status === self::ST_PAGO) {
            $c = $this->classificar(PagamentoClassificacao::APROVADO, 'capturado');
        } else {
            $c = $this->classificar(PagamentoClassificacao::ERRO_TECNICO, 'captura_recusada');
            LogService::error('Cielo recusou a captura', [
                'charge' => $chargeId, 'status' => $status, 'return_code' => $retorno,
                'mensagem' => $p['ReturnMessage'] ?? null,
            ], 'pagamento');
        }

        $c->chargeId         = $chargeId;
        $c->codigoAdquirente = $retorno ?: null;
        $c->httpStatus       = (int) $r['http'];
        return $c;
    }

    public function cancelar(
        string $chargeId,
        ?int $valorCentavos = null,
        bool $porAntifraude = false
    ): PagamentoClassificacao {
        $qs = $valorCentavos !== null ? '?amount=' . $valorCentavos : '';
        $r  = $this->http('PUT', '/1/sales/' . rawurlencode($chargeId) . '/void' . $qs, null);

        if ($r['erro'] !== null) {
            // Cancelamento sem resposta é o pior caso: pode ter valido. Marca
            // como pendente para a fila reconsultar, nunca como cancelado.
            $c = $this->classificar(PagamentoClassificacao::PENDENTE, 'cancelamento_sem_resposta');
            $c->cancelamentoPendente = true;
            $c->mensagemAdquirente   = $r['erro'];
            return $c;
        }

        $p      = $r['body']['Payment'] ?? $r['body'];
        $status = isset($p['Status']) ? (int) $p['Status'] : -1;

        if (in_array($status, [self::ST_CANCELADO, self::ST_ESTORNADO], true)) {
            $c = $this->classificar(PagamentoClassificacao::APROVADO, 'cancelado');
        } else {
            $c = $this->classificar(PagamentoClassificacao::ERRO_TECNICO, 'cancelamento_falhou');
            LogService::error('Cielo recusou o cancelamento', [
                'charge' => $chargeId, 'status' => $status,
                'mensagem' => $p['ReturnMessage'] ?? null,
                'antifraude' => $porAntifraude,
            ], 'pagamento');
        }

        $c->chargeId   = $chargeId;
        $c->httpStatus = (int) $r['http'];
        return $c;
    }

    // =========================================================================
    // Auxiliares
    // =========================================================================

    private function cliente(array $d, string $tipo): array
    {
        $cli = $d['cliente'] ?? [];
        $doc = preg_replace('/\D/', '', (string) ($cli['documento'] ?? ''));

        $c = array_filter([
            'Name'         => mb_substr(trim((string) ($cli['nome'] ?? 'Cliente')), 0, 255),
            'Email'        => (string) ($cli['email'] ?? ''),
            'Identity'     => $doc,
            'IdentityType' => $doc !== '' ? (strlen($doc) > 11 ? 'CNPJ' : 'CPF') : null,
        ], static fn($v) => $v !== null && $v !== '');

        // Só o boleto exige endereço; mandar no cartão e no Pix não agrega e
        // aumenta o dado trafegado à toa.
        if ($tipo === 'boleto') {
            $e = $d['entrega'] ?? ($cli['endereco'] ?? []);
            $end = array_filter([
                'Street'     => (string) ($e['logradouro'] ?? ''),
                'Number'     => (string) ($e['numero'] ?? ''),
                'Complement' => (string) ($e['complemento'] ?? ''),
                'ZipCode'    => preg_replace('/\D/', '', (string) ($e['cep'] ?? '')),
                'District'   => (string) ($e['bairro'] ?? ''),
                'City'       => (string) ($e['cidade'] ?? ''),
                'State'      => mb_substr((string) ($e['uf'] ?? $e['estado'] ?? ''), 0, 2),
                'Country'    => 'BRA',
            ], static fn($v) => $v !== null && $v !== '');

            if ($end) $c['Address'] = $end;
        }

        return $c;
    }

    /**
     * Chamada HTTP.
     *
     * `protected` de propósito: é o ponto que os testes substituem para
     * exercitar a interpretação sem tocar na API — que aqui é a de produção.
     */
    protected function http(string $metodo, string $caminho, ?array $corpo = null, bool $consulta = false): array
    {
        $url = ($consulta ? $this->baseConsulta() : $this->base()) . $caminho;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $metodo,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'MerchantId: '  . $this->merchantId,
                'MerchantKey: ' . $this->merchantKey,
                'RequestId: '   . self::uuid(),
            ],
        ]);

        if ($corpo !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($corpo, JSON_UNESCAPED_UNICODE));
        }

        $raw  = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $erro = curl_errno($ch) ? curl_error($ch) : null;
        curl_close($ch);

        return [
            'http' => $http,
            'erro' => $erro,
            'raw'  => is_string($raw) ? $raw : '',
            'body' => is_string($raw) ? (json_decode($raw, true) ?: []) : [],
        ];
    }

    private function classificar(string $porta, string $classeErro): PagamentoClassificacao
    {
        $c = new PagamentoClassificacao();
        $c->porta           = $porta;
        $c->classeErro      = $classeErro;
        $c->mensagemCliente = self::MENSAGEM[$porta] ?? 'Não foi possível processar o pagamento.';

        // Só falha nossa ou da adquirente autoriza tentar outra. Recusa do
        // emissor, não — é o que o motor bloqueia em tempo de execução.
        $c->podeCairParaOutra = in_array($porta, [
            PagamentoClassificacao::ERRO_TECNICO,
            PagamentoClassificacao::INDISPONIVEL,
        ], true);

        $c->exigeConsulta = $porta === PagamentoClassificacao::INCERTO;

        return $c;
    }

    /** Deixa o QR pronto para o `src` de um <img>. */
    private static function comoImagem(?string $b64): ?string
    {
        $b64 = trim((string) $b64);
        if ($b64 === '') return null;

        if (str_starts_with($b64, 'data:') || str_starts_with($b64, 'http')) return $b64;

        return 'data:image/png;base64,' . $b64;
    }

    /** MerchantOrderId aceita até 50 chars; o nosso código cabe. */
    private static function referencia(array $d): string
    {
        $ref = preg_replace('/[^A-Za-z0-9_-]/', '-', (string) ($d['order_id_loja'] ?? '')) ?? '';
        return mb_substr($ref, 0, 50) ?: ('ped-' . bin2hex(random_bytes(6)));
    }

    /**
     * Aparece na fatura do cliente. A Cielo aceita 13 caracteres.
     *
     * "SportMoto 62399999" cortado em 13 vira "SportMoto 623" — um número
     * pela metade que não identifica nada e ainda parece erro. Quando não
     * cabe inteiro, fica só o nome da loja, que é o que a pessoa precisa
     * reconhecer na fatura.
     */
    private static function descritor(array $d): string
    {
        $t = trim(preg_replace('/[^A-Za-z0-9 ]/', '', (string) ($d['descricao_fatura'] ?? '')) ?? '');

        if ($t === '' || mb_strlen($t) <= 13) return $t;

        $primeira = explode(' ', $t)[0] ?? '';
        return mb_substr($primeira !== '' ? $primeira : $t, 0, 13);
    }

    /** @return array{0:string,1:string} mês com 2 dígitos, ano com 4 */
    private static function validade(string $v): array
    {
        $d = preg_replace('/\D/', '', $v) ?? '';
        $m = substr($d, 0, 2);
        $a = substr($d, 2);

        if (strlen($a) === 2) $a = '20' . $a;

        return [str_pad($m, 2, '0', STR_PAD_LEFT), $a ?: (string) (int) date('Y')];
    }

    private static function bandeiraParaCielo(string $b): string
    {
        return [
            'visa' => 'Visa', 'master' => 'Master', 'mastercard' => 'Master',
            'amex' => 'Amex', 'elo' => 'Elo', 'hipercard' => 'Hipercard',
            'diners' => 'Diners', 'discover' => 'Discover', 'jcb' => 'JCB',
            'aura' => 'Aura', 'cabal' => 'Cabal',
        ][strtolower(trim($b))] ?? '';
    }

    private static function ms(float $t0): int
    {
        return (int) ((microtime(true) - $t0) * 1000);
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
