<?php
/**
 * app/models/EmailProvider.php
 */
class EmailProvider
{
    /** @var PDO */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function all($apenasAtivos = false)
    {
        $sql = "SELECT * FROM email_provedores";
        if ($apenasAtivos) $sql .= " WHERE ativo = 1";
        $sql .= " ORDER BY padrao DESC, nome ASC";
        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find($id)
    {
        $st = $this->db->prepare("SELECT * FROM email_provedores WHERE id = :id LIMIT 1");
        $st->execute([':id' => (int)$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function padrao()
    {
        $st = $this->db->query("SELECT * FROM email_provedores WHERE ativo = 1 AND padrao = 1 LIMIT 1");
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function save(array $data)
    {
        $id = isset($data['id']) ? (int)$data['id'] : 0;

        // Se este vai ser o padrão, derruba os demais.
        if (!empty($data['padrao'])) {
            $this->db->exec("UPDATE email_provedores SET padrao = 0");
        }

        $campos = [
            'nome','tipo','remetente_email','remetente_nome','reply_to','dominio',
            'regiao','credenciais','limite_por_minuto','limite_por_dia',
            'webhook_secret','ativo','padrao'
        ];
        $vals = [];
        foreach ($campos as $c) {
            $vals[$c] = isset($data[$c]) ? $data[$c] : null;
        }

        if ($id > 0) {
            $sql = "UPDATE email_provedores SET
                nome=:nome, tipo=:tipo, remetente_email=:remetente_email,
                remetente_nome=:remetente_nome, reply_to=:reply_to, dominio=:dominio,
                regiao=:regiao, credenciais=:credenciais,
                limite_por_minuto=:limite_por_minuto, limite_por_dia=:limite_por_dia,
                webhook_secret=:webhook_secret, ativo=:ativo, padrao=:padrao
                WHERE id=:id";
            $vals[':id'] = $id;
            $st = $this->db->prepare($sql);
            // bind explícito porque temos chaves sem ":" acima
            foreach ($vals as $k => $v) {
                $key = (strpos($k, ':') === 0) ? $k : (':' . $k);
                $st->bindValue($key, $v);
            }
            $st->execute();
            return $id;
        }

        $sql = "INSERT INTO email_provedores
            (nome,tipo,remetente_email,remetente_nome,reply_to,dominio,regiao,
             credenciais,limite_por_minuto,limite_por_dia,webhook_secret,ativo,padrao)
            VALUES
            (:nome,:tipo,:remetente_email,:remetente_nome,:reply_to,:dominio,:regiao,
             :credenciais,:limite_por_minuto,:limite_por_dia,:webhook_secret,:ativo,:padrao)";
        $st = $this->db->prepare($sql);
        foreach ($vals as $k => $v) {
            $st->bindValue(':' . $k, $v);
        }
        $st->execute();
        return (int)$this->db->lastInsertId();
    }

    public function delete($id)
    {
        $st = $this->db->prepare("DELETE FROM email_provedores WHERE id = :id");
        return $st->execute([':id' => (int)$id]);
    }
}
