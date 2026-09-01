<?php
/**
 * admin/views/chat/campanha-form.php
 * @var array|null $campanha @var array $templates @var array $tags @var array $fluxos
 */
$base = defined('BASE_URL') ? BASE_URL : '';
$h    = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$c   = $campanha;
$seg = $c['segmento'] ?? [];
$tv  = $c['template_vars'] ?? [];
$segTags    = array_map('intval', (array)($seg['tags'] ?? []));
$segExcluir = array_map('intval', (array)($seg['tags_excluir'] ?? []));
?>

<div class="ch">

  <div class="ch-head">
    <div>
      <h1><?= $c ? 'Editar campanha' : 'Nova campanha' ?></h1>
      <p>Escolha a mensagem, monte o público e defina o ritmo de envio.</p>
    </div>
    <div class="ch-head-acoes">
      <a href="<?= $base ?>/admin/chat/campanhas" class="ch-btn">← Campanhas</a>
    </div>
  </div>

  <form id="ch-form-camp">
    <input type="hidden" name="csrf_token" value="<?= $h($csrf_token ?? '') ?>">
    <input type="hidden" name="id" value="<?= $c ? (int)$c['id'] : '' ?>">

    <div style="display:grid;grid-template-columns:minmax(0,2fr) minmax(300px,1fr);gap:16px;align-items:start;">

      <div>
        <?php // ── Mensagem ────────────────────────────────────────────── ?>
        <div class="ch-card" style="margin-bottom:16px;">
          <div class="ch-card-head"><h2>1. Mensagem</h2></div>
          <div class="ch-card-body">
            <div class="ch-campo">
              <label class="ch-label">Nome da campanha (interno)</label>
              <input type="text" class="ch-input" name="nome" value="<?= $h($c['nome'] ?? '') ?>" required>
            </div>

            <div class="ch-campo">
              <label class="ch-label">Tipo de envio</label>
              <select class="ch-select" name="tipo" id="ch-tipo">
                <option value="template" <?= ($c['tipo'] ?? 'template') === 'template' ? 'selected' : '' ?>>
                  Template aprovado — alcança qualquer contato
                </option>
                <option value="texto" <?= ($c['tipo'] ?? '') === 'texto' ? 'selected' : '' ?>>
                  Texto livre — só quem tem janela de 24h aberta
                </option>
              </select>
            </div>

            <div id="ch-box-template">
              <?php if (!$templates): ?>
                <div class="ch-aviso ch-aviso--erro">
                  <div>
                    <strong class="ch-aviso-tit">Nenhum template aprovado</strong>
                    <a href="<?= $base ?>/admin/chat/templates">Sincronize os templates</a>
                    ou crie um no Gerenciador do WhatsApp.
                  </div>
                </div>
              <?php else: ?>
                <div class="ch-campo">
                  <label class="ch-label">Template</label>
                  <select class="ch-select" name="template_nome" id="ch-tpl">
                    <option value="">— selecione —</option>
                    <?php foreach ($templates as $t): ?>
                      <option value="<?= $h($t['nome']) ?>"
                              data-idioma="<?= $h($t['idioma']) ?>"
                              data-vars="<?= (int)$t['vars_body'] ?>"
                              data-header="<?= $h($t['header_tipo'] ?: '') ?>"
                              data-varsheader="<?= (int)$t['vars_header'] ?>"
                              data-btnurl="<?= (int)$t['botoes_url'] ?>"
                              data-corpo="<?= $h($t['corpo_preview']) ?>"
                              <?= ($c['template_nome'] ?? '') === $t['nome'] ? 'selected' : '' ?>>
                        <?= $h($t['nome']) ?> — <?= (int)$t['vars_body'] ?> variável(is)
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <input type="hidden" name="template_idioma" id="ch-tpl-idioma"
                         value="<?= $h($c['template_idioma'] ?? 'pt_BR') ?>">
                </div>

                <div id="ch-tpl-vars"></div>

                <div id="ch-tpl-prev" style="display:none;margin-top:14px;">
                  <label class="ch-label">Como o cliente vai ver</label>
                  <div class="ch-msg ch-msg--out" style="max-width:100%;">
                    <div class="ch-bolha" id="ch-tpl-prev-txt"></div>
                  </div>
                </div>
              <?php endif; ?>
            </div>

            <div id="ch-box-texto" style="display:none;">
              <div class="ch-campo">
                <label class="ch-label">Mensagem</label>
                <textarea class="ch-textarea" name="mensagem" rows="5"><?= $h($c['mensagem'] ?? '') ?></textarea>
                <div class="ch-ajuda">
                  Aceita {{primeiro_nome}}, {{saudacao}} e campos do contato.
                  <strong>Só chega em quem está com a janela aberta.</strong>
                </div>
              </div>
            </div>

            <div class="ch-campo">
              <label class="ch-label">Iniciar um fluxo depois do envio (opcional)</label>
              <select class="ch-select" name="fluxo_id">
                <option value="0">Nenhum</option>
                <?php foreach ($fluxos as $f): ?>
                  <option value="<?= (int)$f['id'] ?>" <?= (int)($c['fluxo_id'] ?? 0) === (int)$f['id'] ? 'selected' : '' ?>>
                    <?= $h($f['nome']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="ch-ajuda">Útil para pesquisa de satisfação ou para tratar a resposta.</div>
            </div>
          </div>
        </div>

        <?php // ── Público ─────────────────────────────────────────────── ?>
        <div class="ch-card" style="margin-bottom:16px;">
          <div class="ch-card-head"><h2>2. Público</h2></div>
          <div class="ch-card-body">
            <div class="ch-aviso ch-aviso--info">
              <div>
                Quem fez opt-out ou está bloqueado é sempre excluído — isso não é
                configurável, é regra do sistema.
              </div>
            </div>

            <div class="ch-grid-2">
              <div class="ch-campo">
                <label class="ch-label">Incluir quem tem a tag</label>
                <select class="ch-select ch-seg" name="tags[]" multiple size="5">
                  <?php foreach ($tags as $t): ?>
                    <option value="<?= (int)$t['id'] ?>" <?= in_array((int)$t['id'], $segTags, true) ? 'selected' : '' ?>>
                      <?= $h($t['nome']) ?> (<?= (int)$t['total'] ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
                <div class="ch-ajuda">Nada selecionado = todos os contatos.</div>
              </div>

              <div class="ch-campo">
                <label class="ch-label">Excluir quem tem a tag</label>
                <select class="ch-select ch-seg" name="tags_excluir[]" multiple size="5">
                  <?php foreach ($tags as $t): ?>
                    <option value="<?= (int)$t['id'] ?>" <?= in_array((int)$t['id'], $segExcluir, true) ? 'selected' : '' ?>>
                      <?= $h($t['nome']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div class="ch-grid-3">
              <div class="ch-campo">
                <label class="ch-label">Combinação das tags</label>
                <select class="ch-select ch-seg" name="tags_modo">
                  <option value="qualquer" <?= ($seg['tags_modo'] ?? 'qualquer') === 'qualquer' ? 'selected' : '' ?>>Qualquer uma</option>
                  <option value="todas"    <?= ($seg['tags_modo'] ?? '') === 'todas' ? 'selected' : '' ?>>Todas elas</option>
                </select>
              </div>
              <div class="ch-campo">
                <label class="ch-label">É cliente da loja</label>
                <select class="ch-select ch-seg" name="com_cliente">
                  <option value="">Tanto faz</option>
                  <option value="1" <?= ($seg['com_cliente'] ?? '') === 1 ? 'selected' : '' ?>>Só clientes</option>
                  <option value="0" <?= isset($seg['com_cliente']) && $seg['com_cliente'] === 0 ? 'selected' : '' ?>>Só não-clientes</option>
                </select>
              </div>
              <div class="ch-campo">
                <label class="ch-label">Cadastrados a partir de</label>
                <input type="date" class="ch-input ch-seg" name="desde" value="<?= $h($seg['desde'] ?? '') ?>">
              </div>
            </div>
          </div>
        </div>

        <?php // ── Envio ───────────────────────────────────────────────── ?>
        <div class="ch-card">
          <div class="ch-card-head"><h2>3. Envio</h2></div>
          <div class="ch-card-body">
            <div class="ch-grid-2">
              <div class="ch-campo">
                <label class="ch-label">Agendar para (deixe vazio para disparar manualmente)</label>
                <input type="datetime-local" class="ch-input" name="agendado_para"
                       value="<?= $c && $c['agendado_para'] ? date('Y-m-d\TH:i', strtotime((string)$c['agendado_para'])) : '' ?>">
              </div>
              <div class="ch-campo">
                <label class="ch-label">Ritmo (mensagens por minuto)</label>
                <input type="number" class="ch-input" name="ritmo_por_minuto" min="1" max="600"
                       value="<?= (int)($c['ritmo_por_minuto'] ?? 60) ?>">
                <div class="ch-ajuda">
                  Devagar protege a reputação do número. 60/min dá 3.600 por hora.
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <?php // ── Lateral: resumo ────────────────────────────────────────── ?>
      <div>
        <div class="ch-card" style="position:sticky;top:16px;">
          <div class="ch-card-head"><h2>Resumo</h2></div>
          <div class="ch-card-body">
            <div class="ch-kpi" style="border:none;padding:0;margin-bottom:14px;">
              <div class="ch-kpi-rot">Público estimado</div>
              <div class="ch-kpi-val" id="ch-estimativa">—</div>
              <div class="ch-kpi-sub" id="ch-estimativa-obs">ajuste os filtros para calcular</div>
            </div>

            <div id="ch-camp-erro" class="ch-sm" style="color:var(--danger);margin-bottom:10px;"></div>

            <button type="submit" class="ch-btn ch-btn--pri" style="width:100%;">
              <?= $c ? 'Salvar alterações' : 'Salvar rascunho' ?>
            </button>

            <?php if ($c && in_array($c['status'], ['rascunho', 'agendada', 'pausada'], true)): ?>
              <button type="button" class="ch-btn ch-btn--wa" style="width:100%;margin-top:8px;" id="ch-disparar">
                <?= $c['status'] === 'pausada' ? 'Retomar envio' : 'Disparar agora' ?>
              </button>
            <?php endif; ?>

            <p class="ch-sm ch-mut" style="margin:12px 0 0;">
              O disparo só começa depois de você confirmar. Nada é enviado ao salvar.
            </p>
          </div>
        </div>
      </div>
    </div>
  </form>
</div>

<script>
(function ($) {
  var BASE = window.BASE_URL || '<?= $base ?>';
  var CSRF = '<?= $h($csrf_token ?? '') ?>';
  var CAMP_ID = <?= $c ? (int)$c['id'] : 0 ?>;
  var VARS_SALVAS = <?= json_encode(array_values((array)($tv['body'] ?? [])), JSON_UNESCAPED_UNICODE) ?>;

  function esc(s) { return $('<i>').text(s == null ? '' : String(s)).html(); }

  function alternarTipo() {
    var t = $('#ch-tipo').val();
    $('#ch-box-template').toggle(t === 'template');
    $('#ch-box-texto').toggle(t === 'texto');
    estimar();
  }
  $('#ch-tipo').on('change', alternarTipo);

  // ── Variáveis do template ──────────────────────────────────────────────
  function montarVars(usarSalvas) {
    var $o = $('#ch-tpl option:selected');
    if (!$o.length || !$o.val()) { $('#ch-tpl-vars').empty(); $('#ch-tpl-prev').hide(); return; }

    $('#ch-tpl-idioma').val($o.data('idioma') || 'pt_BR');

    var nVars = parseInt($o.data('vars'), 10) || 0;
    var html = '';
    for (var i = 1; i <= nVars; i++) {
      var val = usarSalvas && VARS_SALVAS[i - 1] ? VARS_SALVAS[i - 1] : '';
      html += '<div class="ch-campo"><label class="ch-label">Variável {{' + i + '}}</label>' +
              '<input type="text" class="ch-input ch-var" name="vars_body[]" data-i="' + i + '" ' +
              'value="' + esc(val) + '" placeholder="{{primeiro_nome}} ou texto fixo"></div>';
    }
    if (($o.data('header') === 'TEXT') && parseInt($o.data('varsheader'), 10) > 0) {
      html += '<div class="ch-campo"><label class="ch-label">Variável do cabeçalho</label>' +
              '<input type="text" class="ch-input" name="var_header"></div>';
    }
    if (parseInt($o.data('btnurl'), 10) > 0) {
      html += '<div class="ch-campo"><label class="ch-label">Complemento da URL do botão</label>' +
              '<input type="text" class="ch-input" name="var_botao"></div>';
    }
    if (nVars > 0) {
      html += '<div class="ch-ajuda" style="margin-top:-6px;">' +
              'Use {{primeiro_nome}}, {{nome}}, {{total_pedidos}} ou qualquer campo do contato.</div>';
    }
    $('#ch-tpl-vars').html(html);
    preview();
  }

  function preview() {
    var corpo = $('#ch-tpl option:selected').data('corpo') || '';
    if (!corpo) { $('#ch-tpl-prev').hide(); return; }
    $('.ch-var').each(function () {
      var i = $(this).data('i');
      var v = ($(this).val() || '').trim() || '{{' + i + '}}';
      corpo = corpo.split('{{' + i + '}}').join(v);
    });
    $('#ch-tpl-prev-txt').html(esc(corpo).replace(/\n/g, '<br>'));
    $('#ch-tpl-prev').show();
  }

  $('#ch-tpl').on('change', function () { montarVars(false); });
  $(document).on('input', '.ch-var', preview);

  // ── Estimativa de público ──────────────────────────────────────────────
  var tEst = null;
  function estimar() {
    clearTimeout(tEst);
    tEst = setTimeout(function () {
      var dados = { csrf_token: CSRF, tipo: $('#ch-tipo').val() };
      $('.ch-seg').each(function () {
        var nome = $(this).attr('name');
        var v = $(this).val();
        if (nome.indexOf('[]') > 0) {
          (v || []).forEach(function (x) {
            dados[nome] = dados[nome] || [];
            dados[nome].push(x);
          });
        } else {
          dados[nome] = v;
        }
      });

      $.post(BASE + '/admin/chat/campanhas/estimar', dados, function (r) {
        if (!r.ok) return;
        $('#ch-estimativa').text(new Intl.NumberFormat('pt-BR').format(r.total));
        $('#ch-estimativa-obs').text(
          $('#ch-tipo').val() === 'texto'
            ? 'só contatos com janela de 24h aberta'
            : 'contatos que aceitam receber'
        );
      }, 'json');
    }, 400);
  }
  $(document).on('change', '.ch-seg', estimar);

  // ── Salvar ─────────────────────────────────────────────────────────────
  $('#ch-form-camp').on('submit', function (e) {
    e.preventDefault();
    var $b = $(this).find('button[type=submit]').prop('disabled', true);
    $('#ch-camp-erro').text('');

    $.post(BASE + '/admin/chat/campanhas/salvar', $(this).serialize(), function (r) {
      if (r.ok) window.location.href = BASE + '/admin/chat/campanhas/' + r.id;
      else $('#ch-camp-erro').text(r.erro || 'Falha ao salvar.');
    }, 'json').fail(function () {
      $('#ch-camp-erro').text('Erro de rede.');
    }).always(function () { $b.prop('disabled', false); });
  });

  $('#ch-disparar').on('click', function () {
    if (!CAMP_ID) return;
    var pub = $('#ch-estimativa').text();
    if (!confirm('Disparar para aproximadamente ' + pub + ' contato(s)?\n\nIsso envia mensagens reais e gera custo na Meta.')) return;

    var $b = $(this).prop('disabled', true).text('Iniciando...');
    $.post(BASE + '/admin/chat/campanhas/' + CAMP_ID + '/iniciar', { csrf_token: CSRF }, function (r) {
      if (r.ok) window.location.href = BASE + '/admin/chat/campanhas/' + CAMP_ID;
      else { $('#ch-camp-erro').text(r.erro || 'Falha.'); $b.prop('disabled', false).text('Disparar agora'); }
    }, 'json');
  });

  // ── Início ─────────────────────────────────────────────────────────────
  alternarTipo();
  montarVars(true);
  estimar();
})(jQuery);
</script>
