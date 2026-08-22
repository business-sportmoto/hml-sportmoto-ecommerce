<?php
// app/presenters/HomeSectionPresenter.php
// As seções de carrossel da home.
//
// PersonalizationService::buildHomeSections() já devolve quase o formato certo
// — ['id','badge','titulo','subtitulo','tipo','ver_mais_url','produtos'].
// O que falta é traduzir ver_mais_url num destino navegável e serializar os
// produtos.
//
// ── O detalhe que faz a diferença ───────────────────────────────────────────
// Os batches do card rodam UMA vez para TODAS as seções, com os ids
// deduplicados. A home tem até 12 seções de 18 produtos, e elas repetem
// bastante produto entre si (um lançamento em promoção aparece em pelo menos
// três). Refazer os batches por seção jogaria fora a maior parte do ganho.

final class HomeSectionPresenter
{
    /**
     * @param  array<int,array> $secoes saída de buildHomeSections()
     * @return array<int,array>
     */
    public static function colecao(array $secoes, PresenterContext $ctx): array
    {
        if (!$secoes) {
            return [];
        }

        // Achata os produtos de todas as seções e monta os lotes de uma vez.
        $todos = [];
        foreach ($secoes as $s) {
            foreach ($s['produtos'] ?? [] as $p) {
                $todos[(int)$p['id']] = $p; // a chave já deduplica
            }
        }

        $lotes = ProductCardPresenter::lotes(array_values($todos), $ctx);

        // Serializa cada produto uma única vez e reusa por referência de id.
        $cards = [];
        foreach ($todos as $id => $p) {
            $cards[$id] = ProductCardPresenter::comLotes($p, $ctx, $lotes);
        }

        $saida = [];
        foreach ($secoes as $s) {
            $produtos = [];
            foreach ($s['produtos'] ?? [] as $p) {
                $id = (int)$p['id'];
                if (isset($cards[$id])) {
                    $produtos[] = $cards[$id];
                }
            }

            if (!$produtos) {
                continue; // seção vazia não vira carrossel vazio no app
            }

            $saida[] = [
                'id'        => $s['id'] ?? null,
                'badge'     => $s['badge'] ?? null,
                'titulo'    => $s['titulo'] ?? '',
                'subtitulo' => $s['subtitulo'] ?? null,
                'tipo'      => $s['tipo'] ?? 'fixed',
                'ver_mais'  => DestinoPresenter::de($s['ver_mais_url'] ?? null),
                'produtos'  => $produtos,
            ];
        }

        return $saida;
    }
}
