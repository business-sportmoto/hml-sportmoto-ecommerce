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
      window.adminDrawer({
        'titulo': titulo,
        'conteudo': html
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
