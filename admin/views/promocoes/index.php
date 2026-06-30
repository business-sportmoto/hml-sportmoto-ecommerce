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
        'desconto_progressivo' => ['label' => 'Progressivo',  'cor' => '#3b82f6'],
        'brinde'               => ['label' => 'Brinde',       'cor' => '#8b5cf6'],
        'compre_ganhe'         => ['label' => 'Compre+Leve',  'cor' => '#06b6d4'],
        'frete_gratis'         => ['label' => 'Frete grátis', 'cor' => '#10b981'],
        'bundle'               => ['label' => 'Bundle',       'cor' => '#f59e0b'],
        'cashback'             => ['label' => 'Cashback',     'cor' => '#ec4899'],
        'relampago'            => ['label' => 'Relâmpago',    'cor' => '#ef4444'],
        'fidelidade'           => ['label' => 'Fidelidade',   'cor' => '#f97316'],
      ];
      foreach ($promocoes as $p):
        $tipo     = $tipoLabels[$p['tipo']] ?? ['label' => $p['tipo'], 'cor' => '#64748b'];
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
            <span class="badge" style="background:#eff6ff;color:#1d4ed8;font-size:10px;">acumula</span>
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

<style>
.ap-filters { display:flex; gap:10px; padding:14px 18px; flex-wrap:wrap; }
.ap-filters .form-control { flex:1; min-width:160px; }
.admin-table tr.row-inativo td:not(:last-child):not(:nth-last-child(2)) { opacity:.5; }
.toggle-switch { position:relative; display:inline-block; width:38px; height:22px; cursor:pointer; }
.toggle-switch input { opacity:0; width:0; height:0; }
.toggle-slider {
  position:absolute; inset:0; background:#d1d5db; border-radius:99px;
  transition:background .2s;
}
.toggle-slider:before {
  content:''; position:absolute; width:16px; height:16px;
  left:3px; top:3px; background:#fff; border-radius:50%; transition:.2s;
}
.toggle-switch input:checked + .toggle-slider { background:#16a34a; }
.toggle-switch input:checked + .toggle-slider:before { transform:translateX(16px); }
.btn-danger-ghost { color:#dc2626; border-color:#fecaca; }
.btn-danger-ghost:hover { background:#fef2f2; }
</style>

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