<?php
/**
 * admin/views/logs/index.php
 *
 * Dashboard de logs. Uma linha = um PROBLEMA (agrupado por fingerprint),
 * não um evento solto — por isso o contador de ocorrências é protagonista.
 *
 * SEGURANÇA: mensagem, URL, arquivo e user-agent podem conter payload de
 * atacante (um scanner injeta na URL e isso vira uma linha aqui). TODO output
 * passa por View::e(). Renderizar cru seria stored XSS no próprio painel.
 */
$f = $filtros;
?>

<div class="lg">

  <!-- ── Cabeçalho ───────────────────────────────────────────────────── -->
  <header class="lg-head">
    <div>
      <h1 class="lg-title">Logs</h1>
      <p class="lg-sub">Erros agrupados por assinatura. Últimas 24h no resumo.</p>
    </div>
    <div class="lg-head-actions">
      <button type="button" class="lg-btn lg-btn--ghost" data-limpar="antigos">
        Limpar ruído antigo
      </button>
      <button type="button" class="lg-btn lg-btn--ghost" data-limpar="resolvidos">
        Apagar resolvidos
      </button>
    </div>
  </header>

  <!-- ── Resumo 24h ──────────────────────────────────────────────────── -->
  <div class="lg-stats">
    <div class="lg-stat lg-stat--critical">
      <span class="lg-stat-num"><?= (int) $stats['criticos'] ?></span>
      <span class="lg-stat-lbl">Críticos abertos</span>
    </div>
    <div class="lg-stat lg-stat--error">
      <span class="lg-stat-num"><?= (int) $stats['erros'] ?></span>
      <span class="lg-stat-lbl">Erros abertos</span>
    </div>
    <div class="lg-stat lg-stat--warning">
      <span class="lg-stat-num"><?= (int) $stats['avisos'] ?></span>
      <span class="lg-stat-lbl">Avisos abertos</span>
    </div>
    <div class="lg-stat">
      <span class="lg-stat-num"><?= number_format((int) $stats['eventos'], 0, ',', '.') ?></span>
      <span class="lg-stat-lbl">Eventos registrados</span>
    </div>
  </div>

  <!-- ── Filtros ─────────────────────────────────────────────────────── -->
  <form class="lg-filters" method="GET" action="<?= ADMIN_URL ?>/logs" id="lg-filters">

    <div class="lg-search">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" aria-hidden="true">
        <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
      </svg>
      <input type="search" name="q" value="<?= View::e($f['q']) ?>"
             placeholder="Buscar em mensagem, arquivo, URL ou classe da exceção"
             aria-label="Buscar nos logs">
    </div>

    <div class="lg-filter-row">
      <!-- Nível -->
      <div class="lg-levels" role="group" aria-label="Filtrar por nível">
        <?php
          $niveis = [
            ''         => 'Todos',
            'critical' => 'Crítico',
            'error'    => 'Erro',
            'warning'  => 'Aviso',
            'info'     => 'Info',
            'debug'    => 'Debug',
          ];
          foreach ($niveis as $val => $label):
        ?>
        <label class="lg-level lg-level--<?= $val ?: 'all' ?> <?= $f['nivel'] === $val ? 'is-on' : '' ?>">
          <input type="radio" name="nivel" value="<?= View::e($val) ?>"
                 <?= $f['nivel'] === $val ? 'checked' : '' ?>>
          <?= View::e($label) ?>
        </label>
        <?php endforeach; ?>
      </div>

      <!-- Canal -->
      <select name="canal" class="lg-select" aria-label="Canal">
        <option value="">Todos os canais</option>
        <?php foreach ($canais as $c): ?>
        <option value="<?= View::e($c) ?>" <?= $f['canal'] === $c ? 'selected' : '' ?>>
          <?= View::e($c) ?>
        </option>
        <?php endforeach; ?>
      </select>

      <!-- Período -->
      <select name="periodo" class="lg-select" aria-label="Período">
        <?php foreach (['1h'=>'Última hora','2h'=>'2 horas','24h'=>'24 horas',
                        '7d'=>'7 dias','30d'=>'30 dias','tudo'=>'Tudo'] as $v=>$l): ?>
        <option value="<?= $v ?>" <?= $f['periodo'] === $v ? 'selected' : '' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select>

      <!-- Status -->
      <select name="status" class="lg-select" aria-label="Status">
        <?php foreach (['abertos'=>'Abertos','resolvidos'=>'Resolvidos','todos'=>'Todos'] as $v=>$l): ?>
        <option value="<?= $v ?>" <?= $f['status'] === $v ? 'selected' : '' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select>

      <!-- Filtro por usuário (aceita o ID de usuarios.id) -->
      <input type="number" name="usuario_id" class="lg-select lg-uid"
             value="<?= $f['usuario_id'] ?: '' ?>"
             placeholder="ID do usuário" min="1"
             aria-label="Filtrar por ID de usuário" style="width:130px;">

      <!-- Ordem -->
      <select name="ordem" class="lg-select" aria-label="Ordenar">
        <option value="recentes"   <?= $f['ordem'] === 'recentes'   ? 'selected' : '' ?>>Mais recentes</option>
        <option value="frequentes" <?= $f['ordem'] === 'frequentes' ? 'selected' : '' ?>>Mais frequentes</option>
      </select>

      <button type="submit" class="lg-btn lg-btn--primary">Aplicar</button>
      <a href="<?= ADMIN_URL ?>/logs" class="lg-btn lg-btn--ghost">Limpar filtros</a>
    </div>
  </form>

  <!-- ── Lista ───────────────────────────────────────────────────────── -->
  <div class="lg-count">
    <?= number_format((int) $total, 0, ',', '.') ?>
    <?= (int) $total === 1 ? 'registro' : 'registros' ?>
  </div>

  <?php if (!empty($usuarioFiltrado)): $u = $usuarioFiltrado; ?>
  <div class="lg-userbar">
    <div class="lg-userbar-info">
      <span class="lg-userbar-label">Logs de</span>
      <strong><?= View::e($u['nome']) ?></strong>
      <code><?= View::e($u['email']) ?></code>
      <span class="lg-chip"><?= View::e($u['tipo']) ?></span>
    </div>

    <div class="lg-userbar-actions">
      <?php if (!empty($u['cliente_id'])): ?>
        <a href="<?= ADMIN_URL ?>/clientes/<?= (int) $u['cliente_id'] ?>"
           class="lg-btn lg-btn--ghost">Abrir perfil</a>
      <?php endif; ?>
      <a href="<?= ADMIN_URL ?>/logs" class="lg-btn lg-btn--ghost">Remover filtro</a>
    </div>
  </div>

  <p class="lg-warn">
    Registros anteriores à v2 do log podem ter atribuição de usuário incorreta
    (gravavam <code>admin_id</code>/<code>cliente_id</code> na coluna
    <code>usuario_id</code>). Trate logs antigos com ressalva.
  </p>
  <?php endif; ?>

  <?php if (empty($logs)): ?>
    <div class="lg-empty">
      <p class="lg-empty-title">Nenhum log com esses filtros.</p>
      <p>Amplie o período ou limpe os filtros para ver mais.</p>
    </div>
  <?php else: ?>

  <ul class="lg-list">
    <?php foreach ($logs as $l): ?>
    <li class="lg-row <?= (int) $l['resolvido'] === 1 ? 'is-resolved' : '' ?>"
        data-log-id="<?= (int) $l['id'] ?>"
        tabindex="0" role="button"
        aria-label="Abrir detalhes do log <?= (int) $l['id'] ?>">

      <span class="lg-dot lg-dot--<?= View::e($l['nivel']) ?>" title="<?= View::e($l['nivel']) ?>"></span>

      <div class="lg-row-main">
        <p class="lg-msg"><?= View::e(mb_strimwidth((string) $l['mensagem'], 0, 160, '…')) ?></p>

        <div class="lg-meta">
          <?php if (!empty($l['tipo'])): ?>
            <code class="lg-tipo"><?= View::e($l['tipo']) ?></code>
          <?php endif; ?>

          <?php if (!empty($l['arquivo'])): ?>
            <code class="lg-file"
                  title="<?= View::e($l['arquivo']) ?>"><?= View::e(basename((string) $l['arquivo'])) ?><?= $l['linha'] ? ':' . (int) $l['linha'] : '' ?></code>
          <?php endif; ?>

          <span class="lg-chip"><?= View::e($l['canal']) ?></span>

          <?php if (!empty($l['metodo']) && !empty($l['url'])): ?>
            <span class="lg-route" title="<?= View::e($l['url']) ?>">
              <?= View::e($l['metodo']) ?> <?= View::e(mb_strimwidth((string) $l['url'], 0, 48, '…')) ?>
            </span>
          <?php endif; ?>
        </div>
      </div>

      <!-- Ocorrências: o número que diz se isto é um caso isolado ou um incêndio -->
      <span class="lg-occ <?= (int) $l['ocorrencias'] > 20 ? 'is-hot' : '' ?>"
            title="<?= (int) $l['ocorrencias'] ?> ocorrências">
        <?= (int) $l['ocorrencias'] > 999 ? '999+' : (int) $l['ocorrencias'] ?>×
      </span>

      <time class="lg-time" datetime="<?= View::e((string) $l['visto_em']) ?>">
        <?= View::e(date('d/m H:i', strtotime((string) ($l['visto_em'] ?: $l['criado_em'])))) ?>
      </time>
    </li>
    <?php endforeach; ?>
  </ul>

  <!-- Paginação -->
  <?php if (($páginas ?? 1) > 1): ?>
  <nav class="lg-pager" aria-label="Paginação">
    <?php
      $qs = $_GET;
      for ($p = max(1, $f['page'] - 2); $p <= min($páginas, $f['page'] + 2); $p++):
        $qs['page'] = $p;
    ?>
      <a href="?<?= View::e(http_build_query($qs)) ?>"
         class="lg-page <?= $p === $f['page'] ? 'is-on' : '' ?>"><?= $p ?></a>
    <?php endfor; ?>
  </nav>
  <?php endif; ?>

  <?php endif; ?>
</div>

<!-- ── Painel de detalhe (preenchido via JS) ─────────────────────────── -->
<div class="lg-drawer" id="lg-drawer" hidden aria-modal="true" role="dialog"
     aria-labelledby="lg-drawer-title">
  <div class="lg-drawer-backdrop" data-close></div>
  <aside class="lg-drawer-panel">
    <header class="lg-drawer-head">
      <h2 id="lg-drawer-title">Detalhe do erro</h2>
      <button type="button" class="lg-drawer-close" data-close aria-label="Fechar">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
             stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </header>
    <div class="lg-drawer-body" id="lg-drawer-body">
      <div class="lg-loading">Carregando…</div>
    </div>
  </aside>
</div>

<input type="hidden" id="lg-csrf" value="<?= View::e(SecurityHelper::generateCsrf()) ?>">

