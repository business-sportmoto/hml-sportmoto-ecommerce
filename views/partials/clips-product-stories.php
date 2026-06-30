
<?php
// ════════════════════════════════════════════════════════
// views/partials/clips-product-stories.php
// Bolinhas estilo Instagram Story abaixo da galeria
// Uso: View::partial('partials/clips-product-stories', ['produto_id' => $product['id']])
// ════════════════════════════════════════════════════════
if (empty($produto_id)) return;

$db = Database::getInstance()->getConnection();
$stmt = $db->prepare(
    "SELECT c.id, c.titulo, c.arquivo_poster, c.arquivo_video,
            c.total_views, c.total_likes
     FROM clips c
     JOIN clip_produtos cp ON cp.clip_id = c.id
     WHERE cp.produto_id = ? AND c.ativo = 1 AND c.status = 'ativo'
     ORDER BY c.ordem ASC, c.total_views DESC
     LIMIT 8"
);
$stmt->execute([$produto_id]);
$clips = $stmt->fetchAll();

if (empty($clips)) return;
?>

<div class="product-clips-stories" id="product-clips-stories"
     data-produto-id="<?= (int)$produto_id ?>">

  <div class="product-clips-stories-label">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="2" stroke-linecap="round">
      <polygon points="23 7 16 12 23 17 23 7"/>
      <rect x="1" y="5" width="15" height="14" rx="2"/>
    </svg>
    Veja em vídeo
  </div>

  <div class="product-clips-stories-row" id="product-clips-stories-row">
    <?php foreach ($clips as $i => $c):
      $poster = $c['arquivo_poster']
              ? UPLOAD_URL . '/clips/posters/' . $c['arquivo_poster']
              : null;
      $viewsFmt = $c['total_views'] >= 1000
                ? round($c['total_views'] / 1000, 1) . 'k'
                : (string)(int)$c['total_views'];
    ?>
    <button type="button"
            class="product-story-circle"
            data-clip-id="<?= (int)$c['id'] ?>"
            data-idx="<?= $i ?>"
            aria-label="Ver clip: <?= View::e($c['titulo']) ?>">

      <!-- Anel animado (gradiente estilo Instagram) -->
      <div class="product-story-ring">
        <div class="product-story-thumb">
          <?php if ($poster): ?>
          <img src="<?= View::e($poster) ?>" alt="" loading="lazy">
          <?php else: ?>
          <div class="product-story-thumb-empty">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
              <polygon points="23 7 16 12 23 17 23 7"/>
              <rect x="1" y="5" width="15" height="14" rx="2"/>
            </svg>
          </div>
          <?php endif; ?>
          <!-- Play icon overlay -->
          <div class="product-story-play">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="white">
              <polygon points="5 3 19 12 5 21 5 3"/>
            </svg>
          </div>
        </div>
      </div>

      <!-- Label com views -->
      <span class="product-story-views">
        <svg width="9" height="9" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
          <circle cx="12" cy="12" r="3"/>
        </svg>
        <?= $viewsFmt ?>
      </span>

    </button>
    <?php endforeach; ?>
  </div>

</div>

<!-- Inclui o feed overlay se ainda não foi incluído -->
<?php if (!defined('CLIPS_FEED_OVERLAY_INCLUDED')): ?>
  <?php define('CLIPS_FEED_OVERLAY_INCLUDED', true); ?>
  <?php View::partial('partials/clips-feed-overlay') ?>
<?php endif; ?>

<script>
// Inicializa os stories deste produto quando ClipFeed estiver disponível

</script>