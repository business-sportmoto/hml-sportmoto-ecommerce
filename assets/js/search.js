// assets/js/search.js — substitua o arquivo completo

$(function () {

  // ── Dropdown de categorias ───────────────────────────────
  const $catBtn      = $('#btn-search-cat');
  const $catDropdown = $('#search-cat-dropdown');
  const $catLabel    = $('#search-cat-label');
  const $catValue    = $('#search-cat-value');
  const $searchInput = $('#search-input');

  // Abre/fecha dropdown de categorias
  $catBtn.on('click', function (e) {
    e.stopPropagation();
    const isOpen = $catDropdown.hasClass('open');
    closeAllDropdowns();
    if (!isOpen) {
      $catDropdown.addClass('open');
      $catBtn.attr('aria-expanded', 'true');
      $catBtn.find('.search-cat-chevron').css('transform', 'rotate(180deg)');
    }
  });

  // Seleciona uma categoria
  $(document).on('click', '.search-cat-option', function () {
    const id   = $(this).data('id');
    const nome = $(this).data('nome');

    // Atualiza label e valor
    $catLabel.text(nome);
    $catValue.val(id);

    // Atualiza placeholder do input
    $searchInput.attr('placeholder',
      id ? `Buscar em ${nome}...` : 'Buscar produtos, marcas e categorias...'
    );

    // Marca como selecionado
    $('.search-cat-option').removeClass('active').attr('aria-selected', 'false');
    $(this).addClass('active').attr('aria-selected', 'true');

    closeAllDropdowns();
    $searchInput.focus();
  });

  function closeAllDropdowns() {
    $catDropdown.removeClass('open');
    $catBtn.attr('aria-expanded', 'false');
    $catBtn.find('.search-cat-chevron').css('transform', 'rotate(0deg)');
  }

  // Fecha ao clicar fora
  $(document).on('click', function (e) {
    if (!$(e.target).closest('#search-cat-selector').length) {
      closeAllDropdowns();
    }
  });

  // Fecha com ESC
  $(document).on('keydown', function (e) {
    if (e.key === 'Escape') closeAllDropdowns();
  });

  // Navegar com teclado no dropdown
  $catBtn.on('keydown', function (e) {
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      $catDropdown.addClass('open');
      $catBtn.attr('aria-expanded', 'true');
      $('.search-cat-option').first().focus();
    }
  });

  $(document).on('keydown', '.search-cat-option', function (e) {
    const $options = $('.search-cat-option');
    const idx      = $options.index(this);

    if (e.key === 'ArrowDown') {
      e.preventDefault();
      $options.eq(idx + 1).focus();
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      if (idx === 0) $catBtn.focus();
      else $options.eq(idx - 1).focus();
    } else if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      $(this).trigger('click');
    } else if (e.key === 'Escape') {
      closeAllDropdowns();
      $catBtn.focus();
    }
  });

  // ── Autocomplete com suporte a categoria ─────────────────
  let searchTimer;
  let activeIndex = -1;

  $searchInput.on('input', function () {
    const q      = $(this).val().trim();
    const catId  = $catValue.val();
    clearTimeout(searchTimer);
    activeIndex = -1;

    if (q.length < 2) { hideAutocomplete(); return; }

    searchTimer = setTimeout(function () {
      $.get(BASE_URL + '/busca/autocomplete', { q, categoria_id: catId }, function (res) {
        if (!res.ok || !res.items.length) { hideAutocomplete(); return; }

        const $list = $('<ul class="ac-list" role="listbox">');

        // Cabeçalho mostrando em qual categoria está buscando
        if (catId && $catLabel.text() !== 'Todas') {
          $list.append(`
            <li class="ac-category-header">
              Buscando em <strong>${$catLabel.text()}</strong>
              <button type="button" class="ac-clear-cat">Buscar em tudo</button>
            </li>`);
        }

        res.items.forEach(function (item) {
          const promo = item.preco_promo && parseFloat(item.preco_promo) < parseFloat(item.preco);
          const priceHtml = promo
            ? `<span class="ac-price-orig">${item.preco_fmt}</span>
               <span class="ac-price ac-price--sale">${item.preco_promo_fmt}</span>`
            : `<span class="ac-price">${item.preco_fmt}</span>`;

          $list.append(`
            <li class="ac-item" role="option">
              <a href="${BASE_URL}/produto/${item.slug}" class="ac-link">
                <img src="${item.imagem || UPLOAD_URL + '/placeholder.jpg'}"
                     alt="${item.nome}" width="44" height="44" loading="lazy">
                <div class="ac-info">
                  <span class="ac-name">${item.nome}</span>
                  <span class="ac-cat">${item.categoria || ''}</span>
                </div>
                <div class="ac-pricing">${priceHtml}</div>
              </a>
            </li>`);
        });

        // Categorias sugeridas (apenas se buscando em todas)
        if (!catId && res.categorias && res.categorias.length) {
          $list.append('<li class="ac-section-label">Categorias</li>');
          res.categorias.forEach(function (cat) {
            $list.append(`
              <li class="ac-item ac-item--cat" role="option">
                <a href="${BASE_URL}/categoria/${cat.slug}" class="ac-link">
                  <div class="ac-cat-icon">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round">
                      <path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/>
                    </svg>
                  </div>
                  <div class="ac-info">
                    <span class="ac-name">${cat.nome}</span>
                    <span class="ac-cat">Ver categoria</span>
                  </div>
                </a>
              </li>`);
          });
        }

        // Rodapé "ver todos"
        const url = catId
          ? `${BASE_URL}/busca?q=${encodeURIComponent(q)}&categoria_id=${catId}`
          : `${BASE_URL}/busca?q=${encodeURIComponent(q)}`;

        $list.append(`
          <li class="ac-see-all">
            <a href="${url}">
              Ver todos os resultados para "<strong>${q}</strong>"
              ${catId ? ` em <em>${$catLabel.text()}</em>` : ''}
            </a>
          </li>`);

        $('#search-autocomplete').html($list).show();
        closeAllDropdowns(); // Fecha dropdown de categoria ao digitar
      }, 'json');
    }, 350);
  });

  // Limpar categoria direto no autocomplete
  $(document).on('click', '.ac-clear-cat', function (e) {
    e.preventDefault();
    e.stopPropagation();
    // Reseta para "Todas"
    $catLabel.text('Todas');
    $catValue.val('');
    $searchInput.attr('placeholder', 'Buscar produtos, marcas e categorias...');
    $('.search-cat-option').removeClass('active').attr('aria-selected', 'false');
    $('.search-cat-option[data-id=""]').addClass('active').attr('aria-selected', 'true');
    $searchInput.trigger('input'); // dispara nova busca
  });

  // Navegação por teclado no autocomplete
  $searchInput.on('keydown', function (e) {
    const $items = $('#search-autocomplete .ac-item');
    if (!$items.length) return;

    if (e.key === 'ArrowDown') {
      e.preventDefault();
      activeIndex = Math.min(activeIndex + 1, $items.length - 1);
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      activeIndex = Math.max(activeIndex - 1, -1);
    } else if (e.key === 'Escape') {
      hideAutocomplete(); return;
    } else if (e.key === 'Enter' && activeIndex >= 0) {
      e.preventDefault();
      $items.eq(activeIndex).find('a')[0].click();
      return;
    } else {
      return;
    }

    $items.removeClass('active');
    if (activeIndex >= 0) {
      $items.eq(activeIndex).addClass('active');
      $searchInput.val($items.eq(activeIndex).find('.ac-name').text());
    }
  });

  function hideAutocomplete() {
    $('#search-autocomplete').hide().empty();
    activeIndex = -1;
  }

  // Fecha autocomplete ao clicar fora
  $(document).on('click', function (e) {
    if (!$(e.target).closest('#header-search').length) {
      hideAutocomplete();
    }
  });

  $searchInput.on('focus', function () {
    if ($('#search-autocomplete').children().length) {
      $('#search-autocomplete').show();
    }
  });

});