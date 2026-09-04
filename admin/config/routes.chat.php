<?php
// admin/config/routes.chat.php
//
// Módulo Chat — plataforma conversacional WhatsApp (estilo ManyChat).
//
// ORDEM IMPORTA: o AdminRouter traduz {id} para ([^/]+), que casa com qualquer
// segmento — inclusive "atividade", "nova" e "exportar". Toda rota literal
// precisa vir ANTES da rota com parâmetro do mesmo prefixo.

// ── Visão geral ──────────────────────────────────────────────
AdminRouter::get ('/chat',                        'ChatAdminController@index');
AdminRouter::get ('/chat/dados',                  'ChatAdminController@dados');

// ── Configuração ─────────────────────────────────────────────
AdminRouter::get ('/chat/config',                 'ChatAdminController@config');
AdminRouter::post('/chat/config/salvar',          'ChatAdminController@configSalvar');
AdminRouter::post('/chat/config/testar',          'ChatAdminController@configTestar');
AdminRouter::get ('/chat/config/webhook-logs',            'ChatAdminController@webhookLogs');
AdminRouter::get ('/chat/config/webhook-logs/{id:\d+}',   'ChatAdminController@webhookLogDetalhe');

// ── Templates HSM ────────────────────────────────────────────
AdminRouter::get ('/chat/templates',              'ChatAdminController@templates');
AdminRouter::post('/chat/templates/sincronizar',  'ChatAdminController@templatesSincronizar');

// ── Inbox / Live Chat ────────────────────────────────────────
AdminRouter::get ('/chat/inbox',                       'ChatInboxController@index');
AdminRouter::get ('/chat/inbox/conversas',             'ChatInboxController@conversas');
AdminRouter::get ('/chat/inbox/{id}/thread',           'ChatInboxController@thread');
AdminRouter::get ('/chat/inbox/{id}/novas',            'ChatInboxController@novas');
AdminRouter::post('/chat/inbox/{id}/enviar',           'ChatInboxController@enviar');
AdminRouter::post('/chat/inbox/{id}/template',         'ChatInboxController@enviarTemplate');
AdminRouter::post('/chat/inbox/{id}/upload',           'ChatInboxController@upload');
AdminRouter::post('/chat/inbox/{id}/status',           'ChatInboxController@status');
AdminRouter::post('/chat/inbox/{id}/atribuir',         'ChatInboxController@atribuir');
AdminRouter::post('/chat/inbox/{id}/lida',             'ChatInboxController@marcarLida');
AdminRouter::post('/chat/inbox/{id}/bot',              'ChatInboxController@bot');
AdminRouter::post('/chat/inbox/{id}/iniciar-fluxo',    'ChatInboxController@iniciarFluxo');
AdminRouter::post('/chat/inbox/{id}/nota',             'ChatInboxController@adicionarNota');

// ── Contatos ─────────────────────────────────────────────────
// Literais antes de /chat/contatos/{id}
AdminRouter::get ('/chat/contatos',                 'ChatContatoController@index');
AdminRouter::get ('/chat/contatos/exportar',        'ChatContatoController@exportar');
AdminRouter::get ('/chat/contatos/buscar-clientes', 'ChatContatoController@buscarClientes');
AdminRouter::post('/chat/contatos/criar',           'ChatContatoController@criar');
AdminRouter::get ('/chat/contatos/{id}',            'ChatContatoController@show');
AdminRouter::post('/chat/contatos/{id}/salvar',     'ChatContatoController@salvar');
AdminRouter::post('/chat/contatos/{id}/tag',        'ChatContatoController@tag');
AdminRouter::post('/chat/contatos/{id}/campo',      'ChatContatoController@campo');
AdminRouter::post('/chat/contatos/{id}/optin',      'ChatContatoController@optin');
AdminRouter::post('/chat/contatos/{id}/bloquear',   'ChatContatoController@bloquear');
AdminRouter::post('/chat/contatos/{id}/vincular',   'ChatContatoController@vincular');
AdminRouter::post('/chat/notas/{id}/excluir',       'ChatContatoController@excluirNota');

// ── Tags ─────────────────────────────────────────────────────
AdminRouter::get ('/chat/tags',              'ChatContatoController@tags');
AdminRouter::post('/chat/tags/salvar',       'ChatContatoController@tagSalvar');
AdminRouter::post('/chat/tags/{id}/excluir', 'ChatContatoController@tagExcluir');

// ── Fluxos ───────────────────────────────────────────────────
// /chat/fluxos/atividade PRECISA vir antes de /chat/fluxos/{id}
AdminRouter::get ('/chat/fluxos',                  'ChatFluxoController@index');
AdminRouter::get ('/chat/fluxos/atividade',        'ChatFluxoController@atividade');
AdminRouter::get ('/chat/fluxos/atividade/dados',  'ChatFluxoController@atividadeDados');
AdminRouter::post('/chat/fluxos/criar',            'ChatFluxoController@criar');
AdminRouter::get ('/chat/fluxos/{id}',             'ChatFluxoController@editor');
AdminRouter::get ('/chat/fluxos/{id}/stats',       'ChatFluxoController@stats');
AdminRouter::post('/chat/fluxos/{id}/salvar',      'ChatFluxoController@salvar');
AdminRouter::post('/chat/fluxos/{id}/publicar',    'ChatFluxoController@publicar');
AdminRouter::post('/chat/fluxos/{id}/status',      'ChatFluxoController@status');
AdminRouter::post('/chat/fluxos/{id}/duplicar',    'ChatFluxoController@duplicar');
AdminRouter::post('/chat/fluxos/{id}/excluir',     'ChatFluxoController@excluir');

// ── Gatilhos ─────────────────────────────────────────────────
AdminRouter::get ('/chat/gatilhos',              'ChatGatilhoController@index');
AdminRouter::post('/chat/gatilhos/salvar',       'ChatGatilhoController@salvar');
AdminRouter::post('/chat/gatilhos/simular',      'ChatGatilhoController@simular');
AdminRouter::post('/chat/gatilhos/{id}/ativo',   'ChatGatilhoController@alternarAtivo');
AdminRouter::post('/chat/gatilhos/{id}/excluir', 'ChatGatilhoController@excluir');

// ── Campanhas ────────────────────────────────────────────────
// /chat/campanhas/nova antes de /chat/campanhas/{id}
AdminRouter::get ('/chat/campanhas',                'ChatCampanhaController@index');
AdminRouter::get ('/chat/campanhas/nova',           'ChatCampanhaController@nova');
AdminRouter::post('/chat/campanhas/salvar',         'ChatCampanhaController@salvar');
AdminRouter::post('/chat/campanhas/estimar',        'ChatCampanhaController@estimar');
AdminRouter::get ('/chat/campanhas/{id}',           'ChatCampanhaController@show');
AdminRouter::get ('/chat/campanhas/{id}/editar',    'ChatCampanhaController@editar');
AdminRouter::get ('/chat/campanhas/{id}/dados',     'ChatCampanhaController@dados');
AdminRouter::post('/chat/campanhas/{id}/iniciar',   'ChatCampanhaController@iniciar');
AdminRouter::post('/chat/campanhas/{id}/pausar',    'ChatCampanhaController@pausar');
AdminRouter::post('/chat/campanhas/{id}/cancelar',  'ChatCampanhaController@cancelar');
AdminRouter::post('/chat/campanhas/{id}/excluir',   'ChatCampanhaController@excluir');

// ── Instagram ────────────────────────────────────────────────
// Literais antes das rotas com {id}
AdminRouter::get ('/chat/instagram',                    'ChatInstagramController@index');
AdminRouter::get ('/chat/instagram/regras',             'ChatInstagramController@regras');
AdminRouter::get ('/chat/instagram/comentarios',        'ChatInstagramController@comentarios');
AdminRouter::get ('/chat/instagram/top-seguidores',     'ChatInstagramController@topSeguidores');
AdminRouter::post('/chat/instagram/conectar',           'ChatInstagramController@conectar');
AdminRouter::post('/chat/instagram/regras/salvar',      'ChatInstagramController@salvarRegra');
AdminRouter::post('/chat/instagram/regras/simular',     'ChatInstagramController@simular');
AdminRouter::post('/chat/instagram/regras/{id}/ativo',  'ChatInstagramController@alternarRegra');
AdminRouter::post('/chat/instagram/regras/{id}/excluir','ChatInstagramController@excluirRegra');
AdminRouter::post('/chat/instagram/{id}/assinar',       'ChatInstagramController@assinar');
AdminRouter::post('/chat/instagram/{id}/sincronizar',   'ChatInstagramController@sincronizar');
AdminRouter::post('/chat/instagram/{id}/ativo',         'ChatInstagramController@alternarAtivo');
AdminRouter::post('/chat/instagram/{id}/testar',        'ChatInstagramController@testar');
AdminRouter::post('/chat/instagram/{id}/desconectar',   'ChatInstagramController@desconectar');

// ── Automações do Instagram (pastas, receitas, editor, insights) ─────────
// Literais antes das rotas com {id}
AdminRouter::get ('/chat/automacoes',                  'ChatIgAutomacaoController@index');
AdminRouter::get ('/chat/automacoes/nova',             'ChatIgAutomacaoController@nova');
AdminRouter::post('/chat/automacoes/criar',            'ChatIgAutomacaoController@criar');
AdminRouter::post('/chat/automacoes/pastas/salvar',    'ChatIgAutomacaoController@salvarPasta');
AdminRouter::post('/chat/automacoes/pastas/{id}/excluir', 'ChatIgAutomacaoController@excluirPasta');
AdminRouter::get ('/chat/automacoes/{id}',             'ChatIgAutomacaoController@show');
AdminRouter::get ('/chat/automacoes/{id}/editar',      'ChatIgAutomacaoController@editar');
AdminRouter::get ('/chat/automacoes/{id}/dados',       'ChatIgAutomacaoController@dados');
AdminRouter::post('/chat/automacoes/{id}/salvar',      'ChatIgAutomacaoController@salvar');
AdminRouter::post('/chat/automacoes/{id}/status',      'ChatIgAutomacaoController@status');
AdminRouter::post('/chat/automacoes/{id}/duplicar',    'ChatIgAutomacaoController@duplicar');
AdminRouter::post('/chat/automacoes/{id}/excluir',     'ChatIgAutomacaoController@excluir');
AdminRouter::post('/chat/automacoes/{id}/restaurar',   'ChatIgAutomacaoController@restaurar');
AdminRouter::post('/chat/automacoes/{id}/remover',     'ChatIgAutomacaoController@remover');
AdminRouter::post('/chat/automacoes/{id}/pasta',       'ChatIgAutomacaoController@mover');
AdminRouter::post('/chat/automacoes/{id}/transferir',  'ChatIgAutomacaoController@transferir');
