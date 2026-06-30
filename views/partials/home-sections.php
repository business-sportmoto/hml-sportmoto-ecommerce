<?php
// ════════════════════════════════════════════════════════
// views/partials/home-sections.php
// ════════════════════════════════════════════════════════
if (empty($sections)) return;

// ID único do slider — deve ser o mesmo no data-slider E no id do track
$gridId = $sl_container ?? 'destaque';

$menu_grid = $menu_grid ?? null;
?>

<section class="section products-section" id="section-<?= View::e($gridId) ?>">
  <div class="container">
    <div class="section-header">
      <div>
        <?php if (!empty($sections['badge'])): ?>
        <span class="section-eyebrow"><?= View::e($sections['badge']) ?></span>
        <?php endif; ?>
        <h2 class="section-title"><?= View::e($sections['titulo']) ?></h2>
        <?php if (!empty($sections['subtitulo'])): ?>
        <div class="section-subtitle"><?= View::e($sections['subtitulo']) ?></div>
        <?php endif; ?>
      </div>
      <?php if (!empty($sections['ver_mais_url'])): ?>
      <a href="<?= View::e($sections['ver_mais_url']) ?>" class="section-link">
        Ver todos
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <line x1="5" y1="12" x2="19" y2="12"/>
          <polyline points="12 5 19 12 12 19"/>
        </svg>
      </a>
      <?php endif; ?>
    </div>

    <?php if(!is_null($menu_grid)){ ?>
    <div class="products-tabs" id="best-seller-tabs">
      <button class="products-tab active" data-target="grid-<?= View::e($gridId) ?>" data-categoria="all">Todos</button>
      <?php foreach (array_slice($menu_grid, 0, 4) as $cat): ?>
        <button class="products-tab" data-categoria="<?= (int)$cat['id'] ?>"
                data-target="grid-<?= View::e($gridId) ?>">
          <?= View::e($cat['nome']) ?>
        </button>
      <?php endforeach; ?>
    </div>
    <?php } ?>

    <div class="slider-wrap" data-slider="grid-<?= View::e($gridId) ?>">

      <button class="slider-btn slider-btn--prev" aria-label="Anterior">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <polyline points="15 18 9 12 15 6"/>
        </svg>
      </button>

      <div class="slider-viewport">
        <!-- data-slider e id usam o mesmo $gridId — obrigatório para o JS funcionar -->
        <div class="products-grid products-grid--5 slider-track" id="grid-<?= View::e($gridId) ?>">
          <?php foreach ($sections['produtos'] as $product): ?>
            <?php View::partial('partials/product-card', ['product' => $product, 'stock_require'=> new EstoqueService()]) ?>
          <?php endforeach; ?>
        </div>
      </div>

      <button class="slider-btn slider-btn--next" aria-label="Próximo">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <polyline points="9 18 15 12 9 6"/>
        </svg>
      </button>

    </div>
  </div>
</section>