<?php 

// Registre a rota LITERAL antes de qualquer rota paramétrica do módulo
// (/admin/logistica/{id}) — risco recorrente já documentado no projeto.
AdminRouter::get('/logistica',              'LogisticaController@torre');
// AdminRouter::get('/admin/logistica/torre',        'LogisticaController@torre');
AdminRouter::get('/logistica/torre/dados',  'LogisticaController@torreDados');

AdminRouter::get('/logistica/etiquetas/rotulo', 'EtiquetaController@rotulo');

AdminRouter::get ('/logistica/transportadoras',          'TransportadoraController@index');
AdminRouter::get ('/logistica/transportadoras/dados',    'TransportadoraController@dados');
AdminRouter::get ('/logistica/transportadoras/obter',    'TransportadoraController@obter');
AdminRouter::get ('/logistica/transportadoras/logs',     'TransportadoraController@logs');
AdminRouter::post('/logistica/transportadoras/salvar',   'TransportadoraController@salvar');
AdminRouter::post('/logistica/transportadoras/status',   'TransportadoraController@status');
AdminRouter::post('/logistica/transportadoras/reordenar','TransportadoraController@reordenar');
AdminRouter::post('/logistica/transportadoras/testar',   'TransportadoraController@testar');

// Regras
AdminRouter::get ('/logistica/regras',            'FreteController@regras');
AdminRouter::get ('/logistica/regras/dados',      'FreteController@regrasDados');
AdminRouter::get ('/logistica/regras/obter',      'FreteController@regraObter');
AdminRouter::post('/logistica/regras/salvar',     'FreteController@regraSalvar');
AdminRouter::post('/logistica/regras/status',     'FreteController@regraStatus');
AdminRouter::post('/logistica/regras/reordenar',  'FreteController@regraReordenar');
AdminRouter::post('/logistica/regras/remover',    'FreteController@regraRemover');

// Simulador
AdminRouter::get ('/logistica/simulador',         'FreteController@simulador');
AdminRouter::post('/logistica/simulador/cotar',   'FreteController@simular');

// Embalagens (gestão opcional)
AdminRouter::get ('/logistica/embalagens/dados',   'FreteController@embalagensDados');
AdminRouter::post('/logistica/embalagens/salvar',  'FreteController@embalagemSalvar');
AdminRouter::post('/logistica/embalagens/status',  'FreteController@embalagemStatus');
AdminRouter::post('/logistica/embalagens/remover', 'FreteController@embalagemRemover');

AdminRouter::get ('/logistica/etiquetas',              'EtiquetaController@index');
AdminRouter::get ('/logistica/etiquetas/dados',        'EtiquetaController@dados');
AdminRouter::get ('/logistica/etiquetas/obter',        'EtiquetaController@obter');
AdminRouter::post('/logistica/etiquetas/criar',        'EtiquetaController@criar');
AdminRouter::post('/logistica/etiquetas/comprar',      'EtiquetaController@comprar');
AdminRouter::post('/logistica/etiquetas/comprar-lote', 'EtiquetaController@comprarLote');
AdminRouter::post('/logistica/etiquetas/imprimir',     'EtiquetaController@imprimir');
AdminRouter::post('/logistica/etiquetas/cancelar',     'EtiquetaController@cancelar');
AdminRouter::post('/logistica/etiquetas/manifesto',    'EtiquetaController@manifesto');
AdminRouter::post('/logistica/etiquetas/remover',      'EtiquetaController@remover');

// Admin (literais antes de qualquer {id})
AdminRouter::get ('/logistica/rastreios',           'RastreioController@index');
AdminRouter::get ('/logistica/rastreios/dados',     'RastreioController@dados');
AdminRouter::get ('/logistica/rastreios/obter',     'RastreioController@obter');
AdminRouter::post('/logistica/rastreios/atualizar', 'RastreioController@atualizar');


AdminRouter::get ('/logistica/reversas',            'ReversaController@index');
AdminRouter::get ('/logistica/reversas/dados',      'ReversaController@dados');
AdminRouter::get ('/logistica/reversas/obter',      'ReversaController@obter');
AdminRouter::get ('/logistica/reversas/buscar-cliente','ReversaController@buscarCliente');
AdminRouter::post('/logistica/reversas/solicitar',  'ReversaController@solicitar');
AdminRouter::post('/logistica/reversas/autorizar',  'ReversaController@autorizar');
AdminRouter::post('/logistica/reversas/gerar',      'ReversaController@gerar');
AdminRouter::post('/logistica/reversas/cancelar',   'ReversaController@cancelar');
AdminRouter::post('/logistica/reversas/receber',    'ReversaController@receber');
AdminRouter::post('/logistica/reversas/processo',   'ReversaController@processo');
AdminRouter::post('/logistica/reversas/sincronizar','ReversaController@sincronizar');
AdminRouter::post('/logistica/reversas/remover',    'ReversaController@remover');


AdminRouter::get ('/logistica/divergencias',                'DivergenciaController@index');
AdminRouter::get ('/logistica/divergencias/dados',          'DivergenciaController@dados');
AdminRouter::get ('/logistica/divergencias/obter',          'DivergenciaController@obter');
AdminRouter::get ('/logistica/divergencias/contexto-etiqueta','DivergenciaController@contextoEtiqueta');
AdminRouter::post('/logistica/divergencias/registrar',      'DivergenciaController@registrar');
AdminRouter::post('/logistica/divergencias/analisar',       'DivergenciaController@analisar');
AdminRouter::post('/logistica/divergencias/resolver',       'DivergenciaController@resolver');
AdminRouter::post('/logistica/divergencias/ignorar',        'DivergenciaController@ignorar');
AdminRouter::post('/logistica/divergencias/reabrir',        'DivergenciaController@reabrir');
AdminRouter::post('/logistica/divergencias/atualizar',      'DivergenciaController@atualizar');
// alertas de produto
AdminRouter::get ('/logistica/divergencias/alertas',        'DivergenciaController@alertas');
AdminRouter::get ('/logistica/divergencias/alerta-obter',   'DivergenciaController@alertaObter');
AdminRouter::post('/logistica/divergencias/resolver-alerta','DivergenciaController@resolverAlerta');
AdminRouter::post('/logistica/divergencias/reabrir-alerta', 'DivergenciaController@reabrirAlerta');

// Gestão admin das chaves
AdminRouter::get ('/logistica/api-keys',          'ApiKeyController@index');
AdminRouter::get ('/logistica/api-keys/dados',    'ApiKeyController@dados');
AdminRouter::post('/logistica/api-keys/criar',    'ApiKeyController@criar');
AdminRouter::post('/logistica/api-keys/atualizar','ApiKeyController@atualizar');
AdminRouter::post('/logistica/api-keys/revogar',  'ApiKeyController@revogar');


AdminRouter::get ('/logistica/frete-fallback',          'FreteFallbackController@index');
AdminRouter::get ('/logistica/frete-fallback/dados',    'FreteFallbackController@dados');
AdminRouter::post('/logistica/frete-fallback/salvar',   'FreteFallbackController@salvar');
AdminRouter::post('/logistica/frete-fallback/remover',  'FreteFallbackController@remover');
AdminRouter::post('/logistica/frete-fallback/alternar', 'FreteFallbackController@alternar');

AdminRouter::get('/logistica/etiquetas/buscar-cep',     'EtiquetaController@buscarCep');
AdminRouter::get('/logistica/etiquetas/buscar-cliente', 'EtiquetaController@buscarCliente');