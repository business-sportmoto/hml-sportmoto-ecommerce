<?php
// admin/views/estoque/canais.php
// Variáveis: $canais
//
// A dimensão que o site não tem: `pedidos.canal` só conhece site|app|pedido.
// Aqui mora o de/para entre a loja do Bling e o nome do marketplace.
?>
<div class="ap-page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div>
    <h1 style="font-size:22px;font-weight:800;margin:0;">Canais de venda</h1>
    <p style="margin:4px 0 0;color:var(--text-2);font-size:13px;">
      De/para entre a loja do Bling (<code>pedido.loja.id</code>) e o nome do canal.
      É o que permite responder "quanto este SKU vendeu no Mercado Livre".
    </p>
  </div>
  <a href="<?= ADMIN_URL ?>/estoque/ponte" class="btn">← Voltar para a ponte</a>
</div>

<div style="display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:20px;margin-top:18px;align-items:start;">

  <div style="overflow-x:auto;">
    <table class="table" style="width:100%;border-collapse:collapse;font-size:13px;">
      <thead>
        <tr style="text-align:left;border-bottom:1px solid var(--border);">
          <th style="padding:8px;">Loja no Bling</th>
          <th style="padding:8px;">Nome do canal</th>
          <th style="padding:8px;text-align:right;">Movimentos</th>
          <th style="padding:8px;">Ativo</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$canais): ?>
        <tr><td colspan="4" style="padding:26px;text-align:center;color:var(--text-2);">
          Nenhum canal cadastrado.
        </td></tr>
      <?php endif; ?>
      <?php foreach ($canais as $c): ?>
        <tr style="border-bottom:1px solid var(--border);">
          <td style="padding:8px;"><code><?= (int)$c['bling_loja_id'] ?></code></td>
          <td style="padding:8px;"><?= htmlspecialchars((string)$c['nome']) ?></td>
          <td style="padding:8px;text-align:right;"><?= (int)$c['movimentos'] ?></td>
          <td style="padding:8px;"><?= ((int)$c['ativo'] === 1) ? 'sim' : 'não' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <p style="font-size:12px;color:var(--text-2);margin-top:12px;">
      A loja <code>0</code> é o pedido sem loja vinculada no Bling. Ela tem rótulo de
      propósito: sem isso a análise mostra "sem canal" e ninguém entende o que é.
    </p>
  </div>

  <form id="form-canal" style="background:var(--surface);border:1px solid var(--border);
        border-radius:10px;padding:16px;">
    <h3 style="margin:0 0 12px;font-size:15px;">Cadastrar ou renomear</h3>

    <label style="display:block;font-size:12px;color:var(--text-2);margin-bottom:4px;">
      Id da loja no Bling
    </label>
    <input type="number" name="bling_loja_id" min="0" required class="input"
           style="width:100%;margin-bottom:12px;">

    <label style="display:block;font-size:12px;color:var(--text-2);margin-bottom:4px;">
      Nome do canal
    </label>
    <input type="text" name="nome" maxlength="80" required class="input"
           placeholder="Mercado Livre" style="width:100%;margin-bottom:14px;">

    <button type="submit" class="btn btn-primary" style="width:100%;">Salvar</button>

    <p style="font-size:11px;color:var(--text-3);margin:12px 0 0;">
      Id já cadastrado é renomeado, não duplicado.
    </p>
  </form>
</div>

<script>
(function () {
  $('#form-canal').on('submit', function (e) {
    e.preventDefault();
    var $f = $(this), $b = $f.find('button[type=submit]');
    $b.prop('disabled', true);

    $.post(ADMIN_URL + '/estoque/ponte/canais',
      $f.serialize() + '&_csrf_token=' + encodeURIComponent(CSRF_TOKEN),
      function (res) {
        if (window.Toast) {
          window.Toast[res.ok ? 'success' : 'error'](res.msg);
        } else {
          alert(res.msg);
        }
        if (res.ok) setTimeout(function () { location.reload(); }, 800);
        else $b.prop('disabled', false);
      }, 'json'
    ).fail(function () {
      if (window.Toast) window.Toast.error('Falha ao falar com o servidor.');
      $b.prop('disabled', false);
    });
  });
})();
</script>
