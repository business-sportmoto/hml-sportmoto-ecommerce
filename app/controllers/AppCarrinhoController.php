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
}
