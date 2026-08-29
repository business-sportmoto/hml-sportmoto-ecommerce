<?php
// app/presenters/AvaliacaoPresenter.php
// Avaliações de produto, com mídia.
//
// A web monta cada avaliação em ReviewController::listar() e chama
// Review::jaVotou() DENTRO do array_map — uma query por avaliação. Aqui o voto
// chega pronto, num lote só, resolvido pelo controller (Review::votosEmLote).
// É a mesma regra dos outros presenters: nunca uma query por item.
//
// `arquivo` e `arquivo_thumb` guardam só o nome do arquivo; a pasta é sempre
// uploads/avaliacoes/. O app não sabe montar caminho — sai daqui absoluto.

final class AvaliacaoPresenter
{
    private const PASTA = 'avaliacoes/';

    /**
     * @param array<int,array> $rows      Linhas de Review::listar()
     * @param array<int,bool>  $votos     [avaliacao_id => votou], de Review::votosEmLote()
     * @return array<int,array>
     */
    public static function colecao(array $rows, PresenterContext $ctx, array $votos = []): array
    {
        return array_values(array_map(
            static fn(array $a) => self::uma($a, $ctx, $votos),
            $rows
        ));
    }

    public static function uma(array $a, PresenterContext $ctx, array $votos = []): array
    {
        $id = (int)($a['id'] ?? 0);

        return [
            'id'         => $id,
            'nota'       => (int)($a['nota'] ?? 0),
            'titulo'     => self::texto($a['titulo'] ?? null),
            'comentario' => self::texto($a['comentario'] ?? null),
            'destaque'   => !empty($a['destaque']),
            'autor'      => [
                'nome' => (string)($a['nome_exibido'] ?? $a['cliente_nome'] ?? 'Cliente'),
                // `verificado` = existe pedido aprovado com este produto. É o
                // selo "compra verificada" e vale mais que a nota em si.
                'verificado' => !empty($a['verificado']) || !empty($a['pedido_id']),
                'avatar'     => $ctx->url($a['avatar'] ?? null),
            ],
            'criado_em'  => self::data($a['criado_em'] ?? null),
            'util'       => [
                'total' => (int)($a['util_sim'] ?? 0),
                'votei' => (bool)($votos[$id] ?? false),
            ],
            'midias'     => self::midias($a['midias'] ?? [], $ctx),
            // Só o dono vê a própria avaliação ainda em moderação; para os
            // demais ela nem sai da consulta (aprovado = 1).
            'aprovada'   => !isset($a['aprovado']) || (bool)$a['aprovado'],
        ];
    }

    /**
     * Galeria global do produto — as mídias de todas as avaliações, sem o texto.
     * @param array<int,array> $rows Linhas de Review::getMidiasGlobal()
     */
    public static function galeria(array $rows, PresenterContext $ctx): array
    {
        return self::midias($rows, $ctx);
    }

    /** Resumo + distribuição, no formato que a barra de notas do app espera. */
    public static function resumo(array $r, int $comMidia = 0): array
    {
        $total = (int)($r['total'] ?? 0);

        return [
            'total'  => $total,
            'media'  => round((float)($r['media'] ?? 0), 1),
            'com_midia' => $comMidia,
            // Chaves como string: em JSON um objeto nunca tem chave numérica,
            // e "5" evita que o app receba um array esparso.
            'distribuicao' => [
                '5' => (int)($r['n5'] ?? 0),
                '4' => (int)($r['n4'] ?? 0),
                '3' => (int)($r['n3'] ?? 0),
                '2' => (int)($r['n2'] ?? 0),
                '1' => (int)($r['n1'] ?? 0),
            ],
        ];
    }

    /* ================================================================= */

    /** @param array<int,array> $midias */
    private static function midias(array $midias, PresenterContext $ctx): array
    {
        return array_values(array_map(static function (array $m) use ($ctx): array {
            $arquivo = (string)($m['arquivo'] ?? '');
            // `thumb` na tabela temporária, `arquivo_thumb` na definitiva.
            $thumb   = $m['arquivo_thumb'] ?? $m['thumb'] ?? null;

            return [
                'id'    => isset($m['id']) ? (int)$m['id'] : null,
                'tipo'  => ($m['tipo'] ?? 'imagem') === 'video' ? 'video' : 'imagem',
                'url'   => $ctx->url(self::PASTA . $arquivo),
                // Vídeo sem thumb (ffmpeg ausente) cai para null e o app mostra
                // o placeholder de play em vez de uma imagem quebrada.
                'thumb' => $thumb ? $ctx->url(self::PASTA . $thumb) : null,
            ];
        }, $midias));
    }

    private static function texto(?string $v): ?string
    {
        $v = $v === null ? '' : trim($v);
        return $v === '' ? null : $v;
    }

    private static function data(?string $valor): ?string
    {
        if (!$valor) return null;
        $ts = strtotime($valor);
        return $ts ? date(DATE_ATOM, $ts) : null;
    }
}
