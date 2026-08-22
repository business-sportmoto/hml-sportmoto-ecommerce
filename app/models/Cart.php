<?php
// app/models/Cart.php
// Gerencia todo o ciclo de vida do carrinho: criação, itens,
// cupons, frete, persistência em sessão e mesclagem pós-login.

class Cart extends Model {

    protected string $table = 'carrinhos';

    private array $cartStatus = ['aberto','finalizado','abandonado','expirado'];

    private function getStatusNoSelected($status = null) :array {
        if(!$status) return $this->cartStatus;

        return $this->cartStatus = array_values(array_diff($this->cartStatus, [$status]));
    }

    public function reOpenCart($cart_id){        
        $db         = Database::getInstance()->getConnection();
        $status = 'aberto';
        
        $db->prepare(
                "UPDATE carrinhos SET status = ?, atualizado_em = NOW()  WHERE id = ?" )->execute([$status, $cart_id]);                        
    }

    public function getTotalItensCart() {
        if(Session::getCarrinhoCount()){
            return Session::getCarrinhoCount();
        } else {
            $carrinho = $this->getOrCreate();
            $count = $this->countItems((int)$carrinho['id']);
            Session::setCarrinhoCount($count);

            return $count;
        }
    }

    public function finalizaCart($clienteId){     
           
        $db         = Database::getInstance()->getConnection();
        $status = 'finalizado';
        $db->prepare(
                "UPDATE carrinhos
                SET status = ?, atualizado_em = NOW()
                WHERE cliente_id = ?"
            )->execute([$status, $clienteId]);
        $carrinhoId = Session::getCarrinhoId(); 
        $this->clear($carrinhoId);
    }

    // ── Obter ou criar carrinho ───────────────────────────────
    public function getOrCreateCarrinhoId(
        ?int $clienteId,
        string $sessaoId,
        bool $criar = false
    ): ?int {
        // Verifica sessão PHP
        $cached = Session::get('carrinho_id');
        if ($cached) return (int) $cached;

        // Busca no banco
        if ($clienteId) {
            $stmt = $this->db->prepare(
                "SELECT id FROM carrinhos
                WHERE cliente_id = ? AND status = 'ativo'
                ORDER BY atualizado_em DESC LIMIT 1"
            );
            $stmt->execute([$clienteId]);
        } else {
            $stmt = $this->db->prepare(
                "SELECT id FROM carrinhos
                WHERE sessao_id = ? AND status = 'ativo' AND cliente_id IS NULL
                ORDER BY atualizado_em DESC LIMIT 1"
            );
            $stmt->execute([$sessaoId]);
        }

        $id = $stmt->fetchColumn();

        if ($id) {
            Session::set('carrinho_id', $id);
            return (int) $id;
        }

        if (!$criar) return null;

        // Cria novo
        $this->db->prepare(
            "INSERT INTO carrinhos (cliente_id, sessao_id, status, criado_em, atualizado_em)
            VALUES (?, ?, 'ativo', NOW(), NOW())"
        )->execute([$clienteId, $sessaoId]);

        $novoId = (int) $this->db->lastInsertId();
        Session::set('carrinho_id', $novoId);
        return $novoId;
    }

    /**
     * Retorna o carrinho ativo da sessão/cliente.
     * Cria um novo se não existir.
     */
    public function getOrCreate(): array {
        $carrinhoId = Session::getCarrinhoId();
        $clienteId  = Session::getClienteId();

        // Tenta recuperar pelo ID da sessão
        if ($carrinhoId) {
            $carrinho = $this->find($carrinhoId);
            if ($carrinho && $carrinho['status'] !== 'finalizado') {
                // Vincula ao cliente se acabou de logar
                if ($clienteId && !$carrinho['cliente_id']) {
                    $this->update($carrinhoId, ['cliente_id' => $clienteId]);
                    $carrinho['cliente_id'] = $clienteId;
                }
                return $carrinho;
            }            
        }

       
        $statusJson = json_encode($this->getStatusNoSelected('finalizado'));

        // return [$statusJson];

        // Tenta recuperar pelo cliente logado
        if ($clienteId) {
            $stmt = $this->db->prepare(
                "SELECT * FROM carrinhos
                 WHERE cliente_id = ? AND JSON_CONTAINS(?, JSON_QUOTE(status), '$')
                 ORDER BY atualizado_em DESC LIMIT 1"
            );
            $stmt->execute([$clienteId, $statusJson]);
            $carrinho = $stmt->fetch();
            if ($carrinho) {
                Session::setCarrinhoId((int)$carrinho['id']);
                return $carrinho;
            }
        }

        // Cria novo carrinho
        $id = $this->insert([
            'cliente_id' => $clienteId,
            'sessao_id'  => session_id(),
        ]);

        Session::setCarrinhoId($id);
        Session::setCarrinhoCount(0);

        return $this->find($id);
    }

    // ── Itens ─────────────────────────────────────────────────

    /**
     * Retorna todos os itens do carrinho com dados do produto.
     */
    public function getItems(int $carrinhoId): array {
        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare(
            "SELECT
                ci.id,
                ci.produto_id,
                ci.sku_id,
                ci.quantidade,
                ci.preco_unitario,
                ci.quantidade * ci.preco_unitario AS subtotal,
                p.nome          AS nome_produto,
                p.slug          AS produto_slug,
                p.estoque_total AS estoque_produto,
                pi.arquivo      AS imagem,
                ps.sku          AS sku_codigo,
                ps.estoque      AS estoque_sku
            FROM carrinho_itens ci
            JOIN produtos p       ON p.id  = ci.produto_id
            LEFT JOIN produto_imagens pi
                                ON pi.produto_id = ci.produto_id
                                AND pi.principal = 1
            LEFT JOIN produto_skus ps ON ps.id = ci.sku_id
            WHERE ci.carrinho_id = ?
            ORDER BY ci.id ASC"
        );
        $stmt->execute([$carrinhoId]);
        $itens = $stmt->fetchAll();

        if (empty($itens)) return [];

        // Coleta IDs para buscar atributos em batch
        $produtoIds = array_unique(array_column($itens, 'produto_id'));
        $skuIds     = array_filter(array_unique(array_column($itens, 'sku_id')));

        // Busca agrupadores de todos os produtos de uma vez
        $agrupadores = [];
        if (!empty($produtoIds)) {
            $placeholders = implode(',', array_fill(0, count($produtoIds), '?'));
            $stmt = $db->prepare(
                "SELECT paa.produto_id, at.nome, at.slug, at.tipo_display,
                        paa.valor, paa.valor_hex
                FROM produto_atributos_agrupadores paa
                JOIN atributo_tipos at ON at.id = paa.atributo_tipo_id
                WHERE paa.produto_id IN ({$placeholders})
                ORDER BY paa.produto_id, at.ordenacao"
            );
            $stmt->execute($produtoIds);
            foreach ($stmt->fetchAll() as $row) {
                $agrupadores[$row['produto_id']][] = $row;
            }
        }

        // Busca atributos de todos os SKUs de uma vez
        $skuAtributos = [];
        if (!empty($skuIds)) {
            $placeholders = implode(',', array_fill(0, count($skuIds), '?'));
            $stmt = $db->prepare(
                "SELECT sa.sku_id, at.nome, at.slug, at.tipo_display, sa.valor
                FROM sku_atributos sa
                JOIN atributo_tipos at ON at.id = sa.atributo_tipo_id
                WHERE sa.sku_id IN ({$placeholders})
                ORDER BY sa.sku_id, at.ordenacao"
            );
            $stmt->execute(array_values($skuIds));
            foreach ($stmt->fetchAll() as $row) {
                $skuAtributos[$row['sku_id']][] = $row;
            }
        }

        // Monta cada item com seus atributos
        foreach ($itens as &$item) {
            $item['imagem_url'] = ImageHelper::getCartItemImage(
                (int)$item['produto_id'],
                $item['sku_id'] ? (int)$item['sku_id'] : null
            );

            $pid   = (int)$item['produto_id'];
            $skuId = $item['sku_id'] ? (int)$item['sku_id'] : null;

            $attrs = [];

            // Agrupadores do produto (cor, estampa...)
            foreach ($agrupadores[$pid] ?? [] as $a) {
                $attrs[] = [
                    'nome'        => $a['nome'],
                    'valor'       => $a['valor'],
                    'valor_hex'   => $a['valor_hex'] ?? null,
                    'tipo_display'=> $a['tipo_display'],
                ];
            }

            // Atributos do SKU (tamanho, voltagem...)
            foreach ($skuAtributos[$skuId] ?? [] as $a) {
                $attrs[] = [
                    'nome'        => $a['nome'],
                    'valor'       => $a['valor'],
                    'valor_hex'   => null,
                    'tipo_display'=> $a['tipo_display'],
                ];
            }

            $item['atributos']    = $attrs;
            $item['estoque_total']= $skuId
                ? (int)($item['estoque_sku']     ?? 0)
                : (int)($item['estoque_produto'] ?? 0);
        }
        unset($item);

        return $itens;
    }
    

    /**
     * Adiciona item ao carrinho.
     * Se já existir a mesma combinação produto+variação, soma a quantidade.
     */
    public function addItem(int $carrinhoId, int $productId, int $qty,
                             ?int $estoqueId, array $opcoes = []): bool {
        // Valida estoque
        $available = $this->getAvailableStock($productId, $estoqueId);
        if ($available <= 0) {
            throw new RuntimeException('Produto sem estoque disponível.');
        }

        // Verifica se item já existe no carrinho
        $existing = $this->findExistingItem($carrinhoId, $productId, $estoqueId);

        if ($existing) {
            $newQty = $existing['quantidade'] + $qty;
            if ($newQty > $available) {
                throw new RuntimeException("Estoque insuficiente. Disponível: {$available} unidades.");
            }
            $this->db->prepare(
                "UPDATE carrinho_itens SET quantidade = ? WHERE id = ?"
            )->execute([$newQty, $existing['id']]);
        } else {
            if ($qty > $available) {
                throw new RuntimeException("Estoque insuficiente. Disponível: {$available} unidades.");
            }

            // Busca preço vigente do produto
            $product = (new Product())->find($productId);
            $preco   = PriceHelper::currentPrice($product);

            $this->db->prepare(
                "INSERT INTO carrinho_itens
                 (carrinho_id, produto_id, estoque_id, quantidade, preco_unitario, opcoes_selecionadas)
                 VALUES (?, ?, ?, ?, ?, ?)"
            )->execute([
                $carrinhoId,
                $productId,
                $estoqueId,
                $qty,
                $preco,
                !empty($opcoes) ? json_encode($opcoes, JSON_UNESCAPED_UNICODE) : null,
            ]);
        }

        $this->touch($carrinhoId);
        return true;
    }

    /**
     * Remove um item do carrinho pelo ID do item.
     */
    public function removeItem(int $itemId, int $carrinhoId): bool {
        $stmt = $this->db->prepare(
            "DELETE FROM carrinho_itens WHERE id = ? AND carrinho_id = ?"
        );
        $result = $stmt->execute([$itemId, $carrinhoId]);
        if ($result) $this->touch($carrinhoId);
        return $result;
    }

    /**
     * Atualiza a quantidade de um item.
     */
    public function updateItemQty(int $itemId, int $carrinhoId, int $qty): bool {
        if ($qty <= 0) return $this->removeItem($itemId, $carrinhoId);

        // Valida estoque
        $stmt = $this->db->prepare(
            "SELECT produto_id, estoque_id FROM carrinho_itens
             WHERE id = ? AND carrinho_id = ? LIMIT 1"
        );
        $stmt->execute([$itemId, $carrinhoId]);
        $item = $stmt->fetch();
        if (!$item) return false;

        $available = $this->getAvailableStock($item['produto_id'], $item['estoque_id']);
        if ($qty > $available) {
            throw new RuntimeException("Estoque insuficiente. Disponível: {$available} unidades.");
        }

        $this->db->prepare(
            "UPDATE carrinho_itens SET quantidade = ? WHERE id = ? AND carrinho_id = ?"
        )->execute([$qty, $itemId, $carrinhoId]);        

        $this->touch($carrinhoId);
        return true;
    }

    /**
     * Esvazia o carrinho.
     */
    public function clear(int $carrinhoId): void {
        $this->db->prepare(
            "DELETE FROM carrinho_itens WHERE carrinho_id = ?"
        )->execute([$carrinhoId]);
        $this->db->prepare(
            "UPDATE carrinhos SET cupom_id = NULL, desconto = 0 WHERE id = ?"
        )->execute([$carrinhoId]);
        $this->touch($carrinhoId);
    }

    // ── Totais ────────────────────────────────────────────────

    /**
     * Calcula e retorna todos os totais do carrinho.
     */
    public function getTotals(int $carrinhoId): array {
        $state         = new CheckoutState();

        $items    = $this->getItems($carrinhoId);
        $carrinho = $this->find($carrinhoId);

        $subtotal = array_sum(array_column($items, 'subtotal'));
        $frete    = (float)($carrinho['frete_valor'] ?? 0);
        $desconto = 0.0;

        // Aplica cupom
        if (!empty($carrinho['cupom_id'])) {
            $cupom = $this->getCupom($carrinho['cupom_id']);
            if ($cupom) {
                $desconto = $this->calcDesconto($cupom, $subtotal, $frete);
            }
        }

        // $frete = 0;
        // // Frete grátis por configuração
        // $getFrete = $state->getFrete();
        // if ($getFrete) {
        //     $frete = $getFrete['valor'] ?? $frete;
        // }

        $data = [];
        // $data['checkoutFrete'] = $state->getFrete();
        // $data['checkoutCupom'] = $state->getCupom();

        $total = max(0, $subtotal + $frete - $desconto);

        return [
            'debug'         => $data,
            'items'         => $items,
            'total_itens'   => array_sum(array_column($items, 'quantidade')),
            'subtotal'      => $subtotal,
            'subtotal_fmt'  => PriceHelper::format($subtotal),
            'frete'         => $frete,
            'frete_fmt'     => $frete > 0 ? PriceHelper::format($frete) : 'Grátis',
            'frete_servico' => $carrinho['frete_servico'],
            'frete_prazo'   => $carrinho['frete_prazo'],
            'frete_cep'     => $carrinho['frete_cep'],
            'desconto'      => $desconto,
            'desconto_fmt'  => $desconto > 0 ? '- ' . PriceHelper::format($desconto) : null,
            'total'         => $total,
            'total_fmt'     => PriceHelper::format($total),
            'cupom'         => !empty($carrinho['cupom_id']) ? $this->getCupom($carrinho['cupom_id']) : null,
            'codigo_vendedor' => $carrinho['codigo_vendedor'] ?? null,
        ];
    }

    // ── Cupom ─────────────────────────────────────────────────

    /**
     * Valida e aplica um cupom ao carrinho.
     */
    public function applyCupom(int $carrinhoId, string $codigo, int $clienteId = 0): array {
        $codigo = strtoupper(trim($codigo));

        $stmt = $this->db->prepare(
            "SELECT * FROM cupons
             WHERE codigo = ? AND ativo = 1
               AND (valido_de  IS NULL OR valido_de  <= NOW())
               AND (valido_ate IS NULL OR valido_ate >= NOW())
             LIMIT 1"
        );
        $stmt->execute([$codigo]);
        $cupom = $stmt->fetch();

        if (!$cupom) {
            return ['ok' => false, 'msg' => 'Cupom inválido ou expirado.'];
        }

        // Verifica limite total de uso
        if ($cupom['limite_total'] !== null && $cupom['usado'] >= $cupom['limite_total']) {
            return ['ok' => false, 'msg' => 'Este cupom atingiu o limite de usos.'];
        }

        // Verifica limite por usuário
        if ($clienteId && $cupom['limite_por_usuario'] > 0) {
            $stmt2 = $this->db->prepare(
                "SELECT COUNT(*) FROM cupom_uso WHERE cupom_id = ? AND cliente_id = ?"
            );
            $stmt2->execute([$cupom['id'], $clienteId]);
            if ((int)$stmt2->fetchColumn() >= $cupom['limite_por_usuario']) {
                return ['ok' => false, 'msg' => 'Você já utilizou este cupom o número máximo de vezes.'];
            }
        }

        // Verifica valor mínimo
        $totals = $this->getTotals($carrinhoId);
        if ($cupom['minimo_pedido'] > 0 && $totals['subtotal'] < $cupom['minimo_pedido']) {
            return [
                'ok'  => false,
                'msg' => 'Pedido mínimo para este cupom: ' . PriceHelper::format($cupom['minimo_pedido']),
            ];
        }

        // Aplica
        $this->db->prepare(
            "UPDATE carrinhos SET cupom_id = ?, desconto = ? WHERE id = ?"
        )->execute([
            $cupom['id'],
            $this->calcDesconto($cupom, $totals['subtotal'], $totals['frete']),
            $carrinhoId,
        ]);

        $this->touch($carrinhoId);

        return ['ok' => true, 'msg' => 'Cupom aplicado com sucesso!', 'cupom' => $cupom];
    }

    public function removeCupom(int $carrinhoId): void {
        $this->db->prepare(
            "UPDATE carrinhos SET cupom_id = NULL, desconto = 0 WHERE id = ?"
        )->execute([$carrinhoId]);
        $this->touch($carrinhoId);
    }

    // ── Vendedor ──────────────────────────────────────────────

    public function applyVendedor(int $carrinhoId, string $codigo): array {
        $stmt = $this->db->prepare(
            "SELECT * FROM vendedores WHERE codigo = ?"
        );
        $stmt->execute([strtoupper(trim($codigo))]);
        $vendedor = $stmt->fetch();

        if (!$vendedor) {
            return ['ok' => false, 'msg' => 'Código de vendedor não encontrado.', 'teste'=>$codigo];
        }

        $this->db->prepare(
            "UPDATE carrinhos SET codigo_vendedor = ? WHERE id = ?"
        )->execute([$vendedor['codigo'], $carrinhoId]);

        return [
            'ok'  => true,
            'msg' => "Vendedor: {$vendedor['nome']}",
            'vendedor' => $vendedor,
        ];
    }

    // ── Frete ─────────────────────────────────────────────────

    public function saveShipping(int $carrinhoId, array $frete): void {
        $this->db->prepare(
            "UPDATE carrinhos
             SET frete_valor = ?, frete_servico = ?, frete_prazo = ?, frete_cep = ?
             WHERE id = ?"
        )->execute([
            $frete['valor'],
            $frete['servico'],
            $frete['prazo'],
            preg_replace('/\D/', '', $frete['cep']),
            $carrinhoId,
        ]);
        $this->touch($carrinhoId);
    }

    // ── Compartilhamento ──────────────────────────────────────

    /**
     * Gera token de compartilhamento do carrinho.
     */
    public function generateShareToken(int $carrinhoId): string {
        $token = SecurityHelper::generateToken(16);
        // Armazena token temporariamente na sessão (basta para o link funcionar)
        Session::set('cart_share_token_' . $token, $carrinhoId);
        return $token;
    }

    /**
     * Restaura carrinho de um token compartilhado.
     */
    public function restoreFromToken(string $token): bool {
        $carrinhoId = Session::get('cart_share_token_' . $token);
        if (!$carrinhoId) return false;

        $original = $this->find($carrinhoId);
        if (!$original) return false;

        // Cria novo carrinho para o usuário atual com os mesmos itens
        $newId = $this->insert([
            'cliente_id' => Session::getClienteId(),
            'sessao_id'  => session_id(),
        ]);

        $items = $this->getItems($carrinhoId);
        foreach ($items as $item) {
            $this->addItem($newId, $item['produto_id'], $item['quantidade'],
                           $item['estoque_id'], $item['opcoes']);
        }

        Session::setCarrinhoId($newId);
        return true;
    }

    // ── Contagem ──────────────────────────────────────────────

    public function countItems(int $carrinhoId): int {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(quantidade), 0) FROM carrinho_itens WHERE carrinho_id = ?"
        );
        $stmt->execute([$carrinhoId]);
        return (int)$stmt->fetchColumn();
    }

    // ── Helpers privados ──────────────────────────────────────

    private function findExistingItem(int $carrinhoId, int $productId, ?int $estoqueId): ?array {
        $sql    = "SELECT * FROM carrinho_itens WHERE carrinho_id = ? AND produto_id = ?";
        $params = [$carrinhoId, $productId];

        if ($estoqueId) {
            $sql    .= " AND estoque_id = ?";
            $params[] = $estoqueId;
        } else {
            $sql .= " AND estoque_id IS NULL";
        }

        $sql .= " LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() ?: null;
    }

    private function getAvailableStock(int $productId, ?int $estoqueId): int {
        if ($estoqueId) {
            $stmt = $this->db->prepare(
                "SELECT quantidade FROM produto_estoque WHERE id = ? LIMIT 1"
            );
            $stmt->execute([$estoqueId]);
            return (int)$stmt->fetchColumn();
        }
        $stmt = $this->db->prepare(
            "SELECT estoque_total FROM produtos WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$productId]);
        return (int)$stmt->fetchColumn();
    }

    private function getCupom(int $cupomId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM cupons WHERE id = ? LIMIT 1");
        $stmt->execute([$cupomId]);
        return $stmt->fetch() ?: null;
    }

    private function calcDesconto(array $cupom, float $subtotal, float $frete): float {
        $desconto = match($cupom['tipo']) {
            'percentual'   => $subtotal * ($cupom['valor'] / 100),
            'fixo'         => min((float)$cupom['valor'], $subtotal),
            'frete_gratis' => $frete,
            default        => 0.0,
        };

        // Respeita teto máximo de desconto
        if (!empty($cupom['maximo_desconto']) && $desconto > $cupom['maximo_desconto']) {
            $desconto = (float)$cupom['maximo_desconto'];
        }

        return round($desconto, 2);
    }

    private function touch(int $carrinhoId): void {
        $this->db->prepare(
            "UPDATE carrinhos SET atualizado_em = NOW() WHERE id = ?"
        )->execute([$carrinhoId]);
        // Atualiza contagem na sessão
        Session::setCarrinhoCount($this->countItems($carrinhoId));
    }


   /**
     * Retorna dados do vendedor e compartilhamento de um carrinho.
     * Verifica tanto a tabela carrinhos quanto carrinhos_compartilhados.
     */
    public static function getVendedorInfo(int $carrinhoId): array {
        $db = Database::getInstance()->getConnection();

        // ── 1. Dados do carrinho principal ───────────────────
        $stmt = $db->prepare(
            "SELECT
                c.codigo_vendedor,
                c.compartilhado_por_nome,
                c.compartilhado_por_usuario,
                u_comp.nome  AS usuario_comp_nome,
                v.codigo     AS vend_codigo,
                u_vend.nome  AS vend_nome
            FROM carrinhos c
            LEFT JOIN usuarios u_comp ON u_comp.id = c.compartilhado_por_usuario
            LEFT JOIN vendedores v    ON v.codigo   = c.codigo_vendedor
                                    AND v.ativo    = 1
            LEFT JOIN usuarios u_vend ON u_vend.id  = v.usuario_id
            WHERE c.id = ?
            LIMIT 1"
        );
        
        $stmt->execute([$carrinhoId]);
        $row = $stmt->fetch();

        if (!$row) {
            return self::emptyVendedorInfo();
        }

        $vendedorCodigo   = $row['codigo_vendedor']   ?: null;
        $vendedorNome     = $row['vend_nome']
                        ?: ($row['vend_codigo']       ?: null);
        $compartilhadoPor = $row['usuario_comp_nome']
                        ?? ($row['compartilhado_por_nome'] ?: null);
        $compartilhadoPorUsuarioId = $row['compartilhado_por_usuario']
                                    ? (int)$row['compartilhado_por_usuario']
                                    : null;

        // ── 2. Se não tiver vendedor no carrinho principal,
        //       busca no compartilhamento mais recente ────────
        if (!$vendedorCodigo) {
            $stmt = $db->prepare(
                "SELECT
                    cc.vendedor_codigo,
                    cc.vendedor_nome,
                    cc.nome_visitante,
                    u.nome AS usuario_nome
                FROM carrinhos_compartilhados cc
                LEFT JOIN usuarios u ON u.id = cc.usuario_id
                WHERE cc.carrinho_id = ?
                AND cc.expira_em > NOW()
                ORDER BY cc.criado_em DESC
                LIMIT 1"
            );
            $stmt->execute([$carrinhoId]);
            $shared = $stmt->fetch();

            if ($shared) {
                // Vendedor do compartilhamento
                if (!$vendedorCodigo && $shared['vendedor_codigo']) {
                    $vendedorCodigo = $shared['vendedor_codigo'];
                    $vendedorNome   = $shared['vendedor_nome'] ?: $shared['vendedor_codigo'];
                }

                // Quem compartilhou (se não tiver no carrinho principal)
                if (!$compartilhadoPor) {
                    $compartilhadoPor = $shared['usuario_nome']
                                    ?? ($shared['nome_visitante'] ?: null);
                }
            }
        }

        return [
            'vendedor_codigo'           => $vendedorCodigo,
            'vendedor_nome'             => $vendedorNome,
            'compartilhado_por'         => $compartilhadoPor,
            'compartilhado_por_usuario' => $compartilhadoPorUsuarioId,
        ];
    }

    /**
     * Versão para carrinho compartilhado — busca direto pelo token.
     */
    public static function getVendedorInfoByToken(string $token): array {
        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare(
            "SELECT
                cc.vendedor_codigo,
                cc.vendedor_nome,
                cc.nome_visitante,
                cc.carrinho_id,
                u.nome AS usuario_nome
            FROM carrinhos_compartilhados cc
            LEFT JOIN usuarios u ON u.id = cc.usuario_id
            WHERE cc.token = ?
            AND cc.expira_em > NOW()
            LIMIT 1"
        );
        $stmt->execute([$token]);
        $shared = $stmt->fetch();

        if (!$shared) return self::emptyVendedorInfo();

        // Tenta buscar também do carrinho original
        $infoCarrinho = self::getVendedorInfo((int)$shared['carrinho_id']);

        return [
            'vendedor_codigo'           => $shared['vendedor_codigo']
                                        ?: $infoCarrinho['vendedor_codigo'],
            'vendedor_nome'             => $shared['vendedor_nome']
                                        ?: $infoCarrinho['vendedor_nome'],
            'compartilhado_por'         => $shared['usuario_nome']
                                        ?? ($shared['nome_visitante']
                                        ?: $infoCarrinho['compartilhado_por']),
            'compartilhado_por_usuario' => $infoCarrinho['compartilhado_por_usuario'],
        ];
    }

    /**
     * Retorna array vazio padrão.
     */
    private static function emptyVendedorInfo(): array {
        return [
            'vendedor_codigo'           => null,
            'vendedor_nome'             => null,
            'compartilhado_por'         => null,
            'compartilhado_por_usuario' => null,
        ];
    }

    
// ════════════════════════════════════════════════════════
// ADICIONAR ao app/models/Cart.php
// ════════════════════════════════════════════════════════
// Cola estes métodos públicos no final da classe Cart,
// antes do último `}`.
//
// Dependências (já existem na sua tabela):
//   carrinho_itens: carrinho_id, produto_id, sku_id, quantidade
//   produto_skus:   codigo, preco, preco_promo, peso,
//                   comprimento, largura, altura
//   produto_imagens: produto_id, arquivo, principal
// ════════════════════════════════════════════════════════

    // ════════════════════════════════════════════════════
    // NECESSÁRIO PELO CheckoutState
    // ════════════════════════════════════════════════════

    /**
     * Verifica se o carrinho atual está vazio.
     */
    public function isEmpty(): bool {
        $carrinho = $this->getOrCreate();
        if (empty($carrinho['id'])) return true;

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM carrinho_itens WHERE carrinho_id = ?"
        );
        $stmt->execute([(int)$carrinho['id']]);
        return ((int)$stmt->fetchColumn()) === 0;
    }

    // ════════════════════════════════════════════════════
    // NECESSÁRIO PELO CheckoutController / Summary
    // ════════════════════════════════════════════════════

    /**
     * Retorna itens do carrinho com variações do SKU formatadas.
     * Gera $item['variacao_label'] = "Cor: Preto · Tamanho: M"
     *
     * Requer: produto_atributos, produto_atributo_valores, sku_atributo_valores
     * Se essas tabelas não existirem, retorna sem variacao_label.
     */
    public function getItensComVariacoes(int $clienteId): array {
        $carrinho = $this->getByCliente($clienteId);
        if (!$carrinho) return [];

        // Busca itens com dados básicos do produto + sku
        $stmt = $this->db->prepare(
            "SELECT
                ci.id          AS item_id,
                ci.quantidade,
                ci.produto_id,
                ci.sku_id,
                p.id            AS pro_id,
                p.nome,
                p.slug,
                pi.arquivo     AS imagem_principal,
                COALESCE(NULLIF(s.preco_promo, 0), s.preco) AS valor_unitario,
                p.preco AS preco_master,
                s.id        AS sku_id_pro,
                s.sku       AS sku_codigo,
                s.peso_kg,
                s.comprimento_cm,
                s.largura_cm,
                s.altura_cm
             FROM carrinho_itens ci
             JOIN produtos p             ON p.id = ci.produto_id
             LEFT JOIN produto_skus s    ON s.id = ci.sku_id
             LEFT JOIN produto_imagens pi ON pi.produto_id = p.id AND pi.principal = 1
             WHERE ci.carrinho_id = ?
               AND p.ativo = 1
             ORDER BY ci.id ASC"
        );
        $stmt->execute([(int)$carrinho['id']]);
        $itens = $stmt->fetchAll();

        if (empty($itens)) return [];

        // Tenta buscar atributos dos SKUs (tabelas opcionais)
        $skuIds = array_filter(array_column($itens, 'sku_id'));
        $atributos = $this->buscarAtributosDosSkus($skuIds);

        foreach ($itens as &$item) {
            $skuId = (int)($item['sku_id'] ?? 0);
            $item['variacao_label'] = $skuId && isset($atributos[$skuId])
                ? $atributos[$skuId]
                : null;
        }
        unset($item);

        return $itens;
    }

    public function getItensComVariacoesByCartId(int $carrinhoId): array {
        // $carrinho = $this->getByCliente($clienteId);
        if (!$carrinhoId) return [];

        // Busca itens com dados básicos do produto + sku
        $stmt = $this->db->prepare(
            "SELECT
                ci.id          AS item_id,
                ci.quantidade,
                ci.produto_id,
                ci.sku_id,
                p.id            AS pro_id,
                p.nome,
                p.slug,
                pi.arquivo     AS imagem_principal,
                COALESCE(NULLIF(s.preco_promo, 0), s.preco) AS valor_unitario,
                p.preco AS preco_master,
                s.id        AS sku_id_pro,
                s.sku       AS sku_codigo,
                s.peso_kg,
                s.comprimento_cm,
                s.largura_cm,
                s.altura_cm
             FROM carrinho_itens ci
             JOIN produtos p             ON p.id = ci.produto_id
             LEFT JOIN produto_skus s    ON s.id = ci.sku_id
             LEFT JOIN produto_imagens pi ON pi.produto_id = p.id AND pi.principal = 1
             WHERE ci.carrinho_id = ?
               AND p.ativo = 1
             ORDER BY ci.id ASC"
        );
        $stmt->execute([(int)$carrinhoId]);
        $itens = $stmt->fetchAll();

        if (empty($itens)) return [];

        // Tenta buscar atributos dos SKUs (tabelas opcionais)
        $skuIds = array_filter(array_column($itens, 'sku_id'));
        $atributos = $this->buscarAtributosDosSkus($skuIds);

        foreach ($itens as &$item) {
            $skuId = (int)($item['sku_id'] ?? 0);
            $item['variacao_label'] = $skuId && isset($atributos[$skuId])
                ? $atributos[$skuId]
                : null;
        }
        unset($item);

        return $itens;
    }

    /**
     * Retorna itens com dados de dimensão para cálculo de frete.
     * Formato esperado pelo FreteService::montarPayloadProds().
     */
    public function getItensComDimensoes(int $clienteId): array {
        $itens = $this->getItensComVariacoes($clienteId);

        return array_map(function ($i) {
            return [
                'produto_id'     => $i['produto_id'],
                'sku_id'         => $i['sku_id']     ?? null,
                'sku_codigo'     => $i['sku_codigo']  ?? (string)$i['produto_id'],
                'quantidade'     => (int)$i['quantidade'],
                'valor_unitario' => (float)$i['valor_unitario'] ?? $i['preco_master'],
                'peso'           => (float)($i['peso']         ?? 0.5),
                'comprimento'    => (float)($i['comprimento']  ?? 0.20),
                'largura'        => (float)($i['largura']      ?? 0.20),
                'altura'         => (float)($i['altura']       ?? 0.10),
            ];
        }, $itens);
    }

    /**
     * Calcula subtotal, desconto e total a partir de um array de itens.
     * Aceita o desconto de frete/cupom já salvo no estado.
     */
    public function calcularTotais(array $itens, float $frete = 0, float $desconto = 0): array {
        $subtotal = array_reduce($itens, function ($carry, $item) {
            return $carry + ((float)($item['valor_unitario'] ?? 0) * (int)($item['quantidade'] ?? 1));
        }, 0.0);

        $total = max(0, $subtotal - $desconto + $frete);

        return [
            'subtotal' => round($subtotal, 2),
            'desconto' => round($desconto, 2),
            'frete'    => round($frete, 2),
            'total'    => round($total, 2),
        ];
    }

    // Não mexe no primeiro caso ele precise buscar o valor_unitario
    public function calcularTotais2(array $itens, float $frete = 0, float $desconto = 0): array {
        $subtotal = array_reduce($itens, function ($carry, $item) {
            return $carry + ((float)($item['preco'] ?? 0) * (int)($item['qtd'] ?? 1));
        }, 0.0);

        $total = max(0, $subtotal - $desconto + $frete);

        return [
            'subtotal' => round($subtotal, 2),
            'desconto' => round($desconto, 2),
            'frete'    => round($frete, 2),
            'total'    => round($total, 2),
        ];
    }

    /**
     * Valida um cupom de desconto.
     *
     * Se você tiver uma tabela `cupons`, adapte a query abaixo.
     * Se não tiver, retorna null (checkout funciona sem cupom).
     *
     * @return array{valido:bool, desconto:float, tipo:string}|null
     */
    public function validarCupom(string $codigo): ?array {
        // Verifica se a tabela de cupons existe antes de tentar
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM cupons
                 WHERE codigo = ?
                   AND ativo = 1
                   AND (validade_ate IS NULL OR validade_ate >= NOW())
                   AND (uso_maximo   IS NULL OR usos_realizados < uso_maximo)
                 LIMIT 1"
            );
            $stmt->execute([mb_strtoupper(trim($codigo))]);
            $cupom = $stmt->fetch();
        } catch (\PDOException $e) {
            // Tabela não existe → cupons desativados
            return null;
        }

        if (!$cupom) return null;

        return [
            'valido'   => true,
            'desconto' => (float)$cupom['valor_desconto'],
            'tipo'     => $cupom['tipo_desconto'] ?? 'fixo',  // fixo | percentual
        ];
    }

    // ════════════════════════════════════════════════════
    // HELPERS PRIVADOS
    // ════════════════════════════════════════════════════

    /**
     * Retorna o carrinho ativo de um cliente logado.
     * Diferente de getOrCreate() que usa sessão — este usa cliente_id diretamente.
     */
    private function getByCliente(int $clienteId): ?array {
        $stmt = $this->db->prepare(
            "SELECT * FROM carrinhos
             WHERE cliente_id = ?
            --    AND status = 'aberto'
             ORDER BY atualizado_em DESC
             LIMIT 1"
        );
        $stmt->execute([$clienteId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Busca atributos de múltiplos SKUs de uma vez.
     * Retorna [ sku_id => 'Cor: Preto · Tamanho: M' ]
     *
     * Tenta três schemas comuns de atributos. Se nenhuma tabela existir,
     * retorna array vazio silenciosamente.
     */
    private function buscarAtributosDosSkus(array $skuIds): array {
        if (empty($skuIds)) return [];

        $in     = implode(',', array_fill(0, count($skuIds), '?'));
        $result = [];

        // Schema 1: sku_atributo_valores → produto_atributo_valores → produto_atributos
        try {
            $stmt = $this->db->prepare(
                "SELECT
                    sav.sku_id,
                    pa.nome   AS atributo,
                    pav.valor AS valor
                 FROM sku_atributo_valores sav
                 JOIN produto_atributo_valores pav ON pav.id = sav.atributo_valor_id
                 JOIN produto_atributos pa         ON pa.id  = pav.atributo_id
                 WHERE sav.sku_id IN ({$in})
                 ORDER BY sav.sku_id, pa.ordem ASC"
            );
            $stmt->execute(array_values($skuIds));
            $rows = $stmt->fetchAll();

            foreach ($rows as $row) {
                $sid = (int)$row['sku_id'];
                $result[$sid][] = $row['atributo'] . ': ' . $row['valor'];
            }

            foreach ($result as $sid => $parts) {
                $result[$sid] = implode(' · ', $parts);
            }
            return $result;

        } catch (\PDOException $e) {
            // Schema 1 não existe, tenta schema 2
        }

        // Schema 2: sku_variacao_valores (simpler join)
        try {
            $stmt = $this->db->prepare(
                "SELECT
                    svv.sku_id,
                    vo.nome  AS atributo,
                    v.nome   AS valor
                 FROM sku_variacao_valores svv
                 JOIN variacoes v          ON v.id  = svv.variacao_id
                 JOIN variacoes_opcoes vo  ON vo.id = v.opcao_id
                 WHERE svv.sku_id IN ({$in})
                 ORDER BY svv.sku_id, vo.ordem ASC"
            );
            $stmt->execute(array_values($skuIds));
            $rows = $stmt->fetchAll();

            foreach ($rows as $row) {
                $sid = (int)$row['sku_id'];
                $result[$sid][] = $row['atributo'] . ': ' . $row['valor'];
            }
            foreach ($result as $sid => $parts) {
                $result[$sid] = implode(' · ', $parts);
            }
            return $result;

        } catch (\PDOException $e) {
            // Nenhum schema de atributo encontrado — retorna vazio
            return [];
        }
    }

    public function getItensParaCupom(?int $clienteId): array {
        $itens = $this->getItensComVariacoes($clienteId ?? 0);
        // Normaliza para o formato esperado pelo CouponService
        return array_map(function ($item) {
            return [
                'id'           => $item['item_id'] ?? $item['id'],
                'produto_id'   => (int)$item['produto_id'],
                'preco'        => (float)($item['valor_unitario'] ?? $item['preco'] ?? $item['preco_master'] ?? 0),
                'qtd'          => (int)$item['quantidade'],
                'categoria_id' => (int)($item['categoria_id'] ?? 0),
                'marca_id'     => (int)($item['marca_id']     ?? 0),
                'em_promocao'  => !empty($item['preco_promo']) && $item['preco_promo'] < $item['preco'],
            ];
        }, $itens);
    }    

    /**
     * Retorna o ID do carrinho ativo da sessão ou do banco.
     * Se $criarSeNaoExistir = true, cria um novo carrinho.
     */
    public function getCarrinhoId(bool $criarSeNaoExistir = false): ?int {
        $carrinhoId = Session::get('carrinho_id');
        if ($carrinhoId) return (int) $carrinhoId;

        $db = Database::getInstance()->getConnection();

        // Cliente logado
        if (Session::isClienteLogado()) {
            $clienteId = (int) Session::getClienteId();
            $stmt = $db->prepare(
                "SELECT id FROM carrinhos
                WHERE cliente_id = ? AND status <> 'finalizado'
                ORDER BY atualizado_em DESC
                LIMIT 1"
            );
            $stmt->execute([$clienteId]);
            $id = $stmt->fetchColumn();

            if ($id) {
                Session::set('carrinho_id', $id);
                return (int) $id;
            }
        }

        // Visitante — pelo session_id
        $sessaoId = session_id();
        $stmt = $db->prepare(
            "SELECT id FROM carrinhos
            WHERE sessao_id = ? AND cliente_id IS NULL
            ORDER BY atualizado_em DESC
            LIMIT 1"
        );
        $stmt->execute([$sessaoId]);
        $id = $stmt->fetchColumn();

        if ($id) {
            Session::set('carrinho_id', $id);
            return (int) $id;
        }

        // Cria novo se solicitado
        if ($criarSeNaoExistir) {
            $clienteId = Session::isClienteLogado()
                        ? (int) Session::getClienteId()
                        : null;

            $db->prepare(
                "INSERT INTO carrinhos (cliente_id, sessao_id, criado_em, atualizado_em)
                VALUES (?, ?, NOW(), NOW())"
            )->execute([$clienteId, $sessaoId]);

            $novoId = (int) $db->lastInsertId();
            Session::set('carrinho_id', $novoId);
            return $novoId;
        }

        return null;
    }

}