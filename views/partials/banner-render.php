<?php
/**
 * Uso:
 * View::partial('partials/banner-render', ['zona' => 'home_hero']);
 */

if (empty($zona)) return;

$bannerModel = new Banner();
$banners     = $bannerModel->getByZona($zona);

if (empty($banners)) return;

// Detecta formato da zona pelo primeiro item
$formato     = $banners[0]['formato'] ?? 'single';
$width     = $banners[0]['largura_ideal'].'px' ?? 'auto';
$height     = $banners[0]['altura_ideal'].'px' ?? 'auto';
$maxBanners  = (int)($banners[0]['max_banners'] ?? 1);

// Registra impressão (assíncrono via JS no frontend)
$banner_ids  = array_column($banners, 'id');
?>

<?php if ($formato === 'slider'): ?>

<div class="bn-slider"
     data-banner-zona="<?= View::e($zona) ?>"
     data-banner-ids='<?= json_encode($banner_ids) ?>'>
  <div class="bn-slider-track">
    <?php foreach ($banners as $i => $b): ?>
      <?php View::partial('partials/banner-item', ['b' => $b, 'index' => $i, 'total' => count($banners)]) ?>
    <?php endforeach; ?>
  </div>

  <?php if (count($banners) > 1): ?>
  <div class="bn-slider-dots">
    <?php foreach ($banners as $i => $_): ?>
    <button type="button" class="bn-slider-dot <?= $i === 0 ? 'is-active' : '' ?>"
            data-slide="<?= $i ?>" aria-label="Banner <?= $i + 1 ?>"></button>
    <?php endforeach; ?>
  </div>

  <button class="bn-slider-arrow bn-slider-arrow--prev" aria-label="Anterior">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
      <polyline points="15 18 9 12 15 6"/>
    </svg>
  </button>
  <button class="bn-slider-arrow bn-slider-arrow--next" aria-label="Próximo">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
      <polyline points="9 18 15 12 9 6"/>
    </svg>
  </button>
  <?php endif; ?>
</div>

<?php elseif ($formato === 'grid'): ?>

<div class="bn-grid bn-grid--cols-<?= count($banners) ?>"
     data-banner-zona="<?= View::e($zona) ?>"
     data-banner-ids='<?= json_encode($banner_ids) ?>'
     style="
     <?php 
      echo 'width: '.$width.'; max-width: 100%;';
      echo 'height: '.$height.';';
    ?>">
  <?php foreach ($banners as $b): ?>
    <?php View::partial('partials/banner-item', ['b' => $b]) ?>
  <?php endforeach; ?>
</div>

<?php else: /* single ou full_width */ ?>

<div class="bn-single <?= $formato === 'full_width' ? 'bn-single--full' : '' ?>"
     data-banner-zona="<?= View::e($zona) ?>"
     data-banner-ids='<?= json_encode($banner_ids) ?>'
     style="
    <?php 
      echo 'width: '.$width.'; max-width: 100%;';
      echo 'height: '.$height.';';
    ?>
     ">
  <?php View::partial('partials/banner-item', ['b' => $banners[0]]) ?>
</div>

<?php endif; ?>
