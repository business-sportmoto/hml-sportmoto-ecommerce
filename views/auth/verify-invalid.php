<?php
// views/auth/verify-invalid.php — layout 'minimal'
// Variáveis: $jaUsado (bool) — token existente porém já consumido
$jaUsado = !empty($jaUsado);
?>
<div style="min-height:70vh;display:flex;align-items:center;justify-content:center;padding:24px;">
  <div style="max-width:440px;width:100%;text-align:center;">

    <div style="width:72px;height:72px;margin:0 auto 20px;border-radius:50%;
                display:flex;align-items:center;justify-content:center;font-size:32px;
                background:<?= $jaUsado ? '#f0fdf4' : '#fef2f2' ?>;">
      <?= $jaUsado ? '✓' : '⚠️' ?>
    </div>

    <?php if ($jaUsado): ?>
      <h1 style="font-size:22px;font-weight:800;margin:0 0 10px;">
        Este e-mail já foi verificado
      </h1>
      <p style="color:var(--c-text-muted);font-size:14.5px;line-height:1.6;margin:0 0 24px;">
        Tudo certo com a sua conta — este link já tinha sido usado.
        É só entrar normalmente.
      </p>
      <a href="<?= BASE_URL ?>/login" class="btn btn-primary"
         style="display:inline-block;min-width:180px;">Fazer login</a>

    <?php else: ?>
      <h1 style="font-size:22px;font-weight:800;margin:0 0 10px;">
        Link de verificação inválido
      </h1>
      <p style="color:var(--c-text-muted);font-size:14.5px;line-height:1.6;margin:0 0 8px;">
        Este link não é válido. Isso costuma acontecer quando ele foi copiado
        pela metade ou substituído por um mais recente.
      </p>
      <p style="color:var(--c-text-muted);font-size:13.5px;line-height:1.6;margin:0 0 24px;">
        Se você pediu um novo e-mail de verificação, use sempre o
        <strong>mais recente</strong> da sua caixa de entrada.
      </p>

      <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
        <a href="<?= BASE_URL ?>/verificar-email" class="btn btn-primary"
           style="min-width:180px;">Reenviar verificação</a>
        <a href="<?= BASE_URL ?>/login" class="btn">Ir para o login</a>
      </div>
    <?php endif; ?>

    <p style="margin:28px 0 0;font-size:12.5px;color:var(--c-text-muted);">
      Precisa de ajuda? <a href="<?= BASE_URL ?>/ajuda"
         style="color:inherit;text-decoration:underline;">Fale com a gente</a>
    </p>
  </div>
</div>