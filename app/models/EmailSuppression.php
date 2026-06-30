<?php
/**
 * app/models/EmailSuppression.php
 */
class EmailSuppression
{
    /** @var PDO */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function isSuppressed($email)
    {
        $email = strtolower(trim($email));
        if ($email === '') return false;
        $st = $this->db->prepare("SELECT id FROM email_supressoes
            WHERE email = :e AND (expira_em IS NULL OR expira_em > NOW()) LIMIT 1");
        $st->execute([':e' => $email]);
        return (bool)$st->fetchColumn();
    }

    public function adicionar($email, $motivo, $origem = 'sistema', $observacao = null, $expiraEm = null)
    {
        $email = strtolower(trim($email));
        if ($email === '') return false;
        $hash = hash('sha256', $email);

        $sql = "INSERT INTO email_supressoes (email, email_hash, motivo, origem, observacao, expira_em)
                VALUES (:e, :h, :m, :o, :ob, :exp)
                ON DUPLICATE KEY UPDATE
                    motivo = VALUES(motivo),
                    origem = VALUES(origem),
                    observacao = VALUES(observacao),
                    expira_em = VALUES(expira_em)";
        $st = $this->db->prepare($sql);
        return $st->execute([
            ':e'   => $email,
            ':h'   => $hash,
            ':m'   => $motivo,
            ':o'   => $origem,
            ':ob'  => $observacao,
            ':exp' => $expiraEm,
        ]);
    }

    public function remover($email)
    {
        $email = strtolower(trim($email));
        $st = $this->db->prepare("DELETE FROM email_supressoes WHERE email = :e");
        return $st->execute([':e' => $email]);
    }

    public function listar(array $filtros = [], $pagina = 1, $porPagina = 100)
    {
        $where = [];
        $params = [];
        if (!empty($filtros['motivo'])) {
            $where[] = 'motivo = :m';
            $params[':m'] = $filtros['motivo'];
        }
        if (!empty($filtros['busca'])) {
            $where[] = 'email LIKE :b';
            $params[':b'] = '%' . $filtros['busca'] . '%';
        }
        $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stc = $this->db->prepare("SELECT COUNT(*) FROM email_supressoes $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $pagina = max(1, (int)$pagina);
        $porPagina = max(1, min(500, (int)$porPagina));
        $offset = ($pagina - 1) * $porPagina;

        $sql = "SELECT * FROM email_supressoes $sqlWhere
                ORDER BY id DESC LIMIT $porPagina OFFSET $offset";
        $st = $this->db->prepare($sql);
        $st->execute($params);

        return [
            'total' => $total,
            'pagina' => $pagina,
            'por_pagina' => $porPagina,
            'itens' => $st->fetchAll(PDO::FETCH_ASSOC),
        ];
    }
}
