<?php
// views/customer/orders.php
// $pedidos, $has_pages, $prev, $next, $pages, $current_page, $total, $pagination
// injetados pelo CustomerController via $pag->toArray()

$statusMap = [
    'aguardando_pagamento' => ['cor' => 'warning', 'label' => 'Aguardando pagamento'],
    'pagamento_aprovado'   => ['cor' => 'info',    'label' => 'Pagamento aprovado'],
    'em_separacao'         => ['cor' => 'info',    'label' => 'Em separação'],
    'enviado'              => ['cor' => 'primary', 'label' => 'Enviado'],
    'entregue'             => ['cor' => 'success', 'label' => 'Entregue'],
    'cancelado'            => ['cor' => 'danger',  'label' => 'Cancelado'],
    'troca_devolucao'      => ['cor' => 'warning', 'label' => 'Troca/Devolução'],
];

// Filtro por status via GET
$filtroStatus = SecurityHelper::sanitizeString($_GET['status'] ?? '');
?>

<div class="customer-page">

  <!-- Cabeçalho -->
  <div class="customer-page-header">
    <div>
      <h1>Meus pedidos</h1>
      <p class="customer-page-sub">
        <?= number_format($total ?? 0) ?>
        <?= ($total ?? 0) === 1 ? 'pedido' : 'pedidos' ?>
      </p>
    </div>
  </div>

  <!-- Filter chips por status com ícones + contagem -->
  <?php
    // Ícones por status
    $chipIcons = [
      '' => '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="16" height="14" rx="2"/><line x1="2" y1="7" x2="18" y2="7"/><line x1="6" y1="11" x2="9" y2="11"/><line x1="6" y1="14" x2="9" y2="14"/><line x1="11" y1="11" x2="15" y2="11"/><line x1="11" y1="14" x2="15" y2="14"/></svg>',
      'aguardando_pagamento' => '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="10" cy="10" r="8"/><polyline points="10,5.5 10,10 13.5,12"/></svg>',
      'pagamento_aprovado'   => '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><rect x="2" y="4" width="16" height="12" rx="1.5"/><line x1="2" y1="8" x2="18" y2="8"/><polyline points="5,13 7.5,15.5 12,10.5"/></svg>',
      'em_separacao'         => '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 3H4a1 1 0 00-1 1v12a1 1 0 001 1h12a1 1 0 001-1V4a1 1 0 00-1-1z"/><polyline points="7,9 9.5,11.5 14,7"/></svg>',
      'enviado'              => '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="5" width="11" height="9" rx="1"/><path d="M12 8h2.5l2.5 2.5V14h-5V8z"/><circle cx="4.5" cy="16.5" r="1.5"/><circle cx="14.5" cy="16.5" r="1.5"/></svg>',
      'entregue'             => '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8.5L10 3l7 5.5V17a1 1 0 01-1 1H4a1 1 0 01-1-1V8.5z"/><path d="M7.5 18v-5.5h5V18"/></svg>',
      'cancelado'            => '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="10" cy="10" r="8"/><line x1="7" y1="7" x2="13" y2="13"/><line x1="13" y1="7" x2="7" y2="13"/></svg>',
      'troca_devolucao'      => '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><polyline points="14,1.5 18,5.5 14,9.5"/><path d="M2,9.5V7.5a4 4 0 014-4h12"/><polyline points="6,18.5 2,14.5 6,10.5"/><path d="M18,14.5v2a4 4 0 01-4 4H2"/></svg>',
    ];
  ?>
  <div class="orders-filter-bar">

    <!-- Chip: Todos -->
    <a href="?<?= http_build_query(array_merge(array_diff_key($_GET, ['status'=>'','pagina'=>'']), ['pagina'=>1])) ?>"
       class="filter-chip filter-chip--todos <?= $filtroStatus === '' ? 'is-active' : '' ?>">
      <span class="fc-icon"><?= $chipIcons[''] ?></span>
      <span class="fc-label">Todos</span>
      <?php if (!empty($total)): ?>
        <span class="fc-badge"><?= (int)$total ?></span>
      <?php endif; ?>
    </a>

    <!-- Chips por status -->
    <?php foreach ($statusMap as $val => $info):
      $cnt = (int)($statusCounts[$val] ?? 0);
      // Oculta status sem pedidos (exceto o ativo)
      if ($cnt === 0 && $filtroStatus !== $val) continue;
    ?>
    <a href="?<?= http_build_query(array_merge(array_diff_key($_GET, ['status'=>'','pagina'=>'']), ['status'=>$val,'pagina'=>1])) ?>"
       class="filter-chip filter-chip--<?= $info['cor'] ?> <?= $filtroStatus === $val ? 'is-active' : '' ?>">
      <span class="fc-icon"><?= $chipIcons[$val] ?? '' ?></span>
      <span class="fc-label"><?= View::e($info['label']) ?></span>
      <?php if ($cnt > 0): ?>
        <span class="fc-badge"><?= $cnt ?></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>

  </div>

  <?php if (empty($pedidos)): ?>

  <!-- Empty state -->
  <div class="orders-empty">
    <svg width="52" height="52" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round">
      <path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/>
      <line x1="3" y1="6" x2="21" y2="6"/>
      <path d="M16 10a4 4 0 01-8 0"/>
    </svg>
    <strong>Nenhum pedido encontrado</strong>
    <p>
      <?= $filtroStatus
          ? 'Nenhum pedido com o status selecionado.'
          : 'Quando você realizar uma compra, ela aparecerá aqui.' ?>
    </p>
    <a href="<?= BASE_URL ?>" class="btn btn-primary">Explorar produtos</a>
  </div>

  <?php else: ?>

  <!-- Lista de pedidos -->
  <div class="orders-list">
    <?php foreach ($pedidos as $p):
      $st     = $statusMap[$p['status_pedido']] ?? ['cor'=>'info','label'=>$p['status_pedido']];
      $imgUrl = $p['primeiro_produto_id'] ? ImageHelper::getCartItemImage($p['primeiro_produto_id']) : View::asset('images/placeholder.jpg');
      $totalItens = (int)($p['total_itens'] ?? 0);
    ?>
    <a href="<?= BASE_URL ?>/minha-conta/pedido/<?= (int)$p['id'] ?>" data-teste="<?= $p['primeiro_produto_id']; ?>"
       class="order-card">

      <!-- Thumbnail + contagem -->
      <div class="order-card-thumb-wrap">
        <img src="<?= View::e($imgUrl) ?>" alt="" loading="lazy" class="order-card-thumb">
        <?php if ($totalItens > 1): ?>
          <span class="order-card-thumb-count">+<?= $totalItens - 1 ?></span>
        <?php endif; ?>
      </div>

      <!-- Info -->
      <div class="order-card-body">
        <div class="order-card-code">#<?= View::e($p['codigo']) ?></div>
        <div class="order-card-date">
          <?= date('d/m/Y', strtotime($p['criado_em'])) ?>
        </div>
        <div class="order-card-items-count">
          <?= $totalItens ?> <?= $totalItens === 1 ? 'item' : 'itens' ?>
        </div>
      </div>

      <!-- Total + status + seta -->
      <div class="order-card-right">
        <strong class="order-card-total">
          <?= PriceHelper::format((float)$p['total']) ?>
        </strong>
        <span class="order-status-pill order-status-pill--<?= $st['cor'] ?>">
          <?= $st['label'] ?>
        </span>
      </div>
      <div class="order-card-arrow">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.5" stroke-linecap="round"><polyline points="9 18 15 12 9 6"/></svg>
      </div>

    </a>
    <?php endforeach; ?>
  </div>

  <!-- Paginação -->
  <?php if ($has_pages): ?>
  <nav class="pagination-bar" aria-label="Paginação de pedidos">

    <!-- Anterior -->
    <?php if ($prev): ?>
      <a href="<?= $pagination->url($prev) ?>" class="page-btn page-btn--nav" aria-label="Anterior">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="15 18 9 12 15 6"/></svg>
      </a>
    <?php else: ?>
      <span class="page-btn page-btn--nav page-btn--disabled" aria-disabled="true">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="15 18 9 12 15 6"/></svg>
      </span>
    <?php endif; ?>

    <!-- Números -->
    <?php foreach ($pages as $pg): ?>
      <?php if ($pg === '...'): ?>
        <span class="page-sep">…</span>
      <?php else: ?>
        <a href="<?= $pagination->url($pg) ?>"
           class="page-btn <?= (int)$pg === (int)$current_page ? 'page-btn--active' : '' ?>"
           <?= (int)$pg === (int)$current_page ? 'aria-current="page"' : '' ?>>
          <?= $pg ?>
        </a>
      <?php endif; ?>
    <?php endforeach; ?>

    <!-- Próxima -->
    <?php if ($next): ?>
      <a href="<?= $pagination->url($next) ?>" class="page-btn page-btn--nav" aria-label="Próxima">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="9 18 15 12 9 6"/></svg>
      </a>
    <?php else: ?>
      <span class="page-btn page-btn--nav page-btn--disabled" aria-disabled="true">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="9 18 15 12 9 6"/></svg>
      </span>
    <?php endif; ?>

  </nav>
  <?php endif; ?>

  <?php endif; ?>
</div>