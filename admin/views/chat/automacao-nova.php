<?php
/**
 * admin/views/chat/automacao-nova.php
 * @var array $receitas @var array $pastas @var bool $contaOk
 */
$base = defined('BASE_URL') ? BASE_URL : '';
$h    = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>

<div class="ch">

  <div class="ch-head">
    <div>
      <h1>Nova automação</h1>
      <p>Escolha um modelo pronto — os textos já vêm preenchidos e você ajusta ao seu tom.</p>
    </div>
    <div class="ch-head-acoes">
      <a href="<?= $base ?>/admin/chat/automacoes" class="ch-btn">← Automações</a>
    </div>
  </div>

  <?php if (!$contaOk): ?>
    <div class="ch-aviso ch-aviso--aviso">
      <div>
        <strong class="ch-aviso-tit">Nenhuma conta do Instagram conectada</strong>
        Dá para montar a automação agora, mas ela só dispara depois de conectar.
        <a href="<?= $base ?>/admin/chat/instagram">Conectar conta</a>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($pastas): ?>
    <div class="ch-campo" style="max-width:280px;">
      <label class="ch-label">Criar dentro da pasta</label>
      <select class="ch-select" id="ch-nova-pasta-sel">
        <option value="0">Sem pasta</option>
        <?php foreach ($pastas as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= (int)($_GET['pasta'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>>
            <?= $h($p['nome']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  <?php endif; ?>

  <div class="ch-receitas ch-mt">
    <?php foreach ($receitas as $r): ?>
      <button type="button" class="ch-receita" data-receita="<?= $h($r['chave']) ?>">
        <?php if (!empty($r['cria_fluxo'])): ?><span class="ch-receita-selo">Fluxo</span><?php endif; ?>
        <span class="ch-receita-ico" style="background:<?= $h($r['cor']) ?>1f;">
          <?= $r['icone'] ?>
        </span>
        <span class="ch-receita-nome"><?= $h($r['nome']) ?></span>
        <span class="ch-receita-resumo"><?= $h($r['resumo']) ?></span>
        <span class="ch-receita-gat"><?= $h($r['gatilho_rotulo']) ?></span>
      </button>
    <?php endforeach; ?>
  </div>

  <div class="ch-aviso ch-aviso--info ch-mt">
    <div>
      <strong class="ch-aviso-tit">Como funciona</strong>
      Toda automação começa como <strong>rascunho</strong>: você ajusta os textos, vê a prévia
      e só depois ativa. Nada é enviado a ninguém antes disso.
    </div>
  </div>

  <div id="ch-nova-erro" class="ch-sm" style="color:var(--danger);margin-top:12px;"></div>
</div>

<script>
(function ($) {
  var BASE = window.BASE_URL || '<?= $base ?>';
  var CSRF = '<?= $h($csrf_token ?? '') ?>';

  $('.ch-receita').on('click', function () {
    var $b = $(this).prop('disabled', true).css('opacity', .6);

    $.post(BASE + '/admin/chat/automacoes/criar', {
      csrf_token: CSRF,
      receita: $(this).data('receita'),
      pasta_id: $('#ch-nova-pasta-sel').val() || 0
    }, function (r) {
      if (r.ok && r.redirect) { window.location.href = r.redirect; return; }
      $('#ch-nova-erro').text(r.erro || 'Falha ao criar a automação.');
      $b.prop('disabled', false).css('opacity', 1);
    }, 'json').fail(function () {
      $('#ch-nova-erro').text('Erro de rede.');
      $b.prop('disabled', false).css('opacity', 1);
    });
  });
})(jQuery);
</script>
