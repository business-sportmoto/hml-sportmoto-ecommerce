<?php
// views/customer/dashboard.php
$statusLabels = [
    'aguardando_pagamento' => ['cor' => 'warning', 'label' => 'Aguardando pagamento'],
    'pagamento_aprovado'   => ['cor' => 'info',    'label' => 'Pagamento aprovado'],
    'em_separacao'         => ['cor' => 'info',    'label' => 'Em separação'],
    'enviado'              => ['cor' => 'info',    'label' => 'Enviado'],
    'entregue'             => ['cor' => 'success', 'label' => 'Entregue'],
    'cancelado'            => ['cor' => 'danger',  'label' => 'Cancelado'],
];
?>
<div class="customer-page">
  <div class="customer-page-header">
    <h1 style="display:flex; align-items:center; gap:8px;">
      Olá, <?= View::e(explode(' ', $perfil['nome'])[0]) ?>!
      <?php View::partial('partials/verified-badge', [
          'verificado' => $perfil['verificado'] ?? false,
          'size'       => 'lg',
          'show_text'  => true,
      ]) ?>
    </h1>
    <p class="customer-page-sub">
      Membro desde <?= date('M/Y', strtotime($perfil['membro_desde'])) ?>
    </p>
  </div>

  <!-- Stats -->
  <div class="customer-stats">
    <div class="customer-stat">
      <span class="stat-value"><?= (int)$stats['total_pedidos'] ?></span>
      <span class="stat-label">Pedidos</span>
    </div>
    <div class="customer-stat">
      <span class="stat-value"><?= PriceHelper::format($stats['gasto_total']) ?></span>
      <span class="stat-label">Total gasto</span>
    </div>
    <div class="customer-stat">
      <span class="stat-value"><?= (int)$stats['total_favoritos'] ?></span>
      <span class="stat-label">Favoritos</span>
    </div>
    <div class="customer-stat">
      <span class="stat-value"><?= (int)$stats['total_enderecos'] ?></span>
      <span class="stat-label">Endereços</span>
    </div>
  </div>

  <!-- Pedidos recentes -->
  <div class="customer-section">
    <div class="customer-section-header">
      <h2>Pedidos recentes</h2>
      <a href="<?= BASE_URL ?>/minha-conta/pedidos" class="section-link">Ver todos</a>
    </div>

    <?php if (empty($pedidos)): ?>
    <div class="empty-state">
      <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
        <path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/>
        <path d="M16 10a4 4 0 01-8 0"/>
      </svg>
      <p>Você ainda não fez nenhum pedido.</p>
      <a href="<?= BASE_URL ?>/busca" class="btn btn-primary btn-sm">Começar a comprar</a>
    </div>
    <?php else: ?>
    <div class="orders-list">
      <?php foreach ($pedidos as $ped):
        $st = $statusLabels[$ped['status_pedido']] ?? ['cor' => 'info', 'label' => $ped['status_pedido']];
      ?>
      <a href="<?= BASE_URL ?>/minha-conta/pedido/<?= (int)$ped['id'] ?>"
         class="order-card">
        <div class="order-card-header">
          <span class="order-card-code"><?= View::e($ped['codigo']) ?></span>
          <span class="order-status-badge order-status-badge--<?= $st['cor'] ?>">
            <?= $st['label'] ?>
          </span>
        </div>
        <div class="order-card-info">
          <span><?= (int)$ped['total_itens'] ?> iten<?= $ped['total_itens'] != 1 ? 's' : '' ?></span>
          <span><?= date('d/m/Y', strtotime($ped['criado_em'])) ?></span>
          <strong><?= PriceHelper::format((float)$ped['total']) ?></strong>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>


  <?php View::partial('customer/partials/_dashboard-fotos') ?>

  <!-- Atalhos rápidos -->
  <div class="customer-section">
    <h2>Acesso rápido</h2>
    <div class="quick-links">
      <a href="<?= BASE_URL ?>/minha-conta/enderecos" class="quick-link">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/>
        </svg>
        <span>Endereços</span>
      </a>
      <a href="<?= BASE_URL ?>/minha-conta/favoritos" class="quick-link">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/>
        </svg>
        <span>Favoritos</span>
      </a>
      <a href="<?= BASE_URL ?>/minha-conta/cartoes" class="quick-link">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>
        </svg>
        <span>Cartões</span>
      </a>
      <a href="<?= BASE_URL ?>/minha-conta/perfil" class="quick-link">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/>
        </svg>
        <span>Meu perfil</span>
      </a>
    </div>
  </div>
</div>