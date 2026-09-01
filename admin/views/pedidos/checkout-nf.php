<?php
/**
 * View: NF simplificada (romaneio) para bobina térmica 80mm.
 *
 * NÃO é documento fiscal. É o comprovante que vai dentro da caixa: o cliente
 * confere o que recebeu contra o que pediu. A NF-e de verdade é emitida no
 * Bling; quando ela existe, o número e a chave aparecem aqui para amarrar
 * um documento ao outro.
 */
$e   = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$brl = static fn($v) => 'R$ ' . number_format((float)$v, 2, ',', '.');
$p   = $pedido;
$pecas = 0;
foreach ($p['itens'] as $i) { $pecas += (int)$i['quantidade']; }
?>

<div class="imp_etiqueta">

  <div class="imp_topo">
    <div class="imp_qr"><?= QrHelper::svg((string)$p['id'], 4) ?></div>
    <div class="imp_topo_txt">
      <div class="imp_forte imp_loja"><?= $e($loja['nome'] ?? 'Loja') ?></div>
      <?php if (!empty($loja['cnpj'])): ?>
        <div class="imp_data">CNPJ <?= $e($loja['cnpj']) ?></div>
      <?php endif; ?>
      <?php if (!empty($loja['telefone'])): ?>
        <div class="imp_data"><?= $e($loja['telefone']) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="imp_sep"></div>

  <div class="imp_rotulo">Resumo do pedido</div>
  <div class="imp_pedido_num">#<?= (int)$p['id'] ?></div>
  <div class="imp_pedido_cod"><?= $e($p['codigo']) ?></div>
  <div class="imp_data"><?= $e(date('d/m/Y H:i', strtotime((string)$p['criado_em']))) ?></div>

  <?php if (!empty($p['nfe_numero'])): ?>
    <div class="imp_bloco" style="margin-top:2mm">
      <div class="imp_rotulo">Nota fiscal</div>
      <div class="imp_forte">NF <?= $e($p['nfe_numero']) ?><?= !empty($p['nfe_serie']) ? ' · série ' . $e($p['nfe_serie']) : '' ?></div>
      <?php if (!empty($p['nfe_chave'])): ?>
        <div class="imp_chave"><?= $e(chunk_split((string)$p['nfe_chave'], 4, ' ')) ?></div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="imp_sep"></div>

  <div class="imp_bloco">
    <div class="imp_rotulo">Destinatário</div>
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

  <div class="imp_sep"></div>

  <div class="imp_rotulo">Itens</div>
  <table class="imp_itens">
    <?php foreach ($p['itens'] as $i): ?>
      <tr>
        <td class="imp_qtd"><?= (int)$i['quantidade'] ?>x</td>
        <td>
          <div class="imp_item_nome"><?= $e($i['nome']) ?></div>
          <?php if (!empty($i['variacao_texto'])): ?>
            <div class="imp_item_var"><?= $e($i['variacao_texto']) ?></div>
          <?php endif; ?>
          <?php if (!empty($i['sku_real'])): ?>
            <div class="imp_item_sku">SKU <?= $e($i['sku_real']) ?></div>
          <?php endif; ?>
        </td>
        <td class="imp_preco"><?= $brl((float)$i['preco_unitario'] * (int)$i['quantidade']) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>

  <div class="imp_sep"></div>

  <div class="imp_totais">
    <div><span><?= (int)$pecas ?> peça(s)</span><span><?= $brl($p['subtotal'] ?? 0) ?></span></div>
    <?php if ((float)($p['desconto'] ?? 0) > 0): ?>
      <div><span>Desconto</span><span>− <?= $brl($p['desconto']) ?></span></div>
    <?php endif; ?>
    <div><span>Frete<?= !empty($p['frete_servico']) ? ' (' . $e($p['frete_servico']) . ')' : '' ?></span><span><?= $brl($p['frete'] ?? 0) ?></span></div>
    <div class="imp_total"><span>Total</span><span><?= $brl($p['total']) ?></span></div>
  </div>

  <?php if (!empty($p['codigo_rastreio'])): ?>
    <div class="imp_bloco" style="margin-top:3mm">
      <div class="imp_rotulo">Rastreio</div>
      <div class="imp_forte imp_rastreio"><?= $e($p['codigo_rastreio']) ?></div>
    </div>
  <?php endif; ?>

  <div class="imp_rodape">
    Documento não fiscal — comprovante de conferência.<br>
    <?= $e($loja['nome'] ?? '') ?><?= !empty($loja['email']) ? ' · ' . $e($loja['email']) : '' ?>
  </div>
</div>
