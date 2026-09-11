<?php
// admin/controllers/EstoqueController.php

class EstoqueController extends Controller {

    public function __construct() {
        // O card de estoque do formulário de produto (editor) lê saldo e
        // histórico e puxa do Bling. Ajuste manual não existe mais — o saldo
        // é do Bling (ver ajustar()). Até 11/09 era requireAdmin().
        AuthHelper::requireAdminLevel('super', 'gerente', 'editor', 'estoque');
    }

    // ── Ajuste manual: desativado (11/09/2026) ────────────
    //
    // O saldo é do Bling (decisão de 02/09/2026 — ver bling-estoque-modelo
    // no Vault). O ajuste daqui gravava estoque_saldo e estoque_log locais,
    // não chegava ao Bling, e o cron da hora seguinte sobrescrevia: ilusão de
    // ajuste. Ajuste de verdade entra pela origem, Syscar → Bling; o painel
    // espelha e oferece "Puxar do Bling" (ressincronizar()).
    //
    // As rotas seguem de pé para responder com o motivo, não com um 404.
    public function ajustar(): void {
        $this->verifyCsrf();
        $this->ajusteSoNoBling();
    }

    // ── Histórico de movimentações ────────────────────────
    public function historico(): void {
        $produtoId = SecurityHelper::sanitizeInt($_GET['produto_id'] ?? 0);
        $skuId     = SecurityHelper::sanitizeInt($_GET['sku_id']     ?? 0) ?: null;
        $page      = max(1, (int)($_GET['page'] ?? 1));
        $perPage   = 20;

        if (!$produtoId) $this->json(['ok' => false, 'msg' => 'Produto inválido.']);

        $offset = ($page - 1) * $perPage;
        $db     = Database::getInstance()->getConnection();

        $where  = "el.produto_id = ?";
        $params = [$produtoId];

        if ($skuId !== null) {
            $where   .= " AND el.sku_id = ?";
            $params[] = $skuId;
        }

        $stmtCount = $db->prepare(
            "SELECT COUNT(*) FROM estoque_log el WHERE {$where}"
        );
        $stmtCount->execute($params);
        $total = (int)$stmtCount->fetchColumn();

        // Inclui sku e atributos na query
        $stmt = $db->prepare(
            "SELECT el.*,
                    ps.sku AS sku_codigo,
                    u.nome AS usuario_nome,
                    -- Concatena atributos do SKU para exibir o nome da variação
                    (
                        SELECT GROUP_CONCAT(
                            CONCAT(at.nome, ': ', sa.valor)
                            ORDER BY at.ordenacao ASC
                            SEPARATOR ' | '
                        )
                        FROM sku_atributos sa
                        JOIN atributo_tipos at ON at.id = sa.atributo_tipo_id
                        WHERE sa.sku_id = el.sku_id
                    ) AS sku_variacao
            FROM estoque_log el
            LEFT JOIN produto_skus ps ON ps.id = el.sku_id
            LEFT JOIN usuarios u      ON u.id  = el.usuario_id
            WHERE {$where}
            ORDER BY el.criado_em DESC
            LIMIT ? OFFSET ?"
        );
        $stmt->execute(array_merge($params, [$perPage, $offset]));
        $logs = $stmt->fetchAll();

        $this->json([
            'ok'    => true,
            'logs'  => $logs,
            'total' => $total,
            'page'  => $page,
            'pages' => (int)ceil($total / $perPage),
        ]);
    }

    // ── Saldo atual ───────────────────────────────────────
    // public function saldo(): void {
    //     $produtoId = SecurityHelper::sanitizeInt($_GET['produto_id'] ?? 0);
    //     $skuId     = SecurityHelper::sanitizeInt($_GET['sku_id']     ?? 0) ?: null;

    //     if (!$produtoId) $this->json(['ok' => false]);

    //     $estoque    = new EstoqueService();
    //     $saldo      = $estoque->getSaldo($produtoId, $skuId);
    //     $disponivel = $estoque->getDisponivel($produtoId, $skuId);
    //     $reservado  = $saldo - $disponivel;

    //     $this->json([
    //         'ok'         => true,
    //         'saldo'      => $saldo,
    //         'disponivel' => $disponivel,
    //         'reservado'  => $reservado,
    //     ]);
    // }

    public function saldo(): void {
        $produtoId = SecurityHelper::sanitizeInt($_GET['produto_id'] ?? 0);
        $skuId     = SecurityHelper::sanitizeInt($_GET['sku_id']     ?? 0) ?: null;

        if (!$produtoId) $this->json(['ok' => false]);

        $db      = Database::getInstance()->getConnection();
        $estoque = new EstoqueService();

        // ── Saldo do SKU ou produto ───────────────────────────
        $saldo      = $estoque->getSaldo($produtoId, $skuId);
        $disponivel = $estoque->getDisponivel($produtoId, $skuId);
        $reservado  = $saldo - $disponivel;

        // ── Totais reais de todas as variações ────────────────
        $stmt = $db->prepare(
            "SELECT
                COALESCE(SUM(es.saldo),     0) AS total_saldo,
                COALESCE(SUM(es.reservado), 0) AS total_reservado,
                GREATEST(
                    COALESCE(SUM(es.saldo), 0) - COALESCE(SUM(es.reservado), 0),
                    0
                ) AS total_disponivel
            FROM estoque_saldo es
            JOIN produto_skus ps
                ON ps.id = es.sku_id
                AND ps.produto_id = ?
                AND ps.ativo = 1
            WHERE es.produto_id = ?
            AND es.sku_id IS NOT NULL"
        );
        $stmt->execute([$produtoId, $produtoId]);
        $totais = $stmt->fetch();

        // Se não tem SKUs (produto simples), o total é o próprio saldo do produto
        $temVariacao = !empty($totais['total_saldo']) || $totais !== false;

        $this->json([
            'ok'              => true,

            // Saldo do SKU/produto específico
            'saldo'           => $saldo,
            'disponivel'      => $disponivel,
            'reservado'       => $reservado,

            // Totais reais de todas as variações (para atualizar #pe-estoque-card)
            'total_saldo'     => (int)($totais['total_saldo']     ?? $saldo),
            'total_disponivel'=> (int)($totais['total_disponivel']?? $disponivel),
            'total_reservado' => (int)($totais['total_reservado'] ?? $reservado),
        ]);
    }

    // ── Ressincronizar com o Bling ────────────────────────
    //
    // Substitui os antigos recalcular() + sincronizar(), que derivavam o
    // saldo do estoque_log local. Com o Bling dono do estoque, as baixas
    // acontecem lá e o ledger local só guarda os espelhamentos — o número
    // recalculado pelo log era ficção, e o botão gravava essa ficção.
    //
    // A pergunta certa passou a ser "qual o saldo no Bling agora?".
    public function ressincronizar(): void {
        $this->verifyCsrf();
        // Sem guard extra: puxar do Bling só lê o Bling e grava o espelho —
        // vale para quem vê o card (construtor: sup/ger/edi/est).

        $produtoId = SecurityHelper::sanitizeInt($_POST['produto_id'] ?? 0);
        if (!$produtoId) {
            $this->json(['ok' => false, 'msg' => 'Produto inválido.']);
        }

        try {
            $this->json((new BlingEstoqueService())->sincronizarProduto($produtoId));
        } catch (\Throwable $e) {
            LogService::exception($e, 'error', 'bling', ['produto_id' => $produtoId]);
            $this->json(['ok' => false, 'msg' => 'Falha ao consultar o Bling: ' . $e->getMessage()]);
        }
    }

    // ── Listagem geral de estoque (visão admin) ───────────
    public function index(): void {
        $db      = Database::getInstance()->getConnection();
        $page    = max(1, (int)($_GET['page']  ?? 1));
        $perPage = 30;
        $offset  = ($page - 1) * $perPage;
        $search  = SecurityHelper::sanitizeString($_GET['q'] ?? '');
        $alerta  = $_GET['alerta'] ?? ''; // 'baixo' | 'zerado'

        $where  = "p.deleted_at IS NULL AND p.ativo = 1";
        $params = [];

        if ($search) {
            $where   .= " AND p.nome LIKE ?";
            $params[] = '%' . $search . '%';
        }
        if ($alerta === 'zerado') {
            $where .= " AND es.saldo = 0";
        } elseif ($alerta === 'baixo') {
            $where .= " AND es.saldo > 0 AND es.saldo <= p.estoque_minimo";
        }

        $stmtCount = $db->prepare(
            "SELECT COUNT(DISTINCT p.id)
             FROM produtos p
             LEFT JOIN estoque_saldo es
                    ON es.produto_id = p.id AND es.sku_id IS NULL
             WHERE {$where}"
        );
        $stmtCount->execute($params);
        $total = (int)$stmtCount->fetchColumn();

        $stmt = $db->prepare(
            "SELECT p.id, p.nome, p.slug, p.estoque_minimo,
                    p.tem_variacao,
                    pi.arquivo AS imagem,
                    c.nome     AS categoria_nome,
                    m.nome     AS marca_nome,
                    COALESCE(es.saldo,     0) AS saldo,
                    COALESCE(es.reservado, 0) AS reservado,
                    COALESCE(es.saldo, 0) - COALESCE(es.reservado, 0) AS disponivel,
                    (SELECT COUNT(*) FROM produto_skus ps
                     WHERE ps.produto_id = p.id AND ps.ativo = 1) AS total_skus
             FROM produtos p
             LEFT JOIN estoque_saldo es
                    ON es.produto_id = p.id AND es.sku_id IS NULL
             LEFT JOIN produto_imagens pi
                    ON pi.produto_id = p.id AND pi.principal = 1
             LEFT JOIN categorias c ON c.id = p.categoria_id
             LEFT JOIN marcas m     ON m.id = p.marca_id
             WHERE {$where}
             ORDER BY es.saldo ASC, p.nome ASC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute(array_merge($params, [$perPage, $offset]));
        $produtos = $stmt->fetchAll();

        // Totais para cards de resumo
        $stmtResumo = $db->query(
            "SELECT
                COUNT(DISTINCT p.id)                                        AS total_produtos,
                COUNT(DISTINCT CASE WHEN es.saldo = 0 THEN p.id END)        AS zerado,
                COUNT(DISTINCT CASE WHEN es.saldo > 0
                    AND es.saldo <= p.estoque_minimo THEN p.id END)          AS baixo,
                SUM(COALESCE(es.saldo, 0))                                   AS total_unidades
             FROM produtos p
             LEFT JOIN estoque_saldo es ON es.produto_id = p.id AND es.sku_id IS NULL
             WHERE p.deleted_at IS NULL AND p.ativo = 1"
        );
        $resumo = $stmtResumo->fetch();

        $this->render('estoque/index', [
            'produtos'   => $produtos,
            'resumo'     => $resumo,
            'total'      => $total,
            'page'       => $page,
            'perPage'    => $perPage,
            'search'     => $search,
            'alerta'     => $alerta,
        ], 'admin');
    }

    public function ajustarSku(): void {
        $this->verifyCsrf();
        $this->ajusteSoNoBling();
    }

    private function ajusteSoNoBling(): void {
        $this->json([
            'ok'  => false,
            'msg' => 'O saldo é do Bling: ajuste pela origem (Syscar → Bling) e use "Puxar do Bling" para trazer o número.',
        ], 410);
    }
}
