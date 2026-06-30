<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/models/Clip.php — v2 com múltiplos produtos
// ════════════════════════════════════════════════════════
class Clip extends Model {

    protected string $table = 'clips';

    // ── Feed paginado ────────────────────────────────────
    public function getFeed(int $page = 1, int $perPage = 10, bool $apenasDestaque = false): array {
        $offset = ($page - 1) * $perPage;
        $where  = "c.ativo = 1 AND c.status = 'ativo'";
        if ($apenasDestaque) $where .= " AND c.destaque = 1";

        $stmt = $this->db->prepare(
            "SELECT c.*
             FROM clips c
             WHERE {$where}
             ORDER BY c.ordem ASC, c.total_views DESC, c.criado_em DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->bindValue(1, $perPage, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset,  PDO::PARAM_INT);
        $stmt->execute();
        $clips = $stmt->fetchAll();

        return empty($clips) ? [] : $this->hidratarProdutos($clips);
    }


    // ── Quais produto_ids têm pelo menos 1 clip ativo ───
    // Roda UMA query para toda a listagem — sem N+1.
    public static function produtosComClip(array $produtoIds): array
    {
        if (empty($produtoIds)) return [];

        $db   = Database::getInstance()->getConnection();
        $in   = implode(',', array_fill(0, count($produtoIds), '?'));
        $stmt = $db->prepare(
            "SELECT DISTINCT cp.produto_id
            FROM clip_produtos cp
            INNER JOIN clips c ON c.id = cp.clip_id
            WHERE cp.produto_id IN ({$in})
                AND c.ativo   = 1
                AND c.status  = 'ativo'"
        );
        $stmt->execute(array_values($produtoIds));
        return array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'produto_id'));
    }

        public function countFeed(bool $apenasDestaque = false): int {
        $where = "ativo = 1 AND status = 'ativo'";
        if ($apenasDestaque) $where .= " AND destaque = 1";
        return (int)$this->db->query("SELECT COUNT(*) FROM clips WHERE {$where}")->fetchColumn();
    }

    // ── Clips de um produto ──────────────────────────────
    public function getPorProduto(int $produtoId, int $limit = 10): array {
        $stmt = $this->db->prepare(
            "SELECT c.*
             FROM clips c
             JOIN clip_produtos cp ON cp.clip_id = c.id
             WHERE cp.produto_id = ? AND c.ativo = 1 AND c.status = 'ativo'
             ORDER BY c.ordem ASC, c.total_views DESC
             LIMIT ?"
        );
        $stmt->bindValue(1, $produtoId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit,     PDO::PARAM_INT);
        $stmt->execute();
        $clips = $stmt->fetchAll();

        return empty($clips) ? [] : $this->hidratarProdutos($clips);
    }

    // ── Clip individual ──────────────────────────────────
    public function getComProdutos(int $id): ?array {
        $stmt = $this->db->prepare(
            "SELECT c.* FROM clips c WHERE c.id = ? AND c.ativo = 1 LIMIT 1"
        );
        $stmt->execute([$id]);
        $clip = $stmt->fetch();
        if (!$clip) return null;

        $results = $this->hidratarProdutos([$clip]);
        return $results[0] ?? null;
    }

    /** @deprecated use getComProdutos */
    public function getComProduto(int $id): ?array {
        return $this->getComProdutos($id);
    }

    // ── Hidratar produtos em lote (evita N+1) ───────────
    private function hidratarProdutos(array $clips): array {
        $ids  = array_column($clips, 'id');
        $in   = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $this->db->prepare(
            "SELECT cp.clip_id,
                    p.id         AS produto_id,
                    p.nome       AS produto_nome,
                    p.slug       AS produto_slug,
                    p.preco      AS produto_preco,
                    p.preco_promo AS produto_preco_promo,
                    pi.arquivo   AS produto_imagem,
                    cp.ordem
             FROM clip_produtos cp
             JOIN produtos p ON p.id = cp.produto_id AND p.ativo = 1 AND p.deleted_at IS NULL
             LEFT JOIN produto_imagens pi ON pi.produto_id = p.id AND pi.principal = 1
             WHERE cp.clip_id IN ({$in})
             ORDER BY cp.clip_id, cp.ordem ASC"
        );
        $stmt->execute($ids);
        $linhas = $stmt->fetchAll();

        // Agrupa por clip_id
        $porClip = [];
        foreach ($linhas as $l) {
            $porClip[$l['clip_id']][] = $l;
        }

        foreach ($clips as &$c) {
            $c['produtos'] = $porClip[$c['id']] ?? [];
        }

        return $clips;
    }

    // ── Sincronizar produtos de um clip ──────────────────
    public function sincronizarProdutos(int $clipId, array $produtoIds): void {
        $this->db->prepare("DELETE FROM clip_produtos WHERE clip_id=?")->execute([$clipId]);
        $stmt = $this->db->prepare(
            "INSERT IGNORE INTO clip_produtos (clip_id, produto_id, ordem) VALUES (?,?,?)"
        );
        foreach (array_values($produtoIds) as $ordem => $pid) {
            if ((int)$pid > 0) $stmt->execute([$clipId, (int)$pid, $ordem]);
        }
    }

    // ── Produtos vinculados a um clip ────────────────────
    public function getProdutosDoClip(int $clipId): array {
        $stmt = $this->db->prepare(
            "SELECT cp.produto_id, p.nome, p.slug
             FROM clip_produtos cp
             JOIN produtos p ON p.id = cp.produto_id
             WHERE cp.clip_id = ?
             ORDER BY cp.ordem ASC"
        );
        $stmt->execute([$clipId]);
        return $stmt->fetchAll();
    }

    // ── Like ─────────────────────────────────────────────
    public function jaÇurtiu(int $clipId, ?int $clienteId, string $sessionKey): bool {
        if ($clienteId) {
            $stmt = $this->db->prepare(
                "SELECT id FROM clip_likes WHERE clip_id=? AND cliente_id=? LIMIT 1"
            );
            $stmt->execute([$clipId, $clienteId]);
        } else {
            $stmt = $this->db->prepare(
                "SELECT id FROM clip_likes WHERE clip_id=? AND session_key=? LIMIT 1"
            );
            $stmt->execute([$clipId, $sessionKey]);
        }
        return (bool)$stmt->fetchColumn();
    }

    public function toggleLike(int $clipId, ?int $clienteId, string $sessionKey, string $ip): bool {
        if ($this->jaÇurtiu($clipId, $clienteId, $sessionKey)) {
            if ($clienteId) {
                $this->db->prepare("DELETE FROM clip_likes WHERE clip_id=? AND cliente_id=?")
                         ->execute([$clipId, $clienteId]);
            } else {
                $this->db->prepare("DELETE FROM clip_likes WHERE clip_id=? AND session_key=?")
                         ->execute([$clipId, $sessionKey]);
            }
            $this->db->prepare(
                "UPDATE clips SET total_likes = GREATEST(total_likes-1, 0) WHERE id=?"
            )->execute([$clipId]);
            return false;
        }

        $this->db->prepare(
            "INSERT IGNORE INTO clip_likes (clip_id, cliente_id, session_key, ip) VALUES (?,?,?,?)"
        )->execute([$clipId, $clienteId ?: null, $sessionKey, $ip]);
        $this->db->prepare("UPDATE clips SET total_likes = total_likes+1 WHERE id=?")
                 ->execute([$clipId]);
        return true;
    }

    // ── Views ────────────────────────────────────────────
    public function registrarView(int $clipId, string $sessionKey, string $ip): void {
        $ins = $this->db->prepare(
            "INSERT IGNORE INTO clip_views (clip_id, session_key, ip) VALUES (?,?,?)"
        );
        $ins->execute([$clipId, $sessionKey, $ip]);
        if ($ins->rowCount() > 0) {
            $this->db->prepare(
                "UPDATE clips SET total_views = total_views+1 WHERE id=?"
            )->execute([$clipId]);
        }
    }

    public function registrarShare(int $clipId): void {
        $this->db->prepare(
            "UPDATE clips SET total_compartilhamentos = total_compartilhamentos+1 WHERE id=?"
        )->execute([$clipId]);
    }

    // ── Comentários ──────────────────────────────────────
    public function getComentarios(int $clipId, int $page = 1): array {
        $perPage = 20;
        $offset  = ($page - 1) * $perPage;
        $stmt    = $this->db->prepare(
            "SELECT id, nome, texto, criado_em FROM clip_comentarios
             WHERE clip_id = ? AND status = 'aprovado'
             ORDER BY criado_em ASC
             LIMIT ? OFFSET ?"
        );
        $stmt->bindValue(1, $clipId,  PDO::PARAM_INT);
        $stmt->bindValue(2, $perPage, PDO::PARAM_INT);
        $stmt->bindValue(3, $offset,  PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function addComentario(int $clipId, string $nome, string $texto, ?int $clienteId, string $ip): array {
        $status = $clienteId ? 'aprovado' : 'pendente';

        $this->db->prepare(
            "INSERT INTO clip_comentarios (clip_id, cliente_id, nome, texto, status, ip)
             VALUES (?,?,?,?,?,?)"
        )->execute([$clipId, $clienteId ?: null, $nome, $texto, $status, $ip]);

        $id = (int)$this->db->lastInsertId();

        if ($status === 'aprovado') {
            $this->db->prepare(
                "UPDATE clips SET total_comentarios = total_comentarios+1 WHERE id=?"
            )->execute([$clipId]);
        }

        return [
            'id'        => $id,
            'nome'      => $nome,
            'texto'     => $texto,
            'status'    => $status,
            'aprovado'  => $status === 'aprovado',
            'criado_em' => date('Y-m-d H:i:s'),
        ];
    }

    // ── Rate limit ───────────────────────────────────────
    public function checarRateLimit(string $ip, string $acao, int $max = 10): bool {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM clip_rate_limit
             WHERE ip=? AND acao=? AND criado_em > DATE_SUB(NOW(), INTERVAL 1 MINUTE)"
        );
        $stmt->execute([$ip, $acao]);
        if ((int)$stmt->fetchColumn() >= $max) return false;

        $this->db->prepare("INSERT INTO clip_rate_limit (ip, acao) VALUES (?,?)")
                 ->execute([$ip, $acao]);
        return true;
    }
}





// ════════════════════════════════════════════════════════
// admin/controllers/ClipsController.php — atualização salvar()
// ════════════════════════════════════════════════════════
// Substitui apenas o método salvar() no controller existente:

/*

*/