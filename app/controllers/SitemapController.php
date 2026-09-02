<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/controllers/SitemapController.php
//
// /mapa-do-site  — para gente
// /sitemap.xml   — para buscador
//
// As duas saem da mesma lista (SitemapService). Manter duas fontes é como se
// perde o sincronismo: alguém adiciona a categoria no XML e esquece do HTML.
// ════════════════════════════════════════════════════════

class SitemapController extends Controller
{
    // ── GET /mapa-do-site ────────────────────────────────
    public function html(): void
    {
        $secoes = (new SitemapService())->secoes();

        SeoHelper::setTitle('Mapa do site');
        SeoHelper::setDescription('Todas as seções, categorias, marcas e páginas da loja em um só lugar.');
        SeoHelper::setCanonical(BASE_URL . '/mapa-do-site');

        $this->render('mapa-do-site', ['secoes' => $secoes], 'main');
    }

    // ── GET /sitemap.xml ─────────────────────────────────
    public function xml(): void
    {
        $urls = (new SitemapService())->urls(true);

        // Buffer limpo antes do XML: qualquer espaço solto de um include vira
        // "XML declaration not at start of document" e o arquivo inteiro é
        // descartado pelo buscador.
        while (ob_get_level() > 0) { ob_end_clean(); }

        header('Content-Type: application/xml; charset=utf-8');
        header('X-Robots-Tag: noindex');   // o próprio sitemap não se indexa

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($urls as $u) {
            echo "  <url>\n";
            echo '    <loc>' . htmlspecialchars($u['url'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</loc>\n";
            if (!empty($u['lastmod'])) {
                echo '    <lastmod>' . htmlspecialchars($u['lastmod'], ENT_XML1, 'UTF-8') . "</lastmod>\n";
            }
            echo "  </url>\n";
        }

        echo '</urlset>';
    }
}
