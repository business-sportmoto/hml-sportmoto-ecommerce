<?php if (!empty($produto_id)): ?>
<div class="product-clips-section" id="product-clips-section"
     data-produto-id="<?= (int)$produto_id ?>">
  <h3 class="product-clips-title">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="2" stroke-linecap="round">
      <polygon points="23 7 16 12 23 17 23 7"/>
      <rect x="1" y="5" width="15" height="14" rx="2"/>
    </svg>
    Veja em vídeo
  </h3>
  <div class="product-clips-carousel">
    <!-- Populado via JS -->
  </div>
</div>
<?php endif; ?>

