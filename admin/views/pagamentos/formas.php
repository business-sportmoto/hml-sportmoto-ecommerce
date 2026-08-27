<?php
// admin/views/pagamentos/formas.php
// $metodos e $simulacoes injetados pelo AdminPagamentoConfigController

/** Centavos → "1.234,56" para os inputs de dinheiro. */
$dinheiro = static fn(?int $c): string => $c === null ? '' : number_format($c / 100, 2, ',', '.');

$rotulos = [
    'pix'            => ['icone' => 'Pix',    'cor' => '#0f766e', 'bg' => '#f0fdfa'],
    'cartao_credito' => ['icone' => 'Cartão', 'cor' => '#1d4ed8', 'bg' => '#eff6ff'],
    'boleto'         => ['icone' => 'Boleto', 'cor' => '#92400e', 'bg' => '#fffbeb'],
];
?>

<div class="admin-page">

  <div class="admin-page-header">
    <div>
      <a href="<?= ADMIN_URL ?>/configuracoes" class="back-link">← Configurações</a>
      <h1 class="admin-page-title">Formas de pagamento</h1>
      <p class="admin-page-sub">
        Política comercial da loja: taxa, desconto e parcelamento. Vale para qualquer
        adquirente que processar — quem processa é definido no fluxo de pagamento.
      </p>
    </div>
    <a href="<?= ADMIN_URL ?>/pagamentos/adquirentes" class="btn btn-outline">Adquirentes →</a>
  </div>

  <div class="admin-card" style="margin-bottom:18px;padding:14px 20px;">
    <div style="display:flex;flex-wrap:wrap;gap:20px;font-size:12.5px;color:var(--c-text-muted);">
      <span><strong style="color:var(--c-dark);">Taxa</strong> — juros repassados ao cliente nas parcelas com juros</span>
      <span><strong style="color:var(--c-dark);">Desconto</strong> — abatimento por escolher esta forma (ex.: 5% no Pix)</span>
      <span><strong style="color:var(--c-dark);">Teto de desconto</strong> — limite somando desconto do método + cupom</span>
      <span><strong style="color:var(--c-dark);">Parcela mínima</strong> — abaixo disso a opção não é oferecida</span>
    </div>
  </div>

  <?php foreach ($metodos as $m):
      $r   = $rotulos[$m['codigo']] ?? ['icone' => $m['nome'], 'cor' => '#475569', 'bg' => '#f8fafc'];
      $ehCartao = str_starts_with($m['codigo'], 'cartao');
  ?>
  <form class="admin-card form-metodo" data-codigo="<?= View::e($m['codigo']) ?>" style="margin-bottom:16px;padding:20px;">
    <?= SecurityHelper::csrfField() ?>
    <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">

    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:18px;">
      <div style="display:flex;align-items:center;gap:12px;">
        <span style="background:<?= $r['bg'] ?>;color:<?= $r['cor'] ?>;padding:6px 12px;border-radius:6px;font-weight:700;font-size:13px;">
          <?= View::e($r['icone']) ?>
        </span>
        <div>
          <input type="text" name="nome" value="<?= View::e($m['nome']) ?>"
                 class="form-control" style="font-weight:600;max-width:280px;">
          <div style="font-size:11.5px;color:var(--c-text-muted);margin-top:3px;">
            código <code><?= View::e($m['codigo']) ?></code>
          </div>
        </div>
      </div>

      <label class="check-label" style="margin:0;">
        <input type="checkbox" name="ativo" value="1" <?= $m['ativo'] ? 'checked' : '' ?>>
        <span class="check-custom"></span> Ativa no checkout
      </label>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;">
      <div class="form-group">
        <label>Desconto (%)</label>
        <input type="text" name="desconto_percentual" class="form-control"
               value="<?= number_format((float) $m['desconto_percentual'], 2, ',', '') ?>">
      </div>
      <div class="form-group">
        <label>Teto de desconto (%)</label>
        <input type="text" name="desconto_max_percent" class="form-control"
               value="<?= number_format((float) $m['desconto_max_percent'], 2, ',', '') ?>">
      </div>
      <div class="form-group">
        <label>Valor mínimo (R$)</label>
        <input type="text" name="valor_min" class="form-control money"
               value="<?= $dinheiro((int) $m['valor_min_centavos']) ?>">
      </div>
      <div class="form-group">
        <label>Valor máximo (R$)</label>
        <input type="text" name="valor_max" class="form-control money"
               placeholder="sem limite"
               value="<?= $m['valor_max_centavos'] !== null ? $dinheiro((int) $m['valor_max_centavos']) : '' ?>">
      </div>

      <?php if ($ehCartao): ?>
      <div class="form-group">
        <label>Parcelamento máximo</label>
        <input type="number" name="parcelas_max" class="form-control" min="1" max="24"
               value="<?= (int) $m['parcelas_max'] ?>">
      </div>
      <div class="form-group">
        <label>Parcelas sem juros</label>
        <input type="number" name="parcelas_sem_juros" class="form-control" min="1" max="24"
               value="<?= (int) $m['parcelas_sem_juros'] ?>">
      </div>
      <div class="form-group">
        <label>Taxa por parcela (%)</label>
        <input type="text" name="taxa_percentual" class="form-control"
               value="<?= number_format((float) $m['taxa_percentual'], 2, ',', '') ?>">
      </div>
      <div class="form-group">
        <label>Parcela mínima (R$)</label>
        <input type="text" name="parcela_min" class="form-control money"
               value="<?= $dinheiro((int) $m['parcela_min_centavos']) ?>">
      </div>
      <?php else: ?>
      <input type="hidden" name="parcelas_max" value="1">
      <input type="hidden" name="parcelas_sem_juros" value="1">
      <input type="hidden" name="taxa_percentual" value="0">
      <input type="hidden" name="parcela_min" value="0">
      <?php endif; ?>
    </div>

    <?php if ($ehCartao): ?>
    <!-- Simulação: o lojista vê o efeito da regra antes de salvar, em vez de
         descobrir no checkout que a 12ª parcela ficou abaixo do mínimo. -->
    <div class="simulacao" style="margin-top:16px;padding:14px;background:#f8fafc;border-radius:8px;">
      <div style="font-size:12px;font-weight:700;color:var(--c-dark);margin-bottom:10px;">
        Simulação para R$ 500,00
        <span style="font-weight:400;color:var(--c-text-muted);">— atualiza ao mudar os campos</span>
      </div>
      <div class="simulacao-linhas" style="display:flex;flex-wrap:wrap;gap:8px;font-size:12px;">
        <?php foreach (($simulacoes[$m['codigo']] ?? []) as $p): ?>
          <span style="padding:5px 10px;border-radius:5px;background:#fff;border:1px solid var(--c-border);">
            <strong><?= (int) $p['parcela'] ?>x</strong>
            R$ <?= number_format($p['valor_parcela'] / 100, 2, ',', '.') ?>
            <?php if ($p['com_juros']): ?>
              <span style="color:#b45309;">c/ juros</span>
            <?php else: ?>
              <span style="color:#15803d;">s/ juros</span>
            <?php endif; ?>
          </span>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <div style="display:flex;align-items:center;gap:12px;margin-top:16px;">
      <button type="submit" class="btn btn-primary">Salvar</button>
      <span class="form-feedback" style="font-size:12.5px;"></span>
    </div>
  </form>
  <?php endforeach; ?>

</div>

<script>
(function () {
  'use strict';
  var BASE = '<?= ADMIN_URL ?>';

  function centavos(txt) {
    var s = String(txt || '').replace(/\./g, '').replace(',', '.');
    return Math.round((parseFloat(s) || 0) * 100);
  }

  document.querySelectorAll('.form-metodo').forEach(function (form) {
    var feedback = form.querySelector('.form-feedback');

    // ── Simulação ao vivo ────────────────────────────────────────
    // Só recalcula quando a tela tem simulação (cartão); nos demais
    // métodos o bloco nem existe.
    var alvo = form.querySelector('.simulacao-linhas');
    if (alvo) {
      var recalcular = function () {
        var fd = new FormData();
        fd.append('_csrf_token', form.querySelector('[name="_csrf_token"]').value);
        fd.append('valor', '500,00');
        ['parcelas_max', 'parcelas_sem_juros', 'taxa_percentual'].forEach(function (n) {
          var el = form.querySelector('[name="' + n + '"]');
          if (el) fd.append(n, el.value);
        });
        var pm = form.querySelector('[name="parcela_min"]');
        if (pm) fd.append('parcela_min', pm.value);

        fetch(BASE + '/pagamentos/formas/simular', {
          method: 'POST', body: fd, credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) { return r.json(); }).then(function (res) {
          if (!res.ok) return;
          alvo.innerHTML = res.parcelas.map(function (p) {
            var reais = (p.valor_parcela / 100).toFixed(2).replace('.', ',');
            var tag = p.com_juros
              ? '<span style="color:#b45309;">c/ juros</span>'
              : '<span style="color:#15803d;">s/ juros</span>';
            return '<span style="padding:5px 10px;border-radius:5px;background:#fff;border:1px solid var(--c-border);">'
                 + '<strong>' + p.parcela + 'x</strong> R$ ' + reais + ' ' + tag + '</span>';
          }).join('');
        }).catch(function () { /* simulação é conveniência: falha em silêncio */ });
      };

      ['parcelas_max', 'parcelas_sem_juros', 'taxa_percentual', 'parcela_min'].forEach(function (n) {
        var el = form.querySelector('[name="' + n + '"]');
        if (el) el.addEventListener('input', recalcular);
      });
    }

    // ── Salvar ───────────────────────────────────────────────────
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = form.querySelector('button[type="submit"]');
      btn.disabled = true;
      feedback.textContent = '';

      fetch(BASE + '/pagamentos/formas/salvar', {
        method: 'POST', body: new FormData(form), credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      }).then(function (r) { return r.json(); }).then(function (res) {
        btn.disabled = false;
        if (window.Toast) {
          res.ok ? Toast.success(res.msg) : Toast.error(res.msg || 'Não foi possível salvar.');
        }
        feedback.textContent = res.msg || '';
        feedback.style.color = res.ok ? '#15803d' : '#b91c1c';

        if (res.ok && res.simulacao && alvo) {
          alvo.innerHTML = res.simulacao.map(function (p) {
            var reais = (p.valor_parcela / 100).toFixed(2).replace('.', ',');
            var tag = p.com_juros
              ? '<span style="color:#b45309;">c/ juros</span>'
              : '<span style="color:#15803d;">s/ juros</span>';
            return '<span style="padding:5px 10px;border-radius:5px;background:#fff;border:1px solid var(--c-border);">'
                 + '<strong>' + p.parcela + 'x</strong> R$ ' + reais + ' ' + tag + '</span>';
          }).join('');
        }
      }).catch(function () {
        btn.disabled = false;
        feedback.textContent = 'Erro de conexão.';
        feedback.style.color = '#b91c1c';
      });
    });
  });
})();
</script>
