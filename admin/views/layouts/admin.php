<?php
// admin/views/layouts/admin.php
$adminNome  = $admin_nome  ?? 'Admin';
$adminNivel = $admin_nivel ?? 'admin';
$currentUri = $_SERVER['REQUEST_URI'] ?? '';

function adminIsActive(string $path): string {
    return str_contains($_SERVER['REQUEST_URI'] ?? '', $path) ? ' active' : '';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">

  <?php // Aplica o tema antes de qualquer CSS pintar — sem isto a pagina
        // pisca no tema anterior a cada navegacao (FOUC). ?>
  <script>
    (function () {
      try {
        var t = localStorage.getItem('admin-tema');
        if (t !== 'claro' && t !== 'escuro') t = 'escuro';   // padrao do painel
        document.documentElement.setAttribute('data-theme', t === 'escuro' ? 'dark' : 'light');
      } catch (e) {
        document.documentElement.setAttribute('data-theme', 'dark');
      }
    })();
  </script>
  <title><?= $page_title ?? 'Admin' ?> — <?= htmlspecialchars(\ConfigHelper::get('site_nome', 'Loja'), ENT_QUOTES) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="<?= PerformanceHelper::assetVersion('/css/admin.css', true) ?>">
  <link rel="stylesheet" href="<?= PerformanceHelper::assetVersion('/css/functions.css', true) ?>">
  <link rel="stylesheet" href="<?= PerformanceHelper::assetVersion('/css/pages.css', true) ?>">
  <link rel="stylesheet" href="<?= PerformanceHelper::assetVersion('/css/email-marketing.css', false) ?>">
  <link rel="stylesheet" href="<?= PerformanceHelper::assetVersion('/css/icon-finder.css', false) ?>">
  <link rel="stylesheet" href="<?= PerformanceHelper::assetVersion('/css/toast.css', false) ?>">
  <link rel="stylesheet" href="<?= PerformanceHelper::assetVersion('/css/lightbox.css', false) ?>">

  <?php if(trim(adminIsActive('/admin/fluxos')) == 'active'){ ?>
  <link rel="stylesheet" href="<?= PerformanceHelper::assetVersion('css/fluxo-canvas.css', true) ?>">
  <?php } ?>

  <?php if(trim(adminIsActive('/admin/logistica')) == 'active'){ ?>
  <link rel="stylesheet" href="<?= PerformanceHelper::assetVersion('css/logistica.css', true) ?>">
  <?php } ?>

  <?php // Modulo Chat (WhatsApp). O editor de fluxo usa Drawflow, o mesmo do
        // /admin/fluxos e do fluxo de pagamentos. ?>
  <?php if(trim(adminIsActive('/admin/chat')) == 'active'){ ?>
  <link rel="stylesheet" href="<?= PerformanceHelper::assetVersion('css/chat.css', true) ?>">
    <?php if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/admin/chat/fluxos/')): ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/jerosoler/Drawflow/dist/drawflow.min.css">
    <?php endif; ?>
  <?php } ?>

  <script src="<?= BASE_URL ?>/assets/js/jquery.min.js"></script>
</head>
<body class="admin-body">

<?php if (trim(adminIsActive('/admin/logistica')) === 'active') {
    // Sprite de icones do modulo; precisa vir antes do conteudo que o referencia.
    include __DIR__ . '/../logistica/_sprite.php';
} ?>

<!-- ── Sidebar ──────────────────────────────────────────── -->
<aside class="admin-sidebar" id="adminSidebar">

  <!-- Logo -->
  <div class="admin-sidebar-logo">
    <div class="admin-logo-icon">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
           stroke="white" stroke-width="2" stroke-linecap="round">
        <path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/>
        <line x1="3" y1="6" x2="21" y2="6"/>
        <path d="M16 10a4 4 0 01-8 0"/>
      </svg>
    </div>
    <span class="admin-logo-text"><?= htmlspecialchars(\ConfigHelper::get('site_nome', 'Loja'), ENT_QUOTES) ?></span>
    <span class="admin-logo-badge">Admin</span>
  </div>

  <!-- Nav -->
  <?php require_once VIEW_PATH_ADMIN.'/partials/menu.php'; ?>

  <!-- User card -->
  <div class="admin-sidebar-footer">
    <div class="admin-user-card">
      <div class="admin-user-avatar">
        <?= mb_substr($adminNome, 0, 1) ?>
      </div>
      <div class="admin-user-info">
        <strong><?= htmlspecialchars($adminNome, ENT_QUOTES) ?></strong>
        <span><?= ucfirst($adminNivel) ?></span>
      </div>
    </div>
  </div>

</aside>

<!-- ── Main ───────────────────────────────────────────────── -->
<div class="admin-main">

  <!-- Topbar -->
  <header class="admin-topbar">
    <!-- Mobile menu toggle -->
    <button class="admin-topbar-btn" id="btnMenuToggle"
            style="display:none;" aria-label="Menu">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2" stroke-linecap="round">
        <line x1="3" y1="6" x2="21" y2="6"/>
        <line x1="3" y1="12" x2="21" y2="12"/>
        <line x1="3" y1="18" x2="21" y2="18"/>
      </svg>
    </button>

    <div class="admin-topbar-search">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2" stroke-linecap="round">
        <circle cx="11" cy="11" r="8"/>
        <line x1="21" y1="21" x2="16.65" y2="16.65"/>
      </svg>
      <input type="text" placeholder="Buscar no painel..." id="adminGlobalSearch">
    </div>

    <div class="admin-topbar-actions">
      <button type="button" class="admin-topbar-btn admin-tema-btn" id="adminTemaBtn"
              title="Alternar tema" aria-label="Alternar tema claro/escuro" aria-pressed="false">
        <span class="admin-tema-ico admin-tema-ico--claro"><?= IconLibrary::render('light-mode', 'icon icon--md') ?></span>
        <span class="admin-tema-ico admin-tema-ico--escuro"><?= IconLibrary::render('dark-mode', 'icon icon--md') ?></span>
      </button>
      <a href="<?= BASE_URL ?>/admin/power-bi" class="admin-topbar-btn power" title="Power BI">
        <?= IconLibrary::render('area-chart', 'icon icon--md') ?>
        <span>Power BI</span>
      </a>
      <a href="<?= BASE_URL ?>" target="_blank" class="admin-topbar-btn" title="Ver site">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/>
          <polyline points="15 3 21 3 21 9"/>
          <line x1="10" y1="14" x2="21" y2="3"/>
        </svg>
      </a>
      <!-- <button class="admin-topbar-btn" title="Notificações">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/>
          <path d="M13.73 21a2 2 0 01-3.46 0"/>
        </svg>
        <span class="admin-topbar-btn-dot"></span>
      </button> -->

      <div class="ntf-bell-wrap">
        <button type="button" id="ntf-bell" class="ntf-bell" aria-label="Notificações">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
              stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/>
            <path d="M13.73 21a2 2 0 01-3.46 0"/>
          </svg>
          <span id="ntf-badge" class="ntf-badge" style="display:none;">0</span>
        </button>

        <!-- Modal/dropdown -->
        <div id="ntf-modal" class="ntf-modal" style="display:none;">
          <div class="ntf-modal-head">
            <span class="ntf-modal-title">Notificações</span>
            <button type="button" id="ntf-marcar-todas" class="ntf-link-btn">Marcar todas como lidas</button>
          </div>

          <div class="ntf-filtros" id="ntf-filtros">
            <button type="button" class="ntf-chip ntf-chip-ativa" data-cat="">Todas</button>
            <!-- chips de categoria são injetados via JS -->
          </div>

          <div id="ntf-lista" class="ntf-lista">
            <div class="ntf-vazio">Carregando…</div>
          </div>

          <div class="ntf-modal-foot">
            <button type="button" id="ntf-carregar-mais" class="ntf-link-btn" style="display:none;">Carregar mais</button>
          </div>
        </div>
      </div>
    </div>
  </header>

  <!-- Conteúdo -->
  <main class="admin-content">
    <?= $content ?>
  </main>
  
  <button type="button" class="icon-finder-floating js-open-icon-finder" data-target="#icon_key">Ícones</button>
</div>

<!-- Overlay mobile -->
<div id="sidebarOverlay"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:150;"
     onclick="closeSidebar()"></div>

     <!-- Toast -->
<div id="admin-toast-container"></div>
<?php require VIEW_PATH . '/partials/icon-finder.php'; ?>

<script>
  const BASE_URL   = '<?= BASE_URL ?>';
  
  const CSRF_TOKEN = '<?= \SecurityHelper::generateCsrf() ?>';

  // Mobile sidebar
  function closeSidebar() {
    document.getElementById('adminSidebar').classList.remove('open');
    document.getElementById('sidebarOverlay').style.display = 'none';
  }
  document.getElementById('btnMenuToggle')?.addEventListener('click', function () {
    document.getElementById('adminSidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').style.display = 'block';
  });

  // Mostra botão de menu no mobile
  if (window.innerWidth <= 768) {
    document.getElementById('btnMenuToggle').style.display = 'flex';
  }
  window.addEventListener('resize', function () {
    const btn = document.getElementById('btnMenuToggle');
    if (btn) btn.style.display = window.innerWidth <= 768 ? 'flex' : 'none';
  });

  // Scroll animate observer
  const observer = new IntersectionObserver((entries) => {
    entries.forEach(e => {
      if (e.isIntersecting) {
        e.target.classList.add('visible');
        observer.unobserve(e.target);
      }
    });
  }, { threshold: 0.1 });

  document.querySelectorAll('.observe-animate').forEach(el => observer.observe(el));
</script>

<script src="<?= PerformanceHelper::assetVersion('js/toast.js', false) ?>"></script>
<script src="<?= PerformanceHelper::assetVersion('js/lightbox.js', false) ?>"></script>
<script src="<?= PerformanceHelper::assetVersion('js/admin-core.js', true) ?>"></script>
<script src="<?= PerformanceHelper::assetVersion('js/admin.js', true) ?>"></script>
<script src="<?= PerformanceHelper::assetVersion('js/functions.js', true) ?>"></script>
<script src="<?= PerformanceHelper::assetVersion('js/pages.js', true) ?>"></script>
<script src="<?= PerformanceHelper::assetVersion('js/icon-finder.js', false) ?>"></script>
<?php if (!empty($extra_js)): ?>
  <?php foreach ((array)$extra_js as $js): ?>
  <script src="<?= htmlspecialchars($js, ENT_QUOTES) ?>"></script>
  <?php endforeach; ?>
<?php endif; ?>
<script src="<?= PerformanceHelper::assetVersion('js/email-marketing.js', false) ?>"></script>

<?php if(trim(adminIsActive('/admin/fluxos')) == 'active'){ ?>
<script src="<?= PerformanceHelper::assetVersion('js/fluxo-canvas.js', true) ?>"></script>
<?php } ?>

<?php if(trim(adminIsActive('/admin/logistica')) == 'active'){ ?>
<?php // Arquivo unico do modulo (espelha logistica.css); cada tela se ativa pelo seu elemento raiz. ?>
<script src="<?= PerformanceHelper::assetVersion('js/logistica.js', true) ?>"></script>
<?php } ?>

<?php if(trim(adminIsActive('/admin/chat')) == 'active'){ ?>
  <?php // O canvas so entra no editor: Drawflow e pesado demais para carregar
        // no inbox, que ja e a tela mais cara do modulo. ?>
  <?php if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/admin/chat/fluxos/')): ?>
  <script src="https://cdn.jsdelivr.net/gh/jerosoler/Drawflow/dist/drawflow.min.js"></script>
  <script src="<?= PerformanceHelper::assetVersion('js/chat-fluxo.js', true) ?>"></script>
  <?php endif; ?>
  <?php // Editor e listagem de automacoes do Instagram ?>
  <?php if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/admin/chat/automacoes')): ?>
  <script src="<?= PerformanceHelper::assetVersion('js/chat-automacao.js', true) ?>"></script>
  <?php endif; ?>
<script src="<?= PerformanceHelper::assetVersion('js/chat.js', true) ?>"></script>
<?php } ?>
</body>
</html>