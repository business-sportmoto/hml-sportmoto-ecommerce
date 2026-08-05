<?php
/**
 * ReversaController (admin) — logística reversa.
 * Permissão em cascata; CSRF nos POSTs.
 */
class ReversaController extends Controller
{
    private ReversaService $reversas;

    public function __construct()
    {
        AuthHelper::requirePermission('logistica');
        $this->reversas = new ReversaService();
    }

    /* ---------------- tela ---------------- */

    public function index(): void
    {
        $this->render('logistica/reversas', [
            'titulo'          => 'Reversas',
            'transportadoras' => $this->transportadorasComServicos(),
            'filtros'         => $this->filtros(),
        ], 'admin');
    }

    public function dados(): void
    {
        $res = $this->reversas->listar($this->filtros(), max(1, (int)($_GET['pagina'] ?? 1)));
        $res['ok'] = true;
        $this->json($res);
    }

    public function obter(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        $r = $id > 0 ? $this->reversas->obter($id) : null;
        if (!$r) { $this->json(['ok' => false, 'erro' => 'Reversa não encontrada.']); return; }
        unset($r['etiqueta_external_id']); // interno
        $this->json(['ok' => true, 'reversa' => $r]);
    }

    /** Busca de cliente por CPF (autopreenchimento do formulário). */
    public function buscarCliente(): void
    {
        $cpf = trim((string)($_GET['cpf'] ?? ''));
        $clientes = $cpf !== '' ? (new ClienteBuscaService())->buscarPorCpf($cpf) : [];
        $this->json(['ok' => true, 'clientes' => $clientes]);
    }

    /* ---------------- ações ---------------- */

    public function solicitar(): void
    {
        $this->verifyCsrf();
        $dados = [
            'pedido_id'       => $_POST['pedido_id'] ?? null,
            'cliente_id'      => $_POST['cliente_id'] ?? null,
            'etiqueta_id'     => $_POST['etiqueta_id'] ?? null,
            'motivo'          => $_POST['motivo'] ?? 'devolucao',
            'tipo'            => $_POST['tipo'] ?? 'postagem',
            'processo'        => $_POST['processo'] ?? '',
            'itens'           => is_array($_POST['itens'] ?? null) ? $_POST['itens'] : [],
            'endereco_coleta' => is_array($_POST['endereco_coleta'] ?? null) ? $_POST['endereco_coleta'] : [],
        ];
        $this->json($this->reversas->solicitar($dados, $this->usuarioId()));
    }

    public function autorizar(): void
    {
        $this->verifyCsrf();
        $this->json($this->reversas->autorizar((int)($_POST['id'] ?? 0), $this->usuarioId()));
    }

    public function gerar(): void
    {
        $this->verifyCsrf();
        $extras = [
            'transportadora_id' => (int)($_POST['transportadora_id'] ?? 0),
            'servico_codigo'    => $_POST['servico_codigo'] ?? '',
            'servico_nome'      => $_POST['servico_nome'] ?? 'Reversa',
            'valor_declarado'   => $_POST['valor_declarado'] ?? 0,
            'formato'           => $_POST['formato'] ?? 'pdf',
            'remetente'         => is_array($_POST['remetente'] ?? null) ? $_POST['remetente'] : [],
            'volumes'           => $this->volumesDoPost(),
        ];
        $this->json($this->reversas->gerarEtiqueta((int)($_POST['id'] ?? 0), $extras, $this->usuarioId()));
    }

    public function cancelar(): void
    {
        $this->verifyCsrf();
        $this->json($this->reversas->cancelar((int)($_POST['id'] ?? 0), $this->usuarioId()));
    }

    public function receber(): void
    {
        $this->verifyCsrf();
        $this->json($this->reversas->marcarRecebida((int)($_POST['id'] ?? 0), $this->usuarioId()));
    }

    public function processo(): void
    {
        $this->verifyCsrf();
        $this->json($this->reversas->definirProcesso((int)($_POST['id'] ?? 0), (string)($_POST['processo'] ?? 'nenhum'), $this->usuarioId()));
    }

    public function sincronizar(): void
    {
        $this->verifyCsrf();
        $this->json($this->reversas->sincronizarComRastreio((int)($_POST['id'] ?? 0), $this->usuarioId()));
    }

    public function remover(): void
    {
        $this->verifyCsrf();
        $this->json($this->reversas->remover((int)($_POST['id'] ?? 0), $this->usuarioId()));
    }

    /* ---------------- helpers ---------------- */

    private function volumesDoPost(): array
    {
        $out = [];
        foreach ((is_array($_POST['volumes'] ?? null) ? $_POST['volumes'] : []) as $v) {
            $out[] = [
                'altura_cm'      => (float)($v['altura_cm'] ?? 0),
                'largura_cm'     => (float)($v['largura_cm'] ?? 0),
                'comprimento_cm' => (float)($v['comprimento_cm'] ?? 0),
                'peso_g'         => (int)($v['peso_g'] ?? 0),
            ];
        }
        return $out;
    }

    private function filtros(): array
    {
        $out = [];
        if (!empty($_GET['status']))   $out['status'] = (string)$_GET['status'];
        if (!empty($_GET['motivo']))   $out['motivo'] = (string)$_GET['motivo'];
        if (!empty($_GET['processo'])) $out['processo'] = (string)$_GET['processo'];
        if (!empty($_GET['busca']))    $out['busca'] = trim((string)$_GET['busca']);
        return $out;
    }

    private function transportadorasComServicos(): array
    {
        try {
            $pdo = Database::getInstance()->getConnection();
            $ts = $pdo->query("SELECT id, nome FROM log_transportadoras WHERE status = 'ativo' ORDER BY prioridade ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (!$ts) return [];
            $in = implode(',', array_map(static fn($t) => (int)$t['id'], $ts));
            $sv = $pdo->query("SELECT transportadora_id, codigo, nome FROM log_transportadora_servicos WHERE transportadora_id IN ($in) AND habilitado = 1 ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $porT = [];
            foreach ($sv as $s) $porT[$s['transportadora_id']][] = ['codigo' => $s['codigo'], 'nome' => $s['nome']];
            foreach ($ts as &$t) $t['servicos'] = $porT[$t['id']] ?? [];
            return $ts;
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