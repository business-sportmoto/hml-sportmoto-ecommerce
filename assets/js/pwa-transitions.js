/**
 * assets/js/pwa-transitions.js
 * View Transitions para MPA.
 *
 * - Chrome 126+: usa CSS @view-transition nativo (zero JS necessário)
 * - Outros browsers: fallback com fade via classe .page-exit / .page-enter
 * - Só ativa em modo standalone (PWA instalado)
 */
(function () {
  'use strict';

  var isStandalone = window.navigator.standalone === true ||
                     window.matchMedia('(display-mode: standalone)').matches;
  if (!isStandalone) return;

  // Se o browser suporta CSS @view-transition nativo, não precisa de JS
  // Verifica a presença pelo at-rule ou pela API document.startViewTransition
  var nativeVT = CSS && CSS.supports('(view-transition-name: none)') &&
                 typeof document.startViewTransition === 'function';

  if (nativeVT) return; // CSS cuida de tudo

  // ── Fallback: fade via classes CSS ───────────────────
  var DURATION_OUT = 180;

  function navigate(url) {
    if (window.PageProgress) window.PageProgress.start();

    document.body.classList.add('page-exit');

    setTimeout(function () {
      window.location.href = url;
    }, DURATION_OUT);
  }

  // Intercepta links internos
  document.addEventListener('click', function (e) {
    var node = e.target;
    while (node && node.tagName !== 'A') node = node.parentNode;
    if (!node || !node.href) return;
    if (node.href.indexOf(window.location.origin) !== 0) return;
    var href = node.getAttribute('href') || '';
    if (href.charAt(0) === '#')                          return;
    if (node.getAttribute('download') !== null)          return;
    if (node.getAttribute('target') === '_blank')        return;
    if (node.getAttribute('data-no-progress') !== null)  return;
    if (node.href === window.location.href)              return;

    e.preventDefault();
    navigate(node.href);
  });

  // Aplica .page-enter na nova página
  var completar = function () {
    document.body.classList.remove('page-exit');
    document.body.classList.add('page-enter');
    setTimeout(function () {
      document.body.classList.remove('page-enter');
    }, 300);
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', completar);
  } else {
    completar();
  }

})();