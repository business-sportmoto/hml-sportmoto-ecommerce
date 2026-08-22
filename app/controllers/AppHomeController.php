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
            $rows = (new Category())->getActive();
        } catch (\Throwable $e) {
            return [];
        }

        return CategoriaPresenter::colecao($rows, $ctx);
    }
}
