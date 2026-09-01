<?php
/**
 * admin/views/bi/metas.php
 * Metas comerciais do BI.
 */
$anoAtual = (int)date('Y');
?>
<div class="admin-page">

  <div class="admin-page-head" style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;">
    <div>
      <h1 class="admin-title">Metas comerciais</h1>
      <p style="color:var(--text-3);font-size:13px;margin:4px 0 0;max-width:640px;">
        Uma meta é <strong>período × métrica × recorte</strong>. Só pode existir uma
        por combinação — duas tornariam o “% atingido” ambíguo.
      </p>
    </div>
    <button type="button" class="btn btn-primary" id="btn-nova-meta">Nova meta</button>
  </div>

  <!-- Filtros -->
  <form method="get" class="admin-card" style="padding:14px 18px;margin-bottom:14px;
       display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
    <div>
      <label class="form-label-xs">Métrica</label>
      <select name="metrica" class="form-control">
        <option value="">Todas</option>
        <?php foreach ($metricas as $k => $lbl): ?>
        <option value="<?= View::e($k) ?>" <?= ($filtros['metrica'] ?? '') === $k ? 'selected' : '' ?>>
          <?= View::e($lbl) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="form-label-xs">Recorte</label>
      <select name="dimensao" class="form-control">
        <option value="">Todos</option>
        <?php foreach ($dimensoes as $k => $lbl): ?>
        <option value="<?= View::e($k) ?>" <?= ($filtros['dimensao'] ?? '') === $k ? 'selected' : '' ?>>
          <?= View::e($lbl) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="form-label-xs">Ano</label>
      <input type="number" name="ano" class="form-control" style="max-width:110px;"
             value="<?= !empty($filtros['ano']) ? (int)$filtros['ano'] : '' ?>"
             placeholder="<?= $anoAtual ?>" min="2025" max="2030">
    </div>
    <button class="btn btn-outline">Filtrar</button>
  </form>

  <!-- Lista -->
  <div class="admin-card">
    <?php if (empty($metas)): ?>
      <div style="padding:36px 18px;text-align:center;color:var(--text-3);">
        Nenhuma meta cadastrada ainda.
      </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
      <table class="admin-table">
        <thead>
          <tr>
            <th>Período</th>
            <th>Métrica</th>
            <th>Recorte</th>
            <th style="text-align:right;">Meta</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($metas as $m):
              $alvoLabel = $dimensoes[$m['dimensao']] ?? $m['dimensao'];
              if ($m['dimensao'] === 'canal' && !empty($m['dimensao_valor'])) {
                  $alvoLabel .= ': ' . $m['dimensao_valor'];
              } elseif (!empty($m['dimensao_id'])) {
                  foreach (($alvos[$m['dimensao']] ?? []) as $a) {
                      if ((string)$a['id'] === (string)$m['dimensao_id']) {
                          $alvoLabel .= ': ' . $a['nome']; break;
                      }
                  }
              }
              $ehDinheiro = in_array($m['metrica'], ['faturamento','ticket_medio','margem'], true);
          ?>
          <tr data-meta='<?= View::e(json_encode($m, JSON_UNESCAPED_UNICODE)) ?>'>
            <td>
              <?= date('d/m/Y', strtotime($m['periodo_ini'])) ?>
              – <?= date('d/m/Y', strtotime($m['periodo_fim'])) ?>
              <div style="font-size:11px;color:var(--text-3);">
                <?= View::e($granuls[$m['granularidade']] ?? $m['granularidade']) ?>
              </div>
            </td>
            <td><?= View::e($metricas[$m['metrica']] ?? $m['metrica']) ?></td>
            <td><?= View::e($alvoLabel) ?></td>
            <td style="text-align:right;font-weight:700;">
              <?= $ehDinheiro
                    ? 'R$ ' . number_format((float)$m['valor_meta'], 2, ',', '.')
                    : number_format((float)$m['valor_meta'], 0, ',', '.') ?>
            </td>
            <td style="text-align:right;white-space:nowrap;">
              <button type="button" class="btn btn-xs btn-ghost js-editar-meta">Editar</button>
              <button type="button" class="btn btn-xs btn-ghost btn-danger js-excluir-meta"
                      data-id="<?= (int)$m['id'] ?>">Excluir</button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Modal -->
<div class="modal" id="modal-meta" style="display:none;">
  <div class="modal-box" style="max-width:560px;">
    <div class="modal-head">
      <h3 id="modal-meta-titulo">Nova meta</h3>
      <button type="button" class="modal-close" onclick="fecharModal('modal-meta')">&times;</button>
    </div>
    <div class="modal-body" style="display:flex;flex-direction:column;gap:12px;">
      <input type="hidden" id="mt-id">

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        <div>
          <label class="form-label-xs">Início do período</label>
          <input type="date" id="mt-ini" class="form-control">
        </div>
        <div>
          <label class="form-label-xs">Fim do período</label>
          <input type="date" id="mt-fim" class="form-control">
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        <div>
          <label class="form-label-xs">Métrica</label>
          <select id="mt-metrica" class="form-control">
            <?php foreach ($metricas as $k => $lbl): ?>
            <option value="<?= View::e($k) ?>"><?= View::e($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label-xs">Granularidade</label>
          <select id="mt-gran" class="form-control">
            <?php foreach ($granuls as $k => $lbl): ?>
            <option value="<?= View::e($k) ?>" <?= $k === 'mes' ? 'selected' : '' ?>>
              <?= View::e($lbl) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        <div>
          <label class="form-label-xs">Recorte</label>
          <select id="mt-dimensao" class="form-control">
            <?php foreach ($dimensoes as $k => $lbl): ?>
            <option value="<?= View::e($k) ?>"><?= View::e($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div id="mt-alvo-wrap" style="display:none;">
          <label class="form-label-xs">Alvo</label>
          <select id="mt-alvo" class="form-control"></select>
        </div>
      </div>

      <div>
        <label class="form-label-xs">Valor da meta</label>
        <input type="number" id="mt-valor" class="form-control" step="0.01" min="0"
               placeholder="0,00">
      </div>

      <div>
        <label class="form-label-xs">Observação</label>
        <input type="text" id="mt-obs" class="form-control" maxlength="255"
               placeholder="Opcional">
      </div>

      <div id="mt-msg" class="form-alert" style="display:none;"></div>
    </div>
    <div class="modal-foot" style="display:flex;gap:10px;">
      <button type="button" class="btn btn-primary" id="btn-salvar-meta">Salvar</button>
      <button type="button" class="btn btn-outline" onclick="fecharModal('modal-meta')">Cancelar</button>
    </div>
  </div>
</div>

<script>
(function () {
  // Alvos por dimensão vêm do servidor: o combo de alvo depende do
  // recorte escolhido, e buscar por Ajax a cada troca seria latência
  // sem ganho — a lista é pequena e já foi carregada com a página.
  var ALVOS = <?= json_encode($alvos, JSON_UNESCAPED_UNICODE) ?>;
  var BASE  = (window.BASE_URL || '') + '/admin/bi/metas';

  function alternarAlvo() {
    var dim  = document.getElementById('mt-dimensao').value,
        wrap = document.getElementById('mt-alvo-wrap'),
        sel  = document.getElementById('mt-alvo');

    if (dim === 'loja') { wrap.style.display = 'none'; sel.innerHTML = ''; return; }

    wrap.style.display = 'block';
    sel.innerHTML = '<option value="">Selecione…</option>';
    (ALVOS[dim] || []).forEach(function (a) {
      var o = document.createElement('option');
      o.value = a.id; o.textContent = a.nome;
      sel.appendChild(o);
    });
  }

  function abrir(meta) {
    document.getElementById('mt-id').value       = meta ? meta.id : '';
    document.getElementById('mt-ini').value      = meta ? meta.periodo_ini : '';
    document.getElementById('mt-fim').value      = meta ? meta.periodo_fim : '';
    document.getElementById('mt-metrica').value  = meta ? meta.metrica : 'faturamento';
    document.getElementById('mt-gran').value     = meta ? meta.granularidade : 'mes';
    document.getElementById('mt-dimensao').value = meta ? meta.dimensao : 'loja';
    document.getElementById('mt-valor').value    = meta ? meta.valor_meta : '';
    document.getElementById('mt-obs').value      = (meta && meta.observacao) || '';
    document.getElementById('modal-meta-titulo').textContent = meta ? 'Editar meta' : 'Nova meta';
    CK.formAlertClear($('#mt-msg'));

    alternarAlvo();
    if (meta) {
      document.getElementById('mt-alvo').value =
        meta.dimensao === 'canal' ? (meta.dimensao_valor || '') : (meta.dimensao_id || '');
    }
    abrirModal('modal-meta');
  }

  document.getElementById('mt-dimensao').addEventListener('change', alternarAlvo);
  document.getElementById('btn-nova-meta').addEventListener('click', function () { abrir(null); });

  $(document).on('click', '.js-editar-meta', function () {
    abrir($(this).closest('tr').data('meta'));
  });

  $('#btn-salvar-meta').on('click', function () {
    var $btn = $(this), $msg = $('#mt-msg');
    CK.formAlertClear($msg);
    CK.btnLoading($btn);

    CK.post(BASE + '/salvar', {
      id:            $('#mt-id').val(),
      periodo_ini:   $('#mt-ini').val(),
      periodo_fim:   $('#mt-fim').val(),
      granularidade: $('#mt-gran').val(),
      metrica:       $('#mt-metrica').val(),
      dimensao:      $('#mt-dimensao').val(),
      dimensao_id:   $('#mt-alvo').val() || '',
      valor_meta:    $('#mt-valor').val(),
      observacao:    $('#mt-obs').val()
    }).done(function (res) {
      CK.btnLoading($btn, false);
      if (res.ok) {
        Toast.success(res.msg);
        setTimeout(function () { location.reload(); }, 700);
      } else {
        CK.formAlertSet($msg, res.msg || 'Erro ao salvar.');
      }
    }).fail(function () {
      CK.btnLoading($btn, false);
      CK.formAlertSet($msg, 'Falha de comunicação.');
    });
  });

  $(document).on('click', '.js-excluir-meta', function () {
    var id = $(this).data('id');
    if (!confirm('Excluir esta meta?')) return;
    CK.post(BASE + '/excluir', { id: id }).done(function (res) {
      if (res.ok) { Toast.success(res.msg); location.reload(); }
      else { Toast.error(res.msg || 'Erro.'); }
    });
  });
})();
</script>
