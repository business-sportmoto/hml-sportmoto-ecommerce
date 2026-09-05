<?php
/**
 * admin/controllers/EmailProviderAdminController.php
 */
class EmailProviderAdminController extends Controller
{
    /** @var EmailProvider */
    private $model;
    /** @var EmailProviderService */
    private $svc;

    public function __construct()
    {
        // parent::__construct();
        $this->requirePermission();
        $this->model = new EmailProvider();
        $this->svc = new EmailProviderService();
    }

    private function requirePermission(): void
    {
        // A cascata mora no AuthHelper agora — ver o porquê lá.
        AuthHelper::requirePermissaoOuNivel('email_marketing', 'super', 'gerente');
    }

    public function index()
    {
        $itens = $this->model->all(false);
        // Não expor o blob de credenciais bruto
        foreach ($itens as &$i) { $i['credenciais'] = null; }
        unset($i);

        $this->render('email-marketing/provedores/index', [
            'itens' => $itens,
            'titulo' => 'Provedores de Email',
        ], 'admin');
    }

    public function salvar()
    {
        $this->verifyCsrf();

        $id = (int)($_POST['id'] ?? 0);
        $tipo = $_POST['tipo'] ?? '';
        if (!in_array($tipo, ['ses','mailgun','sendgrid','brevo','smtp'], true)) {
            return $this->json(['ok' => false, 'erro' => 'Tipo inválido']);
        }

        // credenciais vêm como array no $_POST['credenciais']
        $credIn = $_POST['credenciais'] ?? [];
        if (!is_array($credIn)) $credIn = [];
        $cred = $this->svc->encryptCreds($credIn);

        $dados = [
            'id'    => $id,
            'nome'  => trim((string)($_POST['nome'] ?? '')),
            'tipo'  => $tipo,
            'remetente_email' => trim((string)($_POST['remetente_email'] ?? '')),
            'remetente_nome'  => trim((string)($_POST['remetente_nome']  ?? '')),
            'reply_to'        => trim((string)($_POST['reply_to'] ?? '')) ?: null,
            'dominio'         => trim((string)($_POST['dominio'] ?? '')) ?: null,
            'regiao'          => trim((string)($_POST['regiao']  ?? '')) ?: null,
            'credenciais'     => $cred,
            'limite_por_minuto' => (int)($_POST['limite_por_minuto'] ?? 60),
            'limite_por_dia'    => (int)($_POST['limite_por_dia'] ?? 50000),
            'webhook_secret'    => trim((string)($_POST['webhook_secret'] ?? '')) ?: null,
            'ativo'  => !empty($_POST['ativo']) ? 1 : 0,
            'padrao' => !empty($_POST['padrao']) ? 1 : 0,
        ];

        try {
            $newId = $this->model->save($dados);
            if (class_exists('LogService')) {
                LogService::audit('email_provider_salvar', ['id' => $newId, 'tipo' => $tipo]);
            }
            return $this->json(['ok' => true, 'id' => $newId]);
        } catch (Throwable $e) {
            return $this->json(['ok' => false, 'erro' => $e->getMessage()]);
        }
    }

    public function testar()
    {
        $this->verifyCsrf();
        $id = (int)($_POST['id'] ?? 0);
        $emailDestino = strtolower(trim((string)($_POST['email'] ?? '')));
        if (!filter_var($emailDestino, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['ok' => false, 'erro' => 'Email destino inválido']);
        }
        try {
            $provider = $this->svc->build($id);
            $cfg = $this->svc->getConfig($id);
            $res = $provider->send([
                'from_email' => $cfg['remetente_email'],
                'from_name'  => $cfg['remetente_nome'] ?? '',
                'reply_to'   => $cfg['reply_to'] ?? null,
                'to_email'   => $emailDestino,
                'to_name'    => 'Teste',
                'subject'    => '[Teste] Provedor ' . $cfg['nome'],
                'html'       => '<p>Este é um email de teste enviado pelo painel administrativo do SportMoto às '
                              . date('d/m/Y H:i') . '.</p>',
                'text'       => 'Teste de provedor ' . $cfg['nome'] . ' em ' . date('d/m/Y H:i'),
                'headers'    => ['X-Email-Test' => '1'],
            ]);
            if ($res->success) {
                return $this->json(['ok' => true, 'message_id' => $res->providerMessageId]);
            }
            return $this->json(['ok' => false, 'erro' => $res->error]);
        } catch (Throwable $e) {
            return $this->json(['ok' => false, 'erro' => $e->getMessage()]);
        }
    }
}
