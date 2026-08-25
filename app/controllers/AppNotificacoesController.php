<?php
// app/controllers/AppNotificacoesController.php
// Central de notificações do cliente.
//
// NotificacaoService é 100% estático e não toca em Session — é o serviço mais
// fácil de reusar de toda a loja. Aqui não há regra nova: o controller só
// traduz destinatário (sempre 'cliente' + clienteId, nunca vindo do corpo) e
// devolve o formato do app.
//
// O `destinatario_tipo` FIXO em 'cliente' é o que impede um cliente de ler as
// notificações do admin passando ?tipo=admin.

class AppNotificacoesController extends AppApiController
{
    /**
     * GET /api/app/v1/conta/notificacoes
     * ?categoria=pedido&apenas_nao_lidas=1
     */
    public function index(): void
    {
        $this->bootCliente();
        $this->liberarSessao();

        $pagina = $this->pagina(20, 50);
        $ctx    = $this->contexto();

        $itens = NotificacaoService::listar('cliente', (int)$this->clienteId, [
            'categoria'        => $this->query('categoria') ?: null,
            'apenas_nao_lidas' => (bool)$this->query('apenas_nao_lidas', false),
            'limite'           => $pagina['limit'] + 1, // sonda de "tem mais"
            'offset'           => $pagina['offset'],
        ]);

        // NotificacaoService::listar() não devolve total, e um COUNT extra a
        // cada scroll não se paga. Pedimos um item além da página: se veio,
        // existe próxima. `total` fica sendo o que já foi visto.
        $temMais = count($itens) > $pagina['limit'];
        if ($temMais) {
            array_pop($itens);
        }

        $this->ok(
            [
                'notificacoes' => NotificacaoPresenter::colecao($itens, $ctx),
                'nao_lidas'    => NotificacaoService::contarNaoLidas('cliente', (int)$this->clienteId),
            ],
            200,
            [
                'pagina'    => $pagina['page'],
                'por_pagina'=> $pagina['limit'],
                'tem_mais'  => $temMais,
            ]
        );
    }

    /**
     * GET /api/app/v1/conta/notificacoes/contador
     * Só o número do badge — é o que o cabeçalho consulta em toda tela.
     */
    public function contador(): void
    {
        $this->bootCliente();
        $this->liberarSessao();

        $this->ok(['nao_lidas' => NotificacaoService::contarNaoLidas('cliente', (int)$this->clienteId)]);
    }

    /**
     * POST /api/app/v1/conta/notificacoes/{id}/lida
     * O id é o de notificacao_usuarios; marcarLida() valida a posse.
     */
    public function marcarLida(string $id = '0'): void
    {
        $this->bootCliente();
        $this->liberarSessao();

        // `false` aqui significa "já estava lida" OU "não é sua". Não vale
        // distinguir os dois casos na resposta: informaria a existência de
        // notificações alheias. O contador atualizado é o que a tela precisa.
        NotificacaoService::marcarLida((int)$id, 'cliente', (int)$this->clienteId);

        $this->ok(['nao_lidas' => NotificacaoService::contarNaoLidas('cliente', (int)$this->clienteId)]);
    }

    /**
     * POST /api/app/v1/conta/notificacoes/lidas
     */
    public function marcarTodasLidas(): void
    {
        $this->bootCliente();
        $this->liberarSessao();

        $afetadas = NotificacaoService::marcarTodasLidas('cliente', (int)$this->clienteId);

        $this->ok(['marcadas' => $afetadas, 'nao_lidas' => 0]);
    }
}
