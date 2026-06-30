<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/helpers/IdHasher.php
//
// Encoda IDs numéricos como strings URL-safe assinadas
// com HMAC. IDs manipulados na URL são rejeitados pela
// verificação de assinatura.
//
// Uso:
//   $hash = IdHasher::encode(42, 'address');
//   $id   = IdHasher::decode($hash, 'address');  // null se inválido
//
// O parâmetro $context cria "namespaces" — um hash gerado
// para 'address' não funciona para 'cart_item' nem vice-versa.
// Isso previne IDOR cross-recurso.
// ════════════════════════════════════════════════════════

class IdHasher {

    /** Chave secreta. Em produção, vir de env/config. */
    private const SECRET_FALLBACK = 'CHANGE_ME_IN_PRODUCTION';

    /**
     * Encoda um ID numérico em string URL-safe assinada.
     */
    public static function encode(int $id, string $context = 'default'): string {
        if ($id <= 0) {
            throw new InvalidArgumentException('ID deve ser positivo.');
        }

        $key  = self::secret() . '|' . $context;
        $body = (string)$id;
        // Assinatura truncada (8 bytes = 16 hex chars). Suficiente para evitar bruteforce
        // sem deixar a URL gigante.
        $sig  = substr(hash_hmac('sha256', $body, $key), 0, 16);
        $raw  = $body . '.' . $sig;

        // URL-safe base64
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * Decoda e valida. Retorna null se hash inválido/adulterado.
     */
    public static function decode(string $hash, string $context = 'default'): ?int {
        if (empty($hash) || !preg_match('/^[A-Za-z0-9_\-]+$/', $hash)) {
            return null;
        }

        // Re-pad base64
        $padded = strtr($hash, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        $raw     = base64_decode($padded, true);
        if ($raw === false || !str_contains($raw, '.')) {
            return null;
        }

        [$body, $sig] = explode('.', $raw, 2);
        if (!ctype_digit($body) || strlen($sig) !== 16) {
            return null;
        }

        $key      = self::secret() . '|' . $context;
        $expected = substr(hash_hmac('sha256', $body, $key), 0, 16);

        // Timing-safe compare
        if (!hash_equals($expected, $sig)) {
            return null;
        }

        $id = (int)$body;
        return $id > 0 ? $id : null;
    }

    /**
     * Verifica se um hash é válido para o contexto, sem extrair o ID.
     */
    public static function isValid(string $hash, string $context = 'default'): bool {
        return self::decode($hash, $context) !== null;
    }

    private static function secret(): string {
        if (defined('APP_HASH_SECRET') && !empty(APP_HASH_SECRET)) {
            return APP_HASH_SECRET;
        }
        if (defined('APP_KEY') && !empty(APP_KEY)) {
            return APP_KEY;
        }
        return self::SECRET_FALLBACK;
    }
}