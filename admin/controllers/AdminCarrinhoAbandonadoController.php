<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/controllers/AdminCarrinhoAbandonadoController.php
// RBAC: atendimento opera; gerente/super gerenciam.
// Todos os POSTs com verifyCsrf().
// ════════════════════════════════════════════════════════

class AdminCarrinhoAbandonadoController extends Controller {

    private CarrinhoAbandonado         $model;
    private CarrinhoRecuperacaoService $service;

    public function __construct() {
        AuthHelper::requireAdmin();

        // Garante a identidade resolvida (cobre sessões abertas antes
        // do deploy) e BLOQUEIA admin sem vínculo: ação com autor 0
        // corrompe responsavel_id e a trilha de auditoria — integridade
        // de dados aqui é requisito de segurança, não cosmética.
        if (AuthHelper::usuarioId() <= 0) {
            http_response_code(403);
            exit('Seu acesso admin não está vinculado a um usuário do sistema. Contate o administrador.');
        }

        $this->model   = new CarrinhoAbandonado();
        $this->service = new CarrinhoRecuperacaoService();
    }

    // ── GET /admin/carrinhos-abandonados ──────────────────
    public function index(): void {
        $filtros = [
            'q'              => SecurityHelper::sanitizeString($_GET['q']              ?? ''),
            'status'         => SecurityHelper::sanitizeString($_GET['status']         ?? ''),
            'prioridade'     => SecurityHelper::sanitizeString($_GET['prioridade']     ?? ''),
            'responsavel_id' => (int)($_GET['responsavel_id'] ?? 0),
            'data_de'        => SecurityHelper::sanitizeString($_GET['data_de']        ?? ''),
            'data_ate'       => SecurityHelper::sanitizeString($_GET['data_ate']       ?? ''),
            'valor_min'      => SecurityHelper::sanitizeString($_GET['valor_min']      ?? ''),
            'valor_max'      => SecurityHelper::sanitizeString($_GET['valor_max']      ?? ''),
            'contato'        => SecurityHelper::sanitizeString($_GET['contato']        ?? ''),
            'ordenar'        => SecurityHelper::sanitizeString($_GET['ordenar']        ?? 'prioridade'),
        ];
        $page = max(1, (int)($_GET['page'] ?? 1));

        $resultado = $this->model->listar(
            $filtros, $page, 25,
            $this->ehGestor() ? null : (int)Session::get('usuario_id')
        );

        $this->render('carrinhos-abandonados/index', [
            'rows'          => $resultado['rows'],
            'total'         => $resultado['total'],
            'page'          => $page,
            'totalPaginas'  => (int)ceil($resultado['total'] / 25),
            'filtros'       => $filtros,
            'responsaveis'  => $this->model->getResponsaveis(),
            'responsavel_id' => ($_GET['responsavel_id'] ?? '') === 'pool'
                                ? 'pool' : (int)($_GET['responsavel_id'] ?? 0),
            'ehGestor' => $this->ehGestor(),
            'ehSuper'  => AuthHelper::hasLevel('super'),
        ], 'admin');
    }

    // ── GET /admin/carrinhos-abandonados/dashboard ────────
    public function dashboard(): void {
        $de  = SecurityHelper::sanitizeString($_GET['de']  ?? date('Y-m-d', strtotime('-30 days')));
        $ate = SecurityHelper::sanitizeString($_GET['ate'] ?? date('Y-m-d'));

        // Datas validadas contra formato — não confiar em input
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $de))  $de  = date('Y-m-d', strtotime('-30 days'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate)) $ate = date('Y-m-d');

        $this->render('carrinhos-abandonados/dashboard', [
            'dados' => $this->model->dashboard($de, $ate),
            'de'    => $de,
            'ate'   => $ate,
        ], 'admin');
    }

    // ── GET /admin/carrinhos-abandonados/{id} ─────────────
    public function show(int $id): void {
        $rec = $this->model->findById($id);
        if (!$rec || !$this->podeAcessar($rec)) {
            $this->json(['ok' => false, 'msg' => 'Carrinho não encontrado.']);
            return;
        }

        $this->render('carrinhos-abandonados/show', [
            'rec'           => $rec,
            'itens'         => $this->model->getItens((int)$rec['carrinho_id']),
            'eventos'       => $this->model->getEventos($id),
            'responsaveis'  => $this->model->getResponsaveis(),
            'templatesWpp'  => $this->service->listarTemplates('whatsapp'),
            'templatesMail' => $this->service->listarTemplates('email'),
            'ehGestor' => $this->ehGestor(),
            'ehSuper'  => AuthHelper::hasLevel('super'),
        ], 'admin');
    }

    // ── POST /admin/carrinhos-abandonados/{id}/status ─────
    public function mudarStatus(int $id): void {
        $this->verifyCsrf();
        $this->json($this->service->mudarStatus(
            $id,
            SecurityHelper::sanitizeString($_POST['status'] ?? ''),
            (int)Session::get('usuario_id'),
            SecurityHelper::sanitizeString($_POST['motivo'] ?? '')
        ));
    }

    // ── POST /admin/carrinhos-abandonados/{id}/responsavel ─
    public function atribuir(int $id): void {
        $this->verifyCsrf();
        AuthHelper::requireAdminLevel('super', 'gerente');
 
        // Guard de escopo: gerente/super enxergam tudo, então o
        // podeAcessar passa; mantém a checagem por consistência
        // com as demais actions por ID.
        $rec = $this->model->findById($id);
        if (!$rec || !$this->podeAcessar($rec)) {
            $this->json(['ok' => false, 'msg' => 'Carrinho não encontrado.']);
            return;
        }
 
        $this->json($this->service->atribuirResponsavel(
            $id,
            (int)($_POST['responsavel_id'] ?? 0),
            (int)Session::get('usuario_id'),
            AuthHelper::hasLevel('super')  // só super reatribui/transfere
        ));
    }

    // ── POST /admin/carrinhos-abandonados/{id}/anotacao ───
    public function anotar(int $id): void {
        $this->verifyCsrf();
        $this->json($this->service->anotar(
            $id,
            SecurityHelper::sanitizeString($_POST['texto'] ?? ''),
            (int)Session::get('usuario_id')
        ));
    }

    // ── POST /admin/carrinhos-abandonados/{id}/agendar ────
    public function agendar(int $id): void {
        $this->verifyCsrf();
        $this->json($this->service->agendarContato(
            $id,
            SecurityHelper::sanitizeString($_POST['quando'] ?? ''),
            (int)Session::get('usuario_id')
        ));
    }

    // ── POST /admin/carrinhos-abandonados/{id}/whatsapp ───
    public function whatsapp(int $id): void {
        $this->verifyCsrf();
        $this->json($this->service->prepararWhatsapp($id, (int)($_POST['template_id'] ?? 0), (int)Session::get('usuario_id'), $this->ehGestor()));
    }

    // ── POST /admin/carrinhos-abandonados/{id}/email ──────
    public function email(int $id): void {
        $this->verifyCsrf();
        $this->json($this->service->enviarEmail(
            $id, (int)($_POST['template_id'] ?? 0), (int)Session::get('usuario_id')
        ));
    }

    // ── POST /admin/carrinhos-abandonados/{id}/link ───────
    public function gerarLink(int $id): void {
        $this->verifyCsrf();
        $token = $this->service->gerarToken($id);
        $this->json($token
            ? ['ok' => true, 'link' => BASE_URL . '/carrinho/recuperar/' . $token]
            : ['ok' => false, 'msg' => 'Registro não encontrado.']);
    }

    // ── GET /admin/carrinhos-abandonados/exportar ─────────
    public function exportar(): void {
        AuthHelper::requireAdminLevel('super', 'gerente');

        $filtros   = ['ordenar' => 'data'];
        $resultado = $this->model->listar($filtros, 1, 5000);

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename=carrinhos-abandonados-'
            . date('Y-m-d') . '.csv');

        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF"); // BOM UTF-8 para Excel
        fputcsv($out, ['ID','Cliente','Telefone','Email','Status','Prioridade',
                       'Valor','Itens','Abandonado em','Responsável'], ';');
        foreach ($resultado['rows'] as $r) {
            fputcsv($out, [
                $r['id'], $r['cliente_nome'] ?? 'Não identificado',
                $r['cliente_telefone'] ?? '', $r['cliente_email'] ?? '',
                $r['status'], $r['prioridade'],
                number_format((float)$r['valor_snapshot'], 2, ',', '.'),
                $r['itens_snapshot'], $r['abandonado_em'],
                $r['responsavel_nome'] ?? '',
            ], ';');
        }
        fclose($out);
        exit;
    }

    
// ════════════════════════════════════════════════════════
// PATCH — app/controllers/AdminCarrinhoAbandonadoController.php
// ÂNCORA: colar DENTRO da classe, após o método exportar(),
// antes do fechamento "}" da classe.
// (Substitui AdminRecuperacaoTemplateController.php — descartado.)
//
// RBAC: o construtor da classe usa requireAdmin() para o nível
// atendimento operar a Central. Templates são comunicação em
// massa — por isso CADA método abaixo abre com
// requireAdminLevel('super','gerente'). Qualquer método de
// template criado no futuro DEVE seguir o mesmo padrão.
// ════════════════════════════════════════════════════════
 
    // ══════════════════════════════════════════════════
    // TEMPLATES DE RECUPERAÇÃO
    // ══════════════════════════════════════════════════
 
    // ── GET /admin/carrinhos-abandonados/templates ────────
    public function templatesIndex(): void {
        AuthHelper::requireAdminLevel('super', 'gerente');
 
        $canal = SecurityHelper::sanitizeString($_GET['canal'] ?? '');
        $canal = in_array($canal, ['whatsapp', 'email'], true) ? $canal : null;
 
        $this->render('carrinhos-abandonados/templates/index', [
            'templates' => $this->model->templatesListar($canal),
            'canal'     => $canal,
            'salvo'     => !empty($_GET['salvo']),
        ], 'admin');
    }
 
    // ── GET /admin/carrinhos-abandonados/templates/novo ───
    public function templatesNovo(): void {
        AuthHelper::requireAdminLevel('super', 'gerente');
 
        $this->render('carrinhos-abandonados/templates/form', [
            'template' => null,
            'erro'     => null,
        ], 'admin');
    }
 
    // ── POST /admin/carrinhos-abandonados/templates/novo ──
    public function templatesCriar(): void {
        AuthHelper::requireAdminLevel('super', 'gerente');
        $this->verifyCsrf();
        try {
            $this->model->templateSalvar($this->coletarTemplate());
            $this->redirect(ADMIN_URL . '/carrinhos-abandonados/templates?salvo=1');
        } catch (\InvalidArgumentException $e) {
            // Re-renderiza com valores digitados — operador não perde
            // um template de 2000 caracteres por causa de um typo
            $this->render('carrinhos-abandonados/templates/form', [
                'template' => $this->coletarTemplate(),
                'erro'     => $e->getMessage(),
            ], 'admin');
        }
    }
 
    // ── GET /admin/carrinhos-abandonados/templates/{id} ───
    public function templatesEditar(int $id): void {
        AuthHelper::requireAdminLevel('super', 'gerente');
 
        $tpl = $this->model->templateFindById($id);
        if (!$tpl) {
            http_response_code(404);
            $this->render('errors/404', [], 'admin');
            return;
        }
        $this->render('carrinhos-abandonados/templates/form', [
            'template' => $tpl,
            'erro'     => null,
        ], 'admin');
    }
 
    // ── POST /admin/carrinhos-abandonados/templates/{id} ──
    public function templatesAtualizar(int $id): void {
        AuthHelper::requireAdminLevel('super', 'gerente');
        $this->verifyCsrf();
        try {
            $this->model->templateSalvar($this->coletarTemplate(), $id);
            $this->redirect(ADMIN_URL . '/carrinhos-abandonados/templates?salvo=1');
        } catch (\InvalidArgumentException $e) {
            $dados       = $this->coletarTemplate();
            $dados['id'] = $id;
            // Canal real vem do banco — imutável na edição
            $atual = $this->model->templateFindById($id);
            if ($atual) $dados['canal'] = $atual['canal'];
 
            $this->render('carrinhos-abandonados/templates/form', [
                'template' => $dados,
                'erro'     => $e->getMessage(),
            ], 'admin');
        }
    }
 
    // ── POST /admin/carrinhos-abandonados/templates/{id}/toggle ─
    public function templatesToggle(int $id): void {
        AuthHelper::requireAdminLevel('super', 'gerente');
        $this->verifyCsrf();
        if (!$this->model->templateFindById($id)) {
            $this->json(['ok' => false, 'msg' => 'Template não encontrado.']);
            return;
        }
        $this->model->templateToggleAtivo($id);
        $this->json(['ok' => true]);
    }
 
    // ── POST /admin/carrinhos-abandonados/templates/{id}/excluir ─
    public function templatesExcluir(int $id): void {
        AuthHelper::requireAdminLevel('super', 'gerente');
        $this->verifyCsrf();
        if (!$this->model->templateFindById($id)) {
            $this->json(['ok' => false, 'msg' => 'Template não encontrado.']);
            return;
        }
        $this->json($this->model->templateExcluir($id));
    }
 
    /**
     * Coleta do POST. O campo `conteudo` deliberadamente NÃO passa
     * por sanitizeString: templates de e-mail contêm HTML legítimo
     * (<p>, <a href>) que sanitização de entrada destruiria. A defesa
     * correta é (a) whitelist de variáveis no model, (b) escape na
     * SAÍDA (View::e na listagem), (c) preview em iframe sandbox,
     * (d) RBAC gerente+ — conteúdo é trusted-admin por design.
     */
    private function coletarTemplate(): array {
        return [
            'nome'            => SecurityHelper::sanitizeString($_POST['nome']            ?? ''),
            'canal'           => SecurityHelper::sanitizeString($_POST['canal']           ?? ''),
            'assunto'         => SecurityHelper::sanitizeString($_POST['assunto']         ?? ''),
            'conteudo'        => trim((string)($_POST['conteudo'] ?? '')),
            'uso_recomendado' => SecurityHelper::sanitizeString($_POST['uso_recomendado'] ?? ''),
            'ativo'           => isset($_POST['ativo']) ? 1 : 0,
        ];
    }


    // ══════════════════════════════════════════════════
    // AUTOMAÇÃO CONFIGURÁVEL
    // ══════════════════════════════════════════════════
 
    // ── GET /admin/carrinhos-abandonados/config ───────────
    public function configForm(): void {
        AuthHelper::requireAdminLevel('super', 'gerente');
 
        $this->render('carrinhos-abandonados/config', [
            'config' => $this->model->configListar(),
            'schema' => CarrinhoAbandonado::CONFIG_SCHEMA,
            'salvo'  => !empty($_GET['salvo']),
            'erro'   => null,
        ], 'admin');
    }
 
    // ── POST /admin/carrinhos-abandonados/config ──────────
    public function configSalvar(): void {
        AuthHelper::requireAdminLevel('super', 'gerente');
        $this->verifyCsrf();
 
        $resultado = $this->model->configSalvar($_POST, (int)Session::get('usuario_id'));
 
        if ($resultado['ok']) {
            $this->redirect(ADMIN_URL . '/carrinhos-abandonados/config?salvo=1');
            return;
        }
 
        // Erro de bounds: re-renderiza com os valores VIGENTES do banco
        // (não os inválidos) — evita o operador salvar em cima de valor
        // rejeitado sem perceber
        $this->render('carrinhos-abandonados/config', [
            'config' => $this->model->configListar(),
            'schema' => CarrinhoAbandonado::CONFIG_SCHEMA,
            'salvo'  => false,
            'erro'   => $resultado['msg'],
        ], 'admin');
    }
 
    // ══════════════════════════════════════════════════
    // RELATÓRIO — conversão por template
    // ══════════════════════════════════════════════════
 
    // ── GET /admin/carrinhos-abandonados/relatorio-templates ─
    public function relatorioTemplates(): void {
        AuthHelper::requireAdminLevel('super', 'gerente');
 
        $de  = SecurityHelper::sanitizeString($_GET['de']  ?? date('Y-m-d', strtotime('-30 days')));
        $ate = SecurityHelper::sanitizeString($_GET['ate'] ?? date('Y-m-d'));
 
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $de))  $de  = date('Y-m-d', strtotime('-30 days'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate)) $ate = date('Y-m-d');
 
        $this->render('carrinhos-abandonados/relatorio-templates', [
            'linhas' => $this->model->relatorioConversaoTemplates($de, $ate),
            'de'     => $de,
            'ate'    => $ate,
        ], 'admin');
    }

    /**
     * Gerente/super têm visão total; atendimento vê pool + seus.
     * Se AuthHelper::hasLevel() não existir no seu projeto, crie:
     *   public static function hasLevel(string ...$niveis): bool {
     *       return in_array(Session::get('admin_nivel'), $niveis, true);
     *   }
     * (ajuste 'admin_nivel' à chave real de sessão do seu RBAC)
     */
    private function ehGestor(): bool {
        // return AuthHelper::hasLevel('super', 'gerente');
        return true;
    }
 
    /**
     * Guard anti-IDOR de escopo: atendimento só acessa carrinho do
     * pool ou próprio. OBRIGATÓRIO em toda action que recebe {id} —
     * listagem filtrada com show() aberto seria a falha clássica.
     */
    private function podeAcessar(array $rec): bool {
        if ($this->ehGestor()) return true;
        $donoId = (int)($rec['responsavel_id'] ?? 0);
        return $donoId === 0 || $donoId === (int)Session::get('usuario_id');
    }
 
    // ── POST /admin/carrinhos-abandonados/{id}/capturar ───
    public function capturar(int $id): void {
        $this->verifyCsrf();
        $this->json($this->service->capturar($id, (int)Session::get('usuario_id')));
    }
}