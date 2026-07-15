<?php
/**
 * Central de Marketing IA · Configurações — página principal (Fase 0).
 * Variáveis: $provedores, $modelos, $limites, $capacidades, $kpis, $csrf
 */
if (!function_exists('ia_e')) {
    function ia_e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('ia_usd')) {
    function ia_usd($v): string {
        if ($v === null || $v === '') return '—';
        $f = (float) $v;
        return 'US$ ' . number_format($f, ($f > 0 && $f < 0.01) ? 4 : 2, ',', '.');
    }
}
?>
<div class="ia_wrap">

  <header class="ia_head">
    <div>
      <h1 class="ia_titulo"><i class="bi bi-stars"></i> Central de Marketing IA</h1>
      <p class="ia_sub">Provedores, modelos e limites de uso</p>
    </div>
    <span class="ia_fase">Fase 0 · Fundação</span>
  </header>

  <section class="ia_kpis">
    <div class="ia_kpi">
      <span class="ia_kpi_rotulo">Provedores ativos</span>
      <span class="ia_kpi_valor"><span id="ia_kpi_prov"><?= (int) $kpis['provedores_ativos'] ?></span><span class="ia_kpi_de">/<span id="ia_kpi_prov_t"><?= (int) $kpis['provedores_total'] ?></span></span></span>
    </div>
    <div class="ia_kpi">
      <span class="ia_kpi_rotulo">Modelos ativos</span>
      <span class="ia_kpi_valor"><span id="ia_kpi_mod"><?= (int) $kpis['modelos_ativos'] ?></span><span class="ia_kpi_de">/<span id="ia_kpi_mod_t"><?= (int) $kpis['modelos_total'] ?></span></span></span>
    </div>
    <div class="ia_kpi">
      <span class="ia_kpi_rotulo">Gasto hoje</span>
      <span class="ia_kpi_valor" id="ia_kpi_gasto"><?= ia_e(ia_usd($kpis['gasto_hoje_usd'])) ?></span>
    </div>
    <div class="ia_kpi">
      <span class="ia_kpi_rotulo">Limite diário global</span>
      <span class="ia_kpi_valor" id="ia_kpi_limite"><?= ia_e(ia_usd($kpis['limite_diario_usd'])) ?></span>
    </div>
  </section>

  <section class="ia_card">
    <div class="ia_card_head">
      <h2 class="ia_card_titulo"><i class="bi bi-plug"></i> Provedores</h2>
      <span class="ia_hint">Novos provedores entram junto com o adapter correspondente (Fase 1+)</span>
    </div>
    <div class="ia_tabela_scroll">
      <table class="ia_tabela">
        <thead>
          <tr>
            <th>Provedor</th>
            <th>Chave de API</th>
            <th class="ia_num">Modelos ativos</th>
            <th class="ia_num">Limite diário</th>
            <th class="ia_num">Timeout</th>
            <th>Status</th>
            <th class="ia_acoes_th"></th>
          </tr>
        </thead>
        <tbody id="ia_tb_prov">
          <?php include __DIR__ . '/_provedores_rows.php'; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="ia_card">
    <div class="ia_card_head">
      <h2 class="ia_card_titulo"><i class="bi bi-cpu"></i> Modelos</h2>
      <button type="button" class="ia_btn ia_btn_primario" id="ia_btn_novo_modelo">
        <i class="bi bi-plus-lg"></i> Novo modelo
      </button>
    </div>
    <div class="ia_tabela_scroll">
      <table class="ia_tabela">
        <thead>
          <tr>
            <th>Capacidade</th>
            <th>Modelo</th>
            <th>Provedor</th>
            <th class="ia_num">Prioridade</th>
            <th>Custo</th>
            <th>Status</th>
            <th class="ia_acoes_th"></th>
          </tr>
        </thead>
        <tbody id="ia_tb_mod">
          <?php include __DIR__ . '/_modelos_rows.php'; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="ia_card">
    <div class="ia_card_head">
      <h2 class="ia_card_titulo"><i class="bi bi-speedometer2"></i> Limites de uso</h2>
      <button type="button" class="ia_btn ia_btn_primario" id="ia_btn_novo_limite">
        <i class="bi bi-plus-lg"></i> Novo limite
      </button>
    </div>
    <div class="ia_tabela_scroll">
      <table class="ia_tabela">
        <thead>
          <tr>
            <th>Escopo</th>
            <th>Referência</th>
            <th class="ia_num">Diário (US$)</th>
            <th class="ia_num">Mensal (US$)</th>
            <th class="ia_num">Ger./min</th>
            <th class="ia_num">Alerta</th>
            <th>Status</th>
            <th class="ia_acoes_th"></th>
          </tr>
        </thead>
        <tbody id="ia_tb_lim">
          <?php include __DIR__ . '/_limites_rows.php'; ?>
        </tbody>
      </table>
    </div>
  </section>

</div>

<style>
/* ==== Central IA · design tokens (herda --em-* quando existirem) ==== */
.ia_wrap{
  --ia-azul:var(--em-blue,#0a66c2);
  --ia-azul-suave:rgba(10,102,194,.08);
  --ia-borda:var(--em-border,#e5e8ec);
  --ia-card:var(--em-card,#ffffff);
  --ia-fundo:var(--em-bg,#f6f7f9);
  --ia-txt:var(--em-text,#101828);
  --ia-txt2:var(--em-text-soft,#5f6b7a);
  --ia-sombra:var(--em-shadow,0 1px 2px rgba(16,24,40,.05));
  max-width:1180px;margin:0 auto;padding:8px 4px 56px;
  font-family:var(--em-font,'Plus Jakarta Sans',system-ui,-apple-system,sans-serif);
  color:var(--ia-txt);
}
@media (prefers-color-scheme:dark){
  /* .ia_wrap{
    --ia-azul-suave:rgba(88,166,255,.12);
    --ia-borda:var(--em-border,#2a2f36);
    --ia-card:var(--em-card,#15181d);
    --ia-fundo:var(--em-bg,#0f1114);
    --ia-txt:var(--em-text,#e8eaed);
    --ia-txt2:var(--em-text-soft,#98a2b3);
    --ia-sombra:0 1px 2px rgba(0,0,0,.45);
  } */
}

/* ==== cabeçalho ==== */
.ia_head{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin:6px 2px 20px}
.ia_titulo{font-size:22px;font-weight:700;letter-spacing:-.01em;margin:0;display:flex;align-items:center;gap:10px}
.ia_titulo .bi{color:var(--ia-azul)}
.ia_sub{margin:4px 0 0;font-size:13.5px;color:var(--ia-txt2)}
.ia_fase{font-size:12px;font-weight:600;color:var(--ia-azul);background:var(--ia-azul-suave);border-radius:999px;padding:6px 14px;white-space:nowrap}

/* ==== KPIs ==== */
.ia_kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:22px}
.ia_kpi{background:var(--ia-card);border:1px solid var(--ia-borda);border-radius:16px;box-shadow:var(--ia-sombra);padding:16px 18px;display:flex;flex-direction:column;gap:6px}
.ia_kpi_rotulo{font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--ia-txt2)}
.ia_kpi_valor{font-size:26px;font-weight:700;font-variant-numeric:tabular-nums;line-height:1.1}
.ia_kpi_de{font-size:15px;font-weight:600;color:var(--ia-txt2)}

/* ==== cards / tabelas ==== */
.ia_card{background:var(--ia-card);border:1px solid var(--ia-borda);border-radius:16px;box-shadow:var(--ia-sombra);margin-bottom:22px;overflow:hidden}
.ia_card_head{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:16px 20px;border-bottom:1px solid var(--ia-borda)}
.ia_card_titulo{font-size:15.5px;font-weight:700;margin:0;display:flex;align-items:center;gap:9px}
.ia_card_titulo .bi{color:var(--ia-azul);font-size:15px}
.ia_hint{font-size:12.5px;color:var(--ia-txt2)}
.ia_tabela_scroll{overflow-x:auto}
.ia_tabela{width:100%;border-collapse:collapse;font-size:13.8px}
.ia_tabela th{font-size:11.5px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--ia-txt2);text-align:left;padding:10px 16px;border-bottom:1px solid var(--ia-borda);white-space:nowrap}
.ia_tabela td{padding:13px 16px;border-bottom:1px solid var(--ia-borda);vertical-align:middle}
.ia_tabela tbody tr:last-child td{border-bottom:0}
.ia_tabela tbody tr:hover td{background:var(--ia-azul-suave)}
.ia_num{text-align:right;font-variant-numeric:tabular-nums}
td.ia_num{font-variant-numeric:tabular-nums}
.ia_acoes_th{width:1%}
.ia_celula_principal{font-weight:600}
.ia_celula_sub{display:block;font-size:12px;color:var(--ia-txt2);margin-top:2px;font-weight:400}
.ia_mono{font-family:var(--em-font-mono,'DM Mono',ui-monospace,SFMono-Regular,monospace);font-size:12.5px}
.ia_vazio td{text-align:center;color:var(--ia-txt2);padding:28px 16px}

/* ==== pills / badges ==== */
.ia_pill{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;border-radius:999px;padding:4px 11px;white-space:nowrap}
.ia_pill_ok{background:rgba(22,163,74,.12);color:#15803d}
.ia_pill_off{background:rgba(100,116,139,.14);color:#64748b}
.ia_pill_aviso{background:rgba(217,119,6,.12);color:#b45309}
@media (prefers-color-scheme:dark){
  .ia_pill_ok{background:rgba(34,197,94,.16);color:#4ade80}
  .ia_pill_off{background:rgba(148,163,184,.16);color:#94a3b8}
  .ia_pill_aviso{background:rgba(245,158,11,.16);color:#fbbf24}
}
.ia_cap{display:inline-block;font-size:12px;font-weight:600;color:var(--ia-azul);background:var(--ia-azul-suave);border-radius:8px;padding:3px 9px;white-space:nowrap}

/* ==== botões ==== */
.ia_btn{display:inline-flex;align-items:center;gap:7px;border-radius:999px;border:1px solid var(--ia-borda);background:var(--ia-card);color:var(--ia-txt);font-size:13px;font-weight:600;padding:8px 16px;cursor:pointer;transition:filter .15s,background .15s}
.ia_btn:hover{filter:brightness(.97)}
.ia_btn_primario{background:var(--ia-azul);border-color:var(--ia-azul);color:#fff}
.ia_btn_primario:hover{filter:brightness(1.06)}
.ia_btn_icone{width:32px;height:32px;padding:0;justify-content:center;border-radius:10px;font-size:14px;color:var(--ia-txt2)}
.ia_btn_icone:hover{color:var(--ia-azul)}
.ia_btn_icone.ia_perigo:hover{color:#dc2626}
.ia_btn_icone[disabled]{opacity:.35;cursor:not-allowed}
.ia_acoes{display:flex;gap:6px;justify-content:flex-end}

/* ==== formulários (drawer) ==== */
.ia_form_grupo{margin-bottom:16px}
.ia_form_linha{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.ia_form_grupo label{display:block;font-size:12.5px;font-weight:600;color:var(--ia-txt2);margin-bottom:6px}
.ia_input{width:100%;box-sizing:border-box;border:1px solid var(--ia-borda);border-radius:10px;background:var(--ia-fundo);color:var(--ia-txt);font-size:14px;padding:9px 12px;font-family:inherit}
.ia_input:focus{outline:2px solid var(--ia-azul-suave);border-color:var(--ia-azul)}
textarea.ia_input{resize:vertical;min-height:74px}
.ia_input_mono{font-family:var(--em-font-mono,'DM Mono',ui-monospace,monospace);font-size:12.8px}
.ia_ajuda{font-size:12px;color:var(--ia-txt2);margin-top:5px;line-height:1.45}
.ia_check{display:flex;align-items:center;gap:9px;font-size:13.5px;font-weight:600;cursor:pointer;user-select:none}
.ia_check input{width:17px;height:17px;accent-color:var(--ia-azul);cursor:pointer}
.ia_form_rodape{display:flex;justify-content:flex-end;gap:10px;margin-top:22px;padding-top:16px;border-top:1px solid var(--ia-borda)}
.ia_aviso_seguro{display:flex;gap:9px;align-items:flex-start;font-size:12.5px;color:var(--ia-txt2);background:var(--ia-azul-suave);border-radius:10px;padding:10px 12px;line-height:1.45}
.ia_aviso_seguro .bi{color:var(--ia-azul);margin-top:1px}

/* ==== drawer fallback (usado só se window.adminDrawer não existir) ==== */
.ia_drawer_veu{position:fixed;inset:0;background:rgba(15,17,20,.45);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);z-index:1050;opacity:0;pointer-events:none;transition:opacity .2s}
.ia_drawer_veu.aberto{opacity:1;pointer-events:auto}
.ia_drawer{position:fixed;top:0;right:0;transform:translateX(105%);width:min(520px,94vw);height:100%;background:var(--em-card,#fff);color:var(--em-text,#101828);z-index:1051;box-shadow:-16px 0 48px rgba(0,0,0,.22);transition:transform .25s ease;display:flex;flex-direction:column}
@media (prefers-color-scheme:dark){.ia_drawer{background:var(--em-card,#15181d);color:var(--em-text,#e8eaed)}}
.ia_drawer.aberto{transform:translateX(0)}
.ia_drawer_head{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--em-border,#e5e8ec)}
.ia_drawer_titulo{font-size:16px;font-weight:700;margin:0}
.ia_drawer_fechar{border:0;background:transparent;font-size:19px;cursor:pointer;color:inherit;line-height:1;padding:4px}
.ia_drawer_corpo{padding:22px;overflow-y:auto;flex:1}

/* ==== toast ==== */
.ia_toast{position:fixed;bottom:26px;left:50%;transform:translate(-50%,16px);background:#111827;color:#fff;font-size:13.5px;font-weight:600;border-radius:999px;padding:10px 20px;opacity:0;pointer-events:none;transition:opacity .22s,transform .22s;z-index:1100;box-shadow:0 8px 24px rgba(0,0,0,.25)}
.ia_toast.visivel{opacity:1;transform:translate(-50%,0)}
.ia_toast.erro{background:#b91c1c}

@media (max-width:720px){
  .ia_head{flex-direction:column;align-items:flex-start}
  .ia_form_linha{grid-template-columns:1fr}
}
.ia_spin { display: inline-block; width: 13px; height: 13px; border: 2px solid var(--em-border); border-top-color: var(--em-blue); border-radius: 50%; animation: ia_girar .8s linear infinite; vertical-align: -2px; }
@keyframes ia_girar { to { transform: rotate(360deg); } }
</style>

<script>
(function ($) {
  'use strict';

  var IA_CSRF = '<?= ia_e($csrf ?? '') ?>';

  var URLS = {
    provLinhas:  '/admin/ia/config/provedores/linhas',
    provForm:    '/admin/ia/config/provedor/form',
    provAlt:     '/admin/ia/config/provedor/alternar',
    provTestar:  '/admin/ia/config/provedor/testar',
    modLinhas:   '/admin/ia/config/modelos/linhas',
    modForm:     '/admin/ia/config/modelo/form',
    modAlt:      '/admin/ia/config/modelo/alternar',
    modExcluir:  '/admin/ia/config/modelo/excluir',
    limLinhas:   '/admin/ia/config/limites/linhas',
    limForm:     '/admin/ia/config/limite/form',
    limExcluir:  '/admin/ia/config/limite/excluir'
  };

  var TBODY = { prov: '#ia_tb_prov', mod: '#ia_tb_mod', lim: '#ia_tb_lim' };
  var LINHAS = { prov: URLS.provLinhas, mod: URLS.modLinhas, lim: URLS.limLinhas };

  /* ---------- transporte ---------- */
  function iaGet(url, dados) {
    return $.ajax({ url: url, method: 'GET', data: dados || {}, dataType: 'json' });
  }
  function iaPost(url, dados) {
    var corpo = $.extend({ csrf_token: IA_CSRF }, dados || {});
    return $.ajax({ url: url, method: 'POST', data: corpo, dataType: 'json' });
  }

  /* ---------- toast ---------- */
  var $toast = null, toastTimer = null;
  function toast(msg, erro) {
    if (!$toast) { $toast = $('<div class="ia_toast" role="status"></div>').appendTo('body'); }
    $toast.text(msg).toggleClass('erro', !!erro).addClass('visivel');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { $toast.removeClass('visivel'); }, 2800);
  }

  /* ---------- drawer (usa adminDrawer do projeto; fallback interno) ---------- */
  function drawerAbrir(titulo, html) {
    
    
    if (typeof window.adminDrawer === 'function') {
      // AJUSTE: adapte a chamada à assinatura do seu adminDrawer, se diferente.
      adminDrawer({
        titulo  : titulo,
        tamanho : 'lg',
        conteudo: html,
      });
      return;
    }
    var $veu = $('#ia_drawer_veu'), $dr = $('#ia_drawer');
    if (!$dr.length) {
      $veu = $('<div class="ia_drawer_veu" id="ia_drawer_veu"></div>').appendTo('body');
      $dr = $(
        '<aside class="ia_drawer" id="ia_drawer" role="dialog" aria-modal="true">' +
          '<div class="ia_drawer_head">' +
            '<h3 class="ia_drawer_titulo" id="ia_drawer_titulo"></h3>' +
            '<button type="button" class="ia_drawer_fechar" aria-label="Fechar"><i class="bi bi-x-lg"></i></button>' +
          '</div>' +
          '<div class="ia_drawer_corpo" id="ia_drawer_corpo"></div>' +
        '</aside>'
      ).appendTo('body');
      $veu.on('click', drawerFechar);
      $dr.on('click', '.ia_drawer_fechar', drawerFechar);
      $(document).on('keydown.iaDrawer', function (e) { if (e.key === 'Escape') drawerFechar(); });
    }
    $('#ia_drawer_titulo').text(titulo || '');
    $('#ia_drawer_corpo').html(html || '');
    requestAnimationFrame(function () { $veu.addClass('aberto'); $dr.addClass('aberto'); });
  }
  function drawerFechar() {
    if (typeof window.adminDrawerClose === 'function') { window.adminDrawerClose(); return; }
    $('#ia_drawer_veu').removeClass('aberto');
    $('#ia_drawer').removeClass('aberto');
  }

  /* ---------- KPIs + linhas ---------- */
  function fmtUsd(v) {
    if (v === null || v === undefined || v === '') return '—';
    var n = parseFloat(v);
    return 'US$ ' + n.toLocaleString('pt-BR', {
      minimumFractionDigits: (n > 0 && n < 0.01) ? 4 : 2,
      maximumFractionDigits: (n > 0 && n < 0.01) ? 4 : 2
    });
  }
  function aplicarKpis(k) {
    if (!k) return;
    $('#ia_kpi_prov').text(k.provedores_ativos);
    $('#ia_kpi_prov_t').text(k.provedores_total);
    $('#ia_kpi_mod').text(k.modelos_ativos);
    $('#ia_kpi_mod_t').text(k.modelos_total);
    $('#ia_kpi_gasto').text(fmtUsd(k.gasto_hoje_usd));
    $('#ia_kpi_limite').text(fmtUsd(k.limite_diario_usd));
  }
  function recarregar(qual) {
    iaGet(LINHAS[qual]).done(function (r) {
      if (r && r.ok) { $(TBODY[qual]).html(r.html); aplicarKpis(r.kpis); }
    });
  }

  function abrirForm(url, params, tituloPadrao) {
    iaGet(url, params).done(function (r) {
      if (r && r.ok) { drawerAbrir(r.titulo || tituloPadrao, r.html); }
      else { toast((r && r.msg) || 'Erro ao carregar o formulário.', true); }
    }).fail(function () { toast('Falha de comunicação.', true); });
  }

  /* ---------- eventos: provedores ---------- */
  $(document).on('click', '.ia_ac_prov_editar', function () {
    abrirForm(URLS.provForm, { id: $(this).data('id') }, 'Editar provedor');
  });
  $(document).on('click', '.ia_ac_prov_alternar', function () {
    iaPost(URLS.provAlt, { id: $(this).data('id') }).done(function (r) {
      toast((r && r.msg) || 'Feito.', !(r && r.ok));
      if (r && r.ok) recarregar('prov');
    }).fail(function () { toast('Falha de comunicação.', true); });
  });
  $(document).on('click', '.ia_ac_prov_testar', function () {
    var $b = $(this).prop('disabled', true);
    var icone = $b.html();
    $b.html('<span class="ia_spin"></span>');
    iaPost(URLS.provTestar, { id: $b.data('id') }).done(function (r) {
      toast((r && r.msg) || 'Feito.', !(r && r.ok));
    }).fail(function () {
      toast('Falha de comunicação.', true);
    }).always(function () {
      $b.prop('disabled', false).html(icone);
    });
  });

  /* ---------- eventos: modelos ---------- */
  $(document).on('click', '#ia_btn_novo_modelo', function () {
    abrirForm(URLS.modForm, {}, 'Novo modelo');
  });
  $(document).on('click', '.ia_ac_mod_editar', function () {
    abrirForm(URLS.modForm, { id: $(this).data('id') }, 'Editar modelo');
  });
  $(document).on('click', '.ia_ac_mod_alternar', function () {
    iaPost(URLS.modAlt, { id: $(this).data('id') }).done(function (r) {
      toast((r && r.msg) || 'Feito.', !(r && r.ok));
      if (r && r.ok) recarregar('mod');
    }).fail(function () { toast('Falha de comunicação.', true); });
  });
  $(document).on('click', '.ia_ac_mod_excluir', function () {
    if (!window.confirm('Excluir este modelo do catálogo?')) return;
    iaPost(URLS.modExcluir, { id: $(this).data('id') }).done(function (r) {
      toast((r && r.msg) || 'Feito.', !(r && r.ok));
      if (r && r.ok) recarregar('mod');
    }).fail(function () { toast('Falha de comunicação.', true); });
  });

  /* ---------- eventos: limites ---------- */
  $(document).on('click', '#ia_btn_novo_limite', function () {
    abrirForm(URLS.limForm, {}, 'Novo limite');
  });
  $(document).on('click', '.ia_ac_lim_editar', function () {
    abrirForm(URLS.limForm, { id: $(this).data('id') }, 'Editar limite');
  });
  $(document).on('click', '.ia_ac_lim_excluir', function () {
    if (!window.confirm('Excluir este limite?')) return;
    iaPost(URLS.limExcluir, { id: $(this).data('id') }).done(function (r) {
      toast((r && r.msg) || 'Feito.', !(r && r.ok));
      if (r && r.ok) recarregar('lim');
    }).fail(function () { toast('Falha de comunicação.', true); });
  });

  /* ---------- envio genérico dos formulários do drawer ---------- */
  $(document).on('submit', 'form.ia_form', function (e) {
    e.preventDefault();
    var $f = $(this);
    var $btn = $f.find('button[type=submit]').prop('disabled', true);

    $.ajax({
      url: $f.data('acao'),
      method: 'POST',
      data: $f.serialize(),
      dataType: 'json'
    }).done(function (r) {
      toast((r && r.msg) || 'Feito.', !(r && r.ok));
      if (r && r.ok) { drawerFechar(); recarregar($f.data('recarregar')); }
    }).fail(function () {
      toast('Falha de comunicação.', true);
    }).always(function () {
      $btn.prop('disabled', false);
    });
  });

  /* ---------- form de limite: escopo controla campo de usuário ---------- */
  $(document).on('change', '#ia_lim_escopo', function () {
    $('#ia_lim_ref_wrap').toggle($(this).val() === 'usuario');
  });

})(jQuery);
</script>
