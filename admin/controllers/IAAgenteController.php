<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// admin/controllers/IAAgenteController.php
// ════════════════════════════════════════════════════════

/**
 * Catálogo de agentes de BI — Fase A do "SportMoto AI".
 *
 * Cria e edita agentes sem código: persona, ferramentas (whitelist),
 * modelo, esforço, páginas do BI que atende, sugestões, perguntas por
 * tema e a rodada agendada. As regras estão em IAAgenteCatalogoService;
 * a persistência em IAAgente (ia_tipos_conteudo + ia_agentes).
 *
 * Mesmo desenho da Central (IAConfigController): página com a lista,
 * formulário servido por Ajax dentro do adminDrawer, POST em JSON.
 */
class IAAgenteController extends Controller
{
    private const PERMISSAO = 'marketing_ia_agentes';

    private IAAgente                $modelo;
    private IAAgenteCatalogoService $svc;

    public function __construct()
    {
        $this->exigirPermissao(self::PERMISSAO);
        $this->modelo = new IAAgente();
        $this->svc    = new IAAgenteCatalogoService($this->modelo);
    }

    // ── GET /admin/ia/agentes ─────────────────────────────
    public function index(): void
    {
        $agentes   = $this->modelo->listar();
        $conversas = $this->svc->conversasPorAgente();
        foreach ($agentes as &$a) {
            $a['conversas'] = (int) ($conversas[$a['codigo']] ?? 0);
        }
        unset($a);

        $this->render('ia/agentes/index', [
            'agentes' => $agentes,
            'paginas' => IAAgenteGateway::PAGINAS,
            'csrf'    => SecurityHelper::generateCsrf(),
        ], 'admin');
    }

    // ── GET /admin/ia/agentes/form?id= ────────────────────
    // Formulário para o drawer. id vazio = novo.
    public function form(): void
    {
        $id     = (int) ($_GET['id'] ?? 0);
        $agente = $id > 0 ? $this->modelo->buscar($id) : null;
        if ($id > 0 && $agente === null) {
            $this->json(['ok' => false, 'msg' => 'Agente não encontrado.']);
        }

        $html = $this->partial('agente_form', [
            'agente'      => $agente,
            'ferramentas' => (new IAAgenteGateway())->catalogoPublico(),
            'paginas'     => IAAgenteGateway::PAGINAS,
            'ocupadas'    => $this->modelo->paginasOcupadas($id > 0 ? $id : null),
            'modelos'     => $this->svc->modelosDeAgente(),
            'efforts'     => IAAgente::EFFORTS,
            'perguntasTexto' => $agente ? IAAgenteCatalogoService::perguntasParaTexto($agente['perguntas']) : '',
        ]);
        $this->json(['ok' => true, 'titulo' => $agente ? 'Editar agente' : 'Novo agente', 'html' => $html]);
    }

    // ── POST /admin/ia/agentes/salvar ─────────────────────
    public function salvar(): void
    {
        $this->verifyCsrf();

        $id    = (int) ($_POST['id'] ?? 0);
        $atual = $id > 0 ? $this->modelo->buscar($id) : null;
        if ($id > 0 && $atual === null) {
            $this->json(['ok' => false, 'msg' => 'Agente não encontrado.']);
        }

        $v = $this->svc->validar($_POST, $atual);
        if (!$v['ok']) {
            $this->json(['ok' => false, 'msg' => $v['msg']]);
        }
        $dados = $v['dados'] + ['criado_por' => AuthHelper::usuarioId() > 0 ? AuthHelper::usuarioId() : null];

        try {
            if ($atual === null) {
                $id = $this->modelo->criar($dados);
            } else {
                $this->modelo->atualizar($id, $dados);
            }
        } catch (\Throwable $e) {
            LogService::exception($e, 'error', 'ia', ['onde' => 'IAAgenteController::salvar', 'codigo' => $dados['codigo']]);
            $this->json(['ok' => false, 'msg' => 'Não foi possível salvar o agente. O erro foi registrado.']);
        }

        IAAgenteGateway::limparCacheAgentes();
        LogService::audit('ia_agente_salvo', [
            'id' => $id, 'codigo' => $dados['codigo'], 'novo' => $atual === null,
            'ferramentas' => count($dados['ferramentas']), 'paginas' => $dados['paginas'],
        ]);

        $this->json([
            'ok'  => true,
            'id'  => $id,
            'msg' => ($atual === null ? 'Agente criado.' : 'Agente salvo.') . ($v['aviso'] ? ' ' . $v['aviso'] : ''),
        ]);
    }

    // ── POST /admin/ia/agentes/alternar ───────────────────
    public function alternar(): void
    {
        $this->verifyCsrf();

        $id = (int) ($_POST['id'] ?? 0);
        $a  = $this->modelo->buscar($id);
        if ($a === null) {
            $this->json(['ok' => false, 'msg' => 'Agente não encontrado.']);
        }
        if ((int) $a['ativo'] === 0) {
            $chk = $this->svc->podeAtivar($a);
            if (!$chk['ok']) $this->json($chk);
        }

        $novo = $this->modelo->alternar($id);
        IAAgenteGateway::limparCacheAgentes();
        LogService::audit('ia_agente_alternado', ['id' => $id, 'ativo' => $novo]);
        $this->json(['ok' => true, 'ativo' => $novo, 'msg' => $novo ? 'Agente ativado.' : 'Agente desativado.']);
    }

    // ── POST /admin/ia/agentes/excluir — só sem histórico ─
    public function excluir(): void
    {
        $this->verifyCsrf();

        $id = (int) ($_POST['id'] ?? 0);
        $r  = $this->modelo->excluir($id);
        if ($r['ok']) {
            IAAgenteGateway::limparCacheAgentes();
            LogService::audit('ia_agente_excluido', ['id' => $id]);
        }
        $this->json($r);
    }

    /* ------------------------------------------------------------------ */

    private function partial(string $arquivo, array $dados = []): string
    {
        $arquivo = basename($arquivo);
        $caminho = __DIR__ . '/../views/ia/agentes/' . $arquivo . '.php';
        if (!is_file($caminho)) {
            LogService::error('ia_partial_inexistente', ['arquivo' => $arquivo]);
            return '';
        }
        extract($dados, EXTR_SKIP);
        ob_start();
        include $caminho;
        return (string) ob_get_clean();
    }

    /** Mesma guarda da Central (IAConfigController): granular primeiro, cargo depois, Ajax ≠ navegação. */
    private function exigirPermissao(string $permissao): void
    {
        AuthHelper::requireAdmin();
        if ((new IAPermissaoService())->pode($permissao)) {
            return;
        }
        http_response_code(403);
        if (AuthHelper::isAjax()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'msg' => 'Sem permissão para esta ação.'], JSON_UNESCAPED_UNICODE);
        } else {
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><meta charset="utf-8"><title>Sem permissão</title>'
               . '<p style="font:16px system-ui;padding:2rem">Você não tem permissão para gerenciar os agentes de IA.</p>';
        }
        exit;
    }
}
