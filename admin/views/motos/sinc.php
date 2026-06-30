<?php // admin/views/motos/sinc.php ?>

<div class="admin-page">
  <div class="admin-page-header">
    <div>
      <h1>Motos — Base FIPE</h1>
      <p>Sincronize a base de montadoras, modelos e anos da tabela FIPE</p>
    </div>
    <button type="button" class="btn btn-primary" id="btn-sinc-fipe">
      <?= IconLibrary::render('sync', 'icon icon--md') ?>
      Sincronizar FIPE agora
    </button>
  </div>

  <!-- Cards de estatísticas -->
  <div class="motos-stats-grid">
    <div class="motos-stat-card">
      <div class="motos-stat-icon motos-stat-icon--blue">
        <?= IconLibrary::render('factory', 'icon icon--md') ?>
      </div>
      <div>
        <span class="motos-stat-valor"><?= number_format((int)$stats['montadoras']) ?></span>
        <span class="motos-stat-label">Montadoras</span>
      </div>
    </div>
    <div class="motos-stat-card">
      <div class="motos-stat-icon motos-stat-icon--purple">
        <?= IconLibrary::render('motorcycle', 'icon icon--md') ?>
      </div>
      <div>
        <span class="motos-stat-valor"><?= number_format((int)$stats['modelos']) ?></span>
        <span class="motos-stat-label">Modelos</span>
      </div>
    </div>
    <div class="motos-stat-card">
      <div class="motos-stat-icon motos-stat-icon--green">
        <?= IconLibrary::render('calendar-today', 'icon icon--md') ?>
      </div>
      <div>
        <span class="motos-stat-valor"><?= number_format((int)$stats['anos']) ?></span>
        <span class="motos-stat-label">Anos registrados</span>
      </div>
    </div>
  </div>

  <!-- Progresso da sincronização (escondido por padrão) -->
  <div class="motos-sinc-progress" id="motos-sinc-progress" style="display:none;">
    <div class="admin-card">
      <div class="motos-sinc-header">
        <div class="motos-sinc-spinner"></div>
        <div>
          <strong>Sincronizando com a FIPE...</strong>
          <p id="motos-sinc-msg" class="motos-sinc-msg">
            Iniciando importação de montadoras...
          </p>
        </div>
      </div>
      <div class="motos-progress-bar">
        <div class="motos-progress-fill" id="motos-progress-fill"></div>
      </div>
      <p class="motos-sinc-aviso">
        Não feche esta página. A sincronização pode levar alguns minutos.
      </p>
    </div>
  </div>

  <!-- Resultado -->
  <div id="motos-sinc-result" style="display:none;"></div>

  <!-- Log de sincronizações -->
  <div class="admin-card" style="margin-top:20px;">
    <div class="admin-card-header">
      <h3>Histórico de sincronizações</h3>
    </div>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th>Iniciado em</th>
            <th>Finalizado em</th>
            <th class="text-center">Montadoras</th>
            <th class="text-center">Modelos</th>
            <th class="text-center">Anos</th>
            <th class="text-center">Status</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($logs)): ?>
          <tr>
            <td colspan="6" style="text-align:center;color:var(--text-3);
                                    padding:24px;font-style:italic;">
              Nenhuma sincronização realizada ainda.
            </td>
          </tr>
          <?php else: ?>
          <?php foreach ($logs as $log):
            $cores = ['ok' => 'success', 'erro' => 'danger', 'rodando' => 'warning'];
            $labels= ['ok' => 'Concluído','erro' => 'Erro','rodando' => 'Em andamento'];
          ?>
          <tr>
            <td><?= date('d/m/Y H:i', strtotime($log['iniciado_em'])) ?></td>
            <td>
              <?= $log['finalizado_em']
                  ? date('d/m/Y H:i', strtotime($log['finalizado_em']))
                  : '—' ?>
            </td>
            <td class="text-center">
              <?= number_format((int)$log['montadoras']) ?>
            </td>
            <td class="text-center">
              <?= number_format((int)$log['modelos']) ?>
            </td>
            <td class="text-center">
              <?= number_format((int)$log['anos']) ?>
            </td>
            <td class="text-center">
              <span class="admin-badge admin-badge--<?= $cores[$log['status']] ?? 'muted' ?>">
                <?= $labels[$log['status']] ?? $log['status'] ?>
              </span>
              <?php if ($log['status'] === 'erro' && $log['erro_msg']): ?>
              <span class="motos-erro-msg" title="<?= View::e($log['erro_msg']) ?>">
                ⚠
              </span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Gestão de montadoras -->
  <div class="admin-card" style="margin-top:20px;">
    <div class="admin-page-header" style="padding:0 0 16px;">
      <h3>Montadoras cadastradas</h3>
      <button type="button" class="btn btn-outline btn-sm"
              id="btn-nova-montadora">
        + Adicionar manualmente
      </button>
    </div>
    <div class="admin-table-wrap">
      <table class="admin-table" id="montadoras-table">
        <thead>
          <tr>
            <th width="60">Thumb</th>
            <th>Nome / Slug</th>
            <th class="text-center">Modelos</th>
            <th class="text-center">Ativo</th>
            <th width="100">Ações</th>
          </tr>
        </thead>
        <tbody id="montadoras-tbody">
          <?php
          $db   = Database::getInstance()->getConnection();
          $mont = $db->query(
              "SELECT mm.*,
                      COUNT(DISTINCT mo.id) AS total_modelos
               FROM moto_montadoras mm
               LEFT JOIN moto_modelos mo ON mo.montadora_id = mm.id AND mo.ativo = 1
               GROUP BY mm.id
               ORDER BY mm.nome ASC"
          )->fetchAll();

          foreach ($mont as $m):
          ?>
          <tr data-id="<?= $m['id'] ?>">
            <td>
              <div class="motos-thumb-wrap" id="thumb-mont-<?= $m['id'] ?>">
                <?php if (!empty($m['thumb'])): ?>
                <img src="<?= View::upload('motos/' . $m['thumb']) ?>"
                     alt="<?= View::e($m['nome']) ?>"
                     class="motos-thumb-img">
                <?php else: ?>
                <div class="motos-thumb-empty">
                  <?= mb_strtoupper(mb_substr($m['nome'], 0, 2)) ?>
                </div>
                <?php endif; ?>
                <button type="button"
                        class="motos-thumb-upload-btn"
                        data-tipo="montadora"
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
              <a href="<?= BASE_URL ?>/admin/motos/modelos?montadora_id=<?= $m['id'] ?>"
                 class="admin-badge admin-badge--muted">
                <?= (int)$m['total_modelos'] ?> modelos
              </a>
            </td>
            <td class="text-center">
              <button type="button"
                      class="admin-toggle <?= $m['ativo'] ? 'admin-toggle--on' : '' ?>"
                      data-id="<?= $m['id'] ?>" data-type="montadora">
                <span class="admin-toggle-track">
                  <span class="admin-toggle-thumb"></span>
                </span>
              </button>
            </td>
            <td>
              <div class="admin-row-actions">
                <a href="<?= BASE_URL ?>/montadora/<?= View::e($m['slug']) ?>"
                   target="_blank"
                   class="btn btn-xs btn-ghost" title="Ver no site">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                       stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/>
                    <polyline points="15 3 21 3 21 9"/>
                    <line x1="10" y1="14" x2="21" y2="3"/>
                  </svg>
                </a>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Input oculto para upload de thumb -->
<input type="file" id="motos-thumb-input" accept="image/*"
       style="display:none;">