<?php
// app/presenters/CheckoutPresenter.php
// O estado do checkout como o app precisa vê-lo.
//
// Dinheiro sai como STRING DECIMAL, igual ao resto da API: o app soma em
// centavos e formata com Intl.NumberFormat('pt-BR'). Mandar "R$ 1.234,56"
// pronto obrigaria o cliente a desformatar para conferir qualquer conta.
//
// `etapas` existe para o app não precisar deduzir, a partir de quatro campos
// nulos, o que ainda falta para poder finalizar. O servidor já sabe — é ele que
// vai recusar — então diz.

final class CheckoutPresenter
{
    /**
     * @param array      $conta     saída de CheckoutTotais::calcular()
     * @param array|null $endereco  linha de `enderecos`
     * @param array|null $frete     CheckoutState::getFrete()
     */
    public static function estado(
        array $conta,
        ?array $endereco,
        ?array $frete,
        array $extra,
        PresenterContext $ctx
    ): array {
        $temItens = (bool)($extra['tem_itens'] ?? false);

        return [
            'pode_finalizar' => $temItens && $endereco !== null && $frete !== null
                                && !empty($extra['metodo']),

            // O que falta, na ordem em que a tela pede.
            'etapas' => [
                'itens'     => ['ok' => $temItens],
                'endereco'  => ['ok' => $endereco !== null],
                'frete'     => ['ok' => $frete !== null],
                'pagamento' => ['ok' => !empty($extra['metodo'])],
            ],

            'endereco'  => $endereco ? EnderecoPresenter::um($endereco) : null,
            'frete'     => self::frete($frete),
            'pagamento' => [
                'metodo'   => $extra['metodo'] ?? null,
                'parcelas' => (int)($extra['parcelas'] ?? 1),
                'cartao_id'=> isset($extra['cartao_id']) ? (int)$extra['cartao_id'] : null,
            ],

            'cupom' => empty($extra['cupom']['codigo']) ? null : [
                'codigo'         => $extra['cupom']['codigo'],
                'desconto'       => PrecoPresenter::dec($conta['desconto_cupom']),
                'frete_desconto' => PrecoPresenter::dec($conta['frete_desconto_cupom']),
                // Preenchido quando o cupom deixou de valer para o carrinho
                // atual — a tela mostra o aviso em vez de um desconto fantasma.
                'invalido'       => $conta['cupom_erro'],
            ],

            'credito' => [
                'saldo'    => PrecoPresenter::dec($extra['credito_saldo'] ?? 0),
                'aplicado' => PrecoPresenter::dec($conta['credito_usado']),
            ],

            'totais'      => self::totais($conta),
            'observacao'  => $extra['observacao'] ?? null,
            'itens_total' => (int)($extra['itens_total'] ?? 0),
        ];
    }

    public static function totais(array $c): array
    {
        return [
            'subtotal'          => PrecoPresenter::dec($c['subtotal_pedido']),
            'desconto'          => PrecoPresenter::dec($c['desconto_total']),
            'desconto_cupom'    => PrecoPresenter::dec($c['desconto_cupom']),
            'desconto_promocao' => PrecoPresenter::dec($c['desconto_promocao']),
            'frete'             => PrecoPresenter::dec($c['frete_final']),
            'frete_cheio'       => PrecoPresenter::dec($c['frete_valor']),
            'frete_desconto'    => PrecoPresenter::dec($c['frete_desconto_total']),

            // `total` é o valor do pedido; `a_pagar` é o que vai ao gateway
            // depois do crédito. Os dois aparecem porque a tela mostra a conta
            // inteira, não só o que resta pagar.
            'total'             => PrecoPresenter::dec($c['total']),
            'credito'           => PrecoPresenter::dec($c['credito_usado']),
            'a_pagar'           => PrecoPresenter::dec($c['a_pagar']),
            'coberto_por_credito' => (bool)$c['coberto_por_credito'],

            'brindes' => array_values(array_map(static fn(array $b) => [
                'produto_id' => (int)$b['produto_id'],
                'nome'       => $b['nome'] ?? '',
                'quantidade' => (int)$b['quantidade'],
            ], $c['brindes'] ?? [])),
        ];
    }

    private static function frete(?array $f): ?array
    {
        if (!$f || ($f['codigo'] ?? '') === '') {
            return null;
        }

        return [
            'codigo'    => $f['codigo'],
            'descricao' => $f['descricao'] ?? null,
            'valor'     => PrecoPresenter::dec($f['valor'] ?? 0),
            'prazo_dias'=> (int)($f['prazo'] ?? 0),
            'carrier'   => $f['carrier'] ?? null,
        ];
    }

    /**
     * Opções vindas de FreteService::calcular(), que devolve
     * ['id','nome','prazo','valor','tipo','carrier','poster','tag',...].
     *
     * `tipo` chega junto porque retirada em loja não é entrega: a tela precisa
     * poder separar as duas coisas em vez de mostrar "frete R$ 0,00" para quem
     * na verdade vai buscar no balcão.
     */
    public static function opcoesFrete(array $opcoes): array
    {
        return array_values(array_map(static function (array $o) {
            $gratis = !empty($o['frete_gratis']);
            $valor  = (float)($o['valor'] ?? 0);

            return [
                'codigo'       => (string)($o['id'] ?? ''),
                'descricao'    => (string)($o['nome'] ?? ''),
                'prazo_dias'   => (int)($o['prazo'] ?? 0),
                'prazo_texto'  => $o['prazo_texto'] ?: null,
                'valor'        => PrecoPresenter::dec($gratis ? 0 : $valor),
                'frete_gratis' => $gratis,
                'tipo'         => (string)($o['tipo'] ?? 'entrega'),
                'carrier'      => $o['carrier'] ?: null,
                'observacao'   => $o['observacao'] ?: null,
            ];
        }, $opcoes));
    }

    /** Cartão salvo — bandeira e últimos 4, NUNCA o token do vault. */
    public static function cartao(array $c): array
    {
        return [
            'id'        => (int)$c['id'],
            'bandeira'  => $c['bandeira'] ?? null,
            'ultimos_4' => $c['ultimos_4'] ?? null,
            'titular'   => $c['nome_titular'] ?? null,
            'apelido'   => $c['apelido'] ?? null,
            'validade'  => $c['validade'] ?? null,
            'principal' => !empty($c['principal']),
        ];
    }
}
