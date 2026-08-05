<?php
/**
 * TransportadoraController — cadastro e integrações de transportadoras.
 *
 * Página (index) + endpoints AJAX: listar, obter para edição, salvar,
 * alternar status, reordenar prioridade, testar conexão e ver logs.
 *
 * Padrão do projeto: extends Controller, permissão em cascata no
 * construtor, CSRF em todo POST, respostas JSON via $this->json().
 */
class TransportadoraController extends Controller
{
    private TransportadoraAdminService $service;

    public function __construct()
    {
        AuthHelper::requirePermission('logistica');
        $this->service = new TransportadoraAdminService();
    }

    /* ---------------------------------------------------------------
       Página
       --------------------------------------------------------------- */

    /** GET /admin/logistica/transportadoras */
    public function index(): void
    {
        $filtros = $this->filtros();
        $this->render('logistica/transportadoras', [
            'titulo'         => 'Transportadoras',
            'transportadoras'=> $this->service->listar($filtros),
            'catalogo'       => TransportadoraManager::catalogo(),
            'filtros'        => $filtros,
        ], 'admin');
    }

    /* ---------------------------------------------------------------
       Leitura (AJAX)
       --------------------------------------------------------------- */

    /** GET /admin/logistica/transportadoras/dados */
    public function dados(): void
    {
        $this->json([
            'ok'              => true,
            'transportadoras' => $this->service->listar($this->filtros()),
        ]);
    }

    /** GET /admin/logistica/transportadoras/obter?id= */
    public function obter(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        $t = $id > 0 ? $this->service->obter($id) : null;
        if (!$t) {
            $this->json(['ok' => false, 'erro' => 'Transportadora não encontrada.']);
            return;
        }
        $this->json(['ok' => true, 'transportadora' => $t]);
    }

    /** GET /admin/logistica/transportadoras/logs?id=&pagina= */
    public function logs(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        $pagina = max(1, (int)($_GET['pagina'] ?? 1));
        if ($id <= 0) {
            $this->json(['ok' => false, 'erro' => 'ID inválido.']);
            return;
        }
        $this->json(['ok' => true] + $this->service->logs($id, $pagina));
    }

    /* ---------------------------------------------------------------
       Escrita (AJAX, POST + CSRF)
       --------------------------------------------------------------- */

    /** POST /admin/logistica/transportadoras/salvar */
    public function salvar(): void
    {
        $this->verifyCsrf();

        $dados = [
            'id'                 => $_POST['id'] ?? null,
            'nome'               => $_POST['nome'] ?? '',
            'slug'               => $_POST['slug'] ?? '',
            'adapter'            => $_POST['adapter'] ?? '',
            'logo_url'           => $_POST['logo_url'] ?? '',
            'status'             => $_POST['status'] ?? 'inativo',
            'ambiente'           => $_POST['ambiente'] ?? 'sandbox',
            'cep_origem'         => $_POST['cep_origem'] ?? '',
            'contrato'           => $_POST['contrato'] ?? '',
            'config'             => is_array($_POST['config'] ?? null) ? $_POST['config'] : [],
            'prazo_preparo_dias' => $_POST['prazo_preparo_dias'] ?? 0,
            'margem_tipo'        => $_POST['margem_tipo'] ?? 'nenhum',
            'margem_percentual'  => $_POST['margem_percentual'] ?? 0,
            'margem_valor'       => $_POST['margem_valor'] ?? 0,
            'seguro_padrao'      => $_POST['seguro_padrao'] ?? 0,
            'usa_valor_declarado'=> $_POST['usa_valor_declarado'] ?? 0,
            'suporta_coleta'     => $_POST['suporta_coleta'] ?? 0,
            'suporta_postagem'   => $_POST['suporta_postagem'] ?? 1,
            'prioridade'         => $_POST['prioridade'] ?? 100,
            'servicos'           => is_array($_POST['servicos'] ?? null) ? array_values($_POST['servicos']) : [],
        ];

        $res = $this->service->salvar($dados, $this->usuarioId());
        $this->json($res);
    }

    /** POST /admin/logistica/transportadoras/status */
    public function status(): void
    {
        $this->verifyCsrf();
        $id = (int)($_POST['id'] ?? 0);
        $status = (string)($_POST['status'] ?? '');
        $this->json($this->service->alternarStatus($id, $status, $this->usuarioId()));
    }

    /** POST /admin/logistica/transportadoras/reordenar */
    public function reordenar(): void
    {
        $this->verifyCsrf();
        $ordem = is_array($_POST['ordem'] ?? null) ? $_POST['ordem'] : [];
        $this->json($this->service->reordenar($ordem, $this->usuarioId()));
    }

    /** POST /admin/logistica/transportadoras/testar */
    public function testar(): void
    {
        $this->verifyCsrf();
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $this->json(['ok' => false, 'mensagem' => 'ID inválido.']);
            return;
        }
        $this->json($this->service->testarConexao($id, $this->usuarioId()));
    }

    /* ---------------------------------------------------------------
       Helpers
       --------------------------------------------------------------- */

    private function filtros(): array
    {
        $g = static fn(string $k): ?string =>
            isset($_GET[$k]) && $_GET[$k] !== '' ? trim((string)$_GET[$k]) : null;
        return array_filter([
            'status' => $g('status'),
            'busca'  => $g('busca'),
        ], static fn($v) => $v !== null);
    }

    /** ID do admin logado, para auditoria (tolerante a diferentes AuthHelper). */
    private function usuarioId(): ?int
    {
        foreach (['usuarioId', 'idUsuario', 'id'] as $m) {
            if (method_exists('AuthHelper', $m)) {
                $v = AuthHelper::$m();
                if (is_numeric($v)) return (int)$v;
            }
        }
        if (!empty($_SESSION['usuario_id'])) return (int)$_SESSION['usuario_id'];
        if (!empty($_SESSION['admin']['id'])) return (int)$_SESSION['admin']['id'];
        return null;
    }
}
