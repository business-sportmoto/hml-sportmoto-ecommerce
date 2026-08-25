<?php
// app/controllers/AppCheckoutController.php
// Checkout do app.
//
// Nada de regra de negócio nova aqui. O estado vive no MESMO CheckoutState que
// a web usa (funciona graças à ponte de sessão), os totais saem de
// CheckoutTotais — a fonte única compartilhada com CheckoutController — e a
// criação do pedido é o process() da web, invocado por AppCheckoutRunner.
//
// A consequência prática: um cupom novo, uma promoção nova ou uma mudança de
// regra de frete passam a valer nos dois clientes no mesmo deploy, sem ninguém
// lembrar de replicar.
//
// Este controller é, portanto, quase todo tradução: recebe JSON, escreve no
// CheckoutState, devolve o envelope da API.

class AppCheckoutController extends AppApiController
{
    private const METODOS = ['pix', 'boleto', 'cartao'];

    /* =================================================================
       ESTADO
       ================================================================= */

    /**
     * GET /api/app/v1/checkout
     * Tudo o que a tela de checkout precisa, numa requisição.
     */
    public function estado(): void
    {
        $this->bootCliente();

        $this->ok(['checkout' => $this->montarEstado()]);
    }

    /**
     * GET /api/app/v1/checkout/resumo
     * Só os totais — para a tela reagir a mudanças sem recarregar o resto.
     */
    public function resumo(): void
    {
        $this->bootCliente();

        $this->ok(['totais' => CheckoutPresenter::totais($this->conta())]);
    }

    /* =================================================================
       ENDEREÇO
       ================================================================= */

    /**
     * POST /api/app/v1/checkout/endereco    Corpo: { endereco_id }
     */
    public function definirEndereco(): void
    {
        $this->bootCliente();
        $corpo = $this->exigirCampos(['endereco_id']);

        $enderecoId = (int)$corpo['endereco_id'];

        // findOwned antes de gravar: sem isso um id alheio entraria no estado
        // e só quebraria lá na frente, dentro do process().
        if (!(new Endereco())->findOwned($enderecoId, (int)$this->clienteId)) {
            $this->falha(404, 'nao_encontrado', 'Endereço não encontrado.');
        }

        // setEnderecoId() já descarta o frete escolhido quando o endereço muda
        // — o CEP é outro, e manter a cotação antiga cobraria o valor errado.
        (new CheckoutState())->setEnderecoId($enderecoId);

        $this->ok(['checkout' => $this->montarEstado()]);
    }

    /* =================================================================
       FRETE
       ================================================================= */

    /**
     * GET /api/app/v1/checkout/frete
     * Cota o carrinho inteiro para o endereço escolhido.
     */
    public function opcoesFrete(): void
    {
        $this->bootCliente();

        $state    = new CheckoutState();
        $endereco = $this->enderecoDoEstado($state);

        if (!$endereco) {
            $this->falha(422, 'endereco_ausente', 'Escolha um endereço de entrega antes do frete.');
        }

        $itens = (new Cart())->getItensComDimensoes((int)$this->clienteId);
        if (!$itens) {
            $this->falha(422, 'carrinho_vazio', 'Seu carrinho está vazio.');
        }

        try {
            $res = (new FreteService())->calcular(
                (string)$endereco['cep'],
                $itens,
                $state->getCarrinhoId() ?? 0
            );
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'frete_checkout']);
            $this->falha(502, 'falha_frete', 'Não foi possível calcular o frete agora.');
        }

        if (empty($res['ok'])) {
            $this->falha(422, 'falha_frete', (string)($res['erro'] ?? 'Não foi possível calcular o frete.'));
        }

        $this->ok([
            'cep'    => EnderecoPresenter::um($endereco)['cep'],
            'opcoes' => CheckoutPresenter::opcoesFrete($res['opcoes']),
        ]);
    }

    /**
     * POST /api/app/v1/checkout/frete    Corpo: { codigo }
     *
     * Recebe só o CÓDIGO e recota no servidor para achar o valor. Aceitar o
     * preço vindo do cliente deixaria qualquer um escolher o próprio frete.
     */
    public function definirFrete(): void
    {
        $this->bootCliente();
        $corpo = $this->exigirCampos(['codigo']);

        $codigo   = trim((string)$corpo['codigo']);
        $state    = new CheckoutState();
        $endereco = $this->enderecoDoEstado($state);

        if (!$endereco) {
            $this->falha(422, 'endereco_ausente', 'Escolha um endereço de entrega antes do frete.');
        }

        $itens = (new Cart())->getItensComDimensoes((int)$this->clienteId);
        if (!$itens) {
            $this->falha(422, 'carrinho_vazio', 'Seu carrinho está vazio.');
        }

        try {
            $res = (new FreteService())->calcular(
                (string)$endereco['cep'],
                $itens,
                $state->getCarrinhoId() ?? 0
            );
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'definir_frete']);
            $this->falha(502, 'falha_frete', 'Não foi possível calcular o frete agora.');
        }

        $escolhida = null;
        foreach (($res['opcoes'] ?? []) as $o) {
            if ((string)($o['id'] ?? '') === $codigo) {
                $escolhida = $o;
                break;
            }
        }

        if (!$escolhida) {
            // A opção sumiu entre listar e escolher (mudou o carrinho, a
            // transportadora saiu do ar). Melhor recusar do que gravar um
            // frete que a loja não vai conseguir postar.
            $this->falha(422, 'frete_indisponivel', 'Essa opção de entrega não está mais disponível. Escolha outra.');
        }

        $state->setFrete([
            'codigo'    => (string)$escolhida['id'],
            'descricao' => (string)$escolhida['nome'],
            'valor'     => (float)$escolhida['valor'],
            'prazo'     => (int)$escolhida['prazo'],
            'carrier'   => (string)($escolhida['carrier'] ?? ''),
            'poster'    => (string)($escolhida['poster'] ?? ''),
            'tag'       => (string)($escolhida['tag'] ?? ''),
        ]);

        $this->ok(['checkout' => $this->montarEstado()]);
    }

    /* =================================================================
       CUPOM
       ================================================================= */

    /**
     * POST /api/app/v1/checkout/cupom    Corpo: { codigo }
     *
     * Grava nos MESMOS dois lugares que CouponController::aplicar() usa:
     * CheckoutState (para a tela) e Session['cupom_aplicado'] (que é de onde
     * process() lê na finalização). Gravar só num dos dois faria o desconto
     * aparecer na tela e sumir no pedido.
     */
    public function aplicarCupom(): void
    {
        $this->bootCliente();
        $corpo = $this->exigirCampos(['codigo']);

        $codigo = mb_strtoupper(trim((string)$corpo['codigo']));
        if ($codigo === '') {
            $this->falha(422, 'dados_invalidos', 'Informe o código do cupom.');
        }

        $cart     = new Cart();
        $state    = new CheckoutState();
        $itens    = $cart->getItensParaCupom((int)$this->clienteId);
        $totais   = $cart->calcularTotais($itens);
        $subtotal = (float)($totais['subtotal'] ?? 0);
        $frete    = (float)($state->getFrete()['valor'] ?? 0);

        $res = (new CouponService())->validar(
            $codigo, $itens, $subtotal, $frete, (int)$this->clienteId, ['origem' => 'app']
        );

        if (empty($res['ok'])) {
            $this->falha(422, (string)($res['codigo_erro'] ?? 'cupom_invalido'), (string)$res['msg']);
        }

        $state->setCupom(
            $res['cupom']['codigo'],
            (float)$res['desconto'] + (float)$res['frete_desconto'],
            (string)$res['regra']
        );

        Session::set('cupom_aplicado', [
            'codigo'         => $res['cupom']['codigo'],
            'cupom_id'       => $res['cupom']['id'],
            'desconto'       => $res['desconto'],
            'frete_desconto' => $res['frete_desconto'],
            'tipo'           => $res['regra'],
            'aplicado_em'    => time(),
        ]);

        $this->ok(['checkout' => $this->montarEstado()]);
    }

    /**
     * DELETE /api/app/v1/checkout/cupom
     */
    public function removerCupom(): void
    {
        $this->bootCliente();

        Session::remove('cupom_aplicado');
        (new CheckoutState())->removerCupom();

        $this->ok(['checkout' => $this->montarEstado()]);
    }

    /* =================================================================
       CRÉDITO
       ================================================================= */

    /**
     * POST /api/app/v1/checkout/credito    Corpo: { valor }
     *
     * O crédito é por USUÁRIO, não por cliente — CreditoService trabalha com
     * usuario_id, e confundir os dois debitaria da conta errada.
     */
    public function aplicarCredito(): void
    {
        $this->bootCliente();
        $corpo = $this->exigirCampos(['valor']);

        $valor = round((float)str_replace(',', '.', (string)$corpo['valor']), 2);
        if ($valor <= 0) {
            $this->falha(422, 'dados_invalidos', 'Informe um valor maior que zero.');
        }

        $usuarioId = $this->usuarioIdDoCliente();
        if (!$usuarioId) {
            $this->falha(422, 'sem_credito', 'Não foi possível identificar seu saldo.');
        }

        $credito = new CreditoService();
        $saldo   = $credito->getSaldoDisponivel($usuarioId);

        if ($saldo <= 0) {
            $this->falha(422, 'sem_saldo', 'Você não tem crédito disponível.');
        }

        // Guarda o pedido do cliente; CheckoutTotais limita ao saldo e ao total
        // na hora de fechar a conta. Limitar aqui também evitaria uma ida ao
        // banco, mas duplicaria a regra em dois lugares.
        Session::set('checkout_credito', min($valor, $saldo));

        $this->ok(['checkout' => $this->montarEstado()]);
    }

    /**
     * DELETE /api/app/v1/checkout/credito
     */
    public function removerCredito(): void
    {
        $this->bootCliente();

        Session::remove('checkout_credito');

        $this->ok(['checkout' => $this->montarEstado()]);
    }

    /* =================================================================
       PAGAMENTO
       ================================================================= */

    /**
     * POST /api/app/v1/checkout/pagamento
     * Corpo: { metodo, parcelas?, cartao_id? }
     */
    public function definirPagamento(): void
    {
        $this->bootCliente();
        $corpo = $this->exigirCampos(['metodo']);

        $metodo = (string)$corpo['metodo'];
        if (!in_array($metodo, self::METODOS, true)) {
            $this->falha(422, 'metodo_invalido', 'Forma de pagamento inválida.');
        }

        Session::set('checkout_payment_method', $metodo);

        if ($metodo === 'cartao') {
            $cartaoId = (int)($corpo['cartao_id'] ?? 0);
            if ($cartaoId <= 0) {
                $this->falha(422, 'cartao_ausente', 'Escolha um cartão para continuar.');
            }

            if (!(new CartaoSalvo())->findOwned($cartaoId, (int)$this->clienteId)) {
                $this->falha(404, 'nao_encontrado', 'Cartão não encontrado.');
            }

            Session::set('checkout_cartao_id', $cartaoId);
            Session::set('checkout_parcelas', max(1, (int)($corpo['parcelas'] ?? 1)));
        } else {
            // PIX e boleto são à vista. Deixar parcelas antigas na sessão faria
            // process() gravar "12x" num pedido PIX.
            Session::remove('checkout_cartao_id');
            Session::set('checkout_parcelas', 1);
        }

        if (isset($corpo['observacao'])) {
            (new CheckoutState())->setObservacao((string)$corpo['observacao']);
        }

        $this->ok(['checkout' => $this->montarEstado()]);
    }

    /**
     * GET /api/app/v1/conta/cartoes
     */
    public function cartoes(): void
    {
        $this->bootCliente();
        $this->liberarSessao();

        try {
            $rows = (new CartaoSalvo())->listarPorCliente((int)$this->clienteId);
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'listar_cartoes']);
            $rows = [];
        }

        $this->ok(['cartoes' => array_values(array_map(
            static fn(array $c) => CheckoutPresenter::cartao($c),
            $rows
        ))]);
    }

    /**
     * DELETE /api/app/v1/conta/cartoes/{id}
     */
    public function removerCartao(string $id = '0'): void
    {
        $this->bootCliente();

        $ok = (new CartaoSalvo())->desativar((int)$id, (int)$this->clienteId);
        if (!$ok) {
            $this->falha(404, 'nao_encontrado', 'Cartão não encontrado.');
        }

        // Se era o cartão escolhido para esta compra, a escolha morre junto.
        if ((int)Session::get('checkout_cartao_id') === (int)$id) {
            Session::remove('checkout_cartao_id');
        }

        $this->ok(['removido' => true]);
    }

    /* =================================================================
       FINALIZAÇÃO
       ================================================================= */

    /**
     * POST /api/app/v1/checkout/finalizar
     * Header obrigatório: Idempotency-Key
     *
     * O header é EXIGIDO, não opcional. Num app, perder a resposta de um POST é
     * rotina — a rede cai, o usuário toca de novo — e sem a chave o segundo
     * toque vira um segundo pedido, uma segunda cobrança e uma segunda baixa de
     * estoque. Aceitar a chamada sem chave seria oferecer o caminho errado.
     */
    public function finalizar(): void
    {
        $this->bootCliente();

        $chave = $this->idempotencyKey();
        if (!$chave) {
            $this->falha(
                400,
                'idempotency_key_ausente',
                'Envie o header Idempotency-Key para finalizar o pedido.'
            );
        }

        $corpo         = $this->corpo();
        $dispositivoId = (int)$this->dispositivo['id'];

        try {
            $reserva = AppIdempotencia::reservar(
                $this->db(), $dispositivoId, $chave, 'checkout/finalizar', $corpo
            );
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'idempotencia_reservar']);
            $this->falha(500, 'falha_idempotencia', 'Não foi possível iniciar a finalização.');
        }

        // Já finalizado com esta chave: devolve a MESMA resposta. O app não
        // distingue de um sucesso normal, que é exatamente o que se quer.
        if ($reserva['estado'] === 'concluido') {
            $this->ok($reserva['resposta'] ?? [], (int)($reserva['status_http'] ?? 200));
        }

        if ($reserva['estado'] === 'em_andamento') {
            $this->falha(
                409,
                'finalizacao_em_andamento',
                'Seu pedido já está sendo processado. Aguarde alguns segundos.'
            );
        }

        if ($reserva['estado'] === 'conflito') {
            $this->falha(
                422,
                'idempotency_key_reutilizada',
                'Esta chave já foi usada para outro pedido. Gere uma nova.'
            );
        }

        // ── Monta o que process() espera, igual a finalize() da web ────────
        $state    = new CheckoutState();
        $endereco = $this->enderecoDoEstado($state);
        $frete    = $state->getFrete();
        $metodo   = (string)Session::get('checkout_payment_method', '');

        if (!$endereco || !$frete || !in_array($metodo, self::METODOS, true)) {
            AppIdempotencia::falhar($this->db(), $dispositivoId, $chave);
            $this->falha(422, 'checkout_incompleto', 'Escolha endereço, entrega e forma de pagamento antes de finalizar.');
        }

        // Item que a loja não consegue precificar não vira pedido. Deixar
        // passar criaria uma venda de R$ 0,00 — sempre pior do que pedir ao
        // cliente que escolha a variação de novo.
        $conta = $this->conta();
        if (!empty($conta['itens_sem_preco'])) {
            AppIdempotencia::falhar($this->db(), $dispositivoId, $chave);
            $this->falha(
                422,
                'item_sem_preco',
                'Um item do carrinho está sem preço definido. Remova e adicione novamente escolhendo a variação.',
                ['itens' => $conta['itens_sem_preco']]
            );
        }

        // O endereço pode ter vindo do "principal" implícito; process() lê de
        // $_POST['endereco_entrega_id'], então gravamos a escolha antes.
        if ($state->getEnderecoId() !== (int)$endereco['id']) {
            $state->setEnderecoId((int)$endereco['id']);
            // setEnderecoId descarta o frete — devolvemos o que já estava
            // escolhido, que foi cotado para este mesmo CEP.
            $state->setFrete($frete);
        }

        $post = [
            'cliente_id'          => (int)$this->clienteId,
            'endereco_entrega_id' => (int)$endereco['id'],
            'forma_pagamento'     => $metodo,
            'parcelas'            => max(1, (int)Session::get('checkout_parcelas', 1)),
            'frete'               => $frete,
            'observacao'          => $state->getObservacao(),
            'salvar_cartao'       => 0,
        ];

        if ($metodo === 'cartao') {
            $cartaoId = (int)Session::get('checkout_cartao_id', 0);
            if ($cartaoId <= 0) {
                AppIdempotencia::falhar($this->db(), $dispositivoId, $chave);
                $this->falha(422, 'cartao_ausente', 'Escolha um cartão para continuar.');
            }
            $post['cartao_salvo_id'] = $cartaoId;
        }

        try {
            $resultado = AppCheckoutRunner::finalizar($post);
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'finalizar_pedido', 'chave' => $chave]);
            AppIdempotencia::falhar($this->db(), $dispositivoId, $chave);
            $this->falha(500, 'falha_finalizar', 'Não foi possível finalizar seu pedido. Nada foi cobrado.');
        }

        // process() recusou por validação (carrinho vazio, cartão sem token).
        // Nada foi criado, então a chave volta a valer.
        if (empty($resultado['ok'])) {
            AppIdempotencia::falhar($this->db(), $dispositivoId, $chave);
            $this->falha(422, 'checkout_recusado', (string)($resultado['msg'] ?? 'Não foi possível finalizar o pedido.'));
        }

        $codigo = (string)($resultado['pedido']['codigo'] ?? '');
        $dados  = $this->pagamentoDoPedido($codigo);

        AppIdempotencia::concluir(
            $this->db(), $dispositivoId, $chave, $dados, 201,
            isset($resultado['pedido']['id']) ? (int)$resultado['pedido']['id'] : null
        );

        $this->ok($dados, 201);
    }

    /**
     * GET /api/app/v1/pedidos/{codigo}/pagamento
     *
     * O app consulta enquanto a tela de PIX está aberta. O webhook da Malga
     * costuma chegar antes, mas depender só dele deixaria o cliente olhando um
     * QR Code já pago.
     */
    public function statusPagamento(string $codigo = ''): void
    {
        $this->bootCliente();
        $this->liberarSessao();

        $this->ok($this->pagamentoDoPedido(trim($codigo)));
    }

    /**
     * Bloco de pagamento de um pedido do cliente — o mesmo formato na resposta
     * do finalizar e na consulta de status, para a tela não ter dois parsers.
     */
    private function pagamentoDoPedido(string $codigo): array
    {
        $pedido = (new Order())->findByCode($codigo, (int)$this->clienteId);

        if (!$pedido) {
            $this->falha(404, 'nao_encontrado', 'Pedido não encontrado.');
        }

        return ['pagamento' => PagamentoPresenter::de($pedido, $this->contexto())];
    }

    /* =================================================================
       INTERNOS
       ================================================================= */

    private function conta(): array
    {
        $cart  = new Cart();
        $state = new CheckoutState();

        return CheckoutTotais::calcular([
            'cliente_id'      => (int)$this->clienteId,
            'usuario_id'      => $this->usuarioIdDoCliente(),
            'itens'           => $cart->getItensComVariacoes((int)$this->clienteId),
            'itens_cupom'     => $cart->getItensParaCupom((int)$this->clienteId),
            'frete_valor'     => (float)($state->getFrete()['valor'] ?? 0),
            'cupom'           => Session::get('cupom_aplicado'),
            'credito'         => (float)Session::get('checkout_credito', 0),
            'primeira_compra' => null,
            'score'           => (int)(Session::get('cliente_score') ?? 0),
            'origem'          => 'app',
        ]);
    }

    private function montarEstado(): array
    {
        $cart  = new Cart();
        $state = new CheckoutState();
        $itens = $cart->getItensComVariacoes((int)$this->clienteId);

        $usuarioId = $this->usuarioIdDoCliente();

        $conta = CheckoutTotais::calcular([
            'cliente_id'      => (int)$this->clienteId,
            'usuario_id'      => $usuarioId,
            'itens'           => $itens,
            'itens_cupom'     => $cart->getItensParaCupom((int)$this->clienteId),
            'frete_valor'     => (float)($state->getFrete()['valor'] ?? 0),
            'cupom'           => Session::get('cupom_aplicado'),
            'credito'         => (float)Session::get('checkout_credito', 0),
            'primeira_compra' => null,
            'score'           => (int)(Session::get('cliente_score') ?? 0),
            'origem'          => 'app',
        ]);

        $saldo = $usuarioId ? (new CreditoService())->getSaldoDisponivel($usuarioId) : 0.0;

        return CheckoutPresenter::estado(
            $conta,
            $this->enderecoDoEstado($state),
            $state->getFrete(),
            [
                'tem_itens'     => !empty($itens),
                'itens_total'   => (int)array_sum(array_map(static fn($i) => (int)$i['quantidade'], $itens)),
                'metodo'        => Session::get('checkout_payment_method'),
                'parcelas'      => Session::get('checkout_parcelas', 1),
                'cartao_id'     => Session::get('checkout_cartao_id'),
                'cupom'         => Session::get('cupom_aplicado'),
                'credito_saldo' => $saldo,
                'observacao'    => $state->getObservacao() ?: null,
            ],
            $this->contexto()
        );
    }

    private function enderecoDoEstado(CheckoutState $state): ?array
    {
        $id = $state->getEnderecoId();
        if ($id) {
            $end = (new Endereco())->findOwned($id, (int)$this->clienteId);
            if ($end) {
                return $end;
            }
        }

        // Sem escolha explícita, o principal — é o mesmo padrão do checkout da
        // web e o que a barra de entrega do app já mostra. Sem isso o cliente
        // teria que reescolher um endereço que já é o dele.
        return (new Endereco())->principal((int)$this->clienteId);
    }

    /** O crédito é por usuário; o resto do checkout, por cliente. */
    private function usuarioIdDoCliente(): ?int
    {
        try {
            $st = $this->db()->prepare("SELECT usuario_id FROM clientes WHERE id = :c LIMIT 1");
            $st->execute([':c' => (int)$this->clienteId]);
            $id = (int)($st->fetchColumn() ?: 0);
        } catch (\Throwable $e) {
            return null;
        }

        return $id ?: null;
    }
}
