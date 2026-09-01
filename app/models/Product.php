<?php
// app/models/Product.php

class Product extends Model {
    protected string $table = 'produtos';

    // ── Listagens para a home ─────────────────────────────────

    public function parseClips(array $products): array {
        if (empty($products)) return $products;  
        $ids        = array_values(array_map('intval', array_column($products, 'id')));
        $idsComClip = Clip::produtosComClip($ids);   // estático — sem new Clip()
    
        foreach ($products as &$p) {
            $p['_tem_clip'] = in_array((int)$p['id'], $idsComClip, true);
        }
        unset($p);
    
        return $products;
    }

    public function getFeatured(int $limit = 8): array
    {
        $produtos = $this->getList(['destaque' => 1], $limit);
    

        return $produtos;
    }

    public function getOnSale(int $limit = 8): array {
        $stmt = $this->db->prepare(
            "SELECT p.*,
                    pi.arquivo AS imagem_principal,
                    c.nome AS categoria_nome, c.slug AS categoria_slug,
                    m.nome AS marca_nome
             FROM produtos p
             LEFT JOIN produto_imagens pi ON pi.produto_id = p.id AND pi.principal = 1
             LEFT JOIN categorias c ON c.id = p.categoria_id
             LEFT JOIN marcas m ON m.id = p.marca_id
             WHERE p.ativo = 1
               AND p.deleted_at IS NULL
               AND p.preco_promo IS NOT NULL
               AND p.preco_promo > 0
               AND (p.promo_inicio IS NULL OR p.promo_inicio <= NOW())
               AND (p.promo_fim   IS NULL OR p.promo_fim   >= NOW())
             ORDER BY (1 - p.preco_promo / p.preco) DESC
             LIMIT ?"
        );
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    public function getBestSellers(int $limit = 8): array {
        $produtos = $this->getList(['order' => 'vendidos DESC'], $limit);

        return $this->parseClips($produtos);
    }

    public function getRecent(int $limit = 8): array {
        $produtos = $this->getList(['order' => 'criado_em DESC'], $limit);
        
        return $this->parseClips($produtos);
    }

    /**
     * Busca inteligente full-text + fallback LIKE.
     * Busca em: nome, descrição, meta_keywords, SKU e tags.
     */
    /**
     * Busca textual — agora é um wrapper sobre getCatalog(), reaproveitando
     * TODO o padrão do catálogo: favoritado, preco_min/max, marca_slug,
     * prioridade de estoque, filtro de atributos/características, e a
     * mesma ordenação (incluindo relevância de busca em buildOrder()).
     *
     * Antes esta era uma query isolada e mais simples (sem favoritado,
     * sem preço misto, sem suporte a marcas[]/preco_min/preco_max que o
     * SearchController já montava mas nunca eram usados). Unificado para
     * que qualquer melhoria futura no catálogo beneficie a busca também.
     */
    public function search(string $term, int $limit = 24, int $offset = 0, array $filters = []): array {
        $filters['q'] = SecurityHelper::sanitizeSearch($term);
        return $this->getCatalog($filters, $limit, $offset);
    }

    public function countSearch(string $term, array $filters = []): int {
        $filters['q'] = SecurityHelper::sanitizeSearch($term);
        return $this->countCatalog($filters);
    }

    public function autocomplete(string $term, int $limit = 6, array $filters = []): array {
        $termLike = '%' . SecurityHelper::sanitizeSearch($term) . '%';

        $where  = "p.ativo = 1 AND p.deleted_at IS NULL AND (p.nome LIKE ? OR p.sku_legado LIKE ?)";
        $params = [$termLike, $termLike];

        if (!empty($filters['categoria_id'])) {
            $where   .= " AND p.categoria_id = ?";
            $params[] = (int)$filters['categoria_id'];
        }

        $stmt = $this->db->prepare(
            "SELECT p.id, p.nome, p.slug, p.preco, p.preco_promo,
                    pi.arquivo AS imagem,
                    c.nome AS categoria
            FROM produtos p
            LEFT JOIN produto_imagens pi ON pi.produto_id = p.id AND pi.principal = 1
            LEFT JOIN categorias c ON c.id = p.categoria_id
            WHERE {$where}
            ORDER BY p.vendidos DESC
            LIMIT ?"
        );
        $params[] = $limit;
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // ── Helper de query com filtros ───────────────────────────

    public function findBySlug2(string $slug): ?array {
        $stmt = $this->db->prepare(
            "SELECT p.*,
                    c.nome AS categoria_nome, c.slug AS categoria_slug,
                    m.nome AS marca_nome, m.slug AS marca_slug
             FROM produtos p
             LEFT JOIN categorias c ON c.id = p.categoria_id
             LEFT JOIN marcas m     ON m.id  = p.marca_id
             WHERE p.slug = ? AND p.ativo = 1 AND p.deleted_at IS NULL
             LIMIT 1"
        );
        $stmt->execute([$slug]);
        return $stmt->fetch() ?: null;
    }

    public function findById(string $slug): ?array {
        $stmt = $this->db->prepare(
            "SELECT p.*,
                    c.nome AS categoria_nome, c.slug AS categoria_slug,
                    m.nome AS marca_nome, m.slug AS marca_slug
             FROM produtos p
             LEFT JOIN categorias c ON c.id = p.categoria_id
             LEFT JOIN marcas m     ON m.id  = p.marca_id
             WHERE p.id = ? AND p.ativo = 1 AND p.deleted_at IS NULL
             LIMIT 1"
        );
        $stmt->execute([$slug]);
        return $stmt->fetch() ?: null;
    }

    // private function getList(array $opts = [], int $limit = 8): array {
    //     $where  = "p.ativo = 1 AND p.deleted_at IS NULL";
    //     $params = [];
    //     $order  = $opts['order'] ?? 'p.criado_em DESC';

    //     if (!empty($opts['destaque']))     { $where .= " AND p.destaque = 1"; }
    //     if (!empty($opts['categoria_id'])) { $where .= " AND p.categoria_id = ?"; $params[] = $opts['categoria_id']; }

    //     // Pega o cliente_id da sessão para marcar favoritos
    //     $clienteId = Session::isClienteLogado()
    //                 ? (int)Session::get('cliente_id')
    //                 : 0;

    //     $stmt = $this->db->prepare(
    //         "SELECT p.*,
    //                 pi.arquivo   AS imagem_principal,
    //                 c.nome       AS categoria_nome,
    //                 c.slug       AS categoria_slug,
    //                 m.nome       AS marca_nome,
    //                 m.slug AS marca_slug,
    //                 -- Menor preço entre os SKUs ativos com estoque
    //                 (
    //                     SELECT MIN(COALESCE(NULLIF(s.preco_promo, 0), s.preco))
    //                     FROM produto_skus s
    //                     WHERE s.produto_id = p.id
    //                     AND s.ativo = 1
    //                     AND s.estoque > 0
    //                 ) AS preco_min,

    //                 -- Maior preço entre os SKUs ativos com estoque
    //                 (
    //                     SELECT MAX(COALESCE(NULLIF(s.preco_promo, 0), s.preco))
    //                     FROM produto_skus s
    //                     WHERE s.produto_id = p.id
    //                     AND s.ativo = 1
    //                     AND s.estoque > 0
    //                 ) AS preco_max,

    //                 -- Verifica se está na lista padrão do cliente logado
    //                 CASE
    //                     WHEN ? > 0 THEN (
    //                         SELECT COUNT(*) > 0
    //                         FROM wishlist_itens wi
    //                         JOIN wishlist w ON w.id = wi.wishlist_id
    //                         WHERE w.cliente_id  = ?
    //                         AND w.padrao      = 1
    //                         AND wi.produto_id = p.id
    //                     )
    //                     ELSE 0
    //                 END AS favoritado

    //         FROM produtos p
    //         LEFT JOIN produto_imagens pi ON pi.produto_id = p.id AND pi.principal = 1
    //         LEFT JOIN categorias c       ON c.id = p.categoria_id
    //         LEFT JOIN marcas m           ON m.id = p.marca_id
    //         WHERE {$where}
    //         ORDER BY {$order}
    //         LIMIT ?"
    //     );

    //     // Injeta clienteId duas vezes (para o CASE e para o subquery)
    //     // ANTES dos outros params
    //     array_unshift($params, $clienteId, $clienteId);
    //     $params[] = $limit;

    //     $stmt->execute($params);
    //     return $stmt->fetchAll();
    // }

    /**
     * @param int $offset Deslocamento. Zero por padrão — todas as chamadas da
     *                    web continuam valendo sem mudar nada. O app usa isto
     *                    para carregar mais produtos no mesmo carrossel.
     */
    private function getList(array $opts = [], int $limit = 8, int $offset = 0): array {
        $where  = "p.ativo = 1 AND p.deleted_at IS NULL";
        $params = [];
        $order  = $opts['order'] ?? 'p.criado_em DESC';
    
        // ── Filtros existentes ────────────────────────────
        if (!empty($opts['destaque']))     { $where .= " AND p.destaque = 1"; }
        if (!empty($opts['categoria_id'])) { $where .= " AND p.categoria_id = ?"; $params[] = $opts['categoria_id']; }
    
        // ── Filtros novos ─────────────────────────────────
    
        // IDs específicos — mantém ordem do array
        if (!empty($opts['ids'])) {
            $ids  = array_values(array_map('intval', $opts['ids']));
            $in   = implode(',', array_fill(0, count($ids), '?'));
            $where .= " AND p.id IN ({$in})";
            foreach ($ids as $id) $params[] = $id;
            // Ordena conforme a lista fornecida (se não veio ordem explícita)
            if (!isset($opts['order'])) {
                $order = "FIELD(p.id, " . implode(',', $ids) . ")";
            }
        }
    
        // IDs a excluir
        if (!empty($opts['excluir_ids'])) {
            $ids  = array_values(array_map('intval', $opts['excluir_ids']));
            $in   = implode(',', array_fill(0, count($ids), '?'));
            $where .= " AND p.id NOT IN ({$in})";
            foreach ($ids as $id) $params[] = $id;
        }
    
        // Múltiplas categorias via tabela pivot
        if (!empty($opts['categorias'])) {
            $cats = array_values(array_map('intval', $opts['categorias']));
            $in   = implode(',', array_fill(0, count($cats), '?'));
            $where .= " AND EXISTS (
                SELECT 1 FROM produto_categorias pc2
                WHERE pc2.produto_id = p.id
                AND pc2.categoria_id IN ({$in})
            )";
            foreach ($cats as $c) $params[] = $c;
        }
    
        // Múltiplas marcas
        if (!empty($opts['marcas'])) {
            $mids = array_values(array_map('intval', $opts['marcas']));
            $in   = implode(',', array_fill(0, count($mids), '?'));
            $where .= " AND p.marca_id IN ({$in})";
            foreach ($mids as $m) $params[] = $m;
        }
    
        // Marca única (sem criar conflito com 'marcas')
        if (!empty($opts['marca_id']) && empty($opts['marcas'])) {
            $where .= " AND p.marca_id = ?";
            $params[] = (int)$opts['marca_id'];
        }
    
        // Apenas em promoção
        if (!empty($opts['promocao'])) {
            $where .= " AND p.preco_promo > 0 AND p.preco_promo < p.preco";
        }
    
        // Busca por nome
        if (!empty($opts['busca'])) {
            $where .= " AND p.nome LIKE ?";
            $params[] = '%' . $opts['busca'] . '%';
        }
    
        // ─────────────────────────────────────────────────
        $clienteId = Session::isClienteLogado()
                ? (int)Session::get('cliente_id')
                : 0;

        // Critério de desempate OBRIGATÓRIO quando há OFFSET.
        //
        // `criado_em DESC` e `vendidos DESC` empatam com facilidade (produtos
        // cadastrados no mesmo lote, produtos com zero venda), e o MySQL não
        // garante a mesma ordem entre os empatados em duas consultas
        // diferentes. Numa lista inteira isso nunca apareceu — a consulta era
        // uma só. Paginando, a página 2 pula ou repete exatamente os produtos
        // que empataram na fronteira: uma seção de 12 entregava 11.
        //
        // `p.id` é único, então fecha qualquer empate. Fora quando a ordem já
        // é por id — o caso de `ids`, que usa FIELD().
        $desempate = str_contains($order, 'p.id') ? '' : ', p.id DESC';
    
        $stmt = $this->db->prepare(
            "SELECT p.*,
                    pi.arquivo   AS imagem_principal,
                    c.nome       AS categoria_nome,
                    c.slug       AS categoria_slug,
                    m.nome       AS marca_nome,
                    m.slug       AS marca_slug,
                    (
                        SELECT MIN(COALESCE(NULLIF(s.preco_promo, 0), s.preco))
                        FROM produto_skus s
                        WHERE s.produto_id = p.id AND s.ativo = 1 AND s.estoque > 0
                    ) AS preco_min,
                    (
                        SELECT MAX(COALESCE(NULLIF(s.preco_promo, 0), s.preco))
                        FROM produto_skus s
                        WHERE s.produto_id = p.id AND s.ativo = 1 AND s.estoque > 0
                    ) AS preco_max,
                    CASE
                        WHEN ? > 0 THEN (
                            SELECT COUNT(*) > 0
                            FROM wishlist_itens wi
                            JOIN wishlist w ON w.id = wi.wishlist_id
                            WHERE w.cliente_id = ? AND w.padrao = 1 AND wi.produto_id = p.id
                        )
                        ELSE 0
                    END AS favoritado
            FROM produtos p
            LEFT JOIN produto_imagens pi ON pi.produto_id = p.id AND pi.principal = 1
            LEFT JOIN categorias c       ON c.id = p.categoria_id
            LEFT JOIN marcas m           ON m.id = p.marca_id
            WHERE {$where}
            ORDER BY {$order}{$desempate}
            LIMIT ? OFFSET ?"
        );
    
        array_unshift($params, $clienteId, $clienteId);
        $params[] = $limit;
        $params[] = max(0, $offset);
    
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getByFilters(array $opts = [], int $limit = 8, int $offset = 0): array {
        return $this->getList($opts, $limit, $offset);
    }


    // ── Catálogo com filtros ──────────────────────────────────

    /**
     * Listagem completa para catálogo com todos os filtros possíveis.
     */
    
    // app/models/Product.php

    
    public function getCatalog(array $filters = [], int $limit = 24, int $offset = 0): array {
        [$where, $params] = $this->buildFilters($filters);
        $order = $this->buildOrder($filters['ordem'] ?? 'relevancia', $filters['q'] ?? '');

        // Mesmo padrão do getList(): lê clienteId da sessão para marcar favoritos.
        // Zero quando não logado — o CASE WHEN retorna 0 sem subquery.
        $clienteId = Session::isClienteLogado() ? (int)Session::get('cliente_id') : 0;

        $stmt = $this->db->prepare(
            "SELECT DISTINCT p.*,
                    pi.arquivo AS imagem_principal,
                    c.nome     AS categoria_nome,
                    c.slug     AS categoria_slug,
                    m.nome     AS marca_nome,
                    m.slug     AS marca_slug,
                    (
                        SELECT MIN(COALESCE(NULLIF(s.preco_promo, 0), s.preco))
                        FROM produto_skus s
                        WHERE s.produto_id = p.id AND s.ativo = 1 AND s.estoque > 0
                    ) AS preco_min,
                    (
                        SELECT MAX(COALESCE(NULLIF(s.preco_promo, 0), s.preco))
                        FROM produto_skus s
                        WHERE s.produto_id = p.id AND s.ativo = 1 AND s.estoque > 0
                    ) AS preco_max,
                    CASE
                        WHEN ? > 0 THEN (
                            SELECT COUNT(*) > 0
                            FROM wishlist_itens wi
                            JOIN wishlist w ON w.id = wi.wishlist_id
                            WHERE w.cliente_id = ? AND w.padrao = 1 AND wi.produto_id = p.id
                        )
                        ELSE 0
                    END AS favoritado
            FROM produtos p
            LEFT JOIN produto_categorias pc_multi ON pc_multi.produto_id = p.id
            LEFT JOIN produto_imagens pi ON pi.produto_id = p.id AND pi.principal = 1
            LEFT JOIN categorias c ON c.id = p.categoria_id
            LEFT JOIN marcas m     ON m.id = p.marca_id
            WHERE {$where}
            ORDER BY {$order}
            LIMIT ? OFFSET ?"
        );

        // Os dois ? do CASE WHEN ficam antes dos params do WHERE — array_unshift
        // garante a ordem correta igual ao getList()
        array_unshift($params, $clienteId, $clienteId);
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    public function countCatalog(array $filters = []): int {
        [$where, $params] = $this->buildFilters($filters);

        $stmt = $this->db->prepare(
            "SELECT COUNT(DISTINCT p.id)
            FROM produtos p
            LEFT JOIN categorias c ON c.id = p.categoria_id
            LEFT JOIN marcas m     ON m.id  = p.marca_id
            WHERE {$where}"
        );
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }
    

    /**
     * Retorna faixas de preço disponíveis para os filtros.
     */
    public function getPriceRange(array $filters = []): array {
        $baseFilters = $filters;
        unset($baseFilters['preco_min'], $baseFilters['preco_max']);
        [$where, $params] = $this->buildFilters($baseFilters);

        $stmt = $this->db->prepare(
            "SELECT MIN(COALESCE(NULLIF(p.preco_promo,0), p.preco)) AS min_price,
                    MAX(COALESCE(NULLIF(p.preco_promo,0), p.preco)) AS max_price
             FROM produtos p
             LEFT JOIN categorias c ON c.id = p.categoria_id
             LEFT JOIN marcas m     ON m.id  = p.marca_id
             WHERE {$where}"
        );
        $stmt->execute($params);
        return $stmt->fetch();
    }

    /**
     * Retorna marcas disponíveis com contagem de produtos.
     */
    public function getBrandsForFilter(array $filters = []): array {
        $baseFilters = $filters;
        unset($baseFilters['marcas']);
        [$where, $params] = $this->buildFilters($baseFilters);

        $stmt = $this->db->prepare(
            "SELECT m.id, m.nome, m.slug, COUNT(DISTINCT p.id) AS total
             FROM produtos p
             JOIN marcas m ON m.id = p.marca_id
             LEFT JOIN categorias c ON c.id = p.categoria_id
             WHERE {$where}
             GROUP BY m.id, m.nome, m.slug
             ORDER BY m.nome ASC"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // ── Produto individual ────────────────────────────────────

    public function findBySlug(string $slug): ?array {
        $stmt = $this->db->prepare(
            "SELECT p.*,
                    c.nome AS categoria_nome, c.slug AS categoria_slug,
                    m.nome AS marca_nome, m.slug AS marca_slug
             FROM produtos p
             LEFT JOIN categorias c ON c.id = p.categoria_id
             LEFT JOIN marcas m     ON m.id  = p.marca_id
             WHERE p.slug = ? AND p.ativo = 1 AND p.deleted_at IS NULL
             LIMIT 1"
        );
        $stmt->execute([$slug]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Retorna todas as imagens de um produto, principal primeiro.
     */
    public function getImages(int $productId): array {
        $stmt = $this->db->prepare(
            "SELECT * FROM produto_imagens
             WHERE produto_id = ?
             ORDER BY principal DESC, ordem ASC"
        );
        $stmt->execute([$productId]);
        return $stmt->fetchAll();
    }

    /**
     * Retorna variações com suas opções agrupadas.
     * Estrutura: [ { id, nome, opcoes: [ {id, valor, cor_hex, imagem} ] } ]
     */
    public function getVariationsWithOptions(int $productId): array {
        $stmt = $this->db->prepare(
            "SELECT pv.id AS variacao_id, pv.nome AS variacao_nome, pv.ordem AS variacao_ordem,
                    pvo.id AS opcao_id, pvo.valor, pvo.cor_hex, pvo.imagem, pvo.ordem AS opcao_ordem
             FROM produto_variacoes pv
             JOIN produto_variacao_opcoes pvo ON pvo.variacao_id = pv.id
             WHERE pv.produto_id = ?
             ORDER BY pv.ordem ASC, pvo.ordem ASC"
        );
        $stmt->execute([$productId]);
        $rows = $stmt->fetchAll();

        $groups = [];
        foreach ($rows as $row) {
            $vid = $row['variacao_id'];
            if (!isset($groups[$vid])) {
                $groups[$vid] = [
                    'id'     => $vid,
                    'nome'   => $row['variacao_nome'],
                    'ordem'  => $row['variacao_ordem'],
                    'opcoes' => [],
                ];
            }
            $groups[$vid]['opcoes'][] = [
                'id'      => $row['opcao_id'],
                'valor'   => $row['valor'],
                'cor_hex' => $row['cor_hex'],
                'imagem'  => $row['imagem'],
                'ordem'   => $row['opcao_ordem'],
            ];
        }
        return array_values($groups);
    }

    /**
     * Retorna todos os registros de estoque de um produto
     * (um por combinação de variações ou único se sem variação).
     */
    public function getStockMatrix(int $productId): array {
        $stmt = $this->db->prepare(
            "SELECT * FROM produto_estoque WHERE produto_id = ?"
        );
        $stmt->execute([$productId]);
        return $stmt->fetchAll();
    }

    /**
     * Busca estoque por combinação específica de opções.
     * @param string $combinacao Ex: "3-7" (IDs das opções separados por -)
     */
    public function getStockByCombination(int $productId, string $combinacao): ?array {
        $stmt = $this->db->prepare(
            "SELECT * FROM produto_estoque
             WHERE produto_id = ? AND combinacao_opcoes = ?
             LIMIT 1"
        );
        $stmt->execute([$productId, $combinacao]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Produtos relacionados: mesma categoria, excluindo o atual.
     */
    public function getRelated(int $productId, int $categoryId, int $limit = 6, int $offset = 0): array {
        $stmt = $this->db->prepare(
            "SELECT p.*,
                    pi.arquivo AS imagem_principal,
                    c.nome AS categoria_nome, c.slug AS categoria_slug,
                    m.nome AS marca_nome
             FROM produtos p
             LEFT JOIN produto_imagens pi ON pi.produto_id = p.id AND pi.principal = 1
             LEFT JOIN categorias c ON c.id = p.categoria_id
             LEFT JOIN marcas m     ON m.id  = p.marca_id
             WHERE p.ativo = 1
               AND p.deleted_at IS NULL
               AND p.categoria_id = ?
               AND p.id != ?
             ORDER BY p.vendidos DESC, p.visualizacoes DESC, p.id DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute([$categoryId, $productId, $limit, max(0, $offset)]);
        return $stmt->fetchAll();
    }

    /**
     * Incrementa o contador de visualizações.
     */
    public function incrementViews(int $productId): void {
        $this->db->prepare(
            "UPDATE produtos SET visualizacoes = visualizacoes + 1 WHERE id = ?"
        )->execute([$productId]);
    }

    // ── Avaliações ────────────────────────────────────────────

    public function getReviews(int $productId, int $limit = 10, int $offset = 0): array {
        $stmt = $this->db->prepare(
            "SELECT av.*, u.nome AS cliente_nome, c.avatar
             FROM avaliacoes av
             JOIN clientes c  ON c.id = av.cliente_id
             JOIN usuarios u  ON u.id = c.usuario_id
             WHERE av.produto_id = ? AND av.aprovado = 1
             ORDER BY av.criado_em DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute([$productId, $limit, $offset]);
        return $stmt->fetchAll();
    }

    public function getReviewStats(int $productId): array {
        $stmt = $this->db->prepare(
            "SELECT
               COUNT(*) AS total,
               ROUND(AVG(nota), 1) AS media,
               SUM(nota = 5) AS cinco,
               SUM(nota = 4) AS quatro,
               SUM(nota = 3) AS tres,
               SUM(nota = 2) AS dois,
               SUM(nota = 1) AS um
             FROM avaliacoes
             WHERE produto_id = ? AND aprovado = 1"
        );
        $stmt->execute([$productId]);
        return $stmt->fetch();
    }

    public function saveReview(array $data): int {
        // Verifica se o cliente já avaliou este produto neste pedido
        if (!empty($data['pedido_id'])) {
            $exists = $this->db->prepare(
                "SELECT id FROM avaliacoes
                 WHERE cliente_id = ? AND produto_id = ? AND pedido_id = ? LIMIT 1"
            );
            $exists->execute([$data['cliente_id'], $data['produto_id'], $data['pedido_id']]);
            if ($exists->fetchColumn()) {
                throw new RuntimeException('Você já avaliou este produto neste pedido.');
            }
        }

        return $this->insert([
            'produto_id'  => $data['produto_id'],
            'cliente_id'  => $data['cliente_id'],
            'pedido_id'   => $data['pedido_id']  ?? null,
            'nota'        => min(5, max(1, (int) $data['nota'])),
            'titulo'      => mb_substr($data['titulo'] ?? '', 0, 150),
            'comentario'  => $data['comentario'] ?? null,
            'aprovado'    => 0, // requer aprovação do admin
        ]);
    }

    public function clientePodeAvaliar(int $clienteId, int $productId): bool {
        // Verifica se o cliente comprou e recebeu o produto
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM pedido_itens pi
             JOIN pedidos p ON p.id = pi.pedido_id
             WHERE p.cliente_id = ?
               AND pi.produto_id = ?
               AND p.status_pedido = 'entregue'
             LIMIT 1"
        );
        $stmt->execute([$clienteId, $productId]);
        return (bool) $stmt->fetchColumn();
    }

    // ── Helpers internos ──────────────────────────────────────

    
    private function buildFilters(array $f): array {
        $where  = "p.ativo = 1 AND p.deleted_at IS NULL";
        $params = [];

        // ── Categoria (mantém como está) ──────────────────────
        if (!empty($f['categoria_id'])) {
            $ids = $this->getCategoryIds((int)$f['categoria_id']);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $where  .= " AND EXISTS (
                SELECT 1 FROM produto_categorias pc_cat
                WHERE pc_cat.produto_id   = p.id
                AND pc_cat.categoria_id IN ({$placeholders})
            )";
            $params = array_merge($params, $ids);
        }

        // ── Filtro de moto — AND EXISTS separado ──────────────
        // Só aplica se tiver montadora selecionada
        if (!empty($f['montadora_id'])) {
            $montId  = (int)$f['montadora_id'];
            $modId   = !empty($f['modelo_id']) ? (int)$f['modelo_id'] : null;
            $ano     = !empty($f['ano'])        ? (int)$f['ano']       : null;

            // Base: produto compatível com a montadora
            $subWhere  = "pc_moto.produto_id   = p.id
                    AND pc_moto.montadora_id = ?";
            $subParams = [$montId];

            // Refinamento por modelo (ou sem modelo = toda a montadora)
            if ($modId) {
                $subWhere   .= " AND (pc_moto.modelo_id = ? OR pc_moto.modelo_id IS NULL)";
                $subParams[] = $modId;
            }

            // Refinamento por ano
            if ($ano) {
                $subWhere   .= " AND (
                    (pc_moto.ano_inicio IS NULL AND pc_moto.ano_fim   IS NULL)
                    OR (pc_moto.ano_inicio IS NULL AND pc_moto.ano_fim   >= ?)
                    OR (pc_moto.ano_fim   IS NULL AND pc_moto.ano_inicio <= ?)
                    OR (pc_moto.ano_inicio <= ?   AND pc_moto.ano_fim    >= ?)
                )";
                $subParams[] = $ano;
                $subParams[] = $ano;
                $subParams[] = $ano;
                $subParams[] = $ano;
            }

            $where  .= " AND EXISTS (
                SELECT 1 FROM produto_compatibilidade pc_moto
                WHERE {$subWhere}
            )";
            $params = array_merge($params, $subParams);
        }

        // ── Marca ─────────────────────────────────────────────
        if (!empty($f['marca_id'])) {
            $where   .= " AND p.marca_id = ?";
            $params[] = (int)$f['marca_id'];
        }
        if (!empty($f['marcas']) && is_array($f['marcas'])) {
            $placeholders = implode(',', array_fill(0, count($f['marcas']), '?'));
            $where  .= " AND p.marca_id IN ({$placeholders})";
            $params  = array_merge($params, array_map('intval', $f['marcas']));
        }

        // ── Preço ─────────────────────────────────────────────
        // Mesma lógica de preço misto do buildOrder(): produto com SKU usa
        // o menor preço de variação; produto simples usa p.preco/p.preco_promo.
        // Sem isso, filtrar "até R$200" excluía produtos com SKU barato cujo
        // p.preco base estava acima do filtro.
        if (!empty($f['preco_min']) && (float)$f['preco_min'] > 0) {
            $where .= " AND COALESCE(
                (SELECT MIN(COALESCE(NULLIF(s.preco_promo,0), s.preco))
                FROM produto_skus s
                WHERE s.produto_id = p.id AND s.ativo = 1 AND s.estoque > 0),
                NULLIF(p.preco_promo,0),
                p.preco
            ) >= ?";
            $params[] = (float)$f['preco_min'];
        }
        if (!empty($f['preco_max']) && (float)$f['preco_max'] > 0) {
            $where .= " AND COALESCE(
                (SELECT MIN(COALESCE(NULLIF(s.preco_promo,0), s.preco))
                FROM produto_skus s
                WHERE s.produto_id = p.id AND s.ativo = 1 AND s.estoque > 0),
                NULLIF(p.preco_promo,0),
                p.preco
            ) <= ?";
            $params[] = (float)$f['preco_max'];
        }

        // ── Promoção ──────────────────────────────────────────
        if (!empty($f['em_promocao'])) {
            $where .= " AND p.preco_promo IS NOT NULL AND p.preco_promo > 0
                        AND (p.promo_inicio IS NULL OR p.promo_inicio <= NOW())
                        AND (p.promo_fim    IS NULL OR p.promo_fim    >= NOW())";
        }

        // ── Estoque ───────────────────────────────────────────
        if (!empty($f['com_estoque'])) {
            $where .= " AND p.estoque_total > 0";
        }

        // ── Busca textual ─────────────────────────────────────
        // Inclui busca por tag (EXISTS, não JOIN — evita duplicar linhas
        // sem precisar de DISTINCT extra) — recurso que existia na busca
        // antiga isolada e foi preservado aqui na unificação.
        if (!empty($f['q'])) {
            $term  = SecurityHelper::sanitizeSearch($f['q']);
            $like  = '%' . $term . '%';
            $where .= " AND (
                MATCH(p.nome, p.descricao_curta, p.descricao, p.meta_keywords, p.sku_legado)
                AGAINST(? IN BOOLEAN MODE)
                OR p.nome      LIKE ?
                OR p.sku_legado LIKE ?
                OR EXISTS (
                    SELECT 1 FROM produto_tags pt_q
                    JOIN tags t_q ON t_q.id = pt_q.tag_id
                    WHERE pt_q.produto_id = p.id AND t_q.nome LIKE ?
                )
            )";
            array_push($params, $term . '*', $like, $like, $like);
        }

        // ── Atributos de SKU (Tamanho, Cor, etc.) ──────────────
        // Recebe $f['atributos'] como ['Tamanho' => ['42','44'], 'Cor' => ['Preto']]
        // AND entre tipos diferentes, OR dentro do mesmo tipo.
        if (!empty($f['atributos']) && is_array($f['atributos'])) {
            foreach ($f['atributos'] as $tipo => $valores) {
                $valores = array_filter((array)$valores, fn($v) => $v !== '');
                if (empty($valores)) continue;
                $in     = implode(',', array_fill(0, count($valores), '?'));
                $where .= " AND EXISTS (
                    SELECT 1 FROM produto_skus ps_at
                    JOIN sku_atributos sa_at  ON sa_at.sku_id      = ps_at.id
                    JOIN atributo_tipos at_at ON at_at.id           = sa_at.atributo_tipo_id
                    WHERE ps_at.produto_id = p.id
                    AND ps_at.ativo      = 1
                    AND at_at.nome       = ?
                    AND sa_at.valor      IN ({$in})
                )";
                $params[] = $tipo;
                foreach ($valores as $v) $params[] = $v;
            }
        }

        // ── Características do produto (sistema diferente de atributos/SKU) ──
        // Tabela: produto_caracteristicas + caracteristicas (não usa SKU).
        // Recebe $f['caracteristicas'] como ['Idade' => ['Adultos'], 'Com Viseira Solar' => ['Sim']]
        if (!empty($f['caracteristicas']) && is_array($f['caracteristicas'])) {
            foreach ($f['caracteristicas'] as $nomeChar => $valores) {
                $valores = array_filter((array)$valores, fn($v) => $v !== '');
                if (empty($valores)) continue;
                $in     = implode(',', array_fill(0, count($valores), '?'));
                $where .= " AND EXISTS (
                    SELECT 1 FROM produto_caracteristicas pcar_f
                    JOIN caracteristicas c_f ON c_f.id = pcar_f.caracteristica_id
                    WHERE pcar_f.produto_id = p.id
                    AND c_f.nome  = ?
                    AND pcar_f.valor IN ({$in})
                )";
                $params[] = $nomeChar;
                foreach ($valores as $v) $params[] = $v;
            }
        }

        return [$where, $params];
    }

    /**
     * Monta o ORDER BY do catálogo.
     *
     * IMPORTANTE — preço misto (produto simples vs. produto com SKU):
     * produtos com variação (tem_variacao=1) têm o preço real em
     * produto_skus, não em p.preco/p.preco_promo. As subqueries abaixo
     * cobrem os dois casos: se houver SKU ativo com estoque, usa o menor
     * preço de SKU; senão, cai no preço do produto simples.
     *
     * Mesma lógica usada em getCatalog() para preco_min/preco_max —
     * mantém ordenação e exibição consistentes.
     */
    /**
     * Monta o ORDER BY do catálogo.
     *
     * $termoBusca: quando informado e $ordem='relevancia' (padrão), prioriza
     * nome exato/match parcial do termo antes do critério padrão de
     * destaque — mesma lógica que existia em Product::search() isolado,
     * agora unificada aqui para servir categoria, moto e busca por igual.
     *
     * IMPORTANTE — preço misto (produto simples vs. produto com SKU):
     * produtos com variação (tem_variacao=1) têm o preço real em
     * produto_skus, não em p.preco/p.preco_promo. As subqueries abaixo
     * cobrem os dois casos: se houver SKU ativo com estoque, usa o menor
     * preço de SKU; senão, cai no preço do produto simples.
     *
     * Mesma lógica usada em getCatalog() para preco_min/preco_max —
     * mantém ordenação e exibição consistentes.
     */
    private function buildOrder(string $ordem, string $termoBusca = ''): string {
        // ── Prioridade de estoque ──────────────────────────────
        // Produtos com disponível real (saldo - reservado > 0) em
        // QUALQUER linha de estoque_saldo vêm sempre primeiro, antes
        // de qualquer outro critério de ordenação escolhido pelo
        // usuário. Cobre produto simples (sku_id NULL) e produto com
        // variação (uma linha por SKU) — basta UMA linha disponível.
        $temEstoque = "
            EXISTS (
                SELECT 1 FROM estoque_saldo es
                WHERE es.produto_id = p.id
                  AND (es.saldo - es.reservado) > 0
            ) DESC
        ";

        $precoAtual = "
            COALESCE(
                (SELECT MIN(COALESCE(NULLIF(s.preco_promo,0), s.preco))
                 FROM produto_skus s
                 WHERE s.produto_id = p.id AND s.ativo = 1 AND s.estoque > 0),
                NULLIF(p.preco_promo,0),
                p.preco
            )
        ";

        $precoCheio = "
            COALESCE(
                (SELECT MIN(s.preco)
                 FROM produto_skus s
                 WHERE s.produto_id = p.id AND s.ativo = 1 AND s.estoque > 0),
                p.preco
            )
        ";

        // ── Relevância de busca textual (pontuação por tokens) ──
        // Quebra o termo em palavras e soma pontos por cada uma que
        // aparece no nome do produto. Token que bate o NOME de uma
        // marca cadastrada ganha peso extra (+3) — sinal forte de
        // intenção ("capacete asx" deve priorizar produtos da marca
        // ASX antes de outros capacetes genéricos).
        //
        // Resolve o caso relatado: buscar "capacete asx" antes só
        // verificava a frase inteira como substring (match binário),
        // então qualquer "Capacete X" empatava com "Capacete ASX" e a
        // ordem ficava arbitrária. Agora "Capacete ASX Eagle" pontua
        // mais (1 + 3 = 4) que "Capacete LS2" (1, só bate "capacete").
        $relevanciaBusca = '';
        if ($termoBusca !== '' && in_array($ordem, ['relevancia', ''], true)) {
            $tokens = array_filter(
                preg_split('/\s+/', trim($termoBusca)),
                fn($t) => mb_strlen($t) >= 2
            );

            // Limite de segurança: termos absurdamente longos não devem
            // gerar uma query com dezenas de subqueries no ORDER BY.
            $tokens = array_slice($tokens, 0, 6);

            if (!empty($tokens)) {
                $tokens = array_values($tokens);
                $partesPontuacao = [];

                foreach ($tokens as $i => $token) {
                    $tokenLike = $this->db->quote('%' . $token . '%');

                    // +1 ponto: token aparece no nome do produto
                    $partesPontuacao[] = "IF(p.nome LIKE {$tokenLike}, 1, 0)";

                    // +3 pontos: token bate o nome de uma marca cadastrada.
                    // Só conta se ALGUM OUTRO token também aparecer no nome
                    // — senão, qualquer produto da marca ganha o bônus só
                    // por pertencer a ela (ex: "capacete asx" inflava toda
                    // bota/jaqueta/luva ASX, não só o capacete). Quando há
                    // um único token na busca, não existe "outro" para
                    // exigir — o bônus de marca vale isolado nesse caso
                    // (preserva o comportamento já correto de buscar só "asx").
                    $outrosTokens = array_filter(
                        $tokens,
                        fn($t, $j) => $j !== $i,
                        ARRAY_FILTER_USE_BOTH
                    );

                    if (empty($outrosTokens)) {
                        $exigeOutroMatch = '1';
                    } else {
                        $condOutros = array_map(
                            fn($t) => "p.nome LIKE " . $this->db->quote('%' . $t . '%'),
                            $outrosTokens
                        );
                        $exigeOutroMatch = '(' . implode(' OR ', $condOutros) . ')';
                    }

                    $partesPontuacao[] = "IF(
                        {$exigeOutroMatch}
                        AND EXISTS (
                            SELECT 1 FROM marcas mb
                            WHERE mb.id = p.marca_id
                              AND mb.nome LIKE {$tokenLike}
                        ),
                        3, 0
                    )";
                }
                $somaScore = implode(' + ', $partesPontuacao);
                $relevanciaBusca = "({$somaScore}) DESC, ";
            }
        }

        $resto = match($ordem) {
            'menor_preco'    => "{$precoAtual} ASC",
            'maior_preco'    => "{$precoAtual} DESC",
            'maior_desconto' => "
                CASE WHEN {$precoCheio} > 0
                     THEN (1 - ({$precoAtual} / {$precoCheio}))
                     ELSE 0
                END DESC
            ",
            'mais_vendidos'  => "p.vendidos DESC",
            'mais_vistos'    => "p.visualizacoes DESC",
            'novidades'      => "p.criado_em DESC",
            'destaque'       => "p.destaque DESC, p.vendidos DESC",
            default          => "p.destaque DESC, p.vendidos DESC, p.criado_em DESC",
        };

        // `p.id DESC` fecha os empates. O catálogo já paginava sem isto e
        // sofria do mesmo problema em silêncio: com a ordem padrão
        // (destaque, vendidos, criado_em), todo produto sem venda empata com
        // os outros sem venda, e rolar a lista podia pular itens.
        return "{$temEstoque}, {$relevanciaBusca}{$resto}, p.id DESC";
    }

    /**
     * Retorna IDs da categoria e todas as suas subcategorias recursivamente.
     */
    private function getCategoryIds(int $categoryId): array {
        $ids  = [$categoryId];
        $stmt = $this->db->prepare(
            "SELECT id FROM categorias WHERE parent_id = ? AND ativo = 1"
        );
        $stmt->execute([$categoryId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $childId) {
            $ids = array_merge($ids, $this->getCategoryIds((int)$childId));
        }
        return $ids;
    }

    /**
     * Verifica se o produto está em alguma categoria (ou ancestral)
     * que tenha o flag busca_moto = 1.
     *
     * Usa CTE recursiva para subir a árvore de ancestrais em uma única query.
     * Cache estático evita reconsulta dentro do mesmo request.
     */
    // public static function temBuscaMoto(int $produtoId): bool {
    //     static $cache = [];
    //     if (isset($cache[$produtoId])) {
    //         return $cache[$produtoId];
    //     }

    //     $sql = "
    //         WITH RECURSIVE arvore AS (
    //             -- Categorias diretas do produto
    //             SELECT c.id, c.parent_id, c.busca_moto
    //             FROM produto_categorias pc
    //             JOIN categorias c ON c.id = pc.categoria_id
    //             WHERE pc.produto_id = ?

    //             UNION

    //             -- Sobe pelos ancestrais
    //             SELECT c.id, c.parent_id, c.busca_moto
    //             FROM categorias c
    //             JOIN arvore a ON c.id = a.parent_id
    //         )
    //         SELECT 1
    //         FROM arvore
    //         WHERE busca_moto = 1
    //         LIMIT 1
    //     ";

    //     $db = Database::getInstance()->getConnection();
    //     $stmt = $db->prepare($sql);
    //     $stmt->execute([$produtoId]);

    //     $cache[$produtoId] = (bool) $stmt->fetchColumn();
    //     return $cache[$produtoId];
    // }

    public static function temBuscaMoto(int $produtoId): bool
    {
        static $cache = [];

        if (isset($cache[$produtoId])) {
            return $cache[$produtoId];
        }

        $db = Database::getInstance()->getConnection();

        // 1. Busca categorias diretas do produto
        $stmt = $db->prepare("
            SELECT c.id, c.parent_id, c.busca_moto
            FROM produto_categorias pc
            INNER JOIN categorias c ON c.id = pc.categoria_id
            WHERE pc.produto_id = ?
        ");
        $stmt->execute([$produtoId]);

        $categorias = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$categorias) {
            $cache[$produtoId] = false;
            return false;
        }

        foreach ($categorias as $categoria) {
            // Categoria direta já está marcada
            if ((int) $categoria['busca_moto'] === 1) {
                $cache[$produtoId] = true;
                return true;
            }

            // Sobe nos pais
            if (!empty($categoria['parent_id'])) {
                if (self::categoriaPaiTemBuscaMoto((int) $categoria['parent_id'])) {
                    $cache[$produtoId] = true;
                    return true;
                }
            }
        }

        $cache[$produtoId] = false;
        return false;
    }

    private static function categoriaPaiTemBuscaMoto(int $categoriaId): bool
    {
        static $cachePais = [];

        if (isset($cachePais[$categoriaId])) {
            return $cachePais[$categoriaId];
        }

        $db = Database::getInstance()->getConnection();

        while ($categoriaId > 0) {
            if (isset($cachePais[$categoriaId])) {
                return $cachePais[$categoriaId];
            }

            $stmt = $db->prepare("
                SELECT id, parent_id, busca_moto
                FROM categorias
                WHERE id = ?
                LIMIT 1
            ");
            $stmt->execute([$categoriaId]);

            $categoria = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$categoria) {
                $cachePais[$categoriaId] = false;
                return false;
            }

            if ((int) $categoria['busca_moto'] === 1) {
                $cachePais[$categoriaId] = true;
                return true;
            }

            $categoriaId = (int) ($categoria['parent_id'] ?? 0);
        }

        return false;
    }

    /**
     * SNIPPET 1 — adicionar este método em app/models/Product.php
     * Retorna os atributos disponíveis (agrupados por tipo) para o conjunto
     * filtrado atual. Usa a mesma buildFilters() que getCatalog(), então
     * os atributos exibidos correspondem sempre ao que está na tela.
     */
    public function getAttributesForFilter(array $filters = []): array {
        [$where, $params] = $this->buildFilters($filters);
    
        $stmt = $this->db->prepare(
            "SELECT at.nome AS tipo, sa.valor, COUNT(DISTINCT p.id) AS total
            FROM produtos p
            LEFT JOIN produto_categorias pc_multi ON pc_multi.produto_id = p.id
            JOIN produto_skus ps  ON ps.produto_id = p.id  AND ps.ativo = 1
            JOIN sku_atributos sa ON sa.sku_id      = ps.id
            JOIN atributo_tipos at ON at.id         = sa.atributo_tipo_id
            WHERE {$where}
            AND sa.valor IS NOT NULL AND sa.valor != ''
            GROUP BY at.nome, sa.valor
            ORDER BY at.nome ASC, sa.valor ASC"
        );
        $stmt->execute($params);
    
        $grouped = [];
        foreach ($stmt->fetchAll() as $row) {
            $grouped[$row['tipo']][] = ['valor' => $row['valor'], 'total' => (int)$row['total']];
        }
        return $grouped;
    }

    /**
     * Características preenchidas de UM produto, prontas para exibir
     * (nome, tipo, unidade, valor) — usado em views/products/detail.php.
     * Sistema diferente de atributos/SKU: caracteristicas + produto_caracteristicas.
     */
    public function getCaracteristicas(int $produtoId): array {
        $stmt = $this->db->prepare(
            "SELECT c.nome, c.slug, c.tipo, c.unidade, pcar.valor,
                    COALESCE(cc.ordem, c.ordem) AS ordem
             FROM produto_caracteristicas pcar
             JOIN caracteristicas c ON c.id = pcar.caracteristica_id AND c.ativo = 1
             LEFT JOIN categoria_caracteristicas cc
                    ON cc.caracteristica_id = c.id
                   AND cc.categoria_id = (SELECT categoria_id FROM produtos WHERE id = ?)
             WHERE pcar.produto_id = ?
               AND pcar.valor IS NOT NULL AND pcar.valor != ''
             ORDER BY ordem ASC, c.nome ASC"
        );
        $stmt->execute([$produtoId, $produtoId]);
        return $stmt->fetchAll();
    }

    /**
     * Características disponíveis para filtro lateral, agrupadas por nome.
     * Usa a mesma buildFilters() de getCatalog() — os valores exibidos
     * sempre refletem o que está filtrado na tela.
     *
     * Só retorna tipos 'select' e 'boolean': texto livre (texto/textarea/
     * numero/url) não faz sentido como checkbox de filtro.
     */
    public function getCaracteristicasForFilter(array $filters = []): array {
        [$where, $params] = $this->buildFilters($filters);

        $stmt = $this->db->prepare(
            "SELECT c.nome AS tipo, pcar.valor, COUNT(DISTINCT p.id) AS total
             FROM produtos p
             LEFT JOIN produto_categorias pc_multi ON pc_multi.produto_id = p.id
             JOIN produto_caracteristicas pcar ON pcar.produto_id = p.id
             JOIN caracteristicas c ON c.id = pcar.caracteristica_id
                                     AND c.ativo = 1
                                     AND c.tipo IN ('select', 'boolean')
             WHERE {$where}
               AND pcar.valor IS NOT NULL AND pcar.valor != ''
             GROUP BY c.nome, pcar.valor
             ORDER BY MIN(c.ordem) ASC, c.nome ASC, pcar.valor ASC"
        );
        $stmt->execute($params);

        $grouped = [];
        foreach ($stmt->fetchAll() as $row) {
            $grouped[$row['tipo']][] = ['valor' => $row['valor'], 'total' => (int)$row['total']];
        }
        return $grouped;
    }
}