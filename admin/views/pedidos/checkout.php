<?php
/**
 * View: painel de separação (checkout de expedição).
 * Recebe: $fila, $busca
 */
$e   = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$brl = static fn($v) => 'R$ ' . number_format((float)$v, 2, ',', '.');
$ico = static fn($n, $s = 16) => '<span class="log_iw" style="font-size:' . (int)$s . 'px">' . (class_exists('IconLibrary') ? IconLibrary::ref($n, '') : '') . '</span>';
?>

<div class="admin-page sep_page" id="sepPainel">

  <div class="sep_head">
    <div>
      <h1 class="admin-page-title"><?= $ico('package', 22) ?> Checkout de expedição</h1>
      <p class="sep_sub">Pedidos pagos aguardando separação. Imprimir a lista move o pedido para <strong>Em separação</strong>.</p>
    </div>
    <div class="sep_head_acoes">
      <a href="<?= BASE_URL ?>/admin/pedidos" class="btn btn-secondary"><?= $ico('arrow-back', 15) ?> Pedidos</a>
      <button type="button" class="btn btn-primary" id="sepImprimirTodos" <?= empty($fila) ? 'disabled' : '' ?>>
        <?= $ico('printer', 15) ?> Imprimir envios (<span id="sepCount"><?= count($fila) ?></span>)
      </button>
    </div>
  </div>

  <form class="sep_filtros" method="get" action="<?= BASE_URL ?>/admin/pedidos/checkout">
    <input type="text" name="busca" class="form-control" placeholder="Buscar por pedido, código ou cliente..."
           value="<?= $e($busca ?? '') ?>">
    <button class="btn btn-secondary" type="submit"><?= $ico('search', 15) ?> Buscar</button>
    <?php if (!empty($busca)): ?>
      <a href="<?= BASE_URL ?>/admin/pedidos/checkout" class="btn btn-secondary">Limpar</a>
    <?php endif; ?>
  </form>

  <?php if (empty($fila)): ?>
    <div class="admin-empty-state sep_vazio">
      <?= $ico('check-circle', 34) ?>
      <h3>Nada para separar</h3>
      <p>Nenhum pedido com pagamento aprovado no momento.</p>
    </div>
  <?php else: ?>

    <div class="admin-card">
      <div class="admin-card-body sep_card_body">
        <table class="admin-table sep_tabela">
          <thead>
            <tr>
              <th class="sep_col_check"><input type="checkbox" id="sepTodos" checked></th>
              <th style="width:92px">Pedido</th>
              <th>Cliente</th>
              <th style="width:96px">Itens</th>
              <th style="width:120px">Total</th>
              <th style="width:130px">NF-e</th>
              <th style="width:190px" class="sep_col_acoes">Ações</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($fila as $p): ?>
            <tr data-id="<?= (int)$p['id'] ?>">
              <td><input type="checkbox" class="sep_check" value="<?= (int)$p['id'] ?>" checked></td>
              <td>
                <div class="sep_num">#<?= (int)$p['id'] ?></div>
                <div class="sep_cod"><?= $e($p['codigo']) ?></div>
              </td>
              <td>
                <div class="sep_cliente"><?= $e($p['cliente_nome']) ?></div>
                <div class="sep_meta"><?= $e(date('d/m/Y H:i', strtotime((string)$p['criado_em']))) ?><?= $p['frete_servico'] ? ' · ' . $e($p['frete_servico']) : '' ?></div>
              </td>
              <td>
                <span class="sep_pecas"><?= (int)$p['itens_pecas'] ?></span>
                <span class="sep_meta">peça(s)</span>
              </td>
              <td class="sep_total"><?= $brl($p['total']) ?></td>
              <td>
                <?php if (!empty($p['nfe_numero'])): ?>
                  <span class="sep_tag sep_tag--ok">NF <?= $e($p['nfe_numero']) ?></span>
                <?php else: ?>
                  <span class="sep_tag sep_tag--espera">aguardando</span>
                <?php endif; ?>
              </td>
              <td class="sep_col_acoes">
                <a class="btn btn-secondary btn-sm" href="<?= BASE_URL ?>/admin/pedidos/checkout/<?= (int)$p['id'] ?>">
                  <?= $ico('barcode-scanner', 15) ?> Conferir
                </a>
                <button type="button" class="btn btn-secondary btn-sm js-imprimir-um"
                        title="Imprimir só este" aria-label="Imprimir só este pedido">
                  <?= $ico('printer', 15) ?>
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

  <?php endif; ?>
</div>

<script>
  window.SEP_BASE = '<?= BASE_URL ?>/admin/pedidos/checkout';
</script>
