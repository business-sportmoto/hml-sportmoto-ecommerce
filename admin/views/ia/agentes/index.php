<?php
/**
 * Agentes de IA · catálogo — página principal.
 * Variáveis: $agentes, $paginas, $csrf
 */
if (!function_exists('ia_e')) {
    function ia_e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
}
$ativos = count(array_filter($agentes, fn($a) => (int) $a['ativo'] === 1));
$semDono = array_diff(array_keys($paginas), array_merge(...array_map(fn($a) => (int) $a['ativo'] === 1 ? $a['paginas'] : [], $agentes ?: [[]])));
?>
<div class="ia_wrap">

  <header class="ia_head">
    <div>
      <h1 class="ia_titulo"><?= IconLibrary::render('person-circle', 'ia_ico', ['aria-hidden' => 'true']) ?> Agentes de IA</h1>
      <p class="ia_sub">Quem responde no botão <strong>Analisar com IA</strong> do painel de BI — persona, ferramentas, páginas e rodada agendada</p>
    </div>
    <div class="ia_topo_acoes">
      <a class="ia_btn" href="<?= BASE_URL ?>/admin/power-bi"><?= IconLibrary::render('arrow-right', 'ia_ico', ['aria-hidden' => 'true']) ?> Abrir o BI</a>
      <button type="button" class="ia_btn ia_btn_primario ia_ac_ag_novo"><?= IconLibrary::render('plus', 'ia_ico', ['aria-hidden' => 'true']) ?> Novo agente</button>
    </div>
  </header>

  <section class="ia_kpis">
    <div class="ia_kpi"><span class="ia_kpi_rotulo">Agentes ativos</span><span class="ia_kpi_valor"><?= $ativos ?><span class="ia_kpi_de">/<?= count($agentes) ?></span></span></div>
    <div class="ia_kpi"><span class="ia_kpi_rotulo">Páginas do BI atendidas</span><span class="ia_kpi_valor"><?= count($paginas) - count($semDono) ?><span class="ia_kpi_de">/<?= count($paginas) ?></span></span></div>
    <div class="ia_kpi"><span class="ia_kpi_rotulo">Conversas no histórico</span><span class="ia_kpi_valor"><?= array_sum(array_column($agentes, 'conversas')) ?></span></div>
    <div class="ia_kpi"><span class="ia_kpi_rotulo">Rodada agendada</span><span class="ia_kpi_valor"><?= count(array_filter($agentes, fn($a) => (int) $a['ativo'] === 1 && (int) $a['agendado_ativo'] === 1)) ?><span class="ia_kpi_de"> agente(s)</span></span></div>
  </section>

  <?php if ($semDono !== []): ?>
  <div class="ia_aviso_seguro" style="margin-bottom:14px">
    <?= IconLibrary::render('alert-triangle', 'ia_ico', ['aria-hidden' => 'true']) ?>
    <span>Páginas do BI sem agente: <strong><?= ia_e(implode(', ', array_map(fn($p) => $paginas[$p], $semDono))) ?></strong>. Nelas o botão de IA fica desligado.</span>
  </div>
  <?php endif; ?>

  <section class="ia_card">
    <div class="ia_card_head">
      <h2 class="ia_card_titulo"><?= IconLibrary::render('stacks', 'ia_ico', ['aria-hidden' => 'true']) ?> Catálogo</h2>
      <span class="ia_hint">Uma página do BI tem um só agente. Persona e ferramentas definem o que ele sabe dizer.</span>
    </div>
    <div class="ia_tabela_scroll">
      <table class="ia_tabela">
        <thead>
          <tr>
            <th>Agente</th>
            <th>Páginas</th>
            <th class="ia_num">Ferramentas</th>
            <th>Modelo</th>
            <th>Agendado</th>
            <th class="ia_num">Conversas</th>
            <th>Status</th>
            <th class="ia_acoes_th">Ações</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($agentes)): ?>
          <tr class="ia_vazio"><td colspan="8">Nenhum agente — rode <code>php cli/ia-migrar.php --aplicar</code> para semear os três padrão, ou crie um.</td></tr>
        <?php else: foreach ($agentes as $a): ?>
          <tr>
            <td>
              <span class="ia_celula_principal"><?= ia_e($a['nome_exibicao']) ?> <span class="ia_pill ia_pill_neutra"><?= ia_e($a['rotulo_curto']) ?></span></span>
              <span class="ia_celula_sub ia_mono"><?= ia_e($a['codigo']) ?></span>
              <?php if (!empty($a['descricao'])): ?><span class="ia_celula_sub"><?= ia_e($a['descricao']) ?></span><?php endif; ?>
            </td>
            <td>
              <?php if ($a['paginas'] === []): ?><span class="ia_pill ia_pill_off">nenhuma</span>
              <?php else: ?>
                <div class="ia_chips"><?php foreach ($a['paginas'] as $p): ?><span class="ia_chip"><?= ia_e($paginas[$p] ?? $p) ?></span><?php endforeach; ?></div>
              <?php endif; ?>
            </td>
            <td class="ia_num"><?= count($a['ferramentas']) ?></td>
            <td>
              <span class="ia_mono"><?= ia_e($a['codigo_modelo'] ?? 'padrão da capacidade') ?></span>
              <span class="ia_celula_sub">effort <?= ia_e($a['effort']) ?> · <?= (int) $a['max_tokens'] ?> tokens</span>
            </td>
            <td>
              <?php if ((int) $a['agendado_ativo'] === 1 && !empty($a['pergunta_agendada'])): ?>
                <span class="ia_pill ia_pill_azul">diária</span>
                <span class="ia_celula_sub">pré-carga: <?= ia_e($paginas[$a['pagina_agendada']] ?? $a['pagina_agendada'] ?? '—') ?></span>
              <?php else: ?>
                <span class="ia_pill ia_pill_off">não</span>
              <?php endif; ?>
            </td>
            <td class="ia_num"><?= (int) $a['conversas'] ?></td>
            <td>
              <?php if ((int) $a['ativo'] === 1): ?>
                <span class="ia_pill ia_pill_ok"><?= IconLibrary::render('check-circle', 'ia_ico', ['aria-hidden' => 'true']) ?> Ativo</span>
              <?php else: ?>
                <span class="ia_pill ia_pill_off">Inativo</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="ia_acoes">
                <button type="button" class="ia_btn ia_btn_icone ia_ac_ag_editar" data-id="<?= (int) $a['id'] ?>" title="Editar" aria-label="Editar <?= ia_e($a['nome_exibicao']) ?>"><?= IconLibrary::render('edit', 'ia_ico', ['aria-hidden' => 'true']) ?></button>
                <button type="button" class="ia_btn ia_btn_icone ia_ac_ag_alternar" data-id="<?= (int) $a['id'] ?>" title="<?= (int) $a['ativo'] === 1 ? 'Desativar' : 'Ativar' ?>" aria-label="<?= (int) $a['ativo'] === 1 ? 'Desativar' : 'Ativar' ?> <?= ia_e($a['nome_exibicao']) ?>"><?= IconLibrary::render('power', 'ia_ico', ['aria-hidden' => 'true']) ?></button>
                <?php if ((int) $a['conversas'] === 0): ?>
                <button type="button" class="ia_btn ia_btn_icone ia_ac_ag_excluir" data-id="<?= (int) $a['id'] ?>" data-nome="<?= ia_e($a['nome_exibicao']) ?>" title="Excluir" aria-label="Excluir <?= ia_e($a['nome_exibicao']) ?>"><?= IconLibrary::render('trash', 'ia_ico', ['aria-hidden' => 'true']) ?></button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </section>

</div>

<script>
(function ($) {
  'use strict';
  var IA_CSRF = '<?= ia_e($csrf ?? '') ?>';
  var URLS = {
    form:     '<?= BASE_URL ?>/admin/ia/agentes/form',
    salvar:   '<?= BASE_URL ?>/admin/ia/agentes/salvar',
    alternar: '<?= BASE_URL ?>/admin/ia/agentes/alternar',
    excluir:  '<?= BASE_URL ?>/admin/ia/agentes/excluir'
  };

  function iaGet(url, dados) { return $.ajax({ url: url, method: 'GET', data: dados || {}, dataType: 'json' }); }
  function iaPost(url, dados) { return $.ajax({ url: url, method: 'POST', data: $.extend({ _csrf_token: IA_CSRF }, dados || {}), dataType: 'json' }); }

  var $toast = null, toastTimer = null;
  function toast(msg, erro) {
    if (!$toast) { $toast = $('<div class="ia_toast" role="status"></div>').appendTo('body'); }
    $toast.text(msg).toggleClass('erro', !!erro).addClass('visivel');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { $toast.removeClass('visivel'); }, 3200);
  }

  var drawer = null;
  function abrirForm(id) {
    iaGet(URLS.form, id ? { id: id } : {}).done(function (r) {
      if (!(r && r.ok)) { toast((r && r.msg) || 'Erro ao carregar o formulário.', true); return; }
      if (typeof window.adminDrawer !== 'function') { toast('Componente de painel lateral indisponível.', true); return; }
      drawer = window.adminDrawer({ titulo: r.titulo, subtitulo: 'Persona, ferramentas, páginas e rodada agendada', conteudo: r.html, tamanho: 'lg', focoInicial: 'input[name=nome_exibicao]' });
      drawer.escutar('submit', 'form.ia_form_agente', function (ev) {
        ev.preventDefault();
        var $f = $(ev.target), $btn = $f.find('[type=submit]').prop('disabled', true);
        $.ajax({ url: URLS.salvar, method: 'POST', data: $f.serialize(), dataType: 'json' })
          .done(function (r) {
            toast((r && r.msg) || 'Feito.', !(r && r.ok));
            if (r && r.ok) { setTimeout(function () { window.location.reload(); }, 700); }
          })
          .fail(function (x) { toast((x.responseJSON && x.responseJSON.msg) || 'Falha de comunicação.', true); })
          .always(function () { $btn.prop('disabled', false); });
      });
      // Marca/desmarca todas as ferramentas de um domínio.
      drawer.escutar('click', '.ia_ag_dominio_todos', function (ev) {
        var $g = $(ev.target).closest('.ia_ag_dominio');
        var todos = $g.find('input[type=checkbox]').length === $g.find('input[type=checkbox]:checked').length;
        $g.find('input[type=checkbox]').prop('checked', !todos);
      });
    }).fail(function () { toast('Falha de comunicação.', true); });
  }

  $(document).on('click', '.ia_ac_ag_novo',   function () { abrirForm(0); });
  $(document).on('click', '.ia_ac_ag_editar', function () { abrirForm($(this).data('id')); });
  $(document).on('click', '.ia_ac_ag_alternar', function () {
    iaPost(URLS.alternar, { id: $(this).data('id') }).done(function (r) {
      toast((r && r.msg) || 'Feito.', !(r && r.ok));
      if (r && r.ok) setTimeout(function () { window.location.reload(); }, 600);
    }).fail(function () { toast('Falha de comunicação.', true); });
  });
  $(document).on('click', '.ia_ac_ag_excluir', function () {
    var nome = $(this).data('nome');
    if (!window.confirm('Excluir o agente "' + nome + '"? Só é possível sem histórico de conversas.')) return;
    iaPost(URLS.excluir, { id: $(this).data('id') }).done(function (r) {
      toast((r && r.msg) || 'Feito.', !(r && r.ok));
      if (r && r.ok) setTimeout(function () { window.location.reload(); }, 600);
    }).fail(function () { toast('Falha de comunicação.', true); });
  });
})(jQuery);
</script>
