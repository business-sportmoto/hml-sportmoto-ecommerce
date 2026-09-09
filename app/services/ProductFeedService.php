<?php
declare(strict_types=1);

/**
 * app/services/ProductFeedService.php
 *
 * Monta o feed de produtos no formato RSS 2.0 com namespace `g:`
 * (base.google.com/ns/1.0) — o MESMO formato aceito pelo Catálogo da
 * Meta e pelo Google Merchant Center. Um endpoint, duas plataformas.
 *
 * ═══ A REGRA QUE NÃO PODE QUEBRAR ═══
 * `g:id` TEM que ser igual ao `content_ids` dos eventos, que hoje é
 * `produtos.id` (ProductController::beacon envia `[(string)$id]`).
 *
 * É por isso que este feed é POR PRODUTO e não por SKU: um feed
 * SKU-level teria ids que nenhum evento menciona, o catálogo não
 * casaria com o ViewContent e os anúncios dinâmicos ficariam sem saber
 * qual item mostrar — sem erro, sem aviso, só campanha que não roda.
 *
 * Preço vem do PriceHelper::currentPrice(), o mesmo usado no /beacon.
 * Se divergir, a Meta acusa "price mismatch" entre feed e landing page.
 *
 * SOMENTE LEITURA.
 */
final class ProductFeedService
{
    /** Teto de itens por geração — trava de segurança, não regra de negócio. */
    private const LIMITE = 20000;

    /** Meta aceita até 20 imagens extras; 10 já cobre o catálogo. */
    private const MAX_IMAGENS_EXTRA = 10;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    /**
     * Linhas do feed, prontas para virar XML.
     *
     * @return array{itens:array<int,array<string,mixed>>, ignorados:array<string,int>}
     */
    public function itens(): array
    {
        $ignorados = ['sem_imagem' => 0, 'sem_preco' => 0, 'sem_titulo' => 0];

        $sql =
            "SELECT p.id, p.nome, p.slug, p.descricao_curta, p.descricao,
                    p.preco, p.preco_promo, p.promo_inicio, p.promo_fim,
                    p.estoque_total, p.item_group_id, p.google_category,
                    m.nome AS marca,
                    (SELECT pi.arquivo FROM produto_imagens pi
                      WHERE pi.produto_id = p.id
                      ORDER BY pi.principal DESC, pi.ordem ASC, pi.id ASC
                      LIMIT 1) AS imagem_principal,
                    (SELECT COUNT(*) FROM produto_skus s
                      WHERE s.produto_id = p.id AND s.ativo = 1) AS qtd_skus,
                    (SELECT s.ean FROM produto_skus s
                      WHERE s.produto_id = p.id AND s.ativo = 1
                        AND s.ean IS NOT NULL AND s.ean <> ''
                      ORDER BY s.id ASC LIMIT 1) AS ean
               FROM produtos p
               LEFT JOIN marcas m ON m.id = p.marca_id
              WHERE p.ativo = 1 AND p.deleted_at IS NULL
              ORDER BY p.id ASC
              LIMIT " . self::LIMITE;

        $produtos = $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $itens    = [];

        foreach ($produtos as $p) {
            $titulo = trim((string) ($p['nome'] ?? ''));
            if ($titulo === '') { $ignorados['sem_titulo']++; continue; }

            $precoBase = (float) $p['preco'];
            if ($precoBase <= 0) { $ignorados['sem_preco']++; continue; }

            // Item sem imagem é REJEITADO pela Meta. Melhor não enviar do
            // que sujar o diagnóstico do catálogo com erro previsível.
            $imagem = $this->urlImagem($p['imagem_principal'] ?? null);
            if ($imagem === null) { $ignorados['sem_imagem']++; continue; }

            // Mesmo cálculo do /beacon: promoção só vale dentro da janela.
            $precoVigente = PriceHelper::currentPrice($p);

            $item = [
                'id'           => (string) $p['id'],   // = content_ids do evento
                'title'        => $this->limitar($this->texto($titulo), 150),
                'description'  => $this->descricao($p),
                'link'         => BASE_URL . '/produto/' . $p['slug'],
                'image_link'   => $imagem,
                'availability' => ((int) $p['estoque_total']) > 0 ? 'in stock' : 'out of stock',
                'condition'    => 'new',               // loja de peças novas
                'price'        => $this->moeda($precoBase),
                'brand'        => $this->limitar($this->texto($p['marca'] ?? '') ?: 'SportMoto', 70),
            ];

            // sale_price só quando há desconto vigente de verdade.
            if ($precoVigente > 0 && $precoVigente < $precoBase) {
                $item['sale_price'] = $this->moeda($precoVigente);
            }

            // GTIN só com SKU único: com variações, o EAN de um SKU não
            // representa o produto e um GTIN errado derruba o item.
            if (((int) $p['qtd_skus']) === 1 && !empty($p['ean'])) {
                $item['gtin'] = preg_replace('/\D/', '', (string) $p['ean']);
            }

            if (!empty($p['item_group_id'])) {
                $item['item_group_id'] = (string) $p['item_group_id'];
            }
            if (!empty($p['google_category'])) {
                $item['google_product_category'] = $this->texto($p['google_category']);
            }

            $extras = $this->imagensExtras((int) $p['id'], $imagem);
            if ($extras !== []) {
                $item['additional_image_link'] = $extras;
            }

            $itens[] = $item;
        }

        return ['itens' => $itens, 'ignorados' => $ignorados];
    }

    /**
     * Resolve a URL pública da imagem. O arquivo pode já ser uma URL
     * completa (R2) ou só o nome — mesma regra do sitemap.
     */
    private function urlImagem(?string $arquivo): ?string
    {
        $arquivo = trim((string) $arquivo);
        if ($arquivo === '') return null;

        if (str_starts_with($arquivo, 'http://') || str_starts_with($arquivo, 'https://')) {
            return $arquivo;
        }
        return UPLOAD_URL . '/products/' . $arquivo;
    }

    /** Demais imagens do produto, exceto a principal. */
    private function imagensExtras(int $produtoId, string $principal): array
    {
        $st = $this->db->prepare(
            "SELECT arquivo FROM produto_imagens
              WHERE produto_id = ?
              ORDER BY principal DESC, ordem ASC, id ASC
              LIMIT " . (self::MAX_IMAGENS_EXTRA + 1)
        );
        $st->execute([$produtoId]);

        $urls = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $arq) {
            $u = $this->urlImagem($arq);
            if ($u !== null && $u !== $principal) { $urls[] = $u; }
        }
        return array_slice(array_values(array_unique($urls)), 0, self::MAX_IMAGENS_EXTRA);
    }

    /**
     * Descrição: prefere a curta; cai na longa sem HTML. Campo é
     * obrigatório, então nunca volta vazio — no limite repete o título.
     */
    private function descricao(array $p): string
    {
        $texto = $this->texto($p['descricao_curta'] ?? '');
        if ($texto === '') {
            // strip_tags ANTES do decode: decodificar primeiro poderia
            // reconstruir uma tag a partir de &lt;script&gt; e ela
            // sobreviveria à limpeza.
            $texto = $this->texto(strip_tags((string) ($p['descricao'] ?? '')));
            // Segunda passada: o decode pode ter revelado marcação que
            // estava escapada por baixo das camadas.
            $texto = trim(strip_tags($texto));
        }
        if ($texto === '') { $texto = $this->texto($p['nome'] ?? ''); }

        return $this->limitar($texto, 5000);
    }

    /** Formato exigido: "89.90 BRL" — ponto decimal, sem separador de milhar. */
    private function moeda(float $valor): string
    {
        return number_format($valor, 2, '.', '') . ' BRL';
    }

    private function limitar(string $s, int $max): string
    {
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
    }

    /**
     * Decodifica entidades HTML até o texto estabilizar.
     *
     * POR QUE O LOOP: há valores no banco com escape EMPILHADO — o
     * mesmo campo aparece com `&amp;amp;amp;gt;` e a profundidade varia
     * por linha, sinal de que algo re-escapa a cada gravação. Um único
     * html_entity_decode() descascaria só uma camada e o feed sairia
     * com lixo, que a plataforma rejeita ou categoriza errado.
     *
     * O texto limpo sai daqui CRU; quem escapa (uma vez só) é o XML.
     */
    private function texto(?string $valor): string
    {
        $s = trim((string) $valor);

        // Teto de segurança: impede laço infinito se algum valor
        // patológico nunca estabilizar.
        for ($i = 0; $i < 10; $i++) {
            $decodificado = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decodificado === $s) break;
            $s = $decodificado;
        }

        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }
}
