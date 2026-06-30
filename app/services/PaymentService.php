<?php
declare(strict_types=1);

/**
 * PaymentService
 *
 * Orquestrador entre o CheckoutController e o gateway de pagamento.
 *
 * Responsabilidades:
 *   1. Receber dados normalizados do pedido (do checkout)
 *   2. Chamar o adapter ativo (via PaymentGatewayFactory)
 *   3. Persistir o resultado em pgto_transacoes (audit log)
 *   4. Retornar PaymentGatewayResult pro controller atualizar pedidos
 *
 * Não toca em `pedidos` diretamente — esse é trabalho do CheckoutController.
 * Razão: manter PaymentService agnóstico ao schema de negócio do ecommerce.
 */
class PaymentService
{
    private PaymentGatewayInterface $gateway;
    private PDO $db;

    public function __construct(?PaymentGatewayInterface $gateway = null)
    {
        $this->gateway = $gateway ?? PaymentGatewayFactory::current();
        $this->db     = Database::getInstance()->getConnection();
    }

    public function getGatewayCodigo(): string
    {
        return $this->gateway->getCodigo();
    }

    /**
     * Processa um pagamento. Faz a cobrança no gateway e grava em
     * pgto_transacoes (independente de sucesso ou falha — auditoria
     * completa).
     *
     * @param array $dados Mesma estrutura esperada por PaymentGatewayInterface::cobrar()
     *                     mais 'pedido_id' (int) pro vínculo.
     */
    public function processarPagamento(array $dados): PaymentGatewayResult
    {
        $this->validarDados($dados);
    
        // ← NOVO: gera chave determinística por pedido+método
        $idempotencyKey = MalgaService::gerarIdempotencyKey(
            (string) ($dados['order_id_loja'] ?? ''),
            (string) ($dados['metodo']        ?? '')
        );
    
        // ← NOVO: injeta no array para o adapter repassar à Malga 
        $dados['idempotency_key'] = $idempotencyKey;
    
        // Grava intenção ANTES de chamar o gateway
        $transacaoId = $this->registrarIntencao($dados);
    
        // ← NOVO: persiste a chave na transação para auditoria e retentativas
        try {
            $this->db->prepare(
                "UPDATE pgto_transacoes SET idempotency_key = :k WHERE id = :id"
            )->execute([':k' => $idempotencyKey, ':id' => $transacaoId]);
        } catch (\Throwable $e) {
            // Coluna pode não existir ainda (migration pendente) — não bloqueia
            LogService::warning('[PaymentService] idempotency_key não persistida: ' . $e->getMessage());
        }
    
        // Chama o gateway via adapter (o adapter já recebe $dados com a chave)
        $resultado = $this->gateway->cobrar($dados);
    
        $this->atualizarTransacao($transacaoId, $resultado);
    
        return $resultado;
    }

    /**
     * Tokeniza cartão. Persiste log no pgto_transacoes apenas se o gateway
     * cobra por isso (Malga não cobra) — por enquanto não logamos.
     */
    public function tokenizarCartao(array $dadosCartao): array
    {
        return $this->gateway->tokenizarCartao($dadosCartao);
    }

    /**
     * Consulta status atualizado de uma cobrança no gateway.
     * Útil pra crons de reconciliação e pro success page reabrir.
     */
    public function consultarStatus(string $chargeId): PaymentGatewayResult
    {
        $resultado = $this->gateway->consultar($chargeId);

        // Atualiza a transação local se ela existir
        if ($resultado->ok) {
            $this->db->prepare(
                "UPDATE pgto_transacoes
                    SET status = :status,
                        raw_response = :raw,
                        pago_em = CASE WHEN :status2 = 'aprovado' AND pago_em IS NULL THEN NOW() ELSE pago_em END,
                        atualizado_em = NOW()
                  WHERE charge_id = :cid"
            )->execute([
                ':status'  => $resultado->status,
                ':status2' => $resultado->status,
                ':raw'     => json_encode($resultado->raw, JSON_UNESCAPED_UNICODE),
                ':cid'     => $chargeId,
            ]);
        }
        return $resultado;
    }

    /**
     * Estorno (refund) — também loga em pgto_transacoes.
     */
    public function estornar(string $chargeId, ?int $valorCentavos = null): PaymentGatewayResult
    {
        $resultado = $this->gateway->estornar($chargeId, $valorCentavos);

        if ($resultado->ok) {
            $this->db->prepare(
                "UPDATE pgto_transacoes
                    SET status = 'estornado',
                        raw_response = :raw,
                        atualizado_em = NOW()
                  WHERE charge_id = :cid"
            )->execute([
                ':raw' => json_encode($resultado->raw, JSON_UNESCAPED_UNICODE),
                ':cid' => $chargeId,
            ]);

            if (class_exists('LogService') && method_exists('LogService', 'audit')) {
                LogService::audit('pagamento_estornado', [
                    'charge_id' => $chargeId,
                    'valor_centavos' => $valorCentavos,
                ]);
            }
        }
        return $resultado;
    }

    // =================================================================
    // PRIVADOS
    // =================================================================

    private function validarDados(array $d): void
    {
        foreach (['order_id_loja', 'valor_centavos', 'metodo'] as $f) {
            if (empty($d[$f])) {
                throw new InvalidArgumentException("PaymentService: '{$f}' é obrigatório");
            }
        }
        if (!in_array($d['metodo'], ['pix', 'boleto', 'cartao'], true)) {
            throw new InvalidArgumentException("Método inválido: {$d['metodo']}");
        }
    }

    /**
     * Insere linha em pgto_transacoes ANTES de chamar o gateway.
     * Status inicial: 'pendente'. Retorna o id pra UPDATE posterior.
     */
    private function registrarIntencao(array $d): int
    {
        // Resolve gateway_id na tabela
        $stmt = $this->db->prepare(
            "SELECT id FROM pgto_gateways WHERE codigo = :c LIMIT 1"
        );
        $stmt->execute([':c' => $this->gateway->getCodigo()]);
        $gatewayDbId = (int) ($stmt->fetchColumn() ?: 0);

        // Se o gateway atual não tem registro no DB (caso do fake),
        // inserimos um placeholder pra manter FK válida
        if ($gatewayDbId === 0 && $this->gateway->getCodigo() === 'fake') {
            $this->db->exec(
                "INSERT IGNORE INTO pgto_gateways
                    (codigo, nome, ativo, sandbox, client_id, api_key, merchant_id)
                 VALUES ('fake', 'Fake (testes)', 1, 1, '-', '-', '-')"
            );
            $stmt->execute([':c' => 'fake']);
            $gatewayDbId = (int) ($stmt->fetchColumn() ?: 0);
        }

        $this->db->prepare(
            "INSERT INTO pgto_transacoes
                (gateway_id, pedido_id, order_id_loja, cliente_id, valor_centavos,
                 moeda, metodo, parcelas, status, raw_request, ip_origem)
             VALUES (:gw, :ped, :oid, :cli, :val, 'BRL', :met, :par, 'pendente', :req, :ip)"
        )->execute([
            ':gw'  => $gatewayDbId,
            ':ped' => $d['pedido_id'] ?? null,
            ':oid' => $d['order_id_loja'],
            ':cli' => $d['cliente_id'] ?? null,
            ':val' => (int) $d['valor_centavos'],
            ':met' => $d['metodo'],
            ':par' => $d['parcelas'] ?? null,
            ':req' => json_encode($this->sanitizarParaLog($d), JSON_UNESCAPED_UNICODE),
            ':ip'  => $d['ip_origem'] ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Atualiza a transação com a resposta do gateway.
     */
    private function atualizarTransacao(int $transacaoId, PaymentGatewayResult $r): void
    {
        $pagoEm = $r->status === 'aprovado' ? date('Y-m-d H:i:s') : null;

        $this->db->prepare(
            "UPDATE pgto_transacoes
                SET charge_id              = :cid,
                    status                 = :st,
                    provedor_real          = :pr,
                    declined_code          = :dc,
                    declined_message       = :dm,
                    pix_qrcode             = :pqr,
                    pix_qrcode_image       = :pimg,
                    pix_expira_em          = :pexp,
                    boleto_linha_digitavel = :bld,
                    boleto_codigo_barras   = :bcb,
                    boleto_pdf_url         = :bpdf,
                    boleto_vencimento      = :bvenc,
                    raw_response           = :raw,
                    pago_em                = :pago,
                    atualizado_em          = NOW()
              WHERE id = :id"
        )->execute([
            ':cid'  => $r->chargeId,
            ':st'   => $r->status,
            ':pr'   => $r->provedorReal,
            ':dc'   => $r->errorCode,
            ':dm'   => $r->errorMessage,
            ':pqr'  => $r->pixCopiaCola,
            ':pimg' => $r->pixQrCode,
            ':pexp' => $r->pixExpiraEm,
            ':bld'  => $r->boletoLinhaDigitavel,
            ':bcb'  => $r->boletoCodigoBarras,
            ':bpdf' => $r->boletoUrl,
            ':bvenc'=> $r->boletoVencimento,
            ':raw'  => json_encode($r->raw, JSON_UNESCAPED_UNICODE),
            ':pago' => $pagoEm,
            ':id'   => $transacaoId,
        ]);
    }

    /**
     * Remove campos sensíveis antes de gravar em raw_request.
     * IMPORTANTE: token_cartao é OK (é tokenId, não PAN), mas
     * dadosCartao bruto (se viesse) precisaria ser removido aqui.
     */
    private function sanitizarParaLog(array $d): array
    {
        // Hoje os dados que chegam aqui já estão sem PAN/CVV (chegou só o tokenId).
        // Defensivo: se vier algo sensível, remove.
        unset($d['cartao_dados']['cvv'], $d['cartao_dados']['numero']);
        return $d;
    }
}
