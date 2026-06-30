<?php
// views/moto/montadoras.php
$pageTitle       = 'Peças por Moto — Encontre peças compatíveis com a sua moto';
$pageDescription = 'Selecione a montadora, modelo e ano da sua moto e encontre todas as peças compatíveis.';

// Carrega montadoras com contadores para o select do hero
$db = Database::getInstance()->getConnection();
$allMontadoras = $db->query(
    "SELECT id, nome, slug FROM moto_montadoras WHERE ativo=1 ORDER BY nome ASC"
)->fetchAll();
?>

<div class="mm-page">

  <!-- ══ HERO ══════════════════════════════════════════════ -->
  <section class="mm-hero">
    <div class="mm-hero-bg"></div>
    <div class="container mm-hero-inner">

      <div class="mm-hero-text">
        <span class="mm-eyebrow">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <circle cx="5.5" cy="17.5" r="3.5"/>
            <circle cx="18.5" cy="17.5" r="3.5"/>
            <path d="M15 6h-2l-3 8H5.5"/>
            <path d="M15 6l3 5h1.5"/>
            <path d="M9 6h4"/>
          </svg>
          Busca por compatibilidade
        </span>
        <h1 class="mm-hero-title">
          Encontre as peças<br>
          <span class="mm-hero-accent">certas para a sua moto</span>
        </h1>
        <p class="mm-hero-sub">
          Selecione montadora, modelo e ano. Mostramos só o que é compatível.
        </p>
      </div>

      <!-- Formulário de busca em cascata -->
      <div class="mm-search-box">
        <div class="mm-search-box-header">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <circle cx="11" cy="11" r="8"/>
            <path d="m21 21-4.35-4.35"/>
          </svg>
          Selecione sua moto
        </div>

        <form id="form-busca-moto-hero" class="mm-search-form">
          <div class="mm-search-field">
            <label class="mm-field-label">Montadora</label>
            <div class="mm-select-wrap">
              <select id="hs-montadora" class="mm-select" required>
                <option value="">Selecione a montadora</option>
                <?php foreach ($montadoras as $m): ?>
                <option value="<?= $m['id'] ?>"
                        data-slug="<?= View::e($m['slug']) ?>">
                  <?= View::e($m['nome']) ?>
                </option>
                <?php endforeach; ?>
              </select>
              <svg class="mm-select-icon" width="14" height="14" viewBox="0 0 24 24"
                   fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                <polyline points="6 9 12 15 18 9"/>
              </svg>
            </div>
          </div>

          <div class="mm-search-field">
            <label class="mm-field-label">Modelo</label>
            <div class="mm-select-wrap">
              <select id="hs-modelo" class="mm-select" disabled required>
                <option value="">Selecione o modelo</option>
              </select>
              <svg class="mm-select-icon" width="14" height="14" viewBox="0 0 24 24"
                   fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                <polyline points="6 9 12 15 18 9"/>
              </svg>
            </div>
          </div>

          <div class="mm-search-field">
            <label class="mm-field-label">Ano</label>
            <div class="mm-select-wrap">
              <select id="hs-ano" class="mm-select" disabled>
                <option value="">Todos os anos</option>
              </select>
              <svg class="mm-select-icon" width="14" height="14" viewBox="0 0 24 24"
                   fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                <polyline points="6 9 12 15 18 9"/>
              </svg>
            </div>
          </div>

          <button type="submit" class="mm-search-btn" id="hs-btn" disabled>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
              <circle cx="11" cy="11" r="8"/>
              <path d="m21 21-4.35-4.35"/>
            </svg>
            Ver peças compatíveis
          </button>
        </form>
      </div>

    </div>
  </section>

  <!-- ══ STATS ═════════════════════════════════════════════ -->
  <div class="container">
    <div class="mm-stats-row">
      <div class="mm-stat">
        <strong><?= number_format(count($montadoras)) ?></strong>
        <span>Montadoras</span>
      </div>
      <div class="mm-stat-divider"></div>
      <div class="mm-stat">
        <?php
        $totalMod = (int)$db->query("SELECT COUNT(*) FROM moto_modelos WHERE ativo=1")->fetchColumn();
        ?>
        <strong><?= number_format($totalMod) ?></strong>
        <span>Modelos</span>
      </div>
      <div class="mm-stat-divider"></div>
      <div class="mm-stat">
        <?php
        $totalProd = (int)$db->query(
            "SELECT COUNT(DISTINCT produto_id) FROM produto_compatibilidade"
        )->fetchColumn();
        ?>
        <strong><?= number_format($totalProd) ?>+</strong>
        <span>Peças compatíveis</span>
      </div>
    </div>
  </div>

  <!-- ══ GRID DE MONTADORAS ═════════════════════════════════ -->
  <section class="container mm-section">
    <div class="mm-section-header">
      <h2>Escolha a montadora</h2>
      <p><?= count($montadoras) ?> fabricantes disponíveis</p>
    </div>

    <?php if (empty($montadoras)): ?>
    <div class="mm-empty">
      <svg width="48" height="48" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="1.2" stroke-linecap="round">
        <circle cx="5.5" cy="17.5" r="3.5"/>
        <circle cx="18.5" cy="17.5" r="3.5"/>
        <path d="M15 6h-2l-3 8H5.5"/>
        <path d="M15 6l3 5h1.5"/>
      </svg>
      <p>Nenhuma montadora cadastrada ainda.</p>
      <a href="<?= BASE_URL ?>/admin/motos" class="mm-btn-outline">
        Sincronizar base FIPE
      </a>
    </div>
    <?php else: ?>

    <div class="mm-grid">
      <?php foreach ($montadoras as $m):
        $totalProdsMont = (int)($m['total_produtos'] ?? 0);
        $totalModMont   = (int)($m['total_modelos']  ?? 0);
      ?>
      <a href="<?= BASE_URL ?>/montadora/<?= View::e($m['slug']) ?>"
         class="mm-card">

        <!-- Logo / Inicial -->
        <div class="mm-card-logo">
          <?php if (!empty($m['logo']) || !empty($m['thumb'])): ?>
          <img src="<?= View::upload('motos/' . ($m['logo'] ?? $m['thumb'])) ?>"
               alt="<?= View::e($m['nome']) ?>"
               loading="lazy">
          <?php else: ?>
          <span class="mm-card-initials">
            <?= mb_strtoupper(mb_substr($m['nome'], 0, 2)) ?>
          </span>
          <?php endif; ?>
        </div>

        <!-- Info -->
        <div class="mm-card-info">
          <h3 class="mm-card-nome"><?= View::e($m['nome']) ?></h3>
          <div class="mm-card-meta">
            <span>
              <svg width="10" height="10" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                <circle cx="5.5" cy="17.5" r="3.5"/>
                <circle cx="18.5" cy="17.5" r="3.5"/>
                <path d="M15 6h-2l-3 8H5.5"/>
              </svg>
              <?= $totalModMont ?> modelo<?= $totalModMont != 1 ? 's' : '' ?>
            </span>
            <span>
              <svg width="10" height="10" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                <path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/>
              </svg>
              <?= number_format($totalProdsMont) ?> peça<?= $totalProdsMont != 1 ? 's' : '' ?>
            </span>
          </div>
        </div>

        <svg class="mm-card-arrow" width="16" height="16" viewBox="0 0 24 24"
             fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <line x1="5" y1="12" x2="19" y2="12"/>
          <polyline points="12 5 19 12 12 19"/>
        </svg>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>

</div>

<script>
(function () {
  const $mont = document.getElementById('hs-montadora');
  const $mod  = document.getElementById('hs-modelo');
  const $ano  = document.getElementById('hs-ano');
  const $btn  = document.getElementById('hs-btn');

  $mont.addEventListener('change', function () {
    const id   = this.value;
    const slug = this.options[this.selectedIndex].dataset.slug;

    $mod.innerHTML = '<option value="">Carregando...</option>';
    $mod.disabled  = true;
    $ano.innerHTML = '<option value="">Todos os anos</option>';
    $ano.disabled  = true;
    $btn.disabled  = true;
    $mont.dataset.slug = slug || '';

    if (!id) return;

    fetch(`<?= BASE_URL ?>/ajax/moto/modelos?montadora_id=${id}`)
      .then(r => r.json()).then(modelos => {
        let opts = '<option value="">Todos os modelos</option>';
        modelos.forEach(m => {
          opts += `<option value="${m.id}" data-slug="${m.slug}">${m.nome}</option>`;
        });
        $mod.innerHTML = opts;
        $mod.disabled  = false;
        $btn.disabled  = false;
      });
  });

  $mod.addEventListener('change', function () {
    const id = this.value;
    $ano.innerHTML = '<option value="">Carregando...</option>';
    $ano.disabled  = true;
    if (!id) {
      $ano.innerHTML = '<option value="">Todos os anos</option>';
      return;
    }
    fetch(`<?= BASE_URL ?>/ajax/moto/anos?modelo_id=${id}`)
      .then(r => r.json()).then(anos => {
        let opts = '<option value="">Todos os anos</option>';
        anos.forEach(a => { opts += `<option value="${a.ano}">${a.ano}</option>`; });
        $ano.innerHTML = opts;
        $ano.disabled  = false;
      });
  });

  document.getElementById('form-busca-moto-hero').addEventListener('submit', function (e) {
    e.preventDefault();
    const montSlug = $mont.dataset.slug;
    if (!montSlug) return;
    const modSlug = $mod.options[$mod.selectedIndex]?.dataset?.slug;
    const ano     = $ano.value;
    let url = `<?= BASE_URL ?>/montadora/${montSlug}`;
    if (modSlug) {
      url += `/${modSlug}`;
      if (ano) url += `-${ano}`;
    }
    window.location.href = url;
  });
})();
</script>