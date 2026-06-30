<?php
// views/dashboard/index.php
$hoje   = $stats['hoje']   ?? [];
$mes    = $stats['mes']    ?? [];
$totais = $stats['totais'] ?? [];
$pedidosRecentes = $stats['pedidosRecentes'] ?? [];
$topProdutos     = $stats['topProdutos']     ?? [];
$chartData       = $stats['chartData']       ?? [];

$statusLabels = [
    'aguardando_pagamento' => ['label' => 'Aguardando',  'color' => 'warning'],
    'aprovado'             => ['label' => 'Aprovado',    'color' => 'success'],
    'em_separacao'         => ['label' => 'Separação',   'color' => 'info'],
    'enviado'              => ['label' => 'Enviado',     'color' => 'info'],
    'entregue'             => ['label' => 'Entregue',    'color' => 'success'],
    'cancelado'            => ['label' => 'Cancelado',   'color' => 'danger'],
    'reembolsado'          => ['label' => 'Reembolsado', 'color' => 'muted'],
];
?>

<div class="admin-page">

  <!-- ── Header ───────────────────────────────────────────── -->
  <div class="admin-page-header">
    <div>
      <h1>Dashboard</h1>
      <p>Bem-vindo! Aqui está o resumo de hoje, <?= date('d/m/Y') ?>.</p>
    </div>
    <div style="display:flex;gap:8px;">
      <?php if (($totais['pedidos_pendentes'] ?? 0) > 0): ?>
      <a href="<?= BASE_URL ?>/admin/pedidos?status=aguardando_pagamento"
         class="admin-alert-badge">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <circle cx="12" cy="12" r="10"/>
          <line x1="12" y1="8"  x2="12" y2="12"/>
          <line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        <?= (int)$totais['pedidos_pendentes'] ?> pedido(s) pendente(s)
      </a>
      <?php endif; ?>
      <?php if (($pedidos_nao_pagos_10d ?? 0) > 0): ?>
      <a href="<?= BASE_URL ?>/admin/payment/transacoes?status=pendente"
         class="admin-alert-badge admin-alert-badge--warning"
         title="Pedidos criados nos últimos 10 dias que ainda não foram pagos">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <rect x="1" y="4" width="22" height="16" rx="2"/>
          <line x1="1" y1="10" x2="23" y2="10"/>
        </svg>
        <?= (int)$pedidos_nao_pagos_10d ?> não pago(s) (10d)
      </a>
      <?php endif; ?>
      <?php if (($totais['estoque_baixo'] ?? 0) > 0): ?>
      <a href="<?= BASE_URL ?>/admin/produtos?estoque=baixo"
         class="admin-alert-badge admin-alert-badge--warning">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
          <line x1="12" y1="9" x2="12" y2="13"/>
          <line x1="12" y1="17" x2="12.01" y2="17"/>
        </svg>
        <?= (int)$totais['estoque_baixo'] ?> produto(s) com estoque baixo
      </a>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Stats cards ──────────────────────────────────────── -->
  <div class="admin-stats-grid">

    <div class="admin-stat-card">
      <div class="admin-stat-icon admin-stat-icon--blue">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/>
          <line x1="3" y1="6" x2="21" y2="6"/>
          <path d="M16 10a4 4 0 01-8 0"/>
        </svg>
      </div>
      <div class="admin-stat-info">
        <span class="admin-stat-label">Pedidos hoje</span>
        <strong class="admin-stat-value"><?= (int)($hoje['total_pedidos'] ?? 0) ?></strong>
        <span class="admin-stat-sub">
          <?= (int)($mes['total_pedidos'] ?? 0) ?> este mês
        </span>
      </div>
    </div>

    <div class="admin-stat-card">
      <div class="admin-stat-icon admin-stat-icon--green">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <line x1="12" y1="1" x2="12" y2="23"/>
          <path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/>
        </svg>
      </div>
      <div class="admin-stat-info">
        <span class="admin-stat-label">Receita hoje</span>
        <strong class="admin-stat-value">
          <?= PriceHelper::format((float)($hoje['receita'] ?? 0)) ?>
        </strong>
        <span class="admin-stat-sub">
          <?= PriceHelper::format((float)($mes['receita'] ?? 0)) ?> este mês
        </span>
      </div>
    </div>

    <div class="admin-stat-card">
      <div class="admin-stat-icon admin-stat-icon--purple">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/>
          <circle cx="9" cy="7" r="4"/>
          <path d="M23 21v-2a4 4 0 00-3-3.87"/>
          <path d="M16 3.13a4 4 0 010 7.75"/>
        </svg>
      </div>
      <div class="admin-stat-info">
        <span class="admin-stat-label">Total clientes</span>
        <strong class="admin-stat-value">
          <?= number_format((int)($totais['total_clientes'] ?? 0)) ?>
        </strong>
        <span class="admin-stat-sub">cadastrados</span>
      </div>
    </div>

    <div class="admin-stat-card">
      <div class="admin-stat-icon admin-stat-icon--orange">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/>
        </svg>
      </div>
      <div class="admin-stat-info">
        <span class="admin-stat-label">Produtos ativos</span>
        <strong class="admin-stat-value">
          <?= number_format((int)($totais['total_produtos'] ?? 0)) ?>
        </strong>
        <?php if (($totais['estoque_baixo'] ?? 0) > 0): ?>
        <span class="admin-stat-sub admin-stat-sub--warning">
          <?= (int)$totais['estoque_baixo'] ?> com estoque baixo
        </span>
        <?php else: ?>
        <span class="admin-stat-sub">todos em estoque</span>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <!-- ── Gráfico + top produtos ────────────────────────────── -->
  <div class="admin-grid-2" style="margin-top:24px;">

    <!-- Gráfico 30 dias -->
    <div class="admin-card">
      <div class="admin-card-header">
        <h3>Receita — últimos 30 dias</h3>
      </div>
      <div style="position:relative;height:200px;padding:8px 0;">
        <canvas id="chartReceita"></canvas>
      </div>
    </div>

    <!-- Top produtos -->
    <div class="admin-card">
      <div class="admin-card-header">
        <h3>Mais vendidos este mês</h3>
        <a href="<?= BASE_URL ?>/admin/produtos" class="btn btn-sm btn-ghost">
          Ver todos
        </a>
      </div>
      <?php if (empty($topProdutos)): ?>
      <p class="admin-empty">Sem dados ainda.</p>
      <?php else: ?>
      <div class="admin-top-list">
        <?php foreach ($topProdutos as $i => $p): ?>
        <div class="admin-top-item">
          <span class="admin-top-rank"><?= $i + 1 ?></span>
          <div class="admin-top-info">
            <a href="<?= BASE_URL ?>/admin/produtos/<?= View::e($p['slug']) ?>"
               class="admin-top-nome">
              <?= View::e($p['nome']) ?>
            </a>
            <span class="admin-top-meta">
              <?= (int)$p['vendidos'] ?> vendido(s)
            </span>
          </div>
          <span class="admin-top-receita">
            <?= PriceHelper::format((float)$p['receita']) ?>
          </span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

  </div>

  <!-- ── Últimos pedidos ───────────────────────────────────── -->
  <div class="admin-card" style="margin-top:24px;">
    <div class="admin-card-header">
      <h3>Últimos pedidos</h3>
      <a href="<?= BASE_URL ?>/admin/pedidos" class="btn btn-sm btn-outline">
        Ver todos
      </a>
    </div>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th>Pedido</th>
            <th>Cliente</th>
            <th>Total</th>
            <th>Pagamento</th>
            <th>Status</th>
            <th>Data</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($pedidosRecentes)): ?>
          <tr>
            <td colspan="7" class="admin-table-empty">
              Nenhum pedido ainda.
            </td>
          </tr>
          <?php else: ?>
          <?php foreach ($pedidosRecentes as $p):
            $statusInfo = $statusLabels[$p['status_pedido'] ?? ''] ?? ['label' => $p['status_pedido'] ?? '—', 'color' => 'muted'];
          ?>
          <tr>
            <td>
              <strong class="admin-codigo">#<?= View::e($p['codigo'] ?? $p['id']) ?></strong>
            </td>
            <td><?= View::e($p['cliente_nome'] ?? '—') ?></td>
            <td><?= PriceHelper::format((float)$p['total']) ?></td>
            <td>
              <span class="admin-badge admin-badge--<?= $p['status_pagamento'] === 'aprovado' ? 'success' : 'warning' ?>">
                <?= $p['status_pagamento'] === 'aprovado' ? 'Aprovado' : 'Pendente' ?>
              </span>
            </td>
            <td>
              <span class="admin-badge admin-badge--<?= $statusInfo['color'] ?>">
                <?= View::e($statusInfo['label']) ?>
              </span>
            </td>
            <td class="admin-date">
              <?= date('d/m/Y H:i', strtotime($p['criado_em'])) ?>
            </td>
            <td>
              <a href="<?= BASE_URL ?>/admin/pedidos/<?= (int)$p['id'] ?>"
                 class="btn btn-sm btn-ghost">Ver</a>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  

</div>

<!-- <div class="admin-aside">teste</div> -->

<!-- Chart.js -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script>
(function () {
  const raw = <?= json_encode($chartData, JSON_UNESCAPED_UNICODE) ?>;
  if (!raw.length) return;

  // Preenche dias sem dados com zero
  const dias    = [];
  const receita = [];
  const pedidos = [];
  const hoje    = new Date();

  for (let i = 29; i >= 0; i--) {
    const d   = new Date(hoje);
    d.setDate(d.getDate() - i);
    const key = d.toISOString().slice(0, 10);
    const row = raw.find(r => r.dia === key);
    dias.push(d.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' }));
    receita.push(row ? parseFloat(row.receita) : 0);
    pedidos.push(row ? parseInt(row.pedidos)  : 0);
  }

  const isDark = document.documentElement.classList.contains('dark');
  const textColor = isDark ? '#a0a0a0' : '#888';
  const gridColor = isDark ? 'rgba(255,255,255,.06)' : 'rgba(0,0,0,.06)';

  new Chart(document.getElementById('chartReceita'), {
    type: 'line',
    data: {
      labels: dias,
      datasets: [{
        label: 'Receita',
        data: receita,
        borderColor: '#e63946',
        backgroundColor: 'rgba(230,57,70,.08)',
        borderWidth: 2,
        pointRadius: 0,
        pointHoverRadius: 4,
        fill: true,
        tension: .4,
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: ctx => 'R$ ' + ctx.raw.toFixed(2).replace('.', ','),
          },
        },
      },
      scales: {
        x: {
          grid: { color: gridColor },
          ticks: {
            color: textColor, maxTicksLimit: 8,
            font: { size: 11 },
          },
        },
        y: {
          grid: { color: gridColor },
          ticks: {
            color: textColor,
            font: { size: 11 },
            callback: v => 'R$ ' + v.toLocaleString('pt-BR'),
          },
          beginAtZero: true,
        },
      },
    },
  });
})();
</script>