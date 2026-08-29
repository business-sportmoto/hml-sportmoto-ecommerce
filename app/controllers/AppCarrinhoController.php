<?php
// app/controllers/AppCarrinhoController.php
// Carrinho do app.
//
// Funciona logado ou anônimo: a ponte de sessão dá ao dispositivo um
// `sessao_id` estável, e o carrinho de visitante vive no banco como o da web.
// Ao logar, AppAuthService chama o CartMergeService e os dois se juntam.
//
// Toda operação de escrita devolve o CARRINHO INTEIRO, não só um "ok". É de
// propósito: o app atualiza a tela com update otimista e precisa reconciliar
// com o servidor num único passo — totais, cupom e frete mudam junto com o
// item, e uma segunda chamada para buscar isso deixaria a tela inconsistente
// no intervalo.

class AppCarrinhoController extends AppApiController
{
    /**
     * GET /api/app/v1/carrinho
     */
    public function index(): void
    {
        $this->bootPublico();
        $this->responderCarrinho();
    }

    /**
     * GET /api/app/v1/carrinho/contador
     * Resposta mínima para o badge da aba — chamada com frequência.
     */
    public function contador(): void
    {
        $this->bootPublico();
        $quantidade = (new AppCartService($this->db()))->contar();
        $this->liberarSessao();

        $this->ok(['quantidade' => $quantidade]);
    }

    /**
     * POST /api/app/v1/carrinho/itens
     * Corpo: { produto_id, sku_id?, quantidade? }
     */
    public function adicionar(): void
    {
        $this->bootPublico();
        $corpo = $this->exigirCampos(['produto_id']);

        $servico = new AppCartService($this->db());
        $r = $servico->adicionar(
            (int)$corpo['produto_id'],
            !empty($corpo['sku_id']) ? (int)$corpo['sku_id'] : null,
            (int)($corpo['quantidade'] ?? 1)
        );

        if (!$r['ok']) {
            $this->falha(422, 'nao_adicionado', $r['msg'] ?? 'Não foi possível adicionar o item.');
        }

        AppLog::info('Item adicionado ao carrinho pelo app', [
            'produto_id' => (int)$corpo['produto_id'],
            'sku_id'     => $corpo['sku_id'] ?? null,
        ]);

        $this->responderCarrinho(['item_id' => $r['item_id'], 'adicionado' => true], 201);
    }

    /**
     * PATCH /api/app/v1/carrinho/itens/{id}
     * Corpo: { quantidade }
     */
    public function atualizar(string $id = '0'): void
    {
        $this->bootPublico();
        $corpo = $this->exigirCampos(['quantidade']);

        $r = (new AppCartService($this->db()))->atualizarQuantidade((int)$id, (int)$corpo['quantidade']);

        if (!$r['ok']) {
            // O teto de estoque vai no meta para o app ajustar o stepper em vez
            // de só mostrar um erro e deixar o número errado na tela.
            $this->falha(422, 'quantidade_invalida', $r['msg'] ?? 'Não foi possível atualizar.', [
                'quantidade_maxima' => $r['quantidade_maxima'] ?? null,
            ]);
        }

        $this->responderCarrinho(['atualizado' => true]);
    }

    /**
     * DELETE /api/app/v1/carrinho/itens/{id}
     */
    public function remover(string $id = '0'): void
    {
        $this->bootPublico();

        $r = (new AppCartService($this->db()))->remover((int)$id);

        if (!$r['ok']) {
            $this->falha(404, 'item_nao_encontrado', $r['msg'] ?? 'Item não encontrado.');
        }

        $this->responderCarrinho(['removido' => true]);
    }

    /**
     * POST /api/app/v1/carrinho/cupom   Corpo: { codigo }
     */
    public function aplicarCupom(): void
    {
        $this->bootPublico();
        $corpo = $this->exigirCampos(['codigo']);

        $servico = new AppCartService($this->db());
        $carrinhoId = $servico->carrinhoId();

        if (!$carrinhoId) {
            $this->falha(422, 'carrinho_vazio', 'Adicione itens antes de aplicar um cupom.');
        }

        try {
            $r = (new Cart())->applyCupom(
                $carrinhoId,
                strtoupper(trim((string)$corpo['codigo'])),
                (int)($this->clienteId ?? 0)
            );
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'aplicar_cupom']);
            $this->falha(500, 'falha_cupom', 'Não foi possível validar o cupom agora.');
        }

        if (empty($r['ok'])) {
            $this->falha(422, 'cupom_invalido', $r['msg'] ?? 'Cupom inválido.');
        }

        $this->responderCarrinho(['cupom_aplicado' => true]);
    }

    /**
     * DELETE /api/app/v1/carrinho/cupom
     */
    public function removerCupom(): void
    {
        $this->bootPublico();

        $carrinhoId = (new AppCartService($this->db()))->carrinhoId();
        if ($carrinhoId) {
            (new Cart())->removeCupom($carrinhoId);
        }

        $this->responderCarrinho(['cupom_removido' => true]);
    }

    /* ================================================================= */

    /**
     * Resposta padrão de toda operação: o carrinho completo.
     * `$extra` carrega o que foi feito, para o app confirmar a ação.
     */
    private function responderCarrinho(array $extra = [], int $status = 200): void
    {
        $servico = new AppCartService($this->db());
        $carrinhoId = $servico->carrinhoId();

        if (!$carrinhoId) {
            $this->liberarSessao();
            $this->ok(array_merge($extra, [
                'itens'  => [],
                'vazio'  => true,
                'totais' => [
                    'quantidade' => 0,
                    'subtotal' => '0.00', 'desconto' => '0.00',
                    'frete' => '0.00',    'total' => '0.00',
                    'frete_gratis' => true, 'parcelamento' => null,
                ],
                'cupom' => null, 'frete' => null, 'avisos' => [],
            ]), $status);
        }

        $totais = (new Cart())->getTotals($carrinhoId);
        $ctx = $this->contexto();
        $this->liberarSessao();

        $this->ok(array_merge($extra, CartPresenter::montar($totais, $ctx)), $status);
    }

    /* =================================================================
       CARRINHO COMPARTILHADO
       ================================================================= */

    /**
     * POST /api/app/v1/carrinho/compartilhar
     * Corpo: { nome? }  — `nome` só para visitante, que não tem conta a exibir.
     *
     * Congela o carrinho atual num link de 7 dias. A URL aponta para o site
     * porque é ela que a pessoa vai colar no WhatsApp: quem tiver o app abre
     * pelo deep link, quem não tiver abre no navegador.
     */
    public function compartilhar(): void
    {
        $this->bootPublico();

        $carrinhoId = (new AppCartService($this->db()))->carrinhoId();
        if (!$carrinhoId) {
            $this->falha(422, 'carrinho_vazio', 'Seu carrinho está vazio.');
        }

        $r = (new CarrinhoCompartilhadoService())->criar(
            $carrinhoId,
            $this->usuarioId,
            trim((string)$this->campo('nome', ''))
        );

        $this->liberarSessao();

        if (empty($r['ok'])) {
            $this->falha(422, 'carrinho_vazio', (string)($r['erro'] ?? 'Não foi possível compartilhar.'));
        }

        $this->ok([
            'token'     => $r['token'],
            'url'       => $r['url'],
            'expira_em' => date(DATE_ATOM, strtotime((string)$r['expira_em'])),
        ], 201);
    }

    /**
     * GET /api/app/v1/carrinho/compartilhado/{token}
     *
     * Só a visão do snapshot — nada é copiado aqui. Ver antes de decidir é o
     * ponto: o carrinho de quem abre não pode mudar por abrir um link.
     */
    public function verCompartilhado(string $token = ''): void
    {
        $this->bootPublico();

        $servico = new CarrinhoCompartilhadoService();
        $c = $servico->abrir($token);

        if (!$c) {
            $this->liberarSessao();
            $this->falha(404, 'link_invalido', 'Este link expirou ou não existe mais.');
        }

        $servico->registrarVisualizacao($token, $this->clienteId, $this->ipReal());

        $ctx = $this->contexto();

        // Quantos itens quem abriu já tem: é o que decide se o app precisa
        // perguntar "somar ou substituir?" antes de copiar.
        $meuCarrinho = (new AppCartService($this->db()))->contar();

        $this->liberarSessao();

        $this->ok([
            'compartilhado'  => CarrinhoCompartilhadoPresenter::montar($c, $ctx),
            'meus_itens'     => $meuCarrinho,
            'precisa_decidir'=> $meuCarrinho > 0,
        ]);
    }

    /**
     * POST /api/app/v1/carrinho/compartilhado/{token}/copiar
     * Corpo: { estrategia: "mesclar" | "substituir" }
     *
     * A loja pergunta "adicionar ou substituir?" com um confirm() e, se o modo
     * vier em branco, devolve um erro pedindo para escolher. Aqui a escolha é
     * explícita no corpo e o padrão é `mesclar` — o que nunca descarta o que a
     * pessoa já tinha.
     *
     * Preço e estoque são reconferidos na cópia: o link vale uma semana e
     * ninguém deve congelar uma promoção guardando a URL.
     */
    public function copiarCompartilhado(string $token = ''): void
    {
        $this->bootPublico();

        $estrategia = (string)$this->campo('estrategia', 'mesclar');

        // criar: true — quem chega por link normalmente ainda não tem carrinho.
        $carrinhoId = (new AppCartService($this->db()))->carrinhoId(true);
        if (!$carrinhoId) {
            $this->falha(500, 'sem_carrinho', 'Não foi possível preparar seu carrinho.');
        }

        $r = (new CarrinhoCompartilhadoService())->copiar(
            $token,
            $carrinhoId,
            $estrategia,
            $this->clienteId,
            $this->ipReal()
        );

        if (empty($r['ok'])) {
            $this->falha(404, 'link_invalido', (string)($r['erro'] ?? 'Link inválido.'));
        }

        AppLog::info('Carrinho compartilhado copiado no app', [
            'adicionados' => $r['adicionados'],
            'ignorados'   => $r['ignorados'],
            'estrategia'  => $estrategia,
        ]);

        // Devolve o carrinho inteiro, como toda escrita deste controller.
        $this->responderCarrinho([
            'copiados'        => $r['adicionados'],
            'ignorados'       => $r['ignorados'],
            'itens_ignorados' => $r['itens_ignorados'],
        ]);
    }
}
