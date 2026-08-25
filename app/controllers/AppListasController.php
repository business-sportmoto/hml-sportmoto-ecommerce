<?php
// app/controllers/AppListasController.php
// Listas de desejos.
//
// A loja não tem "favoritos" e sim N listas nomeadas por cliente, uma delas
// marcada como `padrao` — e é essa que o coração do card liga e desliga. As
// outras são listas que o cliente cria ("Trilha", "Presente do irmão").
//
// Tratar o sistema como um booleano de favorito, como fizemos na primeira
// versão do app, perde metade do recurso: o produto pode estar em três listas
// e nenhuma delas ser a padrão.
//
// Reusa a mesma modelagem de WishlistController, sem o acoplamento a $_POST
// e CSRF.

class AppListasController extends AppApiController
{
    /** Mesmo teto do WishlistController::criar(). */
    private const MAX_LISTAS = 20;

    /**
     * GET /api/app/v1/conta/listas
     */
    public function index(): void
    {
        $this->bootCliente();
        $this->liberarSessao();

        $ctx = $this->contexto();

        try {
            $st = $this->db()->prepare(
                "SELECT w.id, w.nome, w.padrao, w.publica, w.descricao, w.criado_em,
                        COUNT(wi.id) AS total_itens,
                        MAX(pi.arquivo) AS capa
                 FROM wishlist w
                 LEFT JOIN wishlist_itens wi ON wi.wishlist_id = w.id
                 LEFT JOIN produto_imagens pi
                        ON pi.produto_id = wi.produto_id AND pi.principal = 1
                 WHERE w.cliente_id = :c
                 GROUP BY w.id
                 ORDER BY w.padrao DESC, w.criado_em DESC"
            );
            $st->execute([':c' => $this->clienteId]);
            $linhas = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'listar_listas']);
            $this->falha(500, 'falha_listas', 'Não foi possível carregar suas listas.');
        }

        $this->ok(['listas' => array_map(
            static fn(array $l) => [
                'id'          => (int)$l['id'],
                'nome'        => $l['nome'],
                // A lista padrão não pode ser renomeada nem excluída — é o
                // destino do coração e sempre precisa existir.
                'padrao'      => !empty($l['padrao']),
                'publica'     => !empty($l['publica']),
                'descricao'   => $l['descricao'] ?? null,
                'total_itens' => (int)$l['total_itens'],
                'capa'        => $ctx->url($l['capa'] ?? null),
                'criado_em'   => !empty($l['criado_em'])
                    ? date(DATE_ATOM, strtotime((string)$l['criado_em']))
                    : null,
            ],
            $linhas
        )]);
    }

    /**
     * GET /api/app/v1/conta/listas/{id}
     * Itens da lista, como cards de vitrine.
     */
    public function itens(string $id = '0'): void
    {
        $this->bootCliente();
        $this->liberarSessao();

        $listaId = (int)$id;
        $lista   = $this->listaDoCliente($listaId);

        if (!$lista) {
            $this->falha(404, 'nao_encontrada', 'Lista não encontrada.');
        }

        $pagina = $this->pagina(20, 40);
        $ctx    = $this->contexto();

        try {
            $st = $this->db()->prepare(
                "SELECT produto_id FROM wishlist_itens
                 WHERE wishlist_id = :l
                 ORDER BY adicionado_em DESC
                 LIMIT :lim OFFSET :off"
            );
            $st->bindValue(':l', $listaId, PDO::PARAM_INT);
            $st->bindValue(':lim', $pagina['limit'], PDO::PARAM_INT);
            $st->bindValue(':off', $pagina['offset'], PDO::PARAM_INT);
            $st->execute();
            $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

            $totalSt = $this->db()->prepare(
                "SELECT COUNT(*) FROM wishlist_itens WHERE wishlist_id = :l"
            );
            $totalSt->execute([':l' => $listaId]);
            $total = (int)$totalSt->fetchColumn();
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'itens_lista']);
            $this->falha(500, 'falha_lista', 'Não foi possível carregar a lista.');
        }

        $produtos = $ids ? (new Product())->getByFilters(['ids' => $ids], count($ids)) : [];

        $this->okPaginado(
            'produtos',
            ProductCardPresenter::colecao($produtos, $ctx),
            $total,
            $pagina,
            ['lista' => [
                'id'     => (int)$lista['id'],
                'nome'   => $lista['nome'],
                'padrao' => !empty($lista['padrao']),
            ]]
        );
    }

    /**
     * POST /api/app/v1/conta/listas   Corpo: { nome, descricao?, publica? }
     */
    public function criar(): void
    {
        $this->bootCliente();
        $corpo = $this->exigirCampos(['nome']);
        $this->liberarSessao();

        $nome = mb_substr(trim((string)$corpo['nome']), 0, 60);
        if ($nome === '') {
            $this->falha(422, 'dados_invalidos', 'Informe um nome para a lista.');
        }

        try {
            $st = $this->db()->prepare("SELECT COUNT(*) FROM wishlist WHERE cliente_id = :c");
            $st->execute([':c' => $this->clienteId]);

            if ((int)$st->fetchColumn() >= self::MAX_LISTAS) {
                $this->falha(422, 'limite_listas', 'Você atingiu o limite de ' . self::MAX_LISTAS . ' listas.');
            }

            $this->db()->prepare(
                "INSERT INTO wishlist (cliente_id, nome, publica, descricao)
                 VALUES (:c, :n, :p, :d)"
            )->execute([
                ':c' => $this->clienteId,
                ':n' => $nome,
                ':p' => !empty($corpo['publica']) ? 1 : 0,
                ':d' => !empty($corpo['descricao']) ? mb_substr((string)$corpo['descricao'], 0, 200) : null,
            ]);

            $listaId = (int)$this->db()->lastInsertId();
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'criar_lista']);
            $this->falha(500, 'falha_lista', 'Não foi possível criar a lista.');
        }

        $this->ok(['lista' => ['id' => $listaId, 'nome' => $nome, 'padrao' => false, 'total_itens' => 0]], 201);
    }

    /**
     * PATCH /api/app/v1/conta/listas/{id}   Corpo: { nome?, descricao?, publica? }
     */
    public function editar(string $id = '0'): void
    {
        $this->bootCliente();
        $corpo = $this->corpo();
        $this->liberarSessao();

        $lista = $this->listaDoCliente((int)$id);
        if (!$lista) {
            $this->falha(404, 'nao_encontrada', 'Lista não encontrada.');
        }
        if (!empty($lista['padrao']) && isset($corpo['nome'])) {
            $this->falha(422, 'lista_padrao', 'A lista de favoritos não pode ser renomeada.');
        }

        $sets = [];
        $vals = [':id' => (int)$id, ':c' => $this->clienteId];

        if (isset($corpo['nome']) && trim((string)$corpo['nome']) !== '') {
            $sets[] = 'nome = :n';
            $vals[':n'] = mb_substr(trim((string)$corpo['nome']), 0, 60);
        }
        if (array_key_exists('descricao', $corpo)) {
            $sets[] = 'descricao = :d';
            $vals[':d'] = $corpo['descricao'] ? mb_substr((string)$corpo['descricao'], 0, 200) : null;
        }
        if (array_key_exists('publica', $corpo)) {
            $sets[] = 'publica = :p';
            $vals[':p'] = !empty($corpo['publica']) ? 1 : 0;
        }

        if (!$sets) {
            $this->falha(422, 'dados_invalidos', 'Nada para atualizar.');
        }

        try {
            $this->db()->prepare(
                "UPDATE wishlist SET " . implode(', ', $sets) . " WHERE id = :id AND cliente_id = :c"
            )->execute($vals);
        } catch (\Throwable $e) {
            $this->falha(500, 'falha_lista', 'Não foi possível atualizar a lista.');
        }

        $this->ok(['atualizada' => true]);
    }

    /**
     * DELETE /api/app/v1/conta/listas/{id}
     */
    public function excluir(string $id = '0'): void
    {
        $this->bootCliente();
        $this->liberarSessao();

        $lista = $this->listaDoCliente((int)$id);
        if (!$lista) {
            $this->falha(404, 'nao_encontrada', 'Lista não encontrada.');
        }
        if (!empty($lista['padrao'])) {
            $this->falha(422, 'lista_padrao', 'A lista de favoritos não pode ser excluída.');
        }

        try {
            $this->db()->prepare("DELETE FROM wishlist_itens WHERE wishlist_id = :l")
                ->execute([':l' => (int)$id]);
            $this->db()->prepare("DELETE FROM wishlist WHERE id = :l AND cliente_id = :c")
                ->execute([':l' => (int)$id, ':c' => $this->clienteId]);
        } catch (\Throwable $e) {
            $this->falha(500, 'falha_lista', 'Não foi possível excluir a lista.');
        }

        $this->ok(['excluida' => true]);
    }

    /**
     * GET /api/app/v1/conta/listas/produto/{id}
     *
     * Em quais listas este produto está. É o que a folha "Salvar em lista" da
     * página de produto precisa para marcar os checkboxes de saída.
     */
    public function doProduto(string $id = '0'): void
    {
        $this->bootCliente();
        $this->liberarSessao();

        try {
            $st = $this->db()->prepare(
                "SELECT w.id, w.nome, w.padrao,
                        EXISTS (SELECT 1 FROM wishlist_itens wi
                                WHERE wi.wishlist_id = w.id AND wi.produto_id = :p) AS tem
                 FROM wishlist w
                 WHERE w.cliente_id = :c
                 ORDER BY w.padrao DESC, w.criado_em DESC"
            );
            $st->execute([':p' => (int)$id, ':c' => $this->clienteId]);
            $linhas = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $linhas = [];
        }

        $this->ok(['listas' => array_map(static fn(array $l) => [
            'id'     => (int)$l['id'],
            'nome'   => $l['nome'],
            'padrao' => !empty($l['padrao']),
            'contem' => !empty($l['tem']),
        ], $linhas)]);
    }

    /**
     * POST /api/app/v1/conta/listas/{id}/itens    Corpo: { produto_id }
     */
    public function adicionarItem(string $id = '0'): void
    {
        $this->bootCliente();
        $corpo = $this->exigirCampos(['produto_id']);
        $this->liberarSessao();

        $listaId   = (int)$id;
        $produtoId = (int)$corpo['produto_id'];

        if (!$this->listaDoCliente($listaId)) {
            $this->falha(404, 'nao_encontrada', 'Lista não encontrada.');
        }

        try {
            // Idempotente: adicionar duas vezes não é erro do ponto de vista de
            // quem toca no botão, e devolver 422 aqui só atrapalharia a UI.
            $st = $this->db()->prepare(
                "SELECT id FROM wishlist_itens WHERE wishlist_id = :l AND produto_id = :p LIMIT 1"
            );
            $st->execute([':l' => $listaId, ':p' => $produtoId]);

            if (!$st->fetchColumn()) {
                $this->db()->prepare(
                    "INSERT INTO wishlist_itens (wishlist_id, produto_id, adicionado_em)
                     VALUES (:l, :p, NOW())"
                )->execute([':l' => $listaId, ':p' => $produtoId]);
            }
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'adicionar_item_lista']);
            $this->falha(500, 'falha_lista', 'Não foi possível adicionar à lista.');
        }

        $this->ok(['adicionado' => true, 'lista_id' => $listaId, 'produto_id' => $produtoId], 201);
    }

    /**
     * DELETE /api/app/v1/conta/listas/{id}/itens/{produto}
     */
    public function removerItem(string $id = '0', string $produto = '0'): void
    {
        $this->bootCliente();
        $this->liberarSessao();

        $listaId = (int)$id;

        if (!$this->listaDoCliente($listaId)) {
            $this->falha(404, 'nao_encontrada', 'Lista não encontrada.');
        }

        try {
            $this->db()->prepare(
                "DELETE FROM wishlist_itens WHERE wishlist_id = :l AND produto_id = :p"
            )->execute([':l' => $listaId, ':p' => (int)$produto]);
        } catch (\Throwable $e) {
            $this->falha(500, 'falha_lista', 'Não foi possível remover da lista.');
        }

        $this->ok(['removido' => true, 'lista_id' => $listaId, 'produto_id' => (int)$produto]);
    }

    /* ================================================================= */

    private function listaDoCliente(int $listaId): ?array
    {
        try {
            $st = $this->db()->prepare(
                "SELECT id, nome, padrao FROM wishlist WHERE id = :l AND cliente_id = :c LIMIT 1"
            );
            $st->execute([':l' => $listaId, ':c' => $this->clienteId]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
