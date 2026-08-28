<?php
declare(strict_types=1);

/**
 * app/services/payment/PagamentoCriptoService.php
 *
 * Cifragem das credenciais de adquirente guardadas em pgto_gateways.
 *
 * AES-256-GCM. Formato do blob: base64( iv(12) . tag(16) . cifrado ), o mesmo
 * do IACriptoService — GCM porque é autenticado: um blob adulterado falha na
 * verificação da tag em vez de decifrar em lixo silencioso.
 *
 * POR QUE UMA CHAVE PRÓPRIA (PGTO_CRYPTO_KEY) E NÃO A DA IA:
 *   São domínios independentes com ciclos de vida diferentes. Compartilhar a
 *   chave significa que girar o segredo da Central de IA derrubaria o
 *   pagamento junto — e ninguém lembraria disso no dia.
 *
 *   Se PGTO_CRYPTO_KEY não estiver definida, cai em IA_CRYPTO_KEY para não
 *   travar a instalação, mas registra um aviso: é estado de transição, não
 *   configuração final.
 *
 * Gerar a chave:
 *   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
 * e guardar no .env como PGTO_CRYPTO_KEY (64 caracteres hexadecimais).
 *
 * PERDER A CHAVE = PERDER AS CREDENCIAIS. Elas não são recuperáveis a partir
 * do banco; o caminho é recadastrar no painel da adquirente.
 */
class PagamentoCriptoService
{
    private const CIFRA     = 'aes-256-gcm';
    private const IV_BYTES  = 12;
    private const TAG_BYTES = 16;

    public static function disponivel(): bool
    {
        try {
            self::chave();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function cifrar(string $textoClaro): string
    {
        $iv  = random_bytes(self::IV_BYTES);
        $tag = '';

        $cifrado = openssl_encrypt(
            $textoClaro, self::CIFRA, self::chave(), OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_BYTES
        );

        if ($cifrado === false) {
            throw new RuntimeException('PagamentoCriptoService: falha ao cifrar.');
        }

        return base64_encode($iv . $tag . $cifrado);
    }

    /**
     * Decifra um blob gerado por cifrar().
     *
     * Devolve null — nunca lança — quando o valor não é um blob nosso. Isso é
     * o que permite conviver com credencial em texto puro gravada antes desta
     * camada existir: quem chama trata null como "use o valor como está".
     */
    public static function decifrar(?string $blob): ?string
    {
        if ($blob === null || $blob === '') return null;

        $bin = base64_decode($blob, true);
        if ($bin === false || strlen($bin) <= self::IV_BYTES + self::TAG_BYTES) {
            return null;
        }

        $iv      = substr($bin, 0, self::IV_BYTES);
        $tag     = substr($bin, self::IV_BYTES, self::TAG_BYTES);
        $cifrado = substr($bin, self::IV_BYTES + self::TAG_BYTES);

        try {
            $claro = openssl_decrypt($cifrado, self::CIFRA, self::chave(), OPENSSL_RAW_DATA, $iv, $tag);
        } catch (\Throwable) {
            return null;
        }

        return $claro === false ? null : $claro;
    }

    /** Últimos 4 caracteres, para conferir no painel sem exibir o segredo. */
    public static function last4(string $segredo): string
    {
        return $segredo === '' ? '' : str_repeat('•', 4) . mb_substr($segredo, -4);
    }

    private static function chave(): string
    {
        $hex = self::env('PGTO_CRYPTO_KEY');

        if ($hex === '') {
            $hex = self::env('IA_CRYPTO_KEY');
            if ($hex !== '') {
                LogService::warning(
                    'PGTO_CRYPTO_KEY ausente — usando IA_CRYPTO_KEY como transicao',
                    ['acao' => 'cripto_pagamento'], 'pagamento'
                );
            }
        }

        if ($hex === '') {
            throw new RuntimeException(
                'PGTO_CRYPTO_KEY nao definida. Gere com bin2hex(random_bytes(32)) '
                . 'e adicione ao .env (64 caracteres hexadecimais).'
            );
        }

        $bin = @hex2bin($hex);
        if ($bin === false || strlen($bin) !== 32) {
            throw new RuntimeException('PGTO_CRYPTO_KEY invalida: esperados 64 caracteres hexadecimais.');
        }

        return $bin;
    }

    private static function env(string $chave): string
    {
        $v = getenv($chave);
        if ($v !== false && $v !== '') return (string) $v;
        if (!empty($_ENV[$chave]))    return (string) $_ENV[$chave];
        if (!empty($_SERVER[$chave])) return (string) $_SERVER[$chave];
        if (defined($chave) && is_string(constant($chave)) && constant($chave) !== '') {
            return (string) constant($chave);
        }
        return '';
    }
}
