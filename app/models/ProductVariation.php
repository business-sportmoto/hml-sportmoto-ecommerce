<?php
// app/models/ProductVariation.php

class ProductVariation extends Model {

    /**
     * Retorna todos os dados de variação e agrupamento para a
     * página do produto. Query única, sem N+1.
     */
    public function getProductData(int $productId): array {

    // ── 1. Produto + família ──────────────────────────────
    $stmt = $this->db->prepare(
        "SELECT p.*,
                f.id   AS familia_id,
                f.nome AS familia_nome,
                f.slug AS familia_slug
         FROM produtos p
         LEFT JOIN familia_produtos f ON f.id = p.familia_id
         WHERE p.id = ? AND p.deleted_at IS NULL
         LIMIT 1"
    );
    $stmt->execute([$productId]);
    $produto = $stmt->fetch();
    if (!$produto) return [];

    // ── 2. Atributos agrupadores do produto atual ─────────
    $stmt = $this->db->prepare(
        "SELECT at.id, at.nome, at.slug, at.tipo_display,
                paa.valor, paa.valor_hex, paa.valor_img
         FROM produto_atributos_agrupadores paa
         JOIN atributo_tipos at ON at.id = paa.atributo_tipo_id
         WHERE paa.produto_id = ?
         ORDER BY at.ordenacao ASC"
    );
    $stmt->execute([$productId]);
    $atributosAgrupadores = $stmt->fetchAll();

    // ── 3. Produtos da família ────────────────────────────
    $familiaId       = (int)($produto['familia_id'] ?? 0);
    $produtosFamilia = [];

    if ($familiaId) {
        $stmt = $this->db->prepare(
            "SELECT
                p.id, p.nome, p.slug,
                p.preco, p.preco_promo, p.estoque_total,
                MAX(pi.arquivo) AS imagem_principal,
                JSON_OBJECTAGG(at.slug, paa.valor)     AS agrupadores,
                JSON_OBJECTAGG(at.slug, paa.valor_hex) AS agrupadores_hex,
                JSON_OBJECTAGG(at.slug, paa.valor_img) AS agrupadores_img
            FROM produtos p
            JOIN produto_atributos_agrupadores paa ON paa.produto_id = p.id
            JOIN atributo_tipos at ON at.id = paa.atributo_tipo_id
            LEFT JOIN produto_imagens pi
                    ON pi.produto_id = p.id AND pi.principal = 1
            WHERE p.familia_id = ?
            AND p.ativo = 1
            AND p.deleted_at IS NULL
            GROUP BY
                p.id, p.nome, p.slug,
                p.preco, p.preco_promo, p.estoque_total
            ORDER BY p.id ASC"
        );
        $stmt->execute([$familiaId]);
        $rows = $stmt->fetchAll();

        foreach ($rows as $r) {
            $r['agrupadores']     = json_decode($r['agrupadores'],     true) ?? [];
            $r['agrupadores_hex'] = json_decode($r['agrupadores_hex'], true) ?? [];
            $r['agrupadores_img'] = json_decode($r['agrupadores_img'], true) ?? [];
            $r['atual']           = (int)$r['id'] === $productId;
            $r['sem_estoque']     = (int)$r['estoque_total'] === 0;
            $produtosFamilia[]    = $r;
        }
    }

    // ── 4. Tipos de variação do produto ───────────────────
    $stmt = $this->db->prepare(
        "SELECT at.id, at.nome, at.slug, at.tipo_display,
                at.unidade, at.ordenacao
         FROM produto_variacao_tipos pvt
         JOIN atributo_tipos at ON at.id = pvt.atributo_tipo_id
         WHERE pvt.produto_id = ?
         ORDER BY pvt.ordenacao ASC, at.ordenacao ASC"
    );
    $stmt->execute([$productId]);
    $tiposRaw = $stmt->fetchAll();

    // ── 5. SKUs do produto ────────────────────────────────
    $stmt = $this->db->prepare(
        "SELECT
            s.id   AS sku_id,
            s.sku,
            s.ean,
            s.preco,
            s.preco_promo,
            s.estoque,
            s.ativo,
            s.ordenacao,
            sa.atributo_tipo_id,
            at.slug AS atributo_slug,
            sa.valor
         FROM produto_skus s
         JOIN sku_atributos sa ON sa.sku_id = s.id
         JOIN atributo_tipos at ON at.id = sa.atributo_tipo_id
         WHERE s.produto_id = ?
           AND s.ativo = 1
         ORDER BY s.ordenacao ASC, s.id ASC, at.ordenacao ASC"
    );
    $stmt->execute([$productId]);
    $skuRows = $stmt->fetchAll();

    // Agrupa atributos por SKU
    $skusMap = [];
    foreach ($skuRows as $row) {
        $sid = $row['sku_id'];
        if (!isset($skusMap[$sid])) {
            $skusMap[$sid] = [
                'sku_id'     => $sid,
                'sku'        => $row['sku'],
                'ean'        => $row['ean'],
                'preco'      => (float)$row['preco'],
                'preco_promo'=> $row['preco_promo'] ? (float)$row['preco_promo'] : null,
                'estoque'    => (int)$row['estoque'],
                'atributos'  => [],
            ];
        }
        $skusMap[$sid]['atributos'][$row['atributo_slug']] = $row['valor'];
    }

    $skus   = [];
    $matriz = [];

    // Pega a ordem dos tipos para montar a chave
    $tiposSlug = array_column($tiposRaw, 'slug');

    foreach ($skusMap as $sku) {
        $precoFinal = (float)($sku['preco_promo'] ?: $sku['preco']);
        $semEstoque = $sku['estoque'] === 0;

        $sku['preco_final']  = $precoFinal;
        $sku['preco_fmt']    = 'R$ ' . number_format($precoFinal, 2, ',', '.');
        $sku['sem_estoque']  = $semEstoque;
        $skus[]              = $sku;

        // Monta chave da matriz respeitando a ordem dos tipos
        // Ex: tipos = ['tamanho'] → chave = '58'
        // Ex: tipos = ['tamanho','voltagem'] → chave = '58|110V'
        $partes = [];
        foreach ($tiposSlug as $slug) {
            $partes[] = $sku['atributos'][$slug] ?? '';
        }
        $chave = implode('|', $partes);

        $matriz[$chave] = [
            'sku_id'     => $sku['sku_id'],
            'sku'        => $sku['sku'],
            'preco'      => $precoFinal,
            'preco_fmt'  => $sku['preco_fmt'],
            'estoque'    => $sku['estoque'],
            'sem_estoque'=> $semEstoque,
        ];
    }

    // ── 6. Valores por tipo de variação ───────────────────
    // Extrai os valores únicos de cada tipo a partir dos SKUs
    $tiposVariacao = [];
    foreach ($tiposRaw as $tipo) {
        $slug   = $tipo['slug'];
        $valores = [];
        $vistos  = [];

        foreach ($skus as $sku) {
            $val = $sku['atributos'][$slug] ?? null;
            if ($val === null || isset($vistos[$val])) continue;
            $vistos[$val] = true;

            // Verifica se algum SKU com este valor tem estoque
            $temEstoque = false;
            foreach ($skus as $s2) {
                if (($s2['atributos'][$slug] ?? null) === $val && $s2['estoque'] > 0) {
                    $temEstoque = true;
                    break;
                }
            }

            $valores[] = [
                'valor'       => $val,
                'tem_estoque' => $temEstoque,
            ];
        }

        // Ordena os valores
        usort($valores, function ($a, $b) {
            $ordem = ['PP','P','M','G','GG','XG','XGG',
                      '34','35','36','37','38','39','40','41','42','43','44','45',
                      '46','47','48','50','52','54','56','58','60','62','64'];
            $ia = array_search(strtoupper($a['valor']), $ordem);
            $ib = array_search(strtoupper($b['valor']), $ordem);
            if ($ia !== false && $ib !== false) return $ia - $ib;
            if ($ia !== false) return -1;
            if ($ib !== false) return 1;
            return strnatcmp($a['valor'], $b['valor']);
        });

        $tiposVariacao[] = array_merge($tipo, ['valores' => $valores]);
    }

    // ── 7. Range de preços das variações ─────────────────────
    $precos = array_map(fn($s) => $s['preco_final'], $skus);
    $precos = array_filter($precos, fn($p) => $p > 0);

    $precoMin = !empty($precos) ? min($precos) : (float)$produto['preco'];
    $precoMax = !empty($precos) ? max($precos) : (float)$produto['preco'];
    $temRange = $precoMin !== $precoMax;

    return [
        'produto'               => $produto,
        'familia_id'            => $familiaId,
        'atributos_agrupadores' => $atributosAgrupadores,
        'produtos_familia'      => $produtosFamilia,
        'skus'                  => $skus,
        'tipos_variacao'        => $tiposVariacao,
        'tipos_slug'            => $tiposSlug,
        'matriz_skus'           => $matriz,
        'preco_min'             => $precoMin,
        'preco_max'             => $precoMax,
        'preco_min_fmt'         => PriceHelper::format($precoMin),
        'preco_max_fmt'         => PriceHelper::format($precoMax),
        'tem_range_preco'       => $temRange,
    ];
}

    /**
     * Retorna os dados da família para o card de produto.
     * Otimizado para listas — busca em batch.
     */
    public function getFamiliaParaCards(array $productIds): array {
        if (empty($productIds)) return [];

        $placeholders = implode(',', array_fill(0, count($productIds), '?'));

        $stmt = $this->db->prepare(
            "SELECT
                p.id AS produto_id,
                p2.id AS membro_id,
                p2.slug AS membro_slug,
                p2.estoque_total AS membro_estoque,
                at.slug AS atributo_slug,
                paa.valor,
                paa.valor_hex,
                pi.arquivo AS imagem
             FROM produtos p
             JOIN familia_produtos f   ON f.id = p.familia_id
             JOIN produtos p2          ON p2.familia_id = f.id
                                      AND p2.ativo = 1
                                      AND p2.deleted_at IS NULL
             JOIN produto_atributos_agrupadores paa ON paa.produto_id = p2.id
             JOIN atributo_tipos at ON at.id = paa.atributo_tipo_id
             LEFT JOIN produto_imagens pi ON pi.produto_id = p2.id AND pi.principal = 1
             WHERE p.id IN ({$placeholders})
             ORDER BY p.id, at.ordenacao, p2.id"
        );
        $stmt->execute($productIds);
        $rows = $stmt->fetchAll();

        // Agrupa por produto original
        $result = [];
        foreach ($rows as $row) {
            $pid  = $row['produto_id'];
            $attr = $row['atributo_slug'];
            $val  = $row['valor'];

            if (!isset($result[$pid])) $result[$pid] = [];
            if (!isset($result[$pid][$attr])) $result[$pid][$attr] = [];

            // Evita duplicatas
            $key = $row['membro_id'];
            if (!isset($result[$pid][$attr][$key])) {
                $result[$pid][$attr][$key] = [
                    'produto_id'  => (int)$row['membro_id'],
                    'slug'        => $row['membro_slug'],
                    'valor'       => $val,
                    'valor_hex'   => $row['valor_hex'],
                    'imagem'      => $row['imagem'],
                    'sem_estoque' => (int)$row['membro_estoque'] === 0,
                ];
            }
        }

        // Reindexar como array
        foreach ($result as &$attrs) {
            foreach ($attrs as &$vals) {
                $vals = array_values($vals);
            }
        }

        return $result;
    }

    
}