<?php
// ════════════════════════════════════════════════════════
// views/partials/clips-home-section.php
// Vitrine horizontal "Clips em alta" — Cloudflare Stream
//
// CORREÇÕES APLICADAS:
//  1. Poster com FALLBACK (custom do R2 -> thumbnail do Stream).
//     Antes lia c.arquivo_poster cru: sem poster custom, o card ficava vazio.
//  2. N+1 ELIMINADO: contagem de produtos em UMA query agregada, não uma
//     query por clip dentro do loop (12 clips = 12 queries na HOME).
//  3. GIF de preview no hover (data-clip-preview), com carga sob demanda.
// ════════════════════════════════════════════════════════

$db = Database::getInstance()->getConnection();

// Uma única query: clips + contagem de produtos agregada (sem N+1)
$stmtClips = $db->query(
    "SELECT c.id, c.titulo, c.arquivo_poster, c.arquivo_video,
            c.total_views, c.total_likes, c.total_comentarios,
            COUNT(cp.produto_id) AS total_produtos
     FROM clips c
     LEFT JOIN clip_produtos cp ON cp.clip_id = c.id
     WHERE c.ativo = 1 AND c.status = 'ativo' AND c.destaque = 1
     GROUP BY c.id
     ORDER BY c.total_views DESC, c.ordem ASC
     LIMIT 12"
);
$clipsFeed = $stmtClips->fetchAll();

if (empty($clipsFeed)) return;

// Service para montar as URLs do Stream (0 chamadas de API — usa customer code)
$clipSvc = new ClipService();
?>

<section class="clips-home" id="clips-home-section">
  <div class="container">
    <div class="clips-home-header">
      <div class="clips-home-heading">
        <span class="clips-home-badge">
          <span class="clips-pulse"></span>
          Ao vivo
        </span>
        <h2 class="clips-home-title">Clips em alta</h2>
        <p class="clips-home-sub">Veja os vídeos mais assistidos</p>
      </div>
      <a href="<?= BASE_URL ?>/clips" class="clips-ver-todos">
        Ver todos
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <line x1="5" y1="12" x2="19" y2="12"/>
          <polyline points="12 5 19 12 12 19"/>
        </svg>
      </a>
    </div>

    <!-- Carrossel horizontal -->
    <div class="clips-carousel-wrap">
      <div class="clips-carousel" id="clips-carousel">
        <?php foreach ($clipsFeed as $i => $c): ?>
        <?php
          // ── Poster: custom (R2) OU thumbnail automático do vídeo (Stream) ──
          $poster = $clipSvc->posterFor($c);

          // ── GIF de preview para o hover (só se houver vídeo no Stream) ──
          $uid     = (string)($c['arquivo_video'] ?? '');
          $temUid  = (bool) preg_match('/^[a-f0-9]{32}$/i', $uid);
          $preview = $temUid ? $clipSvc->previewUrl($uid) : null;

          $proClip   = (int)($c['total_produtos'] ?? 0);
          $views_fmt = $c['total_views'] >= 1000
                     ? round($c['total_views'] / 1000, 1) . 'k'
                     : (string)$c['total_views'];
        ?>
        <div class="clip-card"
             data-clip-id="<?= (int)$c['id'] ?>"
             data-index="<?= $i ?>"
             <?php if ($preview): ?>data-clip-preview="<?= View::e($preview) ?>"<?php endif; ?>
             tabindex="0"
             role="button"
             aria-label="Ver clip: <?= View::e($c['titulo']) ?>">

          <!-- Thumbnail 9:16 -->
          <div class="clip-card-media">
            <?php if ($poster): ?>
            <img src="<?= View::e($poster) ?>"
                 alt="<?= View::e($c['titulo']) ?>"
                 loading="lazy"
                 class="clip-card-poster">
            <?php else: ?>
            <div class="clip-card-poster clip-card-poster--empty"></div>
            <?php endif; ?>

            <!-- Play button overlay -->
            <div class="clip-card-play">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="white">
                <polygon points="5 3 19 12 5 21 5 3"/>
              </svg>
            </div>

            <!-- Badge de produtos vinculados ao clip -->
            <?php if ($proClip > 0): ?>
            <div class="clip-card-produtos-badge"
                 title="<?= $proClip === 1 ? '1 produto neste clip' : $proClip . ' produtos neste clip' ?>">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                   stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/>
                <line x1="3" y1="6" x2="21" y2="6"/>
                <path d="M16 10a4 4 0 01-8 0"/>
              </svg>
              <span><?= $proClip ?></span>
            </div>
            <?php endif; ?>

            <!-- Views badge -->
            <div class="clip-card-views">
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                <circle cx="12" cy="12" r="3"/>
              </svg>
              <?= $views_fmt ?>
            </div>
          </div>

          <!-- Info abaixo -->
          <div class="clip-card-info">
            <p class="clip-card-titulo"><?= View::e(mb_substr($c['titulo'], 0, 50)) ?></p>
            <div class="clip-card-meta">
              <span>
                <svg width="10" height="10" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                  <path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/>
                </svg>
                <?= number_format((int)$c['total_likes']) ?>
              </span>
              <span>
                <svg width="10" height="10" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                  <path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>
                </svg>
                <?= number_format((int)$c['total_comentarios']) ?>
              </span>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Setas do carrossel (desktop) -->
      <button class="clips-carousel-arrow clips-carousel-arrow--prev" id="clips-prev" aria-label="Anterior">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <polyline points="15 18 9 12 15 6"/>
        </svg>
      </button>
      <button class="clips-carousel-arrow clips-carousel-arrow--next" id="clips-next" aria-label="Próximo">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <polyline points="9 18 15 12 9 6"/>
        </svg>
      </button>
    </div>
  </div>
</section>