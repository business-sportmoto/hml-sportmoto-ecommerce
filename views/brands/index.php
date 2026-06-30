<?php
// Passa dados para o JS
$marcasJs = array_map(fn($m) => [
    'id'     => (int)$m['id'],
    'nome'   => $m['nome'],
    'slug'   => $m['slug'],
    'bg_cor' => $m['bg_cor'] ?? '#f8fafc',
    'logo'   => !empty($m['logo']) ? View::upload('brands/' . $m['logo']) : null,
], $marcas);
?>

<!-- ── Hero ─────────────────────────────────────────────── -->
<div class="brands-page-hero">
  <div class="container">
    <span class="brands-eyebrow">Catálogo</span>
    <h1>Explore por <span class="text-gradient">marcas</span></h1>
    <p><?= $totalMarcas ?> marcas disponíveis</p>
  </div>
</div>

<!-- ── Grid de todas as marcas ──────────────────────────── -->
<section class="brands-page-grid-section">
  <div class="container">

    <div class="brands-page-grid" id="brands-page-grid">
      <?php foreach ($marcas as $m):
        $bg = !empty($m['bg_cor']) ? $m['bg_cor'] : '#f8fafc';
      ?>
      <a href="<?= (int)$m['total_produtos'] > 0
              ? BASE_URL . '/marca/' . View::e($m['slug'])
              : '#' ?>"
        class="brand-page-card <?= (int)$m['total_produtos'] === 0 ? 'brand-page-card--vazia' : '' ?>"
        <?= (int)$m['total_produtos'] === 0 ? 'aria-disabled="true"' : '' ?>>

        <div class="brand-page-card-logo" style="background:<?= View::e($bg) ?>">
          <?php if (!empty($m['logo'])): ?>
          <img src="<?= View::upload('brands/' . $m['logo']) ?>"
               alt="<?= View::e($m['nome']) ?>"
               loading="lazy">
          <?php else: ?>
          <span class="brand-page-card-initials">
            <?= View::e(strtoupper($m['nome'])) ?>
          </span>
          <?php endif; ?>
        </div>

        <div class="brand-page-card-info">
          <span class="brand-page-card-nome"><?= View::e($m['nome']) ?></span>
          <span class="brand-page-card-count">
            <?= (int)$m['total_produtos'] > 0
            ? number_format((int)$m['total_produtos']) . ' produto' . ($m['total_produtos'] != 1 ? 's' : '')
            : 'Em breve' ?>
          </span>
        </div>

      </a>
      <?php endforeach; ?>
    </div>

  </div>
</section>

<!-- ── Sliders por marca ─────────────────────────────────── -->
<section class="brands-sliders-section">
  <div class="container" id="brands-sliders-wrap">

    <!-- Sliders SSR das primeiras marcas -->
    <?php foreach ($marcasInicial as $m): ?>
    <div class="brand-slider-block observe-up"
         data-marca-id="<?= (int)$m['id'] ?>"
         data-loaded="false">

      <div class="brand-slider-header">
        <div class="brand-slider-brand">
          <?php if (!empty($m['logo'])): ?>
          <div class="brand-slider-logo"
               style="background:<?= View::e($m['bg_cor'] ?? '#f8fafc') ?>">
            <img src="<?= View::upload('brands/' . $m['logo']) ?>"
                 alt="<?= View::e($m['nome']) ?>">
          </div>
          <?php endif; ?>
          <h2><?= View::e($m['nome']) ?></h2>
        </div>
        <a href="<?= BASE_URL ?>/marca/<?= View::e($m['slug']) ?>"
           class="brands-ver-tudo">
          Ver tudo
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <line x1="5" y1="12" x2="19" y2="12"/>
            <polyline points="12 5 19 12 12 19"/>
          </svg>
        </a>
      </div>

      <!-- Track do slider -->
      <div class="brand-products-slider-wrap">
        <button type="button" class="bps-nav bps-nav--prev" aria-label="Anterior">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <polyline points="15 18 9 12 15 6"/>
          </svg>
        </button>

        <div class="bps-overflow">
          <div class="bps-track" data-offset="0" data-total="0" data-loading="false">
            <!-- Skeleton enquanto carrega -->
            <?php for ($i = 0; $i < 5; $i++): ?>
            <div class="bps-skeleton"></div>
            <?php endfor; ?>
          </div>
        </div>

        <button type="button" class="bps-nav bps-nav--next" aria-label="Próximo">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <polyline points="9 18 15 12 9 6"/>
          </svg>
        </button>
      </div>

      <!-- Botão ver mais (aparece se >20 produtos) -->
      <div class="bps-ver-mais" style="display:none;">
        <a href="<?= BASE_URL ?>/marca/<?= View::e($m['slug']) ?>"
           class="btn btn-outline">
          Ver todos os produtos da <?= View::e($m['nome']) ?>
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <line x1="5" y1="12" x2="19" y2="12"/>
            <polyline points="12 5 19 12 12 19"/>
          </svg>
        </a>
      </div>

    </div>
    <?php endforeach; ?>

    <!-- Container para marcas carregadas via JS -->
    <div id="brands-sliders-dynamic"></div>

    <!-- Botão carregar mais marcas -->
    <?php if ($totalComProduto > 4): ?>
    <div class="brands-load-more-wrap" id="brands-load-more-wrap">
      <button type="button" class="btn btn-outline brands-load-more"
              id="btn-load-more-brands"
              data-offset="4">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
            stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <polyline points="7 10 12 15 17 10"/>
        </svg>
        Carregar mais marcas
        <span class="brands-load-more-count">
          +<?= $totalComProduto - 4 ?> marcas
        </span>
      </button>
    </div>
    <?php endif; ?>

  </div>
</section>

<script>
window.BRANDS_DATA = <?= json_encode($marcasJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>