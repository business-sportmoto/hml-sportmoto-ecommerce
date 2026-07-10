<?php
declare(strict_types=1);

/**
 * EXEMPLO DE INTEGRACAO — upload de imagem de produto.
 * (não é um arquivo para copiar direto; mostra como ligar as peças no teu
 *  fluxo de cadastro/edicao de produto, dentro do controller que ja existe.)
 */

use App\Services\R2MediaService;
use App\Services\ImageProcessor;

// ── 1. Instanciar o service com config do .env ────────────────────────────
$media = new R2MediaService([
    'account_id'      => env('R2_ACCOUNT_ID'),
    'access_key'      => env('R2_MEDIA_ACCESS_KEY'),
    'secret_key'      => env('R2_MEDIA_SECRET_KEY'),
    'bucket'          => env('R2_MEDIA_BUCKET'),          // sportmoto-media
    'public_base_url' => env('R2_MEDIA_PUBLIC_URL'),      // https://media.sportmoto.com.br
]);

$processor = new ImageProcessor();

// ── 2. No handler de upload (ex.: AdminProdutoController::uploadImagem) ────
try {
    $file = $_FILES['imagem'] ?? [];

    // Valida (magic bytes, tamanho, dimensao) — lanca em caso invalido
    $processor->validateUpload($file);

    // Processa em variantes WebP (reprocessamento destroi payloads embutidos)
    $variants = $processor->toWebpVariants($file['tmp_name']);

    // Sobe cada variante com key de hash aleatorio (anti-enumeracao)
    $urls = [];
    $baseHash = null;
    foreach ($variants as $name => $bytes) {
        // Mantem o mesmo hash base para full/thumb do mesmo upload,
        // diferenciando pela variante no nome:
        if ($baseHash === null) {
            $key = R2MediaService::generateKey('produtos', 'webp');
            $baseHash = $key; // guarda o padrao
        }
        // deriva a key da variante a partir da base
        $key = str_replace('.webp', "-{$name}.webp", $baseHash);
        $urls[$name] = $media->upload($key, $bytes, 'image/webp');
    }

    // $urls['full'] e $urls['thumb'] -> salvar no banco (produto_imagens)
    // Ex.: INSERT INTO produto_imagens (produto_id, url_full, url_thumb) VALUES (...)

} catch (\RuntimeException $e) {
    // Mensagem generica ao usuario; detalhe no log
    error_log('[UPLOAD] ' . $e->getMessage());
    // Session::flash('error', 'Nao foi possivel processar a imagem.');
}

/*
 * ═══════════════════════════════════════════════════════════════════════
 * BLOCO PARA ADICIONAR AO .env DO HML
 * ═══════════════════════════════════════════════════════════════════════
 *
 * R2_MEDIA_ACCESS_KEY="<access key do token hml-media-write>"
 * R2_MEDIA_SECRET_KEY="<secret key do token hml-media-write>"
 * R2_MEDIA_BUCKET="sportmoto-media"
 * R2_MEDIA_PUBLIC_URL="https://media.sportmoto.com.br"
 *
 * (R2_ACCOUNT_ID ja existe do backup — reusa o mesmo)
 * ═══════════════════════════════════════════════════════════════════════
 */