/**
 * checkout-payment.js — v3
 * Seleção de método: Pix / Boleto / Cartão salvo
 * "Revisar pedido" → salva método + obs → /checkout/summary
 */
;(function ($, window) {
  'use strict';

  // Seleciona método visualmente
  $(document).on('click', '.payment-method-card', function (e) {
    if ($(e.target).is('input')) return;
    $('.payment-method-card').removeClass('is-selected');
    $(this).addClass('is-selected');
    $(this).find('input[type="radio"]').prop('checked', true);
  });

  // Observação autosave
  let obsTimer;
  $('#observacao').on('input', function () {
    clearTimeout(obsTimer);
    const obs = $(this).val();
    obsTimer = setTimeout(function () {
      CK.post('/checkout/payment/save-observation', { observacao: obs });
    }, 900);
  });

  // Continuar para resumo
  $('#btn-to-summary').on('click', function () {
    const $btn = $(this);
    const $err = $('#payment-error-global');
    const $sel = $('.payment-method-card.is-selected');
    CK.formAlertClear($err);

    if (!$sel.length) {
      CK.formAlertSet($err, 'Selecione uma forma de pagamento para continuar.');
      return;
    }

    const radio    = $sel.find('input[type="radio"]');
    let metodo     = radio.val() || '';
    let cartaoId   = null;

    if (metodo.startsWith('cartao_salvo_')) {
      cartaoId = parseInt(metodo.replace('cartao_salvo_', ''));
      metodo   = 'cartao';
    }

    CK.btnLoading($btn);
    CK.post('/checkout/payment/save-method', {
      forma_pagamento: metodo,
      cartao_id:       cartaoId || '',
      observacao:      $('#observacao').val().trim(),
    })
    .done(function () {
      window.location.href = BASE_URL + '/checkout/summary';
    })
    .fail(function () {
      CK.btnLoading($btn, false);
      CK.formAlertSet($err, 'Erro de conexão.');
    });
  });

}(jQuery, window));