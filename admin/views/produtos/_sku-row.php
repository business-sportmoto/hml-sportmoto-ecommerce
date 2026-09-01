<?php
$key       = isset($sku['id']) ? (string)$sku['id'] : ($key ?? 'new_0');
$attrsMap  = $sku['atributos_map'] ?? [];
$tiposVar  = array_filter($atributos_tipos, fn($t) => $t['papel'] === 'variacao');
?>

<div class="pe-sku-card" data-sku-id="<?= $key ?>">
  <div class="pe-sku-card-header">

    <div class="pe-sku-card-code">
      <label class="pe-label" style="font-size:11px;">Código SKU</label>
      <input type="text"
             name="skus[<?= $key ?>][sku]"
             class="form-control form-control--sm"
             value="<?= View::e($sku['sku'] ?? '') ?>"
             placeholder="SKU-001"
             style="font-family:var(--font-mono);font-size:12px;">
    </div>

    <div class="pe-sku-card-preco">
      <label class="pe-label" style="font-size:11px;">Preço</label>
      <div class="pe-price-input-wrap">
        <span class="pe-price-prefix" style="font-size:12px;">R$</span>
        <input type="number"
               name="skus[<?= $key ?>][preco]"
               class="form-control pe-price-input"
               value="<?= number_format((float)($sku['preco'] ?? 0), 2, '.', '') ?>"
               step="0.01" min="0" style="font-size:13px;">
      </div>
    </div>

    <div class="pe-sku-card-preco">
      <label class="pe-label" style="font-size:11px;">Preço promo</label>
      <div class="pe-price-input-wrap">
        <span class="pe-price-prefix" style="font-size:12px;">R$</span>
        <input type="number"
               name="skus[<?= $key ?>][preco_promo]"
               class="form-control pe-price-input"
               value="<?= !empty($sku['preco_promo']) ? number_format((float)$sku['preco_promo'], 2, '.', '') : '' ?>"
               step="0.01" min="0" placeholder="—"
               style="font-size:13px;">
      </div>
    </div>

    <div class="pe-sku-card-preco">
      <label class="pe-label" style="font-size:11px;">Custo</label>
      <div class="pe-price-input-wrap">
        <span class="pe-price-prefix" style="font-size:12px;">R$</span>
        <input type="number"
               name="skus[<?= $key ?>][custo]"
               class="form-control pe-price-input"
               value="<?= !empty($sku['custo']) ? number_format((float)$sku['custo'], 2, '.', '') : '' ?>"
               step="0.01" min="0" placeholder="—"
               title="Em branco = desconhecido. Tem precedencia sobre o custo do produto."
               style="font-size:13px;">
      </div>
    </div>

    <div class="pe-sku-card-estoque">
      <label class="pe-label" style="font-size:11px;">Estoque</label>
      <input type="number"
             name="skus[<?= $key ?>][estoque]"
             class="form-control form-control--sm pe-sku-estoque"
             value="<?= (int)($sku['estoque'] ?? 0) ?>"
             min="0" style="max-width:80px;">
    </div>

    <div class="pe-sku-card-ativo">
      <label class="pe-label" style="font-size:11px;">Ativo</label>
      <label class="pe-toggle-mini">
        <input type="checkbox"
               name="skus[<?= $key ?>][ativo]" value="1"
               <?= ($sku['ativo'] ?? 1) ? 'checked' : '' ?>>
        <span class="pe-toggle-mini-track"></span>
      </label>
    </div>

    <button type="button" class="pe-sku-card-del pe-sku-del">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
        <polyline points="3 6 5 6 21 6"/>
        <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
      </svg>
    </button>

  </div>

  <!-- Atributos com select de valores pré-definidos -->
  <?php if (!empty($tiposVar)): ?>
  <div class="pe-sku-attrs-section">
    <span class="pe-sku-attrs-title">Atributos de variação</span>
    <div class="pe-sku-attrs-list">
      <?php foreach ($tiposVar as $tipo):
        $valorAtual = $attrsMap[(int)$tipo['id']] ?? '';

        // Busca valores pré-definidos deste tipo
        $stmt = Database::getInstance()->getConnection()->prepare(
            "SELECT id, valor, valor_hex FROM atributo_valores
             WHERE atributo_tipo_id = ? ORDER BY ordem ASC, valor ASC"
        );
        $stmt->execute([$tipo['id']]);
        $valoresTipo = $stmt->fetchAll();
      ?>
      <div class="pe-sku-attr-item">
        <label class="pe-sku-attr-nome"><?= View::e($tipo['nome']) ?></label>

        <?php if (!empty($valoresTipo)): ?>
        <!-- Tem valores pré-definidos: usa select visual -->
        <div class="pe-sku-attr-opcoes"
             data-tipo-id="<?= $tipo['id'] ?>"
             data-tipo-display="<?= View::e($tipo['tipo_display']) ?>">

          <input type="hidden"
                 name="skus[<?= $key ?>][atributos][<?= $tipo['id'] ?>]"
                 class="pe-sku-attr-hidden"
                 value="<?= View::e($valorAtual) ?>">

          <?php foreach ($valoresTipo as $val): ?>
          <?php $selected = ($val['valor'] === $valorAtual); ?>

          <?php if ($tipo['tipo_display'] === 'color_swatch' && !empty($val['valor_hex'])): ?>
          <!-- Swatch de cor -->
          <button type="button"
                  class="pe-sku-swatch-btn <?= $selected ? 'selected' : '' ?>"
                  data-valor="<?= View::e($val['valor']) ?>"
                  style="background:<?= View::e($val['valor_hex']) ?>"
                  title="<?= View::e($val['valor']) ?>">
            <?php if ($selected): ?>
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none"
                 stroke="white" stroke-width="3" stroke-linecap="round">
              <polyline points="20 6 9 17 4 12"/>
            </svg>
            <?php endif; ?>
          </button>

          <?php else: ?>
          <!-- Botão de texto -->
          <button type="button"
                  class="pe-sku-opt-btn <?= $selected ? 'selected' : '' ?>"
                  data-valor="<?= View::e($val['valor']) ?>">
            <?= View::e($val['valor']) ?>
          </button>
          <?php endif; ?>

          <?php endforeach; ?>
        </div>

        <?php else: ?>
        <!-- Sem valores pré-definidos: input livre -->
        <input type="text"
               name="skus[<?= $key ?>][atributos][<?= $tipo['id'] ?>]"
               class="form-control form-control--sm"
               value="<?= View::e($valorAtual) ?>"
               placeholder="Digite o valor..."
               style="max-width:180px;">
        <?php endif; ?>

      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

</div>