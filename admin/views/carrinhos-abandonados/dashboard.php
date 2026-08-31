<?php
// views/admin/carrinhos-abandonados/dashboard.php
// Variáveis: $dados (kpi, top_produtos, por_usuario), $de, $ate
$kpi = $dados['kpi'];
?>
<div class="ap-page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div>
    <h1 style="font-size:22px;font-weight:800;margin:0;">Recuperação de Carrinhos</h1>
    <p style="color:var(--c-text-muted);margin:4px 0 0;font-size:13.5px;">
      Central comercial de carrinhos abandonados
    </p>
  </div>
  <div style="display:flex;gap:10px;align-items:center;">
    <form method="get" style="display:flex;gap:8px;align-items:center;">
      <input type="date" name="de"  value="<?= View::e($de)  ?>" class="form-control" style="width:auto;">
      <input type="date" name="ate" value="<?= View::e($ate) ?>" class="form-control" style="width:auto;">
      <button class="btn btn-primary" style="white-space:nowrap;">Aplicar</button>
    </form>
    <a href="<?= ADMIN_URL ?>/carrinhos-abandonados" class="btn"
       style="background:var(--c-dark);color:var(--surface);white-space:nowrap;">Ver carrinhos →</a>
  </div>
</div>

<!-- KPIs principais -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;margin:18px 0;">
  <?php
  $cards = [
    ['Valor em aberto',   'R$ ' . number_format((float)$kpi['valor_aberto'], 2, ',', '.'),     'var(--danger)', 'var(--danger-lt)'],
    ['Valor recuperado',  'R$ ' . number_format((float)$kpi['valor_recuperado'], 2, ',', '.'), 'var(--success)', 'var(--success-lt)'],
    ['Taxa de recuperação', number_format((float)$kpi['taxa_recuperacao'], 1, ',', '') . '%',  'var(--blue)', 'var(--blue-lt)'],
    ['Ticket médio',      'R$ ' . number_format((float)$kpi['ticket_medio'], 2, ',', '.'),     'var(--purple)', 'var(--purple-lt)'],
    ['Em aberto',         (int)$kpi['em_aberto'],   'var(--warning)', 'var(--warning-lt)'],
    ['Recuperados',       (int)$kpi['recuperados'], 'var(--success)', 'var(--success-lt)'],
    ['Perdidos',          (int)$kpi['perdidos'],    'var(--text-2)', 'var(--bg)'],
    ['Sem contato',       (int)$kpi['sem_contato'], 'var(--danger)', 'var(--danger-lt)'],
  ];
  foreach ($cards as [$label, $valor, $cor, $bg]): ?>
  <div class="admin-card" style="padding:16px 18px;border-left:4px solid <?= $cor ?>;">
    <div style="font-size:12px;color:var(--c-text-muted);font-weight:600;
                text-transform:uppercase;letter-spacing:.4px;"><?= $label ?></div>
    <div style="font-size:22px;font-weight:800;color:<?= $cor ?>;margin-top:4px;">
      <?= $valor ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Identificação -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px;">

  <div class="admin-card">
    <h3 class="ap-card-title">Produtos mais abandonados</h3>
    <?php if (empty($dados['top_produtos'])): ?>
      <p style="padding:24px;text-align:center;color:var(--c-text-muted);">
        Nenhum abandono no período. 🎉
      </p>
    <?php else: ?>
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
      <thead>
        <tr style="text-align:left;color:var(--c-text-muted);font-size:11.5px;
                   text-transform:uppercase;letter-spacing:.4px;">
          <th style="padding:10px 18px;">Produto</th>
          <th style="padding:10px;text-align:center;">Carrinhos</th>
          <th style="padding:10px 18px;text-align:right;">Valor parado</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($dados['top_produtos'] as $p): ?>
        <tr style="border-top:1px solid var(--c-border);">
          <td style="padding:10px 18px;font-weight:600;"><?= View::e($p['produto_nome']) ?></td>
          <td style="padding:10px;text-align:center;">
            <span class="badge" style="background:var(--danger-lt);color:var(--danger);">
              <?= (int)$p['carrinhos'] ?></span>
          </td>
          <td style="padding:10px 18px;text-align:right;font-weight:700;color:var(--danger);">
            R$ <?= number_format((float)$p['valor'], 2, ',', '.') ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <div class="admin-card">
    <h3 class="ap-card-title">Performance por atendente</h3>
    <?php if (empty($dados['por_usuario'])): ?>
      <p style="padding:24px;text-align:center;color:var(--c-text-muted);">
        Nenhum carrinho atribuído no período.
      </p>
    <?php else: ?>
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
      <thead>
        <tr style="text-align:left;color:var(--c-text-muted);font-size:11.5px;
                   text-transform:uppercase;letter-spacing:.4px;">
          <th style="padding:10px 18px;">Atendente</th>
          <th style="padding:10px;text-align:center;">Recuperados</th>
          <th style="padding:10px;text-align:center;">Pendentes</th>
          <th style="padding:10px 18px;text-align:right;">Valor recuperado</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($dados['por_usuario'] as $u): ?>
        <tr style="border-top:1px solid var(--c-border);">
          <td style="padding:10px 18px;font-weight:600;"><?= View::e($u['responsavel_nome']) ?></td>
          <td style="padding:10px;text-align:center;">
            <?= (int)$u['recuperados'] ?>/<?= (int)$u['atribuidos'] ?></td>
          <td style="padding:10px;text-align:center;">
            <?php if ((int)$u['pendentes'] > 8): ?>
              <span class="badge" style="background:var(--danger-lt);color:var(--danger);"
                    title="Sobrecarga — redistribuir"><?= (int)$u['pendentes'] ?> ⚠</span>
            <?php else: ?>
              <?= (int)$u['pendentes'] ?>
            <?php endif; ?>
          </td>
          <td style="padding:10px 18px;text-align:right;font-weight:700;color:var(--success);">
            R$ <?= number_format((float)$u['valor_recuperado'], 2, ',', '.') ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<div class="admin-card" style="margin-top:14px;padding:14px 18px;display:flex;
     gap:22px;flex-wrap:wrap;font-size:13px;color:var(--c-text-muted);">
  <span>👤 Identificados: <strong style="color:var(--c-dark);"><?= (int)$kpi['identificados'] ?></strong></span>
  <span>❓ Sem identificação: <strong style="color:var(--c-dark);"><?= (int)$kpi['anonimos'] ?></strong></span>
  <span>📊 Total no período: <strong style="color:var(--c-dark);"><?= (int)$kpi['total'] ?></strong></span>
</div>