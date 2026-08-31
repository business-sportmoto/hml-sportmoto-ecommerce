<?php
// views/admin/pedidos/index.php

$statusMap = [
    'aguardando_pagamento' => ['cor'=>'warning', 'label'=>'Aguardando pgto.'],
    'pagamento_aprovado'   => ['cor'=>'info',    'label'=>'Pgto. aprovado'],
    'em_separacao'         => ['cor'=>'info',    'label'=>'Em separação'],
    'enviado'              => ['cor'=>'primary', 'label'=>'Enviado'],
    'entregue'             => ['cor'=>'success', 'label'=>'Entregue'],
    'cancelado'            => ['cor'=>'danger',  'label'=>'Cancelado'],
    'troca_devolucao'      => ['cor'=>'warning', 'label'=>'Troca/Dev.'],
];
$pagMap = [
    'pendente'   => ['cor'=>'warning', 'label'=>'Pendente'],
    'aguardando' => ['cor'=>'warning', 'label'=>'Aguardando'],
    'aprovado'   => ['cor'=>'success', 'label'=>'Aprovado'],
    'recusado'   => ['cor'=>'danger',  'label'=>'Recusado'],
    'estornado'  => ['cor'=>'danger',  'label'=>'Estornado'],
    'reembolsado'=> ['cor'=>'info',    'label'=>'Reembolsado'],
];
?>

<div class="admin-page">

  <div class="admin-page-header">
    <div>
      <h1 class="admin-page-title">Pedidos</h1>
      <p class="admin-page-sub"><?= number_format($total) ?> pedidos encontrados</p>
    </div>
    <div class="ped_head_acoes">
    <a href="<?= ADMIN_URL ?>/pedidos/checkout" class="btn btn-secondary">
      <?= class_exists('IconLibrary') ? IconLibrary::render('package', 'icon icon--md') : '' ?>
      Checkout
    </a>
    <a href="<?= ADMIN_URL ?>/pedidos/novo" class="btn btn-primary">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Novo pedido
    </a>
    </div>
  </div>

  <!-- KPIs -->
  <div class="stats-grid stats-grid--5" style="margin-bottom:20px;">
    <div class="stat-card">
      <div class="stat-card-icon stat-card-icon--blue">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/></svg>
      </div>
      <div class="stat-card-body">
        <span class="stat-card-value"><?= (int)($kpis['novos_hoje'] ?? 0) ?></span>
        <span class="stat-card-label">Novos hoje</span>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-card-icon stat-card-icon--orange">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      </div>
      <div class="stat-card-body">
        <span class="stat-card-value"><?= (int)($kpis['aguardando_pagamento'] ?? 0) ?></span>
        <span class="stat-card-label">Aguardando pgto.</span>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-card-icon stat-card-icon--purple">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><polyline points="9 11 12 14 22 4"/></svg>
      </div>
      <div class="stat-card-body">
        <span class="stat-card-value"><?= (int)($kpis['em_separacao'] ?? 0) ?></span>
        <span class="stat-card-label">Em separação</span>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-card-icon stat-card-icon--blue">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
      </div>
      <div class="stat-card-body">
        <span class="stat-card-value"><?= (int)($kpis['enviados'] ?? 0) ?></span>
        <span class="stat-card-label">Enviados</span>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-card-icon stat-card-icon--green">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
      </div>
      <div class="stat-card-body">
        <span class="stat-card-value"><?= PriceHelper::format((float)($kpis['receita_total'] ?? 0)) ?></span>
        <span class="stat-card-label">Receita total</span>
      </div>
    </div>
  </div>

  <!-- Filtros -->
  <form method="GET" class="admin-filters">
    <div class="filter-row">
      <div class="filter-group filter-group--search">
        <input type="text" name="q" class="form-control" placeholder="Buscar por código, cliente ou e-mail…"
               value="<?= View::e($filtros['q']) ?>">
      </div>
      <div class="filter-group">
        <select name="status_pedido" class="form-control">
          <option value="">Todos os status</option>
          <?php foreach ($statusMap as $k => $v): ?>
          <option value="<?= $k ?>" <?= $filtros['status_pedido']===$k?'selected':'' ?>><?= $v['label'] ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filter-group">
        <select name="status_pagamento" class="form-control">
          <option value="">Pagamento</option>
          <?php foreach ($pagMap as $k => $v): ?>
          <option value="<?= $k ?>" <?= $filtros['status_pagamento']===$k?'selected':'' ?>><?= $v['label'] ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filter-group">
        <input type="date" name="data_de" class="form-control" value="<?= View::e($filtros['data_de']) ?>">
      </div>
      <div class="filter-group">
        <input type="date" name="data_ate" class="form-control" value="<?= View::e($filtros['data_ate']) ?>">
      </div>
      <button type="submit" class="btn btn-outline">Filtrar</button>
      <?php if (array_filter($filtros)): ?>
        <a href="<?= ADMIN_URL ?>/pedidos" class="btn btn-ghost">Limpar</a>
      <?php endif; ?>
    </div>
  </form>

  <!-- Tabela -->
  <div class="admin-card">
    <?php if (empty($pedidos)): ?>
    <div class="empty-state">
      <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/></svg>
      <strong>Nenhum pedido encontrado</strong>
    </div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th>Pedido</th>
            <th>Cliente</th>
            <th>Itens</th>
            <th>Total</th>
            <th>Pagamento</th>
            <th>Status</th>
            <th>Data</th>
            <th class="text-right">Ação</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pedidos as $p):
            $st  = $statusMap[$p['status_pedido']]    ?? ['cor'=>'info','label'=>$p['status_pedido']];
            $pag = $pagMap[$p['status_pagamento']]    ?? ['cor'=>'info','label'=>$p['status_pagamento']];
            $img = !empty($p['primeira_imagem'])
                 ? BASE_URL.'/uploads/produtos/'.$p['primeira_imagem']
                 : BASE_URL.'/assets/img/placeholder.png';
                
            $img = $p['primeiro_produto_id'] ? ImageHelper::getCartItemImage($p['primeiro_produto_id']) : '';
          ?>
          <tr>
            <td>
              <a href="<?= ADMIN_URL ?>/pedidos/<?= $p['id'] ?>" class="link-subtle">
                <strong>#<?= View::e($p['codigo']) ?></strong>
              </a>
              <?php if ($p['codigo_rastreio']): ?>
                <div style="margin-top:3px;">
                  <code style="font-size:10.5px;color:var(--text-2);"><?= View::e($p['codigo_rastreio']) ?></code>
                </div>
              <?php endif; ?>
            </td>
            <td>
              <div class="td-main"><?= View::e($p['cliente_nome']) ?></div>
              <small class="txt-muted"><?= View::e($p['cliente_email']) ?></small>
            </td>
            <td>
              <div style="display:flex;align-items:center;gap:8px;">
                <img src="<?= View::e($img) ?>" width="36" height="36"
                     style="border-radius:6px;object-fit:cover;background:var(--surface2);">
                <span style="font-size:12.5px;color:var(--c-text-muted);">
                  <?= (int)$p['total_itens'] ?> <?= (int)$p['total_itens']===1?'item':'itens' ?>
                </span>
              </div>
            </td>
            <td>
              <strong><?= PriceHelper::format((float)$p['total']) ?></strong>
              <?php if ($p['parcelas'] > 1): ?>
                <div><small class="txt-muted"><?= $p['parcelas'] ?>× parcelas</small></div>
              <?php endif; ?>
            </td>
            <td>
              <span class="badge badge-<?= $pag['cor'] ?>"><?= $pag['label'] ?></span>
              <?php if ($p['cartao_bandeira']): ?>
                <div style="margin-top:3px;">
                  <span style="font-size:11px;color:var(--c-text-muted);">
                    <?= View::e(ucfirst($p['cartao_bandeira'])) ?> ****<?= View::e($p['cartao_ultimos_4'] ?? '') ?>
                  </span>
                </div>
              <?php endif; ?>
            </td>
            <td>
              <span class="badge badge-<?= $st['cor'] ?>"><?= $st['label'] ?></span>
            </td>
            <td>
              <small><?= date('d/m/Y', strtotime($p['criado_em'])) ?></small>
              <div><small class="txt-muted"><?= date('H:i', strtotime($p['criado_em'])) ?></small></div>
            </td>
            <td class="text-right">
              <a href="<?= ADMIN_URL ?>/pedidos/<?= $p['id'] ?>" class="btn-icon" title="Abrir">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Paginação -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
      <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <a href="?<?= http_build_query(array_merge($filtros, ['page'=>$i])) ?>"
           class="pagination-item <?= $page===$i?'is-active':'' ?>"><?= $i ?></a>
      <?php endfor; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>