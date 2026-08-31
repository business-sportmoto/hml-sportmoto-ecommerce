<?php
/**
 * app/services/ChatInstagramClient.php
 *
 * Cliente da Instagram Messaging API + Graph API de comentários.
 *
 * DIFERENÇAS QUE IMPORTAM EM RELAÇÃO AO WHATSAPP (ChatMetaClient):
 *
 *   · Autenticação: usa o TOKEN DA PÁGINA do Facebook vinculada à conta IG,
 *     não um token global do .env. Por isso o construtor recebe o token —
 *     ele vem de chat_ig_contas.page_token.
 *
 *   · Fora da janela de 24h NÃO existe template HSM. O que libera é a tag
 *     HUMAN_AGENT, que dá 7 dias e exige a permissão "Human Agent" aprovada.
 *
 *   · Botões viram "quick replies" (até 13) ou botões de template. Não há
 *     o formato de lista do WhatsApp.
 *
 *   · A "private reply" a um comentário só pode ser usada UMA VEZ por
 *     comentário e dentro de 7 dias. A segunda tentativa devolve erro 10903.
 *     Quem controla isso é o ChatInstagramService, com base em
 *     chat_ig_comentarios.dm_enviado.
 *
 * Documentação: developers.facebook.com/docs/messenger-platform/instagram
 */

if (!class_exists('ChatIgException')) {
    class ChatIgException extends RuntimeException
    {
        public int  $httpCode   = 0;
        public ?int $metaCode   = null;
        public ?int $metaSub    = null;
        public bool $permanente = false;

        public function __construct(
            string $msg, int $httpCode = 0, ?int $metaCode = null,
            ?int $metaSub = null, bool $permanente = false
        ) {
            parent::__construct($msg);
            $this->httpCode   = $httpCode;
            $this->metaCode   = $metaCode;
            $this->metaSub    = $metaSub;
            $this->permanente = $permanente;
        }
    }
}

class ChatInstagramClient
{
    private string $token;
    private string $igUserId;
    private string $pageId;
    private string $apiVersion;
    private string $baseUrl;
    private int    $timeout;

    private const MAX_RETRIES = 3;
    private const BACKOFF_MS  = [400, 1200, 3000];

    /** Erros que não adianta retentar. */
    private const ERROS_PERMANENTES = [
        100,    // parâmetro inválido
        200,    // permissão ausente
        10,     // permissão não concedida
        190,    // token inválido/expirado
        613,    // rate limit de chamada (tratado à parte abaixo)
        10903,  // private reply já usada neste comentário
        10900,  // comentário não encontrado / apagado
        2534014, // fora da janela e sem tag válida
        551,    // usuário não disponível para receber
    ];

    /** Fora da janela de 24h sem tag válida. */
    public const ERRO_FORA_DA_JANELA = 2534014;
    /** Private reply já consumida para aquele comentário. */
    public const ERRO_REPLY_USADA    = 10903;

    /** Tags de mensagem aceitas fora da janela padrão. */
    public const TAG_HUMAN_AGENT = 'HUMAN_AGENT';

    public function __construct(string $pageToken, string $igUserId, string $pageId = '')
    {
        $this->token    = trim($pageToken);
        $this->igUserId = trim($igUserId);
        $this->pageId   = trim($pageId);

        if ($this->token === '')    throw new InvalidArgumentException('ChatIg: token da página vazio');
        if ($this->igUserId === '') throw new InvalidArgumentException('ChatIg: ig_user_id vazio');
        if (!function_exists('curl_init')) throw new RuntimeException('ChatIg: cURL indisponível');

        $this->apiVersion = self::cfg('META_API_VERSION', 'v21.0');
        $this->timeout    = (int)(self::cfg('META_API_TIMEOUT', '20')) ?: 20;
        $this->baseUrl    = 'https://graph.facebook.com/' . $this->apiVersion;
    }

    /** Constrói a partir de uma linha de chat_ig_contas. */
    public static function daConta(array $conta): self
    {
        return new self(
            (string)($conta['page_token'] ?? ''),
            (string)($conta['ig_user_id'] ?? ''),
            (string)($conta['page_id'] ?? '')
        );
    }

    /**
     * Nó do endpoint /messages.
     *
     * A Meta tem DOIS modelos de autenticação para DM do Instagram, e eles não
     * se misturam:
     *   · Instagram API com login do Facebook  → POST /{page-id}/messages
     *     com token da PÁGINA (é o nosso caso)
     *   · Instagram API com login do Instagram → POST /{ig-user-id}/messages
     *     com token de usuário do Instagram
     *
     * Usar o ig_user_id com token de página devolve o enganoso
     * "(#3) Application does not have the capability to make this API call",
     * que parece falta de permissão mas é só o endpoint errado.
     */
    private function noDeMensagens(): string
    {
        return $this->pageId !== '' ? $this->pageId : 'me';
    }

    // =========================================================================
    // ENVIO DE DM
    // =========================================================================

    /**
     * Texto simples.
     *
     * @param string|null $tag TAG_HUMAN_AGENT para responder fora das 24h
     */
    public function enviarTexto(string $igsid, string $texto, ?string $tag = null): array
    {
        $texto = trim($texto);
        if ($texto === '') throw new InvalidArgumentException('ChatIg: texto vazio');
        // Limite do Instagram Direct
        if (mb_strlen($texto) > 1000) $texto = mb_substr($texto, 0, 997) . '...';

        return $this->enviarMensagem(['id' => $igsid], ['text' => $texto], $tag);
    }

    /**
     * Texto com respostas rápidas (até 13).
     *
     * @param array $opcoes [['titulo'=>'Sim','payload'=>'sim'], ...] título ≤ 20 chars
     */
    public function enviarRespostasRapidas(
        string $igsid, string $texto, array $opcoes, ?string $tag = null
    ): array {
        $qr = [];
        foreach (array_slice(array_values($opcoes), 0, 13) as $i => $o) {
            $titulo = trim((string)($o['titulo'] ?? $o['title'] ?? ''));
            if ($titulo === '') continue;
            $qr[] = [
                'content_type' => 'text',
                'title'        => mb_substr($titulo, 0, 20),
                'payload'      => mb_substr((string)($o['payload'] ?? ('op_' . ($i + 1))), 0, 1000),
            ];
        }
        if (!$qr) throw new InvalidArgumentException('ChatIg: nenhuma resposta rápida válida');

        return $this->enviarMensagem(['id' => $igsid], [
            'text'          => mb_substr(trim($texto), 0, 1000),
            'quick_replies' => $qr,
        ], $tag);
    }

    /**
     * Mídia por URL pública.
     * @param string $tipo image|video|audio|file
     */
    public function enviarMidia(string $igsid, string $tipo, string $url, ?string $tag = null): array
    {
        $tipo = strtolower(trim($tipo));
        if (!in_array($tipo, ['image', 'video', 'audio', 'file'], true)) {
            throw new InvalidArgumentException("ChatIg: tipo de mídia inválido '$tipo'");
        }
        return $this->enviarMensagem(['id' => $igsid], [
            'attachment' => [
                'type'    => $tipo,
                'payload' => ['url' => $url, 'is_reusable' => true],
            ],
        ], $tag);
    }

    /**
     * Card com botões (generic template, 1 elemento).
     *
     * @param array $botoes [['tipo'=>'url','titulo'=>'Ver','url'=>'...'],
     *                       ['tipo'=>'postback','titulo'=>'Quero','payload'=>'quero']]
     */
    public function enviarCard(
        string $igsid, string $titulo, ?string $subtitulo, ?string $imagemUrl,
        array $botoes = [], ?string $tag = null
    ): array {
        $bts = [];
        foreach (array_slice(array_values($botoes), 0, 3) as $i => $b) {
            $t = trim((string)($b['titulo'] ?? ''));
            if ($t === '') continue;

            if (($b['tipo'] ?? 'postback') === 'url') {
                $bts[] = ['type' => 'web_url', 'url' => (string)$b['url'], 'title' => mb_substr($t, 0, 20)];
            } else {
                $bts[] = [
                    'type'    => 'postback',
                    'title'   => mb_substr($t, 0, 20),
                    'payload' => mb_substr((string)($b['payload'] ?? ('btn_' . ($i + 1))), 0, 1000),
                ];
            }
        }

        $elemento = array_filter([
            'title'     => mb_substr(trim($titulo), 0, 80),
            'subtitle'  => $subtitulo ? mb_substr(trim($subtitulo), 0, 80) : null,
            'image_url' => $imagemUrl ?: null,
            'buttons'   => $bts ?: null,
        ], fn($v) => $v !== null);

        return $this->enviarMensagem(['id' => $igsid], [
            'attachment' => [
                'type'    => 'template',
                'payload' => ['template_type' => 'generic', 'elements' => [$elemento]],
            ],
        ], $tag);
    }

    /** Carrossel de até 10 cards. */
    public function enviarCarrossel(string $igsid, array $cards, ?string $tag = null): array
    {
        $elementos = [];
        foreach (array_slice(array_values($cards), 0, 10) as $c) {
            $bts = [];
            foreach (array_slice((array)($c['botoes'] ?? []), 0, 3) as $i => $b) {
                $t = trim((string)($b['titulo'] ?? ''));
                if ($t === '') continue;
                $bts[] = ($b['tipo'] ?? 'postback') === 'url'
                    ? ['type' => 'web_url', 'url' => (string)$b['url'], 'title' => mb_substr($t, 0, 20)]
                    : ['type' => 'postback', 'title' => mb_substr($t, 0, 20),
                       'payload' => mb_substr((string)($b['payload'] ?? ('btn_' . ($i + 1))), 0, 1000)];
            }
            $elementos[] = array_filter([
                'title'     => mb_substr(trim((string)($c['titulo'] ?? '')), 0, 80),
                'subtitle'  => !empty($c['subtitulo']) ? mb_substr((string)$c['subtitulo'], 0, 80) : null,
                'image_url' => $c['imagem'] ?? null,
                'buttons'   => $bts ?: null,
            ], fn($v) => $v !== null);
        }
        if (!$elementos) throw new InvalidArgumentException('ChatIg: carrossel sem cards');

        return $this->enviarMensagem(['id' => $igsid], [
            'attachment' => [
                'type'    => 'template',
                'payload' => ['template_type' => 'generic', 'elements' => $elementos],
            ],
        ], $tag);
    }

    /**
     * PRIVATE REPLY — abre um DM a partir de um comentário.
     *
     * É o recurso que sustenta o "comente PROMO e receba no direct". Regras da
     * Meta: uma única vez por comentário, dentro de 7 dias, e o destinatário é
     * o comentário (não o usuário).
     */
    public function responderNoDirect(string $commentId, string $texto): array
    {
        $texto = trim($texto);
        if ($texto === '') throw new InvalidArgumentException('ChatIg: texto vazio');

        return $this->enviarMensagem(
            ['comment_id' => $commentId],
            ['text' => mb_substr($texto, 0, 1000)]
        );
    }

    /** Private reply com respostas rápidas. */
    public function responderNoDirectComOpcoes(string $commentId, string $texto, array $opcoes): array
    {
        $qr = [];
        foreach (array_slice(array_values($opcoes), 0, 13) as $i => $o) {
            $t = trim((string)($o['titulo'] ?? ''));
            if ($t === '') continue;
            $qr[] = ['content_type' => 'text', 'title' => mb_substr($t, 0, 20),
                     'payload' => mb_substr((string)($o['payload'] ?? ('op_' . ($i + 1))), 0, 1000)];
        }

        $msg = ['text' => mb_substr(trim($texto), 0, 1000)];
        if ($qr) $msg['quick_replies'] = $qr;

        return $this->enviarMensagem(['comment_id' => $commentId], $msg);
    }

    /** typing_on | typing_off | mark_seen */
    public function acaoRemetente(string $igsid, string $acao = 'mark_seen'): bool
    {
        if (!in_array($acao, ['typing_on', 'typing_off', 'mark_seen'], true)) return false;
        try {
            $this->post("/" . $this->noDeMensagens() . "/messages", [
                'recipient'     => ['id' => $igsid],
                'sender_action' => $acao,
            ]);
            return true;
        } catch (Throwable $e) {
            return false;   // cortesia visual nunca pode quebrar o fluxo
        }
    }

    // =========================================================================
    // COMENTÁRIOS
    // =========================================================================

    /** Resposta PÚBLICA, dentro da thread do comentário. */
    public function responderComentario(string $commentId, string $texto): array
    {
        $texto = trim($texto);
        if ($texto === '') throw new InvalidArgumentException('ChatIg: resposta vazia');

        return $this->post('/' . rawurlencode($commentId) . '/replies', [
            'message' => mb_substr($texto, 0, 2200),
        ]);
    }

    public function ocultarComentario(string $commentId, bool $ocultar = true): bool
    {
        try {
            $this->post('/' . rawurlencode($commentId), ['hide' => $ocultar ? 'true' : 'false']);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function excluirComentario(string $commentId): bool
    {
        try {
            $this->request('DELETE', $this->baseUrl . '/' . rawurlencode($commentId), null);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function comentario(string $commentId): array
    {
        return $this->get('/' . rawurlencode($commentId), [
            'fields' => 'id,text,username,timestamp,parent_id,hidden,like_count,from,media',
        ]);
    }

    public function comentariosDaMidia(string $mediaId, int $limite = 50): array
    {
        $r = $this->get('/' . rawurlencode($mediaId) . '/comments', [
            'fields' => 'id,text,username,timestamp,like_count,replies{id,text,username}',
            'limit'  => max(1, min(100, $limite)),
        ]);
        return $r['data'] ?? [];
    }

    // =========================================================================
    // CONTA E MÍDIAS
    // =========================================================================

    public function perfilDaConta(): array
    {
        return $this->get('/' . $this->igUserId, [
            'fields' => 'id,username,name,profile_picture_url,followers_count,media_count',
        ]);
    }

    /**
     * Perfil de quem mandou DM. Os campos de "segue/é seguido" só voltam
     * quando a pessoa iniciou conversa — é o que a Meta libera.
     */
    public function perfilDoUsuario(string $igsid): array
    {
        try {
            return $this->get('/' . rawurlencode($igsid), [
                'fields' => 'name,username,profile_pic,follower_count,is_user_follow_business,is_business_follow_user',
            ]);
        } catch (Throwable $e) {
            return [];   // perfil é enriquecimento, não pode bloquear o atendimento
        }
    }

    /** Posts e reels, do mais novo para o mais antigo. */
    public function midias(int $limite = 50): array
    {
        $r = $this->get('/' . $this->igUserId . '/media', [
            'fields' => 'id,caption,media_type,media_product_type,media_url,permalink,'
                      . 'thumbnail_url,timestamp,comments_count,like_count',
            'limit'  => max(1, min(100, $limite)),
        ]);
        return $r['data'] ?? [];
    }

    public function midia(string $mediaId): array
    {
        return $this->get('/' . rawurlencode($mediaId), [
            'fields' => 'id,caption,media_type,media_product_type,permalink,thumbnail_url,'
                      . 'media_url,timestamp,comments_count,like_count',
        ]);
    }

    public function testarConexao(): array
    {
        try {
            $p = $this->perfilDaConta();
            return [
                'ok'          => true,
                'id'          => $p['id'] ?? $this->igUserId,
                'username'    => $p['username'] ?? '?',
                'nome'        => $p['name'] ?? '',
                'seguidores'  => $p['followers_count'] ?? 0,
                'publicacoes' => $p['media_count'] ?? 0,
                'foto'        => $p['profile_picture_url'] ?? null,
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'mensagem' => $e->getMessage()];
        }
    }

    // =========================================================================
    // DESCOBERTA (estático — usa token de usuário, não de página)
    // =========================================================================

    /**
     * Lista as páginas do usuário e a conta IG Business de cada uma.
     * É o passo que descobre o page_token que todo o resto usa.
     *
     * Exige no token: pages_show_list + instagram_basic
     * (e instagram_manage_messages para depois conseguir enviar DM).
     */
    public static function descobrirContas(string $userToken): array
    {
        $versao = self::cfg('META_API_VERSION', 'v21.0');
        $url = "https://graph.facebook.com/$versao/me/accounts?"
             . http_build_query([
                 'fields'       => 'id,name,access_token,instagram_business_account{id,username,name,profile_picture_url,followers_count}',
                 'limit'        => 50,
                 'access_token' => $userToken,
             ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $d = json_decode((string)$resp, true);
        if (!is_array($d)) {
            throw new ChatIgException("ChatIg: resposta inválida ao listar páginas (HTTP $code)", $code);
        }
        if (!empty($d['error'])) {
            throw new ChatIgException(
                'ChatIg: ' . ($d['error']['message'] ?? 'erro ao listar páginas'),
                $code, $d['error']['code'] ?? null, null, true
            );
        }

        $out = [];
        foreach (($d['data'] ?? []) as $pg) {
            $ig = $pg['instagram_business_account'] ?? null;
            if (!$ig || empty($ig['id'])) continue;   // página sem IG vinculado não serve

            $out[] = [
                'page_id'     => (string)$pg['id'],
                'page_nome'   => (string)($pg['name'] ?? ''),
                'page_token'  => (string)($pg['access_token'] ?? ''),
                'ig_user_id'  => (string)$ig['id'],
                'username'    => (string)($ig['username'] ?? ''),
                'nome'        => (string)($ig['name'] ?? ''),
                'foto_url'    => $ig['profile_picture_url'] ?? null,
                'seguidores'  => isset($ig['followers_count']) ? (int)$ig['followers_count'] : null,
            ];
        }
        return $out;
    }

    /**
     * Assina o app nos eventos da página. Sem isto o webhook não chega,
     * mesmo com a URL cadastrada no painel.
     */
    /**
     * Assina o app nos eventos DA PÁGINA (mensagens).
     *
     * Atenção aos dois níveis de assinatura, que são coisas diferentes:
     *   · App → objeto `instagram` (no painel, Webhooks): entrega comentários,
     *     menções e mensagens de TODAS as contas conectadas. É o principal.
     *   · Página → subscribed_apps (esta chamada): habilita os eventos de
     *     conversa naquela página específica.
     *
     * `comments` e `live_comments` NÃO são campos válidos aqui — a lista de
     * campos da Página é outra, e mandá-los faz a chamada inteira falhar com
     * erro 100. Comentário se assina no objeto `instagram`, no painel.
     */
    public function assinarWebhook(string $pageId): array
    {
        return $this->post('/' . rawurlencode($pageId) . '/subscribed_apps', [
            'subscribed_fields' => 'messages,messaging_postbacks,messaging_optins,'
                                 . 'message_reactions,messaging_referrals,message_reads',
        ]);
    }

    public function assinaturasDaPagina(string $pageId): array
    {
        return $this->get('/' . rawurlencode($pageId) . '/subscribed_apps', ['fields' => 'subscribed_fields']);
    }

    // =========================================================================
    // HTTP
    // =========================================================================

    /** Envelope de /messages. */
    private function enviarMensagem(array $destinatario, array $mensagem, ?string $tag = null): array
    {
        $body = [
            'recipient' => $destinatario,
            'message'   => $mensagem,
        ];

        // Private reply não aceita messaging_type — o comment_id já define o contexto
        if (!isset($destinatario['comment_id'])) {
            if ($tag) {
                $body['messaging_type'] = 'MESSAGE_TAG';
                $body['tag']            = $tag;
            } else {
                $body['messaging_type'] = 'RESPONSE';
            }
        }

        $r = $this->post("/" . $this->noDeMensagens() . "/messages", $body);

        return [
            'wamid' => (string)($r['message_id'] ?? ''),   // mesma chave do WhatsApp: o resto do módulo não precisa saber a diferença
            'igsid' => (string)($r['recipient_id'] ?? ''),
            'bruto' => $r,
        ];
    }

    private function get(string $path, array $params = []): array
    {
        $params['access_token'] = $this->token;
        return $this->request('GET', $this->baseUrl . $path . '?' . http_build_query($params), null);
    }

    private function post(string $path, array $body): array
    {
        return $this->request('POST', $this->baseUrl . $path, $body);
    }

    private function request(string $metodo, string $url, ?array $body): array
    {
        $ultimo = null;

        for ($t = 0; $t <= self::MAX_RETRIES; $t++) {
            if ($t > 0) usleep((self::BACKOFF_MS[$t - 1] ?? 3000) * 1000);

            $ch = curl_init($url);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_CUSTOMREQUEST  => $metodo,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            ];
            if ($body !== null) {
                // O token vai no corpo em POST; em GET já foi na querystring
                $body['access_token'] = $this->token;
                $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            curl_setopt_array($ch, $opts);

            $resp  = curl_exec($ch);
            $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno = curl_errno($ch);
            $err   = curl_error($ch);
            curl_close($ch);

            if ($resp === false || $errno !== 0) {
                $ultimo = new ChatIgException("ChatIg: falha de rede: $err", 0);
                if (in_array($errno, [
                    CURLE_OPERATION_TIMEOUTED, CURLE_COULDNT_CONNECT,
                    CURLE_COULDNT_RESOLVE_HOST, CURLE_GOT_NOTHING,
                ], true)) continue;
                throw $ultimo;
            }

            $d = json_decode((string)$resp, true);
            if (!is_array($d)) {
                if ($code >= 500) { $ultimo = new ChatIgException("ChatIg: HTTP $code (não-JSON)", $code); continue; }
                throw new ChatIgException("ChatIg: resposta inválida HTTP $code", $code);
            }

            if ($code < 400 && empty($d['error'])) return $d;

            $e        = $d['error'] ?? [];
            $msg      = (string)($e['message'] ?? "HTTP $code");
            $metaCode = isset($e['code']) ? (int)$e['code'] : null;
            $metaSub  = isset($e['error_subcode']) ? (int)$e['error_subcode'] : null;
            $extra    = !empty($e['error_user_msg']) ? ' | ' . $e['error_user_msg'] : '';

            // 613 é rate limit: transiente, apesar de estar na lista de "conhecidos"
            $permanente = ($metaCode !== null && $metaCode !== 613
                           && in_array($metaCode, self::ERROS_PERMANENTES, true))
                       || ($code >= 400 && $code < 500 && $code !== 429 && $metaCode !== 613);

            $ex = new ChatIgException("Instagram: {$msg}{$extra}", $code, $metaCode, $metaSub, $permanente);
            if ($permanente) throw $ex;

            $ultimo = $ex;
        }

        throw $ultimo ?? new ChatIgException('ChatIg: falha após retries');
    }

    private static function cfg(string $chave, string $default = ''): string
    {
        if (defined($chave)) { $v = constant($chave); if (is_string($v) && $v !== '') return $v; }
        $v = getenv($chave); if ($v !== false && $v !== '') return (string)$v;
        if (isset($_ENV[$chave])    && $_ENV[$chave]    !== '') return (string)$_ENV[$chave];
        if (isset($_SERVER[$chave]) && $_SERVER[$chave] !== '') return (string)$_SERVER[$chave];
        return $default;
    }
}
