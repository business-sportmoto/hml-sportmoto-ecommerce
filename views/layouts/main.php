<?php
// views/layouts/main.php — versão final com SEO e performance
ClickCaptureService::capturar();
SeoHelper::setWebSite(); // JSON-LD de WebSite apenas na home
SeoHelper::setOrganization();
$categoryModel    = new Category();

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>  
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <?php View::partial('partials/seo-tags') ?>
  <?php include VIEW_PATH . '/partials/schema-organization.php'; ?>
  <link rel="stylesheet" href="<?= PerformanceHelper::assetVersion('css/main.css') ?>">
  <link rel="stylesheet" href="<?= PerformanceHelper::assetVersion('css/clips.css') ?>">
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
  <script src="<?= PerformanceHelper::assetVersion('js/pixel.js') ?>"></script>
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
    
    window.META_PIXEL_ID = 2248940325475287;
  </script>

  <!-- Scripts carregados de forma assíncrona para melhor performance -->
  <script src="<?= PerformanceHelper::assetVersion('js/jquery.min.js') ?>"></script>
  
  <script src="<?= PerformanceHelper::assetVersion('js/toast.js') ?>" defer></script>
  <script src="<?= PerformanceHelper::assetVersion('js/main.js') ?>" defer></script>
  <script src="<?= PerformanceHelper::assetVersion('js/checkout.js') ?>" defer></script>
  <script src="<?= PerformanceHelper::assetVersion('js/master.js') ?>" defer></script>
  <script src="<?= PerformanceHelper::assetVersion('js/cart.js') ?>" defer></script>
  <script src="<?= PerformanceHelper::assetVersion('js/search.js') ?>" defer></script>
  <script src="<?= PerformanceHelper::assetVersion('js/lightbox.js') ?>" defer></script>

  <script src="<?= PerformanceHelper::assetVersion('js/hls.js') ?>" defer></script>   
  <script src="<?= PerformanceHelper::assetVersion('js/clips.js') ?>" defer></script>
  
  <!-- <script src="<?= PerformanceHelper::assetVersion('js/pwa-core.js') ?>" defer></script> -->

<!-- Adicionar após os scripts principais -->
<?php if (($page ?? '') === 'cart/shared'): ?>
<script src="<?= PerformanceHelper::assetVersion('js/shared-cart.js') ?>" defer></script>
<?php endif; ?>

  <?php if (!empty($extraJs)): ?>
    <?php foreach ((array)$extraJs as $js): ?>
      <script src="<?= PerformanceHelper::assetVersion($js) ?>" defer></script>
    <?php endforeach; ?>
  <?php endif; ?>
  
  <!-- JS extras de páginas customizadas -->
  <?php foreach ((array)($extra_js ?? []) as $jsUrl): ?>
    <script src="<?= PerformanceHelper::assetVersion($jsUrl) ?>" defer></script>
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
  <script defer>
  (function ($) {
    function track(tipo, bannerId, ctx) {
      // sendBeacon sobrevive à navegação do clique; fallback $.post
      var dados = new FormData();
      dados.append('tipo', tipo);
      dados.append('entidade_tipo', 'banner');
      dados.append('entidade_id', bannerId);
      if (ctx) {
        Object.keys(ctx).forEach(function (k) {
          dados.append('ctx[' + k + ']', ctx[k]);
        });
      }
      if (navigator.sendBeacon) {
        navigator.sendBeacon(BASE_URL + '/track', dados);
      } else {
        $.ajax({ url: BASE_URL + '/track', method: 'POST', data: dados,
                processData: false, contentType: false, async: true });
      }

      
    }

    // ── banner_click ──────────────────────────────────────────────────────────
    $(document).on('click', '.trk-banner', function () {
      var $b = $(this);
      track('banner_click', $b.data('banner-id') || 0, { pos: $b.data('pos') || '' });
      // não previne o default — navegação segue normal
    });

    // ── banner_visto (IntersectionObserver; ignora se não suportado) ─────────
    if ('IntersectionObserver' in window) {
      var vistos = {};
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (e) {
          if (!e.isIntersecting) return;
          var $b  = $(e.target);
          var id  = $b.data('banner-id') || 0;
          if (vistos[id]) return;
          vistos[id] = true;
          track('banner_visto', id, { pos: $b.data('pos') || '' });
          io.unobserve(e.target);
        });
      }, { threshold: 0.5 });

      $('.trk-banner').each(function () { io.observe(this); });
    }
  })(jQuery);
  </script>
  <?php View::partial('partials/cookie-banner') ?>
</body>
</html>