<?php
/**
 * app/models/EmailLink.php
 */
class EmailLink
{
    /** @var PDO */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function findOrCreate($campanhaId, $url, $titulo = null)
    {
        $url = trim($url);
        $hash = hash('sha256', $url);

        $st = $this->db->prepare("SELECT * FROM email_links
            WHERE campanha_id = :c AND url_hash = :h LIMIT 1");
        $st->execute([':c' => (int)$campanhaId, ':h' => $hash]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;

        $ins = $this->db->prepare("INSERT INTO email_links
            (campanha_id, url_destino, url_hash, titulo)
            VALUES (:c, :u, :h, :t)");
        $ins->execute([
            ':c' => (int)$campanhaId,
            ':u' => $url,
            ':h' => $hash,
            ':t' => $titulo,
        ]);
        $id = (int)$this->db->lastInsertId();
        return [
            'id' => $id,
            'campanha_id' => (int)$campanhaId,
            'url_destino' => $url,
            'url_hash' => $hash,
            'titulo' => $titulo,
            'total_cliques' => 0,
        ];
    }

    public function find($id)
    {
        $st = $this->db->prepare("SELECT * FROM email_links WHERE id = :id LIMIT 1");
        $st->execute([':id' => (int)$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function incrementarClique($id)
    {
        $st = $this->db->prepare("UPDATE email_links
            SET total_cliques = total_cliques + 1 WHERE id = :id");
        return $st->execute([':id' => (int)$id]);
    }

    public function porCampanha($campanhaId)
    {
        $st = $this->db->prepare("SELECT * FROM email_links
            WHERE campanha_id = :c ORDER BY total_cliques DESC, id ASC");
        $st->execute([':c' => (int)$campanhaId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}
