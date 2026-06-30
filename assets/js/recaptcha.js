/**
 * recaptcha.js
 * Helper genérico para gerar tokens do reCAPTCHA v3 antes de enviar
 * um formulário. Não assume nenhum framework de submit específico —
 * funciona com fetch, $.ajax, FormData, o que já existir no projeto.
 *
 * Requer que o script do Google já esteja carregado na página:
 *   <script src="https://www.google.com/recaptcha/api.js?render=SITE_KEY"></script>
 *
 * Uso típico (dentro do submit handler do login):
 *
 *   async function fazerLogin() {
 *     const token = await Recaptcha.getToken('login');
 *     // ... inclui token no FormData/body como 'recaptcha_token' ...
 *   }
 *
 * O 'action' (ex: 'login') é só um rótulo que aparece no painel do
 * Google reCAPTCHA para você analisar scores por tipo de ação —
 * não afeta a validação em si.
 */
window.Recaptcha = (function () {
  'use strict';

  var SITE_KEY = window.RECAPTCHA_SITE_KEY || '';

  /**
   * Gera um token para a action informada. Retorna uma Promise que
   * resolve com o token (string) ou null se o reCAPTCHA não estiver
   * configurado/carregado (degrada graciosamente — o backend já trata
   * token ausente sem travar o usuário quando o captcha nem era exigido).
   */
  function getToken(action) {
    action = action || 'submit';

    return new Promise(function (resolve) {
      if (!SITE_KEY || typeof grecaptcha === 'undefined') {
        resolve(null);
        return;
      }

      grecaptcha.ready(function () {
        grecaptcha.execute(SITE_KEY, { action: action })
          .then(function (token) { resolve(token); })
          .catch(function () { resolve(null); });
      });
    });
  }

  return { getToken: getToken };
})();