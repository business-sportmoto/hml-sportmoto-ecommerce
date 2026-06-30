<?php
class CategoriasController extends Controller {

    public function __construct() {
        AuthHelper::requireAdmin();
    }

    // ── Listagem ──────────────────────────────────────────
    public function index(): void {
        $db   = Database::getInstance()->getConnection();
        $stmt = $db->query(
            "SELECT c.*,
                    p.nome AS parent_nome,
                    (SELECT COUNT(*) FROM categorias f WHERE f.parent_id = c.id) AS total_filhos,
                    (SELECT COUNT(*) FROM produtos pr WHERE pr.categoria_id = c.id AND pr.deleted_at IS NULL) AS total_produtos
             FROM categorias c
             LEFT JOIN categorias p ON p.id = c.parent_id
             ORDER BY c.parent_id ASC, c.ordem ASC, c.nome ASC"
        );
        $categorias = $stmt->fetchAll();

        // Monta árvore para exibição
        $arvore = $this->buildTree($categorias);

        $this->render('categorias/index', [
            'categorias' => $categorias,
            'arvore'     => $arvore,
        ], 'admin');
    }

    // ── Formulário criar ──────────────────────────────────
    public function criar(): void {
        $db      = Database::getInstance()->getConnection();
        $parents = $this->getParentsDisponiveis($db);

        $this->render('categorias/form', [
            'categoria' => null,
            'parents'   => $parents,
            'titulo'    => 'Nova categoria',
        ], 'admin');
    }

    // ── Formulário editar ─────────────────────────────────
    public function editar(int $id): void {
        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM categorias WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $categoria = $stmt->fetch();

        if (!$categoria) {
            Session::flash('error', 'Categoria não encontrada.');
            $this->redirect(BASE_URL . '/admin/categorias');
        }

        $parents = $this->getParentsDisponiveis($db, $id);

        $this->render('categorias/form', [
            'categoria' => $categoria,
            'parents'   => $parents,
            'titulo'    => 'Editar: ' . $categoria['nome'],
        ], 'admin');
    }

    // ── Salvar (criar ou editar) ──────────────────────────
    public function salvar(): void {
        $this->verifyCsrf();

        $id        = SecurityHelper::sanitizeInt($_POST['id']          ?? 0);
        $nome      = SecurityHelper::sanitizeString($_POST['nome']     ?? '');
        $parentId  = SecurityHelper::sanitizeInt($_POST['parent_id']   ?? 0);
        $descricao = SecurityHelper::sanitizeString($_POST['descricao']?? '');
        $ordem     = SecurityHelper::sanitizeInt($_POST['ordem']       ?? 0);
        $ativo     = isset($_POST['ativo']) ? 1 : 0;
        $destaque  = isset($_POST['destaque']) ? 1 : 0;

        $buscaMoto = isset($_POST['busca_moto']) ? 1 : 0;

        $meta_title         = SecurityHelper::sanitizeString($_POST['meta_title']       ?? null);
        $meta_description   = SecurityHelper::sanitizeString($_POST['meta_description']       ?? null);
        $meta_keywords      = SecurityHelper::sanitizeString($_POST['meta_keywords']       ?? null);
 
        if (empty($nome)) {
            $this->json(['ok' => false, 'msg' => 'Nome é obrigatório.']);
        }

        $db   = Database::getInstance()->getConnection();
        $slug = $id > 0
        ? SlugHelper::unique($nome, 'categorias', (string)$id)
        : SlugHelper::unique($nome, 'categorias');

        // Upload de imagem
        $imagem = null;
        if (!empty($_FILES['imagem']['tmp_name'])) {
            $uploadHelper = new UploadHelper();
            $upload = $uploadHelper->saveImage($_FILES['imagem'], 'categories', 200, 200);
            if (!$upload) {
                $this->json(['ok' => false, 'msg' => $upload['msg']]);
            }
            $imagem = $upload;
        }

        try {
            if ($id > 0) {
                // Editar
                $sql = "UPDATE categorias
                        SET nome = ?, slug = ?, parent_id = ?, descricao = ?,
                            ordem = ?, ativo = ?, destaque = ?, meta_title = ?, meta_description = ?, meta_keywords = ?, busca_moto = ?
                            " . ($imagem ? ", imagem = ?" : "") . "
                        WHERE id = ?";
                $params = [
                    $nome, $slug, $parentId ?: null, $descricao,
                    $ordem, $ativo, $destaque, $meta_title, $meta_description, $meta_keywords, $buscaMoto
                ];
                if ($imagem) $params[] = $imagem;
                $params[] = $id;

                $db->prepare($sql)->execute($params);
                $nav_tree = CacheHelper::delete('menu_categorias');

                $this->json(['ok' => true, 'msg' => 'Categoria atualizada!', 'id' => $id]);                
            } else {
                // Criar
                $db->prepare(
                    "INSERT INTO categorias
                     (nome, slug, parent_id, descricao, imagem, ordem, ativo, destaque, meta_title, meta_description, meta_keywords, busca_moto)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                )->execute([
                    $nome, $slug, $parentId ?: null, $descricao,
                    $imagem, $ordem, $ativo, $destaque, $meta_title, $meta_description, $meta_keywords, $buscaMoto  
                ]);
                $novoId = (int)$db->lastInsertId();
                $nav_tree = CacheHelper::delete('menu_categorias');
                $this->json(['ok' => true, 'msg' => 'Categoria criada!', 'id' => $novoId]);
            }
        } catch (Exception $e) {
            $this->json(['ok' => false, 'msg' => 'Erro ao salvar: ' . $e->getMessage()]);
        }
    }

    // ── Excluir ───────────────────────────────────────────
    public function excluir(): void {
        $this->verifyCsrf();

        $id = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
        if (!$id) $this->json(['ok' => false, 'msg' => 'ID inválido.']);

        $db = Database::getInstance()->getConnection();

        // Verifica se tem filhos
        $stmt = $db->prepare("SELECT COUNT(*) FROM categorias WHERE parent_id = ?");
        $stmt->execute([$id]);
        if ((int)$stmt->fetchColumn() > 0) {
            $this->json(['ok' => false, 'msg' => 'Remova as subcategorias antes de excluir.']);
        }

        // Verifica se tem produtos
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM produtos WHERE categoria_id = ? AND deleted_at IS NULL"
        );
        $stmt->execute([$id]);
        if ((int)$stmt->fetchColumn() > 0) {
            $this->json(['ok' => false, 'msg' => 'Existem produtos nesta categoria. Mova-os antes de excluir.']);
        }

        $db->prepare("DELETE FROM categorias WHERE id = ?")->execute([$id]);
        $this->json(['ok' => true, 'msg' => 'Categoria excluída.']);
    }

    // ── Ativar/desativar ──────────────────────────────────
    public function toggleAtivo(): void {
        $this->verifyCsrf();

        $id = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
        if (!$id) $this->json(['ok' => false]);

        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT ativo FROM categorias WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $atual = (int)$stmt->fetchColumn();
        $novo  = $atual ? 0 : 1;

        $db->prepare("UPDATE categorias SET ativo = ? WHERE id = ?")->execute([$novo, $id]);

        $this->json(['ok' => true, 'ativo' => $novo]);
    }

    // ── Reordenar via drag & drop ─────────────────────────
    public function reordenar(): void {
        $this->verifyCsrf();

        $ordens = $_POST['ordens'] ?? [];
        if (empty($ordens)) $this->json(['ok' => false]);

        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare("UPDATE categorias SET ordem = ? WHERE id = ?");

        foreach ($ordens as $ordem => $catId) {
            $stmt->execute([$ordem, (int)$catId]);
        }

        $this->json(['ok' => true]);
    }

    // ── Helpers ───────────────────────────────────────────
    private function buildTree(array $items, ?int $parentId = null, int $depth = 0): array {
        $tree = [];
        foreach ($items as $item) {
            $pid = $item['parent_id'] === null ? null : (int)$item['parent_id'];
            if ($pid === $parentId) {
                $item['depth']    = $depth;
                $item['children'] = $this->buildTree($items, (int)$item['id'], $depth + 1);
                $tree[]           = $item;
            }
        }
        return $tree;
    }

    private function flatTree(array $tree, array &$result = []): array {
        foreach ($tree as $node) {
            $children = $node['children'] ?? [];
            unset($node['children']);
            $result[] = $node;
            if ($children) $this->flatTree($children, $result);
        }
        return $result;
    }

    private function getParentsDisponiveis(PDO $db, int $excludeId = 0): array {
        $stmt = $db->query(
            "SELECT id, nome, parent_id FROM categorias ORDER BY nome ASC"
        );
        $all = $stmt->fetchAll();

        // Remove a categoria atual e seus filhos para evitar loop
        if ($excludeId > 0) {
            $filhos = $this->getFilhosIds($all, $excludeId);
            $filhos[] = $excludeId;
            $all = array_filter($all, fn($c) => !in_array($c['id'], $filhos));
        }

        return array_values($all);
    }

    private function getFilhosIds(array $all, int $parentId): array {
        $ids = [];
        foreach ($all as $item) {
            if ((int)$item['parent_id'] === $parentId) {
                $ids[] = (int)$item['id'];
                $ids   = array_merge($ids, $this->getFilhosIds($all, (int)$item['id']));
            }
        }
        return $ids;
    }

    // Adicionar ao CategoriasController existente:

    // Salva vínculo de características em uma categoria
    public function salvarCaracteristicas(): void {
        $this->verifyCsrf();

        $catId = SecurityHelper::sanitizeInt($_POST['categoria_id'] ?? 0);
        if (!$catId) $this->json(['ok' => false, 'msg' => 'Categoria inválida.']);

        $vinculos = $_POST['vinculos'] ?? []; // [{id, obrigatorio, ordem}]

        $db = Database::getInstance()->getConnection();
        $db->beginTransaction();

        try {
            // Remove todos os vínculos atuais
            $db->prepare(
                "DELETE FROM categoria_caracteristicas WHERE categoria_id = ?"
            )->execute([$catId]);

            if (!empty($vinculos)) {
                $stmt = $db->prepare(
                    "INSERT INTO categoria_caracteristicas
                    (categoria_id, caracteristica_id, obrigatorio, ordem)
                    VALUES (?, ?, ?, ?)"
                );
                foreach ($vinculos as $i => $v) {
                    $charId = SecurityHelper::sanitizeInt($v['id']         ?? 0);
                    $obrig  = isset($v['obrigatorio']) ? 1 : 0;
                    $ordem  = SecurityHelper::sanitizeInt($v['ordem'] ?? $i);
                    if ($charId) {
                        $stmt->execute([$catId, $charId, $obrig, $ordem]);
                    }
                }
            }

            $db->commit();
            $this->json(['ok' => true, 'msg' => 'Características da categoria salvas!']);

        } catch (\Exception $e) {
            $db->rollBack();
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }

    // Ajax: busca características vinculadas a uma categoria
    public function getCaracteristicas(): void {
        $catId = SecurityHelper::sanitizeInt($_GET['categoria_id'] ?? 0);
        if (!$catId) $this->json(['ok' => false, 'caracteristicas' => []]);

        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare(
            "SELECT c.*, cc.obrigatorio AS cat_obrigatorio, cc.ordem AS cat_ordem
            FROM caracteristicas c
            JOIN categoria_caracteristicas cc ON cc.caracteristica_id = c.id
            WHERE cc.categoria_id = ? AND c.ativo = 1
            ORDER BY cc.ordem ASC, c.nome ASC"
        );
        $stmt->execute([$catId]);

        $this->json(['ok' => true, 'caracteristicas' => $stmt->fetchAll()]);
    }
}