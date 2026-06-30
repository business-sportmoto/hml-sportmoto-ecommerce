<?php
/**
 * app/services/DataCrazyService.php
 *
 * Adapter para a API do CRM DataCrazy (WhatsApp Cloud API oficial).
 * Documentação: https://docs.datacrazy.io/
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CONFIGURAÇÃO (config/config.php via define, ou .env via getenv/$_ENV):
 *   DATACRAZY_API_KEY     — (obrigatório) Bearer token da API
 *   DATACRAZY_INSTANCE_ID — (obrigatório) ID da instância WhatsApp conectada
 *   DATACRAZY_API_URL     — (opcional) padrão: https://api.g1.datacrazy.io
 *   DATACRAZY_TIMEOUT     — (opcional) timeout em segundos, padrão 15
 *   DATACRAZY_DDD_PADRAO  — (opcional) DDD para números sem DDD, ex: '41'
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Lança InvalidArgumentException se a configuração estiver ausente.
 * Lança DataCrazyException em erros de API/rede (já com retry automático).
 */

if (!class_exists('DataCrazyException')) {
    class DataCrazyException extends RuntimeException
    {
        /** @var int HTTP status code (0 = erro de rede/local) */
        public $httpCode = 0;
        /** @var mixed corpo da resposta, se houver */
        public $responseBody = null;

        public function __construct(string $message, int $httpCode = 0, $responseBody = null, ?Throwable $previous = null)
        {
            parent::__construct($message, 0, $previous);
            $this->httpCode     = $httpCode;
            $this->responseBody = $responseBody;
        }
    }
}

class DataCrazyService
{
    /** @var string */
    private $apiKey;
    /** @var string */
    private $instanceId;
    /** @var string */
    private $baseUrl;
    /** @var int */
    private $timeout;
    /** @var string DDD padrão para números sem DDD (vazio = rejeita) */
    private $dddPadrao;

    /** @var int Máximo de tentativas em erro transiente */
    private const MAX_RETRIES = 3;
    /** @var int[] Backoff em ms entre tentativas */
    private const BACKOFF_MS = [200, 600, 1500];
    /** @var int Tamanho máximo de mensagem WhatsApp (limite Meta: 4096) */
    private const MAX_MSG_LEN = 4000;

    /** @var array Cache em memória de conversas buscadas nesta request */
    private static $conversaCache = [];

    public function __construct()
    {
        $this->apiKey     = trim($this->getConfig('DATACRAZY_API_KEY'));
        $this->instanceId = trim($this->getConfig('DATACRAZY_INSTANCE_ID'));
        $this->baseUrl    = rtrim($this->getConfig('DATACRAZY_API_URL', 'https://api.g1.datacrazy.io'), '/');
        $this->timeout    = (int)($this->getConfig('DATACRAZY_TIMEOUT', '15')) ?: 15;
        $this->dddPadrao  = preg_replace('/\D/', '', $this->getConfig('DATACRAZY_DDD_PADRAO', ''));

        if ($this->apiKey === '') {
            throw new InvalidArgumentException('DataCrazy: DATACRAZY_API_KEY não configurada');
        }
        if ($this->instanceId === '') {
            throw new InvalidArgumentException('DataCrazy: DATACRAZY_INSTANCE_ID não configurada');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('DataCrazy: extensão cURL do PHP não disponível');
        }
    }

    // =========================================================================
    // CONVERSAS
    // =========================================================================

    /**
     * Busca conversa pelo número de telefone (com cache por request).
     *
     * @return array|null Conversa encontrada ou null
     */
    public function buscarConversaPorTelefone(string $telefone): ?array
    {
        $numero = $this->normalizarTelefone($telefone);
        if (!$numero) return null;

        if (array_key_exists($numero, self::$conversaCache)) {
            return self::$conversaCache[$numero];
        }

        // Nota: filter deve ser passado como chaves separadas (filter[x])
        // pois json_encode gera string que a API rejeita com HTTP 400.
        $res = $this->get('/api/v1/conversations', [
            'search'            => $numero,
            'filter[instances]' => $this->instanceId,
            'filter[opened]'    => 'false',
            'take'              => 10,
        ]);

        $encontrada = null;
        if (!empty($res['data']) && is_array($res['data'])) {
            foreach ($res['data'] as $conv) {
                $contactId = $conv['contact']['contactId'] ?? '';
                if ($this->mesmoNumero($contactId, $numero)) {
                    $encontrada = $conv;
                    break;
                }
            }
        }

        self::$conversaCache[$numero] = $encontrada;
        return $encontrada;
    }

    /**
     * Envia mensagem de texto para uma conversa existente.
     *
     * @throws DataCrazyException
     */
    public function enviarMensagem(string $conversaId, string $texto, ?string $dataAgendada = null): array
    {
        $conversaId = trim($conversaId);
        if ($conversaId === '') {
            throw new InvalidArgumentException('DataCrazy: conversaId vazio');
        }

        $texto = $this->prepararTexto($texto);
        if ($texto === '') {
            throw new InvalidArgumentException('DataCrazy: mensagem vazia');
        }

        $body = ['body' => $texto];
        if ($dataAgendada) {
            $body['scheduledDate'] = $dataAgendada;
        }

        return $this->post('/api/v1/conversations/' . rawurlencode($conversaId) . '/messages', $body);
    }

    // =========================================================================
    // LEADS
    // =========================================================================

    /**
     * Cria lead no DataCrazy. Idempotente: se já existir lead com o telefone,
     * retorna o existente em vez de duplicar.
     *
     * @throws DataCrazyException
     */
    public function criarLead(string $nome, string $telefone, ?string $email = null, array $extra = []): array
    {
        $existente = $this->buscarLeadPorTelefone($telefone);
        if ($existente) return $existente;

        $numero = $this->normalizarTelefone($telefone);

        $body = array_merge([
            'name'  => $nome !== '' ? $nome : 'Cliente',
            'phone' => $numero ? '+' . $numero : $telefone,
        ], $extra);

        if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $body['email'] = $email;
        }

        return $this->post('/api/v1/leads', $body);
    }

    /**
     * Busca lead pelo telefone.
     */
    public function buscarLeadPorTelefone(string $telefone): ?array
    {
        $numero = $this->normalizarTelefone($telefone);
        if (!$numero) return null;

        try {
            $res = $this->get('/api/v1/leads', [
                'search' => $numero,
                'take'   => 10,
            ]);
        } catch (DataCrazyException $e) {
            return null; // busca de lead falhando não quebra o fluxo
        }

        if (empty($res['data']) || !is_array($res['data'])) return null;

        foreach ($res['data'] as $lead) {
            if ($this->mesmoNumero($lead['phone'] ?? '', $numero)) {
                return $lead;
            }
        }
        return null;
    }

    // =========================================================================
    // INSTÂNCIAS / DIAGNÓSTICO
    // =========================================================================

    public function buscarInstancias(): array
    {
        return $this->get('/api/v1/instances');
    }

    /**
     * Verifica se a configuração está válida (painel de diagnóstico).
     *
     * @return array{ok:bool, mensagem:string, instancia:?array}
     */
    public function testarConexao(): array
    {
        try {
            $res = $this->buscarInstancias();
            $instancias = $res['data'] ?? $res;
            $achou = null;
            if (is_array($instancias)) {
                foreach ($instancias as $inst) {
                    if (is_array($inst) && ($inst['id'] ?? '') === $this->instanceId) {
                        $achou = $inst;
                        break;
                    }
                }
            }
            if ($achou) {
                $ativa = $achou['isActive'] ?? true;
                return [
                    'ok'        => (bool)$ativa,
                    'mensagem'  => $ativa ? 'Conexão OK e instância ativa' : 'Instância encontrada mas INATIVA',
                    'instancia' => $achou,
                ];
            }
            return [
                'ok'        => false,
                'mensagem'  => 'API respondeu, mas a instância configurada não foi encontrada. Verifique DATACRAZY_INSTANCE_ID.',
                'instancia' => null,
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'mensagem' => $e->getMessage(), 'instancia' => null];
        }
    }

    // =========================================================================
    // NORMALIZAÇÃO DE TELEFONE
    // =========================================================================

    /**
     * Normaliza telefone para E.164 sem "+": DDI+DDD+numero.
     * Retorna null se inválido.
     */
    public function normalizarTelefone(string $telefone): ?string
    {
        $d = preg_replace('/\D/', '', $telefone);
        if ($d === '' || strlen($d) < 8) return null;

        $d = ltrim($d, '0');

        // Já tem DDI 55
        if (strlen($d) >= 12 && substr($d, 0, 2) === '55') {
            $ddd   = substr($d, 2, 2);
            $resto = substr($d, 4);
            if (!$this->dddValido($ddd)) return null;
            $resto = $this->corrigirNono($resto);
            return $this->validarFinal('55' . $ddd . $resto);
        }

        // 11 dígitos: DDD + celular com 9
        if (strlen($d) === 11) {
            $ddd   = substr($d, 0, 2);
            $resto = substr($d, 2);
            if (!$this->dddValido($ddd)) return null;
            return $this->validarFinal('55' . $ddd . $resto);
        }

        // 10 dígitos: DDD + 8 (fixo ou celular antigo)
        if (strlen($d) === 10) {
            $ddd   = substr($d, 0, 2);
            $resto = substr($d, 2);
            if (!$this->dddValido($ddd)) return null;
            $resto = $this->corrigirNono($resto);
            return $this->validarFinal('55' . $ddd . $resto);
        }

        // 8-9 dígitos: sem DDD
        if (strlen($d) >= 8 && strlen($d) <= 9) {
            if ($this->dddPadrao === '' || !$this->dddValido($this->dddPadrao)) {
                return null;
            }
            $resto = $this->corrigirNono($d);
            return $this->validarFinal('55' . $this->dddPadrao . $resto);
        }

        // Internacional não-BR
        if (strlen($d) >= 11 && strlen($d) <= 15) {
            return $d;
        }

        return null;
    }

    private function corrigirNono(string $resto): string
    {
        if (strlen($resto) === 8 && in_array($resto[0], ['6','7','8','9'], true)) {
            return '9' . $resto;
        }
        return $resto;
    }

    private function dddValido(string $ddd): bool
    {
        $n = (int)$ddd;
        return $n >= 11 && $n <= 99;
    }

    private function validarFinal(string $numero): ?string
    {
        $len = strlen($numero);
        return ($len >= 12 && $len <= 13) ? $numero : null;
    }

    /**
     * Compara dois números pelo núcleo, tolerante a:
     *   - presença/ausência do DDI 55
     *   - presença/ausência do 9º dígito
     * Reduz ambos ao "núcleo canônico" (DDD + 8 últimos dígitos) e compara.
     */
    private function mesmoNumero(string $a, string $b): bool
    {
        $a = preg_replace('/\D/', '', $a);
        $b = preg_replace('/\D/', '', $b);
        if ($a === '' || $b === '') return false;
        if ($a === $b) return true;

        $ca = $this->nucleoCanonico($a);
        $cb = $this->nucleoCanonico($b);
        return $ca !== null && $ca === $cb;
    }

    /**
     * Reduz um número a DDD(2) + núcleo(8 últimos dígitos), removendo DDI e 9º.
     * Ex: 5547991331190 -> 4731331190? Não: pega DDD 47 + últimos 8 = "31331190"
     *     resultando em "4731331190" — estável independente de DDI/9.
     */
    private function nucleoCanonico(string $d): ?string
    {
        // Remove DDI 55 se presente e o resto tiver tamanho de número nacional
        if (strlen($d) >= 12 && substr($d, 0, 2) === '55') {
            $d = substr($d, 2);
        }
        if (strlen($d) < 10) return null;          // sem DDD não dá pra canonizar
        $ddd    = substr($d, 0, 2);
        $ultimos = substr($d, -8);                 // núcleo sem o 9º dígito
        return $ddd . $ultimos;
    }

    // =========================================================================
    // PREPARO DE TEXTO
    // =========================================================================

    private function prepararTexto(string $texto): string
    {
        $texto = str_replace(["\r\n", "\r"], "\n", $texto);
        $texto = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $texto);
        $texto = trim($texto);

        if (function_exists('mb_strlen') && mb_strlen($texto, 'UTF-8') > self::MAX_MSG_LEN) {
            $texto = mb_substr($texto, 0, self::MAX_MSG_LEN - 1, 'UTF-8') . '…';
        } elseif (strlen($texto) > self::MAX_MSG_LEN) {
            $texto = substr($texto, 0, self::MAX_MSG_LEN - 1) . '…';
        }
        return $texto;
    }

    // =========================================================================
    // HTTP COM RETRY
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

        for ($tentativa = 0; $tentativa <= self::MAX_RETRIES; $tentativa++) {
            if ($tentativa > 0) {
                $ms = self::BACKOFF_MS[$tentativa - 1] ?? 1500;
                usleep($ms * 1000);
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $this->apiKey,
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }

            $resp     = curl_exec($ch);
            $code     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErrn = curl_errno($ch);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            // Erro de rede/cURL
            if ($resp === false || $curlErrn !== 0) {
                $ultimoErro = new DataCrazyException(
                    "Falha de rede ao acessar DataCrazy: {$curlErr} (errno {$curlErrn})", 0
                );
                if (in_array($curlErrn, [
                    CURLE_OPERATION_TIMEOUTED, CURLE_COULDNT_CONNECT,
                    CURLE_COULDNT_RESOLVE_HOST, CURLE_GOT_NOTHING,
                ], true)) {
                    continue;
                }
                throw $ultimoErro;
            }

            $data = json_decode($resp, true);

            if (!is_array($data)) {
                if ($code >= 500) {
                    $ultimoErro = new DataCrazyException("DataCrazy HTTP {$code} (resposta não-JSON)", $code, $resp);
                    continue;
                }
                throw new DataCrazyException(
                    "DataCrazy: resposta inválida (HTTP {$code}): " . substr((string)$resp, 0, 200),
                    $code, $resp
                );
            }

            if ($code < 400) {
                return $data;
            }

            $msg = $data['message'] ?? ($data['error'] ?? "HTTP {$code}");
            if (is_array($msg)) $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);

            if ($code === 429 || $code >= 500) {
                $ultimoErro = new DataCrazyException("DataCrazy API {$code}: {$msg}", $code, $data);
                continue;
            }

            throw new DataCrazyException("DataCrazy API {$code}: {$msg}", $code, $data);
        }

        throw $ultimoErro ?? new DataCrazyException('DataCrazy: falha após retries', 0);
    }

    // =========================================================================
    // CONFIG
    // =========================================================================

    private function getConfig(string $key, string $default = ''): string
    {
        if (defined($key)) {
            $v = constant($key);
            if (is_string($v) && $v !== '') return $v;
        }
        $val = getenv($key);
        if ($val !== false && $val !== '') return (string)$val;
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') return (string)$_ENV[$key];
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return (string)$_SERVER[$key];
        return $default;
    }

    /** Limpa o cache de conversas (workers de longa duração). */
    public static function limparCache(): void
    {
        self::$conversaCache = [];
    }
}