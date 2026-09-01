<?php
/**
 * admin/views/chat/automacao-insights.php
 * @var array $automacao @var array $insights @var int $dias
 * @var array|null $receita @var array $donos @var bool $ehGestor
 */
$base = defined('BASE_URL') ? BASE_URL : '';
$h    = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$n    = fn($v) => number_format((float)$v, 0, ',', '.');
$a    = $automacao;
$i    = $insights;

$badge = ['ativa' => ['ATIVA', 'ok'], 'rascunho' => ['RASCUNHO', 'neutro'], 'parada' => ['PARADA', 'aviso']];
[$stLbl, $stCor] = $badge[$a['status']] ?? [$a['status'], 'neutro'];

$semDados = (int)$i['envios'] === 0 && (int)$i['comentarios']['total'] === 0;

/**
 * Próximos passos: só aparecem quando fazem sentido para o estado atual.
 * Sugerir "melhore o CTR" numa automação que nunca rodou seria ruído.
 */
$passos = [];
if ($a['status'] !== 'ativa') {
    $passos[] = ['🚀', '#2563eb', 'Ative a automação',
                 'Ela está configurada mas parada — nenhum comentário está sendo respondido.'];
}
if ($a['status'] === 'ativa' && $a['escopo'] === 'midia' && count($a['midias']) <= 1) {
    $passos[] = ['📣', '#8b5cf6', 'Amplie para mais publicações',
                 'Seus posts não alcançam as mesmas pessoas. Rodar em mais publicações aumenta o volume.'];
}
if ((int)$i['envios'] > 0 && (float)$i['ctr'] < 20 && trim((string)$a['link_destino']) !== '') {
    $passos[] = ['👆', '#e1306c', 'Melhore os cliques no link',
                 'O CTR está em ' . number_format((float)$i['ctr'], 1, ',', '.') . '%. Uma chamada mais direta na mensagem costuma ajudar.'];
}
if ((int)$i['comentarios']['falhas'] > 0) {
    $passos[] = ['⚠️', '#d97706', $n($i['comentarios']['falhas']) . ' direct(s) não entregue(s)',
                 'Normalmente é gente que bloqueia mensagem de quem não segue, ou o direct daquele comentário já foi usado.'];
}
if (trim((string)$a['link_destino']) === '' && (int)$a['enviar_dm'] === 1) {
    $passos[] = ['🔗', '#0ea472', 'Adicione um link',
                 'Sem link não há clique para medir — e é o link que leva a pessoa para a loja.'];
}
?>

<div class="ch">

  <?php // ── Cabeçalho ──────────────────────────────────────────────────── ?>
  <div class="ch-fx-toolbar">
    <div class="ch-fx-titulo">
      <a href="<?= $base ?>/admin/chat/automacoes" class="ch-btn ch-btn--ico" title="Voltar">←</a>
      <span><?= $h($a['nome']) ?></span>
      <span class="ch-badge ch-badge--estado ch-badge--<?= $stCor ?>"><?= $h($stLbl) ?></span>
    </div>

    <select class="ch-select" id="ch-i-dias" style="width:auto;padding:6px 10px;font-size:12.5px;">
      <?php foreach ([7 => '7 dias', 14 => '14 dias', 30 => '30 dias', 90 => '90 dias'] as $v => $lbl): ?>
        <option value="<?= $v ?>" <?= (int)$dias === $v ? 'selected' : '' ?>><?= $lbl ?></option>
      <?php endforeach; ?>
    </select>

    <a href="<?= $base ?>/admin/chat/automacoes/<?= (int)$a['id'] ?>/editar" class="ch-btn">Editar</a>
    <?php if ($a['status'] === 'ativa'): ?>
      <button type="button" class="ch-btn ch-i-status" data-status="parada">Parar</button>
    <?php else: ?>
      <button type="button" class="ch-btn ch-btn--pri ch-i-status" data-status="ativa">Ativar</button>
    <?php endif; ?>
  </div>

  <div id="ch-i-msg"></div>

  <?php // ── Próximos passos ────────────────────────────────────────────── ?>
  <?php if ($passos): ?>
    <h2 style="font-size:15px;font-weight:700;margin:0 0 12px;">Próximos passos recomendados</h2>
    <div class="ch-ins-passos">
      <?php foreach (array_slice($passos, 0, 2) as [$ico, $cor, $tit, $txt]): ?>
        <div class="ch-ins-passo">
          <div class="ch-ins-passo-ico" style="background:<?= $h($cor) ?>1f;"><?= $ico ?></div>
          <div>
            <div class="ch-ins-passo-tit"><?= $h($tit) ?></div>
            <div class="ch-ins-passo-txt"><?= $h($txt) ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php // ── Métricas principais ────────────────────────────────────────── ?>
  <h2 style="font-size:15px;font-weight:700;margin:0 0 12px;">
    Principais métricas
    <span class="ch-sm ch-mut" style="font-weight:500;">· últimos <?= (int)$dias ?> dias</span>
  </h2>

  <?php if ($semDados): ?>
    <div class="ch-card" style="margin-bottom:16px;">
      <div class="ch-vazio">
        <strong>Nenhum dado para mostrar... ainda!</strong>
        <p style="max-width:48ch;margin:0 auto;">
          <?php if ($a['status'] !== 'ativa'): ?>
            Ative a automação e peça para alguém comentar
            <?= $a['palavras'] ? '<strong>' . $h(explode(',', $a['palavras'])[0]) . '</strong>' : '' ?>
            num post para começar a coletar dados.
          <?php else: ?>
            Peça a alguém para comentar
            <?= $a['palavras'] ? '<strong>' . $h(explode(',', $a['palavras'])[0]) . '</strong>' : '' ?>
            em um post para testar a automação.
          <?php endif; ?>
        </p>
      </div>
    </div>
  <?php endif; ?>

  <div class="ch-ins-resumo" style="margin-bottom:18px;">
    <div>
      <div class="ch-ins-rot">Envios</div>
      <div class="ch-ins-val" data-m="envios"><?= $n($i['envios']) ?></div>
    </div>
    <div>
      <div class="ch-ins-rot">Cliques</div>
      <div class="ch-ins-val" data-m="cliques"><?= $n($i['cliques']) ?></div>
    </div>
    <div>
      <div class="ch-ins-rot">CTR</div>
      <div class="ch-ins-val" data-m="ctr"><?= number_format((float)$i['ctr'], 0, ',', '.') ?>%</div>
    </div>
    <div>
      <div class="ch-ins-rot">E-mails</div>
      <div class="ch-ins-val" data-m="emails"><?= $n($i['emails']) ?></div>
    </div>
  </div>

  <?php // ── Funil + gráfico ────────────────────────────────────────────── ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(330px,1fr));gap:16px;margin-bottom:16px;">

    <div class="ch-card">
      <div class="ch-card-head"><h2>Do comentário ao direct</h2></div>
      <div class="ch-card-body">
        <?php
        $c   = $i['comentarios'];
        $tot = max(1, (int)$c['total']);
        $etapas = [
            ['Comentários recebidos', (int)$c['total'],    '#64748b'],
            ['Responderam em público', (int)$c['publicos'], '#0a66c2'],
            ['Viraram direct',         (int)$c['com_dm'],   '#25d366'],
            ['Clicaram no link',       (int)$i['cliques'],  '#e1306c'],
        ];
        foreach ($etapas as [$rot, $val, $cor]):
          $pct = round(($val / $tot) * 100, 1); ?>
          <div style="margin-bottom:13px;">
            <div class="ch-flex-sb" style="margin-bottom:5px;">
              <span class="ch-sm"><?= $h($rot) ?></span>
              <span class="ch-sm ch-b"><?= $n($val) ?>
                <span class="ch-mut" style="font-weight:500;"><?= number_format($pct, 0, ',', '.') ?>%</span>
              </span>
            </div>
            <div class="ch-prog"><span style="width:<?= min(100, $pct) ?>%;background:<?= $cor ?>;"></span></div>
          </div>
        <?php endforeach; ?>

        <?php if ((int)$c['falhas'] > 0): ?>
          <div class="ch-sm" style="color:var(--danger);margin-top:12px;">
            <?= $n($c['falhas']) ?> direct(s) não entregue(s) —
            <a href="<?= $base ?>/admin/chat/instagram/comentarios?regra=<?= (int)$a['id'] ?>&so_erro=1">ver motivos</a>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="ch-card">
      <div class="ch-card-head"><h2>Por dia</h2></div>
      <div class="ch-card-body">
        <?php
        $serie = $i['serie'] ?? [];
        $max = 1;
        foreach ($serie as $d) $max = max($max, (int)$d['comentarios'], (int)$d['dms']);
        if (!$serie): ?>
          <div class="ch-vazio" style="padding:24px;">Sem movimento no período.</div>
        <?php else:
          $largura = 900; $altura = 170; $padE = 30; $padB = 24; $padT = 8;
          $areaW = $largura - $padE - 10; $areaH = $altura - $padB - $padT;
          $porGrupo = $areaW / max(1, count($serie));
          $lb = max(2, ($porGrupo * 0.6) / 2);
          $passo = max(1, (int)ceil(count($serie) / 10));
        ?>
          <svg class="ch-gr" viewBox="0 0 <?= $largura ?> <?= $altura ?>" preserveAspectRatio="none" role="img">
            <?php for ($k = 0; $k <= 3; $k++):
              $y = $padT + ($areaH / 3) * $k;
              $v = (int)round($max - ($max / 3) * $k); ?>
              <line class="ch-gr-grade" x1="<?= $padE ?>" y1="<?= round($y, 1) ?>" x2="<?= $largura - 10 ?>" y2="<?= round($y, 1) ?>"/>
              <text class="ch-gr-eixo" x="<?= $padE - 5 ?>" y="<?= round($y + 3, 1) ?>" text-anchor="end"><?= $v ?></text>
            <?php endfor; ?>

            <?php foreach ($serie as $idx => $d):
              $x0 = $padE + $idx * $porGrupo + ($porGrupo * 0.2);
              foreach ([['comentarios', '#64748b', 0], ['dms', '#25d366', 1]] as [$k, $cor, $j]):
                $val = (int)$d[$k];
                $alt = $max > 0 ? ($val / $max) * $areaH : 0; ?>
                <rect class="ch-barra" x="<?= round($x0 + $j * $lb, 1) ?>"
                      y="<?= round($padT + $areaH - $alt, 1) ?>"
                      width="<?= round($lb - 1, 1) ?>" height="<?= round(max(0, $alt), 1) ?>"
                      fill="<?= $cor ?>" rx="1.5">
                  <title><?= $h($d['rotulo']) ?> — <?= $k === 'dms' ? 'directs' : 'comentários' ?>: <?= $val ?></title>
                </rect>
              <?php endforeach; ?>
              <?php if ($idx % $passo === 0): ?>
                <text class="ch-gr-eixo" x="<?= round($x0 + $porGrupo * 0.15, 1) ?>"
                      y="<?= $altura - 7 ?>" text-anchor="middle"><?= $h($d['rotulo']) ?></text>
              <?php endif; ?>
            <?php endforeach; ?>
          </svg>
          <div class="ch-gr-legenda">
            <span><i class="ch-gr-cor" style="background:#64748b"></i>Comentários</span>
            <span><i class="ch-gr-cor" style="background:#25d366"></i>Directs enviados</span>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php // ── Configuração resumida + últimos comentários ────────────────── ?>
  <div style="display:grid;grid-template-columns:300px minmax(0,1fr);gap:16px;align-items:start;">

    <div class="ch-card">
      <div class="ch-card-head"><h2>Configuração</h2></div>
      <div class="ch-card-body">
        <div class="ch-dado"><dt>Modelo</dt><dd><?= $h($receita['nome'] ?? $a['receita']) ?></dd></div>
        <div class="ch-dado"><dt>Gatilho</dt><dd><?= $h($a['gatilho_rotulo']) ?></dd></div>
        <div class="ch-dado"><dt>Onde</dt><dd>
          <?= $a['escopo'] === 'midia' ? count($a['midias']) . ' publicação(ões)'
             : ($a['escopo'] === 'novas' ? 'Posts novos' : 'Todos os posts') ?>
        </dd></div>
        <div class="ch-dado"><dt>Palavras</dt><dd class="ch-mono">
          <?= $h($a['palavras'] ?: 'qualquer') ?>
        </dd></div>
        <?php if ($a['tag_nome']): ?>
          <div class="ch-dado"><dt>Tag</dt><dd>
            <span class="ch-tag" style="color:<?= $h($a['tag_cor']) ?>;background:<?= $h($a['tag_cor']) ?>22;">
              <?= $h($a['tag_nome']) ?>
            </span>
          </dd></div>
        <?php endif; ?>
        <?php if ($a['fluxo_nome']): ?>
          <div class="ch-dado"><dt>Fluxo</dt><dd><?= $h($a['fluxo_nome']) ?></dd></div>
        <?php endif; ?>
        <div class="ch-dado"><dt>Criada por</dt><dd><?= $h($a['dono_nome'] ?: 'do time') ?></dd></div>
        <div class="ch-dado"><dt>Disparos</dt><dd><?= $n($i['disparos']) ?></dd></div>

        <?php if ($ehGestor && $donos): ?>
          <div class="ch-campo" style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border);">
            <label class="ch-label">Trocar dono</label>
            <select class="ch-select" id="ch-i-dono">
              <option value="0">Do time (sem dono)</option>
              <?php foreach ($donos as $d): ?>
                <option value="<?= (int)$d['id'] ?>" <?= (int)($a['usuario_id'] ?? 0) === (int)$d['id'] ? 'selected' : '' ?>>
                  <?= $h($d['nome']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="ch-ajuda">Quem não é gestor só enxerga as automações que são suas.</div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="ch-card">
      <div class="ch-card-head">
        <h2>Últimos comentários</h2>
        <a href="<?= $base ?>/admin/chat/instagram/comentarios?regra=<?= (int)$a['id'] ?>" class="ch-btn ch-btn--sm">Ver todos</a>
      </div>
      <?php if (empty($i['ultimos'])): ?>
        <div class="ch-vazio">Nenhum comentário processado por esta automação ainda.</div>
      <?php else: ?>
        <div class="ch-tabela-wrap">
          <table class="ch-tabela">
            <thead><tr><th>Quando</th><th>Quem</th><th>Comentário</th><th>Resultado</th></tr></thead>
            <tbody>
              <?php foreach ($i['ultimos'] as $u): ?>
              <tr>
                <td class="ch-sm ch-mut" style="white-space:nowrap;">
                  <?= date('d/m H:i', strtotime((string)$u['criado_em'])) ?>
                </td>
                <td class="ch-sm">@<?= $h($u['from_username'] ?: '?') ?></td>
                <td class="ch-sm" style="max-width:260px;"><?= $h(mb_substr((string)$u['texto'], 0, 90)) ?></td>
                <td class="ch-sm">
                  <?php if ((int)$u['respondido_publico']): ?><span class="ch-badge ch-badge--info">respondido</span><?php endif; ?>
                  <?php if ((int)$u['dm_enviado']): ?><span class="ch-badge ch-badge--ok">direct</span><?php endif; ?>
                  <?php if ($u['dm_erro']): ?>
                    <div style="color:var(--danger);"><?= $h(mb_substr((string)$u['dm_erro'], 0, 60)) ?></div>
                  <?php endif; ?>
                </td>
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
  var BASE = window.BASE_URL || '<?= $base ?>';
  var CSRF = '<?= $h($csrf_token ?? '') ?>';
  var ID   = <?= (int)$a['id'] ?>;

  function esc(s) { return $('<i>').text(s == null ? '' : String(s)).html(); }
  function aviso(txt, tipo) {
    $('#ch-i-msg').html('<div class="ch-aviso ch-aviso--' + tipo + '"><div>' + esc(txt) + '</div></div>');
    if (tipo === 'ok') setTimeout(function () { $('#ch-i-msg').empty(); }, 3000);
  }

  $('#ch-i-dias').on('change', function () {
    var u = new URL(window.location.href);
    u.searchParams.set('dias', $(this).val());
    window.location.href = u.toString();
  });

  $('.ch-i-status').on('click', function () {
    var st = $(this).data('status'), $b = $(this).prop('disabled', true);
    $.post(BASE + '/admin/chat/automacoes/' + ID + '/status',
      { csrf_token: CSRF, status: st }, function (r) {
        if (r.ok) location.reload();
        else { aviso(r.erro || 'Falha.', 'erro'); $b.prop('disabled', false); }
      }, 'json').fail(function () { $b.prop('disabled', false); });
  });

  $('#ch-i-dono').on('change', function () {
    $.post(BASE + '/admin/chat/automacoes/' + ID + '/transferir',
      { csrf_token: CSRF, usuario_id: $(this).val() }, function (r) {
        aviso(r.ok ? 'Dono atualizado.' : (r.erro || 'Falha.'), r.ok ? 'ok' : 'erro');
      }, 'json');
  });
})(jQuery);
</script>
