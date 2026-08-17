<?php
// ════════════════════════════════════════════════════════
// SCHEMA.ORG JSON-LD — BreadcrumbList (navegação na busca)
// Inserir na detail.php, logo após o Product JSON-LD (ou
// junto do bloco de schema, no topo — depende de onde $breadcrumb
// já está definido; precisa vir DEPOIS de $breadcrumb existir).
//
// Reusa o MESMO array $breadcrumb da navegação visual — garante
// que o breadcrumb do Google e o da tela são idênticos.
//
// Resultado: o Google mostra "Início › Categoria › Produto" no
// lugar da URL crua no resultado de busca.
// ════════════════════════════════════════════════════════

if (!empty($breadcrumb) && is_array($breadcrumb)) {
    $itemList = [];
    $pos = 1;

    foreach ($breadcrumb as $crumb) {
        if (empty($crumb['label'])) {
            continue;
        }

        $item = [
            '@type'    => 'ListItem',
            'position' => $pos,
            'name'     => $crumb['label'],
        ];

        // O último item (produto atual) tem url = null. O schema
        // permite ListItem sem 'item' na última posição — é o
        // padrão recomendado pelo Google (a página atual não
        // linka pra si mesma).
        if (!empty($crumb['url'])) {
            $item['item'] = $crumb['url'];
        }

        $itemList[] = $item;
        $pos++;
    }

    if (count($itemList) > 0) {
        $breadcrumbSchema = [
            '@context'        => 'https://schema.org/',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $itemList,
        ];
        ?>
<script type="application/ld+json">
<?= json_encode($breadcrumbSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>
</script>
        <?php
    }
}