<?php 

// admin/controllers/MotosSincController.php

class MotosSincController extends Controller {

    public function __construct() {
        AuthHelper::requireAdmin();
    }

    public function index(): void {
        $db   = Database::getInstance()->getConnection();
        $logs = $db->query(
            "SELECT * FROM fipe_sync_log ORDER BY id DESC LIMIT 10"
        )->fetchAll();

        $stats = $db->query(
            "SELECT
                (SELECT COUNT(*) FROM moto_montadoras WHERE ativo=1) AS montadoras,
                (SELECT COUNT(*) FROM moto_modelos    WHERE ativo=1) AS modelos,
                (SELECT COUNT(*) FROM moto_anos)                      AS anos"
        )->fetch();

        $this->render('motos/sinc', [
            'logs'  => $logs,
            'stats' => $stats,
        ], 'admin');
    }

    public function sincronizar(): void {
        $this->verifyCsrf();
        set_time_limit(0);

        try {
            $fipe   = new FipeService();
            $result = $fipe->sincronizarTudo();
            $this->json(['ok' => true, 'stats' => $result['stats']]);
        } catch (\Exception $e) {
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }

    public function uploadThumb(): void {
        $this->verifyCsrf();
        $tipo = $_POST['tipo'] ?? ''; // 'montadora' | 'modelo'
        $id   = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);

        if (!in_array($tipo, ['montadora', 'modelo']) || !$id) {
            $this->json(['ok' => false]);
        }
        if (empty($_FILES['thumb']['tmp_name'])) {
            $this->json(['ok' => false, 'msg' => 'Nenhum arquivo.']);
        }

        $ext     = strtolower(pathinfo($_FILES['thumb']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','webp'];
        if (!in_array($ext, $allowed)) {
            $this->json(['ok' => false, 'msg' => 'Formato inválido.']);
        }

        $dir     = UPLOAD_PATH . '/motos/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $arquivo = $tipo . '_' . $id . '_' . uniqid() . '.' . $ext;
        move_uploaded_file($_FILES['thumb']['tmp_name'], $dir . $arquivo);

        $tabela = $tipo === 'montadora' ? 'moto_montadoras' : 'moto_modelos';
        Database::getInstance()->getConnection()
            ->prepare("UPDATE {$tabela} SET thumb=? WHERE id=?")
            ->execute([$arquivo, $id]);

        $this->json([
            'ok'  => true,
            'url' => UPLOAD_URL . '/motos/' . $arquivo,
        ]);
    }

    // Adicionar ao MotosSincController existente:

    // ── Listagem de modelos por montadora ─────────────────────
    public function modelos(): void {
        $montadoraId = SecurityHelper::sanitizeInt($_GET['montadora_id'] ?? 0);
        if (!$montadoraId) {
            Session::flash('error', 'Montadora inválida.');
            $this->redirect(BASE_URL . '/admin/motos');
        }

        $db = Database::getInstance()->getConnection();

        $montadora = $db->prepare(
            "SELECT * FROM moto_montadoras WHERE id = ? LIMIT 1"
        );
        $montadora->execute([$montadoraId]);
        $montadora = $montadora->fetch();

        if (!$montadora) {
            Session::flash('error', 'Montadora não encontrada.');
            $this->redirect(BASE_URL . '/admin/motos');
        }

        $stmt = $db->prepare(
            "SELECT mo.*,
                    COUNT(DISTINCT ma.ano) AS total_anos
            FROM moto_modelos mo
            LEFT JOIN moto_anos ma ON ma.modelo_id = mo.id
            WHERE mo.montadora_id = ?
            GROUP BY mo.id
            ORDER BY mo.nome ASC"
        );
        $stmt->execute([$montadoraId]);
        $modelos = $stmt->fetchAll();

        $this->render('motos/modelos', [
            'montadora' => $montadora,
            'modelos'   => $modelos,
        ], 'admin');
    }

    // ── Salvar montadora/modelo manualmente ───────────────────
    public function salvarMontadora(): void {
        $this->verifyCsrf();

        $id    = SecurityHelper::sanitizeInt($_POST['id']   ?? 0);
        $nome  = SecurityHelper::sanitizeString($_POST['nome'] ?? '');
        $ativo = isset($_POST['ativo']) ? 1 : 0;

        if (empty($nome)) $this->json(['ok' => false, 'msg' => 'Nome obrigatório.']);

        $db   = Database::getInstance()->getConnection();
        $slug = $this->slugUnico('moto_montadoras', $this->slugify($nome), $id);

        if ($id > 0) {
            $db->prepare(
                "UPDATE moto_montadoras SET nome=?, slug=?, ativo=? WHERE id=?"
            )->execute([$nome, $slug, $ativo, $id]);
        } else {
            $db->prepare(
                "INSERT INTO moto_montadoras (nome, slug, ativo) VALUES (?,?,?)"
            )->execute([$nome, $slug, $ativo]);
            $id = (int)$db->lastInsertId();
        }

        $this->json(['ok' => true, 'msg' => 'Montadora salva!', 'id' => $id, 'nome' => $nome, 'slug' => $slug]);
    }

    public function salvarModelo(): void {
        $this->verifyCsrf();

        $id          = SecurityHelper::sanitizeInt($_POST['id']           ?? 0);
        $montadoraId = SecurityHelper::sanitizeInt($_POST['montadora_id'] ?? 0);
        $nome        = SecurityHelper::sanitizeString($_POST['nome']       ?? '');
        $ativo       = isset($_POST['ativo']) ? 1 : 0;

        if (!$montadoraId || empty($nome)) {
            $this->json(['ok' => false, 'msg' => 'Dados inválidos.']);
        }

        $db   = Database::getInstance()->getConnection();
        $slug = $this->slugUnico('moto_modelos', $this->slugify($nome), null, $montadoraId);

        if ($id > 0) {
            $db->prepare(
                "UPDATE moto_modelos SET nome=?, slug=?, ativo=? WHERE id=? AND montadora_id=?"
            )->execute([$nome, $slug, $ativo, $id, $montadoraId]);
        } else {
            $db->prepare(
                "INSERT INTO moto_modelos (montadora_id, nome, slug, ativo) VALUES (?,?,?,?)"
            )->execute([$montadoraId, $nome, $slug, $ativo]);
            $id = (int)$db->lastInsertId();
        }

        $this->json(['ok' => true, 'msg' => 'Modelo salvo!', 'id' => $id, 'nome' => $nome]);
    }

    public function excluirMontadora(): void {
        $this->verifyCsrf();
        $id = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
        if (!$id) $this->json(['ok' => false]);

        $db = Database::getInstance()->getConnection();

        // Verifica se tem produtos vinculados
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM produto_compatibilidade WHERE montadora_id = ?"
        );
        $stmt->execute([$id]);
        if ((int)$stmt->fetchColumn() > 0) {
            $this->json([
                'ok'  => false,
                'msg' => 'Esta montadora tem produtos vinculados. Remova os vínculos antes.',
            ]);
        }

        $db->prepare("DELETE FROM moto_montadoras WHERE id = ?")->execute([$id]);
        $this->json(['ok' => true, 'msg' => 'Montadora excluída.']);
    }

    public function toggleAtivo(): void {
        $this->verifyCsrf();

        $id   = SecurityHelper::sanitizeInt($_POST['id']   ?? 0);
        $tipo = SecurityHelper::sanitizeString($_POST['tipo'] ?? '');

        $tabelas = [
            'montadora' => 'moto_montadoras',
            'modelo'    => 'moto_modelos',
        ];

        $tabela = $tabelas[$tipo] ?? null;
        if (!$id || !$tabela) $this->json(['ok' => false]);

        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT ativo FROM {$tabela} WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $novo = (int)$stmt->fetchColumn() ? 0 : 1;

        $db->prepare("UPDATE {$tabela} SET ativo = ? WHERE id = ?")->execute([$novo, $id]);
        $this->json(['ok' => true, 'ativo' => $novo]);
    }

    // ── Helpers ───────────────────────────────────────────────
    private function slugify(string $texto): string {
        $slug = mb_strtolower(trim($texto));
        $slug = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug);
        $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
        $slug = preg_replace('/[\s-]+/', '-', $slug);
        return trim($slug, '-');
    }

    private function slugUnico(
        string $tabela,
        string $slug,
        ?int   $excludeId   = null,
        ?int   $montadoraId = null
    ): string {
        $final   = $slug;
        $counter = 1;

        while (true) {
            if ($montadoraId !== null) {
                $stmt = Database::getInstance()->getConnection()->prepare(
                    "SELECT id FROM {$tabela}
                    WHERE montadora_id = ? AND slug = ? AND (? IS NULL OR id != ?)
                    LIMIT 1"
                );
                $stmt->execute([$montadoraId, $final, $excludeId, $excludeId]);
            } else {
                $stmt = Database::getInstance()->getConnection()->prepare(
                    "SELECT id FROM {$tabela}
                    WHERE slug = ? AND (? IS NULL OR id != ?)
                    LIMIT 1"
                );
                $stmt->execute([$final, $excludeId, $excludeId]);
            }

            if (!$stmt->fetchColumn()) break;
            $final = $slug . '-' . (++$counter);
        }

        return $final;
    }

    // Substitua o método sincronizar() e adicione os novos:

    // ── Etapa 1: busca e salva todas as marcas ────────────────
    public function syncMarcas(): void {
        $this->verifyCsrf();

        try {
            $fipe   = new FipeService();
            $marcas = $fipe->buscarESalvarMarcas();

            $this->json([
                'ok'    => true,
                'total' => count($marcas),
                'marcas'=> $marcas, // [{id_local, fipe_code, nome}]
            ]);
        } catch (\Exception $e) {
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }

    // ── Etapa 2: busca e salva modelos de uma marca ───────────
    public function syncModelos(): void {
        $this->verifyCsrf();

        $montadoraId = SecurityHelper::sanitizeInt($_POST['montadora_id'] ?? 0);
        $fipeCode    = SecurityHelper::sanitizeString($_POST['fipe_code'] ?? '');

        if (!$montadoraId || !$fipeCode) {
            $this->json(['ok' => false, 'msg' => 'Dados inválidos.']);
        }

        try {
            $fipe    = new FipeService();
            $modelos = $fipe->buscarESalvarModelos($montadoraId, $fipeCode);

            $this->json([
                'ok'     => true,
                'total'  => count($modelos),
                'modelos'=> $modelos, // [{id_local, fipe_code, nome}]
            ]);
        } catch (\Exception $e) {
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }

    // ── Etapa 3: busca e salva anos de um modelo ──────────────
    public function syncAnos(): void {
        $this->verifyCsrf();

        $modeloId    = SecurityHelper::sanitizeInt($_POST['modelo_id']    ?? 0);
        $fipeCodeMarca= SecurityHelper::sanitizeString($_POST['fipe_code_marca']  ?? '');
        $fipeCodeMod  = SecurityHelper::sanitizeString($_POST['fipe_code_modelo'] ?? '');

        if (!$modeloId || !$fipeCodeMarca || !$fipeCodeMod) {
            $this->json(['ok' => false, 'msg' => 'Dados inválidos.']);
        }

        try {
            $fipe  = new FipeService();
            $total = $fipe->buscarESalvarAnos($modeloId, $fipeCodeMarca, $fipeCodeMod);

            $this->json(['ok' => true, 'total_anos' => $total]);
        } catch (\Exception $e) {
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }

    // ── Finaliza o log ────────────────────────────────────────
    public function syncFinalizar(): void {
        $this->verifyCsrf();

        $montadoras = SecurityHelper::sanitizeInt($_POST['montadoras'] ?? 0);
        $modelos    = SecurityHelper::sanitizeInt($_POST['modelos']    ?? 0);
        $anos       = SecurityHelper::sanitizeInt($_POST['anos']       ?? 0);
        $status     = in_array($_POST['status'] ?? '', ['ok','erro']) ? $_POST['status'] : 'ok';
        $erro       = SecurityHelper::sanitizeString($_POST['erro'] ?? '');

        $db = Database::getInstance()->getConnection();

        // Fecha o log mais recente que está 'rodando'
        $db->prepare(
            "UPDATE fipe_sync_log
            SET finalizado_em=NOW(), montadoras=?, modelos=?, anos=?, status=?, erro_msg=?
            WHERE status='rodando'
            ORDER BY id DESC LIMIT 1"
        )->execute([$montadoras, $modelos, $anos, $status, $erro ?: null]);

        $this->json(['ok' => true]);
    }

    // ── Inicia o log (chamado pelo JS antes de começar) ───────
    public function syncIniciar(): void {
        $this->verifyCsrf();
        $db = Database::getInstance()->getConnection();
        $db->prepare("INSERT INTO fipe_sync_log (status) VALUES ('rodando')")->execute();
        $this->json(['ok' => true]);
    }
}