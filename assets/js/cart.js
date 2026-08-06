// assets/js/cart.js
// Interações do carrinho: CRUD, cupom, frete, compartilhamento.

$(function () {
    

  // ── Adicionar ao carrinho (qualquer página) ──────────────
  // Lida com .btn-add-to-cart nos cards de produto e no catálogo
  // $(document).on('click', '.btn-add-to-cart', function (e) {
  //   // Se for na página de produto, a lógica está em product.js
  //   if ($('.product-page').length) return;

  //   e.preventDefault();
  //   const $btn      = $(this);
  //   const productId = $btn.data('product-id');

  //   $btn.prop('disabled', true);
  //   const origHtml = $btn.html();
  //   $btn.html('<span style="opacity:.6">Adicionando...</span>');

  //   $.post(BASE_URL + '/carrinho/adicionar', {
  //     produto_id:  productId,
  //     quantidade:  1,
  //     _csrf_token: CSRF_TOKEN,
  //   }, function (res) {
  //     if (res.ok) {
  //       updateCartCount(res.cart_count);
  //       showToast('Produto adicionado ao carrinho!', 'success');
  //     } else {
  //       showToast(res.msg || 'Não foi possível adicionar.', 'error');
  //     }
  //     $btn.prop('disabled', false).html(origHtml);
  //   }, 'json').fail(function () {
  //     showToast('Erro de conexão.', 'error');
  //     $btn.prop('disabled', false).html(origHtml);
  //   });
  // });

  // ── Operações da PÁGINA do carrinho ──────────────────────
  if (!$('.cart-page').length) return;

  // ── Remover item ─────────────────────────────────────────
  $(document).on('click', '.cart-item-remove-btn, .cart-item-remove', function () {
    const itemId = $(this).data('item-id');
    const $item  = $(`#cart-item-${itemId}`);

    $item.addClass('removing');

    $.post(BASE_URL + '/carrinho/remover', {
      item_id:     itemId,
      _csrf_token: CART_CSRF,
    }, function (res) {
      if (res.ok) {
        $item.slideUp(300, function () { $(this).remove(); });
        updateCartCount(res.cart_count);
        updateSummary(res);

        if (res.cart_count === 0) {
          setTimeout(() => location.reload(), 350);
        }
      } else {
        $item.removeClass('removing');
        showToast(res.msg || 'Não foi possível remover.', 'error');
      }
    }, 'json');
  });

  // ── Atualizar quantidade ──────────────────────────────────
  let qtyTimer;

  // $(document).on('click', '.cart-qty-minus, .cart-qty-plus', function () {
  //   const $btn  = $(this);
  //   const $row  = $btn.closest('.cart-item');
  //   const itemId = $row.data('item-id');
  //   const $input = $row.find('.cart-qty-input').first();
  //   const max    = parseInt($input.attr('max')) || 999;
  //   let   val    = parseInt($input.val()) || 1;

  //   if ($btn.hasClass('cart-qty-minus')) val = Math.max(1, val - 1);
  //   else                                  val = Math.min(max, val + 1);

  //   $input.val(val);
  //   triggerQtyUpdate(itemId, val);
  // });

  // $(document).on('change', '.cart-qty-input', function () {
  //   const $input = $(this);
  //   const itemId = $input.data('item-id') || $input.closest('.cart-item').data('item-id');
  //   const max    = parseInt($input.attr('max')) || 999;
  //   let val      = parseInt($input.val()) || 1;
  //   val = Math.max(1, Math.min(max, val));
  //   $input.val(val);
  //   triggerQtyUpdate(itemId, val);
  // });

  // function triggerQtyUpdate(itemId, qty) {
  //   clearTimeout(qtyTimer);
  //   qtyTimer = setTimeout(function () {
  //     $.post(BASE_URL + '/carrinho/atualizar', {
  //       item_id:    itemId,
  //       quantidade: qty,
  //       _csrf_token: CART_CSRF,
  //     }, function (res) {
  //       if (res.ok) {
  //         updateCartCount(res.cart_count);
  //         updateSummary(res);

  //         // Atualiza subtotal do item
  //         if (res.item_subtotal_fmt) {
  //           $(`#item-sub-${itemId}`).text(res.item_subtotal_fmt);
  //         }
  //       } else {
  //         showToast(res.msg, 'warning');
  //       }
  //     }, 'json');
  //   }, 500);
  // }

  // ── Vendedor na página do carrinho ───────────────────────
  $(document).on('click', '#cart-vend-apply', function () {
      const codigo = $('#cart-vend-input').val().trim().toUpperCase();
      const $fb    = $('#cart-vend-fb');
      const $btn   = $(this);

      $fb.text('').removeClass('fb-ok fb-erro');

      if (!codigo) {
          $fb.text('Informe o código do vendedor.').addClass('fb-erro');
          return;
      }

      $btn.prop('disabled', true).text('...');

      $.post(BASE_URL + '/carrinho/vendedor', {
          codigo      : codigo,
          _csrf_token : CSRF_TOKEN,
      }, function (res) {
          $btn.prop('disabled', false).text('Aplicar');

          if (!res.ok) {
              $fb.text(res.msg).addClass('fb-erro');
              return;
          }

          // Recarrega a página para refletir o vendedor aplicado
          window.location.reload();

      }, 'json').fail(function () {
          $btn.prop('disabled', false).text('Aplicar');
          $fb.text('Erro de conexão.').addClass('fb-erro');
      });
  });

  // Botão editar vendedor — mostra o form
  $(document).on('click', '#btn-editar-vendedor', function () {
      $(this).closest('.cart-vend-info').hide();
      $('#cart-vend-form').slideDown(180);
      $('#cart-vend-input').focus();
  });

  // Enter no input
  $(document).on('keydown', '#cart-vend-input', function (e) {
      if (e.key === 'Enter') {
          e.preventDefault();
          $('#cart-vend-apply').trigger('click');
      }
  });

  // ── Cupom ─────────────────────────────────────────────────
  $('#btn-apply-coupon').on('click', function () {
    const code   = $('#coupon-input').val().trim().toUpperCase();
    const $msg   = $('#coupon-msg');
    const $btn   = $(this);

    if (!code) { $msg.text('Informe o código do cupom.').addClass('msg-error'); return; }

    $btn.prop('disabled', true).text('Aplicando...');

    $.post(BASE_URL + '/carrinho/cupom', {
      cupom:       code,
      _csrf_token: CART_CSRF,
    }, function (res) {
      $msg.text(res.msg).removeClass('msg-error msg-ok').addClass(res.ok ? 'msg-ok' : 'msg-error');
      if (res.ok) {
        updateSummary(res);
        // Recarrega seção de cupom para mostrar estado "aplicado"
        

        setTimeout(() => location.reload(), 1000);
      }
      $btn.prop('disabled', false).text('Aplicar');
    }, 'json');
  });

  $('#coupon-input').on('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); $('#btn-apply-coupon').trigger('click'); }
  });

  $('#btn-remove-coupon').on('click', function () {
    $.post(BASE_URL + '/carrinho/cupom/remover', { _csrf_token: CART_CSRF }, function (res) {
      if (res.ok) {
        updateSummary(res);
        location.reload();
      }
    }, 'json');
  });

  // ── Accordions (frete / vendedor) ─────────────────────────
  $('#toggle-shipping').on('click', function () {
    toggleAccordion('#shipping-body', $(this).find('.accordion-chevron'));
  });
  $('#toggle-vendor').on('click', function () {
    toggleAccordion('#vendor-body', $(this).find('.accordion-chevron'));
  });

  function toggleAccordion(bodySelector, $chevron) {
    const $body = $(bodySelector);
    $body.is(':visible') ? $body.slideUp(200) : $body.slideDown(200);
    $chevron.css('transform', $body.is(':visible') ? 'rotate(0deg)' : 'rotate(180deg)');
  }

  // ── Cálculo de frete no carrinho ──────────────────────────
  $('#btn-cart-shipping').on('click', function () {
    // Pré-preenche com o CEP ativo se estiver vazio
    if (!$('#cart-cep').val() && window.EC_CEP_ATIVO) {
      const cep = window.EC_CEP_ATIVO;
      $('#cart-cep').val(cep.substring(0, 5) + '-' + cep.substring(5));
    }
    const cep = $('#cart-cep').val().replace(/\D/g, '');
    if (cep.length !== 8) { showToast('Informe um CEP válido.', 'warning'); return; }

    const $results = $('#cart-shipping-results');
    const $btn     = $(this);

    $btn.prop('disabled', true).text('Calculando...');
    $results.html('<p style="font-size:13px;color:#888;padding:8px 0">Buscando opções...</p>').show();

    $.post(BASE_URL + '/carrinho/frete', {
      cep:         cep,
      _csrf_token: CART_CSRF,
    }, function (res) {
      if (!res.ok || !res.opcoes?.length) {
        $results.html('<p style="font-size:13px;color:#e63946">Frete não disponível para este CEP.</p>');
        $btn.prop('disabled', false).text('Calcular');
        return;
      }

      let html = `<p style="font-size:12px;color:#888;margin-bottom:10px">
                    Entrega para: <strong>${res.localidade} - ${res.uf}</strong></p>
                  <ul class="shipping-options">`;

      res.opcoes.forEach(function (opt) {
        const priceHtml = parseFloat(opt.valor) === 0
          ? '<strong class="shipping-free">Grátis</strong>'
          : `<strong>R$ ${parseFloat(opt.valor).toFixed(2).replace('.', ',')}</strong>`;

        html += `
          <li class="shipping-option">
            <label class="shipping-option-label">
              <input type="radio" name="frete_cart" class="frete-opcao"
                     value="${opt.servico}"
                     data-valor="${opt.valor}"
                     data-prazo="${opt.prazo}"
                     data-cep="${cep}"
                     data-nome="${opt.nome}">
              <div class="shipping-option-info">
                <span class="shipping-name">${opt.nome}</span>
                <span class="shipping-prazo">Prazo: ${opt.prazo} dia(s) útil(eis)</span>
              </div>
              ${priceHtml}
            </label>
          </li>`;
      });

      html += '</ul>';
      $results.html(html);
      $btn.prop('disabled', false).text('Calcular');

      // Seleciona frete ao clicar
      $(document).on('change', '.frete-opcao', function () {
        const $opt = $(this);
        $.post(BASE_URL + '/carrinho/frete/selecionar', {
          servico:     $opt.val(),
          valor:       $opt.data('valor'),
          prazo:       $opt.data('prazo'),
          cep:         $opt.data('cep'),
          _csrf_token: CART_CSRF,
        }, function (res) {
          if (res.ok) {
            updateSummary(res);
            // Atualiza badge do frete selecionado
            $('.shipping-selected-badge').text($opt.data('nome'));
          }
        }, 'json');
      });
    }, 'json').fail(function () {
      $results.html('<p style="font-size:13px;color:#e63946">Erro ao calcular frete.</p>');
      $btn.prop('disabled', false).text('Calcular');
    });
  });

  

  // ── Compartilhar carrinho ─────────────────────────────────
  $('#btn-share-cart').on('click', function () {
    $.post(BASE_URL + '/carrinho/compartilhar', { _csrf_token: CART_CSRF }, function (res) {
      if (res.ok) {
        const $box = $('#share-cart-url');
        $('#share-url-input').val(res.url);
        $box.show();
      }
    }, 'json');
  });

  $('#btn-copy-cart-url').on('click', function () {
    const url = $('#share-url-input').val();
    if (navigator.clipboard) {
      navigator.clipboard.writeText(url).then(function () {
        showToast('Link copiado!', 'success');
      });
    }
  });

  // ── Atualiza resumo (totais) ──────────────────────────────
  function updateSummary(res) {
    

    if (res.subtotal_fmt) $('#summary-subtotal').text(res.subtotal_fmt);
    if (res.frete_fmt)    $('#summary-frete').text(res.frete_fmt);
    if (res.total_fmt)    $('#summary-total').text(res.total_fmt);

    const $rowDesc = $('#row-desconto');
    if (res.desconto_fmt) {
      if ($rowDesc.length) {
        $rowDesc.show();
        $('#summary-desconto').text(res.desconto_fmt);
      } else {
        const rowHtml = `<div class="cart-total-row cart-total-row--discount" id="row-desconto">
          <span>Desconto</span>
          <span id="summary-desconto">${res.desconto_fmt}</span>
        </div>`;
        $('.cart-total-divider').before(rowHtml);
      }
    } else {
      $rowDesc.hide();
    }

    
  }

  
  // ── Carrinho com seleção ──────────────────────────────────
  (function () {

    if (!$('.cart-item').length) return; // só na página do carrinho

    let freteValor     = null;
    let freteServico   = null;
    let descontoValor  = 0;    
    let ultimoFreteIds = '';

    // ── Lê todos os itens do DOM ──────────────────────────
    function lerItens() {
      const itens = [];
      $('.cart-item').each(function () {
          const $item    = $(this);
          const id       = parseInt($item.data('id'));
          const preco    = parseFloat($item.data('preco'))      || 0;
          const qtd      = parseInt($item.find('.cart-qty-input').val()) || 1;

          // Sempre recalcula pelo valor do input atual
          const subtotal = preco * qtd;

          // Sincroniza o data com o valor atual
          $item.data('quantidade', qtd).data('subtotal', subtotal);

          const checked = $item.find('.cart-item-checkbox').is(':checked');
          itens.push({ id, preco, qtd, subtotal, checked, $item });
      });
      return itens;
  }

    // ── Itens selecionados ────────────────────────────────
    function itensSelecionados() {
      return lerItens().filter(i => i.checked);
    }

    // ── Atualiza o resumo ─────────────────────────────────
    function atualizarResumo() {
      calcularFrete();
      CartPromoPreview.atualizar();
      
      const selecionados  = itensSelecionados();
      const linhas        = selecionados.length;                              // nº de produtos distintos
      const totalQtd      = selecionados.reduce((acc, i) => acc + i.qtd, 0); // soma das quantidades
      const subtotal      = selecionados.reduce((acc, i) => acc + i.subtotal, 0);
      const total         = subtotal - descontoValor + (freteValor || 0);

      // Badge: soma das quantidades
      $('#summary-itens-count').text(totalQtd);

      // Texto do select-all: nº de linhas (produtos distintos)
      $('#cart-selected-count').text(
          '(' + linhas + ' ' + (linhas === 1 ? 'produto' : 'produtos') + ', '
          + totalQtd + ' ' + (totalQtd === 1 ? 'unidade' : 'unidades') + ')'
      );

      // Valores
      $('#summary-subtotal').text(formatarPreco(subtotal));

      if (descontoValor > 0) {
          $('#summary-desconto').text('− ' + formatarPreco(descontoValor));
          $('#summary-desconto-row').show();
      } else {
          $('#summary-desconto-row').hide();
      }

      if (freteValor !== null) {
          $('#summary-frete').html(
              freteValor === 0
              ? '<span class="text-success">Grátis</span>'
              : formatarPreco(freteValor)
          );
      }

      $('#summary-total').text(formatarPreco(Math.max(0, total)));

      const parcela = calcularMelhorParcela(Math.max(0, total));
      if (parcela && linhas > 0) {
          $('#summary-parcela').text(
              'ou ' + parcela.vezes + 'x de ' + parcela.valorFmt + ' sem juros'
          ).show();
      } else {
          $('#summary-parcela').hide();
      }

      $('#btn-checkout').prop('disabled', linhas === 0);
      $('#cart-sem-selecao').toggle(linhas === 0);
      $('#btn-remove-selected').toggle(linhas > 0);

      // Estado do select-all
      const totalLinhas = lerItens().length;
      const $checkAll   = $('#cart-select-all');
      if (linhas === 0) {
          $checkAll.prop({ checked: false, indeterminate: false });
      } else if (linhas === totalLinhas) {
          $checkAll.prop({ checked: true,  indeterminate: false });
      } else {
          $checkAll.prop({ checked: false, indeterminate: true });
      }

      // Reset frete se mudar seleção
      if (freteValor !== null && selecionados.map(i => i.id).join() !== ultimoFreteIds) {
          freteValor = null;
          freteServico = null;
          $('#summary-frete').html(
              '<button type="button" class="cart-frete-calcular btn-open-location" id="btn-calcular-frete">Calcular</button>'
          );
          $('#cart-frete-resultado').empty().hide();
      }
      ultimoFreteIds = selecionados.map(i => i.id).join();
  }

    // ── Checkbox individual ───────────────────────────────
    $(document).on('change', '.cart-item-checkbox', function () {
      atualizarResumo();
    });

    // ── Selecionar todos ──────────────────────────────────
    $(document).on('change', '#cart-select-all', function () {
      const checked = $(this).is(':checked');
      $('.cart-item-checkbox').prop('checked', checked);
      atualizarResumo();
    });

    // ── Clique na linha do item seleciona/deseleciona ─────
    $(document).on('click', '.cart-item', function (e) {
      // Não dispara se clicar em botão, input ou link
      if ($(e.target).closest('button, input, a, label').length) return;
      const $cb = $(this).find('.cart-item-checkbox');
      $cb.prop('checked', !$cb.is(':checked'));
      atualizarResumo();
    });

    // ── Quantidade ────────────────────────────────────────
    $(document).on('click', '.cart-qty-minus, .cart-qty-plus', function () {
      
      const $btn_cart  = $(this);
      const id_cart    = $btn_cart.data('item-id');
      const $item_cart = $('#cart-item-' + id_cart);
      const $input_cart = $item_cart.find('.cart-qty-input');
      const max_cart   = parseInt($input_cart.attr('max')) || 99;
      let   qty_cart   = parseInt($input_cart.val()) || 1;

      console.log($btn_cart, id_cart);
      

      if ($btn_cart.hasClass('cart-qty-plus'))  qty_cart = Math.min(qty_cart + 1, max_cart);
      if ($btn_cart.hasClass('cart-qty-minus')) qty_cart = Math.max(qty_cart - 1, 1);

      $input_cart.val(qty_cart);
      atualizarQuantidade(id_cart, qty_cart, $item_cart);
    });

    $(document).on('change', '.cart-qty-input', function () {
      const id    = $(this).data('id');
      const $item = $('#cart-item-' + id);
      const max   = parseInt($(this).attr('max')) || 99;
      let   qty   = Math.min(Math.max(parseInt($(this).val()) || 1, 1), max);
      $(this).val(qty);
      atualizarQuantidade(id, qty, $item);
    });

    function atualizarQuantidade(id, qty, $item) {
        const preco    = parseFloat($item.data('preco')) || 0;
        const subtotal = preco * qty;

        // Atualiza ANTES do Ajax para o resumo responder imediatamente
        $item.data('quantidade', qty).data('subtotal', subtotal);
        $('#cart-item-subtotal-' + id).text(formatarPreco(subtotal));

        // Atualiza botões +/-
        const max = parseInt($item.find('.cart-qty-input').attr('max')) || 99;
        $item.find('.cart-qty-minus').prop('disabled', qty <= 1);
        $item.find('.cart-qty-plus').prop('disabled', qty >= max);

        // Resumo atualiza imediatamente (sem esperar Ajax)
        // atualizarResumo();

        // Persiste no banco em background
        $.post(BASE_URL + '/carrinho/atualizar', {
            item_id     : id,
            quantidade  : qty,
            _csrf_token : CSRF_TOKEN,
        }, function (res) {

            if (res.ok && res.subtotal !== undefined) {
                // Confirma com valor real do servidor
                const subtotalReal = parseFloat(res.subtotal);
                $item.data('subtotal', subtotalReal);
                $('#cart-item-subtotal-' + id).text(formatarPreco(subtotalReal));
                
            }

            atualizarResumo();
        }, 'json');
    }

    

    // ── Remover item ──────────────────────────────────────
    $(document).on('click', '.cart-item-remove', function () {
      const id    = $(this).data('id');
      const $item = $('#cart-item-' + id);
      const nome  = $item.find('.cart-item-name').text().trim();

      if (!confirm('Remover "' + nome + '" do carrinho?')) return;

      removerItem(id, $item);
    });

    // ── Remover selecionados ──────────────────────────────
    $(document).on('click', '#btn-remove-selected', function () {
      const sel = itensSelecionados();
      if (!sel.length || !confirm('Remover ' + sel.length + ' item(ns) selecionado(s)?')) return;

      sel.forEach(function (item) {
        removerItem(item.id, item.$item);
      });
    });

    function removerItem(id, $item) {
      $item.addClass('cart-item--removing');

      $.post(BASE_URL + '/carrinho/remover', {
        item_id     : id,
        _csrf_token : CSRF_TOKEN,
      }, function (res) {
        if (res.ok) {
          $item.slideUp(250, function () {
            $(this).remove();
            

            // Se ficou vazio
            if (!$('.cart-item').length) {
              $('#cart-items').html(`
                <div class="cart-empty">
                  <p>Seu carrinho está vazio.</p>
                  <a href="${BASE_URL}/busca" class="btn btn-primary">
                    Explorar produtos
                  </a>
                </div>
              `);
              $('.cart-sidebar').hide();
            }
          });
          // Atualiza badge do header
          if (res.count !== undefined) {
            $('#cart-count, #mc-badge').text(res.count);
          }
        }

        atualizarResumo();
      }, 'json');
    }

    // ── Calcular frete ────────────────────────────────────
    

    $(document).on('click', '#btn-frete-buscar', function () {
      const cep = $('#cart-cep-input').val().replace(/\D/g, '');
      if (cep.length !== 8) {
        showToast('CEP inválido.', 'error');
        return;
      }

      const selecionados = itensSelecionados();
      if (!selecionados.length) {
        showToast('Selecione ao menos um item.', 'error');
        return;
      }

      const $btn = $(this);
      $btn.prop('disabled', true).text('...');

      // Monta lista de itens selecionados para calcular frete
      const ids = selecionados.map(i => i.id);

      $.get(BASE_URL + '/frete/calcular', {
        cep         : cep,
        item_ids    : ids.join(','),
      }, function (res) {
        $btn.prop('disabled', false).text('OK');

        if (!res.ok || !res.opcoes || !res.opcoes.length) {
          $('#cart-frete-resultado').html(
            '<p class="cart-frete-erro">CEP não encontrado ou frete indisponível.</p>'
          ).show();
          return;
        }

        let html = '<div class="cart-frete-opcoes">';
        res.opcoes.forEach(function (op, i) {
          const gratis = op.valor === 0;
          html += `
            <label class="cart-frete-opcao ${i === 0 ? 'selected' : ''}">
              <input type="radio" name="frete_opcao"
                    value="${op.valor}"
                    data-servico="${op.servico}"
                    ${i === 0 ? 'checked' : ''}>
              <div class="cart-frete-opcao-info">
                <span class="cart-frete-nome">${op.servico}</span>
                <span class="cart-frete-prazo">${op.prazo} dias úteis</span>
              </div>
              <span class="cart-frete-valor ${gratis ? 'text-success' : ''}">
                ${gratis ? 'Grátis' : formatarPreco(op.valor)}
              </span>
            </label>`;
        });
        html += '</div>';

        $('#cart-frete-resultado').html(html).show();

        // Seleciona o primeiro automaticamente
        freteValor   = res.opcoes[0].valor;
        freteServico = res.opcoes[0].servico;
        atualizarResumo();
      }, 'json').fail(function () {
        $btn.prop('disabled', false).text('OK');
        showToast('Erro ao calcular frete.', 'error');
      });
    });

    // Troca de opção de frete
    $(document).on('change', 'input[name="frete_opcao"]', function () {
      freteValor   = parseFloat($(this).val()) || 0;
      freteServico = $(this).data('servico');
      $('.cart-frete-opcao').removeClass('selected');
      $(this).closest('.cart-frete-opcao').addClass('selected');
      atualizarResumo();
    });

    // ── Cupom ─────────────────────────────────────────────
    $(document).on('click', '#btn-cupom-toggle', function () {
      $('#cart-cupom-form').slideToggle(180);
    });

    $(document).on('click', '#btn-cupom-apply', function () {
      const codigo = $('#cart-cupom-input').val().trim().toUpperCase();
      const $fb    = $('#cart-cupom-fb');

      $fb.text('').removeClass('fb-ok fb-erro');
      if (!codigo) return;

      $(this).prop('disabled', true).text('...');

      $.post(BASE_URL + '/carrinho/cupom', {
        codigo      : codigo,
        _csrf_token : CSRF_TOKEN,
      }, function (res) {
        $('#btn-cupom-apply').prop('disabled', false).text('Aplicar');

        if (!res.ok) {
          $fb.text(res.msg).addClass('fb-erro');
          return;
        }

        descontoValor = parseFloat(res.desconto) || 0;
        $fb.text(res.msg).addClass('fb-ok');
        atualizarResumo();
      }, 'json');
    });

    // ── Finalizar compra ──────────────────────────────────
    $(document).on('click', '#btn-checkout', function () {
      const selecionados = itensSelecionados();
      if (!selecionados.length) return;

      const ids = selecionados.map(i => i.id);

      // Salva os itens selecionados na sessão antes de ir para o checkout
      $.post(BASE_URL + '/checkout/selecionar-itens', {
        item_ids    : ids,
        frete_valor : freteValor    || 0,
        frete_servico: freteServico || '',
        _csrf_token : CSRF_TOKEN,
      }, function (res) {
        if (res.ok) {
          window.location.href = BASE_URL + '/checkout';
        }
      }, 'json');
    });

    // ── Helpers ───────────────────────────────────────────
    function formatarPreco(valor) {
      return 'R$ ' + parseFloat(valor).toFixed(2)
        .replace('.', ',')
        .replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    function calcularMelhorParcela(total) {
      const MAX = 10, MIN_PARCELA = 10;
      if (!total || total <= 0) return null;
      for (let n = MAX; n >= 2; n--) {
        const val = total / n;
        if (val >= MIN_PARCELA) {
          return {
            vezes   : n,
            valorFmt: formatarPreco(val),
          };
        }
      }
      return null;
    }

    // ── Inicializa ────────────────────────────────────────
    atualizarResumo();

  })();
});


// assets/js/cart.js

$(function () {

  // ── Estado ───────────────────────────────────────────────
  let mcAberto     = false;
  let fretesAll    = false;
  let freteDados   = [];

  // ── Abrir / fechar ───────────────────────────────────────
  function abrirCart() {
    mcAberto = true;
    $('#mini-cart').addClass('open').attr('aria-hidden', 'false');
    $('#mc-backdrop').addClass('show');
    $('body').addClass('mc-open');
    carregarCart();
  }

  function fecharCart() {
    mcAberto = false;
    $('#mini-cart').removeClass('open').attr('aria-hidden', 'true');
    $('#mc-backdrop').removeClass('show');
    $('body').removeClass('mc-open');
  }

  $(document).on('click', '#btn-open-cart',     abrirCart);
  $(document).on('click', '#mc-close',          fecharCart);
  $(document).on('click', '#mc-backdrop',       fecharCart);
  $(document).on('keydown', function (e) {
    if (e.key === 'Escape' && mcAberto) fecharCart();
  });

  // ── Carregar dados ────────────────────────────────────────
  function carregarCart() {
    $.get(BASE_URL + '/carrinho/mini', function (res) {
      if (!res.ok) return;
      renderizarCart(res);
    }, 'json');
  }

  function renderizarCart(res) {
    // console.log('RENDER CARD', res);
    
    const vazio = !res.items || res.items.length === 0;

    atualizarBadge(res.count || 0);

    if (vazio) {
      $('#mc-empty').show();
      $('#mc-content, #mc-footer').hide();
      return;
    }

    $('#mc-empty').hide();
    $('#mc-content, #mc-footer').show();

    renderItens(res.items);
    // renderizarItens(res.items);
    renderTotais(res);
    sincronizarVendedor(res);
    sincronizarCupom(res);
  }
  

  // ── Itens ─────────────────────────────────────────────────
  // function renderItens(itens) {
  //   const $wrap = $('#mc-items').empty();

  //   itens.forEach(function (item) {
  //     const img = item.imagem
  //       ? `${UPLOAD_URL}/products/${item.imagem}`
  //       : `${UPLOAD_URL}/placeholder.jpg`;

  //     const opts = item.opcoes
  //       ? `<span class="mc-item-opts">${esc(item.opcoes)}</span>`
  //       : '';

  //     const minusDisabled = item.quantidade <= 1 ? 'disabled' : '';
  //     const plusDisabled  = item.quantidade >= (item.estoque || 99) ? 'disabled' : '';

  //     $wrap.append(`
  //       <div class="mc-item" id="mc-item-${item.id}">
  //         <a href="${BASE_URL}/produto/${esc(item.slug)}" class="mc-item-img" tabindex="-1">
  //           <img src="${img}" alt="${esc(item.nome)}"
  //                width="72" height="72" loading="lazy">
  //         </a>
  //         <div class="mc-item-body">
  //           <a href="${BASE_URL}/produto/${esc(item.slug)}" class="mc-item-nome">
  //             ${esc(item.nome)}
  //           </a>
  //           ${opts}
  //           <div class="mc-item-bottom">
  //             <div class="mc-qty-ctrl">
  //               <button class="mc-qty-btn" data-id="${item.id}"
  //                       data-qty="${item.quantidade - 1}"
  //                       ${minusDisabled} aria-label="Diminuir quantidade">
  //                 <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
  //                      stroke="currentColor" stroke-width="3">
  //                   <line x1="5" y1="12" x2="19" y2="12"/>
  //                 </svg>
  //               </button>
  //               <input type="number" class="mc-qty-input" value="${item.quantidade}"
  //                      min="1" max="${item.estoque || 99}"
  //                      data-id="${item.id}" aria-label="Quantidade">
  //               <button class="mc-qty-btn" data-id="${item.id}"
  //                       data-qty="${item.quantidade + 1}"
  //                       ${plusDisabled} aria-label="Aumentar quantidade">
  //                 <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
  //                      stroke="currentColor" stroke-width="3">
  //                   <line x1="12" y1="5" x2="12" y2="19"/>
  //                   <line x1="5" y1="12" x2="19" y2="12"/>
  //                 </svg>
  //               </button>
  //             </div>
  //             <span class="mc-item-preco">${item.subtotal_fmt}</span>
  //           </div>
  //         </div>
  //         <button class="mc-item-del" data-id="${item.id}"
  //                 aria-label="Remover ${esc(item.nome)}">
  //           <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
  //                stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
  //             <line x1="18" y1="6" x2="6" y2="18"/>
  //             <line x1="6"  y1="6" x2="18" y2="18"/>
  //           </svg>
  //         </button>
  //       </div>`);
  //   });
  // }
  function renderizarItens(items) {
    const $lista = $('#mc-items');
    $lista.empty();

    if (!items || !items.length) {
        $lista.html(`
          <div class="mc-empty">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
              <circle cx="9"  cy="21" r="1"/>
              <circle cx="20" cy="21" r="1"/>
              <path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/>
            </svg>
            <p>Seu carrinho está vazio</p>
            <a href="${BASE_URL}/busca" class="btn btn-primary btn-sm">
              Explorar produtos
            </a>
          </div>
        `);
        return;
    }

    items.forEach(function (item) {
        const imgSrc = item.imagem || (BASE_URL + '/assets/images/placeholder.jpg');

        // Monta badges de atributos
        let attrsHtml = '';
        if (item.atributos && item.atributos.length) {
            attrsHtml = '<div class="mc-item-attrs">';
            item.atributos.forEach(function (a) {
                if (a.tipo_display === 'color_swatch' && a.valor_hex) {
                    attrsHtml += `
                      <span class="mc-attr-tag">
                        <span class="mc-attr-swatch"
                              style="background:${a.valor_hex}"></span>
                        ${a.valor}
                      </span>`;
                } else {
                    attrsHtml += `
                      <span class="mc-attr-tag">
                        <span class="mc-attr-label">${a.nome}:</span>
                        ${a.valor}
                      </span>`;
                }
            });
            attrsHtml += '</div>';
        }

        const $item = $(`
          <div class="mc-item" data-id="${item.id}">
            <a href="${BASE_URL}/produto/${item.slug}" class="mc-item-img">
              <img src="${imgSrc}" alt="${item.nome}"
                   width="72" height="72" loading="lazy">
            </a>

            <div class="mc-item-info">
              <a href="${BASE_URL}/produto/${item.slug}"
                 class="mc-item-name">${item.nome}</a>
              ${attrsHtml}

              <div class="mc-item-bottom">
                <div class="mc-item-qty">
                  <button type="button" class="mc-qty-btn mc-qty-minus"
                          data-id="${item.id}">−</button>
                  <input type="number" class="mc-qty-input"
                         value="${item.quantidade}"
                         min="1" max="${item.estoque}"
                         data-id="${item.id}">
                  <button type="button" class="mc-qty-btn mc-qty-plus"
                          data-id="${item.id}">+</button>
                </div>
                <div class="mc-item-prices">
                  <span class="mc-item-price">${item.subtotal_fmt}</span>
                  <span class="mc-item-unit">${item.preco_fmt} / un.</span>
                </div>
              </div>
            </div>

            <button type="button" class="mc-item-remove" data-id="${item.id}"
                    title="Remover">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                <line x1="18" y1="6" x2="6"  y2="18"/>
                <line x1="6"  y1="6" x2="18" y2="18"/>
              </svg>
            </button>
          </div>
        `);

        $lista.append($item);
    });
  }
  function renderItens(itens) {
    const $wrap = $('#mc-items').empty();

    if (!itens || !itens.length) {
        $wrap.append(`
          <div class="mc-empty">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
              <circle cx="9"  cy="21" r="1"/>
              <circle cx="20" cy="21" r="1"/>
              <path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/>
            </svg>
            <p>Seu carrinho está vazio</p>
            <a href="${BASE_URL}/busca" class="btn btn-primary btn-sm">
              Explorar produtos
            </a>
          </div>
        `);
        return;
    }

    itens.forEach(function (item) {
        const img = item.imagem
            ? item.imagem
            : `${BASE_URL}/assets/images/placeholder.jpg`;

        // ── Atributos de variação ──────────────────────────
        let attrsHtml = '';
        if (item.atributos && item.atributos.length) {
            const tagsHtml = item.atributos.map(function (a) {
                if (a.tipo_display === 'color_swatch' && a.valor_hex) {
                    return `<span class="mc-item-attr-tag">
                              <span class="mc-item-attr-swatch"
                                    style="background:${esc(a.valor_hex)}"></span>
                              ${esc(a.valor)}
                            </span>`;
                }
                return `<span class="mc-item-attr-tag">
                          <span class="mc-item-attr-label">${esc(a.nome)}:</span>
                          ${esc(a.valor)}
                        </span>`;
            }).join('');
            attrsHtml = `<div class="mc-item-attrs">${tagsHtml}</div>`;
        } else if (item.opcoes) {
            // Compatibilidade com o campo opcoes antigo
            attrsHtml = `<span class="mc-item-opts">${esc(item.opcoes)}</span>`;
        }

        const minusDisabled = item.quantidade <= 1                      ? 'disabled' : '';
        const plusDisabled  = item.quantidade >= (item.estoque || 99)   ? 'disabled' : '';

        $wrap.append(`
          <div class="mc-item" id="mc-item-${item.id}">
            <a href="${BASE_URL}/produto/${esc(item.slug)}"
               class="mc-item-img" tabindex="-1">
              <img src="${img}" alt="${esc(item.nome)}"
                   width="72" height="72" loading="lazy">
            </a>

            <div class="mc-item-body">
              <a href="${BASE_URL}/produto/${esc(item.slug)}"
                 class="mc-item-nome">
                ${esc(item.nome)}
              </a>

              ${attrsHtml}

              <div class="mc-item-bottom">
                <div class="mc-qty-ctrl">
                  <button class="mc-qty-btn"
                          data-id="${item.id}"
                          data-qty="${item.quantidade - 1}"
                          ${minusDisabled}
                          aria-label="Diminuir quantidade">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="3">
                      <line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                  </button>
                  <input type="number" class="mc-qty-input"
                         value="${item.quantidade}"
                         min="1" max="${item.estoque || 99}"
                         data-id="${item.id}"
                         aria-label="Quantidade">
                  <button class="mc-qty-btn"
                          data-id="${item.id}"
                          data-qty="${item.quantidade + 1}"
                          ${plusDisabled}
                          aria-label="Aumentar quantidade">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="3">
                      <line x1="12" y1="5" x2="12" y2="19"/>
                      <line x1="5"  y1="12" x2="19" y2="12"/>
                    </svg>
                  </button>
                </div>
                <span class="mc-item-preco">${item.subtotal_fmt}</span>
              </div>
            </div>

            <button class="mc-item-del"
                    data-id="${item.id}"
                    aria-label="Remover ${esc(item.nome)}">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                <line x1="18" y1="6" x2="6"  y2="18"/>
                <line x1="6"  y1="6" x2="18" y2="18"/>
              </svg>
            </button>
          </div>
        `);
    });
}

  // ── Controles de quantidade ───────────────────────────────

  // Botões + e -
  $(document).on('click', '.mc-qty-btn', function () {
    const id  = $(this).data('id');
    const qty = parseInt($(this).data('qty'));
    if (qty >= 0) atualizarItem(id, qty);
  });

  // Input numérico direto
  let qtyTimer;
  $(document).on('input', '.mc-qty-input', function () {
    const $input = $(this);
    const id     = $input.data('id');
    clearTimeout(qtyTimer);
    qtyTimer = setTimeout(function () {
      const qty = parseInt($input.val()) || 1;
      const min = parseInt($input.attr('min')) || 1;
      const max = parseInt($input.attr('max')) || 99;
      atualizarItem(id, Math.min(Math.max(qty, min), max));
    }, 600);
  });

  // Enter no input
  $(document).on('keydown', '.mc-qty-input', function (e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      $(this).trigger('input');
    }
  });

  // Remover item
  $(document).on('click', '.mc-item-del', function () {
    atualizarItem($(this).data('id'), 0);
  });

  function atualizarItem(id, qty) {
    // Feedback visual imediato
    const $item = $(`#mc-item-${id}`);
    if (qty === 0) {
      $item.addClass('mc-item--removing');
    }

    $.post(BASE_URL + '/carrinho/atualizar', {
      item_id:     id,
      quantidade:  qty,
      _csrf_token: CSRF_TOKEN,
    }, function (res) {
      if (res.ok) {
        const vazio = !res.items || res.items.length === 0;
        atualizarBadge(res.count || 0);
        calcularFrete();
        if (vazio) {
          $('#mc-empty').show();
          $('#mc-content, #mc-footer').hide();
          return;
        }

        renderTotais(res);
        renderItens(res.items);
        atualizarBadge(res.count);
        if (qty === 0) showToast('Item removido.', 'info');
      } else {
        $item.removeClass('mc-item--removing');
        showToast(res.msg || 'Erro ao atualizar.', 'error');
      }
    }, 'json').fail(function () {
      $item.removeClass('mc-item--removing');
    });
  }

  // ── Totais ─────────────────────────────────────────────────
  function renderTotais(res) {
    $('#mc-subtotal').text(res.subtotal_fmt || '—');
    $('#mc-total').text(res.total_fmt     || '—');

    if (res.desconto > 0) {
      $('#mc-row-desconto').show();
      $('#mc-desconto').text('− ' + res.desconto_fmt);
    } else {
      $('#mc-row-desconto').hide();
    }

    if (res.frete > 0) {
      $('#mc-row-frete').show();
      $('#mc-frete-valor').text(res.frete_fmt);
      $('#mc-frete-servico').text(res.frete_servico ? '· ' + res.frete_servico : '');
    } else if (res.frete === 0 && res.frete_servico) {
      $('#mc-row-frete').show();
      $('#mc-frete-valor').html('<span class="mc-frete-gratis">Grátis</span>');
      $('#mc-frete-servico').text('· ' + res.frete_servico);
    } else {
      $('#mc-row-frete').hide();
    }

    if (res.melhor_parcela) {
      $('#mc-parcela').text('ou ' + res.melhor_parcela).show();
    } else {
      $('#mc-parcela').hide();
    }
  }

  // ── Acordeões ─────────────────────────────────────────────
  $(document).on('click', '.mc-accordion-btn', function () {
    const acord = $(this);
    const target   = $(this).data('target');
    const $body    = $('#' + target);
    const $chevron = $(this).find('.mc-accordion-chevron');
    const isOpen   = $body.is(':visible');
    
    // Fecha outros abertos
    $('.mc-accordion-body').not($body).slideUp(180);
    $('.mc-accordion-chevron').not($chevron).css('transform', 'rotate(0deg)');

    $body.slideToggle(180);
    $chevron.css('transform', isOpen ? 'rotate(0deg)' : 'rotate(180deg)');

    // Pré-preenche CEP se abrir o frete
    if (target === 'mc-shipping' && !isOpen) {
      const cep = getCookieCep();
      if (cep && !$('#mc-cep-input').val()) {
        $body.find('.mc-link-btn').text('Carregando...').prop('disabled', true);
        $('#mc-cep-input').val(cep.substring(0,5) + '-' + cep.substring(5));
        $('#mc-cep-display').text(
          cep.substring(0,5) + '-' + cep.substring(5)
        );
        $('#mc-cep-row').show();
        $('#mc-cep-form').hide();
        calcularFrete(function(){
          $body.find('.mc-link-btn').text('Alterar').prop('disabled', false);
        })
      }
    }
  });

  // ── Vendedor ───────────────────────────────────────────────
  function sincronizarVendedor(res) {
    if (res.vendedor_codigo) {
      $('#mc-seller-tag-txt').text('Vendedor: ' + res.vendedor_codigo);
      $('#mc-seller-tag').show();
      $('#mc-seller-form').hide();
      $('#mc-seller-status').text(res.vendedor_codigo);
    } else {
      $('#mc-seller-tag').hide();
      $('#mc-seller-form').show();
      $('#mc-seller-status').text('');
    }
  }

  $('#mc-seller-apply').on('click', function () {
    const codigo = $('#mc-seller-input').val().trim();
    if (!codigo) { feedbackMc('mc-seller-fb', 'Digite um código.', false); return; }

    $(this).prop('disabled', true).text('...');

    $.post(BASE_URL + '/carrinho/vendedor', {
      codigo, _csrf_token: CSRF_TOKEN
    }, function (res) {
      feedbackMc('mc-seller-fb', res.msg, res.ok);
      if (res.ok) { carregarCart(); $('#mc-seller-input').val(''); }
      $('#mc-seller-apply').prop('disabled', false).text('Aplicar');
    }, 'json');
  });

  $(document).on('click', '#mc-seller-remove', function () {
    $.post(BASE_URL + '/carrinho/vendedor/remover', {
      _csrf_token: CSRF_TOKEN
    }, function (res) {
      if (res.ok) { carregarCart(); feedbackMc('mc-seller-fb', '', true); }
    }, 'json');
  });

  // ── Cupom ──────────────────────────────────────────────────
  function sincronizarCupom(res) {
    if (res.cupom_codigo) {
      $('#mc-coupon-tag-txt').text(res.cupom_codigo + ' — ' + res.desconto_fmt);
      $('#mc-coupon-tag').show();
      $('#mc-coupon-form').hide();
      $('#mc-coupon-status').text('Aplicado');
    } else {
      $('#mc-coupon-tag').hide();
      $('#mc-coupon-form').show();
      $('#mc-coupon-status').text('');
    }
  }

  $('#mc-coupon-apply').on('click', function () {
    const codigo_vendedor = $('#mc-coupon-input').val().trim().toUpperCase();
    if (!codigo_vendedor) { feedbackMc('mc-coupon-fb', 'Digite um cupom.', false); return; }

    $(this).prop('disabled', true).text('...');

    $.post(BASE_URL + '/carrinho/cupom', {
      codigo_vendedor, _csrf_token: CSRF_TOKEN
    }, function (res) {
      feedbackMc('mc-coupon-fb', res.msg, res.ok);
      if (res.ok) { carregarCart(); $('#mc-coupon-input').val(''); }
      $('#mc-coupon-apply').prop('disabled', false).text('Aplicar');
    }, 'json');
  });

  $(document).on('click', '#mc-coupon-remove', function () {
    $.post(BASE_URL + '/carrinho/cupom/remover', {
      _csrf_token: CSRF_TOKEN
    }, function (res) {
      if (res.ok) { carregarCart(); feedbackMc('mc-coupon-fb', '', true); }
    }, 'json');
  });

  // ── Frete ──────────────────────────────────────────────────
  $('#mc-cep-change').on('click', function () {
    $('#mc-cep-row').hide();
    $('#mc-cep-form').show();
    $('#mc-frete-resultado').hide();
    freteDados = [];
    $('#mc-cep-input').val('').focus();
  });

  $('#mc-calc-frete').on('click', calcularFrete);
  $('#mc-cep-input').on('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); calcularFrete(); }
  });

  function calcularFrete(call) {
    
    let cep = $('#mc-cep-input').val().replace(/\D/g, '');

    if (cep.length !== 8 && !window.EC_CEP_ATIVO) {
      feedbackMc('mc-cep-fb', 'CEP inválido.', false);
      return;
    }

    const $btn = $('#mc-calc-frete');
    $btn.prop('disabled', true).text('...');
    feedbackMc('mc-cep-fb', '', true);

    if(window.EC_CEP_ATIVO){
      cep = window.EC_CEP_ATIVO;
    }

    $.get(BASE_URL + '/carrinho/frete', { cep:cep, _csrf_token: CSRF_TOKEN }, function (res) {
      // console.log(res);
      if(typeof call == 'function'){
        call();
      }
      $btn.prop('disabled', false).text('Calcular');

      if (!res.ok || !res.opcoes || !res.opcoes.length) {
        feedbackMc('mc-cep-fb', res.msg || 'Nenhuma opção disponível.', false);
        return;
      }

      // Salva CEP
      document.cookie = `ec_cep=${cep};path=/;max-age=${86400*30};samesite=Lax`;
      window.EC_CEP_ATIVO = cep;

      $('#mc-cep-display').text(
        cep.substring(0,5) + '-' + cep.substring(5) +
        (res.cidade ? ' — ' + res.cidade : '')
      );
      $('#mc-cep-row').show();
      $('#mc-cep-form').hide();

      // Ordena por preço
      freteDados = res.opcoes.sort((a, b) => a.valor - b.valor);
      fretesAll  = false;
      renderFretes(freteDados);

      // Atualiza status do accordion
      $('#mc-shipping-status').text(
        cep.substring(0,5) + '-' + cep.substring(5)
      );
    }, 'json').fail(function () {
      $btn.prop('disabled', false).text('Calcular');
      feedbackMc('mc-cep-fb', 'Erro de conexão.', false);
    });
  }

  function renderFretes(opcoes) {
    const melhor = opcoes[0];
    const outros = opcoes.slice(1);

    $('#mc-frete-best').html(buildFreteOpcao(melhor, true));

    const $outros = $('#mc-frete-outros').empty();
    outros.forEach(op => $outros.append(buildFreteOpcao(op, false)));

    if (outros.length > 0) {
      $('#mc-toggle-fretes').show();
    } else {
      $('#mc-toggle-fretes').hide();
    }

    $('#mc-frete-outros').hide();
    $('#mc-frete-resultado').show();
    fretesAll = false;
    atualizarBtnToggleFrete(false);
  }

  function buildFreteOpcao(op, isMelhor) {
    const precoHtml = op.valor <= 0
      ? '<strong class="mc-frete-gratis">Grátis</strong>'
      : `<strong>${op.valor_fmt}</strong>`;

    const prazoHtml = op.prazo_dias
      ? `<small>${op.prazo_dias} dia${op.prazo_dias !== 1 ? 's' : ''} úteis</small>`
      : '';

    const tag = isMelhor
      ? '<span class="mc-frete-tag-melhor">Mais barato</span>'
      : '';

    return `
      <div class="mc-frete-opcao ${isMelhor ? 'mc-frete-opcao--melhor' : ''}"
           data-servico="${esc(op.servico)}"
           data-preco="${op.valor}"
           data-prazo="${op.prazo_dias || 0}">
        <div class="mc-frete-esq">
          <span class="mc-frete-nome">${esc(op.servico)}</span>
          ${prazoHtml}
        </div>
        <div class="mc-frete-dir">
          ${tag}
          ${precoHtml}
        </div>
      </div>`;
  }

  // Seleciona frete ao clicar
  $(document).on('click', '.mc-frete-opcao', function () {
    const servico = $(this).data('servico');
    const preco   = parseFloat($(this).data('preco'));
    const prazo   = parseInt($(this).data('prazo'));

    $('.mc-frete-opcao').removeClass('mc-frete-opcao--selecionado');
    $(this).addClass('mc-frete-opcao--selecionado');

    $.post(BASE_URL + '/carrinho/frete/selecionar', {
      servico, preco, prazo, _csrf_token: CSRF_TOKEN,
    }, function (res) {
      if (res.ok) {
        renderTotais(res);
        showToast('Frete ' + servico + ' selecionado.', 'success');
      }
    }, 'json');
  });

  // Toggle ver todos / ocultar
  $('#mc-toggle-fretes').on('click', function () {
    fretesAll = !fretesAll;
    $('#mc-frete-outros').slideToggle(180);
    atualizarBtnToggleFrete(fretesAll);
  });

  function atualizarBtnToggleFrete(aberto) {
    const rotacao = aberto ? 'rotate(180deg)' : 'rotate(0deg)';
    const texto   = aberto ? 'Ocultar opções' : 'Ver todas as opções';
    $('#mc-toggle-fretes')
      .find('svg').css('transform', rotacao).end()
      .contents().filter(function () {
        return this.nodeType === 3;
      }).last().replaceWith(' ' + texto);
  }

  // ── Compartilhar ───────────────────────────────────────────
  // Substituir todo o bloco de compartilhar no cart.js

// ── Compartilhar ───────────────────────────────────────────
$('#mc-share-btn').on('click', function () {
  $('#mc-share-box').slideToggle(180);
});

$('#mc-share-gerar').on('click', function () {
    const $btn  = $(this);
    const nome  = $('#mc-share-nome').length ? $('#mc-share-nome').val().trim() : '';

    $btn.prop('disabled', true).text('Gerando...');

    $.post(BASE_URL + '/carrinho/compartilhar', {
        nome,
        _csrf_token: CSRF_TOKEN,
    }, function (res) {
        $btn.prop('disabled', false).text('Gerar link');

        if (!res.ok) {
            showToast(res.msg || 'Erro ao gerar link.', 'error');
            return;
        }

        $('#mc-share-url').val(res.url);
        $('#mc-share-expira').text('Válido até ' + res.expira_em);
        $('#mc-share-gerar').hide();
        $('#mc-share-link-box').slideDown(180);

        if (navigator.share) {
            navigator.share({ title: 'Confira este carrinho!', url: res.url })
                     .catch(() => {});
        }
    }, 'json').fail(function () {
        $btn.prop('disabled', false).text('Gerar link');
        showToast('Erro de conexão.', 'error');
    });
});

// Copiar link
$('#mc-share-copy').on('click', function () {
  const url = $('#mc-share-url').val();
  if (!url) return;
  if (navigator.clipboard) {
    navigator.clipboard.writeText(url)
      .then(() => showToast('Link copiado!', 'success'));
  } else {
    $('#mc-share-url').select();
    document.execCommand('copy');
    showToast('Link copiado!', 'success');
  }
});

$('#mc-share-reset').on('click', function () {
  $('#mc-share-url').val('');
  $('#mc-share-link-box').hide();
  $('#mc-share-gerar').show().text('Gerar link');
});

  // ── Adicionar ao carrinho (botões externos) ────────────────
  $(document).on('click', '.btn-add-cart', function (e) {
    e.preventDefault();
    const $btn = $(this);
    const pid  = $btn.data('product-id');
    const qty  = parseInt($btn.data('qty') || 1);

    $btn.prop('disabled', true);

    $.post(BASE_URL + '/carrinho/adicionar', {
      produto_id:  pid,
      quantidade:  qty,
      _csrf_token: CSRF_TOKEN,
    }, function (res) {
      if (res.ok) {
        atualizarBadge(res.count);
        showToast('Adicionado ao carrinho!', 'success');
        abrirCart();
      } else {
        showToast(res.msg || 'Erro ao adicionar.', 'error');
      }
      $btn.prop('disabled', false);
    }, 'json').fail(function () {
      showToast('Erro de conexão.', 'error');
      $btn.prop('disabled', false);
    });
  });

  // ── Helpers ────────────────────────────────────────────────
  function atualizarBadge(count) {
    $('#cart-count, #mc-badge').text(count).toggle(count > 0);
  }

  function feedbackMc(id, msg, ok) {
    const $el = $('#' + id);
    $el.removeClass('mc-fb--ok mc-fb--err');
    if (!msg) { $el.text('').hide(); return; }
    $el.text(msg)
       .addClass(ok ? 'mc-fb--ok' : 'mc-fb--err')
       .show();
  }

  function getCookieCep() {
    const m = document.cookie.match(/(?:^|;\s*)ec_cep=([^;]+)/);
    return m ? m[1].replace(/\D/g, '') : null;
  }

  function esc(str) {
    return String(str ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  // ── Vendedor ───────────────────────────────────────────────

// Sincroniza o bloco de vendedor com os dados do carrinho
  function sincronizarVendedor(res) {
      if (res.vendedor_codigo && res.vendedor_nome) {
          mostrarVendedorAplicado(res.vendedor_nome, res.vendedor_codigo);
      } else {
          mostrarVendedorForm();
      }
  }

  function mostrarVendedorAplicado(nome, codigo) {
      $('#mc-seller-tag-nome').text(nome);
      $('#mc-seller-tag-codigo').text('(' + codigo + ')');
      $('#mc-seller-tag').show();
      $('#mc-seller-form').hide();
      $('#mc-seller-status').text(codigo);
  }

  function mostrarVendedorForm() {
      $('#mc-seller-tag').hide();
      $('#mc-seller-form').show();
      $('#mc-seller-input').val('');
      $('#mc-seller-fb').text('').hide();
      $('#mc-seller-status').text('');
  }

  // Aplicar vendedor
  $('#mc-seller-apply').on('click', function () {
      const codigo = $('#mc-seller-input').val().trim();

      if (!codigo) {
          feedbackMc('mc-seller-fb', 'Informe o código do vendedor.', false);
          return;
      }

      const $btn = $(this);
      $btn.prop('disabled', true).text('...');

      $.post(BASE_URL + '/carrinho/vendedor', {
          codigo,
          _csrf_token: CSRF_TOKEN,
      }, function (res) {
          $btn.prop('disabled', false).text('Aplicar');

          if (!res.ok) {
              feedbackMc('mc-seller-fb', res.msg, false);
              return;
          }

          feedbackMc('mc-seller-fb', '', true);
          mostrarVendedorAplicado(res.vendedor_nome, res.codigo);

          // Atualiza mcData para o compartilhar usar
          if (mcData) {
              mcData.vendedor_nome   = res.vendedor_nome;
              mcData.vendedor_codigo = res.codigo;
          }

          showToast(res.msg, 'success');
      }, 'json').fail(function () {
          $btn.prop('disabled', false).text('Aplicar');
          feedbackMc('mc-seller-fb', 'Erro de conexão.', false);
      });
  });

  // Editar vendedor (volta para o form)
  $(document).on('click', '#mc-seller-edit', function () {
      mostrarVendedorForm();
      // Abre o accordion se estiver fechado
      const $body = $('#mc-seller');
      if (!$body.is(':visible')) {
          $body.slideDown(180);
          $('[data-target="mc-seller"] .mc-accordion-chevron')
              .css('transform', 'rotate(180deg)');
      }
      setTimeout(() => $('#mc-seller-input').focus(), 50);
  });

  // Remover vendedor
  $(document).on('click', '#mc-seller-remove', function () {
      $.post(BASE_URL + '/carrinho/vendedor/remover', {
          _csrf_token: CSRF_TOKEN,
      }, function (res) {
          if (!res.ok) return;
          mostrarVendedorForm();
          if (mcData) {
              mcData.vendedor_nome   = null;
              mcData.vendedor_codigo = null;
          }
          showToast(res.msg, 'info');
      }, 'json');
  });

  // Enter no input do vendedor
  $('#mc-seller-input').on('keydown', function (e) {
      if (e.key === 'Enter') {
          e.preventDefault();
          $('#mc-seller-apply').trigger('click');
      }
  });

  

});