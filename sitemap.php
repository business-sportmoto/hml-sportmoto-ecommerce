<?php
// sitemap.php — Sitemap XML gerado dinamicamente
// Acesse: https://sualoja.com.br/sitemap.xml
// Adicionar rota: Router::get('/sitemap.xml', 'SitemapController@index')

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

// Cache do sitemap por 6 horas
$cacheFile = STORAGE_PATH . '/cache/sitemap.xml';
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 21600) {
    header('Content-Type: application/xml; charset=UTF-8');
    readfile($cacheFile);
    exit;
}

$db = Database::getInstance()->getConnection();

ob_start();
header('Content-Type: application/xml; charset=UTF-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
         xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

// Função helper para escapar URL
$esc = fn($v) => htmlspecialchars($v, ENT_XML1);

// ── Páginas estáticas ─────────────────────────────────────
$estaticas = [
    ['loc' => BASE_URL,             'priority' => '1.0',  'changefreq' => 'daily'],
    ['loc' => BASE_URL . '/busca',  'priority' => '0.8',  'changefreq' => 'daily'],
    ['loc' => BASE_URL . '/login',  'priority' => '0.3',  'changefreq' => 'monthly'],
    ['loc' => BASE_URL . '/cadastro','priority' => '0.3', 'changefreq' => 'monthly'],
];

foreach ($estaticas as $url) {
    echo "  <url>\n";
    echo "    <loc>{$esc($url['loc'])}</loc>\n";
    echo "    <changefreq>{$url['changefreq']}</changefreq>\n";
    echo "    <priority>{$url['priority']}</priority>\n";
    echo "    <lastmod>" . date('Y-m-d') . "</lastmod>\n";
    echo "  </url>\n";
}

// ── Páginas institucionais ────────────────────────────────
$paginas = $db->query(
    "SELECT slug, atualizado_em FROM paginas WHERE ativo = 1"
)->fetchAll();

foreach ($paginas as $pag) {
    $loc = BASE_URL . '/' . $pag['slug'];
    $mod = date('Y-m-d', strtotime($pag['atualizado_em']));
    echo "  <url>\n";
    echo "    <loc>{$esc($loc)}</loc>\n";
    echo "    <lastmod>{$mod}</lastmod>\n";
    echo "    <changefreq>monthly</changefreq>\n";
    echo "    <priority>0.4</priority>\n";
    echo "  </url>\n";
}

// ── Categorias ────────────────────────────────────────────
$categorias = $db->query(
    "SELECT slug, atualizado_em FROM categorias WHERE ativo = 1"
)->fetchAll();

foreach ($categorias as $cat) {
    $loc = BASE_URL . '/categoria/' . $cat['slug'];
    $mod = isset($cat['atualizado_em'])
           ? date('Y-m-d', strtotime($cat['atualizado_em']))
           : date('Y-m-d');
    echo "  <url>\n";
    echo "    <loc>{$esc($loc)}</loc>\n";
    echo "    <lastmod>{$mod}</lastmod>\n";
    echo "    <changefreq>weekly</changefreq>\n";
    echo "    <priority>0.7</priority>\n";
    echo "  </url>\n";
}

// ── Produtos (com imagens) ────────────────────────────────
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
        $imgUrl = UPLOAD_URL . '/products/' . $prod['imagem'];
        echo "    <image:image>\n";
        echo "      <image:loc>{$esc($imgUrl)}</image:loc>\n";
        echo "      <image:title>{$esc($prod['nome_img'])}</image:title>\n";
        echo "    </image:image>\n";
    }
    echo "  </url>\n";
}

echo "</urlset>\n";

$xml = ob_get_clean();

// Salva cache
file_put_contents($cacheFile, $xml);

header('Content-Type: application/xml; charset=UTF-8');
echo $xml;