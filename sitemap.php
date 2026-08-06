<?php
// sitemap.php — Sitemap XML dinâmico
// Rota: Router::get('/sitemap.xml', 'SitemapController@index')
// (ou servido direto da raiz, conforme seu setup atual)

require_once __DIR__ . '/config/defines.php';
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

spl_autoload_register(function (string $class): void {
    $paths = [ROOT_PATH . '/core/', ROOT_PATH . '/app/models/',
              ROOT_PATH . '/app/helpers/', ROOT_PATH . '/app/services/'];
    foreach ($paths as $path) {
        $file = $path . $class . '.php';
        if (file_exists($file)) { require_once $file; return; }
    }
});

// ── Cache 6h COM LOCK (evita 2 crawlers regenerarem juntos → XML corrompido) ──
$cacheFile = STORAGE_PATH . '/cache/sitemap.xml';
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 21600) {
    header('Content-Type: application/xml; charset=UTF-8');
    readfile($cacheFile);
    exit;
}

$db = Database::getInstance()->getConnection();

ob_start();
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
echo '         xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

$esc = fn($v) => htmlspecialchars((string)$v, ENT_XML1);

/** Emite um bloco <url> padrão. */
$emitUrl = function (string $loc, string $mod, string $freq, string $prio) use ($esc): void {
    echo "  <url>\n";
    echo "    <loc>{$esc($loc)}</loc>\n";
    echo "    <lastmod>{$mod}</lastmod>\n";
    echo "    <changefreq>{$freq}</changefreq>\n";
    echo "    <priority>{$prio}</priority>\n";
    echo "  </url>\n";
};

// ── Páginas estáticas ────────────────────────────────────
$hoje = date('Y-m-d');
$estaticas = [
    [BASE_URL,              '1.0', 'daily'],
    [BASE_URL . '/busca',   '0.8', 'daily'],
    [BASE_URL . '/motos',   '0.7', 'weekly'],   // hub de veículos
    [BASE_URL . '/login',   '0.3', 'monthly'],
    [BASE_URL . '/cadastro','0.3', 'monthly'],
];
foreach ($estaticas as [$loc, $prio, $freq]) {
    $emitUrl($loc, $hoje, $freq, $prio);
}

// ── Páginas institucionais ───────────────────────────────
foreach ($db->query("SELECT slug, atualizado_em FROM paginas WHERE ativo = 1")->fetchAll() as $pag) {
    $emitUrl(BASE_URL . '/' . $pag['slug'],
             date('Y-m-d', strtotime($pag['atualizado_em'])), 'monthly', '0.4');
}

// ── Categorias ───────────────────────────────────────────
foreach ($db->query("SELECT slug, atualizado_em FROM categorias WHERE ativo = 1")->fetchAll() as $cat) {
    $mod = !empty($cat['atualizado_em']) ? date('Y-m-d', strtotime($cat['atualizado_em'])) : $hoje;
    $emitUrl(BASE_URL . '/categoria/' . $cat['slug'], $mod, 'weekly', '0.7');
}

// ── Montadoras — /montadora/{slug} ───────────────────────
// Só montadoras que têm produtos compatíveis (senão é página vazia
// = SEO ruim). O EXISTS filtra montadoras sem catálogo.
$montadoras = $db->query(
    "SELECT DISTINCT mm.slug
     FROM montadoras mm
     WHERE mm.ativo = 1
       AND EXISTS (
           SELECT 1 FROM produto_compatibilidade pc
           WHERE pc.montadora_id = mm.id
       )"
)->fetchAll();
foreach ($montadoras as $mm) {
    $emitUrl(BASE_URL . '/montadora/' . $mm['slug'], $hoje, 'weekly', '0.6');
}

// ── Modelos — /montadora/{slug}/{modelo-slug} ────────────
// Páginas de alto valor SEO ("peças para CB 500"). Só modelos
// ativos com compatibilidade real.
$modelos = $db->query(
    "SELECT mm.slug AS montadora_slug, mo.slug AS modelo_slug
     FROM moto_modelos mo
     JOIN montadoras mm ON mm.id = mo.montadora_id AND mm.ativo = 1
     WHERE mo.ativo = 1
       AND EXISTS (
           SELECT 1 FROM produto_compatibilidade pc
           WHERE pc.modelo_id = mo.id
       )"
)->fetchAll();
foreach ($modelos as $mod) {
    $emitUrl(
        BASE_URL . '/montadora/' . $mod['montadora_slug'] . '/' . $mod['modelo_slug'],
        $hoje, 'weekly', '0.7'
    );
}

// ── Produtos (com imagens R2) ────────────────────────────
$produtos = $db->query(
    "SELECT p.slug, p.atualizado_em,
            pi.arquivo AS imagem, p.nome AS nome_img
     FROM produtos p
     LEFT JOIN produto_imagens pi ON pi.produto_id = p.id AND pi.principal = 1
     WHERE p.ativo = 1 AND p.deleted_at IS NULL
     ORDER BY p.atualizado_em DESC
     LIMIT 50000"
)->fetchAll();

foreach ($produtos as $prod) {
    $loc = BASE_URL . '/produto/' . $prod['slug'];
    $mod = date('Y-m-d', strtotime($prod['atualizado_em']));
    echo "  <url>\n";
    echo "    <loc>{$esc($loc)}</loc>\n";
    echo "    <lastmod>{$mod}</lastmod>\n";
    echo "    <changefreq>weekly</changefreq>\n";
    echo "    <priority>0.8</priority>\n";
    if (!empty($prod['imagem'])) {
        // IMAGENS NO R2: o arquivo pode já ser uma URL completa (https)
        // ou só o nome. Trata os dois casos — não prefixa se já for URL.
        $img = $prod['imagem'];
        $imgUrl = (str_starts_with($img, 'http://') || str_starts_with($img, 'https://'))
                ? $img
                : UPLOAD_URL . '/products/' . $img;
        echo "    <image:image>\n";
        echo "      <image:loc>{$esc($imgUrl)}</image:loc>\n";
        echo "      <image:title>{$esc($prod['nome_img'])}</image:title>\n";
        echo "    </image:image>\n";
    }
    echo "  </url>\n";
}

echo "</urlset>\n";
$xml = ob_get_clean();

// ── Grava cache COM LOCK (atômico, evita corrupção concorrente) ──
$tmp = $cacheFile . '.' . getmypid() . '.tmp';
if (file_put_contents($tmp, $xml, LOCK_EX) !== false) {
    @rename($tmp, $cacheFile);   // rename é atômico no mesmo filesystem
} else {
    @unlink($tmp);
}

header('Content-Type: application/xml; charset=UTF-8');
echo $xml;