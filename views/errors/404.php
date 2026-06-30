<?php
// views/errors/404.php
http_response_code(404);
?>

<style>
  .error-page { min-height: 80vh; display: flex; align-items: center;
                justify-content: center; text-align: center; padding: 48px 20px; }
  .error-code { font-size: 100px; font-weight: 900; color: var(--c-primary);
                line-height: 1; margin-bottom: 16px; }
  .error-title { font-size: 24px; font-weight: 700; margin-bottom: 12px; }
  .error-desc { color: var(--c-text-muted); margin-bottom: 28px; max-width: 400px; }
  .error-actions { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
</style>

<main class="error-page main-content">
  <div>
    <div class="error-code">404</div>
    <h1 class="error-title">Página não encontrada</h1>
    <p class="error-desc">
      A página que você procura não existe, foi movida ou o endereço foi digitado incorretamente.
    </p>
    <div class="error-actions">
      <a href="<?= BASE_URL ?>" class="btn btn-primary">Ir para a home</a>
      <a href="<?= BASE_URL ?>/busca" class="btn btn-outline">Buscar produtos</a>
    </div>
  </div>
</main>

