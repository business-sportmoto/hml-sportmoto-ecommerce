<?php
declare(strict_types=1);

/**
 * app/services/payment/PagamentoRoteamentoResultado.php
 *
 * Desfecho de um roteamento completo — o que o checkout recebe.
 *
 * Distingue três estados que o checkout precisa tratar diferente:
 *   aprovado  → libera o pedido
 *   pendente  → mostra QR/boleto e espera o webhook
 *   recusado  → mostra a mensagem e oferece outra forma
 *
 * `caminho` guarda os nós visitados: é o que permite ao dashboard mostrar
 * POR ONDE o pagamento passou, não só onde parou.
 */
final class PagamentoRoteamentoResultado
{
    public bool   $ok     = false;
    public string $motivo = 'nao_processado';

    /** Texto para o comprador. Nunca detalhe interno da adquirente. */
    public string $mensagemCliente = 'Não foi possível processar o pagamento.';

    public ?PagamentoClassificacao $classificacao = null;
    public ?string $adquirenteUsada = null;

    public int $fluxoId     = 0;
    public int $fluxoVersao = 0;
    public int $tentativas  = 0;

    /** Nós visitados, em ordem. */
    public array $caminho = [];

    /**
     * O motor impediu um fallback que o desenho do fluxo permitia.
     * Sinaliza fluxo mal montado — a aresta existe mas nunca será usada.
     */
    public bool $bloqueouFallback = false;

    /**
     * Pedido retido para decisao humana. Distinto de ok=false: nao houve
     * recusa, houve suspensao — o checkout precisa dizer coisas diferentes.
     */
    public bool $retido = false;

    /** id da linha em pgto_tentativas da tentativa corrente. */
    public ?int $tentativaIdAtual = null;

    /**
     * Desfecho do no de antifraude, quando houve. Guarda a regra que decidiu
     * e se chegou a gastar consulta na ClearSale — e o que o operador precisa
     * ler quando o pedido aparece retido na fila.
     */
    public ?array $antifraude = null;

    /** Pedido retido para decisao humana. */
    public function emAnalise(): bool
    {
        return ($this->antifraude['porta'] ?? null) === 'analise';
    }

    public function encerrar(bool $ok, string $motivo, string $mensagemCliente): void
    {
        $this->ok              = $ok;
        $this->motivo          = $motivo;
        $this->mensagemCliente = $mensagemCliente;
    }

    public function aprovado(): bool
    {
        return $this->classificacao?->porta === PagamentoClassificacao::APROVADO;
    }

    public function pendente(): bool
    {
        return $this->classificacao?->porta === PagamentoClassificacao::PENDENTE;
    }

    /** Instrumento de pagamento para a tela (Pix/boleto). */
    public function instrumento(): array
    {
        $c = $this->classificacao;
        if (!$c) return [];

        if ($c->pixQrCode) {
            return ['tipo' => 'pix', 'qrcode' => $c->pixQrCode,
                    'qrcode_base64' => $c->pixQrCodeBase64, 'expira_em' => $c->pixExpiraEm];
        }
        if ($c->boletoLinhaDigitavel) {
            return ['tipo' => 'boleto', 'linha_digitavel' => $c->boletoLinhaDigitavel,
                    'codigo_barras' => $c->boletoCodigoBarras, 'url' => $c->boletoUrl,
                    'vencimento' => $c->boletoVencimento];
        }
        return [];
    }

    /** Resumo para log e dashboard. */
    public function resumo(): array
    {
        return [
            'ok'          => $this->ok,
            'motivo'      => $this->motivo,
            'adquirente'  => $this->adquirenteUsada,
            'tentativas'  => $this->tentativas,
            'fluxo'       => $this->fluxoId . 'v' . $this->fluxoVersao,
            'caminho'     => implode(' > ', $this->caminho),
            'porta_final' => $this->classificacao?->porta,
        ];
    }
}
