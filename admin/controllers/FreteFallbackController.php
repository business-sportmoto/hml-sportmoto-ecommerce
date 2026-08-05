<?php
/**
 * FreteFallbackController (admin) — tabela de fallback de frete + saúde.
 * Permissão em cascata; CSRF nos POSTs.
 */
class FreteFallbackController extends Controller
{
    private FreteFallbackService $fallback;
    private FreteSaudeService $saude;

    public function __construct()
    {
        AuthHelper::requirePermission('logistica');
        $this->fallback = new FreteFallbackService();
        $this->saude = new FreteSaudeService();
    }

    public function index(): void
    {
        $this->render('logistica/frete-fallback', ['titulo' => 'Frete — fallback'], 'admin');
    }

    public function dados(): void
    {
        $this->json([
            'ok'     => true,
            'itens'  => $this->fallback->listar(),
            'saude'  => $this->saude->estado('cotacao'),
        ]);
    }

    public function salvar(): void
    {
        $this->verifyCsrf();
        $d = [
            'id'           => $_POST['id'] ?? 0,
            'uf'           => $_POST['uf'] ?? '',
            'regiao'       => $_POST['regiao'] ?? '',
            'peso_min_g'   => $_POST['peso_min_g'] ?? 0,
            'peso_max_g'   => $_POST['peso_max_g'] ?? 30000,
            'servico'      => $_POST['servico'] ?? 'PAC',
            'servico_nome' => $_POST['servico_nome'] ?? 'Estimativa',
            'prazo_dias'   => $_POST['prazo_dias'] ?? 7,
            'valor_base'   => $_POST['valor_base'] ?? 0,
            'valor_por_kg' => $_POST['valor_por_kg'] ?? 0,
            'ativo'        => isset($_POST['ativo']) ? (int)$_POST['ativo'] : 1,
            'ordem'        => $_POST['ordem'] ?? 0,
        ];
        $this->json($this->fallback->salvar($d, $this->usuarioId()));
    }

    public function remover(): void
    {
        $this->verifyCsrf();
        $this->json($this->fallback->remover((int)($_POST['id'] ?? 0)));
    }

    public function alternar(): void
    {
        $this->verifyCsrf();
        $this->json($this->fallback->alternar((int)($_POST['id'] ?? 0)));
    }

    private function usuarioId(): ?int
    {
        foreach (['usuarioId', 'idUsuario', 'id'] as $m) {
            if (method_exists('AuthHelper', $m)) {
                $v = AuthHelper::$m();
                if (is_numeric($v)) return (int)$v;
            }
        }
        return !empty($_SESSION['usuario_id']) ? (int)$_SESSION['usuario_id'] : null;
    }
}
