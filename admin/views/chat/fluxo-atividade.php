<?php
/**
 * admin/views/chat/fluxo-atividade.php
 * @var array $fluxos @var array $kpis
 */
$base = defined('BASE_URL') ? BASE_URL : '';
$h    = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$n    = fn($v) => number_format((float)$v, 0, ',', '.');
?>

<div class="ch">

  <div class="ch-head">
    <div>
      <h1>Atividade dos fluxos</h1>
      <p>Quem entrou, por onde passou e onde terminou.</p>
    </div>
    <div class="ch-head-acoes">
      <a href="<?= $base ?>/admin/chat/fluxos" class="ch-btn">← Fluxos</a>
    </div>
  </div>

  <div class="ch-kpis">
    <div class="ch-kpi"><div class="ch-kpi-rot">Iniciadas hoje</div><div class="ch-kpi-val"><?= $n($kpis['sessoes_hoje']) ?></div></div>
    <div class="ch-kpi"><div class="ch-kpi-rot">Em andamento</div><div class="ch-kpi-val"><?= $n($kpis['ativas']) ?></div></div>
    <div class="ch-kpi"><div class="ch-kpi-rot">Concluídas hoje</div><div class="ch-kpi-val"><?= $n($kpis['concluidas_hoje']) ?></div></div>
    <div class="ch-kpi">
      <div class="ch-kpi-rot">Erros hoje</div>
      <div class="ch-kpi-val" style="<?= (int)$kpis['erros_hoje'] > 0 ? 'color:var(--danger)' : '' ?>"><?= $n($kpis['erros_hoje']) ?></div>
    </div>
  </div>

  <div class="ch-filtros">
    <div class="ch-campo">
      <label class="ch-label">Fluxo</label>
      <select class="ch-select" id="ch-f-fluxo">
        <option value="0">Todos</option>
        <?php foreach ($fluxos as $f): ?>
          <option value="<?= (int)$f['id'] ?>"><?= $h($f['nome']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="ch-campo" style="flex:0;">
      <label class="ch-check" style="margin-top:22px;">
        <input type="checkbox" id="ch-f-erros" <?= !empty($_GET['so_erros']) ? 'checked' : '' ?>>
        <span>Só erros</span>
      </label>
    </div>
    <div class="ch-campo" style="flex:0;">
      <button type="button" class="ch-btn" id="ch-f-atualizar" style="margin-top:20px;">Atualizar</button>
    </div>
  </div>

  <div class="ch-card">
    <div class="ch-tabela-wrap">
      <table class="ch-tabela">
        <thead><tr><th>Quando</th><th>Fluxo</th><th>Contato</th><th>Evento</th><th>Detalhe</th></tr></thead>
        <tbody id="ch-atv"><tr><td colspan="5" class="ch-carregando">Carregando...</td></tr></tbody>
      </table>
    </div>
    <div style="padding:14px;text-align:center;">
      <button type="button" class="ch-btn" id="ch-mais" style="display:none;">Carregar mais</button>
    </div>
  </div>
</div>

<script>
(function ($) {
  var BASE = window.BASE_URL || '<?= $base ?>';
  var proximo = 0;

  function esc(s) { return $('<i>').text(s == null ? '' : String(s)).html(); }

  var STATUS = {
    inicio:     ['entrou',      'info'],
    concluido:  ['concluiu',    'ok'],
    saiu:       ['saiu',        'neutro'],
    erro:       ['erro',        'erro']
  };

  function carregar(reset) {
    if (reset) { proximo = 0; $('#ch-atv').html('<tr><td colspan="5" class="ch-carregando">Carregando...</td></tr>'); }

    $.get(BASE + '/admin/chat/fluxos/atividade/dados', {
      fluxo_id: $('#ch-f-fluxo').val(),
      so_erros: $('#ch-f-erros').is(':checked') ? 1 : 0,
      antes_de: proximo
    }, function (r) {
      if (!r.ok) return;
      if (reset) $('#ch-atv').empty();

      if (!r.itens.length && reset) {
        $('#ch-atv').html('<tr><td colspan="5" class="ch-vazio">Nenhuma atividade registrada.</td></tr>');
        $('#ch-mais').hide();
        return;
      }

      var html = r.itens.map(function (i) {
        var st = STATUS[i.porta] || [i.porta, 'neutro'];
        var nome = i.contato_nome || i.nome_perfil || i.wa_id || '—';
        return '<tr>' +
          '<td class="ch-sm ch-mut">' + esc(new Date(i.criado_em.replace(' ', 'T')).toLocaleString('pt-BR')) + '</td>' +
          '<td class="ch-sm">' + esc(i.fluxo_nome || 'removido') + ' <span class="ch-mut">v' + i.versao + '</span></td>' +
          '<td class="ch-sm">' + (i.contato_id
              ? '<a href="' + BASE + '/admin/chat/contatos/' + i.contato_id + '">' + esc(nome) + '</a>'
              : esc(nome)) + '</td>' +
          '<td><span class="ch-badge ch-badge--' + st[1] + '">' + esc(st[0]) + '</span></td>' +
          '<td class="ch-sm ch-mut">' + esc(i.detalhe || '') + '</td>' +
          '</tr>';
      }).join('');

      $('#ch-atv').append(html);
      proximo = r.proximo;
      $('#ch-mais').toggle(r.itens.length >= 50);
    }, 'json');
  }

  $('#ch-f-atualizar, #ch-f-fluxo, #ch-f-erros').on('click change', function () { carregar(true); });
  $('#ch-mais').on('click', function () { carregar(false); });

  carregar(true);
})(jQuery);
</script>
