<?php
// app/controllers/AppEnderecosController.php
// Endereços de entrega: listar, criar, editar, excluir — e o bloco de entrega
// do cabeçalho.
//
// As REGRAS DE VALIDAÇÃO são as de CustomerController::saveAddress, e não
// outras: destinatário com 3 caracteres, CEP com 8 dígitos, logradouro, número
// e cidade obrigatórios, UF com 2 letras. App e site precisam recusar as
// mesmas coisas, senão um endereço salvo aqui quebra o checkout de lá.
//
// Duas regras a mais, que a web não checa e o BANCO exige:
//
//  1. `bairro` é NOT NULL, e Endereco::sanitize() converte string vazia em
//     NULL — ou seja, salvar sem bairro é erro 500, não validação. Acontece de
//     verdade: cidade pequena com CEP único volta do ViaCEP sem bairro.
//  2. Excluir o endereço PRINCIPAL deixaria o cliente sem nenhum, e o checkout
//     parte de Endereco::principal(). Aqui outro assume o lugar.
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
     * POST /api/app/v1/conta/enderecos
     *
     * O primeiro endereço do cliente nasce principal: sem isso o checkout não
     * teria de onde partir, e a pessoa cadastraria um endereço que o app
     * continuaria ignorando. Mesma regra de Customer::saveAddress.
     */
    public function criar(): void
    {
        $this->bootCliente();

        $dados = $this->validar($this->corpo());
        $clienteId = (int)$this->clienteId;

        $modelo = new Endereco();

        try {
            $primeiro = $modelo->listarPorCliente($clienteId) === [];

            $id = $modelo->salvar($dados + [
                'cliente_id' => $clienteId,
                // Ou o cliente pediu, ou é o primeiro e alguém precisa ser.
                'principal'  => $primeiro || !empty($this->campo('principal')) ? 1 : 0,
            ]);

            $novo = $modelo->findOwned($id, $clienteId);
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'criar_endereco', 'cliente' => $clienteId]);
            $this->falha(500, 'falha_salvar', 'Não foi possível salvar o endereço.');
        }

        $this->liberarSessao();

        $this->ok(['endereco' => $novo ? EnderecoPresenter::um($novo) : null], 201);
    }

    /**
     * PATCH /api/app/v1/conta/enderecos/{id}
     */
    public function atualizar(string $id = '0'): void
    {
        $this->bootCliente();

        $enderecoId = (int)$id;
        $clienteId  = (int)$this->clienteId;
        $modelo     = new Endereco();

        // findOwned ANTES de qualquer escrita: Endereco::atualizar() filtra só
        // por `id`, sem cliente_id no WHERE. Sem esta checagem, um id de outro
        // cliente seria sobrescrito.
        $atual = $modelo->findOwned($enderecoId, $clienteId);
        if (!$atual) {
            $this->falha(404, 'nao_encontrado', 'Endereço não encontrado.');
        }

        $dados = $this->validar($this->corpo());

        try {
            $modelo->atualizar($enderecoId, $dados + ['cliente_id' => $clienteId]);
            $atualizado = $modelo->findOwned($enderecoId, $clienteId);
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'atualizar_endereco', 'endereco_id' => $enderecoId]);
            $this->falha(500, 'falha_salvar', 'Não foi possível salvar as alterações.');
        }

        $this->liberarSessao();

        $this->ok(['endereco' => $atualizado ? EnderecoPresenter::um($atualizado) : null]);
    }

    /**
     * DELETE /api/app/v1/conta/enderecos/{id}
     */
    public function excluir(string $id = '0'): void
    {
        $this->bootCliente();

        $enderecoId = (int)$id;
        $clienteId  = (int)$this->clienteId;
        $modelo     = new Endereco();

        $alvo = $modelo->findOwned($enderecoId, $clienteId);
        if (!$alvo) {
            $this->falha(404, 'nao_encontrado', 'Endereço não encontrado.');
        }

        // Regra de Customer::deleteAddress: endereço preso a pedido em
        // andamento não sai. Apagá-lo deixaria o pedido sem destino registrado
        // enquanto a encomenda ainda está a caminho.
        if ($this->presoAPedido($enderecoId, $clienteId)) {
            $this->falha(409, 'endereco_em_uso',
                'Este endereço está vinculado a um pedido em andamento e não pode ser excluído.');
        }

        try {
            $modelo->excluir($enderecoId, $clienteId);

            // Era o principal: alguém precisa herdar o posto, senão o cliente
            // fica sem endereço de entrega e o checkout não tem de onde partir.
            // A web não faz isso — é a lacuna que fecha aqui.
            $novoPrincipal = null;
            if (!empty($alvo['principal'])) {
                $restantes = $modelo->listarPorCliente($clienteId);
                if ($restantes !== []) {
                    $modelo->tornarPrincipal((int)$restantes[0]['id'], $clienteId);
                    $novoPrincipal = $modelo->principal($clienteId);
                }
            }
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'excluir_endereco', 'endereco_id' => $enderecoId]);
            $this->falha(500, 'falha_excluir', 'Não foi possível excluir o endereço.');
        }

        $this->liberarSessao();

        $this->ok([
            'excluido'  => true,
            'principal' => $novoPrincipal ? EnderecoPresenter::um($novoPrincipal) : null,
        ]);
    }

    /* ================================================================= */

    /**
     * As regras de CustomerController::saveAddress, com `bairro` a mais.
     *
     * Devolve TODOS os erros de uma vez, e não só o primeiro: num formulário de
     * nove campos, corrigir um por requisição é castigo.
     *
     * @return array<string,mixed>
     */
    private function validar(array $corpo): array
    {
        $texto = static fn(string $c): string => trim((string)($corpo[$c] ?? ''));

        $dados = [
            'nome_destinatario' => $texto('nome_destinatario'),
            'cep'               => preg_replace('/\D/', '', (string)($corpo['cep'] ?? '')) ?? '',
            'logradouro'        => $texto('logradouro'),
            'numero'            => $texto('numero'),
            'complemento'       => $texto('complemento'),
            'bairro'            => $texto('bairro'),
            'cidade'            => $texto('cidade'),
            'estado'            => strtoupper($texto('estado')),
            'telefone_contato'  => $texto('telefone'),
            'apelido'           => $texto('apelido'),
            'observacao_entrega'=> $texto('observacao'),
        ];

        $erros = [];
        if (mb_strlen($dados['nome_destinatario']) < 3) $erros['nome_destinatario'] = 'Informe o nome de quem recebe.';
        if (strlen($dados['cep']) !== 8)                $erros['cep']               = 'CEP inválido.';
        if ($dados['logradouro'] === '')                $erros['logradouro']        = 'Informe a rua.';
        if ($dados['numero'] === '')                    $erros['numero']            = 'Informe o número.';
        if ($dados['bairro'] === '')                    $erros['bairro']            = 'Informe o bairro.';
        if ($dados['cidade'] === '')                    $erros['cidade']            = 'Informe a cidade.';
        if (!preg_match('/^[A-Z]{2}$/', $dados['estado'])) $erros['estado']         = 'UF inválida.';

        if ($erros) {
            $this->falha(422, 'dados_invalidos', 'Confira os campos destacados.', ['campos' => $erros]);
        }

        return $dados;
    }

    private function presoAPedido(int $enderecoId, int $clienteId): bool
    {
        try {
            $st = $this->db()->prepare(
                "SELECT COUNT(*) FROM pedidos
                  WHERE cliente_id = ? AND endereco_entrega_id = ?
                    AND status_pedido NOT IN ('entregue','cancelado')"
            );
            $st->execute([$clienteId, $enderecoId]);
            return (int)$st->fetchColumn() > 0;
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'endereco_preso', 'endereco_id' => $enderecoId]);
            // Na dúvida, NÃO exclui: perder o endereço de um pedido em trânsito
            // é pior do que recusar uma exclusão legítima.
            return true;
        }
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
