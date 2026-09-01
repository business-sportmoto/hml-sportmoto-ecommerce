<?php
/**
 * View: conferência de um pedido (checkout de expedição).
 * Recebe: $pedido (com itens, etiqueta, nfe_ok), $etiquetaOk
 *
 * A conferência vive no navegador: bipar não grava nada no banco, é o operador
 * batendo o que está na caixa contra o que o pedido pede. O que persiste é o
 * resultado — a etiqueta emitida no fim.
 */
$e   = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$brl = static fn($v) => 'R$ ' . number_format((float)$v, 2, ',', '.');
$ico = static fn($n, $s = 16) => '<span class="log_iw" style="font-size:' . (int)$s . 'px">' . (class_exists('IconLibrary') ? IconLibrary::ref($n, '') : '') . '</span>';

$p      = $pedido;
$pecas  = 0;
foreach ($p['itens'] as $i) { $pecas += (int)$i['quantidade']; }
?>

<div class="admin-page sep_page" id="sepConf" data-pedido="<?= (int)$p['id'] ?>">

  <div class="sep_head">
    <div>
      <h1 class="admin-page-title"><?= $ico('barcode-scanner', 22) ?> Conferência #<?= (int)$p['id'] ?></h1>
      <p class="sep_sub"><?= $e($p['codigo']) ?> · <?= $e($p['cliente_nome']) ?></p>
    </div>
    <div class="sep_head_acoes">
      <a href="<?= BASE_URL ?>/admin/pedidos/checkout" class="btn btn-secondary"><?= $ico('arrow-back', 15) ?> Fila</a>
      <a href="<?= BASE_URL ?>/admin/pedidos/<?= (int)$p['id'] ?>" class="btn btn-secondary">Ver pedido</a>
    </div>
  </div>

  <div class="sep_grid">

    <!-- ── coluna: conferência ─────────────────────────── -->
    <div class="sep_col_principal">

      <div class="admin-card">
        <div class="admin-card-body">
          <div class="sep_bipar">
            <label for="sepCodigo" class="sep_bipar_label">Bipe o código de barras do produto</label>
            <input type="text" id="sepCodigo" class="form-control sep_bipar_input"
                   placeholder="EAN ou SKU" autocomplete="off" autofocus>
            <div class="sep_bipar_dica">O leitor digita e dá Enter sozinho. Também aceita o SKU digitado à mão.</div>
          </div>

          <div class="sep_progresso">
            <div class="sep_progresso_barra"><div class="sep_progresso_fill" id="sepFill"></div></div>
            <div class="sep_progresso_txt">
              <strong id="sepConferidas">0</strong> de <strong><?= (int)$pecas ?></strong> peça(s) conferida(s)
            </div>
          </div>

          <table class="admin-table sep_itens">
            <thead>
              <tr>
                <th style="width:70px">Conf.</th>
                <th>Item</th>
                <th style="width:130px">SKU / EAN</th>
                <th style="width:70px">Qtd</th>
                <th style="width:110px">Preço</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($p['itens'] as $i): ?>
              <tr class="sep_item" data-item="<?= (int)$i['id'] ?>"
                  data-qtd="<?= (int)$i['quantidade'] ?>"
                  data-ean="<?= $e($i['ean']) ?>">
                <td class="sep_item_conf">
                  <span class="sep_contador"><span class="sep_c">0</span>/<?= (int)$i['quantidade'] ?></span>
                </td>
                <td>
                  <div class="sep_item_nome"><?= $e($i['nome']) ?></div>
                  <?php if (!empty($i['variacao_texto'])): ?>
                    <div class="sep_meta"><?= $e($i['variacao_texto']) ?></div>
                  <?php endif; ?>
                  <?php if (empty($i['ean']) && empty($i['sku_real']) && empty($i['sku_legado'])): ?>
                    <button type="button" class="btn btn-secondary btn-sm js-conferir-manual">
                      Sem código — conferir manualmente
                    </button>
                  <?php endif; ?>
                </td>
                <td class="sep_mono">
                  <?= $i['sku_real'] ? $e($i['sku_real']) : '<span class="sep_meta">—</span>' ?><br>
                  <?php if ($i['ean']): ?><?= $e($i['ean']) ?>
                  <?php elseif (!empty($i['sku_legado'])): ?>REF <?= $e($i['sku_legado']) ?>
                  <?php else: ?><span class="sep_meta">sem código</span><?php endif; ?>
                </td>
                <td class="sep_qtd"><?= (int)$i['quantidade'] ?></td>
                <td><?= $brl($i['preco_unitario']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ── coluna: dados e envio ───────────────────────── -->
    <div class="sep_col_lado">

      <div class="admin-card">
        <div class="admin-card-header"><h3>Entrega</h3></div>
        <div class="admin-card-body">
          <div class="sep_forte"><?= $e($p['nome_destinatario'] ?: $p['cliente_nome']) ?></div>
          <?php if (!empty($p['logradouro'])): ?>
            <div class="sep_end">
              <?= $e($p['logradouro']) ?>, <?= $e($p['numero'] ?: 's/n') ?><br>
              <?= $e($p['bairro']) ?> · <?= $e($p['cidade']) ?>/<?= $e($p['estado']) ?><br>
              CEP <?= $e($p['cep']) ?>
            </div>
          <?php else: ?>
            <div class="sep_meta">Sem endereço de entrega cadastrado.</div>
          <?php endif; ?>
          <?php if (!empty($p['frete_servico'])): ?>
            <div class="sep_tag sep_tag--neutro" style="margin-top:10px"><?= $e($p['frete_servico']) ?></div>
          <?php endif; ?>
        </div>
      </div>

      <div class="admin-card">
        <div class="admin-card-header"><h3>Nota fiscal</h3></div>
        <div class="admin-card-body">
          <?php if (!empty($p['nfe_ok'])): ?>
            <div class="sep_tag sep_tag--ok">NF <?= $e($p['nfe_numero']) ?>/<?= $e($p['nfe_serie']) ?></div>
            <div class="sep_meta sep_mono" style="margin-top:8px;word-break:break-all"><?= $e($p['nfe_chave']) ?></div>
            <?php if (!empty($p['nfe_danfe'])): ?>
              <a class="btn btn-secondary btn-sm" style="margin-top:10px" target="_blank"
                 rel="noopener" href="<?= $e($p['nfe_danfe']) ?>"><?= $ico('open-in-new', 15) ?> DANFE</a>
            <?php endif; ?>
          <?php else: ?>
            <div class="sep_aviso">
              <strong>Aguardando a NF-e</strong>
              <div>A nota é emitida no Bling. Enquanto ela não chega, a etiqueta fica bloqueada.</div>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="admin-card">
        <div class="admin-card-header"><h3>Etiqueta de envio</h3></div>
        <div class="admin-card-body">
          <?php if (!empty($p['etiqueta'])): ?>
            <div class="sep_tag sep_tag--ok">Etiqueta emitida</div>
            <div class="sep_mono" style="margin-top:8px"><?= $e($p['etiqueta']['codigo_rastreio']) ?></div>
            <?php if (!empty($p['etiqueta']['url_pdf'])): ?>
              <a class="btn btn-primary btn-sm" style="margin-top:10px" target="_blank"
                 rel="noopener" href="<?= $e($p['etiqueta']['url_pdf']) ?>"><?= $ico('printer', 15) ?> Imprimir etiqueta</a>
            <?php endif; ?>

          <?php elseif (empty($etiquetaOk['ok'])): ?>
            <div class="sep_aviso"><?= $e($etiquetaOk['msg'] ?? 'Etiqueta indisponível.') ?></div>

          <?php else: ?>
            <div class="sep_campo">
              <label for="sepTransp">Transportadora e serviço</label>
              <select id="sepTransp" class="form-control"><option value="">Carregando...</option></select>
            </div>
            <button type="button" class="btn btn-primary" id="sepGerarEtiqueta" style="margin-top:10px" disabled>
              <?= $ico('etiqueta', 15) ?> Gerar etiqueta
            </button>
            <div class="sep_meta" style="margin-top:8px">Emite de verdade na transportadora e tem custo.</div>
          <?php endif; ?>
        </div>
      </div>

    </div>
  </div>
</div>

<script>
  window.SEP_PEDIDO = <?= (int)$p['id'] ?>;
  window.SEP_BASE   = '<?= BASE_URL ?>/admin/pedidos/checkout';
  window.SEP_OPCOES = '<?= BASE_URL ?>/admin/pedidos/opcoes-envio';
</script>
