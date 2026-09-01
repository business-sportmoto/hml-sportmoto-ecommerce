<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// admin/controllers/BiMetaController.php
// ════════════════════════════════════════════════════════

class BiMetaController extends Controller {

    private BiMeta $model;

    public function __construct() {
        AuthHelper::requireAdmin();
        // Meta é decisão comercial com impacto financeiro — mesmo
        // nível de Promoções e Cupons (ver CLAUDE.md §4.6).
        AuthHelper::requireAdminLevel('super', 'gerente');
        $this->model = new BiMeta();
    }

    // ── GET /admin/bi/metas ───────────────────────────────
    public function index(): void {
        $filtros = [
            'metrica'  => SecurityHelper::sanitizeString($_GET['metrica']  ?? ''),
            'dimensao' => SecurityHelper::sanitizeString($_GET['dimensao'] ?? ''),
            'ano'      => (int)($_GET['ano'] ?? 0),
        ];

        $metas    = $this->model->listar(array_filter($filtros));
        $metricas = BiMeta::METRICAS;
        $dimensoes= BiMeta::DIMENSOES;
        $granuls  = BiMeta::GRANULARIDADES;

        // Alvos possíveis por dimensão, para o combo dependente.
        $alvos = $this->model->alvos();

        $this->render('bi/metas', compact(
            'metas','filtros','metricas','dimensoes','granuls','alvos'
        ), 'admin');
    }

    // ── POST /admin/bi/metas/salvar ───────────────────────
    public function salvar(): void {
        $this->verifyCsrf();

        $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;

        $res = $this->model->salvar([
            'periodo_ini'   => SecurityHelper::sanitizeString($_POST['periodo_ini']   ?? ''),
            'periodo_fim'   => SecurityHelper::sanitizeString($_POST['periodo_fim']   ?? ''),
            'granularidade' => SecurityHelper::sanitizeString($_POST['granularidade'] ?? 'mes'),
            'metrica'       => SecurityHelper::sanitizeString($_POST['metrica']       ?? ''),
            'dimensao'      => SecurityHelper::sanitizeString($_POST['dimensao']      ?? 'loja'),
            'dimensao_id'   => $_POST['dimensao_id'] ?? null,
            'valor_meta'    => $_POST['valor_meta']  ?? '0',
            'observacao'    => SecurityHelper::sanitizeString($_POST['observacao']    ?? ''),
        ], $id);

        $this->json($res);
    }

    // ── POST /admin/bi/metas/excluir ──────────────────────
    public function excluir(): void {
        $this->verifyCsrf();

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) $this->json(['ok' => false, 'msg' => 'ID inválido.']);

        $this->json($this->model->excluir($id)
            ? ['ok' => true,  'msg' => 'Meta excluída.']
            : ['ok' => false, 'msg' => 'Não foi possível excluir.']);
    }
}
