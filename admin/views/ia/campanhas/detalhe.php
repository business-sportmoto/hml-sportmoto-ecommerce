<?php
/**
 * Central de Marketing IA — Detalhe da campanha (Fase 3B).
 * Variáveis: $csrf, $campanha_id
 */
if (!function_exists('ia_e')) {
    function ia_e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
}
// O CSS do modulo vive em admin/assets/css/pages.css (bloco
// "admin/views/ia/**"), carregado pelo layout do painel. O pacote
// original injetava um _estilos.php por view; ao unificar a folha,
// esse include ficou apontando para um arquivo que nao existe.
?>
<div class="ia_pagina">

  <div class="ia_topo">
    <div>
      <h1 class="ia_titulo"><?= IconLibrary::render('grid', 'ia_ico', ['aria-hidden' => 'true']) ?><span id="ia_d_nome">Campanha</span>
        <span class="ia_pill ia_pill_neutra" id="ia_d_pill">…</span></h1>
      <p class="ia_sub" id="ia_d_meta">—</p>
    </div>
    <div class="ia_topo_acoes" id="ia_d_acoes">
      <a href="/admin/ia/campanhas" class="ia_btn"><?= IconLibrary::render('arrow-back', 'ia_ico', ['aria-hidden' => 'true']) ?> Campanhas</a>
    </div>
  </div>

  <div class="ia_card">
    <div id="ia_d_grade"><p class="ia_ajuda">Carregando grade…</p></div>
  </div>

</div>

<div class="ia_veu" id="ia_veu" style="display:none"></div>
<div class="ia_drawer" id="ia_drawer">
  <div class="ia_drawer_topo">
    <p class="ia_drawer_titulo" id="ia_drawer_titulo">Detalhe</p>
    <button type="button" class="ia_btn ia_btn_icone" id="ia_drawer_fechar"><?= IconLibrary::render('close', 'ia_ico', ['aria-hidden' => 'true']) ?></button>
  </div>
  <div class="ia_drawer_corpo" id="ia_drawer_corpo"></div>
</div>
<div class="ia_toast" id="ia_toast"></div>

<script>
(function () {
  'use strict';
  var CSRF = <?= json_encode($csrf) ?>;

  // Icones do HTML montado em JS: o IconLibrary so existe no servidor.
  // Icones do HTML montado em JS. Chaves SEMANTICAS (nao classes de fonte):
  // a biblioteca nao tem "pause", entao pausa usa sync-disabled, que comunica
  // "parado" melhor que qualquer alternativa disponivel.
  var IA_ICO = <?= json_encode([
      'publicado' => IconLibrary::render('check',         'ia_ico', ['aria-hidden' => 'true']),
      'rascunho'  => IconLibrary::render('pencil',        'ia_ico', ['aria-hidden' => 'true']),
      'pausada'   => IconLibrary::render('sync-disabled', 'ia_ico', ['aria-hidden' => 'true']),
      'concluida' => IconLibrary::render('check-circle',  'ia_ico', ['aria-hidden' => 'true']),
      'cancelada' => IconLibrary::render('cancel',        'ia_ico', ['aria-hidden' => 'true']),
      'arquivada' => IconLibrary::render('inbox',         'ia_ico', ['aria-hidden' => 'true']),
      'iniciar'   => IconLibrary::render('play-arrow',    'ia_ico', ['aria-hidden' => 'true']),
      'refazer'   => IconLibrary::render('reload',        'ia_ico', ['aria-hidden' => 'true']),
      'aprovar'   => IconLibrary::render('shield-check',  'ia_ico', ['aria-hidden' => 'true']),
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  var campId = <?= (int) $campanha_id ?>;
  var statusAtual = '';
  var drawerRef = null;

  var PILL = {
    rascunho:  ['ia_pill_neutra', 'Rascunho'],
    gerando:   ['ia_pill_azul',   'Gerando'],
    pausada:   ['ia_pill_neutra', 'Pausada'],
    concluida: ['ia_pill_ok',     'Concluída'],
    cancelada: ['ia_pill_erro',   'Cancelada'],
    arquivada: ['ia_pill_neutra', 'Arquivada']
  };

  function toast(msg, ok) {
    var $t = $('#ia_toast');
    $t.text(msg).toggleClass('erro', !ok).addClass('aberto');
    setTimeout(function () { $t.removeClass('aberto'); }, 3200);
  }
  function iaPost(url, dados, cb) {
    dados = dados || {};
    dados._csrf_token = CSRF;
    $.post(url, dados, cb, 'json').fail(function () { toast('Falha de comunicação — tente de novo.', false); });
  }
  function moeda(v) { return 'US$ ' + (Number(v) || 0).toFixed(4); }

  /* ── Drawer: componente global do projeto, com fallback local ── */
  function drawerAbrir(titulo, html) {
    if (typeof window.adminDrawer === 'function') {
      drawerRef = window.adminDrawer({ titulo: titulo, conteudo: html, tamanho: 'lg' });
      return;
    }
    $('#ia_drawer_titulo').text(titulo);
    $('#ia_drawer_corpo').html(html);
    $('#ia_veu').show();
    $('#ia_drawer').addClass('aberto');
  }
  function drawerFechar() {
    if (drawerRef && typeof drawerRef.fechar === 'function') {
      drawerRef.fechar();
      drawerRef = null;
      return;
    }
    $('#ia_drawer').removeClass('aberto');
    $('#ia_veu').hide();
  }
  $('#ia_drawer_fechar').on('click', drawerFechar);
  $('#ia_veu').on('click', drawerFechar);
  $(document).on('keydown', function (e) { if (e.key === 'Escape') { drawerFechar(); } });

  /* ── Ações contextuais do topo ── */
  // O SVG entra como HTML; o rotulo, como TEXTO — assim um rotulo com
  // contador nunca vira markup por acidente.
  function botao(rotulo, icone, primario, fn) {
    var $b = $('<button type="button" class="ia_btn' + (primario ? ' ia_btn_primario' : '') + '">');
    $b.html(icone || '').append(document.createTextNode(' ' + rotulo));
    return $b.on('click', fn);
  }
  function acaoSimples(url, confirmar) {
    return function () {
      if (confirmar && !window.confirm(confirmar)) { return; }
      iaPost(url, { id: campId }, function (r) {
        toast(r.msg || (r.ok ? 'Feito.' : 'Erro.'), !!r.ok);
        if (r.ok) { carregar(); }
      });
    };
  }

  function montarAcoes(c, cont) {
    var $a = $('#ia_d_acoes');
    $a.find('button').remove();
    if (c.status === 'gerando') {
      $a.append(botao('Pausar', IA_ICO.pausada, false, acaoSimples('/admin/ia/campanha/pausar')));
    }
    if (c.status === 'pausada' || c.status === 'rascunho') {
      $a.append(botao(c.status === 'rascunho' ? 'Iniciar' : 'Retomar', IA_ICO.iniciar, true, acaoSimples('/admin/ia/campanha/' + (c.status === 'rascunho' ? 'iniciar' : 'retomar'))));
      $a.append(botao('Cancelar', IA_ICO.cancelada, false, acaoSimples('/admin/ia/campanha/cancelar', 'Cancelar a campanha? Gerações na fila serão canceladas.')));
    }
    if (cont.falhas > 0 && (c.status === 'concluida' || c.status === 'pausada' || c.status === 'gerando')) {
      $a.append(botao('Refazer falhas (' + cont.falhas + ')', IA_ICO.refazer, false, acaoSimples('/admin/ia/campanha/refazer-falhas')));
    }
    if (cont.concluidas > 0) {
      $a.append(botao('Aprovar concluídas', IA_ICO.aprovar, false, acaoSimples('/admin/ia/campanha/aprovar-concluidas')));
    }
    if (c.status === 'concluida' || c.status === 'cancelada') {
      $a.append(botao('Arquivar', IA_ICO.arquivada, false, acaoSimples('/admin/ia/campanha/arquivar')));
    }
  }

  /* ── Carga + polling adaptativo ── */
  function carregar() {
    $.getJSON('/admin/ia/campanha/dados', { id: campId }, function (r) {
      if (!r.ok) { toast(r.msg || 'Erro.', false); return; }
      var c = r.campanha, cont = r.contadores;
      statusAtual = c.status;

      $('#ia_d_nome').text(c.nome);
      var p = PILL[c.status] || PILL.rascunho;
      var $pill = $('#ia_d_pill').attr('class', 'ia_pill ' + p[0]).empty();
      if (c.status === 'gerando') { $pill.append('<span class="ia_spin"></span> '); }
      $pill.append(document.createTextNode(p[1]));

      $('#ia_d_meta').text(
        cont.pares + ' pares · ' + cont.concluidas + ' concluída(s) · ' +
        cont.falhas + ' falha(s) · ' + cont.em_voo + ' em voo · ' + moeda(cont.custo_real) +
        (c.orcamento_max_usd ? ' / teto ' + moeda(c.orcamento_max_usd) : '')
      );

      montarAcoes(c, cont);
    });

    $.getJSON('/admin/ia/campanha/grade', { id: campId }, function (r) {
      if (r && r.ok) { $('#ia_d_grade').html(r.html); }
    });
  }

  carregar();
  setInterval(function () {
    if (document.hidden) { return; }
    carregar();
  }, 10000);

  /* ── Célula da grade → drawer da geração (reusa o parcial do histórico) ── */
  $(document).on('click', '.ia_grade_celula', function () {
    var gid = $(this).data('gid');
    $.get('/admin/ia/historico/detalhe', { id: gid }, function (r) {
      if (r && r.ok) { drawerAbrir('Geração #' + gid, r.html); }
      else { toast((r && r.msg) || 'Erro ao abrir.', false); }
    }, 'json');
  });

  /* ── Handlers das ações do drawer (mesmas classes do histórico) ── */
  $(document).on('click', '.ia_ac_curar', function () {
    var $b = $(this).prop('disabled', true);
    iaPost('/admin/ia/historico/aprovacao', { id: $b.data('id'), acao: $b.data('acao') }, function (r) {
      $b.prop('disabled', false);
      toast(r.msg || (r.ok ? 'Curadoria atualizada.' : 'Erro.'), !!r.ok);
      if (r.ok) { drawerFechar(); carregar(); }
    });
  });
  $(document).on('click', '.ia_ac_refazer_hist', function () {
    var $b = $(this).prop('disabled', true);
    iaPost('/admin/ia/historico/refazer', { id: $b.data('id') }, function (r) {
      $b.prop('disabled', false);
      toast(r.ok ? 'Nova geração enfileirada.' : (r.msg || 'Erro ao refazer.'), !!r.ok);
      if (r.ok) { drawerFechar(); carregar(); }
    });
  });
  $(document).on('click', '.ia_ac_publicar_hist', function () {
    var $b = $(this).prop('disabled', true);
    iaPost('/admin/ia/banner/publicar', { geracao_id: $b.data('id') }, function (r) {
      toast(r.msg || (r.ok ? 'Banner criado.' : 'Erro ao publicar.'), !!r.ok);
      if (r.ok) { $b.html(IA_ICO.publicado + ' Publicado'); } else { $b.prop('disabled', false); }
    });
  });
  $(document).on('click', '.ia_ac_copiar_det', function () {
    var texto = $('#ia_det_texto').text();
    if (navigator.clipboard && texto) { navigator.clipboard.writeText(texto); toast('Copiado.', true); }
  });
})();
</script>
