<?php
// Achata a árvore para renderizar com indentação
function renderCatRow(array $cat, int $depth = 0): void { ?>
<tr id="cat-row-<?= $cat['id'] ?>"
    data-id="<?= $cat['id'] ?>"
    class="cat-row <?= $depth > 0 ? 'cat-row--child cat-depth-' . $depth : '' ?>">

  <td class="cat-td-drag">
    <span class="admin-drag-handle" title="Arrastar">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2" stroke-linecap="round">
        <circle cx="9" cy="5" r="1"/><circle cx="15" cy="5" r="1"/>
        <circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/>
        <circle cx="9" cy="19" r="1"/><circle cx="15" cy="19" r="1"/>
      </svg>
    </span>
  </td>

  <td>
    <div class="cat-nome-wrap" style="padding-left:<?= $depth * 20 ?>px">
      <?php if ($depth > 0): ?>
      <span class="cat-indent-icon">└</span>
      <?php endif; ?>
      <?php if (!empty($cat['imagem'])): ?>
      <img src="<?= View::upload('categories/' . $cat['imagem']) ?>"
           alt="" width="28" height="28" class="cat-thumb">
      <?php else: ?>
      <span class="cat-thumb-placeholder">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
          <circle cx="8.5" cy="8.5" r="1.5"/>
          <polyline points="21 15 16 10 5 21"/>
        </svg>
      </span>
      <?php endif; ?>
      <div>
        <span class="cat-nome"><?= View::e($cat['nome']) ?></span>
        <span class="cat-slug">/<?= View::e($cat['slug']) ?></span>
      </div>
      <!-- admin/views/categorias/index.php — na coluna da categoria: -->
      <?php if (!empty($cat['busca_moto'])): ?>
      <span class="admin-badge admin-badge--info" title="Busca por moto ativa">
        <svg width="10" height="10" viewBox="0 0 24 24" fill="none"
            stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <circle cx="5.5" cy="17.5" r="3.5"/>
          <circle cx="18.5" cy="17.5" r="3.5"/>
          <path d="M15 6h-2l-3 8H5.5"/>
        </svg>
        Moto
      </span>
      <?php endif; ?>
    </div>
  </td>

  <td class="text-center">
    <?php if ((int)$cat['total_filhos'] > 0): ?>
    <span class="admin-badge admin-badge--info">
      <?= (int)$cat['total_filhos'] ?> subcategoria(s)
    </span>
    <?php else: ?>
    <span class="admin-muted">—</span>
    <?php endif; ?>
  </td>

  <td class="text-center">
    <?php if ((int)$cat['total_produtos'] > 0): ?>
    <a href="<?= BASE_URL ?>/admin/produtos?categoria_id=<?= $cat['id'] ?>"
       class="admin-badge admin-badge--muted">
      <?= (int)$cat['total_produtos'] ?> produto(s)
    </a>
    <?php else: ?>
    <span class="admin-muted">0</span>
    <?php endif; ?>
  </td>

  <td class="text-center">
    <button type="button"
            class="admin-toggle <?= $cat['ativo'] ? 'admin-toggle--on' : '' ?>"
            data-id="<?= $cat['id'] ?>"
            title="<?= $cat['ativo'] ? 'Ativo — clique para desativar' : 'Inativo — clique para ativar' ?>">
      <span class="admin-toggle-track">
        <span class="admin-toggle-thumb"></span>
      </span>
    </button>
  </td>

  <td class="text-center">
    <span class="admin-ordem-val"><?= (int)$cat['ordem'] ?></span>
  </td>

  <td>
    <div class="admin-row-actions">
      <?php if ($depth < 2): ?>
      <a href="<?= BASE_URL ?>/admin/categorias/criar?parent_id=<?= $cat['id'] ?>"
         class="btn btn-sm btn-ghost" title="Adicionar subcategoria">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <line x1="12" y1="5" x2="12" y2="19"/>
          <line x1="5"  y1="12" x2="19" y2="12"/>
        </svg>
      </a>
      <?php endif; ?>
      <a href="<?= BASE_URL ?>/admin/categorias/<?= $cat['id'] ?>/editar"
         class="btn btn-sm btn-ghost" title="Editar">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
          <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
        </svg>
      </a>
      <button type="button"
              class="btn btn-sm btn-ghost btn-danger btn-excluir-cat"
              data-id="<?= $cat['id'] ?>"
              data-nome="<?= View::e($cat['nome']) ?>"
              title="Excluir">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <polyline points="3 6 5 6 21 6"/>
          <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
          <path d="M10 11v6M14 11v6"/>
        </svg>
      </button>
    </div>
  </td>

</tr>

<?php
  // Renderiza filhos recursivamente
  foreach ($cat['children'] ?? [] as $filho) {
    renderCatRow($filho, $depth + 1);
  }
}
?>

<div class="admin-page">

  <div class="admin-page-header">
    <div>
      <h1>Categorias</h1>
      <p><?= count($categorias) ?> categorias cadastradas</p>
    </div>
    <a href="<?= BASE_URL ?>/admin/categorias/criar"
       class="btn btn-primary">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
        <line x1="12" y1="5" x2="12" y2="19"/>
        <line x1="5"  y1="12" x2="19" y2="12"/>
      </svg>
      Nova categoria
    </a>
  </div>

  <?php if (empty($categorias)): ?>
  <div class="admin-empty-state">
    <svg width="48" height="48" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="1" stroke-linecap="round">
      <path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/>
    </svg>
    <p>Nenhuma categoria cadastrada ainda.</p>
    <a href="<?= BASE_URL ?>/admin/categorias/criar" class="btn btn-primary">
      Criar primeira categoria
    </a>
  </div>

  <?php else: ?>
  <div class="admin-card">
    <div class="admin-card-header">
      <div class="admin-search-wrap">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <circle cx="11" cy="11" r="8"/>
          <line x1="21" y1="21" x2="16.65" y2="16.65"/>
        </svg>
        <input type="text" id="cat-search"
               class="admin-search-input"
               placeholder="Buscar categoria...">
      </div>
      <div style="display:flex;gap:8px;">
        <button type="button" class="btn btn-sm btn-ghost"
                id="btn-expandir-todos" title="Expandir todos">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <polyline points="15 3 21 3 21 9"/>
            <polyline points="9 21 3 21 3 15"/>
            <line x1="21" y1="3" x2="14" y2="10"/>
            <line x1="3" y1="21" x2="10" y2="14"/>
          </svg>
        </button>
        <button type="button" class="btn btn-sm btn-ghost"
                id="btn-salvar-ordem">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/>
            <polyline points="17 21 17 13 7 13 7 21"/>
            <polyline points="7 3 7 8 15 8"/>
          </svg>
          Salvar ordem
        </button>
      </div>
    </div>

    <div class="admin-table-wrap">
      <table class="admin-table" id="cat-table">
        <thead>
          <tr>
            <th width="32"></th>
            <th>Nome / Slug</th>
            <th class="text-center">Subcategorias</th>
            <th class="text-center">Produtos</th>
            <th class="text-center">Ativo</th>
            <th class="text-center">Ordem</th>
            <th width="120">Ações</th>
          </tr>
        </thead>
        <tbody id="cat-tbody">
          <?php foreach ($arvore as $cat): ?>
          <?php renderCatRow($cat, 0) ?>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

</div>