<?php
// app/presenters/CategoriaPresenter.php
// Categorias e marcas — os dois eixos de navegação do catálogo.

final class CategoriaPresenter
{
    /** @return array<int,array> */
    public static function colecao(array $rows, PresenterContext $ctx): array
    {
        return array_values(array_map(
            static fn(array $c) => self::uma($c, $ctx),
            $rows
        ));
    }

    public static function uma(array $c, PresenterContext $ctx): array
    {
        return [
            'id'        => (int)$c['id'],
            'nome'      => $c['nome'],
            'slug'      => $c['slug'],
            'parent_id' => isset($c['parent_id']) ? (int)$c['parent_id'] ?: null : null,
            'imagem'    => $ctx->url($c['imagem'] ?? null),
            'destaque'  => !empty($c['destaque']),
            // busca_moto liga o seletor montadora→modelo→ano nesta categoria
            'busca_moto'=> !empty($c['busca_moto']),
            'filhos'    => isset($c['children'])
                ? self::colecao($c['children'], $ctx)
                : [],
        ];
    }

    /** Árvore recursiva de Category::getNavTree() / getTree(). */
    public static function arvore(array $rows, PresenterContext $ctx): array
    {
        return self::colecao($rows, $ctx);
    }
}
