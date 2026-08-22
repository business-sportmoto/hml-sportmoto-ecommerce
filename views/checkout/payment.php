<?php
// ════════════════════════════════════════════════════════
// views/checkout/payment.php — v3
//
// ESTRUTURA:
//   1. Pix e Boleto (formas de pagamento imediatas)
//   2. Cartões salvos do cliente (se houver)
//   3. Botão "Adicionar cartão de crédito"
// ════════════════════════════════════════════════════════

$metodoPersistido = Session::get('checkout_payment_method', '');
$temCartoes       = !empty($cartoesSalvos);
?>

<div class="checkout-section" id="payment-page">

  <div class="section-head">
    <h2>
      <span class="section-num">3</span>
      Forma de pagamento
    </h2>
    <p class="section-sub">Escolha como você quer pagar. Seus dados são protegidos.</p>
  </div>

  <div id="payment-error-global" class="form-alert" style="display:none;"></div>

  <!-- ════════════════════════════════════════════
       BLOCO 1: PIX + BOLETO
       ════════════════════════════════════════════ -->
  <div class="payment-group" id="group-digital">
    <div class="payment-group-label">Pagamento digital</div>
    <div class="payment-methods-list">

      <!-- PIX -->
      <label class="payment-method-card
                    <?= $metodoPersistido === 'pix' ? 'is-selected' : '' ?>"
             data-method="pix" for="pm-pix">
        <input type="radio" id="pm-pix" name="forma_pagamento" value="pix"
               <?= $metodoPersistido === 'pix' ? 'checked' : '' ?>>

        <div class="payment-icon payment-icon--pix">
          <?= IconLibrary::render('pix-main', 'icon icon--sm'); ?>
        </div>

        <div class="payment-method-body">
          <div class="payment-method-header">
            <strong>Pix</strong>
            <span class="payment-badge-green">Aprovação instantânea</span>
          </div>
          <div class="payment-desc">QR Code gerado ao finalizar · Sem taxas</div>
        </div>

        <div class="payment-method-check">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
      </label>

      <!-- BOLETO -->
      <label class="payment-method-card
                    <?= $metodoPersistido === 'boleto' ? 'is-selected' : '' ?>"
             data-method="boleto" for="pm-boleto">
        <input type="radio" id="pm-boleto" name="forma_pagamento" value="boleto"
               <?= $metodoPersistido === 'boleto' ? 'checked' : '' ?>>

        <div class="payment-icon payment-icon--boleto">
          <?= IconLibrary::render('boleto', 'icon icon--sm'); ?>
        </div>

        <div class="payment-method-body">
          <div class="payment-method-header">
            <strong>Boleto bancário</strong>
            <span class="payment-badge-gray">Vence em 3 dias</span>
          </div>
          <div class="payment-desc">Compensação em até 2 dias úteis · Sem acréscimos</div>
        </div>

        <div class="payment-method-check">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
      </label>

    </div>
  </div>

  <!-- ════════════════════════════════════════════
       BLOCO 2: CARTÕES SALVOS (se houver)
       ════════════════════════════════════════════ -->
  <?php if ($temCartoes): ?>
  <div class="payment-group" id="group-saved-cards">
    <div class="payment-group-label">
      Cartões salvos
      <a href="<?= BASE_URL ?>/checkout/payment/card/add" class="group-label-action">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Novo
      </a>
    </div>
    <div class="payment-methods-list">

      <?php foreach ($cartoesSalvos as $cartao):
        $isSelected = $metodoPersistido === 'cartao' && ((int)Session::get('checkout_cartao_id') === (int)$cartao['id'] || (!empty($cartao['principal']) && !Session::get('checkout_cartao_id')));
      ?>
      <label class="payment-method-card saved-card-item <?= $isSelected ? 'is-selected' : '' ?>"
             data-method="cartao" data-cartao-id="<?= (int)$cartao['id'] ?>"
             for="pm-card-<?= (int)$cartao['id'] ?>">
        <input type="radio" id="pm-card-<?= (int)$cartao['id'] ?>"
               name="forma_pagamento" value="cartao_salvo_<?= (int)$cartao['id'] ?>"
               data-cartao-id="<?= (int)$cartao['id'] ?>"
               <?= $isSelected ? 'checked' : '' ?>>

        <div class="payment-icon payment-icon--card">          
          <?= IconLibrary::logo($cartao['bandeira']) ?>
        </div>

        <div class="payment-method-body">
          <div class="payment-method-header">
            <strong>
              <?= View::e(CartaoSalvo::labelBandeira($cartao['bandeira'])) ?>
              
            </strong>
            <?php if (!empty($cartao['principal'])): ?>
            <span class="payment-badge-blue">Principal</span>
            <?php endif; ?>
          </div>
          <div class="payment-desc">
            •••• <?= View::e($cartao['ultimos_4']) ?> ·
            <?= View::e($cartao['apelido']) ?> ·
            Validade <?= View::e($cartao['validade']) ?>
          </div>
        </div>

        <div class="payment-method-check">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
      </label>
      <?php endforeach; ?>

    </div>
  </div>
  <?php endif; ?>

  <!-- ════════════════════════════════════════════
       BLOCO 3: ADICIONAR NOVO CARTÃO
       ════════════════════════════════════════════ -->
  <div class="payment-group" id="group-add-card">
    <?php if (!$temCartoes): ?>
    <div class="payment-group-label">Cartão de crédito</div>
    <?php endif; ?>

    <a href="<?= BASE_URL ?>/checkout/payment/card/add"
       class="btn-add-card" id="btn-add-card">
      <span class="btn-add-card-icon">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
      </span>
      <span class="btn-add-card-body">
        <strong>Adicionar cartão de crédito</strong>
        <small>Visa, Mastercard, Elo, Amex, Hipercard</small>
      </span>
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="9 18 15 12 9 6"/></svg>
    </a>
  </div>

  <!-- ── Observação ────────────────────────────── -->
  <div class="payment-group">
    <div class="payment-group-label">
      Observações do pedido <span class="label-opt">opcional</span>
    </div>
    <textarea id="observacao" class="form-control" rows="2" maxlength="500"
              placeholder="Algo importante para nossa equipe?"><?= View::e(
        SecurityHelper::sanitizeString($observacaoAtual ?? '')
    ) ?></textarea>
  </div>

  <!-- ── Botão continuar ───────────────────────── -->
  <button type="button" class="btn btn-primary btn-full btn-to-summary" id="btn-to-summary">
    Revisar pedido
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
  </button>
</div>

<?php if (!empty($checkoutEventId)): ?>
<script>
  (function () {
    if (!window.smPixel) return;
    window.smPixel.track('InitiateCheckout', {
      value: <?= (float)($checkoutValue ?? 0) ?>,
      currency: 'BRL',
      num_items: <?= (int)($checkoutNumItems ?? 0) ?>,
      content_ids: <?= json_encode(array_map('strval', $checkoutContentIds ?? [])) ?>
    }, <?= json_encode($checkoutEventId) ?>); // ← MESMO event_id do CAPI
  })();
</script>
<?php endif; ?>