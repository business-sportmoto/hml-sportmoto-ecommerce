<?php
// app/controllers/CustomerController.php

class CustomerController extends Controller {

    private Customer         $customerModel;
    private SessionManager   $sessionManager;
    private TwoFactorService $twoFactorService;

    public function __construct() {
        AuthHelper::requireCustomer();
        $this->customerModel    = new Customer();
        $this->sessionManager   = new SessionManager();
        $this->twoFactorService = new TwoFactorService();
    }

    private function clienteId(): int {
        return (int) Session::getClienteId();
    }

    // ── Dashboard ─────────────────────────────────────────────

    public function dashboard(): void {
        $perfil  = $this->customerModel->getFullProfile($this->clienteId());
        $stats   = $this->customerModel->getDashboardStats($this->clienteId());
        $pedidos = $this->customerModel->getOrders($this->clienteId(), 3);

        SeoHelper::setTitle('Minha Conta');
        $this->render('customer/conta', [
            'perfil'  => $perfil,
            'stats'   => $stats,
            'pedidos' => $pedidos,
        ], 'customer');
    }

    // ── Dashboard ─────────────────────────────────────────────

    public function minhasAvaliacoes(): void {
        $perfil  = $this->customerModel->getFullProfile($this->clienteId());
        $stats   = $this->customerModel->getDashboardStats($this->clienteId());
        $pedidos = $this->customerModel->getOrders($this->clienteId(), 3);
        $itens   = $this->customerModel->getProdutosParaAvaliar($this->clienteId());

        // avaliados/pendentes calculados a partir da própria lista —
        // evita uma query extra só para contar o que já temos em mãos.
        $stats['avaliados'] = count(array_filter($itens, fn($i) => (bool)$i['ja_avaliou']));
        $stats['pendentes'] = count($itens) - $stats['avaliados'];

        SeoHelper::setTitle('Minhas Avaliações');
        $this->render('customer/minhas-avaliacoes', [
            'perfil'  => $perfil,
            'stats'   => $stats,
            'pedidos' => $pedidos,
            'itens'   => $itens,
        ], 'customer');
    }

    // ── Pedidos ───────────────────────────────────────────────

    public function orders(): void {
        $perfil  = $this->customerModel->getFullProfile($this->clienteId());

        $page    = max(1, (int)($_GET['pagina'] ?? 1));
        $total   = $this->customerModel->countOrders($this->clienteId());
        $pag     = new PaginationHelper($total, $page, BASE_URL . '/minha-conta/pedidos', 10);
        $pedidos = $this->customerModel->getOrders($this->clienteId(), $pag->getPerPage(), $pag->offset());

        // Status do banco para chips e labels dinâmicos
        $statusModel  = new PedidoStatus();
        $statusMapDb  = $statusModel->getMapBySlug();
        $statusCounts = $this->customerModel->getStatusCounts($this->clienteId());

        SeoHelper::setTitle('Meus Pedidos');
        $this->render('customer/orders', array_merge($pag->toArray(), [
            'perfil'       => $perfil,
            'pedidos'      => $pedidos,
            'statusMapDb'  => $statusMapDb,
            'statusCounts' => $statusCounts,
        ]), 'customer');
    }

    public function orderDetail(string $id): void {
        $perfil  = $this->customerModel->getFullProfile($this->clienteId());
        $pedido = $this->customerModel->getOrder($this->clienteId(), (int)$id);
        if (!$pedido) {
            Session::flash('error', 'Pedido não encontrado.');
            $this->redirect(BASE_URL . '/minha-conta/pedidos');
        }

        $itens     = $this->customerModel->getOrderItems((int)$pedido['id']);
        $historico = $this->customerModel->getOrderHistory((int)$pedido['id']);

        // Status + NF do banco
        $statusModel = new PedidoStatus();
        $statusMapDb = $statusModel->getMapBySlug();
        $orderModel  = new Order();
        $nf          = $orderModel->getNotaFiscal((int)$pedido['id'], $this->clienteId());

        // Carrega devolução ativa do pedido (se houver)
        $devolucao = null;
        if (($pedido['status_pedido'] ?? '') === 'troca_devolucao') {
            try {
                $devService = new DevolucaoService();
                $devLista   = $devService->listar([
                    'cliente_id'=> $this->clienteId(),
                    'pedido_id' => (int)$pedido['id'],
                ], 1, 1);
                if (!empty($devLista)) {
                    $sol = $devLista[0];
                    $devolucao = [
                        'solicitacao'  => $sol,
                        'itens'        => $devService->getItens((int)$sol['id']),
                        'historico'    => $devService->getHistorico((int)$sol['id']),
                        'fotos'        => json_decode($sol['fotos_json'] ?? '[]', true) ?: [],
                    ];
                }
            } catch (\Throwable) {}
        }

        SeoHelper::setTitle('Pedido ' . $pedido['codigo']);
        $this->render('customer/order-detail', [
            'perfil'     => $perfil,
            'pedido'     => $pedido,
            'itens'      => $itens,
            'historico'  => $historico,
            'statusMapDb'=> $statusMapDb,
            'nf'         => $nf,
            'devolucao'  => $devolucao,
        ], 'customer');
    }

    // ── Endereços ─────────────────────────────────────────────

    public function addresses(): void {
        $perfil  = $this->customerModel->getFullProfile($this->clienteId());
        $enderecos = $this->customerModel->getAddresses($this->clienteId());
        SeoHelper::setTitle('Meus Endereços');
        $this->render('customer/addresses', [
            'perfil'  => $perfil,
            'enderecos' => $enderecos], 'customer'
        );
    }

    public function saveAddress(): void {
        $this->verifyCsrf();

        $enderecoId = SecurityHelper::sanitizeInt($_POST['endereco_id'] ?? 0) ?: null;
        $data = [
            'nome_destinatario' => SecurityHelper::sanitizeString($_POST['nome_destinatario'] ?? ''),
            'cep'               => preg_replace('/\D/', '', $_POST['cep']          ?? ''),
            'logradouro'        => SecurityHelper::sanitizeString($_POST['logradouro']        ?? ''),
            'numero'            => SecurityHelper::sanitizeString($_POST['numero']            ?? ''),
            'complemento'       => SecurityHelper::sanitizeString($_POST['complemento']       ?? ''),
            'bairro'            => SecurityHelper::sanitizeString($_POST['bairro']            ?? ''),
            'cidade'            => SecurityHelper::sanitizeString($_POST['cidade']            ?? ''),
            'estado'            => SecurityHelper::sanitizeString($_POST['estado']            ?? ''),
            'telefone_contato'  => SecurityHelper::sanitizeString($_POST['telefone']          ?? ''),
        ];

        $errors = [];
        if (mb_strlen($data['nome_destinatario']) < 3) $errors[] = 'Nome do destinatário inválido.';
        if (strlen($data['cep']) !== 8)                 $errors[] = 'CEP inválido.';
        if (empty($data['logradouro']))                 $errors[] = 'Logradouro obrigatório.';
        if (empty($data['numero']))                     $errors[] = 'Número obrigatório.';
        if (empty($data['cidade']))                     $errors[] = 'Cidade obrigatória.';
        if (strlen($data['estado']) !== 2)              $errors[] = 'Estado inválido.';

        if ($errors) {
            $this->json(['ok' => false, 'errors' => $errors]);
        }

        $id = $this->customerModel->saveAddress($this->clienteId(), $data, $enderecoId);
        $this->json(['ok' => true, 'endereco_id' => $id, 'msg' => 'Endereço salvo!']);
    }

    public function deleteAddress(): void {
        $this->verifyCsrf();
        $enderecoId = SecurityHelper::sanitizeInt($_POST['endereco_id'] ?? 0);

        try {
            $this->customerModel->deleteAddress($this->clienteId(), $enderecoId);
            $this->json(['ok' => true, 'msg' => 'Endereço removido.']);
        } catch (RuntimeException $e) {
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }

    public function setPrincipalAddress(): void {
        $this->verifyCsrf();
        $enderecoId = SecurityHelper::sanitizeInt($_POST['endereco_id'] ?? 0);
        $this->customerModel->setPrincipalAddress($this->clienteId(), $enderecoId);
        $this->json(['ok' => true, 'msg' => 'Endereço principal atualizado.']);
    }

    // ── Wishlist ──────────────────────────────────────────────

    public function wishlist(): void {
        $perfil  = $this->customerModel->getFullProfile($this->clienteId());
        $lists = $this->customerModel->getWishlists($this->clienteId());
        $items = [];
        if (!empty($lists)) {
            $items = $this->customerModel->getWishlistItems(
                (int)$lists[0]['id'], $this->clienteId()
            );
        }

        SeoHelper::setTitle('Meus Favoritos');
        $this->render('customer/wishlist', [
            'perfil'  => $perfil,
            'lists' => $lists,
            'items' => $items,
        ], 'customer');
    }

    public function toggleWishlist(): void {
        
        if (!Session::isClienteLogado()) {
            $this->json([
                'ok' => false, 
                'redirect' => BASE_URL . '/login',
                'favoritado' => false,
                'msg'        => 'Adicionado aos favoritos!',
            ]);
        }
        $this->verifyCsrf();

        $productId    = SecurityHelper::sanitizeInt($_POST['produto_id'] ?? 0);
        $jaFavoritado = $this->customerModel->isInWishlist($this->clienteId(), $productId);

        if ($jaFavoritado) {
            $this->customerModel->removeFromWishlist($this->clienteId(), $productId);
            $this->json([
                'ok'         => true,
                'favoritado' => false,
                'msg'        => 'Removido dos favoritos.',
            ]);
        } else {
            $this->customerModel->addToWishlist($this->clienteId(), $productId);
            $this->json([
                'ok'         => true,
                'favoritado' => true,
                'msg'        => 'Adicionado aos favoritos!',
            ]);
        }
    }

    public function checkWishlist(): void {
        if (!Session::isClienteLogado()) {
            $this->json(['ok' => false, 'favoritados' => []]);
        }

        $idsStr = $_POST['ids'] ?? '';
        $ids    = array_filter(array_map('intval', explode(',', $idsStr)));

        if (empty($ids)) {
            $this->json(['ok' => true, 'favoritados' => []]);
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare(
            "SELECT DISTINCT wi.produto_id
             FROM wishlist_itens wi
             JOIN wishlist w ON w.id = wi.wishlist_id
             WHERE w.cliente_id = ?
               AND wi.produto_id IN ({$placeholders})"
        );
        $stmt->execute(array_merge([$this->clienteId()], $ids));
        $favoritados = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $this->json(['ok' => true, 'favoritados' => array_map('intval', $favoritados)]);
    }

    // ── Cartões ───────────────────────────────────────────────

    public function cards(): void {
        $perfil  = $this->customerModel->getFullProfile($this->clienteId());
        $cards = $this->customerModel->getCards($this->clienteId());
        SeoHelper::setTitle('Meus Cartões');
        $this->render('customer/cards', ['perfil'  => $perfil,'cards' => $cards], 'customer');
    }

    public function deleteCard(): void {
        $this->verifyCsrf();
        $cartaoId = SecurityHelper::sanitizeInt($_POST['cartao_id'] ?? 0);
        $ok = $this->customerModel->deleteCard($this->clienteId(), $cartaoId);
        $this->json(['ok' => $ok, 'msg' => $ok ? 'Cartão removido.' : 'Erro ao remover.']);
    }

    public function setPrincipalCard(): void {
        $this->verifyCsrf();
        $cartaoId = SecurityHelper::sanitizeInt($_POST['cartao_id'] ?? 0);
        $this->customerModel->setPrincipalCard($this->clienteId(), $cartaoId);
        $this->json(['ok' => true, 'msg' => 'Cartão principal atualizado.']);
    }

    // ── Perfil ────────────────────────────────────────────────

    public function profile(): void {
        $perfil    = $this->customerModel->getFullProfile($this->clienteId());
        $docService = new DocumentService();
        $docStatus  = $docService->getStatus($this->clienteId());

        SeoHelper::setTitle('Editar Perfil');
        $this->render('customer/profile', [
            'perfil'      => $perfil,            
            'doc_status'  => $docStatus,
            'twofa_ativo' => $this->twoFactorService->isAtivo((int)$perfil['usuario_id']),
        ], 'customer');
    }

    public function saveProfile(): void {
        $this->verifyCsrf();

        $perfil = $this->customerModel->getFullProfile($this->clienteId());
        $data = [
            'usuario_id'  => $perfil['usuario_id'],
            'nome'        => SecurityHelper::sanitizeString($_POST['nome']       ?? ''),
            'cpf'         => $_POST['cpf']        ?? '',
            'telefone'    => SecurityHelper::sanitizeString($_POST['telefone']   ?? ''),
            'celular'     => SecurityHelper::sanitizeString($_POST['celular']    ?? ''),
            'nascimento'  => $_POST['nascimento']  ?? '',
            'genero'      => $_POST['genero']      ?? '',
            'newsletter'  => isset($_POST['newsletter']),
        ];

        $errors = [];
        if (mb_strlen($data['nome']) < 3) $errors[] = 'Nome muito curto.';
        if (!empty($data['cpf']) && !SecurityHelper::validateCpf($data['cpf'])) {
            $errors[] = 'CPF inválido.';
        }
        if (!empty($data['cpf'])) {
            $userModel = new User();
            if ($userModel->cpfExists($data['cpf'], $this->clienteId())) {
                $errors[] = 'Este CPF já está cadastrado.';
            }
        }

        if ($errors) $this->json(['ok' => false, 'errors' => $errors]);

        if (!empty($_FILES['avatar']['name'])) {
            if (!SecurityHelper::validateUploadedImage($_FILES['avatar'])) {
                $this->json(['ok' => false, 'msg' => 'Imagem inválida. Use JPG, PNG ou WEBP até 5MB.']);
            }
            $uploadHelper = new UploadHelper();
            $arquivo = $uploadHelper->saveImage($_FILES['avatar'], 'avatars', 200, 200);
            if ($arquivo) {
                if (!empty($perfil['avatar'])) {
                    @unlink(UPLOAD_PATH . '/avatars/' . $perfil['avatar']);
                }
                $this->customerModel->updateAvatar($this->clienteId(), $arquivo);
            }
        }

        $this->customerModel->updateProfile($this->clienteId(), $data);
        Session::set('cliente_nome', $data['nome']);

        $this->json(['ok' => true, 'msg' => 'Perfil atualizado com sucesso!']);
    }

    // ── Trocar senha com 2FA ──────────────────────────────────

    public function changePassword(): void {
        $this->verifyCsrf();

        $perfil     = $this->customerModel->getFullProfile($this->clienteId());
        $userId     = (int)$perfil['usuario_id'];
        $senhaAtual = $_POST['senha_atual']     ?? '';
        $novaSenha  = $_POST['nova_senha']      ?? '';
        $confirmar  = $_POST['confirmar_senha'] ?? '';

        $userModel = new User();

        if (!$userModel->verifyCurrentPassword($userId, $senhaAtual)) {
            $this->json(['ok' => false, 'msg' => 'Senha atual incorreta.']);
        }
        if (!SecurityHelper::validatePassword($novaSenha)) {
            $this->json(['ok' => false, 'msg' => 'Senha fraca. Use 8+ caracteres, maiúsculas, minúsculas e números.']);
        }
        if ($novaSenha !== $confirmar) {
            $this->json(['ok' => false, 'msg' => 'As senhas não conferem.']);
        }

        if($this->twoFactorService->isAtivo($userId)){
            if (!$this->twoFactorService->acaoAutorizada('alterar_senha')) {
                $code = $this->twoFactorService->solicitarVerificacao($userId, 'alterar_senha');
                MailHelper::send2FACode($perfil['email'], $perfil['nome'], $code);
                $this->json([
                    'ok'         => false,
                    'requer_2fa' => true,
                    'msg'        => 'Código de verificação enviado para ' . $perfil['email'],
                ]);
            }
        }

        $userModel->updatePassword($userId, $novaSenha);
        $this->sessionManager->revokeAllExceptCurrent($userId);

        $this->json(['ok' => true, 'msg' => 'Senha alterada! Outras sessões foram encerradas.']);
    }

    // ── Sessões ativas ────────────────────────────────────────

    public function sessions(): void {
        $perfil   = $this->customerModel->getFullProfile($this->clienteId());
        $sessions = $this->sessionManager->getActiveSessions((int)$perfil['usuario_id']);

        SeoHelper::setTitle('Sessões e Segurança');
        $this->render('customer/sessions', [
            'perfil'      => $perfil,
            'sessions'    => $sessions,
            'twofa_ativo' => $this->twoFactorService->isAtivo((int)$perfil['usuario_id']),
        ], 'customer');
    }

    public function revokeSession(): void {
        $this->verifyCsrf();

        $perfil    = $this->customerModel->getFullProfile($this->clienteId());
        $userId    = (int)$perfil['usuario_id'];
        $sessionId = SecurityHelper::sanitizeInt($_POST['session_id'] ?? 0);

        if (!$this->twoFactorService->acaoAutorizada('revogar_sessoes')) {
            $code = $this->twoFactorService->solicitarVerificacao($userId, 'revogar_sessoes');
            MailHelper::send2FACode($perfil['email'], $perfil['nome'], $code);
            $this->json([
                'ok'         => false,
                'requer_2fa' => true,
                'msg'        => 'Código de verificação enviado para o seu e-mail.',
            ]);
        }

        $ok = $this->sessionManager->revokeSession($sessionId, $userId);
        $this->json(['ok' => $ok, 'msg' => $ok ? 'Sessão encerrada.' : 'Sessão não encontrada.']);
    }

    public function revokeAllSessions(): void {
        $this->verifyCsrf();

        $perfil = $this->customerModel->getFullProfile($this->clienteId());
        $userId = (int)$perfil['usuario_id'];

        if (!$this->twoFactorService->acaoAutorizada('revogar_sessoes')) {
            $code = $this->twoFactorService->solicitarVerificacao($userId, 'revogar_sessoes');
            MailHelper::send2FACode($perfil['email'], $perfil['nome'], $code);
            $this->json([
                'ok'         => false,
                'requer_2fa' => true,
                'msg'        => 'Código de verificação enviado para o seu e-mail.',
            ]);
        }

        $count = $this->sessionManager->revokeAllExceptCurrent($userId);

        if ($count === 0) {
            $this->json([
                'ok'  => true,
                'msg' => 'Nenhuma outra sessão ativa encontrada.',
            ]);
        }

        $this->json([
            'ok'  => true,
            'msg' => $count . ' sessão(ões) encerrada(s). Sua sessão atual permanece ativa.',
        ]);
    }

    // ── 2FA ───────────────────────────────────────────────────

    public function verify2fa(): void {
        $this->verifyCsrf();

        $perfil = $this->customerModel->getFullProfile($this->clienteId());
        $userId = (int)$perfil['usuario_id'];
        $code   = trim($_POST['code'] ?? '');
        $acao   = Session::get('2fa_acao_pendente', '');

        if (empty($code) || strlen($code) !== 6) {
            $this->json(['ok' => false, 'msg' => 'Código inválido.']);
        }

        if (SecurityHelper::rateLimitExceeded('2fa_verify_' . $userId, 5, 600)) {
            $this->json(['ok' => false, 'msg' => 'Muitas tentativas. Aguarde alguns minutos.']);
        }

        if (!$this->twoFactorService->validarCodigo($userId, $code)) {
            $this->json(['ok' => false, 'msg' => 'Código incorreto ou expirado.'.$code]);
        }

        $this->twoFactorService->marcarAutorizado($acao);
        SecurityHelper::clearRateLimit('2fa_verify_' . $userId);

        $this->json(['ok' => true, 'msg' => 'Verificação concluída!', 'acao' => $acao]);
    }

    public function toggle2fa(): void {
        $this->verifyCsrf();

        $perfil     = $this->customerModel->getFullProfile($this->clienteId());
        $userId     = (int)$perfil['usuario_id'];
        $twoFaAtivo = $this->twoFactorService->isAtivo($userId);
        $acao       = $twoFaAtivo ? 'desativar_2fa' : 'ativar_2fa';

        if (!$this->twoFactorService->acaoAutorizada($acao)) {
            $code = $this->twoFactorService->solicitarVerificacao($userId, $acao);
            MailHelper::send2FACode($perfil['email'], $perfil['nome'], $code);
            $this->json([
                'ok'         => false,
                'requer_2fa' => true,
                'msg'        => 'Código de verificação enviado para o seu e-mail.',
            ]);
        }

        if ($twoFaAtivo) {
            $this->twoFactorService->desativar($userId);
            $msg = 'Autenticação em dois fatores desativada.';
        } else {
            $this->twoFactorService->ativar($userId);
            $msg = 'Autenticação em dois fatores ativada!';
        }

        $this->json(['ok' => true, 'msg' => $msg, 'ativo' => !$twoFaAtivo]);
    }

    // Adicionar ao CustomerController

    public function history(): void {
        $perfil  = $this->customerModel->getFullProfile($this->clienteId());
        $page   = max(1, (int)($_GET['pagina'] ?? 1));
        $total  = (new History())->countClienteHistory($this->clienteId());
        $pag    = new PaginationHelper($total, $page, BASE_URL . '/minha-conta/historico', 30);

        $history    = (new History())->getClienteHistory($this->clienteId(), $pag->getPerPage(), $pag->offset());
        $maisVistos = (new History())->getMaisVistos($this->clienteId(), 6);
        $categorias = (new History())->getCategoriasFavoritas($this->clienteId());
        $marcas     = (new History())->getMarcasFavoritas($this->clienteId());
        $buscas     = (new History())->getTermosBusca($this->clienteId(), 8);

        SeoHelper::setTitle('Meu Histórico');
        $this->render('customer/history', array_merge($pag->toArray(), [
            'perfil'  => $perfil,
            'history'    => $history,
            'maisVistos' => $maisVistos,
            'categorias' => $categorias,
            'marcas'     => $marcas,
            'buscas'     => $buscas,
        ]), 'customer');
    }

    public function clearHistory(): void {
        $this->verifyCsrf();
        (new History())->clearHistory($this->clienteId());
        $this->json(['ok' => true, 'msg' => 'Histórico apagado.']);
    }

    public function updateHistoryTime(): void {
        $id       = SecurityHelper::sanitizeInt($_POST['id']       ?? 0);
        $segundos = SecurityHelper::sanitizeInt($_POST['segundos'] ?? 0);
        if ($id > 0 && $segundos > 0) {
            (new History())->updateTime($id, $segundos);
        }
        $this->json(['ok' => true]);
    }

    // Adicionar ao CustomerController existente:

    private function svc(): VeiculoService {
        return new VeiculoService();
    }

    // ── GET /minha-conta/garagem ──────────────────────────────
    public function garagem(): void {
        AuthHelper::requireCustomer();

        $perfil  = $this->customerModel->getFullProfile($this->clienteId());

        $clienteId = (int)Session::get('cliente_id');
        $svc       = $this->svc();
        $motos     = $svc->listarPorCliente($clienteId);

        $db = Database::getInstance()->getConnection();
        $montadoras = $db->query(
            "SELECT id, nome, slug FROM moto_montadoras WHERE ativo=1 ORDER BY nome ASC"
        )->fetchAll();

        SeoHelper::setTitle('Minha Garagem');
                
        $this->render('customer/garagem', [
            'perfil'  => $perfil,
            'motos'      => $motos,
            'montadoras' => $montadoras,            
        ], 'customer');
    }

    static public function getMotosPrincipal(): void {
        AuthHelper::requireCustomer();
        $clienteId = (int)Session::get('cliente_id');
        $motos     = $this->svc()->listarPorCliente($clienteId);
        $this->json(['ok' => true, 'motos' => $motos]);
    }

    // ── POST /minha-conta/garagem/adicionar ───────────────────
    public function garagemAdicionar(): void {
        AuthHelper::requireCustomer();
        $this->verifyCsrf();

        $clienteId = (int)Session::get('cliente_id');

        try {
            $moto = $this->svc()->adicionar(
                $clienteId,
                SecurityHelper::sanitizeInt($_POST['montadora_id'] ?? 0),
                SecurityHelper::sanitizeInt($_POST['modelo_id']    ?? 0) ?: null,
                SecurityHelper::sanitizeInt($_POST['ano']           ?? 0) ?: null,
                SecurityHelper::sanitizeString($_POST['apelido']    ?? ''),
                $this->validHex($_POST['cor']                       ?? ''),
                SecurityHelper::sanitizeString($_POST['placa']      ?? '') ?: null,
                isset($_POST['tornar_ativo']) ? true : false
            );
            $this->json(['ok' => true, 'msg' => 'Moto adicionada à garagem!', 'moto' => $moto]);
        } catch (\Exception $e) {
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }

    // ── POST /minha-conta/garagem/atualizar ───────────────────
    public function garagemAtualizar(): void {
        AuthHelper::requireCustomer();
        $this->verifyCsrf();

        $clienteId = (int)Session::get('cliente_id');
        $id        = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
        if (!$id) $this->json(['ok' => false, 'msg' => 'ID inválido.']);

        $ok = $this->svc()->atualizar($clienteId, $id, [
            'apelido'     => SecurityHelper::sanitizeString($_POST['apelido']     ?? ''),
            'cor'         => $this->validHex($_POST['cor']                         ?? ''),
            'placa'       => SecurityHelper::sanitizeString($_POST['placa']       ?? ''),
            'observacoes' => SecurityHelper::sanitizeString($_POST['observacoes'] ?? ''),
        ]);

        $this->json(['ok' => $ok, 'msg' => $ok ? 'Moto atualizada.' : 'Erro ao atualizar.']);
    }

    // ── POST /minha-conta/garagem/ativar ──────────────────────
    public function garagemAtivar(): void {
        AuthHelper::requireCustomer();
        $this->verifyCsrf();

        $clienteId = (int)Session::get('cliente_id');
        $id        = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);

        if ($this->svc()->ativar($clienteId, $id)) {
            $this->json(['ok' => true, 'veiculo' => $this->svc()->getAtivo()]);
        } else {
            $this->json(['ok' => false, 'msg' => 'Não foi possível ativar.']);
        }
    }

    // ── POST /minha-conta/garagem/remover ─────────────────────
    public function garagemRemover(): void {
        AuthHelper::requireCustomer();
        $this->verifyCsrf();

        $clienteId = (int)Session::get('cliente_id');
        $id        = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);

        $ok = $this->svc()->remover($clienteId, $id);
        $this->json(['ok' => $ok]);
    }

    // ── Helpers ───────────────────────────────────────────────
    private function validHex(string $color): ?string {
        $color = trim($color);
        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : null;
    }

    // Adicionar ao CustomerController:

    private function fotoSvc(): VeiculoFotoService {
        return new VeiculoFotoService();
    }

    // GET /minha-conta/garagem/{veiculoId}/fotos
    public function garagemFotos(int $veiculoId): void {
        AuthHelper::requireCustomer();
        $clienteId = (int)Session::get('cliente_id');
        $fotos     = $this->fotoSvc()->listarPorVeiculo($clienteId, $veiculoId);
        $this->json(['ok' => true, 'fotos' => $fotos]);
    }

    // POST /minha-conta/garagem/{veiculoId}/fotos/upload
    public function garagemFotoUpload(int $veiculoId): void {
        AuthHelper::requireCustomer();
        $this->verifyCsrf();

        $clienteId = (int)Session::get('cliente_id');

        if (empty($_FILES['foto']['tmp_name'])) {
            $this->json(['ok' => false, 'msg' => 'Nenhum arquivo enviado.']);
        }

        try {
            $foto = $this->fotoSvc()->upload($clienteId, $veiculoId, $_FILES['foto'], [
                'visibilidade' => $_POST['visibilidade'] ?? 'privado',
                'legenda'      => SecurityHelper::sanitizeString($_POST['legenda'] ?? ''),
            ]);

            $msg = $foto['visibilidade'] === 'publico'
                ? 'Foto enviada! Vai aparecer no site após aprovação.'
                : 'Foto enviada!';

            $this->json(['ok' => true, 'msg' => $msg, 'foto' => $foto]);
        } catch (\Exception $e) {
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }

    // POST /minha-conta/garagem/foto/atualizar
    public function garagemFotoAtualizar(): void {
        AuthHelper::requireCustomer();
        $this->verifyCsrf();

        $clienteId = (int)Session::get('cliente_id');
        $fotoId    = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);

        $ok = $this->fotoSvc()->atualizar($clienteId, $fotoId, [
            'legenda'      => $_POST['legenda']      ?? null,
            'visibilidade' => $_POST['visibilidade'] ?? null,
        ]);

        $this->json(['ok' => $ok]);
    }

    // POST /minha-conta/garagem/foto/capa
    public function garagemFotoCapa(): void {
        AuthHelper::requireCustomer();
        $this->verifyCsrf();

        $clienteId = (int)Session::get('cliente_id');
        $fotoId    = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);

        $this->json(['ok' => $this->fotoSvc()->definirCapa($clienteId, $fotoId)]);
    }

    // POST /minha-conta/garagem/foto/remover
    public function garagemFotoRemover(): void {
        AuthHelper::requireCustomer();
        $this->verifyCsrf();

        $clienteId = (int)Session::get('cliente_id');
        $fotoId    = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);

        $this->json(['ok' => $this->fotoSvc()->remover($clienteId, $fotoId)]);
    }

    // app/controllers/CustomerController.php — adicionar:

    public function garagemMoto(int $id): void {
        AuthHelper::requireCustomer();

        $clienteId = (int)Session::get('cliente_id');
        $svc       = new VeiculoService();
        $fotoSvc   = new VeiculoFotoService();

        $perfil  = $this->customerModel->getFullProfile($this->clienteId());        

        // Carrega a moto e valida propriedade
        $motos = $svc->listarPorCliente($clienteId);
        $moto  = null;
        foreach ($motos as $m) {
            if ((int)$m['id'] === $id) { $moto = $m; break; }
        }

        if (!$moto) {
            Session::flash('error', 'Moto não encontrada.');
            $this->redirect(BASE_URL . '/minha-conta/garagem');
            return;
        }

        $fotos = $fotoSvc->listarPorVeiculo($clienteId, $id);

        // Estatísticas
        $stats = [
            'total_fotos'     => count($fotos),
            'fotos_publicas'  => count(array_filter($fotos, fn($f) => $f['visibilidade'] === 'publico' && $f['status_moderacao'] === 'aprovada')),
            'fotos_pendentes' => count(array_filter($fotos, fn($f) => $f['status_moderacao'] === 'pendente')),
            'fotos_privadas'  => count(array_filter($fotos, fn($f) => $f['visibilidade'] === 'privado')),
        ];

        // Carrega dados do cliente (pra mostrar insta atual)
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT insta_cliente FROM clientes WHERE id = ? LIMIT 1");
        $stmt->execute([$clienteId]);
        $cliente = $stmt->fetch();

        $pageTitle = $moto['apelido'] ?: $moto['label'];

        $this->render('customer/garagem-moto', [
            'perfil'  => $perfil,
            'moto'      => $moto,
            'fotos'     => $fotos,
            'stats'     => $stats,
            'cliente'   => $cliente,
            'pageTitle' => $pageTitle,
        ], 'customer');
    }

    // Endpoint pra atualizar o insta do cliente
    public function atualizarInsta(): void {
        AuthHelper::requireCustomer();
        $this->verifyCsrf();

        $clienteId = (int)Session::get('cliente_id');
        $insta     = $this->normalizarInstagram($_POST['insta_cliente'] ?? '');

        $db = Database::getInstance()->getConnection();
        $db->prepare("UPDATE clientes SET insta_cliente = ? WHERE id = ?")
        ->execute([$insta, $clienteId]);

        $this->json(['ok' => true, 'insta' => $insta]);
    }

    /**
     * Normaliza Instagram pra @usuario.
     * Aceita: @usuario, usuario, instagram.com/usuario, https://...
     */
    private function normalizarInstagram(string $input): ?string {
        $input = trim($input);
        if (empty($input)) return null;

        // Remove URL completa
        $input = preg_replace('#^https?://(www\.)?instagram\.com/#i', '', $input);
        $input = preg_replace('#^(www\.)?instagram\.com/#i', '', $input);
        $input = trim($input, '/');
        $input = ltrim($input, '@');

        // Remove query string
        $input = strtok($input, '?');

        // Valida formato (Instagram permite letras, números, _ e .)
        if (!preg_match('/^[a-zA-Z0-9._]{1,30}$/', $input)) {
            return null;
        }

        return '@' . strtolower($input);
    }

    public function rastreio(int $id): void {
        // Anti-IDOR: valida que o pedido pertence ao cliente logado
        $pedido = $this->customerModel->getOrder($this->clienteId(), $id);
        if (!$pedido || empty($pedido['codigo_rastreio'])) {
            $this->json(['ok' => false, 'msg' => 'Rastreio não disponível.']);
        }
    
        $codigo = $pedido['codigo_rastreio'];
    
        // ────────────────────────────────────────────────────
        // INTEGRAÇÃO — substitua pelo seu provedor de rastreio
        // ────────────────────────────────────────────────────
        // Opção A: Correios (via API oficial ou proxy)
        //   $eventos = CorreiosService::rastrear($codigo);
        //
        // Opção B: Melhor Envio, Frenet, etc.
        //   $eventos = MelhorEnvioService::rastrear($codigo);
        //
        // Opção C: Dados do seu próprio banco
        //   (se você armazenar eventos em pedido_historico)
        // ────────────────────────────────────────────────────
    
        // Enquanto não integrar: retorna dados baseados no status do pedido
        $rastreio = $this->buildRastreioFromStatus($pedido);
    
        $this->json(['ok' => true, 'rastreio' => $rastreio]);
    }
    
    /**
     * Monta a resposta de rastreio a partir do status do pedido.
     * Substitua este método pelo retorno real da API de rastreio.
     */
    private function buildRastreioFromStatus(array $pedido): array {
        $status = $pedido['status_pedido'] ?? 'aguardando_pagamento';
    
        // Mapa de status → dados de exibição
        $mapa = [
            'em_separacao' => [
                'status_label'   => 'Em separação',
                'titulo'         => 'Pedido em separação',
                'descricao'      => 'Estamos separando os itens do seu pedido para envio.',
                'progresso'      => 30,
                'localizacao_atual' => null,
            ],
            'enviado' => [
                'status_label'   => 'Em rota',
                'titulo'         => 'Pacote em movimentação',
                'descricao'      => 'Seu pacote está a caminho e será entregue em breve.',
                'progresso'      => 65,
                'localizacao_atual' => null,
            ],
            'entregue' => [
                'status_label'   => 'Entregue',
                'titulo'         => 'Pacote entregue!',
                'descricao'      => 'Seu pedido foi entregue com sucesso. Obrigado pela compra!',
                'progresso'      => 100,
                'localizacao_atual' => null,
            ],
        ];
    
        $info = $mapa[$status] ?? $mapa['enviado'];
    
        // Última atualização do pedido
        $ultimaAtualizacao = !empty($pedido['atualizado_em'])
            ? date('d/m \à\s H:i', strtotime($pedido['atualizado_em']))
            : null;
    
        // Previsão de entrega
        $previsao = null;
        if (!empty($pedido['frete_prazo'])) {
            $diasUteis = (int)$pedido['frete_prazo'];
            // Calcula a data estimada (dias úteis a partir do envio)
            // Aqui você pode usar uma função real de cálculo de dias úteis
            $previsao = [
                'data_formatada' => $diasUteis . ' dia(s) úteis após postagem',
                'janela_inicio'  => '08:00',
                'janela_fim'     => '20:00',
            ];
        }
    
        return [
            'codigo'           => $pedido['codigo_rastreio'],
            'status_label'     => $info['status_label'],
            'titulo'           => $info['titulo'],
            'descricao'        => $info['descricao'],
            'progresso'        => $info['progresso'],
            'ultima_atualizacao' => $ultimaAtualizacao,
            'previsao_entrega' => $previsao,
            'localizacao_atual'=> $info['localizacao_atual'],
            'eventos'          => [], // preenchido pela API real
        ];
    }
}