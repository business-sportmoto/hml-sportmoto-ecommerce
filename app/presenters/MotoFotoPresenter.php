<?php
// app/presenters/MotoFotoPresenter.php
// Fotos da moto do cliente.
//
// Três tamanhos são gravados no upload (thumb, medium, full) e o app recebe os
// três: a grade usa thumb, o visualizador usa full. Mandar só a maior faria o
// aparelho baixar megabytes para desenhar quadradinhos de 100px.

final class MotoFotoPresenter
{
    /** @return array<int,array> */
    public static function colecao(array $fotos, PresenterContext $ctx): array
    {
        return array_values(array_map(
            static fn(array $f) => self::uma($f, $ctx),
            $fotos
        ));
    }

    public static function uma(array $f, PresenterContext $ctx): array
    {
        return [
            'id'       => (int)$f['id'],
            'thumb'    => $ctx->url($f['arquivo_thumb'] ?? null),
            'medium'   => $ctx->url($f['arquivo_medium'] ?? null),
            'full'     => $ctx->url($f['arquivo_full'] ?? null),
            'largura'  => isset($f['largura']) ? (int)$f['largura'] : null,
            'altura'   => isset($f['altura']) ? (int)$f['altura'] : null,
            'legenda'  => $f['legenda'] ?? null,
            'capa'     => !empty($f['capa']),

            // Privado é o padrão do VeiculoFotoService, e está certo: publicar
            // a moto de alguém é escolha do dono.
            'visibilidade' => $f['visibilidade'] ?? 'privado',

            // Foto pública passa por moderação. O app precisa disso para
            // explicar por que ela ainda não aparece na comunidade.
            'moderacao' => [
                'status' => $f['status_moderacao'] ?? 'aprovada',
                'motivo' => $f['motivo_rejeicao'] ?? null,
                'pendente' => ($f['status_moderacao'] ?? '') === 'pendente',
            ],

            'criado_em' => !empty($f['criado_em'])
                ? date(DATE_ATOM, strtotime((string)$f['criado_em']))
                : null,
        ];
    }
}
