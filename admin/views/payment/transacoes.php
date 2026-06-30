<?php
/**
 * admin/views/admin/payment/transacoes.php — Lista de transações
 *
 * Variáveis:
 *   $resultado  → ['itens','total','pagina','paginas','por_pagina']
 *   $filtros    → array de filtros aplicados (pré-preenche o form)
 *   $provedores → array de strings (provedores distintos)
 */
$base = defined('BASE_URL') ? BASE_URL : '';
require_once __DIR__ . '/_helpers.php';
?>
<link rel="stylesheet" href="<?= $base ?>/public/css/payment-admin.css?v=1">

<div class="pgto_wrapper" data-base="<?= htmlspecialchars($base) ?>">

  <!-- Cabeçalho -->
  <div class="pgto_header">
    <div>
      <h1>Transações</h1>
      <p class="pgto_sub">
        <?= number_format((int) $resultado['total'], 0, ',', '.') ?> resultado(s)
        — página <?= (int) $resultado['pagina'] ?> de <?= (int) $resultado['paginas'] ?>
      </p>
    </div>
    <div class="pgto_actions">
      <a href="<?= $base ?>/admin/payment" class="pgto_btn pgto_btn_ghost">← Voltar ao painel</a>
    </div>
  </div>

  <!-- Filtros -->
  <form method="get" class="pgto_card pgto_filtros">
    <div class="pgto_filtros_grid">
      <div class="pgto_filtro">
        <label>Busca</label>
        <input type="text" name="busca" value="<?= htmlspecialchars($filtros['busca'] ?? '') ?>"
               placeholder="order_id, charge_id ou provedor">
      </div>
      <div class="pgto_filtro">
        <label>Status</label>
        <select name="status">
          <option value="">Todos</option>
          <?php foreach (['pendente','pre_autorizado','aprovado','recusado','falhou','cancelado','estornado','estorno_pendente','chargeback','erro'] as $s): ?>
            <option value="<?= $s ?>" <?= ($filtros['status'] ?? '') === $s ? 'selected' : '' ?>><?= $s ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="pgto_filtro">
        <label>Método</label>
        <select name="metodo">
          <option value="">Todos</option>
          <?php foreach (['pix','boleto','cartao'] as $m): ?>
            <option value="<?= $m ?>" <?= ($filtros['metodo'] ?? '') === $m ? 'selected' : '' ?>><?= $m ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if (!empty($provedores)): ?>
      <div class="pgto_filtro">
        <label>Provedor</label>
        <select name="provedor">
          <option value="">Todos</option>
          <?php foreach ($provedores as $p): ?>
            <option value="<?= htmlspecialchars($p) ?>" <?= ($filtros['provedor_real'] ?? '') === $p ? 'selected' : '' ?>>
              <?= htmlspecialchars($p) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="pgto_filtro">
        <label>De</label>
        <input type="date" name="data_de" value="<?= htmlspecialchars($filtros['data_de'] ?? '') ?>">
      </div>
      <div class="pgto_filtro">
        <label>Até</label>
        <input type="date" name="data_ate" value="<?= htmlspecialchars($filtros['data_ate'] ?? '') ?>">
      </div>
      <div class="pgto_filtro">
        <label>Valor min. (R$)</label>
        <input type="number" step="0.01" name="valor_min" value="<?= htmlspecialchars($filtros['valor_min'] ?? '') ?>">
      </div>
      <div class="pgto_filtro">
        <label>Valor max. (R$)</label>
        <input type="number" step="0.01" name="valor_max" value="<?= htmlspecialchars($filtros['valor_max'] ?? '') ?>">
      </div>
    </div>
    <div class="pgto_filtros_actions">
      <button type="submit" class="pgto_btn pgto_btn_primary">Filtrar</button>
      <a href="<?= $base ?>/admin/payment/transacoes" class="pgto_btn pgto_btn_ghost">Limpar</a>
    </div>
  </form>

  <!-- Tabela -->
  <div class="pgto_card pgto_table_card">
    <table class="pgto_table">
      <thead>
        <tr>
          <th>ID</th>
          <th>Pedido</th>
          <th>Método</th>
          <th>Status</th>
          <th class="num">Valor</th>
          <th>Provedor</th>
          <th>Criada</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($resultado['itens'])): ?>
          <tr><td colspan="8" class="pgto_empty">Nenhuma transação encontrada para os filtros aplicados.</td></tr>
        <?php else: ?>
          <?php foreach ($resultado['itens'] as $t): ?>
            <tr>
              <td><code>#<?= (int) $t['id'] ?></code></td>
              <td>
                <strong><?= htmlspecialchars($t['order_id_loja']) ?></strong>
                <?php if (!empty($t['charge_id'])): ?>
                  <br><small class="pgto_muted"><?= htmlspecialchars($t['charge_id']) ?></small>
                <?php endif; ?>
              </td>
              <td>
                <?= htmlspecialchars(ucfirst($t['metodo'])) ?>
                <?php if (!empty($t['parcelas']) && (int) $t['parcelas'] > 1): ?>
                  <small class="pgto_muted">· <?= (int) $t['parcelas'] ?>x</small>
                <?php endif; ?>
              </td>
              <td>
                <span class="pgto_status pgto_status_<?= htmlspecialchars($t['status']) ?>">
                  <?= htmlspecialchars($t['status']) ?>
                </span>
              </td>
              <td class="num"><?= pgto_money((int) $t['valor_centavos']) ?></td>
              <td><?= htmlspecialchars($t['provedor_real'] ?? '—') ?></td>
              <td>
                <span title="<?= htmlspecialchars($t['criado_em']) ?>">
                  <?= date('d/m H:i', strtotime($t['criado_em'])) ?>
                </span>
              </td>
              <td class="actions">
                <a href="<?= $base ?>/admin/payment/transacoes/<?= (int) $t['id'] ?>"
                   class="pgto_link_chevron">detalhes
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                       stroke-width="2.5" stroke-linecap="round"><polyline points="9 18 15 12 9 6"/></svg>
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Paginação -->
  <?php if ((int) $resultado['paginas'] > 1): ?>
    <div class="pgto_pagination">
      <?php
        $atual    = (int) $resultado['pagina'];
        $paginas  = (int) $resultado['paginas'];
        $queryBase = $_GET;
        unset($queryBase['pagina']);
        $qs = http_build_query($queryBase);
        $qs = $qs ? '&' . $qs : '';

        $primeira = max(1, $atual - 2);
        $ultima   = min($paginas, $atual + 2);
      ?>
      <?php if ($atual > 1): ?>
        <a href="?pagina=<?= $atual - 1 . $qs ?>" class="pgto_btn pgto_btn_ghost">‹ anterior</a>
      <?php endif; ?>

      <?php for ($i = $primeira; $i <= $ultima; $i++): ?>
        <a href="?pagina=<?= $i . $qs ?>"
           class="pgto_btn <?= $i === $atual ? 'pgto_btn_primary' : 'pgto_btn_ghost' ?>"><?= $i ?></a>
      <?php endfor; ?>

      <?php if ($atual < $paginas): ?>
        <a href="?pagina=<?= $atual + 1 . $qs ?>" class="pgto_btn pgto_btn_ghost">próxima ›</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>

</div>
