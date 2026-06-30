<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/controllers/AdminStatusPedidoController.php
// ════════════════════════════════════════════════════════

class AdminStatusPedidoController extends Controller {

    private PedidoStatus $model;

    public function __construct() {
        // parent::__construct();
        AuthHelper::requireAdmin();
        AuthHelper::requireAdminLevel('super', 'gerente');
        $this->model = new PedidoStatus();
    }

    // ── GET /admin/configuracoes/status-pedidos ───────────
    public function index(): void {
        $statusList = $this->model->getAll();
        $this->render('config/status-pedidos', compact('statusList'), 'admin');
    }

    // ── POST /admin/configuracoes/status-pedidos/salvar ───
    // Cria (sem id) ou atualiza (com id) — responde JSON
    public function salvar(): void {
        $this->verifyCsrf();

        $id    = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        $dados = [
            'slug'                  => SecurityHelper::sanitizeString($_POST['slug']       ?? ''),
            'label'                 => SecurityHelper::sanitizeString($_POST['label']      ?? ''),
            'cor'                   => SecurityHelper::sanitizeString($_POST['cor']        ?? 'info'),
            'icone_key'             => SecurityHelper::sanitizeString($_POST['icone_key']  ?? ''),
            'ativo'                 => (int)($_POST['ativo']                               ?? 1),
            'ordenacao'             => (int)($_POST['ordenacao']                           ?? 50),
            'estorna_estoque'       => (int)($_POST['estorna_estoque']                     ?? 0),
            'cancela_cupom'         => (int)($_POST['cancela_cupom']                       ?? 0),
            'bloqueia_edicao_itens' => (int)($_POST['bloqueia_edicao_itens']               ?? 1),
            'notifica_cliente'      => (int)($_POST['notifica_cliente']                    ?? 1),
        ];

        // Normaliza icone_key vazio para null
        if (empty($dados['icone_key'])) $dados['icone_key'] = null;

        try {
            if ($id) {
                $this->model->atualizar($id, $dados);
                $status = $this->model->findById($id);
                $this->json(['ok' => true, 'msg' => 'Status atualizado.', 'status' => $status]);
            } else {
                if (empty($dados['label'])) {
                    $this->json(['ok' => false, 'msg' => 'Informe o nome do status.']);
                }
                $novoId = $this->model->criar($dados);
                $status = $this->model->findById($novoId);
                $this->json(['ok' => true, 'msg' => 'Status criado com sucesso.', 'status' => $status, 'novo' => true]);
            }
        } catch (\RuntimeException $e) {
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        } catch (\Throwable $e) {
            error_log('[AdminStatusPedidoController] salvar: ' . $e->getMessage());
            $this->json(['ok' => false, 'msg' => 'Erro interno ao salvar.']);
        }
    }

    // ── POST /admin/configuracoes/status-pedidos/excluir ──
    public function excluir(): void {
        $this->verifyCsrf();
        AuthHelper::requireAdminLevel('super');

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) $this->json(['ok' => false, 'msg' => 'ID inválido.']);

        $this->json($this->model->excluir($id));
    }

    // ── GET /admin/configuracoes/status-pedidos/dados/{id} ──
    // Chamado pelo JS para carregar um status no modal de edição
    public function dados(int $id): void {
        $status = $this->model->findById($id);
        if (!$status) {
            $this->json(['ok' => false, 'msg' => 'Status não encontrado.']);
        }
        $this->json(['ok' => true, 'status' => $status]);
    }

    // ── POST /admin/configuracoes/status-pedidos/reordenar ─
    public function reordenar(): void {
        $this->verifyCsrf();
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids) || empty($ids)) {
            $this->json(['ok' => false, 'msg' => 'Dados inválidos.']);
        }
        $this->model->reordenar(array_map('intval', $ids));
        $this->json(['ok' => true]);
    }
}