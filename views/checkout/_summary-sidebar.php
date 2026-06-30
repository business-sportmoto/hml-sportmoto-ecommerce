<?php
// ════════════════════════════════════════════════════════
// views/checkout/_summary-sidebar.php
//
// Partial do resumo lateral. Usado em /address e /payment.
// /summary mostra o resumo no corpo principal, não aqui.
//
// Variáveis:
//   $itens   - itens do carrinho (com variações via sku)
//   $totais  - ['subtotal','desconto','frete','total']
//   $frete   - estado atual do frete (CheckoutState::getFrete)
//   $cupom   - estado atual do cupom
//   $etapa   - etapa atual ('address'|'payment')
// ════════════════════════════════════════════════════════

$subtotal = (float)($totais['subtotal'] ?? 0);
$desconto = (float)($cupom['desconto']  ?? 0);
$valorFrete = (float)($frete['valor']   ?? 0);
$total    = max(0, $subtotal - $desconto + $valorFrete);

$ctaUrl  = $etapa === 'address' ? BASE_URL . '/checkout/payment' : null;
$ctaText = 'Continuar';
?>

<div class="checkout-summary-inner">

  <!-- Banner "Quase lá" -->
  <div class="summary-progress">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="2.5" stroke-linecap="round">
      <circle cx="12" cy="12" r="10"/>
      <polyline points="12 6 12 12 16 14"/>
    </svg>
    Você está quase lá
  </div>

  <h3 class="summary-title">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/>
      <line x1="3" y1="6" x2="21" y2="6"/>
      <path d="M16 10a4 4 0 01-8 0"/>
    </svg>
    Seu pedido
    <span class="summary-count"><?= count($itens) ?>
      <?= count($itens) === 1 ? 'item' : 'itens' ?>
    </span>
  </h3>

  <!-- Lista de itens -->
  <div class="checkout-items-list">
    <?php foreach ($itens as $item):
      $image_main = ImageHelper::getCartItemImage($item['produto_id']);
      
      $imagem  = $item['imagem_principal'] ?? null;
      $imgUrl  = $imagem
        ? BASE_URL . '/uploads/produtos/' . $imagem
        : BASE_URL . '/assets/img/placeholder.png';
      $variacao = $item['variacao_label'] ?? null;
      $valor   = (float)($item['valor_unitario'] ?? $item['preco'] ?? 0);
      $qtd     = (int)($item['quantidade']      ?? 1);
    ?>
    <div class="checkout-item">
      <div class="checkout-item-img">
        <img src="<?= View::e($image_main) ?>" alt="<?= View::e($item['nome_produto']) ?>" loading="lazy">
        <span class="checkout-item-qty"><?= $qtd ?></span>
      </div>
      <div class="checkout-item-info">
        <span class="checkout-item-name" title="<?= View::e($item['nome_produto']) ?>">
          <?= View::e($item['nome_produto']) ?>
        </span>
        <?php if ($variacao): ?>
        <span class="checkout-item-opts"><?= View::e($variacao) ?></span>
        <?php endif; ?>

        <?php if (!empty($item['atributos'])): ?>
              <div class="cart-item-attrs">
                <?php foreach ($item['atributos'] as $attr): ?>
                <span class="cart-attr-tag">
                  <?php if ($attr['tipo_display'] === 'color_swatch' && !empty($attr['valor_hex'])): ?>
                    <span class="cart-attr-swatch"
                          style="background:<?= View::e($attr['valor_hex']) ?>"></span>
                  <?php else: ?>
                    <span class="cart-attr-label"><?= View::e($attr['nome']) ?>:</span>
                  <?php endif; ?>
                  <span class="cart-attr-valor"><?= View::e($attr['valor']) ?></span>
                </span>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>
      </div>
      <span class="checkout-item-price">
        R$ <?= number_format($valor * $qtd, 2, ',', '.') ?>
      </span>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="summary-divider"></div>

  <!-- Cupom -->
  <!-- <div class="summary-coupon">
    <label class="summary-coupon-label">Cupom de desconto</label>
    <div>
      <input type="text" id="summary-coupon-input" class="form-control"
             placeholder="DIGITE SEU CUPOM" maxlength="30"
             value="<?= View::e($cupom['codigo'] ?? '') ?>"
             style="text-transform:uppercase;">
      <button type="button" class="btn btn-outline" id="summary-btn-coupon">
        <?= !empty($cupom) ? 'Trocar' : 'Aplicar' ?>
      </button>
    </div>
    <span id="summary-coupon-msg" class="summary-coupon-msg"></span>
  </div> -->

  <?php View::partial('partials/_coupon-input', ['titleLabel'=>true]) ?>
  <?php if ($cliente_logado): ?>
  <!-- Widget de crédito -->
   
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

      <?php if ($creditoSessao > 0): ?>
      <!-- Estado: crédito já aplicado (vindo da sessão) -->
      <div class="scw-applied" id="scw-applied">
        <span class="scw-applied-label">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
          Crédito aplicado
        </span>
        <span class="scw-applied-valor"><?= '−'.PriceHelper::format($creditoSessao) ?></span>
        <button type="button" class="scw-remove" id="btn-credito-remover">Remover</button>
      </div>
      <?php else: ?>
      <!-- Estado: crédito disponível para aplicar -->
      <div class="scw-form" id="scw-form">
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
      <?php endif; ?>

    </div>
    <?php endif; ?>

    <div class="summary-divider"></div>
  <?php endif; ?>

  <!-- Totais -->
  <!-- <div class="summary-totals">
    <div class="summary-row">
      <span>Subtotal</span>
      <span id="summary-subtotal-valor">R$ <?= number_format($subtotal, 2, ',', '.') ?></span>
    </div>

    <?php if ($desconto > 0): ?>
    <div class="summary-row summary-row--discount" id="summary-desconto-row">
      <span>Desconto <?= $cupom ? '(' . View::e($cupom['codigo']) . ')' : '' ?></span>
      <span id="summary-desconto-valor">-R$ <?= number_format($desconto, 2, ',', '.') ?></span>
    </div>
    <?php endif; ?>

    <div class="summary-row">
      <span>Frete</span>
      <span id="summary-frete-valor">
        <?php if (!$frete): ?>
          <span class="summary-pending">A calcular</span>
        <?php elseif ($valorFrete === 0.0): ?>
          <strong style="color:var(--c-success);font-weight:800;">GRÁTIS</strong>
        <?php else: ?>
          R$ <?= number_format($valorFrete, 2, ',', '.') ?>
        <?php endif; ?>
      </span>
    </div>

    <div class="summary-divider" style="margin:4px 0;"></div>

    <div class="summary-row summary-row--total">
      <span>Total</span>
      <span id="summary-total-valor">R$ <?= number_format($total, 2, ',', '.') ?></span>
    </div>
  </div> -->

  <!-- Totais -->
  <div class="summary-totals">
    <div class="summary-row">
      <span>Subtotal</span>
      <span id="ck-subtotal">R$ <?= number_format($subtotal, 2, ',', '.') ?></span>
    </div>
    <div class="summary-row">
      <span>Frete</span>
      <span id="summary-frete-valor">
        <?php if (!$frete): ?>
          <span class="summary-pending">A calcular</span>
        <?php elseif ($valorFrete === 0.0): ?>
          <strong style="color:var(--c-success);font-weight:800;">GRÁTIS</strong>
        <?php else: ?>
          R$ <?= number_format($valorFrete, 2, ',', '.') ?>
        <?php endif; ?>
      </span>
    </div>
    <?php if ($desconto > 0): ?>
    <div class="summary-row summary-row--discount" id="ck-row-desconto">
      <span>Desconto</span>
      <span id="ck-desconto">-R$ <?= number_format($desconto, 2, ',', '.') ?></span>
    </div>
    <?php endif; ?>

    <!-- Desconto de promoção automática — preenchido pelo JS (cart-promo-preview.js) -->
    <div class="summary-row summary-row--promo" id="ck-row-promo" style="display:none;">
      <span style="display:flex;align-items:center;gap:6px;">
        <span style="font-size:10.5px;font-weight:700;background:#eff6ff;color:#1d4ed8;
                     padding:1px 6px;border-radius:99px;letter-spacing:.2px;">PROMO</span>
        Promoção
      </span>
      <span id="ck-promo" style="color:#16a34a;font-weight:700;"></span>
    </div>

    <!-- Cashback a receber — não deduz o total, apenas informativo -->
    <div class="summary-row" id="ck-row-cashback" style="display:none;">
      <span style="display:flex;align-items:center;gap:6px;">
        <span style="font-size:10.5px;font-weight:700;background:#f5f3ff;color:#7c3aed;
                     padding:1px 6px;border-radius:99px;letter-spacing:.2px;">CB</span>
        Cashback
      </span>
      <span id="ck-cashback" style="color:#7c3aed;font-weight:700;font-size:12.5px;"></span>
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
    <div class="summary-divider"></div>
    <div class="summary-row summary-row--total">
      <span>Total</span>
      <span id="ck-total">
        
        <?= $creditoSessao > 0 ? PriceHelper::format($totalComCredito) : 'R$ '.number_format($total, 2, ',', '.'); ?>
      </span>
    </div>
  </div>

  <!-- Hidden refs para o JS de frete recalcular -->
  <input type="hidden" id="summary-subtotal-raw" value="<?= $subtotal ?>">
  <input type="hidden" id="summary-desconto-raw" value="<?= $desconto ?>">
  <input type="hidden" id="ck-total-base" value="<?= $total ?>">

  <!-- CTA -->
  <?php if ($ctaUrl): ?>
  <!-- <a href="<?= $ctaUrl ?>" class="btn btn-primary btn-full summary-cta">
    <?= $ctaText ?>
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="2.5" stroke-linecap="round">
      <line x1="5" y1="12" x2="19" y2="12"/>
      <polyline points="12 5 19 12 12 19"/>
    </svg>
  </a> -->
  <?php endif; ?>

  <!-- Selo de segurança -->
  <div class="summary-security">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
    </svg>
    Compra 100% segura · Dados criptografados
  </div>
</div>

<style>

</style>