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
        // 4º argumento (override de gestor) espelha o whatsapp(): sem ele,
        // gerente destravava carrinho alheio num canal e não no outro.
        $this->json($this->service->enviarEmail(
            $id, (int)($_POST['template_id'] ?? 0), (int)Session::get('usuario_id'),
            $this->ehGestor()
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

            // Cabeçalho, rodapé e botões — só WhatsApp, e só em mensagem
            // interativa. O model zera tudo isso quando o canal é e-mail.
            'cabecalho_tipo'  => SecurityHelper::sanitizeString($_POST['cabecalho_tipo']  ?? 'nenhum'),
            // O valor NÃO passa por sanitizeString: quando é mídia, é uma URL,
            // e escapar & de query string quebraria o endereço. O model valida
            // com FILTER_VALIDATE_URL, e a saída escapa na view.
            'cabecalho_valor' => trim((string)($_POST['cabecalho_valor'] ?? '')),
            'rodape'          => SecurityHelper::sanitizeString($_POST['rodape'] ?? ''),
            'botoes'          => $this->coletarBotoes(),
        ];
    }

    /**
     * Limites e formatos do cabeçalho, por tipo.
     *
     * São da Meta, não nossos. Barrar aqui dá mensagem clara; deixar passar dá
     * erro genérico da API depois de o arquivo já estar no disco.
     */
    private const CABECALHO_FORMATOS = [
        'imagem'    => ['exts' => ['jpg', 'jpeg', 'png'],
                        'mb'   => 5,   'rotulo' => 'Imagem'],
        'video'     => ['exts' => ['mp4', '3gp'],
                        'mb'   => 16,  'rotulo' => 'Vídeo'],
        'documento' => ['exts' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt'],
                        'mb'   => 100, 'rotulo' => 'Documento'],
    ];

    /** Conteúdo que nunca pode ficar público no nosso domínio. */
    private const MIME_PERIGOSO = [
        'text/html', 'application/xhtml+xml', 'image/svg+xml',
        'application/x-msdownload', 'application/x-executable',
        'application/x-dosexec', 'application/x-sh', 'text/x-php',
        'application/x-httpd-php',
    ];

    // ── POST /admin/carrinhos-abandonados/templates/upload-cabecalho ──
    /**
     * Recebe a mídia do cabeçalho e devolve a URL pública.
     *
     * A Meta BUSCA o arquivo nessa URL na hora do envio — ela não recebe os
     * bytes de nós. Por isso o endereço precisa ser alcançável pela internet:
     * num ambiente local o upload funciona e o envio falha, e o motivo não é
     * óbvio. O aviso está na tela.
     */
    public function templatesUploadCabecalho(): void {
        AuthHelper::requireAdminLevel('super', 'gerente');
        $this->verifyCsrf();

        $tipo = SecurityHelper::sanitizeString($_POST['tipo'] ?? '');
        if (!isset(self::CABECALHO_FORMATOS[$tipo])) {
            $this->json(['ok' => false, 'msg' => 'Escolha o tipo do cabeçalho antes de enviar.']);
            return;
        }
        $regra = self::CABECALHO_FORMATOS[$tipo];

        $f = $_FILES['arquivo'] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->json(['ok' => false, 'msg' => $this->erroUpload((int)($f['error'] ?? UPLOAD_ERR_NO_FILE))]);
            return;
        }
        // Só o arquivo que veio no POST — barra caminho forjado
        if (!is_uploaded_file((string)$f['tmp_name'])) {
            $this->json(['ok' => false, 'msg' => 'Upload inválido.']);
            return;
        }

        // O FORMATO vem da extensão; o sniff decide SEGURANÇA. mime_content_type
        // erra demais para servir de whitelist de formato, mas é confiável para
        // dizer "isto é HTML" — que é o que não pode ficar público aqui.
        $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $regra['exts'], true)) {
            $this->json(['ok' => false, 'msg' => $regra['rotulo'] . ': aceita apenas '
                . implode(', ', $regra['exts']) . '.']);
            return;
        }

        $sniff = function_exists('mime_content_type')
            ? strtolower(trim(explode(';', (string)mime_content_type((string)$f['tmp_name']))[0]))
            : '';
        if ($sniff !== '' && in_array($sniff, self::MIME_PERIGOSO, true)) {
            $this->json(['ok' => false,
                'msg' => "O conteúdo do arquivo não confere com .{$ext} ({$sniff}). Envio bloqueado."]);
            return;
        }

        if ((int)$f['size'] > $regra['mb'] * 1024 * 1024) {
            $this->json(['ok' => false,
                'msg' => $regra['rotulo'] . " pode ter no máximo {$regra['mb']} MB."]);
            return;
        }

        $rel = 'uploads/templates/' . date('Y/m');
        $dir = (defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 2)) . '/' . $rel;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->json(['ok' => false, 'msg' => 'Não foi possível criar a pasta de upload.']);
            return;
        }

        // Nome aleatório: o nome que o usuário deu nunca vira caminho no disco
        $nome    = bin2hex(random_bytes(16)) . '.' . $ext;
        $destino = $dir . '/' . $nome;
        if (!move_uploaded_file((string)$f['tmp_name'], $destino)) {
            $this->json(['ok' => false, 'msg' => 'Falha ao gravar o arquivo.']);
            return;
        }

        $url = (defined('BASE_URL') ? BASE_URL : '') . '/' . $rel . '/' . $nome;

        LogService::audit('recuperacao_template_upload', [
            'tipo' => $tipo, 'arquivo' => $nome, 'bytes' => (int)$f['size'],
        ]);

        $this->json(['ok' => true, 'url' => $url, 'nome' => (string)$f['name']]);
    }

    /** Mensagem para o código de erro do PHP, em vez de "erro 1". */
    private function erroUpload(int $codigo): string {
        return match ($codigo) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Arquivo maior que o limite do servidor.',
            UPLOAD_ERR_PARTIAL    => 'O envio foi interrompido. Tente de novo.',
            UPLOAD_ERR_NO_FILE    => 'Nenhum arquivo escolhido.',
            UPLOAD_ERR_NO_TMP_DIR,
            UPLOAD_ERR_CANT_WRITE => 'O servidor não conseguiu gravar o arquivo.',
            default               => 'Falha no envio do arquivo.',
        };
    }

    /**
     * Botões do template, no shape que o model normaliza.
     *
     * O formulário manda linhas paralelas (`botao_titulo[]`, `botao_link[]`,
     * `botao_url[]`); aqui elas viram uma lista de itens. O destino pode ser
     * uma variável pronta (`{link}`) ou um endereço digitado — quando é
     * `personalizado`, vale o campo livre.
     */
    private function coletarBotoes(): ?array {
        $tipo = SecurityHelper::sanitizeString($_POST['botoes_tipo'] ?? 'nenhum');
        if (!in_array($tipo, ['botoes', 'lista'], true)) return null;

        $titulos = (array)($_POST['botao_titulo'] ?? []);
        $links   = (array)($_POST['botao_link']   ?? []);
        $urls    = (array)($_POST['botao_url']    ?? []);
        $descs   = (array)($_POST['botao_desc']   ?? []);

        $itens = [];
        foreach ($titulos as $i => $titulo) {
            $titulo = SecurityHelper::sanitizeString((string)$titulo);
            if (trim($titulo) === '') continue;

            $escolha = SecurityHelper::sanitizeString((string)($links[$i] ?? '{link}'));
            $destino = $escolha === 'personalizado'
                ? trim((string)($urls[$i] ?? ''))
                : $escolha;

            $itens[] = [
                'titulo'    => $titulo,
                'url'       => $destino,
                'descricao' => SecurityHelper::sanitizeString((string)($descs[$i] ?? '')),
            ];
        }
        if (!$itens) return null;

        return [
            'tipo'        => $tipo,
            'itens'       => $itens,
            'texto_botao' => SecurityHelper::sanitizeString($_POST['botoes_texto_botao'] ?? ''),
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
     * Gerente/super têm visão total; vendedor vê pool + seus.
     *
     * Estava devolvendo `true` fixo, com a chamada real comentada — o que
     * desligava a visibilidade por linha inteira (CLAUDE.md §4.7): todo
     * admin via e operava todo carrinho, e o podeAcessar() nunca bloqueava.
     *
     * `super` passa por bypass do próprio hasLevel(), então não precisa
     * estar na lista — está por legibilidade.
     *
     * ATENÇÃO NO DEPLOY: se o ambiente ainda tiver admins com o nível
     * legado 'admin' (pré migration-cargos), eles deixam de ser gestores
     * aqui e passam a ver só o pool + os próprios. Falha para o lado
     * restritivo, que é o certo — mas confira `SELECT nivel, COUNT(*)
     * FROM admins GROUP BY nivel` antes de subir.
     */
    private function ehGestor(): bool {
        return AuthHelper::hasLevel('super', 'gerente');
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