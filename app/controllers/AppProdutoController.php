<?php
// app/controllers/AppProdutoController.php
// Página de produto e seus recursos auxiliares.

class AppProdutoController extends AppApiController
{
    /**
     * GET /api/app/v1/produtos/{slug}
     *
     * A PDP inteira numa resposta: galeria, variações com matriz de SKU,
     * características, resumo de avaliações, compatibilidade e relacionados.
     * A matriz vai completa para que trocar de tamanho ou cor não custe outra
     * requisição.
     */
    public function detalhe(string $slug = ''): void
    {
        $this->bootOpcional();

        $modelo  = new Product();
        $produto = $modelo->findBySlug($slug);

        if (!$produto) {
            $this->falha(404, 'nao_encontrado', 'Produto não encontrado.');
        }

        $id = (int)$produto['id'];

        // Escritas de estado antes de soltar o lock da sessão.
        $modelo->incrementViews($id);
        $this->registrarVisita($id);
        $this->liberarSessao();

        // findBySlug() não traz `favoritado` (só getList/getCatalog trazem).
        // Uma consulta pontual é mais barata que passar a PDP pelo catálogo.
        $produto['favoritado'] = $this->estaFavoritado($id);

        $this->ok(['produto' => ProductDetailPresenter::montar($produto, $this->contexto())]);
    }

    /**
     * GET /api/app/v1/produtos/{id}/avaliacoes
     * Lista paginada. O resumo e a distribuição já vêm em /produtos/{slug}.
     */
    public function avaliacoes(string $id = '0'): void
    {
        $this->bootOpcional();
        $this->liberarSessao();

        $produtoId = (int)$id;
        $pagina    = $this->pagina(10, 30);
        $ctx       = $this->contexto();

        $modelo = new Product();
        $rows   = $modelo->getReviews($produtoId, $pagina['limit'], $pagina['offset']);
        $stats  = $modelo->getReviewStats($produtoId);

        $this->okPaginado(
            'avaliacoes',
            array_values(array_map(static fn(array $a) => [
                'id'        => (int)$a['id'],
                'nota'      => (int)$a['nota'],
                'titulo'    => $a['titulo'] ?? null,
                'comentario'=> $a['comentario'] ?? null,
                'autor'     => [
                    'nome'   => $a['cliente_nome'] ?? 'Cliente',
                    'avatar' => $ctx->url($a['avatar'] ?? null),
                ],
                'criado_em' => !empty($a['criado_em'])
                    ? date(DATE_ATOM, strtotime($a['criado_em']))
                    : null,
                'util' => [
                    'sim' => (int)($a['util_sim'] ?? 0),
                    'nao' => (int)($a['util_nao'] ?? 0),
                ],
            ], $rows)),
            (int)($stats['total'] ?? 0),
            $pagina
        );
    }

    /**
     * GET /api/app/v1/produtos/{id}/clips
     */
    public function clips(string $id = '0'): void
    {
        $this->bootOpcional();
        $this->liberarSessao();

        $clips = (new Clip())->getPorProduto((int)$id, 20);

        $this->ok(['clips' => ClipPresenter::colecao($clips, $this->contexto())]);
    }

    /**
     * POST /api/app/v1/produtos/{id}/avisar-estoque
     * Corpo: { email, sku_id? }
     */
    public function avisarEstoque(string $id = '0'): void
    {
        $this->bootPublico();

        $corpo = $this->exigirCampos(['email']);
        $email = filter_var(trim((string)$corpo['email']), FILTER_VALIDATE_EMAIL);

        if (!$email) {
            $this->falha(422, 'email_invalido', 'Informe um e-mail válido.');
        }

        $produtoId = (int)$id;
        if (!(new Product())->find($produtoId)) {
            $this->falha(404, 'nao_encontrado', 'Produto não encontrado.');
        }

        try {
            $this->db()->prepare(
                "INSERT INTO aviso_estoque (produto_id, sku_id, cliente_id, email, status)
                 VALUES (:p, :s, :c, :e, 'pendente')"
            )->execute([
                ':p' => $produtoId,
                ':s' => !empty($corpo['sku_id']) ? (int)$corpo['sku_id'] : null,
                ':c' => $this->clienteId,
                ':e' => $email,
            ]);
        } catch (\Throwable $e) {
            LogService::error('Falha ao registrar aviso de estoque', ['erro' => $e->getMessage()]);
            $this->falha(500, 'falha_registro', 'Não foi possível registrar o aviso.');
        }

        $this->ok(['registrado' => true, 'email' => $email], 201);
    }

    /* ================================================================= */

    private function estaFavoritado(int $produtoId): bool
    {
        if (!$this->clienteId) {
            return false;
        }

        try {
            $st = $this->db()->prepare(
                "SELECT 1
                 FROM wishlist_itens wi
                 JOIN wishlist w ON w.id = wi.wishlist_id
                 WHERE w.cliente_id = :c AND w.padrao = 1 AND wi.produto_id = :p
                 LIMIT 1"
            );
            $st->execute([':c' => $this->clienteId, ':p' => $produtoId]);
            return (bool)$st->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Histórico de navegação — alimenta "baseado no que você viu" na home. */
    private function registrarVisita(int $produtoId): void
    {
        $sessao = session_id();
        if ($sessao === '') {
            return;
        }

        try {
            $this->db()->prepare(
                "INSERT INTO historico_navegacao (cliente_id, sessao_id, tipo, referencia_id)
                 VALUES (:c, :s, 'produto', :r)"
            )->execute([
                ':c' => $this->clienteId,
                ':s' => $sessao,
                ':r' => $produtoId,
            ]);
        } catch (\Throwable $e) { /* enriquecimento, não requisito */ }
    }
}
