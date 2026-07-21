<?php
// views/cart/shared.php
$diasRestantes = max(0, (int)ceil(
    (strtotime($expira_em) - time()) / 86400
));


?>
<div class="container" style="padding: 48px 0 80px;">
  <div class="shared-cart-wrap">

    <!-- Header -->
    <div class="shared-cart-header">
      <svg width="32" height="32" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
        <circle cx="9"  cy="21" r="1"/>
        <circle cx="20" cy="21" r="1"/>
        <path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/>
      </svg>
      <div>
        <h1>Carrinho compartilhado</h1>
        <p>
          Válido por mais
          <strong><?= $diasRestantes ?> dia<?= $diasRestantes !== 1 ? 's' : '' ?></strong>
          <!-- · Visto <?= (int)$compartilhado['visualizacoes'] ?> vez<?= $compartilhado['visualizacoes'] !== 1 ? 'es' : '' ?> -->
        </p>
      </div>
    </div>

    <!-- Quem compartilhou + vendedor -->
    <?php if ($compartilhado_por || $vendedor_nome): ?>
    <div class="shared-cart-info-bar">

      <?php if ($compartilhado_por): ?>
      <div class="shared-info-item">
        <div class="shared-info-icon shared-info-icon--user">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/>
            <circle cx="12" cy="7" r="4"/>
          </svg>
        </div>
        <div class="shared-info-text">
          <span class="shared-info-label">Compartilhado por</span>
          <strong class="shared-info-value"><?= View::e($compartilhado_por) ?></strong>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($vendedor_nome): ?>
      <div class="shared-info-item">
        <div class="shared-info-icon shared-info-icon--seller">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <rect x="2" y="7" width="20" height="14" rx="2"/>
            <path d="M16 3H8L6 7h12l-2-4z"/>
          </svg>
        </div>
        <div class="shared-info-text">
          <span class="shared-info-label">Vendedor</span>
          <strong class="shared-info-value"><?= View::e($vendedor_nome) ?></strong>
        </div>
      </div>
      <?php endif; ?>

    </div>
    <?php endif; ?>

    <!-- Aviso copiado -->
    <?php if ($copiado): ?>
    <div class="shared-cart-notice shared-cart-notice--success">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
        <polyline points="20 6 9 17 4 12"/>
      </svg>
      Itens adicionados ao seu carrinho!
      <a href="<?= BASE_URL ?>/carrinho">Ver meu carrinho</a>
    </div>
    <?php endif; ?>

    <!-- Itens do snapshot -->
    <div class="shared-cart-items">
      <?php if (empty($itens)): ?>
        <p style="color:var(--c-text-muted);text-align:center;padding:32px 0;">
          Este carrinho não tem itens.
        </p>
      <?php else: ?>
        <?php foreach ($itens as $item):
          $imgUrl = !empty($item['imagem'])
            ? View::e($item['imagem'])
            : null;
          $opcoes = $item['opcoes'] ?? [];
        ?>
        <div class="shared-cart-item">

          <?php if ($imgUrl): ?>
          <img src="<?= $imgUrl ?>" alt="<?= View::e($item['nome']) ?>"
               width="72" height="72" loading="lazy">
          <?php else: ?>
          <div class="shared-cart-item-placeholder">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
              <path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/>
            </svg>
          </div>
          <?php endif; ?>

          <div class="shared-cart-item-info">
            <?php if (!empty($item['slug'])): ?>
              <a href="<?= BASE_URL ?>/produto/<?= View::e($item['slug']) ?>"
                 class="shared-cart-item-name">
                <?= View::e($item['nome']) ?>
              </a>
            <?php else: ?>
              <span class="shared-cart-item-name"><?= View::e($item['nome']) ?></span>
            <?php endif; ?>

            <?php if (!empty($opcoes)): ?>
            <span class="shared-cart-item-opts">
              <?= View::e(implode(' · ', array_map(
                  fn($k, $v) => "{$k}: {$v}",
                  array_keys($opcoes),
                  array_values($opcoes)
              ))) ?>
            </span>
            <?php endif; ?>

            <span class="shared-cart-item-qty">
              <?= (int)$item['quantidade'] ?> un.
              × <?= PriceHelper::format((float)$item['preco']) ?>
            </span>
          </div>

          <div class="shared-cart-item-price">
            <?= PriceHelper::format((float)$item['subtotal']) ?>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Totais -->
    <?php if (!empty($itens)): ?>
    <div class="shared-cart-totals">
      <div class="shared-total-row">
        <span>Subtotal</span>
        <span><?= PriceHelper::format((float)$subtotal) ?></span>
      </div>
      <?php if ($desconto > 0): ?>
      <div class="shared-total-row shared-total-row--discount">
        <span>Desconto</span>
        <span>− <?= PriceHelper::format((float)$desconto) ?></span>
      </div>
      <?php endif; ?>
      <div class="shared-total-divider"></div>
      <div class="shared-total-row shared-total-row--total">
        <strong>Total no momento do compartilhamento</strong>
        <strong><?= PriceHelper::format((float)$total) ?></strong>
      </div>
      <p class="shared-total-obs">
        * Os preços podem ter sido atualizados. O valor final será calculado no checkout.
      </p>
    </div>

    <div class="shared-cart-actions">
        <?php if (!$copiado): ?>
        <button type="button" class="btn btn-primary" id="btn-copiar-carrinho"
                data-token="<?= View::e($token) ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
            stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <circle cx="9"  cy="21" r="1"/>
            <circle cx="20" cy="21" r="1"/>
            <path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/>
        </svg>
        Adicionar ao meu carrinho
        </button>

        <!-- Modal de conflito -->
        <div class="modal-backdrop" id="modal-carrinho-conflito" style="display:none;">
        <div class="modal" style="max-width:420px;">
            <div class="modal-header">
            <h3>Você já tem itens no carrinho</h3>
            <button type="button" class="modal-close" id="btn-fechar-conflito">×</button>
            </div>
            <div class="modal-body">

            <p style="font-size:14px;color:var(--c-text-muted);margin-bottom:20px;line-height:1.6;">
                O que deseja fazer com os produtos que já estão no seu carrinho?
            </p>

            <!-- Opção 1: Adicionar junto -->
            <button type="button" class="conflito-opcao" id="btn-adicionar-junto">
                <div class="conflito-opcao-icon conflito-opcao-icon--merge">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <line x1="12" y1="5" x2="12" y2="19"/>
                    <line x1="5"  y1="12" x2="19" y2="12"/>
                </svg>
                </div>
                <div class="conflito-opcao-info">
                <strong>Adicionar ao carrinho atual</strong>
                <span>Os produtos compartilhados serão adicionados junto com os que você já tem.</span>
                </div>
                <svg class="conflito-opcao-arrow" width="16" height="16" viewBox="0 0 24 24"
                    fill="none" stroke="currentColor" stroke-width="2.5">
                <polyline points="9 18 15 12 9 6"/>
                </svg>
            </button>

            <!-- Opção 2: Substituir -->
            <button type="button" class="conflito-opcao conflito-opcao--danger"
                    id="btn-substituir-carrinho">
                <div class="conflito-opcao-icon conflito-opcao-icon--replace">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <polyline points="1 4 1 10 7 10"/>
                    <path d="M3.51 15a9 9 0 102.13-9.36L1 10"/>
                </svg>
                </div>
                <div class="conflito-opcao-info">
                <strong>Esvaziar e usar este carrinho</strong>
                <span>Seus itens atuais serão removidos e substituídos pelos compartilhados.</span>
                </div>
                <svg class="conflito-opcao-arrow" width="16" height="16" viewBox="0 0 24 24"
                    fill="none" stroke="currentColor" stroke-width="2.5">
                <polyline points="9 18 15 12 9 6"/>
                </svg>
            </button>

            </div>
        </div>
        </div>

        <?php else: ?>
        <a href="<?= BASE_URL ?>/checkout" class="btn btn-primary">
        Finalizar compra
        </a>
        <?php endif; ?>      
      <a href="<?= BASE_URL ?>/busca" class="btn btn-outline">
        Continuar comprando
      </a>
    </div>
    <?php endif; ?>

  </div>
</div>