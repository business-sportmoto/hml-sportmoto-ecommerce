<?php
$cepInfoData       = CepController::getCepAtivo();
// views/products/detail.php — Página de produto (REDESIGN)
// ─────────────────────────────────────────────────────────────
// Preserva TODOS os hooks do product.js e bindings do controller.
// Absorve: BUG-1 ($reviewStats inline removido), BUG-2 ($semEstoque
// isolado no loop de família via $membroSemEstoque), BUG-4 (tab
// "Ficha técnica" morta removida), + ID duplicado #sku-preco-wrapper
// (mantido só o do painel de preço; removido o das variações).
// Wrapper .pdx para o CSS novo (product-detail.css) vencer por
// especificidade sem colidir com o main.css.
// ─────────────────────────────────────────────────────────────
$preco     = PriceHelper::currentPrice($product);
$precoOrig = (float) $product['preco'];
$temPromo  = $preco < $precoOrig;
$descPct   = $temPromo ? PriceHelper::discountPercent($precoOrig, $preco) : 0;
$semEstoque = (int)($product['estoque_total']) === 0;
$mainImage  = !empty($images) ? $images[0] : null;

$resumo = (new Review())->getResumo((int)$product['id']);

// var_dump($images);

View::partial('products/schema-jsonld',
[
  'product'=> $product,
  'semEstoque'=>$semEstoque,
  'preco'=>$preco,
  'images'=>$images,
  'resumo'=>$resumo,
]);

$favoritado  = false;
$listasProduto = [];

$clienteLogado = false;
$clienteData = [];
if (Session::isClienteLogado()) {
    $wl        = new Wishlist();
    $user = new User();

    $clienteId = (int)Session::get('cliente_id');
    $clienteLogado = true;
    $clienteData = $user->getUserParcial($clienteId);

    $favoritado = $wl->isProdutoFavorito($clienteId, (int)$product['id']);

    $db   = Database::getInstance()->getConnection();
    $stmt = $db->prepare(
        "SELECT w.id, w.nome, w.padrao,
                (SELECT COUNT(*) FROM wishlist_itens wi
                 WHERE wi.wishlist_id = w.id
                   AND wi.produto_id  = ?) AS tem_produto
         FROM wishlist w
         WHERE w.cliente_id = ?
         ORDER BY w.padrao DESC, w.criado_em ASC"
    );
    $stmt->execute([(int)$product['id'], $clienteId]);
    $listasProduto = $stmt->fetchAll();

    $padrao = array_filter($listasProduto, function($item) {
        return $item['padrao'] == 1;
    });
    $resultado_padrao_wish = reset($padrao);
}

// Helper inline para renderizar o swatch correto (família)
function renderSwatch(array $membro, string $atributoSlug): void {
    $img = $membro['agrupadores_img'][$atributoSlug] ?? null;
    $hex = $membro['agrupadores_hex'][$atributoSlug] ?? null;
    $val = $membro['agrupadores'][$atributoSlug]     ?? '';

    if ($img) {
        echo '<img src="' . UPLOAD_URL . '/products/' . htmlspecialchars($img, ENT_QUOTES) . '"'
           . ' alt="' . htmlspecialchars($val, ENT_QUOTES) . '" loading="lazy">';
    } elseif ($hex) {
        echo '<span class="swatch-color" style="background-color:' . htmlspecialchars($hex, ENT_QUOTES) . '"></span>';
    } else {
        echo '<span class="swatch-text">' . htmlspecialchars(mb_substr($val, 0, 3), ENT_QUOTES) . '</span>';
    }
}
function swatchTipo(array $membro, string $atributoSlug): string {
    if (!empty($membro['agrupadores_img'][$atributoSlug])) return 'swatch--img';
    if (!empty($membro['agrupadores_hex'][$atributoSlug])) return 'swatch--color';
    return 'swatch--text';
}
?>

<!-- Breadcrumb -->
<nav class="breadcrumb-nav">
  <div class="container">
    <ol class="breadcrumb">
      <?php foreach ($breadcrumb as $crumb): ?>
      <li class="breadcrumb-item <?= $crumb['url'] === null ? 'active' : '' ?>">
        <?php if ($crumb['url']): ?>
          <a href="<?= View::e($crumb['url']) ?>"><?= View::e($crumb['label']) ?></a>
        <?php else: ?>
          <span><?= View::e($crumb['label']) ?></span>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ol>
  </div>
</nav>
<?php View::partial('products/schema-breadcrumb', [
  'breadcrumb'=> $breadcrumb
]) ?>
<?php
  if(CONF_BAR_VEICLE) {
    if(Product::temBuscaMoto((int)$product['id'])) {
        View::partial('partials/meu-veiculo-bar', ['produtosCompativeis' => $produtosCompativeis ?? []]);
    }
  }
?>

<?php
  // Resumo de avaliações (para o cabeçalho)
  
?>

<div class="pdx">
<div class="product-page" id="product-detail" data-product-id="<?= (int)$product['id'] ?>">
  <div class="container">

    <!-- ═══ CABEÇALHO FULL-WIDTH ═══ -->
    <div class="pdx-head">
      

      <h1 class="pdx-title product-title"><?= View::e($product['nome']) ?></h1>
      
      <div class="pdx-headmeta">
        <span class="pdx-headdiv"></span>
        <?php if (!empty($product['marca_nome'])): ?>
          <a href="<?= BASE_URL ?>/marca/<?= View::e($product['marca_slug']) ?>" class="pdx-brand"><?= View::e($product['marca_nome']) ?></a>
        <?php endif; ?>
        <span class="pdx-headdiv"></span>
        <?php if (!empty($product['sku_legado'])): ?>
          <span class="pdx-sku">COD. <?= View::e($product['sku_legado']) ?></span>
        <?php endif; ?>
        <span class="pdx-headdiv"></span>
        <?php if (!empty($resumo['total']) && (int)$resumo['total'] > 0): ?>
        <div class="pdx-rating">
          <?php View::partial('partials/_rating-badge', [
              'media' => (float)$resumo['media'],
              'total' => (int)$resumo['total'],
              'size'  => 'md',
              'link'  => '#sm-reviews-section',
          ]) ?>
        </div>
        <span class="pdx-headdiv"></span>
        <?php endif; ?>
        
      </div>
    </div>

    <!-- ═══ GRID: galeria | info ═══ -->
    <div class="product-detail-layout pdx-grid">

      <!-- ── GALERIA ── -->
      <div class="product-gallery" id="product-gallery">
        <div class="pdx-gallery-top<?= count($images) > 1 ? '' : ' pdx-gallery-top--solo' ?>">

          <!-- Miniaturas (hook: #gallery-thumbs / .gallery-thumb) -->
          <?php if (count($images) > 1): ?>
          <div class="gallery-thumbs" id="gallery-thumbs">
            <?php
              $thumbMax = 5;
              $overflow = count($images) > $thumbMax;
              $visiveis = $overflow ? $thumbMax - 1 : count($images);
            ?>
            <?php foreach ($images as $i => $img): ?>
              <?php if ($i < $visiveis): ?>
              <button class="gallery-thumb <?= $i === 0 ? 'active' : '' ?>"
                      data-src="<?= View::uploadR2($img['arquivo']) ?>"
                      data-index="<?= $i ?>"
                      aria-label="Imagem <?= $i + 1 ?>">
                <img src="<?= View::uploadR2($img['arquivo']) ?>"
                     alt="<?= View::e($img['alt_text'] ?? $product['nome']) ?>"
                     loading="lazy" width="72" height="72">
              </button>
              <?php elseif ($i === $visiveis): ?>
              <!-- Tile "+N" abre a galeria fullscreen -->
              <button class="gallery-thumb pdx-thumb-more" type="button"
                      data-more="<?= count($images) - $visiveis ?>"
                      data-index="<?= $i ?>"
                      aria-label="Ver todas as fotos">
                <img src="<?= View::uploadR2($img['arquivo']) ?>" alt="" loading="lazy" width="72" height="72">
              </button>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <!-- Imagem principal (hooks: #zoom-wrapper / #gallery-main-img) -->
          <div class="gallery-main">
            <div class="gallery-zoom-wrapper" id="zoom-wrapper">
              <img id="gallery-main-img"
                   src="<?= $mainImage ? View::uploadR2($mainImage['arquivo']) : View::asset('images/placeholder.jpg') ?>"
                   alt="<?= View::e($product['nome']) ?>"
                   class="gallery-main-img"
                   fetchpriority="high">
            </div>

            <div class="product-badges product-badges--lg">
              <?php if ($temPromo && $descPct > 0): ?>
                <span class="badge badge--sale badge--lg">-<?= $descPct ?>%</span>
              <?php endif; ?>
              <?php if ($semEstoque): ?>
                <span class="badge badge--soldout badge--lg">Esgotado</span>
              <?php endif; ?>
            </div>

            <?php if (count($images) > 0): ?>
            <span class="pdx-gwatch">
              <svg viewBox="0 0 24 24" fill="none" stroke-linecap="round" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.3-4.3"/></svg>
              Toque para ampliar
            </span>
            <?php endif; ?>

            <?php if (count($images) > 1): ?>
            <button class="gallery-arrow gallery-arrow--prev" id="gallery-prev" aria-label="Imagem anterior">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="15 18 9 12 15 6"/></svg>
            </button>
            <button class="gallery-arrow gallery-arrow--next" id="gallery-next" aria-label="Próxima imagem">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="9 18 15 12 9 6"/></svg>
            </button>
            <?php endif; ?>
          </div>
        </div>

        <!-- Compartilhamento (hook: #btn-copy-link) -->
        <div class="product-share">
          <span class="share-label">Compartilhar:</span>
          <div class="share-buttons">
            <?php
            $shareUrl   = urlencode(BASE_URL . '/produto/' . $product['slug']);
            $shareTitle = urlencode($product['nome']);
            ?>
            <a href="https://wa.me/?text=<?= $shareTitle ?>%20<?= $shareUrl ?>" target="_blank" rel="noopener" class="share-btn share-btn--whatsapp" aria-label="WhatsApp">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413z"/></svg>
            </a>
            <a href="https://www.facebook.com/sharer/sharer.php?u=<?= $shareUrl ?>" target="_blank" rel="noopener" class="share-btn share-btn--facebook" aria-label="Facebook">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
            </a>
            <button class="share-btn share-btn--copy" id="btn-copy-link" data-url="<?= View::e(BASE_URL . '/produto/' . $product['slug']) ?>" aria-label="Copiar link">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
            </button>
          </div>
        </div>

        <!-- Clips / reels (partial autossuficiente) -->
        <div class="pdx-clips">
          <?php View::partial('partials/clips-product-stories', ['produto_id' => $product['id']]) ?>
        </div>
      </div><!-- /product-gallery -->

      <!-- ── INFO ── -->
      <div class="product-info" id="product-info">

        <!-- Banner de compatibilidade (moto) -->
        <?php
        if(Product::temBuscaMoto((int)$product['id'])):
          $veiculoAtivo = $_SESSION['meu_veiculo'] ?? null;
          if ($veiculoAtivo):
              $svc          = new VeiculoService();
              $ehCompativel = $svc->isProdutoCompativel((int)$product['id']);
          ?>
          <div class="prod-compat-banner <?= $ehCompativel ? 'is-compat' : 'is-nc' ?>" id="prod-compat-banner">
            <?php if ($ehCompativel): ?>
            <div class="prod-compat-icon prod-compat-icon--ok">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="3" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
            <div class="prod-compat-info">
              <strong>Compatível com sua moto</strong>
              <span><?= View::e($veiculoAtivo['label']) ?></span>
            </div>
            <?php else: ?>
            <div class="prod-compat-icon prod-compat-icon--nc">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            </div>
            <div class="prod-compat-info">
              <strong>Compatibilidade não confirmada</strong>
              <span>Não encontramos compatibilidade para <em><?= View::e($veiculoAtivo['label']) ?></em>.
                <a href="#tab-compatibilidade" class="prod-compat-link">Ver motos compatíveis</a>
              </span>
            </div>
            <?php endif; ?>
            <button type="button" class="prod-compat-trocar" id="prod-compat-trocar">Trocar moto</button>
          </div>
          <?php endif; ?>
        <?php endif; ?>

        <?php
          // ── Variáveis de preço/parcelamento ──
          $temRange       = $vdata['tem_range_preco'] ?? false;
          $precoMinFmt    = $vdata['preco_min_fmt']   ?? PriceHelper::format((float)$product['preco']);
          $precoMaxFmt    = $vdata['preco_max_fmt']   ?? null;
          $temPromo       = !empty($product['preco_promo']) && $product['preco_promo'] < $product['preco'];

          $precoParcelar  = (float)($vdata['preco_min'] ?? $product['preco']);
          $parcelas       = PriceHelper::installments($precoParcelar);
          $arrParcelas    = $parcelas;
          $ultimaParcela  = end($arrParcelas);
          $maxParcelas    = $ultimaParcela ? (int)$ultimaParcela['parcelas'] : 0;

          // Descontos por método sobre o preço efetivo.
          // O percentual vive AQUI e em nenhum outro lugar: a cópia da página,
          // a modal de pagamento e o recálculo por SKU no JS (PV.pix_pct) leem
          // todos daqui. Antes o 0.95 e o texto "5% off" eram dois números
          // soltos que podiam divergir em silêncio.
          $pixPct      = 5;
          $boletoPct   = 3;
          $precoPix    = $preco * (1 - $pixPct / 100);
          $precoBoleto = $preco * (1 - $boletoPct / 100);
        ?>

        <!-- ═══ PAINEL DE PREÇO (consolidado — hooks #sku-preco-* / #price-range-wrapper) ═══ -->
        <div class="pdx-price product-price-block" id="product-price-block">

          <!-- Favorito (coração) — hooks .btn-favorito / wishlist-control -->
          <button type="button"
                  class="btn-favorito btn-favorito--detail wishlist-control pdx-fav <?= $favoritado ? 'active' : '' ?>"
                  data-product-id="<?= (int)$product['id'] ?>" data-list-id="<?= $resultado_padrao_wish['id'] ?? '' ?>"
                  aria-label="<?= $favoritado ? 'Remover dos favoritos' : 'Adicionar aos favoritos' ?>">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="<?= $favoritado ? 'currentColor' : 'none' ?>" stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/>
            </svg>
          </button>

          <!-- Preço do SKU selecionado (oculto até selecionar) — CANÔNICO -->
          <div id="sku-preco-wrapper" style="display:none;">
            <span class="pdx-avail <?= $semEstoque ? 'is-out' : '' ?>">
              <span class="dot"></span><?= $semEstoque ? 'Indisponível' : 'Pronta entrega' ?>
            </span>
            <div class="pdx-price-row price-values">
              <span class="pdx-price-was price-original" id="sku-preco-original" style="display:none;"></span>
              <span class="pdx-price-now price-current price-current--sale" id="sku-preco-valor"></span>
            </div>
            <span class="pdx-price-install price-installment" id="sku-preco-parcela"></span>
          </div>

          <!-- Preço base / range (oculto após selecionar) -->
          <div id="price-range-wrapper">
            <?php if ($temPromo): ?>
              <span class="pdx-avail <?= $semEstoque ? 'is-out' : '' ?>">
                <span class="dot"></span><?= $semEstoque ? 'Indisponível' : 'Pronta entrega' ?>
              </span>
              <div class="pdx-price-row price-values">
                <span class="pdx-price-was price-original"><?= PriceHelper::format((float)$product['preco']) ?></span>
                <span class="pdx-price-now price-current price-current--sale"><?= PriceHelper::format((float)$product['preco_promo']) ?></span>
                <?php if ($descPct > 0): ?><span class="pdx-price-off">-<?= $descPct ?>%</span><?php endif; ?>
              </div>
              <?php if ($maxParcelas > 1): ?>
              <!-- BUG corrigido: valor da parcela agora é renderizado -->
              <span class="pdx-price-install price-installment">
                ou <?= $ultimaParcela['parcelas'] ?>x de <strong><?= PriceHelper::format($ultimaParcela['valor_parcela']) ?></strong> sem juros
              </span>
              <?php endif; ?>

            <?php elseif ($temRange): ?>
              <span class="pdx-price-from price-label">A partir de</span>
              <div class="pdx-price-row price-values">
                <span class="pdx-price-now price-current"><?= $precoMinFmt ?></span>
              </div>
              <div class="pdx-price-range price-range-detail">
                <?= $precoMinFmt ?> <span class="price-range-sep">até</span> <?= $precoMaxFmt ?>
              </div>
              <?php if ($maxParcelas > 1): ?>
              <span class="pdx-price-install price-installment">em até <?= $maxParcelas ?>x sem juros</span>
              <?php endif; ?>

            <?php else: ?>
              <span class="pdx-avail <?= $semEstoque ? 'is-out' : '' ?>">
                <span class="dot"></span><?= $semEstoque ? 'Indisponível' : 'Pronta entrega' ?>
              </span>
              <div class="pdx-price-row price-values">
                <span class="pdx-price-now price-current"><?= $precoMinFmt ?></span>
              </div>
              <?php if ($maxParcelas > 1): ?>
              <span class="pdx-price-install price-installment">
                ou <?= $ultimaParcela['parcelas'] ?>x de <strong><?= PriceHelper::format($ultimaParcela['valor_parcela']) ?></strong> sem juros
              </span>
              <?php endif; ?>
            <?php endif; ?>
          </div><!-- /#price-range-wrapper -->

          <!-- Formas de pagamento — DE PROPÓSITO fora do #price-range-wrapper.
               O JS esconde o range ao selecionar a variação; enquanto o Pix
               morava lá dentro, o melhor preço da página e o link de formas de
               pagamento desapareciam exatamente no momento em que o cliente
               escolhia o tamanho. O valor é recalculado por SKU em
               aplicarSkuNaUI() usando PV.pix_pct. -->
          <div class="pdx-price-methods" id="pdx-price-methods">
            <div class="pdx-price-pix">
              <svg viewBox="0 0 24 24" fill="none" stroke="#0a8f5b" stroke-width="2" stroke-linecap="round"><path d="M12 2l3 3-3 3-3-3 3-3zM5 9l3 3-3 3-3-3 3-3zM19 9l3 3-3 3-3-3 3-3zM12 16l3 3-3 3-3-3 3-3z"/></svg>
              <span><strong id="pdx-pix-valor"><?= PriceHelper::format($precoPix) ?></strong> à vista no Pix (<?= (int)$pixPct ?>% off)</span>
            </div>
            <button type="button" class="pdx-pay-link" id="pdx-open-pay">
              <svg viewBox="0 0 24 24" fill="none"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
              Ver todas as formas de pagamento
            </button>
          </div>
        </div><!-- /pdx-price -->

        <!-- Regras comerciais para o JS. Emitido SEMPRE (o bloco window.PV das
             variações só existe em produto com variação, e o handler de compra
             lê PV mesmo sem elas). O parcelamento vem do ConfigHelper, as mesmas
             chaves que PriceHelper::installments() usa — antes o JS tinha os
             próprios MAX_PARCELAS=10 e mínimo 10,00 e contradizia o PHP. -->
        <script>
          window.PV = Object.assign(window.PV || {}, {
            preco_num : <?= json_encode(round((float)$preco, 2)) ?>,
            pix_pct   : <?= (int)$pixPct ?>,
            parcelas  : {
              max       : <?= (int)   ConfigHelper::get('parcelas_max', 12) ?>,
              min_valor : <?= json_encode((float) ConfigHelper::get('parcelas_min_valor', 30.00)) ?>,
              juros     : <?= json_encode((float) ConfigHelper::get('parcelas_juros', 0)) ?>
            }
          });
        </script>

        <!-- Cálculo de frete (partial com JS próprio — #fpFrete) -->
        <?php include __DIR__ . '/../partials/frete-produto.php'; ?>

        <!-- ═══ VARIAÇÕES ═══ -->
        <?php
          $variation = new ProductVariation();
          $vdata     = $variation->getProductData((int)$product['id']);
        ?>
        <?php if (!empty($vdata)): ?>
          <div class="product-variations" id="product-variations">

            <!-- Atributos agrupadores (navegação entre produtos da família) -->
            <?php foreach ($vdata['atributos_agrupadores'] as $atr): ?>
            <div class="variation-group variation-group--agrupador">
              <div class="variation-label">
                <?= View::e($atr['nome']) ?>:
                <strong class="variation-valor-atual"><?= View::e($atr['valor']) ?></strong>
              </div>
              <div class="variation-opcoes">
                <?php foreach ($vdata['produtos_familia'] as $membro): ?>
                <?php
                  $valorMembro = $membro['agrupadores'][$atr['slug']] ?? null;
                  if (!$valorMembro) continue;
                  $isAtual          = $membro['atual'];
                  $membroSemEstoque = $membro['sem_estoque']; // BUG-2: NÃO sobrescreve $semEstoque do produto
                  $tipo             = swatchTipo($membro, $atr['slug']);
                ?>
                <a href="<?= BASE_URL ?>/produto/<?= View::e($membro['slug']) ?>"
                  class="variation-swatch variation-swatch--agrupador <?= $tipo ?>
                          <?= $isAtual          ? 'active'      : '' ?>
                          <?= $membroSemEstoque ? 'sem-estoque' : '' ?>"
                  data-valor="<?= View::e($valorMembro) ?>"
                  data-produto-id="<?= (int)$membro['id'] ?>"
                  title="<?= View::e($valorMembro) ?><?= $membroSemEstoque ? ' — Sem estoque' : '' ?>"
                  <?= $isAtual ? 'aria-current="true"' : '' ?>>
                  <?php renderSwatch($membro, $atr['slug']) ?>
                </a>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endforeach; ?>

            <!-- Atributos de variação (seleção de SKU) -->
            <?php foreach ($vdata['tipos_variacao'] as $tipo): ?>
            <div class="variation-group variation-group--variacao" data-tipo="<?= View::e($tipo['slug']) ?>">
              <div class="variation-label">
                <?= View::e($tipo['nome']) ?>
                <?php if ($tipo['unidade']): ?><small>(<?= View::e($tipo['unidade']) ?>)</small><?php endif; ?>
                : <strong class="variation-valor-atual" id="label-<?= View::e($tipo['slug']) ?>">Selecione</strong>
              </div>
              <div class="variation-opcoes">
                <?php foreach ($tipo['valores'] as $v): ?>
                <button type="button"
                        class="variation-swatch variation-swatch--variacao <?= !$v['tem_estoque'] ? 'sem-estoque' : '' ?>"
                        data-tipo="<?= View::e($tipo['slug']) ?>"
                        data-valor="<?= View::e($v['valor']) ?>"
                        <?= !$v['tem_estoque'] ? 'disabled title="Sem estoque"' : '' ?>>
                  <?= View::e($v['valor']) ?>
                </button>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endforeach; ?>

            <!-- BUG (ID duplicado) corrigido: o #sku-preco-wrapper das variações foi
                 REMOVIDO. O preço do SKU é exibido no painel de preço acima (canônico). -->

            <div id="variacao-aviso" class="variacao-aviso" style="display:none;">
              Selecione todas as opções antes de adicionar ao carrinho.
            </div>
          </div>

          <!-- Dados serializados para o JS (window.PV) — VERBATIM.
               Object.assign e não atribuição: as regras comerciais emitidas
               junto ao painel de preço já estão em window.PV e não podem ser
               sobrescritas aqui. -->
          <script>
            window.PV = Object.assign(window.PV || {}, {
              produto_id   : <?= (int)$product['id'] ?>,
              produto_slug : <?= json_encode($product['slug']) ?>,
              tipos_slug   : <?= json_encode($vdata['tipos_slug']  ?? [], JSON_UNESCAPED_UNICODE) ?>,
              matriz       : <?= json_encode($vdata['matriz_skus'] ?? [], JSON_UNESCAPED_UNICODE) ?>,
              chave_map    : <?= json_encode(
                  array_reduce(
                      $vdata['skus'] ?? [],
                      function ($carry, $sku) use ($vdata) {
                          $identifier = !empty($sku['id_legado'])
                                        ? $sku['id_legado']
                                        : (string)$sku['sku_id'];
                          $partes = [];
                          foreach ($vdata['tipos_slug'] ?? [] as $slug) {
                              $partes[] = $sku['atributos'][$slug] ?? '';
                          }
                          $chave = implode('|', $partes);
                          if ($chave) $carry[$chave] = $identifier;
                          return $carry;
                      }, []
                  ), JSON_UNESCAPED_UNICODE
              ) ?>,
              legado_map   : <?= json_encode(
                  array_reduce(
                      $vdata['skus'] ?? [],
                      function ($carry, $sku) use ($vdata) {
                          $identifier = !empty($sku['id_legado'])
                                        ? $sku['id_legado']
                                        : (string)$sku['sku_id'];
                          $partes = [];
                          foreach ($vdata['tipos_slug'] ?? [] as $slug) {
                              $partes[] = $sku['atributos'][$slug] ?? '';
                          }
                          $chave = implode('|', $partes);
                          if ($chave) $carry[$identifier] = $chave;
                          return $carry;
                      }, []
                  ), JSON_UNESCAPED_UNICODE
              ) ?>,
              variant_pre  : <?= json_encode($skuPreSelecionado ? [
                  'id_legado'  => $skuPreSelecionado['id_legado'] ?? null,
                  'atributos'  => $skuPreSelecionado['atributos'],
                  'preco_fmt'  => $skuPreSelecionado['preco_fmt'],
                  'estoque'    => $skuPreSelecionado['estoque'],
                  'sem_estoque'=> $skuPreSelecionado['estoque'] === 0,
              ] : null, JSON_UNESCAPED_UNICODE) ?>,
              tem_range    : <?= json_encode($vdata['tem_range_preco'] ?? false) ?>,
              preco_min_fmt: <?= json_encode($vdata['preco_min_fmt']   ?? '') ?>,
              preco_max_fmt: <?= json_encode($vdata['preco_max_fmt']   ?? '') ?>,
              preco   : <?= json_encode(PriceHelper::format((float)$product['preco'])) ?>
            });
          </script>
        <?php endif; ?>

        <!-- ═══ QUANTIDADE + ESTOQUE (hooks) ═══ -->
        <div class="product-qty-stock pdx-buy-row">
          <div class="qty-control">
            <button type="button" class="qty-btn" id="qty-minus" aria-label="Diminuir">-</button>
            <input type="number" id="product-qty" class="qty-input" value="1" min="1" max="<?= (int)$product['estoque_total'] ?>" readonly>
            <button type="button" class="qty-btn" id="qty-plus" aria-label="Aumentar">+</button>
          </div>
          <div class="stock-info" id="stock-info">
            <?php if ($semEstoque): ?>
              <span class="stock-badge stock-badge--out">Esgotado</span>
            <?php elseif ((int)$product['estoque_total'] <= 5): ?>
              <span class="stock-badge stock-badge--low">Últimas <?= (int)$product['estoque_total'] ?> unidades</span>
            <?php else: ?>
              <span class="stock-badge stock-badge--in">Em estoque</span>
            <?php endif; ?>
          </div>
        </div>

        <!-- ═══ AÇÕES (hooks: #btn-buynow / .btn-add-cart-detail / #aviso-*) ═══ -->
        <div class="product-actions" data-pro-name="<?= $product['nome'] ?>">
          <?php if (!$semEstoque): ?>
          <!-- O rótulo mora num .pdx-btn-label próprio: o JS troca o texto pelo
               span, nunca por .text() no botão — que apagava o ícone SVG junto. -->
          <button type="button" class="btn btn-primary btn-buynow" id="btn-buynow" data-product-id="<?= (int)$product['id'] ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>
            <span class="pdx-btn-label">Comprar agora</span>
          </button>
          <button type="button" class="btn btn-outline btn-add-cart-detail" data-product-id="<?= (int)$product['id'] ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/></svg>
            <span class="pdx-btn-label">Adicionar ao carrinho</span>
          </button>
          <?php else: ?>
          <button class="btn btn-soldout btn-full" disabled>Produto esgotado</button>
          <div id="aviso-estoque-box" class="aviso-estoque" style="display:none;">
            <p class="aviso-estoque__titulo">Produto esgotado</p>
            <p class="aviso-estoque__sub">Avisamos você assim que voltar ao estoque.</p>
            <div class="aviso-estoque__form">
              <input type="email" id="aviso-email" placeholder="Seu melhor email" class="aviso-estoque__input">
              <button type="button" id="aviso-btn" class="aviso-estoque__btn">Avise-me</button>
            </div>
            <p id="aviso-msg" class="aviso-estoque__msg" style="display:none;"></p>
          </div>
          <?php endif; ?>
          <?php if ($product['estoque_total'] <= 0): ?>
            <script>window.AVISO_PRODUTO_ID = <?= (int)$product['id'] ?>;</script>
            <?php if (!empty($clienteLogado)): ?>
              <script>window.AVISO_EMAIL = '<?= htmlspecialchars($clienteData['email']) ?>';</script>
            <?php endif; ?>
          <?php endif; ?>
        </div>

        <!-- ═══ WISHLIST (hooks preservados) ═══ -->
        <div class="product-actions-wishlist">
          <div class="wishlist-btn-wrap" id="wishlist-btn-wrap">
            <button type="button"
                    class="btn-wishlist-main <?= !empty(array_filter($listasProduto, fn($l) => $l['tem_produto'] && !$l['padrao'])) ? 'wishlist-btn--ativa' : '' ?>"
                    id="btn-wishlist-toggle"
                    data-product-id="<?= (int)$product['id'] ?>">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M19 21l-7-5-7 5V5a2 2 0 012-2h10a2 2 0 012 2z"/></svg>
              <span id="wishlist-btn-label">Salvar em lista</span>
              <svg class="wishlist-chevron" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
            </button>

            <div class="wishlist-dropdown" id="wishlist-dropdown" style="display:none;">
              <div class="wishlist-dropdown-header">
                <span>Salvar em...</span>
                <button type="button" class="wishlist-dropdown-close" id="btn-wishlist-close">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
              </div>

              <div class="wishlist-listas" id="wishlist-listas">
                <?php if (!Session::isClienteLogado()): ?>
                <div class="wishlist-login-aviso">
                  <p>Faça login para salvar em listas.</p>
                  <a href="<?= BASE_URL ?>/login?redirect=<?= urlencode($_SERVER['REQUEST_URI']) ?>" class="btn btn-primary btn-sm btn-full">Fazer login</a>
                </div>
                <?php elseif (empty($listasProduto)): ?>
                <p class="wishlist-vazia">Nenhuma lista ainda.</p>
                <?php else: ?>
                <?php foreach ($listasProduto as $lista): ?>
                <label class="wishlist-lista-item <?= $lista['tem_produto'] ? 'wishlist-lista-item--ativa' : '' ?>" data-lista-id="<?= (int)$lista['id'] ?>">
                  <input type="checkbox" class="wishlist-lista-check" data-lista-id="<?= (int)$lista['id'] ?>" <?= $lista['tem_produto'] ? 'checked' : '' ?>>
                  <span class="wishlist-check-custom"></span>
                  <span class="wishlist-lista-nome"><?= View::e($lista['nome']) ?></span>
                  <?php if ($lista['tem_produto']): ?><span class="wishlist-lista-badge">Salvo</span><?php endif; ?>
                </label>
                <?php endforeach; ?>
                <?php endif; ?>
              </div>

              <?php if (Session::isClienteLogado()): ?>
              <div class="wishlist-nova">
                <button type="button" class="wishlist-nova-btn" id="btn-nova-lista">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                  Criar nova lista
                </button>
                <div class="wishlist-nova-form" id="wishlist-nova-form" style="display:none;">
                  <input type="text" id="wishlist-nova-nome" class="form-control form-control--sm" placeholder="Nome da lista" maxlength="100">
                  <div class="wishlist-nova-actions">
                    <button type="button" class="btn btn-primary btn-sm" id="btn-nova-lista-salvar">Criar e salvar</button>
                    <button type="button" class="btn btn-ghost btn-sm" id="btn-nova-lista-cancelar">Cancelar</button>
                  </div>
                </div>
              </div>
              <?php endif; ?>
            </div><!-- /.wishlist-dropdown -->
          </div><!-- /.wishlist-btn-wrap -->
        </div><!-- /.product-actions-wishlist -->

        <?php if (Session::isClienteLogado()): ?>
        <script>
        window.WISHLIST_LISTAS = <?= json_encode(
            array_values(array_filter($listasProduto, fn($l) => !$l['padrao'])),
            JSON_UNESCAPED_UNICODE
        ) ?>;
        </script>
        <?php endif; ?>

        <!-- ═══ CONFIANÇA (ex-quick-info, redesenhado) ═══ -->
        <div class="pdx-trust">
          <div class="pdx-trust-item">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="1.7" stroke-linecap="round"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h5l2 5v3h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
            <span class="pdx-trust-t">Entrega Brasil</span>
            <span class="pdx-trust-s">para todo o país</span>
          </div>
          <div class="pdx-trust-item">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="1.7" stroke-linecap="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>
            <span class="pdx-trust-t">Compra segura</span>
            <span class="pdx-trust-s">ambiente protegido</span>
          </div>
          <div class="pdx-trust-item">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="1.7" stroke-linecap="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/></svg>
            <span class="pdx-trust-t">Troca em 30 dias</span>
            <span class="pdx-trust-s">devolução fácil</span>
          </div>
        </div>

      </div><!-- /product-info -->
    </div><!-- /product-detail-layout -->
  </div><!-- /container -->
</div><!-- /product-page -->

<?php
  // Carrossel de recomendação (aleatório) — mantido
  $viewSection = [
    $produtos_destaque,
    $produtos_promocao,
    $sectionPorFavoritos,
    $sectionPorCategorias,
    $sectionPorBuscas,
    $sectionPorClips,
    $sectionPorMarcas
  ];
  $viewSection = $viewSection[array_rand($viewSection)];
  View::partial('partials/home-sections', ['sections' => $viewSection]);
?>

<div class="pdx">
<div class="container">

  <!-- ═══ DESCRIÇÃO (sem tabs, com "ver mais") ═══ -->
  <section class="pdx-section" id="pdx-descricao">
    <h2 class="pdx-section-title">Descrição</h2>
    <div class="pdx-prose">
      <div class="pdx-collapse" id="pdx-desc-collapse">
        <div class="product-description rich-text">
          <?= $product['descricao'] ?? '<p>Produto sem descrição.</p>' ?>
        </div>
        <div class="pdx-collapse-fade"></div>
      </div>
      <button class="pdx-more" id="pdx-desc-more" type="button">Ver descrição completa<svg viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg></button>
    </div>
  </section>

  <!-- ═══ ESPECIFICAÇÕES (Características) ═══ -->
  <?php if (!empty($caracteristicas)): ?>
  <section class="pdx-section product-specs-section">
    <h2 class="pdx-section-title">Especificações</h2>
    <div class="pdx-specs">
      <?php foreach ($caracteristicas as $car): ?>
      <div class="pdx-spec">
        <span class="pdx-spec-k"><?= View::e($car['nome']) ?></span>
        <span class="pdx-spec-v">
          <?php if ($car['tipo'] === 'boolean'): ?>
            <?php $valBool = mb_strtolower(trim($car['valor'])); ?>
            <span class="product-spec-bool <?= in_array($valBool, ['sim', '1', 'true']) ? 'is-yes' : 'is-no' ?>">
              <?= in_array($valBool, ['sim', '1', 'true']) ? 'Sim' : 'Não' ?>
            </span>
          <?php elseif ($car['tipo'] === 'url'): ?>
            <a href="<?= View::e($car['valor']) ?>" target="_blank" rel="noopener nofollow"><?= View::e($car['valor']) ?></a>
          <?php else: ?>
            <?= View::e($car['valor']) ?>
            <?php if (!empty($car['unidade'])): ?><span class="product-spec-unit"><?= View::e($car['unidade']) ?></span><?php endif; ?>
          <?php endif; ?>
        </span>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- ═══ PERGUNTAS / RESUMO IA / AVALIAÇÕES (partials) ═══ -->
  <?php View::partial('partials/product-questions', ['produto_id' => $product['id']]) ?>
  <?php View::partial('partials/_review-summary-ia', ['produto_id' => $product['id']]) ?>
  <?php View::partial('partials/product-reviews', ['produto_id' => $product['id']]) ?>

  <!-- ═══ RELACIONADOS ═══ -->
  <?php if (!empty($related)): ?>
  <section class="related-products section">
    <div class="section-header"><h2 class="section-title">Você também pode gostar</h2></div>
    <div class="products-grid products-grid--4">
      <?php foreach ($related as $rel): ?>
        <?php View::partial('partials/product-card', ['product' => $rel]) ?>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

</div><!-- /container -->
</div><!-- /pdx -->

<?php
  View::partial('partials/home-sections', ['sections' => $sectionPorHistorico]);
  View::partial('partials/home-sections', ['sections' => $sectionPorCarrinho]);
?>

<!-- ═══ MODAL DE PAGAMENTO ═══ -->
<div class="pdx-modal" id="pdx-pay-modal">
  <div class="pdx-modal-back" id="pdx-pay-back"></div>
  <div class="pdx-modal-card">
    <div class="pdx-modal-head">
      <span class="pdx-modal-title">Formas de pagamento</span>
      <button class="pdx-modal-x" id="pdx-pay-x" type="button" aria-label="Fechar"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div class="pdx-modal-body">

      <!-- Pix -->
      <div class="pdx-pay-m">
        <div class="pdx-pay-m-head">
          <span class="pdx-pay-ic pdx-pay-ic--pix"><svg viewBox="0 0 24 24"><path d="M12 2l3 3-3 3-3-3 3-3zM5 9l3 3-3 3-3-3 3-3zM19 9l3 3-3 3-3-3 3-3zM12 16l3 3-3 3-3-3 3-3z"/></svg></span>
          <div><div class="pdx-pay-m-t">Pix <span class="pdx-pay-tag"><?= (int)$pixPct ?>% OFF</span></div><div class="pdx-pay-m-s">Aprovação na hora</div></div>
        </div>
        <div class="pdx-pay-hi"><?= PriceHelper::format($precoPix) ?><small>economize <?= PriceHelper::format($preco - $precoPix) ?></small></div>
        <p class="pdx-pay-m-desc">Na finalização você recebe o QR Code e o código copia-e-cola. O pagamento cai em segundos e o pedido é liberado automaticamente, sem esperar compensação.</p>
      </div>

      <!-- Cartão -->
      <div class="pdx-pay-m">
        <div class="pdx-pay-m-head">
          <span class="pdx-pay-ic pdx-pay-ic--card"><svg viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg></span>
          <div><div class="pdx-pay-m-t">Cartão de crédito</div><div class="pdx-pay-m-s">Em até <?= $maxParcelas ?: 12 ?>x sem juros</div></div>
        </div>
        <?php if (!empty($parcelas)): ?>
        <div class="pdx-pay-table">
          <?php foreach ($parcelas as $p): ?>
          <div class="pdx-pay-tr">
            <span class="pdx-pay-tr-k"><?= (int)$p['parcelas'] ?>x</span>
            <span class="pdx-pay-tr-v"><?= PriceHelper::format($p['valor_parcela']) ?><?php if ((int)$p['parcelas'] === $maxParcelas): ?><span class="pdx-pay-free">sem juros</span><?php endif; ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="pdx-pay-brands">
          <span class="pdx-pay-brand">VISA</span><span class="pdx-pay-brand">MASTERCARD</span><span class="pdx-pay-brand">ELO</span><span class="pdx-pay-brand">AMEX</span><span class="pdx-pay-brand">HIPERCARD</span><span class="pdx-pay-brand">DINERS</span>
        </div>
      </div>

      <!-- Boleto -->
      <div class="pdx-pay-m">
        <div class="pdx-pay-m-head">
          <span class="pdx-pay-ic pdx-pay-ic--boleto"><svg viewBox="0 0 24 24"><line x1="3" y1="5" x2="3" y2="19"/><line x1="7" y1="5" x2="7" y2="19"/><line x1="10" y1="5" x2="10" y2="19"/><line x1="14" y1="5" x2="14" y2="19"/><line x1="18" y1="5" x2="18" y2="19"/><line x1="21" y1="5" x2="21" y2="19"/></svg></span>
          <div><div class="pdx-pay-m-t">Boleto bancário <span class="pdx-pay-tag">3% OFF</span></div><div class="pdx-pay-m-s">Compensa em 1 a 2 dias úteis</div></div>
        </div>
        <div class="pdx-pay-hi"><?= PriceHelper::format($precoBoleto) ?><small>economize <?= PriceHelper::format($preco - $precoBoleto) ?></small></div>
        <p class="pdx-pay-m-desc">O boleto vence em 3 dias úteis. O pedido é separado e enviado só depois que o pagamento é confirmado pelo banco, então some 1 a 2 dias úteis ao prazo de entrega.</p>
      </div>

      <div class="pdx-pay-note">
        <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        Todos os pagamentos são processados em ambiente criptografado. A SportMoto não armazena os dados do seu cartão.
      </div>

    </div>
  </div>
</div>

<!-- ═══ LIGHTBOX FULLSCREEN (todas as fotos) ═══ -->
<?php if (!empty($images)): ?>
<div class="pdx-lb" id="pdx-lb" data-count="<?= count($images) ?>">
  <div class="pdx-lb-top">
    <span class="pdx-lb-count" id="pdx-lb-count">1 / <?= count($images) ?></span>
    <button class="pdx-lb-close" id="pdx-lb-close" type="button" aria-label="Fechar"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
  </div>
  <div class="pdx-lb-main">
    <button class="pdx-lb-nav pdx-lb-nav--prev" id="pdx-lb-prev" type="button" aria-label="Anterior"><svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg></button>
    <img class="pdx-lb-img" id="pdx-lb-img" src="" alt="">
    <button class="pdx-lb-nav pdx-lb-nav--next" id="pdx-lb-next" type="button" aria-label="Próxima"><svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg></button>
  </div>
  <div class="pdx-lb-strip" id="pdx-lb-strip">
    <?php foreach ($images as $i => $img): ?>
    <button class="pdx-lb-thumb <?= $i === 0 ? 'active' : '' ?>" type="button" data-index="<?= $i ?>">
      <img src="<?= View::uploadR2($img['arquivo']) ?>" alt="" loading="lazy">
    </button>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Lista de imagens (fullsize) para o lightbox -->
<script>
window.PDX_IMAGES = <?= json_encode(array_map(function($im){
  return View::uploadR2($im['arquivo']);
}, $images ?? []), JSON_UNESCAPED_SLASHES) ?>;

<?php if ($cepInfoData['tem_cep']): ?>
    window.EC_CEP_ATIVO = "<?= View::e($cepInfoData['cep']) ?>";
    <?php endif; ?>
</script>



<!-- ═══ JS do redesign (jQuery) — lightbox, modal pagamento, ver-mais ═══ -->
<!-- NÃO duplica o product.js: variações/cart/galeria-thumbs continuam nele. -->


<style>
/* Specs — cores dos badges booleanos (herda do design antigo, mantido) */
.pdx .product-spec-bool { font-size: 11.5px; font-weight: 700; padding: 2px 9px; border-radius: 99px; }
.pdx .product-spec-bool.is-yes { background: #dcfce7; color: #16a34a; }
.pdx .product-spec-bool.is-no  { background: #fee2e2; color: #dc2626; }
.pdx .product-spec-unit { font-weight: 400; color: #94a3b8; margin-left: 3px; }
</style>