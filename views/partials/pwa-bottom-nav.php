<?php
/**
 * views/partials/pwa-bottom-nav.php
 * Bottom navigation para o customer layout no PWA.
 * Precisa de: $paginaAtual (string), $perfil (array)
 *
 * Uso: <?php View::partial('partials/pwa-bottom-nav', ['paginaAtual'=>$paginaAtual]) ?>
 */

// Contagem do carrinho (reutiliza o modelo que já está carregado)
try {
    $cartModel  = new Cart();
    $clienteId  = Session::getClienteId();
    $cartCount  = $clienteId ? (int)$cartModel->countItems($cartModel->getOrCreate($clienteId)) : 0;
} catch (\Throwable) {
    $cartCount = 0;
}

// Define qual aba está ativa
$isAccount = str_starts_with($paginaAtual, '/minha-conta');
$isOrders  = str_starts_with($paginaAtual, '/minha-conta/pedidos')
          || str_starts_with($paginaAtual, '/minha-conta/devoluc');
$isCart    = $paginaAtual === '/carrinho';
$isAccount = str_starts_with($paginaAtual, '/minha-conta')
          && !$isOrders
          && !str_starts_with($paginaAtual, '/minha-conta/devoluc');
$isHome    = !$isAccount && !$isCart && !$isOrders;
?>

<nav id="pwa-bottom-nav" role="navigation" aria-label="Navegação principal">

  <!-- Explorar -->
  <a href="<?= BASE_URL ?>/"
     class="pwa-nav-item <?= $isHome ? 'is-active' : '' ?>"
     aria-label="Explorar loja">
    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/>
      <polyline points="9 22 9 12 15 12 15 22"/>
    </svg>
    <span>Explorar</span>
  </a>

  <!-- Pedidos -->
  <a href="<?= BASE_URL ?>/minha-conta/pedidos"
     class="pwa-nav-item <?= $isOrders ? 'is-active' : '' ?>"
     aria-label="Meus pedidos">
    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/>
      <line x1="3" y1="6" x2="21" y2="6"/>
      <path d="M16 10a4 4 0 01-8 0"/>
    </svg>
    <span>Pedidos</span>
  </a>

  <!-- Carrinho -->
  <a href="<?= BASE_URL ?>/carrinho"
     class="pwa-nav-item <?= $isCart ? 'is-active' : '' ?>"
     aria-label="Carrinho de compras">
    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <circle cx="9" cy="21" r="1"/>
      <circle cx="20" cy="21" r="1"/>
      <path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 001.95-1.54L23 6H6"/>
    </svg>
    <?php if ($cartCount > 0): ?>
    <span class="pwa-nav-badge" aria-label="<?= $cartCount ?> itens">
      <?= $cartCount > 99 ? '99+' : $cartCount ?>
    </span>
    <?php endif; ?>
    <span>Carrinho</span>
  </a>

  <!-- Conta -->
  <a href="<?= BASE_URL ?>/minha-conta/conta"
     class="pwa-nav-item <?= $isAccount ? 'is-active' : '' ?>"
     aria-label="Minha conta">
    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/>
      <circle cx="12" cy="7" r="4"/>
    </svg>
    <span>Conta</span>
  </a>

</nav>