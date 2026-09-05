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
AdminRouter::post('/ia/recorte/gerar',              'IAGeracaoController@recorteGerar');
AdminRouter::get ('/ia/gerar/status',               'IAGeracaoController@status');
AdminRouter::get ('/ia/arquivo',                    'IAGeracaoController@arquivo');

// ── IA · Compositor de banners (Fase 2C) ──────────────────
AdminRouter::post('/ia/banner/publicar',            'IAGeracaoController@bannerPublicar');

// ── IA · Campanhas em lote (Fase 3B) ──────────────────────
AdminRouter::get ('/ia/campanhas',                  'IACampanhaController@index');
AdminRouter::get ('/ia/campanhas/nova',             'IACampanhaController@nova');
AdminRouter::get ('/ia/campanhas/listar',           'IACampanhaController@listar');
AdminRouter::get ('/ia/campanha',                   'IACampanhaController@detalhe');
AdminRouter::get ('/ia/campanha/dados',             'IACampanhaController@dados');
AdminRouter::get ('/ia/campanha/estimativa',        'IACampanhaController@estimativa');
AdminRouter::get ('/ia/campanha/grade',             'IACampanhaController@grade');
AdminRouter::get ('/ia/campanha/produtos-filtro',   'IACampanhaController@produtosPorFiltro');
AdminRouter::post('/ia/campanha/criar',             'IACampanhaController@criar');
AdminRouter::post('/ia/campanha/atualizar',         'IACampanhaController@atualizar');
AdminRouter::post('/ia/campanha/produtos',          'IACampanhaController@produtos');
AdminRouter::post('/ia/campanha/tipos',             'IACampanhaController@tipos');
AdminRouter::post('/ia/campanha/iniciar',           'IACampanhaController@iniciar');
AdminRouter::post('/ia/campanha/pausar',            'IACampanhaController@pausar');
AdminRouter::post('/ia/campanha/retomar',           'IACampanhaController@retomar');
AdminRouter::post('/ia/campanha/cancelar',          'IACampanhaController@cancelar');
AdminRouter::post('/ia/campanha/arquivar',          'IACampanhaController@arquivar');
AdminRouter::post('/ia/campanha/refazer-falhas',    'IACampanhaController@refazerFalhas');
AdminRouter::post('/ia/campanha/aprovar-concluidas','IACampanhaController@aprovarConcluidas');

// ── IA · Agentes de BI (SportMoto AI — Fase A) ─────────────
// Permissão marketing_ia_agentes (super+gerente). Criar/editar agentes
// sem código: persona, ferramentas, páginas do BI, perguntas, agendado.
AdminRouter::get ('/ia/agentes',                    'IAAgenteController@index');
AdminRouter::get ('/ia/agentes/form',               'IAAgenteController@form');
AdminRouter::post('/ia/agentes/salvar',             'IAAgenteController@salvar');
AdminRouter::post('/ia/agentes/alternar',           'IAAgenteController@alternar');
AdminRouter::post('/ia/agentes/excluir',            'IAAgenteController@excluir');

// ── IA · Histórico e curadoria (Fase 1) ───────────────────
AdminRouter::get ('/ia/historico',                  'IAGeracaoController@historico');
AdminRouter::get ('/ia/historico/linhas',           'IAGeracaoController@historicoLinhas');
AdminRouter::get ('/ia/historico/detalhe',          'IAGeracaoController@historicoDetalhe');
AdminRouter::post('/ia/historico/aprovacao',        'IAGeracaoController@aprovacao');
AdminRouter::post('/ia/historico/refazer',          'IAGeracaoController@refazer');