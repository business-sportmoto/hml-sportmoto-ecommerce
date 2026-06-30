<?php
// views/moto/catalogo.php

// SEO dinâmico sem SeoHelper::set()
$pageTitle       = $seoTitle  ?? 'Peças compatíveis';
$pageDescription = $seoDesc   ?? '';

$db = Database::getInstance()->getConnection();

// Monta URL base para links internos
$urlBase = BASE_URL . '/montadora/' . $montadora['slug'];
if (!empty($modeloSlug)) {
    $urlBase .= '/' . $modeloSlug;
    if (!empty($ano)) $urlBase .= '-' . $ano;
}

// Filtros ativos
$catFiltro   = (int)($_GET['categoria_id'] ?? 0);
$marcaFiltro = (int)($_GET['marca_id']     ?? 0);
$qFiltro     = trim($_GET['q']             ?? '');
$ordem       = $_GET['ordem'] ?? 'relevancia';

// Montadoras para o mini-seletor
$allMontadoras = $db->query(
    "SELECT id, nome, slug FROM moto_montadoras WHERE ativo=1 ORDER BY nome ASC"
)->fetchAll();
?>

<!-- ══ BREADCRUMB ═══════════════════════════════════════════ -->
<div class="mc-breadcrumb-wrap">
  <div class="container">
    <nav class="mc-breadcrumb">
      <a href="<?= BASE_URL ?>">Início</a>
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2" stroke-linecap="round">
        <polyline points="9 18 15 12 9 6"/>
      </svg>      
      <?php foreach ($breadcrumb as $bc): ?>
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2" stroke-linecap="round">
        <polyline points="9 18 15 12 9 6"/>
      </svg>
      <?php if ($bc === end($breadcrumb)): ?>
        <span><?= View::e($bc['label']) ?></span>
      <?php else: ?>
        <a href="<?= View::e($bc['url']) ?>"><?= View::e($bc['label']) ?></a>
      <?php endif; ?>
      <?php endforeach; ?>
    </nav>
  </div>
</div>

<?php if (!empty($mostrarVeiculoBar)): ?>
<?php View::partial('partials/meu-veiculo-bar', [
    'veiculoAtivo' => $veiculoAtivo ?? null,
    // true quando o cliente já está na página da própria moto principal —
    // a barra muda de "ver peças da sua moto" para "você está vendo a
    // sua moto" (não esconde, mas também não repete o link redundante).
    'ehMotoAtual'  => $ehMotoAtual ?? false,
    'motoUrlOverride' => !empty($veiculoAtivo)
        ? BASE_URL . '/motos/buscar?' . http_build_query(array_filter([
              'montadora_id' => $veiculoAtivo['montadora_id'] ?? null,
              'modelo_id'    => $veiculoAtivo['modelo_id']    ?? null,
              'ano'          => $veiculoAtivo['ano']           ?? null,
          ]))
        : null,
]) ?>
<?php endif; ?>

<!-- ══ HERO DA MOTO ═════════════════════════════════════════ -->
<section class="mc-hero">
  <div class="container">
    <div class="mc-hero-inner">

      <!-- Logo da montadora -->
      <div class="mc-hero-logo">
        <?php if (!empty($montadora['thumb']) || !empty($montadora['logo'])): ?>
        <img src="<?= View::upload('motos/' . ($montadora['logo'] ?? $montadora['thumb'])) ?>"
             alt="<?= View::e($montadora['nome']) ?>">
        <?php else: ?>
        <span><?= mb_strtoupper(mb_substr($montadora['nome'], 0, 2)) ?></span>
        <?php endif; ?>
      </div>

      <!-- Título + info -->
      <div class="mc-hero-info">
        <h1 class="mc-hero-title"><?= View::e($pageTitle) ?></h1>
        <p class="mc-hero-count">
          <strong><?= number_format($total) ?></strong>
          produto<?= $total != 1 ? 's' : '' ?> compatíve<?= $total != 1 ? 'is' : 'l' ?>
        </p>
      </div>

      <!-- Trocar moto -->
      <a href="<?= BASE_URL ?>/motos" class="mc-trocar-moto">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <polyline points="23 4 23 10 17 10"/>
          <polyline points="1 20 1 14 7 14"/>
          <path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/>
        </svg>
        Trocar moto
      </a>

    </div>

    <!-- Mini busca para refinar -->
    <div class="mc-hero-refine">
      <form class="mc-refine-form" id="form-mc-refine">
        <div class="mc-refine-select-wrap">
          <select id="mc-montadora" class="mc-refine-select">
            <option value="">Montadora</option>
            <?php foreach ($allMontadoras as $m): ?>
            <option value="<?= $m['id'] ?>"
                    data-slug="<?= View::e($m['slug']) ?>"
                    <?= (int)$montadora['id'] === (int)$m['id'] ? 'selected' : '' ?>>
              <?= View::e($m['nome']) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <polyline points="6 9 12 15 18 9"/>
          </svg>
        </div>

        <div class="mc-refine-select-wrap">
          <select id="mc-modelo" class="mc-refine-select"
                  <?= empty($modelo) ? 'disabled' : '' ?>>
            <option value="">Todos os modelos</option>
            <?php
            if (!empty($modelo)):
              $stmtMods = $db->prepare(
                  "SELECT id, nome, slug FROM moto_modelos
                   WHERE montadora_id=? AND ativo=1 ORDER BY nome ASC"
              );
              $stmtMods->execute([$montadora['id']]);
              foreach ($stmtMods->fetchAll() as $mod):
            ?>
            <option value="<?= $mod['id'] ?>"
                    data-slug="<?= View::e($mod['slug']) ?>"
                    <?= (int)$modelo['id'] === (int)$mod['id'] ? 'selected' : '' ?>>
              <?= View::e($mod['nome']) ?>
            </option>
            <?php endforeach; endif; ?>
          </select>
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <polyline points="6 9 12 15 18 9"/>
          </svg>
        </div>

        <div class="mc-refine-select-wrap">
          <select id="mc-ano" class="mc-refine-select"
                  <?= empty($modelo) ? 'disabled' : '' ?>>
            <option value="">Todos os anos</option>
            <?php
            if (!empty($modelo)):
              $stmtAnos = $db->prepare(
                  "SELECT DISTINCT ano FROM moto_anos
                   WHERE modelo_id=? ORDER BY ano DESC"
              );
              $stmtAnos->execute([$modelo['id']]);
              foreach ($stmtAnos->fetchAll() as $a):
            ?>
            <option value="<?= $a['ano'] ?>"
                    <?= (int)$ano === (int)$a['ano'] ? 'selected' : '' ?>>
              <?= $a['ano'] ?>
            </option>
            <?php endforeach; endif; ?>
          </select>
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <polyline points="6 9 12 15 18 9"/>
          </svg>
        </div>

        <button type="submit" class="mc-refine-btn">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <circle cx="11" cy="11" r="8"/>
            <path d="m21 21-4.35-4.35"/>
          </svg>
          Buscar
        </button>
      </form>
    </div>
  </div>
</section>

<!-- ══ LAYOUT PRINCIPAL ══════════════════════════════════════ -->
<div class="container">
  <div class="mc-layout">

    <!-- ── SIDEBAR ──────────────────────────────────────── -->
    <aside class="mc-sidebar">

      <!-- Modelos disponíveis (quando só montadora) -->
      <?php if (!empty($modelos)): ?>
      <div class="mc-sidebar-card">
        <h3 class="mc-sidebar-title">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <circle cx="5.5" cy="17.5" r="3.5"/>
            <circle cx="18.5" cy="17.5" r="3.5"/>
            <path d="M15 6h-2l-3 8H5.5"/>
          </svg>
          Modelos disponíveis
        </h3>
        <ul class="mc-model-list">
          <?php foreach ($modelos as $mod): ?>
          <li>
            <a href="<?= BASE_URL ?>/montadora/<?= View::e($montadora['slug']) ?>/<?= View::e($mod['slug']) ?>"
               class="mc-model-item">
              <?php if (!empty($mod['thumb'])): ?>
              <img src="<?= View::upload('motos/' . $mod['thumb']) ?>"
                   alt="<?= View::e($mod['nome']) ?>"
                   class="mc-model-thumb">
              <?php else: ?>
              <span class="mc-model-thumb-empty">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
                  <circle cx="5.5" cy="17.5" r="3.5"/>
                  <circle cx="18.5" cy="17.5" r="3.5"/>
                  <path d="M15 6h-2l-3 8H5.5"/>
                </svg>
              </span>
              <?php endif; ?>
              <div>
                <span class="mc-model-nome"><?= View::e($mod['nome']) ?></span>
                <span class="mc-model-count">
                  <?= (int)$mod['total_produtos'] ?> peça<?= $mod['total_produtos'] != 1 ? 's' : '' ?>
                </span>
              </div>
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                <polyline points="9 18 15 12 9 6"/>
              </svg>
            </a>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>

      <!-- Anos disponíveis (quando tem modelo) -->
      <?php if (!empty($anos) && !empty($modelo)): ?>
      <div class="mc-sidebar-card">
        <h3 class="mc-sidebar-title">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <rect x="3" y="4" width="18" height="18" rx="2"/>
            <line x1="16" y1="2" x2="16" y2="6"/>
            <line x1="8"  y1="2" x2="8"  y2="6"/>
            <line x1="3"  y1="10" x2="21" y2="10"/>
          </svg>
          Filtrar por ano
        </h3>
        <div class="mc-anos-list">
          <a href="<?= BASE_URL ?>/montadora/<?= View::e($montadora['slug']) ?>/<?= View::e($modelo['slug']) ?>"
             class="mc-ano-item <?= !$ano ? 'is-active' : '' ?>">
            <span>Todos os anos</span>
          </a>
          <?php foreach ($anos as $a): ?>
          <a href="<?= BASE_URL ?>/montadora/<?= View::e($montadora['slug']) ?>/<?= View::e($modeloSlug) ?>-<?= $a['ano'] ?>"
             class="mc-ano-item <?= (int)$ano === (int)$a['ano'] ? 'is-active' : '' ?>">
            <span class="mc-ano-num"><?= $a['ano'] ?></span>
            <span class="mc-ano-count"><?= (int)$a['total_produtos'] ?></span>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Filtro de busca textual -->
      <div class="mc-sidebar-card">
        <form method="GET" action="<?= View::e($urlBase) ?>">
          <h3 class="mc-sidebar-title">Buscar nesta moto</h3>
          <div class="mc-sidebar-search">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <circle cx="11" cy="11" r="8"/>
              <path d="m21 21-4.35-4.35"/>
            </svg>
            <input type="text" name="q"
                   value="<?= View::e($qFiltro) ?>"
                   placeholder="Ex: filtro de óleo...">
          </div>
          <?php if ($catFiltro): ?>
          <input type="hidden" name="categoria_id" value="<?= $catFiltro ?>">
          <?php endif; ?>
          <?php if ($ordem !== 'relevancia'): ?>
          <input type="hidden" name="ordem" value="<?= View::e($ordem) ?>">
          <?php endif; ?>
          <button type="submit" class="mc-sidebar-search-btn">Buscar</button>
        </form>
      </div>

    </aside>

    <!-- ── CONTEÚDO ──────────────────────────────────────── -->
    <main class="mc-main">

      <!-- Toolbar -->
      <div class="mc-toolbar">
        <div class="mc-toolbar-left">
          <span class="mc-toolbar-count">
            <?= number_format($total) ?> produto<?= $total != 1 ? 's' : '' ?>
          </span>
          <?php if ($qFiltro): ?>
          <span class="mc-toolbar-tag">
            "<?= View::e($qFiltro) ?>"
            <a href="<?= View::e($urlBase) ?>">×</a>
          </span>
          <?php endif; ?>
        </div>
        <div class="mc-toolbar-right">
          <?php $ordens = [
            'relevancia'    => 'Relevância',
            'novidades'     => 'Novidades',
            'menor_preco'   => 'Menor preço',
            'maior_preco'   => 'Maior preço',
            'maior_desconto'=> 'Maior desconto',
            'mais_vendidos' => 'Mais vendidos',
          ]; ?>
          <div class="sort-dropdown" id="sort-dropdown">
            <label class="sort-label">Ordenar por:</label>

            <select id="sort-select" class="sort-select sort-select--native">
              <?php foreach ($ordens as $val => $label): ?>
              <option value="<?= $val ?>" <?= $ordem === $val ? 'selected' : '' ?>>
                <?= $label ?>
              </option>
              <?php endforeach; ?>
            </select>

            <button type="button" class="sort-trigger" id="sort-trigger"
                    aria-haspopup="listbox" aria-expanded="false">
              <span id="sort-trigger-label"><?= View::e($ordens[$ordem] ?? 'Relevância') ?></span>
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                <polyline points="6 9 12 15 18 9"/>
              </svg>
            </button>

            <ul class="sort-options" id="sort-options" role="listbox" hidden>
              <?php foreach ($ordens as $val => $label): ?>
              <li class="sort-option <?= $ordem === $val ? 'is-selected' : '' ?>"
                  role="option" data-value="<?= $val ?>"
                  aria-selected="<?= $ordem === $val ? 'true' : 'false' ?>">
                <?= $label ?>
                <?php if ($ordem === $val): ?>
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

      <!-- Grid de produtos -->
      <?php if (empty($produtos)): ?>
      <div class="mc-empty">
        <div class="mc-empty-icon">
          <svg width="40" height="40" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="1.2" stroke-linecap="round">
            <circle cx="5.5" cy="17.5" r="3.5"/>
            <circle cx="18.5" cy="17.5" r="3.5"/>
            <path d="M15 6h-2l-3 8H5.5"/>
            <path d="M15 6l3 5h1.5"/>
            <path d="M9 6h4"/>
          </svg>
        </div>
        <h3>Nenhum produto encontrado</h3>
        <p>
          Não encontramos peças compatíveis com
          <strong><?= View::e($pageTitle) ?></strong>.
        </p>
        <div class="mc-empty-actions">
          <?php if (!empty($modelo)): ?>
          <a href="<?= BASE_URL ?>/montadora/<?= View::e($montadora['slug']) ?>"
             class="mc-btn-primary">
            Ver todos os modelos <?= View::e($montadora['nome']) ?>
          </a>
          <?php endif; ?>
          <a href="<?= BASE_URL ?>/motos" class="mc-btn-outline">
            Escolher outra moto
          </a>
        </div>
      </div>

      <?php else: ?>

      <div class="products-grid products-grid--4" id="catalog-grid">
        <?php foreach ($produtos as $product): ?>
        
        <?php View::partial('partials/product-card', ['product' => $product]) ?>
        <?php endforeach; ?>
      </div>

      <!-- Paginação -->
      <?php $totalPages = (int)ceil($total / $perPage);
      if ($totalPages > 1): ?>
      <div class="mc-paginacao">
        <?php
        $buildUrl = function (int $p) use ($urlBase, $ordem, $qFiltro, $catFiltro): string {
          $q = http_build_query(array_filter([
            'ordem'        => $ordem !== 'relevancia' ? $ordem : null,
            'q'            => $qFiltro ?: null,
            'categoria_id' => $catFiltro ?: null,
            'page'         => $p > 1 ? $p : null,
          ]));
          return $urlBase . ($q ? '?' . $q : '');
        };
        ?>
        <?php if ($page > 1): ?>
        <a href="<?= $buildUrl($page - 1) ?>" class="mc-pag-btn">← Anterior</a>
        <?php endif; ?>

        <div class="mc-pag-nums">
          <?php for ($i = max(1,$page-2); $i <= min($totalPages,$page+2); $i++): ?>
          <a href="<?= $buildUrl($i) ?>"
             class="mc-pag-btn <?= $i===$page ? 'is-active' : '' ?>">
            <?= $i ?>
          </a>
          <?php endfor; ?>
        </div>

        <?php if ($page < $totalPages): ?>
        <a href="<?= $buildUrl($page + 1) ?>" class="mc-pag-btn">Próxima →</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <?php endif; ?>

    </main>
  </div>
</div>

<!-- JS do mini-refine -->
<script>
(function () {
  const $mont = document.getElementById('mc-montadora');
  const $mod  = document.getElementById('mc-modelo');
  const $ano  = document.getElementById('mc-ano');

  if (!$mont) return;

  $mont.addEventListener('change', function () {
    const id   = this.value;
    const slug = this.options[this.selectedIndex]?.dataset?.slug;
    $mont.dataset.slug = slug || '';
    $mod.innerHTML = '<option value="">Carregando...</option>';
    $mod.disabled  = true;
    $ano.innerHTML = '<option value="">Todos os anos</option>';
    $ano.disabled  = true;
    if (!id) return;
    fetch(`<?= BASE_URL ?>/ajax/moto/modelos?montadora_id=${id}`)
      .then(r => r.json()).then(list => {
        let opts = '<option value="">Todos os modelos</option>';
        list.forEach(m => { opts += `<option value="${m.id}" data-slug="${m.slug}">${m.nome}</option>`; });
        $mod.innerHTML = opts;
        $mod.disabled  = false;
      });
  });

  $mod.addEventListener('change', function () {
    const id = this.value;
    $ano.innerHTML = '<option value="">Carregando...</option>';
    $ano.disabled  = true;
    if (!id) { $ano.innerHTML = '<option value="">Todos os anos</option>'; return; }
    fetch(`<?= BASE_URL ?>/ajax/moto/anos?modelo_id=${id}`)
      .then(r => r.json()).then(list => {
        let opts = '<option value="">Todos os anos</option>';
        list.forEach(a => { opts += `<option value="${a.ano}">${a.ano}</option>`; });
        $ano.innerHTML = opts;
        $ano.disabled  = false;
      });
  });

  document.getElementById('form-mc-refine').addEventListener('submit', function (e) {
    e.preventDefault();
    const montSlug = $mont.dataset.slug || $mont.options[$mont.selectedIndex]?.dataset?.slug;
    if (!montSlug) return;
    const modSlug = $mod.options[$mod.selectedIndex]?.dataset?.slug;
    const ano     = $ano.value;
    let url = `<?= BASE_URL ?>/montadora/${montSlug}`;
    if (modSlug) { url += `/${modSlug}`; if (ano) url += `-${ano}`; }
    window.location.href = url;
  });
})();
</script>

<!-- Dropdown de ordenar + layout switcher: reaproveita catalog.js,
     o mesmo script já usado no catálogo de categorias. -->
<script src="<?= BASE_URL ?>/assets/js/catalog.js" defer></script>

<style>
/* ── Sort dropdown customizado (mesmo do catálogo de categorias) ── */
.sort-dropdown { position: relative; display: flex; align-items: center; gap: 8px; }
.sort-select--native {
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
  position: absolute; top: calc(100% + 6px); right: 0;
  min-width: 200px; background: #fff;
  border: 1px solid var(--c-border, #e2e8f0);
  border-radius: 12px;
  box-shadow: 0 8px 28px rgba(15,23,42,.12);
  padding: 6px; margin: 0; list-style: none;
  z-index: 30; max-height: 320px; overflow-y: auto;
}
.sort-option {
  display: flex; align-items: center; justify-content: space-between; gap: 10px;
  padding: 9px 12px; border-radius: 8px;
  font-size: 13.5px; color: #334155; cursor: pointer;
  transition: background .12s;
}
.sort-option:hover { background: #f1f5f9; }
.sort-option.is-selected { background: #eff6ff; color: var(--c-primary, #2563eb); font-weight: 700; }
.sort-option svg { flex-shrink: 0; color: var(--c-primary, #2563eb); }
@media (max-width: 600px) {
  .sort-label { display: none; }
  .sort-trigger { min-width: 0; }
}

/* ── Layout switcher ──────────────────────────────────── */
.layout-switcher { display: flex; gap: 4px; }
.layout-btn {
  width: 32px; height: 32px; border-radius: 8px;
  border: 1.5px solid var(--c-border, #e2e8f0); background: #fff;
  color: #94a3b8; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  transition: border-color .15s, color .15s, background .15s;
}
.layout-btn:hover { border-color: var(--c-primary, #2563eb); color: var(--c-primary, #2563eb); }
.layout-btn.is-active { background: var(--c-primary, #2563eb); border-color: var(--c-primary, #2563eb); color: #fff; }

/* ── Grid 4 (já é o padrão desta view) ──────────────────── */
.products-grid--4 { grid-template-columns: repeat(4, 1fr) !important; }
@media (max-width: 1024px) { .products-grid--4 { grid-template-columns: repeat(3, 1fr) !important; } }
@media (max-width: 680px)  { .products-grid--4 { grid-template-columns: repeat(2, 1fr) !important; } }

/* ── Lista ───────────────────────────────────────────── */
.products-grid--list {
  display: flex !important; flex-direction: column !important; gap: 12px !important;
}
.products-grid--list .product-card {
  display: grid !important;
  grid-template-columns: 120px 1fr auto;
  align-items: center;
  gap: 14px;
  padding: 12px !important;
}
.products-grid--list .product-card__image { width: 120px; height: 120px; flex-shrink: 0; }
.products-grid--list .product-card__img   { aspect-ratio: 1; object-fit: contain; }
.products-grid--list .product-card__name  { font-size: 14.5px; -webkit-line-clamp: 2; }
.products-grid--list .product-card__price { font-size: 17px; }
@media (max-width: 480px) {
  .products-grid--list .product-card { grid-template-columns: 80px 1fr; }
  .products-grid--list .product-card__image { width: 80px; height: 80px; }
}
</style>