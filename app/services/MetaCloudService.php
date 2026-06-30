<?php
/**
 * app/services/MetaCloudService.php
 *
 * Adapter para a Meta WhatsApp Cloud API — envio de templates HSM aprovados.
 * Usado quando o cliente está fora da janela de 24h (não é possível texto livre).
 *
 * Documentação Meta: https://developers.facebook.com/docs/whatsapp/cloud-api/messages/templates
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CONFIGURAÇÃO (.env ou config/config.php):
 *   META_PHONE_NUMBER_ID  — ID numérico do número no Meta Business Manager
 *   META_CLOUD_API_TOKEN  — Token permanente do usuário de sistema
 *   META_API_VERSION      — (opcional) padrão: v19.0
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * FLUXO:
 *   1. Crie e aprove templates no Meta Business Manager
 *   2. Use MetaCloudService::enviarTemplate() ou os métodos do WhatsappService
 *
 * TIPOS DE COMPONENTES DE TEMPLATE:
 *   - header  → título (texto, imagem, documento, vídeo)
 *   - body    → corpo com variáveis {{1}}, {{2}}, ...
 *   - button  → botões de resposta rápida ou URL
 */

if (!class_exists('MetaCloudException')) {
    class MetaCloudException extends RuntimeException
    {
        public $httpCode    = 0;
        public $metaCode    = null;
        public $metaSubcode = null;

        public function __construct(string $msg, int $httpCode = 0, ?int $metaCode = null, ?int $metaSubcode = null, ?Throwable $prev = null)
        {
            parent::__construct($msg, 0, $prev);
            $this->httpCode    = $httpCode;
            $this->metaCode    = $metaCode;
            $this->metaSubcode = $metaSubcode;
        }
    }
}

class MetaCloudService
{
    private $phoneNumberId;
    private $token;
    private $apiVersion;
    private $baseUrl;
    private $timeout;

    private const MAX_RETRIES = 3;
    private const BACKOFF_MS  = [300, 800, 2000];

    /** Códigos de erro Meta que NÃO devem ser retentados */
    private const ERROS_PERMANENTES = [
        100,  // invalid parameter
        131030, // template not found / not approved
        131031, // template paused
        131047, // re-engagement message (fora da janela sem template)
        131051, // unsupported message type
        131052, // media download error
        368,  // temporarily blocked
    ];

    public function __construct()
    {
        $this->phoneNumberId = trim($this->cfg('META_PHONE_NUMBER_ID'));
        $this->token         = trim($this->cfg('META_CLOUD_API_TOKEN'));
        $this->apiVersion    = $this->cfg('META_API_VERSION', 'v19.0');
        $this->timeout       = (int)($this->cfg('META_API_TIMEOUT', '15')) ?: 15;
        $this->baseUrl       = 'https://graph.facebook.com/' . $this->apiVersion;

        if ($this->phoneNumberId === '') {
            throw new InvalidArgumentException('MetaCloud: META_PHONE_NUMBER_ID não configurado');
        }
        if ($this->token === '') {
            throw new InvalidArgumentException('MetaCloud: META_CLOUD_API_TOKEN não configurado');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('MetaCloud: extensão cURL não disponível');
        }
    }

    // =========================================================================
    // ENVIO DE TEMPLATE
    // =========================================================================

    /**
     * Envia um template HSM aprovado pelo Meta.
     *
     * @param string $para       Número destino em formato E.164 sem + (ex: 5547999998888)
     * @param string $template   Nome exato do template aprovado (ex: 'pedido_enviado')
     * @param string $idioma     Código do idioma (ex: 'pt_BR')
     * @param array  $componentes Componentes do template (body, header, buttons)
     * @return array Resposta da API com 'messages[0].id'
     * @throws MetaCloudException
     *
     * Exemplo de $componentes:
     *   [
     *     [
     *       'type' => 'body',
     *       'parameters' => [
     *         ['type' => 'text', 'text' => 'João'],
     *         ['type' => 'text', 'text' => 'SM-001'],
     *       ]
     *     ]
     *   ]
     */
    public function enviarTemplate(
        string $para,
        string $template,
        string $idioma,
        array  $componentes = []
    ): array {
        $para     = preg_replace('/\D/', '', $para);
        $template = trim($template);
        $idioma   = trim($idioma) ?: 'pt_BR';

        if ($para === '' || strlen($para) < 10) {
            throw new InvalidArgumentException("MetaCloud: número inválido: '$para'");
        }
        if ($template === '') {
            throw new InvalidArgumentException('MetaCloud: nome do template vazio');
        }

        $body = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $para,
            'type'              => 'template',
            'template'          => [
                'name'     => $template,
                'language' => ['code' => $idioma],
            ],
        ];

        if (!empty($componentes)) {
            $body['template']['components'] = $componentes;
        }

        return $this->post("/{$this->phoneNumberId}/messages", $body);
    }

    // =========================================================================
    // HELPERS DE COMPONENTES — construtores fluentes
    // =========================================================================

    /**
     * Cria componente body com variáveis de texto.
     *
     * Uso: MetaCloudService::body('João', 'SM-001', 'BR123456')
     */
    public static function body(string ...$variaveis): array
    {
        return [
            'type'       => 'body',
            'parameters' => array_map(
                fn($v) => ['type' => 'text', 'text' => (string)$v],
                $variaveis
            ),
        ];
    }

    /**
     * Cria componente header de texto.
     */
    public static function headerTexto(string $texto): array
    {
        return [
            'type'       => 'header',
            'parameters' => [['type' => 'text', 'text' => $texto]],
        ];
    }

    /**
     * Cria componente header de imagem (URL pública).
     */
    public static function headerImagem(string $url): array
    {
        return [
            'type'       => 'header',
            'parameters' => [['type' => 'image', 'image' => ['link' => $url]]],
        ];
    }

    /**
     * Cria componente de botão URL dinâmico (sufixo da URL).
     * Ex: template tem URL base "https://sportmoto.com.br/pedido/" e o botão
     * recebe o sufixo "SM-001" → URL final "https://sportmoto.com.br/pedido/SM-001"
     */
    public static function botaoUrl(int $indice, string $sufixo): array
    {
        return [
            'type'       => 'button',
            'sub_type'   => 'url',
            'index'      => (string)$indice,
            'parameters' => [['type' => 'text', 'text' => $sufixo]],
        ];
    }

    // =========================================================================
    // DIAGNÓSTICO
    // =========================================================================

    /**
     * Lista os templates cadastrados e seus status de aprovação.
     * Útil para descobrir os nomes exatos antes de usar enviarTemplate().
     */
    public function listarTemplates(?string $status = null): array
    {
        $wabaId = trim($this->cfg('META_WABA_ID'));

        // Se não configurado, tenta descobrir via API
        if ($wabaId === '') {
            $wabaId = $this->descobrirWabaId();
        }

        if (!$wabaId) {
            throw new MetaCloudException(
                'MetaCloud: não foi possível obter o WABA ID. ' .
                'Configure META_WABA_ID no .env (Meta Business Manager → WhatsApp → Configurações → ID da conta)'
            );
        }

        $params = ['fields' => 'name,status,language,category,components'];
        if ($status) $params['status'] = strtoupper($status);

        return $this->get("/{$wabaId}/message_templates", $params);
    }

    /**
     * Tenta descobrir o WABA ID por diferentes métodos.
     * Retorna null se não conseguir — nesse caso configure META_WABA_ID manualmente.
     */
    private function descobrirWabaId(): ?string
    {
        // Método 1: campo owned_whatsapp_business_accounts via /me
        try {
            $me = $this->get('/me', ['fields' => 'id,owned_whatsapp_business_accounts']);
            $contas = $me['owned_whatsapp_business_accounts']['data'] ?? [];
            if (!empty($contas[0]['id'])) return (string)$contas[0]['id'];
        } catch (Throwable $e) {}

        // Método 2: via phone_number_id com campo account_id
        try {
            $phone = $this->get("/{$this->phoneNumberId}", ['fields' => 'account_id']);
            if (!empty($phone['account_id'])) return (string)$phone['account_id'];
        } catch (Throwable $e) {}

        // Método 3: via phone_number_id com campo id direto
        try {
            $phone = $this->get("/{$this->phoneNumberId}", ['fields' => 'id,display_phone_number']);
            // O WABA geralmente é o pai do phone number — tenta via edge
            $waba = $this->get("/{$this->phoneNumberId}/whatsapp_business_account", []);
            if (!empty($waba['id'])) return (string)$waba['id'];
        } catch (Throwable $e) {}

        return null;
    }

    /**
     * Verifica se as credenciais estão corretas.
     */
    public function testarConexao(): array
    {
        try {
            $r = $this->get("/{$this->phoneNumberId}", [
                'fields' => 'display_phone_number,verified_name,quality_rating',
            ]);
            return [
                'ok'      => true,
                'numero'  => $r['display_phone_number'] ?? '?',
                'nome'    => $r['verified_name'] ?? '?',
                'qualidade' => $r['quality_rating'] ?? '?',
                'dados'   => $r,
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'mensagem' => $e->getMessage()];
        }
    }

    // =========================================================================
    // HTTP
    // =========================================================================

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
            if ($t > 0) usleep((self::BACKOFF_MS[$t - 1] ?? 2000) * 1000);

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
                    json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                );
            }

            $resp     = curl_exec($ch);
            $code     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErrn = curl_errno($ch);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            // Erro de rede
            if ($resp === false || $curlErrn !== 0) {
                $ultimoErro = new MetaCloudException("Falha de rede: {$curlErr}", 0);
                if (in_array($curlErrn, [
                    CURLE_OPERATION_TIMEOUTED, CURLE_COULDNT_CONNECT,
                    CURLE_COULDNT_RESOLVE_HOST, CURLE_GOT_NOTHING,
                ], true)) continue;
                throw $ultimoErro;
            }

            $data = json_decode($resp, true);
            if (!is_array($data)) {
                if ($code >= 500) {
                    $ultimoErro = new MetaCloudException("Meta HTTP {$code} (não-JSON)", $code);
                    continue;
                }
                throw new MetaCloudException("Meta resposta inválida HTTP {$code}", $code);
            }

            // Sucesso
            if ($code < 400 && empty($data['error'])) {
                return $data;
            }

            // Erro da Meta API
            $err        = $data['error'] ?? [];
            $msg        = $err['message']          ?? "HTTP {$code}";
            $metaCode   = isset($err['code'])    ? (int)$err['code']    : null;
            $metaSub    = isset($err['error_subcode']) ? (int)$err['error_subcode'] : null;
            $userMsg    = $err['error_user_msg']  ?? '';
            $detalhe    = $userMsg ? " | {$userMsg}" : '';

            $ex = new MetaCloudException("Meta API: {$msg}{$detalhe}", $code, $metaCode, $metaSub);

            // Erros permanentes: não retentar
            if ($metaCode && in_array($metaCode, self::ERROS_PERMANENTES, true)) {
                throw $ex;
            }
            // 4xx genérico: não retentar
            if ($code >= 400 && $code < 500 && $code !== 429) {
                throw $ex;
            }

            // 429 e 5xx: transiente, retentar
            $ultimoErro = $ex;
        }

        throw $ultimoErro ?? new MetaCloudException('Meta: falha após retries');
    }

    // =========================================================================
    // CONFIG
    // =========================================================================

    private function cfg(string $key, string $default = ''): string
    {
        if (defined($key)) { $v = constant($key); if (is_string($v) && $v !== '') return $v; }
        $v = getenv($key); if ($v !== false && $v !== '') return (string)$v;
        if (isset($_ENV[$key])    && $_ENV[$key]    !== '') return (string)$_ENV[$key];
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return (string)$_SERVER[$key];
        return $default;
    }
}