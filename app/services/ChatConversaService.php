<?php
/**
 * app/services/ChatConversaService.php
 *
 * A conversa é a thread do inbox: 1 por contato por canal (igual ao ManyChat).
 * Este service cuida do estado do atendimento — status, agente responsável,
 * não-lidas, pausa do bot — e da listagem do inbox.
 *
 * PAUSA DO BOT: quando um humano responde, o bot precisa calar. Senão o
 * cliente recebe a resposta do atendente e a do robô ao mesmo tempo, que é o
 * jeito mais rápido de queimar a régua de automação. `bot_pausado_ate` dá uma
 * pausa com validade — passado o prazo, a automação volta sozinha.
 */
class ChatConversaService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    // =========================================================================
    // OBTENÇÃO
    // =========================================================================

    public function obter(int $id): ?array
    {
        $st = $this->db->prepare(
            "SELECT cv.*, c.wa_id, c.nome, c.nome_perfil, c.telefone_exibicao,
                    c.cliente_id, c.email, c.optin, c.bloqueado, c.janela_expira_em,
                    c.campos_json, c.avatar_url, c.ig_username, c.janela_humana_ate,
                    u.nome AS agente_nome
             FROM chat_conversas cv
             JOIN chat_contatos c ON c.id = cv.contato_id
             LEFT JOIN usuarios u ON u.id = cv.atribuido_a
             WHERE cv.id = :id LIMIT 1"
        );
        $st->execute([':id' => $id]);
        $cv = $st->fetch(PDO::FETCH_ASSOC);
        return $cv ? $this->hidratar($cv) : null;
    }

    public function obterPorContato(int $contatoId, string $canal = 'whatsapp'): ?array
    {
        $st = $this->db->prepare(
            "SELECT id FROM chat_conversas WHERE contato_id = :c AND canal = :ca LIMIT 1"
        );
        $st->execute([':c' => $contatoId, ':ca' => $canal]);
        $id = (int)$st->fetchColumn();
        return $id ? $this->obter($id) : null;
    }

    /** Cria a conversa se ainda não existe. Idempotente sob concorrência. */
    public function garantir(int $contatoId, string $canal = 'whatsapp'): array
    {
        $st = $this->db->prepare(
            "INSERT INTO chat_conversas (contato_id, canal) VALUES (:c, :ca)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)"
        );
        $st->execute([':c' => $contatoId, ':ca' => $canal]);
        $id = (int)$this->db->lastInsertId();
        return $this->obter($id) ?? [];
    }

    private function hidratar(array $cv): array
    {
        $ehInstagram = ($cv['canal'] ?? 'whatsapp') === 'instagram';

        $cv['campos']    = json_decode($cv['campos_json'] ?? '{}', true) ?: [];
        $cv['na_janela'] = !empty($cv['janela_expira_em']) && strtotime((string)$cv['janela_expira_em']) > time();
        $cv['bot_ativo'] = !$this->botPausado($cv);

        // Identidade depende do canal: telefone no WhatsApp, @handle no Instagram
        $cv['identificador'] = $ehInstagram
            ? ('@' . ($cv['ig_username'] ?? $cv['wa_id']))
            : ($cv['telefone_exibicao'] ?: $cv['wa_id']);

        $cv['nome_exibicao'] = $cv['nome'] ?: ($cv['nome_perfil'] ?: $cv['identificador']);
        $cv['canal_rotulo']  = $ehInstagram ? 'Instagram' : 'WhatsApp';

        $cv['janela_restante'] = $cv['na_janela']
            ? $this->humanizarIntervalo(strtotime((string)$cv['janela_expira_em']) - time())
            : null;

        // No Instagram, fora das 24h ainda dá para responder por 7 dias com a
        // tag de atendimento humano. A UI precisa saber disso para não mostrar
        // "não dá para responder" quando dá.
        $cv['ig_janela_humana'] = $ehInstagram
            && !empty($cv['janela_humana_ate'])
            && strtotime((string)$cv['janela_humana_ate']) > time();

        $cv['pode_texto_livre'] = $cv['na_janela'] || $cv['ig_janela_humana'];

        return $cv;
    }

    /** O bot está calado nesta conversa? */
    public function botPausado(array $cv): bool
    {
        if ((int)($cv['bot_pausado'] ?? 0) !== 1) return false;
        $ate = $cv['bot_pausado_ate'] ?? null;
        // pausa sem prazo = pausa indefinida (o agente assumiu de vez)
        if ($ate === null) return true;
        return strtotime((string)$ate) > time();
    }

    // =========================================================================
    // INBOX
    // =========================================================================

    /**
     * Listagem do inbox.
     *
     * @param array $f status, agente_id, busca, tags[], nao_lidas, canal, janela
     * @return array{itens:array, total:int}
     */
    public function listarInbox(array $f = [], int $pagina = 1, int $porPagina = 25): array
    {
        $w = ['1=1'];
        $p = [];

        if (!empty($f['status']) && in_array($f['status'], ['aberta', 'pendente', 'resolvida'], true)) {
            $w[] = "cv.status = :status";
            $p[':status'] = $f['status'];
        }
        if (!empty($f['agente_id'])) {
            if ($f['agente_id'] === 'sem') {
                $w[] = "cv.atribuido_a IS NULL";
            } else {
                $w[] = "cv.atribuido_a = :ag";
                $p[':ag'] = (int)$f['agente_id'];
            }
        }
        if (!empty($f['nao_lidas'])) {
            $w[] = "cv.nao_lidas > 0";
        }
        if (!empty($f['janela'])) {
            $w[] = $f['janela'] === 'aberta'
                ? "(c.janela_expira_em IS NOT NULL AND c.janela_expira_em > NOW())"
                : "(c.janela_expira_em IS NULL OR c.janela_expira_em <= NOW())";
        }
        // Filtro por canal — a caixa é unificada, mas às vezes se quer só um
        if (!empty($f['canal']) && in_array($f['canal'], ['whatsapp', 'instagram'], true)) {
            $w[] = "cv.canal = :canal";
            $p[':canal'] = $f['canal'];
        }
        if (!empty($f['busca'])) {
            $termo = '%' . trim((string)$f['busca']) . '%';
            $w[] = "(c.nome LIKE :b1 OR c.nome_perfil LIKE :b2 OR c.wa_id LIKE :b3
                     OR c.ig_username LIKE :b5 OR cv.ultima_preview LIKE :b4)";
            $p[':b1'] = $termo; $p[':b2'] = $termo; $p[':b3'] = $termo;
            $p[':b4'] = $termo; $p[':b5'] = $termo;
        }
        $tags = array_values(array_filter(array_map('intval', (array)($f['tags'] ?? []))));
        if ($tags) {
            $ph = [];
            foreach ($tags as $i => $t) { $ph[] = ":t$i"; $p[":t$i"] = $t; }
            $w[] = "EXISTS (SELECT 1 FROM chat_contato_tags ct
                            WHERE ct.contato_id = c.id AND ct.tag_id IN (" . implode(',', $ph) . "))";
        }

        $where     = 'WHERE ' . implode(' AND ', $w);
        $pagina    = max(1, $pagina);
        $porPagina = max(1, min(100, $porPagina));
        $offset    = ($pagina - 1) * $porPagina;

        $stT = $this->db->prepare(
            "SELECT COUNT(*) FROM chat_conversas cv JOIN chat_contatos c ON c.id = cv.contato_id $where"
        );
        $stT->execute($p);
        $total = (int)$stT->fetchColumn();

        $st = $this->db->prepare(
            "SELECT cv.*, c.wa_id, c.nome, c.nome_perfil, c.telefone_exibicao, c.cliente_id,
                    c.janela_expira_em, c.janela_humana_ate, c.avatar_url, c.optin,
                    c.bloqueado, c.campos_json, c.ig_username,
                    u.nome AS agente_nome
             FROM chat_conversas cv
             JOIN chat_contatos c ON c.id = cv.contato_id
             LEFT JOIN usuarios u ON u.id = cv.atribuido_a
             $where
             ORDER BY (cv.nao_lidas > 0) DESC, COALESCE(cv.ultima_mensagem_em, cv.criado_em) DESC
             LIMIT $porPagina OFFSET $offset"
        );
        $st->execute($p);

        $itens = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $cv) {
            $c = $this->hidratar($cv);
            $c['tags'] = $this->tagsResumidas((int)$cv['contato_id']);
            $itens[] = $c;
        }

        return ['itens' => $itens, 'total' => $total];
    }

    private function tagsResumidas(int $contatoId): array
    {
        $st = $this->db->prepare(
            "SELECT t.id, t.nome, t.cor FROM chat_contato_tags ct
             JOIN chat_tags t ON t.id = ct.tag_id
             WHERE ct.contato_id = :c ORDER BY t.nome LIMIT 6"
        );
        $st->execute([':c' => $contatoId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Contadores dos filtros do inbox (as "abas"). */
    public function contadores(?int $agenteId = null): array
    {
        $out = ['aberta' => 0, 'pendente' => 0, 'resolvida' => 0, 'nao_lidas' => 0, 'minhas' => 0, 'sem_agente' => 0];
        try {
            foreach ($this->db->query(
                "SELECT status, COUNT(*) AS n FROM chat_conversas GROUP BY status"
            )->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[$r['status']] = (int)$r['n'];
            }
            $out['nao_lidas'] = (int)$this->db->query(
                "SELECT COUNT(*) FROM chat_conversas WHERE nao_lidas > 0"
            )->fetchColumn();
            $out['sem_agente'] = (int)$this->db->query(
                "SELECT COUNT(*) FROM chat_conversas WHERE atribuido_a IS NULL AND status <> 'resolvida'"
            )->fetchColumn();
            if ($agenteId) {
                $st = $this->db->prepare(
                    "SELECT COUNT(*) FROM chat_conversas WHERE atribuido_a = :a AND status <> 'resolvida'"
                );
                $st->execute([':a' => $agenteId]);
                $out['minhas'] = (int)$st->fetchColumn();
            }
        } catch (Throwable $e) {}
        return $out;
    }

    // =========================================================================
    // AÇÕES DE ATENDIMENTO
    // =========================================================================

    public function atribuir(int $conversaId, ?int $usuarioId): bool
    {
        $this->db->prepare(
            "UPDATE chat_conversas
             SET atribuido_a = :u, status = IF(status = 'resolvida', 'aberta', status)
             WHERE id = :id"
        )->execute([':u' => $usuarioId ?: null, ':id' => $conversaId]);
        return true;
    }

    public function mudarStatus(int $conversaId, string $status, ?int $usuarioId = null): bool
    {
        if (!in_array($status, ['aberta', 'pendente', 'resolvida'], true)) return false;

        if ($status === 'resolvida') {
            $this->db->prepare(
                "UPDATE chat_conversas
                 SET status = 'resolvida', resolvida_em = NOW(), resolvida_por = :u,
                     nao_lidas = 0, bot_pausado = 0, bot_pausado_ate = NULL
                 WHERE id = :id"
            )->execute([':u' => $usuarioId, ':id' => $conversaId]);
        } else {
            $this->db->prepare(
                "UPDATE chat_conversas
                 SET status = :s, resolvida_em = NULL, resolvida_por = NULL
                 WHERE id = :id"
            )->execute([':s' => $status, ':id' => $conversaId]);
        }
        return true;
    }

    public function marcarLida(int $conversaId): void
    {
        $this->db->prepare("UPDATE chat_conversas SET nao_lidas = 0 WHERE id = :id")
                 ->execute([':id' => $conversaId]);
    }

    /**
     * Pausa o bot. $minutos = 0 pausa por tempo indeterminado (agente assumiu).
     * Chamado automaticamente quando um humano envia mensagem pelo inbox.
     */
    public function pausarBot(int $conversaId, ?int $minutos = null): void
    {
        $minutos = $minutos ?? ChatConfig::int('pausa_bot_minutos', 60);
        $ate = $minutos > 0 ? date('Y-m-d H:i:s', time() + $minutos * 60) : null;
        $this->db->prepare(
            "UPDATE chat_conversas SET bot_pausado = 1, bot_pausado_ate = :a WHERE id = :id"
        )->execute([':a' => $ate, ':id' => $conversaId]);
    }

    public function retomarBot(int $conversaId): void
    {
        $this->db->prepare(
            "UPDATE chat_conversas SET bot_pausado = 0, bot_pausado_ate = NULL WHERE id = :id"
        )->execute([':id' => $conversaId]);
    }

    // =========================================================================
    // ATUALIZAÇÃO PELO FLUXO DE MENSAGENS
    // =========================================================================

    /**
     * Chamado pelo ChatMensagemService a cada mensagem persistida.
     * Mantém preview/contadores sem exigir um COUNT na listagem do inbox.
     */
    public function tocar(int $conversaId, string $direcao, string $preview, bool $incrementaNaoLidas = false): void
    {
        $sql = "UPDATE chat_conversas
                SET ultima_mensagem_em = NOW(),
                    ultima_direcao     = :d,
                    ultima_preview     = :p,
                    total_mensagens    = total_mensagens + 1";

        if ($incrementaNaoLidas) {
            // Uma resposta do cliente reabre o atendimento — deixar 'resolvida'
            // esconderia a mensagem nova da fila do inbox.
            $sql .= ", nao_lidas = nao_lidas + 1,
                       status = IF(status = 'resolvida', 'aberta', status),
                       resolvida_em = IF(status = 'resolvida', NULL, resolvida_em)";
        }
        $sql .= " WHERE id = :id";

        $this->db->prepare($sql)->execute([
            ':d'  => $direcao,
            ':p'  => mb_substr($preview, 0, 250),
            ':id' => $conversaId,
        ]);
    }

    // =========================================================================
    // AGENTES
    // =========================================================================

    /** Admins que podem receber conversas (usuarios.id, não admins.id). */
    public function agentesDisponiveis(): array
    {
        try {
            $st = $this->db->query(
                "SELECT u.id, u.nome, a.nivel
                 FROM admins a
                 JOIN usuarios u ON u.id = a.usuario_id
                 WHERE u.ativo = 1 AND u.deleted_at IS NULL
                 ORDER BY u.nome"
            );
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    private function humanizarIntervalo(int $segundos): string
    {
        if ($segundos <= 0) return 'expirada';
        $h = intdiv($segundos, 3600);
        $m = intdiv($segundos % 3600, 60);
        if ($h > 0) return "{$h}h{$m}min";
        return "{$m}min";
    }
}
