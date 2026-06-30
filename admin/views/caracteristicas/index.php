<?php
$tipoLabels = [
    'texto'   => ['label' => 'Texto',    'color' => 'blue'],
    'numero'  => ['label' => 'Número',   'color' => 'purple'],
    'select'  => ['label' => 'Seleção',  'color' => 'teal'],
    'boolean' => ['label' => 'Sim/Não',  'color' => 'green'],
    'textarea'=> ['label' => 'Texto longo','color'=> 'muted'],
    'url'     => ['label' => 'URL',      'color' => 'warning'],
];
?>
<div class="admin-page">
  <div class="admin-page-header">
    <div>
      <h1>Características</h1>
      <p>Defina campos globais — vincule às categorias para ativar nos produtos</p>
    </div>
    <button type="button" class="btn btn-primary" id="btn-nova-caracteristica">
      + Nova característica
    </button>
  </div>

  <div class="admin-card">
    <div class="admin-table-wrap">
      <table class="admin-table" id="chars-table">
        <thead>
          <tr>
            <th width="32"></th>
            <th>Nome / Slug</th>
            <th class="text-center">Tipo</th>
            <th class="text-center">Unidade</th>
            <th class="text-center">Obrigatório</th>
            <th class="text-center">Categorias</th>
            <th class="text-center">Produtos</th>
            <th width="80">Ações</th>
          </tr>
        </thead>
        <tbody id="chars-tbody">
          <?php foreach ($caracteristicas as $c):
            $tl = $tipoLabels[$c['tipo']] ?? ['label' => $c['tipo'], 'color' => 'muted'];
          ?>
          <tr data-id="<?= $c['id'] ?>">
            <td class="cat-td-drag">
              <span class="admin-drag-handle">⠿</span>
            </td>
            <td>
              <span class="cat-nome"><?= View::e($c['nome']) ?></span>
              <span class="cat-slug"><?= View::e($c['slug']) ?></span>
            </td>
            <td class="text-center">
              <span class="admin-badge admin-badge--<?= $tl['color'] ?>">
                <?= $tl['label'] ?>
              </span>
            </td>
            <td class="text-center admin-muted">
              <?= View::e($c['unidade'] ?? '—') ?>
            </td>
            <td class="text-center">
              <?php if ($c['obrigatorio']): ?>
              <span class="admin-badge admin-badge--danger">Sim</span>
              <?php else: ?>
              <span class="admin-muted">Não</span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <span class="admin-badge admin-badge--muted">
                <?= (int)$c['total_categorias'] ?>
              </span>
            </td>
            <td class="text-center">
              <span class="admin-badge admin-badge--muted">
                <?= (int)$c['total_produtos'] ?>
              </span>
            </td>
            <td>
              <div class="admin-row-actions">
                <button type="button"
                        class="btn btn-sm btn-ghost btn-editar-char"
                        data-id="<?= $c['id'] ?>"
                        data-nome="<?= View::e($c['nome']) ?>"
                        data-tipo="<?= View::e($c['tipo']) ?>"
                        data-unidade="<?= View::e($c['unidade'] ?? '') ?>"
                        data-placeholder="<?= View::e($c['placeholder'] ?? '') ?>"
                        data-obrigatorio="<?= (int)$c['obrigatorio'] ?>"
                        data-ordem="<?= (int)$c['ordem'] ?>"
                        data-opcoes='<?= View::e($c['opcoes'] ?? '[]') ?>'>
                  Editar
                </button>
                <button type="button"
                        class="btn btn-sm btn-ghost btn-excluir-char"
                        data-id="<?= $c['id'] ?>"
                        data-nome="<?= View::e($c['nome']) ?>">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                       stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <polyline points="3 6 5 6 21 6"/>
                    <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
                  </svg>
                </button>
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
<div class="pe-modal-backdrop" id="modal-char" style="display:none;">
  <div class="pe-modal pe-modal--lg">
    <div class="pe-modal-header">
      <h3 id="modal-char-titulo">Nova característica</h3>
      <button type="button" class="pe-modal-close"
              id="btn-close-modal-char">×</button>
    </div>
    <div class="pe-modal-body">
      <form id="form-char">
        <?= SecurityHelper::csrfField() ?>
        <input type="hidden" name="id" id="char-id" value="0">

        <div class="pe-grid-2">
          <div class="form-group">
            <label class="pe-label">
              Nome <span class="pe-required">*</span>
            </label>
            <input type="text" name="nome" id="char-nome"
                   class="form-control"
                   placeholder="Ex: Peso, Voltagem, Material...">
          </div>
          <div class="form-group">
            <label class="pe-label">Tipo de campo</label>
            <select name="tipo" id="char-tipo" class="form-control">
              <option value="texto">Texto curto</option>
              <option value="numero">Número</option>
              <option value="select">Seleção (lista)</option>
              <option value="boolean">Sim / Não</option>
              <option value="textarea">Texto longo</option>
              <option value="url">URL / Link</option>
            </select>
          </div>
        </div>

        <div class="pe-grid-2">
          <div class="form-group">
            <label class="pe-label">Unidade</label>
            <input type="text" name="unidade" id="char-unidade"
                   class="form-control" placeholder="Ex: kg, cm, W, V, ml">
            <p class="pe-field-hint">
              Exibida ao lado do valor. Deixe vazio se não aplicável.
            </p>
          </div>
          <div class="form-group">
            <label class="pe-label">Placeholder</label>
            <input type="text" name="placeholder" id="char-placeholder"
                   class="form-control"
                   placeholder="Ex: Digite o peso em kg...">
          </div>
        </div>

        <!-- Opções (só para tipo select) -->
        <div class="form-group" id="char-opcoes-group" style="display:none;">
          <label class="pe-label">Opções da lista</label>
          <p class="pe-field-hint">Uma opção por linha.</p>
          <div id="char-opcoes-list"></div>
          <button type="button" class="pe-add-btn" id="btn-add-opcao">
            + Adicionar opção
          </button>
        </div>

        <div class="pe-grid-2">
          <div class="form-group">
            <label class="pe-label">Ordem</label>
            <input type="number" name="ordem" id="char-ordem"
                   class="form-control" value="0" min="0"
                   style="max-width:100px;">
          </div>
          <div class="form-group">
            <label class="pe-label" style="margin-bottom:10px;">
              Configurações
            </label>
            <label class="pe-toggle-label">
              <div class="pe-toggle-switch">
                <input type="checkbox" name="obrigatorio" id="char-obrigatorio" value="1">
                <span class="pe-toggle-track">
                  <span class="pe-toggle-thumb-inner"></span>
                </span>
              </div>
              <div>
                <span class="pe-toggle-title">Obrigatório por padrão</span>
                <span class="pe-toggle-desc">
                  Pode ser sobrescrito por categoria
                </span>
              </div>
            </label>
          </div>
        </div>
      </form>
    </div>
    <div class="pe-modal-footer">
      <button type="button" class="btn btn-ghost"
              id="btn-cancelar-char">Cancelar</button>
      <button type="button" class="btn btn-primary"
              id="btn-salvar-char">Salvar</button>
    </div>
  </div>
</div>