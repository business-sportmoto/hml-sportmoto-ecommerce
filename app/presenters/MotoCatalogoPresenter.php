<?php
// app/presenters/MotoCatalogoPresenter.php
//
// A navegação POR MOTO — "peças que servem na minha CG 160 2017".
//
// Não confundir com MotoPresenter, que é a GARAGEM: lá são as motos que o
// cliente cadastrou; aqui é o caminho montadora → modelo → ano que leva às
// peças compatíveis. São dois assuntos com o mesmo vocabulário, e misturá-los
// num presenter só faria cada tela receber campos que não usa.
//
// Espelha views/moto/montadoras.php e views/moto/catalogo.php.

final class MotoCatalogoPresenter
{
    /**
     * Montadoras e modelos ficam em `uploads/motos/`, e `PresenterContext::url()`
     * não sabe disso — ela só prefixa a raiz de uploads. Sem a pasta, toda arte
     * de moto responde 404.
     *
     * É a QUARTA vez que este mesmo erro aparece no projeto: já aconteceu com
     * `avatars/`, `garagem/` e `brands/`. O padrão de correção é sempre este —
     * a pasta declarada aqui, ao lado de quem monta a URL.
     */
    private const PASTA = 'motos/';

    /** Prefixa a pasta antes de absolutizar. Nulo continua nulo. */
    private static function arquivo(PresenterContext $ctx, ?string $nome): ?string
    {
        $nome = trim((string)$nome);

        return $nome === '' ? null : $ctx->url(self::PASTA . $nome);
    }

    /** @return array<int,array> */
    public static function montadoras(array $rows, PresenterContext $ctx): array
    {
        return array_values(array_map(
            static fn(array $m) => self::montadora($m, $ctx),
            $rows
        ));
    }

    public static function montadora(array $m, PresenterContext $ctx): array
    {
        return [
            'id'    => (int)$m['id'],
            'nome'  => trim((string)($m['nome'] ?? '')),
            'slug'  => (string)($m['slug'] ?? ''),
            'logo'  => self::arquivo($ctx, $m['logo'] ?? null),
            'thumb' => self::arquivo($ctx, $m['thumb'] ?? null),

            // Quantos produtos e modelos existem por trás. É o que diz se vale
            // entrar — uma montadora com 2 peças não merece o mesmo destaque
            // de uma com 200.
            'total_produtos' => isset($m['total_produtos']) ? (int)$m['total_produtos'] : null,
            'total_modelos'  => isset($m['total_modelos'])  ? (int)$m['total_modelos']  : null,
        ];
    }

    /** @return array<int,array> */
    public static function modelos(array $rows, PresenterContext $ctx): array
    {
        return array_values(array_map(
            static fn(array $mo) => [
                'id'    => (int)$mo['id'],
                'nome'  => trim((string)($mo['nome'] ?? '')),
                'slug'  => (string)($mo['slug'] ?? ''),
                'thumb' => self::arquivo($ctx, $mo['thumb'] ?? null),
                'total_produtos' => isset($mo['total_produtos']) ? (int)$mo['total_produtos'] : null,
            ],
            $rows
        ));
    }

    /**
     * Os anos de um modelo.
     *
     * `total_produtos` pode ser zero: o ano existe no cadastro da moto mesmo
     * sem nenhuma peça mapeada para ele. A tela decide se esconde ou mostra
     * apagado — o presenter não some com o dado.
     *
     * @return array<int,array>
     */
    public static function anos(array $rows): array
    {
        return array_values(array_map(
            static fn(array $a) => [
                'ano'            => (int)$a['ano'],
                'total_produtos' => isset($a['total_produtos']) ? (int)$a['total_produtos'] : null,
            ],
            $rows
        ));
    }

    /**
     * O cabeçalho da tela: onde a pessoa está na trilha montadora → modelo →
     * ano, e o título que descreve isso.
     */
    public static function contexto(array $montadora, ?array $modelo, ?int $ano, PresenterContext $ctx): array
    {
        $partes = [trim((string)($montadora['nome'] ?? ''))];
        if ($modelo) { $partes[] = trim((string)($modelo['nome'] ?? '')); }
        if ($ano)    { $partes[] = (string)$ano; }

        return [
            'montadora' => self::montadora($montadora, $ctx),
            'modelo'    => $modelo === null ? null : [
                'id'         => (int)$modelo['id'],
                'nome'       => trim((string)($modelo['nome'] ?? '')),
                'slug'       => (string)($modelo['slug'] ?? ''),
                'thumb'      => self::arquivo($ctx, $modelo['thumb'] ?? null),
                'cilindrada' => $modelo['cilindrada'] ?? null,
                'tipo'       => $modelo['tipo'] ?? null,
            ],
            'ano'    => $ano,
            // "Honda CG 160 2017" — o que a tela mostra como título e o que o
            // cliente reconhece como "a minha moto".
            'titulo' => implode(' ', $partes),
        ];
    }
}
