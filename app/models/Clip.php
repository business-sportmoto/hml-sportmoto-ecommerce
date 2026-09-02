<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/models/Clip.php — v2 com múltiplos produtos
// ════════════════════════════════════════════════════════
class Clip extends Model {

    protected string $table = 'clips';

    // ── Feed paginado ────────────────────────────────────
    /**
     * @param int $inicial Clip que deve abrir o feed. Só vale na página 1.
     *                     É o que faz "tocar neste vídeo" abrir NESTE vídeo em
     *                     vez de jogar a pessoa no começo da lista.
     */
    public function getFeed(int $page = 1, int $perPage = 10, bool $apenasDestaque = false, int $inicial = 0): array {
        $offset = ($page - 1) * $perPage;
        $where  = "c.ativo = 1 AND c.status = 'ativo'";
        if ($apenasDestaque) $where .= " AND c.destaque = 1";

        $primeiro = null;

        if ($inicial > 0 && $page === 1) {
            $st = $this->db->prepare(
                "SELECT c.*, u.nome AS autor_nome FROM clips c
                 LEFT JOIN usuarios u ON u.id = c.autor_id
                 WHERE c.id = ? AND {$where} LIMIT 1"
            );
            $st->execute([$inicial]);
            $primeiro = $st->fetch() ?: null;

            if ($primeiro) {
                // Uma vaga a menos na consulta geral, e o próprio clip fora
                // dela — senão ele apareceria duas vezes no feed.
                $where   .= " AND c.id <> " . (int)$inicial;
                $perPage  = max(1, $perPage - 1);
            }
        }

        // O LEFT JOIN traz quem publicou. É LEFT porque `autor_id` é opcional:
        // clip da própria loja não tem autor, e um INNER faria esses clips
        // sumirem do feed inteiro.
        $stmt = $this->db->prepare(
            "SELECT c.*, u.nome AS autor_nome
             FROM clips c
             LEFT JOIN usuarios u ON u.id = c.autor_id
             WHERE {$where}
             ORDER BY c.ordem ASC, c.total_views DESC, c.criado_em DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->bindValue(1, $perPage, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset,  PDO::PARAM_INT);
        $stmt->execute();
        $clips = $stmt->fetchAll();

        if ($primeiro) {
            array_unshift($clips, $primeiro);
        }

        return empty($clips) ? [] : $this->hidratarProdutos($clips);
    }


    // ── Quais produto_ids têm pelo menos 1 clip ativo ───
    // Roda UMA query para toda a listagem — sem N+1.
    //
    // Memoização por request: a home monta várias seções e cada uma chama este
    // método pelo Product::parseClips(). Sem o cache, uma home de 8 seções faz
    // 8 queries idênticas em espírito, e os mesmos produtos aparecem em mais de
    // uma seção. Aqui só os ids ainda não resolvidos vão ao banco; se todos já
    // são conhecidos, a chamada não custa query nenhuma.
    // O cache vive só no request — não há risco de servir dado velho.
    private static array $cacheComClip = [];

    public static function produtosComClip(array $produtoIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $produtoIds)));
        if (!$ids) return [];

        $faltando = array_values(array_diff($ids, array_keys(self::$cacheComClip)));

        if ($faltando) {
            $db   = Database::getInstance()->getConnection();
            $in   = implode(',', array_fill(0, count($faltando), '?'));
            $stmt = $db->prepare(
                "SELECT DISTINCT cp.produto_id
                FROM clip_produtos cp
                INNER JOIN clips c ON c.id = cp.clip_id
                WHERE cp.produto_id IN ({$in})
                    AND c.ativo   = 1
                    AND c.status  = 'ativo'"
            );
            $stmt->execute($faltando);
            $comClip = array_flip(array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'produto_id')));

            // Grava inclusive os que NÃO têm clip: é o que evita reconsultá-los.
            foreach ($faltando as $id) {
                self::$cacheComClip[$id] = isset($comClip[$id]);
            }
        }

        return array_values(array_filter($ids, static fn(int $id) => self::$cacheComClip[$id] ?? false));
    }

        public function countFeed(bool $apenasDestaque = false): int {
        $where = "ativo = 1 AND status = 'ativo'";
        if ($apenasDestaque) $where .= " AND destaque = 1";
        return (int)$this->db->query("SELECT COUNT(*) FROM clips WHERE {$where}")->fetchColumn();
    }

    // ── Clips de um produto ──────────────────────────────
    public function getPorProduto(int $produtoId, int $limit = 10): array {
        $stmt = $this->db->prepare(
            "SELECT c.*, u.nome AS autor_nome
             FROM clips c
             LEFT JOIN usuarios u ON u.id = c.autor_id
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
            "SELECT c.*, u.nome AS autor_nome FROM clips c
             LEFT JOIN usuarios u ON u.id = c.autor_id
             WHERE c.id = ? AND c.ativo = 1 LIMIT 1"
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

    /**
     * Clips para o seletor do formulário de produto.
     *
     * Traz todos, inclusive os inativos, marcados como tais: um clip
     * despublicado continua vinculável, e esconder da lista faria parecer que
     * o vínculo sumiu quando na verdade foi o clip que saiu do ar.
     */
    public function getParaSelecao(int $limite = 500): array {
        // Sem filtro de exclusão: a tabela `clips` não tem deleted_at — o
        // módulo apaga de verdade em vez de marcar.
        $stmt = $this->db->prepare(
            "SELECT id, titulo, ativo FROM clips
           ORDER BY criado_em DESC
              LIMIT ?"
        );
        $stmt->bindValue(1, $limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    // ── Clips vinculados a um produto (lado inverso) ─────
    public function getClipsDoProduto(int $produtoId): array {
        $stmt = $this->db->prepare(
            "SELECT c.id, c.titulo, c.ativo
               FROM clip_produtos cp
               JOIN clips c ON c.id = cp.clip_id
              WHERE cp.produto_id = ?
           ORDER BY c.criado_em DESC"
        );
        $stmt->execute([$produtoId]);
        return $stmt->fetchAll();
    }

    /**
     * Sincroniza os clips de UM produto.
     *
     * Espelho de sincronizarProdutos(), com duas diferenças que importam:
     *
     * 1. Remove só os pares deste produto. Apagar por clip_id, como faz o
     *    outro lado, derrubaria os vínculos daquele clip com produtos que nem
     *    estão nesta tela.
     *
     * 2. O par novo entra no FIM da lista daquele clip (ordem = max + 1). A
     *    coluna `ordem` ordena os produtos DENTRO do clip; gravar 0 aqui
     *    jogaria o produto novo para a frente dos que o clip já tinha.
     */
    public function sincronizarClipsDoProduto(int $produtoId, array $clipIds): void {
        $clipIds = array_values(array_unique(array_filter(array_map('intval', $clipIds))));

        $st = $this->db->prepare("SELECT clip_id FROM clip_produtos WHERE produto_id = ?");
        $st->execute([$produtoId]);
        $atuais = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);

        foreach (array_diff($atuais, $clipIds) as $remover) {
            $this->db->prepare("DELETE FROM clip_produtos WHERE produto_id = ? AND clip_id = ?")
                     ->execute([$produtoId, $remover]);
        }

        $ins = $this->db->prepare(
            "INSERT IGNORE INTO clip_produtos (clip_id, produto_id, ordem)
             VALUES (:clip, :produto,
                     COALESCE((SELECT MAX(x.ordem) + 1 FROM (SELECT ordem FROM clip_produtos
                               WHERE clip_id = :clip2) AS x), 0))"
        );
        foreach (array_diff($clipIds, $atuais) as $novo) {
            $ins->execute([':clip' => $novo, ':produto' => $produtoId, ':clip2' => $novo]);
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

    /**
     * Capa do clip de um produto — só o necessário para desenhar o anel de
     * story na página do produto.
     *
     * getPorProduto() serve o feed e hidrata os produtos de cada clip; usá-la
     * só para saber "existe clip?" custaria queries que a PDP não precisa.
     *
     * @return array{id:int,titulo:?string,arquivo_poster:?string,arquivo_video:?string,total:int}|null
     */
    public function capaDoProduto(int $produtoId): ?array {
        // Dois placeholders para o MESMO valor de propósito: a conexão roda
        // com PDO::ATTR_EMULATE_PREPARES = false (config/database.php:18), e
        // nesse modo o mesmo nome não pode aparecer duas vezes na consulta.
        $stmt = $this->db->prepare(
            "SELECT c.id, c.titulo, c.arquivo_poster, c.arquivo_video,
                    (SELECT COUNT(*)
                       FROM clip_produtos cp2
                       JOIN clips c2 ON c2.id = cp2.clip_id
                      WHERE cp2.produto_id = :pc
                        AND c2.ativo = 1 AND c2.status = 'ativo') AS total
             FROM clips c
             JOIN clip_produtos cp ON cp.clip_id = c.id
             WHERE cp.produto_id = :p AND c.ativo = 1 AND c.status = 'ativo'
             ORDER BY c.ordem ASC, c.total_views DESC
             LIMIT 1"
        );
        $stmt->execute([':p' => $produtoId, ':pc' => $produtoId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }
}
