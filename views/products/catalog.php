<?php
// views/products/catalog.php — Listagem de produtos com filtros laterais
$ordens = [
    'relevancia'    => 'Relevância',
    'novidades'     => 'Novidades',
    'menor_preco'   => 'Menor preço',
    'maior_preco'   => 'Maior preço',
    'maior_desconto'=> 'Maior desconto',
    'mais_vendidos' => 'Mais vendidos',
    'mais_vistos'   => 'Mais vistos',
];
$ordemAtual = $filters['ordem'] ?? 'relevancia';
?>

<!-- Breadcrumb -->
<?php if (!empty($breadcrumb)): ?>
<nav class="breadcrumb-nav" aria-label="Você está em">
  <div class="container">
    <ol class="breadcrumb">
      <?php foreach ($breadcrumb as $i => $crumb): ?>
      <li class="breadcrumb-item <?= $crumb['url'] === null ? 'active' : '' ?>">
        <?php if ($crumb['url']): ?>
          <a href="<?= View::e($crumb['url']) ?>"><?= View::e($crumb['label']) ?></a>
        <?php else: ?>
          <span><?= View::e($crumb['label']) ?></span>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ol>
  </div>
</nav>
<?php endif; ?>

<?php if (!empty($mostrarVeiculoBar)): ?>
<?php View::partial('partials/meu-veiculo-bar', [
    'produtosCompativeis' => $produtosCompativeis ?? [],
    'veiculoAtivo'        => $veiculoAtivo ?? null,
    'category'            => $category ?? null,
]) ?>
<?php endif; ?>

<?php if (!empty($marca)): ?>
<div class="brand-hero">
  <div class="container">
    <div class="brand-hero-inner">

      <?php
      $logoBg = !empty($marca['bg_cor']) ? $marca['bg_cor'] : '#f8fafc';
      ?>
      <div class="brand-hero-logo" style="background:<?= View::e($logoBg) ?>">
        <?php if (!empty($marca['logo'])): ?>
        <img src="<?= View::upload('brands/' . $marca['logo']) ?>"
             alt="<?= View::e($marca['nome']) ?>" loading="eager">
        <?php else: ?>
        <span class="brand-hero-initials"><?= View::e(strtoupper($marca['nome'])) ?></span>
        <?php endif; ?>
      </div>

      <div class="brand-hero-info">
        <h1><?= View::e($marca['nome']) ?></h1>
        <?php if (!empty($marca['descricao'])): ?>
        <p class="brand-hero-desc"><?= nl2br(View::e($marca['descricao'])) ?></p>
        <?php endif; ?>
        <div class="brand-hero-meta">
          <span class="brand-hero-count">
            <strong><?= number_format($total) ?></strong>
            <?= $total == 1 ? 'produto' : 'produtos' ?>
          </span>
          <?php if (!empty($marca['site'])): ?>
          <a href="<?= View::e($marca['site']) ?>"
             target="_blank" rel="noopener nofollow"
             class="brand-hero-site">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <circle cx="12" cy="12" r="10"/>
              <line x1="2" y1="12" x2="22" y2="12"/>
              <path d="M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"/>
            </svg>
            Site oficial
          </a>
          <?php endif; ?>
        </div>
      </div>

    </div>
  </div>
</div>
<?php endif; ?>

<div class="catalog-page">
  <div class="container">

    <!-- Logo após o header da categoria e antes da sidebar/grid: -->
    <?php 
    if(isset($temBuscaMoto)):
      if ($temBuscaMoto && !empty($montadoras)): ?>
      <?php View::partial('partials/moto-search-bar', [
          'montadoras' => $montadoras,
          'motoFiltro' => $motoFiltro,
          'categoria'  => $category,
      ]) ?>
      <?php endif; ?>
    <?php endif; ?>

    <!-- Cabeçalho do catálogo -->
    <div class="catalog-header">
      <div class="catalog-header-left">
        <h1 class="catalog-title"><?= View::e($title ?? 'Produtos') ?></h1>
        <span class="catalog-count"><?= number_format($total) ?> produto<?= $total !== 1 ? 's' : '' ?></span>
      </div>
      <div class="catalog-header-right">
        <button class="btn-filter-mobile" id="btn-filter-mobile" aria-label="Filtros">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <line x1="4" y1="6"  x2="20" y2="6"/>
            <line x1="8" y1="12" x2="16" y2="12"/>
            <line x1="11" y1="18" x2="13" y2="18"/>
          </svg>
          Filtros
        </button>
        <div class="catalog-sort sort-dropdown" id="sort-dropdown">
          <label class="sort-label" id="sort-label-trigger">Ordenar por:</label>

          <!-- Select nativo: mantém acessibilidade, submissão de formulário
               e serve de fonte de verdade do valor selecionado. -->
          <select id="sort-select" class="sort-select sort-select--native">
            <?php foreach ($ordens as $val => $label): ?>
            <option value="<?= $val ?>" <?= $ordemAtual === $val ? 'selected' : '' ?>>
              <?= $label ?>
            </option>
            <?php endforeach; ?>
          </select>

          <!-- Dropdown customizado por cima — sincronizado via JS -->
          <button type="button" class="sort-trigger" id="sort-trigger"
                  aria-haspopup="listbox" aria-expanded="false">
            <span id="sort-trigger-label"><?= View::e($ordens[$ordemAtual] ?? 'Relevância') ?></span>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
              <polyline points="6 9 12 15 18 9"/>
            </svg>
          </button>

          <ul class="sort-options" id="sort-options" role="listbox" hidden>
            <?php foreach ($ordens as $val => $label): ?>
            <li class="sort-option <?= $ordemAtual === $val ? 'is-selected' : '' ?>"
                role="option" data-value="<?= $val ?>"
                aria-selected="<?= $ordemAtual === $val ? 'true' : 'false' ?>">
              <?= $label ?>
              <?php if ($ordemAtual === $val): ?>
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                <polyline points="20 6 9 17 4 12"/>
              </svg>
              <?php endif; ?>
            </li>
            <?php endforeach; ?>
          </ul>
        </div>
        <!-- Layout switcher -->
        <div class="layout-switcher" role="group" aria-label="Modo de exibição">
          <button class="layout-btn" data-layout="grid3" title="Grade 3 colunas">
            <svg width="14" height="14" viewBox="0 0 22 22" fill="currentColor">
              <rect x="1" y="1" width="9" height="9" rx="1"/><rect x="12" y="1" width="9" height="9" rx="1"/>
              <rect x="1" y="12" width="9" height="9" rx="1"/><rect x="12" y="12" width="9" height="9" rx="1"/>
            </svg>
          </button>
          <button class="layout-btn" data-layout="grid4" title="Grade 4 colunas">
            <svg width="14" height="14" viewBox="0 0 22 22" fill="currentColor">
              <rect x="1"  y="1" width="5.5" height="9" rx="1"/><rect x="8.5" y="1" width="5" height="9" rx="1"/>
              <rect x="15" y="1" width="6"   height="9" rx="1"/>
              <rect x="1"  y="12" width="5.5" height="9" rx="1"/><rect x="8.5" y="12" width="5" height="9" rx="1"/>
              <rect x="15" y="12" width="6"   height="9" rx="1"/>
            </svg>
          </button>
          <button class="layout-btn" data-layout="list" title="Lista">
            <svg width="14" height="14" viewBox="0 0 22 22" fill="none" stroke="currentColor"
                 stroke-width="2.5" stroke-linecap="round">
              <rect x="1" y="2" width="4" height="4" rx="0.5" fill="currentColor" stroke="none"/>
              <line x1="8" y1="4" x2="21" y2="4"/>
              <rect x="1" y="9" width="4" height="4" rx="0.5" fill="currentColor" stroke="none"/>
              <line x1="8" y1="11" x2="21" y2="11"/>
              <rect x="1" y="16" width="4" height="4" rx="0.5" fill="currentColor" stroke="none"/>
              <line x1="8" y1="18" x2="21" y2="18"/>
            </svg>
          </button>
        </div>
      </div>
      </div>
    </div>

    <!-- Filtros ativos (pills) -->
    <?php
    $hasFilters = !empty($filters['q']) || !empty($filters['marcas'])
               || !empty($filters['preco_min']) || !empty($filters['preco_max'])
               || !empty($filters['em_promocao']);
    ?>
    <?php if ($hasFilters): ?>
    <div class="active-filters container" id="active-filters">
      <?php if (!empty($filters['q'])): ?>
        <span class="filter-pill">
          Busca: "<?= View::e($filters['q']) ?>"
          <a href="?" class="pill-remove" aria-label="Remover filtro">×</a>
        </span>
      <?php endif; ?>
      <?php if (!empty($filters['em_promocao'])): ?>
        <span class="filter-pill">
          Em promoção
          <a href="<?= View::e(http_build_query(array_merge($_GET, ['em_promocao' => '']))) ?>"
             class="pill-remove">×</a>
        </span>
      <?php endif; ?>
      <a href="?" class="clear-filters">Limpar filtros</a>
    </div>
    <?php endif; ?>

    <div class="catalog-layout container">

      <!-- ── Sidebar de filtros ─────────────────────────── -->
      <aside class="catalog-sidebar" id="catalog-sidebar">

        

        <div class="sidebar-header">
          <h2 class="sidebar-title">Filtros</h2>
          <button class="sidebar-close" id="btn-sidebar-close" aria-label="Fechar filtros">×</button>
        </div>

        <form id="filter-form" method="GET" action="">
          <?php
          // Preserva ordenação e busca ao filtrar
          if (!empty($filters['q'])): ?>
            <input type="hidden" name="q" value="<?= View::e($filters['q']) ?>">
          <?php endif; ?>
          <?php if (!empty($filters['ordem'])): ?>
            <input type="hidden" name="ordem" id="filter-ordem" value="<?= View::e($filters['ordem']) ?>">
          <?php endif; ?>

          <!-- Disponibilidade -->
          <div class="filter-group">
            <h3 class="filter-group-title">Disponibilidade</h3>
            <label class="filter-check">
              <input type="checkbox" name="com_estoque" value="1"
                     <?= !empty($filters['com_estoque']) ? 'checked' : '' ?>>
              <span class="check-custom"></span>
              Em estoque
            </label>
            <label class="filter-check">
              <input type="checkbox" name="em_promocao" value="1"
                     <?= !empty($filters['em_promocao']) ? 'checked' : '' ?>>
              <span class="check-custom"></span>
              Em promoção
            </label>
          </div>

          <!-- Faixa de preço -->
          <?php if (!empty($priceRange['min_price']) || !empty($priceRange['max_price'])): ?>
          <div class="filter-group">
            <h3 class="filter-group-title">Preço</h3>
            <div class="price-range-inputs">
              <div class="price-input-wrap">
                <span class="price-currency">R$</span>
                <input type="number" name="preco_min" id="preco_min" class="price-input"
                       placeholder="<?= number_format((float)($priceRange['min_price'] ?? 0), 0, ',', '.') ?>"
                       value="<?= View::e($filters['preco_min'] ?? '') ?>"
                       min="0" step="1">
              </div>
              <span class="price-range-sep">até</span>
              <div class="price-input-wrap">
                <span class="price-currency">R$</span>
                <input type="number" name="preco_max" id="preco_max" class="price-input"
                       placeholder="<?= number_format((float)($priceRange['max_price'] ?? 9999), 0, ',', '.') ?>"
                       value="<?= View::e($filters['preco_max'] ?? '') ?>"
                       min="0" step="1">
              </div>
            </div>
            <!-- Slider visual de preço -->
            <div class="price-slider-wrap">
              <div class="price-track">
                <div class="price-range-fill" id="price-range-fill"></div>
              </div>
              <input type="range" class="price-slider" id="slider-min"
                     min="<?= (int)($priceRange['min_price'] ?? 0) ?>"
                     max="<?= (int)($priceRange['max_price'] ?? 9999) ?>"
                     value="<?= (int)($filters['preco_min'] ?? ($priceRange['min_price'] ?? 0)) ?>">
              <input type="range" class="price-slider" id="slider-max"
                     min="<?= (int)($priceRange['min_price'] ?? 0) ?>"
                     max="<?= (int)($priceRange['max_price'] ?? 9999) ?>"
                     value="<?= (int)($filters['preco_max'] ?? ($priceRange['max_price'] ?? 9999)) ?>">
            </div>
          </div>
          <?php endif; ?>

          <!-- Marcas -->
          <?php if (!empty($brands)): ?>
          <div class="filter-group">
            <h3 class="filter-group-title">Marcas</h3>
            <div class="filter-brands-list" id="brands-list">
              <?php foreach ($brands as $brand): ?>
              <label class="filter-check">
                <input type="checkbox" name="marcas[]"
                       value="<?= (int)$brand['id'] ?>"
                       <?= in_array((int)$brand['id'], $filters['marcas'] ?? []) ? 'checked' : '' ?>>
                <span class="check-custom"></span>
                <?= View::e($brand['nome']) ?>
                <span class="filter-count">(<?= (int)$brand['total'] ?>)</span>
              </label>
              <?php endforeach; ?>
              <?php if (count($brands) > 6): ?>
              <button type="button" class="btn-show-more" data-target="brands-list">
                Ver mais marcas
              </button>
              <?php endif; ?>
            </div>
          </div>
          <?php endif; ?>

          <!-- Variações (Tamanho, Cor — atributos de SKU) -->
          <?php if (!empty($atributosFilter)): ?>
          <div class="filter-group">
            <h3 class="filter-group-title">Variações</h3>
            <?php foreach ($atributosFilter as $tipo => $valores): ?>
            <div class="filter-attr-group">
              <button type="button" class="filter-attr-toggle" aria-expanded="false">
                <?= View::e($tipo) ?>
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                  <polyline points="6 9 12 15 18 9"/>
                </svg>
              </button>
              <div class="filter-attr-list" hidden>
                <?php foreach ($valores as $v): ?>
                <label class="filter-check">
                  <input type="checkbox"
                         name="atributos[<?= View::e($tipo) ?>][]"
                         value="<?= View::e($v['valor']) ?>"
                         <?= in_array($v['valor'], (array)($filters['atributos'][$tipo] ?? []))
                             ? 'checked' : '' ?>>
                  <span class="check-custom"></span>
                  <?= View::e($v['valor']) ?>
                  <span class="filter-count">(<?= $v['total'] ?>)</span>
                </label>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <!-- Características (sistema próprio: caracteristicas +
               produto_caracteristicas — diferente de atributos/SKU) -->
          <?php if (!empty($caracteristicasFilter)): ?>
          <div class="filter-group">
            <h3 class="filter-group-title">Características</h3>
            <?php foreach ($caracteristicasFilter as $nomeChar => $valores): ?>
            <div class="filter-attr-group">
              <button type="button" class="filter-attr-toggle" aria-expanded="false">
                <?= View::e($nomeChar) ?>
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                  <polyline points="6 9 12 15 18 9"/>
                </svg>
              </button>
              <div class="filter-attr-list" hidden>
                <?php foreach ($valores as $v): ?>
                <label class="filter-check">
                  <input type="checkbox"
                         name="caracteristicas[<?= View::e($nomeChar) ?>][]"
                         value="<?= View::e($v['valor']) ?>"
                         <?= in_array($v['valor'], (array)($filters['caracteristicas'][$nomeChar] ?? []))
                             ? 'checked' : '' ?>>
                  <span class="check-custom"></span>
                  <?= View::e($v['valor']) ?>
                  <span class="filter-count">(<?= $v['total'] ?>)</span>
                </label>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <!-- Subcategorias -->
          <?php if (!empty($subcats)): ?>
          <div class="filter-group">
            <h3 class="filter-group-title">Subcategorias</h3>
            <ul class="filter-cats">
              <?php foreach ($subcats as $sub): ?>
              <li>
                <a href="<?= BASE_URL ?>/categoria/<?= View::e($sub['slug']) ?>"
                   class="filter-cat-link">
                  <?= View::e($sub['nome']) ?>
                </a>
              </li>
              <?php endforeach; ?>
            </ul>
          </div>
          <?php endif; ?>

          <button type="submit" class="btn btn-primary btn-full btn-apply-filter">
            Aplicar filtros
          </button>
        </form>
      </aside>

      <!-- ── Grade de produtos ──────────────────────────── -->
      <div class="catalog-content">

        <?php if (empty($products)): ?>
        <div class="catalog-empty">
          <svg width="80" height="80" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
            <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
          </svg>
          <h3>Nenhum produto encontrado</h3>
          <p>Tente outros filtros ou termos de busca.</p>
          <a href="<?= BASE_URL ?>/busca" class="btn btn-primary">Ver todos os produtos</a>
        </div>
        <?php else: ?>
        <div class="products-grid products-grid--3" id="catalog-grid">
          <?php foreach ($products as $product): ?>
            <?php View::partial('partials/product-card', ['product' => $product]) ?>
          <?php endforeach; ?>
        </div>

        <!-- Paginação -->
        <?php if ($has_pages): ?>
        <nav class="pagination-wrap" aria-label="Paginação">
          <?php if ($prev): ?>
            <a href="<?= View::e($pagination->url($prev)) ?>"
               class="page-btn page-btn--prev" aria-label="Página anterior">
              &lsaquo;
            </a>
          <?php endif; ?>

          <?php foreach ($pages as $p): ?>
            <?php if ($p === '...'): ?>
              <span class="page-ellipsis">&hellip;</span>
            <?php else: ?>
              <a href="<?= View::e($pagination->url($p)) ?>"
                 class="page-btn <?= $p === $current_page ? 'active' : '' ?>"
                 <?= $p === $current_page ? 'aria-current="page"' : '' ?>>
                <?= $p ?>
              </a>
            <?php endif; ?>
          <?php endforeach; ?>

          <?php if ($next): ?>
            <a href="<?= View::e($pagination->url($next)) ?>"
               class="page-btn page-btn--next" aria-label="Próxima página">
              &rsaquo;
            </a>
          <?php endif; ?>
        </nav>
        <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>

<div class="sidebar-overlay" id="sidebar-overlay"></div>

<style>
/* ── Sort dropdown customizado ────────────────────────── */
.sort-dropdown { position: relative; display: flex; align-items: center; gap: 8px; }
.sort-select--native {
  /* Mantido funcional (acessibilidade/fallback) mas invisível */
  position: absolute; opacity: 0; pointer-events: none; width: 1px; height: 1px;
}
.sort-trigger {
  display: flex; align-items: center; gap: 8px;
  padding: 8px 14px;
  border: 1.5px solid var(--c-border, #e2e8f0);
  border-radius: 10px;
  background: #fff;
  font-size: 13.5px; font-weight: 600; color: var(--c-heading, #1e293b);
  cursor: pointer;
  transition: border-color .15s, box-shadow .15s;
  min-width: 168px;
  justify-content: space-between;
}
.sort-trigger:hover { border-color: var(--c-primary, #2563eb); }
.sort-trigger:focus-visible {
  outline: none;
  border-color: var(--c-primary, #2563eb);
  box-shadow: 0 0 0 3px rgba(37,99,235,.12);
}
.sort-trigger svg { flex-shrink: 0; color: #94a3b8; transition: transform .2s; }
.sort-dropdown.is-open .sort-trigger svg { transform: rotate(180deg); }
.sort-dropdown.is-open .sort-trigger {
  border-color: var(--c-primary, #2563eb);
  box-shadow: 0 0 0 3px rgba(37,99,235,.12);
}

.sort-options {
  position: absolute;
  top: calc(100% + 6px);
  right: 0;
  min-width: 200px;
  background: #fff;
  border: 1px solid var(--c-border, #e2e8f0);
  border-radius: 12px;
  box-shadow: 0 8px 28px rgba(15,23,42,.12);
  padding: 6px;
  margin: 0;
  list-style: none;
  z-index: 30;
  max-height: 320px;
  overflow-y: auto;
}
.sort-option {
  display: flex; align-items: center; justify-content: space-between;
  gap: 10px;
  padding: 9px 12px;
  border-radius: 8px;
  font-size: 13.5px; color: #334155;
  cursor: pointer;
  transition: background .12s;
}
.sort-option:hover { background: #f1f5f9; }
.sort-option.is-selected {
  background: #eff6ff;
  color: var(--c-primary, #2563eb);
  font-weight: 700;
}
.sort-option svg { flex-shrink: 0; color: var(--c-primary, #2563eb); }

@media (max-width: 600px) {
  .sort-label { display: none; }
  .sort-trigger { min-width: 0; }
  .sort-options { right: 0; left: auto; }
}

/* ── Layout switcher ──────────────────────────────────── */
.layout-switcher { display:flex; gap:4px; }
.layout-btn {
  width:32px; height:32px; border-radius:8px;
  border:1.5px solid var(--c-border,#e2e8f0); background:#fff;
  color:#94a3b8; cursor:pointer;
  display:flex; align-items:center; justify-content:center;
  transition:border-color .15s, color .15s, background .15s;
}
.layout-btn:hover { border-color:var(--c-primary,#2563eb); color:var(--c-primary,#2563eb); }
.layout-btn.is-active { background:var(--c-primary,#2563eb); border-color:var(--c-primary,#2563eb); color:#fff; }

/* ── Grid 4 ───────────────────────────────────────────── */
.products-grid--4 { grid-template-columns: repeat(4, 1fr) !important; }
@media (max-width:1024px) { .products-grid--4 { grid-template-columns: repeat(3, 1fr) !important; } }
@media (max-width:680px)  { .products-grid--4 { grid-template-columns: repeat(2, 1fr) !important; } }

/* ── Lista ───────────────────────────────────────────── */
.products-grid--list {
  display:flex !important; flex-direction:column !important; gap:12px !important;
}
.products-grid--list .product-card {
  display:grid !important;
  grid-template-columns:120px 1fr auto;
  align-items:center;
  gap:14px;
  padding:12px !important;
}
.products-grid--list .product-card__image { width:120px; height:120px; flex-shrink:0; }
.products-grid--list .product-card__img   { aspect-ratio:1; object-fit:contain; }
.products-grid--list .product-card__name  { font-size:14.5px; -webkit-line-clamp:2; }
.products-grid--list .product-card__price { font-size:17px; }
@media (max-width:480px) {
  .products-grid--list .product-card { grid-template-columns:80px 1fr; }
  .products-grid--list .product-card__image { width:80px; height:80px; }
}

/* ── Price slider dual range ──────────────────────────── */
.price-slider-wrap { position:relative; height:28px; margin-top:12px; }
.price-track {
  position:absolute; top:50%; transform:translateY(-50%);
  left:0; right:0; height:4px;
  background:#e2e8f0; border-radius:2px;
}
.price-range-fill {
  position:absolute; height:100%;
  background:var(--c-primary,#2563eb); border-radius:2px;
}
.price-slider {
  position:absolute; top:0; left:0; width:100%; height:100%;
  background:transparent; pointer-events:none;
  -webkit-appearance:none; appearance:none;
}
.price-slider::-webkit-slider-thumb {
  pointer-events:all; -webkit-appearance:none;
  width:18px; height:18px;
  background:var(--c-primary,#2563eb); border-radius:50%;
  cursor:pointer; border:2px solid #fff;
  box-shadow:0 1px 4px rgba(0,0,0,.2);
}
.price-slider::-moz-range-thumb {
  pointer-events:all; width:18px; height:18px;
  background:var(--c-primary,#2563eb); border-radius:50%;
  cursor:pointer; border:2px solid #fff;
  box-shadow:0 1px 4px rgba(0,0,0,.2);
}

/* ── Características / Atributos ─────────────────────── */
.filter-attr-group { margin-bottom:8px; }
.filter-attr-toggle {
  width:100%; display:flex; justify-content:space-between; align-items:center;
  background:none; border:none; padding:6px 0; cursor:pointer;
  font-size:13px; font-weight:600; color:var(--c-heading,#1e293b);
}
.filter-attr-toggle svg { transition:transform .2s; flex-shrink:0; }
.filter-attr-toggle[aria-expanded="true"] svg { transform:rotate(180deg); }
.filter-attr-list { padding-top:4px; }
</style>

<script src="<?= BASE_URL ?>/assets/js/catalog.js" defer></script>