<?php
// views/customer/cards.php
$bandeiras = ['Visa' => '#1a1f71', 'Mastercard' => '#eb001b', 'Amex' => '#2e77bc', 'Elo' => '#ffcb05'];
?>
<div class="customer-page">
  <div class="customer-page-header">
    <h1>Meus cartões</h1>
  </div>

  <div class="cards-info-banner">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
      <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
    </svg>
    Os dados completos do cartão nunca são armazenados em nossos servidores — apenas um token seguro fornecido pelo gateway de pagamento. O CVV jamais é salvo. Ao remover um cartão aqui, ele também é apagado na operadora.
  </div>

  <?php if (empty($cards)): ?>
  <div class="empty-state">
    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
      <rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>
    </svg>
    <p>Você não tem cartões salvos.</p>
    <p class="empty-state-hint">Os cartões são salvos automaticamente quando você opta por isso durante o pagamento.</p>
  </div>
  <?php else: ?>
  <div class="cards-grid" id="cards-grid">
    <?php foreach ($cards as $card):
      $cor = $bandeiras[$card['bandeira']] ?? '#1a1a2e';

      // Titular e validade vêm da adquirente. Quando faltam — cartão salvo
      // antes de o código guardar isso — mostramos um traço em vez de um
      // valor inventado: o cliente não teria como saber que era placeholder.
      $titular  = trim((string)($card['nome_titular'] ?? ''));
      $validade = trim((string)($card['validade'] ?? ''));
      $rotulo   = trim((string)($card['apelido'] ?? '')) ?: ucfirst($card['bandeira']);
    ?>
    <div class="card-item" id="card-item-<?= (int)$card['id'] ?>">
      <div class="card-visual" style="background: linear-gradient(135deg, <?= $cor ?> 0%, #000 100%);">
        <div class="card-visual-number">•••• •••• •••• <?= View::e($card['ultimos_4']) ?></div>
        <div class="card-visual-bottom">
          <span class="card-visual-holder"><?= $titular !== '' ? View::e($titular) : '—' ?></span>
          <span class="card-visual-expiry"><?= $validade !== '' ? View::e($validade) : '—' ?></span>
        </div>
        <div class="card-visual-brand" style="">
          <span class="card-visual-holder">
            <?= View::e($rotulo) ?>
            <?php if ($card['principal']): ?>
              <span class="card-principal-badge">Principal</span>
            <?php endif; ?>
          </span>
          <?= IconLibrary::logo($card['bandeira'], 70, 40) ?>
        </div>
      </div>
      <div class="card-item-actions">
        <?php if (!$card['principal']): ?>
        <button type="button" class="btn-link btn-set-principal-card"
                data-id="<?= (int)$card['id'] ?>">
          Definir como principal
        </button>
        <?php endif; ?>
        <button type="button" class="btn-link btn-link--danger btn-delete-card"
                data-id="<?= (int)$card['id'] ?>">
          Remover
        </button>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>