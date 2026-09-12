<?php
class MarcasController extends Controller {

    public function __construct() {
        // Catálogo: super, gerente e editor (CLAUDE.md §4.2). Até 11/09 era
        // requireAdmin(). O autocomplete de marcas (/api/buscar-marcas) é
        // do AdminApiController e segue aberto a todo cargo.
        AuthHelper::requireAdminLevel('super', 'gerente', 'editor');
    }

    public function index(): void {
        $db   = Database::getInstance()->getConnection();
        $stmt = $db->query(
            "SELECT m.*,
                    (SELECT COUNT(*) FROM produtos p
                     WHERE p.marca_id = m.id AND p.deleted_at IS NULL) AS total_produtos
             FROM marcas m
             ORDER BY m.nome ASC"
        );
        $marcas = $stmt->fetchAll();

        $this->render('marcas/index', ['marcas' => $marcas], 'admin');
    }

    public function criar(): void {
        $this->render('marcas/form', [
            'marca'  => null,
            'titulo' => 'Nova marca',
        ], 'admin');
    }

    public function editar(int $id): void {
        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM marcas WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $marca = $stmt->fetch();

        if (!$marca) {
            Session::flash('error', 'Marca não encontrada.');
            $this->redirect(BASE_URL . '/admin/marcas');
        }

        $this->render('marcas/form', [
            'marca'  => $marca,
            'titulo' => 'Editar: ' . $marca['nome'],
        ], 'admin');
    }

    public function salvar(): void {
        $this->verifyCsrf();

        $id          = SecurityHelper::sanitizeInt($_POST['id']              ?? 0);
        $nome        = SecurityHelper::sanitizeString($_POST['nome']         ?? '');
        $descricao   = SecurityHelper::sanitizeString($_POST['descricao']    ?? '');
        $site        = SecurityHelper::sanitizeString($_POST['site']         ?? '');
        $destaque    = isset($_POST['destaque'])  ? 1 : 0;
        $ativo       = isset($_POST['ativo'])     ? 1 : 0;
        $metaTitle   = SecurityHelper::sanitizeString($_POST['meta_title']       ?? '');
        $metaDesc    = SecurityHelper::sanitizeString($_POST['meta_description'] ?? '');
        $metaKey    = SecurityHelper::sanitizeString($_POST['meta_keywords'] ?? '');

        // No método salvar(), adicionar junto com as outras variáveis:
        $bgCor = SecurityHelper::sanitizeString($_POST['bg_cor'] ?? '');
        // Valida formato hex
        if ($bgCor && !preg_match('/^#[0-9a-fA-F]{6}$/', $bgCor)) {
            $bgCor = null;
        }



        if (empty($nome)) {
            $this->json(['ok' => false, 'msg' => 'Nome é obrigatório.']);
        }

        $db   = Database::getInstance()->getConnection();

        // Guardado antes: URL trocada vira 301 depois da gravação (fase 4).
        $slugAntigo = '';
        if ($id > 0) {
            $stSlug = $db->prepare("SELECT slug FROM marcas WHERE id = ? LIMIT 1");
            $stSlug->execute([$id]);
            $slugAntigo = (string) ($stSlug->fetchColumn() ?: '');
        }

        $slug = $this->resolverSlug($db, $id, $nome);

        // Upload do logo
        $logo = null;
        if (!empty($_FILES['logo']['tmp_name'])) {
            $ext     = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','webp','svg'];

            if (!in_array($ext, $allowed)) {
                $this->json(['ok' => false, 'msg' => 'Formato inválido. Use JPG, PNG, WEBP ou SVG.']);
            }
            if ($_FILES['logo']['size'] > 2 * 1024 * 1024) {
                $this->json(['ok' => false, 'msg' => 'Logo muito grande. Máximo 2MB.']);
            }

            $dir = UPLOAD_PATH . '/brands/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);

            $logo    = 'brand_' . uniqid() . '.' . $ext;
            if (!move_uploaded_file($_FILES['logo']['tmp_name'], $dir . $logo)) {
                $this->json(['ok' => false, 'msg' => 'Erro ao salvar logo.']);
            }
        }

        try {
            if ($id > 0) {
                $logoSql = $logo ? ', logo = ?' : '';
                $sql     = "UPDATE marcas
                            SET nome = ?, slug = ?, descricao = ?, site = ?,
                                destaque = ?, ativo = ?,
                                meta_title = ?, meta_description = ?, meta_keywords = ?, bg_cor = ?
                                {$logoSql}
                            WHERE id = ?";
                $params  = [
                    $nome, $slug, $descricao, $site ?: null,
                    $destaque, $ativo,
                    $metaTitle ?: null, $metaDesc ?: null, $metaKey ?: null, $bgCor
                ];
                if ($logo) $params[] = $logo;
                $params[] = $id;
                $db->prepare($sql)->execute($params);

                if ($slugAntigo !== '' && $slugAntigo !== $slug) {
                    (new RedirecionamentoService($db))->aoTrocarSlug(
                        '/marca/' . $slugAntigo, '/marca/' . $slug, AuthHelper::usuarioId()
                    );
                }

                $this->json(['ok' => true, 'msg' => 'Marca atualizada!', 'id' => $id]);
            } else {
                $db->prepare(
                    "INSERT INTO marcas
                     (nome, slug, descricao, logo, site, destaque, ativo, meta_title, meta_description, meta_keywords, bg_cor)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                )->execute([
                    $nome, $slug, $descricao, $logo, $site ?: null,
                    $destaque, $ativo,
                    $metaTitle ?: null, $metaDesc ?: null, $metaKey ?: null, $bgCor
                ]);
                $novoId = (int)$db->lastInsertId();
                $this->json(['ok' => true, 'msg' => 'Marca criada!', 'id' => $novoId]);
            }
        } catch (Exception $e) {
            $this->json(['ok' => false, 'msg' => 'Erro ao salvar: ' . $e->getMessage()]);
        }
    }

    /**
     * Slug da marca ao salvar — a URL é ativo de SEO, não campo derivado.
     *
     *  1. Registro existente mantém o slug que tem: renomear NÃO muda a URL.
     *  2. Muda só quando o admin edita o campo "Slug" de propósito.
     *  3. Registro novo: o campo, ou o nome se ele vier vazio.
     *
     * Unicidade ignorando o próprio registro (há UNIQUE uk_slug).
     *
     * ANTES: SlugHelper::unique($nome, 'marcas', (string)$id) — o $id ia na
     * posição de $column (`WHERE 5 = ?`, que nunca casa). Sem checagem de
     * unicidade (dois nomes com o mesmo slug estouravam o uk_slug) e a URL
     * regerada pelo nome a cada save. O mesmo bug que o produto teve — ver
     * catalogo-url-canonica no Vault. Achado na fase 0 do rastreador de 404.
     */
    private function resolverSlug(PDO $db, int $id, string $nome): string
    {
        $slugPost = SlugHelper::make((string)($_POST['slug'] ?? ''));

        if ($id > 0) {
            $stmt = $db->prepare("SELECT slug FROM marcas WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $atual = trim((string)($stmt->fetchColumn() ?: ''));

            // Campo vazio ou igual ao atual: a URL fica como está.
            if ($atual !== '' && ($slugPost === '' || $slugPost === $atual)) {
                return $atual;
            }
        }

        $base = $slugPost !== '' ? $slugPost : SlugHelper::make($nome);
        if ($base === '') {
            $base = 'marca';
        }

        return SlugHelper::unique($base, 'marcas', 'slug', $id);
    }

    public function excluir(): void {
        $this->verifyCsrf();

        $id = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
        if (!$id) $this->json(['ok' => false, 'msg' => 'ID inválido.']);

        $db   = Database::getInstance()->getConnection();

        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM produtos WHERE marca_id = ? AND deleted_at IS NULL"
        );
        $stmt->execute([$id]);
        if ((int)$stmt->fetchColumn() > 0) {
            $this->json(['ok' => false, 'msg' => 'Existem produtos com esta marca. Remova-os antes.']);
        }

        $db->prepare("DELETE FROM marcas WHERE id = ?")->execute([$id]);
        $this->json(['ok' => true, 'msg' => 'Marca excluída.']);
    }

    public function toggleAtivo(): void {
        $this->verifyCsrf();
        $id = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
        if (!$id) $this->json(['ok' => false]);

        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT ativo FROM marcas WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $novo = (int)$stmt->fetchColumn() ? 0 : 1;

        $db->prepare("UPDATE marcas SET ativo = ? WHERE id = ?")->execute([$novo, $id]);
        $this->json(['ok' => true, 'ativo' => $novo]);
    }
}