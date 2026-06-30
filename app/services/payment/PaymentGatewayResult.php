<?php
declare(strict_types=1);

/**
 * PaymentGatewayResult
 *
 * DTO normalizado retornado por todos os adapters. Garante que o
 * CheckoutController não dependa do formato específico de cada gateway.
 *
 * Status normalizados (vocabulário interno do SportMoto):
 *   - aprovado          → cobrança capturada / pix recebido / boleto pago
 *   - pendente          → criada, aguardando ação do cliente (pix em aberto, boleto não pago)
 *   - pre_autorizado    → cartão autorizado mas não capturado ainda
 *   - recusado          → cartão negado pelo emissor / antifraude reprovou
 *   - cancelado         → cobrança cancelada antes de captura
 *   - estornado         → refund efetuado
 *   - chargeback        → contestação confirmada
 *   - erro              → falha técnica (rede, validação, etc.)
 */
class PaymentGatewayResult
{
    /** Indica se a operação foi tecnicamente bem-sucedida (false em erro de rede/validação) */
    public bool $ok = false;

    /** Status normalizado (ver lista acima). 'erro' quando ok=false. */
    public string $status = 'erro';

    /** ID da charge no gateway (Malga charge_id, por exemplo). null em erros de rede. */
    public ?string $chargeId = null;

    /** Nome do gateway (malga, pagarme, fake). Usado pra log/auditoria. */
    public string $gatewayCodigo = '';

    /** Provedor real que processou (pagarme, cielo, etc.) quando aplicável. */
    public ?string $provedorReal = null;

    // ── PIX ────────────────────────────────────────────────
    public ?string $pixQrCode = null;          // imagem (URL ou base64)
    public ?string $pixCopiaCola = null;       // string EMV pra pagar
    public ?string $pixExpiraEm = null;        // ISO datetime (Y-m-d H:i:s)

    // ── Boleto ─────────────────────────────────────────────
    public ?string $boletoUrl = null;          // URL do PDF
    public ?string $boletoLinhaDigitavel = null;
    public ?string $boletoCodigoBarras = null;
    public ?string $boletoVencimento = null;   // Y-m-d

    // ── Cartão ─────────────────────────────────────────────
    public ?string $cartaoBandeira = null;
    public ?string $cartaoUltimos4 = null;

    // ── Erro ───────────────────────────────────────────────
    public ?string $errorCode = null;          // ex: invalid_cvv, insufficient_funds
    public ?string $errorMessage = null;       // mensagem amigável

    /** Resposta crua do gateway pra debug/auditoria (vai em raw_response). */
    public array $raw = [];

    /** Factory pra erro técnico (timeout, exception, etc.) */
    public static function erro(string $mensagem, string $gateway = '', array $raw = []): self
    {
        $r = new self();
        $r->ok = false;
        $r->status = 'erro';
        $r->errorMessage = $mensagem;
        $r->gatewayCodigo = $gateway;
        $r->raw = $raw;
        return $r;
    }

    /** Converte pra array (útil pra JSON/log) */
    public function toArray(): array
    {
        return [
            'ok'              => $this->ok,
            'status'          => $this->status,
            'charge_id'       => $this->chargeId,
            'gateway'         => $this->gatewayCodigo,
            'provedor_real'   => $this->provedorReal,
            'pix_qr_code'     => $this->pixQrCode,
            'pix_copia_cola'  => $this->pixCopiaCola,
            'pix_expira_em'   => $this->pixExpiraEm,
            'boleto_url'      => $this->boletoUrl,
            'boleto_linha_digitavel' => $this->boletoLinhaDigitavel,
            'boleto_codigo_barras'   => $this->boletoCodigoBarras,
            'boleto_vencimento'      => $this->boletoVencimento,
            'cartao_bandeira'   => $this->cartaoBandeira,
            'cartao_ultimos_4'  => $this->cartaoUltimos4,
            'error_code'        => $this->errorCode,
            'error_message'     => $this->errorMessage,
        ];
    }
}
