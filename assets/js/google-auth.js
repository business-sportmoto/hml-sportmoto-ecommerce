/**
 * google-auth.js
 * Handles: GIS login callback, complete-profile modal, sessions page
 */
(function ($) {
  'use strict';

  const BASE = BASE_URL   || '';
  const CSRF = CSRF_TOKEN || '';

  // ── Máscara de CPF e Telefone ─────────────────────────
  function maskCpf(val) {
    return val.replace(/\D/g,'')
              .replace(/(\d{3})(\d)/,    '$1.$2')
              .replace(/(\d{3})(\d)/,    '$1.$2')
              .replace(/(\d{3})(\d{1,2})$/,'$1-$2')
              .slice(0, 14);
  }
  function maskTel(val) {
    const d = val.replace(/\D/g,'');
    if (d.length <= 10)
      return d.replace(/(\d{2})(\d{4})(\d{0,4})/,'($1) $2-$3');
    return d.replace(/(\d{2})(\d{5})(\d{0,4})/,'($1) $2-$3').slice(0, 15);
  }

  $(document).on('input', '#gcm-cpf', function () {
    this.value = maskCpf(this.value);
  });
  $(document).on('input', '#gcm-tel', function () {
    this.value = maskTel(this.value);
  });

  // ── Toast ─────────────────────────────────────────────
  function toast(msg, isError) {
    if (typeof window.showToast === 'function') {
      window.showToast(msg, isError ? 'error' : 'success');
      return;
    }
    let $t = $('#auth-toast');
    if (!$t.length) {
      $t = $('<div id="auth-toast" style="position:fixed;top:20px;left:50%;transform:translateX(-50%);padding:12px 20px;border-radius:99px;font-size:13.5px;font-weight:700;z-index:9999;transition:all .3s;opacity:0;pointer-events:none;color:#fff"></div>').appendTo('body');
    }
    $t.css('background', isError ? '#dc2626' : '#0f172a').text(msg).css('opacity', 1);
    setTimeout(() => $t.css('opacity', 0), 3500);
  }

  // ════════════════════════════════════════════════════════
  // CALLBACK GLOBAL DO GIS — chamado pelo Google
  // Usado em login.php e register.php
  // ════════════════════════════════════════════════════════
  window.onGoogleAuth = function (response) {
    if (!response || !response.credential) {
      toast('Autenticação cancelada.', true);
      return;
    }

    $.post(BASE + '/auth/google', {
      credential:  response.credential,
      _csrf_token: CSRF,
    }, function (res) {

      if (!res.ok) {
        toast(res.msg || 'Erro ao autenticar.', true);
        return;
      }

      // Conta nova → pedir CPF e WhatsApp
      if (res.action === 'completar_perfil') {
        abrirModalCompletarPerfil(res);
        return;
      }

      // Login direto → redireciona
      toast(res.msg || 'Login realizado!');
      setTimeout(() => {
        window.location.href = res.redirect || (BASE + '/minha-conta');
      }, 500);

    }, 'json').fail(() => toast('Erro de conexão.', true));
  };

  // ── Botão manual dispara o GIS ────────────────────────
  $(document).on('click', '#google-btn-trigger', function () {
    const $onload = $('#g_id_onload');
    if ($onload.length && window.google && window.google.accounts) {
      google.accounts.id.prompt();
    } else {
      // Fallback: recarrega o GIS e tenta de novo
      toast('Aguarde o Google carregar...', false);
      setTimeout(() => {
        if (window.google && window.google.accounts) google.accounts.id.prompt();
        // else window.location.reload();
      }, 1200);
    }
  });

  // ════════════════════════════════════════════════════════
  // MODAL COMPLETAR PERFIL (CPF + WhatsApp)
  // ════════════════════════════════════════════════════════
  function abrirModalCompletarPerfil(data) {
    const $modal = $('#google-complete-modal');
    if (!$modal.length) {
      toast('Recarregando página...', false);
      // setTimeout(() => window.location.reload(), 800);
      return;
    }

    // Preenche identidade do Google
    $('#gcm-nome').text(data.nome   || '');
    $('#gcm-email').text(data.email || '');

    const $img      = $('#gcm-avatar-img');
    const $initials = $('#gcm-avatar-initials');

    if (data.avatar) {
      $img.attr('src', data.avatar).prop('hidden', false);
      $initials.hide();
    } else {
      const initial = (data.nome || data.email || '?').charAt(0).toUpperCase();
      $initials.text(initial).show();
      $img.prop('hidden', true);
    }

    // Reset form
    $('#google-complete-form')[0].reset();
    $('#gcm-cpf-err, #gcm-tel-err, #gcm-global-err').prop('hidden', true).text('');
    $('#gcm-cpf, #gcm-tel').removeClass('is-error');

    $modal.prop('hidden', false);
    document.body.style.overflow = 'hidden';
    setTimeout(() => $('#gcm-tel').trigger('focus'), 150);
  }

  function fecharModalCompletarPerfil() {
    $('#google-complete-modal').prop('hidden', true);
    document.body.style.overflow = '';
    // Avisa backend para limpar sessão pendente
    $.post(BASE + '/auth/google/cancelar', { _csrf_token: CSRF });
  }

  $(document).on('click', '#gcm-cancel', fecharModalCompletarPerfil);
  $(document).on('click', '#google-complete-modal', function (e) {
    if ($(e.target).is('.auth-modal-backdrop')) fecharModalCompletarPerfil();
  });
  $(document).on('keydown', function (e) {
    if (e.key === 'Escape' && !$('#google-complete-modal').prop('hidden'))
      fecharModalCompletarPerfil();
  });

  // Submit do modal
  $(document).on('submit', '#google-complete-form', function (e) {
    e.preventDefault();

    // Limpa erros
    $('#gcm-cpf-err, #gcm-tel-err, #gcm-global-err').prop('hidden', true).text('');
    $('#gcm-cpf, #gcm-tel').removeClass('is-error');

    const cpf      = $('#gcm-cpf').val().replace(/\D/g, '');
    const telefone = $('#gcm-tel').val().replace(/\D/g, '');

    // Validação client-side
    let hasError = false;

    if (cpf && cpf.length !== 11) {
      $('#gcm-cpf-err').text('CPF incompleto.').prop('hidden', false);
      $('#gcm-cpf').addClass('is-error');
      hasError = true;
    }
    if (!telefone || telefone.length < 10) {
      $('#gcm-tel-err').text('Informe um WhatsApp válido com DDD.').prop('hidden', false);
      $('#gcm-tel').addClass('is-error');
      hasError = true;
    }
    if (hasError) return;

    const $btn = $('#gcm-submit').prop('disabled', true).text('Criando conta...');

    $.post(BASE + '/auth/google/completar-perfil', {
      cpf,
      telefone,
      _csrf_token: CSRF,
    }, function (res) {
      $btn.prop('disabled', false).text('Criar minha conta');

      if (res.ok) {
        $('#google-complete-modal').prop('hidden', true);
        document.body.style.overflow = '';
        toast(res.msg || 'Conta criada!');
        setTimeout(() => {
          window.location.href = res.redirect || (BASE + '/minha-conta');
        }, 500);
        return;
      }

      // Erro por campo
      if (res.campo === 'cpf') {
        $('#gcm-cpf-err').text(res.msg).prop('hidden', false);
        $('#gcm-cpf').addClass('is-error').trigger('focus');
      } else if (res.campo === 'telefone') {
        $('#gcm-tel-err').text(res.msg).prop('hidden', false);
        $('#gcm-tel').addClass('is-error').trigger('focus');
      } else {
        $('#gcm-global-err').text(res.msg || 'Erro. Tente novamente.').prop('hidden', false);
      }
    }, 'json').fail(() => {
      $btn.prop('disabled', false).text('Criar minha conta');
      $('#gcm-global-err').text('Erro de conexão.').prop('hidden', false);
    });
  });

  // ════════════════════════════════════════════════════════
  // PÁGINA DE SESSÕES — vincular/desvincular Google
  // ════════════════════════════════════════════════════════
  const SESS = window.SESS_CONFIG;

  // Callback do GIS na página de sessões (vincular)
  window.onGoogleVincular = function (response) {
    if (!response || !response.credential) {
      toast('Cancelado.', true);
      return;
    }
    $.post(BASE + '/auth/google/vincular-conta', {
      credential:  response.credential,
      _csrf_token: SESS ? SESS.csrf : CSRF,
    }, function (res) {
      if (res.ok) {
        toast(res.msg || 'Google conectado!');
        // setTimeout(() => window.location.reload(), 800);
      } else {
        toast(res.msg || 'Erro ao conectar.', true);
      }
    }, 'json').fail(() => toast('Erro de conexão.', true));
  };

  // Botão "Conectar Google" na página de sessões
  $(document).on('click', '#btn-vincular-google', function () {
    if (window.google && window.google.accounts) {
      google.accounts.id.prompt();
    } else {
      toast('Aguarde o Google carregar...', false);
    }
  });

  // Botão "Desconectar"
  $(document).on('click', '#btn-desvincular-google', async function () {
    const cfg = window.SESS_CONFIG || {};

    const ok = window.adminConfirm
      ? await window.adminConfirm({
          titulo:    'Desconectar Google?',
          mensagem:  'Você não poderá mais usar o Google para entrar nesta conta.',
          tipo:      'danger',
          confirmar: 'Desconectar',
        })
      : confirm('Desconectar sua conta Google?');

    if (!ok) return;

    $.post(BASE + '/auth/google/desvincular-conta', {
      _csrf_token: cfg.csrf || CSRF,
    }, function (res) {
      if (res.ok) {
        toast(res.msg || 'Google desconectado.');
        // setTimeout(() => window.location.reload(), 700);
      } else {
        toast(res.msg || 'Erro.', true);
      }
    }, 'json').fail(() => toast('Erro de conexão.', true));
  });

  // ── Encerrar sessão individual ────────────────────────
  $(document).on('click', '.sess-btn-encerrar', function () {
    const id  = $(this).data('id');
    const $item = $(this).closest('.sess-item');

    $.post(BASE + '/minha-conta/encerrar-sessao', {
      id,
      _csrf_token: SESS ? SESS.csrf : CSRF,
    }, function (res) {
      if (res.ok) {
        $item.css({ transition: 'all .25s', opacity: 0, height: 0, overflow: 'hidden', marginBottom: 0 });
        setTimeout(() => $item.remove(), 260);
        toast('Sessão encerrada.');
      } else {
        toast(res.msg || 'Erro.', true);
      }
    }, 'json');
  });

  // ── Encerrar todas as outras sessões ─────────────────
  $(document).on('click', '#btn-encerrar-todas', async function () {
    const cfg = window.SESS_CONFIG || {};
    const ok  = window.adminConfirm
      ? await window.adminConfirm({
          titulo:    'Encerrar todas as sessões?',
          mensagem:  'Todos os outros dispositivos serão desconectados. Sua sessão atual permanece ativa.',
          tipo:      'danger',
          confirmar: 'Encerrar todas',
        })
      : confirm('Encerrar todas as outras sessões?');

    if (!ok) return;

    $.post(BASE + '/minha-conta/encerrar-todas-sessoes', {
      _csrf_token: cfg.csrf || CSRF,
    }, function (res) {
      if (res.ok) {
        $('.sess-item:not(.is-atual)').fadeOut(250, function() { $(this).remove(); });
        $('#btn-encerrar-todas').remove();
        toast('Todas as outras sessões foram encerradas.');
      } else {
        toast(res.msg || 'Erro.', true);
      }
    }, 'json');
  });

}(jQuery));