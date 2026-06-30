<?php
declare(strict_types=1);

class MotoCompatibilidade extends Model {

    protected string $table = 'produto_compatibilidade';

    /**
     * Retorna produtos compatíveis com uma moto específica.
     * Resolve montadora + modelo + ano, aplicando lógica de faixas.
     */
    public function getProdutosCompativeis(
        int    $montadoraId,
        ?int   $modeloId = null,
        ?int   $ano      = null,
        int    $limit    = 24,
        int    $offset   = 0,
        array  $filtros  = []
    ): array {

        $where  = "pc.montadora_id = ?";
        $params = [$montadoraId];

        // Modelo: específico ou qualquer modelo da montadora
        if ($modeloId) {
            $where   .= " AND (pc.modelo_id = ? OR pc.modelo_id IS NULL)";
            $params[] = $modeloId;
        }

        // Ano: dentro da faixa ou sem restrição
        if ($ano) {
            $where   .= " AND (
                (pc.ano_inicio IS NULL AND pc.ano_fim IS NULL)
                OR (pc.ano_inicio IS NULL AND pc.ano_fim   >= ?)
                OR (pc.ano_fim   IS NULL AND pc.ano_inicio <= ?)
                OR (pc.ano_inicio <= ? AND pc.ano_fim >= ?)
            )";
            $params[] = $ano;
            $params[] = $ano;
            $params[] = $ano;
            $params[] = $ano;
        }

        // Filtros adicionais de produto
        $whereProd  = "p.ativo = 1 AND p.deleted_at IS NULL";
        $paramsProd = [];

        if (!empty($filtros['categoria_id'])) {
            $whereProd   .= " AND p.categoria_id = ?";
            $paramsProd[] = (int)$filtros['categoria_id'];
        }
        if (!empty($filtros['marca_id'])) {
            $whereProd   .= " AND p.marca_id = ?";
            $paramsProd[] = (int)$filtros['marca_id'];
        }
        if (!empty($filtros['q'])) {
            $like         = '%' . SecurityHelper::sanitizeSearch($filtros['q']) . '%';
            $whereProd   .= " AND p.nome LIKE ?";
            $paramsProd[] = $like;
        }

        $allParams = array_merge($params, $paramsProd);

        // Mesmo padrão do Product::getCatalog() — lê clienteId da sessão
        // para marcar favoritos. Zero quando não logado.
        $clienteId = Session::isClienteLogado() ? (int)Session::get('cliente_id') : 0;

        $order = $this->buildOrder($filtros['ordem'] ?? 'relevancia');

        $stmt = $this->db->prepare(
            "SELECT DISTINCT p.*,
                    pi.arquivo AS imagem_principal,
                    c.nome     AS categoria_nome,
                    m.nome     AS marca_nome,
                    m.slug     AS marca_slug,
                    pc.observacao AS compat_obs,
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
             FROM produto_compatibilidade pc
             JOIN produtos p ON p.id = pc.produto_id
             LEFT JOIN produto_imagens pi ON pi.produto_id = p.id AND pi.principal = 1
             LEFT JOIN categorias c ON c.id = p.categoria_id
             LEFT JOIN marcas m     ON m.id = p.marca_id
             WHERE {$where} AND {$whereProd}
             ORDER BY {$order}
             LIMIT ? OFFSET ?"
        );

        // Os dois ? do CASE WHEN vão ANTES dos params do WHERE/whereProd —
        // mesma técnica do array_unshift usada em Product::getCatalog()
        array_unshift($allParams, $clienteId, $clienteId);
        $stmt->execute(array_merge($allParams, [$limit, $offset]));
        return $stmt->fetchAll();
    }

    /**
     * Monta o ORDER BY do catálogo por moto.
     * Mesma lógica de preço misto do Product::buildOrder() — produto
     * com SKU usa o menor preço de variação; produto simples usa
     * p.preco/p.preco_promo direto.
     */
    private function buildOrder(string $ordem): string {
        // ── Prioridade de estoque (mesma regra do Product::buildOrder) ──
        // Produtos com disponível real vêm sempre primeiro, antes de
        // qualquer outro critério escolhido pelo usuário.
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
            default          => "p.destaque DESC, p.vendidos DESC",
        };

        return "{$temEstoque}, {$resto}";
    }

    public function countCompativeis(
        int  $montadoraId,
        ?int $modeloId = null,
        ?int $ano      = null,
        array $filtros = []
    ): int {
        $where  = "pc.montadora_id = ?";
        $params = [$montadoraId];

        if ($modeloId) {
            $where   .= " AND (pc.modelo_id = ? OR pc.modelo_id IS NULL)";
            $params[] = $modeloId;
        }
        if ($ano) {
            $where   .= " AND (
                (pc.ano_inicio IS NULL AND pc.ano_fim IS NULL)
                OR (pc.ano_inicio IS NULL AND pc.ano_fim   >= ?)
                OR (pc.ano_fim   IS NULL AND pc.ano_inicio <= ?)
                OR (pc.ano_inicio <= ? AND pc.ano_fim >= ?)
            )";
            $params[] = $ano; $params[] = $ano;
            $params[] = $ano; $params[] = $ano;
        }

        // Mesmos filtros de produto da listagem (getProdutosCompativeis) —
        // sem isso, buscar por marca/texto deixa o total de páginas errado
        // (maior que o real), gerando páginas vazias no fim da paginação.
        $whereProd = "p.ativo = 1 AND p.deleted_at IS NULL";
        if (!empty($filtros['categoria_id'])) {
            $whereProd .= " AND p.categoria_id = ?";
            $params[]   = (int)$filtros['categoria_id'];
        }
        if (!empty($filtros['marca_id'])) {
            $whereProd .= " AND p.marca_id = ?";
            $params[]   = (int)$filtros['marca_id'];
        }
        if (!empty($filtros['q'])) {
            $whereProd .= " AND p.nome LIKE ?";
            $params[]   = '%' . SecurityHelper::sanitizeSearch($filtros['q']) . '%';
        }

        $stmt = $this->db->prepare(
            "SELECT COUNT(DISTINCT p.id)
             FROM produto_compatibilidade pc
             JOIN produtos p ON p.id = pc.produto_id
             WHERE {$where} AND {$whereProd}"
        );
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Salva compatibilidades de um produto.
     */
    public function salvarCompatibildades(int $produtoId, array $itens): void {
        $this->db->prepare(
            "DELETE FROM produto_compatibilidade WHERE produto_id = ?"
        )->execute([$produtoId]);

        if (empty($itens)) return;

        $stmt = $this->db->prepare(
            "INSERT INTO produto_compatibilidade
             (produto_id, montadora_id, modelo_id, ano_inicio, ano_fim, observacao)
             VALUES (?,?,?,?,?,?)"
        );

        foreach ($itens as $item) {
            $montId    = (int)($item['montadora_id'] ?? 0);
            $modelId   = !empty($item['modelo_id']) ? (int)$item['modelo_id'] : null;
            $anoIni    = !empty($item['ano_inicio']) ? (int)$item['ano_inicio'] : null;
            $anoFim    = !empty($item['ano_fim'])    ? (int)$item['ano_fim']    : null;
            $obs       = SecurityHelper::sanitizeString($item['observacao'] ?? '');

            if (!$montId) continue;

            $stmt->execute([$produtoId, $montId, $modelId, $anoIni, $anoFim, $obs ?: null]);
        }
    }

    /**
     * Compatibilidades de um produto para exibir no editor.
     */
    public function getDoProduct(int $produtoId): array {
        $stmt = $this->db->prepare(
            "SELECT pc.*,
                    mm.nome  AS montadora_nome,
                    mm.slug  AS montadora_slug,
                    mo.nome  AS modelo_nome,
                    mo.slug  AS modelo_slug
             FROM produto_compatibilidade pc
             JOIN moto_montadoras mm ON mm.id = pc.montadora_id
             LEFT JOIN moto_modelos mo ON mo.id = pc.modelo_id
             WHERE pc.produto_id = ?
             ORDER BY mm.nome ASC, mo.nome ASC"
        );
        $stmt->execute([$produtoId]);
        return $stmt->fetchAll();
    }

    /**
     * Resolve montadora/modelo/ano a partir de slugs de URL.
     */
    public function resolveUrl(
        string  $montadoraSlug,
        ?string $modeloSlug = null,
        ?int    $ano        = null
    ): array {
        $stmt = $this->db->prepare(
            "SELECT id, nome, slug, logo, thumb, fipe_codigo
             FROM moto_montadoras WHERE slug = ? AND ativo = 1 LIMIT 1"
        );
        $stmt->execute([$montadoraSlug]);
        $montadora = $stmt->fetch();
        if (!$montadora) return [];

        $modelo = null;
        if ($modeloSlug) {
            $stmt = $this->db->prepare(
                "SELECT id, nome, slug, thumb, cilindrada, tipo
                 FROM moto_modelos WHERE montadora_id=? AND slug=? AND ativo=1 LIMIT 1"
            );
            $stmt->execute([$montadora['id'], $modeloSlug]);
            $modelo = $stmt->fetch() ?: null;
        }

        return compact('montadora', 'modelo', 'ano');
    }
}