/**
 * admin-login.js — interações do login administrativo.
 * Salvar em: admin/assets/js/admin-login.js
 *
 * CSP-SAFE: arquivo externo (nada de inline-script), compatível com
 * script-src 'self'. Sem dependências. Progressive enhancement — o form
 * funciona mesmo sem JS.
 */
(function () {
  'use strict';

  // --- Toggle de visibilidade da senha -----------------------------------
  var toggle = document.getElementById('togglePwd');
  var pwd = document.getElementById('senha');

  if (toggle && pwd) {
    toggle.addEventListener('click', function () {
      var show = pwd.type === 'password';
      pwd.type = show ? 'text' : 'password';
      toggle.setAttribute('aria-label', show ? 'Ocultar senha' : 'Mostrar senha');
      var eye = toggle.querySelector('.icon-eye');
      var eyeOff = toggle.querySelector('.icon-eye-off');
      if (eye && eyeOff) {
        eye.hidden = show;
        eyeOff.hidden = !show;
      }
      pwd.focus();
    });
  }

  // --- Estado de loading no submit (evita duplo-envio) --------------------
  var form = document.getElementById('loginForm');
  var submit = document.getElementById('loginSubmit');

  if (form && submit) {
    form.addEventListener('submit', function () {
      // Deixa o browser validar required/email antes de travar o botão.
      if (form.checkValidity()) {
        submit.classList.add('is-loading');
        // Trava reenvio; o navegador ainda submete normalmente.
        setTimeout(function () { submit.setAttribute('disabled', 'disabled'); }, 0);
      }
    });
  }
})();