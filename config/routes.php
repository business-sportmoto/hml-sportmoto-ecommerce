<?php
// config/routes.php
// Todas as rotas públicas da aplicação.
// As rotas do admin são definidas em admin/config/routes.php

// ── Loja ─────────────────────────────────────────────────────
Router::get('/',                          'HomeController@index');

Router::get('/manifest.json',               'AdminPwaController@manifest');

Router::post('/webhooks/malga',          'WebhookController@malga');
Router::get( '/webhooks/malga/diagnose', 'WebhookController@diagnose');
// Webhook público (sem auth) — ANTES das rotas admin
Router::post('/webhook/bling', 'BlingWebhookController@receive');

Router::post('/webhooks/ia/replicate', 'IAWebhookController@replicate');

Router::get('/dica/{id}', 'DicaCuidadoController@abrir');

// Catálogo
Router::get('/categoria/{slug}',          'CategoryController@show');
Router::get('/busca',                     'SearchController@results');

Router::get('/ajuda',                     'HelpCenterController@index');
Router::get('/ajuda/busca',               'HelpCenterController@busca');
Router::get('/ajuda/categoria/{slug}',    'HelpCenterController@categoria');

// Comuns (cliente e admin — o controller resolve pela sessão)
Router::get ('/notificacoes/contador',     'NotificacaoController@contador');
Router::get ('/notificacoes/listar',       'NotificacaoController@listar');
Router::post('/notificacoes/marcar-lida',  'NotificacaoController@marcarLida');
Router::post('/notificacoes/marcar-todas', 'NotificacaoController@marcarTodas');


// ROTA — config/routes.php (área pública)   
Router::post('/track', 'TrackController@registrar');

// Banner
Router::post('/banner/impressao', 'BannerController@impressao');
Router::get('/banner/click/{id}', 'BannerController@click');

// Página pública da marca
// Router::get('/marca/{slug}',              'CategoryController@brand');

Router::get('/marca/{slug}',              'CategoryController@brand');
Router::get('/marcas',               'BrandController@index');
Router::get('/marcas/produtos',      'BrandController@produtos');
Router::get('/marcas/load-more',     'BrandController@loadMore');

// Garantir que ficam ANTES de /produto/{slug}
Router::get('/produto/card-images',     'ProductController@cardImages');
Router::get('/produto/card-variations', 'ProductController@cardVariations');
Router::get('/produto/variant-data',    'ProductController@variantData');
// Router::get('/produto/{slug}',          'ProductController@detail');

// Router::get('/produto/card-images',       'ProductController@cardImages');
Router::get('/produto/group-colors', 'ProductController@groupColors');
Router::get('/produto/group-data',   'ProductController@groupData');
// Router::get('/produto/variant-data', 'ProductController@variantData');
Router::post('/produto/visualizacao',       'ProductController@registerView');
Router::post('/produto/avisar-estoque',     'ProductController@avisarEstoque');

Router::get('/produto/{slug}',            'ProductController@detail');


// Carrinho
Router::get('/carrinho',                  'CartController@index');

Router::get( '/carrinho/recuperar/{token}',        'CarrinhoRecuperacaoController@recuperar');

Router::post('/carrinho/adicionar',       'CartController@add');
Router::post('/carrinho/remover',         'CartController@remove');
Router::post('/carrinho/atualizar',       'CartController@update');
Router::post('/carrinho/cupom',           'CartController@applyCoupon');
Router::post('/carrinho/cupom/remover',   'CartController@removeCoupon');
Router::post('/carrinho/frete',           'CartController@calcShipping');
Router::get('/carrinho/compartilhar/{token}', 'CartController@share');

// Checkout
// Router::get('/checkout',                  'CheckoutController@index');
// Router::post('/checkout/identificacao',   'CheckoutController@identify');
Router::post('/checkout/selecionar-itens','CheckoutController@selecionarItens');
// Router::post('/checkout/endereco',        'CheckoutController@saveAddress');
// Router::post('/checkout/frete',           'CheckoutController@selectShipping');
// Router::post('/checkout/finalizar',       'CheckoutController@process');
// Router::get('/checkout/sucesso/{codigo}', 'CheckoutController@success');
// Router::post('/checkout/frete/calcular', 'CheckoutController@calcularFrete');
// Router::post('/checkout/frete', 'CheckoutController@selectShipping');

// Router::post('/checkout/identificacao',    'CheckoutController@identify');
// Router::post('/checkout/endereco',         'CheckoutController@saveAddress');
// Router::post('/checkout/frete/selecionar', 'CheckoutController@selectShipping');
// Router::post('/checkout/finalizar',        'CheckoutController@process');
// Router::get('/checkout/cep',               'CheckoutController@fetchCep');
// Router::get('/checkout/sucesso/{codigo}',  'CheckoutController@success');

// ════════════════════════════════════════════════════════
// ADICIONAR em config/routes.php
// Substitui qualquer rota /checkout existente.
// ════════════════════════════════════════════════════════

// Entrada — redireciona para etapa pendente
Router::get('/checkout',               'CheckoutController@index');
// ── ETAPA 1: Identificação ──────────────────────────────
Router::get ('/checkout/identify',     'CheckoutController@identify');
Router::post('/checkout/identify',     'CheckoutController@identifyPost');
// ── ETAPA 2: Endereço ───────────────────────────────────
Router::get ('/checkout/address',                 'CheckoutController@address');
Router::post('/checkout/address/select',          'CheckoutController@addressSelect');
Router::get ('/checkout/address/add',             'CheckoutController@addressAdd');
Router::post('/checkout/address/add',             'CheckoutController@addressAddPost');
Router::get ('/checkout/address/update',          'CheckoutController@addressList');
Router::get ('/checkout/address/update/{hash}',   'CheckoutController@addressEdit');
Router::post('/checkout/address/update/{hash}',   'CheckoutController@addressEditPost');
Router::post('/checkout/address/set-principal',   'CheckoutController@addressSetPrincipal');
Router::post('/checkout/address/select-by-hash', 'CheckoutController@addressSelectByHash');


// ── ETAPA 3: Pagamento ──────────────────────────────────
Router::get ('/checkout/payment',                 'CheckoutController@payment');
Router::post('/checkout/payment/shipping',        'CheckoutController@calculateShipping');
Router::post('/checkout/payment/shipping/save',   'CheckoutController@saveShipping');

Router::get('/checkout/status/{codigo}',          'CheckoutController@pixStatus');

Router::post('/checkout/payment/coupon',          'CheckoutController@applyCoupon'); #CUPOM
// ── ETAPA 4: Resumo ─────────────────────────────────────
Router::get ('/checkout/summary',                 'CheckoutController@summary');
// ── Finalizar + Sucesso ─────────────────────────────────
Router::post('/checkout/finalize',                'CheckoutController@finalize');
Router::get ('/checkout/success/{codigo}',        'CheckoutController@success');
// ── Utilitários AJAX ────────────────────────────────────
Router::get ('/checkout/cep',                     'CheckoutController@fetchCep');

// 1. Calcular fretes disponíveis (chamado após salvar endereço)
Router::post('/checkout/frete/calcular', 'CheckoutController@calcularFrete');

// 2. Confirmar escolha de frete (já existe como selectShipping)
Router::post('/checkout/frete', 'CheckoutController@selectShipping');

Router::get('/checkout/debug-state', 'CheckoutController@debugState');
Router::get('/checkout/success/{codigo}', 'CheckoutController@success');

// Cartão de crédito
Router::get ('/checkout/payment/card/add',    'CheckoutController@paymentCardAdd');
Router::post('/checkout/payment/card/add',    'CheckoutController@paymentCardAddPost');
 
// Salvar método de pagamento e observação
Router::post('/checkout/payment/save-method',      'CheckoutController@savePaymentMethod');
Router::post('/checkout/payment/save-observation', 'CheckoutController@saveObservation');
 
// ── Crédito no checkout ───────────────────────────────────
Router::post('/checkout/aplicar-credito',  'CheckoutController@aplicarCredito');
Router::post('/checkout/remover-credito',  'CheckoutController@removerCredito');

Router::get('/promocoes/preview', 'PromocaoController@preview');

// Para que o JS funcione, o calcularFrete() precisa retornar:
// json{
//   "ok": true,
//   "opcoes": [
//     {"id": "pac",     "nome": "PAC",                "prazo": 7, "valor": 25.90},
//     {"id": "sedex",   "nome": "SEDEX",              "prazo": 3, "valor": 38.50},
//     {"id": "expresso","nome": "Entrega expressa",   "prazo": 1, "valor": 0},
//     {"id": "loja",    "nome": "Retirada na loja",   "prazo": 0, "valor": 0, "tipo": "retirada"}
//   ]
// }

// Autenticação
Router::get('/login',                     'AuthController@loginForm');
Router::post('/login',                    'AuthController@login');
Router::get('/cadastro',                  'AuthController@registerForm');
Router::post('/cadastro',                 'AuthController@register');
Router::get('/verificar-email/{token}',   'AuthController@verifyEmail');
Router::get('/sair',                      'AuthController@logout');
Router::get('/recuperar-senha',           'AuthController@forgotForm');
Router::post('/recuperar-senha',          'AuthController@forgot');
Router::get('/redefinir-senha/{token}',   'AuthController@resetForm');
Router::post('/redefinir-senha',          'AuthController@reset');
Router::get('/autenticacao-2fa',          'AuthController@twoFactorForm');
Router::post('/autenticacao-2fa',         'AuthController@twoFactor');
Router::post('/autenticacao-2fa/enviar',   'AuthController@send2FAChannel');

// Login em etapas
Router::post('/login/verificar-identidade', 'AuthController@checkIdentity');
Router::post('/login/com-codigo',           'AuthController@sendLoginCode');
Router::post('/login/validar-codigo',       'AuthController@validateLoginCode'); //

// Cadastro
Router::get('/cadastro',                    'AuthController@registerForm');
Router::post('/cadastro',                   'AuthController@register');
Router::get('/verificar-email/{token}',     'AuthController@verifyEmail');
Router::post('/cadastro/reenviar-codigo',   'AuthController@resendVerification'); //

// Área do cliente (requer login)
Router::get('/minha-conta',                 'CustomerController@dashboard');
Router::get('/minha-conta/conta',           'CustomerController@conta');

Router::get('/minha-conta/pedido/{id:\d+}/rastreio', 'CustomerController@rastreio');

Router::get('/minha-conta/avaliacoes', 'CustomerController@minhasAvaliacoes');

Router::get('/minha-conta/pedidos',       'CustomerController@orders');
Router::get('/minha-conta/pedido/{id:\d+}', 'CustomerController@orderDetail');
Router::get('/minha-conta/enderecos',     'CustomerController@addresses');
Router::post('/minha-conta/endereco/salvar', 'CustomerController@saveAddress');
Router::post('/minha-conta/endereco/excluir', 'CustomerController@deleteAddress');

// Router::get('/minha-conta/favoritos',     'CustomerController@wishlist');
Router::post('/minha-conta/favorito/adicionar', 'CustomerController@addWishlist');
Router::post('/minha-conta/favorito/remover',   'CustomerController@removeWishlist');
Router::post('/minha-conta/favoritos/verificar', 'CustomerController@checkWishlist');

Router::post('/cupom/aplicar',   'CouponController@aplicar');
Router::post('/cupom/remover',   'CouponController@remover');
Router::post('/cupom/revalidar', 'CouponController@revalidar');

// Substitui as rotas antigas de wishlist
Router::post('/minha-conta/favorito/toggle',     'CustomerController@toggleWishlist');

// Rotas TOTP — adicionar em config/routes.php

// Tela de gerenciamento (painel do cliente)
Router::get ('/minha-conta/seguranca',                     'CustomerSecurityController@index');

// Setup
Router::post('/minha-conta/seguranca/totp/iniciar',         'CustomerSecurityController@totpIniciar');
Router::post('/minha-conta/seguranca/totp/confirmar',       'CustomerSecurityController@totpConfirmar');
Router::post('/minha-conta/seguranca/totp/desativar',       'CustomerSecurityController@totpDesativar');
Router::post('/minha-conta/seguranca/totp/regenerar-backup','CustomerSecurityController@totpRegenerarBackup');

Router::post('/minha-conta/seguranca/totp/desativar-solicitar',       'CustomerSecurityController@totpDesativarSolicitar');
Router::post('/minha-conta/seguranca/totp/desativar-confirmar',       'CustomerSecurityController@totpDesativarConfirmar');

// A rota de validação no login (POST /autenticacao-2fa/enviar e
// POST /autenticacao-2fa) já existem — TOTP é só mais um valor possível
// de "canal", não precisa de rota nova ali.

// Wishlist
Router::get( '/minha-conta/favoritos',              'WishlistController@index');
Router::get( '/favoritos/minhas-listas',            'WishlistController@minhasListas');
Router::get( '/favoritos/itens',                    'WishlistController@itens');
Router::get( '/favoritos/verificar',                'WishlistController@verificarProduto');
Router::post('/favoritos/criar',                    'WishlistController@criar');
Router::post('/favoritos/editar',                   'WishlistController@editar');
Router::post('/favoritos/excluir',                  'WishlistController@excluir');
Router::post('/favoritos/item/adicionar',           'WishlistController@adicionarItem');
Router::post('/favoritos/item/remover',             'WishlistController@removerItem');
Router::post('/favoritos/favoritar',                'WishlistController@favoritar');
Router::post('/favoritos/desfavoritar',             'WishlistController@desfavoritar');

Router::get('/minha-conta/cartoes',       'CustomerController@cards');
Router::post('/minha-conta/cartao/excluir', 'CustomerController@deleteCard');
Router::get('/minha-conta/perfil',        'CustomerController@profile');
Router::post('/minha-conta/perfil/salvar', 'CustomerController@saveProfile');
Router::post('/minha-conta/senha/alterar', 'CustomerController@changePassword');

// Wishlist pública compartilhada
Router::get('/lista/{token}',             'WishlistController@shared');

// Cliente — devolução
Router::get('/minha-conta/devolucoes',                          'CustomerDevolucaoController@index');
Router::get('/minha-conta/devolucao/nova/{id:\d+}',             'CustomerDevolucaoController@novaForm');
Router::post('/minha-conta/devolucao/nova',                     'CustomerDevolucaoController@criar');
Router::get('/minha-conta/devolucao/{id:\d+}',                  'CustomerDevolucaoController@show');
Router::post('/minha-conta/devolucao/{id:\d+}/cancelar',        'CustomerDevolucaoController@cancelar');
Router::post('/minha-conta/devolucao/{id:\d+}/rastreio',        'CustomerDevolucaoController@informarRastreio');

// Newsletter
Router::post('/newsletter',               'HomeController@newsletter');
Router::get('/newsletter/cancelar/{token}', 'HomeController@unsubscribe');

// Páginas institucionais (deve ficar por último — catch-all de slug)
// Router::get('/{slug}',                    'PageController@show');


// Adicionar em config/routes.php
Router::post('/carrinho/frete/selecionar', 'CartController@selectShipping');
// Router::post('/carrinho/vendedor',         'CartController@applyVendor');
Router::get('/carrinho/mini',              'CartController@mini');
Router::post('/carrinho/compartilhar',     'CartController@share');

Router::post('/carrinho/copiar-compartilhado', 'CartController@copyShared');

// Atualizar as rotas do vendedor
Router::post('/carrinho/vendedor',        'CartController@applyVendedor');
Router::post('/carrinho/vendedor/remover','CartController@removeVendedor');


// Router::get('/carrinho/compartilhar/{token}', 'CartController@restoreShared');
// Visualização do carrinho compartilhado (GET — acessado pelo link)
// Router::get('/carrinho/compartilhado/{token}',  'CartController@viewShared');

// ── Carrinho público (CartController — já existe) ─────────
Router::get ('/carrinho/compartilhado/{token}',                 'CartController@viewShared');   //← MANTER como está
Router::post('/carrinho/copiar-compartilhado',                  'CartController@copyShared');   //← MANTER como está
Router::post('/carrinho/compartilhar',                          'CartController@share');       // ← MANTER como está
 
// ── Área do cliente — analytics ───────────────────────────
Router::get ('/minha-conta/carrinhos-compartilhados',           'CustomerCarrinhoController@index');
Router::get ('/minha-conta/carrinhos-compartilhados/{token:\w+}','CustomerCarrinhoController@show');
 
// ── REMOVER esta rota (conflitava com CartController@viewShared)
Router::get('/carrinho/compartilhado/{token:\w+}', 'CustomerCarrinhoController@abrirCompartilhado'); //← DELETAR

Router::get('/frete/calcular',             'CartController@calcShipping');
Router::get('/home/por-categoria',     'ProductController@byCategory');
Router::get('/busca/autocomplete',         'SearchController@autocomplete');

// Adicionar em config/routes.php
Router::post('/minha-conta/endereco/principal', 'CustomerController@setPrincipalAddress');
Router::post('/minha-conta/cartao/principal',   'CustomerController@setPrincipalCard');


// Adicionar em config/routes.php
Router::get('/minha-conta/sessoes',              'CustomerController@sessions');
Router::post('/minha-conta/sessao/revogar',      'CustomerController@revokeSession');
Router::post('/minha-conta/sessoes/revogar-todas','CustomerController@revokeAllSessions');

Router::get ('/sessao/verificar-modal-lembrar', 'AuthController@verificarModalLembrar');
Router::post('/sessao/confirmar-lembrar',        'AuthController@confirmarLembrar');


Router::post('/minha-conta/2fa/verificar',       'CustomerController@verify2fa');
Router::post('/minha-conta/2fa/toggle',          'CustomerController@toggle2fa');

Router::get( '/minha-conta/garagem',           'CustomerController@garagem');
Router::post('/minha-conta/garagem/adicionar', 'CustomerController@garagemAdicionar');
Router::post('/minha-conta/garagem/atualizar', 'CustomerController@garagemAtualizar');
Router::post('/minha-conta/garagem/ativar',    'CustomerController@garagemAtivar');
Router::post('/minha-conta/garagem/remover',   'CustomerController@garagemRemover');

// Ajax (já existem do MotoController, mas se quiser separar):
Router::get('/minha-conta/garagem/modelos', 'CustomerController@garagemModelos');
Router::get('/minha-conta/garagem/anos',    'CustomerController@garagemAnos');

// config/routes.php
Router::get( '/minha-conta/garagem/{id}/fotos',         'CustomerController@garagemFotos');
Router::post('/minha-conta/garagem/{id}/fotos/upload',  'CustomerController@garagemFotoUpload');
Router::post('/minha-conta/garagem/foto/atualizar',     'CustomerController@garagemFotoAtualizar');
Router::post('/minha-conta/garagem/foto/capa',          'CustomerController@garagemFotoCapa');
Router::post('/minha-conta/garagem/foto/remover',       'CustomerController@garagemFotoRemover');

// config/routes.php
Router::get( '/minha-conta/garagem/moto/{id}', 'CustomerController@garagemMoto');
Router::post('/minha-conta/perfil/insta',      'CustomerController@atualizarInsta');



// Listagem de montadoras
// ── Busca por moto ────────────────────────────────────────
Router::get('/motos',                            'MotoController@montadoras');
Router::get('/motos/buscar',                     'MotoController@buscarRedirect');
Router::get('/montadora/{montadora}',            'MotoController@catalogo');
Router::get('/montadora/{montadora}/{moto}',     'MotoController@catalogo');
 
// Ajax em cascata
Router::get('/ajax/moto/modelos',               'MotoController@ajaxModelos');
Router::get('/ajax/moto/anos',                  'MotoController@ajaxAnos');
// config/routes.php
Router::get('/ajax/moto/slug-modelo', 'MotoController@ajaxSlugModelo');

// Adicionar em config/routes.php
Router::get('/cep/info',      'CepController@info');
Router::post('/cep/salvar',   'CepController@save');
Router::post('/cep/remover',  'CepController@remove');

// Adicionar em config/routes.php

// config/routes.php
Router::post('/auth/google',                  'GoogleAuthController@callback');
Router::post('/auth/google/vincular',         'GoogleAuthController@vincular');
Router::post('/auth/google/cancelar-vinculo', 'GoogleAuthController@cancelarVinculo');

// Router::post('/auth/google',                   'GoogleAuthController@callback');
Router::post('/auth/google/completar-perfil',  'GoogleAuthController@completarPerfil');
Router::post('/auth/google/cancelar',          'GoogleAuthController@cancelar');
Router::post('/auth/google/vincular-conta',    'GoogleAuthController@vincularConta');
Router::post('/auth/google/desvincular-conta', 'GoogleAuthController@desvincularConta');

// No painel do cliente
Router::post('/minha-conta/desvincular-social', 'GoogleAuthController@desvincular');

Router::post('/minha-conta/documento/upload',    'DocumentController@upload');
Router::post('/minha-conta/documento/qr',        'DocumentController@generateQr');
Router::get('/minha-conta/documento/status',     'DocumentController@checkStatus');
Router::get('/verificar-documento/{token}',      'DocumentController@mobileForm');
Router::post('/verificar-documento/upload',      'DocumentController@mobileUpload');

Router::get('/minha-conta/historico',             'CustomerController@history');
Router::post('/minha-conta/historico/limpar',     'CustomerController@clearHistory');
Router::post('/historico/tempo',                  'CustomerController@updateHistoryTime');

// config/routes.php
Router::post('/meu-veiculo/salvar',  'VeiculoController@salvar');
Router::post('/meu-veiculo/remover', 'VeiculoController@remover');
Router::get( '/meu-veiculo/status',  'VeiculoController@status');
Router::get( '/meu-veiculo/modelos', 'VeiculoController@ajaxModelos');
Router::get( '/meu-veiculo/anos',    'VeiculoController@ajaxAnos');

// config/routes.php
Router::get('/feed/google-merchant', 'FeedController@googleMerchant');

//Clips
Router::get( '/clips/feed',        'ClipController@feed');
Router::post('/clips/view',        'ClipController@view');
Router::post('/clips/like',        'ClipController@like');
Router::post('/clips/share',       'ClipController@share');
Router::get( '/clips/comentarios', 'ClipController@comentarios');
Router::post('/clips/comentar',    'ClipController@comentar');
Router::get('/clip/{id}',          'ClipController@publicPage');

// Avaliações
Router::post('/avaliacao/salvar',         'ProductController@saveReview');

//Avaliações
Router::get( '/avaliacoes',              'ReviewController@listar');
Router::post('/avaliacoes/upload-midia', 'ReviewController@uploadMidia');
Router::post('/avaliacoes/enviar',       'ReviewController@enviar');
Router::post('/avaliacoes/util',         'ReviewController@util');
Router::get('/avaliacoes/resumo-ia', 'ReviewController@resumoIA');

Router::post('/avaliacoes/remover-midia', 'ReviewController@removerMidia');

//Perguntas
Router::get( '/perguntas',        'PerguntaController@listar');
Router::post('/perguntas/enviar', 'PerguntaController@enviar');
Router::post('/perguntas/util',   'PerguntaController@util');

// Router::post('/webhook/email/mailgun',                       'HomeController@index');
require __DIR__ . '/routes.email-marketing.php';



// Deve ser a ÚLTIMA rota do arquivo (curinga)
Router::get('/{slug}', 'PageController@show');

