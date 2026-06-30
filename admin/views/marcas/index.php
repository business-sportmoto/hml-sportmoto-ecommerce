<div class="admin-page">

  <div class="admin-page-header">
    <div>
      <h1>Marcas</h1>
      <p><?= count($marcas) ?> marcas cadastradas</p>
    </div>
    <a href="<?= BASE_URL ?>/admin/marcas/criar" class="btn btn-primary">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
        <line x1="12" y1="5" x2="12" y2="19"/>
        <line x1="5"  y1="12" x2="19" y2="12"/>
      </svg>
      Nova marca
    </a>
  </div>

  <?php if (empty($marcas)): ?>
  <div class="admin-empty-state">
    <svg width="48" height="48" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="1" stroke-linecap="round">
      <path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/>
      <line x1="7" y1="7" x2="7.01" y2="7"/>
    </svg>
    <p>Nenhuma marca cadastrada ainda.</p>
    <a href="<?= BASE_URL ?>/admin/marcas/criar" class="btn btn-primary">
      Criar primeira marca
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
        <input type="text" id="marca-search"
               class="admin-search-input"
               placeholder="Buscar marca...">
      </div>
    </div>

    <div class="admin-table-wrap">
      <table class="admin-table" id="marcas-table">
        <thead>
          <tr>
            <th>Logo</th>
            <th>Nome / Slug</th>
            <th class="text-center">Produtos</th>
            <th class="text-center">Destaque</th>
            <th class="text-center">Ativo</th>
            <th width="120">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($marcas as $m): ?>
          <tr id="marca-row-<?= $m['id'] ?>">

            <td width="60" >
              <?php if (!empty($m['logo'])): ?>
              <img src="<?= View::upload('brands/' . $m['logo']) ?>" <?= !empty($m['bg_cor']) ? 'style="background-color:' . View::e($m['bg_cor']) . ';"' : '' ?>
                   alt="<?= View::e($m['nome']) ?>"
                   width="40" height="40"
                   class="marca-logo-thumb">
              <?php else: ?>
              <div class="marca-logo-placeholder" <?= !empty($m['bg_cor']) ? 'style="background-color:' . View::e($m['bg_cor']) . ';"' : '' ?>>
                <?= mb_substr($m['nome'], 0, 2) ?>
              </div>
              <?php endif; ?>
            </td>

            <td>
              <span class="cat-nome"><?= View::e($m['nome']) ?></span>
              <span class="cat-slug">/marca/<?= View::e($m['slug']) ?></span>
            </td>

            <td class="text-center">
              <?php if ((int)$m['total_produtos'] > 0): ?>
              <a href="<?= BASE_URL ?>/admin/produtos?marca_id=<?= $m['id'] ?>"
                 class="admin-badge admin-badge--muted">
                <?= (int)$m['total_produtos'] ?> produto(s)
              </a>
              <?php else: ?>
              <span class="admin-muted">0</span>
              <?php endif; ?>
            </td>

            <td class="text-center">
              <?php if ($m['destaque']): ?>
              <span class="admin-badge admin-badge--warning">Destaque</span>
              <?php else: ?>
              <span class="admin-muted">—</span>
              <?php endif; ?>
            </td>

            <td class="text-center">
              <button type="button"
                      class="admin-toggle <?= $m['ativo'] ? 'admin-toggle--on' : '' ?>"
                      data-id="<?= $m['id'] ?>">
                <span class="admin-toggle-track">
                  <span class="admin-toggle-thumb"></span>
                </span>
              </button>
            </td>

            <td>
              <div class="admin-row-actions">
                <a href="<?= BASE_URL ?>/marca/<?= View::e($m['slug']) ?>"
                   target="_blank"
                   class="btn btn-sm btn-ghost" title="Ver página">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                       stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/>
                    <polyline points="15 3 21 3 21 9"/>
                    <line x1="10" y1="14" x2="21" y2="3"/>
                  </svg>
                </a>
                <a href="<?= BASE_URL ?>/admin/marcas/<?= $m['id'] ?>/editar"
                   class="btn btn-sm btn-ghost" title="Editar">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                       stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
                    <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
                  </svg>
                </a>
                <button type="button"
                        class="btn btn-sm btn-ghost btn-excluir-marca"
                        data-id="<?= $m['id'] ?>"
                        data-nome="<?= View::e($m['nome']) ?>"
                        title="Excluir">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
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
  <?php endif; ?>

</div>