<?php
// views/admin/cupons/index.php
// Lista de cupons com filtros, stats, ações inline
$tipos = [
    'percentual' => 'Percentual', 'fixo' => 'Fixo', 'frete_gratis' => 'Frete grátis',
    'progressivo' => 'Progressivo', 'automatico' => 'Automático',
    'exclusivo' => 'Exclusivo', 'primeira_compra' => 'Primeira compra',
    'campanha' => 'Campanha', 'recuperacao_carrinho' => 'Rec. carrinho',
];
$tiposBadge = [
    'percentual' => 'badge-blue', 'fixo' => 'badge-purple', 'frete_gratis' => 'badge-green',
    'progressivo' => 'badge-orange', 'automatico' => 'badge-teal', 'exclusivo' => 'badge-pink',
    'primeira_compra' => 'badge-yellow', 'campanha' => 'badge-indigo', 'recuperacao_carrinho' => 'badge-red',
];
?>
<div class="admin-page">

  <!-- Header -->
  <div class="admin-page-header">
    <div>
      <h1 class="admin-page-title">Cupons de desconto</h1>
      <p class="admin-page-sub">Gerencie, monitore e audite todos os cupons.</p>
    </div>
    <div class="admin-badge">
      <a href="<?= BASE_URL ?>/admin/cupons/relatorio" class="btn btn-ghost">
        <?= IconLibrary::render('bar_chart_4_bars', 'icon icon--md') ?>
        Relatórios
      </a>
      <a href="<?= BASE_URL ?>/admin/cupons/form" class="btn btn-primary">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Criar cupom
      </a>      
    </div>
  </div>

  <!-- Stats Cards -->
  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-card-icon stat-card-icon--green">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
      </div>
      <div class="stat-card-body">
        <span class="stat-card-value"><?= $stats['ativos'] ?? 0 ?></span>
        <span class="stat-card-label">Cupons ativos</span>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-card-icon stat-card-icon--blue">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
      </div>
      <div class="stat-card-body">
        <span class="stat-card-value"><?= $stats['usos_hoje'] ?? 0 ?></span>
        <span class="stat-card-label">Usos hoje</span>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-card-icon stat-card-icon--purple">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
      </div>
      <div class="stat-card-body">
        <span class="stat-card-value"><?= PriceHelper::format($stats['desconto_total'] ?? 0) ?></span>
        <span class="stat-card-label">Desconto total concedido</span>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-card-icon stat-card-icon--red">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
      </div>
      <div class="stat-card-body">
        <span class="stat-card-value"><?= $stats['recusas_hoje'] ?? 0 ?></span>
        <span class="stat-card-label">Recusas hoje</span>
      </div>
    </div>
  </div>

  <!-- Filtros -->
  <form method="GET" class="admin-filters">
    <div class="filter-row">
      <div class="filter-group filter-group--search">
        <input type="text" name="busca" class="form-control" placeholder="Buscar por código ou nome…"
               value="<?= View::e($filtros['busca'] ?? '') ?>">
      </div>
      <div class="filter-group">
        <select name="tipo" class="form-control">
          <option value="">Todos os tipos</option>
          <?php foreach ($tipos as $k => $v): ?>
          <option value="<?= $k ?>" <?= ($filtros['tipo'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filter-group">
        <select name="ativo" class="form-control">
          <option value="">Status</option>
          <option value="1" <?= ($filtros['ativo'] ?? '') === '1' ? 'selected' : '' ?>>Ativos</option>
          <option value="0" <?= ($filtros['ativo'] ?? '') === '0' ? 'selected' : '' ?>>Inativos</option>
        </select>
      </div>
      <button type="submit" class="btn btn-outline">Filtrar</button>
      <?php if (array_filter($filtros)): ?>
      <a href="<?= BASE_URL ?>/admin/cupons" class="btn btn-ghost">Limpar</a>
      <?php endif; ?>
    </div>
  </form>

  <!-- Tabela -->
  <div class="admin-card">
    <?php if (empty($cupons)): ?>
    <div class="empty-state">
      <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/></svg>
      <strong>Nenhum cupom encontrado</strong>
      <a href="<?= BASE_URL ?>/admin/cupons/form" class="btn btn-primary btn-sm">Criar primeiro cupom</a>
    </div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th>Código</th>
            <th>Nome / Tipo</th>
            <th>Desconto</th>
            <th>Validade</th>
            <th class="text-center">Usos</th>
            <th>Vendedor</th>
            <th class="text-center">Status</th>
            <th class="text-right">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($cupons as $c):
            $hoje     = date('Y-m-d H:i:s');
            $expirado = $c['data_fim'] && $c['data_fim'] < $hoje;
            $agendado = $c['data_inicio'] && $c['data_inicio'] > $hoje;
            $semLimite= $c['limite_total'] === null;
            $usado    = !$semLimite && (int)$c['usos_confirmados'] >= (int)$c['limite_total'];
          ?>
          <tr class="<?= (!$c['ativo'] || $expirado || $usado) ? 'row-muted' : '' ?>">
            <td>
              <code class="coupon-code-badge"><?= View::e($c['codigo']) ?></code>
            </td>
            <td>
              <div class="td-main"><?= View::e($c['nome']) ?></div>
              <span class="badge <?= $tiposBadge[$c['tipo']] ?? 'badge-gray' ?>">
                <?= $tipos[$c['tipo']] ?? $c['tipo'] ?>
              </span>
            </td>
            <td>
              <?php if ($c['tipo'] === 'percentual' || $c['tipo'] === 'primeira_compra'): ?>
                <strong><?= number_format((float)$c['valor'], 0) ?>%</strong>
              <?php elseif ($c['tipo'] === 'fixo'): ?>
                <strong><?= PriceHelper::format((float)$c['valor']) ?></strong>
              <?php elseif ($c['tipo'] === 'frete_gratis'): ?>
                <span class="txt-success">Frete grátis</span>
              <?php elseif ($c['tipo'] === 'progressivo'): ?>
                <span class="txt-muted">Progressivo</span>
              <?php else: ?>
                <?= $c['valor'] ? PriceHelper::format((float)$c['valor']) : '—' ?>
              <?php endif; ?>
              <?php if ($c['valor_maximo']): ?>
                <small class="txt-muted">teto <?= PriceHelper::format((float)$c['valor_maximo']) ?></small>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($c['data_inicio'] || $c['data_fim']): ?>
                <div class="td-dates">
                  <?php if ($c['data_inicio']): ?>
                    <span><?= date('d/m/Y', strtotime($c['data_inicio'])) ?></span>
                  <?php endif; ?>
                  <?php if ($c['data_fim']): ?>
                    <span class="<?= $expirado ? 'txt-red' : '' ?>">
                      até <?= date('d/m/Y', strtotime($c['data_fim'])) ?>
                    </span>
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <span class="txt-muted">Sem validade</span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <div class="uso-count">
                <strong><?= (int)$c['usos_confirmados'] ?></strong>
                <?php if (!$semLimite): ?>
                  <span class="txt-muted">/ <?= (int)$c['limite_total'] ?></span>
                <?php else: ?>
                  <span class="txt-muted">/ ∞</span>
                <?php endif; ?>
              </div>
              <?php if (!$semLimite): ?>
              <div class="uso-bar">
                <div class="uso-bar-fill <?= $usado ? 'uso-bar-fill--full' : '' ?>"
                     style="width:<?= min(100, round(($c['usos_confirmados']/$c['limite_total'])*100)) ?>%"></div>
              </div>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($c['vendedor_nome'] ?? null): ?>
                <span class="vendedor-chip"><?= View::e($c['vendedor_nome']) ?></span>
              <?php else: ?>
                <span class="txt-muted">—</span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <?php if ($expirado): ?>
                <span class="status-badge status-badge--gray">Expirado</span>
              <?php elseif ($usado): ?>
                <span class="status-badge status-badge--gray">Esgotado</span>
              <?php elseif ($agendado): ?>
                <span class="status-badge status-badge--yellow">Agendado</span>
              <?php elseif ($c['ativo']): ?>
                <span class="status-badge status-badge--green">Ativo</span>
              <?php else: ?>
                <span class="status-badge status-badge--red">Pausado</span>
              <?php endif; ?>
            </td>
            <td class="text-right">
              <div class="action-btns">
                <!-- Pausar/Ativar -->
                <button type="button" class="btn-icon btn-icon--toggle"
                        data-id="<?= (int)$c['id'] ?>"
                        data-ativo="<?= (int)$c['ativo'] ?>"
                        title="<?= $c['ativo'] ? 'Pausar' : 'Ativar' ?>">
                  <?php if ($c['ativo']): ?>
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>
                  <?php else: ?>
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                  <?php endif; ?>
                </button>
                <!-- Relatório -->
                <a href="<?= BASE_URL ?>/admin/cupons/historico?id=<?= $c['id'] ?>"
                   class="btn-icon" title="Histórico">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                </a>
                <!-- Editar -->
                <a href="<?= BASE_URL ?>/admin/cupons/form?id=<?= $c['id'] ?>"
                   class="btn-icon" title="Editar">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                </a>
                <!-- Excluir -->
                <button type="button" class="btn-icon btn-icon--danger btn-delete"
                        data-id="<?= (int)$c['id'] ?>"
                        data-nome="<?= View::e($c['codigo']) ?>"
                        title="Excluir">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Paginação -->
    <?php if ($pages > 1): ?>
    <div class="pagination">
      <?php for ($i = 1; $i <= $pages; $i++): ?>
      <a href="?<?= http_build_query(array_merge($filtros, ['page' => $i])) ?>"
         class="pagination-item <?= $page === $i ? 'is-active' : '' ?>"><?= $i ?></a>
      <?php endfor; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
