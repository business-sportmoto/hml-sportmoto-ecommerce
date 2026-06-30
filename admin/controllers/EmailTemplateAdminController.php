<?php
/**
 * admin/controllers/EmailTemplateAdminController.php
 */
// class EmailTemplateAdminController extends Controller
// {
//     /** @var EmailTemplate */
//     private $model;
//     /** @var EmailTemplateService */
//     private $svc;

//     public function __construct()
//     {
//         // parent::__construct();
//         $this->requirePermission();
//         $this->model = new EmailTemplate();
//         $this->svc = new EmailTemplateService();
//     }

//     private function requirePermission()
//     {
//         if (method_exists('AuthHelper', 'requirePermission')) {
//             try { AuthHelper::requirePermission('email_marketing'); return; } catch (Throwable $e) {}
//         }
//         if (method_exists('AuthHelper', 'requireAdminLevel')) {
//             AuthHelper::requireAdminLevel(); return;
//         }
//         AuthHelper::requireAdmin();
//     }

//     public function index()
//     {
//         $itens = $this->model->all(false);
//         $this->render('email-marketing/templates/index', [
//             'itens' => $itens,
//             'titulo' => 'Templates de Email',
//         ], 'admin');
//     }

//     public function criar()
//     {
//         $this->render('email-marketing/templates/form', [
//             'item' => null,
//             'titulo' => 'Novo Template',
//         ], 'admin');
//     }

//     public function editar($id)
//     {
//         $tpl = $this->model->find($id);
//         if (!$tpl) { header('Location: ' . BASE_URL . '/admin/email-marketing/templates'); exit; }
//         $this->render('email-marketing/templates/form', [
//             'item' => $tpl,
//             'titulo' => 'Editar Template',
//         ], 'admin');
//     }

//     public function salvar()
//     {
//         $this->verifyCsrf();
//         $id = (int)($_POST['id'] ?? 0);
//         $nome = trim((string)($_POST['nome'] ?? ''));
//         $assunto = trim((string)($_POST['assunto'] ?? ''));
//         $html = (string)($_POST['html'] ?? '');
//         if ($nome === '' || $assunto === '' || $html === '') {
//             return $this->json(['ok' => false, 'erro' => 'Nome, assunto e HTML são obrigatórios']);
//         }
//         try {
//             $novoId = $this->model->save([
//                 'id'        => $id,
//                 'nome'      => $nome,
//                 'tipo'      => $_POST['tipo'] ?? 'marketing',
//                 'assunto'   => $assunto,
//                 'preheader' => trim((string)($_POST['preheader'] ?? '')) ?: null,
//                 'html'      => $this->svc->sanitizeHtml($html),
//                 'texto'     => trim((string)($_POST['texto'] ?? '')) ?: null,
//                 'status'    => $_POST['status'] ?? 'rascunho',
//             ]);
//             return $this->json(['ok' => true, 'id' => $novoId]);
//         } catch (Throwable $e) {
//             return $this->json(['ok' => false, 'erro' => $e->getMessage()]);
//         }
//     }

//     public function preview()
//     {
//         $this->verifyCsrf();
//         $html = $this->svc->sanitizeHtml((string)($_POST['html'] ?? ''));
//         $vars = [
//             'nome' => 'Maria Silva',
//             'primeiro_nome' => 'Maria',
//             'email' => 'maria@example.com',
//             'cupom' => 'BEMVINDO10',
//             'site_nome' => defined('SITE_NAME') ? SITE_NAME : 'SportMoto',
//             'url_site'  => defined('BASE_URL')  ? BASE_URL  : '',
//             'url_descadastro' => (defined('BASE_URL') ? BASE_URL : '') . '/email/descadastrar/PREVIEW',
//             'data_atual' => date('d/m/Y'),
//         ];
//         $rendered = $this->svc->render($html, $vars);
//         return $this->json(['ok' => true, 'html' => $rendered]);
//     }

//     public function excluir()
//     {
//         $this->verifyCsrf();
//         $id = (int)($_POST['id'] ?? 0);
//         if (!$id) return $this->json(['ok' => false, 'erro' => 'ID inválido']);
//         try {
//             $this->model->delete($id);
//             return $this->json(['ok' => true]);
//         } catch (Throwable $e) {
//             return $this->json(['ok' => false, 'erro' => $e->getMessage()]);
//         }
//     }
// }


/**
 * admin/controllers/EmailTemplateAdminController.php  (v2)
 *
 * SUBSTITUI o existente. Mantém endpoints originais e adiciona:
 *   - criarVisual / editarVisual — abre o editor GrapesJS
 *   - salvarVisual — recebe HTML+JSON+CSS do GrapesJS
 *   - versoes — lista o histórico
 *   - verVersao — exibe uma versão específica
 *   - restaurarVersao
 *   - duplicar
 */
class EmailTemplateAdminController extends Controller
{
    /** @var EmailTemplate */
    private $model;
    /** @var EmailTemplateService */
    private $svc;
    /** @var EmailTemplateVersionService */
    private $versions;

    public function __construct()
    {
        // parent::__construct();
        $this->requirePermission();
        $this->model = new EmailTemplate();
        $this->svc = new EmailTemplateService();
        if (class_exists('EmailTemplateVersionService')) {
            $this->versions = new EmailTemplateVersionService();
        }
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

    private function currentUserId(): ?int
    {
        return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    }

    public function index()
    {
        $itens = $this->model->all(false);
        $this->render('email-marketing/templates/index', [
            'itens' => $itens,
            'titulo' => 'Templates de Email',
        ], 'admin');
    }

    public function criar()
    {
        $this->render('email-marketing/templates/form', [
            'item' => null,
            'titulo' => 'Novo template (HTML manual)',
        ], 'admin');
    }

    public function criarVisual()
    {
        $this->render('email-marketing/templates/form_visual', [
            'item' => null,
            'titulo' => 'Novo template (visual)',
        ], 'admin');
    }

    public function editar($id)
    {
        $tpl = $this->model->find($id);
        if (!$tpl) { header('Location: ' . BASE_URL . '/admin/email-marketing/templates'); exit; }

        // Se o template foi criado em modo visual, abre o editor visual
        if (($tpl['formato'] ?? 'manual') === 'visual') {
            $this->render('email-marketing/templates/form_visual', [
                'item' => $tpl,
                'titulo' => 'Editar template (visual)',
            ], 'admin');
            return;
        }

        $this->render('email-marketing/templates/form', [
            'item' => $tpl,
            'titulo' => 'Editar template',
        ], 'admin');
    }

    public function salvar()
    {
        $this->verifyCsrf();
        $id = (int)($_POST['id'] ?? 0);
        $nome = trim((string)($_POST['nome'] ?? ''));
        $assunto = trim((string)($_POST['assunto'] ?? ''));
        $html = (string)($_POST['html'] ?? '');
        if ($nome === '' || $assunto === '' || $html === '') {
            return $this->json(['ok' => false, 'erro' => 'Nome, assunto e HTML são obrigatórios']);
        }
        try {
            // Sanitiza HTML — registra warnings
            $renderLog = [];
            $htmlSan = $this->svc->sanitizeHtml($html, $renderLog);
            $renderStatus = !empty($renderLog) ? 'warning' : 'ok';

            $novoId = $this->model->save([
                'id'        => $id,
                'nome'      => $nome,
                'tipo'      => $_POST['tipo'] ?? 'marketing',
                'formato'   => $_POST['formato'] ?? 'manual',
                'assunto'   => $assunto,
                'preheader' => trim((string)($_POST['preheader'] ?? '')) ?: null,
                'html'      => $htmlSan,
                'texto'     => trim((string)($_POST['texto'] ?? '')) ?: null,
                'status'    => $_POST['status'] ?? 'rascunho',
                'render_status' => $renderStatus,
                'render_log'    => $renderLog ? json_encode($renderLog) : null,
            ], $this->currentUserId());

            if (class_exists('LogService')) {
                LogService::audit('email_template_salvar', ['id' => $novoId]);
            }
            return $this->json([
                'ok' => true,
                'id' => $novoId,
                'avisos' => $renderLog,
            ]);
        } catch (Throwable $e) {
            return $this->json(['ok' => false, 'erro' => $e->getMessage()]);
        }
    }

    /** Endpoint específico para templates visuais — recebe HTML+JSON+CSS. */
    public function salvarVisual()
    {
        $this->verifyCsrf();
        $id = (int)($_POST['id'] ?? 0);
        $nome = trim((string)($_POST['nome'] ?? ''));
        $assunto = trim((string)($_POST['assunto'] ?? ''));
        $html = (string)($_POST['html'] ?? '');
        $sourceJson = (string)($_POST['source_json'] ?? '');
        $sourceCss = (string)($_POST['source_css'] ?? '');

        if ($nome === '' || $assunto === '' || $html === '') {
            return $this->json(['ok' => false, 'erro' => 'Nome, assunto e conteúdo são obrigatórios']);
        }

        try {
            // GrapesJS pode gerar HTML+CSS separados — concatena para o HTML final renderizado
            $htmlFinal = $html;
            if ($sourceCss) {
                // Embute o CSS no HTML
                $htmlFinal = '<style type="text/css">' . $sourceCss . '</style>' . "\n" . $html;
            }

            $renderLog = [];
            $htmlSan = $this->svc->sanitizeHtml($htmlFinal, $renderLog);
            $renderStatus = !empty($renderLog) ? 'warning' : 'ok';

            $novoId = $this->model->save([
                'id'          => $id,
                'nome'        => $nome,
                'tipo'        => $_POST['tipo'] ?? 'marketing',
                'formato'     => 'visual',
                'assunto'     => $assunto,
                'preheader'   => trim((string)($_POST['preheader'] ?? '')) ?: null,
                'html'        => $htmlSan,
                'source_json' => $sourceJson ?: null,
                'source_css'  => $sourceCss ?: null,
                'texto'       => trim((string)($_POST['texto'] ?? '')) ?: null,
                'status'      => $_POST['status'] ?? 'rascunho',
                'render_status' => $renderStatus,
                'render_log'    => $renderLog ? json_encode($renderLog) : null,
            ], $this->currentUserId());

            if (class_exists('LogService')) {
                LogService::audit('email_template_visual_salvar', ['id' => $novoId]);
            }
            return $this->json(['ok' => true, 'id' => $novoId, 'avisos' => $renderLog]);
        } catch (Throwable $e) {
            return $this->json(['ok' => false, 'erro' => $e->getMessage()]);
        }
    }

    public function preview()
    {
        $this->verifyCsrf();
        $html = $this->svc->sanitizeHtml((string)($_POST['html'] ?? ''));
        $vars = [
            'nome' => 'Maria Silva',
            'primeiro_nome' => 'Maria',
            'email' => 'maria@example.com',
            'cupom' => 'BEMVINDO10',
            'site_nome' => defined('SITE_NAME') ? SITE_NAME : 'SportMoto',
            'url_site'  => defined('BASE_URL')  ? BASE_URL  : '',
            'url_descadastro' => (defined('BASE_URL') ? BASE_URL : '') . '/email/descadastrar/PREVIEW',
            'data_atual' => date('d/m/Y'),
        ];
        $rendered = $this->svc->render($html, $vars);
        return $this->json(['ok' => true, 'html' => $rendered]);
    }

    public function excluir()
    {
        $this->verifyCsrf();
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) return $this->json(['ok' => false, 'erro' => 'ID inválido']);
        try {
            $this->model->delete($id);
            if (class_exists('LogService')) {
                LogService::audit('email_template_excluir', ['id' => $id]);
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
        try {
            $novoId = $this->model->duplicar($id, $this->currentUserId());
            if (class_exists('LogService')) {
                LogService::audit('email_template_duplicar', ['id' => $id, 'novo' => $novoId]);
            }
            return $this->json(['ok' => true, 'id' => $novoId]);
        } catch (Throwable $e) {
            return $this->json(['ok' => false, 'erro' => $e->getMessage()]);
        }
    }

    // =========================================================================
    // VERSIONAMENTO
    // =========================================================================

    public function versoes($id)
    {
        $id = (int)$id;
        $tpl = $this->model->find($id);
        if (!$tpl) { header('Location: ' . BASE_URL . '/admin/email-marketing/templates'); exit; }
        $versoes = $this->versions ? $this->versions->listar($id, 50) : [];

        $this->render('email-marketing/templates/versoes', [
            'item' => $tpl,
            'versoes' => $versoes,
            'titulo' => 'Histórico — ' . $tpl['nome'],
        ], 'admin');
    }

    public function verVersao($id, $versaoId)
    {
        $versaoId = (int)$versaoId;
        $tplId = (int)$id;
        if (!$this->versions) {
            header('Location: ' . BASE_URL . '/admin/email-marketing/templates');
            exit;
        }
        $versao = $this->versions->find($versaoId);
        if (!$versao || (int)$versao['template_id'] !== $tplId) {
            header('Location: ' . BASE_URL . '/admin/email-marketing/templates/' . $tplId . '/versoes');
            exit;
        }

        $tpl = $this->model->find($tplId);
        $this->render('email-marketing/templates/ver_versao', [
            'item' => $tpl,
            'versao' => $versao,
            'titulo' => 'Versão v' . $versao['versao'] . ' — ' . $tpl['nome'],
        ], 'admin');
    }

    public function restaurarVersao()
    {
        $this->verifyCsrf();
        $versaoId = (int)($_POST['versao_id'] ?? 0);
        if (!$this->versions) return $this->json(['ok' => false, 'erro' => 'Versionamento indisponível']);
        try {
            $this->versions->restaurar($versaoId, $this->currentUserId());
            if (class_exists('LogService')) {
                LogService::audit('email_template_restaurar', ['versao_id' => $versaoId]);
            }
            return $this->json(['ok' => true]);
        } catch (Throwable $e) {
            return $this->json(['ok' => false, 'erro' => $e->getMessage()]);
        }
    }
}
