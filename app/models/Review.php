<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/models/Review.php
// ════════════════════════════════════════════════════════
class Review extends Model {

    protected string $table = 'avaliacoes';

    // ── Resumo de avaliações de um produto ───────────────
    public function getResumo(int $produtoId): array {
        $stmt = $this->db->prepare(
            "SELECT
                COUNT(*)                                     AS total,
                COALESCE(AVG(nota), 0)                       AS media,
                SUM(CASE WHEN nota = 5 THEN 1 ELSE 0 END)   AS n5,
                SUM(CASE WHEN nota = 4 THEN 1 ELSE 0 END)   AS n4,
                SUM(CASE WHEN nota = 3 THEN 1 ELSE 0 END)   AS n3,
                SUM(CASE WHEN nota = 2 THEN 1 ELSE 0 END)   AS n2,
                SUM(CASE WHEN nota = 1 THEN 1 ELSE 0 END)   AS n1
             FROM avaliacoes
             WHERE produto_id = ? AND aprovado = 1"
        );
        $stmt->execute([$produtoId]);
        return $stmt->fetch() ?: [];
    }


    // ── Resumo em lote — para listagens sem N+1 ─────────
    // Retorna array indexado por produto_id:
    // [ 12 => ['media'=>4.7,'total'=>23], 45 => [...] ]
    public function getResumoEmLote(array $produtoIds): array {
        if (empty($produtoIds)) return [];

        $in   = implode(',', array_fill(0, count($produtoIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT produto_id,
                    COUNT(*)                  AS total,
                    COALESCE(AVG(nota), 0)    AS media
             FROM avaliacoes
             WHERE produto_id IN ({$in}) AND aprovado = 1
             GROUP BY produto_id"
        );
        $stmt->execute(array_values($produtoIds));

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int)$row['produto_id']] = [
                'media' => round((float)$row['media'], 1),
                'total' => (int)$row['total'],
            ];
        }
        return $result;
    }
    // ── Lista avaliações paginadas com filtros ───────────
    public function listar(
        int    $produtoId,
        int    $page      = 1,
        int    $perPage   = 4,
        string $filtro    = 'todas',
        string $ordem     = 'recentes'
    ): array {
        $offset = ($page - 1) * $perPage;

        $where  = "a.produto_id = ? AND a.aprovado = 1";
        $params = [$produtoId];

        switch ($filtro) {
            case 'fotos':
                $where .= " AND EXISTS (SELECT 1 FROM avaliacao_midias m WHERE m.avaliacao_id=a.id AND m.tipo='imagem' AND m.aprovada=1)";
                break;
            case 'videos':
                $where .= " AND EXISTS (SELECT 1 FROM avaliacao_midias m WHERE m.avaliacao_id=a.id AND m.tipo='video' AND m.aprovada=1)";
                break;
            case '5': case '4': case '3': case '2': case '1':
                $where   .= " AND a.nota = ?";
                $params[] = (int)$filtro;
                break;
        }

        $orderSql = match ($ordem) {
            'uteis'  => 'a.util_sim DESC, a.criado_em DESC',
            'maior'  => 'a.nota DESC, a.criado_em DESC',
            'menor'  => 'a.nota ASC, a.criado_em DESC',
            default  => 'a.destaque DESC, a.criado_em DESC',
        };

        $stmt = $this->db->prepare(
            "SELECT a.*,
                    COALESCE(a.cliente_nome, u.nome, 'Usuário') AS nome_exibido,
                    (a.pedido_id IS NOT NULL)                   AS verificado
             FROM avaliacoes a
             LEFT JOIN clientes c  ON c.id = a.cliente_id
             LEFT JOIN usuarios u  ON u.id = c.usuario_id
             WHERE {$where}
             ORDER BY {$orderSql}
             LIMIT ? OFFSET ?"
        );
        $params[] = $perPage;
        $params[] = $offset;
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        if (empty($rows)) return [];

        // Carrega mídias em lote (evita N+1)
        $ids  = array_column($rows, 'id');
        $in   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT * FROM avaliacao_midias
             WHERE avaliacao_id IN ({$in}) AND aprovada = 1
             ORDER BY avaliacao_id, ordem ASC"
        );
        $stmt->execute($ids);
        $midias = $stmt->fetchAll();

        $midiasPorId = [];
        foreach ($midias as $m) {
            $midiasPorId[$m['avaliacao_id']][] = $m;
        }

        foreach ($rows as &$r) {
            $r['midias'] = $midiasPorId[$r['id']] ?? [];
        }

        return $rows;
    }

    public function countFiltrado(int $produtoId, string $filtro): int {
        $where  = "produto_id = ? AND aprovado = 1";
        $params = [$produtoId];

        switch ($filtro) {
            case 'fotos':
                $where .= " AND EXISTS (SELECT 1 FROM avaliacao_midias m WHERE m.avaliacao_id=id AND m.tipo='imagem' AND m.aprovada=1)";
                break;
            case 'videos':
                $where .= " AND EXISTS (SELECT 1 FROM avaliacao_midias m WHERE m.avaliacao_id=id AND m.tipo='video' AND m.aprovada=1)";
                break;
            case '5': case '4': case '3': case '2': case '1':
                $where   .= " AND nota = ?";
                $params[] = (int)$filtro;
                break;
        }

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM avaliacoes WHERE {$where}");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    // ── Galeria global de mídias do produto ──────────────
    public function getMidiasGlobal(int $produtoId, int $limit = 20): array {
        $stmt = $this->db->prepare(
            "SELECT m.*, a.nota
             FROM avaliacao_midias m
             JOIN avaliacoes a ON a.id = m.avaliacao_id
             WHERE a.produto_id = ? AND a.aprovado = 1 AND m.aprovada = 1
             ORDER BY a.criado_em DESC
             LIMIT ?"
        );
        $stmt->bindValue(1, $produtoId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit,     PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    // ── Verifica se cliente já avaliou este produto ───────
    public function jaAvaliou(int $produtoId, int $clienteId): bool {
        $stmt = $this->db->prepare(
            "SELECT id FROM avaliacoes WHERE produto_id=? AND cliente_id=? LIMIT 1"
        );
        $stmt->execute([$produtoId, $clienteId]);
        return (bool)$stmt->fetchColumn();
    }

    // ── Salvar avaliação ─────────────────────────────────
    public function salvar(array $dados): int {
        $this->db->prepare(
            "INSERT INTO avaliacoes
             (produto_id, cliente_id, pedido_id, cliente_nome,
              nota, titulo, comentario, aprovado, ip_origem)
             VALUES (?,?,?,?,?,?,?,?,?)"
        )->execute([
            $dados['produto_id'],
            $dados['cliente_id']   ?? null,
            $dados['pedido_id']    ?? null,
            $dados['cliente_nome'] ?? null,
            $dados['nota'],
            $dados['titulo']       ?? null,
            $dados['comentario'],
            $dados['aprovado']     ?? 0,
            $dados['ip']           ?? null,
        ]);
        return (int)$this->db->lastInsertId();
    }

    // ── Vincular mídias temporárias ──────────────────────
    public function vincularMidias(int $avaliacaoId, string $token): void {
        $stmt = $this->db->prepare(
            "SELECT * FROM avaliacao_midias_temp WHERE token = ?"
        );
        $stmt->execute([$token]);
        $temps = $stmt->fetchAll();

        $ins = $this->db->prepare(
            "INSERT INTO avaliacao_midias (avaliacao_id, tipo, arquivo, arquivo_thumb, ordem)
             VALUES (?,?,?,?,?)"
        );
        foreach ($temps as $i => $t) {
            $ins->execute([$avaliacaoId, $t['tipo'], $t['arquivo'], $t['thumb'], $i]);
        }

        // Remove temporários
        $this->db->prepare("DELETE FROM avaliacao_midias_temp WHERE token=?")
                 ->execute([$token]);
    }

    // ── Toggle voto útil ─────────────────────────────────
    public function toggleUtil(int $id, ?int $clienteId, string $sessao, string $ip): bool {
        $jaVotou = $this->jaVotou($id, $clienteId, $sessao);

        if ($jaVotou) {
            if ($clienteId) {
                $this->db->prepare("DELETE FROM avaliacao_util_votos WHERE avaliacao_id=? AND cliente_id=?")
                         ->execute([$id, $clienteId]);
            } else {
                $this->db->prepare("DELETE FROM avaliacao_util_votos WHERE avaliacao_id=? AND session_key=?")
                         ->execute([$id, $sessao]);
            }
            $this->db->prepare(
                "UPDATE avaliacoes SET util_sim = GREATEST(util_sim-1, 0) WHERE id=?"
            )->execute([$id]);
            return false;
        }

        $this->db->prepare(
            "INSERT IGNORE INTO avaliacao_util_votos (avaliacao_id, cliente_id, session_key, ip)
             VALUES (?,?,?,?)"
        )->execute([$id, $clienteId ?: null, $sessao, $ip]);
        $this->db->prepare("UPDATE avaliacoes SET util_sim = util_sim+1 WHERE id=?")->execute([$id]);
        return true;
    }

    public function jaVotou(int $id, ?int $clienteId, string $sessao): bool {
        if ($clienteId) {
            $stmt = $this->db->prepare("SELECT id FROM avaliacao_util_votos WHERE avaliacao_id=? AND cliente_id=? LIMIT 1");
            $stmt->execute([$id, $clienteId]);
        } else {
            $stmt = $this->db->prepare("SELECT id FROM avaliacao_util_votos WHERE avaliacao_id=? AND session_key=? LIMIT 1");
            $stmt->execute([$id, $sessao]);
        }
        return (bool)$stmt->fetchColumn();
    }

    // ── Rate limit ───────────────────────────────────────
    public function checarRateLimit(string $ip, int $max = 3): bool {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM avaliacao_rate_limit
             WHERE ip=? AND acao='enviar' AND criado_em > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
        $stmt->execute([$ip]);
        if ((int)$stmt->fetchColumn() >= $max) return false;

        $this->db->prepare("INSERT INTO avaliacao_rate_limit (ip) VALUES (?)")->execute([$ip]);
        return true;
    }

    // ── Votos "útil" em lote ─────────────────────────────
    // A listagem da web chama jaVotou() dentro do laço: uma query por
    // avaliação na tela. Aqui é uma só para a página inteira.
    //
    // Cliente logado é identificado por cliente_id; visitante, pela
    // session_key — as duas colunas coexistem em avaliacao_util_votos.
    //
    // @param int[] $ids
    // @return array<int,bool>  [avaliacao_id => votou]
    public function votosEmLote(array $ids, ?int $clienteId, string $sessao): array {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (empty($ids)) return [];

        // Sem identidade nenhuma não há como ter votado.
        if (!$clienteId && $sessao === '') return [];

        $in = implode(',', array_fill(0, count($ids), '?'));

        if ($clienteId) {
            $sql    = "SELECT avaliacao_id FROM avaliacao_util_votos
                       WHERE avaliacao_id IN ({$in}) AND cliente_id = ?";
            $params = array_merge($ids, [$clienteId]);
        } else {
            $sql    = "SELECT avaliacao_id FROM avaliacao_util_votos
                       WHERE avaliacao_id IN ({$in}) AND session_key = ?";
            $params = array_merge($ids, [$sessao]);
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN, 0) as $id) {
            $out[(int)$id] = true;
        }
        return $out;
    }

    // ── Quantas avaliações do produto têm foto ou vídeo ──
    // Alimenta o filtro "com mídia" do app: sem o número, o app teria de
    // oferecer um filtro que pode não devolver nada.
    public function contarComMidia(int $produtoId): int {
        $stmt = $this->db->prepare(
            "SELECT COUNT(DISTINCT a.id)
             FROM avaliacoes a
             JOIN avaliacao_midias m ON m.avaliacao_id = a.id AND m.aprovada = 1
             WHERE a.produto_id = ? AND a.aprovado = 1"
        );
        $stmt->execute([$produtoId]);
        return (int)$stmt->fetchColumn();
    }
}
