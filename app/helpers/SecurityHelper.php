<?php
// app/helpers/SecurityHelper.php
// Centraliza todas as operações de segurança da aplicação.

class SecurityHelper {

    // ── CSRF ──────────────────────────────────────────────────

    /**
     * Gera e armazena um token CSRF na sessão.
     * Chame no início de cada formulário.
     */
    public static function generateCsrf(): string {
        if (!Session::has(CSRF_TOKEN_NAME)) {
            Session::set(CSRF_TOKEN_NAME, bin2hex(random_bytes(32)));
        }
        return Session::get(CSRF_TOKEN_NAME);
    }

    /**
     * Valida o token CSRF recebido contra o da sessão.
     */
    public static function validateCsrf(string $token): bool {
        $stored = Session::get(CSRF_TOKEN_NAME);
        if (empty($stored) || empty($token)) {
            return false;
        }
        // hash_equals previne timing attacks
        return hash_equals($stored, $token);
    }

    /**
     * Gera o campo hidden HTML com o token CSRF.
     * Uso: <?= SecurityHelper::csrfField() ?>
     */
    public static function csrfField(): string {
        $token = self::generateCsrf();
        $name  = CSRF_TOKEN_NAME;
        return "<input type=\"hidden\" name=\"{$name}\" value=\"{$token}\">";
    }

    /**
     * Regenera o token após uso (formulários sensíveis como login, pagamento).
     */
    public static function regenerateCsrf(): void {
        Session::set(CSRF_TOKEN_NAME, bin2hex(random_bytes(32)));
    }

    // ── Sanitização e validação ───────────────────────────────

    /**
     * Remove tags HTML e escapa caracteres especiais.
     * Para dados que serão exibidos no HTML.
     */
    public static function sanitizeString(mixed $value): string {
        return htmlspecialchars(strip_tags(trim((string) $value)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Sanitiza para uso em consultas de busca (sem remover acentos).
     */
    public static function sanitizeSearch(string $value): string {
        $value = trim($value);
        $value = strip_tags($value);
        // Remove caracteres perigosos para full-text search
        $value = preg_replace('/[+\-><()\~*"@]+/', ' ', $value);
        return mb_substr($value, 0, 200);
    }

    /**
     * Sanitiza e-mail.
     */
    public static function sanitizeEmail(string $email): string {
        return strtolower(trim(filter_var($email, FILTER_SANITIZE_EMAIL)));
    }

    /**
     * Valida e-mail.
     */
    public static function validateEmail(string $email): bool {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Sanitiza inteiro.
     */
    public static function sanitizeInt(mixed $value): int {
        return (int) filter_var($value, FILTER_SANITIZE_NUMBER_INT);
    }

    /**
     * Sanitiza decimal (preço, etc.).
     */
    public static function sanitizeFloat(mixed $value): float {
        // Aceita vírgula como separador decimal (pt-BR)
        $value = str_replace(',', '.', (string) $value);
        return (float) filter_var($value, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
    }

    /**
     * Sanitiza slug (URL amigável).
     */
    public static function sanitizeSlug(string $value): string {
        return preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($value)));
    }

    /**
     * Sanitiza array recursivamente.
     */
    public static function sanitizeArray(array $data): array {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::sanitizeArray($value);
            } else {
                $data[$key] = self::sanitizeString($value);
            }
        }
        return $data;
    }

    /**
     * Valida CPF brasileiro.
     */
    public static function validateCpf(string $cpf): bool {
        $cpf = preg_replace('/[^0-9]/', '', $cpf);

        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) {
                $sum += (int) $cpf[$i] * ($t + 1 - $i);
            }
            $remainder = (10 * $sum) % 11 % 10;
            if ((int) $cpf[$t] !== $remainder) {
                return false;
            }
        }
        return true;
    }

    /**
     * Valida CEP brasileiro.
     */
    public static function validateCep(string $cep): bool {
        return (bool) preg_match('/^\d{5}-?\d{3}$/', trim($cep));
    }

    /**
     * Valida força de senha.
     * Mínimo: 8 chars, 1 maiúscula, 1 minúscula, 1 número.
     */
    public static function validatePassword(string $password): bool {
        return strlen($password) >= 8
            && preg_match('/[A-Z]/', $password)
            && preg_match('/[a-z]/', $password)
            && preg_match('/[0-9]/', $password);
    }

    // ── Rate limiting simples (por sessão) ────────────────────

    /**
     * Registra uma tentativa de ação (ex: login).
     * Retorna true se o limite foi atingido.
     */
    public static function rateLimitExceeded(string $action, int $maxAttempts = 5, int $decaySeconds = 900): bool {
        $key  = "_rl_{$action}";
        $data = Session::get($key, ['attempts' => 0, 'first_at' => time()]);

        // Reseta se o período expirou
        if ((time() - $data['first_at']) > $decaySeconds) {
            $data = ['attempts' => 0, 'first_at' => time()];
        }

        $data['attempts']++;
        Session::set($key, $data);

        return $data['attempts'] > $maxAttempts;
    }

    public static function clearRateLimit(string $action): void {
        Session::remove("_rl_{$action}");
    }

    // ── Proteção de upload ────────────────────────────────────

    /**
     * Valida se um arquivo enviado é realmente uma imagem.
     * Usa finfo para verificar o MIME type real (não apenas a extensão).
     */
    public static function validateUploadedImage(array $file): bool {
        if ($file['error'] !== UPLOAD_ERR_OK) return false;
        if ($file['size'] > UPLOAD_MAX_SIZE)   return false;

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ALLOWED_IMAGES, true)) return false;

        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        return in_array($mimeType, $allowedMimes, true);
    }

    // ── Geração de tokens ─────────────────────────────────────

    /**
     * Gera um token aleatório seguro.
     * @param int $bytes Tamanho em bytes (resultado em hex terá o dobro de chars)
     */
    public static function generateToken(int $bytes = 32): string {
        return bin2hex(random_bytes($bytes));
    }

    /**
     * Gera um código numérico para verificação por SMS/e-mail.
     */
    public static function generateNumericCode(int $digits = 6): string {
        $min = (int) str_pad('1', $digits, '0');
        $max = (int) str_pad('9', $digits, '9');
        return str_pad((string) random_int($min, $max), $digits, '0', STR_PAD_LEFT);
    }

    // ── Proteção de headers HTTP ──────────────────────────────────

    /**
     * Define cabeçalhos de segurança recomendados.
     * Chamar no bootstrap (index.php) antes de qualquer output.
     */
    public static function setSecurityHeaders(): void {
        // Previne clickjacking
        header('X-Frame-Options: SAMEORIGIN');

        // Previne MIME sniffing
        header('X-Content-Type-Options: nosniff');

        // Ativa proteção XSS do browser (legado, mas ainda útil)
        header('X-XSS-Protection: 1; mode=block');

        // Controla referrer nas navegações
        header('Referrer-Policy: strict-origin-when-cross-origin');

        // Permissões do browser (desativa APIs desnecessárias)
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

        // Content Security Policy (ajuste conforme as fontes usadas)
        if (!APP_DEBUG) {
            $baseHost = parse_url(BASE_URL, PHP_URL_HOST);
            $baseImgSrc = $baseHost ? " https://{$baseHost}" : "";

            $stream = 'https://*.cloudflarestream.com';

            header("Cross-Origin-Opener-Policy: same-origin-allow-popups");

            header(
                "Content-Security-Policy: " .
                "default-src 'self'; " .

                "script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://accounts.google.com/gsi/client; " .
                "script-src-elem 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://accounts.google.com/gsi/client https://connect.facebook.net https://device.clearsale.com.br https://sdk.mercadopago.com; " .
                
                "worker-src 'self' blob:;".
                "media-src 'self' blob: {$stream};".

                "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://accounts.google.com/gsi/style; " .                
                "font-src 'self' https://fonts.gstatic.com data:;" .
                
                "img-src 'self' data: https:" . $baseImgSrc . " https://media.sportmoto.com.br https://www.facebook.com {$stream}; " .
                "connect-src 'self' https://accounts.google.com/gsi/ https://sandbox-api.malga.io https://api.malga.io https://www.facebook.com https://connect.facebook.net https://device.clearsale.com.br https://sdk.mercadopago.com {$stream}; " .
                "frame-src 'self' https://accounts.google.com/gsi/ https://hosted-fields-sandbox.malga.io https://hosted-fields.malga.io https://iframe.cloudflarestream.com https://www.facebook.com;" .

                // --- HARDENING (faltavam) ------------------------------------------
                "object-src 'none';".        // sem plugins/Flash como vetor
                "base-uri 'self';".          // impede <base> injetado sequestrar URLs
                "form-action 'self' https://www.facebook.com;".       // impede form injetado POSTar p/ atacante
                "frame-ancestors 'none';".   // anti-clickjacking (já tinha, mantido)
                "upgrade-insecure-requests;"
            );
        }

        // HSTS (apenas em HTTPS)
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    // ── Sanitização de output ─────────────────────────────────────

    /**
     * Sanitiza string para uso em atributos HTML (além do htmlspecialchars).
     */
    public static function sanitizeHtmlAttr(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8', false);
    }

    /**
     * Sanitiza URL para uso em href/src (previne javascript:).
     */
    public static function sanitizeUrl(string $url): string {
        $url = trim($url);
        if (preg_match('/^(javascript|data|vbscript):/i', $url)) {
            return '#';
        }
        return filter_var($url, FILTER_SANITIZE_URL) ?: '#';
    }

    /**
     * Sanitiza conteúdo HTML livre (descrições de produtos, páginas).
     * Remove tags perigosas mantendo formatação básica.
     */
    public static function sanitizeHtml(string $html): string {
        // Tags permitidas para conteúdo rico
        $allowed = '<p><br><strong><em><u><ul><ol><li><h2><h3><h4><a><img><table><thead><tbody><tr><td><th><blockquote><span><div>';
        $html    = strip_tags($html, $allowed);

        // Remove atributos perigosos de event handlers
        $html = preg_replace('/\s+on\w+\s*=\s*["\'][^"\']*["\']/', '', $html);

        // Remove javascript: em hrefs/srcs
        $html = preg_replace('/\s+(href|src|action)\s*=\s*["\']?\s*javascript:[^"\'>\s]*/i', '', $html);

        return $html;
    }

    // ── Proteção contra força bruta no banco ──────────────────────

    /**
     * Rate limiting por IP (usando tabela de logs ou APCu se disponível).
     */
    public static function rateLimitByIp(string $action, int $max = 60, int $windowSeconds = 60): bool {
        $ip  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $key = "rl_{$action}_" . md5($ip);

        if (function_exists('apcu_fetch')) {
            $count = (int) apcu_fetch($key);
            if ($count >= $max) return true;
            apcu_store($key, $count + 1, $windowSeconds);
            return false;
        }

        // Fallback para sessão
        return self::rateLimitExceeded("{$action}_{$ip}", $max, $windowSeconds);
    }

    // ── Validações extras ─────────────────────────────────────────

    /**
     * Valida número de cartão de crédito (algoritmo de Luhn).
     */
    public static function validateCreditCard(string $number): bool {
        $number = preg_replace('/\D/', '', $number);
        if (strlen($number) < 13 || strlen($number) > 19) return false;

        $sum  = 0;
        $flip = false;
        for ($i = strlen($number) - 1; $i >= 0; $i--) {
            $digit = (int)$number[$i];
            if ($flip) {
                $digit *= 2;
                if ($digit > 9) $digit -= 9;
            }
            $sum  += $digit;
            $flip  = !$flip;
        }
        return $sum % 10 === 0;
    }

    /**
     * Valida CNPJ brasileiro.
     */
    public static function validateCnpj(string $cnpj): bool {
        $cnpj = preg_replace('/[^0-9]/', '', $cnpj);
        if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj)) return false;

        $calc = function(string $cnpj, int $len): int {
            $sum  = 0;
            $pos  = $len - 7;
            for ($i = $len; $i >= 1; $i--) {
                $sum += (int)$cnpj[$len - $i] * $pos--;
                if ($pos < 2) $pos = 9;
            }
            return $sum % 11 < 2 ? 0 : 11 - ($sum % 11);
        };

        return (int)$cnpj[12] === $calc($cnpj, 12)
            && (int)$cnpj[13] === $calc($cnpj, 13);
    }

    /**
     * Detecta se a requisição chegou por HTTPS, ciente de proxy.
     *
     * Ordem: sinais LOCAIS primeiro (o servidor sabe a verdade sobre
     * a conexão que ele mesmo terminou); headers de proxy só depois.
     *
     * Confiar em X-Forwarded-Proto / CF-Visitor só é seguro porque a
     * origem está fechada: o UFW libera 80/443 apenas para as faixas
     * da Cloudflare. Sem essa trava de rede, qualquer um forjaria o
     * header — mesma lição do X-Forwarded-For que já validamos com
     * teste de spoofing externo.
     */
    public static function isHttps(): bool {
        // 1. TLS terminado no próprio servidor
        $https = (string)($_SERVER['HTTPS'] ?? '');
        if ($https !== '' && strtolower($https) !== 'off') return true;
        if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443)    return true;

        // 2. Proxy à frente (Cloudflare) — ver nota de confiança acima.
        //    Em cadeia de proxies vem "https, http": o 1º é o do cliente.
        $proto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if (str_starts_with($proto, 'https')) return true;

        // Cloudflare também envia CF-Visitor: {"scheme":"https"}
        $cf = json_decode((string)($_SERVER['HTTP_CF_VISITOR'] ?? ''), true);
        if (is_array($cf) && ($cf['scheme'] ?? '') === 'https') return true;

        return false;
    }

    

    /**
     * IP real do cliente, confiável atrás do Cloudflare.
     *
     * SEGURANÇA: confia APENAS em CF-Connecting-IP, porque o tráfego
     * passa obrigatoriamente pela Cloudflare (UFW só libera IPs da CF).
     * NUNCA usa X-Forwarded-For — é forjável por qualquer cliente e
     * permitiria falsificar IP (burlar rate limit, poluir logs, evadir
     * bloqueios). REMOTE_ADDR é o fallback (será o IP da CF, mas é real).
     */
    public static function clientIp(): string
    {
        // Cloudflare: só a CF consegue setar este header, e todo
        // tráfego passa por ela → confiável.
        $cf = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
        if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) {
            return $cf;
        }

        // Fallback: REMOTE_ADDR. Atrás da CF, será o IP da CF —
        // mas é um IP REAL (não forjável), então é seguro como base.
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($remote !== '' && filter_var($remote, FILTER_VALIDATE_IP)) {
            return $remote;
        }

        return '0.0.0.0';
    }
}