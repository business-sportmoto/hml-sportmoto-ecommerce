<?php
/**
 * admin/controllers/EmailCampaignAdminController.php
 */
class EmailCampaignAdminController extends Controller
{
    /** @var EmailCampaign */
    private $model;
    /** @var EmailCampaignService */
    private $svc;
    /** @var EmailQueueService */
    private $queue;

    public function __construct()
    {
        // parent::__construct();
        $this->requirePermission();
        $this->model = new EmailCampaign();
        $this->svc   = new EmailCampaignService();
        $this->queue = new EmailQueueService();
    }

    private function requirePermission()
    {
        if (method_exists('AuthHelper', 'requirePermission')) {
            try { AuthHelper::requirePermission('email_marketing'); return; } catch (Throwable $e) {}
        }
        if (method_exists('AuthHelper', 'requireAdminLevel')) {
            AuthHelper::requireAdminLevel(); return;
        }
        AuthHelper::requireAdmin();
    }

    public function index()
    {
        $filtros = [
            'status' => $_GET['status'] ?? '',
            'busca'  => trim((string)($_GET['busca'] ?? '')),
        ];
        $pagina = max(1, (int)($_GET['pagina'] ?? 1));
        $resultado = $this->model->all($filtros, $pagina, 50);

        $this->render('email-marketing/campanhas/index', [
            'resultado' => $resultado,
            'filtros'   => $filtros,
            'titulo'    => 'Campanhas',
        ], 'admin');
    }

    public function criar()
    {
        $this->renderForm(null);
    }

    public function editar($id)
    {
        $c = $this->model->find($id);
        if (!$c) { header('Location: ' . BASE_URL . '/admin/email-marketing/campanhas'); exit; }
        $this->renderForm($c);
    }

    private function renderForm($camp)
    {
        $this->render('email-marketing/campanhas/form', [
            'item' => $camp,
            'provedores' => (new EmailProvider())->all(true),
            'templates'  => (new EmailTemplate())->all(true),
            'listas'     => (new EmailList())->all(true),
            'segmentos'  => (new EmailSegment())->all(true),
            'titulo'     => $camp ? 'Editar Campanha' : 'Nova Campanha',
        ], 'admin');
    }

    public function salvar()
    {
        $this->verifyCsrf();
        $id = (int)($_POST['id'] ?? 0);
        $nome = trim((string)($_POST['nome'] ?? ''));
        $providerId = (int)($_POST['provedor_id'] ?? 0);
        $tplId = (int)($_POST['template_id'] ?? 0);
        $listaId = (int)($_POST['lista_id'] ?? 0);
        $segId   = (int)($_POST['segmento_id'] ?? 0);

        if ($nome === '' || !$providerId || !$tplId) {
            return $this->json(['ok' => false, 'erro' => 'Nome, provedor e template são obrigatórios']);
        }
        if (!$listaId && !$segId) {
            return $this->json(['ok' => false, 'erro' => 'Selecione uma lista OU um segmento']);
        }

        $agendada = trim((string)($_POST['agendada_para'] ?? '')) ?: null;
        if ($agendada) {
            // converte do formato datetime-local para timestamp do MySQL
            $agendada = str_replace('T', ' ', $agendada);
            if (strlen($agendada) === 16) $agendada .= ':00';
        }

        try {
            $novoId = $this->model->save([
                'id' => $id,
                'nome' => $nome,
                'provedor_id' => $providerId,
                'template_id' => $tplId,
                'lista_id'    => $listaId ?: null,
                'segmento_id' => $segId ?: null,
                'assunto_override'   => trim((string)($_POST['assunto_override'] ?? '')) ?: null,
                'preheader_override' => trim((string)($_POST['preheader_override'] ?? '')) ?: null,
                'remetente_email'    => trim((string)($_POST['remetente_email'] ?? '')) ?: null,
                'remetente_nome'     => trim((string)($_POST['remetente_nome'] ?? '')) ?: null,
                'reply_to'           => trim((string)($_POST['reply_to'] ?? '')) ?: null,
                'agendada_para'      => $agendada,
                'batch_size'         => (int)($_POST['batch_size'] ?? 200),
                'intervalo_segundos' => (int)($_POST['intervalo_segundos'] ?? 1),
                'criado_por'         => isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
            ]);
            return $this->json(['ok' => true, 'id' => $novoId]);
        } catch (Throwable $e) {
            return $this->json(['ok' => false, 'erro' => $e->getMessage()]);
        }
    }

    public function testar()
    {
        $this->verifyCsrf();
        $id = (int)($_POST['id'] ?? 0);
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        if (!$id || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['ok' => false, 'erro' => 'Parâmetros inválidos']);
        }
        try {
            $res = $this->svc->enviarTeste($id, $email);
            return $res->success
                ? $this->json(['ok' => true, 'message_id' => $res->providerMessageId])
                : $this->json(['ok' => false, 'erro' => $res->error]);
        } catch (Throwable $e) {
            return $this->json(['ok' => false, 'erro' => $e->getMessage()]);
        }
    }

    public function enfileirar()
    {
        $this->verifyCsrf();
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) return $this->json(['ok' => false, 'erro' => 'ID inválido']);
        try {
            $r = $this->queue->enfileirar($id);
            if (class_exists('LogService')) {
                LogService::audit('email_campanha_enfileirar', array_merge(['id' => $id], $r));
            }
            return $this->json(['ok' => true, 'resultado' => $r]);
        } catch (Throwable $e) {
            return $this->json(['ok' => false, 'erro' => $e->getMessage()]);
        }
    }

    public function pausar()    { $this->verifyCsrf(); return $this->acao('pausar'); }
    public function continuar() { $this->verifyCsrf(); return $this->acao('continuar'); }
    public function cancelar()  { $this->verifyCsrf(); return $this->acao('cancelar'); }

    private function acao($metodo)
    {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) return $this->json(['ok' => false, 'erro' => 'ID inválido']);
        try {
            $this->svc->$metodo($id);
            if (class_exists('LogService')) {
                LogService::audit('email_campanha_' . $metodo, ['id' => $id]);
            }
            return $this->json(['ok' => true]);
        } catch (Throwable $e) {
            return $this->json(['ok' => false, 'erro' => $e->getMessage()]);
        }
    }

    public function duplicar()
    {
        $this->verifyCsrf();
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) return $this->json(['ok' => false, 'erro' => 'ID inválido']);
        try {
            $novoId = $this->svc->duplicar($id);
            return $this->json(['ok' => true, 'id' => $novoId]);
        } catch (Throwable $e) {
            return $this->json(['ok' => false, 'erro' => $e->getMessage()]);
        }
    }

    public function relatorio($id)
    {
        $camp = $this->model->find($id);
        if (!$camp) { header('Location: ' . BASE_URL . '/admin/email-marketing/campanhas'); exit; }

        $rec = new EmailCampaignRecipient();
        $links = (new EmailLink())->porCampanha($id);

        $this->render('email-marketing/campanhas/relatorio', [
            'item'    => $camp,
            'status'  => $rec->contarPorStatus($id),
            'links'   => $links,
            'titulo'  => 'Relatório — ' . $camp['nome'],
        ], 'admin');
    }
}
