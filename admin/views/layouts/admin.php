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
  <title><?= $page_title ?? 'Admin' ?> — <?= htmlspecialchars(\ConfigHelper::get('site_nome', 'Loja'), ENT_QUOTES) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="<?= View::asset('css/admin.css') ?>">
  <link rel="stylesheet" href="<?= View::asset('css/functions.css') ?>">
  <link rel="stylesheet" href="<?= View::asset('css/pages.css') ?>">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/email-marketing.css">
  <link rel="stylesheet" href="<?= ASSET_URL ?>/css/icon-finder.css">
  <link rel="stylesheet" href="<?= ASSET_URL ?>/css/toast.css">

  <script src="<?= BASE_URL ?>/assets/js/jquery.min.js"></script>
</head>
<body class="admin-body">

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
  <nav class="admin-nav">

    <div class="admin-nav-section">
      <span class="admin-nav-section-title">Visão geral</span>
      <a href="<?= BASE_URL ?>/admin" class="admin-nav-item<?= adminIsActive('/admin') && !adminIsActive('/admin/') ? ' active' : (str_replace(BASE_URL, '', $currentUri) === '/admin' ? ' active' : '') ?>">
        <span class="admin-nav-icon">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
            <rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>
          </svg>
        </span>
        Dashboard
      </a>
    </div>

    <div class="admin-nav-section">
      <span class="admin-nav-section-title">Catálogo</span>
      <a href="<?= BASE_URL ?>/admin/produtos" class="admin-nav-item<?= adminIsActive('/admin/produto') ?>">
        <span class="admin-nav-icon">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/>
          </svg>
        </span>
        Produtos
      </a>      
      <a href="<?= BASE_URL ?>/admin/categorias" class="admin-nav-item<?= adminIsActive('/admin/categori') ?>">
        <span class="admin-nav-icon">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/>
          </svg>
        </span>
        Categorias
      </a>
      <a href="<?= BASE_URL ?>/admin/marcas" class="admin-nav-item<?= adminIsActive('/admin/marca') ?>">
        <span class="admin-nav-icon">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/>
            <line x1="7" y1="7" x2="7.01" y2="7"/>
          </svg>
        </span>
        Marcas
      </a>
      <a href="<?= BASE_URL ?>/admin/atributos" class="admin-nav-item<?= adminIsActive('/admin/atributos') ?>">
        <span class="admin-nav-icon">          
          <?= IconLibrary::render('shelves', 'icon icon--md') ?>
        </span>
        Atributos
      </a>
      <a href="<?= BASE_URL ?>/admin/caracteristicas" class="admin-nav-item<?= adminIsActive('/admin/caracteristicas') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('format-list-bulleted', 'icon icon--md') ?>
        </span>
        Caracteristicas
      </a>
      <a href="<?= BASE_URL ?>/admin/motos" class="admin-nav-item<?= adminIsActive('/admin/motos') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('motorcycle', 'icon icon--md') ?>
        </span>
        Motos
      </a>
    </div>

    <div class="admin-nav-section">
      <span class="admin-nav-section-title">Vendas</span>
      <a href="<?= BASE_URL ?>/admin/pedidos" class="admin-nav-item<?= adminIsActive('/admin/pedido') ?>">
        <span class="admin-nav-icon">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/>
            <line x1="3" y1="6" x2="21" y2="6"/>
            <path d="M16 10a4 4 0 01-8 0"/>
          </svg>
        </span>
        Pedidos
      </a>
      <a href="<?= BASE_URL ?>/admin/clientes" class="admin-nav-item<?= adminIsActive('/admin/cliente') ?>">
        <span class="admin-nav-icon">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/>
            <circle cx="9" cy="7" r="4"/>
            <path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/>
          </svg>
        </span>
        Clientes
      </a>
      <a href="<?= BASE_URL ?>/admin/cupons" class="admin-nav-item<?= adminIsActive('/admin/cupom') ?>">
        <span class="admin-nav-icon">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/>
          </svg>
        </span>
        Cupons
      </a>
      <a href="<?= BASE_URL ?>/admin/avaliacoes" class="admin-nav-item<?= adminIsActive('/admin/avaliac') ?>">
        <span class="admin-nav-icon">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
          </svg>
        </span>
        Avaliações
      </a>
      <a href="<?= BASE_URL ?>/admin/devolucoes" class="admin-nav-item<?= adminIsActive('/admin/devolucoes') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('low-priority', 'icon icon--md') ?>
        </span>
        Devoluções
      </a>
      <a href="<?= BASE_URL ?>/admin/promocoes" class="admin-nav-item<?= adminIsActive('/admin/promocoes') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('discount', 'icon icon--md') ?>
        </span>
        Promoções
      </a>
    </div>

    <div class="admin-nav-section">
      <span class="admin-nav-section-title">Marketing</span>
      <a href="<?= BASE_URL ?>/admin/email-marketing" class="admin-nav-item<?= adminIsActive('/admin/email-marketing/') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('mark_email_read', 'icon icon--md') ?>
        </span>
        E-mail Marketing
      </a>
      <a href="<?= BASE_URL ?>/admin/email-marketing/campanhas" class="admin-nav-item<?= adminIsActive('/admin/email-marketing/campanhas') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('campaign', 'icon icon--md') ?>
        </span>
        Campanhas
      </a>
      <a href="<?= BASE_URL ?>/admin/email-marketing/automacoes" class="admin-nav-item<?= adminIsActive('/admin/email-marketing/automacoes') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('automation', 'icon icon--md') ?>
        </span>
        Automações
      </a>
      <a href="<?= BASE_URL ?>/admin/carrinhos-abandonados" class="admin-nav-item<?= adminIsActive('/admin/carrinhos-abandonados/') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('automation', 'icon icon--md') ?>
        </span>
        Carrinho abandonado
      </a>
      <a href="<?= BASE_URL ?>/admin/recuperacao-templates" class="admin-nav-item<?= adminIsActive('/admin/recuperacao-templates/') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('automation', 'icon icon--md') ?>
        </span>
        Templates
      </a>
      
    </div>

    <div class="admin-nav-section">
      <span class="admin-nav-section-title">Conteúdo</span>
      <a href="<?= BASE_URL ?>/admin/perguntas" class="admin-nav-item<?= adminIsActive('/admin/pergunta') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('contact-support', 'icon icon--md') ?>
        </span>
        Perguntas&Respostas
      </a>
      <a href="<?= BASE_URL ?>/admin/banners" class="admin-nav-item<?= adminIsActive('/admin/banner') ?>">
        <span class="admin-nav-icon">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <rect x="3" y="3" width="18" height="18" rx="2"/>
            <circle cx="8.5" cy="8.5" r="1.5"/>
            <polyline points="21 15 16 10 5 21"/>
          </svg>
        </span>
        Banners
      </a>
      <a href="<?= BASE_URL ?>/admin/beneficios" class="admin-nav-item<?= adminIsActive('/admin/beneficio') ?>">
        <span class="admin-nav-icon">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <circle cx="12" cy="12" r="10"/>
            <line x1="12" y1="8" x2="12" y2="16"/>
            <line x1="8" y1="12" x2="16" y2="12"/>
          </svg>
        </span>
        Benefícios
      </a>

      <a href="<?= BASE_URL ?>/admin/moderacao/fotos" class="admin-nav-item<?= adminIsActive('/admin/moderacao/fotos') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('cinematic-blur', 'icon icon--md') ?>
        </span>
        Moderação de fotos
        <?php
        $count = (int)Database::getInstance()->getConnection()
            ->query("SELECT COUNT(*) FROM cliente_veiculo_fotos WHERE visibilidade='publico' AND status_moderacao='pendente'")
            ->fetchColumn();
        ?>
        <?php if ($count > 0): ?>
        <span class="admin-nav-badge admin-nav-badge--alert"><?= $count ?></span>
        <?php endif; ?>
      </a>

      <a href="<?= BASE_URL ?>/admin/clips" class="admin-nav-item<?= adminIsActive('/admin/clips') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('video-template', 'icon icon--md') ?>
        </span>
        Clips
      </a>
    </div>

    <div class="admin-nav-section">
      <span class="admin-nav-section-title">Sistema</span>
      <a href="<?= BASE_URL ?>/admin/configuracoes" class="admin-nav-item<?= adminIsActive('/admin/configurac') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('settings'); ?> 
        </span>
        Configurações
      </a>
      <a href="<?= BASE_URL ?>/admin/importar" class="admin-nav-item<?= adminIsActive('/admin/importar') ?>">
        <span class="admin-nav-icon">
          <?= IconLibrary::render('cloud-download'); ?> 
        </span>
        Importações
      </a>
      <a href="<?= BASE_URL ?>/admin/logout" class="admin-nav-item">
        <span class="admin-nav-icon">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/>
            <polyline points="16 17 21 12 16 7"/>
            <line x1="21" y1="12" x2="9" y2="12"/>
          </svg>
        </span>
        Sair
      </a>
    </div>

  </nav>

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
      <button class="admin-topbar-btn" title="Notificações">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/>
          <path d="M13.73 21a2 2 0 01-3.46 0"/>
        </svg>
        <span class="admin-topbar-btn-dot"></span>
      </button>
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

<script src="<?= ASSET_URL ?>/js/toast.js"></script>
<script src="<?= View::asset('js/admin-core.js') ?>"></script>
<script src="<?= View::asset('js/admin.js') ?>"></script>
<script src="<?= View::asset('js/functions.js') ?>"></script>
<script src="<?= View::asset('js/pages.js') ?>"></script>
<script src="<?= ASSET_URL ?>/js/icon-finder.js"></script>
<?php if (!empty($extra_js)): ?>
  <?php foreach ((array)$extra_js as $js): ?>
  <script src="<?= htmlspecialchars($js, ENT_QUOTES) ?>"></script>
  <?php endforeach; ?>
<?php endif; ?>
<script src="<?= BASE_URL ?>/assets/js/email-marketing.js"></script>
</body>
</html>