/**
 * checkout-summary.js — v2
 *
 * Carregado apenas em /checkout/summary.
 * Cartão/Pix/Boleto, máscaras, Luhn, preview, parcelas, finalizar.
 */
;(function ($, window) {
  'use strict';

  const BRANDS = [
    { name: 'amex',       label: 'AMEX',   cvv: 4, pattern: /^3[47]/ },
    { name: 'diners',     label: 'DINERS', cvv: 3, pattern: /^(30[0-5]|36|38)/ },
    { name: 'discover',   label: 'DISC',   cvv: 3, pattern: /^(6011|65)/ },
    { name: 'elo',        label: 'ELO',    cvv: 3, pattern: /^(4011|4312|4389|4514|4573|5041|5066|5067|5090|6277|6362|6363|6504|6505|6516|6550)/ },
    { name: 'hipercard',  label: 'HIPER',  cvv: 3, pattern: /^(606282|3841)/ },
    { name: 'mastercard', label: 'MASTER', cvv: 3, pattern: /^(5[1-5]|2[2-7])/ },
    { name: 'visa',       label: 'VISA',   cvv: 3, pattern: /^4/ },
  ];

  function detectBrand(d) { return BRANDS.find(b => b.pattern.test(d)) || null; }

  function luhnValid(digits) {
    if (!digits || digits.length < 13) return false;
    let sum = 0, dbl = false;
    for (let i = digits.length - 1; i >= 0; i--) {
      let d = parseInt(digits[i], 10);
      if (dbl) { d *= 2; if (d > 9) d -= 9; }
      sum += d; dbl = !dbl;
    }
    return sum % 10 === 0;
  }

  function formatBRL(v) { return 'R$ ' + Number(v).toFixed(2).replace('.', ','); }

  function maskCardNumber($input) {
    const brand  = detectBrand($input.val().replace(/\D/g, '').slice(0, 6));
    let digits   = $input.val().replace(/\D/g, '');
    const isAmex = brand && brand.name === 'amex';
    digits = isAmex ? digits.slice(0, 15) : digits.slice(0, 16);
    const formatted = isAmex
      ? digits.replace(/^(\d{0,4})(\d{0,6})(\d{0,5}).*/, (_, a, b, c) => [a, b, c].filter(Boolean).join(' '))
      : digits.replace(/(.{4})(?=.)/g, '$1 ').trim();
    $input.val(formatted);
    return digits;
  }

  function maskValidade($input) {
    let v = $input.val().replace(/\D/g, '').slice(0, 4);
    if (v.length > 2) v = v.slice(0, 2) + '/' + v.slice(2);
    $input.val(v);
    return v;
  }

  function updatePreview() {
    const digits = $('#numero_cartao').val().replace(/\D/g, '');
    const brand  = detectBrand(digits);
    const padded = digits.padEnd(16, '•').slice(0, 16);
    const groups = padded.match(/.{1,4}/g) || [];

    $('#card-prev-number').text(groups.join(' '));
    $('#card-prev-holder').text($('#nome_cartao').val().trim().toUpperCase() || 'NOME COMPLETO');
    $('#card-prev-expiry').text($('#validade_cartao').val() || 'MM/AA');

    if (brand) {
      $('#card-prev-brand').html(`<span class="card-prev-brand-label card-prev-brand--${brand.name}">${brand.label}</span>`);
      $('#card-brand-detected').text(brand.label).show();
    } else {
      $('#card-prev-brand').html('<span class="card-prev-brand-placeholder">CARTÃO</span>');
      $('#card-brand-detected').hide().empty();
    }
  }

  function popularParcelas() {
    const cfg     = window.CHECKOUT_CONFIG || {};
    const total   = parseFloat(cfg.total || 0);
    const max     = cfg.maxParcelas || 12;
    const minVal  = cfg.minParcela  || 30;
    const juros   = cfg.juros       || {};
    const $select = $('#parcelas');
    if (!total || !$select.length) return;
    const realMax = Math.min(max, Math.max(1, Math.floor(total / minVal)));
    $select.empty();
    for (let n = 1; n <= realMax; n++) {
      const taxa  = juros[n] || 0;
      const totP  = total * (1 + taxa / 100);
      const label = n === 1
        ? `À vista · ${formatBRL(total)}`
        : `${n}x de ${formatBRL(totP / n)} ${taxa === 0 ? 'sem juros' : `(${taxa}% a.m.)`}`;
      $select.append(`<option value="${n}" data-juros="${taxa}">${label}</option>`);
    }
  }

  // Método de pagamento
  $(document).on('click', '.summary-method-pill', function () {
    const method = $(this).find('input[type="radio"]').val();
    $('.summary-method-pill').removeClass('is-active');
    $(this).addClass('is-active').find('input[type="radio"]').prop('checked', true);
    $('#card-panel').attr('hidden',   method !== 'cartao' ? true : null);
    $('#pix-panel').attr('hidden',    method !== 'pix'    ? true : null);
    $('#boleto-panel').attr('hidden', method !== 'boleto' ? true : null);
  });

  // Inputs do cartão
  $(document).on('input', '#numero_cartao', function () {
    const digits = maskCardNumber($(this));
    const brand  = detectBrand(digits);
    $(this).toggleClass('is-valid', luhnValid(digits)).removeClass('is-error');
    if (brand) $('#cvv_cartao').attr('maxlength', brand.cvv);
    updatePreview();
  });

  $(document).on('input', '#nome_cartao', function () {
    $(this).val($(this).val().toUpperCase());
    updatePreview();
  });

  $(document).on('input', '#validade_cartao', function () {
    maskValidade($(this));
    updatePreview();
  });

  $(document).on('input', '#cvv_cartao', function () {
    const max = parseInt($(this).attr('maxlength')) || 4;
    $(this).val($(this).val().replace(/\D/g, '').slice(0, max));
  });

  $(document).on('change', '#save-card-toggle input[type="checkbox"]', function () {
    $(this).closest('.save-card-toggle').toggleClass('is-checked', $(this).is(':checked'));
  });

  /**
   * Desafio 3-D Secure.
   *
   * O emissor autentica o comprador dentro de um iframe. Quando termina, o
   * Mercado Pago devolve para o nosso callback, que avisa esta janela por
   * postMessage. So entao perguntamos o desfecho AO SERVIDOR — o postMessage
   * diz apenas "terminou", nunca "foi aprovado": qualquer pagina poderia
   * mandar essa mensagem.
   */
  function abrirDesafio3ds(desafio, $btn, $err) {
    var $fundo = $(
      '<div class="tds-fundo" role="dialog" aria-modal="true" aria-label="Autenticação do banco">' +
        '<div class="tds-caixa">' +
          '<div class="tds-topo">' +
            '<strong>Confirmação do seu banco</strong>' +
            '<small>Conclua a verificação para autorizar a compra.</small>' +
          '</div>' +
          '<iframe class="tds-frame" title="Autenticação do banco"></iframe>' +
          '<div class="tds-rodape"><span class="tds-espera">Aguardando o banco…</span></div>' +
        '</div>' +
      '</div>'
    );

    $fundo.find('.tds-frame').attr('src', desafio.url);
    $('body').append($fundo).addClass('tds-aberto');

    var encerrado = false;

    function conferir() {
      if (encerrado) return;
      encerrado = true;

      window.removeEventListener('message', ouvir);
      clearTimeout(limite);
      $fundo.find('.tds-espera').text('Confirmando o pagamento…');

      $.getJSON(desafio.status)
        .done(function (r) {
          $fundo.remove();
          $('body').removeClass('tds-aberto');
          // Aprovado ou nao, o pedido existe e a tela dele conta o que houve.
          window.location.href = (r && r.redirect) || desafio.status;
        })
        .fail(function () {
          $fundo.remove();
          $('body').removeClass('tds-aberto');
          CK.btnLoading($btn, false);
          CK.formAlertSet($err, 'Não foi possível confirmar a autenticação. ' +
                                'Acompanhe o pedido em Minha conta.');
        });
    }

    function ouvir(ev) {
      if (ev && ev.data && ev.data.tipo === '3ds-concluido') conferir();
    }

    window.addEventListener('message', ouvir);

    // O emissor da 40 minutos, mas ninguem fica olhando um iframe tanto
    // tempo. Depois de 10 a gente pergunta assim mesmo: o desfecho pode ter
    // saido e a mensagem ter se perdido.
    var limite = setTimeout(conferir, 600000);
  }

  // Finalizar
  $('#btn-finalize').on('click', function () {
    const $btn   = $(this);
    const $err   = $('#finalize-error');
    const method = $('input[name="forma_pagamento"]:checked').val() || 'pix';
    CK.formAlertClear($err);

    if (method === 'cartao') {
      const numeroCartao = $('#numero_cartao').val() || '';
      const digits = numeroCartao.replace(/\D/g, '');
      // const digits   = $('#numero_cartao').val().replace(/\D/g, '');
      const nome_cartao = $('#numero_cartao').val() || '';
      const nome     = nome_cartao.trim();

      const validade_cartao = $('#validade_cartao').val() || '';
      const validade = validade_cartao.trim();

      const cvv_cartao = $('#cvv_cartao').val() || '';
      const cvv      = cvv_cartao.replace(/\D/g, '');
      const erros    = [];
      if (!luhnValid(digits))                  erros.push('Número do cartão inválido.');
      if (nome.length < 4)                      erros.push('Informe o nome impresso no cartão.');
      if (!/^\d{2}\/\d{2}$/.test(validade)) erros.push('Validade inválida (MM/AA).');
      else {
        const [mm, yy] = validade.split('/').map(Number);
        if (mm < 1 || mm > 12) erros.push('Mês de validade inválido.');
        else if (new Date(2000 + yy, mm, 0) < new Date()) erros.push('Cartão vencido.');
      }
      if (cvv.length < 3) erros.push('CVV inválido.');
      if (erros.length) {
        CK.formAlertSet($err, erros[0]);
        $('html,body').animate({ scrollTop: $('#card-panel').offset().top - 80 }, 250);
        return;
      }
    }

    CK.btnLoading($btn);

    function enviar(tokenSalvo) {
      CK.post('/checkout/finalize', {
        forma_pagamento: method,
        parcelas:        $('#parcelas').val() || 1,
        salvar_cartao:   $('input[name="salvar_cartao"]').is(':checked') ? 1 : 0,
        // Token gerado agora a partir do cartao salvo + CVV. Vazio quando a
        // forma de pagamento nao precisa (pix, boleto, cartao novo).
        gateway_token:   tokenSalvo || ''
      })
      .done(function (res) {
        // O emissor pediu autenticacao: nao da para mandar o cliente para a
        // tela de sucesso ainda — o pagamento nao esta aprovado nem recusado.
        if (res.ok && res.desafio_3ds && res.desafio_3ds.url) {
          abrirDesafio3ds(res.desafio_3ds, $btn, $err);
          return;
        }
        if (res.ok && res.redirect) { window.location.href = res.redirect; return; }
        CK.btnLoading($btn, false);
        CK.formAlertSet($err, res.msg || 'Erro ao processar pagamento.');
      })
      .fail(function () {
        CK.btnLoading($btn, false);
        CK.formAlertSet($err, 'Erro de conexão. Tente novamente.');
      });
    }

    // CARTAO SALVO DO MERCADO PAGO: o token nasce AGORA, do card_id mais o
    // CVV que o cliente acabou de digitar. Nao da para reaproveitar o token
    // da vez passada — ele e de uso unico.
    var cardRef = window.__mpCartaoSalvo;
    var SDK     = window.SportMotoMercadoPagoCheckout;

    if (cardRef && SDK) {
      var resolvido = false;

      function encerrar(fn) {
        if (resolvido) return;          // token e erro podem chegar juntos
        resolvido = true;
        clearTimeout(travou);
        $(document).off('mp:token-salvo mp:token-erro', quandoToken);
        fn();
      }

      function quandoToken(e, dado) {
        if (e.type === 'mp:token-salvo' && dado && dado.tokenId) {
          encerrar(function () { enviar(dado.tokenId); });
        } else {
          encerrar(function () {
            CK.btnLoading($btn, false);
            CK.formAlertSet($err, (dado && dado.msg) || 'Não foi possível validar o cartão.');
          });
        }
      }

      // Rede de seguranca: se o SDK nao responder nem com token nem com erro,
      // o botao nao pode ficar girando para sempre.
      var travou = setTimeout(function () {
        encerrar(function () {
          CK.btnLoading($btn, false);
          CK.formAlertSet($err, 'O cartão demorou a responder. Tente novamente.');
        });
      }, 20000);

      $(document).on('mp:token-salvo mp:token-erro', quandoToken);
      SDK.tokenizarSalvo(cardRef);
      return;
    }

    enviar(null);
  });

  $(function () { popularParcelas(); });

}(jQuery, window));