<?php
$marcasDestaque = false;//CacheHelper::get('brands_destaque');
if (!$marcasDestaque) {
    $stmt = Database::getInstance()->getConnection()->query(
        "SELECT id, nome, slug, logo, bg_cor FROM marcas
         WHERE ativo = 1 AND destaque = 1
         ORDER BY nome ASC LIMIT 12"
    );
    $marcasDestaque = $stmt->fetchAll();
    CacheHelper::set('brands_destaque', $marcasDestaque, 3600);
}
if (empty($marcasDestaque)) return;
?>

<section class="brands-section">
  <div class="container">

    <div class="brands-section-header">
      <div>
        <span class="brands-eyebrow">Top Marcas</span>
        <h2 class="brands-title">Navegue por marcas</h2>
        <p class="brands-sub">
          Encontre peças e acessórios para sua marca favorita.
        </p>
      </div>
      <a href="<?= BASE_URL ?>/marcas" class="brands-ver-tudo">
        Ver tudo
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <line x1="5" y1="12" x2="19" y2="12"/>
          <polyline points="12 5 19 12 12 19"/>
        </svg>
      </a>
    </div>

    <div class="brands-grid">
      <?php foreach ($marcasDestaque as $b): ?>
      <a href="<?= BASE_URL ?>/marca/<?= View::e($b['slug']) ?>"
         class="brand-card" <?= !empty($b['bg_cor']) ? 'style="background-color:' . View::e($b['bg_cor']) . ';"' : '' ?>>
        <?php if (!empty($b['logo'])): ?>
        <img src="<?= View::upload('brands/' . $b['logo']) ?>"
             alt="<?= View::e($b['nome']) ?>"
             loading="lazy">
        <?php else: ?>
        <span class="brand-card-nome"><?= View::e(strtoupper($b['nome'])) ?></span>
        <?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>

  </div>
</section>