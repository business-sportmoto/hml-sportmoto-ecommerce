<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/services/SitemapService.php
//
// Monta o mapa do site — a versão para gente (/mapa-do-site) e a versão para
// buscador (/sitemap.xml) saem da MESMA lista.
//
// Mapa do site não é página de conteúdo: escrever os links à mão produz uma
// lista que envelhece na primeira categoria nova e ninguém percebe, porque
// nada quebra — só some. Por isso ele é gerado, e por isso não está no criador
// de páginas.
//
// Páginas com `noindex` saem do XML (é o que o noindex pede) mas continuam no
// mapa para gente, que é navegação e não indexação.
// ════════════════════════════════════════════════════════

class SitemapService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    /**
     * Seções do mapa, na ordem em que aparecem.
     *
     * Cada item: url, label, lastmod (ou null), noindex (bool).
     */
    public function secoes(): array
    {
        return [
            [
                'titulo' => 'A loja',
                'itens'  => [
                    ['url' => BASE_URL . '/',        'label' => 'Início',              'lastmod' => null, 'noindex' => false],
                    ['url' => BASE_URL . '/marcas',  'label' => 'Todas as marcas',     'lastmod' => null, 'noindex' => false],
                    ['url' => BASE_URL . '/promocoes', 'label' => 'Promoções',         'lastmod' => null, 'noindex' => false],
                ],
            ],
            ['titulo' => 'Categorias', 'itens' => $this->categorias()],
            ['titulo' => 'Marcas',     'itens' => $this->marcas()],
            // Peças por moto e Central de ajuda ficavam FORA do sitemap. São
            // as páginas que respondem "serve na minha moto?" e "qual o prazo
            // de troca?" — as duas perguntas que mais levam gente ao site.
            ['titulo' => 'Peças por moto',   'itens' => $this->motos()],
            ['titulo' => 'Central de ajuda', 'itens' => $this->ajuda()],
            ['titulo' => 'Produtos',   'itens' => $this->produtos()],
            ['titulo' => 'Institucional', 'itens' => $this->paginas()],
        ];
    }

    /** Lista plana, para o XML. */
    public function urls(bool $somenteIndexaveis = true): array
    {
        $out = [];
        foreach ($this->secoes() as $sec) {
            foreach ($sec['itens'] as $i) {
                if ($somenteIndexaveis && !empty($i['noindex'])) continue;
                $out[] = $i;
            }
        }
        return $out;
    }

    /* =================================================================
       FONTES
       ================================================================= */

    private function categorias(): array
    {
        return $this->linhas(
            "SELECT slug, nome FROM categorias WHERE ativo = 1 AND slug <> '' ORDER BY nome",
            '/categoria/'
        );
    }

    private function marcas(): array
    {
        return $this->linhas(
            "SELECT slug, nome FROM marcas WHERE ativo = 1 AND slug <> '' ORDER BY nome",
            '/marca/'
        );
    }

    private function produtos(): array
    {
        // O limite existe porque o sitemap.xml tem teto de 50 mil URLs por
        // arquivo. Passando disso, o caminho é dividir em vários e publicar um
        // índice — não truncar em silêncio, então o corte é alto de propósito.
        return $this->linhas(
            "SELECT slug, nome, atualizado_em FROM produtos
              WHERE ativo = 1 AND slug <> '' ORDER BY atualizado_em DESC LIMIT 45000",
            '/produto/'
        );
    }

    /** Montadoras que têm peça compatível no catálogo, e a lista delas. */
    private function motos(): array
    {
        $itens = [[
            'url' => BASE_URL . '/motos', 'label' => 'Peças por moto',
            'lastmod' => null, 'noindex' => false,
        ]];

        return array_merge($itens, $this->linhas(
            "SELECT mm.slug, mm.nome
               FROM moto_montadoras mm
               JOIN produto_compatibilidade pc ON pc.montadora_id = mm.id
              WHERE mm.ativo = 1 AND mm.slug <> ''
           GROUP BY mm.id, mm.slug, mm.nome
           ORDER BY mm.nome",
            '/montadora/'
        ));
    }

    /** Central de ajuda: a capa e cada categoria de dúvida. */
    private function ajuda(): array
    {
        $itens = [[
            'url' => BASE_URL . '/ajuda', 'label' => 'Central de ajuda',
            'lastmod' => null, 'noindex' => false,
        ]];

        return array_merge($itens, $this->linhas(
            "SELECT c.slug, c.nome
               FROM help_categorias c
               JOIN help_perguntas p ON p.categoria_id = c.id AND p.ativo = 1
              WHERE c.ativo = 1 AND c.slug <> ''
           GROUP BY c.id, c.slug, c.nome
           ORDER BY c.ordem, c.nome",
            '/ajuda/categoria/'
        ));
    }

    /** Páginas de conteúdo e landing pages, pelo mesmo lugar que o rodapé usa. */
    private function paginas(): array
    {
        $out = [];
        foreach (PaginaService::todas() as $p) {
            if (empty($p['slug'])) continue;
            $out[] = [
                'url'     => BASE_URL . '/' . $p['slug'],
                'label'   => (string) ($p['menu_label'] ?? $p['titulo'] ?? $p['slug']),
                'lastmod' => !empty($p['atualizado_em'])
                    ? date('Y-m-d', strtotime((string) $p['atualizado_em'])) : null,
                'noindex' => !empty($p['noindex']),
            ];
        }
        return $out;
    }

    private function linhas(string $sql, string $prefixo): array
    {
        try {
            $rows = $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            // Uma seção que falha não pode derrubar o mapa inteiro.
            LogService::exception($e, 'warning', 'app', ['onde' => 'SitemapService', 'sql' => $prefixo]);
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'url'     => BASE_URL . $prefixo . $r['slug'],
                'label'   => (string) ($r['nome'] ?? $r['slug']),
                'lastmod' => !empty($r['atualizado_em'])
                    ? date('Y-m-d', strtotime((string) $r['atualizado_em'])) : null,
                'noindex' => false,
            ];
        }
        return $out;
    }
}
