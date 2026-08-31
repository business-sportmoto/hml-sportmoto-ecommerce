<?php
// ════════════════════════════════════════════════════════
// admin/views/banners/zonas-index.php — schema real
// ════════════════════════════════════════════════════════
$isEdit = false;
?>
<div class="admin-page">

  <div class="admin-page-header">
    <div>
      <h1>Zonas de Banner</h1>
      <p>Defina onde os banners aparecem no site. Cada zona tem formato, dimensões e limite de banners.</p>
    </div>
    <a href="<?= BASE_URL ?>/admin/banner-zonas/form" class="btn btn-primary">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
        <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
      </svg>
      Nova zona
    </a>
  </div>

  <?php if (empty($zonas)): ?>
  <div class="admin-empty-state">
    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round">
      <rect x="3" y="3" width="18" height="18" rx="2"/>
    </svg>
    <h3>Nenhuma zona cadastrada</h3>
    <p>Crie sua primeira zona para começar a gerenciar banners.</p>
    <a href="<?= BASE_URL ?>/admin/banner-zonas/form" class="btn btn-primary">Criar zona</a>
  </div>
  <?php else: ?>

  <div class="bz-grid">
    <?php foreach ($zonas as $z):
      $fmtIcon = match($z['formato']) {
        'slider'     => '<path d="M3 6h18M3 12h18M3 18h18"/>',
        'grid'       => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'full_width' => '<rect x="2" y="9" width="20" height="6" rx="1"/>',
        default      => '<rect x="3" y="5" width="18" height="14" rx="2"/>',   // single
      };
      $fmtLabel = match($z['formato']) {
        'slider'     => 'Slider',
        'grid'       => 'Grade',
        'full_width' => 'Full width',
        default      => 'Único',
      };
      $dim = ($z['largura_ideal'] && $z['altura_ideal'])
           ? $z['largura_ideal'] . '×' . $z['altura_ideal'] . 'px'
           : '—';
    ?>
    <div class="bz-card <?= !$z['ativo'] ? 'is-inativo' : '' ?>" id="bz-card-<?= $z['id'] ?>">

      <div class="bz-card-icon">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
          <?= $fmtIcon ?>
        </svg>
      </div>

      <div class="bz-card-body">
        <div class="bz-card-top">
          <h3 class="bz-card-nome"><?= View::e($z['nome']) ?></h3>
          <span class="bz-formato-badge bz-formato-badge--<?= $z['formato'] ?>">
            <?= $fmtLabel ?>
          </span>
          <?php if (!$z['ativo']): ?>
          <span class="bz-formato-badge" style="background:var(--surface2);color:var(--text-2);">Inativo</span>
          <?php endif; ?>
        </div>

        <code class="bz-chave"><?= View::e($z['chave']) ?></code>

        <?php if ($z['descricao']): ?>
        <p class="bz-card-desc"><?= View::e($z['descricao']) ?></p>
        <?php endif; ?>

        <div class="bz-card-meta">
          <span class="bz-meta-item" title="Dimensões ideais">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/>
            </svg>
            <?= $dim ?>
          </span>
          <span class="bz-meta-item" title="Máx banners">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <rect x="3" y="3" width="18" height="18" rx="2"/>
              <path d="M9 3v18M15 3v18M3 9h18M3 15h18"/>
            </svg>
            Máx <?= $z['max_banners'] ?>
          </span>
          <span class="bz-meta-item <?= $z['banners_ativos'] > 0 ? 'bz-meta-item--active' : '' ?>">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <rect x="3" y="5" width="18" height="14" rx="2"/>
              <polyline points="3 7 12 13 21 7"/>
            </svg>
            <?= (int)$z['total_banners'] ?> banner<?= $z['total_banners'] != 1 ? 's' : '' ?>
            <?php if ($z['banners_ativos'] > 0): ?>
            · <span class="bz-ativos-dot"></span> <?= (int)$z['banners_ativos'] ?> ativo<?= $z['banners_ativos'] != 1 ? 's' : '' ?>
            <?php endif; ?>
          </span>
        </div>
      </div>

      <div class="bz-card-actions">
        <button type="button"
                class="btn btn-xs btn-ghost bz-btn-toggle"
                data-id="<?= $z['id'] ?>"
                title="<?= $z['ativo'] ? 'Pausar zona' : 'Ativar zona' ?>">
          <?php if ($z['ativo']): ?>
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <circle cx="12" cy="12" r="10"/><line x1="10" y1="15" x2="10" y2="9"/><line x1="14" y1="15" x2="14" y2="9"/>
          </svg> Pausar
          <?php else: ?>
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <polygon points="5 3 19 12 5 21 5 3"/>
          </svg> Ativar
          <?php endif; ?>
        </button>

        <a href="<?= BASE_URL ?>/admin/banners?zona_id=<?= $z['id'] ?>"
           class="btn btn-xs btn-ghost" title="Banners desta zona">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <rect x="3" y="5" width="18" height="14" rx="2"/><polyline points="3 7 12 13 21 7"/>
          </svg>
          Banners
          <?php if ($z['total_banners'] > 0): ?>
          <span class="admin-badge"><?= (int)$z['total_banners'] ?></span>
          <?php endif; ?>
        </a>

        <a href="<?= BASE_URL ?>/admin/banner-zonas/form?id=<?= $z['id'] ?>"
           class="btn btn-xs btn-ghost">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
            <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
          </svg>
          Editar
        </a>

        <button type="button"
                class="btn btn-xs btn-ghost bz-btn-excluir"
                data-id="<?= $z['id'] ?>"
                data-nome="<?= View::e($z['nome']) ?>"
                data-banners="<?= (int)$z['total_banners'] ?>"
                style="color:var(--danger,var(--danger))">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <polyline points="3 6 5 6 21 6"/>
            <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
          </svg>
        </button>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<script>
    let isEditZonaBanner = <?= $isEdit ? 'true' : 'false' ?>;
</script>