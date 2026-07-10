<?php
// views/admin/usuarios/vendas-detalhe.php
// Variáveis: $usuario, $resumo, $serie, $de, $ate
$labels = array_map(fn($r) => date('d/m', strtotime($r['dia'])), $serie);
$dados  = array_map(fn($r) => (float)$r['total'], $serie);
?>
<div class="ap-page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div style="display:flex;align-items:center;gap:14px;">
    <a href="<?= ADMIN_URL ?>/usuarios/vendas" class="btn">← Ranking</a>
    <div>
      <h1 style="font-size:20px;font-weight:800;margin:0;"><?= View::e($usuario['nome']) ?></h1>
      <span style="font-size:12.5px;color:var(--c-text-muted);font-family:ui-monospace,monospace;
            font-weight:700;letter-spacing:1px;"><?= View::e($usuario['codigo_vendedor']) ?></span>
    </div>
  </div>
  <form method="get" style="display:flex;gap:8px;align-items:center;">
    <input type="date" name="de"  value="<?= View::e($de)  ?>" class="form-control" style="width:auto;">
    <input type="date" name="ate" value="<?= View::e($ate) ?>" class="form-control" style="width:auto;">
    <button class="btn btn-primary">Aplicar</button>
  </form>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin:16px 0;">
  <div class="admin-card" style="padding:16px 18px;border-left:4px solid #16a34a;">
    <div style="font-size:12px;color:var(--c-text-muted);font-weight:600;text-transform:uppercase;letter-spacing:.4px;">Total vendido</div>
    <div style="font-size:24px;font-weight:800;color:#16a34a;margin-top:4px;">
      R$ <?= number_format((float)$resumo['total'], 2, ',', '.') ?></div>
  </div>
  <div class="admin-card" style="padding:16px 18px;border-left:4px solid #1d4ed8;">
    <div style="font-size:12px;color:var(--c-text-muted);font-weight:600;text-transform:uppercase;letter-spacing:.4px;">Pedidos</div>
    <div style="font-size:24px;font-weight:800;color:#1d4ed8;margin-top:4px;"><?= (int)$resumo['pedidos'] ?></div>
  </div>
  <div class="admin-card" style="padding:16px 18px;border-left:4px solid #7c3aed;">
    <div style="font-size:12px;color:var(--c-text-muted);font-weight:600;text-transform:uppercase;letter-spacing:.4px;">Ticket médio</div>
    <div style="font-size:24px;font-weight:800;color:#7c3aed;margin-top:4px;">
      R$ <?= number_format((float)$resumo['ticket'], 2, ',', '.') ?></div>
  </div>
</div>

<?php if (!empty($serie)): ?>
<div class="admin-card" style="padding:18px;">
  <h3 class="ap-card-title" style="margin-bottom:14px;">Vendas por dia</h3>
  <canvas id="chart-vendas" height="90"></canvas>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
jQuery(function () {
  new Chart(document.getElementById('chart-vendas'), {
    type: 'bar',
    data: {
      labels: <?= json_encode($labels) ?>,
      datasets: [{
        label: 'R$ vendidos',
        data: <?= json_encode($dados) ?>,
        backgroundColor: '#16a34a',
        borderRadius: 6
      }]
    },
    options: {
      plugins: { legend: { display: false } },
      scales: {
        y: { beginAtZero: true,
             ticks: { callback: function (v) { return 'R$ ' + v.toLocaleString('pt-BR'); } } }
      }
    }
  });
});
</script>
<?php else: ?>
<div class="admin-card" style="padding:48px;text-align:center;color:var(--c-text-muted);">
  <div style="font-size:40px;margin-bottom:8px;">📊</div>
  <strong style="color:var(--c-dark);">Sem vendas no período</strong>
</div>
<?php endif; ?>