<?php
// admin/config/routes.php

class AdminRouter {

    private static array $routes = [];

    public static function get(string $p, string $h): void  { self::$routes[] = ['GET',  $p, $h]; }
    public static function post(string $p, string $h): void { self::$routes[] = ['POST', $p, $h]; }
    public static function any(string $p, string $h): void  { self::$routes[] = ['ANY',  $p, $h]; }

    public static function dispatch(): void {
        $method = strtoupper($_SERVER['REQUEST_METHOD']);
        $uri    = self::getUri();

        foreach (self::$routes as [$rm, $pattern, $handler]) {
            if ($rm !== 'ANY' && $rm !== $method) continue;
            $regex = '#^' . preg_replace('/\{([a-z_]+):([^}]+)\}/', '($2)',
                     preg_replace('/\{([a-z_]+)\}/', '([^/]+)',
                     str_replace('/', '\/', $pattern))) . '$#i';
            if (preg_match($regex, $uri, $m)) {
                array_shift($m);
                [$ctrl, $action] = explode('@', $handler);
                (new $ctrl())->$action(...$m);
                return;
            }
        }

        http_response_code(404);
        echo '<h1>404</h1>';
    }

    private static function getUri(): string {
        $uri      = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $base     = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        if ($base && str_starts_with($uri, $base)) $uri = substr($uri, strlen($base));
        return '/' . ltrim($uri, '/');
    }
}

// ── Autenticação do admin ─────────────────────────────────
AdminRouter::get('/',                              'AdminAuthController@loginForm');
AdminRouter::get('/login',                         'AdminAuthController@loginForm');
AdminRouter::post('/login',                        'AdminAuthController@login');
AdminRouter::get('/logout',                        'AdminAuthController@logout');

// ── Dashboard ─────────────────────────────────────────────
AdminRouter::get('/dashboard',                     'DashboardController@index');
AdminRouter::post('/icons/salvar',                 'IconAdminController@store');
AdminRouter::post('/icons/update',                 'IconAdminController@update');

// admin/config/routes.php
AdminRouter::post('/seo-ia/gerar', 'SeoIaController@gerar');

AdminRouter::get ('/notificacoes',                       'NotificacaoAdminController@index');
AdminRouter::post('/notificacoes/enviar',                'NotificacaoAdminController@enviar');
AdminRouter::post('/notificacoes/upload-img',            'NotificacaoAdminController@uploadImg');
AdminRouter::get ('/notificacoes/buscar-destinatarios',  'NotificacaoAdminController@buscarDestinatarios');


AdminRouter::get('/api/buscar-marcas',          'AdminApiController@buscarMarcas');
AdminRouter::get('/api/buscar-categorias',      'AdminApiController@buscarCategorias');
AdminRouter::get('/api/buscar-produtos',        'AdminApiController@buscarProdutos');
AdminRouter::get('/api/buscar-caracteristicas', 'AdminApiController@buscarCaracteristicas');

// IMPORTANTE: essas rotas devem ficar DEPOIS das rotas estáticas do admin
// e ANTES das rotas com parâmetros dinâmicos {id:\d+} — mesmo padrão
// já usado em outras rotas estáticas vs dinâmicas do projeto.

// Admin
AdminRouter::get ('/carrinhos-abandonados',                    'AdminCarrinhoAbandonadoController@index');

AdminRouter::post('/media/stream-upload-url', 'MediaStreamController@uploadUrl');
AdminRouter::get('/media/stream-status',      'MediaStreamController@status');

// ⚠ Declarar ANTES de '/admin/carrinhos-abandonados/{id:\d+}' —
// garante que "templates" nunca seja engolido por um router que
// teste padrões em ordem de declaração.
AdminRouter::get ('/carrinhos-abandonados/templates',                  'AdminCarrinhoAbandonadoController@templatesIndex');
AdminRouter::get ('/carrinhos-abandonados/templates/novo',             'AdminCarrinhoAbandonadoController@templatesNovo');
AdminRouter::post('/carrinhos-abandonados/templates/novo',             'AdminCarrinhoAbandonadoController@templatesCriar');
AdminRouter::get ('/carrinhos-abandonados/templates/{id:\d+}',         'AdminCarrinhoAbandonadoController@templatesEditar');
AdminRouter::post('/carrinhos-abandonados/templates/{id:\d+}',         'AdminCarrinhoAbandonadoController@templatesAtualizar');
AdminRouter::post('/carrinhos-abandonados/templates/{id:\d+}/toggle',  'AdminCarrinhoAbandonadoController@templatesToggle');
AdminRouter::post('/carrinhos-abandonados/templates/{id:\d+}/excluir', 'AdminCarrinhoAbandonadoController@templatesExcluir');

AdminRouter::get ('/carrinhos-abandonados/config',              'AdminCarrinhoAbandonadoController@configForm');
AdminRouter::post('/carrinhos-abandonados/config',              'AdminCarrinhoAbandonadoController@configSalvar');
AdminRouter::get ('/carrinhos-abandonados/relatorio-templates', 'AdminCarrinhoAbandonadoController@relatorioTemplates');
AdminRouter::post('/carrinhos-abandonados/{id:\d+}/capturar', 'AdminCarrinhoAbandonadoController@capturar');

AdminRouter::get ('/carrinhos-abandonados/dashboard',          'AdminCarrinhoAbandonadoController@dashboard');
AdminRouter::get ('/carrinhos-abandonados/exportar',           'AdminCarrinhoAbandonadoController@exportar');
AdminRouter::get ('/carrinhos-abandonados/{id:\d+}',           'AdminCarrinhoAbandonadoController@show');
AdminRouter::post('/carrinhos-abandonados/{id:\d+}/status',      'AdminCarrinhoAbandonadoController@mudarStatus');
AdminRouter::post('/carrinhos-abandonados/{id:\d+}/responsavel', 'AdminCarrinhoAbandonadoController@atribuir');
AdminRouter::post('/carrinhos-abandonados/{id:\d+}/anotacao',    'AdminCarrinhoAbandonadoController@anotar');
AdminRouter::post('/carrinhos-abandonados/{id:\d+}/agendar',     'AdminCarrinhoAbandonadoController@agendar');
AdminRouter::post('/carrinhos-abandonados/{id:\d+}/whatsapp',    'AdminCarrinhoAbandonadoController@whatsapp');
AdminRouter::post('/carrinhos-abandonados/{id:\d+}/email',       'AdminCarrinhoAbandonadoController@email');
AdminRouter::post('/carrinhos-abandonados/{id:\d+}/link',        'AdminCarrinhoAbandonadoController@gerarLink');
// Público

AdminRouter::get ('/usuarios',                 'AdminUsuarioController@index');
AdminRouter::get ('/usuarios/novo',            'AdminUsuarioController@novo');
AdminRouter::post('/usuarios/novo',            'AdminUsuarioController@criar');
AdminRouter::get ('/usuarios/{id:\d+}',        'AdminUsuarioController@editar');
AdminRouter::post('/usuarios/{id:\d+}',        'AdminUsuarioController@atualizar');
AdminRouter::post('/usuarios/{id:\d+}/toggle', 'AdminUsuarioController@toggle');
AdminRouter::post('/logout',                   'AdminAuthController@logout');

AdminRouter::get ('/usuarios/buscar',          'AdminUsuarioController@buscar');       // Ajax
AdminRouter::post('/usuarios/promover',        'AdminUsuarioController@promover');
AdminRouter::get ('/usuarios/vendas',          'AdminUsuarioController@vendas');
AdminRouter::get ('/usuarios/vendas/{id:\d+}', 'AdminUsuarioController@vendasVendedor');

// as de index/editar/atualizar/toggle continuam iguais
// ⚠ /buscar, /promover e /vendas ANTES de /{id} genérica

// ── Produtos ──────────────────────────────────────────────
// AdminRouter::get('/produtos',                      'ProductAdminController@index');
// AdminRouter::get('/produtos/novo',                 'ProductAdminController@create');
// AdminRouter::post('/produtos/novo',                'ProductAdminController@store');
// AdminRouter::get('/produtos/editar/{id:\d+}',      'ProductAdminController@edit');
// AdminRouter::post('/produtos/editar/{id:\d+}',     'ProductAdminController@update');
// AdminRouter::post('/produtos/excluir',             'ProductAdminController@delete');
// AdminRouter::post('/produtos/imagem/upload',       'ProductAdminController@uploadImage');
// AdminRouter::post('/produtos/imagem/excluir',      'ProductAdminController@deleteImage');
// AdminRouter::post('/produtos/imagem/principal',    'ProductAdminController@setMainImage');

AdminRouter::get ('/promocoes',                  'AdminPromocaoController@index');
AdminRouter::get ('/promocoes/nova',              'AdminPromocaoController@nova');
AdminRouter::post('/promocoes/nova',              'AdminPromocaoController@criar');
AdminRouter::get ('/promocoes/{id:\d+}',          'AdminPromocaoController@show');
AdminRouter::post('/promocoes/{id:\d+}',          'AdminPromocaoController@atualizar');
AdminRouter::post('/promocoes/{id:\d+}/toggle',   'AdminPromocaoController@toggle');
AdminRouter::post('/promocoes/{id:\d+}/excluir',  'AdminPromocaoController@excluir');

// ── Categorias ────────────────────────────────────────────
// AdminRouter::get('/categorias',                    'CategoryAdminController@index');
// AdminRouter::post('/categorias/salvar',            'CategoryAdminController@save');
// AdminRouter::post('/categorias/excluir',           'CategoryAdminController@delete');
// AdminRouter::post('/categorias/ordenar',           'CategoryAdminController@reorder');
AdminRouter::get( '/categorias',              'CategoriasController@index');
AdminRouter::get( '/categorias/criar',        'CategoriasController@criar');
AdminRouter::get( '/categorias/{id}/editar',  'CategoriasController@editar');
AdminRouter::post('/categorias/salvar',       'CategoriasController@salvar');
AdminRouter::post('/categorias/excluir',      'CategoriasController@excluir');
AdminRouter::post('/categorias/toggle-ativo', 'CategoriasController@toggleAtivo');
AdminRouter::post('/categorias/reordenar',    'CategoriasController@reordenar');

AdminRouter::get( '/atributos',          'AtributosController@index');
AdminRouter::post('/atributos/salvar',   'AtributosController@salvar');
AdminRouter::post('/atributos/excluir',  'AtributosController@excluir');
AdminRouter::post('/atributos/reordenar','AtributosController@reordenar');

AdminRouter::get( '/atributos/valores',         'AtributosController@valores');
AdminRouter::post('/atributos/valor/salvar',    'AtributosController@salvarValor');
AdminRouter::post('/atributos/valor/excluir',   'AtributosController@excluirValor');

// Montadora/modelo/ano para compatibilidade
// admin/config/routes.php
// AdminRouter::get( '/motos',            'MotosSincController@index');
// AdminRouter::post('/motos/sincronizar','MotosSincController@sincronizar');
// AdminRouter::post('/motos/thumb',      'MotosSincController@uploadThumb');

AdminRouter::get( '/motos',                  'MotosSincController@index');
AdminRouter::get( '/motos/modelos',          'MotosSincController@modelos');
AdminRouter::post('/motos/sincronizar',      'MotosSincController@sincronizar');
AdminRouter::post('/motos/thumb',            'MotosSincController@uploadThumb');
AdminRouter::post('/motos/salvar-montadora', 'MotosSincController@salvarMontadora');
AdminRouter::post('/motos/salvar-modelo',    'MotosSincController@salvarModelo');
AdminRouter::post('/motos/excluir-montadora','MotosSincController@excluirMontadora');
AdminRouter::post('/motos/toggle-ativo',     'MotosSincController@toggleAtivo');

AdminRouter::post('/motos/sync/iniciar',   'MotosSincController@syncIniciar');
AdminRouter::post('/motos/sync/marcas',    'MotosSincController@syncMarcas');
AdminRouter::post('/motos/sync/modelos',   'MotosSincController@syncModelos');
AdminRouter::post('/motos/sync/anos',      'MotosSincController@syncAnos');
AdminRouter::post('/motos/sync/finalizar', 'MotosSincController@syncFinalizar');

// admin/config/routes.php
AdminRouter::get( '/moderacao/fotos',          'ModeracaoFotosController@index');
AdminRouter::post('/moderacao/fotos/aprovar',  'ModeracaoFotosController@aprovar');
AdminRouter::post('/moderacao/fotos/rejeitar', 'ModeracaoFotosController@rejeitar');

// ── Marcas ────────────────────────────────────────────────
// AdminRouter::get('/marcas',                        'BrandAdminController@index');
// AdminRouter::post('/marcas/salvar',                'BrandAdminController@save');
// AdminRouter::post('/marcas/excluir',               'BrandAdminController@delete');

AdminRouter::get( '/marcas',              'MarcasController@index');
AdminRouter::get( '/marcas/criar',        'MarcasController@criar');
AdminRouter::get( '/marcas/{id}/editar',  'MarcasController@editar');
AdminRouter::post('/marcas/salvar',       'MarcasController@salvar');
AdminRouter::post('/marcas/excluir',      'MarcasController@excluir');
AdminRouter::post('/marcas/toggle-ativo', 'MarcasController@toggleAtivo');

// ── Pedidos ───────────────────────────────────────────────
// AdminRouter::get('/pedidos',                       'OrderAdminController@index');
// AdminRouter::get('/pedidos/{id:\d+}',              'OrderAdminController@detail');
// AdminRouter::post('/pedidos/status',               'OrderAdminController@updateStatus');
// AdminRouter::post('/pedidos/rastreio',             'OrderAdminController@updateTracking');

AdminRouter::get ('/pedidos',                          'AdminPedidoController@index');
AdminRouter::get ('/pedidos/novo',                     'AdminPedidoController@novoForm');
AdminRouter::post('/pedidos/novo',                     'AdminPedidoController@criarManual');
AdminRouter::get ('/pedidos/buscar-cliente',           'AdminPedidoController@buscarCliente');
AdminRouter::get ('/pedidos/buscar-produto',           'AdminPedidoController@buscarProduto');
AdminRouter::get ('/pedidos/enderecos/{id:\d+}',       'AdminPedidoController@enderecosPorCliente');
AdminRouter::get ('/pedidos/opcoes-envio',              'AdminPedidoController@opcoesEnvio');

// Checkout de expedicao — literais antes de /pedidos/{id}, e 'imprimir' antes
// de 'checkout/{id}', senao 'imprimir' seria capturado como id.
AdminRouter::get ('/pedidos/checkout',                  'AdminSeparacaoController@index');
AdminRouter::get ('/pedidos/checkout/imprimir',         'AdminSeparacaoController@imprimir');
AdminRouter::get ('/pedidos/checkout/estacao',           'AdminSeparacaoController@estacao');
AdminRouter::post('/pedidos/checkout/estacao/buscar',    'AdminSeparacaoController@estacaoBuscar');
AdminRouter::post('/pedidos/checkout/bipar',            'AdminSeparacaoController@bipar');
AdminRouter::get ('/pedidos/checkout/{id:\d+}',         'AdminSeparacaoController@conferir');
AdminRouter::post('/pedidos/checkout/{id:\d+}/etiqueta','AdminSeparacaoController@gerarEtiqueta');
AdminRouter::get ('/pedidos/{id:\d+}',                 'AdminPedidoController@show');
AdminRouter::post('/pedidos/{id:\d+}/status',          'AdminPedidoController@updateStatus');
AdminRouter::post('/pedidos/{id:\d+}/rastreio',        'AdminPedidoController@updateRastreio');
AdminRouter::post('/pedidos/{id:\d+}/etiqueta',        'AdminPedidoController@gerarEtiqueta');
AdminRouter::post('/pedidos/{id:\d+}/pagamento',       'AdminPedidoController@updatePagamento');
AdminRouter::post('/pedidos/{id:\d+}/item/add',        'AdminPedidoController@addItem');
AdminRouter::post('/pedidos/{id:\d+}/item/{iid:\d+}',  'AdminPedidoController@updateItem');
AdminRouter::post('/pedidos/{id:\d+}/item/{iid:\d+}/del', 'AdminPedidoController@removeItem');
AdminRouter::post('/pedidos/{id:\d+}/observacao',      'AdminPedidoController@addObservacao');
AdminRouter::post('/pedidos/{id:\d+}/nfe',             'AdminPedidoController@salvarNfe');

// ── Clientes ──────────────────────────────────────────────
// AdminRouter::get('/clientes',                      'CustomerAdminController@index');
// AdminRouter::get('/clientes/{id:\d+}',             'CustomerAdminController@detail');
// AdminRouter::post('/clientes/status',              'CustomerAdminController@toggleStatus');

// // ── Score + Crédito (admin) ───────────────────────────────
AdminRouter::get ('/clientes/{id:\d+}/score-credito',              'AdminCreditoScoreController@index');
AdminRouter::post('/clientes/{id:\d+}/score/recalcular',           'AdminCreditoScoreController@recalcularScore');
AdminRouter::post('/clientes/{id:\d+}/score/override',             'AdminCreditoScoreController@overrideScore');
AdminRouter::post('/clientes/{id:\d+}/score/remover-override',     'AdminCreditoScoreController@removerOverride');
AdminRouter::post('/clientes/{id:\d+}/credito/lancar',             'AdminCreditoScoreController@lancarCredito');
AdminRouter::post('/clientes/{id:\d+}/credito/debitar',            'AdminCreditoScoreController@debitarCredito');

AdminRouter::post('/clientes/{id:\d+}/sync-bling', 'AdminClienteController@syncBling');

// Admin — devolução
AdminRouter::get ('/devolucoes',                                'AdminDevolucaoController@index');
AdminRouter::get ('/devolucoes/{id:\d+}',                      'AdminDevolucaoController@show');
AdminRouter::post('/devolucoes/{id:\d+}/aprovar',              'AdminDevolucaoController@aprovar');
AdminRouter::post('/devolucoes/{id:\d+}/negar',                'AdminDevolucaoController@negar');
AdminRouter::post('/devolucoes/{id:\d+}/receber',              'AdminDevolucaoController@confirmarRecebimento');
AdminRouter::post('/devolucoes/{id:\d+}/inspecionar',          'AdminDevolucaoController@inspecionar');
AdminRouter::post('/devolucoes/{id:\d+}/reembolsar',           'AdminDevolucaoController@reembolsar');
AdminRouter::get ('/devolucoes/motivos',                        'AdminDevolucaoController@motivos');
AdminRouter::post('/devolucoes/motivos/salvar',                 'AdminDevolucaoController@salvarMotivo');

// ── Cupons ────────────────────────────────────────────────
// AdminRouter::get('/cupons',                        'CouponAdminController@index');
// AdminRouter::get('/cupons/novo',                   'CouponAdminController@create');
// AdminRouter::post('/cupons/salvar',                'CouponAdminController@save');
// AdminRouter::post('/cupons/excluir',               'CouponAdminController@delete');
// AdminRouter::post('/cupons/toggle',                'CouponAdminController@toggle');

// ── Banners ───────────────────────────────────────────────
// AdminRouter::get('/banners',                       'BannerAdminController@index');
// AdminRouter::post('/banners/salvar',               'BannerAdminController@save');
// AdminRouter::post('/banners/excluir',              'BannerAdminController@delete');
// AdminRouter::post('/banners/toggle',               'BannerAdminController@toggle');
// AdminRouter::post('/banners/ordenar',              'BannerAdminController@reorder');

// admin/config/routes.php
AdminRouter::get( '/banners',              'BannersController@index');
AdminRouter::get( '/banners/form',         'BannersController@form');
AdminRouter::post('/banners/salvar',       'BannersController@salvar');
AdminRouter::post('/banners/excluir',      'BannersController@excluir');
AdminRouter::post('/banners/toggle-ativo', 'BannersController@toggleAtivo');
AdminRouter::post('/banners/reordenar',    'BannersController@reordenar');

AdminRouter::get( '/banner-zonas',              'BannerSlotsController@index');
AdminRouter::get( '/banner-zonas/form',         'BannerSlotsController@form');
AdminRouter::post('/banner-zonas/salvar',       'BannerSlotsController@salvar');
AdminRouter::post('/banner-zonas/toggle-ativo', 'BannerSlotsController@toggleAtivo');
AdminRouter::post('/banner-zonas/excluir',      'BannerSlotsController@excluir');

AdminRouter::post('/banners/stream-upload-url', 'BannersController@streamUploadUrl');
AdminRouter::get('/banners/stream-status',      'BannersController@streamStatus');

// ── Páginas ───────────────────────────────────────────────
AdminRouter::get('/paginas',                       'PageAdminController@index');
AdminRouter::get('/paginas/editar/{id:\d+}',       'PageAdminController@edit');
AdminRouter::post('/paginas/salvar',               'PageAdminController@save');

// ── Configurações ─────────────────────────────────────────
// AdminRouter::get('/configuracoes',                 'SettingsAdminController@index');
// AdminRouter::post('/configuracoes/salvar',         'SettingsAdminController@save');
// AdminRouter::post('/configuracoes/logo',           'SettingsAdminController@uploadLogo');

AdminRouter::get ('/configuracoes',                             'SettingsAdminController@index');
AdminRouter::post('/configuracoes/salvar',                     'SettingsAdminController@salvar');
AdminRouter::post('/configuracoes/salvar-grupo',               'SettingsAdminController@salvarGrupo');
AdminRouter::get ('/configuracoes/status-pedidos',              'AdminStatusPedidoController@index');
AdminRouter::get ('/configuracoes/status-pedidos/dados/{id:\d+}','AdminStatusPedidoController@dados');
AdminRouter::post('/configuracoes/status-pedidos/salvar',       'AdminStatusPedidoController@salvar');
AdminRouter::post('/configuracoes/status-pedidos/excluir',      'AdminStatusPedidoController@excluir');
AdminRouter::post('/configuracoes/status-pedidos/reordenar',    'AdminStatusPedidoController@reordenar');

// ── Configuração de pagamentos ──────────────────────────────────────
// Formas: super+gerente (impacto financeiro). Adquirentes: super (credenciais).
// A permissão real é aplicada no controller, método a método.
AdminRouter::get ('/pagamentos/formas',                  'AdminPagamentoConfigController@formas');
AdminRouter::post('/pagamentos/formas/salvar',           'AdminPagamentoConfigController@salvarForma');
AdminRouter::post('/pagamentos/formas/simular',          'AdminPagamentoConfigController@simularForma');
AdminRouter::get ('/pagamentos/adquirentes',             'AdminPagamentoConfigController@adquirentes');
AdminRouter::get ('/pagamentos/analise',                 'AdminAnaliseController@index');
AdminRouter::get ('/pagamentos/analise/{id:\d+}',        'AdminAnaliseController@detalhe');
AdminRouter::post('/pagamentos/analise/aprovar',         'AdminAnaliseController@aprovar');
AdminRouter::post('/pagamentos/analise/recusar',         'AdminAnaliseController@recusar');
AdminRouter::get ('/pagamentos/fluxos',                  'AdminPagamentoFluxoController@index');
AdminRouter::get ('/pagamentos/fluxos/editor',           'AdminPagamentoFluxoController@editor');
AdminRouter::post('/pagamentos/fluxos/salvar',           'AdminPagamentoFluxoController@salvar');
AdminRouter::post('/pagamentos/fluxos/publicar',         'AdminPagamentoFluxoController@publicar');
AdminRouter::post('/pagamentos/fluxos/rascunho',         'AdminPagamentoFluxoController@novoRascunho');
AdminRouter::post('/pagamentos/adquirentes/salvar',      'AdminPagamentoConfigController@salvarAdquirente');
AdminRouter::post('/pagamentos/adquirentes/alternar',    'AdminPagamentoConfigController@alternarAdquirente');
AdminRouter::post('/pagamentos/adquirentes/testar',      'AdminPagamentoConfigController@testarAdquirente');
AdminRouter::post('/pagamentos/adquirentes/logo',        'AdminPagamentoConfigController@logoAdquirente');

AdminRouter::get ('/configuracoes/pwa',                   'AdminPwaController@index');
AdminRouter::post('/configuracoes/pwa/salvar',            'AdminPwaController@salvar');
AdminRouter::post('/configuracoes/pwa/gerar-icones',      'AdminPwaController@gerarIcones');
AdminRouter::post('/configuracoes/pwa/publicar',          'AdminPwaController@publicar');

// AdminRouter::get ('/configuracoes/bling',                          'AdminBlingController@index');
// AdminRouter::post('/configuracoes/bling/credenciais',              'AdminBlingController@salvarCredenciais');
// AdminRouter::get ('/configuracoes/bling/autorizar',                'AdminBlingController@autorizar');
// AdminRouter::get ('/configuracoes/bling/callback',                 'AdminBlingController@callback');
// AdminRouter::post('/configuracoes/bling/desconectar',              'AdminBlingController@desconectar');
// AdminRouter::post('/configuracoes/bling/sync-estoque',             'AdminBlingController@syncEstoque');
// AdminRouter::post('/configuracoes/bling/enviar-pedido',            'AdminBlingController@enviarPedido');

AdminRouter::get ('/configuracoes/bling',                          'AdminBlingController@index');
AdminRouter::post('/configuracoes/bling/credenciais',              'AdminBlingController@salvarCredenciais');
AdminRouter::get ('/configuracoes/bling/autorizar',                'AdminBlingController@autorizar');
AdminRouter::get ('/configuracoes/bling/callback',                 'AdminBlingController@callback');
AdminRouter::post('/configuracoes/bling/desconectar',              'AdminBlingController@desconectar');
AdminRouter::post('/configuracoes/bling/sync-estoque',             'AdminBlingController@syncEstoque');
AdminRouter::post('/configuracoes/bling/enviar-pedido',            'AdminBlingController@enviarPedido');
AdminRouter::post('/configuracoes/bling/forcar-sync',              'AdminBlingController@forcarSincronizacao');
AdminRouter::get ('/configuracoes/bling/status-map',               'AdminBlingController@getStatusMap');
AdminRouter::post('/configuracoes/bling/status-map',               'AdminBlingController@salvarStatusMap');
AdminRouter::get ('/configuracoes/bling/situacoes',                'AdminBlingController@getSituacoesBling');

AdminRouter::post('/configuracoes/bling/sync-depositos', 'AdminBlingController@syncDepositos');
AdminRouter::post('/configuracoes/bling/vincular-produtos', 'AdminBlingController@vincularProdutos');

AdminRouter::post('/configuracoes/bling/vincular-contatos', 'AdminBlingController@vincularContatos');
AdminRouter::post('/configuracoes/bling/processar-contatos', 'AdminBlingController@processarContatos');

AdminRouter::get('/logs',          'AdminLogsController@index');
AdminRouter::get('/logs/detalhe',  'AdminLogsController@detalhe');
AdminRouter::post('/logs/resolver','AdminLogsController@resolver');
AdminRouter::post('/logs/limpar',  'AdminLogsController@limpar');

// ── Avaliações ────────────────────────────────────────────
// AdminRouter::get('/avaliacoes',                    'ReviewAdminController@index');
// AdminRouter::post('/avaliacoes/aprovar',           'ReviewAdminController@approve');
// AdminRouter::post('/avaliacoes/excluir',           'ReviewAdminController@delete');

// ── API Ajax interna ──────────────────────────────────────
AdminRouter::get('/api/dashboard-stats',           'DashboardController@stats');
AdminRouter::post('/api/estoque',                  'ProductAdminController@updateStock');

// config/routes.php
// Router::get( '/admin/beneficios',        'Admin\BeneficiosController@index');
// Router::post('/admin/beneficios/salvar', 'Admin\BeneficiosController@salvar');

// admin/config/routes.php — verificar se existe:
AdminRouter::get('/beneficios',        'BeneficiosController@index');
AdminRouter::post('/beneficios/salvar','BeneficiosController@salvar');


AdminRouter::get( '/produtos',                    'ProdutosController@index');
AdminRouter::get( '/produtos/criar',              'ProdutosController@criar');
AdminRouter::get( '/produtos/{id}/editar',        'ProdutosController@editar');
AdminRouter::post('/produtos/salvar',             'ProdutosController@salvar');
AdminRouter::post('/produtos/upload-imagem',      'ProdutosController@uploadImagem');
AdminRouter::post('/produtos/set-principal',      'ProdutosController@setPrincipal');
AdminRouter::post('/produtos/remover-imagem',     'ProdutosController@removerImagem');
AdminRouter::post('/produtos/reordenar-imagens',  'ProdutosController@reordenarImagens');
AdminRouter::post('/produtos/excluir',            'ProdutosController@excluir');
AdminRouter::post('/produtos/toggle-ativo',       'ProdutosController@toggleAtivo');
AdminRouter::post('/produtos/{id:\d+}/sync-bling','ProdutosController@syncBling');


// admin/config/routes.php
AdminRouter::get( '/familias/buscar',     'FamiliasController@buscar');
AdminRouter::post('/familias/criar',      'FamiliasController@criar');
AdminRouter::post('/familias/renomear',   'FamiliasController@renomear');
AdminRouter::post('/familias/excluir',    'FamiliasController@excluir');
AdminRouter::post('/familias/vincular',   'FamiliasController@vincular');
AdminRouter::post('/familias/desvincular','FamiliasController@desvincular');

// admin/config/routes.php - tinha erro aqui
AdminRouter::get('/estoque/saldo',      'Estoquecontroller@saldo');
AdminRouter::post('/estoque/ajustar',     'Estoquecontroller@ajustar');
AdminRouter::get( '/estoque/historico',   'Estoquecontroller@historico');
AdminRouter::post('/estoque/recalcular',  'Estoquecontroller@recalcular');
AdminRouter::post('/estoque/sincronizar', 'Estoquecontroller@sincronizar');
AdminRouter::post('/estoque/ajustar-sku', 'Estoquecontroller@ajustarSku');

// ─── Admin ───────────────────────────────────────────────────
AdminRouter::get( '/help-faq',                          'HelpFaqController@index');
 
// Categorias
AdminRouter::get( '/help-faq/categoria/nova',           'HelpFaqController@novaCategoria');
AdminRouter::post('/help-faq/categoria/salvar',         'HelpFaqController@salvarCategoria');
AdminRouter::get( '/help-faq/categoria/editar/{id}',    'HelpFaqController@editarCategoria');
AdminRouter::post('/help-faq/categoria/excluir',        'HelpFaqController@excluirCategoria');
 
// Perguntas
AdminRouter::get( '/help-faq/perguntas',                'HelpFaqController@perguntas');
AdminRouter::get( '/help-faq/pergunta/nova',            'HelpFaqController@novaPergunta');
AdminRouter::post('/help-faq/pergunta/salvar',          'HelpFaqController@salvarPergunta');
AdminRouter::get( '/help-faq/pergunta/editar/{id}',     'HelpFaqController@editarPergunta');
AdminRouter::post('/help-faq/pergunta/excluir',         'HelpFaqController@excluirPergunta');

// Retorna fragmento HTML do formulário de categoria
AdminRouter::get('/help-faq/categoria/form',            'HelpFaqController@formCategoria');
 // Retorna fragmento HTML do formulário de pergunta
AdminRouter::get('/help-faq/pergunta/form',             'HelpFaqController@formPergunta');

// admin/config/routes.php
AdminRouter::get( '/caracteristicas',                 'CaracteristicasController@index');
AdminRouter::post('/caracteristicas/salvar',          'CaracteristicasController@salvar');
AdminRouter::post('/caracteristicas/excluir',         'CaracteristicasController@excluir');
AdminRouter::post('/caracteristicas/reordenar',       'CaracteristicasController@reordenar');
AdminRouter::get( '/caracteristicas/por-categoria',   'CaracteristicasController@porCategoria');
AdminRouter::post('/categorias/salvar-caracteristicas','CategoriasController@salvarCaracteristicas');
AdminRouter::get( '/categorias/get-caracteristicas',  'CategoriasController@getCaracteristicas');

AdminRouter::get( '/produtos/skus-para-edicao',   'ProdutosController@skusParaEdicao');
AdminRouter::post('/produtos/alterar-preco',       'ProdutosController@alterarPreco');
AdminRouter::post('/produtos/alterar-preco-sku',   'ProdutosController@alterarPrecoSku');

//Clips
AdminRouter::get( '/clips',                       'ClipsController@index');
AdminRouter::get( '/clips/form',                  'ClipsController@form');
AdminRouter::post('/clips/salvar',                'ClipsController@salvar');
AdminRouter::post('/clips/excluir',               'ClipsController@excluir');
AdminRouter::post('/clips/toggle-ativo',          'ClipsController@toggleAtivo');
AdminRouter::post('/clips/toggle-destaque',       'ClipsController@toggleDestaque');
AdminRouter::get( '/clips/comentarios',           'ClipsController@comentarios');
AdminRouter::post('/clips/moderar-comentario',    'ClipsController@moderarComentario');
AdminRouter::post('/clips/gerar-poster',          'ClipsController@gerarPoster');

//Avaliações
AdminRouter::get( '/avaliacoes',                    'AvaliacoesController@index');
AdminRouter::post('/avaliacoes/aprovar',            'AvaliacoesController@aprovar');
AdminRouter::post('/avaliacoes/rejeitar',           'AvaliacoesController@rejeitar');
AdminRouter::post('/avaliacoes/excluir',            'AvaliacoesController@excluir');
AdminRouter::post('/avaliacoes/toggle-destaque',    'AvaliacoesController@toggleDestaque');

//Perguntas
AdminRouter::get( '/perguntas',           'PerguntasController@index');
AdminRouter::post('/perguntas/responder', 'PerguntasController@responder');
AdminRouter::post('/perguntas/rejeitar',  'PerguntasController@rejeitar');

//Power BI
AdminRouter::post('/power-bi', 'PowerBIController@index');

AdminRouter::get ('/cupons',              'AdminCouponController@index');
AdminRouter::get ('/cupons/form',         'AdminCouponController@form');
AdminRouter::post('/cupons/salvar',       'AdminCouponController@salvar');
AdminRouter::post('/cupons/toggle-ativo', 'AdminCouponController@toggleAtivo');
AdminRouter::post('/cupons/excluir',      'AdminCouponController@excluir');
AdminRouter::get ('/cupons/historico',    'AdminCouponController@historico');
AdminRouter::get ('/cupons/relatorio',    'AdminCouponController@relatorio');


// ── Score + Crédito (admin) ───────────────────────────────
AdminRouter::get ('/clientes/{id:\d+}/score-credito',              'AdminCreditoScoreController@index');
AdminRouter::post('/clientes/{id:\d+}/score/recalcular',           'AdminCreditoScoreController@recalcularScore');
AdminRouter::post('/clientes/{id:\d+}/score/override',             'AdminCreditoScoreController@overrideScore');
AdminRouter::post('/clientes/{id:\d+}/score/remover-override',     'AdminCreditoScoreController@removerOverride');
AdminRouter::post('/clientes/{id:\d+}/credito/lancar',             'AdminCreditoScoreController@lancarCredito');
AdminRouter::post('/clientes/{id:\d+}/credito/debitar',            'AdminCreditoScoreController@debitarCredito');

// ── Listagem e perfil ─────────────────────────────────────
AdminRouter::get ('/clientes',                               'AdminClienteController@index');
AdminRouter::get ('/clientes/{id:\d+}',                     'AdminClienteController@show');
 
// ── Edição de dados ────────────────────────────────────────
AdminRouter::post('/clientes/{id:\d+}/salvar-perfil',       'AdminClienteController@salvarPerfil');
AdminRouter::post('/clientes/{id:\d+}/toggle-ativo',        'AdminClienteController@toggleAtivo');
 
// ── Tags do cliente ────────────────────────────────────────
AdminRouter::post('/clientes/{id:\d+}/tags',                'AdminClienteController@salvarTags');
 
// ── Notas internas ─────────────────────────────────────────
AdminRouter::post('/clientes/{id:\d+}/nota',                'AdminClienteController@addNota');
AdminRouter::post('/clientes/{id:\d+}/nota/{nid:\d+}/del',  'AdminClienteController@deleteNota');

// ── E-mail personalizado ───────────────────────────────────
AdminRouter::post('/clientes/{id:\d+}/email-personalizado', 'AdminClienteController@enviarEmailPersonalizado');
AdminRouter::get ('/clientes/{id:\d+}/wishlist/{wid:\d+}',         'AdminClienteController@wishlistItens');

// ── Configurações: Tags disponíveis ───────────────────────
AdminRouter::get ('/configuracoes/tags',                    'SettingsAdminController@tags');
AdminRouter::post('/configuracoes/tags/salvar',             'SettingsAdminController@salvarTag');
AdminRouter::post('/configuracoes/tags/{id:\d+}/del',       'SettingsAdminController@deleteTag');


// Admin — devolução
AdminRouter::get ('/devolucoes',                                'AdminDevolucaoController@index');
AdminRouter::post('/devolucoes/receber-manual', 'AdminDevolucaoController@receberManual');
AdminRouter::get ('/devolucoes/motivos',                        'AdminDevolucaoController@motivos');
AdminRouter::post('/devolucoes/motivos/salvar',                 'AdminDevolucaoController@salvarMotivo');
AdminRouter::get ('/devolucoes/buscar-para-recebimento',            'AdminDevolucaoController@buscarParaRecebimento');


AdminRouter::get ('/devolucoes/{id:\d+}',                      'AdminDevolucaoController@show');
AdminRouter::post('/devolucoes/{id:\d+}/aprovar',              'AdminDevolucaoController@aprovar');
AdminRouter::post('/devolucoes/{id:\d+}/negar',                'AdminDevolucaoController@negar');
AdminRouter::post('/devolucoes/{id:\d+}/receber',              'AdminDevolucaoController@confirmarRecebimento');
AdminRouter::post('/devolucoes/{id:\d+}/inspecionar',          'AdminDevolucaoController@inspecionar');
AdminRouter::post('/devolucoes/{id:\d+}/reembolsar',           'AdminDevolucaoController@reembolsar');
AdminRouter::post('/devolucoes/{id:\d+}/gerar-postagem',           'AdminDevolucaoController@gerarPostagem');


// Visualização (todos admins)
AdminRouter::get ('/payment',                          'PaymentController@index');
AdminRouter::get ('/payment/transacoes',               'PaymentController@transacoes');
AdminRouter::get ('/payment/transacoes/{id}',          'PaymentController@detalheTransacao');
AdminRouter::get ('/payment/webhooks',                 'PaymentController@webhooks');
AdminRouter::get ('/payment/webhooks/{id}',            'PaymentController@detalheWebhook');

AdminRouter::post('/payment/transacoes/{id}/consultar', 'PaymentController@consultarTransacao');

// Ações (requer permissão 'financeiro')
AdminRouter::post('/payment/transacoes/{id}/estornar', 'PaymentController@estornar');
AdminRouter::post('/payment/webhooks/{id}/reprocessar','PaymentController@reprocessarWebhook');

AdminRouter::get ('/importar',                    'TrayImportController@index');
AdminRouter::post('/importar/upload',             'TrayImportController@upload');
AdminRouter::get ('/importar/preview',            'TrayImportController@preview');
AdminRouter::post('/importar/chunk',              'TrayImportController@chunk');
AdminRouter::get ('/importar/status',             'TrayImportController@status');
AdminRouter::post('/importar/processar-imagens',  'TrayImportController@processarImagens');

# Motor de Automação v2 (grafo) — Fase 1
AdminRouter::get('/fluxos',                'FluxoAdminController@index');
AdminRouter::get('/fluxos/atividade',   'FluxoAdminController@atividade');
AdminRouter::get('/fluxos/atividade/dados', 'FluxoAdminController@atividadeDados');
AdminRouter::get('/fluxos/{id}',           'FluxoAdminController@editor');
AdminRouter::post('/fluxos/criar',          'FluxoAdminController@criar');
AdminRouter::post('/fluxos/{id}/salvar',    'FluxoAdminController@salvar');
AdminRouter::post('/fluxos/{id}/publicar',  'FluxoAdminController@publicar');
AdminRouter::post('/fluxos/{id}/status',    'FluxoAdminController@status');
AdminRouter::get('/fluxos/{id}/stats',  'FluxoAdminController@stats');

AdminRouter::get('/fluxos/atividade',       'FluxoAdminController@atividade');
AdminRouter::get('/fluxos/atividade/dados', 'FluxoAdminController@atividadeDados');
AdminRouter::get('/fluxos/{id}/stats',      'FluxoAdminController@stats');

AdminRouter::get ('/vida-util',          'VidaUtilAdminController@index');
AdminRouter::get ('/vida-util/listar',   'VidaUtilAdminController@listar');
AdminRouter::post('/vida-util/salvar',   'VidaUtilAdminController@salvar');
AdminRouter::post('/vida-util/pausar',   'VidaUtilAdminController@pausar');
AdminRouter::post('/vida-util/excluir',  'VidaUtilAdminController@excluir');

require __DIR__ . '/routes.email-marketing.php';
require __DIR__ . '/routes.ia.php';
require __DIR__ . '/routes.logistic.php';


require __DIR__ . '/routes.chat.php';
