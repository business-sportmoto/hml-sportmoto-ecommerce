<?php
// app/presenters/MarcaPresenter.php

final class MarcaPresenter
{
    /** @return array<int,array> */
    public static function colecao(array $rows, PresenterContext $ctx): array
    {
        return array_values(array_map(
            static fn(array $m) => self::uma($m, $ctx),
            $rows
        ));
    }

    public static function uma(array $m, PresenterContext $ctx): array
    {
        return [
            'id'       => (int)$m['id'],
            'nome'     => $m['nome'],
            'slug'     => $m['slug'],
            'logo'     => $ctx->url($m['logo'] ?? null),
            // A cor de fundo vem do cadastro e é o que faz o card da marca
            // parecer o mesmo no app e no site.
            'bg_cor'   => $m['bg_cor'] ?? null,
            'destaque' => !empty($m['destaque']),
            'site'     => $m['site'] ?? null,
            'descricao'=> $m['descricao'] ?? null,
            // Presente quando vem de getBrandsForFilter(): quantos produtos a
            // marca tem dentro do filtro atual.
            'total'    => isset($m['total']) ? (int)$m['total'] : null,
        ];
    }
}
