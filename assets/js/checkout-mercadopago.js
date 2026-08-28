/* ════════════════════════════════════════════════════════════════════
 * checkout-mercadopago.js
 *
 * Tokenização de cartão no navegador, com Secure Fields do Mercado Pago.
 *
 * POR QUE ISTO IMPORTA:
 *   Número, validade e CVV vivem dentro de iframes do próprio Mercado Pago.
 *   O PAN nunca entra no nosso DOM, nunca vai num POST nosso e nunca aparece
 *   num log nosso. O servidor recebe só o token e os quatro últimos dígitos —
 *   que é exatamente a premissa do projeto, e o que mantém a loja no escopo
 *   PCI mais leve.
 *
 *   O adapter tem um fallback que tokeniza no servidor quando chega PAN. Ele
 *   existe para o motor não parar; este arquivo é o que torna esse fallback
 *   desnecessário.
 *
 * MESMO CONTRATO DO checkout-malga.js, de propósito: a view chama init() com
 * as mesmas callbacks e recebe o mesmo objeto em onSubmit. Trocar de
 * adquirente no checkout vira trocar qual script carrega.
 *
 *   SportMotoMercadoPagoCheckout.init({
 *     publicKey, onReady, onSubmit, onError
 *   });
 *   onSubmit({ tokenId, brand, last4, bin });
 *
 * O NOME DO TITULAR NÃO É SECURE FIELD:
 *   O Mercado Pago não oferece campo hospedado para ele, e nem precisa — não
 *   é dado de cartão sujeito a PCI. A view reserva a div; aqui dentro dela
 *   nasce um <input> comum.
 * ════════════════════════════════════════════════════════════════════ */

(function (window, $) {
  'use strict';

  var SDK_URL = 'https://sdk.mercadopago.com/js/v2';
  var SDK_TIMEOUT_MS = 10000;

  /* Só os três que carregam dado de cartão são hospedados. */
  var CAMPOS = {
    cardNumber:     { container: 'card-number',           placeholder: '0000 0000 0000 0000' },
    expirationDate: { container: 'card-expiration-date',  placeholder: 'MM/AA' },
    securityCode:   { container: 'card-cvv',              placeholder: '000' }
  };

  var ESTILO = {
    color: '#1e293b',
    fontSize: '14px',
    fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
    placeholderColor: '#94a3b8'
  };

  var mp = null;
  var campos = {};
  var pronto = false;
  var enviando = false;
  var bandeira = null;
  var binAtual = null;
  var cb = {};

  var valido = { cardNumber: false, expirationDate: false, securityCode: false };

  var SportMotoMercadoPagoCheckout = {

    init: function (opts) {
      if (mp) { console.warn('[MP] já inicializado'); return; }

      if (!opts || !opts.publicKey) {
        this._falhar('Chave pública do Mercado Pago não configurada no servidor.');
        return;
      }

      cb = {
        onReady:  opts.onReady  || function () {},
        onSubmit: opts.onSubmit || function () {},
        onError:  opts.onError  || function () {}
      };

      var self = this;
      this._carregarSdk(function (ok) {
        if (!ok) {
          self._falhar('Não foi possível carregar o Mercado Pago. Verifique sua conexão.');
          return;
        }
        self._montar(opts);
      });
    },

    /** Pronto quando os três campos hospedados estão válidos. */
    estaValido: function () {
      return pronto && valido.cardNumber && valido.expirationDate && valido.securityCode;
    },

    bandeiraDetectada: function () { return bandeira; },

    /**
     * Gera o token. É aqui que o cartão vira uma string opaca.
     *
     * O CPF vai junto porque o Mercado Pago exige identificação do titular
     * para emitir o token — sem ela a chamada falha com erro pouco claro.
     */
    tokenizar: function (dados) {
      if (enviando) return;

      if (!this.estaValido()) {
        this._falhar('Confira os dados do cartão.');
        return;
      }

      var nome = String((dados && dados.titular) || $('#card-holder-input').val() || '').trim();
      if (nome.length < 3) {
        this._falhar('Informe o nome como está impresso no cartão.');
        return;
      }

      var doc = String((dados && dados.documento) || '').replace(/\D/g, '');
      if (doc.length !== 11 && doc.length !== 14) {
        this._falhar('Informe um CPF ou CNPJ válido para o titular.');
        return;
      }

      enviando = true;
      this.limparErro();

      var self = this;

      mp.fields.createCardToken({
        cardholderName: nome,
        identificationType: doc.length > 11 ? 'CNPJ' : 'CPF',
        identificationNumber: doc
      }).then(function (token) {
        enviando = false;

        if (!token || !token.id) {
          self._falhar('Não foi possível validar o cartão. Tente novamente.');
          return;
        }

        // Só isto sai daqui. O número inteiro fica nos iframes do MP.
        cb.onSubmit({
          tokenId: token.id,
          brand:   bandeira,
          last4:   token.last_four_digits || null,
          bin:     token.first_six_digits || binAtual || null
        });
      }).catch(function (err) {
        enviando = false;
        console.error('[MP] createCardToken:', err);
        self._falhar(self._mensagemDe(err));
      });
    },

    /**
     * Tokeniza um cartao JA SALVO, a partir do card_id guardado.
     *
     * POR QUE O CVV E PEDIDO DE NOVO:
     *   O Mercado Pago nao cobra por card_id — a Orders API so aceita
     *   `token`, e um token novo so nasce com o codigo de seguranca. Nao e
     *   atrito gratuito: e o que impede que alguem com acesso a conta do
     *   comprador use um cartao salvo sem ter o cartao na mao.
     *
     * Precisa de um campo de CVV montado (mp.fields.create('securityCode')
     * com `settings.cardId`), que e diferente do CVV de cartao novo.
     */
    tokenizarSalvo: function (cardId) {
      if (enviando) return;

      if (!cardId) {
        this._falhar('Cartão salvo sem referência. Adicione o cartão novamente.');
        return;
      }
      if (!valido.securityCode) {
        this._falhar('Informe o código de segurança do cartão.');
        return;
      }

      enviando = true;
      this.limparErro();

      var self = this;

      mp.fields.createCardToken({ cardId: cardId }).then(function (token) {
        enviando = false;
        if (!token || !token.id) {
          self._falhar('Não foi possível validar o cartão. Tente novamente.');
          return;
        }
        cb.onSubmit({
          tokenId: token.id,
          brand:   bandeira,
          last4:   token.last_four_digits || null,
          bin:     token.first_six_digits || null
        });
      }).catch(function (err) {
        enviando = false;
        console.error('[MP] createCardToken(cardId):', err);
        self._falhar(self._mensagemDe(err));
      });
    },

    /**
     * Monta so o campo de CVV, para cobranca de cartao salvo.
     * O `cardId` diz ao SDK qual cartao esse codigo acompanha.
     */
    montarCvvDeCartaoSalvo: function (cardId, container) {
      if (!mp) { this._falhar('Mercado Pago não iniciado.'); return; }

      var alvo = container || 'card-cvv-salvo';
      var $alvo = $('#' + alvo);
      if (!$alvo.length) return;

      var self = this;
      var campo = mp.fields.create('securityCode', {
        placeholder: '000',
        style: ESTILO,
        settings: { cardId: cardId }
      });

      campo.mount(alvo);
      campo.on('validityChange', function (evt) {
        valido.securityCode = !(evt && evt.errorMessages && evt.errorMessages.length);
        $alvo.toggleClass('is-invalid', !valido.securityCode);
        self._sincronizar();
      });

      // O cartao salvo nao tem numero nem validade para validar aqui.
      valido.cardNumber = true;
      valido.expirationDate = true;
      campos.securityCode = campo;
    },

    limparErro: function () {
      $('#card-add-error').hide().text('');
    },

    showError: function (msg) { this._falhar(msg); },

    // ───────────────────────────────────────────────────────────────

    _montar: function (opts) {
      try {
        mp = new window.MercadoPago(opts.publicKey, { locale: 'pt-BR' });
      } catch (e) {
        this._falhar('Falha ao iniciar o Mercado Pago.');
        return;
      }

      var self = this;

      Object.keys(CAMPOS).forEach(function (chave) {
        var cfg = CAMPOS[chave];
        var $alvo = $('#' + cfg.container);
        if (!$alvo.length) return;

        var campo = mp.fields.create(chave, {
          placeholder: $alvo.data('placeholder') || cfg.placeholder,
          style: ESTILO
        });

        campo.mount(cfg.container);

        campo.on('validityChange', function (evt) {
          valido[chave] = !(evt && evt.errorMessages && evt.errorMessages.length);
          $alvo.toggleClass('is-invalid', !valido[chave]);
          self._sincronizar();
        });

        campo.on('focus', function () { $alvo.addClass('is-focused'); });
        campo.on('blur',  function () { $alvo.removeClass('is-focused'); });

        // O BIN é o que revela a bandeira — e o que alimenta a prévia do
        // cartão na tela sem que a gente veja o número.
        if (chave === 'cardNumber') {
          campo.on('binChange', function (evt) {
            binAtual = (evt && evt.bin) || null;
            self._descobrirBandeira(binAtual);
          });
        }

        campos[chave] = campo;
      });

      this._montarTitular();

      pronto = true;
      cb.onReady();
    },

    /**
     * O nome do titular é input comum, dentro da div que a view reservou.
     * Não é dado de cartão: não precisa de iframe e o MP não oferece um.
     */
    _montarTitular: function () {
      var $div = $('#card-holder-name');
      if (!$div.length || $div.find('input').length) return;

      var $input = $('<input>', {
        type: 'text',
        id: 'card-holder-input',
        autocomplete: 'cc-name',
        maxlength: 60,
        placeholder: $div.data('placeholder') || 'Como está no cartão',
        css: { border: 0, outline: 0, width: '100%', height: '100%',
               background: 'transparent', font: 'inherit', color: 'inherit' }
      });

      $input.on('input', function () {
        var v = $(this).val().toUpperCase();
        $(this).val(v);
        $('#card-prev-holder').text(v || 'NOME COMPLETO');
      });

      $div.empty().append($input);
    },

    _descobrirBandeira: function (bin) {
      if (!bin || bin.length < 6) {
        bandeira = null;
        this._pintarBandeira();
        return;
      }

      var self = this;
      mp.getPaymentMethods({ bin: bin }).then(function (r) {
        var achado = r && r.results && r.results[0];
        bandeira = achado ? achado.id : null;
        // secure_thumbnail, nao thumbnail: o segundo vem em http:// e numa
        // pagina https o navegador bloqueia como conteudo misto.
        self._pintarBandeira(achado ? (achado.secure_thumbnail || achado.thumbnail) : null);
      }).catch(function () {
        bandeira = null;
        self._pintarBandeira();
      });
    },

    _pintarBandeira: function (thumb) {
      $('#card-brand-value').val(bandeira || '');
      var $el = $('#card-brand-detected');
      if (!$el.length) return;
      $el.html(thumb ? $('<img>', { src: thumb, alt: bandeira, height: 20 }) : '')
         .attr('data-brand', bandeira || '');
    },

    _sincronizar: function () {
      $(document).trigger('mp:validade', [this.estaValido()]);
    },

    /**
     * Carrega o SDK. Se a tag já existe na página, só espera o global
     * aparecer — recarregar derruba os iframes já montados.
     */
    _carregarSdk: function (feito) {
      if (window.MercadoPago) { feito(true); return; }

      var jaTem = document.querySelector('script[src^="' + SDK_URL + '"]');
      if (!jaTem) {
        var s = document.createElement('script');
        s.src = SDK_URL;
        s.async = true;
        document.head.appendChild(s);
      }

      var limite = Date.now() + SDK_TIMEOUT_MS;
      var timer = setInterval(function () {
        if (window.MercadoPago) { clearInterval(timer); feito(true); return; }
        if (Date.now() > limite) { clearInterval(timer); feito(false); }
      }, 120);
    },

    /** Mensagem do SDK → texto que o comprador entende. */
    _mensagemDe: function (err) {
      var causa = err && (err.cause || err);
      var codigo = '';

      if (Array.isArray(causa) && causa.length) {
        codigo = String(causa[0].code || causa[0].description || '');
      } else if (causa && causa.code) {
        codigo = String(causa.code);
      }

      if (/card_number/i.test(codigo))     return 'Número do cartão inválido.';
      if (/expiration/i.test(codigo))      return 'Validade inválida.';
      if (/security_code|cvv/i.test(codigo)) return 'Código de segurança inválido.';
      if (/cardholder|identification/i.test(codigo)) return 'Confira o nome e o CPF do titular.';

      return 'Não foi possível validar o cartão. Confira os dados.';
    },

    _falhar: function (msg) {
      var $e = $('#card-add-error');
      if ($e.length) $e.text(msg).show();
      cb.onError && cb.onError(msg);
    }
  };

  window.SportMotoMercadoPagoCheckout = SportMotoMercadoPagoCheckout;

})(window, window.jQuery);
