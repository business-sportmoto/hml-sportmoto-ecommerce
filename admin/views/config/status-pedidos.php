<?php
// views/admin/config/status-pedidos.php
// $statusList injetado pelo AdminStatusPedidoController

$coresPossiveis = [
    'warning' => ['label' => 'Amarelo',  'bg' => 'var(--warning-lt)', 'text' => 'var(--warning)', 'dot' => 'var(--warning)'],
    'success' => ['label' => 'Verde',    'bg' => 'var(--success-lt)', 'text' => 'var(--success)', 'dot' => 'var(--success)'],
    'danger'  => ['label' => 'Vermelho', 'bg' => 'var(--danger-lt)', 'text' => 'var(--danger)', 'dot' => 'var(--danger)'],
    'info'    => ['label' => 'Azul claro','bg'=> 'var(--blue-lt)', 'text' => 'var(--blue)', 'dot' => 'var(--blue)'],
    'primary' => ['label' => 'Azul',     'bg' => 'var(--blue-lt)', 'text' => 'var(--blue)', 'dot' => 'var(--blue)'],
    'purple'  => ['label' => 'Roxo',     'bg' => 'var(--purple-lt)', 'text' => 'var(--purple)', 'dot' => 'var(--purple)'],
    'gray'    => ['label' => 'Cinza',    'bg' => 'var(--bg)', 'text' => 'var(--text-2)', 'dot' => 'var(--text-3)'],
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
            <span style="color:var(--border);">—</span>
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
            <span id="sp-slug-lock" style="color:var(--text-3);font-weight:400;">— gerado automaticamente</span>
          </label>
          <input type="text" id="sp-slug" class="form-control"
                 placeholder="aguardando_retirada"
                 style="font-family:'SF Mono',monospace;font-size:12.5px;">
          <small style="color:var(--text-3);font-size:11px;">
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
          <small style="color:var(--text-3);font-size:11px;">
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

<script>

</script>