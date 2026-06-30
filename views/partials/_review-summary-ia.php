<?php
// ════════════════════════════════════════════════════════
// views/partials/_review-summary-ia.php
// Card do resumo IA — carregado via AJAX após o render
//
// Incluir na página do produto, ANTES da seção de avaliações:
//   View::partial('partials/_review-summary-ia', ['produto_id' => $product['id']])
// ════════════════════════════════════════════════════════
if (empty($produto_id)) return;
?>

<div class="rsum-wrap" id="rsum-wrap" data-produto="<?= (int)$produto_id ?>" hidden>
  <div class="rsum-card">

    <!-- Header -->
    <div class="rsum-header">
      <div class="rsum-ia-badge">
        <!-- Ícone sparkle / IA -->
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M12 3l1.5 4.5L18 9l-4.5 1.5L12 15l-1.5-4.5L6 9l4.5-1.5z"/>
          <path d="M19 3l.8 2.2L22 6l-2.2.8L19 9l-.8-2.2L16 6l2.2-.8z" opacity=".6"/>
          <path d="M5 17l.5 1.5L7 19l-1.5.5L5 21l-.5-1.5L3 19l1.5-.5z" opacity=".5"/>
        </svg>
        IA
      </div>
      <h4 class="rsum-title">O que os clientes estão dizendo</h4>
    </div>

    <!-- Skeleton loader (visível durante o fetch) -->
    <div class="rsum-skeleton" id="rsum-skeleton">
      <div class="rsum-skel-line rsum-skel-line--w90"></div>
      <div class="rsum-skel-line rsum-skel-line--w70"></div>
      <div class="rsum-skel-line rsum-skel-line--w50"></div>
    </div>

    <!-- Texto do resumo (preenchido via JS) -->
    <p class="rsum-text" id="rsum-text" hidden></p>

    <!-- Rodapé -->
    <div class="rsum-footer" id="rsum-footer" hidden>
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2" stroke-linecap="round">
        <circle cx="12" cy="12" r="10"/>
        <line x1="12" y1="8" x2="12" y2="12"/>
        <line x1="12" y1="16" x2="12.01" y2="16"/>
      </svg>
      <span id="rsum-footer-text"></span>
    </div>

  </div>
</div>

