/* ════════════════════════════════════════════════════════════════════
 * checkout-cielo-sop.js
 *
 * Tokenização de cartão no navegador com o Silent Order Post da Cielo.
 *
 * O QUE ELE FAZ:
 *   O script oficial lê os inputs da página (classes bp-sop-*) e posta o
 *   cartão DIRETO na Cielo — "os dados do cartão do comprador não trafegam
 *   pelo ambiente da loja". Com enableTokenize, o retorno é um CardToken
 *   reutilizável, já guardado no Cartão Protegido.
 *
 * O QUE ELE NÃO FAZ:
 *   Não pede credencial ao navegador. O AccessToken de 20 minutos nasce no
 *   servidor (CieloAdapter::sopAcesso) e chega pronto via init().
 *
 * CONTRATO (promise):
 *   SportMotoCieloSop.init({ accessToken, environment, provider })
 *   SportMotoCieloSop.tokenizar() → { cardToken, brand, last4, bin, bruto }
 *
 * Os inputs são os MESMOS que o Mercado Pago lê (checkout-mercadopago.js,
 * tokenizarCampos): o cliente digita uma vez e o cartão vai para os dois
 * cofres. É o desenho SAQ A-EP — número na página, nunca no servidor.
 * ════════════════════════════════════════════════════════════════════ */

(function (window, $) {
  'use strict';

  var SCRIPT_URL = 'https://www.pagador.com.br/post/scripts/silentorderpost-1.0.min.js';
  var TIMEOUT_MS = 10000;

  var cfg = null;

  function carregar(feito) {
    if (typeof window.bpSop_silentOrderPost === 'function') { feito(true); return; }

    if (!document.querySelector('script[src^="' + SCRIPT_URL + '"]')) {
      var s = document.createElement('script');
      s.src = SCRIPT_URL;
      s.async = true;
      document.head.appendChild(s);
    }

    var limite = Date.now() + TIMEOUT_MS;
    var t = setInterval(function () {
      if (typeof window.bpSop_silentOrderPost === 'function') { clearInterval(t); feito(true); return; }
      if (Date.now() > limite) { clearInterval(t); feito(false); }
    }, 120);
  }

  var SportMotoCieloSop = {

    init: function (opts) {
      cfg = opts || {};
      return cfg.accessToken ? true : false;
    },

    pronto: function () { return !!(cfg && cfg.accessToken); },

    /**
     * Tokeniza os inputs bp-sop-* da página.
     *
     * O script oficial não é uma função com retorno: ele registra callbacks
     * e dispara ao ser chamado. Aqui isso vira uma promise para o boot da
     * tela conseguir esperar MP e Cielo em paralelo.
     */
    tokenizar: function () {
      return new Promise(function (resolve, reject) {
        if (!cfg || !cfg.accessToken) {
          reject(new Error('Cielo indisponível nesta página.'));
          return;
        }

        carregar(function (ok) {
          if (!ok) { reject(new Error('Não foi possível carregar o script da Cielo.')); return; }

          var encerrado = false;
          var fim = function (fn) { if (!encerrado) { encerrado = true; fn(); } };

          try {
            window.bpSop_silentOrderPost({
              accessToken:      cfg.accessToken,
              environment:      cfg.environment || 'production',
              // 'braspag' posta em *.pagador.com.br — o unico host que
              // respondeu no sandbox (o da Cielo nem resolve). O servidor
              // decide via config_extra.sop_provider; aqui so obedece.
              provider:         cfg.provider || 'braspag',
              language:         'PT',
              cvvrequired:      true,
              mod10required:    true,
              enableBinQuery:   false,
              enableVerifyCard: false,
              // O que faz o retorno ser um CardToken de cofre, não um
              // PaymentToken de uso único.
              enableTokenize:   true,

              onSuccess: function (r) {
                fim(function () {
                  r = r || {};
                  // A doc mostra CardToken com tokenize ligado, mas o
                  // exemplo geral traz PaymentToken. Lê os dois: errar o
                  // nome devolve vazio sem erro nenhum.
                  var token = r.CardToken || r.PaymentToken || '';
                  if (!token) { reject(new Error('Cielo não devolveu o token do cartão.')); return; }
                  resolve({
                    cardToken: token,
                    brand:     (r.Brand || '').toLowerCase() || null,
                    last4:     r.CardLast4Digits || null,
                    bin:       r.CardBin || null,
                    bruto:     r
                  });
                });
              },
              onError: function (e) {
                fim(function () {
                  console.error('[Cielo SOP] erro:', e);
                  reject(new Error('A Cielo recusou os dados do cartão.'));
                });
              },
              onInvalid: function (v) {
                fim(function () {
                  console.warn('[Cielo SOP] inválido:', v);
                  reject(new Error('Confira os dados do cartão.'));
                });
              }
            });
          } catch (e) {
            fim(function () { reject(e); });
          }

          // O script não tem timeout próprio; sem isto a tela ficaria
          // girando para sempre se a Cielo não responder.
          setTimeout(function () {
            fim(function () { reject(new Error('A Cielo não respondeu. Tente novamente.')); });
          }, TIMEOUT_MS * 2);
        });
      });
    }
  };

  window.SportMotoCieloSop = SportMotoCieloSop;

})(window, window.jQuery);
