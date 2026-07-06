<?php
// views/admin/carrinhos-abandonados/templates/index.php
// Variáveis: $templates, $canal (filtro ativo|null), $salvo (bool)

$canalCfg = [
  'whatsapp' => ['💬 WhatsApp', '#16a34a', '#f0fdf4'],
  'email'    => ['✉ E-mail',   '#1d4ed8', '#eff6ff'],
];
?>
<div class="ap-page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div>
    <h1 style="font-size:22px;font-weight:800;margin:0;">Templates de recuperação</h1>
    <p style="color:var(--c-text-muted);margin:4px 0 0;font-size:13.5px;">
      Mensagens de WhatsApp e e-mail usadas na Central de Recuperação
    </p>
  </div>
  <div style="display:flex;gap:10px;">
    <a href="<?= ADMIN_URL ?>/carrinhos-abandonados" class="btn">← Central</a>
    <a href="<?= ADMIN_URL ?>/carrinhos-abandonados/templates/novo" class="btn btn-primary">+ Novo template</a>
  </div>
</div>

<?php if (!empty($salvo)): ?>
<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;
     padding:12px 16px;margin:14px 0;font-size:13.5px;color:#15803d;">
  ✓ Template salvo com sucesso.
</div>
<?php endif; ?>

<!-- Filtro por canal -->
<div style="display:flex;gap:8px;margin:16px 0;">
  <a href="<?= ADMIN_URL ?>/carrinhos-abandonados/templates" class="btn"
     style="<?= $canal === null ? 'background:var(--c-dark);color:#fff;' : '' ?>">Todos</a>
  <?php foreach ($canalCfg as $slug => [$label]): ?>
  <a href="<?= ADMIN_URL ?>/carrinhos-abandonados/templates?canal=<?= $slug ?>" class="btn"
     style="<?= $canal === $slug ? 'background:var(--c-dark);color:#fff;' : '' ?>"><?= $label ?></a>
  <?php endforeach; ?>
</div>

<?php if (empty($templates)): ?>
<div class="admin-card" style="padding:48px;text-align:center;color:var(--c-text-muted);">
  <div style="font-size:40px;margin-bottom:8px;">📄</div>
  <strong style="color:var(--c-dark);">Nenhum template neste filtro</strong>
  <p style="margin:6px 0 0;font-size:13.5px;">Crie o primeiro para agilizar o atendimento.</p>
</div>
<?php else: ?>

<div style="display:flex;flex-direction:column;gap:10px;">
<?php foreach ($templates as $t):
  [$cLabel, $cCor, $cBg] = $canalCfg[$t['canal']];
  $inativo = !(int)$t['ativo'];
?>
  <div class="admin-card" style="display:grid;grid-template-columns:auto 1fr auto auto;
       gap:16px;align-items:center;padding:14px 18px;<?= $inativo ? 'opacity:.55;' : '' ?>">

    <span class="badge" style="background:<?= $cBg ?>;color:<?= $cCor ?>;
          font-size:11px;font-weight:800;white-space:nowrap;"><?= $cLabel ?></span>

    <div style="min-width:0;">
      <div style="font-weight:700;font-size:14px;">
        <?= View::e($t['nome']) ?>
        <?php if ($t['canal'] === 'email' && $t['assunto']): ?>
          <span style="font-weight:400;color:var(--c-text-muted);font-size:12.5px;">
            — <?= View::e($t['assunto']) ?></span>
        <?php endif; ?>
      </div>
      <div style="font-size:12px;color:var(--c-text-muted);margin-top:3px;
                  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:560px;">
        <?= View::e(mb_substr(strip_tags($t['conteudo']), 0, 120)) ?>…
      </div>
      <?php if ($t['uso_recomendado']): ?>
      <div style="font-size:11.5px;color:#7c3aed;margin-top:3px;">
        💡 <?= View::e($t['uso_recomendado']) ?></div>
      <?php endif; ?>
    </div>

    <!-- Toggle ativo -->
    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:12px;
                  color:var(--c-text-muted);white-space:nowrap;">
      <input type="checkbox" class="tpl-toggle" data-id="<?= (int)$t['id'] ?>"
             <?= $inativo ? '' : 'checked' ?>>
      <?= $inativo ? 'Inativo' : 'Ativo' ?>
    </label>

    <div style="display:flex;gap:8px;">
      <a href="<?= ADMIN_URL ?>/carrinhos-abandonados/templates/<?= (int)$t['id'] ?>"
         class="btn" style="white-space:nowrap;">Editar</a>
      <button class="btn tpl-excluir" data-id="<?= (int)$t['id'] ?>"
              style="color:#dc2626;">Excluir</button>
    </div>
  </div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<script>
jQuery(function ($) {
  var BASE = '<?= ADMIN_URL ?>/carrinhos-abandonados/templates/';
  var CSRF = $('meta[name="csrf-token"]').attr('content');

  $('.tpl-toggle').on('change', function () {
    var id = $(this).data('id');
    $.post(BASE + id + '/toggle', { _csrf: CSRF }, null, 'json')
      .done(function (r) { r.ok ? location.reload() : alert(r.msg); })
      .fail(function () { alert('Erro de rede.'); });
  });

  $('.tpl-excluir').on('click', function () {
    if (!confirm('Excluir este template permanentemente?')) return;
    var id = $(this).data('id');
    $.post(BASE + id + '/excluir', { _csrf: CSRF }, null, 'json')
      .done(function (r) { r.ok ? location.reload() : alert(r.msg); })
      .fail(function () { alert('Erro de rede.'); });
  });
});
</script>