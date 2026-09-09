<?php
// views/cart/index.php — Página completa do carrinho
$items         = $totals['items'];
$temItens      = !empty($items);
$totalItens    = count($itens);
$freteGratisMin = (float) ConfigHelper::get('frete_gratis_min', 0);
$faltaFrete    = $freteGratisMin > 0 ? max(0, $freteGratisMin - $totals['subtotal']) : 0;

$cepInfo       = CepController::getCepAtivo();
?>

<div class="cart-page">
  <div class="container">

    <div class="cart-header">
      <h1 class="cart-title">
        Meu Carrinho
        <?php if ($totals['total_itens'] > 0): ?>
          <span class="cart-item-count">(<?= $totals['total_itens'] ?> iten<?= $totals['total_itens'] !== 1 ? 's' : '' ?>)</span>
        <?php endif; ?>
      </h1>
      <a href="<?= BASE_URL ?>/busca" class="cart-continue-link">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <polyline points="15 18 9 12 15 6"/>
        </svg>
        Continuar comprando
      </a>
    </div>

    <?php if (!$temItens): ?>
    <!-- Carrinho vazio -->
    <div class="cart-empty">
      <div class="cart-empty-icon">
        <svg width="80" height="80" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round">
          <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
          <path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/>
        </svg>
      </div>
      <h2>Seu carrinho está vazio</h2>
      <p>Explore nossos produtos e adicione itens ao carrinho.</p>
      <a href="<?= BASE_URL ?>/busca" class="btn btn-primary">Ver produtos</a>
    </div>

    <?php else: ?>
    <!-- Barra de progresso frete grátis -->
    

    <div class="cart-layout">

    
      <!-- ── Itens do carrinho ──────────────────────────── -->
      <div class="cart-items-section set-itens-cart">
        <div class="cart-header">
          <label class="cart-select-all-label">
            <?php
            // O mestre nasce marcado só quando TODOS os itens estão. O estado
            // indeterminado (alguns marcados) quem aplica é o cart.js.
            $todosMarcados = true;
            foreach ($items as $__i) {
                if (array_key_exists('selecionado', $__i) && (int) $__i['selecionado'] !== 1) {
                    $todosMarcados = false;
                    break;
                }
            }
            ?>
            <input type="checkbox" id="cart-select-all" <?= $todosMarcados ? 'checked' : '' ?>>
            <span class="cart-check-custom"></span>
            <span>Todos os produtos
              <em id="cart-selected-count">(<?= $totalItens ?> itens)</em>
            </span>
          </label>

          <button type="button" class="cart-remove-selected" id="btn-remove-selected"
                  style="display:none;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
              <polyline points="3 6 5 6 21 6"/>
              <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
              <path d="M10 11v6M14 11v6"/>
            </svg>
            Remover selecionados
          </button>
        </div>

        <?php include __DIR__ . '/../partials/cart-promo-preview.php'; ?>

        <div class="cart-items-list" id="cart-items-list">
          <?php foreach ($items as $item):
            $imgUrl = !empty($item['imagem'])
                      ? View::e($item['imagem'])
                      : View::asset('images/placeholder.jpg');
            $opcoes = $item['opcoes'] ?? [];

            
            $nome     = $item['nome']     ?? $item['nome_produto']  ?? 'Produto';
            $slug     = $item['slug']     ?? $item['produto_slug']  ?? '';
            $img      = $item['imagem_url'] ?? null;
            $preco    = (float)($item['preco_unitario'] ?? 0);
            $subtotal = (float)($item['subtotal']       ?? $preco * $item['quantidade']);
            $estoque  = (int)($item['estoque_total']    ?? 99);
      
          ?>
          <div class="cart-item" id="cart-item-<?= (int)$item['id'] ?>"
               data-item-id="<?= (int)$item['id'] ?>"
               data-id="<?= (int)$item['id'] ?>"
               data-produto-id="<?= (int)($item['produto_id'] ?? 0) ?>"
               data-preco="<?= $preco ?>"
               data-quantidade="<?= (int)$item['quantidade'] ?>"
               data-subtotal="<?= $subtotal ?>"
               data-peso="<?= (float)($item['peso_kg'] ?? 0) ?>">

            <!-- Checkbox -->
            <?php
            // Sem isto o checkbox nascia SEMPRE marcado: o cliente desmarcava,
            // recarregava a página e a escolha dele tinha sumido.
            $itemSelecionado = !array_key_exists('selecionado', $item)
                            || (int) $item['selecionado'] === 1;
            ?>
            <label class="cart-item-check">
              <input type="checkbox"
                    class="cart-item-checkbox"
                    data-id="<?= (int)$item['id'] ?>"
                    <?= $itemSelecionado ? 'checked' : '' ?>>
              <span class="cart-check-custom"></span>
            </label>

            <!-- Imagem -->
            <div class="cart-item-img">
              <a href="<?= BASE_URL ?>/produto/<?= View::e($item['produto_slug']) ?>">
                <img src="<?= $imgUrl ?>" alt="<?= View::e($item['nome_produto']) ?>"
                     loading="lazy" width="100" height="100">
              </a>
            </div>

            <!-- Informações -->
            <div class="cart-item-info">
              <a href="<?= BASE_URL ?>/produto/<?= View::e($item['produto_slug']) ?>"
                 class="cart-item-name">
                <?= View::e($item['nome_produto']) ?>
              </a>

              <?php if (!empty($opcoes)): ?>
              <div class="cart-item-options">
                <?php foreach ($opcoes as $k => $v): ?>
                  <span class="item-option-pill">
                    <?= View::e($k) ?>: <strong><?= View::e($v) ?></strong>
                  </span>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>

              <?php if (!empty($item['sku_variacao'])): ?>
              <span class="cart-item-sku">SKU: <?= View::e($item['sku_variacao']) ?></span>
              <?php endif; ?>

              <!-- Após o nome do produto, dentro do loop de itens -->

              <?php if (!empty($item['atributos'])): ?>
              <div class="cart-item-attrs">
                <?php foreach ($item['atributos'] as $attr): ?>
                <span class="cart-attr-tag">
                  <?php if ($attr['tipo_display'] === 'color_swatch' && !empty($attr['valor_hex'])): ?>
                    <span class="cart-attr-swatch"
                          style="background:<?= View::e($attr['valor_hex']) ?>"></span>
                  <?php else: ?>
                    <span class="cart-attr-label"><?= View::e($attr['nome']) ?>:</span>
                  <?php endif; ?>
                  <span class="cart-attr-valor"><?= View::e($attr['valor']) ?></span>
                </span>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>

              <!-- Ações mobile -->
              <div class="cart-item-actions-mobile">
                <div class="cart-qty-control">
                  <button class="qty-btn cart-qty-minus"
                          data-item-id="<?= (int)$item['id'] ?>" aria-label="Diminuir">−</button>
                  <input type="number" class="cart-qty-input"
                         value="<?= (int)$item['quantidade'] ?>"
                         min="1"
                         max="<?= (int)($item['estoque_disponivel'] ?? $item['estoque_total']) ?>"
                         data-item-id="<?= (int)$item['id'] ?>">
                  <button class="qty-btn cart-qty-plus"
                          data-item-id="<?= (int)$item['id'] ?>" aria-label="Aumentar">+</button>
                </div>
                <button class="cart-item-remove"
                        data-item-id="<?= (int)$item['id'] ?>" aria-label="Remover item">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                       stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <polyline points="3 6 5 6 21 6"/>
                    <path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a1 1 0 011-1h4a1 1 0 011 1v2"/>
                  </svg>
                  Remover
                </button>
              </div>
            </div>

            <!-- Quantidade (desktop) -->
            <div class="cart-item-qty">
              <div class="cart-qty-control">
                <button class="qty-btn cart-qty-minus"
                        data-item-id="<?= (int)$item['id'] ?>" aria-label="Diminuir">−</button>
                <input type="number" class="cart-qty-input"
                       value="<?= (int)$item['quantidade'] ?>"
                       min="1"
                       max="<?= (int)($item['estoque_disponivel'] ?? $item['estoque_total']) ?>"
                       data-item-id="<?= (int)$item['id'] ?>">
                <button class="qty-btn cart-qty-plus"
                        data-item-id="<?= (int)$item['id'] ?>" aria-label="Aumentar">+</button>
              </div>
            </div>

            <!-- Preço unitário -->
            <?php
            // Só vira etiqueta quando há desconto de verdade. Uma diferença de
            // centavos por arredondamento viraria "0% OFF", que confunde mais
            // do que informa — daí o piso de 1%.
            $precoDe  = (float) ($item['preco_original'] ?? 0);
            $precoPor = (float) ($item['preco_unitario'] ?? 0);
            $pctOff   = ($precoDe > 0 && $precoPor > 0 && $precoDe > $precoPor)
                      ? (int) floor((($precoDe - $precoPor) / $precoDe) * 100)
                      : 0;
            ?>
            <div class="cart-item-price">
              <span class="item-price-label">Preço</span>
              <?php if ($pctOff >= 1): ?>
                <span class="cart-item-price-de"><?= PriceHelper::format($precoDe) ?></span>
                <span class="cart-item-price-off"><?= $pctOff ?>% OFF</span>
              <?php endif; ?>
              <span class="item-price-value cart-item-unit-price"><?= PriceHelper::format($precoPor) ?> / un.</span>
            </div>

            <!-- Subtotal do item -->
            <div class="cart-item-subtotal">
              <span class="item-price-label">Subtotal</span>
              <span class="item-subtotal-value" id="cart-item-subtotal-<?= (int)$item['id'] ?>">
                <?= PriceHelper::format($item['subtotal']) ?>
              </span>
            </div>

            <!-- Remover (desktop) -->
            <div class="cart-item-remove-col">
              <button class="cart-item-remove-btn"
                      data-item-id="<?= (int)$item['id'] ?>" aria-label="Remover">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round">
                  <line x1="18" y1="6" x2="6" y2="18"/>
                  <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
              </button>
            </div>

          </div>
          <?php endforeach; ?>
        </div>

        <!-- Código do vendedor 
        <div class="cart-vendor-code">
          <button class="cart-accordion-trigger" id="toggle-vendor" type="button">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/>
              <circle cx="12" cy="12" r="2"/>
            </svg>
            Tenho código de vendedor
            <svg class="accordion-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
          </button>
          <div class="cart-accordion-body" id="vendor-body" style="display:none;">
            <?php if (!empty($totals['codigo_vendedor'])): ?>
            <div class="vendor-applied">
              Vendedor: <strong><?= View::e($totals['codigo_vendedor']) ?></strong>
              <button type="button" class="btn-clear-vendor" id="btn-clear-vendor">Remover</button>
            </div>
            <?php else: ?>
            <div class="vendor-form">
              <input type="text" id="vendor-input" class="form-control"
                     placeholder="Código do vendedor">
              <button type="button" class="btn btn-dark btn-sm" id="btn-apply-vendor">Aplicar</button>
            </div>
            <span class="vendor-msg" id="vendor-msg"></span>
            <?php endif; ?>
          </div>
        </div>-->

        <!-- Cálculo de frete no carrinho 
        <div class="cart-shipping-calc">
          <button class="cart-accordion-trigger" id="toggle-shipping" type="button">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <rect x="1" y="3" width="15" height="13" rx="1"/>
              <path d="M16 8h5l2 5v3h-7V8z"/>
              <circle cx="5.5" cy="18.5" r="2.5"/>
              <circle cx="18.5" cy="18.5" r="2.5"/>
            </svg>
            Calcular frete e prazo
            <?php if (!empty($totals['frete_servico'])): ?>
              <span class="shipping-selected-badge"><?= View::e($totals['frete_servico']) ?></span>
            <?php endif; ?>
            <svg class="accordion-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
          </button>
          <div class="cart-accordion-body" id="shipping-body"
               style="<?= !empty($totals['frete_servico']) ? '' : 'display:none;' ?>">
            <div class="cart-shipping-form">
              <div class="shipping-input-wrap">
                <input type="text" id="cart-cep" class="form-control cep-mask"
                       placeholder="CEP de entrega" maxlength="9"
                       value="<?= View::e($totals['frete_cep'] ?? '') ?>">
                <a href="https://buscacepinter.correios.com.br/app/endereco/index.php"
                   target="_blank" rel="noopener" class="cep-link">Não sei</a>
              </div>
              <button type="button" class="btn btn-dark btn-sm" id="btn-cart-shipping">
                Calcular
              </button>
            </div>
            <div id="cart-shipping-results" style="display:none;"></div>
          </div>
        </div>-->

      </div>

      

      <aside class="cart-control">
        <?php if ($faltaFrete > 0): ?>
        <div class="free-shipping-bar">
          <div class="free-shipping-track">
            <?php $pct = min(100, ($totals['subtotal'] / $freteGratisMin) * 100); ?>
            <div class="free-shipping-fill" style="width:<?= round($pct) ?>%"></div>
          </div>
          <p class="free-shipping-msg">
            Faltam <strong><?= PriceHelper::format($faltaFrete) ?></strong>
            para você ganhar <strong>frete grátis</strong>!
          </p>
        </div>
        <?php elseif ($freteGratisMin > 0): ?>
        <div class="free-shipping-bar free-shipping-bar--done">
          <div class="free-shipping-track">
            <div class="free-shipping-fill" style="width:100%"></div>
          </div>
          <p class="free-shipping-msg">
            Parabéns! Você ganhou <strong>frete grátis</strong>!
          </p>
        </div>
        <?php endif; ?>

        <!-- ── Vendedor / Compartilhamento ───────────────────────── -->
        <div class="cart-sidebar-vendedor">

          <?php if ($compartilhado_por): ?>
          <!-- Carrinho compartilhado -->
          <div class="cart-vend-info cart-vend-info--shared">
            <div class="cart-vend-icon cart-vend-icon--user">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
                  stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/>
                <circle cx="12" cy="7" r="4"/>
              </svg>
            </div>
            <div class="cart-vend-text">
              <span class="cart-vend-label">Compartilhado por</span>
              <strong class="cart-vend-valor"><?= View::e($compartilhado_por) ?></strong>
            </div>
          </div>
          <?php endif; ?>

          <?php if ($vendedor_nome): ?>
          <!-- Vendedor aplicado -->
          <div class="cart-vend-info cart-vend-info--seller">
            <div class="cart-vend-icon cart-vend-icon--seller">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
                  stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/>
                <circle cx="12" cy="7" r="4"/>
              </svg>
            </div>
            <div class="cart-vend-text">
              <span class="cart-vend-label">Vendedor</span>
              <strong class="cart-vend-valor"><?= View::e($vendedor_nome) ?></strong>
              <?php if ($vendedor_codigo): ?>
              <span class="cart-vend-codigo">(<?= View::e($vendedor_codigo) ?>)</span>
              <?php endif; ?>
            </div>
            <button type="button" class="cart-vend-edit" id="btn-editar-vendedor"
                    title="Alterar vendedor">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                  stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
                <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
              </svg>
            </button>
          </div>

          <?php else: ?>
          <!-- Formulário para informar vendedor -->
          <div class="cart-vend-form" id="cart-vend-form">
            <div class="cart-vend-form-header">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                  stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/>
                <circle cx="12" cy="7" r="4"/>
              </svg>
              <span>Código do vendedor</span>
              <span class="cart-vend-opcional">(opcional)</span>
            </div>
            <div class="cart-vend-field-wrap">
              <input type="text"
                    id="cart-vend-input"
                    class="form-control form-control--sm"
                    placeholder="Ex: VEND123"
                    maxlength="30"
                    style="text-transform:uppercase">
              <button type="button" class="btn btn-outline btn-sm"
                      id="cart-vend-apply">
                Aplicar
              </button>
            </div>
            <span class="cart-vend-feedback" id="cart-vend-fb"></span>
          </div>
          <?php endif; ?>

          <!-- Form oculto de edição (aparece ao clicar em editar) -->
          <?php if ($vendedor_nome): ?>
          <div class="cart-vend-form" id="cart-vend-form" style="display:none;">
            <div class="cart-vend-field-wrap">
              <input type="text"
                    id="cart-vend-input"
                    class="form-control form-control--sm"
                    placeholder="Novo código"
                    value="<?= View::e($vendedor_codigo ?? '') ?>"
                    maxlength="30"
                    style="text-transform:uppercase">
              <button type="button" class="btn btn-outline btn-sm"
                      id="cart-vend-apply">
                Salvar
              </button>
            </div>
            <span class="cart-vend-feedback" id="cart-vend-fb"></span>
          </div>
          <?php endif; ?>

        </div>
      
        

        <!-- ── Resumo do pedido (existente) ─────────────────────── -->
        <!-- ── Resumo do pedido ──────────────────────────── -->
        <!-- Resumo -->
    <!-- ── ENVIO ────────────────────────────────────────────────
         Escolher para onde e por qual serviço é uma DECISÃO, e estava
         espremida dentro do resumo — que é onde se confere, não onde se
         decide. Aqui vira bloco próprio, com o mesmo desenho do widget da
         página de produto: CEP com "Alterar" e a lista atrás de "Ver mais
         opções", para não empurrar o resumo para fora da tela. -->
    <div class="cart-envio" id="cart-envio">
      <div class="cart-envio-head">
        <span class="cart-envio-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
               stroke-linecap="round"><rect x="1" y="7" width="14" height="10" rx="1.5"/><path d="M15 10h4l3 3v4h-7z"/><circle cx="6" cy="18.5" r="1.6"/><circle cx="18" cy="18.5" r="1.6"/></svg>
        </span>
        <div class="cart-envio-head-txt">
          <span class="cart-envio-label">Enviar para</span>
          <strong id="cart-envio-cep">
            <?= $cepInfo['tem_cep'] ? View::e($cepInfo['cep_fmt']) : 'Informe seu CEP' ?>
          </strong>
        </div>
        <!-- Reaproveita a modal de localização que já existe no site — o
             mesmo gesto do header e da página de produto. -->
        <button type="button" class="cart-envio-alterar btn-open-location">
          <?= $cepInfo['tem_cep'] ? 'Alterar' : 'Informar' ?>
        </button>
      </div>

      <!-- Opção escolhida -->
      <div class="cart-envio-escolhido" id="cart-envio-escolhido"
           <?= $cepInfo['tem_cep'] ? '' : 'style="display:none;"' ?>>
        <span id="cart-envio-servico">Calculando…</span>
        <span id="cart-envio-valor"></span>
      </div>

      <!-- CEP manual: só para quem ainda não tem um ativo -->
      <div class="cart-frete-form" id="cart-frete-form"
           <?= $cepInfo['tem_cep'] ? 'style="display:none;"' : '' ?>>
        <div class="cart-frete-input-wrap">
          <input type="text" id="cart-cep-input"
                 class="form-control form-control--sm cep-mask"
                 placeholder="00000-000" maxlength="9">
          <button type="button" class="btn btn-outline btn-sm"
                  id="btn-frete-buscar">OK</button>
        </div>
      </div>

      <button type="button" class="cart-envio-mais" id="btn-ver-mais-frete"
              style="display:none;">
        Ver mais opções
      </button>

      <div id="cart-frete-resultado" style="display:none;"></div>
    </div>

    <?php include __DIR__ . '/../partials/cart-promo-preview.php'; ?>
    <div class="cart-summary" id="cart-summary">
      <h3 class="cart-summary-title">Resumo da compra</h3>

      <!-- Produtos (n) — a contagem é da SELEÇÃO, não do carrinho -->
      <div class="cart-summary-row">
        <span>Produtos (<span id="summary-itens-count">0</span>)</span>
        <span id="summary-subtotal">R$ 0,00</span>
      </div>

      <!-- ORDEM DO ANEXO: produtos, desconto de produtos, envios, cupom.
           O desconto automático vem logo abaixo do subtotal porque incide
           sobre ele; o cupom fica por último, junto do total, porque é o
           único que o cliente controla. -->

      <!-- Desconto de produtos (promoção automática) -->
      <div class="cart-summary-row cart-summary-row--promo"
           id="summary-promo-row" style="display:none;">
        <span>Desconto de produtos</span>
        <span id="summary-promo" class="text-success">− R$ 0,00</span>
      </div>

      <!-- Envios: só o VALOR.
           Escolher o frete é ação, e ação não mora em resumo — foi para o
           bloco "Enviar para", acima. -->
      <div class="cart-summary-row" id="summary-frete-row">
        <span>Envios</span>
        <span>
          <span id="summary-frete-cheio" class="cart-summary-riscado"></span>
          <span id="summary-frete">—</span>
        </span>
      </div>

      <!-- Cupom aplicado.
           UM cupom por pedido — por isso "Cupom aplicado", e não a fração de
           uso que o anexo mostra. Dizer "1/3" prometeria acúmulo que o
           checkout não faz. -->
      <div class="cart-summary-row cart-summary-row--discount"
           id="summary-desconto-row" style="display:none;">
        <span class="cart-summary-cupom-label">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
          Cupom aplicado
        </span>
        <span id="summary-desconto" class="text-success">− R$ 0,00</span>
      </div>

      <!-- Cupom -->
      <div class="cart-cupom">
        <button type="button" class="cart-cupom-toggle"
                id="btn-cupom-toggle">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/>
            <line x1="7" y1="7" x2="7.01" y2="7"/>
          </svg>
          Cupom de desconto
        </button>
        <div class="cart-cupom-form" id="cart-cupom-form" style="display:none;">
          <?php View::partial('partials/_coupon-input', ['titleLabel'=>false]) ?>
        </div>
      </div>

      <div class="cart-summary-divider"></div>

      <!-- Total -->
      <div class="cart-summary-total">
        <strong>Total</strong>
        <span>
          <span id="summary-total-cheio" class="cart-summary-riscado"></span>
          <strong id="summary-total">R$ 0,00</strong>
        </span>
      </div>

      <!-- Quanto o cliente economizou — só aparece quando há economia -->
      <div class="cart-summary-economia" id="summary-economia" style="display:none;">
        Economize <strong id="summary-economia-valor">R$ 0,00</strong>
      </div>

      <div class="cart-summary-installment" id="summary-parcela"></div>

      <!-- Botão checkout: a contagem no rótulo é o que liga o botão à
           seleção. Sem ela o cliente desmarca itens e não tem confirmação
           de quantos está levando. -->
      <button type="button"
              class="btn btn-primary btn-full cart-checkout-btn"
              id="btn-checkout"
              disabled>
        <span id="btn-checkout-label">Continuar</span>
      </button>

      <p class="cart-summary-obs" id="cart-sem-selecao"
         style="display:none;">
        Selecione ao menos um item para continuar.
      </p>

      

    
      </aside>
    <?php endif; ?>

  </div>
</div>

<?php
// Inline data para o JS
$csrfToken = SecurityHelper::generateCsrf();
?>
<script>
const CART_CSRF = '<?= $csrfToken ?>';
</script>