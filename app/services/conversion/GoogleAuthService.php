<?php
declare(strict_types=1);

/**
 * app/services/conversion/GoogleAuthService.php
 *
 * Autenticação server-to-server pra Data Manager API do Google.
 * Fluxo: JWT assinado pela chave da service account → troca por
 * access token OAuth 2.0 (escopo datamanager) → usa o token na API.
 *
 * PRÉ-REQUISITOS (você configura no Google Cloud):
 *  1. Um Google Cloud project
 *  2. Uma service account nesse project, com uma chave JSON baixada
 *  3. ⚠️ CONCEDER acesso da service account à conta do Google Ads:
 *     Google Ads → Ferramentas → Acesso e segurança → adicionar o
 *     e-mail da service account como usuário. ISSO NÃO É AUTOMÁTICO
 *     e é o passo que todo mundo esquece.
 *
 * O access token dura ~1h; cacheamos em memória durante a execução
 * do cron (um cron processa um lote e morre, então não precisa de
 * cache persistente — mas se quiser, dá pra cachear no Valkey).
 */
final class GoogleAuthService
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const SCOPE     = 'https://www.googleapis.com/auth/datamanager';
    private const TIMEOUT   = 8;

    private ?string $tokenCache = null;
    private int $tokenExpira = 0;

    /** Caminho pro JSON da service account (do .env). */
    private string $keyFilePath;

    public function __construct()
    {
        // O JSON da service account fica FORA do webroot, caminho no .env
        $this->keyFilePath = (string)(getenv('GOOGLE_SA_KEY_PATH') ?? '');
    }

    public function estaConfigurado(): bool
    {
        return $this->keyFilePath !== '' && is_readable($this->keyFilePath);
    }

    /**
     * Retorna um access token válido (gera/renova se necessário).
     * Null se falhar (o adapter trata como não-configurado/erro).
     */
    public function getAccessToken(): ?string
    {
        // Cache em memória (dentro da mesma execução)
        if ($this->tokenCache !== null && time() < $this->tokenExpira - 60) {
            return $this->tokenCache;
        }

        try {
            $sa = $this->lerServiceAccount();
            if (!$sa) return null;

            $jwt = $this->montarJwt($sa);
            $token = $this->trocarJwtPorToken($jwt);

            if ($token) {
                $this->tokenCache  = $token;
                $this->tokenExpira = time() + 3600; // ~1h
            }
            return $token;

        } catch (\Throwable $e) {
            error_log('[GoogleAuth] ' . $e->getMessage());
            return null;
        }
    }

    /** Lê e valida o JSON da service account. */
    private function lerServiceAccount(): ?array
    {
        if (!is_readable($this->keyFilePath)) return null;
        $raw = file_get_contents($this->keyFilePath);
        $sa = json_decode($raw ?: '', true);

        // Campos essenciais do JSON de service account
        if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) {
            error_log('[GoogleAuth] JSON de service account inválido');
            return null;
        }
        return $sa;
    }

    /**
     * Monta o JWT assinado (RS256) que o Google troca por token.
     * Header + claims + assinatura com a private_key da service account.
     */
    private function montarJwt(array $sa): string
    {
        $agora = time();

        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss'   => $sa['client_email'],
            'scope' => self::SCOPE,
            'aud'   => self::TOKEN_URL,
            'iat'   => $agora,
            'exp'   => $agora + 3600,
        ];

        $segmentos = [
            $this->base64UrlEncode(json_encode($header)),
            $this->base64UrlEncode(json_encode($claims)),
        ];
        $conteudo = implode('.', $segmentos);

        // Assina com RS256 (a private_key vem do JSON)
        $assinatura = '';
        $ok = openssl_sign(
            $conteudo,
            $assinatura,
            $sa['private_key'],
            OPENSSL_ALGO_SHA256
        );
        if (!$ok) {
            throw new \RuntimeException('Falha ao assinar JWT');
        }

        return $conteudo . '.' . $this->base64UrlEncode($assinatura);
    }

    /** Troca o JWT pelo access token no endpoint OAuth do Google. */
    private function trocarJwtPorToken(string $jwt): ?string
    {
        $post = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]);

        $ch = curl_init(self::TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
        ]);
        $resp   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status < 200 || $status >= 300) {
            error_log('[GoogleAuth] token HTTP ' . $status . ': ' . mb_substr((string)$resp, 0, 300));
            return null;
        }

        $data = json_decode($resp ?: '', true);
        return $data['access_token'] ?? null;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}