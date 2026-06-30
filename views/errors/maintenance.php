<?php
// views/errors/maintenance.php
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Em manutenção — <?= htmlspecialchars(ConfigHelper::get('site_nome', 'Loja')) ?></title>
  <meta name="robots" content="noindex, nofollow">
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:system-ui,sans-serif;background:#1a1a2e;color:#fff;
         min-height:100vh;display:flex;align-items:center;justify-content:center;
         text-align:center;padding:32px 20px}
    .icon{font-size:64px;margin-bottom:20px}
    h1{font-size:28px;font-weight:800;margin-bottom:12px}
    p{color:rgba(255,255,255,.7);max-width:380px;line-height:1.7}
  </style>
</head>
<body>
  <div>
    <div class="icon">🔧</div>
    <h1>Estamos em manutenção</h1>
    <p>Nossa loja está temporariamente fora do ar para melhorias. Voltamos em breve!</p>
  </div>
</body>
</html>