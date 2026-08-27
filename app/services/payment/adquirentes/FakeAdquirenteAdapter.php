<?php
declare(strict_types=1);

/**
 * app/services/payment/adquirentes/FakeAdquirenteAdapter.php
 *
 * Adquirente de mentira, com roteiro. Existe para exercitar o motor de
 * roteamento — fallback, retentativa, incerteza — sem depender de rede,
 * de horário de simulador ou de liberação de IP em WAF.
 *
 * NÃO é o FakeGatewayAdapter antigo (aquele implementa a interface da era
 * Malga). Este fala o vocabulário novo: devolve PagamentoClassificacao.
 *
 * USO:
 *   // sempre aprova
 *   new FakeAdquirenteAdapter('acq_a');
 *
 *   // roteiro: cai na 1ª, aprova na 2ª
 *   new FakeAdquirenteAdapter('acq_a', [PagamentoClassificacao::INDISPONIVEL]);
 *   new FakeAdquirenteAdapter('acq_b', [PagamentoClassificacao::APROVADO]);
 *
 * O roteiro é consumido em ordem; esgotado, repete o último.
 */
class FakeAdquirenteAdapter implements AdquirenteInterface
{
    private string $codigo;
    /** @var string[] portas a devolver, em ordem */
    private array $roteiro;
    private int $passo = 0;
    private bool $configurado;

    /** Chamadas recebidas — o teste inspeciona para conferir a ordem. */
    public array $chamadas = [];

    public function __construct(string $codigo = 'fake', array $roteiro = [], bool $configurado = true)
    {
        $this->codigo      = $codigo;
        $this->roteiro     = $roteiro ?: [PagamentoClassificacao::APROVADO];
        $this->configurado = $configurado;
    }

    public function codigo(): string { return $this->codigo; }
    public function configurado(): bool { return $this->configurado; }

    public function autorizarCartao(array $d): PagamentoClassificacao
    {
        $this->chamadas[] = ['metodo' => 'cartao', 'ref' => $d['tentativa_ref'] ?? null,
                             'parcelas' => $d['parcelas'] ?? 1, 'valor' => $d['valor_centavos'] ?? 0];
        return $this->proxima($d);
    }

    public function criarPix(array $d): PagamentoClassificacao
    {
        $this->chamadas[] = ['metodo' => 'pix', 'ref' => $d['tentativa_ref'] ?? null,
                             'valor' => $d['valor_centavos'] ?? 0];
        $c = $this->proxima($d);
        if ($c->porta === PagamentoClassificacao::PENDENTE || $c->porta === PagamentoClassificacao::APROVADO) {
            $c->porta      = PagamentoClassificacao::PENDENTE;
            $c->classeErro = 'aguardando_pagamento';
            $c->pixQrCode  = '00020101021226' . strtoupper(bin2hex(random_bytes(8)));
        }
        return $c;
    }

    public function criarBoleto(array $d): PagamentoClassificacao
    {
        $this->chamadas[] = ['metodo' => 'boleto', 'ref' => $d['tentativa_ref'] ?? null,
                             'valor' => $d['valor_centavos'] ?? 0];
        $c = $this->proxima($d);
        if ($c->porta === PagamentoClassificacao::PENDENTE || $c->porta === PagamentoClassificacao::APROVADO) {
            $c->porta                = PagamentoClassificacao::PENDENTE;
            $c->classeErro           = 'aguardando_pagamento';
            $c->boletoLinhaDigitavel = '03399' . random_int(10000000, 99999999) . '00000000000';
        }
        return $c;
    }

    public function consultar(string $chargeId): PagamentoClassificacao
    {
        $this->chamadas[] = ['metodo' => 'consultar', 'charge' => $chargeId];
        return $this->proxima([]);
    }

    public function cancelar(string $chargeId, ?int $valorCentavos = null, bool $porAntifraude = false): PagamentoClassificacao
    {
        $this->chamadas[] = ['metodo' => 'cancelar', 'charge' => $chargeId,
                             'valor' => $valorCentavos, 'antifraude' => $porAntifraude];
        $c = new PagamentoClassificacao();
        $c->porta      = PagamentoClassificacao::APROVADO;
        $c->classeErro = 'cancelado';
        return $c;
    }

    private function proxima(array $d): PagamentoClassificacao
    {
        $porta = $this->roteiro[min($this->passo, count($this->roteiro) - 1)];
        $this->passo++;

        $c = new PagamentoClassificacao();
        $c->porta      = $porta;
        $c->httpStatus = 200;
        $c->duracaoMs  = random_int(80, 400);
        $c->chargeId   = $this->codigo . '-' . bin2hex(random_bytes(6));
        $c->bandeira   = $d['cartao']['bandeira'] ?? null;

        // Espelha o que os adapters reais preenchem, para o motor e o
        // dashboard verem a mesma forma de dado em teste e em produção.
        [$c->classeErro, $c->podeCairParaOutra, $c->exigeConsulta, $c->mensagemCliente] = match ($porta) {
            PagamentoClassificacao::APROVADO =>
                ['aprovado', false, false, 'Pagamento aprovado.'],
            PagamentoClassificacao::PENDENTE =>
                ['aguardando_pagamento', false, false, 'Aguardando pagamento.'],
            PagamentoClassificacao::NEGADO_SALDO =>
                ['saldo_insuficiente', false, false, 'Saldo ou limite insuficiente no cartão.'],
            PagamentoClassificacao::NEGADO_ANTIFRAUDE =>
                ['antifraude', false, false, 'Pagamento não autorizado. Contate o banco emissor.'],
            PagamentoClassificacao::NEGADO_DADOS =>
                ['cartao_invalido', false, false, 'Verifique os dados do cartão.'],
            PagamentoClassificacao::NEGADO_GENERICO =>
                ['generico', false, false, 'Pagamento não autorizado.'],
            PagamentoClassificacao::INDISPONIVEL =>
                ['indisponivel', true, false, 'Instabilidade no pagamento. Tentando outra opção...'],
            PagamentoClassificacao::INCERTO =>
                ['timeout', false, true, 'Confirmando seu pagamento...'],
            default =>
                ['tecnico', true, false, 'Não foi possível processar o pagamento.'],
        };

        if ($porta === PagamentoClassificacao::NEGADO_SALDO)  $c->codigoAdquirente = '51';
        if ($porta === PagamentoClassificacao::NEGADO_DADOS)  $c->codigoAdquirente = '06';
        if ($porta === PagamentoClassificacao::APROVADO)      $c->codigoAdquirente = '00';

        return $c;
    }
}
