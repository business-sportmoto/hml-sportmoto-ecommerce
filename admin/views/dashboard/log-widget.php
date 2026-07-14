<?php
/**
 * admin/views/partials/log-widget.php
 *
 * Widget compacto de logs para o dashboard.
 * Inclua no dashboard com:  <?php include ADMIN_PATH . '/views/partials/log-widget.php'; ?>
 *
 * SEGURANÇA:
 *  - Só renderiza para 'super' (o dashboard de logs também é super-only;
 *    mostrar os números para outros papéis vazaria informação sobre falhas
 *    internas — inclusive a existência de erros exploráveis).
 *  - A mensagem do log pode conter payload de atacante -> View::e() sempre.
 *
 * Cada número é um LINK para o dashboard já filtrado — o widget não é
 * decoração, é ponto de partida da investigação.
 */

if (Session::get('admin_nivel') !== 'super') {
    return;
}

$lw = LogService::resumo();
$lwTotalAbertos = $lw['criticos'] + $lw['erros'] + $lw['avisos'];
$lwSaudavel     = ($lw['criticos'] + $lw['erros']) === 0;
?>

<section class="lw <?= $lwSaudavel ? 'lw--ok' : 'lw--alert' ?>" aria-label="Resumo de logs">

  <header class="lw-head">
    <div class="lw-head-title">
      <span class="lw-pulse <?= $lwSaudavel ? '' : 'lw-pulse--alert' ?>"></span>
      <h3>Saúde do sistema</h3>
      <span class="lw-period">24h</span>
    </div>
    <a href="<?= ADMIN_URL ?>/logs" class="lw-all">
      Ver logs
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
           stroke-linecap="round" aria-hidden="true">
        <line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>
      </svg>
    </a>
  </header>

  <?php if ($lwTotalAbertos === 0): ?>

    <p class="lw-clean">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
           stroke-linecap="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
      Nenhum erro aberto nas últimas 24 horas.
    </p>

  <?php else: ?>

    <!-- Números clicáveis: cada um leva ao dashboard já filtrado -->
    <div class="lw-nums">
      <a class="lw-num lw-num--critical <?= $lw['criticos'] ? '' : 'is-zero' ?>"
         href="<?= ADMIN_URL ?>/logs?nivel=critical&status=abertos&periodo=24h">
        <span class="lw-n"><?= (int) $lw['criticos'] ?></span>
        <span class="lw-l">Críticos</span>
      </a>

      <a class="lw-num lw-num--error <?= $lw['erros'] ? '' : 'is-zero' ?>"
         href="<?= ADMIN_URL ?>/logs?nivel=error&status=abertos&periodo=24h">
        <span class="lw-n"><?= (int) $lw['erros'] ?></span>
        <span class="lw-l">Erros</span>
      </a>

      <a class="lw-num lw-num--warning <?= $lw['avisos'] ? '' : 'is-zero' ?>"
         href="<?= ADMIN_URL ?>/logs?nivel=warning&status=abertos&periodo=24h">
        <span class="lw-n"><?= (int) $lw['avisos'] ?></span>
        <span class="lw-l">Avisos</span>
      </a>
    </div>

    <?php if (!empty($lw['pior'])): $p = $lw['pior']; ?>
    <!-- O problema mais recorrente: o número sozinho não diz o que fazer. -->
    <a class="lw-top" href="<?= ADMIN_URL ?>/logs?q=<?= urlencode(mb_substr((string) $p['mensagem'], 0, 40)) ?>">
      <span class="lw-top-badge lw-top-badge--<?= View::e($p['nivel']) ?>">
        <?= (int) $p['ocorrencias'] ?>×
      </span>
      <span class="lw-top-msg"><?= View::e(mb_strimwidth((string) $p['mensagem'], 0, 70, '…')) ?></span>
    </a>
    <?php endif; ?>

    <p class="lw-foot">
      <?= number_format((int) $lw['total'], 0, ',', '.') ?> ocorrências no total
    </p>

  <?php endif; ?>
</section>