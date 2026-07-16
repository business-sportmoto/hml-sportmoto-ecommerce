<?php

$preco      = (float)($product['preco_promo'] ?: $product['preco']);
$precoOrig  = (float)$product['preco'];
$temPromo   = !empty($product['preco_promo']) && $product['preco_promo'] < $precoOrig;
$imgUrl     = !empty($product['imagem_principal'])
              ? View::e($product['imagem_principal'])
              : View::asset('images/placeholder.jpg');

// Range de preços (vindo do model/controller junto com o produto)
$precoMin   = (float)($product['preco_min'] ?? $preco);
$precoMax   = (float)($product['preco_max'] ?? $preco);
$temRange   = isset($product['preco_min'], $product['preco_max'])
              && $product['preco_min'] != $product['preco_max'];

// Preço base para parcelamento
$precoParc  = $temRange ? $precoMin : $preco;
$parcelas   = PriceHelper::installments($precoParc);
$arr        = $parcelas;
$ultima     = end($arr);

$stock_require = $stock_require ?? false;

if($stock_require){
  $disp = $stock_require->getDisponivelNivelVar($product);
  
  if($disp['disponivelTotal'] === 0){
    return false;
  }
}


?>
<div class="product-card" data-product-id="<?= (int)$product['id'] ?>">
  
  <!-- Mídia -->
  <a href="<?= BASE_URL ?>/produto/<?= View::e($product['slug']) ?>"
     class="product-card-media"
     data-images-loaded="false">
    <div class="pc-slider" id="pc-slider-<?= (int)$product['id'] ?>">
      <div class="pc-slide pc-slide--active">
        <img src="<?= $imgUrl ?>"
             alt="<?= View::e($product['nome']) ?>"
             width="300" height="300"
             loading="lazy" class="pc-img">
      </div>
    </div>

    <div class="pc-dots" id="pc-dots-<?= (int)$product['id'] ?>"
         style="display:none;"></div>
    <button type="button" class="pc-arrow pc-arrow--prev" style="display:none;">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
        <polyline points="15 18 9 12 15 6"/>
      </svg>
    </button>
    <button type="button" class="pc-arrow pc-arrow--next" style="display:none;">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
        <polyline points="9 18 15 12 9 6"/>
      </svg>
    </button>

    <?php if ($temPromo): ?>
    <div class="product-badges">
      <span class="badge badge--sale">
        -<?= round((1 - $preco / $precoOrig) * 100) ?>%
      </span>
    </div>
    <?php endif; ?>

    <?php
      // Verifica se o produto está favoritado (passado pelo controller)
      $favoritado = !empty($product['favoritado']);
      // var_dump($product['favoritado']);
    ?>

    <!-- Dentro de .product-card-media, substitua o btn-wishlist -->
    <button type="button"
            class="btn-favorito <?= !empty($product['favoritado']) ? 'active' : '' ?>"
            data-product-id="<?= (int)$product['id'] ?>"
            aria-label="<?= $favoritado ? 'Remover dos favoritos' : 'Adicionar aos favoritos' ?>"
            title="<?= $favoritado ? 'Remover dos favoritos' : 'Adicionar aos favoritos' ?>">
        <svg width="18" height="18" viewBox="0 0 24 24"
          fill="<?= $favoritado ? 'currentColor' : 'none' ?>"
          stroke="currentColor" stroke-width="2" stroke-linecap="round">
        <path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/>
      </svg>
    </button>

    <?php if (isset($product['_tem_clip']) && $product['_tem_clip'] === true): ?>
     <button type="button"
             class="pc-clip-btn"
             data-clip-produto="<?= (int)$product['id'] ?>"
             title="Ver vídeos deste produto">
       <span class="pc-clip-btn__icon">
         <svg viewBox="0 0 24 24" fill="currentColor"><polygon points="6 3 20 12 6 21 6 3"/></svg>
       </span>
       <span class="pc-clip-btn__label">Ver clips</span>
     </button>
   <?php endif; ?>
  </a>

  <!-- Info -->
  <div class="product-card-info">
    
    <?php
    if (Product::temBuscaMoto((int)$product['id'])): 
      $svc          = new VeiculoService();
      // Adicionar ao card do produto — após a imagem, antes do nome:

      // Verifica compatibilidade (deve ser feito em lote antes do loop de produtos)
      // $produtosCompativeis deve ser passado como variável ao renderizar
      // $ehCompativel = isset($produtosCompativeis) && in_array($product['id'], $produtosCompativeis);
      $veiculoAtivo =  Session::get('meu_veiculo') ?? null;
      $ehCompativel = $svc->isProdutoCompativel((int)$product['id']);
      // $veiculoAtivo = null;
      if($veiculoAtivo):
      ?>
      <?php if ($ehCompativel): ?>
      <div class="compat-badge">
        <svg width="11" height="11" viewBox="0 0 24 24" fill="none"
            stroke="currentColor" stroke-width="3" stroke-linecap="round">
          <polyline points="20 6 9 17 4 12"/>
        </svg>
        Compatível com sua moto
      </div>      
      <?php endif; ?> 
    <?php endif; ?> 
    <?php endif; ?> 
    
    <div class="brand-card-item">
      <?= View::e($product['marca_nome']) ?>      
    </div>

    <?php $resumo = (new Review())->getResumo((int)$product['id']); ?>
      <div class="product-rating-top">
          <?php if (!empty($resumo['total']) && (int)$resumo['total'] > 0): ?>
          <?php View::partial('partials/_rating-badge', [
              'media' => (float)$resumo['media'],
              'total' => (int)$resumo['total'],
              'text'  => false,
              'size'  => 'md',
              'link'  => false,
          ]) ?>
          <?php endif; ?>
      </div>

    <a href="<?= BASE_URL ?>/produto/<?= View::e($product['slug']) ?>"
       class="product-name">
      <?= View::e($product['nome']) ?>
    </a>
    

    <!-- ── Preços — sempre visíveis, renderizados no PHP ── -->
    <div class="product-pricing">

      <?php if ($temPromo): ?>
        <!-- Promoção -->
        <span class="price-orig"><?= PriceHelper::format($precoOrig) ?></span>
        <span class="price-main price-main--sale"><?= PriceHelper::format($preco) ?></span>
        <?php if ($ultima && $ultima['parcelas'] > 1): ?>
        <span class="price-install">
          ou <?= $ultima['parcelas'] ?>x
          de <?php
          //PriceHelper::format($ultima['valor'])
          ?> sem juros
        </span>
        <?php endif; ?>

      <?php elseif ($temRange): ?>
        <!-- Range: variações com preços diferentes -->
        <span class="price-from">A partir de</span>
        <span class="price-main"><?= PriceHelper::format($precoMin) ?></span>
        <span class="price-range-sm">
          <?= PriceHelper::format($precoMin) ?> até
          <?= PriceHelper::format($precoMax) ?>
        </span>
        <?php if ($ultima && $ultima['parcelas'] > 1): ?>
        <span class="price-install">
          em até <?= $ultima['parcelas'] ?>x sem juros
        </span>
        <?php endif; ?>

      <?php else: ?>
        <!-- Preço único -->
        <span class="price-main"><?= PriceHelper::format($preco) ?></span>
        <?php if ($ultima && $ultima['parcelas'] > 1): ?>
        <span class="price-install">
          ou <?= $ultima['parcelas'] ?>x
          de <?= PriceHelper::format($ultima['valor_parcela']) ?> sem juros
        </span>
        <?php endif; ?>
      <?php endif; ?>

    </div>
    <!-- ────────────────────────────────────────────────── -->

    <?php if (($product['estoque_total'] ?? 0) > 0): ?>
    <button type="button"
            class="btn btn-primary btn-sm btn-full pc-btn-add"
            data-product-id="<?= (int)$product['id'] ?>">
      Adicionar ao carrinho
    </button>
    <?php else: ?>
    <span class="product-unavailable">Indisponível</span>
    <?php endif; ?>

  </div>

  <!-- Mini modal de variações -->
  <div class="pc-variation-modal"
       id="pc-modal-<?= (int)$product['id'] ?>"
       style="display:none;"
       aria-hidden="true">

    <div class="pc-modal-header">
      <span class="pc-modal-title">Selecione as opções</span>
      <button type="button" class="pc-modal-close"
              data-product-id="<?= (int)$product['id'] ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <line x1="18" y1="6" x2="6"  y2="18"/>
          <line x1="6"  y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>

    <div class="pc-modal-body"
         id="pc-modal-body-<?= (int)$product['id'] ?>">
      <div class="pc-modal-loading">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2"
             style="animation:spin .7s linear infinite">
          <path d="M21 12a9 9 0 11-6.219-8.56"/>
        </svg>
      </div>
    </div>

    <div class="pc-modal-footer"
         id="pc-modal-footer-<?= (int)$product['id'] ?>">
      <div class="pc-modal-price"
           id="pc-modal-price-<?= (int)$product['id'] ?>"
           style="display:none;">
        <span class="pc-modal-price-orig" style="display:none;"></span>
        <span class="pc-modal-price-val"></span>
        <span class="pc-modal-price-install"></span>
      </div>
      <button type="button"
              class="btn btn-primary btn-full pc-modal-confirm"
              data-product-id="<?= (int)$product['id'] ?>"
              disabled>
        Adicionar ao carrinho
      </button>
    </div>

  </div>

</div>