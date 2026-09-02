<?php
/**
 * Central de Marketing IA — Gerar conteúdo (Fase 1: texto).
 * Variáveis: $produto_id_inicial (int), $csrf (string)
 */
if (!function_exists('ia_e')) {
    function ia_e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
}
?>
<div class="ia_pagina">

  <div class="ia_topo">
    <div>
      <h1 class="ia_titulo"><?= IconLibrary::render('wand-stars', 'ia_ico', ['aria-hidden' => 'true']) ?>Central de Marketing IA</h1>
      <p class="ia_sub">Gere legendas, anúncios, descrições e textos de WhatsApp a partir dos dados reais do produto.</p>
    </div>
    <div class="ia_topo_acoes">
      <a href="/admin/ia/historico" class="ia_btn"><?= IconLibrary::render('history-toggle-off', 'ia_ico', ['aria-hidden' => 'true']) ?> Histórico</a>
      <a href="/admin/ia/config" class="ia_btn"><?= IconLibrary::render('settings', 'ia_ico', ['aria-hidden' => 'true']) ?> Configurações</a>
    </div>
  </div>

  <div class="ia_card">
    <p class="ia_card_titulo"><?= IconLibrary::render('search', 'ia_ico', ['aria-hidden' => 'true']) ?> Produto</p>
    <div class="ia_busca_wrap">
      <input type="text" id="ia_busca" class="ia_input" autocomplete="off"
             placeholder="Busque por nome ou ID do produto…">
      <div class="ia_busca_lista" id="ia_busca_lista"></div>
    </div>
    <p class="ia_ajuda">Dica: abra esta tela já com o produto pela URL — <span class="ia_mono">/admin/ia/gerar?produto_id=123</span>.</p>
  </div>

  <div id="ia_painel"></div>

  <div id="ia_resultados"></div>

</div>

<div class="ia_veu" id="ia_veu"></div>
<div class="ia_toast" id="ia_toast"></div>

<script>
jQuery(function ($) {
  'use strict';

  var IA_CSRF = '<?= ia_e($csrf ?? '') ?>';

  // Ícones para o HTML montado em JS. O IconLibrary só existe no servidor,
  // então os SVGs vêm prontos daqui — json_encode escapa aspas e barras, o
  // que um literal de string montado à mão não garantiria.
  var IA_ICO = <?= json_encode([
      'ok'       => IconLibrary::render('check-circle', 'ia_ico', ['aria-hidden' => 'true']),
      'erro'     => IconLibrary::render('x-circle',     'ia_ico', ['aria-hidden' => 'true']),
      'baixar'   => IconLibrary::render('cloud-download','ia_ico', ['aria-hidden' => 'true']),
      'aprovar'  => IconLibrary::render('check-circle', 'ia_ico', ['aria-hidden' => 'true']),
      'reprovar' => IconLibrary::render('x-circle',     'ia_ico', ['aria-hidden' => 'true']),
      'refazer'  => IconLibrary::render('reload',       'ia_ico', ['aria-hidden' => 'true']),
      'copiar'   => IconLibrary::render('copy',         'ia_ico', ['aria-hidden' => 'true']),
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

  var URLS = {
    busca:      '/admin/ia/gerar/produto-busca',
    painel:     '/admin/ia/gerar/produto-painel',
    preview:    '/admin/ia/gerar/preview',
    enfileirar: '/admin/ia/gerar/enfileirar',
    recorte:    '/admin/ia/recorte/gerar',
    status:     '/admin/ia/gerar/status',
    aprovacao:  '/admin/ia/historico/aprovacao',
    refazer:    '/admin/ia/historico/refazer'
  };

  var pendentes = [];   // uuids em polling
  var pollTimer = null;

  /* ------------------------------------------------------------ */
  /* Utilidades                                                     */
  /* ------------------------------------------------------------ */

  function esc(t) {
    return $('<div>').text(t == null ? '' : String(t)).html();
  }

  function toast(msg, ok) {
    var $t = $('#ia_toast');
    $t.text(msg).toggleClass('erro', ok === false).addClass('mostrar');
    clearTimeout($t.data('tm'));
    $t.data('tm', setTimeout(function () { $t.removeClass('mostrar'); }, 3200));
  }

  function iaPost(url, dados, cb) {
    dados = $.extend({ _csrf_token: IA_CSRF }, dados || {});
    $.post(url, dados, null, 'json')
      .done(function (r) { cb(r || { ok: false, msg: 'Resposta inválida.' }); })
      .fail(function () { cb({ ok: false, msg: 'Falha de comunicação com o servidor.' }); });
  }

  function usd(v) {
    if (v === null || v === undefined || v === '') { return '—'; }
    return 'US$ ' + Number(v).toFixed(4).replace('.', ',');
  }

  /* ------------------------------------------------------------ */
  /* Busca de produto                                               */
  /* ------------------------------------------------------------ */

  var buscaTimer = null;

  $('#ia_busca').on('input', function () {
    var q = String($(this).val()).trim();
    clearTimeout(buscaTimer);
    if (q.length < 2) { $('#ia_busca_lista').hide().empty(); return; }
    buscaTimer = setTimeout(function () {
      $.getJSON(URLS.busca, { q: q }, function (r) {
        var $l = $('#ia_busca_lista').empty();
        if (!r.ok || !r.itens || !r.itens.length) {
          $l.append('<div class="ia_busca_item"><span>Nenhum produto encontrado.</span></div>').show();
          return;
        }
        r.itens.forEach(function (p) {
          var preco = p.preco_promo ? p.preco_promo : p.preco;
          $l.append(
            '<div class="ia_busca_item" data-id="' + p.id + '">' +
              '<span><strong>' + esc(p.nome) + '</strong>' +
                (p.marca ? ' <small>· ' + esc(p.marca) + '</small>' : '') + '</span>' +
              '<small>#' + p.id + ' · R$ ' + Number(preco).toFixed(2).replace('.', ',') + '</small>' +
            '</div>'
          );
        });
        $l.show();
      });
    }, 300);
  });

  $(document).on('click', '.ia_busca_item[data-id]', function () {
    var id = $(this).data('id');
    $('#ia_busca_lista').hide().empty();
    $('#ia_busca').val('');
    carregarPainel(id);
  });

  // Capacidade do tipo escolhido comanda o formulário: imagem troca
  // ângulo por proporção (delegado — o painel chega via AJAX).
  $(document).on('change', '#ia_g_tipo', function () {
    var cap = $(this).find('option:selected').data('cap') || 'texto';
    var ehImagem = (cap === 'imagem');
    $('#ia_g_angulo_wrap').toggle(!ehImagem);
    $('#ia_g_proporcao_wrap').toggle(ehImagem);
    if (ehImagem) { $('#ia_g_angulo').val(''); }
  });

  // Remoção de fundo da foto do produto (cache-first): reusa o card + polling.
  $(document).on('click', '#ia_btn_recorte', function () {
    var $b  = $(this).prop('disabled', true);
    var pid = $('#ia_form_gerar [name=produto_id]').val();

    iaPost(URLS.recorte, { produto_id: pid }, function (r) {
      $b.prop('disabled', false);
      if (!r.ok) { toast(r.msg || 'Erro ao solicitar o recorte.', false); return; }
      toast(r.msg || (r.cache ? 'Recorte em cache — custo zero.' : 'Remoção de fundo enfileirada.'), true);
      (r.uuids || []).forEach(function (u) {
        criarCardPendente(u);
        if (pendentes.indexOf(u) === -1) { pendentes.push(u); }
      });
      if ((r.uuids || []).length) { iniciarPolling(); }
    });
  });

  $(document).on('click', function (e) {
    if (!$(e.target).closest('.ia_busca_wrap').length) { $('#ia_busca_lista').hide(); }
  });

  function carregarPainel(produtoId) {
    $('#ia_painel').html('<div class="ia_card"><span class="ia_spin"></span> Carregando produto…</div>');
    $.getJSON(URLS.painel, { produto_id: produtoId }, function (r) {
      if (!r.ok) { $('#ia_painel').empty(); toast(r.msg || 'Erro ao carregar o produto.', false); return; }
      $('#ia_painel').html(r.html);
    }).fail(function () {
      $('#ia_painel').empty();
      toast('Falha ao carregar o painel do produto.', false);
    });
  }

  /* ------------------------------------------------------------ */
  /* Preview e geração                                              */
  /* ------------------------------------------------------------ */

  $(document).on('click', '#ia_btn_preview', function () {
    var $f = $('#ia_form_gerar');
    if (!$f.length) { return; }
    var $btn = $(this).prop('disabled', true);
    iaPost(URLS.preview, $f.serializeArray().reduce(function (o, c) { o[c.name] = c.value; return o; }, {}), function (r) {
      $btn.prop('disabled', false);
      if (!r.ok) { toast(r.msg || 'Erro ao montar o prompt.', false); return; }
      $f.find('[name=prompt_custom]').val(r.prompt);
      toast('Prompt montado — revise e ajuste à vontade antes de gerar.', true);
    });
  });

  $(document).on('submit', '#ia_form_gerar', function (e) {
    e.preventDefault();
    var $f = $(this);
    var $btn = $f.find('button[type=submit]').prop('disabled', true);

    iaPost(URLS.enfileirar, $f.serializeArray().reduce(function (o, c) { o[c.name] = c.value; return o; }, {}), function (r) {
      $btn.prop('disabled', false);
      if (!r.ok) { toast(r.msg || 'Erro ao enfileirar.', false); return; }
      toast((r.msg || 'Enfileirado.') + ' Custo estimado: ' + usd(r.custo_estimado_usd), true);
      (r.uuids || []).forEach(function (u) {
        criarCardPendente(u);
        if (pendentes.indexOf(u) === -1) { pendentes.push(u); }
      });
      iniciarPolling();
    });
  });

  /* ------------------------------------------------------------ */
  /* Cards de resultado + polling                                   */
  /* ------------------------------------------------------------ */

  function criarCardPendente(uuid) {
    if ($('#ia_res_' + uuid).length) { return; }
    $('#ia_resultados').prepend(
      '<div class="ia_resultado" id="ia_res_' + uuid + '" data-uuid="' + uuid + '">' +
        '<div class="ia_resultado_topo">' +
          '<span class="ia_pill ia_pill_azul"><span class="ia_spin"></span> Na fila</span>' +
          '<span class="ia_resultado_meta ia_meta"></span>' +
        '</div>' +
        '<div class="ia_corpo"></div>' +
      '</div>'
    );
  }

  function iniciarPolling() {
    if (pollTimer || !pendentes.length) { return; }
    pollTimer = setInterval(consultarStatus, 2500);
    consultarStatus();
  }

  function pararPollingSeVazio() {
    if (!pendentes.length && pollTimer) {
      clearInterval(pollTimer);
      pollTimer = null;
    }
  }

  function consultarStatus() {
    if (!pendentes.length) { pararPollingSeVazio(); return; }
    $.getJSON(URLS.status, { uuids: pendentes.join(',') }, function (r) {
      if (!r.ok || !r.itens) { return; }
      r.itens.forEach(atualizarCard);
    });
  }

  function atualizarCard(g) {
    var $c = $('#ia_res_' + g.uuid);
    if (!$c.length) { criarCardPendente(g.uuid); $c = $('#ia_res_' + g.uuid); }

    $c.data('id', g.id);
    var meta = [];
    if (g.tipo_nome) { meta.push(esc(g.tipo_nome)); }
    if (g.angulo) { meta.push('ângulo: ' + esc(g.angulo)); }
    if (g.modelo_codigo) { meta.push('<span class="ia_mono">' + esc(g.modelo_codigo) + '</span>'); }
    if (g.tempo_ms) { meta.push((g.tempo_ms / 1000).toFixed(1).replace('.', ',') + 's'); }
    if (g.custo_real_usd !== null && g.custo_real_usd !== undefined) { meta.push(usd(g.custo_real_usd)); }
    $c.find('.ia_meta').html(meta.join(' · '));

    var $pill = $c.find('.ia_pill').first();
    var $corpo = $c.find('.ia_corpo');

    if (g.status === 'na_fila') {
      $pill.attr('class', 'ia_pill ia_pill_azul').html('<span class="ia_spin"></span> Na fila');
    } else if (g.status === 'processando' || g.status === 'aguardando_provedor') {
      $pill.attr('class', 'ia_pill ia_pill_azul').html('<span class="ia_spin"></span> Gerando…');
    } else if (g.status === 'concluida') {
      $pill.attr('class', 'ia_pill ia_pill_ok').html(IA_ICO.ok + ' Concluída');
      if (!$corpo.data('pronto')) {
        if (g.capacidade === 'imagem' && g.arquivo_id) {
          $corpo.data('pronto', 1).html(
            '<div class="ia_resultado_img"><img alt="Imagem gerada" loading="lazy"></div>' +
            '<div class="ia_resultado_acoes">' +
              '<a class="ia_btn ia_ac_baixar" target="_blank" rel="noopener">' + IA_ICO.baixar + ' Baixar</a>' +
              '<button type="button" class="ia_btn ia_ac_aprovar">' + IA_ICO.aprovar + ' Aprovar</button>' +
              '<button type="button" class="ia_btn ia_ac_reprovar">' + IA_ICO.reprovar + ' Reprovar</button>' +
              '<button type="button" class="ia_btn ia_ac_refazer">' + IA_ICO.refazer + ' Refazer</button>' +
            '</div>'
          );
          $corpo.find('img').attr('src', '/admin/ia/arquivo?id=' + g.arquivo_id);
          $corpo.find('.ia_ac_baixar').attr('href', '/admin/ia/arquivo?id=' + g.arquivo_id + '&download=1');
        } else {
          $corpo.data('pronto', 1).html(
            '<div class="ia_resultado_texto"></div>' +
            '<div class="ia_resultado_acoes">' +
              '<button type="button" class="ia_btn ia_ac_copiar">' + IA_ICO.copiar + ' Copiar</button>' +
              '<button type="button" class="ia_btn ia_ac_aprovar">' + IA_ICO.aprovar + ' Aprovar</button>' +
              '<button type="button" class="ia_btn ia_ac_reprovar">' + IA_ICO.reprovar + ' Reprovar</button>' +
              '<button type="button" class="ia_btn ia_ac_refazer">' + IA_ICO.refazer + ' Refazer</button>' +
            '</div>'
          );
          $corpo.find('.ia_resultado_texto').text(g.resultado_texto || '');
        }
      }
      removerPendente(g.uuid);
    } else if (g.status === 'falhou' || g.status === 'cancelada') {
      $pill.attr('class', 'ia_pill ia_pill_erro').html(IA_ICO.erro + ' ' + (g.status === 'falhou' ? 'Falhou' : 'Cancelada'));
      if (!$corpo.data('pronto')) {
        $corpo.data('pronto', 1).html(
          '<div class="ia_resultado_erro"></div>' +
          '<div class="ia_resultado_acoes">' +
            '<button type="button" class="ia_btn ia_ac_refazer">' + IA_ICO.refazer + ' Tentar novamente</button>' +
          '</div>'
        );
        $corpo.find('.ia_resultado_erro').text(g.erro || 'Erro não informado.');
      }
      removerPendente(g.uuid);
    }

    if (g.aprovacao === 'aprovado') { $c.css('border-color', 'var(--em-ok)'); }
    if (g.aprovacao === 'reprovado') { $c.css('border-color', 'var(--em-erro)'); }
  }

  function removerPendente(uuid) {
    var i = pendentes.indexOf(uuid);
    if (i !== -1) { pendentes.splice(i, 1); }
    pararPollingSeVazio();
  }

  /* ------------------------------------------------------------ */
  /* Ações dos cards                                                */
  /* ------------------------------------------------------------ */

  $(document).on('click', '.ia_ac_copiar', function () {
    var texto = $(this).closest('.ia_resultado').find('.ia_resultado_texto').text();
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(texto).then(function () { toast('Copiado para a área de transferência.', true); });
    } else {
      var $ta = $('<textarea>').val(texto).appendTo('body').select();
      document.execCommand('copy');
      $ta.remove();
      toast('Copiado para a área de transferência.', true);
    }
  });

  $(document).on('click', '.ia_ac_aprovar, .ia_ac_reprovar', function () {
    var $b = $(this);
    var $card = $b.closest('.ia_resultado');
    var acao = $b.hasClass('ia_ac_aprovar') ? 'aprovado' : 'reprovado';
    iaPost(URLS.aprovacao, { id: $card.data('id'), acao: acao }, function (r) {
      toast(r.msg || (r.ok ? 'Curadoria atualizada.' : 'Erro.'), r.ok);
      if (r.ok) {
        $card.css('border-color', acao === 'aprovado' ? 'var(--em-ok)' : 'var(--em-erro)');
      }
    });
  });

  $(document).on('click', '.ia_ac_refazer', function () {
    var $b = $(this).prop('disabled', true);
    var $card = $b.closest('.ia_resultado');
    iaPost(URLS.refazer, { id: $card.data('id') }, function (r) {
      $b.prop('disabled', false);
      if (!r.ok) { toast(r.msg || 'Erro ao refazer.', false); return; }
      toast('Nova geração enfileirada.', true);
      (r.uuids || []).forEach(function (u) {
        criarCardPendente(u);
        if (pendentes.indexOf(u) === -1) { pendentes.push(u); }
      });
      iniciarPolling();
    });
  });

  /* ------------------------------------------------------------ */
  /* Boot                                                           */
  /* ------------------------------------------------------------ */

  var produtoInicial = <?= (int) ($produto_id_inicial ?? 0) ?>;
  if (produtoInicial > 0) { carregarPainel(produtoInicial); }
});
</script>
