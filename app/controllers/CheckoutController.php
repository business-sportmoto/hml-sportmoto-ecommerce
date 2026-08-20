<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/controllers/CheckoutController.php — v2
//
// Cada etapa = uma rota dedicada. Não há mais SPA com toggle.
// O estado é persistido via CheckoutState (sessão + DB).
//
// ROTAS:
//   GET  /checkout                         → index() redireciona
//   GET  /checkout/identify                → identify()
//   POST /checkout/identify                → identifyPost()
//   GET  /checkout/address                 → address()
//   POST /checkout/address/select          → addressSelect()  [AJAX]
//   GET  /checkout/address/add             → addressAdd()
//   POST /checkout/address/add             → addressAddPost()
//   GET  /checkout/address/update          → addressList()
//   GET  /checkout/address/update/{hash}   → addressEdit($hash)
//   POST /checkout/address/update/{hash}   → addressEditPost($hash)
//   GET  /checkout/payment                 → payment()
//   POST /checkout/payment/shipping        → calculateShipping()  [AJAX]
//   POST /checkout/payment/shipping/save   → saveShipping()       [AJAX]
//   POST /checkout/payment/coupon          → applyCoupon()        [AJAX]
//   GET  /checkout/summary                 → summary()
//   POST /checkout/finalize                → finalize()
//   GET  /checkout/success/{codigo}        → success()
//   GET  /checkout/cep                     → fetchCep()  [AJAX, mantido]
// ════════════════════════════════════════════════════════

// curl.exe --location --request POST "https://sandbox-api.malga.io/v1/charges/35cd6c39-7856-4fd0-9296-a776fb74bf2d" --header "X-Client-Id: f88629be-1490-468e-a1ce-9f3449f286aa" --header "X-Api-Key: 9bdc5cba-aa4c-4945-9d27-1c30f333a9ca" --header "Content-Type: application/json" --data-raw '{\"status\":\"authorized\"}'

class CheckoutController extends Controller {

    private Cart                $cartService;
    private CheckoutState       $state;
    private User                $userModel;
    private Endereco            $enderecoModel;

    private CartaoSalvo         $CartaoSalvo;
    private Order               $orderModel;

    private AdminPedidoService $service;

    public function __construct() {
        // parent::__construct();
        $this->cartService   = new Cart();
        $this->state         = new CheckoutState();
        $this->userModel     = new User();
        $this->enderecoModel = new Endereco();
        $this->orderModel    = new Order();

        $this->CartaoSalvo = new CartaoSalvo();
        $this->service = new AdminPedidoService();
    }

    // ════════════════════════════════════════════════════
    // ROUTING
    // ════════════════════════════════════════════════════

    /** GET /checkout — redireciona pra próxima etapa pendente */
    public function index(): void {
        if (!Session::isClienteLogado()) {
            // Guarda origem + retorno na sessão e redireciona pro login
            $this->redirecionarParaLogin('checkout', '/checkout');
        }
        if ($this->cartService->isEmpty()) {
            $this->redirect('/carrinho');
        }
        $this->redirectTo($this->state->proximaEtapaUrl());
    }

    public function getUserData(): array {
        $clienteId = (int)Session::get('cliente_id');
        return $this->userModel->getUserComplete($clienteId);
    }

    // ════════════════════════════════════════════════════
    // ETAPA 1 — IDENTIFICAÇÃO
    // ════════════════════════════════════════════════════

    public function identify(): void {
        if (Session::isClienteLogado()) {
            $this->redirect('/checkout/address');
        }
        $this->renderCheckout('checkout/identify', [
            'etapaAtual' => 'identify',
        ]);
    }

    public function identifyPost(): void {
        $this->verifyCsrf();
        $acao  = $_POST['acao']  ?? 'login';
        $email = SecurityHelper::sanitizeEmail($_POST['email'] ?? '');

        if ($acao === 'login') {
            $this->handleLogin($email, $_POST['senha'] ?? '');
        } elseif ($acao === 'cadastro_rapido') {
            $this->handleCadastroRapido($email, $_POST);
        } elseif ($acao === 'verificar_codigo') {
            $this->handleVerificarCodigo($_POST['codigo'] ?? '');
        } elseif ($acao === 'reenviar_codigo') {
            $this->handleReenviarCodigo();
        } elseif ($acao === 'editar_email') {
            Session::remove('checkout_pending_signup');
            $this->json(['ok' => true]);
        } else {
            $this->json(['ok' => false, 'msg' => 'Ação inválida.']);
        }
    }

    // ════════════════════════════════════════════════════
    // ETAPA 2 — ENDEREÇO
    // ════════════════════════════════════════════════════

    /** GET /checkout/address — mostra endereço principal ou seletor */
    public function address(): void {
        
        if (!Session::isClienteLogado()) {
            $this->redirecionarParaLogin('checkout', '/checkout/endereco');
        }
        $this->requireLogin();

        $clienteId = (int)Session::get('cliente_id');

        $enderecos = $this->enderecoModel->listarPorCliente($clienteId);
        if (empty($enderecos)) {
            $this->redirect('/checkout/address/add');
        }

        // Auto-seleciona se nada estava escolhido
        if (!$this->state->getEnderecoId()) {
            $principal = $this->encontrarPrincipal($enderecos);
            if ($principal) {
                $this->state->setEnderecoId((int)$principal['id']);
            }
        }

        $this->state->setUltimaEtapa('address');

        $this->renderCheckout('checkout/address', [
            'etapaAtual'       => 'address',
            'enderecos'        => $this->hidratarEnderecos($enderecos),
            'enderecoSelecionado' => $this->state->getEnderecoId(),
        ]);
    }

    /** POST /checkout/address/select — AJAX: troca o endereço escolhido */
    public function addressSelect(): void {
        $this->requireLoginAjax();
        $this->verifyCsrf();

        $enderecoId = (int)($_POST['endereco_id'] ?? 0);
        $clienteId  = (int)Session::get('cliente_id');

        $end = $this->enderecoModel->findOwned($enderecoId, $clienteId);
        if (!$end) {
            $this->json(['ok' => false, 'msg' => 'Endereço inválido.']);
        }

        $this->state->setEnderecoId($enderecoId);
        $this->json(['ok' => true, 'next' => BASE_URL . '/checkout/payment']);
    }

    public function addressAdd(): void {
        $this->requireLogin();
        $this->renderCheckout('checkout/address-add', [
            'etapaAtual' => 'address',
            'modo'       => 'novo',
        ]);
    }

    public function addressAddPost(): void {
        $this->requireLoginAjax();
        $this->verifyCsrf();

        $clienteId = (int)Session::get('cliente_id');
        $data      = $this->extractEnderecoFromPost();

        $errors = $this->validarEndereco($data);
        if ($errors) {
            $this->json(['ok' => false, 'errors' => $errors]);
        }

        $id = $this->enderecoModel->salvar(array_merge($data, [
            'cliente_id' => $clienteId,
            'principal'  => (int)($_POST['principal'] ?? 0),
        ]));

        $this->state->setEnderecoId($id);
        $this->json([
            'ok'       => true,
            'redirect' => BASE_URL . '/checkout/payment',
        ]);
    }

    public function addressList(): void {
        $this->requireLogin();
        $clienteId = (int)Session::get('cliente_id');
        $enderecos = $this->enderecoModel->listarPorCliente($clienteId);

        $this->renderCheckout('checkout/address-list', [
            'etapaAtual' => 'address',
            'enderecos'  => $this->hidratarEnderecos($enderecos),
        ]);
    }

    public function addressEdit(string $hash): void {
        $this->requireLogin();
        $id        = IdHasher::decode($hash, 'address');
        $clienteId = (int)Session::get('cliente_id');

        if (!$id) $this->notFound();

        $endereco = $this->enderecoModel->findOwned($id, $clienteId);
        if (!$endereco) $this->notFound();

        $this->renderCheckout('checkout/address-edit', [
            'etapaAtual' => 'address',
            'endereco'   => $endereco,
            'hash'       => $hash,
            'modo'       => 'editar',
        ]);
    }

    public function addressEditPost(string $hash): void {
        $this->requireLoginAjax();
        $this->verifyCsrf();

        $id        = IdHasher::decode($hash, 'address');
        $clienteId = (int)Session::get('cliente_id');

        if (!$id || !$this->enderecoModel->findOwned($id, $clienteId)) {
            $this->json(['ok' => false, 'msg' => 'Endereço não encontrado.']);
        }

        $data   = $this->extractEnderecoFromPost();
        $errors = $this->validarEndereco($data);
        if ($errors) {
            $this->json(['ok' => false, 'errors' => $errors]);
        }

        $this->enderecoModel->atualizar($id, $data);
        $this->json([
            'ok'       => true,
            'redirect' => BASE_URL . '/checkout/address/update',
        ]);
    }

    // ── Buscar CEP ────────────────────────────────────────────

    public function fetchCep(): void {
        $cep = preg_replace('/\D/', '', $_GET['cep'] ?? '');
        if (strlen($cep) !== 8) {
            $this->json(['ok' => false, 'msg' => 'CEP inválido.']);
        }

        $url = "https://viacep.com.br/ws/{$cep}/json/";
        $ctx = stream_context_create(['http' => ['timeout' => 5]]);
        $res = @file_get_contents($url, false, $ctx);

        if (!$res) $this->json(['ok' => false, 'msg' => 'CEP não encontrado.']);

        $data = json_decode($res, true);
        if (!$data || isset($data['erro'])) {
            $this->json(['ok' => false, 'msg' => 'CEP não encontrado.']);
        }

        $this->json([
            'ok'          => true,
            'logradouro'  => $data['logradouro']  ?? '',
            'bairro'      => $data['bairro']      ?? '',
            'cidade'      => $data['localidade']  ?? '',
            'estado'      => $data['uf']          ?? '',
        ]);
    }

    

    // ════════════════════════════════════════════════════
    // ETAPA 3 — PAGAMENTO (com cálculo de frete)
    // ════════════════════════════════════════════════════

    public function payment(): void {
        $this->requireLogin();

        if (!$this->state->podeAcessar('payment')) {
            $this->redirect('/checkout/address');
        }

        $clienteId = (int)Session::get('cliente_id');
        $endereco  = $this->enderecoModel->findOwned($this->state->getEnderecoId(), $clienteId);
        if (!$endereco) {
            $this->state->clear();
            $this->redirect('/checkout/address');            
        }

        $cartoesSalvos =  $this->CartaoSalvo->listarPorCliente($clienteId);

        $this->state->setUltimaEtapa('payment');

        // ── CONVERSÃO: InitiateCheckout (Fase 1) ──────────────
        // Cliente passou por identify+address e entra no pagamento
        // = sinal forte de intenção de compra. Dispara uma vez ao
        // abrir a etapa. À prova de falha.
        try {
            $itensCk   = $this->cartService->getItensComVariacoes($clienteId);
            $subtotalCk = array_sum(array_map(
                fn($i) => (float)($i['preco_unitario'] ?? $i['preco'] ?? 0) * (int)($i['quantidade'] ?? 1),
                $itensCk
            ));
            $contentIdsCk = array_map(fn($i) => (string)($i['produto_id'] ?? ''), $itensCk);
            $numItemsCk   = array_sum(array_map(fn($i) => (int)($i['quantidade'] ?? 1), $itensCk));

            (new ConversionService())->initiateCheckout([
                'total'       => $subtotalCk,
                'num_items'   => $numItemsCk,
                'content_ids' => $contentIdsCk,
            ], $clienteId);
        } catch (\Throwable $e) {
            LogService::error('ConversionService -> initiateCheckout', [$e]);
        }

        $this->renderCheckout('checkout/payment', [
            'metodoAtual' => Session::get('checkout_payment_method', 'cartao'),
            'observacaoAtual' => $this->state->getObservacao(),

            'etapaAtual'    => 'payment',
            'endereco'      => $endereco,
            'cartoesSalvos' => $cartoesSalvos,
            'freteAtual'    => $this->state->getFrete(),
            'cupomAtual'    => $this->state->getCupom(),
            'maxParcelas'   => 12,
        ]);
    }

    /** POST /checkout/payment/shipping — AJAX: lista opções de frete */
    public function calculateShipping(): void {
        $this->requireLoginAjax();
        $this->verifyCsrf();

        $clienteId  = (int)Session::get('cliente_id');
        $enderecoId = $this->state->getEnderecoId();
        $endereco   = $enderecoId
            ? $this->enderecoModel->findOwned($enderecoId, $clienteId)
            : null;

        if (!$endereco) {
            $this->json(['ok' => false, 'msg' => 'Endereço não selecionado.']);
        }

        $itens = $this->cartService->getItensComDimensoes($clienteId);
        if (empty($itens)) {
            $this->json(['ok' => false, 'msg' => 'Carrinho vazio.']);
        }

        $service = new FreteService();
        $result  = $service->calcular(
            (string)$endereco['cep'],
            $itens,
            $this->state->getCarrinhoId() ?? 0
        );

        if (!$result['ok']) {
            $this->json([
                'ok'  => false,
                'msg' => $result['erro'] ?? 'Não foi possível calcular o frete.',
            ]);
        }

        $this->json([
            'ok'     => true,
            'opcoes' => $result['opcoes'],
            'cep'    => $endereco['cep'],
        ]);
    }

    /** POST /checkout/payment/shipping/save — AJAX: salva escolha de frete */
    public function saveShipping(): void {
        $this->requireLoginAjax();
        $this->verifyCsrf();

        $codigo    = trim((string)($_POST['frete_codigo'] ?? ''));
        $descricao = trim((string)($_POST['frete_descricao'] ?? ''));
        $valor     = (float)($_POST['frete_valor'] ?? 0);
        $prazo     = (int)($_POST['frete_prazo'] ?? 0);
        $carrier   = trim((string)($_POST['frete_carrier'] ?? ''));
        $poster    = trim((string)($_POST['frete_poster'] ?? ''));
        $tag       = trim((string)($_POST['frete_tag'] ?? ''));

        if (empty($codigo) || empty($descricao)) {
            $this->json(['ok' => false, 'msg' => 'Frete inválido.']);
        }

        $this->state->setFrete([
            'codigo'    => $codigo,
            'descricao' => $descricao,
            'valor'     => $valor,
            'prazo'     => $prazo,
            'carrier'   => $carrier,
            'poster'    => $poster,
            'tag'       => $tag,
        ]);

        
        // em saveShipping(), adicionar ao final antes do json(['ok' => true]):
        if (!empty($_POST['forma_pagamento'])) {
            Session::set('checkout_payment_method', $_POST['forma_pagamento']);
        }
        if (isset($_POST['observacao'])) {
            $this->state->setObservacao($_POST['observacao']);
        }
        $this->json(['ok' => true, 'frete_ok' => true]);
    }

    /** POST /checkout/payment/coupon — AJAX: aplica cupom */
    public function applyCoupon(): void {
        $this->requireLoginAjax();
        $this->verifyCsrf();

        $codigo = mb_strtoupper(trim((string)($_POST['cupom'] ?? '')));
        if (empty($codigo)) {
            $this->state->removerCupom();
            $this->json(['ok' => true, 'desconto' => 0, 'msg' => 'Cupom removido.']);
        }

        // Implementar validação real do cupom no CartService/CouponService
        $cupom = method_exists($this->cartService, 'validarCupom')
            ? $this->cartService->validarCupom($codigo)
            : null;

        if (!$cupom || !$cupom['valido']) {
            $this->json(['ok' => false, 'msg' => 'Cupom inválido ou expirado.']);
        }

        $this->state->setCupom($codigo, (float)$cupom['desconto'], (string)($cupom['tipo'] ?? 'fixo'));
        $this->json([
            'ok'       => true,
            'desconto' => $cupom['desconto'],
            'msg'      => 'Cupom aplicado com sucesso.',
        ]);
    }

    // ════════════════════════════════════════════════════
    // ETAPA 4 — RESUMO
    // ════════════════════════════════════════════════════

    public function summary(): void {
        $this->requireLogin();

        if (!$this->state->podeAcessar('summary')) {
            $this->redirectTo($this->state->proximaEtapaUrl());
        }

        $clienteId = (int)Session::get('cliente_id');
        $endereco  = $this->enderecoModel->findOwned($this->state->getEnderecoId(), $clienteId);
        $itens     = $this->cartService->getItensComVariacoes($clienteId);

        $subtotal  = array_sum(array_map(
            fn($i) => (float)$i['valor_unitario'] * (int)$i['quantidade'],
            $itens
        ));

        $frete    = $this->state->getFrete();
        $cupom    = $this->state->getCupom();
        $desconto = $this->calcularDesconto($cupom, $subtotal);
        $total    = max(0, $subtotal - $desconto + (float)($frete['valor'] ?? 0));

        $this->state->setUltimaEtapa('summary');

        $user_data = $this->userModel->getUserComplete($clienteId);

        $this->renderCheckout('checkout/summary', [
            'etapaAtual' => 'summary',
            'endereco'   => $endereco,
            'frete'      => $frete,
            'cupom'      => $cupom,
            'itens'      => $itens,
            'subtotal'   => $subtotal,
            'desconto'   => $desconto,
            'total'      => $total,
            'observacao' => $this->state->getObservacao(),
            'saldo_disponivel' => (new CreditoService())->getSaldoDisponivel($user_data['usuario_id']),
            'testeID'=>$user_data['id']
        ]);
    }

    // ── POST /checkout/aplicar-credito ───────────────────────
    // public function aplicarCredito(): void {
    //     $this->requireLogin();
    //     $clienteId = (int)Session::get('cliente_id');
    //     $valor     = (float)($_POST['valor'] ?? 0);

    //     $u_data = $this->userModel->getUserComplete($clienteId);
    
    //     // Valida saldo
    //     $credito = new CreditoService();
    //     $saldo   = $credito->getSaldoDisponivel($u_data['usuario_id']);
    
    //     if ($valor <= 0 || $valor > $saldo) {
    //         $this->json(['ok' => false, 'msg' => 'Valor inválido ou saldo insuficiente.']);
    //     }
    
    //     // Salva na sessão do checkout
    //     Session::set('checkout_credito', round($valor, 2));
    //     $this->json(['ok' => true, 'valor' => $valor]);
    // }

    public function aplicarCredito(): void {
        $this->requireLogin();
    
        $valor    = (float)str_replace(',', '.', $_POST['valor'] ?? '0');
        
        $clienteId = (int)Session::get('cliente_id');
        $u_data = $this->userModel->getUserComplete($clienteId);

        $credito  = new CreditoService();
        $cart     = $this->cartService->getOrCreate();
        $totals   = $this->cartService->getTotals((int)$cart['id']);
    
        $totalOriginal = (float)$totals['total'];
        $saldo         = $credito->getSaldoDisponivel($u_data['usuario_id']);
        $maxCredito    = round(min($saldo, $totalOriginal), 2);
    
        // Validações
        if ($valor <= 0) {
            $this->json(['ok' => false, 'msg' => 'Informe um valor maior que zero.']);
        }
        if ($valor > $maxCredito) {
            $valor = $maxCredito; // arredonda para o máximo silenciosamente
        }
        if (!$credito->validarReserva($u_data['usuario_id'], $valor)) {
            $this->json(['ok' => false, 'msg' => 'Saldo insuficiente.']);
        }
    
        // Salva na sessão
        $valor = round($valor, 2);
        Session::set('checkout_credito', $valor);
    
        // Calcula novos totais
        $novoTotal = round(max(0, $totalOriginal - $valor), 2);
    
        $this->json([
            'ok'     => true,
            'credito'=> $valor,
            'teste'=> $totals,
            'totais' => [
                'subtotal_fmt'  => $totals['subtotal_fmt'],
                'frete_fmt'     => $totals['frete_fmt'],
                'desconto'      => (float)($totals['desconto'] ?? 0),
                'desconto_fmt'  => $totals['desconto_fmt'] ?? '',
                'credito_fmt'   => '−' . PriceHelper::format($valor),
                'total'         => $novoTotal,
                'total_fmt'     => PriceHelper::format($novoTotal),
                'total_original'=> $totalOriginal,
                'cobertura_total'=> $novoTotal <= 0, // crédito cobre tudo
            ],
        ]);
    }
    
    // ── POST /checkout/remover-credito ────────────────────────
    // public function removerCredito(): void {
    //     Session::remove('checkout_credito');
    //     $this->json(['ok' => true]);
    // }

    public function removerCredito(): void {
        $this->requireLogin();
    
        Session::remove('checkout_credito');
    
        $clienteId = (int)Session::get('cliente_id');
        $u_data = $this->userModel->getUserComplete($clienteId);
        
        $cart      = $this->cartService->getOrCreate($u_data['usuario_id']);
        $totals    = $this->cartService->getTotals((int)$cart['id']);
    
        $this->json([
            'ok'    => true,
            'totais'=> [
                'subtotal_fmt' => $totals['subtotal_fmt'],
                'frete_fmt'    => $totals['frete_fmt'],
                'desconto'     => (float)($totals['desconto'] ?? 0),
                'desconto_fmt' => $totals['desconto_fmt'] ?? '',
                'total'        => (float)$totals['total'],
                'total_fmt'    => $totals['total_fmt'],
            ],
        ]);
    }

    // ════════════════════════════════════════════════════
    // FINALIZAR
    // ════════════════════════════════════════════════════

    // public function finalize(): void {
    //     $this->requireLoginAjax();
    //     $this->verifyCsrf();
 
    //     if (!$this->state->podeAcessar('summary')) {
    //         $this->json(['ok' => false, 'msg' => 'Checkout incompleto.']);
    //     }
 
    //     $clienteId    = (int)Session::get('cliente_id');
    //     $salvarCartao = !empty($_POST['salvar_cartao']);
    //     $metodo       = Session::get('checkout_payment_method', 'cartao');
 
    //     // ── Resolve cartão ─────────────────────────────────
    //     $cartaoSalvoId = null;
    //     $cartaoTemp    = null;
 
    //     if ($metodo === 'cartao') {
    //         $cardId = Session::get('checkout_card_id');
 
    //         if ($cardId && $cardId !== 'novo') {
    //             // Cartão já persistido — usa direto
    //             $cartaoSalvoId = (int)$cardId;
    //         } else {
    //             // Cartão temporário em sessão
    //             $cartaoTemp = Session::get('checkout_card_temp');
 
    //             if (!$cartaoTemp || time() > (int)($cartaoTemp['expires_at'] ?? 0)) {
    //                 Session::remove('checkout_card_temp');
    //                 $this->json(['ok' => false, 'msg' => 'Sessão de pagamento expirada. Selecione o cartão novamente.']);
    //             }
 
    //             if ($salvarCartao) {
    //                 // Persiste agora
    //                 try {
    //                     $db = Database::getInstance()->getConnection();
 
    //                     if (!empty($cartaoTemp['padrao'] ?? false)) {
    //                         $db->prepare(
    //                             "UPDATE cartoes_salvos SET padrao = 0 WHERE cliente_id = ?"
    //                         )->execute([$clienteId]);
    //                     }
 
    //                     $db->prepare(
    //                         "INSERT INTO cartoes_salvos
    //                          (cliente_id, bandeira, ultimos_4, nome_titular, apelido,
    //                           validade, gateway_token, padrao, criado_em)
    //                          VALUES (?,?,?,?,?,?,?,?,NOW())"
    //                     )->execute([
    //                         $clienteId,
    //                         $cartaoTemp['bandeira']     ?? '',
    //                         $cartaoTemp['ultimos_4']    ?? '',
    //                         $cartaoTemp['nome_titular'] ?? '',
    //                         $cartaoTemp['apelido']      ?? null,
    //                         $cartaoTemp['validade']     ?? '',
    //                         $cartaoTemp['gateway_token'] ?? null,
    //                         0,
    //                     ]);
 
    //                     $cartaoSalvoId = (int)$db->lastInsertId();
    //                 } catch (\PDOException $e) {
    //                     error_log('[finalize] Erro ao salvar cartão: ' . $e->getMessage());
    //                     // Continua mesmo sem salvar o cartão
    //                 }
    //             }
    //             // Se não salvar: usa os dados do temp para processar e descarta depois
    //         }
    //     }
 
    //     // ── Monta payload para process() ───────────────────
    //     $_POST['endereco_entrega_id']  = $this->state->getEnderecoId();
    //     $_POST['endereco_cobranca_id'] = $this->state->getEnderecoId();
    //     $_POST['forma_pagamento']      = $metodo;
    //     $_POST['frete']                = $this->state->getFrete();
    //     $_POST['cupom']                = $this->state->getCupom();
    //     $_POST['observacao']           = $this->state->getObservacao();
 
    //     if ($cartaoSalvoId) {
    //         $_POST['cartao_salvo_id'] = $cartaoSalvoId;
    //     } elseif ($cartaoTemp) {
    //         $_POST['cartao_dados'] = $cartaoTemp;
    //     }
 
    //     // ── Processa o pedido ───────────────────────────────
    //     if (method_exists($this, 'process')) {
    //         $this->process();
    //         // process() vai redirecionar para success — o código abaixo só roda se houver erro
    //         return;
    //     }
 
    //     $this->json(['ok' => false, 'msg' => 'Processador não disponível.']);
    // }


    // ════════════════════════════════════════════════════════
    // CheckoutController — finalize() + process() + debugState()
    //
    // Para ativar o modo fake de testes, adicionar em config.php:
    //   define('CHECKOUT_FAKE_MODE', true);
    // ════════════════════════════════════════════════════════

    // ── finalize() ───────────────────────────────────────────
    // public function finalize() {
    //     $this->requireLoginAjax();
    //     $this->verifyCsrf();

    //     if (!$this->state->podeAcessar('summary')) {
    //         $this->json(['ok' => false, 'msg' => 'Checkout incompleto. Volte ao início.']);
    //     }

    //     $clienteId    = (int)Session::get('cliente_id');
    //     $salvarCartao = !empty($_POST['salvar_cartao']);
    //     $metodo       = Session::get('checkout_payment_method', 'cartao');
    //     $parcelas     = max(1, (int)($_POST['parcelas'] ?? 1));

    //     $cartaoSalvoId = null;
    //     $cartaoTemp    = null;

    //     if ($metodo === 'cartao') {
    //         $cardId = Session::get('checkout_card_id');

    //         // Tenta cartão salvo
    //         if ($cardId && $cardId !== 'novo') {
    //             $db   = Database::getInstance()->getConnection();
    //             $stmt = $db->prepare(
    //                 "SELECT id FROM cartoes_salvos WHERE id = ? AND cliente_id = ? AND ativo = 1 LIMIT 1"
    //             );
    //             $stmt->execute([(int)$cardId, $clienteId]);
    //             if ($stmt->fetchColumn()) {
    //                 $cartaoSalvoId = (int)$cardId;
    //             }
    //         }

    //         // Tenta temporário
    //         if (!$cartaoSalvoId) {
    //             $cartaoTemp = Session::get('checkout_card_temp');
    //             if ($cartaoTemp && time() > (int)($cartaoTemp['expires_at'] ?? 0)) {
    //                 Session::remove('checkout_card_temp');
    //                 $cartaoTemp = null;
    //             }
    //         }

    //         // Fallback: auto-seleciona cartão padrão do cliente
    //         if (!$cartaoSalvoId && !$cartaoTemp) {
    //             $db   = Database::getInstance()->getConnection();
    //             $stmt = $db->prepare(
    //                 "SELECT id FROM cartoes_salvos
    //                 WHERE cliente_id = ? AND ativo = 1
    //                 ORDER BY principal DESC, criado_em DESC LIMIT 1"
    //             );
    //             $stmt->execute([$clienteId]);
    //             $fallbackId = $stmt->fetchColumn();

    //             if ($fallbackId) {
    //                 $cartaoSalvoId = (int)$fallbackId;
    //                 Session::set('checkout_card_id', $cartaoSalvoId);
    //             } else {
    //                 $this->json([
    //                     'ok'  => false,
    //                     'msg' => 'Nenhum cartão selecionado. Volte ao pagamento e adicione um cartão.',
    //                 ]);
    //             }
    //         }

    //         // Persiste temp se solicitado
    //         if ($cartaoTemp && $salvarCartao) {
    //             try {
    //                 $db = Database::getInstance()->getConnection();
    //                 if (!empty($cartaoTemp['padrao'])) {
    //                     $db->prepare("UPDATE cartoes_salvos SET padrao = 0 WHERE cliente_id = ?")
    //                     ->execute([$clienteId]);
    //                 }
    //                 $db->prepare(
    //                     "INSERT INTO cartoes_salvos
    //                     (cliente_id, bandeira, ultimos_4, nome_titular, apelido,
    //                     validade, gateway_token, padrao, criado_em)
    //                     VALUES (?,?,?,?,?,?,?,?,NOW())"
    //                 )->execute([
    //                     $clienteId,
    //                     $cartaoTemp['bandeira']      ?? '',
    //                     $cartaoTemp['ultimos_4']     ?? '',
    //                     $cartaoTemp['nome_titular']  ?? '',
    //                     $cartaoTemp['apelido']       ?? null,
    //                     $cartaoTemp['validade']      ?? '',
    //                     $cartaoTemp['gateway_token'] ?? null,
    //                     0,
    //                 ]);
    //                 $cartaoSalvoId = (int)$db->lastInsertId();
    //                 Session::remove('checkout_card_temp');
    //             } catch (\PDOException $e) {
    //                 error_log('[finalize] Erro ao salvar cartão: ' . $e->getMessage());
    //             }
    //         }
    //     }

    //     // Monta dados para process()
    //     $_POST['cliente_id']          = $clienteId;
    //     $_POST['endereco_entrega_id'] = $this->state->getEnderecoId();
    //     $_POST['forma_pagamento']     = $metodo;
    //     $_POST['parcelas']            = $parcelas;
    //     $_POST['frete']               = $this->state->getFrete();
    //     $_POST['cupom']               = $this->state->getCupom();
    //     $_POST['observacao']          = $this->state->getObservacao();
    //     $_POST['salvar_cartao']       = $salvarCartao ? 1 : 0;

    //     if ($cartaoSalvoId) {
    //         $_POST['cartao_salvo_id'] = $cartaoSalvoId;
    //     } elseif ($cartaoTemp) {
    //         $_POST['cartao_dados'] = $cartaoTemp;
    //     }

    //     $this->process();

    //     // echo $metodo;
    // }

    public function finalize(): void {
        $this->requireLoginAjax();
        $this->verifyCsrf();
    
        if (!$this->state->podeAcessar('summary')) {
            $this->json(['ok' => false, 'msg' => 'Checkout incompleto. Volte ao início.']);
        }
    
        $clienteId    = (int)Session::get('cliente_id');
        $salvarCartao = !empty($_POST['salvar_cartao']);
        $metodo       = Session::get('checkout_payment_method', 'cartao');
        $parcelas     = max(1, (int)($_POST['parcelas'] ?? 1));
    
        $cartaoSalvoId = null;
        $cartaoTemp    = null;
    
        if ($metodo === 'cartao') {
            // FIX 1: aceita tanto o nome novo ('checkout_cartao_id', gravado
            // pelo paymentCardAddPost após a Etapa 2) quanto o nome legado
            // ('checkout_card_id') para não quebrar sessões em andamento.
            $cardId = Session::get('checkout_cartao_id')
                ?? Session::get('checkout_card_id');
    
            // Tenta cartão salvo no banco
            if ($cardId && $cardId !== 'novo') {
                $db   = Database::getInstance()->getConnection();
                $stmt = $db->prepare(
                    "SELECT id FROM cartoes_salvos WHERE id = ? AND cliente_id = ? AND ativo = 1 LIMIT 1"
                );
                $stmt->execute([(int)$cardId, $clienteId]);
                if ($stmt->fetchColumn()) {
                    $cartaoSalvoId = (int)$cardId;
                }
            }
    
            // Tenta temporário em sessão (cartão adicionado nesta compra)
            if (!$cartaoSalvoId) {
                $cartaoTemp = Session::get('checkout_card_temp');
                if ($cartaoTemp && time() > (int)($cartaoTemp['expires_at'] ?? 0)) {
                    Session::remove('checkout_card_temp');
                    $cartaoTemp = null;
                }
            }
    
            // Fallback: auto-seleciona o cartão principal do cliente
            if (!$cartaoSalvoId && !$cartaoTemp) {
                $db   = Database::getInstance()->getConnection();
                $stmt = $db->prepare(
                    "SELECT id FROM cartoes_salvos
                    WHERE cliente_id = ? AND ativo = 1
                    ORDER BY principal DESC, criado_em DESC LIMIT 1"
                );
                $stmt->execute([$clienteId]);
                $fallbackId = $stmt->fetchColumn();
    
                if ($fallbackId) {
                    $cartaoSalvoId = (int)$fallbackId;
                    Session::set('checkout_cartao_id', $cartaoSalvoId);
                } else {
                    $this->json([
                        'ok'  => false,
                        'msg' => 'Nenhum cartão selecionado. Volte ao pagamento e adicione um cartão.',
                    ]);
                }
            }
    
            // Persiste temp se solicitado
            if ($cartaoTemp && $salvarCartao) {
                try {
                    $db = Database::getInstance()->getConnection();
                    if (!empty($cartaoTemp['principal'])) {
                        $db->prepare("UPDATE cartoes_salvos SET principal = 0 WHERE cliente_id = ?")
                        ->execute([$clienteId]);
                    }
                    $db->prepare(
                        "INSERT INTO cartoes_salvos
                        (cliente_id, bandeira, ultimos_4, nome_titular, apelido,
                        validade, token, principal, criado_em)
                        VALUES (?,?,?,?,?,?,?,?,NOW())"
                    )->execute([
                        $clienteId,
                        $cartaoTemp['bandeira']      ?? '',
                        $cartaoTemp['ultimos_4']     ?? '',
                        $cartaoTemp['nome_titular']  ?? 'Titular',
                        $cartaoTemp['apelido']       ?? null,
                        $cartaoTemp['validade']      ?? '',
                        $cartaoTemp['gateway_token'] ?? $cartaoTemp['token'] ?? null,
                        0,
                    ]);
                    $cartaoSalvoId = (int)$db->lastInsertId();
                    Session::remove('checkout_card_temp');
                } catch (\PDOException $e) {
                    error_log('[finalize] Erro ao salvar cartão: ' . $e->getMessage());
                    // Continua — não impede a finalização
                }
            }
        }
    
        // Monta dados para process()
        $_POST['cliente_id']          = $clienteId;
        $_POST['endereco_entrega_id'] = $this->state->getEnderecoId();
        $_POST['forma_pagamento']     = $metodo;
        $_POST['parcelas']            = $parcelas;
        $_POST['frete']               = $this->state->getFrete();
        // $_POST['cupom'] removido: process() lê diretamente de
        // Session::get('cupom_aplicado') onde CouponController::aplicar()
        // salva o dado completo (incluindo cupom_id já resolvido).
        $_POST['observacao']          = $this->state->getObservacao();
        $_POST['salvar_cartao']       = $salvarCartao ? 1 : 0;
    
        if ($cartaoSalvoId) {
            $_POST['cartao_salvo_id'] = $cartaoSalvoId;
        } elseif ($cartaoTemp) {
            $_POST['cartao_dados'] = $cartaoTemp;
        }
    
        $this->process();
    }


    // ── process() ────────────────────────────────────────────
    // private function process(): void {

    //     $db        = Database::getInstance()->getConnection();
    //     $clienteId = (int)$_POST['cliente_id'];
    //     $metodo    = (string)($_POST['forma_pagamento'] ?? 'cartao');
    //     $parcelas  = (int)($_POST['parcelas']           ?? 1);
    //     $freteData = $_POST['frete']    ?? null;
    //     $cupomData = $_POST['cupom']    ?? null;
    //     $observacao= (string)($_POST['observacao']      ?? '');
    //     $cartaoSalvoId = $_POST['cartao_salvo_id'] ?? null;
    //     $cartaoTemp    = $_POST['cartao_dados']    ?? null;
    //     $fakeMode  = defined('CHECKOUT_FAKE_MODE') && CHECKOUT_FAKE_MODE;

    //     // 1. Valida carrinho
    //     $cart  = new Cart();
    //     $itens = $cart->getItensComVariacoes($clienteId);
    //     if (empty($itens)) {
    //         $this->json(['ok' => false, 'msg' => 'Seu carrinho está vazio.']);
    //     }

    //     // 2. Valida endereço
    //     $enderecoId    = (int)($_POST['endereco_entrega_id'] ?? 0);
    //     $enderecoModel = new Endereco();
    //     $endereco      = $enderecoModel->findOwned($enderecoId, $clienteId);
    //     if (!$endereco) {
    //         $this->json(['ok' => false, 'msg' => 'Endereço de entrega inválido.']);
    //     }

    //     // 3. Recalcula totais server-side
    //     $subtotal = (float)array_sum(array_map(
    //         fn($i) => (float)$i['valor_unitario'] * (int)$i['quantidade'],
    //         $itens
    //     ));
    //     $freteValor  = (float)($freteData['valor'] ?? 0);
    //     $desconto    = 0.0;
    //     $freteDesc   = 0.0;
    //     $cupomUsoId  = null;
    //     $cupomId     = null;

    //     // 4. Revalida cupom
    //     if (!empty($cupomData['codigo'])) {
    //         $couponService = new CouponService();
    //         $itensCupom    = $cart->getItensParaCupom($clienteId);
    //         $resultCupom   = $couponService->validar(
    //             $cupomData['codigo'], $itensCupom, $subtotal, $freteValor,
    //             $clienteId, ['origem' => 'finalize']
    //         );
    //         if ($resultCupom['ok']) {
    //             $desconto  = $resultCupom['desconto'];
    //             $freteDesc = $resultCupom['frete_desconto'];
    //         }
    //         $cupomId = $this->buscarCupomId($db, $cupomData['codigo']);
    //     }

    //     $freteValorFinal = max(0, $freteValor - $freteDesc);
    //     $total           = max(0, round($subtotal - $desconto + $freteValorFinal, 2));
    //     $codigo          = strtoupper(substr(md5(uniqid((string)$clienteId, true)), 0, 8));

    //     $cartaoBandeira  = null;
    //     $cartaoUltimos4  = null;
        
    //     if ($metodo === 'cartao') {
    //         if ($cartaoSalvoId) {
    //             // Busca do banco
    //             $stmtCard = $db->prepare(
    //                 "SELECT bandeira, ultimos_4 FROM cartoes_salvos WHERE id = ? LIMIT 1"
    //             );
    //             $stmtCard->execute([(int)$cartaoSalvoId]);
    //             $cardRow = $stmtCard->fetch();
    //             $cartaoBandeira  = $cardRow['bandeira']   ?? null;
    //             $cartaoUltimos4  = $cardRow['ultimos_4']  ?? null;
    //         } elseif ($cartaoTemp) {
    //             // Dados do cartão temporário em sessão
    //             $cartaoBandeira  = $cartaoTemp['bandeira']  ?? null;
    //             $cartaoUltimos4  = $cartaoTemp['ultimos_4'] ?? null;
    //         }
    //     }

    //     // 5. Transação
    //     $db->beginTransaction();
    //     $data_debug = false;
    //     try {
    //         // Cria pedido
    //         $db->prepare(
    //             "INSERT INTO pedidos
    //             (cliente_id, codigo, status_pedido, status_pagamento,
    //             forma_pagamento, parcelas, subtotal, desconto, frete, total,
    //             endereco_entrega_id, frete_descricao, frete_prazo, frete_codigo,
    //             observacao_cliente, cartao_bandeira, cartao_ultimos_4, criado_em)
    //             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())"
    //         )->execute([
    //             $clienteId,
    //             $codigo,
    //             // status_pedido inicial baseado no modo e método  
    //             $fakeMode ? 'pagamento_aprovado' : 'aguardando_pagamento',
    //             // status_pagamento inicial
    //             $fakeMode ? 'aprovado' : 'pendente',
    //             $metodo,
    //             $parcelas,
    //             $subtotal,
    //             $desconto,
    //             $freteValorFinal,
    //             $total,
    //             $enderecoId,
    //             $freteData['descricao'] ?? null,
    //             $freteData['prazo']     ?? $freteData['frete_prazo'] ?? null,
    //             $freteData['codigo']    ?? null,
    //             $observacao,
    //             $cartaoBandeira,   // ← novo
    //             $cartaoUltimos4,   // ← novo
    //         ]);
    //         $pedidoId = (int)$db->lastInsertId();

    //         $this->orderModel->registrarMudancaStatus(
    //             $pedidoId, 
    //             ($fakeMode ? 'pagamento_aprovado' : 'aguardando_pagamento'), 
    //             'Pedido criado no checkout.'
    //         );

    //         // Cria itens
    //         $stmtItem = $db->prepare(
    //             "INSERT INTO pedido_itens
    //             (pedido_id, nome_produto, subtotal, produto_id, sku, quantidade,
    //             preco_unitario, valor_original, desconto_cupom, valor_final_item, cupom_id)
    //             VALUES (?,?,?,?,?,?,?,?,?,?,?)"
    //         );

    //         $data_debug = $itens;

    //         foreach ($itens as $item) {
    //             $valorUnit  = (float)$item['valor_unitario'];
    //             $qtd        = (int)$item['quantidade'];
    //             $valorTotal = $valorUnit * $qtd;
    //             $descItem   = ($desconto > 0 && $subtotal > 0)
    //                 ? round($desconto * ($valorTotal / $subtotal), 2)
    //                 : 0.0;

    //             $stmtItem->execute([
    //                 $pedidoId, 
    //                 $item['nome'],
    //                 $subtotal,
    //                 (int)$item['produto_id'],
    //                 $item['sku_id'] ? (int)$item['sku_id'] : null,
    //                 $qtd, $valorUnit, $valorUnit,
    //                 $descItem, $valorTotal - $descItem, $cupomId,
    //             ]);

    //             // Reserva estoque
    //             if (!empty($item['sku_id'])) {
    //                 $db->prepare(
    //                     "UPDATE produto_skus
    //                     SET estoque = GREATEST(0, estoque - ?)
    //                     WHERE id = ? AND estoque >= ?"
    //                 )->execute([$qtd, (int)$item['sku_id'], $qtd]);
    //             }
    //         }

    //         // Reserva cupom dentro da transação
    //         if ($cupomId && $desconto > 0) {
    //             $couponService = new CouponService();
    //             $cupomUsoId = $couponService->reservar(
    //                 $cupomId, $pedidoId, $clienteId,
    //                 $desconto, $freteDesc, $subtotal, $itens
    //             );
    //         }

    //         $db->commit();

    //     } catch (\Throwable $e) {
    //         $db->rollBack();
    //         error_log('[process] ' . $e->getMessage());
    //         $this->json(['debug' => $data_debug, 'ok' => false, 'msg' => 'Erro interno ao criar pedido. Tente novamente. = (line = '.$e->getLine().') > '.$e->getMessage().' - file:'.$e->getFile()]);
    //     }

    //     // 6. Gateway de pagamento
    //     $statusPagamento = 'pendente';
    //     $pixQrCode = $boletoUrl = $gatewayId = null;
    //     $gatewayResposta = null;

    //     if ($fakeMode) {
    //         $statusPagamento = 'aprovado';
    //         $gatewayId       = 'FAKE-' . $codigo;
    //         $gatewayResposta = json_encode(['fake' => true, 'pedido' => $codigo]);

    //     } else {
    //         /*
    //         |──────────────────────────────────────────────────
    //         | INTEGRE SEU GATEWAY AQUI
    //         | Descomente e adapte conforme seu provedor:
    //         |──────────────────────────────────────────────────
    //         try {
    //             $gw   = new GatewayService();
    //             $resp = $gw->cobrar([
    //                 'valor'    => $total,
    //                 'parcelas' => $parcelas,
    //                 'metodo'   => $metodo,
    //                 'token'    => $cartaoSalvoId
    //                     ? $this->buscarTokenCartao($db, (int)$cartaoSalvoId)
    //                     : ($cartaoTemp['gateway_token'] ?? null),
    //                 'pedido'   => $codigo,
    //                 'cliente'  => $clienteId,
    //             ]);
    //             $statusPagamento = $resp['status'];        // 'aprovado'|'pendente'|'recusado'
    //             $pixQrCode       = $resp['pix_qr']   ?? null;
    //             $boletoUrl       = $resp['boleto_url'] ?? null;
    //             $gatewayId       = $resp['id']         ?? null;
    //             $gatewayResposta = json_encode($resp);
    //         } catch (\Throwable $e) {
    //             error_log('[process] Gateway: ' . $e->getMessage());
    //             $this->json(['ok' => false, 'msg' => 'Falha no processamento do pagamento.']);
    //         }
    //         */
    //     }

    //     // Mapeia resultado do gateway para status_pedido
    //     $statusPedidoMap = [
    //         'aprovado'  => 'pagamento_aprovado',
    //         'pendente'  => 'aguardando_pagamento',
    //         'aguardando'=> 'aguardando_pagamento',
    //         'recusado'  => 'aguardando_pagamento',  // pedido fica aberto para nova tentativa
    //     ];

    //     $this->orderModel->registrarMudancaStatus(
    //         $pedidoId, 
    //         $statusPagamento === 'aprovado' ? 'pagamento_aprovado' : 'aguardando_pagamento', 
    //         'Status do pagamento atualizado: ' . $statusPagamento
    //     );

    //     $statusPedidoFinal = $statusPedidoMap[$statusPagamento] ?? 'aguardando_pagamento';
    //     $pagoEm = ($statusPagamento === 'aprovado') ? date('Y-m-d H:i:s') : null;
        
    //     $db->prepare(
    //         "UPDATE pedidos
    //         SET status_pagamento = ?,
    //             status_pedido    = ?,
    //             pago_em          = ?,
    //             gateway_id       = ?,
    //             gateway_resposta = ?,
    //             pix_qr_code      = ?,
    //             boleto_url       = ?
    //         WHERE id = ?"
    //     )->execute([
    //         $statusPagamento,
    //         $statusPedidoFinal,
    //         $pagoEm,
    //         $gatewayId,
    //         $gatewayResposta,
    //         $pixQrCode,
    //         $boletoUrl,
    //         $pedidoId,
    //     ]);

    //     // 8. Confirma cupom se aprovado
    //     if ($cupomUsoId && $statusPagamento === 'aprovado') {
    //         try { (new CouponService())->confirmar($cupomUsoId); }
    //         catch (\Throwable $e) { error_log('[process] Cupom confirm: ' . $e->getMessage()); }
    //     }

    //     // 9. Limpa sessão
    //     $this->state->clear();
    //     Session::remove('checkout_card_temp');
    //     Session::remove('checkout_card_id');
    //     Session::remove('checkout_payment_method');
    //     Session::remove('cupom_aplicado');
    //     $cart->clear($clienteId);
    //     $this->cartService->finalizaCart($clienteId);

    //     // 10. Responde
    //     $this->json([
    //         'ok'       => true,
    //         'redirect' => BASE_URL . '/checkout/success/' . $codigo,
    //         'pedido'   => [
    //             'id'         => $pedidoId,
    //             'codigo'     => $codigo,
    //             'status'     => $statusPagamento,
    //             'pix_qr'     => $pixQrCode,
    //             'boleto_url' => $boletoUrl,
    //         ],
    //     ]);
    // }

    // private function process(): void {

    //     $db        = Database::getInstance()->getConnection();
    //     $clienteId = (int)$_POST['cliente_id'];
    //     $metodo    = (string)($_POST['forma_pagamento'] ?? 'cartao');
    //     $parcelas  = (int)($_POST['parcelas']           ?? 1);
    //     $freteData = $_POST['frete']    ?? null;
    //     $cupomData = $_POST['cupom']    ?? null;
    //     $observacao= (string)($_POST['observacao']      ?? '');
    //     $cartaoSalvoId = $_POST['cartao_salvo_id'] ?? null;
    //     $cartaoTemp    = $_POST['cartao_dados']    ?? null;

    //     $u_data = $this->userModel->getUserComplete($clienteId);

    //     // 1. Valida carrinho
    //     $cart  = new Cart();
    //     $itens = $cart->getItensComVariacoes($clienteId);
    //     if (empty($itens)) {
    //         $this->json(['ok' => false, 'msg' => 'Seu carrinho está vazio.']);
    //     }

    //     // 2. Valida endereço
    //     $enderecoId    = (int)($_POST['endereco_entrega_id'] ?? 0);
    //     $enderecoModel = new Endereco();
    //     $endereco      = $enderecoModel->findOwned($enderecoId, $clienteId);
    //     if (!$endereco) {
    //         $this->json(['ok' => false, 'msg' => 'Endereço de entrega inválido.']);
    //     }

    //     // 3. Recalcula totais server-side
    //     $subtotal = (float)array_sum(array_map(
    //         fn($i) => (float)$i['valor_unitario'] * (int)$i['quantidade'],
    //         $itens
    //     ));
    //     $freteValor  = (float)($freteData['valor'] ?? 0);
    //     $desconto    = 0.0;
    //     $freteDesc   = 0.0;
    //     $cupomUsoId  = null;
    //     $cupomId     = null;

    //     // 4. Revalida cupom
    //     if (!empty($cupomData['codigo'])) {
    //         $couponService = new CouponService();
    //         $itensCupom    = $cart->getItensParaCupom($clienteId);
    //         $resultCupom   = $couponService->validar(
    //             $cupomData['codigo'], $itensCupom, $subtotal, $freteValor,
    //             $clienteId, ['origem' => 'finalize']
    //         );
    //         if ($resultCupom['ok']) {
    //             $desconto  = $resultCupom['desconto'];
    //             $freteDesc = $resultCupom['frete_desconto'];
    //         }
    //         $cupomId = $this->buscarCupomId($db, $cupomData['codigo']);
    //     }

    //     $freteValorFinal = max(0, $freteValor - $freteDesc);
    //     $total           = max(0, round($subtotal - $desconto + $freteValorFinal, 2));
    //     $codigo          = strtoupper(substr(md5(uniqid((string)$clienteId, true)), 0, 8));

    //     $cartaoBandeira  = null;
    //     $cartaoUltimos4  = null;

    //     if ($metodo === 'cartao') {
    //         if ($cartaoSalvoId) {
    //             $stmtCard = $db->prepare(
    //                 "SELECT bandeira, ultimos_4 FROM cartoes_salvos WHERE id = ? LIMIT 1"
    //             );
    //             $stmtCard->execute([(int)$cartaoSalvoId]);
    //             $cardRow = $stmtCard->fetch();
    //             $cartaoBandeira  = $cardRow['bandeira']   ?? null;
    //             $cartaoUltimos4  = $cardRow['ultimos_4']  ?? null;
    //         } elseif ($cartaoTemp) {
    //             $cartaoBandeira  = $cartaoTemp['bandeira']  ?? null;
    //             $cartaoUltimos4  = $cartaoTemp['ultimos_4'] ?? null;
    //         }
    //     }

        
    //     // $paymentSvc = new PaymentService();
        
    //     // $resultado = $paymentSvc->processarPagamento([
    //     //     'pedido_id'      => 9,
    //     //     'order_id_loja'  => $codigo,
    //     //     'cliente_id'     => $clienteId,
    //     //     'valor_centavos' => (int) round($total * 100),
    //     //     'metodo'         => $metodo,
    //     //     'parcelas'       => $parcelas,
    //     //     // 'token_cartao'   => $tokenCartao,
    //     //     'descricao'      => 'SportMoto #' . $codigo,
    //     //     'cliente'        => $this->buildCustomerData($clienteId, $endereco),
    //     //     'ip_origem'      => $_SERVER['REMOTE_ADDR'] ?? null,
    //     // ]);
    //     // echo json_encode($resultado);
    //     // exit();

    //     // 5. Transação: cria pedido + itens + reserva estoque
    //     $db->beginTransaction();
    //     try {
    //         // Status inicial: pendente até o gateway responder
    //         $db->prepare(
    //             "INSERT INTO pedidos
    //             (cliente_id, codigo, status_pedido, status_pagamento,
    //             forma_pagamento, parcelas, subtotal, desconto, frete, total,
    //             endereco_entrega_id, frete_descricao, frete_prazo, frete_codigo,
    //             observacao_cliente, cartao_bandeira, cartao_ultimos_4, criado_em)
    //             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())"
    //         )->execute([
    //             $clienteId,
    //             $codigo,
    //             'aguardando_pagamento',
    //             'pendente',
    //             $metodo,
    //             $parcelas,
    //             $subtotal,
    //             $desconto,
    //             $freteValorFinal,
    //             $total,
    //             $enderecoId,
    //             $freteData['descricao'] ?? null,
    //             $freteData['prazo']     ?? $freteData['frete_prazo'] ?? null,
    //             $freteData['codigo']    ?? null,
    //             $observacao,
    //             $cartaoBandeira,
    //             $cartaoUltimos4,
    //         ]);
    //         $pedidoId = (int)$db->lastInsertId();

    //         // Itens
    //         $stmtItem = $db->prepare(
    //             "INSERT INTO pedido_itens
    //             (pedido_id, nome_produto, subtotal, produto_id, sku, quantidade,
    //             preco_unitario, valor_original, desconto_cupom, valor_final_item, cupom_id)
    //             VALUES (?,?,?,?,?,?,?,?,?,?,?)"
    //         );

    //         foreach ($itens as $item) {
    //             $valorUnit  = (float)$item['valor_unitario'];
    //             $qtd        = (int)$item['quantidade'];
    //             $valorTotal = $valorUnit * $qtd;
    //             $descItem   = ($desconto > 0 && $subtotal > 0)
    //                 ? round($desconto * ($valorTotal / $subtotal), 2)
    //                 : 0.0;

    //             $stmtItem->execute([
    //                 $pedidoId, $item['nome'], $subtotal,
    //                 (int)$item['produto_id'],
    //                 $item['sku_id'] ? (int)$item['sku_id'] : null,
    //                 $qtd, $valorUnit, $valorUnit,
    //                 $descItem, $valorTotal - $descItem, $cupomId,
    //             ]);

    //             if (!empty($item['sku_id'])) {
    //                 $db->prepare(
    //                     "UPDATE produto_skus
    //                     SET estoque = GREATEST(0, estoque - ?)
    //                     WHERE id = ? AND estoque >= ?"
    //                 )->execute([$qtd, (int)$item['sku_id'], $qtd]);
    //             }
    //         }

    //         if ($cupomId && $desconto > 0) {
    //             $couponService = new CouponService();
    //             $cupomUsoId = $couponService->reservar(
    //                 $cupomId, $pedidoId, $clienteId,
    //                 $desconto, $freteDesc, $subtotal, $itens
    //             );
    //         }

    //         $creditoAplicado = (float)Session::get('checkout_credito', 0);
            
    //         // Valida de novo (race condition: saldo pode ter mudado)
    //         if ($creditoAplicado > 0) {
    //             $creditoService = new CreditoService();
    //             if (!$creditoService->validarReserva($u_data['usuaio_id'], $creditoAplicado)) {
    //                 $this->db->rollBack();
    //                 $this->json(['ok' => false, 'msg' => 'Saldo de crédito insuficiente. Recarregue e tente novamente.']);
    //             }
    //         }

    //         $db->commit();

    //     } catch (\Throwable $e) {
    //         $db->rollBack();
    //         error_log('[process] ' . $e->getMessage());
    //         $this->json([
    //             'ok' => false,
    //             'msg' => 'Erro interno ao criar pedido. Tente novamente.'
    //         ]);
    //     }

    //     // ══════════════════════════════════════════════════════════════
    //     // 6. Gateway de pagamento — agora via PaymentService (plugável)
    //     // ══════════════════════════════════════════════════════════════

    //     $statusPagamento = 'pendente';
    //     $pixQrCode = $pixCopiaCola = $pixExpiraEm = null;
    //     $boletoUrl = $boletoLinhaDig = null;
    //     $gatewayChargeId = null;
    //     $gatewayResposta = null;
    //     $boletoVencimento = null;

    //     $res_pay = [];

    //     try {
    //         // Resolve o token do cartão (se aplicável)
    //         $tokenCartao = null;
    //         if ($metodo === 'cartao') {
    //             if ($cartaoSalvoId) {
    //                 $tokenCartao = $this->buscarTokenCartao($db, (int)$cartaoSalvoId);
    //             } elseif ($cartaoTemp) {
    //                 $tokenCartao = $cartaoTemp['gateway_token'] ?? null;
    //             }

    //             if (empty($tokenCartao)) {
    //                 throw new \RuntimeException('Cartão sem token. Adicione novamente.');
    //             }
    //         }

    //         $paymentSvc = new PaymentService();
    //         $resultado = $paymentSvc->processarPagamento([
    //             'pedido_id'      => $pedidoId,
    //             'order_id_loja'  => $codigo,
    //             'cliente_id'     => $clienteId,
    //             'valor_centavos' => (int) round($total * 100),
    //             'metodo'         => $metodo,
    //             'parcelas'       => $parcelas,
    //             'token_cartao'   => $tokenCartao,
    //             'descricao'      => 'SportMoto #' . $codigo,
    //             'cliente'        => $this->buildCustomerData($clienteId, $endereco),
    //             'ip_origem'      => $_SERVER['REMOTE_ADDR'] ?? null,
    //         ]);

    //         if (!$resultado->ok) {
    //             // erro técnico / rede — pedido fica pendente, usuário pode tentar de novo
    //             error_log('[process] gateway erro: ' . ($resultado->errorMessage ?? '?'));
    //         }

    //         $statusPagamento    = $resultado->status;
    //         $gatewayChargeId    = $resultado->chargeId;
    //         $gatewayResposta    = json_encode($resultado->raw, JSON_UNESCAPED_UNICODE);

    //         // PIX
    //         $pixQrCode          = $resultado->pixQrCode;
    //         $pixCopiaCola       = $resultado->pixCopiaCola;
    //         $pixExpiraEm        = $resultado->pixExpiraEm;

    //         // Boleto
    //         $boletoUrl          = $resultado->boletoUrl;
    //         $boletoLinhaDig     = $resultado->boletoLinhaDigitavel;
    //         $boletoVencimento   = $resultado->boletoVencimento;

    //         $res_pay = $resultado;

    //     } catch (\Throwable $e) {
    //         error_log('[process] gateway exception: ' . $e->getMessage());
    //         $statusPagamento = 'pendente';
    //     }

    //     // 7. Atualiza status do pedido
    //     $statusPedidoMap = [
    //         'aprovado'       => 'pagamento_aprovado',
    //         'pre_autorizado' => 'aguardando_pagamento',
    //         'pendente'       => 'aguardando_pagamento',
    //         'recusado'       => 'aguardando_pagamento',  // permite nova tentativa
    //         'cancelado'      => 'cancelado',
    //         'estornado'      => 'cancelado',
    //         'erro'           => 'aguardando_pagamento',
    //     ];
    //     $statusPedidoFinal = $statusPedidoMap[$statusPagamento] ?? 'aguardando_pagamento';
    //     $pagoEm = ($statusPagamento === 'aprovado') ? date('Y-m-d H:i:s') : null;

    //     // APÓS commit — debita o crédito (fora da transação do pedido)
    //     if ($creditoAplicado > 0) {
    //         try {
    //             $creditoService->confirmarUsoCheckout($u_data['usuaio_id'], $creditoAplicado, $pedidoId, $codigo);
    //             Session::remove('checkout_credito');
    //         } catch (\Throwable $e) {
    //             error_log('[CheckoutController] credito debito: ' . $e->getMessage());
    //             // Não cancela o pedido — log para reconciliação manual
    //         }
    //     }

    //     $db->prepare(
    //         "UPDATE pedidos
    //         SET status_pagamento = ?,
    //             credito_utilizado= ?,
    //             status_pedido    = ?,
    //             pago_em          = ?,
    //             gateway_id       = ?,
    //             gateway_resposta = ?,
    //             pix_qr_code      = ?,
    //             pix_copia_cola   = ?,
    //             pix_expira_em    = ?,
    //             boleto_url       = ?,
    //             boleto_linha_digitavel = ?
    //         WHERE id = ?"
    //     )->execute([
    //         $statusPagamento,
    //         $creditoAplicado,
    //         $statusPedidoFinal,
    //         $pagoEm,
    //         $gatewayChargeId,
    //         $gatewayResposta,
    //         $pixQrCode,
    //         $pixCopiaCola,
    //         $pixExpiraEm,
    //         $boletoUrl,
    //         $boletoLinhaDig,
    //         $pedidoId,
    //     ]);

    //     // 8. Confirma cupom se aprovado
    //     if ($cupomUsoId && $statusPagamento === 'aprovado') {
    //         try { (new CouponService())->confirmar($cupomUsoId); }
    //         catch (\Throwable $e) { error_log('[process] Cupom confirm: ' . $e->getMessage()); }
    //     }



    //     // 9. Limpa sessão
    //     $this->state->clear();
    //     Session::remove('checkout_card_temp');
    //     Session::remove('checkout_card_id');
    //     Session::remove('checkout_payment_method');
    //     Session::remove('cupom_aplicado');
    //     $cart->clear($clienteId);

    //     // 10. Responde
    //     $this->json([
    //         'ok'       => true,
    //         'redirect' => BASE_URL . '/checkout/success/' . $codigo,
    //         'pedido'   => [
    //             'id'         => $pedidoId,
    //             'codigo'     => $codigo,
    //             'status'     => $statusPagamento,
    //             'pix_qr'     => $pixQrCode,
    //             'boleto_url' => $boletoUrl,
    //         ],
    //         // 'resPay'=>$res_pay
    //     ]);
    // }

    private function process(): void {

        $creditoService = new CreditoService();
    
        $db         = Database::getInstance()->getConnection();
        $clienteId  = (int)$_POST['cliente_id'];
        $metodo     = (string)($_POST['forma_pagamento'] ?? 'cartao');
        $parcelas   = (int)($_POST['parcelas']           ?? 1);
        $freteData  = $_POST['frete']    ?? null;
        $observacao = (string)($_POST['observacao']      ?? '');

        // Cupom lido diretamente da sessão — não de $_POST['cupom'].
        // CouponController::aplicar() salva em 'cupom_aplicado' com
        // cupom_id já resolvido, desconto e frete_desconto separados.
        // Ler via CheckoutState::getCupom() (como estava antes) criava
        // uma indireção frágil: a estrutura retornada podia não ter a
        // chave 'codigo' que process() esperava, fazendo reservar()
        // nunca ser chamado.
        $cupomSessao = Session::get('cupom_aplicado');

        $cartaoSalvoId = $_POST['cartao_salvo_id'] ?? null;
        $cartaoTemp    = $_POST['cartao_dados']    ?? null;

        $u_data = $this->userModel->getUserComplete($clienteId);
    
        // 1. Valida carrinho
        $cart  = new Cart();
        $itens = $cart->getItensComVariacoes($clienteId);
        if (empty($itens)) {
            $this->json(['ok' => false, 'msg' => 'Seu carrinho está vazio.']);
        }
    
        // 2. Valida endereço
        $enderecoId    = (int)($_POST['endereco_entrega_id'] ?? 0);
        $enderecoModel = new Endereco();
        $endereco      = $enderecoModel->findOwned($enderecoId, $clienteId);
        if (!$endereco) {
            $this->json(['ok' => false, 'msg' => 'Endereço de entrega inválido.']);
        }
    
        // 3. Recalcula totais server-side
        $subtotal    = (float)array_sum(array_map(
            fn($i) => (float)$i['valor_unitario'] * (int)$i['quantidade'],
            $itens
        ));
        $freteValor  = (float)($freteData['valor'] ?? 0);
        $desconto    = 0.0;
        $freteDesc   = 0.0;
        $cupomUsoId  = null;
        $cupomId     = null;
    
        // 4. Revalida e reserva cupom
        if (!empty($cupomSessao['codigo']) && !empty($cupomSessao['cupom_id'])) {
            $couponService = new CouponService();
            $itensCupom    = $cart->getItensParaCupom($clienteId);

            // Revalida server-side — o cliente pode ter alterado o carrinho
            // entre aplicar o cupom e finalizar (race condition entre sessões).
            $resultCupom = $couponService->validar(
                $cupomSessao['codigo'], $itensCupom, $subtotal, $freteValor,
                $clienteId, ['origem' => 'finalize']
            );

            if ($resultCupom['ok']) {
                // Usa os descontos recalculados (não confia no valor da sessão
                // — pode ter mudado se o carrinho foi alterado após aplicar).
                $desconto  = $resultCupom['desconto'];
                $freteDesc = $resultCupom['frete_desconto'];
                $cupomId   = (int)$cupomSessao['cupom_id']; // já resolvido — sem query extra
            } else {
                // Cupom ficou inválido entre aplicar e finalizar — remove
                // da sessão e continua sem desconto (não cancela o pedido).
                Session::remove('cupom_aplicado');
                $this->state->removerCupom();
                error_log("[process] cupom {$cupomSessao['codigo']} inválido na finalização: " . $resultCupom['msg']);
            }
        }
    
        $freteValorFinal = max(0, $freteValor - $freteDesc);

        // 5. Avalia promoções automáticas
        // Roda após o cupom — respeita a flag acumula_cupom de cada promoção.
        // Promoções que não acumulam com cupom são ignoradas se houver cupom ativo.
        $promocaoService    = new PromocaoService();
        $itensCupom         = $itensCupom ?? $cart->getItensParaCupom($clienteId); // reusa se já buscou
        $primeiraCompra     = !isset($primeiraCompra)
            ? ((new Customer())->getPedidosCount($clienteId) === 0)
            : $primeiraCompra;

        $contextoPromo = [
            'primeira_compra' => $primeiraCompra,
            'score'           => (int)(Session::get('cliente_score') ?? 0),
            'tem_cupom'       => $cupomId !== null,
        ];

        $resultadosPromo = $promocaoService->avaliarCarrinho(
            $itensCupom, $subtotal, $freteValorFinal, $clienteId, $contextoPromo
        );

        // Filtra promoções que não acumulam com cupom quando há cupom ativo
        if ($cupomId !== null) {
            $resultadosPromo = array_filter(
                $resultadosPromo,
                function ($r) {
                    $promo = (new Promocao())->findById($r['promocao_id']);
                    return (bool)($promo['acumula_cupom'] ?? false);
                }
            );
            $resultadosPromo = array_values($resultadosPromo);
        }

        $totaisPromo      = $promocaoService->calcularTotais($resultadosPromo);
        $descontoPromo    = $totaisPromo['desconto_produto'];
        $freteDescPromo   = $totaisPromo['desconto_frete'];
        $brindes          = $totaisPromo['brindes'];

        // Brinde entra como pedido_itens com valor real (subtotal sobe),
        // mas desconto_produto já inclui o preço do brinde (líquido = R$0).
        // Ex: subtotal R$300 + brinde R$50 = R$350 subtotal, R$50 desconto a mais → total R$300.
        $subtotalBrinde = (float)array_sum(array_map(
            fn($b) => $b['preco'] * $b['quantidade'],
            $brindes
        ));

        // Desconto total = cupom + promoções (inclui preço do brinde)
        $descontoTotal    = $desconto + $descontoPromo;
        $freteDescTotal   = $freteDesc + $freteDescPromo;
        $freteValorFinal  = max(0, $freteValor - $freteDescTotal);
        $subtotalPedido   = $subtotal + $subtotalBrinde;
        $total            = max(0, round($subtotalPedido - $descontoTotal + $freteValorFinal, 2));
        // $codigo          = strtoupper(substr(md5(uniqid((string)$clienteId, true)), 0, 8));
        $codigo = $this->gerarProximoCodigoCliente();
    
        $cartaoBandeira = null;
        $cartaoUltimos4 = null;
    
        if ($metodo === 'cartao') {
            if ($cartaoSalvoId) {
                $stmtCard = $db->prepare(
                    "SELECT bandeira, ultimos_4 FROM cartoes_salvos WHERE id = ? LIMIT 1"
                );
                $stmtCard->execute([(int)$cartaoSalvoId]);
                $cardRow = $stmtCard->fetch();
                $cartaoBandeira = $cardRow['bandeira']  ?? null;
                $cartaoUltimos4 = $cardRow['ultimos_4'] ?? null;
            } elseif ($cartaoTemp) {
                $cartaoBandeira = $cartaoTemp['bandeira']  ?? null;
                $cartaoUltimos4 = $cartaoTemp['ultimos_4'] ?? null;
            }
        }
    
        // 5. Transação: cria pedido + itens + reserva estoque
        $db->beginTransaction();
        try {
            $db->prepare(
                "INSERT INTO pedidos
                (cliente_id, codigo, status_pedido, status_pagamento,
                forma_pagamento, parcelas, subtotal, desconto, frete, total,
                endereco_entrega_id, frete_descricao, frete_prazo, frete_codigo,
                observacao_cliente, cartao_bandeira, cartao_ultimos_4, criado_em)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())"
            )->execute([
                $clienteId, $codigo,
                'aguardando_pagamento', 'pendente',
                $metodo, $parcelas,
                $subtotalPedido, $descontoTotal, $freteValorFinal, $total,
                $enderecoId,
                $freteData['descricao'] ?? null,
                $freteData['prazo']     ?? $freteData['frete_prazo'] ?? null,
                $freteData['codigo']    ?? null,
                $observacao,
                $cartaoBandeira,
                $cartaoUltimos4,
            ]);
            $pedidoId = (int)$db->lastInsertId();
    
            $stmtItem = $db->prepare(
                "INSERT INTO pedido_itens
                (pedido_id, nome_produto, subtotal, produto_id, sku, quantidade,
                preco_unitario, valor_original, desconto_cupom, valor_final_item, cupom_id)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)"
            );
            foreach ($itens as $item) {
                $valorUnit  = (float)$item['valor_unitario'];
                $qtd        = (int)$item['quantidade'];
                $valorTotal = $valorUnit * $qtd;
                $descItem   = ($desconto > 0 && $subtotal > 0)
                    ? round($desconto * ($valorTotal / $subtotal), 2)
                    : 0.0;
    
                $stmtItem->execute([
                    $pedidoId, $item['nome'], $subtotal,
                    (int)$item['produto_id'],
                    $item['sku_id'] ? (int)$item['sku_id'] : null,
                    $qtd, $valorUnit, $valorUnit,
                    $descItem, $valorTotal - $descItem, $cupomId,
                ]);
    
                if (!empty($item['sku_id'])) {
                    $db->prepare(
                        "UPDATE produto_skus
                        SET estoque = GREATEST(0, estoque - ?)
                        WHERE id = ? AND estoque >= ?"
                    )->execute([$qtd, (int)$item['sku_id'], $qtd]);
                }
            }
    
            if ($cupomId && ($desconto > 0 || $freteDesc > 0)) {
                $couponService = new CouponService();
                $cupomUsoId = $couponService->reservar(
                    $cupomId, $pedidoId, $clienteId,
                    $desconto, $freteDesc, $subtotal, $itens
                );
            }

            // Registra promoções automáticas — sem transação própria,
            // dentro da mesma unidade atômica do pedido.
            if (!empty($resultadosPromo)) {
                $promocaoService->aplicar($resultadosPromo, $pedidoId, $clienteId);
            }

            // Brindes: insere como pedido_itens com valor real.
            // O desconto da promoção (já em descontoTotal) cobre o preço,
            // resultado líquido = R$0 para o cliente. Entra no pedido,
            // na NF-e e controla estoque normalmente.
            foreach ($brindes as $brinde) {
                $brindeId = (int)$brinde['produto_id'];

                // ── Anti race condition ──────────────────────────
                // O estoque foi verificado em avaliarBrinde(), mas entre
                // a avaliação e este INSERT outro checkout concorrente
                // pode ter levado a última unidade. Lock pessimista na
                // linha do produto + re-verificação dentro da transação.
                $db->prepare("SELECT id FROM produtos WHERE id = ? FOR UPDATE")
                   ->execute([$brindeId]);

                $stmtSaldo = $db->prepare(
                    "SELECT CASE
                        WHEN EXISTS (SELECT 1 FROM estoque_saldo WHERE produto_id = ?)
                        THEN (SELECT COALESCE(SUM(saldo),0) FROM estoque_saldo WHERE produto_id = ?)
                        ELSE (SELECT COALESCE(estoque_total,0) FROM produtos WHERE id = ?)
                     END"
                );
                $stmtSaldo->execute([$brindeId, $brindeId, $brindeId]);
                $saldoAtual = (int)$stmtSaldo->fetchColumn();

                if ($saldoAtual < (int)$brinde['quantidade']) {
                    // Esgotou entre a avaliação e a confirmação.
                    // Rollback total: cliente re-tenta e a promoção não
                    // dispara mais (avaliarBrinde retorna null sem estoque).
                    throw new \RuntimeException('BRINDE_ESGOTADO');
                }

                $db->prepare(
                    "INSERT INTO pedido_itens
                     (pedido_id, produto_id, sku_id, nome_produto,
                      quantidade, valor_unitario, valor_final_item,
                      opcoes_selecionadas, is_brinde)
                     VALUES (?,?,NULL,?,?,?,?,NULL,1)"
                )->execute([
                    $pedidoId,
                    $brindeId,
                    $brinde['nome'] . ' (Brinde)',
                    (int)$brinde['quantidade'],
                    (float)$brinde['preco'],
                    round((float)$brinde['preco'] * (int)$brinde['quantidade'], 2),
                ]);
            }

            $db->commit();
    
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log('[process] ' . $e->getMessage());

            // Mensagem específica para brinde esgotado durante o checkout
            // (evento raro de concorrência) — orienta o cliente sem vazar
            // detalhes internos.
            if ($e->getMessage() === 'BRINDE_ESGOTADO') {
                $this->json([
                    'ok'  => false,
                    'msg' => 'O brinde da promoção esgotou agora há pouco. '
                           . 'Atualize a página e finalize novamente.',
                ]);
                return;
            }
            $this->json(['ok' => false, 'msg' => 'Erro interno ao criar pedido. Tente novamente.']);
        }
    
        // ════════════════════════════════════════════════════
        // 6. Gateway de pagamento — via PaymentService (Etapa 2)
        // ════════════════════════════════════════════════════
        $statusPagamento  = 'pendente';
        $gatewayChargeId  = null;
        $gatewayResposta  = null;
        $pixQrCode        = null;
        $pixCopiaCola     = null;
        $pixExpiraEm      = null;
        $boletoUrl        = null;
        $boletoLinhaDig   = null;
        $boletoVencimento = null;        

        // APÓS commit — debita o crédito (fora da transação do pedido)
        $creditoAplicado = (float)Session::get('checkout_credito', 0);
 
        // Double-check antes de debitar (saldo pode ter mudado)
        if ($creditoAplicado > 0) {
            $creditoService = new CreditoService();
            if ($creditoService->validarReserva($u_data['usuario_id'], $creditoAplicado)) {
                try {
                    $creditoService->confirmarUsoCheckout(
                        $u_data['usuario_id'],
                        $creditoAplicado,
                        $pedidoId,
                        $codigo
                    );
                } catch (\Throwable $e) {
                    // Não cancela o pedido — loga para reconciliação manual
                    error_log("[CheckoutController] debito credito pedido #{$codigo}: " . $e->getMessage());
                }
            }
            Session::remove('checkout_credito');
        }

        $msg_credito_after_aprove = ($creditoAplicado > 0) ? 'Você aplicou um crédito de R$ ' . number_format($creditoAplicado, 2, ',', '.') . ' nesta compra. Se a compra não for aprovada, o crédito voltará para sua conta.' : '';
        $this->service->mudarStatus($pedidoId, 'aguardando_pagamento', 'Pedido criado com sucesso.' . $msg_credito_after_aprove, 0, false);
    
        try {
            $paymentSvc = new PaymentService();

            // Resolve token do cartão
            $tokenCartao = null;
            $isCardId    = false;  // true = cardId permanente, false = tokenId efêmero
            
            if ($metodo === 'cartao') {
                if ($cartaoSalvoId) {
                    $tokenCartao = $this->buscarTokenCartao($db, (int)$cartaoSalvoId);
                    $isCardId    = true;  // vem do banco — é um cardId permanente do vault
                } elseif ($cartaoTemp) {
                    $tokenCartao = $cartaoTemp['gateway_token'] ?? $cartaoTemp['token'] ?? null;
                    $isCardId    = false; // token fresco da sessão — ainda pode ser tokenId
                }
                if (empty($tokenCartao)) {
                    $this->json(['ok' => false, 'msg' => 'Cartão sem token. Adicione o cartão novamente.']);
                }
            }
            
            // ... e no processarPagamento(), adicionar is_card_id ao array:
            $resultado = $paymentSvc->processarPagamento([
                'pedido_id'      => $pedidoId,
                'order_id_loja'  => $codigo,
                'cliente_id'     => $clienteId,
                'valor_centavos' => (int) round($total * 100),
                'metodo'         => $metodo,
                'parcelas'       => $parcelas,
                'token_cartao'   => $tokenCartao,
                'is_card_id'     => $isCardId,   // ← flag nova
                'descricao'      => 'SportMoto #' . $codigo,
                'cliente'        =>  $this->buildCustomerData($clienteId, $endereco),
                'ip_origem'      => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);

            // LogService::info('processarPagamento', $tokenCartao);
    
            $statusPagamento  = $resultado->status;
            $gatewayChargeId  = $resultado->chargeId;
            $gatewayResposta  = json_encode($resultado->raw, JSON_UNESCAPED_UNICODE);
            $pixQrCode        = $resultado->pixQrCode;
            $pixCopiaCola     = $resultado->pixCopiaCola;
            $pixExpiraEm      = $resultado->pixExpiraEm;
            $boletoUrl        = $resultado->boletoUrl;
            $boletoLinhaDig   = $resultado->boletoLinhaDigitavel;
            $boletoVencimento = $resultado->boletoVencimento;
    
        } catch (\Throwable $e) {
            LogService::error('PaymentService -> '.$e->getMessage().' - line:'.$e->getLine().' - file:'.$e->getFile());
            error_log('[process] gateway: ' . $e->getMessage());
            // Não derruba o pedido — fica pendente, webhook pode confirmar depois
            $statusPagamento = 'pendente';            
        }
    
        // 7. Atualiza status do pedido
        $statusPedidoMap = [
            'aprovado'       => 'pagamento_aprovado',
            'pre_autorizado' => 'aguardando_pagamento',
            'pendente'       => 'aguardando_pagamento',
            'recusado'       => 'aguardando_pagamento',
            'falhou'         => 'aguardando_pagamento',
            'cancelado'      => 'cancelado',
            'erro'           => 'aguardando_pagamento',
        ];
        $statusPedidoFinal = $statusPedidoMap[$statusPagamento] ?? 'aguardando_pagamento';
        $pagoEm = ($statusPagamento === 'aprovado') ? date('Y-m-d H:i:s') : null;

        if($statusPedidoFinal === 'pagamento_aprovado') {
            $msg_credito = 'Você utilizou um crédito de R$ ' . number_format($creditoAplicado, 2, ',', '.') . ' nesta compra.';
            $this->service->mudarStatus($pedidoId, 'pagamento_aprovado', 'Pagamento aprovado. ' . $msg_credito, 0, false);
        }

        // → Bling: envia SEMPRE após criação, independente do status de pagamento
        // try {
        //     (new BlingOrderService())->enviarPedido($pedidoId);
        // } catch (\Throwable $e) {
        //     // error_log();
        //     LogService::error('[CheckoutController] Bling enviarPedido #' . $pedidoId . ': ' . $e->getMessage(), $e);
        // }
    
        $db->prepare(
            "UPDATE pedidos
            SET status_pagamento       = ?,
                status_pedido          = ?,
                pago_em                = ?,
                gateway_id             = ?,
                gateway_resposta       = ?,
                pix_qr_code            = ?,
                pix_copia_cola         = ?,
                pix_expira_em          = ?,
                boleto_url             = ?,
                boleto_linha_digitavel = ?,
                boleto_vencimento      = ?,
                credito_utilizado      = ?
            WHERE id = ?"
        )->execute([
            $statusPagamento,
            $statusPedidoFinal,
            $pagoEm,
            $gatewayChargeId,
            $gatewayResposta,
            $pixQrCode,
            $pixCopiaCola,
            $pixExpiraEm,
            $boletoUrl,
            $boletoLinhaDig,
            $boletoVencimento,
            $creditoAplicado,
            $pedidoId,
        ]);
    
        // 8. Confirma cupom se aprovado
        if ($cupomUsoId && $statusPagamento === 'aprovado') {
            try { (new CouponService())->confirmar($cupomUsoId); }
            catch (\Throwable $e) { error_log('[process] Cupom confirm: ' . $e->getMessage()); }
        }
        
        // 
        // 9. Limpa sessão
        $this->state->clear();
        Session::remove('checkout_card_temp');
        Session::remove('checkout_card_id');
        Session::remove('checkout_cartao_id');
        Session::remove('checkout_payment_method');
        Session::remove('cupom_aplicado');
        $cart->clear($clienteId);
        
        
        // 10. Responde
        $this->json([
            'ok'       => true,
            'redirect' => BASE_URL . '/checkout/success/' . $codigo,
            'pedido'   => [
                'id'         => $pedidoId,
                'codigo'     => $codigo,
                'status'     => $statusPagamento,
                'pix_qr'     => $pixQrCode,
                'boleto_url' => $boletoUrl,
            ],
        ]);
    }

    private function gerarProximoCodigoCliente(): int
    {
        $db         = Database::getInstance()->getConnection();
        $st =$db->query("
            SELECT MAX(CAST(codigo AS UNSIGNED)) AS ultimo_codigo
            FROM pedidos
            WHERE codigo REGEXP '^[0-9]+$'
        ");

        $row = $st->fetch(PDO::FETCH_ASSOC);

        $ultimoCodigo = (int)($row['ultimo_codigo'] ?? 0);

        if ($ultimoCodigo < 15121) {
            return 15121;
        }

        return $ultimoCodigo + 2;
    }
    
    
    public function success(string $codigo): void {
        $this->requireLogin();
    
        $clienteId = (int)Session::get('cliente_id');
        $codigo    = strtoupper(preg_replace('/[^A-Z0-9]/', '', $codigo));
    
        if (empty($codigo)) {
            $this->redirect(BASE_URL . '/minha-conta/pedidos');
        }
    
        $db = Database::getInstance()->getConnection();
    
        // Carrega pedido — só se pertencer ao cliente logado
        $stmt = $db->prepare(
            "SELECT p.*,
                    e.nome_destinatario, e.logradouro, e.numero,
                    e.complemento, e.bairro, e.cidade, e.estado, e.cep,
                    u.nome  AS cliente_nome,
                    u.email AS cliente_email
            FROM pedidos p
            LEFT JOIN enderecos e ON e.id = p.endereco_entrega_id
            LEFT JOIN clientes  c ON c.id = p.cliente_id
            LEFT JOIN usuarios  u ON u.id = c.usuario_id
            WHERE p.codigo = ? AND p.cliente_id = ?
            LIMIT 1"
        );
        // OBS: p.* já inclui: status_pedido, status_pagamento, pago_em,
        //      cartao_bandeira, cartao_ultimos_4, parcelas, frete_descricao,
        //      frete_prazo, codigo_rastreio, boleto_url, pix_qr_code, gateway_id
        $stmt->execute([$codigo, $clienteId]);
        $pedido = $stmt->fetch();
    
        // Normaliza colunas que podem não existir em instâncias antigas
        if ($pedido) {
            $pedido['pago_em']           = $pedido['pago_em']           ?? null;
            $pedido['cartao_bandeira']   = $pedido['cartao_bandeira']   ?? null;
            $pedido['cartao_ultimos_4']  = $pedido['cartao_ultimos_4']  ?? null;
            $pedido['codigo_rastreio']   = $pedido['codigo_rastreio']   ?? null;
            $pedido['status_pedido']     = $pedido['status_pedido']     ?? 'aguardando_pagamento';
            $pedido['frete_prazo']       = $pedido['frete_prazo']
                                        ?? $pedido['frete_prazo_dias']  ?? null;
        }
    
        if (!$pedido) {
            $this->redirect(BASE_URL . '/minha-conta/pedidos');
        }
    
        // Carrega itens com variação
        // $stmt2 = $db->prepare(
        //     "SELECT
        //         pi.*,
        //         pr.nome        AS produto_nome,
        //         pr.slug        AS produto_slug,
        //         img.arquivo    AS imagem
        //     FROM pedido_itens pi
        //     JOIN produtos pr         ON pr.id  = pi.produto_id
        //     LEFT JOIN produto_imagens img ON img.produto_id = pr.id AND img.principal = 1
        //     WHERE pi.pedido_id = ?
        //     ORDER BY pi.id ASC"
        // );
        // $stmt2->execute([$pedido['id']]);
        // $itens = $stmt2->fetchAll();

        $order = new Order();
        $itens = $order->getItemsWithVariacoes($pedido['id']);
    
        // Cupom utilizado (se houver)
        $cupom = null;
        if ($pedido['desconto'] > 0) {
            $stmt3 = $db->prepare(
                "SELECT cu.codigo FROM cupom_usos u
                JOIN cupons cu ON cu.id = u.cupom_id
                WHERE u.pedido_id = ? LIMIT 1"
            );
            $stmt3->execute([$pedido['id']]);
            $cupom = $stmt3->fetchColumn() ?: null;
        }
    
        // Pix: dados para exibir QR
        $pixDados = null;
        if ($pedido['forma_pagamento'] === 'pix' && $pedido['pix_qr_code']) {
            $pixDados = [
                'qr_code'    => $pedido['pix_qr_code'],  // base64 ou URL
                'copia_cola' => $pedido['pix_copia_cola'] ?? $pedido['pix_qr_code'],
                'expira_em'  => $pedido['pix_expira_em']  ?? null,
            ];
        }
    
        $this->renderCheckout('checkout/success', [
            'pedido'    => $pedido,
            'itens'     => $itens,
            'cupom'     => $cupom,
            'pixDados'  => $pixDados,
            'etapaAtual'=> 'success',
        ]);
    }

    // ── debugState() — APENAS DESENVOLVIMENTO ────────────────
    // Rota: GET /checkout/debug-state
    public function debugState(): void {
        if (!defined('APP_DEBUG') || !APP_DEBUG) {
            http_response_code(404); exit;
        }
        $clienteId = Session::isClienteLogado() ? (int)Session::get('cliente_id') : null;
        $temp      = Session::get('checkout_card_temp');

        $this->json([
            'session' => [
                'checkout_state'          => Session::get('checkout_state'),
                'checkout_payment_method' => Session::get('checkout_payment_method'),
                'checkout_card_id'        => Session::get('checkout_card_id'),
                'checkout_card_temp'      => $temp ? [
                    'bandeira'   => $temp['bandeira']   ?? null,
                    'ultimos_4'  => $temp['ultimos_4']  ?? null,
                    'nome_titular'=> $temp['nome_titular'] ?? null,
                    'validade'   => $temp['validade']   ?? null,
                    'expires_in' => (int)($temp['expires_at'] ?? 0) - time() . 's',
                ] : null,
                'cupom_aplicado' => Session::get('cupom_aplicado'),
                'cliente_id'     => $clienteId,
            ],
            'state'        => $this->state->getAll(),
            'pode_acessar' => [
                'address' => $this->state->podeAcessar('address'),
                'payment' => $this->state->podeAcessar('payment'),
                'summary' => $this->state->podeAcessar('summary'),
            ],
            'cart_empty' => (new Cart())->isEmpty(),
            'fake_mode'  => defined('CHECKOUT_FAKE_MODE') && CHECKOUT_FAKE_MODE,
        ]);
    }

    // ── Helpers privados ─────────────────────────────────────
    private function buscarCupomId(PDO $db, string $codigo): ?int {
        $stmt = $db->prepare("SELECT id FROM cupons WHERE codigo = ? LIMIT 1");
        $stmt->execute([strtoupper(trim($codigo))]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    }

    private function buscarTokenCartao(PDO $db, int $id): ?string {
        $stmt = $db->prepare("SELECT token FROM cartoes_salvos WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetchColumn() ?: null;
    }

    // ════════════════════════════════════════════════════
    // UTILS PRIVADOS
    // ════════════════════════════════════════════════════

    private function renderCheckout(string $view, array $data = []): void {
        // Adiciona dados comuns a todas as etapas
        $carrinhoId = $this->cartService->getOrCreate()['id'];
        // $data['cartItens']     = $this->cartService->getItems((int)$carrinhoId);
        // $data['cartTotais']    = $this->cartService->calcularTotais($data['cartItens']);
        // $data['checkoutFrete'] = $this->state->getFrete();
        // $data['checkoutCupom'] = $this->state->getCupom();

        $totals = $this->cartService->getTotals((int)$carrinhoId);

        $data['cartItens']     = $totals['items'];
        $data['cartTotais']    = $totals;
        $data['checkoutFrete'] = $this->state->getFrete();
        $data['checkoutCupom'] = $this->state->getCupom();

        $this->render($view, $data, 'checkout-layout');
    }

    private function requireLogin(): void {
        if (!Session::isClienteLogado()) {
            $this->redirect('/checkout/identify');
        }
    }

    // private function requireLoginAjax(): void {
    //     if (!Session::isClienteLogado()) {
    //         $this->json(['ok' => false, 'msg' => 'Não autenticado.', 'redirect' => BASE_URL . '/checkout/identify']);
    //     }
    // }
    private function requireLoginAjax(): void {
        if (!Session::isClienteLogado()) {
            $this->redirecionarParaLogin('checkout', '/checkout');
            // (redirecionarParaLogin detecta AJAX e responde JSON
            //  com { login: true, redirect: '/login' })
        }
    }

    private function redirectTo(string $url): void {
        header('Location: ' . $url);
        exit;
    }

    private function notFound(): void {
        http_response_code(404);
        $this->render('errors/404');
        exit;
    }

    private function extractEnderecoFromPost(): array {
        return [
            'nome_destinatario' => SecurityHelper::sanitizeString($_POST['nome_destinatario'] ?? ''),
            'cep'               => preg_replace('/\D/', '', $_POST['cep'] ?? ''),
            'logradouro'        => SecurityHelper::sanitizeString($_POST['logradouro']  ?? ''),
            'numero'            => SecurityHelper::sanitizeString($_POST['numero']      ?? ''),
            'complemento'       => SecurityHelper::sanitizeString($_POST['complemento'] ?? ''),
            'bairro'            => SecurityHelper::sanitizeString($_POST['bairro']      ?? ''),
            'cidade'            => SecurityHelper::sanitizeString($_POST['cidade']      ?? ''),
            'estado'            => strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $_POST['estado'] ?? ''), 0, 2)),
            'telefone'          => preg_replace('/\D/', '', $_POST['telefone']         ?? ''),
            'apelido'           => SecurityHelper::sanitizeString($_POST['apelido']     ?? ''),
            'observacao_entrega'=> SecurityHelper::sanitizeString($_POST['observacao_entrega'] ?? ''),
        ];
    }

    private function validarEndereco(array $d): array {
        $errors = [];
        if (strlen($d['cep']) !== 8)               $errors[] = 'CEP inválido.';
        if (mb_strlen($d['nome_destinatario']) < 3)$errors[] = 'Nome do destinatário muito curto.';
        if (empty($d['logradouro']))               $errors[] = 'Endereço obrigatório.';
        if (empty($d['numero']))                   $errors[] = 'Número obrigatório.';
        if (empty($d['bairro']))                   $errors[] = 'Bairro obrigatório.';
        if (empty($d['cidade']))                   $errors[] = 'Cidade obrigatória.';
        if (strlen($d['estado']) !== 2)            $errors[] = 'UF inválida.';
        return $errors;
    }

    /** Acrescenta hash encriptado em cada endereço para uso nas URLs */
    private function hidratarEnderecos(array $enderecos): array {
        foreach ($enderecos as &$end) {
            $end['hash'] = IdHasher::encode((int)$end['id'], 'address');
        }
        return $enderecos;
    }

    private function encontrarPrincipal(array $enderecos): ?array {
        foreach ($enderecos as $end) {
            if (!empty($end['principal'])) return $end;
        }
        return $enderecos[0] ?? null;
    }

    private function calcularDesconto(?array $cupom, float $subtotal): float {
        if (!$cupom) return 0;
        if ($cupom['tipo'] === 'percentual') {
            return round($subtotal * ((float)$cupom['desconto'] / 100), 2);
        }
        return min((float)$cupom['desconto'], $subtotal);
    }

    // ── Handlers de identificação (extraídos do v1) ─────
    // O conteúdo completo desses handlers veio no arquivo
    // CheckoutController-identify.php entregue anteriormente.
    // Aqui são apenas as assinaturas pra manter o controller coerente.

    private function handleLogin(string $email, string $senha): void {
        /* mesmo código de identifyPost() acao=login do v1 */
    }
    private function handleCadastroRapido(string $email, array $post): void {
        /* mesmo código de cadastro_rapido do v1 */
    }
    private function handleVerificarCodigo(string $codigo): void {
        /* mesmo código de verificar_codigo do v1 */
    }
    private function handleReenviarCodigo(): void {
        /* mesmo código de reenviar_codigo do v1 */
    }


    public function selecionarItens(): void {
        $this->verifyCsrf();

        $itemIds      = array_filter(array_map('intval', (array)($_POST['item_ids'] ?? [])));
        $freteValor   = (float)($_POST['frete_valor']   ?? 0);
        $freteServico = SecurityHelper::sanitizeString($_POST['frete_servico'] ?? '');

        if (empty($itemIds)) {
            $this->json(['ok' => false, 'msg' => 'Nenhum item selecionado.']);
        }

        Session::set('checkout_itens_selecionados', $itemIds);
        Session::set('checkout_frete_valor',        $freteValor);
        Session::set('checkout_frete_servico',      $freteServico);

        $this->json(['ok' => true]);
    }

// ════════════════════════════════════════════════════════
// ADICIONAR ao CheckoutController.php — métodos de cartão + pagamento
// Colocar antes do último `}` da classe.
// ════════════════════════════════════════════════════════

    /** GET /checkout/payment/card/add */
    // public function paymentCardAdd(): void {
    //     $this->requireLogin();
    //     $rateKey = 'card_add_' . md5($_SERVER['REMOTE_ADDR'] ?? '');
    //     if (SecurityHelper::rateLimitExceeded($rateKey, 5, 3600)) {
    //         Session::flash('error', 'Muitas tentativas. Tente novamente em breve.');
    //         // $this->redirect('/checkout/payment');
    //     }
    //     $this->renderCheckout('checkout/payment-card-add', ['etapaAtual' => 'payment', 'modo'=>'card-add']);
    // }

    public function paymentCardAdd(): void {
        $this->requireLogin();
    
        // Carrega credenciais front-end do gateway ativo
        $pdo = Database::getInstance()->getConnection();
        $stmt = $pdo->prepare(
            "SELECT front_client_id, front_api_key, sandbox
            FROM pgto_gateways
            WHERE codigo = 'malga' AND ativo = 1
            LIMIT 1"
        );
        $stmt->execute();
        $gw = $stmt->fetch(\PDO::FETCH_ASSOC);
    
        if (!$gw || empty($gw['front_client_id']) || empty($gw['front_api_key'])) {
            // Credenciais front não configuradas — não permite chegar nessa página
            $this->renderError(
                'Pagamento por cartão indisponível no momento. Tente outro método.',
                'admin' // ou o layout que o projeto usa
            );
            return;
        }
    
        // Define como constantes pra a view ler
        if (!defined('MALGA_PUBLIC_CLIENT_ID')) define('MALGA_PUBLIC_CLIENT_ID', (string) $gw['front_client_id']);
        if (!defined('MALGA_PUBLIC_API_KEY'))   define('MALGA_PUBLIC_API_KEY',   (string) $gw['front_api_key']);
        if (!defined('MALGA_SANDBOX'))          define('MALGA_SANDBOX',          (bool) $gw['sandbox']);
    
        $this->renderCheckout('checkout/payment-card-add', ['etapaAtual' => 'payment', 'modo'=>'card-add']);
    }

    /** POST /checkout/payment/card/add */
    // public function paymentCardAddPost(): void {
    //     $this->requireLoginAjax();
    //     $this->verifyCsrf();
    //     $clienteId = (int)Session::get('cliente_id');

    //     $rateKey = 'card_add_post_' . md5($_SERVER['REMOTE_ADDR'] ?? '');
    //     if (SecurityHelper::rateLimitExceeded($rateKey, 5, 3600)) {
    //         // $this->json(['ok' => false, 'msg' => 'Muitas tentativas. Aguarde alguns minutos.']);
    //     }

    //     $numero   = preg_replace('/\D/', '', (string)($_POST['numero_cartao']  ?? ''));
    //     $nome     = SecurityHelper::sanitizeString(strtoupper($_POST['nome_cartao'] ?? ''));
    //     $apelido     = SecurityHelper::sanitizeString(strtoupper($_POST['apelido'] ?? ''));
    //     $validade = SecurityHelper::sanitizeString($_POST['validade_cartao'] ?? '');
    //     $cvv      = preg_replace('/\D/', '', (string)($_POST['cvv_cartao'] ?? ''));
    //     $bandeira = SecurityHelper::sanitizeString(strtolower($_POST['bandeira'] ?? ''));
    //     $principal= (int)($_POST['principal'] ?? 0);

    //     $apelido = !empty($apelido) ? $apelido : NULL;

    //     $erros = [];
    //     if (strlen($numero) < 13 || !$this->luhnCheck($numero)) $erros[] = 'Número do cartão inválido.';
    //     if (mb_strlen($nome) < 4)                                $erros[] = 'Nome do titular obrigatório.';
    //     if (!preg_match('/^\d{2}\/\d{2}$/', $validade))      $erros[] = 'Validade inválida (MM/AA).';
    //     if (strlen($cvv) < 3)                                    $erros[] = 'CVV inválido.';

    //     if (!empty($erros)) $this->json(['ok' => false, 'msg' => $erros[0]]);

    //     if (!empty($validade)) {
    //         $expDate = \DateTime::createFromFormat('m/y', $validade);
    //         if (!$expDate || $expDate < new \DateTime('first day of this month')) {
    //             $this->json(['ok' => false, 'msg' => 'Cartão vencido.']);
    //         }
    //     }

    //     // Tokenização via gateway (placeholder — substituir em produção)
    //     $token    = 'tok_' . bin2hex(random_bytes(8));
    //     $ultimos4 = substr($numero, -4);

    //     try {
    //         $cartaoModel = new CartaoSalvo();
    //         $cartaoId = $cartaoModel->salvar([
    //             'cliente_id'   => $clienteId,
    //             'token'        => $token,
    //             'bandeira'     => !empty($bandeira) ? $bandeira : $this->detectarBandeira($numero),
    //             'ultimos_4'    => $ultimos4,
    //             'nome_titular' => $nome,
    //             'apelido'      => $apelido,
    //             'validade'     => $validade,
    //             'principal'    => $principal,
    //         ]);
    //         if($principal){
    //             Session::set('checkout_payment_method', 'cartao');
    //         }
    //         Session::set('checkout_cartao_id', $cartaoId);
    //         $this->json(['ok' => true, 'redirect' => BASE_URL . '/checkout/payment']);
    //     } catch (\InvalidArgumentException $e) {
    //         $this->json(['ok' => false, 'msg' => $e->getMessage()]);
    //     } catch (\Throwable $e) {
    //         error_log('[CartaoAdd] ' . $e->getMessage());
    //         $this->json(['ok' => false, 'msg' => 'Erro ao salvar. Tente novamente.']);
    //     }
    // }

    // public function paymentCardAddPost(): void {
    //     $this->requireLoginAjax();
    //     $this->verifyCsrf();
    //     $clienteId = (int)Session::get('cliente_id');

    //     $rateKey = 'card_add_post_' . md5($_SERVER['REMOTE_ADDR'] ?? '');
    //     if (SecurityHelper::rateLimitExceeded($rateKey, 5, 3600)) {
    //         $this->json(['ok' => false, 'msg' => 'Muitas tentativas. Aguarde alguns minutos.']);
    //     }

    //     // Caso 1: front-end já enviou um gateway_token (tokenização client-side via SDK).
    //     // Esse é o caminho PCI-compliant e deve ser o padrão em produção.
    //     $gatewayToken    = trim((string)($_POST['gateway_token'] ?? ''));
    //     $bandeiraFront   = SecurityHelper::sanitizeString(strtolower($_POST['bandeira'] ?? ''));
    //     $ultimos4Front   = preg_replace('/\D/', '', (string)($_POST['ultimos_4'] ?? ''));
    //     $nome     = SecurityHelper::sanitizeString(strtoupper($_POST['nome_cartao'] ?? ''));
    //     $apelido  = SecurityHelper::sanitizeString(strtoupper($_POST['apelido'] ?? ''));
    //     $validade = SecurityHelper::sanitizeString($_POST['validade_cartao'] ?? '');
    //     $principal= (int)($_POST['principal'] ?? 0);
    //     $apelido  = !empty($apelido) ? $apelido : null;

    //     // Validações comuns (válidas pros dois caminhos)
    //     $erros = [];
    //     if (mb_strlen($nome) < 4)                          $erros[] = 'Nome do titular obrigatório.';
    //     if (!preg_match('/^\d{2}\/\d{2}$/', $validade))    $erros[] = 'Validade inválida (MM/AA).';
    //     if ($erros) $this->json(['ok' => false, 'msg' => $erros[0]]);

    //     $expDate = \DateTime::createFromFormat('m/y', $validade);
    //     if (!$expDate || $expDate < new \DateTime('first day of this month')) {
    //         $this->json(['ok' => false, 'msg' => 'Cartão vencido.']);
    //     }

    //     try {
    //         $paymentSvc = new PaymentService();

    //         if (!empty($gatewayToken)) {
    //             // CAMINHO RECOMENDADO: cartão já tokenizado no client
    //             $token    = $gatewayToken;
    //             $bandeira = $bandeiraFront ?: 'outros';
    //             $ultimos4 = strlen($ultimos4Front) === 4 ? $ultimos4Front : '0000';
    //         } else {
    //             // CAMINHO LEGADO / TRANSIÇÃO: backend faz tokenização server-side
    //             // (mantém compat com a view atual enquanto o SDK JS não está plugado)
    //             $numero = preg_replace('/\D/', '', (string)($_POST['numero_cartao'] ?? ''));
    //             $cvv    = preg_replace('/\D/', '', (string)($_POST['cvv_cartao'] ?? ''));

    //             if (strlen($numero) < 13 || !$this->luhnCheck($numero)) {
    //                 $this->json(['ok' => false, 'msg' => 'Número do cartão inválido.']);
    //             }
    //             if (strlen($cvv) < 3) {
    //                 $this->json(['ok' => false, 'msg' => 'CVV inválido.']);
    //             }

    //             $tokenizado = $paymentSvc->tokenizarCartao([
    //                 'numero'   => $numero,
    //                 'titular'  => $nome,
    //                 'validade' => $validade,
    //                 'cvv'      => $cvv,
    //             ]);

    //             $token    = $tokenizado['token'];
    //             $bandeira = $tokenizado['bandeira'] ?? $this->detectarBandeira($numero);
    //             $ultimos4 = $tokenizado['ultimos_4'];
    //         }

    //         $cartaoModel = new CartaoSalvo();
    //         $cartaoId = $cartaoModel->salvar([
    //             'cliente_id'   => $clienteId,
    //             'token'        => $token,
    //             'bandeira'     => $bandeira,
    //             'ultimos_4'    => $ultimos4,
    //             'nome_titular' => $nome,
    //             'apelido'      => $apelido,
    //             'validade'     => $validade,
    //             'principal'    => $principal,
    //         ]);

    //         if ($principal) {
    //             Session::set('checkout_payment_method', 'cartao');
    //         }
    //         Session::set('checkout_cartao_id', $cartaoId);

    //         $this->json(['ok' => true, 'redirect' => BASE_URL . '/checkout/payment']);

    //     } catch (\InvalidArgumentException $e) {
    //         $this->json(['ok' => false, 'msg' => $e->getMessage()]);
    //     } catch (\RuntimeException $e) {
    //         error_log('[CartaoAdd] tokenização: ' . $e->getMessage());
    //         $this->json(['ok' => false, 'msg' => 'Não foi possível validar o cartão. Verifique os dados e tente novamente.']);
    //     } catch (\Throwable $e) {
    //         error_log('[CartaoAdd] ' . $e->getMessage());
    //         $this->json(['ok' => false, 'msg' => 'Erro ao salvar. Tente novamente.']);
    //     }
    // }

    // public function paymentCardAddPost(): void {
    //     $this->requireLoginAjax();
    //     $this->verifyCsrf();
    //     $clienteId = (int)Session::get('cliente_id');
    
    //     $rateKey = 'card_add_post_' . md5($_SERVER['REMOTE_ADDR'] ?? '');
    //     if (SecurityHelper::rateLimitExceeded($rateKey, 10, 3600)) {
    //         $this->json(['ok' => false, 'msg' => 'Muitas tentativas. Aguarde alguns minutos.']);
    //     }
    
    //     $gatewayToken    = trim((string)($_POST['gateway_token'] ?? ''));
    //     $bandeiraFront   = SecurityHelper::sanitizeString(strtolower($_POST['bandeira'] ?? ''));
    //     $ultimos4Front   = preg_replace('/\D/', '', (string)($_POST['ultimos_4'] ?? ''));
    //     $nome     = SecurityHelper::sanitizeString(strtoupper($_POST['nome_cartao'] ?? ''));
    //     $apelido  = SecurityHelper::sanitizeString(strtoupper($_POST['apelido'] ?? ''));
    //     $validade = SecurityHelper::sanitizeString($_POST['validade_cartao'] ?? '');
    //     $principal= (int)($_POST['padrao'] ?? 0);
    //     $apelido  = !empty($apelido) ? $apelido : null;
    
    //     // ── NOVO: fail-closed quando MALGA_FRONT_REQUIRED=true ─────────
    //     $frontRequired = defined('MALGA_FRONT_REQUIRED') && MALGA_FRONT_REQUIRED === true;
    //     if ($frontRequired && empty($gatewayToken)) {
    //         if (class_exists('LogService')) {
    //             LogService::warning('[CartaoAdd] tentativa de tokenização server-side bloqueada (MALGA_FRONT_REQUIRED=true)', [
    //                 'cliente_id' => $clienteId,
    //                 'ip'         => $_SERVER['REMOTE_ADDR'] ?? null,
    //             ]);
    //         }
    //         $this->json(['ok' => false, 'msg' => 'Erro técnico. Atualize a página e tente de novo.']);
    //     }
    //     // ────────────────────────────────────────────────────────────────
    
    //     // O resto do método continua IGUAL ao patch da Etapa 2:
    //     //   - validações de nome, validade
    //     //   - if (gatewayToken) usa caminho front (recomendado)
    //     //   - else tokeniza server-side (só roda se MALGA_FRONT_REQUIRED=false)
    //     //   - salva via CartaoSalvo::salvar(...)
    
    //     $erros = [];
    //     if (mb_strlen($nome) < 4)                          $erros[] = 'Nome do titular obrigatório.';
    //     if (!preg_match('/^\d{2}\/\d{2}$/', $validade))    $erros[] = 'Validade inválida (MM/AA).';
    
    //     // Quando vem do front, nome/validade podem não estar preenchidos
    //     // (o SDK coleta tudo). Relaxa as validações nesse caso.
    //     if (!empty($gatewayToken)) {
    //         $erros = [];
    //         // O SDK confere validade e CVV no iframe. Trust the token.
    //         // (Se quiser dupla validação, exija que o front preencha esses 2
    //         // hidden inputs antes de submeter — mas é redundante.)
    //     }
    //     if ($erros) $this->json(['ok' => false, 'msg' => $erros[0]]);
    
    //     try {
    //         $paymentSvc = new PaymentService();
    
    //         if (!empty($gatewayToken)) {
    //             $token    = $gatewayToken;
    //             $bandeira = $bandeiraFront ?: 'outros';
    //             $ultimos4 = strlen($ultimos4Front) === 4 ? $ultimos4Front : '0000';
    //         } else {
    //             // Legado — só roda se MALGA_FRONT_REQUIRED=false
    //             $numero = preg_replace('/\D/', '', (string)($_POST['numero_cartao'] ?? ''));
    //             $cvv    = preg_replace('/\D/', '', (string)($_POST['cvv_cartao'] ?? ''));
    
    //             if (strlen($numero) < 13 || !$this->luhnCheck($numero)) {
    //                 $this->json(['ok' => false, 'msg' => 'Número do cartão inválido.']);
    //             }
    //             if (strlen($cvv) < 3) {
    //                 $this->json(['ok' => false, 'msg' => 'CVV inválido.']);
    //             }
    
    //             $tokenizado = $paymentSvc->tokenizarCartao([
    //                 'numero'   => $numero,
    //                 'titular'  => $nome,
    //                 'validade' => $validade,
    //                 'cvv'      => $cvv,
    //             ]);
    
    //             $token    = $tokenizado['token'];
    //             $bandeira = $tokenizado['bandeira'] ?? $this->detectarBandeira($numero);
    //             $ultimos4 = $tokenizado['ultimos_4'];
    //         }
    
    //         $cartaoModel = new CartaoSalvo();
    //         $cartaoId = $cartaoModel->salvar([
    //             'cliente_id'   => $clienteId,
    //             'token'        => $token,
    //             'bandeira'     => $bandeira,
    //             'ultimos_4'    => $ultimos4,
    //             // 'nome_titular' => $nome ?: 'Titular',
    //             'apelido'      => $apelido,
    //             // 'validade'     => $validade ?: '12/99', // o front não envia validade — vem implícita no token
    //             'principal'    => $principal,
    //         ]);
    
    //         if ($principal) Session::set('checkout_payment_method', 'cartao');
    //         Session::set('checkout_cartao_id', $cartaoId);
    
    //         $this->json(['ok' => true, 'redirect' => BASE_URL . '/checkout/payment']);
    
    //     } catch (\InvalidArgumentException $e) {
    //         $this->json(['ok' => false, 'msg' => $e->getMessage()]);
    //     } catch (\RuntimeException $e) {
    //         error_log('[CartaoAdd] tokenização: ' . $e->getMessage());
    //         $this->json(['ok' => false, 'msg' => 'Não foi possível validar o cartão. Verifique os dados.']);
    //     } catch (\Throwable $e) {
    //         error_log('[CartaoAdd] ' . $e->getMessage());
    //         $this->json(['ok' => false, 'msg' => 'Erro ao salvar. Tente novamente.']);
    //     }
    // }

    public function paymentCardAddPost(): void {
        $this->requireLoginAjax();
        $this->verifyCsrf();

        $clienteId = (int) Session::get('cliente_id');

        $rateKey = 'card_add_post_' . md5($_SERVER['REMOTE_ADDR'] ?? '');
        if (SecurityHelper::rateLimitExceeded($rateKey, 10, 3600)) {
            $this->json(['ok' => false, 'msg' => 'Muitas tentativas. Aguarde alguns minutos.']);
        }

        $tokenId   = trim((string) ($_POST['gateway_token'] ?? ''));
        $bandeira  = SecurityHelper::sanitizeString(strtolower($_POST['bandeira']  ?? ''));
        $ultimos4  = preg_replace('/\D/', '', (string) ($_POST['ultimos_4'] ?? ''));
        $apelido   = SecurityHelper::sanitizeString(strtoupper($_POST['apelido'] ?? ''));
        $principal = (int) ($_POST['padrao'] ?? 0);
        $apelido   = $apelido !== '' ? $apelido : null;

        if (empty($tokenId)) {
            $this->json(['ok' => false, 'msg' => 'Token do cartão ausente. Tente novamente.']);
        }
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $tokenId)) {
            $this->json(['ok' => false, 'msg' => 'Token inválido. Atualize a página e tente novamente.']);
        }
        if (strlen($ultimos4) !== 4) $ultimos4 = '0000';
        if (empty($bandeira))        $bandeira  = 'outros';

        // ── Dados do cliente para criar/buscar customer na Malga ──────────
        $db     = Database::getInstance()->getConnection();
        $stmt   = $db->prepare(
            "SELECT c.malga_customer_id, u.nome, u.email, c.telefone, c.cpf
            FROM clientes c
            LEFT JOIN usuarios u ON u.id = c.usuario_id
            WHERE c.id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $clienteId]);
        $cliente = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        $cardId          = null;
        $malgaCustomerId = $cliente['malga_customer_id'] ?? null;

        try {
            $malgaSvc = MalgaService::fromCodigo('malga');

            // ── Etapa 1: GET ou cria customer na Malga ────────────────────
            $dadosCliente = [
                'nome'      => $cliente['nome']     ?? 'Cliente',
                'email'     => $cliente['email']    ?? '',
                'telefone'  => $cliente['telefone'] ?? '',
                'documento' => preg_replace('/\D/', '', (string) ($cliente['cpf'] ?? '')),
            ];

            $customerId = $malgaSvc->buscarOuCriarCustomerPorCliente(
                $dadosCliente,
                $malgaCustomerId
            );

            // Persiste o customerId se for novo
            if ($customerId && $customerId !== $malgaCustomerId) {
                $db->prepare(
                    "UPDATE clientes SET malga_customer_id = :cid WHERE id = :id"
                )->execute([':cid' => $customerId, ':id' => $clienteId]);
            }

            // ── Etapa 2: cria card no vault da Malga ──────────────────────
            // POST /v1/cards com tokenId → retorna cardId permanente
            $vaultResult = $malgaSvc->criarCartaoVault($tokenId);
            $cardId = $vaultResult['cardId'];

            // Atualiza bandeira e last4 com os dados reais da Malga
            if (!empty($vaultResult['bandeira'])) $bandeira = $vaultResult['bandeira'];
            if (!empty($vaultResult['last4']))    $ultimos4  = $vaultResult['last4'];

            // ── Etapa 3: associa card ao customer ─────────────────────────
            // POST /v1/customers/{customerId}/cards → 204 No Content
            if (!empty($customerId)) {
                $malgaSvc->associarCartaoAoCustomer($customerId, $cardId);
            }

        } catch (\Throwable $e) {
            // Loga o erro real do vault para debug
            error_log('[CartaoAdd] vault falhou: ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine());
            if (class_exists('LogService')) {
                LogService::warning('[CartaoAdd] vault/customer falhou — usando tokenId como fallback', [
                    'cliente_id' => $clienteId,
                    'erro'       => $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine(),
                ]);
            }
            // Fallback: usa tokenId — o adapter tentará como sourceType:'token'.
            // ATENÇÃO: token expira em minutos. Se a compra não for finalizada
            // imediatamente, o próximo erro será expired_token. O correto é investigar
            // por que o vault falhou (ver error_log acima).
            $cardId = $tokenId;
        }

        // ── Salva no banco ────────────────────────────────────────────────
        try {
            $cartaoModel = new CartaoSalvo();
            $cartaoId    = $cartaoModel->salvar([
                'cliente_id'   => $clienteId,
                'token'        => $cardId,     // cardId permanente (ou tokenId como fallback)
                'bandeira'     => $bandeira,
                'ultimos_4'    => $ultimos4,
                'nome_titular' => 'Titular',
                'validade'     => '12/99',
                'apelido'      => $apelido,
                'principal'    => $principal,
            ]);

            Session::set('checkout_cartao_id', $cartaoId);
            if ($principal) Session::set('checkout_payment_method', 'cartao');

            LogService::info('[CartaoAdd] cartão salvo', [
                'cliente_id'  => $clienteId,
                'cartao_id'   => $cartaoId,
                'bandeira'    => $bandeira,
                'vault_ok'    => ($cardId !== $tokenId),
                'customer_id' => $malgaCustomerId ?? null,
            ]);

            $this->json(['ok' => true, 'redirect' => BASE_URL . '/checkout/payment']);

        } catch (\InvalidArgumentException $e) {
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        } catch (\Throwable $e) {
            error_log('[CartaoAdd] salvar: ' . $e->getMessage());
            $this->json(['ok' => false, 'msg' => 'Erro ao salvar o cartão. Tente novamente.']);
        }
    }    

    /** POST /checkout/payment/save-method */
    public function savePaymentMethod(): void {
        $this->requireLoginAjax();
        $this->verifyCsrf();
        $metodo   = SecurityHelper::sanitizeString($_POST['forma_pagamento'] ?? '');
        $cartaoId = (int)($_POST['cartao_id'] ?? 0);
        $obs      = SecurityHelper::sanitizeString(mb_substr($_POST['observacao'] ?? '', 0, 500));
        if (!in_array($metodo, ['pix','boleto','cartao'], true)) {
            $this->json(['ok' => false, 'msg' => 'Método inválido.']);
        }
        Session::set('checkout_payment_method', $metodo);
        if ($cartaoId > 0) Session::set('checkout_cartao_id', $cartaoId);
        if ($obs !== '') $this->state->setObservacao($obs);
        $this->json(['ok' => true]);
    }

    /** POST /checkout/payment/save-observation */
    public function saveObservation(): void {
        $this->requireLoginAjax();
        $this->verifyCsrf();
        $obs = SecurityHelper::sanitizeString(mb_substr($_POST['observacao'] ?? '', 0, 500));
        $this->state->setObservacao($obs);
        $this->json(['ok' => true]);
    }

    private function luhnCheck(string $numero): bool {
        $sum = 0; $dbl = false;
        for ($i = strlen($numero) - 1; $i >= 0; $i--) {
            $d = (int)$numero[$i];
            if ($dbl) { $d *= 2; if ($d > 9) $d -= 9; }
            $sum += $d; $dbl = !$dbl;
        }
        return $sum % 10 === 0;
    }

    private function detectarBandeira(string $numero): string {
        $p = [
            'amex'=>'/^3[47]/','diners'=>'/^(30[0-5]|36|38)/',
            'elo'=>'/^(4011|4312|4389|4514|4573|5041|5066|5067|5090|6277|6362|6363|6504|6505|6516|6550)/',
            'hipercard'=>'/^(606282|3841)/','mastercard'=>'/^(5[1-5]|2[2-7])/','visa'=>'/^4/'
        ];
        foreach ($p as $b => $r) { if (preg_match($r, $numero)) return $b; }
        return 'outros';
    }

    // ════════════════════════════════════════════════════════
    // ADICIONAR ao CheckoutController.php
    //
    // Método: addressSetPrincipal()
    // Rota:   POST /checkout/address/set-principal
    // ════════════════════════════════════════════════════════

    /**
     * POST /checkout/address/set-principal
     * Define um endereço como principal para o cliente logado.
     */
    public function addressSetPrincipal(): void {
        $this->requireLoginAjax();
        $this->verifyCsrf();

        $hash      = trim((string)($_POST['hash'] ?? ''));
        $clienteId = (int)Session::get('cliente_id');

        // Valida e decodifica o hash (HMAC — previne IDOR)
        $id = IdHasher::decode($hash, 'address');
        if (!$id) {
            $this->json(['ok' => false, 'msg' => 'Endereço inválido.']);
        }

        // Confirma que o endereço pertence ao cliente logado
        $endereco = $this->enderecoModel->findOwned($id, $clienteId);
        if (!$endereco) {
            $this->json(['ok' => false, 'msg' => 'Endereço não encontrado.']);
        }

        // Já é principal? Nada a fazer.
        if (!empty($endereco['principal'])) {
            $this->json(['ok' => true, 'msg' => 'Já é o endereço principal.']);
        }

        // Persiste
        $this->enderecoModel->tornarPrincipal($id, $clienteId);

        // Atualiza o estado do checkout se o endereço atual era diferente
        if ($this->state->getEnderecoId() === null) {
            $this->state->setEnderecoId($id);
        }

        $this->json(['ok' => true]);
    }

    /**
     * POST /checkout/address/select-by-hash
     *
     * Seleciona um endereço para este pedido (CheckoutState).
     * Se 'tornar_principal' === '1', também define como principal do cliente.
     */
    public function addressSelectByHash(): void {
        $this->requireLoginAjax();
        $this->verifyCsrf();
 
        $hash       = trim((string)($_POST['hash']            ?? ''));
        $setPrincipal = !empty($_POST['tornar_principal']);
        $clienteId  = (int)Session::get('cliente_id');
 
        // Valida hash (HMAC — previne IDOR)
        $id = IdHasher::decode($hash, 'address');
        if (!$id) {
            $this->json(['ok' => false, 'msg' => 'Endereço inválido.']);
        }
 
        // Confirma que o endereço pertence ao cliente
        $endereco = $this->enderecoModel->findOwned($id, $clienteId);
        if (!$endereco) {
            $this->json(['ok' => false, 'msg' => 'Endereço não encontrado.']);
        }
 
        // Seleciona para o checkout (invalida frete se o endereço mudou)
        $this->state->setEnderecoId($id);
 
        // Torna principal se solicitado
        if ($setPrincipal && empty($endereco['principal'])) {
            $this->enderecoModel->tornarPrincipal($id, $clienteId);
        }
 
        $this->json(['ok' => true]);
    }

    /**
     * Monta os dados do comprador no formato esperado pelo PaymentService.
     * Combina dados do cliente (banco) + endereço de entrega.
     *
     * Importante: o documento (CPF) é obrigatório pra Malga aceitar a charge.
     * Se você ainda não coleta CPF do cliente, vai falhar na primeira tentativa
     * real — adicione um campo de CPF no cadastro / no fluxo de identify.
     */
    private function buildCustomerData(int $clienteId, array $endereco): array
    {
        $db = Database::getInstance()->getConnection();

        // Busca dados do cliente (ajustar JOIN conforme schema real)
        $stmt = $db->prepare(
            "SELECT u.nome, u.email, c.cpf, c.telefone
            FROM clientes c
            LEFT JOIN usuarios u ON u.id = c.usuario_id
            WHERE c.id = ? LIMIT 1"
        );
        $stmt->execute([$clienteId]);
        $cliente = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        return [
            'nome'      => $cliente['nome']
                        ?? $endereco['nome_destinatario']
                        ?? 'Cliente',
            'email'     => $cliente['email'] ?? '',
            'telefone'  => $this->normalizarTelefone(
                            $cliente['telefone'] ?? $endereco['telefone'] ?? ''
                        ),
            'documento' => preg_replace('/\D/', '', (string)($cliente['cpf'] ?? '')),
            'endereco'  => [
                'logradouro'  => $endereco['logradouro']  ?? '',
                'numero'      => $endereco['numero']      ?? '',
                'complemento' => $endereco['complemento'] ?? '',
                'bairro'      => $endereco['bairro']      ?? '',
                'cidade'      => $endereco['cidade']      ?? '',
                'estado'      => $endereco['estado']      ?? '',
                'cep'         => $endereco['cep']         ?? '',
            ],
        ];
    }

    /**
     * Normaliza telefone pro formato +55DDDNNNNNNNNN exigido pela Malga.
     */
    private function normalizarTelefone(string $tel): string
    {
        $apenas = preg_replace('/\D/', '', $tel);
        if ($apenas === '') return '';
        if (strpos($apenas, '55') === 0 && strlen($apenas) >= 12) {
            return '+' . $apenas;
        }
        return '+55' . $apenas;
    }

    public function pixStatus(string $codigo): void
    {
        // Sanitiza código (8 hex uppercase)
        $codigo = strtoupper(preg_replace('/[^A-Z0-9]/', '', $codigo));
        if (strlen($codigo) !== 8) {
            $this->json(['status' => 'invalido'], 400);
        }
    
        $db = Database::getInstance()->getConnection();
    
        // Busca o pedido — não exige login pra não quebrar links em e-mail,
        // mas limita os campos retornados (sem expor dados sensíveis)
        $stmt = $db->prepare(
            "SELECT status_pagamento, pago_em, forma_pagamento
            FROM pedidos
            WHERE codigo = :cod
            LIMIT 1"
        );
        $stmt->execute([':cod' => $codigo]);
        $pedido = $stmt->fetch(PDO::FETCH_ASSOC);
    
        if (!$pedido) {
            $this->json(['status' => 'nao_encontrado'], 404);
        }
    
        $this->json([
            'status'  => $pedido['status_pagamento'],
            'pago_em' => $pedido['pago_em'],
        ]);
    }
}