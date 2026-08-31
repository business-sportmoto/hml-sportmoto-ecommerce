<?php
// admin/views/pagamentos/fluxos.php
// $metodos e $porMetodo injetados pelo AdminPagamentoFluxoController

$badge = [
    'rascunho'  => ['Rascunho',  'var(--warning)', 'var(--warning-lt)'],
    'publicado' => ['Publicado', 'var(--success)', 'var(--success-lt)'],
    'arquivado' => ['Arquivado', 'var(--text-2)', 'var(--bg)'],
];
?>

<div class="admin-page fx-page">

  <div class="admin-page-header">
    <div>
      <a href="<?= ADMIN_URL ?>/pagamentos/formas" class="back-link">← Formas de pagamento</a>
      <h1 class="admin-page-title">Fluxos de pagamento</h1>
      <p class="admin-page-sub">
        Um fluxo por forma de pagamento decide qual adquirente é tentada, em que ordem,
        e o que fazer quando alguma falha. Publicar cria uma versão nova — a que está no
        ar nunca é editada por baixo.
      </p>
    </div>
  </div>

  <?php foreach ($metodos as $m):
      $lista     = $porMetodo[$m['codigo']] ?? [];
      $publicado = null;
      $rascunho  = null;
      foreach ($lista as $f) {
          if ($f['status'] === 'publicado' && !$publicado) $publicado = $f;
          if ($f['status'] === 'rascunho'  && !$rascunho)  $rascunho  = $f;
      }
  ?>
  <div class="admin-card" style="margin-bottom:16px;padding:20px;">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
      <div>
        <h3 style="margin:0 0 4px;font-size:15px;"><?= View::e($m['nome']) ?></h3>
        <div style="font-size:11.5px;color:var(--c-text-muted);">
          código <code><?= View::e($m['codigo']) ?></code>
          <?php if (!$m['ativo']): ?>
            · <span style="color:var(--warning);">forma desativada no checkout</span>
          <?php endif; ?>
        </div>
      </div>

      <div style="display:flex;gap:8px;align-items:center;">
        <?php if ($rascunho): ?>
          <a href="<?= ADMIN_URL ?>/pagamentos/fluxos/editor?id=<?= (int) $rascunho['id'] ?>"
             class="btn btn-primary">Continuar rascunho (v<?= (int) $rascunho['versao'] ?>)</a>
        <?php elseif ($publicado): ?>
          <button type="button" class="btn btn-outline btn-rascunho"
                  data-metodo="<?= View::e($m['codigo']) ?>">Criar rascunho</button>
        <?php else: ?>
          <a href="<?= ADMIN_URL ?>/pagamentos/fluxos/editor?metodo=<?= urlencode($m['codigo']) ?>"
             class="btn btn-primary">Criar fluxo</a>
        <?php endif; ?>

        <?php if ($publicado): ?>
          <a href="<?= ADMIN_URL ?>/pagamentos/fluxos/editor?id=<?= (int) $publicado['id'] ?>"
             class="btn btn-outline">Ver publicado</a>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!$publicado): ?>
      <div class="fx-sem-fluxo">
        <strong>Sem fluxo publicado.</strong> Pagamentos nesta forma são recusados —
        o motor não escolhe adquirente por conta própria.
      </div>
    <?php endif; ?>

    <?php if ($lista): ?>
    <table class="admin-table" style="margin-top:14px;">
      <thead>
        <tr><th>Versão</th><th>Status</th><th>Nós</th><th>Publicado em</th></tr>
      </thead>
      <tbody>
        <?php foreach (array_slice($lista, 0, 5) as $f):
            [$lbl, $cor, $bg] = $badge[$f['status']] ?? ['—', 'var(--text-2)', 'var(--bg)']; ?>
        <tr>
          <td>v<?= (int) $f['versao'] ?></td>
          <td><span style="background:<?= $bg ?>;color:<?= $cor ?>;padding:2px 8px;
                     border-radius:20px;font-size:11px;font-weight:700;"><?= $lbl ?></span></td>
          <td><?= (int) $f['total_nos'] ?></td>
          <td style="font-size:12px;color:var(--c-text-muted);">
            <?= $f['publicado_em'] ? date('d/m/Y H:i', strtotime($f['publicado_em'])) : '—' ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>

</div>

<script>
document.querySelectorAll('.btn-rascunho').forEach(function (b) {
  b.addEventListener('click', function () {
    b.disabled = true;
    var fd = new FormData();
    fd.append('_csrf_token', '<?= SecurityHelper::generateCsrf() ?>');
    fd.append('metodo', b.getAttribute('data-metodo'));
    fetch('<?= ADMIN_URL ?>/pagamentos/fluxos/rascunho', {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (r) { return r.json(); }).then(function (res) {
      if (res.ok) location.href = '<?= ADMIN_URL ?>/pagamentos/fluxos/editor?id=' + res.id;
      else { b.disabled = false; if (window.Toast) Toast.error(res.msg); }
    }).catch(function () { b.disabled = false; });
  });
});
</script>
