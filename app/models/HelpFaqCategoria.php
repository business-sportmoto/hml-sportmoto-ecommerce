<?php

class HelpFaqCategoria {

    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    // ------------------------------------------------------------------
    // Leitura pública
    // ------------------------------------------------------------------

    public function getAllAtivas(): array {
        $stmt = $this->db->prepare("
            SELECT c.*,
                   COUNT(p.id) AS total_perguntas
              FROM help_categorias c
         LEFT JOIN help_perguntas p ON p.categoria_id = c.id AND p.ativo = 1
             WHERE c.ativo = 1
          GROUP BY c.id
          ORDER BY c.ordem ASC, c.nome ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getBySlug(string $slug): array|false {
        $stmt = $this->db->prepare(
            "SELECT * FROM help_categorias WHERE slug = :slug AND ativo = 1"
        );
        $stmt->execute([':slug' => $slug]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // ------------------------------------------------------------------
    // Admin — listagem completa
    // ------------------------------------------------------------------

    public function getAll(): array {
        $stmt = $this->db->prepare("
            SELECT c.*,
                   COUNT(p.id) AS total_perguntas
              FROM help_categorias c
         LEFT JOIN help_perguntas p ON p.categoria_id = c.id
          GROUP BY c.id
          ORDER BY c.ordem ASC, c.nome ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById(int $id): array|false {
        $stmt = $this->db->prepare(
            "SELECT * FROM help_categorias WHERE id = :id"
        );
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // ------------------------------------------------------------------
    // Admin — escrita
    // ------------------------------------------------------------------

    public function create(array $data): int {
        $allowed = ['nome', 'slug', 'icone', 'descricao', 'ordem', 'ativo'];
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
        $stmt = $this->db->prepare("INSERT INTO help_categorias ($cols) VALUES ($vals)");
        $stmt->execute($params);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool {
        $allowed = ['nome', 'slug', 'icone', 'descricao', 'ordem', 'ativo'];
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
            "UPDATE help_categorias SET " . implode(', ', $sets) . " WHERE id = :id"
        );
        return $stmt->execute($params);
    }

    public function delete(int $id): bool {
        $stmt = $this->db->prepare("DELETE FROM help_categorias WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    public function slugExists(string $slug, int $excludeId = 0): bool {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM help_categorias WHERE slug = :slug AND id != :id"
        );
        $stmt->execute([':slug' => $slug, ':id' => $excludeId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function generateSlug(string $nome): string {
        $slug = strtolower(trim($nome));
        $slug = iconv('UTF-8', 'ASCII//TRANSLIT', $slug);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        $base = $slug;
        $i    = 1;
        while ($this->slugExists($slug)) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }
}