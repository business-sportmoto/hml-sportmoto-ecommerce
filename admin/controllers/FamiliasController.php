<?php
class FamiliasController extends Controller {

    public function __construct() {
        AuthHelper::requireAdmin();
    }

    // Ajax — busca famílias por nome
    public function buscar(): void {
        $q    = SecurityHelper::sanitizeString($_GET['q'] ?? '');
        $like = '%' . $q . '%';

        $stmt = Database::getInstance()->getConnection()->prepare(
            "SELECT f.id, f.nome,
                    COUNT(p.id) AS total_membros
             FROM familia_produtos f
             LEFT JOIN produtos p ON p.familia_id = f.id
               AND p.deleted_at IS NULL
             WHERE f.nome LIKE ?
             GROUP BY f.id
             ORDER BY f.nome ASC
             LIMIT 10"
        );
        $stmt->execute([$like]);
        $this->json(['ok' => true, 'familias' => $stmt->fetchAll()]);
    }

    // Ajax — cria nova família
    public function criar(): void {
        $this->verifyCsrf();
        $nome = SecurityHelper::sanitizeString($_POST['nome'] ?? '');
        if (empty($nome)) {
            $this->json(['ok' => false, 'msg' => 'Nome obrigatório.']);
        }

        $slug = SlugHelper::unique($nome, 'familia_produtos');

        $db = Database::getInstance()->getConnection();
        $db->prepare(
            "INSERT INTO familia_produtos (nome, slug) VALUES (?, ?)"
        )->execute([$nome, $slug]);

        $id = (int)$db->lastInsertId();
        $this->json(['ok' => true, 'id' => $id, 'nome' => $nome]);
    }

    // Ajax — renomear família
    public function renomear(): void {
        $this->verifyCsrf();
        $id   = SecurityHelper::sanitizeInt($_POST['id']   ?? 0);
        $nome = SecurityHelper::sanitizeString($_POST['nome'] ?? '');
        if (!$id || empty($nome)) {
            $this->json(['ok' => false, 'msg' => 'Dados inválidos.']);
        }

        Database::getInstance()->getConnection()
            ->prepare("UPDATE familia_produtos SET nome=? WHERE id=?")
            ->execute([$nome, $id]);

        $this->json(['ok' => true, 'msg' => 'Família renomeada!']);
    }

    // Ajax — excluir família
    public function excluir(): void {
        $this->verifyCsrf();
        $id = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
        if (!$id) $this->json(['ok' => false]);

        $db   = Database::getInstance()->getConnection();

        // Desvincula produtos antes de excluir
        $db->prepare(
            "UPDATE produtos SET familia_id = NULL WHERE familia_id = ?"
        )->execute([$id]);

        $db->prepare(
            "DELETE FROM familia_produtos WHERE id = ?"
        )->execute([$id]);

        $this->json(['ok' => true, 'msg' => 'Família excluída e produtos desvinculados.']);
    }

    // Ajax — vincular produto a família
    public function vincular(): void {
        $this->verifyCsrf();
        $produtoId  = SecurityHelper::sanitizeInt($_POST['produto_id']  ?? 0);
        $familiaId  = SecurityHelper::sanitizeInt($_POST['familia_id']  ?? 0);
        if (!$produtoId || !$familiaId) {
            $this->json(['ok' => false, 'msg' => 'Dados inválidos.']);
        }

        $db = Database::getInstance()->getConnection();

        // Confirma que a família existe
        $stmt = $db->prepare("SELECT id, nome FROM familia_produtos WHERE id = ?");
        $stmt->execute([$familiaId]);
        $familia = $stmt->fetch();
        if (!$familia) {
            $this->json(['ok' => false, 'msg' => 'Família não encontrada.']);
        }

        $db->prepare(
            "UPDATE produtos SET familia_id = ? WHERE id = ?"
        )->execute([$familiaId, $produtoId]);

        // Conta membros
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM produtos WHERE familia_id = ? AND deleted_at IS NULL"
        );
        $stmt->execute([$familiaId]);
        $total = (int)$stmt->fetchColumn();

        $this->json([
            'ok'     => true,
            'id'     => $familiaId,
            'nome'   => $familia['nome'],
            'total'  => $total,
        ]);
    }

    // Ajax — desvincular produto da família
    public function desvincular(): void {
        $this->verifyCsrf();
        $produtoId = SecurityHelper::sanitizeInt($_POST['produto_id'] ?? 0);
        if (!$produtoId) $this->json(['ok' => false]);

        Database::getInstance()->getConnection()
            ->prepare("UPDATE produtos SET familia_id = NULL WHERE id = ?")
            ->execute([$produtoId]);

        $this->json(['ok' => true]);
    }
}