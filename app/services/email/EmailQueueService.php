<?php
/**
 * app/services/email/EmailQueueService.php
 *
 * Responsável por popular email_campanha_destinatarios a partir de uma lista
 * ou segmento, ignorando supressões e contatos não-elegíveis.
 */
class EmailQueueService
{
    /** @var PDO */
    private $db;
    /** @var EmailCampaign */
    private $campanhas;
    /** @var EmailCampaignRecipient */
    private $destinatarios;
    /** @var EmailSegmentService */
    private $segmentos;
    /** @var EmailSuppressionService */
    private $supressoes;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->campanhas = new EmailCampaign();
        $this->destinatarios = new EmailCampaignRecipient();
        $this->segmentos = new EmailSegmentService();
        $this->supressoes = new EmailSuppressionService();
    }

    /**
     * Popula a fila para uma campanha.
     * Marca a campanha como 'enfileirando' enquanto roda; ao final, deixa em
     * 'agendada' (se houver agendamento futuro) ou 'enviando'.
     *
     * @return array stats
     */
    public function enfileirar($campanhaId)
    {
        $camp = $this->campanhas->find($campanhaId);
        if (!$camp) {
            throw new RuntimeException('Campanha não encontrada');
        }
        if (in_array($camp['status'], ['enviando','concluida','cancelada'], true)) {
            throw new RuntimeException('Campanha não pode ser enfileirada no status atual: ' . $camp['status']);
        }

        $this->campanhas->setStatus($campanhaId, 'enfileirando');

        $totalInseridos = 0;
        $totalIgnorados = 0;

        try {
            if (!empty($camp['lista_id'])) {
                $totalInseridos += $this->enfileirarDeLista($campanhaId, (int)$camp['lista_id'], $totalIgnorados);
            } elseif (!empty($camp['segmento_id'])) {
                $seg = (new EmailSegment())->find((int)$camp['segmento_id']);
                if (!$seg) throw new RuntimeException('Segmento da campanha não encontrado');
                $regras = json_decode($seg['regras_json'], true) ?: [];
                $totalInseridos += $this->enfileirarDeRegras($campanhaId, $regras, $totalIgnorados);
            } else {
                throw new RuntimeException('Campanha sem lista nem segmento');
            }

            // total_destinatarios da campanha + decisão final de status
            $st = $this->db->prepare("UPDATE email_campanhas
                SET total_destinatarios = (
                    SELECT COUNT(*) FROM email_campanha_destinatarios WHERE campanha_id = :c
                ),
                status = CASE
                    WHEN agendada_para IS NOT NULL AND agendada_para > NOW() THEN 'agendada'
                    ELSE 'enviando'
                END,
                iniciada_em = COALESCE(iniciada_em,
                    IF(agendada_para IS NULL OR agendada_para <= NOW(), NOW(), iniciada_em))
                WHERE id = :c2");
            $st->execute([':c' => (int)$campanhaId, ':c2' => (int)$campanhaId]);

        } catch (Throwable $e) {
            $this->campanhas->setStatus($campanhaId, 'erro');
            throw $e;
        }

        return [
            'inseridos' => $totalInseridos,
            'ignorados' => $totalIgnorados,
        ];
    }

    private function enfileirarDeLista($campanhaId, $listaId, &$ignorados)
    {
        $sql = "SELECT ec.id, ec.email, ec.nome, ec.primeiro_nome, ec.token_descadastro
                FROM email_lista_contatos lc
                JOIN email_contatos ec ON ec.id = lc.contato_id
                WHERE lc.lista_id = :l
                  AND lc.status = 'ativo'
                  AND ec.status = 'ativo'
                  AND ec.id > :last
                ORDER BY ec.id ASC
                LIMIT 1000";
        return $this->loopChunks($sql, [':l' => (int)$listaId], $campanhaId, $ignorados);
    }

    private function enfileirarDeRegras($campanhaId, array $regras, &$ignorados)
    {
        $inserted = 0;
        $supr = $this->supressoes;
        $dest = $this->destinatarios;

        $this->segmentos->each($regras, function ($rows) use (&$inserted, &$ignorados, $supr, $dest, $campanhaId) {
            $lote = [];
            foreach ($rows as $r) {
                if ($supr->isSuppressed($r['email'])) { $ignorados++; continue; }
                $lote[] = $r;
            }
            if ($lote) $inserted += $dest->inserirLote($campanhaId, $lote);
        }, 1000);

        return $inserted;
    }

    /** Loop por "cursor" (id > :last) — robusto contra mudanças no LIMIT/OFFSET. */
    private function loopChunks($sql, $baseParams, $campanhaId, &$ignorados)
    {
        $last = 0;
        $total = 0;
        while (true) {
            $st = $this->db->prepare($sql);
            foreach ($baseParams as $k => $v) $st->bindValue($k, $v);
            $st->bindValue(':last', $last, PDO::PARAM_INT);
            $st->execute();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) break;

            $lote = [];
            foreach ($rows as $r) {
                $last = max($last, (int)$r['id']);
                if ($this->supressoes->isSuppressed($r['email'])) { $ignorados++; continue; }
                $lote[] = $r;
            }
            if ($lote) $total += $this->destinatarios->inserirLote($campanhaId, $lote);
            if (count($rows) < 1000) break;
        }
        return $total;
    }
}
