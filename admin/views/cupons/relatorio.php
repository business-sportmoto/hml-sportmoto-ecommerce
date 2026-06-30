<?php
// views/admin/cupons/relatorio.php
// Relatório geral de todos os cupons com gráficos e tabelas
?>
<div class="admin-page">

  <div class="admin-page-header">
    <div>
      <a href="<?= BASE_URL ?>/admin/cupons" class="back-link">← Cupons</a>
      <h1 class="admin-page-title">Relatório de cupons</h1>
      <p class="admin-page-sub">Período: últimos 30 dias</p>
    </div>
    <div class="header-actions">
      <form method="GET" class="filter-inline">
        <input type="date" name="data_de"  class="form-control form-control--sm"
               value="<?= View::e($filtros['data_de']  ?? date('Y-m-d', strtotime('-30 days'))) ?>">
        <span class="filter-sep">até</span>
        <input type="date" name="data_ate" class="form-control form-control--sm"
               value="<?= View::e($filtros['data_ate'] ?? date('Y-m-d')) ?>">
        <button type="submit" class="btn btn-outline btn-sm">Filtrar</button>
      </form>
    </div>
  </div>

  <!-- KPIs principais -->
  <div class="stats-grid stats-grid--5">

    <div class="stat-card stat-card--highlight">
      <div class="stat-card-icon stat-card-icon--green">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
      </div>
      <div class="stat-card-body">
        <span class="stat-card-value"><?= $kpis['usos_confirmados'] ?? 0 ?></span>
        <span class="stat-card-label">Usos confirmados</span>
        <span class="stat-card-delta stat-card-delta--up">
          +<?= $kpis['usos_hoje'] ?? 0 ?> hoje
        </span>
      </div>
    </div>

    <div class="stat-card">
      <div class="stat-card-icon stat-card-icon--purple">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
      </div>
      <div class="stat-card-body">
        <span class="stat-card-value"><?= PriceHelper::format($kpis['desconto_total'] ?? 0) ?></span>
        <span class="stat-card-label">Economia gerada</span>
      </div>
    </div>

    <div class="stat-card">
      <div class="stat-card-icon stat-card-icon--blue">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
      </div>
      <div class="stat-card-body">
        <span class="stat-card-value"><?= PriceHelper::format($kpis['ticket_medio'] ?? 0) ?></span>
        <span class="stat-card-label">Ticket médio com cupom</span>
      </div>
    </div>

    <div class="stat-card">
      <div class="stat-card-icon stat-card-icon--orange">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 15.01 9 12.01"/></svg>
      </div>
      <div class="stat-card-body">
        <span class="stat-card-value"><?= $kpis['taxa_aprovacao'] ?? 0 ?>%</span>
        <span class="stat-card-label">Taxa de aprovação</span>
      </div>
    </div>

    <div class="stat-card">
      <div class="stat-card-icon stat-card-icon--red">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
      </div>
      <div class="stat-card-body">
        <span class="stat-card-value"><?= $kpis['total_recusas'] ?? 0 ?></span>
        <span class="stat-card-label">Tentativas recusadas</span>
      </div>
    </div>
  </div>

  <div class="relatorio-grid">

    <!-- Gráfico: usos por dia -->
    <div class="admin-card relatorio-card relatorio-card--wide">
      <h3 class="admin-card-title">Usos por dia</h3>
      <canvas id="chart-usos-dia" height="80"></canvas>
    </div>

    <!-- Gráfico: desconto por tipo -->
    <div class="admin-card relatorio-card">
      <h3 class="admin-card-title">Desconto por tipo de cupom</h3>
      <canvas id="chart-tipo" height="180"></canvas>
    </div>

  </div>

  <div class="relatorio-grid relatorio-grid--3">

    <!-- Top cupons mais usados -->
    <div class="admin-card relatorio-card">
      <h3 class="admin-card-title">Top cupons</h3>
      <table class="admin-table admin-table--compact">
        <thead><tr><th>Código</th><th class="text-center">Usos</th><th class="text-right">Desconto</th></tr></thead>
        <tbody>
          <?php foreach ($topCupons ?? [] as $row): ?>
          <tr>
            <td><code class="coupon-code-badge coupon-code-badge--sm"><?= View::e($row['codigo']) ?></code></td>
            <td class="text-center"><strong><?= (int)$row['total_usos'] ?></strong></td>
            <td class="text-right txt-green">−<?= PriceHelper::format((float)$row['total_desc']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Top clientes -->
    <div class="admin-card relatorio-card">
      <h3 class="admin-card-title">Top clientes</h3>
      <table class="admin-table admin-table--compact">
        <thead><tr><th>Cliente</th><th class="text-center">Usos</th><th class="text-right">Economizado</th></tr></thead>
        <tbody>
          <?php foreach ($topClientes ?? [] as $row): ?>
          <tr>
            <td>
              <div class="td-main td-main--sm"><?= View::e($row['cliente_nome'] ?? '—') ?></div>
              <small class="txt-muted"><?= View::e($row['cliente_email'] ?? '') ?></small>
            </td>
            <td class="text-center"><?= (int)$row['total_usos'] ?></td>
            <td class="text-right txt-green">−<?= PriceHelper::format((float)$row['total_desc']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Recusas por motivo -->
    <div class="admin-card relatorio-card">
      <h3 class="admin-card-title">Motivos de recusa</h3>
      <table class="admin-table admin-table--compact">
        <thead><tr><th>Motivo</th><th class="text-center">Qtd</th></tr></thead>
        <tbody>
          <?php foreach ($recusasPorMotivo ?? [] as $row): ?>
          <tr>
            <td><small><?= View::e($row['motivo_curto'] ?? $row['motivo_recusa']) ?></small></td>
            <td class="text-center">
              <span class="badge badge-red"><?= (int)$row['total'] ?></span>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

  </div>

  <!-- Por vendedor -->
  <?php if (!empty($porVendedor)): ?>
  <div class="admin-card">
    <h3 class="admin-card-title">Cupons por vendedor</h3>
    <div class="table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th>Vendedor</th>
            <th>Código</th>
            <th class="text-center">Pedidos c/ cupom</th>
            <th class="text-right">Desconto gerado</th>
            <th class="text-right">Ticket médio</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($porVendedor as $v): ?>
          <tr>
            <td>
              <div class="td-main"><?= View::e($v['vendedor_nome'] ?? '—') ?></div>
              <small class="txt-muted"><?= View::e($v['codigo_vendedor'] ?? '') ?></small>
            </td>
            <td>
              <code class="coupon-code-badge coupon-code-badge--sm"><?= View::e($v['cupom_codigo']) ?></code>
            </td>
            <td class="text-center"><strong><?= (int)$v['total_pedidos'] ?></strong></td>
            <td class="text-right txt-green">−<?= PriceHelper::format((float)$v['total_desc']) ?></td>
            <td class="text-right"><?= PriceHelper::format((float)$v['ticket_medio']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
    const usosDia = <?= json_encode($graficoUsosDia ?? []) ?>;
    const tiposData = <?= json_encode($graficoTipos ?? []) ?>;
</script>