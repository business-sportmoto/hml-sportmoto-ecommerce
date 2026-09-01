<?php
// views/layouts/customer.php
$paginaAtual = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$categoryModel    = new Category();

// Contadores dos selos. Vêm do model, como o $categoryModel logo acima —
// o layout é o único consumidor, e replicar isso nos cinco controllers que
// usam este layout só criaria cinco lugares para esquecer.
$menuBadges = ['pedidos' => 0, 'devolucoes' => 0, 'motos' => 0, 'favoritos' => 0,
               'enderecos' => 0, 'cartoes' => 0, 'tier' => 'bronze', 'score' => 0];
try {
    $menuBadges = (new Customer())->getMenuBadges((int) Session::getClienteId()) + $menuBadges;
} catch (\Throwable $e) {
    // Menu sem selo é menu; menu que derruba a página, não. O erro já foi
    // registrado pelo handler global.
}

// A raiz da conta é a única página que também mostra o menu no celular —
// lá o menu lateral fica escondido, e sem isto o cliente ficaria sem
// caminho para as outras telas.
$ehHome = rtrim($paginaAtual, '/') === '/minha-conta';

/**
 * Navegação agrupada. Antes era uma lista corrida de dez itens em que
 * "Cartões" e "Segurança" pesavam o mesmo que "Meus pedidos". Agrupar separa
 * o que o cliente usa toda semana do que ele abre duas vezes por ano.
 *
 * `badge` aponta para a chave em $menuBadges; null = sem selo.
 */
$grupos = [
    ['titulo' => null, 'itens' => [
        ['url' => '/minha-conta', 'label' => 'Visão geral', 'icon' => 'grid', 'badge' => null, 'exato' => true],
    ]],
    ['titulo' => 'Compras', 'itens' => [
        ['url' => '/minha-conta/pedidos',    'label' => 'Meus pedidos',   'icon' => 'bag',        'badge' => 'pedidos',    'tom' => 'blue'],
        ['url' => '/minha-conta/devolucoes', 'label' => 'Devoluções',     'icon' => 'refresh',    'badge' => 'devolucoes', 'tom' => 'orange'],
        ['url' => '/minha-conta/carrinhos-compartilhados', 'label' => 'Carrinhos', 'icon' => 'share-cart', 'badge' => null],
        ['url' => '/minha-conta/historico',  'label' => 'Histórico',      'icon' => 'clock',      'badge' => null],
        ['url' => '/minha-conta/avaliacoes', 'label' => 'Avaliações',     'icon' => 'star',       'badge' => null],
    ]],
    ['titulo' => 'Minha garagem', 'itens' => [
        ['url' => '/minha-conta/garagem',   'label' => 'Garagem',   'icon' => 'motorcycle', 'badge' => 'motos',     'tom' => 'gray'],
        ['url' => '/minha-conta/favoritos', 'label' => 'Favoritos', 'icon' => 'heart',      'badge' => 'favoritos', 'tom' => 'gray'],
    ]],
    ['titulo' => 'Conta', 'itens' => [
        ['url' => '/minha-conta/enderecos', 'label' => 'Endereços',  'icon' => 'pin',    'badge' => 'enderecos', 'tom' => 'gray'],
        ['url' => '/minha-conta/cartoes',   'label' => 'Cartões',    'icon' => 'card',   'badge' => 'cartoes',   'tom' => 'gray'],
        ['url' => '/minha-conta/perfil',    'label' => 'Meu perfil', 'icon' => 'user',   'badge' => null],
        ['url' => '/minha-conta/sessoes',   'label' => 'Segurança',  'icon' => 'shield', 'badge' => null],
    ]],
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
    'star'  => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
    'refresh' => '<polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 014-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 01-4 4H3"/>',
    'share-cart' => '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/><path d="M17 14l4-4-4-4"/>',
    'logout' => '<path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">

  <?php // Aplica o tema antes de qualquer CSS pintar. Sem isto a pagina pisca
        // no tema anterior a cada navegacao (FOUC). Ausencia de atributo e um
        // estado valido: significa "seguir o sistema". ?>
  <script>
    (function () {
      try {
        var t = localStorage.getItem('loja-tema');
        if (t === 'claro')       document.documentElement.setAttribute('data-theme', 'light');
        else if (t === 'escuro') document.documentElement.setAttribute('data-theme', 'dark');
        // qualquer outro valor (inclusive nenhum) = sistema: nao marca nada
      } catch (e) { /* modo privativo: cai no sistema */ }
    })();
  </script>
  <?php View::partial('partials/seo-tags') ?>
  
  <link rel="stylesheet" href="<?= PerformanceHelper::assetVersion('css/main.css') ?>">
  <link rel="stylesheet" href="<?= PerformanceHelper::assetVersion('css/customer.css') ?>">
  <link rel="stylesheet" href="<?= PerformanceHelper::assetVersion('css/clips.css') ?>">
  <link rel="stylesheet" href="<?= PerformanceHelper::assetVersion('css/pwa-native.css') ?>">
  <link rel="stylesheet" href="<?= PerformanceHelper::assetVersion('css/toast.css') ?>">
  <?php // Depois dos outros: e ele que redeclara os tokens de :root. ?>
  <link rel="stylesheet" href="<?= PerformanceHelper::assetVersion('css/tema.css') ?>">
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
      <div class="customer-layout <?= $ehHome ? 'customer-layout--home' : '' ?>">

        <!-- Navegação da área do cliente -->
        <aside class="customer-sidebar acct-nav">

          <?php
          $avatarUrl = !empty($perfil['avatar'] ?? '')
              ? View::upload('avatars/' . $perfil['avatar'])
              : null;
          $tiers = [
              'bronze'   => ['Bronze',   '#b45309', 'rgba(251,191,36,.16)'],
              'silver'   => ['Prata',    '#cbd5e1', 'rgba(203,213,225,.16)'],
              'gold'     => ['Ouro',     '#fbbf24', 'rgba(251,191,36,.16)'],
              'platinum' => ['Platinum', '#93c5fd', 'rgba(147,197,253,.16)'],
          ];
          $tierAtual = $tiers[$menuBadges['tier']] ?? $tiers['bronze'];
          ?>

          <a href="<?= BASE_URL ?>/minha-conta/perfil" class="acct-me">
            <?php if ($avatarUrl): ?>
              <img src="<?= $avatarUrl ?>" alt="" class="acct-me-avatar">
            <?php else: ?>
              <div class="acct-me-avatar acct-me-avatar--initial">
                <?= strtoupper(mb_substr((string) Session::get('cliente_nome'), 0, 1)) ?>
              </div>
            <?php endif; ?>
            <div class="acct-me-info">
              <strong><?= View::e(Session::get('cliente_nome')) ?></strong>
              <span><?= View::e(Session::get('cliente_email')) ?></span>
              <em class="acct-me-tier"
                  style="color:<?= $tierAtual[1] ?>;background:<?= $tierAtual[2] ?>">
                <?= $tierAtual[0] ?><?= $menuBadges['score'] > 0
                    ? ' · ' . number_format($menuBadges['score']) . ' pts' : '' ?>
              </em>
            </div>
            <svg class="acct-me-go" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2.5" stroke-linecap="round"><polyline points="9 18 15 12 9 6"/></svg>
          </a>

          <nav class="acct-groups">
            <?php foreach ($grupos as $grupo): ?>
            <div class="acct-group">
              <?php if ($grupo['titulo']): ?>
                <h2 class="acct-group-title"><?= $grupo['titulo'] ?></h2>
              <?php endif; ?>
              <div class="acct-group-card">
                <?php foreach ($grupo['itens'] as $item):
                  // A raiz casa exata; as demais por prefixo, para que
                  // /pedidos/123 mantenha "Meus pedidos" aceso.
                  $ativo = !empty($item['exato'])
                      ? rtrim($paginaAtual, '/') === $item['url']
                      : str_starts_with($paginaAtual, $item['url']);
                  $n = $item['badge'] ? (int) ($menuBadges[$item['badge']] ?? 0) : 0;
                ?>
                <a href="<?= BASE_URL . $item['url'] ?>"
                   class="acct-item <?= $ativo ? 'is-active' : '' ?>">
                  <span class="acct-ico">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                      <?= $icons[$item['icon']] ?>
                    </svg>
                  </span>
                  <span class="acct-label"><?= $item['label'] ?></span>
                  <?php if ($n > 0): ?>
                    <span class="acct-badge acct-badge--<?= $item['tom'] ?? 'gray' ?>"><?= $n ?></span>
                  <?php endif; ?>
                  <svg class="acct-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                       stroke-width="2.5" stroke-linecap="round"><polyline points="9 18 15 12 9 6"/></svg>
                </a>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endforeach; ?>

            <div class="acct-group">
              <div class="acct-group-card">
                <a href="<?= BASE_URL ?>/sair" class="acct-item acct-item--danger">
                  <span class="acct-ico">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                      <?= $icons['logout'] ?>
                    </svg>
                  </span>
                  <span class="acct-label">Sair da conta</span>
                  <svg class="acct-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                       stroke-width="2.5" stroke-linecap="round"><polyline points="9 18 15 12 9 6"/></svg>
                </a>
              </div>
            </div>
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
  <?php // Controle de tema da pagina de conta; a aplicacao na carga e do <head>. ?>
  <script src="<?= PerformanceHelper::assetVersion('js/tema.js') ?>" defer></script>
  
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