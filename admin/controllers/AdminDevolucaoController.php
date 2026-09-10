<?php
declare(strict_types=1);
 
// ════════════════════════════════════════════════════════
// app/controllers/AdminDevolucaoController.php
// ════════════════════════════════════════════════════════
 
class AdminDevolucaoController extends Controller {
 
    private DevolucaoService $service;
    private PDO              $db;
 
    // ── Quem entra no modulo ──────────────────────────────────
    //
    // Devolucao carrega dado pessoal do cliente e valor a devolver, entao
    // segue o mesmo recorte do dominio de pedidos (CLAUDE.md 4.6): `editor`
    // fica de fora, porque catalogo nao precisa disto.
    //
    // As acoes que decidem dinheiro ou politica apertam mais, cada uma no seu
    // metodo. Ver requireAdminLevel abaixo.
    public function __construct() {
        // parent::__construct();
        AuthHelper::requireAdminLevel('super', 'gerente', 'vendedor', 'estoque');
        $this->service = new DevolucaoService();
        $this->db      = Database::getInstance()->getConnection();
    }
 
    public function index(): void {
        $filtros = [
            'status' => SecurityHelper::sanitizeString($_GET['status'] ?? ''),
            'tipo'   => SecurityHelper::sanitizeString($_GET['tipo']   ?? ''),
            'q'      => SecurityHelper::sanitizeString($_GET['q']      ?? ''),
        ];
        $page    = max(1, (int)($_GET['page'] ?? 1));
        $ativos  = array_filter($filtros);
        $total   = $this->service->contar($ativos);
        $lista   = $this->service->listar($ativos, $page, 20);
        // Do banco, não da página: os chips precisam do total de cada status,
        // e $lista traz 20 linhas.
        $contagens  = $this->service->contarPorStatus($ativos);
        $totalPages = (int)ceil($total / 20);
 
        $this->render('devolucoes/index', compact(
            'lista','filtros','total','page','totalPages','contagens'
        ), 'admin');
    }
 
    public function show(int $id): void {
        $sol = $this->service->findById($id);
        if (!$sol) $this->notFound();
 
        $itens    = $this->service->getItens($id);
        $historico= $this->service->getHistorico($id);
        $motivos  = $this->service->getMotivos(false);
 
        $this->render('devolucoes/show', compact(
            'sol','itens','historico','motivos'
        ), 'admin');
    }
 
    public function aprovar(int $id): void {
        $this->verifyCsrf();
        AuthHelper::requireAdminLevel('super','gerente');
        $obs     = SecurityHelper::sanitizeString($_POST['observacao'] ?? '');
        $adminId = (int)Session::get('admin_id');
        $this->json($this->service->aprovar($id, $adminId, $obs));
    }
 
    public function negar(int $id): void {
        $this->verifyCsrf();
        AuthHelper::requireAdminLevel('super','gerente');
        $motivo  = SecurityHelper::sanitizeString($_POST['motivo'] ?? '');
        $adminId = (int)Session::get('admin_id');
        if (empty($motivo)) $this->json(['ok' => false, 'msg' => 'Informe o motivo da negação.']);
        $this->json($this->service->negar($id, $adminId, $motivo));
    }
 
    public function confirmarRecebimento(int $id): void {
        $this->verifyCsrf();
        AuthHelper::requireAdminLevel('super','gerente','estoque');
        $adminId = (int)Session::get('admin_id');
        $obs     = SecurityHelper::sanitizeString($_POST['observacao'] ?? '');
        $this->json($this->service->confirmarRecebimento($id, $adminId, $obs));
    }
 
    public function inspecionar(int $id): void {
        $this->verifyCsrf();
        AuthHelper::requireAdminLevel('super','gerente');
        $resultado     = SecurityHelper::sanitizeString($_POST['resultado'] ?? '');
        $obs           = SecurityHelper::sanitizeString($_POST['observacao'] ?? '');
        $valorAprovado = !empty($_POST['valor_aprovado'])
            ? (float)str_replace(',', '.', $_POST['valor_aprovado'])
            : null;

        // Quantidades linha a linha, quando o admin abriu o detalhamento.
        // Vazio = veredito único, como sempre foi. O service normaliza os
        // limites; aqui só se converte o que veio do formulário.
        $itens = [];
        foreach ((array)($_POST['itens'] ?? []) as $itemId => $dados) {
            $itens[(int)$itemId] = [
                'recebida' => (int)($dados['recebida'] ?? 0),
                'aprovada' => (int)($dados['aprovada'] ?? 0),
                'obs'      => SecurityHelper::sanitizeString($dados['obs'] ?? ''),
            ];
        }

        $adminId = (int)Session::get('admin_id');
        $this->json($this->service->inspecionar($id, $adminId, $resultado, $obs, $valorAprovado, $itens));
    }
 
    // ── POST /admin/devolucoes/{id}/encerrar ──────────────
    // Só para solicitações que ficaram presas em `inspecionado_reprovado`
    // antes de 10/09/2026. Ver DevolucaoService::encerrarReprovado().
    public function encerrarReprovado(int $id): void {
        $this->verifyCsrf();
        AuthHelper::requireAdminLevel('super','gerente');
        $obs     = SecurityHelper::sanitizeString($_POST['observacao'] ?? '');
        $adminId = (int)Session::get('admin_id');
        $this->json($this->service->encerrarReprovado($id, $adminId, $obs ?: null));
    }

    public function reembolsar(int $id): void {
        $this->verifyCsrf();
        AuthHelper::requireAdminLevel('super','gerente');
        $tipo    = SecurityHelper::sanitizeString($_POST['tipo_reembolso'] ?? '');
        $dados   = $_POST['dados'] ?? [];
        $adminId = (int)Session::get('admin_id');
        $this->json($this->service->reembolsar($id, $adminId, $tipo, $dados));
    }
 
    // ── POST /admin/devolucoes/{id}/gerar-postagem ────────
    public function gerarPostagem(int $id): void {
        $this->verifyCsrf();
        // Emite etiqueta reversa de verdade — a transportadora cobra por ela.
        AuthHelper::requireAdminLevel('super','gerente');
        $adminId = (int)Session::get('admin_id');
        $result  = $this->service->gerarPostagem($id, $adminId);
        $this->json($result);
    }
 
    // ── GET /admin/devolucoes/buscar-para-recebimento?q= ─
    public function buscarParaRecebimento(): void {
        // Sem `verifyCsrf()`: a rota é GET e o JS chama com `$.get`, sem corpo
        // e sem o header X-CSRF-Token — a guarda reprovava sempre que alguém
        // tentasse usá-la fora do caminho feliz.
        //
        // E CSRF aqui não protegeria nada: o ataque consiste em fazer o
        // navegador da vítima EXECUTAR uma ação; esta rota só lê, e a
        // same-origin policy impede o site atacante de ler a resposta. Quem
        // protege é a sessão + o nível.
        AuthHelper::requireAdminLevel('super','gerente','estoque');
        $q = SecurityHelper::sanitizeString($_GET['q'] ?? '');
        if (strlen(trim($q)) < 3) {
            $this->json(['ok' => false, 'msg' => 'Digite ao menos 3 caracteres.']);
        }
        $sol = $this->service->buscarParaRecebimento($q);
        if (!$sol) {
            $this->json(['ok' => false, 'msg' => 'Nenhuma solicitação ativa encontrada.']);
        }
        $this->json(['ok' => true, 'sol' => $sol]);
    }
 
    // ── POST /admin/devolucoes/receber-manual ─────────────
    public function receberManual(): void {
        $this->verifyCsrf();
        AuthHelper::requireAdminLevel('super','gerente','estoque');
        $adminId  = (int)Session::get('admin_id');
        $solId    = (int)($_POST['sol_id']           ?? 0);
        $postagem = SecurityHelper::sanitizeString($_POST['codigo_postagem'] ?? '');
        $rastreio = SecurityHelper::sanitizeString($_POST['codigo_rastreio'] ?? '');
        $obs      = SecurityHelper::sanitizeString($_POST['observacao']      ?? '');
 
        if (!$solId) {
            $this->json(['ok' => false, 'msg' => 'Solicitação não identificada.']);
        }
 
        $this->json(
            $this->service->receberManual($solId, $postagem, $rastreio, $obs, $adminId)
        );
    }
 
    // ── Motivos CRUD ──────────────────────────────────────
    public function motivos(): void {
        AuthHelper::requireAdminLevel('super','gerente');
        $lista = $this->service->getMotivos();
        $this->render('devolucoes/motivos', compact('lista'), 'admin');
    }
 
    public function salvarMotivo(): void {
        $this->verifyCsrf();
        // Define quem paga o frete reverso e o prazo de credito: e politica
        // comercial, nao operacao.
        AuthHelper::requireAdminLevel('super','gerente');
        $id    = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        $dados = [
            'label'             => SecurityHelper::sanitizeString($_POST['label']             ?? ''),
            'tipo'              => SecurityHelper::sanitizeString($_POST['tipo']              ?? 'ambos'),
            'exige_foto'        => (int)($_POST['exige_foto']        ?? 0),
            'responsavel_frete' => SecurityHelper::sanitizeString($_POST['responsavel_frete'] ?? 'loja'),
            'prazo_credito_dias'=> !empty($_POST['prazo_credito_dias']) ? (int)$_POST['prazo_credito_dias'] : null,
            'ativo'             => (int)($_POST['ativo']             ?? 1),
            'ordenacao'         => (int)($_POST['ordenacao']         ?? 0),
        ];
        if (empty($dados['label'])) $this->json(['ok' => false, 'msg' => 'Nome obrigatório.']);
 
        if ($id) {
            $this->db->prepare(
                "UPDATE motivos_devolucao SET label=?,tipo=?,exige_foto=?,responsavel_frete=?,
                 prazo_credito_dias=?,ativo=?,ordenacao=? WHERE id=?"
            )->execute([...array_values($dados), $id]);
        } else {
            $this->db->prepare(
                "INSERT INTO motivos_devolucao (label,tipo,exige_foto,responsavel_frete,
                 prazo_credito_dias,ativo,ordenacao) VALUES (?,?,?,?,?,?,?)"
            )->execute(array_values($dados));
            $id = (int)$this->db->lastInsertId();
        }
        $this->json(['ok' => true, 'id' => $id]);
    }
}
