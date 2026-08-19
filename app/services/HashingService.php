<?php
declare(strict_types=1);

/**
 * app/services/HashingService.php
 *
 * Normaliza e faz hash SHA-256 de PII no formato EXATO que Meta CAPI
 * e Google (Enhanced Conversions / GA4 MP) exigem antes do envio.
 *
 * POR QUE ISTO IMPORTA: o hash é determinístico. Se a normalização
 * diferir da esperada por uma única letra maiúscula, o hash nunca
 * casa e o campo não contribui NADA pro match — e a plataforma não
 * avisa, só falha silenciosamente. Normalização errada = dado que
 * parece enviado mas é inútil.
 *
 * REGRAS (confirmadas na doc Meta/Google 2026):
 *  - Email: lowercase + trim → sha256
 *  - Telefone: só dígitos, COM código do país, SEM '+' → sha256
 *  - Nome/cidade/estado: lowercase + trim + sem acento/pontuação → sha256
 *  - Vazio/null: retorna null (NUNCA sha256('') — é lixo que a
 *    plataforma rejeita e derruba a qualidade)
 *
 * NÃO passar por aqui (vão CRUS, sem hash): IP, user agent, fbc,
 * fbp, gclid — são identificadores de atribuição, não PII.
 *
 * LGPD: este serviço é o ponto onde a PII vira irreversível antes
 * de sair do servidor. Raw PII NUNCA deve chegar à API externa.
 */
final class HashingService
{
    /**
     * Email: lowercase + trim + sha256.
     * Ex: " User@Email.com " → "user@email.com" → hash
     */
    public static function email(?string $email): ?string
    {
        if ($email === null) return null;
        $norm = strtolower(trim($email));
        return self::hash($norm);
    }

    /**
     * Telefone E.164 sem '+': só dígitos, com código do país.
     * Ex: "+55 (51) 99782-4826" → "5551997824826" → hash
     *
     * ATENÇÃO: o número PRECISA ter o código do país (55 no Brasil).
     * Se vier sem, o match quality despenca. Por isso o parâmetro
     * $defaultDDI adiciona 55 quando o número parece nacional.
     */
    public static function phone(?string $phone, string $defaultDDI = '55'): ?string
    {
        if ($phone === null) return null;

        // Remove tudo que não é dígito (inclusive o '+')
        $digits = preg_replace('/\D/', '', $phone);
        if ($digits === '' || $digits === null) return null;

        // Se não começa com o DDI e tem cara de número nacional
        // (10-11 dígitos = DDD + número), prefixa o código do país.
        if (!str_starts_with($digits, $defaultDDI) && strlen($digits) <= 11) {
            $digits = $defaultDDI . $digits;
        }

        // Número absurdamente curto = dado inválido, não hasheia lixo
        if (strlen($digits) < 12) return null;

        return self::hash($digits);
    }

    /**
     * Nome / cidade / estado: lowercase + trim + remove acentos e
     * pontuação (mantém só letras e espaços internos colapsados).
     * Ex: "São Paulo" → "sao paulo" → hash
     */
    public static function name(?string $value): ?string
    {
        if ($value === null) return null;

        $norm = strtolower(trim($value));
        // Remove acentos (á→a, ç→c, etc.) de forma determinística
        $norm = self::stripAccents($norm);
        // Remove tudo que não é letra ou espaço
        $norm = preg_replace('/[^a-z ]/', '', $norm);
        // Colapsa espaços múltiplos
        $norm = preg_replace('/\s+/', ' ', trim($norm));

        return self::hash($norm);
    }

    /**
     * CEP / zip: só dígitos + sha256.
     * Ex: "91770-000" → "91770000" → hash
     */
    public static function zip(?string $zip): ?string
    {
        if ($zip === null) return null;
        $digits = preg_replace('/\D/', '', $zip);
        return self::hash($digits ?? '');
    }

    /**
     * Sigla de país (2 letras ISO), lowercase.
     * Ex: "BR" → "br" → hash
     */
    public static function country(?string $country): ?string
    {
        if ($country === null) return null;
        $norm = strtolower(trim($country));
        return self::hash($norm);
    }

    /**
     * external_id (ID interno do cliente): hasheado, estável por
     * pessoa em TODOS os eventos. Aceita int ou string.
     */
    public static function externalId(int|string|null $id): ?string
    {
        if ($id === null || $id === '') return null;
        return self::hash(trim((string)$id));
    }

    // ══════════════════════════════════════════════════
    // Núcleo do hash — o guardião contra "hash de lixo"
    // ══════════════════════════════════════════════════

    /**
     * SHA-256 hex de um valor JÁ normalizado.
     * Retorna null se vazio — NUNCA hasheia string vazia, pois
     * sha256('') é um valor conhecido que a Meta rejeita como lixo.
     */
    private static function hash(string $normalized): ?string
    {
        if ($normalized === '') return null;

        // Salvaguarda: se já parece um hash SHA-256 (64 hex),
        // não hasheia de novo (hash-de-hash destrói o match).
        if (preg_match('/^[a-f0-9]{64}$/', $normalized)) {
            return $normalized;
        }

        return hash('sha256', $normalized);
    }

    /**
     * Remove acentos de forma determinística, sem depender de
     * locale/iconv (que varia entre servidores → hash inconsistente).
     */
    private static function stripAccents(string $str): string
    {
        $map = [
            'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a',
            'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
            'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
            'ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o',
            'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
            'ç'=>'c','ñ'=>'n',
        ];
        return strtr($str, $map);
    }
}