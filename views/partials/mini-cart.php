<?php
// views/partials/mini-cart.php

$cartService    = new Cart();
$carrinhoCount = $cartService->getTotalItensCart();
?>
<div class="mc-backdrop" id="mc-backdrop"></div>

<aside class="mini-cart" id="mini-cart"
       role="dialog" aria-label="Meu carrinho" aria-hidden="true">

  <!-- Header -->
  <div class="mc-header">
    <div class="mc-header-left">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2" stroke-linecap="round">
        <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
        <path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/>
      </svg>
      <h2>Meu carrinho <?php var_dump(Session::getClienteId()); ?></h2>
      <span class="mc-badge" id="mc-badge"><?= $carrinhoCount > 99 ? '99+' : $carrinhoCount ?></span>
    </div>
    <button class="mc-close" id="mc-close" aria-label="Fechar carrinho">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
        <line x1="18" y1="6"  x2="6"  y2="18"/>
        <line x1="6"  y1="6"  x2="18" y2="18"/>
      </svg>
    </button>
  </div>

  <!-- Body — apenas itens -->
  <div class="mc-body" id="mc-body">

  <!-- Lista de itens -->
    <!-- Vazio -->
    <div class="mc-empty" id="mc-empty">
      <svg width="56" height="56" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="1.2" stroke-linecap="round">
        <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
        <path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/>
      </svg>
      <p>Seu carrinho está vazio.</p>
      <a href="<?= BASE_URL ?>/busca" class="btn btn-primary btn-sm">
        Explorar produtos
      </a>
    </div>

    
    <!-- Com itens -->
    <div id="mc-content" style="display:none;">

      <!-- ── Itens ──────────────────────────────────────── -->
      <div class="mc-items" id="mc-items"></div>
    </div>

  </div>

  <!-- Footer — totais + acordeões + ações -->
  <div class="mc-footer" id="mc-footer" style="display:none;">

    <!-- ── Acordeões ─────────────────────────────────────── -->
    <div class="mc-accordions">

      <!-- Vendedor -->
        <div class="mc-accordion">
        <button type="button" class="mc-accordion-btn" data-target="mc-seller">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/>
            <circle cx="12" cy="7" r="4"/>
            </svg>
            <span>Código do vendedor</span>
            <span class="mc-accordion-status" id="mc-seller-status"></span>
            <svg class="mc-accordion-chevron" width="12" height="12"
                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <polyline points="6 9 12 15 18 9"/>
            </svg>
        </button>

        <div class="mc-accordion-body" id="mc-seller" style="display:none;">

            <!-- Estado: vendedor aplicado -->
            <div id="mc-seller-tag" style="display:none;">
            <div class="mc-seller-applied">
                <div class="mc-seller-applied-info">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/>
                    <circle cx="12" cy="7" r="4"/>
                </svg>
                <div>
                    <span class="mc-seller-nome" id="mc-seller-tag-nome"></span>
                    <span class="mc-seller-codigo" id="mc-seller-tag-codigo"></span>
                </div>
                </div>
                <div class="mc-seller-applied-actions">
                <button type="button" class="mc-seller-action-btn" id="mc-seller-edit"
                        title="Alterar vendedor">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
                    <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
                    </svg>
                </button>
                <button type="button" class="mc-seller-action-btn mc-seller-action-btn--remove"
                        id="mc-seller-remove" title="Remover vendedor">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <line x1="18" y1="6" x2="6"  y2="18"/>
                    <line x1="6"  y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
                </div>
            </div>
            </div>

            <!-- Estado: form de input -->
            <div id="mc-seller-form">
            <div class="mc-field-group">
                <input type="text" id="mc-seller-input" class="mc-field"
                    placeholder="Ex: VEND123" maxlength="30"
                    style="text-transform:uppercase">
                <button type="button" class="mc-field-btn" id="mc-seller-apply">
                Aplicar
                </button>
            </div>
            <span class="mc-feedback" id="mc-seller-fb"></span>
            </div>

        </div>
        </div><!-- Vendedor -->      

      <!-- Cupom -->
      <div class="mc-accordion">
        <button type="button" class="mc-accordion-btn" data-target="mc-coupon">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <rect x="1" y="4" width="22" height="16" rx="2"/>
            <line x1="1" y1="10" x2="23" y2="10"/>
          </svg>
          <span>Cupom de desconto</span>
          <span class="mc-accordion-status mc-accordion-status--success"
                id="mc-coupon-status"></span>
          <svg class="mc-accordion-chevron" width="12" height="12"
               viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <polyline points="6 9 12 15 18 9"/>
          </svg>
        </button>
        <div class="mc-accordion-body" id="mc-coupon" style="display:none;">
          <?php View::partial('partials/_coupon-input', ['titleLabel'=>false]) ?>
        </div>
      </div>

      <!-- Frete -->
      <div class="mc-accordion">
        <button type="button" class="mc-accordion-btn" data-target="mc-shipping">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <rect x="1" y="3" width="15" height="13" rx="1"/>
            <path d="M16 8h4l3 5v3h-7V8z"/>
            <circle cx="5.5"  cy="18.5" r="2.5"/>
            <circle cx="18.5" cy="18.5" r="2.5"/>
          </svg>
          <span>Calcular frete</span>
          <span class="mc-accordion-status" id="mc-shipping-status"></span>
          <svg class="mc-accordion-chevron" width="12" height="12"
               viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <polyline points="6 9 12 15 18 9"/>
          </svg>
        </button>
        <div class="mc-accordion-body" id="mc-shipping" style="display:none;">

          <div id="mc-cep-row" style="display:none;">
            <div class="mc-cep-info">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/>
                <circle cx="12" cy="10" r="3"/>
              </svg>
              <span id="mc-cep-display"></span>
              <button type="button" class="mc-link-btn"
                      id="mc-cep-change">Alterar</button>
            </div>
          </div>

          <div id="mc-cep-form">
            <div class="mc-field-group">
              <input type="text" id="mc-cep-input" class="mc-field cep-mask"
                     placeholder="00000-000" maxlength="9">
              <button type="button" class="mc-field-btn"
                      id="mc-calc-frete">Calcular</button>
            </div>
            <span class="mc-feedback" id="mc-cep-fb"></span>
          </div>

          <div id="mc-frete-resultado" style="display:none;">
            <div id="mc-frete-best"></div>
            <button type="button" class="mc-toggle-fretes"
                    id="mc-toggle-fretes" style="display:none;">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2.5">
                <polyline points="6 9 12 15 18 9"/>
              </svg>
              Ver todas as opções
            </button>
            <div id="mc-frete-outros" style="display:none;"></div>
          </div>

        </div>
      </div>

    </div>
    <!-- fim .mc-accordions -->
  
    <!-- ── Totais ─────────────────────────────────────────── -->
    <div class="mc-totals">
      <?php include __DIR__ . '/../partials/cart-promo-preview.php'; ?>
      <div class="mc-total-row">
        <span>Subtotal</span>
        <span id="mc-subtotal">—</span>
      </div>
      <div class="mc-total-row mc-total-row--discount"
           id="mc-row-desconto" style="display:none;">
        <span>Desconto</span>
        <span id="mc-desconto">—</span>
      </div>
      <div class="mc-total-row" id="mc-row-frete" style="display:none;">
        <span>
          Frete
          <span class="mc-frete-servico" id="mc-frete-servico"></span>
        </span>
        <span id="mc-frete-valor">—</span>
      </div>
      <div class="mc-total-divider"></div>
      <div class="mc-total-row mc-total-row--total">
        <strong>Total</strong>
        <strong id="mc-total">—</strong>
      </div>
      <p class="mc-parcela" id="mc-parcela" style="display:none;"></p>
    </div>

    <!-- ── Botões de ação ─────────────────────────────────── -->
    <div class="mc-actions">
      <a href="<?= BASE_URL ?>/checkout"
         class="btn btn-primary btn-full mc-btn-checkout">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
        </svg>
        Finalizar compra
      </a>

      <div class="mc-actions-row">
        <a href="<?= BASE_URL ?>/carrinho" class="mc-link-btn">
          Ver carrinho completo
        </a>

        <!-- Compartilhar -->
        
        <button type="button" class="mc-share-btn" id="mc-share-btn">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <circle cx="18" cy="5"  r="3"/>
            <circle cx="6"  cy="12" r="3"/>
            <circle cx="18" cy="19" r="3"/>
            <line x1="8.59"  y1="13.51" x2="15.42" y2="17.49"/>
            <line x1="15.41" y1="6.51"  x2="8.59"  y2="10.49"/>
            </svg>
            Compartilhar carrinho
        </button>
        
      </div>

        <!-- Box de compartilhar (expande abaixo) -->
        <!-- ── Compartilhar ───────────────────────────────── -->
        <div id="mc-share-box" style="display:none;">

            <!-- Identificação do usuário -->
            <?php if (Session::isClienteLogado()): ?>
            <div class="mc-share-quem">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/>
                <circle cx="12" cy="7" r="4"/>
            </svg>
            Compartilhando como
            <strong><?= View::e(Session::get('cliente_nome')) ?></strong>
            </div>
            <?php else: ?>
            <div class="mc-share-field-wrap">
            <label class="mc-share-label" for="mc-share-nome">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/>
                <circle cx="12" cy="7" r="4"/>
                </svg>
                Seu nome
                <span class="mc-share-opcional">(opcional)</span>
            </label>
            <input type="text" id="mc-share-nome" class="mc-field"
                    placeholder="Como quer ser identificado?"
                    maxlength="100">
            </div>
            <?php endif; ?>

            <!-- Vendedor (só exibe se tiver aplicado — preenchido pelo JS) -->
            <div id="mc-share-vendedor-info" style="display:none;">
            <div class="mc-share-quem mc-share-quem--seller">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <rect x="2" y="7" width="20" height="14" rx="2"/>
                <path d="M16 3H8L6 7h12l-2-4z"/>
                </svg>
                Vendedor: <strong id="mc-share-vendedor-nome"></strong>
            </div>
            </div>

            <!-- Botão gerar -->
            <button type="button" class="mc-field-btn mc-share-gerar" id="mc-share-gerar">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                <circle cx="18" cy="5"  r="3"/>
                <circle cx="6"  cy="12" r="3"/>
                <circle cx="18" cy="19" r="3"/>
                <line x1="8.59"  y1="13.51" x2="15.42" y2="17.49"/>
                <line x1="15.41" y1="6.51"  x2="8.59"  y2="10.49"/>
            </svg>
            Gerar link
            </button>

            <!-- Link gerado -->
            <div id="mc-share-link-box" style="display:none;">
                <div class="mc-field-group">
                    <input type="text" id="mc-share-url" class="mc-field" readonly>
                    <button type="button" class="mc-field-btn" id="mc-share-copy">Copiar</button>
                </div>
                <div class="mc-share-expira-wrap">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <circle cx="12" cy="12" r="10"/>
                    <polyline points="12 6 12 12 16 14"/>
                    </svg>
                    <span id="mc-share-expira"></span>
                </div>
                <button type="button" class="mc-share-reset" id="mc-share-reset">
                    Gerar novo link
                </button>
            </div>

        </div>

    </div>

  </div>
  <!-- fim .mc-footer -->

</aside>