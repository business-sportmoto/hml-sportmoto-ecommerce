<?php
// ════════════════════════════════════════════════════════
// views/partials/_rating-badge.php
// Badge de avaliação reutilizável
//
// Parâmetros:
//   $media  float   Nota média (ex: 4.7)
//   $total  int     Quantidade de avaliações
//   $size   string  'xs' | 'sm' | 'md'   (default 'sm')
//   $link   string  URL âncora opcional   (default null)
//
// Tamanhos:
//   xs  — só ★ + nota          → cards compactos
//   sm  — ★ + nota + (N)       → cards normais
//   md  — ★★★★☆ + nota + N av. → topo da página do produto
//
// Uso:
//   View::partial('partials/_rating-badge', [
//       'media' => 4.7,
//       'total' => 128,
//       'size'  => 'sm',
//       'link'  => '#avaliacoes',
//   ])
// ════════════════════════════════════════════════════════
$media = round((float)($media ?? 0), 1);
$total = (int)($total ?? 0);
$size  = $size ?? 'sm';
$link  = $link ?? null;
$text  = $text ?? true;

if ($total === 0) return;

$mediaFmt  = number_format($media, 1, ',', '');
$totalFmt  = $total >= 1000
           ? number_format($total / 1000, 1, ',', '') . 'k'
           : (string)$total;
$ariaLabel = "Nota {$mediaFmt} de 5 — {$total} " . ($total === 1 ? 'avaliação' : 'avaliações');

$tag  = $link ? 'a' : 'span';
$href = $link ? ' href="' . htmlspecialchars($link, ENT_QUOTES) . '"' : '';
?>

<?php /* ── xs e sm: estrela única + nota ──────────────── */ ?>
<?php if ($size === 'xs' || $size === 'sm'): ?>
<<?= $tag ?><?= $href ?>
  class="rb rb--<?= $size ?>"
  aria-label="<?= $ariaLabel ?>">

  <svg class="rb__star" viewBox="0 0 24 24" aria-hidden="true" fill="currentColor">
    <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
  </svg>
  <?php if($text): ?>
  <span class="rb__score"><?= $mediaFmt ?></span>

  <?php if ($size === 'sm'): ?>
  <span class="rb__count">(<?= $totalFmt ?>)</span>
  <?php endif; ?>
  <?php endif; ?>

</<?= $tag ?>>

<?php /* ── md: fileira de 5 estrelas + nota + contagem ── */ ?>
<?php else: ?>
<?php
  // Calcula tipo de cada estrela: 'full' | 'half' | 'empty'
  $stars = [];
  for ($i = 0; $i < 5; $i++) {
      $val = $media - $i;
      if      ($val >= 0.75) $stars[] = 'full';
      elseif  ($val >= 0.25) $stars[] = 'half';
      else                   $stars[] = 'empty';
  }
  // ID único por instância (evita conflito de gradiente no SVG)
  $uid = 'rbg' . substr(md5($media . $total . microtime()), 0, 6);
?>
<<?= $tag ?><?= $href ?>
  class="rb rb--md"
  aria-label="<?= $ariaLabel ?>">

  <!-- Definição do gradiente para meia estrela (injetada uma vez) -->
  <svg width="0" height="0" style="position:absolute" aria-hidden="true">
    <defs>
      <linearGradient id="<?= $uid ?>">
        <stop offset="50%" stop-color="#f59e0b"/>
        <stop offset="50%" stop-color="#d1d5db"/>
      </linearGradient>
    </defs>
  </svg>

  <span class="rb__stars" aria-hidden="true">
    <?php foreach ($stars as $type): ?>
    <svg class="rb__star-icon" viewBox="0 0 24 24">
      <?php if ($type === 'full'): ?>
        <polygon fill="#f59e0b"
                 points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
      <?php elseif ($type === 'half'): ?>
        <polygon fill="url(#<?= $uid ?>)"
                 points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
      <?php else: ?>
        <polygon fill="#d1d5db"
                 points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
      <?php endif; ?>
    </svg>
    <?php endforeach; ?>
  </span>
  <?php if($text): ?>
  <span class="rb__score"><?= $mediaFmt ?></span>
  <span class="rb__sep" aria-hidden="true">·</span>
  <span class="rb__count">
    <?= $totalFmt ?> <?= $total === 1 ? 'avaliação' : 'avaliações' ?>
  </span>
  <?php endif; ?>

</<?= $tag ?>>
<?php endif; ?>