<?php
/**
 * admin/controllers/FluxoAdminController.php
 *
 * Rotas:
 *   GET  /admin/fluxos                → listagem
 *   GET  /admin/fluxos/{id}           → editor (JSON)
 *   POST /admin/fluxos/criar          → novo fluxo
 *   POST /admin/fluxos/{id}/salvar    → salva rascunho
 *   POST /admin/fluxos/{id}/publicar  → valida + publica
 *   POST /admin/fluxos/{id}/status    → pausar/reativar/arquivar
 */
class FluxoAdminController extends Controller
{
    /** @var FluxoAdminService */
    private $svc;

    private $params = [];
    // private function json($d) {}
    // private function render($v, $d, $l) {}

    public function __construct()
    {
        // parent::__construct();
        // A cascata mora no AuthHelper — ver o porquê lá. O try/catch que
        // existia aqui era código morto: requirePermission() não lança, nega
        // com exit, e o requireAdminLevel() do catch iria sem argumento
        // nenhum (= só o super).
        AuthHelper::requirePermissaoOuNivel('automacao', 'super', 'gerente');
        $this->svc = new FluxoAdminService();
    }

    public function index(): void
    {
        $this->render('fluxos/index', [
            'fluxos'   => $this->svc->listar(),
            'catalogo' => FluxoNoRegistry::catalogo(),
            'titulo'   => 'Automação v2 — Fluxos',
        ], 'admin');
    }

    public function editor(int $id): void
    {
        
        $fluxo = $this->svc->carregar($id);
        if (!$fluxo) { http_response_code(404); echo 'Fluxo não encontrado. '.json_encode($_GET) ; return; }

        // Templates de email para o select do nó acao_email
        $emailTemplates = [];
        try {
            $db = Database::getInstance()->getConnection();
            $st = $db->query("SELECT id, nome FROM email_templates ORDER BY id DESC LIMIT 200");
            $emailTemplates = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            // sem templates o canvas ainda funciona; o select fica vazio
        }

        // Agentes e páginas do BI para os selects do nó agente_ia (Fase C).
        // Sem o módulo de IA instalado o canvas segue; o select fica só com 'auto'.
        $agentesBi = [['v' => 'auto', 't' => 'auto — o agente sugerido pelo alerta']];
        $paginasBi = [];
        if (class_exists('IAAgenteGateway')) {
            try {
                foreach (IAAgenteGateway::agentes() as $codigo => $a) {
                    $agentesBi[] = ['v' => $codigo, 't' => (string)($a['nome_exibicao'] ?? $codigo)];
                }
                foreach (IAAgenteGateway::PAGINAS as $codigo => $rotulo) {
                    $paginasBi[] = ['v' => $codigo, 't' => $rotulo];
                }
            } catch (Throwable $e) {
                // catálogo ainda não migrado — canvas sem a lista
            }
        }

        $this->render('fluxos/editor', [
            'fluxo'          => $fluxo,
            'catalogo'       => FluxoNoRegistry::catalogo(),
            'emailTemplates' => $emailTemplates,
            'agentesBi'      => $agentesBi,
            'paginasBi'      => $paginasBi,
            'titulo'         => 'Fluxo — ' . $fluxo['nome'],
        ], 'admin');
    }

    public function criar(): void
    {
        $this->verifyCsrf();
        $nome = trim($_POST['nome'] ?? '');
        if ($nome === '') { $this->json(['ok' => false, 'erro' => 'Informe o nome.']); return; }
        $id = $this->svc->criar($nome, trim($_POST['descricao'] ?? '') ?: null);
        $this->json(['ok' => true, 'id' => $id]);
    }

    public function salvar(): void
    {
        $this->verifyCsrf();
        $id = (int)($_POST['fluxo_id'] ?? 0);

        $grafo = json_decode($_POST['grafo_json'] ?? '', true);
        if (!is_array($grafo)) {
            $this->json(['ok' => false, 'erros' => ['JSON do grafo inválido.']]); return;
        }

        $meta = null;
        if (isset($_POST['config_json'])) {
            $cfg = json_decode($_POST['config_json'], true);
            $meta = ['config' => is_array($cfg) ? $cfg : []];
        }

        $r = $this->svc->salvarRascunho($id, $grafo, $meta);
        $this->json($r);
    }

    public function publicar(): void
    {
        $this->verifyCsrf();
        $id = (int)($_POST['fluxo_id'] ?? 0);
        $this->json($this->svc->publicar($id));
    }

    public function status(): void
    {
        $this->verifyCsrf();
        $id     = (int)($_POST['fluxo_id'] ?? 0);
        $status = (string)($_POST['status'] ?? '');
        $ok = $this->svc->mudarStatus($id, $status);
        $this->json(['ok' => $ok]);
    }

    // * ── MÉTODO 1: stats por nó (os balões do canvas) ─────────────────────────── */

    /** GET /admin/fluxos/{id}/stats — contadores por nó da versão publicada. */
    public function stats($id): void
    {
        $id = SecurityHelper::sanitizeInt($id);
        $fluxo = $this->svc->carregar($id);
        if (!$fluxo) { $this->json(['ok' => false, 'erros' => ['Fluxo não encontrado. '.$id], 'teste'=>$_GET]); return; }

        $versao = (int)$fluxo['versao_publicada'];
        if ($versao < 1) {
            // Rascunho nunca rodou — o canvas simplesmente não mostra números
            $this->json(['ok' => true, 'versao' => 0, 'nos' => [], 'debug'=>$fluxo]);
            return;
        }

        $db = Database::getInstance()->getConnection();
        $this->json([
            'ok'     => true,
            'versao' => $versao,
            'nos'    => FluxoLogService::statsPorNo($db, $id, $versao), 'debug'=>$fluxo
        ]);
    }


    /* ── MÉTODO 2: timeline geral (tela + dados paginados) ────────────────────── */

    /** GET /admin/fluxos/atividade — a tela. */
    public function atividade(): void
    {
        $db = Database::getInstance()->getConnection();

        // Fluxos para o filtro
        $fluxos = [];
        try {
            $st = $db->query("SELECT id, nome, status FROM fluxo_v2 ORDER BY nome ASC");
            $fluxos = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable $e) {}

        $this->render('fluxos/atividade', [
            'titulo' => 'Atividade das automações',
            'fluxos' => $fluxos,
            'kpis'   => FluxoLogService::kpis($db),
        ], 'admin');
    }

    /** GET /admin/fluxos/atividade/dados — JSON paginado por cursor. */
    public function atividadeDados(): void
    {
        $db = Database::getInstance()->getConnection();

        $filtros = [
            'fluxo_id'   => (int)($_GET['fluxo_id'] ?? 0),
            'cliente_id' => (int)($_GET['cliente_id'] ?? 0),
            'so_erros'   => !empty($_GET['so_erros']),
        ];
        $antesDe = (int)($_GET['antes_de'] ?? 0);

        $itens = FluxoLogService::atividade($db, $filtros, 50, $antesDe);

        $this->json([
            'ok'      => true,
            'itens'   => $itens,
            'kpis'    => $antesDe === 0 ? FluxoLogService::kpis($db) : null,
            'proximo' => $itens ? (int)end($itens)['id'] : 0,
        ]);
    }
}
