<?php
/**
 * app/models/EmailCampaignVariation.php
 *
 * CRUD sobre a tabela email_campanha_variacoes (A e B).
 */
class EmailCampaignVariation
{
    /** @var PDO */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function findByCampanha(int $campanhaId): array
    {
        $st = $this->db->prepare(
            "SELECT * FROM email_campanha_variacoes
             WHERE campanha_id = :c
             ORDER BY variacao ASC"
        );
        $st->execute([':c' => $campanhaId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $st = $this->db->prepare("SELECT * FROM email_campanha_variacoes WHERE id = :id LIMIT 1");
        $st->execute([':id' => $id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    public function findOne(int $campanhaId, string $variacao): ?array
    {
        $st = $this->db->prepare(
            "SELECT * FROM email_campanha_variacoes
             WHERE campanha_id = :c AND variacao = :v LIMIT 1"
        );
        $st->execute([':c' => $campanhaId, ':v' => $variacao]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /**
     * Salva (upsert) uma variação A ou B.
     */
    public function save(int $campanhaId, string $variacao, array $dados): int
    {
        $existente = $this->findOne($campanhaId, $variacao);
        if ($existente) {
            $sql = "UPDATE email_campanha_variacoes SET
                        template_id = :tid, assunto = :ass, preheader = :pre,
                        remetente_email = :re, remetente_nome = :rn,
                        atualizado_em = NOW()
                    WHERE id = :id";
            $st = $this->db->prepare($sql);
            $st->execute([
                ':tid' => $dados['template_id'] ?? null,
                ':ass' => $dados['assunto'] ?? null,
                ':pre' => $dados['preheader'] ?? null,
                ':re'  => $dados['remetente_email'] ?? null,
                ':rn'  => $dados['remetente_nome'] ?? null,
                ':id'  => $existente['id'],
            ]);
            return (int)$existente['id'];
        }

        $sql = "INSERT INTO email_campanha_variacoes
                (campanha_id, variacao, template_id, assunto, preheader,
                 remetente_email, remetente_nome, criado_em, atualizado_em)
                VALUES (:c, :v, :tid, :ass, :pre, :re, :rn, NOW(), NOW())";
        $st = $this->db->prepare($sql);
        $st->execute([
            ':c'   => $campanhaId,
            ':v'   => $variacao,
            ':tid' => $dados['template_id'] ?? null,
            ':ass' => $dados['assunto'] ?? null,
            ':pre' => $dados['preheader'] ?? null,
            ':re'  => $dados['remetente_email'] ?? null,
            ':rn'  => $dados['remetente_nome'] ?? null,
        ]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Incrementa contador agregado de uma variação.
     * Aceita: enviados, entregues, aberturas, cliques, bounces,
     *         complaints, descadastros, falhas
     */
    public function incrementar(int $campanhaId, string $variacao, string $contador, int $delta = 1): void
    {
        $allowed = [
            'enviados', 'entregues', 'aberturas', 'cliques',
            'bounces', 'complaints', 'descadastros', 'falhas', 'destinatarios',
        ];
        if (!in_array($contador, $allowed, true)) return;
        $col = 'total_' . $contador;

        $sql = "UPDATE email_campanha_variacoes
                SET $col = $col + :d, atualizado_em = NOW()
                WHERE campanha_id = :c AND variacao = :v";
        $st = $this->db->prepare($sql);
        $st->execute([':d' => $delta, ':c' => $campanhaId, ':v' => $variacao]);
    }

    /**
     * Marca o total de destinatários reservados para uma variação.
     */
    public function setDestinatarios(int $campanhaId, string $variacao, int $total): void
    {
        $st = $this->db->prepare(
            "UPDATE email_campanha_variacoes
             SET total_destinatarios = :t, atualizado_em = NOW()
             WHERE campanha_id = :c AND variacao = :v"
        );
        $st->execute([':t' => $total, ':c' => $campanhaId, ':v' => $variacao]);
    }

    public function delete(int $id): void
    {
        $this->db->prepare("DELETE FROM email_campanha_variacoes WHERE id = :id")
                 ->execute([':id' => $id]);
    }
}
