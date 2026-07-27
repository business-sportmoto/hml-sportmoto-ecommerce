<?php
declare(strict_types=1);

/**
 * app/services/BlingAuthService.php
 *
 * Gerencia o ciclo de vida dos tokens OAuth 2.0 da Bling API v3.
 * Um único registro na tabela bling_tokens (id=1).
 */
class BlingAuthService
{
    private PDO $db;

    const AUTH_URL  = 'https://www.bling.com.br/b/Api/v3/oauth/authorize';
    const TOKEN_URL = 'https://www.bling.com.br/b/Api/v3/oauth/token';

    // Renova o token 5 minutos antes de expirar
    const REFRESH_MARGIN_SECONDS = 300;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Retorna o client_secret configurado — usado para validar a
     * assinatura HMAC dos webhooks (X-Bling-Signature-256), que o
     * Bling assina com essa mesma credencial.
     */
    public function getClientSecret(): string
    {
        $creds = $this->getCredenciais();
        return (string)($creds['client_secret'] ?? '');
    }

    // ════════════════════════════════════════════════════
    // CONFIGURAÇÃO — salva client_id e client_secret
    // ════════════════════════════════════════════════════

    public function salvarCredenciais(string $clientId, string $clientSecret): void
    {
        $existe = $this->db->query("SELECT COUNT(*) FROM bling_tokens")->fetchColumn();

        if ($existe) {
            $this->db->prepare(
                "UPDATE bling_tokens SET client_id = ?, client_secret = ? WHERE id = 1"
            )->execute([$clientId, $clientSecret]);
        } else {
            $this->db->prepare(
                "INSERT INTO bling_tokens (client_id, client_secret, access_token, refresh_token, expires_at)
                 VALUES (?, ?, '', '', NOW())"
            )->execute([$clientId, $clientSecret]);
        }
    }

    // ════════════════════════════════════════════════════
    // PASSO 1 — URL de autorização
    // ════════════════════════════════════════════════════

    public function getAuthUrl(): string
    {
        $creds = $this->getCredenciais();
        if (!$creds) {
            throw new \RuntimeException('Credenciais Bling não configuradas.');
        }

        $state = bin2hex(random_bytes(16));
        Session::set('bling_oauth_state', $state);

        return self::AUTH_URL . '?' . http_build_query([
            'response_type' => 'code',
            'client_id'     => $creds['client_id'],
            'state'         => $state,
        ]);
    }

    // ════════════════════════════════════════════════════
    // PASSO 2 — Troca o code pelos tokens
    // ════════════════════════════════════════════════════

    public function exchangeCode(string $code, string $state): bool
    {
        // Valida state para prevenir CSRF
        $savedState = Session::get('bling_oauth_state');
        Session::remove('bling_oauth_state');
        if (!$savedState || $savedState !== $state) {
            throw new \RuntimeException('State OAuth inválido.');
        }

        $creds = $this->getCredenciais();
        if (!$creds) throw new \RuntimeException('Credenciais não configuradas.');

        $response = $this->httpPostComBasicAuth(
            self::TOKEN_URL,
            ['grant_type' => 'authorization_code', 'code' => $code],
            $creds['client_id'],
            $creds['client_secret']
        );

        $this->salvarTokens(
            $response['access_token'],
            $response['refresh_token'],
            (int)$response['expires_in']
        );

        return true;
    }

    // ════════════════════════════════════════════════════
    // TOKEN VÁLIDO (com auto-refresh)
    // ════════════════════════════════════════════════════

    public function getAccessToken(): string
    {
        $token = $this->db->query("SELECT * FROM bling_tokens WHERE id = 1 LIMIT 1")->fetch();
        if (!$token || empty($token['access_token'])) {
            throw new \RuntimeException('Bling não autorizado. Conecte a conta no painel.');
        }

        // Verifica se precisa renovar
        $expiresAt = strtotime($token['expires_at']);
        if (time() >= $expiresAt - self::REFRESH_MARGIN_SECONDS) {
            $this->refresh($token['refresh_token'], $token['client_id'], $token['client_secret']);
            $token = $this->db->query("SELECT access_token FROM bling_tokens WHERE id = 1")->fetch();
        }

        return $token['access_token'];
    }

    public function estaConectado(): bool
    {
        $token = $this->db->query(
            "SELECT access_token FROM bling_tokens WHERE id = 1 LIMIT 1"
        )->fetch();
        return !empty($token['access_token']);
    }

    public function desconectar(): void
    {
        $this->db->query(
            "UPDATE bling_tokens SET access_token = '', refresh_token = '',
             expires_at = NOW() WHERE id = 1"
        );
    }

    // ════════════════════════════════════════════════════
    // PRIVADOS
    // ════════════════════════════════════════════════════

    private function refresh(string $refreshToken, string $clientId, string $clientSecret): void
    {
        try {
            $response = $this->httpPostComBasicAuth(
                self::TOKEN_URL,
                ['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken],
                $clientId,
                $clientSecret
            );
            $this->salvarTokens(
                $response['access_token'],
                $response['refresh_token'],
                (int)$response['expires_in']
            );
        } catch (\Throwable $e) {
            $this->desconectar();
            throw new \RuntimeException('Sessão Bling expirada. Reconecte no painel. ' . $e->getMessage());
        }
    }

    private function salvarTokens(string $access, string $refresh, int $expiresIn): void
    {
        $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);
        $this->db->prepare(
            "UPDATE bling_tokens SET access_token = ?, refresh_token = ?, expires_at = ? WHERE id = 1"
        )->execute([$access, $refresh, $expiresAt]);
    }

    private function getCredenciais(): ?array
    {
        return $this->db->query(
            "SELECT client_id, client_secret FROM bling_tokens WHERE id = 1 LIMIT 1"
        )->fetch() ?: null;
    }

    /**
     * POST com credenciais no header Authorization: Basic
     * Bling v3 exige este formato — client_id/secret NÃO vão no body.
     */
    private function httpPostComBasicAuth(
        string $url,
        array  $data,
        string $clientId,
        string $clientSecret
    ): array {
        $credentials = base64_encode($clientId . ':' . $clientSecret);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
                'Authorization: Basic ' . $credentials,
            ],
        ]);
        $body = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode($body, true);
        if ($http !== 200 || empty($json['access_token'])) {
            throw new \RuntimeException("Bling OAuth falhou ({$http}): {$body}");
        }
        return $json;
    }
}