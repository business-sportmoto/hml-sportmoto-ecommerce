<?php
/**
 * View: etiqueta de separação para bobina térmica 80mm.
 *
 * Uma etiqueta por pedido, com quebra de página entre elas — o operador manda
 * o lote e a impressora corta uma a uma.
 *
 * O QR carrega só o ID do pedido, que é o que a tela de conferência espera. O
 * ID também vai impresso ao lado, para o caso de o QR não ler (bobina gasta,
 * leitor sujo) — sem isso, uma etiqueta ilegível vira um pedido perdido.
 */
$e   = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$brl = static fn($v) => 'R$ ' . number_format((float)$v, 2, ',', '.');
?>

<?php if (!empty($erro)): ?>
  <div class="imp_aviso"><?= $e($erro) ?></div>
  <?php return; ?>
<?php endif; ?>

<?php if (empty($pedidos)): ?>
  <div class="imp_aviso">Nenhum pedido encontrado para impressão.</div>
  <?php return; ?>
<?php endif; ?>

<?php foreach ($pedidos as $p): ?>
<div class="imp_etiqueta">

  <div class="imp_topo">
    <div class="imp_qr"><?= QrHelper::svg((string)$p['id'], 4) ?></div>
    <div class="imp_topo_txt">
      <div class="imp_pedido_num">#<?= (int)$p['id'] ?></div>
      <div class="imp_pedido_cod"><?= $e($p['codigo']) ?></div>
      <div class="imp_data"><?= $e(date('d/m/Y H:i', strtotime((string)$p['criado_em']))) ?></div>
    </div>
  </div>

  <div class="imp_sep"></div>

  <div class="imp_bloco">
    <div class="imp_rotulo">Cliente</div>
    <div class="imp_forte"><?= $e($p['nome_destinatario'] ?: $p['cliente_nome']) ?></div>
    <?php if (!empty($p['logradouro'])): ?>
      <div class="imp_end">
        <?= $e($p['logradouro']) ?>, <?= $e($p['numero'] ?: 's/n') ?>
        <?= !empty($p['complemento']) ? ' — ' . $e($p['complemento']) : '' ?><br>
        <?= $e($p['bairro']) ?> · <?= $e($p['cidade']) ?>/<?= $e($p['estado']) ?><br>
        CEP <?= $e($p['cep']) ?>
      </div>
    <?php endif; ?>
  </div>

  <?php if (!empty($p['frete_servico'])): ?>
    <div class="imp_bloco imp_frete"><?= $e($p['frete_servico']) ?></div>
  <?php endif; ?>

  <div class="imp_sep"></div>

  <div class="imp_rotulo">Itens a separar</div>
  <table class="imp_itens">
    <?php $pecas = 0; foreach ($p['itens'] as $i): $pecas += (int)$i['quantidade']; ?>
      <tr>
        <td class="imp_qtd"><?= (int)$i['quantidade'] ?>x</td>
        <td>
          <div class="imp_item_nome"><?= $e($i['nome']) ?></div>
          <?php if (!empty($i['variacao_texto'])): ?>
            <div class="imp_item_var"><?= $e($i['variacao_texto']) ?></div>
          <?php endif; ?>
          <div class="imp_item_sku">
            <?php if (!empty($i['sku_real'])): ?>
              SKU <?= $e($i['sku_real']) ?>
            <?php else: ?>
              <span class="imp_alerta">sem SKU cadastrado</span>
            <?php endif; ?>
            <?php if (empty($i['ean'])): ?>
              <span class="imp_alerta"> · sem EAN</span>
            <?php endif; ?>
          </div>
        </td>
        <td class="imp_preco"><?= $brl($i['preco_unitario']) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>

  <div class="imp_sep"></div>

  <div class="imp_totais">
    <div><span><?= (int)$pecas ?> peça(s)</span><span><?= count($p['itens']) ?> linha(s)</span></div>
    <div class="imp_total"><span>Total</span><span><?= $brl($p['total']) ?></span></div>
  </div>

  <?php if (!empty($p['observacao_cliente'])): ?>
    <div class="imp_obs">
      <div class="imp_rotulo">Observação do cliente</div>
      <?= $e($p['observacao_cliente']) ?>
    </div>
  <?php endif; ?>
  <?php if (!empty($p['observacao_entrega'])): ?>
    <div class="imp_obs">
      <div class="imp_rotulo">Observação de entrega</div>
      <?= $e($p['observacao_entrega']) ?>
    </div>
  <?php endif; ?>

  <div class="imp_rodape">Separado por ____________________</div>
</div>
<?php endforeach; ?>
