<?php
// app/presenters/CarrinhoCompartilhadoPresenter.php
//
// A visão de um carrinho recebido por link.
//
// O que se mostra aqui é o SNAPSHOT — a foto do carrinho no momento em que o
// link foi gerado. Os preços podem ter mudado desde então, e é justamente por
// isso que a tela precisa dizer de quem veio e até quando vale: quem abre
// precisa entender que está vendo a escolha de outra pessoa, não a sua.
//
// A revalidação (preço e estoque de agora) acontece só na cópia, em
// CarrinhoCompartilhadoService::copiar().

final class CarrinhoCompartilhadoPresenter
{
    public static function montar(array $c, PresenterContext $ctx): array
    {
        $itens = is_array($c['itens'] ?? null) ? $c['itens'] : [];

        return [
            'token'      => (string)($c['token'] ?? ''),
            'de'         => (string)($c['compartilhado_por'] ?? 'Um cliente'),
            'vendedor'   => $c['vendedor_nome'] ?? null,
            'expira_em'  => self::data($c['expira_em'] ?? null),
            'criado_em'  => self::data($c['criado_em'] ?? null),
            'itens'      => self::itens($itens, $ctx),
            'quantidade' => array_sum(array_map(
                static fn(array $i) => (int)($i['quantidade'] ?? 0),
                $itens
            )),
            // Totais do snapshot, como string decimal — mesma regra do resto
            // da API: dinheiro nunca trafega como float.
            'totais' => [
                'subtotal' => PrecoPresenter::dec($c['subtotal'] ?? 0),
                'desconto' => PrecoPresenter::dec($c['desconto'] ?? 0),
                'total'    => PrecoPresenter::dec($c['total']    ?? 0),
            ],
        ];
    }

    /** @param array<int,array> $itens */
    private static function itens(array $itens, PresenterContext $ctx): array
    {
        return array_values(array_map(static function (array $i) use ($ctx): array {
            return [
                'produto_id' => (int)($i['produto_id'] ?? 0),
                'sku_id'     => isset($i['sku_id']) ? (int)$i['sku_id'] : null,
                'nome'       => (string)($i['nome'] ?? 'Produto'),
                'slug'       => (string)($i['slug'] ?? ''),
                'imagem'     => $ctx->url($i['imagem'] ?? null),
                'quantidade' => (int)($i['quantidade'] ?? 0),
                'preco'      => PrecoPresenter::dec($i['preco']    ?? 0),
                'subtotal'   => PrecoPresenter::dec($i['subtotal'] ?? 0),
                'sku'        => $i['sku'] ?? null,
                'opcoes'     => self::opcoes($i['opcoes'] ?? null),
            ];
        }, $itens));
    }

    /**
     * "Tamanho M", "Cor Preto" — o suficiente para reconhecer a variação sem
     * o app precisar consultar o produto.
     *
     * @return array<int,array{nome:string,valor:string}>
     */
    private static function opcoes($opcoes): array
    {
        if (!is_array($opcoes)) {
            return [];
        }

        $out = [];
        foreach ($opcoes as $o) {
            if (!is_array($o)) continue;

            $nome  = trim((string)($o['nome']  ?? ''));
            $valor = trim((string)($o['valor'] ?? ''));

            if ($nome === '' || $valor === '') continue;

            $out[] = ['nome' => $nome, 'valor' => $valor];
        }
        return $out;
    }

    private static function data(?string $valor): ?string
    {
        if (!$valor) return null;
        $ts = strtotime($valor);
        return $ts ? date(DATE_ATOM, $ts) : null;
    }
}
