<?php
/**
 * Layout de impressão térmica (bobina 80mm).
 *
 * Página autossuficiente de propósito: não carrega o CSS do painel, que é
 * escuro e cheio de regra de tela — numa térmica isso sairia como um borrão de
 * toner. Aqui só entra o necessário para o papel.
 *
 * O tema não se aplica: impressão é sempre preto no branco.
 */
?><!DOCTYPE html>
<html lang="pt-BR" data-theme="light">
<head>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars($page_title ?? 'Impressão', ENT_QUOTES) ?></title>
  <link rel="stylesheet" href="<?= PerformanceHelper::assetVersion('/css/impressao.css', true) ?>">
</head>
<body class="imp_body">
<?= $content ?>

<script>
  // Imprime sozinho ao abrir. O operador manda um lote e o dialogo ja aparece.
  // O timeout deixa o SVG do QR terminar o layout antes do snapshot de impressao.
  window.addEventListener('load', function () {
    if (document.querySelector('.imp_etiqueta')) {
      setTimeout(function () { window.print(); }, 250);
    }
  });
</script>
</body>
</html>
