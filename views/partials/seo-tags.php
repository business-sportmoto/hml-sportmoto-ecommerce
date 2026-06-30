<?php
// views/partials/seo-tags.php
// Incluir dentro do <head> no layout main.php
?>
<meta charset="UTF-8">
<?= SeoHelper::render() ?>
<meta name="theme-color" content="#e63946">
<meta name="format-detection" content="telephone=no">
<link rel="icon"             href="<?= View::asset('images/favicon.ico') ?>" type="image/x-icon">
<link rel="icon"             href="<?= View::asset('images/favicon.svg') ?>" type="image/svg+xml">
<link rel="apple-touch-icon" href="<?= View::asset('images/apple-touch-icon.png') ?>">
<link rel="manifest"         href="<?= BASE_URL ?>/manifest.json">
<?= PerformanceHelper::preload(View::asset('css/main.css'), 'style') ?>
<?= PerformanceHelper::preload('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap', 'style') ?>