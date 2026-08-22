<?php
// app/presenters/BannerPresenter.php
// Banners para o app.
//
// A boa notícia: a tabela `banners` já guarda variantes mobile
// (arquivo_imagem_mobile, arquivo_video_mobile), então não há nada a criar do
// lado do admin — o app consome o que a equipe de conteúdo já cadastra.
//
// Vídeo é servido pelo Cloudflare Stream em HLS. iOS reproduz HLS nativamente
// e o Android via ExoPlayer; expo-video cobre os dois.

final class BannerPresenter
{
    /** @return array<int,array> */
    public static function colecao(array $rows, PresenterContext $ctx): array
    {
        return array_values(array_map(
            static fn(array $b) => self::um($b, $ctx),
            $rows
        ));
    }

    public static function um(array $b, PresenterContext $ctx): array
    {
        $ehVideo = in_array($b['tipo_midia'] ?? 'imagem', ['video', 'video_com_imagem'], true);

        return [
            'id'     => (int)$b['id'],
            'titulo' => $b['titulo'] ?? null,
            'badge'  => $b['nome_publico'] ?? null,
            'alt'    => $b['alt_text'] ?? ($b['titulo'] ?? null),

            'midia' => [
                'tipo'  => $ehVideo ? 'video' : 'imagem',
                // Mobile primeiro, desktop como fallback: um banner sem variante
                // mobile ainda aparece, só que na proporção larga.
                'imagem' => $ctx->url($b['arquivo_imagem_mobile'] ?: ($b['arquivo_imagem'] ?? null)),
                'video'  => $ehVideo ? self::videoUrl($b) : null,
                'autoplay' => (bool)($b['video_autoplay'] ?? false),
                'loop'     => (bool)($b['video_loop'] ?? false),
                'mudo'     => (bool)($b['video_mute'] ?? true),
            ],

            'overlay' => [
                'titulo'     => $b['titulo_overlay'] ?? null,
                'subtitulo'  => $b['subtitulo_overlay'] ?? null,
                'posicao'    => $b['posicao_texto'] ?? 'bottom-left',
                'cor_texto'  => $b['cor_texto'] ?? null,
                'cor_fundo'  => $b['cor_fundo'] ?? null,
                'cor'        => $b['cor_overlay'] ?? null,
                'opacidade'  => isset($b['overlay_opacidade']) ? (int)$b['overlay_opacidade'] : 0,
            ],

            'ctas' => array_values(array_filter([
                self::cta($b, 1),
                self::cta($b, 2),
            ])),

            // O banner inteiro clicável. destino é o mesmo objeto navegável dos
            // "ver mais" das seções — o app não interpreta URL da web.
            'destino' => !empty($b['link_geral']) ? DestinoPresenter::de($b['link_geral']) : null,

            // data_fim futura vira contagem regressiva no banner (a web já faz isso)
            'expira_em' => !empty($b['data_fim']) ? date(DATE_ATOM, strtotime($b['data_fim'])) : null,
        ];
    }

    private static function cta(array $b, int $n): ?array
    {
        $texto = $b["cta{$n}_texto"] ?? '';
        if (trim((string)$texto) === '') {
            return null;
        }

        return [
            'texto'   => $texto,
            'estilo'  => $b["cta{$n}_estilo"] ?? 'primary',
            'destino' => !empty($b["cta{$n}_link"]) ? DestinoPresenter::de($b["cta{$n}_link"]) : null,
        ];
    }

    /**
     * Cloudflare Stream guarda um UID de 32 hex e a URL HLS é derivada dele.
     * Usamos StreamService::hlsUrl() em vez de montar a URL à mão: o customer
     * code vem da configuração e mudá-lo em um lugar só é o certo.
     * Um valor que já seja URL completa (vídeo externo) passa direto.
     */
    private static function videoUrl(array $b): ?string
    {
        $uid = $b['arquivo_video_mobile'] ?: ($b['arquivo_video'] ?? null);

        if ($uid && preg_match('/^[0-9a-f]{32}$/i', (string)$uid)) {
            try {
                return (new StreamService())->hlsUrl((string)$uid);
            } catch (\Throwable $e) {
                // Stream sem customer code configurado: o banner ainda aparece
                // como imagem, em vez de derrubar a home inteira.
                //
                // Loga UMA vez por request. Sem o guard, uma home com vários
                // banners de vídeo num ambiente sem CF_STREAM_CUSTOMER_CODE
                // gravava um INSERT em `logs` por banner, em toda visita — o
                // aviso de configuração virava carga de escrita no caminho
                // mais quente da API.
                static $avisado = false;
                if (!$avisado) {
                    $avisado = true;
                    LogService::error('CF_STREAM_CUSTOMER_CODE ausente: vídeo de banner não será servido');
                }
                return null;
            }
        }

        if ($uid && preg_match('#^https?://#i', (string)$uid)) {
            return (string)$uid;
        }

        return !empty($b['video_url_externo']) ? (string)$b['video_url_externo'] : null;
    }
}
