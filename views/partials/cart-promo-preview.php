<?php // views/partials/cart-promo-preview.php
// Incluir na view do carrinho, abaixo dos itens ou no resumo lateral.
// O JS (cart-promo-preview.js) preenche #promo-preview-cards via AJAX.
?>
<div id="cart-promo-preview" aria-live="polite" aria-label="Promoções disponíveis">
  <div id="promo-preview-cards"></div>
</div>

<style>
/* ═══════════════════════════════════════════
   CONTAINER E ANIMAÇÃO
═══════════════════════════════════════════ */
#cart-promo-preview { margin: 0; }

.promo-card {
  border-radius: 12px;
  padding: 12px 16px;
  margin-bottom: 10px;
  display: flex;
  flex-direction: column;
  gap: 8px;
  font-size: 13.5px;
  line-height: 1.4;
  animation: promo-fadein .25s ease;
}
@keyframes promo-fadein {
  from { opacity: 0; transform: translateY(-4px); }
  to   { opacity: 1; transform: translateY(0); }
}

/* ═══════════════════════════════════════════
   ESTADOS — desconto, brinde, compre_ganhe
═══════════════════════════════════════════ */
.promo-card--aplicada {
  background: #f0fdf4; border: 1px solid #bbf7d0;
}
.promo-card--aplicada .promo-card-icon,
.promo-card--aplicada .promo-card-header { color: #15803d; }

.promo-card--proxima_faixa {
  background: #fffbeb; border: 1px solid #fde68a;
}
.promo-card--proxima_faixa .promo-card-icon,
.promo-card--proxima_faixa .promo-card-header { color: #92400e; }

.promo-card--disponivel {
  background: #eff6ff; border: 1px solid #bfdbfe;
}
.promo-card--disponivel .promo-card-icon,
.promo-card--disponivel .promo-card-header { color: #1e40af; }

/* ═══════════════════════════════════════════
   CASHBACK — visual distinto (não é desconto)
═══════════════════════════════════════════ */
.promo-card--cashback {
  background: #f5f3ff; border: 1px solid #ddd6fe;
}
.promo-card--cashback .promo-card-icon,
.promo-card--cashback .promo-card-header { color: #5b21b6; }
.promo-cashback-valor {
  font-size: 14px; font-weight: 800;
  color: #7c3aed; white-space: nowrap;
}
.promo-cashback-meta {
  font-size: 11.5px; color: #7c3aed; opacity: .8;
}

/* ═══════════════════════════════════════════
   LAYOUT INTERNO COMUM
═══════════════════════════════════════════ */
.promo-card-header {
  display: flex; align-items: flex-start;
  gap: 10px; font-weight: 600;
}
.promo-card-icon { flex-shrink: 0; margin-top: 1px; }
.promo-card-text { flex: 1; }
.promo-card-desconto {
  font-size: 13px; font-weight: 700;
  color: #16a34a; white-space: nowrap;
}

/* ═══════════════════════════════════════════
   BADGES INLINE
═══════════════════════════════════════════ */
.promo-faixa-badge {
  display: inline-flex; align-items: center; gap: 4px;
  font-size: 11px; font-weight: 700;
  background: rgba(0,0,0,.06);
  padding: 2px 8px; border-radius: 99px; margin-top: 2px;
}
.promo-badge-gratis {
  display: inline-flex; align-items: center;
  font-size: 10.5px; font-weight: 800; letter-spacing: .5px;
  background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0;
  padding: 1px 8px; border-radius: 99px; white-space: nowrap;
}
.promo-badge-brinde {
  display: inline-flex; align-items: center;
  font-size: 10.5px; font-weight: 800; letter-spacing: .5px;
  background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0;
  padding: 1px 8px; border-radius: 99px; margin-left: 4px;
}
.promo-brinde-nome {
  font-weight: 700; margin-right: 2px;
}

/* ═══════════════════════════════════════════
   BARRA DE PROGRESSO
═══════════════════════════════════════════ */
.promo-progress-wrap {
  display: flex; align-items: center; gap: 8px;
}
.promo-progress-track {
  flex: 1; height: 6px;
  background: rgba(0,0,0,.08); border-radius: 99px; overflow: hidden;
}
.promo-progress-bar {
  height: 100%; border-radius: 99px; transition: width .4s ease;
}
.promo-card--aplicada      .promo-progress-bar { background: #16a34a; }
.promo-card--proxima_faixa .promo-progress-bar { background: #f59e0b; }
.promo-card--disponivel    .promo-progress-bar { background: #3b82f6; }
.promo-progress-pct {
  font-size: 11px; font-weight: 700;
  color: var(--c-text-muted, #9ca3af);
  white-space: nowrap; min-width: 30px; text-align: right;
}

/* ═══════════════════════════════════════════
   BADGE INLINE NOS ITENS DO CARRINHO
═══════════════════════════════════════════ */
.promo-item-badge {
  display: inline-flex; align-items: center;
  font-size: 10.5px; font-weight: 800; letter-spacing: .4px;
  background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0;
  padding: 1px 8px; border-radius: 99px;
  margin-left: 6px; vertical-align: middle; white-space: nowrap;
}
.promo-item-badge--cashback {
  background: #f5f3ff; color: #7c3aed; border-color: #ddd6fe;
}
.promo-item-badge--compre_ganhe {
  background: #ecfdf5; color: #059669; border-color: #a7f3d0;
}
</style>

<style>
/* ═══════════════════════════════════════════
   ITEM BRINDE NA LISTA DO CARRINHO
   Mesmo grid das colunas do .cart-item normal:
   check · img · info · qty · price · subtotal · remove-col
═══════════════════════════════════════════ */
.cart-item--brinde {
  background: linear-gradient(135deg, #f0fdf4 0%, #fafffe 100%);
  border-top: 2px dashed #86efac !important;
  position: relative;
  animation: promo-fadein .3s ease;
}
.cart-item--brinde::before {
  content: '🎁 BRINDE';
  position: absolute; top: 0; left: 0;
  font-size: 10px; font-weight: 800; letter-spacing: .6px;
  color: #15803d; background: #dcfce7;
  border-bottom-right-radius: 8px;
  padding: 2px 10px; line-height: 1.8; z-index: 2;
}
.cart-item--brinde .cart-item-check { pointer-events: none; opacity: .5; }
.cart-item--brinde .cart-item-checkbox { display: none; }
.cart-item--brinde .cart-check-custom {
  display: flex; align-items: center; justify-content: center; color: #16a34a;
}
.cart-item--brinde .cart-check-custom::before { content: '🎁'; font-size: 18px; }
.cart-item--brinde .cart-item-img { position: relative; }
.brinde-img-badge {
  position: absolute; bottom: -4px; right: -4px;
  background: #16a34a; color: #fff;
  font-size: 10px; font-weight: 800;
  width: 20px; height: 20px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  border: 2px solid #fff;
}
.cart-item--brinde .item-price-value {
  text-decoration: line-through; color: #94a3b8;
  font-size: 12px; font-weight: 400;
}
.brinde-gratis-label {
  display: block; font-size: 13px; font-weight: 800;
  color: #16a34a; letter-spacing: .4px;
}
.cart-item--brinde .item-subtotal-value { font-weight: 800; color: #16a34a; }
.cart-item--brinde .cart-qty-control { pointer-events: none; opacity: .5; }
.cart-item--brinde .qty-btn { cursor: not-allowed; }
.cart-item--brinde .cart-item-remove-btn,
.cart-item--brinde .cart-item-remove { pointer-events: none; opacity: .35; cursor: not-allowed; }
</style>

<style>
/* Detalhe do item com desconto no card Compre X leve Y */
.promo-cg-detalhe {
  border-top: 1px solid rgba(0,0,0,.07);
  padding-top: 7px;
  margin-top: 2px;
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.promo-cg-detalhe-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  font-size: 12.5px;
}
.promo-cg-item-nome {
  color: var(--c-dark, #1e293b);
  font-weight: 600;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 200px;
}
.promo-cg-badge-gratis {
  font-size: 10.5px; font-weight: 800; letter-spacing: .5px;
  background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0;
  padding: 1px 8px; border-radius: 99px; white-space: nowrap; flex-shrink: 0;
}
.promo-cg-badge-off {
  font-size: 10.5px; font-weight: 800; letter-spacing: .5px;
  background: #fef9c3; color: #854d0e; border: 1px solid #fde68a;
  padding: 1px 8px; border-radius: 99px; white-space: nowrap; flex-shrink: 0;
}
</style>