<?php
// admin/views/auth/login.php
// Esta view inclui o HTML completo pois é renderizada sem layout.
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $pageTitle ?? 'Login — Admin' ?></title>
  <link rel="stylesheet" href="<?= View::asset('css/admin.css') ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet">
</head>
<body class="admin-body">

<div class="admin-auth-page">
  <div class="admin-auth-box">

    <div style="text-align:center; margin-bottom:20px;">
      <svg width="40" height="40" viewBox="0 0 24 24" fill="none"
           stroke="#e63946" stroke-width="2" stroke-linecap="round">
        <path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/>
      </svg>
    </div>
  
    <h1 class="admin-auth-title">Painel Administrativo</h1>
    <p class="admin-auth-sub">Entre com suas credenciais de acesso</p>

    <?php if (Session::hasFlash('error')): ?>
      <div class="admin-alert admin-alert--error">
        <?= htmlspecialchars(Session::getFlash('error'), ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <?php if (Session::hasFlash('success')): ?>
      <div class="admin-alert admin-alert--success">
        <?= htmlspecialchars(Session::getFlash('success'), ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <form method="POST" action="<?= ADMIN_URL ?>/login">
      <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">

      <div class="form-group" style="margin-bottom:16px;">
        <label for="email">E-mail</label>
        <input type="email" id="email" name="email" class="form-control"
               required autocomplete="email" autofocus
               value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES) ?>">
      </div>

      <div class="form-group" style="margin-bottom:24px;">
        <label for="senha">Senha</label>
        <input type="password" id="senha" name="senha" class="form-control"
               required autocomplete="current-password">
      </div>

      <button type="submit" class="admin-btn admin-btn--primary"
              style="width:100%; justify-content:center; height:44px;">
        Entrar no painel
      </button>
    </form>

    <p style="text-align:center; margin-top:20px;">
      <a href="<?= defined('BASE_URL') ? BASE_URL : '/' ?>"
         style="font-size:13px; color:rgba(255,255,255,.5);">
        ← Voltar para a loja
      </a>
    </p>

  </div>
</div>

</body>
</html>