<?php
// views/layouts/minimal.php
// Layout limpo para login, cadastro, checkout e páginas sem nav.
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <?php View::partial('partials/seo-tags') ?>
  <?php View::partial('partials/pwa-meta'); ?>
  <link rel="stylesheet" href="<?= PerformanceHelper::assetVersion('css/main.css') ?>">
  <link rel="stylesheet" href="<?= PerformanceHelper::assetVersion('css/pwa-native.css') ?>">
  <link rel="stylesheet" href="<?= PerformanceHelper::assetVersion('css/toast.css') ?>">
</head>
 
    <?= $content ?>
    <script>
      const BASE_URL   = '<?= BASE_URL ?>';
      const UPLOAD_URL = '<?= UPLOAD_URL ?>';
      const CSRF_TOKEN = '<?= $csrf_token ?? '' ?>';
    </script>
    <script src="<?= PerformanceHelper::assetVersion('js/jquery.min.js') ?>"></script>
    <script src="<?= PerformanceHelper::assetVersion('js/mask.js') ?>"></script>
    <script src="<?= PerformanceHelper::assetVersion('js/main.js') ?>"></script>
    <script src="<?= PerformanceHelper::assetVersion('js/auth.js') ?>"></script>
    <script src="<?= PerformanceHelper::assetVersion('js/pwa-core.js') ?>"></script>
    <script src="<?= PerformanceHelper::assetVersion('js/toast.js') ?>"></script>
    
</html>