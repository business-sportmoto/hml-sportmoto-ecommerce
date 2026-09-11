<?php
// admin/views/pagamentos/analise-detalhe.php
// $pedido, $antifraude, $tentativas, $transacao — do AdminAnaliseController

$modo      = (string) ($antifraude['modo'] ?? 'pos_captura');
$capturado = $transacao && ($transacao['status'] ?? '') === 'aprovado';
$tier      = (string) ($pedido['tier'] ?? 'bronze');
?>

<div class="admin-page">

  <div class="admin-page-header">
    <div>
      <a href="<?= ADMIN_URL ?>/pagamentos/analise" class="back-link">← Fila de análise</a>
      <h1 class="admin-page-title">Pedido <?= View::e($pedido['codigo']) ?></h1>
      <p class="admin-page-sub">
        R$ <?= number_format((float) $pedido['total'], 2, ',', '.') ?>
        · <?= View::e($pedido['forma_pagamento'] ?? '—') ?>
        · <?= date('d/m/Y H:i', strtotime((string) $pedido['criado_em'])) ?>
      </p>
    </div>
  </div>

  <!-- Por que foi retido -->
  <div class="admin-card" style="margin-bottom:16px;padding:20px;border-left:3px solid var(--warning);">
    <h3 style="margin:0 0 10px;font-size:14px;">Por que este pedido foi retido</h3>
    <?php if ($antifraude): ?>
      <div style="font-size:13px;margin-bottom:6px;">
        <strong><?= View::e($antifraude['regra_aplicada'] ?? '—') ?></strong>
        — <?= View::e($antifraude['motivo_pre'] ?? '') ?>
      </div>
      <div style="font-size:12px;color:var(--c-text-muted);">
        Score no momento da decisão: <?= (int) ($antifraude['score_cliente'] ?? 0) ?> pts
        (<?= View::e($antifraude['tier_cliente'] ?? '—') ?>)
        <?php if (!empty($antifraude['enviado_em'])): ?>
          · ClearSale respondeu <strong><?= View::e($antifraude['recomendacao'] ?? '—') ?></strong>
          <?php if ($antifraude['score'] !== null): ?>
            com score <?= number_format((float) $antifraude['score'], 0) ?>
          <?php endif; ?>
        <?php else: ?>
          · <em>sem consulta à ClearSale</em>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div style="font-size:13px;color:var(--c-text-muted);">
        Não há registro de antifraude para este pedido.
      </div>
    <?php endif; ?>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">

    <!-- Cliente -->
    <div class="admin-card" style="padding:20px;">
      <h3 style="margin:0 0 12px;font-size:14px;">Cliente</h3>
      <div style="font-size:13px;margin-bottom:10px;">
        <strong><?= View::e($pedido['cliente_nome'] ?? '—') ?></strong><br>
        <span style="color:var(--c-text-muted);"><?= View::e($pedido['cliente_email'] ?? '') ?></span>
      </div>
      <table style="width:100%;font-size:12.5px;">
        <tr><td style="color:var(--c-text-muted);padding:3px 0;">Score</td>
            <td class="num"><strong><?= (int) ($pedido['score_total'] ?? 0) ?></strong>
              <?php if ((int) ($pedido['penalidade_pontos'] ?? 0) > 0): ?>
                <span style="color:var(--danger);">− <?= (int) $pedido['penalidade_pontos'] ?> de penalidade</span>
              <?php endif; ?>
              (<?= strtoupper($tier) ?>)</td></tr>
        <tr><td style="color:var(--c-text-muted);padding:3px 0;">Pedidos</td>
            <td class="num"><?= (int) ($pedido['total_pedidos'] ?? 0) ?>
              (<?= (int) ($pedido['total_pedidos_concluidos'] ?? 0) ?> concluídos)</td></tr>
        <tr><td style="color:var(--c-text-muted);padding:3px 0;">Devoluções</td>
            <td class="num"><?= (int) ($pedido['total_devolucoes'] ?? 0) ?></td></tr>
        <tr><td style="color:var(--c-text-muted);padding:3px 0;">Chargebacks</td>
            <td class="num" style="<?= (int) ($pedido['total_chargebacks'] ?? 0) > 0 ? 'color:#b91c1c;font-weight:700;' : '' ?>">
              <?= (int) ($pedido['total_chargebacks'] ?? 0) ?></td></tr>
        <tr><td style="color:var(--c-text-muted);padding:3px 0;">Conta criada há</td>
            <td class="num"><?= (int) ($pedido['dias_conta'] ?? 0) ?> dias</td></tr>
      </table>
      <?php if (!empty($pedido['fraude_confirmada'])): ?>
        <div style="margin-top:10px;padding:8px 12px;background:var(--danger-lt);border-radius:6px;
                    color:var(--danger);font-size:12px;font-weight:700;">
          Cliente marcado com fraude confirmada.
        </div>
      <?php endif; ?>
    </div>

    <!-- Pagamento -->
    <div class="admin-card" style="padding:20px;">
      <h3 style="margin:0 0 12px;font-size:14px;">Pagamento</h3>
      <?php if ($transacao): ?>
        <table style="width:100%;font-size:12.5px;">
          <tr><td style="color:var(--c-text-muted);padding:3px 0;">Adquirente</td>
              <td class="num"><?= View::e($transacao['gateway_nome'] ?? $transacao['gateway_codigo'] ?? '—') ?></td></tr>
          <tr><td style="color:var(--c-text-muted);padding:3px 0;">Status</td>
              <td class="num"><strong><?= View::e($transacao['status']) ?></strong></td></tr>
          <tr><td style="color:var(--c-text-muted);padding:3px 0;">Parcelas</td>
              <td class="num"><?= (int) ($transacao['parcelas'] ?? 1) ?>x</td></tr>
          <tr><td style="color:var(--c-text-muted);padding:3px 0;">Cobrança</td>
              <td class="num" style="font-size:11px;"><?= View::e($transacao['charge_id'] ?? '—') ?></td></tr>
        </table>
      <?php else: ?>
        <p style="font-size:12.5px;color:var(--c-text-muted);">Sem transação registrada.</p>
      <?php endif; ?>

      <!-- A consequência financeira da recusa, explícita ANTES do clique -->
      <div style="margin-top:12px;padding:10px 12px;border-radius:6px;font-size:12px;
                  background:<?= $capturado ? 'var(--danger-lt)' : 'var(--success-lt)' ?>;
                  color:<?= $capturado ? 'var(--danger)' : 'var(--success)' ?>;">
        <?php if ($capturado): ?>
          <strong>O valor já foi capturado.</strong> Recusar aqui dispara estorno
          na adquirente — com custo e prazo de devolução ao cliente.
        <?php else: ?>
          <strong>Valor não capturado</strong> (<?= View::e($modo) ?>).
          Recusar apenas cancela a autorização, sem custo.
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Tentativas -->
  <?php if ($tentativas): ?>
  <div class="admin-card" style="margin-bottom:16px;padding:0;">
    <h3 style="margin:0;padding:16px 20px 10px;font-size:14px;">Tentativas de pagamento</h3>
    <table class="admin-table" style="margin:0;">
      <thead><tr><th>#</th><th>Adquirente</th><th>Resultado</th><th>Motivo</th><th>Bandeira</th><th class="num">Tempo</th></tr></thead>
      <tbody>
      <?php foreach ($tentativas as $t): ?>
        <tr>
          <td><?= (int) $t['sequencia'] ?></td>
          <td><?= View::e($t['adquirente_codigo']) ?></td>
          <td><strong><?= View::e($t['resultado']) ?></strong></td>
          <td style="font-size:12px;">
            <?= View::e($t['classe_erro'] ?? '—') ?>
            <?php if (!empty($t['codigo_adquirente'])): ?>
              <span style="color:var(--c-text-muted);">(<?= View::e($t['codigo_adquirente']) ?>)</span>
            <?php endif; ?>
          </td>
          <td><?= View::e($t['bandeira'] ?? '—') ?></td>
          <td class="num" style="font-size:12px;color:var(--c-text-muted);"><?= (int) $t['duracao_ms'] ?>ms</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Decisão -->
  <?php
    // ClearSale: estado atual + botão. Consultar NÃO decide o pedido — só
    // traz o parecer para quem vai decidir, logo abaixo.
    $cs        = $clearsale ?? ['configurado' => false, 'ambiente' => 'sandbox'];
    $csProd    = ($cs['ambiente'] ?? '') === 'prod';
    $csEnviado = $antifraude && !empty($antifraude['enviado_em']) && ($antifraude['status'] ?? '') !== 'erro';
  ?>
  <div class="admin-card" style="margin-bottom:16px;padding:20px;" id="cs-card">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:10px;">
      <h3 style="margin:0;font-size:14px;">ClearSale</h3>
      <span class="badge <?= $csProd ? 'badge-danger' : 'badge-info' ?>">
        <?= $csProd ? 'Produção' : 'Homologação' ?>
      </span>
    </div>

    <div id="cs-resultado" style="font-size:13.5px;line-height:1.6;margin-bottom:12px;">
      <?php if ($csEnviado): ?>
        Último parecer: <strong><?= View::e($antifraude['codigo_status'] ?? '—') ?></strong>
        <?php if ($antifraude['score'] !== null): ?>
          · score <strong><?= number_format((float) $antifraude['score'], 0) ?></strong>
        <?php endif; ?>
        <?php if (!empty($antifraude['respondido_em'])): ?>
          <span style="color:var(--c-text-muted);">
            · <?= date('d/m/Y H:i', strtotime((string) $antifraude['respondido_em'])) ?>
          </span>
        <?php endif; ?>
      <?php elseif ($antifraude && ($antifraude['status'] ?? '') === 'erro'): ?>
        O último envio falhou: <?= View::e(mb_strimwidth((string) ($antifraude['motivo'] ?? ''), 0, 160, '…')) ?>
      <?php else: ?>
        Este pedido ainda não foi enviado à ClearSale.
      <?php endif; ?>
    </div>

    <?php if (empty($cs['configurado'])): ?>
      <p style="color:var(--danger);font-size:13px;margin:0;">Sem credencial da ClearSale configurada.</p>
    <?php else: ?>
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <button type="button" class="btn btn-outline" id="btn-clearsale"
                data-modo="<?= $csEnviado ? 'consulta' : 'envio' ?>"
                data-prod="<?= $csProd ? '1' : '0' ?>">
          <?= $csEnviado ? 'Consultar parecer' : 'Enviar à ClearSale' ?>
        </button>
        <span class="cs-feedback" style="font-size:12.5px;"></span>
      </div>
    <?php endif; ?>

    <p style="font-size:12px;color:var(--c-text-muted);margin:10px 0 0;">
      Consultar não libera nem recusa o pedido — a decisão continua sendo sua, logo abaixo.
      <?php if (!$csEnviado): ?>
        A ClearSale analisa depois de receber: o score aparece ao consultar de novo, alguns minutos após o envio.
      <?php endif; ?>
    </p>
  </div>

  <div class="admin-card" style="padding:20px;">
    <h3 style="margin:0 0 12px;font-size:14px;">Decisão</h3>
    <form id="form-decisao">
      <?= SecurityHelper::csrfField() ?>
      <input type="hidden" name="pedido_id" value="<?= (int) $pedido['id'] ?>">

      <div class="form-group">
        <label>Motivo <span style="color:var(--c-text-muted);font-weight:400;">— fica no histórico do pedido</span></label>
        <textarea name="motivo" class="form-control" rows="3"
                  placeholder="Ex.: cliente contatado por telefone, dados confirmados."></textarea>
      </div>

      <label class="check-label" style="margin:10px 0;">
        <input type="checkbox" name="fraude_confirmada" value="1">
        <span class="check-custom"></span>
        Marcar como <strong>fraude confirmada</strong> — zera o score e faz o cliente
        passar sempre pelo antifraude
      </label>

      <div style="display:flex;gap:10px;margin-top:14px;align-items:center;">
        <button type="button" class="btn btn-primary" id="btn-aprovar">Liberar pedido</button>
        <button type="button" class="btn btn-outline" id="btn-recusar"
                style="color:var(--danger);border-color:var(--danger-bd);">
          <?= $capturado ? 'Recusar e estornar' : 'Recusar' ?>
        </button>
        <span class="form-feedback" style="font-size:12.5px;"></span>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  'use strict';
  var form = document.getElementById('form-decisao');
  var fb   = form.querySelector('.form-feedback');
  var CAPTURADO = <?= $capturado ? 'true' : 'false' ?>;

  function enviar(url, botao) {
    var motivo = form.querySelector('[name="motivo"]').value.trim();
    if (motivo.length < 5) {
      fb.textContent = 'Descreva o motivo (mínimo 5 caracteres).';
      fb.style.color = 'var(--danger)';
      return;
    }

    form.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
    fb.textContent = 'Processando...';
    fb.style.color = 'var(--text-2)';

    fetch('<?= ADMIN_URL ?>' + url, {
      method: 'POST', body: new FormData(form), credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (r) { return r.json(); }).then(function (res) {
      form.querySelectorAll('button').forEach(function (b) { b.disabled = false; });
      fb.textContent = res.msg || '';
      fb.style.color = res.ok ? 'var(--success)' : 'var(--danger)';
      if (window.Toast) { res.ok ? Toast.success(res.msg) : Toast.error(res.msg); }
      if (res.ok) {
        setTimeout(function () { location.href = '<?= ADMIN_URL ?>/pagamentos/analise'; }, 1400);
      }
    }).catch(function () {
      form.querySelectorAll('button').forEach(function (b) { b.disabled = false; });
      fb.textContent = 'Erro de conexão.';
      fb.style.color = 'var(--danger)';
    });
  }

  document.getElementById('btn-aprovar').addEventListener('click', function () {
    if (!confirm('Liberar este pedido? Ele segue para separação.')) return;
    enviar('/pagamentos/analise/aprovar', this);
  });

  document.getElementById('btn-recusar').addEventListener('click', function () {
    // Confirmação diferente quando há dinheiro a devolver: o operador
    // precisa saber que está disparando um estorno, não só cancelando.
    var msg = CAPTURADO
      ? 'Recusar e ESTORNAR? O valor já foi capturado e será devolvido ao cliente.'
      : 'Recusar este pedido? A autorização será cancelada.';
    if (!confirm(msg)) return;
    enviar('/pagamentos/analise/recusar', this);
  });
})();
</script>

<script>
/* Botão da ClearSale. Não toca na decisão do pedido: só busca o parecer e o
   mostra no card. Em produção, o envio é uma consulta paga — daí a pergunta. */
(function () {
  'use strict';
  var btn = document.getElementById('btn-clearsale');
  if (!btn) return;

  var fb    = document.querySelector('#cs-card .cs-feedback');
  var saida = document.getElementById('cs-resultado');
  var token = document.querySelector('#form-decisao [name="_csrf_token"]');

  btn.addEventListener('click', function () {
    var envio = btn.getAttribute('data-modo') === 'envio';
    if (envio && btn.getAttribute('data-prod') === '1'
        && !confirm('Enviar à ClearSale em PRODUÇÃO? Cada envio é uma consulta cobrada.')) return;

    var dados = new FormData();
    dados.append('pedido_id', '<?= (int) $pedido['id'] ?>');
    dados.append('_csrf_token', token ? token.value : (window.CSRF_TOKEN || ''));

    btn.disabled = true;
    fb.textContent = envio ? 'Enviando…' : 'Consultando…';
    fb.style.color = 'var(--text-2)';

    fetch('<?= ADMIN_URL ?>/pagamentos/analise/clearsale', {
      method: 'POST', body: dados, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (r) { return r.json(); }).then(function (res) {
      btn.disabled = false;
      fb.textContent = '';

      // textContent, nunca innerHTML: o motivo pode trazer texto da API.
      saida.textContent = res.msg || 'Sem resposta.';
      saida.style.color = res.ok ? '' : 'var(--danger)';

      if (res.ok) {
        // Depois do primeiro envio, o próximo clique já é consulta de parecer.
        btn.setAttribute('data-modo', 'consulta');
        btn.textContent = 'Consultar parecer';
      }
      if (window.Toast) { res.ok ? Toast.success(res.msg) : Toast.error(res.msg); }
    }).catch(function () {
      btn.disabled = false;
      fb.textContent = 'Erro de conexão.';
      fb.style.color = 'var(--danger)';
    });
  });
})();
</script>
