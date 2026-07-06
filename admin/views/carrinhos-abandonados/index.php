<?php
// views/admin/carrinhos-abandonados/index.php
// Variáveis: $rows, $total, $page, $totalPaginas, $filtros, $responsaveis

$statusCfg = [
  'novo'                => ['Novo',            '#64748b', '#f8fafc'],
  'abandonado'          => ['Abandonado',      '#dc2626', '#fef2f2'],
  'em_recuperacao'      => ['Em recuperação',  '#d97706', '#fffbeb'],
  'msg_enviada'         => ['Msg enviada',     '#1d4ed8', '#eff6ff'],
  'aguardando_resposta' => ['Aguardando',      '#7c3aed', '#f5f3ff'],
  'respondeu'           => ['Respondeu',       '#0891b2', '#ecfeff'],
  'negociacao'          => ['Negociação',      '#c026d3', '#fdf4ff'],
  'recuperado'          => ['Recuperado ✓',    '#16a34a', '#f0fdf4'],
  'perdido'             => ['Perdido',         '#475569', '#f1f5f9'],
  'ignorado'            => ['Ignorado',        '#94a3b8', '#f8fafc'],
  'sem_contato'         => ['Sem contato',     '#dc2626', '#fff1f2'],
];
$prioCfg = [
  'imediata' => ['🔥 Imediata', '#dc2626', '#fef2f2'],
  'alta'     => ['Alta',        '#d97706', '#fffbeb'],
  'media'    => ['Média',       '#1d4ed8', '#eff6ff'],
  'baixa'    => ['Baixa',       '#64748b', '#f8fafc'],
];

function tempoDesde(string $dt): string {
    $diff = time() - strtotime($dt);
    if ($diff < 3600)   return floor($diff / 60) . 'min';
    if ($diff < 86400)  return floor($diff / 3600) . 'h';
    return floor($diff / 86400) . 'd';
}
?>
<div class="ap-page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div>
    <h1 style="font-size:22px;font-weight:800;margin:0;">
      Carrinhos abandonados
      <span class="badge" style="background:#fef2f2;color:#dc2626;font-size:12px;
            vertical-align:middle;margin-left:6px;"><?= (int)$total ?></span>
    </h1>
  </div>
  <div style="display:flex;gap:10px;">
    <a href="<?= ADMIN_URL ?>/carrinhos-abandonados/templates" class="btn">⚙ Templates</a>
    <a href="<?= ADMIN_URL ?>/carrinhos-abandonados/dashboard" class="btn">📊 Dashboard</a>
    <a href="<?= ADMIN_URL ?>/carrinhos-abandonados/exportar" class="btn">⬇ Exportar CSV</a>
  </div>
</div>

<!-- Filtros -->
<div class="admin-card" style="margin:16px 0;padding:16px 18px;">
  <form method="get" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;align-items:end;">
    <div class="form-group" style="grid-column:span 2;">
      <label class="form-label">Buscar</label>
      <input type="text" name="q" class="form-control"
             placeholder="Nome, telefone, e-mail, CPF ou produto…"
             value="<?= View::e($filtros['q']) ?>">
    </div>
    <div class="form-group">
      <label class="form-label">Status</label>
      <select name="status" class="form-control">
        <option value="">Todos</option>
        <?php foreach ($statusCfg as $slug => [$label]): ?>
        <option value="<?= $slug ?>" <?= $filtros['status'] === $slug ? 'selected' : '' ?>>
          <?= $label ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label">Prioridade</label>
      <select name="prioridade" class="form-control">
        <option value="">Todas</option>
        <?php foreach ($prioCfg as $slug => [$label]): ?>
        <option value="<?= $slug ?>" <?= $filtros['prioridade'] === $slug ? 'selected' : '' ?>>
          <?= $label ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label">Responsável</label>
      <select name="responsavel_id" class="form-control">
        <option value="">Todos</option>
        <?php foreach ($responsaveis as $r): ?>
        <option value="<?= (int)$r['id'] ?>"
          <?= (int)$filtros['responsavel_id'] === (int)$r['id'] ? 'selected' : '' ?>>
          <?= View::e($r['nome']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label">Contato</label>
      <select name="contato" class="form-control">
        <option value="">Qualquer</option>
        <option value="com_telefone" <?= $filtros['contato'] === 'com_telefone' ? 'selected' : '' ?>>Com telefone</option>
        <option value="com_email"    <?= $filtros['contato'] === 'com_email'    ? 'selected' : '' ?>>Com e-mail</option>
        <option value="sem_contato"  <?= $filtros['contato'] === 'sem_contato'  ? 'selected' : '' ?>>Sem contato</option>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label">Período</label>
      <div style="display:flex;gap:6px;">
        <input type="date" name="data_de"  class="form-control" value="<?= View::e($filtros['data_de'])  ?>">
        <input type="date" name="data_ate" class="form-control" value="<?= View::e($filtros['data_ate']) ?>">
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">Valor (R$)</label>
      <div style="display:flex;gap:6px;">
        <input type="number" name="valor_min" class="form-control" placeholder="mín"
               step="0.01" value="<?= View::e($filtros['valor_min']) ?>">
        <input type="number" name="valor_max" class="form-control" placeholder="máx"
               step="0.01" value="<?= View::e($filtros['valor_max']) ?>">
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">Ordenar por</label>
      <select name="ordenar" class="form-control">
        <?php foreach (['prioridade' => 'Prioridade', 'valor' => 'Valor',
                        'data' => 'Mais recentes', 'interacao' => 'Última interação',
                        'score' => 'Chance de recuperação'] as $v => $l): ?>
        <option value="<?= $v ?>" <?= $filtros['ordenar'] === $v ? 'selected' : '' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="btn btn-primary">Filtrar</button>
  </form>
</div>

<!-- Listagem -->
<?php if (empty($rows)): ?>
<div class="admin-card" style="padding:48px;text-align:center;color:var(--c-text-muted);">
  <div style="font-size:40px;margin-bottom:8px;">🛒</div>
  <strong style="color:var(--c-dark);">Nenhum carrinho encontrado</strong>
  <p style="margin:6px 0 0;font-size:13.5px;">Ajuste os filtros ou aguarde a próxima detecção automática.</p>
</div>
<?php else: ?>

<div style="display:flex;flex-direction:column;gap:10px;">
<?php foreach ($rows as $r):
  [$stLabel, $stCor, $stBg]   = $statusCfg[$r['status']]     ?? ['?', '#64748b', '#f8fafc'];
  [$prLabel, $prCor, $prBg]   = $prioCfg[$r['prioridade']]   ?? ['—', '#64748b', '#f8fafc'];
  $temTel  = !empty($r['cliente_telefone']);
  $temMail = !empty($r['cliente_email']);
?>
  <a href="<?= ADMIN_URL ?>/carrinhos-abandonados/<?= (int)$r['id'] ?>"
     class="admin-card" style="display:grid;grid-template-columns:auto 1fr auto auto auto;
     gap:16px;align-items:center;padding:14px 18px;text-decoration:none;color:inherit;
     transition:box-shadow .15s;" onmouseover="this.style.boxShadow='0 4px 16px rgba(0,0,0,.08)'"
     onmouseout="this.style.boxShadow=''">

    <span class="badge" style="background:<?= $prBg ?>;color:<?= $prCor ?>;
          font-size:11px;font-weight:800;white-space:nowrap;"><?= $prLabel ?></span>

    <div style="min-width:0;">
      <div style="font-weight:700;font-size:14px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
        <?= $r['cliente_nome'] ? View::e($r['cliente_nome']) : '<span style="color:var(--c-text-muted);">Não identificado</span>' ?>
        <?php if ((int)$r['pedidos_anteriores'] > 0): ?>
          <span class="badge" style="background:#f0fdf4;color:#16a34a;font-size:10px;">
            Cliente recorrente</span>
        <?php endif; ?>
      </div>
      <div style="font-size:12px;color:var(--c-text-muted);margin-top:3px;display:flex;gap:12px;flex-wrap:wrap;">
        <span><?= $temTel  ? '📱 ' . View::e($r['cliente_telefone']) : '📵 sem telefone' ?></span>
        <span><?= $temMail ? '✉ '  . View::e($r['cliente_email'])    : '✉ sem e-mail' ?></span>
        <?php if ($r['responsavel_nome']): ?>
          <span>👤 <?= View::e($r['responsavel_nome']) ?></span>
        <?php endif; ?>
      </div>
    </div>

    <div style="text-align:right;">
      <div style="font-weight:800;font-size:15px;">
        R$ <?= number_format((float)$r['valor_snapshot'], 2, ',', '.') ?></div>
      <div style="font-size:11.5px;color:var(--c-text-muted);">
        <?= (int)$r['itens_snapshot'] ?> item(ns)</div>
    </div>

    <div style="text-align:center;">
      <span class="badge" style="background:<?= $stBg ?>;color:<?= $stCor ?>;
            font-size:11px;font-weight:700;white-space:nowrap;"><?= $stLabel ?></span>
      <div style="font-size:11px;color:var(--c-text-muted);margin-top:4px;">
        há <?= tempoDesde($r['abandonado_em']) ?></div>
    </div>

    <div style="font-size:18px;color:var(--c-text-muted);">›</div>
  </a>
<?php endforeach; ?>
</div>

<!-- Paginação -->
<?php if ($totalPaginas > 1): ?>
<div style="display:flex;justify-content:center;gap:6px;margin-top:18px;">
  <?php for ($p = max(1, $page - 2); $p <= min($totalPaginas, $page + 2); $p++):
    $qs = http_build_query(array_merge($filtros, ['page' => $p])); ?>
    <a href="?<?= $qs ?>" class="btn" style="<?= $p === $page
        ? 'background:var(--c-dark);color:#fff;' : '' ?>"><?= $p ?></a>
  <?php endfor; ?>
</div>
<?php endif; ?>
<?php endif; ?>