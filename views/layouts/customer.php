<?php
// views/layouts/customer.php
$paginaAtual = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$categoryModel    = new Category();

$menus = [
    ['url' => '/minha-conta',           'label' => 'Dashboard',    'icon' => 'grid'],
    ['url' => '/minha-conta/pedidos',   'label' => 'Meus pedidos', 'icon' => 'bag'],
    ['url' => '/minha-conta/garagem',   'label' => 'Minha garagem', 'icon' => 'motorcycle'],
    ['url' => '/minha-conta/favoritos', 'label' => 'Favoritos',    'icon' => 'heart'],
    ['url' => '/minha-conta/carrinhos-compartilhados', 'label' => 'Cart compartilhados', 'icon' => 'share-cart'],
    ['url' => '/minha-conta/historico', 'label' => 'Histórico', 'icon' => 'clock'],
    ['url' => '/minha-conta/enderecos', 'label' => 'Endereços',    'icon' => 'pin'],
    ['url' => '/minha-conta/cartoes',   'label' => 'Cartões',      'icon' => 'card'],
    ['url' => '/minha-conta/perfil',    'label' => 'Meu perfil',   'icon' => 'user'],
    ['url' => '/minha-conta/sessoes',   'label' => 'Segurança',    'icon' => 'shield'],
    
];

$icons = [
    'grid'  => '<path d="M3 3h7v7H3V3zm0 11h7v7H3v-7zm11-11h7v7h-7V3zm0 11h7v7h-7v-7z"/>',
    'motorcycle' => '<path d="M5 17a3 3 0 106 0 3 3 0 00-6 0zm13.5 0a3.5 3.5 0 117-7 3.5 3.5 0 01-7 7zM13 10h-2l-3 8H5.5M15 6l3 5h1.5M9 6h4"/>',
    'bag'   => '<path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4zM3 6h18"/><path d="M16 10a4 4 0 01-8 0"/>',
    'heart' => '<path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/>',
    'pin'   => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/>',
    'card'  => '<rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>',
    'user'  => '<path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    'shield'=> '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
    'clock' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
    'share-cart' => '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/><path d="M17 14l4-4-4-4"/>',
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <?php View::partial('partials/seo-tags') ?>
  
  <link rel="stylesheet" href="<?= PerformanceHelper::assetVersion('css/main.css') ?>">
  <link rel="stylesheet" href="<?= PerformanceHelper::assetVersion('css/customer.css') ?>">
  <link rel="stylesheet" href="<?= PerformanceHelper::assetVersion('css/clips.css') ?>">
  <link rel="stylesheet" href="<?= PerformanceHelper::assetVersion('css/pwa-native.css') ?>">
  <link rel="stylesheet" href="<?= PerformanceHelper::assetVersion('css/toast.css') ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>

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

  <div class="customer-wrapper customer">
    <div class="container">
      <div class="customer-layout">

        <!-- Sidebar da área do cliente -->
        <aside class="customer-sidebar">
          <div class="customer-profile-card">
            <?php
            $avatarUrl = !empty($perfil['avatar'] ?? '')
                ? View::upload('avatars/' . $perfil['avatar'])
                : null;
            ?>
            <?php if ($avatarUrl): ?>
              <img src="<?= $avatarUrl ?>" alt="" class="customer-avatar">
            <?php else: ?>
              <div class="customer-avatar customer-avatar--initial">
                <?= strtoupper(mb_substr(Session::get('cliente_nome'), 0, 1)) ?>
              </div>
            <?php endif; ?>
            <div class="customer-profile-info">
              <strong><?= View::e(Session::get('cliente_nome')) ?></strong>
              <span><?= View::e(Session::get('cliente_email')) ?></span>
            </div>
          </div>

          <nav class="customer-nav">
            <?php foreach ($menus as $menu):
              $isActive = str_starts_with($paginaAtual, $menu['url'])
                       && ($menu['url'] === '/minha-conta' ? $paginaAtual === $menu['url'] : true);
            ?>
            <a href="<?= BASE_URL . $menu['url'] ?>"
               class="customer-nav-item <?= $isActive ? 'active' : '' ?>">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <?= $icons[$menu['icon']] ?>
              </svg>
              <?= $menu['label'] ?>
            </a>
            <?php endforeach; ?>

            <a href="<?= BASE_URL ?>/sair" class="customer-nav-item customer-nav-item--logout">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/>
                <polyline points="16 17 21 12 16 7"/>
                <line x1="21" y1="12" x2="9" y2="12"/>
              </svg>
              Sair da conta
            </a>
          </nav>
        </aside>
        

        <!-- Conteúdo principal -->
        <main class="customer-content">
          <?php View::partial('partials/flash-message') ?>
          <?= $content ?>
        </main>

      </div>
    </div>
  </div>

  <?php View::partial('partials/footer') ?>
  <?php View::partial('partials/mini-cart') ?>
  <div id="toast-container" aria-live="polite" role="status"></div>

  <script>
    const BASE_URL   = '<?= BASE_URL ?>';
    const UPLOAD_URL = '<?= UPLOAD_URL ?>';
    const CSRF_TOKEN = '<?= SecurityHelper::generateCsrf() ?>';
  </script>


  <div id="toast-container" aria-live="polite"></div>
  
  <script src="<?= PerformanceHelper::assetVersion('js/jquery.min.js') ?>"></script>
  <script src="<?= PerformanceHelper::assetVersion('js/toast.js') ?>" defer></script>
  
  <script src="<?= PerformanceHelper::assetVersion('js/main.js') ?>" defer></script>
  <script src="<?= PerformanceHelper::assetVersion('js/checkout.js') ?>" defer></script>
  <script src="<?= PerformanceHelper::assetVersion('js/master.js') ?>" defer></script>
  <script src="<?= PerformanceHelper::assetVersion('js/customer.js') ?>" defer></script>
  <script src="<?= PerformanceHelper::assetVersion('js/cart.js') ?>" defer></script>
  <script src="<?= PerformanceHelper::assetVersion('js/clips.js') ?>" defer></script>
  <script src="<?= PerformanceHelper::assetVersion('js/pwa-core.js') ?>" defer></script>
  
  <script src="<?= PerformanceHelper::assetVersion('js/google-auth.js') ?>" defer></script>
  
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

  <?php View::partial('partials/pwa-bottom-nav', ['paginaAtual' => $paginaAtual]) ?>

  <script>
  (function ($) {
    var BASE = window.BASE_URL || '';

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
        navigator.sendBeacon(BASE + '/track', dados);
      } else {
        $.ajax({ url: BASE + '/track', method: 'POST', data: dados,
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
</body>
</html>