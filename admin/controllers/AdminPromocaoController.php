<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/controllers/AdminPromocaoController.php
// ════════════════════════════════════════════════════════

class AdminPromocaoController extends Controller {

    private Promocao       $model;
    private PromocaoService $service;

    public function __construct() {
        $this->model   = new Promocao();
        $this->service = new PromocaoService();
    }

    // ── GET /admin/promocoes ──────────────────────────────
    public function index(): void {
        $filtros = [
            'busca' => SecurityHelper::sanitizeString($_GET['busca'] ?? ''),
            'ativo' => isset($_GET['ativo']) && $_GET['ativo'] !== ''
                       ? (int)$_GET['ativo'] : null,
            'tipo'  => SecurityHelper::sanitizeString($_GET['tipo'] ?? ''),
        ];

        $page      = max(1, (int)($_GET['pagina'] ?? 1));
        $perPage   = 20;
        $total     = $this->model->contar($filtros);
        $promocoes = $this->model->listar($filtros, $page, $perPage);
        $pag       = new PaginationHelper($total, $page, ADMIN_URL . '/promocoes');

        $this->render('promocoes/index', array_merge($pag->toArray(), [
            'promocoes' => $promocoes,
            'filtros'   => $filtros,
            'total'     => $total,
        ]), 'admin');
    }

    // ── GET /admin/promocoes/nova ─────────────────────────
    public function nova(): void {
        $this->render('promocoes/form', [
            'promocao' => null,
            'titulo'   => 'Nova Promoção',
        ], 'admin');
    }

    // ── POST /admin/promocoes/nova ────────────────────────
    public function criar(): void {
        $this->verifyCsrf();

        try {
            $data = $this->parseFormData();
            $id   = $this->model->salvar($data);

            $this->redirect(ADMIN_URL . '/promocoes/' . $id . '?criada=1');
        } catch (\Throwable $e) {
            error_log('[AdminPromocaoController::criar] ' . $e->getMessage());
            $this->redirect(ADMIN_URL . '/promocoes/nova?erro=' . urlencode($e->getMessage()));
        }
    }

    // ── GET /admin/promocoes/{id} ─────────────────────────
    public function show(int $id): void {
        $promocao = $this->model->findById($id);
        if (!$promocao) {
            http_response_code(404);
            $this->render('errors/404', [], 'admin');
            return;
        }

        $db         = Database::getInstance()->getConnection();
        $aplicacoes = $this->getAplicacoes($db, $id);
        $escopoNomes= $this->getEscopoNomes($db, $promocao);
        $brindeProduto = $this->getBrindeProduto($db, $promocao);

        $this->render('promocoes/form', [
            'promocao'      => $promocao,
            'aplicacoes'    => $aplicacoes,
            'escopoNomes'   => $escopoNomes,
            'brindeProduto' => $brindeProduto,
            'titulo'        => 'Editar: ' . $promocao['nome'],
            'criada'        => !empty($_GET['criada']),
        ], 'admin');
    }

    // ── POST /admin/promocoes/{id} ────────────────────────
    public function atualizar(int $id): void {
        $this->verifyCsrf();

        $promocao = $this->model->findById($id);
        if (!$promocao) $this->json(['ok' => false, 'msg' => 'Não encontrada.']);

        try {
            $data       = $this->parseFormData();
            $data['id'] = $id;
            $this->model->salvar($data);

            $this->redirect(ADMIN_URL . '/promocoes/' . $id . '?salvo=1');
        } catch (\Throwable $e) {
            error_log('[AdminPromocaoController::atualizar] ' . $e->getMessage());
            $this->redirect(ADMIN_URL . '/promocoes/' . $id . '?erro=' . urlencode($e->getMessage()));
        }
    }

    // ── POST /admin/promocoes/{id}/toggle ─────────────────
    public function toggle(int $id): void {
        $this->verifyCsrf();
        $this->model->toggleAtivo($id);
        $this->json(['ok' => true]);
    }

    // ── POST /admin/promocoes/{id}/excluir ─────────────────
    public function excluir(int $id): void {
        $this->verifyCsrf();
        $adminId = (int)Session::get('admin_id');
        $this->model->softDelete($id, $adminId);
        $this->json(['ok' => true]);
    }

    // ══════════════════════════════════════════════════════
    // PRIVADO
    // ══════════════════════════════════════════════════════

    private function parseFormData(): array {
        $tipo = SecurityHelper::sanitizeString($_POST['tipo'] ?? '');

        // ── Configuração específica por tipo ──────────────
        $configuracao = match($tipo) {
            'desconto_progressivo' => $this->parseFaixas(),
            'frete_gratis'         => [
                'valor_minimo' => (float)str_replace(',', '.', $_POST['cfg_valor_minimo'] ?? '0'),
            ],
            'brinde' => [
                'produto_brinde_id' => (int)($_POST['brinde_produto_id'] ?? 0),
                'quantidade_brinde' => max(1, (int)($_POST['brinde_quantidade'] ?? 1)),
                'gatilho'           => in_array(
                    $_POST['brinde_gatilho'] ?? '',
                    ['valor', 'quantidade', 'ambos'],
                    true
                ) ? $_POST['brinde_gatilho'] : 'valor',
                'valor_minimo'   => (float)str_replace(',', '.', $_POST['brinde_valor_minimo'] ?? '0'),
                'qtd_minima'     => max(1, (int)($_POST['brinde_qtd_minima'] ?? 1)),
                'modo_contagem'  => in_array($_POST['brinde_modo_contagem'] ?? '', ['unidades','distintos'], true)
                                    ? $_POST['brinde_modo_contagem'] : 'unidades',
            ],
            'compre_ganhe' => [
                'comprar'     => max(2, (int)($_POST['cg_comprar']     ?? 2)),
                'levar'       => max(1, (int)($_POST['cg_levar']       ?? 1)),
                'desconto_pct'=> min(100, max(1, (float)str_replace(',', '.', $_POST['cg_desconto_pct'] ?? '100'))),
            ],
            'cashback' => [
                'percentual'    => min(100, max(0.01, (float)str_replace(',', '.', $_POST['cb_percentual']    ?? '5'))),
                'validade_dias' => max(1,   (int)($_POST['cb_validade_dias'] ?? 90)),
            ],
            default => [],
        };

        // ── Escopos (arrays de IDs) ───────────────────────
        $parseIds = fn(string $key): ?array => !empty($_POST[$key])
            ? array_map('intval', array_filter(explode(',', $_POST[$key])))
            : null;

        // ── Características: [{id, valor}, ...] ───────────
        $caracteristicas = null;
        if (!empty($_POST['carac_ids'])) {
            $ids    = explode(',', $_POST['carac_ids']);
            $vals   = explode('||', $_POST['carac_valores'] ?? '');
            $caracteristicas = [];
            foreach ($ids as $i => $cId) {
                if ($cId === '') continue;
                $caracteristicas[] = [
                    'id'    => (int)$cId,
                    'valor' => trim($vals[$i] ?? ''),
                ];
            }
            if (empty($caracteristicas)) $caracteristicas = null;
        }

        // ── Dias da semana ────────────────────────────────
        $diasSemana = !empty($_POST['dias_semana'])
            ? array_map('intval', (array)$_POST['dias_semana'])
            : null;

        return [
            'nome'                   => SecurityHelper::sanitizeString($_POST['nome'] ?? ''),
            'descricao'              => SecurityHelper::sanitizeString($_POST['descricao'] ?? ''),
            'tipo'                   => $tipo,
            'ativo'                  => isset($_POST['ativo']) ? 1 : 0,
            'prioridade'             => (int)($_POST['prioridade'] ?? 0),
            'acumulavel'             => isset($_POST['acumulavel']) ? 1 : 0,
            'acumula_cupom'          => isset($_POST['acumula_cupom']) ? 1 : 0,
            'data_inicio'            => $_POST['data_inicio'] ?: null,
            'data_fim'               => $_POST['data_fim']    ?: null,
            'dias_semana'            => $diasSemana,
            'horario_inicio'         => $_POST['horario_inicio'] ?: null,
            'horario_fim'            => $_POST['horario_fim']    ?: null,
            'apenas_primeira_compra' => isset($_POST['apenas_primeira_compra']) ? 1 : 0,
            'score_minimo'           => $_POST['score_minimo'] !== '' ? (int)$_POST['score_minimo'] : null,
            'valor_minimo_carrinho'  => $_POST['valor_minimo_carrinho'] !== ''
                ? (float)str_replace(',', '.', $_POST['valor_minimo_carrinho']) : null,
            'qtd_minima_itens'       => $_POST['qtd_minima_itens'] !== ''
                ? (int)$_POST['qtd_minima_itens'] : null,
            'escopo_produtos'        => $parseIds('escopo_produtos'),
            'escopo_categorias'      => $parseIds('escopo_categorias'),
            'escopo_marcas'          => $parseIds('escopo_marcas'),
            'escopo_caracteristicas' => $caracteristicas,
            'configuracao'           => $configuracao,
            'criado_por'             => (int)Session::get('admin_id'),
        ];
    }

    private function parseFaixas(): array {
        $qtds   = $_POST['faixa_qtd']   ?? [];
        $pcts   = $_POST['faixa_pct']   ?? [];
        $faixas = [];

        foreach ($qtds as $i => $qtd) {
            $qtd = (int)$qtd;
            $pct = (float)str_replace(',', '.', $pcts[$i] ?? '0');
            if ($qtd > 0 && $pct > 0) {
                $faixas[] = ['qtd' => $qtd, 'pct' => $pct];
            }
        }

        // Garante ordenação crescente por quantidade
        usort($faixas, fn($a, $b) => $a['qtd'] <=> $b['qtd']);

        return [
            'modo_contagem' => in_array($_POST['modo_contagem'] ?? '', ['unidades', 'distintos'], true)
                ? $_POST['modo_contagem']
                : 'unidades',
            'tipo_desconto' => in_array($_POST['tipo_desconto'] ?? '', ['percentual', 'fixo_por_item'], true)
                ? $_POST['tipo_desconto']
                : 'percentual',
            'faixas'       => $faixas,
            'frete_gratis' => isset($_POST['cfg_frete_gratis']) ? true : false,
        ];
    }

    private function getEscopoNomes(PDO $db, array $promocao): array {
        $resultado = [
            'marcas'     => [],
            'categorias' => [],
            'produtos'   => [],
        ];

        // Marcas
        if (!empty($promocao['escopo_marcas'])) {
            $ids  = array_map('intval', $promocao['escopo_marcas']);
            $in   = implode(',', $ids);
            $rows = $db->query("SELECT id, nome FROM marcas WHERE id IN ({$in}) ORDER BY nome")->fetchAll();
            $resultado['marcas'] = $rows;
        }

        // Categorias
        if (!empty($promocao['escopo_categorias'])) {
            $ids  = array_map('intval', $promocao['escopo_categorias']);
            $in   = implode(',', $ids);
            $rows = $db->query("SELECT id, nome FROM categorias WHERE id IN ({$in}) ORDER BY nome")->fetchAll();
            $resultado['categorias'] = $rows;
        }

        // Produtos
        if (!empty($promocao['escopo_produtos'])) {
            $ids  = array_map('intval', $promocao['escopo_produtos']);
            $in   = implode(',', $ids);
            $rows = $db->query(
                "SELECT p.id, CONCAT(p.nome, IF(m.nome IS NOT NULL, CONCAT(' • ', m.nome), '')) AS nome
                 FROM produtos p
                 LEFT JOIN marcas m ON m.id = p.marca_id
                 WHERE p.id IN ({$in})
                 ORDER BY p.nome"
            )->fetchAll();
            $resultado['produtos'] = $rows;
        }

        return $resultado;
    }

    private function getBrindeProduto(PDO $db, array $promocao): ?array {
        if (($promocao['tipo'] ?? '') !== 'brinde') return null;
        $pid = (int)($promocao['configuracao']['produto_brinde_id'] ?? 0);
        if (!$pid) return null;

        $stmt = $db->prepare(
            "SELECT p.id, CONCAT(p.nome, IF(m.nome IS NOT NULL, ' • ' , ''), COALESCE(m.nome,'')) AS nome
             FROM   produtos p
             LEFT   JOIN marcas m ON m.id = p.marca_id
             WHERE  p.id = ? LIMIT 1"
        );
        $stmt->execute([$pid]);
        return $stmt->fetch() ?: null;
    }

    private function getAplicacoes(PDO $db, int $promocaoId): array {
        $stmt = $db->prepare(
            "SELECT pa.*, ped.codigo AS pedido_codigo,
                    u.nome AS cliente_nome, u.email AS cliente_email
             FROM promocao_aplicacoes pa
             LEFT JOIN pedidos ped ON ped.id = pa.pedido_id
             LEFT JOIN clientes c  ON c.id   = pa.cliente_id
             LEFT JOIN usuarios u ON u.id = c.usuario_id
             WHERE pa.promocao_id = ?
             ORDER BY pa.criado_em DESC
             LIMIT 50"
        );
        $stmt->execute([$promocaoId]);
        return $stmt->fetchAll();
    }
}