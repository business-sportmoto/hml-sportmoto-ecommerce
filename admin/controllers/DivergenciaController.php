<?php
/**
 * DivergenciaController (admin) — divergências de frete + alertas de produto.
 * Permissão em cascata; CSRF nos POSTs.
 */
class DivergenciaController extends Controller
{
    private DivergenciaService $div;

    public function __construct()
    {
        AuthHelper::requirePermission('logistica');
        $this->div = new DivergenciaService();
    }

    /* ---------------- tela ---------------- */

    public function index(): void
    {
        $this->render('logistica/divergencias', [
            'titulo'          => 'Divergências',
            'transportadoras' => $this->transportadoras(),
            'filtros'         => $this->filtros(),
        ], 'admin');
    }

    /* ---------------- divergências ---------------- */

    public function dados(): void
    {
        $res = $this->div->listar($this->filtros(), max(1, (int)($_GET['pagina'] ?? 1)));
        $res['ok'] = true;
        $this->json($res);
    }

    public function obter(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        $d = $id > 0 ? $this->div->obter($id) : null;
        if (!$d) { $this->json(['ok' => false, 'erro' => 'Divergência não encontrada.']); return; }
        $this->json(['ok' => true, 'divergencia' => $d]);
    }

    public function contextoEtiqueta(): void
    {
        $etq = (int)($_GET['etiqueta_id'] ?? 0);
        $this->json(['ok' => true, 'contexto' => $etq > 0 ? $this->div->contextoDaEtiqueta($etq) : []]);
    }

    public function registrar(): void
    {
        $this->verifyCsrf();
        $dados = [
            'pedido_id'            => $_POST['pedido_id'] ?? null,
            'etiqueta_id'          => $_POST['etiqueta_id'] ?? null,
            'transportadora_id'    => $_POST['transportadora_id'] ?? null,
            'servico_codigo'       => $_POST['servico_codigo'] ?? null,
            'valor_estimado'       => $_POST['valor_estimado'] ?? 0,
            'valor_cliente'        => $_POST['valor_cliente'] ?? 0,
            'subsidio_loja'        => $_POST['subsidio_loja'] ?? 0,
            'valor_transportadora' => $_POST['valor_transportadora'] ?? 0,
            'peso_informado_g'     => $_POST['peso_informado_g'] ?? null,
            'peso_aferido_g'       => $_POST['peso_aferido_g'] ?? null,
            'dimensoes_informadas' => is_array($_POST['dimensoes_informadas'] ?? null) ? $_POST['dimensoes_informadas'] : null,
            'dimensoes_aferidas'   => is_array($_POST['dimensoes_aferidas'] ?? null) ? $_POST['dimensoes_aferidas'] : null,
            'motivo'               => $_POST['motivo'] ?? '',
            'nivel_impacto'        => $_POST['nivel_impacto'] ?? '',
            'observacoes'          => $_POST['observacoes'] ?? null,
            'produtos'             => is_array($_POST['produtos'] ?? null) ? $_POST['produtos'] : [],
        ];
        $this->json($this->div->registrar($dados, $this->usuarioId()));
    }

    public function analisar(): void { $this->verifyCsrf(); $this->json($this->div->analisar((int)($_POST['id'] ?? 0), $this->usuarioId())); }
    public function resolver(): void { $this->verifyCsrf(); $this->json($this->div->resolver((int)($_POST['id'] ?? 0), $this->usuarioId())); }
    public function ignorar(): void  { $this->verifyCsrf(); $this->json($this->div->ignorar((int)($_POST['id'] ?? 0), $this->usuarioId())); }
    public function reabrir(): void   { $this->verifyCsrf(); $this->json($this->div->reabrir((int)($_POST['id'] ?? 0), $this->usuarioId())); }

    public function atualizar(): void
    {
        $this->verifyCsrf();
        $campos = [];
        foreach (['nivel_impacto', 'observacoes', 'responsavel_id', 'motivo'] as $k) {
            if (isset($_POST[$k])) $campos[$k] = $_POST[$k];
        }
        $this->json($this->div->atualizar((int)($_POST['id'] ?? 0), $campos, $this->usuarioId()));
    }

    /* ---------------- alertas de produto ---------------- */

    public function alertas(): void
    {
        $f = [
            'status' => $_GET['status'] ?? 'aberto',
            'tipo'   => $_GET['tipo'] ?? '',
            'busca'  => trim((string)($_GET['busca'] ?? '')),
        ];
        $res = $this->div->listarAlertas($f, max(1, (int)($_GET['pagina'] ?? 1)));
        $res['ok'] = true;
        $this->json($res);
    }

    public function alertaObter(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        $a = $id > 0 ? $this->div->obterAlerta($id) : null;
        if (!$a) { $this->json(['ok' => false, 'erro' => 'Alerta não encontrado.']); return; }
        $this->json(['ok' => true, 'alerta' => $a]);
    }

    public function resolverAlerta(): void { $this->verifyCsrf(); $this->json($this->div->resolverAlerta((int)($_POST['id'] ?? 0), $this->usuarioId())); }
    public function reabrirAlerta(): void  { $this->verifyCsrf(); $this->json($this->div->reabrirAlerta((int)($_POST['id'] ?? 0), $this->usuarioId())); }

    /* ---------------- helpers ---------------- */

    private function filtros(): array
    {
        $out = [];
        if (!empty($_GET['status'])) $out['status'] = (string)$_GET['status'];
        if (!empty($_GET['nivel']))  $out['nivel'] = (string)$_GET['nivel'];
        if (!empty($_GET['busca']))  $out['busca'] = trim((string)$_GET['busca']);
        return $out;
    }

    private function transportadoras(): array
    {
        try {
            return Database::getInstance()->getConnection()
                ->query("SELECT id, nome FROM log_transportadoras WHERE status = 'ativo' ORDER BY prioridade ASC, id ASC")
                ->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

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
