<?php
// app/presenters/ProductCardPresenter.php
// O card de produto da API — o objeto mais repetido de toda a resposta.
//
// ── Por que este presenter existe ───────────────────────────────────────────
// views/partials/product-card.php faz TRÊS queries por produto:
//   :108  Product::temBuscaMoto($id)
//   :116  (new VeiculoService())->isProdutoCompativel($id)
//   :136  (new Review())->getResumo($id)
// Com 18 produtos por seção e 8 seções, a home dispara ~430 queries só de card.
//
// Aqui a coleção inteira custa 3 queries, e duas delas usam métodos em lote que
// JÁ EXISTEM e não eram aproveitados:
//   Review::getResumoEmLote()  (app/models/Review.php:33)
//   Clip::produtosComClip()    (app/models/Clip.php:35)
// A terceira é ProdutoCompatRepo, escrito para isto.
//
// REGRA: nunca montar um card fora daqui. Um único endpoint que serialize
// produtos à mão traz o N+1 de volta sem ninguém perceber.

final class ProductCardPresenter
{
    /** Abaixo disto o card mostra "últimas unidades". */
    private const ESTOQUE_BAIXO = 5;

    /**
     * Serializa uma coleção de produtos crus de Product::getList()/getCatalog().
     *
     * @param  array<int,array> $rows
     * @return array<int,array>
     */
    public static function colecao(array $rows, PresenterContext $ctx): array
    {
        if (!$rows) {
            return [];
        }

        $lotes = self::lotes($rows, $ctx);

        return array_values(array_map(
            static fn(array $r) => self::comLotes($r, $ctx, $lotes),
            $rows
        ));
    }

    /** Um produto só. Prefira colecao() sempre que houver mais de um. */
    public static function um(array $row, PresenterContext $ctx): array
    {
        return self::comLotes($row, $ctx, self::lotes([$row], $ctx));
    }

    /**
     * Carrega, de uma vez, tudo que o card precisa e que não vem na linha.
     * Público porque HomeSectionPresenter precisa montar UM lote cruzando
     * todas as seções — seções repetem produtos, e refazer os batches por
     * seção desperdiçaria a maior parte do ganho.
     *
     * @param array<int,array> $rows linhas de QUALQUER conjunto de seções
     */
    public static function lotes(array $rows, PresenterContext $ctx): array
    {
        $ids = array_values(array_unique(array_map(
            static fn(array $r) => (int)$r['id'],
            $rows
        )));

        if (!$ids) {
            return ['reviews' => [], 'clips' => [], 'busca_moto' => [], 'compat' => []];
        }

        // 1 query
        $reviews = (new Review())->getResumoEmLote($ids);

        // 1 query
        $clips = array_fill_keys(Clip::produtosComClip($ids), true);

        // 1 query
        $buscaMoto = ProdutoCompatRepo::temBuscaMotoEmLote($ids);

        // 1 query, e só quando há moto ativa — sem veículo o selo nem aparece.
        $compat = $ctx->temVeiculo()
            ? ProdutoCompatRepo::compativeisComVeiculo($ids, $ctx->veiculoAtivo)
            : [];

        return [
            'reviews'    => $reviews,
            'clips'      => $clips,
            'busca_moto' => $buscaMoto,
            'compat'     => $compat,
        ];
    }

    /**
     * Serializa um produto com lotes JÁ carregados — nenhuma query aqui.
     * É por onde HomeSectionPresenter monta as 12 seções com um lote só.
     */
    public static function comLotes(array $p, PresenterContext $ctx, array $lotes): array
    {
        $id = (int)$p['id'];

        $estoqueTotal = (int)($p['estoque_total'] ?? 0);
        $disponivel   = $estoqueTotal > 0;

        $preco = PrecoPresenter::bloco($p);

        $avaliacao = $lotes['reviews'][$id] ?? null;

        return [
            'id'    => $id,
            'slug'  => (string)$p['slug'],
            'nome'  => (string)$p['nome'],
            'sku'   => $p['sku_legado'] ?? null,

            'marca' => empty($p['marca_id']) ? null : [
                'id'   => (int)$p['marca_id'],
                'nome' => $p['marca_nome'] ?? null,
                'slug' => $p['marca_slug'] ?? null,
            ],
            'categoria' => empty($p['categoria_id']) ? null : [
                'id'   => (int)$p['categoria_id'],
                'nome' => $p['categoria_nome'] ?? null,
                'slug' => $p['categoria_slug'] ?? null,
            ],

            // As imagens já vêm como URL absoluta de CDN; url() só normaliza
            // e cobre o caso de caminho relativo legado.
            'imagem' => [
                'url' => $ctx->url($p['imagem_principal'] ?? null)
                         ?? $ctx->url('images/placeholder.jpg', 'asset'),
                'alt' => (string)$p['nome'],
            ],

            'preco' => $preco,

            'avaliacao' => [
                'media' => $avaliacao['media'] ?? 0.0,
                'total' => $avaliacao['total'] ?? 0,
            ],

            'estoque' => [
                'disponivel'   => $disponivel,
                'total'        => $estoqueTotal,
                'alerta_baixo' => $disponivel && $estoqueTotal <= self::ESTOQUE_BAIXO,
            ],

            'favoritado' => !empty($p['favoritado']),
            'tem_clip'   => !empty($lotes['clips'][$id]),

            'compatibilidade' => self::compatibilidade($id, $ctx, $lotes),

            'badges' => self::badges($preco, $disponivel, $estoqueTotal),
        ];
    }

    /**
     * `aplicavel` diz se faz sentido falar de compatibilidade para este produto
     * (está numa categoria com busca_moto). Só então o app decide entre mostrar
     * "compatível com sua moto" e "compatibilidade não confirmada" — sem isso,
     * um capacete universal apareceria como incompatível.
     */
    private static function compatibilidade(int $id, PresenterContext $ctx, array $lotes): array
    {
        $aplicavel = !empty($lotes['busca_moto'][$id]);

        if (!$aplicavel || !$ctx->temVeiculo()) {
            return [
                'aplicavel'  => $aplicavel,
                'compativel' => null,
                'rotulo'     => null,
            ];
        }

        $compativel = !empty($lotes['compat'][$id]);
        $moto       = $ctx->veiculoAtivo['label'] ?? 'sua moto';

        return [
            'aplicavel'  => true,
            'compativel' => $compativel,
            'rotulo'     => $compativel
                ? 'Compatível com ' . $moto
                : 'Compatibilidade não confirmada',
        ];
    }

    private static function badges(array $preco, bool $disponivel, int $estoque): array
    {
        $badges = [];

        if (!empty($preco['tem_promo']) && $preco['desconto_pct'] > 0) {
            $badges[] = ['tipo' => 'promo', 'texto' => '-' . $preco['desconto_pct'] . '%'];
        }
        if (!$disponivel) {
            $badges[] = ['tipo' => 'esgotado', 'texto' => 'Esgotado'];
        } elseif ($estoque <= self::ESTOQUE_BAIXO) {
            $badges[] = ['tipo' => 'ultimas', 'texto' => 'Últimas ' . $estoque . ' unidades'];
        }

        return $badges;
    }
}
