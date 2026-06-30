/**
 * checkout-address.js — v3
 * Sub-passo 1: Seleção de endereço → POST /checkout/address/select
 * Sub-passo 2: Frete inline → POST /checkout/payment/shipping/save → /checkout/payment
 */
;(function ($, window) {
  'use strict';

  Toast.configure({
    position:  'bottom-center',  // padrão do site inteiro
    duration:   5000,
    maxVisible: 1,
  });

  const FRETE_ATUAL = window.CHECKOUT_FRETE_ATUAL || null;
  const SUB_PASSO   = window.CHECKOUT_SUB_PASSO   || 1;
  const END_ID      = window.CHECKOUT_END_ID       || 0;

  // ── Seleciona card visualmente ────────────────────
  $(document).on('click', '.address-card', function (e) {
    if ($(e.target).is('input, button, a')) return;
    $('.address-card').removeClass('is-selected');
    $(this).addClass('is-selected');
    $(this).find('.address-radio').prop('checked', true);
  });

  // ── Botão Confirmar endereço ──────────────────────
  $('#btn-confirm-address').on('click', function () {
    const $card = $('.address-card.is-selected');
    if (!$card.length) { CK.toast('Selecione um endereço.', 'error'); return; }

    const endId = parseInt($card.data('end-id'));
    const cep   = String($card.data('end-cep') || '');
    const $btn  = $(this);
    CK.btnLoading($btn);

    CK.post('/checkout/address/select', { endereco_id: endId })
      .done(function (res) {
        if (!res.ok) {
          CK.btnLoading($btn, false);
          CK.toast(res.msg || 'Erro ao selecionar.', 'error');
          return;
        }
        $('#substep-1').removeClass('is-active').addClass('is-done');
        $('#substep-2').addClass('is-active');
        _confirmarEndereco($card);
        _carregarFrete(endId, cep);
        CK.btnLoading($btn, false);
      })
      .fail(function () {
        CK.btnLoading($btn, false);
        CK.toast('Erro de conexão.', 'error');
      });
  });

  // 'Alterar endereço' é um <a href='/checkout/address/update'> — sem handler JS necessário.

  // Sub-passo 2 já ativo ao entrar (endereço salvo anteriormente)
  if (SUB_PASSO >= 2 && END_ID) {
    const cep = ($('.address-card[data-end-id="' + END_ID + '"]').data('end-cep') || '').toString();
    _carregarFrete(END_ID, cep);
  }

  // ── Carrega frete via API ─────────────────────────
  function _carregarFrete(endId, cep) {
    const $panel = $('#step-frete');

    console.log(cep);
    

    $panel.html(
      '<div class="checkout-section" style="margin-top:0;border-top:2px dashed var(--c-border);border-radius:0 0 var(--radius-lg) var(--radius-lg);">' +
        '<div class="section-head" style="margin-bottom:14px;">' +
          '<h2 style="font-size:16px;">Opções de entrega' +
            ' <span class="frete-cep-tag" style="margin-left:auto;">CEP <strong>' + _fmtCep(cep) + '</strong></span>' +
          '</h2></div>' +
        '<div class="frete-skeleton" id="frete-skeleton"><div class="frete-skel-card"></div><div class="frete-skel-card"></div></div>' +
        '<div class="frete-empty" id="frete-empty" style="display:none;">' +
          '<strong>Frete indisponível para este CEP</strong>' +
          '<span><a href="' + BASE_URL + '/checkout/address">Trocar endereço</a>' +
          ' ou <button type="button" class="btn-link" id="btn-retry-frete">tentar novamente</button></span>' +
        '</div>' +
        '<div class="frete-cards" id="frete-cards"></div>' +
        '<div id="frete-error-msg" class="form-alert" style="display:none;"></div>' +
        '<button type="button" class="btn btn-primary btn-full" id="btn-confirm-frete" disabled style="margin-top:14px;">' +
          'Confirmar entrega e continuar' +
          '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>' +
        '</button>' +
      '</div>'
    );

    $('html, body').animate({ scrollTop: $panel.offset().top - 80 }, 350);

    CK.post('/checkout/payment/shipping', { endereco_id: endId })
      .done(function (res) {
        $('#frete-skeleton').hide();
        if (res.ok && res.opcoes && res.opcoes.length) {
          window.FreteUI.render(res.opcoes, res.cep || cep);
          if (FRETE_ATUAL && FRETE_ATUAL.codigo) {
            const $c = $('.frete-card[data-frete-id="' + FRETE_ATUAL.codigo + '"]');
            if ($c.length) {
              $('.frete-card').removeClass('is-selected');
              $c.addClass('is-selected').find('input[type="radio"]').prop('checked', true);
              $('#btn-confirm-frete').prop('disabled', false);
              if (window.FreteUI) FreteUI._updateSummary(FRETE_ATUAL.valor, FRETE_ATUAL.descricao, FRETE_ATUAL.prazo);
            }
          }
        } else {
          $('#frete-empty').show();
        }
      })
      .fail(function () {
        $('#frete-skeleton').hide();
        $('#frete-empty').show();
      });
  }

  $(document).on('click', '#btn-retry-frete', function () {
    const cep = $('#step-frete').data('cep-inicial') || '';
    _carregarFrete(END_ID, cep);
  });

  // Habilita botão de confirmação ao selecionar frete
  $(document).on('click', '.frete-card', function () {
    setTimeout(function () {
      if ($('.frete-card.is-selected').length) $('#btn-confirm-frete').prop('disabled', false);
    }, 60);
  });

  // Confirma frete → salva → vai para /checkout/payment
  $(document).on('click', '#btn-confirm-frete', function () {
    const sel = window.FreteUI ? window.FreteUI.getSelected() : null;
    if (!sel) { CK.toast('Selecione uma opção de entrega.', 'error'); return; }
    const $btn = $(this);
    CK.btnLoading($btn);
    CK.post('/checkout/payment/shipping/save', {
      frete_codigo:    sel.id,
      frete_descricao: sel.nome,
      frete_valor:     sel.valor,
      frete_prazo:     sel.prazo,
      frete_carrier:   sel.carrier || '',
      frete_poster:    sel.poster  || '',
      frete_tag:       sel.tag     || '',
    })
    .done(function (res) {
      if (res.ok || res.frete_ok) {
        window.location.href = BASE_URL + '/checkout/payment';
      } else {
        CK.btnLoading($btn, false);
        CK.toast(res.msg || 'Erro ao salvar frete.', 'error');
      }
    })
    .fail(function () {
      CK.btnLoading($btn, false);
      CK.toast('Erro de conexão.', 'error');
    });
  });

  // ── Helpers ───────────────────────────────────────
  function _confirmarEndereco($card) {
    const linha1 = $card.find('.address-line').first().text().trim();
    const linha2 = $card.find('.address-line').eq(1).text().trim();
    $('#panel-address .section-head .section-sub').remove();
    $('#panel-address .section-head').append(
      '<div class="section-confirmed">' +
        '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>' +
        _esc(linha1) + ' — ' + _esc(linha2) +
        `<a href="${BASE}/checkout/address/update" class="section-confirmed-edit">Alterar endereço</a>` +
      '</div>'
    );
    $('#address-selector').remove();
  }

  function _fmtCep(cep) {
    const c = cep.replace(/\D/g, '');
    return c.length === 8 ? c.slice(0,5)+'-'+c.slice(5) : cep;
  }

  function _esc(str) {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
  }

  
  // ── Selecionar endereço ao clicar no card ────────────
  $(document).on('click', '.address-card--manage', function (e) {
    if ($(e.target).is('a, button, input')) return;
    const $card  = $(this);
    const $radio = $card.find('.address-select-radio');
    if (!$radio.length) return;

    $('.address-card--manage').removeClass('is-selecting');
    $card.addClass('is-selecting');
    $radio.prop('checked', true);

    // Habilita o botão "Selecionar endereço"
    $('#btn-select-for-cart').prop('disabled', false);
    // Fecha painel de confirmação se estava aberto
    $('#confirm-panel').attr('hidden', true);
  });

  // Clique direto no radio também ativa o card
  $(document).on('change', '.address-select-radio', function () {
    $('.address-card--manage').removeClass('is-selecting');
    $(this).closest('.address-card--manage').addClass('is-selecting');
    $('#btn-select-for-cart').prop('disabled', false);
    $('#confirm-panel').attr('hidden', true);
  });

  // ── "Selecionar endereço" ────────────────────────────
  $('#btn-select-for-cart').on('click', function () {
    const $radio = $('.address-select-radio:checked');
    if (!$radio.length) return;

    const isPrincipal = $radio.data('principal') === 1 || $radio.data('principal') === '1';
    const nome = $radio.closest('.address-card--manage')
                       .find('.address-card-header strong').text().trim();

    // Atualiza título do painel
    $('#confirm-panel-title').text(
      isPrincipal
        ? 'Usar "' + nome + '" como endereço de entrega?'
        : 'O que deseja fazer com o endereço selecionado?'
    );

    if (isPrincipal) {
      // Já é principal: confirma direto sem perguntar sobre tornar principal
      $('#btn-confirm-and-principal').hide();
    } else {
      $('#btn-confirm-and-principal').show();
    }

    $('#confirm-panel').removeAttr('hidden');

    // Scroll suave até o painel
    $('html, body').animate({
      scrollTop: $('#confirm-panel').offset().top - 80
    }, 250);
  });

  // ── "Usar apenas nesta compra" ───────────────────────
  $('#btn-confirm-only-cart').on('click', function () {
    const hash = $('.address-select-radio:checked').data('hash');
    if (!hash) return;
    CK.btnLoading($(this));
    selecionarParaCarrinho(hash, false);
  });

  // ── "Tornar endereço principal" ──────────────────────
  $('#btn-confirm-and-principal').on('click', function () {
    const $radio = $('.address-select-radio:checked');
    const hash   = $radio.data('hash');
    if (!hash) return;
    CK.btnLoading($(this));
    selecionarParaCarrinho(hash, true);
  });

  // ── Cancelar ─────────────────────────────────────────
  $('#btn-confirm-cancel').on('click', function () {
    $('#confirm-panel').attr('hidden', true);
  });

  // ── "Principal" button → toast de confirmação ────────
  $(document).on('click', '.address-action--principal', function (e) {
    e.stopPropagation();
    const $btn  = $(this);
    const hash  = $btn.data('hash');
    const nome  = $btn.closest('.address-card--manage')
                       .find('.address-card-header strong').text().trim();

    Toast.configure({
      type:  'success',  
    });  

    
    Toast.action('Selecionar "' + nome + '" para este carrinho também?.', [
      { 
        label: 'Sim, selecionar', action: function () {
          CK.btnLoading($btn);
          selecionarParaCarrinho(hash, true);
        }
      },
      { 
        label: 'Não, só definir', primary: true,
        action: function () {
          tornarPrincipalApenas(hash, $btn);
        } 
      },
    ]);


  });

  // ══════════════════════════════════════════════════════
  // HELPERS
  // ══════════════════════════════════════════════════════

  function selecionarParaCarrinho(hash, tornarPrincipal) {
    const payload = { hash: hash };
    if (tornarPrincipal) payload.tornar_principal = 1;
    const id_ldg = Toast.loading('Selecionando endereço...');
    CK.post('/checkout/address/select-by-hash', payload)
      .done(function (res) {
        if (res.ok) {          
          Toast.update(id_ldg, {
            type:    'success',
            message: 'Endereço selecionado para entrega.',
            duration: 4000,
          });
          setTimeout(function () {
            window.location.href = BASE_URL + '/checkout/address';
          }, 600);
        } else {          
          Toast.update(id_ldg, {
            type:    'error',
            message: 'Erro ao selecionar.',
            duration: 4000,
          });

          CK.btnLoading($('#btn-confirm-only-cart'), false);
          CK.btnLoading($('#btn-confirm-and-principal'), false);
        }
      })
      .fail(function () {        
        Toast.update(id_ldg, {
          type:    'error',
          message: 'Erro de conexão.',
          duration: 4000,
        });
        CK.btnLoading($('#btn-confirm-only-cart'), false);
        CK.btnLoading($('#btn-confirm-and-principal'), false);
      });
  }

  function tornarPrincipalApenas(hash, $btn) {
    const id_ldg = Toast.loading('Selecionando endereço...');

    CK.post('/checkout/address/set-principal', { hash: hash })
      .done(function (res) {
        if (res.ok) {
          atualizarUIPrincipal($btn.closest('.address-card--manage'));          
          Toast.update(id_ldg, { type: 'success', message: 'Endereço principal atualizado.', duration: 4000});
        } else {          
          Toast.update(id_ldg, { type: 'error', message: res.msg || 'Erro.', duration: 4000});
        }
        CK.btnLoading($btn, false);
      })
      .fail(function () {
        CK.btnLoading($btn, false);        
        Toast.update(id_ldg, { type: 'suerrorccess', message: 'Erro de conexão...', duration: 4000});
      });
  }

  function atualizarUIPrincipal($novoCard) {
    // Remove botão Principal e badge de todos
    $('.address-card--manage').not($novoCard).each(function () {
      const $c = $(this);
      $c.removeClass('is-principal');
      $c.find('.address-badge--principal').remove();

      if ($c.find('.address-badge').length) {
        $c.find('.address-badge').remove();
      }

      if (!$c.find('.address-action--principal').length) {
        const cardHash = $c.data('hash');
        const $newBtn = $(
          '<button type="button" class="address-action address-action--principal" title="Tornar principal">' +
          '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
          'stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">' +
          '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg> Principal</button>'
        ).attr('data-hash', cardHash);
        $c.find('.address-card-actions').prepend($newBtn);
      } else {
        $c.find('.address-action--principal').show().removeClass('is-loading');
      }
    });

    // Marca novo principal
    $novoCard.addClass('is-principal is-becoming-principal');
    $novoCard.find('.address-badge--principal').remove();
    $novoCard.find('.address-card-header')
             .append('<span class="address-badge address-badge--principal">Principal</span>');
    $novoCard.find('.address-action--principal').hide();
    setTimeout(() => $novoCard.removeClass('is-becoming-principal'), 600);
  }

  // Toast com botões de ação
  function showActionToast(msg, actions) {
    let $stack = $('#ck-toast-stack');
    if (!$stack.length) {
      $stack = $('<div id="ck-toast-stack" class="ck-toast-stack"></div>');
      $('body').append($stack);
    }

    // Remove action toasts existentes
    $stack.find('.ck-toast--action').remove();

    const $toast = $('<div class="ck-toast ck-toast--action"></div>');
    $toast.append($('<p class="ck-toast-msg"></p>').text(msg));

    const $btns = $('<div class="ck-toast-actions"></div>');
    actions.forEach(function (a) {
      const $b = $('<button type="button" class="ck-toast-btn"></button>')
                   .text(a.label)
                   .toggleClass('ck-toast-btn--primary', !!a.primary)
                   .on('click', function () {
                     $toast.removeClass('is-visible');
                     setTimeout(() => $toast.remove(), 250);
                     a.action();
                   });
      $btns.append($b);
    });
    $toast.append($btns);
    $stack.append($toast);
    setTimeout(() => $toast.addClass('is-visible'), 20);
    // Auto-dismiss após 8s
    setTimeout(() => {
      $toast.removeClass('is-visible');
      setTimeout(() => $toast.remove(), 250);
    }, 8000);
  }

  // ── Highlight do card quando radio selecionado ───────
  // Marca o endereço já selecionado no checkout (se houver)
  const currentEndId = window.CHECKOUT_ENDERECO_ID || 0;
  if (currentEndId) {
    const $currentRadio = $(`.address-select-radio[value="${currentEndId}"]`);
    if ($currentRadio.length) {
      $currentRadio.prop('checked', true)
                   .closest('.address-card--manage').addClass('is-selecting');
      $('#btn-select-for-cart').prop('disabled', false);
    }
  }

}(jQuery, window));

/**
 * checkout-address.js — v2 (routing-based)
 *
 * Comportamento:
 *   - Selecionar card de endereço → AJAX salva no estado → atualiza UI
 *   - Clicar "Continuar para pagamento" → vai para /checkout/payment
 *   - Para criar/editar endereço, usa páginas dedicadas:
 *       /checkout/address/add
 *       /checkout/address/update
 *       /checkout/address/update/{hash}
 *
 * Este arquivo NÃO faz toggle de formulário inline — não há mais.
 */
;(function () {
  'use strict';

  // ════════════════════════════════════════════════════
  // SELEÇÃO DE CARD
  // ════════════════════════════════════════════════════
  $(document).on('click', '.address-card', function (e) {
    if ($(e.target).is('input, button, a')) return;
    const $card = $(this);
    if ($card.hasClass('is-selected')) return;

    // Visual
    $('.address-card').removeClass('is-selected');
    $card.addClass('is-selected');
    $card.find('.address-radio').prop('checked', true);

    // Persiste no estado do checkout
    const enderecoId = $card.data('end-id');
    CK.post('/checkout/address/select', { endereco_id: enderecoId })
      .done(function (res) {
        if (!res.ok) {
          CK.toast(res.msg || 'Erro ao selecionar endereço.', 'error');
        }
      })
      .fail(function () {
        CK.toast('Erro de conexão.', 'error');
      });
  });

  // ════════════════════════════════════════════════════
  // CONTINUAR PARA PAGAMENTO
  // ════════════════════════════════════════════════════
  $('#btn-continue-address').on('click', function () {
    const $selected = $('.address-card.is-selected');
    if (!$selected.length) {
      CK.toast('Selecione um endereço para continuar.', 'error');
      return;
    }

    CK.btnLoading($(this));
    // Garante que o estado foi salvo antes de navegar
    CK.post('/checkout/address/select', { endereco_id: $selected.data('end-id') })
      .done(function (res) {
        if (res.ok && res.next) {
          window.location.href = res.next;
        } else {
          CK.toast(res.msg || 'Erro ao continuar.', 'error');
          CK.btnLoading($('#btn-continue-address'), false);
        }
      });
  });

}(jQuery, window));

/**
 * checkout-address.js
 *
 * Endereço de entrega — interações premium:
 *   - Auto-busca de CEP via /checkout/fetchCep
 *   - Auto-preenchimento de campos
 *   - Feedback visual "Endereço encontrado"
 *   - Validação inline + estado is-valid
 *   - Seleção de cards de endereço salvo (visual + radio sync)
 *
 * Incluir após o checkout-identify.js.
 */
(function ($) {
  'use strict';
  const BASE = BASE_URL || '';

  // ════════════════════════════════════════════════════
  // MÁSCARA + AUTOBUSCA DE CEP
  // ════════════════════════════════════════════════════
  function maskCep($input) {
    let val = $input.val().replace(/\D/g, '').slice(0, 8);
    if (val.length > 5) val = val.slice(0, 5) + '-' + val.slice(5);
    $input.val(val);
    return val.replace(/\D/g, '');
  }

  $(document).on('input', '#end-cep', function () {
    const clean = maskCep($(this));
    // Reseta indicadores se o usuário está digitando
    $('#cep-success').hide();
    $('#cep-found').hide();
    $('#err-end-cep').empty();

    if (clean.length === 8) {
      buscarCep(clean);
    }
  });

  function buscarCep(cep) {
    $('#cep-loading').show();
    $('#cep-success').hide();
    $('#err-end-cep').empty();

    $.get(BASE + '/checkout/cep', { cep }, function (res) {
      $('#cep-loading').hide();

      if (!res.ok) {
        $('#err-end-cep').text(res.msg || 'CEP não encontrado.');
        return;
      }

      // Preenche e marca como válido
      preencherCampo('#end-logradouro', res.logradouro);
      preencherCampo('#end-bairro',     res.bairro);
      preencherCampo('#end-cidade',     res.cidade);
      preencherCampo('#end-estado',     res.estado);

      $('#cep-success').show();

      const resumo = [res.logradouro, res.bairro, res.cidade, res.estado]
                       .filter(Boolean).join(', ');
      $('#cep-found-summary').text(resumo);
      $('#cep-found').show();

      // Foca no próximo campo lógico (número)
      setTimeout(() => {
        if (!$('#end-numero').val()) $('#end-numero').focus();
      }, 250);
    }, 'json').fail(function () {
      $('#cep-loading').hide();
      $('#err-end-cep').text('Erro de conexão. Tente novamente.');
    });
  }

  function preencherCampo(selector, valor) {
    const $field = $(selector);
    if (!valor) return;
    $field.val(valor);
    if ($field.val()) $field.addClass('is-valid');
  }

  // ════════════════════════════════════════════════════
  // VALIDAÇÃO INLINE — adiciona is-valid quando preenche
  // ════════════════════════════════════════════════════
  $(document).on('blur', '.address-form .form-control[required]', function () {
    const $this = $(this);
    if ($this.val() && $this.val().toString().trim().length > 0) {
      $this.addClass('is-valid').removeClass('is-error');
    } else {
      $this.removeClass('is-valid');
    }
  });

  // ════════════════════════════════════════════════════
  // SELEÇÃO DE CARD DE ENDEREÇO SALVO
  // ════════════════════════════════════════════════════
  $(document).on('click', '.address-card', function (e) {
    // Não interfere se clicar dentro de um input/botão
    if ($(e.target).is('input, button, a')) return;

    const $card = $(this);
    const $radio = $card.find('.address-radio');
    if (!$radio.length) return;

    $('.address-card').removeClass('is-selected');
    $card.addClass('is-selected');
    $radio.prop('checked', true);
  });

  // Marca o selecionado inicial visualmente
  $(function () {
    $('.address-radio:checked').closest('.address-card').addClass('is-selected');
  });

  // ════════════════════════════════════════════════════
  // TOGGLE "ADICIONAR NOVO ENDEREÇO"
  // ════════════════════════════════════════════════════
  $('#btn-toggle-new-address').on('click', function () {
    const $form = $('#address-form');
    const $btn  = $(this);

    if ($form.is(':visible')) {
      $form.slideUp(180);
      $btn.html(
        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> Adicionar novo endereço'
      );
    } else {
      $form.slideDown(220);
      $btn.html(
        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Cancelar'
      );
      // Limpa campos
      $form.find('.form-control').val('').removeClass('is-valid is-error');
      $('#cep-success, #cep-found').hide();
      setTimeout(() => $('#end-cep').focus(), 220);
    }
  });

  // ════════════════════════════════════════════════════
  // SUBMIT DO ENDEREÇO
  // ════════════════════════════════════════════════════
  $('#address-form').on('submit', function (e) {
    e.preventDefault();
    const $form = $(this);
    const $btn  = $('#btn-save-address');

    // Validação client-side mínima
    let hasError = false;
    $form.find('.form-control[required]').each(function () {
      const $field = $(this);
      const val = $field.val().toString().trim();
      if (!val) {
        $field.addClass('is-error').removeClass('is-valid');
        hasError = true;
      }
    });

    if (hasError) {
      $('#address-error').text('Preencha os campos obrigatórios.').show();
      return;
    }

    $('#address-error').hide();
    $btn.addClass('is-loading').prop('disabled', true);

    $.post(BASE + '/checkout/endereco', $form.serialize(), function (res) {
      $btn.removeClass('is-loading').prop('disabled', false);

      if (res.ok) {
        // Atualiza o hidden de endereco_entrega_id usado pelo form de pagamento
        $('#endereco_entrega_id').val(res.endereco_id);
        $('#endereco_cobranca_id').val(res.endereco_id);

        // Avança para o bloco de frete
        $('#frete-section').slideDown(220);
        $form.slideUp(180);

        // Scroll suave para o frete
        $('html, body').animate({
          scrollTop: $('#frete-section').offset().top - 80,
        }, 300);
      } else if (res.errors) {
        $('#address-error').text(res.errors.join(' ')).show();
      } else {
        $('#address-error').text(res.msg || 'Erro ao salvar endereço.').show();
      }
    }, 'json').fail(function () {
      $btn.removeClass('is-loading').prop('disabled', false);
      $('#address-error').text('Erro de conexão.').show();
    });
  });

  // ── Busca de CEP (auto) ─────────────────────────────
  let cepTimer;
  $('#end-cep').on('input', function () {
    const $this = $(this);
    const clean = $this.val().replace(/\D/g, '');
 
    $('#cep-success, #cep-found').hide();
    $('#err-end-cep').empty();
    clearTimeout(cepTimer);
 
    if (clean.length !== 8) return;
 
    $('#cep-loading').show();
    cepTimer = setTimeout(function () {
      CK.get('/checkout/cep', { cep: clean })
        .done(function (res) {
          $('#cep-loading').hide();
          if (!res.ok) {
            $('#err-end-cep').text(res.msg || 'CEP não encontrado.');
            return;
          }
          $('#end-logradouro').val(res.logradouro || '').addClass(res.logradouro ? 'is-valid' : '');
          $('#end-bairro').val(res.bairro || '').addClass(res.bairro ? 'is-valid' : '');
          $('#end-cidade').val(res.cidade || '').addClass(res.cidade ? 'is-valid' : '');
          $('#end-estado').val(res.estado || '').addClass(res.estado ? 'is-valid' : '');
 
          $('#cep-success').show();
          $('#cep-found-summary').text(
            [res.logradouro, res.bairro, res.cidade, res.estado].filter(Boolean).join(', ')
          );
          $('#cep-found').show();
          setTimeout(() => { if (!$('#end-numero').val()) $('#end-numero').focus(); }, 250);
        })
        .fail(function () {
          $('#cep-loading').hide();
          $('#err-end-cep').text('Erro de conexão.');
        });
    }, 400);
  });
 
  // ── Toggle principal ────────────────────────────────
  $('#chk-principal').on('change', function () {
    $('#principal-flag').val($(this).is(':checked') ? 1 : 0);
    $(this).closest('.address-principal-toggle').toggleClass('is-checked', $(this).is(':checked'));
  });
 
  // ── Submit ──────────────────────────────────────────
  $('#form-address-add').on('submit', function (e) {
    e.preventDefault();
    const $form = $(this);
    const $btn  = $('#btn-save-address');
    const $err  = $('#address-error');
    CK.formAlertClear($err);
 
    // Validação client-side
    let erros = [];
    $form.find('.form-control[required]').each(function () {
      if (!$(this).val().toString().trim()) {
        $(this).addClass('is-error');
        erros.push(1);
      } else {
        $(this).removeClass('is-error');
      }
    });
    if (erros.length) {
      CK.formAlertSet($err, 'Preencha os campos obrigatórios.');
      return;
    }
 
    CK.btnLoading($btn);
 
    const data = $form.serializeArray().reduce((a,x) => (a[x.name]=x.value, a), {});
 
    CK.post('/checkout/address/add', data)
      .done(function (res) {
        if (res.ok && res.redirect) {
          window.location.href = res.redirect;
        } else {
          CK.btnLoading($btn, false);
          const msg = res.errors ? res.errors.join(' ') : (res.msg || 'Erro ao salvar.');
          CK.formAlertSet($err, msg);
        }
      })
      .fail(function () {
        CK.btnLoading($btn, false);
        CK.formAlertSet($err, 'Erro de conexão.');
      });
  });

  $(document).on('click', '.address-action--delete', function () {
    const $btn = $(this);
    const hash = $btn.data('hash');
    // if (!confirm('Remover este endereço? Esta ação não pode ser desfeita.')) return;

    Toast.action('Remover este endereço? Esta ação não pode ser desfeita.', [
      { label: 'Remover', action: () => {
        CK.post('/checkout/address/delete', { hash })
        .done(function (res) {
          if (res.ok) {
            $btn.closest('.address-card').slideUp(180, function () { $(this).remove(); });
            CK.toast('Endereço removido.', 'success');
          } else {
            CK.toast(res.msg || 'Erro ao remover.', 'error');
          }
        });
      } },
      { label: 'Cancelar', primary: true },
    ]);
  
    
  });

  // Auto-format CEP on load
  CK.maskCep($('#end-cep'));

  let cepTimer_v2;
  $('#end-cep').on('input', function () {
    const clean = CK.maskCep($(this));
    $('#err-end-cep').empty();
    clearTimeout(cepTimer_v2);
    if (clean.length !== 8) return;
    $('#cep-loading').show(); $('#cep-success').hide();
    cepTimer_v2 = setTimeout(() => {
      CK.get('/checkout/cep', { cep: clean })
        .done(res => {
          $('#cep-loading').hide();
          if (!res.ok) { $('#err-end-cep').text(res.msg || 'CEP não encontrado.'); return; }
          if (res.logradouro) $('#end-logradouro').val(res.logradouro).addClass('is-valid');
          if (res.bairro)     $('#end-bairro').val(res.bairro).addClass('is-valid');
          if (res.cidade)     $('#end-cidade').val(res.cidade).addClass('is-valid');
          if (res.estado)     $('#end-estado').val(res.estado).addClass('is-valid');
          $('#cep-success').show();
        })
        .fail(() => { $('#cep-loading').hide(); });
    }, 400);
  });

  $('#form-address-edit').on('submit', function (e) {
    e.preventDefault();
    const $btn = $('#btn-save-address'), $err = $('#address-error');
    CK.formAlertClear($err);
    let hasError = false;
    $(this).find('.form-control[required]').each(function () {
      const ok = $(this).val().toString().trim().length > 0;
      $(this).toggleClass('is-error', !ok);
      if (!ok) hasError = true;
    });
    if (hasError) { CK.formAlertSet($err, 'Preencha os campos obrigatórios.'); return; }
    CK.btnLoading($btn);
    const data = $(this).serializeArray().reduce((a, x) => (a[x.name] = x.value, a), {});
    CK.post('/checkout/address/update/<?= View::e($hash) ?>', data)
      .done(res => {
        if (res.ok && res.redirect) { window.location.href = res.redirect; return; }
        CK.btnLoading($btn, false);
        CK.formAlertSet($err, res.errors ? res.errors.join(' ') : (res.msg || 'Erro ao salvar.'));
      })
      .fail(() => { CK.btnLoading($btn, false); CK.formAlertSet($err, 'Erro de conexão.'); });
  });

}(jQuery));