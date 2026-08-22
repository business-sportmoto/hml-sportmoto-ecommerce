<?php
// app/services/app/ProdutoCompatRepo.php
// Consultas de compatibilidade produto ↔ moto, sempre em lote.
//
// Product::temBuscaMoto() (Product.php:1037) responde por UM produto e ainda
// sobe a árvore de categorias com um SELECT por nível em
// categoriaPaiTemBuscaMoto(). Num card isso é aceitável; numa listagem de API
// com 18 produtos por seção vira dezenas de queries.
//
// Aqui a mesma pergunta é respondida para o lote inteiro com UMA query, usando
// a CTE recursiva que o MySQL 8 suporta — inclusive já esboçada e comentada no
// próprio Product.php:1008. O comportamento é idêntico: o produto "tem busca
// moto" se qualquer categoria sua, ou qualquer ancestral dela, tiver
// busca_moto = 1.

class ProdutoCompatRepo
{
    /**
     * @param  int[] $produtoIds
     * @return array<int,bool> mapa produto_id => tem busca de moto
     */
    public static function temBuscaMotoEmLote(array $produtoIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $produtoIds)));
        if (!$ids) {
            return [];
        }

        $mapa = array_fill_keys($ids, false);
        $in   = implode(',', array_fill(0, count($ids), '?'));

        $sql = "
            WITH RECURSIVE arvore AS (
                -- Âncora: categorias diretas de cada produto
                SELECT pc.produto_id, c.id, c.parent_id, c.busca_moto
                FROM produto_categorias pc
                INNER JOIN categorias c ON c.id = pc.categoria_id
                WHERE pc.produto_id IN ({$in})

                UNION

                -- Sobe pelos ancestrais, carregando o produto_id de origem
                SELECT a.produto_id, c.id, c.parent_id, c.busca_moto
                FROM categorias c
                INNER JOIN arvore a ON c.id = a.parent_id
            )
            SELECT DISTINCT produto_id
            FROM arvore
            WHERE busca_moto = 1
        ";

        try {
            $stmt = Database::getInstance()->getConnection()->prepare($sql);
            $stmt->execute($ids);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                $mapa[(int)$pid] = true;
            }
        } catch (\Throwable $e) {
            LogService::error('Falha em temBuscaMotoEmLote', ['erro' => $e->getMessage()]);
            // Fail-safe: sem compatibilidade o card só perde um selo.
        }

        return $mapa;
    }

    /**
     * Produtos do lote compatíveis com o veículo informado.
     *
     * Espelha VeiculoService::getProdutosCompativeisLote() (:228), mas recebe o
     * veículo por parâmetro em vez de ler $_SESSION — presenters não podem
     * depender de sessão.
     *
     * @return array<int,bool> mapa produto_id => compatível
     */
    public static function compativeisComVeiculo(array $produtoIds, ?array $veiculo): array
    {
        $ids = array_values(array_unique(array_map('intval', $produtoIds)));
        if (!$ids || empty($veiculo['montadora_id'])) {
            return [];
        }

        $mapa   = array_fill_keys($ids, false);
        $in     = implode(',', array_fill(0, count($ids), '?'));
        $params = $ids;

        $where    = "pc.produto_id IN ({$in}) AND pc.montadora_id = ?";
        $params[] = (int)$veiculo['montadora_id'];

        // Compatibilidade sem modelo declarado vale para toda a montadora.
        if (!empty($veiculo['modelo_id'])) {
            $where   .= " AND (pc.modelo_id = ? OR pc.modelo_id IS NULL)";
            $params[] = (int)$veiculo['modelo_id'];
        }

        // Faixa de anos: os quatro casos de ano_inicio/ano_fim nulos ou não.
        if (!empty($veiculo['ano'])) {
            $ano    = (int)$veiculo['ano'];
            $where .= " AND (
                (pc.ano_inicio IS NULL AND pc.ano_fim IS NULL)
                OR (pc.ano_inicio IS NULL AND pc.ano_fim    >= ?)
                OR (pc.ano_fim    IS NULL AND pc.ano_inicio <= ?)
                OR (pc.ano_inicio <= ? AND pc.ano_fim >= ?)
            )";
            array_push($params, $ano, $ano, $ano, $ano);
        }

        try {
            $stmt = Database::getInstance()->getConnection()->prepare(
                "SELECT DISTINCT pc.produto_id FROM produto_compatibilidade pc WHERE {$where}"
            );
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                $mapa[(int)$pid] = true;
            }
        } catch (\Throwable $e) {
            LogService::error('Falha em compativeisComVeiculo', ['erro' => $e->getMessage()]);
        }

        return $mapa;
    }
}
