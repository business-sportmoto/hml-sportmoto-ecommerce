<?php
// ════════════════════════════════════════════════════════
// views/checkout/payment-card-add.php  —  v2 (hosted fields)
//
// Diferenças da v1:
//   - Os <input> de número, validade e CVV foram REMOVIDOS
//   - Cada um virou uma <div> vazia (hosted field container)
//   - O SDK da Malga injeta iframes hospedados em hosted-fields.malga.io
//     DENTRO dessas divs — os dados do cartão NUNCA tocam nosso DOM
//   - Mantemos UX visual (preview do cartão, validações de UI) via eventos
//     que o SDK emite (cardTypeChanged, validity)
//   - O nome do titular continua sendo input normal (não é dado PCI-sensível
//     e várias bandeiras nem validam ele, mas mantém a UX familiar)
//   - Apelido segue normal também
//
// Compliance: este modelo se enquadra no PCI DSS SAQ-A (o nível mais baixo,
// sem auditoria), porque dados de cartão não passam pelo nosso domínio.
// ════════════════════════════════════════════════════════

$malgaClientId = defined('MALGA_PUBLIC_CLIENT_ID') ? MALGA_PUBLIC_CLIENT_ID : '';
$malgaApiKey   = defined('MALGA_PUBLIC_API_KEY')   ? MALGA_PUBLIC_API_KEY   : '';
$malgaSandbox  = defined('MALGA_SANDBOX')          ? (bool) MALGA_SANDBOX   : true;

// CPF/CNPJ do titular. O Mercado Pago EXIGE identificacao para emitir o
// token — sem ela o createCardToken falha com um erro que nao explica nada.
// Vem preenchido quando o cliente ja tem documento no cadastro; continua
// editavel porque o cartao pode ser de outra pessoa.
$cpfCliente = $cpfCliente ?? '';


?>
<div class="checkout-section">
  <div class="section-head">
    <div class="section-head-back">
      <a href="<?= BASE_URL ?>/checkout/payment" class="back-link">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.5" stroke-linecap="round"><polyline points="15 18 9 12 15 6"/></svg>
        Voltar
      </a>
    </div>
    <h2>
      <span class="section-num">3</span>
      Adicionar cartão de crédito
    </h2>
    <p class="section-sub">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="2" stroke-linecap="round">
        <rect x="3" y="11" width="18" height="11" rx="2"/>
        <path d="M7 11V7a5 5 0 0110 0v4"/>
      </svg>
      Dados nunca trafegam pelo nosso servidor — vão direto pra Malga
    </p>
  </div>

  <!-- Preview do cartão (atualizado pelos eventos do SDK) -->
  <div class="card-preview-3d-wrap" style="margin-bottom:24px;">
    <div class="credit-card-preview">
      <div class="card-prev-brand" id="card-prev-brand">
        <span class="card-prev-brand-placeholder">CARTÃO</span>
      </div>
      <div class="card-prev-number" id="card-prev-number">•••• •••• •••• ••••</div>
      <div class="card-prev-bottom">
        <div>
          <div class="card-prev-label">Titular</div>
          <div class="card-prev-holder" id="card-prev-holder">NOME COMPLETO</div>
        </div>
        <div>
          <div class="card-prev-label">Validade</div>
          <div class="card-prev-expiry" id="card-prev-expiry">MM/AA</div>
        </div>
      </div>
    </div>
  </div>

  <form id="form-card-add" novalidate autocomplete="off">
    <?= SecurityHelper::csrfField() ?>

    <!-- Campos populados pelo JS após tokenização -->
    <input type="hidden" name="gateway_token" id="gateway_token">
    <input type="hidden" name="bandeira"      id="card-brand-value">
    <input type="hidden" name="ultimos_4"     id="card-last4-value">
    <!-- Um token por adquirente: o navegador tokeniza o mesmo cartao em
         cada cofre e o servidor grava uma referencia por adquirente. -->
    <input type="hidden" name="tokens[mercadopago]" id="token-mercadopago">
    <input type="hidden" name="tokens[cielo]"       id="token-cielo">

    <!-- ════════ NÚMERO ════════ -->
    <div class="form-group">
      <label for="card-number">
        Número do cartão
        <span class="card-brand-detected" id="card-brand-detected"></span>
      </label>
      <!-- Container vazio: a Malga injeta um iframe AQUI -->
      <!-- Input NOSSO, nao iframe: o mesmo numero vai para o Mercado Pago
           (createCardToken) e para a Cielo (Silent Order Post) com uma
           digitacao. Classe bp-sop-* e o que o script da Cielo procura.
           Numero na pagina, nunca no servidor — SAQ A-EP, decisao no Vault. -->
      <input type="text" id="card-number" class="form-control bp-sop-cardnumber"
             inputmode="numeric" autocomplete="cc-number" maxlength="23"
             placeholder="0000 0000 0000 0000" data-pci="pan">
      <span class="field-error" id="err-numero"></span>
    </div>

    <!-- ════════ NOME (input normal — não é dado PCI sensível) ════════ -->
    <div class="form-group">
      <label for="card-holder-name">Nome impresso no cartão</label>
      <div id="card-holder-name" class="form-control hosted-field" data-placeholder=""></div>
      <span class="field-error" id="err-nome"></span>
    </div>

    <!-- ════════ CPF/CNPJ DO TITULAR ════════
         Nao e dado de cartao: input normal, sem iframe. Mas e obrigatorio —
         o Mercado Pago recusa a tokenizacao sem identificacao do titular. -->
    <div class="form-group">
      <label for="cpf_titular">
        CPF ou CNPJ do titular
        <span class="label-opt">de quem está no cartão</span>
      </label>
      <input type="text" id="cpf_titular" name="cpf_titular"
             class="form-control"
             inputmode="numeric" autocomplete="off" maxlength="18"
             placeholder="000.000.000-00"
             value="<?= View::e($cpfCliente) ?>">
      <span class="field-error" id="err-cpf"></span>
    </div>

    <div class="form-row">
      <!-- ════════ VALIDADE ════════ -->
      <div class="form-group form-col">
        <label for="card-expiration-date">Validade</label>
        <input type="text" id="card-expiration-date" class="form-control bp-sop-cardexpirationdate"
               inputmode="numeric" autocomplete="cc-exp" maxlength="5" placeholder="MM/AA">
        <span class="field-error" id="err-validade"></span>
      </div>

      <!-- ════════ CVV ════════ -->
      <div class="form-group form-col">
        <label for="card-cvv">
          CVV
          <span class="cvv-tip" title="3 dígitos no verso (4 no Amex)">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <circle cx="12" cy="12" r="10"/>
              <path d="M9.09 9a3 3 0 015.83 1c0 2-3 3-3 3"/>
              <line x1="12" y1="17" x2="12.01" y2="17"/>
            </svg>
          </span>
        </label>
        <input type="password" id="card-cvv" class="form-control bp-sop-cardcvv"
               inputmode="numeric" autocomplete="cc-csc" maxlength="4" placeholder="000">
        <span class="field-error" id="err-cvv"></span>
      </div>
    </div>

    <!-- Apelido — input normal (não é PCI) -->
    <div class="form-group">
      <label for="apelido_cartao">
        Apelido do cartão
        <span class="label-opt">opcional · só você vê</span>
      </label>
      <input type="text" id="apelido_cartao" name="apelido"
             class="form-control"
             placeholder="Ex: Cartão do trabalho, Visa pessoal…"
             maxlength="40" autocomplete="off">
      <small class="form-help">Facilita identificar na próxima compra.</small>
    </div>

    <!-- Salvar para as próximas compras -->
    <!--
      Desmarcado, o cartão AINDA vai para os cofres das adquirentes: é de lá
      que sai a referência de cobrança desta compra. O que muda é o depois —
      ele é apagado assim que o pagamento tem resultado, nos cofres e aqui.
      Ver CheckoutController::paymentCardAddPost().
    -->
    <label class="save-card-toggle" id="lbl-salvar-cartao">
      <input type="checkbox" name="salvar_cartao" value="1" id="chk-salvar-cartao" checked>
      <span class="save-card-toggle-box">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="3" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
      </span>
      <span class="save-card-toggle-text">
        <strong>Salvar cartão para as próximas compras</strong>
        <small id="txt-salvar-cartao">Na próxima vez você digita só o código de segurança.</small>
      </span>
    </label>

    <!-- Tornar padrão — só faz sentido para cartão que fica salvo -->
    <label class="save-card-toggle" id="lbl-padrao">
      <input type="checkbox" name="padrao" value="1" id="chk-padrao">
      <span class="save-card-toggle-box">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="3" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
      </span>
      <span class="save-card-toggle-text">
        <strong>Tornar cartão padrão</strong>
        <small>Usado automaticamente nas próximas compras</small>
      </span>
    </label>

    <!-- Trust row -->
    <div class="card-add-trust">
      <span>
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
        </svg>
        Dados criptografados pela Malga (PCI DSS Level 1)
      </span>
      <span>
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round">
          <rect x="3" y="11" width="18" height="11" rx="2"/>
          <path d="M7 11V7a5 5 0 0110 0v4"/>
        </svg>
        Nunca tocam nosso servidor
      </span>
    </div>

    <div id="card-add-error" class="form-alert" style="display:none;"></div>

    <button type="submit" class="btn btn-primary btn-full" id="btn-save-card" disabled>
      <span class="btn-text">Salvar e usar este cartão</span>
      <span class="btn-loading" style="display:none;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.5" stroke-linecap="round" style="animation:spin 1s linear infinite;">
          <path d="M21 12a9 9 0 11-6.219-8.56"/>
        </svg>
        Validando cartão…
      </span>
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="2.5" stroke-linecap="round">
        <line x1="5" y1="12" x2="19" y2="12"/>
        <polyline points="12 5 19 12 12 19"/>
      </svg>
    </button>
  </form>
</div>

<style>
.hosted-field {
  position: relative;
  padding: 0;
  min-height: 44px;
  overflow: hidden;
}
.hosted-field iframe {
  border: 0;
  width: 100%;
  height: 44px;
  display: block;
}
.hosted-field:not(.is-ready)::before {
  content: attr(data-placeholder);
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  padding: 0 12px;
  color: var(--c-text-muted, #94a3b8);
  font-size: 14px;
  pointer-events: none;
}
.form-control.hosted-field.is-focused {
  outline: 2px solid var(--c-primary, #0a66c2);
  outline-offset: -1px;
}
.form-control.hosted-field.is-invalid {
  border-color: var(--c-danger, #dc2626);
}
.card-add-trust {
  display:flex; flex-wrap:wrap; gap:16px;
  padding:10px 14px; background:var(--c-bg);
  border-radius:8px; margin:8px 0 14px;
}
.card-add-trust span {
  display:inline-flex; align-items:center; gap:5px;
  font-size:11.5px; font-weight:700; color:var(--c-text-muted);
}
.card-add-trust svg { stroke:var(--c-success); }
.card-prev-label {
  font-size:9px; font-weight:700; letter-spacing:.8px;
  text-transform:uppercase; color:rgba(255,255,255,.6); margin-bottom:2px;
}
@keyframes spin { to { transform: rotate(360deg); } }
.btn-loading { display:inline-flex; align-items:center; gap:8px; }
#btn-save-card:disabled { opacity: 0.5; cursor: not-allowed; }
</style>
<!-- ════ Substitua o bloco <style> no final de payment-card-add.php ════ -->
<style>
/*
 * Hosted fields — containers que recebem iframes da Malga.
 *
 * Problema anterior: o ::before com position:absolute cobria o iframe,
 * bloqueando cliques. Também: o próprio container bloqueava enquanto
 * não tinha .is-ready.
 *
 * Solução:
 *   - Antes de .is-ready: container tem pointer-events:none (não bloqueia
 *     o iframe, que ainda não existe)
 *   - ::before exibe o placeholder MAS com pointer-events:none
 *   - Depois de .is-ready: pointer-events volta ao normal; ::before some
 */

.hosted-field {
  position: relative;
  padding: 0;
  min-height: 44px;
  overflow: visible;        /* era 'hidden' — cortava o iframe em alguns layouts */
  pointer-events: auto;     /* garante que o iframe dentro recebe eventos */
}

/* Iframe injetado pelo SDK */
.hosted-field iframe {
  border: 0;
  width: 100%;
  height: 44px;
  display: block;
  position: relative;
  z-index: 1;               /* fica acima do ::before */
}

/* Placeholder exibido enquanto os iframes ainda não carregaram */
.hosted-field:not(.is-ready)::before {
  content: attr(data-placeholder);
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  padding: 0 12px;
  color: var(--c-text-muted, #94a3b8);
  font-size: 14px;
  pointer-events: none;     /* não intercepta cliques */
  z-index: 0;               /* abaixo do iframe */
}

/* Quando pronto: remove o placeholder */
.hosted-field.is-ready::before {
  display: none;
}

/* Foco visual (adicionado pelo SDK via malga-hosted-field-focused) */
.form-control.hosted-field.malga-hosted-field-focused,
.form-control.hosted-field.is-focused {
  outline: 2px solid var(--c-primary, #0a66c2);
  outline-offset: -1px;
}

.form-control.hosted-field.is-invalid {
  border-color: var(--c-danger, #dc2626);
}

.card-add-trust {
  display: flex;
  flex-wrap: wrap;
  gap: 16px;
  padding: 10px 14px;
  background: var(--c-bg);
  border-radius: 8px;
  margin: 8px 0 14px;
}
.card-add-trust span {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 11.5px;
  font-weight: 700;
  color: var(--c-text-muted);
}
.card-add-trust svg { stroke: var(--c-success); }

.card-prev-label {
  font-size: 9px;
  font-weight: 700;
  letter-spacing: .8px;
  text-transform: uppercase;
  color: rgba(255,255,255,.6);
  margin-bottom: 2px;
}
</style>
<?php
// Qual adquirente tokeniza nesta pagina.
//
// CHECKOUT_ADQUIRENTES (JSON) e o conjunto novo: TODAS as adquirentes ativas
// em que o navegador consegue tokenizar, cada uma com o que o seu script
// precisa. Quando ele existe e nao esta vazio, a tela usa inputs proprios e
// manda o mesmo cartao para cada cofre — e o desenho multi-adquirente do
// Vault (pagamentos-cartao-multi-adquirente). As constantes antigas ficam
// para o caminho legado da Malga.
$adq       = defined('CHECKOUT_ADQUIRENTE') ? CHECKOUT_ADQUIRENTE : 'malga';
$publicKey = defined('CHECKOUT_PUBLIC_KEY') ? CHECKOUT_PUBLIC_KEY : '';
$clientId  = defined('CHECKOUT_CLIENT_ID')  ? CHECKOUT_CLIENT_ID  : '';
$sandbox   = defined('CHECKOUT_SANDBOX')    ? CHECKOUT_SANDBOX    : true;

$conjunto  = defined('CHECKOUT_ADQUIRENTES') ? (json_decode(CHECKOUT_ADQUIRENTES, true) ?: []) : [];
$multi     = $conjunto !== [];
?>
<?php if ($multi): ?>
  <!--
    Inputs proprios + tokenizacao em paralelo. O numero passa pela pagina
    (JS), nunca pelo servidor. Os dois glues sao carregados; cada um so e
    usado se a adquirente dele estiver no conjunto.
  -->
  <script src="<?= PerformanceHelper::assetVersion('js/checkout-mercadopago.js') ?>" defer></script>
  <script src="<?= PerformanceHelper::assetVersion('js/checkout-cielo-sop.js') ?>" defer></script>
<?php elseif ($adq === 'mercadopago'): ?>
  <!--
    Mercado Pago: numero, validade e CVV ficam em iframes do proprio MP.
    O SDK e carregado pelo glue, que espera o global aparecer em vez de
    depender da ordem das tags.
  -->
  <script src="<?= PerformanceHelper::assetVersion('js/checkout-mercadopago.js') ?>" defer></script>
<?php else: ?>
  <!--
    Carrega o SDK Malga como ESM e a glue do checkout.
    ATENÇÃO: type="module" é obrigatório porque o SDK só publica ESM/CJS.
  -->
  <script type="module">
    import { MalgaTokenization } from '<?= PerformanceHelper::assetVersion('vendor/malga/malga-tokenization-2.3.0.js') ?>';
    window.__MalgaTokenization = MalgaTokenization;
    window.dispatchEvent(new Event('malga-sdk-ready'));
  </script>
  <script src="<?= PerformanceHelper::assetVersion('js/checkout-malga.js?v=1') ?>" defer></script>
<?php endif; ?>
<script>
  // Boot do checkout. Os dois glues expoem o MESMO contrato — init com as
  // mesmas callbacks e onSubmit com o mesmo objeto —, entao daqui para baixo
  // nada sabe qual adquirente esta em uso.
  $(function () {
    var MULTI = <?= json_encode($multi) ?>;
    var COFRES = <?= json_encode($conjunto, JSON_UNESCAPED_SLASHES) ?>;

    // ══════════════════════════════════════════════════════════════
    //  MULTI-COFRE: o cliente digita uma vez, o cartao vai para cada
    //  adquirente ativa. Falhar numa nao impede as outras — o servidor
    //  grava onde deu certo e responde onde nao deu.
    // ══════════════════════════════════════════════════════════════
    if (MULTI) {
      var MP    = window.SportMotoMercadoPagoCheckout;
      var CIELO = window.SportMotoCieloSop;
      var $err  = $('#card-add-error');
      var $btn  = $('#btn-save-card');
      var enviando = false;

      function erro(msg) { $err.text(msg).show(); }
      function limpar()  { $err.hide().text(''); }
      function digitos(v) { return String(v || '').replace(/\D/g, ''); }

      // Titular: input comum, com a classe que o script da Cielo procura.
      (function montarTitular() {
        var $div = $('#card-holder-name');
        if (!$div.length || $div.find('input').length) return;
        var $i = $('<input>', {
          type: 'text', id: 'card-holder-input', autocomplete: 'cc-name', maxlength: 60,
          'class': 'bp-sop-cardholdername',
          placeholder: $div.data('placeholder') || 'Como está no cartão',
          css: { border: 0, outline: 0, width: '100%', height: '100%',
                 background: 'transparent', font: 'inherit', color: 'inherit' }
        });
        $i.on('input', function () {
          var v = $(this).val().toUpperCase(); $(this).val(v);
          $('#card-prev-holder').text(v || 'NOME COMPLETO');
        });
        $div.empty().append($i);
      })();

      // Mascaras e previa. Nada aqui sai da pagina.
      $('#card-number').on('input', function () {
        var d = digitos($(this).val()).slice(0, 19);
        $(this).val(d.replace(/(\d{4})(?=\d)/g, '$1 '));
        $('#card-prev-number').text((d + '••••••••••••••••').slice(0, 16).replace(/(.{4})/g, '$1 ').trim());
      });
      $('#card-expiration-date').on('input', function () {
        var d = digitos($(this).val()).slice(0, 4);
        $(this).val(d.length > 2 ? d.slice(0, 2) + '/' + d.slice(2) : d);
        $('#card-prev-expiry').text(d.length === 4 ? d.slice(0, 2) + '/' + d.slice(2) : 'MM/AA');
      });

      var promessas = [];
      if (COFRES.mercadopago && MP) {
        promessas.push(MP.initCore({ publicKey: COFRES.mercadopago.publicKey })
          .then(function () { return 'mercadopago'; })
          .catch(function (e) { console.warn('[MP] init:', e); return null; }));
      }
      if (COFRES.cielo && CIELO) {
        CIELO.init(COFRES.cielo);
        promessas.push(Promise.resolve('cielo'));
      }

      Promise.all(promessas).then(function (prontas) {
        prontas = prontas.filter(Boolean);
        if (!prontas.length) { erro('Pagamento por cartão indisponível no momento.'); return; }
        $btn.prop('disabled', false);
      });

      $('#form-card-add').on('submit', function (e) {
        e.preventDefault();
        if (enviando) return;

        var dados = {
          numero:    $('#card-number').val(),
          validade:  $('#card-expiration-date').val(),
          cvv:       $('#card-cvv').val(),
          titular:   $('#card-holder-input').val(),
          documento: digitos($('#cpf_titular').val())
        };

        if (dados.documento.length !== 11 && dados.documento.length !== 14) {
          $('#err-cpf').text('Informe o CPF ou CNPJ do titular.'); $('#cpf_titular').trigger('focus'); return;
        }
        if (digitos(dados.numero).length < 13) { erro('Número do cartão inválido.'); return; }
        if (digitos(dados.validade).length !== 4) { erro('Validade inválida (MM/AA).'); return; }
        if (digitos(dados.cvv).length < 3) { erro('Código de segurança inválido.'); return; }
        if (String(dados.titular || '').trim().length < 3) { erro('Informe o nome como está no cartão.'); return; }

        limpar();
        enviando = true;
        $btn.prop('disabled', true).addClass('is-loading');

        // Os dois cofres em paralelo. allSettled: um pode falhar sem
        // derrubar o outro — e o servidor decide o que fazer com o parcial.
        var tarefas = [];
        if (COFRES.mercadopago && MP) {
          tarefas.push(MP.tokenizarCampos(dados).then(function (t) { return { cofre: 'mercadopago', t: t }; }));
        }
        if (COFRES.cielo && CIELO) {
          tarefas.push(CIELO.tokenizar().then(function (t) { return { cofre: 'cielo', t: t }; }));
        }

        Promise.allSettled(tarefas).then(function (res) {
          var ok = 0, brand = null, last4 = null, motivos = [];
          $('#token-mercadopago').val(''); $('#token-cielo').val('');

          res.forEach(function (r) {
            if (r.status !== 'fulfilled') { motivos.push(r.reason && r.reason.message); return; }
            ok++;
            var v = r.value;
            if (v.cofre === 'mercadopago') { $('#token-mercadopago').val(v.t.tokenId); brand = brand || v.t.brand; last4 = last4 || v.t.last4; }
            if (v.cofre === 'cielo')       { $('#token-cielo').val(v.t.cardToken);   brand = brand || v.t.brand; last4 = last4 || v.t.last4; }
          });

          if (!ok) {
            enviando = false; $btn.prop('disabled', false).removeClass('is-loading');
            erro(motivos.filter(Boolean)[0] || 'Não foi possível validar o cartão.');
            return;
          }

          $('#card-brand-value').val(brand || $('#card-brand-value').val() || '');
          $('#card-last4-value').val(last4 || digitos(dados.numero).slice(-4));

          // O NUMERO NAO VAI NO POST. So os tokens, a bandeira e o final.
          $('#card-number, #card-expiration-date, #card-cvv').prop('disabled', true);

          $.post('<?= BASE_URL ?>/checkout/payment/card/add', $('#form-card-add').serialize())
            .done(function (resp) {
              if (resp.ok) { window.location.href = resp.redirect || '<?= BASE_URL ?>/checkout/payment'; return; }
              erro(resp.msg || 'Não foi possível salvar o cartão.');
            })
            .fail(function () { erro('Erro de comunicação. Tente novamente.'); })
            .always(function () {
              enviando = false;
              $('#card-number, #card-expiration-date, #card-cvv').prop('disabled', false);
              $btn.prop('disabled', false).removeClass('is-loading');
            });
        });
      });

      return;   // o caminho legado abaixo nao roda
    }

    var ADQ = <?= json_encode($adq) ?>;
    var SDK = ADQ === 'mercadopago'
      ? window.SportMotoMercadoPagoCheckout
      : window.SportMotoMalgaCheckout;

    if (!SDK) {
      $('#card-add-error').text('Pagamento por cartão indisponível no momento.').show();
      return;
    }

    // Mascara e validacao do documento do titular. Fica aqui, e nao no glue,
    // porque e UX de formulario: vale para qualquer adquirente.
    var $cpf = $('#cpf_titular');

    function soDigitos(v) { return String(v || '').replace(/\D/g, ''); }

    function formatarDoc(d) {
      if (d.length <= 11) {
        return d.replace(/(\d{3})(\d)/, '$1.$2')
                .replace(/(\d{3})(\d)/, '$1.$2')
                .replace(/(\d{3})(\d{1,2})$/, '$1-$2');
      }
      return d.replace(/^(\d{2})(\d)/, '$1.$2')
              .replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3')
              .replace(/\.(\d{3})(\d)/, '.$1/$2')
              .replace(/(\d{4})(\d)/, '$1-$2');
    }

    $cpf.on('input', function () {
      var d = soDigitos($(this).val()).slice(0, 14);
      $(this).val(formatarDoc(d));
      if (d.length === 11 || d.length === 14) $('#err-cpf').text('');
    });

    $cpf.on('blur', function () {
      var d = soDigitos($(this).val());
      $('#err-cpf').text(
        d.length === 0 || d.length === 11 || d.length === 14 ? '' : 'CPF ou CNPJ incompleto.'
      );
    });

    // O glue do Mercado Pago tokeniza sob demanda, entao o botao de salvar
    // dispara a tokenizacao em vez de enviar o formulario.
    if (ADQ === 'mercadopago') {
      $('#form-card-add').on('submit', function (e) {
        e.preventDefault();

        var doc = soDigitos($cpf.val());
        if (doc.length !== 11 && doc.length !== 14) {
          $('#err-cpf').text('Informe o CPF ou CNPJ do titular.');
          $cpf.trigger('focus');
          return;
        }
        $('#err-cpf').text('');

        SDK.tokenizar({
          titular:   $('#card-holder-input').val(),
          documento: doc
        });
      });
    }

    SDK.init({
      publicKey: <?= json_encode($publicKey) ?>,
      clientId:  <?= json_encode($clientId) ?>,
      apiKey:    <?= json_encode($publicKey) ?>,
      sandbox:   <?= json_encode((bool) $sandbox) ?>,

      onReady: function () {
        $('#btn-save-card').prop('disabled', false);
      },

      // { tokenId, brand, last4, bin } — o numero do cartao nunca chega aqui.
      onSubmit: function (tokenData) {
        $('#gateway_token').val(tokenData.tokenId);
        $('#card-brand-value').val(tokenData.brand || '');
        $('#card-last4-value').val(tokenData.last4 || '');

        $.post('<?= BASE_URL ?>/checkout/payment/card/add', $('#form-card-add').serialize())
          .done(function (resp) {
            if (resp.ok) {
              window.location.href = resp.redirect || '<?= BASE_URL ?>/checkout/payment';
            } else {
              SDK.showError(resp.msg || 'Não foi possível salvar o cartão.');
            }
          })
          .fail(function () {
            SDK.showError('Erro de comunicação. Tente novamente.');
          });
      },

      onError: function (msg) { SDK.showError(msg); }
    });
  });
</script>

<script>
// "Tornar padrão" não faz sentido num cartão que vai ser apagado no fim desta
// compra: no dia seguinte o padrão apontaria para nada. Some junto.
jQuery(function ($) {
  var $salvar = $('#chk-salvar-cartao');
  var $padrao = $('#lbl-padrao');
  var $texto  = $('#txt-salvar-cartao');
  if (!$salvar.length) return;

  function refletir() {
    var salvar = $salvar.is(':checked');
    $padrao.toggle(salvar);
    if (!salvar) $('#chk-padrao').prop('checked', false);
    $texto.text(salvar
      ? 'Na próxima vez você digita só o código de segurança.'
      : 'Os dados são apagados assim que esta compra for concluída.');
  }

  $salvar.on('change', refletir);
  refletir();
});
</script>
