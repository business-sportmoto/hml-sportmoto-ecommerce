<?php
declare(strict_types=1);

class CaracteristicasController extends Controller {

    public function __construct() {
        AuthHelper::requireAdmin();
    }

    // ── Listagem ──────────────────────────────────────────
    public function index(): void {
        $db   = Database::getInstance()->getConnection();
        $stmt = $db->query(
            "SELECT c.*,
                    COUNT(DISTINCT cc.categoria_id)  AS total_categorias,
                    COUNT(DISTINCT pc.produto_id)    AS total_produtos
             FROM caracteristicas c
             LEFT JOIN categoria_caracteristicas cc ON cc.caracteristica_id = c.id
             LEFT JOIN produto_caracteristicas   pc ON pc.caracteristica_id = c.id
             GROUP BY c.id
             ORDER BY c.ordem ASC, c.nome ASC"
        );
        $caracteristicas = $stmt->fetchAll();

        $this->render('caracteristicas/index', [
            'caracteristicas' => $caracteristicas,
        ], 'admin');
    }

    // ── Salvar (criar/editar) ─────────────────────────────
    public function salvar(): void {
        $this->verifyCsrf();

        $id          = SecurityHelper::sanitizeInt($_POST['id']          ?? 0);
        $nome        = SecurityHelper::sanitizeString($_POST['nome']      ?? '');
        $tipo        = $_POST['tipo']        ?? 'texto';
        $unidade     = SecurityHelper::sanitizeString($_POST['unidade']   ?? '');
        $placeholder = SecurityHelper::sanitizeString($_POST['placeholder'] ?? '');
        $obrigatorio = isset($_POST['obrigatorio']) ? 1 : 0;
        $ordem       = SecurityHelper::sanitizeInt($_POST['ordem']        ?? 0);
        $opcoes      = $_POST['opcoes']      ?? []; // array de strings

        $tiposValidos = ['texto','numero','select','boolean','textarea','url'];
        if (empty($nome) || !in_array($tipo, $tiposValidos, true)) {
            $this->json(['ok' => false, 'msg' => 'Dados inválidos.']);
        }

        // Filtra opções (só para tipo select)
        $opcoesJson = null;
        if ($tipo === 'select' && !empty($opcoes)) {
            $opcoesFiltradas = array_values(array_filter(
                array_map('trim', (array)$opcoes)
            ));
            $opcoesJson = !empty($opcoesFiltradas)
                ? json_encode($opcoesFiltradas, JSON_UNESCAPED_UNICODE)
                : null;
        }

        $db   = Database::getInstance()->getConnection();
        $slug = $id > 0
                ? SlugHelper::unique($nome, 'caracteristicas', 'slug', (int)$id)
                : SlugHelper::unique($nome, 'caracteristicas', 'slug', 0);

        $campos = [
            'nome'        => $nome,
            'slug'        => $slug,
            'tipo'        => $tipo,
            'unidade'     => $unidade     ?: null,
            'placeholder' => $placeholder ?: null,
            'opcoes'      => $opcoesJson,
            'obrigatorio' => $obrigatorio,
            'ordem'       => $ordem,
        ];

        if ($id > 0) {
            $sets   = implode(', ', array_map(fn($k) => "{$k} = ?", array_keys($campos)));
            $params = array_values($campos);
            $params[] = $id;
            $db->prepare("UPDATE caracteristicas SET {$sets} WHERE id = ?")
               ->execute($params);
        } else {
            $cols   = implode(', ', array_keys($campos));
            $vals   = implode(', ', array_fill(0, count($campos), '?'));
            $db->prepare("INSERT INTO caracteristicas ({$cols}) VALUES ({$vals})")
               ->execute(array_values($campos));
            $id = (int)$db->lastInsertId();
        }

        $this->json(['ok' => true, 'msg' => 'Característica salva!', 'id' => $id]);
    }

    // ── Excluir ───────────────────────────────────────────
    public function excluir(): void {
        $this->verifyCsrf();
        $id = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
        if (!$id) $this->json(['ok' => false]);

        $db = Database::getInstance()->getConnection();

        // Verifica se tem valores em produtos
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM produto_caracteristicas WHERE caracteristica_id = ?"
        );
        $stmt->execute([$id]);
        if ((int)$stmt->fetchColumn() > 0) {
            $this->json([
                'ok'  => false,
                'msg' => 'Esta característica possui valores em produtos. Remova os valores antes.',
            ]);
        }

        $db->prepare("DELETE FROM categoria_caracteristicas WHERE caracteristica_id = ?")
           ->execute([$id]);
        $db->prepare("DELETE FROM caracteristicas WHERE id = ?")
           ->execute([$id]);

        $this->json(['ok' => true, 'msg' => 'Característica excluída.']);
    }

    // ── Reordenar (drag & drop) ───────────────────────────
    public function reordenar(): void {
        $this->verifyCsrf();
        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare("UPDATE caracteristicas SET ordem = ? WHERE id = ?");
        foreach ($_POST['ordens'] ?? [] as $ordem => $id) {
            $stmt->execute([(int)$ordem, (int)$id]);
        }
        $this->json(['ok' => true]);
    }

    // ── Ajax: lista características de uma categoria ───────
    public function porCategoria(): void {
        $catId = SecurityHelper::sanitizeInt($_GET['categoria_id'] ?? 0);
        if (!$catId) $this->json(['ok' => true, 'caracteristicas' => []]);

        $db   = Database::getInstance()->getConnection();

        // Busca também de categorias pai (herança)
        $ids  = $this->getCategoriasAncestral($catId, $db);
        if (empty($ids)) $this->json(['ok' => true, 'caracteristicas' => []]);

        $in   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare(
            "SELECT DISTINCT c.*,
                    cc.obrigatorio AS cat_obrigatorio,
                    cc.ordem       AS cat_ordem
             FROM caracteristicas c
             JOIN categoria_caracteristicas cc ON cc.caracteristica_id = c.id
             WHERE cc.categoria_id IN ({$in})
               AND c.ativo = 1
             ORDER BY cc.ordem ASC, c.ordem ASC, c.nome ASC"
        );
        $stmt->execute($ids);

        $this->json(['ok' => true, 'caracteristicas' => $stmt->fetchAll()]);
    }

    /**
     * Retorna a categoria e todas as suas ancestrais (para herança de características).
     */
    private function getCategoriasAncestral(int $catId, PDO $db): array {
        $ids  = [];
        $atual = $catId;

        while ($atual) {
            $ids[] = $atual;
            $stmt  = $db->prepare("SELECT parent_id FROM categorias WHERE id = ? LIMIT 1");
            $stmt->execute([$atual]);
            $atual = (int)($stmt->fetchColumn() ?: 0);
        }

        return $ids;
    }
}