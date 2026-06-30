/**
 * checkout-card-add.js
 * Carregado apenas em /checkout/payment/card/add.
 * Máscaras, detecção de bandeira, preview animado, Luhn, submit.
 */
;(function ($, window) {
  'use strict';

  const BRANDS = [
    { name: 'amex',       label: 'AMEX',    cvv: 4, bg: 'linear-gradient(135deg,#2e6dd4,#1a4a9e)', pattern: /^3[47]/ },
    { name: 'diners',     label: 'DINERS',  cvv: 3, bg: 'linear-gradient(135deg,#5c5c5c,#333)',    pattern: /^(30[0-5]|36|38)/ },
    { name: 'elo',        label: 'ELO',     cvv: 3, bg: 'linear-gradient(135deg,#f0c014,#d4a800)', pattern: /^(4011|4312|4389|4514|4573|5041|5066|5067|5090|6277|6362|6363|6504|6505|6516|6550)/ },
    { name: 'hipercard',  label: 'HIPER',   cvv: 3, bg: 'linear-gradient(135deg,#b51c1c,#8b0000)', pattern: /^(606282|3841)/ },
    { name: 'mastercard', label: 'MASTER',  cvv: 3, bg: 'linear-gradient(135deg,#eb5757,#cc0000)', pattern: /^(5[1-5]|2[2-7])/ },
    { name: 'visa',       label: 'VISA',    cvv: 3, bg: 'linear-gradient(135deg,#1a1f6e,#0f146d)', pattern: /^4/ },
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

  function maskCardNumber($input) {
    const brand  = detectBrand($input.val().replace(/\D/g, '').slice(0, 6));
    let digits   = $input.val().replace(/\D/g, '');
    const isAmex = brand && brand.name === 'amex';
    digits       = isAmex ? digits.slice(0, 15) : digits.slice(0, 16);
    $input.val(isAmex
      ? digits.replace(/^(\d{0,4})(\d{0,6})(\d{0,5}).*/, (_, a, b, c) => [a,b,c].filter(Boolean).join(' '))
      : digits.replace(/(.{4})(?=.)/g, '$1 ').trim()
    );
    return digits;
  }

  function maskValidade($input) {
    let v = $input.val().replace(/\D/g, '').slice(0, 4);
    if (v.length > 2) v = v.slice(0, 2) + '/' + v.slice(2);
    $input.val(v);
    return v;
  }

  // ── Preview em tempo real ─────────────────────────
  function updatePreview() {
    const digits = $('#numero_cartao').val().replace(/\D/g, '');
    const brand  = detectBrand(digits);
    const padded = digits.padEnd(16, '•').slice(0, 16);
    const groups = padded.match(/.{1,4}/g) || [];

    $('#card-prev-number').text(groups.join(' '));
    $('#card-prev-holder').text($('#nome_cartao').val().trim().toUpperCase() || 'NOME COMPLETO');
    $('#card-prev-expiry').text($('#validade_cartao').val() || 'MM/AA');

    if (brand) {
      $('#card-prev-brand').html('<span class="card-prev-brand-label">' + brand.label + '</span>');
      $('#card-brand-detected').text(brand.label).show();
      $('#card-preview').css('background', brand.bg);
    } else {
      $('#card-prev-brand').html('<span class="card-prev-brand-placeholder">CARTÃO</span>');
      $('#card-brand-detected').hide().empty();
      $('#card-preview').css('background', 'linear-gradient(135deg,#1a1a2e 0%,#16213e 50%,#e63946 100%)');
    }
  }

  // ── Eventos dos campos ────────────────────────────
  $(document).on('input', '#numero_cartao', function () {
    const digits = maskCardNumber($(this));
    const brand  = detectBrand(digits);
    $(this).toggleClass('is-valid', luhnValid(digits)).removeClass('is-error');
    $('#err-numero').empty();
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

  // Toggle principal
  $(document).on('change', '#toggle-principal input[type="checkbox"]', function () {
    $(this).closest('.save-card-toggle').toggleClass('is-checked', $(this).is(':checked'));
  });
  // Inicia marcado
  if ($('#toggle-principal input[type="checkbox"]').is(':checked')) {
    $('#toggle-principal').addClass('is-checked');
  }

  // ── Submit ────────────────────────────────────────
  $('#form-card-add').on('submit', function (e) {
    e.preventDefault();
    const $btn = $('#btn-save-card');
    const $err = $('#card-add-error');
    CK.formAlertClear($err);

    const digits   = $('#numero_cartao').val().replace(/\D/g, '');
    const nome     = $('#nome_cartao').val().trim();
    const apelido     = $('#apelido_cartao').val().trim();
    const validade = $('#validade_cartao').val().trim();
    const cvv      = $('#cvv_cartao').val().replace(/\D/g, '');
    const brand    = detectBrand(digits);
    const principal= $('#toggle-principal input[type="checkbox"]').is(':checked') ? 1 : 0;

    // Validações client-side
    const erros = [];
    if (!luhnValid(digits))                   erros.push('Número do cartão inválido.');
    if (nome.length < 4)                       erros.push('Informe o nome impresso no cartão.');
    if (!/^\d{2}\/\d{2}$/.test(validade))     erros.push('Validade inválida. Use MM/AA.');
    else {
      const [mm, yy] = validade.split('/').map(Number);
      if (mm < 1 || mm > 12)                   erros.push('Mês de validade inválido.');
      else if (new Date(2000+yy, mm, 0) < new Date()) erros.push('Cartão vencido.');
    }
    if (cvv.length < 3) erros.push('CVV inválido.');

    if (erros.length) {
      CK.formAlertSet($err, erros[0]);
      return;
    }

    CK.btnLoading($btn);

    // Envia para o servidor — o controller tokeniza e salva apenas metadados
    CK.post('/checkout/payment/card/add', {
      numero_cartao:   digits,
      nome_cartao:     nome,
      apelido:     apelido,
      validade_cartao: validade,
      cvv_cartao:      cvv,
      bandeira:        brand ? brand.name : '',
      principal:       principal,
    })
    .done(function (res) {
      if (res.ok && res.redirect) {
        window.location.href = res.redirect;
      } else {
        CK.btnLoading($btn, false);
        CK.formAlertSet($err, res.msg || 'Erro ao salvar cartão.');
      }
    })
    .fail(function () {
      CK.btnLoading($btn, false);
      CK.formAlertSet($err, 'Erro de conexão. Tente novamente.');
    });
  });

}(jQuery, window));