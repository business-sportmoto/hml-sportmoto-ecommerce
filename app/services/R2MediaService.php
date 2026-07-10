<?php
declare(strict_types=1);

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

/**
 * app/services/R2MediaService.php
 *
 * Camada de acesso ao Cloudflare R2 para MIDIA PUBLICA (imagens de produto).
 *
 * ARQUITETURA:
 *  - Bucket 'sportmoto-media' publico para LEITURA via custom domain
 *    (media.sportmoto.com.br), servido pela CDN da Cloudflare com cache.
 *  - ESCRITA restrita a esta credencial (R2_MEDIA_*), separada da de backup
 *    (least privilege: vazamento de uma nao compromete a outra).
 *  - Nomes de objeto com hash aleatorio -> anti-enumeracao do catalogo.
 *
 * @see OWASP A01 (Broken Access Control), A05 (Misconfiguration)
 */
final class R2MediaService
{
    private S3Client $client;
    private string $bucket;
    private string $publicBaseUrl;

    /**
     * @param array{account_id:string,access_key:string,secret_key:string,bucket:string,public_base_url:string} $cfg
     */
    public function __construct(array $cfg)
    {
        $this->bucket        = $cfg['bucket'];
        $this->publicBaseUrl = rtrim($cfg['public_base_url'], '/');

        $this->client = new S3Client([
            'version'                 => 'latest',
            'region'                  => 'auto',
            'endpoint'                => "https://{$cfg['account_id']}.r2.cloudflarestorage.com",
            'use_path_style_endpoint' => true,   // R2 exige path-style
            'credentials'             => [
                'key'    => $cfg['access_key'],
                'secret' => $cfg['secret_key'],
            ],
        ]);
    }

    /**
     * Sobe um arquivo (conteudo binario ja processado) para o R2.
     *
     * @param string $key         Caminho/nome do objeto (ex.: 'produtos/ab/abcd1234.webp')
     * @param string $content     Bytes do arquivo.
     * @param string $contentType MIME (ex.: 'image/webp').
     * @return string URL publica servida pela CDN.
     * @throws RuntimeException em falha de upload.
     */
    public function upload(string $key, string $content, string $contentType): string
    {
        try {
            $this->client->putObject([
                'Bucket'       => $this->bucket,
                'Key'          => $key,
                'Body'         => $content,
                'ContentType'  => $contentType,
                // Cache longo tambem no objeto (a Cache Rule da CF ja forca,
                // mas isto ajuda qualquer consumidor direto).
                'CacheControl' => 'public, max-age=2592000, immutable',
            ]);
        } catch (AwsException $e) {
            throw new RuntimeException('Falha ao enviar midia para R2: ' . $e->getAwsErrorMessage());
        }

        return $this->publicUrl($key);
    }

    /**
     * Remove um objeto (ex.: ao trocar imagem de produto).
     * Idempotente: nao falha se o objeto ja nao existir.
     */
    public function delete(string $key): void
    {
        try {
            $this->client->deleteObject([
                'Bucket' => $this->bucket,
                'Key'    => $key,
            ]);
        } catch (AwsException $e) {
            // Log, mas nao quebra o fluxo — objeto orfao e problema menor.
            error_log('[R2] delete falhou para ' . $e->getAwsErrorMessage());
        }
    }

    /** URL publica (via CDN) para uma key. */
    public function publicUrl(string $key): string
    {
        return $this->publicBaseUrl . '/' . ltrim($key, '/');
    }

    /**
     * Gera uma key com hash aleatorio para evitar enumeracao previsivel.
     * Estrutura: {prefixo}/{2 primeiros chars do hash}/{hash}.{ext}
     * O sub-diretorio de 2 chars distribui os objetos (evita 1 pasta gigante).
     *
     * @param string $prefix ex.: 'produtos'
     * @param string $ext    ex.: 'webp'
     */
    public static function generateKey(string $prefix, string $ext): string
    {
        $hash = bin2hex(random_bytes(16)); // 32 chars, imprevisivel
        $shard = substr($hash, 0, 2);
        $prefix = trim($prefix, '/');
        $ext = ltrim($ext, '.');
        return "{$prefix}/{$shard}/{$hash}.{$ext}";
    }
}