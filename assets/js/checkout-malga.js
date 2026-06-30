/* ════════════════════════════════════════════════════════════════════
 * checkout-malga.js
 * ════════════════════════════════════════════════════════════════════ */

(function(window, $) {
  'use strict';

  var FIELD_MAP = {
    cardNumber:         { container: 'card-number',          placeholder: '0000 0000 0000 0000' },
    cardHolderName:     { container: 'card-holder-name',     placeholder: 'NOME COMO NO CARTÃO' },
    cardExpirationDate: { container: 'card-expiration-date', placeholder: 'MM/AA' },
    cardCvv:            { container: 'card-cvv',             placeholder: '000' }
  };

  // FIX: mapa inverso container → chave do fieldsValid.
  // O SDK emite validity com field = container ID ('card-number'),
  // mas fieldsValid usa a chave camelCase ('cardNumber').
  var CONTAINER_TO_KEY = {};
  Object.keys(FIELD_MAP).forEach(function(key) {
    CONTAINER_TO_KEY[FIELD_MAP[key].container] = key;
  });

  var STYLES_INPUT = {
    color:         '#1e293b',
    'font-size':   '14px',
    'font-family': '-apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
    'padding':     '0 19px'
  };

  var instance    = null;
  var ready       = false;
  var submitting  = false;
  var fieldsValid = {
    cardNumber: false,
    cardHolderName: false,
    cardExpirationDate: false,
    cardCvv: false
  };
  var detectedBrand = null;
  var callbacks     = {};
  var SDK_READY_TIMEOUT_MS = 10000;
  var Toast_ID_Malga;

  var SportMotoMalgaCheckout = {

    init: function(opts) {
      if (instance) { console.warn('[Malga] já inicializado'); return; }
      if (!opts || !opts.clientId || !opts.apiKey) {
        this._fail('Credenciais da Malga não configuradas no servidor.');
        return;
      }

      callbacks = {
        onReady:  opts.onReady  || function() {},
        onSubmit: opts.onSubmit || function() {},
        onError:  opts.onError  || function() {}
      };

      this._waitForSdk(function() {
        SportMotoMalgaCheckout._boot(opts);
      });

      $('#form-card-add').on('submit', function(e) {
        e.preventDefault();
        SportMotoMalgaCheckout._handleSubmit();
      });
    },

    showError: function(msg) {
      if (!Toast_ID_Malga) {
        Toast.configure({ position: 'bottom-center', duration: 5000, maxVisible: 1 });
        Toast.error(msg);
      } else {
        Toast.update(Toast_ID_Malga, { type: 'error', message: msg, duration: 4000 });
      }
      var $err = $('#card-add-error');
      window.scrollTo({ top: $err.offset().top - 100, behavior: 'smooth' });
      submitting = false;
      $('#btn-save-card').prop('disabled', false);
      $('#btn-save-card .btn-text').show();
      $('#btn-save-card .btn-loading').hide();
    },

    reset: function() {
      instance = null; ready = false; submitting = false; detectedBrand = null;
      Object.keys(fieldsValid).forEach(function(k) { fieldsValid[k] = false; });
    },

    _waitForSdk: function(callback) {
      if (window.__MalgaTokenization) { callback(); return; }
      var done = false;
      var onReady = function() {
        if (done) return;
        done = true;
        window.removeEventListener('malga-sdk-ready', onReady);
        callback();
      };
      window.addEventListener('malga-sdk-ready', onReady);
      setTimeout(function() {
        if (done) return;
        done = true;
        SportMotoMalgaCheckout._fail('Tempo esgotado ao carregar o módulo de pagamento. Atualize a página.');
      }, SDK_READY_TIMEOUT_MS);
    },

    _boot: function(opts) {
      // Garante que os containers existem no DOM antes de instanciar o SDK
      var containersOk = Object.keys(FIELD_MAP).every(function(key) {
        return document.getElementById(FIELD_MAP[key].container) !== null;
      });
      if (!containersOk) {
        setTimeout(function() { SportMotoMalgaCheckout._boot(opts); }, 100);
        return;
      }

      try {
        instance = new window.__MalgaTokenization({
          apiKey:   opts.apiKey,
          clientId: opts.clientId,
          options: {
            sandbox: !!opts.sandbox,
            config: {
              fields:         FIELD_MAP,
              styles:         { input: STYLES_INPUT },
              preventAutofill: true
            }
          }
        });

        instance.on('cardTypeChanged', function(evt) {
          SportMotoMalgaCheckout._onBrandChange(evt);
        });
        instance.on('validity', function(evt) {
          SportMotoMalgaCheckout._onFieldValidity(evt);
        });

        SportMotoMalgaCheckout._pollIframesReady();

      } catch (e) {
        console.error('[Malga] boot error:', e);
        SportMotoMalgaCheckout._fail('Erro ao inicializar o pagamento. Atualize a página.');
      }
    },

    _pollIframesReady: function() {
      var maxAttempts = 100; // 10s (100ms cada)
      var attempts    = 0;

      var checker = setInterval(function() {
        attempts++;
        var allMounted = Object.keys(FIELD_MAP).every(function(key) {
          return document.querySelector('#' + FIELD_MAP[key].container + ' iframe') !== null;
        });

        if (allMounted) {
          clearInterval(checker);
          ready = true;
          Object.keys(FIELD_MAP).forEach(function(key) {
            $('#' + FIELD_MAP[key].container).addClass('is-ready');
          });
          callbacks.onReady();
          return;
        }

        if (attempts >= maxAttempts) {
          clearInterval(checker);
          // Libera mesmo assim — se o tokenize() falhar, o erro aparece pra o usuário
          ready = true;
          callbacks.onReady();
          console.warn('[Malga] timeout aguardando iframes. Campos podem não estar prontos.');
        }
      }, 100);
    },

    _onBrandChange: function(evt) {
      // O SDK emite { field, parentNode, card } — brand está em evt.card.type.
      // Fallbacks defensivos para variações entre versões do hosted-field.
      var brand = null;
      if (evt) {
        if (evt.card && evt.card.type)       brand = evt.card.type;
        else if (evt.card && evt.card.brand)  brand = evt.card.brand;
        else if (evt.brand)                   brand = evt.brand;
      }
      detectedBrand = brand ? brand.toLowerCase() : null;

      if (detectedBrand) {
        $('#card-brand-detected').text(detectedBrand.toUpperCase()).show();
        $('#card-prev-brand').text(detectedBrand.toUpperCase());
      } else {
        $('#card-brand-detected').hide();
        $('#card-prev-brand').text('CARTÃO');
      }
    },

    _onFieldValidity: function(evt) {
      if (!evt || !evt.field) return;

      // FIX: o SDK emite field = container ID ('card-number').
      // Converte para a chave do fieldsValid ('cardNumber') via mapa reverso.
      var fieldKey = CONTAINER_TO_KEY[evt.field] || evt.field;

      if (Object.prototype.hasOwnProperty.call(fieldsValid, fieldKey)) {
        // O SDK emite valid/isValid dependendo da versão — aceita os dois
        var isValid = evt.isValid !== undefined ? !!evt.isValid : !!evt.valid;
        fieldsValid[fieldKey] = isValid;
      }

      // Visual feedback: usa o container ID pra achar o elemento
      var $c = $('#' + evt.field);
      if ($c.length) {
        var isInvalid = evt.isValid === false || evt.valid === false;
        $c.toggleClass('is-invalid', isInvalid);
      }

      // Mensagem de erro no campo
      var errId = SportMotoMalgaCheckout._errorIdFor(fieldKey);
      if (errId) {
        var $err = $('#' + errId);
        var errors = evt.errors || (evt.error ? [evt.error] : []);
        if ((evt.isValid === false || evt.valid === false) && errors.length) {
          $err.text(SportMotoMalgaCheckout._humanizeError(errors[0]));
        } else {
          $err.text('');
        }
      }
    },

    _errorIdFor: function(fieldKey) {
      // Aceita tanto a chave camelCase quanto o container ID
      var key = CONTAINER_TO_KEY[fieldKey] || fieldKey;
      return ({
        cardNumber:         'err-numero',
        cardHolderName:     'err-nome',
        cardExpirationDate: 'err-validade',
        cardCvv:            'err-cvv'
      })[key] || null;
    },

    _humanizeError: function(err) {
      var map = { 'invalid': 'Inválido', 'required': 'Obrigatório', 'too_short': 'Muito curto' };
      var code = (typeof err === 'string') ? err : (err && (err.code || err.type || ''));
      return map[code] || 'Verifique este campo';
    },

    _handleSubmit: function() {
      if (submitting) return;
      if (!ready) {
        SportMotoMalgaCheckout.showError('Aguarde o carregamento dos campos de pagamento.');
        return;
      }

      var allValid = Object.keys(fieldsValid).every(function(k) { return fieldsValid[k]; });
      if (!allValid) {
        SportMotoMalgaCheckout.showError('Preencha todos os campos do cartão corretamente.');
        return;
      }

      submitting = true;
      $('#btn-save-card').prop('disabled', true);
      $('#btn-save-card .btn-text').hide();
      $('#btn-save-card .btn-loading').show();
      $('#card-add-error').hide();

      try {
        instance.tokenize().then(function(result) {
          if (!result || !result.tokenId) {
            SportMotoMalgaCheckout.showError('Tokenização falhou. Tente novamente.');
            return;
          }

          // O SDK de hosted fields retorna apenas { tokenId }.
          // last4 e brand vêm do sessionStorage["malga-card"] que o SDK
          // mantém com os valores digitados nos iframes.
          var cardSession = {};
          try {
            cardSession = JSON.parse(sessionStorage.getItem('malga-card') || '{}');
          } catch (e) { /* sessionStorage bloqueado */ }

          var last4 = (cardSession.cardNumber || '').replace(/\D/g, '').slice(-4) || null;
          var brand = detectedBrand || cardSession.cardBrand || cardSession.brand || null;

          callbacks.onSubmit({ tokenId: result.tokenId, brand: brand, last4: last4 });

        }).catch(function(err) {
          console.error('[Malga] tokenize error:', err);
          var msg = (err && err.message) ? err.message : 'Não foi possível validar o cartão.';
          SportMotoMalgaCheckout.showError(msg);
        });
      } catch (e) {
        console.error('[Malga] tokenize threw:', e);
        SportMotoMalgaCheckout.showError('Erro inesperado. Atualize a página e tente de novo.');
      }
    },

    _fail: function(msg) {
      var $err = $('#card-add-error');
      if ($err.length) { $err.text(msg).show(); } else { alert(msg); }
      $('#btn-save-card').prop('disabled', true);
    }

  };

  window.SportMotoMalgaCheckout = SportMotoMalgaCheckout;

})(window, window.jQuery);