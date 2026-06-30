<?php
// app/helpers/ImageHelper.php


/* Helper para lidar com imagens de produtos, considerando SKU e fallback.

Imagem do produto
$url = ImageHelper::getPrincipal($produto['id']);

Imagem do SKU (com fallback para o produto)
$url = ImageHelper::getPrincipal($produto['id'], $sku['id']);

Item do carrinho
$url = ImageHelper::getCartItemImage($item['produto_id'], $item['sku_id']);

// Para o slider de fotos na página do produto
$imagens = ImageHelper::getAll($produto['id']);

// Filtrando pelo SKU selecionado
$imagens = ImageHelper::getAll($produto['id'], $skuId);

// Só as URLs (para JSON/JS)
$urls = ImageHelper::getUrls($produto['id']);

 */

class ImageHelper {

    private static ?PDO $db = null;

    private static function db(): PDO {
        if (!self::$db) {
            self::$db = Database::getInstance()->getConnection();
        }
        return self::$db;
    }

    /**
     * Retorna a URL da imagem principal de um produto.
     * Se informar sku_id, prioriza a imagem do SKU.
     */
    public static function getPrincipal(
        int $produtoId,
        ?int $skuId = null,
        string $fallback = ''
    ): string {
        $arquivo = null;

        // 1. Tenta imagem específica do SKU
        if ($skuId) {
            $stmt = self::db()->prepare(
                "SELECT arquivo FROM produto_imagens
                 WHERE sku_id = ? AND produto_id = ?
                 ORDER BY ordem ASC LIMIT 1"
            );
            $stmt->execute([$skuId, $produtoId]);
            $arquivo = $stmt->fetchColumn();
        }

        // 2. Fallback: imagem principal do produto
        if (!$arquivo) {
            $stmt = self::db()->prepare(
                "SELECT arquivo FROM produto_imagens
                 WHERE produto_id = ? AND principal = 1
                 LIMIT 1"
            );
            $stmt->execute([$produtoId]);
            $arquivo = $stmt->fetchColumn();
        }

        // 3. Fallback: qualquer imagem do produto
        if (!$arquivo) {
            $stmt = self::db()->prepare(
                "SELECT arquivo FROM produto_imagens
                 WHERE produto_id = ?
                 ORDER BY ordem ASC LIMIT 1"
            );
            $stmt->execute([$produtoId]);
            $arquivo = $stmt->fetchColumn();
        }

        if (!$arquivo) {
            return $fallback ?: (BASE_URL . '/assets/images/placeholder.jpg');
        }

        return UPLOAD_URL . '/products/' . $arquivo;
    }

    /**
     * Retorna todas as imagens de um produto (opcionalmente filtradas por SKU).
     */
    public static function getAll(int $produtoId, ?int $skuId = null): array {
        if ($skuId) {
            // Imagens do SKU + imagens gerais do produto
            $stmt = self::db()->prepare(
                "SELECT arquivo, principal, ordem, sku_id
                 FROM produto_imagens
                 WHERE produto_id = ?
                   AND (sku_id = ? OR sku_id IS NULL)
                 ORDER BY
                     CASE WHEN sku_id = ? THEN 0 ELSE 1 END,
                     principal DESC,
                     ordem ASC"
            );
            $stmt->execute([$produtoId, $skuId, $skuId]);
        } else {
            $stmt = self::db()->prepare(
                "SELECT arquivo, principal, ordem, sku_id
                 FROM produto_imagens
                 WHERE produto_id = ?
                 ORDER BY principal DESC, ordem ASC"
            );
            $stmt->execute([$produtoId]);
        }

        $rows = $stmt->fetchAll();

        return array_map(fn($r) => [
            'arquivo'   => $r['arquivo'],
            'url'       => UPLOAD_URL . '/products/' . $r['arquivo'],
            'principal' => (bool)$r['principal'],
            'sku_id'    => $r['sku_id'],
        ], $rows);
    }

    /**
     * Retorna as URLs de todas as imagens (só as URLs, para o JS).
     */
    public static function getUrls(int $produtoId, ?int $skuId = null): array {
        return array_column(self::getAll($produtoId, $skuId), 'url');
    }

    /**
     * Busca imagens de vários produtos em batch.
     * Retorna [ produto_id => url_principal ]
     */
    public static function getPrincipalBatch(array $produtoIds): array {
        if (empty($produtoIds)) return [];

        $placeholders = implode(',', array_fill(0, count($produtoIds), '?'));

        $stmt = self::db()->prepare(
            "SELECT produto_id, arquivo
             FROM produto_imagens
             WHERE produto_id IN ({$placeholders})
               AND principal = 1"
        );
        $stmt->execute($produtoIds);
        $rows = $stmt->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            $result[$row['produto_id']] = UPLOAD_URL . '/products/' . $row['arquivo'];
        }

        // Garante que todos os IDs têm uma entrada (mesmo que seja placeholder)
        foreach ($produtoIds as $id) {
            if (!isset($result[$id])) {
                $result[$id] = BASE_URL . '/assets/images/placeholder.jpg';
            }
        }

        return $result;
    }

    /**
     * Retorna URL da imagem de um item do carrinho (produto + SKU).
     */
    public static function getCartItemImage(int $produtoId, ?int $skuId = null): string {
        return self::getPrincipal($produtoId, $skuId);
    }
}