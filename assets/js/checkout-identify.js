/**
 * checkout-identify.js — v2
 *
 * Adaptado para fluxo baseado em rotas (não mais SPA).
 *
 * Comportamento:
 *   - Login OK     → server faz redirect para /checkout/address
 *   - Cadastro OK  → server salva pending em sessão; JS recarrega
 *                    a página que detecta pending e renderiza verify
 *   - Verify OK    → redirect para /checkout/address
 *   - Editar email → limpa pending, recarrega → volta para login/cadastro
 */
(function () {
  'use strict';

  // ════════════════════════════════════════════════════
  // TABS LOGIN ↔ CADASTRO (com [hidden], não display:none)
  // ════════════════════════════════════════════════════
  $(document).on('click', '.ident-tab', function () {
    const tab = $(this).data('tab');
    $('.ident-tab').removeClass('active');
    $(this).addClass('active');

    $('.ident-panel').attr('hidden', 'hidden');
    $('#panel-' + tab).removeAttr('hidden');
  });

  // ════════════════════════════════════════════════════
  // LOGIN
  // ════════════════════════════════════════════════════
  $('#form-checkout-login').on('submit', function (e) {
    e.preventDefault();
    const $form = $(this);
    const $btn  = $form.find('[type=submit]');
    const $err  = $('#login-error');

    CK.formAlertClear($err);
    CK.btnLoading($btn);

    CK.post('/checkout/identify', $form.serializeArray()
      .reduce((acc, x) => (acc[x.name] = x.value, acc), {}))
      .done(function (res) {
        if (res.ok && res.redirect) {
          window.location.href = res.redirect;
        } else {
          CK.formAlertSet($err, res.msg || 'Não foi possível entrar.');
          CK.btnLoading($btn, false);
        }
      })
      .fail(function () {
        CK.formAlertSet($err, 'Erro de conexão.');
        CK.btnLoading($btn, false);
      });
  });

  // ════════════════════════════════════════════════════
  // CADASTRO RÁPIDO
  // ════════════════════════════════════════════════════
  $('#form-checkout-cadastro').on('submit', function (e) {
    e.preventDefault();
    const $form = $(this);
    const $btn  = $('#btn-cad-submit');
    const $err  = $('#cad-error');

    CK.formAlertClear($err);

    // Validação client-side leve
    const nome     = $('#cad-nome').val().trim();
    const email    = $('#cad-email').val().trim();
    const whatsapp = $('#cad-whatsapp').val().replace(/\D/g, '');

    const erros = [];
    if (nome.length < 3)          erros.push('Nome muito curto.');
    if (!/.+@.+\..+/.test(email)) erros.push('E-mail inválido.');
    if (whatsapp.length < 10)     erros.push('WhatsApp inválido.');

    if (erros.length) {
      CK.formAlertSet($err, erros.join(' '));
      return;
    }

    CK.btnLoading($btn);

    CK.post('/checkout/identify', {
      acao:     'cadastro_rapido',
      nome, email, whatsapp,
    })
    .done(function (res) {
      if (res.ok && res.next === 'verify_email') {
        // Server gravou pending em sessão. Recarrega → mostra verify.
        window.location.href = BASE_URL + '/checkout/identify';
        return;
      }
      if (res.redirect_login) {
        CK.formAlertSet($err, res.msg);
        setTimeout(() => $('.ident-tab[data-tab="login"]').click(), 800);
        $('#login-email').val(email);
        CK.btnLoading($btn, false);
        return;
      }
      if (res.errors && res.errors.length) {
        CK.formAlertSet($err, res.errors.join(' '));
      } else {
        CK.formAlertSet($err, res.msg || 'Não foi possível continuar.');
      }
      CK.btnLoading($btn, false);
    })
    .fail(function () {
      CK.formAlertSet($err, 'Erro de conexão.');
      CK.btnLoading($btn, false);
    });
  });

  // ════════════════════════════════════════════════════
  // VERIFICAÇÃO — inputs de 6 dígitos
  // ════════════════════════════════════════════════════
  function getCodigoCompleto() {
    return $('.verify-digit').map(function () { return $(this).val(); }).get().join('');
  }
  function syncCodigo() {
    const codigo = getCodigoCompleto();
    $('#verify-codigo-hidden').val(codigo);
    $('#btn-verify-submit').prop('disabled', codigo.length !== 6);
  }

  $(document).on('input', '.verify-digit', function () {
    const $this = $(this);
    let val = $this.val().replace(/\D/g, '');
    $this.val(val);
    if (val) {
      $this.addClass('is-filled').removeClass('is-error');
      const next = $('.verify-digit').eq(parseInt($this.data('index')) + 1);
      if (next.length) next.focus();
    } else {
      $this.removeClass('is-filled');
    }
    syncCodigo();
  });

  $(document).on('keydown', '.verify-digit', function (e) {
    if (e.key === 'Backspace' && !$(this).val()) {
      const idx = parseInt($(this).data('index'));
      $('.verify-digit').eq(idx - 1).focus();
    }
  });

  $(document).on('paste', '.verify-digit', function (e) {
    e.preventDefault();
    const pasted = (e.originalEvent.clipboardData || window.clipboardData)
      .getData('text').replace(/\D/g, '').slice(0, 6);
    if (!pasted) return;
    $('.verify-digit').each(function (i) {
      $(this).val(pasted[i] || '').toggleClass('is-filled', !!pasted[i]);
    });
    syncCodigo();
    if (pasted.length === 6) $('#form-checkout-verify').submit();
  });

  // ════════════════════════════════════════════════════
  // VALIDAR CÓDIGO
  // ════════════════════════════════════════════════════
  $('#form-checkout-verify').on('submit', function (e) {
    e.preventDefault();
    const $btn = $('#btn-verify-submit');
    const $err = $('#verify-error');
    const codigo = getCodigoCompleto();
    if (codigo.length !== 6) return;

    CK.formAlertClear($err);
    CK.btnLoading($btn);

    CK.post('/checkout/identify', { acao: 'verificar_codigo', codigo })
      .done(function (res) {
        if (res.ok && res.redirect) {
          window.location.href = res.redirect;
          return;
        }
        CK.btnLoading($btn, false);

        if (res.expired) {
          CK.formAlertSet($err, res.msg || 'Sessão expirada.');
          setTimeout(() => window.location.href = BASE_URL + '/checkout/identify', 1500);
          return;
        }

        $('.verify-digit').addClass('is-error');
        setTimeout(() => {
          $('.verify-digit').removeClass('is-error is-filled').val('');
          syncCodigo();
          $('.verify-digit').first().focus();
        }, 400);

        CK.formAlertSet($err, res.msg || 'Código incorreto.');
      })
      .fail(function () {
        CK.btnLoading($btn, false);
        CK.formAlertSet($err, 'Erro de conexão.');
      });
  });

  // ════════════════════════════════════════════════════
  // REENVIAR / EDITAR
  // ════════════════════════════════════════════════════
  let resendInterval = null;

  function startResendCooldown(seconds) {
    let remaining = seconds;
    const $btn   = $('#btn-resend-code');
    const $timer = $('#resend-timer');
    const $secs  = $('#resend-seconds');

    $btn.prop('disabled', true);
    $timer.show();
    $secs.text(remaining);

    clearInterval(resendInterval);
    resendInterval = setInterval(() => {
      remaining--;
      if (remaining <= 0) {
        clearInterval(resendInterval);
        $btn.prop('disabled', false);
        $timer.hide();
      } else {
        $secs.text(remaining);
      }
    }, 1000);
  }

  $('#btn-resend-code').on('click', function () {
    if ($(this).prop('disabled')) return;
    CK.post('/checkout/identify', { acao: 'reenviar_codigo' })
      .done(function (res) {
        if (res.ok) {
          CK.toast(res.msg || 'Código reenviado para o seu e-mail.', 'success');
          $('.verify-digit').val('').removeClass('is-filled is-error');
          syncCodigo();
          $('.verify-digit').first().focus();
          startResendCooldown(30);
        } else {
          if (res.wait) startResendCooldown(res.wait);
          CK.toast(res.msg || 'Erro ao reenviar.', 'error');
        }
      });
  });

  $('#btn-edit-email').on('click', function () {
    CK.post('/checkout/identify', { acao: 'editar_email' })
      .always(function () {
        window.location.href = BASE_URL + '/checkout/identify';
      });
  });

  // Auto-inicia cooldown ao chegar na tela de verify
  if ($('#form-checkout-verify').length) {
    startResendCooldown(30);
    setTimeout(() => $('.verify-digit').first().focus(), 100);
  }

}());