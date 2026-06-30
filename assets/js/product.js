// assets/js/product.js
// Comportamentos da página de produto.

$(function () {
  if (!$('.product-page').length) return;

  // ── Galeria de imagens ───────────────────────────────────
  const $mainImg  = $('#gallery-main-img');
  const $thumbs   = $('.gallery-thumb');
  let   currentIdx = 0;

  function setGalleryImage(idx) {
    const $thumb = $thumbs.eq(idx);
    const src    = $thumb.data('src');
    if (!src) return;

    $thumbs.removeClass('active');
    $thumb.addClass('active');
    $mainImg.addClass('loading').attr('src', src).one('load', function () {
      $(this).removeClass('loading');
    });
    currentIdx = idx;
  }

  $thumbs.on('click', function () {
    setGalleryImage($(this).data('index'));
  });

  $('#gallery-prev').on('click', function () {
    setGalleryImage((currentIdx - 1 + $thumbs.length) % $thumbs.length);
  });
  $('#gallery-next').on('click', function () {
    setGalleryImage((currentIdx + 1) % $thumbs.length);
  });

  // Touch swipe na imagem principal
  let touchStartX = 0;
  document.querySelector('#zoom-wrapper')?.addEventListener('touchstart', e => {
    touchStartX = e.changedTouches[0].clientX;
  }, { passive: true });
  document.querySelector('#zoom-wrapper')?.addEventListener('touchend', e => {
    const diff = touchStartX - e.changedTouches[0].clientX;
    if (Math.abs(diff) > 50 && $thumbs.length > 1) {
      setGalleryImage(diff > 0
        ? (currentIdx + 1) % $thumbs.length
        : (currentIdx - 1 + $thumbs.length) % $thumbs.length);
    }
  });

  // ── Variações de produto ─────────────────────────────────
  const $varContainer = $('#product-variations');
  if ($varContainer.length) {
    const variationsData = JSON.parse($varContainer.data('variations') || '[]');
    const stockData      = JSON.parse($varContainer.data('stock')      || '[]');

    // Estado atual das seleções: { variacao_id: opcao_id }
    const selected = {};

    $(document).on('click', '.var-option', function () {
      const $btn   = $(this);
      const varId  = $btn.data('variation-id');
      const optId  = $btn.data('option-id');
      const value  = $btn.data('value');

      // Deseleciona opções da mesma variação
      $(`.var-option[data-variation-id="${varId}"]`).removeClass('selected');
      $btn.addClass('selected');
      selected[varId] = optId;

      // Atualiza label "Selecionado: X"
      $(`#sel-var-${varId}`).text(value);

      // Troca imagem se a opção tiver imagem própria
      if ($btn.hasClass('var-option--color') && $btn.data('imagem')) {
        // (implementar se houver imagem por variação)
      }

      updateStockAndPrice();
      $('#variation-alert').hide();
    });

    function getCurrentCombination() {
      return Object.values(selected).sort().join('-');
    }

    function updateStockAndPrice() {
      const allSelected = variationsData.every(v => selected[v.id]);
      if (!allSelected) return;

      const combo = getCurrentCombination();
      const stock = stockData.find(s => {
        // Compara independente da ordem dos IDs
        const a = s.combinacao_opcoes.split('-').map(Number).sort().join('-');
        const b = combo.split('-').map(Number).sort().join('-');
        return a === b;
      });

      const $stockInfo  = $('#stock-info');
      const $qtyInput   = $('#product-qty');
      const $btnBuy     = $('#btn-buynow, #btn-add-cart');

      if (!stock || stock.quantidade === 0) {
        $stockInfo.html('<span class="stock-badge stock-badge--out">Esgotado</span>');
        $btnBuy.prop('disabled', true).addClass('btn-soldout');
        $qtyInput.attr('max', 0);
      } else {
        const qty = stock.quantidade;
        $qtyInput.attr('max', qty).val(Math.min(parseInt($qtyInput.val()), qty));

        if (qty <= 5) {
          $stockInfo.html(`<span class="stock-badge stock-badge--low">Últimas ${qty} unidades</span>`);
        } else {
          $stockInfo.html('<span class="stock-badge stock-badge--in">Em estoque</span>');
        }

        $btnBuy.prop('disabled', false).removeClass('btn-soldout');

        // Ajusta preço se houver preço extra na variação
        if (parseFloat(stock.preco_extra) > 0) {
          const basePrice = parseFloat($('#product-info').data('base-price') || 0);
          // (atualizar bloco de preço se necessário)
        }
      }
    }

    // Bloqueia ação se variação não selecionada
    $('#btn-buynow, #btn-add-cart').on('click', function (e) {
      const allSelected = variationsData.every(v => selected[v.id]);
      if (!allSelected) {
        e.stopImmediatePropagation();
        $('#variation-alert').show();
        $varContainer[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
        return false;
      }
    });
  }

  if (window.AVISO_PRODUTO_ID) {
    $('#aviso-estoque-box').show();
    if (window.AVISO_EMAIL) $('#aviso-email').val(window.AVISO_EMAIL);
  }

  $('#aviso-btn').on('click', function () {
    var email = $('#aviso-email').val();
    var $msg  = $('#aviso-msg');

    if (!email || email.indexOf('@') === -1) {
      $msg.removeClass('ok').addClass('err').text('Informe um email válido.').show();
      return;
    }

    var $btn = $(this).prop('disabled', true).text('Enviando...');

    $.post((window.BASE_URL || '') + '/produto/avisar-estoque', {
      produto_id: window.AVISO_PRODUTO_ID,
      email: email,
      csrf_token: window.CSRF_TOKEN || ''
    }, null, 'json')
    .done(function (r) {
      if (r.ok) {
        $msg.removeClass('err').addClass('ok').text(r.msg).show();
        $('#aviso-email').prop('disabled', true);
        $btn.text('Inscrito ✓');
      } else {
        $msg.removeClass('ok').addClass('err').text(r.msg || 'Erro. Tente novamente.').show();
        $btn.prop('disabled', false).text('Avise-me');
      }
    })
    .fail(function () {
      $msg.removeClass('ok').addClass('err').text('Erro de conexão. Tente novamente.').show();
      $btn.prop('disabled', false).text('Avise-me');
    });
  });

  // ── Controle de quantidade ───────────────────────────────
  $('#qty-minus').on('click', function () {
    const $input = $('#product-qty');
    const val = parseInt($input.val());
    if (val > 1) $input.val(val - 1);
  });
  $('#qty-plus').on('click', function () {
    const $input = $('#product-qty');
    const max = parseInt($input.attr('max')) || 999;
    const val = parseInt($input.val());
    if (val < max) $input.val(val + 1);
    else showToast(`Máximo disponível: ${max} unidades.`, 'warning');
  });

  // ── Adicionar ao carrinho (página de produto) ────────────
  $('#btn-add-cart').on('click', function () {
    const productId  = $(this).data('product-id');
    const qty        = parseInt($('#product-qty').val()) || 1;
    const variations = getSelectedVariations();

    $(this).prop('disabled', true).text('Adicionando...');

    $.post(BASE_URL + '/carrinho/adicionar', {
      produto_id:  productId,
      quantidade:  qty,
      variacoes:   variations,
      _csrf_token: CSRF_TOKEN
    }, function (res) {
      if (res.ok) {
        updateCartCount(res.cart_count);
        showToast('Produto adicionado ao carrinho!', 'success');
        // Abre mini-cart
        setTimeout(() => $('#btn-open-cart').trigger('click'), 400);
      } else {
        showToast(res.msg || 'Não foi possível adicionar.', 'error');
      }
      $('#btn-add-cart').prop('disabled', false).html(`
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
          <path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/>
        </svg>
        Adicionar ao carrinho`);
    }, 'json').fail(function () {
      showToast('Erro de conexão.', 'error');
      $('#btn-add-cart').prop('disabled', false);
    });
  });

  // Comprar agora: adiciona e redireciona para checkout
  $('#btn-buynow').on('click', function () {
    const productId  = $(this).data('product-id');
    const qty        = parseInt($('#product-qty').val()) || 1;
    const variations = getSelectedVariations();

    $.post(BASE_URL + '/carrinho/adicionar', {
      produto_id:  productId,
      quantidade:  qty,
      variacoes:   variations,
      _csrf_token: CSRF_TOKEN
    }, function (res) {
      if (res.ok) {
        window.location.href = BASE_URL + '/checkout';
      } else {
        showToast(res.msg || 'Não foi possível continuar.', 'error');
      }
    }, 'json');
  });

  function getSelectedVariations() {
    const result = {};
    $('.var-option.selected').each(function () {
      result[$(this).data('variation-id')] = $(this).data('option-id');
    });
    return result;
  }

  // ── Abas (Descrição / Ficha / Avaliações) ────────────────
  $(document).on('click', '.tab-btn', function () {
    const tabId = $(this).data('tab');
    $('.tab-btn').removeClass('active');
    $('.tab-panel').removeClass('active');
    $(this).addClass('active');
    $(`#tab-${tabId}`).addClass('active');
  });

  // Scroll para avaliações ao clicar no link
  $('a[href="#reviews"]').on('click', function (e) {
    e.preventDefault();
    $('#tab-reviews-btn').trigger('click');
    $('html, body').animate({ scrollTop: $('#product-tabs').offset().top - 80 }, 400);
  });

  // ── Parcelamento ─────────────────────────────────────────
  $('#btn-installments').on('click', function () {
    const $drop = $('#installments-dropdown');
    $drop.is(':visible') ? $drop.slideUp(200) : $drop.slideDown(200);
    $(this).text($drop.is(':visible') ? 'Ver parcelamento completo' : 'Ocultar parcelamento');
  });

  // ── Cálculo de frete ─────────────────────────────────────
  $('#btn-calc-shipping').on('click', calcShipping);
  $('#shipping-cep').on('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); calcShipping(); }
  });

  function calcShipping() {
    const cep = $('#shipping-cep').val().replace(/\D/g, '');
    if (cep.length !== 8) {
      showToast('Informe um CEP válido com 8 dígitos.', 'warning');
      return;
    }

    const $results = $('#shipping-results');
    const $btn     = $('#btn-calc-shipping');
    $btn.prop('disabled', true).text('Calculando...');
    $results.html('<div class="shipping-loading">Buscando opções...</div>').show();

    $.get(BASE_URL + '/frete/calcular', {
      cep:        cep,
      produto_id:11,
      quantidade: 1,
    }, function (res) {
      if (!res.ok || !res.opcoes || !res.opcoes.length) {
        $results.html('<p class="shipping-error">CEP não encontrado ou frete indisponível.</p>');
        $btn.prop('disabled', false).text('Calcular');
        return;
      }

      let html = '<ul class="shipping-options">';
      res.opcoes.forEach(function (opt) {
        html += `
          <li class="shipping-option">
            <label class="shipping-option-label">
              <input type="radio" name="frete_opcao"
                     value="${opt.servico}"
                     data-valor="${opt.valor}"
                     data-prazo="${opt.prazo}">
              <div class="shipping-option-info">
                <span class="shipping-name">${opt.nome}</span>
                <span class="shipping-prazo">Prazo: ${opt.prazo} dia(s) útil(eis)</span>
              </div>
              <span class="shipping-price">
                ${parseFloat(opt.valor) === 0 ? '<strong>Grátis</strong>' : 'R$ ' + parseFloat(opt.valor).toFixed(2).replace('.', ',')}
              </span>
            </label>
          </li>`;
      });
      html += '</ul>';
      html += `<p class="shipping-cep-info">Entrega para: <strong>${res.localidade} - ${res.uf}</strong></p>`;
      $results.html(html);

      // Salva frete selecionado em cookie para o carrinho/checkout
      $('input[name="frete_opcao"]').on('change', function () {
        const data = {
          servico: $(this).val(),
          valor:   $(this).data('valor'),
          prazo:   $(this).data('prazo'),
          cep:     cep,
        };
        document.cookie = `frete_selecionado=${JSON.stringify(data)};path=/;max-age=86400`;
      });

      $btn.prop('disabled', false).text('Calcular');
    }, 'json').fail(function () {
      $results.html('<p class="shipping-error">Não foi possível calcular o frete.</p>');
      $btn.prop('disabled', false).text('Calcular');
    });
  }

  // ── Copiar link ──────────────────────────────────────────
  $('#btn-copy-link').on('click', function () {
    const url = $(this).data('url');
    if (navigator.clipboard) {
      navigator.clipboard.writeText(url).then(function () {
        showToast('Link copiado!', 'success');
      });
    }
  });

  // ── Avaliações: stars input ──────────────────────────────
  const $starInputs  = $('#star-rating input');
  const $starLabels  = $('#star-rating label');

  $starLabels.on('mouseover', function () {
    const val = parseInt($(this).prev('input').val());
    $starLabels.each(function (i) {
      $(this).toggleClass('hovered', i >= (5 - val));
    });
  }).on('mouseout', function () {
    $starLabels.removeClass('hovered');
  });

  // Contador de caracteres no textarea
  $('#review-comentario').on('input', function () {
    $('#review-char-count').text($(this).val().length + '/1000');
  });

  // Submit de avaliação
  $('#review-form').on('submit', function (e) {
    e.preventDefault();
    const $btn = $('#btn-submit-review');

    if (!$('#review-comentario').val().trim()) {
      showToast('Escreva um comentário para enviar.', 'warning');
      return;
    }

    $btn.prop('disabled', true).text('Enviando...');

    $.post(BASE_URL + '/avaliacao/salvar', $(this).serialize(), function (res) {
      if (res.ok) {
        showToast(res.msg, 'success');
        $('#review-form')[0].reset();
        $('#review-char-count').text('0/1000');
      } else {
        showToast(res.msg, 'error');
      }
      $btn.prop('disabled', false).text('Enviar avaliação');
    }, 'json').fail(function () {
      showToast('Erro ao enviar. Tente novamente.', 'error');
      $btn.prop('disabled', false).text('Enviar avaliação');
    });
  });

  // ── Filtros do catálogo ──────────────────────────────────
  if ($('.catalog-page').length) {

    // Ordenação — redireciona ao mudar
    $('#sort-select').on('change', function () {
      const params = new URLSearchParams(window.location.search);
      params.set('ordem', $(this).val());
      params.delete('pagina');
      window.location.search = params.toString();
    });

    // Sidebar mobile
    $('#btn-filter-mobile').on('click', function () {
      $('#catalog-sidebar').addClass('open');
      $('#sidebar-overlay').addClass('visible');
      $('body').addClass('no-scroll');
    });
    $('#btn-sidebar-close, #sidebar-overlay').on('click', function () {
      $('#catalog-sidebar').removeClass('open');
      $('#sidebar-overlay').removeClass('visible');
      $('body').removeClass('no-scroll');
    });

    // Price range sliders
    const $sliderMin = $('#slider-min');
    const $sliderMax = $('#slider-max');
    const $inputMin  = $('#preco_min');
    const $inputMax  = $('#preco_max');

    function syncSliders() {
      const min = parseInt($sliderMin.val());
      const max = parseInt($sliderMax.val());
      if (min > max) { $sliderMin.val(max); return; }
      $inputMin.val(min);
      $inputMax.val(max);
      const total = parseInt($sliderMax.attr('max')) - parseInt($sliderMin.attr('min'));
      const pctL  = (min - parseInt($sliderMin.attr('min'))) / total * 100;
      const pctR  = (max - parseInt($sliderMin.attr('min'))) / total * 100;
      $('#price-range-fill').css({ left: pctL + '%', width: (pctR - pctL) + '%' });
    }

    $sliderMin.on('input', syncSliders);
    $sliderMax.on('input', syncSliders);
    syncSliders();

    // Ver mais marcas
    $(document).on('click', '.btn-show-more', function () {
      const $list  = $('#' + $(this).data('target'));
      $list.find('.filter-check').show();
      $(this).hide();
    });
    // Esconde marcas acima de 6 inicialmente
    $('#brands-list .filter-check:nth-child(n+7)').hide();
  }

  function calcShipping() {
    // Pré-preenche com o CEP ativo se o campo estiver vazio
    const $cepInput = $('#shipping-cep');
    if (!$cepInput.val() && window.EC_CEP_ATIVO) {
      const cep = window.EC_CEP_ATIVO;
      const fmt = cep.substring(0, 5) + '-' + cep.substring(5);
      $cepInput.val(fmt);
    }

    const cep = $cepInput.val().replace(/\D/g, '');
    // ... restante da função existente
  }


  
});

$(function () {
  if (typeof window.PV === 'undefined') return;

  const PV             = window.PV;
  const tiposOrdenados = PV.tipos_slug  || [];
  const legadoMap      = PV.legado_map  || {};
  const selecoes       = {};
  let   skuAtual       = null;

  // ── Pré-seleciona variante da URL ────────────────────────
  if (PV.variant_pre) {
    const pre = PV.variant_pre;

    // Marca os botões como ativos
    Object.entries(pre.atributos).forEach(([tipo, valor]) => {
      selecoes[tipo] = String(valor);
      const $btn = $(`.variation-swatch--variacao[data-tipo="${tipo}"][data-valor="${valor}"]`);
      $btn.addClass('active');
      $(`#label-${tipo}`).text(valor);
    });

    // Atualiza UI com os dados já disponíveis
    aplicarSkuNaUI({
      preco_fmt  : pre.preco_fmt,
      estoque    : pre.estoque,
      sem_estoque: pre.sem_estoque,
    });
  }

  // ── Clique em variação (tamanho, voltagem...) ────────────
  $(document).on('click', '.variation-swatch--variacao:not(:disabled):not(.sem-estoque)', function () {
    const $btn  = $(this);
    const tipo  = String($btn.data('tipo'));
    const valor = String($btn.data('valor'));

    // Visual
    $(`.variation-swatch--variacao[data-tipo="${tipo}"]`).removeClass('active');
    $btn.addClass('active');
    $(`#label-${tipo}`).text(valor);

    selecoes[tipo] = valor;

    resolverSku();
  });

  // ── Clique em agrupador (cor, estampa...) ────────────────
  // Navega para outro produto via pushState
  $(document).on('click', '.variation-swatch--agrupador', function (e) {
    e.preventDefault();

    const href     = $(this).attr('href');
    const produtoId = $(this).data('produto-id');

    // Se já é o produto atual, ignora
    if (parseInt(produtoId) === parseInt(PV.produto_id)) return;

    // Navega para o produto da cor (com reload pois é outro produto)
    window.location.href = href;
  });

  // ── Resolve SKU pela combinação atual ────────────────────
  
  // ── Aplica os dados do SKU na UI ─────────────────────────
  function aplicarSkuNaUI(sku) {
    // Preço
    mostrarPrecoSku(sku.preco_fmt, sku.sem_estoque);

    // Botão de comprar
    const $btn = $('#btn-comprar, .btn-add-cart-detail').first();
    if (!$btn.length) return;

    if (sku.sem_estoque) {
      $btn.prop('disabled', true)
          .text('Sem estoque')
          .removeClass('btn-primary')
          .addClass('btn-disabled');
    } else {
      $btn.prop('disabled', false)
          .text('Adicionar ao carrinho')
          .removeClass('btn-disabled')
          .addClass('btn-primary');

      if (sku.sku_id) {
        $btn.attr('data-sku-id', sku.sku_id).data('sku-id', sku.sku_id);
      }
    }
  }

  // Handler unificado para adicionar ao carrinho da página de produto
  $(document).on('click', '#btn-comprar, .btn-add-cart-detail', function (e) {
      e.preventDefault();

      const $btn      = $(this);
      const produtoId = parseInt($btn.data('product-id') || $btn.attr('data-product-id'));

      // Lê o sku_id de ambas as fontes (jQuery data e atributo HTML)
      const skuId     = parseInt($btn.data('sku-id') || $btn.attr('data-sku-id') || 0);

      console.log('[Comprar] produto_id:', produtoId, '| sku_id:', skuId);

      if (!produtoId) return;

      // Verifica se tem variações obrigatórias não selecionadas
      if (typeof window.PV !== 'undefined' && PV.tipos_slug && PV.tipos_slug.length > 0) {
          if (!skuId) {
              mostrarAviso('Selecione todas as opções antes de adicionar ao carrinho.');
              // Destaca os seletores sem seleção
              $('.variation-swatch--variacao').closest('.variation-group')
                  .filter(function () {
                      return !$(this).find('.variation-swatch--variacao.active').length;
                  })
                  .addClass('variation-group--alerta');

              setTimeout(function () {
                  $('.variation-group--alerta').removeClass('variation-group--alerta');
              }, 2000);
              return;
          }
      }

      $btn.prop('disabled', true).text('Adicionando...');

      const dados = {
          produto_id  : produtoId,
          quantidade  : 1,
          _csrf_token : CSRF_TOKEN,
      };

      if (skuId > 0) {
          dados.sku_id = skuId;
      }

      console.log('[Comprar] Enviando:', dados);

      $.post(BASE_URL + '/carrinho/adicionar', dados, function (res) {
          console.log('[Comprar] Resposta:', res);

          $btn.prop('disabled', false).text('Adicionar ao carrinho');

          if (!res.ok) {
              mostrarAviso(res.msg || 'Erro ao adicionar.');
              return;
          }

          // Atualiza badge
          if (res.count !== undefined) {
              $('#cart-count, #mc-badge').text(res.count).show();
          }

          // Abre mini cart
          if (typeof abrirMiniCart === 'function') abrirMiniCart();

          if (typeof showToast === 'function') {
              showToast('Produto adicionado ao carrinho!', 'success');
          }

      }, 'json').fail(function (xhr) {
          console.error('[Comprar] Erro:', xhr.status, xhr.responseText);
          $btn.prop('disabled', false).text('Adicionar ao carrinho');
      });
  });

  // Substituir a função atualizarURL
  // Substituir a função aplicarSkuNaUI e adicionar restaurarPrecoBase

  function aplicarSkuNaUI(sku) {
    $('#price-range-wrapper').hide();
    $('#sku-preco-wrapper').show();

    // Preço principal
    $('#sku-preco-valor').text(sku.preco_fmt);

    // Preço original riscado (se tiver promo)
    if (sku.preco_original_fmt) {
        $('#sku-preco-original').text(sku.preco_original_fmt).show();
    } else {
        $('#sku-preco-original').hide();
    }

    // Parcelamento calculado no JS a partir do preço do SKU
    const parcela = calcularParcela(sku.preco);
    if (parcela) {
        $('#sku-preco-parcela')
            .text('ou ' + parcela.vezes + 'x de ' + parcela.valorFmt + ' sem juros')
            .show();
    } else {
        $('#sku-preco-parcela').hide();
    }

    // Botão de comprar
    const $btn = $('#btn-comprar, .btn-add-cart-detail').first();
    if (!$btn.length) return;

    if (sku.sem_estoque) {
        $btn.prop('disabled', true)
            .text('Sem estoque')
            .removeClass('btn-primary')
            .addClass('btn-disabled');
    } else {
        $btn.prop('disabled', false)
            .text('Adicionar ao carrinho')
            .removeClass('btn-disabled')
            .addClass('btn-primary')
            .attr('data-sku-id', sku.sku_id)
            .data('sku-id', sku.sku_id);
    }
  }

  function restaurarPrecoBase() {
      $('#sku-preco-wrapper').hide();
      $('#price-range-wrapper').show();
  }

  // Calcula o parcelamento no JS com as mesmas regras do PHP
  function calcularParcela(preco) {
      // Ajuste os valores conforme suas regras de negócio
      const MAX_PARCELAS  = 10;
      const VALOR_MINIMO  = 10.00; // parcela mínima
      const SEM_JUROS     = true;

      if (!preco || preco <= 0) return null;

      let melhorParcela = null;

      for (let n = MAX_PARCELAS; n >= 2; n--) {
          const valor = preco / n;
          if (valor >= VALOR_MINIMO) {
              melhorParcela = {
                  vezes   : n,
                  valor   : valor,
                  valorFmt: 'R$ ' + valor.toFixed(2)
                      .replace('.', ',')
                      .replace(/\B(?=(\d{3})+(?!\d))/g, '.'),
              };
              break;
          }
      }

      return melhorParcela;
  }

  function restaurarPrecoBase() {
      $('#sku-preco-wrapper').hide();
      $('#price-range-wrapper').show();
  }

  // Na função resolverSku, chamar restaurarPrecoBase quando faltam seleções:
  function resolverSku() {
      const faltando = tiposOrdenados.filter(t => !selecoes[t]);
      atualizarBotoesDisponiveis();

      if (faltando.length > 0) {
          restaurarPrecoBase(); // ← volta para o range/preço base
          esconderAviso();
          return;
      }

      const chave = tiposOrdenados.map(t => selecoes[t]).join('|');
      const sku   = PV.matriz[chave];

      if (!sku) {
          mostrarAviso('Combinação não disponível.');
          restaurarPrecoBase();
          return;
      }

      esconderAviso();
      skuAtual = sku;
      aplicarSkuNaUI(sku);
      atualizarURL(chave);
  }
  function atualizarURL(chave) {
      if (!window.history || !window.history.pushState) return;

      // Usa chave_map para pegar o identifier (id_legado ou sku_id)
      const identifier = PV.chave_map && PV.chave_map[chave]
                        ? PV.chave_map[chave]
                        : null;

      if (!identifier) {
          console.warn('[URL] Identifier não encontrado para chave:', chave);
          return;
      }

      const url = new URL(window.location.href);
      url.searchParams.set('variant_id', identifier);

      window.history.pushState(
          { sku: skuAtual, selecoes: Object.assign({}, selecoes) },
          document.title,
          url.toString()
      );

      console.log('[URL] Atualizada para:', url.toString());
  }

  // E na função resolverSku, passar a chave diretamente:
  function resolverSku() {
      const faltando = tiposOrdenados.filter(t => !selecoes[t]);
      atualizarBotoesDisponiveis();

      if (faltando.length > 0) {
          esconderPrecoSku();
          return;
      }

      const chave = tiposOrdenados.map(t => selecoes[t]).join('|');
      const sku   = PV.matriz[chave];

      if (!sku) {
          mostrarAviso('Combinação não disponível.');
          esconderPrecoSku();
          return;
      }

      esconderAviso();
      skuAtual = sku;
      aplicarSkuNaUI(sku);
      atualizarURL(chave); // ← passa a chave, não mais o id_legado
  }

  // ── Botão voltar/avançar do browser ─────────────────────
  window.addEventListener('popstate', function (e) {
    if (!e.state) return;

    // Restaura as seleções do estado anterior
    const { selecoes: sel } = e.state;
    if (!sel) return;

    // Limpa seleções atuais
    Object.keys(selecoes).forEach(k => delete selecoes[k]);
    $('.variation-swatch--variacao').removeClass('active');

    // Aplica o estado anterior
    Object.entries(sel).forEach(([tipo, valor]) => {
      selecoes[tipo] = valor;
      const $btn = $(`.variation-swatch--variacao[data-tipo="${tipo}"][data-valor="${valor}"]`);
      $btn.addClass('active');
      $(`#label-${tipo}`).text(valor);
    });

    resolverSku();
  });

  // ── Disponibilidade dos botões ────────────────────────────
  function atualizarBotoesDisponiveis() {
    tiposOrdenados.forEach(function (tipoAtual) {
      $(`.variation-swatch--variacao[data-tipo="${tipoAtual}"]`).each(function () {
        const $btn     = $(this);
        const valorBtn = String($btn.data('valor'));

        const outrosSelecionados = tiposOrdenados
          .filter(t => t !== tipoAtual)
          .every(t => selecoes[t]);

        if (!outrosSelecionados) return;

        const chaveSimulada = tiposOrdenados
          .map(t => t === tipoAtual ? valorBtn : selecoes[t])
          .join('|');

        const skuSim      = PV.matriz[chaveSimulada];
        const indisponivel = !skuSim || skuSim.sem_estoque;

        $btn.toggleClass('sem-estoque', indisponivel && !$btn.hasClass('active'))
            .prop('disabled', indisponivel && !$btn.hasClass('active'));
      });
    });
  }

  // ── Helpers UI ────────────────────────────────────────────
  function mostrarPrecoSku(precoFmt, semEstoque) {
    $('#sku-preco-valor').text(precoFmt);
    $('#sku-preco-wrapper').show();
    $('#sku-preco-valor').toggleClass('sku-preco--sem-estoque', semEstoque);
  }

  function esconderPrecoSku() {
    $('#sku-preco-wrapper').hide();
  }

  function mostrarAviso(msg) {
    $('#variacao-aviso').text(msg).show();
  }

  function esconderAviso() {
    $('#variacao-aviso').hide();
  }

  // Hover no label do agrupador
  $(document).on('mouseenter', '.variation-swatch--agrupador', function () {
    $(this).closest('.variation-group')
           .find('.variation-valor-atual')
           .text($(this).data('valor'));
  }).on('mouseleave', '.variation-swatch--agrupador', function () {
    const $grp = $(this).closest('.variation-group');
    $grp.find('.variation-valor-atual')
        .text($grp.find('.active').data('valor') || '');
  });

  // Inicializa
  atualizarBotoesDisponiveis();

// ── Wishlist dropdown na página do produto ────────────────
  (function () {
    console.log('TESTING');
    
    if (!$('#btn-wishlist-toggle').length) return;

    const produtoId = $('#btn-wishlist-toggle').data('product-id');
    let   listasCarregadas = false;

    // ── Abre o dropdown ──────────────────────────────────
    $(document).on('click', '#btn-wishlist-toggle', function (e) {
      e.stopPropagation();
      const $dd = $('#wishlist-dropdown');

      if ($dd.is(':visible')) {
        $dd.slideUp(150);
        return;
      }

      $dd.slideDown(180);

      // Carrega as listas (só na primeira vez)
      if (!listasCarregadas) carregarListas();
    });

    // Fecha ao clicar fora
    $(document).on('click', function (e) {
      if (!$(e.target).closest('#wishlist-btn-wrap').length) {
        $('#wishlist-dropdown').slideUp(150);
        fecharNovaListaForm();
      }
    });
    $(document).on('click', '#btn-wishlist-close', function () {
      $('#wishlist-dropdown').slideUp(150);
    });

    // ── Carrega as listas do cliente ─────────────────────
    
    // Substituir a função carregarListas() no product.js

    function carregarListas() {
        // Usa dados já carregados pelo PHP (sem Ajax extra)
        if (typeof window.WISHLIST_LISTAS !== 'undefined') {
            if (!window.WISHLIST_LISTAS.length) {
                $('#wishlist-listas').html(
                    '<p class="wishlist-vazia">Nenhuma lista ainda. Crie uma abaixo!</p>'
                );
            }
            // HTML já foi renderizado pelo PHP, só marca como carregado
            listasCarregadas = true;
            return;
        }

        // Fallback: carrega via Ajax se não tiver no PHP
        $('#wishlist-listas').html('<div class="wishlist-loading">Carregando...</div>');

        $.get(BASE_URL + '/favoritos/verificar', { produto_id: produtoId }, function (res) {
            listasCarregadas = true;

            if (res.nao_logado) {
                $('#wishlist-listas').html(`
                  <div class="wishlist-login-aviso">
                    <p>${res.msg}</p>
                    <a href="${BASE_URL}/login?redirect=${encodeURIComponent(window.location.pathname)}"
                      class="btn btn-primary btn-sm btn-full">Fazer login</a>
                  </div>
                `);
                $('#btn-nova-lista').hide();
                return;
            }

            renderizarListas(res.listas);
        }, 'json');
    }

    function renderizarListas(listas) {
      if (!listas.length) {
        $('#wishlist-listas').html(
          '<p class="wishlist-vazia">Nenhuma lista ainda. Crie uma abaixo!</p>'
        );
        return;
      }

      let html = '';
      listas.forEach(function (lista) {
        const tem    = lista.tem_produto;
        html += `
          <label class="wishlist-lista-item ${tem ? 'wishlist-lista-item--ativa' : ''}"
                data-lista-id="${lista.id}">
            <input type="checkbox"
                  class="wishlist-lista-check"
                  data-lista-id="${lista.id}"
                  ${tem ? 'checked' : ''}>
            <span class="wishlist-check-custom"></span>
            <span class="wishlist-lista-nome">${lista.nome}</span>
            ${tem
              ? '<span class="wishlist-lista-badge">Salvo</span>'
              : ''}
          </label>`;
      });
      $('#wishlist-listas').html(html);
    }

    function renderItemBody(id, block_list = false) {
      // console.log(id);
      console.log(id);
        //block_list é para evitar que quando clicado na lista, ele vai tentar marcar na lista novamente, pois isso já é feito diretamente no click da checkbox, então nesse caso ele só atualiza o botão principal e o dropdown, sem tentar marcar/desmarcar nada
        $('body').find('.wishlist-control').each(function () {
            if ($(this).data('list-id') == id) {
                
              // Atualiza visual
              $(this).toggleClass('active');
              const ativo = $(this).hasClass('active');
              // Atualiza SVG fill
              $(this).find('svg').attr('fill', ativo ? 'currentColor' : 'none');

              if($(this).hasClass('hab-text')){
                // Atualiza label (se existir)
                $(this).find('.btn-favorito-label').text(
                    ativo ? 'Nos seus favoritos' : 'Favoritar'
                );          
                // Atualiza aria-label
                $(this).attr('aria-label',
                    ativo ? 'Remover dos favoritos' : 'Adicionar aos favoritos'
                );
              } 
            }
        });

        if(block_list) return;

        if($('body').find('.wishlist-btn-wrap').length > 0){
          $('body').find('.wishlist-btn-wrap .wishlist-lista-item').each(function () {
            if ($(this).data('lista-id') == id) {
              let check = $(this).find('.wishlist-lista-check');
              let marcar = check.is(':checked');

              console.log(marcar);
              

              const $item = $(this);
              if (!marcar) {
                check.prop('checked', true);
                $item.addClass('wishlist-lista-item--ativa');
                if (!$item.find('.wishlist-lista-badge').length) {
                  $item.append('<span class="wishlist-lista-badge">Salvo</span>');
                  check
                }
              } else {
                check.prop('checked', false);
                $item.removeClass('wishlist-lista-item--ativa')
                    .find('.wishlist-lista-badge').remove();
              }
            }
          });
        }
    }

    // ── Marcar/desmarcar lista ────────────────────────────
    $(document).on('change', '.wishlist-lista-check', function () {
      const $cb     = $(this);
      const listaId = $cb.data('lista-id');
      const marcar  = $cb.is(':checked');
      const url     = marcar
        ? BASE_URL + '/favoritos/item/adicionar'
        : BASE_URL + '/favoritos/item/remover';

      const dados = { _csrf_token: CSRF_TOKEN };
      if (marcar) {
        dados.lista_id   = listaId;
        dados.produto_id = produtoId;
      } else {
        // Para remover, precisa do item_id
        // Busca o item_id via Ajax
        $.get(BASE_URL + '/favoritos/itens', { lista_id: listaId }, function (res) {
          if (!res.ok) return;
          const item = res.itens.find(i => i.produto_id == produtoId);
          if (!item) return;

          $.post(BASE_URL + '/favoritos/item/remover', {
            item_id     : item.item_id,
            _csrf_token : CSRF_TOKEN,
          }, function (r) {
            if (r.ok) {
              $cb.closest('.wishlist-lista-item')
                .removeClass('wishlist-lista-item--ativa')
                .find('.wishlist-lista-badge').remove();
              showToast(r.msg, 'info');
              atualizarBotaoPrincipal();
              renderItemBody(listaId, true)
            }
          }, 'json');
        }, 'json');
        return;
      }

      $.post(url, dados, function (res) {
        if (res.nao_logado) {
          showToast(res.msg, 'info');
          $cb.prop('checked', false);
          return;
        }

        if (!res.ok) {
          showToast(res.msg, 'error');
          $cb.prop('checked', !marcar);
          return;
        }

        const $item = $cb.closest('.wishlist-lista-item');
        if (marcar) {
          $item.addClass('wishlist-lista-item--ativa');
          if (!$item.find('.wishlist-lista-badge').length) {
            $item.append('<span class="wishlist-lista-badge">Salvo</span>');
          }
        } else {
          $item.removeClass('wishlist-lista-item--ativa')
              .find('.wishlist-lista-badge').remove();
        }

        showToast(res.msg, marcar ? 'success' : 'info');
        atualizarBotaoPrincipal();
        renderItemBody(listaId, true)
      }, 'json');
    });

    function atualizarBotaoPrincipal() {
      return false;
      const temAlgum = $('.wishlist-lista-check:checked').length > 0;
      const $btn     = $('#btn-wishlist-toggle');

      if (temAlgum) {
        $btn.addClass('wishlist-btn--ativa');
        $('#wishlist-btn-label').text('Salvo nos favoritos');
        $btn.find('svg').first().css('fill', 'var(--c-primary)');
      } else {
        $btn.removeClass('wishlist-btn--ativa');
        $('#wishlist-btn-label').text('Adicionar à lista');
        $btn.find('svg').first().css('fill', 'none');
      }
    }

    // ── Criar nova lista inline ───────────────────────────
    $(document).on('click', '#btn-nova-lista', function () {
      $(this).hide();
      $('#wishlist-nova-form').slideDown(180);
      setTimeout(() => $('#wishlist-nova-nome').focus(), 100);
    });

    $(document).on('click', '#btn-nova-lista-cancelar', function () {
      fecharNovaListaForm();
    });

    function fecharNovaListaForm() {
      $('#wishlist-nova-form').slideUp(150);
      $('#btn-nova-lista').show();
      $('#wishlist-nova-nome').val('');
    }

    $(document).on('click', '#btn-nova-lista-salvar', function () {
      const nome = $('#wishlist-nova-nome').val().trim();
      if (!nome) {
        $('#wishlist-nova-nome').focus();
        return;
      }

      $(this).prop('disabled', true).text('...');

      $.post(BASE_URL + '/favoritos/criar', {
        nome        : nome,
        _csrf_token : CSRF_TOKEN,
      }, function (res) {
        $('#btn-nova-lista-salvar').prop('disabled', false).text('Criar');

        if (!res.ok) {
          showToast(res.msg, 'error');
          return;
        }

        // Adiciona o produto na lista recém criada
        $.post(BASE_URL + '/favoritos/item/adicionar', {
          lista_id    : res.lista_id,
          produto_id  : produtoId,
          _csrf_token : CSRF_TOKEN,
        }, function () {
          fecharNovaListaForm();
          listasCarregadas = false; // força reload das listas
          carregarListas();
          showToast('Lista "' + res.nome + '" criada e produto adicionado!', 'success');
          atualizarBotaoPrincipal();
        }, 'json');
      }, 'json');
    });

    // Enter no input de nova lista
    $(document).on('keydown', '#wishlist-nova-nome', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        $('#btn-nova-lista-salvar').trigger('click');
      }
    });

    $(document).on('click', '.btn-favorito', function (e) {
      e.preventDefault();
      e.stopPropagation();

      if($('.product-page').length <= 0) {
        return false; // Deixa o comportamento normal do botão na página de produto
      }

      const $btn      = $(this);
      const produtoId = $btn.data('product-id');
      const list_id   = $btn.data('list-id');
      const isActive  = $btn.hasClass('active');
      const url       = isActive
                        ? BASE_URL + '/favoritos/desfavoritar'
                        : BASE_URL + '/favoritos/favoritar';

      $btn.prop('disabled', true);

      $.post(url, {
          produto_id  : produtoId,
          _csrf_token : CSRF_TOKEN,
      }, function (res) {
          $btn.prop('disabled', false);

          // Não logado
          if (res.nao_logado) {
              showToast(res.msg, 'info', {
                  label : 'Fazer login',
                  url   : BASE_URL + '/login?redirect='
                          + encodeURIComponent(window.location.pathname),
              });
              return;
          }

          if (!res.ok) {
              showToast(res.msg, 'error');
              return;
          }

          renderItemBody(list_id)

          // // Atualiza visual
          // $btn.toggleClass('active');
          const ativo = $btn.hasClass('active');

          // // Atualiza SVG fill
          // $btn.find('svg').attr('fill', ativo ? 'currentColor' : 'none');

          // // Atualiza label (se existir)
          // $btn.find('.btn-favorito-label').text(
          //     ativo ? 'Nos seus favoritos' : 'Favoritar'
          // );          

          // // Atualiza aria-label
          // $btn.attr('aria-label',
          //     ativo ? 'Remover dos favoritos' : 'Adicionar aos favoritos'
          // );

          showToast(res.msg, ativo ? 'success' : 'info');

      }, 'json').fail(function () {
          $btn.prop('disabled', false);
          showToast('Erro de conexão.', 'error');
      });
  });

  })();  
});


