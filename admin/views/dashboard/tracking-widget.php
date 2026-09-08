<?php
/**
 * admin/views/dashboard/tracking-widget.php
 *
 * Widget de saúde do pipeline de conversões (Meta CAPI / futuros
 * destinos). Inclua no dashboard com:
 *   <?php include ADMIN_PATH . '/views/dashboard/tracking-widget.php'; ?>
 *
 * POR QUE EXISTE: até aqui uma falha do dispatcher era silenciosa —
 * dead_letter crescia, eventos ficavam presos e ninguém via. Este
 * card transforma "perder dado sem saber" em "perder dado e ver".
 *
 * SEGURANÇA:
 *  - super-only, igual ao log-widget: os números expõem falhas
 *    internas, e cada um leva à tela de logs, que também é super-only.
 *    Mostrar para outro cargo geraria um link que só devolve 403.
 *
 * Reaproveita o design system .lw-* do log-widget de propósito: os
 * dois cards ficam lado a lado e dizem a mesma coisa ("o que está
 * quebrado agora"), então dividem a linguagem visual e o tema escuro.
 */

if (Session::get('admin_nivel') !== 'super') {
    return;
}

$tw = TrackingHealthService::resumo();

$twLogs = ADMIN_URL . '/logs?canal=tracking&status=abertos&periodo=24h';

// O card só fica vermelho por coisa acionável. 'pulados' (consentimento
// negado) é conformidade, não falha — vive no rodapé, nunca no alerta.
$twAlerta = !$tw['ok'];
?>

<section class="lw <?= $twAlerta ? 'lw--alert' : 'lw--ok' ?>" aria-label="Saúde do tracking">

  <header class="lw-head">
    <div class="lw-head-title">
      <span class="lw-pulse <?= $twAlerta ? 'lw-pulse--alert' : '' ?>"></span>
      <h3>Saúde do tracking</h3>
      <span class="lw-period">24h</span>
    </div>
    <a href="<?= $twLogs ?>" class="lw-all">
      Ver logs
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
           stroke-linecap="round" aria-hidden="true">
        <line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>
      </svg>
    </a>
  </header>

  <?php if ($tw['erro']): ?>

    <!-- Nunca mostrar zeros quando a medição falhou: zero parece saúde. -->
    <p class="lw-clean" style="color:var(--lw-warning)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
           stroke-linecap="round" aria-hidden="true">
        <circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.5v.01"/>
      </svg>
      Não foi possível ler o estado da fila.
    </p>

  <?php elseif (!$twAlerta): ?>

    <p class="lw-clean">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
           stroke-linecap="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
      Fila escoando normalmente.
    </p>

  <?php else: ?>

    <div class="lw-nums lw-nums--4">
      <!-- Presos: o dispatcher marca 'processing' e morre antes de fechar.
           buscarPendentes() só lê 'pending', então NADA devolve estes à
           fila — é a única perda definitiva e por isso vem primeiro. -->
      <a class="lw-num lw-num--critical <?= $tw['presos'] ? '' : 'is-zero' ?>" href="<?= $twLogs ?>">
        <span class="lw-n"><?= (int) $tw['presos'] ?></span>
        <span class="lw-l">Presos</span>
      </a>

      <!-- Atrasados: pending vencido com retry já liberado = cron parado. -->
      <a class="lw-num lw-num--error <?= $tw['atrasados'] ? '' : 'is-zero' ?>" href="<?= $twLogs ?>">
        <span class="lw-n"><?= (int) $tw['atrasados'] ?></span>
        <span class="lw-l">Atrasados</span>
      </a>

      <a class="lw-num lw-num--error <?= $tw['falhados'] ? '' : 'is-zero' ?>" href="<?= $twLogs ?>">
        <span class="lw-n"><?= (int) $tw['falhados'] ?></span>
        <span class="lw-l">Falhados</span>
      </a>

      <a class="lw-num lw-num--warning <?= $tw['dead_abertos'] ? '' : 'is-zero' ?>" href="<?= $twLogs ?>">
        <span class="lw-n"><?= (int) $tw['dead_abertos'] ?></span>
        <span class="lw-l">Dead letter</span>
      </a>
    </div>

    <?php
    // O número sozinho não diz o que fazer. Uma linha explica a condição
    // mais grave presente, na ordem em que doem.
    $twDiag = null;

    // Duração em texto curto, inline: um helper global aqui quebraria
    // se o widget fosse incluído duas vezes (cannot redeclare).
    $twIdade = '';
    if ($tw['atraso_min'] !== null) {
        $m = (int) $tw['atraso_min'];
        $twIdade = ' há ' . ($m < 60
            ? $m . ' min'
            : ($m < 1440 ? intdiv($m, 60) . 'h' : intdiv($m, 1440) . ' dia(s)'));
    }

    if ($tw['presos'] > 0) {
        $twDiag = $tw['presos'] . ' evento(s) travado(s) em processing — '
                . 'nada os devolve à fila sozinho.';
    } elseif ($tw['atrasados'] > 0) {
        $twDiag = 'Fila parada' . $twIdade
                . ' — verifique o cron conversion-dispatch.';
    } elseif ($tw['falhados'] > 0) {
        $twDiag = $tw['falhados'] . ' evento(s) esgotaram as tentativas de envio.';
    } elseif ($tw['dead_abertos'] > 0) {
        $twDiag = $tw['dead_abertos'] . ' evento(s) em dead letter aguardando reprocesso.';
    }
    ?>
    <?php if ($twDiag !== null): ?>
    <a class="lw-top" href="<?= $twLogs ?>">
      <span class="lw-top-badge lw-top-badge--critical">!</span>
      <span class="lw-top-msg"><?= View::e($twDiag) ?></span>
    </a>
    <?php endif; ?>

  <?php endif; ?>

  <?php if (!$tw['erro']): ?>
  <p class="lw-foot">
    <?= number_format((int) $tw['enviados_24h'], 0, ',', '.') ?> enviado(s) em 24h
    <?php if ($tw['pulados_24h'] > 0): ?>
      · <?= number_format((int) $tw['pulados_24h'], 0, ',', '.') ?> sem consentimento
    <?php endif; ?>
    <?php if (!empty($tw['ultimo_processado'])): ?>
      · último às <?= View::e(date('d/m H:i', strtotime((string) $tw['ultimo_processado']))) ?>
    <?php else: ?>
      · nenhum evento processado ainda
    <?php endif; ?>
  </p>
  <?php endif; ?>
</section>
