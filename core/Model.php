<?php
// core/Model.php
// Classe base para todos os Models — encapsula acesso ao PDO

abstract class Model {
    protected PDO $db;
    protected string $table;       // cada subclasse define sua tabela
    protected string $primaryKey = 'id';

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    // Busca por chave primária
    public function find(int $id): ?array {
        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->table} WHERE {$this->primaryKey} = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    // Busca todos com ordenação e limite opcionais
    public function all(string $orderBy = 'id', string $direction = 'ASC',
                        int $limit = 0, int $offset = 0): array {
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $sql = "SELECT * FROM {$this->table} ORDER BY {$orderBy} {$direction}";
        if ($limit > 0) {
            $sql .= " LIMIT {$limit} OFFSET {$offset}";
        }
        return $this->db->query($sql)->fetchAll();
    }

    // Insere um registro e retorna o ID gerado
    public function insert(array $data): int {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $stmt = $this->db->prepare(
            "INSERT INTO {$this->table} ({$columns}) VALUES ({$placeholders})"
        );
        $stmt->execute(array_values($data));
        return (int) $this->db->lastInsertId();
    }

    // Atualiza registro por PK
    public function update(int $id, array $data): bool {
        $setParts = array_map(fn($col) => "{$col} = ?", array_keys($data));
        $setClause = implode(', ', $setParts);
        $stmt = $this->db->prepare(
            "UPDATE {$this->table} SET {$setClause}
             WHERE {$this->primaryKey} = ?"
        );
        $values = array_values($data);
        $values[] = $id;
        return $stmt->execute($values);
    }

    // Soft delete (marca como deleted_at) ou hard delete
    public function delete(int $id, bool $soft = true): bool {
        if ($soft && $this->hasColumn('deleted_at')) {
            return $this->update($id, ['deleted_at' => date('Y-m-d H:i:s')]);
        }
        $stmt = $this->db->prepare(
            "DELETE FROM {$this->table} WHERE {$this->primaryKey} = ?"
        );
        return $stmt->execute([$id]);
    }

    // Contagem total (útil para paginação)
    public function count(string $where = '', array $params = []): int {
        $sql = "SELECT COUNT(*) FROM {$this->table}";
        if ($where) $sql .= " WHERE {$where}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    // Verifica se uma coluna existe (usado no soft delete)
    private function hasColumn(string $column): bool {
        try {
            $stmt = $this->db->query("SELECT {$column} FROM {$this->table} LIMIT 0");
            return $stmt !== false;
        } catch (PDOException) {
            return false;
        }
    }
}