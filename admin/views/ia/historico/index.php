<?php
/**
 * Central de Marketing IA — Histórico de gerações.
 * Variáveis: $linhas, $total, $pagina, $por_pagina, $filtros, $tipos,
 *            $kpis, $gasto_hoje, $pct_diario, $csrf
 */
if (!function_exists('ia_e')) {
    function ia_e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
}
include __DIR__ . '/../_estilos.php';

$paginas = max(1, (int) ceil(($total ?? 0) / ($por_pagina ?? 25)));
?>
<div class="ia_pagina">

  <div class="ia_topo">
    <div>
      <h1 class="ia_titulo"><i class="bi bi-clock-history"></i>Histórico de gerações</h1>
      <p class="ia_sub">Tudo o que a Central de Marketing IA produziu — com custo, modelo usado e curadoria.</p>
    </div>
    <div class="ia_topo_acoes">
      <a href="/admin/ia/gerar" class="ia_btn ia_btn_primario"><i class="bi bi-stars"></i> Gerar conteúdo</a>
      <a href="/admin/ia/config" class="ia_btn"><i class="bi bi-gear"></i> Configurações</a>
    </div>
  </div>

  <div class="ia_kpis">
    <div class="ia_kpi">
      <div class="ia_kpi_rotulo">Gerações hoje</div>
      <div class="ia_kpi_valor"><?= (int) $kpis['hoje'] ?></div>
    </div>
    <div class="ia_kpi">
      <div class="ia_kpi_rotulo">No mês</div>
      <div class="ia_kpi_valor"><?= (int) $kpis['mes'] ?></div>
    </div>
    <div class="ia_kpi">
      <div class="ia_kpi_rotulo">Aprovadas</div>
      <div class="ia_kpi_valor"><?= (int) $kpis['aprovados'] ?></div>
    </div>
    <div class="ia_kpi">
      <div class="ia_kpi_rotulo">Falhas</div>
      <div class="ia_kpi_valor"><?= (int) $kpis['falhas'] ?></div>
    </div>
    <div class="ia_kpi">
      <div class="ia_kpi_rotulo">Gasto hoje (global)</div>
      <div class="ia_kpi_valor">US$ <?= number_format((float) ($gasto_hoje ?? 0), 4, ',', '.') ?>
        <?php if ($pct_diario !== null): ?>
          <small><?= (int) $pct_diario ?>% do limite</small>
        <?php endif; ?>
      </div>
      <?php if ($pct_diario !== null && (int) $pct_diario >= 70): ?>
        <div style="margin-top:6px"><span class="ia_pill ia_pill_aviso"><i class="bi bi-exclamation-triangle"></i> Perto do teto diário</span></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="ia_card">
    <form id="ia_filtros" autocomplete="off">
      <div class="ia_form_linha" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr))">
        <div class="ia_form_grupo">
          <label>Status</label>
          <select name="status" class="ia_input">
            <option value="">Todos</option>
            <?php foreach (['na_fila' => 'Na fila', 'processando' => 'Processando', 'concluida' => 'Concluída', 'falhou' => 'Falhou', 'cancelada' => 'Cancelada'] as $v => $r): ?>
              <option value="<?= $v ?>" <?= ($filtros['status'] === $v) ? 'selected' : '' ?>><?= $r ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="ia_form_grupo">
          <label>Curadoria</label>
          <select name="aprovacao" class="ia_input">
            <option value="">Todas</option>
            <?php foreach (['pendente' => 'Pendente', 'aprovado' => 'Aprovado', 'reprovado' => 'Reprovado', 'arquivado' => 'Arquivado'] as $v => $r): ?>
              <option value="<?= $v ?>" <?= ($filtros['aprovacao'] === $v) ? 'selected' : '' ?>><?= $r ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="ia_form_grupo">
          <label>Tipo</label>
          <select name="tipo_conteudo_id" class="ia_input">
            <option value="">Todos</option>
            <?php foreach ($tipos as $t): ?>
              <option value="<?= (int) $t['id'] ?>" <?= ((int) $filtros['tipo_conteudo_id'] === (int) $t['id']) ? 'selected' : '' ?>>
                <?= ia_e($t['nome']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="ia_form_grupo">
          <label>Produto (nome ou ID)</label>
          <input type="text" name="busca" class="ia_input" value="<?= ia_e($filtros['busca']) ?>" placeholder="ex.: vela NGK">
        </div>
        <div class="ia_form_grupo">
          <label>De</label>
          <input type="date" name="data_ini" class="ia_input" value="<?= ia_e($filtros['data_ini']) ?>">
        </div>
        <div class="ia_form_grupo">
          <label>Até</label>
          <input type="date" name="data_fim" class="ia_input" value="<?= ia_e($filtros['data_fim']) ?>">
        </div>
      </div>
      <div style="display:flex;gap:8px;justify-content:flex-end">
        <button type="button" id="ia_btn_limpar" class="ia_btn"><i class="bi bi-x-lg"></i> Limpar</button>
        <button type="submit" class="ia_btn ia_btn_primario"><i class="bi bi-funnel"></i> Filtrar</button>
      </div>
    </form>
  </div>

  <div class="ia_card">
    <div class="ia_tabela_wrap">
      <table class="ia_tabela">
        <thead>
          <tr>
            <th>#</th>
            <th>Quando</th>
            <th>Tipo</th>
            <th>Produto</th>
            <th>Modelo</th>
            <th>Status</th>
            <th>Curadoria</th>
            <th class="ia_num">Custo</th>
            <th class="ia_num">Tempo</th>
            <th></th>
          </tr>
        </thead>
        <tbody id="ia_hist_corpo">
          <?php include __DIR__ . '/_linhas.php'; ?>
        </tbody>
      </table>
    </div>
    <div class="ia_paginacao">
      <span class="ia_paginacao_info" id="ia_pag_info">
        <?= (int) $total ?> geração(ões) · página <?= (int) $pagina ?> de <?= $paginas ?>
      </span>
      <div style="display:flex;gap:8px">
        <button type="button" class="ia_btn ia_btn_icone" id="ia_pag_ant" <?= ($pagina <= 1) ? 'disabled' : '' ?>><i class="bi bi-chevron-left"></i></button>
        <button type="button" class="ia_btn ia_btn_icone" id="ia_pag_prox" <?= ($pagina >= $paginas) ? 'disabled' : '' ?>><i class="bi bi-chevron-right"></i></button>
      </div>
    </div>
  </div>

</div>

<div class="ia_veu" id="ia_veu"></div>
<div class="ia_drawer" id="ia_drawer">
  <div class="ia_drawer_topo">
    <p class="ia_drawer_titulo" id="ia_drawer_titulo">Detalhe</p>
    <button type="button" class="ia_btn ia_btn_icone" id="ia_drawer_fechar"><i class="bi bi-x-lg"></i></button>
  </div>
  <div class="ia_drawer_corpo" id="ia_drawer_corpo"></div>
</div>
<div class="ia_toast" id="ia_toast"></div>

<script>
jQuery(function ($) {
  'use strict';

  var IA_CSRF = '<?= ia_e($csrf ?? '') ?>';
  var URLS = {
    linhas:    '/admin/ia/historico/linhas',
    detalhe:   '/admin/ia/historico/detalhe',
    aprovacao: '/admin/ia/historico/aprovacao',
    refazer:   '/admin/ia/historico/refazer'
  };

  var pagina  = <?= (int) $pagina ?>;
  var paginas = <?= (int) $paginas ?>;

  function toast(msg, ok) {
    var $t = $('#ia_toast');
    $t.text(msg).toggleClass('erro', ok === false).addClass('mostrar');
    clearTimeout($t.data('tm'));
    $t.data('tm', setTimeout(function () { $t.removeClass('mostrar'); }, 3200));
  }

  function iaPost(url, dados, cb) {
    dados = $.extend({ csrf_token: IA_CSRF }, dados || {});
    $.post(url, dados, null, 'json')
      .done(function (r) { cb(r || { ok: false, msg: 'Resposta inválida.' }); })
      .fail(function () { cb({ ok: false, msg: 'Falha de comunicação com o servidor.' }); });
  }

  function filtrosAtuais() {
    var f = {};
    $('#ia_filtros').serializeArray().forEach(function (c) { f[c.name] = c.value; });
    f.pagina = pagina;
    return f;
  }

  function recarregar() {
    $.getJSON(URLS.linhas, filtrosAtuais(), function (r) {
      if (!r.ok) { toast('Erro ao carregar o histórico.', false); return; }
      $('#ia_hist_corpo').html(r.html);
      paginas = r.paginas;
      $('#ia_pag_info').text(r.total + ' geração(ões) · página ' + r.pagina + ' de ' + r.paginas);
      $('#ia_pag_ant').prop('disabled', pagina <= 1);
      $('#ia_pag_prox').prop('disabled', pagina >= paginas);
    });
  }

  $('#ia_filtros').on('submit', function (e) { e.preventDefault(); pagina = 1; recarregar(); });
  $('#ia_btn_limpar').on('click', function () {
    $('#ia_filtros')[0].reset();
    $('#ia_filtros select').val('');
    pagina = 1;
    recarregar();
  });
  $('#ia_pag_ant').on('click', function () { if (pagina > 1) { pagina--; recarregar(); } });
  $('#ia_pag_prox').on('click', function () { if (pagina < paginas) { pagina++; recarregar(); } });

  /* Drawer -------------------------------------------------------- */

  function drawerAbrir(titulo, html) {
    if (typeof window.adminDrawer === 'function') {
      // AJUSTE: alinhe com a assinatura do helper adminDrawer do projeto.
      adminDrawer({
        titulo  : titulo,
        tamanho : 'lg',
        conteudo: html,
      });
      return;
    }
    $('#ia_drawer_titulo').text(titulo);
    $('#ia_drawer_corpo').html(html);
    $('#ia_veu').show();
    $('#ia_drawer').addClass('aberto');
  }

  function drawerFechar() {
    if (typeof window.adminDrawerClose === 'function') { window.adminDrawerClose(); }
    $('#ia_drawer').removeClass('aberto');
    $('#ia_veu').hide();
  }

  $('#ia_drawer_fechar, #ia_veu').on('click', drawerFechar);
  $(document).on('keydown', function (e) { if (e.key === 'Escape') { drawerFechar(); } });

  $(document).on('click', '.ia_ac_ver', function () {
    var id = $(this).closest('tr').data('id');
    $.getJSON(URLS.detalhe, { id: id }, function (r) {
      if (!r.ok) { toast(r.msg || 'Erro ao abrir o detalhe.', false); return; }
      drawerAbrir(r.titulo || 'Detalhe', r.html);
    });
  });

  /* Ações --------------------------------------------------------- */

  $(document).on('click', '.ia_ac_curar', function () {
    var $b = $(this);
    iaPost(URLS.aprovacao, { id: $b.data('id'), acao: $b.data('acao') }, function (r) {
      toast(r.msg || (r.ok ? 'Curadoria atualizada.' : 'Erro.'), r.ok);
      if (r.ok) { drawerFechar(); recarregar(); }
    });
  });

  $(document).on('click', '.ia_ac_refazer_hist', function () {
    var $b = $(this).prop('disabled', true);
    iaPost(URLS.refazer, { id: $b.data('id') }, function (r) {
      $b.prop('disabled', false);
      toast(r.ok ? 'Nova geração enfileirada — acompanhe pela tela Gerar ou atualize em instantes.' : (r.msg || 'Erro ao refazer.'), r.ok);
      if (r.ok) { drawerFechar(); recarregar(); }
    });
  });

  $(document).on('click', '.ia_ac_copiar_det', function () {
    var texto = $('#ia_det_texto').text();
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(texto).then(function () { toast('Copiado para a área de transferência.', true); });
    } else {
      var $ta = $('<textarea>').val(texto).appendTo('body').select();
      document.execCommand('copy');
      $ta.remove();
      toast('Copiado para a área de transferência.', true);
    }
  });
});
</script>
