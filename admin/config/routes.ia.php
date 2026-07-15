<?php
// admin/config/routes.ia.php
// Central de Marketing IA — Fases 0 e 1
// Permissões (verificadas nos controllers): marketing_ia (módulo) ·
// marketing_ia_config (configurações) · marketing_ia_aprovar (curadoria)

// ── IA · Configurações (Fase 0 + testar conexão) ──────────
AdminRouter::get ('/ia/config',                     'IAConfigController@index');
AdminRouter::get ('/ia/config/provedores/linhas',   'IAConfigController@provedoresLinhas');
AdminRouter::get ('/ia/config/provedor/form',       'IAConfigController@provedorForm');
AdminRouter::post('/ia/config/provedor/salvar',     'IAConfigController@provedorSalvar');
AdminRouter::post('/ia/config/provedor/alternar',   'IAConfigController@provedorAlternar');
AdminRouter::post('/ia/config/provedor/testar',     'IAConfigController@provedorTestar');
AdminRouter::get ('/ia/config/modelos/linhas',      'IAConfigController@modelosLinhas');
AdminRouter::get ('/ia/config/modelo/form',         'IAConfigController@modeloForm');
AdminRouter::post('/ia/config/modelo/salvar',       'IAConfigController@modeloSalvar');
AdminRouter::post('/ia/config/modelo/alternar',     'IAConfigController@modeloAlternar');
AdminRouter::post('/ia/config/modelo/excluir',      'IAConfigController@modeloExcluir');
AdminRouter::get ('/ia/config/limites/linhas',      'IAConfigController@limitesLinhas');
AdminRouter::get ('/ia/config/limite/form',         'IAConfigController@limiteForm');
AdminRouter::post('/ia/config/limite/salvar',       'IAConfigController@limiteSalvar');
AdminRouter::post('/ia/config/limite/excluir',      'IAConfigController@limiteExcluir');

// ── IA · Geração de conteúdo (Fase 1) ─────────────────────
AdminRouter::get ('/ia/gerar',                      'IAGeracaoController@gerar');
AdminRouter::get ('/ia/gerar/produto-busca',        'IAGeracaoController@produtoBusca');
AdminRouter::get ('/ia/gerar/produto-painel',       'IAGeracaoController@produtoPainel');
AdminRouter::post('/ia/gerar/preview',              'IAGeracaoController@preview');
AdminRouter::post('/ia/gerar/enfileirar',           'IAGeracaoController@enfileirar');
AdminRouter::get ('/ia/gerar/status',               'IAGeracaoController@status');

// ── IA · Histórico e curadoria (Fase 1) ───────────────────
AdminRouter::get ('/ia/historico',                  'IAGeracaoController@historico');
AdminRouter::get ('/ia/historico/linhas',           'IAGeracaoController@historicoLinhas');
AdminRouter::get ('/ia/historico/detalhe',          'IAGeracaoController@historicoDetalhe');
AdminRouter::post('/ia/historico/aprovacao',        'IAGeracaoController@aprovacao');
AdminRouter::post('/ia/historico/refazer',          'IAGeracaoController@refazer');