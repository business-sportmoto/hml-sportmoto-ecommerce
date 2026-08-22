<?php
// app/presenters/ProductDetailPresenter.php
// A página de produto inteira em UMA resposta.
//
// A PDP da web (views/products/detail.php, 47 KB) monta galeria, variações,
// matriz de estoque, características, avaliações e relacionados. No app, fazer
// isso com seis chamadas HTTP daria seis spinners e uma tela que monta aos
// pedaços. Aqui é uma chamada só, ~10 queries.
//
// A matriz de SKUs vai completa no payload. É o que permite trocar de variação
// (tamanho, cor) e ver preço e estoque atualizarem SEM nova requisição — o
// mesmo truque que a web faz com window.PV.

final class ProductDetailPresenter
{
    private const ESTOQUE_BAIXO = 5;

    public static function montar(array $produto, PresenterContext $ctx): array
    {
        $id = (int)$produto['id'];

        $modelo    = new Product();
        $variacoes = (new ProductVariation())->getProductData($id);

        $imagens       = $modelo->getImages($id);
        $caracteristic = $modelo->getCaracteristicas($id);
        $stats         = $modelo->getReviewStats($id);
        $relacionados  = $modelo->getRelated($id, (int)($produto['categoria_id'] ?? 0), 8);

        $estoqueTotal = (int)($produto['estoque_total'] ?? 0);
        $temBuscaMoto = ProdutoCompatRepo::temBuscaMotoEmLote([$id])[$id] ?? false;

        return [
            'id'    => $id,
            'slug'  => (string)$produto['slug'],
            'nome'  => (string)$produto['nome'],
            'sku'   => $produto['sku_legado'] ?? null,

            'marca' => empty($produto['marca_id']) ? null : [
                'id'   => (int)$produto['marca_id'],
                'nome' => $produto['marca_nome'] ?? null,
                'slug' => $produto['marca_slug'] ?? null,
            ],
            'categoria' => empty($produto['categoria_id']) ? null : [
                'id'   => (int)$produto['categoria_id'],
                'nome' => $produto['categoria_nome'] ?? null,
                'slug' => $produto['categoria_slug'] ?? null,
            ],

            'descricao'       => $produto['descricao'] ?? null,
            'descricao_curta' => $produto['descricao_curta'] ?? null,

            'imagens' => self::imagens($imagens, $produto, $ctx),
            'preco'   => PrecoPresenter::bloco($produto),

            'estoque' => [
                'disponivel'   => $estoqueTotal > 0,
                'total'        => $estoqueTotal,
                'alerta_baixo' => $estoqueTotal > 0 && $estoqueTotal <= self::ESTOQUE_BAIXO,
            ],

            'variacoes' => self::variacoes($variacoes),

            'caracteristicas' => array_values(array_map(static fn(array $c) => [
                'nome'    => $c['nome'],
                'slug'    => $c['slug'],
                'tipo'    => $c['tipo'],
                'valor'   => $c['valor'],
                'unidade' => $c['unidade'] ?? null,
            ], $caracteristic)),

            'avaliacao' => [
                'media'        => isset($stats['media']) ? (float)$stats['media'] : 0.0,
                'total'        => (int)($stats['total'] ?? 0),
                'distribuicao' => [
                    5 => (int)($stats['cinco']  ?? 0),
                    4 => (int)($stats['quatro'] ?? 0),
                    3 => (int)($stats['tres']   ?? 0),
                    2 => (int)($stats['dois']   ?? 0),
                    1 => (int)($stats['um']     ?? 0),
                ],
            ],

            'favoritado' => !empty($produto['favoritado']),

            'compatibilidade' => [
                'aplicavel'  => $temBuscaMoto,
                'compativel' => $temBuscaMoto && $ctx->temVeiculo()
                    ? (ProdutoCompatRepo::compativeisComVeiculo([$id], $ctx->veiculoAtivo)[$id] ?? false)
                    : null,
                'moto' => $ctx->veiculoAtivo['label'] ?? null,
            ],

            'relacionados' => ProductCardPresenter::colecao($relacionados, $ctx),

            'compartilhar' => [
                'url'   => $ctx->baseUrl . '/produto/' . $produto['slug'],
                'texto' => $produto['nome'],
            ],
        ];
    }

    /**
     * Galeria. Marca a principal e devolve URLs absolutas — o app não deve
     * precisar saber de onde a mídia vem.
     */
    private static function imagens(array $rows, array $produto, PresenterContext $ctx): array
    {
        $out = [];
        foreach ($rows as $img) {
            $url = $ctx->url($img['arquivo'] ?? null);
            if (!$url) {
                continue;
            }
            $out[] = [
                'url'       => $url,
                'principal' => !empty($img['principal']),
                'sku_id'    => isset($img['sku_id']) ? (int)$img['sku_id'] : null,
                'alt'       => $produto['nome'],
            ];
        }

        if (!$out) {
            $out[] = [
                'url'       => $ctx->url('images/placeholder.jpg', 'asset'),
                'principal' => true,
                'sku_id'    => null,
                'alt'       => $produto['nome'],
            ];
        }

        return $out;
    }

    /**
     * Traduz a saída de ProductVariation::getProductData().
     *
     * Dois eixos distintos, e o app precisa dos dois:
     *   agrupadores → navegam entre PRODUTOS da mesma família (outra cor é
     *                 outro produto, com outro slug)
     *   tipos       → selecionam o SKU DENTRO deste produto (tamanho, voltagem)
     *
     * `matriz` é chaveada pela combinação de valores na ordem de `tipos_slug`,
     * separada por "|" — ex.: "58|110V". O app monta a mesma chave a partir da
     * seleção do usuário e resolve preço e estoque localmente.
     */
    private static function variacoes(array $v): array
    {
        if (!$v) {
            return [
                'tem_variacao' => false,
                'tipos'        => [],
                'matriz'       => [],
                'agrupadores'  => [],
                'familia'      => [],
            ];
        }

        $matriz = [];
        foreach ($v['matriz_skus'] ?? [] as $chave => $sku) {
            $matriz[$chave] = [
                'sku_id'      => (int)$sku['sku_id'],
                'sku'         => $sku['sku'],
                'preco'       => PrecoPresenter::dec($sku['preco']),
                'estoque'     => (int)$sku['estoque'],
                'disponivel'  => !$sku['sem_estoque'],
            ];
        }

        return [
            'tem_variacao' => !empty($v['tipos_variacao']),
            'ordem_chave'  => $v['tipos_slug'] ?? [],

            'tipos' => array_values(array_map(static fn(array $t) => [
                'id'      => (int)$t['id'],
                'nome'    => $t['nome'],
                'slug'    => $t['slug'],
                'display' => $t['tipo_display'] ?? 'texto',
                'unidade' => $t['unidade'] ?? null,
                'valores' => array_values(array_map(static fn(array $val) => [
                    'valor'       => $val['valor'],
                    'disponivel'  => (bool)$val['tem_estoque'],
                ], $t['valores'] ?? [])),
            ], $v['tipos_variacao'] ?? [])),

            'matriz' => (object)$matriz,

            'agrupadores' => array_values(array_map(static fn(array $a) => [
                'nome'    => $a['nome'],
                'slug'    => $a['slug'],
                'display' => $a['tipo_display'] ?? 'texto',
                'valor'   => $a['valor'],
                'hex'     => $a['valor_hex'] ?? null,
            ], $v['atributos_agrupadores'] ?? [])),

            'familia' => array_values(array_map(static fn(array $f) => [
                'id'          => (int)$f['id'],
                'nome'        => $f['nome'],
                'slug'        => $f['slug'],
                'atual'       => !empty($f['atual']),
                'disponivel'  => empty($f['sem_estoque']),
                'imagem'      => $f['imagem_principal'] ?? null,
                'agrupadores' => $f['agrupadores'] ?? [],
                'hex'         => $f['agrupadores_hex'] ?? [],
            ], $v['produtos_familia'] ?? [])),

            'faixa_preco' => empty($v['tem_range_preco']) ? null : [
                'min' => PrecoPresenter::dec($v['preco_min']),
                'max' => PrecoPresenter::dec($v['preco_max']),
            ],
        ];
    }
}
