<?php
// views/layouts/auth.php
// Layout minimalista usado na página de login do admin.
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $pageTitle ?? 'Admin' ?></title>
  <link rel="stylesheet" href="<?= View::asset('css/admin.css') ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="admin-body">
  <?= $content ?>
</body>
</html>