<?php
/**
 * admin/views/chat/campanhas.php
 * @var array $campanhas
 */
$base = defined('BASE_URL') ? BASE_URL : '';
$h    = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$n    = fn($v) => number_format((float)$v, 0, ',', '.');

$badge = [
    'rascunho'  => ['Rascunho',   'neutro'],
    'agendada'  => ['Agendada',   'info'],
    'enviando'  => ['Enviando',   'aviso'],
    'pausada'   => ['Pausada',    'aviso'],
    'concluida' => ['Concluída',  'ok'],
    'cancelada' => ['Cancelada',  'neutro'],
    'erro'      => ['Erro',       'erro'],
];
?>

<div class="ch">

  <div class="ch-head">
    <div>
      <h1>Disparos</h1>
      <p>Envio em massa para um segmento de contatos.</p>
    </div>
    <div class="ch-head-acoes">
      <a href="<?= $base ?>/admin/chat/templates" class="ch-btn">Templates</a>
      <a href="<?= $base ?>/admin/chat/campanhas/nova" class="ch-btn ch-btn--pri">Nova campanha</a>
    </div>
  </div>

  <div class="ch-aviso ch-aviso--info">
    <div>
      <strong class="ch-aviso-tit">Como a Meta trata disparo em massa</strong>
      Para quem <em>não</em> escreveu para a loja nas últimas 24h, só um
      <strong>template aprovado</strong> passa. Campanha de texto livre alcança apenas
      quem está com a janela aberta — os demais são marcados como “pulados”, não como erro.
    </div>
  </div>

  <div class="ch-card">
    <?php if (!$campanhas): ?>
      <div class="ch-vazio">
        <strong>Nenhuma campanha ainda</strong>
        <p style="max-width:50ch;margin:0 auto 16px;">
          Escolha um template aprovado, monte o público por tags e dispare — com controle
          de ritmo para não queimar a reputação do número.
        </p>
        <a href="<?= $base ?>/admin/chat/campanhas/nova" class="ch-btn ch-btn--pri">Criar a primeira</a>
      </div>
    <?php else: ?>
    <div class="ch-tabela-wrap">
      <table class="ch-tabela">
        <thead>
          <tr>
            <th>Campanha</th><th>Situação</th><th>Progresso</th>
            <th class="ch-num">Entregues</th><th class="ch-num">Lidos</th>
            <th class="ch-num">Falhas</th><th>Quando</th><th style="width:1%;"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($campanhas as $c):
            [$lbl, $cor] = $badge[$c['status']] ?? [$c['status'], 'neutro'];
            $tot  = max(1, (int)$c['total_destinatarios']);
            $env  = (int)$c['total_enviados'] + (int)$c['total_falhas'] + (int)$c['total_pulados'];
            $pct  = min(100, round(($env / $tot) * 100));
          ?>
          <tr>
            <td>
              <a href="<?= $base ?>/admin/chat/campanhas/<?= (int)$c['id'] ?>" class="ch-b"><?= $h($c['nome']) ?></a>
              <div class="ch-sm ch-mut">
                <?= $c['tipo'] === 'template' ? 'template ' . $h($c['template_nome']) : 'texto livre' ?>
              </div>
            </td>
            <td><span class="ch-badge ch-badge--<?= $cor ?>"><?= $h($lbl) ?></span></td>
            <td style="min-width:130px;">
              <?php if ((int)$c['total_destinatarios'] > 0): ?>
                <div class="ch-prog" title="<?= $env ?> de <?= (int)$c['total_destinatarios'] ?>">
                  <span style="width:<?= $pct ?>%;background:var(--blue);"></span>
                </div>
                <div class="ch-sm ch-mut" style="margin-top:3px;">
                  <?= $n($env) ?> / <?= $n($c['total_destinatarios']) ?>
                </div>
              <?php else: ?>
                <span class="ch-mut">—</span>
              <?php endif; ?>
            </td>
            <td class="ch-num"><?= $n($c['total_entregues']) ?></td>
            <td class="ch-num"><?= $n($c['total_lidos']) ?></td>
            <td class="ch-num"><?= (int)$c['total_falhas'] > 0
                  ? '<span style="color:var(--danger)">' . $n($c['total_falhas']) . '</span>' : '0' ?></td>
            <td class="ch-sm ch-mut">
              <?php if ($c['agendado_para'] && $c['status'] === 'agendada'): ?>
                <?= date('d/m/Y H:i', strtotime((string)$c['agendado_para'])) ?>
              <?php elseif ($c['iniciado_em']): ?>
                <?= date('d/m/Y H:i', strtotime((string)$c['iniciado_em'])) ?>
              <?php else: ?>
                criada <?= date('d/m/Y', strtotime((string)$c['criado_em'])) ?>
              <?php endif; ?>
            </td>
            <td>
              <a href="<?= $base ?>/admin/chat/campanhas/<?= (int)$c['id'] ?>" class="ch-btn ch-btn--sm">Ver</a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
