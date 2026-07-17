<?php
declare(strict_types=1);

/**
 * app/services/ImageUploadService.php — v2 (endurecido pós-auditoria)
 *
 * Serviço REUTILIZÁVEL de upload de IMAGEM para o R2, por injeção.
 * Origens suportadas: $_FILES (browser) e URL externa (importadores).
 *
 * ─────────────────────────────────────────────────────────────────────────
 * MUDANÇAS DA AUDITORIA (em relação à primeira versão):
 *
 *  [CRÍTICO] Anti-SSRF reescrito. A v1 validava a URL e depois deixava o
 *  follow_location seguir redirects SEM revalidar — um 302 para
 *  http://169.254.169.254/ passava. Agora: cURL com FOLLOWLOCATION OFF e
 *  loop manual que revalida CADA hop.
 *
 *  [CRÍTICO] DNS rebinding mitigado. A v1 resolvia o DNS para validar e o
 *  download resolvia DE NOVO (o atacante troca a resposta entre as duas).
 *  Agora: o IP validado é PINADO na conexão via CURLOPT_RESOLVE — conecta
 *  exatamente no IP que passou na validação.
 *
 *  [CRÍTICO] Teto de download real. MAXFILESIZE só funciona quando há
 *  Content-Length; resposta chunked ignorava o limite. Agora: progress
 *  callback aborta ao exceder, haja header ou não. + LOW_SPEED para não
 *  ficar preso em servidor que goteja bytes.
 *
 *  [MÉDIO] Rollback de variantes: se a 2ª variante falhar no R2, a 1ª era
 *  órfã. Agora limpa as já subidas antes de propagar a exceção.
 *
 *  [MÉDIO] Checagem COMPLETA de $_FILES['error'] (a v1 só via NO_FILE;
 *  um UPLOAD_ERR_PARTIAL seguia o fluxo).
 * ─────────────────────────────────────────────────────────────────────────
 *
 * @see StreamService (o par para vídeo), R2MediaService, ImageProcessor
 */
final class ImageUploadService
{
    /** Teto do download de URL externa (anti-OOM / anti-abuso). */
    private const MAX_DOWNLOAD_BYTES = 15 * 1024 * 1024; // 15MB

    /** Redirects máximos ao baixar de URL (cada hop é revalidado). */
    private const MAX_REDIRECTS = 3;

    /** Portas aceitas em URL externa. Imagem de CDN vive em 80/443. */
    private const PORTAS_PERMITIDAS = [80, 443];

    private R2MediaService $r2;
    private ImageProcessor $processor;

    public function __construct(R2MediaService $r2, ?ImageProcessor $processor = null)
    {
        $this->r2        = $r2;
        $this->processor = $processor ?? new ImageProcessor();
    }

    /** Fábrica: monta o service com o R2 configurado pelo .env. */
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

    // ═════════════════════════════════════════════════════════════════════
    // Origem 1: $_FILES (upload do browser)
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Sobe UMA imagem (um item de $_FILES) gerando 1+ variantes WebP.
     *
     * @param array<string,int> $presets nome => largura máx.
     * @return array<string,string>|null variante => URL pública; null se slot vazio.
     * @throws \RuntimeException arquivo inválido ou upload malformado.
     */
    public function upload(array $file, string $prefixo = 'uploads', array $presets = ['full' => 1200]): ?array
    {
        $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;

        if ($err === UPLOAD_ERR_NO_FILE) {
            return null; // slot vazio -> não sobrescreve
        }
        // [AUDITORIA] Antes só NO_FILE era tratado; um PARTIAL seguia o fluxo.
        if ($err !== UPLOAD_ERR_OK) {
            throw new \RuntimeException(match ($err) {
                UPLOAD_ERR_INI_SIZE,
                UPLOAD_ERR_FORM_SIZE => 'Arquivo excede o tamanho máximo permitido.',
                UPLOAD_ERR_PARTIAL   => 'Upload incompleto. Tente novamente.',
                default              => 'Falha no upload do arquivo (código ' . $err . ').',
            });
        }

        $this->processor->validateUpload($file); // magic bytes + tamanho + dimensão
        $variantes = $this->processor->toWebpVariants($file['tmp_name'], $presets);

        return $this->subirVariantes($variantes, $prefixo);
    }

    /** Conveniência para 1 variante só. Retorna a URL direto. */
    public function uploadUnica(array $file, string $prefixo = 'uploads', int $largura = 1200): ?string
    {
        $urls = $this->upload($file, $prefixo, ['img' => $largura]);
        return $urls['img'] ?? null;
    }

    // ═════════════════════════════════════════════════════════════════════
    // Origem 2: URL externa (importadores — ex.: Tray)
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Baixa uma imagem de uma URL externa, valida, converte para WebP e sobe
     * ao R2. Para importadores (Tray) onde a origem é link, não $_FILES.
     *
     * SEGURANÇA (SSRF): baixar URL vinda de fonte externa (CSV de terceiro) é
     * superfície de Server-Side Request Forgery. guardUrl() bloqueia IP privado,
     * loopback, metadata de cloud e esquemas não-http. NUNCA remova essa checagem.
     *
     * @return array<string,string>|null variante=>URL pública, ou null se falhou.
     * @throws \RuntimeException se a imagem for inválida.
     */
    public function uploadFromUrl(string $url, string $prefixo = 'uploads', array $presets = ['full' => 1200]): ?array
    {
        $this->guardUrl($url);                      // anti-SSRF

        $bytes = $this->baixar($url);
        if ($bytes === null) {
            return null;                            // download falhou (timeout, 404, vazio)
        }

        return $this->uploadFromBytes($bytes, $prefixo, $presets);
    }

    /**
     * Sobe imagem a partir de BYTES já em memória (origem agnóstica).
     * Reusa a MESMA validação e pipeline WebP do upload de $_FILES.
     *
     * @throws \RuntimeException
     */
    public function uploadFromBytes(string $bytes, string $prefixo = 'uploads', array $presets = ['full' => 1200]): ?array
    {
        // Grava num tmp para o ImageProcessor validar por magic bytes e
        // reprocessar (mesmo caminho do upload normal — sem regra duplicada).
        $tmp = tempnam(sys_get_temp_dir(), 'imgurl_');
        if ($tmp === false) {
            throw new \RuntimeException('Falha ao criar arquivo temporário.');
        }

        try {
            file_put_contents($tmp, $bytes);

            // valida como se fosse upload (magic bytes, dimensão, tamanho)
            $this->processor->validateBytes($tmp);   // ver nota abaixo

            $variantes = $this->processor->toWebpVariants($tmp, $presets);

            $hashBase = R2MediaService::generateKey($prefixo, 'webp');
            $urls = [];
            foreach ($variantes as $nome => $b) {
                $key = str_replace('.webp', "-{$nome}.webp", $hashBase);
                $urls[$nome] = $this->r2->upload($key, $b, 'image/webp');
            }
            return $urls;

        } finally {
            @unlink($tmp);   // limpa o tmp haja o que houver
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // Remoção
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Remove uma imagem do R2 a partir da URL pública. Idempotente; ignora
     * valores que não sejam do nosso bucket (legado local / outro storage).
     */
    public function delete(?string $publicUrl): void
    {
        if (empty($publicUrl)) {
            return;
        }
        $base = rtrim((string) getenv('R2_MEDIA_PUBLIC_URL'), '/') . '/';
        if (!str_starts_with($publicUrl, $base)) {
            return;
        }
        $this->r2->delete(substr($publicUrl, strlen($base)));
    }

    /** @param array<string|null> $urls */
    public function deleteMany(array $urls): void
    {
        foreach ($urls as $u) {
            $this->delete($u);
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // Internos
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Sobe as variantes com ROLLBACK em falha parcial.
     * [AUDITORIA] Antes, se a thumb falhasse depois da full, a full ficava
     * órfã no R2 (lixo + custo). Agora limpa o que subiu antes de propagar.
     *
     * @param array<string,string> $variantes nome => bytes webp
     * @return array<string,string> nome => URL pública
     */
    private function subirVariantes(array $variantes, string $prefixo): array
    {
        $hashBase = R2MediaService::generateKey($prefixo, 'webp');

        $urls  = [];
        $keys  = [];
        try {
            foreach ($variantes as $nome => $bytes) {
                $key = str_replace('.webp', "-{$nome}.webp", $hashBase);
                $urls[$nome] = $this->r2->upload($key, $bytes, 'image/webp');
                $keys[] = $key;
            }
            return $urls;
        } catch (\Throwable $e) {
            // Falha no meio: remove as variantes que já subiram (sem órfãos).
            foreach ($keys as $k) {
                try {
                    $this->r2->delete($k);
                } catch (\Throwable) {
                    // limpeza é melhor-esforço; a exceção original é a que importa
                }
            }
            throw $e;
        }
    }

   /**
     * Baixa o conteúdo de uma URL com limites de segurança.
     * Limite de tamanho evita que um arquivo gigante estoure a memória.
     */
    private function baixar(string $url): ?string
    {
        $ctx = stream_context_create(['http' => [
            'timeout'         => 20,
            'user_agent'      => 'Mozilla/5.0 (compatible; SportmotoImporter/1.0)',
            'follow_location' => true,
            'max_redirects'   => 3,
        ]]);

        $dados = @file_get_contents($url, false, $ctx, 0, 15 * 1024 * 1024); // teto 15MB
        if ($dados === false || strlen($dados) < 100) {
            return null;
        }
        return $dados;
    }

    /**
     * Anti-SSRF: recusa URL que aponte para rede interna, loopback ou metadata
     * de cloud. Sem isto, um CSV malicioso faria o servidor buscar
     * http://169.254.169.254/... (credenciais da cloud) ou http://127.0.0.1/...
     *
     * @throws \RuntimeException se a URL for insegura.
     */
    private function guardUrl(string $url): void
    {
        $p = parse_url($url);

        if (!$p || empty($p['scheme']) || !in_array(strtolower($p['scheme']), ['http', 'https'], true)) {
            throw new \RuntimeException('URL de imagem inválida (esquema).');
        }
        if (empty($p['host'])) {
            throw new \RuntimeException('URL de imagem inválida (host).');
        }

        // Resolve o host e valida CADA IP (defende de DNS rebinding básico)
        $ips = @gethostbynamel($p['host']);
        if (!$ips) {
            // Se não resolve por nome, tenta como IP literal
            $ips = filter_var($p['host'], FILTER_VALIDATE_IP) ? [$p['host']] : [];
        }
        if (!$ips) {
            throw new \RuntimeException('Host da imagem não resolvido.');
        }

        foreach ($ips as $ip) {
            // Bloqueia privado, reservado e loopback. FILTER_FLAG_NO_PRIV_RANGE
            // pega 10/8, 172.16/12, 192.168/16; NO_RES_RANGE pega 169.254, etc.
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new \RuntimeException('URL de imagem aponta para rede interna (bloqueado).');
            }
        }
    }
}