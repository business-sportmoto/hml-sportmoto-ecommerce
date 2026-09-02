<?php
// app/models/Order.php
// Criação e consulta de pedidos.

class Order extends Model {

    protected string $table = 'pedidos';

    /**
     * Cria pedido completo em transação:
     * pedido → itens → baixa de estoque → uso de cupom → limpa carrinho.
     */
    public function createFromCart(array $data, Cart $cartModel): int {
        $carrinho = $cartModel->getOrCreate();
        $totals   = $cartModel->getTotals((int)$carrinho['id']);

        if (empty($totals['items'])) {
            throw new RuntimeException('Carrinho vazio.');
        }

        $this->db->beginTransaction();
        try {
            // Gera código único do pedido
            $codigo = $this->generateCodigo();

            $pedidoId = $this->insert([
                'cliente_id'            => $data['cliente_id'],
                'endereco_entrega_id'   => $data['endereco_entrega_id']  ?? null,
                'endereco_cobranca_id'  => $data['endereco_cobranca_id'] ?? null,
                'cupom_id'              => $carrinho['cupom_id']          ?? null,
                'codigo'                => $codigo,
                'codigo_vendedor'       => $carrinho['codigo_vendedor']   ?? null,
                'subtotal'              => $totals['subtotal'],
                'frete'                 => $totals['frete'],
                'desconto'              => $totals['desconto'],
                'total'                 => $totals['total'],
                'forma_pagamento'       => $data['forma_pagamento'],
                'parcelas'              => $data['parcelas']   ?? 1,
                'status_pagamento'      => 'pendente',
                'status_pedido'         => 'aguardando_pagamento',
                'frete_servico'         => $carrinho['frete_servico']     ?? null,
                'frete_prazo_dias'      => $carrinho['frete_prazo']       ?? null,
                'observacao_cliente'    => $data['observacao']            ?? null,
                'ip_origem'             => $_SERVER['REMOTE_ADDR']        ?? null,
                'endereco_entrega_snapshot'  => json_encode($data['endereco_entrega']  ?? []),
                'endereco_cobranca_snapshot' => json_encode($data['endereco_cobranca'] ?? []),
            ]);

            // Insere itens e baixa estoque
            foreach ($totals['items'] as $item) {
                $this->db->prepare(
                    "INSERT INTO pedido_itens
                     (pedido_id, produto_id, estoque_id, nome_produto, sku,
                      quantidade, preco_unitario, subtotal, opcoes_snapshot, imagem_snapshot)
                     VALUES (?,?,?,?,?,?,?,?,?,?)"
                )->execute([
                    $pedidoId,
                    $item['produto_id'],
                    $item['estoque_id']   ?? null,
                    $item['nome'],
                    $item['sku_variacao'] ?? $item['produto_id'],
                    $item['quantidade'],
                    $item['preco_vigente'],
                    $item['subtotal'],
                    !empty($item['opcoes']) ? json_encode($item['opcoes']) : null,
                    $item['imagem']        ?? null,
                ]);

                // Baixa estoque
                if ($item['estoque_id']) {
                    $this->db->prepare(
                        "UPDATE produto_estoque
                         SET quantidade = GREATEST(0, quantidade - ?)
                         WHERE id = ?"
                    )->execute([$item['quantidade'], $item['estoque_id']]);
                }
                $this->db->prepare(
                    "UPDATE produtos
                     SET estoque_total = GREATEST(0, estoque_total - ?),
                         vendidos = vendidos + ?
                     WHERE id = ?"
                )->execute([$item['quantidade'], $item['quantidade'], $item['produto_id']]);
            }

            // Registra uso do cupom
            if (!empty($carrinho['cupom_id'])) {
                $this->db->prepare(
                    "INSERT INTO cupom_uso (cupom_id, cliente_id, pedido_id, desconto_aplicado)
                     VALUES (?,?,?,?)"
                )->execute([
                    $carrinho['cupom_id'],
                    $data['cliente_id'],
                    $pedidoId,
                    $totals['desconto'],
                ]);
                $this->db->prepare(
                    "UPDATE cupons SET usado = usado + 1 WHERE id = ?"
                )->execute([$carrinho['cupom_id']]);
            }

            // Primeiro registro de status
            $this->db->prepare(
                "INSERT INTO pedido_status_historico
                 (pedido_id, status_novo, observacao)
                 VALUES (?, 'aguardando_pagamento', 'Pedido criado')"
            )->execute([$pedidoId]);

            // Limpa carrinho
            $cartModel->clear((int)$carrinho['id']);
            Session::setCarrinhoCount(0);
            Session::remove('carrinho_id');

            $this->db->commit();
            return $pedidoId;

        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function findByCode(string $codigo, int $clienteId): ?array {
        $stmt = $this->db->prepare(
            "SELECT p.*,
                    ea.logradouro AS ent_logradouro, ea.numero AS ent_numero,
                    ea.bairro AS ent_bairro, ea.cidade AS ent_cidade,
                    ea.estado AS ent_estado, ea.cep AS ent_cep
             FROM pedidos p
             LEFT JOIN enderecos ea ON ea.id = p.endereco_entrega_id
             WHERE p.codigo = ? AND p.cliente_id = ?
             LIMIT 1"
        );
        $stmt->execute([$codigo, $clienteId]);
        return $stmt->fetch() ?: null;
    }

    public function findByID(int $pedido_id): ?array {
        $stmt = $this->db->prepare(
            "SELECT p.*,
                    ea.logradouro AS ent_logradouro, ea.numero AS ent_numero,
                    ea.bairro AS ent_bairro, ea.cidade AS ent_cidade,
                    ea.estado AS ent_estado, ea.cep AS ent_cep
             FROM pedidos p
             LEFT JOIN enderecos ea ON ea.id = p.endereco_entrega_id
             WHERE p.id = ?
             LIMIT 1"
        );
        $stmt->execute([$pedido_id]);
        return $stmt->fetch() ?: null;
    }

    public function getItems(int $pedidoId): array {
        $stmt = $this->db->prepare(
            "SELECT * FROM pedido_itens WHERE pedido_id = ? ORDER BY id ASC"
        );
        $stmt->execute([$pedidoId]);
        return $stmt->fetchAll();
    }
    

    private function generateCodigo(): string {
        do {
            $codigo = 'PED-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
            $stmt   = $this->db->prepare("SELECT id FROM pedidos WHERE codigo = ? LIMIT 1");
            $stmt->execute([$codigo]);
        } while ($stmt->fetchColumn());
        return $codigo;
    }

    public function getItemsWithVariacoes(int $pedidoId): array {
        $db = Database::getInstance()->getConnection();
    
        // ── 1. Query principal ──────────────────────────────
        $stmt = $db->prepare(
            "SELECT
                pi.*,
                COALESCE(pi.nome_produto, pr.nome)  AS produto_nome,
                pr.slug   AS produto_slug,
                pr.ativo  AS produto_ativo,
                -- Imagem principal atual (snapshot resolvido depois)
                img.arquivo                                   AS imagem_arquivo,
                -- pedido_itens.sku e VARCHAR mas guarda o ID de
                -- produto_skus (ver AdminPedido::addItem). O alias explicito
                -- e obrigatorio: sem ele o array_column por sku_id logo
                -- abaixo devolvia [] e o batch de sku_atributos nunca rodava
                -- -- o cliente via a cor do produto mas NUNCA o tamanho da
                -- variacao que comprou.
                pi.sku                                        AS sku_id,
                ps.sku                                        AS sku_codigo,
                ps.estoque                                    AS estoque_sku,
                pr.estoque_total                              AS estoque_produto
            FROM pedido_itens pi
            JOIN  produtos pr        ON pr.id  = pi.produto_id
            LEFT JOIN produto_imagens img ON img.produto_id = pr.id AND img.principal = 1
            LEFT JOIN produto_skus   ps  ON ps.id = pi.sku
            WHERE pi.pedido_id = ?
            ORDER BY pi.id ASC"
        );
        $stmt->execute([$pedidoId]);
        $itens = $stmt->fetchAll();
    
        if (empty($itens)) return [];
    
        // ── 2. Coleta IDs para os batchs ────────────────────
        $produtoIds = array_unique(array_column($itens, 'produto_id'));
        $skuIds     = array_values(array_filter(
            array_unique(array_column($itens, 'sku_id'))
        ));
    
        // ── 3. Batch: agrupadores do produto (cor, estampa…) ─
        $agrupadores = [];
        if (!empty($produtoIds)) {
            $ph   = implode(',', array_fill(0, count($produtoIds), '?'));
            $stmt = $db->prepare(
                "SELECT paa.produto_id,
                        at.nome, at.slug, at.tipo_display,
                        paa.valor, paa.valor_hex
                FROM produto_atributos_agrupadores paa
                JOIN atributo_tipos at ON at.id = paa.atributo_tipo_id
                WHERE paa.produto_id IN ({$ph})
                ORDER BY paa.produto_id, at.ordenacao"
            );
            $stmt->execute($produtoIds);
            foreach ($stmt->fetchAll() as $row) {
                $agrupadores[(int)$row['produto_id']][] = $row;
            }
        }
    
        // ── 4. Batch: atributos do SKU (tamanho, voltagem…) ──
        $skuAtributos = [];
        if (!empty($skuIds)) {
            $ph   = implode(',', array_fill(0, count($skuIds), '?'));
            $stmt = $db->prepare(
                "SELECT sa.sku_id,
                        at.nome, at.slug, at.tipo_display,
                        sa.valor
                FROM sku_atributos sa
                JOIN atributo_tipos at ON at.id = sa.atributo_tipo_id
                WHERE sa.sku_id IN ({$ph})
                ORDER BY sa.sku_id, at.ordenacao"
            );
            $stmt->execute($skuIds);
            foreach ($stmt->fetchAll() as $row) {
                $skuAtributos[(int)$row['sku_id']][] = $row;
            }
        }
    
        // ── 5. Monta cada item com atributos + imagem ────────
        foreach ($itens as &$item) {
            $pid   = (int)$item['produto_id'];
            $skuId = ($item['sku']) ? (int)$item['sku'] : null;
    
            // ── Imagem ────────────────────────────────────────
            // Prioridade: snapshot gravado → imagem atual do produto
            // $item['imagem_url'] = $this->resolveImagem(
            //     $item['imagem_snapshot'] ?? null,
            //     $item['imagem_arquivo']  ?? null,
            //     $pid
            // );

            $item['imagem'] = ImageHelper::getCartItemImage($item['produto_id']);
    
            // ── SKU ───────────────────────────────────────────
            $item['sku'] = $item['sku_snapshot'] ?? $item['sku_codigo'] ?? null;
    
            // ── Estoque atual (informativo) ───────────────────
            $item['estoque_atual'] = $skuId
                ? (int)($item['estoque_sku']     ?? 0)
                : (int)($item['estoque_produto'] ?? 0);
    
            // ── Atributos — 3 camadas de fallback ─────────────
            $atributos = $this->resolveAtributos(
                $item['opcoes_snapshot'] ?? null,   // 1. snapshot (imutável)
                $agrupadores[$pid]       ?? [],      // 2. agrupadores live
                $skuId ? ($skuAtributos[$skuId] ?? []) : []  // 3. SKU live
            );
            $item['atributos'] = $atributos;
    
            // ── Preços (normaliza nomes) ──────────────────────
            $item['preco_unitario'] = (float)($item['preco']           ?? $item['preco_unitario'] ?? 0);
            $item['subtotal']       = (float)($item['valor_final_item'] ?? $item['subtotal']      ?? 0);
            $item['desconto_cupom'] = (float)($item['desconto_cupom']  ?? 0);
        }
        unset($item);
    
        return $itens;
    }
    
    // ── Helpers privados ─────────────────────────────────────
    
    /**
     * Resolve a URL da imagem do item.
     * Prioridade: snapshot gravado > imagem atual do produto.
     */
    private function resolveImagem(
        ?string $snapshot,
        ?string $arquivoAtual,
        int     $produtoId
    ): string {
        if (!empty($snapshot)) {
            return BASE_URL . '/uploads/produtos/' . $snapshot;
        }
        if (!empty($arquivoAtual)) {
            return BASE_URL . '/uploads/produtos/' . $arquivoAtual;
        }
        return BASE_URL . '/assets/img/placeholder.png';
    }
    
    /**
     * Resolve os atributos de exibição de um item.
     *
     * Prioridade:
     *   1. opcoes_snapshot (JSON fixo da compra)
     *   2. agrupadores do produto + atributos do SKU (dados live)
     *
     * Formato de retorno: [ {nome, valor, valor_hex, tipo_display} ]
     */
    private function resolveAtributos(
        string|array|null $snapshot,
        array $agrupadores,
        array $skuAtributos
    ): array {
        // ── 1. Snapshot disponível ───────────────────────────
        if (!empty($snapshot)) {
            $decoded = is_string($snapshot)
                ? json_decode($snapshot, true)
                : $snapshot;
    
            if (is_array($decoded) && !empty($decoded)) {
                $attrs = [];
    
                // Snapshot pode ser:
                //   A) Formato chave→valor: {"Cor":"Azul","Tamanho":"M"}
                //   B) Array de objetos:    [{nome,valor,...}]
                if (isset($decoded[0]) && is_array($decoded[0])) {
                    // Formato B
                    return array_map(fn($a) => [
                        'nome'         => $a['nome']         ?? '',
                        'valor'        => $a['valor']        ?? '',
                        'valor_hex'    => $a['valor_hex']    ?? null,
                        'tipo_display' => $a['tipo_display'] ?? 'texto',
                    ], $decoded);
                }
    
                // Formato A
                foreach ($decoded as $nome => $valor) {
                    $attrs[] = [
                        'nome'         => $nome,
                        'valor'        => $valor,
                        'valor_hex'    => null,
                        'tipo_display' => 'texto',
                    ];
                }
                return $attrs;
            }
        }
    
        // ── 2. Dados live (SKU não foi deletado) ─────────────
        $attrs = [];
    
        foreach ($agrupadores as $a) {
            $attrs[] = [
                'nome'         => $a['nome'],
                'valor'        => $a['valor'],
                'valor_hex'    => $a['valor_hex'] ?? null,
                'tipo_display' => $a['tipo_display'],
            ];
        }
    
        foreach ($skuAtributos as $a) {
            $attrs[] = [
                'nome'         => $a['nome'],
                'valor'        => $a['valor'],
                'valor_hex'    => null,
                'tipo_display' => $a['tipo_display'],
            ];
        }
    
        return $attrs;
    }

    public function registrarMudancaStatus(
        int     $pedidoId,
        string  $novoStatus,
        ?string $observacao = null,
        ?int    $adminId    = null
    ): void {
        $this->db->prepare(
            "INSERT INTO pedido_historico (pedido_id, status_novo, observacao, admin_id)
            VALUES (?, ?, ?, ?)"
        )->execute([$pedidoId, $novoStatus, $observacao, $adminId]);
    
        $this->db->prepare(
            "UPDATE pedidos SET status_pedido = ?, atualizado_em = NOW() WHERE id = ?"
        )->execute([$novoStatus, $pedidoId]);
    }

    /**
     * Busca a nota fiscal vinculada a um pedido.
     *
     * Valida que o pedido pertence ao cliente antes de retornar —
     * nunca expõe NF de pedidos de outros clientes (anti-IDOR).
     *
     * @param int $pedidoId   ID do pedido
     * @param int $clienteId  ID do cliente logado (segurança)
     * @return array|null     Dados da NF ou null se não encontrada
     */
    public function getNotaFiscal(int $pedidoId, int $clienteId): ?array {
        // JOIN com pedidos garante que o pedido pertence ao cliente
        $stmt = $this->db->prepare(
            "SELECT
                nfe.id,
                nfe.pedido_id,
                nfe.serie,
                nfe.numero,
                nfe.valorNota,
                nfe.numeroPedidoLoja,
                nfe.chaveAcesso,
                nfe.linkDanfe,
                nfe.linkPDF,
                nfe.dataEmissao,
                nfe.criado_em
            FROM pedidos_nfe nfe
            JOIN pedidos p ON p.id = nfe.pedido_id
            WHERE nfe.pedido_id = ?
            AND p.cliente_id  = ?
            LIMIT 1"
        );
        $stmt->execute([$pedidoId, $clienteId]);
        $nf = $stmt->fetch();
    
        if (!$nf) return null;
    
        // Formata a chave de acesso em blocos de 4 (legibilidade)
        if (!empty($nf['chaveAcesso'])) {
            $nf['chaveAcesso_fmt'] = implode(' ', str_split($nf['chaveAcesso'], 4));
        }
    
        // URL canônica do PDF — prioridade: linkPDF → linkDanfe
        $nf['url_pdf']  = $nf['linkPDF']   ?: $nf['linkDanfe'] ?: null;
        $nf['url_danfe']= $nf['linkDanfe'] ?: null;
        $nf['tem_pdf']  = !empty($nf['url_pdf']);
    
        // XML via download direto (se tiver chave, monta URL dos Correios/SEFAZ)
        // ou usa linkDanfe como XML — adaptar conforme seu gateway de NF
        $nf['url_xml'] = null; // seu gateway pode fornecer URL do XML
    
        return $nf;
    }


}