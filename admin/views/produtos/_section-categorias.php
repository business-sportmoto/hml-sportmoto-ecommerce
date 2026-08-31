<?php
// admin/views/produtos/_section-categorias.php

// Monta árvore de categorias com nível de profundidade
function buildCatTree(array $cats, ?int $parentId = null, int $depth = 0): array {
    $tree = [];
    foreach ($cats as $cat) {
        if ((int)($cat['parent_id'] ?? 0) === (int)$parentId) {
            $cat['_depth']    = $depth;
            $cat['_children'] = buildCatTree($cats, (int)$cat['id'], $depth + 1);
            $tree[] = $cat;
        }
    }
    return $tree;
}

function flattenCatTree(array $tree): array {
    $flat = [];
    foreach ($tree as $cat) {
        $flat[] = $cat;
        if (!empty($cat['_children'])) {
            foreach (flattenCatTree($cat['_children']) as $child) {
                $flat[] = $child;
            }
        }
    }
    return $flat;
}

function buildCatFullPath(int $id, array $map, string $sep = ' › '): string {
    $parts = [];
    $atual = $id;
    while ($atual && isset($map[$atual])) {
        $parts[] = $map[$atual]['nome'];
        $atual   = (int)($map[$atual]['parent_id'] ?? 0);
    }
    return implode($sep, array_reverse($parts));
}

$catMap      = array_column($categorias, null, 'id');
$catTree     = buildCatTree($categorias);
$catFlat     = flattenCatTree($catTree);
$mapaCats    = $mapaCategorias ?? []; // [categoria_id => is_principal]
?>

<section class="pe-section" id="pe-categorias">
  <div class="pe-section-head">
    <h2>Categorias</h2>
    <p>
      Vincule o produto a uma ou mais categorias.
      A marcada com
      <span class="cat-star-inline">
        <svg width="11" height="11" viewBox="0 0 24 24" fill="#f59e0b" stroke="#f59e0b" stroke-width="2">
          <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
        </svg>
      </span>
      é a principal — usada no breadcrumb, SEO e filtros.
    </p>
  </div>

  <div class="pe-card cats-card">

    <!-- ── Categorias selecionadas ───────────────────────── -->
    <div class="cats-selected-wrap">
      <div id="prod-cats-list" class="cats-selected-list">

        <?php if (empty($mapaCats)): ?>
        <div class="cats-empty-state" id="cats-empty-state">
          <div class="cats-empty-icon">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
              <path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/>
            </svg>
          </div>
          <span>Nenhuma categoria selecionada</span>
        </div>
        <?php endif; ?>

        <?php foreach ($mapaCats as $cid => $isPrincipal):
          if (!isset($catMap[$cid])) continue;
          $fullPath = buildCatFullPath($cid, $catMap);
          $parts    = explode(' › ', $fullPath);
          $leaf     = array_pop($parts);
          $parents  = implode(' › ', $parts);
        ?>
        <div class="cat-selected-item" data-id="<?= $cid ?>">
          <button type="button"
                  class="cat-star-btn <?= $isPrincipal ? 'is-principal' : '' ?>"
                  data-id="<?= $cid ?>"
                  title="<?= $isPrincipal ? 'Categoria principal' : 'Definir como principal' ?>">
            <svg width="15" height="15" viewBox="0 0 24 24"
                 fill="<?= $isPrincipal ? 'var(--warning)' : 'none' ?>"
                 stroke="<?= $isPrincipal ? 'var(--warning)' : 'var(--text-3)' ?>"
                 stroke-width="2" stroke-linecap="round">
              <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
            </svg>
          </button>

          <div class="cat-selected-label">
            <?php if ($parents): ?>
            <span class="cat-selected-path"><?= View::e($parents) ?> ›</span>
            <?php endif; ?>
            <span class="cat-selected-leaf"><?= View::e($leaf) ?></span>
          </div>

          <?php if ($isPrincipal): ?>
          <span class="cat-principal-badge">Principal</span>
          <?php endif; ?>

          <input type="hidden"
                 name="categorias[<?= $cid ?>][id]"
                 value="<?= $cid ?>">
          <input type="hidden"
                 name="categorias[<?= $cid ?>][principal]"
                 class="prod-cat-principal-input"
                 value="<?= $isPrincipal ? 1 : 0 ?>">

          <button type="button"
                  class="cat-remove-btn prod-cat-remove"
                  data-id="<?= $cid ?>"
                  title="Remover">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
              <line x1="18" y1="6" x2="6"  y2="18"/>
              <line x1="6"  y1="6" x2="18" y2="18"/>
            </svg>
          </button>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- ── Dropdown de busca ─────────────────────────────── -->
    <div class="cats-picker-wrap">
      <div class="cats-search-trigger" id="cats-search-trigger">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <line x1="12" y1="5" x2="12" y2="19"/>
          <line x1="5"  y1="12" x2="19" y2="12"/>
        </svg>
        <span>Adicionar categoria</span>
      </div>

      <!-- Dropdown (controlado via JS) -->
      <div class="cats-dropdown" id="cats-dropdown">
        <div class="cats-dropdown-search-wrap">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <circle cx="11" cy="11" r="8"/>
            <line x1="21" y1="21" x2="16.65" y2="16.65"/>
          </svg>
          <input type="text"
                 id="cats-search-input"
                 class="cats-search-input"
                 placeholder="Buscar categoria..."
                 autocomplete="off">
          <button type="button" class="cats-dropdown-close" id="cats-dropdown-close">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
              <line x1="18" y1="6" x2="6"  y2="18"/>
              <line x1="6"  y1="6" x2="18" y2="18"/>
            </svg>
          </button>
        </div>

        <div class="cats-dropdown-list" id="cats-dropdown-list">
          <?php foreach ($catFlat as $cat):
            $depth    = $cat['_depth'];
            $fullPath = buildCatFullPath($cat['id'], $catMap);
            $hasChild = !empty($cat['_children']);
          ?>
          <div class="cats-dropdown-item <?= $hasChild ? 'has-children' : '' ?>"
               data-id="<?= $cat['id'] ?>"
               data-full-path="<?= View::e($fullPath) ?>"
               data-nome="<?= View::e($cat['nome']) ?>"
               data-search="<?= View::e(mb_strtolower($fullPath)) ?>"
               style="--depth:<?= $depth ?>">

            <!-- Indentação visual -->
            <?php if ($depth > 0): ?>
            <span class="cats-item-indent">
              <?php for ($i = 0; $i < $depth - 1; $i++): ?>
              <span class="cats-indent-line"></span>
              <?php endfor; ?>
              <span class="cats-indent-corner"></span>
            </span>
            <?php endif; ?>

            <span class="cats-item-icon">
              <?php if ($hasChild): ?>
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/>
              </svg>
              <?php else: ?>
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <path d="M13 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V9z"/>
              </svg>
              <?php endif; ?>
            </span>

            <span class="cats-item-nome"><?= View::e($cat['nome']) ?></span>

            <?php if ($hasChild): ?>
            <span class="cats-item-badge">
              <?= count($cat['_children']) ?>
            </span>
            <?php endif; ?>

            <span class="cats-item-check" style="display:none;">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="3" stroke-linecap="round">
                <polyline points="20 6 9 17 4 12"/>
              </svg>
            </span>
          </div>
          <?php endforeach; ?>

          <div class="cats-dropdown-empty" id="cats-dropdown-empty" style="display:none;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
              <circle cx="11" cy="11" r="8"/>
              <line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <span>Nenhuma categoria encontrada</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Hidden com categoria principal -->
    <input type="hidden" name="categoria_id" id="pe-categoria-principal"
           value="<?= (int)($p['categoria_id'] ?? 0) ?>">

  </div>
</section>