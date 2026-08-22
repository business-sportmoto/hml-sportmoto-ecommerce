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

// ── Auth ────────────────────────────────────────────────────────────────────
// Fase 0 traz só o ciclo de vida do token; login, 2FA, Google e Apple na Fase 2.
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

// ── Clips ───────────────────────────────────────────────────────────────────
AppRouter::get ($v . '/clips/feed',            'AppClipsController@feed');       // antes de {id}
AppRouter::get ($v . '/clips/{id:\d+}',        'AppClipsController@detalhe');

// Diagnóstico da ponte de sessão. Só responde com APP_DEBUG ligado — é o teste
// de aceitação da Fase 0 (o carrinho anônimo precisa persistir sem cookie).
AppRouter::get ($v . '/_diagnostico',          'AppSistemaController@diagnostico');
