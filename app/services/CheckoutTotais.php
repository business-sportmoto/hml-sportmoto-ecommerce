<?php
declare(strict_types=1);

/**
 * app/services/CheckoutTotais.php
 *
 * A conta do checkout, num lugar só.
 *
 * Esta lógica vivia dentro de CheckoutController::process(), entre o INSERT do
 * pedido e a chamada ao gateway. Enquanto existia um só cliente (a web) isso
 * funcionava; com o app, replicá-la seria garantir que as duas divergissem no
 * primeiro cupom novo — e divergência de total é o bug que o cliente vê no
 * extrato do cartão.
 *
 * Então `process()` passou a chamar daqui, e o app usa a MESMA função para
 * montar o resumo. O teste de paridade compara os dois: se um dia der
 * diferente, é bug nesta classe, não em um dos dois caminhos.
 *
 * É pura de propósito — recebe itens e contexto, devolve números. Não lê
 * Session, não escreve no banco, não decide fluxo. Dá para testar sem servidor.
 *
 * A ORDEM DAS OPERAÇÕES importa e é a mesma de sempre:
 *   1. subtotal dos itens
 *   2. cupom (revalidado — o carrinho pode ter mudado desde que foi aplicado)
 *   3. promoções automáticas (respeitando `acumula_cupom`)
 *   4. brinde entra como item de valor real, e o desconto cobre o preço dele
 *   5. frete menos os descontos de frete
 *   6. crédito da conta abate por ÚLTIMO, sobre o total já fechado
 */
final class CheckoutTotais
{
    /**
     * @param array $ctx {
     *   cliente_id:      int
     *   usuario_id:      int|null   (o crédito é por USUÁRIO, não por cliente)
     *   itens:           array      Cart::getItensComVariacoes()
     *   itens_cupom:     array      Cart::getItensParaCupom()
     *   frete_valor:     float
     *   cupom:           array|null ['codigo'=>..,'cupom_id'=>..]
     *   credito:         float      quanto o cliente pediu para usar
     *   primeira_compra: bool|null  calculado se vier null
     *   score:           int
     * }
     */
    public static function calcular(array $ctx): array
    {
        $clienteId  = (int)($ctx['cliente_id'] ?? 0);
        $itens      = $ctx['itens'] ?? [];
        $itensCupom = $ctx['itens_cupom'] ?? [];
        $freteValor = (float)($ctx['frete_valor'] ?? 0);

        // 1. Subtotal.
        //
        // Item que não consegue ser precificado é listado à parte em vez de
        // entrar valendo zero. Somar zero é o comportamento perigoso: o pedido
        // fecha, o cliente recebe o produto e a loja não cobra nada. Quem
        // chama decide o que fazer — o app recusa a finalização.
        $subtotal   = 0.0;
        $semPreco   = [];

        foreach ($itens as $i) {
            $unitario = (float)($i['valor_unitario'] ?? 0);
            $qtd      = (int)($i['quantidade'] ?? 0);

            if ($unitario <= 0 || $qtd <= 0) {
                $semPreco[] = [
                    'item_id'    => (int)($i['item_id'] ?? 0),
                    'produto_id' => (int)($i['produto_id'] ?? 0),
                    'nome'       => (string)($i['nome'] ?? ''),
                ];
                continue;
            }

            $subtotal += $unitario * $qtd;
        }

        // 2. Cupom — SEMPRE revalidado. O valor guardado na sessão pode ter
        //    envelhecido: o cliente troca a quantidade e o desconto muda, ou o
        //    cupom deixa de valer para o carrinho novo.
        $desconto   = 0.0;
        $freteDesc  = 0.0;
        $cupomId    = null;
        $cupomErro  = null;

        $cupom = $ctx['cupom'] ?? null;
        if (!empty($cupom['codigo']) && !empty($cupom['cupom_id'])) {
            $resultado = (new CouponService())->validar(
                (string)$cupom['codigo'],
                $itensCupom,
                $subtotal,
                $freteValor,
                $clienteId,
                ['origem' => (string)($ctx['origem'] ?? 'totais')]
            );

            if (!empty($resultado['ok'])) {
                $desconto  = (float)$resultado['desconto'];
                $freteDesc = (float)$resultado['frete_desconto'];
                $cupomId   = (int)$cupom['cupom_id'];
            } else {
                // Não derruba a compra: o cupom sai e o cliente é avisado.
                $cupomErro = (string)($resultado['msg'] ?? 'Cupom inválido.');
            }
        }

        $freteAposCupom = max(0, $freteValor - $freteDesc);

        // 3. Promoções automáticas — depois do cupom, porque `acumula_cupom` é
        //    decidido olhando se existe cupom ativo.
        $promocaoService = new PromocaoService();

        $primeiraCompra = $ctx['primeira_compra'];
        if ($primeiraCompra === null) {
            $primeiraCompra = ((new Customer())->getStatusCounts($clienteId) === 0);
        }

        $resultadosPromo = $promocaoService->avaliarCarrinho(
            $itensCupom,
            $subtotal,
            $freteAposCupom,
            $clienteId,
            [
                'primeira_compra' => (bool)$primeiraCompra,
                'score'           => (int)($ctx['score'] ?? 0),
                'tem_cupom'       => $cupomId !== null,
            ]
        );

        if ($cupomId !== null) {
            $modeloPromo = new Promocao();
            $resultadosPromo = array_values(array_filter(
                $resultadosPromo,
                static function ($r) use ($modeloPromo) {
                    $promo = $modeloPromo->findById($r['promocao_id']);
                    return (bool)($promo['acumula_cupom'] ?? false);
                }
            ));
        }

        $totaisPromo    = $promocaoService->calcularTotais($resultadosPromo);
        $descontoPromo  = (float)$totaisPromo['desconto_produto'];
        $freteDescPromo = (float)$totaisPromo['desconto_frete'];
        $brindes        = $totaisPromo['brindes'];

        // 4. Brinde: entra como item de valor real (sobe o subtotal) e o
        //    desconto da promoção já cobre esse preço — líquido R$ 0 para o
        //    cliente, mas com NF-e e baixa de estoque corretas.
        $subtotalBrinde = (float)array_sum(array_map(
            static fn($b) => (float)$b['preco'] * (int)$b['quantidade'],
            $brindes
        ));

        $descontoTotal  = round($desconto + $descontoPromo, 2);
        $freteDescTotal = round($freteDesc + $freteDescPromo, 2);
        $freteFinal     = round(max(0, $freteValor - $freteDescTotal), 2);
        $subtotalPedido = round($subtotal + $subtotalBrinde, 2);

        $total = round(max(0, $subtotalPedido - $descontoTotal + $freteFinal), 2);

        // 5. Crédito da conta. Abate por último e NUNCA passa do total — nem do
        //    saldo real, que é reconferido aqui em vez de confiar no que veio
        //    da sessão.
        $creditoPedido = round(max(0, (float)($ctx['credito'] ?? 0)), 2);
        $creditoUsado  = 0.0;

        if ($creditoPedido > 0 && !empty($ctx['usuario_id'])) {
            $saldo = (new CreditoService())->getSaldoDisponivel((int)$ctx['usuario_id']);
            $creditoUsado = round(min($creditoPedido, $saldo, $total), 2);
        }

        // `total` é o valor do pedido; `a_pagar` é o que vai ao gateway. Manter
        // os dois separados preserva a identidade
        // total = subtotal - desconto + frete nos relatórios, e ainda assim
        // cobra do cartão só o que falta depois do crédito.
        $aPagar = round(max(0, $total - $creditoUsado), 2);

        return [
            'subtotal'            => round($subtotal, 2),
            'subtotal_brinde'     => round($subtotalBrinde, 2),
            'subtotal_pedido'     => $subtotalPedido,

            'desconto_cupom'      => round($desconto, 2),
            'desconto_promocao'   => round($descontoPromo, 2),
            'desconto_total'      => $descontoTotal,

            'frete_valor'         => round($freteValor, 2),
            'frete_desconto_cupom'=> round($freteDesc, 2),
            'frete_desconto_promo'=> round($freteDescPromo, 2),
            'frete_desconto_total'=> $freteDescTotal,
            'frete_final'         => $freteFinal,

            'total'               => $total,
            'credito_usado'       => $creditoUsado,
            'a_pagar'             => $aPagar,
            'coberto_por_credito' => $aPagar <= 0.0 && $total > 0,

            'cupom_id'            => $cupomId,
            'cupom_erro'          => $cupomErro,
            /** Itens que não puderam ser precificados — ver o cálculo do subtotal. */
            'itens_sem_preco'     => $semPreco,
            'resultados_promocao' => $resultadosPromo,
            'brindes'             => $brindes,
            'primeira_compra'     => (bool)$primeiraCompra,
        ];
    }
}
