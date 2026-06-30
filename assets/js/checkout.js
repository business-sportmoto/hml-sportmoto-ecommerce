/**
 * checkout-frete.js
 *
 * Renderiza opções de frete como cards premium.
 *
 * Recebe array de fretes (do servidor) com formato:
 *   [{ id, nome, prazo, valor, tipo? }]
 *
 * Calcula badges automaticamente:
 *   - "Grátis"        quando valor === 0
 *   - "Melhor preço"  quando é o mais barato e não é grátis
 *   - "Mais rápido"   quando é o de menor prazo
 *   - "Retirada"      quando tipo === 'retirada' OR nome contém 'retir'
 *
 * Atualiza o resumo do pedido em tempo real e libera o próximo passo.
 *
 * API pública:
 *   FreteUI.render(opcoes, cepFormatado)
 *   FreteUI.clear()
 *   FreteUI.getSelected()
 */

/**
   * cart-promo-preview.js
   * Busca e renderiza cards de promoções ativas no carrinho.
   *
   * Integração: chamar CartPromoPreview.atualizar() sempre que o
   * carrinho mudar (item adicionado, quantidade alterada, cupom
   * aplicado/removido). Também roda automaticamente no DOMContentLoaded.
   *
   * Depende de window.CART_CONFIG.baseUrl (já exposto pelo layout do
   * carrinho via window.AUTH_CONFIG.baseUrl ou similar).
   */
  

(function ($, window) {
  'use strict';

  const BASE = window.BASE_URL || '';

    

  // ── Helpers ─────────────────────────────────────────
  function formatBRL(v) {
    return 'R$ ' + Number(v).toFixed(2).replace('.', ',');
  }

  function formatPrazo(dias) {
    if (!dias || dias <= 0) return 'Disponível agora';
    if (dias === 1) return '1 dia útil';
    return `${dias} dias úteis`;
  }

  function freteIcone(opt) {
    if (opt.tipo === 'retirada' || /retir/i.test(opt.nome || '')) {
      // ícone loja
      return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M3 9v11a2 2 0 002 2h14a2 2 0 002-2V9"/>
        <path d="M5 3h14l2 6H3l2-6z"/>
        <path d="M9 22V12h6v10"/>
      </svg>`;
    }
    if (Number(opt.valor) === 0) {
      // ícone presente (grátis)
      return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
        <polyline points="20 12 20 22 4 22 4 12"/>
        <rect x="2" y="7" width="20" height="5" rx="1"/>
        <line x1="12" y1="22" x2="12" y2="7"/>
        <path d="M12 7H7.5a2.5 2.5 0 010-5C11 2 12 7 12 7z"/>
        <path d="M12 7h4.5a2.5 2.5 0 000-5C13 2 12 7 12 7z"/>
      </svg>`;
    }
    // ícone caminhão padrão
    return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
      <rect x="1" y="3" width="15" height="13" rx="1"/>
      <path d="M16 8h4l3 3v5h-7V8z"/>
      <circle cx="5.5" cy="18.5" r="2.5"/>
      <circle cx="18.5" cy="18.5" r="2.5"/>
    </svg>`;
  }

  // Calcula badges aplicáveis a uma opção
  function calcularBadge(opt, opcoes) {
    const valores = opcoes.map(o => Number(o.valor)).filter(v => !isNaN(v));
    const prazos  = opcoes.map(o => parseInt(o.prazo) || 999).filter(p => p < 999);
    const isFree    = Number(opt.valor) === 0;
    const isPickup  = opt.tipo === 'retirada' || /retir/i.test(opt.nome || '');
    const isFastest = prazos.length > 1 && parseInt(opt.prazo) === Math.min(...prazos);
    const isCheapest= !isFree && valores.length > 1 && Number(opt.valor) === Math.min(...valores.filter(v => v > 0));

    // Prioridade: pickup > free > fastest > cheapest
    if (isPickup) {
      return {
        cls: 'frete-badge--pickup',
        txt: 'Retirada na loja',
        icon: '<svg viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="12" r="4"/></svg>',
      };
    }
    if (isFree) {
      return {
        cls: 'frete-badge--free',
        txt: 'Frete grátis',
        icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>',
      };
    }
    if (isFastest) {
      return {
        cls: 'frete-badge--fast',
        txt: 'Mais rápido',
        icon: '<svg viewBox="0 0 24 24" fill="currentColor"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>',
      };
    }
    if (isCheapest) {
      return {
        cls: 'frete-badge--cheap',
        txt: 'Melhor preço',
        icon: '<svg viewBox="0 0 24 24" fill="currentColor"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
      };
    }
    return null;
  }

  function montarCard(opt, opcoes, index) {
    const badge   = calcularBadge(opt, opcoes);
    const isFree  = Number(opt.valor) === 0;
    const valor   = isFree ? '<span class="frete-valor-num frete-valor-num--free">GRÁTIS</span>'
                           : `<span class="frete-valor-num">${formatBRL(opt.valor)}</span>`;

    const badgeHtml = badge
      ? `<span class="frete-badge ${badge.cls}">${badge.icon} ${badge.txt}</span>`
      : '';

    return `
      <label class="frete-card" data-frete-id="${opt.id}"
             data-frete-valor="${Number(opt.valor)}"
             data-frete-nome="${opt.nome}"
             data-frete-prazo="${opt.prazo}">
        <input type="radio" name="frete_id" value="${opt.id}"
               style="position:absolute;opacity:0;pointer-events:none;" ${index === 0 ? 'checked' : ''}>
        <div class="frete-icon">${freteIcone(opt)}</div>
        <div class="frete-card-body">
          <div class="frete-card-top">
            <span class="frete-card-nome">${opt.nome}</span>
            ${badgeHtml}
          </div>
          <span class="frete-card-prazo">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
              <circle cx="12" cy="12" r="10"/>
              <polyline points="12 6 12 12 16 14"/>
            </svg>
            ${formatPrazo(parseInt(opt.prazo))}
          </span>
        </div>
        <div class="frete-card-valor">${valor}</div>
        <div class="frete-card-check">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round">
            <polyline points="20 6 9 17 4 12"/>
          </svg>
        </div>
      </label>`;
  }

  // ════════════════════════════════════════════════════
  // API pública
  // ════════════════════════════════════════════════════
  window.FreteUI = {

    render(opcoes, cepFormatado) {
      const $cards = $('#frete-cards');
      const $skel  = $('#frete-skeleton');
      const $empty = $('#frete-empty');
      const $btn   = $('#btn-confirm-frete');

      $skel.hide();
      $cards.empty();

      // Atualiza CEP tag
      if (cepFormatado) {
        $('#frete-cep-display').text(cepFormatado);
        $('#frete-cep-tag').show();
      }

      if (!opcoes || !opcoes.length) {
        $empty.show();
        $btn.prop('disabled', true);
        return;
      }

      $empty.hide();

      // Renderiza
      const html = opcoes.map((opt, i) => montarCard(opt, opcoes, i)).join('');
      $cards.html(html);

      // Marca o primeiro como selecionado (já vem checked)
      $cards.find('.frete-card').first().addClass('is-selected');

      // Atualiza resumo com a opção pré-selecionada
      const first = opcoes[0];
      this._updateSummary(first.valor, first.nome, parseInt(first.prazo));

      $btn.prop('disabled', false);
    },

    loading() {
      $('#frete-cards').empty();
      $('#frete-empty').hide();
      $('#frete-skeleton').show();
    },

    clear() {
      $('#frete-cards').empty();
      $('#frete-skeleton, #frete-empty').hide();
      $('#btn-confirm-frete').prop('disabled', true);
      this._updateSummary(0, '', 0);
    },

    getSelected() {
      const $sel = $('.frete-card.is-selected');
      if (!$sel.length) return null;
      return {
        id:    $sel.data('frete-id'),
        nome:  $sel.data('frete-nome'),
        valor: parseFloat($sel.data('frete-valor')),
        prazo: parseInt($sel.data('frete-prazo')) || 0,
      };
    },

    _updateSummary(valor, nome, prazo) {
      // Atualiza o resumo lateral
      const $line = $('#summary-frete');
      const $lineValor = $('#summary-frete-valor');
      if (!$line.length || !$lineValor.length) return;

      if (Number(valor) === 0) {
        $lineValor.html('<span style="color:#16a34a;font-weight:800;">GRÁTIS</span>');
      } else {
        $lineValor.text(formatBRL(valor));
      }

      // Atualiza total
      const subtotal = parseFloat($('#summary-subtotal-raw').val()) || 0;
      const desconto = parseFloat($('#summary-desconto-raw').val()) || 0;
      const total    = Math.max(0, subtotal - desconto + Number(valor));
      $('#summary-total-valor').text(formatBRL(total));
    },
  };

  // ════════════════════════════════════════════════════
  // Eventos
  // ════════════════════════════════════════════════════

  // Seleção de card
  $(document).on('click', '.frete-card', function (e) {
    if ($(e.target).is('input, button, a')) return;

    const $card = $(this);
    if ($card.hasClass('is-selected')) return;

    $('.frete-card').removeClass('is-selected');
    $card.addClass('is-selected');
    $card.find('input[type="radio"]').prop('checked', true);

    FreteUI._updateSummary(
      $card.data('frete-valor'),
      $card.data('frete-nome'),
      parseInt($card.data('frete-prazo'))
    );
  });

  // Trocar CEP — abre o formulário de endereço de novo
  $(document).on('click', '#frete-cep-edit', function () {
    $('#frete-section').slideUp(180);
    $('#address-form').slideDown(220);
    $('html, body').animate({ scrollTop: $('#cep-hero').offset().top - 80 }, 300);
  });

  // Continuar para pagamento
  $('#btn-confirm-frete').on('click', function () {
    const sel = FreteUI.getSelected();
    if (!sel) return;

    const $btn = $(this);
    $btn.addClass('is-loading').prop('disabled', true);

    // Salva escolha no servidor (mantém compatibilidade com selectShipping)
    $.post(BASE + '/checkout/frete', {
      frete_id:   sel.id,
      frete_nome: sel.nome,
      frete_valor:sel.valor,
      frete_prazo:sel.prazo,
      _csrf_token: $('input[name="_csrf_token"]').first().val(),
    }, function (res) {
      $btn.removeClass('is-loading').prop('disabled', false);

      if (res.ok) {
        // Avança para etapa 3 (pagamento)
        $('#section-pagamento').slideDown(220).show();
        $('html, body').animate({
          scrollTop: $('#section-pagamento').offset().top - 80,
        }, 300);
      } else {
        alert(res.msg || 'Erro ao salvar frete.');
      }
    }, 'json').fail(function () {
      $btn.removeClass('is-loading').prop('disabled', false);
      alert('Erro de conexão.');
    });
  });

  // ════════════════════════════════════════════════════
  // Integração com o checkout-address.js
  // Quando o endereço é salvo, busca opções de frete
  // ════════════════════════════════════════════════════
  window.calcularFrete = function (enderecoId, cep) {
    FreteUI.loading();
    $('#frete-section').slideDown(220);

    $.post(BASE + '/checkout/frete/calcular', {
      endereco_id: enderecoId,
      cep: cep,
      _csrf_token: $('input[name="_csrf_token"]').first().val(),
    }, function (res) {
      if (res.ok && res.opcoes) {
        FreteUI.render(res.opcoes, cep);
      } else {
        FreteUI.render([], cep);
      }
    }, 'json').fail(function () {
      FreteUI.render([], cep);
    });
  };

// }(jQuery, window));

/**
 * checkout-core.js
 *
 * Utilitários compartilhados por todas as páginas do checkout.
 * Carregado pelo layout master ANTES dos scripts específicos.
 *
 * Expõe:
 *   CK.post(url, data)             — wrapper de POST com CSRF
 *   CK.get(url, params)            — wrapper de GET
 *   CK.toast(msg, type)            — notificação visual
 *   CK.maskCep($input)             — máscara CEP
 *   CK.maskPhone($input)           — máscara telefone
 *   CK.formatBRL(v)                — formata valor BRL
 *   CK.formAlertSet($el, msg, type)— mostra alerta inline
 *   CK.btnLoading($btn, on)        — toggle loading
 */
// (function ($, window) {
//   'use strict';

  // const BASE = BASE_URL   || '';
  const CSRF = CSRF_TOKEN || '';

  const CK = {

    // ── AJAX wrappers ──────────────────────────────────
    post(url, data = {}) {
      const payload = Object.assign({}, data, { _csrf_token: CSRF });
      return $.ajax({
        url:  url.startsWith('http') ? url : BASE + url,
        type: 'POST',
        data: payload,
        dataType: 'json',
      });
    },

    get(url, params = {}) {
      return $.ajax({
        url:  url.startsWith('http') ? url : BASE + url,
        type: 'GET',
        data: params,
        dataType: 'json',
      });
    },

    // ── Notificações ───────────────────────────────────
    toast(msg, type = 'info') {
      let $stack = $('#ck-toast-stack');
      if (!$stack.length) {
        $stack = $('<div id="ck-toast-stack" class="ck-toast-stack"></div>');
        $('body').append($stack);
      }
      const $t = $(`<div class="ck-toast ck-toast--${type}">${msg}</div>`);
      $stack.append($t);
      setTimeout(() => $t.addClass('is-visible'), 20);
      setTimeout(() => {
        $t.removeClass('is-visible');
        setTimeout(() => $t.remove(), 250);
      }, 3500);
    },

    // ── Máscaras ───────────────────────────────────────
    maskCep($input) {
      if($input.length <= 0) return;
      let v = $input.val().replace(/\D/g, '').slice(0, 8);
      if (v.length > 5) v = v.slice(0, 5) + '-' + v.slice(5);
      $input.val(v);
      return v.replace(/\D/g, '');
    },

    maskPhone($input) {
      let v = $input.val().replace(/\D/g, '').slice(0, 11);
      if (v.length > 10) {
        v = v.replace(/^(\d{2})(\d{5})(\d{0,4}).*/, '($1) $2-$3');
      } else if (v.length > 6) {
        v = v.replace(/^(\d{2})(\d{4})(\d{0,4}).*/, '($1) $2-$3');
      } else if (v.length > 2) {
        v = v.replace(/^(\d{2})(\d{0,5}).*/, '($1) $2');
      }
      $input.val(v.trim().replace(/-$/, ''));
      return v.replace(/\D/g, '');
    },

    // ── Helpers ────────────────────────────────────────
    formatBRL(v) {
      return 'R$ ' + Number(v).toFixed(2).replace('.', ',');
    },

    formAlertSet($el, msg, type = 'error') {
      if (!$el || !$el.length) return;
      $el.removeClass('form-alert--error form-alert--success')
         .addClass(type === 'success' ? 'form-alert--success' : 'form-alert--error')
         .html(msg)
         .show();
    },

    formAlertClear($el) {
      if ($el && $el.length) $el.hide().empty();
    },

    btnLoading($btn, on = true) {
      if (on) {
        $btn.addClass('is-loading')
            .data('original-text', $btn.html())
            .prop('disabled', true);
      } else {
        $btn.removeClass('is-loading')
            .prop('disabled', false);
        const orig = $btn.data('original-text');
        if (orig) $btn.html(orig);
      }
    },
  };

  // ── Bindings globais ─────────────────────────────────
  $(function () {
    // Máscaras automáticas via classe
    $(document).on('input', '.cep-mask',   function () { CK.maskCep($(this)); });
    $(document).on('input', '.phone-mask', function () { CK.maskPhone($(this)); });

    // Toggle senha
    $(document).on('click', '.toggle-password', function () {
      const id   = $(this).data('target');
      const $inp = $('#' + id);
      $inp.attr('type', $inp.attr('type') === 'password' ? 'text' : 'password');
    });

    // Cupom no resumo lateral (usado em /address e /payment)
    $(document).on('click', '#summary-btn-coupon', function () {
      const code = $('#summary-coupon-input').val().trim().toUpperCase();
      const $msg = $('#summary-coupon-msg');
      const $btn = $(this);

      CK.btnLoading($btn);
      CK.post('/checkout/payment/coupon', { cupom: code })
        .done(function (res) {
          if (res.ok) {
            $msg.text(res.msg).removeClass('msg-error').addClass('msg-ok');
            // Atualiza valores no resumo
            window.location.reload();  // recarrega pra refletir desconto em tudo
          } else {
            $msg.text(res.msg || 'Cupom inválido.').removeClass('msg-ok').addClass('msg-error');
          }
        })
        .fail(function () {
          $msg.text('Erro de conexão.').addClass('msg-error');
        })
        .always(function () { CK.btnLoading($btn, false); });
    });
  });

  // Expõe globalmente
  window.CK = CK;


   // ── Dados de detecção ─────────────────────────────────
  const BRANDS = [
    {
      name: 'amex', label: 'American Express', cvv: 4,
      pattern: /^3[47]/,
    },
    {
      name: 'diners', label: 'Diners Club', cvv: 3,
      pattern: /^(30[0-5]|36|38)/,
    },
    {
      name: 'discover', label: 'Discover', cvv: 3,
      pattern: /^(6011|65)/,
    },
    {
      name: 'elo', label: 'Elo', cvv: 3,
      pattern: /^(4011|4312|4389|4514|4573|5041|5066|5067|5090|6277|6362|6363|6504|6505|6516|6550)/,
    },
    {
      name: 'hipercard', label: 'Hipercard', cvv: 3,
      pattern: /^(606282|3841)/,
    },
    {
      name: 'mastercard', label: 'Mastercard', cvv: 3,
      pattern: /^(5[1-5]|2[2-7])/,
    },
    {
      name: 'visa', label: 'Visa', cvv: 3,
      pattern: /^4/,
    },
  ];
 
  // ── SVGs ──────────────────────────────────────────────
  const LOGOS = {
 
    visa: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 38 24" role="img" aria-label="Visa">
  <rect width="38" height="24" rx="4" fill="#1A1F71"/>
  <text x="19.5" y="16.5" text-anchor="middle"
        font-family="Arial,sans-serif" font-size="11" font-weight="900"
        font-style="italic" fill="white" letter-spacing="0.5">VISA</text>
</svg>`,
 
    mastercard: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 38 24" role="img" aria-label="Mastercard">
  <rect width="38" height="24" rx="4" fill="#252525"/>
  <circle cx="15" cy="12" r="7.5" fill="#EB001B"/>
  <circle cx="23" cy="12" r="7.5" fill="#F79E1B"/>
  <path d="M19 5.75C21.12 7.18 22.5 9.45 22.5 12C22.5 14.55 21.12 16.82 19 18.25C16.88 16.82 15.5 14.55 15.5 12C15.5 9.45 16.88 7.18 19 5.75Z" fill="#FF5F00"/>
</svg>`,
 
    amex: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 38 24" role="img" aria-label="American Express">
  <rect width="38" height="24" rx="4" fill="#2E77BC"/>
  <text x="19" y="15" text-anchor="middle"
        font-family="Arial,sans-serif" font-size="9.5" font-weight="900"
        fill="white" letter-spacing="1.5">AMEX</text>
</svg>`,
 
    elo: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 38 24" role="img" aria-label="Elo">
  <rect width="38" height="24" rx="4" fill="#1C1C1C"/>
  <rect x="6" y="8" width="6" height="8" rx="1" fill="#FFCB05"/>
  <rect x="6.5" y="11.2" width="5" height="1.6" rx="0.5" fill="#1C1C1C"/>
  <rect x="14.5" y="8" width="2" height="8" rx="0.5" fill="white"/>
  <rect x="14.5" y="14.5" width="5" height="1.5" rx="0.5" fill="white"/>
  <circle cx="27.5" cy="12" r="4" fill="none" stroke="#00AEEF" stroke-width="2"/>
  <path d="M29.5 9 L31 12 L29.5 15" fill="none" stroke="#EF7921" stroke-width="1.5" stroke-linecap="round"/>
</svg>`,
 
    hipercard: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 38 24" role="img" aria-label="Hipercard">
  <defs>
    <linearGradient id="hg" x1="0%" y1="0%" x2="0%" y2="100%">
      <stop offset="0%" style="stop-color:#D42027"/>
      <stop offset="100%" style="stop-color:#8B0F14"/>
    </linearGradient>
  </defs>
  <rect width="38" height="24" rx="4" fill="url(#hg)"/>
  <rect x="6" y="8" width="2" height="8" rx="0.5" fill="white"/>
  <rect x="10" y="8" width="2" height="8" rx="0.5" fill="white"/>
  <rect x="6" y="11" width="6" height="2" rx="0.5" fill="white"/>
  <text x="25" y="15.5" text-anchor="middle"
        font-family="Arial,sans-serif" font-size="6" font-weight="900"
        fill="white" letter-spacing="0.8">IPER</text>
</svg>`,
 
    diners: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 38 24" role="img" aria-label="Diners Club">
  <rect width="38" height="24" rx="4" fill="#F5F5F5" stroke="#E0E0E0" stroke-width="0.5"/>
  <circle cx="16" cy="12" r="6" fill="none" stroke="#004A97" stroke-width="1.5"/>
  <circle cx="22" cy="12" r="6" fill="none" stroke="#004A97" stroke-width="1.5"/>
  <path d="M19 6.5C20.5 7.8 21.5 9.8 21.5 12C21.5 14.2 20.5 16.2 19 17.5C17.5 16.2 16.5 14.2 16.5 12C16.5 9.8 17.5 7.8 19 6.5Z" fill="#004A97"/>
</svg>`,
 
    discover: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 38 24" role="img" aria-label="Discover">
  <rect width="38" height="24" rx="4" fill="#FFFFFF" stroke="#E0E0E0" stroke-width="0.5"/>
  <circle cx="28" cy="12" r="9" fill="#F76F20" opacity="0.85"/>
  <circle cx="26" cy="12" r="7" fill="#F76F20"/>
  <text x="9" y="15" text-anchor="middle"
        font-family="Arial,sans-serif" font-size="5.5" font-weight="900"
        fill="#231F20" letter-spacing="0.3">DISC</text>
</svg>`,
 
    pix: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 38 24" role="img" aria-label="Pix">
  <rect width="38" height="24" rx="4" fill="#32BCAD"/>
  <g transform="translate(5,4)">
    <path d="M9.5 4.5 L12 7 L9.5 9.5 L7 7Z" fill="white"/>
    <path d="M4 0 L6.5 2.5 L4 5 L1.5 2.5Z" fill="rgba(255,255,255,.75)"/>
    <path d="M15 0 L17.5 2.5 L15 5 L12.5 2.5Z" fill="rgba(255,255,255,.75)"/>
    <path d="M4 9 L6.5 11.5 L4 14 L1.5 11.5Z" fill="rgba(255,255,255,.75)"/>
    <path d="M15 9 L17.5 11.5 L15 14 L12.5 11.5Z" fill="rgba(255,255,255,.75)"/>
  </g>
  <text x="31" y="15.5" text-anchor="middle"
        font-family="Arial,sans-serif" font-size="6" font-weight="900"
        fill="white" letter-spacing="0">PIX</text>
</svg>`,
 
    default: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 38 24">
  <rect width="38" height="24" rx="4" fill="#e2e8f0" stroke="#cbd5e1" stroke-width="0.5"/>
  <rect x="4" y="8" width="30" height="2.5" rx="1.5" fill="#94a3b8"/>
  <rect x="4" y="13" width="12" height="2.5" rx="1.5" fill="#94a3b8"/>
</svg>`,
  };
 
  // ── API ───────────────────────────────────────────────
  const CardBrands = {
 
    /** Detecta bandeira por primeiros dígitos */
    detect(digits) {
      if (!digits) return null;
      const d = digits.replace(/\D/g, '');
      const found = BRANDS.find(b => b.pattern.test(d));
      if (!found) return null;
      return {
        name:  found.name,
        label: found.label,
        cvv:   found.cvv,
        logo:  LOGOS[found.name] || LOGOS.default,
      };
    },
 
    /** Retorna SVG da bandeira pelo nome */
    logo(name) {
      return LOGOS[name?.toLowerCase()] || LOGOS.default;
    },
 
    /**
     * Atualiza um elemento DOM com o logo da bandeira.
     * @param {HTMLElement|string} el  Elemento ou seletor CSS
     * @param {string|null}        brand  Nome da bandeira (null = placeholder)
     */
    updateIcon(el, brand) {
      const target = typeof el === 'string' ? document.querySelector(el) : el;
      if (!target) return;
      const svg = brand ? (LOGOS[brand] || LOGOS.default) : LOGOS.default;
      target.innerHTML = svg;
      if (brand) {
        target.classList.add('has-brand');
        target.setAttribute('data-brand', brand);
      } else {
        target.classList.remove('has-brand');
        target.removeAttribute('data-brand');
      }
    },
 
    /** Lista de todas as bandeiras disponíveis */
    all: BRANDS,
  };
 
  window.CardBrands = CardBrands;
  

}(jQuery, window));


/**
 * assets/js/checkout-credito.js
 * Widget de crédito no checkout — aplica/remove saldo com atualização
 * animada de todos os valores do resumo lateral e barra mobile.
 *
 * Depende de: jQuery, BASE_URL (global)
 * IDs esperados: #credito-widget, #scw-input, #btn-credito-aplicar,
 *                #btn-credito-remover, #scw-form, #scw-applied,
 *                #ck-row-credito, #ck-credito, #ck-total, #mobile-bar-total
 */
;(function ($) {
  'use strict';

  var $widget = $('#credito-widget');
  if (!$widget.length) return;

  // ── Helpers ──────────────────────────────────────────────
  function fmtBRL(v) {
    var abs = Math.abs(parseFloat(v) || 0);
    return 'R$ ' + abs.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  }

  /**
   * Anima a troca de um valor numérico em texto.
   * Ex: "R$ 120,00" → "R$ 75,00"
   */
  function animarValor($el, novoTexto, destaque) {
    $el
      .addClass('ck-val-updating')
      .one('transitionend webkitTransitionEnd', function () {
        $el.text(novoTexto);
        $el.removeClass('ck-val-updating').addClass('ck-val-updated');
        if (destaque) $el.addClass('ck-val--' + destaque);
        setTimeout(function () {
          $el.removeClass('ck-val-updated');
        }, 1800);
      });
    // Fallback se transitionend não disparar (ex: display:none)
    setTimeout(function () {
      if ($el.hasClass('ck-val-updating')) {
        $el.text(novoTexto).removeClass('ck-val-updating').addClass('ck-val-updated');
        if (destaque) $el.addClass('ck-val--' + destaque);
      }
    }, 350);
  }

  /**
   * Atualiza todos os elementos do resumo com os novos totais.
   */
  function atualizarResumo(totais, creditoAplicado) {
    // Linha de crédito
    if (creditoAplicado > 0) {
      $('#ck-row-credito').show();
      animarValor($('#ck-credito'), totais.credito_fmt || ('−' + fmtBRL(creditoAplicado)), 'desconto');
    } else {
      $('#ck-row-credito').hide();
      $('#ck-credito').text('').removeClass('ck-val--desconto');
    }

    // Total principal
    animarValor($('#ck-total'), totais.total_fmt, creditoAplicado > 0 ? 'credito' : null);

    // Barra mobile
    var $mobile = $('#mobile-bar-total');
    if ($mobile.length) {
      $mobile.text(totais.total_fmt)
             .addClass('mobile-bar-updated');
      setTimeout(function () { $mobile.removeClass('mobile-bar-updated'); }, 1500);
    }

    // Se crédito cobre tudo: destaca como "Grátis" / R$ 0,00
    if (totais.cobertura_total) {
      $('#ck-total').addClass('ck-val--gratis');
    } else {
      $('#ck-total').removeClass('ck-val--gratis');
    }
  }

  // ── Aplicar crédito ────────────────────────────────────────
  $('#btn-credito-aplicar').on('click', function () {
    var raw    = $('#scw-input').val().replace(/\./g, '').replace(',', '.');
    var valor  = parseFloat(raw) || 0;
    var max    = parseFloat($widget.data('max'))   || 0;
    var total  = parseFloat($widget.data('total')) || 0;

    if (valor <= 0) {
      $('#scw-input').addClass('input-error');
      setTimeout(function () { $('#scw-input').removeClass('input-error'); }, 1500);
      return;
    }
    // Silenciosamente limita ao máximo
    valor = Math.min(valor, max, total);

    var $btn = $(this);
    $btn.prop('disabled', true).text('Aplicando…');

    $.post(BASE_URL + '/checkout/aplicar-credito', { valor: valor })
      .done(function (res) {
        $btn.prop('disabled', false).text('Aplicar');
        if (!res.ok) {
          showInlineError(res.msg || 'Erro ao aplicar crédito.');
          return;
        }

        var cr = res.credito;

        // Atualiza o valor no estado aplicado antes de mostrar
        $('#scw-applied-valor').text('−' + fmtBRL(cr));

        // Troca form → estado aplicado
        $('#scw-form').fadeOut(150, function () {
          $('#scw-applied').fadeIn(200);
        });

        atualizarResumo(res.totais, cr);
      })
      .fail(function () {
        $btn.prop('disabled', false).text('Aplicar');
        showInlineError('Erro de conexão. Tente novamente.');
      });
  });

  // ── Remover crédito ────────────────────────────────────────
  $(document).on('click', '#btn-credito-remover', function () {
    var $btn = $(this);
    $btn.prop('disabled', true).text('Removendo…');

    $.post(BASE_URL + '/checkout/remover-credito', {})
      .done(function (res) {
        $btn.prop('disabled', false).text('Remover');
        if (!res.ok) return;

        // Volta para o form e limpa o valor aplicado
        $('#scw-applied').fadeOut(150, function () {
          $('#scw-applied-valor').text('');
          $('#scw-form').fadeIn(200);
        });

        atualizarResumo(res.totais, 0);
      })
      .fail(function () {
        $btn.prop('disabled', false).text('Remover');
      });
  });

  // ── Input: máscara numérica + validação em tempo real ──────
  $('#scw-input').on('input', function () {
    var raw   = this.value.replace(/[^0-9,]/g, '');
    var valor = parseFloat(raw.replace(',', '.')) || 0;
    var max   = parseFloat($widget.data('max')) || 0;

    $(this).toggleClass('input-warn', valor > max);

    var hint = 'Máximo: ' + fmtBRL(max);
    if (valor > 0 && valor <= max) {
      hint = 'Você pagará: ' + fmtBRL(Math.max(0, parseFloat($widget.data('total')) - valor));
    } else if (valor > max) {
      hint = 'Valor excede o saldo ou o total do pedido.';
    }
    $('.scw-hint').text(hint);
  });

  // ── Erro inline ────────────────────────────────────────────
  function showInlineError(msg) {
    var $err = $('#scw-error');
    if (!$err.length) {
      $err = $('<div id="scw-error" class="scw-error"></div>');
      $('#scw-form').append($err);
    }
    $err.text(msg).show();
    setTimeout(function () { $err.fadeOut(); }, 3500);
  }

}(jQuery));