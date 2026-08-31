<?php
// app/presenters/ClipPresenter.php
// Clips — os vídeos verticais shoppable, o recurso mais característico da loja.
//
// Vídeo vive no Cloudflare Stream e é entregue em HLS. As URLs de reprodução e
// de poster são derivadas do UID por ClipService, sem chamada de API — o que
// importa num feed com scroll infinito.
//
// Cada clip carrega os produtos vinculados já serializados: o card sobe por
// cima do vídeo e precisa aparecer junto com ele, não numa segunda requisição.

final class ClipPresenter
{
    /** @return array<int,array> */
    public static function colecao(array $clips, PresenterContext $ctx): array
    {
        if (!$clips) {
            return [];
        }

        $servico = new ClipService();

        return array_values(array_map(
            static fn(array $c) => self::um($c, $ctx, $servico),
            $clips
        ));
    }

    public static function um(array $c, PresenterContext $ctx, ?ClipService $servico = null): array
    {
        $servico ??= new ClipService();
        $uid = (string)($c['arquivo_video'] ?? '');

        return [
            'id'        => (int)$c['id'],
            'titulo'    => $c['titulo'] ?? null,
            'descricao' => $c['descricao'] ?? null,
            'hashtags'  => self::hashtags($c['hashtags'] ?? null),

            'video' => [
                // arquivo_hls preenchido tem precedência (vídeo hospedado fora
                // do Stream); senão derivamos do UID.
                'hls'      => self::hls($c, $uid, $servico),
                'poster'   => $servico->posterFor($c),
                'duracao'  => isset($c['duracao_segundos']) ? (int)$c['duracao_segundos'] : null,
                'resolucao'=> $c['resolucao'] ?? null,
            ],

            'metricas' => [
                'views'          => (int)($c['total_views'] ?? 0),
                'likes'          => (int)($c['total_likes'] ?? 0),
                'comentarios'    => (int)($c['total_comentarios'] ?? 0),
                'compartilhados' => (int)($c['total_compartilhamentos'] ?? 0),
            ],

            // Quem publicou. `autor_id` aponta para usuarios; sem ele, o clip é
            // da própria loja — e é a loja que assina.
            'autor' => self::autor($c),

            'criado_em' => !empty($c['criado_em'])
                ? date(DATE_ATOM, strtotime((string)$c['criado_em']))
                : null,

            // Estado do usuário atual. Preenchido pelo controller quando houver
            // cliente — o presenter não consulta nada por item.
            'curtiu' => (bool)($c['_curtiu'] ?? false),

            'cta' => empty($c['cta_texto']) ? null : [
                'texto'   => $c['cta_texto'],
                'destino' => DestinoPresenter::de($c['cta_link'] ?? null),
            ],

            'produtos' => self::produtos($c['produtos'] ?? [], $ctx),

            'compartilhar' => [
                'url'   => $ctx->baseUrl . '/clip/' . (int)$c['id'],
                'texto' => $c['titulo'] ?? 'Veja este clip',
            ],
        ];
    }

    /**
     * O autor do clip: nome, arroba e iniciais para o avatar.
     *
     * O nome vem do JOIN que o controller faz (`autor_nome`); sem autor, assina
     * a loja. A arroba é derivada do nome porque a tabela não guarda handle —
     * inventar um campo no admin só para isso seria pedir trabalho a alguém
     * para um enfeite.
     */
    private static function autor(array $c): array
    {
        $nome = trim((string)($c['autor_nome'] ?? ''));

        if ($nome === '') {
            $nome = defined('SITE_NOME') ? (string)SITE_NOME : 'Loja';
        }

        return [
            'nome'     => $nome,
            'arroba'   => self::arroba($nome),
            'iniciais' => self::iniciais($nome),
            'oficial'  => empty($c['autor_id']),
        ];
    }

    /** "Moto Vlog" → "motovlog" */
    private static function arroba(string $nome): string
    {
        $limpo = preg_replace('/[^a-z0-9]/', '', mb_strtolower($nome)) ?? '';
        return $limpo !== '' ? mb_substr($limpo, 0, 24) : 'loja';
    }

    /** "Moto Vlog" → "MV" */
    private static function iniciais(string $nome): string
    {
        $partes = array_values(array_filter(preg_split('/\s+/', trim($nome)) ?: []));
        if (!$partes) return '?';

        $ini = mb_substr($partes[0], 0, 1);
        if (count($partes) > 1) {
            $ini .= mb_substr($partes[count($partes) - 1], 0, 1);
        }
        return mb_strtoupper($ini);
    }

    private static function hls(array $c, string $uid, ClipService $servico): ?string
    {
        $hls = trim((string)($c['arquivo_hls'] ?? ''));
        if ($hls !== '' && str_starts_with($hls, 'http')) {
            return $hls;
        }

        if (preg_match('/^[a-f0-9]{32}$/i', $uid)) {
            try {
                return $servico->hlsUrl($uid);
            } catch (\Throwable $e) {
                LogService::error('Clip sem CF_STREAM_CUSTOMER_CODE', ['clip_id' => $c['id'] ?? null]);
            }
        }

        return null;
    }

    /**
     * Produto vinculado ao clip. Versão enxuta de propósito: é um card flutuante
     * sobre o vídeo, não a vitrine — não vale carregar avaliação nem
     * compatibilidade para cada um deles no meio de um feed.
     */
    private static function produtos(array $rows, PresenterContext $ctx): array
    {
        $out = [];
        foreach ($rows as $p) {
            $produto = [
                'preco'       => $p['produto_preco'] ?? null,
                'preco_promo' => $p['produto_preco_promo'] ?? null,
            ];

            $out[] = [
                'id'     => (int)$p['produto_id'],
                'nome'   => $p['produto_nome'],
                'slug'   => $p['produto_slug'],
                'imagem' => $ctx->url($p['produto_imagem'] ?? null)
                            ?? $ctx->url('images/placeholder.jpg', 'asset'),
                'preco'  => PrecoPresenter::bloco($produto),
            ];
        }
        return $out;
    }

    private static function hashtags(?string $raw): array
    {
        if (!$raw) {
            return [];
        }

        // A coluna aceita JSON ou uma lista separada por vírgula/espaço.
        $json = json_decode($raw, true);
        if (is_array($json)) {
            return array_values(array_filter(array_map('strval', $json)));
        }

        preg_match_all('/#?([\p{L}\p{N}_]+)/u', $raw, $m);
        return array_values(array_filter($m[1] ?? []));
    }
}
