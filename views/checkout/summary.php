<?php
// ════════════════════════════════════════════════════════
// views/checkout/summary.php
// Etapa 4: revisão final + dados do cartão + finalizar.
// $itens já têm 'variacao_label' via getItensComVariacoes().
// ════════════════════════════════════════════════════════
$metodo = $frete['metodo_pagamento'] ?? Session::get('checkout_payment_method', 'cartao');
$isCartao = $metodo === 'cartao';
$isPix    = $metodo === 'pix';
$isBoleto = $metodo === 'boleto';
?>

<div class="checkout-section summary-page">

  <div class="section-head">
    <h2><span class="section-num">4</span> Revise seu pedido</h2>
    <p class="section-sub">Tudo certo? Confirme e finalize com segurança.</p>
  </div>

  <!-- ── ENDEREÇO ────────────────────────────────────── -->
  <div class="summary-block">
    <div class="summary-block-header">
      <h3 class="summary-block-title">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        Entrega
      </h3>
      <a href="<?= BASE_URL ?>/checkout/address/update" class="summary-block-edit">Alterar</a>
    </div>
    <div class="summary-block-body">
      <p class="summary-detail-main"><?= View::e($endereco['nome_destinatario']) ?></p>
      <p class="summary-detail">
        <?= View::e($endereco['logradouro']) ?>, <?= View::e($endereco['numero']) ?>
        <?php if (!empty($endereco['complemento'])): ?> — <?= View::e($endereco['complemento']) ?><?php endif; ?>
      </p>
      <p class="summary-detail"><?= View::e($endereco['bairro']) ?> — <?= View::e($endereco['cidade']) ?>/<?= View::e($endereco['estado']) ?></p>
      <p class="summary-detail summary-detail--muted">CEP <?= View::e($endereco['cep']) ?></p>
      <?php if (!empty($endereco['observacao_entrega'])): ?>
      <p class="summary-detail summary-detail--obs">
        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
        <?= View::e($endereco['observacao_entrega']) ?>
      </p>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── FRETE ───────────────────────────────────────── -->
  <div class="summary-block">
    <div class="summary-block-header">
      <h3 class="summary-block-title">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
        Frete
      </h3>
      <a href="<?= BASE_URL ?>/checkout/address" class="summary-block-edit">Alterar</a>
    </div>
    <div class="summary-block-body summary-block-body--row">
      <?php if ($frete): ?>
        <div>
          <p class="summary-detail-main"><?= View::e($frete['descricao']) ?></p>
          <p class="summary-detail">
            <?= $frete['prazo'] > 0 ? "Em até {$frete['prazo']} dia(s) útil(eis)" : "Entrega imediata" ?>
          </p>
        </div>
        <strong class="summary-frete-valor <?= (float)$frete['valor'] === 0.0 ? 'is-free' : '' ?>">
          <?= (float)$frete['valor'] === 0.0 ? 'GRÁTIS' : 'R$ ' . number_format((float)$frete['valor'], 2, ',', '.') ?>
        </strong>
      <?php else: ?>
        <span class="summary-detail summary-detail--muted">Frete não selecionado</span>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── ITENS ───────────────────────────────────────── -->
  <div class="summary-block">
    <div class="summary-block-header">
      <h3 class="summary-block-title">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>
        Itens (<?= count($itens) ?>)
      </h3>
    </div>
    <div class="summary-items-list">
      <?php foreach ($itens as $item):
        $imgUrl =ImageHelper::getCartItemImage($item['pro_id']);
        $valor  = (float)($item['valor_unitario'] ?? $item['preco'] ?? 0);
        $qtd    = (int)($item['quantidade'] ?? 1);
        $variacao = $item['variacao_label'] ?? null;
      ?>
      <div class="summary-item">
        <div class="summary-item-img">
          <img src="<?= View::e($imgUrl) ?>" alt="<?= View::e($item['nome']) ?>" loading="lazy">
          <span class="summary-item-qty"><?= $qtd ?></span>
        </div>
        <div class="summary-item-info">
          <strong><?= View::e($item['nome']) ?></strong>
          <?php if ($variacao): ?>
          <span class="summary-item-variacao"><?= View::e($variacao) ?></span>
          <?php endif; ?>
        </div>
        <span class="summary-item-price">
          R$ <?= number_format($valor * $qtd, 2, ',', '.') ?>
        </span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ── OBSERVAÇÃO ──────────────────────────────────── -->
  <?php if (!empty($observacao)): ?>
  <div class="summary-block">
    <div class="summary-block-header">
      <h3 class="summary-block-title">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
        Observação
      </h3>
      <a href="<?= BASE_URL ?>/checkout/payment" class="summary-block-edit">Alterar</a>
    </div>
    <p class="summary-detail"><?= View::e($observacao) ?></p>
  </div>
  <?php endif; ?>

  <?php View::partial('partials/_coupon-input', ['titleLabel'=>true]) ?>

  <?php
      $clienteId = (int)Session::get('cliente_id');
      
      $userModel       = new User();
      $u_data          = $userModel->getUserComplete($clienteId);

      $creditoService   = new CreditoService();
      $saldoCredito    = (float) $creditoService->getSaldoDisponivel($u_data['usuario_id'] ?? 0);
      $totalPedido     = $total;
      $creditoSessao   = (float)Session::get('checkout_credito', 0);
      $maxCredito      = round(min($saldoCredito, $totalPedido), 2);      
    ?>
    <?php if ($saldoCredito > 0): ?>
    <div class="summary-credito-widget" id="credito-widget"
          data-saldo="<?= $saldoCredito ?>"
          data-max="<?= $maxCredito ?>"
          data-total="<?= $totalPedido ?>">

      <div class="scw-header" id="scw-header">
        <div class="scw-header-left">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2.2" stroke-linecap="round">
            <rect x="2" y="7" width="20" height="14" rx="2"/>
            <path d="M16 7V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v2"/>
            <line x1="12" y1="12" x2="12" y2="16"/>
            <line x1="10" y1="14" x2="14" y2="14"/>
          </svg>
          <span>Saldo disponível</span>
        </div>
        <strong class="scw-saldo"><?= PriceHelper::format($saldoCredito) ?></strong>
      </div>

      <!-- Estado: form de aplicar -->
      <div class="scw-form" id="scw-form"
           style="<?= $creditoSessao > 0 ? 'display:none;' : '' ?>">
        <div class="scw-input-row">
          <div class="scw-prefix">R$</div>
          <input type="text" id="scw-input"
                 class="scw-input form-control"
                 value="<?= number_format($maxCredito, 2, ',', '.') ?>"
                 inputmode="decimal" autocomplete="off">
          <button type="button" class="btn btn-primary scw-btn-aplicar"
                  id="btn-credito-aplicar">
            Aplicar
          </button>
        </div>
        <div class="scw-hint">
          Máximo: <?= PriceHelper::format($maxCredito) ?>
        </div>
      </div>

      <!-- Estado: crédito aplicado -->
      <div class="scw-applied" id="scw-applied"
           style="<?= $creditoSessao > 0 ? '' : 'display:none;' ?>">
        <span class="scw-applied-label">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2.5" stroke-linecap="round">
            <polyline points="20 6 9 17 4 12"/>
          </svg>
          Crédito aplicado
        </span>
        <span class="scw-applied-valor" id="scw-applied-valor">
          <?= $creditoSessao > 0 ? '−'.PriceHelper::format($creditoSessao) : '' ?>
        </span>
        <button type="button" class="scw-remove" id="btn-credito-remover">Remover</button>
      </div>

    </div>
    <?php endif; ?>

     

  <!-- ── TOTAIS ───────────────────────────────────────── -->
  <div class="summary-totals-block">
    <div class="summary-total-row">
      <span>Subtotal</span>
      <span id="ck-subtotal">R$ <?= number_format($subtotal, 2, ',', '.') ?></span>
    </div>
    <?php if ($desconto > 0): ?>
    <div class="summary-total-row is-discount">
      <span>Desconto <?= $cupom ? '(' . View::e($cupom['codigo']) . ')' : '' ?></span>
      <span>-R$ <?= number_format($desconto, 2, ',', '.') ?></span>
    </div>
    <?php endif; ?>

    <!-- Promoção automática — preenchido pelo JS -->
    <div class="summary-total-row is-discount" id="ck-row-promo" style="display:none;">
      <span style="display:flex;align-items:center;gap:6px;">
        <span style="font-size:10.5px;font-weight:700;background:#eff6ff;color:#1d4ed8;
                     padding:1px 6px;border-radius:99px;">PROMO</span>
        <!-- Promoção -->
      </span>
      <span id="ck-promo" style="color:#16a34a;font-weight:700;"></span>
    </div>

    <input type="hidden" id="ck-total-base"
           value="<?= max(0, $subtotal - $desconto + ($frete['valor'] ?? 0)) ?>">
    <div class="summary-total-row">
      <span>Frete</span>
      <span id="summary-frete-valor">
        <?php if (!$frete): ?>
          <span class="summary-pending">A calcular</span>
        <?php elseif ($frete['valor'] === 0.0): ?>
          <strong class="is-free" style="color:var(--c-success);font-weight:800;">GRÁTIS</strong>
        <?php else: ?>
          R$ <?= number_format($frete['valor'], 2, ',', '.') ?>
        <?php endif; ?>
      </span>
    </div>

    <?php
      $creditoSessao = (float)Session::get('checkout_credito', 0);
      $totalComCredito = max(0, (float)$total - $creditoSessao);
    ?>

    <div class="summary-row summary-row--credito" id="ck-row-credito"
          style="<?= $creditoSessao > 0 ? '' : 'display:none;' ?>">
      <span>Crédito aplicado</span>
      <span id="ck-credito">
        <?= $creditoSessao > 0 ? '−'.PriceHelper::format($creditoSessao) : '' ?>
      </span>
    </div>

    <div class="summary-total-row is-total">
      <span>Total</span>
      <span id="ck-total">
        
        <?= $creditoSessao > 0 ? PriceHelper::format($totalComCredito) : 'R$ '.number_format($total, 2, ',', '.'); ?>
      </span>
    </div>
  </div>



 <!-- ════════════════════════════════════════════════
     PAGAMENTO — resumo, sem re-preencher dados
     ════════════════════════════════════════════════ -->
<?php
  $metodoSummary  = Session::get('checkout_payment_method', 'cartao');
  $cardSalvo      = null;
  $cardTemp       = null;
 
  if ($metodoSummary === 'cartao') {
    $clienteId = (int)Session::get('cliente_id');
    $cardId    = Session::get('checkout_cartao_id');
 
    if ($cardId && $cardId !== 'novo') {
      try {
        $stmtCard = Database::getInstance()->getConnection()->prepare(
          "SELECT * FROM cartoes_salvos WHERE id = ? AND cliente_id = ? AND ativo = 1 LIMIT 1"
        );
        $stmtCard->execute([(int)$cardId, $clienteId]);
        $cardSalvo = $stmtCard->fetch() ?: null;
      } catch (\PDOException $e) {}
    }
 
    if (!$cardSalvo) {
      $cardTemp = Session::get('checkout_card_temp');
      if ($cardTemp && time() > (int)($cardTemp['expires_at'] ?? 0)) {
        Session::remove('checkout_card_temp');
        $cardTemp = null;
      }
    }
  }
  $cardAtual = $cardSalvo ?? $cardTemp;

  // ── Cartao salvo que exige token novo a cada compra ─────────────────
  //
  // O Mercado Pago nao cobra por card_id: a Orders API so aceita `token`, e
  // um token novo so nasce com o codigo de seguranca. Entao cartao salvo do
  // MP ainda pede o CVV — o que o cadastro poupa e numero, validade e nome.
  //
  // Nao e atrito gratuito: e o que impede quem tem acesso a conta do
  // comprador de usar um cartao salvo sem ter o cartao na mao.
  $cvvNecessario = false;
  $cvvCardRef    = '';
  $cvvPublicKey  = '';

  if ($cardSalvo && !empty($cardSalvo['card_ref'])) {
      try {
          $gw = Database::getInstance()->getConnection()->prepare(
              'SELECT codigo FROM pgto_gateways WHERE id = ? AND ativo = 1 LIMIT 1'
          );
          $gw->execute([(int) ($cardSalvo['gateway_id'] ?? 0)]);

          if ($gw->fetchColumn() === 'mercadopago') {
              $cred          = PagamentoCredencialService::para('mercadopago');
              $cvvPublicKey  = $cred['public_key'];
              $cvvCardRef    = (string) $cardSalvo['card_ref'];
              $cvvNecessario = $cvvPublicKey !== '';
          }
      } catch (\Throwable $e) {
          // Sem o CVV o pagamento falha adiante com mensagem clara; derrubar
          // a pagina do resumo aqui seria pior.
          LogService::exception($e, 'warning', 'pagamento', ['acao' => 'cvv_cartao_salvo']);
      }
  }
?>

<div class="summary-block summary-payment-block">
  <div class="summary-block-header">
    <h3 class="summary-block-title">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="2.2" stroke-linecap="round">
        <rect x="1" y="4" width="22" height="16" rx="2"/>
        <line x1="1" y1="10" x2="23" y2="10"/>
      </svg>
      Pagamento
    </h3>
    <a href="<?= BASE_URL ?>/checkout/payment" class="summary-block-edit">Alterar</a>
  </div>

  
  <?php if ($metodoSummary === 'cartao' && !empty($cardAtual)): ?>

    <div class="payment-summary-card">
      <div class="payment-summary-card-info">
        <div class="saved-card-brand-icon saved-card-brand-icon--<?= View::e(strtolower($cardAtual['bandeira'] ?? 'default')) ?>">
          <?= IconLibrary::badge($cardAtual['bandeira']) ?>
        </div>
        <div class="payment-summary-card-details">
          <?php if (!empty($cardAtual['apelido'])): ?>
            <strong><?= View::e($cardAtual['apelido']) ?></strong>
          <?php endif; ?>
          <span class="card-number">•••• •••• •••• <?= View::e($cardAtual['ultimos_4'] ?? '????') ?></span>
          <span class="card-holder"><?= View::e($cardAtual['nome_titular'] ?? '') ?></span>
          <span class="card-expiry">Válido até <?= View::e($cardAtual['validade'] ?? '') ?></span>
        </div>
      </div>

      <?php if (!empty($cardSalvo)): ?>
        <span class="card-saved-badge">
          <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
          </svg>
          Salvo
        </span>
      <?php else: ?>
        <label class="save-card-toggle save-card-toggle--inline" id="save-card-toggle">
          <input type="checkbox" name="salvar_cartao" value="1" id="chk-salvar-cartao">
          <span class="save-card-toggle-box">
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="3.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
          </span>
          <span class="save-card-toggle-text">
            <strong>Salvar para próximas compras</strong>
            <small>Se não salvar, os dados são removidos após esta compra.</small>
          </span>
        </label>

        <!-- Nudge: aparece quando toggle NÃO está marcado -->
        <div class="save-card-nudge" id="save-card-nudge">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="10"/>
            <line x1="12" y1="8" x2="12" y2="12"/>
            <line x1="12" y1="16" x2="12.01" y2="16"/>
          </svg>
          <span>
            Salve e não precise digitar novamente —
            <button type="button" id="btn-nudge-save">Salvar este cartão</button>
          </span>
        </div>

      <?php endif; ?>
    </div>
 
    <!-- Parcelas -->
     
    <div class="form-group" style="margin-top:14px;margin-bottom:0;">
      <label for="parcelas">Parcelas</label>
      <select id="parcelas" name="parcelas" class="form-control">
        <?php foreach (PriceHelper::installments($total) as $op): ?>
        <option value="<?= (int)$op['parcelas'] ?>"
                <?= !empty($op['tem_juros']) ? 'data-juros="1"' : '' ?>>
          <?= View::e($op['label']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
 
  <?php elseif ($metodoSummary === 'cartao'): ?>
 
    <div class="payment-summary-empty">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="2.2" stroke-linecap="round">
        <circle cx="12" cy="12" r="10"/>
        <line x1="12" y1="8" x2="12" y2="12"/>
        <line x1="12" y1="16" x2="12.01" y2="16"/>
      </svg>
      Nenhum cartão selecionado.
      <a href="<?= BASE_URL ?>/checkout/payment">Selecionar cartão</a>
    </div>
 
  <?php elseif ($metodoSummary === 'pix'): ?>
 
    <div class="payment-summary-method payment-summary-method--pix">
      <div class="payment-icon payment-icon--pix">PIX</div>
      <div>
        <strong>Pix</strong>
        <span>QR Code gerado após finalizar. Confirmação em segundos.</span>
      </div>
    </div>
 
  <?php elseif ($metodoSummary === 'boleto'): ?>
 
    <div class="payment-summary-method payment-summary-method--boleto">
      <div class="payment-icon payment-icon--boleto">|||</div>
      <div>
        <strong>Boleto bancário</strong>
        <span>Compensação em até 2 dias úteis após o pagamento.</span>
      </div>
    </div>
 
  <?php endif; ?>
</div>
 

  <!-- ── SEGURANÇA + FINALIZAR ────────────────────────── -->
  <div class="payment-security-row">
    <div class="payment-security-item">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
      Pagamento criptografado
    </div>
    <div class="payment-security-item">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
      Ambiente seguro
    </div>
    <div class="payment-security-item">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
      Dados protegidos
    </div>
  </div>

  <?php if ($cvvNecessario): ?>
  <!-- ════════ CVV do cartao salvo ════════
       Campo hospedado do Mercado Pago: o codigo e digitado dentro de um
       iframe deles e vira token. Nao passa pelo nosso DOM nem pelo POST. -->
  <div class="form-group" id="cvv-salvo-bloco">
    <label for="card-cvv-salvo">
      Código de segurança
      <span class="label-opt">3 dígitos no verso · 4 no Amex</span>
    </label>
    <div id="card-cvv-salvo" class="form-control hosted-field" data-placeholder="000"></div>
    <span class="field-error" id="err-cvv-salvo"></span>
    <small class="form-help">
      Pedimos a cada compra para proteger seu cartão salvo.
    </small>
  </div>
  <?php endif; ?>

  <div id="finalize-error" class="form-alert" style="display:none;"></div>

  <button type="button" class="btn btn-primary btn-full btn-place-order" id="btn-finalize">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
    Finalizar compra com segurança
  </button>

  <p class="payment-terms">
    Ao finalizar, você concorda com nossos
    <a href="<?= BASE_URL ?>/termos-de-uso" target="_blank">Termos de Uso</a>
    e nossa <a href="<?= BASE_URL ?>/politica-privacidade" target="_blank">Política de Privacidade</a>.
  </p>
</div>

<?php if ($cvvNecessario): ?>
<script src="<?= PerformanceHelper::assetVersion('js/checkout-mercadopago.js') ?>" defer></script>
<script>
  // Monta so o campo de CVV, amarrado ao cartao salvo. O `card_ref` diz ao
  // SDK de qual cartao esse codigo e — sem ele o token nasceria orfao.
  jQuery(function ($) {
    var SDK = window.SportMotoMercadoPagoCheckout;
    if (!SDK) return;

    SDK.init({
      publicKey: <?= json_encode($cvvPublicKey) ?>,
      onReady: function () {
        SDK.montarCvvDeCartaoSalvo(<?= json_encode($cvvCardRef) ?>, 'card-cvv-salvo');
      },
      // O token so nasce quando o cliente clica em finalizar; o checkout-summary
      // guarda aqui e segue com o POST.
      onSubmit: function (t) { $(document).trigger('mp:token-salvo', [t]); },
      // O erro tambem vira evento: sem isso o botao de finalizar ficaria
      // girando ate o timeout, sem o cliente saber o que aconteceu.
      onError:  function (m) {
        $('#err-cvv-salvo').text(m);
        $(document).trigger('mp:token-erro', [{ msg: m }]);
      }
    });

    // O botao de finalizar precisa do token ANTES do POST.
    window.__mpCartaoSalvo = <?= json_encode($cvvCardRef) ?>;
  });
</script>
<?php endif; ?>

<style>

</style>