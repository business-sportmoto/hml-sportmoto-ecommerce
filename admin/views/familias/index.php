<?php
/**
 * Lista de famílias de produtos.
 *
 * Família agrupa produtos irmãos que têm URL própria (o mesmo capacete em
 * duas cores). Na loja ela vira o seletor de "outras versões" dentro da
 * página do produto — família vazia ou com um membro só não mostra nada,
 * e é por isso que a tela destaca esses dois casos.
 */
?>
<div class="admin-page">

  <div class="admin-page-header">
    <div>
      <h1>Famílias de produtos</h1>
      <p>
        Agrupam produtos irmãos — mesmo modelo em cores ou estampas diferentes.
        Na loja viram o seletor de versões dentro da página do produto.
      </p>
    </div>
    <a href="<?= BASE_URL ?>/admin/produtos" class="btn btn-outline">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
        <path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/>
      </svg>
      Ir para produtos
    </a>
  </div>

  <?php // Não existe "nova família" aqui: ela nasce dentro do produto. ?>
  <div class="admin-stats-grid">

    <div class="admin-stat-card">
      <div class="admin-stat-icon admin-stat-icon--blue">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-linecap="round">
          <rect x="2" y="3" width="6" height="18" rx="2"/>
          <rect x="9" y="3" width="6" height="18" rx="2"/>
          <rect x="16" y="3" width="6" height="18" rx="2"/>
        </svg>
      </div>
      <div class="admin-stat-info">
        <span class="admin-stat-label">Famílias</span>
        <span class="admin-stat-value"><?= number_format($resumo['familias'], 0, ',', '.') ?></span>
        <span class="admin-stat-sub">
          <?= $resumo['inativas'] ?> inativa<?= $resumo['inativas'] == 1 ? '' : 's' ?>
        </span>
      </div>
    </div>

    <div class="admin-stat-card">
      <div class="admin-stat-icon admin-stat-icon--green">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-linecap="round">
          <path d="M20 6L9 17l-5-5"/>
        </svg>
      </div>
      <div class="admin-stat-info">
        <span class="admin-stat-label">Produtos vinculados</span>
        <span class="admin-stat-value"><?= number_format($resumo['produtos_vinculados'], 0, ',', '.') ?></span>
        <span class="admin-stat-sub">
          <?= number_format($resumo['produtos_sem_familia'], 0, ',', '.') ?> ativos sem família
        </span>
      </div>
    </div>

    <div class="admin-stat-card">
      <div class="admin-stat-icon admin-stat-icon--orange">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-linecap="round">
          <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
          <line x1="12" y1="9"  x2="12" y2="13"/>
          <line x1="12" y1="17" x2="12.01" y2="17"/>
        </svg>
      </div>
      <div class="admin-stat-info">
        <span class="admin-stat-label">Com 1 produto só</span>
        <span class="admin-stat-value"><?= number_format($resumo['sozinhas'], 0, ',', '.') ?></span>
        <span class="admin-stat-sub">não mostram seletor na loja</span>
      </div>
    </div>

    <div class="admin-stat-card">
      <div class="admin-stat-icon admin-stat-icon--purple">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-linecap="round">
          <circle cx="12" cy="12" r="9"/>
          <line x1="8" y1="12" x2="16" y2="12"/>
        </svg>
      </div>
      <div class="admin-stat-info">
        <span class="admin-stat-label">Sem nenhum produto</span>
        <span class="admin-stat-value"><?= number_format($resumo['vazias'], 0, ',', '.') ?></span>
        <span class="admin-stat-sub">podem ser excluídas</span>
      </div>
    </div>

  </div>

  <div class="admin-card">
    <div class="admin-card-header">
      <form method="get" action="<?= BASE_URL ?>/admin/familias" class="fam-filtros">
        <div class="admin-search-wrap">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <circle cx="11" cy="11" r="8"/>
            <line x1="21" y1="21" x2="16.65" y2="16.65"/>
          </svg>
          <input type="text" name="busca" id="fam-busca" class="admin-search-input"
                 value="<?= View::e($filtros['busca']) ?>"
                 aria-label="Buscar família por nome ou URL"
                 placeholder="Buscar por nome ou URL da família...">
        </div>

        <select name="situacao" id="fam-situacao" class="form-control fam-filtro-select"
                aria-label="Filtrar por situação">
          <option value="">Todas as famílias</option>
          <option value="sozinhas" <?= $filtros['situacao'] === 'sozinhas' ? 'selected' : '' ?>>Com 1 produto só</option>
          <option value="vazias"   <?= $filtros['situacao'] === 'vazias'   ? 'selected' : '' ?>>Sem nenhum produto</option>
          <option value="inativas" <?= $filtros['situacao'] === 'inativas' ? 'selected' : '' ?>>Inativas</option>
        </select>

        <button type="submit" class="btn btn-sm btn-primary">Filtrar</button>
        <?php if ($filtros['busca'] !== '' || $filtros['situacao'] !== ''): ?>
        <a href="<?= BASE_URL ?>/admin/familias" class="btn btn-sm btn-ghost">Limpar</a>
        <?php endif; ?>
      </form>
    </div>

    <?php if (empty($familias)): ?>
    <div class="admin-empty-state">
      <svg width="48" height="48" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="1" stroke-linecap="round">
        <rect x="2" y="3" width="6" height="18" rx="2"/>
        <rect x="9" y="3" width="6" height="18" rx="2"/>
        <rect x="16" y="3" width="6" height="18" rx="2"/>
      </svg>
      <?php if ($filtros['busca'] !== '' || $filtros['situacao'] !== ''): ?>
      <p>Nenhuma família com esse filtro.</p>
      <a href="<?= BASE_URL ?>/admin/familias" class="btn btn-outline">Ver todas</a>
      <?php else: ?>
      <p>
        Nenhuma família cadastrada ainda.<br>
        Elas nascem dentro do produto, no card <strong>Família de produtos</strong>.
      </p>
      <a href="<?= BASE_URL ?>/admin/produtos" class="btn btn-primary">Abrir produtos</a>
      <?php endif; ?>
    </div>

    <?php else: ?>
    <div class="admin-table-wrap">
      <table class="admin-table" id="familias-table">
        <thead>
          <tr>
            <th>Família</th>
            <th width="130" class="text-center">Produtos</th>
            <th>Quem está nela</th>
            <th width="90" class="text-center">Situação</th>
            <th width="100">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($familias as $f): ?>
          <tr id="familia-row-<?= (int) $f['id'] ?>">

            <td>
              <a href="<?= BASE_URL ?>/admin/familias/<?= (int) $f['id'] ?>" class="cat-nome fam-nome-link">
                <?= View::e($f['nome']) ?>
              </a>
              <span class="cat-slug">/<?= View::e($f['slug']) ?></span>
            </td>

            <td class="text-center">
              <?php if ($f['total_membros'] === 0): ?>
              <span class="admin-badge admin-badge--warning">vazia</span>
              <?php elseif ($f['total_membros'] === 1): ?>
              <span class="admin-badge admin-badge--warning"
                    title="Com um produto só, a loja não mostra o seletor de versões">1 produto</span>
              <?php else: ?>
              <span class="admin-badge admin-badge--muted"><?= $f['total_membros'] ?> produtos</span>
              <?php endif; ?>
              <?php if ($f['total_membros'] > 0 && $f['membros_ativos'] < $f['total_membros']): ?>
              <span class="fam-inativos"><?= $f['total_membros'] - $f['membros_ativos'] ?> inativo(s)</span>
              <?php endif; ?>
            </td>

            <td class="fam-membros">
              <?php if (empty($f['membros'])): ?>
              <span class="admin-muted">—</span>
              <?php else: ?>
              <div class="fam-minis">
                <?php foreach ($f['membros'] as $m): ?>
                <a href="<?= BASE_URL ?>/admin/produtos/<?= (int) $m['id'] ?>/editar"
                   class="fam-mini<?= $m['ativo'] ? '' : ' fam-mini--off' ?>"
                   title="<?= View::e($m['nome']) ?>">
                  <?php if (!empty($m['imagem'])): ?>
                  <img src="<?= View::e($m['imagem']) ?>" alt="" loading="lazy" class="fam-mini-foto">
                  <?php else: ?>
                  <span class="fam-mini-foto fam-mini-foto--vazia">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
                      <rect x="3" y="3" width="18" height="18" rx="2"/>
                      <circle cx="8.5" cy="8.5" r="1.5"/>
                      <polyline points="21 15 16 10 5 21"/>
                    </svg>
                  </span>
                  <?php endif; ?>
                  <span class="fam-mini-texto">
                    <span class="fam-mini-nome"><?= View::e(mb_strimwidth($m['nome'], 0, 44, '…')) ?></span>
                    <span class="fam-mini-sku"><?= $m['sku_legado'] ? View::e($m['sku_legado']) : 'sem ref.' ?></span>
                  </span>
                </a>
                <?php endforeach; ?>
                <?php $sobram = $f['total_membros'] - count($f['membros']); ?>
                <?php if ($sobram > 0): ?>
                <a href="<?= BASE_URL ?>/admin/familias/<?= (int) $f['id'] ?>" class="fam-mini fam-mini--mais">
                  +<?= $sobram ?>
                </a>
                <?php endif; ?>
              </div>
              <?php endif; ?>
            </td>

            <td class="text-center">
              <?php if ((int) $f['ativo'] === 1): ?>
              <span class="admin-badge admin-badge--success">Ativa</span>
              <?php else: ?>
              <span class="admin-badge admin-badge--muted">Inativa</span>
              <?php endif; ?>
            </td>

            <td>
              <div class="admin-row-actions">
                <a href="<?= BASE_URL ?>/admin/familias/<?= (int) $f['id'] ?>"
                   class="btn btn-sm btn-ghost" title="Abrir família"
                   aria-label="Abrir a família <?= View::e($f['nome']) ?>">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                       stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                    <circle cx="12" cy="12" r="3"/>
                  </svg>
                </a>
                <button type="button"
                        class="btn btn-sm btn-ghost btn-excluir-familia"
                        data-id="<?= (int) $f['id'] ?>"
                        data-nome="<?= View::e($f['nome']) ?>"
                        data-total="<?= $f['total_membros'] ?>"
                        title="Excluir família"
                        aria-label="Excluir a família <?= View::e($f['nome']) ?>">
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

    <?php $totalPages = (int) ceil($total / $perPage);
    if ($totalPages > 1): ?>
    <div class="admin-pagination">
      <span class="admin-pagination-info">
        <?= (($page - 1) * $perPage) + 1 ?>–<?= min($page * $perPage, $total) ?>
        de <?= number_format($total, 0, ',', '.') ?>
      </span>
      <div class="admin-pagination-btns">
        <?php if ($page > 1): ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>"
           class="btn btn-sm btn-ghost">&larr;</a>
        <?php endif; ?>
        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"
           class="btn btn-sm <?= $i === $page ? 'btn-primary' : 'btn-ghost' ?>"><?= $i ?></a>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>"
           class="btn btn-sm btn-ghost">&rarr;</a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>

</div>

<script>
// Excluir família — o produto NUNCA vai junto, só perde o vínculo.
$(document).on('click', '.btn-excluir-familia', async function () {
  const id    = $(this).data('id');
  const nome  = $(this).data('nome');
  const total = parseInt($(this).data('total') || 0, 10);

  const ok = await adminConfirm({
    titulo   : 'Excluir a família "' + nome + '"?',
    mensagem : total > 0
      ? total + ' produto(s) perdem o vínculo e ficam sem família. Nenhum produto é excluído.'
      : 'A família não tem produtos. Nada mais é afetado.',
    tipo      : 'danger',
    confirmar : 'Sim, excluir',
    cancelar  : 'Cancelar',
  });
  if (!ok) return;

  $.post(BASE_URL + '/admin/familias/excluir', { id: id, _csrf_token: CSRF_TOKEN }, function (res) {
    if (!res.ok) { showToast(res.msg || 'Não foi possível excluir.', 'error'); return; }
    $('#familia-row-' + id).fadeOut(200, function () { $(this).remove(); });
    showToast(res.msg, 'success');
  }, 'json');
});
</script>
