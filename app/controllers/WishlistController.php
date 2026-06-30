<?php
class WishlistController extends Controller {

    private Customer         $customerModel;

    public function __construct() {
        // AuthHelper::requireCustomer();
        $this->customerModel    = new Customer();
    }

    private function requireLogin(): void {
        if (!Session::isClienteLogado()) {
            $this->json([
                'ok'          => false,
                'nao_logado'  => true,
                'msg'         => 'Faça login para usar sua lista de favoritos.',
                'redirect'    => BASE_URL . '/login',
            ]);
        }
    }

    private function clienteId(): int {
        return (int)Session::get('cliente_id');
    }

    // ── Retorna todas as listas do cliente ────────────────
    public function minhasListas(): void {
        $this->requireLogin();

        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare(
            "SELECT w.id, w.nome, w.publica, w.descricao, w.criado_em,
                    COUNT(wi.id)          AS total_itens,
                    MAX(pi.arquivo)       AS imagem_capa
             FROM wishlist w
             LEFT JOIN wishlist_itens wi ON wi.wishlist_id = w.id
             LEFT JOIN produto_imagens pi
                    ON pi.produto_id = wi.produto_id AND pi.principal = 1
             WHERE w.cliente_id = ?
             GROUP BY w.id
             ORDER BY w.criado_em DESC"
        );
        $stmt->execute([$this->clienteId()]);
        $listas = $stmt->fetchAll();

        foreach ($listas as &$l) {
            $l['imagem_capa'] = $l['imagem_capa']
                ? UPLOAD_URL . '/products/' . $l['imagem_capa']
                : null;
        }
        unset($l);

        $this->json(['ok' => true, 'listas' => $listas]);
    }

    // ── Retorna itens de uma lista ────────────────────────
    public function itens(): void {
        $this->requireLogin();

        $listaId = SecurityHelper::sanitizeInt($_GET['lista_id'] ?? 0);
        if (!$listaId) $this->json(['ok' => false, 'msg' => 'Lista inválida.']);

        $db   = Database::getInstance()->getConnection();

        // Confirma que pertence ao cliente
        $stmt = $db->prepare(
            "SELECT id, nome FROM wishlist
             WHERE id = ? AND cliente_id = ? LIMIT 1"
        );
        $stmt->execute([$listaId, $this->clienteId()]);
        $lista = $stmt->fetch();

        if (!$lista) {
            $this->json(['ok' => false, 'msg' => 'Lista não encontrada.']);
        }

        $stmt = $db->prepare(
            "SELECT wi.id AS item_id, wi.produto_id, wi.adicionado_em,
                    p.nome, p.slug, p.preco, p.preco_promo, p.estoque_total,
                    pi.arquivo AS imagem
             FROM wishlist_itens wi
             JOIN produtos p       ON p.id  = wi.produto_id
             LEFT JOIN produto_imagens pi
                    ON pi.produto_id = p.id AND pi.principal = 1
             WHERE wi.wishlist_id = ?
             ORDER BY wi.adicionado_em DESC"
        );
        $stmt->execute([$listaId]);
        $itens = $stmt->fetchAll();

        foreach ($itens as &$item) {
            $preco = (float)($item['preco_promo'] ?: $item['preco']);
            $item['preco_fmt']  = PriceHelper::format($preco);
            $item['imagem_url'] = $item['imagem']
                ? UPLOAD_URL . '/products/' . $item['imagem']
                : BASE_URL . '/assets/images/placeholder.jpg';
        }
        unset($item);

        $this->json(['ok' => true, 'lista' => $lista, 'itens' => $itens]);
    }

    // ── Cria nova lista ───────────────────────────────────
    public function criar(): void {
        $this->requireLogin();
        $this->verifyCsrf();

        $nome     = SecurityHelper::sanitizeString($_POST['nome'] ?? '');
        $publica  = !empty($_POST['publica']) ? 1 : 0;
        $descricao = SecurityHelper::sanitizeString($_POST['descricao'] ?? '');

        if (empty($nome)) {
            $this->json(['ok' => false, 'msg' => 'Informe um nome para a lista.']);
        }

        $db   = Database::getInstance()->getConnection();

        // Limite de listas por cliente
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM wishlist WHERE cliente_id = ?"
        );
        $stmt->execute([$this->clienteId()]);
        if ((int)$stmt->fetchColumn() >= 20) {
            $this->json(['ok' => false, 'msg' => 'Limite de 20 listas atingido.']);
        }

        $db->prepare(
            "INSERT INTO wishlist (cliente_id, nome, publica, descricao)
             VALUES (?, ?, ?, ?)"
        )->execute([$this->clienteId(), $nome, $publica, $descricao ?: null]);

        $listaId = (int)$db->lastInsertId();

        $this->json([
            'ok'      => true,
            'msg'     => 'Lista criada!',
            'lista_id'=> $listaId,
            'nome'    => $nome,
        ]);
    }

    // ── Edita nome/visibilidade da lista ──────────────────
    public function editar(): void {
        $this->requireLogin();
        $this->verifyCsrf();

        $listaId  = SecurityHelper::sanitizeInt($_POST['lista_id'] ?? 0);
        $nome     = SecurityHelper::sanitizeString($_POST['nome']     ?? '');
        $publica  = !empty($_POST['publica']) ? 1 : 0;
        $descricao = SecurityHelper::sanitizeString($_POST['descricao'] ?? '');

        if (!$listaId || empty($nome)) {
            $this->json(['ok' => false, 'msg' => 'Dados inválidos.']);
        }

        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare(
            "UPDATE wishlist SET nome = ?, publica = ?, descricao = ?
             WHERE id = ? AND cliente_id = ?"
        );
        $stmt->execute([
            $nome, $publica, $descricao ?: null,
            $listaId, $this->clienteId(),
        ]);

        if ($stmt->rowCount() === 0) {
            $this->json(['ok' => false, 'msg' => 'Lista não encontrada.']);
        }

        $this->json(['ok' => true, 'msg' => 'Lista atualizada!', 'nome' => $nome, 'descricao' => $descricao, 'publica' => $publica]);
    }

    // ── Exclui lista ──────────────────────────────────────
    public function excluir(): void {
        $this->requireLogin();
        $this->verifyCsrf();

        $listaId = SecurityHelper::sanitizeInt($_POST['lista_id'] ?? 0);
        if (!$listaId) $this->json(['ok' => false, 'msg' => 'Lista inválida.']);

        $db = Database::getInstance()->getConnection();
        $db->prepare(
            "DELETE FROM wishlist WHERE id = ? AND cliente_id = ?"
        )->execute([$listaId, $this->clienteId()]);

        $this->json(['ok' => true, 'msg' => 'Lista excluída.']);
    }

    // ── Adiciona produto à lista ──────────────────────────
    public function adicionarItem(): void {
        $this->requireLogin();
        $this->verifyCsrf();

        $listaId   = SecurityHelper::sanitizeInt($_POST['lista_id']   ?? 0);
        $produtoId = SecurityHelper::sanitizeInt($_POST['produto_id'] ?? 0);

        if (!$listaId || !$produtoId) {
            $this->json(['ok' => false, 'msg' => 'Dados inválidos.']);
        }

        $db   = Database::getInstance()->getConnection();

        // Confirma que a lista pertence ao cliente
        $stmt = $db->prepare(
            "SELECT id, padrao FROM wishlist WHERE id = ? AND cliente_id = ? LIMIT 1"
        );
        $stmt->execute([$listaId, $this->clienteId()]);
        $getFetch = $stmt->fetch(PDO::FETCH_ASSOC);
        $padrao = $getFetch['padrao'] ?? 0;
        if (!$getFetch) {
            $this->json(['ok' => false, 'msg' => 'Lista não encontrada.', 'padrao' => $padrao]);
        }                

        // Verifica se já existe
        $stmt_list = $db->prepare(
            "SELECT id FROM wishlist_itens
             WHERE wishlist_id = ? AND produto_id = ? LIMIT 1"
        );
        $stmt_list->execute([$listaId, $produtoId]);
        if ($stmt_list->fetchColumn()) {
            $this->json(['ok' => false, 'msg' => 'Produto já está nesta lista.', 'padrao' => $padrao]);
        }

        $db->prepare(
            "INSERT INTO wishlist_itens (wishlist_id, produto_id, adicionado_em)
             VALUES (?, ?, NOW())"
        )->execute([$listaId, $produtoId]);

        $this->json(['ok' => true, 'msg' => 'Adicionado à lista!', 'padrao' => $padrao]);
    }

    // ── Remove produto da lista ───────────────────────────
    public function removerItem(): void {
        $this->requireLogin();
        $this->verifyCsrf();

        $itemId = SecurityHelper::sanitizeInt($_POST['item_id'] ?? 0);
        if (!$itemId) $this->json(['ok' => false, 'msg' => 'Item inválido.']);

        $db = Database::getInstance()->getConnection();
        $db->prepare(
            "DELETE wi FROM wishlist_itens wi
             JOIN wishlist w ON w.id = wi.wishlist_id
             WHERE wi.id = ? AND w.cliente_id = ?"
        )->execute([$itemId, $this->clienteId()]);

        $this->json(['ok' => true, 'msg' => 'Produto removido da lista.']);
    }

    // ── Verifica em quais listas o produto está ───────────
    public function verificarProduto(): void {
        $this->requireLogin();

        $produtoId = SecurityHelper::sanitizeInt($_GET['produto_id'] ?? 0);
        if (!$produtoId) $this->json(['ok' => false]);

        $db   = Database::getInstance()->getConnection();

        // Todas as listas do cliente
        $stmt = $db->prepare(
            "SELECT w.id, w.nome,
                    (SELECT COUNT(*) FROM wishlist_itens wi
                     WHERE wi.wishlist_id = w.id AND wi.produto_id = ?) AS tem_produto
             FROM wishlist w
             WHERE w.cliente_id = ?
             ORDER BY w.criado_em DESC"
        );
        $stmt->execute([$produtoId, $this->clienteId()]);
        $listas = $stmt->fetchAll();

        foreach ($listas as &$l) {
            $l['tem_produto'] = (bool)$l['tem_produto'];
        }
        unset($l);

        $this->json(['ok' => true, 'listas' => $listas]);
    }

    // ── View da área do cliente ───────────────────────────
    public function index(): void {
        if (!Session::isClienteLogado()) {
            $this->redirect(BASE_URL . '/login');
        }
        $perfil  = $this->customerModel->getFullProfile($this->clienteId());
        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare(
            "SELECT w.id, w.nome, w.padrao, w.publica, w.descricao, w.criado_em,
                    COUNT(wi.id) AS total_itens
            FROM wishlist w
            LEFT JOIN wishlist_itens wi ON wi.wishlist_id = w.id
            WHERE w.cliente_id = ?
            GROUP BY w.id
            ORDER BY w.padrao DESC, w.criado_em ASC"
            // -- Padrão sempre primeiro, depois por ordem de criação
        );
        $stmt->execute([$this->clienteId()]);
        $listas = $stmt->fetchAll();

        // Garante que a lista padrão existe
        if (empty(array_filter($listas, fn($l) => $l['padrao']))) {
            $wl = new Wishlist();
            $wl->getListaPadrao($this->clienteId());
            // Recarrega
            $stmt->execute([$this->clienteId()]);
            $listas = $stmt->fetchAll();
        }

        SeoHelper::setTitle('Meus Favoritos');
        $this->render('customer/wishlist', [
            'perfil'  => $perfil,
            'listas' => $listas]
        , 'customer');
    }

    // Adicionar ao WishlistController

    public function favoritar(): void {
        $this->verifyCsrf();

        if (!Session::isClienteLogado()) {
            $this->json([
                'ok'         => false,
                'nao_logado' => true,
                'msg'        => 'Faça login para favoritar produtos.',
                'redirect'   => BASE_URL . '/login',
            ]);
        }

        $produtoId = SecurityHelper::sanitizeInt($_POST['produto_id'] ?? 0);
        if (!$produtoId) {
            $this->json(['ok' => false, 'msg' => 'Produto inválido.']);
        }

        $wishlist  = new Wishlist();
        $clienteId = (int)Session::get('cliente_id');
        $adicionou = $wishlist->favoritar($clienteId, $produtoId);

        $this->json([
            'ok'        => true,
            'favoritado'=> true,
            'msg'       => $adicionou
                        ? 'Adicionado aos favoritos!'
                        : 'Já está nos favoritos.',
        ]);
    }

    public function desfavoritar(): void {
        $this->verifyCsrf();

        if (!Session::isClienteLogado()) {
            $this->json([
                'ok'         => false,
                'nao_logado' => true,
                'msg'        => 'Faça login para gerenciar favoritos.',
            ]);
        }

        $produtoId = SecurityHelper::sanitizeInt($_POST['produto_id'] ?? 0);
        if (!$produtoId) {
            $this->json(['ok' => false, 'msg' => 'Produto inválido.']);
        }

        $wishlist  = new Wishlist();
        $clienteId = (int)Session::get('cliente_id');
        $wishlist->desfavoritar($clienteId, $produtoId);

        $this->json([
            'ok'        => true,
            'favoritado'=> false,
            'msg'       => 'Removido dos favoritos.',
        ]);
    }
}