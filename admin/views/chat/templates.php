<?php
/**
 * admin/views/chat/templates.php
 * @var array $templates
 */
$base = defined('BASE_URL') ? BASE_URL : '';
$h    = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$badgeStatus = function (string $s): string {
    return match (strtoupper($s)) {
        'APPROVED' => '<span class="ch-badge ch-badge--ok">aprovado</span>',
        'PENDING', 'IN_APPEAL' => '<span class="ch-badge ch-badge--aviso">em análise</span>',
        'REJECTED' => '<span class="ch-badge ch-badge--erro">reprovado</span>',
        'PAUSED', 'DISABLED' => '<span class="ch-badge ch-badge--erro">pausado</span>',
        'REMOVIDO' => '<span class="ch-badge ch-badge--neutro">removido na Meta</span>',
        default    => '<span class="ch-badge ch-badge--neutro">' . htmlspecialchars($s, ENT_QUOTES) . '</span>',
    };
};
$aprovados = array_filter($templates, fn($t) => strtoupper((string)$t['status']) === 'APPROVED');
?>

<div class="ch">

  <div class="ch-head">
    <div>
      <h1>Templates de mensagem</h1>
      <p>
        Modelos aprovados pela Meta. São o <strong>único</strong> jeito de falar com alguém
        que não escreveu para a loja nas últimas 24 horas.
      </p>
    </div>
    <div class="ch-head-acoes">
      <button type="button" class="ch-btn ch-btn--pri" id="ch-sinc">Sincronizar com a Meta</button>
      <a href="<?= $base ?>/admin/chat/config" class="ch-btn">Configuração</a>
    </div>
  </div>

  <div class="ch-aviso ch-aviso--info">
    <div>
      <strong class="ch-aviso-tit">Templates não são criados aqui</strong>
      Você cria e submete para aprovação no
      <a href="https://business.facebook.com/wa/manage/message-templates/" target="_blank" rel="noopener">Gerenciador do WhatsApp</a>.
      Esta tela é um espelho: sincronize depois de criar ou de a Meta aprovar algo,
      para que o modelo apareça nos fluxos e nas campanhas.
    </div>
  </div>

  <div class="ch-kpis">
    <div class="ch-kpi">
      <div class="ch-kpi-rot">Aprovados</div>
      <div class="ch-kpi-val"><?= count($aprovados) ?></div>
      <div class="ch-kpi-sub">prontos para uso</div>
    </div>
    <div class="ch-kpi">
      <div class="ch-kpi-rot">Total sincronizado</div>
      <div class="ch-kpi-val"><?= count($templates) ?></div>
    </div>
  </div>

  <div class="ch-card">
    <div class="ch-card-head">
      <h2>Modelos</h2>
      <span id="ch-sinc-msg" class="ch-sm"></span>
    </div>

    <?php if (!$templates): ?>
      <div class="ch-vazio">
        <strong>Nada sincronizado ainda</strong>
        Clique em “Sincronizar com a Meta” para trazer os modelos já aprovados na sua conta.
      </div>
    <?php else: ?>
    <div class="ch-tabela-wrap">
      <table class="ch-tabela">
        <thead>
          <tr>
            <th>Nome</th><th>Categoria</th><th>Idioma</th><th>Status</th>
            <th class="ch-num">Variáveis</th><th>Cabeçalho</th><th>Prévia do corpo</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($templates as $t): ?>
          <tr>
            <td><span class="ch-mono ch-b"><?= $h($t['nome']) ?></span></td>
            <td class="ch-sm"><?= $h($t['categoria'] ?: '—') ?></td>
            <td class="ch-sm"><?= $h($t['idioma']) ?></td>
            <td><?= $badgeStatus((string)$t['status']) ?></td>
            <td class="ch-num">
              <?= (int)$t['vars_body'] ?>
              <?php if ((int)$t['botoes_url'] > 0): ?>
                <div class="ch-sm ch-mut">+<?= (int)$t['botoes_url'] ?> no botão</div>
              <?php endif; ?>
            </td>
            <td class="ch-sm ch-mut"><?= $h($t['header_tipo'] ?: '—') ?></td>
            <td class="ch-sm ch-mut" style="max-width:340px;">
              <?= nl2br($h(mb_substr((string)$t['corpo_preview'], 0, 160))) ?>
              <?= mb_strlen((string)$t['corpo_preview']) > 160 ? '…' : '' ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <div class="ch-aviso ch-aviso--aviso ch-mt">
    <div>
      <strong class="ch-aviso-tit">Como as variáveis funcionam</strong>
      No texto do template, a Meta usa <code>{{1}}</code>, <code>{{2}}</code>… na ordem.
      Ao montar uma campanha ou um bloco de template no fluxo, você diz o que entra em cada
      posição — e ali pode usar as variáveis do contato, como
      <code>{{primeiro_nome}}</code> ou <code>{{total_pedidos}}</code>.
      <div style="margin-top:6px;">
        Mandar menos parâmetros do que o modelo espera faz a Meta recusar o envio
        (erro 132000) em <em>todos</em> os destinatários — por isso o formulário confere antes.
      </div>
    </div>
  </div>
</div>

<script>
(function ($) {
  var BASE = window.BASE_URL || '<?= $base ?>';
  $('#ch-sinc').on('click', function () {
    var btn = $(this).prop('disabled', true).text('Sincronizando...');
    $.post(BASE + '/admin/chat/templates/sincronizar',
      { csrf_token: window.CSRF_TOKEN || '<?= $h($csrf_token ?? '') ?>' },
      function (r) {
        $('#ch-sinc-msg')
          .text(r.ok ? r.msg : (r.erro || 'Falha.'))
          .css('color', r.ok ? 'var(--success)' : 'var(--danger)');
        if (r.ok) setTimeout(function () { location.reload(); }, 900);
      }, 'json')
      .fail(function () { $('#ch-sinc-msg').text('Erro de rede.').css('color', 'var(--danger)'); })
      .always(function () { btn.prop('disabled', false).text('Sincronizar com a Meta'); });
  });
})(jQuery);
</script>
