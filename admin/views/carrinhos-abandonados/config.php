<?php
// views/admin/carrinhos-abandonados/config.php
// Variáveis: $config (valores vigentes), $schema (CONFIG_SCHEMA),
//            $salvo (bool), $erro (string|null)
?>
<div class="ap-page-header" style="display:flex;align-items:center;gap:14px;">
  <a href="<?= ADMIN_URL ?>/carrinhos-abandonados" class="btn">← Central</a>
  <div>
    <h1 style="font-size:20px;font-weight:800;margin:0;">Automação da recuperação</h1>
    <p style="color:var(--c-text-muted);margin:4px 0 0;font-size:13px;">
      Regras de detecção, sugestão de contato e anti-spam
    </p>
  </div>
</div>

<?php if (!empty($salvo)): ?>
<div style="background:var(--success-lt);border:1px solid var(--success-bd);border-radius:10px;
     padding:12px 16px;margin:14px 0;font-size:13.5px;color:var(--success);">
  ✓ Configuração salva. As novas regras valem a partir da próxima execução do cron (até 30 min).
</div>
<?php endif; ?>

<?php if (!empty($erro)): ?>
<div style="background:var(--danger-lt);border:1px solid var(--danger-bd);border-radius:10px;
     padding:12px 16px;margin:14px 0;font-size:13.5px;color:var(--danger);">
  ⚠ <?= View::e($erro) ?> Nenhum valor fora dos limites foi salvo.
</div>
<?php endif; ?>

<div style="background:var(--warning-lt);border:1px solid var(--warning-bd);border-radius:10px;
     padding:12px 16px;margin:14px 0;font-size:13px;color:var(--warning);">
  ⚠️ <strong>Impacto operacional:</strong> a <em>janela de abandono</em> e o
  <em>valor mínimo</em> controlam a detecção global — reduzir demais a janela
  pode marcar carrinhos de clientes ainda ativos e disparar contatos indevidos.
</div>

<form method="post" action="<?= ADMIN_URL ?>/carrinhos-abandonados/config">
  <?= SecurityHelper::csrfField() ?>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));
              gap:14px;margin-top:14px;">
    <?php foreach ($schema as $chave => $def): ?>
    <div class="admin-card" style="padding:16px 18px;">
      <label class="form-label" for="cfg-<?= $chave ?>"><?= View::e($def['label']) ?></label>
      <input type="number"
             id="cfg-<?= $chave ?>"
             name="<?= $chave ?>"
             class="form-control"
             value="<?= $def['step'] < 1
                 ? number_format((float)$config[$chave], 2, '.', '')
                 : (int)$config[$chave] ?>"
             min="<?= $def['min'] ?>"
             max="<?= $def['max'] ?>"
             step="<?= $def['step'] ?>"
             required>
      <span class="form-hint" style="display:block;margin-top:6px;">
        <?= View::e($def['hint']) ?>
        <span style="color:var(--c-text-muted);opacity:.7;">
          (<?= $def['min'] ?>–<?= $def['max'] ?>)</span>
      </span>
    </div>
    <?php endforeach; ?>
  </div>

  <div style="display:flex;gap:10px;margin-top:18px;">
    <button type="submit" class="btn btn-primary" style="min-width:180px;">
      Salvar configuração</button>
    <a href="<?= ADMIN_URL ?>/carrinhos-abandonados" class="btn">Cancelar</a>
  </div>
</form>