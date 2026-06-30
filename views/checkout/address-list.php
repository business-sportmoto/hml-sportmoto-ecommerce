<?php
// ════════════════════════════════════════════════════════
// views/checkout/address-list.php
// Lista TODOS os endereços com opção de editar/remover.
// GET /checkout/address/update
// ════════════════════════════════════════════════════════
?>

<div class="checkout-section">
  <div class="section-head">
    <div class="section-head-back">
      <a href="<?= BASE_URL ?>/checkout/address" class="back-link">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.5" stroke-linecap="round">
          <polyline points="15 18 9 12 15 6"/>
        </svg>
        Voltar
      </a>
    </div>
    <h2><span class="section-num">2</span> Gerenciar endereços</h2>
    <p class="section-sub">Gerencie seus endereços: edite, defina um como principal ou remova.</p>
  </div>

  <?php if (empty($enderecos)): ?>

  <div class="empty-state">
    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
      <path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/>
      <polyline points="9 22 9 12 15 12 15 22"/>
    </svg>
    <strong>Nenhum endereço cadastrado</strong>
    <span>Adicione um endereço para continuar sua compra.</span>
    <a href="<?= BASE_URL ?>/checkout/address/add" class="btn btn-primary" style="margin-top:16px;">
      Adicionar endereço
    </a>
  </div>

  <?php else: ?>

  <div class="saved-addresses saved-addresses--manage">
    <?php foreach ($enderecos as $end): ?>
    <div class="address-card address-card--manage <?= $end['principal'] ? 'is-principal' : '' ?>"
         data-end-id="<?= (int)$end['id'] ?>"
         data-hash="<?= View::e($end['hash']) ?>">
      <!-- Radio para selecionar endereço para este pedido -->
      <input type="radio" name="endereco_select"
             class="address-select-radio visually-hidden"
             value="<?= (int)$end['id'] ?>"
             data-hash="<?= View::e($end['hash']) ?>"
             data-principal="<?= $end['principal'] ? '1' : '0' ?>">

      <div class="address-card-body">
        <div class="address-card-header">
          <span class="address-icon">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
              <path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/>
              <polyline points="9 22 9 12 15 12 15 22"/>
            </svg>
          </span>
          <strong><?= View::e($end['nome_destinatario']) ?></strong>
          <?php if ($end['principal']): ?>
          <span class="address-badge">Principal</span>
          <?php endif; ?>
          <?php if (!empty($end['apelido'])): ?>
          <span class="address-badge address-badge--neutral"><?= View::e($end['apelido']) ?></span>
          <?php endif; ?>
        </div>
        <p class="address-line">
          <?= View::e($end['logradouro']) ?>, <?= View::e($end['numero']) ?>
          <?php if (!empty($end['complemento'])): ?> — <?= View::e($end['complemento']) ?><?php endif; ?>
        </p>
        <p class="address-line">
          <?= View::e($end['bairro']) ?> — <?= View::e($end['cidade']) ?>/<?= View::e($end['estado']) ?>
        </p>
        <p class="address-line address-line--cep">CEP <?= View::e($end['cep']) ?></p>
      </div>

      <div class="address-card-actions">
        <a href="<?= BASE_URL ?>/checkout/address/update/<?= View::e($end['hash']) ?>"
           class="address-action address-action--edit" title="Editar">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
            <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
          </svg>
          Editar
        </a>
        <?php if (!$end['principal']): ?>
        <button type="button" class="address-action address-action--principal"
                data-hash="<?= View::e($end['hash']) ?>" title="Tornar principal">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
          </svg>
          Principal
        </button>
        <button type="button" class="address-action address-action--delete"
                data-hash="<?= View::e($end['hash']) ?>" title="Remover">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2.2" stroke-linecap="round">
            <polyline points="3 6 5 6 21 6"/>
            <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
          </svg>
        </button>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Botão selecionar + painel de confirmação -->
  <div class="select-address-wrap">
    <button type="button" class="btn btn-primary btn-full" id="btn-select-for-cart"
            disabled>
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <polyline points="20 6 9 17 4 12"/>
      </svg>
      Selecionar endereço
    </button>

    <!-- Painel de confirmação (aparece ao clicar no botão) -->
    <div class="confirm-panel" id="confirm-panel" hidden>
      <p class="confirm-panel-title" id="confirm-panel-title">
        O que deseja fazer com este endereço?
      </p>
      <div class="confirm-panel-actions">
        <button type="button" class="btn btn-outline btn-full" id="btn-confirm-only-cart">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2.2" stroke-linecap="round">
            <path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/>
            <path d="M16 10a4 4 0 01-8 0"/>
          </svg>
          Usar apenas nesta compra
        </button>
        <button type="button" class="btn btn-primary btn-full" id="btn-confirm-and-principal">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
          </svg>
          Tornar principal e usar agora
        </button>
      </div>
      <button type="button" class="confirm-panel-cancel" id="btn-confirm-cancel">
        Cancelar
      </button>
    </div>
  </div>

  <a href="<?= BASE_URL ?>/checkout/address/add" class="btn-add-address">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="2.5" stroke-linecap="round">
      <line x1="12" y1="5"  x2="12" y2="19"/>
      <line x1="5"  y1="12" x2="19" y2="12"/>
    </svg>
    Adicionar novo endereço
  </a>

  <?php endif; ?>
</div>



<script>
$(function () {

});
</script>

<style>
/* ── Radio seleção de endereço ─────────────────────── */
.visually-hidden {
  position:absolute; width:1px; height:1px;
  padding:0; margin:-1px; overflow:hidden;
  clip:rect(0,0,0,0); white-space:nowrap; border:0;
}
.address-card--manage { cursor:pointer; }
.address-card--manage.is-selecting {
  border-color:var(--c-primary);
  background:linear-gradient(135deg,var(--c-primary-l) 0%,#fff 55%);
  box-shadow:0 0 0 3px rgba(var(--c-primary-rgb,37,99,235),.12);
}

/* ── Botão selecionar + painel de confirmação ─────── */
.select-address-wrap { margin:16px 0; }

.confirm-panel {
  margin-top:10px;
  padding:18px 20px;
  background:#fff;
  border:1.5px solid var(--c-border);
  border-radius:12px;
  animation:panel-slide-in .2s ease;
}
@keyframes panel-slide-in {
  from { opacity:0; transform:translateY(-6px); }
  to   { opacity:1; transform:translateY(0); }
}
.confirm-panel-title {
  font-size:14px; font-weight:800; color:var(--c-dark);
  margin:0 0 14px; line-height:1.4;
}
.confirm-panel-actions {
  display:flex; gap:10px; flex-direction:column;
}
@media (min-width:480px) {
  .confirm-panel-actions { flex-direction:row; }
  .confirm-panel-actions .btn { flex:1; }
}
.confirm-panel-cancel {
  display:block; margin:10px auto 0;
  background:none; border:none; padding:4px 12px;
  font-family:inherit; font-size:12.5px; font-weight:700;
  color:var(--c-text-muted); cursor:pointer;
  transition:color .15s;
}
.confirm-panel-cancel:hover { color:var(--c-dark); }

/* ── Action Toast ──────────────────────────────────── */
.ck-toast--action {
  background:#0f172a;
  flex-direction:column;
  align-items:flex-start;
  gap:10px;
  min-width:280px;
  max-width:360px;
}
.ck-toast-msg { margin:0; font-size:13.5px; line-height:1.4; }
.ck-toast-actions { display:flex; gap:8px; flex-wrap:wrap; width:100%; }
.ck-toast-btn {
  flex:1; padding:7px 12px;
  background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.25);
  border-radius:7px; font-family:inherit; font-size:12.5px;
  font-weight:700; color:#fff; cursor:pointer;
  transition:background .15s; white-space:nowrap;
}
.ck-toast-btn:hover { background:rgba(255,255,255,.25); }
.ck-toast-btn--primary {
  background:var(--c-primary);
  border-color:var(--c-primary);
}
.ck-toast-btn--primary:hover {
  background:color-mix(in srgb,var(--c-primary) 85%,black);
}
</style>