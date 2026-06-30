<?php
declare(strict_types=1);

/**
 * MalgaWebhookSignatureValidator
 *
 * Verifica assinaturas Ed25519 dos webhooks Malga (v1.1).
 *
 * Algoritmo oficial (https://docs.malga.io/documentations/webhooks/webhook1-1):
 *
 *     msg = "{X-Plug-Date}\n{raw_body}"
 *     sig = hex_to_bin(X-Plug-Signature)
 *     valido = ed25519_verify(msg, sig, public_key)
 *
 * Anti-replay: X-Plug-Date é UNIX timestamp em MILISSEGUNDOS (não segundos!).
 * Por padrão rejeitamos eventos com mais de 5 minutos de idade — recomendação
 * explícita na doc da Malga.
 *
 * CRÍTICO: usa o body BRUTO da request, antes de qualquer processamento PHP.
 * O chamador precisa fazer file_get_contents('php://input') e passar a string
 * intacta. Se rodar json_decode e depois json_encode, o JSON pode mudar
 * (ordem de chaves, espaços, etc.) e a assinatura quebra.
 *
 * Dependência: PHP 7.2+ com libsodium nativo (sodium_*). Já vem habilitado
 * por padrão no LiteSpeed lsphp82 do servidor.
 */
class MalgaWebhookSignatureValidator
{
    /** Janela máxima de aceitação do timestamp do evento (em segundos). */
    const MAX_AGE_SECONDS_PADRAO = 300; // 5 minutos

    /** Quantos bytes brutos a chave pública Ed25519 deve ter. */
    const ED25519_PUBKEY_BYTES = 32;

    /** Tamanho da assinatura Ed25519 em bytes. */
    const ED25519_SIGNATURE_BYTES = 64;

    /** Prefixo DER do PKCS#8 PublicKeyInfo pra Ed25519 (12 bytes fixos) */
    const DER_PREFIX_ED25519 = "\x30\x2a\x30\x05\x06\x03\x2b\x65\x70\x03\x21\x00";

    /** @var string Chave pública em formato bruto (32 bytes). */
    private $publicKeyRaw;

    /** @var int Janela máxima de idade aceitável, em segundos. */
    private $maxAgeSeconds;

    /**
     * @param string $publicKey Chave pública em PEM (como vem da Malga) OU raw 32 bytes
     * @param int    $maxAgeSeconds Janela de replay protection (default 5 min)
     *
     * @throws InvalidArgumentException quando a chave é inválida
     * @throws RuntimeException quando libsodium não está disponível
     */
    public function __construct(string $publicKey, int $maxAgeSeconds = self::MAX_AGE_SECONDS_PADRAO)
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            throw new RuntimeException(
                'libsodium não está disponível neste PHP. Instale ou habilite a extensão sodium.'
            );
        }
        $this->publicKeyRaw  = self::normalizarChavePublica($publicKey);
        $this->maxAgeSeconds = max(1, $maxAgeSeconds);
    }

    /**
     * Verifica uma requisição de webhook completa.
     *
     * @param string $rawBody       Body cru (de file_get_contents('php://input'))
     * @param string $signatureHex  Header X-Plug-Signature
     * @param string $plugDateMs    Header X-Plug-Date (Unix timestamp em ms, string)
     * @param int|null $agoraMs     Timestamp atual em ms (opcional, pra testes)
     *
     * @return array{valid: bool, motivo: string|null, idade_segundos: int|null}
     *
     * Não lança exceção: retorna estrutura com diagnóstico pra log estruturado.
     */
    public function verificar(string $rawBody, string $signatureHex, string $plugDateMs, ?int $agoraMs = null): array
    {
        // 1) Janela temporal (replay protection)
        if (!ctype_digit($plugDateMs)) {
            return ['valid' => false, 'motivo' => 'X-Plug-Date não numérico', 'idade_segundos' => null];
        }

        $eventoMs = (int) $plugDateMs;
        $agora    = $agoraMs ?? (int) (microtime(true) * 1000);
        $idadeSeg = (int) (($agora - $eventoMs) / 1000);

        // Permite até 30 segundos no "futuro" (drift de relógio entre servidores)
        if ($idadeSeg < -30) {
            return ['valid' => false, 'motivo' => 'X-Plug-Date no futuro além do tolerável', 'idade_segundos' => $idadeSeg];
        }
        if ($idadeSeg > $this->maxAgeSeconds) {
            return ['valid' => false, 'motivo' => 'Evento expirado (replay?)', 'idade_segundos' => $idadeSeg];
        }

        // 2) Formato da assinatura (deve ser 128 chars hex = 64 bytes)
        if (strlen($signatureHex) !== self::ED25519_SIGNATURE_BYTES * 2 || !ctype_xdigit($signatureHex)) {
            return ['valid' => false, 'motivo' => 'X-Plug-Signature mal formada', 'idade_segundos' => $idadeSeg];
        }

        $signatureBin = hex2bin($signatureHex);
        if ($signatureBin === false || strlen($signatureBin) !== self::ED25519_SIGNATURE_BYTES) {
            return ['valid' => false, 'motivo' => 'X-Plug-Signature não decodifica em 64 bytes', 'idade_segundos' => $idadeSeg];
        }

        // 3) Monta mensagem assinada exatamente como a Malga monta
        //    "{date_ms}\n{raw_body}"  (LF, não CRLF)
        $mensagem = $plugDateMs . "\n" . $rawBody;

        // 4) Verifica via libsodium (em tempo constante)
        try {
            $ok = sodium_crypto_sign_verify_detached($signatureBin, $mensagem, $this->publicKeyRaw);
        } catch (\Throwable $e) {
            return ['valid' => false, 'motivo' => 'Erro na verificação: ' . $e->getMessage(), 'idade_segundos' => $idadeSeg];
        }

        if (!$ok) {
            return ['valid' => false, 'motivo' => 'Assinatura inválida', 'idade_segundos' => $idadeSeg];
        }

        return ['valid' => true, 'motivo' => null, 'idade_segundos' => $idadeSeg];
    }

    /**
     * Converte uma chave pública Ed25519 em PEM (PKCS#8 PublicKeyInfo) pra
     * 32 bytes raw que o libsodium consome.
     *
     * Aceita também:
     *   - 32 bytes binários puros (passa direto)
     *   - 64 chars hex (32 bytes em hex)
     *
     * Estrutura do PEM Ed25519:
     *   12 bytes de header DER (algoritmo OID) + 32 bytes da chave = 44 bytes total
     *
     * Header esperado (hex): 302a300506032b6570032100
     */
    public static function normalizarChavePublica(string $chave): string
    {
        $chave = trim($chave);

        // Caso 1: já é raw binário de 32 bytes
        if (strlen($chave) === self::ED25519_PUBKEY_BYTES && !ctype_print($chave)) {
            return $chave;
        }

        // Caso 2: 64 chars hex
        if (strlen($chave) === self::ED25519_PUBKEY_BYTES * 2 && ctype_xdigit($chave)) {
            $bin = hex2bin($chave);
            if ($bin !== false) return $bin;
        }

        // Caso 3: PEM
        if (strpos($chave, '-----BEGIN') !== false) {
            $base64 = preg_replace('/-----[^-]+-----|\s+/', '', $chave);
            $der    = base64_decode($base64, true);
            if ($der === false) {
                throw new InvalidArgumentException('Chave pública PEM com base64 inválido');
            }
            if (strlen($der) !== 44) {
                throw new InvalidArgumentException(
                    'PEM Ed25519 deve decodificar em 44 bytes (12 header + 32 chave), recebido: ' . strlen($der)
                );
            }
            // Confere o header DER pra ter certeza que é Ed25519 mesmo
            if (substr($der, 0, 12) !== self::DER_PREFIX_ED25519) {
                throw new InvalidArgumentException(
                    'Header DER da chave pública não é Ed25519. Esperava ' .
                    bin2hex(self::DER_PREFIX_ED25519) . ', recebeu ' . bin2hex(substr($der, 0, 12))
                );
            }
            return substr($der, 12);
        }

        throw new InvalidArgumentException(
            'Formato de chave pública não reconhecido (esperado PEM, hex 64 ou raw 32 bytes)'
        );
    }

    /**
     * Carrega o validador a partir da configuração no banco (pgto_gateways).
     */
    public static function fromGatewayCodigo(string $codigo = 'malga'): self
    {
        $pdo = Database::getInstance()->getConnection();
        $stmt = $pdo->prepare(
            'SELECT webhook_public_key FROM pgto_gateways WHERE codigo = :c LIMIT 1'
        );
        $stmt->execute([':c' => $codigo]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || empty($row['webhook_public_key'])) {
            throw new RuntimeException(
                "Webhook do gateway '{$codigo}' não está configurado " .
                "(rode scripts/register_malga_webhook.php primeiro)"
            );
        }
        return new self($row['webhook_public_key']);
    }
}
