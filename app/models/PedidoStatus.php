<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/models/PedidoStatus.php
// ════════════════════════════════════════════════════════

class PedidoStatus {

    private PDO $db;

    // Cache estático de sessão para evitar queries repetidas
    private static array $cache = [];

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    // ════════════════════════════════════════════════════
    // LEITURA
    // ════════════════════════════════════════════════════

    /**
     * Todos os status ordenados (admin — ativos e inativos).
     */
    public function getAll(): array {
        $stmt = $this->db->query(
            "SELECT * FROM pedido_status ORDER BY ordenacao ASC, id ASC"
        );
        return $stmt->fetchAll();
    }

    /**
     * Apenas status ativos — usado em dropdowns e timelines.
     */
    public function getAtivos(): array {
        if (!empty(self::$cache['ativos'])) {
            return self::$cache['ativos'];
        }
        $stmt = $this->db->query(
            "SELECT * FROM pedido_status WHERE ativo = 1 ORDER BY ordenacao ASC"
        );
        self::$cache['ativos'] = $stmt->fetchAll();
        return self::$cache['ativos'];
    }

    /**
     * Busca pelo slug — com cache.
     */
    public function findBySlug(string $slug): ?array {
        if (isset(self::$cache['slugs'][$slug])) {
            return self::$cache['slugs'][$slug];
        }
        $stmt = $this->db->prepare(
            "SELECT * FROM pedido_status WHERE slug = ? LIMIT 1"
        );
        $stmt->execute([$slug]);
        $row = $stmt->fetch() ?: null;
        self::$cache['slugs'][$slug] = $row;
        return $row;
    }

    public function findById(int $id): ?array {
        $stmt = $this->db->prepare(
            "SELECT * FROM pedido_status WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Map slug → row para o painel (evita N queries na timeline).
     * Ex: $map['enviado']['cor'] === 'primary'
     */
    public function getMapBySlug(): array {
        $all = $this->getAtivos();
        $map = [];
        foreach ($all as $s) {
            $map[$s['slug']] = $s;
        }
        return $map;
    }

    // ════════════════════════════════════════════════════
    // ESCRITA
    // ════════════════════════════════════════════════════

    /**
     * Cria um status customizado.
     */
    public function criar(array $dados): int {
        $slug = $this->gerarSlug($dados['label'] ?? '', $dados['slug'] ?? '');

        // Valida unicidade do slug
        if ($this->findBySlug($slug)) {
            throw new \RuntimeException("O slug '{$slug}' já existe.");
        }

        $this->db->prepare(
            "INSERT INTO pedido_status
             (slug, label, cor, icone_key, padrao, ativo, ordenacao,
              estorna_estoque, cancela_cupom, bloqueia_edicao_itens, notifica_cliente)
             VALUES (?,?,?,?,0,?,?,?,?,?,?)"
        )->execute([
            $slug,
            trim($dados['label']),
            $dados['cor']      ?? 'info',
            $dados['icone_key']?? null,
            (int)($dados['ativo']    ?? 1),
            (int)($dados['ordenacao']?? 50),
            (int)($dados['estorna_estoque']       ?? 0),
            (int)($dados['cancela_cupom']          ?? 0),
            (int)($dados['bloqueia_edicao_itens']  ?? 1),
            (int)($dados['notifica_cliente']       ?? 1),
        ]);

        self::$cache = []; // invalida cache
        return (int)$this->db->lastInsertId();
    }

    /**
     * Atualiza um status.
     * Status padrão: permite editar tudo exceto o slug.
     */
    public function atualizar(int $id, array $dados): bool {
        $status = $this->findById($id);
        if (!$status) return false;

        // Se for padrão, protege o slug
        if ($status['padrao'] && isset($dados['slug'])) {
            unset($dados['slug']);
        }

        // Se slug foi enviado para custom, re-gera e valida unicidade
        if (!$status['padrao'] && !empty($dados['slug'])) {
            $novoSlug = $this->gerarSlug('', $dados['slug']);
            if ($novoSlug !== $status['slug']) {
                $existente = $this->findBySlug($novoSlug);
                if ($existente && $existente['id'] !== $id) {
                    throw new \RuntimeException("Slug '{$novoSlug}' já está em uso.");
                }
                $dados['slug'] = $novoSlug;
            }
        }

        $this->db->prepare(
            "UPDATE pedido_status SET
                label                 = COALESCE(?, label),
                cor                   = COALESCE(?, cor),
                icone_key             = COALESCE(?, icone_key),
                ativo                 = COALESCE(?, ativo),
                ordenacao             = COALESCE(?, ordenacao),
                estorna_estoque       = COALESCE(?, estorna_estoque),
                cancela_cupom         = COALESCE(?, cancela_cupom),
                bloqueia_edicao_itens = COALESCE(?, bloqueia_edicao_itens),
                notifica_cliente      = COALESCE(?, notifica_cliente),
                slug                  = COALESCE(?, slug)
             WHERE id = ?"
        )->execute([
            isset($dados['label'])                ? trim($dados['label'])                : null,
            $dados['cor']       ?? null,
            $dados['icone_key'] ?? null,
            isset($dados['ativo'])                 ? (int)$dados['ativo']                : null,
            isset($dados['ordenacao'])             ? (int)$dados['ordenacao']            : null,
            isset($dados['estorna_estoque'])        ? (int)$dados['estorna_estoque']      : null,
            isset($dados['cancela_cupom'])          ? (int)$dados['cancela_cupom']        : null,
            isset($dados['bloqueia_edicao_itens'])  ? (int)$dados['bloqueia_edicao_itens']: null,
            isset($dados['notifica_cliente'])       ? (int)$dados['notifica_cliente']     : null,
            $dados['slug'] ?? null,
            $id,
        ]);

        self::$cache = [];
        return true;
    }

    /**
     * Exclui status customizado (padrao = 0).
     * Verifica se há pedidos com este status antes de excluir.
     */
    public function excluir(int $id): array {
        $status = $this->findById($id);
        if (!$status) {
            return ['ok' => false, 'msg' => 'Status não encontrado.'];
        }
        if ($status['padrao']) {
            return ['ok' => false, 'msg' => 'Status padrão do sistema não pode ser excluído.'];
        }

        // Verifica pedidos usando este status
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM pedidos WHERE status_pedido = ?"
        );
        $stmt->execute([$status['slug']]);
        $total = (int)$stmt->fetchColumn();

        if ($total > 0) {
            return [
                'ok'  => false,
                'msg' => "Existem {$total} pedido(s) com este status. Inative-o em vez de excluir.",
            ];
        }

        $this->db->prepare("DELETE FROM pedido_status WHERE id = ? AND padrao = 0")
                 ->execute([$id]);
        self::$cache = [];
        return ['ok' => true];
    }

    /**
     * Reordena status por array de IDs.
     * Ex: reordenar([3,1,5,2,4]) → ordena conforme a posição no array
     */
    public function reordenar(array $ids): void {
        $stmt = $this->db->prepare(
            "UPDATE pedido_status SET ordenacao = ? WHERE id = ?"
        );
        foreach ($ids as $pos => $id) {
            $stmt->execute([($pos + 1) * 10, (int)$id]);
        }
        self::$cache = [];
    }

    // ════════════════════════════════════════════════════
    // HELPERS
    // ════════════════════════════════════════════════════

    /**
     * Gera slug a partir de string (SEO-safe, sem espaços).
     */
    private function gerarSlug(string $label, string $slugOverride = ''): string {
        $base = $slugOverride ?: $label;
        $slug = mb_strtolower(trim($base));
        $slug = preg_replace('/[àáâãäå]/u', 'a', $slug);
        $slug = preg_replace('/[èéêë]/u',   'e', $slug);
        $slug = preg_replace('/[ìíîï]/u',   'i', $slug);
        $slug = preg_replace('/[òóôõö]/u',  'o', $slug);
        $slug = preg_replace('/[ùúûü]/u',   'u', $slug);
        $slug = preg_replace('/[ç]/u',      'c', $slug);
        $slug = preg_replace('/[^a-z0-9_]/u', '_', $slug);
        $slug = preg_replace('/_+/', '_', $slug);
        return trim($slug, '_');
    }
}