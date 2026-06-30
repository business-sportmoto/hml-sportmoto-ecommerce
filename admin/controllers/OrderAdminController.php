<?php
// admin/controllers/OrderAdminController.php

class OrderAdminController extends Controller {

    private PDO $db;

    public function __construct() {
        AuthHelper::requirePermission('pedidos');
        $this->db = Database::getInstance()->getConnection();
    }

    public function index(): void {
        $page   = max(1, (int)($_GET['pagina'] ?? 1));
        $busca  = SecurityHelper::sanitizeString($_GET['q']      ?? '');
        $status = SecurityHelper::sanitizeString($_GET['status'] ?? '');
        $forma  = SecurityHelper::sanitizeString($_GET['forma']  ?? '');
        $de     = $_GET['de']  ?? '';
        $ate    = $_GET['ate'] ?? '';

        $where  = "1=1";
        $params = [];

        if ($busca) {
            $where   .= " AND (p.codigo LIKE ? OR u.nome LIKE ? OR u.email LIKE ?)";
            $like     = "%{$busca}%";
            array_push($params, $like, $like, $like);
        }
        if ($status) { $where .= " AND p.status_pedido = ?";    $params[] = $status; }
        if ($forma)  { $where .= " AND p.forma_pagamento = ?";  $params[] = $forma; }
        if ($de)     { $where .= " AND DATE(p.criado_em) >= ?"; $params[] = $de; }
        if ($ate)    { $where .= " AND DATE(p.criado_em) <= ?"; $params[] = $ate; }

        $perPage = 25;
        $offset  = ($page - 1) * $perPage;

        $total = (int) $this->db->prepare(
            "SELECT COUNT(*) FROM pedidos p
             JOIN clientes c ON c.id = p.cliente_id
             JOIN usuarios u ON u.id = c.usuario_id
             WHERE {$where}"
        )->execute($params) ? $this->db->query(
            "SELECT COUNT(*) FROM pedidos p
             JOIN clientes c ON c.id = p.cliente_id
             JOIN usuarios u ON u.id = c.usuario_id
             WHERE {$where}"
        )->fetchColumn() : 0;

        // Query com bind seguro
        $stmtCount = $this->db->prepare(
            "SELECT COUNT(*) FROM pedidos p
             JOIN clientes c ON c.id = p.cliente_id
             JOIN usuarios u ON u.id = c.usuario_id
             WHERE {$where}"
        );
        $stmtCount->execute($params);
        $total = (int) $stmtCount->fetchColumn();

        $stmtList = $this->db->prepare(
            "SELECT p.*, u.nome AS cliente_nome, u.email AS cliente_email
             FROM pedidos p
             JOIN clientes c ON c.id = p.cliente_id
             JOIN usuarios u ON u.id = c.usuario_id
             WHERE {$where}
             ORDER BY p.criado_em DESC
             LIMIT ? OFFSET ?"
        );
        $stmtList->execute(array_merge($params, [$perPage, $offset]));
        $pedidos = $stmtList->fetchAll();

        $pag = new PaginationHelper($total, $page, ADMIN_URL . '/pedidos');

        $this->render('orders/index', array_merge($pag->toArray(), [
            'pedidos' => $pedidos,
            'busca'   => $busca,
            'status'  => $status,
            'forma'   => $forma,
            'de'      => $de,
            'ate'     => $ate,
        ]), 'admin');
    }

    public function detail(string $id): void {
        $pedido = $this->db->prepare(
            "SELECT p.*, u.nome AS cliente_nome, u.email AS cliente_email,
                    c.cpf, c.telefone AS cliente_tel
             FROM pedidos p
             JOIN clientes c ON c.id = p.cliente_id
             JOIN usuarios u ON u.id = c.usuario_id
             WHERE p.id = ? LIMIT 1"
        );
        $pedido->execute([$id]);
        $pedido = $pedido->fetch();

        if (!$pedido) $this->redirect(ADMIN_URL . '/pedidos');

        $itens = $this->db->prepare(
            "SELECT * FROM pedido_itens WHERE pedido_id = ?"
        );
        $itens->execute([$id]);

        $historico = $this->db->prepare(
            "SELECT h.*, u.nome AS admin_nome
             FROM pedido_status_historico h
             LEFT JOIN admins a ON a.id = h.admin_id
             LEFT JOIN usuarios u ON u.id = a.usuario_id
             WHERE h.pedido_id = ?
             ORDER BY h.criado_em ASC"
        );
        $historico->execute([$id]);

        $pagamento = $this->db->prepare(
            "SELECT * FROM pagamentos WHERE pedido_id = ? ORDER BY criado_em DESC LIMIT 1"
        );
        $pagamento->execute([$id]);

        $this->render('orders/detail', [
            'pedido'    => $pedido,
            'itens'     => $itens->fetchAll(),
            'historico' => $historico->fetchAll(),
            'pagamento' => $pagamento->fetch() ?: null,
        ], 'admin');
    }

    public function updateStatus(): void {
        $this->verifyCsrf();
        $pedidoId   = SecurityHelper::sanitizeInt(   $_POST['pedido_id']    ?? 0);
        $novoStatus = SecurityHelper::sanitizeString($_POST['status']       ?? '');
        $obs        = SecurityHelper::sanitizeString($_POST['observacao']   ?? '');
        $adminId    = Session::getAdminId();

        $pedido = $this->db->prepare("SELECT status_pedido FROM pedidos WHERE id = ? LIMIT 1");
        $pedido->execute([$pedidoId]);
        $atual = $pedido->fetch();

        if (!$atual) $this->json(['ok' => false, 'msg' => 'Pedido não encontrado.']);

        $statusValidos = ['aguardando_pagamento','pagamento_aprovado','em_separacao',
                          'enviado','entregue','cancelado','troca_devolucao'];

        if (!in_array($novoStatus, $statusValidos, true)) {
            $this->json(['ok' => false, 'msg' => 'Status inválido.']);
        }

        $this->db->prepare(
            "UPDATE pedidos SET status_pedido = ? WHERE id = ?"
        )->execute([$novoStatus, $pedidoId]);

        $this->db->prepare(
            "INSERT INTO pedido_status_historico
             (pedido_id, admin_id, status_anterior, status_novo, observacao)
             VALUES (?,?,?,?,?)"
        )->execute([$pedidoId, $adminId, $atual['status_pedido'], $novoStatus, $obs]);

        $this->json(['ok' => true, 'msg' => 'Status atualizado!', 'novo_status' => $novoStatus]);
    }

    public function updateTracking(): void {
        $this->verifyCsrf();
        $pedidoId = SecurityHelper::sanitizeInt(   $_POST['pedido_id']     ?? 0);
        $rastreio = SecurityHelper::sanitizeString($_POST['codigo_rastreio'] ?? '');

        $this->db->prepare(
            "UPDATE pedidos SET codigo_rastreio = ? WHERE id = ?"
        )->execute([$rastreio, $pedidoId]);

        $this->json(['ok' => true, 'msg' => 'Código de rastreio salvo.']);
    }
}