<?php
// app/presenters/CartPresenter.php
// O carrinho como o app precisa dele.
//
// Cart::getTotals() já entrega itens, subtotal, frete, desconto e cupom — mas
// numa forma feita para a view da web: mistura valores brutos com strings
// formatadas (`subtotal` e `subtotal_fmt`), e ainda carrega uma chave `debug`.
// Aqui isso vira um payload limpo, com dinheiro em string decimal.
//
// O bloco `avisos` é o que a web não tem e o app precisa: estoque muda entre a
// hora em que o item foi adicionado e a hora em que a tela abre. Sem avisar, o
// usuário só descobre no checkout.

final class CartPresenter
{
    public static function montar(array $totais, PresenterContext $ctx): array
    {
        $itens = self::itens($totais['items'] ?? [], $ctx);

        return [
            'itens'  => $itens,
            'vazio'  => count($itens) === 0,
            'totais' => [
                'quantidade' => (int)($totais['total_itens'] ?? 0),
                'subtotal'   => PrecoPresenter::dec($totais['subtotal'] ?? 0),
                'desconto'   => PrecoPresenter::dec($totais['desconto'] ?? 0),
                'frete'      => PrecoPresenter::dec($totais['frete'] ?? 0),
                'total'      => PrecoPresenter::dec($totais['total'] ?? 0),
                'frete_gratis' => (float)($totais['frete'] ?? 0) <= 0,
                'parcelamento' => PrecoPresenter::parcelamento((float)($totais['total'] ?? 0)),
            ],
            'cupom' => self::cupom($totais['cupom'] ?? null),
            'frete' => empty($totais['frete_cep']) ? null : [
                'cep'     => $totais['frete_cep'],
                'servico' => $totais['frete_servico'] ?? null,
                'prazo'   => $totais['frete_prazo'] ?? null,
                'valor'   => PrecoPresenter::dec($totais['frete'] ?? 0),
            ],
            'avisos' => self::avisos($itens),
        ];
    }

    /** @return array<int,array> */
    private static function itens(array $rows, PresenterContext $ctx): array
    {
        $saida = [];

        foreach ($rows as $i) {
            $quantidade = (int)$i['quantidade'];
            $estoque    = (int)($i['estoque_total'] ?? 0);

            $saida[] = [
                'id'         => (int)$i['id'],
                'produto_id' => (int)$i['produto_id'],
                'sku_id'     => isset($i['sku_id']) && $i['sku_id'] !== null ? (int)$i['sku_id'] : null,
                'sku'        => $i['sku_codigo'] ?? null,
                'nome'       => $i['nome_produto'] ?? '',
                'slug'       => $i['produto_slug'] ?? '',
                'imagem'     => $ctx->url($i['imagem_url'] ?? $i['imagem'] ?? null)
                                ?? $ctx->url('images/placeholder.jpg', 'asset'),

                'quantidade' => $quantidade,
                // O app precisa disso para limitar o stepper na própria tela,
                // em vez de descobrir o teto errando.
                'quantidade_maxima' => max(0, $estoque),

                'preco_unitario' => PrecoPresenter::dec($i['preco_unitario'] ?? 0),
                'subtotal'       => PrecoPresenter::dec($i['subtotal'] ?? 0),

                // Cor, tamanho, voltagem — já resolvidos por Cart::getItems().
                'atributos' => array_values(array_map(static fn(array $a) => [
                    'nome'    => $a['nome'],
                    'valor'   => $a['valor'],
                    'hex'     => $a['valor_hex'] ?? null,
                    'display' => $a['tipo_display'] ?? 'texto',
                ], $i['atributos'] ?? [])),

                'disponivel'    => $estoque > 0,
                'estoque_curto' => $estoque > 0 && $quantidade > $estoque,
            ];
        }

        return $saida;
    }

    private static function cupom(?array $cupom): ?array
    {
        if (!$cupom) {
            return null;
        }

        return [
            'codigo'    => $cupom['codigo'] ?? null,
            'descricao' => $cupom['descricao'] ?? null,
            'tipo'      => $cupom['tipo'] ?? null,
            'valor'     => isset($cupom['valor']) ? PrecoPresenter::dec($cupom['valor']) : null,
        ];
    }

    /**
     * Problemas que o usuário precisa resolver ANTES do checkout.
     * Devolver isso junto com o carrinho evita a surpresa no fim do fluxo.
     */
    private static function avisos(array $itens): array
    {
        $avisos = [];

        foreach ($itens as $i) {
            if (!$i['disponivel']) {
                $avisos[] = [
                    'tipo'    => 'esgotado',
                    'item_id' => $i['id'],
                    'mensagem' => $i['nome'] . ' ficou sem estoque.',
                ];
            } elseif ($i['estoque_curto']) {
                $avisos[] = [
                    'tipo'    => 'estoque_reduzido',
                    'item_id' => $i['id'],
                    'disponivel' => $i['quantidade_maxima'],
                    'mensagem' => 'Restam apenas ' . $i['quantidade_maxima'] . ' de ' . $i['nome'] . '.',
                ];
            }
        }

        return $avisos;
    }
}
