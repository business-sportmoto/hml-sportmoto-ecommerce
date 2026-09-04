<?php
/**
 * app/services/ChatMetaClient.php
 *
 * Cliente completo da WhatsApp Cloud API para o módulo Chat.
 *
 * POR QUE NÃO REUSAR O MetaCloudService: aquele adapter cobre só template HSM
 * (envio fora da janela de 24h). O módulo conversacional precisa do resto da
 * superfície — texto livre, mídia, botões, listas, reações, marcar como lida,
 * download de mídia recebida. Este cliente cobre tudo e mantém o MESMO padrão
 * de retry/backoff e classificação de erro permanente que já existe no projeto.
 *
 * .env:
 *   META_PHONE_NUMBER_ID · META_CLOUD_API_TOKEN · META_WABA_ID
 *   META_API_VERSION (opcional, padrão v21.0)
 *   META_APP_SECRET     (assinatura do webhook do WhatsApp — app → Básico)
 *   META_APP_SECRET_IG  (opcional: assinatura do Instagram quando ele é
 *                        configurado por "Casos de uso" e tem chave própria)
 *
 * REGRA DE OURO DA JANELA DE 24H:
 *   Fora da janela, a Meta SÓ aceita `template`. Qualquer texto/mídia/interativo
 *   volta erro 131047. Quem decide é o ChatEnvioService, não este cliente —
 *   aqui a responsabilidade é falar HTTP com a Meta corretamente.
 */

if (!class_exists('ChatMetaException')) {
    class ChatMetaException extends RuntimeException
    {
        public int  $httpCode    = 0;
        public ?int $metaCode    = null;
        public ?int $metaSubcode = null;
        public bool $permanente  = false;

        public function __construct(
            string $msg, int $httpCode = 0, ?int $metaCode = null,
            ?int $metaSubcode = null, bool $permanente = false, ?Throwable $prev = null
        ) {
            parent::__construct($msg, 0, $prev);
            $this->httpCode    = $httpCode;
            $this->metaCode    = $metaCode;
            $this->metaSubcode = $metaSubcode;
            $this->permanente  = $permanente;
        }
    }
}

class ChatMetaClient
{
    private string $phoneNumberId;
    private string $token;
    private string $wabaId;
    private string $apiVersion;
    private string $baseUrl;
    private int    $timeout;

    private const MAX_RETRIES = 3;
    private const BACKOFF_MS  = [400, 1200, 3000];

    /** Códigos da Meta que não adianta retentar — falha é do payload/estado. */
    private const ERROS_PERMANENTES = [
        100,    // parâmetro inválido
        131008, // campo obrigatório ausente
        131009, // valor de parâmetro inválido
        131026, // número não existe no WhatsApp / não pode receber
        131030, // destinatário fora da lista de permitidos (app em dev)
        131047, // fora da janela de 24h → exige template
        131051, // tipo de mensagem não suportado
        131052, // falha ao baixar mídia
        132000, // template: número de parâmetros não bate
        132001, // template não existe / não aprovado no idioma
        132005, // template: texto traduzido longo demais
        132007, // template: violação de formato
        132012, // template: formato de parâmetro inválido
        132015, // template pausado
        132016, // template desabilitado
        133010, // número não registrado
        368,    // temporariamente bloqueado por violação de política
    ];

    /** Erro 131047 é o sinal canônico de "janela fechada, use template". */
    public const ERRO_FORA_DA_JANELA = 131047;

    public function __construct(?string $phoneNumberId = null, ?string $token = null)
    {
        $this->phoneNumberId = $phoneNumberId ?? trim(self::cfg('META_PHONE_NUMBER_ID'));
        $this->token         = $token         ?? trim(self::cfg('META_CLOUD_API_TOKEN'));
        $this->wabaId        = trim(self::cfg('META_WABA_ID'));
        $this->apiVersion    = self::cfg('META_API_VERSION', 'v21.0');
        $this->timeout       = (int)(self::cfg('META_API_TIMEOUT', '20')) ?: 20;
        $this->baseUrl       = 'https://graph.facebook.com/' . $this->apiVersion;

        if ($this->phoneNumberId === '') {
            throw new InvalidArgumentException('ChatMeta: META_PHONE_NUMBER_ID não configurado');
        }
        if ($this->token === '') {
            throw new InvalidArgumentException('ChatMeta: META_CLOUD_API_TOKEN não configurado');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('ChatMeta: extensão cURL indisponível');
        }
    }

    public function estaConfigurado(): bool
    {
        return $this->phoneNumberId !== '' && $this->token !== '';
    }

    // =========================================================================
    // ENVIO — todos devolvem ['wamid' => string, 'bruto' => array]
    // =========================================================================

    /** Texto livre. Só funciona DENTRO da janela de 24h. */
    public function enviarTexto(string $para, string $texto, bool $previewUrl = true, ?string $respostaA = null): array
    {
        $texto = trim($texto);
        if ($texto === '') throw new InvalidArgumentException('ChatMeta: texto vazio');
        // Limite duro da Meta para body de texto
        if (mb_strlen($texto) > 4096) $texto = mb_substr($texto, 0, 4093) . '...';

        return $this->enviarMensagem($para, [
            'type' => 'text',
            'text' => ['body' => $texto, 'preview_url' => $previewUrl],
        ], $respostaA);
    }

    /**
     * Mídia por URL pública ou por media_id já hospedado na Meta.
     *
     * @param string $tipo image|video|audio|document|sticker
     * @param string $origem URL http(s) ou media_id numérico
     */
    public function enviarMidia(
        string $para, string $tipo, string $origem,
        ?string $legenda = null, ?string $nomeArquivo = null, ?string $respostaA = null
    ): array {
        $tipo = strtolower(trim($tipo));
        if (!in_array($tipo, ['image', 'video', 'audio', 'document', 'sticker'], true)) {
            throw new InvalidArgumentException("ChatMeta: tipo de mídia inválido '$tipo'");
        }

        // media_id da Meta é numérico; qualquer outra coisa tratamos como link
        $midia = ctype_digit($origem) ? ['id' => $origem] : ['link' => $origem];

        // audio e sticker não aceitam caption na API
        if ($legenda !== null && $legenda !== '' && in_array($tipo, ['image', 'video', 'document'], true)) {
            $midia['caption'] = mb_substr($legenda, 0, 1024);
        }
        if ($tipo === 'document' && $nomeArquivo) {
            $midia['filename'] = mb_substr($nomeArquivo, 0, 240);
        }

        return $this->enviarMensagem($para, ['type' => $tipo, $tipo => $midia], $respostaA);
    }

    /**
     * Botões de resposta rápida (máx. 3).
     *
     * @param array $botoes [['id'=>'sim','titulo'=>'Sim'], ...] — título máx 20 chars
     * @param array $cabecalho ['tipo'=>'text|image|video|document','valor'=>...]
     */
    public function enviarBotoes(
        string $para, string $corpo, array $botoes,
        ?array $cabecalho = null, ?string $rodape = null, ?string $respostaA = null
    ): array {
        $botoes = array_slice(array_values($botoes), 0, 3);
        if (!$botoes) throw new InvalidArgumentException('ChatMeta: nenhum botão informado');

        $acoes = [];
        foreach ($botoes as $i => $b) {
            $id     = (string)($b['id'] ?? ('btn_' . ($i + 1)));
            $titulo = trim((string)($b['titulo'] ?? $b['title'] ?? ''));
            if ($titulo === '') continue;
            $acoes[] = [
                'type'  => 'reply',
                'reply' => [
                    'id'    => mb_substr($id, 0, 256),
                    'title' => mb_substr($titulo, 0, 20),   // limite duro da Meta
                ],
            ];
        }
        if (!$acoes) throw new InvalidArgumentException('ChatMeta: botões sem título');

        $interactive = [
            'type'   => 'button',
            'body'   => ['text' => mb_substr(trim($corpo), 0, 1024)],
            'action' => ['buttons' => $acoes],
        ];
        if ($rodape)    $interactive['footer'] = ['text' => mb_substr($rodape, 0, 60)];
        if ($cabecalho) $interactive['header'] = $this->montarCabecalho($cabecalho);

        return $this->enviarMensagem($para, [
            'type' => 'interactive', 'interactive' => $interactive,
        ], $respostaA);
    }

    /**
     * Lista de opções (máx. 10 linhas no total, distribuídas em seções).
     *
     * @param array $secoes [['titulo'=>'Categorias','linhas'=>[['id'=>..,'titulo'=>..,'descricao'=>..]]]]
     */
    public function enviarLista(
        string $para, string $corpo, string $textoBotao, array $secoes,
        ?array $cabecalho = null, ?string $rodape = null, ?string $respostaA = null
    ): array {
        $secoesOut = [];
        $totalLinhas = 0;

        foreach ($secoes as $s) {
            $linhas = [];
            foreach (($s['linhas'] ?? $s['rows'] ?? []) as $l) {
                if ($totalLinhas >= 10) break;   // limite duro da Meta
                $titulo = trim((string)($l['titulo'] ?? $l['title'] ?? ''));
                if ($titulo === '') continue;
                $linha = [
                    'id'    => mb_substr((string)($l['id'] ?? ('op_' . ($totalLinhas + 1))), 0, 200),
                    'title' => mb_substr($titulo, 0, 24),
                ];
                $desc = trim((string)($l['descricao'] ?? $l['description'] ?? ''));
                if ($desc !== '') $linha['description'] = mb_substr($desc, 0, 72);
                $linhas[] = $linha;
                $totalLinhas++;
            }
            if ($linhas) {
                $secoesOut[] = [
                    'title' => mb_substr((string)($s['titulo'] ?? $s['title'] ?? 'Opções'), 0, 24),
                    'rows'  => $linhas,
                ];
            }
        }
        if (!$secoesOut) throw new InvalidArgumentException('ChatMeta: lista sem linhas válidas');

        $interactive = [
            'type'   => 'list',
            'body'   => ['text' => mb_substr(trim($corpo), 0, 1024)],
            'action' => [
                'button'   => mb_substr(trim($textoBotao) ?: 'Ver opções', 0, 20),
                'sections' => $secoesOut,
            ],
        ];
        if ($rodape)    $interactive['footer'] = ['text' => mb_substr($rodape, 0, 60)];
        if ($cabecalho) $interactive['header'] = $this->montarCabecalho($cabecalho);

        return $this->enviarMensagem($para, [
            'type' => 'interactive', 'interactive' => $interactive,
        ], $respostaA);
    }

    /** Botões de CTA com URL (interactive cta_url). */
    public function enviarBotaoUrl(
        string $para, string $corpo, string $textoBotao, string $url,
        ?array $cabecalho = null, ?string $rodape = null
    ): array {
        $interactive = [
            'type'   => 'cta_url',
            'body'   => ['text' => mb_substr(trim($corpo), 0, 1024)],
            'action' => [
                'name'       => 'cta_url',
                'parameters' => [
                    'display_text' => mb_substr(trim($textoBotao) ?: 'Abrir', 0, 20),
                    'url'          => $url,
                ],
            ],
        ];
        if ($rodape)    $interactive['footer'] = ['text' => mb_substr($rodape, 0, 60)];
        if ($cabecalho) $interactive['header'] = $this->montarCabecalho($cabecalho);

        return $this->enviarMensagem($para, [
            'type' => 'interactive', 'interactive' => $interactive,
        ]);
    }

    /** Template HSM aprovado — único caminho válido fora da janela de 24h. */
    public function enviarTemplate(string $para, string $nome, string $idioma = 'pt_BR', array $componentes = []): array
    {
        $nome = trim($nome);
        if ($nome === '') throw new InvalidArgumentException('ChatMeta: nome do template vazio');

        $template = ['name' => $nome, 'language' => ['code' => trim($idioma) ?: 'pt_BR']];
        if ($componentes) $template['components'] = array_values($componentes);

        return $this->enviarMensagem($para, ['type' => 'template', 'template' => $template]);
    }

    /** Localização. */
    public function enviarLocalizacao(string $para, float $lat, float $lng, string $nome = '', string $endereco = ''): array
    {
        return $this->enviarMensagem($para, [
            'type'     => 'location',
            'location' => array_filter([
                'latitude'  => $lat,
                'longitude' => $lng,
                'name'      => $nome ?: null,
                'address'   => $endereco ?: null,
            ], fn($v) => $v !== null),
        ]);
    }

    /** Reação (emoji vazio remove a reação). */
    public function enviarReacao(string $para, string $wamid, string $emoji): array
    {
        return $this->enviarMensagem($para, [
            'type'     => 'reaction',
            'reaction' => ['message_id' => $wamid, 'emoji' => $emoji],
        ]);
    }

    /** Marca a mensagem recebida como lida (os dois tiques azuis no cliente). */
    public function marcarLida(string $wamid): bool
    {
        try {
            $this->post("/{$this->phoneNumberId}/messages", [
                'messaging_product' => 'whatsapp',
                'status'            => 'read',
                'message_id'        => $wamid,
            ]);
            return true;
        } catch (Throwable $e) {
            return false;   // marcar lida nunca pode quebrar o fluxo de recepção
        }
    }

    /** Indicador de "digitando..." (aceito junto do read receipt). */
    public function indicarDigitando(string $wamid): bool
    {
        try {
            $this->post("/{$this->phoneNumberId}/messages", [
                'messaging_product' => 'whatsapp',
                'status'            => 'read',
                'message_id'        => $wamid,
                'typing_indicator'  => ['type' => 'text'],
            ]);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    // =========================================================================
    // MÍDIA RECEBIDA
    // =========================================================================

    /** Metadados da mídia: url temporária (5 min), mime_type, sha256, file_size. */
    public function midiaInfo(string $mediaId): array
    {
        return $this->get('/' . rawurlencode($mediaId), ['phone_number_id' => $this->phoneNumberId]);
    }

    /**
     * Baixa o binário da mídia. A URL da Meta exige o Bearer token —
     * um GET simples sem header devolve 401.
     *
     * @return array{conteudo:string, mime:string, tamanho:int}
     */
    public function baixarMidia(string $mediaId): array
    {
        $info = $this->midiaInfo($mediaId);
        $url  = (string)($info['url'] ?? '');
        if ($url === '') throw new ChatMetaException('ChatMeta: mídia sem URL', 0, null, null, true);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->token],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $bin  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($bin === false || $code >= 400) {
            throw new ChatMetaException("ChatMeta: falha ao baixar mídia (HTTP $code) $err", $code);
        }

        return [
            'conteudo' => $bin,
            'mime'     => (string)($info['mime_type'] ?? 'application/octet-stream'),
            'tamanho'  => strlen($bin),
        ];
    }

    /** Sobe um arquivo local e devolve o media_id (reutilizável por 30 dias). */
    public function uploadMidia(string $caminho, ?string $mime = null): string
    {
        if (!is_file($caminho)) throw new InvalidArgumentException("ChatMeta: arquivo não existe: $caminho");
        $mime = $mime ?: (function_exists('mime_content_type') ? (mime_content_type($caminho) ?: 'application/octet-stream') : 'application/octet-stream');

        $ch = curl_init($this->baseUrl . "/{$this->phoneNumberId}/media");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->token],
            CURLOPT_POSTFIELDS     => [
                'messaging_product' => 'whatsapp',
                'type'              => $mime,
                'file'              => new CURLFile($caminho, $mime, basename($caminho)),
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode((string)$resp, true);
        if ($code >= 400 || empty($data['id'])) {
            $msg = $data['error']['message'] ?? "HTTP $code";
            throw new ChatMetaException("ChatMeta: upload falhou: $msg", $code);
        }
        return (string)$data['id'];
    }

    // =========================================================================
    // CONTA / TEMPLATES
    // =========================================================================

    public function perfilNumero(): array
    {
        return $this->get("/{$this->phoneNumberId}", [
            'fields' => 'display_phone_number,verified_name,quality_rating,messaging_limit_tier,platform_type',
        ]);
    }

    public function testarConexao(): array
    {
        try {
            $r = $this->perfilNumero();
            return [
                'ok'        => true,
                'numero'    => $r['display_phone_number'] ?? '?',
                'nome'      => $r['verified_name'] ?? '?',
                'qualidade' => $r['quality_rating'] ?? '?',
                'limite'    => $r['messaging_limit_tier'] ?? '?',
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'mensagem' => $e->getMessage()];
        }
    }

    /** Templates cadastrados no WABA (com paginação seguida até o fim). */
    public function listarTemplates(int $maxPaginas = 10): array
    {
        if ($this->wabaId === '') {
            throw new ChatMetaException('ChatMeta: META_WABA_ID não configurado', 0, null, null, true);
        }

        $todos  = [];
        $params = ['fields' => 'id,name,status,language,category,components', 'limit' => 100];
        $url    = "/{$this->wabaId}/message_templates";
        $pagina = 0;

        while ($url !== '' && $pagina++ < $maxPaginas) {
            $r = str_starts_with($url, 'http')
                ? $this->request('GET', $url, null)
                : $this->get($url, $params);

            foreach (($r['data'] ?? []) as $t) $todos[] = $t;

            $url    = (string)($r['paging']['next'] ?? '');
            $params = [];
        }
        return $todos;
    }

    // =========================================================================
    // ASSINATURA DO WEBHOOK
    // =========================================================================

    /**
     * Valida o header X-Hub-Signature-256 contra o corpo CRU da requisição.
     *
     * Precisa do body exatamente como chegou (php://input) — qualquer
     * re-serialização do JSON muda bytes e invalida o HMAC.
     */
    public static function assinaturaValida(string $corpoBruto, ?string $header, ?string $appSecret = null): bool
    {
        return self::qualSegredoAssinou($corpoBruto, $header, $appSecret) !== null;
    }

    /**
     * Qual segredo assinou esta chamada — 'whatsapp', 'instagram' ou null.
     *
     * Um app da Meta pode ter DOIS segredos, e os dois webhooks chegam no mesmo
     * endpoint:
     *
     *   · WhatsApp  → chave secreta do app (Configurações do app → Básico)
     *   · Instagram → quando configurado por "Casos de uso" (Instagram API com
     *                 login do Instagram), o produto tem chave PRÓPRIA e assina
     *                 com ela.
     *
     * Validar com um só faz o outro canal ser sempre recusado — e o sintoma é
     * mudo: o webhook chega, a assinatura não bate, a chamada é descartada.
     *
     * Tentar os dois não enfraquece nada: continua sendo preciso conhecer um
     * dos segredos reais. Devolver QUAL casou é o que torna o problema
     * diagnosticável em vez de invisível.
     */
    public static function qualSegredoAssinou(
        string $corpoBruto, ?string $header, ?string $appSecret = null
    ): ?string {
        if (!$header) return null;

        $header = trim($header);
        if (!str_starts_with($header, 'sha256=')) return null;
        $recebida = substr($header, 7);

        $candidatos = $appSecret !== null
            ? ['whatsapp' => $appSecret]
            : self::segredos();

        foreach ($candidatos as $canal => $secret) {
            if ($secret === '') continue;
            if (hash_equals(hash_hmac('sha256', $corpoBruto, $secret), $recebida)) {
                return $canal;
            }
        }
        return null;
    }

    /**
     * Segredos configurados, por canal.
     *
     * `META_APP_SECRET_IG` é opcional: quem usa o Instagram pela API antiga
     * (login do Facebook) assina com o segredo do app mesmo, e não precisa de
     * uma segunda chave.
     */
    public static function segredos(): array
    {
        return array_filter([
            'whatsapp'  => trim(self::cfg('META_APP_SECRET')),
            'instagram' => trim(self::cfg('META_APP_SECRET_IG')),
        ], fn($v) => $v !== '');
    }

    public static function temAppSecret(): bool
    {
        return self::segredos() !== [];
    }

    /** Há segredo próprio do Instagram configurado? */
    public static function temAppSecretIg(): bool
    {
        return trim(self::cfg('META_APP_SECRET_IG')) !== '';
    }

    public static function verifyToken(): string
    {
        return trim(self::cfg('META_WEBHOOK_VERIFY_TOKEN'));
    }

    // =========================================================================
    // INTERNO
    // =========================================================================

    /** Normaliza número BR para E.164 sem "+", tratando o 9º dígito. */
    public static function normalizarNumero(string $numero): string
    {
        $n = preg_replace('/\D/', '', $numero) ?? '';
        if ($n === '') return '';

        // 8 ou 9 dígitos = número local sem DDD: não dá para adivinhar, devolve cru
        if (strlen($n) <= 9) return $n;

        // 10/11 dígitos = DDD + número, sem país → assume Brasil
        if (strlen($n) === 10 || strlen($n) === 11) $n = '55' . $n;

        return $n;
    }

    /** Monta o header de um interativo a partir de ['tipo'=>..,'valor'=>..]. */
    private function montarCabecalho(array $cab): array
    {
        $tipo  = strtolower((string)($cab['tipo'] ?? $cab['type'] ?? 'text'));
        $valor = (string)($cab['valor'] ?? $cab['value'] ?? '');

        if ($tipo === 'text') {
            return ['type' => 'text', 'text' => mb_substr($valor, 0, 60)];
        }
        if (in_array($tipo, ['image', 'video', 'document'], true)) {
            $midia = ctype_digit($valor) ? ['id' => $valor] : ['link' => $valor];
            if ($tipo === 'document' && !empty($cab['filename'])) {
                $midia['filename'] = (string)$cab['filename'];
            }
            return ['type' => $tipo, $tipo => $midia];
        }
        return ['type' => 'text', 'text' => mb_substr($valor, 0, 60)];
    }

    /** Envelope comum de /messages + extração do wamid. */
    private function enviarMensagem(string $para, array $conteudo, ?string $respostaA = null): array
    {
        $para = self::normalizarNumero($para);
        if ($para === '' || strlen($para) < 10) {
            throw new InvalidArgumentException("ChatMeta: número inválido '$para'");
        }

        $body = array_merge([
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $para,
        ], $conteudo);

        if ($respostaA) $body['context'] = ['message_id' => $respostaA];

        $r = $this->post("/{$this->phoneNumberId}/messages", $body);

        return [
            'wamid' => (string)($r['messages'][0]['id'] ?? ''),
            'bruto' => $r,
        ];
    }

    private function get(string $path, array $params = []): array
    {
        $url = $this->baseUrl . $path;
        if ($params) $url .= '?' . http_build_query($params);
        return $this->request('GET', $url, null);
    }

    private function post(string $path, array $body): array
    {
        return $this->request('POST', $this->baseUrl . $path, $body);
    }

    private function request(string $method, string $url, ?array $body): array
    {
        $ultimoErro = null;

        for ($t = 0; $t <= self::MAX_RETRIES; $t++) {
            if ($t > 0) usleep((self::BACKOFF_MS[$t - 1] ?? 3000) * 1000);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $this->token,
                    'Content-Type: application/json',
                ],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS,
                    json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }

            $resp     = curl_exec($ch);
            $code     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErrn = curl_errno($ch);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            // ── Falha de rede ──
            if ($resp === false || $curlErrn !== 0) {
                $ultimoErro = new ChatMetaException("ChatMeta: falha de rede: $curlErr", 0);
                if (in_array($curlErrn, [
                    CURLE_OPERATION_TIMEOUTED, CURLE_COULDNT_CONNECT,
                    CURLE_COULDNT_RESOLVE_HOST, CURLE_GOT_NOTHING,
                ], true)) continue;
                throw $ultimoErro;
            }

            $data = json_decode((string)$resp, true);
            if (!is_array($data)) {
                if ($code >= 500) { $ultimoErro = new ChatMetaException("ChatMeta: HTTP $code (não-JSON)", $code); continue; }
                throw new ChatMetaException("ChatMeta: resposta inválida HTTP $code", $code);
            }

            if ($code < 400 && empty($data['error'])) return $data;

            // ── Erro da API ──
            $err      = $data['error'] ?? [];
            $msg      = (string)($err['message'] ?? "HTTP $code");
            $metaCode = isset($err['code'])          ? (int)$err['code']          : null;
            $metaSub  = isset($err['error_subcode']) ? (int)$err['error_subcode'] : null;
            $detalhe  = !empty($err['error_data']['details']) ? ' | ' . $err['error_data']['details']
                      : (!empty($err['error_user_msg']) ? ' | ' . $err['error_user_msg'] : '');

            $permanente = ($metaCode && in_array($metaCode, self::ERROS_PERMANENTES, true))
                       || ($code >= 400 && $code < 500 && $code !== 429);

            $ex = new ChatMetaException("Meta: {$msg}{$detalhe}", $code, $metaCode, $metaSub, $permanente);
            if ($permanente) throw $ex;

            $ultimoErro = $ex;   // 429 / 5xx → retenta
        }

        throw $ultimoErro ?? new ChatMetaException('ChatMeta: falha após retries');
    }

    private static function cfg(string $key, string $default = ''): string
    {
        if (defined($key)) { $v = constant($key); if (is_string($v) && $v !== '') return $v; }
        $v = getenv($key); if ($v !== false && $v !== '') return (string)$v;
        if (isset($_ENV[$key])    && $_ENV[$key]    !== '') return (string)$_ENV[$key];
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return (string)$_SERVER[$key];
        return $default;
    }
}
