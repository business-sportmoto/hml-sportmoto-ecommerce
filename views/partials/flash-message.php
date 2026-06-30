<?php
// views/partials/flash-message.php
// Exibe mensagens de sucesso, erro, info e aviso salvas na sessão.
// Incluir logo após o <body> no layout.
?>
<?php
$types = ['success' => 'Sucesso', 'error' => 'Erro', 'info' => 'Informação', 'warning' => 'Atenção'];
foreach ($types as $type => $label):
    if (!Session::hasFlash($type)) continue;
    $msg = Session::getFlash($type);
?>
<div class="flash-message flash-<?= $type ?>" data-flash="<?= $type ?>">
    <span class="flash-icon"></span>
    <span class="flash-text"><?= View::e($msg) ?></span>
    <button class="flash-close" aria-label="Fechar">×</button>
</div>
<?php endforeach; ?>