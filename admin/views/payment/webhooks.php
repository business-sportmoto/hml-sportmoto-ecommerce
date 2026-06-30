<?php
/**
 * admin/views/admin/payment/webhooks.php
 *
 * Variáveis:
 *   $resultado, $filtros, $tipos
 */
$base = defined('BASE_URL') ? BASE_URL : '';
require_once __DIR__ . '/_helpers.php';
?>
<link rel="stylesheet" href="<?= $base ?>/public/css/payment-admin.css?v=1">

<div class="pgto_wrapper">

  <div class="pgto_header">
    <div>
      <h1>Webhooks</h1>
      <p class="pgto_sub">
        <?= number_format((int) $resultado['total'], 0, ',', '.') ?> evento(s)
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
        <label>Tipo</label>
        <select name="tipo">
          <option value="">Todos</option>
          <?php foreach ($tipos as $t): ?>
            <option value="<?= htmlspecialchars($t) ?>" <?= ($filtros['tipo'] ?? '') === $t ? 'selected' : '' ?>>
              <?= htmlspecialchars($t) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="pgto_filtro">
        <label>Processado</label>
        <select name="processado">
          <option value="">Todos</option>
          <option value="1" <?= ($filtros['processado'] ?? '') === '1' ? 'selected' : '' ?>>Sim</option>
          <option value="0" <?= ($filtros['processado'] ?? '') === '0' ? 'selected' : '' ?>>Não (pendente / erro)</option>
        </select>
      </div>
      <div class="pgto_filtro">
        <label>Assinatura válida</label>
        <select name="assinatura">
          <option value="">Todos</option>
          <option value="1" <?= ($filtros['assinatura_valida'] ?? '') === '1' ? 'selected' : '' ?>>Sim</option>
          <option value="0" <?= ($filtros['assinatura_valida'] ?? '') === '0' ? 'selected' : '' ?>>Não (rejeitado)</option>
        </select>
      </div>
      <div class="pgto_filtro">
        <label>charge_id</label>
        <input type="text" name="charge_id" value="<?= htmlspecialchars($filtros['charge_id'] ?? '') ?>">
      </div>
      <div class="pgto_filtro">
        <label>De</label>
        <input type="date" name="data_de" value="<?= htmlspecialchars($filtros['data_de'] ?? '') ?>">
      </div>
      <div class="pgto_filtro">
        <label>Até</label>
        <input type="date" name="data_ate" value="<?= htmlspecialchars($filtros['data_ate'] ?? '') ?>">
      </div>
    </div>
    <div class="pgto_filtros_actions">
      <button type="submit" class="pgto_btn pgto_btn_primary">Filtrar</button>
      <a href="<?= $base ?>/admin/payment/webhooks" class="pgto_btn pgto_btn_ghost">Limpar</a>
    </div>
  </form>

  <!-- Tabela -->
  <div class="pgto_card pgto_table_card">
    <table class="pgto_table">
      <thead>
        <tr>
          <th>ID</th>
          <th>Tipo</th>
          <th>charge_id</th>
          <th>Sig.</th>
          <th>Processado</th>
          <th>Tent.</th>
          <th>Recebido</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($resultado['itens'])): ?>
          <tr><td colspan="8" class="pgto_empty">Nenhum webhook encontrado.</td></tr>
        <?php else: ?>
          <?php foreach ($resultado['itens'] as $w): ?>
            <tr>
              <td><code>#<?= (int) $w['id'] ?></code></td>
              <td><code class="pgto_tipo_wh"><?= htmlspecialchars($w['tipo']) ?></code></td>
              <td>
                <?php if (!empty($w['charge_id'])): ?>
                  <code><?= htmlspecialchars(substr($w['charge_id'], 0, 8)) ?>…</code>
                <?php else: ?>
                  <span class="pgto_muted">—</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ((int) $w['assinatura_valida'] === 1): ?>
                  <span class="pgto_pill pgto_pill_ok" title="Assinatura Ed25519 validada">✓</span>
                <?php elseif ((int) $w['assinatura_valida'] === 0): ?>
                  <span class="pgto_pill pgto_pill_err" title="Assinatura inválida">✗</span>
                <?php else: ?>
                  <span class="pgto_muted">?</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ((int) $w['processado'] === 1): ?>
                  <span class="pgto_pill pgto_pill_ok">processado</span>
                <?php elseif (!empty($w['erro'])): ?>
                  <span class="pgto_pill pgto_pill_err" title="<?= htmlspecialchars($w['erro']) ?>">erro</span>
                <?php else: ?>
                  <span class="pgto_pill pgto_pill_warn">pendente</span>
                <?php endif; ?>
              </td>
              <td class="num"><?= (int) $w['tentativas'] ?></td>
              <td>
                <span title="<?= htmlspecialchars($w['recebido_em']) ?>">
                  <?= date('d/m H:i:s', strtotime($w['recebido_em'])) ?>
                </span>
              </td>
              <td class="actions">
                <a href="<?= $base ?>/admin/payment/webhooks/<?= (int) $w['id'] ?>"
                   class="pgto_link_chevron">ver
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
