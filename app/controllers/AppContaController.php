<?php
// app/controllers/AppContaController.php
// Área do cliente: perfil, favoritos e pedidos.
//
// Tudo aqui exige login (bootCliente). O único ponto sutil é `/favoritos/ids`,
// que existe para a vitrine marcar o coração sem precisar carregar a lista
// inteira de favoritos a cada tela.

class AppContaController extends AppApiController
{
    /**
     * GET /api/app/v1/conta/perfil
     */
    public function perfil(): void
    {
        $this->bootCliente();
        $this->liberarSessao();

        try {
            $st = $this->db()->prepare(
                "SELECT u.id AS usuario_id, u.nome, u.email, u.email_verificado,
                        c.id AS cliente_id, c.telefone, c.avatar, c.criado_em
                 FROM clientes c
                 INNER JOIN usuarios u ON u.id = c.usuario_id
                 WHERE c.id = :c LIMIT 1"
            );
            $st->execute([':c' => $this->clienteId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'perfil']);
            $this->falha(500, 'falha_perfil', 'Não foi possível carregar seu perfil.');
        }

        if (!$row) {
            $this->falha(404, 'nao_encontrado', 'Perfil não encontrado.');
        }

        $ctx = $this->contexto();

        $this->ok(['perfil' => [
            'cliente_id'       => (int)$row['cliente_id'],
            'nome'             => $row['nome'],
            'primeiro_nome'    => trim(explode(' ', trim((string)$row['nome']))[0] ?? ''),
            'email'            => $row['email'],
            'email_verificado' => !empty($row['email_verificado']),
            'telefone'         => $row['telefone'] ?? null,
            'avatar'           => $ctx->url($row['avatar'] ?? null),
            'cliente_desde'    => !empty($row['criado_em'])
                ? date(DATE_ATOM, strtotime((string)$row['criado_em']))
                : null,
        ]]);
    }

    /* =================================================================
       FAVORITOS
       ================================================================= */

    /**
     * GET /api/app/v1/conta/favoritos
     * Devolve cards completos — é uma vitrine, não uma lista de ids.
     */
    public function favoritos(): void
    {
        $this->bootCliente();
        $this->liberarSessao();

        $pagina = $this->pagina(20, 40);
        $ctx    = $this->contexto();

        try {
            $st = $this->db()->prepare(
                "SELECT wi.produto_id
                 FROM wishlist_itens wi
                 INNER JOIN wishlist w ON w.id = wi.wishlist_id
                 WHERE w.cliente_id = :c AND w.padrao = 1
                 ORDER BY wi.adicionado_em DESC
                 LIMIT :lim OFFSET :off"
            );
            $st->bindValue(':c', $this->clienteId, PDO::PARAM_INT);
            $st->bindValue(':lim', $pagina['limit'], PDO::PARAM_INT);
            $st->bindValue(':off', $pagina['offset'], PDO::PARAM_INT);
            $st->execute();
            $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

            $total = (int)$this->db()->query(
                "SELECT COUNT(*) FROM wishlist_itens wi
                 INNER JOIN wishlist w ON w.id = wi.wishlist_id
                 WHERE w.cliente_id = " . (int)$this->clienteId . " AND w.padrao = 1"
            )->fetchColumn();
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'favoritos']);
            $this->falha(500, 'falha_favoritos', 'Não foi possível carregar seus favoritos.');
        }

        // `ids` preserva a ordem de adição — getByFilters usa FIELD() para não
        // perdê-la, então o mais recente continua em primeiro.
        $produtos = $ids ? (new Product())->getByFilters(['ids' => $ids], count($ids)) : [];

        $this->okPaginado('produtos', ProductCardPresenter::colecao($produtos, $ctx), $total, $pagina);
    }

    /**
     * GET /api/app/v1/conta/favoritos/ids
     *
     * Só os ids. O app guarda esse conjunto e marca o coração em qualquer card
     * sem uma consulta por produto — o `favoritado` que vem no card só existe
     * quando a listagem passou pelo catálogo.
     */
    public function favoritosIds(): void
    {
        $this->bootCliente();
        $this->liberarSessao();

        try {
            $st = $this->db()->prepare(
                "SELECT wi.produto_id
                 FROM wishlist_itens wi
                 INNER JOIN wishlist w ON w.id = wi.wishlist_id
                 WHERE w.cliente_id = :c AND w.padrao = 1"
            );
            $st->execute([':c' => $this->clienteId]);
            $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        } catch (\Throwable $e) {
            $ids = [];
        }

        $this->ok(['ids' => $ids]);
    }

    /**
     * POST /api/app/v1/conta/favoritos    Corpo: { produto_id }
     * DELETE /api/app/v1/conta/favoritos/{id}
     */
    public function favoritar(): void
    {
        $this->bootCliente();
        $corpo = $this->exigirCampos(['produto_id']);
        $this->liberarSessao();

        $produtoId = (int)$corpo['produto_id'];

        try {
            $ok = (new Wishlist())->favoritar($this->clienteId, $produtoId);
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'favoritar', 'produto_id' => $produtoId]);
            $this->falha(500, 'falha_favorito', 'Não foi possível favoritar agora.');
        }

        $this->ok(['favoritado' => (bool)$ok, 'produto_id' => $produtoId], 201);
    }

    public function desfavoritar(string $id = '0'): void
    {
        $this->bootCliente();
        $this->liberarSessao();

        $produtoId = (int)$id;

        try {
            (new Wishlist())->desfavoritar($this->clienteId, $produtoId);
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'desfavoritar', 'produto_id' => $produtoId]);
            $this->falha(500, 'falha_favorito', 'Não foi possível remover dos favoritos.');
        }

        $this->ok(['favoritado' => false, 'produto_id' => $produtoId]);
    }

    /* =================================================================
       PEDIDOS
       ================================================================= */

    /**
     * GET /api/app/v1/conta/pedidos
     *
     * A prévia de imagens de cada pedido sai numa query em lote, não uma por
     * pedido — é o mesmo cuidado do card de produto.
     */
    public function pedidos(): void
    {
        $this->bootCliente();
        $this->liberarSessao();

        $pagina = $this->pagina(10, 30);
        $ctx    = $this->contexto();

        try {
            $st = $this->db()->prepare(
                "SELECT p.*, (SELECT COALESCE(SUM(pi.quantidade), 0)
                              FROM pedido_itens pi WHERE pi.pedido_id = p.id) AS itens_total
                 FROM pedidos p
                 WHERE p.cliente_id = :c
                 ORDER BY p.criado_em DESC
                 LIMIT :lim OFFSET :off"
            );
            $st->bindValue(':c', $this->clienteId, PDO::PARAM_INT);
            $st->bindValue(':lim', $pagina['limit'], PDO::PARAM_INT);
            $st->bindValue(':off', $pagina['offset'], PDO::PARAM_INT);
            $st->execute();
            $pedidos = $st->fetchAll(PDO::FETCH_ASSOC);

            $totalSt = $this->db()->prepare("SELECT COUNT(*) FROM pedidos WHERE cliente_id = :c");
            $totalSt->execute([':c' => $this->clienteId]);
            $total = (int)$totalSt->fetchColumn();
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'listar_pedidos']);
            $this->falha(500, 'falha_pedidos', 'Não foi possível carregar seus pedidos.');
        }

        $pedidos = $this->anexarPrevia($pedidos);

        $this->okPaginado(
            'pedidos',
            array_map(static fn(array $p) => OrderPresenter::resumo($p, $ctx), $pedidos),
            $total,
            $pagina
        );
    }

    /**
     * GET /api/app/v1/conta/pedidos/{codigo}
     * Busca por CÓDIGO, não por id: é o que o cliente vê e compartilha, e
     * findByCode() já valida a posse pelo cliente_id.
     */
    public function pedido(string $codigo = ''): void
    {
        $this->bootCliente();
        $this->liberarSessao();

        $modelo = new Order();
        $pedido = $modelo->findByCode(trim($codigo), (int)$this->clienteId);

        if (!$pedido) {
            $this->falha(404, 'nao_encontrado', 'Pedido não encontrado.');
        }

        $itens = $modelo->getItemsWithVariacoes((int)$pedido['id']);

        // Rastreio e elegibilidade de devolução vêm JUNTO, e não em chamadas
        // separadas: as duas coisas mudam o desenho da tela inteira (a régua no
        // topo, o botão no rodapé), e buscá-las depois faria o layout saltar
        // com o pedido já na tela.
        $this->ok([
            'pedido' => OrderPresenter::detalhe(
                $pedido,
                $itens,
                $this->contexto(),
                $this->rastreioDoPedido((int)$pedido['id']),
                $this->devolucaoDoPedido($pedido, $itens)
            ),
        ]);
    }

    /**
     * O rastreio da encomenda, se já existe um.
     *
     * `porPedido()` entra por pedido_id porque o cliente já provou ser dono do
     * pedido para chegar aqui — e sai pelo mesmo sanitizador da página pública:
     * a tela do comprador não vê mais do que qualquer um veria pelo link.
     */
    private function rastreioDoPedido(int $pedidoId): ?array
    {
        try {
            return (new RastreioService($this->db()))->porPedido($pedidoId);
        } catch (\Throwable $e) {
            // Logística fora do ar não pode derrubar a tela do pedido: sem
            // rastreio, o app mostra o resto normalmente.
            AppLog::exception($e, ['acao' => 'pedido_rastreio', 'pedido_id' => $pedidoId]);
            return null;
        }
    }

    /**
     * Se dá para pedir devolução deste pedido.
     *
     * Mesma regra de AppDevolucoesController::elegibilidade — entregue E
     * dentro do prazo do CDC, contado da ENTREGA REAL (primeiro evento
     * 'entregue' no histórico), não do envio.
     *
     * @param array<int,array> $itens
     */
    private function devolucaoDoPedido(array $pedido, array $itens): ?array
    {
        try {
            $entregue = ($pedido['status_pedido'] ?? '') === 'entregue';
            $prazo    = DevolucaoService::PRAZO_CDC_DIAS;

            $st = $this->db()->prepare(
                "SELECT criado_em FROM pedido_historico
                 WHERE pedido_id = :p AND status_novo = 'entregue'
                 ORDER BY criado_em ASC LIMIT 1"
            );
            $st->execute([':p' => (int)$pedido['id']]);
            $referencia = $st->fetchColumn() ?: ($pedido['atualizado_em'] ?? null);

            $diasDesde = $referencia
                ? (int)floor((time() - strtotime((string)$referencia)) / 86400)
                : null;
            $dentroDoPrazo = $diasDesde === null || $diasDesde <= $prazo;

            return [
                'pode'   => $entregue && $dentroDoPrazo && !empty($itens),
                'motivo' => !$entregue
                    ? 'Disponível depois que o pedido for entregue.'
                    : (!$dentroDoPrazo ? "O prazo de {$prazo} dias já passou." : null),
                'prazo_dias'  => $prazo,
                'dias_desde'  => $diasDesde,
                'entregue_em' => $referencia
                    ? date(DATE_ATOM, strtotime((string)$referencia))
                    : null,
            ];
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'pedido_devolucao']);
            return null;
        }
    }

    /**
     * Uma query para as miniaturas de todos os pedidos da página.
     * @param array<int,array> $pedidos
     */
    private function anexarPrevia(array $pedidos): array
    {
        if (!$pedidos) {
            return $pedidos;
        }

        $ids = array_map(static fn(array $p) => (int)$p['id'], $pedidos);
        $in  = implode(',', array_fill(0, count($ids), '?'));

        try {
            $st = $this->db()->prepare(
                "SELECT pi.pedido_id, img.arquivo AS imagem
                 FROM pedido_itens pi
                 LEFT JOIN produto_imagens img
                        ON img.produto_id = pi.produto_id AND img.principal = 1
                 WHERE pi.pedido_id IN ({$in})
                 ORDER BY pi.pedido_id, pi.id"
            );
            $st->execute($ids);

            $porPedido = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $pid = (int)$row['pedido_id'];
                if (count($porPedido[$pid] ?? []) < 3) {
                    $porPedido[$pid][] = ['imagem' => $row['imagem']];
                }
            }
        } catch (\Throwable $e) {
            return $pedidos;
        }

        foreach ($pedidos as &$p) {
            $p['previa_itens'] = $porPedido[(int)$p['id']] ?? [];
        }
        unset($p);

        return $pedidos;
    }
}
