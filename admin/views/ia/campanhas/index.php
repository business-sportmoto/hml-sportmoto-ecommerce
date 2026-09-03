<?php
/**
 * Central de Marketing IA — Campanhas (Fase 3B): lista em cards.
 * Variáveis: $csrf (string), $erro (?string)
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
      <h1 class="ia_titulo"><?= IconLibrary::render('stacks', 'ia_ico', ['aria-hidden' => 'true']) ?>Campanhas em lote</h1>
      <p class="ia_sub">Produtos × tipos de conteúdo, gerados no ritmo dos seus limites — com orçamento, progresso e curadoria em massa.</p>
    </div>
    <div class="ia_topo_acoes">
      <a href="/admin/ia/gerar" class="ia_btn"><?= IconLibrary::render('wand-stars', 'ia_ico', ['aria-hidden' => 'true']) ?> Gerar avulso</a>
      <a href="/admin/ia/campanhas/nova" class="ia_btn ia_btn_primario"><?= IconLibrary::render('plus', 'ia_ico', ['aria-hidden' => 'true']) ?> Nova campanha</a>
    </div>
  </div>

  <?php if (!empty($erro)): ?>
    <p class="ia_ajuda ia_ajuda_erro"><?= ia_e($erro) ?></p>
  <?php endif; ?>

  <div class="ia_camp_cards" id="ia_camp_cards">
    <p class="ia_ajuda" id="ia_camp_vazio">Carregando campanhas…</p>
  </div>

</div>

<div class="ia_toast" id="ia_toast"></div>

<script>
(function () {
  'use strict';
  var CSRF = <?= json_encode($csrf) ?>;

  // Icones do HTML montado em JS: o IconLibrary so existe no servidor.
  var IA_ICO = <?= json_encode([
      'continuar' => IconLibrary::render('pencil',        'ia_ico', ['aria-hidden' => 'true']),
      'abrir'     => IconLibrary::render('grid',          'ia_ico', ['aria-hidden' => 'true']),
      'rascunho'  => IconLibrary::render('pencil',        'ia_ico', ['aria-hidden' => 'true']),
      'pausada'   => IconLibrary::render('sync-disabled', 'ia_ico', ['aria-hidden' => 'true']),
      'concluida' => IconLibrary::render('check-circle',  'ia_ico', ['aria-hidden' => 'true']),
      'cancelada' => IconLibrary::render('cancel',        'ia_ico', ['aria-hidden' => 'true']),
      'arquivada' => IconLibrary::render('inbox',         'ia_ico', ['aria-hidden' => 'true']),
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

  var PILL = {
    rascunho:  ['ia_pill_neutra', 'rascunho',  'Rascunho'],
    gerando:   ['ia_pill_azul',   '',          'Gerando'],
    pausada:   ['ia_pill_neutra', 'pausada',   'Pausada'],
    concluida: ['ia_pill_ok',     'concluida', 'Concluída'],
    cancelada: ['ia_pill_erro',   'cancelada', 'Cancelada'],
    arquivada: ['ia_pill_neutra', 'arquivada', 'Arquivada']
  };

  function toast(msg, ok) {
    var $t = $('#ia_toast');
    $t.text(msg).toggleClass('erro', !ok).addClass('aberto');
    setTimeout(function () { $t.removeClass('aberto'); }, 3200);
  }

  function moeda(v) { return 'US$ ' + (Number(v) || 0).toFixed(4).replace('.', ','); }

  function card(c) {
    var pares = (Number(c.n_produtos) || 0) * (Number(c.n_tipos) || 0);
    var feitas = (Number(c.n_concluidas) || 0) + (Number(c.n_falhas) || 0);
    var pct = pares > 0 ? Math.round(100 * feitas / pares) : 0;
    var p = PILL[c.status] || PILL.rascunho;

    var $c = $('<div class="ia_camp_card">');
    var $topo = $('<div class="ia_camp_topo">');
    $('<p class="ia_camp_nome">').text(c.nome).appendTo($topo);
    var $pill = $('<span class="ia_pill ' + p[0] + '">');
    if (c.status === 'gerando') { $pill.append('<span class="ia_spin"></span> '); }
    else if (p[1] && IA_ICO[p[1]]) { $pill.append(IA_ICO[p[1]] + ' '); }
    $pill.append(document.createTextNode(p[2]));
    $pill.appendTo($topo);
    $topo.appendTo($c);

    $('<p class="ia_camp_meta">').text(
      c.n_produtos + ' produto(s) × ' + c.n_tipos + ' tipo(s) = ' + pares + ' pares · ' +
      c.n_concluidas + ' ok' + (Number(c.n_falhas) > 0 ? ' · ' + c.n_falhas + ' falha(s)' : '') +
      ' · ' + moeda(c.custo_real) +
      (c.orcamento_max_usd ? ' / teto ' + moeda(c.orcamento_max_usd) : '')
    ).appendTo($c);

    $('<div class="ia_camp_prog"><div class="ia_camp_prog_fill" style="width:' + pct + '%"></div></div>').appendTo($c);

    var $ac = $('<div class="ia_camp_acoes">');
    if (c.status === 'rascunho') {
      $('<a class="ia_btn ia_btn_primario">').attr('href', '/admin/ia/campanhas/nova?id=' + c.id)
        .html(IA_ICO.continuar + ' Continuar').appendTo($ac);
    } else {
      $('<a class="ia_btn ia_btn_primario">').attr('href', '/admin/ia/campanha?id=' + c.id)
        .html(IA_ICO.abrir + ' Abrir').appendTo($ac);
    }
    $ac.appendTo($c);
    return $c;
  }

  function carregar() {
    $.getJSON('/admin/ia/campanhas/listar', function (r) {
      if (!r || !r.ok) { return; }
      var $box = $('#ia_camp_cards').empty();
      if (!r.itens.length) {
        $box.append($('<p class="ia_ajuda">').text('Nenhuma campanha ainda — crie a primeira.'));
        return;
      }
      r.itens.forEach(function (c) { $box.append(card(c)); });
    });
  }

  carregar();
  setInterval(function () { if (!document.hidden) { carregar(); } }, 20000);
})();
</script>
