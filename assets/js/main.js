// assets/js/main.js
// Inicializações e comportamentos globais da loja.
(function(){
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

  $(function () {
    
    // ── Header inteligente (sticky + hide on scroll down) ────
    const $header  = $('#site-header');
    const $spacer  = $('#header-spacer');
    let lastScroll = 0;
    let headerH    = $header.outerHeight(true);

    function updateSpacer() {
      headerH = $header.outerHeight(true);
      $spacer.css('height', headerH);
    }
    updateSpacer();
    $(window).on('resize', updateSpacer);

    $(window).on('scroll', function () {
      const current = $(this).scrollTop();
      if (current > 80) {
        $header.addClass('header--scrolled');
      } else {
        $header.removeClass('header--scrolled header--hidden');
      }
      if (current > lastScroll && current > 200) {
        $header.addClass('header--hidden');
      } else {
        $header.removeClass('header--hidden');
      }
      lastScroll = current;
    });

    // ── Menu mobile ──────────────────────────────────────────
    const $mobileMenu = $('#mobile-menu');
    const $overlay    = $('#overlay-mobile');

    function openMobileMenu() {
      $mobileMenu.addClass('open').attr('aria-hidden', 'false');
      $overlay.addClass('visible');
      $('body').addClass('no-scroll');
    }
    function closeMobileMenu() {
      $mobileMenu.removeClass('open').attr('aria-hidden', 'true');
      $overlay.removeClass('visible');
      $('body').removeClass('no-scroll');
    }

    $('#btn-menu-mobile').on('click', openMobileMenu);
    $('#btn-close-mobile').on('click', closeMobileMenu);
    $overlay.on('click', function () { closeMobileMenu(); closeMiniCart(); });

    // Accordion de subcategorias no menu mobile
    $('.mobile-nav-item.has-children > a').on('click', function (e) {
      const $item = $(this).parent();
      if ($item.find('.mobile-nav-sub').length) {
        e.preventDefault();
        $item.toggleClass('open');
        $item.find('.mobile-nav-sub').slideToggle(200);
      }
    });

    

    // ── Mini carrinho ────────────────────────────────────────
    // const $cartDrawer = $('#mini-cart-drawer');

    // function openMiniCart() {
    //   $cartDrawer.addClass('open');
    //   $overlay.addClass('visible');
    //   loadMiniCart();
    // }
    // function closeMiniCart() {
    //   $cartDrawer.removeClass('open');
    //   if (!$mobileMenu.hasClass('open')) {
    //     $overlay.removeClass('visible');
    //   }
    // }

    // $('#btn-open-cart').on('click', openMiniCart);
    // $('#btn-close-cart').on('click', closeMiniCart);

    function loadMiniCart() {
      $.get(BASE_URL + '/carrinho/mini', function (res) {
        if (!res.ok) return;
        const $body   = $('#mc-body');
        const $footer = $('#mc-footer');

        if (!res.items || res.items.length === 0) {
          $body.html('<div class="mini-cart-empty">Seu carrinho está vazio.</div>');
          $footer.hide();
          return;
        }

        let html = '<ul class="mini-cart-items">';
        res.items.forEach(function (item) {
          html += `
            <li class="mini-cart-item">
              <img src="${item.imagem}" alt="${item.nome}" width="60" height="60">
              <div class="mci-info">
                <a href="${BASE_URL}/produto/${item.slug}">${item.nome}</a>
                <span class="mci-opts">${item.opcoes || ''}</span>
                <div class="mci-qty-price">
                  <span class="mci-qty">${item.quantidade}x</span>
                  <span class="mci-price">${item.preco_fmt}</span>
                </div>
              </div>
              <button class="mci-remove btn-remove-item" data-item-id="${item.id}" aria-label="Remover">×</button>
            </li>`;
        });
        html += '</ul>';

        $body.html(html);
        $('#mc-subtotal').text(res.subtotal_fmt);
        $('#mc-count').text(res.total_itens);
        $footer.show();
      }).fail(function () {
        $('#mc-body').html('<div class="mini-cart-empty">Não foi possível carregar o carrinho.</div>');
      });
    }

    $(document).on('click', '.mci-remove', function () {
      const itemId = $(this).data('item-id');
      $.post(BASE_URL + '/carrinho/remover', {
        item_id: itemId, _csrf_token: CSRF_TOKEN
      }, function (res) {
        if (res.ok) {
          updateCartCount(res.cart_count);
          loadMiniCart();
        }
      }, 'json');
    });

    // ── Atualiza badge do carrinho ───────────────────────────
    window.updateCartCount = function (count) {
      const $badge = $('#cart-count');
      $badge.text(count);
      count > 0 ? $badge.show() : $badge.hide();
      // Anima o badge
      $badge.addClass('bounce');
      setTimeout(() => $badge.removeClass('bounce'), 400);
    };

    // ── Hero Slider ──────────────────────────────────────────
    const $slides = $('.hero-slide');
    const $dots   = $('.hero-dot');
    let current   = 0;
    let autoplay;

    function goToSlide(n) {
      $slides.eq(current).removeClass('active');
      $dots.eq(current).removeClass('active');
      current = (n + $slides.length) % $slides.length;
      $slides.eq(current).addClass('active');
      $dots.eq(current).addClass('active');
    }

    if ($slides.length > 1) {
      autoplay = setInterval(() => goToSlide(current + 1), 5000);

      $('#hero-next').on('click', function () {
        clearInterval(autoplay);
        goToSlide(current + 1);
        autoplay = setInterval(() => goToSlide(current + 1), 5000);
      });
      $('#hero-prev').on('click', function () {
        clearInterval(autoplay);
        goToSlide(current - 1);
        autoplay = setInterval(() => goToSlide(current + 1), 5000);
      });
      $dots.on('click', function () {
        clearInterval(autoplay);
        goToSlide($(this).data('index'));
        autoplay = setInterval(() => goToSlide(current + 1), 5000);
      });

      // Touch/swipe no slider
      let touchStartX = 0;
      document.querySelector('#hero-slider')?.addEventListener('touchstart', e => {
        touchStartX = e.changedTouches[0].clientX;
      }, { passive: true });
      document.querySelector('#hero-slider')?.addEventListener('touchend', e => {
        const diff = touchStartX - e.changedTouches[0].clientX;
        if (Math.abs(diff) > 50) {
          clearInterval(autoplay);
          goToSlide(diff > 0 ? current + 1 : current - 1);
          autoplay = setInterval(() => goToSlide(current + 1), 5000);
        }
      });
    }

    // ── Slider de produtos (lancamentos, etc.) ────────────────
    $(document).on('click', '.slider-arrow', function () {
      const sliderId = $(this).data('slider');
      const $slider  = $('#' + sliderId);
      const cardW    = $slider.find('.product-card').outerWidth(true);
      const isPrev   = $(this).hasClass('slider-arrow--prev');
      $slider.animate({ scrollLeft: $slider.scrollLeft() + (isPrev ? -cardW * 2 : cardW * 2) }, 300);
    });

    // ── Toast de notificação ─────────────────────────────────
    window.showToast = function (msg, type = 'info', duration = 4000) {
      const id    = 'toast-' + Date.now();
      const icons = {
        success: '✓', error: '✕', info: 'ℹ', warning: '⚠'
      };
      const $toast = $(`
        <div id="${id}" class="toast toast--${type}" role="alert">
          <span class="toast-icon">${icons[type] || 'ℹ'}</span>
          <span class="toast-msg">${msg}</span>
          <button class="toast-close" aria-label="Fechar">×</button>
        </div>`);

      $('#toast-container').append($toast);
      requestAnimationFrame(() => $toast.addClass('visible'));

      const timer = setTimeout(() => dismissToast($toast), duration);

      $toast.find('.toast-close').on('click', function () {
        clearTimeout(timer);
        dismissToast($toast);
      });
    };

    function dismissToast($t) {
      $t.removeClass('visible');
      setTimeout(() => $t.remove(), 300);
    }

    // ── Flash messages do servidor → toast ───────────────────
    $('.flash-message').each(function () {
      const type = $(this).data('flash');
      const msg  = $(this).find('.flash-text').text();
      if (msg) notifyToast(msg, type);
      $(this).remove();
    });

    // ── Wishlist rápida (card de produto) ────────────────────
    // $(document).on('click', '.btn-wishlist', function () {
    //   const $btn       = $(this);
    //   const productId  = $btn.data('product-id');

    //   $.post(BASE_URL + '/minha-conta/favorito/adicionar', {
    //     produto_id: productId, _csrf_token: CSRF_TOKEN
    //   }, function (res) {
    //     if (res.ok) {
    //       $btn.addClass('active');
    //       notifyToast(res.msg || 'Adicionado aos favoritos!', 'success');
    //     } else if (res.redirect) {
    //       window.location.href = res.redirect;
    //     } else {
    //       notifyToast(res.msg || 'Erro ao adicionar.', 'error');
    //     }
    //   }, 'json');
    // });

    // ── FAVORITOS: toggle corrigido ──────────────────────────
    // Carrega estado inicial dos favoritos na página
    // function carregarEstadoFavoritos() {
    //   $('.btn-wishlist').each(function () {
    //     const productId = $(this).data('product-id');
    //     if (!productId) return;

    //     // Marca como ativo se tiver classe 'active' carregada do servidor
    //     // (quando vem do HTML gerado com estado)
    //     const $btn = $(this);
    //     if ($btn.hasClass('active')) {
    //       $btn.attr('aria-pressed', 'true').attr('title', 'Remover dos favoritos');
    //     }
    //   });
    // }

    // Verifica estado dos favoritos via Ajax (para cards do catálogo/home)
    // function verificarFavoritos() {
    //   const ids = [];
    //   $('.btn-wishlist').each(function () {
    //     const id = $(this).data('product-id');
    //     if (id) ids.push(id);
    //   });

      
    //   if (!ids.length) return;

    //   // Só verifica se estiver logado
    //   // if (!$('body').data('logado') && !document.cookie.includes('ec_remember')) return;

    //   console.log(ids);

    //   $.post(BASE_URL + '/minha-conta/favoritos/verificar', {
    //     ids:         ids.join(','),
    //     _csrf_token: CSRF_TOKEN
    //   }, function (res) {
    //     if (!res.ok) return;
    //     (res.favoritados || []).forEach(function (id) {
    //       $(`.btn-wishlist[data-product-id="${id}"]`)
    //         .addClass('active')
    //         .attr('aria-pressed', 'true')
    //         .attr('title', 'Remover dos favoritos');
    //     });
    //   }, 'json').fail(function () {
    //     // Silencia erro — usuário pode não estar logado
    //   });
    // }
    

  // ── Hero busca por moto ───────────────────────────────────
  (function () {
    const $mont  = $('#hbm-montadora');
    const $mod   = $('#hbm-modelo');
    const $ano   = $('#hbm-ano');
    const $btn   = $('#hbm-btn-buscar');
    const $form  = $('#form-hero-busca-moto');

    if (!$mont.length) return;

    // ── Montadora → carrega modelos ────────────────────────
    $mont.on('change', function () {
      const id = $(this).val();

      // Reset modelo e ano
      $mod.html('<option value="">Modelo</option>').prop('disabled', true);
      $ano.html('<option value="">Ano</option>').prop('disabled', true);
      $btn.prop('disabled', true);

      if (!id) return;

      $mod.html('<option value="">Carregando...</option>');

      $.get(BASE_URL + '/ajax/moto/modelos', { montadora_id: id }, function (modelos) {
        if (!modelos.length) {
          // Sem modelos → permite buscar só pela montadora
          $mod.html('<option value="">Todos os modelos</option>').prop('disabled', false);
          $btn.prop('disabled', false);
          return;
        }

        let opts = '<option value="">Selecione o modelo</option>';
        modelos.forEach(m => {
          opts += `<option value="${m.id}">${m.nome}</option>`;
        });
        $mod.html(opts).prop('disabled', false);
        $btn.prop('disabled', false);
      }, 'json').fail(function () {
        $mod.html('<option value="">Erro ao carregar</option>');
        $btn.prop('disabled', false);
      });
    });

    // ── Modelo → carrega anos ──────────────────────────────
    $mod.on('change', function () {
      const id = $(this).val();

      $ano.html('<option value="">Ano</option>').prop('disabled', true);

      if (!id) {
        $btn.prop('disabled', !$mont.val());
        return;
      }

      $ano.html('<option value="">Carregando...</option>');

      $.get(BASE_URL + '/ajax/moto/anos', { modelo_id: id }, function (anos) {
        if (!anos.length) {
          $ano.html('<option value="">Sem anos</option>').prop('disabled', true);
          return;
        }

        let opts = '<option value="">Todos os anos</option>';
        anos.forEach(a => {
          opts += `<option value="${a.ano}">${a.ano}</option>`;
        });
        $ano.html(opts).prop('disabled', false);
      }, 'json');

      $btn.prop('disabled', false);
    });

    // ── Submit: monta URL amigável ─────────────────────────
    $form.on('submit', function (e) {
      e.preventDefault();

      const montId  = $mont.val();
      const modId   = $mod.val();
      const ano     = $ano.val();

      if (!montId) return;

      // Pega slugs das options selecionadas
      const montSlug = $mont.find('option:selected').data('slug');

      if (!montSlug) {
        // Fallback por ID caso não tenha slug
        window.location.href = `${BASE_URL}/motos/buscar?montadora_id=${montId}&modelo_id=${modId}&ano=${ano}`;
        return;
      }

      // Tenta montar URL amigável se tiver modelo
      if (modId && modId !== '') {
        // Busca o slug do modelo via Ajax
        $.get(BASE_URL + '/ajax/moto/slug-modelo', { modelo_id: modId }, function (res) {
          if (res.slug) {
            let url = `${BASE_URL}/montadora/${montSlug}/${res.slug}`;
            if (ano) url += `-${ano}`;
            window.location.href = url;
          } else {
            window.location.href = `${BASE_URL}/montadora/${montSlug}`;
          }
        }, 'json').fail(function () {
          window.location.href = `${BASE_URL}/montadora/${montSlug}`;
        });
      } else {
        window.location.href = `${BASE_URL}/montadora/${montSlug}`;
      }
    });

    // ── Habilita botão se já tiver montadora selecionada ──
    if ($mont.val()) {
      $btn.prop('disabled', false);
    }

  })();

  // assets/js/main.js — append

  // ── Meu Veículo ───────────────────────────────────────────
  (function () {
    const bar       = document.getElementById('meu-veiculo-bar');
    const dropdown  = document.getElementById('mvb-dropdown');
    const semVeic   = document.getElementById('mvb-sem-veiculo');
    const comVeic   = document.getElementById('mvb-com-veiculo');
    const nomeEl    = document.getElementById('mvb-veiculo-nome');
    const dotEl     = document.getElementById('mvb-compat-dot');

    if (!bar) return;

    // ── Abre/fecha dropdown ──────────────────────────────
    function abrirDropdown()  { dropdown?.classList.add('open'); }
    function fecharDropdown() { dropdown?.classList.remove('open'); }

    document.getElementById('mvb-trigger-btn')
      ?.addEventListener('click', abrirDropdown);
    document.getElementById('mvb-trigger-btn-2')
      ?.addEventListener('click', abrirDropdown);
    document.getElementById('mvb-close-btn')
      ?.addEventListener('click', fecharDropdown);
    document.getElementById('prod-compat-trocar')
      ?.addEventListener('click', abrirDropdown);

    document.addEventListener('click', e => {
      if (bar && !bar.contains(e.target)) fecharDropdown();
    });

    // ── Cascata: montadora → modelos ─────────────────────
    document.getElementById('mvb-montadora')
      ?.addEventListener('change', function () {
        const id     = this.value;
        const $mod   = document.getElementById('mvb-modelo');
        const $ano   = document.getElementById('mvb-ano');
        $mod.innerHTML = '<option value="">Carregando...</option>';
        $mod.disabled  = true;
        $ano.innerHTML = '<option value="">Todos</option>';
        $ano.disabled  = true;
        if (!id) { $mod.innerHTML = '<option value="">Todos</option>'; return; }
        fetch(`${BASE_URL}/meu-veiculo/modelos?montadora_id=${id}`)
          .then(r => r.json()).then(list => {
            let opts = '<option value="">Todos os modelos</option>';
            list.forEach(m => { opts += `<option value="${m.id}">${m.nome}</option>`; });
            $mod.innerHTML = opts; $mod.disabled = false;
          });
      });

    // ── Cascata: modelo → anos ───────────────────────────
    document.getElementById('mvb-modelo')
      ?.addEventListener('change', function () {
        const id   = this.value;
        const $ano = document.getElementById('mvb-ano');
        $ano.innerHTML = '<option value="">Carregando...</option>';
        $ano.disabled  = true;
        if (!id) { $ano.innerHTML = '<option value="">Todos</option>'; return; }
        fetch(`${BASE_URL}/meu-veiculo/anos?modelo_id=${id}`)
          .then(r => r.json()).then(list => {
            let opts = '<option value="">Todos os anos</option>';
            list.forEach(a => { opts += `<option value="${a.ano}">${a.ano}</option>`; });
            $ano.innerHTML = opts; $ano.disabled = false;
          });
      });

    // ── Salvar ───────────────────────────────────────────
    document.getElementById('mvb-form')
      ?.addEventListener('submit', function (e) {
        e.preventDefault();
        const fd = new FormData(this);
        fetch(`${BASE_URL}/meu-veiculo/salvar`, { method: 'POST', body: fd })
          .then(r => r.json()).then(res => {
            if (!res.ok) { notifyToast(res.msg, 'error'); return; }
            fecharDropdown();
            atualizarUI(res.veiculo);
            notifyToast('Moto salva!', 'success');
            // Recarrega para atualizar badges de compatibilidade
            setTimeout(() => window.location.reload(), 600);
          });
      });

    // ── Remover ──────────────────────────────────────────
    document.getElementById('mvb-remover-btn')
      ?.addEventListener('click', function () {
        const fd = new FormData();
        fd.append('_csrf_token', CSRF_TOKEN);
        fetch(`${BASE_URL}/meu-veiculo/remover`, { method: 'POST', body: fd })
          .then(r => r.json()).then(res => {
            if (res.ok) {
              atualizarUI(null);
              setTimeout(() => window.location.reload(), 400);
            }
          });
      });

    // ── Atualiza UI ──────────────────────────────────────
    function atualizarUI(veiculo) {
      if (veiculo) {
        semVeic?.classList.add('hidden');
        comVeic?.classList.remove('hidden');
        if (nomeEl) nomeEl.textContent = veiculo.label || '';
      } else {
        semVeic?.classList.remove('hidden');
        comVeic?.classList.add('hidden');
      }
    }

    // ── Dot de compatibilidade (na página do produto) ────
    // Checa se existe badge de compatibilidade na página
    const bannerEl = document.getElementById('prod-compat-banner');
    if (dotEl && bannerEl) {
      const isCompat = bannerEl.classList.contains('is-compat');
      dotEl.classList.toggle('is-compat', isCompat);
      dotEl.classList.toggle('is-nc', !isCompat);
    }

  })();

  // ── Banner system ─────────────────────────────────────────
  // (function () {

  //   // Slider
  //   document.querySelectorAll('.bn-slider').forEach(slider => {
  //     const track = slider.querySelector('.bn-slider-track');
  //     const items = slider.querySelectorAll('.bn-item');
  //     const dots  = slider.querySelectorAll('.bn-slider-dot');
  //     const prev  = slider.querySelector('.bn-slider-arrow--prev');
  //     const next  = slider.querySelector('.bn-slider-arrow--next');

  //     if (items.length <= 1) return;

  //     let current = 0;
  //     const total = items.length;

  //     function goTo(idx) {
  //       current = (idx + total) % total;
  //       track.style.transform = `translateX(-${current * 100}%)`;
  //       dots.forEach((d, i) => d.classList.toggle('is-active', i === current));
  //     }

  //     prev?.addEventListener('click', () => goTo(current - 1));
  //     next?.addEventListener('click', () => goTo(current + 1));
  //     dots.forEach((d, i) => d.addEventListener('click', () => goTo(i)));

  //     // Auto-play
  //     let timer = setInterval(() => goTo(current + 1), 6000);
  //     slider.addEventListener('mouseenter', () => clearInterval(timer));
  //     slider.addEventListener('mouseleave', () => {
  //       timer = setInterval(() => goTo(current + 1), 6000);
  //     });

  //     // Touch swipe
  //     let startX = null;
  //     track.addEventListener('touchstart', e => { startX = e.touches[0].clientX; });
  //     track.addEventListener('touchend',   e => {
  //       if (startX === null) return;
  //       const diff = startX - e.changedTouches[0].clientX;
  //       if (Math.abs(diff) > 50) goTo(current + (diff > 0 ? 1 : -1));
  //       startX = null;
  //     });
  //   });

  //   // Registra impressões — só uma vez por sessão por banner
  //   const impressoesRegistradas = new Set(JSON.parse(sessionStorage.getItem('banner_imp') || '[]'));
  //   const novasImpressoes       = [];

  //   document.querySelectorAll('[data-banner-ids]').forEach(zone => {
  //     try {
  //       const ids = JSON.parse(zone.dataset.bannerIds);
  //       ids.forEach(id => {
  //         if (!impressoesRegistradas.has(id)) {
  //           novasImpressoes.push(id);
  //           impressoesRegistradas.add(id);
  //         }
  //       });
  //     } catch (e) {}
  //   });

  //   if (novasImpressoes.length > 0) {
  //     sessionStorage.setItem('banner_imp', JSON.stringify([...impressoesRegistradas]));

  //     const fd = new FormData();
  //     novasImpressoes.forEach((id, i) => fd.append(`ids[${i}]`, id));

  //     // Envia silenciosamente
  //     fetch(BASE_URL + '/banner/impressao', { method: 'POST', body: fd })
  //       .catch(() => {});
  //   }

  //   // Tracking de cliques
  //   document.querySelectorAll('[data-banner-click]').forEach(el => {
  //     el.addEventListener('click', function () {
  //       const id = this.dataset.bannerClick;
  //       if (!id) return;

  //       // Beacon não bloqueia navegação
  //       if (navigator.sendBeacon) {
  //         const fd = new FormData();
  //         fd.append('ids[]', id);
  //         navigator.sendBeacon(BASE_URL + '/banner/impressao', fd);
  //       }
  //     });
  //   });

  // })();

  /**
   * banner.js — Countdown + Slider + Tracking + Progresso Circular
   * Versão jQuery.
   * Dependência: jQuery.
   */
  ;(function ($, window, document) {
    'use strict';

    var countdownTimer = null;

    // ════════════════════════════════════════════════════════
    // HELPERS
    // ════════════════════════════════════════════════════════

    function pad(n) {
      n = parseInt(n, 10) || 0;
      return n < 10 ? '0' + n : String(n);
    }

    function getBaseUrl() {
      return typeof window.BASE_URL !== 'undefined' ? window.BASE_URL : '';
    }

    function storageGet(key, fallback) {
      try {
        return JSON.parse(sessionStorage.getItem(key) || fallback);
      } catch (e) {
        return JSON.parse(fallback);
      }
    }

    function storageSet(key, value) {
      try {
        sessionStorage.setItem(key, JSON.stringify(value));
      } catch (e) {}
    }

    // ════════════════════════════════════════════════════════
    // COUNTDOWN ENGINE
    // Detecta todos .bn-countdown[data-fim]
    // ════════════════════════════════════════════════════════

    function tickCountdown(el) {
      var $el = $(el);
      var fimRaw = $el.attr('data-fim');

      if (!fimRaw) return;

      var fim = new Date(fimRaw).getTime();

      if (isNaN(fim)) return;

      var agora = Date.now();
      var diff = fim - agora;

      if (diff <= 0) {
        $el.addClass('is-expired');

        $el.find('.bn-unit-val').each(function () {
          $(this).text('00');
        });

        return;
      }

      var dias  = Math.floor(diff / 86400000);
      var horas = Math.floor((diff % 86400000) / 3600000);
      var min   = Math.floor((diff % 3600000) / 60000);
      var seg   = Math.floor((diff % 60000) / 1000);

      var vals = {
        dias: dias,
        horas: horas,
        min: min,
        seg: seg
      };

      $.each(vals, function (key, val) {
        var $span = $el.find('[data-unit="' + key + '"]');

        if (!$span.length) return;

        var novo = pad(val);

        if ($span.text() !== novo) {
          $span.addClass('is-ticking');

          setTimeout(function () {
            $span.removeClass('is-ticking');
          }, 160);

          $span.text(novo);
        }
      });
    }

    function initCountdowns() {
      var $els = $('.bn-countdown[data-fim]');

      if (!$els.length) return;

      $els.each(function () {
        tickCountdown(this);
      });

      if (countdownTimer) {
        clearInterval(countdownTimer);
      }

      countdownTimer = setInterval(function () {
        $('.bn-countdown[data-fim]').each(function () {
          tickCountdown(this);
        });
      }, 1000);
    }

    // ════════════════════════════════════════════════════════
    // SLIDER ENGINE
    // Compatível com .bn-slider e .bn-slider-wrap
    // ════════════════════════════════════════════════════════

    function createCircleProgress($slider) {
      if ($slider.find('.bn-slider-progress-circle').length) {
        return $slider.find('.bn-slider-progress-circle').first();
      }

      var size = 42;
      var stroke = 4;
      var radius = (size - stroke) / 2;
      var circumference = 2 * Math.PI * radius;

      if ($slider.css('position') === 'static') {
        $slider.css('position', 'relative');
      }

      var $progress = $('<div class="bn-slider-progress-circle" aria-hidden="true"></div>');

      $progress.css({
        position: 'absolute',
        right: '18px',
        bottom: '18px',
        width: size + 'px',
        height: size + 'px',
        zIndex: 30,
        pointerEvents: 'none',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center'
      });

      var svg =
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 ' + size + ' ' + size + '">' +
          '<circle ' +
            'cx="' + (size / 2) + '" ' +
            'cy="' + (size / 2) + '" ' +
            'r="' + radius + '" ' +
            'fill="none" ' +
            'stroke="rgba(255,255,255,.35)" ' +
            'stroke-width="' + stroke + '"' +
          '/>' +
          '<circle ' +
            'class="bn-slider-progress-circle-bar" ' +
            'cx="' + (size / 2) + '" ' +
            'cy="' + (size / 2) + '" ' +
            'r="' + radius + '" ' +
            'fill="none" ' +
            'stroke="var(--c-primary, #398de6)" ' +
            'stroke-width="' + stroke + '" ' +
            'stroke-linecap="round" ' +
            'stroke-dasharray="' + circumference + '" ' +
            'stroke-dashoffset="' + circumference + '" ' +
            'transform="rotate(-90 ' + (size / 2) + ' ' + (size / 2) + ')"' +
          '/>' +
        '</svg>';

      $progress.html(svg);
      $progress.data('circumference', circumference);

      $slider.append($progress);

      return $progress;
    }

    function initSliders() {
      $('.bn-slider, .bn-slider-wrap').each(function () {
        var $slider = $(this);

        if ($slider.data('bn-slider-ready')) return;

        var $track = $slider.find('.bn-slider-track').first();

        if (!$track.length) return;

        var $items = $track.children('.bn-item');

        if (!$items.length) {
          $items = $track.children();
        }

        var total = $items.length;

        if (total <= 1) return;

        $slider.data('bn-slider-ready', true);

        var $dots = $slider.find('.bn-slider-dot');
        var $prev = $slider.find('.bn-slider-arrow--prev').first();
        var $next = $slider.find('.bn-slider-arrow--next').first();

        var current = 0;
        var interval = parseInt($slider.attr('data-interval') || $slider.data('interval') || 6000, 10);

        if (!interval || interval < 1000) {
          interval = 6000;
        }

        var $progress = createCircleProgress($slider);
        var $progressBar = $progress.find('.bn-slider-progress-circle-bar');
        var circumference = $progress.data('circumference');

        var rafId = null;
        var paused = false;
        var startTime = null;
        var elapsedBeforePause = 0;
        var touchStartX = null;

        function setProgress(percent) {
          percent = Math.max(0, Math.min(1, percent));

          var offset = circumference - (percent * circumference);

          $progressBar.css({
            strokeDashoffset: offset
          });
        }

        function stopAnimationFrame() {
          if (rafId) {
            cancelAnimationFrame(rafId);
            rafId = null;
          }
        }

        function loop(timestamp) {
          if (paused) return;

          if (startTime === null) {
            startTime = timestamp;
          }

          var elapsed = elapsedBeforePause + (timestamp - startTime);
          var percent = elapsed / interval;

          setProgress(percent);

          if (elapsed >= interval) {
            goTo(current + 1, true);

            elapsedBeforePause = 0;
            startTime = timestamp;
            setProgress(0);
          }

          rafId = requestAnimationFrame(loop);
        }

        function startAuto() {
          paused = false;
          elapsedBeforePause = 0;
          startTime = null;

          setProgress(0);
          stopAnimationFrame();

          rafId = requestAnimationFrame(loop);
        }

        function pauseAuto() {
          if (paused) return;

          paused = true;

          if (startTime !== null) {
            elapsedBeforePause += performance.now() - startTime;
          }

          startTime = null;

          stopAnimationFrame();
        }

        function resumeAuto() {
          if (!paused) return;

          paused = false;
          startTime = null;

          stopAnimationFrame();
          rafId = requestAnimationFrame(loop);
        }

        function restartAuto() {
          paused = false;
          elapsedBeforePause = 0;
          startTime = null;

          setProgress(0);
          stopAnimationFrame();

          rafId = requestAnimationFrame(loop);
        }

        function goTo(idx, fromAuto) {
          current = (idx + total) % total;

          $track.css({
            transform: 'translateX(-' + (current * 100) + '%)'
          });

          $dots.each(function (i) {
            $(this).toggleClass('is-active', i === current);
          });

          if (!fromAuto) {
            restartAuto();
          }
        }

        function nextSlide() {
          goTo(current + 1, false);
        }

        function prevSlide() {
          goTo(current - 1, false);
        }

        // Arrows
        $prev.on('click.bnSlider', function (e) {
          e.preventDefault();
          prevSlide();
        });

        $next.on('click.bnSlider', function (e) {
          e.preventDefault();
          nextSlide();
        });

        // Dots
        $dots.each(function (i) {
          $(this).on('click.bnSlider', function (e) {
            e.preventDefault();
            goTo(i, false);
          });
        });

        // Pause on hover desktop
        $slider.on('mouseenter.bnSlider', function () {
          pauseAuto();
        });

        $slider.on('mouseleave.bnSlider', function () {
          resumeAuto();
        });

        // Mobile: segurou o dedo em cima, pausa
        $slider.on('touchstart.bnSlider', function () {
          pauseAuto();
        });

        $slider.on('touchend.bnSlider touchcancel.bnSlider', function () {
          resumeAuto();
        });

        // Touch swipe
        $track.on('touchstart.bnSlider', function (e) {
          var originalEvent = e.originalEvent;

          if (!originalEvent.touches || !originalEvent.touches.length) return;

          touchStartX = originalEvent.touches[0].clientX;
        });

        $track.on('touchend.bnSlider', function (e) {
          var originalEvent = e.originalEvent;

          if (
            touchStartX === null ||
            !originalEvent.changedTouches ||
            !originalEvent.changedTouches.length
          ) {
            touchStartX = null;
            return;
          }

          var diff = touchStartX - originalEvent.changedTouches[0].clientX;

          if (Math.abs(diff) > 50) {
            if (diff > 0) {
              goTo(current + 1, false);
            } else {
              goTo(current - 1, false);
            }
          }

          touchStartX = null;
        });

        // Keyboard
        $slider.attr('tabindex', '0');

        $slider.on('keydown.bnSlider', function (e) {
          if (e.key === 'ArrowRight') {
            nextSlide();
          }

          if (e.key === 'ArrowLeft') {
            prevSlide();
          }
        });

        // Init
        goTo(0, true);
        startAuto();
      });
    }

    // ════════════════════════════════════════════════════════
    // IMPRESSÕES
    // Registra impressões uma vez por sessão por banner
    // ════════════════════════════════════════════════════════

    function registerImpressions() {
      var baseUrl = getBaseUrl();

      if (!baseUrl) return;

      var impressoesRegistradas = new Set(storageGet('banner_imp', '[]').map(String));
      var novasImpressoes = [];

      $('[data-banner-ids]').each(function () {
        var raw = $(this).attr('data-banner-ids');

        if (!raw) return;

        try {
          var ids = JSON.parse(raw);

          $.each(ids, function (_, id) {
            id = String(id);

            if (!impressoesRegistradas.has(id)) {
              novasImpressoes.push(id);
              impressoesRegistradas.add(id);
            }
          });
        } catch (e) {}
      });

      if (!novasImpressoes.length) return;

      storageSet('banner_imp', Array.from(impressoesRegistradas));

      var fd = new FormData();

      $.each(novasImpressoes, function (i, id) {
        fd.append('ids[' + i + ']', id);
      });

      $.ajax({
        url: baseUrl + '/banner/impressao',
        method: 'POST',
        data: fd,
        processData: false,
        contentType: false
      }).fail(function () {});
    }

    // ════════════════════════════════════════════════════════
    // TRACKING DE CLIQUES
    // ════════════════════════════════════════════════════════

    function registerClicks() {
      $('[data-banner-click]')
        .off('click.bnBannerClick')
        .on('click.bnBannerClick', function () {
          var baseUrl = getBaseUrl();
          var id = $(this).attr('data-banner-click');

          if (!baseUrl || !id) return;

          var fd = new FormData();
          fd.append('ids[]', id);

          if (navigator.sendBeacon) {
            navigator.sendBeacon(baseUrl + '/banner/impressao', fd);
            return;
          }

          $.ajax({
            url: baseUrl + '/banner/impressao',
            method: 'POST',
            data: fd,
            processData: false,
            contentType: false
          }).fail(function () {});
        });
    }

    // ════════════════════════════════════════════════════════
    // INIT
    // ════════════════════════════════════════════════════════

    function initBanners() {
      initCountdowns();
      initSliders();
      registerImpressions();
      registerClicks();
    }

    $(document).ready(function () {
      initBanners();
    });

    // Expõe para uso externo, útil em AJAX, lazy-load ou SPA
    window.BannerCountdown = {
      init: initCountdowns
    };

    window.BannerSlider = {
      init: initSliders
    };

    window.BannerTracking = {
      impressions: registerImpressions,
      clicks: registerClicks
    };

    window.BannerModule = {
      init: initBanners
    };

  }(jQuery, window, document));

  /**
   * personalization-tracking.js
   * Registra sinais de navegação via sendBeacon.
   * Incluir globalmente no layout principal.
   *
   * Uso automático (via data attributes na view):
   *   <div data-track-produto="42"></div>
   *   <div data-track-categoria="7"></div>
   *   <div data-track-marca="3"></div>
   *   <div data-track-busca="escapamento esportivo"></div>
   *   <div data-track-clip="15"></div>
   *
   * Uso manual via JS:
   *   PersonalizationTrack.produto(42);
   *   PersonalizationTrack.categoria(7);
   *   PersonalizationTrack.busca('escapamento');
   */
  // ;(function (window, document) {
  //   'use strict';

  //   const BASE = BASE_URL   || '';
  //   const CSRF = CSRF_TOKEN || '';

  //   function beacon(tipo, referencia) {
  //     if (!tipo || referencia === null || referencia === undefined || referencia === '') return;
  //     const fd = new FormData();
  //     fd.append('tipo',        tipo);
  //     fd.append('referencia',  String(referencia));
  //     fd.append('_csrf_token', CSRF);

  //     if (navigator.sendBeacon) {
  //       navigator.sendBeacon(BASE + '/historico/registrar', fd);
  //     } else {
  //       fetch(BASE + '/historico/registrar', {
  //         method: 'POST', body: fd, keepalive: true,
  //       }).catch(() => {});
  //     }
  //   }

  //   // ── Auto-registra pelo data-track-* na página ─────────
  //   document.addEventListener('DOMContentLoaded', function () {
  //     const tipos = ['produto', 'categoria', 'busca', 'marca', 'clip'];
  //     tipos.forEach(function (tipo) {
  //       const attr = 'data-track-' + tipo;
  //       const el   = document.querySelector('[' + attr + ']');
  //       if (!el) return;
  //       const val = el.getAttribute(attr);
  //       if (val !== null && val !== '') beacon(tipo, val);
  //     });
  //   });

  //   // ── API pública ───────────────────────────────────────
  //   window.PersonalizationTrack = {
  //     produto:   (id)    => beacon('produto',   id),
  //     categoria: (id)    => beacon('categoria', id),
  //     marca:     (id)    => beacon('marca',     id),
  //     clip:      (id)    => beacon('clip',      id),
  //     busca:     (termo) => beacon('busca',     termo),
  //   };

  // }(window, document));

  /**
   * banner.js — Countdown + Slider para banners
   * Versão jQuery.
   * Dependência: jQuery.
   */
  // (function () {
  //   'use strict';

  //   // ════════════════════════════════════════════════════════
  //   // COUNTDOWN ENGINE
  //   // Detecta todos .bn-countdown[data-fim] na página
  //   // ════════════════════════════════════════════════════════

  //   function pad(n) {
  //     return String(n).padStart(2, '0');
  //   }

  //   function tickCountdown(el) {
  //     var $el = $(el);
  //     var fim = new Date($el.data('fim')).getTime();
  //     var agora = Date.now();
  //     var diff = fim - agora;

  //     if (diff <= 0) {
  //       $el.addClass('is-expired');

  //       $el.find('.bn-unit-val').each(function () {
  //         $(this).text('00');
  //       });

  //       return;
  //     }

  //     var dias  = Math.floor(diff / 86400000);
  //     var horas = Math.floor((diff % 86400000) / 3600000);
  //     var min   = Math.floor((diff % 3600000) / 60000);
  //     var seg   = Math.floor((diff % 60000) / 1000);

  //     var vals = {
  //       dias: dias,
  //       horas: horas,
  //       min: min,
  //       seg: seg
  //     };

  //     $.each(vals, function (key, val) {
  //       var $span = $el.find('[data-unit="' + key + '"]');

  //       if (!$span.length) return;

  //       var novo = pad(val);

  //       if ($span.text() !== novo) {
  //         $span.addClass('is-ticking');

  //         setTimeout(function () {
  //           $span.removeClass('is-ticking');
  //         }, 160);

  //         $span.text(novo);
  //       }
  //     });
  //   }

  //   function initCountdowns() {
  //     var $els = $('.bn-countdown[data-fim]');

  //     if (!$els.length) return;

  //     // Tick imediato
  //     $els.each(function () {
  //       tickCountdown(this);
  //     });

  //     // Atualiza a cada segundo
  //     setInterval(function () {
  //       $els.each(function () {
  //         tickCountdown(this);
  //       });
  //     }, 1000);
  //   }

  //   // ════════════════════════════════════════════════════════
  //   // SLIDER ENGINE
  //   // Detecta .bn-slider-wrap com múltiplos .bn
  //   // ════════════════════════════════════════════════════════

  //   function initSliders() {
  //     $('.bn-slider-wrap').each(function () {
  //       var $wrap = $(this);
  //       var $track = $wrap.find('.bn-slider-track').first();
  //       var $slides = $track.children();

  //       if (!$track.length || $slides.length <= 1) return;

  //       var current = 0;
  //       var autoPlay = null;
  //       var interval = parseInt($wrap.data('interval') || '5000', 10);

  //       // Dots
  //       var $dotsWrap = $wrap.find('.bn-slider-dots').first();
  //       var $dots = $dotsWrap.length ? $dotsWrap.find('.bn-slider-dot') : $();

  //       function goTo(idx) {
  //         current = (idx + $slides.length) % $slides.length;

  //         $track.css({
  //           transform: 'translateX(-' + (current * 100) + '%)'
  //         });

  //         $dots.each(function (i) {
  //           $(this).toggleClass('is-active', i === current);
  //         });
  //       }

  //       function next() {
  //         goTo(current + 1);
  //       }

  //       function prev() {
  //         goTo(current - 1);
  //       }

  //       function startAuto() {
  //         stopAuto();
  //         autoPlay = setInterval(next, interval);
  //       }

  //       function stopAuto() {
  //         if (autoPlay) {
  //           clearInterval(autoPlay);
  //           autoPlay = null;
  //         }
  //       }

  //       // Dots click
  //       $dots.each(function (i) {
  //         $(this).on('click', function () {
  //           goTo(i);
  //           startAuto();
  //         });
  //       });

  //       // Arrows
  //       var $btnPrev = $wrap.find('.bn-slider-arrow--prev').first();
  //       var $btnNext = $wrap.find('.bn-slider-arrow--next').first();

  //       if ($btnPrev.length) {
  //         $btnPrev.on('click', function () {
  //           prev();
  //           startAuto();
  //         });
  //       }

  //       if ($btnNext.length) {
  //         $btnNext.on('click', function () {
  //           next();
  //           startAuto();
  //         });
  //       }

  //       // Touch/swipe
  //       var touchStartX = 0;

  //       $track.on('touchstart', function (e) {
  //         var originalEvent = e.originalEvent;

  //         if (!originalEvent.touches || !originalEvent.touches.length) return;

  //         touchStartX = originalEvent.touches[0].clientX;
  //         stopAuto();
  //       });

  //       $track.on('touchend', function (e) {
  //         var originalEvent = e.originalEvent;

  //         if (!originalEvent.changedTouches || !originalEvent.changedTouches.length) return;

  //         var diff = touchStartX - originalEvent.changedTouches[0].clientX;

  //         if (Math.abs(diff) > 50) {
  //           if (diff > 0) {
  //             next();
  //           } else {
  //             prev();
  //           }
  //         }

  //         startAuto();
  //       });

  //       // Pause on hover
  //       $wrap.on('mouseenter', stopAuto);
  //       $wrap.on('mouseleave', startAuto);

  //       // Keyboard
  //       $wrap.attr('tabindex', '0');

  //       $wrap.on('keydown', function (e) {
  //         if (e.key === 'ArrowRight') {
  //           next();
  //           startAuto();
  //         }

  //         if (e.key === 'ArrowLeft') {
  //           prev();
  //           startAuto();
  //         }
  //       });

  //       // Init
  //       goTo(0);
  //       startAuto();
  //     });
  //   }

  //   // ════════════════════════════════════════════════════════
  //   // INIT
  //   // ════════════════════════════════════════════════════════

  //   $(document).ready(function () {
  //     initCountdowns();
  //     initSliders();
  //   });

  //   // Expõe para uso externo, exemplo: SPA, AJAX ou lazy-load
  //   window.BannerCountdown = {
  //     init: initCountdowns
  //   };

  //   window.BannerSlider = {
  //     init: initSliders
  //   };

  // }());

  /**
   * banner.js — Countdown + Slider para banners
   * Nenhuma dependência além do browser nativo.
   */
  // ;(function (window, document) {
  //   'use strict';

  //   // ════════════════════════════════════════════════════════
  //   // COUNTDOWN ENGINE
  //   // Detecta todos .bn-countdown[data-fim] na página
  //   // ════════════════════════════════════════════════════════
  //   function pad(n) { return String(n).padStart(2, '0'); }

  //   function tickCountdown(el) {
  //     const fim  = new Date(el.dataset.fim).getTime();
  //     const agora = Date.now();
  //     const diff  = fim - agora;

  //     if (diff <= 0) {
  //       el.classList.add('is-expired');
  //       el.querySelectorAll('.bn-unit-val').forEach(u => {
  //         u.textContent = '00';
  //       });
  //       return;
  //     }

  //     const dias  = Math.floor(diff / 86400000);
  //     const horas = Math.floor((diff % 86400000) / 3600000);
  //     const min   = Math.floor((diff % 3600000)  / 60000);
  //     const seg   = Math.floor((diff % 60000)    / 1000);

  //     const vals = { dias, horas, min, seg };

  //     Object.entries(vals).forEach(([key, val]) => {
  //       const span = el.querySelector(`[data-unit="${key}"]`);
  //       if (!span) return;
  //       const novo = pad(val);
  //       if (span.textContent !== novo) {
  //         span.classList.add('is-ticking');
  //         setTimeout(() => span.classList.remove('is-ticking'), 160);
  //         span.textContent = novo;
  //       }
  //     });
  //   }

  //   function initCountdowns() {
  //     const els = document.querySelectorAll('.bn-countdown[data-fim]');
  //     if (!els.length) return;

  //     // Tick imediato
  //     els.forEach(tickCountdown);

  //     // Atualiza a cada segundo
  //     setInterval(() => els.forEach(tickCountdown), 1000);
  //   }

  //   // ════════════════════════════════════════════════════════
  //   // SLIDER ENGINE
  //   // Detecta .bn-slider-wrap com múltiplos .bn
  //   // ════════════════════════════════════════════════════════
  //   function initSliders() {
  //     document.querySelectorAll('.bn-slider-wrap').forEach(function (wrap) {
  //       const track  = wrap.querySelector('.bn-slider-track');
  //       const slides = track ? Array.from(track.children) : [];
  //       if (slides.length <= 1) return;

  //       let current  = 0;
  //       let autoPlay = null;
  //       const interval = parseInt(wrap.dataset.interval || '5000', 10);

  //       // Dots
  //       const dotsWrap = wrap.querySelector('.bn-slider-dots');
  //       const dots = dotsWrap ? Array.from(dotsWrap.querySelectorAll('.bn-slider-dot')) : [];

  //       function goTo(idx) {
  //         current = (idx + slides.length) % slides.length;
  //         track.style.transform = `translateX(-${current * 100}%)`;
  //         dots.forEach((d, i) => d.classList.toggle('is-active', i === current));
  //       }

  //       function next() { goTo(current + 1); }
  //       function prev() { goTo(current - 1); }

  //       function startAuto() {
  //         stopAuto();
  //         autoPlay = setInterval(next, interval);
  //       }
  //       function stopAuto() {
  //         if (autoPlay) { clearInterval(autoPlay); autoPlay = null; }
  //       }

  //       // Dots click
  //       dots.forEach((d, i) => {
  //         d.addEventListener('click', () => { goTo(i); startAuto(); });
  //       });

  //       // Arrows
  //       const btnPrev = wrap.querySelector('.bn-slider-arrow--prev');
  //       const btnNext = wrap.querySelector('.bn-slider-arrow--next');
  //       if (btnPrev) btnPrev.addEventListener('click', () => { prev(); startAuto(); });
  //       if (btnNext) btnNext.addEventListener('click', () => { next(); startAuto(); });

  //       // Touch/swipe
  //       let touchStartX = 0;
  //       track.addEventListener('touchstart', e => {
  //         touchStartX = e.touches[0].clientX;
  //         stopAuto();
  //       }, { passive: true });
  //       track.addEventListener('touchend', e => {
  //         const diff = touchStartX - e.changedTouches[0].clientX;
  //         if (Math.abs(diff) > 50) diff > 0 ? next() : prev();
  //         startAuto();
  //       }, { passive: true });

  //       // Pause on hover
  //       wrap.addEventListener('mouseenter', stopAuto);
  //       wrap.addEventListener('mouseleave', startAuto);

  //       // Keyboard
  //       wrap.setAttribute('tabindex', '0');
  //       wrap.addEventListener('keydown', e => {
  //         if (e.key === 'ArrowRight') { next(); startAuto(); }
  //         if (e.key === 'ArrowLeft')  { prev(); startAuto(); }
  //       });

  //       // Init
  //       goTo(0);
  //       startAuto();
  //     });
  //   }

  //   // ════════════════════════════════════════════════════════
  //   // INIT
  //   // ════════════════════════════════════════════════════════
  //   document.addEventListener('DOMContentLoaded', function () {
  //     initCountdowns();
  //     initSliders();
  //   });

  //   // Expõe para uso externo (ex: SPA ou lazy-load)
  //   window.BannerCountdown = { init: initCountdowns };
  //   window.BannerSlider    = { init: initSliders };

  // }(window, document));



  // ── Busca por moto na categoria ────────────────────────────
  (function () {
    const $mont  = $('#cat-busca-montadora');
    const $mod   = $('#cat-busca-modelo');
    const $ano   = $('#cat-busca-ano');

    if (!$mont.length) return;

    $mont.on('change', function () {
      const id = $(this).val();
      $mod.html('<option value="">Carregando...</option>').prop('disabled', true);
      $ano.html('<option value="">Ano</option>').prop('disabled', true);

      if (!id) {
        $mod.html('<option value="">Modelo</option>').prop('disabled', true);
        return;
      }

      $.get(BASE_URL + '/ajax/moto/modelos', { montadora_id: id }, function (modelos) {
        let opts = '<option value="">Todos os modelos</option>';
        modelos.forEach(m => {
          opts += `<option value="${m.id}">${m.nome}</option>`;
        });
        $mod.html(opts).prop('disabled', false);
      }, 'json');
    });

    $mod.on('change', function () {
      const id = $(this).val();
      $ano.html('<option value="">Carregando...</option>').prop('disabled', true);

      if (!id) {
        $ano.html('<option value="">Ano</option>').prop('disabled', true);
        return;
      }

      $.get(BASE_URL + '/ajax/moto/anos', { modelo_id: id }, function (anos) {
        let opts = '<option value="">Todos os anos</option>';
        anos.forEach(a => {
          opts += `<option value="${a.ano}">${a.ano}</option>`;
        });
        $ano.html(opts).prop('disabled', false);
      }, 'json');
    });
  })();

  // ── Busca por moto: redirect para URL amigável ────────────
  // Cobre tanto o hero (#form-hero-busca-moto)
  // quanto a barra da categoria (.cat-moto-search-form)

  (function () {

    function inicializarBuscaMoto($form) {
      if (!$form.length) return;

      const $mont = $form.find('[name="montadora_id"]');
      const $mod  = $form.find('[name="modelo_id"]');
      const $ano  = $form.find('[name="ano"]');
      const $btn  = $form.find('[type="submit"]');

      // ── Cascata montadora → modelos ──────────────────────
      $mont.on('change', function () {
        const id = $(this).val();

        $mod.html('<option value="">Carregando...</option>').prop('disabled', true);
        $ano.html('<option value="">Ano</option>').prop('disabled', true);
        $btn.prop('disabled', true);

        if (!id) {
          $mod.html('<option value="">Modelo</option>').prop('disabled', true);
          return;
        }

        $.get(BASE_URL + '/ajax/moto/modelos', { montadora_id: id }, function (modelos) {
          let opts = '<option value="">Todos os modelos</option>';
          modelos.forEach(m => {
            opts += `<option value="${m.id}" data-slug="${m.slug}">${m.nome}</option>`;
          });
          $mod.html(opts).prop('disabled', false);
          $btn.prop('disabled', false);
        }, 'json');
      });

      // ── Cascata modelo → anos ─────────────────────────────
      $mod.on('change', function () {
        const id = $(this).val();

        $ano.html('<option value="">Carregando...</option>').prop('disabled', true);

        if (!id) {
          $ano.html('<option value="">Ano</option>').prop('disabled', true);
          $btn.prop('disabled', !$mont.val());
          return;
        }

        $.get(BASE_URL + '/ajax/moto/anos', { modelo_id: id }, function (anos) {
          let opts = '<option value="">Todos os anos</option>';
          anos.forEach(a => {
            opts += `<option value="${a.ano}">${a.ano}</option>`;
          });
          $ano.html(opts).prop('disabled', false);
        }, 'json');

        $btn.prop('disabled', false);
      });

      // ── Submit → monta URL amigável ───────────────────────
      $form.on('submit', function (e) {
        e.preventDefault();

        const montId   = $mont.val();
        const montSlug = $mont.find('option:selected').data('slug')
                      || $mont.find('option:selected').attr('data-slug');

        if (!montId || !montSlug) return;

        const modSlug = $mod.find('option:selected').data('slug')
                    || $mod.find('option:selected').attr('data-slug');
        const ano     = $ano.val();

        // Monta a URL amigável
        let url = `${BASE_URL}/montadora/${montSlug}`;

        if (modSlug) {
          url += `/${modSlug}`;
          if (ano) url += `-${ano}`;
        }

        window.location.href = url;
      });

      // Habilita botão se já tiver montadora selecionada ao carregar
      if ($mont.val()) $btn.prop('disabled', false);
    }

    // Inicializa em todos os formulários de busca por moto da página
    inicializarBuscaMoto($('#form-hero-busca-moto'));
    // inicializarBuscaMoto($('.cat-moto-search-form'));

  })();

    // Adicionar dentro do $(function() { ... }) no main.js

    // ── Slider de imagens no card de produto ─────────────────
    (function () {

      // Cache de imagens já carregadas por produto
      const imageCache = {};

      // Estado do slider por card
      const sliderState = {};

      // ── Hover no card ───────────────────────────────────────
      $(document).on('mouseenter', '.product-card', function () {
        const $card      = $(this);
        const productId  = $card.data('product-id');
        const loaded     = $card.data('images-loaded');

        if (!productId) return;

        // Já carregou — só inicia o slider
        if (loaded === true || loaded === 'true') {
          iniciarSlider($card, productId);
          return;
        }

        // Primeira vez — carrega as imagens via Ajax
        $card.data('images-loaded', 'loading');

        // Verifica cache
        if (imageCache[productId]) {
          renderSlides($card, productId, imageCache[productId]);
          return;
        }

        $.get(BASE_URL + '/produto/card-images', { id: productId }, function (res) {
          if (!res.ok || res.images.length <= 1) {
            $card.data('images-loaded', true);
            return;
          }

          imageCache[productId] = res.images;
          renderSlides($card, productId, res.images);

        }, 'json').fail(function () {
          $card.data('images-loaded', true);
        });

      });

      // Substituir o hover do .pc-color-swatch

    $(document).on('mouseenter', '.pc-color-swatch', function (e) {
        e.stopPropagation();

        const $swatch   = $(this);
        const $card     = $swatch.closest('.product-card');
        const productId = $card.data('product-id');
        const imagem    = $swatch.data('imagem');

        if (!imagem) return;

        const $activeSlide = $card.find('.pc-slide--active .pc-img');

        // Salva imagem original apenas uma vez
        if (!$activeSlide.data('original-src')) {
            $activeSlide.data('original-src', $activeSlide.attr('src'));
        }

        $activeSlide.attr('src', imagem);

    }).on('mouseleave', '.pc-color-swatch', function () {
        const $card        = $(this).closest('.product-card');
        const $activeSlide = $card.find('.pc-slide--active .pc-img');
        const original     = $activeSlide.data('original-src');

        // Restaura imagem original ao sair do swatch
        // mas NÃO apaga o data para o próximo hover
        if (original) {
            $activeSlide.attr('src', original);
        }
    });

      // Substituir o evento mouseleave do .product-card no slider

      const resetTimers = {}; // timer de reset por produto

      $(document).on('mouseleave', '.product-card', function () {
          const $card     = $(this);
          const productId = $card.data('product-id');

          // Para o autoplay
          pararAutoplay(productId);

          // Cancela reset anterior se existir
          if (resetTimers[productId]) {
              clearTimeout(resetTimers[productId]);
          }

          // Após 30 segundos volta para a primeira imagem suavemente
          resetTimers[productId] = setTimeout(function () {
              const state = sliderState[productId];
              if (!state || state.current === 0) return;

              irParaSlide($card, productId, 0, true);
          }, 10000);
      });

      // ── Renderiza os slides ─────────────────────────────────
      function renderSlides($card, productId, images) {
        const $slider = $card.find('.pc-slider');
        const $dots   = $card.find('.pc-dots');

        // Remove slides antigos (mantém só o primeiro)
        $slider.find('.pc-slide:not(:first-child)').remove();

        // Atualiza src do primeiro slide
        if (images[0]) {
          $slider.find('.pc-slide:first-child .pc-img').attr('src', images[0]);
        }

        // Cria slides para as demais imagens
        images.slice(1).forEach(function (url) {
          const $slide = $('<div class="pc-slide">');
          const $img   = $('<img>').attr({
            src:     url,
            alt:     '',
            width:   300,
            height:  300,
            loading: 'lazy',
          }).addClass('pc-img');
          $slide.append($img);
          $slider.append($slide);
        });

        // Cria dots
        $dots.empty();
        images.forEach(function (_, i) {
          $('<button>').attr({ type: 'button', 'aria-label': 'Foto ' + (i + 1) })
            .addClass('pc-dot' + (i === 0 ? ' pc-dot--active' : ''))
            .data('index', i)
            .appendTo($dots);
        });

        // Mostra controles se tiver mais de 1 imagem
        if (images.length > 1) {
          $dots.show();
          $card.find('.pc-arrow').show();
        }

        // Inicializa estado
        sliderState[productId] = { current: 0, total: images.length, timer: null };

        $card.data('images-loaded', true);
        iniciarSlider($card, productId);
      }

      // ── Inicia o autoplay ───────────────────────────────────
      function iniciarSlider($card, productId) {
        const state = sliderState[productId];
        if (!state || state.total <= 1) return;

        let time = $card.data('time') || 5000;
        if (typeof time === 'string') {
          time = parseInt(time);
          if (isNaN(time)) time = 5000;
        }

        pararAutoplay(productId);

        // Avança automaticamente a cada 1.2s
        state.timer = setInterval(function () {
          const next = (state.current + 1) % state.total;
          irParaSlide($card, productId, next, true);
        }, time);
      }

      // ── Para o autoplay ─────────────────────────────────────
      function pararAutoplay(productId) {
        const state = sliderState[productId];
        if (state && state.timer) {
          clearInterval(state.timer);
          state.timer = null;
        }
      }

      // ── Vai para um slide específico ────────────────────────
      function irParaSlide($card, productId, index, animado) {
        const state = sliderState[productId];
        if (!state) return;

        const $slider = $card.find('.pc-slider');
        const $slides = $slider.find('.pc-slide');
        const $dots   = $card.find('.pc-dot');

        if (index < 0 || index >= $slides.length) return;

        $slides.removeClass('pc-slide--active pc-slide--prev');
        $slides.eq(state.current).addClass('pc-slide--prev');
        $slides.eq(index).addClass('pc-slide--active');

        $dots.removeClass('pc-dot--active');
        $dots.eq(index).addClass('pc-dot--active');

        state.current = index;
      }

      // ── Setas de navegação ──────────────────────────────────
      $(document).on('click', '.pc-arrow--prev', function (e) {
        e.preventDefault();
        e.stopPropagation();
        const $card     = $(this).closest('.product-card');
        const productId = $card.data('product-id');
        const state     = sliderState[productId];
        if (!state) return;
        pararAutoplay(productId);
        const prev = (state.current - 1 + state.total) % state.total;
        irParaSlide($card, productId, prev, true);
      });

      $(document).on('click', '.pc-arrow--next', function (e) {
        e.preventDefault();
        e.stopPropagation();
        const $card     = $(this).closest('.product-card');
        const productId = $card.data('product-id');
        const state     = sliderState[productId];
        if (!state) return;
        pararAutoplay(productId);
        const next = (state.current + 1) % state.total;
        irParaSlide($card, productId, next, true);
      });

      // ── Dots ────────────────────────────────────────────────
      $(document).on('click', '.pc-dot', function (e) {
        e.preventDefault();
        e.stopPropagation();
        const $card     = $(this).closest('.product-card');
        const productId = $card.data('product-id');
        const index     = $(this).data('index');
        pararAutoplay(productId);
        irParaSlide($card, productId, index, true);
      });

      // ── Swipe touch ─────────────────────────────────────────
      let touchStartX = 0;

      $(document).on('touchstart', '.product-card', function (e) {
        touchStartX = e.originalEvent.touches[0].clientX;
      });

      $(document).on('touchend', '.product-card', function (e) {
        const diff      = touchStartX - e.originalEvent.changedTouches[0].clientX;
        const $card     = $(this);
        const productId = $card.data('product-id');
        const state     = sliderState[productId];
        if (!state || Math.abs(diff) < 30) return;

        pararAutoplay(productId);
        if (diff > 0) {
          irParaSlide($card, productId, (state.current + 1) % state.total, true);
        } else {
          irParaSlide($card, productId, (state.current - 1 + state.total) % state.total, true);
        }
      });

    })();

    // ── Benefits slider ───────────────────────────────────────
    (function () {
      const $track  = document.getElementById('benefitsTrack');
      const $dots   = document.getElementById('benefitsDots');
      const $prev   = document.getElementById('benefitsPrev');
      const $next   = document.getElementById('benefitsNext');

      if (!$track) return;

      const cards   = $track.children;
      const GAP     = 12;
      let   current = 0;
      let   autoTimer;

      function getVisible() {
        const w = $track.parentElement.offsetWidth;
        if (w < 400) return 1;
        if (w < 640) return 2;
        if (w < 900) return 3;
        return 4;
      }

      function getCardWidth() {
        const visible = getVisible();
        const total   = $track.parentElement.offsetWidth;
        return (total - GAP * (visible - 1)) / visible;
      }

      function maxSlide() {
        return Math.max(0, cards.length - getVisible());
      }

      function goTo(index) {
        current = Math.max(0, Math.min(index, maxSlide()));
        const offset = current * (getCardWidth() + GAP);
        $track.style.transform = `translateX(-${offset}px)`;

        $prev.disabled = current === 0;
        $next.disabled = current >= maxSlide();

        document.querySelectorAll('.benefits-dot').forEach((d, i) => {
          d.classList.toggle('active', i === current);
        });
      }

      function buildDots() {
        $dots.innerHTML = '';
        for (let i = 0; i <= maxSlide(); i++) {
          const d = document.createElement('button');
          d.className = 'benefits-dot' + (i === current ? ' active' : '');
          d.setAttribute('aria-label', 'Item ' + (i + 1));
          d.addEventListener('click', () => { goTo(i); resetAuto(); });
          $dots.appendChild(d);
        }
      }

      function resetAuto() {
        clearInterval(autoTimer);
        autoTimer = setInterval(() => {
          goTo(current >= maxSlide() ? 0 : current + 1);
        }, 5000);
      }

      $prev.addEventListener('click', () => { goTo(current - 1); resetAuto(); });
      $next.addEventListener('click', () => { goTo(current + 1); resetAuto(); });

      // Swipe touch
      let touchX = 0;
      $track.addEventListener('touchstart', e => { touchX = e.touches[0].clientX; }, { passive: true });
      $track.addEventListener('touchend',   e => {
        const diff = touchX - e.changedTouches[0].clientX;
        if (Math.abs(diff) > 40) { goTo(diff > 0 ? current + 1 : current - 1); resetAuto(); }
      });

      // Inicializa
      function init() {
        buildDots();
        goTo(0);
        resetAuto();
      }

      // Pausa autoplay no hover
      document.getElementById('benefitsSlider')
        ?.addEventListener('mouseenter', () => clearInterval(autoTimer));
      document.getElementById('benefitsSlider')
        ?.addEventListener('mouseleave', () => resetAuto());

      window.addEventListener('resize', () => { buildDots(); goTo(current); });

      init();
    })();

    // ── Brands index page ─────────────────────────────────────
    (function () {
      if (!document.getElementById('brands-sliders-wrap')) return;

      const LIMIT_SLIDER = 20; // máximo por slider
      const STEP         = 7;  // quantos buscar por vez

      // ── Cria HTML de um card de produto ──────────────────
      function buildProductCard(item) {
        const promo = item.tem_promo
          ? `<span class="bps-card-original">${item.preco_original_fmt}</span>`
          : '';
        return `
          <a href="${BASE_URL}/produto/${item.slug}" class="bps-card">
            <div class="bps-card-img">
              <img src="${item.imagem}" alt="${item.nome}" loading="lazy">
            </div>
            <div class="bps-card-info">
              <span class="bps-card-nome">${item.nome}</span>
              <div class="bps-card-precos">
                ${promo}
                <span class="bps-card-preco ${item.tem_promo ? 'bps-card-preco--promo' : ''}">
                  ${item.preco_fmt}
                </span>
              </div>
            </div>
          </a>`;
      }

      // ── Cria o bloco HTML de uma nova marca ───────────────
      function buildBrandBlock(marca) {
        const logoHtml = marca.logo
          ? `<div class="brand-slider-logo" style="background:${marca.bg_cor}">
              <img src="${marca.logo}" alt="${marca.nome}">
            </div>`
          : '';

        const skeletons = Array(5).fill('<div class="bps-skeleton"></div>').join('');

        return `
          <div class="brand-slider-block observe-up"
              data-marca-id="${marca.id}"
              data-loaded="false">

            <div class="brand-slider-header">
              <div class="brand-slider-brand">
                ${logoHtml}
                <h2>${marca.nome}</h2>
              </div>
              <a href="${BASE_URL}/marca/${marca.slug}" class="brands-ver-tudo">
                Ver tudo
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                  <line x1="5" y1="12" x2="19" y2="12"/>
                  <polyline points="12 5 19 12 12 19"/>
                </svg>
              </a>
            </div>

            <div class="brand-products-slider-wrap">
              <button type="button" class="bps-nav bps-nav--prev" aria-label="Anterior">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                  <polyline points="15 18 9 12 15 6"/>
                </svg>
              </button>
              <div class="bps-overflow">
                <div class="bps-track" data-offset="0" data-total="0" data-loading="false">
                  ${skeletons}
                </div>
              </div>
              <button type="button" class="bps-nav bps-nav--next" aria-label="Próximo">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                  <polyline points="9 18 15 12 9 6"/>
                </svg>
              </button>
            </div>

            <div class="bps-ver-mais" style="display:none;">
              <a href="${BASE_URL}/marca/${marca.slug}" class="btn btn-outline">
                Ver todos os produtos da ${marca.nome}
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                  <line x1="5" y1="12" x2="19" y2="12"/>
                  <polyline points="12 5 19 12 12 19"/>
                </svg>
              </a>
            </div>
          </div>`;
      }

      // ── Carrega produtos de uma marca no slider ───────────
      function loadBrandProducts($block, offset = 0) {
        const marcaId = $block.data('marca-id');
        const $track  = $block.find('.bps-track');
        const $nav    = $block.find('.bps-nav');

        if ($track.data('loading')) return;
        $track.data('loading', true);

        $.get(BASE_URL + '/marcas/produtos', {
          marca_id: marcaId,
          offset  : offset,
        }, function (res) {
          $track.data('loading', false);

          if (!res.ok || !res.items.length) {
            if (offset === 0) $block.hide();
            return;
          }

          // Na primeira carga, limpa skeletons
          if (offset === 0) $track.empty();

          // Adiciona cards
          res.items.forEach(item => {
            $track.append(buildProductCard(item));
          });

          // Atualiza estado
          $track.data('offset', offset + res.items.length);
          $track.data('total', res.total);
          $block.data('loaded', true);

          // Exibe botão ver mais se >20 produtos
          if (res.has_page) {
            $block.find('.bps-ver-mais').show();
          }

          // Atualiza setas
          updateNavBtns($block);

        }, 'json');
      }

      // ── Atualiza estado das setas ─────────────────────────
      function updateNavBtns($block) {
        const $overflow = $block.find('.bps-overflow');
        const $track    = $block.find('.bps-track');
        const scrollLeft = $overflow[0].scrollLeft;
        const maxScroll  = $track[0].scrollWidth - $overflow[0].clientWidth;

        $block.find('.bps-nav--prev').prop('disabled', scrollLeft <= 0);
        $block.find('.bps-nav--next').prop('disabled', scrollLeft >= maxScroll - 4);
      }

      // ── Scroll horizontal com botões ──────────────────────
      $(document).on('click', '.bps-nav', function () {
        const $block    = $(this).closest('.brand-slider-block');
        const $overflow = $block.find('.bps-overflow');
        const dir       = $(this).hasClass('bps-nav--prev') ? -1 : 1;
        const cardW     = $block.find('.bps-card').first().outerWidth(true) || 200;
        const scrollBy  = cardW * 3;

        $overflow.animate({ scrollLeft: $overflow.scrollLeft() + dir * scrollBy }, 280);
      });

      // ── Infinite scroll lateral: carrega mais ao chegar no fim ──
      $(document).on('scroll', '.bps-overflow', function () {
        const $overflow = $(this);
        const $block    = $overflow.closest('.brand-slider-block');
        const $track    = $block.find('.bps-track');

        const scrollLeft  = $overflow[0].scrollLeft;
        const scrollWidth = $track[0].scrollWidth;
        const clientWidth = $overflow[0].clientWidth;
        const offset      = parseInt($track.data('offset') || 0);
        const total       = parseInt($track.data('total')  || 0);

        updateNavBtns($block);

        // Se chegou perto do fim e ainda tem mais (até limite 20)
        if (scrollLeft + clientWidth >= scrollWidth - 100) {
          if (offset < Math.min(total, LIMIT_SLIDER) && !$track.data('loading')) {
            loadBrandProducts($block, offset);
          }
        }
      });

      // ── Intersection Observer: carrega quando entra na tela ──
      const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
          if (!entry.isIntersecting) return;
          const $block = $(entry.target);

          // Anima entrada
          $block.addClass('visible');

          // Carrega produtos se ainda não carregou
          if ($block.data('loaded') === false || $block.data('loaded') === 'false') {
            loadBrandProducts($block, 0);
          }

          observer.unobserve(entry.target);
        });
      }, { threshold: 0.1 });

      function observeBlocks() {
        document.querySelectorAll('.brand-slider-block:not([data-observed])').forEach(el => {
          el.setAttribute('data-observed', '1');
          observer.observe(el);
        });
      }

      // ── Botão carregar mais marcas ────────────────────────
      $('#btn-load-more-brands').on('click', function () {
        const $btn    = $(this);
        const offset  = parseInt($btn.data('offset'));
        const $dynWrap = $('#brands-sliders-dynamic');

        $btn.prop('disabled', true).html(`
          <svg class="spin" width="15" height="15" viewBox="0 0 24 24" fill="none"
              stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <path d="M21 12a9 9 0 11-6.219-8.56"/>
          </svg>
          Carregando...
        `);

        $.get(BASE_URL + '/marcas/load-more', { offset }, function (res) {
          if (!res.ok) return;

          res.marcas.forEach(marca => {
            const html = buildBrandBlock(marca);
            $dynWrap.append(html);
          });

          // Re-observa novos blocos
          observeBlocks();

          const newOffset = offset + res.marcas.length;
          $btn.data('offset', newOffset);

          if (res.has_more) {
            $btn.prop('disabled', false).html(`
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
                  stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                <polyline points="7 10 12 15 17 10"/>
              </svg>
              Carregar mais marcas
            `);
          } else {
            $('#brands-load-more-wrap').fadeOut(300);
          }
        }, 'json');
      });

      // Dentro do IIFE da página de marcas, adicione após o observeBlocks():

      // Observer para os cards de marca no grid
      const cardObserver = new IntersectionObserver((entries) => {
        entries.forEach((entry, i) => {
          if (!entry.isIntersecting) return;
          // Delay escalonado para efeito cascata
          setTimeout(() => {
            entry.target.classList.add('visible');
          }, i * 50);
          cardObserver.unobserve(entry.target);
        });
      }, { threshold: 0.05 });

      document.querySelectorAll('.brand-page-card').forEach(el => {
        cardObserver.observe(el);
      });

      // ── Inicia ────────────────────────────────────────────
      observeBlocks();

    })();

    /**
   * sm-reviews.js — Módulo de Avaliações
   * Destino: assets/js/reviews.js
   * Conecta ao backend PHP via AJAX
   */
  (function () {
    // 'use strict';

    if (!window.SM_REVIEWS_CONFIG) return;

    const CFG      = window.SM_REVIEWS_CONFIG;
    const BASE     = CFG.baseUrl;
    const PRODUTO  = CFG.produtoId;

    const FILTERS  = [
      { key:'todas',  label:'Todas' },
      { key:'fotos',  label:'📷 Com fotos' },
      { key:'videos', label:'▶ Com vídeos' },
      // { key:'5',      label:'★ 5 estrelas' },
      // { key:'4',      label:'★ 4 estrelas' },
      // { key:'3',      label:'★ 3 estrelas' },
      // { key:'2',      label:'★ 2 estrelas' },
      // { key:'1',      label:'★ 1 estrela' },
    ];

    const STAR_HINTS = ['Péssimo','Ruim','Regular','Bom','Excelente'];

    let state = {
      filtro: 'todas',
      ordem:  'recentes',
      page:   1,
      hasMore:false,
      lbMedia:[],
      lbIndex:0,
      rating:  0,
      uploadToken: '',
      uploadFiles: [],
      uploadCount: 0,
    };

    /* ── Helpers ─────────────────────────────────────────── */
    function esc(s) { return $('<div>').text(s || '').html(); }

    function starsHtml(nota, size) {
      size = size || 16;
      const full  = Math.floor(nota);
      const half  = (nota - full) >= .5 ? 1 : 0;
      const empty = 5 - full - half;
      const svg   = `<svg viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>`;
      let h = '';
      for (let i=0;i<full;i++)  h += `<span class="sm-star sm-star--full"  style="width:${size}px;height:${size}px;">${svg}</span>`;
      if (half)                  h += `<span class="sm-star sm-star--half"  style="width:${size}px;height:${size}px;">${svg}</span>`;
      for (let i=0;i<empty;i++) h += `<span class="sm-star sm-star--empty" style="width:${size}px;height:${size}px;">${svg}</span>`;
      return h;
    }

    function fmtNum(n) { return n >= 1000 ? (n/1000).toFixed(1)+'k' : String(n||0); }

    /* ── Carregar avaliações ─────────────────────────────── */
    function carregar(append) {
      if (!append) {
        state.page = 1;
        $('#sm-reviews-list').html('<div class="sm-reviews-empty"><div class="sm-reviews-loading-spinner"></div></div>');
      }

      $.get(`${BASE}/avaliacoes`, {
        produto_id: PRODUTO,
        page:       state.page,
        filtro:     state.filtro,
        ordem:      state.ordem,
      }, function (res) {
        if (!res.ok) return;

        // Resumo (apenas na primeira carga)
        if (state.page === 1 && !append) {
          renderResumo(res.resumo);
          if (res.midias) renderGaleria(res.midias);
        }

        state.hasMore = res.has_more;

        if (state.page === 1) $('#sm-reviews-list').empty();

        if (!res.reviews.length && state.page === 1) {
          $('#sm-reviews-list').html('<div class="sm-reviews-empty">Seja o primeiro a avaliar este produto!</div>');
          $('#sm-load-more').hide();
          return;
        }

        res.reviews.forEach((r, idx) => {
          const delay = (idx + (append ? 0 : 0)) * 50;
          $('#sm-reviews-list').append(buildReviewHtml(r, delay));
        });

        $('#sm-load-more').toggle(res.has_more);

      }, 'json');
    }

    /* ── Resumo ──────────────────────────────────────────── */
    function renderResumo(r) {
      if(r && r.total == 0) {
        const media = parseFloat(r.media);
        const total = parseInt(r.total);
        
        $('#sm-reviews-head-count').text(`Sem avaliações ainda`);
      }
          
      if (!r || !r.total) return;

      const media = parseFloat(r.media);
      const total = parseInt(r.total);

      $('#sm-score-num').text(media.toFixed(1));
      $('#sm-score-stars').html(starsHtml(media, 20));
      $('#sm-score-total').text(`${total} avaliação${total!==1?'es':''}`);
      $('#sm-reviews-head-count').text(`${total} avaliação${total!==1?'es':''}`);

      // Barras
      [5,4,3,2,1].forEach(n => {
        const cnt = parseInt(r['n'+n] || 0);
        const pct = total ? (cnt/total)*100 : 0;
        $(`.sm-reviews-bar-count-${n}`).text(cnt);
        // Anima
        setTimeout(() => {
          $(`.sm-reviews-bar-row[data-filter="${n}"] .sm-reviews-bar-fill`)
            .css('width', pct.toFixed(1)+'%');
        }, 100);
      });
    }

    /* ── Galeria global ──────────────────────────────────── */
    function renderGaleria(midias) {
      if (!midias.length) { $('#sm-media-strip').hide(); return; }
      $('#sm-media-strip').show();
      const $scroll = $('#sm-media-scroll').empty();

      midias.forEach((m, idx) => {
        let html;
        if (m.tipo === 'video') {
          const poster = m.thumb_url || '';
          html = `<div class="sm-media-thumb" data-lb-idx="${idx}">
            <img src="${esc(poster)}" alt="" loading="lazy">
            <div class="sm-media-thumb-play"><svg viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3" fill="white"/></svg></div>
          </div>`;
        } else {
          html = `<div class="sm-media-thumb" data-lb-idx="${idx}">
            <img src="${esc(m.url)}" alt="" loading="lazy">
          </div>`;
        }
        $scroll.append(html);
      });

      state.lbMedia = midias;
      $scroll.on('click', '.sm-media-thumb', function() {
        openLightbox(parseInt($(this).data('lb-idx')));
      });
    }

    /* ── Filtros ─────────────────────────────────────────── */
    function renderFilters() {
      const $wrap = $('#sm-filters').empty();
      FILTERS.forEach(f => {
        const cls = f.key === state.filtro ? 'is-active' : '';
        $wrap.append(`<button class="sm-filter-pill ${cls}" data-key="${f.key}">${esc(f.label)}</button>`);
      });
    }

    /* ── Build HTML de avaliação ─────────────────────────── */
    function buildReviewHtml(r, delay) {
      const votedCls = r.votou ? 'is-voted' : '';
      const isLong   = (r.comentario||'').length > 260;
      const featCls  = r.destaque ? 'sm-featured' : '';

      // Mídias
      let midiasHtml = '';
      if (r.midias_fmt && r.midias_fmt.length) {
        const items = r.midias_fmt.map((m, mi) => {
          const key = `${r.id}-${mi}`;
          if (m.tipo === 'video') {
            const poster = m.thumb_url ? `<img src="${esc(m.thumb_url)}" alt="" loading="lazy">` : '';
            return `<div class="sm-review-media-item" data-media-key="${key}" data-media-url="${esc(m.url)}" data-media-tipo="video">
              ${poster}
              <div class="sm-review-media-play"><svg viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3" fill="white"/></svg></div>
            </div>`;
          }
          return `<div class="sm-review-media-item" data-media-key="${key}" data-media-url="${esc(m.url)}" data-media-tipo="imagem">
            <img src="${esc(m.url)}" alt="" loading="lazy">
          </div>`;
        }).join('');
        midiasHtml = `<div class="sm-review-media">${items}</div>`;
      }

      const titulo    = r.titulo ? `<div class="sm-review-title">${esc(r.titulo)}</div>` : '';
      const bodyTrunc = isLong ? 'sm-review-body is-truncated' : 'sm-review-body';
      const readMore  = isLong ? `<button class="sm-review-read-more">Ler mais ↓</button>` : '';
      const verified  = r.verificado ? `<span class="sm-review-verified">
        <svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        Compra verificada</span>` : '';
      const badge     = r.destaque ? `<span class="sm-review-badge-featured">✦ Destaque</span>` : '';

      return `
      <div class="sm-review-item ${featCls}" data-rv-id="${r.id}" style="animation-delay:${delay}ms">
        <div class="sm-review-header">
          <div class="sm-review-avatar">${esc((r.nome_exibido||'?').charAt(0).toUpperCase())}</div>
          <div class="sm-review-meta">
            <div class="sm-review-name">${esc(r.nome_exibido)}</div>
            <div class="sm-review-stars-date">
              <div class="sm-review-stars">${starsHtml(parseInt(r.nota), 14)}</div>
              <span class="sm-review-date">${esc(r.data_fmt)}</span>
            </div>
            ${verified}
          </div>
        </div>
        ${titulo}
        <div class="${bodyTrunc}">${esc(r.comentario)}</div>
        ${readMore}
        ${midiasHtml}
        <div class="sm-review-footer">
          <div class="sm-review-useful">
            <span>Útil?</span>
            <button class="sm-review-useful-btn ${votedCls}" data-id="${r.id}">
              <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">
                <path d="M14 9V5a3 3 0 00-3-3l-4 9v11h11.28a2 2 0 002-1.7l1.38-9a2 2 0 00-2-2.3H14z"/>
                <path d="M7 22H4a2 2 0 01-2-2v-7a2 2 0 012-2h3"/>
              </svg>
              Sim <span class="sm-review-useful-count">${fmtNum(r.util_sim)}</span>
            </button>
          </div>
          ${badge}
        </div>
      </div>`;
    }

    /* ── Lightbox ────────────────────────────────────────── */
    function openLightbox(idx) {
      state.lbIndex = idx;
      renderLbItem();
      $('#sm-lightbox').addClass('is-open');
      $('body').css('overflow','hidden');
    }

    function renderLbItem() {
      const item = state.lbMedia[state.lbIndex];
      if (!item) return;
      $('#sm-lb-img').hide(); $('#sm-lb-video').hide();
      const $v = $('#sm-lb-video')[0]; if ($v) $v.pause();

      if (item.tipo === 'video') {
        $('#sm-lb-video').attr('src', item.url).show()[0].load();
      } else {
        $('#sm-lb-img').attr('src', item.url).show();
      }

      const total = state.lbMedia.length;
      $('#sm-lb-counter').text(`${state.lbIndex+1} / ${total}`);
      $('#sm-lb-prev').toggle(state.lbIndex > 0);
      $('#sm-lb-next').toggle(state.lbIndex < total-1);
    }

    function closeLightbox() {
      $('#sm-lightbox').removeClass('is-open');
      $('body').css('overflow','');
      const $v = $('#sm-lb-video')[0]; if ($v) { $v.pause(); $v.src=''; }
    }

    /* ── Upload de mídias ────────────────────────────────── */
    function handleFiles(files) {
      const MAX = 5;
      if (state.uploadFiles.length >= MAX) {
        notifyToast('Máximo de 5 arquivos por avaliação.'); return;
      }

      Array.from(files).slice(0, MAX - state.uploadFiles.length).forEach(file => {
        const isImg = file.type.startsWith('image/');
        const isVid = file.type.startsWith('video/');
        if (!isImg && !isVid) { notifyToast('Formato não suportado: ' + file.name); return; }
        if (isImg && file.size > 5*1024*1024) { notifyToast('Imagem muito grande (máx. 5MB).'); return; }
        if (isVid && file.size > 30*1024*1024) { notifyToast('Vídeo muito grande (máx. 30MB).'); return; }

        state.uploadFiles.push(file);
        const localIdx = state.uploadFiles.length - 1;

        // Preview local
        const reader = new FileReader();
        reader.onload = e => {
          const media = isVid
            ? `<video src="${e.target.result}" muted></video>`
            : `<img src="${e.target.result}" alt="">`;
          const $item = $(`<div class="sm-upload-preview-item" data-local-idx="${localIdx}">
            ${media}
            <button class="sm-upload-preview-remove" data-local-idx="${localIdx}">
              <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
          </div>`);
          $('#sm-upload-previews').append($item);
        };
        reader.readAsDataURL(file);

        // Upload para o servidor
        uploadFile(file, localIdx);
      });
    }

    function uploadFile(file, localIdx) {
      // Progress item
      const $prog = $(`<div class="sm-upload-progress-item" id="prog-${localIdx}">
        <div class="sm-upload-progress-info">
          <span class="sm-upload-progress-name">${esc(file.name)}</span>
          <span class="sm-upload-progress-status">Enviando…</span>
        </div>
        <div class="sm-upload-progress-track">
          <div class="sm-upload-progress-bar" id="bar-${localIdx}"></div>
        </div>
      </div>`);
      $('#sm-upload-progress').show().append($prog);

      const fd = new FormData();
      fd.append('midia', file);
      fd.append('token', state.uploadToken);
      fd.append('_csrf_token', CFG.csrfToken);

      // Simula progresso enquanto faz o upload real
      let pct = 0;
      const progressInterval = setInterval(() => {
        pct = Math.min(pct + 12, 85);
        $(`#bar-${localIdx}`).css('width', pct + '%');
      }, 150);

      $.ajax({
        url:         `${BASE}/avaliacoes/upload-midia`,
        type:        'POST',
        data:        fd,
        processData: false,
        contentType: false,
        success: function (res) {
          clearInterval(progressInterval);
          if (res.ok) {
            state.uploadToken = res.token; // token unificador das mídias
            $(`#bar-${localIdx}`).css('width','100%');
            $(`#prog-${localIdx}`).addClass('is-done');
            $(`#prog-${localIdx} .sm-upload-progress-status`).text('Concluído ✓');
          } else {
            $(`#prog-${localIdx}`).addClass('is-error');
            $(`#prog-${localIdx} .sm-upload-progress-status`).text(res.msg || 'Erro');
            notifyToast(res.msg || 'Erro no upload.');
          }
        },
        error: function () {
          clearInterval(progressInterval);
          $(`#prog-${localIdx}`).addClass('is-error');
          $(`#prog-${localIdx} .sm-upload-progress-status`).text('Falhou');
        },
      });
    }

    /* ── Submit da avaliação ─────────────────────────────── */
    function submitAvaliacao() {
      const nota      = state.rating;
      const nome      = $('#sm-write-name').val();
      const titulo    = $('#sm-write-title').val();
      const comentario= $('#sm-write-body').val();

      if (!nota)      { notifyToast('Selecione uma nota.'); return; }
      if (!CFG.isLogado && !nome) { notifyToast('Informe seu nome.'); return; }
      if (!comentario){ notifyToast('Escreva um comentário.'); return; }

      const $btn = $('#sm-write-submit').prop('disabled', true).text('Enviando…');

      const fd = new FormData();
      fd.append('_csrf_token', CFG.csrfToken);
      fd.append('produto_id',  PRODUTO);
      fd.append('nota',        nota);
      fd.append('titulo',      titulo);
      fd.append('comentario',  comentario);
      fd.append('nome',        nome);
      fd.append('upload_token', state.uploadToken);

      $.ajax({
        url: `${BASE}/avaliacoes/enviar`, type:'POST', data:fd,
        processData:false, contentType:false,
        success: function (res) {
          $btn.prop('disabled', false).text('Enviar avaliação');
          if (res.ok) {
            closeWriteModal();
            notifyToast(res.msg || 'Avaliação enviada!');
            if (res.aprovado) {
              // Recarrega a lista
              setTimeout(() => { state.filtro='todas'; state.ordem='recentes'; carregar(false); }, 800);
            }
            resetForm();
          } else {
            notifyToast(res.msg || 'Erro ao enviar.');
          }
        },
        error: function() {
          $btn.prop('disabled',false).text('Enviar avaliação');
          notifyToast('Erro de conexão.');
        },
      });
    }

    function resetForm() {
      state.rating = 0; state.uploadToken = ''; state.uploadFiles = [];
      highlightStars(0);
      $('#sm-star-hint, #sm-write-name, #sm-write-title, #sm-write-body').val('');
      $('#sm-star-hint').text('');
      $('#sm-upload-previews, #sm-upload-progress').empty();
      $('#sm-upload-progress').hide();
      $('#sm-nota-val').val(0);
    }

    function highlightStars(val) {
      $('#sm-star-picker .sm-star-picker-star').each(function() {
        $(this).toggleClass('is-active', parseInt($(this).data('val')) <= val);
      });
    }

    function openWriteModal()  { $('#sm-write-modal').addClass('is-open'); $('body').css('overflow','hidden'); }
    function closeWriteModal() { $('#sm-write-modal').removeClass('is-open'); $('body').css('overflow',''); }

    /* ── Init ────────────────────────────────────────────── */
    function init() {
      renderFilters();
      carregar(false);
      bindEvents();
    }

    function bindEvents() {
      // Filtros pelas barras
      $('#sm-reviews-bars').on('click', '.sm-reviews-bar-row', function() {
        state.filtro = $(this).data('filter').toString();
        renderFilters(); carregar(false);
      });

      // Pills de filtro
      $('#sm-filters').on('click', '.sm-filter-pill', function() {
        state.filtro = $(this).data('key');
        renderFilters(); carregar(false);
      });

      // Ordenação
      $('#sm-sort').on('change', function() {
        state.ordem = $(this).val(); carregar(false);
      });

      // Abrir modal
      $('#sm-write-btn').on('click', openWriteModal);
      $('#sm-write-close').on('click', closeWriteModal);
      $('#sm-write-modal').on('click', function(e) {
        if ($(e.target).is('#sm-write-modal')) closeWriteModal();
      });

      // Lightbox
      $('#sm-lb-close').on('click', closeLightbox);
      $('#sm-lightbox').on('click', function(e) {
        if ($(e.target).is('#sm-lightbox')) closeLightbox();
      });
      $('#sm-lb-prev').on('click', () => { if (state.lbIndex>0) { state.lbIndex--; renderLbItem(); }});
      $('#sm-lb-next').on('click', () => { if (state.lbIndex<state.lbMedia.length-1) { state.lbIndex++; renderLbItem(); }});
      $(document).on('keydown', function(e) {
        if (!$('#sm-lightbox').hasClass('is-open')) return;
        if (e.key==='Escape')     closeLightbox();
        if (e.key==='ArrowLeft')  $('#sm-lb-prev').trigger('click');
        if (e.key==='ArrowRight') $('#sm-lb-next').trigger('click');
      });

      // Clicar em mídia da review → lightbox
      $('#sm-reviews-list').on('click', '.sm-review-media-item', function() {
        const $item = $(this);
        // Coleta todas as mídias do mesmo review
        const $rv   = $item.closest('.sm-review-item');
        const items = $rv.find('.sm-review-media-item');
        state.lbMedia = [];
        items.each(function() {
          state.lbMedia.push({
            tipo: $(this).data('media-tipo'),
            url:  $(this).data('media-url'),
          });
        });
        openLightbox(items.index($item));
      });

      // Star picker
      $('#sm-star-picker').on('mouseenter', '.sm-star-picker-star', function() {
        highlightStars(parseInt($(this).data('val')));
        $('#sm-star-hint').text(STAR_HINTS[parseInt($(this).data('val'))-1]);
      }).on('mouseleave', function() {
        highlightStars(state.rating);
        $('#sm-star-hint').text(state.rating ? STAR_HINTS[state.rating-1] : '');
      }).on('click', '.sm-star-picker-star', function() {
        state.rating = parseInt($(this).data('val'));
        $('#sm-nota-val').val(state.rating);
        highlightStars(state.rating);
        $('#sm-star-hint').text(STAR_HINTS[state.rating-1]);
      });

      // Upload drag & drop
      const $zone = $('#sm-upload-zone');
      $zone.on('dragover', function(e) {
        e.preventDefault(); $(this).addClass('is-dragover');
      }).on('dragleave drop', function(e) {
        e.preventDefault(); $(this).removeClass('is-dragover');
        if (e.type==='drop') handleFiles(e.originalEvent.dataTransfer.files);
      });
      $('#sm-upload-input').on('change', function() {
        handleFiles(this.files); this.value='';
      });

      // Remover preview
      $('#sm-upload-previews').on('click', '.sm-upload-preview-remove', function() {
        const idx = parseInt($(this).data('local-idx'));
        state.uploadFiles.splice(idx, 1);
        $(this).closest('.sm-upload-preview-item').remove();
      });

      // Submit
      $('#sm-write-form').on('submit', function(e) { e.preventDefault(); submitAvaliacao(); });

      // Ler mais
      $('#sm-reviews-list').on('click', '.sm-review-read-more', function() {
        $(this).prev('.sm-review-body').removeClass('is-truncated');
        $(this).remove();
      });

      // Útil
      $('#sm-reviews-list').on('click', '.sm-review-useful-btn', function() {
        const id   = $(this).data('id');
        const $btn = $(this);
        const $cnt = $btn.find('.sm-review-useful-count');
        const wasVoted = $btn.hasClass('is-voted');

        // Otimistic UI
        $btn.toggleClass('is-voted');
        $cnt.text(fmtNum((parseInt($cnt.text())||0) + (wasVoted?-1:1)));

        $.post(`${BASE}/avaliacoes/util`, { id, _csrf_token: CFG.csrfToken }, function(res) {
          if (res.ok) {
            $cnt.text(fmtNum(res.total));
            $btn.toggleClass('is-voted', res.votou);
          } else {
            $btn.toggleClass('is-voted', wasVoted);
          }
        }, 'json');
      });

      // Carregar mais
      $('#sm-load-btn').on('click', function() {
        state.page++;
        carregar(true);
      });
    }

    $(document).ready(init);

  }());



  //QA = Perguntas e respostas
  (function () {
    'use strict';
    if (!window.QA_CONFIG) return;

    const CFG  = window.QA_CONFIG;
    const BASE = CFG.baseUrl;

    // ── Estado global ─────────────────────────────────────
    let _total      = 0;   // total de perguntas
    let _paginaAtual = 1;  // página atual (lista principal, máx 4)

    // ── Estado do modal "todas as perguntas" ─────────────
    const Modal = {
      page:    1,
      perPage: 10,
      loading: false,
      hasMore: true,
      observer: null,

      open() {
        this.page    = 1;
        this.loading = false;
        this.hasMore = true;

        $('#qa-all-list').empty();
        $('#qa-all-loading').show();
        $('#qa-all-modal').prop('hidden', false);
        $('body').css('overflow', 'hidden');

        // Copia as perguntas já carregadas para não re-fetch
        const $items = $('#qa-list .qa-item');
        if ($items.length) {
          $items.clone().appendTo('#qa-all-list');
          // Se já temos a primeira página, pula para a 2
          this.page = 2;
          this.loading = false;
          $('#qa-all-loading').hide();
          if (_total > CFG.perPageInicial) {
            this._carregarMais();
          }
        } else {
          this._carregarMais();
        }

        this._initObserver();
      },

      fechar() {
        $('#qa-all-modal').prop('hidden', true);
        $('body').css('overflow', '');
        if (this.observer) {
          this.observer.disconnect();
          this.observer = null;
        }
      },

      _carregarMais() {
        if (this.loading || !this.hasMore) return;
        this.loading = true;
        $('#qa-all-loading').show();

        $.get(BASE + '/perguntas', {
          produto_id: CFG.produtoId,
          page:       this.page,
          per_page:   this.perPage,
        }, (res) => {
          this.loading = false;
          $('#qa-all-loading').hide();

          if (!res.ok) return;

          this.hasMore = res.has_more;
          this.page++;

          res.perguntas.forEach((p, i) => {
            const $item = $(buildItem(p));
            $item.css('animation-delay', (i * 40) + 'ms');
            $('#qa-all-list').append($item);
          });

          if (!this.hasMore) {
            $('#qa-all-sentinel').hide();
          }
        }, 'json').fail(() => {
          this.loading = false;
          $('#qa-all-loading').hide();
        });
      },

      _initObserver() {
        const sentinel = document.getElementById('qa-all-sentinel');
        if (!sentinel) return;

        this.observer = new IntersectionObserver((entries) => {
          if (entries[0].isIntersecting && !this.loading && this.hasMore) {
            this._carregarMais();
          }
        }, {
          root:       document.getElementById('qa-all-scroll'),
          rootMargin: '120px',
          threshold:  0,
        });

        this.observer.observe(sentinel);
      },
    };

    // ── Carregar perguntas na página do produto (4 iniciais) ─
    function carregar() {
      $.get(BASE + '/perguntas', {
        produto_id: CFG.produtoId,
        page:       1,
        per_page:   CFG.perPageInicial || 4,
      }, (res) => {
        const $list = $('#qa-list').empty();
        _total = res.total || 0;

        if (!res.ok || !res.perguntas.length) {
          $list.html(`
            <div class="qa-empty">
              <strong>Ainda não há perguntas</strong>
              Seja o primeiro a perguntar sobre este produto.
            </div>`);
          atualizarBotaoVerMais();
          return;
        }

        res.perguntas.forEach(p => $list.append(buildItem(p)));
        atualizarBotaoVerMais();
      }, 'json');
    }

    function atualizarBotaoVerMais() {
      const perPage = CFG.perPageInicial || 4;
      const $wrap   = $('#qa-ver-mais-wrap');
      if (_total > perPage) {
        $wrap.find('.qa-ver-mais-count').text(_total - perPage);
        $wrap.show();
      } else {
        $wrap.hide();
      }
    }

    // ── Builder do item de pergunta ───────────────────────
    function esc(s) { return $('<div>').text(s || '').html(); }

    function buildItem(p) {
      const isMine    = p.minha;
      const isPending = p.status === 'aguardando_admin';
      const isAdmin   = p.resposta_fonte === 'admin';
      const isIa      = p.resposta_fonte === 'ia';

      let answerHtml = '';
      if (p.resposta) {
        const fonteCls  = isAdmin ? 'is-admin' : 'is-ia';
        const fonteIcon = isAdmin
          ? `<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>`
          : `<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 010 14.14M4.93 4.93a10 10 0 000 14.14"/></svg>`;
        const fonteLabel = isAdmin ? 'Especialista' : 'Resposta automática';

        answerHtml = `
          <div class="qa-a ${isIa ? 'is-ia' : ''}">
            <div class="qa-a-icon">A</div>
            <div class="qa-a-body">
              <div class="qa-a-text">${esc(p.resposta)}</div>
              <div class="qa-a-meta">
                <span class="qa-a-fonte ${fonteCls}">${fonteIcon} ${fonteLabel}</span>
                <span>· ${esc(p.data_fmt)}</span>
              </div>
            </div>
          </div>`;
      } else if (isPending) {
        answerHtml = `
          <div class="qa-pendente">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
              <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
            </svg>
            Aguardando resposta de um especialista
          </div>`;
      }

      let mineBadge = '';
      if (isMine) {
        const tip = p.feita_anonima
          ? 'Esta pergunta foi feita por você antes de fazer login. Vinculamos pelo seu e-mail.'
          : 'Esta pergunta foi feita por você.';
        mineBadge = `
          <span class="qa-mine-badge" tabindex="0">
            Sua pergunta
            <span class="qa-tooltip">${esc(tip)}</span>
          </span>`;
      }

      const utilHtml = p.resposta ? `
        <div class="qa-actions">
          <button type="button"
                  class="qa-util-btn ${p.votou_util ? 'is-voted' : ''}"
                  data-id="${p.id}">
            <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">
              <path d="M14 9V5a3 3 0 00-3-3l-4 9v11h11.28a2 2 0 002-1.7l1.38-9a2 2 0 00-2-2.3H14z"/>
              <path d="M7 22H4a2 2 0 01-2-2v-7a2 2 0 012-2h3"/>
            </svg>
            Útil <span class="qa-util-count">${p.util_count || 0}</span>
          </button>
        </div>` : '';

      return `
        <div class="qa-item ${isMine ? 'is-mine' : ''}" data-id="${p.id}">
          <div class="qa-q">
            <div class="qa-q-icon">P</div>
            <div class="qa-q-body">
              <div class="qa-q-text">${esc(p.pergunta)}</div>
              <div class="qa-q-meta">
                <span class="qa-q-author">${esc(p.autor_nome)}</span>
                <span>· ${esc(p.data_fmt)}</span>
                ${mineBadge}
              </div>
            </div>
          </div>
          ${answerHtml}
          ${utilHtml}
        </div>`;
    }

    // ── Botão "Útil" (delegado — funciona em lista e modal) ─
    $(document).on('click', '.qa-util-btn', function () {
      const id      = $(this).data('id');
      const $btn    = $(this);
      const $count  = $btn.find('.qa-util-count');
      const wasVoted = $btn.hasClass('is-voted');

      $btn.toggleClass('is-voted');
      $count.text(parseInt($count.text()) + (wasVoted ? -1 : 1));

      $.post(BASE + '/perguntas/util', { id, _csrf_token: CFG.csrfToken }, (res) => {
        if (res.ok) {
          $count.text(res.util_count);
          $btn.toggleClass('is-voted', res.votou);
          // Sincroniza o item correspondente na outra lista (modal ↔ página)
          $(`.qa-util-btn[data-id="${id}"]`).each(function () {
            $(this).toggleClass('is-voted', res.votou);
            $(this).find('.qa-util-count').text(res.util_count);
          });
        } else {
          $btn.toggleClass('is-voted', wasVoted);
          $count.text(Math.abs(parseInt($count.text()) + (wasVoted ? 1 : -1)));
        }
      }, 'json');
    });

    // ── Abrir modal com todas as perguntas ────────────────
    $('#qa-btn-ver-mais, #qa-ver-mais-btn').on('click', () => Modal.open());

    $(document).on('click', '#qa-all-modal-close, .qa-all-backdrop', () => Modal.fechar());
    $(document).on('keydown', (e) => {
      if (e.key === 'Escape' && !$('#qa-all-modal').prop('hidden')) Modal.fechar();
    });

    // ── Botão "Fazer pergunta" ────────────────────────────
    function abrirFormPergunta() {
      // Fecha modal se estiver aberto e abre o de pergunta
      Modal.fechar();
      $('#qa-form')[0]?.reset();
      $('#qa-modal').prop('hidden', false);
      $('body').css('overflow', 'hidden');
    }
    $('#qa-btn-ask, #qa-all-btn-ask').on('click', abrirFormPergunta);

    function fecharModalPergunta() {
      $('#qa-modal').prop('hidden', true);
      $('body').css('overflow', '');
    }
    $('#qa-modal-close, .qa-modal-backdrop').on('click', fecharModalPergunta);
    $(document).on('keydown', (e) => {
      if (e.key === 'Escape' && !$('#qa-modal').prop('hidden')) fecharModalPergunta();
    });

    // Counter do textarea
    $('#qa-form textarea[name="pergunta"]').on('input', function () {
      $('#qa-counter-num').text(this.value.length);
    });

    // ── Submit do form ────────────────────────────────────
    $('#qa-form').on('submit', function (e) {
      e.preventDefault();
      const $btn = $('#qa-submit');
      $btn.prop('disabled', true).text('Enviando…');

      $.post(BASE + '/perguntas/enviar', $(this).serialize(), (res) => {
        $btn.prop('disabled', false).text('Enviar pergunta');

        if (!res.ok) { alert(res.msg || 'Erro.'); return; }

        $('#qa-form').hide();
        const $r = $('#qa-result').prop('hidden', false);

        if (res.fonte === 'ia') {
          $('#qa-result-icon').addClass('is-ia').html(`
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <polyline points="20 6 9 17 4 12"/>
            </svg>`);
          $('#qa-result-title').text('Resposta encontrada!');
          $('#qa-result-msg').text('Veja abaixo a resposta gerada:');
          $('#qa-result-answer').prop('hidden', false).text(res.resposta);
        } else {
          $('#qa-result-icon').addClass('is-admin').html(`
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <circle cx="12" cy="12" r="10"/>
              <polyline points="12 6 12 12 16 14"/>
            </svg>`);
          $('#qa-result-title').text('Pergunta encaminhada!');
          $('#qa-result-msg').text(res.msg || 'Você receberá a resposta por e-mail em até 24h.');
          $('#qa-result-answer').prop('hidden', true);
        }

        // Re-carrega a lista com a nova pergunta
        setTimeout(() => {
          fecharModalPergunta();
          carregar();
          // Se o modal estava aberto, atualiza também
          if (!$('#qa-all-modal').prop('hidden')) {
            Modal.open();
          }
        }, 1200);
      }, 'json').fail(() => {
        $btn.prop('disabled', false).text('Enviar pergunta');
        alert('Erro de conexão.');
      });
    });

    $('#qa-result-close').on('click', fecharModalPergunta);

    // ── Init ─────────────────────────────────────────────
    $(document).ready(carregar);

  }());

    // Clique no botão de favorito — usa toggle
    // $(document).on('click', '.btn-wishlist', function (e) {
    //   e.preventDefault();
    //   e.stopPropagation();

    //   const $btn      = $(this);
    //   const productId = $btn.data('product-id');
    //   const favoritado = $btn.hasClass('active');

    //   // Feedback visual imediato (otimista)
    //   $btn.toggleClass('active', !favoritado);
    //   $btn.attr('aria-pressed', (!favoritado).toString());

    //   $('body').find('.btn-wishlist[data-product-id="' + productId + '"]').each(function () {
    //     $(this).toggleClass('active', !favoritado);
    //     $(this).attr('aria-pressed', (!favoritado).toString());
    //     $(this).attr('title', !favoritado ? 'Remover dos favoritos' : 'Adicionar aos favoritos');
    //   });

    //   $.post(BASE_URL + '/minha-conta/favorito/toggle', {
    //     produto_id:  productId,
    //     _csrf_token: CSRF_TOKEN
    //   }, function (res) {
    //     if (!res.ok) {
    //       // Reverte se falhou
    //       $btn.toggleClass('active', favoritado);
    //       $btn.attr('aria-pressed', favoritado.toString());

    //       if (res.redirect) {
    //         window.location.href = res.redirect;
    //       } else {
    //         notifyToast(res.msg || 'Erro ao atualizar favoritos.', 'error');
    //       }
    //       return;
    //     }

    //     // Confirma o estado correto com a resposta do servidor
    //     $btn.toggleClass('active', res.favoritado);
    //     $btn.attr('aria-pressed', res.favoritado.toString());
    //     $btn.attr('title', res.favoritado ? 'Remover dos favoritos' : 'Adicionar aos favoritos');

    //     notifyToast(res.msg, res.favoritado ? 'success' : 'info');

    //     // Se estiver na página de favoritos e removeu, some o card
    //     if (!res.favoritado && window.location.pathname.includes('favoritos')) {
    //       $btn.closest('.product-card').slideUp(300, function () {
    //         $(this).remove();
    //       });
    //     }
    //   }, 'json').fail(function () {
    //     // Reverte em caso de falha de rede
    //     $btn.toggleClass('active', favoritado);
    //     notifyToast('Você precisa fazer login para adicionar aos favoritos.', 'error');
    //   });
    // });

      // ── Botão de favorito (coração) ───────────────────────────
    $(document).on('click', '.btn-favorito', function (e) {
        e.preventDefault();
        e.stopPropagation();

        if($('.product-page').length > 0) {
          return false; // Deixa o comportamento normal do botão na página de produto
        }

        const $btn      = $(this);
        const produtoId = $btn.data('product-id');
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
                notifyToast(res.msg, 'info', {
                    label : 'Fazer login',
                    url   : BASE_URL + '/login?redirect='
                            + encodeURIComponent(window.location.pathname),
                });
                return;
            }

            if (!res.ok) {
                notifyToast(res.msg, 'error');
                return;
            }

            // Atualiza visual
            $btn.toggleClass('active');
            const ativo = $btn.hasClass('active');

            // Atualiza SVG fill
            $btn.find('svg').attr('fill', ativo ? 'currentColor' : 'none');

            // Atualiza label (se existir)
            $btn.find('.btn-favorito-label').text(
                ativo ? 'Nos seus favoritos' : 'Favoritar'
            );

            // Atualiza aria-label
            $btn.attr('aria-label',
                ativo ? 'Remover dos favoritos' : 'Adicionar aos favoritos'
            );

            notifyToast(res.msg, ativo ? 'success' : 'info');

        }, 'json').fail(function () {
            $btn.prop('disabled', false);
            notifyToast('Erro de conexão.', 'error');
        });
    });
    
    // ── Tabs dos mais vendidos ────────────────────────────────
    $(document).on('click', '.products-tab', function () {
      const $tab        = $(this);
      const categoriaId = $tab.data('categoria');
      const targetId    = $tab.data('target');

      $tab.siblings().removeClass('active');
      $tab.addClass('active');

      if (!categoriaId) return;

      const $grid = $('#' + targetId);
      $grid.css('opacity', '.5');

      $.get(BASE_URL + '/home/por-categoria', {
        categoria_id: categoriaId,
        limite: 8
      }, function (res) {
        if (res.ok && res.html) {
          $grid.html(res.html).css('opacity', '1');

          // Aqui está o pulo do gato
          initLazyReveal($grid[0]);
        }
      }, 'json').fail(function () {
        $grid.css('opacity', '1');
      });
    });

    // ── Newsletter ───────────────────────────────────────────
    $('#footer-newsletter-form').on('submit', function (e) {
      e.preventDefault();
      const $form = $(this);
      const $msg  = $('#newsletter-msg');
      const email = $form.find('input[name=email]').val();

      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        $msg.text('E-mail inválido.').addClass('msg-error').removeClass('msg-ok');
        return;
      }

      $.post(BASE_URL + '/newsletter', $form.serialize(), function (res) {
        $msg.text(res.msg)
            .removeClass('msg-error msg-ok')
            .addClass(res.ok ? 'msg-ok' : 'msg-error');
        if (res.ok) $form.find('input[name=email]').val('');
      }, 'json');
    });

    // ── Lazy reveal com IntersectionObserver ─────────────────
    if ('IntersectionObserver' in window) {
      // const revealObs = new IntersectionObserver((entries) => {
      //   entries.forEach(entry => {
      //     if (entry.isIntersecting) {
      //       $(entry.target).addClass('revealed');
      //       revealObs.unobserve(entry.target);
      //     }
      //   });
      // }, { threshold: 0.1 });

      // document.querySelectorAll('.section, .product-card, .category-card, .testimonial-card') .forEach(el => revealObs.observe(el));

      // ── Lazy reveal com IntersectionObserver ─────────────────
      let revealObs = null;

      function initLazyReveal(scope = document) {
        const elements = scope.querySelectorAll(
          '.section, .product-card, .category-card, .testimonial-card'
        );

        if (!elements.length) return;

        if ('IntersectionObserver' in window) {
          if (!revealObs) {
            revealObs = new IntersectionObserver((entries) => {
              entries.forEach(entry => {
                if (entry.isIntersecting) {
                  entry.target.classList.add('revealed');
                  revealObs.unobserve(entry.target);
                }
              });
            }, { threshold: 0.1 });
          }

          elements.forEach(el => {
            if (!el.classList.contains('revealed')) {
              revealObs.observe(el);
            }
          });
        } else {
          elements.forEach(el => el.classList.add('revealed'));
        }
      }

      // inicia nos elementos já existentes
      initLazyReveal();
    }

    // carregarEstadoFavoritos();
    // verificarFavoritos();


    // Adicionar dentro do $(function() { ... }) no main.js

  // ── Box de localização ───────────────────────────────────
  const $locBackdrop = $('#location-modal-backdrop');
  const $locModal    = $('#location-modal');

  // Abre o modal
  $('.btn-open-location').on('click', function (e) {
    e.stopPropagation();
    const isOpen = $locBackdrop.is(':visible');
    closeLocationModal();
    if (!isOpen) openLocationModal();
  });

  function openLocationModal() {
    $locBackdrop.fadeIn(150);
    positionLocationModal();
    setTimeout(() => $('#location-cep-input').focus(), 150);
  }

  function closeLocationModal() {
    $locBackdrop.fadeOut(150);
  }

  // Posiciona o dropdown abaixo do botão
  function positionLocationModal() {
    const $btn    = $('.btn-open-location');
    const offset  = $btn.offset();
    const btnW    = $btn.outerWidth();
    const modalW  = Math.min(360, $(window).width() - 32);
    let   left    = offset.left + btnW - modalW;

    // Não sair da tela pela esquerda
    if (left < 16) left = 16;

    $locModal.css({
      top:   offset.top + $btn.outerHeight() + 8,
      left:  left,
      width: modalW,
    });
  }

  // Fecha ao clicar fora
  $(document).on('click', function (e) {
    if ($locBackdrop.is(':visible') &&
        !$(e.target).closest('#location-modal, .btn-open-location').length) {
      closeLocationModal();
    }
  });

  // Fecha com ESC
  $(document).on('keydown', function (e) {
    if (e.key === 'Escape') closeLocationModal();
  });

  $('#btn-close-location').on('click', closeLocationModal);

  // Reposiciona ao redimensionar
  $(window).on('resize', function () {
    if ($locBackdrop.is(':visible')) positionLocationModal();
  });

  // Botão "Alterar CEP"
  $(document).on('click', '#btn-change-cep', function () {
    $('#location-current').slideUp(150);
    $('#location-form-wrap').slideDown(150);
    setTimeout(() => $('#location-cep-input').focus(), 160);
  });

  // Salvar CEP
  $(document).on('submit', '#form-cep', function (e) {
    e.preventDefault();

    const cep    = $('#location-cep-input').val().replace(/\D/g, '');
    const $err   = $('#location-cep-error');
    const $btn   = $('#btn-save-cep');
    const salvar = $('#salvar-endereco-check').is(':checked') ? 1 : 0;

    $err.text('');

    if (cep.length !== 8) {
      $err.text('CEP inválido. Digite os 8 dígitos.');
      return;
    }

    $btn.prop('disabled', true).text('Buscando...');

    $.post(BASE_URL + '/cep/salvar', {
      cep,
      salvar_endereço: salvar,
      _csrf_token: CSRF_TOKEN,
    }, function (res) {
      if (!res.ok) {
        $err.text(res.msg || 'CEP não encontrado.');
        $btn.prop('disabled', false).text('Usar este CEP');
        return;
      }

      // Atualiza o botão no header
      updateLocationDisplay(res);
      closeLocationModal();
      notifyToast('CEP ' + res.cep_fmt + ' salvo!', 'success');

      // Salva em cookie para o JS do lado cliente
      document.cookie = `ec_cep=${res.cep};path=/;max-age=${86400 * 30};samesite=Lax`;

      $btn.prop('disabled', false).text('Usar este CEP');
    }, 'json').fail(function () {
      $err.text('Erro de conexão. Tente novamente.');
      $btn.prop('disabled', false).text('Usar este CEP');
    });
  });

    // Remover CEP salvo
    $(document).on('click', '#btn-remove-cep', function () {
      $.post(BASE_URL + '/cep/remover', { _csrf_token: CSRF_TOKEN }, function (res) {
        if (res.ok) {
          document.cookie = 'ec_cep=;path=/;max-age=0';
          $('#location-text').html('<span class="location-empty">Informe seu CEP</span>');
          closeLocationModal();
          notifyToast('Localização removida.', 'info');

          const text_cep_full = $('.cart-page .location-cep-input');
          if (text_cep_full.length > 0) {
            text_cep_full.each(function () {       
              let button_cart_open = '<button type="button" class="cart-frete-calcular" id="btn-calcular-frete">Calcular</button>'; 
              $(this).html(button_cart_open);

              $(button_cart_open).on('click', function (e) {
                e.stopPropagation();
                const isOpen = $locBackdrop.is(':visible');
                closeLocationModal();
                if (!isOpen) openLocationModal();
              });
            });
          }
        }
      }, 'json');
    });

    // Atualiza o display do header após salvar
    function updateLocationDisplay(data) {
      const html = `
        <span class="location-cep">${data.cep_fmt}</span>
        <span class="location-city">${data.display}</span>`;
      $('#location-text').html(html);

      // Atualiza o bloco "CEP atual" dentro do modal
      $('#modal-cep-fmt').text(data.cep_fmt);
      $('#modal-cep-city').text(data.display);
      $('#location-current').show();
      $('#location-form-wrap').hide();

      const text_cep_full = $('.location-cep-input');
      if (text_cep_full.length > 0) {
        text_cep_full.each(function () {        
          $(this).html(data.cep_fmt);
        });
      }
      // Disponibiliza o CEP globalmente para os cálculos de frete
      window.EC_CEP_ATIVO = data.cep;
    }

    // Expõe o CEP ativo para outros scripts (cálculo de frete, produto, etc.)
    window.EC_CEP_ATIVO = (function () {
      const match = document.cookie.match(/(?:^|;\s*)ec_cep=([^;]+)/);
      return match ? match[1] : null;
    })();

    // Adicionar no main.js — registra quanto tempo o usuário ficou na página

    // (function () {
      // Só rastreia em páginas de produto e categoria
      const isProduto   = document.querySelector('.product-page');
      const isCategoria = document.querySelector('.catalog-page');
      if (!isProduto && !isCategoria) return;

      const inicio = Date.now();

      // Ao sair da página (unload ou visibilitychange)
      function enviarTempo() {
        const segundos = Math.floor((Date.now() - inicio) / 1000);
        if (segundos < 2) return; // ignora visitas muito rápidas

        // Usa sendBeacon para garantir envio mesmo ao fechar a aba
        const data = new FormData();
        data.append('segundos',    segundos);
        data.append('_csrf_token', CSRF_TOKEN);

        navigator.sendBeacon(BASE_URL + '/historico/tempo', data);
      }

      document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') enviarTempo();
      });
      window.addEventListener('pagehide', enviarTempo);
    // })();

    // Limpar histórico
    $(document).on('click', '#btn-clear-history', function () {
      if (!confirm('Tem certeza que deseja apagar todo o seu histórico de navegação?')) return;

      $.post(BASE_URL + '/minha-conta/historico/limpar', {
        _csrf_token: CSRF_TOKEN
      }, function (res) {
        if (res.ok) {
          notifyToast(res.msg, 'success');
          setTimeout(() => location.reload(), 800);
        }
      }, 'json');
    });

    // Adicionar dentro do $(function() { ... }) no main.js

    
  });

  /**
   * catalog.js — Catálogo de produtos
   * ════════════════════════════════════════════════════════
   * - Ordenar por: change no select → atualiza URL
   * - Dual range price slider ←→ inputs sincronizados
   * - Layout switcher: grid3 / grid4 / lista (salva localStorage)
   * - Características: accordion expand/collapse
   * - Sidebar mobile: abrir/fechar
   */
  (function ($) {
    'use strict';

    // ══════════════════════════════════════════════════════
    // SORT — dropdown customizado sincronizado com <select> nativo
    // ══════════════════════════════════════════════════════
    function aplicarOrdenacao(valor) {
      var params = new URLSearchParams(window.location.search);
      params.set('ordem', valor);
      // Limpa os dois nomes de paginação usados no projeto:
      // 'pagina' no catálogo de categorias, 'page' no catálogo de motos.
      params.delete('pagina');
      params.delete('page');
      window.location.search = params.toString();
    }

    var $dropdown = $('#sort-dropdown');
    var $trigger  = $('#sort-trigger');
    var $options  = $('#sort-options');

    function closeSortDropdown() {
      $dropdown.removeClass('is-open');
      $trigger.attr('aria-expanded', 'false');
      $options.prop('hidden', true);
    }

    function openSortDropdown() {
      $dropdown.addClass('is-open');
      $trigger.attr('aria-expanded', 'true');
      $options.prop('hidden', false);
    }

    $trigger.on('click', function (e) {
      e.stopPropagation();
      $dropdown.hasClass('is-open') ? closeSortDropdown() : openSortDropdown();
    });

    $options.on('click', '.sort-option', function () {
      var valor = $(this).data('value');
      $('#sort-select').val(valor);
      aplicarOrdenacao(valor);
    });

    // Fecha ao clicar fora ou pressionar Escape
    $(document).on('click', function (e) {
      if (!$(e.target).closest('#sort-dropdown').length) closeSortDropdown();
    });
    $(document).on('keydown', function (e) {
      if (e.key === 'Escape') closeSortDropdown();
    });

    // Fallback: se algo mudar o <select> nativo diretamente, aplica também
    $('#sort-select').on('change', function () {
      aplicarOrdenacao(this.value);
    });

    // ══════════════════════════════════════════════════════
    // PRICE SLIDER DUPLO
    // ══════════════════════════════════════════════════════
    var $sliderMin = $('#slider-min');
    var $sliderMax = $('#slider-max');
    var $inputMin  = $('#preco_min');
    var $inputMax  = $('#preco_max');
    var $fill      = $('#price-range-fill');

    function updateFill() {
      if (!$sliderMin.length) return;
      var min    = parseFloat($sliderMin.attr('min')) || 0;
      var max    = parseFloat($sliderMin.attr('max')) || 9999;
      var valMin = parseFloat($sliderMin.val());
      var valMax = parseFloat($sliderMax.val());
      var pctMin = ((valMin - min) / (max - min)) * 100;
      var pctMax = ((valMax - min) / (max - min)) * 100;
      $fill.css({ left: pctMin + '%', width: (pctMax - pctMin) + '%' });
    }

    // Slider min moveu
    $sliderMin.on('input', function () {
      var val    = parseFloat(this.value);
      var maxVal = parseFloat($sliderMax.val());
      if (val > maxVal) { this.value = maxVal; val = maxVal; }
      $inputMin.val(Math.round(val));
      updateFill();
    });

    // Slider max moveu
    $sliderMax.on('input', function () {
      var val    = parseFloat(this.value);
      var minVal = parseFloat($sliderMin.val());
      if (val < minVal) { this.value = minVal; val = minVal; }
      $inputMax.val(Math.round(val));
      updateFill();
    });

    // Input numérico min alterado → sincroniza slider
    $inputMin.on('change', function () {
      var val    = parseFloat(this.value) || 0;
      var maxVal = parseFloat($sliderMax.val());
      if (val > maxVal) val = maxVal;
      $sliderMin.val(val);
      this.value = Math.round(val);
      updateFill();
    });

    // Input numérico max alterado → sincroniza slider
    $inputMax.on('change', function () {
      var val    = parseFloat(this.value) || parseFloat($sliderMax.attr('max'));
      var minVal = parseFloat($sliderMin.val());
      if (val < minVal) val = minVal;
      $sliderMax.val(val);
      this.value = Math.round(val);
      updateFill();
    });

    // Renderização inicial
    updateFill();

    // ══════════════════════════════════════════════════════
    // LAYOUT SWITCHER
    // ══════════════════════════════════════════════════════
    var LAYOUT_KEY = 'cat_layout';
    var LAYOUTS    = {
      grid3: 'products-grid--3',
      grid4: 'products-grid--4',
      list : 'products-grid--list',
    };

    function applyLayout(layout) {
      var $grid = $('#catalog-grid');
      if (!$grid.length) return;

      Object.values(LAYOUTS).forEach(function (cls) { $grid.removeClass(cls); });
      $grid.addClass(LAYOUTS[layout] || LAYOUTS.grid3);

      $('.layout-btn').removeClass('is-active');
      $('.layout-btn[data-layout="' + layout + '"]').addClass('is-active');

      try { localStorage.setItem(LAYOUT_KEY, layout); } catch (e) {}
    }

    $('.layout-btn').on('click', function () { applyLayout($(this).data('layout')); });

    // Restaura preferência salva. Se não houver nada salvo ainda,
    // detecta o layout padrão pela classe já presente no HTML —
    // o catálogo de categorias nasce em grid3, o de motos em grid4.
    var savedLayout;
    try { savedLayout = localStorage.getItem(LAYOUT_KEY); } catch (e) {}

    if (savedLayout && LAYOUTS[savedLayout]) {
      applyLayout(savedLayout);
    } else {
      var $grid = $('#catalog-grid');
      var defaultLayout = 'grid3';
      Object.keys(LAYOUTS).forEach(function (key) {
        if ($grid.hasClass(LAYOUTS[key])) defaultLayout = key;
      });
      applyLayout(defaultLayout);
    }

    // ══════════════════════════════════════════════════════
    // CARACTERÍSTICAS — accordion
    // ══════════════════════════════════════════════════════
    $(document).on('click', '.filter-attr-toggle', function () {
      var $btn  = $(this);
      var $list = $btn.next('.filter-attr-list');
      var open  = $btn.attr('aria-expanded') === 'true';

      $btn.attr('aria-expanded', !open);
      if (open) {
        $list.prop('hidden', true);
      } else {
        $list.prop('hidden', false);
      }
    });

    // Mantém abertos os grupos que têm algum valor selecionado
    $('.filter-attr-list').each(function () {
      if ($(this).find('input:checked').length > 0) {
        $(this).prop('hidden', false);
        $(this).prev('.filter-attr-toggle').attr('aria-expanded', 'true');
      }
    });

    // ══════════════════════════════════════════════════════
    // SIDEBAR MOBILE
    // ══════════════════════════════════════════════════════
    $('#btn-filter-mobile').on('click', function () {
      $('#catalog-sidebar').addClass('is-open');
      $('#sidebar-overlay').addClass('is-active');
      $('body').css('overflow', 'hidden');
    });

    function closeSidebar() {
      $('#catalog-sidebar').removeClass('is-open');
      $('#sidebar-overlay').removeClass('is-active');
      $('body').css('overflow', '');
    }

    $('#btn-sidebar-close, #sidebar-overlay').on('click', closeSidebar);
    $(document).on('keydown', function (e) { if (e.key === 'Escape') closeSidebar(); });

  }(jQuery));

  /**
   * modal-lembrar-dispositivo.js
   * Ao carregar a página, pergunta ao servidor se deve mostrar a modal
   * "continuar conectado aqui?" (flag consumida uma vez por login, ver
   * AuthController::verificarModalLembrar). Não bloqueia nada — roda em
   * background e só aparece se o servidor confirmar.
   */
  (function ($) {
    'use strict';

    // window.Toast('teste')
    console.log(window);
    

    // Reaproveita BASE_URL já exposto por outros scripts do projeto.
    // Fallback para o atributo data-base-url no <body>, se existir.
    function getBaseUrl() {
      if (window.AUTH_CONFIG && window.AUTH_CONFIG.baseUrl) return window.AUTH_CONFIG.baseUrl;
      if (window.SESS_CONFIG && window.SESS_CONFIG.base) return window.SESS_CONFIG.base;
      return document.body.getAttribute('data-base-url') || '';
    }

    document.addEventListener('DOMContentLoaded', function () {
      var modal = document.getElementById('modal-lembrar-dispositivo');
      if (!modal) return;

      var BASE = getBaseUrl();

      fetch(BASE + '/sessao/verificar-modal-lembrar', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
      })
        .then(function (r) { return r.json(); })
        .then(function (resp) {
          if (resp.ok && resp.mostrar) {
            abrirModal();
          }
        })
        .catch(function () { /* falha silenciosa — não é crítico */ });

      var stepPergunta = document.getElementById('lembrar-step-pergunta');
      var stepSenha     = document.getElementById('lembrar-step-senha');
      var btnNao         = document.getElementById('btn-lembrar-nao');
      var btnSim          = document.getElementById('btn-lembrar-sim');
      var btnVoltar         = document.getElementById('btn-lembrar-voltar');
      var btnConfirmar        = document.getElementById('btn-lembrar-confirmar');
      var inputSenha            = document.getElementById('lembrar-senha-input');
      var errSenha               = document.getElementById('err-lembrar-senha');

      function abrirModal() {
        modal.style.display = 'flex';
        stepPergunta.style.display = '';
        stepSenha.style.display = 'none';
      }
      function fecharModal() {
        modal.style.display = 'none';
      }

      if (btnNao) btnNao.addEventListener('click', fecharModal);

      if (btnSim) {
        btnSim.addEventListener('click', function () {
          stepPergunta.style.display = 'none';
          stepSenha.style.display = '';
          inputSenha.value = '';
          errSenha.textContent = '';
          inputSenha.focus();
        });
      }

      if (btnVoltar) {
        btnVoltar.addEventListener('click', function () {
          stepSenha.style.display = 'none';
          stepPergunta.style.display = '';
        });
      }

      if (btnConfirmar) {
        btnConfirmar.addEventListener('click', function () {
          var senha = inputSenha.value;
          if (!senha) {
            errSenha.textContent = 'Digite sua senha.';
            return;
          }

          var csrf = (window.AUTH_CONFIG && window.AUTH_CONFIG.csrfToken)
            || (window.SESS_CONFIG && window.SESS_CONFIG.csrf)
            || '';

          var fd = new FormData();
          fd.append('_token', csrf);
          fd.append('senha', senha);

          btnConfirmar.disabled = true;
          fetch(BASE + '/sessao/confirmar-lembrar', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd,
            credentials: 'same-origin',
          })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
              btnConfirmar.disabled = false;
              if (!resp.ok) {
                errSenha.textContent = resp.msg || 'Erro ao confirmar.';
                notifyToast(errSenha.textContent)
                return;
              }
              fecharModal();
            })
            .catch(function () {
              btnConfirmar.disabled = false;
              errSenha.textContent = 'Erro de conexão. Tente novamente.';
              notifyToast(errSenha.textContent)
            });
        });
      }
    });
    
  })(jQuery);


  // ── Mini modal de variações no card ──────────────────────
  (function () {

    // Cache de dados de variação por produto
    const varCache = {};

    // Estado de seleção por produto
    const modalState = {};

    // ── Clique em "Adicionar ao carrinho" ────────────────────
    $(document).on('click', '.pc-btn-add', function (e) {
      e.stopPropagation();

      const prodId = $(this).data('product-id');
      const $card  = $(this).closest('.product-card');
      const $modal = $card.find('.pc-variation-modal');

      // Já tem dados em cache
      if (varCache[prodId]) {
        const d = varCache[prodId];
        if (!d.tem_variacao) {
          // Sem variações — adiciona direto
          adicionarAoCarrinho(prodId, null, null);
          return;
        }
        abrirModal($card, prodId);
        return;
      }

      // Carrega via Ajax
      $(this).prop('disabled', true);

      $.get(BASE_URL + '/produto/card-variations', { id: prodId }, function (res) {
        $('.pc-btn-add[data-product-id="' + prodId + '"]').prop('disabled', false);

        if (!res.ok) return;

        varCache[prodId] = res;

        if (!res.tem_variacao) {
          // Produto simples — adiciona direto
          adicionarAoCarrinho(prodId, null, null);
          return;
        }

        // Atualiza preço do card se tiver range
        if (res.tem_range) {
          atualizarPrecosCard(prodId, res);
        }

        abrirModal($card, prodId);
      }, 'json').fail(function () {
        $('.pc-btn-add[data-product-id="' + prodId + '"]').prop('disabled', false);
      });
    });

    // ── Abre o modal ─────────────────────────────────────────
  

    // ── Renderiza o body do modal ────────────────────────────
    function renderizarModalBody(d, prodId) {
      let html = '';

      // Agrupadores (cor, estampa) — navega entre produtos
      if (d.agrupadores && d.agrupadores.length && d.familia && d.familia.length > 1) {
        d.agrupadores.forEach(function (atr) {
          html += `<div class="pc-modal-group" data-atributo="${atr.slug}">`;
          html += `<div class="pc-modal-label">${atr.nome}:
                    <strong class="pc-modal-val-atual">${atr.valor}</strong>
                  </div>`;
          html += '<div class="pc-modal-opcoes">';

          d.familia.forEach(function (membro) {
            const val    = membro.agrupadores    && membro.agrupadores[atr.slug];
            const hex    = membro.agrupadores_hex && membro.agrupadores_hex[atr.slug];
            const img    = membro.agrupadores_img && membro.agrupadores_img[atr.slug];
            if (!val) return;

            const isAtual = membro.atual;
            const semEst  = membro.sem_estoque;
            const tipo    = img ? 'img' : (hex ? 'color' : 'text');

            html += `<a href="${BASE_URL}/produto/${membro.slug}"
                        class="pc-modal-swatch pc-modal-swatch--${tipo}
                              ${isAtual ? 'active' : ''}
                              ${semEst  ? 'sem-estoque' : ''}"
                        data-valor="${val}"
                        title="${val}${semEst ? ' — Sem estoque' : ''}">`;

            if (tipo === 'img' && img) {
              html += `<img src="${img}" alt="${val}" loading="lazy">`;
            } else if (tipo === 'color' && hex) {
              html += `<span class="swatch-color" style="background:${hex}"></span>`;
            } else {
              html += `<span class="swatch-text">${val.substring(0, 3)}</span>`;
            }
            html += '</a>';
          });

          html += '</div></div>';
        });
      }

      // Variações compráveis (tamanho, voltagem...)
      if (d.tipos_variacao && d.tipos_variacao.length) {
        d.tipos_variacao.forEach(function (tipo) {
          html += `<div class="pc-modal-group" data-tipo="${tipo.slug}">`;
          html += `<div class="pc-modal-label">${tipo.nome}:
                    <strong class="pc-modal-val-atual" id="pc-modal-lbl-${prodId}-${tipo.slug}">
                      Selecione
                    </strong>
                  </div>`;
          html += '<div class="pc-modal-opcoes">';

          tipo.valores.forEach(function (v) {
            const semEst = !v.tem_estoque;
            html += `<button type="button"
                            class="pc-modal-btn-variacao ${semEst ? 'sem-estoque' : ''}"
                            data-tipo="${tipo.slug}"
                            data-valor="${v.valor}"
                            data-product-id="${prodId}"
                            ${semEst ? 'disabled title="Sem estoque"' : ''}>
                      ${v.valor}
                    </button>`;
          });

          html += '</div></div>';
        });
      }

      return html || '<p class="pc-modal-empty">Produto sem variações.</p>';
    }

    // ── Clique em variação dentro do modal ───────────────────
    // $(document).on('click', '.pc-modal-btn-variacao:not(:disabled)', function (e) {
    //   e.stopPropagation();
    //   alert('Cliquei na variação!'); // Debug
    //   const $btn  = $(this);
    //   const tipo  = $btn.data('tipo');
    //   const valor = String($btn.data('valor'));
    //   const prodId = $btn.data('product-id');
    //   const state  = modalState[prodId];
    //   if (!state) return;

    //   // Visual
    //   $btn.closest('.pc-modal-group')
    //       .find('.pc-modal-btn-variacao').removeClass('active');
    //   $btn.addClass('active');

    //   $(`#pc-modal-lbl-${prodId}-${tipo}`).text(valor);

    //   state.selecoes[tipo] = valor;

    //   resolverSkuModal(prodId);
    // });

    // // ── Resolve SKU no modal ─────────────────────────────────
    // function resolverSkuModal(prodId) {
    //   console.log(modalState);
      
    //   const state   = modalState[prodId];
    //   const $card   = $(`.product-card[data-product-id="${prodId}"]`);
    //   const $btnAdd = $card.find('.pc-modal-confirm');
    //   const $price  = $card.find('#pc-modal-price-' + prodId);

    //   const faltando = state.tiposSlug.filter(t => !state.selecoes[t]);

    //   if (faltando.length > 0) {
    //     $btnAdd.prop('disabled', true);
    //     return;
    //   }

    //   const chave = state.tiposSlug.map(t => state.selecoes[t]).join('|');
    //   const sku   = state.matriz[chave];

    //   console.log('[SKU] Chave:', chave, '| SKU:', sku);

    //   if (!sku) {
    //     $btnAdd.prop('disabled', true);
    //     return;
    //   }

    //   state.skuAtual = sku;

    //   // Atualiza preço
    //   $price.find('.pc-modal-price-val').text(sku.preco_fmt);
    //   if (sku.sem_estoque) {
    //     $price.find('.pc-modal-price-val').addClass('price-sem-estoque');
    //     $price.find('.pc-modal-price-install').text('Sem estoque');
    //   } else {
    //     $price.find('.pc-modal-price-val').removeClass('price-sem-estoque');
    //     const parcela = calcularParcela(sku.preco);
    //     if (parcela) {
    //       $price.find('.pc-modal-price-install')
    //             .text('ou ' + parcela.vezes + 'x de ' + parcela.valorFmt + ' sem juros');
    //     }
    //   }
    //   $price.show();

    //   // Habilita / desabilita botão
    //   $btnAdd.prop('disabled', sku.sem_estoque);
    //   $btnAdd.attr('data-sku-id', sku.sku_id).data('sku-id', sku.sku_id);
    // }

    // ── Confirmar adição ao carrinho pelo modal ──────────────
    // $(document).on('click', '.pc-modal-confirm:not(:disabled)', function (e) {
    //   e.stopPropagation();

    //   const prodId = $(this).data('product-id');
    //   const state  = modalState[prodId];
    //   if (!state || !state.skuAtual) return;

    //   adicionarAoCarrinho(prodId, state.skuAtual.sku_id, state.skuAtual);
    // });

    // ── Adicionar ao carrinho ────────────────────────────────
    function adicionarAoCarrinho(prodId, skuId, sku) {
      
      const $card = $(`.product-card[data-product-id="${prodId}"]`);
      const $btn  = $card.find('.pc-modal-confirm, .pc-btn-add').first();

      $btn.prop('disabled', true).text('Adicionando...');

      const dados = {
        produto_id  : prodId,
        quantidade  : 1,
        _csrf_token : CSRF_TOKEN,
      };
      if (skuId) dados.sku_id = skuId;
      

      $.post(BASE_URL + '/carrinho/adicionar', dados, function (res) {
        if (res.ok) {
          fecharModal($card, prodId);

          // Feedback visual no card
          $card.find('.pc-btn-add')
              .prop('disabled', false)
              .text('Adicionado!')
              .addClass('btn-success');

          setTimeout(function () {
            $card.find('.pc-btn-add')
                .text('Adicionar ao carrinho')
                .removeClass('btn-success');
          }, 2000);

          // Atualiza badge do carrinho
          if (res.count) {
            $('#cart-count, #mc-badge').text(res.count).show();
          }

          // Abre o mini cart
          if (typeof abrirMiniCart === 'function') abrirMiniCart();

          notifyToast('Produto adicionado ao carrinho!', 'success');


        } else {
          $btn.prop('disabled', false).text('Adicionar ao carrinho');
          notifyToast(res.msg || 'Erro ao adicionar.', 'error');
        }

        CartPromoPreview.atualizar();
      }, 'json').fail(function () {
        $btn.prop('disabled', false).text('Adicionar ao carrinho');
        notifyToast('Erro de conexão.', 'error');
      });
    }

    // ── Fechar modal ─────────────────────────────────────────
    $(document).on('click', '.pc-modal-close', function (e) {
      e.stopPropagation();
      const prodId = $(this).data('product-id');
      const $card  = $(this).closest('.product-card');
      fecharModal($card, prodId);
    });

    function fecharModal($card, prodId) {
      $card.find('.pc-variation-modal').slideUp(180).attr('aria-hidden', 'true');
      $card.removeClass('pc-card--modal-open');

      // Remove backdrop se não houver mais modais abertos
      if ($('.pc-variation-modal:visible').length === 0) {
          $('#pc-modal-backdrop').removeClass('active');
      }
  }

  function fecharTodosModais() {
      $('.pc-variation-modal').slideUp(180).attr('aria-hidden', 'true');
      $('.product-card').removeClass('pc-card--modal-open');
      $('#pc-modal-backdrop').removeClass('active');
  }

    // Fecha ao clicar fora
    $(document).on('click', function () { fecharTodosModais(); });
    $(document).on('click', '.product-card', function (e) { e.stopPropagation(); });

    // / Fechar ao clicar no backdrop
    $(document).on('click', '#pc-modal-backdrop', fecharTodosModais);

    // ESC fecha o modal
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') fecharTodosModais();
    });

    // Impede que clique dentro do card feche o modal
    $(document).on('click', '.product-card', function (e) {
        e.stopPropagation();
    });

    // Clique fora fecha (fallback)
    $(document).on('click', function () {
        fecharTodosModais();
    });

    // ── Atualiza preços do card se tiver range ───────────────
    function atualizarPrecosCard(prodId, d) {
      const $pricing = $('#pc-pricing-' + prodId);
      if (!$pricing.length || !d.tem_range) return;

      const maxP = calcularParcela(d.preco_min);
      const instStr = maxP
        ? 'em até ' + maxP.vezes + 'x sem juros'
        : '';

      $pricing.html(`
        <span class="price-from">A partir de</span>
        <span class="price-main">${d.preco_min_fmt}</span>
        <span class="price-range-sm">${d.preco_min_fmt} até ${d.preco_max_fmt}</span>
        ${instStr ? `<span class="price-install">${instStr}</span>` : ''}
      `);
    }

    // ── Calcula parcelamento ─────────────────────────────────
    function calcularParcela(preco) {
      const MAX_PARCELAS = 10;
      const MIN_PARCELA  = 10.00;
      if (!preco || preco <= 0) return null;

      for (let n = MAX_PARCELAS; n >= 2; n--) {
        const val = preco / n;
        if (val >= MIN_PARCELA) {
          return {
            vezes   : n,
            valorFmt: 'R$ ' + val.toFixed(2)
              .replace('.', ',')
              .replace(/\B(?=(\d{3})+(?!\d))/g, '.'),
          };
        }
      }
      return null;
    }

    // Substituir as funções abrirModal, fecharModal e fecharTodosModais

  // Modal global reutilizável — fica no body, fora do card
  let $modalGlobal = null;
  let prodIdAberto = null;

  function criarModalGlobal() {
      if ($('#pc-modal-global').length) return;

      $('body').append(`
        <div id="pc-modal-global" aria-hidden="true" style="display:none;">
          <div class="pc-modal-header">
            <span class="pc-modal-title">Selecione as opções</span>
            <button type="button" class="pc-modal-close-global">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                  stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                <line x1="18" y1="6" x2="6"  y2="18"/>
                <line x1="6"  y1="6" x2="18" y2="18"/>
              </svg>
            </button>
          </div>
          <div class="pc-modal-body" id="pc-modal-global-body"></div>
          <div class="pc-modal-footer">
            <div class="pc-modal-price" id="pc-modal-global-price" style="display:none;">
              <span class="pc-modal-price-orig" style="display:none;"></span>
              <span class="pc-modal-price-val"></span>
              <span class="pc-modal-price-install"></span>
            </div>
            <button type="button"
                    class="btn btn-primary btn-full pc-modal-confirm"
                    id="pc-modal-global-confirm" disabled>
              Adicionar ao carrinho
            </button>
          </div>
        </div>
      `);

      $modalGlobal = $('#pc-modal-global');
  }

    function abrirModal($card, prodId) {
        criarModalGlobal();
        fecharTodosModais();

        const d = varCache[prodId];

        modalState[prodId] = {
            selecoes : {},
            tiposSlug: d.tipos_slug || [],
            matriz   : d.matriz     || {},
            chaveMap : d.chave_map  || {},
            skuAtual : null,
        };

        prodIdAberto = prodId;

        $('#pc-modal-global-body').html(renderizarModalBody(d, prodId));

        const $price = $('#pc-modal-global-price');
        $price.find('.pc-modal-price-orig').hide();
        if (d.tem_range) {
            $price.find('.pc-modal-price-val').text('A partir de ' + d.preco_min_fmt);
            $price.find('.pc-modal-price-install').text('');
            $price.show();
        } else if (d.preco_min_fmt) {
            $price.find('.pc-modal-price-val').text(d.preco_min_fmt);
            $price.show();
        }

        $('#pc-modal-global-confirm')
            .prop('disabled', true)
            .data('product-id', prodId)
            .attr('data-product-id', prodId);

        // Posiciona sobre o card ANTES de mostrar
        posicionarModal($card);

        // Mostra com animação
        $modalGlobal
            .css('display', 'flex')
            .addClass('entrando')
            .attr('aria-hidden', 'false');

        // Remove classe de animação após terminar
        setTimeout(() => $modalGlobal.removeClass('entrando'), 300);

        $('#pc-modal-backdrop').addClass('active');
        $card.addClass('pc-card--modal-open');

        travarScroll();
    }

    // function posicionarModal($card) {
    //     if (!$modalGlobal || !$modalGlobal.length) return;

    //     const card     = $card[0].getBoundingClientRect();
    //     const scrollTop  = window.pageYOffset || document.documentElement.scrollTop;
    //     const scrollLeft = window.pageXOffset || document.documentElement.scrollLeft;

    //     let top_fake = (card.top  + scrollTop) + card.height - $modalGlobal.outerHeight();

    //     $modalGlobal.css({
    //         position : 'absolute',
    //         width    : card.width,
    //         left     : (card.left + scrollLeft),
    //         top      : top_fake,
    //         // height   : card.height,
    //     });
    // }

    function posicionarModal($card) {
      if (!$modalGlobal || !$modalGlobal.length) return;

      // Mobile: tela inteira no bottom
      if (window.innerWidth <= 768) {
          $modalGlobal.css({
              position  : 'fixed',
              bottom    : 0,
              left      : 0,
              right     : 0,
              top       : 'auto',
              width     : '100%',
              height    : 'auto',
              maxHeight : '85vh',
              // borderRadius: '16px 16px 0 0',
          });
          $modalGlobal.addClass('modal-mobile').removeClass('modal-desktop');
          return;
      }

      // Desktop: cobre o card exatamente
      const card       = $card[0].getBoundingClientRect();
      const scrollTop  = window.pageYOffset || document.documentElement.scrollTop;
      const scrollLeft = window.pageXOffset || document.documentElement.scrollLeft;
      let top_fake = (card.top  + scrollTop) + card.height - $modalGlobal.outerHeight();
      
      $modalGlobal.css({
          position    : 'absolute',
          // top         : card.top  + scrollTop,
          top         : top_fake,
          left        : card.left + scrollLeft,
          width       : card.width,
          // height      : card.height,
          bottom      : 'auto',
          right       : 'auto',
          maxHeight   : '',
          borderRadius: '',
      });
      $modalGlobal.addClass('modal-desktop').removeClass('modal-mobile');
    }

    function fecharModal() {
        if ($modalGlobal) {
            $modalGlobal.fadeOut(150).attr('aria-hidden', 'true');
        }
        if (prodIdAberto) {
            $(`.product-card[data-product-id="${prodIdAberto}"]`)
                .removeClass('pc-card--modal-open');
            prodIdAberto = null;
        }
        $('#pc-modal-backdrop').removeClass('active');

        // Libera o scroll
        liberarScroll();
    }

  function fecharTodosModais() {
      fecharModal();
  }

  // ── Clique em variação dentro do modal global ────────────
  $(document).on('click', '#pc-modal-global .pc-modal-btn-variacao:not(:disabled)', function (e) {
      e.stopPropagation();
      // alert('clicou variação global');
      const tipo   = String($(this).data('tipo'));
      const valor  = String($(this).data('valor'));
      const prodId = prodIdAberto;
      const state  = modalState[prodId];
      if (!state) return;

      $(this).closest('.pc-modal-group')
            .find('.pc-modal-btn-variacao').removeClass('active');
      $(this).addClass('active');

      $(`#pc-modal-global .pc-modal-val-atual[data-tipo="${tipo}"]`).text(valor);
      $(`#pc-modal-lbl-${prodId}-${tipo}`).text(valor);

      state.selecoes[tipo] = valor;
      resolverSkuModal(prodId);
  });

  // ── Resolver SKU usando o modal global ───────────────────
  function resolverSkuModal(prodId) {
      const state   = modalState[prodId];
      const $btnAdd = $('#pc-modal-global-confirm');
      const $price  = $('#pc-modal-global-price');

      const faltando = state.tiposSlug.filter(t => !state.selecoes[t]);
      if (faltando.length > 0) {
          $btnAdd.prop('disabled', true);
          return;
      }

      const chave = state.tiposSlug.map(t => state.selecoes[t]).join('|');
      const sku   = state.matriz[chave];

      console.log(modalState[prodId]);

      if (!sku) {
          $btnAdd.prop('disabled', true);
          return;
      }

      state.skuAtual = sku;

      $price.find('.pc-modal-price-val')
            .text(sku.preco_fmt)
            .toggleClass('price-sem-estoque', sku.sem_estoque);

      if (sku.sem_estoque) {
          $price.find('.pc-modal-price-install').text('Sem estoque');
      } else {
          const parcela = calcularParcela(sku.preco);
          $price.find('.pc-modal-price-install').text(
              parcela
              ? 'ou ' + parcela.vezes + 'x de ' + parcela.valorFmt + ' sem juros'
              : ''
          );
      }
      $price.show();

      $btnAdd.prop('disabled', sku.sem_estoque)
            .attr('data-sku-id', sku.sku_id)
            .data('sku-id', sku.sku_id);
  }

  // ── Confirmar adição pelo modal global ───────────────────
  $(document).on('click', '#pc-modal-global-confirm:not(:disabled)', function (e) {
      e.stopPropagation();
      const prodId = prodIdAberto;
      const state  = modalState[prodId];
      if (!state || !state.skuAtual) return;
      adicionarAoCarrinho(prodId, state.skuAtual.sku_id, state.skuAtual);
  });

  // ── Fechar pelo X do modal global ───────────────────────
  $(document).on('click', '.pc-modal-close-global', function (e) {
      e.stopPropagation();
      fecharModal();
  });

  // ── Backdrop e ESC ───────────────────────────────────────
  $(document).on('click', '#pc-modal-backdrop', fecharTodosModais);
  $(document).on('keydown', function (e) {
      if (e.key === 'Escape') fecharTodosModais();
  });

  // Impede que clique no card feche o modal
  $(document).on('click', '.product-card', function (e) {
      e.stopPropagation();
  });
  $(document).on('click', function () {
      fecharTodosModais();
  });


  // ── Scroll lock ──────────────────────────────────────────
  let scrollY = 0;

  function travarScroll() {
      scrollY = window.pageYOffset;
      $('body').css({
          // overflow  : 'hidden'
      });
  }

  function liberarScroll() {
      $('body').css({
          // overflow  : 'auto',
      });
      // window.scrollTo(0, scrollY);
  }

  // Reposiciona ao redimensionar (sem scroll pois está travado)
  // $(window).on('resize.pcmodal', function () {
  //     if (!prodIdAberto || !$modalGlobal || !$modalGlobal.is(':visible')) return;
  //     const $card = $(`.product-card[data-product-id="${prodIdAberto}"]`);
  //     if ($card.length) posicionarModal($card);
  // });

  // No handler de resize existente — detecta mudança de breakpoint
  $(window).on('resize.pcmodal', function () {
      if (!prodIdAberto || !$modalGlobal || !$modalGlobal.is(':visible')) return;
      const $card = $(`.product-card[data-product-id="${prodIdAberto}"]`);
      if ($card.length) posicionarModal($card);
  });
  })();

  /**
   * product-slider.js — Premium Product Slider
   * ════════════════════════════════════════════════════════
   * Arquitetura:
   *  - Pixel-perfect: navegação por offset real, nunca além do conteúdo
   *  - Auto-contido: cada .slider-wrap encontra seu próprio track
   *    (não depende de IDs únicos — suporta N sliders por página)
   *  - Momentum drag com rubber-band nas bordas (feel nativo iOS)
   *  - Snap inteligente: para no card mais próximo após o drag
   *  - ResizeObserver: recalcula quando o container muda
   *    (sidebars, filtros, orientação do device)
   *  - Acessível: teclado, ARIA, focus management
   *
   * Uso:
   *   <div class="slider-wrap" data-slider>
   *     <button class="slider-btn slider-btn--prev">…</button>
   *     <div class="slider-viewport">
   *       <div class="products-grid slider-track">…cards…</div>
   *     </div>
   *     <button class="slider-btn slider-btn--next">…</button>
   *   </div>
   */
  (function ($) {
    'use strict';

    var SETTINGS = {
      transitionMs   : 380,
      easing         : 'cubic-bezier(0.25, 0.46, 0.45, 0.94)',
      swipeThreshold : 40,    // px mínimo para mudar de página
      rubberBand     : 0.3,   // resistência ao arrastar além das bordas
      clickTolerance : 8,     // px de movimento que ainda conta como clique
    };

    function ProductSlider(wrapEl) {
      this.$wrap     = $(wrapEl);
      this.$viewport = this.$wrap.find('.slider-viewport').first();
      this.$track    = this.$wrap.find('.slider-track').first();
      this.$prev     = this.$wrap.find('.slider-btn--prev').first();
      this.$next     = this.$wrap.find('.slider-btn--next').first();

      if (!this.$track.length || !this.$viewport.length) return;

      this.offset     = 0;   // deslocamento atual em px (sempre >= 0)
      this.maxOffset  = 0;   // deslocamento máximo (trackW - viewportW)
      this.stepW      = 0;   // largura de um card + gap
      this.dragging   = false;
      this.dragStartX = 0;
      this.dragLastX  = 0;
      this.dragBase   = 0;

      this.init();
    }

    ProductSlider.prototype = {

      // ══════════════════════════════════════════════
      // SETUP
      // ══════════════════════════════════════════════
      init: function () {
        var self = this;

        this.bindArrows();
        this.bindDrag();
        this.bindKeyboard();
        this.observeResize();

        // Mede após o primeiro paint (CSS flex aplicado)
        requestAnimationFrame(function () { self.measure(); });

        // Re-mede quando todas as imagens carregarem
        var $imgs   = this.$track.find('img');
        var pending = $imgs.filter(function () { return !this.complete; }).length;
        if (pending > 0) {
          $imgs.each(function () {
            if (this.complete) return;
            $(this).one('load error', function () {
              pending--;
              if (pending === 0) self.measure();
            });
          });
        }
      },

      // ══════════════════════════════════════════════
      // MEDIÇÃO — pixel-based, nunca passa do conteúdo
      // ══════════════════════════════════════════════
      measure: function () {
        var trackW = this.$track[0].scrollWidth;     // largura total do conteúdo
        var vpW    = this.$viewport.innerWidth();    // largura visível

        // Se o layout ainda não pintou, tenta no próximo frame
        if (vpW === 0 || trackW === 0) {
          var self = this;
          requestAnimationFrame(function () { self.measure(); });
          return;
        }

        var $first = this.$track.children().first();
        var gap    = parseFloat(this.$track.css('gap')) || 0;
        this.stepW = $first.outerWidth() + gap;

        // ── O fix do espaço vazio ───────────────────
        // maxOffset é o quanto o track pode deslocar até o ÚLTIMO card
        // encostar na borda direita do viewport. Nem 1px a mais.
        this.maxOffset = Math.max(0, trackW - vpW);

        // Clampa o offset atual (ex: após resize)
        this.setOffset(Math.min(this.offset, this.maxOffset), false);
        this.updateArrows();

        // Sem overflow → esconde as arrows completamente
        this.$wrap.toggleClass('slider--static', this.maxOffset === 0);
      },

      // ══════════════════════════════════════════════
      // NAVEGAÇÃO
      // ══════════════════════════════════════════════
      setOffset: function (px, animate) {
        this.offset = Math.max(0, Math.min(px, this.maxOffset));

        this.$track.css({
          transition: animate
            ? 'transform ' + SETTINGS.transitionMs + 'ms ' + SETTINGS.easing
            : 'none',
          transform: 'translate3d(-' + this.offset + 'px, 0, 0)',
        });

        this.updateArrows();
      },

      /** Avança/retrocede uma "página" = quantos cards couberem no viewport */
      page: function (direction) {
        var vpW      = this.$viewport.innerWidth();
        var cardsFit = Math.max(1, Math.floor(vpW / this.stepW));
        var delta    = cardsFit * this.stepW * direction;

        // Snap ao múltiplo do card para não cortar no meio
        var target = Math.round((this.offset + delta) / this.stepW) * this.stepW;
        this.setOffset(target, true);
      },

      /** Snap para o card mais próximo (chamado após drag) */
      snap: function () {
        var snapped = Math.round(this.offset / this.stepW) * this.stepW;
        this.setOffset(snapped, true);
      },

      updateArrows: function () {
        // Margem de 2px para tolerância de subpixel rendering
        this.$prev.toggleClass('is-hidden', this.offset <= 2);
        this.$next.toggleClass('is-hidden', this.offset >= this.maxOffset - 2);
      },

      // ══════════════════════════════════════════════
      // EVENTOS
      // ══════════════════════════════════════════════
      bindArrows: function () {
        var self = this;
        this.$prev.on('click', function () { self.page(-1); });
        this.$next.on('click', function () { self.page(1);  });
      },

      bindKeyboard: function () {
        var self = this;
        this.$wrap.attr('tabindex', '0').on('keydown', function (e) {
          if (e.key === 'ArrowLeft')  { e.preventDefault(); self.page(-1); }
          if (e.key === 'ArrowRight') { e.preventDefault(); self.page(1);  }
        });
      },

      bindDrag: function () {
        var self  = this;
        var track = this.$track[0];

        track.addEventListener('pointerdown', function (e) {
          // ── Não inicia drag em elementos interativos ──────
          // Quando o clique vem de um <a>, <button>, <input> etc. dentro
          // do card, não ativamos setPointerCapture: o evento fica livre
          // para chegar ao elemento correto (curtir, carrinho, link do produto).
          var alvo = e.target;
          while (alvo && alvo !== track) {
            var tag = (alvo.tagName || '').toUpperCase();
            if (tag === 'A' || tag === 'BUTTON' || tag === 'INPUT' ||
                tag === 'SELECT' || tag === 'TEXTAREA' || tag === 'LABEL') {
              return; // deixa o evento fluir naturalmente
            }
            alvo = alvo.parentElement;
          }

          // Só botão primário / toque
          if (e.button !== undefined && e.button !== 0) return;

          self.dragging   = true;
          self.dragStartX = self.dragLastX = e.clientX;
          self.dragBase   = self.offset;
          self.$track.addClass('is-dragging');

          // Captura o pointer: drag continua mesmo saindo do elemento
          track.setPointerCapture(e.pointerId);
        });

        track.addEventListener('pointermove', function (e) {
          if (!self.dragging) return;
          self.dragLastX = e.clientX;

          var diff   = self.dragStartX - e.clientX;
          var target = self.dragBase + diff;

          // ── Rubber-band nas bordas (feel nativo) ────
          if (target < 0) {
            target = target * SETTINGS.rubberBand;
          } else if (target > self.maxOffset) {
            var over = target - self.maxOffset;
            target   = self.maxOffset + over * SETTINGS.rubberBand;
          }

          self.$track.css({
            transition: 'none',
            transform : 'translate3d(-' + target + 'px, 0, 0)',
          });
        });

        function endDrag(e) {
          if (!self.dragging) return;
          self.dragging = false;
          self.$track.removeClass('is-dragging');

          var diff = self.dragStartX - self.dragLastX;

          if (Math.abs(diff) > SETTINGS.swipeThreshold) {
            // Swipe: avança na direção + snap ao card
            var target = Math.round((self.dragBase + diff) / self.stepW) * self.stepW;
            // Garante avanço de ao menos 1 card na direção do swipe
            if (target === self.dragBase) {
              target += (diff > 0 ? self.stepW : -self.stepW);
            }
            self.setOffset(target, true);
          } else {
            // Movimento pequeno: volta ao snap atual
            self.snap();
          }
        }

        track.addEventListener('pointerup',     endDrag);
        track.addEventListener('pointercancel', endDrag);

        // Previne click acidental após drag real (movimento > tolerância)
        // Não usa stopPropagation: o evento precisa chegar nos handlers dos cards
        this.$track.on('click', 'a, button', function (e) {
          if (Math.abs(self.dragStartX - self.dragLastX) > SETTINGS.clickTolerance) {
            e.preventDefault();
          }
        });

        // Permite scroll vertical da página em touch:
        // pan-y libera o eixo Y para o browser, X fica com o slider
        track.style.touchAction = 'pan-y';
      },

      // ══════════════════════════════════════════════
      // RESIZE — ResizeObserver no viewport
      // ══════════════════════════════════════════════
      observeResize: function () {
        var self = this;

        if (typeof ResizeObserver !== 'undefined') {
          var debounce;
          this.resizeObserver = new ResizeObserver(function () {
            clearTimeout(debounce);
            debounce = setTimeout(function () { self.measure(); }, 100);
          });
          this.resizeObserver.observe(this.$viewport[0]);
        } else {
          // Fallback para browsers antigos
          var timer;
          $(window).on('resize', function () {
            clearTimeout(timer);
            timer = setTimeout(function () { self.measure(); }, 150);
          });
        }
      },
    };

    // ══════════════════════════════════════════════════
    // BOOTSTRAP — inicializa todos os sliders da página
    // Auto-contido: não depende de IDs únicos
    // ══════════════════════════════════════════════════
    $(function () {
      $('.slider-wrap').each(function () {
        if (!$(this).data('product-slider')) {
          $(this).data('product-slider', new ProductSlider(this));
        }
      });
    });

    // API pública para inicializar sliders adicionados dinamicamente
    window.initProductSliders = function (context) {
      $(context || document).find('.slider-wrap').each(function () {
        if (!$(this).data('product-slider')) {
          $(this).data('product-slider', new ProductSlider(this));
        }
      });
    };

  }(jQuery));

}())