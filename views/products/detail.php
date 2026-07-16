<?php
// views/products/detail.php — Página de produto completa
$preco     = PriceHelper::currentPrice($product);
$precoOrig = (float) $product['preco'];
$temPromo  = $preco < $precoOrig;
$descPct   = $temPromo ? PriceHelper::discountPercent($precoOrig, $preco) : 0;
$semEstoque = (int)($product['estoque_total']) === 0;
$mainImage  = !empty($images) ? $images[0] : null;

// Adicionar no topo do detail.php, após carregar $product

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


    // Verifica se está nos favoritos (lista padrão)
    $favoritado = $wl->isProdutoFavorito($clienteId, (int)$product['id']);

    // Busca todas as listas para o dropdown "Adicionar à lista"
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

    // var_dump(json_encode($resultado));
}



?>
<?php
// Helper inline para renderizar o swatch correto
// Helper — coloque no TOPO do detail.php, antes de qualquer HTML
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

// Determina a classe do swatch para o CSS correto
function swatchTipo(array $membro, string $atributoSlug): string {
    if (!empty($membro['agrupadores_img'][$atributoSlug])) return 'swatch--img';
    if (!empty($membro['agrupadores_hex'][$atributoSlug])) return 'swatch--color';
    return 'swatch--text';
}
?>

<?= $jsonLd ?>

<!-- Breadcrumb -->
<nav class="breadcrumb-nav" aria-label="Você está em">
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
<?php
  if(CONF_BAR_VEICLE) {
    if(Product::temBuscaMoto((int)$product['id'])) {
        View::partial('partials/meu-veiculo-bar', ['produtosCompativeis' => $produtosCompativeis ?? []]);
    }    
  }
?>

<div class="product-page">
  <div class="container">
    <div class="product-detail-layout">

      <!-- ── Galeria ────────────────────────────────────── -->
      <div class="product-gallery" id="product-gallery">

        <!-- Imagem principal -->
        <div class="gallery-main">
          <div class="gallery-zoom-wrapper" id="zoom-wrapper">
            <img id="gallery-main-img"
                 src="<?= $mainImage ? View::e($mainImage['arquivo']) : View::asset('images/placeholder.jpg') ?>"
                 alt="<?= View::e($product['nome']) ?>"
                 class="gallery-main-img"
                 fetchpriority="high">
          </div>

          <!-- Selos na galeria -->
          <div class="product-badges product-badges--lg">
            <?php if ($temPromo && $descPct > 0): ?>
              <span class="badge badge--sale badge--lg">-<?= $descPct ?>%</span>
            <?php endif; ?>
            <?php if ($semEstoque): ?>
              <span class="badge badge--soldout badge--lg">Esgotado</span>
            <?php endif; ?>
          </div>

          <!-- Arrows da galeria mobile -->
          <?php if (count($images) > 1): ?>
          <button class="gallery-arrow gallery-arrow--prev" id="gallery-prev" aria-label="Imagem anterior">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
              <polyline points="15 18 9 12 15 6"/>
            </svg>
          </button>
          <button class="gallery-arrow gallery-arrow--next" id="gallery-next" aria-label="Próxima imagem">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
              <polyline points="9 18 15 12 9 6"/>
            </svg>
          </button>
          <?php endif; ?>
        </div>

        <!-- Miniaturas -->
        <?php if (count($images) > 1): ?>
        <div class="gallery-thumbs" id="gallery-thumbs">
          <?php foreach ($images as $i => $img): ?>
          <button class="gallery-thumb <?= $i === 0 ? 'active' : '' ?>"
                  data-src="<?= View::e($img['arquivo']) ?>"
                  data-index="<?= $i ?>"
                  aria-label="Imagem <?= $i + 1 ?>">
            <img src="<?= View::e($img['arquivo']) ?>"
                 alt="<?= View::e($img['alt_text'] ?? $product['nome']) ?>"
                 loading="lazy" width="72" height="72">
          </button>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Compartilhamento social -->
        <div class="product-share">
          <span class="share-label">Compartilhar:</span>
          <div class="share-buttons">
            <?php
            $shareUrl   = urlencode(BASE_URL . '/produto/' . $product['slug']);
            $shareTitle = urlencode($product['nome']);
            ?>
            <a href="https://wa.me/?text=<?= $shareTitle ?>%20<?= $shareUrl ?>"
               target="_blank" rel="noopener" class="share-btn share-btn--whatsapp" aria-label="WhatsApp">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413z"/>
              </svg>
            </a>
            <a href="https://www.facebook.com/sharer/sharer.php?u=<?= $shareUrl ?>"
               target="_blank" rel="noopener" class="share-btn share-btn--facebook" aria-label="Facebook">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
              </svg>
            </a>
            <button class="share-btn share-btn--copy" id="btn-copy-link"
                    data-url="<?= View::e(BASE_URL . '/produto/' . $product['slug']) ?>"
                    aria-label="Copiar link">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
                <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
              </svg>
            </button>
          </div>
        </div>

        <?php View::partial('partials/clips-product-stories', ['produto_id' => $product['id']]) ?>

      </div>

      <!-- ── Informações do produto ─────────────────────── -->
      <div class="product-info" id="product-info">

        <!-- Categoria e marca -->
        <div class="product-meta-top">
          <?php if (!empty($product['categoria_nome'])): ?>
          <a href="<?= BASE_URL ?>/categoria/<?= View::e($product['categoria_slug']) ?>"
             class="product-category-link">
            <?= View::e($product['categoria_nome']) ?>
          </a>
          <?php endif; ?>
          <?php if (!empty($product['marca_nome'])): ?>
          <span class="product-meta-sep">/</span>
          <a href="<?= BASE_URL ?>/marca/<?= View::e($product['marca_slug']) ?>"
             class="product-brand-link">
            <?= View::e($product['marca_nome']) ?>
          </a>
          <?php endif; ?>
        </div>

        <h1 class="product-title"><?= View::e($product['nome']) ?></h1>

        <?php 
        $resumo = (new Review())->getResumo((int)$product['id']);
        // var_dump($resumo);
        ?>

        <div class="product-rating-top">
            <?php if (!empty($resumo['total']) && (int)$resumo['total'] > 0): ?>
            <?php View::partial('partials/_rating-badge', [
                'media' => (float)$resumo['media'],
                'total' => (int)$resumo['total'],
                'size'  => 'md',
                'link'  => '#sm-reviews-section',
            ]) ?>
            <a href="#sm-reviews-section" class="product-rating-anchor">
                Ver todas as avaliações
            </a>
            <?php endif; ?>
        </div>

        <?php
        if(Product::temBuscaMoto((int)$product['id'])):
          // Adicionar após o título do produto
          $veiculoAtivo = $_SESSION['meu_veiculo'] ?? null;
          if ($veiculoAtivo):
              $svc          = new VeiculoService();
              $ehCompativel = $svc->isProdutoCompativel((int)$product['id']);
          ?>

          <div class="prod-compat-banner <?= $ehCompativel ? 'is-compat' : 'is-nc' ?>"
              id="prod-compat-banner">
            <?php if ($ehCompativel): ?>
            <div class="prod-compat-icon prod-compat-icon--ok">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                  stroke="white" stroke-width="3" stroke-linecap="round">
                <polyline points="20 6 9 17 4 12"/>
              </svg>
            </div>
            <div class="prod-compat-info">
              <strong>Compatível com sua moto</strong>
              <span><?= View::e($veiculoAtivo['label']) ?></span>
            </div>
            <?php else: ?>
            <div class="prod-compat-icon prod-compat-icon--nc">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                  stroke="white" stroke-width="2.5" stroke-linecap="round">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="8" x2="12" y2="12"/>
                <line x1="12" y1="16" x2="12.01" y2="16"/>
              </svg>
            </div>
            <div class="prod-compat-info">
              <strong>Compatibilidade não confirmada</strong>
              <span>
                Não encontramos compatibilidade para
                <em><?= View::e($veiculoAtivo['label']) ?></em>.
                <a href="#tab-compatibilidade" class="prod-compat-link">
                  Ver motos compatíveis
                </a>
              </span>
            </div>
            <?php endif; ?>

            <button type="button" class="prod-compat-trocar" id="prod-compat-trocar">
              Trocar moto
            </button>
          </div>
          <?php endif; ?>
        <?php endif; ?>

        <!-- SKU + avaliações -->
        <div class="product-meta-row">
          <span class="product-sku">SKU: <?= View::e($product['sku_legado']) ?></span>
          <?php if (!empty($reviewStats['total']) && (int)$reviewStats['total'] > 0): ?>
          <div class="product-rating-summary">
            <div class="rating-stars-sm">
              <?php for ($i = 1; $i <= 5; $i++): ?>
                <span class="star <?= $i <= round((float)$reviewStats['media']) ? 'star--filled' : '' ?>">★</span>
              <?php endfor; ?>
            </div>
            <a href="#reviews" class="rating-count-link">
              <?= View::e($reviewStats['media']) ?> (<?= (int)$reviewStats['total'] ?> avaliações)
            </a>
          </div>
          <?php endif; ?>

          <!-- Botão de favorito simples (coração) -->
          <button type="button"
                  class="btn-favorito btn-favorito--detail wishlist-control <?= $favoritado ? 'active' : '' ?>"
                  data-product-id="<?= (int)$product['id'] ?>" data-list-id="<?= $resultado_padrao_wish['id'] ?? '' ?>"
                  >
            <svg width="22" height="22" viewBox="0 0 24 24"
                fill="<?= $favoritado ? 'currentColor' : 'none' ?>"
                stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/>
            </svg>
          </button>
        </div>


        <?php
          $temRange       = $vdata['tem_range_preco'] ?? false;
          $precoMinFmt    = $vdata['preco_min_fmt']   ?? PriceHelper::format((float)$product['preco']);
          $precoMaxFmt    = $vdata['preco_max_fmt']   ?? null;
          $temPromo       = !empty($product['preco_promo']) && $product['preco_promo'] < $product['preco'];

          // Parcelamento do preço único (ou menor preço)
          $precoParcelar  = (float)($vdata['preco_min'] ?? $product['preco']);
          $parcelas       = PriceHelper::installments($precoParcelar);
          $arrParcelas    = $parcelas;
          $ultimaParcela  = end($arrParcelas);
          $maxParcelas    = $ultimaParcela ? (int)$ultimaParcela['parcelas'] : 0;
          ?>

          <div class="product-price-block" id="product-price-block">

            <!-- Preço do SKU selecionado (oculto até selecionar) -->
            <div id="sku-preco-wrapper" style="display:none;">
              <span class="price-label">Preço</span>
              <div class="price-values">
                <span class="price-original" id="sku-preco-original" style="display:none;"></span>
                <span class="price-current price-current--sale" id="sku-preco-valor"></span>
              </div>
              <span class="price-installment" id="sku-preco-parcela"></span>
            </div>

            <!-- Preço base / range (oculto após selecionar) -->
            <div id="price-range-wrapper">

              <?php if ($temPromo): ?>
              <!-- Produto com promoção fixa (sem variação de preço) -->
              <span class="price-label">Preço</span>
              <div class="price-values">
                <span class="price-original">
                  <?= PriceHelper::format((float)$product['preco']) ?>
                </span>
                <span class="price-current price-current--sale">
                  <?= PriceHelper::format((float)$product['preco_promo']) ?>
                </span>
              </div>
              <?php if ($maxParcelas > 1): ?>
              <span class="price-installment">
                ou <?= $ultimaParcela['parcelas'] ?>x de
                <?php
                // PriceHelper::format($ultimaParcela['valor']) 
                ?> sem juros
              </span>
              <?php endif; ?>

              <?php elseif ($temRange): ?>
              <!-- Range de preços entre variações -->
              <span class="price-label">A partir de</span>
              <div class="price-values">
                <span class="price-current"><?= $precoMinFmt ?></span>
              </div>
              <div class="price-range-detail">
                <span class="price-range-values">
                  <?= $precoMinFmt ?>
                  <span class="price-range-sep">até</span>
                  <?= $precoMaxFmt ?>
                </span>
              </div>
              <?php if ($maxParcelas > 1): ?>
              <!-- Só mostra quantas vezes, sem valor de parcela -->
              <span class="price-installment">
                em até <?= $maxParcelas ?>x sem juros
              </span>
              <?php endif; ?>

              <?php else: ?>
              <!-- Preço único -->
              <span class="price-label">Preço</span>
              <div class="price-values">
                <span class="price-current"><?= $precoMinFmt ?></span>
              </div>
              <?php if ($maxParcelas > 1): ?>
              <span class="price-installment">
                ou <?= $ultimaParcela['parcelas'] ?>x de
                <?= PriceHelper::format($ultimaParcela['valor_parcela']) ?> sem juros
              </span>
              <?php endif; ?>
              <?php endif; ?>

            </div>

          </div>

        <!-- Descrição curta -->
        <?php if (!empty($product['descricao_curta'])): ?>
        <div class="product-short-desc">
          <?= $product['descricao_curta'] ?>
        </div>
        <?php endif; ?>

        <!-- Variações -->
        <?php
          $variation = new ProductVariation();
          $vdata     = $variation->getProductData((int)$product['id']);
          ?>

          <?php if (!empty($vdata)): ?>
          <div class="product-variations" id="product-variations">

            <!-- ── Atributos agrupadores (navegação entre produtos) ── -->
            <!-- Loop dos agrupadores -->
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
                  $isAtual    = $membro['atual'];
                  $semEstoque = $membro['sem_estoque'];
                  $tipo       = swatchTipo($membro, $atr['slug']); // ← detecta o tipo
                ?>
                <a href="<?= BASE_URL ?>/produto/<?= View::e($membro['slug']) ?>"
                  class="variation-swatch variation-swatch--agrupador <?= $tipo ?>
                          <?= $isAtual    ? 'active'      : '' ?>
                          <?= $semEstoque ? 'sem-estoque' : '' ?>"
                  data-valor="<?= View::e($valorMembro) ?>"
                  data-produto-id="<?= (int)$membro['id'] ?>"
                  title="<?= View::e($valorMembro) ?><?= $semEstoque ? ' — Sem estoque' : '' ?>"
                  <?= $isAtual ? 'aria-current="true"' : '' ?>>

                  <?php renderSwatch($membro, $atr['slug']) ?> <!-- ← USO aqui -->

                </a>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endforeach; ?>

            <!-- ── Atributos de variação (seleção de SKU) ────────────── -->
            <?php foreach ($vdata['tipos_variacao'] as $tipo): ?>
            <div class="variation-group variation-group--variacao"
                data-tipo="<?= View::e($tipo['slug']) ?>">

              <div class="variation-label">
                <?= View::e($tipo['nome']) ?>
                <?php if ($tipo['unidade']): ?>
                  <small>(<?= View::e($tipo['unidade']) ?>)</small>
                <?php endif; ?>
                : <strong class="variation-valor-atual" id="label-<?= View::e($tipo['slug']) ?>">
                    Selecione
                  </strong>
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

            <!-- Preço do SKU selecionado -->
            <div id="sku-preco-wrapper" style="display:none;">
              <span class="sku-preco" id="sku-preco-valor"></span>
            </div>

            <!-- Aviso de seleção pendente -->
            <div id="variacao-aviso" class="variacao-aviso" style="display:none;">
              Selecione todas as opções antes de adicionar ao carrinho.
            </div>

          </div>

          <!-- Dados serializados para o JS -->
          <script>
            window.PV = {
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

              // Range de preços para restaurar quando desselecionar
              tem_range    : <?= json_encode($vdata['tem_range_preco'] ?? false) ?>,
              preco_min_fmt: <?= json_encode($vdata['preco_min_fmt']   ?? '') ?>,
              preco_max_fmt: <?= json_encode($vdata['preco_max_fmt']   ?? '') ?>,
              preco   : <?= json_encode(PriceHelper::format((float)$product['preco'])) ?>,
            };
            </script>
          <?php endif; ?>

        <!-- Quantidade e estoque -->
        <div class="product-qty-stock">
          <div class="qty-control">
            <button type="button" class="qty-btn" id="qty-minus" aria-label="Diminuir">-</button>
            <input type="number" id="product-qty" class="qty-input"
                   value="1" min="1" max="<?= (int)$product['estoque_total'] ?>" readonly>
            <button type="button" class="qty-btn" id="qty-plus" aria-label="Aumentar">+</button>
          </div>

          <div class="stock-info" id="stock-info">
            <?php if ($semEstoque): ?>
              <span class="stock-badge stock-badge--out">Esgotado</span>
            <?php elseif ((int)$product['estoque_total'] <= 5): ?>
              <span class="stock-badge stock-badge--low">
                Últimas <?= (int)$product['estoque_total'] ?> unidades
              </span>
            <?php else: ?>
              <span class="stock-badge stock-badge--in">Em estoque</span>
            <?php endif; ?>
          </div>
        </div>

        <!-- Botões de ação -->
        <div class="product-actions">
          <?php if (!$semEstoque): ?>
          <button type="button" class="btn btn-primary btn-buynow"
                  id="btn-buynow"
                  data-product-id="<?= (int)$product['id'] ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
              <path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/>
              <path d="M16 10a4 4 0 01-8 0"/>
            </svg>
            Comprar agora
          </button>
          <button type="button" class="btn btn-outline btn-add-cart-detail"
                  
                  data-product-id="<?= (int)$product['id'] ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
              <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
              <path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/>
            </svg>
            Adicionar ao carrinho
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
          <!-- <button type="button" class="btn-wishlist-detail btn-wishlist"
                  data-product-id="<?= (int)$product['id'] ?>" aria-label="Adicionar aos favoritos">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2">
              <path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/>
            </svg>
            <span>Adicionar aos favoritos</span>
          </button> -->
        </div>

        <!-- Substituir o btn-wishlist simples por: -->
        <div class="product-actions-wishlist">

  <!-- ── Botão favoritar (coração) → lista padrão ──────── -->
          <!-- <button type="button"
                  class="btn-favorito wishlist-control btn-favorito--detail <?= $favoritado ? 'active' : '' ?> hab-text"
                  data-product-id="<?= (int)$product['id'] ?>" data-list-id="<?= $resultado_padrao_wish['id'] ?? '' ?>"
                  aria-label="<?= $favoritado ? 'Remover dos favoritos' : 'Adicionar aos favoritos' ?>">
            <svg width="18" height="18" viewBox="0 0 24 24"
                fill="<?= $favoritado ? 'currentColor' : 'none' ?>"
                stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/>
            </svg>
            <span class="btn-favorito-label">
              <?= $favoritado ? 'Nos favoritos' : 'Favoritar' ?>
            </span>
          </button> -->

          <!-- ── Botão "Adicionar à lista" → dropdown ──────────── -->
          <div class="wishlist-btn-wrap" id="wishlist-btn-wrap">
            <button type="button"
                    class="btn-wishlist-main <?= !empty(array_filter($listasProduto, fn($l) => $l['tem_produto'] && !$l['padrao'])) ? 'wishlist-btn--ativa' : '' ?>"
                    id="btn-wishlist-toggle"
                    data-product-id="<?= (int)$product['id'] ?>">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                  stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <path d="M19 21l-7-5-7 5V5a2 2 0 012-2h10a2 2 0 012 2z"/>
              </svg>
              <span id="wishlist-btn-label">Salvar em lista</span>
              <svg class="wishlist-chevron" width="13" height="13" viewBox="0 0 24 24"
                  fill="none" stroke="currentColor" stroke-width="2.5">
                <polyline points="6 9 12 15 18 9"/>
              </svg>
            </button>

            <!-- Dropdown -->
            <div class="wishlist-dropdown" id="wishlist-dropdown" style="display:none;">
              <div class="wishlist-dropdown-header">
                <span>Salvar em...</span>
                <button type="button" class="wishlist-dropdown-close" id="btn-wishlist-close">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                      stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <line x1="18" y1="6" x2="6"  y2="18"/>
                    <line x1="6"  y1="6" x2="18" y2="18"/>
                  </svg>
                </button>
              </div>

              <div class="wishlist-listas" id="wishlist-listas">
                <?php if (!Session::isClienteLogado()): ?>
                <!-- Não logado -->
                <div class="wishlist-login-aviso">
                  <p>Faça login para salvar em listas.</p>
                  <a href="<?= BASE_URL ?>/login?redirect=<?= urlencode($_SERVER['REQUEST_URI']) ?>"
                    class="btn btn-primary btn-sm btn-full">
                    Fazer login
                  </a>
                </div>
                <?php elseif (empty($listasProduto)): ?>
                <p class="wishlist-vazia">Nenhuma lista ainda.</p>
                <?php else: ?>
                <?php foreach ($listasProduto as $lista):
                  // Pula a lista padrão no dropdown (já controlada pelo coração)
                  // if ($lista['padrao']) continue;
                ?>
                <label class="wishlist-lista-item <?= $lista['tem_produto'] ? 'wishlist-lista-item--ativa' : '' ?>"
                      data-lista-id="<?= (int)$lista['id'] ?>">
                  <input type="checkbox"
                        class="wishlist-lista-check"
                        data-lista-id="<?= (int)$lista['id'] ?>"
                        <?= $lista['tem_produto'] ? 'checked' : '' ?>>
                  <span class="wishlist-check-custom"></span>
                  <span class="wishlist-lista-nome"><?= View::e($lista['nome']) ?></span>
                  <?php if ($lista['tem_produto']): ?>
                  <span class="wishlist-lista-badge">Salvo</span>
                  <?php endif; ?>
                </label>
                <?php endforeach; ?>
                <?php endif; ?>
              </div>

              <?php if (Session::isClienteLogado()): ?>
              <!-- Criar nova lista -->
              <div class="wishlist-nova">
                <button type="button" class="wishlist-nova-btn" id="btn-nova-lista">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                      stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <line x1="12" y1="5" x2="12" y2="19"/>
                    <line x1="5"  y1="12" x2="19" y2="12"/>
                  </svg>
                  Criar nova lista
                </button>

                <div class="wishlist-nova-form" id="wishlist-nova-form" style="display:none;">
                  <input type="text" id="wishlist-nova-nome"
                        class="form-control form-control--sm"
                        placeholder="Nome da lista" maxlength="100">
                  <div class="wishlist-nova-actions">
                    <button type="button" class="btn btn-primary btn-sm"
                            id="btn-nova-lista-salvar">
                      Criar e salvar
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm"
                            id="btn-nova-lista-cancelar">
                      Cancelar
                    </button>
                  </div>
                </div>
              </div>
              <?php endif; ?>

            </div><!-- /.wishlist-dropdown -->
          </div><!-- /.wishlist-btn-wrap -->

        </div><!-- /.product-actions-wishlist -->

        <!-- Passa as listas para o JS (evita Ajax no carregamento) -->
        <?php if (Session::isClienteLogado()): ?>
        <script>
        window.WISHLIST_LISTAS = <?= json_encode(
            array_values(array_filter($listasProduto, fn($l) => !$l['padrao'])),
            JSON_UNESCAPED_UNICODE
        ) ?>;
        </script>
        <?php endif; ?>

        <!-- Cálculo de frete -->
        <div class="shipping-calc" id="shipping-calc">
          <h3 class="shipping-calc-title">Calcular frete e prazo</h3>
          <div class="shipping-form">
            <div class="shipping-input-wrap">
              <input type="text" id="shipping-cep" class="form-control cep-mask"
                     placeholder="Digite seu CEP" maxlength="9">
              <a href="https://buscacepinter.correios.com.br/app/endereco/index.php"
                 target="_blank" rel="noopener" class="cep-link">Não sei meu CEP</a>
            </div>
            <button type="button" class="btn btn-dark" id="btn-calc-shipping">Calcular</button>
          </div>
          <div class="shipping-results" id="shipping-results" style="display:none;"></div>
        </div>

        <!-- Informações adicionais rápidas -->
        <div class="product-quick-info">
          <div class="quick-info-item">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
            </svg>
            <span>Compra 100% segura</span>
          </div>
          <div class="quick-info-item">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <polyline points="23 4 23 10 17 10"/>
              <path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/>
            </svg>
            <span>Troca em até 30 dias</span>
          </div>
          <div class="quick-info-item">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <rect x="1" y="3" width="15" height="13" rx="1"/>
              <path d="M16 8h5l2 5v3h-7V8z"/>
              <circle cx="5.5" cy="18.5" r="2.5"/>
              <circle cx="18.5" cy="18.5" r="2.5"/>
            </svg>
            <span>Entrega para todo o Brasil</span>
          </div>
        </div>

      </div>
    </div>
</div>
    <?php 
      $viewSection = [
        $produtos_destaque, 
        $produtos_promocao, 
        // $sectionFavoritos, 
        $sectionPorFavoritos, 
        $sectionPorCategorias,
        $sectionPorBuscas, 
        $sectionPorClips, 
        $sectionPorMarcas
      ]; 
      $viewSection = $viewSection[array_rand($viewSection)];
      View::partial('partials/home-sections', ['sections' => $viewSection]);                
    ?>
<div class="container">
  <?php if (!empty($caracteristicas)): ?>
  <section class="product-specs-section">
    <h2 class="product-specs-title">Características</h2>
    <div class="product-specs-grid">
      <?php foreach ($caracteristicas as $car): ?>
      <div class="product-spec-item">
        <span class="product-spec-label"><?= View::e($car['nome']) ?></span>
        <span class="product-spec-value">
          <?php if ($car['tipo'] === 'boolean'): ?>
            <?php $valBool = mb_strtolower(trim($car['valor'])); ?>
            <span class="product-spec-bool <?= in_array($valBool, ['sim', '1', 'true']) ? 'is-yes' : 'is-no' ?>">
              <?= in_array($valBool, ['sim', '1', 'true']) ? 'Sim' : 'Não' ?>
            </span>
          <?php elseif ($car['tipo'] === 'url'): ?>
            <a href="<?= View::e($car['valor']) ?>" target="_blank" rel="noopener nofollow">
              <?= View::e($car['valor']) ?>
            </a>
          <?php else: ?>
            <?= View::e($car['valor']) ?>
            <?php if (!empty($car['unidade'])): ?>
            <span class="product-spec-unit"><?= View::e($car['unidade']) ?></span>
            <?php endif; ?>
          <?php endif; ?>
        </span>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>
    <!-- ── Abas: Descrição / Ficha / Avaliações ───────────── -->
    <div class="product-tabs-section" id="product-tabs">
      <div class="tabs-nav">
        <button class="tab-btn active" data-tab="descricao">Descrição</button>
        <?php if (!empty($product['ficha_tecnica'])): ?>
        <button class="tab-btn" data-tab="ficha">Ficha técnica</button>
        <?php endif; ?>
        <!-- <button class="tab-btn" data-tab="reviews" id="tab-reviews-btn">
          Avaliações (<?= (int)($reviewStats['total'] ?? 0) ?>)
        </button> -->
      </div>

      <!-- Descrição completa -->
      <div class="tab-panel active" id="tab-descricao">
        <div class="product-description rich-text">
          <?= $product['descricao'] ?? '<p>Produto sem descrição.</p>' ?>
        </div>
      </div>
      
      <?php View::partial('partials/product-questions', ['produto_id' => $product['id']]) ?>      
      <?php View::partial('partials/_review-summary-ia', ['produto_id' => $product['id']]) ?>
      <?php View::partial('partials/product-reviews', ['produto_id' => $product['id']])  ?>

      <!-- Ficha técnica -->
      <?php if (!empty($product['ficha_tecnica'])): ?>
      <div class="tab-panel" id="tab-ficha">
        <?php $ficha = is_string($product['ficha_tecnica'])
              ? json_decode($product['ficha_tecnica'], true) : $product['ficha_tecnica']; ?>
        <?php if (is_array($ficha)): ?>
        <table class="ficha-tecnica-table">
          <tbody>
            <?php foreach ($ficha as $attr => $val): ?>
            <tr>
              <th><?= View::e($attr) ?></th>
              <td><?= View::e($val) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      
    </div>
    
    <!-- ── Produtos relacionados ──────────────────────────── -->
    <?php if (!empty($related)): ?>
    <section class="related-products section">
      <div class="section-header">
        <h2 class="section-title">Você também pode gostar</h2>
      </div>
      <div class="products-grid products-grid--4">
        <?php foreach ($related as $rel): ?>
          <?php View::partial('partials/product-card', ['product' => $rel]) ?>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>    
  </div>
</div>

<?php 
         View::partial('partials/home-sections', ['sections' => $sectionPorHistorico]);
         View::partial('partials/home-sections', ['sections' => $sectionPorCarrinho]);        
    ?>

    <style>
.product-specs-section {
  margin: 28px 0;
  padding: 20px 0;
  border-top: 1px solid var(--c-border, #eef0f3);
}
.product-specs-title {
  font-size: 16px; font-weight: 800;
  margin: 0 0 16px;
  color: var(--c-heading, #1e293b);
}
.product-specs-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 10px 24px;
}
@media (max-width: 600px) {
  .product-specs-grid { grid-template-columns: 1fr; }
}
.product-spec-item {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: 12px;
  padding: 10px 0;
  border-bottom: 1px dashed var(--c-border, #eef0f3);
  font-size: 13.5px;
}
.product-spec-label {
  color: #64748b;
  flex-shrink: 0;
}
.product-spec-value {
  font-weight: 600;
  color: var(--c-heading, #1e293b);
  text-align: right;
}
.product-spec-unit {
  font-weight: 400;
  color: #94a3b8;
  margin-left: 3px;
}
.product-spec-bool {
  font-size: 11.5px; font-weight: 700;
  padding: 2px 9px; border-radius: 99px;
}
.product-spec-bool.is-yes { background: #dcfce7; color: #16a34a; }
.product-spec-bool.is-no  { background: #fee2e2; color: #dc2626; }
</style>