<?php
// views/admin/cupons/historico.php
// Histórico + auditoria de um cupom específico
$tipoLabel = [
    'percentual'=>'Percentual','fixo'=>'Fixo','frete_gratis'=>'Frete grátis',
    'progressivo'=>'Progressivo','automatico'=>'Automático','exclusivo'=>'Exclusivo',
    'primeira_compra'=>'Primeira compra','campanha'=>'Campanha','recuperacao_carrinho'=>'Rec. carrinho',
];
$statusLabel = [
    'simulado'=>'Simulado','reservado'=>'Reservado','aplicado'=>'Aplicado',
    'confirmado'=>'Confirmado','cancelado'=>'Cancelado','expirado'=>'Expirado','estornado'=>'Estornado',
];
$statusBadge = [
    'confirmado'=>'badge-green','reservado'=>'badge-yellow','aplicado'=>'badge-blue',
    'cancelado'=>'badge-gray','expirado'=>'badge-gray','estornado'=>'badge-red','simulado'=>'badge-gray',
];
?>
<div class="admin-page">
  <div class="admin-page-header">
    <div>
      <a href="<?= BASE_URL ?>/admin/cupons" class="back-link">← Cupons</a>
      <h1 class="admin-page-title">
        <code class="coupon-code-badge coupon-code-badge--lg"><?= View::e($cupom['codigo']) ?></code>
        &nbsp;Histórico de uso
      </h1>
    </div>
    <a href="<?= BASE_URL ?>/admin/cupons/form?id=<?= (int)$cupom['id'] ?>" class="btn btn-outline btn-sm">Editar cupom</a>
  </div>

  <!-- Resumo do cupom -->
  <div class="stats-grid" style="grid-template-columns:repeat(4,1fr)">
    <div class="stat-card">
      <div class="stat-card-body">
        <span class="stat-card-value"><?= (int)$cupom['total_usos'] ?></span>
        <span class="stat-card-label">Usos confirmados</span>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-card-body">
        <span class="stat-card-value"><?= PriceHelper::format((float)$cupom['total_desconto_concedido']) ?></span>
        <span class="stat-card-label">Desconto total gerado</span>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-card-body">
        <span class="stat-card-value"><?= (int)$cupom['total_recusas'] ?></span>
        <span class="stat-card-label">Tentativas recusadas</span>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-card-body">
        <?php
          $taxa = ($cupom['total_usos'] + $cupom['total_recusas']) > 0
            ? round($cupom['total_usos'] / ($cupom['total_usos'] + $cupom['total_recusas']) * 100, 1)
            : 0;
        ?>
        <span class="stat-card-value"><?= $taxa ?>%</span>
        <span class="stat-card-label">Taxa de aprovação</span>
      </div>
    </div>
  </div>

  <!-- Abas -->
  <div class="admin-tabs">
    <button class="admin-tab is-active" data-tab="usos">Usos (<?= count($historico) ?>)</button>
    <button class="admin-tab" data-tab="auditoria">Auditoria (<?= count($auditoria) ?>)</button>
  </div>

  <!-- Tab: Usos -->
  <div class="admin-tab-panel is-active" id="tab-usos">
    <div class="admin-card">
      <?php if (empty($historico)): ?>
      <p class="txt-muted p-4">Nenhum uso registrado ainda.</p>
      <?php else: ?>
      <div class="table-wrap">
        <table class="admin-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Cliente</th>
              <th>Pedido</th>
              <th>Vendedor</th>
              <th>Valor original</th>
              <th>Desconto</th>
              <th>Valor final</th>
              <th class="text-center">Status</th>
              <th>Data</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($historico as $uso): ?>
            <tr>
              <td><small class="txt-muted">#<?= $uso['id'] ?></small></td>
              <td>
                <div class="td-main"><?= View::e($uso['cliente_nome'] ?? '—') ?></div>
                <small class="txt-muted"><?= View::e($uso['cliente_email'] ?? '') ?></small>
              </td>
              <td>
                <?php if ($uso['pedido_id']): ?>
                <a href="<?= BASE_URL ?>/admin/pedidos/<?= $uso['pedido_id'] ?>" class="link-subtle">
                  #<?= $uso['pedido_id'] ?>
                </a>
                <?php else: ?>—<?php endif; ?>
              </td>
              <td><?= View::e($uso['vendedor_nome'] ?? '—') ?></td>
              <td><?= PriceHelper::format((float)$uso['valor_original']) ?></td>
              <td class="txt-green">−<?= PriceHelper::format((float)$uso['valor_desconto'] + (float)$uso['valor_frete_desc']) ?></td>
              <td><strong><?= PriceHelper::format((float)$uso['valor_final']) ?></strong></td>
              <td class="text-center">
                <span class="badge <?= $statusBadge[$uso['status']] ?? 'badge-gray' ?>">
                  <?= $statusLabel[$uso['status']] ?? $uso['status'] ?>
                </span>
              </td>
              <td><small><?= date('d/m/Y H:i', strtotime($uso['criado_em'])) ?></small></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Tab: Auditoria -->
  <div class="admin-tab-panel" id="tab-auditoria" hidden>
    <div class="admin-card">
      <?php if (empty($auditoria)): ?>
      <p class="txt-muted p-4">Nenhum registro de auditoria.</p>
      <?php else: ?>
      <div class="table-wrap">
        <table class="admin-table admin-table--compact">
          <thead>
            <tr>
              <th>Data</th>
              <th>Ação</th>
              <th class="text-center">Resultado</th>
              <th>Cliente / IP</th>
              <th>Valor</th>
              <th>Desconto</th>
              <th>Motivo de recusa</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($auditoria as $a): ?>
            <tr class="<?= $a['resultado'] === 'recusado' ? 'row-muted' : '' ?>">
              <td><small><?= date('d/m H:i', strtotime($a['criado_em'])) ?></small></td>
              <td><span class="badge badge-gray"><?= $a['acao'] ?></span></td>
              <td class="text-center">
                <?php if ($a['resultado'] === 'aprovado'): ?>
                  <span class="dot dot--green"></span>
                <?php else: ?>
                  <span class="dot dot--red"></span>
                <?php endif; ?>
              </td>
              <td>
                <small><?= View::e($a['cliente_email'] ?? $a['ip'] ?? '—') ?></small>
              </td>
              <td><small><?= $a['valor_carrinho'] ? PriceHelper::format((float)$a['valor_carrinho']) : '—' ?></small></td>
              <td><small class="txt-green"><?= $a['valor_desconto'] ? '−' . PriceHelper::format((float)$a['valor_desconto']) : '—' ?></small></td>
              <td><small class="txt-red"><?= View::e($a['motivo_recusa'] ?? '') ?></small></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
