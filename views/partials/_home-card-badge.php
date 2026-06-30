<?php
// ════════════════════════════════════════════════════════
// views/partials/_home-card-badge.php
// Badge de quantidade de produtos para cards da home
//
// Parâmetros:
//   $count   int     Quantidade de produtos        (obrigatório)
//   $label   string  'produtos' (singular/plural automático)
//   $url     string  Link ao clicar no badge        (opcional)
//   $variant string  '' | 'solid' | 'blue'          (opcional)
//   $icon    bool    true = exibe ícone de tag       (opcional)
//
// Exemplos de uso:
//
//   <!-- No card de categoria da home -->
//   <?php View::partial('partials/_home-card-badge', [
//       'count' => $categoria['total_produtos'],
//       'url'   => BASE_URL . '/categoria/' . $categoria['slug'],
//   ]) 
//
//   <!-- Badge sólido -->
//   <?php View::partial('partials/_home-card-badge', [
//       'count'   => 48,
//       'variant' => 'solid',
//       'label'   => 'itens',
//   ]) 
// ════════════════════════════════════════════════════════

$count   = (int)($count   ?? 0);
$label   = $label   ?? null;      // null = automático
$url     = $url     ?? null;
$variant = $variant ?? '';
$icon    = (bool)($icon   ?? true);
 
if ($count === 0) return;
 
// Label automático
if ($label === null) {
    $label = $count === 1 ? 'produto' : 'produtos';
}
 
// Formata número grande
$countFmt = $count >= 1000
    ? number_format($count / 1000, 1, ',', '') . 'k'
    : (string)$count;
 
$cls = 'home-card-product-badge';
if ($variant) $cls .= ' home-card-product-badge--' . $variant;
 
$tag  = $url ? 'a' : 'span';
$href = $url ? ' href="' . View::e($url) . '"' : '';
?>
 
<<?= $tag ?><?= $href ?> class="<?= $cls ?>" aria-label="<?= $countFmt ?> <?= View::e($label) ?>">
 
  <?php if ($icon): ?>
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
       stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
    <path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/>
    <line x1="7" y1="7" x2="7.01" y2="7"/>
  </svg>
  <?php endif; ?>
 
  <span class="home-card-product-badge__count"><?= $countFmt ?></span>
  <span><?= View::e($label) ?></span>
 
</<?= $tag ?>>