<?php
$beneficios = false;//CacheHelper::get('benefits_slider');

if (!$beneficios) {
    $stmt = Database::getInstance()->getConnection()->query(
        "SELECT icone, titulo, descricao, link, css_classe
         FROM beneficios_slider
         WHERE ativo = 1
         ORDER BY ordem ASC"
    );
    $beneficios = $stmt->fetchAll();
    CacheHelper::set('benefits_slider', $beneficios, 3600);
}

$icons = [
    'truck'   => '<path d="M1 3h15v13H1zm15 5h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
    'shield'  => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
    'credit'  => '<rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>',
    'headset' => '<path d="M3 18v-6a9 9 0 0118 0v6"/><path d="M21 19a2 2 0 01-2 2h-1a2 2 0 01-2-2v-3a2 2 0 012-2h3z"/><path d="M3 19a2 2 0 002 2h1a2 2 0 002-2v-3a2 2 0 00-2-2H3z"/>',
    'star'    => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
    'gift'    => '<polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 010-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 000-5C13 2 12 7 12 7z"/>',
    'tag'     => '<path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>',
    'refresh' => '<polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/>',
    'favorite' => '<svg xmlns="http://www.w3.org/2000/svg" height="20px" viewBox="0 -960 960 960" width="20px" fill="#e3e3e3"><path d="m480-144-50-45q-100-89-165-152.5t-102.5-113Q125-504 110.5-545T96-629q0-89 61-150t150-61q49 0 95 21t78 59q32-38 78-59t95-21q89 0 150 61t61 150q0 43-14 83t-51.5 89q-37.5 49-103 113.5T528-187l-48 43Zm0-97q93-83 153-141.5t95.5-102Q764-528 778-562t14-67q0-59-40-99t-99-40q-35 0-65.5 14.5T535-713l-35 41h-40l-35-41q-22-26-53.5-40.5T307-768q-59 0-99 40t-40 99q0 33 13 65.5t47.5 75.5q34.5 43 95 102T480-241Zm0-264Z"/></svg>',
];
?>

<?php if (!empty($beneficios)): ?>
<section class="benefits-section">
  <div class="container">
    <div class="benefits-slider-wrap" id="benefitsSlider">

      <button type="button" class="benefits-nav benefits-nav--prev"
              id="benefitsPrev" aria-label="Anterior">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <polyline points="15 18 9 12 15 6"/>
        </svg>
      </button>

      <div class="benefits-overflow">
        <div class="benefits-track" id="benefitsTrack">
          <?php foreach ($beneficios as $b):
            $iconPath  = $icons[$b['icone']] ?? $icons['star'];
            $hasLink   = !empty($b['link']);
            $extraClass = !empty($b['css_classe'])
                          ? ' ' . htmlspecialchars($b['css_classe'], ENT_QUOTES)
                          : '';
            // Tag dinâmica: <a> se tiver link, <div> se não tiver
            $tag    = $hasLink ? 'a' : 'div';
            $attrs  = $hasLink
                      ? ' href="' . View::e($b['link']) . '"'
                      : '';
          ?>
          <<?= $tag ?><?= $attrs ?>
             class="benefit-card<?= $extraClass ?>"
             <?= $hasLink ? 'tabindex="0"' : '' ?>>
            <div class="benefit-icon">
              <?= IconLibrary::render($b['icone'], 'icon icon--md') ?>
            </div>
            <div class="benefit-text">
              <h4><?= View::e($b['titulo']) ?></h4>
              <p><?= View::e($b['descricao']) ?></p>
            </div>
            <?php if ($hasLink): ?>
            <svg class="benefit-arrow" width="14" height="14" viewBox="0 0 24 24"
                 fill="none" stroke="currentColor" stroke-width="2.5"
                 stroke-linecap="round">
              <polyline points="9 18 15 12 9 6"/>
            </svg>
            <?php endif; ?>
          </<?= $tag ?>>
          <?php endforeach; ?>
        </div>
      </div>

      <button type="button" class="benefits-nav benefits-nav--next"
              id="benefitsNext" aria-label="Próximo">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <polyline points="9 18 15 12 9 6"/>
        </svg>
      </button>

    </div>
    <div class="benefits-dots" id="benefitsDots"></div>
  </div>
</section>
<?php endif; ?>