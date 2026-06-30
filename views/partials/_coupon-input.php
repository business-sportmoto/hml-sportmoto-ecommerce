
<?php
// ════════════════════════════════════════════════════════
// views/partials/_coupon-input.php
//
// Partial reutilizável para aplicar cupom.
// Usar em: carrinho, checkout sidebar, summary.
//
// Variáveis:
//   $cupomAtual  — cupom em sessão (pode ser null)
//   $origem      — 'carrinho'|'checkout' (default 'carrinho')
// ════════════════════════════════════════════════════════

$origem      = $origem     ?? 'carrinho';
$cupomAtual  = $cupomAtual ?? Session::get('cupom_aplicado');
$codigoAtual = $cupomAtual['codigo'] ?? '';
$titleLabel = $titleLabel ?? null;
?>



<div class="coupon-input-block" id="coupon-block">

  <?php if (!$cupomAtual): ?>

  <!-- ── Sem cupom — input + botão ──────────────── -->
  <div class="coupon-row">
    <?php if ($titleLabel): ?>
    <label for="coupon-field" class="coupon-label">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/>
        <line x1="7" y1="7" x2="7.01" y2="7"/>
      </svg>
      Cupom de desconto
    </label>
    <?php endif; ?>
    <div class="coupon-field-wrap">
      <input type="text" id="coupon-field" class="form-control coupon-input"
             placeholder="CÓDIGO DO CUPOM" maxlength="50"
             style="text-transform:uppercase; letter-spacing:.5px;"
             autocomplete="off" autocapitalize="characters" spellcheck="false">
      <button type="button" class="btn btn-outline coupon-btn" id="coupon-btn-aplicar">
        Aplicar
      </button>
    </div>
    <div class="coupon-msg" id="coupon-msg" role="status" aria-live="polite"></div>
  </div>

  <?php else: ?>

  <!-- ── Cupom aplicado — badge + remover ───────── -->
  <div class="coupon-applied" id="coupon-applied">
    <div class="coupon-applied-info">
      <span class="coupon-applied-icon">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
      </span>
      <div>
        <strong class="coupon-applied-code"><?= View::e($codigoAtual) ?></strong>
        <?php if (!empty($cupomAtual['desconto']) && $cupomAtual['desconto'] > 0): ?>
          <span class="coupon-applied-value">
            − <?= PriceHelper::format($cupomAtual['desconto']) ?>
          </span>
        <?php endif; ?>
        <?php if (!empty($cupomAtual['frete_desconto']) && $cupomAtual['frete_desconto'] > 0): ?>
          <span class="coupon-applied-value">+ Frete grátis</span>
        <?php endif; ?>
      </div>
    </div>
    <button type="button" class="coupon-remove-btn" id="coupon-btn-remover"
            title="Remover cupom" aria-label="Remover cupom">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="2.5" stroke-linecap="round">
        <line x1="18" y1="6" x2="6" y2="18"/>
        <line x1="6" y1="6" x2="18" y2="18"/>
      </svg>
    </button>
  </div>

  <?php endif; ?>
</div>

<script>
var origemCoupomApi = <?= json_encode($origem) ?>;
</script>

<style>
.coupon-input-block { margin: 10px 0; }
.coupon-label {
  display: flex; align-items: center; gap: 5px;
  font-size: 11.5px; font-weight: 800;
  color: var(--c-text-muted); text-transform: uppercase; letter-spacing: .4px;
  margin-bottom: 6px;
}
.coupon-field-wrap {
  display: flex; gap: 8px;
}
.coupon-input {
  flex: 1; font-size: 13px; font-weight: 700; letter-spacing: .5px;
}
.coupon-btn {
  flex-shrink: 0; padding: 0 16px; height: 42px;
  font-size: 13px; font-weight: 700; white-space: nowrap;
}
.coupon-msg {
  min-height: 16px; font-size: 12.5px; margin-top: 5px; font-weight: 600;
}
.coupon-msg--error   { color: #dc2626; }
.coupon-msg--success { color: var(--c-success); }
.coupon-msg--loading { color: var(--c-text-muted); font-style: italic; }

/* Badge cupom aplicado */
.coupon-applied {
  display: flex; align-items: center; justify-content: space-between;
  padding: 10px 12px; gap: 10px;
  background: #f0fdf4; border: 1.5px solid #bbf7d0;
  border-radius: 10px;
}
.coupon-applied-info { display: flex; align-items: center; gap: 8px; }
.coupon-applied-icon {
  width: 24px; height: 24px; border-radius: 50%;
  background: var(--c-success); color: #fff;
  display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.coupon-applied-icon svg { stroke: #fff; }
.coupon-applied-code {
  display: block; font-size: 13px; font-weight: 800;
  color: #14532d; letter-spacing: .3px;
}
.coupon-applied-value {
  display: block; font-size: 12px; font-weight: 700; color: #16a34a;
}
.coupon-remove-btn {
  width: 28px; height: 28px; flex-shrink: 0;
  background: none; border: none; border-radius: 6px;
  cursor: pointer; color: #94a3b8;
  display: flex; align-items: center; justify-content: center;
  transition: color .15s, background .15s;
}
.coupon-remove-btn:hover { color: #dc2626; background: rgba(220,38,38,.07); }
</style>