<?php
// app/controllers/AppHomeController.php
// A home do app: banners, seções personalizadas e clips em destaque.
//
// Uma chamada só. A home da web faz ~24 blocos e centenas de queries; aqui o
// alvo é ≤ 12 queries, e o que garante isso é HomeSectionPresenter montar os
// lotes do card UMA vez para todas as seções.

class AppHomeController extends AppApiController
{
    /** Zonas de banner que o app consome, na ordem em que aparecem na tela. */
    private const ZONAS = [
        'home_hero',
        'home_min_1',
        'banner_duplo_home',
        'home_mid_2',
        'home_mid_1',
    ];

    /**
     * GET /api/app/v1/home
     */
    public function index(): void
    {
        $this->bootOpcional();
        $this->liberarSessao(); // leitura pura: libera o lock antes do trabalho

        $ctx = $this->contexto();

        // PersonalizationService já recebe clienteId e sessionKey explicitamente
        // no construtor — é o único serviço da loja que não precisa da ponte.
        $personalizacao = new PersonalizationService(
            $this->clienteId,
            $ctx->sessaoKey
        );

        $secoes = HomeSectionPresenter::colecao(
            $personalizacao->buildHomeSections(),
            $ctx
        );

        $this->ok([
            'banners'        => $this->banners($ctx),
            'secoes'         => $secoes,
            'clips_destaque' => $this->clipsDestaque($ctx),
            'veiculo_ativo'  => $this->veiculoAtivo($ctx),
            'categorias'     => $this->categorias($ctx),
            'menu'           => $this->menu($ctx),
            'beneficios'     => $this->beneficios($ctx),
            'marcas'         => $this->marcasDestaque($ctx),
            'comunidade'     => $this->comunidade($ctx),
        ]);
    }

    /**
     * GET /api/app/v1/banners/{zona}
     * Zona avulsa — para telas que não são a home.
     */
    public function banner(string $zona = ''): void
    {
        $this->bootOpcional();
        $this->liberarSessao();

        $zona = preg_replace('/[^a-z0-9_]/i', '', $zona);
        if ($zona === '') {
            $this->falha(422, 'zona_invalida', 'Informe a zona do banner.');
        }

        $ctx  = $this->contexto();
        $rows = (new Banner())->getByZona($zona);

        $this->ok(['banners' => BannerPresenter::colecao($rows, $ctx)]);
    }

    /**
     * POST /api/app/v1/banners/impressao
     * Corpo: { ids: [1,2,3] }
     *
     * Em lote porque o app registra a impressão quando o carrossel para — não
     * faz sentido um POST por banner visto.
     */
    public function impressao(): void
    {
        $this->bootPublico();
        $this->liberarSessao();

        $ids = array_slice(array_filter(array_map('intval', (array)$this->campo('ids', []))), 0, 30);
        if (!$ids) {
            $this->falha(422, 'dados_invalidos', 'Informe ids.');
        }

        $modelo = new Banner();
        foreach ($ids as $id) {
            $modelo->registrarImpressao($id);
        }

        $this->ok(['registrados' => count($ids)]);
    }

    /**
     * POST /api/app/v1/banners/{id}/clique
     * Devolve o destino já traduzido, para o app navegar sem interpretar URL.
     */
    public function clique(string $id = '0'): void
    {
        $this->bootPublico();

        $bannerId = (int)$id;
        $modelo   = new Banner();
        $banner   = $modelo->find($bannerId);

        if (!$banner) {
            $this->falha(404, 'nao_encontrado', 'Banner não encontrado.');
        }

        $modelo->registrarClique(
            $bannerId,
            $this->ipReal(),
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            'app'
        );

        $this->ok(['destino' => DestinoPresenter::de($banner['link_geral'] ?? null)]);
    }

    /* =================================================================
       Blocos internos da home
       ================================================================= */

    private function banners(PresenterContext $ctx): array
    {
        // Uma query para as 5 zonas — ver Banner::getByZonas().
        $porZona = (new Banner())->getByZonas(self::ZONAS);

        $saida = [];
        foreach (self::ZONAS as $zona) { // preserva a ordem de exibição
            if (!empty($porZona[$zona])) {
                $saida[$zona] = BannerPresenter::colecao($porZona[$zona], $ctx);
            }
        }

        return $saida;
    }

    private function clipsDestaque(PresenterContext $ctx): array
    {
        try {
            $clips = (new Clip())->getFeed(1, 12, true);
        } catch (\Throwable $e) {
            LogService::error('Falha ao carregar clips da home', ['erro' => $e->getMessage()]);
            return [];
        }

        return ClipPresenter::colecao($clips, $ctx);
    }

    /**
     * A faixa de benefícios — frete grátis, garantia, parcelamento.
     *
     * Mesma consulta de views/partials/benefits-slider.php, e a mesma chave de
     * cache: mexer num benefício no admin limpa os dois de uma vez.
     *
     * @return array<int,array>
     */
    private function beneficios(PresenterContext $ctx): array
    {
        try {
            $rows = CacheHelper::get('benefits_slider');

            if (!$rows) {
                $st = $this->db()->query(
                    "SELECT icone, titulo, descricao, link, css_classe
                     FROM beneficios_slider
                     WHERE ativo = 1
                     ORDER BY ordem ASC"
                );
                $rows = $st->fetchAll();
                CacheHelper::set('benefits_slider', $rows, 3600);
            }
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'home_beneficios']);
            return [];
        }

        return $rows ? BeneficioPresenter::colecao($rows, $ctx) : [];
    }

    /**
     * "Navegue por marcas" — a grade de marcas em destaque.
     *
     * Mesma consulta de views/partials/brands-section.php: ativas, marcadas
     * como destaque, em ordem alfabética, no máximo 12.
     *
     * Reusa a chave de cache `brands_destaque` DE PROPÓSITO — é a mesma que o
     * partial da loja grava, e compartilhar significa que mexer numa marca no
     * admin limpa os dois de uma vez. (Hoje o partial ignora o cache na
     * leitura: a primeira linha dele é `$marcasDestaque = false;//CacheHelper…`,
     * comentário de depuração que ficou. Aqui a leitura é feita de verdade.)
     *
     * @return array<int,array>
     */
    private function marcasDestaque(PresenterContext $ctx): array
    {
        try {
            $rows = CacheHelper::get('brands_destaque');

            if (!$rows) {
                $st = $this->db()->query(
                    "SELECT id, nome, slug, logo, bg_cor FROM marcas
                     WHERE ativo = 1 AND destaque = 1
                     ORDER BY nome ASC LIMIT 12"
                );
                $rows = $st->fetchAll();
                CacheHelper::set('brands_destaque', $rows, 3600);
            }
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'home_marcas']);
            return [];
        }

        return $rows ? MarcaPresenter::colecao($rows, $ctx) : [];
    }

    /**
     * "A nossa comunidade" — as motos dos clientes.
     *
     * Mesma consulta de views/partials/nossos-clientes.php: só foto pública E
     * aprovada, em ordem aleatória. O RAND() é de propósito — a seção existe
     * para dar visibilidade a quem publicou, e uma ordem fixa daria sempre aos
     * mesmos.
     *
     * @return array{titulo:string,subtitulo:string,fotos:array<int,array>}|null
     */
    private function comunidade(PresenterContext $ctx): ?array
    {
        try {
            $st = $this->db()->prepare(
                "SELECT f.id, f.arquivo_thumb, f.arquivo_medium, f.arquivo_full, f.legenda,
                        c.insta_cliente,
                        cv.apelido AS moto_apelido, cv.ano AS moto_ano,
                        mm.nome AS montadora, mo.nome AS modelo
                 FROM cliente_veiculo_fotos f
                 JOIN cliente_veiculos cv ON cv.id = f.veiculo_id
                 JOIN clientes c          ON c.id  = f.cliente_id
                 JOIN moto_montadoras mm  ON mm.id = cv.montadora_id
                 LEFT JOIN moto_modelos mo ON mo.id = cv.modelo_id
                 WHERE f.visibilidade = 'publico'
                   AND f.status_moderacao = 'aprovada'
                 ORDER BY RAND()
                 LIMIT 12"
            );
            $st->execute();
            $fotos = $st->fetchAll();
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'home_comunidade']);
            return null;
        }

        if (!$fotos) {
            return null;
        }

        return [
            'titulo'    => 'A nossa comunidade',
            'subtitulo' => 'Mais de 2.000 motociclistas já estão com a gente',
            'cta'       => 'Envie sua foto',
            'fotos'     => ComunidadePresenter::colecao($fotos, $ctx),
        ];
    }

    /**
     * A moto ativa muda o catálogo inteiro (filtro de compatibilidade), então o
     * app precisa dela já na primeira tela para mostrar a barra "meu veículo".
     */
    private function veiculoAtivo(PresenterContext $ctx): ?array
    {
        if (!$ctx->temVeiculo()) {
            return null;
        }

        $v = $ctx->veiculoAtivo;

        return [
            'montadora_id' => (int)$v['montadora_id'],
            'modelo_id'    => isset($v['modelo_id']) ? (int)$v['modelo_id'] : null,
            'ano'          => isset($v['ano']) ? (int)$v['ano'] : null,
            'label'        => $v['label'] ?? null,
        ];
    }

    private function categorias(PresenterContext $ctx): array
    {
        try {
            // Mesma chamada de HomeController::index(): destaque = 1, sem
            // filtro de parent. É a grade de categorias da home, não o menu.
            $rows = (new Category())->getActive(0, true, true);
        } catch (\Throwable $e) {
            return [];
        }

        return CategoriaPresenter::colecao($rows, $ctx);
    }

    /**
     * O menu de navegação — a árvore completa de categorias, a mesma que a web
     * monta em views/layouts/main.php para o header.
     *
     * Reusa a chave de cache `menu_categorias` DE PROPÓSITO: o admin já a
     * invalida ao salvar uma categoria (CategoriasController:118,132), então
     * app e site trocam o menu no mesmo instante. Uma chave própria significaria
     * o app exibindo uma categoria excluída por até uma hora.
     */
    private function menu(PresenterContext $ctx): array
    {
        try {
            $arvore = CacheHelper::get('menu_categorias');
            if (!$arvore) {
                $arvore = (new Category())->getNavTree();
                CacheHelper::set('menu_categorias', $arvore, 3600);
            }
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'menu_home']);
            return [];
        }

        return CategoriaPresenter::arvore($arvore, $ctx);
    }
}
