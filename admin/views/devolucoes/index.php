<?php
// views/admin/devolucoes/index.php

$statusLabels = [
    'solicitado'              => ['cor'=>'warning', 'label'=>'Solicitado'],
    'pre_aprovado'            => ['cor'=>'success', 'label'=>'Pré-aprovado'],
    'aguardando_aprovacao'    => ['cor'=>'warning', 'label'=>'Aguardando'],
    'aprovado'                => ['cor'=>'info',    'label'=>'Aprovado'],
    'negado'                  => ['cor'=>'danger',  'label'=>'Negado'],
    'aguardando_postagem'     => ['cor'=>'warning', 'label'=>'Ag. postagem'],
    'em_transito_reverso'     => ['cor'=>'primary', 'label'=>'Em trânsito'],
    'item_recebido'           => ['cor'=>'info',    'label'=>'Recebido'],
    'inspecionado_aprovado'   => ['cor'=>'success', 'label'=>'Insp. aprovada'],
    'inspecionado_reprovado'  => ['cor'=>'danger',  'label'=>'Insp. reprovada'],
    'concluido'               => ['cor'=>'success', 'label'=>'Concluído'],
    'concluido_reprovado'     => ['cor'=>'danger',  'label'=>'Encerrado'],
    'cancelado'               => ['cor'=>'danger',  'label'=>'Cancelado'],
    'expirado'                => ['cor'=>'gray',    'label'=>'Expirado'],
];

// Contagens por status (para os chips)
$contagensPorStatus = [];
foreach ($lista as $sol) {
    $contagensPorStatus[$sol['status']] = ($contagensPorStatus[$sol['status']] ?? 0) + 1;
}
?>

<div class="admin-page">

  <div class="admin-page-header">
    <div>
      <h1 class="admin-page-title">Devoluções e Trocas</h1>
      <p class="admin-page-sub"><?= number_format($total) ?> solicitações encontradas</p>
    </div>
    <div style="display:flex;gap:8px;align-items:center;">
      <button type="button" class="btn btn-primary btn-sm" id="btn-registrar-recebimento">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.5" stroke-linecap="round" style="margin-right:4px;">
          <polyline points="20 6 9 17 4 12"/>
        </svg>
        Registrar recebimento
      </button>
      <a href="<?= ADMIN_URL ?>/devolucoes/motivos" class="btn btn-outline btn-sm">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="3"/>
          <path d="M19.07 4.93a10 10 0 010 14.14M4.93 4.93a10 10 0 000 14.14"/>
        </svg>
        Gerenciar motivos
      </a>
    </div>
  </div>

  <!-- Filtros -->
  <form method="GET" class="admin-filters" style="margin-bottom:16px;">
    <div class="filter-row">
      <div class="filter-group filter-group--search">
        <input type="text" name="q" class="form-control"
               placeholder="Buscar por cliente, e-mail ou pedido…"
               value="<?= View::e($filtros['q'] ?? '') ?>">
      </div>
      <div class="filter-group">
        <select name="status" class="form-control">
          <option value="">Todos os status</option>
          <?php foreach ($statusLabels as $k => $v): ?>
          <option value="<?= $k ?>" <?= ($filtros['status']??'')===$k?'selected':'' ?>>
            <?= $v['label'] ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filter-group">
        <select name="tipo" class="form-control">
          <option value="">Tipo</option>
          <option value="devolucao" <?= ($filtros['tipo']??'')==='devolucao'?'selected':'' ?>>Devolução</option>
          <option value="troca"     <?= ($filtros['tipo']??'')==='troca'?'selected':'' ?>>Troca</option>
        </select>
      </div>
      <button type="submit" class="btn btn-outline">Filtrar</button>
      <?php if (array_filter($filtros)): ?>
        <a href="<?= ADMIN_URL ?>/devolucoes" class="btn btn-ghost">Limpar</a>
      <?php endif; ?>
    </div>
  </form>

  <!-- Chips de status com contagem -->
  <div class="orders-filter-bar" style="margin-bottom:16px;">
    <a href="<?= ADMIN_URL ?>/devolucoes"
       class="filter-chip <?= empty($filtros['status'])?'is-active':'' ?>">
      Todos
      <span class="fc-badge"><?= $total ?></span>
    </a>
    <?php
    // Destaca os que precisam de ação
    $prioritarios = ['aguardando_aprovacao','item_recebido','inspecionado_aprovado'];
    foreach ($prioritarios as $slug):
      if (empty($contagensPorStatus[$slug])) continue;
      $st = $statusLabels[$slug];
    ?>
    <a href="<?= ADMIN_URL ?>/devolucoes?status=<?= $slug ?>"
       class="filter-chip filter-chip--<?= $st['cor'] ?> <?= ($filtros['status']??'')===$slug?'is-active':'' ?>">
      <?= $st['label'] ?>
      <span class="fc-badge"><?= $contagensPorStatus[$slug] ?></span>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- Tabela -->
  <div class="admin-card">
    <?php if (empty($lista)): ?>
    <div class="empty-state">
      <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="1.5" stroke-linecap="round">
        <polyline points="17 1 21 5 17 9"/>
        <path d="M3 11V9a4 4 0 014-4h14"/>
        <polyline points="7 23 3 19 7 15"/>
        <path d="M21 13v2a4 4 0 01-4 4H3"/>
      </svg>
      <strong>Nenhuma solicitação encontrada</strong>
    </div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Cliente</th>
            <th>Pedido</th>
            <th>Tipo</th>
            <th>Motivo</th>
            <th>Valor</th>
            <th>Status</th>
            <th>Data</th>
            <th class="text-right">Ação</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($lista as $s):
            $st = $statusLabels[$s['status']] ?? ['cor'=>'info','label'=>$s['status']];
            $urgente = in_array($s['status'], $prioritarios);
          ?>
          <tr <?= $urgente ? 'style="background:#fffbeb;"' : '' ?>>
            <td>
              <span style="font-family:'SF Mono',monospace;font-size:13px;font-weight:700;">
                #<?= (int)$s['id'] ?>
              </span>
              <?php if ($urgente): ?>
                <span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#f59e0b;margin-left:4px;"></span>
              <?php endif; ?>
            </td>
            <td>
              <div class="td-main"><?= View::e($s['cliente_nome']) ?></div>
              <small class="txt-muted"><?= View::e($s['cliente_email']) ?></small>
            </td>
            <td>
              <a href="<?= ADMIN_URL ?>/pedidos/<?= (int)$s['pedido_id'] ?>"
                 class="link-subtle" style="font-family:'SF Mono',monospace;">
                #<?= View::e($s['pedido_codigo'] ?? '') ?>
              </a>
            </td>
            <td>
              <span class="badge badge-<?= $s['tipo']==='troca'?'info':'primary' ?>">
                <?= ucfirst($s['tipo']) ?>
              </span>
            </td>
            <td>
              <small><?= View::e($s['motivo_label']) ?></small>
            </td>
            <td>
              <strong><?= PriceHelper::format((float)$s['valor_solicitado']) ?></strong>
              <?php if ($s['valor_aprovado'] && $s['valor_aprovado'] != $s['valor_solicitado']): ?>
                <div><small class="txt-muted">Aprovado: <?= PriceHelper::format((float)$s['valor_aprovado']) ?></small></div>
              <?php endif; ?>
            </td>
            <td>
              <span class="badge badge-<?= $st['cor'] ?>"><?= $st['label'] ?></span>
            </td>
            <td>
              <small><?= date('d/m/Y', strtotime($s['criado_em'])) ?></small>
              <div><small class="txt-muted"><?= date('H:i', strtotime($s['criado_em'])) ?></small></div>
            </td>
            <td class="text-right">
              <a href="<?= ADMIN_URL ?>/devolucoes/<?= (int)$s['id'] ?>"
                 class="btn-icon" title="Ver detalhes">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2.5" stroke-linecap="round">
                  <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
                  <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
                </svg>
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
        <a href="?<?= http_build_query(array_merge($filtros, ['page' => $i])) ?>"
           class="pagination-item <?= $page===$i?'is-active':'' ?>">
          <?= $i ?>
        </a>
      <?php endfor; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<script>
</script>