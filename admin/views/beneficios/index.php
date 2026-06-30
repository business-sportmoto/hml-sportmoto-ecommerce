<?php // views/admin/beneficios/index.php ?>

<div class="admin-page">
  <div class="admin-page-header">
    <h1>Benefícios do slider</h1>
    <p>Arraste para reordenar. Clique em + para adicionar.</p>
  </div>

  <form id="form-beneficios">
    <?= SecurityHelper::csrfField() ?>

    <div class="benefit-admin-list" id="benefitAdminList">
      <?php foreach ($beneficios as $i => $b): ?>
      <div class="benefit-admin-item" data-index="<?= $i ?>">

        <div class="benefit-admin-drag" title="Arrastar">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <line x1="8"  y1="6"  x2="21" y2="6"/>
            <line x1="8"  y1="12" x2="21" y2="12"/>
            <line x1="8"  y1="18" x2="21" y2="18"/>
            <line x1="3"  y1="6"  x2="3.01" y2="6"/>
            <line x1="3"  y1="12" x2="3.01" y2="12"/>
            <line x1="3"  y1="18" x2="3.01" y2="18"/>
          </svg>
        </div>

        <input type="hidden" name="items[<?= $i ?>][id]"
               value="<?= (int)$b['id'] ?>">

        <!-- Ícone -->
        <div class="benefit-admin-icon-pick">
          <!-- 
          <div class="benefit-icon-preview" id="iconPreview<?= $i ?>">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
              <?= $icons[$b['icone']] ?? $icons['star'] ?>
            </svg>
          </div>
          <select name="items[<?= $i ?>][icone]"
                  class="benefit-icon-select form-control form-control--sm"
                  data-index="<?= $i ?>">
            <?php foreach (array_keys($icons) as $k): ?>
            <option value="<?= $k ?>"
                    <?= $b['icone'] === $k ? 'selected' : '' ?>>
              <?= ucfirst($k) ?>
            </option>
            <?php endforeach; ?>
          </select> -->

          <div class="bloco-icon">
              <div class="preview-icon">
                <?= IconLibrary::render($b['icone'], 'icon icon--md') ?>
              </div>

              <input type="hidden" name="items[<?= $i ?>][icone]" class="input-icon-key" value="<?= $b['icone'] ?>">

              <button type="button" class="btn-open-icon-finder">
                  <!-- Escolher ícone -->
                   <?= IconLibrary::render('add-reaction', 'icon icon--md') ?>
              </button>
          </div>
        </div>

        <!-- Campos -->
        <div class="benefit-admin-fields">
          <div class="benefit-admin-row">
            <div class="form-group">
              <label>Título</label>
              <input type="text" name="items[<?= $i ?>][titulo]"
                     class="form-control form-control--sm"
                     value="<?= View::e($b['titulo']) ?>"
                     placeholder="Título" maxlength="100" required>
            </div>
            <div class="form-group">
              <label>Descrição</label>
              <input type="text" name="items[<?= $i ?>][descricao]"
                     class="form-control form-control--sm"
                     value="<?= View::e($b['descricao']) ?>"
                     placeholder="Descrição" maxlength="200">
            </div>
          </div>
          <div class="benefit-admin-row">
            <div class="form-group">
              <label>
                Link
                <span class="field-hint">(opcional — torna o card clicável)</span>
              </label>
              <input type="text" name="items[<?= $i ?>][link]"
                     class="form-control form-control--sm"
                     value="<?= View::e($b['link'] ?? '') ?>"
                     placeholder="Ex: /busca?frete_gratis=1">
            </div>
            <div class="form-group">
              <label>
                Classe CSS extra
                <span class="field-hint">(opcional — ex: benefit-promo)</span>
              </label>
              <input type="text" name="items[<?= $i ?>][css_classe]"
                     class="form-control form-control--sm"
                     value="<?= View::e($b['css_classe'] ?? '') ?>"
                     placeholder="Ex: benefit-promo benefit-destaque"
                     maxlength="100">
            </div>
          </div>
        </div>

        <!-- Ativo + Remover -->
        <div class="benefit-admin-actions">
          <label class="toggle-switch" title="Ativo">
            <input type="hidden"   name="items[<?= $i ?>][ativo]" value="0">
            <input type="checkbox" name="items[<?= $i ?>][ativo]" value="1"
                   <?= ($b['ativo'] ?? 1) ? 'checked' : '' ?>>
            <span class="toggle-track"></span>
          </label>
          <button type="button" class="benefit-admin-del"
                  title="Remover">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
              <polyline points="3 6 5 6 21 6"/>
              <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
            </svg>
          </button>
        </div>

      </div>
      <?php endforeach; ?>
    </div>

    <!-- Adicionar -->
    <button type="button" class="benefit-admin-add" id="btnAddBenefit">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
        <line x1="12" y1="5" x2="12" y2="19"/>
        <line x1="5"  y1="12" x2="19" y2="12"/>
      </svg>
      Adicionar benefício
    </button>

    <div class="benefit-admin-footer">
      <button type="submit" class="btn btn-primary" id="btnSaveBenefits">
        Salvar alterações
      </button>
      <span class="benefit-admin-feedback" id="benefitFeedback"></span>
    </div>
  </form>
</div>


