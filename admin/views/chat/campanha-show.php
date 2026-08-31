<?php
/**
 * admin/views/chat/campanha-show.php
 * @var array $campanha @var array $resumo @var array $destinatarios
 * @var int $total @var int $pagina @var int $porPagina @var string $filtroStatus
 */
$base = defined('BASE_URL') ? BASE_URL : '';
$h    = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$n    = fn($v) => number_format((float)$v, 0, ',', '.');

$c = $campanha;
$badge = [
    'rascunho'  => ['Rascunho',  'neutro'], 'agendada'  => ['Agendada',  'info'],
    'enviando'  => ['Enviando',  'aviso'],  'pausada'   => ['Pausada',   'aviso'],
    'concluida' => ['Concluída', 'ok'],     'cancelada' => ['Cancelada', 'neutro'],
    'erro'      => ['Erro',      'erro'],
];
[$lbl, $cor] = $badge[$c['status']] ?? [$c['status'], 'neutro'];

$statusDest = [
    'pendente' => ['Na fila',   'neutro'], 'enviado'  => ['Enviado',  'info'],
    'entregue' => ['Entregue',  'ok'],     'lido'     => ['Lido',     'ok'],
    'falhou'   => ['Falhou',    'erro'],   'pulado'   => ['Pulado',   'aviso'],
];
$paginas = (int)ceil($total / max(1, $porPagina));
$qs = fn(array $e = []) => '?' . http_build_query(array_merge($_GET, $e));

$editavel = in_array($c['status'], ['rascunho', 'agendada', 'pausada'], true);
$rodando  = $c['status'] === 'enviando';
?>

<div class="ch">

  <div class="ch-head">
    <div>
      <h1><?= $h($c['nome']) ?></h1>
      <p>
        <span class="ch-badge ch-badge--<?= $cor ?>"><?= $h($lbl) ?></span>
        <?= $c['tipo'] === 'template'
              ? 'template <span class="ch-mono">' . $h($c['template_nome']) . '</span>'
              : 'texto livre' ?>
      </p>
    </div>
    <div class="ch-head-acoes">
      <a href="<?= $base ?>/admin/chat/campanhas" class="ch-btn">← Campanhas</a>
      <?php if ($editavel): ?>
        <a href="<?= $base ?>/admin/chat/campanhas/<?= (int)$c['id'] ?>/editar" class="ch-btn">Editar</a>
        <button type="button" class="ch-btn ch-btn--wa" id="ch-iniciar">
          <?= $c['status'] === 'pausada' ? 'Retomar' : 'Disparar agora' ?>
        </button>
      <?php endif; ?>
      <?php if ($rodando): ?>
        <button type="button" class="ch-btn" id="ch-pausar">Pausar</button>
      <?php endif; ?>
      <?php if ($editavel || $rodando): ?>
        <button type="button" class="ch-btn ch-btn--perigo" id="ch-cancelar">Cancelar</button>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($rodando): ?>
    <div class="ch-aviso ch-aviso--info" id="ch-aviso-rodando">
      <div>
        <strong>Envio em andamento</strong>
        O worker consome a fila a cada minuto, no ritmo de
        <?= (int)$c['ritmo_por_minuto'] ?> mensagens/minuto. Esta página atualiza sozinha.
      </div>
    </div>
  <?php endif; ?>

  <?php if ($c['erro_detalhe']): ?>
    <div class="ch-aviso ch-aviso--erro">
      <div><strong>Erro</strong> <?= $h($c['erro_detalhe']) ?></div>
    </div>
  <?php endif; ?>

  <div class="ch-kpis" id="ch-kpis">
    <div class="ch-kpi">
      <div class="ch-kpi-rot">Destinatários</div>
      <div class="ch-kpi-val" data-k="destinatarios"><?= $n($c['total_destinatarios']) ?></div>
    </div>
    <div class="ch-kpi">
      <div class="ch-kpi-rot">Enviados</div>
      <div class="ch-kpi-val" data-k="enviados"><?= $n($c['total_enviados']) ?></div>
    </div>
    <div class="ch-kpi">
      <div class="ch-kpi-rot">Entregues</div>
      <div class="ch-kpi-val" data-k="entregues"><?= $n($c['total_entregues']) ?></div>
    </div>
    <div class="ch-kpi">
      <div class="ch-kpi-rot">Lidos</div>
      <div class="ch-kpi-val" data-k="lidos"><?= $n($c['total_lidos']) ?></div>
    </div>
    <div class="ch-kpi">
      <div class="ch-kpi-rot">Falhas</div>
      <div class="ch-kpi-val" data-k="falhas" style="<?= (int)$c['total_falhas'] > 0 ? 'color:var(--danger)' : '' ?>">
        <?= $n($c['total_falhas']) ?>
      </div>
    </div>
    <div class="ch-kpi">
      <div class="ch-kpi-rot">Pulados</div>
      <div class="ch-kpi-val" data-k="pulados"><?= $n($c['total_pulados']) ?></div>
      <div class="ch-kpi-sub">fora da janela ou opt-out</div>
    </div>
  </div>

  <?php if ((int)$c['total_pulados'] > 0 && $c['tipo'] === 'texto'): ?>
    <div class="ch-aviso ch-aviso--aviso">
      <div>
        <strong><?= $n($c['total_pulados']) ?> contato(s) não foram alcançados</strong>
        Campanha de texto livre só chega em quem tem a janela de 24h aberta.
        Para alcançar o resto, refaça a campanha com um template aprovado.
      </div>
    </div>
  <?php endif; ?>

  <div class="ch-card">
    <div class="ch-card-head">
      <h2>Destinatários</h2>
      <div class="ch-flex">
        <?php foreach (['' => 'Todos'] + array_map(fn($x) => $x[0], $statusDest) as $k => $lblF): ?>
          <a href="<?= $qs(['status' => $k, 'pagina' => 1]) ?>"
             class="ch-pill <?= $filtroStatus === (string)$k ? 'ativa' : '' ?>"
             style="text-decoration:none;">
            <?= $h($lblF) ?><?= $k !== '' ? ' (' . $n($resumo[$k] ?? 0) . ')' : '' ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>

    <?php if (!$destinatarios): ?>
      <div class="ch-vazio">
        <?php if ($c['status'] === 'rascunho'): ?>
          <strong>A fila ainda não foi montada</strong>
          Os destinatários são definidos no momento em que você dispara — assim o
          público fica congelado e o relatório fecha.
        <?php else: ?>
          Nenhum destinatário neste filtro.
        <?php endif; ?>
      </div>
    <?php else: ?>
    <div class="ch-tabela-wrap">
      <table class="ch-tabela">
        <thead><tr><th>Contato</th><th>Telefone</th><th>Situação</th><th>Enviado em</th><th>Erro</th></tr></thead>
        <tbody>
          <?php foreach ($destinatarios as $d):
            [$sl, $sc] = $statusDest[$d['status']] ?? [$d['status'], 'neutro']; ?>
          <tr>
            <td>
              <a href="<?= $base ?>/admin/chat/contatos/<?= (int)$d['contato_id'] ?>">
                <?= $h($d['nome'] ?: ($d['nome_perfil'] ?: 'sem nome')) ?>
              </a>
            </td>
            <td class="ch-mono ch-sm"><?= $h($d['telefone_exibicao'] ?: $d['wa_id']) ?></td>
            <td><span class="ch-badge ch-badge--<?= $sc ?>"><?= $h($sl) ?></span></td>
            <td class="ch-sm ch-mut">
              <?= $d['enviado_em'] ? date('d/m H:i', strtotime((string)$d['enviado_em'])) : '—' ?>
            </td>
            <td class="ch-sm ch-mut"><?= $h(mb_substr((string)$d['erro_detalhe'], 0, 70)) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($paginas > 1): ?>
      <div class="ch-pag">
        <?php if ($pagina > 1): ?><a href="<?= $qs(['pagina' => $pagina - 1]) ?>">‹</a><?php endif; ?>
        <?php for ($p = max(1, $pagina - 2); $p <= min($paginas, $pagina + 2); $p++): ?>
          <?= $p === $pagina
                ? '<span class="atual">' . $p . '</span>'
                : '<a href="' . $qs(['pagina' => $p]) . '">' . $p . '</a>' ?>
        <?php endfor; ?>
        <?php if ($pagina < $paginas): ?><a href="<?= $qs(['pagina' => $pagina + 1]) ?>">›</a><?php endif; ?>
      </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<script>
(function ($) {
  var BASE = window.BASE_URL || '<?= $base ?>';
  var CSRF = '<?= $h($csrf_token ?? '') ?>';
  var ID   = <?= (int)$c['id'] ?>;
  var RODANDO = <?= $rodando ? 'true' : 'false' ?>;

  function acao(rota, confirmacao, recarrega) {
    if (confirmacao && !confirm(confirmacao)) return;
    $.post(BASE + '/admin/chat/campanhas/' + ID + '/' + rota, { csrf_token: CSRF }, function (r) {
      if (r.ok === false && r.erro) { alert(r.erro); return; }
      if (recarrega !== false) location.reload();
    }, 'json');
  }

  $('#ch-iniciar').on('click', function () {
    acao('iniciar', 'Disparar esta campanha?\n\nIsso envia mensagens reais e gera custo na Meta.');
  });
  $('#ch-pausar').on('click',   function () { acao('pausar'); });
  $('#ch-cancelar').on('click', function () { acao('cancelar', 'Cancelar a campanha? Os pendentes não serão enviados.'); });

  // Progresso ao vivo enquanto está enviando
  if (RODANDO) {
    setInterval(function () {
      if (document.hidden) return;
      $.get(BASE + '/admin/chat/campanhas/' + ID + '/dados', function (r) {
        if (!r.ok) return;
        var fmt = new Intl.NumberFormat('pt-BR');
        Object.keys(r.totais).forEach(function (k) {
          $('[data-k=' + k + ']').text(fmt.format(r.totais[k]));
        });
        if (r.status !== 'enviando') location.reload();
      }, 'json');
    }, 8000);
  }
})(jQuery);
</script>
