<?php
class FamiliasController extends Controller {

    private FamiliaService $service;

    public function __construct() {
        // Família de produto é catálogo: super, gerente e editor. Até 11/09
        // era requireAdmin(). Até 12/09 só o form de produto chamava estas
        // rotas; agora a família tem tela própria (index/ver).
        AuthHelper::requireAdminLevel('super', 'gerente', 'editor');
        $this->service = new FamiliaService();
    }

    /** GET /admin/familias — a lista que não existia. */
    public function index(): void {
        $filtros = [
            'busca'    => SecurityHelper::sanitizeString($_GET['busca'] ?? ''),
            'situacao' => SecurityHelper::sanitizeString($_GET['situacao'] ?? ''),
        ];
        $page    = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 20;

        $lista = $this->service->listar($filtros, $page, $perPage);

        $this->render('familias/index', [
            'page_title' => 'Famílias de produtos',
            'familias'   => $lista['itens'],
            'total'      => $lista['total'],
            'page'       => $page,
            'perPage'    => $perPage,
            'filtros'    => $filtros,
            'resumo'     => $this->service->resumo(),
        ], 'admin');
    }

    /** GET /admin/familias/{id} — quem está dentro dela. */
    public function ver(int $id): void {
        $familia = $this->service->detalhe($id);
        if (!$familia) {
            Session::flash('error', 'Família não encontrada.');
            $this->redirect(BASE_URL . '/admin/familias');
            return;
        }

        $this->render('familias/ver', [
            'page_title' => 'Família: ' . $familia['nome'],
            'familia'    => $familia,
        ], 'admin');
    }

    /** Ajax — POST /admin/familias/salvar: nome, descrição e situação. */
    public function salvar(): void {
        $this->verifyCsrf();
        $id = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
        if (!$id) $this->json(['ok' => false, 'msg' => 'Família inválida.']);

        $this->json($this->service->salvar($id, [
            'nome'      => SecurityHelper::sanitizeString($_POST['nome'] ?? ''),
            'descricao' => SecurityHelper::sanitizeString($_POST['descricao'] ?? ''),
            'ativo'     => ($_POST['ativo'] ?? '0') === '1',
        ]));
    }

    /**
     * Ajax — GET /admin/familias/sugerir: famílias que combinam com o produto.
     *
     * Serve o formulário de produto. Quem cadastra um produto novo não sabe
     * de cor o que já existe, e sem isto cria uma família repetida ao lado
     * da que deveria ter usado.
     */
    public function sugerir(): void {
        $this->json(['ok' => true, 'sugestoes' => $this->service->sugerir([
            'nome'         => SecurityHelper::sanitizeString($_GET['nome'] ?? ''),
            'marca_id'     => (int) ($_GET['marca_id'] ?? 0),
            'categoria_id' => (int) ($_GET['categoria_id'] ?? 0),
            'produto_id'   => (int) ($_GET['produto_id'] ?? 0),
        ])]);
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
    //
    // A regra (desvincular produto, limpar agrupadores, registrar quem fez)
    // mora no FamiliaService: a tela de famílias e o form de produto chamam
    // o mesmo caminho, e antes cada um apagava do seu jeito — o agrupador
    // ficava órfão quando a exclusão vinha daqui.
    public function excluir(): void {
        $this->verifyCsrf();
        $id = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
        if (!$id) $this->json(['ok' => false, 'msg' => 'Família inválida.']);

        $this->json($this->service->excluir($id));
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