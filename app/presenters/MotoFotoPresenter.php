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

    /** As três versões moram em uploads/garagem/; a coluna guarda só o nome. */
    public const PASTA = 'garagem/';

    /** Nome do arquivo → URL absoluta, com a pasta certa. */
    public static function arquivo(?string $nome, PresenterContext $ctx): ?string
    {
        $nome = trim((string)$nome);
        if ($nome === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $nome)) {
            return $nome;
        }
        return $ctx->url(self::PASTA . ltrim($nome, '/'));
    }

    public static function uma(array $f, PresenterContext $ctx): array
    {
        return [
            'id'       => (int)$f['id'],
            // PresenterContext::url() recebe o caminho a partir da RAIZ dos
            // uploads. Passar só o nome do arquivo — como estava aqui — gerava
            // /uploads/abc_medium.webp e dava 404 em TODA foto da garagem.
            // É o mesmo tropeço já corrigido no avatar, em PerfilPresenter.
            'thumb'    => self::arquivo($f['arquivo_thumb']  ?? null, $ctx),
            'medium'   => self::arquivo($f['arquivo_medium'] ?? null, $ctx),
            'full'     => self::arquivo($f['arquivo_full']   ?? null, $ctx),
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
