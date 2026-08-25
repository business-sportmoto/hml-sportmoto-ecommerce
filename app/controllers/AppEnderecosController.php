<?php
// app/controllers/AppEnderecosController.php
// Endereços de entrega + o bloco de entrega do cabeçalho.
//
// A barra de endereço do topo mostra o endereço PRINCIPAL — não um "endereço
// selecionado" separado. Foi decisão deliberada: o checkout da loja já parte do
// principal (Endereco::principal()), então inventar um segundo conceito de
// "endereço ativo" só para o app criaria duas verdades sobre para onde a compra
// vai. Trocar pelo cabeçalho chama tornarPrincipal() e a web enxerga a mesma
// escolha na hora.

class AppEnderecosController extends AppApiController
{
    /**
     * GET /api/app/v1/conta/enderecos
     */
    public function index(): void
    {
        $this->bootCliente();
        $this->liberarSessao();

        try {
            $rows = (new Endereco())->listarPorCliente((int)$this->clienteId);
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'listar_enderecos']);
            $this->falha(500, 'falha_enderecos', 'Não foi possível carregar seus endereços.');
        }

        $this->ok(['enderecos' => EnderecoPresenter::colecao($rows)]);
    }

    /**
     * POST /api/app/v1/conta/enderecos/{id}/principal
     */
    public function tornarPrincipal(string $id = '0'): void
    {
        $this->bootCliente();
        $this->liberarSessao();

        $modelo   = new Endereco();
        $clienteId = (int)$this->clienteId;

        // findOwned antes de tornarPrincipal: sem isso, um id de outro cliente
        // passaria pelo rebaixamento e deixaria ESTE cliente sem principal.
        if (!$modelo->findOwned((int)$id, $clienteId)) {
            $this->falha(404, 'nao_encontrado', 'Endereço não encontrado.');
        }

        try {
            $modelo->tornarPrincipal((int)$id, $clienteId);
            $principal = $modelo->principal($clienteId);
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'tornar_principal', 'endereco_id' => (int)$id]);
            $this->falha(500, 'falha_endereco', 'Não foi possível alterar o endereço de entrega.');
        }

        $this->ok(['endereco' => $principal ? EnderecoPresenter::um($principal) : null]);
    }

    /**
     * GET /api/app/v1/conta/cabecalho
     *
     * O que a barra superior precisa em TODA tela, numa requisição só: para
     * onde entregamos e quantos avisos não lidos existem. Dois endpoints
     * separados dobrariam o custo de abrir qualquer tela do app.
     */
    public function cabecalho(): void
    {
        $this->bootCliente();
        $this->liberarSessao();

        $clienteId = (int)$this->clienteId;

        try {
            // Uma query só: listarPorCliente() já ordena o principal primeiro,
            // então ele sai daqui junto com a contagem. Chamar principal() além
            // disso seria uma segunda ida ao banco pelo mesmo dado.
            $todos = (new Endereco())->listarPorCliente($clienteId);
            $total = count($todos);

            // A ordenação é `principal DESC`, então o principal — se existir —
            // é o primeiro. O teste da flag não é redundante: um cliente pode
            // ter endereços e nenhum marcado como principal, e nesse caso o
            // primeiro da lista é só o mais recente. Anunciá-lo como destino da
            // entrega seria inventar uma escolha que o cliente não fez.
            $principal = !empty($todos[0]['principal']) ? $todos[0] : null;
        } catch (\Throwable $e) {
            $principal = null;
            $total     = 0;
        }

        $this->ok([
            'entrega' => [
                'endereco'         => $principal ? EnderecoPresenter::um($principal) : null,
                'total_enderecos'  => $total,
            ],
            'notificacoes_nao_lidas' => NotificacaoService::contarNaoLidas('cliente', $clienteId),
        ]);
    }
}
