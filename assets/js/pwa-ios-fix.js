/**
 * assets/js/pwa-ios-fix.js
 * Correções específicas para PWA no iOS Safari.
 * Incluir em TODOS os layouts (customer.php, main.php, admin, etc.)
 */
(function () {
  'use strict';

  // Detecta se está rodando como PWA instalado no iOS
  var isIosStandalone = window.navigator.standalone === true;

  // ── Fix 1: intercepta navegação interna ────────────────
  // Em standalone no iOS, <a href> abre o Safari. Este script força
  // a navegação dentro do próprio app para URLs do mesmo domínio.
  if (isIosStandalone) {
    document.addEventListener('click', function (e) {
      var node = e.target;

      // Sobe na árvore DOM até encontrar um <a>
      while (node && node.tagName !== 'A') {
        node = node.parentNode;
      }
      if (!node || !node.href) return;

      var url = node.href;

      // Só intercepta links do mesmo domínio
      if (url.indexOf(window.location.origin) !== 0) return;

      // Não intercepta âncoras (#), downloads ou target=_blank
      if (node.getAttribute('href').charAt(0) === '#') return;
      if (node.getAttribute('download')) return;
      if (node.getAttribute('target') === '_blank') return;

      e.preventDefault();
      window.location.href = url;
    });
  }

  // ── Fix 2: intercepta submits de formulário ─────────────
  // Em standalone iOS, forms com method GET às vezes abrem o Safari.
  if (isIosStandalone) {
    document.addEventListener('submit', function (e) {
      var form = e.target;
      if (form.method && form.method.toLowerCase() === 'get') {
        e.preventDefault();
        var data   = new URLSearchParams(new FormData(form));
        var action = form.action || window.location.href;
        window.location.href = action + (action.includes('?') ? '&' : '?') + data.toString();
      }
    });
  }

  // ── Fix 3: força recarga limpa ao voltar (bfcache iOS) ──
  // iOS mantém páginas em cache e não executa JS ao usar "voltar".
  // Isso causa estados inconsistentes (carrinho desatualizado, etc.)
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) {
      // Página veio do bfcache — recarrega silenciosamente
      window.location.reload();
    }
  });

  // ── Fix 4: classe no body para estilização iOS-específica
  if (/iPad|iPhone|iPod/.test(navigator.userAgent)) {
    document.documentElement.classList.add('is-ios');
    if (isIosStandalone) {
      document.documentElement.classList.add('is-ios-standalone');
    }
  }

})();


/**
 * assets/js/pwa-progress.js
 * Barra de progresso de navegação — só ativa em modo PWA instalado.
 */
(function () {
  'use strict';

  // ── Só roda se estiver em modo standalone (PWA instalado) ──
  var isStandalone = window.navigator.standalone === true ||
                     window.matchMedia('(display-mode: standalone)').matches;
  if (!isStandalone) return;

  var BAR_KEY = '_pgbar_active';

  // ── Cria a barra e injeta direto no body ──────────────
  // IMPORTANTE: deve ser filho direto do body para que
  // position:fixed não seja quebrado por transforms no layout
  var bar = document.createElement('div');
  bar.id  = 'page-progress';
  bar.innerHTML = '<div id="page-progress-fill"></div>';

  var style = document.createElement('style');
  style.textContent = [
    '#page-progress{',
    '  position:fixed;top:0;left:0;right:0;',
    '  height:3px;z-index:2147483647;',   // z-index máximo possível
    '  pointer-events:none;opacity:0;',
    '  transition:opacity .2s ease;',
    '}',
    '#page-progress.is-visible{opacity:1;}',
    '#page-progress-fill{',
    '  height:100%;width:0%;',
    '  background:var(--pgbar-color,#3b82f6);',
    '  box-shadow:0 0 6px var(--pgbar-color,#3b82f6);',
    '  transition:width .35s cubic-bezier(.22,1,.36,1);',
    '  border-radius:0 2px 2px 0;',
    '}',
    '#page-progress.is-done #page-progress-fill{width:100%!important;}',
    '#page-progress.is-done{transition:opacity .3s ease .2s;opacity:0;}',
  ].join('');
  document.head.appendChild(style);

  // Aguarda o body existir (script pode rodar antes do body)
  function mount() {
    document.body.appendChild(bar);
  }
  if (document.body) {
    mount();
  } else {
    document.addEventListener('DOMContentLoaded', mount);
  }

  var fill      = null;
  var timer     = null;
  var stepTimer = null;

  function getFill() {
    if (!fill) fill = document.getElementById('page-progress-fill');
    return fill;
  }

  // ── API ───────────────────────────────────────────────
  var Progress = {
    start: function () {
      clearTimeout(timer);
      clearTimeout(stepTimer);
      var f = getFill();
      if (!f) return;

      bar.classList.remove('is-done');
      bar.classList.add('is-visible');
      f.style.transition = 'none';
      f.style.width = '0%';
      void f.offsetWidth; // reflow

      sessionStorage.setItem(BAR_KEY, '1');

      var steps  = [25, 50, 72];
      var delays = [100, 600, 1400];
      steps.forEach(function (w, i) {
        stepTimer = setTimeout(function () {
          if (!sessionStorage.getItem(BAR_KEY)) return;
          f.style.transition = 'width .35s cubic-bezier(.22,1,.36,1)';
          f.style.width = w + '%';
        }, delays[i]);
      });
    },

    done: function () {
      clearTimeout(stepTimer);
      sessionStorage.removeItem(BAR_KEY);
      bar.classList.add('is-done');
      timer = setTimeout(function () {
        bar.classList.remove('is-visible', 'is-done');
        var f = getFill();
        if (f) f.style.width = '0%';
      }, 600);
    },

    isActive: function () {
      return sessionStorage.getItem(BAR_KEY) === '1';
    },
  };

  window.PageProgress = Progress;

  // ── Completa ao carregar nova página ──────────────────
  if (Progress.isActive()) {
    var completar = function () {
      var f = getFill();
      if (f) {
        bar.classList.add('is-visible');
        f.style.transition = 'none';
        f.style.width = '82%';
        void f.offsetWidth;
      }
      setTimeout(function () { Progress.done(); }, 80);
    };

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', completar);
    } else {
      completar();
    }
  }

  // ── Ouve cliques em links internos ────────────────────
  document.addEventListener('click', function (e) {
    var node = e.target;
    while (node && node.tagName !== 'A') node = node.parentNode;
    if (!node || !node.href) return;
    if (node.href.indexOf(window.location.origin) !== 0) return;
    var href = node.getAttribute('href') || '';
    if (href.charAt(0) === '#') return;
    if (node.getAttribute('download') !== null) return;
    if (node.getAttribute('target') === '_blank') return;
    if (node.getAttribute('data-no-progress') !== null) return;
    if (node.href === window.location.href) return;
    Progress.start();
  });

  // ── Ouve submits de form ──────────────────────────────
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (form.getAttribute('data-no-progress') !== null) return;
    if (form.getAttribute('data-ajax') !== null) return;
    Progress.start();
  });

})();


/**
 * assets/js/pwa-pull-refresh.js
 * Pull-to-refresh — só ativa em modo PWA instalado.
 */
(function () {
  'use strict';

  // ── Só roda em modo standalone ────────────────────────
  var isStandalone = window.navigator.standalone === true ||
                     window.matchMedia('(display-mode: standalone)').matches;
  if (!isStandalone) return;

  var THRESHOLD  = 300;   // px para ativar o reload
  var MAX_PULL   = 300;  // px máximo de arraste visual
  var RESISTANCE = 0.62; // amortecimento do arraste

  var startY     = 0;
  var currentY   = 0;
  var pulling    = false;
  var canPull    = false;
  var refreshing = false;

  // ── CSS ───────────────────────────────────────────────
  var style = document.createElement('style');
  style.textContent = [

    // Previne pull-to-refresh nativo do Chrome em standalone
    'html { overscroll-behavior-y: contain; }',

    // Pill indicador
    '#ptr-wrap {',
    '  position: fixed;',
    '  top: 0; left: 50%;',
    '  transform: translateX(-50%) translateY(-100px);',
    '  z-index: 2147483646;',
    '  pointer-events: none;',
    '  padding-top: env(safe-area-inset-top, 0px);',
    '}',
    '#ptr-pill {',
    '  display: flex; align-items: center; gap: 8px;',
    '  background: #fff;',
    '  border-radius: 99px;',
    '  padding: 8px 16px 8px 10px;',
    '  box-shadow: 0 4px 20px rgba(0,0,0,.14), 0 1px 4px rgba(0,0,0,.08);',
    '  font-size: 13px; font-weight: 600;',
    '  color: #475569;',
    '  white-space: nowrap;',
    '  font-family: -apple-system, system-ui, sans-serif;',
    '  transition: background .2s, color .2s;',
    '}',
    '#ptr-icon {',
    '  width: 28px; height: 28px;',
    '  border-radius: 50%;',
    '  background: #f1f5f9;',
    '  display: flex; align-items: center; justify-content: center;',
    '  transition: background .2s, transform .25s cubic-bezier(.34,1.56,.64,1);',
    '  flex-shrink: 0;',
    '}',
    '#ptr-icon svg { display: block; }',

    // Estado: pronto para soltar
    '#ptr-wrap.is-ready #ptr-pill { background: #f0fdf4; color: #15803d; }',
    '#ptr-wrap.is-ready #ptr-icon { background: #dcfce7; }',
    '#ptr-wrap.is-ready #ptr-icon svg { stroke: #16a34a; }',
    '#ptr-wrap.is-ready #ptr-icon { transform: rotate(180deg); }',

    // Estado: recarregando — substitui arrow por spinner
    '#ptr-wrap.is-refreshing #ptr-icon { background: #eff6ff; animation: none; }',
    '#ptr-wrap.is-refreshing #ptr-icon svg.ptr-arrow   { display: none; }',
    '#ptr-wrap.is-refreshing #ptr-icon svg.ptr-spinner { display: block !important; }',
    '#ptr-wrap.is-refreshing #ptr-pill { background: #eff6ff; color: #2563eb; }',
    '@keyframes ptr-spin { to { transform: rotate(360deg); } }',
    '.ptr-spinner { animation: ptr-spin .7s linear infinite; transform-origin: center; }',
  ].join('\n');
  document.head.appendChild(style);

  // ── HTML da pill ──────────────────────────────────────
  var wrap = document.createElement('div');
  wrap.id  = 'ptr-wrap';

  // Arrow SVG (usado em pulling + ready)
  var arrowSvg =
    '<svg class="ptr-arrow" width="16" height="16" viewBox="0 0 24 24"' +
    ' fill="none" stroke="#64748b" stroke-width="2.5" stroke-linecap="round">' +
    '<line x1="12" y1="5" x2="12" y2="19"/>' +
    '<polyline points="19 12 12 19 5 12"/>' +
    '</svg>';

  // Spinner SVG (usado em refreshing)
  var spinnerSvg =
    '<svg class="ptr-spinner" width="16" height="16" viewBox="0 0 24 24"' +
    ' fill="none" stroke="#3b82f6" stroke-width="2.5" stroke-linecap="round"' +
    ' style="display:none;">' +
    '<path d="M21 12a9 9 0 11-6.219-8.56"/>' +
    '</svg>';

  wrap.innerHTML =
    '<div id="ptr-pill">' +
      '<div id="ptr-icon">' + arrowSvg + spinnerSvg + '</div>' +
      '<span id="ptr-text">Puxe para atualizar</span>' +
    '</div>';

  if (document.body) {
    document.body.appendChild(wrap);
  } else {
    document.addEventListener('DOMContentLoaded', function () {
      document.body.appendChild(wrap);
    });
  }

  var textEl = null;
  function getText() {
    if (!textEl) textEl = document.getElementById('ptr-text');
    return textEl;
  }

  // ── Posiciona a pill conforme o arraste ───────────────
  function setPullPosition(deltaY) {
    var visual = Math.min(deltaY * RESISTANCE, MAX_PULL * RESISTANCE);
    // -64px = pill está escondida acima (altura estimada da pill)
    wrap.style.transform =
      'translateX(-50%) translateY(' + (visual - 64) + 'px)';
  }

  function resetPosition(animate) {
    wrap.style.transition = animate
      ? 'transform .32s cubic-bezier(.22,1,.36,1)'
      : 'none';
    wrap.style.transform = 'translateX(-50%) translateY(-100px)';
  }

  function lockRefreshing() {
    wrap.style.transition = 'transform .32s cubic-bezier(.22,1,.36,1)';
    wrap.style.transform  = 'translateX(-50%) translateY(calc(env(safe-area-inset-top,0px) + 12px))';
  }

  // ── Touch handlers ────────────────────────────────────
  document.addEventListener('touchstart', function (e) {
    if (refreshing) return;

    var scrollTop = document.documentElement.scrollTop || document.body.scrollTop || 0;
    if (scrollTop > 2) return; // tolerância de 2px

    // Verifica se elemento pai tem scroll próprio
    var el = e.target;
    while (el && el !== document.body) {
      var ov = getComputedStyle(el).overflowY;
      if ((ov === 'scroll' || ov === 'auto') && el.scrollTop > 0) return;
      el = el.parentElement;
    }

    canPull  = true;
    pulling  = false;
    startY   = e.touches[0].clientY;
    currentY = startY;
    wrap.style.transition = 'none';

  }, { passive: true });

  document.addEventListener('touchmove', function (e) {
    if (!canPull || refreshing) return;

    currentY   = e.touches[0].clientY;
    var deltaY = currentY - startY;

    if (deltaY <= 0) {
      if (pulling) {
        pulling = false;
        wrap.classList.remove('is-ready');
        resetPosition(false);
      }
      return;
    }

    pulling = true;
    setPullPosition(deltaY);

    var t = getText();
    if (deltaY >= THRESHOLD) {
      if (!wrap.classList.contains('is-ready')) {
        wrap.classList.add('is-ready');
        if (t) t.textContent = 'Solte para atualizar';
        // Pulso tátil se disponível
        if (navigator.vibrate) navigator.vibrate(8);
      }
    } else {
      wrap.classList.remove('is-ready');
      if (t) t.textContent = 'Puxe para atualizar';
    }

  }, { passive: true });

  document.addEventListener('touchend', function () {
    if (!canPull || refreshing) { canPull = false; return; }
    canPull = false;

    var deltaY = currentY - startY;
    if (!pulling) return;
    pulling = false;

    if (deltaY >= THRESHOLD) {
      // ── Aciona o reload ──────────────────────────────
      refreshing = true;
      wrap.classList.remove('is-ready');
      wrap.classList.add('is-refreshing');
      lockRefreshing();

      var t = getText();
      if (t) t.textContent = 'Atualizando…';

      if (window.PageProgress) window.PageProgress.start();
      setTimeout(function () { window.location.reload(); }, 350);

    } else {
      wrap.classList.remove('is-ready');
      var t2 = getText();
      if (t2) t2.textContent = 'Puxe para atualizar';
      resetPosition(true);
    }
  });

  document.addEventListener('touchcancel', function () {
    canPull = false;
    if (pulling && !refreshing) {
      pulling = false;
      wrap.classList.remove('is-ready');
      resetPosition(true);
    }
  });

})();