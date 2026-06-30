<?php
/**
 * admin/views/admin/payment/webhook-detalhe.php
 *
 * Variáveis:
 *   $log → linha de pgto_webhook_log com payload_decoded
 */
$base = defined('BASE_URL') ? BASE_URL : '';
require_once __DIR__ . '/_helpers.php';

$podeReprocessar = ((int) $log['processado'] !== 1) && ((int) $log['assinatura_valida'] === 1);
?>
<link rel="stylesheet" href="<?= $base ?>/public/css/payment-admin.css?v=1">

<div class="pgto_wrapper">

  <div class="pgto_header">
    <div>
      <h1>Webhook #<?= (int) $log['id'] ?></h1>
      <p class="pgto_sub">
        <code><?= htmlspecialchars($log['tipo']) ?></code>
        · recebido em <?= htmlspecialchars($log['recebido_em']) ?>
      </p>
    </div>
    <div class="pgto_actions">
      <a href="<?= $base ?>/admin/payment/webhooks" class="pgto_btn pgto_btn_ghost">← Voltar</a>
      <?php if ($podeReprocessar): ?>
        <button type="button" class="pgto_btn pgto_btn_primary" id="btn-reprocessar">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/>
            <path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/>
          </svg>
          Reprocessar
        </button>
      <?php endif; ?>
    </div>
  </div>

  <div class="pgto_detail_grid">

    <div class="pgto_detail_col">

      <!-- Status -->
      <div class="pgto_card">
        <h3 class="pgto_card_title">Status</h3>
        <dl class="pgto_dl">
          <dt>Processado</dt>
          <dd>
            <?php if ((int) $log['processado'] === 1): ?>
              <span class="pgto_pill pgto_pill_ok">sim</span>
              <?php if (!empty($log['processado_em'])): ?>
                <small class="pgto_muted">em <?= htmlspecialchars($log['processado_em']) ?></small>
              <?php endif; ?>
            <?php else: ?>
              <span class="pgto_pill pgto_pill_warn">não</span>
            <?php endif; ?>
          </dd>

          <dt>Assinatura Ed25519</dt>
          <dd>
            <?php if ((int) $log['assinatura_valida'] === 1): ?>
              <span class="pgto_pill pgto_pill_ok">válida</span>
            <?php elseif ((int) $log['assinatura_valida'] === 0): ?>
              <span class="pgto_pill pgto_pill_err">inválida — request rejeitada</span>
            <?php else: ?>
              <span class="pgto_muted">não verificada</span>
            <?php endif; ?>
          </dd>

          <dt>Tentativas</dt>
          <dd><?= (int) $log['tentativas'] ?></dd>

          <?php if (!empty($log['erro'])): ?>
            <dt>Último erro</dt>
            <dd>
              <div class="pgto_detail_warning">
                <?= htmlspecialchars($log['erro']) ?>
              </div>
            </dd>
          <?php endif; ?>
        </dl>
      </div>

      <!-- Identificação -->
      <div class="pgto_card">
        <h3 class="pgto_card_title">Identificação</h3>
        <dl class="pgto_dl">
          <dt>event_id</dt> <dd><code><?= htmlspecialchars($log['event_id']) ?></code></dd>
          <dt>tipo</dt>     <dd><code><?= htmlspecialchars($log['tipo']) ?></code></dd>
          <dt>charge_id</dt>
          <dd>
            <?php if (!empty($log['charge_id'])): ?>
              <code><?= htmlspecialchars($log['charge_id']) ?></code>
            <?php else: ?>
              <span class="pgto_muted">—</span>
            <?php endif; ?>
          </dd>
          <dt>IP de origem</dt>
          <dd><code><?= htmlspecialchars($log['ip_origem'] ?? '—') ?></code></dd>
        </dl>
      </div>

      <!-- Resultado do reprocesso (aparece após click) -->
      <div id="reprocesso-resultado" hidden></div>

    </div>

    <div class="pgto_detail_col">
      <!-- Payload -->
      <div class="pgto_card">
        <h3 class="pgto_card_title">Payload recebido</h3>
        <?php if (!empty($log['payload_decoded'])): ?>
          <pre class="pgto_json"><?= htmlspecialchars(json_encode($log['payload_decoded'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
        <?php else: ?>
          <pre class="pgto_json"><?= htmlspecialchars($log['payload'] ?? '') ?></pre>
        <?php endif; ?>
      </div>

      <?php if (!empty($log['assinatura_header'])): ?>
        <details class="pgto_card pgto_card_collapsible">
          <summary class="pgto_card_title">Header X-Plug-Signature <small class="pgto_muted">(clique para expandir)</small></summary>
          <pre class="pgto_json"><?= htmlspecialchars($log['assinatura_header']) ?></pre>
        </details>
      <?php endif; ?>
    </div>

  </div>
</div>

<?php if ($podeReprocessar): ?>
<script>
(function() {
  var btn  = document.getElementById('btn-reprocessar');
  var out  = document.getElementById('reprocesso-resultado');
  if (!btn) return;

  btn.addEventListener('click', function() {
    if (!confirm('Reprocessar este webhook? A lógica de domínio (atualizar pedido, liberar estoque, etc.) será executada.')) {
      return;
    }
    btn.disabled = true;
    btn.textContent = 'Reprocessando…';

    var form = new FormData();
    <?php if (class_exists('SecurityHelper')): ?>
    form.append('<?= htmlspecialchars(SecurityHelper::csrfFieldName()) ?>',
                '<?= htmlspecialchars(SecurityHelper::csrfToken()) ?>');
    <?php endif; ?>

    fetch('<?= $base ?>/admin/payment/webhooks/<?= (int) $log['id'] ?>/reprocessar', {
      method: 'POST',
      body: form,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(r) { return r.json(); })
    .then(function(resp) {
      btn.disabled = false;
      btn.textContent = 'Reprocessar';
      out.hidden = false;
      out.className = resp.ok ? 'pgto_alerta pgto_alerta_ok' : 'pgto_alerta pgto_alerta_erro';
      out.textContent = resp.msg || (resp.ok ? 'Reprocessado.' : 'Falhou.');
      if (resp.ok) {
        setTimeout(function() { window.location.reload(); }, 2000);
      }
    })
    .catch(function() {
      btn.disabled = false;
      btn.textContent = 'Reprocessar';
      out.hidden = false;
      out.className = 'pgto_alerta pgto_alerta_erro';
      out.textContent = 'Erro de comunicação. Tente novamente.';
    });
  });
})();
</script>
<?php endif; ?>
