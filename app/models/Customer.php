<?php
// app/models/Customer.php
// Operações do perfil do cliente: dados, endereços, pedidos, cartões e wishlist.

class Customer extends Model {

    protected string $table = 'clientes';

    // ── Perfil ────────────────────────────────────────────────

    public function getFullProfile(int $clienteId): ?array {
        $stmt = $this->db->prepare(
            "SELECT u.id AS usuario_id, u.nome, u.email, u.email_verificado, u.senha_hash,
                    u.criado_em AS membro_desde,
                    c.id AS cliente_id, c.cpf, c.telefone, c.celular,
                    c.nascimento, c.genero, c.avatar, c.newsletter,
                    c.verificado, c.verificado_em,
                    -- views/customer/conta.php le saldo_disponivel do perfil
                    -- para montar o cartao de credito da loja. A coluna existe
                    -- em `clientes` mas nao vinha neste SELECT, entao o cartao
                    -- nunca aparecia — nem para quem tem saldo.
                    c.saldo_disponivel
            FROM clientes c
            JOIN usuarios u ON u.id = c.usuario_id
            WHERE c.id = ? AND u.deleted_at IS NULL
            LIMIT 1"
        );
        $stmt->execute([$clienteId]);
        return $stmt->fetch() ?: null;
    }

    public function getStatusCounts(int $clienteId): array {
        $stmt = $this->db->prepare(
            "SELECT status_pedido, COUNT(*) AS total
            FROM pedidos
            WHERE cliente_id = ?
            GROUP BY status_pedido"
        );
        $stmt->execute([$clienteId]);
    
        $counts = [];
        foreach ($stmt->fetchAll() as $row) {
            $counts[$row['status_pedido']] = (int)$row['total'];
        }
        return $counts;
    }

    public function updateProfile(int $clienteId, array $data): bool {
        $db = $this->db;

        // Atualiza tabela usuários
        $stmtU = $db->prepare(
            "UPDATE usuarios SET nome = ? WHERE id = ?"
        );
        $stmtU->execute([$data['nome'], $data['usuario_id']]);

        // Atualiza tabela clientes
        $stmtC = $db->prepare(
            "UPDATE clientes
             SET cpf = ?, telefone = ?, celular = ?, nascimento = ?, genero = ?, newsletter = ?
             WHERE id = ?"
        );
        return $stmtC->execute([
            !empty($data['cpf'])        ? preg_replace('/\D/', '', $data['cpf']) : null,
            !empty($data['telefone'])   ? $data['telefone']   : null,
            !empty($data['celular'])    ? $data['celular']    : null,
            !empty($data['nascimento']) ? $data['nascimento'] : null,
            !empty($data['genero'])     ? $data['genero']     : null,
            // `isset()` num booleano FALSE devolve true — com isso a
            // newsletter era gravada como 1 mesmo quando o cliente desmarcava,
            // e nao havia como desativar nem pela web nem pelo app.
            !empty($data['newsletter']) ? 1 : 0,
            $clienteId,
        ]);
    }

    public function updateAvatar(int $clienteId, string $arquivo): void {
        $this->db->prepare(
            "UPDATE clientes SET avatar = ? WHERE id = ?"
        )->execute([$arquivo, $clienteId]);
    }

    // ── Pedidos ───────────────────────────────────────────────

    // <?php
    // ════════════════════════════════════════════════════════
    // ADICIONAR em app/models/Customer.php
    //
    // Métodos para o módulo de pedidos do cliente.
    // ════════════════════════════════════════════════════════

    /**
     * Conta pedidos do cliente (com filtro opcional de status).
     */
    public function countOrders(int $clienteId): int {
        $filtroStatus = SecurityHelper::sanitizeString($_GET['status'] ?? '');

        $sql    = "SELECT COUNT(*) FROM pedidos WHERE cliente_id = ?";
        $params = [$clienteId];

        if ($filtroStatus && $this->isStatusValido($filtroStatus)) {
            $sql    .= " AND status_pedido = ?";
            $params[] = $filtroStatus;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Lista pedidos do cliente com paginação.
     * Retorna imagem do primeiro item e contagem total.
     */
    public function getOrders(int $clienteId, int $limit = 10, int $offset = 0): array {
        $filtroStatus = SecurityHelper::sanitizeString($_GET['status'] ?? '');

        $where  = "p.cliente_id = ?";
        $params = [$clienteId];

        if ($filtroStatus && $this->isStatusValido($filtroStatus)) {
            $where  .= " AND p.status_pedido = ?";
            $params[] = $filtroStatus;
        }

        $params[] = $limit;
        $params[] = $offset;

        $stmt = $this->db->prepare(
            "SELECT
                p.id, p.codigo, p.status_pedido, p.status_pagamento,
                p.forma_pagamento, p.subtotal, p.desconto, p.frete, p.total,
                p.parcelas, p.criado_em,
                -- Primeiro item: imagem e nome
                (SELECT pit2.produto_id
                FROM pedido_itens pit2
                JOIN produto_imagens pi2 ON pi2.produto_id = pit2.produto_id AND pi2.principal = 1
                WHERE pit2.pedido_id = p.id
                ORDER BY pit2.id ASC LIMIT 1
                ) AS primeiro_produto_id,
                -- Total de itens
                (SELECT SUM(pit3.quantidade)
                FROM pedido_itens pit3
                WHERE pit3.pedido_id = p.id
                ) AS total_itens
            FROM pedidos p
            WHERE {$where}
            ORDER BY p.criado_em DESC
            LIMIT ? OFFSET ?"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Carrega um pedido específico do cliente (anti-IDOR).
     */
    public function getOrder(int $clienteId, int $pedidoId): ?array {
        $stmt = $this->db->prepare(
            "SELECT
                p.*,
                -- Endereço de entrega via JOIN (fallback se snapshot vazio)
                e.nome_destinatario AS ent_destinatario,
                e.logradouro        AS ent_logradouro,
                e.numero            AS ent_numero,
                e.complemento       AS ent_complemento,
                e.bairro            AS ent_bairro,
                e.cidade            AS ent_cidade,
                e.estado            AS ent_estado,
                e.cep               AS ent_cep
            FROM pedidos p
            LEFT JOIN enderecos e ON e.id = p.endereco_entrega_id
            WHERE p.id = ? AND p.cliente_id = ?
            LIMIT 1"
        );
        $stmt->execute([$pedidoId, $clienteId]);
        $pedido = $stmt->fetch();

        if (!$pedido) return null;

        // Normaliza colunas que podem não existir em instâncias antigas
        $pedido['pago_em']           = $pedido['pago_em']           ?? null;
        $pedido['cartao_bandeira']   = $pedido['cartao_bandeira']   ?? null;
        $pedido['cartao_ultimos_4']  = $pedido['cartao_ultimos_4']  ?? null;
        $pedido['codigo_rastreio']   = $pedido['codigo_rastreio']   ?? null;
        $pedido['status_pedido']     = $pedido['status_pedido']     ?? 'aguardando_pagamento';
        $pedido['frete_prazo']       = $pedido['frete_prazo']
                                    ?? $pedido['frete_prazo_dias']  ?? null;
        $pedido['frete_descricao']   = $pedido['frete_descricao']
                                    ?? $pedido['frete_servico']     ?? null;
        return $pedido;
    }

    /**
     * Itens do pedido com informações do produto (nome, slug, imagem, variações).
     */
    public function getOrderItems(int $pedidoId): array {
        $stmt = $this->db->prepare(
            "SELECT
                pi.*,
                -- Snapshot do nome (prioridade) ou nome atual do produto
                COALESCE(pi.nome_produto, pr.nome) AS nome_produto,
                pr.slug         AS produto_slug,
                pr.ativo        AS produto_ativo,
                -- Imagem snapshot ou primeira imagem atual
                COALESCE(pi.imagem_snapshot,
                    (SELECT img.arquivo
                    FROM produto_imagens img
                    WHERE img.produto_id = pi.produto_id AND img.principal = 1
                    LIMIT 1)
                )               AS imagem,
                -- SKU do item
                COALESCE(pi.sku, sk.sku) AS sku
            FROM pedido_itens pi
            JOIN produtos pr ON pr.id = pi.produto_id
            LEFT JOIN produto_skus sk ON sk.id = pi.sku
            WHERE pi.pedido_id = ?
            ORDER BY pi.id ASC"
        );
        $stmt->execute([$pedidoId]);
        return $stmt->fetchAll();
    }

    /**
     * Histórico de mudanças de status do pedido.
     * Exige tabela pedido_historico (ver migration abaixo).
     */
    public function getOrderHistory(int $pedidoId): array {
        try {
            $stmt = $this->db->prepare(
                "SELECT status_novo, observacao, criado_em
                FROM pedido_historico
                WHERE pedido_id = ?
                ORDER BY criado_em DESC"
            );
            $stmt->execute([$pedidoId]);
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            // Tabela não existe ainda — retorna vazio sem quebrar
            return [];
        }
    }

    /**
     * Valida se o status passado via GET é um valor permitido.
     */
    private function isStatusValido(string $status): bool {
        return in_array($status, [
            'aguardando_pagamento', 'pagamento_aprovado', 'em_separacao',
            'enviado', 'entregue', 'cancelado', 'troca_devolucao',
        ], true);
    }

    // ── Endereços ─────────────────────────────────────────────

    public function getAddresses(int $clienteId): array {
        $stmt = $this->db->prepare(
            "SELECT * FROM enderecos
             WHERE cliente_id = ?
             ORDER BY principal DESC, id DESC"
        );
        $stmt->execute([$clienteId]);
        return $stmt->fetchAll();
    }

    public function getAddress(int $clienteId, int $enderecoId): ?array {
        $stmt = $this->db->prepare(
            "SELECT * FROM enderecos WHERE id = ? AND cliente_id = ? LIMIT 1"
        );
        $stmt->execute([$enderecoId, $clienteId]);
        return $stmt->fetch() ?: null;
    }

    public function saveAddress(int $clienteId, array $data, ?int $enderecoId = null): int {
        if ($enderecoId) {
            $this->db->prepare(
                "UPDATE enderecos
                 SET nome_destinatario=?,cep=?,logradouro=?,numero=?,complemento=?,
                     bairro=?,cidade=?,estado=?,telefone_contato=?
                 WHERE id=? AND cliente_id=?"
            )->execute([
                $data['nome_destinatario'], $data['cep'], $data['logradouro'],
                $data['numero'], $data['complemento'], $data['bairro'],
                $data['cidade'], $data['estado'], $data['telefone_contato'],
                $enderecoId, $clienteId,
            ]);
            return $enderecoId;
        }

        $stmt = $this->db->prepare(
            "INSERT INTO enderecos
             (cliente_id,nome_destinatario,cep,logradouro,numero,complemento,
              bairro,cidade,estado,telefone_contato,principal)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)"
        );
        $stmt->execute([
            $clienteId, $data['nome_destinatario'], $data['cep'], $data['logradouro'],
            $data['numero'], $data['complemento'], $data['bairro'], $data['cidade'],
            $data['estado'], $data['telefone_contato'],
            empty($this->getAddresses($clienteId)) ? 1 : 0,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function deleteAddress(int $clienteId, int $enderecoId): bool {
        // Não permite excluir endereço usado em pedido ativo
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM pedidos
             WHERE cliente_id = ? AND endereco_entrega_id = ?
               AND status_pedido NOT IN ('entregue','cancelado')"
        );
        $stmt->execute([$clienteId, $enderecoId]);
        if ((int)$stmt->fetchColumn() > 0) {
            throw new RuntimeException('Endereço vinculado a pedido em andamento.');
        }

        return (bool) $this->db->prepare(
            "DELETE FROM enderecos WHERE id = ? AND cliente_id = ?"
        )->execute([$enderecoId, $clienteId]);
    }

    public function setPrincipalAddress(int $clienteId, int $enderecoId): void {
        $this->db->prepare(
            "UPDATE enderecos SET principal = 0 WHERE cliente_id = ?"
        )->execute([$clienteId]);

        $this->db->prepare(
            "UPDATE enderecos SET principal = 1 WHERE id = ? AND cliente_id = ?"
        )->execute([$enderecoId, $clienteId]);
    }

    // ── Wishlist ──────────────────────────────────────────────

    public function getWishlists(int $clienteId): array {
        $stmt = $this->db->prepare(
            "SELECT w.*,
                    COUNT(wi.id) AS total_itens
             FROM wishlist w
             LEFT JOIN wishlist_itens wi ON wi.wishlist_id = w.id
             WHERE w.cliente_id = ?
             GROUP BY w.id
             ORDER BY w.criado_em DESC"
        );
        $stmt->execute([$clienteId]);
        return $stmt->fetchAll();
    }

    public function getWishlistItems(int $wishlistId, int $clienteId): array {
        $stmt = $this->db->prepare(
            "SELECT wi.*, p.nome, p.slug, p.preco, p.preco_promo,
                    p.estoque_total, pi.arquivo AS imagem
             FROM wishlist_itens wi
             JOIN produtos p ON p.id = wi.produto_id
             LEFT JOIN produto_imagens pi ON pi.produto_id = p.id AND pi.principal = 1
             WHERE wi.wishlist_id = ?
               AND (SELECT cliente_id FROM wishlist WHERE id = ?) = ?
             ORDER BY wi.adicionado_em DESC"
        );
        $stmt->execute([$wishlistId, $wishlistId, $clienteId]);
        return $stmt->fetchAll();
    }

    public function addToWishlist(int $clienteId, int $productId, ?int $wishlistId = null): array {
        // Usa a lista padrão ou cria uma
        if (!$wishlistId) {
            $stmt = $this->db->prepare(
                "SELECT id FROM wishlist WHERE cliente_id = ? ORDER BY criado_em ASC LIMIT 1"
            );
            $stmt->execute([$clienteId]);
            $wishlistId = $stmt->fetchColumn();

            if (!$wishlistId) {
                $this->db->prepare(
                    "INSERT INTO wishlist (cliente_id, nome) VALUES (?, 'Minha Lista')"
                )->execute([$clienteId]);
                $wishlistId = (int) $this->db->lastInsertId();
            }
        }

        // Verifica se já está na lista
        $stmt = $this->db->prepare(
            "SELECT id FROM wishlist_itens WHERE wishlist_id = ? AND produto_id = ? LIMIT 1"
        );
        $stmt->execute([$wishlistId, $productId]);
        if ($stmt->fetchColumn()) {
            return ['ok' => true, 'msg' => 'Produto já está nos favoritos.', 'already' => true];
        }

        $this->db->prepare(
            "INSERT INTO wishlist_itens (wishlist_id, produto_id) VALUES (?,?)"
        )->execute([$wishlistId, $productId]);

        return ['ok' => true, 'msg' => 'Adicionado aos favoritos!'];
    }

    public function removeFromWishlist(int $clienteId, int $productId, ?int $wishlistId = null): bool {
        if ($wishlistId) {
            return (bool) $this->db->prepare(
                "DELETE wi FROM wishlist_itens wi
                 JOIN wishlist w ON w.id = wi.wishlist_id
                 WHERE wi.produto_id = ? AND wi.wishlist_id = ? AND w.cliente_id = ?"
            )->execute([$productId, $wishlistId, $clienteId]);
        }

        return (bool) $this->db->prepare(
            "DELETE wi FROM wishlist_itens wi
             JOIN wishlist w ON w.id = wi.wishlist_id
             WHERE wi.produto_id = ? AND w.cliente_id = ?"
        )->execute([$productId, $clienteId]);
    }

    public function isInWishlist(int $clienteId, int $productId): bool {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM wishlist_itens wi
             JOIN wishlist w ON w.id = wi.wishlist_id
             WHERE w.cliente_id = ? AND wi.produto_id = ?"
        );
        $stmt->execute([$clienteId, $productId]);
        return (bool) $stmt->fetchColumn();
    }

    // ── Cartões ───────────────────────────────────────────────

    public function getCards(int $clienteId): array {
        $stmt = $this->db->prepare(
            "SELECT * FROM cartoes_salvos WHERE cliente_id = ? ORDER BY principal DESC, id DESC"
        );
        $stmt->execute([$clienteId]);
        return $stmt->fetchAll();
    }

    public function deleteCard(int $clienteId, int $cartaoId): bool {
        return (bool) $this->db->prepare(
            "DELETE FROM cartoes_salvos WHERE id = ? AND cliente_id = ?"
        )->execute([$cartaoId, $clienteId]);
    }

    public function setPrincipalCard(int $clienteId, int $cartaoId): void {
        $this->db->prepare(
            "UPDATE cartoes_salvos SET principal = 0 WHERE cliente_id = ?"
        )->execute([$clienteId]);
        $this->db->prepare(
            "UPDATE cartoes_salvos SET principal = 1 WHERE id = ? AND cliente_id = ?"
        )->execute([$cartaoId, $clienteId]);
    }

    // ── Avaliações ────────────────────────────────────────────

    /**
     * Produtos elegíveis para avaliação: itens de pedidos do cliente
     * com pagamento aprovado E já entregues. Um produto comprado em
     * mais de um pedido aparece uma única vez (dados do pedido mais
     * recente), com flag indicando se o cliente já avaliou e a nota
     * média/total de avaliações do produto (todas as avaliações
     * aprovadas, não só as deste cliente).
     *
     * Usa ROW_NUMBER() em vez de GROUP BY + MAX() para pegar o pedido
     * mais recente com precisão (preço/nome do pedido certo, não uma
     * mistura de colunas de pedidos diferentes) — e evita o problema
     * de ONLY_FULL_GROUP_BY que já apareceu antes neste projeto.
     */
    public function getProdutosParaAvaliar(int $clienteId): array {
        $stmt = $this->db->prepare(
            "SELECT
                ranked.produto_id,
                ranked.pedido_id,
                ranked.nome,
                ranked.slug,
                ranked.img_capa,
                ranked.preco_pago,
                EXISTS (
                    SELECT 1 FROM avaliacoes av
                    WHERE av.produto_id = ranked.produto_id
                      AND av.cliente_id = ?
                ) AS ja_avaliou,
                rs.nota_media,
                rs.total_avaliacoes
             FROM (
                SELECT
                    pi.produto_id,
                    ped.id AS pedido_id,
                    COALESCE(pi.nome_produto, p.nome) AS nome,
                    p.slug,
                    COALESCE(pi.imagem_snapshot,
                        (SELECT img.arquivo FROM produto_imagens img
                         WHERE img.produto_id = pi.produto_id AND img.principal = 1
                         LIMIT 1)
                    ) AS img_capa,
                    pi.valor_final_item AS preco_pago,
                    ROW_NUMBER() OVER (
                        PARTITION BY pi.produto_id
                        ORDER BY ped.criado_em DESC
                    ) AS rn
                FROM pedido_itens pi
                JOIN pedidos ped ON ped.id = pi.pedido_id
                LEFT JOIN produtos p ON p.id = pi.produto_id
                WHERE ped.cliente_id = ?
                  AND ped.status_pagamento = 'aprovado'
                  AND ped.status_pedido = 'entregue'
             ) ranked
             LEFT JOIN (
                SELECT produto_id, AVG(nota) AS nota_media, COUNT(*) AS total_avaliacoes
                FROM avaliacoes
                WHERE aprovado = 1
                GROUP BY produto_id
             ) rs ON rs.produto_id = ranked.produto_id
             WHERE ranked.rn = 1
             ORDER BY ranked.pedido_id DESC"
        );
        $stmt->execute([$clienteId, $clienteId]);
        return $stmt->fetchAll();
    }

    // ── Stats do dashboard ────────────────────────────────────

    public function getDashboardStats(int $clienteId): array {
        $db = $this->db;

        $stmtPed = $db->prepare(
            "SELECT COUNT(*) AS total,
                    SUM(total) AS gasto_total,
                    MAX(criado_em) AS ultimo_pedido
             FROM pedidos WHERE cliente_id = ?"
        );
        $stmtPed->execute([$clienteId]);
        $pedStats = $stmtPed->fetch();

        $stmtWish = $db->prepare(
            "SELECT COUNT(*) FROM wishlist_itens wi
             JOIN wishlist w ON w.id = wi.wishlist_id
             WHERE w.cliente_id = ?"
        );
        $stmtWish->execute([$clienteId]);
        $wishTotal = (int) $stmtWish->fetchColumn();

        $stmtEnd = $db->prepare(
            "SELECT COUNT(*) FROM enderecos WHERE cliente_id = ?"
        );
        $stmtEnd->execute([$clienteId]);
        $endTotal = (int) $stmtEnd->fetchColumn();

        // views/customer/conta.php le `tier` e `score` daqui para o selo do
        // topo. Nenhum dos dois era devolvido, entao o padrao do proprio view
        // (`bronze` e 0) valia para todo mundo: o cliente 2, que e `gold` com
        // 900 pontos, aparecia como Bronze 0 pts.
        $stmtScore = $db->prepare(
            "SELECT score_total, tier FROM clientes_score WHERE cliente_id = ? LIMIT 1"
        );
        $stmtScore->execute([$clienteId]);
        $score = $stmtScore->fetch() ?: null;

        return [
            'total_pedidos'  => (int)   $pedStats['total'],
            'gasto_total'    => (float) ($pedStats['gasto_total'] ?? 0),
            'ultimo_pedido'  => $pedStats['ultimo_pedido'],
            'total_favoritos'=> $wishTotal,
            'total_enderecos'=> $endTotal,

            // Sem linha em clientes_score o cliente e novo e ainda nao foi
            // calculado — bronze/0 e a resposta certa, nao um valor de falha.
            'score'          => (int)   ($score['score_total'] ?? 0),
            'tier'           => (string)($score['tier'] ?? 'bronze'),
        ];
    }

    /**
     * Contadores dos selos do menu da area do cliente.
     *
     * O menu vive no layout, entao aparece em TODA pagina da conta — por isso
     * sao contagens simples e indexadas, nao agregacoes caras. Sem elas a view
     * lia variaveis que ninguem definia e o PHP imprimia o warning no meio do
     * menu, como aconteceu em conta.php.
     *
     * @return array{pedidos:int, devolucoes:int, motos:int, favoritos:int,
     *               enderecos:int, cartoes:int}
     */
    public function getMenuBadges(int $clienteId): array
    {
        // Memoiza por requisicao: na home o dashboard pede os contadores e o
        // layout pede de novo para o menu. Sao seis contagens — barato uma
        // vez, desperdicio duas.
        static $cache = [];
        if (isset($cache[$clienteId])) return $cache[$clienteId];

        $conta = function (string $sql) use ($clienteId): int {
            $st = $this->db->prepare($sql);
            $st->execute([$clienteId]);
            return (int) $st->fetchColumn();
        };

        $badges = [
            // "Ativo" e o que ainda pode mudar de estado. Pedido entregue,
            // cancelado ou devolvido nao pede atencao do cliente.
            'pedidos' => $conta(
                "SELECT COUNT(*) FROM pedidos
                  WHERE cliente_id = ?
                    AND status_pedido NOT IN ('entregue','cancelado','devolvido')"
            ),

            // Mesma logica: so as solicitacoes ainda em curso.
            'devolucoes' => $conta(
                "SELECT COUNT(*) FROM solicitacoes_devolucao
                  WHERE cliente_id = ?
                    AND status NOT IN ('concluido','concluido_reprovado',
                                       'cancelado','expirado','negado')"
            ),

            'motos'     => $conta("SELECT COUNT(*) FROM cliente_veiculos WHERE cliente_id = ?"),
            'enderecos' => $conta("SELECT COUNT(*) FROM enderecos WHERE cliente_id = ?"),
            'cartoes'   => $conta(
                "SELECT COUNT(*) FROM cartoes_salvos WHERE cliente_id = ? AND ativo = 1"
            ),
            'favoritos' => $conta(
                "SELECT COUNT(*) FROM wishlist_itens wi
                   JOIN wishlist w ON w.id = wi.wishlist_id
                  WHERE w.cliente_id = ?"
            ),
        ] + $this->tierDoCliente($clienteId);

        return $cache[$clienteId] = $badges;
    }

    /**
     * Tier e score para o selo do menu.
     *
     * Fica aqui, e nao so no getDashboardStats, porque o menu aparece em toda
     * pagina da conta — e o selo sumir ao sair da home passaria a impressao de
     * que o cliente perdeu o nivel.
     *
     * @return array{tier:string, score:int}
     */
    private function tierDoCliente(int $clienteId): array
    {
        $st = $this->db->prepare(
            "SELECT score_total, tier FROM clientes_score WHERE cliente_id = ? LIMIT 1"
        );
        $st->execute([$clienteId]);
        $r = $st->fetch() ?: [];

        // Sem linha o cliente e novo: bronze/0 e a resposta certa.
        return [
            'tier'  => (string) ($r['tier'] ?? 'bronze'),
            'score' => (int)    ($r['score_total'] ?? 0),
        ];
    }
}