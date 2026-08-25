<?php
// app/services/app/AppCartService.php
// Operações de escrita no carrinho, para o app.
//
// ── Por que não usar Cart::addItem() do model ───────────────────────────────
// O projeto tem duas convenções para identificar a variação de um item:
//
//   Cart::addItem()            (app/models/Cart.php:272) grava `estoque_id`
//   CartController::addItem()  (:304) grava `sku_id`
//
// O caminho VIVO — o que a loja usa hoje e o que Cart::getItems() lê de volta —
// é o do controller, com `sku_id`. Usar o método do model deixaria o item sem
// sku_id, e ele apareceria no carrinho sem tamanho, sem cor e sem estoque
// correto. Este serviço segue a convenção viva.
//
// Leitura continua vindo de Cart::getItems() e Cart::getTotals(), que já
// resolvem imagem, atributos agrupadores, atributos de SKU, cupom e frete.

class AppCartService
{
    private PDO $pdo;
    private Cart $cart;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
        $this->cart = new Cart();
    }

    /**
     * Resolve o carrinho do dispositivo, criando se preciso.
     * Depende da ponte de sessão: `sessao_id` é o session_id() do dispositivo.
     *
     * ── Por que não Cart::getOrCreateCarrinhoId() ───────────────────────
     * Aquele método (app/models/Cart.php:52) filtra e insere com
     * `status = 'ativo'`, mas o ENUM da coluna é
     * ('aberto','finalizado','abandonado','expirado') — 'ativo' NÃO existe.
     * O SELECT nunca acha nada e o INSERT falha com "Data truncated".
     * Ele está quebrado contra o schema atual; a loja usa
     * CartController::getCarrinhoId() (:1197), que é o que replicamos aqui:
     * filtra por `status <> 'finalizado'` e deixa o INSERT usar o default.
     */
    public function carrinhoId(bool $criar = false): ?int
    {
        $cached = Session::get('carrinho_id');
        if ($cached) {
            return (int)$cached;
        }

        $clienteId = Session::getClienteId();
        $sessaoId  = session_id();

        if ($clienteId) {
            $st = $this->pdo->prepare(
                "SELECT id FROM carrinhos
                 WHERE cliente_id = ? AND status <> 'finalizado'
                 ORDER BY atualizado_em DESC LIMIT 1"
            );
            $st->execute([$clienteId]);
            if ($id = $st->fetchColumn()) {
                Session::set('carrinho_id', (int)$id);
                return (int)$id;
            }
        }

        $st = $this->pdo->prepare(
            "SELECT id FROM carrinhos
             WHERE sessao_id = ? AND cliente_id IS NULL AND status <> 'finalizado'
             ORDER BY atualizado_em DESC LIMIT 1"
        );
        $st->execute([$sessaoId]);
        if ($id = $st->fetchColumn()) {
            Session::set('carrinho_id', (int)$id);
            return (int)$id;
        }

        if (!$criar) {
            return null;
        }

        // Sem `status` no INSERT: o default da coluna é 'aberto'.
        $this->pdo->prepare(
            "INSERT INTO carrinhos (cliente_id, sessao_id, criado_em, atualizado_em)
             VALUES (?, ?, NOW(), NOW())"
        )->execute([$clienteId, $sessaoId]);

        $novo = (int)$this->pdo->lastInsertId();
        Session::set('carrinho_id', $novo);
        return $novo;
    }

    /**
     * Adiciona item.
     *
     * @return array{ok:bool, msg?:string, item_id?:int, quantidade?:int}
     */
    public function adicionar(int $produtoId, ?int $skuId, int $quantidade): array
    {
        $quantidade = max(1, min(99, $quantidade));

        $produto = $this->produtoAtivo($produtoId);
        if (!$produto) {
            return ['ok' => false, 'msg' => 'Produto não encontrado.'];
        }

        [$preco, $estoque, $erro] = $this->precoEEstoque($produto, $skuId);
        if ($erro) {
            return ['ok' => false, 'msg' => $erro];
        }

        if ($estoque <= 0) {
            return ['ok' => false, 'msg' => 'Produto sem estoque.'];
        }

        $carrinhoId = $this->carrinhoId(true);
        if (!$carrinhoId) {
            return ['ok' => false, 'msg' => 'Não foi possível abrir o carrinho.'];
        }

        // `sku_id IS NULL` não casa com `=`, daí a comparação em duas partes —
        // mesma construção do CartController::addItem() (:288).
        $st = $this->pdo->prepare(
            "SELECT id, quantidade FROM carrinho_itens
             WHERE carrinho_id = ? AND produto_id = ?
               AND (sku_id = ? OR (sku_id IS NULL AND ? IS NULL))
             LIMIT 1"
        );
        $st->execute([$carrinhoId, $produtoId, $skuId, $skuId]);
        $existente = $st->fetch(PDO::FETCH_ASSOC);

        if ($existente) {
            // Somar sem teto deixaria o carrinho com mais unidades do que
            // existem em estoque, e o erro só apareceria no checkout.
            $nova = min((int)$existente['quantidade'] + $quantidade, $estoque);

            $this->pdo->prepare(
                "UPDATE carrinho_itens SET quantidade = ?, subtotal = ? * preco_unitario WHERE id = ?"
            )->execute([$nova, $nova, (int)$existente['id']]);

            $itemId = (int)$existente['id'];
            $qtdFinal = $nova;
        } else {
            if ($quantidade > $estoque) {
                $quantidade = $estoque;
            }

            $this->pdo->prepare(
                "INSERT INTO carrinho_itens
                    (carrinho_id, produto_id, sku_id, quantidade, preco_unitario, subtotal)
                 VALUES (?, ?, ?, ?, ?, ?)"
            )->execute([$carrinhoId, $produtoId, $skuId, $quantidade, $preco, $quantidade * $preco]);

            $itemId = (int)$this->pdo->lastInsertId();
            $qtdFinal = $quantidade;
        }

        $this->tocar($carrinhoId);

        return ['ok' => true, 'item_id' => $itemId, 'quantidade' => $qtdFinal];
    }

    /** @return array{ok:bool, msg?:string, quantidade?:int} */
    public function atualizarQuantidade(int $itemId, int $quantidade): array
    {
        $carrinhoId = $this->carrinhoId();
        if (!$carrinhoId) {
            return ['ok' => false, 'msg' => 'Carrinho vazio.'];
        }

        if ($quantidade <= 0) {
            return $this->remover($itemId);
        }

        $st = $this->pdo->prepare(
            "SELECT ci.produto_id, ci.sku_id, p.estoque_total, ps.estoque AS estoque_sku
             FROM carrinho_itens ci
             JOIN produtos p ON p.id = ci.produto_id
             LEFT JOIN produto_skus ps ON ps.id = ci.sku_id
             WHERE ci.id = ? AND ci.carrinho_id = ? LIMIT 1"
        );
        $st->execute([$itemId, $carrinhoId]);
        $item = $st->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            return ['ok' => false, 'msg' => 'Item não encontrado no carrinho.'];
        }

        $estoque = $item['sku_id']
            ? (int)($item['estoque_sku'] ?? 0)
            : (int)($item['estoque_total'] ?? 0);

        if ($quantidade > $estoque) {
            return [
                'ok' => false,
                'msg' => $estoque > 0
                    ? "Só temos {$estoque} " . ($estoque === 1 ? 'unidade' : 'unidades') . ' em estoque.'
                    : 'Este item ficou sem estoque.',
                'quantidade_maxima' => $estoque,
            ];
        }

        $this->pdo->prepare(
            "UPDATE carrinho_itens SET quantidade = ?, subtotal = ? * preco_unitario
             WHERE id = ? AND carrinho_id = ?"
        )->execute([$quantidade, $quantidade, $itemId, $carrinhoId]);

        $this->tocar($carrinhoId);

        return ['ok' => true, 'quantidade' => $quantidade];
    }

    /** @return array{ok:bool, msg?:string} */
    public function remover(int $itemId): array
    {
        $carrinhoId = $this->carrinhoId();
        if (!$carrinhoId) {
            return ['ok' => false, 'msg' => 'Carrinho vazio.'];
        }

        $st = $this->pdo->prepare("DELETE FROM carrinho_itens WHERE id = ? AND carrinho_id = ?");
        $st->execute([$itemId, $carrinhoId]);

        if ($st->rowCount() === 0) {
            return ['ok' => false, 'msg' => 'Item não encontrado no carrinho.'];
        }

        $this->tocar($carrinhoId);
        return ['ok' => true];
    }

    public function contar(): int
    {
        $carrinhoId = $this->carrinhoId();
        if (!$carrinhoId) {
            return 0;
        }

        try {
            $st = $this->pdo->prepare(
                "SELECT COALESCE(SUM(quantidade), 0) FROM carrinho_itens WHERE carrinho_id = ?"
            );
            $st->execute([$carrinhoId]);
            return (int)$st->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /* =================================================================
       Internos
       ================================================================= */

    private function produtoAtivo(int $produtoId): ?array
    {
        $st = $this->pdo->prepare(
            "SELECT id, nome, estoque_total, preco, preco_promo, promo_inicio, promo_fim
             FROM produtos
             WHERE id = ? AND ativo = 1 AND deleted_at IS NULL
             LIMIT 1"
        );
        $st->execute([$produtoId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Preço e estoque conforme o item tenha ou não variação.
     *
     * O preço é congelado no carrinho (preco_unitario) no momento da adição —
     * é o comportamento da loja, e mudar isso aqui faria o app e o site
     * cobrarem valores diferentes pelo mesmo carrinho.
     *
     * @return array{0:float,1:int,2:?string} [preço, estoque, erro]
     */
    private function precoEEstoque(array $produto, ?int $skuId): array
    {
        if (!$skuId) {
            return [PriceHelper::currentPrice($produto), (int)$produto['estoque_total'], null];
        }

        $st = $this->pdo->prepare(
            "SELECT preco, preco_promo, estoque
             FROM produto_skus
             WHERE id = ? AND produto_id = ? AND ativo = 1
             LIMIT 1"
        );
        $st->execute([$skuId, (int)$produto['id']]);
        $sku = $st->fetch(PDO::FETCH_ASSOC);

        if (!$sku) {
            return [0.0, 0, 'Variação não encontrada.'];
        }
        if ((int)$sku['estoque'] <= 0) {
            return [0.0, 0, 'Esta variação está sem estoque.'];
        }

        return [(float)($sku['preco_promo'] ?: $sku['preco']), (int)$sku['estoque'], null];
    }

    private function tocar(int $carrinhoId): void
    {
        try {
            $this->pdo->prepare("UPDATE carrinhos SET atualizado_em = NOW() WHERE id = ?")
                ->execute([$carrinhoId]);

            // A sessão guarda o contador que o badge do carrinho lê.
            Session::setCarrinhoCount($this->contar());
        } catch (\Throwable $e) {
            AppLog::warning('Falha ao atualizar carrinho', ['carrinho_id' => $carrinhoId]);
        }
    }
}
