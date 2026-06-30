(function () {
  const origem = typeof origemCoupomApi !== 'undefined' ? origemCoupomApi : 'checkout;';
  // ── Aplicar ──────────────────────────────────────────
  var $btn   = document.getElementById('coupon-btn-aplicar');
  var $input = document.getElementById('coupon-field');
  var $msg   = document.getElementById('coupon-msg');

  function aplicar() {
    var code = ($input ? $input.value : '').trim().toUpperCase();
    if (!code) { setMsg('error', 'Informe o código do cupom.'); return; }

    setMsg('loading', 'Verificando…');
    if ($btn) $btn.disabled = true;

    CK.post('/cupom/aplicar', { codigo: code, origem: origem })
      .done(function (res) {
        if (res.ok) {
          setMsg('success', res.msg || 'Cupom aplicado!');
          // Recarrega o bloco para mostrar badge
          setTimeout(function () { window.location.reload(); }, 600);
        } else {
          setMsg('error', res.msg || 'Cupom inválido.');
          if ($btn) $btn.disabled = false;
        }
      })
      .fail(function () {
        setMsg('error', 'Erro de conexão. Tente novamente.');
        if ($btn) $btn.disabled = false;
      });
  }

  if ($btn) $btn.addEventListener('click', aplicar);
  if ($input) {
    $input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); aplicar(); }
    });
    $input.addEventListener('input', function () {
      this.value = this.value.toUpperCase();
    });
  }

  // ── Remover ──────────────────────────────────────────
  var $remover = document.getElementById('coupon-btn-remover');
  if ($remover) {
    $remover.addEventListener('click', function () {
      CK.post('/cupom/remover', {})
        .done(function () { window.location.reload(); })
        .fail(function () { Toast.error('Erro ao remover cupom.'); });
    });
  }

  // ── Helper de mensagem ───────────────────────────────
  function setMsg(type, text) {
    if (!$msg) return;
    $msg.className = 'coupon-msg coupon-msg--' + type;
    $msg.textContent = text;
  }
})();

/**
 * cart-promo-preview.js
 * Renderiza cards de promoções com visual específico por tipo.
 *
 * Tipos suportados:
 *   desconto_progressivo — barra de progresso + faixa ativa
 *   frete_gratis         — badge GRÁTIS + barra de valor
 *   brinde               — nome do produto + badge BRINDE
 *   compre_ganhe         — "Compre X pague Y" + barra de itens
 *   cashback             — visual roxo distinto (não é desconto)
 *
 * API pública: CartPromoPreview.atualizar(imediato?)
 */
window.CartPromoPreview = (function () {
  'use strict';

  var BASE  = (window.AUTH_CONFIG && window.AUTH_CONFIG.baseUrl) ||
              (window.CART_CONFIG && window.CART_CONFIG.base)    || '';
  var timer = null;

  /* ─── SVG Icons ─────────────────────────────────── */
  var ICO = {
    check:   '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>',
    trend:   '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>',
    truck:   '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>',
    gift:    '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></svg>',
    bag:     '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 2 3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>',
    coin:    '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v2m0 8v2M9.5 9a2.5 2.5 0 015 0c0 1.5-2.5 2-2.5 3m0 2h.01"/></svg>',
    info:    '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
  };

  /* ─── Barra de progresso ─────────────────────────── */
  function progressBar(pct) {
    return '<div class="promo-progress-wrap">' +
      '<div class="promo-progress-track">' +
      '<div class="promo-progress-bar" style="width:' + pct + '%"></div>' +
      '</div>' +
      '<span class="promo-progress-pct">' + pct + '%</span>' +
      '</div>';
  }

  /* ─── Renderizadores por tipo ────────────────────── */

  function renderDescontoProgressivo(card) {
    var estado = card.estado;
    var icon   = estado === 'aplicada' ? ICO.check : ICO.trend;
    var prog   = card.progresso_pct || 0;

    var faixaBadge = '';
    if (card.faixa_atual) {
      faixaBadge = '<div class="promo-faixa-badge">Ativo: ' +
        escHtml(String(card.faixa_atual.pct)) + '% de desconto</div>';
    }
    var descHtml = card.desconto_fmt
      ? '<span class="promo-card-desconto">−' + escHtml(card.desconto_fmt) + '</span>' : '';

    var html = '<div class="promo-card promo-card--' + escHtml(estado) + '">' +
      '<div class="promo-card-header">' +
      '<span class="promo-card-icon">' + icon + '</span>' +
      '<span class="promo-card-text">' + escHtml(card.msg) + faixaBadge + '</span>' +
      descHtml +
      '</div>';

    if (estado !== 'aplicada' || card.proxima_faixa) {
      html += progressBar(prog);
    }
    return html + '</div>';
  }

  function renderFreteGratis(card) {
    var estado  = card.estado;
    var prog    = card.progresso_pct || 0;
    var applied = estado === 'aplicada';

    var rightHtml = applied
      ? '<span class="promo-badge-gratis">GRÁTIS</span>'
      : (card.desconto_fmt ? '<span class="promo-card-desconto">−' + escHtml(card.desconto_fmt) + '</span>' : '');

    var html = '<div class="promo-card promo-card--' + escHtml(estado) + '">' +
      '<div class="promo-card-header">' +
      '<span class="promo-card-icon">' + ICO.truck + '</span>' +
      '<span class="promo-card-text">' + escHtml(card.msg) + '</span>' +
      rightHtml +
      '</div>';

    if (!applied) html += progressBar(prog);
    return html + '</div>';
  }

  function renderBrinde(card) {
    var estado  = card.estado;
    var prog    = card.progresso_pct || 0;
    var applied = estado === 'aplicada';

    // Monta nome do produto brinde
    var brindeName = '';
    if (card.brindes && card.brindes.length) {
      var b = card.brindes[0];
      var qtdLabel = b.quantidade > 1 ? b.quantidade + '× ' : '';
      brindeName = '<br><span class="promo-brinde-nome">' +
        escHtml(qtdLabel + b.nome) + '</span>' +
        '<span class="promo-badge-brinde">BRINDE</span>';
    }

    var html = '<div class="promo-card promo-card--' + escHtml(estado) + '">' +
      '<div class="promo-card-header">' +
      '<span class="promo-card-icon">' + ICO.gift + '</span>' +
      '<span class="promo-card-text">' +
      escHtml(card.msg) + /*brindeName +*/
      '</span>' +
      '</div>';

    if (!applied) html += progressBar(prog);
    return html + '</div>';
  }

  function renderCompraGanhe(card) {
    var estado   = card.estado;
    var prog     = card.progresso_pct || 0;
    var applied  = estado === 'aplicada';
    var pct      = card.desconto_pct || 100;
    var labelPct = pct >= 100 ? 'GRÁTIS' : pct + '% OFF';

    var descHtml = card.desconto_fmt && applied
      ? '<span class="promo-card-desconto">−' + escHtml(card.desconto_fmt) + '</span>' : '';

    var html = '<div class="promo-card promo-card--' + escHtml(estado) + '">' +
      '<div class="promo-card-header">' +
      '<span class="promo-card-icon">' + ICO.bag + '</span>' +
      '<span class="promo-card-text">' + escHtml(card.msg) + '</span>' +
      descHtml +
      '</div>';

    // Quando aplicado, mostra qual item específico recebe o desconto.
    // O nome é buscado do DOM ($.cart-item[data-produto-id]) — sem
    // chamada de API extra. Fallback para "Produto #X" se não achar.
    if (applied && card.itens_desconto && card.itens_desconto.length) {
      var detalheHtml = '<div class="promo-cg-detalhe">';
      card.itens_desconto.forEach(function (item) {
        var $el   = $('.cart-item[data-produto-id="' + item.produto_id + '"]');
        var nome  = $el.find('.cart-item-name').first().text().trim() ||
                    'Produto #' + item.produto_id;
        var qtdLabel = item.qtd_desconto > 1 ? item.qtd_desconto + '× ' : '';
        detalheHtml +=
          '<div class="promo-cg-detalhe-row">' +
          '  <span class="promo-cg-item-nome">' + escHtml(qtdLabel + nome) + '</span>' +
          '  <span class="promo-cg-badge-' + (pct >= 100 ? 'gratis' : 'off') + '">' +
               escHtml(labelPct) + '</span>' +
          '</div>';
      });
      detalheHtml += '</div>';
      html += detalheHtml;
    }

    if (!applied) html += progressBar(prog);
    return html + '</div>';
  }

  function renderCashback(card) {
    // Cashback tem visual DISTINTO — não é desconto, é crédito futuro.
    // Usa classe promo-card--cashback (roxa) independente do estado.
    var valorFmt  = card.cashback_fmt || '';
    var validade  = card.validade_dias
      ? '<div class="promo-cashback-meta">⏱ Crédito com validade de ' +
        escHtml(String(card.validade_dias)) + ' dias após liberação</div>'
      : '';

    return '<div class="promo-card promo-card--cashback">' +
      '<div class="promo-card-header">' +
      '<span class="promo-card-icon">' + ICO.coin + '</span>' +
      '<span class="promo-card-text">' + escHtml(card.msg) + '</span>' +
      (valorFmt ? '<span class="promo-cashback-valor">+' + escHtml(valorFmt) + '</span>' : '') +
      '</div>' +
      validade +
      '</div>';
  }

  /* ─── Dispatcher ─────────────────────────────────── */
  function renderCard(card) {
    switch (card.tipo) {
      case 'frete_gratis':         return renderFreteGratis(card);
      case 'brinde':               return renderBrinde(card);
      case 'compre_ganhe':         return renderCompraGanhe(card);
      case 'cashback':             return renderCashback(card);
      case 'desconto_progressivo':
      default:                     return renderDescontoProgressivo(card);
    }
  }

  /* ─── Totais ─────────────────────────────────────── */
  function fmtBrl(valor) {
    return 'R$ ' + Math.abs(valor).toFixed(2)
      .replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  }

  function atualizarTotais(cards) {
    var descontoPromo = 0;
    var cashbackTotal = 0;

    cards.forEach(function (card) {
      if (card.tipo === 'cashback') {
        // Cashback não reduz o total — mostra linha informativa separada
        cashbackTotal += card.cashback_valor || 0;
      } else if (card.estado === 'aplicada' || card.estado === 'proxima_faixa') {
        descontoPromo += card.desconto || 0;
      }
    });
    descontoPromo = Math.round(descontoPromo * 100) / 100;
    cashbackTotal = Math.round(cashbackTotal * 100) / 100;

    /* Carrinho — linha de desconto */
    var promoRow = document.getElementById('summary-promo-row');
    var promoVal = document.getElementById('summary-promo');
    if (promoRow) {
      promoRow.style.display = descontoPromo > 0 ? '' : 'none';
      if (promoVal && descontoPromo > 0) promoVal.textContent = '− ' + fmtBrl(descontoPromo);
    }

    /* Checkout sidebar — linha de desconto */
    var ckRowPromo  = document.getElementById('ck-row-promo');
    var ckPromo     = document.getElementById('ck-promo');
    var ckTotal     = document.getElementById('ck-total');
    var ckTotalBase = document.getElementById('ck-total-base');

    if (ckRowPromo) {
      if (descontoPromo > 0) {
        ckRowPromo.style.display = '';
        if (ckPromo) ckPromo.textContent = '−' + fmtBrl(descontoPromo);
        if (ckTotal && ckTotalBase) {
          var base = parseFloat(ckTotalBase.value) || 0;
          var ckCredEl = document.getElementById('ck-credito');
          var credito  = ckCredEl
            ? (parseFloat(ckCredEl.textContent.replace(/[^\d,]/g, '').replace(',', '.')) || 0)
            : 0;
          ckTotal.textContent = fmtBrl(Math.max(0, base - descontoPromo - credito));
        }
      } else {
        ckRowPromo.style.display = 'none';
        if (ckTotal && ckTotalBase) {
          ckTotal.textContent = fmtBrl(Math.max(0, parseFloat(ckTotalBase.value) || 0));
        }
      }
    }

    /* Checkout sidebar — linha de cashback (informativa, não deduz o total) */
    var ckRowCb = document.getElementById('ck-row-cashback');
    var ckCb    = document.getElementById('ck-cashback');
    if (ckRowCb) {
      ckRowCb.style.display = cashbackTotal > 0 ? '' : 'none';
      if (ckCb && cashbackTotal > 0) {
        ckCb.textContent = '+' + fmtBrl(cashbackTotal) + ' em créditos';
      }
    }

    /* Evento para o JS do carrinho integrar o desconto no total */
    document.dispatchEvent(new CustomEvent('cart:promo-atualizada', {
      detail: { desconto: descontoPromo, cashback: cashbackTotal },
    }));
  }

  /* ─── Badges nos itens do carrinho ──────────────── */
  function atualizarBadgesItens(cards) {
    document.querySelectorAll('.promo-item-badge').forEach(function (el) { el.remove(); });

    cards.forEach(function (card) {
      if (card.estado !== 'aplicada') return;

      if (card.tipo === 'compre_ganhe') {
        // Badge APENAS nos itens que realmente recebem o desconto
        // (os Y mais baratos), não em todos os elegíveis.
        var pct      = card.desconto_pct || 100;
        var labelPct = pct >= 100 ? 'GRÁTIS' : pct + '% OFF';
        (card.itens_desconto || []).forEach(function (item) {
          var $el = $('.cart-item[data-produto-id="' + item.produto_id + '"]');
          if (!$el.length) return;
          var qtdLabel = item.qtd_desconto > 1 ? item.qtd_desconto + '× ' : '';
          var span = document.createElement('span');
          span.className = 'promo-item-badge promo-item-badge--compre_ganhe';
          span.textContent = qtdLabel + labelPct;
          var nome = $el.find('.cart-item-name')[0];
          if (nome) nome.insertAdjacentElement('afterend', span);
        });
        return;
      }

      // Outros tipos — usa itens_elegiveis
      var label      = '';
      var extraClass = '';
      if (card.tipo === 'desconto_progressivo' && card.faixa_atual) {
        label = card.faixa_atual.pct + '% OFF';
      } else if (card.tipo === 'frete_gratis') {
        label = 'Frete grátis';
      } else if (card.tipo === 'brinde') {
        return; // brinde tem item próprio na lista
      } else if (card.tipo === 'cashback') {
        label = 'CB ' + (card.cashback_pct || '') + '%';
        extraClass = 'promo-item-badge--cashback';
      } else {
        label = 'Promoção';
      }

      (card.itens_elegiveis || []).forEach(function (pid) {
        var $el = document.querySelector('.cart-item[data-produto-id="' + pid + '"]');
        if (!$el) return;
        var span = document.createElement('span');
        span.className = 'promo-item-badge' + (extraClass ? ' ' + extraClass : '');
        span.textContent = label;
        var nome = $el.querySelector('.cart-item-name');
        if (nome) nome.insertAdjacentElement('afterend', span);
      });
    });
  }

  /* ─── Brindes na lista de itens do carrinho ─────── */

  /**
   * Insere (ou atualiza) itens de brinde diretamente dentro de
   * qualquer container .set-itens-cart da página.
   *
   * O brinde aparece como um .cart-item real, usando o mesmo grid
   * de colunas dos itens normais. Quantidade/remover ficam
   * desabilitados — é um item promocional, não editável.
   *
   * O selector .set-itens-cart deve ser adicionado na view de
   * carrinho e checkout (#cart-items-list ou equivalente).
   */
  function adicionarItensBrinde(cards) {
    var $containers = $('.set-itens-cart');
    if (!$containers.length) return;

    // Remove brindes anteriores (atualização limpa)
    $containers.find('.cart-item--brinde').remove();

    // Coleta brindes de cards aplicados
    var brindes = [];
    cards.forEach(function (card) {
      if (card.tipo === 'brinde' && card.estado === 'aplicada' && card.brindes && card.brindes.length) {
        card.brindes.forEach(function (b) {
          brindes.push({ brinde: b, promocao: card.promocao_nome });
        });
      }
    });

    if (!brindes.length) return;

    brindes.forEach(function (entry) {
      var b   = entry.brinde;
      var qtd = b.quantidade || 1;

      // Imagem
      var imgHtml = b.imagem_url
        ? '<img src="' + escHtml(b.imagem_url) + '" alt="' + escHtml(b.nome) + '" loading="lazy" width="100" height="100">'
        : '<img src="" alt="' + escHtml(b.nome) + '" style="background:#f1f5f9;width:100px;height:100px;display:block;">';

      // Botão de quantidade — desabilitado
      var qtyHtml = '<div class="cart-qty-control">' +
        '<button class="qty-btn cart-qty-minus" disabled>−</button>' +
        '<input type="number" class="cart-qty-input" value="' + qtd + '" readonly style="pointer-events:none;">' +
        '<button class="qty-btn cart-qty-plus" disabled>+</button>' +
        '</div>';

      var $item = $(
        '<div class="cart-item cart-item--brinde" data-brinde-promo="1">' +

        /* Checkbox area */
        '<label class="cart-item-check">' +
        '  <span class="cart-check-custom"></span>' +
        '</label>' +

        /* Imagem */
        '<div class="cart-item-img">' +
        imgHtml +
        (qtd > 1 ? '<span class="brinde-img-badge">' + qtd + '</span>' : '') +
        '</div>' +

        /* Info */
        '<div class="cart-item-info">' +
        '  <span class="cart-item-name">' + escHtml(b.nome) + '</span>' +
        '  <span class="cart-item-sku" style="color:#16a34a;font-weight:600;font-size:12px;">🎁 ' + escHtml(entry.promocao) + '</span>' +
        /* Ações mobile desabilitadas */
        '  <div class="cart-item-actions-mobile">' +
        '    ' + qtyHtml +
        '    <button class="cart-item-remove" disabled style="opacity:.35;cursor:not-allowed;">' +
        '      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg>' +
        '      Brinde</button>' +
        '  </div>' +
        '</div>' +

        /* Quantidade (desktop) */
        '<div class="cart-item-qty">' + qtyHtml + '</div>' +

        /* Preço */
        '<div class="cart-item-price">' +
        '  <span class="item-price-label">Preço</span>' +
        (b.preco_fmt ? '  <span class="item-price-value">' + escHtml(b.preco_fmt) + ' / un.</span>' : '') +
        '  <span class="brinde-gratis-label">GRÁTIS</span>' +
        '</div>' +

        /* Subtotal */
        '<div class="cart-item-subtotal">' +
        '  <span class="item-price-label">Subtotal</span>' +
        '  <span class="item-subtotal-value">GRÁTIS</span>' +
        '</div>' +

        /* Remover (desktop) - desabilitado */
        '<div class="cart-item-remove-col">' +
        '  <button class="cart-item-remove-btn" disabled title="Item de brinde — não pode ser removido">' +
        '    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>' +
        '  </button>' +
        '</div>' +

        '</div>'
      );

      $containers.each(function () {
        $(this).append($item.clone(true));
      });
    });
  }

  /* ─── Render principal ───────────────────────────── */
  function render(cards) {
    var container = document.getElementById('promo-preview-cards');
    if (!container) return;

    if (!cards || !cards.length) {
      container.innerHTML = '';
      atualizarTotais([]);
      atualizarBadgesItens([]);
      adicionarItensBrinde([]);
      return;
    }
    container.innerHTML = cards.map(renderCard).join('');
    atualizarTotais(cards);
    atualizarBadgesItens(cards);
    adicionarItensBrinde(cards);
  }

  /* ─── Fetch ──────────────────────────────────────── */
  function buscar() {
    fetch(BASE + '/promocoes/preview', {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
    })
    .then(function (r) { return r.json(); })
    .then(function (data) { if (data.ok) render(data.cards); })
    .catch(function () { /* silencioso */ });
  }

  function escHtml(str) {
    return String(str || '')
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function atualizar(imediato) {
    clearTimeout(timer);
    if (imediato) { buscar(); } else { timer = setTimeout(buscar, 400); }
  }

  document.addEventListener('DOMContentLoaded', function () { buscar(); });

  return { atualizar: atualizar };
})();