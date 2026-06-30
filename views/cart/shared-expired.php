<?php // views/cart/shared-expired.php ?>
<div class="container" style="padding: 80px 0; text-align: center;">
  <div style="max-width: 400px; margin: 0 auto;">
    <div style="font-size: 56px; margin-bottom: 16px;">⏰</div>
    <h1 style="font-size: 22px; font-weight: 700; margin-bottom: 10px; color: var(--c-dark);">
      Link expirado
    </h1>
    <p style="color: var(--c-text-muted); font-size: 15px; margin-bottom: 28px; line-height: 1.7;">
      Este carrinho compartilhado não está mais disponível.<br>
      Os links expiram após 7 dias.
    </p>
    <a href="<?= BASE_URL ?>/busca" class="btn btn-primary">
      Explorar produtos
    </a>
  </div>
</div>