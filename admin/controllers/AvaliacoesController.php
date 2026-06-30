<?php 

// ════════════════════════════════════════════════════════
// admin/controllers/AvaliacoesController.php
// ════════════════════════════════════════════════════════
class AvaliacoesController extends Controller {
 
    public function __construct() {
        AuthHelper::requireAdmin();
    }
 
    public function index(): void {
        $db      = Database::getInstance()->getConnection();
        $filtro  = $_GET['aprovado'] ?? 'todas';
        $busca   = SecurityHelper::sanitizeString($_GET['q'] ?? '');
        $nota    = SecurityHelper::sanitizeInt($_GET['nota'] ?? 0);
        $page    = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 15;
        $offset  = ($page - 1) * $perPage;
 
        $where  = "1=1";
        $params = [];
 
        if ($filtro === '0') { $where .= " AND a.aprovado = 0"; }
        elseif ($filtro === '1') { $where .= " AND a.aprovado = 1"; }
 
        if ($nota) { $where .= " AND a.nota = ?"; $params[] = $nota; }
 
        if ($busca) {
            $where   .= " AND (a.comentario LIKE ? OR a.cliente_nome LIKE ? OR p.nome LIKE ?)";
            $like     = "%{$busca}%";
            $params[] = $like; $params[] = $like; $params[] = $like;
        }
 
        $stmt = $db->prepare(
            "SELECT a.*,
                    COALESCE(a.cliente_nome, u.nome, 'Visitante') AS nome_exibido,
                    p.nome  AS produto_nome,
                    p.slug  AS produto_slug,
                    (SELECT COUNT(*) FROM avaliacao_midias m WHERE m.avaliacao_id = a.id) AS total_midias
             FROM avaliacoes a
             LEFT JOIN clientes c  ON c.id = a.cliente_id
             LEFT JOIN usuarios u  ON u.id = c.usuario_id
             LEFT JOIN produtos p  ON p.id = a.produto_id
             WHERE {$where}
             ORDER BY a.criado_em DESC
             LIMIT ? OFFSET ?"
        );
        $params[] = $perPage;
        $params[] = $offset;
        $stmt->execute($params);
        $avaliacoes = $stmt->fetchAll();
 
        // Contadores pra tabs
        $total       = (int)$db->query("SELECT COUNT(*) FROM avaliacoes")->fetchColumn();
        $pendentes   = (int)$db->query("SELECT COUNT(*) FROM avaliacoes WHERE aprovado=0")->fetchColumn();
        $aprovadas   = (int)$db->query("SELECT COUNT(*) FROM avaliacoes WHERE aprovado=1")->fetchColumn();
 
        $this->render('avaliacoes/index', [
            'avaliacoes' => $avaliacoes,
            'filtro'     => $filtro,
            'busca'      => $busca,
            'nota'       => $nota,
            'page'       => $page,
            'perPage'    => $perPage,
            'total'      => $total,
            'pendentes'  => $pendentes,
            'aprovadas'  => $aprovadas,
        ], 'admin');
    }
 
    public function aprovar(): void {
        $this->verifyCsrf();
        $id = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
        if (!$id) $this->json(['ok' => false]);
 
        $db = Database::getInstance()->getConnection();
        $db->prepare(
            "UPDATE avaliacoes SET aprovado=1, moderado_em=NOW() WHERE id=?"
        )->execute([$id]);
 
        $this->json(['ok' => true]);
    }
 
    public function rejeitar(): void {
        $this->verifyCsrf();
        $id     = SecurityHelper::sanitizeInt($_POST['id']     ?? 0);
        $motivo = SecurityHelper::sanitizeString($_POST['motivo'] ?? '');
        if (!$id) $this->json(['ok' => false]);
 
        $db = Database::getInstance()->getConnection();
        $db->prepare(
            "UPDATE avaliacoes
             SET aprovado=0, motivo_rejeicao=?, moderado_em=NOW()
             WHERE id=?"
        )->execute([$motivo ?: null, $id]);
 
        $this->json(['ok' => true]);
    }
 
    public function excluir(): void {
        $this->verifyCsrf();
        $id = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
        if (!$id) $this->json(['ok' => false]);
 
        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare(
            "SELECT arquivo, arquivo_thumb FROM avaliacao_midias WHERE avaliacao_id=?"
        );
        $stmt->execute([$id]);
        foreach ($stmt->fetchAll() as $m) {
            foreach ([$m['arquivo'], $m['arquivo_thumb']] as $f) {
                if ($f) @unlink(UPLOAD_PATH . '/avaliacoes/' . $f);
            }
        }
 
        $db->prepare("DELETE FROM avaliacoes WHERE id=?")->execute([$id]);
        $this->json(['ok' => true]);
    }
 
    public function toggleDestaque(): void {
        $this->verifyCsrf();
        $id   = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT destaque FROM avaliacoes WHERE id=? LIMIT 1");
        $stmt->execute([$id]);
        $novo = (int)$stmt->fetchColumn() ? 0 : 1;
        $db->prepare("UPDATE avaliacoes SET destaque=? WHERE id=?")->execute([$novo, $id]);
        $this->json(['ok' => true, 'destaque' => $novo]);
    }
 
    public function moderarMidia(): void {
        $this->verifyCsrf();
        $id      = SecurityHelper::sanitizeInt($_POST['id']       ?? 0);
        $acao    = $_POST['acao'] ?? '';
        $aprovada = $acao === 'aprovar' ? 1 : 0;
 
        $db = Database::getInstance()->getConnection();
        $db->prepare("UPDATE avaliacao_midias SET aprovada=? WHERE id=?")->execute([$aprovada, $id]);
        $this->json(['ok' => true]);
    }
}