<?php /** Estilos compartilhados da Central de Marketing IA (telas gerar/histórico). */ ?>
<style>
:root {
  --em-blue: #0a66c2;
  --em-blue-escuro: #084d92;
  --em-bg: #f4f6f9;
  --em-card: #ffffff;
  --em-texto: #1c1e21;
  --em-texto-sub: #65676b;
  --em-border: #e4e6eb;
  --em-shadow: 0 1px 2px rgba(16, 24, 40, .06), 0 1px 3px rgba(16, 24, 40, .1);
  --em-ok: #0a7f42;
  --em-ok-bg: #e6f4ec;
  --em-aviso: #92400e;
  --em-aviso-bg: #fef3c7;
  --em-erro: #b42318;
  --em-erro-bg: #fee4e2;
  --em-off: #475467;
  --em-off-bg: #eaecf0;
}
@media (prefers-color-scheme: dark) {
  :root {
    --em-bg: #0f1115;
    --em-card: #171a21;
    --em-texto: #e7e9ee;
    --em-texto-sub: #9aa0ab;
    --em-border: #262b35;
    --em-shadow: 0 1px 2px rgba(0, 0, 0, .5);
    --em-ok-bg: rgba(10, 127, 66, .16);
    --em-aviso-bg: rgba(146, 64, 14, .18);
    --em-erro-bg: rgba(180, 35, 24, .16);
    --em-off-bg: rgba(71, 84, 103, .25);
  }
}

.ia_pagina { max-width: 1180px; margin: 0 auto; padding: 24px 16px 64px; color: var(--em-texto); }
.ia_topo { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 20px; }
.ia_titulo { font-size: 22px; font-weight: 700; letter-spacing: -.01em; margin: 0; }
.ia_titulo i { color: var(--em-blue); margin-right: 8px; }
.ia_sub { color: var(--em-texto-sub); font-size: 13px; margin: 4px 0 0; }
.ia_topo_acoes { display: flex; gap: 8px; flex-wrap: wrap; }

.ia_card { background: var(--em-card); border: 1px solid var(--em-border); border-radius: 16px; box-shadow: var(--em-shadow); padding: 18px; margin-bottom: 16px; }
.ia_card_titulo { font-size: 14px; font-weight: 700; margin: 0 0 12px; display: flex; align-items: center; gap: 8px; }
.ia_card_titulo i { color: var(--em-blue); }

.ia_kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 16px; }
.ia_kpi { background: var(--em-card); border: 1px solid var(--em-border); border-radius: 16px; box-shadow: var(--em-shadow); padding: 14px 16px; }
.ia_kpi_rotulo { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: var(--em-texto-sub); margin-bottom: 6px; }
.ia_kpi_valor { font-size: 22px; font-weight: 700; font-variant-numeric: tabular-nums; }
.ia_kpi_valor small { font-size: 12px; font-weight: 500; color: var(--em-texto-sub); }

.ia_btn { display: inline-flex; align-items: center; gap: 6px; border: 1px solid var(--em-border); background: var(--em-card); color: var(--em-texto); border-radius: 999px; padding: 8px 14px; font-size: 13px; font-weight: 600; cursor: pointer; transition: background .15s, border-color .15s, opacity .15s; text-decoration: none; }
.ia_btn:hover { border-color: var(--em-blue); color: var(--em-blue); }
.ia_btn:disabled { opacity: .45; cursor: not-allowed; }
.ia_btn_primario { background: var(--em-blue); border-color: var(--em-blue); color: #fff; }
.ia_btn_primario:hover { background: var(--em-blue-escuro); border-color: var(--em-blue-escuro); color: #fff; }
.ia_btn_icone { padding: 7px 9px; border-radius: 10px; }
.ia_btn.ia_perigo:hover { border-color: var(--em-erro); color: var(--em-erro); }

.ia_pill { display: inline-flex; align-items: center; gap: 5px; border-radius: 999px; padding: 3px 10px; font-size: 11.5px; font-weight: 600; white-space: nowrap; }
.ia_pill i { font-size: 11px; }
.ia_pill_ok { background: var(--em-ok-bg); color: var(--em-ok); }
.ia_pill_aviso { background: var(--em-aviso-bg); color: var(--em-aviso); }
.ia_pill_erro { background: var(--em-erro-bg); color: var(--em-erro); }
.ia_pill_off { background: var(--em-off-bg); color: var(--em-off); }
.ia_pill_azul { background: rgba(10, 102, 194, .12); color: var(--em-blue); }

.ia_form_grupo { margin-bottom: 14px; }
.ia_form_grupo label { display: block; font-size: 12.5px; font-weight: 600; margin-bottom: 6px; }
.ia_form_linha { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
@media (max-width: 720px) { .ia_form_linha { grid-template-columns: 1fr; } }
.ia_input { width: 100%; box-sizing: border-box; background: var(--em-bg); border: 1px solid var(--em-border); border-radius: 10px; color: var(--em-texto); padding: 9px 12px; font-size: 13.5px; outline: none; transition: border-color .15s, box-shadow .15s; }
.ia_input:focus { border-color: var(--em-blue); box-shadow: 0 0 0 3px rgba(10, 102, 194, .15); }
.ia_input_mono, .ia_mono { font-family: 'DM Mono', ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12.5px; }
textarea.ia_input { resize: vertical; min-height: 88px; line-height: 1.5; }
.ia_ajuda { font-size: 11.5px; color: var(--em-texto-sub); margin: 6px 0 0; line-height: 1.45; }
.ia_check { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; cursor: pointer; }
.ia_form_rodape { display: flex; justify-content: flex-end; gap: 8px; margin-top: 18px; padding-top: 14px; border-top: 1px solid var(--em-border); }

.ia_tabela_wrap { overflow-x: auto; }
.ia_tabela { width: 100%; border-collapse: collapse; font-size: 13px; }
.ia_tabela th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: var(--em-texto-sub); padding: 8px 10px; border-bottom: 1px solid var(--em-border); white-space: nowrap; }
.ia_tabela td { padding: 10px; border-bottom: 1px solid var(--em-border); vertical-align: middle; }
.ia_tabela tr:last-child td { border-bottom: 0; }
.ia_num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.ia_celula_principal { display: block; font-weight: 600; }
.ia_celula_sub { display: block; font-size: 11.5px; color: var(--em-texto-sub); margin-top: 2px; }
.ia_acoes { display: flex; gap: 6px; justify-content: flex-end; }
.ia_vazio td { text-align: center; color: var(--em-texto-sub); padding: 28px 10px; }
.ia_cap { display: inline-block; background: rgba(10, 102, 194, .1); color: var(--em-blue); border-radius: 8px; padding: 3px 8px; font-size: 11.5px; font-weight: 600; }

/* Busca de produto (autocomplete) */
.ia_busca_wrap { position: relative; }
.ia_busca_lista { position: absolute; z-index: 60; left: 0; right: 0; top: calc(100% + 6px); background: var(--em-card); border: 1px solid var(--em-border); border-radius: 12px; box-shadow: 0 12px 32px rgba(16, 24, 40, .18); overflow: hidden; display: none; }
.ia_busca_item { display: flex; justify-content: space-between; gap: 12px; align-items: center; padding: 10px 14px; cursor: pointer; border-bottom: 1px solid var(--em-border); }
.ia_busca_item:last-child { border-bottom: 0; }
.ia_busca_item:hover { background: rgba(10, 102, 194, .07); }
.ia_busca_item small { color: var(--em-texto-sub); white-space: nowrap; }

/* Painel do produto */
.ia_produto { display: flex; gap: 14px; align-items: flex-start; flex-wrap: wrap; }
.ia_produto_thumb { width: 64px; height: 64px; border-radius: 14px; background: var(--em-bg); border: 1px solid var(--em-border); display: flex; align-items: center; justify-content: center; font-size: 24px; color: var(--em-texto-sub); flex-shrink: 0; }
.ia_produto_info { flex: 1; min-width: 240px; }
.ia_produto_nome { font-size: 15px; font-weight: 700; margin: 0 0 4px; }
.ia_produto_meta { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px; }
.ia_preco { font-variant-numeric: tabular-nums; font-weight: 700; }
.ia_preco_de { color: var(--em-texto-sub); text-decoration: line-through; font-weight: 500; margin-right: 6px; }

/* Cards de resultado */
.ia_resultado { background: var(--em-card); border: 1px solid var(--em-border); border-radius: 16px; box-shadow: var(--em-shadow); padding: 16px; margin-bottom: 12px; }
.ia_resultado_topo { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin-bottom: 10px; }
.ia_resultado_meta { font-size: 12px; color: var(--em-texto-sub); display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
.ia_resultado_texto { white-space: pre-wrap; font-size: 13.5px; line-height: 1.6; background: var(--em-bg); border: 1px solid var(--em-border); border-radius: 12px; padding: 14px; }
.ia_resultado_erro { color: var(--em-erro); font-size: 13px; background: var(--em-erro-bg); border-radius: 12px; padding: 12px 14px; }
.ia_resultado_img { background: var(--em-bg); border: 1px solid var(--em-border); border-radius: 12px; padding: 8px; text-align: center; }
.ia_resultado_img img { max-width: 100%; max-height: 480px; border-radius: 8px; display: inline-block; }
.ia_resultado_acoes { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; }

.ia_spin { display: inline-block; width: 14px; height: 14px; border: 2px solid var(--em-border); border-top-color: var(--em-blue); border-radius: 50%; animation: ia_girar .8s linear infinite; vertical-align: -2px; }
@keyframes ia_girar { to { transform: rotate(360deg); } }

/* Drawer (fallback) e toast */
.ia_veu { position: fixed; inset: 0; background: rgba(15, 17, 21, .45); backdrop-filter: blur(3px); z-index: 90; display: none; }
.ia_drawer { position: fixed; top: 0; right: 0; bottom: 0; width: min(560px, 96vw); background: var(--em-card); border-left: 1px solid var(--em-border); z-index: 91; transform: translateX(102%); transition: transform .22s ease; display: flex; flex-direction: column; }
.ia_drawer.aberto { transform: translateX(0); }
.ia_drawer_topo { display: flex; align-items: center; justify-content: space-between; padding: 16px 18px; border-bottom: 1px solid var(--em-border); }
.ia_drawer_titulo { font-size: 15px; font-weight: 700; margin: 0; }
.ia_drawer_corpo { padding: 18px; overflow-y: auto; flex: 1; }
.ia_toast { position: fixed; bottom: 22px; left: 50%; transform: translateX(-50%) translateY(12px); background: var(--em-texto); color: var(--em-card); border-radius: 999px; padding: 10px 18px; font-size: 13px; font-weight: 600; z-index: 120; opacity: 0; transition: opacity .2s, transform .2s; pointer-events: none; max-width: 92vw; }
.ia_toast.mostrar { opacity: 1; transform: translateX(-50%) translateY(0); }
.ia_toast.erro { background: var(--em-erro); color: #fff; }

.ia_paginacao { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-top: 14px; flex-wrap: wrap; }
.ia_paginacao_info { font-size: 12.5px; color: var(--em-texto-sub); }

.ia_detalhe_grade { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 16px; margin-bottom: 16px; }
.ia_detalhe_item { font-size: 13px; }
.ia_detalhe_item b { display: block; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: var(--em-texto-sub); margin-bottom: 3px; font-weight: 600; }
.ia_pre { white-space: pre-wrap; font-family: 'DM Mono', ui-monospace, monospace; font-size: 12px; line-height: 1.55; background: var(--em-bg); border: 1px solid var(--em-border); border-radius: 12px; padding: 12px; max-height: 320px; overflow-y: auto; }
details.ia_dobra { border: 1px solid var(--em-border); border-radius: 12px; padding: 10px 14px; margin-bottom: 12px; }
details.ia_dobra summary { cursor: pointer; font-size: 13px; font-weight: 600; }
details.ia_dobra[open] summary { margin-bottom: 10px; }
</style>
<style>
/* Fase 2B — faixa da foto do produto */
.ia_foto_strip { display: flex; gap: 14px; align-items: center; background: var(--em-bg); border: 1px solid var(--em-border); border-radius: 12px; padding: 12px 14px; margin-bottom: 16px; }
.ia_foto_strip img { width: 72px; height: 72px; object-fit: cover; border-radius: 10px; border: 1px solid var(--em-border); background: #fff; }
.ia_foto_titulo { font-weight: 600; font-size: 14px; margin: 0 0 2px; }
</style>
<style>
/* Fase 3B — campanhas */
.ia_camp_cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 14px; }
.ia_camp_card { background: var(--em-card, #fff); border: 1px solid var(--em-border); border-radius: 16px; padding: 16px; }
.ia_camp_topo { display: flex; justify-content: space-between; align-items: center; gap: 10px; }
.ia_camp_nome { font-weight: 700; font-size: 15px; margin: 0; }
.ia_camp_meta { font-size: 12.5px; color: var(--em-texto-suave, #5c6b7a); margin: 8px 0 10px; font-variant-numeric: tabular-nums; }
.ia_camp_prog { height: 8px; border-radius: 6px; background: var(--em-bg); overflow: hidden; }
.ia_camp_prog_fill { height: 100%; background: var(--em-blue, #0a66c2); border-radius: 6px; transition: width .4s; }
.ia_camp_acoes { display: flex; gap: 8px; margin-top: 12px; }
.ia_secao_titulo { font-size: 15px; font-weight: 700; margin: 0 0 12px; }
.ia_grupo_rotulo { font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: var(--em-texto-suave, #5c6b7a); margin: 12px 0 6px; }
.ia_tipos_grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 8px 14px; }
.ia_tipo_item select { margin-top: 6px; width: 100%; }
.ia_form_linha_compacta { display: flex; gap: 8px; }
.ia_form_linha_compacta select { flex: 1; }
.ia_chips { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
.ia_chip { background: var(--em-bg); border: 1px solid var(--em-border); border-radius: 999px; padding: 5px 10px; font-size: 12.5px; display: inline-flex; align-items: center; gap: 6px; }
.ia_chip_x { border: 0; background: none; cursor: pointer; font-size: 14px; line-height: 1; color: var(--em-texto-suave, #5c6b7a); }
.ia_busca_lista { position: relative; }
.ia_busca_item { display: block; width: 100%; text-align: left; border: 1px solid var(--em-border); background: var(--em-card, #fff); padding: 8px 10px; font-size: 13px; cursor: pointer; border-radius: 8px; margin-top: 4px; }
.ia_busca_item:hover { background: var(--em-bg); }
.ia_pill_neutra { background: var(--em-bg); color: var(--em-texto-suave, #5c6b7a); border: 1px solid var(--em-border); }
.ia_grade { width: 100%; border-collapse: collapse; font-size: 13px; }
.ia_grade th, .ia_grade td { padding: 8px 10px; border-bottom: 1px solid var(--em-border); text-align: center; }
.ia_grade th { font-size: 12px; text-transform: uppercase; letter-spacing: .03em; color: var(--em-texto-suave, #5c6b7a); }
.ia_grade .ia_grade_prod { text-align: left; max-width: 280px; }
.ia_grade_celula { cursor: pointer; border: 0; }
</style>
