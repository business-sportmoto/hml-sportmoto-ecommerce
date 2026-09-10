<?php
// views/admin/pedidos/index.php

$statusMap = [
    'aguardando_pagamento' => ['cor'=>'warning', 'label'=>'Aguardando pgto.'],
    'pagamento_aprovado'   => ['cor'=>'info',    'label'=>'Pgto. aprovado'],
    'em_separacao'         => ['cor'=>'info',    'label'=>'Em separação'],
    'enviado'              => ['cor'=>'primary', 'label'=>'Enviado'],
    'entregue'             => ['cor'=>'success', 'label'=>'Entregue'],
    'cancelado'            => ['cor'=>'danger',  'label'=>'Cancelado'],
    'troca_devolucao'      => ['cor'=>'warning', 'label'=>'Troca/Dev.'],
];
$pagMap = [
    'pendente'   => ['cor'=>'warning', 'label'=>'Pendente'],
    'aguardando' => ['cor'=>'warning', 'label'=>'Aguardando'],
    'aprovado'   => ['cor'=>'success', 'label'=>'Aprovado'],
    'recusado'   => ['cor'=>'danger',  'label'=>'Recusado'],
    'estornado'  => ['cor'=>'danger',  'label'=>'Estornado'],
    'reembolsado'=> ['cor'=>'info',    'label'=>'Reembolsado'],
];
?>

<div class="admin-page">

  <div class="admin-page-header">
    <div>
      <h1 class="admin-page-title">Pedidos</h1>
      <p class="admin-page-sub"><?= number_format($total) ?> pedidos encontrados</p>
    </div>
    <div class="ped_head_acoes">
    <a href="<?= ADMIN_URL ?>/pedidos/checkout" class="btn btn-secondary">
      <?= class_exists('IconLibrary') ? IconLibrary::render('package', 'icon icon--md') : '' ?>
      Checkout
    </a>
    <a href="<?= ADMIN_URL ?>/pedidos/novo" class="btn btn-primary">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Novo pedido
    </a>
    </div>
  </div>

  <!-- KPIs -->
  <div class="stats-grid stats-grid--5" style="margin-bottom:20px;">
    <div class="stat-card">
      <div class="stat-card-icon stat-card-icon--blue">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/></svg>
      </div>
      <div class="stat-card-body">
        <span class="stat-card-value"><?= (int)($kpis['novos_hoje'] ?? 0) ?></span>
        <span class="stat-card-label">Novos hoje</span>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-card-icon stat-card-icon--orange">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      </div>
      <div class="stat-card-body">
        <span class="stat-card-value"><?= (int)($kpis['aguardando_pagamento'] ?? 0) ?></span>
        <span class="stat-card-label">Aguardando pgto.</span>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-card-icon stat-card-icon--purple">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><polyline points="9 11 12 14 22 4"/></svg>
      </div>
      <div class="stat-card-body">
        <span class="stat-card-value"><?= (int)($kpis['em_separacao'] ?? 0) ?></span>
        <span class="stat-card-label">Em separação</span>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-card-icon stat-card-icon--blue">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
      </div>
      <div class="stat-card-body">
        <span class="stat-card-value"><?= (int)($kpis['enviados'] ?? 0) ?></span>
        <span class="stat-card-label">Enviados</span>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-card-icon stat-card-icon--green">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
      </div>
      <div class="stat-card-body">
        <?php
        $recMes = (float) ($kpis['receita_mes'] ?? 0);
        $recAnt = (float) ($kpis['receita_mes_anterior'] ?? 0);
        $recVar = $kpis['receita_variacao'] ?? null;   // null = sem base
        ?>
        <span class="stat-card-value"><?= PriceHelper::format($recMes) ?></span>
        <span class="stat-card-label">
          Receita do mês
          <?php if ($recVar !== null): ?>
            <span class="stat-delta <?= $recVar >= 0 ? 'is-up' : 'is-down' ?>">
              <?= $recVar >= 0 ? '▲' : '▼' ?> <?= number_format(abs($recVar), 1, ',', '.') ?>%
            </span>
          <?php endif; ?>
        </span>
        <span class="stat-card-hint">
          <?php if ($recVar !== null): ?>
            mês anterior: <?= PriceHelper::format($recAnt) ?>
          <?php else: ?>
            sem receita no mês anterior para comparar
          <?php endif; ?>
        </span>
      </div>
    </div>
  </div>

  <?php $atrasadas = (int) ($kpis['entregas_atrasadas'] ?? 0); ?>
  <?php if ($atrasadas > 0): ?>
  <!-- Saúde dos envios.
       Só aparece quando há atraso: um aviso permanente vira paisagem e para
       de ser lido. O número vem da MESMA fonte da torre (log_rastreios.atraso)
       para as duas telas não discordarem. -->
  <a href="<?= BASE_URL ?>/admin/logistica" class="ap-saude-badge">
    <span class="ap-saude-ico">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="2.2" stroke-linecap="round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    </span>
    <span class="ap-saude-txt">
      <strong><?= $atrasadas ?></strong>
      entrega<?= $atrasadas === 1 ? '' : 's' ?> em atraso
    </span>
    <span class="ap-saude-cta">Ver na torre de controle →</span>
  </a>
  <?php endif; ?>

  <!-- Filtros -->
  <form method="GET" class="admin-filters">
    <div class="filter-row">
      <div class="filter-group filter-group--search">
        <input type="text" name="q" class="form-control" placeholder="Buscar por código, cliente ou e-mail…"
               value="<?= View::e($filtros['q']) ?>">
      </div>
      <div class="filter-group">
        <select name="status_pedido" class="form-control">
          <option value="">Todos os status</option>
          <?php foreach ($statusMap as $k => $v): ?>
          <option value="<?= $k ?>" <?= $filtros['status_pedido']===$k?'selected':'' ?>><?= $v['label'] ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filter-group">
        <select name="status_pagamento" class="form-control">
          <option value="">Pagamento</option>
          <?php foreach ($pagMap as $k => $v): ?>
          <option value="<?= $k ?>" <?= $filtros['status_pagamento']===$k?'selected':'' ?>><?= $v['label'] ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filter-group">
        <input type="date" name="data_de" class="form-control" value="<?= View::e($filtros['data_de']) ?>">
      </div>
      <div class="filter-group">
        <input type="date" name="data_ate" class="form-control" value="<?= View::e($filtros['data_ate']) ?>">
      </div>
      <button type="submit" class="btn btn-outline">Filtrar</button>
      <?php if (array_filter($filtros)): ?>
        <a href="<?= ADMIN_URL ?>/pedidos" class="btn btn-ghost">Limpar</a>
      <?php endif; ?>
    </div>
  </form>

  <!-- Tabela -->
  <div class="admin-card">
    <?php if (empty($pedidos)): ?>
    <div class="empty-state">
      <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/></svg>
      <strong>Nenhum pedido encontrado</strong>
    </div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th>Pedido</th>
            <th>Cliente</th>
            <th>Itens</th>
            <th>Total</th>
            <th>Pagamento</th>
            <th>Status</th>
            <th>Data</th>
            <th class="text-right">Ação</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pedidos as $p):
            $st  = $statusMap[$p['status_pedido']]    ?? ['cor'=>'info','label'=>$p['status_pedido']];
            $pag = $pagMap[$p['status_pagamento']]    ?? ['cor'=>'info','label'=>$p['status_pagamento']];
            $img = !empty($p['primeira_imagem'])
                 ? BASE_URL.'/uploads/produtos/'.$p['primeira_imagem']
                 : BASE_URL.'/assets/img/placeholder.png';
                
            $img = $p['primeiro_produto_id'] ? ImageHelper::getCartItemImage($p['primeiro_produto_id']) : '';
          ?>
          <tr>
            <td>
              <a href="<?= ADMIN_URL ?>/pedidos/<?= $p['id'] ?>" class="link-subtle">
                <strong>#<?= View::e($p['codigo']) ?></strong>
              </a>
              <?php if ($p['codigo_rastreio']): ?>
                <div style="margin-top:3px;">
                  <?php if (!empty($p['rastreio_id'])): ?>
                    <button type="button" class="ap-rastreio-btn"
                            data-rastreio="<?= (int) $p['rastreio_id'] ?>"
                            title="Ver situação e timeline">
                      <?= View::e($p['codigo_rastreio']) ?>
                    </button>
                  <?php else: ?>
                    <code style="font-size:10.5px;color:var(--text-2);"><?= View::e($p['codigo_rastreio']) ?></code>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </td>
            <td>
              <div class="td-main"><?= View::e($p['cliente_nome']) ?></div>
              <small class="txt-muted"><?= View::e($p['cliente_email']) ?></small>
            </td>
            <td>
              <div style="display:flex;align-items:center;gap:8px;">
                <img src="<?= View::e($img) ?>" width="36" height="36"
                     style="border-radius:6px;object-fit:cover;background:var(--surface2);">
                <span style="font-size:12.5px;color:var(--c-text-muted);">
                  <?= (int)$p['total_itens'] ?> <?= (int)$p['total_itens']===1?'item':'itens' ?>
                </span>
              </div>
            </td>
            <td>
              <strong><?= PriceHelper::format((float)$p['total']) ?></strong>
              <?php if ($p['parcelas'] > 1): ?>
                <div><small class="txt-muted"><?= $p['parcelas'] ?>× parcelas</small></div>
              <?php endif; ?>
            </td>
            <td>
              <span class="badge badge-<?= $pag['cor'] ?>"><?= $pag['label'] ?></span>
              <?php if ($p['cartao_bandeira']): ?>
                <div style="margin-top:3px;">
                  <span style="font-size:11px;color:var(--c-text-muted);">
                    <?= View::e(ucfirst($p['cartao_bandeira'])) ?> ****<?= View::e($p['cartao_ultimos_4'] ?? '') ?>
                  </span>
                </div>
              <?php endif; ?>
            </td>
            <td>
              <span class="badge badge-<?= $st['cor'] ?>"><?= $st['label'] ?></span>
            </td>
            <td>
              <small><?= date('d/m/Y', strtotime($p['criado_em'])) ?></small>
              <div><small class="txt-muted"><?= date('H:i', strtotime($p['criado_em'])) ?></small></div>
            </td>
            <td class="text-right">
              <?php if (!empty($p['etiqueta_id'])): ?>
              <button type="button" class="btn-icon ap-reimprimir"
                      data-etiqueta="<?= (int) $p['etiqueta_id'] ?>"
                      title="Reimprimir etiqueta de envio">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2.2" stroke-linecap="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
              </button>
              <?php endif; ?>
              <a href="<?= ADMIN_URL ?>/pedidos/<?= $p['id'] ?>" class="btn-icon" title="Abrir">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Paginação -->
    <?php if ($totalPages > 1):
      // JANELA DE PÁGINAS, não a lista inteira.
      //
      // O laço anterior imprimia TODAS as páginas: com alguns milhares de
      // pedidos isso vira centenas de links, quebra a linha e some com o
      // conteúdo. Aqui saem no máximo 7 números em volta da página atual,
      // com primeira e última sempre acessíveis.
      $janela = 2;
      $ini    = max(1, $page - $janela);
      $fim    = min($totalPages, $page + $janela);

      $url = static fn(int $p): string =>
          '?' . http_build_query(array_merge($filtros, ['page' => $p]));
    ?>
    <div class="pagination">
      <span class="pagination-info">
        <?= number_format($total, 0, ',', '.') ?>
        pedido<?= $total === 1 ? '' : 's' ?> ·
        página <?= $page ?> de <?= $totalPages ?>
      </span>

      <div class="pagination-nav">
        <?php if ($page > 1): ?>
          <a href="<?= $url($page - 1) ?>" class="pagination-item pagination-item--nav"
             rel="prev" aria-label="Página anterior">‹</a>
        <?php endif; ?>

        <?php if ($ini > 1): ?>
          <a href="<?= $url(1) ?>" class="pagination-item">1</a>
          <?php if ($ini > 2): ?><span class="pagination-gap">…</span><?php endif; ?>
        <?php endif; ?>

        <?php for ($i = $ini; $i <= $fim; $i++): ?>
          <a href="<?= $url($i) ?>"
             class="pagination-item <?= $page === $i ? 'is-active' : '' ?>"
             <?= $page === $i ? 'aria-current="page"' : '' ?>><?= $i ?></a>
        <?php endfor; ?>

        <?php if ($fim < $totalPages): ?>
          <?php if ($fim < $totalPages - 1): ?><span class="pagination-gap">…</span><?php endif; ?>
          <a href="<?= $url($totalPages) ?>" class="pagination-item"><?= $totalPages ?></a>
        <?php endif; ?>

        <?php if ($page < $totalPages): ?>
          <a href="<?= $url($page + 1) ?>" class="pagination-item pagination-item--nav"
             rel="next" aria-label="Próxima página">›</a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<script>
/* Abre o drawer de rastreio de /admin/logistica/rastreios.
   Delegado: as linhas da tabela podem ser repaginadas. */
document.addEventListener('click', function (e) {
  var b = e.target.closest('[data-rastreio]');
  if (!b) return;
  if (!window.LogRastreio) { return; }   // logistica.js ainda carregando
  window.LogRastreio.abrir(b.getAttribute('data-rastreio'));
});

/* ── Reimprimir etiqueta ─────────────────────────────────────────
 *
 * Chama o MESMO endpoint da tela de etiquetas. Ele já sabe de onde tirar o
 * PDF (rótulo salvo, URL externa ou busca na transportadora) e registra o
 * evento `reimpressa` — escrever outra rota aqui duplicaria essa cascata e as
 * duas divergiriam na primeira mudança.
 */
document.addEventListener('click', function (e) {
  var b = e.target.closest('.ap-reimprimir');
  if (!b || b.disabled) return;

  var id = b.getAttribute('data-etiqueta');
  b.disabled = true;
  b.classList.add('is-carregando');

  // A aba é aberta ANTES do POST, e não no callback: navegador bloqueia
  // window.open disparado de dentro de uma resposta assíncrona, porque ali já
  // não há gesto do usuário. Abrimos vazia e preenchemos quando o PDF chega.
  var aba = window.open('', '_blank');

  fetch(BASE_URL + '/admin/logistica/etiquetas/imprimir', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'id=' + encodeURIComponent(id) + '&_csrf_token=' + encodeURIComponent(window.CSRF_TOKEN || ''),
  })
    .then(function (r) { return r.json(); })
    .then(function (res) {
      b.disabled = false;
      b.classList.remove('is-carregando');

      if (res && res.ok && res.url_pdf) {
        if (aba) aba.location = res.url_pdf;
        else     window.location = res.url_pdf;
        return;
      }
      if (aba) aba.close();
      adminToast((res && res.erro) || 'Não foi possível reimprimir a etiqueta.', 'error');
    })
    .catch(function () {
      b.disabled = false;
      b.classList.remove('is-carregando');
      if (aba) aba.close();
      adminToast('Erro de comunicação ao reimprimir.', 'error');
    });
});
</script>