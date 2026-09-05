// assets/js/product.js
// Comportamentos da página de produto.

$(function () {
  if (!$('.product-page').length) return;

  // ── Galeria de imagens ───────────────────────────────────
  const $mainImg  = $('#gallery-main-img');
  const $thumbs   = $('.gallery-thumb');
  let   currentIdx = 0;

  function setGalleryImage(idx) {
    const $thumb = $thumbs.eq(idx);
    const src    = $thumb.data('src');
    if (!src) return;

    $thumbs.removeClass('active');
    $thumb.addClass('active');
    $mainImg.addClass('loading').attr('src', src).one('load', function () {
      $(this).removeClass('loading');
    });
    currentIdx = idx;
  }

  $thumbs.on('click', function () {
    setGalleryImage($(this).data('index'));
  });

  $('#gallery-prev').on('click', function () {
    setGalleryImage((currentIdx - 1 + $thumbs.length) % $thumbs.length);
  });
  $('#gallery-next').on('click', function () {
    setGalleryImage((currentIdx + 1) % $thumbs.length);
  });

  // Touch swipe na imagem principal
  let touchStartX = 0;
  document.querySelector('#zoom-wrapper')?.addEventListener('touchstart', e => {
    touchStartX = e.changedTouches[0].clientX;
  }, { passive: true });
  document.querySelector('#zoom-wrapper')?.addEventListener('touchend', e => {
    const diff = touchStartX - e.changedTouches[0].clientX;
    if (Math.abs(diff) > 50 && $thumbs.length > 1) {
      setGalleryImage(diff > 0
        ? (currentIdx + 1) % $thumbs.length
        : (currentIdx - 1 + $thumbs.length) % $thumbs.length);
    }
  });

  if (window.AVISO_PRODUTO_ID) {
    $('#aviso-estoque-box').show();
    if (window.AVISO_EMAIL) $('#aviso-email').val(window.AVISO_EMAIL);
  }

  $('#aviso-btn').on('click', function () {
    var email = $('#aviso-email').val();
    var $msg  = $('#aviso-msg');

    if (!email || email.indexOf('@') === -1) {
      $msg.removeClass('ok').addClass('err').text('Informe um email válido.').show();
      return;
    }

    var $btn = $(this).prop('disabled', true).text('Enviando...');

    $.post((window.BASE_URL || '') + '/produto/avisar-estoque', {
      produto_id: window.AVISO_PRODUTO_ID,
      email: email,
      csrf_token: window.CSRF_TOKEN || ''
    }, null, 'json')
    .done(function (r) {
      if (r.ok) {
        $msg.removeClass('err').addClass('ok').text(r.msg).show();
        $('#aviso-email').prop('disabled', true);
        $btn.text('Inscrito ✓');
      } else {
        $msg.removeClass('ok').addClass('err').text(r.msg || 'Erro. Tente novamente.').show();
        $btn.prop('disabled', false).text('Avise-me');
      }
    })
    .fail(function () {
      $msg.removeClass('ok').addClass('err').text('Erro de conexão. Tente novamente.').show();
      $btn.prop('disabled', false).text('Avise-me');
    });
  });

  // ── Controle de quantidade ───────────────────────────────
  $('#qty-minus').on('click', function () {
    const $input = $('#product-qty');
    const val = parseInt($input.val());
    if (val > 1) $input.val(val - 1);
  });
  $('#qty-plus').on('click', function () {
    const $input = $('#product-qty');
    const max = parseInt($input.attr('max')) || 999;
    const val = parseInt($input.val());
    if (val < max) $input.val(val + 1);
    else showToast(`Máximo disponível: ${max} unidades.`, 'warning');
  });


  // ── Abas (Descrição / Ficha / Avaliações) ────────────────
  $(document).on('click', '.tab-btn', function () {
    const tabId = $(this).data('tab');
    $('.tab-btn').removeClass('active');
    $('.tab-panel').removeClass('active');
    $(this).addClass('active');
    $(`#tab-${tabId}`).addClass('active');
  });

  // Scroll para avaliações ao clicar no link
  $('a[href="#reviews"]').on('click', function (e) {
    e.preventDefault();
    $('#tab-reviews-btn').trigger('click');
    $('html, body').animate({ scrollTop: $('#product-tabs').offset().top - 80 }, 400);
  });

  // ── Parcelamento ─────────────────────────────────────────
  $('#btn-installments').on('click', function () {
    const $drop = $('#installments-dropdown');
    $drop.is(':visible') ? $drop.slideUp(200) : $drop.slideDown(200);
    $(this).text($drop.is(':visible') ? 'Ver parcelamento completo' : 'Ocultar parcelamento');
  });
  console.log('cep');
  // ── Cálculo de frete ─────────────────────────────────────
  $('#btn-calc-shipping').on('click', calcShipping2);
  $('#shipping-cep').on('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); calcShipping2(); }
  });

  function calcShipping2() {
    
    const cep = $('#shipping-cep').val().replace(/\D/g, '');
    
    
    if (cep.length !== 8) {
      showToast('Informe um CEP válido com 8 dígitos.', 'warning');
      return;
    }

    const $results = $('#shipping-results');
    const $btn     = $('#btn-calc-shipping');
    $btn.prop('disabled', true).text('Calculando...');
    $results.html('<div class="shipping-loading">Buscando opções...</div>').show();

    $.get(BASE_URL + '/frete/calcular', {
      cep:        cep,
      produto_id:11,
      quantidade: 1,
    }, function (res) {
      if (!res.ok || !res.opcoes || !res.opcoes.length) {
        $results.html('<p class="shipping-error">CEP não encontrado ou frete indisponível.</p>');
        $btn.prop('disabled', false).text('Calcular');
        return;
      }

      let html = '<ul class="shipping-options">';
      res.opcoes.forEach(function (opt) {
        html += `
          <li class="shipping-option">
            <label class="shipping-option-label">
              <input type="radio" name="frete_opcao"
                     value="${opt.servico}"
                     data-valor="${opt.valor}"
                     data-prazo="${opt.prazo}">
              <div class="shipping-option-info">
                <span class="shipping-name">${opt.nome}</span>
                <span class="shipping-prazo">Prazo: ${opt.prazo} dia(s) útil(eis)</span>
              </div>
              <span class="shipping-price">
                ${parseFloat(opt.valor) === 0 ? '<strong>Grátis</strong>' : 'R$ ' + parseFloat(opt.valor).toFixed(2).replace('.', ',')}
              </span>
            </label>
          </li>`;
      });
      html += '</ul>';
      html += `<p class="shipping-cep-info">Entrega para: <strong>${res.localidade} - ${res.uf}</strong></p>`;
      $results.html(html);

      // Salva frete selecionado em cookie para o carrinho/checkout
      $('input[name="frete_opcao"]').on('change', function () {
        const data = {
          servico: $(this).val(),
          valor:   $(this).data('valor'),
          prazo:   $(this).data('prazo'),
          cep:     cep,
        };
        document.cookie = `frete_selecionado=${JSON.stringify(data)};path=/;max-age=86400`;
      });

      $btn.prop('disabled', false).text('Calcular');
    }, 'json').fail(function () {
      $results.html('<p class="shipping-error">Não foi possível calcular o frete.</p>');
      $btn.prop('disabled', false).text('Calcular');
    });
  }

  

  // ── Copiar link ──────────────────────────────────────────
  $('#btn-copy-link').on('click', function () {
    const url = $(this).data('url');
    if (navigator.clipboard) {
      navigator.clipboard.writeText(url).then(function () {
        showToast('Link copiado!', 'success');
      });
    }
  });

  // ── Avaliações: stars input ──────────────────────────────
  const $starInputs  = $('#star-rating input');
  const $starLabels  = $('#star-rating label');

  $starLabels.on('mouseover', function () {
    const val = parseInt($(this).prev('input').val());
    $starLabels.each(function (i) {
      $(this).toggleClass('hovered', i >= (5 - val));
    });
  }).on('mouseout', function () {
    $starLabels.removeClass('hovered');
  });

  // Contador de caracteres no textarea
  $('#review-comentario').on('input', function () {
    $('#review-char-count').text($(this).val().length + '/1000');
  });

  // Submit de avaliação
  $('#review-form').on('submit', function (e) {
    e.preventDefault();
    const $btn = $('#btn-submit-review');

    if (!$('#review-comentario').val().trim()) {
      showToast('Escreva um comentário para enviar.', 'warning');
      return;
    }

    $btn.prop('disabled', true).text('Enviando...');

    $.post(BASE_URL + '/avaliacao/salvar', $(this).serialize(), function (res) {
      if (res.ok) {
        showToast(res.msg, 'success');
        $('#review-form')[0].reset();
        $('#review-char-count').text('0/1000');
      } else {
        showToast(res.msg, 'error');
      }
      $btn.prop('disabled', false).text('Enviar avaliação');
    }, 'json').fail(function () {
      showToast('Erro ao enviar. Tente novamente.', 'error');
      $btn.prop('disabled', false).text('Enviar avaliação');
    });
  });

  // ── Filtros do catálogo ──────────────────────────────────
  if ($('.catalog-page').length) {

    // Ordenação — redireciona ao mudar
    $('#sort-select').on('change', function () {
      const params = new URLSearchParams(window.location.search);
      params.set('ordem', $(this).val());
      params.delete('pagina');
      window.location.search = params.toString();
    });

    // Sidebar mobile
    $('#btn-filter-mobile').on('click', function () {
      $('#catalog-sidebar').addClass('open');
      $('#sidebar-overlay').addClass('visible');
      $('body').addClass('no-scroll');
    });
    $('#btn-sidebar-close, #sidebar-overlay').on('click', function () {
      $('#catalog-sidebar').removeClass('open');
      $('#sidebar-overlay').removeClass('visible');
      $('body').removeClass('no-scroll');
    });

    // Price range sliders
    const $sliderMin = $('#slider-min');
    const $sliderMax = $('#slider-max');
    const $inputMin  = $('#preco_min');
    const $inputMax  = $('#preco_max');

    function syncSliders() {
      const min = parseInt($sliderMin.val());
      const max = parseInt($sliderMax.val());
      if (min > max) { $sliderMin.val(max); return; }
      $inputMin.val(min);
      $inputMax.val(max);
      const total = parseInt($sliderMax.attr('max')) - parseInt($sliderMin.attr('min'));
      const pctL  = (min - parseInt($sliderMin.attr('min'))) / total * 100;
      const pctR  = (max - parseInt($sliderMin.attr('min'))) / total * 100;
      $('#price-range-fill').css({ left: pctL + '%', width: (pctR - pctL) + '%' });
    }

    $sliderMin.on('input', syncSliders);
    $sliderMax.on('input', syncSliders);
    syncSliders();

    // Ver mais marcas
    $(document).on('click', '.btn-show-more', function () {
      const $list  = $('#' + $(this).data('target'));
      $list.find('.filter-check').show();
      $(this).hide();
    });
    // Esconde marcas acima de 6 inicialmente
    $('#brands-list .filter-check:nth-child(n+7)').hide();
  }

  function calcShipping() {
    // Pré-preenche com o CEP ativo se o campo estiver vazio
    const $cepInput = $('#shipping-cep');
    if (!$cepInput.val() && window.EC_CEP_ATIVO) {
      const cep = window.EC_CEP_ATIVO;
      const fmt = cep.substring(0, 5) + '-' + cep.substring(5);
      $cepInput.val(fmt);
    }

    const cep = $cepInput.val().replace(/\D/g, '');
    // ... restante da função existente
  }


  
});

/**
 * Frete na página de produto (vitrine) — layout clean. jQuery v4.
 *
 * Primeira dobra: se é grátis e o prazo. Modal "Opções de frete e retirada"
 * mostra o destino + as opções em FOCO (mais barato e mais rápido — 1 só quando
 * o mais barato já é o mais rápido).
 *
 * Requer: window.BASE_URL.
 * Opcionais:
 *   - window.EC_CART_SUBTOTAL  → subtotal do carrinho (para o selo/limiar)
 *   - window.EC_ENDERECO_TXT   → endereço completo do cliente (mostrado no modal)
 * Hook no seu fluxo de CEP (opcional):
 *   ao salvar:  window.FreteProduto && FreteProduto.atualizar(res.cep);
 *   ao remover: window.FreteProduto && FreteProduto.atualizar(null);
 */
(function ($) {
    'use strict';

    var URL_FRETE = (window.BASE_URL || '') + '/frete/produto';
    var DIAS = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];

    // Ponto de extensão: transportadoras futuras (tele-entrega, D+1 em cidades
    // próximas) podem chegar marcadas com o.categoria. Opções com categoria
    // "especial" são SEMPRE exibidas, além do mais barato/rápido, e continuam
    // passando pelas regras (frete grátis etc.) porque vêm do mesmo backend.
    var CATEGORIAS_ESPECIAIS = ['d1', 'tele_entrega', 'expressa_local', 'retirada'];

    var $box, PRODUTO_ID = 0, PRECO = 0, cepAtual = null, ultima = null;

    var ICO = {
        truck: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M1 3h15v13H1z"/><path d="M16 8h4l3 3v5h-7z"/><circle cx="5.5" cy="18.5" r="2"/><circle cx="18.5" cy="18.5" r="2"/></svg>',
        pin: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 12-9 12s-9-5-9-12a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>',
        econ: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M20.6 13.4 12 22l-8.6-8.6A5 5 0 0 1 2 9.9V4h5.9a5 5 0 0 1 3.5 1.4l9.2 9.2a1.8 1.8 0 0 1 0 2.6z"/><circle cx="7" cy="9" r="1.3"/></svg>',
        fast: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 4 14h7l-1 8 9-12h-7l1-8z"/></svg>',
        star: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l2.9 6.3 6.6.6-5 4.4 1.5 6.6L12 17l-6 3.5L7.5 14l-5-4.4 6.6-.6L12 2z"/></svg>'
    };

    /* ---------- utils ---------- */
    function cookieCep() { var m = document.cookie.match(/(?:^|;\s*)ec_cep=([^;]+)/); return m ? m[1].replace(/\D/g, '') : ''; }

    function cepAtivo() { 
      var c = cookieCep(); 
      if (c.length === 8) return c; 
      console.log(window);
      
      var g = (window.EC_CEP_ATIVO || '').toString().replace(/\D/g, ''); 
      return g.length === 8 ? g : ''; 
    }

    function fmtCep(c) { c = (c || '').replace(/\D/g, ''); return c.length === 8 ? c.slice(0, 5) + '-' + c.slice(5) : c; }
    function reais(v) { return 'R$ ' + (Number(v) || 0).toFixed(2).replace('.', ','); }
    function esc(s) { return $('<i>').text(s == null ? '' : String(s)).html(); }
    function subtotalCarrinho() { return Number(window.EC_CART_SUBTOTAL || 0) || 0; }

    function maisDiasUteis(n) { var d = new Date(), add = 0; while (add < n) { d.setDate(d.getDate() + 1); var w = d.getDay(); if (w !== 0 && w !== 6) add++; } return d; }

    // Regra do prazo: dentro de 6 dias -> nome do dia da semana; senão -> data.
    // (7 dias cairia no mesmo dia de hoje — ex.: hoje segunda, "segunda-feira" —
    // e ficaria ambíguo; por isso o corte é <= 6 e acima disso mostra dd/mm.)
    function chegada(prazo) {
        prazo = Math.max(1, parseInt(prazo, 10) || 1);
        var d = maisDiasUteis(prazo), hoje = new Date(); hoje.setHours(0, 0, 0, 0);
        var dias = Math.round((d - hoje) / 86400000);
        if (dias <= 6) return DIAS[d.getDay()];
        return ('0' + d.getDate()).slice(-2) + '/' + ('0' + (d.getMonth() + 1)).slice(-2);
    }

    function ehGratis(r) { var ops = r.opcoes || [], cta = r.cta || {}; return !!(ops[0] && ops[0].frete_gratis) || cta.tipo === 'ja_tem'; }
    function limiarDe(cta) { if (!cta) return null; if (cta.limiar != null) return Number(cta.limiar); if (cta.faltam != null) return Number(cta.faltam) + subtotalCarrinho(); return null; }
    function seloHtml(free, cta) {
        if (free) return '<span class="fp_selo">Frete grátis</span>';
        var lim = limiarDe(cta);
        if (lim != null && lim > 0) return '<span class="fp_selo">Frete grátis acima de ' + reais(lim) + '</span>';
        return '';
    }

    /* ---------- estados do widget ---------- */
    function skeleton() { $box.html('<div class="fp_frete fp_frete--loading"><div class="fp_sk fp_sk--a"></div><div class="fp_sk fp_sk--b"></div><div class="fp_sk fp_sk--c"></div></div>'); }

    function estadoSemCep() {
        $box.html('<div class="fp_frete fp_frete--cep">' +
            '<span class="fp_cep_ic">' + ICO.truck + '</span>' +
            '<span class="fp_cep_txt"><strong>Frete e prazo de entrega</strong><span>Informe seu CEP para calcular</span></span>' +
            '<button type="button" class="fp_cep_btn" data-fp-cep>Calcular</button>' +
        '</div>');
    }

    function estadoErro(msg) {
        $box.html('<div class="fp_frete fp_frete--cep">' +
            '<span class="fp_cep_ic">' + ICO.truck + '</span>' +
            '<span class="fp_cep_txt"><strong>Não foi possível calcular</strong><span class="fp_erro_txt">' + esc(msg || 'Tente novamente.') + '</span></span>' +
            '<button type="button" class="fp_cep_btn" data-fp-cep>Alterar</button>' +
        '</div>');
    }

    function estadoResultado(r) {
        var ops = r.opcoes || [];
        if (!ops.length) { estadoErro('Sem entrega para este CEP.'); return; }
        var best = ops[0], free = ehGratis(r), dia = chegada(best.prazo_dias);
        var linha = free
            ? 'Chegará <span class="fp_g">grátis</span> até <strong>' + dia + '</strong>'
            : 'Chegará até <strong>' + dia + '</strong> · a partir de ' + reais(best.valor);
        $box.html('<div class="fp_frete">' +
            seloHtml(free, r.cta) +
            '<p class="fp_prazo">' + linha + '</p>' +
            '<button type="button" class="fp_detalhes" data-fp-more>Mais detalhes e formas de entrega</button>' +
        '</div>');
    }

    /* ---------- seleção em foco (mais barato + mais rápido) ---------- */
    function valorDe(o) { return o.frete_gratis ? 0 : (Number(o.valor) || 0); }
    function cmpBarato(a, b) { var d = valorDe(a) - valorDe(b); return d !== 0 ? d : ((a.prazo_dias | 0) - (b.prazo_dias | 0)); }
    function cmpRapido(a, b) { var d = (a.prazo_dias | 0) - (b.prazo_dias | 0); return d !== 0 ? d : (valorDe(a) - valorDe(b)); }
    function menorPor(ops, cmp) { return ops.reduce(function (m, o) { return cmp(o, m) < 0 ? o : m; }, ops[0]); }

    // Retorna [{op, tipo}] — no máximo: especiais (futuro) + mais barato + mais rápido.
    // Quando o mais barato já é o mais rápido, devolve 1 só (tipo 'unica').
    function selecionarOpcoes(ops) {
        if (!ops.length) return [];
        var especiais = ops.filter(function (o) { return CATEGORIAS_ESPECIAIS.indexOf(String(o.categoria || '').toLowerCase()) >= 0; });
        var comuns = ops.filter(function (o) { return CATEGORIAS_ESPECIAIS.indexOf(String(o.categoria || '').toLowerCase()) < 0; });

        var lista = especiais.map(function (o) { return { op: o, tipo: 'especial' }; });

        if (comuns.length) {
            var barato = menorPor(comuns, cmpBarato);
            var rapido = menorPor(comuns, cmpRapido);
            if (barato === rapido) lista.push({ op: rapido, tipo: 'unica' });
            else { lista.push({ op: barato, tipo: 'barato' }); lista.push({ op: rapido, tipo: 'rapido' }); }
        }
        return lista;
    }

    var ROTULO_CATEGORIA = { d1: 'Entrega rápida', tele_entrega: 'Tele-entrega', expressa_local: 'Entrega expressa', retirada: 'Retirar na loja' };

    function cardOpcao(item, free) {
        console.log(item, free);
        
        var o = item.op;
        var gr = o.frete_gratis || (free && (item.tipo === 'barato' || item.tipo === 'unica'));
        var val = gr ? '<span class="fp_c_val is-gratis">Grátis</span>' : '<span class="fp_c_val">' + reais(o.valor) + '</span>';
        var rotEsp = o.servico || ROTULO_CATEGORIA[String(o.categoria || '').toLowerCase()] || 'Entrega expressa';
        var meta = ({
            barato: { ic: ICO.econ, rot: 'Mais econômica' },
            rapido: { ic: ICO.fast, rot: 'Mais rápida' },
            unica: { ic: ICO.fast, rot: gr ? 'Frete grátis' : 'Entrega' },
            especial: { ic: ICO.star, rot: rotEsp }
        })[item.tipo] || { ic: ICO.truck, rot: 'Entrega' };
        return '<div class="fp_card">' +
            '<span class="fp_c_ic">' + meta.ic + '</span>' +
            '<div class="fp_c_info"><span class="fp_c_rot">' + esc(meta.rot) + '</span>' +
                '<span class="fp_c_prazo">Chegará até ' + chegada(o.prazo_dias) + '</span></div>' +
            val +
        '</div>';
    }

    function destinoHtml(r) {
        var full = String(window.EC_ENDERECO_TXT || '').trim();
        var loc = r.localidade ? esc(r.localidade) + '/' + esc(r.uf) : '';
        var linha2 = full ? esc(full) : (loc || 'Endereço de entrega');
        return '<div class="fp_dest">' +
            '<span class="fp_dest_ic">' + ICO.pin + '</span>' +
            '<div class="fp_dest_info">' +
                '<span class="fp_dest_cep">CEP ' + esc(fmtCep(cepAtual)) + '</span>' +
                '<span class="fp_dest_loc">' + linha2 + '</span>' +
                '<button type="button" class="fp_dest_link" data-fp-cep>Trocar CEP</button>' +
            '</div>' +
        '</div>';
    }

    /* ---------- modal ---------- */
    function abrirModal() {
        if (!ultima || !(ultima.opcoes || []).length) return;
        fecharModal();
        var free = ehGratis(ultima);
        // curadoria vem do BACKEND (mesma usada em carrinho/checkout/pedido manual);
        // fallback para a seleção no cliente se a resposta não trouxer destaques.
        var itens = (ultima.destaques && ultima.destaques.length)
            ? ultima.destaques.map(function (d) { return { op: d.opcao, tipo: d.tipo }; })
            : selecionarOpcoes(ultima.opcoes);
        var cards = itens.map(function (it) { return cardOpcao(it, free); }).join('');
        var selo = seloHtml(free, ultima.cta);

        var $bg = $('<div class="fp_modal_bg" id="fpModal">' +
            '<div class="fp_modal" role="dialog" aria-modal="true" aria-label="Opções de frete e retirada">' +
                '<div class="fp_modal_head"><h3>Opções de frete e retirada</h3>' +
                    '<button type="button" class="fp_modal_x" data-fp-close aria-label="Fechar">&times;</button></div>' +
                '<p class="fp_modal_sub">Calculamos os custos e prazos para este endereço:</p>' +
                destinoHtml(ultima) +
                (selo ? '<div class="fp_modal_selo">' + selo + '</div>' : '') +
                '<div class="fp_cards">' + cards + '</div>' +
                (ultima.estimativa ? '<p class="fp_modal_nota">Prazos e valores estimados — a cotação em tempo real está indisponível no momento.</p>' : '') +
            '</div>' +
        '</div>');
        $('body').append($bg).addClass('fp_no_scroll');
        requestAnimationFrame(function () { $bg.addClass('is-open'); });
    }
    function fecharModal() { var $m = $('#fpModal'); if (!$m.length) return; $m.removeClass('is-open'); $('body').removeClass('fp_no_scroll'); setTimeout(function () { $m.remove(); }, 170); }

    /* ---------- fluxo ---------- */
    function buscar() {
        var cep = cepAtivo();
        console.log(cep);
        
        if (!cep) { estadoSemCep(); return; }
        cepAtual = cep; skeleton();
        $.get(URL_FRETE, { cep: cep, produto_id: PRODUTO_ID, subtotal_atual: subtotalCarrinho() }, function (r) {
            if (!r || !r.ok) { estadoErro(r && r.erro); return; }
            r.cep_usado = cep; ultima = r; estadoResultado(r);
        }, 'json').fail(function () { estadoErro('Erro de comunicação.'); });
    }
    function abrirModalCep() { var $b = $('.btn-open-location'); if ($b.length) $b.first().trigger('click'); }

    /* ---------- API + auto-refresh ---------- */
    window.FreteProduto = {
        atualizar: function (cep) { if (cep === null || cep === false) { ultima = null; estadoSemCep(); return; } buscar(); },
        recarregar: buscar
    };

    $(function () {
      
        $box = $('#fpFrete'); if (!$box.length) return;
        
        PRODUTO_ID = parseInt($box.data('produto-id'), 10) || 0;
        PRECO = parseFloat($box.data('preco')) || 0;
        if (!PRODUTO_ID) return;        

        // "Calcular"/"Trocar CEP" (widget ou modal) abrem a sua modal de localização
        $(document).on('click', '[data-fp-cep]', function (e) { e.stopPropagation(); if ($('#fpModal').length) fecharModal(); abrirModalCep(); });
        $box.on('click', '[data-fp-more]', abrirModal);
        $(document).on('click', '[data-fp-close]', fecharModal);
        $(document).on('click', '#fpModal', function (e) { if (e.target === this) fecharModal(); });
        $(document).on('keydown', function (e) { if (e.key === 'Escape') fecharModal(); });

        $(document).on('submit', '#form-cep', function () { setTimeout(buscar, 900); });
        $(document).on('click', '#btn-remove-cep', function () { setTimeout(function () { window.FreteProduto.atualizar(null); }, 500); });

        buscar();
    });
})(jQuery);


$(function () {
  if (typeof window.PV === 'undefined') return;

  const PV             = window.PV;
  const tiposOrdenados = PV.tipos_slug  || [];
  const legadoMap      = PV.legado_map  || {};
  const selecoes       = {};
  let   skuAtual       = null;
  console.log(PV);
  // ── Pré-seleciona variante da URL ────────────────────────
  if (PV.variant_pre) {
    const pre = PV.variant_pre;

    // Marca os botões como ativos
    Object.entries(pre.atributos).forEach(([tipo, valor]) => {
      selecoes[tipo] = String(valor);
      const $btn = $(`.variation-swatch--variacao[data-tipo="${tipo}"][data-valor="${valor}"]`);
      $btn.addClass('active');
      
      $(`#label-${tipo}`).text(valor);
    });

    let sku_data = Object.values(selecoes);
    let sku_id = (sku_data.length > 0) ? sku_data[0] : 0;

    const sku_matriz   = PV.matriz[sku_id];

    console.log();
    

    // Atualiza UI com os dados já disponíveis
    aplicarSkuNaUI(sku_matriz);
  }

  // ── Clique em variação (tamanho, voltagem...) ────────────
  $(document).on('click', '.variation-swatch--variacao:not(:disabled):not(.sem-estoque)', function () {
    const $btn  = $(this);
    const tipo  = String($btn.data('tipo'));
    const valor = String($btn.data('valor'));

    // Visual
    $(`.variation-swatch--variacao[data-tipo="${tipo}"]`).removeClass('active');
    $btn.addClass('active');
    $(`#label-${tipo}`).text(valor);

    selecoes[tipo] = valor;

    resolverSku();
  });

  // ── Clique em agrupador (cor, estampa...) ────────────────
  // Navega para outro produto via pushState
  $(document).on('click', '.variation-swatch--agrupador', function (e) {
    e.preventDefault();

    const href     = $(this).attr('href');
    const produtoId = $(this).data('produto-id');

    // Se já é o produto atual, ignora
    if (parseInt(produtoId) === parseInt(PV.produto_id)) return;

    // Navega para o produto da cor (com reload pois é outro produto)
    window.location.href = href;
  });

  // ── Resolve SKU pela combinação atual ────────────────────
  

  // Gera um UUID no navegador (mesmo formato do servidor)
  function uuidv4() {
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      var r = Math.random() * 16 | 0;
      var v = c === 'x' ? r : (r & 0x3 | 0x8);
      return v.toString(16);
    });
  }

  // Handler unificado para adicionar ao carrinho da página de produto
  $(document).on('click', '#btn-comprar, .btn-add-cart-detail, #btn-buynow', function (e) {
      e.preventDefault();

      const $btn      = $(this);
      const isBuyNow  = $btn.is('#btn-buynow');   // Comprar agora → checkout
      const produtoId = parseInt($btn.data('product-id') || $btn.attr('data-product-id'));
      const produtoName = $btn.parent().data('pro-name') || $btn.parent().attr('data-pro-name');

      // Lê o sku_id de ambas as fontes (jQuery data e atributo HTML)
      const skuId     = parseInt($btn.parent().data('sku-id') || $btn.parent().attr('data-sku-id') || 0);

      if (!produtoId) return;

      // Verifica se tem variações obrigatórias não selecionadas
      if (typeof window.PV !== 'undefined' && PV.tipos_slug && Object.keys(PV.tipos_slug).length > 0) {
          if (!skuId) {
              mostrarAviso('Selecione todas as opções antes de adicionar ao carrinho.');
              // Destaca os seletores sem seleção
              $('.variation-swatch--variacao').closest('.variation-group')
                  .filter(function () {
                      return !$(this).find('.variation-swatch--variacao.active').length;
                  })
                  .addClass('variation-group--alerta');

              setTimeout(function () {
                  $('.variation-group--alerta').removeClass('variation-group--alerta');
              }, 2000);
              return;
          }
      }

      // Estado de carregamento sem apagar o ícone e sem trocar a identidade do
      // botão: quem clicou em "Comprar agora" volta a ler "Comprar agora".
      setRotulo($btn, 'Adicionando...');
      $btn.prop('disabled', true).addClass('btn-disabled');

      const dados = {
          produto_id  : produtoId,
          quantidade  : parseInt($('#product-qty').val()) || 1,
          _csrf_token : CSRF_TOKEN,
      };

      if (skuId > 0) {
          dados.sku_id = skuId;
      }
      var eventId = uuidv4(); // ← gerado AQUI, vai pros 2 lados

      // 1. Dispara o Pixel IMEDIATAMENTE (no clique) com o eventId.
      //    value vem de precoCorrente() (numérico, do SKU escolhido). Antes era
      //    PV.preco — string formatada — e o evento saía com value NaN; em
      //    produto sem variação o window.PV nem existe e o clique inteiro
      //    quebrava antes do POST.
      if (window.smPixel) {
        window.smPixel.track('AddToCart', {
          content_type: 'product',
          content_ids: [String(produtoId)],
          content_name: produtoName || '',
          value: precoCorrente() * (dados.quantidade || 1),
          currency: 'BRL'
        }, eventId);
      }

      dados.event_id = eventId;

      $.post(BASE_URL + '/carrinho/adicionar', dados, function (res) {
          restaurarRotulo($btn);
          $btn.prop('disabled', false).removeClass('btn-disabled');

          if (!res.ok) {
              mostrarAviso(res.msg || 'Erro ao adicionar.');
              return;
          }

          // Atualiza badge
          if (res.count !== undefined) {
              $('#cart-count, #mc-badge').text(res.count).show();
          }

          // Comprar agora vai direto ao checkout
          if (isBuyNow) { window.location.href = BASE_URL + '/checkout'; return; }

          // Abre mini cart
          if (typeof abrirMiniCart === 'function') abrirMiniCart();

          if (typeof showToast === 'function') {
              showToast('Produto adicionado ao carrinho!', 'success');
          }



      }, 'json').fail(function (xhr) {
          console.error('[Comprar] Erro:', xhr.status, xhr.responseText);
          restaurarRotulo($btn);
          $btn.prop('disabled', false).removeClass('btn-disabled');
          mostrarAviso('Não foi possível adicionar ao carrinho. Tente de novo.');
      });
  });


  // ── Preço corrente da página ──────────────────────────────
  // Base = preço efetivo do produto (PV.preco_num, numérico). Passa a ser o
  // preço do SKU assim que houver seleção. PV.preco é string formatada
  // ("R$ 899,90") e virava NaN quando usado em conta.
  let precoSelecionado = null;

  function precoBaseNum() {
      return (window.PV && parseFloat(PV.preco_num)) || 0;
  }

  function precoCorrente() {
      return precoSelecionado !== null ? precoSelecionado : precoBaseNum();
  }

  // Arredonda como o round() do PHP (metade para cima), não como o toFixed()
  // do JS. Sem isto, 899,90 × 0,95 = 854,905 virava "854,90" aqui e "854,91"
  // no PHP: o preço do Pix mudava um centavo no instante em que o cliente
  // escolhia o tamanho. O .toPrecision(12) mata o ruído binário do float.
  function arredondar2(valor) {
      return Math.round(Number((valor * 100).toPrecision(12))) / 100;
  }

  function formatarBRL(valor) {
      return 'R$ ' + arredondar2(valor).toFixed(2)
          .replace('.', ',')
          .replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  }

  // ── Rótulo dos CTAs ───────────────────────────────────────
  // Escreve no .pdx-btn-label, nunca no botão: .text() no botão apagava o
  // ícone SVG junto com o texto. Guarda o rótulo original na primeira troca
  // para conseguir voltar ao que o PHP renderizou.
  function alvoRotulo($btn) {
      const $label = $btn.find('.pdx-btn-label');
      return $label.length ? $label : $btn;
  }

  function setRotulo($btn, texto) {
      const $alvo = alvoRotulo($btn);
      if (!$alvo.length) return;
      if ($alvo.data('rotulo-original') === undefined) {
          $alvo.data('rotulo-original', $alvo.text().trim());
      }
      $alvo.text(texto);
  }

  function restaurarRotulo($btn) {
      const $alvo = alvoRotulo($btn);
      if (!$alvo.length) return;
      const original = $alvo.data('rotulo-original');
      if (original !== undefined) $alvo.text(original);
  }

  // ── Pix ───────────────────────────────────────────────────
  // O bloco de formas de pagamento vive FORA do #price-range-wrapper, então
  // sobrevive à seleção de variação; aqui só o valor é recalculado por SKU.
  let pixBaseFmt = null;

  function atualizarPix(precoNum) {
      const $alvo = $('#pdx-pix-valor');
      if (!$alvo.length) return;
      if (pixBaseFmt === null) pixBaseFmt = $alvo.text();

      const pct   = (window.PV && parseFloat(PV.pix_pct)) || 0;
      const preco = parseFloat(precoNum);
      if (!pct || !preco || preco <= 0) return;

      $alvo.text(formatarBRL(preco * (1 - pct / 100)));
  }

  function restaurarPix() {
      if (pixBaseFmt !== null) $('#pdx-pix-valor').text(pixBaseFmt);
  }

  // ── Estado dos CTAs ───────────────────────────────────────
  function ctas() {
      return {
          $add : $('.btn-add-cart-detail, #btn-comprar').first(),
          $buy : $('#btn-buynow')
      };
  }

  function liberarCtas() {
      const { $add, $buy } = ctas();
      restaurarRotulo($add);
      restaurarRotulo($buy);
      $add.prop('disabled', false).removeClass('btn-disabled');
      $buy.prop('disabled', false).removeClass('btn-disabled').addClass('btn-primary');
  }

  function aplicarSkuNaUI(sku) {
    $('#price-range-wrapper').hide();
    $('#sku-preco-wrapper').show();

    // Preço principal
    $('#sku-preco-valor').text(sku.preco_fmt);

    // Preço original riscado (se tiver promo)
    if (sku.preco_original_fmt) {
        $('#sku-preco-original').text(sku.preco_original_fmt).show();
    } else {
        $('#sku-preco-original').hide();
    }

    // Parcelamento — regras vindas do PHP (PV.parcelas)
    const parcela = calcularParcela(sku.preco);
    if (parcela) {
        $('#sku-preco-parcela')
            .text('ou ' + parcela.vezes + 'x de ' + parcela.valorFmt + (parcela.semJuros ? ' sem juros' : ''))
            .show();
    } else {
        $('#sku-preco-parcela').hide();
    }

    atualizarPix(sku.preco);
    precoSelecionado = parseFloat(sku.preco) || precoSelecionado;

    // O sku_id é lido do elemento PAI no handler de compra. Precisa ser gravado
    // nos dois estados: antes, um SKU esgotado mantinha o id do SKU anterior.
    const { $add, $buy } = ctas();
    $('.product-actions').attr('data-sku-id', sku.sku_id).data('sku-id', sku.sku_id);
    $add.attr('data-sku-id', sku.sku_id).data('sku-id', sku.sku_id);
    $buy.attr('data-sku-id', sku.sku_id).data('sku-id', sku.sku_id);

    if (sku.sem_estoque) {
        // Os DOIS botões saem de cena. Antes só o primeiro do seletor era
        // desabilitado (o "Comprar agora"), e o "Adicionar ao carrinho" seguia
        // ativo — dava para mandar um SKU esgotado pro carrinho.
        setRotulo($add, 'Sem estoque');
        $add.prop('disabled', true).addClass('btn-disabled');
        $buy.prop('disabled', true).removeClass('btn-primary').addClass('btn-disabled');
    } else {
        // O #btn-buynow NUNCA é renomeado: ele é "Comprar agora" e vai direto
        // ao checkout. Renomear os dois deixava botões idênticos lado a lado
        // com destinos diferentes.
        liberarCtas();
    }
  }

  // Espelha PriceHelper::installments(): mesmas chaves de config, mesma regra de
  // corte, mesma fórmula de juros. Os números vêm do PHP em PV.parcelas — este
  // arquivo não tem opinião própria sobre parcelamento. Antes tinha
  // (MAX_PARCELAS=10, mínimo R$ 10,00) e contradizia o PHP e a barra do topo.
  function regrasParcelamento() {
      const cfg = (window.PV && PV.parcelas) || {};
      const num = (v, padrao) => (isFinite(parseFloat(v)) ? parseFloat(v) : padrao);
      return {
          max     : num(cfg.max, 12),
          minValor: num(cfg.min_valor, 30.00),
          juros   : num(cfg.juros, 0)
      };
  }

  function calcularParcela(preco) {
      const valorTotal = parseFloat(preco);
      if (!valorTotal || valorTotal <= 0) return null;

      const { max, minValor, juros } = regrasParcelamento();
      let melhorParcela = null;

      for (let n = 1; n <= max; n++) {
          const valor = (juros > 0 && n > 1)
              ? valorTotal * (juros / 100) / (1 - Math.pow(1 + juros / 100, -n))
              : valorTotal / n;

          if (valor < minValor && n > 1) break;

          melhorParcela = {
              vezes   : n,
              valor   : valor,
              valorFmt: formatarBRL(valor),
              semJuros: !(juros > 0 && n > 1)
          };
      }

      // 1x não é parcelamento: não há o que anunciar.
      return (melhorParcela && melhorParcela.vezes > 1) ? melhorParcela : null;
  }

  function restaurarPrecoBase() {
      $('#sku-preco-wrapper').hide();
      $('#price-range-wrapper').show();
      restaurarPix();
      precoSelecionado = null;
      $('.product-actions').removeAttr('data-sku-id').removeData('sku-id');
      liberarCtas();
  }

  function atualizarURL(chave) {
    
    
      if (!window.history || !window.history.pushState) return;

      // Usa chave_map para pegar o identifier (id_legado ou sku_id)
      const identifier = PV.chave_map && PV.chave_map[chave]
                        ? PV.chave_map[chave]
                        : null;

      if (!identifier) {
          console.warn('[URL] Identifier não encontrado para chave:', chave);
          return;
      }

      const url = new URL(window.location.href);
      url.searchParams.set('variant_id', identifier);

      window.history.pushState(
          { sku: skuAtual, selecoes: Object.assign({}, selecoes) },
          document.title,
          url.toString()
      );

      console.log('[URL] Atualizada para:', url.toString());
  }

  function resolverSku() {
      const faltando = tiposOrdenados.filter(t => !selecoes[t]);
      atualizarBotoesDisponiveis();

      if (faltando.length > 0) {
          esconderPrecoSku();
          return;
      }

      const chave = tiposOrdenados.map(t => selecoes[t]).join('|');
      const sku   = PV.matriz[chave];

      console.log('chave -> '+chave);
      

      if (!sku) {
          mostrarAviso('Combinação não disponível.');
          esconderPrecoSku();
          return;
      }

      esconderAviso();
      skuAtual = sku;
      aplicarSkuNaUI(sku);
      atualizarURL(chave); // ← passa a chave, não mais o id_legado
  }

  // ── Botão voltar/avançar do browser ─────────────────────
  window.addEventListener('popstate', function (e) {
    if (!e.state) return;

    // Restaura as seleções do estado anterior
    const { selecoes: sel } = e.state;
    if (!sel) return;

    // Limpa seleções atuais
    Object.keys(selecoes).forEach(k => delete selecoes[k]);
    $('.variation-swatch--variacao').removeClass('active');

    // Aplica o estado anterior
    Object.entries(sel).forEach(([tipo, valor]) => {
      selecoes[tipo] = valor;
      const $btn = $(`.variation-swatch--variacao[data-tipo="${tipo}"][data-valor="${valor}"]`);
      $btn.addClass('active');
      $(`#label-${tipo}`).text(valor);
    });

    resolverSku();
  });

  // ── Disponibilidade dos botões ────────────────────────────
  function atualizarBotoesDisponiveis() {
    tiposOrdenados.forEach(function (tipoAtual) {
      $(`.variation-swatch--variacao[data-tipo="${tipoAtual}"]`).each(function () {
        const $btn     = $(this);
        const valorBtn = String($btn.data('valor'));

        const outrosSelecionados = tiposOrdenados
          .filter(t => t !== tipoAtual)
          .every(t => selecoes[t]);

        if (!outrosSelecionados) return;

        const chaveSimulada = tiposOrdenados
          .map(t => t === tipoAtual ? valorBtn : selecoes[t])
          .join('|');

        const skuSim      = PV.matriz[chaveSimulada];
        const indisponivel = !skuSim || skuSim.sem_estoque;

        $btn.toggleClass('sem-estoque', indisponivel && !$btn.hasClass('active'))
            .prop('disabled', indisponivel && !$btn.hasClass('active'));
      });
    });
  }

  // ── Helpers UI ────────────────────────────────────────────
  function mostrarPrecoSku(precoFmt, semEstoque) {
    $('#sku-preco-valor').text(precoFmt);
    $('#sku-preco-wrapper').show();
    $('#sku-preco-valor').toggleClass('sku-preco--sem-estoque', semEstoque);
  }

  function esconderPrecoSku() {
    $('#sku-preco-wrapper').hide();
  }

  function mostrarAviso(msg) {
    $('#variacao-aviso').text(msg).show();
  }

  function esconderAviso() {
    $('#variacao-aviso').hide();
  }

  // Hover no label do agrupador
  $(document).on('mouseenter', '.variation-swatch--agrupador', function () {
    $(this).closest('.variation-group')
           .find('.variation-valor-atual')
           .text($(this).data('valor'));
  }).on('mouseleave', '.variation-swatch--agrupador', function () {
    const $grp = $(this).closest('.variation-group');
    $grp.find('.variation-valor-atual')
        .text($grp.find('.active').data('valor') || '');
  });

  // Inicializa
  atualizarBotoesDisponiveis();

// ── Wishlist dropdown na página do produto ────────────────
  (function () {
    console.log('TESTING');
    
    if (!$('#btn-wishlist-toggle').length) return;

    const produtoId = $('#btn-wishlist-toggle').data('product-id');
    let   listasCarregadas = false;

    // ── Abre o dropdown ──────────────────────────────────
    $(document).on('click', '#btn-wishlist-toggle', function (e) {
      e.stopPropagation();
      const $dd = $('#wishlist-dropdown');

      if ($dd.is(':visible')) {
        $dd.slideUp(150);
        return;
      }

      $dd.slideDown(180);

      // Carrega as listas (só na primeira vez)
      if (!listasCarregadas) carregarListas();
    });

    // Fecha ao clicar fora
    $(document).on('click', function (e) {
      if (!$(e.target).closest('#wishlist-btn-wrap').length) {
        $('#wishlist-dropdown').slideUp(150);
        fecharNovaListaForm();
      }
    });
    $(document).on('click', '#btn-wishlist-close', function () {
      $('#wishlist-dropdown').slideUp(150);
    });

    // ── Carrega as listas do cliente ─────────────────────
    

    function carregarListas() {
        // Usa dados já carregados pelo PHP (sem Ajax extra)
        if (typeof window.WISHLIST_LISTAS !== 'undefined') {
            if (!window.WISHLIST_LISTAS.length) {
                $('#wishlist-listas').html(
                    '<p class="wishlist-vazia">Nenhuma lista ainda. Crie uma abaixo!</p>'
                );
            }
            // HTML já foi renderizado pelo PHP, só marca como carregado
            listasCarregadas = true;
            return;
        }

        // Fallback: carrega via Ajax se não tiver no PHP
        $('#wishlist-listas').html('<div class="wishlist-loading">Carregando...</div>');

        $.get(BASE_URL + '/favoritos/verificar', { produto_id: produtoId }, function (res) {
            listasCarregadas = true;

            if (res.nao_logado) {
                $('#wishlist-listas').html(`
                  <div class="wishlist-login-aviso">
                    <p>${res.msg}</p>
                    <a href="${BASE_URL}/login?redirect=${encodeURIComponent(window.location.pathname)}"
                      class="btn btn-primary btn-sm btn-full">Fazer login</a>
                  </div>
                `);
                $('#btn-nova-lista').hide();
                return;
            }

            renderizarListas(res.listas);
        }, 'json');
    }

    function renderizarListas(listas) {
      if (!listas.length) {
        $('#wishlist-listas').html(
          '<p class="wishlist-vazia">Nenhuma lista ainda. Crie uma abaixo!</p>'
        );
        return;
      }

      let html = '';
      listas.forEach(function (lista) {
        const tem    = lista.tem_produto;
        html += `
          <label class="wishlist-lista-item ${tem ? 'wishlist-lista-item--ativa' : ''}"
                data-lista-id="${lista.id}">
            <input type="checkbox"
                  class="wishlist-lista-check"
                  data-lista-id="${lista.id}"
                  ${tem ? 'checked' : ''}>
            <span class="wishlist-check-custom"></span>
            <span class="wishlist-lista-nome">${lista.nome}</span>
            ${tem
              ? '<span class="wishlist-lista-badge">Salvo</span>'
              : ''}
          </label>`;
      });
      $('#wishlist-listas').html(html);
    }

    function renderItemBody(id, block_list = false) {
      // console.log(id);
      console.log(id);
        //block_list é para evitar que quando clicado na lista, ele vai tentar marcar na lista novamente, pois isso já é feito diretamente no click da checkbox, então nesse caso ele só atualiza o botão principal e o dropdown, sem tentar marcar/desmarcar nada
        $('body').find('.wishlist-control').each(function () {
            if ($(this).data('list-id') == id) {
                
              // Atualiza visual
              $(this).toggleClass('active');
              const ativo = $(this).hasClass('active');
              // Atualiza SVG fill
              $(this).find('svg').attr('fill', ativo ? 'currentColor' : 'none');

              if($(this).hasClass('hab-text')){
                // Atualiza label (se existir)
                $(this).find('.btn-favorito-label').text(
                    ativo ? 'Nos seus favoritos' : 'Favoritar'
                );          
                // Atualiza aria-label
                $(this).attr('aria-label',
                    ativo ? 'Remover dos favoritos' : 'Adicionar aos favoritos'
                );
              } 
            }
        });

        if(block_list) return;

        if($('body').find('.wishlist-btn-wrap').length > 0){
          $('body').find('.wishlist-btn-wrap .wishlist-lista-item').each(function () {
            if ($(this).data('lista-id') == id) {
              let check = $(this).find('.wishlist-lista-check');
              let marcar = check.is(':checked');

              console.log(marcar);
              

              const $item = $(this);
              if (!marcar) {
                check.prop('checked', true);
                $item.addClass('wishlist-lista-item--ativa');
                if (!$item.find('.wishlist-lista-badge').length) {
                  $item.append('<span class="wishlist-lista-badge">Salvo</span>');
                  check
                }
              } else {
                check.prop('checked', false);
                $item.removeClass('wishlist-lista-item--ativa')
                    .find('.wishlist-lista-badge').remove();
              }
            }
          });
        }
    }

    // ── Marcar/desmarcar lista ────────────────────────────
    $(document).on('change', '.wishlist-lista-check', function () {
      const $cb     = $(this);
      const listaId = $cb.data('lista-id');
      const marcar  = $cb.is(':checked');
      const url     = marcar
        ? BASE_URL + '/favoritos/item/adicionar'
        : BASE_URL + '/favoritos/item/remover';

      const dados = { _csrf_token: CSRF_TOKEN };
      if (marcar) {
        dados.lista_id   = listaId;
        dados.produto_id = produtoId;
      } else {
        // Para remover, precisa do item_id
        // Busca o item_id via Ajax
        $.get(BASE_URL + '/favoritos/itens', { lista_id: listaId }, function (res) {
          if (!res.ok) return;
          const item = res.itens.find(i => i.produto_id == produtoId);
          if (!item) return;

          $.post(BASE_URL + '/favoritos/item/remover', {
            item_id     : item.item_id,
            _csrf_token : CSRF_TOKEN,
          }, function (r) {
            if (r.ok) {
              $cb.closest('.wishlist-lista-item')
                .removeClass('wishlist-lista-item--ativa')
                .find('.wishlist-lista-badge').remove();
              showToast(r.msg, 'info');
              atualizarBotaoPrincipal();
              renderItemBody(listaId, true)
            }
          }, 'json');
        }, 'json');
        return;
      }

      $.post(url, dados, function (res) {
        if (res.nao_logado) {
          showToast(res.msg, 'info');
          $cb.prop('checked', false);
          return;
        }

        if (!res.ok) {
          showToast(res.msg, 'error');
          $cb.prop('checked', !marcar);
          return;
        }

        const $item = $cb.closest('.wishlist-lista-item');
        if (marcar) {
          $item.addClass('wishlist-lista-item--ativa');
          if (!$item.find('.wishlist-lista-badge').length) {
            $item.append('<span class="wishlist-lista-badge">Salvo</span>');
          }
        } else {
          $item.removeClass('wishlist-lista-item--ativa')
              .find('.wishlist-lista-badge').remove();
        }

        showToast(res.msg, marcar ? 'success' : 'info');
        atualizarBotaoPrincipal();
        renderItemBody(listaId, true)
      }, 'json');
    });

    function atualizarBotaoPrincipal() {
      return false;
      const temAlgum = $('.wishlist-lista-check:checked').length > 0;
      const $btn     = $('#btn-wishlist-toggle');

      if (temAlgum) {
        $btn.addClass('wishlist-btn--ativa');
        $('#wishlist-btn-label').text('Salvo nos favoritos');
        $btn.find('svg').first().css('fill', 'var(--c-primary)');
      } else {
        $btn.removeClass('wishlist-btn--ativa');
        $('#wishlist-btn-label').text('Adicionar à lista');
        $btn.find('svg').first().css('fill', 'none');
      }
    }

    // ── Criar nova lista inline ───────────────────────────
    $(document).on('click', '#btn-nova-lista', function () {
      $(this).hide();
      $('#wishlist-nova-form').slideDown(180);
      setTimeout(() => $('#wishlist-nova-nome').focus(), 100);
    });

    $(document).on('click', '#btn-nova-lista-cancelar', function () {
      fecharNovaListaForm();
    });

    function fecharNovaListaForm() {
      $('#wishlist-nova-form').slideUp(150);
      $('#btn-nova-lista').show();
      $('#wishlist-nova-nome').val('');
    }

    $(document).on('click', '#btn-nova-lista-salvar', function () {
      const nome = $('#wishlist-nova-nome').val().trim();
      if (!nome) {
        $('#wishlist-nova-nome').focus();
        return;
      }

      $(this).prop('disabled', true).text('...');

      $.post(BASE_URL + '/favoritos/criar', {
        nome        : nome,
        _csrf_token : CSRF_TOKEN,
      }, function (res) {
        $('#btn-nova-lista-salvar').prop('disabled', false).text('Criar');

        if (!res.ok) {
          showToast(res.msg, 'error');
          return;
        }

        // Adiciona o produto na lista recém criada
        $.post(BASE_URL + '/favoritos/item/adicionar', {
          lista_id    : res.lista_id,
          produto_id  : produtoId,
          _csrf_token : CSRF_TOKEN,
        }, function () {
          fecharNovaListaForm();
          listasCarregadas = false; // força reload das listas
          carregarListas();
          showToast('Lista "' + res.nome + '" criada e produto adicionado!', 'success');
          atualizarBotaoPrincipal();
        }, 'json');
      }, 'json');
    });

    // Enter no input de nova lista
    $(document).on('keydown', '#wishlist-nova-nome', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        $('#btn-nova-lista-salvar').trigger('click');
      }
    });

    $(document).on('click', '.btn-favorito', function (e) {
      e.preventDefault();
      e.stopPropagation();

      if($('.product-page').length <= 0) {
        return false; // Deixa o comportamento normal do botão na página de produto
      }

      const $btn      = $(this);
      const produtoId = $btn.data('product-id');
      const list_id   = $btn.data('list-id');
      const isActive  = $btn.hasClass('active');
      const url       = isActive
                        ? BASE_URL + '/favoritos/desfavoritar'
                        : BASE_URL + '/favoritos/favoritar';

      $btn.prop('disabled', true);

      $.post(url, {
          produto_id  : produtoId,
          _csrf_token : CSRF_TOKEN,
      }, function (res) {
          $btn.prop('disabled', false);

          // Não logado
          if (res.nao_logado) {
              showToast(res.msg, 'info', {
                  label : 'Fazer login',
                  url   : BASE_URL + '/login?redirect='
                          + encodeURIComponent(window.location.pathname),
              });
              return;
          }

          if (!res.ok) {
              showToast(res.msg, 'error');
              return;
          }

          renderItemBody(list_id)

          // // Atualiza visual
          // $btn.toggleClass('active');
          const ativo = $btn.hasClass('active');

          // // Atualiza SVG fill
          // $btn.find('svg').attr('fill', ativo ? 'currentColor' : 'none');

          // // Atualiza label (se existir)
          // $btn.find('.btn-favorito-label').text(
          //     ativo ? 'Nos seus favoritos' : 'Favoritar'
          // );          

          // // Atualiza aria-label
          // $btn.attr('aria-label',
          //     ativo ? 'Remover dos favoritos' : 'Adicionar aos favoritos'
          // );

          showToast(res.msg, ativo ? 'success' : 'info');

      }, 'json').fail(function () {
          $btn.prop('disabled', false);
          showToast('Erro de conexão.', 'error');
      });
  });

  })();  
  

  $(function(){
    
    // ── LIGHTBOX ──
    var $lb = $('#pdx-lb');
    if ($lb.length) {
      var imgs = window.PDX_IMAGES || [];
      var cur = 0;
      var $lbImg = $('#pdx-lb-img'), $lbCount = $('#pdx-lb-count');
      function lbGo(i){
        if(!imgs.length) return;
        cur = (i + imgs.length) % imgs.length;
        $lbImg.attr('src', imgs[cur]);
        $lbCount.text((cur+1) + ' / ' + imgs.length);
        $('#pdx-lb-strip .pdx-lb-thumb').removeClass('active').eq(cur).addClass('active');
      }
      function lbOpen(i){ lbGo(i); $lb.addClass('open'); $('body').css('overflow','hidden'); }
      function lbClose(){ $lb.removeClass('open'); $('body').css('overflow',''); }

      // Abre: clique na imagem principal ou no tile "+N"
      $('#zoom-wrapper').on('click', function(){ lbOpen(0); });
      $(document).on('click', '.pdx-thumb-more', function(e){
        e.preventDefault(); e.stopPropagation();
        lbOpen(parseInt($(this).data('index'), 10) || 0);
      });
      $('#pdx-lb-strip').on('click', '.pdx-lb-thumb', function(){ lbGo(parseInt($(this).data('index'),10)); });
      $('#pdx-lb-prev').on('click', function(){ lbGo(cur-1); });
      $('#pdx-lb-next').on('click', function(){ lbGo(cur+1); });
      $('#pdx-lb-close').on('click', lbClose);
      $lb.on('click', function(e){ if(e.target === this || $(e.target).hasClass('pdx-lb-main')) lbClose(); });
      $(document).on('keydown', function(e){
        if(!$lb.hasClass('open')) return;
        if(e.key === 'Escape') lbClose();
        if(e.key === 'ArrowLeft') lbGo(cur-1);
        if(e.key === 'ArrowRight') lbGo(cur+1);
      });
    }

    // ── MODAL DE PAGAMENTO ──
    var $pay = $('#pdx-pay-modal');
    function payOpen(){ $pay.addClass('open'); $('body').css('overflow','hidden'); }
    function payClose(){ $pay.removeClass('open'); $('body').css('overflow',''); }
    $('#pdx-open-pay').on('click', payOpen);
    $('#pdx-pay-x').on('click', payClose);
    $('#pdx-pay-back').on('click', payClose);
    $(document).on('keydown', function(e){ if(e.key === 'Escape' && $pay.hasClass('open')) payClose(); });

    // ── VER MAIS (descrição) ──
    var $coll = $('#pdx-desc-collapse'), $more = $('#pdx-desc-more');
    if ($coll.length) {
      // esconde o botão se a descrição já couber
      if ($coll[0].scrollHeight <= $coll[0].clientHeight + 8) {
        $more.hide(); $coll.find('.pdx-collapse-fade').hide();
      }
      $more.on('click', function(){
        var open = $coll.toggleClass('open').hasClass('open');
        $more.toggleClass('open', open);
        $more.contents().first()[0].nodeValue = open ? 'Ver menos ' : 'Ver descrição completa';
      });
    }
  });
});