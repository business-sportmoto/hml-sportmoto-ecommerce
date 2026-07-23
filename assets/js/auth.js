/**
 * auth.js
 * Interações dos fluxos de autenticação:
 * - Login por identificação + senha/código
 * - Verificação de e-mail
 * - Recuperação/redefinição de senha
 * - Cadastro
 * - 2FA simples e 2FA com escolha de canal
 *
 * Dependências esperadas:
 * - jQuery
 * - BASE_URL
 * - CSRF_TOKEN
 * - Toast.js global: window.Toast
 */

$(function () {
  'use strict';

  let loginAtual = '';
  let emailTimer;

  // ════════════════════════════════════════════════════════
  // LOGIN — ETAPA 1: IDENTIFICAÇÃO
  // ════════════════════════════════════════════════════════
  $('#form-identidade').on('submit', function (e) {
    e.preventDefault();

    const valor = $('#input-login').val().trim();
    const $btn  = $('#btn-identidade');
    const $err  = $('#err-login');

    $err.text('');

    if (!valor) {
      $err.text('Informe o e-mail ou CPF.');
      return;
    }

    $btn.prop('disabled', true).text('Verificando...');

    $.post(BASE_URL + '/login/verificar-identidade', {
      login:       valor,
      _csrf_token: CSRF_TOKEN,
    }, function (res) {
      $btn.prop('disabled', false).text('Continuar');

      if (res.nao_existe) {
        $err.text(res.msg || 'Cadastro não encontrado.');
        setTimeout(function () {
          window.location.href = res.redirect;
        }, 1200);
        return;
      }

      // ── NOVO: cliente da Tray sem senha definida ──────────────
      // Redireciona automaticamente pro fluxo de definir senha, já
      // levando o e-mail na query string pra pré-preencher o campo.
      if (res.definir_senha) {
        // Mostra a mensagem rapidamente antes de redirecionar (opcional)
        if (res.msg) {
          // usar o mesmo mecanismo de exibir mensagem que o login já tem
          $err.text(res.msg); // ← adapte ao nome real da função
        }
        var emailParam = encodeURIComponent(res.email || '');
        setTimeout(function () {
          window.location.href = AUTH_CONFIG.baseUrl + '/recuperar-senha?email=' + emailParam;
        }, 1200); // pequeno delay pra mensagem ser lida; ajuste à vontade
        return;
      }

      if (!res.ok) {
        $err.text(res.msg || 'Não foi possível verificar seus dados.');
        return;
      }

      loginAtual = valor;
      mostrarEtapaSenha(res);
    }, 'json').fail(function () {
      $btn.prop('disabled', false).text('Continuar');
      $err.text('Erro de conexão. Tente novamente.');
    });
  });

  /**
   * Mostra a etapa de autenticação após o usuário ser encontrado.
   * Preenche dados visuais, campos ocultos e direciona o foco para senha.
   */
  function mostrarEtapaSenha(res) {
    const nome = (res.nome || 'Cliente').trim();

    $('#hidden-login-senha').val(loginAtual);
    $('#hidden-login-codigo').val(loginAtual);
    $('#codigo-email-dest').text(res.email_mask || '');

    const $avatar = $('#auth-user-avatar');
    if (res.avatar) {
      $avatar.empty().append(
        $('<img>', {
          src:    res.avatar,
          alt:    '',
          width:  40,
          height: 40,
        })
      );
    } else {
      $avatar.html('<div class="auth-avatar-inicial"></div>');
      $avatar.find('.auth-avatar-inicial').text(nome.charAt(0).toUpperCase());
    }

    $('#auth-user-nome').text(nome);
    $('#auth-user-email-mask').text(res.email_mask || '');
    $('#auth-title').text('Olá, ' + nome + '!');
    $('#auth-sub').text('Como deseja entrar?');

    $('#etapa-identidade').hide();
    $('#etapa-senha').show();
    $('#input-senha').focus();
  }

  // Voltar para a etapa de identificação.
  $('#btn-trocar-conta').on('click', function () {
    $('#etapa-senha').hide();
    $('#etapa-identidade').show();
    $('#auth-title').text('Bem-vindo(a)');
    $('#auth-sub').text('Informe seu e-mail ou CPF para continuar');
    $('#input-login').val('').focus();
    loginAtual = '';
  });

  // ════════════════════════════════════════════════════════
  // LOGIN — ABAS SENHA / CÓDIGO
  // ════════════════════════════════════════════════════════
  $(document).on('click', '.auth-tab', function () {
    const tab = $(this).data('tab');

    $('.auth-tab').removeClass('active');
    $(this).addClass('active');

    $('#painel-senha').toggle(tab === 'senha');
    $('#painel-codigo').toggle(tab === 'codigo');

    if (tab === 'senha') {
      setTimeout(function () {
        $('#input-senha').focus();
      }, 50);
    }
  });

  // ════════════════════════════════════════════════════════
  // LOGIN — ENTRAR COM SENHA
  // ════════════════════════════════════════════════════════
  $('#form-senha').on('submit', function (e) {
    e.preventDefault();

    const $btn  = $('#btn-entrar-senha');
    const $err  = $('#err-senha');
    const senha = $('#input-senha').val();

    $err.text('');

    if (!senha) {
      $err.text('Informe sua senha.');
      return;
    }

    $btn.prop('disabled', true).text('Entrando...');

    const dadosArray = $(this).serializeArray();
    const dados = {};

    dadosArray.forEach(function (campo) {
      dados[campo.name] = campo.value;
    });

    enviarLogin(dados).then(function(res){
      if (res.ok || res.requer_2fa) {
        window.location.href = res.redirect;
        return;
      }

      // ── NOVO: cliente da Tray sem senha definida ──────────────
      // Redireciona automaticamente pro fluxo de definir senha, já
      // levando o e-mail na query string pra pré-preencher o campo.
      if (res.definir_senha) {
        // Mostra a mensagem rapidamente antes de redirecionar (opcional)
        if (res.msg) {
          // usar o mesmo mecanismo de exibir mensagem que o login já tem
          mostrarMensagemLogin(res.msg); // ← adapte ao nome real da função
        }
        var emailParam = encodeURIComponent(res.email || '');
        setTimeout(function () {
          window.location.href = AUTH_CONFIG.baseUrl + '/recuperar-senha?email=' + emailParam;
        }, 1200); // pequeno delay pra mensagem ser lida; ajuste à vontade
        return;
      }

      $btn.prop('disabled', false).text('Entrar');

      if (res.email_pendente) {
        mostrarEtapaVerificacao(loginAtual, res.msg);
        return;
      }

      $err.text(res.msg || 'Não foi possível entrar.');
    });

    // $.post(BASE_URL + '/login', $(this).serialize(), function (res) {
    //   if (res.ok || res.requer_2fa) {
    //     window.location.href = res.redirect;
    //     return;
    //   }

    //   $btn.prop('disabled', false).text('Entrar');

    //   if (res.email_pendente) {
    //     mostrarEtapaVerificacao(loginAtual, res.msg);
    //     return;
    //   }

    //   $err.text(res.msg || 'Não foi possível entrar.');
    // }, 'json').fail(function () {
    //   $btn.prop('disabled', false).text('Entrar');
    //   $err.text('Erro de conexão.');
    // });
  });

  // SNIPPET — como integrar o reCAPTCHA v3 no JS de submit do login
  async function enviarLogin(login) {
    // Gera o token ANTES do submit. Resolve null se reCAPTCHA não
    // estiver configurado/carregado — backend já trata isso sem travar.
    var token = await Recaptcha.getToken('login');
  
    var fd = new FormData();
    fd.append('_csrf_token', AUTH_CONFIG.csrfToken);
    fd.append('login', login.login);
    fd.append('senha', login.senha);
    if (login.lembrar) fd.append('lembrar', '1');
    if (token) fd.append('recaptcha_token', token); // ← única linha nova
  
    return fetch(AUTH_CONFIG.baseUrl + '/login', {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: fd,
      credentials: 'same-origin',
    }).then(function (r) { return r.json(); });
  }

  // Mostrar/ocultar senha.
  // Mantido em apenas um handler para evitar alternância dupla.
  $(document).on('click', '.toggle-password', function () {
    const targetId = $(this).data('target');
    const $input   = $('#' + targetId);
    const isPass   = $input.attr('type') === 'password';

    $input.attr('type', isPass ? 'text' : 'password');
    $(this).toggleClass('active', isPass);
  });

  // ════════════════════════════════════════════════════════
  // LOGIN — ENTRAR COM CÓDIGO POR E-MAIL
  // ════════════════════════════════════════════════════════
  $('#btn-enviar-codigo').on('click', function () {
    const $btn = $(this);

    $btn.prop('disabled', true).text('Enviando...');

    $.post(BASE_URL + '/login/com-codigo', {
      login:       loginAtual,
      _csrf_token: CSRF_TOKEN,
    }, function (res) {
      $btn.prop('disabled', false).text('Enviar código');

      if (!res.ok) {
        notify(res.msg || 'Não foi possível enviar o código.', 'error');
        return;
      }

      $('#codigo-enviado-msg').empty()
        .append('Código enviado para ')
        .append($('<strong>').text(res.email_mask || 'seu e-mail'))
        .append('. Válido por 10 minutos.');

      $('#codigo-solicitacao').hide();
      $('#codigo-validacao').show();

      setTimeout(function () {
        $('#input-codigo').focus();
      }, 100);
    }, 'json').fail(function () {
      $btn.prop('disabled', false).text('Enviar código');
      notify('Erro de conexão. Tente novamente.', 'error');
    });
  });

  $('#input-codigo').on('input', function () {
    const codigo = $(this).val().replace(/\D/g, '').substring(0, 6);
    $(this).val(codigo);

    if (codigo.length === 6) {
      $('#form-codigo').trigger('submit');
    }
  });

  $('#form-codigo').on('submit', function (e) {
    e.preventDefault();

    const $btn   = $('#btn-validar-codigo');
    const $err   = $('#err-codigo');
    const codigo = $('#input-codigo').val().trim();

    $err.text('');

    if (codigo.length !== 6) {
      $err.text('Insira os 6 dígitos.');
      return;
    }

    $btn.prop('disabled', true).text('Verificando...');

    $.post(BASE_URL + '/login/validar-codigo', $(this).serialize(), function (res) {
      if (res.ok) {
        window.location.href = res.redirect;
        return;
      }

      $err.text(res.msg || 'Código inválido.');
      $btn.prop('disabled', false).text('Verificar e entrar');
    }, 'json').fail(function () {
      $btn.prop('disabled', false).text('Verificar e entrar');
      $err.text('Erro de conexão.');
    });
  });

  $('#btn-reenviar-codigo').on('click', function () {
    const $btn = $(this);

    $btn.prop('disabled', true).text('Reenviando...');

    $.post(BASE_URL + '/login/com-codigo', {
      login:       loginAtual,
      _csrf_token: CSRF_TOKEN,
    }, function (res) {
      $btn.prop('disabled', false).text('Não recebi o código — reenviar');

      if (res.ok) {
        $('#codigo-enviado-msg').empty()
          .append('Novo código enviado para ')
          .append($('<strong>').text(res.email_mask || 'seu e-mail'))
          .append('.');
      } else {
        notify(res.msg || 'Não foi possível reenviar o código.', 'error');
      }
    }, 'json').fail(function () {
      $btn.prop('disabled', false).text('Não recebi o código — reenviar');
      notify('Erro de conexão. Tente novamente.', 'error');
    });
  });

  

  // ════════════════════════════════════════════════════════
  // VERIFICAÇÃO DE E-MAIL PÓS-CADASTRO
  // ════════════════════════════════════════════════════════
  function mostrarEtapaVerificacao(login, msg, emailMask) {
    $('#hidden-login-verify').val(login);
    $('#verify-email-dest').text(emailMask || 'seu e-mail');

    // Seletores cobrem login.php E register.php ao mesmo tempo:
    // o que não existe na página atual vira no-op silencioso no
    // jQuery. Evita ter duas funções quase idênticas.
    $('#etapa-identidade, #etapa-senha, #form-register, .auth-back').hide();
    $('#etapa-verificacao').show();

    $('#auth-title').text('Verifique seu e-mail');
    $('#auth-sub').text(msg || 'Insira o código que enviamos.');

    setTimeout(function () {
      $('#input-verify-codigo').focus();
    }, 100);
  }

  $('#input-verify-codigo').on('input', function () {
    const codigo = $(this).val().replace(/\D/g, '').substring(0, 6);
    $(this).val(codigo);

    if (codigo.length === 6) {
      $('#form-verify-email').trigger('submit');
    }
  });

  $('#form-verify-email').on('submit', function (e) {
    e.preventDefault();

    const codigo = $('#input-verify-codigo').val().trim();
    const login  = $('#hidden-login-verify').val();
    const $err   = $('#err-verify-codigo');

    $err.text('');

    if (codigo.length !== 6) {
      $err.text('Insira os 6 dígitos.');
      return;
    }

    $.post(BASE_URL + '/login/validar-codigo', {
      login,
      codigo,
      tipo:        'email_verify',
      _csrf_token: CSRF_TOKEN,
    }, function (res) {
      if (res.ok) {
        // window.location.href = res.redirect;
        return;
      }

      $err.text(res.msg || 'Código inválido.');
    }, 'json').fail(function () {
      $err.text('Erro de conexão.');
    });
  });

  $('#btn-reenviar-verify').on('click', function () {
    const login = $('#hidden-login-verify').val();
    const $btn  = $(this);

    $btn.prop('disabled', true).text('Reenviando...');

    $.post(BASE_URL + '/cadastro/reenviar-codigo', {
      login,
      _csrf_token: CSRF_TOKEN,
    }, function (res) {
      $btn.prop('disabled', false).text('Reenviar código');

      if (res.ok) {
        $('#auth-sub').text(res.msg || 'Código reenviado.');
      } else {
        notify(res.msg || 'Não foi possível reenviar o código.', 'error');
      }
    }, 'json').fail(function () {
      $btn.prop('disabled', false).text('Reenviar código');
      notify('Erro de conexão. Tente novamente.', 'error');
    });
  });

  // Pré-preenche o login quando recebido por query string.
  const urlLogin = new URLSearchParams(window.location.search).get('login');
  if (urlLogin) {
    $('#input-login').val(urlLogin);
    setTimeout(function () {
      $('#form-identidade').trigger('submit');
    }, 300);
  }

  // ════════════════════════════════════════════════════════
  // RECUPERAÇÃO DE SENHA
  // ════════════════════════════════════════════════════════
  $('#form-forgot').on('submit', function (e) {
    e.preventDefault();
    clearErrors();

    const email = $('#email').val().trim();

    if (!isValidEmail(email)) {
      showError('#err-email', 'Informe um e-mail válido.');
      return;
    }

    const $btn = $(this).find('[type=submit]');
    setLoading($btn, true);

    $.ajax({
      url:      BASE_URL + '/recuperar-senha',
      type:     'POST',
      data:     $(this).serialize(),
      dataType: 'json',
    }).done(function (res) {
      notify(res.msg, res.ok ? 'success' : 'error');
      setLoading($btn, false);
      $('#email').val('');
    }).fail(function () {
      notify('Erro de conexão.', 'error');
      setLoading($btn, false);
    });
  });

  // ════════════════════════════════════════════════════════
  // REDEFINIÇÃO DE SENHA
  // ════════════════════════════════════════════════════════
  $('#form-reset').on('submit', function (e) {
    e.preventDefault();
    clearErrors();

    let valid = true;

    const senha = $('#senha').val();
    const conf  = $('#confirmar_senha').val();

    if (!isStrongPassword(senha)) {
      showError('#err-senha', 'Senha fraca.');
      valid = false;
    }

    if (senha !== conf) {
      showError('#err-confirmar', 'As senhas não conferem.');
      valid = false;
    }

    if (!valid) return;

    const $btn = $(this).find('[type=submit]');
    setLoading($btn, true);

    $.ajax({
      url:      BASE_URL + '/redefinir-senha',
      type:     'POST',
      data:     $(this).serialize(),
      dataType: 'json',
    }).done(function (res) {
      if (res.ok) {
        notify('Senha redefinida! Redirecionando...', 'success');
        setTimeout(function () {
          window.location.href = res.redirect;
        }, 2000);
        return;
      }

      notify(res.msg || 'Não foi possível redefinir a senha.', 'error');
      setLoading($btn, false);
    }).fail(function () {
      notify('Erro de conexão.', 'error');
      setLoading($btn, false);
    });
  });

  // ════════════════════════════════════════════════════════
  // 2FA SIMPLES
  // ════════════════════════════════════════════════════════
  // Evita duplo submit quando a tela usa o fluxo completo com escolha de canal.
  if (!$('#2fa-step-canal, #2fa-step-codigo').length) {
    $('#form-2fa').on('submit', function (e) {
      e.preventDefault();

      const code = $('#code').val().trim();

      if (code.length !== 6) {
        showError('#err-code', 'Código deve ter 6 dígitos.');
        return;
      }

      const $btn = $(this).find('[type=submit]');
      setLoading($btn, true);

      $.ajax({
        url:      BASE_URL + '/autenticacao-2fa',
        type:     'POST',
        data:     $(this).serialize(),
        dataType: 'json',
      }).done(function (res) {
        if (res.ok) {
          window.location.href = res.redirect || BASE_URL + '/minha-conta';
          return;
        }

        notify(res.msg || 'Código inválido.', 'error');
        $('#code').val('').focus();
        setLoading($btn, false);
      }).fail(function () {
        notify('Erro de conexão.', 'error');
        setLoading($btn, false);
      });
    });
  }

  // ════════════════════════════════════════════════════════
  // CADASTRO — INTERAÇÕES E VALIDAÇÕES
  // ════════════════════════════════════════════════════════
  $('#senha').on('input', function () {
    const val   = $(this).val();
    const score = calcPasswordStrength(val);
    const $bar  = $('#strength-bar');
    const $fill = $('#strength-fill');
    const $lbl  = $('#strength-label');

    if (!val.length) {
      $bar.hide();
      return;
    }

    $bar.show();

    const levels = [
      { label: 'Muito fraca', color: '#e63946', width: '20%'  },
      { label: 'Fraca',       color: '#f4a261', width: '40%'  },
      { label: 'Regular',     color: '#e9c46a', width: '60%'  },
      { label: 'Forte',       color: '#2a9d8f', width: '80%'  },
      { label: 'Muito forte', color: '#06d6a0', width: '100%' },
    ];

    const lvl = levels[Math.min(score, 4)];
    $fill.css({ width: lvl.width, background: lvl.color });
    $lbl.text(lvl.label).css('color', lvl.color);
  });

  $('#confirmar_senha').on('input', function () {
    const match = $(this).val() === $('#senha').val();

    showFieldStatus(
      '#confirmar_senha',
      '#err-confirmar',
      match ? '' : 'As senhas não conferem.'
    );

    $(this)
      .toggleClass('input-error', !match)
      .toggleClass('input-ok', match && $(this).val().length > 0);
  });

  $('#email', '#form-register').on('input', function () {
    clearTimeout(emailTimer);

    const email = $(this).val().trim();
    const $hint = $('#hint-email');
    const $err  = $('#err-email');

    if (!isValidEmail(email)) {
      $err.text('E-mail inválido.');
      $hint.hide();
      return;
    }

    $err.text('');

    emailTimer = setTimeout(function () {
      $.post(BASE_URL + '/cadastro/verificar-email', {
        email:       email,
        _csrf_token: $('input[name="_csrf_token"]').val(),
      }).done(function (res) {
        if (res.exists) {
          $err.text('Este e-mail já está cadastrado.');
          $hint.hide();
          return;
        }

        $err.text('');
        $hint.text('E-mail disponível.').addClass('hint-ok').show();
      });
    }, 600);
  });

  $(document).on('input', '.cpf-mask', function () {
    let v = $(this).val().replace(/\D/g, '').substring(0, 11);

    v = v.replace(/(\d{3})(\d)/, '$1.$2');
    v = v.replace(/(\d{3})(\d)/, '$1.$2');
    v = v.replace(/(\d{3})(\d{1,2})$/, '$1-$2');

    $(this).val(v);
  });

  $(document).on('input', '.phone-mask', function () {
    let v = $(this).val().replace(/\D/g, '').substring(0, 11);

    if (v.length <= 10) {
      v = v.replace(/(\d{2})(\d)/, '($1) $2');
      v = v.replace(/(\d{4})(\d)/, '$1-$2');
    } else {
      v = v.replace(/(\d{2})(\d)/, '($1) $2');
      v = v.replace(/(\d{5})(\d)/, '$1-$2');
    }

    $(this).val(v);
  });

  $('.otp-input').on('input', function () {
    const codigo = $(this).val().replace(/\D/g, '').substring(0, 6);
    $(this).val(codigo);

    if (codigo.length === 6) {
      $(this).closest('form').trigger('submit');
    }
  });

  $('#form-register').on('submit', function (e) {
    e.preventDefault();
    clearErrors();

    const $btn   = $('#btn-register');
    const nome   = $('#nome').val().trim();
    const email  = $('#email').val().trim();
    const senha  = $('#senha').val();
    const conf   = $('#confirmar_senha').val();
    const termos = $('#termos').is(':checked');

    let valid = true;

    if (nome.length < 3) {
      showError('#err-nome', 'Nome muito curto.');
      valid = false;
    }

    if (!isValidEmail(email)) {
      showError('#err-email', 'E-mail inválido.');
      valid = false;
    }

    if (!isStrongPassword(senha)) {
      showError('#err-senha', 'Senha fraca. Use 8+ chars, maiúsculas, minúsculas e números.');
      valid = false;
    }

    if (senha !== conf) {
      showError('#err-confirmar', 'As senhas não conferem.');
      valid = false;
    }

    if (!termos) {
      showError('#err-termos', 'Aceite os termos para continuar.');
      valid = false;
    }

    if (!valid) return;

    setLoading($btn, true);

    $.ajax({
      url:      BASE_URL + '/cadastro',
      type:     'POST',
      data:     $(this).serialize(),
      dataType: 'json',
    }).done(function (res) {
      if (res.ok) {
        if (res.ok) {
          if (res.verificacao) {
            // Mesma etapa que o login usa com email_pendente = true.
            // O cliente NÃO troca de página: digita o código e entra.
            mostrarEtapaVerificacao(
              $('#email').val().trim(), res.msg, res.email_mask
            );
            setLoading($btn, false);
            return;
          }
          // Fallback: cadastro sem verificação pendente
          notify(res.msg || 'Cadastro realizado com sucesso.', 'success');
          window.location.href = BASE_URL + '/login';
          return;
        }
        return;
      }

      if (res.errors) {
        res.errors.forEach(function (err) {
          notify(err, 'error');
        });
      } else {
        notify(res.msg || 'Não foi possível concluir o cadastro.', 'error');
      }

      setLoading($btn, false);
    }).fail(function () {
      notify('Erro de conexão. Tente novamente.', 'error');
      setLoading($btn, false);
    });
  });

  // ════════════════════════════════════════════════════════
  // UTILITÁRIOS
  // ════════════════════════════════════════════════════════
  function isValidEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(email || ''));
  }

  function isStrongPassword(pwd) {
    pwd = String(pwd || '');

    return pwd.length >= 8 &&
      /[A-Z]/.test(pwd) &&
      /[a-z]/.test(pwd) &&
      /[0-9]/.test(pwd);
  }

  function calcPasswordStrength(pwd) {
    pwd = String(pwd || '');

    let score = 0;

    if (pwd.length >= 8) score++;
    if (pwd.length >= 12) score++;
    if (/[A-Z]/.test(pwd)) score++;
    if (/[0-9]/.test(pwd)) score++;
    if (/[^a-zA-Z0-9]/.test(pwd)) score++;

    return Math.min(score, 4);
  }

  function showError(selector, msg) {
    $(selector).text(msg).addClass('visible');
    $(selector).closest('.form-group').find('.form-control').addClass('input-error');
  }

  function showFieldStatus(inputSel, errorSel, msg) {
    $(errorSel).text(msg).toggleClass('visible', msg.length > 0);
  }

  function clearErrors() {
    $('.field-error').text('').removeClass('visible');
    $('.form-control').removeClass('input-error input-ok');
  }

  function setLoading($btn, loading) {
    $btn.find('.btn-text').toggle(!loading);
    $btn.find('.btn-loading').toggle(loading);
    $btn.prop('disabled', loading);
  }

  /**
   * Notificação padronizada usando o plugin Toast.js.
   * Mantém fallback para ambientes onde o plugin ainda não foi carregado.
   */
  function notify(msg, type, opts) {
    msg  = msg || '';
    type = type || 'info';
    opts = opts || {};

    opts.position = 'bottom-center';

    if (window.Toast) {
      if (type === 'success' && typeof window.Toast.success === 'function') {
        window.Toast.success(msg, opts);
        return;
      }

      if (type === 'error' && typeof window.Toast.error === 'function') {
        window.Toast.error(msg, opts);
        return;
      }

      if (type === 'warning' && typeof window.Toast.warning === 'function') {
        window.Toast.warning(msg, opts);
        return;
      }

      if (type === 'info' && typeof window.Toast.info === 'function') {
        window.Toast.info(msg, opts);
        return;
      }

      if (typeof window.Toast.show === 'function') {
        window.Toast.show(Object.assign({}, opts, {
          type:    type,
          message: msg,
        }));
        return;
      }
    }

    if (typeof window.showToast === 'function') {
      window.showToast(msg, type);
      return;
    }

    if (msg) {
      alert(msg);
    }
  }
});






/**
 * auth-2fa.js
 * Fluxo de 2FA com escolha de canal:
 *   Etapa A: usuário escolhe canal (email/whatsapp/sms) → envia código
 *   Etapa B: usuário digita o código → valida → completa login
 *
 * Requer window.AUTH_CONFIG = { baseUrl, csrfToken }.
 */
(function () {
  'use strict';

  var CFG  = window.AUTH_CONFIG || {};
  var BASE = CFG.baseUrl || '';

  // Exposto: trata resposta do login (chamar onde processa o /login)
  window.handle2FAResponse = function (resp) {
    if (resp && resp.requer_2fa) {
      window.location.href = resp.redirect || (BASE + '/autenticacao-2fa');
      return true;
    }
    return false;
  };

  document.addEventListener('DOMContentLoaded', function () {
    var stepCanal  = document.getElementById('2fa-step-canal');
    var stepCodigo = document.getElementById('2fa-step-codigo');
    if (!stepCanal || !stepCodigo) return;

    var form       = document.getElementById('form-2fa');
    var input      = document.getElementById('code');
    var errEl      = document.getElementById('err-code');
    var banner     = document.getElementById('2fa-sent-banner');
    var subtitle   = document.getElementById('2fa-subtitle');
    var btnReenviar= document.getElementById('btn-2fa-reenviar');
    var btnTrocar  = document.getElementById('btn-2fa-trocar');
    var btnSubmit  = form ? form.querySelector('button[type="submit"]') : null;
    var btnTxt     = btnSubmit ? btnSubmit.querySelector('.btn-text') : null;
    var btnLd      = btnSubmit ? btnSubmit.querySelector('.btn-loading') : null;

    var canalAtual = null;

    function post(url, data) {
      var fd = new FormData();
      fd.append('_csrf_token', CFG.csrfToken);
      Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
      return fetch(BASE + url, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd,
        credentials: 'same-origin',
      }).then(function (r) { return r.json(); });
    }

    // ── Etapa A: escolher canal e enviar ──────────────
    function enviarPorCanal(canal, btnEl) {
      canalAtual = canal;
      if (btnEl) btnEl.style.opacity = '0.6';

      post('/autenticacao-2fa/enviar', { canal: canal })
        .then(function (resp) {
          if (btnEl) btnEl.style.opacity = '';

          if (resp.restart) { window.location.href = BASE + '/login'; return; }
          if (!resp.ok) { alert(resp.msg || 'Erro ao enviar código.'); return; }

          // Mostra etapa do código
          stepCanal.style.display  = 'none';
          stepCodigo.style.display = '';
          if (subtitle) subtitle.textContent = 'Digite o código de 6 dígitos que enviamos.';
          if (banner) {
            banner.innerHTML =
              '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
              'stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg> ' +
              resp.msg + ' <strong>' + (resp.destino || '') + '</strong>';
          }
          if (input) { input.value = ''; input.focus(); }

          // TOTP não tem "reenviar" — o código já vem do app do usuário,
          // não foi enviado por nenhum canal externo.
          if (btnReenviar) {
            btnReenviar.style.display = (canal === 'totp') ? 'none' : '';
          }
        })
        .catch(function () {
          if (btnEl) btnEl.style.opacity = '';
          alert('Erro de conexão. Tente novamente.');
        });
    }

    stepCanal.querySelectorAll('.twofa-channel:not(:disabled)').forEach(function (btn) {
      btn.addEventListener('click', function () {
        enviarPorCanal(btn.getAttribute('data-canal'), btn);
      });
    });

    // ── Trocar método: volta para etapa A ─────────────
    if (btnTrocar) {
      btnTrocar.addEventListener('click', function () {
        stepCodigo.style.display = 'none';
        stepCanal.style.display  = '';
        if (subtitle) subtitle.textContent = 'Como prefere receber seu código de verificação?';
      });
    }

    // ── Reenviar: reenvia pelo mesmo canal ────────────
    if (btnReenviar) {
      btnReenviar.addEventListener('click', function () {
        if (!canalAtual) return;
        btnReenviar.disabled = true;
        btnReenviar.textContent = 'Reenviando...';
        post('/autenticacao-2fa/enviar', { canal: canalAtual })
          .then(function (resp) {
            btnReenviar.disabled = false;
            btnReenviar.textContent = 'Reenviar código';
            if (resp.ok && banner) {
              banner.innerHTML =
                '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
                'stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg> ' +
                resp.msg + ' <strong>' + (resp.destino || '') + '</strong>';
            } else if (!resp.ok) {
              alert(resp.msg || 'Erro ao reenviar.');
            }
          })
          .catch(function () {
            btnReenviar.disabled = false;
            btnReenviar.textContent = 'Reenviar código';
          });
      });
    }

    // ── Etapa B: validar código ───────────────────────
    if (input) {
      input.addEventListener('input', function () {
        this.value = this.value.replace(/\D/g, '').slice(0, 6);
        if (errEl) errEl.textContent = '';
        if (this.value.length === 6) form.requestSubmit();
      });
    }

    function setLoading(on) {
      if (!btnSubmit) return;
      btnSubmit.disabled = on;
      if (btnTxt) btnTxt.style.display = on ? 'none' : '';
      if (btnLd)  btnLd.style.display  = on ? '' : 'none';
    }

    if (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (errEl) errEl.textContent = '';

        var code = (input && input.value || '').trim();
        if (code.length !== 6) {
          if (errEl) errEl.textContent = 'Digite os 6 dígitos do código.';
          return;
        }

        setLoading(true);

        post('/autenticacao-2fa', { code: code })
          .then(function (resp) {
            if (resp.ok) {
              window.location.href = resp.redirect || (BASE + '/minha-conta/conta');
              return;
            }
            if (resp.restart) { window.location.href = BASE + '/login'; return; }
            setLoading(false);
            if (errEl) errEl.textContent = resp.msg || 'Código inválido.';
            if (input) { input.value = ''; input.focus(); }
          })
          .catch(function () {
            setLoading(false);
            if (errEl) errEl.textContent = 'Erro de conexão. Tente novamente.';
          });
      });
    }
  });

})();
