<?php

class HelpFaq {

    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    // ------------------------------------------------------------------
    // Leitura pública
    // ------------------------------------------------------------------

    /**
     * Retorna todas as perguntas ativas de uma categoria.
     */
    public function getByCategoriaId(int $categoriaId): array {
        $stmt = $this->db->prepare("
            SELECT * FROM help_perguntas
             WHERE categoria_id = :cid AND ativo = 1
          ORDER BY ordem ASC, id ASC
        ");
        $stmt->execute([':cid' => $categoriaId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Busca full-text em pergunta + resposta (LIKE simples — sem necessidade de FULLTEXT index).
     */
    public function search(string $termo): array {
        $like = '%' . $termo . '%';
        $stmt = $this->db->prepare("
            SELECT p.*, c.nome AS categoria_nome, c.slug AS categoria_slug, c.icone AS categoria_icone
              FROM help_perguntas p
              JOIN help_categorias c ON c.id = p.categoria_id AND c.ativo = 1
             WHERE p.ativo = 1
               AND (p.pergunta LIKE :t1 OR p.resposta LIKE :t2)
          ORDER BY p.ordem ASC
             LIMIT 30
        ");
        $stmt->execute([':t1' => $like, ':t2' => $like]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retorna todas as perguntas agrupadas por categoria (para o Help Center completo).
     */
    public function getAllAtivasAgrupadas(): array {
        $stmt = $this->db->prepare("
            SELECT p.*, c.nome AS categoria_nome, c.slug AS categoria_slug, c.icone AS categoria_icone
              FROM help_perguntas p
              JOIN help_categorias c ON c.id = p.categoria_id AND c.ativo = 1
             WHERE p.ativo = 1
          ORDER BY c.ordem ASC, p.ordem ASC
        ");
        $stmt->execute();
        $rows   = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $groups = [];
        foreach ($rows as $row) {
            $groups[$row['categoria_slug']][] = $row;
        }
        return $groups;
    }

    public function incrementVisualizacao(int $id): void {
        $this->db->prepare(
            "UPDATE help_perguntas SET visualizacoes = visualizacoes + 1 WHERE id = :id"
        )->execute([':id' => $id]);
    }

    // ------------------------------------------------------------------
    // Admin
    // ------------------------------------------------------------------

    public function getAllAdmin(?int $categoriaId = null): array {
        $where  = $categoriaId ? 'WHERE p.categoria_id = :cid' : '';
        $params = $categoriaId ? [':cid' => $categoriaId] : [];
        $stmt   = $this->db->prepare("
            SELECT p.*, c.nome AS categoria_nome
              FROM help_perguntas p
              JOIN help_categorias c ON c.id = p.categoria_id
              $where
          ORDER BY c.ordem ASC, p.ordem ASC, p.id ASC
        ");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById(int $id): array|false {
        $stmt = $this->db->prepare(
            "SELECT * FROM help_perguntas WHERE id = :id"
        );
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function create(array $data): int {
        $allowed = ['categoria_id', 'pergunta', 'resposta', 'ordem', 'ativo'];
        $fields  = [];
        $params  = [];
        foreach ($allowed as $f) {
            if (isset($data[$f])) {
                $fields[] = $f;
                $params[":$f"] = $data[$f];
            }
        }
        $cols = implode(', ', $fields);
        $vals = implode(', ', array_keys($params));
        $stmt = $this->db->prepare("INSERT INTO help_perguntas ($cols) VALUES ($vals)");
        $stmt->execute($params);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool {
        $allowed = ['categoria_id', 'pergunta', 'resposta', 'ordem', 'ativo'];
        $sets    = [];
        $params  = [':id' => $id];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $data)) {
                $sets[] = "$f = :$f";
                $params[":$f"] = $data[$f];
            }
        }
        if (empty($sets)) return false;
        $stmt = $this->db->prepare(
            "UPDATE help_perguntas SET " . implode(', ', $sets) . " WHERE id = :id"
        );
        return $stmt->execute($params);
    }

    public function delete(int $id): bool {
        $stmt = $this->db->prepare("DELETE FROM help_perguntas WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }
}