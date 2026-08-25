<?php
// app/presenters/MotoPresenter.php
// A garagem — o recurso que separa esta loja de um e-commerce genérico.
//
// A moto ATIVA muda o catálogo inteiro: define o que é "compatível" em todo
// card e habilita o filtro "serve na minha moto". Por isso o payload sempre
// diz qual é a ativa, e não deixa o app deduzir.

final class MotoPresenter
{
    /** @return array<int,array> */
    public static function colecao(array $veiculos, PresenterContext $ctx): array
    {
        $ativoId = (int)($ctx->veiculoAtivo['id'] ?? 0);

        return array_values(array_map(
            static fn(array $v) => self::uma($v, $ctx, $ativoId),
            $veiculos
        ));
    }

    public static function uma(array $v, PresenterContext $ctx, ?int $ativoId = null): array
    {
        $ativoId ??= (int)($ctx->veiculoAtivo['id'] ?? 0);
        $id = (int)$v['id'];

        return [
            'id'      => $id,
            // `label` já vem montado por VeiculoService::buildLabel() — é o
            // mesmo texto que a barra "meu veículo" mostra no site.
            'label'   => $v['label'] ?? null,
            'apelido' => $v['apelido'] ?? null,
            'ano'     => isset($v['ano']) && $v['ano'] ? (int)$v['ano'] : null,
            'cor'     => $v['cor'] ?? null,
            'placa'   => $v['placa'] ?? null,

            'montadora' => [
                'id'    => (int)$v['montadora_id'],
                'nome'  => $v['montadora_nome'] ?? null,
                'slug'  => $v['montadora_slug'] ?? null,
                'thumb' => $ctx->url($v['montadora_thumb'] ?? null),
            ],
            'modelo' => empty($v['modelo_id']) ? null : [
                'id'    => (int)$v['modelo_id'],
                'nome'  => $v['modelo_nome'] ?? null,
                'slug'  => $v['modelo_slug'] ?? null,
                'thumb' => $ctx->url($v['modelo_thumb'] ?? null),
            ],

            // `principal` é o que o banco guarda; `ativa` é o que vale AGORA na
            // sessão. Normalmente coincidem, mas o app precisa do segundo.
            'ativa'      => $id === $ativoId,
            'principal'  => !empty($v['principal']),
            'criado_em'  => !empty($v['criado_em'])
                ? date(DATE_ATOM, strtotime((string)$v['criado_em']))
                : null,
        ];
    }

    /** Item dos selects em cascata montadora → modelo → ano. */
    public static function montadoras(array $rows, PresenterContext $ctx): array
    {
        return array_values(array_map(static fn(array $m) => [
            'id'    => (int)$m['id'],
            'nome'  => $m['nome'],
            'slug'  => $m['slug'],
            'logo'  => $ctx->url($m['logo'] ?? null),
            'thumb' => $ctx->url($m['thumb'] ?? null),
        ], $rows));
    }

    public static function modelos(array $rows, PresenterContext $ctx): array
    {
        return array_values(array_map(static fn(array $m) => [
            'id'         => (int)$m['id'],
            'nome'       => $m['nome'],
            'slug'       => $m['slug'],
            'thumb'      => $ctx->url($m['thumb'] ?? null),
            'cilindrada' => $m['cilindrada'] ?? null,
            'tipo'       => $m['tipo'] ?? null,
        ], $rows));
    }
}
