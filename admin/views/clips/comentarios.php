<?php
// ════════════════════════════════════════════════════════
// admin/views/clips/comentarios.php
// ════════════════════════════════════════════════════════
?>

<div class="admin-page">

  <div class="admin-page-header">
    <div>
      <a href="<?= BASE_URL ?>/admin/clips" class="admin-back-link">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <line x1="19" y1="12" x2="5" y2="12"/>
          <polyline points="12 19 5 12 12 5"/>
        </svg>
        Voltar para Clips
      </a>
      <h1>Comentários dos Clips</h1>
      <p>
        <?php if ($pendentes > 0): ?>
        <span style="color:var(--warning);font-weight:700;"><?= $pendentes ?> aguardando moderação</span>
        <?php else: ?>
        Todos os comentários estão moderados
        <?php endif; ?>
      </p>
    </div>
  </div>

  <!-- Tabs de filtro -->
  <div style="display:flex;gap:6px;margin-bottom:20px;flex-wrap:wrap;">
    <?php $tabs = [
      'pendente'  => ['label' => 'Pendentes',  'badge' => $pendentes],
      'aprovado'  => ['label' => 'Aprovados',  'badge' => 0],
      'rejeitado' => ['label' => 'Rejeitados', 'badge' => 0],
    ]; ?>
    <?php foreach ($tabs as $key => $tab): ?>
    <a href="<?= BASE_URL ?>/admin/clips/comentarios?status=<?= $key ?>"
       class="mod-tab <?= $filtro === $key ? 'is-active' : '' ?>">
      <?= $tab['label'] ?>
      <?php if ($tab['badge'] > 0 && $filtro !== $key): ?>
      <span class="mod-tab-badge"><?= $tab['badge'] ?></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- Lista de comentários -->
  <?php if (empty($comentarios)): ?>
  <div class="admin-empty-state">
    <svg width="48" height="48" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="1" stroke-linecap="round">
      <path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>
    </svg>
    <h3>Nenhum comentário <?= $filtro === 'pendente' ? 'pendente' : ($filtro === 'aprovado' ? 'aprovado' : 'rejeitado') ?></h3>
    <p>
      <?php if ($filtro === 'pendente'): ?>
        Não há comentários aguardando moderação.
      <?php else: ?>
        Nenhum comentário neste status ainda.
      <?php endif; ?>
    </p>
  </div>

  <?php else: ?>

  <div class="admin-card">
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th>Autor</th>
            <th>Comentário</th>
            <th>Clip</th>
            <th>Data</th>
            <th>Status</th>
            <th style="width:140px;"></th>
          </tr>
        </thead>
        <tbody id="comentarios-tbody">
          <?php foreach ($comentarios as $c): ?>
          <tr id="row-com-<?= $c['id'] ?>">

            <!-- Autor -->
            <td>
              <div style="display:flex;align-items:center;gap:8px;">
                <div style="
                  width:32px;height:32px;border-radius:50%;
                  background:linear-gradient(135deg,var(--blue),var(--blue));
                  display:flex;align-items:center;justify-content:center;
                  font-size:13px;font-weight:800;color:var(--surface);flex-shrink:0;">
                  <?= mb_strtoupper(mb_substr($c['nome'], 0, 1)) ?>
                </div>
                <div>
                  <div style="font-size:13px;font-weight:700;color:var(--text);">
                    <?= View::e($c['nome']) ?>
                  </div>
                  <?php if ($c['ip']): ?>
                  <div style="font-size:11px;color:var(--text-3);">
                    <?= View::e($c['ip']) ?>
                  </div>
                  <?php endif; ?>
                </div>
              </div>
            </td>

            <!-- Comentário -->
            <td>
              <p style="font-size:13.5px;color:var(--text-2);line-height:1.5;margin:0;max-width:380px;">
                <?= View::e($c['texto']) ?>
              </p>
            </td>

            <!-- Clip -->
            <td>
              <a href="<?= BASE_URL ?>/admin/clips/form?id=<?= (int)$c['clip_id'] ?>"
                 style="font-size:12.5px;color:var(--blue);text-decoration:none;font-weight:600;
                        max-width:160px;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                 title="<?= View::e($c['clip_titulo']) ?>">
                <?= View::e(mb_substr($c['clip_titulo'], 0, 40)) ?>
              </a>
            </td>

            <!-- Data -->
            <td style="font-size:12px;color:var(--text-3);white-space:nowrap;">
              <?= date('d/m/Y', strtotime($c['criado_em'])) ?><br>
              <span style="font-size:11px;"><?= date('H:i', strtotime($c['criado_em'])) ?></span>
            </td>

            <!-- Status -->
            <td>
              <?php if ($c['status'] === 'aprovado'): ?>
              <span class="admin-badge admin-badge--success">Aprovado</span>
              <?php elseif ($c['status'] === 'rejeitado'): ?>
              <span class="admin-badge admin-badge--danger">Rejeitado</span>
              <?php else: ?>
              <span class="admin-badge admin-badge--warning">Pendente</span>
              <?php endif; ?>
            </td>

            <!-- Ações -->
            <td>
              <div style="display:flex;gap:6px;align-items:center;">
                <?php if ($c['status'] !== 'aprovado'): ?>
                <button type="button" class="btn btn-xs btn-success btn-aprovar-com"
                        data-id="<?= $c['id'] ?>" title="Aprovar">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                       stroke="currentColor" stroke-width="3" stroke-linecap="round">
                    <polyline points="20 6 9 17 4 12"/>
                  </svg>
                </button>
                <?php endif; ?>

                <?php if ($c['status'] !== 'rejeitado'): ?>
                <button type="button" class="btn btn-xs btn-ghost btn-rejeitar-com"
                        data-id="<?= $c['id'] ?>" title="Rejeitar"
                        style="color:var(--danger,var(--danger));">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                       stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                  </svg>
                </button>
                <?php endif; ?>

                <button type="button" class="btn btn-xs btn-ghost btn-excluir-com"
                        data-id="<?= $c['id'] ?>"
                        data-nome="<?= View::e(mb_substr($c['nome'], 0, 30)) ?>"
                        title="Excluir permanentemente">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
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

<!-- <script>
$(function () {
  // ── Aprovar ──────────────────────────────────────────
  
});
</script> -->