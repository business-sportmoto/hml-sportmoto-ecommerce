<?php
// config/routes.app.php
// Rotas da API do app mobile. Carregado só por AppRouter::dispatch().
//
// ┌──────────────────────────────────────────────────────────────────────────┐
// │ ATENÇÃO: A ORDEM IMPORTA. O router casa a PRIMEIRA rota que bater.       │
// │ Rotas literais SEMPRE antes das que têm {param} no mesmo nível.          │
// │ Ex.: /produtos/destaques precisa vir antes de /produtos/{slug}, senão    │
// │ "destaques" é capturado como slug e a rota literal nunca é alcançada.    │
// │ É o mesmo cuidado já tomado em config/routes.php (/produto/card-images   │
// │ declarado antes de /produto/{slug}).                                     │
// └──────────────────────────────────────────────────────────────────────────┘

$v = '/api/app/v1';

// ── Sistema ─────────────────────────────────────────────────────────────────
// Únicos endpoints que existem antes de haver token.
AppRouter::get ($v . '/config',                'AppSistemaController@config');
AppRouter::post($v . '/dispositivos/registrar','AppSistemaController@registrarDispositivo');
AppRouter::patch($v . '/dispositivos',         'AppSistemaController@atualizarDispositivo');

// Saúde do aplicativo: o app reporta o que quebrou nele. Sem isto, tela branca
// no aparelho é invisível para quem opera a loja.
AppRouter::post($v . '/telemetria',            'AppSistemaController@telemetria');
AppRouter::get ($v . '/_saude',                'AppSistemaController@saude');

// ── Auth ────────────────────────────────────────────────────────────────────
// Fase 0 traz só o ciclo de vida do token; login, 2FA, Google e Apple na Fase 2.
AppRouter::post($v . '/auth/login',            'AppAuthController@login');
AppRouter::post($v . '/auth/2fa/enviar',       'AppAuthController@enviarCodigo2fa');
AppRouter::post($v . '/auth/2fa/verificar',    'AppAuthController@verificar2fa');
AppRouter::post($v . '/auth/google/cadastro',  'AppAuthController@googleCadastro'); // antes de /auth/google
AppRouter::post($v . '/auth/google',           'AppAuthController@google');
AppRouter::post($v . '/auth/refresh',          'AppAuthController@refresh');
AppRouter::post($v . '/auth/logout',           'AppAuthController@logout');

// ── Home e banners ──────────────────────────────────────────────────────────
AppRouter::get ($v . '/home',                  'AppHomeController@index');
AppRouter::post($v . '/banners/impressao',     'AppHomeController@impressao');   // literal antes de {id}
AppRouter::post($v . '/banners/{id:\d+}/clique','AppHomeController@clique');
AppRouter::get ($v . '/banners/{zona}',        'AppHomeController@banner');

// ── Catálogo, busca e navegação ─────────────────────────────────────────────
AppRouter::get ($v . '/catalogo/filtros',      'AppCatalogoController@filtrosDisponiveis'); // antes de /catalogo
AppRouter::get ($v . '/catalogo',              'AppCatalogoController@index');
AppRouter::get ($v . '/busca/autocomplete',    'AppCatalogoController@autocomplete');       // antes de /busca
AppRouter::get ($v . '/busca',                 'AppCatalogoController@busca');
AppRouter::get ($v . '/categorias',            'AppCatalogoController@categorias');
AppRouter::get ($v . '/categorias/{slug}',     'AppCatalogoController@categoria');
AppRouter::get ($v . '/marcas',                'AppCatalogoController@marcas');
AppRouter::get ($v . '/marcas/{slug}',         'AppCatalogoController@marca');

// ── Produto ─────────────────────────────────────────────────────────────────
// As rotas com sufixo usam {id:\d+}, e a de detalhe usa {slug}: como \d+ não
// casa com um slug textual, a ordem entre elas é segura em qualquer sentido.
AppRouter::get ($v . '/produtos/{id:\d+}/avaliacoes',     'AppProdutoController@avaliacoes');
AppRouter::get ($v . '/produtos/{id:\d+}/clips',          'AppProdutoController@clips');
AppRouter::post($v . '/produtos/{id:\d+}/avisar-estoque', 'AppProdutoController@avisarEstoque');
AppRouter::get ($v . '/produtos/{slug}',                  'AppProdutoController@detalhe');

// ── Carrinho ────────────────────────────────────────────────────────────────
// Funciona anônimo: o carrinho de visitante vive no banco, chaveado pela ponte
// de sessão do dispositivo. Ao logar, o CartMergeService junta os dois.
AppRouter::get   ($v . '/carrinho/contador',        'AppCarrinhoController@contador'); // antes dos demais
AppRouter::get   ($v . '/carrinho',                 'AppCarrinhoController@index');
AppRouter::post  ($v . '/carrinho/itens',           'AppCarrinhoController@adicionar');
AppRouter::patch ($v . '/carrinho/itens/{id:\d+}',  'AppCarrinhoController@atualizar');
AppRouter::delete($v . '/carrinho/itens/{id:\d+}',  'AppCarrinhoController@remover');
AppRouter::post  ($v . '/carrinho/cupom',           'AppCarrinhoController@aplicarCupom');
AppRouter::delete($v . '/carrinho/cupom',           'AppCarrinhoController@removerCupom');

// ── Conta ───────────────────────────────────────────────────────────────────
AppRouter::get   ($v . '/conta/perfil',                'AppContaController@perfil');
AppRouter::get   ($v . '/conta/favoritos/ids',         'AppContaController@favoritosIds'); // antes de /favoritos
AppRouter::get   ($v . '/conta/favoritos',             'AppContaController@favoritos');
AppRouter::post  ($v . '/conta/favoritos',             'AppContaController@favoritar');
AppRouter::delete($v . '/conta/favoritos/{id:\d+}',    'AppContaController@desfavoritar');
AppRouter::get   ($v . '/conta/pedidos',               'AppContaController@pedidos');

// ── Listas de desejos ───────────────────────────────────────────────────────
// A loja tem N listas nomeadas por cliente, uma delas `padrao` (os favoritos).
// Literais antes de {id}, e /produto/{id} antes de /{id} pelo mesmo motivo.
AppRouter::get   ($v . '/conta/listas/produto/{id:\d+}',        'AppListasController@doProduto');
AppRouter::get   ($v . '/conta/listas',                         'AppListasController@index');
AppRouter::post  ($v . '/conta/listas',                         'AppListasController@criar');
AppRouter::post  ($v . '/conta/listas/{id:\d+}/itens',          'AppListasController@adicionarItem');
AppRouter::delete($v . '/conta/listas/{id:\d+}/itens/{produto:\d+}', 'AppListasController@removerItem');
AppRouter::get   ($v . '/conta/listas/{id:\d+}',                'AppListasController@itens');
AppRouter::patch ($v . '/conta/listas/{id:\d+}',                'AppListasController@editar');
AppRouter::delete($v . '/conta/listas/{id:\d+}',                'AppListasController@excluir');

// ── Devoluções e trocas ─────────────────────────────────────────────────────
AppRouter::get   ($v . '/conta/devolucoes/motivos',             'AppDevolucoesController@motivos'); // antes de {id}
AppRouter::post  ($v . '/conta/devolucoes/midias',              'AppDevolucoesController@enviarMidia');
AppRouter::get   ($v . '/conta/devolucoes',                     'AppDevolucoesController@index');
AppRouter::post  ($v . '/conta/devolucoes',                     'AppDevolucoesController@criar');
AppRouter::get   ($v . '/conta/devolucoes/{id:\d+}',            'AppDevolucoesController@show');
AppRouter::post  ($v . '/conta/devolucoes/{id:\d+}/cancelar',   'AppDevolucoesController@cancelar');
AppRouter::post  ($v . '/conta/devolucoes/{id:\d+}/rastreio',   'AppDevolucoesController@rastreio');

// ── Histórico de navegação ──────────────────────────────────────────────────
AppRouter::get   ($v . '/conta/historico/resumo',               'AppHistoricoController@resumo'); // antes de /historico
AppRouter::get   ($v . '/conta/historico',                      'AppHistoricoController@index');
AppRouter::delete($v . '/conta/historico',                      'AppHistoricoController@limpar');
AppRouter::post  ($v . '/conta/historico/tempo',                'AppHistoricoController@tempo');

// ── Checkout ────────────────────────────────────────────────────────────────
// O estado vive no MESMO CheckoutState da web (ponte de sessão) e os totais
// saem de CheckoutTotais, compartilhado com CheckoutController::process().
// Literais antes de {codigo}, como sempre.
AppRouter::get   ($v . '/checkout/resumo',    'AppCheckoutController@resumo');
AppRouter::get   ($v . '/checkout/frete',     'AppCheckoutController@opcoesFrete');
AppRouter::post  ($v . '/checkout/frete',     'AppCheckoutController@definirFrete');
AppRouter::post  ($v . '/checkout/endereco',  'AppCheckoutController@definirEndereco');
AppRouter::post  ($v . '/checkout/cupom',     'AppCheckoutController@aplicarCupom');
AppRouter::delete($v . '/checkout/cupom',     'AppCheckoutController@removerCupom');
AppRouter::post  ($v . '/checkout/credito',   'AppCheckoutController@aplicarCredito');
AppRouter::delete($v . '/checkout/credito',   'AppCheckoutController@removerCredito');
AppRouter::post  ($v . '/checkout/pagamento', 'AppCheckoutController@definirPagamento');
AppRouter::post  ($v . '/checkout/finalizar', 'AppCheckoutController@finalizar');
AppRouter::get   ($v . '/checkout',           'AppCheckoutController@estado');

AppRouter::get   ($v . '/conta/cartoes',            'AppCheckoutController@cartoes');
AppRouter::delete($v . '/conta/cartoes/{id:\d+}',   'AppCheckoutController@removerCartao');

// ── CEP e frete na vitrine ──────────────────────────────────────────────────
// Públicas de propósito: é o visitante anônimo que mais precisa saber o frete
// antes de decidir criar conta. O CEP fica no dispositivo, que é o que o
// cookie `ec_cep` representa na web.
AppRouter::get   ($v . '/cep',            'AppCepController@ativo');
AppRouter::post  ($v . '/cep',            'AppCepController@salvar');
AppRouter::delete($v . '/cep',            'AppCepController@remover');
AppRouter::get   ($v . '/frete/produto',  'AppCepController@produto');

// ── Cabeçalho, endereços e notificações ─────────────────────────────────────
// /conta/cabecalho junta "para onde entregamos" e "quantos avisos não lidos"
// numa requisição: a barra superior aparece em toda tela, e dois endpoints
// dobrariam o custo de cada abertura.
AppRouter::get   ($v . '/conta/cabecalho',                      'AppEnderecosController@cabecalho');

AppRouter::get   ($v . '/conta/enderecos',                      'AppEnderecosController@index');
AppRouter::post  ($v . '/conta/enderecos/{id:\d+}/principal',   'AppEnderecosController@tornarPrincipal');

// Literais antes de {id}: "contador" e "lidas" seriam capturados como id.
AppRouter::get   ($v . '/conta/notificacoes/contador',          'AppNotificacoesController@contador');
AppRouter::post  ($v . '/conta/notificacoes/lidas',             'AppNotificacoesController@marcarTodasLidas');
AppRouter::get   ($v . '/conta/notificacoes',                   'AppNotificacoesController@index');
AppRouter::post  ($v . '/conta/notificacoes/{id:\d+}/lida',     'AppNotificacoesController@marcarLida');

// Rota de pedido com {codigo} textual fica por ÚLTIMO entre as de /conta/...:
// como {codigo} casa qualquer coisa, ela engoliria /conta/pedidos/... acima.
AppRouter::get   ($v . '/conta/pedidos/{codigo}/devolucao', 'AppDevolucoesController@elegibilidade');
AppRouter::get   ($v . '/conta/pedidos/{codigo}/pagamento', 'AppCheckoutController@statusPagamento');
AppRouter::get   ($v . '/conta/pedidos/{codigo}',           'AppContaController@pedido');

// ── Motos e garagem ─────────────────────────────────────────────────────────
// A cascata é pública (dá para escolher a moto antes de criar conta);
// a garagem exige login.
AppRouter::get   ($v . '/motos/montadoras',            'AppGaragemController@montadoras');
AppRouter::get   ($v . '/motos/modelos',               'AppGaragemController@modelos');
AppRouter::get   ($v . '/motos/anos',                  'AppGaragemController@anos');
AppRouter::get   ($v . '/garagem',                     'AppGaragemController@index');
AppRouter::post  ($v . '/garagem',                     'AppGaragemController@adicionar');
// Rotas de foto usam o prefixo literal /garagem/fotos e vêm ANTES de
// /garagem/{id}, senão "fotos" seria capturado como id.
AppRouter::post  ($v . '/garagem/fotos/{id:\d+}/capa', 'AppGaragemController@definirCapa');
AppRouter::delete($v . '/garagem/fotos/{id:\d+}',      'AppGaragemController@removerFoto');
AppRouter::get   ($v . '/garagem/{id:\d+}/fotos',      'AppGaragemController@fotos');
AppRouter::post  ($v . '/garagem/{id:\d+}/fotos',      'AppGaragemController@enviarFoto');
AppRouter::post  ($v . '/garagem/{id:\d+}/ativar',     'AppGaragemController@ativar');
AppRouter::patch ($v . '/garagem/{id:\d+}',            'AppGaragemController@atualizar');
AppRouter::delete($v . '/garagem/{id:\d+}',            'AppGaragemController@remover');

// ── Clips ───────────────────────────────────────────────────────────────────
AppRouter::get ($v . '/clips/feed',            'AppClipsController@feed');       // antes de {id}
AppRouter::get ($v . '/clips/{id:\d+}',        'AppClipsController@detalhe');

// Diagnóstico da ponte de sessão. Só responde com APP_DEBUG ligado — é o teste
// de aceitação da Fase 0 (o carrinho anônimo precisa persistir sem cookie).
AppRouter::get ($v . '/_diagnostico',          'AppSistemaController@diagnostico');
