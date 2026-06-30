<?php
// ════════════════════════════════════════════════════════
// views/checkout/address.php — v3
//
// Dois passos dentro da mesma página:
//   Passo 1: Selecionar endereço
//   Passo 2: Selecionar forma de envio (frete)
//
// Passo 2 é renderizado pelo servidor SE já houver
// endereço+frete salvos no estado; caso contrário é
// carregado dinamicamente pelo JS após o passo 1.
// ════════════════════════════════════════════════════════

$enderecoSelecionado = $enderecoSelecionado ?? null;
$freteAtual          = $freteAtual          ?? null;  // do CheckoutState
$mostrarFrete        = $enderecoSelecionado !== null;


 $freteJaSalvo = $checkoutFrete ?? null;
$endSelecionado = $enderecoSelecionado ?? null;
// Se já tem endereço E frete salvos, mostra os dois sub-passos prontos
$subPassoInicial = ($endSelecionado && $freteJaSalvo) ? 2 : ($endSelecionado ? 2 : 1);
?>

<div class="checkout-section" id="address-page">

  <!-- ════════════════════════════════════════════════
       PASSO 1 — ENDEREÇO
       ════════════════════════════════════════════════ -->
  <div class="checkout-step-block" id="step-address">

    <div class="step-block-header">
      <div class="step-block-num <?= $mostrarFrete ? 'is-done' : 'is-active' ?>">
        <?php if ($mostrarFrete): ?>
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="3" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
        <?php else: ?>
          1
        <?php endif; ?>
      </div>
      <div>
        <h2 class="step-block-title">Endereço de entrega</h2>
        <?php if ($mostrarFrete && $enderecoSelecionado): ?>
          <?php $end = collect_selected($enderecos ?? [], $enderecoSelecionado); ?>
          <p class="step-block-sub-done" id="address-done-label">
            <?= View::e($end['logradouro'] ?? '') ?>, <?= View::e($end['numero'] ?? '') ?>
            — <?= View::e($end['cidade'] ?? '') ?>/<?= View::e($end['estado'] ?? '') ?>
            <a href="<?= BASE_URL ?>/checkout/address/update"
               class="step-block-change">Alterar endereço</a>
          </p>
        <?php else: ?>
          <p class="step-block-sub">Para onde enviamos seu pedido?</p>
        <?php endif; ?>
      </div>
    </div>

    <!-- Lista de endereços (visível se passo 1 ativo ou ao alterar) -->
    <div id="address-list-panel" <?= $mostrarFrete ? 'hidden' : '' ?>>

      <?php if (empty($enderecos)): ?>
        <div class="empty-state">
          <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="1.5" stroke-linecap="round">
            <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/>
          </svg>
          <strong>Nenhum endereço cadastrado</strong>
          <p>Adicione seu primeiro endereço para continuar.</p>
        </div>

      <?php else: ?>
        <div class="saved-addresses" id="saved-addresses">
          <?php foreach ($enderecos as $end):
            $isSel = (int)$end['id'] === (int)$enderecoSelecionado;
          ?>
          <label class="address-card as <?= $isSel ? 'is-selected' : '' ?>"
                 data-end-id="<?= (int)$end['id'] ?>"
                 data-end-cep="<?= View::e(substr($end['cep'], 0, 5) . '-' . substr($end['cep'], 5)) ?>">
            <input type="radio" name="endereco_entrega" class="address-radio"
                   value="<?= (int)$end['id'] ?>" <?= $isSel ? 'checked' : '' ?>>
            <div class="address-card-body">
              <div class="address-card-header">
                <span class="address-icon">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                       stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/>
                    <polyline points="9 22 9 12 15 12 15 22"/>
                  </svg>
                </span>
                <strong><?= View::e($end['nome_destinatario']) ?></strong>
                <?php if ($end['principal']): ?>
                  <span class="address-badge">Principal</span>
                <?php endif; ?>
                <?php if (!empty($end['apelido'])): ?>
                  <span class="address-apelido"><?= View::e($end['apelido']) ?></span>
                <?php endif; ?>
              </div>
              <p class="address-line">
                <?= View::e($end['logradouro']) ?>, <?= View::e($end['numero']) ?>
                <?php if (!empty($end['complemento'])): ?>— <?= View::e($end['complemento']) ?><?php endif; ?>
              </p>
              <p class="address-line">
                <?= View::e($end['bairro']) ?> —
                <?= View::e($end['cidade']) ?>/<?= View::e($end['estado']) ?>
              </p>
              <p class="address-line address-line--cep">CEP <?= View::e($end['cep']) ?></p>
            </div>
            <div class="address-card-check">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                   stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
          </label>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="address-actions">
        <a href="<?= BASE_URL ?>/checkout/address/add" class="btn-add-address">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2.5" stroke-linecap="round">
            <line x1="12" y1="5" x2="12" y2="19"/>
            <line x1="5" y1="12" x2="19" y2="12"/>
          </svg>
          Adicionar novo endereço
        </a>
        <?php if (!empty($enderecos)): ?>
        <a href="<?= BASE_URL ?>/checkout/address/update" class="btn-manage-addresses">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2.5" stroke-linecap="round">
            <path d="M12 20h9"/>
            <path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/>
          </svg>
          Gerenciar endereços
        </a>
        <?php endif; ?>
      </div>

      <?php if (!empty($enderecos)): ?>
      <button type="button" class="btn btn-primary btn-full" id="btn-select-address">
        Usar este endereço
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.5" stroke-linecap="round">
          <line x1="5" y1="12" x2="19" y2="12"/>
          <polyline points="12 5 19 12 12 19"/>
        </svg>
      </button>
      <?php endif; ?>
    </div>
  </div>

  
</div>
<!-- ════════════════════════════════════════════════
       PASSO 2 — FORMA DE ENVIO
       ════════════════════════════════════════════════ -->
  <div class="checkout-step-block" id="step-frete"
      <?= $mostrarFrete ? '' : 'hidden' ?>
      data-cep-inicial="<?= View::e(preg_replace('/\D/', '', $enderecos[0]['cep'] ?? '')) ?>"
      data-end-inicial="<?= (int)$endSelecionado ?>">

    <div class="step-block-header">
      <div class="step-block-num <?= $freteAtual ? 'is-done' : ($mostrarFrete ? 'is-active' : '') ?>">
        <?php if ($freteAtual): ?>
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="3" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
        <?php else: ?>
          2
        <?php endif; ?>
      </div>
      <div>
        <h2 class="step-block-title">Forma de envio</h2>
        <?php if ($freteAtual): ?>
          <p class="step-block-sub-done">
            <?= View::e($freteAtual['descricao']) ?> ·
            <?php if ((float)$freteAtual['valor'] === 0.0): ?>
              <strong class="txt-success">GRÁTIS</strong>
            <?php else: ?>
              <?= PriceHelper::format((float)$freteAtual['valor']) ?>
            <?php endif; ?>
            <button type="button" class="step-block-change" id="btn-change-frete">Alterar</button>
          </p>
        <?php else: ?>
          <p class="step-block-sub">Calculando opções para o seu CEP…</p>
        <?php endif; ?>
      </div>
    </div>

    <!-- Cards de frete -->
    <div id="frete-list-panel">
      <div class="frete-skeleton" id="frete-skeleton">
        <div class="frete-skel-card"></div>
        <div class="frete-skel-card"></div>
        <div class="frete-skel-card"></div>
      </div>
      <div class="frete-empty" id="frete-empty" style="display:none;">
        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="1.5" stroke-linecap="round">
          <circle cx="12" cy="12" r="10"/>
          <line x1="12" y1="8" x2="12" y2="12"/>
          <line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        <strong>Frete indisponível para este endereço</strong>
        <span>
          <button type="button" class="btn-link" id="btn-retry-frete">Tentar novamente</button>
          ou
          <a href="<?= BASE_URL ?>/checkout/address">trocar endereço</a>
        </span>
      </div>
      <div class="frete-cards" id="frete-cards"></div>

      <div id="frete-error" class="form-alert" style="display:none;"></div>

      <button type="button" class="btn btn-primary btn-full" id="btn-confirm-frete"
              disabled style="margin-top:14px;">
        Continuar para pagamento
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.5" stroke-linecap="round">
          <line x1="5" y1="12" x2="19" y2="12"/>
          <polyline points="12 5 19 12 12 19"/>
        </svg>
      </button>
    </div>
  </div>
<?php
// Helper para encontrar endereço selecionado no array
function collect_selected(array $enderecos, ?int $id): array {
    foreach ($enderecos as $e) {
        if ((int)$e['id'] === $id) return $e;
    }
    return $enderecos[0] ?? [];
}
?>

  <script>
  // Dados iniciais para o JS
  window.CHECKOUT_FRETE_ATUAL = <?= json_encode($freteJaSalvo) ?>;
  window.CHECKOUT_SUB_PASSO   = <?= $subPassoInicial ?>;
  window.CHECKOUT_END_ID      = <?= (int)$endSelecionado ?>;
  </script>


