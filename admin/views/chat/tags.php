<?php
/**
 * admin/views/chat/tags.php
 * @var array $tags @var bool $podeGerir
 */
$base = defined('BASE_URL') ? BASE_URL : '';
$h    = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>

<div class="ch">

  <div class="ch-head">
    <div>
      <h1>Tags</h1>
      <p>Rótulos aplicados aos contatos — por fluxo, por gatilho ou à mão. Servem de filtro para as campanhas.</p>
    </div>
    <div class="ch-head-acoes">
      <a href="<?= $base ?>/admin/chat/contatos" class="ch-btn">← Contatos</a>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:minmax(0,2fr) minmax(280px,1fr);gap:16px;align-items:start;">

    <div class="ch-card">
      <div class="ch-card-head"><h2><?= count($tags) ?> tag(s)</h2></div>
      <?php if (!$tags): ?>
        <div class="ch-vazio">Nenhuma tag criada.</div>
      <?php else: ?>
      <div class="ch-tabela-wrap">
        <table class="ch-tabela">
          <thead><tr><th>Tag</th><th>Identificador</th><th>Descrição</th><th class="ch-num">Contatos</th><th style="width:1%;"></th></tr></thead>
          <tbody>
            <?php foreach ($tags as $t): ?>
            <tr>
              <td>
                <span class="ch-tag" style="color:<?= $h($t['cor']) ?>;background:<?= $h($t['cor']) ?>22;">
                  <?= $h($t['nome']) ?>
                </span>
              </td>
              <td class="ch-mono ch-sm ch-mut"><?= $h($t['slug']) ?></td>
              <td class="ch-sm ch-mut"><?= $h($t['descricao'] ?: '—') ?></td>
              <td class="ch-num">
                <a href="<?= $base ?>/admin/chat/contatos?tags[]=<?= (int)$t['id'] ?>">
                  <?= number_format((int)$t['total'], 0, ',', '.') ?>
                </a>
              </td>
              <td>
                <?php if ($podeGerir): ?>
                  <button type="button" class="ch-btn ch-btn--sm ch-btn--perigo ch-tag-del"
                          data-id="<?= (int)$t['id'] ?>" data-nome="<?= $h($t['nome']) ?>"
                          data-total="<?= (int)$t['total'] ?>">×</button>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <?php if ($podeGerir): ?>
    <div class="ch-card">
      <div class="ch-card-head"><h2>Nova tag</h2></div>
      <div class="ch-card-body">
        <form id="ch-form-tag">
          <input type="hidden" name="csrf_token" value="<?= $h($csrf_token ?? '') ?>">
          <div class="ch-campo">
            <label class="ch-label">Nome</label>
            <input type="text" class="ch-input" name="nome" required maxlength="60">
          </div>
          <div class="ch-campo">
            <label class="ch-label">Cor</label>
            <input type="color" class="ch-input" name="cor" value="#2563eb" style="height:40px;padding:4px;">
          </div>
          <div class="ch-campo">
            <label class="ch-label">Descrição (opcional)</label>
            <input type="text" class="ch-input" name="descricao" maxlength="200">
          </div>
          <div id="ch-tag-erro" class="ch-sm" style="color:var(--danger);margin-bottom:8px;"></div>
          <button type="submit" class="ch-btn ch-btn--pri" style="width:100%;">Criar tag</button>
        </form>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
(function ($) {
  var BASE = window.BASE_URL || '<?= $base ?>';
  var CSRF = '<?= $h($csrf_token ?? '') ?>';

  $('#ch-form-tag').on('submit', function (e) {
    e.preventDefault();
    var $b = $(this).find('button').prop('disabled', true);
    $.post(BASE + '/admin/chat/tags/salvar', $(this).serialize(), function (r) {
      if (r.ok) location.reload();
      else $('#ch-tag-erro').text(r.erro || 'Falha.');
    }, 'json').always(function () { $b.prop('disabled', false); });
  });

  $('.ch-tag-del').on('click', function () {
    var t = parseInt($(this).data('total'), 10) || 0;
    var aviso = 'Excluir a tag "' + $(this).data('nome') + '"?';
    if (t > 0) aviso += '\n\nEla será removida de ' + t + ' contato(s).';
    aviso += '\nFluxos e gatilhos que usam esta tag param de funcionar.';
    if (!confirm(aviso)) return;

    $.post(BASE + '/admin/chat/tags/' + $(this).data('id') + '/excluir',
      { csrf_token: CSRF }, function () { location.reload(); }, 'json');
  });
})(jQuery);
</script>
