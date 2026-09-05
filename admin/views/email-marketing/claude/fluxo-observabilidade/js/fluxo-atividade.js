/**
 * public/js/fluxo-atividade.js
 *
 * Timeline de atividade das automações. Polling adaptativo no padrão do
 * projeto: aba ativa 15s, background pausado, refresh ao voltar.
 */
(function ($) {
  'use strict';

  var BASE = window.BASE_URL || '';
  var cursorProximo = 0;   // id do último item carregado (paginação)
  var maisAntigo = false;  // true quando o botão "carregar mais" foi usado
  var timer = null;

  // Como cada tipo de linha aparece: [ícone bi-*, classe de cor, rótulo]
  var APRESENTACAO = {
    '__inicio':         ['bi-play-circle',        'fxa_ini',   'Jornada iniciada'],
    '__fim:concluido':  ['bi-check-circle',       'fxa_ok',    'Jornada concluída'],
    '__fim:saiu':       ['bi-box-arrow-right',    'fxa_neutro','Saiu (exit condition)'],
    '__fim:erro':       ['bi-x-circle',           'fxa_erro',  'Jornada com erro'],
    'acao_email':       ['bi-envelope',           'fxa_acao',  'Email'],
    'acao_whatsapp':    ['bi-whatsapp',           'fxa_acao',  'WhatsApp'],
    'acao_notificacao': ['bi-bell',               'fxa_acao',  'Notificação'],
    'acao_tag':         ['bi-tags',               'fxa_acao',  'Tag'],
    'acao_cupom':       ['bi-ticket-perforated',  'fxa_acao',  'Cupom gerado'],
    'acao_webhook':     ['bi-hdd-network',        'fxa_acao',  'Webhook'],
    'acao_notificar_vendedor': ['bi-megaphone',   'fxa_acao',  'Vendedor avisado'],
    'esperar':          ['bi-hourglass-split',    'fxa_neutro','Esperar'],
    'esperar_evento':   ['bi-hourglass-bottom',   'fxa_neutro','Esperar evento'],
    'split_ab':         ['bi-signpost-split',     'fxa_neutro','Split A/B'],
    'encerrar':         ['bi-stop-circle',        'fxa_neutro','Encerrar']
  };

  function apresentar(item) {
    var chaveFim = item.no_chave === '__fim' ? '__fim:' + item.porta : null;
    var ap = APRESENTACAO[chaveFim] || APRESENTACAO[item.no_chave] ||
             APRESENTACAO[item.tipo_no] || null;
    if (ap) return { icone: ap[0], classe: ap[1], rotulo: ap[2] };

    // Condições e triggers genéricos
    if (item.tipo_no.indexOf('cond_') === 0) {
      return { icone: 'bi-question-diamond', classe: 'fxa_cond', rotulo: 'Condição' };
    }
    if (item.tipo_no.indexOf('trigger') === 0) {
      return { icone: 'bi-lightning-charge', classe: 'fxa_ini', rotulo: 'Trigger' };
    }
    return { icone: 'bi-arrow-right-circle', classe: 'fxa_neutro', rotulo: item.tipo_no };
  }

  function descrever(item) {
    var partes = [];
    if (item.no_chave === '__inicio') {
      partes.push('via ' + (item.tipo_no || 'trigger'));
    } else if (item.no_chave === '__fim') {
      if (item.porta === 'erro' && item.detalhe) partes.push(item.detalhe);
    } else {
      partes.push('nó "' + item.no_chave + '"');
      if (item.porta === '__erro') partes.push(item.detalhe || 'erro');
      else if (item.porta === '__dormir') partes.push('dormiu');
      else if (item.porta === '__aguardar') partes.push('aguardando evento');
      else if (item.detalhe === 'cap') partes.push('envio pulado pelo teto semanal');
      else if (item.porta && item.porta !== 'saida') partes.push('porta ' + item.porta);
    }
    return partes.join(' · ');
  }

  function quando(iso) {
    var d = new Date(String(iso).replace(' ', 'T'));
    if (isNaN(d)) return iso;
    var diff = (Date.now() - d.getTime()) / 1000;
    if (diff < 60)    return 'agora';
    if (diff < 3600)  return Math.floor(diff / 60) + ' min atrás';
    if (diff < 86400) return Math.floor(diff / 3600) + ' h atrás';
    return d.toLocaleDateString('pt-BR') + ' ' +
           d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
  }

  function linha(item) {
    var ap  = apresentar(item);
    var $li = $('<div class="fxa_item">').attr('data-id', item.id);
    if (item.porta === '__erro' || (item.no_chave === '__fim' && item.porta === 'erro')) {
      $li.addClass('fxa_item_erro');
    }

    $li.append($('<div class="fxa_ic ' + ap.classe + '">')
      .append('<i class="bi ' + ap.icone + '"></i>'));

    var $c = $('<div class="fxa_corpo">');
    var $t = $('<div class="fxa_titulo">');
    $t.append($('<b>').text(ap.rotulo));
    if (item.fluxo_nome) $t.append($('<span class="fxa_fluxo">').text(item.fluxo_nome));
    $c.append($t);

    var desc = descrever(item);
    if (desc) $c.append($('<div class="fxa_desc">').text(desc));

    var $meta = $('<div class="fxa_meta">');
    $meta.append($('<span>').text(quando(item.criado_em)));
    if (item.cliente_nome)      $meta.append($('<span>').text(item.cliente_nome));
    else if (item.cliente_id)   $meta.append($('<span>').text('cliente #' + item.cliente_id));
    $meta.append($('<span>').text('exec #' + item.execucao_id));
    if (parseInt(item.duracao_ms, 10) > 500) {
      $meta.append($('<span class="fxa_lento">').text(item.duracao_ms + ' ms'));
    }
    $c.append($meta);

    return $li.append($c);
  }

  function filtros() {
    return {
      fluxo_id:   $('#fxa-f-fluxo').val() || '',
      cliente_id: $('#fxa-f-cliente').val() || '',
      so_erros:   $('#fxa-f-erros').prop('checked') ? 1 : ''
    };
  }

  function carregar(anexar) {
    var params = filtros();
    if (anexar) params.antes_de = cursorProximo;

    $.get(BASE + '/admin/fluxos/atividade/dados', params, function (r) {
      if (!r || !r.ok) return;

      var $l = $('#fxa-lista');
      if (!anexar) $l.empty();
      else $l.find('.fxa_load').remove();

      if (!r.itens.length && !anexar) {
        $l.append($('<div class="fxa_vazio">')
          .append('<i class="bi bi-moon-stars"></i>')
          .append($('<div class="fxa_vazio_t">').text('Nada por aqui ainda'))
          .append($('<p class="fxa_vazio_p">').text(
            'Assim que um fluxo publicado rodar, cada passo aparece nesta linha do tempo.')));
        $('#fxa-mais').hide();
        return;
      }

      $.each(r.itens, function (_, item) { $l.append(linha(item)); });
      cursorProximo = r.proximo || 0;
      $('#fxa-mais').toggle(r.itens.length >= 50);

      // KPIs vêm só na primeira página
      if (r.kpis) {
        $('#fxa-k-iniciadas').text(r.kpis.iniciadas_hoje);
        $('#fxa-k-envios').text(r.kpis.envios_hoje);
        $('#fxa-k-erros').text(r.kpis.erros_24h);
        var vivas = r.kpis.ativas + r.kpis.dormindo + r.kpis.aguardando;
        $('#fxa-k-vivas').text(vivas);
        $('#fxa-k-vivas-nota').text(
          r.kpis.dormindo + ' dormindo · ' + r.kpis.aguardando + ' aguardando evento');
      }
    }, 'json');
  }

  // ── Polling adaptativo (padrão do projeto: ativo 15s, background pausado) ──
  function ligarPolling() {
    pararPolling();
    timer = setInterval(function () {
      // Não recarrega se o usuário paginou para o passado (perderia a posição)
      if (!maisAntigo) carregar(false);
    }, 15000);
    $('#fxa-ao-vivo').addClass('ativo');
  }
  function pararPolling() {
    if (timer) { clearInterval(timer); timer = null; }
    $('#fxa-ao-vivo').removeClass('ativo');
  }

  $(function () {
    carregar(false);
    ligarPolling();

    document.addEventListener('visibilitychange', function () {
      if (document.hidden) pararPolling();
      else { carregar(false); maisAntigo = false; ligarPolling(); }
    });

    $('#fxa-f-fluxo, #fxa-f-erros').on('change', function () {
      maisAntigo = false; cursorProximo = 0; carregar(false);
    });
    var tCliente = null;
    $('#fxa-f-cliente').on('input', function () {
      clearTimeout(tCliente);
      tCliente = setTimeout(function () {
        maisAntigo = false; cursorProximo = 0; carregar(false);
      }, 400);
    });

    $('#fxa-mais').on('click', function () {
      maisAntigo = true;
      carregar(true);
    });
  });

})(jQuery);
