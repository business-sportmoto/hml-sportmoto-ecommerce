<?php
// app/controllers/CartController.php
// Gerencia todas as operações do carrinho:
// adicionar, remover, atualizar, cupom, frete, mini-cart e compartilhamento.

class CartController extends Controller {

    private Cart $cartModel;

    public function __construct() {
        $this->cartModel = new Cart();
    }

    // ── Página do carrinho ────────────────────────────────────

    // public function index(): void {
    //     $carrinho = $this->cartModel->getOrCreate();
    //     $totals   = $this->cartModel->getTotals((int)$carrinho['id']);

    //     SeoHelper::setTitle('Meu Carrinho');

    //     $this->render('cart/index', [
    //         'carrinho' => $carrinho,
    //         'totals'   => $totals,
    //     ]);
    // }
    

    public function index(): void {
        $carrinhoId = $this->getCarrinhoId();
        $db         = Database::getInstance()->getConnection();

        $vendedorNome      = null;
        $vendedorCodigo    = null;
        $compartilhadoPor  = null;

        if ($carrinhoId) {
           
            $vendedorInfo = Cart::getVendedorInfo($carrinhoId);
            if ($vendedorInfo) {
                $vendedorCodigo   = $vendedorInfo['codigo_vendedor'] ?? null;
                $vendedorNome     = $vendedorInfo['vendedor_nome']   ?? null;
                $compartilhadoPor = $vendedorInfo['compartilhado_por'] ?? null;
            }
        }

        $cartModel = new Cart();
        $itens     = $carrinhoId ? $cartModel->getItems($carrinhoId)   : [];
        $totals    = $carrinhoId ? $cartModel->getTotals($carrinhoId)  : [];

        SeoHelper::setTitle('Carrinho de compras');
        SeoHelper::setRobots('noindex, nofollow');

        $this->render('cart/index', [
            'itens'            => $itens,
            'totals'           => $totals,
            'carrinho_id'      => $carrinhoId,
            'vendedor_codigo'  => $vendedorCodigo,
            'vendedor_nome'    => $vendedorNome,
            'compartilhado_por'=> $compartilhadoPor,
        ]);
    }

    // ── Adicionar item ────────────────────────────────────────

    public function add(): void {
       $this->verifyCsrf();

    //    $carrinho = $this->cartModel->getOrCreate();

        $produtoId  = SecurityHelper::sanitizeInt($_POST['produto_id'] ?? 0);
        $skuId      = SecurityHelper::sanitizeInt($_POST['sku_id']     ?? 0);
        $quantidade = max(1, (int)($_POST['quantidade'] ?? 1));

        if (!$produtoId) {
            $this->json(['ok' => false, 'msg' => 'Produto inválido.']);
        }

        $db = Database::getInstance()->getConnection();

        // Valida o produto
        $stmt = $db->prepare(
            "SELECT id, nome, estoque_total, ativo
            FROM produtos WHERE id = ? AND ativo = 1 LIMIT 1"
        );
        $stmt->execute([$produtoId]);
        $produto = $stmt->fetch();

        if (!$produto) {
            $this->json(['ok' => false, 'msg' => 'Produto não encontrado.']);
        }

        // Valida o SKU se informado
        $preco  = null;
        $estoque = null;

        if ($skuId) {
            $stmt = $db->prepare(
                "SELECT id, preco, preco_promo, estoque
                FROM produto_skus
                WHERE id = ? AND produto_id = ? AND ativo = 1 LIMIT 1"
            );
            $stmt->execute([$skuId, $produtoId]);
            $sku = $stmt->fetch();

            if (!$sku) {
                $this->json(['ok' => false, 'msg' => 'Variação não encontrada.']);
            }

            if ($sku['estoque'] <= 0) {
                $this->json(['ok' => false, 'msg' => 'Variação sem estoque.']);
            }

            $preco   = (float)($sku['preco_promo'] ?: $sku['preco']);
            $estoque = (int)$sku['estoque'];
        } else {
            // Produto sem variação
            $stmt = $db->prepare(
                "SELECT preco, preco_promo FROM produtos WHERE id = ? LIMIT 1"
            );
            $stmt->execute([$produtoId]);
            $p = $stmt->fetch();
            $preco   = (float)($p['preco_promo'] ?: $p['preco']);
            $estoque = (int)$produto['estoque_total'];
        }

        if ($estoque <= 0) {
            $this->json(['ok' => false, 'msg' => 'Produto sem estoque.']);
        }

        $carrinhoId = $this->cartModel->getOrCreate()['id'];

        if(!isset($carrinhoId)){
            $this->json(['ok' => false, 'msg' => 'Algo deu errado com seu carinho. Já apliquei as correções, só preciso que você atualize a pagina!']);
        }

        // $this->json(['ok' => false, 'msg' => $carrinhoId]);

        // Verifica se já existe no carrinho (mesmo produto + mesmo SKU)
        $stmt = $db->prepare(
            "SELECT id, quantidade FROM carrinho_itens
            WHERE carrinho_id = ?
            AND produto_id  = ?
            AND (sku_id = ? OR (sku_id IS NULL AND ? IS NULL))
            LIMIT 1"
        );
        $skuIdParam = $skuId ?: null;
        $stmt->execute([$carrinhoId, $produtoId, $skuIdParam, $skuIdParam]);
        $itemExistente = $stmt->fetch();

        if ($itemExistente) {
            $novaQty = min($itemExistente['quantidade'] + $quantidade, $estoque);
            $db->prepare(
                "UPDATE carrinho_itens
                SET quantidade = ?, subtotal = ? * preco_unitario
                WHERE id = ?"
            )->execute([$novaQty, $novaQty, $itemExistente['id']]);
        } else {
            $db->prepare(
                "INSERT INTO carrinho_itens
                (carrinho_id, produto_id, sku_id, quantidade, preco_unitario, subtotal)
                VALUES (?, ?, ?, ?, ?, ?)"
            )->execute([
                $carrinhoId,
                $produtoId,
                $skuIdParam,
                $quantidade,
                $preco,
                $quantidade * $preco,
            ]);
        }

        // Atualiza contagem na sessão
        $stmt = $db->prepare(
            "SELECT COALESCE(SUM(quantidade), 0)
            FROM carrinho_itens WHERE carrinho_id = ?"
        );
        $stmt->execute([$carrinhoId]);
        $count = (int)$stmt->fetchColumn();
        Session::set('carrinho_count', $count);

        $this->json([
            'ok'    => true,
            'msg'   => 'Produto adicionado ao carrinho!',
            'count' => $count,
        ]);
    }

    public function addItem(): void {
        $this->verifyCsrf();

        $produtoId  = SecurityHelper::sanitizeInt($_POST['produto_id'] ?? 0);
        $skuId      = SecurityHelper::sanitizeInt($_POST['sku_id']     ?? 0);
        $quantidade = max(1, (int)($_POST['quantidade'] ?? 1));

        if (!$produtoId) {
            $this->json(['ok' => false, 'msg' => 'Produto inválido.']);
        }

        $db = Database::getInstance()->getConnection();

        // Valida o produto
        $stmt = $db->prepare(
            "SELECT id, nome, estoque_total, ativo
            FROM produtos WHERE id = ? AND ativo = 1 LIMIT 1"
        );
        $stmt->execute([$produtoId]);
        $produto = $stmt->fetch();

        if (!$produto) {
            $this->json(['ok' => false, 'msg' => 'Produto não encontrado.']);
        }

        // Valida o SKU se informado
        $preco  = null;
        $estoque = null;

        if ($skuId) {
            $stmt = $db->prepare(
                "SELECT id, preco, preco_promo, estoque
                FROM produto_skus
                WHERE id = ? AND produto_id = ? AND ativo = 1 LIMIT 1"
            );
            $stmt->execute([$skuId, $produtoId]);
            $sku = $stmt->fetch();

            if (!$sku) {
                $this->json(['ok' => false, 'msg' => 'Variação não encontrada.']);
            }

            if ($sku['estoque'] <= 0) {
                $this->json(['ok' => false, 'msg' => 'Variação sem estoque.']);
            }

            $preco   = (float)($sku['preco_promo'] ?: $sku['preco']);
            $estoque = (int)$sku['estoque'];
        } else {
            // Produto sem variação
            $stmt = $db->prepare(
                "SELECT preco, preco_promo FROM produtos WHERE id = ? LIMIT 1"
            );
            $stmt->execute([$produtoId]);
            $p = $stmt->fetch();
            $preco   = (float)($p['preco_promo'] ?: $p['preco']);
            $estoque = (int)$produto['estoque_total'];
        }

        if ($estoque <= 0) {
            $this->json(['ok' => false, 'msg' => 'Produto sem estoque.']);
        }

        $carrinhoId = $this->getCarrinhoId(true);

        // Verifica se já existe no carrinho (mesmo produto + mesmo SKU)
        $stmt = $db->prepare(
            "SELECT id, quantidade FROM carrinho_itens
            WHERE carrinho_id = ?
            AND produto_id  = ?
            AND (sku_id = ? OR (sku_id IS NULL AND ? IS NULL))
            LIMIT 1"
        );
        $skuIdParam = $skuId ?: null;
        $stmt->execute([$carrinhoId, $produtoId, $skuIdParam, $skuIdParam]);
        $itemExistente = $stmt->fetch();

        if ($itemExistente) {
            $novaQty = min($itemExistente['quantidade'] + $quantidade, $estoque);
            $db->prepare(
                "UPDATE carrinho_itens
                SET quantidade = ?, subtotal = ? * preco_unitario
                WHERE id = ?"
            )->execute([$novaQty, $novaQty, $itemExistente['id']]);
        } else {
            $db->prepare(
                "INSERT INTO carrinho_itens
                (carrinho_id, produto_id, sku_id, quantidade, preco_unitario, subtotal)
                VALUES (?, ?, ?, ?, ?, ?)"
            )->execute([
                $carrinhoId,
                $produtoId,
                $skuIdParam,
                $quantidade,
                $preco,
                $quantidade * $preco,
            ]);
        }

        // Atualiza contagem na sessão
        $stmt = $db->prepare(
            "SELECT COALESCE(SUM(quantidade), 0)
            FROM carrinho_itens WHERE carrinho_id = ?"
        );
        $stmt->execute([$carrinhoId]);
        $count = (int)$stmt->fetchColumn();
        Session::set('carrinho_count', $count);

        $this->json([
            'ok'    => true,
            'msg'   => 'Produto adicionado ao carrinho!',
            'count' => $count,
        ]);
    }

    // ── Remover item ──────────────────────────────────────────

    public function remove(): void {
        $this->verifyCsrf();

        $itemId   = SecurityHelper::sanitizeInt($_POST['item_id'] ?? 0);
        $carrinho = $this->cartModel->getOrCreate();

        if ($itemId <= 0) {
            $this->json(['ok' => false, 'msg' => 'Item inválido.']);
        }

        $this->cartModel->removeItem($itemId, (int)$carrinho['id']);

        $count  = $this->cartModel->countItems((int)$carrinho['id']);
        $totals = $this->cartModel->getTotals((int)$carrinho['id']);

        Session::setCarrinhoCount($count);

        $this->json([
            'ok'           => true,
            'cart_count'   => $count,
            'subtotal_fmt' => $totals['subtotal_fmt'],
            'total_fmt'    => $totals['total_fmt'],
            'desconto_fmt' => $totals['desconto_fmt'],
        ]);
    }

    // ── Atualizar quantidade ──────────────────────────────────

    public function update(): void {
        $this->verifyCsrf();

        $itemId   = SecurityHelper::sanitizeInt($_POST['item_id'] ?? 0);
        $qty      = SecurityHelper::sanitizeInt($_POST['quantidade'] ?? 1);
        $carrinho = $this->cartModel->getOrCreate();

        try {
            $this->cartModel->updateItemQty($itemId, (int)$carrinho['id'], $qty);

            $count  = $this->cartModel->countItems((int)$carrinho['id']);
            // $totals = $this->cartModel->getTotals((int)$carrinho['id']);

            // $parcelas      = PriceHelper::installments((float)($totals['total'] ?? 0));
            // $ultimaParcela = end($parcelas);

            Session::setCarrinhoCount($count);

            // $this->json([
            //     'ok'              => true,
            //     'count'           => $count,
            //     'subtotal_fmt'    => $totals['subtotal_fmt'],
            //     'frete'           => (float)($totals['frete'] ?? 0),
            //     'frete_fmt'       => $totals['frete_fmt'],
            //     'frete_servico'   => $totals['frete_servico'] ?? null,
            //     'total'           => (float)($totals['total'] ?? 0),
            //     'desconto_fmt'    => $totals['desconto_fmt'],
            //     'total_fmt'       => $totals['total_fmt'],
            //     'melhor_parcela'  => ($ultimaParcela && ($ultimaParcela['parcelas'] ?? 0) > 1)
            //                     ? $ultimaParcela['label'] : null,
            // ]);

            $this->mini(); // Retorna dados atualizados do mini-cart
            
        } catch (RuntimeException $e) {
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }

    

    // ── Aplicar cupom ─────────────────────────────────────────

    public function applyCoupon(): void {
        $this->verifyCsrf();

        $codigo   = strtoupper(trim($_POST['cupom'] ?? ''));
        $carrinho = $this->cartModel->getOrCreate();
        $clienteId = Session::getClienteId() ?? 0;

        if (empty($codigo)) {
            $this->json(['ok' => false, 'msg' => 'Informe o código do cupom.']);
        }

        $result = $this->cartModel->applyCupom((int)$carrinho['id'], $codigo, $clienteId);

        if ($result['ok']) {
            $totals = $this->cartModel->getTotals((int)$carrinho['id']);
            $result['subtotal_fmt'] = $totals['subtotal_fmt'];
            $result['desconto_fmt'] = $totals['desconto_fmt'];
            $result['frete_fmt']    = $totals['frete_fmt'];
            $result['total_fmt']    = $totals['total_fmt'];
        }

        $this->json($result);
    }

    // ── Remover cupom ─────────────────────────────────────────

    public function removeCoupon(): void {
        $this->verifyCsrf();
        $carrinho = $this->cartModel->getOrCreate();
        $this->cartModel->removeCupom((int)$carrinho['id']);

        $totals = $this->cartModel->getTotals((int)$carrinho['id']);
        $this->json([
            'ok'           => true,
            'subtotal_fmt' => $totals['subtotal_fmt'],
            'frete_fmt'    => $totals['frete_fmt'],
            'desconto_fmt' => null,
            'total_fmt'    => $totals['total_fmt'],
        ]);
    }

    // ── Calcular frete ────────────────────────────────────────

    public function calcShipping(): void {
        $cep      = preg_replace('/\D/', '', $_POST['cep'] ?? $_GET['cep'] ?? '');
        $productId = SecurityHelper::sanitizeInt($_GET['produto_id'] ?? 0);

        if (strlen($cep) !== 8) {
            $this->json(['ok' => false, 'msg' => 'CEP inválido.']);
        }

        $carrinho   = $this->cartModel->getOrCreate();
        $totals     = $this->cartModel->getTotals((int)$carrinho['id']);
        $shippingSvc = new ShippingService();

        // Agrega dimensões e peso dos itens
        $pesoTotal = 0;
        foreach ($totals['items'] as $item) {
            $product = (new Product())->find($item['produto_id']);
            $pesoTotal += (float)($product['peso_kg'] ?? 0.5) * $item['quantidade'];
        }

        $result = $shippingSvc->calculate($cep, [
            'peso_kg'         => max(0.3, $pesoTotal),
            'valor_carrinho'  => $totals['subtotal'],
            'produto_id'      => $productId,
        ]);

        $this->json($result);
    }

    // ── Selecionar frete ──────────────────────────────────────

    public function selectShipping(): void {
        $this->verifyCsrf();

        $carrinho = $this->cartModel->getOrCreate();
        $frete = [
            'servico' => SecurityHelper::sanitizeString($_POST['servico'] ?? ''),
            'valor'   => SecurityHelper::sanitizeFloat( $_POST['valor']   ?? 0),
            'prazo'   => SecurityHelper::sanitizeInt(   $_POST['prazo']   ?? 0),
            'cep'     => $_POST['cep'] ?? '',
        ];

        $this->cartModel->saveShipping((int)$carrinho['id'], $frete);

        $totals = $this->cartModel->getTotals((int)$carrinho['id']);
        $this->json([
            'ok'           => true,
            'frete_fmt'    => $totals['frete_fmt'],
            'total_fmt'    => $totals['total_fmt'],
            'desconto_fmt' => $totals['desconto_fmt'],
        ]);
    }

    // ── Mini-cart (Ajax drawer) ───────────────────────────────

    public function mini(): void {
        $carrinhoId = $this->getCarrinhoId();

        if (!$carrinhoId) {
            $this->json([
                'ok'              => true,
                'items'           => [],
                'count'           => 0,
                'subtotal_fmt'    => 'R$ 0,00',
                'total_fmt'       => 'R$ 0,00',
                'desconto'        => 0,
                'frete'           => 0,
                'vendedor_codigo' => null,
                'vendedor_nome'   => null,
                'cupom_codigo'    => null
            ]);
        }

        $db        = Database::getInstance()->getConnection();
        $cartModel = new Cart();
        $items     = $cartModel->getItems($carrinhoId);
        $totals    = $cartModel->getTotals($carrinhoId);
        $status_aberto = ['aberto','abandonado','expirado'];
        $placeholdersStatus = implode(',', array_fill(0, count($status_aberto), '?'));

        // Busca dados do carrinho com nome do vendedor via usuarios.nome
        $stmt = $db->prepare(
            "SELECT c.codigo_vendedor,
                    u.nome AS vendedor_nome
            FROM carrinhos c
            LEFT JOIN vendedores v ON v.codigo  = c.codigo_vendedor
                                    AND v.ativo   = 1
            LEFT JOIN usuarios u   ON u.id       = v.usuario_id
            WHERE c.id = ? AND c.status IN (".$placeholdersStatus.")
            LIMIT 1"
        );
        $params = array_merge([$carrinhoId], $status_aberto);
        $stmt->execute($params);
        $cart = $stmt->fetch();

        if(!$cart){
            $this->json([
                'ok'              => true,
                'items'           => [],
                'count'           => 0,
                'subtotal_fmt'    => 'R$ 0,00',
                'total_fmt'       => 'R$ 0,00',
                'desconto'        => 0,
                'frete'           => 0,
                'vendedor_codigo' => null,
                'vendedor_nome'   => null,
                'cupom_codigo'    => null,
            ]);
        }

        // Reabre o carrinho
        $this->cartModel->reOpenCart($carrinhoId);

        // Nome do vendedor: usuarios.nome se existir, senão o próprio código
        $vendedorNome = $cart['vendedor_nome']
                    ?: ($cart['codigo_vendedor'] ?: null);

        
        $itemsFormatted = array_map(function ($item) {
            $nomeItem = $item['nome_produto'] ?? $item['nome'] ?? 'Produto';

            return [
                'id'           => (int)$item['id'],
                'produto_id'   => (int)$item['produto_id'],
                'sku_id'       => $item['sku_id'] ? (int)$item['sku_id'] : null,
                'nome'         => $nomeItem,
                'slug'         => $item['produto_slug'] ?? '',
                'imagem' => ImageHelper::getCartItemImage(
                    (int)$item['produto_id'],
                    $item['sku_id'] ? (int)$item['sku_id'] : null
                ),
                'quantidade'   => (int)$item['quantidade'],
                'estoque'      => (int)$item['estoque_total'],
                'preco_fmt'    => PriceHelper::format((float)($item['preco_unitario'] ?? 0)),
                'subtotal_fmt' => PriceHelper::format((float)($item['subtotal']       ?? 0)),
                'atributos'    => $item['atributos'] ?? [],
            ];
        }, $items);

        $parcelas      = PriceHelper::installments((float)($totals['total'] ?? 0));
        $ultimaParcela = end($parcelas);

        $this->json([
            'ok'              => true,
            'items'           => $itemsFormatted,
            // 'count'           => count($items),
            'count'           => Session::getCarrinhoCount(),
            'subtotal_fmt'    => PriceHelper::format((float)($totals['subtotal'] ?? 0)),
            'desconto'        => (float)($totals['desconto'] ?? 0),
            'desconto_fmt'    => PriceHelper::format((float)($totals['desconto'] ?? 0)),
            'frete'           => (float)($totals['frete'] ?? 0),
            'frete_fmt'       => PriceHelper::format((float)($totals['frete'] ?? 0)),
            'frete_servico'   => $totals['frete_servico'] ?? null,
            'total'           => (float)($totals['total'] ?? 0),
            'total_fmt'       => PriceHelper::format((float)($totals['total'] ?? 0)),
            'cupom_codigo'    => $cart['cupom_codigo']  ?? null,
            'vendedor_codigo' => $cart['codigo_vendedor'] ?? null,
            'vendedor_nome'   => $vendedorNome,
            'melhor_parcela'  => ($ultimaParcela && ($ultimaParcela['parcelas'] ?? 0) > 1)
                                ? $ultimaParcela['label'] : null,
                                'tete'=>$items
        ]);
    }

    // ── Compartilhar carrinho ─────────────────────────────────

        /**
     * Gera o link de compartilhamento (chamado via Ajax POST).
     */
    public function share(): void {
        $this->verifyCsrf();

        $carrinhoId = $this->getCarrinhoId();
        if (!$carrinhoId) {
            $this->json(['ok' => false, 'msg' => 'Carrinho vazio.']);
        }

        $db        = Database::getInstance()->getConnection();
        $cartModel = new Cart();

        // Busca itens e totais atuais
        $itens  = $cartModel->getItems($carrinhoId);
        $totals = $cartModel->getTotals($carrinhoId);

        if (empty($itens)) {
            $this->json(['ok' => false, 'msg' => 'Carrinho vazio.']);
        }

        // Busca vendedor atual do carrinho
        $stmtCart = $db->prepare(
            "SELECT c.codigo_vendedor, u.nome AS vendedor_nome
            FROM carrinhos c
            LEFT JOIN vendedores v ON v.codigo = c.codigo_vendedor AND v.ativo = 1
            LEFT JOIN usuarios u   ON u.id     = v.usuario_id
            WHERE c.id = ? LIMIT 1"
        );
        $stmtCart->execute([$carrinhoId]);
        $cartData = $stmtCart->fetch();

        // Monta snapshot dos itens
        $snapshot = array_map(function ($item) {
            return [
                'produto_id'    => $item['produto_id'],
                'nome'          => $item['nome_produto'] ?? $item['nome'] ?? 'Produto',
                'slug'          => $item['produto_slug'] ?? $item['slug'] ?? '',
                'imagem'        => $item['imagem'] ?? null,
                'quantidade'    => (int)$item['quantidade'],
                'preco'         => (float)($item['preco_unitario'] ?? $item['preco'] ?? 0),
                'subtotal'      => (float)($item['subtotal'] ?? 0),
                'opcoes'        => !empty($item['opcoes_snapshot'])
                                ? json_decode($item['opcoes_snapshot'], true)
                                : null,
                'sku'           => $item['sku_legado'] ?? null,
            ];
        }, $itens);

        // Identificação de quem compartilha
        $usuarioId    = null;
        $nomeVisitante = null;

        if (Session::isClienteLogado()) {
            $usuarioId = (int) Session::get('usuario_id');
        } else {
            $nomeVisitante = SecurityHelper::sanitizeString($_POST['nome'] ?? '') ?: null;
        }

        // Vendedor
        $vendedorCodigo = $cartData['codigo_vendedor'] ?? null;
        $vendedorNome   = $cartData['vendedor_nome']
                    ?: ($vendedorCodigo ?: null);

        $token    = SecurityHelper::generateToken(24);
        $expiraEm = date('Y-m-d H:i:s', time() + (86400 * 7)); // 7 dias

        $db->prepare(
            "INSERT INTO carrinhos_compartilhados
            (token, carrinho_id, itens_snapshot, subtotal, desconto, total,
            vendedor_codigo, vendedor_nome, usuario_id, nome_visitante, expira_em)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)"
        )->execute([
            $token,
            $carrinhoId,
            json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            (float)($totals['subtotal'] ?? 0),
            (float)($totals['desconto'] ?? 0),
            (float)($totals['total']    ?? 0),
            $vendedorCodigo,
            $vendedorNome,
            $usuarioId,
            $nomeVisitante,
            $expiraEm,
        ]);

        $url = rtrim(BASE_URL, '/') . '/carrinho/compartilhado/' . $token;

        $this->json([
            'ok'       => true,
            'url'      => $url,
            'expira_em'=> date('d/m/Y', strtotime($expiraEm)),
        ]);
    }


    /**
     * Exibe o carrinho compartilhado (acessado pelo link GET).
     */
    public function viewShared(string $token): void {
        $token = preg_replace('/[^a-f0-9]/', '', $token);

        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare(
            "SELECT cc.*,
                    u.nome AS usuario_nome
            FROM carrinhos_compartilhados cc
            LEFT JOIN usuarios u ON u.id = cc.usuario_id
            WHERE cc.token = ?
            LIMIT 1"
        );
        $stmt->execute([$token]);
        $compartilhado = $stmt->fetch();

        // Não encontrado
        if (!$compartilhado) {
            $this->render('cart/shared-expired', [], 'main');
            return;
        }

        // Expirado
        if (strtotime($compartilhado['expira_em']) < time()) {
            $this->render('cart/shared-expired', [], 'main');
            return;
        }

        // Incrementa visualizações
        $db->prepare(
            "UPDATE carrinhos_compartilhados
            SET visualizacoes = visualizacoes + 1
            WHERE token = ?"
        )->execute([$token]);
 
        // Registra no log de uso (deduplica por sessão/hora)
        (new CartCompartilhado())->registrarUso(
            $token,
            'visualizou',
            Session::isClienteLogado() ? (int)Session::getClienteId() : null,
            null,
            $_SERVER['REMOTE_ADDR'] ?? null
        );

        // Resolve quem compartilhou
        $compartilhadoPor = $compartilhado['usuario_nome']
                        ?? $compartilhado['nome_visitante']
                        ?? null;

        // Itens do snapshot
        $itens = json_decode($compartilhado['itens_snapshot'], true) ?? [];

        // Copia para o carrinho se solicitado
        $copiado = false;
        if (!empty($_GET['copiar'])) {
            $copiado = $this->copiarDoSnapshot($itens, $compartilhado['vendedor_codigo']);
        }

        SeoHelper::setTitle('Carrinho compartilhado');
        SeoHelper::setRobots('noindex, nofollow');

        $this->render('cart/shared', [
            'compartilhado'    => $compartilhado,
            'compartilhado_por'=> $compartilhadoPor,
            'vendedor_nome'    => $compartilhado['vendedor_nome'],
            'itens'            => $itens,
            'subtotal'         => $compartilhado['subtotal'],
            'desconto'         => $compartilhado['desconto'],
            'total'            => $compartilhado['total'],
            'token'            => $token,
            'copiado'          => $copiado,
            'expira_em'        => $compartilhado['expira_em'],
            'extra_js'         => [BASE_URL . '/assets/js/shared-cart.js']
        ]);
    }

    public function copyShared(): void {
        $this->verifyCsrf();

        $token  = preg_replace('/[^a-f0-9]/', '', $_POST['token'] ?? '');
        $modo   = $_POST['modo'] ?? 'adicionar'; // 'adicionar' ou 'substituir'

        if (empty($token)) {
            $this->json(['ok' => false, 'msg' => 'Token inválido.']);
        }

        $db   = Database::getInstance()->getConnection();

        // Busca o compartilhamento
        $stmt = $db->prepare(
            "SELECT * FROM carrinhos_compartilhados
            WHERE token = ? AND expira_em > NOW()
            LIMIT 1"
        );
        $stmt->execute([$token]);
        $compartilhado = $stmt->fetch();

        if (!$compartilhado) {
            $this->json(['ok' => false, 'msg' => 'Link expirado ou inválido.']);
        }

        $itens          = json_decode($compartilhado['itens_snapshot'], true) ?? [];
        $vendedorCodigo = $compartilhado['vendedor_codigo'];

        if (empty($itens)) {
            $this->json(['ok' => false, 'msg' => 'Carrinho compartilhado vazio.']);
        }

        // Obtém ou cria o carrinho atual
        $carrinhoId = $this->getCarrinhoId(true);

        // Verifica se já tem itens no carrinho atual
        $stmtCheck = $db->prepare(
            "SELECT COUNT(*) FROM carrinho_itens WHERE carrinho_id = ?"
        );
        $stmtCheck->execute([$carrinhoId]);
        $temItens = (int)$stmtCheck->fetchColumn() > 0;

        // Se tem itens e não informou o modo, pede confirmação
        if ($temItens && !in_array($modo, ['adicionar', 'substituir'])) {
            $this->json([
                'ok'        => false,
                'conflito'  => true,
                'msg'       => 'Você já tem itens no carrinho.',
            ]);
        }

        // Modo substituir: esvazia o carrinho atual primeiro
        if ($modo === 'substituir') {
            $db->prepare(
                "DELETE FROM carrinho_itens WHERE carrinho_id = ?"
            )->execute([$carrinhoId]);
        }

        // Copia os itens do snapshot
        $adicionados = 0;
        $ignorados   = 0;

        foreach ($itens as $item) {
            if (empty($item['produto_id'])) continue;

            // Verifica se o produto ainda está ativo
            $stmtProd = $db->prepare(
                "SELECT id, preco, preco_promo, estoque_total
                FROM produtos
                WHERE id = ? AND ativo = 1 AND deleted_at IS NULL
                LIMIT 1"
            );
            $stmtProd->execute([$item['produto_id']]);
            $produto = $stmtProd->fetch();

            if (!$produto) { $ignorados++; continue; }

            // Usa preço atual
            $preco = (float)($produto['preco_promo'] ?: $produto['preco']);
            $qty   = min((int)$item['quantidade'], (int)$produto['estoque_total']);

            if ($qty <= 0) { $ignorados++; continue; }

            // Verifica se já existe no carrinho (no modo adicionar)
            $stmtExiste = $db->prepare(
                "SELECT id, quantidade FROM carrinho_itens
                WHERE carrinho_id = ? AND produto_id = ?
                LIMIT 1"
            );
            $stmtExiste->execute([$carrinhoId, $item['produto_id']]);
            $existe = $stmtExiste->fetch();

            if ($existe) {
                $novaQty = min(
                    $existe['quantidade'] + $qty,
                    (int)$produto['estoque_total']
                );
                $db->prepare(
                    "UPDATE carrinho_itens
                    SET quantidade = ?
                    WHERE id = ?"
                )->execute([$novaQty, $existe['id']]);

            } else {
                // Verifica qual coluna existe na sua tabela
                // Tente primeiro sem subtotal — só produto_id, quantidade e preco
                $db->prepare(
                    "INSERT INTO carrinho_itens
                    (carrinho_id, produto_id, quantidade, preco_unitario)
                    VALUES (?, ?, ?, ?)"
                )->execute([
                    $carrinhoId,
                    $item['produto_id'],
                    $qty,
                    $preco,
                ]);
            }

            $adicionados++;
        }

        // Aplica vendedor se tiver
        if ($vendedorCodigo) {
            $db->prepare(
                "UPDATE carrinhos SET codigo_vendedor = ? WHERE id = ?"
            )->execute([$vendedorCodigo, $carrinhoId]);
        }
 
        // Víncula o token ao carrinho para rastrear pedidos futuros
        $db->prepare(
            "UPDATE carrinhos
             SET compartilhado_token = COALESCE(compartilhado_token, ?)
             WHERE id = ?"
        )->execute([$token, $carrinhoId]);
 
        // Registra criação de carrinho no log
        (new CartCompartilhado())->registrarUso(
            $token,
            'criou_carrinho',
            Session::isClienteLogado() ? (int)Session::getClienteId() : null,
            null,
            $_SERVER['REMOTE_ADDR'] ?? null
        );

        // Atualiza contagem na sessão
        $stmtCount = $db->prepare(
            "SELECT COALESCE(SUM(quantidade), 0) FROM carrinho_itens WHERE carrinho_id = ?"
        );
        $stmtCount->execute([$carrinhoId]);
        $novaContagem = (int)$stmtCount->fetchColumn();
        Session::set('carrinho_count', $novaContagem);

        $msg = $adicionados . ' produto' . ($adicionados !== 1 ? 's' : '') . ' adicionado'
            . ($adicionados !== 1 ? 's' : '') . ' ao seu carrinho!';

        if ($ignorados > 0) {
            $msg .= ' (' . $ignorados . ' produto' . ($ignorados !== 1 ? 's' : '')
                . ' indisponível' . ($ignorados !== 1 ? 'eis' : '') . ')';
        }

        $this->json([
            'ok'       => true,
            'msg'      => $msg,
            'count'    => $novaContagem,
            'redirect' => BASE_URL . '/carrinho',
        ]);
    }

    /**
     * Copia itens do snapshot para o carrinho atual.
     */
    private function copiarDoSnapshot(array $itens, ?string $vendedorCodigo): bool {
        try {
            $carrinhoId = $this->getCarrinhoId(true);
            $db         = Database::getInstance()->getConnection();

            foreach ($itens as $item) {
                if (empty($item['produto_id'])) continue;

                // Verifica se o produto ainda existe e está ativo
                $stmt = $db->prepare(
                    "SELECT id, preco, preco_promo, estoque_total
                    FROM produtos
                    WHERE id = ? AND ativo = 1 AND deleted_at IS NULL
                    LIMIT 1"
                );
                $stmt->execute([$item['produto_id']]);
                $produto = $stmt->fetch();

                if (!$produto) continue;

                // Usa o preço atual, não o do snapshot
                $preco = (float)($produto['preco_promo'] ?: $produto['preco']);
                $qty   = min((int)$item['quantidade'], (int)$produto['estoque_total']);

                if ($qty <= 0) continue;

                // Verifica se já existe no carrinho
                $stmtExiste = $db->prepare(
                    "SELECT id, quantidade FROM carrinho_itens
                    WHERE carrinho_id = ? AND produto_id = ?
                    LIMIT 1"
                );
                $stmtExiste->execute([$carrinhoId, $item['produto_id']]);
                $existe = $stmtExiste->fetch();

                if ($existe) {
                    $db->prepare(
                        "UPDATE carrinho_itens
                        SET quantidade = quantidade + ?, subtotal = (quantidade + ?) * preco_unitario
                        WHERE id = ?"
                    )->execute([$qty, $qty, $existe['id']]);
                } else {
                    $db->prepare(
                        "INSERT INTO carrinho_itens
                        (carrinho_id, produto_id, quantidade, preco_unitario, subtotal)
                        VALUES (?, ?, ?, ?, ?)"
                    )->execute([
                        $carrinhoId,
                        $item['produto_id'],
                        $qty,
                        $preco,
                        $qty * $preco,
                    ]);
                }
            }

            // Aplica o vendedor se tiver
            if ($vendedorCodigo) {
                $db->prepare(
                    "UPDATE carrinhos SET codigo_vendedor = ? WHERE id = ?"
                )->execute([$vendedorCodigo, $carrinhoId]);
            }

            // Atualiza contagem na sessão
            $stmtCount = $db->prepare(
                "SELECT SUM(quantidade) FROM carrinho_itens WHERE carrinho_id = ?"
            );
            $stmtCount->execute([$carrinhoId]);
            Session::set('carrinho_count', (int)$stmtCount->fetchColumn());

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Copia todos os itens do carrinho compartilhado para o carrinho atual.
     */
    private function copiarCarrinho(int $carrinhoOrigemId): bool {
        try {
            $cartModel  = new Cart();
            $meuCarrinho = $this->getCarrinhoId(true); // cria se não existir
            $itens       = (new Cart())->getItems($carrinhoOrigemId);

            foreach ($itens as $item) {
                $cartModel->addItem($meuCarrinho, [
                    'produto_id'  => $item['produto_id'],
                    'quantidade'  => $item['quantidade'],
                    'preco'       => $item['preco_unitario'],
                    'opcoes'      => $item['opcoes_snapshot'] ?? null,
                ]);
            }

            Session::set('carrinho_count',
                $cartModel->countItems($meuCarrinho)
            );

            return true;
        } catch (Exception) {
            return false;
        }
    }

    // ── Aplicar código de vendedor ────────────────────────────

    public function applyVendor(): void {
        $this->verifyCsrf();
        $codigo   = SecurityHelper::sanitizeString($_POST['codigo_vendedor'] ?? $_POST['codigo'] ?? '');
        $carrinho = $this->cartModel->getOrCreate();

        $result = $this->cartModel->applyVendedor((int)$carrinho['id'], $codigo);
        $this->json($result);
    }

    public function applyVendedor(): void {
        $this->verifyCsrf();

        $codigo     = strtoupper(SecurityHelper::sanitizeString($_POST['codigo'] ?? ''));
        $carrinhoId = $this->getCarrinhoId();

        if (empty($codigo)) {
            $this->json(['ok' => false, 'msg' => 'Informe o código do vendedor.']);
        }
        if (!$carrinhoId) {
            $this->json(['ok' => false, 'msg' => 'Carrinho não encontrado.']);
        }

        $db = Database::getInstance()->getConnection();

        // Valida pelo campo correto: vendedores.codigo
        // Busca o nome em usuarios.nome via usuario_id
        $stmt = $db->prepare(
            "SELECT v.id, v.codigo, v.usuario_id,
                    u.nome AS vendedor_nome
            FROM vendedores v
            LEFT JOIN usuarios u ON u.id = v.usuario_id
            WHERE v.codigo = ?
            AND v.ativo  = 1
            LIMIT 1"
        );
        $stmt->execute([$codigo]);
        $vendedor = $stmt->fetch();

        if (!$vendedor) {
            $this->json(['ok' => false, 'msg' => 'Código de vendedor inválido.', 'codigo' => $codigo]);
        }

        // Nome: usa usuarios.nome se vinculado, senão usa o próprio código
        $nomeExibir = $vendedor['vendedor_nome'] ?: $codigo;

        // Salva no carrinho usando o campo correto: codigo_vendedor
        $db->prepare(
            "UPDATE carrinhos
            SET codigo_vendedor = ?
            WHERE id = ?"
        )->execute([$codigo, $carrinhoId]);

        $this->json([
            'ok'            => true,
            'msg'           => 'Vendedor ' . $nomeExibir . ' aplicado!',
            'vendedor_nome' => $nomeExibir,
            'codigo'        => $codigo,
        ]);
    }

    public function removeVendedor(): void {
        $this->verifyCsrf();

        $carrinhoId = $this->getCarrinhoId();
        if (!$carrinhoId) {
            $this->json(['ok' => false, 'msg' => 'Carrinho não encontrado.']);
        }

        Database::getInstance()->getConnection()->prepare(
            "UPDATE carrinhos SET codigo_vendedor = NULL WHERE id = ?"
        )->execute([$carrinhoId]);

        $this->json(['ok' => true, 'msg' => 'Vendedor removido.']);
    }
    // Adicionar ao CartController — método auxiliar para obter o carrinho ativo

    /**
     * Retorna o ID do carrinho ativo da sessão ou do banco.
     * Se $criarSeNaoExistir = true, cria um novo carrinho.
     */
    private function getCarrinhoId(bool $criarSeNaoExistir = false): ?int {
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


    // app/controllers/CartController.php
    // Isso provavelmente não será usado, não quero fazer reserva de estoque no carrinho.
    public function reservarEstoque(int $carrinhoId): bool {
        $estoque = new EstoqueService();
        $itens   = Cart::getItems($carrinhoId);

        foreach ($itens as $item) {
            $resultado = $estoque->reservar(
                produtoId : (int)$item['produto_id'],
                quantidade: (int)$item['quantidade'],
                opcoes    : [
                    'sku_id'          => $item['sku_id'] ? (int)$item['sku_id'] : null,
                    'referencia_tipo' => 'carrinho',
                    'referencia_id'   => $carrinhoId,
                    'idempotency_key' => "reserva_carrinho_{$carrinhoId}_prod_{$item['produto_id']}",
                ]
            );

            if (!$resultado['ok']) return false;
        }
        return true;
    }
}