<?php
// views/partials/header.php
$cartService    = new Cart();
$carrinhoCount = $cartService->getTotalItensCart();
$cepInfo       = CepController::getCepAtivo();
?>
<?php
// Função recursiva para mega menu (Todas as categorias)
function renderMegaSubcats(array $items, int $depth = 0): void {
    if (empty($items)) return;
    echo "<ul class=\"mega-cat-subs mega-cat-subs--depth-{$depth}\">\n";
    foreach ($items as $item) {
        $hasChildren = !empty($item['children']);
        echo '<li class="' . ($hasChildren ? 'has-sub' : '') . '">';
        echo '<a href="' . BASE_URL . '/categoria/' . htmlspecialchars($item['slug'], ENT_QUOTES) . '">';
        echo '<span>' . htmlspecialchars($item['nome'], ENT_QUOTES) . '</span>';
        if ($hasChildren) {
            // Arrow indicando que tem submenu
            echo '<svg class="sub-arrow" width="12" height="12" viewBox="0 0 24 24"'
               . ' fill="none" stroke="currentColor" stroke-width="2.5"'
               . ' stroke-linecap="round">'
               . '<polyline points="9 18 15 12 9 6"/>'
               . '</svg>';
        }
        echo '</a>';
        if ($hasChildren) {
            renderMegaSubcats($item['children'], $depth + 1);
        }
        echo '</li>';
    }
    echo "</ul>\n";
}

// Função recursiva para o dropdown das categorias dinâmicas
function renderDropdownSubs(array $items, int $depth = 0): void {
    if (empty($items)) return;
    
    $cls = $depth === 0 ? 'dropdown-body' : 'dropdown-sub-list dropdown-sub-list--depth-' . $depth;
    echo "<ul class=\"{$cls}\">";
    echo '<div class="dropdown-header">
            <a href="' . BASE_URL . '/categoria/' . (isset($items[0]['slug']) ? htmlspecialchars($items[0]['slug'], ENT_QUOTES) : '') . '"
              class="dropdown-header-title">
            </a>
            <a href="' . BASE_URL . '/categoria/' . (isset($items[0]['slug']) ? htmlspecialchars($items[0]['slug'], ENT_QUOTES) : '') . '"
              class="dropdown-header-link">
              Ver tudo
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none"
                  stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                <polyline points="9 18 15 12 9 6"/>
              </svg>
            </a>
          </div>';
    foreach ($items as $item) {
        $hasChildren = !empty($item['children']);
        echo '<li class="dropdown-item' . ($hasChildren ? ' dropdown-item--has-sub' : '') . '">';
        echo '<a href="' . BASE_URL . '/categoria/' . htmlspecialchars($item['slug'], ENT_QUOTES) . '" class="dropdown-link">';
        echo '<span>' . htmlspecialchars($item['nome'], ENT_QUOTES) . '</span>';
        if ($hasChildren) {
            echo '<svg class="sub-arrow" width="11" height="11" viewBox="0 0 24 24"'
               . ' fill="none" stroke="currentColor" stroke-width="2.5"'
               . ' stroke-linecap="round"><polyline points="9 18 15 12 9 6"/></svg>';
        }
        echo '</a>';
        if ($hasChildren) {
            renderDropdownSubs($item['children'], $depth + 1);
        }
        echo '</li>';
    }

    echo '</ul>';
}



?>
<header class="site-header" id="site-header">
  <div class="header-top">
    <div class="container">
      <div class="header-top-inner">
        <span class="header-top-msg">
          Frete grátis acima de R$ 299 &bull; Parcele em até 12x sem juros
        </span>
        <div class="header-top-links">
          <?php if (Session::isClienteLogado()): ?>
            <span>Olá, <?= View::e(Session::get('cliente_nome')) ?></span>
            <a href="<?= BASE_URL ?>/minha-conta">Minha conta</a>
            <a href="<?= BASE_URL ?>/sair">Sair</a>
          <?php else: ?>
            <a href="<?= BASE_URL ?>/login">Entrar</a>
            <a href="<?= BASE_URL ?>/cadastro">Criar conta</a>
          <?php endif; ?>
          <a href="<?= BASE_URL ?>/ajuda">Ajuda</a>
        </div>
      </div>
    </div>
  </div>

  <div class="header-main">
    <div class="container">
      <div class="header-main-inner">

        <button class="btn-menu-mobile" id="btn-menu-mobile" aria-label="Abrir menu">
          <span></span><span></span><span></span>
        </button>

        <a href="<?= BASE_URL ?>" class="header-logo" aria-label="<?= View::e($config['nome']) ?>">
          <?php if ($config['logo']): ?>
            <img src="<?= View::upload(ltrim($config['logo'], '/')) ?>"
                 alt="<?= View::e($config['nome']) ?>" width="140" height="44">
          <?php else: ?>
            <span class="logo-text"><?= View::e($config['nome']) ?></span>
          <?php endif; ?>
        </a>

        <?php
          // Busca as categorias raiz para o dropdown (cache para não repetir query)
          $searchCategorias = CacheHelper::remember('search_cats', 3600, function () {
              return Database::getInstance()->getConnection()
                  ->query("SELECT id, nome, slug FROM categorias
                          WHERE ativo = 1 AND parent_id IS NULL
                          ORDER BY ordem ASC, nome ASC")
                  ->fetchAll();
          });

          $catSelecionada    = (int)($_GET['categoria_id'] ?? 0);
          $catSelecionadaNome = 'Todas';
          foreach ($searchCategorias as $sc) {
              if ($sc['id'] == $catSelecionada) {
                  $catSelecionadaNome = $sc['nome'];
                  break;
              }
          }
          ?>

          <div class="header-search" id="header-search">
            <form class="search-form" action="<?= BASE_URL ?>/busca"
                  method="GET" role="search" autocomplete="off" id="main-search-form">

              <!-- Seletor de categoria -->
              <div class="search-cat-selector" id="search-cat-selector">
                <button type="button" class="search-cat-btn" id="btn-search-cat"
                        aria-haspopup="listbox" aria-expanded="false"
                        aria-label="Filtrar por categoria">
                  <span class="search-cat-label" id="search-cat-label">
                    <?= View::e($catSelecionadaNome) ?>
                  </span>
                  <svg class="search-cat-chevron" width="11" height="11"
                      viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <polyline points="6 9 12 15 18 9"/>
                  </svg>
                </button>

                <!-- Hidden input que vai no form -->
                <input type="hidden" name="categoria_id" id="search-cat-value"
                      value="<?= $catSelecionada ?: '' ?>">

                <!-- Dropdown de categorias -->
                <div class="search-cat-dropdown" id="search-cat-dropdown"
                    role="listbox" aria-label="Categorias">
                  <div class="search-cat-list">

                    <!-- Todas as categorias -->
                    <button type="button" class="search-cat-option <?= !$catSelecionada ? 'active' : '' ?>"
                            data-id="" data-nome="Todas" role="option"
                            aria-selected="<?= !$catSelecionada ? 'true' : 'false' ?>">
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
                        <rect x="3" y="5"  width="18" height="2" rx="1"/>
                        <rect x="3" y="11" width="18" height="2" rx="1"/>
                        <rect x="3" y="17" width="18" height="2" rx="1"/>
                      </svg>
                      Todas as categorias
                    </button>

                    <div class="search-cat-divider"></div>

                    <?php foreach ($searchCategorias as $sc): ?>
                    <button type="button"
                            class="search-cat-option <?= $catSelecionada == $sc['id'] ? 'active' : '' ?>"
                            data-id="<?= (int)$sc['id'] ?>"
                            data-nome="<?= View::e($sc['nome']) ?>"
                            role="option"
                            aria-selected="<?= $catSelecionada == $sc['id'] ? 'true' : 'false' ?>">
                      <?= View::e($sc['nome']) ?>
                    </button>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>

              <!-- Separador visual -->
              <div class="search-cat-sep" aria-hidden="true"></div>

              <!-- Input de busca -->
              <input type="search" name="q" id="search-input" class="search-input"
                    placeholder="<?= $catSelecionada
                        ? 'Buscar em ' . View::e($catSelecionadaNome) . '...'
                        : 'Buscar produtos, marcas e categorias...' ?>"
                    aria-label="Buscar produtos"
                    value="<?= View::e($_GET['q'] ?? '') ?>"
                    minlength="2" maxlength="100">

              <button type="submit" class="search-btn" aria-label="Buscar">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                  <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
                </svg>
              </button>
            </form>

            <!-- Autocomplete dropdown -->
            <div class="search-autocomplete" id="search-autocomplete" aria-live="polite"></div>
          </div>

        <!-- Box de localização -->
        <div class="header-location" id="header-location">
          <button type="button" class="header-location-btn btn-open-location"
                  aria-label="Definir localização para cálculo de frete">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/>
              <circle cx="12" cy="10" r="3"/>
            </svg>
            <span class="location-text" id="location-text">
              <?php if ($cepInfo['tem_cep']): ?>
                <span class="location-cep"><?= View::e($cepInfo['cep_fmt']) ?></span>
                <span class="location-city"><?= View::e($cepInfo['display']) ?></span>
              <?php else: ?>
                <span class="location-empty">Informe seu CEP</span>
              <?php endif; ?>
            </span>
            <svg class="location-chevron" width="11" height="11" viewBox="0 0 24 24"
                 fill="none" stroke="currentColor" stroke-width="2.5">
              <polyline points="6 9 12 15 18 9"/>
            </svg>
          </button>
        </div>

        <div class="header-actions">
          <a href="<?= BASE_URL ?>/minha-conta/favoritos" class="header-action"
             aria-label="Lista de desejos">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
            </svg>
            <span class="action-label">Favoritos</span>
          </a>

          <a href="<?= BASE_URL ?>/minha-conta" class="header-action" aria-label="Minha conta">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
              <circle cx="12" cy="7" r="4"/>
            </svg>
            <span class="action-label" style="display:flex; align-items:center; gap:4px;">
              <?= Session::isClienteLogado()
                ? mb_substr(Session::get('cliente_nome'), 0,
                    strpos(Session::get('cliente_nome'), ' ') ?: 10)
                : 'Entrar' ?>
              <?php if (Session::isClienteLogado()): 
                
                ?>
                <?php View::partial('partials/verified-badge', ['verificado' => (bool)Session::get('cliente_verificado'), 'size' => 'sm', ]) ?>
              <?php endif; ?>
            </span>
          </a>

          <button type="button" class="header-action header-cart-btn" id="btn-open-cart" aria-label="Abrir carrinho">
            <span class="cart-icon-wrapper">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none"
                  stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <circle cx="9" cy="21" r="1"/>
                <circle cx="20" cy="21" r="1"/>
                <path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/>
              </svg>
              <span class="cart-count" id="cart-count" <?= ($carrinhoCount === 0) ? 'style="display:none"' : '' ?>>
                <?php
                  echo $carrinhoCount > 99 ? '99+' : $carrinhoCount;
                ?>
              </span>
            </span>
            <span class="action-label">Carrinho</span>
          </button>
          </div>
      </div>
    </div>
  </div>

  

<nav class="header-nav" id="header-nav" aria-label="Navegação principal">
  <div class="container">
    <ul class="nav-list">

      <!-- Todas as categorias -->
      <li class="nav-item nav-item--mega">
        <a href="<?= BASE_URL ?>/busca" class="nav-link nav-link--all">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor">
            <rect x="3" y="5"  width="18" height="2" rx="1"/>
            <rect x="3" y="11" width="18" height="2" rx="1"/>
            <rect x="3" y="17" width="18" height="2" rx="1"/>
          </svg>
          Todas as Categorias
          <svg class="nav-chevron" width="11" height="11" viewBox="0 0 24 24"
               fill="none" stroke="currentColor" stroke-width="2.5">
            <polyline points="6 9 12 15 18 9"/>
          </svg>
        </a>

        <div class="mega-menu">
          <div class="mega-inner mega-inner--all">
            <div class="mega-all-grid">
              <?php
              $cats = $nav_tree ?? [];
              $cols = array_chunk($cats, max(1, (int)ceil(count($cats) / 4)));
              ?>
              <?php foreach ($cols as $col): ?>
              <div class="mega-col">
                <?php foreach ($col as $cat): ?>
                <div class="mega-cat-group">
                  <a href="<?= BASE_URL ?>/categoria/<?= View::e($cat['slug']) ?>"
                     class="mega-cat-title">
                    <?= View::e($cat['nome']) ?>
                  </a>
                  <?php if (!empty($cat['children'])): ?>
                    <?php renderMegaSubcats($cat['children'], 0) ?>
                  <?php endif; ?>
                </div>
                <?php endforeach; ?>
              </div>
              <?php endforeach; ?>

              <div class="mega-col mega-col--highlight">
                <span class="mega-highlight-label">Destaques</span>
                <a href="<?= BASE_URL ?>/busca?lancamento=1" class="mega-highlight-link">
                  <span class="mega-highlight-tag mega-highlight-tag--new">Novo</span>
                  Lançamentos
                </a>
                <a href="<?= BASE_URL ?>/busca?ordem=maior_desconto" class="mega-highlight-link">
                  <span class="mega-highlight-tag mega-highlight-tag--sale">%</span>
                  Mais descontos
                </a>
                <a href="<?= BASE_URL ?>/busca?ordem=mais_vendidos" class="mega-highlight-link">
                  <span class="mega-highlight-tag mega-highlight-tag--hot">Top</span>
                  Mais vendidos
                </a>
              </div>
            </div>
          </div>
        </div>
      </li>
      <?php ?>
      <!-- Categorias dinâmicas — dropdown com N níveis -->
      <?php foreach (($nav_tree ?? []) as $cat): ?>
      <li class="nav-item <?= !empty($cat['children']) ? 'nav-item--has-sub' : '' ?>">
        <a href="<?= BASE_URL ?>/categoria/<?= View::e($cat['slug']) ?>"
          class="nav-link">
          <?= View::e($cat['nome']) ?>
          <?php if (!empty($cat['children'])): ?>
          <svg class="nav-chevron" width="11" height="11" viewBox="0 0 24 24"
              fill="none" stroke="currentColor" stroke-width="2.5">
            <polyline points="6 9 12 15 18 9"/>
          </svg>
          <?php endif; ?>
        </a>

        <?php if (!empty($cat['children'])): ?>
        <div class="nav-dropdown">

          <!-- Cabeçalho estilo mega -->
          

          <!-- Itens recursivos -->
          <?php renderDropdownSubs($cat['children'], 0) ?>

          <!-- Rodapé com destaques -->
          <div class="dropdown-footer">
            <a href="<?= BASE_URL ?>/categoria/<?= View::e($cat['slug']) ?>?ordem=maior_desconto"
              class="dropdown-footer-tag dropdown-footer-tag--sale">
              % Promoções
            </a>
            <a href="<?= BASE_URL ?>/categoria/<?= View::e($cat['slug']) ?>?ordem=mais_vendidos"
              class="dropdown-footer-tag dropdown-footer-tag--hot">
              Top vendidos
            </a>
            <a href="<?= BASE_URL ?>/categoria/<?= View::e($cat['slug']) ?>?lancamento=1"
              class="dropdown-footer-tag dropdown-footer-tag--new">
              Novidades
            </a>
          </div>

        </div>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>

      <!-- Promoções -->
      <li class="nav-item nav-item--mega nav-item--promo">
        <a href="<?= BASE_URL ?>/busca?ordem=maior_desconto"
           class="nav-link nav-link--sale">
          Promoções
          <svg class="nav-chevron" width="11" height="11" viewBox="0 0 24 24"
               fill="none" stroke="currentColor" stroke-width="2.5">
            <polyline points="6 9 12 15 18 9"/>
          </svg>
        </a>

        <div class="mega-menu">
          <div class="mega-inner mega-inner--promo">
            <div class="mega-promo-grid">
              <div class="mega-promo-nav">
                <span class="mega-promo-nav-title">Promoções</span>
                <ul class="mega-promo-links">
                  <?php
                  $promoLinks = [
                    ['label' => 'Mais descontos',   'url' => '/busca?ordem=maior_desconto'],
                    ['label' => 'Mais vendidos',     'url' => '/busca?ordem=mais_vendidos'],
                    ['label' => 'Frete grátis',      'url' => '/busca?frete_gratis=1'],
                    ['label' => 'Lançamentos',       'url' => '/busca?lancamento=1'],
                    ['label' => 'Outlet',            'url' => '/busca?outlet=1'],
                    ['label' => 'Escolha seu cupom', 'url' => '/busca?cupom=1'],
                    ['label' => 'Para usar já',      'url' => '/busca?disponivel=1'],
                    ['label' => 'Desconto no app',   'url' => '/busca?app=1'],
                  ];
                  ?>
                  <?php foreach ($promoLinks as $pl): ?>
                  <li>
                    <a href="<?= BASE_URL . $pl['url'] ?>" class="mega-promo-link">
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                           stroke="currentColor" stroke-width="2" stroke-linecap="round">
                        <polyline points="9 18 15 12 9 6"/>
                      </svg>
                      <?= View::e($pl['label']) ?>
                    </a>
                  </li>
                  <?php endforeach; ?>
                </ul>
              </div>

              <div class="mega-promo-cards">
                <div class="mega-card mega-card--lilac">
                  <div class="mega-card-body">
                    <span class="mega-card-eyebrow">Exclusivo</span>
                    <h3 class="mega-card-title">Escolha seu benefício</h3>
                    <p class="mega-card-desc">Frete grátis, brinde ou desconto?</p>
                    <a href="<?= BASE_URL ?>/busca?beneficio=1" class="mega-card-btn">Resgate agora</a>
                  </div>
                  <div class="mega-card-deco mega-card-deco--1"></div>
                </div>
                <div class="mega-card mega-card--slate">
                  <div class="mega-card-body">
                    <span class="mega-card-eyebrow">Economia</span>
                    <h3 class="mega-card-title">Escolha seu cupom</h3>
                    <p class="mega-card-desc">Economize nas suas marcas favoritas</p>
                    <a href="<?= BASE_URL ?>/busca?cupom=1" class="mega-card-btn">Confira</a>
                  </div>
                  <div class="mega-card-deco mega-card-deco--2"></div>
                </div>
                <div class="mega-card mega-card--rose">
                  <div class="mega-card-body">
                    <span class="mega-card-eyebrow">App exclusivo</span>
                    <h3 class="mega-card-title">Ganhe 10% OFF</h3>
                    <p class="mega-card-desc">Na sua primeira compra no app</p>
                    <a href="<?= BASE_URL ?>/busca?app=1" class="mega-card-btn">Confira</a>
                  </div>
                  <div class="mega-card-deco mega-card-deco--3"></div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </li>

      <!-- Dentro da .nav-list, adicione após as categorias: -->
      <li class="nav-item nav-item--marcas">
        <a href="<?= BASE_URL ?>/marcas" class="nav-link">
          Marcas
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
              stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <polyline points="6 9 12 15 18 9"/>
          </svg>
        </a>

        <div class="marcas-dropdown">
          <div class="marcas-dropdown-inner">

            <div class="marcas-dropdown-header">
              <span class="marcas-dropdown-title">Nossas marcas</span>
              <a href="<?= BASE_URL ?>/marcas" class="marcas-dropdown-ver-tudo">
                Ver todas
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                  <line x1="5" y1="12" x2="19" y2="12"/>
                  <polyline points="12 5 19 12 12 19"/>
                </svg>
              </a>
            </div>

            <div class="marcas-dropdown-grid">
              <?php foreach ($marcasMenu as $m):
                $bg = !empty($m['bg_cor']) ? $m['bg_cor'] : '#f8fafc';
              ?>
              <a href="<?= BASE_URL ?>/marca/<?= View::e($m['slug']) ?>"
                class="marcas-dropdown-card">

                <div class="marcas-dropdown-logo"
                    style="background:<?= View::e($bg) ?>">
                  <?php if (!empty($m['logo'])): ?>
                  <img src="<?= View::upload('brands/' . $m['logo']) ?>"
                      alt="<?= View::e($m['nome']) ?>"
                      loading="lazy">
                  <?php else: ?>
                  <span class="marcas-dropdown-initials">
                    <?= View::e(mb_strtoupper(mb_substr($m['nome'], 0, 2))) ?>
                  </span>
                  <?php endif; ?>
                </div>

                <div class="marcas-dropdown-info">
                  <span class="marcas-dropdown-nome"><?= View::e($m['nome']) ?></span>
                  <span class="marcas-dropdown-count">
                    <?= number_format((int)$m['total_produtos']) ?> produto<?= $m['total_produtos'] != 1 ? 's' : '' ?>
                  </span>
                </div>

              </a>
              <?php endforeach; ?>
            </div>

          </div>
        </div>
      </li>      
    </ul>
  </div>
</nav>
</header>

<div class="header-spacer" id="header-spacer"></div>

<!-- Modal de localização (fora do header, antes do </body>) -->
<div class="location-modal-backdrop" id="location-modal-backdrop" style="display:none;">
  <div class="location-modal" id="location-modal">

    <div class="location-modal-header">
      <h3>
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/>
          <circle cx="12" cy="10" r="3"/>
        </svg>
        Calcular frete e prazo
      </h3>
      <button type="button" class="location-modal-close" id="btn-close-location"
              aria-label="Fechar">×</button>
    </div>

    <div class="location-modal-body">

      <?php if ($cepInfo['tem_cep']): ?>
      <!-- CEP atual -->
      <div class="location-current" id="location-current">
        <div class="location-current-info">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/>
            <circle cx="12" cy="10" r="3"/>
          </svg>
          <div>
            <strong id="modal-cep-fmt"><?= View::e($cepInfo['cep_fmt']) ?></strong>
            <span id="modal-cep-city"><?= View::e($cepInfo['display']) ?></span>
          </div>
        </div>
        <button type="button" class="btn-change-cep" id="btn-change-cep">
          Alterar
        </button>
      </div>
      <?php endif; ?>

      <!-- Formulário de CEP -->
      <div class="location-form-wrap" id="location-form-wrap"
           style="<?= $cepInfo['tem_cep'] ? 'display:none;' : '' ?>">

        <?php if (!Session::isClienteLogado()): ?>
        <!-- Visitante: só pede o CEP -->
        <p class="location-form-desc">
          Informe seu CEP para ver prazos e fretes disponíveis.
        </p>
        <form class="location-form" id="form-cep" novalidate>
          <div class="location-input-group">
            <input type="text" id="location-cep-input" class="form-control cep-mask"
                   placeholder="00000-000" maxlength="9" autocomplete="postal-code">
            <button type="submit" class="btn btn-primary btn-sm" id="btn-save-cep">
              Usar este CEP
            </button>
          </div>
          <span class="location-cep-error" id="location-cep-error"></span>
          <a href="#" id="link-lookup-cep" class="cep-link"
             target="_blank" rel="noopener"
             href="https://buscacepinter.correios.com.br/app/endereco/index.php">
            Não sei meu CEP
          </a>
        </form>

        <div class="location-login-hint">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <circle cx="12" cy="12" r="10"/>
            <line x1="12" y1="8" x2="12" y2="12"/>
            <line x1="12" y1="16" x2="12.01" y2="16"/>
          </svg>
          Já tem cadastro?
          <a href="<?= BASE_URL ?>/login">Faça login</a>
          para usar o CEP do seu endereço automaticamente.
        </div>

        <?php elseif (!$cepInfo['tem_endereco']): ?>
        <!-- Logado sem endereço -->
        <p class="location-form-desc">
          Você ainda não tem endereço cadastrado.
          Informe seu CEP para calcular o frete.
        </p>
        <form class="location-form" id="form-cep" novalidate>
          <div class="location-input-group">
            <input type="text" id="location-cep-input" class="form-control cep-mask"
                   placeholder="00000-000" maxlength="9" autocomplete="postal-code">
            <button type="submit" class="btn btn-primary btn-sm" id="btn-save-cep">
              Usar este CEP
            </button>
          </div>
          <span class="location-cep-error" id="location-cep-error"></span>
          <label class="location-save-check">
            <input type="checkbox" id="salvar-endereco-check" value="1" checked>
            <span class="check-custom"></span>
            Salvar como meu endereço principal
          </label>
        </form>

        <div class="location-or">
          <span>ou</span>
        </div>
        <a href="<?= BASE_URL ?>/minha-conta/enderecos"
           class="btn btn-outline btn-full btn-sm location-goto-address">
          Cadastrar endereço completo
        </a>

        <?php else: ?>
        <!-- Logado com endereço — não deve mostrar o form (já tem CEP) -->
        <p class="location-form-desc">
          Usando o CEP do seu endereço principal.
        </p>
        <a href="<?= BASE_URL ?>/minha-conta/enderecos" class="btn btn-outline btn-sm">
          Gerenciar endereços
        </a>
        <?php endif; ?>

      </div>

    </div>

    <?php if ($cepInfo['tem_cep'] && $cepInfo['origem'] !== 'endereco'): ?>
    <div class="location-modal-footer">
      <button type="button" class="btn-remove-cep" id="btn-remove-cep">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <polyline points="3 6 5 6 21 6"/>
          <path d="M19 6l-1 14H6L5 6"/>
        </svg>
        Remover CEP salvo
      </button>
    </div>
    <?php endif; ?>

  </div>
</div>