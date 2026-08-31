<?php
// views/admin/usuarios/vendas.php — ranking geral (gestor)
// Variáveis: $ranking, $de, $ate
$totalGeral  = array_sum(array_column($ranking, 'total'));
$pedidosGer  = array_sum(array_column($ranking, 'pedidos'));
?>
<div class="ap-page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div style="display:flex;align-items:center;gap:14px;">
    <a href="<?= ADMIN_URL ?>/usuarios" class="btn">← Usuários</a>
    <h1 style="font-size:20px;font-weight:800;margin:0;">Vendas por vendedor</h1>
  </div>
  <form method="get" style="display:flex;gap:8px;align-items:center;">
    <input type="date" name="de"  value="<?= View::e($de)  ?>" class="form-control" style="width:auto;">
    <input type="date" name="ate" value="<?= View::e($ate) ?>" class="form-control" style="width:auto;">
    <button class="btn btn-primary">Aplicar</button>
  </form>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin:16px 0;">
  <div class="admin-card" style="padding:16px 18px;border-left:4px solid var(--success);">
    <div style="font-size:12px;color:var(--c-text-muted);font-weight:600;text-transform:uppercase;letter-spacing:.4px;">Total vendido (período)</div>
    <div style="font-size:22px;font-weight:800;color:var(--success);margin-top:4px;">
      R$ <?= number_format($totalGeral, 2, ',', '.') ?></div>
  </div>
  <div class="admin-card" style="padding:16px 18px;border-left:4px solid var(--blue);">
    <div style="font-size:12px;color:var(--c-text-muted);font-weight:600;text-transform:uppercase;letter-spacing:.4px;">Pedidos atribuídos</div>
    <div style="font-size:22px;font-weight:800;color:var(--blue);margin-top:4px;"><?= (int)$pedidosGer ?></div>
  </div>
</div>

<?php if (empty($ranking)): ?>
<div class="admin-card" style="padding:48px;text-align:center;color:var(--c-text-muted);">
  <div style="font-size:40px;margin-bottom:8px;">🏆</div>
  <strong style="color:var(--c-dark);">Nenhum vendedor cadastrado</strong>
</div>
<?php else: ?>
<div class="admin-card">
  <table style="width:100%;border-collapse:collapse;font-size:13px;">
    <thead>
      <tr style="text-align:left;color:var(--c-text-muted);font-size:11.5px;text-transform:uppercase;letter-spacing:.4px;">
        <th style="padding:12px 18px;">#</th>
        <th style="padding:12px 10px;">Vendedor</th>
        <th style="padding:12px 10px;">Código</th>
        <th style="padding:12px 10px;text-align:center;">Pedidos</th>
        <th style="padding:12px 10px;text-align:right;">Ticket médio</th>
        <th style="padding:12px 18px;text-align:right;">Total vendido</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($ranking as $i => $v): ?>
      <tr style="border-top:1px solid var(--c-border);<?= !(int)$v['ativo'] ? 'opacity:.5;' : '' ?>">
        <td style="padding:12px 18px;font-weight:800;color:<?= $i < 3 ? 'var(--warning)' : 'inherit' ?>;">
          <?= $i + 1 ?>º</td>
        <td style="padding:12px 10px;font-weight:700;">
          <?= View::e($v['vendedor_nome']) ?>
          <?php if (!(int)$v['ativo']): ?>
            <span style="font-size:10.5px;color:var(--c-text-muted);">(inativo)</span>
          <?php endif; ?>
        </td>
        <td style="padding:12px 10px;font-family:ui-monospace,monospace;font-weight:700;
                   letter-spacing:1px;"><?= View::e($v['codigo']) ?></td>
        <td style="padding:12px 10px;text-align:center;"><?= (int)$v['pedidos'] ?></td>
        <td style="padding:12px 10px;text-align:right;">
          R$ <?= number_format((float)$v['ticket'], 2, ',', '.') ?></td>
        <td style="padding:12px 18px;text-align:right;font-weight:800;color:var(--success);">
          R$ <?= number_format((float)$v['total'], 2, ',', '.') ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<div class="admin-card" style="margin-top:14px;padding:12px 18px;font-size:12.5px;color:var(--c-text-muted);">
  Conta pedidos com pagamento aprovado no período, atribuídos via código do vendedor no checkout.
  Vendedores inativos aparecem se venderam no período (comissão histórica preservada).
</div>
<?php endif; ?>