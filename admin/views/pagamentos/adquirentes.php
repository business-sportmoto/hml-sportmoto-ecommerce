<?php
// admin/views/pagamentos/adquirentes.php
// $adquirentes e $suportadas injetados pelo AdminPagamentoConfigController
//
// SEGREDOS: os campos de chave chegam aqui SEM valor — o model já os removeu.
// A tela mostra apenas "configurado" ou "pendente". Um segredo renderizado num
// input acaba em cache do navegador, em print de suporte e no autocomplete.
?>

<div class="admin-page">

  <div class="admin-page-header">
    <div>
      <a href="<?= ADMIN_URL ?>/configuracoes" class="back-link">← Configurações</a>
      <h1 class="admin-page-title">Adquirentes</h1>
      <p class="admin-page-sub">
        Quem processa o pagamento. As credenciais ficam aqui; as regras comerciais
        ficam em Formas de pagamento; e a ordem em que cada uma é tentada, no fluxo.
      </p>
    </div>
    <a href="<?= ADMIN_URL ?>/pagamentos/formas" class="btn btn-outline">← Formas de pagamento</a>
  </div>

  <div class="admin-card" style="margin-bottom:18px;padding:14px 20px;background:#fffbeb;border-color:#fde68a;">
    <div style="font-size:12.5px;color:#92400e;">
      <strong>Chaves nunca são exibidas.</strong> Deixe o campo em branco para manter a
      chave atual — preencher só é necessário quando você quer trocá-la.
    </div>
  </div>

  <?php foreach ($adquirentes as $a):
      $configurada = !empty($a['merchant_id']) && !empty($a['api_key_preenchido']);
      $emUso       = !empty($a['fluxos']);
  ?>
  <form class="admin-card form-adquirente" data-id="<?= (int) $a['id'] ?>"
        data-codigo="<?= View::e($a['codigo']) ?>" style="margin-bottom:16px;padding:20px;">
    <?= SecurityHelper::csrfField() ?>
    <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">

    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:18px;">
      <div>
        <div style="display:flex;align-items:center;gap:10px;">
          <h3 style="margin:0;font-size:16px;"><?= View::e($a['nome']) ?></h3>

          <?php if ($a['ativo']): ?>
            <span style="background:#f0fdf4;color:#15803d;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700;">ATIVA</span>
          <?php else: ?>
            <span style="background:#f8fafc;color:#64748b;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700;">INATIVA</span>
          <?php endif; ?>

          <?php if (!empty($a['sandbox'])): ?>
            <span style="background:#fffbeb;color:#92400e;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700;">SANDBOX</span>
          <?php endif; ?>

          <?php if (empty($a['tem_adapter'])): ?>
            <span style="background:#fef2f2;color:#b91c1c;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700;"
                  title="Não existe adapter implementado para esta adquirente">SEM ADAPTER</span>
          <?php endif; ?>
        </div>

        <div style="font-size:11.5px;color:var(--c-text-muted);margin-top:4px;">
          código <code><?= View::e($a['codigo']) ?></code>
          · credenciais: <?= $configurada
              ? '<span style="color:#15803d;font-weight:600;">configuradas</span>'
              : '<span style="color:#b45309;font-weight:600;">pendentes</span>' ?>
        </div>

        <?php if ($emUso): ?>
        <div style="font-size:11.5px;color:#1d4ed8;margin-top:6px;">
          Em uso no fluxo:
          <?= View::e(implode(', ', array_column($a['fluxos'], 'nome'))) ?>
        </div>
        <?php endif; ?>
      </div>

      <div style="display:flex;gap:8px;align-items:center;">
        <button type="button" class="btn btn-outline btn-testar">Testar conexão</button>
        <button type="button" class="btn <?= $a['ativo'] ? 'btn-outline' : 'btn-primary' ?> btn-alternar"
                data-ativar="<?= $a['ativo'] ? '0' : '1' ?>">
          <?= $a['ativo'] ? 'Desativar' : 'Ativar' ?>
        </button>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px;">
      <div class="form-group">
        <label>Nome de exibição</label>
        <input type="text" name="nome" class="form-control" value="<?= View::e($a['nome']) ?>">
      </div>
      <div class="form-group">
        <label>Merchant ID</label>
        <input type="text" name="merchant_id" class="form-control"
               value="<?= View::e($a['merchant_id'] ?? '') ?>" autocomplete="off">
      </div>
      <div class="form-group">
        <label>Client ID</label>
        <input type="text" name="client_id" class="form-control"
               value="<?= View::e($a['client_id'] ?? '') ?>" autocomplete="off">
      </div>

      <div class="form-group">
        <label>
          Chave de API
          <?php if (!empty($a['api_key_preenchido'])): ?>
            <span style="color:#15803d;font-size:11px;">• já configurada</span>
          <?php endif; ?>
        </label>
        <input type="password" name="api_key" class="form-control" autocomplete="new-password"
               placeholder="<?= !empty($a['api_key_preenchido']) ? 'deixe em branco para manter' : 'obrigatória' ?>">
      </div>

      <div class="form-group">
        <label>
          Segredo do webhook
          <?php if (!empty($a['webhook_secret_preenchido'])): ?>
            <span style="color:#15803d;font-size:11px;">• já configurado</span>
          <?php endif; ?>
        </label>
        <input type="password" name="webhook_secret" class="form-control" autocomplete="new-password"
               placeholder="<?= !empty($a['webhook_secret_preenchido']) ? 'deixe em branco para manter' : 'opcional' ?>">
      </div>

      <div class="form-group">
        <label>URL de notificação</label>
        <input type="text" name="webhook_endpoint" class="form-control"
               value="<?= View::e($a['webhook_endpoint'] ?? '') ?>"
               placeholder="<?= View::e(BASE_URL) ?>/webhooks/<?= View::e($a['codigo']) ?>">
      </div>
    </div>

    <label class="check-label" style="margin-top:14px;">
      <input type="checkbox" name="sandbox" value="1" <?= !empty($a['sandbox']) ? 'checked' : '' ?>>
      <span class="check-custom"></span> Ambiente de testes (sandbox)
    </label>

    <div style="display:flex;align-items:center;gap:12px;margin-top:16px;">
      <button type="submit" class="btn btn-primary">Salvar</button>
      <span class="form-feedback" style="font-size:12.5px;"></span>
    </div>
  </form>
  <?php endforeach; ?>

  <?php if (!$adquirentes): ?>
  <div class="admin-card" style="padding:40px;text-align:center;color:var(--c-text-muted);">
    Nenhuma adquirente cadastrada. Rode <code>migration-pagamentos.sql</code>.
  </div>
  <?php endif; ?>

</div>

<script>
(function () {
  'use strict';
  var BASE = '<?= ADMIN_URL ?>';

  function post(url, form, extra) {
    var fd = new FormData(form);
    Object.keys(extra || {}).forEach(function (k) { fd.append(k, extra[k]); });
    return fetch(BASE + url, {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (r) { return r.json(); });
  }

  function aviso(ok, msg) {
    if (window.Toast) { ok ? Toast.success(msg) : Toast.error(msg); }
    else { alert(msg); }
  }

  document.querySelectorAll('.form-adquirente').forEach(function (form) {
    var feedback = form.querySelector('.form-feedback');

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = form.querySelector('button[type="submit"]');
      btn.disabled = true;

      post('/pagamentos/adquirentes/salvar', form).then(function (res) {
        btn.disabled = false;
        aviso(res.ok, res.msg || 'Não foi possível salvar.');
        feedback.textContent = res.msg || '';
        feedback.style.color = res.ok ? '#15803d' : '#b91c1c';
        // Limpa os campos de segredo depois de salvar: o valor digitado não
        // deve continuar na tela nem no histórico do formulário.
        if (res.ok) {
          form.querySelectorAll('input[type="password"]').forEach(function (i) { i.value = ''; });
        }
      }).catch(function () {
        btn.disabled = false;
        aviso(false, 'Erro de conexão.');
      });
    });

    form.querySelector('.btn-testar').addEventListener('click', function () {
      var b = this;
      b.disabled = true;
      var txt = b.textContent;
      b.textContent = 'Testando...';

      post('/pagamentos/adquirentes/testar', form).then(function (res) {
        b.disabled = false; b.textContent = txt;
        aviso(res.ok, res.msg);
        feedback.textContent = res.msg || '';
        feedback.style.color = res.ok ? '#15803d' : '#b91c1c';
      }).catch(function () {
        b.disabled = false; b.textContent = txt;
        aviso(false, 'Erro de conexão.');
      });
    });

    form.querySelector('.btn-alternar').addEventListener('click', function () {
      var b = this;
      var ativar = b.getAttribute('data-ativar');
      b.disabled = true;

      post('/pagamentos/adquirentes/alternar', form, { ativar: ativar }).then(function (res) {
        b.disabled = false;

        // Desativar adquirente em uso num fluxo publicado pede confirmação:
        // pode interromper pagamentos em andamento.
        if (!res.ok && res.confirmar) {
          if (!confirm(res.msg)) return;
          b.disabled = true;
          return post('/pagamentos/adquirentes/alternar', form,
                      { ativar: ativar, confirmado: '1' }).then(function (r2) {
            b.disabled = false;
            aviso(r2.ok, r2.msg);
            if (r2.ok) location.reload();
          });
        }

        aviso(res.ok, res.msg);
        if (res.ok) location.reload();
      }).catch(function () {
        b.disabled = false;
        aviso(false, 'Erro de conexão.');
      });
    });
  });
})();
</script>
