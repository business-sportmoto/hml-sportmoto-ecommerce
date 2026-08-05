<?php
/**
 * ApiKeyController (admin) — gestão de chaves de API da logística.
 * Permissão em cascata; CSRF nos POSTs. A chave em texto puro só é exibida
 * na criação (o backend guarda apenas o hash).
 */
class ApiKeyController extends Controller
{
    private ApiKeyService $keys;

    public function __construct()
    {
        AuthHelper::requirePermission('logistica');
        $this->keys = new ApiKeyService();
    }

    public function index(): void
    {
        $this->render('logistica/api-keys', [
            'titulo'  => 'API',
            'escopos' => ['cotar', 'etiquetas', 'rastreio', 'reversa', 'divergencias'],
        ], 'admin');
    }

    public function dados(): void
    {
        $this->json(['ok' => true, 'itens' => $this->keys->listar()]);
    }

    public function criar(): void
    {
        $this->verifyCsrf();
        $escopos = is_array($_POST['escopos'] ?? null) ? $_POST['escopos'] : [];
        $webhook = [];
        if (!empty($_POST['webhook_url'])) $webhook = ['url' => trim((string)$_POST['webhook_url']), 'secret' => $_POST['webhook_secret'] ?? null];
        $r = $this->keys->criar((string)($_POST['nome'] ?? ''), $escopos, (int)($_POST['rate_limit'] ?? 120), $webhook);
        $this->json($r);
    }

    public function atualizar(): void
    {
        $this->verifyCsrf();
        $campos = [];
        foreach (['nome', 'rate_limit', 'ativa'] as $k) {
            if (isset($_POST[$k])) $campos[$k] = $_POST[$k];
        }
        if (isset($_POST['escopos']) && is_array($_POST['escopos'])) $campos['escopos'] = $_POST['escopos'];
        if (!empty($_POST['webhook_url'])) $campos['webhook'] = ['url' => trim((string)$_POST['webhook_url']), 'secret' => $_POST['webhook_secret'] ?? null];
        $this->json($this->keys->atualizar((int)($_POST['id'] ?? 0), $campos));
    }

    public function revogar(): void
    {
        $this->verifyCsrf();
        $this->json($this->keys->revogar((int)($_POST['id'] ?? 0)));
    }
}
