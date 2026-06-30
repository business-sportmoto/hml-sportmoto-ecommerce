<?php
/**
 * app/models/EmailCampaign.php
 */
class EmailCampaign
{
    /** @var PDO */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function all(array $filtros = [], $pagina = 1, $porPagina = 50)
    {
        $where = [];
        $params = [];

        if (!empty($filtros['status'])) {
            $where[] = 'c.status = :s';
            $params[':s'] = $filtros['status'];
        }
        if (!empty($filtros['busca'])) {
            $where[] = 'c.nome LIKE :busca';
            $params[':busca'] = '%' . $filtros['busca'] . '%';
        }
        $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stc = $this->db->prepare("SELECT COUNT(*) FROM email_campanhas c $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $pagina = max(1, (int)$pagina);
        $porPagina = max(1, min(200, (int)$porPagina));
        $offset = ($pagina - 1) * $porPagina;

        $sql = "SELECT c.*, t.nome AS template_nome, p.nome AS provedor_nome
                FROM email_campanhas c
                LEFT JOIN email_templates  t ON t.id = c.template_id
                LEFT JOIN email_provedores p ON p.id = c.provedor_id
                $sqlWhere
                ORDER BY c.id DESC LIMIT $porPagina OFFSET $offset";
        $st = $this->db->prepare($sql);
        $st->execute($params);

        return [
            'total' => $total,
            'pagina' => $pagina,
            'por_pagina' => $porPagina,
            'itens' => $st->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    public function find($id)
    {
        $st = $this->db->prepare("SELECT * FROM email_campanhas WHERE id = :id LIMIT 1");
        $st->execute([':id' => (int)$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function save(array $d)
    {
        $id = isset($d['id']) ? (int)$d['id'] : 0;

        if ($id > 0) {
            $sql = "UPDATE email_campanhas SET
                nome=:nome, provedor_id=:prov, template_id=:tpl,
                lista_id=:lista, segmento_id=:seg,
                assunto_override=:assunto, preheader_override=:preh,
                remetente_email=:rem_email, remetente_nome=:rem_nome, reply_to=:reply,
                agendada_para=:agendada, batch_size=:bs, intervalo_segundos=:int
                WHERE id=:id";
            $st = $this->db->prepare($sql);
            $st->execute([
                ':nome' => $d['nome'],
                ':prov' => (int)$d['provedor_id'],
                ':tpl'  => (int)$d['template_id'],
                ':lista'=> !empty($d['lista_id']) ? (int)$d['lista_id'] : null,
                ':seg'  => !empty($d['segmento_id']) ? (int)$d['segmento_id'] : null,
                ':assunto' => $d['assunto_override'] ?? null,
                ':preh'    => $d['preheader_override'] ?? null,
                ':rem_email' => $d['remetente_email'] ?? null,
                ':rem_nome'  => $d['remetente_nome']  ?? null,
                ':reply'   => $d['reply_to'] ?? null,
                ':agendada'=> $d['agendada_para'] ?? null,
                ':bs'   => max(1, min(2000, (int)($d['batch_size'] ?? 200))),
                ':int'  => max(0, (int)($d['intervalo_segundos'] ?? 1)),
                ':id'   => $id,
            ]);
            return $id;
        }

        $sql = "INSERT INTO email_campanhas
            (nome,provedor_id,template_id,lista_id,segmento_id,
             assunto_override,preheader_override,remetente_email,remetente_nome,reply_to,
             status,agendada_para,batch_size,intervalo_segundos,criado_por)
            VALUES
            (:nome,:prov,:tpl,:lista,:seg,
             :assunto,:preh,:rem_email,:rem_nome,:reply,
             'rascunho',:agendada,:bs,:int,:user)";
        $st = $this->db->prepare($sql);
        $st->execute([
            ':nome' => $d['nome'],
            ':prov' => (int)$d['provedor_id'],
            ':tpl'  => (int)$d['template_id'],
            ':lista'=> !empty($d['lista_id']) ? (int)$d['lista_id'] : null,
            ':seg'  => !empty($d['segmento_id']) ? (int)$d['segmento_id'] : null,
            ':assunto' => $d['assunto_override'] ?? null,
            ':preh'    => $d['preheader_override'] ?? null,
            ':rem_email' => $d['remetente_email'] ?? null,
            ':rem_nome'  => $d['remetente_nome']  ?? null,
            ':reply'   => $d['reply_to'] ?? null,
            ':agendada'=> $d['agendada_para'] ?? null,
            ':bs'   => max(1, min(2000, (int)($d['batch_size'] ?? 200))),
            ':int'  => max(0, (int)($d['intervalo_segundos'] ?? 1)),
            ':user' => isset($d['criado_por']) ? (int)$d['criado_por'] : null,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function setStatus($id, $status)
    {
        $st = $this->db->prepare("UPDATE email_campanhas SET status = :s WHERE id = :id");
        return $st->execute([':s' => $status, ':id' => (int)$id]);
    }

    public function marcarIniciada($id)
    {
        $st = $this->db->prepare("UPDATE email_campanhas
            SET status = 'enviando', iniciada_em = COALESCE(iniciada_em, NOW())
            WHERE id = :id");
        return $st->execute([':id' => (int)$id]);
    }

    public function marcarConcluida($id)
    {
        $st = $this->db->prepare("UPDATE email_campanhas
            SET status = 'concluida', concluida_em = NOW()
            WHERE id = :id");
        return $st->execute([':id' => (int)$id]);
    }

    public function incrementar($id, $coluna, $qtd = 1)
    {
        $permitidos = [
            'total_destinatarios','total_enviados','total_entregues',
            'total_aberturas','total_cliques','total_bounces',
            'total_complaints','total_descadastros','total_falhas'
        ];
        if (!in_array($coluna, $permitidos, true)) return false;
        $st = $this->db->prepare("UPDATE email_campanhas
            SET $coluna = $coluna + :q WHERE id = :id");
        return $st->execute([':q' => (int)$qtd, ':id' => (int)$id]);
    }

    /** Campanhas elegíveis para execução pelo worker */
    public function elegiveisParaWorker($limit = 5)
    {
        $limit = max(1, (int)$limit);
        $sql = "SELECT * FROM email_campanhas
            WHERE status IN ('agendada','enviando')
              AND (agendada_para IS NULL OR agendada_para <= NOW())
            ORDER BY status DESC, agendada_para ASC, id ASC
            LIMIT $limit";
        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
}
