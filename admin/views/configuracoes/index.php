<?php
// views/admin/configuracoes/index.php
// $grupos → array ordenado de grupos com suas configurações

// Ícones inline para cada tipo de setting
function settingIcone(string $chave): string {
    $map = [
        'nome'          => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M20 7H4a2 2 0 00-2 2v10a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>',
        'email'         => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>',
        'default'       => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 010 14.14M4.93 4.93a10 10 0 000 14.14"/></svg>',
    ];
    return $map[$chave] ?? $map['default'];
}

$grupoAtivo = $_GET['grupo'] ?? array_key_first($grupos);
$icons = [
    'settings' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 010 14.14M4.93 4.93a10 10 0 000 14.14"/></svg>',
    'shopping-bag' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>',
    'social' => '<svg xmlns="http://www.w3.org/2000/svg" height="15" viewBox="0 -960 960 960" width="15" fill="currentColor"><path d="M121-121q-41-41-41-99t41-99q41-41 99-41 18 0 35 4.5t32 12.5l153-153v-110q-44-13-72-49.5T340-740q0-58 41-99t99-41q58 0 99 41t41 99q0 48-28 84.5T520-606v110l154 153q15-8 31.5-12.5T740-360q58 0 99 41t41 99q0 58-41 99t-99 41q-58 0-99-41t-41-99q0-18 4.5-35t12.5-32L480-424 343-287q8 15 12.5 32t4.5 35q0 58-41 99t-99 41q-58 0-99-41Zm661.5-56.5Q800-195 800-220t-17.5-42.5Q765-280 740-280t-42.5 17.5Q680-245 680-220t17.5 42.5Q715-160 740-160t42.5-17.5Zm-260-520Q540-715 540-740t-17.5-42.5Q505-800 480-800t-42.5 17.5Q420-765 420-740t17.5 42.5Q455-680 480-680t42.5-17.5Zm-260 520Q280-195 280-220t-17.5-42.5Q245-280 220-280t-42.5 17.5Q160-245 160-220t17.5 42.5Q195-160 220-160t42.5-17.5Z"/></svg>',
    'hour' => '<svg xmlns="http://www.w3.org/2000/svg" height="15" viewBox="0 -960 960 960" width="15" fill="currentColor"><path d="m612-292 56-56-148-148v-184h-80v216l172 172ZM480-80q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Zm0-400Zm0 320q133 0 226.5-93.5T800-480q0-133-93.5-226.5T480-800q-133 0-226.5 93.5T160-480q0 133 93.5 226.5T480-160Z"/></svg>',
    'address' => '<svg xmlns="http://www.w3.org/2000/svg" height="15" viewBox="0 -960 960 960" width="15" fill="currentColor"><path d="M680-600h80v-80h-80v80Zm0 160h80v-80h-80v80Zm0 160h80v-80h-80v80Zm0 160v-80h160v-560H480v56l-80-58v-78h520v720H680Zm-640 0v-400l280-200 280 200v400H360v-200h-80v200H40Zm80-80h80v-200h240v200h80v-280L320-622 120-480v280Zm560-360ZM440-200v-200H200v200-200h240v200Z"/></svg>',
    'mail' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>',
    'truck' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>',
    'send' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>',
    'credit-card' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>',
    'search' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
    'shield' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
    'package' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="16.5" y1="9.4" x2="7.5" y2="4.21"/><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>',
    'star' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
    'sliders' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/></svg>',
];
?>

<div class="admin-page cfg-page">

  <div class="admin-page-header">
    <div>
      <h1 class="admin-page-title">Configurações <?= ConfigHelper::get('site_email'); ?></h1>
      <p class="admin-page-sub">Gerencie todas as configurações do sistema.</p>
    </div>
  </div>

  <div class="cfg-layout">

    <!-- ── Sidebar de navegação ────────────────────────── -->
    <nav class="cfg-nav">
      <?php foreach ($grupos as $key => $grupo): ?>
      <a href="#cfg-grupo-<?= $key ?>"
         class="cfg-nav-item <?= $grupoAtivo === $key ? 'is-active' : '' ?>"
         data-grupo="<?= $key ?>">
        <span class="cfg-nav-icon"><?= $icons[$grupo['icon']] ?? $icons['sliders'] ?></span>
        <?= View::e($grupo['label']) ?>
        <span class="cfg-nav-count"><?= count($grupo['itens']) ?></span>
      </a>
      <?php endforeach; ?>

      <!-- Link especial: Status de pedidos -->
      <div class="cfg-nav-divider"></div>
      <a href="<?= ADMIN_URL ?>/configuracoes/status-pedidos" class="cfg-nav-item cfg-nav-item--link">
        <span class="cfg-nav-icon">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round">
            <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
          </svg>
        </span>
        Status de pedidos
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.5" stroke-linecap="round" style="margin-left:auto;">
          <polyline points="9 18 15 12 9 6"/>
        </svg>
      </a>
      <a href="<?= ADMIN_URL ?>/payment" class="cfg-nav-item cfg-nav-item--link">
        <span class="cfg-nav-icon">
          <?= IconLibrary::render('credit-score') ?>
        </span>
        Pagamentos
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.5" stroke-linecap="round" style="margin-left:auto;">
          <polyline points="9 18 15 12 9 6"/>
        </svg>
      </a>

      <a href="<?= ADMIN_URL ?>/configuracoes/logs/canais" class="cfg-nav-item cfg-nav-item--link">
        <span class="cfg-nav-icon">
          <?= IconLibrary::render('history-toggle-off') ?>
        </span>
        Logs
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.5" stroke-linecap="round" style="margin-left:auto;">
          <polyline points="9 18 15 12 9 6"/>
        </svg>
      </a>

      <a href="<?= ADMIN_URL ?>/configuracoes/pwa" class="cfg-nav-item cfg-nav-item--link">
        <span class="cfg-nav-icon">
          <?= IconLibrary::render('pwa') ?>
        </span>
        Pwa
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.5" stroke-linecap="round" style="margin-left:auto;">
          <polyline points="9 18 15 12 9 6"/>
        </svg>
      </a>

      <a href="<?= ADMIN_URL ?>/help-faq" class="cfg-nav-item cfg-nav-item--link">
        <span class="cfg-nav-icon">
          <?= IconLibrary::render('help') ?>
        </span>
        Ajuda/FAQ
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.5" stroke-linecap="round" style="margin-left:auto;">
          <polyline points="9 18 15 12 9 6"/>
        </svg>
      </a>
      <div class="cfg-nav-divider"></div>
      <a href="<?= ADMIN_URL ?>/configuracoes/bling" class="cfg-nav-item cfg-nav-item--link">
        <span class="cfg-nav-icon">
          <?= IconLibrary::render('help') ?>
        </span>
        Bling
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.5" stroke-linecap="round" style="margin-left:auto;">
          <polyline points="9 18 15 12 9 6"/>
        </svg>
      </a>
    </nav>

    <!-- ── Conteúdo ───────────────────────────────────── -->
    <div class="cfg-content" id="cfg-content">
      <?php foreach ($grupos as $key => $grupo): ?>
      <section class="cfg-grupo" id="cfg-grupo-<?= $key ?>">

        <div class="cfg-grupo-header">
          <span class="cfg-grupo-icon"><?= $icons[$grupo['icon']] ?? $icons['sliders'] ?></span>
          <h2><?= View::e($grupo['label']) ?></h2>
        </div>

        <div class="cfg-card">
          <?php foreach ($grupo['itens'] as $i => $item):
            $isBool = $item['tipo'] === 'bool';
            $isLast = $i === count($grupo['itens']) - 1;
          ?>
          <div class="cfg-row <?= $isLast ? 'cfg-row--last' : '' ?>"
               data-chave="<?= View::e($item['chave']) ?>"
               data-tipo="<?= View::e($item['tipo']) ?>">

            <div class="cfg-row-info">
              <span class="cfg-row-label"><?= View::e($item['descricao'] ?? $item['chave']) ?></span>
              <code class="cfg-row-chave"><?= View::e($item['chave']) ?></code>
            </div>

            <div class="cfg-row-valor" id="cfg-val-<?= View::e($item['chave']) ?>">
              <?php if ($isBool): ?>
                <span class="cfg-bool cfg-bool--<?= $item['valor']==='1' ? 'on' : 'off' ?>">
                  <?= $item['valor']==='1' ? '● Ativo' : '○ Inativo' ?>
                </span>
              <?php else: ?>
                <span class="cfg-val-text"><?= $item['valor_exibir'] ?></span>
              <?php endif; ?>
            </div>

            <button type="button"
                    class="btn-icon cfg-btn-editar"
                    data-chave="<?= View::e($item['chave']) ?>"
                    data-tipo="<?= View::e($item['tipo']) ?>"
                    data-valor="<?= View::e($item['valor'] ?? '') ?>"
                    data-label="<?= View::e($item['descricao'] ?? $item['chave']) ?>"
                    title="Editar">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                   stroke-width="2.5" stroke-linecap="round">
                <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
                <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
              </svg>
            </button>

          </div>
          <?php endforeach; ?>
        </div>

      </section>
      <?php endforeach; ?>
    </div>

  </div>
</div>

<script>

</script>