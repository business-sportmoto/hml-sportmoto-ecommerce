<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/services/PersonalizationService.php — v2
//
// AGORA usa Product::getList() para todos os fetches.
// O card recebe o mesmo objeto de sempre, sem bagunça.
// ════════════════════════════════════════════════════════
class PersonalizationService {

    private const LIMITE        = 18;
    private const MIN_FAV_SECAO = 5; //min 5
    private const MIN_FAV_RECO  = 2; //min 5
    private const MIN_HISTORICO = 3; 
    private const MIN_CATS      = 2;
    private const MIN_BUSCAS    = 1;
    private const MIN_CLIPS     = 1;
    private const MIN_MARCAS    = 2;
    private const JANELA_DIAS   = 30;

    private PDO $db;

    private EstoqueService $EstoqueService;

    public function __construct(
        private readonly ?int   $clienteId,
        private readonly string $sessionKey,
        private readonly Product $product = new Product()
    ) {
        $this->db = Database::getInstance()->getConnection();

        $this->EstoqueService = new EstoqueService();
    }

    // ════════════════════════════════════════════════════
    // INTERFACE PRINCIPAL
    // ════════════════════════════════════════════════════

    public function buildHomeSections(): array {
        $favIds  = $this->getFavoritosIds();
        $histIds = $this->getHistoricoIds('produto');
        $catIds  = $this->getHistoricoIds('categoria');
        $buscas  = $this->getBuscas();
        $clipCats= $this->getCategoriasDeClips();
        $marcaIds= $this->getHistoricoIds('marca');
        $cartCats= $this->getCarrinhoCategorias();

        $sections = [
            $this->sectionNovidades(),
            $this->sectionPromocoes(),
            $this->sectionMaisVendidos(),
        ];

        if (count($favIds) >= self::MIN_FAV_SECAO)
            $sections[] = $this->sectionFavoritos($favIds);

        if (count($favIds) >= self::MIN_FAV_RECO)
            $sections[] = $this->sectionPorFavoritos($favIds);

        if (count($histIds) >= self::MIN_HISTORICO)
            $sections[] = $this->sectionPorHistorico($histIds);

        if (count($catIds) >= self::MIN_CATS)
            $sections[] = $this->sectionPorCategorias($catIds);

        if (count($buscas) >= self::MIN_BUSCAS)
            $sections[] = $this->sectionPorBuscas($buscas);

        if (count($clipCats) >= self::MIN_CLIPS)
            $sections[] = $this->sectionPorClips($clipCats);

        if (count($marcaIds) >= self::MIN_MARCAS)
            $sections[] = $this->sectionPorMarcas($marcaIds);

        if (!empty($cartCats))
            $sections[] = $this->sectionPorCarrinho($cartCats);

        return array_values(array_filter(
            $sections,
            fn($s) => !empty($s) && !empty($s['produtos'])
        ));
    }

    public function buildHomeSectionsPrepare(){
        $sections = [];

        $favIds  = $this->getFavoritosIds();
        $histIds = $this->getHistoricoIds('produto');
        $catIds  = $this->getHistoricoIds('categoria');
        $buscas  = $this->getBuscas();
        $clipCats= $this->getCategoriasDeClips();
        $marcaIds= $this->getHistoricoIds('marca');
        $cartCats= $this->getCarrinhoCategorias();

         if (count($favIds) >= self::MIN_FAV_SECAO)
            $sections['sectionFavoritos'] = $this->sectionFavoritos($favIds);

         if (count($favIds) >= self::MIN_FAV_RECO)
            $sections['sectionPorFavoritos'] = $this->sectionPorFavoritos($favIds);

        if (count($histIds) >= self::MIN_HISTORICO)
            $sections['sectionPorHistorico'] = $this->sectionPorHistorico($histIds);

        if (count($catIds) >= self::MIN_CATS)
            $sections['sectionPorCategorias'] = $this->sectionPorCategorias($catIds);

        if (count($buscas) >= self::MIN_BUSCAS)
            $sections['sectionPorBuscas'] = $this->sectionPorBuscas($buscas);

        if (count($clipCats) >= self::MIN_CLIPS)
            $sections['sectionPorClips'] = $this->sectionPorClips($clipCats);

        if (count($marcaIds) >= self::MIN_MARCAS)
            $sections['sectionPorMarcas'] = $this->sectionPorMarcas($marcaIds);

        if (!empty($cartCats))
            $sections['sectionPorCarrinho'] = $this->sectionPorCarrinho($cartCats);

        $sections['sectionMaisVendidos'] = $this->sectionMaisVendidos();
        $sections['sectionNovidades'] = $this->sectionNovidades();
        $sections['sectionPromocoes'] = $this->sectionPromocoes();
        $sections['sectionDestaque'] = $this->sectionDestaque();

        

        return $sections;
    }   

    /**
     * Função universal: retorna produtos de interesse para qualquer página.
     */
    public function getProductsOfInterest(
        int   $limit           = 12,
        array $excluirIds      = [],
        array $forcaCategorias = []
    ): array {
        $catIds  = array_unique(array_merge(
            $this->getCategoriasDosFavoritos(),
            $this->getHistoricoIds('categoria'),
            $this->getCategoriasDeClips(),
            $this->getCarrinhoCategorias(),
            $forcaCategorias
        ));
        $marcaIds = $this->getHistoricoIds('marca');

        if (!empty($catIds)) {
            $produtos = $this->product->parseClips(
                $this->product->getByFilters([
                    'categorias'  => array_slice($catIds, 0, 10),
                    'excluir_ids' => $excluirIds,
                    'order'       => 'p.criado_em DESC',
                ], $limit)
            );
            if (!empty($produtos)) return $produtos;
        }

        if (!empty($marcaIds)) {
            return $this->product->parseClips(
                $this->product->getByFilters([
                    'marcas'      => array_slice($marcaIds, 0, 5),
                    'excluir_ids' => $excluirIds,
                    'order'       => 'p.criado_em DESC',
                ], $limit)
            );
        }

        // Fallback: mais vendidos
        return $this->sectionMaisVendidos()['produtos'] ?? [];
    }

    // ════════════════════════════════════════════════════
    // SEÇÕES FIXAS
    // ════════════════════════════════════════════════════

    private function sectionNovidades(): array {
        return [
            'id'          => 'novidades',
            'titulo'      => 'Acabou de chegar',
            'subtitulo'   => 'Os últimos lançamentos',
            'tipo'        => 'fixed',
            'ver_mais_url'=> BASE_URL . '/busca?ordenar=recentes',
            'produtos'    => $this->product->parseClips(
                $this->product->getByFilters(['order' => 'p.criado_em DESC'], self::LIMITE)
            ),
        ];
    }

    private function sectionPromocoes(): array {
        return [
            'id'          => 'produtos_promocao',
            'badge'       => 'Promoções',
            'titulo'      => 'Ofertas imperdíveis',
            'subtitulo'   => 'As peças e acessórios mais recentes da nossa curadoria. ',
            'tipo'        => 'fixed',
            'ver_mais_url'=> BASE_URL . '/busca?promocao=1',
            'produtos'    => $this->product->parseClips(
                $this->product->getByFilters(['promocao' => 1, 'order' => 'p.preco_promo ASC'], self::LIMITE)
            ),
        ];
    }

    private function sectionDestaque(): array {
        return [
            'id'          => 'produtos_destaque',
            'badge'       => 'Destaque',
            'titulo'      => 'Produtos em detasque',
            'subtitulo'   => 'As peças e acessórios mais recentes da nossa curadoria. ',
            'tipo'        => 'fixed',
            'ver_mais_url'=> BASE_URL . '/busca?promocao=1',
            'produtos'    => $this->product->parseClips(
                $this->product->getFeatured(15)
            ),
        ];
    }

    private function sectionMaisVendidos(): array {
        // Busca IDs dos mais vendidos separadamente (envolve JOIN de pedidos)
        // depois hidrata com getList para ter o objeto completo
        // $stmt = $this->db->prepare(
        //     "SELECT pi2.produto_id,
        //             COALESCE(SUM(pi2.quantidade), 0) AS total_vendas
        //      FROM pedido_itens pi2
        //      JOIN pedidos ped ON ped.id = pi2.pedido_id
        //                      AND ped.status_pagamento = 'aprovado'
        //      GROUP BY pi2.produto_id
        //      ORDER BY total_vendas DESC
        //      LIMIT ?"
        // );
        // $stmt->bindValue(1, self::LIMITE, PDO::PARAM_INT);
        // $stmt->execute();
        // $ids = array_column($stmt->fetchAll(), 'produto_id');

        // if (empty($ids)) {
        //     // Fallback se não há pedidos: mais recentes
        //     $produtos = $this->product->getByFilters(
        //         ['order' => 'p.criado_em DESC'],
        //         self::LIMITE
        //     );
        // } else {
        //     $produtos = $this->product->getByFilters(
        //         ['ids' => $ids],
        //         self::LIMITE
        //     );
        // }

        return [
            'id'          => 'mais_vendidos',
            'titulo'      => 'Mais vendidos',
            'subtitulo'   => 'Os favoritos dos clientes',
            'tipo'        => 'fixed',
            'ver_mais_url'=> BASE_URL . '/busca?ordenar=vendas',
            'produtos'    => $this->product->getBestSellers(15),
        ];
    }

    // ════════════════════════════════════════════════════
    // SEÇÕES PERSONALIZADAS
    // ════════════════════════════════════════════════════

    private function sectionFavoritos(array $ids): array {
        return [
            'id'          => 'seus_favoritos',
            'titulo'      => 'Seus favoritos',
            'subtitulo'   => 'Produtos que você salvou',
            'tipo'        => 'personalized',
            'ver_mais_url'=> BASE_URL . '/minha-conta/favoritos',
            'produtos'    => $this->product->parseClips(
                $this->product->getByFilters(['ids' => $ids], self::LIMITE)
            ),
        ];
    }

    private function sectionPorFavoritos(array $favIds): array {
        $catIds = $this->getCategoriasDosFavoritos();
        if (empty($catIds)) return [];
        // Exclui apenas os IDs que o usuário JÁ tem nos favoritos
        // para não mostrar o que ele já salvou
        $produtos = $this->product->getByFilters([
            'categorias'  => $catIds,
            'excluir_ids' => $favIds,
            'order'       => 'p.criado_em DESC',
        ], self::LIMITE);

        // Se excluir todos zerou o resultado, mostra sem excluir
        if (empty($produtos)) {
            $produtos = $this->product->getByFilters([
                'categorias' => $catIds,
                'order'      => 'p.criado_em DESC',
            ], self::LIMITE);
        }

        return [
            'id'          => 'por_favoritos',
            'titulo'      => 'De acordo com seus favoritos',
            'subtitulo'   => 'Você também pode gostar',
            'tipo'        => 'personalized',
            'ver_mais_url'=> null,
            'produtos'    => $this->product->parseClips($produtos),
        ];
    }

    private function sectionPorHistorico(array $histIds): array {
        $catIds = $this->categoriasDosProdutos($histIds);
        if (empty($catIds)) return [];
        $produtos = $this->product->getByFilters([
            'categorias'  => $catIds,
            'excluir_ids' => $histIds,
            'order'       => 'p.criado_em DESC',
        ], self::LIMITE);

        // Fallback: mostra da categoria sem excluir
        if (empty($produtos)) {
            $produtos = $this->product->getByFilters([
                'categorias' => $catIds,
                'order'      => 'p.criado_em DESC',
            ], self::LIMITE);
        }

        return [
            'id'          => 'por_historico',
            'badge'       => 'Vai gostar 👀',
            'titulo'      => 'Baseado no que você viu',
            'subtitulo'   => 'Inspirado no último visto, separamos alguns itens que vocÊ vai gostar!',
            'tipo'        => 'personalized',
            'ver_mais_url'=> null,
            'produtos'    => $this->product->parseClips($produtos),
        ];
    }

    private function sectionPorCategorias(array $catIds): array {
        return [
            // Esta era a única seção sem `id`. Sem ele o app não consegue pedir
            // a próxima página dela — e a home não tinha como distinguir uma
            // seção da outra no cache.
            'id'          => 'por_categorias',
            'badge'       => 'Por categorias',
            'titulo'      => 'Das categorias que você visitou',
            'subtitulo'   => 'Separei uns itens das categorias que você visitou, vem ver!',
            'tipo'        => 'personalized',
            'ver_mais_url'=> null,
            'produtos'    => $this->product->parseClips(
                $this->product->getByFilters([
                    'categorias' => $catIds,
                    'order'      => 'p.criado_em DESC',
                ], self::LIMITE)
            ),
        ];
    }

    private function sectionPorBuscas(array $termos): array {
        if (empty($termos)) return [];

        // Tenta cada termo até encontrar resultado
        foreach ($termos as $termo) {
            $termo = trim((string)$termo);
            if (strlen($termo) < 2) continue;

            $produtos = $this->product->getByFilters([
                'busca' => $termo,
                'order' => 'p.criado_em DESC',
            ], self::LIMITE);

            if (!empty($produtos)) {
                return [
                    'id'          => 'por_buscas',
                    'badge'       => 'Por busca',
                    'titulo'      => 'Relacionado às suas buscas',
                    'subtitulo'   => 'Baseado nas suas pesquisas, separamos uns itens pra você, vem ver!',
                    'tipo'        => 'personalized',
                    'ver_mais_url'=> null,
                    'produtos'    => $this->product->parseClips($produtos),
                ];
            }
        }

        return [];
    }

    private function sectionPorClips(array $catIds): array {
        return [
            'id'          => 'por_clips',
            'badge'       => 'Por Clips',
            'titulo'      => 'Dos clips para seu carrinho',
            'subtitulo'   => 'De acordo com os clips que você assistiu, não passe vontade',
            'tipo'        => 'personalized',
            'ver_mais_url'=> null,
            'produtos'    => $this->product->parseClips(
                $this->product->getByFilters([
                    'categorias' => $catIds,
                    'order'      => 'p.criado_em DESC',
                ], self::LIMITE)
            ),
        ];
    }

    private function sectionPorMarcas(array $marcaIds): array {
        return [
            'id'          => 'por_marcas',
            'badge'       => 'Por marcas',
            'titulo'      => 'Das suas marcas favoritas',
            'subtitulo'   => 'Separamos os melhores produtos das suas marcas favoritas, não passe vontade.',
            'tipo'        => 'personalized',
            'ver_mais_url'=> null,
            'produtos'    => $this->product->parseClips(
                $this->product->getByFilters([
                    'marcas' => $marcaIds,
                    'order'  => 'p.criado_em DESC',
                ], self::LIMITE)
            ),
        ];
    }

    private function sectionPorCarrinho(array $catIds): array {
        return [
            'id'          => 'por_carrinho',
            'badge'       => 'Seu carrinho',
            'titulo'      => 'Complete seu kit',
            'subtitulo'   => 'Produtos relacionados ao seu carrinho. Aprovete parcelamento até 12x sem juros!',
            'tipo'        => 'personalized',
            'ver_mais_url'=> null,
            'produtos'    => $this->product->parseClips(
                $this->product->getByFilters([
                    'categorias' => $catIds,
                    'order'      => 'p.criado_em DESC',
                ], self::LIMITE)
            ),
        ];
    }

    // ════════════════════════════════════════════════════
    // PAGINAÇÃO DAS SEÇÕES
    // ════════════════════════════════════════════════════
    //
    // buildHomeSections() monta as seções COM os produtos da primeira página.
    // Para o carrossel continuar carregando enquanto a pessoa arrasta para o
    // lado, o servidor precisa saber remontar a MESMA consulta com outro
    // deslocamento — e é só isso que estes métodos fazem.
    //
    // A consulta é derivada do `id` da seção, e não recebida do cliente. O
    // motivo é direto: `order` entra na SQL por interpolação
    // (`ORDER BY {$order}` em Product::getList), então aceitar filtros vindos
    // do aparelho seria abrir injeção. O id é um rótulo de um catálogo fechado.

    /**
     * Uma página de produtos de uma seção.
     *
     * @param  string $id     id da seção, como aparece em buildHomeSections()
     * @param  int    $limite quantos produtos
     * @param  int    $offset a partir de qual
     * @return array<int,array>
     */
    public function produtosDaSecao(string $id, int $limite, int $offset = 0): array
    {
        $consulta = $this->consultaDaSecao($id);
        if ($consulta === null) {
            return [];
        }

        return $this->product->parseClips(
            $this->product->getByFilters($consulta, $limite, $offset)
        );
    }

    /**
     * Os filtros que definem uma seção — os mesmos que o builder dela usa.
     *
     * Devolve null para seção desconhecida, e para as personalizadas cujos
     * sinais sumiram (o cliente esvaziou os favoritos entre uma página e
     * outra, por exemplo). Nesse caso o carrossel simplesmente para de
     * crescer, que é melhor do que devolver produtos de outro critério.
     */
    private function consultaDaSecao(string $id): ?array
    {
        switch ($id) {
            case 'novidades':
                return ['order' => 'p.criado_em DESC'];

            case 'produtos_promocao':
                return ['promocao' => 1, 'order' => 'p.preco_promo ASC'];

            case 'mais_vendidos':
                // getBestSellers() é getList(['order' => 'vendidos DESC']).
                return ['order' => 'vendidos DESC'];

            case 'seus_favoritos':
                $ids = $this->getFavoritosIds();
                return $ids ? ['ids' => $ids] : null;

            case 'por_favoritos':
                $cats = $this->getCategoriasDosFavoritos();
                if (!$cats) return null;
                return $this->comFallbackDeExclusao($cats, $this->getFavoritosIds());

            case 'por_historico':
                $hist = $this->getHistoricoIds('produto');
                $cats = $this->categoriasDosProdutos($hist);
                if (!$cats) return null;
                return $this->comFallbackDeExclusao($cats, $hist);

            case 'por_categorias':
                $cats = $this->getHistoricoIds('categoria');
                return $cats ? ['categorias' => $cats, 'order' => 'p.criado_em DESC'] : null;

            case 'por_clips':
                $cats = $this->getCategoriasDeClips();
                return $cats ? ['categorias' => $cats, 'order' => 'p.criado_em DESC'] : null;

            case 'por_carrinho':
                $cats = $this->getCarrinhoCategorias();
                return $cats ? ['categorias' => $cats, 'order' => 'p.criado_em DESC'] : null;

            case 'por_marcas':
                $marcas = $this->getHistoricoIds('marca');
                return $marcas ? ['marcas' => $marcas, 'order' => 'p.criado_em DESC'] : null;

            case 'por_buscas':
                return $this->consultaDaBusca();

            default:
                return null;
        }
    }

    /**
     * Duas seções escondem produtos que o cliente já conhece — e caem para a
     * versão sem exclusão quando isso zera o resultado.
     *
     * A escolha precisa ser a MESMA em todas as páginas. Se a página 1 veio da
     * variante com exclusão e a 2 viesse da sem, produtos já mostrados
     * reapareceriam no meio do carrossel. A sonda de uma linha decide isso uma
     * vez, e sempre igual.
     */
    private function comFallbackDeExclusao(array $cats, array $excluir): array
    {
        $comExclusao = [
            'categorias'  => $cats,
            'excluir_ids' => $excluir,
            'order'       => 'p.criado_em DESC',
        ];

        if ($excluir === [] || $this->product->getByFilters($comExclusao, 1) !== []) {
            return $comExclusao;
        }

        return ['categorias' => $cats, 'order' => 'p.criado_em DESC'];
    }

    /**
     * A seção de buscas usa o PRIMEIRO termo do histórico que devolve produto.
     * Como o builder itera na mesma ordem, sondar aqui reencontra o mesmo
     * termo — e o carrossel continua de onde parou em vez de trocar de assunto
     * na segunda página.
     */
    private function consultaDaBusca(): ?array
    {
        foreach ($this->getBuscas() as $termo) {
            $termo = trim((string)$termo);
            if (strlen($termo) < 2) {
                continue;
            }

            $consulta = ['busca' => $termo, 'order' => 'p.criado_em DESC'];
            if ($this->product->getByFilters($consulta, 1) !== []) {
                return $consulta;
            }
        }

        return null;
    }

    // ════════════════════════════════════════════════════
    // COLETA DE SINAIS
    // ════════════════════════════════════════════════════

    private function getFavoritosIds(): array {
        if (!$this->clienteId) return [];
        $stmt = $this->db->prepare(
            "SELECT wi.produto_id
             FROM wishlist_itens wi
             JOIN wishlist w ON w.id = wi.wishlist_id
             WHERE w.cliente_id = ?
             ORDER BY wi.adicionado_em DESC
             LIMIT 50"
        );
        $stmt->execute([$this->clienteId]);
        return array_map('intval', array_column($stmt->fetchAll(), 'produto_id'));
    }

    private function getCategoriasDosFavoritos(): array {
        $ids = $this->getFavoritosIds();
        return $this->categoriasDosProdutos($ids);
    }

    /**
     * Existe QUALQUER histórico deste visitante na janela considerada?
     *
     * getHistoricoIds() é chamado 4 vezes (produto, categoria, marca, clip) e
     * getBuscas() uma quinta, todas com o mesmo WHERE. Para quem chega pela
     * primeira vez — o caso mais comum de todos, e o único que existe no
     * primeiro acesso do app — as cinco voltam vazias. Uma checagem barata
     * substitui as cinco. O resultado é memoizado por request.
     *
     * Não altera o que a home mostra: se não há histórico, as seções derivadas
     * dele já não eram adicionadas.
     */
    private function temHistorico(): bool {
        static $cache = null;
        if ($cache !== null) return $cache;

        $cond  = $this->clienteId ? "h.cliente_id = ?" : "h.sessao_id = ?";
        $param = $this->clienteId ?? $this->sessionKey;

        if ($param === null || $param === '') return $cache = false;

        $stmt = $this->db->prepare(
            "SELECT 1 FROM historico_navegacao h
             WHERE {$cond}
               AND h.criado_em > DATE_SUB(NOW(), INTERVAL ? DAY)
             LIMIT 1"
        );
        $stmt->execute([$param, self::JANELA_DIAS]);
        return $cache = (bool)$stmt->fetchColumn();
    }

    /** Retorna IDs do histórico por tipo (produto|categoria|marca|clip) */
    private function getHistoricoIds(string $tipo): array {
        if (!$this->temHistorico()) return [];

        $cond  = $this->clienteId ? "h.cliente_id = ?" : "h.sessao_id = ?";
        $param = $this->clienteId ?? $this->sessionKey;

        $stmt = $this->db->prepare(
            "SELECT h.referencia_id AS ref,
                    MAX(h.criado_em) AS ultima
             FROM historico_navegacao h
             WHERE {$cond}
               AND h.tipo          = ?
               AND h.referencia_id IS NOT NULL
               AND h.criado_em     > DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY h.referencia_id
             ORDER BY ultima DESC
             LIMIT 30"
        );
        $stmt->execute([$param, $tipo, self::JANELA_DIAS]);
        return array_map('intval', array_column($stmt->fetchAll(), 'ref'));
    }

    private function getBuscas(): array {
        if (!$this->temHistorico()) return [];

        $cond  = $this->clienteId ? "h.cliente_id = ?" : "h.sessao_id = ?";
        $param = $this->clienteId ?? $this->sessionKey;

        $stmt = $this->db->prepare(
            "SELECT h.termo_busca AS ref,
                    MAX(h.criado_em) AS ultima
             FROM historico_navegacao h
             WHERE {$cond}
               AND h.tipo        = 'busca'
               AND h.termo_busca IS NOT NULL
               AND h.termo_busca != ''
               AND h.criado_em   > DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY h.termo_busca
             ORDER BY ultima DESC
             LIMIT 10"
        );
        $stmt->execute([$param, self::JANELA_DIAS]);
        return array_column($stmt->fetchAll(), 'ref');
    }

    private function getCategoriasDeClips(): array {
        $ids = $this->getHistoricoIds('clip');
        if (empty($ids)) return [];

        $in   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT DISTINCT pc.categoria_id
             FROM clip_produtos cp
             JOIN produto_categorias pc ON pc.produto_id = cp.produto_id
             WHERE cp.clip_id IN ({$in})
             LIMIT 10"
        );
        $stmt->execute($ids);
        return array_map('intval', array_column($stmt->fetchAll(), 'categoria_id'));
    }

    private function getCarrinhoCategorias(): array {
        if ($this->clienteId) {
            $stmt = $this->db->prepare(
                "SELECT pc.categoria_id,
                        MAX(ca.atualizado_em) AS ultima
                 FROM carrinhos ca
                 JOIN carrinho_itens ci ON ci.carrinho_id = ca.id
                 JOIN produto_categorias pc ON pc.produto_id = ci.produto_id
                 WHERE ca.cliente_id = ?
                 GROUP BY pc.categoria_id
                 ORDER BY ultima DESC
                 LIMIT 10"
            );
            $stmt->execute([$this->clienteId]);
        } else {
            $carrinhoId = Session::getCarrinhoId();
            if (!$carrinhoId) return [];
            $stmt = $this->db->prepare(
                "SELECT DISTINCT pc.categoria_id
                 FROM carrinho_itens ci
                 JOIN produto_categorias pc ON pc.produto_id = ci.produto_id
                 WHERE ci.carrinho_id = ?
                 LIMIT 10"
            );
            $stmt->execute([$carrinhoId]);
        }
        return array_map('intval', array_column($stmt->fetchAll(), 'categoria_id'));
    }

    private function categoriasDosProdutos(array $prodIds): array {
        if (empty($prodIds)) return [];
        $ids = array_values(array_map('intval', $prodIds));
        $in  = implode(',', array_fill(0, count($ids), '?'));

        // Combina pivot (produto_categorias) + FK direta (p.categoria_id)
        // para funcionar independente de como o estoque de categorias está estruturado
        $stmt = $this->db->prepare(
            "SELECT DISTINCT categoria_id FROM produto_categorias
             WHERE produto_id IN ({$in})
             UNION
             SELECT DISTINCT categoria_id FROM produtos
             WHERE id IN ({$in}) AND categoria_id IS NOT NULL"
        );
        $stmt->execute(array_merge($ids, $ids));
        return array_map('intval', array_column($stmt->fetchAll(), 'categoria_id'));
    }

    // ════════════════════════════════════════════════════
    // REGISTRO DE SINAIS
    // ════════════════════════════════════════════════════

    public function registrar(string $tipo, string $referencia): void {
        $tipos = ['produto', 'categoria', 'busca', 'marca', 'clip'];
        if (!in_array($tipo, $tipos, true) || empty(trim($referencia))) return;

        if ($tipo === 'busca') {
            $this->db->prepare(
                "INSERT INTO historico_navegacao (cliente_id, sessao_id, tipo, termo_busca)
                 VALUES (?,?,?,?)"
            )->execute([$this->clienteId, $this->sessionKey, 'busca',
                        mb_substr(trim($referencia), 0, 200)]);
        } else {
            $rid = filter_var($referencia, FILTER_VALIDATE_INT);
            if (!$rid) return;
            $this->db->prepare(
                "INSERT INTO historico_navegacao (cliente_id, sessao_id, tipo, referencia_id)
                 VALUES (?,?,?,?)"
            )->execute([$this->clienteId, $this->sessionKey, $tipo, (int)$rid]);
        }
    }

    public static function getSessionKey(): string {
        if (session_status() === PHP_SESSION_ACTIVE) return session_id();
        if (empty($_SESSION['_psess'])) $_SESSION['_psess'] = bin2hex(random_bytes(16));
        return $_SESSION['_psess'];
    }
}