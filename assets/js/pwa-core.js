/**
 * assets/js/pwa-core.js
 * Todos os módulos PWA em um único arquivo.
 * CSS em: assets/css/pwa-style.css
 *
 * Módulos inclusos:
 *  1. iOS Fix          — intercepta navegação e formulários em standalone
 *  2. Progress Bar     — barra de progresso entre páginas (expõe window.PageProgress)
 *  3. Pull-to-Refresh  — gesto de arrastar para recarregar
 *  4. View Transitions — animações entre páginas (fallback para não-Chrome 126)
 *  5. Splash Screen    — overlay animado no primeiro acesso da sessão
 */
(function () {
  'use strict';

  // ══════════════════════════════════════════════════════
  // DETECÇÃO DE STANDALONE — compartilhada por todos os módulos
  // ══════════════════════════════════════════════════════
  var isStandalone = window.navigator.standalone === true ||
                     window.matchMedia('(display-mode: standalone)').matches;

  // Adiciona classes utilitárias no <html> para CSS condicional
  if (/iPad|iPhone|iPod/.test(navigator.userAgent)) {
    document.documentElement.classList.add('is-ios');
    if (isStandalone) document.documentElement.classList.add('is-ios-standalone');
  }
  if (isStandalone && !document.documentElement.classList.contains('is-ios-standalone')) {
    document.documentElement.classList.add('is-pwa-standalone');
  }

  // ══════════════════════════════════════════════════════
  // 1. iOS FIX
  // Intercepta links internos e forms para manter a navegação
  // dentro do app (sem abrir o Safari).
  // Também resolve o bfcache que congela estados no iOS.
  // ══════════════════════════════════════════════════════
  if (isStandalone) {

    // Links internos → window.location em vez de abrir Safari
    document.addEventListener('click', function (e) {
      var node = e.target;
      while (node && node.tagName !== 'A') node = node.parentNode;
      if (!node || !node.href) return;
      if (node.href.indexOf(window.location.origin) !== 0) return;
      if ((node.getAttribute('href') || '').charAt(0) === '#') return;
      if (node.getAttribute('download') !== null) return;
      if (node.getAttribute('target') === '_blank') return;
      e.preventDefault();
      window.location.href = node.href;
    });

    // Forms GET também ficam dentro do app
    document.addEventListener('submit', function (e) {
      var form = e.target;
      if (form.method && form.method.toLowerCase() === 'get') {
        e.preventDefault();
        var data   = new URLSearchParams(new FormData(form));
        var action = form.action || window.location.href;
        window.location.href = action + (action.includes('?') ? '&' : '?') + data.toString();
      }
    });

    // bfcache — recarrega ao voltar para garantir estado fresco
    window.addEventListener('pageshow', function (e) {
      if (e.persisted) window.location.reload();
    });
  }

  // ══════════════════════════════════════════════════════
  // 2. PROGRESS BAR
  // Barra fina no topo durante a navegação.
  // Expõe window.PageProgress para uso externo.
  // ══════════════════════════════════════════════════════
  var BAR_KEY = '_pgbar_active';

  // Cria elemento (mesmo sem standalone — pode ser chamado via PageProgress)
  var bar  = document.createElement('div');
  bar.id   = 'page-progress';
  bar.innerHTML = '<div id="page-progress-fill"></div>';

  function mountBar() { document.body.appendChild(bar); }
  if (document.body) mountBar();
  else document.addEventListener('DOMContentLoaded', mountBar);

  var fill      = null;
  var barTimer  = null;
  var stepTimer = null;

  function getBarFill() {
    if (!fill) fill = document.getElementById('page-progress-fill');
    return fill;
  }

  var Progress = {
    start: function () {
      if (!isStandalone) return;
      clearTimeout(barTimer);
      clearTimeout(stepTimer);
      var f = getBarFill();
      if (!f) return;
      bar.classList.remove('is-done');
      bar.classList.add('is-visible');
      f.style.transition = 'none';
      f.style.width      = '0%';
      void f.offsetWidth;
      sessionStorage.setItem(BAR_KEY, '1');
      var steps  = [25, 50, 72];
      var delays = [100, 600, 1400];
      steps.forEach(function (w, i) {
        stepTimer = setTimeout(function () {
          if (!sessionStorage.getItem(BAR_KEY)) return;
          f.style.transition = 'width .35s cubic-bezier(.22,1,.36,1)';
          f.style.width      = w + '%';
        }, delays[i]);
      });
    },
    done: function () {
      clearTimeout(stepTimer);
      sessionStorage.removeItem(BAR_KEY);
      bar.classList.add('is-done');
      barTimer = setTimeout(function () {
        bar.classList.remove('is-visible', 'is-done');
        var f = getBarFill();
        if (f) f.style.width = '0%';
      }, 600);
    },
    isActive: function () { return sessionStorage.getItem(BAR_KEY) === '1'; },
  };

  window.PageProgress = Progress;

  // Completa ao carregar a nova página
  if (isStandalone && Progress.isActive()) {
    var completeBar = function () {
      var f = getBarFill();
      if (f) {
        bar.classList.add('is-visible');
        f.style.transition = 'none';
        f.style.width      = '82%';
        void f.offsetWidth;
      }
      setTimeout(function () { Progress.done(); }, 80);
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', completeBar);
    else completeBar();
  }

  // Inicia nos cliques (sem duplicar os já interceptados pelo iOS fix)
  if (isStandalone) {
    document.addEventListener('click', function (e) {
      var node = e.target;
      while (node && node.tagName !== 'A') node = node.parentNode;
      if (!node || !node.href) return;
      if (node.href.indexOf(window.location.origin) !== 0) return;
      if ((node.getAttribute('href') || '').charAt(0) === '#') return;
      if (node.getAttribute('download') !== null) return;
      if (node.getAttribute('target') === '_blank') return;
      if (node.getAttribute('data-no-progress') !== null) return;
      if (node.href === window.location.href) return;
      Progress.start();
    });

    document.addEventListener('submit', function (e) {
      var form = e.target;
      if (form.getAttribute('data-no-progress') !== null) return;
      if (form.getAttribute('data-ajax') !== null) return;
      Progress.start();
    });
  }

  // ══════════════════════════════════════════════════════
  // 3. PULL-TO-REFRESH
  // Gesto de arrastar para baixo para recarregar.
  // CSS: pwa-style.css → #ptr-wrap, #ptr-pill, etc.
  // ══════════════════════════════════════════════════════
  if (isStandalone) {
    var PTR_THRESHOLD  = 75;
    var PTR_MAX        = 110;
    var PTR_RESISTANCE = 0.42;

    var ptrStartY = 0, ptrCurrentY = 0;
    var ptrPulling = false, ptrCanPull = false, ptrRefreshing = false;

    var ptrWrap = document.createElement('div');
    ptrWrap.id  = 'ptr-wrap';
    ptrWrap.innerHTML =
      '<div id="ptr-pill">' +
        '<div id="ptr-icon">' +
          '<svg class="ptr-arrow" width="16" height="16" viewBox="0 0 24 24"' +
           ' fill="none" stroke="#64748b" stroke-width="2.5" stroke-linecap="round">' +
           '<line x1="12" y1="5" x2="12" y2="19"/>' +
           '<polyline points="19 12 12 19 5 12"/></svg>' +
          '<svg class="ptr-spinner" width="16" height="16" viewBox="0 0 24 24"' +
           ' fill="none" stroke="#3b82f6" stroke-width="2.5" stroke-linecap="round"' +
           ' style="display:none;">' +
           '<path d="M21 12a9 9 0 11-6.219-8.56"/></svg>' +
        '</div>' +
        '<span id="ptr-text">Puxe para atualizar</span>' +
      '</div>';

    function mountPtr() { document.body.appendChild(ptrWrap); }
    if (document.body) mountPtr();
    else document.addEventListener('DOMContentLoaded', mountPtr);

    var ptrTextEl = null;
    function getPtrText() {
      if (!ptrTextEl) ptrTextEl = document.getElementById('ptr-text');
      return ptrTextEl;
    }

    function ptrSetPos(dy) {
      var v = Math.min(dy * PTR_RESISTANCE, PTR_MAX * PTR_RESISTANCE);
      ptrWrap.style.transform = 'translateX(-50%) translateY(' + (v - 64) + 'px)';
    }
    function ptrReset(animate) {
      ptrWrap.style.transition = animate ? 'transform .32s cubic-bezier(.22,1,.36,1)' : 'none';
      ptrWrap.style.transform  = 'translateX(-50%) translateY(-100px)';
    }
    function ptrLock() {
      ptrWrap.style.transition = 'transform .32s cubic-bezier(.22,1,.36,1)';
      ptrWrap.style.transform  = 'translateX(-50%) translateY(calc(env(safe-area-inset-top,0px) + 12px))';
    }
    function ptrTrigger() {
      ptrRefreshing = true;
      ptrWrap.classList.remove('is-ready');
      ptrWrap.classList.add('is-refreshing');
      ptrLock();
      var t = getPtrText();
      if (t) t.textContent = 'Atualizando…';
      Progress.start();
      setTimeout(function () { window.location.reload(); }, 350);
    }

    document.addEventListener('touchstart', function (e) {
      if (ptrRefreshing) return;
      var st = document.documentElement.scrollTop || document.body.scrollTop || 0;
      if (st > 2) return;
      var el = e.target;
      while (el && el !== document.body) {
        var ov = getComputedStyle(el).overflowY;
        if ((ov === 'scroll' || ov === 'auto') && el.scrollTop > 0) return;
        el = el.parentElement;
      }
      ptrCanPull = true;
      ptrPulling = false;
      ptrStartY  = e.touches[0].clientY;
      ptrCurrentY= ptrStartY;
      ptrWrap.style.transition = 'none';
    }, { passive: true });

    document.addEventListener('touchmove', function (e) {
      if (!ptrCanPull || ptrRefreshing) return;
      ptrCurrentY = e.touches[0].clientY;
      var dy = ptrCurrentY - ptrStartY;
      if (dy <= 0) {
        if (ptrPulling) { ptrPulling = false; ptrWrap.classList.remove('is-ready'); ptrReset(false); }
        return;
      }
      ptrPulling = true;
      ptrSetPos(dy);
      var t = getPtrText();
      if (dy >= PTR_THRESHOLD) {
        if (!ptrWrap.classList.contains('is-ready')) {
          ptrWrap.classList.add('is-ready');
          if (t) t.textContent = 'Solte para atualizar';
          if (navigator.vibrate) navigator.vibrate(8);
        }
      } else {
        ptrWrap.classList.remove('is-ready');
        if (t) t.textContent = 'Puxe para atualizar';
      }
    }, { passive: true });

    document.addEventListener('touchend', function () {
      if (!ptrCanPull || ptrRefreshing) { ptrCanPull = false; return; }
      ptrCanPull = false;
      if (!ptrPulling) return;
      ptrPulling = false;
      if (ptrCurrentY - ptrStartY >= PTR_THRESHOLD) {
        ptrTrigger();
      } else {
        ptrWrap.classList.remove('is-ready');
        var t = getPtrText();
        if (t) t.textContent = 'Puxe para atualizar';
        ptrReset(true);
      }
    });

    document.addEventListener('touchcancel', function () {
      ptrCanPull = false;
      if (ptrPulling && !ptrRefreshing) { ptrPulling = false; ptrReset(true); }
    });
  }

  // ══════════════════════════════════════════════════════
  // 4. VIEW TRANSITIONS (fallback para navegadores sem suporte nativo)
  // Chrome 126+: CSS @view-transition cuida sozinho.
  // Safari + Chrome antigo: fade via .page-exit / .page-enter
  // ══════════════════════════════════════════════════════
  if (isStandalone) {
    var nativeVT = typeof CSS !== 'undefined' &&
                   CSS.supports('(view-transition-name: none)') &&
                   typeof document.startViewTransition === 'function';

    if (!nativeVT) {
      var VT_DURATION = 180;

      function vtNavigate(url) {
        Progress.start();
        document.body.classList.add('page-exit');
        setTimeout(function () { window.location.href = url; }, VT_DURATION);
      }

      // Sobrescreve o listener do iOS fix para este contexto
      document.addEventListener('click', function (e) {
        var node = e.target;
        while (node && node.tagName !== 'A') node = node.parentNode;
        if (!node || !node.href) return;
        if (node.href.indexOf(window.location.origin) !== 0) return;
        if ((node.getAttribute('href') || '').charAt(0) === '#') return;
        if (node.getAttribute('download') !== null) return;
        if (node.getAttribute('target') === '_blank') return;
        if (node.getAttribute('data-no-progress') !== null) return;
        if (node.href === window.location.href) return;
        e.preventDefault();
        vtNavigate(node.href);
      });

      // Aplica .page-enter na nova página
      var enterPage = function () {
        document.body.classList.remove('page-exit');
        document.body.classList.add('page-enter');
        setTimeout(function () { document.body.classList.remove('page-enter'); }, 300);
      };
      if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', enterPage);
      else enterPage();
    }
  }

  // ══════════════════════════════════════════════════════
  // 5. SPLASH SCREEN
  // Overlay animado na primeira abertura da sessão PWA.
  // ══════════════════════════════════════════════════════
  if (isStandalone) {
    var SPLASH_KEY = '_pwa_splashed';
    if (!sessionStorage.getItem(SPLASH_KEY)) {
      sessionStorage.setItem(SPLASH_KEY, '1');

      var cfg  = window.__PWA_CONFIG__ || {};
      var icon = cfg.icon || '/assets/images/icon-192.png';
      var name = cfg.name || '';
      var bg   = cfg.bg   || '#0f172a';

      var splash = document.createElement('div');
      splash.id  = 'pwa-splash';
      splash.style.setProperty('--pwa-splash-bg', bg);
      splash.innerHTML =
        '<img src="' + icon + '" alt="">' +
        (name ? '<span class="splash-name">' + name + '</span>' : '');

      function mountSplash() {
        document.body.appendChild(splash);
        setTimeout(function () { splash.remove(); }, 1400);
      }
      if (document.body) mountSplash();
      else document.addEventListener('DOMContentLoaded', mountSplash);
    }
  }

})();