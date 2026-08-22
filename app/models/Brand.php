<?php
// app/models/Brand.php
//
// NOTA: este arquivo existia vazio (0 bytes) no projeto — um stub que nunca foi
// preenchido. BrandController e index.php consultam `marcas` com SQL cru.
// A API do app precisava dessas consultas, e escrevê-las inline no controller
// espalharia ainda mais o mesmo SELECT. O model é aditivo: nada existente
// referencia esta classe, então preenchê-lo não altera o comportamento da loja.

class Brand extends Model {

    protected string $table = 'marcas';

    /** Marcas ativas, com a contagem de produtos publicados de cada uma. */
    public function getActive(bool $apenasComProdutos = false, int $limit = 0): array {
        $having = $apenasComProdutos ? "HAVING total_produtos > 0" : "";
        $limite = $limit > 0 ? "LIMIT {$limit}" : "";

        return $this->db->query(
            "SELECT m.id, m.nome, m.slug, m.logo, m.bg_cor, m.destaque, m.site, m.descricao,
                    COUNT(p.id) AS total_produtos
             FROM marcas m
             LEFT JOIN produtos p
                    ON p.marca_id = m.id AND p.ativo = 1 AND p.deleted_at IS NULL
             WHERE m.ativo = 1
             GROUP BY m.id
             {$having}
             ORDER BY m.nome ASC
             {$limite}"
        )->fetchAll();
    }

    /** Marcas marcadas como destaque — o carrossel de marcas da home. */
    public function getDestaques(int $limit = 12): array {
        $stmt = $this->db->prepare(
            "SELECT m.id, m.nome, m.slug, m.logo, m.bg_cor, m.destaque,
                    COUNT(p.id) AS total_produtos
             FROM marcas m
             LEFT JOIN produtos p
                    ON p.marca_id = m.id AND p.ativo = 1 AND p.deleted_at IS NULL
             WHERE m.ativo = 1 AND m.destaque = 1
             GROUP BY m.id
             ORDER BY total_produtos DESC, m.nome ASC
             LIMIT ?"
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function findBySlug(string $slug): ?array {
        $stmt = $this->db->prepare(
            "SELECT * FROM marcas WHERE slug = ? AND ativo = 1 LIMIT 1"
        );
        $stmt->execute([$slug]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Resolve uma lista de slugs para ids.
     * O app filtra por slug (é o que aparece no deep link e na URL
     * compartilhada); Product::buildFilters() só entende id.
     *
     * @param  string[] $slugs
     * @return int[]
     */
    public function idsPorSlugs(array $slugs): array {
        $slugs = array_values(array_filter(array_map('strval', $slugs)));
        if (!$slugs) return [];

        $in   = implode(',', array_fill(0, count($slugs), '?'));
        $stmt = $this->db->prepare(
            "SELECT id FROM marcas WHERE slug IN ({$in}) AND ativo = 1"
        );
        $stmt->execute($slugs);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
