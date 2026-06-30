<?php
// views/partials/menu-mobile.php
// Drawer de navegação mobile deslizante da esquerda.
?>
<aside class="mobile-menu" id="mobile-menu" aria-label="Menu mobile" aria-hidden="true">
  <div class="mobile-menu-header">
    <span class="mobile-menu-title">Menu</span>
    <button class="mobile-menu-close" id="btn-close-mobile" aria-label="Fechar menu">×</button>
  </div>

  <?php if (Session::isClienteLogado()): ?>
  <div class="mobile-user-info">
    <div class="mobile-user-avatar">
      <?= strtoupper(mb_substr(Session::get('cliente_nome'), 0, 1)) ?>
    </div>
    <div>
      <div class="mobile-user-name"><?= View::e(Session::get('cliente_nome')) ?></div>
      <a href="<?= BASE_URL ?>/minha-conta" class="mobile-user-link">Minha conta</a>
    </div>
  </div>
  <?php else: ?>
  <div class="mobile-auth-btns">
    <a href="<?= BASE_URL ?>/login"   class="btn btn-primary btn-sm">Entrar</a>
    <a href="<?= BASE_URL ?>/cadastro" class="btn btn-outline btn-sm">Cadastrar</a>
  </div>
  <?php endif; ?>

  <!-- Busca mobile -->
  <form class="mobile-search" action="<?= BASE_URL ?>/busca" method="GET">
    <input type="search" name="q" placeholder="Buscar..." class="mobile-search-input">
    <button type="submit" class="mobile-search-btn" aria-label="Buscar">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
        <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
      </svg>
    </button>
  </form>

  <!-- Navegação -->
  <nav class="mobile-nav">
    <ul class="mobile-nav-list">
      <li><a href="<?= BASE_URL ?>">Início</a></li>

      <?php foreach (($nav_tree ?? []) as $cat): ?>
      <li class="mobile-nav-item <?= !empty($cat['children']) ? 'has-children' : '' ?>">
        <a href="<?= BASE_URL ?>/categoria/<?= View::e($cat['slug']) ?>">
          <?= View::e($cat['nome']) ?>
          <?php if (!empty($cat['children'])): ?>
            <svg class="chevron" width="14" height="14" viewBox="0 0 24 24"
                 fill="none" stroke="currentColor" stroke-width="2.5">
              <polyline points="6 9 12 15 18 9"/>
            </svg>
          <?php endif; ?>
        </a>
        <?php if (!empty($cat['children'])): ?>
        <ul class="mobile-nav-sub">
          <?php foreach ($cat['children'] as $sub): ?>
          <li>
            <a href="<?= BASE_URL ?>/categoria/<?= View::e($sub['slug']) ?>">
              <?= View::e($sub['nome']) ?>
            </a>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>

      <li><a href="<?= BASE_URL ?>/busca?ordem=maior_desconto" class="link-sale">Promoções</a></li>
    </ul>
  </nav>

  <div class="mobile-menu-footer">
    <a href="<?= BASE_URL ?>/minha-conta/pedidos">Meus pedidos</a>
    <a href="<?= BASE_URL ?>/minha-conta/favoritos">Favoritos</a>
    <a href="<?= BASE_URL ?>/contato">Atendimento</a>
    <?php if (Session::isClienteLogado()): ?>
      <a href="<?= BASE_URL ?>/sair" class="link-logout">Sair</a>
    <?php endif; ?>
  </div>
</aside>