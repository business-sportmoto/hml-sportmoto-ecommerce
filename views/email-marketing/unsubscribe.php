<?php
/** @var string $token */
/** @var array|null $contato */
/** @var bool $confirmado */
$base = defined('BASE_URL') ? BASE_URL : '';
$site = defined('SITE_NAME') ? SITE_NAME : 'SportMoto';
?>
<div style="max-width:560px;margin:60px auto;padding:32px;background:#fff;border-radius:8px;
            box-shadow:0 2px 12px rgba(0,0,0,.06);font-family:Arial,Helvetica,sans-serif;">
    <h1 style="margin:0 0 16px;font-size:22px;color:#222;">Descadastrar de emails</h1>

    <?php if (!$contato): ?>
        <p>O link utilizado é inválido ou expirou. Caso continue recebendo emails que não deseja, entre em contato com <?= htmlspecialchars($site) ?>.</p>
    <?php elseif ($confirmado): ?>
        <p>O email <strong><?= htmlspecialchars($contato['email']) ?></strong> foi removido da nossa lista de envios.</p>
        <p>Você pode levar até 24h para deixar de receber mensagens já em fila. Pedimos desculpas se algum email ainda chegar nesse intervalo.</p>
        <p><a href="<?= htmlspecialchars($base) ?>" style="color:#0a66c2;">Voltar ao site</a></p>
    <?php else: ?>
        <p>Confirme abaixo para descadastrar o email <strong><?= htmlspecialchars($contato['email']) ?></strong> de futuros envios do <?= htmlspecialchars($site) ?>.</p>
        <form method="post" action="<?= htmlspecialchars($base) ?>/email/descadastrar/<?= htmlspecialchars($token) ?>">
            <button type="submit" style="background: var(--c-primary);color:#fff;border:0;border-radius:4px;padding:12px 24px;cursor:pointer;font-size:14px;">
                Confirmar descadastro
            </button>
        </form>
        <p style="font-size:12px;color:#666;margin-top:24px;">
            Se você não solicitou isso, basta fechar esta página.
        </p>
    <?php endif; ?>
</div>
