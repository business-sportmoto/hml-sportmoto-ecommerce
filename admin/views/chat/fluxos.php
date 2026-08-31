<?php
/**
 * admin/views/chat/fluxos.php
 * @var array $fluxos @var array $kpis
 */
$base = defined('BASE_URL') ? BASE_URL : '';
$h    = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$n    = fn($v) => number_format((float)$v, 0, ',', '.');

$badge = [
    'rascunho'  => ['Rascunho',  'neutro'],
    'publicado' => ['No ar',     'ok'],
    'pausado'   => ['Pausado',   'aviso'],
    'arquivado' => ['Arquivado', 'neutro'],
];
?>

<div class="ch">

  <div class="ch-head">
    <div>
      <h1>Fluxos</h1>
      <p>A conversa automática: o que o bot responde, o que pergunta e para onde leva.</p>
    </div>
    <div class="ch-head-acoes">
      <a href="<?= $base ?>/admin/chat/fluxos/atividade" class="ch-btn">Atividade</a>
      <a href="<?= $base ?>/admin/chat/gatilhos" class="ch-btn">Gatilhos</a>
      <button type="button" class="ch-btn ch-btn--pri" id="ch-novo-fluxo">Novo fluxo</button>
    </div>
  </div>

  <div class="ch-kpis">
    <div class="ch-kpi">
      <div class="ch-kpi-rot">Sessões hoje</div>
      <div class="ch-kpi-val"><?= $n($kpis['sessoes_hoje']) ?></div>
    </div>
    <div class="ch-kpi">
      <div class="ch-kpi-rot">Em andamento</div>
      <div class="ch-kpi-val"><?= $n($kpis['ativas']) ?></div>
      <div class="ch-kpi-sub">conversas dentro de um fluxo agora</div>
    </div>
    <div class="ch-kpi">
      <div class="ch-kpi-rot">Concluídas hoje</div>
      <div class="ch-kpi-val"><?= $n($kpis['concluidas_hoje']) ?></div>
    </div>
    <div class="ch-kpi">
      <div class="ch-kpi-rot">Erros hoje</div>
      <div class="ch-kpi-val" style="<?= (int)$kpis['erros_hoje'] > 0 ? 'color:var(--danger)' : '' ?>"><?= $n($kpis['erros_hoje']) ?></div>
      <?php if ((int)$kpis['erros_hoje'] > 0): ?>
        <div class="ch-kpi-sub"><a href="<?= $base ?>/admin/chat/fluxos/atividade?so_erros=1">ver o que falhou</a></div>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!$fluxos): ?>
    <div class="ch-card">
      <div class="ch-vazio">
        <strong>Nenhum fluxo ainda</strong>
        <p style="max-width:52ch;margin:0 auto 16px;">
          Um fluxo é montado arrastando blocos: “manda esta mensagem”, “pergunta isso”,
          “se a resposta for X, faz Y”. Depois você liga um gatilho — por exemplo,
          a palavra <em>menu</em> — para ele começar.
        </p>
        <button type="button" class="ch-btn ch-btn--pri" id="ch-novo-fluxo-2">Criar o primeiro fluxo</button>
      </div>
    </div>
  <?php else: ?>
    <div class="ch-card">
      <div class="ch-tabela-wrap">
        <table class="ch-tabela">
          <thead>
            <tr>
              <th>Fluxo</th><th>Situação</th><th>Gatilhos</th>
              <th class="ch-num">Em andamento</th><th class="ch-num">Concluídas</th>
              <th class="ch-num">Erros</th><th style="width:1%;"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($fluxos as $f):
              [$lbl, $cor] = $badge[$f['status']] ?? [$f['status'], 'neutro']; ?>
            <tr>
              <td>
                <a href="<?= $base ?>/admin/chat/fluxos/<?= (int)$f['id'] ?>" class="ch-b"><?= $h($f['nome']) ?></a>
                <?php if ($f['descricao']): ?>
                  <div class="ch-sm ch-mut"><?= $h($f['descricao']) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <span class="ch-badge ch-badge--<?= $cor ?>"><?= $h($lbl) ?></span>
                <?php if ((int)$f['versao_publicada'] > 0): ?>
                  <span class="ch-sm ch-mut">v<?= (int)$f['versao_publicada'] ?></span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ((int)$f['gatilhos'] > 0): ?>
                  <span class="ch-badge ch-badge--info"><?= (int)$f['gatilhos'] ?></span>
                <?php elseif ($f['status'] === 'publicado'): ?>
                  <span class="ch-badge ch-badge--aviso" title="Publicado mas nada o dispara">nenhum</span>
                <?php else: ?>
                  <span class="ch-mut">—</span>
                <?php endif; ?>
              </td>
              <td class="ch-num"><?= $n($f['em_andamento']) ?></td>
              <td class="ch-num"><?= $n($f['concluidas']) ?></td>
              <td class="ch-num"><?= (int)$f['com_erro'] > 0
                    ? '<span style="color:var(--danger)">' . $n($f['com_erro']) . '</span>' : '0' ?></td>
              <td>
                <div class="ch-flex">
                  <a href="<?= $base ?>/admin/chat/fluxos/<?= (int)$f['id'] ?>" class="ch-btn ch-btn--sm">Editar</a>
                  <button type="button" class="ch-btn ch-btn--sm ch-dup" data-id="<?= (int)$f['id'] ?>" title="Duplicar">⧉</button>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php // Publicado sem gatilho é o erro silencioso mais comum do módulo ?>
    <?php $orfaos = array_filter($fluxos, fn($f) => $f['status'] === 'publicado' && (int)$f['gatilhos'] === 0); ?>
    <?php if ($orfaos): ?>
      <div class="ch-aviso ch-aviso--aviso ch-mt">
        <div>
          <strong><?= count($orfaos) ?> fluxo(s) publicado(s) sem nenhum gatilho</strong>
          Eles estão no ar mas nada os dispara — só vão rodar se você iniciar manualmente
          pelo atendimento ou por uma campanha.
          <a href="<?= $base ?>/admin/chat/gatilhos">Criar um gatilho</a>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<script>
(function ($) {
  var BASE = window.BASE_URL || '<?= $base ?>';
  var CSRF = window.CSRF_TOKEN || '<?= $h($csrf_token ?? '') ?>';

  function criar() {
    var nome = prompt('Nome do fluxo:');
    if (!nome || !nome.trim()) return;
    $.post(BASE + '/admin/chat/fluxos/criar', { nome: nome.trim(), csrf_token: CSRF }, function (r) {
      if (r.ok) window.location.href = BASE + '/admin/chat/fluxos/' + r.id;
      else alert(r.erro || 'Falha ao criar.');
    }, 'json');
  }

  $('#ch-novo-fluxo, #ch-novo-fluxo-2').on('click', criar);

  $('.ch-dup').on('click', function () {
    var id = $(this).data('id');
    if (!confirm('Duplicar este fluxo?')) return;
    $.post(BASE + '/admin/chat/fluxos/' + id + '/duplicar', { csrf_token: CSRF }, function (r) {
      if (r.ok) window.location.href = BASE + '/admin/chat/fluxos/' + r.id;
      else alert(r.erro || 'Falha ao duplicar.');
    }, 'json');
  });
})(jQuery);
</script>
