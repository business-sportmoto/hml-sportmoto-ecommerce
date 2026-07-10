<?php
// views/layouts/main.php — versão final com SEO e performance
SeoHelper::setWebSite(); // JSON-LD de WebSite apenas na home
$categoryModel    = new Category();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>  
  <?php View::partial('partials/seo-tags') ?>
  <?php View::partial('partials/pwa-meta'); ?>
  <link rel="stylesheet" href="<?= PerformanceHelper::assetVersion('css/main.css') ?>">
  <link rel="stylesheet" href="<?= PerformanceHelper::assetVersion('css/mototv-busca.css') ?>">
  <link rel="stylesheet" href="<?= PerformanceHelper::assetVersion('css/clips.css') ?>">
  <link rel="stylesheet" href="<?= PerformanceHelper::assetVersion('css/pwa-native.css') ?>">
  <link rel="stylesheet" href="<?= PerformanceHelper::assetVersion('css/lightbox.css') ?>">
  <!-- CSS extras de páginas customizadas -->
  <?php foreach ((array)($extra_css ?? []) as $cssUrl): ?>
    <link rel="stylesheet" href="<?= View::e($cssUrl) ?>">
  <?php endforeach; ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet" media="print" onload="this.media='all'">
  
  <noscript>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
  </noscript>
</head>
<body class="<?= isset($bodyClass) ? View::e($bodyClass) : '' ?>">
  <?php 
    $nav_tree = CacheHelper::get('menu_categorias');
    if (!$nav_tree) {
      $nav_tree = $categoryModel->getNavTree();
      CacheHelper::set('menu_categorias', $nav_tree, 3600);
    }
  ?>
  <?php View::partial('partials/header', ['nav_tree' => $nav_tree ?? []]) ?> 
  <?php View::partial('partials/menu-mobile', ['nav_tree' => $nav_tree ?? []]) ?>

  <div id="overlay-mobile" class="overlay-mobile"></div>
  <div id="header-spacer"></div>

  <main id="main-content">    
    <?php View::partial('partials/flash-message') ?>
    <?= $content ?>
  </main>

  <?php View::partial('partials/footer') ?>
  <?php View::partial('partials/mini-cart') ?>
  <div id="toast-container" aria-live="polite" role="status"></div>

  <?php View::partial('partials/clips-feed-overlay') ?>

  <script>
    const BASE_URL   = '<?= BASE_URL ?>';
    const UPLOAD_URL = '<?= UPLOAD_URL ?>';
    const CSRF_TOKEN = '<?= $csrf_token ?? '' ?>';
  </script>

  <!-- Scripts carregados de forma assíncrona para melhor performance -->
  <script src="<?= PerformanceHelper::assetVersion('js/jquery.min.js') ?>" defer></script>
  <script src="<?= PerformanceHelper::assetVersion('js/mask.js') ?>" defer></script>
  <script src="<?= PerformanceHelper::assetVersion('js/main.js') ?>" defer></script>
  <script src="<?= PerformanceHelper::assetVersion('js/checkout.js') ?>" defer></script>
  <script src="<?= PerformanceHelper::assetVersion('js/master.js') ?>" defer></script>
  <script src="<?= PerformanceHelper::assetVersion('js/cart.js') ?>" defer></script>
  <script src="<?= PerformanceHelper::assetVersion('js/search.js') ?>" defer></script>
  <script src="<?= PerformanceHelper::assetVersion('js/lightbox.js') ?>" defer></script>

     
  <script src="<?= PerformanceHelper::assetVersion('js/clips.js') ?>" defer></script>
  <script src="<?= PerformanceHelper::assetVersion('js/hls.js') ?>" defer></script>
  <!-- <script src="<?= PerformanceHelper::assetVersion('js/pwa-core.js') ?>" defer></script> -->

<!-- Adicionar após os scripts principais -->
<?php if (($page ?? '') === 'cart/shared'): ?>
<script src="<?= View::asset('js/shared-cart.js') ?>" defer></script>
<?php endif; ?>

  <?php if (!empty($extraJs)): ?>
    <?php foreach ((array)$extraJs as $js): ?>
      <script src="<?= PerformanceHelper::assetVersion($js) ?>" defer></script>
    <?php endforeach; ?>
  <?php endif; ?>
  
  <!-- JS extras de páginas customizadas -->
  <?php foreach ((array)($extra_js ?? []) as $jsUrl): ?>
    <script src="<?= View::e($jsUrl) ?>" defer></script>
  <?php endforeach; ?>

  <!-- Backdrop do modal de variações do card -->
  <div id="pc-modal-backdrop" aria-hidden="true"></div>
  <?php // require VIEW_PATH . '/partials/icon-finder.php'; ?>
  <?php if (!empty($autoOpenClipId)): ?>
  <script>
    window.AUTO_OPEN_CLIP_ID   = <?= (int)$autoOpenClipId ?>;
    window.AUTO_OPEN_CLIP_DATA = <?= $autoOpenClipData ?? 'null' ?>;
  </script>
  <?php endif; ?>
  <?php 
  if(isset($paginaAtual)){
    View::partial('partials/pwa-bottom-nav', ['paginaAtual' => $paginaAtual]);
  }
  
  ?>
</body>
</html>