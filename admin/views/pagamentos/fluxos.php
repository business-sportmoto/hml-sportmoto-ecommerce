<?php
// admin/views/pagamentos/fluxos.php
// $metodos e $porMetodo injetados pelo AdminPagamentoFluxoController

$badge = [
    'rascunho'  => ['Rascunho',  '#b45309', '#fffbeb'],
    'publicado' => ['Publicado', '#15803d', '#f0fdf4'],
    'arquivado' => ['Arquivado', '#64748b', '#f8fafc'],
];
?>


<style>
/* ── Tema ──────────────────────────────────────────────────────
   Aponta para o sistema pgto_ (pages.css), que ja define claro e escuro. */
.fx-page{
  --fx-sup:var(--pgto-surface);
  --fx-bd:var(--pgto-border);
  --fx-tx:var(--pgto-text);
  --fx-tx2:var(--pgto-text-muted);
  --fx-erro-bg:var(--pgto-red-soft);
  --fx-erro-bd:#dc26264d;
  --fx-erro-tx:var(--pgto-red);
}
/* Selos de status vem com cor inline do PHP; no escuro precisam de fundo
   proprio, senao um #f0fdf4 vira um bloco branco sobre a superficie escura. */
@media (prefers-color-scheme: dark){
  html:not([data-theme="light"]) .fx-page .admin-table td span[style],
  html:not([data-theme="light"]) .fx-page h3 + div span[style]{
    background:var(--pgto-surface-2) !important;filter:brightness(1.3)
  }
}
html[data-theme="dark"] .fx-page .admin-table td span[style]{
  background:var(--pgto-surface-2) !important;filter:brightness(1.3)
}

.fx-page .admin-card{background:var(--fx-sup);border-color:var(--fx-bd);
  transition:border-color .16s,box-shadow .16s}
.fx-page .admin-card:hover{border-color:var(--fx-bd);
  box-shadow:0 6px 20px -10px rgba(15,23,42,.16)}
.fx-page h3{color:var(--fx-tx)}
.fx-page code{color:var(--fx-tx2)}
.fx-page .admin-table th{color:var(--fx-tx2);border-color:var(--fx-bd)}
.fx-page .admin-table td{color:var(--fx-tx);border-color:var(--fx-bd);
  font-variant-numeric:tabular-nums}

.fx-sem-fluxo{margin-top:14px;padding:11px 14px;border-radius:8px;font-size:12.5px;
  line-height:1.5;background:var(--fx-erro-bg);border:1px solid var(--fx-erro-bd);
  color:var(--fx-erro-tx)}
</style>

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
            · <span style="color:#b45309;">forma desativada no checkout</span>
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
            [$lbl, $cor, $bg] = $badge[$f['status']] ?? ['—', '#64748b', '#f8fafc']; ?>
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
