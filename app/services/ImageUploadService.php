<?php
declare(strict_types=1);

/**
 * app/services/ImageUploadService.php
 *
 * Serviço REUTILIZÁVEL de upload de IMAGEM para o R2. Espelha o StreamService
 * (que já existe para vídeo): uma fonte de verdade da validação, processamento
 * e limpeza — qualquer módulo injeta e usa, sem copiar o fluxo.
 *
 * Composição, não herança: o controller INJETA este service no construtor
 * (padrão do projeto), em vez de herdar um trait.
 *
 * FLUXO (imagem passa pelo servidor, diferente do vídeo):
 *   $_FILES → ImageProcessor (magic bytes + reprocessa + WebP) → R2 → URL pública
 *
 * DEPENDÊNCIAS: R2MediaService, ImageProcessor (já em app/services/).
 * Recebe o R2MediaService por injeção -> testável e desacoplado do .env.
 *
 * @see StreamService (o par para vídeo), R2MediaService, ImageProcessor
 */
final class ImageUploadService
{
    private R2MediaService $r2;
    private ImageProcessor $processor;

    public function __construct(R2MediaService $r2, ?ImageProcessor $processor = null)
    {
        $this->r2        = $r2;
        $this->processor = $processor ?? new ImageProcessor();
    }

    /**
     * Fábrica de conveniência: monta o service já com o R2MediaService
     * configurado pelo .env. Use quando não estiver injetando o R2 manualmente.
     *
     *   $img = ImageUploadService::fromEnv();
     */
    public static function fromEnv(): self
    {
        return new self(new R2MediaService([
            'account_id'      => getenv('R2_ACCOUNT_ID'),
            'access_key'      => getenv('R2_MEDIA_ACCESS_KEY'),
            'secret_key'      => getenv('R2_MEDIA_SECRET_KEY'),
            'bucket'          => getenv('R2_MEDIA_BUCKET'),
            'public_base_url' => getenv('R2_MEDIA_PUBLIC_URL'),
        ]));
    }

    /**
     * Sobe UMA imagem (um slot de $_FILES) gerando 1+ variantes WebP.
     *
     * @param array          $file    Item de $_FILES (ex.: $_FILES['imagem']).
     * @param string         $prefixo "Pasta" lógica no bucket ('produtos', 'banners'...).
     * @param array<string,int> $presets nome => largura máx. Default: 'full' 1200px.
     * @return array<string,string>|null variante => URL pública, ou null se nada enviado.
     * @throws \RuntimeException se o arquivo for inválido (capture no controller).
     */
    public function upload(array $file, string $prefixo = 'uploads', array $presets = ['full' => 1200]): ?array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null; // slot vazio -> não sobrescreve
        }

        $this->processor->validateUpload($file);                 // magic bytes + tamanho + dimensão
        $variantes = $this->processor->toWebpVariants($file['tmp_name'], $presets);

        // Todas as variantes do mesmo upload compartilham o hash base,
        // diferenciando pelo sufixo -> fácil correlacionar e limpar depois.
        $hashBase = R2MediaService::generateKey($prefixo, 'webp'); // ex.: produtos/ab/abcd.webp

        $urls = [];
        foreach ($variantes as $nome => $bytes) {
            $key = str_replace('.webp', "-{$nome}.webp", $hashBase);
            $urls[$nome] = $this->r2->upload($key, $bytes, 'image/webp');
        }
        return $urls;
    }

    /**
     * Conveniência para 1 variante só (avatar, poster...). Retorna a URL direto.
     *
     * @throws \RuntimeException
     */
    public function uploadUnica(array $file, string $prefixo = 'uploads', int $largura = 1200): ?string
    {
        $urls = $this->upload($file, $prefixo, ['img' => $largura]);
        return $urls['img'] ?? null;
    }

    /**
     * Remove uma imagem do R2 a partir da URL pública salva no banco.
     * Idempotente; ignora valores que não sejam URL do nosso bucket (legado/outro storage).
     */
    public function delete(?string $publicUrl): void
    {
        if (empty($publicUrl)) {
            return;
        }
        $base = rtrim((string) getenv('R2_MEDIA_PUBLIC_URL'), '/') . '/';
        if (!str_starts_with($publicUrl, $base)) {
            return; // não é do nosso R2 -> não mexe
        }
        $this->r2->delete(substr($publicUrl, strlen($base)));
    }

    /**
     * Remove várias variantes de uma vez (ex.: full + thumb).
     * @param array<string|null> $urls
     */
    public function deleteMany(array $urls): void
    {
        foreach ($urls as $u) {
            $this->delete($u);
        }
    }
}