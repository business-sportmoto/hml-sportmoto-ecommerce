<?php
/**
 * app/models/EmailSegment.php
 */
class EmailSegment
{
    /** @var PDO */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function all($apenasAtivos = true)
    {
        $sql = "SELECT * FROM email_segmentos";
        if ($apenasAtivos) $sql .= " WHERE ativo = 1";
        $sql .= " ORDER BY nome ASC";
        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find($id)
    {
        $st = $this->db->prepare("SELECT * FROM email_segmentos WHERE id = :id LIMIT 1");
        $st->execute([':id' => (int)$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function save(array $data)
    {
        $id = isset($data['id']) ? (int)$data['id'] : 0;
        $regras = isset($data['regras_json']) ? $data['regras_json'] : '{}';
        if (is_array($regras)) $regras = json_encode($regras, JSON_UNESCAPED_UNICODE);

        if ($id > 0) {
            $st = $this->db->prepare("UPDATE email_segmentos
                SET nome=:n, descricao=:d, regras_json=:r, ativo=:a
                WHERE id=:id");
            $st->execute([
                ':n' => $data['nome'],
                ':d' => $data['descricao'] ?? null,
                ':r' => $regras,
                ':a' => !empty($data['ativo']) ? 1 : 0,
                ':id' => $id,
            ]);
            return $id;
        }
        $st = $this->db->prepare("INSERT INTO email_segmentos
            (nome, descricao, regras_json, ativo)
            VALUES (:n, :d, :r, :a)");
        $st->execute([
            ':n' => $data['nome'],
            ':d' => $data['descricao'] ?? null,
            ':r' => $regras,
            ':a' => !empty($data['ativo']) ? 1 : 0,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function atualizarEstimativa($id, $total)
    {
        $st = $this->db->prepare("UPDATE email_segmentos
            SET total_estimado = :t, ultima_estimativa_em = NOW()
            WHERE id = :id");
        $st->execute([':t' => (int)$total, ':id' => (int)$id]);
    }

    public function delete($id)
    {
        $st = $this->db->prepare("DELETE FROM email_segmentos WHERE id = :id");
        return $st->execute([':id' => (int)$id]);
    }
}
