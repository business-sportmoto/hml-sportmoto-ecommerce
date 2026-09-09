/* ════════════════════════════════════════════════════════════════════
 * checkout-safrapay.js
 *
 * Tokenização de cartão no navegador — Checkout Transparente da Safra Pay.
 *
 * O QUE ELE FAZ:
 *   Posta o cartão DIRETO em payment[-hml].safrapay.com.br e recebe um
 *   `cardToken` temporário. O número nunca chega ao nosso servidor: o que
 *   trafega no POST do formulário é só o token, a bandeira e o final.
 *   O backend cobra com ele em `temporaryCardToken` (SafraPayAdapter).
 *
 * CONTRATO (promise) — o MESMO do glue da Cielo e do Mercado Pago, para o
 * boot da tela não precisar saber qual adquirente está em uso:
 *   SportMotoSafraPay.init({ merchantCredential, merchantId, sandbox })
 *   SportMotoSafraPay.tokenizar(dados) → { cardToken, brand, last4, bin, bruto }
 *
 * O TOKEN É DE USO ÚNICO E VALE 15 MINUTOS. Ele não é referência de cofre:
 * serve para UMA cobrança. Ver a nota em CheckoutController::cardAdd sobre
 * por que a Safra não entra em "salvar cartão" nesta fase.
 *
 * ────────────────────────────────────────────────────────────────────
 * POR QUE NÃO CARREGAMOS A SDK OFICIAL
 *
 * A Safra publica `safrapay-transparent-v2.0.0.js` num CDN Akamai. É um
 * wrapper de ~2 KB em cima de um único fetch — e o wrapper tem defeitos que
 * custariam caro justamente no dia em que algo desse errado:
 *
 *   1. O caminho de erro está quebrado. Ao receber HTTP != 2xx ela faz
 *      `var r = e.json()` SEM aguardar a promise, lê `r.response.errors` de
 *      um objeto Promise (undefined) e chama o callback de erro com
 *      `{message: undefined}`. Depois NÃO retorna, então o `.then` seguinte
 *      roda com a resposta undefined, estoura um TypeError e chama o
 *      callback de erro DE NOVO. Erro dispara duas vezes e o motivo real é
 *      descartado.
 *   2. O corpo que ela joga fora é exatamente o que precisamos —
 *      {"errors":[{"errorCode":133,"field":"CardholderDocument",
 *      "message":"O documento '123' é inválido."}]}. Com a SDK, um CPF
 *      errado e uma credencial errada ficam indistinguíveis na tela.
 *   3. `setCredentials` faz Object.freeze na config; como o módulo é
 *      "use strict", chamar duas vezes LANÇA TypeError.
 *   4. Não tem timeout nem abort: um fetch pendurado deixa o botão girando
 *      para sempre.
 *
 * O contrato de rede aqui é BYTE A BYTE o mesmo da SDK — mesma URL, mesmos
 * headers, mesmos nomes de campo. Não inventamos protocolo: trocamos o
 * wrapper por um que trata erro, tem timeout e não dispara callback
 * duplicado. Se a Safra publicar uma v3, é aqui que se mexe.
 *
 * PCI: idêntico ao caminho da SDK. Nos dois casos o PAN é lido dos nossos
 * próprios inputs por JS da nossa página — a SDK não usa iframe. É o mesmo
 * desenho SAQ A-EP já adotado para Mercado Pago e Cielo.
 * ════════════════════════════════════════════════════════════════════ */

(function (window) {
  'use strict';

  // Baked na SDK oficial: o arquivo /dev aponta para hml e o /prod para
  // produção. Aqui vira decisão de runtime, vinda de pgto_gateways.sandbox.
  var HOST = {
    hml:  'https://payment-hml.safrapay.com.br',
    prod: 'https://payment.safrapay.com.br'
  };

  var TIMEOUT_MS = 15000;

  var cfg  = null;
  var bins = {};   // cache de bandeira por BIN (6 primeiros dígitos)

  function digitos(v) { return String(v == null ? '' : v).replace(/\D/g, ''); }

  function base() { return cfg.sandbox ? HOST.hml : HOST.prod; }

  /**
   * Bandeira sem rede — resposta imediata enquanto o cliente digita.
   *
   * A Elo tem centenas de faixas e esta tabela cobre só as principais; por
   * isso ela é o PLANO B de bandeiraPorBin(), nunca a fonte preferida.
   */
  function bandeiraLocal(numero) {
    var d = digitos(numero);
    if (!d) return null;
    if (/^4/.test(d))     return 'visa';
    if (/^3[47]/.test(d)) return 'amex';
    if (/^(4011|4312|4389|4514|4576|5041|5067|509|627780|636297|636368|650|6516|6550)/.test(d)) return 'elo';
    if (/^(38|60)/.test(d))                              return 'hipercard';
    if (/^(30[0-5]|36)/.test(d))                         return 'diners';
    if (/^(5[1-5]|2(2[2-9]|[3-6]\d|7[01]|720))/.test(d)) return 'mastercard';
    return null;
  }

  /**
   * Bandeira pela consulta de BIN da própria Safra (GET /v2/Card/Bin).
   *
   * É a fonte autoritativa: quem vai processar é quem diz qual é a bandeira.
   * Importa porque, com a Safra sozinha no conjunto, não há token de outra
   * adquirente devolvendo `brand` — sem isto a coluna `bandeira` do cartão
   * nasceria vazia.
   *
   * Chamada durante a DIGITAÇÃO (ao completar 6 dígitos), não no submit: o
   * resultado já está em cache quando o cliente clica em pagar, então não
   * custa latência nem vira mais um ponto de falha no caminho crítico.
   *
   * GET simples, sem header customizado — não dispara preflight CORS.
   */
  function bandeiraPorBin(bin) {
    bin = digitos(bin).slice(0, 6);

    if (bin.length < 6) return Promise.resolve(null);
    if (Object.prototype.hasOwnProperty.call(bins, bin)) return Promise.resolve(bins[bin]);
    if (!cfg) return Promise.resolve(null);

    return fetch(base() + '/v2/Card/Bin?bin=' + encodeURIComponent(bin))
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) {
        var b = (j && j.cardBrand) ? String(j.cardBrand).toLowerCase() : null;
        bins[bin] = b;
        return b;
      })
      .catch(function () { return null; });   // rede caiu: o local resolve
  }

  /**
   * Mensagem de tela a partir do erro estruturado da Safra.
   *
   * As mensagens da API são PT-BR e já legíveis, mas escritas para quem
   * integra ("Propriedade 'Cvv' é inválida."). Traduz por campo para o que o
   * cliente precisa fazer; se vier um campo novo, mostra o texto da API, que
   * é melhor do que um genérico.
   */
  /**
   * Erro NOSSO, com texto já pensado para o cliente.
   *
   * A marca existe para o `catch` distinguir o que nós lançamos do que o
   * navegador lança. Sem ela, um TypeError("Failed to fetch") — que é como
   * aparece tanto queda de rede quanto bloqueio de CSP — era repassado cru e
   * chegava em inglês na tela do cliente.
   */
  function erroNosso(msg) {
    var e = new Error(msg);
    e.nosso = true;
    return e;
  }

  function mensagemDoErro(erros) {
    var porCampo = {
      cardnumber:         'Número do cartão inválido.',
      cardholdername:     'Informe o nome como está no cartão.',
      cardholderdocument: 'CPF ou CNPJ do titular inválido.',
      expirationmonth:    'Mês de validade inválido.',
      expirationyear:     'Ano de validade inválido.',
      cvv:                'Código de segurança inválido.'
    };

    var i;
    for (i = 0; i < erros.length; i++) {
      var campo = String(erros[i].field || '').toLowerCase();
      if (porCampo[campo]) return porCampo[campo];
    }
    for (i = 0; i < erros.length; i++) {
      if (erros[i].message) return String(erros[i].message);
    }
    return 'A Safra recusou os dados do cartão.';
  }

  var SportMotoSafraPay = {

    /**
     * @param {{merchantCredential:string, merchantId:string, sandbox:boolean}} opts
     *        merchantCredential = CNPJ da loja; merchantId = o do portal.
     *        Nenhum dos dois é segredo — os dois vão para o navegador em
     *        qualquer integração transparente.
     */
    init: function (opts) {
      opts = opts || {};

      // `sandbox` PRECISA vir explícito. Chutar aqui significaria mandar um
      // cartão real para o ambiente de teste (venda que não existe) ou um
      // cartão de teste para produção. Sem o booleano, a Safra fica fora
      // desta tela — o único desfecho seguro.
      if (typeof opts.sandbox !== 'boolean') {
        console.error('[SafraPay] init sem `sandbox` booleano — ambiente indefinido.');
        return false;
      }
      if (!opts.merchantCredential || !opts.merchantId) {
        console.error('[SafraPay] init sem merchantCredential/merchantId.');
        return false;
      }

      cfg = {
        merchantCredential: String(opts.merchantCredential),
        merchantId:         String(opts.merchantId),
        sandbox:            opts.sandbox
      };
      return true;
    },

    pronto: function () { return !!cfg; },

    bandeiraPorBin: bandeiraPorBin,
    bandeiraLocal:  bandeiraLocal,

    /**
     * @param {{numero:string, validade:string, cvv:string, titular:string,
     *          documento:string}} dados  validade em MM/AA ou MM/AAAA
     */
    tokenizar: function (dados) {
      if (!cfg) return Promise.reject(new Error('Safra Pay indisponível nesta página.'));

      dados = dados || {};

      var numero = digitos(dados.numero);
      var val    = digitos(dados.validade);
      var cvv    = digitos(dados.cvv);
      var doc    = digitos(dados.documento);
      var nome   = String(dados.titular || '').trim();

      if (numero.length < 13)                    return Promise.reject(new Error('Número do cartão inválido.'));
      if (val.length !== 4 && val.length !== 6)   return Promise.reject(new Error('Validade inválida (MM/AA).'));
      if (cvv.length < 3)                         return Promise.reject(new Error('Código de segurança inválido.'));
      if (nome.length < 3)                        return Promise.reject(new Error('Informe o nome como está no cartão.'));
      if (doc.length !== 11 && doc.length !== 14) return Promise.reject(new Error('Informe o CPF ou CNPJ do titular.'));

      var mes = val.slice(0, 2);

      // ANO COM 4 DÍGITOS, SEMPRE. Verificado em homologação: "30" volta
      // `errorCode 6 — O ano '30' é inválido`. O formulário coleta MM/AA.
      var ano = val.length === 6 ? val.slice(2) : '20' + val.slice(2);

      var bin = numero.slice(0, 6);

      // A bandeira é OPCIONAL para a Safra e ela NÃO a valida (verificado em
      // homologação: tokeniza sem o campo e tokeniza com a bandeira errada).
      // Mandamos porque a SDK oficial manda; o valor que importa de verdade é
      // o que sai daqui para a coluna `bandeira` do cartão.
      return bandeiraPorBin(bin).then(function (marca) {
        marca = marca || bandeiraLocal(numero);

        var corpo = {
          brand:              marca ? marca.charAt(0).toUpperCase() + marca.slice(1) : '',
          cardNumber:         numero,
          cardholderName:     nome,
          cardholderDocument: doc,
          expirationMonth:    mes,
          expirationYear:     ano,
          cvv:                cvv
        };

        // A SDK não tem timeout: um fetch pendurado deixa o botão girando
        // para sempre. AbortController corta e vira mensagem de tela.
        var ctrl    = (typeof AbortController === 'function') ? new AbortController() : null;
        var relogio = setTimeout(function () { if (ctrl) ctrl.abort(); }, TIMEOUT_MS);

        return fetch(base() + '/v2/temporary/card', {
          method:  'POST',
          headers: {
            'merchantCredential': cfg.merchantCredential,
            'merchantId':         cfg.merchantId,
            'Content-Type':       'application/json'
          },
          body:   JSON.stringify(corpo),
          signal: ctrl ? ctrl.signal : undefined
        })
          // AGUARDA o json em QUALQUER status — é o passo que a SDK erra e o
          // que separa "não foi possível" do motivo real.
          .then(function (resp) {
            return resp.json()
              .catch(function () { return null; })
              .then(function (j) { return { http: resp.status, corpo: j }; });
          })
          .then(function (r) {
            clearTimeout(relogio);

            var j = r.corpo || {};

            if (j.cardToken) {
              return {
                cardToken: String(j.cardToken),
                brand:     marca || null,
                last4:     numero.slice(-4),
                bin:       bin,
                // traceKey identifica a chamada no lado da Safra: é com ele
                // que o suporte deles acha o registro. Não carrega dado de
                // cartão.
                bruto:     { traceKey: j.traceKey || null, http: r.http }
              };
            }

            var erros = Array.isArray(j.errors) ? j.errors : [];

            // Só código, campo e traceKey no console — nunca o corpo que
            // enviamos, que contém o PAN.
            console.warn('[SafraPay] tokenização recusada:', {
              http:     r.http,
              traceKey: j.traceKey || null,
              erros:    erros.map(function (e) { return { errorCode: e.errorCode, field: e.field }; })
            });

            // 401/403 é credencial da loja, não cartão. Dizer "confira os
            // dados do cartão" faria o cliente redigitar um cartão bom
            // várias vezes enquanto o problema está no cadastro.
            if (r.http === 401 || r.http === 403) {
              throw erroNosso('Pagamento por cartão indisponível no momento.');
            }

            throw erroNosso(erros.length ? mensagemDoErro(erros)
                                         : 'Não foi possível validar o cartão.');
          })
          .catch(function (e) {
            clearTimeout(relogio);

            if (e && e.nosso) throw e;

            if (e && e.name === 'AbortError') {
              throw erroNosso('A Safra não respondeu. Tente novamente.');
            }

            // TypeError "Failed to fetch". O navegador NÃO conta o motivo:
            // rede fora do ar e bloqueio de CSP chegam exatamente iguais aqui
            // — a diretiva violada só aparece no console. Repassar essa
            // mensagem crua colocava um texto em inglês na frente do cliente
            // e ainda sugeria problema no cartão, que está intacto.
            console.error('[SafraPay] falha de rede ou CSP ao tokenizar:',
              e && e.message,
              '— se for CSP, liberar o host em connect-src (SecurityHelper).');

            throw erroNosso('Não foi possível contatar a operadora do cartão. '
                          + 'Verifique sua conexão e tente novamente.');
          });
      });
    }
  };

  window.SportMotoSafraPay = SportMotoSafraPay;

})(window);
