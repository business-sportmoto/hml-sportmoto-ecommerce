<?php
// ════════════════════════════════════════════════════════
// views/layouts/checkout-layout.php
//
// Layout master para TODAS as páginas do checkout.
// Conteúdo da página vem em $content.
//
// Variáveis esperadas no $data:
//   - etapaAtual: 'identify' | 'address' | 'payment' | 'summary'
//   - cartItens, cartTotais (injetados pelo controller)
//   - checkoutFrete, checkoutCupom (do estado)
// ════════════════════════════════════════════════════════

$etapas = [
    'identify' => ['num' => 1, 'label' => 'Identificação'],
    'address'  => ['num' => 2, 'label' => 'Entrega'],
    'payment'  => ['num' => 3, 'label' => 'Pagamento'],
    'summary'  => ['num' => 4, 'label' => 'Revisão'],
    'success'  => ['num' => 5, 'label' => 'Pedido realizado'],
];
 
$etapa           = $etapaAtual ?? 'identify';
$etapaNum        = $etapas[$etapa]['num'] ?? 1;
$isSuccess       = $etapa === 'success';
$isIdentify      = $etapa === 'identify';
 
// Sidebar lateral só aparece em address e payment
$mostrarSidebar  = in_array($etapa, ['address', 'payment'], true);
 
// Barra mobile só em address e payment
$mostrarBarraMobile = $mostrarSidebar;
 
// Totais para a barra mobile
$totalMobile = (float)($cartTotais['total'] ?? $cartTotais['subtotal'] ?? 0);
 
// CTA da barra mobile por etapa
$ctaMobile = [
    'address' => ['label' => 'Continuar',       'action' => 'btn'],
    'payment' => ['label' => 'Revisar pedido',   'action' => 'link', 'url' => BASE_URL . '/checkout/summary'],
][$etapa] ?? null;
 
// Config de parcelas para o JS (PriceHelper config)
$parcelasConfig = [
    'total'       => (float)($cartTotais['total'] ?? 0),
    'maxParcelas' => (int)ConfigHelper::get('parcelas_max', 12),
    'minParcela'  => (float)ConfigHelper::get('parcelas_min_valor', 30),
    'juros'       => [],
];

$etapaAtualNum = $etapas[$etapaAtual ?? 'identify']['num'];

$mostrarResumoLateral = ($etapaAtual ?? '') !== 'summary'
                     && ($etapaAtual ?? '') !== 'identify';

$totalParaBarraMobile = $cartTotais['total'] ?? ($cartTotais['subtotal'] ?? 0);

$ctaPorEtapa = [
    'identify' => null,  // identify tem seus próprios botões inline
    'address'  => ['label' => 'Continuar', 'url' => BASE_URL . '/checkout/payment'],
    'payment'  => ['label' => 'Revisar pedido', 'url' => BASE_URL . '/checkout/summary'],
    'summary'  => ['label' => 'Finalizar compra', 'url' => '#btn-finalize'],
];
$ctaMobile = $ctaPorEtapa[$etapaAtual ?? 'identify'] ?? null;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <!-- <meta name="viewport" content="width=device-width, initial-scale=1.0"> -->
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="format-detection" content="telephone=no, email=no, address=no">
  <?php if (($etapaAtual ?? '') === 'success'): ?>
  <meta name="theme-color" content="#16a34a">
  <?php else: ?>
  <meta name="theme-color" content="#0f172a">
  <?php endif; ?>
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="<?= SITE_NOME ?? 'Checkout' ?>">
  <meta name="mobile-web-app-capable" content="yes">

  <!-- Open Graph mínimo (para compartilhamento) -->
  <meta property="og:type"        content="website">
  <meta property="og:site_name"   content="<?= SITE_NOME ?? '' ?>">
  <meta property="og:title"       content="Checkout · <?= SITE_NOME ?? '' ?>">
  <meta property="og:description" content="Finalize seu pedido com segurança.">
  
  <!-- Canonical (evita indexação das páginas de checkout) -->
  <link rel="canonical" href="<?= BASE_URL . '/' . ltrim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/') ?>">


  <meta name="robots" content="noindex,nofollow">
  <title><?= $etapas[$etapaAtual]['label'] ?? 'Checkout' ?> · <?= SITE_NOME ?? 'Checkout' ?></title>
  
  <link rel="stylesheet" href="<?= PerformanceHelper::assetVersion('css/main.css') ?>">
  <link rel="stylesheet" href="<?= PerformanceHelper::assetVersion('css/checkout-premium.css') ?>">
  <link rel="stylesheet" href="<?= PerformanceHelper::assetVersion('css/toast.css') ?>">

  <style>
    /* iOS zoom em input com font-size < 16px → sempre usar 16px nos inputs do checkout */
    @media (max-width: 768px) {
      .checkout-body input,
      .checkout-body select,
      .checkout-body textarea {
        font-size: 16px !important;
      }
    }
  </style>

  <script src="<?= PerformanceHelper::assetVersion('js/jquery.min.js') ?>"></script>
</head>
<body class="checkout-body checkout-body--<?= $etapaAtual ?>">
  
  <!-- ── HEADER ──────────────────────────────────────── -->
  <header class="checkout-header">
    <a href="<?= BASE_URL ?>" class="checkout-logo">
      <span><?= SITE_NOME ?? 'Loja' ?></span>
    </a>

    <!-- Indicador de etapas -->
    <div class="checkout-steps">
      <?php $i = 0; foreach ($etapas as $key => $info): $i++; ?>
        <div class="checkout-step
                    <?= $info['num'] === $etapaAtualNum ? 'active' : '' ?>
                    <?= $info['num'] <  $etapaAtualNum ? 'done'   : '' ?>"
             data-step="<?= $info['num'] ?>">
          <span class="step-num">
            <?php if ($info['num'] < $etapaAtualNum): ?>
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                   stroke-width="3" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
            <?php else: ?>
              <?= $info['num'] ?>
            <?php endif; ?>
          </span>
          <span class="step-label"><?= $info['label'] ?></span>
        </div>
        <?php if ($i < count($etapas)): ?>
          <div class="checkout-step-sep"></div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>

    <a href="<?= BASE_URL ?>/carrinho" class="checkout-back-cart">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="2.5" stroke-linecap="round">
        <polyline points="15 18 9 12 15 6"/>
      </svg>
      Voltar ao carrinho
    </a>
  </header>

  <main class="checkout-main">
    <div class="checkout-container">
      <div class="checkout-layout <?= $mostrarSidebar ? 'checkout-layout--with-sidebar' : 'checkout-layout--single' ?>">
 
        <!-- Conteúdo da etapa -->
        <div class="checkout-content">
          <?= $content ?>
        </div>
 
        <!-- Resumo lateral (apenas address e payment) -->
        <?php if ($mostrarSidebar): ?>
        <aside class="checkout-sidebar" id="checkout-sidebar" aria-label="Resumo do pedido">
          <?php View::partial('checkout/_summary-sidebar', [
              'itens'  => $cartItens         ?? [],
              'totais' => $cartTotais         ?? [],
              'frete'  => $checkoutFrete      ?? null,
              'cupom'  => $checkoutCupom      ?? null,
              'etapa'  => $etapa,
          ]) ?>
        </aside>
        <?php endif; ?>
 
      </div>
    </div>
  </main>

  <!-- ── Barra fixa mobile (total + CTA) ──────────────── -->
  <?php if ($ctaMobile && $mostrarSidebar): ?>
  <div class="checkout-mobile-bar">
    <div class="checkout-mobile-bar-info">
      <span class="checkout-mobile-bar-label">Total</span>
      <strong class="checkout-mobile-bar-total" id="mobile-bar-total">
        R$ <?= number_format($totalParaBarraMobile, 2, ',', '.') ?>
      </strong>
    </div>
    <?php
      $isHashCta = str_starts_with($ctaMobile['url'], '#');
      $tag       = $isHashCta ? 'button' : 'a';
      $attr      = $isHashCta
                 ? "type=\"button\" onclick=\"document.querySelector('{$ctaMobile['url']}')?.click()\""
                 : "href=\"{$ctaMobile['url']}\"";
    ?>
    <<?= $tag ?> class="btn btn-primary checkout-mobile-bar-cta" <?= $attr ?>>
      <?= View::e($ctaMobile['label']) ?>
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="2.5" stroke-linecap="round">
        <line x1="5" y1="12" x2="19" y2="12"/>
        <polyline points="12 5 19 12 12 19"/>
      </svg>
    </<?= $tag ?>>
  </div>
  <?php endif; ?>
  
  <script>
    const BASE_URL   = '<?= BASE_URL ?>';
    const UPLOAD_URL = '<?= UPLOAD_URL ?>';
    const CSRF_TOKEN = '<?= $csrf_token ?? '' ?>';
  </script>
  
  
  
  <!-- <script src="<?= PerformanceHelper::assetVersion('js/main.js') ?>"></script> -->
  <script src="<?= PerformanceHelper::assetVersion('js/checkout.js') ?>" defer></script>
  <script src="<?= PerformanceHelper::assetVersion('js/master.js') ?>" defer></script>
  <script src="<?= PerformanceHelper::assetVersion('js/toast.js') ?>" defer></script>
  <?php if (($etapaAtual ?? '') === 'identify'): ?>
    <script src="<?= PerformanceHelper::assetVersion('js/checkout-identify.js') ?>" defer></script>
  <?php elseif (($etapaAtual ?? '') === 'address'): ?>    
    <script src="<?= PerformanceHelper::assetVersion('js/checkout-address.js') ?>" defer></script>
  <?php elseif (($etapaAtual ?? '') === 'payment'): ?>    
    <script src="<?= PerformanceHelper::assetVersion('js/checkout-frete.js') ?>" defer></script>
    <script src="<?= PerformanceHelper::assetVersion('js/checkout-pagamento.js') ?>" defer></script>
    <script src="<?= PerformanceHelper::assetVersion('js/checkout-summary.js') ?>" defer></script>
    <?php if (($modo ?? '') === 'card-add'): ?>
      <!-- <script src="<?= PerformanceHelper::assetVersion('js/checkout-card-add.js') ?>" defer></script>   -->
    <?php endif; ?>
  <?php elseif (($etapaAtual ?? '') === 'summary'): ?>
    <script src="<?= PerformanceHelper::assetVersion('js/checkout-summary.js') ?>" defer></script>
  <?php endif; ?>

  <script src="<?= PerformanceHelper::assetVersion('js/pwa-ios-fix.js') ?>"></script>
</body>
</html>