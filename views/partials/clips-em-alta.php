<?php // views/partials/clips-em-alta.php
// Dados mockados — backend será implementado depois
$clips = [
    ['titulo' => 'Review: CB 500 2025',          'canal' => '@motovlog',   'views' => '128k', 'img' => null],
    ['titulo' => 'Como instalar escapamento esportivo', 'canal' => '@oficinarpro','views' => '89k',  'img' => null],
    ['titulo' => 'Top 5 capacetes premium',       'canal' => '@ridelife',   'views' => '210k', 'img' => null],
    ['titulo' => 'Jaqueta de couro: vale a pena?','canal' => '@bikerstyle', 'views' => '67k',  'img' => null],
    ['titulo' => 'LED ou Xenon? Comparativo',     'canal' => '@motoluz',    'views' => '156k', 'img' => null],
    ['titulo' => 'Brembo na trilha: teste real',  'canal' => '@offroad',    'views' => '98k',  'img' => null],
];

// Gradientes para os cards sem imagem
$gradients = [
    'linear-gradient(135deg, #1a1a2e 0%, #16213e 100%)',
    'linear-gradient(135deg, #0f0f23 0%, #1a1040 100%)',
    'linear-gradient(135deg, #2d1b00 0%, #3d2800 100%)',
    'linear-gradient(135deg, #0d1b2a 0%, #1b2838 100%)',
    'linear-gradient(135deg, #1a0a00 0%, #2d1200 100%)',
    'linear-gradient(135deg, #0a1628 0%, #162238 100%)',
];
?>

<section class="clips-section">
  <div class="container">

    <div class="clips-header">
      <div>
        <div class="clips-eyebrow">
          <?= IconLibrary::render('videocam', 'icon icon--md') ?>
          MOTOTV
        </div>
        <h2 class="clips-title">Clips em alta</h2>
        <p class="clips-sub">Reviews, dicas e bastidores do mundo das motos.</p>
      </div>
      <a href="<?= BASE_URL ?>/mototv" class="clips-ver-tudo">
        Ver tudo
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <line x1="5" y1="12" x2="19" y2="12"/>
          <polyline points="12 5 19 12 12 19"/>
        </svg>
      </a>
    </div>

    <div class="clips-grid">
      <?php foreach ($clips as $i => $clip): ?>
      <a href="<?= BASE_URL ?>/mototv/clip" class="clip-card">

        <!-- Thumb -->
        <div class="clip-card-thumb"
             style="background:<?= $gradients[$i % count($gradients)] ?>">

          <?php if (!empty($clip['img'])): ?>
          <img src="<?= View::e($clip['img']) ?>"
               alt="<?= View::e($clip['titulo']) ?>"
               loading="lazy">
          <?php else: ?>
          <!-- Placeholder decorativo -->
          <div class="clip-card-placeholder">
             <?= IconLibrary::render('arrow-forward', 'icon icon--md') ?>
          </div>
          <?php endif; ?>

          <!-- Badge de views -->
          <span class="clip-card-views"><?= View::e($clip['views']) ?></span>

          <!-- Overlay gradient -->
          <div class="clip-card-overlay"></div>

          <!-- Info sobre a imagem -->
          <div class="clip-card-info">
            <span class="clip-card-titulo"><?= View::e($clip['titulo']) ?></span>
            <span class="clip-card-canal"><?= View::e($clip['canal']) ?></span>
          </div>

        </div>

      </a>
      <?php endforeach; ?>
    </div>

  </div>
</section>