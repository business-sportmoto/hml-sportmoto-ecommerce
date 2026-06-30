<?php
// views/customer/history.php
$tipoLabels = [
    'produto'   => ['label' => 'Produto',   'cor' => 'blue'],
    'categoria' => ['label' => 'Categoria', 'cor' => 'purple'],
    'busca'     => ['label' => 'Busca',     'cor' => 'amber'],
    'marca'     => ['label' => 'Marcas',     'cor' => 'pink'],
    'clip'      => ['label' => 'Clips',      'cor' => 'orange'],
];
?>
<div class="customer-page">
  <div class="customer-page-header">
    <div>
      <h1>Meu histórico</h1>
      <p class="customer-page-sub"><?= number_format($total) ?> itens registrados</p>
    </div>
    <?php if ($total > 0): ?>
    <button type="button" class="btn btn-outline btn-sm" id="btn-clear-history">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2" stroke-linecap="round">
        <polyline points="3 6 5 6 21 6"/>
        <path d="M19 6l-1 14H6L5 6"/>
      </svg>
      Apagar histórico
    </button>
    <?php endif; ?>
  </div>

  <?php if ($total === 0): ?>
  <div class="empty-state">
    <svg width="52" height="52" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="1.2" stroke-linecap="round">
      <circle cx="12" cy="12" r="10"/>
      <polyline points="12 6 12 12 16 14"/>
    </svg>
    <p>Você ainda não tem histórico de navegação.</p>
    <a href="<?= BASE_URL ?>/busca" class="btn btn-primary">Explorar produtos</a>
  </div>

  <?php else: ?>

  <div class="history-layout">
    <div class="history-main">

      <!-- Lista do histórico -->
      <div class="customer-section" id="history-list-section">
        <h2>Navegação recente</h2>
        <div class="history-list" id="history-list">
          <?php
          $diaAtual = null;
          foreach ($history as $item):
            $dia = date('d/m/Y', strtotime($item['criado_em']));
            $tipo = $tipoLabels[$item['tipo']] ?? ['label' => $item['tipo'], 'cor' => 'gray'];
          ?>

          <?php if ($dia !== $diaAtual): ?>
            <?php $diaAtual = $dia; ?>
            <div class="history-date-divider">
              <span>
                <?= $dia === date('d/m/Y') ? 'Hoje' :
                    ($dia === date('d/m/Y', strtotime('-1 day')) ? 'Ontem' : $dia) ?>
              </span>
            </div>
          <?php endif; ?>

          <div class="history-item">

            <!-- Ícone do tipo -->
            <div class="history-item-icon history-item-icon--<?= $tipo['cor'] ?>">
              <?php if ($item['tipo'] === 'produto'): ?>
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/>
              </svg>
              <?php elseif ($item['tipo'] === 'clip'): ?>
              <?= IconLibrary::render('video-template', 'icon icon--md') ?>
              <?php elseif ($item['tipo'] === 'categoria'): ?>
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/>
              </svg>
              <?php else: ?>
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <circle cx="11" cy="11" r="8"/>
                <path d="m21 21-4.35-4.35"/>
              </svg>
              <?php endif; ?>
            </div>

            <!-- Imagem do produto (se houver) -->
            <!-- Thumb: produto tem imagem, marca tem logo com bg_cor, categoria sem imagem -->
            <?php if ($item['tipo'] === 'produto' && !empty($item['produto_img'])): ?>
            <div class="history-item-thumb">
              <img src="<?= View::upload('products/' . $item['produto_img']) ?>"
                  alt="<?= View::e($item['produto_nome']) ?>"
                  width="48" height="48" loading="lazy">
            </div>

            <?php elseif ($item['tipo'] === 'clip' && !empty($item['clip_poster'])): 
              $item['clip_poster'] = $item['clip_poster'] ? View::upload('clips/posters/' . $item['clip_poster']) : null;  

              if($item['clip_poster']){
            ?>
            <div class="history-item-thumb">
              <img src="<?=  $item['clip_poster']; ?>"
                  alt="<?= View::e($item['clip_nome']) ?>"
                  width="48" height="48" loading="lazy">
            </div>
            <?php } ?>

            <?php elseif ($item['tipo'] === 'marca'): ?>
            <?php $bgCor = !empty($item['marca_bg_cor']) ? $item['marca_bg_cor'] : '#f1f5f9'; ?>
            <div class="history-item-thumb history-item-thumb--marca"
                style="background:<?= View::e($bgCor) ?>">
              <?php if (!empty($item['marca_logo'])): ?>
              <img src="<?= View::upload('brands/' . $item['marca_logo']) ?>"
                  alt="<?= View::e($item['marca_nome']) ?>"
                  width="48" height="48" loading="lazy"
                  style="object-fit:contain;padding:4px;">
              <?php else: ?>
              <span class="history-marca-initials">
                <?= View::e(mb_strtoupper(mb_substr($item['marca_nome'] ?? '', 0, 2))) ?>
              </span>
              <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Informações -->
            <div class="history-item-info">
              <?php if ($item['tipo'] === 'produto' && !empty($item['produto_slug'])): ?>
                <a href="<?= BASE_URL ?>/produto/<?= View::e($item['produto_slug']) ?>"
                   class="history-item-name">
                  <?= View::e($item['produto_nome']) ?>
                </a>
                <span class="history-item-type">
                  <span class="history-type-badge history-type-badge--<?= $tipo['cor'] ?>">
                    <?= $tipo['label'] ?>
                  </span>
                </span>

              <?php elseif ($item['tipo'] === 'categoria' && !empty($item['categoria_slug'])): ?>
                <a href="<?= BASE_URL ?>/categoria/<?= View::e($item['categoria_slug']) ?>"
                   class="history-item-name">
                  <?= View::e($item['categoria_nome']) ?>
                </a>
                <span class="history-item-type">
                  <span class="history-type-badge history-type-badge--<?= $tipo['cor'] ?>">
                    <?= $tipo['label'] ?>
                  </span>
                </span>

              <?php elseif ($item['tipo'] === 'clip'): ?>
                <a href="<?= BASE_URL ?>/clip/<?= View::e($item['clip_id']) ?>" data-clip-produto="<?= View::e($item['clip_id'] ?? '') ?>"
                   class="history-item-name">
                  <?= View::e($item['clip_nome']) ?>
                </a>
                <span class="history-item-type">
                  <span class="history-type-badge history-type-badge--<?= $tipo['cor'] ?>">
                    <?= $tipo['label'] ?>
                  </span>
                </span>

              <?php elseif ($item['tipo'] === 'marca' && !empty($item['marca_slug'])): ?>
                <a href="<?= BASE_URL ?>/marca/<?= View::e($item['marca_slug']) ?>"
                  class="history-item-name">
                  <?= View::e($item['marca_nome']) ?>
                </a>
                <span class="history-type-badge history-type-badge--<?= $tipo['cor'] ?>">
                  <?= $tipo['label'] ?>
                </span>

              <?php elseif ($item['tipo'] === 'busca'): ?>
                <a href="<?= BASE_URL ?>/busca?q=<?= urlencode($item['termo_busca']) ?>"
                   class="history-item-name">
                  "<?= View::e($item['termo_busca']) ?>"
                </a>
                <span class="history-item-type">
                  <span class="history-type-badge history-type-badge--<?= $tipo['cor'] ?>">
                    <?= $tipo['label'] ?>
                  </span>
                </span>
              <?php endif; ?>
            </div>

            <!-- Tempo e hora -->
            <div class="history-item-meta">
              <span class="history-item-hour">
                <?= date('H:i', strtotime($item['criado_em'])) ?>
              </span>
              <?php if ($item['tempo_pagina'] > 0): ?>
              <span class="history-item-time">
                <?= $item['tempo_pagina'] >= 60
                    ? floor($item['tempo_pagina']/60) . 'min'
                    : $item['tempo_pagina'] . 's' ?>
              </span>
              <?php endif; ?>
            </div>

          </div>
          <?php endforeach; ?>
        </div>

        <!-- Paginação -->
        <?php if ($has_pages): ?>
        <?php View::partial('partials/pagination', [
            'pagination'   => $pagination,
            'current_page' => $current_page,
        ]) ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Sidebar com insights -->
    <aside class="history-sidebar">

      <!-- Mais vistos -->
      <?php if (!empty($maisVistos)): ?>
      <div class="customer-section">
        <h2>Produtos que você mais viu</h2>
        <div class="history-top-products">
          <?php foreach ($maisVistos as $prod): ?>
          <a href="<?= BASE_URL ?>/produto/<?= View::e($prod['produto_slug'] ?? $prod['slug']) ?>"
             class="history-top-item">
            <?php
            $img = $prod['imagem_principal'] ?? null;
            ?>
            <?php if ($img): ?>
            <img src="<?= View::upload('products/' . $img) ?>"
                 alt="<?= View::e($prod['nome']) ?>"
                 width="40" height="40" loading="lazy">
            <?php else: ?>
            <div class="history-top-placeholder"></div>
            <?php endif; ?>
            <div class="history-top-info">
              <span class="history-top-name"><?= View::e($prod['nome']) ?></span>
              <span class="history-top-count">
                <?= (int)$prod['vezes_visto'] ?>x visitado
              </span>
            </div>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Categorias favoritas -->
      <?php if (!empty($categorias)): ?>
      <div class="customer-section">
        <h2>Categorias favoritas</h2>
        <div class="history-categories">
          <?php foreach ($categorias as $cat): ?>
          <a href="<?= BASE_URL ?>/categoria/<?= View::e($cat['slug']) ?>"
             class="history-category-item">
            <span class="history-cat-name"><?= View::e($cat['nome']) ?></span>
            <span class="history-cat-count"><?= (int)$cat['visualizacoes'] ?>x</span>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- marcas favoritas -->
      <?php if (!empty($marcas)): ?>
      <div class="customer-section">
        <h2>Marcas favoritas</h2>
        <div class="history-categories">
          <?php foreach ($marcas as $marca): ?>
          <a href="<?= BASE_URL ?>/marca/<?= View::e($marca['slug']) ?>"
             class="history-category-item">
            <span class="history-cat-name"><?= View::e($marca['nome']) ?></span>
            <span class="history-cat-count"><?= (int)$marca['visualizacoes'] ?>x</span>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Buscas recentes -->
      <?php if (!empty($buscas)): ?>
      <div class="customer-section">
        <h2>Suas buscas</h2>
        <div class="history-searches">
          <?php foreach ($buscas as $busca): ?>
          <a href="<?= BASE_URL ?>/busca?q=<?= urlencode($busca['termo_busca']) ?>"
             class="history-search-item">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
              <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
            </svg>
            <?= View::e($busca['termo_busca']) ?>
            <span class="history-search-count"><?= (int)$busca['total'] ?>x</span>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

    </aside>
  </div>
  <?php endif; ?>
</div>