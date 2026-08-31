<?php // views/admin/promocoes/index.php ?>
<div class="admin-page-header">
  <div>
    <h1>Promoções</h1>
    <span style="font-size:13px;color:var(--c-text-muted);"><?= $total ?> promoção<?= $total !== 1 ? 'ões' : '' ?></span>
  </div>
  <a href="<?= ADMIN_URL ?>/promocoes/nova" class="btn btn-primary">+ Nova promoção</a>
</div>

<!-- Filtros -->
<div class="admin-card" style="margin-bottom:16px;">
  <form method="GET" class="ap-filters">
    <input type="text" name="busca" value="<?= View::e($filtros['busca']) ?>"
           placeholder="Buscar por nome…" class="form-control">

    <select name="tipo" class="form-control">
      <option value="">Todos os tipos</option>
      <?php foreach ([
        'desconto_progressivo' => 'Desconto progressivo',
        'brinde'               => 'Brinde',
        'compre_ganhe'         => 'Compre X leve Y',
        'frete_gratis'         => 'Frete grátis',
        'bundle'               => 'Bundle/Kit',
        'cashback'             => 'Cashback',
        'relampago'            => 'Relâmpago',
        'fidelidade'           => 'Fidelidade',
      ] as $val => $label): ?>
        <option value="<?= $val ?>" <?= ($filtros['tipo'] === $val) ? 'selected' : '' ?>>
          <?= $label ?>
        </option>
      <?php endforeach; ?>
    </select>

    <select name="ativo" class="form-control">
      <option value="">Todos</option>
      <option value="1" <?= $filtros['ativo'] === 1 ? 'selected' : '' ?>>Ativas</option>
      <option value="0" <?= $filtros['ativo'] === 0 ? 'selected' : '' ?>>Inativas</option>
    </select>

    <button type="submit" class="btn btn-outline">Filtrar</button>
    <a href="<?= ADMIN_URL ?>/promocoes" class="btn btn-ghost">Limpar</a>
  </form>
</div>

<?php if (empty($promocoes)): ?>
<div class="admin-card empty-state" style="padding:40px;text-align:center;">
  <p style="font-size:15px;color:var(--c-text-muted);">Nenhuma promoção encontrada.</p>
  <a href="<?= ADMIN_URL ?>/promocoes/nova" class="btn btn-primary" style="margin-top:12px;">
    Criar primeira promoção
  </a>
</div>
<?php else: ?>
<div class="admin-card">
  <table class="admin-table">
    <thead>
      <tr>
        <th>Nome</th>
        <th>Tipo</th>
        <th>Prioridade</th>
        <th>Validade</th>
        <th>Aplicações</th>
        <th>Status</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php
      $tipoLabels = [
        'desconto_progressivo' => ['label' => 'Progressivo',  'cor' => 'var(--blue)'],
        'brinde'               => ['label' => 'Brinde',       'cor' => 'var(--purple)'],
        'compre_ganhe'         => ['label' => 'Compre+Leve',  'cor' => 'var(--info)'],
        'frete_gratis'         => ['label' => 'Frete grátis', 'cor' => 'var(--success)'],
        'bundle'               => ['label' => 'Bundle',       'cor' => 'var(--warning)'],
        'cashback'             => ['label' => 'Cashback',     'cor' => '#ec4899'],
        'relampago'            => ['label' => 'Relâmpago',    'cor' => 'var(--danger)'],
        'fidelidade'           => ['label' => 'Fidelidade',   'cor' => '#f97316'],
      ];
      foreach ($promocoes as $p):
        $tipo     = $tipoLabels[$p['tipo']] ?? ['label' => $p['tipo'], 'cor' => 'var(--text-2)'];
        $vencida  = $p['data_fim'] && strtotime($p['data_fim']) < time();
        $semInicio= $p['data_inicio'] && strtotime($p['data_inicio']) > time();
      ?>
      <tr data-id="<?= $p['id'] ?>" class="<?= !$p['ativo'] ? 'row-inativo' : '' ?>">
        <td>
          <strong><?= View::e($p['nome']) ?></strong>
          <?php if (!empty($p['descricao'])): ?>
            <div style="font-size:12px;color:var(--c-text-muted);margin-top:2px;">
              <?= View::e(mb_substr($p['descricao'], 0, 60)) ?>…
            </div>
          <?php endif; ?>
          <?php if ($p['acumulavel']): ?>
            <span class="badge" style="background:var(--blue-lt);color:var(--blue);font-size:10px;">acumula</span>
          <?php endif; ?>
        </td>
        <td>
          <span class="badge" style="background:<?= $tipo['cor'] ?>22;color:<?= $tipo['cor'] ?>;font-size:11.5px;">
            <?= $tipo['label'] ?>
          </span>
        </td>
        <td style="text-align:center;">
          <strong><?= (int)$p['prioridade'] ?></strong>
        </td>
        <td style="font-size:12.5px;">
          <?php if ($p['data_inicio'] || $p['data_fim']): ?>
            <?= $p['data_inicio'] ? date('d/m/Y', strtotime($p['data_inicio'])) : '—' ?>
            →
            <?= $p['data_fim'] ? date('d/m/Y', strtotime($p['data_fim'])) : '∞' ?>
            <?php if ($vencida):  ?><span class="badge badge-danger"  style="font-size:10px;">Vencida</span><?php endif; ?>
            <?php if ($semInicio): ?><span class="badge badge-warning" style="font-size:10px;">Aguardando</span><?php endif; ?>
          <?php else: ?>
            <span style="color:var(--c-text-muted);">Sem limite</span>
          <?php endif; ?>
        </td>
        <td style="text-align:center;">
          <?= number_format((int)$p['total_aplicacoes']) ?>
          <?php if ((float)$p['total_desconto_concedido'] > 0): ?>
            <div style="font-size:11px;color:var(--c-text-muted);">
              −<?= PriceHelper::format((float)$p['total_desconto_concedido']) ?>
            </div>
          <?php endif; ?>
        </td>
        <td>
          <label class="toggle-switch" title="<?= $p['ativo'] ? 'Desativar' : 'Ativar' ?>">
            <input type="checkbox" class="js-toggle-promo" data-id="<?= $p['id'] ?>"
                   <?= $p['ativo'] ? 'checked' : '' ?>>
            <span class="toggle-slider"></span>
          </label>
        </td>
        <td style="white-space:nowrap;">
          <a href="<?= ADMIN_URL ?>/promocoes/<?= $p['id'] ?>" class="btn btn-ghost btn-sm">Editar</a>
          <button type="button" class="btn btn-ghost btn-sm btn-danger-ghost js-excluir-promo"
                  data-id="<?= $p['id'] ?>" data-nome="<?= View::e($p['nome']) ?>">
            Excluir
          </button>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($has_pages): ?>
<?php include __DIR__ . '/../../partials/pagination.php'; ?>
<?php endif; ?>
<?php endif; ?>

<script>
const CSRF_PROMO = '<?= SecurityHelper::generateCsrf() ?>';

document.querySelectorAll('.js-toggle-promo').forEach(function (chk) {
  chk.addEventListener('change', function () {
    fetch('<?= ADMIN_URL ?>/promocoes/' + this.dataset.id + '/toggle', {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: new URLSearchParams({ _token: CSRF_PROMO }),
      credentials: 'same-origin',
    }).catch(function () { chk.checked = !chk.checked; });
  });
});

document.querySelectorAll('.js-excluir-promo').forEach(function (btn) {
  btn.addEventListener('click', function () {
    if (!confirm('Excluir a promoção "' + this.dataset.nome + '"?\nEsta ação não pode ser desfeita.')) return;
    fetch('<?= ADMIN_URL ?>/promocoes/' + this.dataset.id + '/excluir', {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: new URLSearchParams({ _token: CSRF_PROMO }),
      credentials: 'same-origin',
    })
    .then(function (r) { return r.json(); })
    .then(function (resp) {
      if (resp.ok) {
        var row = document.querySelector('tr[data-id="' + btn.dataset.id + '"]');
        if (row) row.remove();
      }
    });
  });
});
</script>