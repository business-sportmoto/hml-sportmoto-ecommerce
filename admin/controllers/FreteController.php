<?php
/**
 * FreteController — Calculadora, Motor de Regras e Embalagens.
 *
 * Regras (CRUD), Simulador (roda a Calculadora) e Embalagens (gestão enxuta).
 * Permissão em cascata no construtor; CSRF em todo POST; no simulador, o
 * custo real (valor_original) é ocultado se o admin não tem 'logistica.custos'.
 */
class FreteController extends Controller
{
    private RegrasAdminService $regras;
    private CalculadoraService $calc;
    private EmbalagemService $embalagens;

    public function __construct()
    {
        AuthHelper::requirePermission('logistica');
        $this->regras     = new RegrasAdminService();
        $this->calc       = new CalculadoraService();
        $this->embalagens = new EmbalagemService();
    }

    /* ============================ REGRAS ============================ */

    public function regras(): void
    {
        $filtros = $this->filtrosRegras();
        $this->render('logistica/regras', [
            'titulo'  => 'Regras de frete',
            'regras'  => $this->regras->listar($filtros),
            'campos'  => RegrasAdminService::CAMPOS_CONDICAO,
            'opers'   => RegrasAdminService::OPERADORES,
            'filtros' => $filtros,
        ], 'admin');
    }

    public function regrasDados(): void
    {
        $this->json(['ok' => true, 'regras' => $this->regras->listar($this->filtrosRegras())]);
    }

    public function regraObter(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        $r = $id > 0 ? $this->regras->obter($id) : null;
        $this->json($r ? ['ok' => true, 'regra' => $r] : ['ok' => false, 'erro' => 'Regra não encontrada.']);
    }

    public function regraSalvar(): void
    {
        $this->verifyCsrf();
        $dados = [
            'id'          => $_POST['id'] ?? null,
            'nome'        => $_POST['nome'] ?? '',
            'descricao'   => $_POST['descricao'] ?? '',
            'prioridade'  => $_POST['prioridade'] ?? 100,
            'ativa'       => $_POST['ativa'] ?? 0,
            'acumulativa' => $_POST['acumulativa'] ?? 0,
            'inicio_em'   => $_POST['inicio_em'] ?? '',
            'fim_em'      => $_POST['fim_em'] ?? '',
            'acoes'       => is_array($_POST['acoes'] ?? null) ? $_POST['acoes'] : [],
            'condicoes'   => is_array($_POST['condicoes'] ?? null) ? array_values($_POST['condicoes']) : [],
        ];
        $this->json($this->regras->salvar($dados, $this->usuarioId()));
    }

    public function regraStatus(): void
    {
        $this->verifyCsrf();
        $this->json($this->regras->alternar((int)($_POST['id'] ?? 0), !empty($_POST['ativa']), $this->usuarioId()));
    }

    public function regraReordenar(): void
    {
        $this->verifyCsrf();
        $ordem = is_array($_POST['ordem'] ?? null) ? $_POST['ordem'] : [];
        $this->json($this->regras->reordenar($ordem, $this->usuarioId()));
    }

    public function regraRemover(): void
    {
        $this->verifyCsrf();
        $this->json($this->regras->remover((int)($_POST['id'] ?? 0), $this->usuarioId()));
    }

    /* =========================== SIMULADOR =========================== */

    public function simulador(): void
    {
        $this->render('logistica/simulador', [
            'titulo'     => 'Simulador de frete',
            'embalagens' => $this->embalagens->listar(),
        ], 'admin');
    }

    public function simular(): void
    {
        $this->verifyCsrf();

        $itens = [];
        foreach ((is_array($_POST['itens'] ?? null) ? $_POST['itens'] : []) as $it) {
            $itens[] = [
                'peso_g'         => (int)($it['peso_g'] ?? 0),
                'altura_cm'      => (float)($it['altura_cm'] ?? 0),
                'largura_cm'     => (float)($it['largura_cm'] ?? 0),
                'comprimento_cm' => (float)($it['comprimento_cm'] ?? 0),
                'valor'          => (float)($it['valor'] ?? 0),
                'quantidade'     => max(1, (int)($it['quantidade'] ?? 1)),
                'categoria_id'   => $it['categoria_id'] ?? null,
                'marca_id'       => $it['marca_id'] ?? null,
                'produto_id'     => $it['produto_id'] ?? null,
            ];
        }

        $req = [
            'cep_destino'      => $_POST['cep_destino'] ?? '',
            'cep_origem'       => $_POST['cep_origem'] ?? '',
            'uf'               => $_POST['uf'] ?? '',
            'cidade'           => $_POST['cidade'] ?? '',
            'canal'            => $_POST['canal'] ?? 'site',
            'tipo_cliente'     => $_POST['tipo_cliente'] ?? '',
            'valor_mercadoria' => $_POST['valor_mercadoria'] ?? 0,
            'seguro'           => !empty($_POST['seguro']),
            'origem'           => 'manual',
            'itens'            => $itens,
            'usuario_id'       => $this->usuarioId(),
            'persistir'        => true,
        ];

        $res = $this->calc->cotar($req);

        // Oculta custo real se sem permissão.
        if (!$this->podeVerCustos() && !empty($res['opcoes'])) {
            foreach ($res['opcoes'] as &$o) { unset($o['valor_original'], $o['valor_ajuste']); }
            unset($o);
        }
        $res['pode_ver_custos'] = $this->podeVerCustos();
        $this->json($res);
    }

    /* ========================== EMBALAGENS ========================== */

    public function embalagensDados(): void
    {
        $this->json(['ok' => true, 'embalagens' => $this->embalagens->listar()]);
    }

    public function embalagemSalvar(): void
    {
        $this->verifyCsrf();
        $this->json($this->embalagens->salvar($_POST, $this->usuarioId()));
    }

    public function embalagemStatus(): void
    {
        $this->verifyCsrf();
        $this->json($this->embalagens->alternar((int)($_POST['id'] ?? 0), !empty($_POST['ativo']), $this->usuarioId()));
    }

    public function embalagemRemover(): void
    {
        $this->verifyCsrf();
        $this->json($this->embalagens->remover((int)($_POST['id'] ?? 0), $this->usuarioId()));
    }

    /* ============================ helpers ============================ */

    private function filtrosRegras(): array
    {
        $out = [];
        if (isset($_GET['ativa']) && $_GET['ativa'] !== '') $out['ativa'] = (int)$_GET['ativa'];
        if (!empty($_GET['busca'])) $out['busca'] = trim((string)$_GET['busca']);
        return $out;
    }

    private function podeVerCustos(): bool
    {
        foreach (['pode', 'temPermissao', 'can'] as $m) {
            if (method_exists('AuthHelper', $m)) {
                try { return (bool)AuthHelper::$m('logistica.custos'); } catch (\Throwable $e) { /* tenta próximo */ }
            }
        }
        return true; // fallback: mostra (comportamento da Fase 1)
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
