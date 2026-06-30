<?php
/**
 * PATCH — admin/config/routes.php
 * Adicionar este bloco no final do arquivo (antes do `return` final, se houver),
 * ou em qualquer ponto após as demais rotas administrativas.
 *
 * Não substituir rotas existentes. Apenas anexar.
 */

// ------- Email Marketing -------
AdminRouter::get( '/email-marketing',                          'EmailMarketingController@index');

AdminRouter::get( '/email-marketing/provedores',               'EmailProviderAdminController@index');
AdminRouter::post('/email-marketing/provedores/salvar',        'EmailProviderAdminController@salvar');
AdminRouter::post('/email-marketing/provedores/testar',        'EmailProviderAdminController@testar');

AdminRouter::get( '/email-marketing/contatos',                 'EmailContactAdminController@index');
AdminRouter::post('/email-marketing/contatos/sincronizar',     'EmailContactAdminController@sincronizar');
AdminRouter::post('/email-marketing/contatos/bloquear',        'EmailContactAdminController@bloquear');
AdminRouter::post('/email-marketing/contatos/desbloquear',     'EmailContactAdminController@desbloquear');

// AdminRouter::get( '/email-marketing/listas',                   'EmailListAdminController@index');
// AdminRouter::post('/email-marketing/listas/salvar',            'EmailListAdminController@salvar');
// AdminRouter::post('/email-marketing/listas/excluir',           'EmailListAdminController@excluir');

AdminRouter::get( '/email-marketing/segmentos',                'EmailSegmentAdminController@index');
AdminRouter::post('/email-marketing/segmentos/salvar',         'EmailSegmentAdminController@salvar');
AdminRouter::post('/email-marketing/segmentos/preview',        'EmailSegmentAdminController@preview');
AdminRouter::post('/email-marketing/segmentos/excluir',        'EmailSegmentAdminController@excluir');

AdminRouter::get( '/email-marketing/templates',                'EmailTemplateAdminController@index');
AdminRouter::get( '/email-marketing/templates/criar',          'EmailTemplateAdminController@criar');
AdminRouter::get( '/email-marketing/templates/{id}/editar',    'EmailTemplateAdminController@editar');
AdminRouter::post('/email-marketing/templates/salvar',         'EmailTemplateAdminController@salvar');
AdminRouter::post('/email-marketing/templates/preview',        'EmailTemplateAdminController@preview');
AdminRouter::post('/email-marketing/templates/excluir',        'EmailTemplateAdminController@excluir');

AdminRouter::get( '/email-marketing/campanhas',                'EmailCampaignAdminController@index');
AdminRouter::get( '/email-marketing/campanhas/criar',          'EmailCampaignAdminController@criar');
AdminRouter::get( '/email-marketing/campanhas/{id}/editar',    'EmailCampaignAdminController@editar');
AdminRouter::get( '/email-marketing/campanhas/{id}/relatorio', 'EmailCampaignAdminController@relatorio');
AdminRouter::post('/email-marketing/campanhas/salvar',         'EmailCampaignAdminController@salvar');
AdminRouter::post('/email-marketing/campanhas/testar',         'EmailCampaignAdminController@testar');
AdminRouter::post('/email-marketing/campanhas/enfileirar',     'EmailCampaignAdminController@enfileirar');
AdminRouter::post('/email-marketing/campanhas/pausar',         'EmailCampaignAdminController@pausar');
AdminRouter::post('/email-marketing/campanhas/continuar',      'EmailCampaignAdminController@continuar');
AdminRouter::post('/email-marketing/campanhas/cancelar',       'EmailCampaignAdminController@cancelar');
AdminRouter::post('/email-marketing/campanhas/duplicar',       'EmailCampaignAdminController@duplicar');

AdminRouter::get( '/email-marketing/supressoes',               'EmailSuppressionAdminController@index');
AdminRouter::post('/email-marketing/supressoes/adicionar',     'EmailSuppressionAdminController@adicionar');
AdminRouter::post('/email-marketing/supressoes/remover',       'EmailSuppressionAdminController@remover');

AdminRouter::get( '/email-marketing/listas',                        'EmailListAdminController@index');
AdminRouter::post('/email-marketing/listas/salvar',                 'EmailListAdminController@salvar');
AdminRouter::post('/email-marketing/listas/excluir',                'EmailListAdminController@excluir');
AdminRouter::get( '/email-marketing/listas/{id}',                  'EmailListAdminController@detalhes');
AdminRouter::post('/email-marketing/listas/buscar-contatos',        'EmailListAdminController@buscarContatos');
AdminRouter::post('/email-marketing/listas/adicionar-contato',      'EmailListAdminController@adicionarContato');
AdminRouter::post('/email-marketing/listas/adicionar-em-lote',      'EmailListAdminController@adicionarEmLote');
AdminRouter::post('/email-marketing/listas/remover-contato',        'EmailListAdminController@removerContato');

AdminRouter::get ('/email-marketing/templates',                              'EmailTemplateAdminController@index');
AdminRouter::get ('/email-marketing/templates/criar',                        'EmailTemplateAdminController@criar');
AdminRouter::get ('/email-marketing/templates/criar-visual',                 'EmailTemplateAdminController@criarVisual');
AdminRouter::get ('/email-marketing/templates/{id}/editar',                  'EmailTemplateAdminController@editar');
AdminRouter::post('/email-marketing/templates/salvar',                       'EmailTemplateAdminController@salvar');
AdminRouter::post('/email-marketing/templates/salvar-visual',                'EmailTemplateAdminController@salvarVisual');
AdminRouter::post('/email-marketing/templates/preview',                      'EmailTemplateAdminController@preview');
AdminRouter::post('/email-marketing/templates/excluir',                      'EmailTemplateAdminController@excluir');
AdminRouter::post('/email-marketing/templates/duplicar',                     'EmailTemplateAdminController@duplicar');
AdminRouter::get ('/email-marketing/templates/{id}/versoes',                 'EmailTemplateAdminController@versoes');
AdminRouter::get ('/email-marketing/templates/{id}/versoes/{versaoId}',      'EmailTemplateAdminController@verVersao');
AdminRouter::post('/email-marketing/templates/restaurar-versao',             'EmailTemplateAdminController@restaurarVersao');

/**
 * PATCH para admin/config/routes.email-marketing.php
 * Rotas de teste A/B.
 */
AdminRouter::post('/email-marketing/ab/ativar',                            'EmailAbTestAdminController@ativar');
AdminRouter::post('/email-marketing/ab/desativar',                         'EmailAbTestAdminController@desativar');
AdminRouter::get ('/email-marketing/campanhas/{id}/ab',                    'EmailAbTestAdminController@variacoes');
AdminRouter::post('/email-marketing/ab/salvar-variacoes',                  'EmailAbTestAdminController@salvarVariacoes');
AdminRouter::get ('/email-marketing/campanhas/{id}/ab/validar',            'EmailAbTestAdminController@validar');
AdminRouter::get ('/email-marketing/campanhas/{id}/ab/relatorio',          'EmailAbTestAdminController@relatorio');
AdminRouter::post('/email-marketing/ab/escolher-vencedor',                 'EmailAbTestAdminController@escolherVencedor');

//== Importação CSV ===
AdminRouter::get ('/email-marketing/csv',                            'EmailCsvImportAdminController@index');
AdminRouter::get ('/email-marketing/csv/novo',                       'EmailCsvImportAdminController@novo');
AdminRouter::post('/email-marketing/csv/upload',                     'EmailCsvImportAdminController@upload');
AdminRouter::post('/email-marketing/csv/confirmar',                  'EmailCsvImportAdminController@confirmar');
AdminRouter::get ('/email-marketing/csv/{id}',                       'EmailCsvImportAdminController@detalhes');
AdminRouter::get ('/email-marketing/csv/{id}/progresso',             'EmailCsvImportAdminController@progresso');
AdminRouter::get ('/email-marketing/csv/{id}/erros.csv',             'EmailCsvImportAdminController@baixarErros');
AdminRouter::post('/email-marketing/csv/cancelar',                   'EmailCsvImportAdminController@cancelar');

/**
 * PATCH para admin/config/routes.email-marketing.php
 * Adicione as rotas abaixo.
 */

AdminRouter::get ('/email-marketing/automacoes',                              'AutomacaoAdminController@index');
AdminRouter::get ('/email-marketing/automacoes/{id}/editar',                  'AutomacaoAdminController@editar');
AdminRouter::post('/email-marketing/automacoes/salvar',                       'AutomacaoAdminController@salvar');
AdminRouter::post('/email-marketing/automacoes/toggle',                       'AutomacaoAdminController@toggle');
AdminRouter::get ('/email-marketing/automacoes/{id}/relatorio',               'AutomacaoAdminController@relatorio');


AdminRouter::get ('/email-marketing/transacional',         'EmailTransacionalAdminController@index');
AdminRouter::get ('/email-marketing/transacional/log',     'EmailTransacionalAdminController@log');
AdminRouter::post('/email-marketing/transacional/testar',  'EmailTransacionalAdminController@testar');

// Logs unificados (todos os canais)
AdminRouter::get('/configuracoes/logs/canais',             'CanalLogAdminController@index');
AdminRouter::get('/configuracoes/logs/canais/detalhe',     'CanalLogAdminController@detalhe');
AdminRouter::get('/configuracoes/logs/canais/busca-pedido','CanalLogAdminController@buscaPedido');
AdminRouter::get('/configuracoes/logs/canais/exportar',    'CanalLogAdminController@exportar');

// Atalhos por canal (mesma view, filtro fixo)
AdminRouter::get('/configuracoes/logs/whatsapp',           'CanalLogAdminController@whatsapp');
AdminRouter::get('/configuracoes/logs/whatsapp/exportar',  'CanalLogAdminController@exportar');
AdminRouter::get('/configuracoes/logs/email-transacional', 'CanalLogAdminController@emailTransacional');