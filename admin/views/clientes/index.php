<?php
// views/admin/clientes/index.php
$tierCores = [
    'bronze'   => ['bg'=>'#fef3c7','text'=>'#92400e','dot'=>'#d97706'],
    'silver'   => ['bg'=>'#f1f5f9','text'=>'#475569','dot'=>'#94a3b8'],
    'gold'     => ['bg'=>'#fef9c3','text'=>'#713f12','dot'=>'#ca8a04'],
    'platinum' => ['bg'=>'#eff6ff','text'=>'#1e3a8a','dot'=>'#2563eb'],
];
$mesAtual = (int)date('n');
$nomeMes  = ['','Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];
?>
<div class="admin-page">
  <div class="admin-page-header">
    <div>
      <h1 class="admin-page-title">Clientes</h1>
      <p class="admin-page-sub"><?= number_format($total) ?> clientes encontrados</p>
    </div>
     <div>
    <a href="?bling_sync=erro" class="btn btn-outline btn-sm">Falhas de sync</a>
    <a href="?bling_sync=nao" class="btn btn-outline btn-sm">Não sincronizados</a>
    </div>
  </div>
  <?php
// ════════════════════════════════════════════════════════
// HEADER DASHBOARD — topo da view clientes/index.php
// Colar ANTES da tabela de clientes.
// Requer $stats (do controller: getDashboardStats()).
//
// WEEKDAY() do MySQL: segunda=0 → "semana" começa na segunda.
// Se sua semana começa domingo, ajuste a query do model.
// ════════════════════════════════════════════════════════
$pct = fn($n, $t) => $t > 0 ? round($n / $t * 100) : 0;
?>
 
<div class="cli-dash">
  <div class="cli-dash-card cli-dash-card--primary">
    <div class="cli-dash-label">Total de clientes</div>
    <div class="cli-dash-value"><?= number_format($stats['total'], 0, ',', '.') ?></div>
    <div class="cli-dash-foot">Base completa</div>
  </div>
 
  <div class="cli-dash-card">
    <div class="cli-dash-label">Vindos da Tray</div>
    <div class="cli-dash-value"><?= number_format($stats['da_tray'], 0, ',', '.') ?></div>
    <div class="cli-dash-foot"><?= $pct($stats['da_tray'], $stats['total']) ?>% da base</div>
  </div>
 
  <div class="cli-dash-card">
    <div class="cli-dash-label">Ativaram a conta</div>
    <div class="cli-dash-value cli-dash-value--ok"><?= number_format($stats['ativados'], 0, ',', '.') ?></div>
    <div class="cli-dash-foot"><?= $pct($stats['ativados'], $stats['total']) ?>% ativados</div>
  </div>
 
  <div class="cli-dash-card">
    <div class="cli-dash-label">Banidos / Suspensos</div>
    <div class="cli-dash-novos" style="margin-top:2px;">
      <div>
        <span class="cli-dash-novos-num <?= $stats['banidos_total'] > 0 ? 'cli-dash-value--danger' : '' ?>">
          <?= number_format($stats['banidos_total'], 0, ',', '.') ?>
        </span>
        <span class="cli-dash-novos-cap">Banidos</span>
      </div>
      <div>
        <span class="cli-dash-novos-num <?= $stats['suspensos_compra'] > 0 ? 'cli-dash-value--danger' : '' ?>">
          <?= number_format($stats['suspensos_compra'], 0, ',', '.') ?>
        </span>
        <span class="cli-dash-novos-cap" title="não podem realizar compra">Sem compra</span>
      </div>
    </div>
  </div>
 
  <div class="cli-dash-card cli-dash-card--novos">
    <div class="cli-dash-label">Novos clientes</div>
    <div class="cli-dash-novos">
      <div>
        <span class="cli-dash-novos-num"><?= number_format($stats['novos_hoje'], 0, ',', '.') ?></span>
        <span class="cli-dash-novos-cap">Hoje</span>
      </div>
      <div>
        <span class="cli-dash-novos-num"><?= number_format($stats['novos_semana'], 0, ',', '.') ?></span>
        <span class="cli-dash-novos-cap">Semana</span>
      </div>
      <div>
        <span class="cli-dash-novos-num"><?= number_format($stats['novos_mes'], 0, ',', '.') ?></span>
        <span class="cli-dash-novos-cap">Mês</span>
      </div>
    </div>
  </div>
</div>
  <!-- Filtros -->
  <form method="GET" class="admin-filters" style="margin-bottom:16px;">
    <div class="filter-row">
      <div class="filter-group filter-group--search">
        <input type="text" name="q" class="form-control"
               placeholder="Nome, e-mail ou CPF…"
               value="<?= View::e($filtros['q'] ?? '') ?>">
      </div>
      <div class="filter-group">
        <select name="tier" class="form-control">
          <option value="">Todos os tiers</option>
          <?php foreach (['bronze','silver','gold','platinum'] as $t): ?>
          <option value="<?= $t ?>" <?= ($filtros['tier']??'')===$t?'selected':'' ?>>
            <?= ucfirst($t) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filter-group">
        <select name="tag_id" class="form-control">
          <option value="">Todas as tags</option>
          <?php foreach ($tags as $tag): ?>
          <option value="<?= (int)$tag['id'] ?>" <?= ($filtros['tag_id']??'')==$tag['id']?'selected':'' ?>>
            <?= View::e($tag['nome']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filter-group">
        <select name="ativo" class="form-control">
          <option value="">Todos</option>
          <option value="1" <?= ($filtros['ativo']??'')==='1'?'selected':'' ?>>Ativos</option>
          <option value="0" <?= ($filtros['ativo']??'')==='0'?'selected':'' ?>>Bloqueados</option>
        </select>
      </div>
      <div class="filter-group">
        <select name="aniversario_mes" class="form-control">
          <option value="">Aniversários</option>
          <?php for ($m=1;$m<=12;$m++): ?>
          <option value="<?= $m ?>" <?= ($filtros['aniversario_mes']??'')==$m?'selected':'' ?>>
            <?= $nomeMes[$m] ?> <?= $m===$mesAtual?'⭐':'' ?>
          </option>
          <?php endfor; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-outline">Filtrar</button>
      <?php if (array_filter($filtros)): ?>
        <a href="<?= ADMIN_URL ?>/clientes" class="btn btn-ghost">Limpar</a>
      <?php endif; ?>
    </div>
  </form>

  <div class="admin-card">
    <?php if (empty($clientes)): ?>
    <div class="empty-state"><strong>Nenhum cliente encontrado</strong></div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th>Cliente</th>
            <th>Tier</th>
            <th>LTV</th>
            <th>Pedidos</th>
            <th>Tags</th>
            <th>Último acesso</th>
            <th>Status</th>
            <th style="padding:12px 10px;">Origem & Status</th>
            <th class="text-right">Ação</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($clientes as $c):
            $tier   = $c['tier'] ?? 'bronze';
            $tc     = $tierCores[$tier] ?? $tierCores['bronze'];
            $tagNomes = $c['tags_nomes'] ? explode('||', $c['tags_nomes']) : [];
            $tagCores = $c['tags_cores'] ? explode('||', $c['tags_cores']) : [];
            $ultimoAcesso = $c['ultimo_acesso'] ?? null;
            $diasSemAcesso= $ultimoAcesso ? (int)((time()-strtotime($ultimoAcesso))/86400) : null;
            $semAcesso30 = $diasSemAcesso !== null && $diasSemAcesso > 30;
            // Aniversário hoje/no mês
            $nascimento = $c['nascimento'] ?? null;
            $anivHoje = $nascimento && date('m-d') === date('m-d', strtotime($nascimento));
            $anivMes  = $nascimento && (int)date('m') === (int)date('m', strtotime($nascimento)) && !$anivHoje;
          ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:10px;">
                <?php if (!empty($c['avatar'])): ?>
                  <img src="<?= BASE_URL ?>/uploads/avatars/<?= View::e($c['avatar']) ?>"
                       style="width:36px;height:36px;border-radius:50%;object-fit:cover;flex-shrink:0;">
                <?php else: ?>
                  <div style="width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#667eea,#764ba2);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;color:#fff;flex-shrink:0;">
                    <?= mb_strtoupper(mb_substr($c['nome'],0,1)) ?>
                  </div>
                <?php endif; ?>
                <div>
                  <div style="font-weight:700;font-size:13.5px;">
                    <?= View::e($c['nome']) ?>
                    <?= $anivHoje ? ' 🎂' : ($anivMes ? ' 🎈' : '') ?>
                  </div>
                  <small class="txt-muted"><?= View::e($c['email']) ?></small>
                </div>
              </div>
            </td>
            <td>
              <span style="display:inline-flex;align-items:center;gap:5px;background:<?= $tc['bg'] ?>;color:<?= $tc['text'] ?>;padding:3px 10px;border-radius:99px;font-size:12px;font-weight:700;">
                <span style="width:6px;height:6px;border-radius:50%;background:<?= $tc['dot'] ?>;flex-shrink:0;"></span>
                <?= ucfirst($tier) ?>
              </span>
            </td>
            <td><strong><?= PriceHelper::format((float)($c['ltv_total'] ?? 0)) ?></strong></td>
            <td><?= (int)($c['total_pedidos_real'] ?? 0) ?></td>
            <td>
              <div style="display:flex;gap:4px;flex-wrap:wrap;">
                <?php foreach ($tagNomes as $i => $tn): ?>
                  <span style="background:<?= View::e($tagCores[$i] ?? '#64748b') ?>22;color:<?= View::e($tagCores[$i] ?? '#64748b') ?>;border:1px solid <?= View::e($tagCores[$i] ?? '#64748b') ?>44;font-size:11px;font-weight:700;padding:2px 8px;border-radius:99px;">
                    <?= View::e($tn) ?>
                  </span>
                <?php endforeach; ?>
              </div>
            </td>
            <td>
              <?php if ($ultimoAcesso): ?>
                <span <?= $semAcesso30?'style="color:#dc2626;font-weight:700;"':'' ?>>
                  <?= date('d/m/Y', strtotime($ultimoAcesso)) ?>
                  <?= $semAcesso30 ? ' ⚠' : '' ?>
                </span>
              <?php else: ?>
                <span class="txt-muted">—</span>
              <?php endif; ?>
            </td>
            <td>
              <span class="badge badge-<?= $c['ativo'] ? 'success' : 'danger' ?>">
                <?= $c['ativo'] ? 'Ativo' : 'Bloqueado' ?>
              </span>
            </td>
            <td style="padding:12px 10px;">
              <span class="badges-wrap" data-cliente="<?= (int)$c['id'] ?>">
                <?= ClienteBadges::html($c) ?>
              </span>
              <?php if (empty($c['bling_id'])): ?>
              <button type="button" class="btn btn-ghost btn-xs btn-sync-bling"
                      data-id="<?= (int)$c['id'] ?>"
                      style="margin-left:6px;font-size:10.5px;padding:2px 8px;"
                      title="Sincronizar este cliente com o Bling">⟳ Bling</button>
              <?php endif; ?>
            </td>
            
            <td class="text-right">
              <a href="<?= ADMIN_URL ?>/clientes/<?= (int)$c['id'] ?>"
                 class="btn-icon" title="Ver perfil">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2.5" stroke-linecap="round">
                  <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
                  <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
                </svg>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
      <?php for ($i=1;$i<=$totalPages;$i++): ?>
        <a href="?<?= http_build_query(array_merge($filtros,['page'=>$i])) ?>"
           class="pagination-item <?= $page===$i?'is-active':'' ?>"><?= $i ?></a>
      <?php endfor; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<style>
.cli-dash{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:20px}
.cli-dash-card{background:#fff;border:1px solid var(--c-border,#e6e9ef);border-radius:12px;padding:16px 18px;display:flex;flex-direction:column;gap:4px;transition:box-shadow .18s,transform .18s}
.cli-dash-card:hover{box-shadow:0 8px 24px rgba(15,23,42,.06);transform:translateY(-1px)}
.cli-dash-card--primary{background:linear-gradient(135deg,#1e293b,#0f172a);border-color:#0f172a}
.cli-dash-card--primary .cli-dash-label{color:#94a3b8}
.cli-dash-card--primary .cli-dash-value{color:#fff}
.cli-dash-card--primary .cli-dash-foot{color:#64748b}
.cli-dash-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--c-text-muted,#64748b)}
.cli-dash-value{font-size:28px;font-weight:800;line-height:1.1;color:var(--c-dark,#0f172a);font-variant-numeric:tabular-nums}
.cli-dash-value--ok{color:#16a34a}
.cli-dash-value--danger{color:#dc2626}
.cli-dash-foot{font-size:11px;color:var(--c-text-muted,#94a3b8)}
.cli-dash-card--novos{grid-column:span 1}
.cli-dash-novos{display:flex;gap:14px;margin-top:2px}
.cli-dash-novos>div{display:flex;flex-direction:column}
.cli-dash-novos-num{font-size:20px;font-weight:800;color:var(--c-dark,#0f172a);line-height:1.1;font-variant-numeric:tabular-nums}
.cli-dash-novos-cap{font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.4px;color:var(--c-text-muted,#94a3b8);margin-top:2px}
@media (max-width:1100px){.cli-dash{grid-template-columns:repeat(3,1fr)}.cli-dash-card--novos{grid-column:span 3}}
@media (max-width:640px){.cli-dash{grid-template-columns:repeat(2,1fr)}.cli-dash-card--novos{grid-column:span 2}}
</style>

<script>
  $(document).on('click', '.btn-sync-bling', function () {
  var $btn = $(this), id = $btn.data('id');
  CK.btnLoading($btn);

  $.post(BASE_URL + '/admin/clientes/' + id + '/sync-bling', { _token: CSRF_TOKEN })
    .done(function (r) {
      CK.btnLoading($btn, false);
      adminToast(r.msg, r.ok ? 'success' : 'error');

      if (r.ok && r.badge_bling) {
        // Troca o badge do Bling na linha, sem reload
        var $wrap = $('.badges-wrap[data-cliente="' + id + '"]');
        $wrap.find('.cli-badge--bling').replaceWith(r.badge_bling);
        // Sincronizado: o botão perde a razão de existir
        $btn.remove();
      }
    })
    .fail(function () {
      CK.btnLoading($btn, false);
      adminToast('Erro de rede.', 'error');
    });
});
</script>