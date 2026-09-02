<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/models/Coupon.php
// Acesso ao banco — sem lógica de negócio aqui.
// ════════════════════════════════════════════════════════

class Coupon {

    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    // ── Leitura ────────────────────────────────────────

    public function findByCodigo(string $codigo): ?array {
        $stmt = $this->db->prepare(
            "SELECT * FROM cupons
             WHERE codigo = ? AND deleted_at IS NULL
             LIMIT 1"
        );
        $stmt->execute([strtoupper(trim($codigo))]);
        return $stmt->fetch() ?: null;
    }

    public function findById(int $id): ?array {
        $stmt = $this->db->prepare(
            "SELECT * FROM cupons WHERE id = ? AND deleted_at IS NULL LIMIT 1"
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Conta quantas vezes um cliente usou este cupom (confirmados).
     */
    public function usosPorCliente(int $cupomId, int $clienteId): int {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM cupom_usos
             WHERE cupom_id  = ?
               AND cliente_id = ?
               AND status IN ('confirmado','aplicado','reservado')"
        );
        $stmt->execute([$cupomId, $clienteId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Total de usos confirmados do cupom (com lock para concorrência).
     * Usar dentro de transação.
     */
    public function totalUsosComLock(int $cupomId): int {
        $stmt = $this->db->prepare(
            "SELECT total_usos FROM cupons WHERE id = ? FOR UPDATE"
        );
        $stmt->execute([$cupomId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Lista para admin com paginação.
     */
    public function listar(array $filtros = [], int $page = 1, int $perPage = 20): array {
        $where  = ['c.deleted_at IS NULL'];
        $params = [];

        if (!empty($filtros['busca'])) {
            $where[] = "(c.codigo LIKE ? OR c.nome LIKE ?)";
            $params[] = '%' . $filtros['busca'] . '%';
            $params[] = '%' . $filtros['busca'] . '%';
        }
        if (isset($filtros['ativo'])) {
            $where[] = "c.ativo = ?";
            $params[] = (int)$filtros['ativo'];
        }
        if (!empty($filtros['tipo'])) {
            $where[] = "c.tipo = ?";
            $params[] = $filtros['tipo'];
        }

        $sql    = "SELECT c.*, " .
                  "(SELECT COUNT(*) FROM cupom_usos cu WHERE cu.cupom_id = c.id AND cu.status = 'confirmado') AS usos_confirmados " .
                  "FROM cupons c " .
                  "WHERE " . implode(' AND ', $where) . " " .
                  "ORDER BY c.criado_em DESC " .
                  "LIMIT ? OFFSET ?";
        $params[] = $perPage;
        $params[] = ($page - 1) * $perPage;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function contar(array $filtros = []): int {
        $where  = ['deleted_at IS NULL'];
        $params = [];
        if (!empty($filtros['busca'])) {
            $where[] = "(codigo LIKE ? OR nome LIKE ?)";
            $params[] = '%' . $filtros['busca'] . '%';
            $params[] = '%' . $filtros['busca'] . '%';
        }
        if (isset($filtros['ativo'])) { $where[] = "ativo = ?"; $params[] = (int)$filtros['ativo']; }
        if (!empty($filtros['tipo']))  { $where[] = "tipo = ?";  $params[] = $filtros['tipo']; }

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM cupons WHERE " . implode(' AND ', $where));
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Histórico de uso de um cupom para o admin.
     */
    public function historico(int $cupomId, int $limit = 50): array {
        $stmt = $this->db->prepare(
            "SELECT cu.*, c.email AS cliente_email, c.nome AS cliente_nome
             FROM cupom_usos cu
             LEFT JOIN clientes c ON c.id = cu.cliente_id
             WHERE cu.cupom_id = ?
             ORDER BY cu.criado_em DESC
             LIMIT ?"
        );
        $stmt->execute([$cupomId, $limit]);
        return $stmt->fetchAll();
    }

    /**
     * Auditoria filtrada para o admin.
     */
    public function auditoria(array $filtros = [], int $limit = 100): array {
        $where  = ['1=1'];
        $params = [];
        if (!empty($filtros['cupom_id']))  { $where[] = "cupom_id = ?";    $params[] = $filtros['cupom_id']; }
        if (!empty($filtros['resultado'])) { $where[] = "resultado = ?";   $params[] = $filtros['resultado']; }
        if (!empty($filtros['pedido_id'])) { $where[] = "pedido_id = ?";   $params[] = $filtros['pedido_id']; }
        if (!empty($filtros['data_de']))   { $where[] = "criado_em >= ?";  $params[] = $filtros['data_de']; }
        if (!empty($filtros['data_ate']))  { $where[] = "criado_em <= ?";  $params[] = $filtros['data_ate'] . ' 23:59:59'; }

        $stmt = $this->db->prepare(
            "SELECT * FROM cupom_auditoria WHERE " . implode(' AND ', $where) .
            " ORDER BY criado_em DESC LIMIT ?"
        );
        $params[] = $limit;
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // ── Escrita ────────────────────────────────────────

    public function salvar(array $data): int {
        if (!empty($data['id'])) {
            return $this->atualizar((int)$data['id'], $data);
        }
        return $this->inserir($data);
    }

    private function inserir(array $data): int {
        $data['codigo'] = strtoupper(trim($data['codigo']));
        $this->db->prepare(
            "INSERT INTO cupons
             (codigo, nome, descricao, tipo, valor, valor_maximo, valor_minimo_pedido,
              ativo, data_inicio, data_fim, limite_total, limite_por_cliente,
              apenas_primeira_compra, permite_produto_promo, acumula_desconto,
              divulgavel,
              escopo_produtos, escopo_categorias, escopo_marcas, escopo_clientes,
              regras_progressivas, campanha_id, campanha_nome, criado_por)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        )->execute([
            $data['codigo'],
            $data['nome'],
            $data['descricao']            ?? null,
            $data['tipo'],
            $data['valor']                ?? null,
            $data['valor_maximo']         ?? null,
            $data['valor_minimo_pedido']  ?? 0,
            $data['ativo']                ?? 1,
            $data['data_inicio']          ?? null,
            $data['data_fim']             ?? null,
            $data['limite_total']         ?? null,
            $data['limite_por_cliente']   ?? 1,
            $data['apenas_primeira_compra'] ?? 0,
            $data['permite_produto_promo']  ?? 1,
            $data['acumula_desconto']       ?? 0,
            // Opt-in: cupom novo NUNCA nasce podendo ser distribuído sozinho
            $data['divulgavel']             ?? 0,
            isset($data['escopo_produtos'])   ? json_encode($data['escopo_produtos'])   : null,
            isset($data['escopo_categorias']) ? json_encode($data['escopo_categorias']) : null,
            isset($data['escopo_marcas'])      ? json_encode($data['escopo_marcas'])     : null,
            isset($data['escopo_clientes'])    ? json_encode($data['escopo_clientes'])   : null,
            isset($data['regras_progressivas']) ? json_encode($data['regras_progressivas']) : null,
            $data['campanha_id']     ?? null,
            $data['campanha_nome']   ?? null,
            $data['criado_por']      ?? null,
        ]);
        return (int)$this->db->lastInsertId();
    }

    private function atualizar(int $id, array $data): int {
        $data['codigo'] = strtoupper(trim($data['codigo']));
        $this->db->prepare(
            "UPDATE cupons SET
             codigo=?, nome=?, descricao=?, tipo=?, valor=?, valor_maximo=?,
             valor_minimo_pedido=?, ativo=?, data_inicio=?, data_fim=?,
             limite_total=?, limite_por_cliente=?, apenas_primeira_compra=?,
             permite_produto_promo=?, acumula_desconto=?, divulgavel=?,
             escopo_produtos=?, escopo_categorias=?, escopo_marcas=?,
             escopo_clientes=?, regras_progressivas=?,
             campanha_id=?, campanha_nome=?, atualizado_por=?
             WHERE id=?"
        )->execute([
            $data['codigo'],
            $data['nome'],
            $data['descricao']            ?? null,
            $data['tipo'],
            $data['valor']                ?? null,
            $data['valor_maximo']         ?? null,
            $data['valor_minimo_pedido']  ?? 0,
            $data['ativo']                ?? 1,
            $data['data_inicio']          ?? null,
            $data['data_fim']             ?? null,
            $data['limite_total']         ?? null,
            $data['limite_por_cliente']   ?? 1,
            $data['apenas_primeira_compra'] ?? 0,
            $data['permite_produto_promo']  ?? 1,
            $data['acumula_desconto']       ?? 0,
            $data['divulgavel']             ?? 0,
            isset($data['escopo_produtos'])   ? json_encode($data['escopo_produtos'])   : null,
            isset($data['escopo_categorias']) ? json_encode($data['escopo_categorias']) : null,
            isset($data['escopo_marcas'])      ? json_encode($data['escopo_marcas'])     : null,
            isset($data['escopo_clientes'])    ? json_encode($data['escopo_clientes'])   : null,
            isset($data['regras_progressivas']) ? json_encode($data['regras_progressivas']) : null,
            $data['campanha_id']    ?? null,
            $data['campanha_nome']  ?? null,
            $data['atualizado_por'] ?? null,
            $id,
        ]);
        return $id;
    }

    public function toggleAtivo(int $id, int $adminId): void {
        $this->db->prepare(
            "UPDATE cupons SET ativo = 1 - ativo, atualizado_por = ? WHERE id = ?"
        )->execute([$adminId, $id]);
    }

    public function softDelete(int $id, int $adminId): void {
        $this->db->prepare(
            "UPDATE cupons SET deleted_at = NOW(), atualizado_por = ? WHERE id = ?"
        )->execute([$adminId, $id]);
    }

    // ── Contadores (chamados após confirmação) ─────────

    public function incrementarUso(int $id, float $desconto): void {
        $this->db->prepare(
            "UPDATE cupons
             SET total_usos = total_usos + 1,
                 total_desconto_concedido = total_desconto_concedido + ?
             WHERE id = ?"
        )->execute([$desconto, $id]);
    }

    public function incrementarRecusa(int $id): void {
        $this->db->prepare(
            "UPDATE cupons SET total_recusas = total_recusas + 1 WHERE id = ?"
        )->execute([$id]);
    }
}