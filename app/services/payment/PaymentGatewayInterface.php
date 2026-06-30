<?php
declare(strict_types=1);

/**
 * PaymentGatewayInterface
 *
 * Contrato que todo adapter de pagamento deve implementar. Adicionar um
 * novo gateway (Asaas, Pagar.me direto, PagSeguro, etc.) = criar uma
 * classe que implementa esta interface. Nada mais muda.
 *
 * Os parâmetros são arrays normalizados pra evitar acoplamento de
 * formato. Cada adapter traduz internamente pra o formato do gateway.
 *
 * Conventions:
 *   - Valores em centavos (int)
 *   - Datas como string ISO (Y-m-d ou Y-m-d H:i:s)
 *   - Telefones: dígitos + DDI (+5511...)
 *   - Documento: apenas dígitos
 */
interface PaymentGatewayInterface
{
    /**
     * Código identificador do gateway (malga, pagarme, fake, etc.).
     * Usado pra audit log.
     */
    public function getCodigo(): string;

    /**
     * Cria uma cobrança no gateway.
     *
     * Estrutura esperada em $dados (campos comuns):
     *   - order_id_loja  string  (idempotência, geralmente codigo do pedido)
     *   - valor_centavos int
     *   - metodo         string  ('pix'|'boleto'|'cartao')
     *   - parcelas       int     (apenas pra metodo=cartao)
     *   - token_cartao   string  (apenas pra metodo=cartao - tokenId do gateway)
     *   - descricao      string  (opcional, vai em statementDescriptor)
     *   - cliente        array   ['nome','email','telefone','documento','endereco'=>[...]]
     *   - vencimento     string  Y-m-d (apenas pra boleto)
     *   - pix_expira_em  int     segundos (apenas pra pix, default 3600)
     *
     * @throws InvalidArgumentException se $dados não tem o necessário
     */
    public function cobrar(array $dados): PaymentGatewayResult;

    /**
     * Consulta o status atual de uma charge no gateway.
     * Usado por crons de reconciliação e quando o webhook chega.
     */
    public function consultar(string $chargeId): PaymentGatewayResult;

    /**
     * Estorna (refund) uma cobrança aprovada.
     * @param int|null $valorCentavos null = estorno total
     */
    public function estornar(string $chargeId, ?int $valorCentavos = null): PaymentGatewayResult;

    /**
     * Tokeniza dados de cartão server-side.
     *
     * IMPORTANTE: Esta forma trafega dados sensíveis pelo seu servidor.
     * Para ficar PCI-compliant (escopo SAQ-A), o ideal é tokenizar
     * client-side via SDK JS do gateway. Mantemos este método pra
     * transição: enquanto o front não tem o SDK, o backend faz a ponte.
     *
     * Entrada:
     *   - numero    string (dígitos)
     *   - titular   string
     *   - validade  string (MM/AA)
     *   - cvv       string
     *
     * Retorno:
     *   - token     string (tokenId)
     *   - bandeira  string (visa, mastercard, etc.)
     *   - ultimos_4 string
     *
     * @throws RuntimeException se a tokenização falhar
     */
    public function tokenizarCartao(array $dadosCartao): array;
}
