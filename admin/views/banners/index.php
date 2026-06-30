<?php
$totalBanners = array_sum(array_map(fn($z) => (int)$z['total_banners'], $zonas));
?>

<div class="admin-page">
  <div class="admin-page-header">
    <div>
      <h1>Banners</h1>
      <p><?= $totalBanners ?> banners em <?= count($zonas) ?> zonas</p>
    </div>

    <a href="<?= BASE_URL ?>/admin/banner-zonas" class="admin-nav-link">
      Zonas de Banner
    </a>
  </div>

  <!-- Cards de zonas -->
  <div class="banner-zonas-grid">
    <?php foreach ($zonas as $zona):
      $banners = $bannersPorZona[$zona['id']] ?? [];
      $cheia   = count($banners) >= (int)$zona['max_banners'];
    ?>
    <div class="banner-zona-card" data-zona-id="<?= $zona['id'] ?>">

      <!-- Header da zona -->
      <div class="bz-header">
        <div class="bz-header-info">
          <span class="bz-badge bz-badge--<?= $zona['formato'] ?>">
            <?= ucfirst($zona['formato']) ?>
          </span>
          <h3 class="bz-title"><?= View::e($zona['nome']) ?></h3>
          <span class="bz-meta">
            <?= count($banners) ?>/<?= $zona['max_banners'] ?> banners
            <?php if ($zona['largura_ideal']): ?>
            · <?= $zona['largura_ideal'] ?>×<?= $zona['altura_ideal'] ?>px
            <?php endif; ?>
          </span>
        </div>
        <a href="<?= BASE_URL ?>/admin/banners/form?zona_id=<?= $zona['id'] ?>"
           class="btn btn-sm btn-primary <?= $cheia ? 'is-disabled' : '' ?>"
           <?= $cheia ? 'onclick="event.preventDefault();showToast(\'Limite atingido nesta zona.\',\'warning\');"' : '' ?>>
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <line x1="12" y1="5" x2="12" y2="19"/>
            <line x1="5"  y1="12" x2="19" y2="12"/>
          </svg>
          Novo banner
        </a>
      </div>

      <!-- Lista de banners desta zona -->
      <?php if (empty($banners)): ?>
      <div class="bz-empty">
        <svg width="32" height="32" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="1.2" stroke-linecap="round">
          <rect x="3" y="3" width="18" height="18" rx="2"/>
          <circle cx="8.5" cy="8.5" r="1.5"/>
          <polyline points="21 15 16 10 5 21"/>
        </svg>
        <p>Nenhum banner cadastrado nesta zona.</p>
      </div>
      <?php else: ?>
      <div class="bz-banners-list" data-zona="<?= $zona['id'] ?>">
        <?php foreach ($banners as $b):
          $thumb    = !empty($b['arquivo_imagem'])
                      ? View::upload('banners/' . $b['arquivo_imagem'])
                      : null;
          $isVideo  = in_array($b['tipo_midia'], ['video','video_com_imagem']);
          $agendado = !empty($b['data_inicio']) && strtotime($b['data_inicio']) > time();
          $expirado = !empty($b['data_fim'])    && strtotime($b['data_fim'])    < time();
          $ctr      = $b['impressoes'] > 0 ? round(($b['cliques'] / $b['impressoes']) * 100, 1) : 0;
        ?>
        <div class="banner-item" data-id="<?= $b['id'] ?>">

          <!-- Drag handle -->
          <span class="banner-drag" title="Arrastar">⠿</span>

          <!-- Thumb -->
          <div class="banner-thumb">
            <?php if ($thumb): ?>
            <img src="<?= $thumb ?>" alt="" loading="lazy">
            <?php elseif ($b['arquivo_video']): ?>
            <div class="banner-thumb-video">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="white">
                <polygon points="5 3 19 12 5 21 5 3"/>
              </svg>
            </div>
            <?php else: ?>
            <div class="banner-thumb-empty">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
                <rect x="3" y="3" width="18" height="18" rx="2"/>
                <circle cx="8.5" cy="8.5" r="1.5"/>
                <polyline points="21 15 16 10 5 21"/>
              </svg>
            </div>
            <?php endif; ?>

            <?php if ($isVideo): ?>
            <span class="banner-thumb-tag">VIDEO</span>
            <?php endif; ?>
          </div>

          <!-- Info -->
          <div class="banner-info">
            <div class="banner-info-top">
              <strong><?= View::e($b['titulo']) ?></strong>
              <?php if ($agendado): ?>
              <span class="banner-tag banner-tag--agendado">
                Agendado para <?= date('d/m/Y H:i', strtotime($b['data_inicio'])) ?>
              </span>
              <?php elseif ($expirado): ?>
              <span class="banner-tag banner-tag--expirado">Expirado</span>
              <?php endif; ?>
            </div>
            <div class="banner-info-meta">
              <?php if ($b['nome_publico']): ?>
              <span class="banner-meta-item">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round">
                  <line x1="4" y1="9" x2="20" y2="9"/>
                  <line x1="4" y1="15" x2="20" y2="15"/>
                </svg>
                <?= View::e(mb_substr($b['nome_publico'], 0, 40)) ?>
              </span>
              <?php endif; ?>
              <span class="banner-meta-item">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round">
                  <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                  <circle cx="12" cy="12" r="3"/>
                </svg>
                <?= number_format($b['impressoes']) ?> imp.
              </span>
              <span class="banner-meta-item">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round">
                  <path d="M9 11l3 3L22 4"/>
                  <path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/>
                </svg>
                <?= number_format($b['cliques']) ?> cliques
              </span>
              <?php if ($b['impressoes'] > 50): ?>
              <span class="banner-meta-ctr"><?= $ctr ?>% CTR</span>
              <?php endif; ?>
            </div>
          </div>

          <!-- Actions -->
          <div class="banner-actions">
            <button type="button"
                    class="admin-toggle <?= $b['ativo'] ? 'admin-toggle--on' : '' ?>"
                    data-id="<?= $b['id'] ?>" data-type="banner">
              <span class="admin-toggle-track">
                <span class="admin-toggle-thumb"></span>
              </span>
            </button>
            <a href="<?= BASE_URL ?>/admin/banners/form?id=<?= $b['id'] ?>"
               class="btn btn-xs btn-ghost" title="Editar">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
                <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
              </svg>
            </a>
            <button type="button" class="btn btn-xs btn-ghost btn-excluir-banner"
                    data-id="<?= $b['id'] ?>" data-titulo="<?= View::e($b['titulo']) ?>"
                    title="Excluir">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
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
    <?php endforeach; ?>
  </div>
</div>