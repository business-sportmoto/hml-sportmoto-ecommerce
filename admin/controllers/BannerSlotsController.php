<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// admin/controllers/BannerSlotsController.php
// CRUD de banner_zonas — adaptado ao schema real
// ════════════════════════════════════════════════════════
class BannerSlotsController extends Controller {

    private PDO $db;

    public function __construct() {
        AuthHelper::requireAdmin();
        $this->db = Database::getInstance()->getConnection();
    }

    // ── GET /admin/banner-zonas ──────────────────────────
    public function index(): void {
        $stmt = $this->db->query(
            "SELECT z.*,
                    COUNT(b.id)      AS total_banners,
                    SUM(b.ativo = 1) AS banners_ativos
             FROM banner_zonas z
             LEFT JOIN banners b ON b.zona_id = z.id
             GROUP BY z.id
             ORDER BY z.ordem ASC, z.id ASC"
        );
        $zonas = $stmt->fetchAll();

        $this->render('banners/zonas-index', ['zonas' => $zonas], 'admin');
    }

    // ── GET /admin/banner-zonas/form?id=X ───────────────
    public function form(): void {
        $id   = SecurityHelper::sanitizeInt($_GET['id'] ?? 0);
        $zona = null;

        if ($id) {
            $stmt = $this->db->prepare("SELECT * FROM banner_zonas WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $zona = $stmt->fetch() ?: null;
            if (!$zona) $this->redirect(BASE_URL . '/admin/banner-zonas');
        }

        $this->render('banners/zonas-form', ['zona' => $zona], 'admin');
    }

    // ── POST /admin/banner-zonas/salvar ──────────────────
    public function salvar(): void {
        $this->verifyCsrf();

        $id          = SecurityHelper::sanitizeInt($_POST['id']            ?? 0);
        $nome        = SecurityHelper::sanitizeString($_POST['nome']        ?? '');
        $chave       = SecurityHelper::sanitizeString($_POST['chave']       ?? '');
        $descricao   = SecurityHelper::sanitizeString($_POST['descricao']   ?? '');
        $formato     = SecurityHelper::sanitizeString($_POST['formato']     ?? 'single');
        $largura     = SecurityHelper::sanitizeInt($_POST['largura_rec']  ?? 0);
        $altura      = SecurityHelper::sanitizeInt($_POST['altura_rec']   ?? 0);
        $maxBanners  = max(1, SecurityHelper::sanitizeInt($_POST['max_banners'] ?? 1));
        $ordem       = SecurityHelper::sanitizeInt($_POST['ordem']          ?? 0);
        $ativo       = isset($_POST['ativo']) ? 1 : 0;

        if (empty($nome)) {
            $this->json(['ok' => false, 'msg' => 'Nome obrigatório.']);
        }

        // Sanitiza chave: snake_case alfanumérico
        $chave = strtolower(trim($chave));
        $chave = preg_replace('/[^a-z0-9_]/', '_', $chave);
        $chave = preg_replace('/_+/', '_', trim($chave, '_'));

        if (empty($chave)) {
            $this->json(['ok' => false, 'msg' => 'Chave inválida.']);
        }

        $formatosValidos = ['slider','grid','single','full_width','hero','strip'];
        if (!in_array($formato, $formatosValidos, true)) {
            $this->json(['ok' => false, 'msg' => 'Formato inválido.']);
        }

        // Chave duplicada?
        $stmtChk = $this->db->prepare(
            "SELECT id FROM banner_zonas WHERE chave = ? AND id != ? LIMIT 1"
        );
        $stmtChk->execute([$chave, $id]);
        if ($stmtChk->fetchColumn()) {
            $this->json(['ok' => false,
                'msg' => "A chave \"{$chave}\" já existe. Escolha outra."]);
        }

        $dados = [
            'nome'         => $nome,
            'chave'        => $chave,
            'descricao'    => $descricao ?: null,
            'formato'      => $formato,
            'largura_ideal'=> $largura  ?: null,
            'altura_ideal' => $altura   ?: null,
            'max_banners'  => $maxBanners,
            'ordem'        => $ordem,
            'ativo'        => $ativo,
        ];

        if ($id > 0) {
            $sets   = implode(', ', array_map(fn($k) => "{$k} = ?", array_keys($dados)));
            $params = array_values($dados);
            $params[] = $id;
            $this->db->prepare("UPDATE banner_zonas SET {$sets} WHERE id = ?")
                     ->execute($params);
            $this->json(['ok' => true, 'msg' => 'Zona atualizada!', 'id' => $id]);
        } else {
            $cols  = implode(', ', array_keys($dados));
            $marks = implode(', ', array_fill(0, count($dados), '?'));
            $this->db->prepare("INSERT INTO banner_zonas ({$cols}) VALUES ({$marks})")
                     ->execute(array_values($dados));
            $novoId = (int)$this->db->lastInsertId();
            $this->json(['ok' => true, 'msg' => 'Zona criada!', 'id' => $novoId]);
        }
    }

    // ── POST /admin/banner-zonas/toggle-ativo ────────────
    public function toggleAtivo(): void {
        $this->verifyCsrf();
        $id   = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
        $stmt = $this->db->prepare("SELECT ativo FROM banner_zonas WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $novo = (int)$stmt->fetchColumn() ? 0 : 1;
        $this->db->prepare("UPDATE banner_zonas SET ativo = ? WHERE id = ?")
                 ->execute([$novo, $id]);
        $this->json(['ok' => true, 'ativo' => $novo]);
    }

    // ── POST /admin/banner-zonas/excluir ─────────────────
    public function excluir(): void {
        $this->verifyCsrf();
        $id = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
        if (!$id) $this->json(['ok' => false]);

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM banners WHERE zona_id = ?");
        $stmt->execute([$id]);
        $total = (int)$stmt->fetchColumn();

        if ($total > 0) {
            $this->json(['ok' => false,
                'msg' => "Esta zona tem {$total} banner(s) vinculado(s). Remova-os antes."]);
        }

        $this->db->prepare("DELETE FROM banner_zonas WHERE id = ?")->execute([$id]);
        $this->json(['ok' => true]);
    }
}