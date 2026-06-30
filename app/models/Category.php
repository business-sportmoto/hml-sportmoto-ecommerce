<?php
// app/models/Category.php

class Category extends Model {
    protected string $table = 'categorias';

    public function getActive(int $parentId = 0, bool $destaque = false, $all = false): array {
        $all = !$all ? "AND parent_id " . ($parentId === 0 ? "IS NULL" : "= ?") : "";
        $sql    = "SELECT * FROM categorias WHERE ativo = 1 ".$all;
        $params = [];
        if ($parentId !== 0) $params[] = $parentId;
        if ($destaque) { $sql .= " AND destaque = 1"; }
        $sql .= " ORDER BY ordem ASC, nome ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    

    public function getTree(): array {
        $stmt = $this->db->query(
            "SELECT * FROM categorias WHERE ativo = 1 ORDER BY parent_id ASC, ordem ASC"
        );
        $all  = $stmt->fetchAll();
        return $this->buildTree($all);
    }

    /**
     * Retorna a árvore completa de categorias com N níveis de profundidade.
     */
    public function getNavTree(): array {
        $stmt = $this->db->query(
            "SELECT id, nome, slug, parent_id, ordem
            FROM categorias
            WHERE ativo = 1
            ORDER BY parent_id ASC, ordem ASC, nome ASC"
        );
        $all = $stmt->fetchAll();

        return $this->buildTree($all);
    }

    private function buildTree(array $items, ?int $parentId = null): array {
        $branch = [];
        foreach ($items as $item) {
            $pid = $item['parent_id'] === null ? null : (int)$item['parent_id'];
            if ($pid === $parentId) {
                $children = $this->buildTree($items, (int)$item['id']);
                if ($children) {
                    $item['children'] = $children;
                }
                $branch[] = $item;
            }
        }
        return $branch;
    }

    public function findBySlug(string $slug): ?array {
        $stmt = $this->db->prepare(
            "SELECT * FROM categorias WHERE slug = ? AND ativo = 1 LIMIT 1"
        );
        $stmt->execute([$slug]);
        return $stmt->fetch() ?: null;
    }

    // Adicionar ao model de categoria:

    /**
     * Verifica se uma categoria (ou qualquer ancestral) tem busca_moto ativa.
     */
    public function temBuscaMoto(int $categoriaId): bool {
        $atual = $categoriaId;

        while ($atual) {
            $stmt = $this->db->prepare(
                "SELECT busca_moto, parent_id
                FROM categorias WHERE id = ? LIMIT 1"
            );
            $stmt->execute([$atual]);
            $row = $stmt->fetch();

            if (!$row) break;
            if ((int)$row['busca_moto'] === 1) return true;

            $atual = (int)($row['parent_id'] ?? 0);
        }

        return false;
    }

    /**
     * Retorna todas as categorias com busca_moto ativa.
     */
    public function getComBuscaMoto(): array {
        return $this->db->query(
            "SELECT id, nome, slug FROM categorias
            WHERE busca_moto = 1 AND ativo = 1
            ORDER BY nome ASC"
        )->fetchAll();
    }
}