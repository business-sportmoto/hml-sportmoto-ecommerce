<?php
// views/customer/wishlist.php
$versao = 1;

if($versao === 1) {
?>
<?php // views/customer/wishlist.php ?>

<div class="customer-section">
  <div class="customer-section-header">
    <div>
      <h2>Meus Favoritos</h2>
      <p>Organize seus produtos em listas personalizadas</p>
    </div>
    <button type="button" class="btn btn-primary btn-sm" id="btn-criar-lista">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
        <line x1="12" y1="5" x2="12" y2="19"/>
        <line x1="5"  y1="12" x2="19" y2="12"/>
      </svg>
      Nova lista
    </button>
  </div>

  <!-- Grid de listas -->
  <?php if (empty($listas)): ?>
  <div class="wishlist-empty">
    <svg width="48" height="48" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="1" stroke-linecap="round">
      <path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/>
    </svg>
    <p>Você ainda não tem listas de favoritos.</p>
    <button type="button" class="btn btn-primary" id="btn-criar-lista">
      Criar minha primeira lista
    </button>
  </div>

  <?php else: ?>
  <div class="wishlist-grid" id="wishlist-grid">
    <?php foreach ($listas as $lista): ?>
    <div class="wishlist-card"
        data-lista-id="<?= (int)$lista['id'] ?>"><!-- ← no card pai -->

      <div class="wishlist-card-body"
          data-lista-id="<?= (int)$lista['id'] ?>"><!-- ← no body também -->

        <div class="wishlist-card-icon">
          <?php if ($lista['padrao']): ?>
          <!-- Ícone diferente para lista padrão -->
          <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"
              stroke="none">
            <path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/>
          </svg>
          <?php else: ?>
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none"
              stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
            <path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/>
          </svg>
          <?php endif; ?>
        </div>

        <div class="wishlist-card-info">
          <span class="wishlist-card-nome">
            <?= View::e($lista['nome']) ?>
            <?php if ($lista['padrao']): ?>
            <span class="wishlist-padrao-badge">Padrão</span>
            <?php endif; ?>
          </span>
          <span class="wishlist-card-count">
            <?= (int)$lista['total_itens'] ?>
            <?= $lista['total_itens'] == 1 ? 'produto' : 'produtos' ?>
          </span>
          <?php if (!empty($lista['publica'])): ?>
          <span class="wishlist-card-badge">Pública</span>
          <?php endif; ?>
        </div>

      </div><!-- /.wishlist-card-body -->

      <!-- Esconde botão excluir para lista padrão -->
      <?php if (!$lista['padrao']): ?>
      <div class="wishlist-card-actions">
        <button type="button"
                class="wishlist-action-btn btn-editar-lista"
                data-lista-id="<?= (int)$lista['id'] ?>"
                data-nome="<?= View::e($lista['nome']) ?>"
                data-publica="<?= (int)($lista['publica'] ?? 0) ?>"
                data-descricao="<?= View::e($lista['descricao'] ?? '') ?>"
                title="Editar">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
              stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
            <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
          </svg>
        </button>

        
        <button type="button"
                class="wishlist-action-btn wishlist-action-btn--danger btn-excluir-lista"
                data-lista-id="<?= (int)$lista['id'] ?>"
                data-nome="<?= View::e($lista['nome']) ?>"
                title="Excluir">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
              stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <polyline points="3 6 5 6 21 6"/>
            <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
          </svg>
        </button>
        
      </div>
      <?php endif; ?>
    </div><!-- /.wishlist-card -->
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div>

<!-- Modal de itens da lista -->
<div class="modal-backdrop" id="modal-lista" style="display:none;">
  <div class="modal modal--lg">
    <div class="modal-header">
      <h3 id="modal-lista-titulo">Lista</h3>
      <button type="button" class="modal-close" id="btn-fechar-modal-lista">×</button>
    </div>
    <div class="modal-body" id="modal-lista-body">
      <div class="wishlist-loading">Carregando...</div>
    </div>
  </div>
</div>

<!-- Modal criar/editar lista -->
<div class="modal-backdrop" id="modal-form-lista" style="display:none;">
  <div class="modal">
    <div class="modal-header">
      <h3 id="modal-form-titulo">Nova lista</h3>
      <button type="button" class="modal-close" id="btn-fechar-modal-form">×</button>
    </div>
    <div class="modal-body">
      <form id="form-lista" novalidate>
        <?= SecurityHelper::csrfField() ?>
        <input type="hidden" id="form-lista-id" name="lista_id" value="">

        <div class="form-group">
          <label for="form-lista-nome">Nome da lista *</label>
          <input type="text" id="form-lista-nome" name="nome"
                 class="form-control" placeholder="Ex: Quero muito, Aniversário..."
                 maxlength="100" required>
        </div>

        <div class="form-group">
          <label for="form-lista-descricao">Descrição</label>
          <input type="text" id="form-lista-descricao" name="descricao"
                 class="form-control" placeholder="Opcional"
                 maxlength="255">
        </div>

        <label class="check-label">
          <input type="checkbox" name="publica" id="form-lista-publica" value="1">
          <span class="check-custom"></span>
          Lista pública (pode ser compartilhada)
        </label>

        <div class="form-actions" style="margin-top:20px;">
          <button type="submit" class="btn btn-primary" id="btn-salvar-lista">
            Salvar
          </button>
          <button type="button" class="btn btn-outline"
                  id="btn-cancelar-form-lista">
            Cancelar
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php 
} else {
    
?>
<div class="customer-page">
  <div class="customer-page-header">
    <h1>Meus favoritos</h1>
    <span class="customer-page-sub"><?= count($items) ?> iten<?= count($items) !== 1 ? 's' : '' ?></span>
  </div>

  <?php if (empty($items)): ?>
  <div class="empty-state">
    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
      <path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/>
    </svg>
    <p>Sua lista de favoritos está vazia.</p>
    <a href="<?= BASE_URL ?>/busca" class="btn btn-primary">Explorar produtos</a>
  </div>
  <?php else: ?>
  <div class="products-grid products-grid--4" id="wishlist-grid">
    <?php foreach ($items as $item):
      $product = [
          'id'              => $item['produto_id'],
          'nome'            => $item['nome'],
          'slug'            => $item['slug'],
          'preco'           => $item['preco'],
          'preco_promo'     => $item['preco_promo'],
          'estoque_total'   => $item['estoque_total'],
          'imagem_principal'=> $item['imagem'],
          'destaque'        => 0,
          'criado_em'       => null,
      ];
    ?>
    <?php View::partial('partials/product-card', ['product' => $product]) ?>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php
}


