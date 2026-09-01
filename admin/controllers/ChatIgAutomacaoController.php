<?php
/**
 * admin/controllers/ChatIgAutomacaoController.php
 *
 * Automações do Instagram: galeria de receitas, editor e insights.
 *
 * Rotas:
 *   GET  /admin/chat/automacoes                  → listagem (pastas + filtros)
 *   GET  /admin/chat/automacoes/nova             → galeria de receitas
 *   POST /admin/chat/automacoes/criar            → cria a partir de uma receita
 *   GET  /admin/chat/automacoes/{id}             → insights
 *   GET  /admin/chat/automacoes/{id}/editar      → editor
 *   POST /admin/chat/automacoes/{id}/salvar
 *   POST /admin/chat/automacoes/{id}/status      → ativar / parar / rascunho
 *   POST /admin/chat/automacoes/{id}/duplicar|excluir|restaurar|remover
 *   POST /admin/chat/automacoes/{id}/pasta       → move para pasta
 *   POST /admin/chat/automacoes/{id}/transferir  → troca o dono (só gestor)
 *   POST /admin/chat/automacoes/pastas/salvar|excluir
 *
 * PERMISSÃO: operar automação é trabalho de quem cuida do social, então
 * vendedor entra. A visibilidade por dono é resolvida no service — quem não é
 * gestor só enxerga as suas. Toda ação por {id} devolve 404 quando não é sua,
 * nunca 403: confirmar que o registro existe já vaza informação.
 */
class ChatIgAutomacaoController extends Controller
{
    private ChatIgAutomacaoService $svc;
    private ChatInstagramService   $ig;

    public function __construct()
    {
        AuthHelper::requireAdminLevel('super', 'gerente', 'vendedor');
        $this->svc = new ChatIgAutomacaoService();
        $this->ig  = new ChatInstagramService();
    }

    /** 404 uniforme — ver a nota de permissão no topo. */
    private function naoEncontrada(bool $ajax = false): void
    {
        if ($ajax || AuthHelper::isAjax()) {
            $this->json(['ok' => false, 'erro' => 'Automação não encontrada.'], 404);
            return;
        }
        http_response_code(404);
        echo 'Automação não encontrada.';
    }

    // =========================================================================
    // LISTAGEM
    // =========================================================================

    public function index(): void
    {
        $f = [
            'busca'    => trim((string)($_GET['q'] ?? '')),
            'gatilho'  => (string)($_GET['gatilho'] ?? ''),
            'status'   => (string)($_GET['status'] ?? ''),
            'pasta_id' => isset($_GET['pasta']) && $_GET['pasta'] !== '' ? (int)$_GET['pasta'] : null,
            'lixeira'  => !empty($_GET['lixeira']),
        ];
        $pagina = max(1, (int)($_GET['pagina'] ?? 1));
        $r = $this->svc->listar($f, $pagina, 25);

        $this->render('chat/automacoes', [
            'titulo'     => 'Instagram — Automações',
            'automacoes' => $r['itens'],
            'total'      => $r['total'],
            'pagina'     => $pagina,
            'porPagina'  => 25,
            'filtros'    => $f,
            'pastas'     => $this->svc->pastas(),
            'contadores' => $this->svc->contadores(),
            'gatilhos'   => ChatIgReceitaService::gatilhos(),
            'ehGestor'   => $this->svc->ehGestor(),
            'contaOk'    => $this->ig->contaPadrao() !== null,
            // Só gestor transfere automação, então só ele precisa da lista
            'donos'      => $this->svc->ehGestor() ? $this->svc->donosPossiveis() : [],
        ], 'admin');
    }

    /** Galeria de receitas — a tela de "Nova Automação". */
    public function nova(): void
    {
        $this->render('chat/automacao-nova', [
            'titulo'   => 'Nova automação',
            'receitas' => ChatIgReceitaService::paraGaleria(),
            'pastas'   => $this->svc->pastas(),
            'contaOk'  => $this->ig->contaPadrao() !== null,
        ], 'admin');
    }

    public function criar(): void
    {
        $this->verifyCsrf();

        $r = $this->svc->criarDaReceita(
            (string)($_POST['receita'] ?? 'zero'),
            trim((string)($_POST['nome'] ?? '')) ?: null,
            (int)($_POST['pasta_id'] ?? 0) ?: null
        );

        if (!$r['ok']) { $this->json($r); return; }

        $this->json([
            'ok'       => true,
            'id'       => $r['id'],
            'redirect' => BASE_URL . '/admin/chat/automacoes/' . $r['id'] . '/editar',
        ]);
    }

    // =========================================================================
    // EDITOR
    // =========================================================================

    public function editar($id): void
    {
        $id = SecurityHelper::sanitizeInt($id);
        $a  = $this->svc->obter($id);
        if (!$a) { $this->naoEncontrada(); return; }

        $receita = (string)$a['receita'];

        $this->render('chat/automacao-editor', [
            'titulo'    => $a['nome'],
            'automacao' => $a,
            'receita'   => ChatIgReceitaService::obter($receita),
            'campos'    => ChatIgReceitaService::campos($receita),
            'pastas'    => $this->svc->pastas(),
            'contas'    => $this->ig->contas(true),
            'midias'    => $this->ig->midias(null, 60),
            // Todos, não só os publicados: o fluxo criado junto com a automação
            // nasce rascunho, e com a lista filtrada ele não aparecia no select —
            // salvar a automação apagaria o vínculo recém-criado.
            'fluxos'    => (new ChatFluxoAdminService())->listar(),
            'tags'      => (new ChatContatoService())->listarTags(),
            'ehGestor'  => $this->svc->ehGestor(),
        ], 'admin');
    }

    public function salvar($id): void
    {
        $this->verifyCsrf();
        $id = SecurityHelper::sanitizeInt($id);

        $r = $this->svc->salvar($id, [
            'nome'                  => $_POST['nome']                  ?? '',
            'pasta_id'              => $_POST['pasta_id']              ?? 0,
            'conta_id'              => $_POST['conta_id']              ?? 0,
            'escopo'                => $_POST['escopo']                ?? 'todas',
            'midias'                => (array)($_POST['midias']        ?? []),
            'palavras'              => $_POST['palavras']              ?? '',
            'modo_match'            => $_POST['modo_match']            ?? 'contem',
            'ignorar_proprios'      => !empty($_POST['ignorar_proprios']),
            'ignorar_respostas'     => !empty($_POST['ignorar_respostas']),
            'responder_publico'     => !empty($_POST['responder_publico']),
            'resposta_publica'      => $_POST['resposta_publica']      ?? '',
            'enviar_dm'             => !empty($_POST['enviar_dm']),
            'mensagem_dm'           => $_POST['mensagem_dm']           ?? '',
            'exigir_seguidor'       => !empty($_POST['exigir_seguidor']),
            'mensagem_nao_seguidor' => $_POST['mensagem_nao_seguidor'] ?? '',
            'link_destino'          => $_POST['link_destino']          ?? '',
            'link_texto'            => $_POST['link_texto']            ?? '',
            'pedir_email'           => !empty($_POST['pedir_email']),
            'mensagem_email'        => $_POST['mensagem_email']        ?? '',
            'fluxo_id'              => $_POST['fluxo_id']              ?? 0,
            'tag_id'                => $_POST['tag_id']                ?? 0,
            'prioridade'            => $_POST['prioridade']            ?? 50,
            'uma_vez_por_pessoa'    => !empty($_POST['uma_vez_por_pessoa']),
        ]);

        if (!$r['ok'] && ($r['erro'] ?? '') === 'Automação não encontrada.') {
            $this->naoEncontrada(true);
            return;
        }
        $this->json($r);
    }

    // =========================================================================
    // INSIGHTS
    // =========================================================================

    public function show($id): void
    {
        $id = SecurityHelper::sanitizeInt($id);
        $a  = $this->svc->obter($id);
        if (!$a) { $this->naoEncontrada(); return; }

        $dias = max(7, min(90, (int)($_GET['dias'] ?? 30)));

        $this->render('chat/automacao-insights', [
            'titulo'    => $a['nome'],
            'automacao' => $a,
            'insights'  => $this->svc->insights($id, $dias),
            'dias'      => $dias,
            'receita'   => ChatIgReceitaService::obter((string)$a['receita']),
            'donos'     => $this->svc->ehGestor() ? $this->svc->donosPossiveis() : [],
            'ehGestor'  => $this->svc->ehGestor(),
        ], 'admin');
    }

    /** Números para o polling da tela de insights. */
    public function dados($id): void
    {
        $id = SecurityHelper::sanitizeInt($id);
        if (!$this->svc->obter($id)) { $this->naoEncontrada(true); return; }

        $dias = max(7, min(90, (int)($_GET['dias'] ?? 30)));
        $this->json(['ok' => true, 'insights' => $this->svc->insights($id, $dias)]);
    }

    // =========================================================================
    // CICLO DE VIDA
    // =========================================================================

    public function status($id): void
    {
        $this->verifyCsrf();
        $r = $this->svc->mudarStatus(SecurityHelper::sanitizeInt($id), (string)($_POST['status'] ?? ''));
        $this->json($r);
    }

    public function duplicar($id): void
    {
        $this->verifyCsrf();
        $r = $this->svc->duplicar(SecurityHelper::sanitizeInt($id));
        if ($r['ok']) $r['redirect'] = BASE_URL . '/admin/chat/automacoes/' . $r['id'] . '/editar';
        $this->json($r);
    }

    public function excluir($id): void
    {
        $this->verifyCsrf();
        $this->json($this->svc->excluir(SecurityHelper::sanitizeInt($id)));
    }

    public function restaurar($id): void
    {
        $this->verifyCsrf();
        $this->json($this->svc->restaurar(SecurityHelper::sanitizeInt($id)));
    }

    /** Remoção definitiva — só gestor. */
    public function remover($id): void
    {
        $this->verifyCsrf();
        $this->json($this->svc->excluirDefinitivo(SecurityHelper::sanitizeInt($id)));
    }

    public function mover($id): void
    {
        $this->verifyCsrf();
        $pasta = (int)($_POST['pasta_id'] ?? 0) ?: null;
        $this->json($this->svc->moverParaPasta(SecurityHelper::sanitizeInt($id), $pasta));
    }

    public function transferir($id): void
    {
        $this->verifyCsrf();
        $novo = (int)($_POST['usuario_id'] ?? 0) ?: null;
        $this->json($this->svc->transferir(SecurityHelper::sanitizeInt($id), $novo));
    }

    // =========================================================================
    // PASTAS
    // =========================================================================

    public function salvarPasta(): void
    {
        $this->verifyCsrf();
        $this->json($this->svc->salvarPasta(
            (string)($_POST['nome'] ?? ''),
            (string)($_POST['cor'] ?? '#64748b'),
            (int)($_POST['id'] ?? 0) ?: null
        ));
    }

    public function excluirPasta($id): void
    {
        $this->verifyCsrf();
        $this->json($this->svc->excluirPasta(SecurityHelper::sanitizeInt($id)));
    }
}
