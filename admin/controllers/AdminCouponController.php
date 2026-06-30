<?php
declare(strict_types=1);

// app/controllers/AdminCouponController.php

class AdminCouponController extends Controller {

    private Coupon        $model;
    private CouponService $service;
    private PDO           $db;

    public function __construct() {
        // parent::__construct();
        $this->model   = new Coupon();
        $this->service = new CouponService();
        $this->db      = Database::getInstance()->getConnection();
    }

    // ── GET /admin/cupons ─────────────────────────────────
    public function index(): void {
        $filtros = [
            'busca' => SecurityHelper::sanitizeString($_GET['busca'] ?? ''),
            'ativo' => isset($_GET['ativo']) && $_GET['ativo'] !== '' ? (string)$_GET['ativo'] : null,
            'tipo'  => SecurityHelper::sanitizeString($_GET['tipo'] ?? ''),
        ];
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $cupons = $this->listarComVendedor($filtros, $page, 20);
        $total  = $this->model->contar(array_filter($filtros));

        $this->render('cupons/index', [
            'cupons'  => $cupons,
            'filtros' => $filtros,
            'total'   => $total,
            'page'    => $page,
            'pages'   => (int)ceil($total / 20),
            'stats'   => $this->getStatsGerais(),
        ], 'admin');
    }

    // ── GET /admin/cupons/form ────────────────────────────
    public function form(): void {
        $id    = (int)($_GET['id'] ?? 0);
        $cupom = $id ? $this->model->findById($id) : null;

        $this->render('cupons/form', [
            'cupom'     => $cupom,
            'vendedores' => $this->getVendedores(),
        ], 'admin');
    }

    // ── POST /admin/cupons/salvar ─────────────────────────
    public function salvar(): void {
        $this->verifyCsrf();
        $adminId = (int)Session::get('admin_id');
        $data    = $this->extrairDadosForm();
        $errors  = $this->validarForm($data);
        if ($errors) $this->json(['ok' => false, 'errors' => $errors]);

        // Resolve vendedor
        if (!empty($data['vendedor_id'])) {
            $stmt = $this->db->prepare("SELECT codigo FROM vendedores WHERE id = ? LIMIT 1");
            $stmt->execute([(int)$data['vendedor_id']]);
            $data['codigo_vendedor'] = $stmt->fetchColumn() ?: null;
        }

        $data[!empty($data['id']) ? 'atualizado_por' : 'criado_por'] = $adminId;

        try {
            $id = $this->model->salvar($data);
            $this->json(['ok' => true, 'id' => $id,
                         'redirect' => BASE_URL . '/admin/cupons',
                         'msg' => 'Cupom salvo com sucesso.']);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                $this->json(['ok' => false, 'msg' => 'Este código de cupom já existe.']);
            }
            throw $e;
        }
    }

    // ── POST /admin/cupons/toggle-ativo ──────────────────
    public function toggleAtivo(): void {
        $this->verifyCsrf();
        $id      = (int)($_POST['id'] ?? 0);
        $adminId = (int)Session::get('admin_id');
        if (!$id) $this->json(['ok' => false, 'msg' => 'ID inválido.']);
        $this->model->toggleAtivo($id, $adminId);
        $this->json(['ok' => true]);
    }

    // ── POST /admin/cupons/excluir ────────────────────────
    public function excluir(): void {
        $this->verifyCsrf();
        $id      = (int)($_POST['id'] ?? 0);
        $adminId = (int)Session::get('admin_id');
        if (!$id) $this->json(['ok' => false, 'msg' => 'ID inválido.']);
        $this->model->softDelete($id, $adminId);
        $this->json(['ok' => true, 'msg' => 'Cupom removido.']);
    }

    // ── GET /admin/cupons/historico ───────────────────────
    public function historico(): void {
        $id    = (int)($_GET['id'] ?? 0);
        $cupom = $this->model->findById($id);
        if (!$cupom) $this->notFound();

        $historico = $this->historicoComVendedor($id, 100);
        $auditoria = $this->model->auditoria(['cupom_id' => $id], 100);

        $this->render('cupons/historico', 
            compact('cupom', 'historico', 'auditoria'), 'admin'
        );
    }

    // ── GET /admin/cupons/relatorio ───────────────────────
    public function relatorio(): void {
        $filtros = [
            'data_de'  => SecurityHelper::sanitizeString($_GET['data_de']  ?? date('Y-m-d', strtotime('-30 days'))),
            'data_ate' => SecurityHelper::sanitizeString($_GET['data_ate'] ?? date('Y-m-d')),
        ];
        $de  = $filtros['data_de']  . ' 00:00:00';
        $ate = $filtros['data_ate'] . ' 23:59:59';

        $this->render('cupons/relatorio', [
            'filtros'         => $filtros,
            'kpis'            => $this->getKpis($de, $ate),
            'topCupons'       => $this->getTopCupons($de, $ate, 10),
            'topClientes'     => $this->getTopClientes($de, $ate, 10),
            'recusasPorMotivo'=> $this->getRecusasPorMotivo($de, $ate),
            'graficoUsosDia'  => $this->getGraficoUsosDia($de, $ate),
            'graficoTipos'    => $this->getGraficoTipos($de, $ate),
            'porVendedor'     => $this->getRelatorioPorVendedor($de, $ate),
        ], 'admin');
    }

    // ════════════════════════════════════════════════════
    // QUERIES PRIVADAS
    // ════════════════════════════════════════════════════

    private function listarComVendedor(array $filtros, int $page, int $perPage): array {
        $where  = ['c.deleted_at IS NULL'];
        $params = [];
        if (!empty($filtros['busca'])) {
            $where[] = "(c.codigo LIKE ? OR c.nome LIKE ?)";
            $params[] = '%' . $filtros['busca'] . '%';
            $params[] = '%' . $filtros['busca'] . '%';
        }
        if ($filtros['ativo'] !== null) {
            $where[] = "c.ativo = ?"; $params[] = (int)$filtros['ativo'];
        }
        if (!empty($filtros['tipo'])) {
            $where[] = "c.tipo = ?"; $params[] = $filtros['tipo'];
        }

        $sql = "SELECT c.*,
                    v.nome AS vendedor_nome, v.codigo AS vendedor_codigo,
                    (SELECT COUNT(*) FROM cupom_usos cu
                     WHERE cu.cupom_id = c.id AND cu.status = 'confirmado') AS usos_confirmados
                FROM cupons c
                LEFT JOIN vendedores v ON v.id = c.vendedor_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY c.criado_em DESC
                LIMIT ? OFFSET ?";
        $params[] = $perPage;
        $params[] = ($page - 1) * $perPage;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function historicoComVendedor(int $cupomId, int $limit): array {
        $stmt = $this->db->prepare(
            "SELECT cu.*,
                u.nome AS cliente_nome, u.email AS cliente_email,
                COALESCE(vp.nome, vc.nome) AS vendedor_nome,
                p.codigo AS pedido_numero
             FROM cupom_usos cu
             LEFT JOIN clientes  cl  ON cl.id   = cu.cliente_id
             LEFT JOIN usuarios  u   ON u.id   =    cl.usuario_id
             LEFT JOIN pedidos   p   ON p.id    = cu.pedido_id
             LEFT JOIN vendedores vp ON vp.codigo = p.codigo_vendedor
             LEFT JOIN cupons     cp ON cp.id    = cu.cupom_id
             LEFT JOIN vendedores vc ON vc.id    = cp.vendedor_id
             WHERE cu.cupom_id = ?
             ORDER BY cu.criado_em DESC
             LIMIT ?"
        );
        $stmt->execute([$cupomId, $limit]);
        return $stmt->fetchAll();
    }

    private function getStatsGerais(): array {
        $hoje = date('Y-m-d');
        $r    = [];

        $stmt = $this->db->query("SELECT COUNT(*) FROM cupons WHERE ativo = 1 AND deleted_at IS NULL");
        $r['ativos'] = (int)$stmt->fetchColumn();

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM cupom_usos WHERE status = 'confirmado' AND DATE(criado_em) = ?"
        );
        $stmt->execute([$hoje]);
        $r['usos_hoje'] = (int)$stmt->fetchColumn();

        $stmt = $this->db->query("SELECT COALESCE(SUM(total_desconto_concedido),0) FROM cupons WHERE deleted_at IS NULL");
        $r['desconto_total'] = (float)$stmt->fetchColumn();

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM cupom_auditoria WHERE resultado = 'recusado' AND DATE(criado_em) = ?"
        );
        $stmt->execute([$hoje]);
        $r['recusas_hoje'] = (int)$stmt->fetchColumn();

        return $r;
    }

    private function getKpis(string $de, string $ate): array {
        $stmt = $this->db->prepare(
            "SELECT
                COUNT(*) AS usos_confirmados,
                COALESCE(SUM(valor_desconto + valor_frete_desc), 0) AS desconto_total,
                COALESCE(AVG(valor_original), 0) AS ticket_medio
             FROM cupom_usos
             WHERE status = 'confirmado' AND criado_em BETWEEN ? AND ?"
        );
        $stmt->execute([$de, $ate]);
        $r = $stmt->fetch();

        // Recusas no período
        $stmt2 = $this->db->prepare(
            "SELECT COUNT(*) FROM cupom_auditoria WHERE resultado = 'recusado' AND criado_em BETWEEN ? AND ?"
        );
        $stmt2->execute([$de, $ate]);
        $r['total_recusas'] = (int)$stmt2->fetchColumn();

        // Usos hoje
        $stmt3 = $this->db->prepare(
            "SELECT COUNT(*) FROM cupom_usos WHERE status = 'confirmado' AND DATE(criado_em) = ?"
        );
        $stmt3->execute([date('Y-m-d')]);
        $r['usos_hoje'] = (int)$stmt3->fetchColumn();

        // Taxa de aprovação
        $total = $r['usos_confirmados'] + $r['total_recusas'];
        $r['taxa_aprovacao'] = $total > 0 ? round($r['usos_confirmados'] / $total * 100, 1) : 0;

        return $r;
    }

    private function getTopCupons(string $de, string $ate, int $limit): array {
        $stmt = $this->db->prepare(
            "SELECT c.codigo, COUNT(cu.id) AS total_usos,
                    COALESCE(SUM(cu.valor_desconto + cu.valor_frete_desc), 0) AS total_desc
             FROM cupom_usos cu
             JOIN cupons c ON c.id = cu.cupom_id
             WHERE cu.status = 'confirmado' AND cu.criado_em BETWEEN ? AND ?
             GROUP BY c.id, c.codigo
             ORDER BY total_usos DESC LIMIT ?"
        );
        $stmt->execute([$de, $ate, $limit]);
        return $stmt->fetchAll();
    }

    private function getTopClientes(string $de, string $ate, int $limit): array {
        $stmt = $this->db->prepare(
            "SELECT u.nome AS cliente_nome, u.email AS cliente_email,
                    COUNT(cu.id) AS total_usos,
                    COALESCE(SUM(cu.valor_desconto + cu.valor_frete_desc), 0) AS total_desc
             FROM cupom_usos cu
             LEFT JOIN clientes cl ON cl.id = cu.cliente_id
             LEFT JOIN usuarios u ON u.id = cl.usuario_id
             WHERE cu.status = 'confirmado' AND cu.criado_em BETWEEN ? AND ?
             GROUP BY cu.cliente_id
             ORDER BY total_usos DESC LIMIT ?"
        );
        $stmt->execute([$de, $ate, $limit]);
        return $stmt->fetchAll();
    }

    private function getRecusasPorMotivo(string $de, string $ate): array {
        $stmt = $this->db->prepare(
            "SELECT
                SUBSTRING_INDEX(motivo_recusa, '.', 1) AS motivo_curto,
                COUNT(*) AS total
             FROM cupom_auditoria
             WHERE resultado = 'recusado' AND criado_em BETWEEN ? AND ?
               AND motivo_recusa IS NOT NULL
             GROUP BY motivo_curto
             ORDER BY total DESC LIMIT 10"
        );
        $stmt->execute([$de, $ate]);
        return $stmt->fetchAll();
    }

    private function getGraficoUsosDia(string $de, string $ate): array {
        $stmt = $this->db->prepare(
            "SELECT DATE(criado_em) AS dia, COUNT(*) AS total
             FROM cupom_usos
             WHERE status = 'confirmado' AND criado_em BETWEEN ? AND ?
             GROUP BY dia ORDER BY dia ASC"
        );
        $stmt->execute([$de, $ate]);
        return array_map(fn($r) => [
            'dia'   => date('d/m', strtotime($r['dia'])),
            'total' => (int)$r['total'],
        ], $stmt->fetchAll());
    }

    private function getGraficoTipos(string $de, string $ate): array {
        $stmt = $this->db->prepare(
            "SELECT c.tipo, COUNT(cu.id) AS total
             FROM cupom_usos cu
             JOIN cupons c ON c.id = cu.cupom_id
             WHERE cu.status = 'confirmado' AND cu.criado_em BETWEEN ? AND ?
             GROUP BY c.tipo ORDER BY total DESC"
        );
        $stmt->execute([$de, $ate]);
        return $stmt->fetchAll();
    }

    private function getRelatorioPorVendedor(string $de, string $ate): array {
        $stmt = $this->db->prepare(
            "SELECT
                COALESCE(vp.nome, vc.nome)         AS vendedor_nome,
                COALESCE(p.codigo_vendedor, cp.codigo_vendedor) AS codigo_vendedor,
                cp.codigo                           AS cupom_codigo,
                COUNT(cu.id)                        AS total_pedidos,
                COALESCE(SUM(cu.valor_desconto + cu.valor_frete_desc), 0) AS total_desc,
                COALESCE(AVG(cu.valor_original), 0) AS ticket_medio
             FROM cupom_usos cu
             JOIN  cupons    cp ON cp.id    = cu.cupom_id
             LEFT JOIN pedidos   p  ON p.id     = cu.pedido_id
             LEFT JOIN vendedores vp ON vp.codigo = p.codigo_vendedor
             LEFT JOIN vendedores vc ON vc.id     = cp.vendedor_id
             WHERE cu.status = 'confirmado' AND cu.criado_em BETWEEN ? AND ?
               AND (p.codigo_vendedor IS NOT NULL OR cp.codigo_vendedor IS NOT NULL)
             GROUP BY
            vendedor_nome,
            codigo_vendedor,
            cupom_codigo
             ORDER BY total_pedidos DESC"
        );
        $stmt->execute([$de, $ate]);
        return $stmt->fetchAll();
    }

    private function getVendedores(): array {
        try {
            $stmt = $this->db->query("SELECT id, nome, codigo FROM vendedores WHERE ativo = 1 ORDER BY nome");
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            return [];
        }
    }

    // ── Validação e extração do form ──────────────────────
    private function extrairDadosForm(): array {
        return [
            'id'                     => (int)($_POST['id'] ?? 0) ?: null,
            'codigo'                 => strtoupper(trim($_POST['codigo'] ?? '')),
            'nome'                   => SecurityHelper::sanitizeString($_POST['nome'] ?? ''),
            'descricao'              => SecurityHelper::sanitizeString($_POST['descricao'] ?? ''),
            'tipo'                   => SecurityHelper::sanitizeString($_POST['tipo'] ?? 'percentual'),
            'valor'                  => strlen($_POST['valor'] ?? '') ? (float)str_replace(',','.',($_POST['valor'] ?? '0')) : null,
            'valor_maximo'           => strlen($_POST['valor_maximo'] ?? '') ? (float)str_replace(',','.',($_POST['valor_maximo'] ?? '0')) : null,
            'valor_minimo_pedido'    => (float)str_replace(',', '.', ($_POST['valor_minimo_pedido'] ?? '0')),
            'ativo'                  => isset($_POST['ativo']) && $_POST['ativo'] !== '0' ? 1 : 0,
            'data_inicio'            => !empty($_POST['data_inicio']) ? str_replace('T',' ',$_POST['data_inicio']) . ':00' : null,
            'data_fim'               => !empty($_POST['data_fim'])    ? str_replace('T',' ',$_POST['data_fim'])    . ':00' : null,
            'limite_total'           => strlen($_POST['limite_total'] ?? '') ? (int)$_POST['limite_total'] : null,
            'limite_por_cliente'     => max(1, (int)($_POST['limite_por_cliente'] ?? 1)),
            'apenas_primeira_compra' => (int)($_POST['apenas_primeira_compra'] ?? 0),
            'permite_produto_promo'  => (int)($_POST['permite_produto_promo']  ?? 1),
            'acumula_desconto'       => (int)($_POST['acumula_desconto']        ?? 0),
            'escopo_produtos'        => $this->parseIds($_POST['escopo_produtos']   ?? ''),
            'escopo_categorias'      => $this->parseIds($_POST['escopo_categorias'] ?? ''),
            'escopo_marcas'          => $this->parseIds($_POST['escopo_marcas']     ?? ''),
            'escopo_clientes'        => $this->parseIds($_POST['escopo_clientes']   ?? ''),
            'regras_progressivas'    => !empty($_POST['regras_progressivas']) ? json_decode($_POST['regras_progressivas'], true) : null,
            'campanha_id'            => (int)($_POST['campanha_id']   ?? 0) ?: null,
            'campanha_nome'          => SecurityHelper::sanitizeString($_POST['campanha_nome']   ?? ''),
            'vendedor_id'            => (int)($_POST['vendedor_id']   ?? 0) ?: null,
            'codigo_vendedor'        => SecurityHelper::sanitizeString($_POST['codigo_vendedor'] ?? ''),
        ];
    }

    private function validarForm(array $data): array {
        $errors = [];
        if (empty($data['codigo'])) $errors[] = 'Código obrigatório.';
        if (empty($data['nome']))   $errors[] = 'Nome obrigatório.';
        if (!preg_match('/^[A-Z0-9_\-]{3,50}$/', $data['codigo'] ?? '')) {
            $errors[] = 'Código inválido. Use letras maiúsculas, números, - e _';
        }
        if (!in_array($data['tipo'] ?? '', ['frete_gratis','automatico','recuperacao_carrinho'], true)) {
            if ($data['valor'] === null) $errors[] = 'Informe o valor do desconto.';
            if (in_array($data['tipo'], ['percentual','primeira_compra','exclusivo'], true)
                && ($data['valor'] < 1 || $data['valor'] > 100)) {
                $errors[] = 'Percentual deve ser entre 1 e 100.';
            }
        }
        if ($data['data_fim'] && $data['data_inicio'] && $data['data_fim'] < $data['data_inicio']) {
            $errors[] = 'Data fim deve ser após a data início.';
        }
        return $errors;
    }

    private function parseIds(mixed $input): ?array {
        if (empty($input)) return null;
        if (is_array($input)) return array_values(array_filter(array_map('intval', $input)));
        $ids = array_values(array_filter(array_map('intval', explode(',', $input))));
        return $ids ?: null;
    }
}