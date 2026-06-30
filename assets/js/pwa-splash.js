/**
 * assets/js/pwa-splash.js
 * Splash screen animado — só mostra na primeira abertura do PWA por sessão.
 */
(function () {
  'use strict';

  var isStandalone = window.navigator.standalone === true ||
                     window.matchMedia('(display-mode: standalone)').matches;
  if (!isStandalone) return;

  var KEY = '_pwa_splashed';
  if (sessionStorage.getItem(KEY)) return; // já mostrou nesta sessão
  sessionStorage.setItem(KEY, '1');

  // Lê configurações injetadas pelo PHP (ver customer.php)
  var cfg  = window.__PWA_CONFIG__ || {};
  var icon = cfg.icon || '/icons/icon-192.png';
  var name = cfg.name || '';
  var bg   = cfg.bg   || '#0f172a';

  var el = document.createElement('div');
  el.id  = 'pwa-splash';
  el.style.setProperty('--pwa-splash-bg', bg);

  el.innerHTML =
    '<img src="' + icon + '" alt="">' +
    (name ? '<span class="splash-name">' + name + '</span>' : '');

  document.body.appendChild(el);

  // Remove do DOM após a animação terminar
  setTimeout(function () {
    el.style.display = 'none';
    el.remove();
  }, 1400);

})();