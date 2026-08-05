<?php
/**
 * Widget de frete na página de produto.
 * Incluir no template do produto (onde $produto está disponível):
 *   <?php include __DIR__ . '/../partials/frete-produto.php'; ?>
 *
 * Espera $produto com 'id' e um campo de preço (preco_final/preco/valor).
 * O JS lê o CEP do cookie `ec_cep` (o mesmo da sua modal de localização) e
 * busca o frete sozinho ao abrir a página.
 */
$__pid   = (int)($product['id'] ?? 0);
$__preco = (float)($product['preco_final'] ?? $product['preco_venda'] ?? $product['preco'] ?? $product['valor'] ?? 0);
?>

<div id="fpFrete" class="fp_frete_slot"
     data-produto-id="<?= $__pid ?>"
     data-preco="<?= htmlspecialchars(number_format($__preco, 2, '.', ''), ENT_QUOTES) ?>">
    <!-- preenchido por frete-produto.js -->
</div>

