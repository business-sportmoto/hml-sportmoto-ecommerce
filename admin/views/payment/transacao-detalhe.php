<?php
/**
 * admin/views/admin/payment/transacao-detalhe.php
 *
 * Variáveis:
 *   $tx → array da transação (com raw_request_decoded, raw_response_decoded, webhooks[])
 */
$base = defined('BASE_URL') ? BASE_URL : '';
require_once __DIR__ . '/_helpers.php';

$podeEstornar = ($tx['status'] === 'aprovado') && !empty($tx['charge_id']);
$valorReais = number_format(((int) $tx['valor_centavos']) / 100, 2, '.', '');
?>


<div class="pgto_wrapper" data-base="<?= htmlspecialchars($base) ?>" data-tx-id="<?= (int) $tx['id'] ?>">

  <!-- Cabeçalho -->
  <div class="pgto_header">
    <div>
      <h1>Transação #<?= (int) $tx['id'] ?></h1>
      <p class="pgto_sub">
        <code><?= htmlspecialchars($tx['order_id_loja']) ?></code>
        · <?= htmlspecialchars(ucfirst($tx['metodo'])) ?>
        · <strong><?= pgto_money((int) $tx['valor_centavos']) ?></strong>
      </p>
    </div>
    <div class="pgto_actions">
      <a href="<?= $base ?>/admin/payment/transacoes" class="pgto_btn pgto_btn_ghost">← Voltar</a>
      <?php if (!empty($tx['charge_id'])): ?>
        <button type="button" class="pgto_btn" id="btn-consultar-gateway"
                title="Consulta o status atual direto no gateway Malga">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="23 4 23 10 17 10"/>
            <polyline points="1 20 1 14 7 14"/>
            <path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/>
          </svg>
          Consultar gateway
        </button>
      <?php endif; ?>
      <?php if ($podeEstornar): ?>
        <button type="button" class="pgto_btn pgto_btn_danger" id="btn-abrir-estorno">
          Estornar
        </button>
      <?php endif; ?>
    </div>
  </div>

  <div class="pgto_detail_grid">

    <!-- Coluna esquerda: dados principais -->
    <div class="pgto_detail_col">

      <!-- Status atual -->
      <div class="pgto_card">
        <h3 class="pgto_card_title">Status</h3>
        <div class="pgto_status_big pgto_status_<?= htmlspecialchars($tx['status']) ?>">
          <?= pgto_status_label((string) $tx['status']) ?>
        </div>
        <?php if (!empty($tx['declined_message'])): ?>
          <div class="pgto_detail_row pgto_detail_warning">
            <strong>Motivo:</strong>
            <?= htmlspecialchars($tx['declined_message']) ?>
            <?php if (!empty($tx['declined_code'])): ?>
              <small class="pgto_muted">(código: <?= htmlspecialchars($tx['declined_code']) ?>)</small>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <div id="consulta-resultado" style="display:none; margin-top:12px;"></div>
      </div>

      <!-- Identificação -->
      <div class="pgto_card">
        <h3 class="pgto_card_title">Identificação</h3>
        <dl class="pgto_dl">
          <dt>order_id_loja</dt> <dd><code><?= htmlspecialchars($tx['order_id_loja']) ?></code></dd>
          <dt>charge_id (Malga)</dt>
          <dd>
            <?php if (!empty($tx['charge_id'])): ?>
              <code><?= htmlspecialchars($tx['charge_id']) ?></code>
            <?php else: ?>
              <span class="pgto_muted">(não foi criado no gateway)</span>
            <?php endif; ?>
          </dd>
          <dt>pedido_id</dt> <dd><?= htmlspecialchars((string) ($tx['pedido_id'] ?? '—')) ?></dd>
          <dt>cliente_id</dt> <dd><?= htmlspecialchars((string) ($tx['cliente_id'] ?? '—')) ?></dd>
          <dt>gateway</dt>
          <dd><?= htmlspecialchars($tx['gateway_nome']) ?> <small class="pgto_muted">(<?= htmlspecialchars($tx['gateway_codigo']) ?>)</small></dd>
          <dt>provedor real</dt> <dd><?= htmlspecialchars($tx['provedor_real'] ?? '—') ?></dd>
        </dl>
      </div>

      <!-- Pagamento -->
      <div class="pgto_card">
        <h3 class="pgto_card_title">Pagamento</h3>
        <dl class="pgto_dl">
          <dt>Método</dt> <dd><?= htmlspecialchars(ucfirst($tx['metodo'])) ?></dd>
          <?php if ($tx['metodo'] === 'cartao' && !empty($tx['parcelas'])): ?>
            <dt>Parcelas</dt> <dd><?= (int) $tx['parcelas'] ?>x</dd>
          <?php endif; ?>
          <dt>Valor</dt> <dd class="num"><?= pgto_money((int) $tx['valor_centavos']) ?></dd>
          <dt>Moeda</dt> <dd><?= htmlspecialchars($tx['moeda']) ?></dd>

          <?php if ($tx['metodo'] === 'pix' && !empty($tx['pix_qrcode'])): ?>
            <dt>PIX copia-e-cola</dt>
            <dd>
              <code class="pgto_code_block"><?= htmlspecialchars($tx['pix_qrcode']) ?></code>
              <?php if (!empty($tx['pix_expira_em'])): ?>
                <br><small class="pgto_muted">Expira em <?= date('d/m/Y H:i', strtotime($tx['pix_expira_em'])) ?></small>
              <?php endif; ?>
            </dd>
          <?php endif; ?>

          <?php if ($tx['metodo'] === 'boleto' && !empty($tx['boleto_linha_digitavel'])): ?>
            <dt>Boleto — linha digitável</dt>
            <dd><code><?= htmlspecialchars($tx['boleto_linha_digitavel']) ?></code></dd>
            <?php if (!empty($tx['boleto_pdf_url'])): ?>
              <dt>Boleto — URL</dt>
              <dd><a href="<?= htmlspecialchars($tx['boleto_pdf_url']) ?>" target="_blank" rel="noopener">abrir boleto ↗</a></dd>
            <?php endif; ?>
            <?php if (!empty($tx['boleto_vencimento'])): ?>
              <dt>Vencimento</dt>
              <dd><?= date('d/m/Y', strtotime($tx['boleto_vencimento'])) ?></dd>
            <?php endif; ?>
          <?php endif; ?>
        </dl>
      </div>

      <!-- Linha do tempo -->
      <div class="pgto_card">
        <h3 class="pgto_card_title">Linha do tempo</h3>
        <ul class="pgto_timeline">
          <li>
            <span class="pgto_tl_dot"></span>
            <div>
              <strong>Criada</strong>
              <span class="pgto_muted"><?= htmlspecialchars($tx['criado_em']) ?></span>
            </div>
          </li>
          <?php if (!empty($tx['pago_em'])): ?>
            <li>
              <span class="pgto_tl_dot pgto_tl_dot_ok"></span>
              <div>
                <strong>Aprovada</strong>
                <span class="pgto_muted"><?= htmlspecialchars($tx['pago_em']) ?></span>
              </div>
            </li>
          <?php endif; ?>
          <li>
            <span class="pgto_tl_dot pgto_tl_dot_atual"></span>
            <div>
              <strong>Última atualização</strong>
              <span class="pgto_muted"><?= htmlspecialchars($tx['atualizado_em']) ?></span>
            </div>
          </li>
        </ul>
      </div>

    </div>

    <!-- Coluna direita: webhooks e raw -->
    <div class="pgto_detail_col">

      <!-- Webhooks relacionados -->
      <div class="pgto_card">
        <h3 class="pgto_card_title">Webhooks relacionados <small class="pgto_muted">(<?= count($tx['webhooks']) ?>)</small></h3>
        <?php if (empty($tx['webhooks'])): ?>
          <p class="pgto_muted">Nenhum webhook recebido pra essa charge ainda.</p>
        <?php else: ?>
          <table class="pgto_table pgto_table_compact">
            <thead>
              <tr>
                <th>Tipo</th>
                <th>Processado?</th>
                <th>Recebido</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($tx['webhooks'] as $wh): ?>
                <tr>
                  <td><code><?= htmlspecialchars($wh['tipo']) ?></code></td>
                  <td>
                    <?php if ((int) $wh['processado'] === 1): ?>
                      <span class="pgto_pill pgto_pill_ok">processado</span>
                    <?php elseif (!empty($wh['erro'])): ?>
                      <span class="pgto_pill pgto_pill_err" title="<?= htmlspecialchars($wh['erro']) ?>">erro</span>
                    <?php else: ?>
                      <span class="pgto_pill pgto_pill_warn">pendente</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span title="<?= htmlspecialchars($wh['recebido_em']) ?>">
                      <?= date('d/m H:i:s', strtotime($wh['recebido_em'])) ?>
                    </span>
                  </td>
                  <td class="actions">
                    <a href="<?= $base ?>/admin/payment/webhooks/<?= (int) $wh['id'] ?>"
                       class="pgto_link_chevron">ver
                      <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                           stroke-width="2.5" stroke-linecap="round"><polyline points="9 18 15 12 9 6"/></svg>
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

      <!-- Raw request/response -->
      <?php foreach (['raw_response_decoded' => 'Resposta do gateway', 'raw_request_decoded' => 'Request enviado'] as $key => $label): ?>
        <?php if (!empty($tx[$key])): ?>
          <details class="pgto_card pgto_card_collapsible">
            <summary class="pgto_card_title"><?= $label ?> <small class="pgto_muted">(clique para expandir)</small></summary>
            <pre class="pgto_json"><?= htmlspecialchars(json_encode($tx[$key], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
          </details>
        <?php endif; ?>
      <?php endforeach; ?>

    </div>
  </div>
</div>

<?php if (!empty($tx['charge_id'])): ?>
<script>
  BASE_TRANSACAO_DETALHE = '<?= $base ?>/admin/payment/transacoes/<?= (int) $tx['id'] ?>';
</script>
<?php endif; ?>

<!-- ─────────── Modal de Estorno ─────────── -->
<?php if ($podeEstornar): ?>
<div class="pgto_modal" id="modal-estorno" hidden>
  <div class="pgto_modal_overlay" data-close></div>
  <div class="pgto_modal_box" role="dialog" aria-labelledby="estorno-title">
    <button type="button" class="pgto_modal_close" data-close aria-label="Fechar">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
    <h2 id="estorno-title">Estornar transação</h2>
    <p class="pgto_modal_sub">
      Valor total da transação:
      <strong><?= pgto_money((int) $tx['valor_centavos']) ?></strong>
    </p>

    <form id="form-estorno" autocomplete="off">
      <?php /* CSRF: usa o helper do projeto */ ?>
      <?php if (class_exists('SecurityHelper')) echo SecurityHelper::csrfField(); ?>

      <div class="pgto_form_field">
        <label for="estorno-tipo">Tipo</label>
        <select id="estorno-tipo">
          <option value="total">Estorno total (<?= pgto_money((int) $tx['valor_centavos']) ?>)</option>
          <option value="parcial">Estorno parcial</option>
        </select>
      </div>

      <div class="pgto_form_field" id="campo-valor-parcial" hidden>
        <label for="estorno-valor">Valor a estornar (R$)</label>
        <input type="number" id="estorno-valor" name="valor"
               step="0.01" min="0.01" max="<?= $valorReais ?>"
               placeholder="0,00">
        <small class="pgto_muted">Máximo: <?= pgto_money((int) $tx['valor_centavos']) ?></small>
      </div>

      <div class="pgto_form_field">
        <label for="estorno-motivo">Motivo <small class="pgto_muted">(obrigatório, mín. 5 chars)</small></label>
        <textarea id="estorno-motivo" name="motivo" rows="3" minlength="5" maxlength="500"
                  placeholder="Ex: cliente solicitou cancelamento por arrependimento"></textarea>
      </div>

      <div id="estorno-erro" class="pgto_alerta pgto_alerta_erro" hidden></div>
      <div id="estorno-ok"   class="pgto_alerta pgto_alerta_ok"   hidden></div>

      <div class="pgto_modal_actions">
        <button type="button" class="pgto_btn pgto_btn_ghost" data-close>Cancelar</button>
        <button type="submit" class="pgto_btn pgto_btn_danger" id="btn-confirmar-estorno">
          Confirmar estorno
        </button>
      </div>
    </form>
  </div>
</div>

<script>
(function() {
  
})();
</script>
<?php endif; ?>