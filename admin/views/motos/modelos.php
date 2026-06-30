<?php // admin/views/motos/modelos.php ?>

<div class="admin-page">
  <div class="admin-page-header">
    <div>
      <a href="<?= BASE_URL ?>/admin/motos" class="admin-back-link">
        ← Montadoras
      </a>
      <h1><?= View::e($montadora['nome']) ?></h1>
      <p><?= count($modelos) ?> modelo<?= count($modelos) != 1 ? 's' : '' ?> cadastrado<?= count($modelos) != 1 ? 's' : '' ?></p>
    </div>
    <button type="button" class="btn btn-primary" id="btn-novo-modelo">
      + Adicionar modelo
    </button>
  </div>

  <div class="admin-card">
    <div class="admin-table-wrap">
      <table class="admin-table" id="modelos-table">
        <thead>
          <tr>
            <th width="60">Thumb</th>
            <th>Nome / Slug</th>
            <th class="text-center">Anos</th>
            <th class="text-center">Ativo</th>
            <th width="80">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($modelos)): ?>
          <tr>
            <td colspan="5"
                style="text-align:center;padding:32px;
                       color:var(--text-3);font-style:italic;">
              Nenhum modelo cadastrado.
            </td>
          </tr>
          <?php endif; ?>

          <?php foreach ($modelos as $m): ?>
          <tr data-id="<?= $m['id'] ?>">
            <td>
              <div class="motos-thumb-wrap" id="thumb-mod-<?= $m['id'] ?>">
                <?php if (!empty($m['thumb'])): ?>
                <img src="<?= View::upload('motos/' . $m['thumb']) ?>"
                     alt="" class="motos-thumb-img">
                <?php else: ?>
                <div class="motos-thumb-empty" style="font-size:9px;">
                  <?= mb_strtoupper(mb_substr($m['nome'], 0, 3)) ?>
                </div>
                <?php endif; ?>
                <button type="button"
                        class="motos-thumb-upload-btn"
                        data-tipo="modelo"
                        data-id="<?= $m['id'] ?>"
                        title="Upload thumb">
                  <svg width="10" height="10" viewBox="0 0 24 24" fill="none"
                       stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>
                    <polyline points="17 8 12 3 7 8"/>
                    <line x1="12" y1="3" x2="12" y2="15"/>
                  </svg>
                </button>
              </div>
            </td>
            <td>
              <span class="cat-nome"><?= View::e($m['nome']) ?></span>
              <span class="cat-slug"><?= View::e($m['slug']) ?></span>
            </td>
            <td class="text-center">
              <span class="admin-badge admin-badge--muted">
                <?= (int)$m['total_anos'] ?> ano<?= $m['total_anos'] != 1 ? 's' : '' ?>
              </span>
            </td>
            <td class="text-center">
              <button type="button"
                      class="admin-toggle <?= $m['ativo'] ? 'admin-toggle--on' : '' ?>"
                      data-id="<?= $m['id'] ?>" data-type="modelo">
                <span class="admin-toggle-track">
                  <span class="admin-toggle-thumb"></span>
                </span>
              </button>
            </td>
            <td>
              <div class="admin-row-actions">
                <button type="button"
                        class="btn btn-xs btn-ghost btn-editar-modelo"
                        data-id="<?= $m['id'] ?>"
                        data-nome="<?= View::e($m['nome']) ?>"
                        title="Editar">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                       stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
                    <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
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
</div>

<!-- Dados para JS -->
<script>
window.MONTADORA_ID_ATUAL = <?= (int)$montadora['id'] ?>;
window.MONTADORA_NOME     = <?= json_encode($montadora['nome']) ?>;
</script>

<input type="file" id="motos-thumb-input" accept="image/*" style="display:none;">