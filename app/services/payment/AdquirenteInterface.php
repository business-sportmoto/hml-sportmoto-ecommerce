<?php
declare(strict_types=1);

/**
 * app/services/payment/AdquirenteInterface.php
 *
 * Contrato que toda adquirente implementa. É o que permite ao motor de
 * roteamento trocar Safra por Cielo, Rede ou Mercado Pago sem saber a
 * diferença: o grafo aponta para um código de adquirente, a fábrica devolve
 * um objeto deste tipo, e o motor só conversa por aqui.
 *
 * Todo método devolve PagamentoClassificacao — nunca array cru da adquirente.
 * Traduzir a resposta é responsabilidade do adapter; decidir o caminho é do
 * motor.
 *
 * NENHUM método pode lançar por falha da adquirente. Rede caída, 500, WAF,
 * timeout: tudo vira classificação. Exceção só para erro de PROGRAMAÇÃO
 * (valor zero, cartão ausente), que é bug nosso e deve estourar alto.
 */
interface AdquirenteInterface
{
    /** Código em pgto_gateways.codigo (safrapay, cielo, fake...). */
    public function codigo(): string;

    /** Tem credenciais para operar? O motor pula adquirente não configurada. */
    public function configurado(): bool;

    /**
     * Autoriza (e captura, quando o modo for automático) uma cobrança de cartão.
     *
     * @param array $d order_id_loja, tentativa_ref, valor_centavos, parcelas,
     *                 session_id, ip_cliente, descricao_fatura, cliente[],
     *                 cartao[] | token_temporario, metadata[]
     */
    public function autorizarCartao(array $d): PagamentoClassificacao;

    /** Cria cobrança Pix. Sucesso = instrumento pagável, não dinheiro. */
    public function criarPix(array $d): PagamentoClassificacao;

    /** Emite boleto. Sucesso = instrumento pagável, não dinheiro. */
    public function criarBoleto(array $d): PagamentoClassificacao;

    /**
     * Consulta o estado atual de uma cobrança.
     * É o que resolve o caso `incerto`: depois de um timeout, perguntar antes
     * de tentar outra adquirente evita cobrar o cliente duas vezes.
     */
    public function consultar(string $chargeId): PagamentoClassificacao;

    /**
     * Estorna ou cancela.
     * @param int|null $valorCentavos null = total
     * @param bool $porAntifraude registra a recusa como antifraude na adquirente
     */
    public function cancelar(string $chargeId, ?int $valorCentavos = null, bool $porAntifraude = false): PagamentoClassificacao;
}
