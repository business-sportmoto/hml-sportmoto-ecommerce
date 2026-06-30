<?php
/**
 * app/models/EmailConsent.php
 */
class EmailConsent
{
    /** @var PDO */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function registrar($contatoId, $acao, array $extra = [])
    {
        $sql = "INSERT INTO email_consentimentos
            (contato_id, acao, origem, ip, user_agent, texto_termo, referencia)
            VALUES (:c, :a, :o, :ip, :ua, :tt, :ref)";
        $st = $this->db->prepare($sql);
        $st->execute([
            ':c'   => (int)$contatoId,
            ':a'   => $acao,
            ':o'   => $extra['origem']      ?? 'sistema',
            ':ip'  => $extra['ip']          ?? null,
            ':ua'  => isset($extra['user_agent']) ? substr($extra['user_agent'], 0, 250) : null,
            ':tt'  => $extra['texto_termo'] ?? null,
            ':ref' => $extra['referencia']  ?? null,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function historicoDoContato($contatoId, $limit = 50)
    {
        $limit = max(1, min(500, (int)$limit));
        $st = $this->db->prepare("SELECT * FROM email_consentimentos
            WHERE contato_id = :c ORDER BY id DESC LIMIT $limit");
        $st->execute([':c' => (int)$contatoId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}
