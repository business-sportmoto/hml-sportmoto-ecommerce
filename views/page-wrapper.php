<?php
// views/page-wrapper.php
// Os assets chegam via $page_assets passado pelo controller
// e são mesclados pelo View::render() junto com o array de dados.
// Aqui apenas configuramos $extra_css e $extra_js para o layout usar.

if (!empty($page_assets['css'])) {
    $extra_css = is_array($page_assets['css']) ? $page_assets['css'] : [$page_assets['css']];
}
if (!empty($page_assets['js'])) {
    $extra_js = is_array($page_assets['js']) ? $page_assets['js'] : [$page_assets['js']];
}
?>
<?php
// views/page-wrapper.php
?>
<div class="custom-page custom-page--<?= View::e($page_slug) ?>">

  <?php if ($usa_breadcrumb && !empty($breadcrumb)): ?>
  <div class="container">
    <nav class="breadcrumb-nav" aria-label="Breadcrumb">
      <?php foreach ($breadcrumb as $i => $crumb): ?>
        <?php if ($i > 0): ?>
          <span class="breadcrumb-sep" aria-hidden="true">/</span>
        <?php endif; ?>
        <?php if (!empty($crumb['url']) && $i < count($breadcrumb) - 1): ?>
          <a href="<?= View::e($crumb['url']) ?>" class="breadcrumb-link">
            <?= View::e($crumb['label']) ?>
          </a>
        <?php else: ?>
          <span class="breadcrumb-current" aria-current="page">
            <?= View::e($crumb['label']) ?>
          </span>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
  </div>
  <?php endif; ?>
    
  <?php 
  // var_dump($extra_css); 
  // var_dump($extra_js); 
  echo $page_content;
  ?>

</div>