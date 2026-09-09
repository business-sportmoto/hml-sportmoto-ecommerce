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
                'notificacoes' => NotificacaoPresenter::colecao(
                    $itens,
                    $ctx,
                    $this->codigosDePedido($itens)
                ),
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

    /**
     * id do pedido => código, para as notificações desta página.
     *
     * UMA consulta para a lista inteira. As notificações de pedido guardam o
     * id na URL e a tela do app abre por código; sem este mapa, cada
     * notificação precisaria da própria consulta.
     *
     * Filtra por `cliente_id`: um id de pedido de outra pessoa não pode virar
     * um código navegável só porque apareceu numa URL.
     *
     * @param  array<int,array> $itens
     * @return array<int,string>
     */
    private function codigosDePedido(array $itens): array
    {
        $ids = NotificacaoPresenter::idsDePedido($itens);

        if ($ids === []) {
            return [];
        }

        try {
            $marcadores = implode(',', array_fill(0, count($ids), '?'));
            $st = $this->db()->prepare(
                "SELECT id, codigo FROM pedidos
                  WHERE id IN ({$marcadores}) AND cliente_id = ?"
            );
            $st->execute([...$ids, (int)$this->clienteId]);

            $mapa = [];
            foreach ($st->fetchAll() as $linha) {
                $mapa[(int)$linha['id']] = (string)$linha['codigo'];
            }

            return $mapa;
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'notificacoes_codigos_pedido']);

            // Sem o mapa, o destino cai para a lista de pedidos — que é uma
            // degradação aceitável, não um erro de tela.
            return [];
        }
    }
}
