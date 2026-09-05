<?php
/**
 * admin/controllers/VidaUtilAdminController.php
 *
 * Regras de dica de cuidado (categoria_vida_util) — listagem, criação,
 * edição, pausa e exclusão.
 *
 * Rotas em admin/config/routes.php (formato AdminRouter):
 *   AdminRouter::get ('/vida-util',          'VidaUtilAdminController@index');
 *   AdminRouter::get ('/vida-util/listar',   'VidaUtilAdminController@listar');
 *   AdminRouter::post('/vida-util/salvar',   'VidaUtilAdminController@salvar');
 *   AdminRouter::post('/vida-util/pausar',   'VidaUtilAdminController@pausar');
 *   AdminRouter::post('/vida-util/excluir',  'VidaUtilAdminController@excluir');
 */
class VidaUtilAdminController extends Controller
{
    /** Categorias de notificação aceitas (mesma lista do sistema de notificações). */
    private const CATEGORIAS_NOTIF = ['sistema', 'pedido', 'promocao', 'estoque', 'financeiro', 'conta'];

    /** @var PDO */
    private $db;

    public function __construct()
    {
        $this->autorizar();
        $this->db = Database::getInstance()->getConnection();
    }

    /** Cascata de permissão do projeto. */
    private function autorizar(): void
    {
        if (method_exists('AuthHelper', 'requirePermission')) {
            AuthHelper::requirePermission('vida_util');
            return;
        }
        if (method_exists('AuthHelper', 'requireAdminLevel')) {
            AuthHelper::requireAdminLevel();
            return;
        }
        AuthHelper::requireAdmin();
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Tela
    // ═════════════════════════════════════════════════════════════════════════

    public function index(): void
    {
        $this->render('admin/vida-util/index', [
            'titulo' => 'Dicas de cuidado',
            'dados'  => $this->montarDados(),
        ], 'admin');
    }

    public function listar(): void
    {
        $this->json(['ok' => true] + $this->montarDados());
    }

    /**
     * Tudo que a tela precisa numa tacada: regras + números do funil +
     * categorias ainda sem regra (para o select de criação).
     */
    private function montarDados(): array
    {
        $regras = [];
        $funil  = ['agendadas' => 0, 'enviadas' => 0, 'cliques' => 0, 'taxa' => 0.0];
        $livres = [];

        try {
            // Números por categoria, numa query só
            $porCat = [];
            $st = $this->db->query(
                "SELECT categoria_id,
                        SUM(CASE WHEN status = 'agendado' THEN 1 ELSE 0 END) AS agendadas,
                        SUM(CASE WHEN status = 'enviado'  THEN 1 ELSE 0 END) AS enviadas,
                        SUM(CASE WHEN clicado_em IS NOT NULL THEN 1 ELSE 0 END) AS cliques
                 FROM vida_util_agenda
                 GROUP BY categoria_id"
            );
            foreach (($st ? $st->fetchAll(PDO::FETCH_ASSOC) : []) as $r) {
                $porCat[(int)$r['categoria_id']] = [
                    'agendadas' => (int)$r['agendadas'],
                    'enviadas'  => (int)$r['enviadas'],
                    'cliques'   => (int)$r['cliques'],
                ];
            }

            // Regras, da mais frequente para a mais esparsa
            $st = $this->db->query(
                "SELECT v.*, c.nome AS categoria_nome
                 FROM categoria_vida_util v
                 LEFT JOIN categorias c ON c.id = v.categoria_id
                 ORDER BY v.meses ASC, c.nome ASC"
            );
            foreach (($st ? $st->fetchAll(PDO::FETCH_ASSOC) : []) as $r) {
                $cid = (int)$r['categoria_id'];
                $n   = $porCat[$cid] ?? ['agendadas' => 0, 'enviadas' => 0, 'cliques' => 0];

                $regras[] = [
                    'id'              => (int)$r['id'],
                    'categoria_id'    => $cid,
                    'categoria_nome'  => $r['categoria_nome'] ?: ('Categoria #' . $cid),
                    'meses'           => (int)$r['meses'],
                    'titulo'          => (string)$r['titulo'],
                    'dica'            => (string)$r['dica'],
                    'categoria_notif' => (string)($r['categoria_notif'] ?? 'sistema'),
                    'ativo'           => (int)$r['ativo'] === 1,
                    'agendadas'       => $n['agendadas'],
                    'enviadas'        => $n['enviadas'],
                    'cliques'         => $n['cliques'],
                ];

                $funil['agendadas'] += $n['agendadas'];
                $funil['enviadas']  += $n['enviadas'];
                $funil['cliques']   += $n['cliques'];
            }
            $funil['taxa'] = $funil['enviadas'] > 0
                ? round($funil['cliques'] / $funil['enviadas'] * 100, 1)
                : 0.0;

            // Categorias que ainda não têm regra
            $st = $this->db->query(
                "SELECT c.id, c.nome
                 FROM categorias c
                 LEFT JOIN categoria_vida_util v ON v.categoria_id = c.id
                 WHERE v.id IS NULL
                 ORDER BY c.nome ASC"
            );
            foreach (($st ? $st->fetchAll(PDO::FETCH_ASSOC) : []) as $r) {
                $livres[] = ['id' => (int)$r['id'], 'nome' => (string)$r['nome']];
            }

        } catch (Throwable $e) {
            $this->logErro('montarDados', $e);
        }

        return ['regras' => $regras, 'funil' => $funil, 'categorias_livres' => $livres];
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Escrita
    // ═════════════════════════════════════════════════════════════════════════

    public function salvar(): void
    {
        $this->verifyCsrf();

        $id     = (int)($_POST['id'] ?? 0);
        $catId  = (int)($_POST['categoria_id'] ?? 0);
        $meses  = (int)($_POST['meses'] ?? 0);
        $titulo = trim((string)($_POST['titulo'] ?? ''));
        $dica   = trim((string)($_POST['dica'] ?? ''));
        $notif  = (string)($_POST['categoria_notif'] ?? 'sistema');
        $ativo  = !empty($_POST['ativo']) ? 1 : 0;

        // ── Validação ────────────────────────────────────────────────────────
        $erros = [];
        if ($catId <= 0)                       $erros[] = 'Escolha a categoria.';
        if ($meses < 1 || $meses > 600)        $erros[] = 'O prazo precisa ficar entre 1 e 600 meses.';
        if ($titulo === '')                    $erros[] = 'Escreva o título da dica.';
        if (mb_strlen($titulo) > 150)          $erros[] = 'O título passa de 150 caracteres.';
        if ($dica === '')                      $erros[] = 'Escreva o texto da dica.';
        if (mb_strlen($dica) > 2000)           $erros[] = 'O texto da dica passa de 2000 caracteres.';
        if (!in_array($notif, self::CATEGORIAS_NOTIF, true)) $notif = 'sistema';

        if ($erros) { $this->json(['ok' => false, 'erros' => $erros]); return; }

        try {
            if ($id > 0) {
                // A categoria não muda na edição — os agendamentos já apontam pra ela
                $st = $this->db->prepare(
                    "UPDATE categoria_vida_util
                     SET meses = :m, titulo = :t, dica = :d, categoria_notif = :n, ativo = :a
                     WHERE id = :id"
                );
                $st->execute([
                    ':m' => $meses, ':t' => $titulo, ':d' => $dica,
                    ':n' => $notif, ':a' => $ativo, ':id' => $id,
                ]);
                $this->auditar('vida_util_regra_editada', ['id' => $id, 'categoria_id' => $catId, 'meses' => $meses]);
                $this->json(['ok' => true, 'msg' => 'Regra salva.', 'id' => $id]);
                return;
            }

            // Criação — UNIQUE (categoria_id) garante uma regra por categoria
            $ja = $this->db->prepare("SELECT id FROM categoria_vida_util WHERE categoria_id = :c LIMIT 1");
            $ja->execute([':c' => $catId]);
            if ($ja->fetchColumn()) {
                $this->json(['ok' => false, 'erros' => ['Essa categoria já tem uma regra. Edite a existente.']]);
                return;
            }

            $st = $this->db->prepare(
                "INSERT INTO categoria_vida_util (categoria_id, meses, titulo, dica, categoria_notif, ativo)
                 VALUES (:c, :m, :t, :d, :n, :a)"
            );
            $st->execute([
                ':c' => $catId, ':m' => $meses, ':t' => $titulo,
                ':d' => $dica, ':n' => $notif, ':a' => $ativo,
            ]);
            $novoId = (int)$this->db->lastInsertId();
            $this->auditar('vida_util_regra_criada', ['id' => $novoId, 'categoria_id' => $catId, 'meses' => $meses]);
            $this->json(['ok' => true, 'msg' => 'Regra criada.', 'id' => $novoId]);

        } catch (Throwable $e) {
            $this->logErro('salvar', $e);
            $this->json(['ok' => false, 'erros' => ['Não deu para salvar. Confira o log e tente de novo.']]);
        }
    }

    public function pausar(): void
    {
        $this->verifyCsrf();
        $id    = (int)($_POST['id'] ?? 0);
        $ativo = !empty($_POST['ativo']) ? 1 : 0;

        if ($id <= 0) { $this->json(['ok' => false, 'erros' => ['Regra inválida.']]); return; }

        try {
            $this->db->prepare("UPDATE categoria_vida_util SET ativo = :a WHERE id = :id")
                     ->execute([':a' => $ativo, ':id' => $id]);
            $this->auditar('vida_util_regra_status', ['id' => $id, 'ativo' => $ativo]);
            $this->json([
                'ok'  => true,
                'msg' => $ativo ? 'Regra ativada.' : 'Regra pausada. As dicas já agendadas continuam valendo.',
            ]);
        } catch (Throwable $e) {
            $this->logErro('pausar', $e);
            $this->json(['ok' => false, 'erros' => ['Não deu para alterar o status.']]);
        }
    }

    public function excluir(): void
    {
        $this->verifyCsrf();
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { $this->json(['ok' => false, 'erros' => ['Regra inválida.']]); return; }

        try {
            $st = $this->db->prepare("SELECT categoria_id FROM categoria_vida_util WHERE id = :id LIMIT 1");
            $st->execute([':id' => $id]);
            $catId = (int)$st->fetchColumn();
            if ($catId <= 0) { $this->json(['ok' => false, 'erros' => ['Regra não encontrada.']]); return; }

            // Mesma proteção do PedidoStatus::excluir(): com histórico, pausa em vez de apagar
            $st = $this->db->prepare("SELECT COUNT(*) FROM vida_util_agenda WHERE categoria_id = :c");
            $st->execute([':c' => $catId]);
            $usos = (int)$st->fetchColumn();

            if ($usos > 0) {
                $this->json(['ok' => false, 'erros' => [
                    "Existem {$usos} dica(s) ligadas a esta regra. Pause a regra em vez de excluir."
                ]]);
                return;
            }

            $this->db->prepare("DELETE FROM categoria_vida_util WHERE id = :id")->execute([':id' => $id]);
            $this->auditar('vida_util_regra_excluida', ['id' => $id, 'categoria_id' => $catId]);
            $this->json(['ok' => true, 'msg' => 'Regra excluída.']);

        } catch (Throwable $e) {
            $this->logErro('excluir', $e);
            $this->json(['ok' => false, 'erros' => ['Não deu para excluir.']]);
        }
    }

    // ═════════════════════════════════════════════════════════════════════════

    private function auditar(string $acao, array $ctx): void
    {
        if (!class_exists('LogService')) return;
        try { LogService::audit($acao, $ctx); } catch (Throwable $e) {}
    }

    private function logErro(string $onde, Throwable $e): void
    {
        if (!class_exists('LogService')) return;
        try {
            LogService::error('VidaUtilAdmin: ' . $e->getMessage(),
                ['metodo' => $onde, 'arquivo' => $e->getFile() . ':' . $e->getLine()]);
        } catch (Throwable $x) {}
    }
}
