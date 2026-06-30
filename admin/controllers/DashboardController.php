<?php
// admin/controllers/DashboardController.php

class DashboardController extends Controller {

    public function __construct() {
        AuthHelper::requireAdmin();
    }

    public function index(): void {
        $db    = Database::getInstance()->getConnection();
        $stats = $this->getStats($db);
        $this->render('dashboard/index', [
            'stats' => $stats,
            'pedidos_nao_pagos_10d' => (function () use ($db) {
                $stmt = $db->prepare(
                    "SELECT COUNT(*)
                    FROM pedidos
                    WHERE status_pagamento IN ('pendente', 'aguardando_pagamento')
                        AND criado_em >= DATE_SUB(NOW(), INTERVAL 10 DAY)"
                );
                $stmt->execute();
                return (int) $stmt->fetchColumn();
            })(),
        ], 'admin');
    }

    public function stats(): void {
        AuthHelper::requireAdmin();
        $db    = Database::getInstance()->getConnection();
        $this->json($this->getStats($db));
    }

    private function getStats(PDO $db): array {
        // Vendas hoje
        $stmtHoje = $db->query(
            "SELECT COUNT(*) AS total_pedidos,
                    COALESCE(SUM(total),0) AS receita
             FROM pedidos
             WHERE DATE(criado_em) = CURDATE()
               AND status_pagamento = 'aprovado'"
        );
        $hoje = $stmtHoje->fetch();

        // Vendas mês atual
        $stmtMes = $db->query(
            "SELECT COUNT(*) AS total_pedidos,
                    COALESCE(SUM(total),0) AS receita
             FROM pedidos
             WHERE YEAR(criado_em) = YEAR(NOW())
               AND MONTH(criado_em) = MONTH(NOW())
               AND status_pagamento = 'aprovado'"
        );
        $mes = $stmtMes->fetch();

        // Totais gerais
        $stmtTotal = $db->query(
            "SELECT
               (SELECT COUNT(*) FROM pedidos) AS total_pedidos,
               (SELECT COUNT(*) FROM usuarios WHERE tipo='cliente' AND deleted_at IS NULL) AS total_clientes,
               (SELECT COUNT(*) FROM produtos  WHERE ativo=1 AND deleted_at IS NULL) AS total_produtos,
               (SELECT COUNT(*) FROM pedidos WHERE status_pedido='aguardando_pagamento') AS pedidos_pendentes,
               (SELECT COUNT(*) FROM produtos WHERE estoque_total <= estoque_minimo AND ativo=1) AS estoque_baixo,
               (SELECT COUNT(*) FROM avaliacoes WHERE aprovado=0) AS avaliacoes_pendentes"
        );
        $totais = $stmtTotal->fetch();

        // Pedidos recentes
        $stmtRec = $db->query(
            "SELECT p.id, p.codigo, p.total, p.status_pedido, p.status_pagamento,
                    p.criado_em, u.nome AS cliente_nome
             FROM pedidos p
             JOIN clientes c ON c.id = p.cliente_id
             JOIN usuarios u ON u.id = c.usuario_id
             ORDER BY p.criado_em DESC
             LIMIT 8"
        );
        $pedidosRecentes = $stmtRec->fetchAll();

        // Produtos mais vendidos (mês)
        $stmtTop = $db->query(
            "SELECT p.nome, p.slug, SUM(pi.quantidade) AS vendidos,
                    SUM(pi.subtotal) AS receita
             FROM pedido_itens pi
             JOIN produtos p ON p.id = pi.produto_id
             JOIN pedidos ped ON ped.id = pi.pedido_id
             WHERE MONTH(ped.criado_em) = MONTH(NOW())
               AND ped.status_pagamento = 'aprovado'
             GROUP BY p.id
             ORDER BY vendidos DESC
             LIMIT 5"
        );
        $topProdutos = $stmtTop->fetchAll();

        // Gráfico últimos 30 dias
        $stmtChart = $db->query(
            "SELECT DATE(criado_em) AS dia,
                    COUNT(*) AS pedidos,
                    COALESCE(SUM(total),0) AS receita
             FROM pedidos
             WHERE criado_em >= DATE_SUB(NOW(), INTERVAL 30 DAY)
               AND status_pagamento = 'aprovado'
             GROUP BY DATE(criado_em)
             ORDER BY dia ASC"
        );
        $chartData = $stmtChart->fetchAll();

        return compact(
            'hoje', 'mes', 'totais',
            'pedidosRecentes', 'topProdutos', 'chartData'
        );
    }
}