// assets/js/customer.js
// Interações da área do cliente.

/**
 * assets/js/customer-drawer.js
 * Drawer lateral para a área do cliente.
 * API idêntica ao adminDrawer — window.customerDrawer({titulo, conteudo, tamanho, onClose})
 * Tamanhos: 'sm' (360px) | 'md' (520px) | 'lg' (680px) | 'xl' (860px)
 */
(function () {
  'use strict';

  let stack = [];

  window.customerDrawer = function ({
    titulo   = '',
    conteudo = '',
    tamanho  = 'md',
    onClose  = null,
  } = {}) {

    // Overlay único
    let overlay = document.getElementById('cust-drawer-overlay');
    if (!overlay) {
      overlay = document.createElement('div');
      overlay.id = 'cust-drawer-overlay';
      overlay.className = 'cust-drawer-overlay';
      overlay.addEventListener('click', fecharTopo);
      document.body.appendChild(overlay);
    }
    overlay.classList.add('cust-drawer-overlay--visible');

    // Cria drawer
    const el = document.createElement('div');
    el.className = `cust-drawer cust-drawer--${tamanho}`;
    el.innerHTML = `
      <div class="cust-drawer-header">
        <h3 class="cust-drawer-titulo"></h3>
        <button type="button" class="cust-drawer-close" aria-label="Fechar">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <line x1="18" y1="6" x2="6"  y2="18"/>
            <line x1="6"  y1="6" x2="18" y2="18"/>
          </svg>
        </button>
      </div>
      <div class="cust-drawer-body"></div>`;

    el.querySelector('.cust-drawer-titulo').textContent = titulo;
    el.querySelector('.cust-drawer-body').innerHTML     = conteudo;
    el.querySelector('.cust-drawer-close')
      .addEventListener('click', () => fechar(el, onClose));

    document.body.appendChild(el);
    stack.push({ el, onClose });

    // Anima entrada (double RAF para garantir transição CSS)
    requestAnimationFrame(() => {
      requestAnimationFrame(() => el.classList.add('cust-drawer--open'));
    });

    // ESC para fechar
    const onKey = function (e) {
      if (e.key === 'Escape') {
        fechar(el, onClose);
        document.removeEventListener('keydown', onKey);
      }
    };
    document.addEventListener('keydown', onKey);
    el._removeKey = () => document.removeEventListener('keydown', onKey);

    return {
      close:       () => fechar(el, onClose),
      setConteudo: (html) => { el.querySelector('.cust-drawer-body').innerHTML = html; },
      setTitulo:   (txt)  => { el.querySelector('.cust-drawer-titulo').textContent = txt; },
      body:        () => el.querySelector('.cust-drawer-body'),
    };
  };

  function notifyToast(msg, type, opts) {
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

  function fecharTopo() {
    if (!stack.length) return;
    const top = stack[stack.length - 1];
    fechar(top.el, top.onClose);
  }

  function fechar(el, onClose) {
    el.classList.remove('cust-drawer--open');
    el._removeKey?.();
    setTimeout(() => {
      el.remove();
      stack = stack.filter(function (s) { return s.el !== el; });
      if (!stack.length) {
        const ov = document.getElementById('cust-drawer-overlay');
        if (ov) ov.classList.remove('cust-drawer-overlay--visible');
      }
      if (typeof onClose === 'function') onClose();
    }, 320);
  }


//_csrf_token
/**
 * totp-sessions.js
 * Fluxo de setup/desativação do app autenticador (TOTP), integrado
 * dentro de views/customer/sessions.php. Usa window.SESS_CONFIG
 * (já definido na página) em vez de uma config própria.
 */


  var CFG  = window.SESS_CONFIG || {};
  var BASE = CFG.base || '';

  function post(url, data) {
    var fd = new FormData();
    fd.append('_csrf_token', CFG.csrf);
    Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
    return fetch(BASE + url, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: fd,
      credentials: 'same-origin',
    }).then(function (r) { return r.json(); });
  }

  document.addEventListener('DOMContentLoaded', function () {
    var modalSetup     = document.getElementById('modal-totp-setup');
    var btnIniciar      = document.getElementById('btn-iniciar-setup-totp');
    var btnCloseSetup    = document.getElementById('btn-close-totp-setup');
    var stepQr           = document.getElementById('totp-step-qr');
    var stepBackup        = document.getElementById('totp-step-backup');
    var qrCanvas           = document.getElementById('totp-qr-canvas');
    var secretText          = document.getElementById('totp-secret-text');
    var inputCode            = document.getElementById('totp-confirm-code');
    var errConfirm             = document.getElementById('err-totp-confirm');
    var btnConfirmar             = document.getElementById('btn-confirmar-totp');
    var backupGrid                 = document.getElementById('totp-backup-codes');
    var btnCopiarBackup             = document.getElementById('btn-copiar-backup');
    var btnFecharBackup              = document.getElementById('btn-fechar-backup');

    if (!modalSetup) return; // página sem os elementos de TOTP — não faz nada

    // ── Setup ──────────────────────────────────────────
    function abrirModalSetup() {
      modalSetup.style.display = 'flex';
      stepQr.style.display = '';
      stepBackup.style.display = 'none';
      inputCode.value = '';
      errConfirm.textContent = '';
      qrCanvas.innerHTML = '';

      post('/minha-conta/seguranca/totp/iniciar', {})
        .then(function (resp) {
          if (!resp.ok) {
            alert(resp.msg || 'Erro ao iniciar configuração.');
            fecharModalSetup();
            return;
          }
          secretText.textContent = resp.secret;
          new QRCode(qrCanvas, {
            text: resp.uri,
            width: 200,
            height: 200,
            colorDark: '#1e293b',
            colorLight: '#ffffff',
          });
        })
        .catch(function () {
          alert('Erro de conexão. Tente novamente.');
          fecharModalSetup();
        });
    }

    function fecharModalSetup() {
      modalSetup.style.display = 'none';
    }

    if (btnIniciar)    btnIniciar.addEventListener('click', abrirModalSetup);
    if (btnCloseSetup) btnCloseSetup.addEventListener('click', fecharModalSetup);

    if (inputCode) {
      inputCode.addEventListener('input', function () {
        this.value = this.value.replace(/\D/g, '').slice(0, 6);
        errConfirm.textContent = '';
      });
    }

    function confirmarSetup() {
      var code = (inputCode.value || '').trim();
      if (code.length !== 6) {
        errConfirm.textContent = 'Digite os 6 dígitos do código.';
        return;
      }

      btnConfirmar.disabled = true;
      post('/minha-conta/seguranca/totp/confirmar', { code: code })
        .then(function (resp) {
          btnConfirmar.disabled = false;
          if (!resp.ok) {
            if (resp.restart) { fecharModalSetup(); return; }
            errConfirm.textContent = resp.msg || 'Código inválido.';
            return;
          }

          backupGrid.innerHTML = '';
          (resp.codigos_backup || []).forEach(function (cod) {
            var span = document.createElement('span');
            span.textContent = cod;
            backupGrid.appendChild(span);
          });

          stepQr.style.display = 'none';
          stepBackup.style.display = '';
        })
        .catch(function () {
          btnConfirmar.disabled = false;
          errConfirm.textContent = 'Erro de conexão. Tente novamente.';
        });
    }

    if (btnConfirmar) btnConfirmar.addEventListener('click', confirmarSetup);

    if (btnCopiarBackup) {
      btnCopiarBackup.addEventListener('click', function () {
        var codigos = Array.from(backupGrid.querySelectorAll('span'))
          .map(function (s) { return s.textContent; })
          .join('\n');
        navigator.clipboard.writeText(codigos).then(function () {
          btnCopiarBackup.textContent = 'Copiado!';
          setTimeout(function () { btnCopiarBackup.textContent = 'Copiar todos os códigos'; }, 2000);
        });
      });
    }

    if (btnFecharBackup) {
      btnFecharBackup.addEventListener('click', function () {
        fecharModalSetup();
        window.location.reload(); // atualiza o card para o estado "ativo"
      });
    }

    // ── Regenerar códigos de backup (botão direto no card) ──
    var btnRegenerar = document.getElementById('btn-regenerar-backup');
    if (btnRegenerar) {
      btnRegenerar.addEventListener('click', function () {
        if (!confirm('Isso invalida os códigos de backup antigos. Continuar?')) return;

        post('/minha-conta/seguranca/totp/regenerar-backup', {})
          .then(function (resp) {
            if (!resp.ok) {
              alert(resp.msg || 'Erro ao gerar novos códigos.');
              return;
            }
            backupGrid.innerHTML = '';
            (resp.codigos_backup || []).forEach(function (cod) {
              var span = document.createElement('span');
              span.textContent = cod;
              backupGrid.appendChild(span);
            });
            modalSetup.style.display = 'flex';
            stepQr.style.display = 'none';
            stepBackup.style.display = '';
          })
          .catch(function () { alert('Erro de conexão.'); });
      });
    }

    // ── Desativação (código por e-mail, não senha — funciona para
    //    contas só-Google sem senha cadastrada) ──────────────────
    var modalDesativar        = document.getElementById('modal-totp-desativar');
    var btnAbrirDesativar      = document.getElementById('btn-abrir-desativar-totp');
    var btnCloseDesativar       = document.getElementById('btn-close-totp-desativar');
    var stepAviso                = document.getElementById('totp-desativar-step-aviso');
    var stepCodigo                 = document.getElementById('totp-desativar-step-codigo');
    var btnCancelarDesativar         = document.getElementById('btn-cancelar-totp-desativar');
    var btnEnviarCodigoDesativar       = document.getElementById('btn-enviar-codigo-desativar');
    var btnVoltarDesativar               = document.getElementById('btn-voltar-totp-desativar');
    var btnConfirmarDesativar              = document.getElementById('btn-confirmar-totp-desativar');
    var inputCodigoDesativar                 = document.getElementById('totp-desativar-codigo');
    var errDesativar                           = document.getElementById('err-totp-desativar');

    function abrirModalDesativar() {
      modalDesativar.style.display = 'flex';
      stepAviso.style.display = '';
      stepCodigo.style.display = 'none';
      inputCodigoDesativar.value = '';
      errDesativar.textContent = '';
    }
    function fecharModalDesativar() {
      modalDesativar.style.display = 'none';
    }

    if (btnAbrirDesativar)    btnAbrirDesativar.addEventListener('click', abrirModalDesativar);
    if (btnCloseDesativar)    btnCloseDesativar.addEventListener('click', fecharModalDesativar);
    if (btnCancelarDesativar) btnCancelarDesativar.addEventListener('click', fecharModalDesativar);

    if (btnVoltarDesativar) {
      btnVoltarDesativar.addEventListener('click', function () {
        stepCodigo.style.display = 'none';
        stepAviso.style.display = '';
      });
    }

    if (btnEnviarCodigoDesativar) {
      btnEnviarCodigoDesativar.addEventListener('click', function () {
        btnEnviarCodigoDesativar.disabled = true;
        post('/minha-conta/seguranca/totp/desativar-solicitar', {})
          .then(function (resp) {
            btnEnviarCodigoDesativar.disabled = false;
            if (!resp.ok) {
              alert(resp.msg || 'Erro ao enviar código.');
              return;
            }
            stepAviso.style.display = 'none';
            stepCodigo.style.display = '';
            inputCodigoDesativar.focus();
          })
          .catch(function () {
            btnEnviarCodigoDesativar.disabled = false;
            alert('Erro de conexão. Tente novamente.');
          });
      });
    }

    if (inputCodigoDesativar) {
      inputCodigoDesativar.addEventListener('input', function () {
        this.value = this.value.replace(/\D/g, '').slice(0, 6);
        errDesativar.textContent = '';
      });
    }

    if (btnConfirmarDesativar) {
      btnConfirmarDesativar.addEventListener('click', function () {
        var codigo = (inputCodigoDesativar.value || '').trim();
        if (codigo.length !== 6) {
          errDesativar.textContent = 'Digite os 6 dígitos do código.';
          return;
        }

        btnConfirmarDesativar.disabled = true;
        post('/minha-conta/seguranca/totp/desativar-confirmar', { code: codigo })
          .then(function (resp) {
            btnConfirmarDesativar.disabled = false;
            if (!resp.ok) {
              errDesativar.textContent = resp.msg || 'Código inválido.';
              return;
            }
            window.location.reload();
          })
          .catch(function () {
            btnConfirmarDesativar.disabled = false;
            errDesativar.textContent = 'Erro de conexão.';
          });
      });
    }
  });




$(function () {

  const BASE = BASE_URL  || '';
  const CSRF = CSRF_TOKEN || '';

  // ── Mostrar/ocultar senha ────────────────────────────────
  $(document).on('click', '.toggle-password', function () {
    const $inp = $('#' + $(this).data('target'));
    $inp.attr('type', $inp.attr('type') === 'password' ? 'text' : 'password');
    $(this).toggleClass('active');
  });

  // ── Endereços: modal ─────────────────────────────────────
  function openAddressModal(data = {}) {
    $('#modal-address-title').text(data.id ? 'Editar endereço' : 'Novo endereço');
    $('#edit-endereco-id').val(data.id || '');
    $('#m-nome').val(data.nome || '');
    $('#m-cep').val(data.cep || '');
    $('#m-logradouro').val(data.logradouro || '');
    $('#m-numero').val(data.numero || '');
    $('#m-comp').val(data.complemento || '');
    $('#m-bairro').val(data.bairro || '');
    $('#m-cidade').val(data.cidade || '');
    $('#m-estado').val(data.estado || '');
    $('#m-tel').val(data.telefone || '');
    $('#address-form-error').hide();
    $('#address-modal-backdrop').show();
    setTimeout(() => $('#m-nome').focus(), 100);
  }

  function closeAddressModal() {
    $('#address-modal-backdrop').hide();
    $('#form-address')[0].reset();
  }

  $('#btn-new-address').on('click', () => openAddressModal());
  $('#btn-close-address-modal, #btn-cancel-address').on('click', closeAddressModal);
  $('#address-modal-backdrop').on('click', function (e) {
    if ($(e.target).is('#address-modal-backdrop')) closeAddressModal();
  });

  $(document).on('click', '.btn-edit-address', function () {
    openAddressModal({
      id:          $(this).data('id'),
      nome:        $(this).data('nome'),
      cep:         $(this).data('cep'),
      logradouro:  $(this).data('logradouro'),
      numero:      $(this).data('numero'),
      complemento: $(this).data('complemento'),
      bairro:      $(this).data('bairro'),
      cidade:      $(this).data('cidade'),
      estado:      $(this).data('estado'),
      telefone:    $(this).data('telefone'),
    });
  });

  // Auto-fill CEP no modal
  let cepModalTimer;
  $('#m-cep').on('input', function () {
    const cep = $(this).val().replace(/\D/g, '');
    clearTimeout(cepModalTimer);
    if (cep.length !== 8) return;

    cepModalTimer = setTimeout(function () {
      $.get(BASE + '/checkout/cep', { cep }, function (res) {
        if (res.ok) {
          $('#m-logradouro').val(res.logradouro);
          $('#m-bairro').val(res.bairro);
          $('#m-cidade').val(res.cidade);
          $('#m-estado').val(res.estado);
          $('#m-numero').focus();
        }
      }, 'json');
    }, 600);
  });

  // Salvar endereço
  $('#form-address').on('submit', function (e) {
    e.preventDefault();
    const $btn = $('#btn-save-address-modal');
    $btn.prop('disabled', true).text('Salvando...');

    $.post(BASE + '/minha-conta/endereco/salvar', $(this).serialize(), function (res) {
      if (res.ok) {
        showToast(res.msg, 'success');
        closeAddressModal();
        setTimeout(() => location.reload(), 700);
      } else {
        const msg = res.errors ? res.errors.join('<br>') : res.msg;
        $('#address-form-error').html(msg).show();
        $btn.prop('disabled', false).text('Salvar endereço');
      }
    }, 'json').fail(function () {
      $('#address-form-error').text('Erro de conexão.').show();
      $btn.prop('disabled', false).text('Salvar endereço');
    });
  });

  // Excluir endereço
  $(document).on('click', '.btn-delete-address', function () {
    if (!confirm('Tem certeza que deseja excluir este endereço?')) return;
    const id   = $(this).data('id');
    const $row = $(`#address-item-${id}`);

    $.post(BASE + '/minha-conta/endereco/excluir', {
      endereco_id: id, _csrf_token: CSRF_TOKEN
    }, function (res) {
      if (res.ok) {
        $row.slideUp(300, () => $row.remove());
        showToast(res.msg, 'success');
      } else {
        showToast(res.msg, 'error');
      }
    }, 'json');
  });

  // Definir endereço como principal
  $(document).on('click', '.btn-set-principal', function () {
    const id = $(this).data('id');
    $.post(BASE + '/minha-conta/endereco/principal', {
      endereco_id: id, _csrf_token: CSRF_TOKEN
    }, function (res) {
      if (res.ok) {
        showToast(res.msg, 'success');
        setTimeout(() => location.reload(), 600);
      }
    }, 'json');
  });

  // ── Cartões ──────────────────────────────────────────────

  $(document).on('click', '.btn-delete-card', function () {
    const id = $(this).data('id');
    const $row = $(`#card-item-${id}`);

    Toast.action('Remover este cartão salvo?', [
      {
        label: 'Cancelar',
        primary: false,        
        action: function () {}
      },
      {
        label: 'Remover',
        primary: true,
        action: function () {
          $.post(BASE + '/minha-conta/cartao/excluir', {
            cartao_id: id,
            _csrf_token: CSRF_TOKEN
          }, function (res) {
            if (res.ok) {
              $row.slideUp(300, () => $row.remove());
              Toast.success(res.msg || 'Cartão removido com sucesso.',
                {
                  duration: 7000,
                  position: 'bottom-center'
                }
              );
            } else {
              Toast.error(res.msg || 'Não foi possível remover o cartão.');
            }
          }, 'json').fail(function () {
            Toast.error('Erro ao remover o cartão.');
          });
        }
      }
    ], {
      type: 'warning',
      title: 'Atenção',
      duration: 7000,
      position: 'bottom-center',
    });
  });

  $(document).on('click', '.btn-set-principal-card', function () {
    const id = $(this).data('id');
    $.post(BASE + '/minha-conta/cartao/principal', {
      cartao_id: id, _csrf_token: CSRF_TOKEN
    }, function (res) {
      if (res.ok) {
        showToast(res.msg, 'success');
        setTimeout(() => location.reload(), 600);
      }
    }, 'json');
  });

  // ── Perfil ────────────────────────────────────────────────

  // Preview de avatar
  $('#avatar-input').on('change', function () {
    const file = this.files[0];
    if (!file) return;

    if (file.size > 5 * 1024 * 1024) {
      showToast('Imagem muito grande. Máximo 5MB.', 'error');
      this.value = '';
      return;
    }

    const reader = new FileReader();
    reader.onload = function (e) {
      const src = e.target.result;
      if ($('#avatar-preview').length) {
        $('#avatar-preview').attr('src', src);
      } else {
        // Substitui o div de inicial por uma img
        const $img = $('<img>').attr({ src, alt: '', id: 'avatar-preview' }).addClass('profile-avatar');
        $('#avatar-preview-initial').replaceWith($img);
      }
    };
    reader.readAsDataURL(file);
  });

  // Salvar perfil
  $('#form-profile').on('submit', function (e) {
    e.preventDefault();
    const $btn  = $('#btn-save-profile');
    const $err  = $('#profile-error');
    $btn.prop('disabled', true).text('Salvando...');

    const formData = new FormData(this);
    // Adiciona o arquivo de avatar se houver
    const avatarFile = $('#avatar-input')[0].files[0];
    if (avatarFile) formData.set('avatar', avatarFile);

    $.ajax({
      url:         BASE + '/minha-conta/perfil/salvar',
      type:        'POST',
      data:        formData,
      processData: false,
      contentType: false,
      dataType:    'json',
    }).done(function (res) {
      if (res.ok) {
        showToast(res.msg, 'success');
        $err.hide();
      } else {
        const msg = res.errors ? res.errors.join('<br>') : res.msg;
        $err.html(msg).show();
      }
      $btn.prop('disabled', false).text('Salvar alterações');
    }).fail(function () {
      $err.text('Erro de conexão.').show();
      $btn.prop('disabled', false).text('Salvar alterações');
    });
  });

  // Trocar senha
  // $('#form-password').on('submit', function (e) {
  //   e.preventDefault();
  //   const $btn = $('#btn-save-password');
  //   const $msg = $('#password-msg');
  //   const nova = $('#p-nova-senha').val();
  //   const conf = $('#p-conf-senha').val();

  //   if (nova !== conf) {
  //     $msg.text('As senhas não conferem.').removeClass('form-alert--success').addClass('form-alert form-alert--error').show();
  //     return;
  //   }

  //   $btn.prop('disabled', true).text('Alterando...');

  //   $.post(BASE + '/minha-conta/senha/alterar', $(this).serialize(), function (res) {
  //     $msg.text(res.msg)
  //         .removeClass('form-alert--error form-alert--success')
  //         .addClass('form-alert ' + (res.ok ? 'form-alert--success' : 'form-alert--error'))
  //         .show();

  //     if (res.ok) {
  //       $('#form-password')[0].reset();
  //     }
  //     $btn.prop('disabled', false).text('Alterar senha');
  //   }, 'json').fail(function () {
  //     $msg.text('Erro de conexão.').show();
  //     $btn.prop('disabled', false).text('Alterar senha');
  //   });
  // });

  // ── Wishlist: remover ────────────────────────────────────
  $(document).on('click', '.btn-wishlist', function () {
    if (!$(this).hasClass('active')) return; // Lida com adicionar via main.js
    const pid = $(this).data('product-id');
    $.post(BASE + '/minha-conta/favorito/remover', {
      produto_id: pid, _csrf_token: CSRF_TOKEN
    }, function (res) {
      if (res.ok && window.location.pathname.includes('favoritos')) {
        // Remove o card da lista de favoritos
        $(`[data-product-id="${pid}"]`).closest('.product-card').slideUp(300, function () {
          $(this).remove();
        });
        showToast(res.msg, 'info');
      }
    }, 'json');
  });

  (function () {
    var $btn = $('#btn-ver-mais-hist');
    if (!$btn.length) return;
    $btn.on('click', function () {
      var raw = document.getElementById('od-hist-data');
      if (!raw) return;
      var ev = [];
      try { ev = JSON.parse(raw.textContent || raw.innerHTML); } catch(e) { return; }
      var corMap = {
        success:'#16a34a', warning:'#f59e0b',
        danger:'#dc2626', info:'#0284c7', primary:'#2563eb', gray:'#94a3b8',
      };
      var html = '<div class="od-hist-list">';
      ev.forEach(function (e, i) {
        html +=
          '<div class="odh-event-wrap">' +
            '<div class="odh-icon-col">' +
              '<div class="odh-event-icon odh-icon--' + e.cor + (i===0?' odh-icon--first':'') + '">' +
                '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="10" cy="10" r="7.5"/><polyline points="10,6 10,10 13,12"/></svg>' +
              '</div>' +
              (i < ev.length-1 ? '<div class="odh-dashed-line"></div>' : '') +
            '</div>' +
            '<div class="odh-event-card' + (i===0?' odh-event-card--latest':'') + '">' +
              '<div class="odh-event-meta">' +
                '<span class="odh-event-date">' + e.data + '</span>' +
                '<span class="odh-sep">·</span>' +
                '<span class="odh-event-time">' + e.hora + '</span>' +
                (e.localidade ? '<span class="odh-sep">·</span><span class="odh-event-local">' + e.localidade + '</span>' : '') +
              '</div>' +
              '<strong>' + e.label + '</strong>' +
              (e.observacao ? '<p>' + e.observacao + '</p>' : '') +
            '</div>' +
          '</div>';
      });
      html += '</div>';
      if (typeof window.customerDrawer === 'function') {
        window.customerDrawer({
          titulo:   'Histórico completo (' + ev.length + ' eventos)',
          conteudo: html,
          tamanho:  'md',
        });
      }
    });
  })();
  
  // Substitua/adicione estas seções no customer.js

  (function ($) {
    // ── Lightbox ──────────────────────────────────────────
    const $lb     = $('#dash-fotos-lightbox');
    const $lbImg  = $('#dash-lb-img');
    const $lbLbl  = $('#dash-lb-label');

    $(document).on('click keydown', '.dash-foto-item', function (e) {
      if (e.type === 'keydown' && e.key !== 'Enter') return;
      // Não abre se clicou no badge da moto
      if ($(e.target).closest('.dash-foto-badge--moto').length) return;

      const src   = $(this).data('src');
      const label = $(this).data('label');
      if (!src) return;

      $lbImg.attr('src', src).attr('alt', label);
      $lbLbl.text(label);
      $lb.prop('hidden', false);
      document.body.style.overflow = 'hidden';
    });

    function fecharLb() {
      $lb.prop('hidden', true);
      document.body.style.overflow = '';
      $lbImg.attr('src', '');
    }

    $('#dash-lb-close').on('click', fecharLb);
    $('.dash-fotos-lb-backdrop').on('click', fecharLb);
    $(document).on('keydown', function (e) {
      if (e.key === 'Escape' && !$lb.prop('hidden')) fecharLb();
    });

    // Pagina de carrinho compartilhados

    $(document).on('click', '.cc-btn-copiar', function () {
      alert()
      var link  = $(this).data('link');
      var input = document.getElementById($(this).data('input'));
      if (navigator.clipboard) {
        navigator.clipboard.writeText(link);
      } else {
        input.select();
        document.execCommand('copy');
      }
      $(this).addClass('copiado').text('✓ Copiado!');
      setTimeout(() => $(this).removeClass('copiado').html('<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg> Copiar link'), 2000);
    });

    function copiarLink(link, $btn) {
      if (navigator.clipboard) {
        navigator.clipboard.writeText(link);
      } else {
        var el = document.createElement('input');
        el.value = link; document.body.appendChild(el);
        el.select(); document.execCommand('copy');
        document.body.removeChild(el);
      }
      $btn.text('✓ Copiado!').addClass('copiado');
      setTimeout(function () { $btn.text('🔗 Copiar link').removeClass('copiado'); }, 2000);
    }

    $('#btn-copiar-link').on('click', function () {
      copiarLink($(this).data('link'), $(this));
    });
    $('#btn-copiar-detalhe').on('click', function () {
      copiarLink($(this).data('link'), $(this));
    });

  }(jQuery));

(function ($) {
  'use strict';

  const BASE = BASE_URL  || '';
  const CSRF = CSRF_TOKEN || '';
  const STAR_HINTS = ['Péssimo','Ruim','Regular','Bom','Excelente'];

  let currentRating = 0;
  let uploadToken   = '';

  // Estado do upload de mídia, redesenhado para nunca colidir IDs:
  // uploadCounter só cresce (nunca reaproveita um número já usado),
  // mesmo depois de remover uma foto — diferente do esquema anterior
  // (localIdx = array.length - 1), que reciclava índices ao remover e
  // adicionar, gerando dois elementos com o mesmo id no DOM.
  let uploadCounter  = 0;
  let activeIds      = new Set();  // ids ainda visíveis/pendentes na tela
  let arquivosPorId  = {};         // id → nome do arquivo já confirmado pelo servidor

  // ── Filtros ──────────────────────────────────────────
  $('.cav-filter-btn').on('click', function () {
    $('.cav-filter-btn').removeClass('is-active');
    $(this).addClass('is-active');
    const f = $(this).data('filtro');

    $('#cav-grid .cav-card').each(function () {
      const cardF = $(this).data('filtro');
      $(this).toggle(f === 'todos' || f === cardF);
    });
  });

  // ── Abrir modal de avaliação ─────────────────────────
  function abrirModal(produtoId, nomeProduto) {
    resetModal();
    $('#cav-form-produto-id').val(produtoId);
    $('#cav-modal-produto-nome').text(nomeProduto || '');
    $('#cav-review-modal').addClass('is-open');
    $('body').css('overflow', 'hidden');
    setTimeout(() => $('#cav-star-picker').find('.sm-star-picker-star').first().trigger('focus'), 100);
  }

  function fecharModal() {
    $('#cav-review-modal').removeClass('is-open');
    $('body').css('overflow', '');
  }

  function resetModal() {
    currentRating  = 0;
    uploadToken    = '';
    activeIds      = new Set();
    arquivosPorId  = {};
    highlightStars(0);
    $('#cav-star-hint').text('');
    $('#cav-nota-val').val('0');
    $('#cav-titulo, #cav-comentario').val('');
    $('#cav-titulo-counter').text('0/150');
    $('#cav-comentario-counter').text('0/2000').removeClass('is-near-limit');
    $('#cav-upload-previews, #cav-upload-progress').empty();
    $('#cav-upload-progress').hide();
    $('#cav-review-form').show();
    $('#cav-result').prop('hidden', true);
  }

  // Clique no card (imagem) ou no botão
  $(document).on('click', '[data-open-review]', function (e) {
    e.preventDefault();
    const pid  = $(this).data('open-review');
    const nome = $(this).data('nome')
              || $(this).closest('.cav-card').find('.cav-card-nome').text().trim();
    abrirModal(pid, nome);
  });

  $(document).on('keydown', '[data-open-review]', function (e) {
    if (e.key === 'Enter') $(this).trigger('click');
  });

  $('#cav-modal-close, #cav-result-close').on('click', fecharModal);
  $('#cav-review-modal').on('click', function (e) {
    if ($(e.target).is('#cav-review-modal')) fecharModal();
  });
  $(document).on('keydown', function (e) {
    if (e.key === 'Escape' && $('#cav-review-modal').hasClass('is-open')) fecharModal();
  });

  // ── Star picker ──────────────────────────────────────
  function highlightStars(val) {
    $('#cav-star-picker .sm-star-picker-star').each(function () {
      const v = parseInt($(this).data('val'));
      $(this).toggleClass('is-active', v <= val)
             .attr('aria-checked', v === val ? 'true' : 'false');
    });
  }

  // Navegação por teclado no seletor de estrelas — antes só funcionava
  // com mouse. Enter/Espaço seleciona; setas movem entre as estrelas.
  $('#cav-star-picker').on('keydown', '.sm-star-picker-star', function (e) {
    const val = parseInt($(this).data('val'));

    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      $(this).trigger('click');
    } else if (e.key === 'ArrowRight' && val < 5) {
      e.preventDefault();
      $(`.sm-star-picker-star[data-val="${val + 1}"]`).trigger('focus').trigger('click');
    } else if (e.key === 'ArrowLeft' && val > 1) {
      e.preventDefault();
      $(`.sm-star-picker-star[data-val="${val - 1}"]`).trigger('focus').trigger('click');
    }
  });

  $('#cav-star-picker')
    .on('mouseenter', '.sm-star-picker-star', function () {
      highlightStars(parseInt($(this).data('val')));
      $('#cav-star-hint').text(STAR_HINTS[parseInt($(this).data('val'))-1]);
    })
    .on('mouseleave', function () {
      highlightStars(currentRating);
      $('#cav-star-hint').text(currentRating ? STAR_HINTS[currentRating-1] : '');
    })
    .on('click', '.sm-star-picker-star', function () {
      currentRating = parseInt($(this).data('val'));
      $('#cav-nota-val').val(currentRating);
      highlightStars(currentRating);
      $('#cav-star-hint').text(STAR_HINTS[currentRating-1]);
    });

  // ── Contadores de caracteres ──────────────────────────
  function bindCounter(inputSel, counterSel, max) {
    $(inputSel).on('input', function () {
      const len = $(this).val().length;
      $(counterSel).text(`${len}/${max}`).toggleClass('is-near-limit', len > max * 0.9);
    });
  }
  bindCounter('#cav-titulo',     '#cav-titulo-counter',     150);
  bindCounter('#cav-comentario', '#cav-comentario-counter', 2000);

  // ── Upload de fotos ──────────────────────────────────
  const $zone = $('#cav-upload-zone');

  $zone.on('dragover', function (e) {
    e.preventDefault(); $(this).addClass('is-dragover');
  }).on('dragleave drop', function (e) {
    e.preventDefault(); $(this).removeClass('is-dragover');
    if (e.type === 'drop') handleFiles(e.originalEvent.dataTransfer.files);
  });

  $('#cav-upload-input').on('change', function () {
    handleFiles(this.files); this.value = '';
  });

  function handleFiles(files) {
    const MAX = 5;
    const slotsLivres = MAX - activeIds.size;
    if (slotsLivres <= 0) {
      notifyToast('Você já adicionou o máximo de 5 fotos.', 'error');
      return;
    }

    Array.from(files).slice(0, slotsLivres).forEach(file => {
      if (!file.type.startsWith('image/')) {
        notifyToast('Apenas imagens são aceitas.', 'error'); return;
      }
      if (file.size > 5 * 1024 * 1024) {
        notifyToast('Arquivo muito grande (máx. 5MB).', 'error'); return;
      }

      // uploadCounter só cresce — nunca reaproveita um id já usado,
      // mesmo depois de remover uma foto no meio da lista. Evita a
      // colisão que existia antes (dois elementos com o mesmo id no
      // DOM quando se removia uma foto e adicionava outra).
      const id = uploadCounter++;
      activeIds.add(id);

      const reader = new FileReader();
      reader.onload = e => {
        const $item = $(`
          <div class="sm-upload-preview-item" data-id="${id}">
            <img src="${e.target.result}" alt="">
            <button type="button" class="sm-upload-preview-remove" data-id="${id}" aria-label="Remover foto">
              <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
          </div>`);
        $('#cav-upload-previews').append($item);
      };
      reader.readAsDataURL(file);

      uploadFileToServer(file, id);
    });
  }

  function uploadFileToServer(file, id) {
    const $prog = $(`
      <div class="sm-upload-progress-item" id="cav-prog-${id}">
        <div class="sm-upload-progress-info">
          <span class="sm-upload-progress-name">${escHtml(file.name)}</span>
          <span class="sm-upload-progress-status">Enviando…</span>
        </div>
        <div class="sm-upload-progress-track">
          <div class="sm-upload-progress-bar" id="cav-bar-${id}"></div>
        </div>
      </div>`);
    $('#cav-upload-progress').show().append($prog);

    const fd = new FormData();
    fd.append('midia', file);
    fd.append('token', uploadToken);
    fd.append('_csrf_token', CSRF);

    let pct = 0;
    const iv = setInterval(() => {
      pct = Math.min(pct + 15, 85);
      $(`#cav-bar-${id}`).css('width', pct + '%');
    }, 120);

    $.ajax({
      url: BASE + '/avaliacoes/upload-midia', type: 'POST',
      data: fd, processData: false, contentType: false,
      success: function (res) {
        clearInterval(iv);
        if (res.ok) {
          uploadToken = res.token;
          $('#cav-upload-token').val(uploadToken);
          arquivosPorId[id] = res.arquivo;
          $(`#cav-bar-${id}`).css('width','100%');
          $(`#cav-prog-${id}`).addClass('is-done')
            .find('.sm-upload-progress-status').text('✓');
        } else {
          // Upload falhou — remove a pré-visualização órfã em vez de
          // deixar uma foto "quebrada" sem nenhuma forma de removê-la
          // a não ser recarregando a página.
          activeIds.delete(id);
          $(`.sm-upload-preview-item[data-id="${id}"]`).remove();
          $(`#cav-prog-${id}`).addClass('is-error')
            .find('.sm-upload-progress-status').text('Erro');
          notifyToast(res.msg || 'Erro ao enviar foto.', 'error');
        }
      },
      error: function () {
        clearInterval(iv);
        activeIds.delete(id);
        $(`.sm-upload-preview-item[data-id="${id}"]`).remove();
        $(`#cav-prog-${id}`).addClass('is-error')
          .find('.sm-upload-progress-status').text('Falhou');
        notifyToast('Erro de conexão ao enviar foto.', 'error');
      },
    });
  }

  $('#cav-upload-previews').on('click', '.sm-upload-preview-remove', function () {
    const id = parseInt($(this).data('id'));
    activeIds.delete(id);
    $(this).closest('.sm-upload-preview-item').remove();
    $(`#cav-prog-${id}`).remove();

    const arquivo = arquivosPorId[id];
    if (arquivo) {
      delete arquivosPorId[id];
      // Remove de verdade no servidor — antes, o "X" só escondia a
      // foto da tela, mas ela continuava sendo anexada à avaliação na
      // publicação (vincularMidias busca tudo que está sob o token).
      $.post(BASE + '/avaliacoes/remover-midia', {
        token: uploadToken,
        arquivo: arquivo,
        _csrf_token: CSRF,
      });
    }
  });

  // ── Submit ────────────────────────────────────────────
  $('#cav-review-form').on('submit', function (e) {
    e.preventDefault();

    if (!currentRating) {
      notifyToast('Selecione uma nota.', 'warning'); return;
    }
    const comentario = $('#cav-comentario').val().trim();
    if (!comentario) {
      notifyToast('Escreva um comentário.', 'warning'); return;
    }

    const $btn = $('#cav-submit').prop('disabled', true).text('Publicando…');

    $.ajax({
      url: BASE + '/avaliacoes/enviar', type: 'POST',
      data: $(this).serialize(), dataType: 'json',
      success: function (res) {
        $btn.prop('disabled', false).text('Publicar avaliação');
        if (res.ok) {
          $('#cav-review-form').hide();
          $('#cav-result').prop('hidden', false);
          $('#cav-result-title').text('Avaliação enviada!');
          $('#cav-result-msg').text(res.msg || 'Obrigado pelo seu feedback.');

          // Marca o card como avaliado na lista
          const pid = $('#cav-form-produto-id').val();
          const $card = $(`.cav-card[data-produto-id="${pid}"]`);
          $card.addClass('is-avaliado').data('filtro','avaliados');
          $card.find('.cav-card-rate-hint').remove();
          $card.find('.cav-card-img').attr({
            'role': null, 'tabindex': null, 'data-open-review': null,
          });
          $card.find('.cav-btn-avaliar')
               .replaceWith('<span class="cav-btn-ver">Ver avaliação</span>');
          $card.find('.cav-card-img').prepend(`
            <div class="cav-card-done">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                   stroke="white" stroke-width="3" stroke-linecap="round">
                <polyline points="20 6 9 17 4 12"/>
              </svg>
              Avaliado
            </div>`);

          // Atualiza os contadores dos filtros e do cabeçalho — sem
          // isso, o card vira "Avaliado" mas os números acima (Para
          // avaliar / Já avaliados / pendentes) ficam errados até a
          // página ser recarregada.
          atualizarContadoresPosAvaliacao();

          // Detecta o próximo produto ainda não avaliado e configura
          // o botão "Avaliar próximo" — esconde se não restar nenhum.
          const $proximo = $('.cav-card:not(.is-avaliado)').first();
          if ($proximo.length) {
            const proxId   = $proximo.data('produto-id');
            const proxNome = $proximo.find('.cav-card-nome').text().trim();
            $('#cav-btn-proximo')
              .show()
              .off('click')
              .on('click', function () { abrirModal(proxId, proxNome); });
          } else {
            $('#cav-btn-proximo').hide();
            $('#cav-result-msg').text('Você avaliou todos os seus produtos. Obrigado!');
          }
        } else {
          notifyToast(res.msg || 'Erro ao enviar.', 'error');
        }
      },
      error: function () {
        $btn.prop('disabled', false).text('Publicar avaliação');
        notifyToast('Erro de conexão. Tente novamente.', 'error');
      },
    });
  });

  // Atualiza filtros (Para avaliar / Já avaliados) e o cabeçalho
  // (X avaliados / Y pendentes) sem precisar recarregar a página.
  function atualizarContadoresPosAvaliacao() {
    const $cAvaliar   = $('#cav-count-avaliar');
    const $cAvaliados = $('#cav-count-avaliados');
    $cAvaliar.text(Math.max(0, parseInt($cAvaliar.text()) - 1));
    $cAvaliados.text(parseInt($cAvaliados.text()) + 1);

    const totalAvaliados = parseInt($('#cav-stat-avaliados-num').text() || '0') + 1;
    const totalPendentes = Math.max(0, parseInt($('#cav-stat-pendentes-num').text() || '0') - 1);

    $('#cav-stat-avaliados-num').text(totalAvaliados);
    $('#cav-stat-avaliados-label').text(totalAvaliados !== 1 ? 'avaliados' : 'avaliado');

    $('#cav-stat-pendentes-num').text(totalPendentes);
    $('#cav-stat-pendentes-label').text(totalPendentes !== 1 ? 'pendentes' : 'pendente');
    $('#cav-stat-pendentes').toggle(totalPendentes > 0);
  }



  function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s; return d.innerHTML;
  }

}(jQuery));

  // Verify

  // Adicionar no customer.js

  // ── Verificação de documento ─────────────────────────────
  let pollingTimer = null;

  $('#btn-open-verify').on('click', function () {
    $('#modal-verify-backdrop').show();
    $('#form-verify-doc')[0]?.reset();
    $('#doc-upload-placeholder').show();
    $('#doc-upload-preview').hide();
    $('#btn-submit-doc').prop('disabled', true);
    $('#verify-error').hide();
    $('#verify-analyzing, #verify-result').hide();
    $('#verify-method-tabs, #verify-panel-desktop').show();
    $('#verify-panel-mobile').hide();
  });

  $('#btn-close-verify, #btn-cancel-verify').on('click', function () {
    $('#modal-verify-backdrop').hide();
    clearPolling();
  });

  $('#modal-verify-backdrop').on('click', function (e) {
    if ($(e.target).is('#modal-verify-backdrop')) {
      $(this).hide();
      clearPolling();
    }
  });

  // Tabs desktop / mobile
  $('.verify-tab').on('click', function () {
    $('.verify-tab').removeClass('active');
    $(this).addClass('active');
    const method = $(this).data('method');
    $('#verify-panel-desktop').toggle(method === 'desktop');
    $('#verify-panel-mobile').toggle(method === 'mobile');

    if (method === 'mobile') {
      loadQrCode();
    } else {
      clearPolling();
    }
  });

  // Área de drag-and-drop
  const $dropArea = $('#doc-upload-area');

  $dropArea.on('dragover', function (e) {
    e.preventDefault();
    $(this).addClass('dragover');
  }).on('dragleave drop', function (e) {
    e.preventDefault();
    $(this).removeClass('dragover');
  });

  $dropArea.on('drop', function (e) {
    const file = e.originalEvent.dataTransfer.files[0];
    if (file) setDocFile(file);
  });

  $('#btn-choose-doc').on('click', () => $('#doc-file-input').trigger('click'));

  $('#doc-file-input').on('change', function () {
    if (this.files[0]) setDocFile(this.files[0]);
  });

  function setDocFile(file) {
    const allowed = ['image/jpeg', 'image/png', 'image/webp'];
    const $err    = $('#verify-error');

    if (!allowed.includes(file.type)) {
      $err.text('Formato inválido. Use JPG, PNG ou WEBP.').show();
      return;
    }
    if (file.size > 10 * 1024 * 1024) {
      $err.text('Arquivo muito grande. Máximo 10MB.').show();
      return;
    }

    $err.hide();

    const reader = new FileReader();
    reader.onload = function (e) {
      $('#doc-preview-img').attr('src', e.target.result);
      $('#doc-upload-placeholder').hide();
      $('#doc-upload-preview').show();
      $('#btn-submit-doc').prop('disabled', false);
    };
    reader.readAsDataURL(file);

    // Associa o arquivo ao input
    const dt = new DataTransfer();
    dt.items.add(file);
    document.getElementById('doc-file-input').files = dt.files;
  }

  $('#doc-remove-btn').on('click', function () {
    $('#doc-file-input').val('');
    $('#doc-upload-preview').hide();
    $('#doc-upload-placeholder').show();
    $('#btn-submit-doc').prop('disabled', true);
  });

  // Submit do formulário de verificação
  $('#form-verify-doc').on('submit', function (e) {
    e.preventDefault();

    const $btn = $('#btn-submit-doc');
    $btn.prop('disabled', true);

    // Mostra tela de análise
    $('#verify-method-tabs').hide();
    $('#verify-panel-desktop').hide();
    $('#verify-analyzing').show();

    const fd = new FormData(this);

    $.ajax({
      url:         BASE + '/minha-conta/documento/upload',
      type:        'POST',
      data:        fd,
      processData: false,
      contentType: false,
      dataType:    'json',
    }).done(function (res) {
      $('#verify-analyzing').hide();
      mostrarResultado(res);
    }).fail(function () {
      $('#verify-analyzing').hide();
      $('#verify-method-tabs, #verify-panel-desktop').show();
      $('#verify-error').text('Erro de conexão. Tente novamente.').show();
      $btn.prop('disabled', false);
    });
  });

  function mostrarResultado(res) {
    const $result = $('#verify-result');
    const $icon   = $('#result-icon');
    const $title  = $('#result-title');
    const $msg    = $('#result-msg');

    if (res.ok && res.status === 'verificado') {
      $icon.html('<svg width="52" height="52" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2" stroke-linecap="round"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>');
      $title.text('Perfil verificado!').css('color', '#065f46');
      $msg.text('Seu documento foi analisado com sucesso. Seu perfil agora está verificado.');
    } else if (res.status === 'rejeitado') {
      $icon.html('<svg width="52" height="52" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>');
      $title.text('Documento não aprovado').css('color', '#991b1b');
      $msg.text(res.msg || 'Tente novamente com uma foto melhor.');
    } else {
      $icon.html('<svg width="52" height="52" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>');
      $title.text('Em análise').css('color', '#92400e');
      $msg.text('Seu documento está sendo analisado. Aguarde...');
    }

    $result.show();
  }

  $('#btn-verify-done').on('click', function () {
    $('#modal-verify-backdrop').hide();
    location.reload();
  });

  // ── QR Code ──────────────────────────────────────────────
  let qrExpiraEm = null;
  let qrCountdownTimer = null;

  function loadQrCode() {
    $('#qr-loading').show();
    $('#qr-content').hide();
    clearPolling();

    $.post(BASE + '/minha-conta/documento/qr', { _csrf_token: CSRF_TOKEN }, function (res) {
      if (!res.ok) {
        $('#qr-loading').html('<p style="color:var(--c-primary)">Erro ao gerar QR Code.</p>');
        return;
      }

      $('#qr-loading').hide();
      $('#qr-content').show();
      $('#qr-url-input').val(res.url);

      // Gera QR code com biblioteca JS
      $('#qr-code-container').empty();
      gerarQrCode(res.url);

      // Countdown
      qrExpiraEm = new Date(res.expira_em.replace(' ', 'T'));
      iniciarCountdown();

      // Inicia polling para detectar quando o celular enviou
      iniciarPolling();
    }, 'json').fail(function () {
      $('#qr-loading').html('<p style="color:var(--c-primary)">Erro de conexão.</p>');
    });
  }

  function gerarQrCode(url) {
    // Usa QR Code via API do Google Charts (não envia dados sensíveis, apenas a URL)
    const encodedUrl = encodeURIComponent(url);
    const qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodedUrl}&margin=10`;

    $('<img>').attr({
      src:    qrUrl,
      width:  200,
      height: 200,
      alt:    'QR Code para verificação de documento',
      style:  'border-radius:8px;border:1px solid var(--c-border);',
    }).appendTo('#qr-code-container');
  }

  function iniciarCountdown() {
    clearInterval(qrCountdownTimer);
    qrCountdownTimer = setInterval(function () {
      const diff = Math.max(0, Math.floor((qrExpiraEm - Date.now()) / 1000));
      const min  = Math.floor(diff / 60);
      const sec  = diff % 60;
      $('#qr-expiry').text(`${min}:${sec.toString().padStart(2, '0')}`);

      if (diff === 0) {
        clearInterval(qrCountdownTimer);
        clearPolling();
        $('#qr-content').html('<p style="text-align:center;color:var(--c-primary);padding:20px 0">QR Code expirado. <button type="button" class="btn btn-outline btn-sm" onclick="loadQrCode()">Gerar novo</button></p>');
      }
    }, 1000);
  }

  function iniciarPolling() {
    clearPolling();
    pollingTimer = setInterval(function () {
      $.get(BASE + '/minha-conta/documento/status', function (res) {
        if (res.status === 'verificado' || res.status === 'rejeitado') {
          clearPolling();
          $('#verify-method-tabs').hide();
          $('#verify-panel-mobile').hide();
          mostrarResultado({ ok: res.status === 'verificado', status: res.status, msg: res.msg });
        }
      }, 'json');
    }, 3000); // verifica a cada 3 segundos
  }

  function clearPolling() {
    if (pollingTimer)     { clearInterval(pollingTimer);    pollingTimer = null; }
    if (qrCountdownTimer) { clearInterval(qrCountdownTimer); qrCountdownTimer = null; }
  }

  $('#btn-copy-qr-url').on('click', function () {
    const url = $('#qr-url-input').val();
    if (navigator.clipboard) {
      navigator.clipboard.writeText(url).then(function () {
        showToast('Link copiado!', 'success');
      });
    }
  });

  $('#btn-regen-qr').on('click', loadQrCode);

  // Polling para status "em_analise" ao carregar a página
  if ($('#verification-progress').length) {
    const statusPoller = setInterval(function () {
      $.get(BASE + '/minha-conta/documento/status', function (res) {
        if (res.status === 'verificado' || res.status === 'rejeitado') {
          clearInterval(statusPoller);
          location.reload();
        }
      }, 'json');
    }, 4000);
  }


  // ── Wishlist na área do cliente ───────────────────────────
(function () {
    if (!$('#wishlist-grid, .wishlist-empty').length && !$('#btn-criar-lista').length) return;

    // ── Abrir lista (ver itens) ──────────────────────────
    // Substituir o handler de abertura da lista
    $(document).on('click', '.wishlist-card-body', function () {
        const $card   = $(this).closest('.wishlist-card');
        const listaId = $card.data('lista-id')
                    || $(this).data('lista-id');

        if (!listaId) {
            console.warn('[Wishlist] lista-id não encontrado no card');
            return;
        }

        const nome = $card.find('.wishlist-card-nome').text().trim()
                  || 'Minha lista';

        $('#modal-lista-titulo').text(nome);
        $('#modal-lista-body').html('<div class="wishlist-loading">Carregando...</div>');
        $('#modal-lista').fadeIn(200);

        $.get(BASE + '/favoritos/itens', { lista_id: listaId }, function (res) {
            if (!res.ok) {
                $('#modal-lista-body').html(
                    '<p class="wishlist-erro">Erro ao carregar lista.</p>'
                );
                return;
            }
            renderizarItensLista(res.itens, listaId);
        }, 'json').fail(function (xhr) {
            console.error('[Wishlist] Erro:', xhr.status, xhr.responseText);
            $('#modal-lista-body').html(
                '<p class="wishlist-erro">Erro ao carregar lista.</p>'
            );
        });
    });

    function renderizarItensLista(itens, listaId) {
      if (!itens.length) {
        $('#modal-lista-body').html(`
          <div class="wishlist-empty-lista">
            <p>Esta lista está vazia.</p>
            <a href="${BASE}/busca" class="btn btn-primary btn-sm">
              Explorar produtos
            </a>
          </div>
        `);
        return;
      }

      let html = '<div class="wishlist-itens-grid">';
      itens.forEach(function (item) {
        html += `
          <div class="wishlist-item" data-item-id="${item.item_id}">
            <a href="${BASE}/produto/${item.slug}" class="wishlist-item-img">
              <img src="${item.imagem_url}" alt="${item.nome}"
                  width="80" height="80" loading="lazy">
            </a>
            <div class="wishlist-item-info">
              <a href="${BASE}/produto/${item.slug}"
                class="wishlist-item-nome">${item.nome}</a>
              <span class="wishlist-item-preco">${item.preco_fmt}</span>
            </div>
            <div class="wishlist-item-actions">
              <a href="${BASE}/produto/${item.slug}"
                class="btn btn-primary btn-sm">Ver produto</a>
              <button type="button" class="btn btn-ghost btn-sm btn-remover-item"
                      data-item-id="${item.item_id}"
                      data-lista-id="${listaId}">
                Remover
              </button>
            </div>
          </div>`;
      });
      html += '</div>';
      $('#modal-lista-body').html(html);
    }

    // Fechar modal de itens
    $(document).on('click', '#btn-fechar-modal-lista, #modal-lista', function (e) {
      if (e.target === this) $('#modal-lista').fadeOut(200);
    });

    // ── Remover item da lista ────────────────────────────
    $(document).on('click', '.btn-remover-item', function () {
      const itemId  = $(this).data('item-id');
      const listaId = $(this).data('lista-id');
      const $item   = $(this).closest('.wishlist-item');

      $(this).prop('disabled', true).text('...');

      $.post(BASE + '/favoritos/item/remover', {
        item_id     : itemId,
        _csrf_token : CSRF_TOKEN,
      }, function (res) {
        if (res.ok) {
          $item.slideUp(200, function () { $(this).remove(); });
          showToast(res.msg, 'success');

          // Atualiza contador na grid
          const $card = $(`.wishlist-card[data-lista-id="${listaId}"]`);
          const count = parseInt($card.find('.wishlist-card-count').text()) - 1;
          $card.find('.wishlist-card-count').text(
            count + (count === 1 ? ' produto' : ' produtos')
          );
        }
      }, 'json');
    });

    // ── Criar lista ──────────────────────────────────────
    $(document).on('click', '#btn-criar-lista', function () {
      $('#form-lista-id').val('');
      $('#form-lista-nome').val('');
      $('#form-lista-descricao').val('');
      $('#form-lista-publica').prop('checked', false);
      $('#modal-form-titulo').text('Nova lista');
      $('#modal-form-lista').fadeIn(200);
      setTimeout(() => $('#form-lista-nome').focus(), 100);
    });

    // ── Editar lista ─────────────────────────────────────
    $(document).on('click', '.btn-editar-lista', function (e) {
      e.stopPropagation();
      const $btn = $(this);
      $('#form-lista-id').val($btn.data('lista-id'));
      $('#form-lista-nome').val($btn.data('nome'));
      $('#form-lista-descricao').val($btn.data('descricao'));
      $('#form-lista-publica').prop('checked', $btn.data('publica') == 1);
      $('#modal-form-titulo').text('Editar lista');
      $('#modal-form-lista').fadeIn(200);
      setTimeout(() => $('#form-lista-nome').focus(), 100);
    });

    // Fechar modal de form
    $(document).on('click', '#btn-fechar-modal-form, #btn-cancelar-form-lista', function () {
      $('#modal-form-lista').fadeOut(200);
    });

    // ── Salvar (criar ou editar) ──────────────────────────
    $(document).on('submit', '#form-lista', function (e) {
      e.preventDefault();

      const listaId = $('#form-lista-id').val();
      const isEdicao = !!listaId;
      const url      = isEdicao
        ? BASE + '/favoritos/editar'
        : BASE + '/favoritos/criar';

      const $btn = $('#btn-salvar-lista');
      $btn.prop('disabled', true).text('Salvando...');

      $.post(url, $(this).serialize(), function (res) {
        $btn.prop('disabled', false).text('Salvar');

        if (!res.ok) {
          showToast(res.msg, 'error');
          return;
        }

        $('#modal-form-lista').fadeOut(200);
        showToast(res.msg, 'success');

        // Atualiza ou adiciona o card
        if (isEdicao) {
          console.log(res);
          
          const $card = $(`.wishlist-card[data-lista-id="${listaId}"]`);
          $card.find('.wishlist-card-nome').text(res.nome);
          $card.find('.btn-editar-lista').data('nome', res.nome);
          $card.find('.btn-editar-lista').attr('data-descricao', res.descricao);
          $card.find('.btn-editar-lista').data('publica', res.publica);
        } else {
          // Recarrega a página para mostrar o novo card
          setTimeout(() => window.location.reload(), 800);
        }
      }, 'json').fail(function () {
        $btn.prop('disabled', false).text('Salvar');
      });
    });

    // ── Excluir lista ─────────────────────────────────────
    $(document).on('click', '.btn-excluir-lista', function (e) {
      e.stopPropagation();
      const listaId = $(this).data('lista-id');
      const nome    = $(this).data('nome');

      if (!confirm('Excluir a lista "' + nome + '"? Esta ação não pode ser desfeita.')) return;

      $.post(BASE + '/favoritos/excluir', {
        lista_id    : listaId,
        _csrf_token : CSRF_TOKEN,
      }, function (res) {
        if (res.ok) {
          $(`.wishlist-card[data-lista-id="${listaId}"]`).slideUp(250, function () {
            $(this).remove();
            if (!$('.wishlist-card').length) {
              window.location.reload();
            }
          });
          showToast(res.msg, 'success');
        }
      }, 'json');
    });

  })();

// ═══ MINHA GARAGEM ═══════════════════════════════════════
(function () {
  if (!document.querySelector('.garagem-actions')) return;
  
  // const CSRF       = window.CSRF_TOKEN || '';
  const modalAdd   = document.getElementById('modal-add-moto');
  const modalEdit  = document.getElementById('modal-edit-moto');

  // ── Abrir modal ────────────────────────────────────────
  function abrirModal(modal) { modal.classList.add('is-open'); document.body.style.overflow = 'hidden'; }
  function fecharModal(modal) { modal.classList.remove('is-open'); document.body.style.overflow = ''; }

  document.getElementById('btn-add-moto')?.addEventListener('click',       () => abrirModal(modalAdd));
  document.getElementById('btn-add-moto-empty')?.addEventListener('click', () => abrirModal(modalAdd));

  document.querySelectorAll('.garagem-modal-close, .garagem-modal-backdrop').forEach(el => {
    el.addEventListener('click', () => {
      if($(modalAdd).length > 0){
        fecharModal(modalAdd);
      }
      fecharModal(modalEdit);
    });
  });

  // ── Cascata: Montadora → Modelos ───────────────────────
  document.getElementById('add-montadora')?.addEventListener('change', function () {
    const id   = this.value;
    const $mod = document.getElementById('add-modelo');
    const $ano = document.getElementById('add-ano');

    $mod.innerHTML = '<option value="">Carregando...</option>';
    $mod.disabled  = true;
    $ano.innerHTML = '<option value="">Todos os anos</option>';
    $ano.disabled  = true;

    if (!id) { $mod.innerHTML = '<option value="">Todos os modelos</option>'; return; }

    fetch(`${BASE}/ajax/moto/modelos?montadora_id=${id}`)
      .then(r => r.json())
      .then(list => {
        let opts = '<option value="">Todos os modelos</option>';
        list.forEach(m => { opts += `<option value="${m.id}">${m.nome}</option>`; });
        $mod.innerHTML = opts;
        $mod.disabled  = false;
      });
  });

  document.getElementById('add-modelo')?.addEventListener('change', function () {
    const id   = this.value;
    const $ano = document.getElementById('add-ano');
    $ano.innerHTML = '<option value="">Carregando...</option>';
    $ano.disabled  = true;

    if (!id) { $ano.innerHTML = '<option value="">Todos os anos</option>'; return; }

    fetch(`${BASE}/ajax/moto/anos?modelo_id=${id}`)
      .then(r => r.json())
      .then(list => {
        let opts = '<option value="">Todos os anos</option>';
        list.forEach(a => { opts += `<option value="${a.ano}">${a.ano}</option>`; });
        $ano.innerHTML = opts;
        $ano.disabled  = false;
      });
  });

  // ── Color presets ──────────────────────────────────────
  document.querySelectorAll('.garagem-color-preset').forEach(btn => {
    btn.addEventListener('click', function () {
      const cor = this.dataset.cor;
      const target = this.dataset.target
        ? document.querySelector(this.dataset.target)
        : this.closest('.garagem-color-picker').querySelector('input[type="color"]');
      if (target) target.value = cor;
    });
  });

  // ── Submit Adicionar ───────────────────────────────────
  document.getElementById('form-add-moto')?.addEventListener('submit', async function (e) {
    e.preventDefault();
    const fd  = new FormData(this);
    const btn = this.querySelector('.btn-salvar-moto');
    btn.disabled = true; btn.textContent = 'Salvando...';

    try {
      const res  = await fetch(`${BASE}/minha-conta/garagem/adicionar`, { method:'POST', body: fd });
      const data = await res.json();
      if (data.ok) {
        showToast('Moto adicionada!', 'success');
        setTimeout(() => window.location.reload(), 600);
      } else {
        showToast(data.msg || 'Erro ao adicionar.', 'error');
        btn.disabled = false; btn.textContent = 'Adicionar à garagem';
      }
    } catch (err) {
      showToast('Erro de conexão.', 'error');
      btn.disabled = false; btn.textContent = 'Adicionar à garagem';
    }
  });

  // ── Editar moto ────────────────────────────────────────
  document.querySelectorAll('.btn-editar-moto').forEach(btn => {
    btn.addEventListener('click', function () {
      document.getElementById('edit-id').value          = this.dataset.id;
      document.getElementById('edit-apelido').value     = this.dataset.apelido     || '';
      document.getElementById('edit-cor').value         = this.dataset.cor         || '#dc2626';
      document.getElementById('edit-placa').value       = this.dataset.placa       || '';
      document.getElementById('edit-observacoes').value = this.dataset.observacoes || '';
      abrirModal(modalEdit);
    });
  });

  document.getElementById('form-edit-moto')?.addEventListener('submit', async function (e) {
    e.preventDefault();
    const fd  = new FormData(this);
    const btn = this.querySelector('.btn-salvar-moto');
    btn.disabled = true; btn.textContent = 'Salvando...';

    try {
      const res  = await fetch(`${BASE}/minha-conta/garagem/atualizar`, { method:'POST', body: fd });
      const data = await res.json();
      if (data.ok) {
        showToast('Atualizado!', 'success');
        setTimeout(() => window.location.reload(), 600);
      } else {
        showToast(data.msg || 'Erro.', 'error');
        btn.disabled = false; btn.textContent = 'Salvar alterações';
      }
    } catch (err) { showToast('Erro de conexão.', 'error'); }
  });

  // ── Ativar moto ────────────────────────────────────────
  document.querySelectorAll('.btn-ativar-moto').forEach(btn => {
    btn.addEventListener('click', async function () {
      const fd = new FormData();
      fd.append('id', this.dataset.id);
      fd.append('_csrf_token', CSRF_TOKEN);

      try {
        const res  = await fetch(`${BASE}/minha-conta/garagem/ativar`, { method:'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
          showToast('Moto ativada!', 'success');
          setTimeout(() => window.location.reload(), 500);
        }
      } catch (err) { showToast('Erro.', 'error'); }
    });
  });

  // ── Remover moto ───────────────────────────────────────
  document.querySelectorAll('.btn-remover-moto').forEach(btn => {
    btn.addEventListener('click', async function () {
      const label = this.dataset.label;

      const confirmar = window.adminConfirm
        ? await window.adminConfirm({
            titulo: 'Remover moto?',
            mensagem: `A moto "${label}" será removida da sua garagem.`,
            tipo: 'danger',
            confirmar: 'Remover',
          })
        : confirm(`Remover "${label}" da garagem?`);

      if (!confirmar) return;

      const fd = new FormData();
      fd.append('id', this.dataset.id);
      fd.append('_csrf_token', CSRF_TOKEN);

      try {
        const res  = await fetch(`${BASE}/minha-conta/garagem/remover`, { method:'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
          showToast('Moto removida.', 'success');
          setTimeout(() => window.location.reload(), 500);
        }
      } catch (err) { showToast('Erro.', 'error'); }
    });
  });

  // ESC fecha modais
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
      fecharModal(modalAdd);
      fecharModal(modalEdit);
    }
  });

})();

// ═══ PÁGINA INDIVIDUAL DA MOTO ════════════════════════════
(function () {
  if (!document.querySelector('.garagem-actions')) return;

  // const BASE = window.BASE_URL  || '';
  // const CSRF = window.CSRF_TOKEN || '';

  // ── Atualizar Insta ───────────────────────────────────
  document.getElementById('form-insta-cliente')?.addEventListener('submit', async function (e) {
    e.preventDefault();
    const fd  = new FormData(this);
    const btn = this.querySelector('.moto-insta-save');
    btn.disabled = true; btn.textContent = '...';

    try {
      const res  = await fetch(`${BASE}/minha-conta/perfil/insta`, { method: 'POST', body: fd });
      const data = await res.json();

      if (data.ok) {
        showToast(data.insta ? `Salvo: ${data.insta}` : 'Instagram removido', 'success');

        // Atualiza preview ao vivo
        const preview = document.getElementById('moto-insta-preview');
        if (data.insta) {
          if (preview) {
            preview.querySelector('a').href = `https://instagram.com/${data.insta.replace('@','')}`;
            preview.querySelector('a').lastChild.textContent = ' ' + data.insta;
          }
        } else if (preview) {
          preview.remove();
        }
      } else {
        showToast(data.msg || 'Formato inválido.', 'error');
      }
    } catch (err) {
      showToast('Erro de conexão.', 'error');
    } finally {
      btn.disabled = false; btn.textContent = 'Salvar';
    }
  });

  // Sanitização ao digitar
  document.getElementById('input-insta')?.addEventListener('input', function () {
    let val = this.value;
    // Remove URL completa colada
    val = val.replace(/^https?:\/\/(www\.)?instagram\.com\//i, '');
    val = val.replace(/^@/, '');
    val = val.split('?')[0].replace(/[^a-zA-Z0-9._]/g, '');
    if (val !== this.value) this.value = val;
  });

  // ── Tornar moto ativa ─────────────────────────────────
  document.getElementById('btn-tornar-ativa')?.addEventListener('click', async function () {
    const fd = new FormData();
    fd.append('id', this.dataset.id);
    fd.append('_csrf_token', CSRF_TOKEN);

    try {
      const res  = await fetch(`${BASE}/minha-conta/garagem/ativar`, { method: 'POST', body: fd });
      const data = await res.json();
      if (data.ok) {
        showToast('Moto ativada!', 'success');
        setTimeout(() => location.reload(), 600);
      }
    } catch (e) { showToast('Erro.', 'error'); }
  });

  // ── Upload de fotos ───────────────────────────────────
  const btnAdd = document.getElementById('btn-add-fotos');
  const input  = document.getElementById('input-fotos-upload');
  const grid   = document.getElementById('moto-galeria-grid');
  const veiculoId = grid?.dataset.veiculoId || new URLSearchParams(window.location.search).get('id');

  // btnAdd?.addEventListener('click', () => input.click());

  // input?.addEventListener('change', async function () {
  //   if (!this.files.length) return;
  //   const files = Array.from(this.files);

  //   for (const file of files) {
  //     const fd = new FormData();
  //     fd.append('foto', file);
  //     fd.append('visibilidade', 'privado'); // sempre começa privada
  //     fd.append('_csrf_token', CSRF_TOKEN);

  //     showToast(`Enviando ${file.name}...`, 'info');

  //     try {
  //       const res  = await fetch(`${BASE}/minha-conta/garagem/${veiculoId}/fotos/upload`, {
  //         method: 'POST', body: fd
  //       });
  //       const data = await res.json();
  //       if (data.ok) {
  //         showToast(data.msg || 'Foto enviada!', 'success');
  //       } else {
  //         showToast(data.msg || 'Erro.', 'error');
  //       }
  //     } catch (e) { showToast('Erro de conexão.', 'error'); }
  //   }

  //   input.value = '';
  //   // setTimeout(() => location.reload(), 800);
  // });

  // ── Ações na galeria ──────────────────────────────────
  grid?.addEventListener('click', async (e) => {
    const btn = e.target.closest('.galeria-action');
    if (!btn) return;
    e.stopPropagation();

    const foto   = btn.closest('.galeria-foto');
    const fotoId = foto.dataset.id;
    const action = btn.dataset.action;

    let url = '';
    const fd = new FormData();
    fd.append('id', fotoId);
    fd.append('_csrf_token', CSRF_TOKEN);

    if (action === 'capa') {
      url = `${BASE}/minha-conta/garagem/foto/capa`;
    } else if (action === 'vis') {
      const novaVis = foto.dataset.vis === 'privado' ? 'publico' : 'privado';
      fd.append('visibilidade', novaVis);
      url = `${BASE}/minha-conta/garagem/foto/atualizar`;
      if (novaVis === 'publico') {
        if (!confirm('Tornar pública? A foto passará por moderação antes de aparecer no site.')) return;
      }
    } else if (action === 'remover') {
      if (!confirm('Remover esta foto?')) return;
      url = `${BASE}/minha-conta/garagem/foto/remover`;
    }

    try {
      const res  = await fetch(url, { method: 'POST', body: fd });
      const data = await res.json();
      if (data.ok) {
        showToast('Atualizado!', 'success');
        setTimeout(() => location.reload(), 500);
      }
    } catch (e) { showToast('Erro.', 'error'); }
  });

})();

// ═══ GALERIA DA MOTO ════════════════════════════════════
(function () {
  const modalFotos = document.getElementById('modal-fotos-moto');
  if (!modalFotos) return;

  
  const elGrid     = document.getElementById('fotos-grid');
  const elZone     = document.getElementById('fotos-upload-zone');
  const elInput    = document.getElementById('fotos-input');
  const elLoading  = document.getElementById('fotos-loading');
  const elLabel    = document.getElementById('fotos-moto-label');
  const elVeicId   = document.getElementById('fotos-veiculo-id');

  let veiculoIdAtual = null;

  // Abrir
  document.querySelectorAll('.btn-fotos-moto').forEach(btn => {
    btn.addEventListener('click', () => {
      veiculoIdAtual = btn.dataset.id;
      elVeicId.value = veiculoIdAtual;
      elLabel.textContent = btn.dataset.label;
      modalFotos.classList.add('is-open');
      document.body.style.overflow = 'hidden';
      carregarFotos();
    });
  });

  modalFotos.querySelectorAll('.garagem-modal-close, .garagem-modal-backdrop').forEach(el => {
    el.addEventListener('click', () => {
      modalFotos.classList.remove('is-open');
      document.body.style.overflow = '';

      setTimeout(() => location.reload(), 600);
    });
  });

  // Drag & drop + click
  elZone.addEventListener('click', e => {
    // Não dispara click se for em radio
    if (e.target.closest('.fotos-vis-opt')) return;
    elInput.click();
  });

  ['dragenter', 'dragover'].forEach(ev => {
    elZone.addEventListener(ev, e => {
      e.preventDefault(); elZone.classList.add('is-dragover');
    });
  });
  ['dragleave', 'drop'].forEach(ev => {
    elZone.addEventListener(ev, e => {
      e.preventDefault(); elZone.classList.remove('is-dragover');
    });
  });

  elZone.addEventListener('drop', e => {
    e.preventDefault();
    const files = Array.from(e.dataTransfer.files).filter(f => f.type.startsWith('image/'));
    if (files.length) processarUploads(files);
  });

  elInput.addEventListener('change', () => {
    if (elInput.files.length) processarUploads(Array.from(elInput.files));
    elInput.value = '';
  });

  async function processarUploads(files) {
    const visibilidade = document.querySelector('input[name="vis_default"]:checked').value;

    elLoading.hidden = false;

    for (const file of files) {
      try {
        const fd = new FormData();
        fd.append('foto', file);
        fd.append('visibilidade', visibilidade);
        fd.append('_csrf_token', CSRF_TOKEN);

        const res  = await fetch(`${BASE}/minha-conta/garagem/${veiculoIdAtual}/fotos/upload`, {
          method: 'POST', body: fd
        });
        const data = await res.json();

        if (data.ok) {
          showToast(data.msg || 'Foto enviada!', 'success');
        } else {
          showToast(data.msg || 'Erro no upload.', 'error');
        }
      } catch (err) {
        showToast('Erro de conexão.', 'error');
      }
    }

    elLoading.hidden = true;
    carregarFotos();
  }

  async function carregarFotos() {
    elGrid.innerHTML = '';
    elLoading.hidden = false;

    try {
      const res  = await fetch(`${BASE}/minha-conta/garagem/${veiculoIdAtual}/fotos`);
      const data = await res.json();

      if (!data.fotos.length) {
        elGrid.innerHTML = '<p style="grid-column:1/-1;text-align:center;padding:24px;color:#94a3b8;font-size:13px;">Nenhuma foto ainda. Adicione a primeira acima.</p>';
        return;
      }

      data.fotos.forEach(f => elGrid.appendChild(criarFotoCard(f)));
    } catch (err) {
      showToast('Erro ao carregar fotos.', 'error');
    } finally {
      elLoading.hidden = true;
    }
  }

  function criarFotoCard(f) {
    const div = document.createElement('div');
    div.className = 'foto-item';
    div.dataset.id = f.id;

    const url = `${BASE}/uploads/garagem/${f.arquivo_thumb}`;

    let badges = '';
    if (Number(f.capa) === 1) {
      badges += '<span class="foto-badge foto-badge--capa">⭐ CAPA</span>';
    }
    if (f.visibilidade === 'publico') {
      if (f.status_moderacao === 'aprovada') {
        badges += '<span class="foto-badge foto-badge--publico">🌐 Pública</span>';
      } else if (f.status_moderacao === 'pendente') {
        badges += '<span class="foto-badge foto-badge--pendente">⏳ Em análise</span>';
      } else {
        badges += '<span class="foto-badge foto-badge--rejeitada">✕ Rejeitada</span>';
      }
    } else {
      badges += '<span class="foto-badge">🔒 Privada</span>';
    }

    div.innerHTML = `
      <img src="${url}" alt="" loading="lazy">
      <div class="foto-item-badges">${badges}</div>
      <div class="foto-item-actions">
        <button type="button" class="foto-action" data-action="capa" title="Tornar capa">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
          </svg>
        </button>
        <button type="button" class="foto-action" data-action="toggle-vis"
                title="${f.visibilidade === 'privado' ? 'Tornar pública' : 'Tornar privada'}">
          ${f.visibilidade === 'privado'
            ? '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"/></svg>'
            : '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>'
          }
        </button>
        <button type="button" class="foto-action foto-action--remover"
                data-action="remover" title="Remover">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <polyline points="3 6 5 6 21 6"/>
            <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
          </svg>
        </button>
      </div>
    `;
    return div;
  }

  // Delegação de ações
  elGrid.addEventListener('click', async (e) => {
    const btn = e.target.closest('.foto-action');
    if (!btn) return;
    e.stopPropagation();

    const fotoItem = btn.closest('.foto-item');
    const fotoId   = fotoItem.dataset.id;
    const action   = btn.dataset.action;

    const fd = new FormData();
    fd.append('id', fotoId);
    fd.append('_csrf_token', CSRF_TOKEN);

    try {
      let url, data;

      if (action === 'capa') {
        url  = `${BASE}/minha-conta/garagem/foto/capa`;
      } else if (action === 'toggle-vis') {
        const isPrivada = fotoItem.querySelector('.foto-badge')?.textContent.includes('Privada');
        fd.append('visibilidade', isPrivada ? 'publico' : 'privado');
        url = `${BASE}/minha-conta/garagem/foto/atualizar`;
      } else if (action === 'remover') {
        const ok = confirm('Remover esta foto?');
        if (!ok) return;
        url = `${BASE}/minha-conta/garagem/foto/remover`;
      }

      const res  = await fetch(url, { method: 'POST', body: fd });
      data = await res.json();

      if (data.ok) {
        showToast('Atualizado!', 'success');
        carregarFotos();
      } else {
        showToast(data.msg || 'Erro.', 'error');
      }
    } catch (err) {
      showToast('Erro de conexão.', 'error');
    }
  });

})();

window.selecionarTipo=function(input){
  $('.dev-tipo-opt').removeClass('dev-tipo-opt--selected');
  $(input).closest('.dev-tipo-opt').addClass('dev-tipo-opt--selected');
};

$(function(){
  $(document).on('change','#sel-motivo',function(){
    var $opt=$(this).find(':selected'),exige=String($opt.data('exige-foto'))==='1',
        $badge=$('#upload-badge'),$input=$('#input-midias');
    $badge.text(exige?'* obrigatório':'opcional')
      .css({color:exige?'#dc2626':'',background:exige?'#fef2f2':''});
    $input.prop('required',exige);
  });

  var $zone=$('#upload-zone'),$input=$('#input-midias'),$previews=$('#dev-previews'),
      $addMore=$('#btn-add-more'),$prompt=$('#upload-prompt'),files=[];

  if($zone.length&&$input.length){
    $zone.on('click',function(e){
      if(!$(e.target).closest('.dev-upload-link').length)$input.trigger('click');
    });

    $addMore.on('click',function(e){
      e.preventDefault();
      $input.trigger('click');
    });

    $zone.on('dragover',function(e){
      e.preventDefault();
      $zone.addClass('drag-over');
    }).on('dragleave',function(){
      $zone.removeClass('drag-over');
    }).on('drop',function(e){
      e.preventDefault();
      $zone.removeClass('drag-over');
      addFiles(Array.prototype.slice.call(e.originalEvent.dataTransfer.files||[]));
    });

    $input.on('change',function(){
      addFiles(Array.prototype.slice.call(this.files||[]));
      this.value='';
    });
  }

  function addFiles(novos){
    $.each(novos,function(_,f){
      if(f.size>10*1024*1024){
        alert(f.name+' é maior que 10MB e foi ignorado.');
        return;
      }
      files.push(f);
    });
    syncInput();
    renderPreviews();
  }

  function removeFile(idx){
    files.splice(idx,1);
    syncInput();
    renderPreviews();
  }

  function syncInput(){
    if(!$input.length||typeof DataTransfer==='undefined')return;
    var dt=new DataTransfer();
    $.each(files,function(_,f){dt.items.add(f);});
    $input[0].files=dt.files;
  }

  function renderPreviews(){
    if(!$previews.length)return;

    if(!files.length){
      $previews.hide().empty();
      $addMore.hide();
      $zone.show().removeClass('has-files');
      $prompt.show();
      return;
    }

    $zone.hide();
    $previews.css('display','grid').empty();
    $addMore.show();

    $.each(files,function(idx,f){
      var url=URL.createObjectURL(f),$item=$('<div>',{class:'dev-preview-item'}),
          $remove=$('<button>',{type:'button',class:'dev-preview-remove',html:'<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>'});

      $remove.on('click',function(e){
        e.stopPropagation();
        removeFile(idx);
      });

      if((f.type||'').indexOf('video/')===0){
        $item.append($('<video>',{src:url,muted:true,preload:'metadata'}))
             .append($('<span>',{class:'dev-preview-video-badge',text:'▶ Vídeo'}));
      }else{
        $item.append($('<img>',{src:url,alt:f.name}));
      }

      $item.append($remove);
      $previews.append($item);
    });
  }

  $(document).on('submit','#form-devolucao',function(e){
    var $err=$('#form-error'),motivo=$('#sel-motivo').val();

    if(!$('.dev-item-check:checked').length){
      e.preventDefault();
      $err.text('Selecione ao menos um item.').show()[0].scrollIntoView({behavior:'smooth',block:'center'});
      return;
    }

    if(!motivo){
      e.preventDefault();
      $err.text('Selecione o motivo da solicitação.').show()[0].scrollIntoView({behavior:'smooth',block:'center'});
    }
  });
});


  
  // Nova.php - scripts para a página de devolução

  // ── Tipo de solicitação ──────────────────────────
  $('.dnv-tipo-card').on('click', function () {
    $('.dnv-tipo-card').removeClass('dnv-tipo-card--active');
    $(this).addClass('dnv-tipo-card--active');
    $(this).find('input[type="radio"]').prop('checked', true);
  });

  // ── Itens — toggle visual + resumo ───────────────
  $(document).on('change', '.dnv-item-input', function () {
    var $row = $(this).closest('.dnv-item');
    if (this.checked) {
      $row.addClass('is-checked');
    } else {
      $row.removeClass('is-checked');
    }
    atualizarResumo();
    $('#err-itens').text('');
  });

  function atualizarResumo() {
    var $selecionados = $('.dnv-item-input:checked');
    var $empty = $('#dnv-resumo-empty');
    var $list  = $('#dnv-resumo-items');

    if ($selecionados.length === 0) {
      $empty.show();
      $list.hide().empty();
      return;
    }

    $empty.hide();
    $list.empty().show();

    $selecionados.each(function () {
      var $item = $(this).closest('.dnv-item');
      var nome  = $item.find('.dnv-item-name').text().trim();
      var img   = $item.find('.dnv-item-thumb img').attr('src') || '';
      var frag  = '<div class="dnv-resumo-item">' +
                    '<img src="' + img + '" alt="">' +
                    '<span>' + $('<span>').text(nome).html() + '</span>' +
                  '</div>';
      $list.append(frag);
    });
  }

  // ── Motivo — marca obrigatório de foto ───────────
  $('#dnv-motivo').on('change', function () {
    var exige = $(this.options[this.selectedIndex]).data('exige-foto') === 1;
    var $badge = $('#dnv-midia-badge');
    if (exige) {
      $badge.text('* obrigatório').css({'color':'#dc2626','background':'#fef2f2'});
      $('#dnv-file-input').prop('required', true);
    } else {
      $badge.text('opcional').css({'color':'','background':''});
      $('#dnv-file-input').prop('required', false);
    }
    $('#err-motivo').text('');
  });

  // ── Contador de caracteres ───────────────────────
  $('#dnv-desc').on('input', function () {
    $('#dnv-desc-count').text(this.value.length);
  });

  // ── Upload — drag & drop + acumulação ────────────
  var _files = []; // array global de File objects

  (function () {
    var $zone  = $('#dnv-drop');
    var $input = $('#dnv-file-input');
    var $prev  = $('#dnv-previews');
    var $more  = $('#dnv-add-more');
    var $idle  = $('#dnv-drop-idle');
    var TIPOS  = ['image/jpeg','image/png','image/webp','video/mp4','video/quicktime'];

    // Abrir seletor
    $('#dnv-drop-btn, #dnv-add-more').on('click', function (e) {
      e.stopPropagation();
      $input.trigger('click');
    });

    // Input change — NÃO limpa o input, apenas lê e renderiza
    $input.on('change', function () {
      adicionar(Array.from(this.files));
    });

    // Drag & drop
    $zone.on('dragover', function (e) {
      e.preventDefault();
      $zone.addClass('drag-over');
    });
    $zone.on('dragleave drop', function (e) {
      e.preventDefault();
      $zone.removeClass('drag-over');
      if (e.type === 'drop') {
        adicionar(Array.from(e.originalEvent.dataTransfer.files));
      }
    });

    function adicionar(novos) {
      novos.forEach(function (f) {
        if (f.size > 10 * 1024 * 1024) {
          mostrarErroGlobal('"' + f.name + '" ultrapassa 10 MB e foi ignorado.');
          return;
        }
        if (TIPOS.indexOf(f.type) === -1) {
          mostrarErroGlobal('"' + f.name + '" tem formato não suportado.');
          return;
        }
        // Evita duplicatas pelo nome + tamanho
        var dup = _files.some(function (x) { return x.name === f.name && x.size === f.size; });
        if (!dup) _files.push(f);
      });
      renderPreviews();
    }

    function remover(idx) {
      URL.revokeObjectURL(_files[idx]._previewUrl);
      _files.splice(idx, 1);
      renderPreviews();
    }

    function renderPreviews() {
      if (_files.length === 0) {
        $zone.show().removeClass('has-files');
        $idle.show();
        $prev.hide().empty();
        $more.hide();
        return;
      }

      $zone.hide();
      $prev.show().empty();
      $more.show();

      _files.forEach(function (f, i) {
        if (!f._previewUrl) f._previewUrl = URL.createObjectURL(f);

        var $div = $('<div class="dnv-preview">');
        if (f.type.startsWith('video/')) {
          $div.append('<video src="' + f._previewUrl + '" muted preload="metadata">');
          $div.append('<span class="dnv-preview-badge">vid</span>');
        } else {
          $div.append('<img src="' + f._previewUrl + '" alt="">');
        }

        var $rm = $('<button type="button" class="dnv-preview-rm" aria-label="Remover">' +
          '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round">' +
          '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>');
        $rm.on('click', function (e) { e.stopPropagation(); remover(i); });
        $div.append($rm);
        $prev.append($div);
      });
    }
  })();

  // ── Erro global ───────────────────────────────────
  function mostrarErroGlobal(msg) {
    return false;
    $('#dnv-global-error-msg').text(msg);
    $('#dnv-global-error').show();
    setTimeout(function () { $('#dnv-global-error').hide(); }, 4000);
  }

  // ── Submit via AJAX + FormData ────────────────────
  $('#dnv-form').on('submit', function (e) {
    e.preventDefault();

    var ok = true;
    if (!$('#dnv-motivo').val()) {
      $('#err-motivo').text('Selecione o motivo da solicitação.'); ok = false;
    }
    if ($('.dnv-item-input:checked').length === 0) {
      $('#err-itens').text('Selecione ao menos um item.'); ok = false;
    }
    if (!ok) {
      var $first = $('.dnv-field-error').filter(function () { return $(this).text().length > 0; }).first();
      if ($first.length) $('html,body').animate({ scrollTop: $first.offset().top - 100 }, 320);
      return;
    }

    // Loading
    var $btn = $('#dnv-submit');
    $btn.prop('disabled', true)
        .find('.dnv-submit-label').hide();
    $btn.find('.dnv-submit-loader').show();
    $('#dnv-global-error').hide();

    // Monta FormData a partir do formulário
    var fd = new FormData(this);

    // Remove qualquer arquivo que possa ter ficado no input nativo
    fd.delete('midias[]');

    // Adiciona os arquivos acumulados no array
    _files.forEach(function (f) {
      fd.append('midias[]', f, f.name);
    });

    $.ajax({
      url         : $(this).attr('action'),
      method      : 'POST',
      data        : fd,
      processData : false,
      contentType : false,
      success: function (r) {
        if (r.ok) {
          window.location.href = r.redirect;
        } else {
          $btn.prop('disabled', false)
              .find('.dnv-submit-label').show();
          $btn.find('.dnv-submit-loader').hide();
          $('#dnv-global-error-msg').text(r.msg || 'Erro ao enviar.');
          $('#dnv-global-error').show();
        }
      },
      error: function () {
        $btn.prop('disabled', false)
            .find('.dnv-submit-label').show();
        $btn.find('.dnv-submit-loader').hide();
        $('#dnv-global-error-msg').text('Erro de conexão. Tente novamente.');
        $('#dnv-global-error').show();
      },
    });
  });



});

})();
