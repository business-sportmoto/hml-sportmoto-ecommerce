<?php
/**
 * admin/views/chat/dashboard.php
 *
 * @var array $kpis @var array $serie @var array $serieContatos @var array $porHora
 * @var array $topFluxos @var array $topGatilhos @var array $porTag @var array $falhas
 * @var array $saude @var int $dias @var float|null $tempoResposta
 */
$base = defined('BASE_URL') ? BASE_URL : '';
$h    = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$n    = fn($v) => number_format((float)$v, 0, ',', '.');

/** Cartão de KPI com variação percentual. */
$kpi = function (string $rotulo, array $d, string $sub = '') use ($n) {
    $temVar = isset($d['variacao']);
    $v      = (float)($d['variacao'] ?? 0);
    $classe = $v > 0 ? 'sobe' : ($v < 0 ? 'desce' : 'igual');
    $seta   = $v > 0 ? '↑' : ($v < 0 ? '↓' : '–');
    ob_start(); ?>
    <div class="ch-kpi">
      <div class="ch-kpi-rot"><?= htmlspecialchars($rotulo, ENT_QUOTES) ?></div>
      <div class="ch-kpi-val"><?= $n($d['valor'] ?? 0) ?></div>
      <?php if ($temVar): ?>
        <div class="ch-kpi-var ch-kpi-var--<?= $classe ?>">
          <?= $seta ?> <?= number_format(abs($v), 1, ',', '.') ?>%
          <span class="ch-mut" style="font-weight:500;">vs. período anterior</span>
        </div>
      <?php elseif ($sub !== ''): ?>
        <div class="ch-kpi-sub"><?= htmlspecialchars($sub, ENT_QUOTES) ?></div>
      <?php endif; ?>
    </div>
    <?php return ob_get_clean();
};

/**
 * Gráfico de barras agrupadas em SVG puro.
 * Sem biblioteca: são poucos pontos e o admin já carrega JS demais.
 */
$grafico = function (array $dados, array $series, int $altura = 190) use ($h) {
    if (!$dados) return '<div class="ch-vazio">Sem dados no período.</div>';

    $max = 1;
    foreach ($dados as $d) {
        foreach ($series as $s) $max = max($max, (int)($d[$s['chave']] ?? 0));
    }
    $topo = (int)(ceil($max / 5) * 5) ?: 5;

    $largura   = 900;
    $padE      = 38; $padB = 26; $padT = 10;
    $areaW     = $largura - $padE - 10;
    $areaH     = $altura - $padB - $padT;
    $porGrupo  = $areaW / max(1, count($dados));
    $larguraB  = max(2, ($porGrupo * 0.62) / max(1, count($series)));

    // Rótulos do eixo X só de tempos em tempos — senão viram borrão
    $passo = max(1, (int)ceil(count($dados) / 12));

    ob_start(); ?>
    <svg class="ch-gr" viewBox="0 0 <?= $largura ?> <?= $altura ?>" preserveAspectRatio="none" role="img">
      <?php for ($i = 0; $i <= 4; $i++):
        $y = $padT + ($areaH / 4) * $i;
        $v = (int)round($topo - ($topo / 4) * $i); ?>
        <line class="ch-gr-grade" x1="<?= $padE ?>" y1="<?= round($y, 1) ?>" x2="<?= $largura - 10 ?>" y2="<?= round($y, 1) ?>"/>
        <text class="ch-gr-eixo" x="<?= $padE - 6 ?>" y="<?= round($y + 3, 1) ?>" text-anchor="end"><?= $v ?></text>
      <?php endfor; ?>

      <?php foreach ($dados as $i => $d):
        $x0 = $padE + $i * $porGrupo + ($porGrupo * 0.19); ?>
        <?php foreach ($series as $j => $s):
          $val = (int)($d[$s['chave']] ?? 0);
          $alt = $topo > 0 ? ($val / $topo) * $areaH : 0;
          $x   = $x0 + $j * $larguraB;
          $y   = $padT + $areaH - $alt; ?>
          <rect class="ch-barra" x="<?= round($x, 1) ?>" y="<?= round($y, 1) ?>"
                width="<?= round($larguraB - 1, 1) ?>" height="<?= round(max(0, $alt), 1) ?>"
                fill="<?= $s['cor'] ?>" rx="1.5">
            <title><?= $h($d['rotulo'] ?? '') ?> — <?= $h($s['rotulo']) ?>: <?= $val ?></title>
          </rect>
        <?php endforeach; ?>
        <?php if ($i % $passo === 0): ?>
          <text class="ch-gr-eixo" x="<?= round($x0 + $porGrupo * 0.15, 1) ?>"
                y="<?= $altura - 8 ?>" text-anchor="middle"><?= $h($d['rotulo'] ?? '') ?></text>
        <?php endif; ?>
      <?php endforeach; ?>
    </svg>
    <div class="ch-gr-legenda">
      <?php foreach ($series as $s): ?>
        <span><i class="ch-gr-cor" style="background:<?= $s['cor'] ?>"></i><?= $h($s['rotulo']) ?></span>
      <?php endforeach; ?>
    </div>
    <?php return ob_get_clean();
};
?>

<div class="ch">

  <div class="ch-head">
    <div>
      <h1>Chat — Visão geral</h1>
      <p>Atendimento e automação por WhatsApp. Números dos últimos <?= (int)$dias ?> dias.</p>
    </div>
    <div class="ch-head-acoes">
      <select class="ch-select" id="ch-periodo" style="width:auto;">
        <?php foreach ([7 => '7 dias', 14 => '14 dias', 30 => '30 dias', 60 => '60 dias', 90 => '90 dias'] as $v => $lbl): ?>
          <option value="<?= $v ?>" <?= (int)$dias === $v ? 'selected' : '' ?>><?= $lbl ?></option>
        <?php endforeach; ?>
      </select>
      <a href="<?= $base ?>/admin/chat/inbox" class="ch-btn ch-btn--wa">Abrir atendimento</a>
    </div>
  </div>

  <?php // ── Alertas de configuração ───────────────────────────────────── ?>
  <?php if (!$saude['meta_ok']): ?>
    <div class="ch-aviso ch-aviso--erro">
      <div>
        <strong>WhatsApp não conectado</strong>
        <?= $h($saude['meta_detalhe'] ?: 'Verifique META_PHONE_NUMBER_ID e META_CLOUD_API_TOKEN no .env.') ?>
        <a href="<?= $base ?>/admin/chat/config">Abrir configuração</a>
      </div>
    </div>
  <?php endif; ?>

  <?php if (!$saude['app_secret']): ?>
    <div class="ch-aviso ch-aviso--aviso">
      <div>
        <strong>Webhook bloqueado: falta o segredo do app</strong>
        Sem <code>META_APP_SECRET</code> no <code>.env</code>, a assinatura das mensagens recebidas
        não pode ser conferida e o webhook recusa tudo — o bot não vai responder ninguém.
        <a href="<?= $base ?>/admin/chat/config">Ver como resolver</a>
      </div>
    </div>
  <?php endif; ?>

  <?php if (!$saude['bot_ativo']): ?>
    <div class="ch-aviso ch-aviso--info">
      <div>
        <strong>Automação desligada</strong>
        As mensagens continuam chegando no atendimento, mas nenhum fluxo ou gatilho dispara.
      </div>
    </div>
  <?php endif; ?>

  <?php // ── KPIs ───────────────────────────────────────────────────────── ?>
  <div class="ch-kpis">
    <?= $kpi('Contatos',           $kpis['contatos_total'],  'total na base') ?>
    <?= $kpi('Novos contatos',     $kpis['contatos_novos']) ?>
    <?= $kpi('Recebidas',          $kpis['msgs_recebidas']) ?>
    <?= $kpi('Enviadas',           $kpis['msgs_enviadas']) ?>
    <?= $kpi('Janela aberta',      $kpis['janela_aberta'],   'dá para mandar texto livre') ?>
    <?= $kpi('Conversas abertas',  $kpis['conversas_abertas'], $kpis['nao_lidas']['valor'] . ' não lida(s)') ?>
    <?= $kpi('Sessões ativas',     $kpis['sessoes_ativas'],  'fluxos em andamento') ?>
    <?= $kpi('Falhas de envio',    $kpis['falhas']) ?>
  </div>

  <?php // ── Gráficos ───────────────────────────────────────────────────── ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(380px,1fr));gap:16px;margin-bottom:16px;">
    <div class="ch-card">
      <div class="ch-card-head">
        <h2>Mensagens por dia</h2>
        <?php if ($tempoResposta !== null): ?>
          <span class="ch-badge ch-badge--info">
            1ª resposta humana: <?= number_format($tempoResposta, 1, ',', '.') ?> min
          </span>
        <?php endif; ?>
      </div>
      <div class="ch-card-body">
        <?= $grafico($serie, [
              ['chave' => 'entrada', 'rotulo' => 'Recebidas', 'cor' => '#25d366'],
              ['chave' => 'saida',   'rotulo' => 'Enviadas',  'cor' => '#2563eb'],
              ['chave' => 'falhas',  'rotulo' => 'Falhas',    'cor' => '#dc2626'],
            ]) ?>
      </div>
    </div>

    <div class="ch-card">
      <div class="ch-card-head"><h2>Novos contatos por dia</h2></div>
      <div class="ch-card-body">
        <?= $grafico($serieContatos, [
              ['chave' => 'novos', 'rotulo' => 'Novos contatos', 'cor' => '#8b5cf6'],
            ]) ?>
      </div>
    </div>
  </div>

  <div class="ch-card" style="margin-bottom:16px;">
    <div class="ch-card-head">
      <h2>Quando as pessoas escrevem</h2>
      <span class="ch-sm ch-mut">mensagens recebidas por hora — últimos 14 dias</span>
    </div>
    <div class="ch-card-body">
      <?= $grafico(array_map(fn($x) => ['rotulo' => $x['hora'], 'total' => $x['total']], $porHora), [
            ['chave' => 'total', 'rotulo' => 'Recebidas', 'cor' => '#0ea472'],
          ], 160) ?>
    </div>
  </div>

  <?php // ── Tabelas ────────────────────────────────────────────────────── ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:16px;">

    <div class="ch-card">
      <div class="ch-card-head">
        <h2>Fluxos</h2>
        <a href="<?= $base ?>/admin/chat/fluxos" class="ch-btn ch-btn--sm">Ver todos</a>
      </div>
      <?php if (!$topFluxos): ?>
        <div class="ch-vazio">
          <strong>Nenhum fluxo ainda</strong>
          Um fluxo é a conversa automática: o que o bot responde e o que ele pergunta.
          <div style="margin-top:12px;"><a href="<?= $base ?>/admin/chat/fluxos" class="ch-btn ch-btn--pri ch-btn--sm">Criar o primeiro fluxo</a></div>
        </div>
      <?php else: ?>
      <div class="ch-tabela-wrap">
        <table class="ch-tabela">
          <thead><tr>
            <th>Fluxo</th><th class="ch-num">Iniciadas</th>
            <th class="ch-num">Concluídas</th><th class="ch-num">Taxa</th>
          </tr></thead>
          <tbody>
            <?php foreach ($topFluxos as $f): ?>
            <tr>
              <td>
                <a href="<?= $base ?>/admin/chat/fluxos/<?= (int)$f['id'] ?>"><?= $h($f['nome']) ?></a>
                <?php if ($f['status'] !== 'publicado'): ?>
                  <span class="ch-badge ch-badge--neutro" style="margin-left:6px;"><?= $h($f['status']) ?></span>
                <?php endif; ?>
                <?php if ((int)$f['erros'] > 0): ?>
                  <span class="ch-badge ch-badge--erro" style="margin-left:6px;"><?= (int)$f['erros'] ?> erro(s)</span>
                <?php endif; ?>
              </td>
              <td class="ch-num"><?= $n($f['iniciadas']) ?></td>
              <td class="ch-num"><?= $n($f['concluidas']) ?></td>
              <td class="ch-num"><?= number_format((float)$f['taxa_conclusao'], 1, ',', '.') ?>%</td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <div class="ch-card">
      <div class="ch-card-head">
        <h2>Gatilhos mais acionados</h2>
        <a href="<?= $base ?>/admin/chat/gatilhos" class="ch-btn ch-btn--sm">Gerenciar</a>
      </div>
      <?php if (!$topGatilhos): ?>
        <div class="ch-vazio">
          <strong>Nenhum gatilho disparou ainda</strong>
          Gatilhos decidem o que o bot faz quando alguém escreve — por palavra-chave,
          primeira mensagem ou resposta padrão.
        </div>
      <?php else: ?>
      <div class="ch-tabela-wrap">
        <table class="ch-tabela">
          <thead><tr><th>Gatilho</th><th>Tipo</th><th class="ch-num">Disparos</th></tr></thead>
          <tbody>
            <?php foreach ($topGatilhos as $g): ?>
            <tr>
              <td>
                <?= $h($g['nome']) ?>
                <?php if (!(int)$g['ativo']): ?>
                  <span class="ch-badge ch-badge--neutro" style="margin-left:6px;">inativo</span>
                <?php endif; ?>
                <?php if ($g['padrao']): ?>
                  <div class="ch-sm ch-mut ch-mono" style="margin-top:2px;"><?= $h(mb_substr($g['padrao'], 0, 46)) ?></div>
                <?php endif; ?>
              </td>
              <td class="ch-sm"><?= $h(str_replace('_', ' ', $g['tipo'])) ?></td>
              <td class="ch-num"><?= $n($g['total_disparos']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <div class="ch-card">
      <div class="ch-card-head">
        <h2>Contatos por tag</h2>
        <a href="<?= $base ?>/admin/chat/tags" class="ch-btn ch-btn--sm">Gerenciar tags</a>
      </div>
      <div class="ch-card-body">
        <?php if (!$porTag): ?>
          <div class="ch-vazio">Nenhuma tag criada.</div>
        <?php else: ?>
          <?php $maxTag = max(1, max(array_map(fn($t) => (int)$t['total'], $porTag))); ?>
          <?php foreach ($porTag as $t): ?>
            <div style="margin-bottom:11px;">
              <div class="ch-flex-sb" style="margin-bottom:4px;">
                <span class="ch-tag" style="color:<?= $h($t['cor']) ?>;background:<?= $h($t['cor']) ?>22;"><?= $h($t['nome']) ?></span>
                <span class="ch-sm ch-b"><?= $n($t['total']) ?></span>
              </div>
              <div class="ch-prog">
                <span style="width:<?= round(((int)$t['total'] / $maxTag) * 100, 1) ?>%;background:<?= $h($t['cor']) ?>;"></span>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="ch-card">
      <div class="ch-card-head">
        <h2>Últimas falhas de envio</h2>
      </div>
      <?php if (!$falhas): ?>
        <div class="ch-vazio">Nenhuma falha registrada. 👌</div>
      <?php else: ?>
      <div class="ch-tabela-wrap">
        <table class="ch-tabela">
          <thead><tr><th>Contato</th><th>Erro</th><th>Quando</th></tr></thead>
          <tbody>
            <?php foreach ($falhas as $f): ?>
            <tr>
              <td><?= $h($f['nome'] ?: ($f['nome_perfil'] ?: $f['wa_id'])) ?></td>
              <td class="ch-sm">
                <?php if ($f['erro_codigo']): ?><span class="ch-badge ch-badge--erro"><?= (int)$f['erro_codigo'] ?></span> <?php endif; ?>
                <?= $h(mb_substr((string)$f['erro_detalhe'], 0, 70)) ?>
              </td>
              <td class="ch-sm ch-mut"><?= date('d/m H:i', strtotime((string)$f['criado_em'])) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

  </div>
</div>

<script>
(function ($) {
  // Troca de período recarrega a página: o servidor já monta todos os gráficos
  $('#ch-periodo').on('change', function () {
    var u = new URL(window.location.href);
    u.searchParams.set('dias', $(this).val());
    window.location.href = u.toString();
  });
})(jQuery);
</script>
