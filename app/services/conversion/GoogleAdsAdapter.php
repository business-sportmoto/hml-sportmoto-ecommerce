<?php
declare(strict_types=1);

/**
 * app/services/conversion/GoogleAdsAdapter.php
 *
 * Adaptador do Google Ads via DATA MANAGER API (a nova API unificada,
 * dez/2025 — a UploadClickConversion antiga foi descontinuada pra
 * tokens novos em jun/2026).
 *
 * Implementa a MESMA interface ConversionAdapter — o dispatcher já
 * sabe lidar com ele (retry, dead_letter, consentimento). Adicionar
 * o Google foi só criar esta classe + registrar no dispatcher.
 *
 * ⚠️⚠️ AVISO IMPORTANTE ⚠️⚠️
 * A Data Manager API é NOVA e muda de versão rápido (v1.0→v1.7 em
 * meses). Os NOMES EXATOS DE CAMPO e o PATH do endpoint MUDAM entre
 * versões. Os pontos marcados 🔶 VALIDAR são a ESTRUTURA correta,
 * mas você DEVE confirmar os nomes de campo exatos na referência
 * oficial da versão que usar:
 * https://developers.google.com/data-manager (referência atual)
 *
 * O que NÃO muda (e está certo aqui): a autenticação (JWT via
 * GoogleAuthService), o hash SHA-256 da PII, o gclid como
 * identificador, a classificação de erro. O que MUDA: os nomes
 * exatos dentro do payload JSON.
 */
final class GoogleAdsAdapter implements ConversionAdapter
{
    private const TIMEOUT = 10;

    // 🔶 VALIDAR: o endpoint e a versão. A base é o Data Manager API.
    // Confirme o path/versão atual na doc (muda entre v1.x).
    private const ENDPOINT = 'https://datamanager.googleapis.com/v1/events:ingest';

    private GoogleAuthService $auth;
    private string $conversionActionId;
    private ?string $customerId;

    public function __construct()
    {
        $this->auth = new GoogleAuthService();
        // IDs do .env — configurados no Google Ads
        $this->conversionActionId = (string)(getenv('GOOGLE_CONVERSION_ACTION_ID') ?? '');
        $this->customerId = getenv('GOOGLE_ADS_CUSTOMER_ID') ?: null;
    }

    public function nome(): string { return 'google_ads'; }

    public function estaConfigurado(): bool
    {
        return $this->auth->estaConfigurado()
            && $this->conversionActionId !== ''
            && $this->customerId !== null;
    }

    public function requerMarketing(): bool { return true; }

    public function enviar(array $evento): ConversionResult
    {
        // Google só recebe conversões que importam pra Ads — mapeamos
        // os eventos relevantes. Purchase é o principal; outros são
        // opcionais (dependem das conversion actions que você criar).
        $conversao = $this->mapearEvento($evento['event_name']);
        if ($conversao === null) {
            // Evento que não mapeia pra uma conversão do Google → skip
            // (não é erro; só não interessa ao Google Ads)
            return ConversionResult::ok(200);
        }

        $token = $this->auth->getAccessToken();
        if (!$token) {
            return ConversionResult::falhaTemporaria(null, 'sem access token');
        }

        $payload = $this->montarPayload($evento);
        return $this->post($token, $payload);
    }

    /**
     * Mapeia o nome do evento interno pra uma conversão do Google.
     * Retorna null se o evento não deve ir pro Google Ads.
     *
     * 🔶 VALIDAR: quais eventos você quer no Google. Purchase é o
     * principal. Os outros dependem de você criar conversion actions
     * correspondentes no Google Ads.
     */
    private function mapearEvento(string $eventName): ?string
    {
        // Por ora, só Purchase (a conversão que mais importa).
        // Pra adicionar outros, crie a conversion action no Google Ads
        // e mapeie aqui.
        $mapa = [
            'Purchase' => $this->conversionActionId,
            // 'AddToCart' => getenv('GOOGLE_CONV_ADDTOCART'), // se criar
        ];
        return $mapa[$eventName] ?? null;
    }

    /**
     * Monta o payload da Data Manager API.
     *
     * 🔶🔶 VALIDAR TUDO AQUI 🔶🔶 — esta é a ESTRUTURA (o que enviar:
     * gclid, PII hasheada, valor, tempo, conversion action). Mas os
     * NOMES EXATOS das chaves JSON MUDAM entre versões da API.
     * Confirme cada nome na referência oficial da sua versão.
     *
     * A estrutura conceitual (sempre necessária):
     *  - identificador do clique: gclid (e/ou gbraid/wbraid p/ iOS)
     *  - dados do usuário hasheados (Enhanced Conversions): email, tel, nome
     *  - a conversion action (qual conversão é)
     *  - valor e moeda
     *  - timestamp da conversão
     *  - order_id (dedup — o mesmo que o Pixel/gtag usa)
     */
    private function montarPayload(array $evento): array
    {
        $payload  = $evento['payload'];
        $contexto = $payload['_context'] ?? [];

        // gclid do visitante (da tracking_clicks — já capturado!)
        $gclid = $this->buscarGclid($evento['visitante_token'] ?? null);

        // PII hasheada (Enhanced Conversions) — mesmo HashingService do Meta
        $userData = $this->montarUserDataHasheada((int)($evento['cliente_id'] ?? 0));

        // 🔶 VALIDAR os nomes de campo abaixo na doc oficial atual.
        // Esta é a forma CONCEITUAL correta (v1.x — confirme os nomes):
        return [
            'destinations' => [[
                // 🔶 o formato do "destination" (customer/conversion action)
                'reference'          => 'sportmoto',
                'loginAccount'       => ['product' => 'GOOGLE_ADS', 'accountId' => $this->customerId],
                'operatingAccount'   => ['product' => 'GOOGLE_ADS', 'accountId' => $this->customerId],
            ]],
            'events' => [[
                // 🔶 nomes dos campos do evento — VALIDAR na doc
                'conversionAction'  => $this->conversionActionId,
                'transactionId'     => (string)($payload['order_id'] ?? ''), // dedup
                'eventTimestamp'    => date('c', strtotime($evento['event_time'])), // ISO 8601
                'currency'          => $payload['currency'] ?? 'BRL',
                'conversionValue'   => (float)($payload['value'] ?? 0),
                // Identificador do clique
                'adIdentifiers'     => array_filter([
                    'gclid' => $gclid,
                ]),
                // Enhanced Conversions (PII hasheada)
                'userData'          => $userData,
                // Sinais crus úteis
                'userAgent'         => $contexto['user_agent'] ?? null,
            ]],
            // Consentimento (Consent Mode) — 🔶 VALIDAR formato
            'consent' => [
                'adUserData'      => 'CONSENT_GRANTED', // já filtrado pelo dispatcher
                'adPersonalization' => 'CONSENT_GRANTED',
            ],
        ];
    }

    /** PII hasheada — reusa o HashingService (formato Google = mesmo SHA-256). */
    private function montarUserDataHasheada(int $clienteId): array
    {
        if ($clienteId <= 0) return [];
        try {
            $db = Database::getInstance()->getConnection();
            $st = $db->prepare(
                "SELECT u.nome, u.email, c.telefone
                 FROM clientes c JOIN usuarios u ON u.id = c.usuario_id
                 WHERE c.id = ? LIMIT 1"
            );
            $st->execute([$clienteId]);
            $r = $st->fetch();
            if (!$r) return [];

            $nome = trim((string)($r['nome'] ?? ''));
            $partes = preg_split('/\s+/', $nome);

            // 🔶 VALIDAR os nomes de campo (emailAddress? hashedEmail?)
            // A estrutura de Enhanced Conversions: identificadores
            // hasheados. Nomes exatos variam por versão.
            return array_filter([
                'hashedEmail'       => HashingService::email($r['email'] ?? null),
                'hashedPhoneNumber' => HashingService::phone($r['telefone'] ?? null),
                'hashedFirstName'   => HashingService::name($partes[0] ?? ''),
                'hashedLastName'    => HashingService::name(count($partes) > 1 ? end($partes) : ''),
            ]);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Busca o gclid do visitante (tracking_clicks — já capturado). */
    private function buscarGclid(?string $visitanteToken): ?string
    {
        if (!$visitanteToken) return null;
        try {
            $db = Database::getInstance()->getConnection();
            $st = $db->prepare(
                "SELECT gclid FROM tracking_clicks
                 WHERE visitante_token = ? AND gclid IS NOT NULL
                 ORDER BY id DESC LIMIT 1"
            );
            $st->execute([$visitanteToken]);
            $v = $st->fetchColumn();
            return $v !== false ? $v : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** POST pra Data Manager API. Classifica erro igual o Meta. */
    private function post(string $token, array $payload): ConversionResult
    {
        try {
            $ch = curl_init(self::ENDPOINT);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $token,
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::TIMEOUT,
            ]);
            $resp   = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno  = curl_errno($ch);
            curl_close($ch);

            if ($errno !== 0) {
                return ConversionResult::falhaTemporaria(null, "curl_errno {$errno}");
            }
            if ($status >= 200 && $status < 300) {
                return ConversionResult::ok($status);
            }
            if ($status >= 500) {
                return ConversionResult::falhaTemporaria($status, mb_substr((string)$resp, 0, 500));
            }
            // 4xx: erro permanente. USER_IDENTIFIER_INVALID = PII não
            // hasheada (mas nós hasheamos, então não deve ocorrer).
            return ConversionResult::falhaPermanente($status, mb_substr((string)$resp, 0, 500));

        } catch (\Throwable $e) {
            return ConversionResult::falhaTemporaria(null, $e->getMessage());
        }
    }
}