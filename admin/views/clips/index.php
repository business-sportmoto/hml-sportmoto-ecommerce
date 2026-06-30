<?php
// ════════════════════════════════════════════════════════
// admin/views/clips/index.php — v2
// Layout em grade, scroll infinito, toggles via AJAX
// ════════════════════════════════════════════════════════
$paginaAtual = (int)($page ?? 1);
$busca       = $busca ?? '';
?>

<div class="admin-page" id="clips-admin-page">

  <!-- ── Header ──────────────────────────────────────── -->
  <div class="admin-page-header">
    <div>
      <h1>Clips</h1>
      <p id="clips-total-label">
        <?= number_format($total) ?> clip<?= $total !== 1 ? 's' : '' ?> cadastrado<?= $total !== 1 ? 's' : '' ?>
      </p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <a href="<?= BASE_URL ?>/admin/clips/comentarios"
         class="btn btn-ghost">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>
        </svg>
        Comentários
        <?php
        $pend = (int)Database::getInstance()->getConnection()
            ->query("SELECT COUNT(*) FROM clip_comentarios WHERE status='pendente'")
            ->fetchColumn();
        ?>
        <?php if ($pend > 0): ?>
        <span class="admin-badge admin-badge--warning"><?= $pend ?></span>
        <?php endif; ?>
      </a>
      <a href="<?= BASE_URL ?>/admin/clips/form" class="btn btn-primary">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <line x1="12" y1="5" x2="12" y2="19"/>
          <line x1="5"  y1="12" x2="19" y2="12"/>
        </svg>
        Novo clip
      </a>
    </div>
  </div>

  <!-- ── Filtros ─────────────────────────────────────── -->
  <div class="clips-admin-toolbar">

    <!-- Busca -->
    <div class="admin-search-wrap clips-admin-search">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2" stroke-linecap="round">
        <circle cx="11" cy="11" r="8"/>
        <path d="m21 21-4.35-4.35"/>
      </svg>
      <input type="text" id="clips-busca-input"
             class="admin-search-input"
             placeholder="Buscar por título…"
             value="<?= View::e($busca) ?>">
      <?php if ($busca): ?>
      <button type="button" id="clips-busca-clear" class="clips-busca-clear">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <line x1="18" y1="6" x2="6" y2="18"/>
          <line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
      <?php endif; ?>
    </div>

    <!-- Filtros de status -->
    <div class="clips-admin-filters">
      <button type="button" class="clips-filter-btn <?= !isset($_GET['status']) ? 'is-active' : '' ?>"
              data-status="">Todos</button>
      <button type="button" class="clips-filter-btn <?= ($_GET['status']??'') === 'ativo' ? 'is-active' : '' ?>"
              data-status="ativo">
        <span class="clips-status-dot" style="background:#16a34a;"></span>
        Ativos
      </button>
      <button type="button" class="clips-filter-btn <?= ($_GET['status']??'') === 'inativo' ? 'is-active' : '' ?>"
              data-status="inativo">
        <span class="clips-status-dot" style="background:#94a3b8;"></span>
        Inativos
      </button>
      <button type="button" class="clips-filter-btn <?= ($_GET['status']??'') === 'destaque' ? 'is-active' : '' ?>"
              data-status="destaque">
        <span style="font-size:12px;">⭐</span>
        Em destaque
      </button>
      <button type="button" class="clips-filter-btn <?= ($_GET['status']??'') === 'sem_poster' ? 'is-active' : '' ?>"
              data-status="sem_poster">
        <svg width="11" height="11" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <rect x="3" y="3" width="18" height="18" rx="2"/>
          <circle cx="8.5" cy="8.5" r="1.5"/>
          <polyline points="21 15 16 10 5 21"/>
        </svg>
        Sem poster
      </button>
    </div>

    <!-- Ordenação -->
    <select id="clips-ordem-select" class="clips-select">
      <option value="recentes">Mais recentes</option>
      <option value="visualizacoes">Mais visualizados</option>
      <option value="likes">Mais curtidos</option>
      <option value="ordem">Por ordem</option>
    </select>

  </div>

  <!-- ── Grade de clips ───────────────────────────────── -->
  <div class="clips-admin-grid" id="clips-admin-grid">
    <?php foreach ($clips as $c): ?>
      <?= View::partial('clips/_card', ['c' => $c], false) ?>
    <?php endforeach; ?>
  </div>

  <!-- Estado vazio -->
  <div id="clips-empty-state" class="admin-empty-state"
       <?= !empty($clips) ? 'hidden' : '' ?>>
    <svg width="48" height="48" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="1" stroke-linecap="round">
      <polygon points="23 7 16 12 23 17 23 7"/>
      <rect x="1" y="5" width="15" height="14" rx="2"/>
    </svg>
    <h3>Nenhum clip encontrado</h3>
    <p>Ajuste os filtros ou crie um novo clip.</p>
    <a href="<?= BASE_URL ?>/admin/clips/form" class="btn btn-primary">
      Criar primeiro clip
    </a>
  </div>

  <!-- Loader do scroll infinito -->
  <div id="clips-load-more" class="clips-load-more" hidden>
    <div class="clips-spinner">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
        <path d="M21 12a9 9 0 11-6.219-8.56"/>
      </svg>
    </div>
    <span>Carregando mais clips…</span>
  </div>

  <!-- Sentinel para o Intersection Observer -->
  <div id="clips-sentinel" style="height:1px;"></div>

</div>



<!-- ════════════════════════════════════════════════════
   JavaScript
═══════════════════════════════════════════════════════ -->
<script>
let page_clip_index       = <?= $paginaAtual ?>;
let hasMore    = <?= json_encode($hasMore ?? ($total > $perPage)) ?>;
let filtro     = { status: '<?= View::e($_GET['status'] ?? '') ?>', busca: '<?= View::e($busca) ?>', ordem: 'recentes' };
</script>