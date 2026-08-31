<?php
// app/presenters/MarcaPresenter.php

final class MarcaPresenter
{
    /** Os logos moram em uploads/brands/; a coluna guarda só o nome. */
    private const PASTA = 'brands/';

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
            // PresenterContext::url() recebe o caminho a partir da RAIZ dos
            // uploads. Passar só o nome do arquivo — como estava aqui — gerava
            // /uploads/brand_abc.webp, e dava 404 em TODO logo de marca: na
            // lista de marcas, nos filtros do catálogo e na página da marca.
            // É o mesmo tropeço já corrigido no avatar e na garagem.
            'logo'     => self::logo($m['logo'] ?? null, $ctx),
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

    /** Nome do arquivo → URL absoluta, com a pasta certa. */
    private static function logo(?string $arquivo, PresenterContext $ctx): ?string
    {
        $arquivo = trim((string)$arquivo);
        if ($arquivo === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $arquivo)) {
            return $arquivo;
        }
        return $ctx->url(self::PASTA . ltrim($arquivo, '/'));
    }
}
