<?php
class AtributosController extends Controller {

    public function __construct() {
        AuthHelper::requireAdmin();
    }

    // Adicionar no AtributosController

    public function index(): void {
        $db   = Database::getInstance()->getConnection();
        $stmt = $db->query(
            "SELECT at.*,
                    COUNT(DISTINCT av.id)  AS total_valores,
                    COUNT(DISTINCT sa.sku_id)  AS uso_skus,
                    COUNT(DISTINCT paa.produto_id) AS uso_produtos
            FROM atributo_tipos at
            LEFT JOIN atributo_valores av  ON av.atributo_tipo_id = at.id
            LEFT JOIN sku_atributos sa     ON sa.atributo_tipo_id = at.id
            LEFT JOIN produto_atributos_agrupadores paa ON paa.atributo_tipo_id = at.id
            GROUP BY at.id
            ORDER BY at.papel ASC, at.ordenacao ASC, at.nome ASC"
        );
        $atributos = $stmt->fetchAll();

        // Carrega valores de todos os tipos
        $stmtV = $db->query(
            "SELECT * FROM atributo_valores ORDER BY atributo_tipo_id ASC, ordem ASC"
        );
        $valoresRaw = $stmtV->fetchAll();

        // Agrupa por tipo
        $valoresPorTipo = [];
        foreach ($valoresRaw as $v) {
            $valoresPorTipo[$v['atributo_tipo_id']][] = $v;
        }

        $this->render('atributos/index', [
            'atributos'      => $atributos,
            'valoresPorTipo' => $valoresPorTipo,
        ], 'admin');
    }

    public function salvarValor(): void {
        $this->verifyCsrf();

        $id      = SecurityHelper::sanitizeInt($_POST['id']               ?? 0);
        $tipoId  = SecurityHelper::sanitizeInt($_POST['atributo_tipo_id'] ?? 0);
        $valor   = SecurityHelper::sanitizeString($_POST['valor']         ?? '');
        $hex     = SecurityHelper::sanitizeString($_POST['valor_hex']     ?? '');
        $ordem   = SecurityHelper::sanitizeInt($_POST['ordem']            ?? 0);

        if (!$tipoId || empty($valor)) {
            $this->json(['ok' => false, 'msg' => 'Dados inválidos.']);
        }

        if ($hex && !preg_match('/^#[0-9a-fA-F]{6}$/', $hex)) $hex = null;

        $db = Database::getInstance()->getConnection();

        if ($id > 0) {
            $db->prepare(
                "UPDATE atributo_valores SET valor=?, valor_hex=?, ordem=? WHERE id=?"
            )->execute([$valor, $hex ?: null, $ordem, $id]);
        } else {
            $db->prepare(
                "INSERT INTO atributo_valores (atributo_tipo_id, valor, valor_hex, ordem)
                VALUES (?,?,?,?)"
            )->execute([$tipoId, $valor, $hex ?: null, $ordem]);
            $id = (int)$db->lastInsertId();
        }

        $this->json([
            'ok'      => true,
            'msg'     => 'Valor salvo!',
            'id'      => $id,
            'valor'   => $valor,
            'valor_hex' => $hex,
        ]);
    }

    public function excluirValor(): void {
        $this->verifyCsrf();
        $id = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
        if (!$id) $this->json(['ok' => false]);

        Database::getInstance()->getConnection()
            ->prepare("DELETE FROM atributo_valores WHERE id=?")
            ->execute([$id]);

        $this->json(['ok' => true, 'msg' => 'Valor removido.']);
    }

    // Endpoint Ajax — retorna valores de um tipo
    public function valores(): void {
        $tipoId = SecurityHelper::sanitizeInt($_GET['tipo_id'] ?? 0);
        if (!$tipoId) $this->json(['ok' => false, 'valores' => []]);

        $stmt = Database::getInstance()->getConnection()->prepare(
            "SELECT id, valor, valor_hex, ordem
            FROM atributo_valores
            WHERE atributo_tipo_id = ?
            ORDER BY ordem ASC, valor ASC"
        );
        $stmt->execute([$tipoId]);
        $this->json(['ok' => true, 'valores' => $stmt->fetchAll()]);
    }

    public function salvar(): void {
        $this->verifyCsrf();

        $id         = SecurityHelper::sanitizeInt($_POST['id']          ?? 0);
        $nome       = SecurityHelper::sanitizeString($_POST['nome']      ?? '');
        $slug       = SecurityHelper::sanitizeString($_POST['slug']      ?? '');
        $papel      = in_array($_POST['papel'] ?? '', ['agrupador','variacao'])
                      ? $_POST['papel'] : 'variacao';
        $tipoDisplay= in_array($_POST['tipo_display'] ?? '', ['text','color_swatch','button','select'])
                      ? $_POST['tipo_display'] : 'button';
        $ordenacao  = SecurityHelper::sanitizeInt($_POST['ordenacao']    ?? 0);

        if (empty($nome)) {
            $this->json(['ok' => false, 'msg' => 'Nome obrigatório.']);
        }

        $db   = Database::getInstance()->getConnection();

        // Auto slug
        if (empty($slug)) {
            $slug = $id > 0
                    ? SlugHelper::unique($nome, 'atributo_tipos', (string)$id, 'slug')
                    : SlugHelper::unique($nome, 'atributo_tipos', null, 'slug');
        }

        if ($id > 0) {
            $db->prepare(
                "UPDATE atributo_tipos
                 SET nome=?, slug=?, papel=?, tipo_display=?, ordenacao=?
                 WHERE id=?"
            )->execute([$nome, $slug, $papel, $tipoDisplay, $ordenacao, $id]);
        } else {
            $db->prepare(
                "INSERT INTO atributo_tipos (nome, slug, papel, tipo_display, ordenacao)
                 VALUES (?,?,?,?,?)"
            )->execute([$nome, $slug, $papel, $tipoDisplay, $ordenacao]);
            $id = (int)$db->lastInsertId();
        }

        $this->json(['ok' => true, 'msg' => 'Atributo salvo!', 'id' => $id]);
    }

    public function excluir(): void {
        $this->verifyCsrf();
        $id = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
        if (!$id) $this->json(['ok' => false]);

        $db = Database::getInstance()->getConnection();

        // Verifica uso
        $stmt = $db->prepare(
            "SELECT
                (SELECT COUNT(*) FROM sku_atributos WHERE atributo_tipo_id = ?) +
                (SELECT COUNT(*) FROM produto_atributos_agrupadores WHERE atributo_tipo_id = ?)
             AS total"
        );
        $stmt->execute([$id, $id]);
        if ((int)$stmt->fetchColumn() > 0) {
            $this->json([
                'ok'  => false,
                'msg' => 'Este atributo está em uso por produtos/SKUs. Remova o vínculo antes.',
            ]);
        }

        $db->prepare("DELETE FROM atributo_tipos WHERE id = ?")->execute([$id]);
        $this->json(['ok' => true, 'msg' => 'Atributo excluído.']);
    }

    public function reordenar(): void {
        $this->verifyCsrf();
        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare("UPDATE atributo_tipos SET ordenacao=? WHERE id=?");
        foreach ($_POST['ordens'] ?? [] as $ordem => $id) {
            $stmt->execute([$ordem, (int)$id]);
        }
        $this->json(['ok' => true]);
    }
}