<?php
$papelLabels = [
    'agrupador' => ['label' => 'Agrupador', 'color' => 'purple',
                    'hint'  => 'Navega entre produtos da família (cor, estampa)'],
    'variacao'  => ['label' => 'Variação',  'color' => 'blue',
                    'hint'  => 'Seleciona o SKU (tamanho, voltagem)'],
];
$displayLabels = [
    'button'      => 'Botão',
    'color_swatch'=> 'Swatch de cor',
    'text'        => 'Texto',
    'select'      => 'Dropdown',
];
?>

<div class="admin-page">
  <div class="admin-page-header">
    <div>
      <h1>Tipos de atributo</h1>
      <p>Configure os atributos usados em variações e famílias de produtos</p>
    </div>
    <button type="button" class="btn btn-primary" id="btn-novo-atributo">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
        <line x1="12" y1="5" x2="12" y2="19"/>
        <line x1="5"  y1="12" x2="19" y2="12"/>
      </svg>
      Novo atributo
    </button>
  </div>

  <!-- Explicação dos papéis -->
  <div class="attr-papel-cards">
    <?php foreach ($papelLabels as $key => $pl): ?>
    <div class="attr-papel-card">
      <span class="admin-badge admin-badge--<?= $pl['color'] ?>"><?= $pl['label'] ?></span>
      <p><?= $pl['hint'] ?></p>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="admin-card">
    <div class="admin-table-wrap">
      <table class="admin-table" id="atributos-table">
        <thead>
          <tr>
            <th width="32"></th>
            <th>Nome / Slug</th>
            <th class="text-center">Papel</th>
            <th class="text-center">Display</th>
            <th class="text-center">Ordem</th>
            <th class="text-center">Em uso</th>
            <th width="100">Ações</th>
            <th width="100">Valores</th>
          </tr>
        </thead>
        <tbody id="atributos-tbody">
          <?php foreach ($atributos as $at): 
            $valores = $valoresPorTipo[$at['id']] ?? [];
            ?>
          <tr data-id="<?= $at['id'] ?>">

            <td class="cat-td-drag">
              <span class="admin-drag-handle">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round">
                  <circle cx="9" cy="5" r="1"/><circle cx="15" cy="5" r="1"/>
                  <circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/>
                  <circle cx="9" cy="19" r="1"/><circle cx="15" cy="19" r="1"/>
                </svg>
              </span>
            </td>

            <td>
              <span class="cat-nome"><?= View::e($at['nome']) ?></span>
              <span class="cat-slug"><?= View::e($at['slug']) ?></span>
            </td>

            <td class="text-center">
              <span class="admin-badge admin-badge--<?= $papelLabels[$at['papel']]['color'] ?? 'muted' ?>">
                <?= $papelLabels[$at['papel']]['label'] ?? $at['papel'] ?>
              </span>
            </td>

            <td class="text-center">
              <span class="attr-display-pill attr-display-pill--<?= $at['tipo_display'] ?>">
                <?php if ($at['tipo_display'] === 'color_swatch'): ?>
                <span class="attr-swatch-demo"></span>
                <?php endif; ?>
                <?= $displayLabels[$at['tipo_display']] ?? $at['tipo_display'] ?>
              </span>
            </td>

            <td class="text-center admin-muted">
              <?= (int)$at['ordenacao'] ?>
            </td>

            <td class="text-center">
              <?php $uso = (int)$at['uso_skus'] + (int)$at['uso_produtos']; ?>
              <?php if ($uso > 0): ?>
              <span class="admin-badge admin-badge--muted"><?= $uso ?> vínculos</span>
              <?php else: ?>
              <span class="admin-muted">Não usado</span>
              <?php endif; ?>
            </td>

            <td>
              <div class="admin-row-actions">
                <button type="button"
                        class="btn btn-sm btn-ghost btn-editar-atributo"
                        data-id="<?= $at['id'] ?>"
                        data-nome="<?= View::e($at['nome']) ?>"
                        data-slug="<?= View::e($at['slug']) ?>"
                        data-papel="<?= View::e($at['papel']) ?>"
                        data-display="<?= View::e($at['tipo_display']) ?>"
                        data-ordem="<?= (int)$at['ordenacao'] ?>"
                        title="Editar">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                       stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
                    <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
                  </svg>
                </button>
                <button type="button"
                        class="btn btn-sm btn-ghost btn-excluir-atributo"
                        data-id="<?= $at['id'] ?>"
                        data-nome="<?= View::e($at['nome']) ?>"
                        title="Excluir">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                       stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <polyline points="3 6 5 6 21 6"/>
                    <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
                  </svg>
                </button>
              </div>
            </td>

            <td>
                <div class="admin-row-actions">
                <button type="button"
                        class="btn btn-sm btn-ghost btn-toggle-valores"
                        data-id="<?= $at['id'] ?>"
                        title="Gerenciar valores">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <line x1="8" y1="6"  x2="21" y2="6"/>
                    <line x1="8" y1="12" x2="21" y2="12"/>
                    <line x1="8" y1="18" x2="21" y2="18"/>
                    <line x1="3" y1="6"  x2="3.01" y2="6"/>
                    <line x1="3" y1="12" x2="3.01" y2="12"/>
                    <line x1="3" y1="18" x2="3.01" y2="18"/>
                    </svg>
                    <?= count($valores) ?> valores
                </button>
                <!-- botões editar/excluir existentes -->
                </div>
            </td>
          </tr>
          <!-- Painel de valores (expande abaixo da linha) -->
            <tr class="attr-valores-row" id="valores-row-<?= $at['id'] ?>"
                style="display:none;">
            <td colspan="7" style="padding:0;">
                <div class="attr-valores-panel">

                <div class="attr-valores-header">
                    <span class="attr-valores-title">
                    Valores de "<?= View::e($at['nome']) ?>"
                    </span>
                    <button type="button"
                            class="btn btn-sm btn-primary btn-add-valor"
                            data-tipo-id="<?= $at['id'] ?>"
                            data-tipo-nome="<?= View::e($at['nome']) ?>"
                            data-tipo-display="<?= View::e($at['tipo_display']) ?>">
                    + Adicionar valor
                    </button>
                </div>

                <div class="attr-valores-list" id="valores-list-<?= $at['id'] ?>">
                    <?php if (empty($valores)): ?>
                    <p class="attr-valores-empty">
                    Nenhum valor cadastrado. Clique em "+ Adicionar valor".
                    </p>
                    <?php else: ?>
                    <?php foreach ($valores as $v): ?>
                    <div class="attr-valor-item" data-id="<?= $v['id'] ?>">

                    <?php if ($at['tipo_display'] === 'color_swatch'): ?>
                    <span class="attr-valor-swatch"
                            style="background:<?= View::e($v['valor_hex'] ?? 'var(--text-3)') ?>">
                    </span>
                    <?php else: ?>
                    <span class="attr-valor-preview-btn">
                        <?= View::e($v['valor']) ?>
                    </span>
                    <?php endif; ?>

                    <span class="attr-valor-nome"><?= View::e($v['valor']) ?></span>

                    <?php if (!empty($v['valor_hex'])): ?>
                    <span class="attr-valor-hex"><?= View::e($v['valor_hex']) ?></span>
                    <?php endif; ?>

                    <div class="attr-valor-actions">
                        <button type="button"
                                class="btn btn-xs btn-ghost btn-edit-valor"
                                data-id="<?= $v['id'] ?>"
                                data-tipo-id="<?= $at['id'] ?>"
                                data-valor="<?= View::e($v['valor']) ?>"
                                data-hex="<?= View::e($v['valor_hex'] ?? '') ?>"
                                data-ordem="<?= (int)$v['ordem'] ?>"
                                data-display="<?= View::e($at['tipo_display']) ?>">
                        Editar
                        </button>
                        <button type="button"
                                class="btn btn-xs btn-ghost btn-danger btn-del-valor"
                                data-id="<?= $v['id'] ?>">
                        Remover
                        </button>
                    </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                </div>
            </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal criar/editar -->
<div class="pe-modal-backdrop" id="modal-atributo" style="display:none;">
  <div class="pe-modal">
    <div class="pe-modal-header">
      <h3 id="modal-atributo-titulo">Novo atributo</h3>
      <button type="button" class="pe-modal-close" id="btn-close-modal-atributo">×</button>
    </div>
    <div class="pe-modal-body">
      <form id="form-atributo">
        <?= SecurityHelper::csrfField() ?>
        <input type="hidden" name="id" id="attr-id" value="0">

        <div class="form-group">
          <label class="pe-label">Nome <span class="pe-required">*</span></label>
          <input type="text" name="nome" id="attr-nome" class="form-control"
                 placeholder="Ex: Tamanho, Cor, Voltagem..." required>
        </div>

        <div class="form-group">
          <label class="pe-label">Slug</label>
          <input type="text" name="slug" id="attr-slug" class="form-control"
                 placeholder="gerado automaticamente"
                 style="font-family:var(--font-mono);font-size:13px;">
          <p class="pe-field-hint">Identificador interno. Não altere após usar em produtos.</p>
        </div>

        <div class="pe-grid-2">
          <div class="form-group">
            <label class="pe-label">Papel</label>
            <select name="papel" id="attr-papel" class="form-control">
              <option value="variacao">
                Variação — seleciona SKU
              </option>
              <option value="agrupador">
                Agrupador — navega entre produtos
              </option>
            </select>
          </div>
          <div class="form-group">
            <label class="pe-label">Tipo de display</label>
            <select name="tipo_display" id="attr-display" class="form-control">
              <option value="button">Botão</option>
              <option value="color_swatch">Swatch de cor</option>
              <option value="text">Texto livre</option>
              <option value="select">Dropdown</option>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label class="pe-label">Ordem de exibição</label>
          <input type="number" name="ordenacao" id="attr-ordem"
                 class="form-control" value="0" min="0" style="max-width:120px;">
        </div>

        <!-- Preview do display -->
        <div class="attr-display-preview" id="attr-display-preview">
          <span class="pe-field-hint">Preview:</span>
          <div id="attr-display-demo"></div>
        </div>

      </form>
    </div>
    <div class="pe-modal-footer">
      <button type="button" class="btn btn-ghost" id="btn-cancelar-atributo">Cancelar</button>
      <button type="button" class="btn btn-primary" id="btn-salvar-atributo">Salvar</button>
    </div>
  </div>
</div>

<!-- Adicionar ao final da view -->
<div class="pe-modal-backdrop" id="modal-valor" style="display:none;">
  <div class="pe-modal">
    <div class="pe-modal-header">
      <h3 id="modal-valor-titulo">Adicionar valor</h3>
      <button type="button" class="pe-modal-close" id="btn-close-modal-valor">×</button>
    </div>
    <div class="pe-modal-body">
      <form id="form-valor">
        <?= SecurityHelper::csrfField() ?>
        <input type="hidden" name="id"               id="val-id"      value="0">
        <input type="hidden" name="atributo_tipo_id" id="val-tipo-id" value="">

        <div class="form-group">
          <label class="pe-label">
            Valor <span class="pe-required">*</span>
          </label>
          <input type="text" name="valor" id="val-valor"
                 class="form-control"
                 placeholder="Ex: Vermelho, PP, 110V...">
        </div>

        <!-- Apenas para color_swatch -->
        <div class="form-group" id="val-cor-group" style="display:none;">
          <label class="pe-label">Cor</label>
          <div style="display:flex;gap:10px;align-items:center;">
            <input type="color" id="val-cor-picker"
                   class="pe-color-swatch-input-lg" value="#ff0000">
            <input type="text" name="valor_hex" id="val-cor-hex"
                   class="form-control" value="#FF0000" maxlength="7"
                   style="font-family:var(--font-mono);max-width:120px;">
          </div>
        </div>

        <div class="form-group">
          <label class="pe-label">Ordem de exibição</label>
          <input type="number" name="ordem" id="val-ordem"
                 class="form-control" value="0" min="0"
                 style="max-width:100px;">
        </div>
      </form>
    </div>
    <div class="pe-modal-footer">
      <button type="button" class="btn btn-ghost"
              id="btn-cancelar-valor">Cancelar</button>
      <button type="button" class="btn btn-primary"
              id="btn-salvar-valor">Salvar valor</button>
    </div>
  </div>
</div>