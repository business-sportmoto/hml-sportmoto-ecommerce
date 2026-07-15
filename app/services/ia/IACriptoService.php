<?php
/**
 * IACriptoService — cifragem de segredos do módulo Central de Marketing IA.
 *
 * AES-256-GCM via OpenSSL. Formato do blob: base64( iv(12) . tag(16) . cifrado ).
 * A chave mestra vive na constante IA_CRYPTO_KEY (64 caracteres hexadecimais),
 * definida no config.php — NUNCA versionar nem expor no webroot.
 *
 * Gerar a chave:
 *   /usr/local/lsws/lsphp82/bin/php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
 */
class IACriptoService
{
    private const CIFRA     = 'aes-256-gcm';
    private const IV_BYTES  = 12;
    private const TAG_BYTES = 16;

    /**
     * Cifra um texto claro. Lança RuntimeException se a chave mestra estiver ausente/ inválida.
     */
    public static function cifrar(string $textoClaro): string
    {
        $iv  = random_bytes(self::IV_BYTES);
        $tag = '';

        $cifrado = openssl_encrypt(
            $textoClaro,
            self::CIFRA,
            self::chave(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_BYTES
        );

        if ($cifrado === false) {
            throw new RuntimeException('IACriptoService: falha ao cifrar o valor.');
        }

        return base64_encode($iv . $tag . $cifrado);
    }

    /**
     * Decifra um blob gerado por cifrar(). Retorna null para blob vazio,
     * malformado ou com tag inválida (nunca lança por dado corrompido).
     */
    public static function decifrar(?string $blob): ?string
    {
        if ($blob === null || $blob === '') {
            return null;
        }

        $bruto = base64_decode($blob, true);
        if ($bruto === false || strlen($bruto) <= self::IV_BYTES + self::TAG_BYTES) {
            return null;
        }

        $iv      = substr($bruto, 0, self::IV_BYTES);
        $tag     = substr($bruto, self::IV_BYTES, self::TAG_BYTES);
        $cifrado = substr($bruto, self::IV_BYTES + self::TAG_BYTES);

        $claro = openssl_decrypt($cifrado, self::CIFRA, self::chave(), OPENSSL_RAW_DATA, $iv, $tag);

        return ($claro === false) ? null : $claro;
    }

    /**
     * Últimos 4 caracteres para exibição mascarada na UI.
     */
    public static function last4(string $segredo): string
    {
        $limpo = trim($segredo);
        if ($limpo === '') {
            return '';
        }
        return (strlen($limpo) >= 4) ? substr($limpo, -4) : str_pad($limpo, 4, '*', STR_PAD_LEFT);
    }

    /**
     * Resolve e valida a chave mestra (32 bytes a partir de 64 chars hex).
     */
    private static function chave(): string
    {
        if (!defined('IA_CRYPTO_KEY') || !is_string(IA_CRYPTO_KEY) || IA_CRYPTO_KEY === '') {
            throw new RuntimeException(
                'IA_CRYPTO_KEY nao definida. Adicione ao config.php: ' .
                "define('IA_CRYPTO_KEY', '<64 hex>'); — gere com bin2hex(random_bytes(32))."
            );
        }

        $bin = @hex2bin(IA_CRYPTO_KEY);
        if ($bin === false || strlen($bin) !== 32) {
            throw new RuntimeException('IA_CRYPTO_KEY invalida: esperados 64 caracteres hexadecimais (32 bytes).');
        }

        return $bin;
    }
}
