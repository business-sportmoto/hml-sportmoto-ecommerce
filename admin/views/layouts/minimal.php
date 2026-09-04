<?php
// admin/views/layouts/minimal.php
//
// Layout enxuto para páginas de erro do painel (403, 404), onde não faz
// sentido carregar sidebar e topbar — quem chega aqui não tem permissão
// para o que a navegação ofereceria.
//
// Existe porque o AuthHelper::requireAdminLevel() e o requirePermission()
// chamam View::render('errors/403', [], 'minimal'). No painel a base de
// views é admin/views, e nem este layout nem a view existiam: TODA negação
// de permissão em navegação normal virava RuntimeException em vez de 403.
// O módulo de IA chegou a contornar isso localmente (ver
// IAConfigController::exigirPermissao) — com o layout no lugar, aquele
// contorno pode ser aposentado.
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex">
  <title><?= View::e($pageTitle ?? 'Sportmoto — Painel') ?></title>
  <link rel="stylesheet" href="<?= View::asset('css/admin.css') ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="admin-body">
  <?= $content ?>
</body>
</html>
