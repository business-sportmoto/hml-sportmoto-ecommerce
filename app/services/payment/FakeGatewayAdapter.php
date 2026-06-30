<?php
declare(strict_types=1);

/**
 * FakeGatewayAdapter
 *
 * Substitui o antigo CHECKOUT_FAKE_MODE. Ativado quando:
 *   defined('PAYMENT_GATEWAY') && PAYMENT_GATEWAY === 'fake'
 *   OU defined('CHECKOUT_FAKE_MODE') && CHECKOUT_FAKE_MODE === true
 *
 * Comportamento configurável via constantes pra simular cenários:
 *   PAYMENT_FAKE_RESULT = 'aprovado'  (padrão) | 'recusado' | 'pendente'
 *   PAYMENT_FAKE_LATENCY_MS = 0 (default) — adiciona delay artificial
 *
 * Útil pra testes locais sem precisar de credenciais do gateway.
 */
class FakeGatewayAdapter implements PaymentGatewayInterface
{
    public function getCodigo(): string
    {
        return 'fake';
    }

    public function cobrar(array $dados): PaymentGatewayResult
    {
        $this->simularLatencia();

        $resultadoSimulado = defined('PAYMENT_FAKE_RESULT')
            ? PAYMENT_FAKE_RESULT
            : 'aprovado';

        $r = new PaymentGatewayResult();
        $r->gatewayCodigo = 'fake';
        $r->provedorReal  = 'fake_provider';
        $r->chargeId      = 'FAKE-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 12));

        if ($resultadoSimulado === 'recusado') {
            $r->ok = true;
            $r->status = 'recusado';
            $r->errorCode = 'fake_decline';
            $r->errorMessage = 'Cartão recusado (simulação).';
            $r->raw = ['fake' => true, 'simulated' => 'declined'];
            return $r;
        }

        $r->ok = true;
        $r->status = $resultadoSimulado;

        $metodo = $dados['metodo'] ?? 'cartao';
        switch ($metodo) {
            case 'pix':
                $r->pixCopiaCola = '00020126FAKE-COPIA-COLA-PIX' . $r->chargeId;
                $r->pixQrCode    = 'data:image/svg+xml;base64,' . base64_encode(
                    '<svg xmlns="http://www.w3.org/2000/svg" width="180" height="180">'
                    . '<rect width="100%" height="100%" fill="#fff"/>'
                    . '<text x="50%" y="50%" text-anchor="middle" font-family="monospace" font-size="14">FAKE QR</text>'
                    . '</svg>'
                );
                $r->pixExpiraEm = date('Y-m-d H:i:s', time() + (int) ($dados['pix_expira_em'] ?? 3600));
                break;

            case 'boleto':
                $r->status = 'pendente'; // boleto sempre fica pendente até o pagamento
                $r->boletoUrl = 'https://fake.local/boleto/' . $r->chargeId . '.pdf';
                $r->boletoLinhaDigitavel = '23793.38128 60082.345678 90123.456789 1 99990000000100';
                $r->boletoCodigoBarras   = '23791999900000001003812860082345678901234567891';
                $r->boletoVencimento     = $dados['vencimento'] ?? date('Y-m-d', strtotime('+3 days'));
                break;

            case 'cartao':
            default:
                // Cartão aprovado direto na simulação
                $r->cartaoBandeira  = $dados['cartao_bandeira']  ?? null;
                $r->cartaoUltimos4  = $dados['cartao_ultimos_4'] ?? null;
                break;
        }

        $r->raw = ['fake' => true, 'metodo' => $metodo, 'order_id' => $dados['order_id_loja'] ?? null];
        return $r;
    }

    public function consultar(string $chargeId): PaymentGatewayResult
    {
        $this->simularLatencia();
        $r = new PaymentGatewayResult();
        $r->ok = true;
        $r->status = 'aprovado';
        $r->chargeId = $chargeId;
        $r->gatewayCodigo = 'fake';
        $r->raw = ['fake' => true, 'consulta' => $chargeId];
        return $r;
    }

    public function estornar(string $chargeId, ?int $valorCentavos = null): PaymentGatewayResult
    {
        $this->simularLatencia();
        $r = new PaymentGatewayResult();
        $r->ok = true;
        $r->status = 'estornado';
        $r->chargeId = $chargeId;
        $r->gatewayCodigo = 'fake';
        $r->raw = ['fake' => true, 'estornado' => $valorCentavos];
        return $r;
    }

    public function tokenizarCartao(array $dadosCartao): array
    {
        $numero = preg_replace('/\D/', '', (string) ($dadosCartao['numero'] ?? ''));
        if (strlen($numero) < 13) {
            throw new InvalidArgumentException('Número de cartão inválido (fake)');
        }
        return [
            'token'     => 'tok_fake_' . bin2hex(random_bytes(8)),
            'bandeira'  => $this->detectarBandeira($numero),
            'ultimos_4' => substr($numero, -4),
        ];
    }

    private function simularLatencia(): void
    {
        $ms = defined('PAYMENT_FAKE_LATENCY_MS') ? (int) PAYMENT_FAKE_LATENCY_MS : 0;
        if ($ms > 0) usleep($ms * 1000);
    }

    private function detectarBandeira(string $numero): string
    {
        $p = [
            'amex'      => '/^3[47]/',
            'diners'    => '/^(30[0-5]|36|38)/',
            'elo'       => '/^(4011|4312|4389|4514|4573|5041|5066|5067|5090|6277|6362|6363|6504|6505|6516|6550)/',
            'hipercard' => '/^(606282|3841)/',
            'mastercard'=> '/^(5[1-5]|2[2-7])/',
            'visa'      => '/^4/',
        ];
        foreach ($p as $b => $r) if (preg_match($r, $numero)) return $b;
        return 'outros';
    }
}
