<?php
// views/admin/cupons/form.php
$isEdit  = !empty($cupom);
$c       = $cupom ?? [];
$title   = $isEdit ? 'Editar cupom' : 'Criar cupom';
$escopos = [
    'produtos'   => json_decode($c['escopo_produtos']   ?? 'null', true) ?? [],
    'categorias' => json_decode($c['escopo_categorias'] ?? 'null', true) ?? [],
    'marcas'     => json_decode($c['escopo_marcas']     ?? 'null', true) ?? [],
    'clientes'   => json_decode($c['escopo_clientes']   ?? 'null', true) ?? [],
];
$progressivas = json_decode($c['regras_progressivas'] ?? 'null', true) ?? [];
?>
<div class="admin-page">
  <div class="admin-page-header">
    <div>
      <a href="<?= BASE_URL ?>/admin/cupons" class="back-link">← Voltar para cupons</a>
      <h1 class="admin-page-title"><?= $title ?></h1>
    </div>
  </div>

  <form id="form-cupom" novalidate>
    <?php if ($isEdit): ?>
    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
    <?= SecurityHelper::csrfField() ?>
    <?php endif; ?>

    <div class="form-grid-2col">

      <!-- ══ COLUNA ESQUERDA ═══════════════════════════ -->
      <div class="form-col-main">

        <!-- Básico -->
        <div class="admin-card form-section">
          <h3 class="form-section-title">Informações básicas</h3>

          <div class="form-row">
            <div class="form-group" style="flex:0 0 180px">
              <label>Código do cupom <span class="required">*</span></label>
              <input type="text" name="codigo" class="form-control"
                     placeholder="EX: PROMO20" maxlength="50"
                     style="text-transform:uppercase; font-weight:700; letter-spacing:.5px;"
                     value="<?= View::e($c['codigo'] ?? '') ?>" required
                     <?= $isEdit ? 'readonly' : '' ?>>
              <small class="form-help">Letras maiúsculas, números, - e _</small>
            </div>
            <div class="form-group" style="flex:1">
              <label>Nome interno <span class="required">*</span></label>
              <input type="text" name="nome" class="form-control"
                     placeholder="Ex: Promoção Black Friday 20%"
                     value="<?= View::e($c['nome'] ?? '') ?>" required>
            </div>
          </div>

          <div class="form-group">
            <label>Descrição <span class="label-opt">opcional</span></label>
            <textarea name="descricao" class="form-control" rows="2"
                      placeholder="Visível para o cliente no carrinho…"><?= View::e($c['descricao'] ?? '') ?></textarea>
          </div>
        </div>

        <!-- Desconto -->
        <div class="admin-card form-section">
          <h3 class="form-section-title">Tipo e valor do desconto</h3>

          <div class="form-row">
            <div class="form-group form-col">
              <label>Tipo <span class="required">*</span></label>
              <select name="tipo" id="campo-tipo" class="form-control" required>
                <?php foreach ([
                  'percentual' => 'Percentual (%)', 'fixo' => 'Valor fixo (R$)',
                  'frete_gratis' => 'Frete grátis', 'progressivo' => 'Progressivo (por faixa)',
                  'automatico' => 'Automático', 'exclusivo' => 'Exclusivo por cliente',
                  'primeira_compra' => 'Primeira compra', 'campanha' => 'Campanha',
                  'recuperacao_carrinho' => 'Recuperação de carrinho',
                ] as $k => $v): ?>
                <option value="<?= $k ?>" <?= ($c['tipo'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group form-col" id="campo-valor-wrap">
              <label>Valor <span class="required">*</span></label>
              <div class="input-symbol-wrap">
                <span class="input-symbol" id="valor-symbol">%</span>
                <input type="number" name="valor" class="form-control with-symbol"
                       placeholder="0" step="0.01" min="0"
                       value="<?= $c['valor'] ?? '' ?>">
              </div>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group form-col">
              <label>Teto de desconto <span class="label-opt">opcional</span></label>
              <div class="input-symbol-wrap">
                <span class="input-symbol">R$</span>
                <input type="number" name="valor_maximo" class="form-control with-symbol"
                       placeholder="0,00" step="0.01" min="0"
                       value="<?= $c['valor_maximo'] ?? '' ?>">
              </div>
              <small class="form-help">Valor máximo de desconto a conceder</small>
            </div>
            <div class="form-group form-col">
              <label>Pedido mínimo <span class="label-opt">opcional</span></label>
              <div class="input-symbol-wrap">
                <span class="input-symbol">R$</span>
                <input type="number" name="valor_minimo_pedido" class="form-control with-symbol"
                       placeholder="0,00" step="0.01" min="0"
                       value="<?= $c['valor_minimo_pedido'] ?? '0' ?>">
              </div>
            </div>
          </div>

          <!-- Regras progressivas (aparecem quando tipo=progressivo) -->
          <div id="progressivo-section" style="display:none;">
            <label class="form-section-label">Faixas progressivas</label>
            <div id="progressivo-table">
              <div class="progressivo-header">
                <span>Valor mín (R$)</span><span>Valor máx (R$)</span>
                <span>Desconto</span><span>Tipo</span><span></span>
              </div>
              <?php foreach ($progressivas as $i => $regra): ?>
              <div class="progressivo-row">
                <input type="number" name="prog_min[]"      class="form-control" value="<?= $regra['min'] ?? '' ?>">
                <input type="number" name="prog_max[]"      class="form-control" value="<?= $regra['max'] ?? '' ?>">
                <input type="number" name="prog_valor[]"    class="form-control" value="<?= $regra['valor'] ?? '' ?>">
                <select name="prog_tipo[]" class="form-control">
                  <option value="percentual" <?= ($regra['tipo']??'') === 'percentual' ? 'selected':'' ?>>%</option>
                  <option value="fixo"       <?= ($regra['tipo']??'') === 'fixo'       ? 'selected':'' ?>>R$</option>
                </select>
                <button type="button" class="btn-icon btn-icon--danger btn-remove-row">×</button>
              </div>
              <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-ghost btn-sm" id="btn-add-progressivo">+ Adicionar faixa</button>
          </div>
        </div>

        <!-- Escopos -->
        <div class="admin-card form-section">
          <h3 class="form-section-title">Escopos de aplicação <span class="label-opt">vazio = todos</span></h3>
          <?php foreach ([
            'produtos'   => 'IDs de produtos (separados por vírgula)',
            'categorias' => 'IDs de categorias',
            'marcas'     => 'IDs de marcas',
            'clientes'   => 'IDs de clientes (cupom exclusivo)',
          ] as $campo => $placeholder): ?>
          <div class="form-group">
            <label><?= ucfirst($campo) ?></label>
            <input type="text" name="escopo_<?= $campo ?>" class="form-control"
                   placeholder="<?= $placeholder ?>"
                   value="<?= implode(',', $escopos[$campo]) ?>">
          </div>
          <?php endforeach; ?>
        </div>

      </div>

      <!-- ══ COLUNA DIREITA ════════════════════════════ -->
      <div class="form-col-side">

        <!-- Status e validade -->
        <div class="admin-card form-section">
          <h3 class="form-section-title">Status e validade</h3>
          <label class="toggle-field">
            <input type="checkbox" name="ativo" value="1"
                   <?= ($c['ativo'] ?? 1) ? 'checked' : '' ?>>
            <span class="toggle-slider"></span>
            <span>Ativo</span>
          </label>
          <div class="form-group mt-2">
            <label>Data de início</label>
            <input type="datetime-local" name="data_inicio" class="form-control"
                   value="<?= ($c && $c['data_inicio']) ? date('Y-m-d\TH:i', strtotime($c['data_inicio'])) : '' ?>">
          </div>
          <div class="form-group">
            <label>Data de fim</label>
            <input type="datetime-local" name="data_fim" class="form-control"
                   value="<?= ($c && $c['data_fim']) ? date('Y-m-d\TH:i', strtotime($c['data_fim'])) : '' ?>">
          </div>
        </div>

        <!-- Limites -->
        <div class="admin-card form-section">
          <h3 class="form-section-title">Limites de uso</h3>
          <div class="form-group">
            <label>Limite total <span class="label-opt">vazio = ilimitado</span></label>
            <input type="number" name="limite_total" class="form-control"
                   placeholder="∞" min="1"
                   value="<?= $c['limite_total'] ?? '' ?>">
          </div>
          <div class="form-group">
            <label>Limite por cliente</label>
            <input type="number" name="limite_por_cliente" class="form-control"
                   min="1" value="<?= $c['limite_por_cliente'] ?? 1 ?>">
          </div>
        </div>

        <!-- Regras -->
        <div class="admin-card form-section">
          <h3 class="form-section-title">Regras especiais</h3>
          <?php foreach ([
            ['apenas_primeira_compra', 'Apenas para primeira compra'],
            ['permite_produto_promo',  'Permite usar com produtos em promoção'],
            ['acumula_desconto',       'Pode acumular com outros descontos'],
          ] as [$name, $label]): ?>
          <label class="toggle-field">
            <input type="checkbox" name="<?= $name ?>" value="1"
                   <?= ($c[$name] ?? 0) ? 'checked' : '' ?>>
            <span class="toggle-slider"></span>
            <span><?= $label ?></span>
          </label>
          <?php endforeach; ?>

          <?php // Separado dos outros de propósito: os de cima mudam COMO o
                // cupom se comporta no checkout; este decide se ele sai sozinho
                // para gente que a loja não escolheu uma a uma. ?>
          <label class="toggle-field" style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border);">
            <input type="checkbox" name="divulgavel" value="1"
                   <?= ($c['divulgavel'] ?? 0) ? 'checked' : '' ?>>
            <span class="toggle-slider"></span>
            <span>
              Pode ser oferecido automaticamente pelo agente do Instagram
              <small style="display:block;color:var(--text-3);font-weight:400;margin-top:2px;">
                Quem clicou no link do direct, colocou no carrinho e não comprou
                recebe este cupom. <strong>Deixe desligado</strong> se ele for de
                campanha fechada, de um cliente, ou se você não quiser que circule
                fora do seu controle.
              </small>
            </span>
          </label>
        </div>

        <!-- Campanha e vendedor -->
        <div class="admin-card form-section">
          <h3 class="form-section-title">Campanha e vendedor</h3>
          <div class="form-group">
            <label>Nome da campanha <span class="label-opt">opcional</span></label>
            <input type="text" name="campanha_nome" class="form-control"
                   placeholder="Ex: Black Friday 2025"
                   value="<?= View::e($c['campanha_nome'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label>Vendedor <span class="label-opt">opcional</span></label>
            <select name="vendedor_id" class="form-control">
              <option value="">Sem vendedor vinculado</option>
              <?php foreach ($vendedores ?? [] as $v): ?>
              <option value="<?= (int)$v['id'] ?>"
                      data-codigo="<?= View::e($v['codigo']) ?>"
                      <?= (int)($c['vendedor_id'] ?? 0) === (int)$v['id'] ? 'selected' : '' ?>>
                <?= View::e($v['nome']) ?> (<?= View::e($v['codigo']) ?>)
              </option>
              <?php endforeach; ?>
            </select>
            <input type="hidden" name="codigo_vendedor" id="codigo_vendedor_hidden"
                   value="<?= View::e($c['codigo_vendedor'] ?? '') ?>">
            <small class="form-help">Vincula este cupom a um vendedor para comissão e rastreio</small>
          </div>
        </div>

        <!-- Ações -->
        <div class="form-actions-sticky">
          <div id="form-error" class="form-alert" style="display:none;"></div>
          <button type="submit" class="btn btn-primary btn-full" id="btn-salvar">
            <?= $isEdit ? 'Salvar alterações' : 'Criar cupom' ?>
          </button>
          <a href="<?= BASE_URL ?>/admin/cupons" class="btn btn-outline btn-full">Cancelar</a>
          <?php if ($isEdit): ?>
          <a href="<?= BASE_URL ?>/admin/cupons/historico?id=<?= (int)$c['id'] ?>"
             class="btn btn-ghost btn-full" style="margin-top:4px;">Ver histórico de uso</a>
          <?php endif; ?>
        </div>

      </div>
    </div>
  </form>
</div>
