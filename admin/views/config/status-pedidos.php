<?php
// views/admin/config/status-pedidos.php
// $statusList injetado pelo AdminStatusPedidoController

$coresPossiveis = [
    'warning' => ['label' => 'Amarelo',  'bg' => '#fffbeb', 'text' => '#92400e', 'dot' => '#f59e0b'],
    'success' => ['label' => 'Verde',    'bg' => '#f0fdf4', 'text' => '#15803d', 'dot' => '#16a34a'],
    'danger'  => ['label' => 'Vermelho', 'bg' => '#fef2f2', 'text' => '#b91c1c', 'dot' => '#ef4444'],
    'info'    => ['label' => 'Azul claro','bg'=> '#eff6ff', 'text' => '#1e40af', 'dot' => '#3b82f6'],
    'primary' => ['label' => 'Azul',     'bg' => '#eff6ff', 'text' => '#1d4ed8', 'dot' => '#2563eb'],
    'purple'  => ['label' => 'Roxo',     'bg' => '#f5f3ff', 'text' => '#5b21b6', 'dot' => '#7c3aed'],
    'gray'    => ['label' => 'Cinza',    'bg' => '#f8fafc', 'text' => '#475569', 'dot' => '#94a3b8'],
];
?>

<div class="admin-page">

  <div class="admin-page-header">
    <div>
      <a href="<?= ADMIN_URL ?>/configuracoes" class="back-link">← Configurações</a>
      <h1 class="admin-page-title">Status de pedidos</h1>
      <p class="admin-page-sub">
        Status padrão do sistema têm o slug protegido. Você pode editar o nome, cor,
        ícone e comportamentos de todos os status.
      </p>
    </div>
    <button type="button" class="btn btn-primary" id="btn-novo-status">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="2.5" stroke-linecap="round">
        <line x1="12" y1="5" x2="12" y2="19"/>
        <line x1="5" y1="12" x2="19" y2="12"/>
      </svg>
      Novo status
    </button>
  </div>

  <!-- Legenda de flags -->
  <div class="admin-card" style="margin-bottom:18px;padding:14px 20px;">
    <div style="display:flex;flex-wrap:wrap;gap:20px;font-size:12.5px;color:var(--c-text-muted);">
      <span><strong style="color:var(--c-dark);">Estornar estoque</strong> — Ao entrar neste status, devolve o estoque dos itens</span>
      <span><strong style="color:var(--c-dark);">Cancelar cupom</strong> — Invalida o uso do cupom vinculado</span>
      <span><strong style="color:var(--c-dark);">Bloquear edição</strong> — Impede o admin de editar/remover itens do pedido</span>
      <span><strong style="color:var(--c-dark);">Notificar cliente</strong> — Dispara e-mail automático ao mudar para este status</span>
    </div>
  </div>

  <!-- Lista de status -->
  <div class="admin-card">
    <div id="status-list" style="padding: 8px 0;">
      <?php foreach ($statusList as $s): ?>
      <?php
        $cor     = $coresPossiveis[$s['cor']] ?? $coresPossiveis['info'];
        $isPadrao = (bool)$s['padrao'];
      ?>
      <div class="sp-row" data-id="<?= (int)$s['id'] ?>">

        <!-- Handle drag -->
        <div class="sp-drag-handle" title="Arrastar para reordenar">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round">
            <line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/>
            <line x1="8" y1="18" x2="21" y2="18"/>
            <line x1="3" y1="6"  x2="3.01" y2="6"/>
            <line x1="3" y1="12" x2="3.01" y2="12"/>
            <line x1="3" y1="18" x2="3.01" y2="18"/>
          </svg>
        </div>

        <!-- Badge de cor -->
        <span class="sp-badge"
              style="background:<?= $cor['bg'] ?>;color:<?= $cor['text'] ?>;border:1px solid <?= $cor['dot'] ?>33;">
          <span class="sp-dot" style="background:<?= $cor['dot'] ?>;"></span>
          <?= View::e($s['label']) ?>
        </span>

        <!-- Slug -->
        <code class="sp-slug"><?= View::e($s['slug']) ?></code>

        <!-- Ícone -->
        <div class="sp-icon-preview" title="Ícone: <?= View::e($s['icone_key'] ?? '—') ?>">
          <?php if (!empty($s['icone_key'])): ?>
            <?= IconLibrary::render(View::e($s['icone_key']), 'icon') ?>
          <?php else: ?>
            <span style="color:#e2e8f0;">—</span>
          <?php endif; ?>
        </div>

        <!-- Flags -->
        <div class="sp-flags">
          <?php
            $flags = [
              ['key'=>'estorna_estoque',      'label'=>'Estoque',   'tip'=>'Estornar estoque'],
              ['key'=>'cancela_cupom',         'label'=>'Cupom',     'tip'=>'Cancelar cupom'],
              ['key'=>'bloqueia_edicao_itens', 'label'=>'Bloqueio',  'tip'=>'Bloquear edição de itens'],
              ['key'=>'notifica_cliente',      'label'=>'E-mail',    'tip'=>'Notificar cliente'],
            ];
            foreach ($flags as $f):
              $on = (bool)$s[$f['key']];
          ?>
          <span class="sp-flag <?= $on ? 'sp-flag--on' : 'sp-flag--off' ?>"
                title="<?= $f['tip'] ?>: <?= $on ? 'SIM' : 'NÃO' ?>">
            <?= $f['label'] ?>
          </span>
          <?php endforeach; ?>
        </div>

        <!-- Status ativo/inativo -->
        <span class="sp-ativo <?= $s['ativo'] ? 'sp-ativo--on' : 'sp-ativo--off' ?>">
          <?= $s['ativo'] ? 'Ativo' : 'Inativo' ?>
        </span>

        <!-- Sistema / Custom badge -->
        <?php if ($isPadrao): ?>
          <span class="sp-system-badge">Sistema</span>
        <?php else: ?>
          <span class="sp-custom-badge">Custom</span>
        <?php endif; ?>

        <!-- Ações -->
        <div class="sp-actions">
          <button type="button" class="btn-icon sp-btn-edit"
                  data-id="<?= (int)$s['id'] ?>"
                  title="Editar">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2.5" stroke-linecap="round">
              <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
              <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
            </svg>
          </button>
          <?php if (!$isPadrao): ?>
          <button type="button" class="btn-icon btn-icon--danger sp-btn-del"
                  data-id="<?= (int)$s['id'] ?>"
                  data-label="<?= View::e($s['label']) ?>"
                  title="Excluir">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2.5" stroke-linecap="round">
              <polyline points="3 6 5 6 21 6"/>
              <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
            </svg>
          </button>
          <?php else: ?>
            <span style="width:32px;display:inline-block;"></span>
          <?php endif; ?>
        </div>

      </div>
      <?php endforeach; ?>
    </div>
  </div>

</div>

<!-- ══ MODAL: criar / editar status ════════════════════ -->
<div class="od-modal-overlay" id="modal-status" hidden>
  <div class="od-modal-box od-modal-box--wide">
    <div class="od-modal-header">
      <h4 id="modal-status-titulo">Novo status</h4>
      <button type="button" class="od-modal-close" onclick="fecharModal('modal-status')">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.5" stroke-linecap="round">
          <line x1="18" y1="6" x2="6" y2="18"/>
          <line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>
    <div class="od-modal-body">
      <input type="hidden" id="sp-edit-id">
      <input type="hidden" id="sp-is-padrao" value="0">

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
        <!-- Nome -->
        <div style="grid-column:1/-1;">
          <label class="form-label-xs">Nome do status *</label>
          <input type="text" id="sp-label" class="form-control"
                 placeholder="Ex: Aguardando retirada">
        </div>
        <!-- Slug -->
        <div>
          <label class="form-label-xs">
            Slug (identificador técnico)
            <span id="sp-slug-lock" style="color:#94a3b8;font-weight:400;">— gerado automaticamente</span>
          </label>
          <input type="text" id="sp-slug" class="form-control"
                 placeholder="aguardando_retirada"
                 style="font-family:'SF Mono',monospace;font-size:12.5px;">
          <small style="color:#94a3b8;font-size:11px;">
            Apenas letras minúsculas, números e underscore.
          </small>
        </div>
        <!-- Cor -->
        <div>
          <label class="form-label-xs">Cor</label>
          <div class="sp-cor-picker" id="sp-cor-picker">
            <?php foreach ($coresPossiveis as $key => $info): ?>
            <button type="button" class="sp-cor-opt" data-cor="<?= $key ?>"
                    style="background:<?= $info['dot'] ?>;"
                    title="<?= $info['label'] ?>">
            </button>
            <?php endforeach; ?>
          </div>
          <input type="hidden" id="sp-cor" value="info">
        </div>
        <!-- Ícone -->
        <div style="grid-column:1/-1;">
          <label class="form-label-xs">Chave do ícone (IconLibrary)</label>
          <div style="display:flex;gap:10px;align-items:center;">
            <input type="text" id="sp-icone-key" class="form-control"
                   placeholder="truck, home, clock, x-circle…"
                   style="font-family:'SF Mono',monospace;">
            <div class="sp-icon-preview" id="sp-icone-preview"
                 style="flex-shrink:0;width:40px;height:40px;border:1px solid var(--c-border);
                        border-radius:8px;display:flex;align-items:center;justify-content:center;">
              —
            </div>
          </div>
        </div>
        <!-- Ordenação -->
        <div>
          <label class="form-label-xs">Posição na timeline</label>
          <input type="number" id="sp-ordenacao" class="form-control"
                 placeholder="Ex: 25 (entre 20 e 30)" min="1" max="999">
          <small style="color:#94a3b8;font-size:11px;">
            Menor número = aparece antes. Padrões: 10→20→30→40→50→60→99
          </small>
        </div>
        <!-- Ativo -->
        <div style="display:flex;align-items:center;gap:10px;padding-top:20px;">
          <label class="toggle-field">
            <input type="checkbox" id="sp-ativo" checked>
            <span class="toggle-slider"></span>
            <span>Status ativo</span>
          </label>
        </div>
      </div>

      <!-- Flags de comportamento -->
      <div style="border-top:1px solid var(--c-border);padding-top:14px;margin-top:4px;">
        <p style="font-size:12.5px;font-weight:800;color:var(--c-dark);margin:0 0 12px;">
          Comportamentos ao entrar neste status
        </p>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <label class="toggle-field">
            <input type="checkbox" id="sp-estorna-estoque">
            <span class="toggle-slider"></span>
            <span>Estornar estoque dos itens</span>
          </label>
          <label class="toggle-field">
            <input type="checkbox" id="sp-cancela-cupom">
            <span class="toggle-slider"></span>
            <span>Cancelar cupom vinculado</span>
          </label>
          <label class="toggle-field">
            <input type="checkbox" id="sp-bloqueia-edicao" checked>
            <span class="toggle-slider"></span>
            <span>Bloquear edição de itens</span>
          </label>
          <label class="toggle-field">
            <input type="checkbox" id="sp-notifica-cliente" checked>
            <span class="toggle-slider"></span>
            <span>Notificar cliente por e-mail</span>
          </label>
        </div>
      </div>

      <div style="margin-top:18px;display:flex;gap:10px;align-items:center;">
        <button type="button" class="btn btn-primary" id="btn-sp-salvar">Salvar status</button>
        <button type="button" class="btn btn-outline" onclick="fecharModal('modal-status')">
          Cancelar
        </button>
        <div id="sp-form-msg" class="form-alert" style="display:none;flex:1;"></div>
      </div>
    </div>
  </div>
</div>

<style>
/* ── Status Pedidos — estilos da página ──────────── */
.sp-row {
  display: flex; align-items: center; gap: 12px;
  padding: 11px 20px; border-bottom: 1px solid #f8fafc;
  transition: background .1s;
}
.sp-row:last-child { border-bottom: none; }
.sp-row:hover { background: #fafbfc; }

.sp-drag-handle {
  color: #e2e8f0; cursor: grab; flex-shrink: 0;
  transition: color .15s;
}
.sp-row:hover .sp-drag-handle { color: #94a3b8; }

.sp-badge {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 4px 12px; border-radius: 99px;
  font-size: 13px; font-weight: 700; white-space: nowrap;
  min-width: 160px;
}
.sp-dot {
  width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0;
}

.sp-slug {
  font-family: 'SF Mono', Monaco, Consolas, monospace;
  font-size: 12px; color: #64748b; background: #f1f5f9;
  padding: 2px 8px; border-radius: 5px; white-space: nowrap;
  min-width: 150px;
}

.sp-icon-preview {
  width: 32px; height: 32px; display: flex;
  align-items: center; justify-content: center;
  color: #64748b; flex-shrink: 0;
}
.sp-icon-preview svg { width: 18px; height: 18px; stroke: currentColor; }

.sp-flags { display: flex; gap: 5px; flex-wrap: wrap; }
.sp-flag {
  font-size: 11px; font-weight: 700; padding: 2px 7px;
  border-radius: 5px; white-space: nowrap;
}
.sp-flag--on  { background: #f0fdf4; color: #16a34a; }
.sp-flag--off { background: #f8fafc; color: #cbd5e1; }

.sp-ativo { font-size: 12px; font-weight: 700; white-space: nowrap; }
.sp-ativo--on  { color: #16a34a; }
.sp-ativo--off { color: #ef4444; }

.sp-system-badge {
  font-size: 10.5px; font-weight: 800; text-transform: uppercase;
  letter-spacing: .5px; color: #94a3b8; background: #f1f5f9;
  padding: 2px 7px; border-radius: 5px; white-space: nowrap;
}
.sp-custom-badge {
  font-size: 10.5px; font-weight: 800; text-transform: uppercase;
  letter-spacing: .5px; color: #2563eb; background: #eff6ff;
  padding: 2px 7px; border-radius: 5px; white-space: nowrap;
}

.sp-actions { display: flex; gap: 4px; margin-left: auto; flex-shrink: 0; }

/* Seletor de cor */
.sp-cor-picker { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 6px; }
.sp-cor-opt {
  width: 26px; height: 26px; border-radius: 50%; border: 3px solid transparent;
  cursor: pointer; transition: transform .15s, border-color .15s;
}
.sp-cor-opt:hover { transform: scale(1.15); }
.sp-cor-opt.is-selected { border-color: #0f172a; transform: scale(1.1); }
</style>

<script>

</script>