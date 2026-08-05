<?php
/**
 * EtiquetaController — emissão, impressão, cancelamento e manifesto de etiquetas.
 *
 * Permissão em cascata; CSRF em todo POST; o custo real (valor) só sai na
 * listagem/detalhe se o admin tem 'logistica.custos'.
 */
class EtiquetaController extends Controller
{
    private EtiquetaService $etiquetas;

    public function __construct()
    {
        AuthHelper::requirePermission('logistica');
        $this->etiquetas = new EtiquetaService();
    }

    /* ============================ TELA ============================ */

    public function index(): void
    {
        $this->render('logistica/etiquetas', [
            'titulo'          => 'Etiquetas',
            'transportadoras' => $this->transportadorasComServicos(),
            'filtros'         => $this->filtros(),
        ], 'admin');
    }

    public function dados(): void
    {
        $f = $this->filtros();
        $pagina = max(1, (int)($_GET['pagina'] ?? 1));
        $res = $this->etiquetas->listar($f, $pagina);
        $res['itens'] = $this->limparCustos($res['itens']);
        $res['pode_ver_custos'] = $this->podeVerCustos();
        $res['ok'] = true;
        $this->json($res);
    }

    public function obter(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        $e = $id > 0 ? $this->etiquetas->obter($id) : null;
        if (!$e) { $this->json(['ok' => false, 'erro' => 'Etiqueta não encontrada.']); return; }
        if (!$this->podeVerCustos()) unset($e['valor']);
        $this->json(['ok' => true, 'etiqueta' => $e, 'eventos' => $this->etiquetas->eventos($id), 'pode_ver_custos' => $this->podeVerCustos()]);
    }

    /* ============================ AÇÕES ============================ */

    public function criar(): void
    {
        $this->verifyCsrf();
        $dados = [
            'pedido_id'       => $_POST['pedido_id'] ?? null,
            'cotacao_id'      => $_POST['cotacao_id'] ?? null,
            'transportadora_id' => (int)($_POST['transportadora_id'] ?? 0),
            'servico_codigo'  => $_POST['servico_codigo'] ?? '',
            'servico_nome'    => $_POST['servico_nome'] ?? null,
            'canal'           => $_POST['canal'] ?? 'site',
            'formato'         => $_POST['formato'] ?? 'pdf',
            'valor_declarado' => $_POST['valor_declarado'] ?? 0,
            'nota_fiscal_chave' => $_POST['nota_fiscal_chave'] ?? null,
            'remetente'       => is_array($_POST['remetente'] ?? null) ? $_POST['remetente'] : [],
            'destinatario'    => is_array($_POST['destinatario'] ?? null) ? $_POST['destinatario'] : [],
            'volumes'         => $this->volumesDoPost(),
            'produtos'        => is_array($_POST['produtos'] ?? null) ? array_values($_POST['produtos']) : [],
        ];
        $this->json($this->etiquetas->criar($dados, $this->usuarioId()));
    }

    public function comprar(): void
    {
        $this->verifyCsrf();
        $this->json($this->etiquetas->comprar((int)($_POST['id'] ?? 0), $this->usuarioId()));
    }

    public function comprarLote(): void
    {
        $this->verifyCsrf();
        $ids = is_array($_POST['ids'] ?? null) ? $_POST['ids'] : [];
        $this->json($this->etiquetas->comprarLote($ids, $this->usuarioId()));
    }

    public function imprimir(): void
    {
        $this->verifyCsrf();
        $this->json($this->etiquetas->imprimir((int)($_POST['id'] ?? 0), $this->usuarioId()));
    }

    public function cancelar(): void
    {
        $this->verifyCsrf();
        $this->json($this->etiquetas->cancelar((int)($_POST['id'] ?? 0), $this->usuarioId()));
    }

    public function manifesto(): void
    {
        $this->verifyCsrf();
        $ids = is_array($_POST['ids'] ?? null) ? $_POST['ids'] : [];
        $this->json($this->etiquetas->gerarManifesto($ids, $this->usuarioId()));
    }

    public function remover(): void
    {
        $this->verifyCsrf();
        $this->json($this->etiquetas->remover((int)($_POST['id'] ?? 0), $this->usuarioId()));
    }

    /* ============================ helpers ============================ */

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
        if (!empty($_GET['status']))            $out['status'] = (string)$_GET['status'];
        if (!empty($_GET['transportadora_id'])) $out['transportadora_id'] = (int)$_GET['transportadora_id'];
        if (!empty($_GET['busca']))             $out['busca'] = trim((string)$_GET['busca']);
        return $out;
    }

    private function limparCustos(array $itens): array
    {
        if ($this->podeVerCustos()) return $itens;
        foreach ($itens as &$i) unset($i['valor']);
        return $itens;
    }

    private function transportadorasComServicos(): array
    {
        try {
            $pdo = Database::getInstance()->getConnection();
            $ts = $pdo->query("SELECT id, nome, slug FROM log_transportadoras WHERE status = 'ativo' ORDER BY prioridade ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (!$ts) return [];
            $in = implode(',', array_map(static fn($t) => (int)$t['id'], $ts));
            $sv = $pdo->query("SELECT transportadora_id, codigo, nome FROM log_transportadora_servicos WHERE transportadora_id IN ($in) AND habilitado = 1 ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $porT = [];
            foreach ($sv as $s) $porT[$s['transportadora_id']][] = ['codigo' => $s['codigo'], 'nome' => $s['nome']];
            foreach ($ts as &$t) $t['servicos'] = $porT[$t['id']] ?? [];
            return $ts;
        } catch (\Throwable $e) {
            LogService::error('Falha ao carregar transportadoras para etiquetas', ['erro' => $e->getMessage()]);
            return [];
        }
    }

    private function podeVerCustos(): bool
    {
        foreach (['pode', 'temPermissao', 'can'] as $m) {
            if (method_exists('AuthHelper', $m)) {
                try { return (bool)AuthHelper::$m('logistica.custos'); } catch (\Throwable $e) { /* próximo */ }
            }
        }
        return true;
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