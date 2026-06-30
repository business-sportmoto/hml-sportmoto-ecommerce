<?php
$cat    = $categoria ?? null;
$isEdit = !empty($cat);
$preParentId = (int)($_GET['parent_id'] ?? $cat['parent_id'] ?? 0);
?>

<div class="admin-page">

  <div class="admin-page-header">
    <div>
      <h1><?= View::e($titulo) ?></h1>
      <nav class="admin-breadcrumb">
        <a href="<?= BASE_URL ?>/admin/categorias">Categorias</a>
        <span>›</span>
        <span><?= $isEdit ? 'Editar' : 'Nova' ?></span>
      </nav>
    </div>
  </div>

  <form id="form-categoria" novalidate>
    <?= SecurityHelper::csrfField() ?>
    <input type="hidden" name="id" value="<?= (int)($cat['id'] ?? 0) ?>">

    <div class="admin-form-grid">

      <!-- Coluna principal -->
      <div class="admin-form-main">

        <div class="admin-card">
          <div class="admin-card-header"><h3>Informações</h3></div>
          <div class="admin-card-body">

            <div class="form-group">
              <label for="cat-nome">
                Nome <span class="required">*</span>
              </label>
              <input type="text" id="cat-nome" name="nome"
                     class="form-control"
                     value="<?= View::e($cat['nome'] ?? '') ?>"
                     placeholder="Nome da categoria" required autofocus>
              <span class="field-error" id="err-nome"></span>
            </div>

            <div class="form-group">
              <label for="cat-slug">Slug</label>
              <div class="input-with-prefix">
                <span class="input-prefix">/</span>
                <input type="text" id="cat-slug" name="slug"
                       class="form-control"
                       value="<?= View::e($cat['slug'] ?? '') ?>"
                       placeholder="gerado-automaticamente">
              </div>
              <small class="field-hint">
                Deixe em branco para gerar automaticamente pelo nome.
              </small>
            </div>

            <div class="form-group">
              <label for="cat-descricao">Descrição</label>
              <textarea id="cat-descricao" name="descricao"
                        class="form-control" rows="3"
                        placeholder="Descrição da categoria (opcional)"><?= View::e($cat['descricao'] ?? '') ?></textarea>
            </div>

          </div>
        </div>

        <!-- Adicionar no formulário de categoria: -->
        <div class="pe-card" style="margin-top:16px;" id="card-cat-chars">
          <div class="pe-card-title-row">
            <span class="pe-card-title">Características</span>
            <span class="pe-card-hint">
              Produtos desta categoria exibirão estes campos
            </span>
          </div>
          

          <!-- Seletor para adicionar características -->
          <div class="cat-char-add-wrap">
            <select id="cat-char-select" class="form-control form-control--sm"
                    style="flex:1;max-width:300px;">
              <option value="">+ Adicionar característica...</option>
              <?php
              $db    = Database::getInstance()->getConnection();
              $chars = $db->query(
                  "SELECT id, nome, tipo FROM caracteristicas
                  WHERE ativo = 1 ORDER BY nome ASC"
              )->fetchAll();
              foreach ($chars as $ch):
              ?>
              <option value="<?= $ch['id'] ?>"
                      data-nome="<?= View::e($ch['nome']) ?>"
                      data-tipo="<?= View::e($ch['tipo']) ?>">
                <?= View::e($ch['nome']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Lista de características vinculadas -->
          <div id="cat-chars-list">
            <?php
            // Busca vínculos existentes
            $vinculadas = [];
            if (!empty($categoria['id'])) {
                $stmt = $db->prepare(
                    "SELECT c.*, cc.obrigatorio AS cat_obrigatorio, cc.ordem AS cat_ordem
                    FROM caracteristicas c
                    JOIN categoria_caracteristicas cc ON cc.caracteristica_id = c.id
                    WHERE cc.categoria_id = ? AND c.ativo = 1
                    ORDER BY cc.ordem ASC"
                );
                $stmt->execute([$categoria['id']]);
                $vinculadas = $stmt->fetchAll();
            }

            foreach ($vinculadas as $v):
            ?>
            <div class="cat-char-item" data-id="<?= $v['id'] ?>">
              <span class="cat-char-drag">⠿</span>
              <span class="cat-char-nome"><?= View::e($v['nome']) ?></span>
              <span class="admin-badge admin-badge--muted"><?= View::e($v['tipo']) ?></span>
              <label class="cat-char-obrig-label">
                <input type="checkbox"
                      class="cat-char-obrig"
                      <?= $v['cat_obrigatorio'] ? 'checked' : '' ?>>
                Obrigatório
              </label>
              <button type="button" class="btn btn-xs btn-ghost cat-char-remove">×</button>
            </div>
            <?php endforeach; ?>
          </div>

          <?php if (!empty($categoria['id'])): ?>
          <div style="margin-top:14px;">
            <button type="button" class="btn btn-sm btn-outline"
                    id="btn-salvar-cat-chars"
                    data-categoria-id="<?= (int)$categoria['id'] ?>">
              Salvar características da categoria
            </button>
          </div>
          <?php endif; ?>
        </div>
        
        <!-- Adicionar como novo card no formulário da categoria -->
        <div class="pe-card" style="margin-top:16px;">
          <div class="pe-card-title-row">
            <span class="pe-card-title">Busca por moto</span>
            <span class="admin-badge admin-badge--info">SEO</span>
          </div>

          <label class="pe-toggle-label">
            <div class="pe-toggle-switch">
              <input type="checkbox" name="busca_moto" value="1"
                    <?= !empty($categoria['busca_moto']) ? 'checked' : '' ?>>
              <span class="pe-toggle-track">
                <span class="pe-toggle-thumb-inner"></span>
              </span>
            </div>
            <div>
              <span class="pe-toggle-title">Habilitar busca por montadora</span>
              <span class="pe-toggle-desc">
                Exibe o seletor de moto (montadora › modelo › ano) nas páginas
                desta categoria. Produtos com compatibilidade cadastrada serão
                filtrados automaticamente.
              </span>
            </div>
          </label>

          <?php if (!empty($categoria['busca_moto'])): ?>
          <div class="cat-busca-moto-preview" style="margin-top:14px;">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <polyline points="20 6 9 17 4 12"/>
            </svg>
            Busca por moto ativa nesta categoria e em todas as suas subcategorias.
          </div>
          <?php endif; ?>
        </div>

        <!-- SEO -->
        <div class="admin-card" id="section-cat-seo" style="margin-top:16px;">
          <div class="admin-card-header">
            <h3>SEO</h3>
            <span class="admin-card-hint">Opcional — preenchido automaticamente se vazio</span>
          </div>
          <div class="admin-card-body">
            <div class="form-group">
              <label for="cat-meta-title">Meta title</label>
              <input type="text" id="cat-meta-title" name="meta_title"
                     class="form-control"
                     value="<?= View::e($cat['meta_title'] ?? '') ?>"
                     placeholder="Título para SEO" maxlength="160">
              <small class="field-hint seo-counter" data-target="cat-meta-title" data-max="60">
                0 / 60 caracteres recomendados
              </small>
            </div>
            <div class="form-group">
              <label for="cat-meta-desc">Meta description</label>
              <textarea id="cat-meta-desc" name="meta_description"
                        class="form-control" rows="2"
                        placeholder="Descrição para SEO" maxlength="320"><?= View::e($cat['meta_description'] ?? '') ?></textarea>
              <small class="field-hint seo-counter" data-target="cat-meta-desc" data-max="155">
                0 / 155 caracteres recomendados
              </small>
            </div>

            <div class="form-group">
              <label class="pe-label">Keywords</label>
              <input type="text" name="meta_keywords" class="form-control"
                     value="<?= View::e($cat['meta_keywords'] ?? '') ?>"
                     placeholder="capacete, agv, k3, moto, proteção">
              <p class="pe-field-hint">Separe por vírgulas. Impacto menor hoje em dia.</p>
            </div>
          </div>
        </div>

      </div>

      <!-- Coluna lateral -->
      <div class="admin-form-side">        

        <!-- Status -->
        <div class="admin-card">
          <div class="admin-card-header"><h3>Status</h3></div>
          <div class="admin-card-body">

            <label class="admin-check-label">
              <input type="checkbox" name="ativo" value="1"
                     <?= ($cat['ativo'] ?? 1) ? 'checked' : '' ?>>
              <span class="admin-check-custom"></span>
              Categoria ativa
            </label>

            <label class="admin-check-label" style="margin-top:10px;">
              <input type="checkbox" name="destaque" value="1"
                     <?= !empty($cat['destaque']) ? 'checked' : '' ?>>
              <span class="admin-check-custom"></span>
              Exibir em destaque no menu
            </label>

            <div class="form-group" style="margin-top:14px;">
              <label for="cat-ordem">Ordem de exibição</label>
              <input type="number" id="cat-ordem" name="ordem"
                     class="form-control"
                     value="<?= (int)($cat['ordem'] ?? 0) ?>"
                     min="0" max="999">
            </div>

          </div>
        </div>

        <!-- Categoria pai -->
        <div class="admin-card" style="margin-top:12px;">
          <div class="admin-card-header"><h3>Hierarquia</h3></div>
          <div class="admin-card-body">
            <div class="form-group">
              <label for="cat-parent">Categoria pai</label>
              <select id="cat-parent" name="parent_id" class="form-control">
                <option value="">— Nenhuma (raiz) —</option>
                <?php foreach ($parents as $p): ?>
                <option value="<?= (int)$p['id'] ?>"
                        <?= (int)$p['id'] === $preParentId ? 'selected' : '' ?>>
                  <?= !empty($p['parent_id']) ? '&nbsp;&nbsp;└ ' : '' ?>
                  <?= View::e($p['nome']) ?>
                </option>
                <?php endforeach; ?>
              </select>
              <small class="field-hint">
                Selecione para criar como subcategoria.
              </small>
            </div>
          </div>
        </div>

        <!-- Imagem -->
        <div class="admin-card" style="margin-top:12px;">
          <div class="admin-card-header"><h3>Imagem</h3></div>
          <div class="admin-card-body">

            <?php if (!empty($cat['imagem'])): ?>
            <div class="admin-img-preview" id="img-preview-wrap">
              <img src="<?= View::upload('categories/' . $cat['imagem']) ?>"
                   alt="" id="img-preview" style="max-width:100%;border-radius:8px;">
              <button type="button" class="admin-img-remove" id="btn-remove-img">
                Remover imagem
              </button>
            </div>
            <input type="hidden" name="imagem_atual"
                   value="<?= View::e($cat['imagem'] ?? '') ?>">
            <?php endif; ?>

            <div class="admin-upload-area" id="upload-area">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
                <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>
                <polyline points="17 8 12 3 7 8"/>
                <line x1="12" y1="3" x2="12" y2="15"/>
              </svg>
              <p>Clique ou arraste uma imagem</p>
              <small>PNG, JPG, WEBP — máx. 2MB</small>
              <input type="file" name="imagem" id="cat-imagem"
                     accept="image/*" class="upload-input-hidden">
            </div>

          </div>
        </div>

        <!-- Ações -->
        <div class="admin-form-actions">
          <button type="submit" class="btn btn-primary btn-full"
                  id="btn-salvar-cat">
            <?= $isEdit ? 'Salvar alterações' : 'Criar categoria' ?>
          </button>
          <a href="<?= BASE_URL ?>/admin/categorias"
             class="btn btn-ghost btn-full">
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
          tipo: 'categoria',
          getContexto: () => ({
              nome     : $('#cat-nome').val(),
              descricao: $('#cat-descricao').val(),
              parent   : $('#cat-parent option:checked').text(),
          }),
          campos: {
              meta_title      : '#cat-meta-title',
              meta_description: '#cat-meta-desc',
              keywords        : '[name="meta_keywords"]',
              // google_category : '[name="google_category"]',
          },
          container: '#section-cat-seo',
      });
    }
});
</script>