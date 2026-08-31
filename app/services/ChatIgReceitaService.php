<?php
/**
 * app/services/ChatIgReceitaService.php
 *
 * Catálogo das automações prontas do Instagram.
 *
 * Uma receita é um MOLDE: define o gatilho, quais campos o editor mostra e o
 * texto inicial de cada um. Depois de criada, a automação vira um registro
 * comum em chat_ig_regras — a receita fica gravada só para o painel saber
 * como desenhar o editor e agrupar por tipo.
 *
 * Cada receita declara `passos`, que é o que o editor renderiza em coluna:
 *   gatilho    → quando dispara (post/reel/story/live)
 *   condicao   → o que o comentário precisa ter
 *   acao       → o que a pessoa recebe
 *
 * Acrescentar uma receita = uma entrada em MAPA. O editor se adapta sozinho.
 */
class ChatIgReceitaService
{
    private const MAPA = [

        // ── Crescimento ─────────────────────────────────────────────────────
        'crescer_seguidores' => [
            'nome'      => 'Aumente seus seguidores com comentários',
            'resumo'    => 'Use comentários do Instagram para fazer sua conta crescer',
            'descricao' => 'Quem comentar a palavra recebe o link no direct — mas só depois de '
                         . 'seguir o perfil. Quem ainda não segue recebe o convite antes.',
            'icone'     => '📈',
            'cor'       => '#8b5cf6',
            'gatilho'   => 'comentario',
            'campos'    => ['palavras', 'resposta_publica', 'exigir_seguidor',
                            'mensagem_nao_seguidor', 'mensagem_dm', 'link', 'tag'],
            'padrao'    => [
                'palavras'              => 'quero, eu quero',
                'responder_publico'     => 1,
                'resposta_publica'      => 'Te chamei no direct! 💜 | Mandei tudo no seu direct 😉 | Corre lá no direct 🔥',
                'exigir_seguidor'       => 1,
                'mensagem_nao_seguidor' => "Oi! Vi seu comentário 😊\n\nMe segue aqui rapidinho que eu já te mando o link — assim você não perde as próximas novidades!",
                'enviar_dm'             => 1,
                'mensagem_dm'           => "Valeu por seguir! 🙌\n\nAqui está o que você pediu:",
                'link_texto'            => 'Ver agora',
                'uma_vez_por_pessoa'    => 1,
            ],
        ],

        // ── Comentário → DM ─────────────────────────────────────────────────
        'dm_comentario' => [
            'nome'      => 'Responda comentários via DM',
            'resumo'    => 'Envie uma linha de produtos usando DMs do Instagram',
            'descricao' => 'Alguém comenta a palavra combinada e recebe no direct a mensagem '
                         . 'com o link. A resposta pública avisa que a mensagem foi enviada.',
            'icone'     => '💬',
            'cor'       => '#0a66c2',
            'gatilho'   => 'comentario',
            'campos'    => ['palavras', 'resposta_publica', 'mensagem_dm', 'link', 'tag', 'fluxo'],
            'padrao'    => [
                'palavras'          => 'link, preço, quero',
                'responder_publico' => 1,
                'resposta_publica'  => 'Te chamei no direct! | Mandei no seu direct 😉',
                'enviar_dm'         => 1,
                'mensagem_dm'       => "Oi! Vi seu comentário 😊\n\nSegue o link do que você procurava:",
                'link_texto'        => 'Ver produto',
            ],
        ],

        // ── Reels ───────────────────────────────────────────────────────────
        'vender_reels' => [
            'nome'      => 'Venda pelos comentários de Reels',
            'resumo'    => 'Um reel tá gerando conversas? Entre nas DMs com uma boa oferta',
            'descricao' => 'Vale só para Reels. Quem comentar recebe a oferta no direct enquanto '
                         . 'o vídeo ainda está circulando.',
            'icone'     => '🎬',
            'cor'       => '#e1306c',
            'gatilho'   => 'comentario_reel',
            'campos'    => ['palavras', 'resposta_publica', 'mensagem_dm', 'link', 'tag', 'fluxo'],
            'padrao'    => [
                'palavras'          => '',   // vazio = qualquer comentário no reel
                'responder_publico' => 1,
                'resposta_publica'  => 'Te mandei a oferta no direct! 🔥 | Corre no direct que tem novidade 👀',
                'enviar_dm'         => 1,
                'mensagem_dm'       => "Que bom que curtiu o reel! 🔥\n\nSeparei uma condição especial pra você:",
                'link_texto'        => 'Ver oferta',
            ],
        ],

        // ── Stories ─────────────────────────────────────────────────────────
        'faq_stories' => [
            'nome'      => 'Responda a Perguntas frequentes de Stories',
            'resumo'    => 'Responda o mais rápido possível às perguntas dos seus seguidores',
            'descricao' => 'Quem responder ao seu story com uma das palavras recebe a resposta '
                         . 'na hora, sem esperar atendimento.',
            'icone'     => '⚡',
            'cor'       => '#f59e0b',
            'gatilho'   => 'story_reply',
            'campos'    => ['palavras', 'mensagem_dm', 'link', 'tag', 'fluxo'],
            'padrao'    => [
                'palavras'          => 'preço, valor, quanto custa, frete',
                'responder_publico' => 0,   // story não tem resposta pública
                'enviar_dm'         => 1,
                'mensagem_dm'       => "Oi! Respondendo sua dúvida 👇\n\n",
                'link_texto'        => 'Ver detalhes',
            ],
        ],

        // ── Live ────────────────────────────────────────────────────────────
        'converter_live' => [
            'nome'      => 'Converta na Live',
            'resumo'    => 'Dispare DMs durante Lives do IG',
            'descricao' => 'Durante a transmissão, quem comentar a palavra recebe o link no '
                         . 'direct sem você parar a live para responder.',
            'icone'     => '🔴',
            'cor'       => '#dc2626',
            'gatilho'   => 'live',
            'campos'    => ['palavras', 'mensagem_dm', 'link', 'tag', 'fluxo'],
            'padrao'    => [
                'palavras'          => 'quero, link, eu quero',
                'responder_publico' => 0,   // durante a live, responder em público polui o chat
                'enviar_dm'         => 1,
                'mensagem_dm'       => "Tá na live? 🔴\n\nAqui está o link que você pediu:",
                'link_texto'        => 'Aproveitar agora',
                'uma_vez_por_pessoa' => 1,
            ],
        ],

        // ── Livre ───────────────────────────────────────────────────────────
        'zero' => [
            'nome'      => 'Começar do zero',
            'resumo'    => 'Monte a automação do seu jeito, sem modelo pronto',
            'descricao' => 'Todos os campos disponíveis, nada preenchido.',
            'icone'     => '✨',
            'cor'       => '#64748b',
            'gatilho'   => 'comentario',
            'campos'    => ['palavras', 'resposta_publica', 'exigir_seguidor',
                            'mensagem_nao_seguidor', 'mensagem_dm', 'link',
                            'pedir_email', 'tag', 'fluxo'],
            'padrao'    => [
                'enviar_dm' => 1,
            ],
        ],
    ];

    /** Rótulos dos tipos de gatilho, para a UI. */
    private const GATILHOS = [
        'comentario'      => ['rotulo' => 'Comentário em publicação ou Reel', 'icone' => '💬'],
        'comentario_reel' => ['rotulo' => 'Comentário em Reel',               'icone' => '🎬'],
        'story_reply'     => ['rotulo' => 'Resposta a Story',                 'icone' => '⚡'],
        'live'            => ['rotulo' => 'Comentário em Live',               'icone' => '🔴'],
        'mencao'          => ['rotulo' => 'Menção da conta',                  'icone' => '@'],
    ];

    public static function todas(): array
    {
        return self::MAPA;
    }

    public static function existe(string $chave): bool
    {
        return isset(self::MAPA[$chave]);
    }

    public static function obter(string $chave): ?array
    {
        return self::MAPA[$chave] ?? null;
    }

    /** Receitas na ordem em que aparecem na tela de criação. */
    public static function paraGaleria(): array
    {
        $out = [];
        foreach (self::MAPA as $chave => $r) {
            $out[] = array_merge($r, [
                'chave'          => $chave,
                'gatilho_rotulo' => self::GATILHOS[$r['gatilho']]['rotulo'] ?? $r['gatilho'],
            ]);
        }
        return $out;
    }

    /** Config inicial de uma automação criada a partir da receita. */
    public static function padraoDe(string $chave): array
    {
        $r = self::MAPA[$chave] ?? self::MAPA['zero'];
        return array_merge([
            'receita'      => self::existe($chave) ? $chave : 'zero',
            'gatilho_tipo' => $r['gatilho'],
            'escopo'       => 'todas',
            'modo_match'   => 'contem',
            'prioridade'   => 50,
            'status'       => 'rascunho',
            'ignorar_proprios' => 1,
        ], $r['padrao']);
    }

    /** O editor só desenha os campos que a receita declara. */
    public static function campos(string $chave): array
    {
        return self::MAPA[$chave]['campos'] ?? self::MAPA['zero']['campos'];
    }

    public static function usaCampo(string $chave, string $campo): bool
    {
        return in_array($campo, self::campos($chave), true);
    }

    public static function gatilhos(): array
    {
        return self::GATILHOS;
    }

    public static function rotuloGatilho(string $g): string
    {
        return self::GATILHOS[$g]['rotulo'] ?? $g;
    }

    public static function iconeGatilho(string $g): string
    {
        return self::GATILHOS[$g]['icone'] ?? '•';
    }

    /**
     * O gatilho aceita este evento?
     *
     * `comentario` é o guarda-chuva: pega feed e reel. `comentario_reel` é
     * estrito. Live vem por um campo de webhook diferente (`live_comments`),
     * por isso não cai no guarda-chuva — misturar faria a automação de live
     * responder comentário de post comum.
     */
    public static function gatilhoAceita(string $gatilho, string $evento, string $tipoMidia = ''): bool
    {
        $tipoMidia = strtoupper($tipoMidia);

        return match ($gatilho) {
            'comentario'      => $evento === 'comments',
            'comentario_reel' => $evento === 'comments' && in_array($tipoMidia, ['REELS', 'VIDEO'], true),
            'live'            => $evento === 'live_comments',
            'story_reply'     => $evento === 'story_reply',
            'mencao'          => $evento === 'mentions',
            default           => false,
        };
    }
}
