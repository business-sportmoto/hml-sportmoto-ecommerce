<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/models/Promocao.php
// Acesso ao banco — sem lógica de negócio aqui.
// ════════════════════════════════════════════════════════

class Promocao {

    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    // ── Leitura ────────────────────────────────────────

    /**
     * Busca todas as promoções ativas elegíveis para avaliação agora.
     * Filtros de data/hora aplicados em SQL para eficiência.
     * A engine de regras (PromocaoService) faz a avaliação fina.
     */
    public function getAtivasAgora(): array {
        $stmt = $this->db->prepare(
            "SELECT * FROM promocoes
             WHERE ativo = 1
               AND deleted_at IS NULL
               AND (data_inicio IS NULL OR data_inicio <= NOW())
               AND (data_fim    IS NULL OR data_fim    >= NOW())
             ORDER BY prioridade DESC, id ASC"
        );
        $stmt->execute();
        $rows = $stmt->fetchAll();

        // Decodifica JSON uma vez aqui, não espalhado pela service
        return array_map([$this, 'decodeJson'], $rows);
    }

    public function findById(int $id): ?array {
        $stmt = $this->db->prepare(
            "SELECT * FROM promocoes WHERE id = ? AND deleted_at IS NULL LIMIT 1"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? $this->decodeJson($row) : null;
    }

    /**
     * Lista para o admin com filtros e paginação.
     */
    public function listar(array $filtros = [], int $page = 1, int $perPage = 20): array {
        [$where, $params] = $this->buildWhere($filtros);

        $stmt = $this->db->prepare(
            "SELECT p.*,
                    (SELECT COUNT(*) FROM promocao_aplicacoes pa
                     WHERE pa.promocao_id = p.id) AS total_aplicacoes_real
             FROM promocoes p
             WHERE {$where}
             ORDER BY p.prioridade DESC, p.criado_em DESC
             LIMIT ? OFFSET ?"
        );
        $params[] = $perPage;
        $params[] = ($page - 1) * $perPage;
        $stmt->execute($params);

        return array_map([$this, 'decodeJson'], $stmt->fetchAll());
    }

    public function contar(array $filtros = []): int {
        [$where, $params] = $this->buildWhere($filtros);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM promocoes WHERE {$where}");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Verifica se já foi aplicada neste pedido (evita double-apply).
     */
    public function jaAplicada(int $promocaoId, int $pedidoId): bool {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM promocao_aplicacoes
             WHERE promocao_id = ? AND pedido_id = ? LIMIT 1"
        );
        $stmt->execute([$promocaoId, $pedidoId]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Total de aplicações (para validar limite global se existir no futuro).
     */
    public function totalAplicacoes(int $promocaoId): int {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM promocao_aplicacoes WHERE promocao_id = ?"
        );
        $stmt->execute([$promocaoId]);
        return (int)$stmt->fetchColumn();
    }

    // ── Escrita ────────────────────────────────────────

    public function salvar(array $data): int {
        return !empty($data['id'])
            ? $this->atualizar((int)$data['id'], $data)
            : $this->inserir($data);
    }

    public function registrarAplicacao(
        int    $promocaoId,
        int    $pedidoId,
        ?int   $clienteId,
        string $tipoBeneficio,
        float  $valorDesconto,
        ?int   $produtoBrindeId = null,
        int    $qtdBrinde = 0,
        array  $detalhes = []
    ): int {
        $this->db->prepare(
            "INSERT INTO promocao_aplicacoes
             (promocao_id, pedido_id, cliente_id, tipo_beneficio,
              valor_desconto, produto_brinde_id, qtd_brinde, detalhes)
             VALUES (?,?,?,?,?,?,?,?)"
        )->execute([
            $promocaoId, $pedidoId, $clienteId, $tipoBeneficio,
            $valorDesconto, $produtoBrindeId, $qtdBrinde,
            json_encode($detalhes, JSON_UNESCAPED_UNICODE),
        ]);

        $this->db->prepare(
            "UPDATE promocoes
             SET total_aplicacoes = total_aplicacoes + 1,
                 total_desconto_concedido = total_desconto_concedido + ?
             WHERE id = ?"
        )->execute([$valorDesconto, $promocaoId]);

        return (int)$this->db->lastInsertId();
    }

    public function toggleAtivo(int $id): void {
        $this->db->prepare(
            "UPDATE promocoes SET ativo = 1 - ativo WHERE id = ?"
        )->execute([$id]);
    }

    public function softDelete(int $id, int $adminId): void {
        $this->db->prepare(
            "UPDATE promocoes SET deleted_at = NOW(), criado_por = ? WHERE id = ?"
        )->execute([$adminId, $id]);
    }

    // ── Helpers privados ───────────────────────────────

    private function buildWhere(array $filtros): array {
        $where  = ['deleted_at IS NULL'];
        $params = [];

        if (!empty($filtros['busca'])) {
            $where[]  = "(nome LIKE ? OR descricao LIKE ?)";
            $params[] = '%' . $filtros['busca'] . '%';
            $params[] = '%' . $filtros['busca'] . '%';
        }
        if (isset($filtros['ativo'])) {
            $where[]  = "ativo = ?";
            $params[] = (int)$filtros['ativo'];
        }
        if (!empty($filtros['tipo'])) {
            $where[]  = "tipo = ?";
            $params[] = $filtros['tipo'];
        }

        return [implode(' AND ', $where), $params];
    }

    private function decodeJson(array $row): array {
        static $jsonCols = [
            'configuracao', 'escopo_produtos', 'escopo_categorias',
            'escopo_marcas', 'escopo_caracteristicas',
            'dias_semana', 'clientes_ids',
        ];
        foreach ($jsonCols as $col) {
            if (isset($row[$col]) && is_string($row[$col])) {
                $row[$col] = json_decode($row[$col], true);
            }
        }
        return $row;
    }

    private function inserir(array $data): int {
        $this->db->prepare(
            "INSERT INTO promocoes
             (nome, descricao, tipo, ativo, prioridade, acumulavel, acumula_cupom,
              data_inicio, data_fim, dias_semana, horario_inicio, horario_fim,
              apenas_primeira_compra, score_minimo, clientes_ids,
              escopo_produtos, escopo_categorias, escopo_marcas,
              escopo_caracteristicas, valor_minimo_carrinho, qtd_minima_itens,
              configuracao, criado_por)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        )->execute($this->bindValues($data));
        return (int)$this->db->lastInsertId();
    }

    private function atualizar(int $id, array $data): int {
        $vals   = $this->bindValues($data);
        $vals[] = $id;
        $this->db->prepare(
            "UPDATE promocoes SET
             nome=?, descricao=?, tipo=?, ativo=?, prioridade=?,
             acumulavel=?, acumula_cupom=?,
             data_inicio=?, data_fim=?, dias_semana=?, horario_inicio=?, horario_fim=?,
             apenas_primeira_compra=?, score_minimo=?, clientes_ids=?,
             escopo_produtos=?, escopo_categorias=?, escopo_marcas=?,
             escopo_caracteristicas=?, valor_minimo_carrinho=?, qtd_minima_itens=?,
             configuracao=?, criado_por=?
             WHERE id=?"
        )->execute($vals);
        return $id;
    }

    private function bindValues(array $d): array {
        return [
            $d['nome'],
            $d['descricao']             ?? null,
            $d['tipo'],
            $d['ativo']                 ?? 1,
            $d['prioridade']            ?? 0,
            $d['acumulavel']            ?? 0,
            $d['acumula_cupom']         ?? 0,
            $d['data_inicio']           ?? null,
            $d['data_fim']              ?? null,
            isset($d['dias_semana'])          ? json_encode($d['dias_semana'])          : null,
            $d['horario_inicio']        ?? null,
            $d['horario_fim']           ?? null,
            $d['apenas_primeira_compra']?? 0,
            $d['score_minimo']          ?? null,
            isset($d['clientes_ids'])         ? json_encode($d['clientes_ids'])         : null,
            isset($d['escopo_produtos'])      ? json_encode($d['escopo_produtos'])      : null,
            isset($d['escopo_categorias'])    ? json_encode($d['escopo_categorias'])    : null,
            isset($d['escopo_marcas'])        ? json_encode($d['escopo_marcas'])        : null,
            isset($d['escopo_caracteristicas'])? json_encode($d['escopo_caracteristicas']): null,
            $d['valor_minimo_carrinho'] ?? null,
            $d['qtd_minima_itens']      ?? null,
            json_encode($d['configuracao'], JSON_UNESCAPED_UNICODE),
            $d['criado_por']            ?? null,
        ];
    }
}