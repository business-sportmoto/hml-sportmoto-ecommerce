<?php
declare(strict_types=1);

/**
 * app/services/payment/adquirentes/MercadoPagoAdapter.php
 *
 * Adquirente Mercado Pago — cartão, Pix e boleto pela Orders API.
 *
 * POR QUE A ORDERS API E NÃO /v1/payments:
 *   A primeira versão deste arquivo usava /v1/payments, escolhida porque a
 *   Orders API não devolvia erro de validação legível e a documentação dela
 *   não saía por scraping. Com a referência em mãos a decisão se inverte —
 *   a Orders API tem duas coisas que a outra não tem:
 *
 *     capture_mode = manual  → pré-autoriza sem capturar. É exatamente o que
 *       o modo `pre_captura` do nó de antifraude precisa: reprovar um pedido
 *       vira cancelamento gratuito em vez de estorno com custo.
 *
 *     config.online.transaction_security → 3DS com liability_shift, que move
 *       o chargeback de fraude para o emissor.
 *
 *   Trocar depois seria reescrever; trocar agora, enquanto nada foi
 *   exercitado contra a API, custa uma tarde.
 *
 * DIFERENÇAS QUE PEGAM QUEM VEM DE /v1/payments:
 *   - valores são STRING, não número: "259.90"
 *   - boleto é id "boleto" / type "ticket" (não "bolbradesco")
 *   - Pix e boleto exigem `shipment`; Pix exige `external_reference`
 *   - `external_reference` aceita só alfanumérico, hífen e sublinhado
 *   - no PEDIDO `transactions.payments` é objeto; na RESPOSTA volta array
 *
 * ESTADO DA VALIDAÇÃO (28/08/2026):
 *   Confirmado contra a API  → autenticação, /v1/card_tokens (BIN + últimos 4)
 *   Escrito, NÃO exercitado  → criação, captura, cancelamento e estorno
 *
 *   A conta de teste disponível devolve 401 "Unauthorized use of live
 *   credentials" em qualquer criação — ela é uma *conta* de teste (APP_USR-)
 *   e o Checkout Transparente quer credenciais em *modo* teste (TEST-).
 *   Quando o primeiro pagamento real passar, confira ANTES DE TUDO o mapa de
 *   status_detail: é ele que decide se o motor pode tentar outra adquirente,
 *   e errar para o lado permissivo gera multa de bandeira.
 *
 * O CARTÃO NÃO DEVERIA PASSAR POR AQUI:
 *   O certo é o navegador tokenizar com MercadoPago.js e mandar só o token.
 *   Existe um fallback que tokeniza no servidor quando chega PAN — mantém o
 *   motor de pé antes do checkout ser adaptado, mas põe a loja no escopo PCI
 *   e grita no log toda vez. Não é para ficar.
 *
 * CREDENCIAIS (.env):
 *   MP_AMBIENTE            sandbox | producao
 *   MP_PUBLIC_KEY          / MP_ACCESS_TOKEN         (produção)
 *   MP_TEST_PUBLIC_KEY     / MP_TEST_ACCESS_TOKEN    (teste)
 *   MP_TIMEOUT             segundos (padrão 20)
 *   MP_PIX_EXPIRA_MIN      minutos até o QR vencer (padrão 30)
 *   MP_BOLETO_DIAS         dias até o boleto vencer (padrão 3)
 *   MP_3DS                 never | on_fraud_risk (padrão never)
 */
class MercadoPagoAdapter implements AdquirenteInterface
{
    private const BASE = 'https://api.mercadopago.com';

    /**
     * status_detail → porta do nosso domínio.
     *
     * A porta decide se o motor PODE tentar outra adquirente. Errar na direção
     * permissiva vira retentativa de recusa do emissor, que é o que gera multa
     * de bandeira (Visa Excessive Reattempts / Mastercard TPE). Por isso o
     * desconhecido cai em NEGADO_GENERICO, que não retenta.
     */
    private const MAPA_DETALHE = [
        // ── Deu certo ───────────────────────────────────────────────
        'accredited'            => PagamentoClassificacao::APROVADO,

        // ── Pré-autorizado: autorização existe, dinheiro não saiu ──
        // APROVADO porque a autorização passou — é o desfecho que o fluxo
        // espera para seguir ao antifraude. A captura vem depois, na fila.
        'waiting_capture'       => PagamentoClassificacao::APROVADO,

        // ── Instrumento criado, dinheiro ainda não ──────────────────
        'waiting_payment'       => PagamentoClassificacao::PENDENTE,
        'waiting_transfer'      => PagamentoClassificacao::PENDENTE,
        'created'               => PagamentoClassificacao::PENDENTE,
        'pending_review_manual' => PagamentoClassificacao::PENDENTE,

        // ── Ainda em curso: pode virar aprovado sozinho ─────────────
        // Recusar aqui recusaria pagamento que ia passar; retentar noutra
        // adquirente arriscaria cobrar duas vezes. Consultar é o único certo.
        'in_process'            => PagamentoClassificacao::INCERTO,
        'in_review'             => PagamentoClassificacao::INCERTO,
        'waiting_retry'         => PagamentoClassificacao::INCERTO,
        'pending_challenge'     => PagamentoClassificacao::INCERTO,
        'pending_contingency'   => PagamentoClassificacao::INCERTO,

        // ── Encerrado sem pagamento ─────────────────────────────────
        'canceled_transaction'  => PagamentoClassificacao::NEGADO_GENERICO,
        'refunded'              => PagamentoClassificacao::NEGADO_GENERICO,
        'partially_refunded'    => PagamentoClassificacao::NEGADO_GENERICO,
        'expired'               => PagamentoClassificacao::NEGADO_GENERICO,

        // ── Recusas do emissor — CONFIRMADAS na Orders API ──────────
        // A Orders API NAO usa os codigos cc_rejected_* da API de pagamentos.
        // Estes tres vieram do sandbox, com os cartoes de teste FUND, SECU,
        // EXPI e OTHE. Nenhum deles retenta: sao decisao do emissor.
        'insufficient_amount'   => PagamentoClassificacao::NEGADO_SALDO,
        'bad_filled_card_data'  => PagamentoClassificacao::NEGADO_DADOS,
        'rejected_by_issuer'    => PagamentoClassificacao::NEGADO_GENERICO,

        // ── Recusas no formato da API de pagamentos ─────────────────
        // Mantidas porque o Mercado Pago ainda devolve estes codigos em
        // alguns caminhos; nao atrapalham se nunca aparecerem.
        'cc_rejected_insufficient_amount'      => PagamentoClassificacao::NEGADO_SALDO,
        'cc_rejected_bad_filled_card_number'   => PagamentoClassificacao::NEGADO_DADOS,
        'cc_rejected_bad_filled_date'          => PagamentoClassificacao::NEGADO_DADOS,
        'cc_rejected_bad_filled_security_code' => PagamentoClassificacao::NEGADO_DADOS,
        'cc_rejected_bad_filled_other'         => PagamentoClassificacao::NEGADO_DADOS,
        'cc_rejected_card_disabled'            => PagamentoClassificacao::NEGADO_DADOS,
        'cc_rejected_card_type_not_allowed'    => PagamentoClassificacao::NEGADO_DADOS,
        'cc_rejected_invalid_installments'     => PagamentoClassificacao::NEGADO_DADOS,
        'cc_rejected_high_risk'                => PagamentoClassificacao::NEGADO_ANTIFRAUDE,
        'cc_rejected_blacklist'                => PagamentoClassificacao::NEGADO_ANTIFRAUDE,
        'rejected_high_risk'                   => PagamentoClassificacao::NEGADO_ANTIFRAUDE,
        'cc_rejected_call_for_authorize'       => PagamentoClassificacao::NEGADO_GENERICO,
        'cc_rejected_duplicated_payment'       => PagamentoClassificacao::NEGADO_GENERICO,
        'cc_rejected_max_attempts'             => PagamentoClassificacao::NEGADO_GENERICO,
        'cc_rejected_other_reason'             => PagamentoClassificacao::NEGADO_GENERICO,
        'cc_rejected_card_error'               => PagamentoClassificacao::NEGADO_GENERICO,
    ];

    /** Mensagem ao comprador. Nunca o motivo cru do emissor. */
    private const MENSAGEM = [
        PagamentoClassificacao::APROVADO          => 'Pagamento aprovado.',
        PagamentoClassificacao::PENDENTE          => 'Aguardando o pagamento.',
        PagamentoClassificacao::NEGADO_SALDO      => 'Não foi possível concluir: limite ou saldo insuficiente.',
        PagamentoClassificacao::NEGADO_DADOS      => 'Confira os dados do cartão e tente novamente.',
        PagamentoClassificacao::NEGADO_ANTIFRAUDE => 'Não foi possível concluir esta compra com este cartão.',
        PagamentoClassificacao::NEGADO_GENERICO   => 'Pagamento não autorizado pelo banco emissor.',
        PagamentoClassificacao::ERRO_TECNICO      => 'Tivemos um problema ao processar. Tente novamente.',
        PagamentoClassificacao::INDISPONIVEL      => 'Pagamento indisponível no momento. Tente outra forma.',
        PagamentoClassificacao::INCERTO           => 'Estamos confirmando seu pagamento. Avisaremos em instantes.',
    ];

    private string $ambiente;
    private string $accessToken;
    private string $publicKey;
    private int    $timeout;

    /** Config vinda do banco (config_extra), com o .env como reserva. */
    private array $config = [];

    public function __construct(?string $accessToken = null, ?string $publicKey = null, ?string $ambiente = null)
    {
        // Credenciais: banco primeiro, .env depois. Ver
        // PagamentoCredencialService para a ordem e o porquê dela.
        $cred = PagamentoCredencialService::para('mercadopago');

        $amb = $ambiente !== null
            ? strtolower($ambiente)
            : ($cred['sandbox'] ? 'sandbox' : 'producao');

        $this->ambiente = in_array($amb, ['sandbox', 'teste', 'test', 'homologacao'], true)
            ? 'sandbox' : 'producao';

        $this->accessToken = $accessToken ?? $cred['access_token'];
        $this->publicKey   = $publicKey   ?? $cred['public_key'];
        $this->config      = $cred['config'];

        $this->timeout = max(5, min((int) ($this->opcao('timeout') ?: 20), 40));
    }

    /** Opção do config_extra do banco; sem ela, a chave do .env. */
    private function opcao(string $nome): string
    {
        if (isset($this->config[$nome]) && $this->config[$nome] !== '') {
            return (string) $this->config[$nome];
        }
        return self::cfg('MP_' . strtoupper($nome));
    }

    public function codigo(): string { return 'mercadopago'; }

    public function configurado(): bool { return $this->accessToken !== ''; }

    public function ambiente(): string { return $this->ambiente; }

    // =========================================================================

    public function autorizarCartao(array $d): PagamentoClassificacao
    {
        $token = (string) ($d['token_temporario'] ?? '');

        if ($token === '') {
            $token = $this->tokenizarNoServidor($d);
            if ($token === '') {
                $c = $this->classificar(PagamentoClassificacao::NEGADO_DADOS, 'sem_token');
                $c->mensagemAdquirente = 'sem token de cartão e sem dados para gerar um';
                return $c;
            }
        }

        $pagamento = [
            'amount'         => self::valor($d['valor_centavos'] ?? 0),
            'payment_method' => array_filter([
                'id'                   => self::bandeiraParaMp((string) ($d['bandeira'] ?? '')),
                'type'                 => 'credit_card',
                'token'                => $token,
                'installments'         => max(1, (int) ($d['parcelas'] ?? 1)),
                'statement_descriptor' => self::descritor($d),
            ], static fn($v) => $v !== null && $v !== ''),
        ];

        $corpo = $this->pedido($d, $pagamento);

        // manual = pré-autoriza e não captura. É o que o modo `pre_captura`
        // do antifraude precisa para reprovar sem custo de estorno.
        $corpo['capture_mode'] = ($d['captura'] ?? '') === 'manual' ? 'manual' : 'automatic';

        // 3DS desligado por padrão: ligado, o MP pode devolver um desafio que
        // precisa ser renderizado no checkout, e isso ainda não existe aqui.
        $corpo['config'] = ['online' => [
            'transaction_security' => ['validation' => $this->opcao('tres_ds') ?: 'never'],
        ]];

        return $this->criar($corpo, $d, 'cartao');
    }

    public function criarPix(array $d): PagamentoClassificacao
    {
        $minutos = max(5, (int) ($this->opcao('pix_expira_min') ?: 30));

        $pagamento = [
            'amount'          => self::valor($d['valor_centavos'] ?? 0),
            'payment_method'  => ['id' => 'pix', 'type' => 'bank_transfer'],
            // Sem vencimento o QR fica pagável por dias, com o estoque
            // reservado esse tempo todo.
            'expiration_time' => 'PT' . $minutos . 'M',
        ];

        return $this->criar($this->pedido($d, $pagamento, true), $d, 'pix');
    }

    public function criarBoleto(array $d): PagamentoClassificacao
    {
        $dias = max(1, (int) ($this->opcao('boleto_dias') ?: 3));

        // `expiration_time` (duracao ISO 8601) e o unico aceito. A referencia
        // mostra `date_of_expiration` ao lado dele no exemplo de boleto, e a
        // API recusa: "additionalProperties 'date_of_expiration' not allowed".
        $pagamento = [
            'amount'          => self::valor($d['valor_centavos'] ?? 0),
            'payment_method'  => ['id' => 'boleto', 'type' => 'ticket'],
            'expiration_time' => 'P' . $dias . 'D',
        ];

        return $this->criar($this->pedido($d, $pagamento, true), $d, 'boleto');
    }

    public function consultar(string $chargeId): PagamentoClassificacao
    {
        $t0 = microtime(true);
        $r  = $this->http('GET', '/v1/orders/' . rawurlencode($chargeId), null);

        if ($r['erro'] !== null) {
            $c = $this->classificar(PagamentoClassificacao::ERRO_TECNICO, 'rede');
            $c->mensagemAdquirente = $r['erro'];
            $c->duracaoMs = self::ms($t0);
            return $c;
        }

        if ($r['http'] === 404) {
            // Não existe do lado deles: seguro tratar como não cobrado.
            $c = $this->classificar(PagamentoClassificacao::NEGADO_GENERICO, 'nao_encontrado');
            $c->httpStatus = 404;
            $c->duracaoMs  = self::ms($t0);
            return $c;
        }

        $c = $this->interpretar($r, 'consulta');
        $c->duracaoMs = self::ms($t0);
        return $c;
    }

    /**
     * Captura uma pré-autorização.
     *
     * Ainda NÃO está no AdquirenteInterface porque a Safra não implementa
     * captura — promover lá quebraria o outro adapter. Quem chama é a fila de
     * análise, que precisa capturar quando um pedido em `pre_captura` é
     * liberado.
     *
     * A Orders API captura só o total; captura parcial não existe. E só
     * aceita pedido em action_required/waiting_capture.
     */
    public function capturar(string $orderId): PagamentoClassificacao
    {
        $t0 = microtime(true);
        $r  = $this->http('POST', '/v1/orders/' . rawurlencode($orderId) . '/capture', null,
                          ['X-Idempotency-Key: mp-cap-' . mb_substr($orderId, 0, 100)]);

        $c = ($r['erro'] === null && $r['http'] < 300)
            ? $this->interpretar($r, 'captura')
            : $this->classificar(PagamentoClassificacao::ERRO_TECNICO, 'captura_falhou');

        $c->chargeId   = $orderId;
        $c->httpStatus = $r['http'];
        $c->duracaoMs  = self::ms($t0);

        if ($c->porta !== PagamentoClassificacao::APROVADO) {
            $c->mensagemAdquirente = mb_substr((string) ($r['body']['message'] ?? $r['raw']), 0, 200);
            LogService::error('Mercado Pago recusou a captura', [
                'order_id' => $orderId, 'http' => $r['http'], 'motivo' => $c->mensagemAdquirente,
            ], 'pagamento');
        }

        return $c;
    }

    /**
     * Guarda o cartao para reuso: cria (ou reaproveita) o cliente e vincula
     * o cartao a ele.
     *
     * POR QUE ISTO E NECESSARIO:
     *   O token da tokenizacao e de USO UNICO — verificado: a primeira
     *   cobranca aprova, a segunda com o mesmo token e recusada. Guardar o
     *   token como "cartao salvo" funcionaria uma vez so. O que persiste e o
     *   par customer_id + card_id, e e ele que vai para cartoes_salvos.
     *
     *   O token E CONSUMIDO aqui: depois de virar cartao, nao serve mais para
     *   cobrar. Quem salva o cartao no mesmo passo de uma compra precisa de
     *   dois tokens, nao um.
     *
     * NAO ESTA NO AdquirenteInterface: guardar cartao nao e algo que toda
     * adquirente faca do mesmo jeito, e forcar na interface obrigaria as
     * outras a fingir que fazem.
     *
     * @return array{ok:bool, customer_ref:?string, card_ref:?string,
     *               bandeira:?string, ultimos4:?string, erro:?string}
     */
    public function salvarCartao(array $cliente, string $token): array
    {
        $email = self::emailParaCadastro((string) ($cliente['email'] ?? ''));

        if ($email === '' || $token === '') {
            return $this->falhaCartao('e-mail do cliente ou token ausente');
        }

        $customerId = $this->acharOuCriarCliente($cliente, $email);
        if ($customerId === null) {
            return $this->falhaCartao('nao foi possivel criar o cliente na adquirente');
        }

        $r = $this->http('POST', '/v1/customers/' . rawurlencode($customerId) . '/cards',
                         ['token' => $token]);

        if ($r['erro'] !== null || $r['http'] < 200 || $r['http'] >= 300) {
            $motivo = (string) ($r['body']['message'] ?? $r['raw']);

            LogService::error('Mercado Pago recusou salvar o cartao', [
                'customer' => $customerId, 'http' => $r['http'],
                'motivo'   => mb_substr($motivo, 0, 200),
                'cliente'=>$cliente, 'tef'=>$cliente['documento'].'tef'
            ], 'pagamento');

            // "invalid card owner" significa que o titular do token nao bate
            // com o cadastro do cliente na adquirente. Vale dizer isso em vez
            // de um erro generico: quem le o log precisa saber onde olhar.
            if (stripos($motivo, 'card owner') !== false) {
                return $this->falhaCartao(
                    'os dados do titular nao conferem com o cadastro do cliente na adquirente'
                );
            }

            return $this->falhaCartao('a adquirente recusou salvar o cartao');
        }

        $c = $r['body'];

        // Cartao salvo e evento de auditoria: alguem passou a poder cobrar
        // aquele cliente sem redigitar o numero. So o erro estava deixando
        // rastro — um cadastro bem-sucedido nao aparecia em lugar nenhum.
        LogService::audit('Cartao salvo na adquirente', [
            'adquirente' => $this->codigo(),
            'customer'   => $customerId,
            'card'       => $c['id'] ?? null,
            'bandeira'   => $c['payment_method']['id'] ?? null,
            'ultimos4'   => $c['last_four_digits'] ?? null,
        ]);

        return [
            'ok'           => true,
            'customer_ref' => $customerId,
            'card_ref'     => isset($c['id']) ? (string) $c['id'] : null,
            'bandeira'     => (string) ($c['payment_method']['id'] ?? '') ?: null,
            'ultimos4'     => (string) ($c['last_four_digits'] ?? '') ?: null,
            'erro'         => null,
        ];
    }

    /**
     * Cliente na adquirente, por e-mail.
     *
     * Busca antes de criar porque o Mercado Pago recusa e-mail duplicado — e
     * o cliente pode ja existir de uma compra anterior, ou de outro cadastro
     * nosso que nao guardou o id.
     */
    private function acharOuCriarCliente(array $cliente, string $email): ?string
    {
        $busca = $this->http('GET', '/v1/customers/search?email=' . urlencode($email), null);

        // LogService::audit('acharOuCriarCliente', [$busca]);

        $achado = $busca['body']['results'][0]['id'] ?? null;
        if ($achado) return (string) $achado;

        $nome = trim((string) ($cliente['nome'] ?? ''));
        $part = $nome !== '' ? (preg_split('/\s+/', $nome) ?: []) : [];

        // O CLIENTE VAI SEM IDENTIFICACAO — e isso e o que faz cartao de
        // terceiro funcionar.
        //
        // Um cliente daqui e o RECIPIENTE do comprador, achado pelo e-mail
        // dele, e guarda TODOS os cartoes que ele usa: o proprio, o da mae,
        // o da empresa. Se o recipiente tiver CPF, o Mercado Pago cruza esse
        // documento com o do titular gravado no token e recusa qualquer
        // cartao que nao seja do comprador — "invalid card owner".
        //
        // O documento do titular ja viaja dentro do token, posto la na
        // tokenizacao. Repetir no cliente nao acrescenta nada e so cria uma
        // regra que proibe o caso comum de pagar com o cartao de outra pessoa.
        //
        // (Cheguei a achar que a identificacao era obrigatoria. Nao e: um
        // POST so com `email` devolve 201. O que derrubava era o `+` no
        // e-mail — ver emailParaCadastro.)
        $corpo = array_filter([
            'email'      => $email,
            // 'first_name' => (string) ($part[0] ?? ''),
            // 'last_name'  => count($part) > 1 ? (string) end($part) : '',
        ], static fn($v) => $v !== '');

        $r = $this->http('POST', '/v1/customers', $corpo);

        if ($r['http'] >= 200 && $r['http'] < 300 && isset($r['body']['id'])) {
            return (string) $r['body']['id'];
        }

        // `cause` e onde mora a explicacao. O `message` sozinho diz
        // "Validation error to register user", que nao ajuda ninguem — foi
        // preciso sondar a API para descobrir que o problema era o `+` no
        // e-mail. Logar o cause evita a proxima cacada.
        LogService::error('Mercado Pago recusou criar o cliente', [
            'http'   => $r['http'],
            'motivo' => mb_substr((string) ($r['body']['message'] ?? $r['raw']), 0, 200),
            'causa'  => isset($r['body']['cause']) ? json_encode($r['body']['cause']) : null,
        ], 'pagamento');

        return null;
    }

    /** Cartoes salvos de um cliente. E o que o checkout mostraria. */
    public function listarCartoes(string $customerId): array
    {
        $r = $this->http('GET', '/v1/customers/' . rawurlencode($customerId) . '/cards', null);

        return ($r['http'] >= 200 && $r['http'] < 300 && is_array($r['body']))
            ? $r['body']
            : [];
    }

    /**
     * Remove um cartao salvo.
     *
     * Precisa existir para o cliente poder descadastrar — e para um teste em
     * producao nao deixar cartao real pendurado na conta.
     */
    public function removerCartao(string $customerId, string $cardId): bool
    {
        $r = $this->http(
            'DELETE',
            '/v1/customers/' . rawurlencode($customerId) . '/cards/' . rawurlencode($cardId),
            null
        );

        $ok = $r['erro'] === null && $r['http'] >= 200 && $r['http'] < 300;

        if (!$ok) {
            LogService::error('Mercado Pago recusou remover o cartao', [
                'customer' => $customerId, 'card' => $cardId, 'http' => $r['http'],
                'motivo'   => mb_substr((string) ($r['body']['message'] ?? $r['raw']), 0, 200),
            ], 'pagamento');
        }

        return $ok;
    }

    /**
     * E-mail aceito pelo cadastro de clientes do Mercado Pago.
     *
     * Eles recusam alias com `+` — "Field=email - Syntax invalid", causa 612.
     * E alias e comum: quem usa Gmail costuma assinar como
     * joao+loja@gmail.com, e o cartao desse cliente nunca seria salvo.
     *
     * Tirar a parte depois do `+` aponta para a MESMA caixa postal, entao nao
     * se perde nada — e o cadastro passa.
     */
    private static function emailParaCadastro(string $email): string
    {
        $email = trim($email);
        if ($email === '' || !str_contains($email, '+')) return $email;

        [$local, $dominio] = explode('@', $email, 2) + ['', ''];
        if ($dominio === '') return $email;

        $limpo = strtok($local, '+') . '@' . $dominio;

        LogService::info('E-mail sem alias para o cadastro no Mercado Pago', [
            'original' => preg_replace('/(.{2}).*(@.*)/', '$1***$2', $email),
        ], 'pagamento');

        return $limpo;
    }

    private function falhaCartao(string $erro): array
    {
        return ['ok' => false, 'customer_ref' => null, 'card_ref' => null,
                'bandeira' => null, 'ultimos4' => null, 'erro' => $erro];
    }

    /**
     * Cancela ou estorna.
     *
     * SÃO ENDPOINTS DIFERENTES, e usar o errado devolve 409:
     *   created / action_required  → /cancel   (nada capturado, é grátis)
     *   já processado              → /refund   (dinheiro volta, com custo)
     *
     * Por isso consulta antes: só o estado atual diz qual dos dois cabe.
     */
    public function cancelar(string $chargeId, ?int $valorCentavos = null, bool $porAntifraude = false): PagamentoClassificacao
    {
        $t0    = microtime(true);
        $atual = $this->http('GET', '/v1/orders/' . rawurlencode($chargeId), null);

        $status = (string) ($atual['body']['status'] ?? '');
        if ($status === '') {
            $c = $this->classificar(PagamentoClassificacao::ERRO_TECNICO, 'consulta_falhou');
            $c->mensagemAdquirente   = $atual['erro'] ?? ('HTTP ' . $atual['http']);
            $c->cancelamentoPendente = true;
            $c->duracaoMs            = self::ms($t0);
            return $c;
        }

        $cancelavel = in_array($status, ['created', 'action_required'], true);

        if ($cancelavel) {
            $r = $this->http('POST', '/v1/orders/' . rawurlencode($chargeId) . '/cancel', null,
                             ['X-Idempotency-Key: mp-can-' . mb_substr($chargeId, 0, 100)]);
        } else {
            // Estorno parcial precisa do id da TRANSAÇÃO, não do pedido.
            $corpo = null;
            if ($valorCentavos !== null) {
                $txId = (string) ($this->primeiroPagamento($atual['body'])['id'] ?? '');
                if ($txId !== '') {
                    $corpo = ['transactions' => [['id' => $txId, 'amount' => self::valor($valorCentavos)]]];
                }
            }
            $r = $this->http('POST', '/v1/orders/' . rawurlencode($chargeId) . '/refund', $corpo,
                             ['X-Idempotency-Key: mp-ref-' . mb_substr($chargeId, 0, 90) . '-' . (int) $valorCentavos]);
        }

        $ok = $r['erro'] === null && $r['http'] >= 200 && $r['http'] < 300;

        $c = $this->classificar(
            $ok ? PagamentoClassificacao::APROVADO : PagamentoClassificacao::ERRO_TECNICO,
            $ok ? ($cancelavel ? 'cancelado' : 'estornado') : 'cancelamento_falhou'
        );
        $c->chargeId             = $chargeId;
        $c->httpStatus           = $r['http'];
        $c->duracaoMs            = self::ms($t0);
        $c->mensagemAdquirente   = $ok ? null : mb_substr((string) ($r['body']['message'] ?? $r['raw']), 0, 200);
        // Falhou: alguém precisa tentar de novo, não dá para dar por encerrado.
        $c->cancelamentoPendente = !$ok;

        if (!$ok) {
            LogService::error('Mercado Pago recusou cancelamento/estorno', [
                'order_id' => $chargeId, 'status_atual' => $status,
                'operacao' => $cancelavel ? 'cancel' : 'refund',
                'http'     => $r['http'], 'antifraude' => $porAntifraude,
            ], 'pagamento');
        }

        return $c;
    }

    // =========================================================================

    /** Envelope do pedido, comum aos três métodos. */
    private function pedido(array $d, array $pagamento, bool $comEntrega = false): array
    {
        $corpo = [
            'type'               => 'online',
            'processing_mode'    => 'automatic',
            'external_reference' => self::referencia($d),
            'total_amount'       => self::valor($d['valor_centavos'] ?? 0),
            'description'        => mb_substr((string) ($d['descricao_fatura'] ?? 'Compra'), 0, 255),
            // ARRAY, sempre — nos dois sentidos. Os exemplos da referência
            // mostram um objeto aqui, e a API recusa:
            //   "'$.transactions.payments' - expected array, but got object"
            'transactions'       => ['payments' => [$pagamento]],
            // Pagador COMPLETO mesmo no cartão. A referência dá first_name,
            // last_name e address como opcionais; a API exige nos três
            // métodos. Descoberto pela validação, não pela documentação.
            'payer'              => $this->pagador($d, true),
        ];

        // Pix e boleto exigem endereço de entrega; o cartão não.
        if ($comEntrega) {
            $end = $this->endereco($d);
            if ($end) $corpo['shipment'] = ['address' => $end];
        }

        return $corpo;
    }

    private function criar(array $corpo, array $d, string $tipo): PagamentoClassificacao
    {
        $t0 = microtime(true);

        // A CHAVE DE IDEMPOTÊNCIA É O QUE IMPEDE COBRANÇA DUPLA.
        // Derivada da tentativa, não aleatória: se a rede cair depois de o MP
        // ter criado o pedido e o motor repetir a chamada, a mesma chave
        // devolve o MESMO pedido em vez de criar um segundo. O tipo entra
        // junto para um Pix e um boleto da mesma tentativa não colidirem.
        $chave = 'mp-' . $tipo . '-'
               . ($d['tentativa_ref'] ?? $d['order_id_loja'] ?? bin2hex(random_bytes(8)));

        $r = $this->http('POST', '/v1/orders', $corpo,
                         ['X-Idempotency-Key: ' . mb_substr($chave, 0, 128)]);

        if ($r['erro'] !== null) {
            // Não sabemos se chegou a criar. Perguntar antes de tentar outra
            // adquirente é o que evita cobrar o cliente duas vezes.
            $c = $this->classificar(PagamentoClassificacao::INCERTO, 'timeout');
            $c->mensagemAdquirente = $r['erro'];
            $c->exigeConsulta      = true;
            $c->duracaoMs          = self::ms($t0);
            LogService::error('Mercado Pago sem resposta', [
                'tipo' => $tipo, 'pedido' => $d['order_id_loja'] ?? null, 'erro' => $r['erro'],
            ], 'pagamento');
            return $c;
        }

        $c = $this->interpretar($r, $tipo);
        $c->duracaoMs = self::ms($t0);
        return $c;
    }

    private function interpretar(array $r, string $tipo): PagamentoClassificacao
    {
        $b    = $r['body'];
        $http = $r['http'];

        // ARMADILHA DO ENVELOPE DE ERRO: o Mercado Pago reusa o nome `status`
        // para o código HTTP quando a requisição falha —
        //   {"message":"...","error":"unauthorized","status":401}
        // Lido como resposta de pedido, isso vira um status "401" que não
        // existe, a mensagem real se perde e o motivo some do log.
        $envelopeDeErro = !isset($b['id'])
            && (isset($b['error']) || is_int($b['status'] ?? null) || $b === []);

        // RECUSA NAO E ERRO, E VEM COM HTTP 402.
        //
        // O Mercado Pago recusa cartao com 402 e embrulha o pedido inteiro
        // dentro de `data`, com os motivos em `errors[].details`. Tratar todo
        // 4xx como falha tecnica fazia TODA recusa do emissor virar
        // erro_tecnico — e erro_tecnico autoriza o motor a tentar outra
        // adquirente. Ou seja: cada cartao recusado seria reapresentado em
        // outra adquirente, que e exatamente a retentativa que gera multa de
        // bandeira (Visa Excessive Reattempts / Mastercard TPE).
        //
        // Enquanto vier um pedido no corpo, isto e decisao de negocio, nao
        // falha de transporte — independente do codigo HTTP.
        $b = $b['data'] ?? $b;

        $temPedido = isset($b['id']) && isset($b['transactions']);

        if (!$temPedido && ($envelopeDeErro || $http >= 400)) {
            return $this->erro($r, $tipo, $http, $r['body']);
        }

        $pg = $this->primeiroPagamento($b);

        // O detalhe do PAGAMENTO é mais específico que o do pedido — é onde
        // aparece a recusa do emissor. Só cai para o do pedido quando falta.
        $det    = (string) ($pg['status_detail'] ?? $b['status_detail'] ?? '');
        $status = (string) ($pg['status'] ?? $b['status'] ?? '');

        $c = $this->classificar($this->porta($status, $det), $det !== '' ? $det : $status);

        $c->chargeId           = isset($b['id']) ? (string) $b['id'] : null;
        $c->httpStatus         = $http;
        $c->codigoAdquirente   = $det !== '' ? $det : null;
        $c->mensagemAdquirente = $status;

        $mp = $pg['payment_method'] ?? [];
        $c->bandeira = isset($mp['id']) ? (string) $mp['id'] : null;

        // ── Instrumentos ────────────────────────────────────────────
        if (!empty($mp['qr_code'])) {
            $c->pixQrCode       = (string) $mp['qr_code'];
            $c->pixQrCodeBase64 = (string) ($mp['qr_code_base64'] ?? '') ?: null;
            $c->pixExpiraEm     = (string) ($pg['date_of_expiration'] ?? '') ?: null;
        }

        $linha = (string) ($mp['digitable_line'] ?? '');
        $barra = (string) ($mp['barcode_content'] ?? '');
        if ($linha !== '' || $barra !== '') {
            $c->boletoLinhaDigitavel = $linha ?: null;
            $c->boletoCodigoBarras   = $barra ?: null;
            $c->boletoUrl            = (string) ($mp['ticket_url'] ?? '') ?: null;
            $c->boletoVencimento     = (string) ($pg['date_of_expiration'] ?? '') ?: null;
        }

        return $c;
    }

    /**
     * Resposta que não é um pedido: transporte, credencial ou requisição
     * malformada. Nenhum desses casos é decisão do emissor, então todos
     * permitem cair para outra adquirente.
     */
    private function erro(array $r, string $tipo, int $http, array $b): PagamentoClassificacao
    {
        $msg = (string) ($b['message'] ?? $b['error'] ?? $r['raw']);

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

        LogService::error('Mercado Pago recusou a requisicao', [
            'tipo'   => $tipo,
            'http'   => $http,
            'erro'   => $b['error'] ?? null,
            'motivo' => mb_substr($msg, 0, 300),
            'causa'  => isset($b['errors']) ? json_encode($b['errors']) : null,
        ], 'pagamento');

        return $c;
    }

    private function porta(string $status, string $detalhe): string
    {
        if (isset(self::MAPA_DETALHE[$detalhe])) {
            return self::MAPA_DETALHE[$detalhe];
        }

        // Codigo novo do lado deles: classifica pelo que a string diz, e na
        // duvida fecha como recusa do emissor. Nunca inventa permissao de
        // retentativa a partir de um codigo que ninguem viu antes.
        if ($detalhe !== '') {
            if (str_contains($detalhe, 'insufficient'))  return PagamentoClassificacao::NEGADO_SALDO;
            if (str_contains($detalhe, 'bad_filled')
                || str_contains($detalhe, 'invalid')
                || str_contains($detalhe, 'disabled'))   return PagamentoClassificacao::NEGADO_DADOS;
            if (str_contains($detalhe, 'fraud')
                || str_contains($detalhe, 'high_risk')
                || str_contains($detalhe, 'blacklist'))  return PagamentoClassificacao::NEGADO_ANTIFRAUDE;
            if (str_contains($detalhe, 'rejected')
                || str_contains($detalhe, 'issuer'))     return PagamentoClassificacao::NEGADO_GENERICO;
        }

        return match ($status) {
            'processed'       => PagamentoClassificacao::APROVADO,
            'created'         => PagamentoClassificacao::PENDENTE,
            'processing'      => PagamentoClassificacao::INCERTO,
            'action_required' => PagamentoClassificacao::INCERTO,
            'canceled'        => PagamentoClassificacao::NEGADO_GENERICO,
            // `failed` no nivel do pedido: o detalhe do pagamento e que diz
            // o motivo. Chegar aqui significa detalhe desconhecido — fecha
            // como recusa do emissor, que e a leitura que nao retenta.
            'failed'          => PagamentoClassificacao::NEGADO_GENERICO,
            // Detalhe desconhecido: fecha como decisão do emissor, que é a
            // leitura que NÃO retenta. Ver o comentário do mapa.
            'rejected'        => PagamentoClassificacao::NEGADO_GENERICO,
            default           => PagamentoClassificacao::ERRO_TECNICO,
        };
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

    /** Na resposta `transactions.payments` é array; aceita objeto por segurança. */
    private function primeiroPagamento(array $corpo): array
    {
        $p = $corpo['transactions']['payments'] ?? [];
        if (!is_array($p) || $p === []) return [];
        return isset($p[0]) && is_array($p[0]) ? $p[0] : $p;
    }

    /**
     * Tokeniza o cartão no servidor.
     *
     * FALLBACK, não o caminho. O certo é o navegador tokenizar com
     * MercadoPago.js e mandar só o token — assim o PAN nunca toca nosso
     * servidor e a loja fica fora do escopo PCI.
     */
    private function tokenizarNoServidor(array $d): string
    {
        $cartao = $d['cartao'] ?? [];
        $pan    = self::digitos((string) ($cartao['numero'] ?? ''));

        if ($pan === '' || $this->publicKey === '') return '';

        LogService::warning('Cartao tokenizado no servidor (o navegador deveria fazer isso)', [
            'pedido' => $d['order_id_loja'] ?? null,
        ], 'pagamento');

        [$mes, $ano] = self::validade((string) ($cartao['validade'] ?? ''));

        $r = $this->http('POST', '/v1/card_tokens?public_key=' . urlencode($this->publicKey), [
            'card_number'      => $pan,
            'security_code'    => (string) ($cartao['cvv'] ?? ''),
            'expiration_month' => $mes,
            'expiration_year'  => $ano,
            'cardholder'       => [
                'name'           => (string) ($cartao['titular'] ?? ''),
                'identification' => [
                    'type'   => 'CPF',
                    'number' => self::digitos((string) ($cartao['documento'] ?? $d['cliente']['documento'] ?? '')),
                ],
            ],
        ], [], false);

        return (string) ($r['body']['id'] ?? '');
    }

    private function pagador(array $d, bool $completo = false): array
    {
        $cli = $d['cliente'] ?? [];
        $doc = self::digitos((string) ($cli['documento'] ?? ''));

        $p = ['email' => $this->emailPagador((string) ($cli['email'] ?? ''))];

        if ($doc !== '') {
            $p['identification'] = ['type' => strlen($doc) > 11 ? 'CNPJ' : 'CPF', 'number' => $doc];
            $p['entity_type']    = strlen($doc) > 11 ? 'association' : 'individual';
        }

        if (!$completo) return $p;

        $nome  = trim((string) ($cli['nome'] ?? ''));
        $parte = $nome !== '' ? (preg_split('/\s+/', $nome) ?: []) : [];
        $p['first_name'] = (string) ($parte[0] ?? 'Cliente');
        $p['last_name']  = count($parte) > 1 ? (string) end($parte) : 'Sobrenome';

        $fone = self::digitos((string) ($cli['telefone'] ?? ''));
        if (strlen($fone) >= 10) {
            $p['phone'] = ['area_code' => substr($fone, 0, 2), 'number' => substr($fone, 2)];
        }

        $end = $this->endereco($d);
        if ($end) $p['address'] = $end;

        return $p;
    }

    /**
     * E-mail do pagador, com uma acomodacao SO DE SANDBOX.
     *
     * O ambiente de teste do Mercado Pago recusa qualquer e-mail que nao
     * termine em @testuser.com:
     *   "Email format is invalid for sandbox environment"
     *
     * Sem isto, nenhum checkout real e testavel — o e-mail do cliente derruba
     * o pagamento antes de qualquer coisa. Em PRODUCAO o e-mail vai intacto:
     * substituir o e-mail de um comprador de verdade quebraria o recibo, a
     * cobranca e o suporte.
     *
     * O apelido preserva o original, entao da para rastrear de quem era.
     */
    private function emailPagador(string $email): string
    {
        if ($this->ambiente !== 'sandbox' || $email === '') return $email;
        if (str_ends_with(strtolower($email), '@testuser.com')) return $email;

        // Estavel por cliente: o mesmo e-mail gera sempre o mesmo apelido,
        // entao o Mercado Pago enxerga um comprador so entre as compras.
        $apelido = 'test_user_' . substr(hash('sha256', strtolower($email)), 0, 12) . '@testuser.com';

        LogService::info('E-mail do pagador trocado (regra do sandbox do Mercado Pago)', [
            'original' => preg_replace('/(.{2}).*(@.*)/', '$1***$2', $email),
            'usado'    => $apelido,
        ], 'pagamento');

        return $apelido;
    }

    private function endereco(array $d): array
    {
        $e = ($d['entrega'] ?? []) ?: ($d['cliente']['endereco'] ?? []);
        if (!$e) return [];

        return array_filter([
            'zip_code'      => self::digitos((string) ($e['cep'] ?? '')),
            'street_name'   => (string) ($e['logradouro'] ?? $e['rua'] ?? ''),
            'street_number' => (string) ($e['numero'] ?? ''),
            'neighborhood'  => (string) ($e['bairro'] ?? ''),
            'city'          => (string) ($e['cidade'] ?? ''),
            'state'         => mb_strtoupper(mb_substr((string) ($e['uf'] ?? $e['estado'] ?? ''), 0, 2)),
            'complement'    => (string) ($e['complemento'] ?? ''),
        ], static fn($v) => $v !== '');
    }

    // ── utilitários ─────────────────────────────────────────────────────

    /** Valor em STRING com 2 casas — a Orders API recusa número. */
    private static function valor(int|float $centavos): string
    {
        return number_format(((int) $centavos) / 100, 2, '.', '');
    }

    /**
     * `external_reference` aceita só alfanumérico, hífen e sublinhado, até 64
     * chars. Um código de pedido com barra ou acento faz o MP recusar o
     * pedido inteiro, e o motivo não é óbvio na mensagem de erro.
     */
    private static function referencia(array $d): string
    {
        $ref = (string) ($d['order_id_loja'] ?? '');
        $ref = preg_replace('/[^A-Za-z0-9_-]/', '-', $ref) ?? '';
        return mb_substr($ref, 0, 64) ?: ('ped-' . bin2hex(random_bytes(6)));
    }

    /** Aparece na fatura do cliente. Só letras, números e espaço. */
    private static function descritor(array $d): string
    {
        $t = preg_replace('/[^A-Za-z0-9 ]/', '', (string) ($d['descricao_fatura'] ?? '')) ?? '';
        return mb_substr(trim($t), 0, 50);
    }

    private static function ms(float $t0): int
    {
        return (int) ((microtime(true) - $t0) * 1000);
    }

    private static function iso8601(int $ts): string
    {
        // Offset explícito: sem ele o MP interpreta como UTC e o vencimento
        // sai 3 horas fora do que a loja combinou com o cliente.
        return date('Y-m-d\TH:i:s.000P', $ts);
    }

    private static function digitos(string $v): string
    {
        return preg_replace('/\D/', '', $v) ?? '';
    }

    /** "12/2030" ou "12/30" → [12, 2030] */
    private static function validade(string $v): array
    {
        $d   = self::digitos($v);
        $mes = (int) substr($d, 0, 2);
        $ano = (int) substr($d, 2);
        if ($ano < 100) $ano += 2000;
        return [$mes, $ano];
    }

    private static function bandeiraParaMp(string $b): string
    {
        return match (strtolower($b)) {
            'mastercard' => 'master',
            'amex'       => 'amex',
            'elo'        => 'elo',
            'hipercard'  => 'hipercard',
            'visa'       => 'visa',
            default      => '',   // sem bandeira, o MP deduz pelo BIN do token
        };
    }

    /**
     * protected, não private: o script de teste estende esta classe e
     * intercepta aqui para inspecionar o corpo exato sem enviar nada.
     *
     * @return array{http:int, body:array, raw:string, erro:?string}
     */
    protected function http(string $metodo, string $recurso, ?array $corpo, array $extra = [], bool $autenticado = true): array
    {
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($autenticado) $headers[] = 'Authorization: Bearer ' . $this->accessToken;
        foreach ($extra as $h) $headers[] = $h;

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $metodo,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 6,
        ];
        if ($corpo !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($corpo, JSON_UNESCAPED_UNICODE);
        }

        $ch = curl_init(self::BASE . $recurso);
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

    private static function cfg(string $chave): string
    {
        $v = getenv($chave);
        if ($v !== false && $v !== '') return (string) $v;
        if (!empty($_ENV[$chave]))    return (string) $_ENV[$chave];
        if (!empty($_SERVER[$chave])) return (string) $_SERVER[$chave];
        if (defined($chave) && is_string(constant($chave))) return (string) constant($chave);
        return '';
    }
}
