<?php
declare(strict_types=1);

/**
 * app/services/BlingApiClient.php
 *
 * Cliente HTTP para a Bling API v3.
 * Gerencia rate limiting (3 req/s), retry em 429 e log automático.
 */
class BlingApiClient
{
    private BlingAuthService $auth;
    private PDO              $db;

    const BASE_URL         = 'https://api.bling.com.br/Api/v3';
    const RATE_LIMIT_RPS   = 3;       // requisições por segundo
    const MAX_RETRIES      = 3;
    const RETRY_DELAY_BASE = 2;       // segundos base para backoff

    // Controle de rate limiting (estático — compartilhado entre instâncias)
    private static float $lastRequestTime = 0.0;
    private static int   $requestsInWindow = 0;

    public function __construct()
    {
        $this->auth = new BlingAuthService();
        $this->db   = Database::getInstance()->getConnection();
    }

    // ════════════════════════════════════════════════════
    // MÉTODOS HTTP PÚBLICOS
    // ════════════════════════════════════════════════════

    public function get(string $endpoint, array $params = []): array
    {
        $url = self::BASE_URL . $endpoint;
        if ($params) $url .= '?' . http_build_query($params);
        return $this->request('GET', $url, null, $endpoint);
    }

    public function post(string $endpoint, array $data): array
    {
        return $this->request('POST', self::BASE_URL . $endpoint, $data, $endpoint);
    }

    public function put(string $endpoint, array $data): array
    {
        return $this->request('PUT', self::BASE_URL . $endpoint, $data, $endpoint);
    }

    public function patch(string $endpoint, array $data): array
    {
        return $this->request('PATCH', self::BASE_URL . $endpoint, $data, $endpoint);
    }

    // ════════════════════════════════════════════════════
    // CORE
    // ════════════════════════════════════════════════════

    private function request(
        string  $method,
        string  $url,
        ?array  $body,
        string  $logRef
    ): array {
        $tentativa = 0;
        $logId     = null;

        while ($tentativa <= self::MAX_RETRIES) {
            // Rate limiting
            $this->throttle();

            $token = $this->auth->getAccessToken();

            $ch = curl_init($url);
            $headers = [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
            ];

            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_HTTPHEADER     => $headers,
            ]);

            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            }

            $responseBody = curl_exec($ch);
            $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError    = curl_error($ch);
            curl_close($ch);

            // Log da requisição (primeira tentativa cria, retry atualiza)
            $logId = $this->upsertLog($logId, $logRef, $method, $body, $httpCode, $responseBody, $curlError);

            // Sucesso
            if ($httpCode >= 200 && $httpCode < 300) {
                $json = json_decode($responseBody, true) ?? [];
                $this->updateLog($logId, 'ok', null);
                return $json['data'] ?? $json;
            }

            // Rate limited — espera e tenta de novo
            if ($httpCode === 429) {
                $delay = self::RETRY_DELAY_BASE * (2 ** $tentativa);
                sleep($delay);
                $tentativa++;
                continue;
            }

            // Servidor indisponível
            if ($httpCode >= 500 && $tentativa < self::MAX_RETRIES) {
                sleep(self::RETRY_DELAY_BASE);
                $tentativa++;
                continue;
            }

            // Erro definitivo
            $msg = "Bling API {$method} {$url} → HTTP {$httpCode}: {$responseBody}";
            $this->updateLog($logId, 'erro', $msg);
            throw new \RuntimeException($msg);
        }

        throw new \RuntimeException("Bling API: máximo de tentativas atingido para {$url}");
    }

    // ════════════════════════════════════════════════════
    // RATE LIMITING — máximo de 3 req/s
    // ════════════════════════════════════════════════════

    private function throttle(): void
    {
        $now = microtime(true);

        // Reseta janela de 1 segundo
        if ($now - self::$lastRequestTime >= 1.0) {
            self::$lastRequestTime   = $now;
            self::$requestsInWindow  = 0;
        }

        self::$requestsInWindow++;

        if (self::$requestsInWindow > self::RATE_LIMIT_RPS) {
            // Calcula quanto falta para completar 1 segundo desde a primeira req da janela
            $sleep = (int)((1.0 - ($now - self::$lastRequestTime)) * 1_000_000);
            if ($sleep > 0) usleep($sleep);
            self::$lastRequestTime  = microtime(true);
            self::$requestsInWindow = 1;
        }
    }

    // ════════════════════════════════════════════════════
    // LOG
    // ════════════════════════════════════════════════════

    private function upsertLog(
        ?int   $logId,
        string $ref,
        string $method,
        ?array $payload,
        int    $httpCode,
        string $resposta,
        string $curlErr
    ): int {
        $tipo     = $this->inferirTipo($ref);
        $direcao  = in_array($method, ['GET']) ? 'pull' : 'push';
        $status   = 'pendente';
        $resJson  = json_decode($resposta, true) ?? ['raw' => $resposta];
        $msgErro  = $curlErr ?: ($httpCode >= 400 ? "HTTP {$httpCode}" : null);

        if ($logId) {
            $this->db->prepare(
                "UPDATE bling_sync_log SET status = ?, resposta = ?, msg_erro = ? WHERE id = ?"
            )->execute([$status, json_encode($resJson), $msgErro, $logId]);
            return $logId;
        }

        $this->db->prepare(
            "INSERT INTO bling_sync_log (tipo, direcao, referencia_id, status, payload, resposta, msg_erro)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $tipo, $direcao, $ref, $status,
            json_encode($payload),
            json_encode($resJson),
            $msgErro,
        ]);
        return (int)$this->db->lastInsertId();
    }

    private function updateLog(int $logId, string $status, ?string $msgErro): void
    {
        $this->db->prepare(
            "UPDATE bling_sync_log SET status = ?, msg_erro = ? WHERE id = ?"
        )->execute([$status, $msgErro, $logId]);
    }

    private function inferirTipo(string $endpoint): string
    {
        if (str_contains($endpoint, 'pedido'))   return 'pedido';
        if (str_contains($endpoint, 'estoque'))  return 'estoque';
        if (str_contains($endpoint, 'produto'))  return 'produto';
        if (str_contains($endpoint, 'nf'))       return 'nfe';
        if (str_contains($endpoint, 'contato'))  return 'cliente';
        return 'pedido';
    }
}
