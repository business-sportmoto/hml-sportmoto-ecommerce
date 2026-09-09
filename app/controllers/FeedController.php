<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/controllers/FeedController.php
//
// /feed/produtos.xml     — feed de produtos (RSS 2.0 + namespace g:)
// /feed/google-merchant  — mesma saída (rota antiga, mantida)
//
// UM feed serve as DUAS plataformas: o Catálogo da Meta (anúncios
// dinâmicos / Advantage+) e o Google Merchant Center consomem o mesmo
// formato. Duplicar geraria duas verdades sobre o mesmo produto.
//
// HISTÓRICO: googleMerchant() montava o XML aqui dentro chamando
// ProductGroup::getGoogleMerchantData(). Esse model nunca foi escrito
// (o arquivo existe com 0 bytes), então a rota respondia HTTP 500 com
// "Class ProductGroup not found" desde sempre. A montagem agora vive
// no ProductFeedService, e o controller só emite XML.
// ════════════════════════════════════════════════════════

class FeedController extends Controller
{
    /** Campos que podem repetir dentro do mesmo <item>. */
    private const MULTIVALOR = ['additional_image_link'];

    // ── GET /feed/produtos.xml ───────────────────────────
    public function produtos(): void
    {
        $dados = (new ProductFeedService())->itens();

        // Mesmo cuidado do sitemap: um espaço solto antes da declaração
        // XML invalida o documento inteiro, e a plataforma descarta o
        // feed sem dizer por quê.
        while (ob_get_level() > 0) { ob_end_clean(); }

        header('Content-Type: application/xml; charset=utf-8');
        header('X-Robots-Tag: noindex');

        $esc = static fn(string $v): string =>
            htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $nomeLoja = (string) (ConfigHelper::get('site_nome') ?: 'SportMoto');

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n";
        echo "<channel>\n";
        echo '  <title>' . $esc($nomeLoja) . "</title>\n";
        echo '  <link>' . $esc(BASE_URL) . "</link>\n";
        echo '  <description>' . $esc('Peças e acessórios para motos.') . "</description>\n";

        foreach ($dados['itens'] as $item) {
            echo "  <item>\n";
            foreach ($item as $campo => $valor) {
                if (in_array($campo, self::MULTIVALOR, true) && is_array($valor)) {
                    foreach ($valor as $v) {
                        echo "    <g:{$campo}>" . $esc((string) $v) . "</g:{$campo}>\n";
                    }
                    continue;
                }
                echo "    <g:{$campo}>" . $esc((string) $valor) . "</g:{$campo}>\n";
            }
            echo "  </item>\n";
        }

        // Produto descartado não pode sumir sem explicação: quem abrir o
        // arquivo vê o motivo, e a plataforma ignora comentários.
        $ig = $dados['ignorados'];
        echo '  <!-- itens: ' . count($dados['itens'])
           . ' | ignorados: sem_imagem=' . (int) $ig['sem_imagem']
           . ' sem_preco=' . (int) $ig['sem_preco']
           . ' sem_titulo=' . (int) $ig['sem_titulo'] . " -->\n";

        echo "</channel>\n";
        echo "</rss>";
    }

    // ── GET /feed/google-merchant ────────────────────────
    /** Alias da rota antiga — mesma saída, para não quebrar links. */
    public function googleMerchant(): void
    {
        $this->produtos();
    }
}
