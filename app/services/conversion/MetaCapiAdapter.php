<?php
declare(strict_types=1);

/**
 * app/services/conversion/MetaCapiAdapter.php
 *
 * Adaptador da Meta Conversions API. Transforma um evento do
 * ledger no formato do Graph API e envia.
 *
 * SPECS (confirmadas na doc Meta 2026):
 *  - Endpoint: POST graph.facebook.com/{VER}/{DATASET_ID}/events
 *  - Auth: ?access_token={TOKEN} (token de System User)
 *  - Obrigatórios: event_name, event_time (Unix), user_data,
 *    action_source='website'
 *  - Dedup: event_id compartilhado com o pixel
 *  - PII: SHA-256 (via HashingService) — NUNCA em texto puro
 *
 * PII crua (IP, user_agent, fbc, fbp) vai SEM hash — são sinais
 * de match, não dados a proteger.
 */
final class MetaCapiAdapter implements ConversionAdapter
{
    private const API_VERSION = 'v21.0'; // conferir/atualizar periodicamente
    private const TIMEOUT     = 8;       // segundos por request

    private string $datasetId;
    private string $token;
    private ?string $testCode;

    public function __construct()
    {
        // Credenciais do .env (nunca hardcoded)
        $this->datasetId = (string)(getenv('META_DATASET_ID') ?? '');
        $this->token     = (string)(getenv('META_CAPI_TOKEN') ?? '');
        // Test Event Code: em homologação, deixa ver os eventos no
        // "Test Events" do Events Manager sem afetar dados reais.
        $this->testCode  = getenv('META_TEST_EVENT_CODE') ?: null;
    }

    public function nome(): string { return 'meta'; }

    public function estaConfigurado(): bool
    {
        return $this->datasetId !== '' && $this->token !== '';
    }

    public function requerMarketing(): bool { return true; }

    public function enviar(array $evento): ConversionResult
    {
        $payload  = $evento['payload'];          // já decodificado pelo dispatcher
        $contexto = $payload['_context'] ?? [];

        // ── user_data: PII HASHEADA + sinais crus ──
        $userData = $this->montarUserData($evento, $contexto);

        // ── custom_data: valor, moeda, produtos ──
        $customData = $this->montarCustomData($payload);

        // ── evento no formato Meta ──
        $eventoMeta = [
            'event_name'    => $evento['event_name'],
            'event_time'    => strtotime($evento['event_time']), // Unix
            'event_id'      => $evento['event_id'],               // dedup c/ pixel
            'action_source' => 'website',
            'user_data'     => $userData,
            'custom_data'   => $customData,
        ];
        // event_source_url melhora atribuição (se veio no contexto)
        if (!empty($contexto['url'])) {
            $eventoMeta['event_source_url'] = $contexto['url'];
        }

        $body = ['data' => [$eventoMeta]];
        if ($this->testCode) {
            $body['test_event_code'] = $this->testCode;
        }        

        return $this->post($body);
    }

    /** Monta user_data: hasheia PII, mantém sinais de match crus. */
    private function montarUserData(array $evento, array $contexto): array
    {
        $ud = [];

        // external_id: id do cliente, hasheado, estável por pessoa
        if (!empty($evento['cliente_id'])) {
            $ext = HashingService::externalId((int)$evento['cliente_id']);
            if ($ext) $ud['external_id'] = $ext;

            // Busca PII do cliente pra hashear (email, telefone, nome)
            $pii = $this->buscarPiiCliente((int)$evento['cliente_id']);
            if ($pii) {
                if (!empty($pii['em'])) $ud['em'] = [$pii['em']];
                if (!empty($pii['ph'])) $ud['ph'] = [$pii['ph']];
                if (!empty($pii['fn'])) $ud['fn'] = [$pii['fn']];
                if (!empty($pii['ln'])) $ud['ln'] = [$pii['ln']];
                if (!empty($pii['ct'])) $ud['ct'] = [$pii['ct']];
                if (!empty($pii['st'])) $ud['st'] = [$pii['st']];
                if (!empty($pii['zp'])) $ud['zp'] = [$pii['zp']];
                $ud['country'] = [HashingService::country('BR')];
            }
        }

        // Sinais de match CRUS (NÃO hashear): IP, user agent, fbc, fbp
        if (!empty($contexto['ip']))         $ud['client_ip_address'] = $contexto['ip'];
        if (!empty($contexto['user_agent'])) $ud['client_user_agent'] = $contexto['user_agent'];

        // fbc/fbp vêm da tabela tracking_clicks (costurados por visitante)
        $click = $this->buscarClickIds($evento['visitante_token'] ?? null);
        if (!empty($click['fbc'])) $ud['fbc'] = $click['fbc'];
        if (!empty($click['fbp'])) $ud['fbp'] = $click['fbp'];
        LogService::debug('buscarClickIds', [$click, $evento, $contexto]);
        return $ud;
    }

    private function montarCustomData(array $payload): array
    {
        $cd = [];
        foreach (['value','currency','content_ids','content_type',
                  'content_name','num_items','order_id','search_string','quantity'] as $k) {
            if (isset($payload[$k]) && $payload[$k] !== '' && $payload[$k] !== []) {
                $cd[$k] = $payload[$k];
            }
        }
        return $cd;
    }

    /** Busca e hasheia a PII do cliente (uma query, no envio). */
    private function buscarPiiCliente(int $clienteId): ?array
    {
        try {
            $db = Database::getInstance()->getConnection();
            $st = $db->prepare(
                "SELECT u.nome, u.email, c.telefone,
                        e.cidade, e.estado, e.cep
                 FROM clientes c
                 JOIN usuarios u ON u.id = c.usuario_id
                 LEFT JOIN enderecos e ON e.cliente_id = c.id AND e.principal = 1
                 WHERE c.id = ? LIMIT 1"
            );
            $st->execute([$clienteId]);
            $r = $st->fetch();
            if (!$r) return null;

            // Separa nome em primeiro/último
            $nome = trim((string)($r['nome'] ?? ''));
            $partes = preg_split('/\s+/', $nome);
            $primeiro = $partes[0] ?? '';
            $ultimo   = count($partes) > 1 ? end($partes) : '';

            return [
                'em' => HashingService::email($r['email'] ?? null),
                'ph' => HashingService::phone($r['telefone'] ?? null),
                'fn' => HashingService::name($primeiro),
                'ln' => HashingService::name($ultimo),
                'ct' => HashingService::name($r['cidade'] ?? null),
                'st' => HashingService::name($r['estado'] ?? null),
                'zp' => HashingService::zip($r['cep'] ?? null),
            ];
        } catch (\Throwable $e) {
            return null; // sem PII, envia só com o que tem
        }
    }

    /** Busca fbc/fbp mais recentes do visitante (tracking_clicks). */
    private function buscarClickIds(?string $visitanteToken): array
    {
        if (!$visitanteToken) return [];
        try {
            $db = Database::getInstance()->getConnection();
            $st = $db->prepare(
                "SELECT fbc, fbp FROM tracking_clicks
                 WHERE visitante_token = ?
                 ORDER BY id DESC LIMIT 1"
            );
            $st->execute([$visitanteToken]);
            return $st->fetch() ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** POST pro Graph API. Classifica a resposta em ok/temp/permanente. */
    private function post(array $body): ConversionResult
    {
        $url = 'https://graph.facebook.com/' . self::API_VERSION
             . '/' . $this->datasetId . '/events'
             . '?access_token=' . urlencode($this->token);

        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::TIMEOUT,
            ]);
            $resp   = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno  = curl_errno($ch);
            curl_close($ch);

            // Erro de rede/timeout → temporário (retry)
            if ($errno !== 0) {
                return ConversionResult::falhaTemporaria(null, "curl_errno {$errno}");
            }

            // 2xx → sucesso
            if ($status >= 200 && $status < 300) {
                return ConversionResult::ok($status);
            }

            // 5xx → temporário (Meta instável, retry)
            if ($status >= 500) {
                return ConversionResult::falhaTemporaria($status, mb_substr((string)$resp, 0, 500));
            }

            // 4xx → permanente (payload/token errado, não adianta repetir)
            return ConversionResult::falhaPermanente($status, mb_substr((string)$resp, 0, 500));

        } catch (\Throwable $e) {
            // Exceção inesperada → temporário (dá chance de retry)
            return ConversionResult::falhaTemporaria(null, $e->getMessage());
        }
    }
}