<?php
/**
 * admin/controllers/ChatCampanhaController.php
 *
 * Campanhas de broadcast.
 *
 * Rotas:
 *   GET  /admin/chat/campanhas                 → listagem
 *   GET  /admin/chat/campanhas/nova            → formulário
 *   GET  /admin/chat/campanhas/{id}            → relatório
 *   GET  /admin/chat/campanhas/{id}/editar     → formulário de edição
 *   POST /admin/chat/campanhas/salvar
 *   POST /admin/chat/campanhas/estimar         → público estimado (ao vivo)
 *   POST /admin/chat/campanhas/{id}/iniciar|pausar|cancelar|excluir
 *   GET  /admin/chat/campanhas/{id}/dados      → progresso (polling)
 *
 * Permissão: disparo em massa tem custo direto por mensagem e afeta a
 * reputação do número na Meta → super/gerente, como promoções.
 */
class ChatCampanhaController extends Controller
{
    private ChatCampanhaService $svc;

    public function __construct()
    {
        AuthHelper::requireAdminLevel('super', 'gerente');
        $this->svc = new ChatCampanhaService();
    }

    // =========================================================================
    // LISTAGEM E RELATÓRIO
    // =========================================================================

    public function index(): void
    {
        $this->render('chat/campanhas', [
            'titulo'    => 'Chat — Campanhas',
            'campanhas' => $this->svc->listar(),
        ], 'admin');
    }

    public function show($id): void
    {
        $id = SecurityHelper::sanitizeInt($id);
        $c  = $this->svc->obter($id);
        if (!$c) { http_response_code(404); echo 'Campanha não encontrada.'; return; }

        $status = (string)($_GET['status'] ?? '');
        $pagina = max(1, (int)($_GET['pagina'] ?? 1));
        $dest   = $this->svc->destinatarios($id, $status, $pagina, 50);

        $this->render('chat/campanha-show', [
            'titulo'        => 'Campanha — ' . $c['nome'],
            'campanha'      => $c,
            'resumo'        => $this->svc->resumo($id),
            'destinatarios' => $dest['itens'],
            'total'         => $dest['total'],
            'pagina'        => $pagina,
            'porPagina'     => 50,
            'filtroStatus'  => $status,
        ], 'admin');
    }

    public function dados($id): void
    {
        $id = SecurityHelper::sanitizeInt($id);
        $c  = $this->svc->obter($id);
        if (!$c) { $this->json(['ok' => false], 404); return; }

        $this->json([
            'ok'     => true,
            'status' => $c['status'],
            'resumo' => $this->svc->resumo($id),
            'totais' => [
                'destinatarios' => (int)$c['total_destinatarios'],
                'enviados'      => (int)$c['total_enviados'],
                'entregues'     => (int)$c['total_entregues'],
                'lidos'         => (int)$c['total_lidos'],
                'falhas'        => (int)$c['total_falhas'],
                'pulados'       => (int)$c['total_pulados'],
            ],
        ]);
    }

    // =========================================================================
    // FORMULÁRIO
    // =========================================================================

    public function nova(): void
    {
        $this->formulario(null);
    }

    public function editar($id): void
    {
        $id = SecurityHelper::sanitizeInt($id);
        $c  = $this->svc->obter($id);
        if (!$c) { http_response_code(404); echo 'Campanha não encontrada.'; return; }

        if (!in_array($c['status'], ['rascunho', 'agendada', 'pausada'], true)) {
            $this->redirect(BASE_URL . '/admin/chat/campanhas/' . $id);
            return;
        }
        $this->formulario($c);
    }

    private function formulario(?array $campanha): void
    {
        $this->render('chat/campanha-form', [
            'titulo'    => $campanha ? 'Editar campanha' : 'Nova campanha',
            'campanha'  => $campanha,
            'templates' => (new ChatTemplateService())->aprovados(),
            'tags'      => (new ChatContatoService())->listarTags(),
            'fluxos'    => (new ChatFluxoAdminService())->listarPublicados(),
        ], 'admin');
    }

    public function salvar(): void
    {
        $this->verifyCsrf();
        $id = (int)($_POST['id'] ?? 0) ?: null;

        $r = $this->svc->salvar([
            'nome'             => $_POST['nome'] ?? '',
            'tipo'             => $_POST['tipo'] ?? 'template',
            'template_nome'    => $_POST['template_nome'] ?? '',
            'template_idioma'  => $_POST['template_idioma'] ?? 'pt_BR',
            'vars_body'        => (array)($_POST['vars_body'] ?? []),
            'var_header'       => $_POST['var_header'] ?? '',
            'var_botao'        => $_POST['var_botao'] ?? '',
            'mensagem'         => $_POST['mensagem'] ?? '',
            'fluxo_id'         => $_POST['fluxo_id'] ?? 0,
            'agendado_para'    => $_POST['agendado_para'] ?? '',
            'ritmo_por_minuto' => $_POST['ritmo_por_minuto'] ?? 60,
            'segmento'         => $this->segmentoDoPost(),
        ], $id, AuthHelper::usuarioId());

        $this->json($r);
    }

    private function segmentoDoPost(): array
    {
        return [
            'tags'         => array_filter(array_map('intval', (array)($_POST['tags'] ?? []))),
            'tags_modo'    => (string)($_POST['tags_modo'] ?? 'qualquer'),
            'tags_excluir' => array_filter(array_map('intval', (array)($_POST['tags_excluir'] ?? []))),
            'janela'       => (string)($_POST['janela'] ?? ''),
            'com_cliente'  => $_POST['com_cliente'] ?? '',
            'origem'       => (string)($_POST['origem'] ?? ''),
            'desde'        => (string)($_POST['desde'] ?? ''),
            'ate'          => (string)($_POST['ate'] ?? ''),
        ];
    }

    /** Público estimado — atualiza enquanto o gestor mexe nos filtros. */
    public function estimar(): void
    {
        $this->verifyCsrf();
        $total = $this->svc->estimarPorFiltros(
            $this->segmentoDoPost(),
            (string)($_POST['tipo'] ?? 'template')
        );
        $this->json(['ok' => true, 'total' => $total]);
    }

    // =========================================================================
    // EXECUÇÃO
    // =========================================================================

    public function iniciar($id): void
    {
        $this->verifyCsrf();
        $this->json($this->svc->iniciar(SecurityHelper::sanitizeInt($id)));
    }

    public function pausar($id): void
    {
        $this->verifyCsrf();
        $this->svc->pausar(SecurityHelper::sanitizeInt($id));
        $this->json(['ok' => true]);
    }

    public function cancelar($id): void
    {
        $this->verifyCsrf();
        $this->svc->cancelar(SecurityHelper::sanitizeInt($id));
        $this->json(['ok' => true]);
    }

    public function excluir($id): void
    {
        $this->verifyCsrf();
        $ok = $this->svc->excluir(SecurityHelper::sanitizeInt($id));
        $this->json(['ok' => $ok, 'erro' => $ok ? null : 'Campanha em envio não pode ser excluída.']);
    }
}
