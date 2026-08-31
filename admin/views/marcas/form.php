<?php
$m      = $marca ?? null;
$isEdit = !empty($m);
?>

<div class="admin-page">
  <div class="admin-page-header">
    <div>
      <h1><?= View::e($titulo) ?></h1>
      <nav class="admin-breadcrumb">
        <a href="<?= BASE_URL ?>/admin/marcas">Marcas</a>
        <span>›</span>
        <span><?= $isEdit ? 'Editar' : 'Nova' ?></span>
      </nav>
    </div>
  </div>

  <form id="form-marca" novalidate>
    <?= SecurityHelper::csrfField() ?>
    <input type="hidden" name="id" value="<?= (int)($m['id'] ?? 0) ?>">

    <div class="admin-form-grid">

      <!-- Principal -->
      <div class="admin-form-main">

        <div class="admin-card">
          <div class="admin-card-header"><h3>Informações</h3></div>
          <div class="admin-card-body">

            <div class="form-group">
              <label>Nome <span class="required">*</span></label>
              <input type="text" id="marca-nome" name="nome"
                     class="form-control"
                     value="<?= View::e($m['nome'] ?? '') ?>"
                     placeholder="Ex: Honda, Yamaha..." required autofocus>
            </div>

            <div class="form-group">
              <label>Slug</label>
              <div class="input-with-prefix">
                <span class="input-prefix">/marca/</span>
                <input type="text" id="marca-slug" name="slug"
                       class="form-control"
                       value="<?= View::e($m['slug'] ?? '') ?>"
                       placeholder="gerado-automaticamente">
              </div>
            </div>

            <div class="form-group">
              <label>Descrição</label>
              <textarea name="descricao" class="form-control" rows="3"
                        placeholder="Sobre a marca..."><?= View::e($m['descricao'] ?? '') ?></textarea>
            </div>

            <div class="form-group">
              <label>Site oficial</label>
              <input type="url" name="site" class="form-control"
                     value="<?= View::e($m['site'] ?? '') ?>"
                     placeholder="https://www.honda.com.br">
            </div>

          </div>
        </div>

        <!-- SEO -->
        <div class="admin-card" style="margin-top:16px;" id="ma-seo">
           <div class="admin-card-header">
            <h3>SEO</h3>
            <span class="admin-card-hint">Para a página da marca</span>
          </div>
          <div class="admin-card-body">
            <div class="form-group">
              <label>Meta title</label>
              <input type="text" name="meta_title" class="form-control"
                     value="<?= View::e($m['meta_title'] ?? '') ?>"
                     placeholder="Título SEO da página da marca"
                     maxlength="160" id="ma-meta-title">
              <small class="field-hint seo-counter"
                     data-target="meta-title" data-max="60">0 / 60</small>
            </div>
            <div class="form-group">
              <label>Meta description</label>
              <textarea name="meta_description" class="form-control"
                        rows="2" maxlength="320" id="ma-meta-desc"
                        placeholder="Descrição SEO"><?= View::e($m['meta_description'] ?? '') ?></textarea>
              <small class="field-hint seo-counter"
                     data-target="meta-desc" data-max="155">0 / 155</small>
            </div>
            <div class="form-group">
              <label class="pe-label">Keywords</label>
              <input type="text" name="meta_keywords" class="form-control"
                     value="<?= View::e($m['meta_keywords'] ?? '') ?>"
                     placeholder="marca, moto, peças, acessórios">
              <p class="pe-field-hint">Separe por vírgulas. Impacto menor hoje em dia.</p>
            </div>
          </div>
        </div>

      </div>

      <!-- Lateral -->
      <div class="admin-form-side">

        <div class="admin-card">
          <div class="admin-card-header"><h3>Status</h3></div>
          <div class="admin-card-body">
            <label class="admin-check-label">
              <input type="checkbox" name="ativo" value="1"
                     <?= ($m['ativo'] ?? 1) ? 'checked' : '' ?>>
              <span class="admin-check-custom"></span>
              Marca ativa
            </label>
            <label class="admin-check-label" style="margin-top:10px;">
              <input type="checkbox" name="destaque" value="1"
                     <?= !empty($m['destaque']) ? 'checked' : '' ?>>
              <span class="admin-check-custom"></span>
              Exibir em destaque no site
            </label>
          </div>
        </div>

        <!-- Dentro do card de Status, após os checkboxes -->
        <div class="form-group admin-card" style="margin-top:14px;">          
          <div class="admin-card-header"><h3>Cor de fundo do logo</h3></div>
          <div class="admin-card-body">
            <div class="color-picker-wrap">
              <input type="color"
                    id="marca-bg-cor"
                    name="bg_cor"
                    value="<?= View::e($m['bg_cor'] ?? 'var(--surface)') ?>"
                    class="color-picker-input">
              <input type="text"
                    id="marca-bg-cor-hex"
                    class="form-control form-control--sm color-picker-hex"
                    value="<?= View::e($m['bg_cor'] ?? 'var(--surface)') ?>"
                    maxlength="7"
                    placeholder="#ffffff">
              <button type="button" class="color-picker-clear" id="btn-clear-color"
                      title="Remover cor">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                  <line x1="18" y1="6" x2="6"  y2="18"/>
                  <line x1="6"  y1="6" x2="18" y2="18"/>
                </svg>
              </button>
            </div>
            
            <small class="field-hint">
              Aplicada no fundo do card da marca no site.
            </small>

            <!-- Preview -->
            <div class="color-picker-preview" id="color-preview"
                style="display:none; background:<?= View::e($m['bg_cor'] ?? 'var(--surface)') ?>">
              <?php if (!empty($m['logo'])): ?>
              <img src="<?= View::upload('brands/' . $m['logo']) ?>"
                  alt="" style="max-height:40px;object-fit:contain;">
              <?php else: ?>
              <span style="font-size:12px;font-weight:700;color:var(--text-2);letter-spacing:1px;">
                PREVIEW
              </span>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Logo -->
        <div class="admin-card" style="margin-top:12px;">
          <div class="admin-card-header"><h3>Logo</h3></div>
          <div class="admin-card-body">

            <?php if (!empty($m['logo'])): ?>
            <div class="admin-img-preview" id="img-preview-wrap">
              <img src="<?= View::upload('brands/' . $m['logo']) ?>"
                   alt="" id="img-preview"
                   style="max-width:100%;max-height:100px;object-fit:contain;border-radius:8px;display:block;margin:0 auto;">
              <button type="button" class="admin-img-remove" id="btn-remove-img">
                Remover logo
              </button>
            </div>
            <?php endif; ?>

            <div class="admin-upload-area" id="upload-area"
                 <?= !empty($m['logo']) ? 'style="display:none;"' : '' ?>>
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
                <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>
                <polyline points="17 8 12 3 7 8"/>
                <line x1="12" y1="3" x2="12" y2="15"/>
              </svg>
              <p>Clique ou arraste o logo</p>
              <small>PNG, JPG, SVG, WEBP — máx. 2MB</small>
              <input type="file" name="logo" id="marca-logo"
                     accept="image/*,.svg" class="upload-input-hidden">
            </div>

          </div>
        </div>

        <div class="admin-form-actions">
          <button type="submit" class="btn btn-primary btn-full" id="btn-salvar">
            <?= $isEdit ? 'Salvar alterações' : 'Criar marca' ?>
          </button>
          <a href="<?= BASE_URL ?>/admin/marcas" class="btn btn-ghost btn-full">
            Cancelar
          </a>
        </div>

      </div>
    </div>
  </form>
</div>

<script>
// Inicializa gerador de SEO na seção #pe-seo
document.addEventListener('DOMContentLoaded', function () {
    if (typeof adminSeoIA !== 'undefined') {
        adminSeoIA({
            tipo: 'marca',

            getContexto: () => ({
                nome     : $('#marca-nome').val(),
                descricao: $('#marca-descricao').val(),
            }),

            campos: {
                meta_title      : '#ma-meta-title',
                meta_description: '#ma-meta-desc',
                keywords        : '#ma-seo [name="meta_keywords"]',                
            },

            container: '#ma-seo',
        });
    }
});
</script>