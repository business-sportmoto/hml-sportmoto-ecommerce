<?php
/**
 * app/models/EmailCampaignRecipient.php
 *
 * Tabela: email_campanha_destinatarios — fila principal.
 * Implementa estratégia de lock por UPDATE+lock_token (compatível com MySQL/MariaDB
 * sem SKIP LOCKED).
 */
class EmailCampaignRecipient
{
    /** @var PDO */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Insere destinatários em lote. Ignora duplicidades (uk_camp_contato).
     * Retorna a quantidade inserida.
     *
     * @param int   $campanhaId
     * @param array $contatos Lista de arrays: ['id'=>, 'email'=>, 'nome'=>, 'token_descadastro'=>]
     */
    public function inserirLote($campanhaId, array $contatos)
    {
        if (empty($contatos)) return 0;

        $sql = "INSERT IGNORE INTO email_campanha_destinatarios
            (campanha_id, contato_id, email, nome, status, token_unsub, token_open)
            VALUES (:c, :ct, :e, :n, 'fila', :tu, :to)";
        $st = $this->db->prepare($sql);

        $inseridos = 0;
        $this->db->beginTransaction();
        try {
            foreach ($contatos as $ct) {
                $st->execute([
                    ':c'  => (int)$campanhaId,
                    ':ct' => (int)$ct['id'],
                    ':e'  => $ct['email'],
                    ':n'  => $ct['nome'] ?? null,
                    ':tu' => $ct['token_descadastro'] ?? bin2hex(random_bytes(32)),
                    ':to' => bin2hex(random_bytes(16)),
                ]);
                if ($st->rowCount() > 0) $inseridos++;
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $inseridos;
    }

    /**
     * Reserva um lote de destinatários atribuindo um lock_token único e retorna
     * os registros assumidos. Estratégia compatível sem SKIP LOCKED.
     *
     * @return array
     */
    public function reservarLote($campanhaId, $batchSize)
    {
        $batchSize = max(1, min(2000, (int)$batchSize));
        $lockToken = $this->uuid4();

        $upd = $this->db->prepare(
            "UPDATE email_campanha_destinatarios
                SET status = 'processando',
                    lock_token = :lock,
                    locked_at  = NOW()
              WHERE status = 'fila'
                AND campanha_id = :c
                AND (proxima_tentativa_em IS NULL OR proxima_tentativa_em <= NOW())
              ORDER BY id ASC
              LIMIT $batchSize"
        );
        $upd->execute([':lock' => $lockToken, ':c' => (int)$campanhaId]);

        if ($upd->rowCount() === 0) return [];

        $sel = $this->db->prepare(
            "SELECT * FROM email_campanha_destinatarios
              WHERE lock_token = :lock AND status = 'processando'"
        );
        $sel->execute([':lock' => $lockToken]);
        return $sel->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Libera registros presos com lock vencido (volta para 'fila').
     */
    public function liberarLocksVencidos($segundos = 600)
    {
        $segundos = max(60, (int)$segundos);
        $sql = "UPDATE email_campanha_destinatarios
                SET status = 'fila', lock_token = NULL, locked_at = NULL
                WHERE status = 'processando'
                  AND locked_at IS NOT NULL
                  AND locked_at < (NOW() - INTERVAL $segundos SECOND)";
        return (int)$this->db->exec($sql);
    }

    public function marcarEnviado($id, $providerMessageId = null)
    {
        $st = $this->db->prepare("UPDATE email_campanha_destinatarios
            SET status = 'enviado',
                enviado_em = NOW(),
                provider_message_id = :pm,
                lock_token = NULL, locked_at = NULL, erro = NULL
            WHERE id = :id");
        return $st->execute([':pm' => $providerMessageId, ':id' => (int)$id]);
    }

    public function marcarFalha($id, $erro, $reagendarSegundos = null)
    {
        if ($reagendarSegundos !== null) {
            $st = $this->db->prepare("UPDATE email_campanha_destinatarios
                SET status = 'fila',
                    tentativas = tentativas + 1,
                    proxima_tentativa_em = DATE_ADD(NOW(), INTERVAL :sec SECOND),
                    erro = :e,
                    lock_token = NULL, locked_at = NULL
                WHERE id = :id");
            return $st->execute([
                ':sec' => (int)$reagendarSegundos,
                ':e'   => substr((string)$erro, 0, 480),
                ':id'  => (int)$id,
            ]);
        }

        $st = $this->db->prepare("UPDATE email_campanha_destinatarios
            SET status = 'falhou',
                tentativas = tentativas + 1,
                erro = :e,
                finalizado_em = NOW(),
                lock_token = NULL, locked_at = NULL
            WHERE id = :id");
        return $st->execute([
            ':e'   => substr((string)$erro, 0, 480),
            ':id'  => (int)$id,
        ]);
    }

    public function marcarIgnorado($id, $motivo)
    {
        $st = $this->db->prepare("UPDATE email_campanha_destinatarios
            SET status = 'ignorado', erro = :e, finalizado_em = NOW(),
                lock_token = NULL, locked_at = NULL
            WHERE id = :id");
        return $st->execute([':e' => substr((string)$motivo, 0, 480), ':id' => (int)$id]);
    }

    public function find($id)
    {
        $st = $this->db->prepare("SELECT * FROM email_campanha_destinatarios WHERE id = :id LIMIT 1");
        $st->execute([':id' => (int)$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findByTokenOpen($token)
    {
        $token = preg_replace('/[^a-f0-9]/i', '', (string)$token);
        if ($token === '') return null;
        $st = $this->db->prepare("SELECT * FROM email_campanha_destinatarios WHERE token_open = :t LIMIT 1");
        $st->execute([':t' => $token]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findByProviderMessageId($providerMessageId)
    {
        $st = $this->db->prepare("SELECT * FROM email_campanha_destinatarios
            WHERE provider_message_id = :p LIMIT 1");
        $st->execute([':p' => $providerMessageId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function contarPorStatus($campanhaId)
    {
        $sql = "SELECT status, COUNT(*) AS qtd FROM email_campanha_destinatarios
                WHERE campanha_id = :c GROUP BY status";
        $st = $this->db->prepare($sql);
        $st->execute([':c' => (int)$campanhaId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['status']] = (int)$r['qtd'];
        }
        return $out;
    }

    public function atualizarStatusEvento($destinatarioId, $novoStatus, $camposExtras = [])
    {
        $permitidos = [
            'entregue_em' => 'entregue_em',
            'aberto_em'   => 'aberto_em',
            'clicado_em'  => 'clicado_em',
        ];
        $set = ['status = :s'];
        $params = [':s' => $novoStatus, ':id' => (int)$destinatarioId];
        foreach ($camposExtras as $k => $v) {
            if (isset($permitidos[$k])) {
                $set[] = "$k = :$k";
                $params[":$k"] = $v;
            }
        }
        $sql = "UPDATE email_campanha_destinatarios SET " . implode(', ', $set) . " WHERE id = :id";
        $st = $this->db->prepare($sql);
        return $st->execute($params);
    }

    private function uuid4()
    {
        $d = random_bytes(16);
        $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
        $d[8] = chr(ord($d[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
