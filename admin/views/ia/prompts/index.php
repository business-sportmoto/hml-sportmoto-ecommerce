<?php
/**
 * Central de IA · Biblioteca de prompts — página principal.
 * Variáveis: $itens, $resumo, $filtros, $csrf
 */
if (!function_exists('ia_e')) {
    function ia_e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
}
$capRotulo = IAPromptService::CAPACIDADES;
$temFiltro = array_filter($filtros, fn($v) => $v !== '') !== [];
?>
<div class="ia_wrap">

  <header class="ia_head">
    <div>
      <h1 class="ia_titulo"><?= IconLibrary::render('docs', 'ia_ico', ['aria-hidden' => 'true']) ?> Biblioteca de prompts</h1>
      <p class="ia_sub">Prompts salvos para texto, imagem e vídeo, um padrão por tipo de conteúdo — e a leitura de imagem que devolve o prompt pronto</p>
    </div>
    <div class="ia_topo_acoes">
      <a class="ia_btn" href="<?= BASE_URL ?>/admin/ia/gerar"><?= IconLibrary::render('wand-stars', 'ia_ico', ['aria-hidden' => 'true']) ?> Gerar conteúdo</a>
      <button type="button" class="ia_btn ia_btn_primario ia_ac_pr_novo"><?= IconLibrary::render('plus', 'ia_ico', ['aria-hidden' => 'true']) ?> Novo prompt</button>
    </div>
  </header>

  <section class="ia_kpis">
    <div class="ia_kpi"><span class="ia_kpi_rotulo">Prompts salvos</span><span class="ia_kpi_valor"><?= (int) $resumo['prompts'] ?></span></div>
    <div class="ia_kpi"><span class="ia_kpi_rotulo">Padrões definidos</span><span class="ia_kpi_valor"><?= (int) $resumo['padroes'] ?></span></div>
    <div class="ia_kpi"><span class="ia_kpi_rotulo">Lidos de imagem</span><span class="ia_kpi_valor"><?= (int) $resumo['de_imagem'] ?></span></div>
    <div class="ia_kpi"><span class="ia_kpi_rotulo">Usos em gerações</span><span class="ia_kpi_valor"><?= (int) $resumo['usos'] ?></span></div>
  </section>

  <section class="ia_card ia_card_pad">
    <p class="ia_card_titulo"><?= IconLibrary::render('camera', 'ia_ico', ['aria-hidden' => 'true']) ?> Ler uma imagem e gerar o prompt</p>
    <p class="ia_ajuda">
      Suba uma foto de referência (JPG, PNG ou WebP, até 5 MB) ou use a foto de um produto. A IA descreve o que vê e
      devolve um prompt de imagem e um de vídeo, curtos e em inglês. Você revisa e escolhe o que salvar.
    </p>

    <form id="ia_pr_form_leitura" autocomplete="off" enctype="multipart/form-data">
      <?= SecurityHelper::csrfField() ?>
      <input type="hidden" name="produto_id" id="ia_pr_produto_id" value="">
      <div class="ia_form_linha">
        <div class="ia_form_grupo">
          <label for="ia_pr_arquivo">Imagem do computador</label>
          <input type="file" id="ia_pr_arquivo" name="imagem" class="ia_input" accept="image/jpeg,image/png,image/webp">
        </div>
        <div class="ia_form_grupo">
          <label for="ia_pr_busca">…ou a foto de um produto</label>
          <div class="ia_busca_wrap">
            <input type="text" id="ia_pr_busca" class="ia_input" autocomplete="off" placeholder="Busque por nome ou ID do produto…">
            <div class="ia_busca_lista" id="ia_pr_busca_lista"></div>
          </div>
          <p class="ia_ajuda" id="ia_pr_produto_escolhido"></p>
        </div>
      </div>
      <div class="ia_form_rodape">
        <button type="submit" class="ia_btn ia_btn_primario" id="ia_pr_btn_ler"><?= IconLibrary::render('zoom-in', 'ia_ico', ['aria-hidden' => 'true']) ?> Ler imagem</button>
      </div>
    </form>

    <div id="ia_pr_resultado" hidden>
      <div class="ia_foto_strip">
        <img id="ia_pr_res_img" alt="Imagem lida" loading="lazy">
        <div>
          <p class="ia_foto_titulo" id="ia_pr_res_titulo"></p>
          <p class="ia_ajuda" id="ia_pr_res_resumo"></p>
          <p class="ia_ajuda ia_mono" id="ia_pr_res_meta"></p>
        </div>
      </div>
      <div class="ia_form_linha">
        <div class="ia_form_grupo">
          <label for="ia_pr_res_pimg">Prompt de imagem</label>
          <textarea id="ia_pr_res_pimg" class="ia_input ia_input_mono" rows="6" readonly spellcheck="false"></textarea>
          <div class="ia_resultado_acoes">
            <button type="button" class="ia_btn ia_ac_pr_salvar_leitura" data-variante="imagem"><?= IconLibrary::render('save', 'ia_ico', ['aria-hidden' => 'true']) ?> Salvar como prompt de imagem</button>
          </div>
        </div>
        <div class="ia_form_grupo">
          <label for="ia_pr_res_pvid">Prompt de vídeo</label>
          <textarea id="ia_pr_res_pvid" class="ia_input ia_input_mono" rows="6" readonly spellcheck="false"></textarea>
          <div class="ia_resultado_acoes">
            <button type="button" class="ia_btn ia_ac_pr_salvar_leitura" data-variante="video"><?= IconLibrary::render('videocam', 'ia_ico', ['aria-hidden' => 'true']) ?> Salvar como prompt de vídeo</button>
          </div>
        </div>
      </div>
      <div class="ia_form_grupo">
        <label for="ia_pr_res_neg">Evitar <span class="ia_label_nota">(vai no fim do prompt salvo, como "Avoid:")</span></label>
        <input type="text" id="ia_pr_res_neg" class="ia_input ia_input_mono" readonly>
      </div>
      <div class="ia_chips" id="ia_pr_res_elementos"></div>
    </div>
  </section>

  <section class="ia_card">
    <div class="ia_card_head">
      <h2 class="ia_card_titulo"><?= IconLibrary::render('stacks', 'ia_ico', ['aria-hidden' => 'true']) ?> Prompts</h2>
      <span class="ia_hint">Padrão entra sozinho no Gerar ao escolher o tipo. Ângulos só ajustam o tom de um texto montado.</span>
    </div>

    <form class="ia_form_linha ia_card_pad" method="get" action="<?= BASE_URL ?>/admin/ia/prompts">
      <div class="ia_form_grupo">
        <label for="ia_pr_f_busca">Buscar</label>
        <input type="search" id="ia_pr_f_busca" name="busca" class="ia_input" value="<?= ia_e($filtros['busca']) ?>" placeholder="Nome, descrição ou texto do prompt">
      </div>
      <div class="ia_form_grupo">
        <label for="ia_pr_f_natureza">Registro</label>
        <select id="ia_pr_f_natureza" name="natureza" class="ia_input">
          <option value="">Todos</option>
          <option value="prompt"<?= $filtros['natureza'] === 'prompt' ? ' selected' : '' ?>>Prompts completos</option>
          <option value="angulo"<?= $filtros['natureza'] === 'angulo' ? ' selected' : '' ?>>Ângulos criativos</option>
        </select>
      </div>
      <div class="ia_form_grupo">
        <label for="ia_pr_f_capacidade">Serve para</label>
        <select id="ia_pr_f_capacidade" name="capacidade" class="ia_input">
          <option value="">Qualquer</option>
          <?php foreach ($capRotulo as $v => $r): ?>
            <option value="<?= ia_e($v) ?>"<?= $filtros['capacidade'] === $v ? ' selected' : '' ?>><?= ia_e($r) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="ia_form_grupo">
        <label for="ia_pr_f_origem">Origem</label>
        <select id="ia_pr_f_origem" name="origem" class="ia_input">
          <option value="">Qualquer</option>
          <?php foreach (IAPromptService::ORIGENS as $v => $r): ?>
            <option value="<?= ia_e($v) ?>"<?= $filtros['origem'] === $v ? ' selected' : '' ?>><?= ia_e($r) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="ia_form_rodape">
        <button type="submit" class="ia_btn"><?= IconLibrary::render('funnel', 'ia_ico', ['aria-hidden' => 'true']) ?> Filtrar</button>
        <?php if ($temFiltro): ?><a class="ia_btn" href="<?= BASE_URL ?>/admin/ia/prompts">Limpar</a><?php endif; ?>
      </div>
    </form>

    <div class="ia_tabela_scroll">
      <table class="ia_tabela">
        <thead>
          <tr>
            <th>Prompt</th>
            <th>Serve para</th>
            <th>Origem</th>
            <th class="ia_num">Usos</th>
            <th>Status</th>
            <th class="ia_acoes_th">Ações</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($itens)): ?>
          <tr class="ia_vazio"><td colspan="6">
            <?= $temFiltro ? 'Nenhum prompt com esses filtros.' : 'Biblioteca vazia — crie um prompt ou leia uma imagem acima.' ?>
          </td></tr>
        <?php else: foreach ($itens as $p):
            $ehPrompt = $p['natureza'] === 'prompt';
        ?>
          <tr>
            <td>
              <span class="ia_celula_principal"><?= ia_e($p['nome']) ?>
                <?php if ((int) $p['padrao'] === 1): ?><span class="ia_pill ia_pill_ok"><?= IconLibrary::render('star', 'ia_ico', ['aria-hidden' => 'true']) ?> Padrão</span><?php endif; ?>
              </span>
              <?php if (!empty($p['descricao'])): ?><span class="ia_celula_sub"><?= ia_e($p['descricao']) ?></span><?php endif; ?>
              <span class="ia_celula_sub ia_mono"><?= ia_e(mb_strimwidth((string) $p['corpo'], 0, 140, '…')) ?></span>
            </td>
            <td>
              <?php if ($ehPrompt): ?>
                <span class="ia_pill ia_pill_neutra"><?= ia_e($capRotulo[$p['capacidade']] ?? $p['capacidade']) ?></span>
                <span class="ia_celula_sub"><?= ia_e($p['tipo_nome'] ?? 'qualquer tipo') ?></span>
              <?php else: ?>
                <span class="ia_pill ia_pill_neutra">Ângulo</span>
                <span class="ia_celula_sub ia_mono"><?= ia_e($p['angulo']) ?></span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($p['origem'] === 'sistema'): ?>
                <span class="ia_pill ia_pill_neutra">Sistema</span>
              <?php elseif ($p['origem'] === 'imagem'): ?>
                <span class="ia_pill ia_pill_azul"><?= IconLibrary::render('camera', 'ia_ico', ['aria-hidden' => 'true']) ?> Lido de imagem</span>
              <?php else: ?>
                <span class="ia_pill ia_pill_off">Feito à mão</span>
              <?php endif; ?>
              <?php if (!empty($p['autor_nome'])): ?><span class="ia_celula_sub"><?= ia_e($p['autor_nome']) ?></span><?php endif; ?>
            </td>
            <td class="ia_num"><?= (int) $p['usos'] ?></td>
            <td>
              <?php if ((int) $p['ativo'] === 1): ?>
                <span class="ia_pill ia_pill_ok"><?= IconLibrary::render('check-circle', 'ia_ico', ['aria-hidden' => 'true']) ?> Ativo</span>
              <?php else: ?>
                <span class="ia_pill ia_pill_off">Inativo</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="ia_acoes">
                <button type="button" class="ia_btn ia_btn_icone ia_ac_pr_editar" data-id="<?= (int) $p['id'] ?>" title="Editar" aria-label="Editar <?= ia_e($p['nome']) ?>"><?= IconLibrary::render('edit', 'ia_ico', ['aria-hidden' => 'true']) ?></button>
                <?php if ($ehPrompt): ?>
                  <button type="button" class="ia_btn ia_btn_icone ia_ac_pr_duplicar" data-id="<?= (int) $p['id'] ?>" title="Duplicar" aria-label="Duplicar <?= ia_e($p['nome']) ?>"><?= IconLibrary::render('copy', 'ia_ico', ['aria-hidden' => 'true']) ?></button>
                  <?php if (!empty($p['tipo_conteudo_id']) && (int) $p['padrao'] !== 1): ?>
                    <button type="button" class="ia_btn ia_btn_icone ia_ac_pr_padrao" data-id="<?= (int) $p['id'] ?>" title="Tornar padrão do tipo" aria-label="Tornar <?= ia_e($p['nome']) ?> o padrão do tipo"><?= IconLibrary::render('star', 'ia_ico', ['aria-hidden' => 'true']) ?></button>
                  <?php endif; ?>
                <?php endif; ?>
                <button type="button" class="ia_btn ia_btn_icone ia_ac_pr_alternar" data-id="<?= (int) $p['id'] ?>" title="<?= (int) $p['ativo'] === 1 ? 'Desativar' : 'Ativar' ?>" aria-label="<?= (int) $p['ativo'] === 1 ? 'Desativar' : 'Ativar' ?> <?= ia_e($p['nome']) ?>"><?= IconLibrary::render('power', 'ia_ico', ['aria-hidden' => 'true']) ?></button>
                <?php if ($p['origem'] !== 'sistema'): ?>
                  <button type="button" class="ia_btn ia_btn_icone ia_perigo ia_ac_pr_excluir" data-id="<?= (int) $p['id'] ?>" data-nome="<?= ia_e($p['nome']) ?>" title="Excluir" aria-label="Excluir <?= ia_e($p['nome']) ?>"><?= IconLibrary::render('trash', 'ia_ico', ['aria-hidden' => 'true']) ?></button>
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
    form:     '<?= BASE_URL ?>/admin/ia/prompts/form',
    salvar:   '<?= BASE_URL ?>/admin/ia/prompts/salvar',
    alternar: '<?= BASE_URL ?>/admin/ia/prompts/alternar',
    padrao:   '<?= BASE_URL ?>/admin/ia/prompts/padrao',
    duplicar: '<?= BASE_URL ?>/admin/ia/prompts/duplicar',
    excluir:  '<?= BASE_URL ?>/admin/ia/prompts/excluir',
    ler:      '<?= BASE_URL ?>/admin/ia/prompts/ler-imagem',
    busca:    '<?= BASE_URL ?>/admin/ia/gerar/produto-busca',
    arquivo:  '<?= BASE_URL ?>/admin/ia/arquivo'
  };

  function iaGet(url, dados) { return $.ajax({ url: url, method: 'GET', data: dados || {}, dataType: 'json' }); }
  function iaPost(url, dados) { return $.ajax({ url: url, method: 'POST', data: $.extend({ _csrf_token: IA_CSRF }, dados || {}), dataType: 'json' }); }

  var $toast = null, toastTimer = null;
  function toast(msg, erro) {
    if (!$toast) { $toast = $('<div class="ia_toast" role="status"></div>').appendTo('body'); }
    $toast.text(msg).toggleClass('erro', !!erro).addClass('visivel');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { $toast.removeClass('visivel'); }, 3600);
  }
  function recarregarDepois(r) { if (r && r.ok) { setTimeout(function () { window.location.reload(); }, 700); } }

  /* ── Formulário no drawer ───────────────────────────────────── */

  // Campos que só valem para uma natureza, e tipos filtrados pela capacidade.
  function ajustarForm($f) {
    var natureza = $f.find('[name=natureza]').val() || 'prompt';
    $f.find('[data-so]').each(function () { $(this).toggle($(this).data('so') === natureza); });
    var cap = natureza === 'angulo' ? 'texto' : $f.find('[name=capacidade]').val();
    var $tipo = $f.find('[name=tipo_conteudo_id]');
    $tipo.find('option[data-cap]').each(function () {
      var serve = $(this).data('cap') === cap;
      $(this).prop('hidden', !serve).prop('disabled', !serve);
    });
    if ($tipo.find('option:selected').prop('disabled')) { $tipo.val(''); }
  }

  var drawer = null;
  function abrirForm(params) {
    iaGet(URLS.form, params || {}).done(function (r) {
      if (!(r && r.ok)) { toast((r && r.msg) || 'Erro ao carregar o formulário.', true); return; }
      if (typeof window.adminDrawer !== 'function') { toast('Componente de painel lateral indisponível.', true); return; }
      drawer = window.adminDrawer({ titulo: r.titulo, subtitulo: 'Biblioteca de prompts da Central de IA', conteudo: r.html, tamanho: 'lg', focoInicial: 'input[name=nome]' });
      ajustarForm($(drawer.corpo()).find('form.ia_c_form_prompt'));
      drawer.escutar('change', '[name=natureza], [name=capacidade]', function (ev) {
        ajustarForm($(ev.target).closest('form'));
      });
      drawer.escutar('submit', 'form.ia_c_form_prompt', function (ev) {
        ev.preventDefault();
        var $f = $(ev.target), $btn = $f.find('[type=submit]').prop('disabled', true);
        $.ajax({ url: URLS.salvar, method: 'POST', data: $f.serialize(), dataType: 'json' })
          .done(function (r) { toast((r && r.msg) || 'Feito.', !(r && r.ok)); recarregarDepois(r); })
          .fail(function (x) { toast((x.responseJSON && x.responseJSON.msg) || 'Falha de comunicação.', true); })
          .always(function () { $btn.prop('disabled', false); });
      });
    }).fail(function () { toast('Falha de comunicação.', true); });
  }

  $(document).on('click', '.ia_ac_pr_novo',   function () { abrirForm({}); });
  $(document).on('click', '.ia_ac_pr_editar', function () { abrirForm({ id: $(this).data('id') }); });

  function acaoSimples(url, id) {
    iaPost(url, { id: id }).done(function (r) { toast((r && r.msg) || 'Feito.', !(r && r.ok)); recarregarDepois(r); })
      .fail(function () { toast('Falha de comunicação.', true); });
  }
  $(document).on('click', '.ia_ac_pr_alternar', function () { acaoSimples(URLS.alternar, $(this).data('id')); });
  $(document).on('click', '.ia_ac_pr_padrao',   function () { acaoSimples(URLS.padrao,   $(this).data('id')); });
  $(document).on('click', '.ia_ac_pr_duplicar', function () { acaoSimples(URLS.duplicar, $(this).data('id')); });
  $(document).on('click', '.ia_ac_pr_excluir',  function () {
    if (!window.confirm('Excluir o prompt "' + $(this).data('nome') + '"? Se ele já gerou conteúdo, a exclusão é recusada — desative nesse caso.')) { return; }
    acaoSimples(URLS.excluir, $(this).data('id'));
  });

  /* ── Leitura de imagem ──────────────────────────────────────── */

  var buscaTimer = null;
  $('#ia_pr_busca').on('input', function () {
    var q = $.trim($(this).val());
    clearTimeout(buscaTimer);
    if (q.length < 2) { $('#ia_pr_busca_lista').hide().empty(); return; }
    buscaTimer = setTimeout(function () {
      iaGet(URLS.busca, { q: q }).done(function (r) {
        var $l = $('#ia_pr_busca_lista').empty();
        (r && r.itens ? r.itens : []).forEach(function (p) {
          $('<button type="button" class="ia_busca_item"></button>')
            .attr('data-id', p.id).text('#' + p.id + ' · ' + p.nome).appendTo($l);
        });
        $l.toggle($l.children().length > 0);
      });
    }, 300);
  });
  $(document).on('click', '#ia_pr_busca_lista .ia_busca_item', function () {
    $('#ia_pr_produto_id').val($(this).data('id'));
    $('#ia_pr_produto_escolhido').text('Vai ler a foto principal de: ' + $(this).text());
    $('#ia_pr_arquivo').val('');
    $('#ia_pr_busca').val('');
    $('#ia_pr_busca_lista').hide().empty();
  });
  $('#ia_pr_arquivo').on('change', function () {
    if (this.files && this.files.length) { $('#ia_pr_produto_id').val(''); $('#ia_pr_produto_escolhido').text(''); }
  });

  var geracaoLida = 0;
  $('#ia_pr_form_leitura').on('submit', function (ev) {
    ev.preventDefault();
    var arquivo = $('#ia_pr_arquivo')[0];
    if (!(arquivo.files && arquivo.files.length) && !$('#ia_pr_produto_id').val()) {
      toast('Escolha uma imagem ou um produto.', true);
      return;
    }
    var $btn = $('#ia_pr_btn_ler').prop('disabled', true).text('Lendo a imagem…');
    $.ajax({ url: URLS.ler, method: 'POST', data: new FormData(this), processData: false, contentType: false, dataType: 'json' })
      .done(function (r) {
        if (!(r && r.ok)) { toast((r && r.msg) || 'Não foi possível ler a imagem.', true); return; }
        geracaoLida = r.geracao_id;
        if (r.arquivo_id) { $('#ia_pr_res_img').attr('src', URLS.arquivo + '?id=' + r.arquivo_id).show(); } else { $('#ia_pr_res_img').hide(); }
        $('#ia_pr_res_titulo').text(r.titulo || 'Imagem lida');
        $('#ia_pr_res_resumo').text(r.resumo || '');
        var meta = [];
        if (r._ia && r._ia.modelo) { meta.push(r._ia.modelo); }
        if (r._ia && r._ia.custo_usd !== null && r._ia.custo_usd !== undefined) { meta.push('US$ ' + Number(r._ia.custo_usd).toFixed(5).replace('.', ',')); }
        if (r._ia && r._ia.tempo_ms) { meta.push((r._ia.tempo_ms / 1000).toFixed(1).replace('.', ',') + ' s'); }
        $('#ia_pr_res_meta').text(meta.join(' · '));
        $('#ia_pr_res_pimg').val(r.prompt_imagem || '');
        $('#ia_pr_res_pvid').val(r.prompt_video || '');
        $('#ia_pr_res_neg').val(r.negativo || '');
        $('.ia_ac_pr_salvar_leitura[data-variante=imagem]').prop('disabled', !r.prompt_imagem);
        $('.ia_ac_pr_salvar_leitura[data-variante=video]').prop('disabled', !r.prompt_video);
        var $el = $('#ia_pr_res_elementos').empty();
        var rotulos = { sujeito: 'Sujeito', material_cor: 'Material e cor', enquadramento: 'Enquadramento', luz: 'Luz', fundo: 'Fundo', camera: 'Câmera' };
        Object.keys(rotulos).forEach(function (k) {
          if (r.elementos && r.elementos[k]) { $('<span class="ia_chip"></span>').text(rotulos[k] + ': ' + r.elementos[k]).appendTo($el); }
        });
        $('#ia_pr_resultado').prop('hidden', false);
      })
      .fail(function (x) { toast((x.responseJSON && x.responseJSON.msg) || 'Falha de comunicação.', true); })
      .always(function () { $btn.prop('disabled', false).text('Ler imagem'); });
  });

  $(document).on('click', '.ia_ac_pr_salvar_leitura', function () {
    if (!geracaoLida) { return; }
    abrirForm({ geracao_id: geracaoLida, variante: $(this).data('variante') });
  });
})(jQuery);
</script>
