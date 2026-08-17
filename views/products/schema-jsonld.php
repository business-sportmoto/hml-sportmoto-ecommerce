<?php
// ════════════════════════════════════════════════════════
// SCHEMA.ORG JSON-LD — Product (Rich Snippets)
// Inserir no TOPO da detail.php, logo após as variáveis
// (depois da linha ~16, onde $preco/$semEstoque já existem).
//
// Gera estrela/preço/estoque na busca do Google. Usa SÓ dados
// reais da view — campos ausentes são omitidos (schema válido
// prefere campo ausente a campo vazio/falso).
//
// Referência: schema.org/Product + Google Merchant structured data.
// ════════════════════════════════════════════════════════

// ── Monta o array do schema condicionalmente ──
$schema = [
    '@context' => 'https://schema.org/',
    '@type'    => 'Product',
    'name'     => $product['nome'] ?? '',
    'sku'      => $product['sku_legado'] ?? '',
];

// Descrição: tira HTML (schema quer texto puro), limita tamanho
if (!empty($product['descricao'])) {
    $descLimpa = trim(preg_replace('/\s+/', ' ', strip_tags($product['descricao'])));
    if ($descLimpa !== '') {
        $schema['description'] = mb_substr($descLimpa, 0, 5000);
    }
}

// Marca
if (!empty($product['marca_nome'])) {
    $schema['brand'] = [
        '@type' => 'Brand',
        'name'  => $product['marca_nome'],
    ];
}

// Imagens (galeria) — usa o helper R2 que a view já usa
if (!empty($images) && is_array($images)) {
    $imgs = [];
    foreach ($images as $img) {
        if (!empty($img['arquivo'])) {
            $imgs[] = View::uploadR2($img['arquivo']);
        }
    }
    if ($imgs) {
        $schema['image'] = $imgs;
    }
}

// ── Offers: range (produto com variação) ou preço único ──
$disponibilidade = $semEstoque
    ? 'https://schema.org/OutOfStock'
    : 'https://schema.org/InStock';

$temRangeSchema = !empty($vdata['tem_range_preco'])
    && !empty($vdata['preco_min'])
    && !empty($vdata['preco_max'])
    && $vdata['preco_min'] != $vdata['preco_max'];

if ($temRangeSchema) {
    // Produto com variação e faixa de preço → AggregateOffer
    $schema['offers'] = [
        '@type'         => 'AggregateOffer',
        'priceCurrency' => 'BRL',
        'lowPrice'      => number_format((float)$vdata['preco_min'], 2, '.', ''),
        'highPrice'     => number_format((float)$vdata['preco_max'], 2, '.', ''),
        'availability'  => $disponibilidade,
        'url'           => BASE_URL . '/produto/' . ($product['slug'] ?? ''),
    ];
} else {
    // Preço único → Offer
    $schema['offers'] = [
        '@type'         => 'Offer',
        'priceCurrency' => 'BRL',
        'price'         => number_format((float)$preco, 2, '.', ''),
        'availability'  => $disponibilidade,
        'url'           => BASE_URL . '/produto/' . ($product['slug'] ?? ''),
        // Validade do preço (Google recomenda) — 1 ano à frente
        'priceValidUntil' => date('Y-m-d', strtotime('+1 year')),
    ];
}

// ── AggregateRating: SÓ se houver avaliações reais ──
// Google penaliza rating falso/vazio. Só inclui se total > 0.
if (!empty($resumo['total']) && (int)$resumo['total'] > 0) {
    $schema['aggregateRating'] = [
        '@type'       => 'AggregateRating',
        'ratingValue' => number_format((float)$resumo['media'], 1, '.', ''),
        'reviewCount' => (int)$resumo['total'],
        'bestRating'  => '5',
        'worstRating' => '1',
    ];
}
?>
<script type="application/ld+json">
<?= json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>
</script>