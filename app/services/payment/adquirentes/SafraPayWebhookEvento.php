<?php
declare(strict_types=1);

/**
 * app/services/payment/adquirentes/SafraPayWebhookEvento.php
 *
 * Normaliza o WebhookCall da Safra Pay.
 *
 * DUAS ARMADILHAS DO FORMATO, ambas verificadas na documentação:
 *
 *  1. O webhook usa PascalCase (ChargeId, Transactions), enquanto a API REST
 *     usa camelCase (chargeId, transactions). São o MESMO domínio com grafias
 *     diferentes — reaproveitar o parser da API aqui devolve tudo nulo.
 *
 *  2. Os status vêm como INTEIRO no webhook e como STRING na API.
 *     "TransactionStatus": 2 no webhook = "Captured" na resposta REST.
 *     Comparar com a string direto nunca casa.
 *
 * Enumeradores (doc: /primeiros-passos#enumeradores).
 */
class SafraPayWebhookEvento
{
    /** EventType — 1 Created, 2 Updated */
    public const EVENTO_CRIADO      = 1;
    public const EVENTO_ATUALIZADO  = 2;

    /** TransactionStatus (inteiro no webhook) → rótulo da API REST. */
    private const TRANSACTION_STATUS = [
        1  => 'PreAuthorized',
        2  => 'Captured',
        3  => 'Denied',
        4  => 'Pending',
        5  => 'Canceled',
        6  => 'PendingCancel',
        7  => 'PendingPayment',
        8  => 'Paid',            // boleto pago
        9  => 'ErrorCreation',   // erro ao criar boleto
        10 => 'Expired',         // boleto expirado
    ];

    /** ChargeStatus (inteiro) → rótulo. */
    private const CHARGE_STATUS = [
        1 => 'Authorized',
        2 => 'PreAuthorized',
        4 => 'Canceled',
        5 => 'Partial',
        6 => 'NotAuthorized',
        7 => 'PendingCancel',
    ];

    /** PaymentType (inteiro) → método interno. */
    private const PAYMENT_TYPE = [
        1 => 'cartao_debito',
        2 => 'cartao_credito',
        3 => 'voucher',
        4 => 'boleto',
        8 => 'pix',
    ];

    public ?string $chargeId         = null;
    public ?string $merchantChargeId = null;   // nosso order_id_loja
    public ?string $merchantId       = null;
    public ?string $nsu              = null;
    public int     $eventType        = 0;
    public ?string $dataHora         = null;

    public ?string $chargeStatus     = null;   // já em rótulo
    public ?string $transactionStatus= null;   // já em rótulo
    public ?string $metodo           = null;
    public bool    $aprovado         = false;
    public bool    $capturada        = false;
    public bool    $cancelada        = false;
    public int     $valorCentavos    = 0;
    public int     $parcelas         = 1;
    public ?string $transactionId    = null;
    public ?string $merchantOrderId  = null;   // tentativa_ref
    public ?string $mensagemErro     = null;
    public ?string $bandeira         = null;
    public ?string $cartaoMascarado  = null;

    // Pix
    public ?string $pixEndToEnd = null;
    public ?string $pixNsu      = null;

    public array $bruto = [];

    public static function daPayload(array $p): self
    {
        $e = new self();
        $e->bruto = $p;

        $e->chargeId         = self::str($p, 'ChargeId');
        $e->merchantChargeId = self::str($p, 'MerchantChargeId');
        $e->merchantId       = self::str($p, 'MerchantId');
        $e->nsu              = self::str($p, 'Nsu');
        $e->dataHora         = self::str($p, 'DateTime');
        $e->eventType        = (int) ($p['EventType'] ?? $p['eventType'] ?? 0);

        $cs = $p['ChargeStatus'] ?? null;
        $e->chargeStatus = is_numeric($cs)
            ? (self::CHARGE_STATUS[(int) $cs] ?? ('desconhecido:' . $cs))
            : (is_string($cs) ? $cs : null);

        $tx = $p['Transactions'][0] ?? ($p['transactions'][0] ?? []);
        if (!is_array($tx)) $tx = [];

        $ts = $tx['TransactionStatus'] ?? null;
        $e->transactionStatus = is_numeric($ts)
            ? (self::TRANSACTION_STATUS[(int) $ts] ?? ('desconhecido:' . $ts))
            : (is_string($ts) ? $ts : null);

        $pt = $tx['PaymentType'] ?? null;
        $e->metodo = is_numeric($pt)
            ? (self::PAYMENT_TYPE[(int) $pt] ?? null)
            : (is_string($pt) ? strtolower($pt) : null);

        $e->aprovado        = !empty($tx['IsApproved']);
        $e->capturada       = !empty($tx['IsCapture']);
        $e->cancelada       = !empty($tx['IsCanceled']);
        $e->valorCentavos   = (int) ($tx['Amount'] ?? 0);
        $e->parcelas        = max(1, (int) ($tx['InstallmentNumber'] ?? 1));
        $e->transactionId   = self::str($tx, 'TransactionId');
        $e->merchantOrderId = self::str($tx, 'MerchantOrderId');
        $e->mensagemErro    = self::str($tx, 'ErrorMessage');
        $e->pixEndToEnd     = self::str($tx, 'EndToEnd');
        $e->pixNsu          = self::str($tx, 'PixNsu');

        if (!empty($tx['Card']) && is_array($tx['Card'])) {
            $e->bandeira        = self::str($tx['Card'], 'Brand');
            $e->cartaoMascarado = self::str($tx['Card'], 'Number');
        }

        return $e;
    }

    /**
     * Chave de idempotência.
     *
     * A Safra não manda um id de evento próprio, então ele é derivado do que
     * identifica o evento de forma estável: cobrança + tipo + status + valor.
     * Uma reentrega do MESMO evento gera a mesma chave e é barrada pelo UNIQUE
     * de pgto_webhook_log; uma mudança real de status gera outra e passa.
     *
     * DateTime fica de fora de propósito: se a Safra reenviar com timestamp
     * novo, a reentrega escaparia da deduplicação.
     */
    public function eventId(): string
    {
        return 'safrapay:' . hash('sha256', implode('|', [
            $this->chargeId ?? '',
            $this->eventType,
            $this->transactionStatus ?? '',
            $this->chargeStatus ?? '',
            $this->valorCentavos,
            $this->transactionId ?? '',
        ]));
    }

    /** Rótulo curto para pgto_webhook_log.tipo. */
    public function tipo(): string
    {
        $ev = $this->eventType === self::EVENTO_CRIADO ? 'created'
            : ($this->eventType === self::EVENTO_ATUALIZADO ? 'updated' : 'evento');
        return $ev . '.' . strtolower((string) ($this->transactionStatus ?? 'desconhecido'));
    }

    /** Pagamento confirmado — Pix pago, boleto pago, cartão capturado. */
    public function pago(): bool
    {
        return in_array($this->transactionStatus, ['Captured', 'Paid'], true)
            && $this->aprovado
            && !$this->cancelada;
    }

    public function negada(): bool
    {
        return in_array($this->transactionStatus, ['Denied', 'ErrorCreation'], true);
    }

    public function foiCancelada(): bool
    {
        return $this->cancelada || $this->transactionStatus === 'Canceled';
    }

    /** Boleto/Pix vencido sem pagamento. */
    public function expirada(): bool
    {
        return $this->transactionStatus === 'Expired';
    }

    private static function str(array $a, string $k): ?string
    {
        // Aceita PascalCase e camelCase: a doc é PascalCase, mas manter as
        // duas evita quebra se a Safra mudar a serialização.
        foreach ([$k, lcfirst($k)] as $chave) {
            if (isset($a[$chave]) && is_scalar($a[$chave]) && (string) $a[$chave] !== '') {
                return (string) $a[$chave];
            }
        }
        return null;
    }
}
