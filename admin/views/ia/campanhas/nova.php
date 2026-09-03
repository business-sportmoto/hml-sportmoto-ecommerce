<?php
/**
 * Central de Marketing IA — Nova campanha / continuar rascunho (Fase 3B).
 * Variáveis: $csrf, $campanha_id (0 = nova), $tipos, $layouts, $categorias, $marcas
 */
if (!function_exists('ia_e')) {
    function ia_e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
}
// O CSS do modulo vive em admin/assets/css/pages.css (bloco
// "admin/views/ia/**"), carregado pelo layout do painel. O pacote
// original injetava um _estilos.php por view; ao unificar a folha,
// esse include ficou apontando para um arquivo que nao existe.

$grupos = [];
foreach ($tipos as $t) {
    $grupos[$t['grupo']][] = $t;
}
?>
<div class="ia_pagina">

  <div class="ia_topo">
    <div>
      <h1 class="ia_titulo"><?= IconLibrary::render('stacks', 'ia_ico', ['aria-hidden' => 'true']) ?><?= $campanha_id > 0 ? 'Continuar campanha' : 'Nova campanha' ?></h1>
      <p class="ia_sub">Um briefing, vários produtos, vários formatos — o worker gera tudo no ritmo dos limites.</p>
    </div>
    <div class="ia_topo_acoes">
      <a href="/admin/ia/campanhas" class="ia_btn"><?= IconLibrary::render('arrow-back', 'ia_ico', ['aria-hidden' => 'true']) ?> Campanhas</a>
    </div>
  </div>

  <div class="ia_card ia_card_pad">
    <h2 class="ia_secao_titulo">1 · Dados e briefing</h2>
    <div class="ia_form_linha">
      <div class="ia_form_grupo">
        <label for="ia_c_nome">Nome da campanha</label>
        <input type="text" id="ia_c_nome" class="ia_input" maxlength="200" placeholder="Ex.: Black Friday Capacetes">
      </div>
      <div class="ia_form_grupo">
        <label for="ia_c_orcamento">Teto de orçamento (US$, opcional)</label>
        <input type="number" id="ia_c_orcamento" class="ia_input" min="0" step="0.01" placeholder="vazio = sem teto">
        <p class="ia_ajuda">Ao atingir, o driver pausa a campanha e avisa no sino.</p>
      </div>
    </div>
    <div class="ia_form_linha">
      <div class="ia_form_grupo">
        <label for="ia_c_objetivo">Objetivo</label>
        <input type="text" id="ia_c_objetivo" class="ia_input" placeholder="Ex.: ofertas de black friday com até 40% off">
      </div>
      <div class="ia_form_grupo">
        <label for="ia_c_publico">Público</label>
        <input type="text" id="ia_c_publico" class="ia_input" placeholder="Ex.: motociclistas urbanos">
      </div>
    </div>
    <div class="ia_form_linha">
      <div class="ia_form_grupo">
        <label for="ia_c_tom">Tom</label>
        <input type="text" id="ia_c_tom" class="ia_input" placeholder="Ex.: urgente e premium">
      </div>
      <div class="ia_form_grupo">
        <label for="ia_c_condicao">Condição especial</label>
        <input type="text" id="ia_c_condicao" class="ia_input" placeholder="Ex.: frete grátis acima de R$ 350">
      </div>
    </div>
  </div>

  <div class="ia_card ia_card_pad">
    <h2 class="ia_secao_titulo">2 · Produtos <span class="ia_celula_sub">(<span id="ia_c_qtd">0</span>/60)</span></h2>
    <div class="ia_form_linha">
      <div class="ia_form_grupo">
        <label for="ia_c_busca">Buscar produto</label>
        <input type="text" id="ia_c_busca" class="ia_input" placeholder="Nome ou código…" autocomplete="off">
        <div class="ia_busca_lista" id="ia_c_busca_lista"></div>
      </div>
      <div class="ia_form_grupo">
        <label>Atalho: adicionar em lote</label>
        <div class="ia_form_linha_compacta">
          <select id="ia_c_categoria" class="ia_input">
            <option value="">Categoria…</option>
            <?php foreach ($categorias as $cat): ?>
              <option value="<?= (int) $cat['id'] ?>"><?= ia_e($cat['nome']) ?></option>
            <?php endforeach; ?>
          </select>
          <select id="ia_c_marca" class="ia_input">
            <option value="">Marca…</option>
            <?php foreach ($marcas as $m): ?>
              <option value="<?= (int) $m['id'] ?>"><?= ia_e($m['nome']) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="button" class="ia_btn" id="ia_c_add_lote"><?= IconLibrary::render('add', 'ia_ico', ['aria-hidden' => 'true']) ?> Adicionar</button>
        </div>
        <p class="ia_ajuda">Produtos ativos da categoria/marca entram de uma vez (respeitando o teto de 60).</p>
      </div>
    </div>
    <div class="ia_chips" id="ia_c_chips"></div>
  </div>

  <div class="ia_card ia_card_pad">
    <h2 class="ia_secao_titulo">3 · Tipos de conteúdo</h2>
    <?php foreach ($grupos as $grupo => $lista): ?>
      <p class="ia_grupo_rotulo"><?= ia_e(ucfirst($grupo)) ?></p>
      <div class="ia_tipos_grid">
        <?php foreach ($lista as $t): ?>
          <div class="ia_tipo_item" data-cap="<?= ia_e($t['capacidade']) ?>">
            <label class="ia_check">
              <input type="checkbox" class="ia_c_tipo" value="<?= (int) $t['id'] ?>" data-cap="<?= ia_e($t['capacidade']) ?>">
              <?= ia_e($t['nome']) ?>
            </label>
            <?php if ($t['capacidade'] === 'composicao'): ?>
              <select class="ia_c_cfg_layout ia_input" style="display:none">
                <option value="">Layout…</option>
                <?php foreach ($layouts as $l): ?>
                  <option value="<?= ia_e($l['codigo']) ?>"><?= ia_e($l['nome']) ?></option>
                <?php endforeach; ?>
              </select>
            <?php elseif ($t['capacidade'] === 'imagem'): ?>
              <select class="ia_c_cfg_proporcao ia_input" style="display:none">
                <option value="1:1">1:1</option><option value="3:2">3:2</option><option value="2:3">2:3</option>
              </select>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="ia_card ia_card_pad">
    <h2 class="ia_secao_titulo">4 · Revisão e custo</h2>
    <button type="button" class="ia_btn" id="ia_c_estimar"><?= IconLibrary::render('calculadora', 'ia_ico', ['aria-hidden' => 'true']) ?> Calcular estimativa</button>
    <div id="ia_c_estimativa" style="margin-top:12px"></div>
    <div class="ia_resultado_acoes" style="margin-top:16px">
      <button type="button" class="ia_btn" id="ia_c_rascunho"><?= IconLibrary::render('save', 'ia_ico', ['aria-hidden' => 'true']) ?> Salvar rascunho</button>
      <button type="button" class="ia_btn ia_btn_primario" id="ia_c_iniciar"><?= IconLibrary::render('rocket-launch', 'ia_ico', ['aria-hidden' => 'true']) ?> Aprovar e gerar</button>
    </div>
  </div>

</div>

<div class="ia_toast" id="ia_toast"></div>

<script>
(function () {
  'use strict';
  var CSRF = <?= json_encode($csrf) ?>;
  var campId = <?= (int) $campanha_id ?>;
  var chips = {}; // id -> nome

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

  /* ── Chips de produtos ── */
  function renderChips() {
    var $box = $('#ia_c_chips').empty();
    Object.keys(chips).forEach(function (id) {
      var $c = $('<span class="ia_chip">');
      $c.text(chips[id] + ' ');
      $('<button type="button" class="ia_chip_x" aria-label="Remover">&times;</button>')
        .on('click', function () { delete chips[id]; renderChips(); }).appendTo($c);
      $box.append($c);
    });
    $('#ia_c_qtd').text(Object.keys(chips).length);
  }
  function addChip(id, nome) {
    if (Object.keys(chips).length >= 60 && !chips[id]) {
      toast('Teto de 60 produtos — divida em duas campanhas.', false);
      return false;
    }
    chips[id] = nome;
    renderChips();
    return true;
  }

  var buscaTimer = null;
  $('#ia_c_busca').on('input', function () {
    var q = $(this).val().trim();
    clearTimeout(buscaTimer);
    if (q.length < 2) { $('#ia_c_busca_lista').empty(); return; }
    buscaTimer = setTimeout(function () {
      $.getJSON('/admin/ia/gerar/produto-busca', { q: q }, function (r) {
        var $l = $('#ia_c_busca_lista').empty();
        (r.itens || []).forEach(function (p) {
          $('<button type="button" class="ia_busca_item">')
            .text(p.nome + ' #' + p.id + (p.marca ? ' · ' + p.marca : ''))
            .on('click', function () { addChip(p.id, p.nome); $('#ia_c_busca').val(''); $l.empty(); })
            .appendTo($l);
        });
      });
    }, 250);
  });

  $('#ia_c_add_lote').on('click', function () {
    var cat = $('#ia_c_categoria').val(), marca = $('#ia_c_marca').val();
    if (!cat && !marca) { toast('Escolha uma categoria ou marca.', false); return; }
    $.getJSON('/admin/ia/campanha/produtos-filtro', { categoria_id: cat || 0, marca_id: cat ? 0 : marca }, function (r) {
      if (!r.ok) { toast(r.msg || 'Erro.', false); return; }
      var add = 0;
      (r.itens || []).some(function (p) {
        if (!chips[p.id]) { if (!addChip(p.id, p.nome)) { return true; } add++; }
        return false;
      });
      toast(add + ' produto(s) adicionados.', true);
    });
  });

  /* ── Tipos: config aparece ao marcar ── */
  $(document).on('change', '.ia_c_tipo', function () {
    var $item = $(this).closest('.ia_tipo_item');
    $item.find('select').toggle(this.checked);
  });

  function colherTipos() {
    var itens = [];
    var erro = null;
    $('.ia_c_tipo:checked').each(function () {
      var $item = $(this).closest('.ia_tipo_item');
      var cap = $(this).data('cap');
      var cfg = {};
      if (cap === 'composicao') {
        cfg.layout = $item.find('.ia_c_cfg_layout').val() || '';
        if (!cfg.layout) { erro = 'Escolha o layout do banner marcado.'; }
      } else if (cap === 'imagem') {
        cfg.proporcao = $item.find('.ia_c_cfg_proporcao').val() || '1:1';
      }
      itens.push({ tipo_conteudo_id: parseInt(this.value, 10), config: cfg });
    });
    return { itens: itens, erro: erro };
  }

  /* ── Salvar (encadeado) ── */
  function dadosBase() {
    return {
      id: campId,
      nome: $('#ia_c_nome').val(),
      orcamento_max_usd: $('#ia_c_orcamento').val(),
      briefing_objetivo: $('#ia_c_objetivo').val(),
      briefing_publico:  $('#ia_c_publico').val(),
      briefing_tom:      $('#ia_c_tom').val(),
      briefing_condicao: $('#ia_c_condicao').val()
    };
  }

  function salvarTudo(cb) {
    var t = colherTipos();
    if ($('#ia_c_nome').val().trim() === '') { toast('Dê um nome à campanha.', false); return; }
    if (t.erro) { toast(t.erro, false); return; }

    var url = campId > 0 ? '/admin/ia/campanha/atualizar' : '/admin/ia/campanha/criar';
    iaPost(url, dadosBase(), function (r) {
      if (!r.ok) { toast(r.msg || 'Erro ao salvar.', false); return; }
      if (r.id) { campId = r.id; }
      iaPost('/admin/ia/campanha/produtos', { id: campId, produto_ids: Object.keys(chips).join(',') }, function (r2) {
        if (!r2.ok) { toast(r2.msg || 'Erro nos produtos.', false); return; }
        iaPost('/admin/ia/campanha/tipos', { id: campId, tipos: JSON.stringify(t.itens) }, function (r3) {
          if (!r3.ok) { toast(r3.msg || 'Erro nos tipos.', false); return; }
          cb();
        });
      });
    });
  }

  $('#ia_c_rascunho').on('click', function () {
    salvarTudo(function () { toast('Rascunho salvo.', true); });
  });

  $('#ia_c_iniciar').on('click', function () {
    salvarTudo(function () {
      iaPost('/admin/ia/campanha/iniciar', { id: campId }, function (r) {
        if (!r.ok) { toast(r.msg || 'Não foi possível iniciar.', false); return; }
        window.location.href = '/admin/ia/campanha?id=' + campId;
      });
    });
  });

  $('#ia_c_estimar').on('click', function () {
    salvarTudo(function () {
      $.getJSON('/admin/ia/campanha/estimativa', { id: campId }, function (r) {
        if (!r.ok) { toast(r.msg || 'Erro na estimativa.', false); return; }
        var $e = $('#ia_c_estimativa').empty();
        $('<p class="ia_camp_meta">').text(r.produtos + ' produto(s) × ' + r.tipos + ' tipo(s) = ' + r.pares +
          ' gerações · total estimado US$ ' + Number(r.total_usd).toFixed(4)).appendTo($e);
        (r.por_tipo || []).forEach(function (pt) {
          $('<p class="ia_ajuda">').text('• ' + pt.tipo + ': US$ ' + Number(pt.subtotal_usd).toFixed(4) +
            ' (unit. ' + Number(pt.unitario_usd).toFixed(4) + ')').appendTo($e);
        });
        (r.avisos || []).forEach(function (a) {
          $('<p class="ia_ajuda ia_ajuda_erro">').text('⚠ ' + a).appendTo($e);
        });
      });
    });
  });

  /* ── Hidratação de rascunho ── */
  if (campId > 0) {
    $.getJSON('/admin/ia/campanha/dados', { id: campId }, function (r) {
      if (!r.ok) { toast(r.msg || 'Erro ao carregar.', false); return; }
      var c = r.campanha, b = c.briefing || {};
      $('#ia_c_nome').val(c.nome);
      $('#ia_c_orcamento').val(c.orcamento_max_usd || '');
      $('#ia_c_objetivo').val(b.objetivo || '');
      $('#ia_c_publico').val(b.publico || '');
      $('#ia_c_tom').val(b.tom || '');
      $('#ia_c_condicao').val(b.condicao || '');
      (r.produtos || []).forEach(function (p) { chips[p.id] = p.nome; });
      renderChips();
      (r.tipos || []).forEach(function (t) {
        var $chk = $('.ia_c_tipo[value="' + t.tipo_conteudo_id + '"]');
        $chk.prop('checked', true).trigger('change');
        var $item = $chk.closest('.ia_tipo_item');
        if (t.config && t.config.layout)    { $item.find('.ia_c_cfg_layout').val(t.config.layout); }
        if (t.config && t.config.proporcao) { $item.find('.ia_c_cfg_proporcao').val(t.config.proporcao); }
      });
    });
  }
})();
</script>
