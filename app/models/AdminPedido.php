<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/models/AdminPedido.php
//
// Queries do módulo de pedidos no painel admin.
// Sem lógica de negócio — apenas acesso ao banco.
// ════════════════════════════════════════════════════════

class AdminPedido {

    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    // ════════════════════════════════════════════════════
    // LISTAGEM
    // ════════════════════════════════════════════════════

    /**
     * Lista pedidos com filtros, paginação e dados do cliente.
     */
    public function listar(array $filtros = [], int $page = 1, int $perPage = 20): array {
        [$where, $params] = $this->buildWhere($filtros);

        $params[] = $perPage;
        $params[] = ($page - 1) * $perPage;

        $stmt = $this->db->prepare(
            "SELECT
                p.id, p.codigo, p.status_pedido, p.status_pagamento,
                p.forma_pagamento, p.parcelas,
                p.subtotal, p.desconto, p.frete, p.total,
                p.cartao_bandeira, p.cartao_ultimos_4,
                p.pago_em, p.codigo_rastreio,
                p.criado_em, p.atualizado_em,
                -- Cliente
                u.nome           AS cliente_nome,
                u.email          AS cliente_email,
                c.cpf            AS cliente_cpf,
                c.telefone       AS cliente_telefone,
                p.cliente_id,
                -- Primeiro item (thumbnail)
                (SELECT pr.nome FROM pedido_itens pi2
                 JOIN produtos pr ON pr.id = pi2.produto_id
                 WHERE pi2.pedido_id = p.id ORDER BY pi2.id ASC LIMIT 1
                ) AS primeiro_produto,
                (SELECT pr.id FROM pedido_itens pi2
                 JOIN produtos pr ON pr.id = pi2.produto_id
                 WHERE pi2.pedido_id = p.id ORDER BY pi2.id ASC LIMIT 1
                ) AS primeiro_produto_id,
                (SELECT img.arquivo FROM pedido_itens pi3
                 JOIN produto_imagens img
                      ON img.produto_id = pi3.produto_id AND img.principal = 1
                 WHERE pi3.pedido_id = p.id ORDER BY pi3.id ASC LIMIT 1
                ) AS primeira_imagem,
                (SELECT SUM(pi4.quantidade) FROM pedido_itens pi4
                 WHERE pi4.pedido_id = p.id
                ) AS total_itens
             FROM pedidos p
             JOIN clientes  c ON c.id        = p.cliente_id
             JOIN usuarios  u ON u.id        = c.usuario_id
             WHERE {$where}
             ORDER BY p.criado_em DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Conta pedidos com os mesmos filtros da listagem.
     */
    public function contar(array $filtros = []): int {
        [$where, $params] = $this->buildWhere($filtros);
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM pedidos p
             JOIN clientes c ON c.id = p.cliente_id
             JOIN usuarios u ON u.id = c.usuario_id
             WHERE {$where}"
        );
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /**
     * KPIs da listagem (contagem e soma por status).
     */
    public function getKpis(): array {
        $stmt = $this->db->query(
            "SELECT
                COUNT(*)                                                    AS total,
                SUM(CASE WHEN status_pagamento = 'aprovado' THEN total END) AS receita_total,
                SUM(CASE WHEN status_pedido = 'aguardando_pagamento'
                    AND DATE(criado_em) = CURDATE() THEN 1 END)             AS novos_hoje,
                SUM(CASE WHEN status_pedido = 'em_separacao'   THEN 1 END) AS em_separacao,
                SUM(CASE WHEN status_pedido = 'enviado'         THEN 1 END) AS enviados,
                SUM(CASE WHEN status_pedido = 'cancelado'       THEN 1 END) AS cancelados,
                SUM(CASE WHEN status_pagamento = 'pendente'
                    AND status_pedido != 'cancelado' THEN 1 END)            AS aguardando_pagamento
             FROM pedidos"
        );
        return $stmt->fetch();
    }

    /**
     * Contagem por status para os chips de filtro.
     */
    public function getContagensPorStatus(): array {
        $stmt = $this->db->query(
            "SELECT status_pedido, COUNT(*) AS total FROM pedidos GROUP BY status_pedido"
        );
        $counts = [];
        foreach ($stmt->fetchAll() as $row) {
            $counts[$row['status_pedido']] = (int)$row['total'];
        }
        return $counts;
    }

    // ════════════════════════════════════════════════════
    // DETALHE DO PEDIDO
    // ════════════════════════════════════════════════════

    /**
     * Carrega pedido completo com cliente, endereço e NF.
     */
    public function findById(int $id): ?array {
        $stmt = $this->db->prepare(
            "SELECT
                p.*,
                -- Cliente
                u.nome           AS cliente_nome,
                u.email          AS cliente_email,
                c.cpf            AS cliente_cpf,
                c.telefone       AS cliente_telefone,
                c.id             AS cliente_id_real,
                -- Endereço de entrega
                e.nome_destinatario AS ent_destinatario,
                e.logradouro        AS ent_logradouro,
                e.numero            AS ent_numero,
                e.complemento       AS ent_complemento,
                e.bairro            AS ent_bairro,
                e.cidade            AS ent_cidade,
                e.estado            AS ent_estado,
                e.cep               AS ent_cep,
                -- NF
                nfe.id              AS nfe_id,
                nfe.numero          AS nfe_numero,
                nfe.serie           AS nfe_serie,
                nfe.valorNota       AS nfe_valor,
                nfe.dataEmissao     AS nfe_emissao,
                nfe.linkPDF         AS nfe_pdf,
                nfe.linkDanfe       AS nfe_danfe,
                nfe.chaveAcesso     AS nfe_chave
             FROM pedidos p
             JOIN clientes c ON c.id = p.cliente_id
             JOIN usuarios u ON u.id = c.usuario_id
             LEFT JOIN enderecos e   ON e.id = p.endereco_entrega_id
             LEFT JOIN pedidos_nfe nfe ON nfe.pedido_id = p.id
             WHERE p.id = ?
             LIMIT 1"
        );
        $stmt->execute([$id]);
        $pedido = $stmt->fetch();
        if (!$pedido) return null;

        // Normaliza
        $pedido['pago_em']          ??= null;
        $pedido['cartao_bandeira']  ??= null;
        $pedido['cartao_ultimos_4'] ??= null;
        $pedido['codigo_rastreio']  ??= null;
        $pedido['frete_descricao']  = $pedido['frete_descricao'] ?? $pedido['frete_servico'] ?? null;

        return $pedido;
    }

    /**
     * Itens do pedido com dados do produto e variações.
     */
    public function getItens(int $pedidoId): array {
        $stmt = $this->db->prepare(
            "SELECT
                pi.*,
                COALESCE(pi.nome_produto, pr.nome) AS nome_produto,
                pr.slug     AS produto_slug,
                pr.ativo    AS produto_ativo,
                pr.preco    AS preco_atual,
                pr.id       AS produto_id,
                COALESCE(pi.imagem_snapshot,
                    (SELECT img.arquivo FROM produto_imagens img
                     WHERE img.produto_id = pr.id AND img.principal = 1 LIMIT 1)
                )          AS imagem,
                COALESCE(pi.sku, ps.sku) AS sku,
                ps.estoque AS estoque_sku,
                pr.estoque_total AS estoque_produto,
                -- Atributos do SKU concatenados
                (SELECT GROUP_CONCAT(CONCAT(at.nome,': ',sa.valor) ORDER BY at.ordenacao SEPARATOR ' · ')
                 FROM sku_atributos sa
                 JOIN atributo_tipos at ON at.id = sa.atributo_tipo_id
                 WHERE sa.sku_id = pi.sku
                ) AS variacao_label
             FROM pedido_itens pi
             JOIN produtos pr     ON pr.id = pi.produto_id
             LEFT JOIN produto_skus ps ON ps.id = pi.sku
             WHERE pi.pedido_id = ?
             ORDER BY pi.id ASC"
        );
        $stmt->execute([$pedidoId]);
        return $stmt->fetchAll();
    }

    /**
     * Histórico de status do pedido.
     */
    public function getHistorico(int $pedidoId): array {
        try {
            $stmt = $this->db->prepare(
                "SELECT ph.*, u.nome AS admin_nome
                 FROM pedido_historico ph
                 LEFT JOIN usuarios u ON u.id = ph.admin_id
                 WHERE ph.pedido_id = ?
                 ORDER BY ph.criado_em DESC"
            );
            $stmt->execute([$pedidoId]);
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            return [];
        }
    }

    /**
     * NF completa do pedido.
     */
    public function getNfe(int $pedidoId): ?array {
        $stmt = $this->db->prepare(
            "SELECT * FROM pedidos_nfe WHERE pedido_id = ? LIMIT 1"
        );
        $stmt->execute([$pedidoId]);
        return $stmt->fetch() ?: null;
    }

    // ════════════════════════════════════════════════════
    // ESCRITA — PEDIDO
    // ════════════════════════════════════════════════════

    /**
     * Atualiza status do pedido.
     */
    public function updateStatus(int $id, string $status, ?string $obs, int $adminId): void {
        $this->db->prepare(
            "UPDATE pedidos SET status_pedido = ?, atualizado_em = NOW() WHERE id = ?"
        )->execute([$status, $id]);

        // Log no histórico
        $this->db->prepare(
            "INSERT INTO pedido_historico (pedido_id, status_novo, observacao, admin_id)
             VALUES (?, ?, ?, ?)"
        )->execute([$id, $status, $obs, $adminId]);
    }

    /**
     * Grava (ou limpa) o motivo de cancelamento do pedido.
     *
     * Passar null nos dois argumentos limpa — é o que acontece quando
     * um pedido cancelado é reativado.
     */
    public function setMotivoCancelamento(int $id, ?int $motivoId, ?string $obs): void {
        // Motivo inexistente vira NULL em vez de FK quebrada: o combo
        // do admin é conveniência, não autorização.
        if ($motivoId !== null) {
            $stmt = $this->db->prepare(
                "SELECT id FROM motivos_cancelamento WHERE id = ? AND ativo = 1"
            );
            $stmt->execute([$motivoId]);
            if (!$stmt->fetchColumn()) {
                $motivoId = null;
            }
        }

        $this->db->prepare(
            "UPDATE pedidos
                SET motivo_cancelamento_id  = ?,
                    motivo_cancelamento_obs = ?,
                    atualizado_em           = NOW()
              WHERE id = ?"
        )->execute([$motivoId, $obs !== null ? mb_substr($obs, 0, 500) : null, $id]);
    }

    /**
     * Resolve o id de um motivo de cancelamento pelo slug.
     * Usado quando o próprio sistema já sabe o motivo (ex.: recusa na
     * análise de risco) e não faz sentido perguntar ao admin.
     */
    public function motivoCancelamentoIdPorSlug(string $slug): ?int {
        $stmt = $this->db->prepare(
            "SELECT id FROM motivos_cancelamento WHERE slug = ? AND ativo = 1 LIMIT 1"
        );
        $stmt->execute([$slug]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    }

    /**
     * Motivos de cancelamento ativos, para o combo do admin.
     */
    public function motivosCancelamento(): array {
        return $this->db->query(
            "SELECT id, label, slug, origem, exige_texto
               FROM motivos_cancelamento
              WHERE ativo = 1
              ORDER BY ordenacao ASC, label ASC"
        )->fetchAll();
    }

    /**
     * Atualiza status de pagamento.
     */
    public function updateStatusPagamento(
        int $id, string $statusPag, ?string $metodoPag = null,
        ?string $bandeira = null, ?string $ultimos4 = null, ?string $pagoEm = null
    ): void {
        $this->db->prepare(
            "UPDATE pedidos
             SET status_pagamento = ?,
                 forma_pagamento  = COALESCE(?, forma_pagamento),
                 cartao_bandeira  = COALESCE(?, cartao_bandeira),
                 cartao_ultimos_4 = COALESCE(?, cartao_ultimos_4),
                 pago_em          = COALESCE(?, pago_em),
                 atualizado_em    = NOW()
             WHERE id = ?"
        )->execute([$statusPag, $metodoPag, $bandeira, $ultimos4, $pagoEm, $id]);
    }

    /**
     * Atualiza código de rastreio.
     */
    public function updateRastreio(int $id, string $codigo): void {
        $this->db->prepare(
            "UPDATE pedidos SET codigo_rastreio = ?, atualizado_em = NOW() WHERE id = ?"
        )->execute([$codigo, $id]);
    }

    /**
     * Adiciona observação interna.
     */
    public function addObservacao(int $id, string $texto): void {
        // Concatena à observação interna existente com timestamp
        $this->db->prepare(
            "UPDATE pedidos
             SET observacao_interna = CONCAT(
                 COALESCE(observacao_interna, ''),
                 IF(observacao_interna IS NULL OR observacao_interna = '', '', '\n'),
                 '[', DATE_FORMAT(NOW(), '%d/%m/%Y %H:%i'), '] ',
                 ?
             ),
             atualizado_em = NOW()
             WHERE id = ?"
        )->execute([$texto, $id]);
    }

    // ════════════════════════════════════════════════════
    // ESCRITA — ITENS
    // ════════════════════════════════════════════════════

    /**
     * Atualiza quantidade e preço de um item.
     * Retorna o delta de estoque (positivo = devolveu, negativo = consumiu).
     */
    public function updateItem(int $itemId, int $novaQtd, float $novoPreco): array {
        // Busca dados atuais
        $stmt = $this->db->prepare(
            "SELECT pi.*, p.estoque_total, ps.estoque AS estoque_sku
             FROM pedido_itens pi
             JOIN produtos p ON p.id = pi.produto_id
             LEFT JOIN produto_skus ps ON ps.id = pi.sku
             WHERE pi.id = ? LIMIT 1"
        );
        $stmt->execute([$itemId]);
        $item = $stmt->fetch();
        if (!$item) throw new \RuntimeException('Item não encontrado.');

        $deltaEstoque  = (int)$item['quantidade'] - $novaQtd; // positivo = devolveu
        $valorFinalItem = round($novaQtd * $novoPreco, 2);

        $this->db->prepare(
            "UPDATE pedido_itens
             SET quantidade      = ?,
                 preco_unitario  = ?,
                 valor_final_item= ?
             WHERE id = ?"
        )->execute([$novaQtd, $novoPreco, $valorFinalItem, $itemId]);

        return [
            'item'          => $item,
            'delta_estoque' => $deltaEstoque,
            'qtd_antiga'    => (int)$item['quantidade'],
            'qtd_nova'      => $novaQtd,
        ];
    }

    /**
     * Remove um item do pedido. Retorna dados para estorno de estoque.
     */
    public function removeItem(int $itemId): array {
        $stmt = $this->db->prepare(
            "SELECT * FROM pedido_itens WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$itemId]);
        $item = $stmt->fetch();
        if (!$item) throw new \RuntimeException('Item não encontrado.');

        $this->db->prepare("DELETE FROM pedido_itens WHERE id = ?")->execute([$itemId]);

        return $item;
    }

    /**
     * Adiciona item ao pedido.
     */
    public function addItem(
        int $pedidoId, int $produtoId, ?int $skuId,
        int $qtd, float $preco
    ): int {
        // Busca snapshots
        $stmt = $this->db->prepare(
            "SELECT pr.nome,
                (SELECT img.arquivo FROM produto_imagens img
                 WHERE img.produto_id = pr.id AND img.principal = 1 LIMIT 1) AS imagem,
                COALESCE(ps.sku, '') AS sku
             FROM produtos pr
             LEFT JOIN produto_skus ps ON ps.id = ?
             WHERE pr.id = ? LIMIT 1"
        );
        $stmt->execute([$skuId, $produtoId]);
        $snap = $stmt->fetch();

        // Monta opcoes_snapshot com atributos
        $opcoes = null;
        if ($skuId) {
            $stmtAttr = $this->db->prepare(
                "SELECT at.nome, sa.valor FROM sku_atributos sa
                 JOIN atributo_tipos at ON at.id = sa.atributo_tipo_id
                 WHERE sa.sku_id = ? ORDER BY at.ordenacao"
            );
            $stmtAttr->execute([$skuId]);
            $attrs = $stmtAttr->fetchAll();
            if ($attrs) {
                $opcoes = json_encode(
                    array_combine(
                        array_column($attrs, 'nome'),
                        array_column($attrs, 'valor')
                    )
                );
            }
        }

        $this->db->prepare(
            "INSERT INTO pedido_itens
             (pedido_id, produto_id, sku, quantidade, preco_unitario,
              valor_final_item, nome_produto, imagem_snapshot,
              opcoes_snapshot)
             VALUES (?,?,?,?,?,?,?,?,?)"
        )->execute([
            $pedidoId, 
            $produtoId, 
            $skuId, 
            $qtd,
            $preco,
            round($qtd * $preco, 2),
            $snap['nome']   ?? null,
            $snap['imagem'] ?? null,
            // $snap['sku']    ?? null,
            $opcoes,
        ]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Recalcula e atualiza subtotal, desconto e total do pedido.
     */
    public function recalcularTotais(int $pedidoId): array {
        // Subtotal real dos itens
        $stmtSub = $this->db->prepare(
            "SELECT COALESCE(SUM(valor_final_item), 0) AS subtotal
             FROM pedido_itens WHERE pedido_id = ?"
        );
        $stmtSub->execute([$pedidoId]);
        $subtotal = (float)$stmtSub->fetchColumn();

        // Lê frete e desconto atuais
        $stmtP = $this->db->prepare(
            "SELECT frete, desconto, cupom_id FROM pedidos WHERE id = ? LIMIT 1"
        );
        $stmtP->execute([$pedidoId]);
        $ped = $stmtP->fetch();

        $frete    = (float)($ped['frete']    ?? 0);
        $desconto = (float)($ped['desconto'] ?? 0);
        $total    = max(0, round($subtotal - $desconto + $frete, 2));

        $this->db->prepare(
            "UPDATE pedidos SET subtotal = ?, total = ?, atualizado_em = NOW() WHERE id = ?"
        )->execute([$subtotal, $total, $pedidoId]);

        return compact('subtotal', 'desconto', 'frete', 'total');
    }

    // ════════════════════════════════════════════════════
    // ESCRITA — NF
    // ════════════════════════════════════════════════════

    /**
     * Upsert da nota fiscal (INSERT ou UPDATE por pedido_id).
     */
    public function salvarNfe(int $pedidoId, array $dados): int {
        $nfeExistente = $this->getNfe($pedidoId);

        $campos = [
            'serie', 'numero', 'numeroPedidoLoja', 'dataEmissao',
            'valorNota', 'chaveAcesso', 'linkDanfe'
        ];

        if ($nfeExistente) {
            $sets  = implode(', ', array_map(fn($c) => "{$c} = ?", $campos));
            $vals  = array_map(fn($c) => $dados[$c] ?? null, $campos);
            $vals[] = $pedidoId;
            $this->db->prepare(
                "UPDATE pedidos_nfe SET {$sets} WHERE pedido_id = ?"
            )->execute($vals);
            return (int)$nfeExistente['id'];
        }

        $colsList = implode(', ', array_merge(['pedido_id'], $campos));
        $phList   = implode(', ', array_fill(0, count($campos) + 1, '?'));
        $vals     = array_merge(
            [$pedidoId],
            array_map(fn($c) => $dados[$c] ?? null, $campos)
        );
        $this->db->prepare(
            "INSERT INTO pedidos_nfe ({$colsList}) VALUES ({$phList})"
        )->execute($vals);
        return (int)$this->db->lastInsertId();
    }

    // ════════════════════════════════════════════════════
    // CRIAÇÃO MANUAL
    // ════════════════════════════════════════════════════

    /**
     * Cria um pedido manual — retorna o ID do pedido criado.
     */
    public function criarManual(array $dados): int {
        $codigo = strtoupper(substr(md5(uniqid('manual', true)), 0, 8));

        $this->db->prepare(
            "INSERT INTO pedidos
             (cliente_id, codigo, status_pedido, status_pagamento,
              forma_pagamento, parcelas, subtotal, desconto, frete, total,
              endereco_entrega_id, frete_descricao, observacao_cliente,
              observacao_interna, canal, criado_em)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())"
        )->execute([
            $dados['cliente_id'],
            $codigo,
            $dados['status_pedido']     ?? 'aguardando_pagamento',
            $dados['status_pagamento']  ?? 'pendente',
            $dados['forma_pagamento']   ?? 'manual',
            $dados['parcelas']          ?? 1,
            $dados['subtotal']          ?? 0,
            $dados['desconto']          ?? 0,
            $dados['frete']             ?? 0,
            $dados['total']             ?? 0,
            $dados['endereco_id']       ?? null,
            $dados['frete_descricao']   ?? null,
            $dados['observacao_cliente']?? null,
            $dados['observacao_interna']?? 'Pedido criado manualmente via admin.',
            // Canal fixo: quem chama este método É o admin. Antes a
            // única pista de que o pedido era manual era o formato do
            // código e um texto em observacao_interna — heurística,
            // não dado.
            $dados['canal'] ?? 'admin',
        ]);
        return (int)$this->db->lastInsertId();
    }

    // ════════════════════════════════════════════════════
    // BUSCA AJAX (autocomplete)
    // ════════════════════════════════════════════════════

    /**
     * Busca clientes para o formulário de pedido manual.
     * Retorna até 10 resultados. Filtra por nome, email ou CPF.
     * Lembra: usuarios.id = clientes.usuario_id
     */
    public function buscarClientes(string $q): array {
        $q    = '%' . trim($q) . '%';
        $stmt = $this->db->prepare(
            "SELECT
                u.id AS usuario_id, u.nome, u.email,
                c.id AS cliente_id, c.cpf, c.telefone
             FROM usuarios u
             JOIN clientes c ON c.usuario_id = u.id
             WHERE u.nome LIKE ? OR u.email LIKE ? OR c.cpf LIKE ?
             ORDER BY u.nome ASC
             LIMIT 10"
        );
        $stmt->execute([$q, $q, $q]);
        return $stmt->fetchAll();
    }

    /**
     * Busca produtos para adicionar ao pedido.
     * Retorna produto + SKUs disponíveis com estoque.
     */
    public function buscarProdutos(string $q): array {
        $like = '%' . trim($q) . '%';
        $stmt = $this->db->prepare(
            "SELECT
                p.id, p.nome, p.slug, p.preco, p.tem_variacao,
                p.estoque_total,
                (SELECT img.arquivo FROM produto_imagens img
                 WHERE img.produto_id = p.id AND img.principal = 1 LIMIT 1) AS imagem
             FROM produtos p
             WHERE p.ativo = 1 AND p.deleted_at IS NULL
               AND (p.nome LIKE ? OR p.sku_legado LIKE ?)
             ORDER BY p.nome ASC
             LIMIT 10"
        );
        $stmt->execute([$like, $like]);
        $produtos = $stmt->fetchAll();

        // Para cada produto com variação, busca os SKUs
        foreach ($produtos as &$prod) {
            if ($prod['tem_variacao']) {
                $stmtSku = $this->db->prepare(
                    "SELECT ps.id, ps.sku, ps.preco, ps.estoque,
                            GROUP_CONCAT(
                                CONCAT(at.nome, ': ', sa.valor)
                                ORDER BY at.ordenacao SEPARATOR ' · '
                            ) AS label
                     FROM produto_skus ps
                     LEFT JOIN sku_atributos sa ON sa.sku_id = ps.id
                     LEFT JOIN atributo_tipos at ON at.id = sa.atributo_tipo_id
                     WHERE ps.produto_id = ? AND ps.ativo = 1
                     GROUP BY ps.id
                     ORDER BY ps.id ASC"
                );
                $stmtSku->execute([$prod['id']]);
                $prod['skus'] = $stmtSku->fetchAll();
            } else {
                $prod['skus'] = [];
            }
        }
        unset($prod);

        return $produtos;
    }

    /**
     * Endereços de um cliente para o pedido manual.
     */
    public function getEnderecosPorCliente(int $clienteId): array {
        $stmt = $this->db->prepare(
            "SELECT * FROM enderecos
             WHERE cliente_id = ? AND ativo = 1
             ORDER BY padrao DESC, id DESC"
        );
        $stmt->execute([$clienteId]);
        return $stmt->fetchAll();
    }

    // ════════════════════════════════════════════════════
    // HELPERS PRIVADOS
    // ════════════════════════════════════════════════════

    /**
     * Monta a cláusula WHERE e params a partir de $filtros.
     */
    private function buildWhere(array $filtros): array {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filtros['q'])) {
            $like     = '%' . $filtros['q'] . '%';
            $where[]  = "(p.codigo LIKE ? OR u.nome LIKE ? OR u.email LIKE ?)";
            $params   = array_merge($params, [$like, $like, $like]);
        }
        if (!empty($filtros['status_pedido'])) {
            $where[] = "p.status_pedido = ?";
            $params[] = $filtros['status_pedido'];
        }
        if (!empty($filtros['status_pagamento'])) {
            $where[] = "p.status_pagamento = ?";
            $params[] = $filtros['status_pagamento'];
        }
        if (!empty($filtros['data_de'])) {
            $where[] = "DATE(p.criado_em) >= ?";
            $params[] = $filtros['data_de'];
        }
        if (!empty($filtros['data_ate'])) {
            $where[] = "DATE(p.criado_em) <= ?";
            $params[] = $filtros['data_ate'];
        }
        if (!empty($filtros['forma_pagamento'])) {
            $where[] = "p.forma_pagamento = ?";
            $params[] = $filtros['forma_pagamento'];
        }

        return [implode(' AND ', $where), $params];
    }
}