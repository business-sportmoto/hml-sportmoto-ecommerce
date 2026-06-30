<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/controllers/AdminClienteController.php
// ════════════════════════════════════════════════════════

class AdminClienteController extends Controller {

    private AdminCliente   $model;
    private ScoreService   $score;
    private CreditoService $credito;
    private PDO            $db;

    public function __construct() {
        // parent::__construct();
        AuthHelper::requireAdmin();
        $this->model   = new AdminCliente();
        $this->score   = new ScoreService();
        $this->credito = new CreditoService();
        $this->db      = Database::getInstance()->getConnection();
    }

    // ── GET /admin/clientes ───────────────────────────────
    public function index(): void {
        $filtros = [
            'q'              => SecurityHelper::sanitizeString($_GET['q']              ?? ''),
            'tier'           => SecurityHelper::sanitizeString($_GET['tier']           ?? ''),
            'tag_id'         => (int)($_GET['tag_id'] ?? 0) ?: '',
            'ativo'          => $_GET['ativo'] ?? '',
            'aniversario_mes'=> (int)($_GET['aniversario_mes'] ?? 0) ?: '',
        ];
        $page    = max(1, (int)($_GET['page'] ?? 1));
        $total   = $this->model->contar(array_filter($filtros, fn($v) => $v !== ''));
        $clientes= $this->model->listar(array_filter($filtros, fn($v) => $v !== ''), $page, 25);
        $tags    = $this->model->getTodasTags();
        $totalPages = (int)ceil($total / 25);

        $this->render('clientes/index', compact(
            'clientes','filtros','total','page','totalPages','tags'
        ), 'admin');
    }

    // ── GET /admin/clientes/{id} ──────────────────────────
    public function show(int $id): void {
        $cliente = $this->model->findById($id);
        if (!$cliente) $this->notFound();

        $usuarioId  = (int)$cliente['usuario_id'];
        $stats      = $this->model->getStats($id);
        $tags       = $this->model->getTags($id);
        $todasTags  = $this->model->getTodasTags();
        $notas      = $this->model->getNotas($id);
        $pedidos    = $this->model->getPedidos($id, 10);
        $totalPed   = $this->model->getTotalPedidos($id);
        $devolucoes = $this->model->getDevolucoes($id, 5);
        $enderecos  = $this->model->getEnderecos($id);
        $cartoes    = $this->model->getCartoes($id);
        $wishlist   = $this->model->getWishlist($id, 12);
        $avaliacoes = $this->model->getAvaliacoes($id, 10);
        $carrinho   = $this->model->getCarrinho($id);
        $cupons     = $this->model->getCuponsUsados($id);
        $garagem    = $this->model->getGaragem($id);
        $sessoes    = $this->model->getSessoes($usuarioId);
        $emailsLog  = $this->model->getEmailsLog($id, 20);
        $timeline   = $this->model->getTimeline($id, $usuarioId, 30);

        $scoreRow   = $this->score->getRow($id);
        $saldo      = $this->credito->getSaldoDisponivel($id);
        $creditoHist= $this->credito->getHistorico($id, 10);

        // Doc + 2FA
        try {
            $docStatus  = (new DocumentService())->getStatus($id);
        } catch (\Throwable) { $docStatus = null; }
        try {
            $twofa      = (new TwoFactorService())->isAtivo($usuarioId);
        } catch (\Throwable) { $twofa = false; }

        // Indicadores de risco
        $riscos = $this->calcularRiscos($cliente, $scoreRow, $stats);

        // Aniversário
        $aniversario = $this->calcularAniversario($cliente['nascimento'] ?? null);

        $this->render('clientes/show', compact(
            'cliente','usuarioId','stats','tags','todasTags','notas',
            'pedidos','totalPed','devolucoes','enderecos','cartoes',
            'wishlist','avaliacoes','carrinho','cupons','garagem',
            'sessoes','emailsLog','timeline','scoreRow','saldo',
            'creditoHist','docStatus','twofa','riscos','aniversario'
        ), 'admin');
    }

    // ── POST /admin/clientes/{id}/salvar-perfil ───────────
    public function salvarPerfil(int $id): void {
        AuthHelper::requireAdminLevel('super', 'gerente');
        $this->verifyCsrf();

        $cliente = $this->model->findById($id);
        if (!$cliente) $this->json(['ok' => false, 'msg' => 'Cliente não encontrado.']);

        $dados = [
            'usuario' => [
                'nome'  => SecurityHelper::sanitizeString($_POST['nome']  ?? ''),
                'email' => SecurityHelper::sanitizeString($_POST['email'] ?? ''),
            ],
            'cliente' => [
                'cpf'          => preg_replace('/\D/', '', $_POST['cpf'] ?? ''),
                'telefone'     => SecurityHelper::sanitizeString($_POST['telefone']  ?? ''),
                'celular'      => SecurityHelper::sanitizeString($_POST['celular']   ?? ''),
                'nascimento'   => SecurityHelper::sanitizeString($_POST['nascimento']?? ''),
                'genero'       => SecurityHelper::sanitizeString($_POST['genero']    ?? ''),
                'newsletter'   => !empty($_POST['newsletter']),
                'insta_cliente'=> SecurityHelper::sanitizeString($_POST['insta']     ?? ''),
            ],
        ];

        if (empty($dados['usuario']['nome'])) {
            $this->json(['ok' => false, 'msg' => 'Nome obrigatório.']);
        }
        if (!empty($dados['usuario']['email']) && !filter_var($dados['usuario']['email'], FILTER_VALIDATE_EMAIL)) {
            $this->json(['ok' => false, 'msg' => 'E-mail inválido.']);
        }

        $this->model->updatePerfil($id, (int)$cliente['usuario_id'], $dados);
        $this->json(['ok' => true, 'msg' => 'Perfil atualizado.']);
    }

    // ── POST /admin/clientes/{id}/toggle-ativo ────────────
    public function toggleAtivo(int $id): void {
        AuthHelper::requireAdminLevel('super');
        $this->verifyCsrf();

        $cliente = $this->model->findById($id);
        if (!$cliente) $this->json(['ok' => false, 'msg' => 'Cliente não encontrado.']);

        $novoAtivo = !(bool)$cliente['ativo'];
        $this->model->toggleAtivo((int)$cliente['usuario_id'], $novoAtivo);
        $this->json([
            'ok'    => true,
            'ativo' => $novoAtivo,
            'msg'   => $novoAtivo ? 'Conta ativada.' : 'Conta bloqueada.',
        ]);
    }

    // ── POST /admin/clientes/{id}/tags ────────────────────
    public function salvarTags(int $id): void {
        $this->verifyCsrf();
        $tagIds  = array_map('intval', $_POST['tags'] ?? []);
        $adminId = (int)Session::get('admin_id');
        $this->model->setTags($id, $tagIds, $adminId);
        $tags = $this->model->getTags($id);
        $this->json(['ok' => true, 'tags' => $tags]);
    }

    // ── POST /admin/clientes/{id}/nota ────────────────────
    public function addNota(int $id): void {
        $this->verifyCsrf();
        $texto = SecurityHelper::sanitizeString($_POST['texto'] ?? '');
        if (empty(trim($texto))) $this->json(['ok' => false, 'msg' => 'Informe o texto da nota.']);
        $adminId = (int)Session::get('admin_id');
        $notaId  = $this->model->addNota($id, $texto, $adminId);
        $this->json([
            'ok'       => true,
            'nota_id'  => $notaId,
            'admin'    => Session::get('admin_nome') ?? 'Admin',
            'criado_em'=> date('d/m/Y H:i'),
        ]);
    }

    // ── POST /admin/clientes/{id}/nota/{nid}/del ──────────
    public function deleteNota(int $id, int $nid): void {
        AuthHelper::requireAdminLevel('super', 'gerente');
        $this->verifyCsrf();
        $this->model->deleteNota($nid);
        $this->json(['ok' => true]);
    }

    // ── POST /admin/clientes/{id}/email-personalizado ─────
    public function enviarEmailPersonalizado(int $id): void {
        $this->verifyCsrf();
        $assunto  = SecurityHelper::sanitizeString($_POST['assunto']  ?? '');
        $mensagem = SecurityHelper::sanitizeString($_POST['mensagem'] ?? '');
        if (empty($assunto) || empty($mensagem)) {
            $this->json(['ok' => false, 'msg' => 'Assunto e mensagem são obrigatórios.']);
        }
        $cliente = $this->model->findById($id);
        if (!$cliente) $this->json(['ok' => false, 'msg' => 'Cliente não encontrado.']);

        $email = new EmailService();
        $ok    = $email->enviarPersonalizado($cliente, $assunto, $mensagem);
        $this->json([
            'ok'  => $ok,
            'msg' => $ok ? 'E-mail enviado!' : 'Erro ao enviar o e-mail.',
        ]);
    }

    // ── Tags disponíveis (Settings) ───────────────────────
    public function salvarTagDisponivel(): void {
        AuthHelper::requireAdminLevel('super', 'gerente');
        $this->verifyCsrf();

        $id    = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        $dados = [
            'nome'      => SecurityHelper::sanitizeString($_POST['nome']       ?? ''),
            'cor'       => SecurityHelper::sanitizeString($_POST['cor']        ?? '#64748b'),
            'icone_key' => SecurityHelper::sanitizeString($_POST['icone_key']  ?? ''),
            'ativo'     => (int)($_POST['ativo'] ?? 1),
            'ordenacao' => (int)($_POST['ordenacao'] ?? 0),
        ];
        if (empty($dados['nome'])) $this->json(['ok' => false, 'msg' => 'Nome obrigatório.']);
        if (empty($dados['icone_key'])) $dados['icone_key'] = null;

        $novoId = $this->model->salvarTagDisponivel($dados, $id);
        $this->json(['ok' => true, 'id' => $novoId]);
    }

    public function deleteTagDisponivel(int $id): void {
        AuthHelper::requireAdminLevel('super');
        $this->verifyCsrf();
        $this->json($this->model->deleteTagDisponivel($id));
    }
    // ── GET /admin/clientes/{id}/wishlist/{wid} ──────────
    // Retorna os itens de uma wishlist para o drawer (AJAX/JSON)
    public function wishlistItens(int $clienteId, int $wishlistId): void {
        $itens = $this->model->getWishlistItens($wishlistId, $clienteId);
        $this->json(['ok' => true, 'itens' => $itens]);
    }
    // ════════════════════════════════════════════════════
    // PRIVADOS
    // ════════════════════════════════════════════════════

    private function calcularRiscos(array $cliente, ?array $scoreRow, array $stats): array {
        $riscos = [];

        if (!empty($scoreRow['total_chargebacks'])) {
            $riscos[] = ['tipo'=>'danger', 'msg'=>'Chargeback registrado ('.((int)$scoreRow['total_chargebacks']).'×)'];
        }
        if (!empty($scoreRow['total_reprovadas']) && (int)$scoreRow['total_reprovadas'] >= 2) {
            $riscos[] = ['tipo'=>'warning', 'msg'=>'Inspeção reprovada '.(int)$scoreRow['total_reprovadas'].'× em devoluções'];
        }
        if (!empty($cliente['ultimo_acesso'])) {
            $diasSemAcesso = (int)((time() - strtotime($cliente['ultimo_acesso'])) / 86400);
            if ($diasSemAcesso > 90) {
                $riscos[] = ['tipo'=>'info', 'msg'=>"Sem acesso há {$diasSemAcesso} dias"];
            }
        }
        if (!$cliente['ativo']) {
            $riscos[] = ['tipo'=>'danger', 'msg'=>'Conta bloqueada'];
        }
        return $riscos;
    }

    private function calcularAniversario(?string $nascimento): array {
        if (!$nascimento || $nascimento === '0000-00-00') return ['status' => 'sem_data'];

        $hoje     = new \DateTime();
        $nasc     = \DateTime::createFromFormat('Y-m-d', $nascimento);
        if (!$nasc) return ['status' => 'sem_data'];

        $mesNasc  = (int)$nasc->format('m');
        $diaNasc  = (int)$nasc->format('d');
        $mesAtual = (int)$hoje->format('m');
        $diaAtual = (int)$hoje->format('d');
        $idade    = (int)$nasc->diff($hoje)->y;

        // Próximo aniversário
        $proxNasc = new \DateTime($hoje->format('Y') . '-' . $nasc->format('m-d'));
        if ($proxNasc < $hoje) $proxNasc->modify('+1 year');
        $diasAte  = (int)$hoje->diff($proxNasc)->days;

        if ($mesNasc === $mesAtual && $diaNasc === $diaAtual) {
            return ['status' => 'hoje', 'idade' => $idade, 'data_fmt' => $nasc->format('d/m')];
        }
        if ($mesNasc === $mesAtual) {
            return ['status' => 'no_mes', 'dias_ate' => $diasAte, 'data_fmt' => $nasc->format('d/m'), 'idade' => $idade];
        }
        return ['status' => 'outro_mes', 'data_fmt' => $nasc->format('d/m'), 'dias_ate' => $diasAte, 'idade' => $idade];
    }
}