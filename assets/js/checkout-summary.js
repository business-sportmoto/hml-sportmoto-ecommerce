/**
 * checkout-summary.js — v2
 *
 * Carregado apenas em /checkout/summary.
 * Cartão/Pix/Boleto, máscaras, Luhn, preview, parcelas, finalizar.
 */
;(function ($, window) {
  'use strict';

  const BRANDS = [
    { name: 'amex',       label: 'AMEX',   cvv: 4, pattern: /^3[47]/ },
    { name: 'diners',     label: 'DINERS', cvv: 3, pattern: /^(30[0-5]|36|38)/ },
    { name: 'discover',   label: 'DISC',   cvv: 3, pattern: /^(6011|65)/ },
    { name: 'elo',        label: 'ELO',    cvv: 3, pattern: /^(4011|4312|4389|4514|4573|5041|5066|5067|5090|6277|6362|6363|6504|6505|6516|6550)/ },
    { name: 'hipercard',  label: 'HIPER',  cvv: 3, pattern: /^(606282|3841)/ },
    { name: 'mastercard', label: 'MASTER', cvv: 3, pattern: /^(5[1-5]|2[2-7])/ },
    { name: 'visa',       label: 'VISA',   cvv: 3, pattern: /^4/ },
  ];

  function detectBrand(d) { return BRANDS.find(b => b.pattern.test(d)) || null; }

  function luhnValid(digits) {
    if (!digits || digits.length < 13) return false;
    let sum = 0, dbl = false;
    for (let i = digits.length - 1; i >= 0; i--) {
      let d = parseInt(digits[i], 10);
      if (dbl) { d *= 2; if (d > 9) d -= 9; }
      sum += d; dbl = !dbl;
    }
    return sum % 10 === 0;
  }

  function formatBRL(v) { return 'R$ ' + Number(v).toFixed(2).replace('.', ','); }

  function maskCardNumber($input) {
    const brand  = detectBrand($input.val().replace(/\D/g, '').slice(0, 6));
    let digits   = $input.val().replace(/\D/g, '');
    const isAmex = brand && brand.name === 'amex';
    digits = isAmex ? digits.slice(0, 15) : digits.slice(0, 16);
    const formatted = isAmex
      ? digits.replace(/^(\d{0,4})(\d{0,6})(\d{0,5}).*/, (_, a, b, c) => [a, b, c].filter(Boolean).join(' '))
      : digits.replace(/(.{4})(?=.)/g, '$1 ').trim();
    $input.val(formatted);
    return digits;
  }

  function maskValidade($input) {
    let v = $input.val().replace(/\D/g, '').slice(0, 4);
    if (v.length > 2) v = v.slice(0, 2) + '/' + v.slice(2);
    $input.val(v);
    return v;
  }

  function updatePreview() {
    const digits = $('#numero_cartao').val().replace(/\D/g, '');
    const brand  = detectBrand(digits);
    const padded = digits.padEnd(16, '•').slice(0, 16);
    const groups = padded.match(/.{1,4}/g) || [];

    $('#card-prev-number').text(groups.join(' '));
    $('#card-prev-holder').text($('#nome_cartao').val().trim().toUpperCase() || 'NOME COMPLETO');
    $('#card-prev-expiry').text($('#validade_cartao').val() || 'MM/AA');

    if (brand) {
      $('#card-prev-brand').html(`<span class="card-prev-brand-label card-prev-brand--${brand.name}">${brand.label}</span>`);
      $('#card-brand-detected').text(brand.label).show();
    } else {
      $('#card-prev-brand').html('<span class="card-prev-brand-placeholder">CARTÃO</span>');
      $('#card-brand-detected').hide().empty();
    }
  }

  function popularParcelas() {
    const cfg     = window.CHECKOUT_CONFIG || {};
    const total   = parseFloat(cfg.total || 0);
    const max     = cfg.maxParcelas || 12;
    const minVal  = cfg.minParcela  || 30;
    const juros   = cfg.juros       || {};
    const $select = $('#parcelas');
    if (!total || !$select.length) return;
    const realMax = Math.min(max, Math.max(1, Math.floor(total / minVal)));
    $select.empty();
    for (let n = 1; n <= realMax; n++) {
      const taxa  = juros[n] || 0;
      const totP  = total * (1 + taxa / 100);
      const label = n === 1
        ? `À vista · ${formatBRL(total)}`
        : `${n}x de ${formatBRL(totP / n)} ${taxa === 0 ? 'sem juros' : `(${taxa}% a.m.)`}`;
      $select.append(`<option value="${n}" data-juros="${taxa}">${label}</option>`);
    }
  }

  // Método de pagamento
  $(document).on('click', '.summary-method-pill', function () {
    const method = $(this).find('input[type="radio"]').val();
    $('.summary-method-pill').removeClass('is-active');
    $(this).addClass('is-active').find('input[type="radio"]').prop('checked', true);
    $('#card-panel').attr('hidden',   method !== 'cartao' ? true : null);
    $('#pix-panel').attr('hidden',    method !== 'pix'    ? true : null);
    $('#boleto-panel').attr('hidden', method !== 'boleto' ? true : null);
  });

  // Inputs do cartão
  $(document).on('input', '#numero_cartao', function () {
    const digits = maskCardNumber($(this));
    const brand  = detectBrand(digits);
    $(this).toggleClass('is-valid', luhnValid(digits)).removeClass('is-error');
    if (brand) $('#cvv_cartao').attr('maxlength', brand.cvv);
    updatePreview();
  });

  $(document).on('input', '#nome_cartao', function () {
    $(this).val($(this).val().toUpperCase());
    updatePreview();
  });

  $(document).on('input', '#validade_cartao', function () {
    maskValidade($(this));
    updatePreview();
  });

  $(document).on('input', '#cvv_cartao', function () {
    const max = parseInt($(this).attr('maxlength')) || 4;
    $(this).val($(this).val().replace(/\D/g, '').slice(0, max));
  });

  $(document).on('change', '#save-card-toggle input[type="checkbox"]', function () {
    $(this).closest('.save-card-toggle').toggleClass('is-checked', $(this).is(':checked'));
  });

  // Finalizar
  $('#btn-finalize').on('click', function () {
    const $btn   = $(this);
    const $err   = $('#finalize-error');
    const method = $('input[name="forma_pagamento"]:checked').val() || 'pix';
    CK.formAlertClear($err);

    if (method === 'cartao') {
      const numeroCartao = $('#numero_cartao').val() || '';
      const digits = numeroCartao.replace(/\D/g, '');
      // const digits   = $('#numero_cartao').val().replace(/\D/g, '');
      const nome_cartao = $('#numero_cartao').val() || '';
      const nome     = nome_cartao.trim();

      const validade_cartao = $('#validade_cartao').val() || '';
      const validade = validade_cartao.trim();

      const cvv_cartao = $('#cvv_cartao').val() || '';
      const cvv      = cvv_cartao.replace(/\D/g, '');
      const erros    = [];
      if (!luhnValid(digits))                  erros.push('Número do cartão inválido.');
      if (nome.length < 4)                      erros.push('Informe o nome impresso no cartão.');
      if (!/^\d{2}\/\d{2}$/.test(validade)) erros.push('Validade inválida (MM/AA).');
      else {
        const [mm, yy] = validade.split('/').map(Number);
        if (mm < 1 || mm > 12) erros.push('Mês de validade inválido.');
        else if (new Date(2000 + yy, mm, 0) < new Date()) erros.push('Cartão vencido.');
      }
      if (cvv.length < 3) erros.push('CVV inválido.');
      if (erros.length) {
        CK.formAlertSet($err, erros[0]);
        $('html,body').animate({ scrollTop: $('#card-panel').offset().top - 80 }, 250);
        return;
      }
    }

    CK.btnLoading($btn);
    CK.post('/checkout/finalize', {
      forma_pagamento: method,
      parcelas:        $('#parcelas').val()         || 1,
      salvar_cartao:   $('input[name="salvar_cartao"]').is(':checked') ? 1 : 0,
      // numero_cartao:   $('#numero_cartao').val().replace(/\D/g, '') || '',
      // nome_cartao:     $('#nome_cartao').val().trim()                || '',
      // validade_cartao: $('#validade_cartao').val().trim()            || '',
      // cvv_cartao:      $('#cvv_cartao').val()                        || '',
    })
    .done(function (res) {
      if (res.ok && res.redirect) { 
        window.location.href = res.redirect; 
      return; }
      CK.btnLoading($btn, false);
      CK.formAlertSet($err, res.msg || 'Erro ao processar pagamento.');
    })
    .fail(function () {
      CK.btnLoading($btn, false);
      CK.formAlertSet($err, 'Erro de conexão. Tente novamente.');
    });
  });

  $(function () { popularParcelas(); });

}(jQuery, window));