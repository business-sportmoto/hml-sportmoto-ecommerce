<?php
// admin/controllers/ProductAdminController.php

class ProductAdminController extends Controller {

    private PDO $db;

    public function __construct() {
        AuthHelper::requirePermission('produtos');
        $this->db = Database::getInstance()->getConnection();
    }

    public function index(): void {
        $page    = max(1, (int)($_GET['pagina'] ?? 1));
        $busca   = SecurityHelper::sanitizeString($_GET['q'] ?? '');
        $catId   = SecurityHelper::sanitizeInt($_GET['categoria'] ?? 0);
        $status  = $_GET['status'] ?? 'todos';

        $where  = "p.deleted_at IS NULL";
        $params = [];

        if ($busca) {
            $where   .= " AND (p.nome LIKE ? OR p.sku LIKE ?)";
            $params[] = "%{$busca}%";
            $params[] = "%{$busca}%";
        }
        if ($catId)      { $where .= " AND p.categoria_id = ?"; $params[] = $catId; }
        if ($status === 'ativo')   { $where .= " AND p.ativo = 1"; }
        if ($status === 'inativo') { $where .= " AND p.ativo = 0"; }
        if ($status === 'sem_estoque') { $where .= " AND p.estoque_total = 0 AND p.ativo = 1"; }

        $perPage = 20;
        $offset  = ($page - 1) * $perPage;

        $stmtCount = $this->db->prepare(
            "SELECT COUNT(*) FROM produtos p WHERE {$where}"
        );
        $stmtCount->execute($params);
        $total = (int)$stmtCount->fetchColumn();

        $stmtList = $this->db->prepare(
            "SELECT p.*, c.nome AS categoria_nome, m.nome AS marca_nome,
                    pi.arquivo AS imagem
             FROM produtos p
             LEFT JOIN categorias c ON c.id = p.categoria_id
             LEFT JOIN marcas m     ON m.id  = p.marca_id
             LEFT JOIN produto_imagens pi ON pi.produto_id = p.id AND pi.principal = 1
             WHERE {$where}
             ORDER BY p.criado_em DESC
             LIMIT ? OFFSET ?"
        );
        $stmtList->execute(array_merge($params, [$perPage, $offset]));
        $produtos = $stmtList->fetchAll();

        $categorias = $this->db->query(
            "SELECT id, nome FROM categorias WHERE ativo=1 ORDER BY nome"
        )->fetchAll();

        $pag = new PaginationHelper($total, $page, ADMIN_URL . '/produtos');

        $this->render('products/index', array_merge($pag->toArray(), [
            'produtos'   => $produtos,
            'categorias' => $categorias,
            'busca'      => $busca,
            'catId'      => $catId,
            'status'     => $status,
        ]), 'admin');
    }

    public function create(): void {
        $this->render('products/form', [
            'produto'    => null,
            'categorias' => $this->getCategorias(),
            'marcas'     => $this->getMarcas(),
            'imagens'    => [],
            'variacoes'  => [],
            'tags'       => '',
        ], 'admin');
    }

    public function store(): void {
        $this->verifyCsrf();
        $data = $this->parseProductData();
        $errors = $this->validateProduct($data);

        if ($errors) $this->json(['ok' => false, 'errors' => $errors]);

        $data['slug'] = SlugHelper::unique($data['nome'], 'produtos');
        $productId    = (new Product())->insert($data);

        $this->saveVariations($productId, $_POST['variacoes'] ?? []);
        $this->saveTags($productId, $_POST['tags'] ?? '');
        $this->saveInitialStock($productId, (int)($data['estoque_total'] ?? 0));

        $this->json(['ok' => true, 'redirect' => ADMIN_URL . '/produtos/editar/' . $productId,
                     'msg' => 'Produto criado com sucesso!']);
    }

    public function edit(string $id): void {
        $product   = (new Product())->find((int)$id);
        if (!$product) { $this->redirect(ADMIN_URL . '/produtos'); }

        $imagens   = $this->db->prepare(
            "SELECT * FROM produto_imagens WHERE produto_id = ? ORDER BY principal DESC, ordem ASC"
        );
        $imagens->execute([$id]);

        $variacoes = $this->db->prepare(
            "SELECT pv.*, GROUP_CONCAT(pvo.id,'|',pvo.valor,'|',COALESCE(pvo.cor_hex,'')
             ORDER BY pvo.ordem SEPARATOR ';;') AS opcoes
             FROM produto_variacoes pv
             LEFT JOIN produto_variacao_opcoes pvo ON pvo.variacao_id = pv.id
             WHERE pv.produto_id = ?
             GROUP BY pv.id ORDER BY pv.ordem"
        );
        $variacoes->execute([$id]);

        $stmtTags = $this->db->prepare(
            "SELECT GROUP_CONCAT(t.nome SEPARATOR ', ') AS tags
             FROM produto_tags pt JOIN tags t ON t.id = pt.tag_id
             WHERE pt.produto_id = ?"
        );
        $stmtTags->execute([$id]);

        $estoque = $this->db->prepare(
            "SELECT * FROM produto_estoque WHERE produto_id = ?"
        );
        $estoque->execute([$id]);

        $this->render('products/form', [
            'produto'    => $product,
            'categorias' => $this->getCategorias(),
            'marcas'     => $this->getMarcas(),
            'imagens'    => $imagens->fetchAll(),
            'variacoes'  => $variacoes->fetchAll(),
            'tags'       => $stmtTags->fetchColumn() ?? '',
            'estoque'    => $estoque->fetchAll(),
        ], 'admin');
    }

    public function update(string $id): void {
        $this->verifyCsrf();
        $productId = (int)$id;
        $product   = (new Product())->find($productId);
        if (!$product) $this->json(['ok' => false, 'msg' => 'Produto não encontrado.']);

        $data   = $this->parseProductData();
        $errors = $this->validateProduct($data, $productId);
        if ($errors) $this->json(['ok' => false, 'errors' => $errors]);

        // Regenera slug apenas se o nome mudou
        // if ($data['nome'] !== $product['nome']) {
        //     $data['slug'] = SlugHelper::unique($data['nome'], 'produtos', 'slug', $productId);
        // } else {
        //     unset($data['slug']);
        // } não regenera, é importante manter o mesmo link, mesmo se mudar o nome.

        (new Product())->update($productId, $data);
        $this->saveVariations($productId, $_POST['variacoes'] ?? []);
        $this->saveTags($productId, $_POST['tags'] ?? '');

        $this->json(['ok' => true, 'msg' => 'Produto atualizado!']);
    }

    public function delete(): void {
        $this->verifyCsrf();
        $id = SecurityHelper::sanitizeInt($_POST['produto_id'] ?? 0);
        (new Product())->delete($id);
        $this->json(['ok' => true, 'msg' => 'Produto removido.']);
    }

    // ── Upload de imagem ──────────────────────────────────────

    public function uploadImage(): void {
        $this->verifyCsrf();
        $productId = SecurityHelper::sanitizeInt($_POST['produto_id'] ?? 0);

        if (empty($_FILES['imagem']['name'])) {
            $this->json(['ok' => false, 'msg' => 'Nenhuma imagem enviada.']);
        }
        if (!SecurityHelper::validateUploadedImage($_FILES['imagem'])) {
            $this->json(['ok' => false, 'msg' => 'Imagem inválida.']);
        }

        $upload = new UploadHelper();
        $thumb  = $upload->saveImage($_FILES['imagem'], 'products', IMG_PRODUCT_W, IMG_PRODUCT_H);

        if (!$thumb) $this->json(['ok' => false, 'msg' => 'Erro ao salvar imagem.']);

        // Verifica se é a primeira imagem (define como principal)
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM produto_imagens WHERE produto_id = ?"
        );
        $stmt->execute([$productId]);
        $isPrincipal = (int)$stmt->fetchColumn() === 0 ? 1 : 0;

        $this->db->prepare(
            "INSERT INTO produto_imagens (produto_id, arquivo, principal, ordem)
             VALUES (?, ?, ?, (SELECT COALESCE(MAX(ordem),0)+1 FROM produto_imagens WHERE produto_id = ?))"
        )->execute([$productId, $thumb, $isPrincipal, $productId]);

        $imgId = (int) $this->db->lastInsertId();

        $this->json([
            'ok'       => true,
            'img_id'   => $imgId,
            'arquivo'  => $thumb,
            'url'      => UPLOAD_URL . '/products/' . $thumb,
            'principal'=> $isPrincipal,
        ]);
    }

    public function deleteImage(): void {
        $this->verifyCsrf();
        $imgId = SecurityHelper::sanitizeInt($_POST['img_id'] ?? 0);
        $stmt  = $this->db->prepare("SELECT * FROM produto_imagens WHERE id = ? LIMIT 1");
        $stmt->execute([$imgId]);
        $img = $stmt->fetch();

        if (!$img) $this->json(['ok' => false, 'msg' => 'Imagem não encontrada.']);

        // Remove arquivo físico
        @unlink(UPLOAD_PATH . '/products/' . $img['arquivo']);

        $this->db->prepare("DELETE FROM produto_imagens WHERE id = ?")->execute([$imgId]);

        // Se era principal, define a próxima como principal
        if ($img['principal']) {
            $this->db->prepare(
                "UPDATE produto_imagens SET principal = 1
                 WHERE produto_id = ? ORDER BY ordem ASC LIMIT 1"
            )->execute([$img['produto_id']]);
        }

        $this->json(['ok' => true]);
    }

    public function setMainImage(): void {
        $this->verifyCsrf();
        $imgId     = SecurityHelper::sanitizeInt($_POST['img_id']     ?? 0);
        $productId = SecurityHelper::sanitizeInt($_POST['produto_id'] ?? 0);

        $this->db->prepare(
            "UPDATE produto_imagens SET principal = 0 WHERE produto_id = ?"
        )->execute([$productId]);
        $this->db->prepare(
            "UPDATE produto_imagens SET principal = 1 WHERE id = ? AND produto_id = ?"
        )->execute([$imgId, $productId]);

        $this->json(['ok' => true]);
    }

    public function updateStock(): void {
        $this->verifyCsrf();
        $productId   = SecurityHelper::sanitizeInt($_POST['produto_id']   ?? 0);
        $combinacao  = SecurityHelper::sanitizeString($_POST['combinacao']  ?? '');
        $quantidade  = SecurityHelper::sanitizeInt($_POST['quantidade']    ?? 0);
        $precoExtra  = SecurityHelper::sanitizeFloat($_POST['preco_extra'] ?? 0);
        $skuVariacao = SecurityHelper::sanitizeString($_POST['sku_variacao'] ?? '');

        $this->db->prepare(
            "INSERT INTO produto_estoque (produto_id, combinacao_opcoes, quantidade, preco_extra, sku_variacao)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE quantidade=VALUES(quantidade),
             preco_extra=VALUES(preco_extra), sku_variacao=VALUES(sku_variacao)"
        )->execute([$productId, $combinacao, $quantidade, $precoExtra, $skuVariacao]);

        // Atualiza estoque total
        $this->db->prepare(
            "UPDATE produtos SET estoque_total = (
                SELECT COALESCE(SUM(quantidade),0) FROM produto_estoque WHERE produto_id = ?
             ) WHERE id = ?"
        )->execute([$productId, $productId]);

        $this->json(['ok' => true, 'msg' => 'Estoque atualizado.']);
    }

    // ── Helpers privados ──────────────────────────────────────

    private function parseProductData(): array {
        $ficha = [];
        $atributos = $_POST['ficha_attr'] ?? [];
        $valores   = $_POST['ficha_val']  ?? [];
        foreach ($atributos as $i => $attr) {
            if (!empty(trim($attr)) && isset($valores[$i])) {
                $ficha[trim($attr)] = trim($valores[$i]);
            }
        }

        return [
            'nome'             => SecurityHelper::sanitizeString($_POST['nome']             ?? ''),
            'sku'              => SecurityHelper::sanitizeString($_POST['sku']              ?? ''),
            'categoria_id'     => SecurityHelper::sanitizeInt(  $_POST['categoria_id']     ?? 0)  ?: null,
            'marca_id'         => SecurityHelper::sanitizeInt(  $_POST['marca_id']         ?? 0)  ?: null,
            'descricao_curta'  => SecurityHelper::sanitizeString($_POST['descricao_curta']  ?? ''),
            'descricao'        => $_POST['descricao'] ?? '',
            'ficha_tecnica'    => !empty($ficha) ? json_encode($ficha, JSON_UNESCAPED_UNICODE) : null,
            'preco'            => SecurityHelper::sanitizeFloat($_POST['preco']             ?? 0),
            'preco_promo'      => SecurityHelper::sanitizeFloat($_POST['preco_promo']       ?? 0) ?: null,
            'promo_inicio'     => !empty($_POST['promo_inicio']) ? $_POST['promo_inicio']   : null,
            'promo_fim'        => !empty($_POST['promo_fim'])    ? $_POST['promo_fim']      : null,
            'custo'            => SecurityHelper::sanitizeFloat($_POST['custo']             ?? 0) ?: null,
            'peso_kg'          => SecurityHelper::sanitizeFloat($_POST['peso_kg']           ?? 0) ?: null,
            'comprimento_cm'   => SecurityHelper::sanitizeFloat($_POST['comprimento_cm']    ?? 0) ?: null,
            'largura_cm'       => SecurityHelper::sanitizeFloat($_POST['largura_cm']        ?? 0) ?: null,
            'altura_cm'        => SecurityHelper::sanitizeFloat($_POST['altura_cm']         ?? 0) ?: null,
            'estoque_total'    => SecurityHelper::sanitizeInt(  $_POST['estoque_total']     ?? 0),
            'estoque_minimo'   => SecurityHelper::sanitizeInt(  $_POST['estoque_minimo']    ?? 0),
            'tem_variacao'     => isset($_POST['tem_variacao']) ? 1 : 0,
            'destaque'         => isset($_POST['destaque'])     ? 1 : 0,
            'lancamento'       => isset($_POST['lancamento'])   ? 1 : 0,
            'ativo'            => isset($_POST['ativo'])        ? 1 : 0,
            'meta_title'       => SecurityHelper::sanitizeString($_POST['meta_title']       ?? ''),
            'meta_description' => SecurityHelper::sanitizeString($_POST['meta_description'] ?? ''),
            'meta_keywords'    => SecurityHelper::sanitizeString($_POST['meta_keywords']    ?? ''),
        ];
    }

    private function validateProduct(array $data, int $ignoreId = 0): array {
        $errors = [];
        if (mb_strlen($data['nome']) < 3)  $errors[] = 'Nome muito curto.';
        if (empty($data['sku']))            $errors[] = 'SKU obrigatório.';
        if ($data['preco'] <= 0)            $errors[] = 'Preço inválido.';

        // SKU único
        $stmt = $this->db->prepare(
            "SELECT id FROM produtos WHERE sku = ? AND id != ? AND deleted_at IS NULL LIMIT 1"
        );
        $stmt->execute([$data['sku'], $ignoreId]);
        if ($stmt->fetchColumn()) $errors[] = 'SKU já está em uso.';

        return $errors;
    }

    private function saveVariations(int $productId, array $variacoes): void {
        // Remove variações antigas
        $this->db->prepare(
            "DELETE FROM produto_variacoes WHERE produto_id = ?"
        )->execute([$productId]);

        foreach ($variacoes as $ordem => $var) {
            if (empty(trim($var['nome'] ?? ''))) continue;

            $this->db->prepare(
                "INSERT INTO produto_variacoes (produto_id, nome, ordem) VALUES (?,?,?)"
            )->execute([$productId, trim($var['nome']), $ordem]);

            $varId = (int) $this->db->lastInsertId();

            foreach ($var['opcoes'] ?? [] as $oOrdem => $opt) {
                if (empty(trim($opt['valor'] ?? ''))) continue;
                $this->db->prepare(
                    "INSERT INTO produto_variacao_opcoes
                     (variacao_id, valor, cor_hex, ordem)
                     VALUES (?,?,?,?)"
                )->execute([
                    $varId,
                    trim($opt['valor']),
                    !empty($opt['cor_hex']) ? $opt['cor_hex'] : null,
                    $oOrdem,
                ]);
            }
        }
    }

    private function saveTags(int $productId, string $tagsStr): void {
        $this->db->prepare(
            "DELETE FROM produto_tags WHERE produto_id = ?"
        )->execute([$productId]);

        $tags = array_filter(array_map('trim', explode(',', $tagsStr)));
        foreach ($tags as $tagNome) {
            if (empty($tagNome)) continue;
            $slug = SlugHelper::make($tagNome);

            // Insere tag se não existir
            $this->db->prepare(
                "INSERT INTO tags (nome, slug) VALUES (?,?)
                 ON DUPLICATE KEY UPDATE nome = VALUES(nome)"
            )->execute([$tagNome, $slug]);

            $stmt = $this->db->prepare("SELECT id FROM tags WHERE slug = ? LIMIT 1");
            $stmt->execute([$slug]);
            $tagId = $stmt->fetchColumn();

            if ($tagId) {
                $this->db->prepare(
                    "INSERT IGNORE INTO produto_tags (produto_id, tag_id) VALUES (?,?)"
                )->execute([$productId, $tagId]);
            }
        }
    }

    private function saveInitialStock(int $productId, int $qty): void {
        $this->db->prepare(
            "INSERT INTO produto_estoque (produto_id, combinacao_opcoes, quantidade)
             VALUES (?, '', ?)"
        )->execute([$productId, $qty]);
    }

    private function getCategorias(): array {
        return $this->db->query(
            "SELECT id, nome, parent_id FROM categorias WHERE ativo=1 ORDER BY nome"
        )->fetchAll();
    }

    private function getMarcas(): array {
        return $this->db->query(
            "SELECT id, nome FROM marcas WHERE ativo=1 ORDER BY nome"
        )->fetchAll();
    }
}