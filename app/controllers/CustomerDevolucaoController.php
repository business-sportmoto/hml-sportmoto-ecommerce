<?php 
class CustomerDevolucaoController extends Controller {
 
    private DevolucaoService $service;
    private Customer         $customerModel;
 
    public function __construct() {
        // parent::__construct();
        AuthHelper::requireCustomer();
        $this->service       = new DevolucaoService();
        $this->customerModel = new Customer();
    }
 
    /** GET /minha-conta/devolucoes */
    public function index(): void {
        $clienteId = $this->clienteId();
        $page      = max(1, (int)($_GET['pagina'] ?? 1));
        $filtros   = ['cliente_id' => $clienteId];
        $total     = $this->service->contar($filtros);
        $lista     = $this->service->listar($filtros, $page, 10);
        $perfil    = $this->customerModel->getFullProfile($clienteId);
        $this->render('customer/devolucoes/index', compact('lista','total','page','perfil'), 'customer');
    }
 
    /** GET /minha-conta/devolucao/nova/{pedidoId} */
    public function novaForm(int $pedidoId): void {
        $clienteId  = $this->clienteId();
        $db         = Database::getInstance()->getConnection();
        $stmt       = $db->prepare(
            "SELECT p.*, u.nome AS cliente_nome
             FROM pedidos p
             JOIN clientes c ON c.id = p.cliente_id
             JOIN usuarios u ON u.id = c.usuario_id
             WHERE p.id = ? AND p.cliente_id = ? AND p.status_pedido = 'entregue'
             LIMIT 1"
        );
        $stmt->execute([$pedidoId, $clienteId]);
        $pedido = $stmt->fetch();
        if (!$pedido) {
            Session::flash('error', 'Pedido não disponível para devolução.');
            $this->redirect(BASE_URL . '/minha-conta/pedidos');
        }
 
        // Itens do pedido
        $stmtI = $db->prepare(
            "SELECT pi.*,
                    COALESCE(pi.nome_produto, pr.nome) AS nome_produto,
                    COALESCE(pi.imagem_snapshot,
                        (SELECT img.arquivo FROM produto_imagens img
                         WHERE img.produto_id = pr.id AND img.principal=1 LIMIT 1)
                    ) AS imagem
             FROM pedido_itens pi
             JOIN produtos pr ON pr.id = pi.produto_id
             WHERE pi.pedido_id = ?"
        );
        $stmtI->execute([$pedidoId]);
        $itens   = $stmtI->fetchAll();
        $motivos = $this->service->getMotivos(true);
 
        // Data de entrega real — primeiro evento "entregue" no histórico.
        // É a referência correta para o prazo CDC de 7 dias (Art. 49), e desde
        // 10/09/2026 mora no service: era esta consulta, copiada aqui e no
        // app, enquanto o `criar()` usava `atualizado_em` e discordava das
        // duas telas.
        $dataEntrega = $this->service->dataDeEntrega($pedidoId) ?: $pedido['atualizado_em'];
 
        $perfil = $this->customerModel->getFullProfile($clienteId);
        $this->render(
            'customer/devolucoes/nova',
            compact('pedido', 'itens', 'motivos', 'perfil', 'dataEntrega'),
            'customer'
        );
    }
 
    /** POST /minha-conta/devolucao/nova */
    public function criar(): void {
        // A view sempre mandou o token (nova.php:41); faltava conferir.
        $this->verifyCsrf();

        $clienteId = $this->clienteId();
        $pedidoId  = (int)($_POST['pedido_id']  ?? 0);
        $tipo      = SecurityHelper::sanitizeString($_POST['tipo']      ?? 'devolucao');
        $motivoId  = (int)($_POST['motivo_id']  ?? 0);
        $descricao = SecurityHelper::sanitizeString($_POST['descricao'] ?? '');
 
        // Itens selecionados: [{pedido_item_id, quantidade}]
        $itens = [];
        foreach ($_POST['itens'] ?? [] as $itemId => $qtd) {
            $q = (int)$qtd;
            if ($q > 0) {
                $itens[] = ['pedido_item_id' => (int)$itemId, 'quantidade' => $q];
            }
        }
 
        // ── A regra ANTES do disco ───────────────────────────────
        //
        // Os arquivos eram movidos para uploads/devolucoes/ e só então o
        // service decidia se a solicitação podia existir. Quando ele recusava
        // — prazo vencido, pedido não entregue, já havia solicitação ativa —
        // as mídias ficavam órfãs no disco para sempre, sem linha no banco
        // que as referenciasse.
        //
        // Mesma forma do bug dos pedidos órfãos no checkout: efeito colateral
        // gravado antes da regra que decide se ele deveria existir.
        $pode = $this->service->podeSolicitar($clienteId, $pedidoId);
        if (!$pode['ok']) {
            $this->json(['ok' => false, 'msg' => $pode['msg']]);
        }

        // Upload de mídias — imagens (jpg, png, webp) e vídeo (mp4, mov)
        $fotos = [];
        $extPermitidas = ['jpg','jpeg','png','webp','mp4','mov','m4v'];
        if (!empty($_FILES['midias']['name'][0])) {
            $uploadDir = ROOT_PATH . '/uploads/devolucoes/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
 
            foreach ($_FILES['midias']['tmp_name'] as $k => $tmp) {
                if ($_FILES['midias']['error'][$k] !== UPLOAD_ERR_OK) continue;
                if ($_FILES['midias']['size'][$k] > 10 * 1024 * 1024) continue; // 10MB
 
                $ext  = strtolower(pathinfo($_FILES['midias']['name'][$k], PATHINFO_EXTENSION));
                if (!in_array($ext, $extPermitidas, true)) continue;
 
                // Valida MIME real para imagens
                if (in_array($ext, ['jpg','jpeg','png','webp'])) {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime  = finfo_file($finfo, $tmp);
                    finfo_close($finfo);
                    if (!in_array($mime, ['image/jpeg','image/png','image/webp'])) continue;
                }
 
                $nome = 'dev_' . bin2hex(random_bytes(8)) . '.' . $ext;
                if (move_uploaded_file($tmp, $uploadDir . $nome)) {
                    $fotos[] = $nome;
                }
            }
        }
 
        $result = $this->service->criar($clienteId, $pedidoId, $tipo, $motivoId, $itens, $descricao, $fotos);

        // A checagem acima cobre o caso comum, mas entre ela e o `criar()`
        // ainda cabe uma corrida (duas abas, dois cliques). Se o service
        // recusou, nada aponta para estes arquivos — apaga.
        if (!$result['ok'] && $fotos) {
            foreach ($fotos as $nome) {
                $caminho = ROOT_PATH . '/uploads/devolucoes/' . $nome;
                if (is_file($caminho)) @unlink($caminho);
            }
        }
 
        if ($result['ok']) {
            $this->json([
                'ok'       => true,
                'redirect' => BASE_URL . '/minha-conta/devolucao/' . $result['solicitacao_id'],
                'msg'      => $result['auto_aprovado']
                    ? 'Solicitação criada e pré-aprovada! Você receberá as instruções de postagem por e-mail.'
                    : 'Solicitação criada. Aguarde a análise da nossa equipe.',
            ]);
        } else {
            $this->json(['ok' => false, 'msg' => $result['msg']]);
        }
    }
 
    /** GET /minha-conta/devolucao/{id} */
    public function show(int $id): void {
        $clienteId = $this->clienteId();
        $sol = $this->service->findById($id);
        if (!$sol || (int)$sol['cliente_id'] !== $clienteId) {
            Session::flash('error', 'Solicitação não encontrada.');
            $this->redirect(BASE_URL . '/minha-conta/pedidos');
        }
        $itens    = $this->service->getItens($id);
        $historico= $this->service->getHistorico($id);
        $perfil = $this->customerModel->getFullProfile($clienteId);
        $this->render('customer/devolucoes/show', compact('sol','itens','historico','perfil'), 'customer');
    }
 
    /** POST /minha-conta/devolucao/{id}/cancelar */
    public function cancelar(int $id): void {
        if (!$this->csrfDeFormulario($id)) return;

        $result = $this->service->cancelarPorCliente($id, $this->clienteId());
        if ($result['ok']) Session::flash('success', 'Solicitação cancelada.');
        else               Session::flash('error',   $result['msg']);
        $this->redirect(BASE_URL . '/minha-conta/devolucao/' . $id);
    }
 
    /** POST /minha-conta/devolucao/{id}/rastreio */
    public function informarRastreio(int $id): void {
        if (!$this->csrfDeFormulario($id)) return;

        $codigo = strtoupper(trim(SecurityHelper::sanitizeString($_POST['codigo_rastreio'] ?? '')));
        $result = $this->service->informarRastreio($id, $this->clienteId(), $codigo);
        if ($result['ok']) Session::flash('success', 'Código de rastreio informado!');
        else               Session::flash('error',   $result['msg']);
        $this->redirect(BASE_URL . '/minha-conta/devolucao/' . $id);
    }
 
    /**
     * CSRF para os POSTs que sao FORMULARIO, nao Ajax.
     *
     * `verifyCsrf()` da classe base responde JSON 403 — certo para o `criar()`,
     * que e Ajax, e errado aqui: o cliente veria um punhado de JSON cru no
     * lugar da pagina. Estes dois voltam para o pedido com uma mensagem.
     *
     * Retorna false quando ja redirecionou; quem chama so precisa dar return.
     */
    private function csrfDeFormulario(int $solId): bool {
        $token = $_POST[CSRF_TOKEN_NAME] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (SecurityHelper::validateCsrf($token)) return true;

        Session::flash('error', 'Sua sessão expirou. Tente novamente.');
        $this->redirect(BASE_URL . '/minha-conta/devolucao/' . $solId);
        return false;
    }

    private function clienteId(): int {
        return (int)Session::get('cliente_id');
    }
}