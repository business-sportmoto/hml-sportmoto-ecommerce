<?php
// views/admin/devolucoes/motivos.php
?>
<div class="admin-page">
  <div class="admin-page-header">
    <div>
      <a href="<?= ADMIN_URL ?>/devolucoes" class="back-link">← Devoluções</a>
      <h1 class="admin-page-title">Motivos de devolução</h1>
      <p class="admin-page-sub">Configure os motivos que os clientes podem selecionar ao solicitar.</p>
    </div>
    <button type="button" class="btn btn-primary" id="btn-novo-motivo">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="2.5" stroke-linecap="round">
        <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
      </svg>
      Novo motivo
    </button>
  </div>

  <div class="admin-card">
    <table class="admin-table">
      <thead>
        <tr>
          <th>Nome</th>
          <th>Tipo</th>
          <th>Exige foto</th>
          <th>Frete</th>
          <th>Validade crédito</th>
          <th>Status</th>
          <th class="text-right">Ações</th>
        </tr>
      </thead>
      <tbody id="motivos-tbody">
        <?php foreach ($lista as $m): ?>
        <tr data-id="<?= (int)$m['id'] ?>">
          <td><strong><?= View::e($m['label']) ?></strong></td>
          <td><span class="badge badge-info"><?= ucfirst($m['tipo']) ?></span></td>
          <td><?= $m['exige_foto'] ? '✓' : '—' ?></td>
          <td>
            <span class="badge badge-<?= $m['responsavel_frete']==='loja'?'success':'warning' ?>">
              <?= $m['responsavel_frete'] === 'loja' ? 'Loja paga' : 'Cliente paga' ?>
            </span>
          </td>
          <td>
            <?= $m['prazo_credito_dias']
                ? (int)$m['prazo_credito_dias'].' dias'
                : '<span class="txt-muted">Não expira</span>' ?>
          </td>
          <td>
            <span class="badge badge-<?= $m['ativo']?'success':'danger' ?>">
              <?= $m['ativo'] ? 'Ativo' : 'Inativo' ?>
            </span>
          </td>
          <td class="text-right">
            <button type="button" class="btn-icon btn-editar-motivo"
                    data-id="<?= (int)$m['id'] ?>"
                    data-label="<?= View::e($m['label']) ?>"
                    data-tipo="<?= View::e($m['tipo']) ?>"
                    data-exige-foto="<?= (int)$m['exige_foto'] ?>"
                    data-frete="<?= View::e($m['responsavel_frete']) ?>"
                    data-prazo="<?= $m['prazo_credito_dias'] ?? '' ?>"
                    data-ativo="<?= (int)$m['ativo'] ?>"
                    data-ord="<?= (int)$m['ordenacao'] ?>"
                    title="Editar">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                   stroke-width="2.5" stroke-linecap="round">
                <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
                <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
              </svg>
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal motivo -->
<div class="od-modal-overlay" id="modal-motivo" hidden>
  <div class="od-modal-box">
    <div class="od-modal-header">
      <h4 id="modal-motivo-titulo">Novo motivo</h4>
      <button type="button" class="od-modal-close" onclick="fecharModal('modal-motivo')">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.5" stroke-linecap="round">
          <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>
    <div class="od-modal-body">
      <input type="hidden" id="mot-id">

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div style="grid-column:1/-1;">
          <label class="form-label-xs">Nome do motivo *</label>
          <input type="text" id="mot-label" class="form-control" placeholder="Ex: Produto com defeito">
        </div>
        <div>
          <label class="form-label-xs">Tipo</label>
          <select id="mot-tipo" class="form-control">
            <option value="ambos">Devolução e Troca</option>
            <option value="devolucao">Somente Devolução</option>
            <option value="troca">Somente Troca</option>
          </select>
        </div>
        <div>
          <label class="form-label-xs">Responsável pelo frete</label>
          <select id="mot-frete" class="form-control">
            <option value="loja">Loja</option>
            <option value="cliente">Cliente</option>
          </select>
        </div>
        <div>
          <label class="form-label-xs">Validade do crédito (dias)</label>
          <input type="number" id="mot-prazo" class="form-control"
                 placeholder="Vazio = não expira" min="1">
        </div>
        <div>
          <label class="form-label-xs">Ordenação</label>
          <input type="number" id="mot-ord" class="form-control" value="0" min="0">
        </div>
        <div style="display:flex;align-items:center;gap:12px;padding-top:18px;">
          <label class="toggle-field">
            <input type="checkbox" id="mot-exige-foto">
            <span class="toggle-slider"></span>
            <span>Exige foto</span>
          </label>
        </div>
        <div style="display:flex;align-items:center;gap:12px;padding-top:18px;">
          <label class="toggle-field">
            <input type="checkbox" id="mot-ativo" checked>
            <span class="toggle-slider"></span>
            <span>Ativo</span>
          </label>
        </div>
      </div>

      <div style="display:flex;gap:10px;margin-top:18px;">
        <button type="button" class="btn btn-primary" id="btn-salvar-motivo">Salvar</button>
        <button type="button" class="btn btn-outline" onclick="fecharModal('modal-motivo')">Cancelar</button>
        <div id="mot-msg" class="form-alert" style="display:none;flex:1;"></div>
      </div>
    </div>
  </div>
</div>
