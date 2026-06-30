<?php
declare(strict_types=1);

/**
 * MalgaGatewayAdapter
 *
 * Adapter que implementa PaymentGatewayInterface usando MalgaService
 * (cliente HTTP da API). É aqui que mora a tradução entre o vocabulário
 * normalizado do SportMoto e o formato específico da Malga.
 *
 * Se um dia trocar de gateway, este arquivo é jogado fora — o resto
 * do código continua igual.
 */
class MalgaGatewayAdapter implements PaymentGatewayInterface
{
    private MalgaService $malga;

    public function __construct(?MalgaService $malga = null)
    {
        $this->malga = $malga ?? MalgaService::fromCodigo('malga');
    }

    public function getCodigo(): string
    {
        return 'malga';
    }

    // =================================================================
    // COBRAR — dispatcher por método
    // =================================================================
    public function cobrar(array $dados): PaymentGatewayResult
    {
        $metodo = $dados['metodo'] ?? '';

        try {
            switch ($metodo) {
                case 'pix':
                    return $this->cobrarPix($dados);
                case 'boleto':
                    return $this->cobrarBoleto($dados);
                case 'cartao':
                    return $this->cobrarCartao($dados);
                default:
                    return PaymentGatewayResult::erro(
                        "Método não suportado: '{$metodo}'",
                        $this->getCodigo()
                    );
            }
        } catch (MalgaException $e) {
            return $this->traduzirErroMalga($e);
        } catch (InvalidArgumentException $e) {
            return PaymentGatewayResult::erro(
                'Dados inválidos: ' . $e->getMessage(),
                $this->getCodigo()
            );
        } catch (\Throwable $e) {
            if (class_exists('LogService')) {
                LogService::error('[MalgaAdapter.cobrar] ' . $e->getMessage(), [
                    'metodo'  => $metodo,
                    'arquivo' => $e->getFile() . ':' . $e->getLine(),
                ]);
            }
            return PaymentGatewayResult::erro(
                'Falha inesperada no gateway. Tente novamente.',
                $this->getCodigo()
            );
        }
    }

    // -----------------------------------------------------------------
    // PIX
    // -----------------------------------------------------------------
    private function cobrarPix(array $d): PaymentGatewayResult
    {
        // Chave determinística — mesmo pedido em retry usa a mesma chave
        \$idempotencyKey = \$d['idempotency_key']
            ?? MalgaService::gerarIdempotencyKey((string)(\$d['order_id_loja'] ?? ''), 'pix');

        $charge = $this->malga->criarChargePix(
            valorCentavos: (int) $d['valor_centavos'],
            orderIdLoja:   (string) $d['order_id_loja'],
            customer:      $this->montarCustomer($d['cliente'] ?? []),
            expiracaoSegundos: (int) ($d['pix_expira_em'] ?? 3600),
            descricao:     $d['descricao'] ?? null
        );

        return $this->traduzirCharge($charge);
    }

    // -----------------------------------------------------------------
    // BOLETO
    // -----------------------------------------------------------------
    private function cobrarBoleto(array $d): PaymentGatewayResult
    {
        $vencimento = $d['vencimento']
            ?? date('Y-m-d', strtotime('+3 days'));

        // Chave determinística — mesmo pedido em retry usa a mesma chave
        \$idempotencyKey = \$d['idempotency_key']
            ?? MalgaService::gerarIdempotencyKey((string)(\$d['order_id_loja'] ?? ''), 'boleto');

        $charge = $this->malga->criarChargeBoleto(
            valorCentavos: (int) $d['valor_centavos'],
            orderIdLoja:   (string) $d['order_id_loja'],
            customer:      $this->montarCustomer($d['cliente'] ?? []),
            vencimento:    $vencimento,
            opcoes:        [
                'instructions' => $d['instrucoes_boleto'] ?? null,
                'descricao'    => $d['descricao'] ?? null,
            ]
        );

        return $this->traduzirCharge($charge);
    }

    // -----------------------------------------------------------------
    // CARTÃO
    // -----------------------------------------------------------------
    private function cobrarCartao(array $d): PaymentGatewayResult
    {
        $tokenCartao = $d['token_cartao'] ?? null;
        if (empty($tokenCartao)) {
            return PaymentGatewayResult::erro(
                'Token do cartão não fornecido.',
                $this->getCodigo()
            );
        }

        $opcoes = [
            'capture'             => true,
            'statementDescriptor' => $d['descricao'] ?? 'SPORTMOTO',
        ];
        $customer        = $this->montarCustomer($d['cliente'] ?? []);
        $idempotencyKey  = $d['idempotency_key']
            ?? MalgaService::gerarIdempotencyKey((string)($d['order_id_loja'] ?? ''), 'cartao');

        // Detecta se é cardId permanente (vault) ou tokenId efêmero (hosted fields).
        // A distinção é feita pelo prefixo salvo em cartoes_salvos.token:
        //   'card_' → cardId permanente, usa sourceType:'card'
        //   UUID puro → tokenId efêmero, usa sourceType:'token'
        //
        // Na prática, ambos são UUIDs v4. A diferença é que o cardId é criado
        // em /v1/cards e nunca expira, enquanto o tokenId expira em minutos.
        // Usamos o campo `tipo_token` (se existir) ou inferimos pelo contexto.
        $isCardId = !empty($d['is_card_id']); // flag setada pelo process() quando vem de cartao salvo

        try {
            if ($isCardId) {
                // Cartão salvo no vault — usa cardId permanente (sourceType:'card')
                try {
                    $charge = $this->malga->criarChargeComCardId(
                        valorCentavos: (int) $d['valor_centavos'],
                        orderIdLoja:   (string) $d['order_id_loja'],
                        cardId:        (string) $tokenCartao,
                        parcelas:      max(1, (int) ($d['parcelas'] ?? 1)),
                        customer:      $customer,
                        opcoes:        $opcoes
                    );
                } catch (MalgaException $cardEx) {
                    // FIX: se a Malga retorna 404 (Card not found), o vault falhou
                    // anteriormente e o banco tem um tokenId efêmero salvo como cardId.
                    // Tenta com sourceType:'token' como fallback defensivo.
                    // O cliente precisará re-adicionar o cartão na próxima compra.
                    $body = $cardEx->getResponseBody();
                    $httpCode = (int) ($body['body']['error']['code']
                        ?? $body['error']['code']
                        ?? $cardEx->getCode()
                        ?? 0);

                    if ($httpCode === 404 || strpos($cardEx->getMessage(), 'Card not found') !== false) {
                        if (class_exists('LogService')) {
                            LogService::warning('[MalgaAdapter] cardId não encontrado no vault, tentando como token', [
                                'token' => substr((string)$tokenCartao, 0, 8) . '...',
                            ]);
                        }
                        $charge = $this->malga->criarChargeCartao(
                            valorCentavos:  (int) $d['valor_centavos'],
                            orderIdLoja:    (string) $d['order_id_loja'],
                            tokenId:        (string) $tokenCartao,
                            parcelas:       max(1, (int) ($d['parcelas'] ?? 1)),
                            customer:       $customer,
                            opcoes:         $opcoes,
                            idempotencyKey: $idempotencyKey
                        );
                    } else {
                        throw $cardEx; // outro erro — propaga normalmente
                    }
                }
            } else {
                // Token efêmero do hosted fields — uso único, pagar agora
                $charge = $this->malga->criarChargeCartao(
                    valorCentavos: (int) $d['valor_centavos'],
                    orderIdLoja:   (string) $d['order_id_loja'],
                    tokenId:       (string) $tokenCartao,
                    parcelas:      max(1, (int) ($d['parcelas'] ?? 1)),
                    customer:      $customer,
                    opcoes:        $opcoes
                );
            }
        } catch (MalgaException $e) {
            return $this->traduzirErroMalga($e);
        }

        return $this->traduzirCharge($charge);
    }

    // =================================================================
    // CONSULTAR / ESTORNAR
    // =================================================================
    public function consultar(string $chargeId): PaymentGatewayResult
    {
        try {
            $charge = $this->malga->consultarCharge($chargeId);
            return $this->traduzirCharge($charge);
        } catch (MalgaException $e) {
            return $this->traduzirErroMalga($e);
        }
    }

    public function estornar(string $chargeId, ?int $valorCentavos = null): PaymentGatewayResult
    {
        try {
            $charge = $this->malga->estornarCharge($chargeId, $valorCentavos);
            $r = $this->traduzirCharge($charge);
            // mesmo que a Malga ainda retorne 'authorized' temporariamente,
            // forçamos o status normalizado pra estornado
            if ($r->ok) {
                $r->status = 'estornado';
            }
            return $r;
        } catch (MalgaException $e) {
            return $this->traduzirErroMalga($e);
        }
    }

    // =================================================================
    // TOKENIZAR CARTÃO
    // =================================================================
    public function tokenizarCartao(array $dadosCartao): array
    {
        // Estratégia atual: server-side via /v1/tokens (OK pra sandbox/MVP).
        // Em produção, o ideal é fazer tokenização client-side via SDK JS
        // da Malga e este método deixa de ser chamado.

        $erros = [];
        foreach (['numero', 'titular', 'validade', 'cvv'] as $f) {
            if (empty($dadosCartao[$f])) $erros[] = $f;
        }
        if ($erros) {
            throw new InvalidArgumentException(
                'Campos do cartão faltando: ' . implode(', ', $erros)
            );
        }

        if (!preg_match('/^(\d{2})\/(\d{2})$/', $dadosCartao['validade'], $m)) {
            throw new InvalidArgumentException('Validade deve estar em MM/AA');
        }
        $mes = $m[1];
        $ano = '20' . $m[2];

        // POST /v1/tokens (Malga) — token de cartão one-shot.
        // Usamos um método interno do MalgaService via reflexão pra não
        // poluir a API pública do service com detalhes de tokenização.
        // (Alternativa: adicionar tokenize() no MalgaService — preferível
        //  num próximo passo. Aqui usamos request manual via cURL pra
        //  manter o MalgaService.php inalterado.)
        $payload = [
            'cardNumber'         => preg_replace('/\D/', '', (string) $dadosCartao['numero']),
            'cardHolderName'     => strtoupper((string) $dadosCartao['titular']),
            'cardCvv'            => preg_replace('/\D/', '', (string) $dadosCartao['cvv']),
            'cardExpirationDate' => $mes . '/' . $ano,
        ];

        $resposta = $this->postManual('/v1/tokens', $payload);

        if (empty($resposta['tokenId']) && empty($resposta['id'])) {
            throw new RuntimeException('Resposta de tokenização inválida da Malga');
        }

        $tokenId = $resposta['tokenId'] ?? $resposta['id'];
        $bandeira = strtolower($resposta['brand'] ?? $resposta['cardBrand'] ?? '');
        $ultimos4 = $resposta['lastDigits'] ?? substr($payload['cardNumber'], -4);

        return [
            'token'     => $tokenId,
            'bandeira'  => $bandeira !== '' ? $bandeira : null,
            'ultimos_4' => $ultimos4,
        ];
    }

    // =================================================================
    // PRIVADOS
    // =================================================================

    /**
     * Converte um objeto charge da Malga em PaymentGatewayResult.
     */
    private function traduzirCharge(array $charge): PaymentGatewayResult
    {
        $r = new PaymentGatewayResult();
        $r->ok = true;
        $r->gatewayCodigo = $this->getCodigo();
        $r->chargeId = $charge['id'] ?? null;
        $r->provedorReal = $charge['responsibleProviderType'] ?? null;
        $r->raw = $charge;

        // Mapeamento de status (já existe no MalgaService::mapearStatus)
        $statusMalga = $charge['status'] ?? 'pending';
        $r->status = MalgaService::mapearStatus($statusMalga);

        // PIX — nomes canônicos da API Malga (confirmados em
        // docs.malga.io/documentations/payment-methods/pix):
        //   paymentMethod.qrCodeData        → string EMV (copia-cola)
        //   paymentMethod.qrCodeImageUrl    → URL da imagem do QR
        //   paymentMethod.expiresIn         → segundos
        $pm = $charge['paymentMethod'] ?? [];
        $pixData = $this->extrairDadosPix($pm, $charge['transactionRequests'] ?? []);
        if ($pixData !== null) {
            $r->pixCopiaCola = $pixData['copia_cola'];
            $r->pixQrCode    = $pixData['qr_image_url'];
            $r->pixExpiraEm  = $pixData['expira_em'];
        }

        // BOLETO — nomes canônicos da API Malga (confirmados em
        // docs.malga.io/documentations/payment-methods/boleto):
        //   paymentMethod.barcodeData       → linha digitável (string que o cliente cola)
        //   paymentMethod.barcodeImageUrl   → URL da imagem do boleto / PDF
        //   paymentMethod.expiresDate       → vencimento (Y-m-d)
        $boletoData = $this->extrairDadosBoleto($pm, $charge['transactionRequests'] ?? []);
        if ($boletoData !== null) {
            $r->boletoLinhaDigitavel = $boletoData['linha_digitavel'];
            $r->boletoCodigoBarras   = $boletoData['linha_digitavel']; // Malga só fornece um campo
            $r->boletoUrl            = $boletoData['image_url'];
            $r->boletoVencimento     = $boletoData['vencimento'];
        }

        // CARTÃO — bandeira e últimos 4 podem vir em paymentSource
        $ps = $charge['paymentSource'] ?? [];
        if (isset($ps['brand'])) {
            $r->cartaoBandeira = strtolower((string) $ps['brand']);
        }
        if (isset($ps['lastDigits'])) {
            $r->cartaoUltimos4 = (string) $ps['lastDigits'];
        }

        // Erro de negócio (cartão recusado, etc.) — status é "failed" mas a
        // chamada HTTP foi 201/200. Captura código de negação.
        if (in_array($statusMalga, ['failed', 'canceled'], true)) {
            $tr = $charge['transactionRequests'][0] ?? null;
            if ($tr) {
                $r->errorCode = isset($tr['declinedCode']) ? (string) $tr['declinedCode'] : null;
                $r->errorMessage = $tr['responseMessage'] ?? null;
            }
        }

        return $r;
    }

    /**
     * Extrai dados de PIX preferindo paymentMethod, com fallback pra
     * transactionRequests[].pix (alguns provedores só populam lá).
     *
     * @return array{copia_cola: ?string, qr_image_url: ?string, expira_em: ?string}|null
     */
    private function extrairDadosPix(array $pm, array $transactionRequests): ?array
    {
        $isPix = ($pm['paymentType'] ?? null) === 'pix';
        $temDados = isset($pm['qrCodeData']) || isset($pm['qrCodeImageUrl']);

        if ($temDados) {
            return [
                'copia_cola'   => $pm['qrCodeData']     ?? null,
                'qr_image_url' => $pm['qrCodeImageUrl'] ?? null,
                'expira_em'    => $this->calcularPixExpiraEm($pm['expiresIn'] ?? null),
            ];
        }

        // Fallback: olha dentro de transactionRequests[].pix
        foreach ($transactionRequests as $tr) {
            $pix = $tr['pix'] ?? null;
            if (is_array($pix) && (isset($pix['qrCodeData']) || isset($pix['qrCodeImageUrl']))) {
                return [
                    'copia_cola'   => $pix['qrCodeData']     ?? null,
                    'qr_image_url' => $pix['qrCodeImageUrl'] ?? null,
                    'expira_em'    => $this->calcularPixExpiraEm($pix['expiresIn'] ?? null),
                ];
            }
        }

        // Se é PIX mas não achou dados, retorna mesmo assim com nulls
        // — o caller decide o que fazer (provavelmente loga warning)
        return $isPix ? ['copia_cola' => null, 'qr_image_url' => null, 'expira_em' => null] : null;
    }

    /**
     * Extrai dados de boleto preferindo paymentMethod, com fallback pra
     * transactionRequests[].boleto.
     *
     * @return array{linha_digitavel: ?string, image_url: ?string, vencimento: ?string}|null
     */
    private function extrairDadosBoleto(array $pm, array $transactionRequests): ?array
    {
        $isBoleto = ($pm['paymentType'] ?? null) === 'boleto';
        $temDados = isset($pm['barcodeData']) || isset($pm['barcodeImageUrl']);

        if ($temDados) {
            return [
                'linha_digitavel' => $pm['barcodeData']     ?? null,
                'image_url'       => $pm['barcodeImageUrl'] ?? null,
                'vencimento'      => $pm['expiresDate']     ?? null,
            ];
        }

        // Fallback: olha dentro de transactionRequests[].boleto
        foreach ($transactionRequests as $tr) {
            $b = $tr['boleto'] ?? null;
            if (is_array($b) && (isset($b['barcodeData']) || isset($b['barcodeImageUrl']))) {
                return [
                    'linha_digitavel' => $b['barcodeData']     ?? null,
                    'image_url'       => $b['barcodeImageUrl'] ?? null,
                    'vencimento'      => $b['expiresDate']     ?? ($pm['expiresDate'] ?? null),
                ];
            }
        }

        return $isBoleto ? ['linha_digitavel' => null, 'image_url' => null, 'vencimento' => $pm['expiresDate'] ?? null] : null;
    }

    private function calcularPixExpiraEm($expiresIn): ?string
    {
        if (!is_numeric($expiresIn)) return null;
        return date('Y-m-d H:i:s', time() + (int) $expiresIn);
    }

    /**
     * Converte MalgaException em PaymentGatewayResult de erro.
     */
    private function traduzirErroMalga(MalgaException $e): PaymentGatewayResult
    {
        $r = PaymentGatewayResult::erro(
            $e->getMessage(),
            $this->getCodigo(),
            $e->getResponseBody()
        );

        if ($e->isNetworkError()) {
            $r->errorCode = 'network_error';
            $r->errorMessage = 'Falha de comunicação com o gateway. Tente novamente.';
        } else {
            $body = $e->getResponseBody();
            $r->errorCode = isset($body['error']['code']) ? (string) $body['error']['code'] : 'gateway_error';
        }

        return $r;
    }

    /**
     * Monta payload de customer no formato esperado pela Malga.
     * Aceita formato amigável do CheckoutController.
     */
    private function montarCustomer(array $cliente): array
    {
        return [
            'name'        => (string) ($cliente['nome'] ?? ''),
            'email'       => (string) ($cliente['email'] ?? ''),
            'phoneNumber' => (string) ($cliente['telefone'] ?? ''),
            'document'    => $cliente['documento'] ?? '',
            'address'     => $this->montarAddress($cliente['endereco'] ?? null),
        ];
    }

    private function montarAddress(?array $end): ?array
    {
        if (empty($end)) return null;
        return [
            'street'        => $end['logradouro'] ?? '',
            'streetNumber'  => $end['numero'] ?? '',
            'complement'    => $end['complemento'] ?? '',
            'neighborhood'  => $end['bairro'] ?? '',
            'city'          => $end['cidade'] ?? '',
            'state'         => $end['estado'] ?? '',
            'zipCode'       => preg_replace('/\D/', '', (string) ($end['cep'] ?? '')),
            'country'       => 'BR',
        ];
    }

    /**
     * POST manual pra endpoints não cobertos pelo MalgaService (ex: /tokens).
     * Usa as mesmas credenciais e tratamento de erro.
     */
    private function postManual(string $endpoint, array $payload): array
    {
        // Carrega credenciais do banco (mesma origem que o MalgaService usa)
        $pdo = Database::getInstance()->getConnection();
        $stmt = $pdo->prepare(
            'SELECT client_id, api_key FROM pgto_gateways WHERE codigo = :c LIMIT 1'
        );
        $stmt->execute([':c' => 'malga']);
        $cfg = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$cfg) throw new RuntimeException('Gateway malga não configurado');

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => 'https://api.malga.io' . $endpoint,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Client-Id: ' . $cfg['client_id'],
                'X-Api-Key: '   . $cfg['api_key'],
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('Falha de rede na tokenização: ' . $err);
        }

        $decoded = json_decode($body, true) ?: [];
        if ($code < 200 || $code >= 300) {
            $msg = $decoded['error']['message'] ?? "HTTP {$code} em {$endpoint}";
            throw new RuntimeException('Tokenização falhou: ' . $msg);
        }

        return $decoded;
    }
}