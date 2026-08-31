<?php
// ════════════════════════════════════════════════════════
// admin/views/avaliacoes/index.php
// ════════════════════════════════════════════════════════
?>

<div class="admin-page">
  <div class="admin-page-header">
    <div>
      <h1>Avaliações</h1>
      <p>
        <span style="color:var(--warning);font-weight:700;"><?= $pendentes ?> pendentes</span>
        · <?= $aprovadas ?> aprovadas · <?= $total ?> no total
      </p>
    </div>
  </div>

  <!-- Tabs de status -->
  <div style="display:flex;gap:6px;margin-bottom:20px;flex-wrap:wrap;">
    <?php $tabs = [
      'todas' => ['label'=>'Todas',    'count'=>$total,    'cor'=>''],
      '0'     => ['label'=>'Pendentes','count'=>$pendentes,'cor'=>'warning'],
      '1'     => ['label'=>'Aprovadas','count'=>$aprovadas,'cor'=>'success'],
    ]; ?>
    <?php foreach ($tabs as $key => $tab): ?>
    <a href="<?= BASE_URL ?>/admin/avaliacoes?aprovado=<?= $key ?><?= $nota ? '&nota='.$nota : '' ?>"
       class="mod-tab <?= $filtro == $key ? 'is-active' : '' ?>">
      <?= $tab['label'] ?>
      <?php if ($tab['count'] > 0 && $tab['cor']): ?>
      <span class="mod-tab-badge" style="background:var(--<?= $tab['cor'] ?>);">
        <?= $tab['count'] ?>
      </span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- Filtros -->
  <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px;"
        action="<?= BASE_URL ?>/admin/avaliacoes">
    <input type="hidden" name="aprovado" value="<?= View::e($filtro) ?>">

    <div class="admin-search-wrap" style="flex:1;min-width:220px;">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2" stroke-linecap="round">
        <circle cx="11" cy="11" r="8"/>
        <path d="m21 21-4.35-4.35"/>
      </svg>
      <input type="text" name="q" value="<?= View::e($busca) ?>"
             class="admin-search-input" placeholder="Buscar por produto, cliente, comentário…">
    </div>

    <select name="nota" class="form-control" style="width:auto;padding:8px 12px;">
      <option value="">Todas as notas</option>
      <?php for ($n=5;$n>=1;$n--): ?>
      <option value="<?= $n ?>" <?= $nota===$n?'selected':'' ?>>
        <?= $n ?> estrela<?= $n>1?'s':'' ?>
      </option>
      <?php endfor; ?>
    </select>

    <button type="submit" class="btn btn-primary">Filtrar</button>
    <?php if ($busca || $nota): ?>
    <a href="<?= BASE_URL ?>/admin/avaliacoes?aprovado=<?= View::e($filtro) ?>"
       class="btn btn-ghost">Limpar</a>
    <?php endif; ?>
  </form>

  <!-- Grid de avaliações -->
  <?php if (empty($avaliacoes)): ?>
  <div class="admin-empty-state">
    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="1" stroke-linecap="round">
      <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
    </svg>
    <p>Nenhuma avaliação encontrada.</p>
  </div>
  <?php else: ?>

  <div class="rv-admin-grid">
    <?php foreach ($avaliacoes as $a): ?>
    <div class="rv-admin-card <?= !$a['aprovado'] ? 'rv-admin-card--pendente' : '' ?>"
         id="rv-card-<?= $a['id'] ?>">

      <!-- Header -->
      <div class="rv-admin-card-header">
        <div class="rv-admin-cliente">
          <div class="rv-admin-avatar">
            <?= mb_strtoupper(mb_substr($a['nome_exibido'], 0, 1)) ?>
          </div>
          <div>
            <div class="rv-admin-nome"><?= View::e($a['nome_exibido']) ?></div>
            <div class="rv-admin-produto">
              <?php if ($a['produto_nome']): ?>
              <a href="<?= BASE_URL ?>/produto/<?= View::e($a['produto_slug']) ?>"
                 target="_blank" class="rv-admin-produto-link">
                <?= View::e(mb_substr($a['produto_nome'], 0, 50)) ?>
              </a>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <div style="display:flex;align-items:center;gap:8px;">
          <!-- Estrelas -->
          <div class="rv-admin-stars">
            <?php for ($s=1;$s<=5;$s++): ?>
            <svg width="14" height="14" viewBox="0 0 24 24">
              <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"
                       fill="<?= $s<=(int)$a['nota'] ? 'var(--warning)' : '#e0d8cc' ?>"/>
            </svg>
            <?php endfor; ?>
          </div>
          <!-- Status badge -->
          <?php if ($a['aprovado']): ?>
          <span class="admin-badge admin-badge--success">Aprovada</span>
          <?php else: ?>
          <span class="admin-badge admin-badge--warning">Pendente</span>
          <?php endif; ?>
          <?php if ($a['destaque']): ?>
          <span class="admin-badge admin-badge--info">Destaque</span>
          <?php endif; ?>
        </div>
      </div>

      <!-- Conteúdo -->
      <div class="rv-admin-card-body">
        <?php if ($a['titulo']): ?>
        <div class="rv-admin-titulo"><?= View::e($a['titulo']) ?></div>
        <?php endif; ?>
        <p class="rv-admin-comentario"><?= View::e($a['comentario']) ?></p>

        <?php if ((int)$a['total_midias'] > 0): ?>
        <div class="rv-admin-midias-count">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <rect x="3" y="3" width="18" height="18" rx="2"/>
            <circle cx="8.5" cy="8.5" r="1.5"/>
            <polyline points="21 15 16 10 5 21"/>
          </svg>
          <?= (int)$a['total_midias'] ?> mídia(s)
        </div>
        <?php endif; ?>

        <div class="rv-admin-meta">
          <span><?= date('d/m/Y H:i', strtotime($a['criado_em'])) ?></span>
          <?php if ($a['ip_origem']): ?>
          <span>IP: <?= View::e($a['ip_origem']) ?></span>
          <?php endif; ?>
          <span>
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <path d="M14 9V5a3 3 0 00-3-3l-4 9v11h11.28a2 2 0 002-1.7l1.38-9a2 2 0 00-2-2.3H14z"/>
            </svg>
            <?= (int)$a['util_sim'] ?> acharam útil
          </span>
        </div>

        <?php if (!empty($a['motivo_rejeicao'])): ?>
        <div class="rv-admin-motivo">
          <strong>Motivo da rejeição:</strong> <?= View::e($a['motivo_rejeicao']) ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- Ações -->
      <div class="rv-admin-card-actions">
        <?php if (!$a['aprovado']): ?>
        <button type="button" class="rv-btn rv-btn--aprovar"
                data-id="<?= $a['id'] ?>">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="3" stroke-linecap="round">
            <polyline points="20 6 9 17 4 12"/>
          </svg>
          Aprovar
        </button>
        <button type="button" class="rv-btn rv-btn--rejeitar"
                data-id="<?= $a['id'] ?>">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <line x1="18" y1="6" x2="6" y2="18"/>
            <line x1="6" y1="6" x2="18" y2="18"/>
          </svg>
          Rejeitar
        </button>
        <?php else: ?>
        <button type="button" class="rv-btn rv-btn--rejeitar"
                data-id="<?= $a['id'] ?>">Remover aprovação</button>
        <?php endif; ?>

        <button type="button" class="rv-btn rv-btn--destaque"
                data-id="<?= $a['id'] ?>"
                title="<?= $a['destaque'] ? 'Remover destaque' : 'Marcar como destaque' ?>">
          <?= $a['destaque'] ? '★ Remover destaque' : '☆ Destacar' ?>
        </button>

        <button type="button" class="rv-btn rv-btn--excluir"
                data-id="<?= $a['id'] ?>"
                data-nome="<?= View::e(mb_substr($a['comentario'], 0, 60)) ?>">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <polyline points="3 6 5 6 21 6"/>
            <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
          </svg>
        </button>
      </div>

    </div>
    <?php endforeach; ?>
  </div>

  <?php endif; ?>
</div>

<!-- Modal de rejeição -->
<div class="mod-modal" id="rv-modal-rejeitar" hidden>
  <div class="mod-modal-backdrop"></div>
  <div class="mod-modal-content">
    <h3>Rejeitar avaliação</h3>
    <p>Informe o motivo (opcional, visível internamente):</p>
    <div class="mod-motivos-presets">
      <button type="button" data-motivo="Conteúdo inadequado">Inadequado</button>
      <button type="button" data-motivo="Linguagem ofensiva">Ofensivo</button>
      <button type="button" data-motivo="Conteúdo promocional ou spam">Spam</button>
      <button type="button" data-motivo="Avaliação irrelevante ao produto">Irrelevante</button>
    </div>
    <textarea id="rv-motivo-input" rows="2" placeholder="Motivo (opcional)…"></textarea>
    <div class="mod-modal-footer">
      <button type="button" class="btn-cancel" id="rv-cancel-rejeitar">Cancelar</button>
      <button type="button" class="btn-confirm-reject" id="rv-confirm-rejeitar">Confirmar</button>
    </div>
  </div>
</div>


<script>

</script>