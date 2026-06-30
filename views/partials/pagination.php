<?php
// views/partials/pagination.php
// Componente de paginação semântico e acessível.
// Uso: View::partial('partials/pagination', ['pagination' => $pag, 'current_page' => $p])
if (empty($pagination) || !$pagination->hasPages()) return;
?>
<nav class="pagination-wrap" aria-label="Navegação de páginas" role="navigation">
  <ol class="pagination-list">

    <?php if ($pagination->previousPage()): ?>
    <li>
      <a href="<?= View::e($pagination->url($pagination->previousPage())) ?>"
         class="page-btn page-btn--prev" aria-label="Página anterior" rel="prev">
        &lsaquo;
      </a>
    </li>
    <?php endif; ?>

    <?php foreach ($pagination->pages() as $p): ?>
    <li>
      <?php if ($p === '...'): ?>
        <span class="page-ellipsis" aria-hidden="true">&hellip;</span>
      <?php else: ?>
        <a href="<?= View::e($pagination->url($p)) ?>"
           class="page-btn <?= $p === $current_page ? 'active' : '' ?>"
           <?= $p === $current_page ? 'aria-current="page"' : '' ?>
           aria-label="Página <?= $p ?>">
          <?= $p ?>
        </a>
      <?php endif; ?>
    </li>
    <?php endforeach; ?>

    <?php if ($pagination->nextPage()): ?>
    <li>
      <a href="<?= View::e($pagination->url($pagination->nextPage())) ?>"
         class="page-btn page-btn--next" aria-label="Próxima página" rel="next">
        &rsaquo;
      </a>
    </li>
    <?php endif; ?>

  </ol>
</nav>