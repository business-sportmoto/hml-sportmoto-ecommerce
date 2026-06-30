<?php
/**
 * MalgaService
 *
 * Cliente HTTP da API Malga (https://docs.malga.io). Implementação em cURL
 * puro, sem Composer/Guzzle, alinhada ao stack do SportMoto.
 *
 * Responsabilidade ÚNICA: fazer IO com a API. Toda lógica de domínio
 * (criar pedido, atualizar status, enviar e-mail de confirmação) fica no
 * PaymentService que consome este serviço.
 *
 * Autenticação: headers X-Client-Id e X-Api-Key em toda request.
 * Valores monetários: SEMPRE em centavos (Malga espera assim).
 *
 * Sandbox: A Malga não usa URL diferente. O ambiente é determinado pelas
 * credenciais e pelo provedor cadastrado no merchant (provedor tipo SANDBOX).
 *
 * Dependências do framework (espera autoload):
 *   - Database          (PDO via Database::getInstance()->getConnection())
 *   - LogService        (LogService::info/error)
 *   - MalgaException    (mesmo namespace/pasta)
 */
class MalgaService
{
    const BASE_URL          = 'https://api.malga.io';
    const VERSAO_API        = 'v1';
    const TIMEOUT_PADRAO    = 30;
    const USER_AGENT        = 'SportMoto/1.0 (+PHP MalgaService)';

    /** @var string */
    private $clientId;

    /** @var string */
    private $apiKey;

    /** @var string */
    private $merchantId;

    /** @var int */
    private $timeout = self::TIMEOUT_PADRAO;

    /** @var bool Loga payloads completos em LogService (cuidado em produção: dados sensíveis) */
    private $logRequests = true;

    /** @var int|null id da linha em pgto_gateways usada pra instanciar */
    private $gatewayId = null;

    /**
     * @param array $config
     *   - client_id   (string, obrigatório)
     *   - api_key     (string, obrigatório)
     *   - merchant_id (string, obrigatório)
     *   - timeout     (int, opcional)
     *   - log_requests (bool, opcional)
     *   - gateway_id  (int, opcional - id da linha em pgto_gateways)
     *
     * @throws InvalidArgumentException quando config inválido
     */
    public function __construct(array $config)
    {
        foreach (['client_id', 'api_key', 'merchant_id'] as $obrigatorio) {
            if (empty($config[$obrigatorio])) {
                throw new InvalidArgumentException("MalgaService: config '{$obrigatorio}' é obrigatório");
            }
        }

        $this->clientId    = (string) $config['client_id'];
        $this->apiKey      = (string) $config['api_key'];
        $this->merchantId  = (string) $config['merchant_id'];
        $this->timeout     = (int) ($config['timeout'] ?? self::TIMEOUT_PADRAO);
        $this->logRequests = (bool) ($config['log_requests'] ?? true);
        $this->gatewayId   = isset($config['gateway_id']) ? (int) $config['gateway_id'] : null;
    }

    /**
     * Factory: carrega configuração do banco pelo código do gateway.
     * Use esta forma na maioria dos casos.
     */
    public static function fromCodigo(string $codigo = 'malga'): self
    {
        $pdo = Database::getInstance()->getConnection();
        $stmt = $pdo->prepare(
            'SELECT id, client_id, api_key, merchant_id, ativo, sandbox
               FROM pgto_gateways
              WHERE codigo = :codigo
              LIMIT 1'
        );
        $stmt->execute([':codigo' => $codigo]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException("Gateway '{$codigo}' não encontrado em pgto_gateways");
        }
        if ((int) $row['ativo'] !== 1) {
            throw new RuntimeException("Gateway '{$codigo}' está desativado");
        }

        return new self([
            'client_id'   => $row['client_id'],
            'api_key'     => $row['api_key'],
            'merchant_id' => $row['merchant_id'],
            'gateway_id'  => (int) $row['id'],
        ]);
    }

    public function getMerchantId(): string
    {
        return $this->merchantId;
    }

    public function getGatewayId(): ?int
    {
        return $this->gatewayId;
    }

    // =================================================================
    // CHARGES - cria, consulta, captura, estorna
    // =================================================================

    /**
     * Cria uma cobrança via PIX. Retorna o objeto charge completo da Malga,
     * incluindo o QR Code (copia-e-cola) e a imagem.
     *
     * @param int    $valorCentavos        Valor em centavos (ex.: 9990 = R$ 99,90)
     * @param string $orderIdLoja          Identificador único do pedido no seu sistema (idempotência)
     * @param array  $customer             ['name', 'email', 'phoneNumber', 'document' => ['type', 'number', 'country']]
     * @param int    $expiracaoSegundos    Validade do PIX em segundos (padrão 1h)
     * @param string|null $descricao
     *
     * @return array Resposta crua decodificada da API
     * @throws MalgaException
     */
    public function criarChargePix(
        int $valorCentavos,
        string $orderIdLoja,
        array $customer,
        int $expiracaoSegundos = 3600,
        ?string $descricao = null,
        ?string $idempotencyKey = null
    ): array {
        $this->validarValor($valorCentavos);
        $this->validarCustomer($customer);

        $payload = [
            'merchantId'    => $this->merchantId,
            'amount'        => $valorCentavos,
            'currency'      => 'BRL',
            'orderId'       => $orderIdLoja,
            'paymentMethod' => [
                'paymentType' => 'pix',
                'expiresIn'   => $expiracaoSegundos,
            ],
            'paymentSource' => [
                'sourceType' => 'customer',
                'customer'   => $this->montarCustomerPayload($customer),
            ],
        ];

        if ($descricao !== null && $descricao !== '') {
            $payload['description'] = mb_substr($descricao, 0, 255);
        }

        return $this->request('POST', '/charges', $payload, $idempotencyKey);
    }

    /**
     * Cria uma cobrança via Boleto.
     *
     * @param int    $valorCentavos
     * @param string $orderIdLoja
     * @param array  $customer
     * @param string $vencimento  Data de vencimento (formato Y-m-d)
     * @param array  $opcoes      Suporta: instructions, fine[days|amount|percentage], interest[...]
     */
    public function criarChargeBoleto(
        int $valorCentavos,
        string $orderIdLoja,
        array $customer,
        string $vencimento,
        array $opcoes = [],
        ?string $idempotencyKey = null
    ): array {
        $this->validarValor($valorCentavos);
        $this->validarCustomer($customer);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $vencimento)) {
            throw new InvalidArgumentException('Vencimento deve estar no formato YYYY-MM-DD');
        }

        $paymentMethod = [
            'paymentType' => 'boleto',
            'expiresDate' => $vencimento,
        ];
        foreach (['instructions', 'fine', 'interest'] as $opt) {
            if (isset($opcoes[$opt])) {
                $paymentMethod[$opt] = $opcoes[$opt];
            }
        }

        $payload = [
            'merchantId'    => $this->merchantId,
            'amount'        => $valorCentavos,
            'currency'      => 'BRL',
            'orderId'       => $orderIdLoja,
            'paymentMethod' => $paymentMethod,
            'paymentSource' => [
                'sourceType' => 'customer',
                'customer'   => $this->montarCustomerPayload($customer),
            ],
        ];

        if (!empty($opcoes['descricao'])) {
            $payload['description'] = mb_substr((string) $opcoes['descricao'], 0, 255);
        }

        return $this->request('POST', '/charges', $payload, $idempotencyKey);
    }

    /**
     * Cria uma cobrança via cartão de crédito usando um token já gerado pelo SDK
     * client-side (tokenização no navegador). É a forma PCI-compliant.
     *
     * @param int    $valorCentavos
     * @param string $orderIdLoja
     * @param string $tokenId       UUID do token gerado pelo SDK Malga no front
     * @param int    $parcelas
     * @param array  $customer      Dados do comprador (vai no antifraude/cobrança recorrente)
     * @param array  $opcoes        capture (bool, default true), statementDescriptor
     */
    public function criarChargeCartao(
        int $valorCentavos,
        string $orderIdLoja,
        string $tokenId,
        int $parcelas,
        array $customer,
        array $opcoes = [],
        ?string $idempotencyKey = null
    ): array {
        $this->validarValor($valorCentavos);
        $this->validarCustomer($customer);

        if (!preg_match('/^[0-9a-f-]{36}$/i', $tokenId)) {
            throw new InvalidArgumentException('tokenId deve ser UUID v4');
        }
        if ($parcelas < 1 || $parcelas > 12) {
            throw new InvalidArgumentException('Parcelas devem estar entre 1 e 12');
        }

        $payload = [
            'merchantId'    => $this->merchantId,
            'amount'        => $valorCentavos,
            'currency'      => 'BRL',
            'orderId'       => $orderIdLoja,
            'capture'       => (bool) ($opcoes['capture'] ?? true),
            'paymentMethod' => [
                'paymentType'  => 'credit',
                'installments' => $parcelas,
            ],
            'paymentSource' => [
                'sourceType' => 'token',
                'tokenId'    => $tokenId,
            ],
        ];

        if (!empty($opcoes['statementDescriptor'])) {
            $payload['statementDescriptor'] = mb_substr((string) $opcoes['statementDescriptor'], 0, 13);
        }

        if (!empty($customer)) {
            $payload['fraudAnalysis'] = [
                'customer' => $this->montarCustomerAntifraude($customer),
            ];
        }

        return $this->request('POST', '/charges', $payload, $idempotencyKey);
    }

    /**
     * Cria uma cobrança de cartão usando cardId permanente do vault Malga.
     * Use este método para cartões já salvos (via criarCartaoVault).
     * sourceType: 'card' não expira como o 'token'.
     */
    public function criarChargeComCardId(
        int $valorCentavos,
        string $orderIdLoja,
        string $cardId,
        int $parcelas,
        array $customer,
        array $opcoes = [],
        ?string $idempotencyKey = null
    ): array {
        $this->validarValor($valorCentavos);
        $this->validarCustomer($customer);

        if (!preg_match('/^[0-9a-f-]{36}$/i', $cardId)) {
            throw new InvalidArgumentException('cardId deve ser UUID v4');
        }
        if ($parcelas < 1 || $parcelas > 12) {
            throw new InvalidArgumentException('Parcelas devem estar entre 1 e 12');
        }

        $payload = [
            'merchantId'    => $this->merchantId,
            'amount'        => $valorCentavos,
            'currency'      => 'BRL',
            'orderId'       => $orderIdLoja,
            'capture'       => (bool) ($opcoes['capture'] ?? true),
            'paymentMethod' => [
                'paymentType'  => 'credit',
                'installments' => $parcelas,
            ],
            'paymentSource' => [
                'sourceType' => 'card',
                'cardId'     => $cardId,
            ],
        ];

        if (!empty($opcoes['statementDescriptor'])) {
            $payload['statementDescriptor'] = mb_substr((string) $opcoes['statementDescriptor'], 0, 13);
        }

        if (!empty($customer)) {
            $payload['fraudAnalysis'] = [
                'customer' => $this->montarCustomerAntifraude($customer),
            ];
        }

        return $this->request('POST', '/charges', $payload, $idempotencyKey);
    }

    /**
     * Salva um cartão no vault da Malga usando um tokenId efêmero.
     * Retorna o cardId permanente que deve ser armazenado em cartoes_salvos.token.
     *
     * Fluxo correto para cartões salvos:
     *   1. SDK front gera tokenId (expira em minutos, uso único)
     *   2. Este método cria o card no vault da Malga → cardId permanente
     *   3. Salva o cardId no banco
     *   4. Cobranças futuras usam criarChargeComCardId() com sourceType:'card'
     */
    public function criarCartaoVault(string $tokenId): array
    {
        if (!preg_match('/^[0-9a-f-]{36}$/i', $tokenId)) {
            throw new InvalidArgumentException('tokenId deve ser UUID v4');
        }

        // POST /v1/cards — resposta: { id, status, brand, last4digits, ... }
        // cvvCheck: true  → faz uma zero-dollar auth para verificar o CVV
        // Resultado: card fica com cvvChecked=true, sem invalid_cvv nas cobranças
        $resposta = $this->request('POST', '/cards', [
            'tokenId'    => $tokenId,
            'merchantId' => $this->merchantId,
            'cvvCheck'   => true,
        ]);

        $cardId = $resposta['id'] ?? null;  // FIX: campo correto é 'id', não 'cardId'
        if (empty($cardId)) {
            throw new \RuntimeException(
                'Malga não retornou id ao criar o cartão no vault. ' .
                'Status: ' . ($resposta['status'] ?? '?') . '. ' .
                'Reason: ' . ($resposta['statusReason'] ?? '?')
            );
        }

        if (($resposta['status'] ?? '') === 'failed') {
            throw new \RuntimeException(
                'Malga recusou o cartão no vault (status: failed). ' .
                'Motivo: ' . ($resposta['statusReason'] ?? 'desconhecido')
            );
        }

        return [
            'cardId'  => (string) $cardId,
            'status'  => $resposta['status'] ?? 'unknown',
            'bandeira'=> strtolower($resposta['brand'] ?? ''),
            'last4'   => $resposta['last4digits'] ?? null,  // FIX: campo correto é 'last4digits'
            'first6'  => $resposta['first6digits'] ?? null,
            'raw'     => $resposta,
        ];
    }

    /**
     * Associa um cardId (vault) a um customer na Malga.
     * POST /v1/customers/{customerId}/cards — retorna 204 sem body.
     * Necessário para o cartão aparecer no dashboard e ser usado com sourceType:'customer'.
     */
    /**
     * Cria um customer na Malga via POST /v1/customers.
     * Retorna o array completo da resposta (incluindo 'id').
     *
     * @param array $dados ['nome'|'name', 'email', 'telefone'|'phone', 'documento'|'document']
     */
    public function criarCustomer(array $dados): array
    {
        // Normaliza chaves — aceita tanto 'nome'/'documento'/'telefone' (PT)
        // quanto 'name'/'document'/'phone' (EN)
        $nome      = $dados['nome']      ?? $dados['name']      ?? '';
        $email     = $dados['email']     ?? '';
        $telefone  = $dados['telefone']  ?? $dados['phone']     ?? $dados['phoneNumber'] ?? '';
        $documento = $dados['documento'] ?? $dados['document']  ?? $dados['cpf'] ?? '';

        if (empty($nome) || empty($email)) {
            throw new InvalidArgumentException('criarCustomer requer nome e email');
        }

        // Monta payload no formato da Malga
        $doc = preg_replace('/\D/', '', (string) $documento);
        $tipoDoc = strlen($doc) === 14 ? 'cnpj' : 'cpf';

        $payload = [
            'name'        => (string) $nome,
            'email'       => (string) $email,
            'phoneNumber' => $this->normalizarTelefone($telefone),
            'document'    => [
                'number'  => $doc,
                'type'    => $tipoDoc,
                'country' => 'BR',
            ],
        ];

        return $this->request('POST', '/customers', $payload);
    }

        public function associarCartaoAoCustomer(string $customerId, string $cardId): void
    {
        if (!preg_match('/^[0-9a-f-]{36}$/i', $customerId)) {
            throw new InvalidArgumentException('customerId deve ser UUID v4');
        }
        if (!preg_match('/^[0-9a-f-]{36}$/i', $cardId)) {
            throw new InvalidArgumentException('cardId deve ser UUID v4');
        }
        // POST retorna 204 No Content — nenhum body para processar
        $this->request('POST', "/customers/{$customerId}/cards", [
            'cardId' => $cardId,
        ]);
    }

    /**
     * Remove a associação de um card do customer na Malga.
     * DELETE /v1/customers/{customerId}/cards/{cardId} → 204 No Content.
     *
     * A Malga não tem DELETE /v1/cards/{id} — o card permanece no vault
     * mas fica desassociado do customer e não pode ser reutilizado.
     */
    public function removerCartaoDoCustomer(string $customerId, string $cardId): void
    {
        if (empty($customerId) || empty($cardId)) return;
        // 204 No Content — sem body, o request() já trata isso
        $this->request('DELETE', "/customers/{$customerId}/cards/{$cardId}");
    }

    /**
     * Retorna o customerId Malga de um cliente, criando-o se necessário.
     *
     * @param array       $dadosCliente         [nome, email, telefone, documento]
     * @param string|null $malgaCustomerIdSalvo ID já salvo em clientes.malga_customer_id
     * @return string customerId da Malga
     */
    public function buscarOuCriarCustomerPorCliente(
        array $dadosCliente,
        ?string $malgaCustomerIdSalvo = null
    ): string {
        // 1. Usa ID já salvo se válido
        if (!empty($malgaCustomerIdSalvo)) {
            try {
                $c = $this->request('GET', "/customers/{$malgaCustomerIdSalvo}");
                if (!empty($c['id'])) return (string) $c['id'];
            } catch (\Throwable $e) {
                // ID inválido ou deletado — cria novo
            }
        }

        // 2. Cria customer — Malga retorna 409 se e-mail já existe
        try {
            $novo = $this->criarCustomer($dadosCliente);
            if (empty($novo['id'])) {
                throw new \RuntimeException('Malga não retornou id ao criar customer');
            }
            return (string) $novo['id'];

        } catch (MalgaException $e) {
            $body    = $e->getResponseBody();
            $errCode = (int) ($body['error']['code'] ?? $body['body']['error']['code'] ?? 0);

            if ($errCode === 409) {
                // 409 = CPF/e-mail duplicado — a Malga NÃO retorna o customerId
                // no corpo do erro. Precisamos buscar pelo documento ou e-mail.
                //
                // Estratégia 1: extrai o número do documento da mensagem de erro
                // ex: "Document number #86019325091 already exists."
                $mensagem = $body['error']['message'] ?? '';
                $docExtraido = null;
                if (preg_match('/#?(\d{11,14})/', $mensagem, $dm)) {
                    $docExtraido = $dm[1];
                }
                if (!$docExtraido) {
                    // Fallback: usa o documento dos dados do cliente
                    $docExtraido = preg_replace('/\D/', '', (string)(
                        $dadosCliente['documento'] ?? $dadosCliente['document'] ?? $dadosCliente['cpf'] ?? ''
                    ));
                }

                // Estratégia 2: busca por documento (GET /customers?document=CPF)
                if (!empty($docExtraido)) {
                    try {
                        $lista = $this->request('GET', '/customers?document.number=' . urlencode($docExtraido));
                        $items = $lista['items'] ?? (isset($lista['id']) ? [$lista] : []);
                        if (!empty($items[0]['id'])) return (string) $items[0]['id'];
                    } catch (\Throwable $de) { /* tenta próxima estratégia */ }
                }

                // Estratégia 3: busca por nome (GET /customers?name=...)
                $nome = $dadosCliente['nome'] ?? $dadosCliente['name'] ?? '';
                if (!empty($nome)) {
                    try {
                        $lista = $this->request('GET', '/customers?name=' . urlencode($nome) . '&limit=5');
                        $items = $lista['items'] ?? [];
                        // Verifica se algum bate pelo documento
                        foreach ($items as $item) {
                            $docItem = preg_replace('/\D/', '', (string)(
                                $item['document']['number'] ?? $item['documentNumber'] ?? ''
                            ));
                            if ($docExtraido && $docItem === $docExtraido) {
                                return (string) $item['id'];
                            }
                        }
                        // Se não achou pelo documento, retorna o primeiro (menos seguro)
                        if (!empty($items[0]['id'])) return (string) $items[0]['id'];
                    } catch (\Throwable $ne) { /* tenta próxima */ }
                }

                // Estratégia 4: extrai UUID do body completo do erro (às vezes vem)
                $responseStr = json_encode($body);
                if (preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $responseStr, $m)) {
                    return $m[0];
                }
            }
            throw $e;
        }
    }

    public function consultarCharge(string $chargeId): array
    {
        $this->validarChargeId($chargeId);
        return $this->request('GET', "/charges/{$chargeId}");
    }

    /** Captura uma cobrança pré-autorizada (cartão com capture=false). */
    public function capturarCharge(string $chargeId, ?int $valorCentavos = null): array
    {
        $this->validarChargeId($chargeId);
        $payload = $valorCentavos !== null ? ['amount' => $valorCentavos] : null;
        return $this->request('POST', "/charges/{$chargeId}/capture", $payload);
    }

    /** Estorna (refund) uma cobrança aprovada. */
    public function estornarCharge(string $chargeId, ?int $valorCentavos = null): array
    {
        $this->validarChargeId($chargeId);
        $payload = $valorCentavos !== null ? ['amount' => $valorCentavos] : null;
        return $this->request('POST', "/charges/{$chargeId}/void", $payload);
    }

    /**
     * SANDBOX ONLY: força um status numa charge de teste pra simular pagamento.
     * Status válidos: pre_authorized, authorized, failed, charged_back.
     */
    public function alterarStatusSandbox(string $chargeId, string $novoStatus): array
    {
        $this->validarChargeId($chargeId);
        $validos = ['pre_authorized', 'authorized', 'failed', 'charged_back'];
        if (!in_array($novoStatus, $validos, true)) {
            throw new InvalidArgumentException('Status sandbox inválido: ' . $novoStatus);
        }
        return $this->request('POST', "/charges/{$chargeId}/status", ['status' => $novoStatus]);
    }

    // =================================================================
    // HELPERS DE DOMÍNIO PÚBLICOS
    // =================================================================

    /**
     * Mapeia o status da Malga pra um vocabulário interno simplificado.
     * Usar no PaymentService antes de gravar.
     */
    public static function mapearStatus(string $statusMalga): string
    {
        $mapa = [
            'pending'           => 'pendente',
            'pre_authorized'    => 'pre_autorizado',
            'authorized'        => 'aprovado',
            'capture_pending'   => 'aprovado',
            'failed'            => 'falhou',
            'canceled'          => 'cancelado',
            'voided'            => 'estornado',
            'refund_pending'    => 'estorno_pendente',
            'charged_back'      => 'chargeback',
        ];
        return $mapa[$statusMalga] ?? 'desconhecido';
    }

    // =================================================================
    // IDEMPOTÊNCIA
    // =================================================================

    /**
     * Gera uma chave de idempotência determinística por pedido+método.
     *
     * Determinística = mesmo pedido retentado gera a MESMA chave.
     * A Malga devolve o resultado original sem processar novamente.
     *
     * Formato UUID v4 sintético (compatível com o requisito da Malga).
     *
     * @param string $orderIdLoja  Código do pedido (ex: "ABC12345")
     * @param string $metodo       'pix' | 'boleto' | 'cartao'
     * @return string  UUID v4 determinístico
     */
    public static function gerarIdempotencyKey(string $orderIdLoja, string $metodo): string
    {
        // SHA-256 do input → UUID v4 sintético (determinístico)
        $hash = hash('sha256', $orderIdLoja . ':' . $metodo);
        return sprintf(
            '%s-%s-4%s-%x%s-%s',
            substr($hash,  0, 8),
            substr($hash,  8, 4),
            substr($hash, 13, 3),
            (hexdec(substr($hash, 16, 1)) & 0x3) | 0x8,
            substr($hash, 17, 3),
            substr($hash, 20, 12)
        );
    }

        // =================================================================
    // PRIVADOS
    // =================================================================

    /**
     * Faz a requisição HTTP. Centralizada pra ter um único ponto de log,
     * timeout e tratamento de erro.
     *
     * @throws MalgaException
     */
    private function request(string $metodo, string $endpoint, ?array $payload = null, ?string $idempotencyKey = null): array
    {
        $metodo = strtoupper($metodo);
        $url    = self::BASE_URL . '/' . self::VERSAO_API . $endpoint;
        $body   = $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Client-Id: ' . $this->clientId,
            'X-Api-Key: '   . $this->apiKey,
            'User-Agent: '  . self::USER_AGENT,
        ];

        // Idempotência — previne cobranças duplicadas em retentativas
        if ($idempotencyKey !== null && $metodo === 'POST') {
            $headers[] = 'X-Idempotency-Key: ' . $idempotencyKey;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL             => $url,
            CURLOPT_CUSTOMREQUEST   => $metodo,
            CURLOPT_HTTPHEADER      => $headers,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_TIMEOUT         => $this->timeout,
            CURLOPT_CONNECTTIMEOUT  => 10,
            CURLOPT_FOLLOWLOCATION  => false,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $tempoInicio = microtime(true);
        $respostaCrua = curl_exec($ch);
        $duracaoMs   = (int) round((microtime(true) - $tempoInicio) * 1000);
        $httpCode    = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $erroCurl    = curl_error($ch);
        $errnoCurl   = curl_errno($ch);
        curl_close($ch);

        // Log (cuidado: o body contém dados sensíveis no caso de cartão one-shot)
        if ($this->logRequests && class_exists('LogService')) {
            LogService::info('Malga request', [
                'metodo'      => $metodo,
                'endpoint'    => $endpoint,
                'http_code'   => $httpCode,
                'duracao_ms'  => $duracaoMs,
                'erro_curl'   => $erroCurl ?: null,
            ]);
        }

        // Erro de transporte
        if ($respostaCrua === false || $errnoCurl !== 0) {
            $msg = "Falha de rede ao chamar Malga ({$endpoint}): {$erroCurl} [errno {$errnoCurl}]";
            if (class_exists('LogService')) {
                LogService::error($msg, ['endpoint' => $endpoint, 'errno' => $errnoCurl]);
            }
            throw new MalgaException($msg, 0);
        }

        // 204 No Content (e outros 2xx sem corpo) — retorna array vazio diretamente
        // ex: POST /customers/{id}/cards retorna 204 sem body
        if ($httpCode === 204 || trim($respostaCrua) === '') {
            if ($httpCode >= 200 && $httpCode < 300) {
                return [];
            }
        }

        $decoded = json_decode($respostaCrua, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $msg = "Resposta Malga não é JSON válido ({$endpoint}): " . json_last_error_msg();
            throw new MalgaException($msg, $httpCode);
        }

        // 2xx = sucesso
        if ($httpCode >= 200 && $httpCode < 300) {
            return is_array($decoded) ? $decoded : [];
        }

        // Erro: a Malga devolve { "error": { "code": ..., "message": ... } }
        $mensagemErro = $decoded['error']['message']
            ?? $decoded['message']
            ?? "HTTP {$httpCode} na chamada {$metodo} {$endpoint}";

        if (class_exists('LogService')) {
            LogService::error('Malga retornou erro', [
                'endpoint'  => $endpoint,
                'http_code' => $httpCode,
                'body'      => $decoded,
            ]);
        }

        throw new MalgaException($mensagemErro, $httpCode, is_array($decoded) ? $decoded : []);
    }

    private function validarValor(int $valorCentavos): void
    {
        if ($valorCentavos < 100) {
            throw new InvalidArgumentException('Valor mínimo: 100 centavos (R$ 1,00)');
        }
    }

    private function validarChargeId(string $chargeId): void
    {
        if ($chargeId === '') {
            throw new InvalidArgumentException('chargeId vazio');
        }
    }

    /**
     * @param array $customer Aceita formato amigável; converte pro esperado pela Malga
     */
    private function validarCustomer(array $customer): void
    {
        foreach (['name', 'email', 'document'] as $obrigatorio) {
            if (empty($customer[$obrigatorio])) {
                throw new InvalidArgumentException("Customer requer campo '{$obrigatorio}'");
            }
        }
        if (!filter_var($customer['email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('E-mail do customer inválido');
        }
    }

    /**
     * Aceita formatos:
     *   ['document' => '12345678901']                     -> assume CPF
     *   ['document' => ['number' => '...', 'type'=>'cpf', 'country'=>'BR']]
     */
    private function montarCustomerPayload(array $customer): array
    {
        $doc = $customer['document'];
        if (is_string($doc)) {
            $apenasDigitos = preg_replace('/\D/', '', $doc);
            $tipo = strlen($apenasDigitos) === 14 ? 'cnpj' : 'cpf';
            $doc = ['number' => $apenasDigitos, 'type' => $tipo, 'country' => 'BR'];
        } else {
            $doc['number'] = preg_replace('/\D/', '', (string) $doc['number']);
            $doc['type']    = strtolower($doc['type'] ?? 'cpf');  // document.type aceita minúsculo na Malga
            $doc['country'] = strtoupper($doc['country'] ?? 'BR');
        }

        $payload = [
            'name'        => (string) $customer['name'],
            'email'       => (string) $customer['email'],
            'phoneNumber' => $this->normalizarTelefone($customer['phoneNumber'] ?? $customer['phone'] ?? ''),
            'document'    => $doc,
        ];

        if (!empty($customer['address'])) {
            $payload['address'] = $customer['address'];
        }

        return $payload;
    }

    private function montarCustomerAntifraude(array $customer): array
    {
        $doc = $customer['document'];
        if (is_array($doc)) {
            $tipoDoc = strtoupper($doc['type'] ?? 'CPF');   // FIX: Malga exige maiúsculo
            $numero  = preg_replace('/\D/', '', (string) ($doc['number'] ?? ''));
        } else {
            $numero  = preg_replace('/\D/', '', (string) $doc);
            $tipoDoc = strlen($numero) === 14 ? 'CNPJ' : 'CPF';  // FIX: maiúsculo
        }

        return [
            'name'         => (string) $customer['name'],
            'email'        => (string) $customer['email'],
            'phone'        => $this->normalizarTelefone($customer['phoneNumber'] ?? $customer['phone'] ?? ''),
            'identityType' => $tipoDoc,
            'identity'     => $numero,
        ];
    }

    /**
     * Normaliza pra +55DDDNNNNNNNNN.
     * Aceita: "(11) 99999-9999", "11999999999", "+5511999999999".
     */
    private function normalizarTelefone(string $telefone): string
    {
        $apenas = preg_replace('/\D/', '', $telefone);
        if ($apenas === '') {
            return '';
        }
        // Já vem com 55 no começo?
        if (strpos($apenas, '55') === 0 && strlen($apenas) >= 12) {
            return '+' . $apenas;
        }
        return '+55' . $apenas;
    }
}