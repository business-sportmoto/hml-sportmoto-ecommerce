/**
 * lightbox.js — Lightbox universal
 *
 * USO:
 *   Imagem simples:
 *     <img src="foto.jpg" data-lightbox alt="...">
 *
 *   Grupo com next/prev:
 *     <img src="a.jpg" data-lightbox="galeria" data-lightbox-src="/full/a.jpg" data-lightbox-caption="Legenda A">
 *     <img src="b.jpg" data-lightbox="galeria" data-lightbox-src="/full/b.jpg">
 *
 *   Em qualquer elemento (link, div, botão):
 *     <a href="/full.jpg" data-lightbox="grupo" data-lightbox-caption="Texto">
 *       <img src="thumb.jpg">
 *     </a>
 *
 * ATRIBUTOS:
 *   data-lightbox           → ativa o lightbox (valor = nome do grupo; vazio = individual)
 *   data-lightbox-src       → URL da imagem em alta resolução (fallback: href do <a> ou src do <img>)
 *   data-lightbox-caption   → legenda exibida abaixo da imagem
 *   data-lightbox-group     → alias para data-lightbox
 *
 * API pública:
 *   LightBox.open(src, { caption, group, index })
 *   LightBox.close()
 *   LightBox.next()
 *   LightBox.prev()
 */
(function (window, document) {
  'use strict';

  // ── Estado ──────────────────────────────────────────────
  let _overlay   = null;
  let _img       = null;
  let _caption   = null;
  let _counter   = null;
  let _btnPrev   = null;
  let _btnNext   = null;
  let _loader    = null;
  let _group     = [];   // [{src, caption}]
  let _current   = 0;
  let _open      = false;
  let _touchStartX = 0;

  // ── Build DOM (uma única vez) ────────────────────────────
  function buildDOM() {
    if (document.getElementById('lb-overlay')) return;

    const tpl = `
<div id="lb-overlay" class="lb-overlay" role="dialog" aria-modal="true" aria-label="Visualizador de imagem" hidden>
  <div class="lb-backdrop"></div>

  <button class="lb-close" id="lb-close" aria-label="Fechar">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
      <line x1="18" y1="6" x2="6" y2="18"/>
      <line x1="6" y1="6" x2="18" y2="18"/>
    </svg>
  </button>

  <button class="lb-nav lb-prev" id="lb-prev" aria-label="Anterior" hidden>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
      <polyline points="15 18 9 12 15 6"/>
    </svg>
  </button>

  <div class="lb-stage">
    <div class="lb-loader" id="lb-loader">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
        <path d="M21 12a9 9 0 11-6.219-8.56"/>
      </svg>
    </div>
    <img class="lb-img" id="lb-img" src="" alt="" draggable="false">
  </div>

  <button class="lb-nav lb-next" id="lb-next" aria-label="Próxima" hidden>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
      <polyline points="9 18 15 12 9 6"/>
    </svg>
  </button>

  <div class="lb-footer">
    <p class="lb-caption" id="lb-caption"></p>
    <span class="lb-counter" id="lb-counter"></span>
  </div>
</div>`;

    document.body.insertAdjacentHTML('beforeend', tpl);

    _overlay = document.getElementById('lb-overlay');
    _img     = document.getElementById('lb-img');
    _caption = document.getElementById('lb-caption');
    _counter = document.getElementById('lb-counter');
    _btnPrev = document.getElementById('lb-prev');
    _btnNext = document.getElementById('lb-next');
    _loader  = document.getElementById('lb-loader');

    // Eventos
    document.getElementById('lb-close').addEventListener('click', LightBox.close);
    document.querySelector('#lb-overlay .lb-backdrop').addEventListener('click', LightBox.close);
    _btnPrev.addEventListener('click', LightBox.prev);
    _btnNext.addEventListener('click', LightBox.next);

    // Teclado
    document.addEventListener('keydown', function (e) {
      if (!_open) return;
      if (e.key === 'Escape')     LightBox.close();
      if (e.key === 'ArrowRight') LightBox.next();
      if (e.key === 'ArrowLeft')  LightBox.prev();
    });

    // Swipe mobile
    _overlay.addEventListener('touchstart', function (e) {
      _touchStartX = e.touches[0].clientX;
    }, { passive: true });

    _overlay.addEventListener('touchend', function (e) {
      const diff = _touchStartX - e.changedTouches[0].clientX;
      if (Math.abs(diff) > 50) {
        diff > 0 ? LightBox.next() : LightBox.prev();
      }
    }, { passive: true });
  }

  // ── Renderiza item atual ─────────────────────────────────
  function renderItem() {
    const item = _group[_current];
    if (!item) return;

    // Loader
    _loader.style.display  = 'flex';
    _img.style.opacity     = '0';

    const total  = _group.length;
    const isMany = total > 1;

    // Nav
    _btnPrev.hidden = !isMany;
    _btnNext.hidden = !isMany;
    _btnPrev.disabled = _current === 0;
    _btnNext.disabled = _current === total - 1;

    // Counter
    _counter.textContent = isMany ? `${_current + 1} / ${total}` : '';

    // Caption
    _caption.textContent = item.caption || '';
    _caption.hidden      = !item.caption;

    // Carrega imagem
    const tmp = new Image();
    tmp.onload = function () {
      _img.src           = item.src;
      _img.alt           = item.caption || '';
      _img.style.opacity = '1';
      _loader.style.display = 'none';
    };
    tmp.onerror = function () {
      _loader.style.display = 'none';
      _img.style.opacity    = '1';
    };
    tmp.src = item.src;
  }

  // ── Coleta src de um elemento ────────────────────────────
  function getSrc(el) {
    return el.dataset.lightboxSrc
        || el.getAttribute('href')
        || (el.tagName === 'IMG' ? el.src : null)
        || el.querySelector('img')?.src
        || '';
  }

  // ── Monta grupos a partir dos data-lightbox ──────────────
  function collectGroup(triggerEl) {
    const groupName = triggerEl.dataset.lightbox
                   || triggerEl.dataset.lightboxGroup
                   || '';

    if (!groupName) {
      // Individual
      return [{
        src:     getSrc(triggerEl),
        caption: triggerEl.dataset.lightboxCaption || triggerEl.getAttribute('alt') || '',
        el:      triggerEl,
      }];
    }

    // Grupo — coleta todos com o mesmo nome
    const selector = `[data-lightbox="${groupName}"], [data-lightbox-group="${groupName}"]`;
    return Array.from(document.querySelectorAll(selector)).map(el => ({
      src:     getSrc(el),
      caption: el.dataset.lightboxCaption || el.getAttribute('alt') || el.querySelector('img')?.getAttribute('alt') || '',
      el,
    }));
  }

  // ── API pública ──────────────────────────────────────────
  const LightBox = {
    open(triggerEl, startIndex = 0) {
      buildDOM();
      _group   = collectGroup(triggerEl);
      _current = startIndex;
      _open    = true;

      _overlay.hidden = false;
      document.body.style.overflow = 'hidden';
      renderItem();
    },

    openItems(items, startIndex = 0) {
      buildDOM();
      _group   = items; // [{src, caption}]
      _current = startIndex;
      _open    = true;

      _overlay.hidden = false;
      document.body.style.overflow = 'hidden';
      renderItem();
    },

    close() {
      if (!_overlay) return;
      _open = false;
      _overlay.hidden = true;
      document.body.style.overflow = '';
      _img.src = '';
    },

    next() {
      if (_current < _group.length - 1) {
        _current++;
        renderItem();
      }
    },

    prev() {
      if (_current > 0) {
        _current--;
        renderItem();
      }
    },
  };

  // ── Auto-bind em [data-lightbox] ─────────────────────────
  function bindAll() {
    document.addEventListener('click', function (e) {
      const el = e.target.closest('[data-lightbox], [data-lightbox-group]');
      if (!el) return;

      // Não ativa se for um <a> sem data-lightbox-src e sem grupo
      if (el.tagName === 'A' && !el.dataset.lightboxSrc && !el.dataset.lightbox) return;

      e.preventDefault();

      const groupName = el.dataset.lightbox || el.dataset.lightboxGroup || '';
      const group     = groupName
        ? Array.from(document.querySelectorAll(`[data-lightbox="${groupName}"], [data-lightbox-group="${groupName}"]`))
        : [el];

      const startIndex = group.indexOf(el);
      LightBox.open(el, Math.max(0, startIndex));
    });
  }

  // ── Init ─────────────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', function () {
    buildDOM();
    bindAll();
  });

  window.LightBox = LightBox;

}(window, document));