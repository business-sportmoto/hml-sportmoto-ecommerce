<?php
/**
 * admin/views/chat/gatilhos.php
 * @var array $gatilhos @var array $fluxos @var array $tags
 */
$base = defined('BASE_URL') ? BASE_URL : '';
$h    = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$rotuloTipo = [
    'palavra_chave' => 'Palavra-chave',
    'boas_vindas'   => 'Primeira mensagem',
    'padrao'        => 'Resposta padrão',
    'referencia'    => 'Link com código',
    'midia'         => 'Recebeu mídia',
    'botao'         => 'Clique em botão',
];
$rotuloAcao = ['fluxo' => 'Inicia fluxo', 'mensagem' => 'Responde texto', 'tag' => 'Aplica tag', 'humano' => 'Chama atendente'];

$temBoasVindas = (bool)array_filter($gatilhos, fn($g) => $g['tipo'] === 'boas_vindas' && (int)$g['ativo']);
$temPadrao     = (bool)array_filter($gatilhos, fn($g) => $g['tipo'] === 'padrao' && (int)$g['ativo']);
?>

<div class="ch">

  <div class="ch-head">
    <div>
      <h1>Gatilhos</h1>
      <p>O que o bot faz quando alguém escreve. Avaliados de cima para baixo — o primeiro que casar vence.</p>
    </div>
    <div class="ch-head-acoes">
      <button type="button" class="ch-btn" id="ch-simular">Testar uma frase</button>
      <button type="button" class="ch-btn ch-btn--pri" id="ch-novo">Novo gatilho</button>
    </div>
  </div>

  <?php if (!$temBoasVindas || !$temPadrao): ?>
    <div class="ch-aviso ch-aviso--aviso">
      <div>
        <strong>Faltam gatilhos importantes</strong>
        <?php if (!$temBoasVindas): ?>
          Sem um gatilho de <strong>primeira mensagem</strong>, quem escreve pela primeira vez
          não recebe boas-vindas.
        <?php endif; ?>
        <?php if (!$temPadrao): ?>
          Sem uma <strong>resposta padrão</strong>, mensagens que não casam com nenhuma
          palavra-chave ficam sem resposta nenhuma.
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="ch-card">
    <div class="ch-card-head">
      <h2>Régua de atendimento</h2>
      <span class="ch-sm ch-mut">ordem de avaliação: referência → palavra-chave → primeira mensagem → padrão</span>
    </div>

    <?php if (!$gatilhos): ?>
      <div class="ch-vazio">
        <strong>Nenhum gatilho ainda</strong>
        <p style="max-width:50ch;margin:0 auto;">
          Sem gatilho, o bot fica em silêncio: as mensagens chegam no atendimento
          mas nada é respondido automaticamente.
        </p>
      </div>
    <?php else: ?>
    <div class="ch-tabela-wrap">
      <table class="ch-tabela">
        <thead>
          <tr>
            <th style="width:1%;">Ordem</th><th>Gatilho</th><th>Quando</th>
            <th>O que faz</th><th class="ch-num">Disparos</th><th style="width:1%;"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($gatilhos as $g): ?>
          <tr style="<?= (int)$g['ativo'] ? '' : 'opacity:.55;' ?>">
            <td class="ch-num ch-mono"><?= (int)$g['prioridade'] ?></td>
            <td>
              <span class="ch-b"><?= $h($g['nome']) ?></span>
              <?php if (!(int)$g['ativo']): ?>
                <span class="ch-badge ch-badge--neutro">inativo</span>
              <?php endif; ?>
              <?php if (!(int)$g['so_fora_fluxo']): ?>
                <div class="ch-sm ch-mut">interrompe fluxo em andamento</div>
              <?php endif; ?>
            </td>
            <td class="ch-sm">
              <div><?= $h($rotuloTipo[$g['tipo']] ?? $g['tipo']) ?></div>
              <?php if ($g['padrao']): ?>
                <div class="ch-mono ch-mut" style="margin-top:2px;">
                  <?= $h(mb_substr($g['padrao'], 0, 50)) ?>
                  <span class="ch-mut">(<?= $h($g['modo_match']) ?>)</span>
                </div>
              <?php endif; ?>
            </td>
            <td class="ch-sm">
              <?= $h($rotuloAcao[$g['acao']] ?? $g['acao']) ?>
              <?php if ($g['acao'] === 'fluxo' && $g['fluxo_nome']): ?>
                <div class="ch-mut">→ <?= $h($g['fluxo_nome']) ?></div>
              <?php elseif ($g['acao'] === 'fluxo'): ?>
                <div style="color:var(--danger)">fluxo removido</div>
              <?php elseif ($g['acao'] === 'tag' && $g['tag_nome']): ?>
                <div><span class="ch-tag" style="color:<?= $h($g['tag_cor']) ?>;background:<?= $h($g['tag_cor']) ?>22;"><?= $h($g['tag_nome']) ?></span></div>
              <?php elseif ($g['acao'] === 'mensagem'): ?>
                <div class="ch-mut"><?= $h(mb_substr((string)$g['mensagem'], 0, 46)) ?>…</div>
              <?php endif; ?>
            </td>
            <td class="ch-num">
              <?= number_format((int)$g['total_disparos'], 0, ',', '.') ?>
              <?php if ($g['ultimo_disparo_em']): ?>
                <div class="ch-sm ch-mut"><?= date('d/m H:i', strtotime((string)$g['ultimo_disparo_em'])) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <div class="ch-flex">
                <button type="button" class="ch-btn ch-btn--sm ch-editar"
                        data-json="<?= $h(json_encode($g, JSON_UNESCAPED_UNICODE)) ?>">Editar</button>
                <button type="button" class="ch-btn ch-btn--sm ch-toggle" data-id="<?= (int)$g['id'] ?>">
                  <?= (int)$g['ativo'] ? 'Desligar' : 'Ligar' ?>
                </button>
                <button type="button" class="ch-btn ch-btn--sm ch-btn--perigo ch-excluir" data-id="<?= (int)$g['id'] ?>">×</button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php // ── Modal do formulário ──────────────────────────────────────────── ?>
<div class="ch-modal" id="ch-modal-gat">
  <div class="ch-modal-cx">
    <div class="ch-modal-head">
      <h3 id="ch-gat-titulo">Novo gatilho</h3>
      <button type="button" class="ch-modal-x" data-fechar>&times;</button>
    </div>
    <div class="ch-modal-body">
      <form id="ch-form-gat">
        <input type="hidden" name="id" id="ch-g-id" value="">
        <input type="hidden" name="csrf_token" value="<?= $h($csrf_token ?? '') ?>">

        <div class="ch-campo">
          <label class="ch-label">Nome (só para você identificar)</label>
          <input type="text" class="ch-input" name="nome" id="ch-g-nome" required>
        </div>

        <div class="ch-campo">
          <label class="ch-label">Quando disparar</label>
          <select class="ch-select" name="tipo" id="ch-g-tipo">
            <option value="palavra_chave">Quando a mensagem contém uma palavra</option>
            <option value="boas_vindas">Na primeira mensagem que a pessoa manda</option>
            <option value="padrao">Quando nada mais casar (resposta padrão)</option>
            <option value="referencia">Quando vier de um link com código</option>
            <option value="midia">Quando receber foto, áudio ou arquivo</option>
          </select>
        </div>

        <div id="ch-g-padrao-box">
          <div class="ch-campo">
            <label class="ch-label" id="ch-g-padrao-lbl">Palavras (separadas por vírgula)</label>
            <input type="text" class="ch-input" name="padrao" id="ch-g-padrao" placeholder="oi, ola, menu, bom dia">
            <div class="ch-ajuda">Acentos e maiúsculas são ignorados: “Olá!” casa com “ola”.</div>
          </div>
          <div class="ch-campo" id="ch-g-modo-box">
            <label class="ch-label">Como comparar</label>
            <select class="ch-select" name="modo_match" id="ch-g-modo">
              <option value="contem">Contém a palavra em qualquer lugar</option>
              <option value="exato">A mensagem é exatamente a palavra</option>
              <option value="comeca">A mensagem começa com a palavra</option>
              <option value="regex">Expressão regular (avançado)</option>
            </select>
          </div>
        </div>

        <div class="ch-campo">
          <label class="ch-label">O que fazer</label>
          <select class="ch-select" name="acao" id="ch-g-acao">
            <option value="fluxo">Iniciar um fluxo</option>
            <option value="mensagem">Responder com um texto fixo</option>
            <option value="tag">Só aplicar uma tag</option>
            <option value="humano">Encaminhar para atendimento humano</option>
          </select>
        </div>

        <div class="ch-campo" id="ch-g-fluxo-box">
          <label class="ch-label">Fluxo</label>
          <select class="ch-select" name="fluxo_id" id="ch-g-fluxo">
            <option value="0">— selecione —</option>
            <?php foreach ($fluxos as $f): ?>
              <option value="<?= (int)$f['id'] ?>"><?= $h($f['nome']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (!$fluxos): ?>
            <div class="ch-ajuda" style="color:var(--warning);">
              Nenhum fluxo publicado. <a href="<?= $base ?>/admin/chat/fluxos">Crie e publique um</a> primeiro.
            </div>
          <?php endif; ?>
        </div>

        <div class="ch-campo" id="ch-g-msg-box" style="display:none;">
          <label class="ch-label">Mensagem de resposta</label>
          <textarea class="ch-textarea" name="mensagem" id="ch-g-msg" rows="3"></textarea>
          <div class="ch-ajuda">Aceita {{primeiro_nome}}, {{saudacao}} e campos do contato.</div>
        </div>

        <div class="ch-campo" id="ch-g-tag-box" style="display:none;">
          <label class="ch-label">Tag</label>
          <select class="ch-select" name="tag_id" id="ch-g-tag">
            <option value="0">— selecione —</option>
            <?php foreach ($tags as $t): ?>
              <option value="<?= (int)$t['id'] ?>"><?= $h($t['nome']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="ch-grid-2">
          <div class="ch-campo">
            <label class="ch-label">Prioridade</label>
            <input type="number" class="ch-input" name="prioridade" id="ch-g-prio" value="50" min="0" max="999">
            <div class="ch-ajuda">Número menor é avaliado primeiro.</div>
          </div>
        </div>

        <div class="ch-campo">
          <label class="ch-check">
            <input type="checkbox" name="so_fora_fluxo" id="ch-g-sff" value="1" checked>
            <span>
              <strong>Não interromper conversa em andamento</strong>
              <div class="ch-ajuda">
                Recomendado. Desmarcado, este gatilho corta um fluxo no meio — útil só
                para atalhos como “falar com atendente”.
              </div>
            </span>
          </label>
        </div>

        <div class="ch-campo">
          <label class="ch-check">
            <input type="checkbox" name="ativo" id="ch-g-ativo" value="1" checked>
            <span><strong>Ativo</strong></span>
          </label>
        </div>

        <div id="ch-g-erro" class="ch-sm" style="color:var(--danger);"></div>
      </form>
    </div>
    <div class="ch-modal-pe">
      <button type="button" class="ch-btn" data-fechar>Cancelar</button>
      <button type="button" class="ch-btn ch-btn--pri" id="ch-g-salvar">Salvar</button>
    </div>
  </div>
</div>

<?php // ── Modal do simulador ───────────────────────────────────────────── ?>
<div class="ch-modal" id="ch-modal-sim">
  <div class="ch-modal-cx" style="max-width:460px;">
    <div class="ch-modal-head">
      <h3>Testar uma frase</h3>
      <button type="button" class="ch-modal-x" data-fechar>&times;</button>
    </div>
    <div class="ch-modal-body">
      <div class="ch-campo">
        <label class="ch-label">O que o cliente escreveria</label>
        <input type="text" class="ch-input" id="ch-sim-txt" placeholder="oi, quero ver o menu">
      </div>
      <div class="ch-campo">
        <label class="ch-check">
          <input type="checkbox" id="ch-sim-primeira">
          <span>É a primeira mensagem dessa pessoa</span>
        </label>
      </div>
      <div id="ch-sim-res"></div>
    </div>
    <div class="ch-modal-pe">
      <button type="button" class="ch-btn" data-fechar>Fechar</button>
      <button type="button" class="ch-btn ch-btn--pri" id="ch-sim-run">Testar</button>
    </div>
  </div>
</div>

<script>
(function ($) {
  var BASE = window.BASE_URL || '<?= $base ?>';
  var CSRF = '<?= $h($csrf_token ?? '') ?>';

  function esc(s) { return $('<i>').text(s == null ? '' : String(s)).html(); }
  $(document).on('click', '[data-fechar]', function () { $(this).closest('.ch-modal').removeClass('aberto'); });
  $(document).on('click', '.ch-modal', function (e) { if (e.target === this) $(this).removeClass('aberto'); });

  function ajustarCampos() {
    var tipo = $('#ch-g-tipo').val();
    var acao = $('#ch-g-acao').val();

    var precisaPadrao = (tipo === 'palavra_chave' || tipo === 'referencia');
    $('#ch-g-padrao-box').toggle(precisaPadrao);
    $('#ch-g-modo-box').toggle(tipo === 'palavra_chave');
    $('#ch-g-padrao-lbl').text(tipo === 'referencia' ? 'Código de referência' : 'Palavras (separadas por vírgula)');

    $('#ch-g-fluxo-box').toggle(acao === 'fluxo');
    $('#ch-g-msg-box').toggle(acao === 'mensagem' || acao === 'humano');
    $('#ch-g-tag-box').toggle(acao === 'tag');
  }
  $('#ch-g-tipo, #ch-g-acao').on('change', ajustarCampos);

  function abrirForm(dados) {
    $('#ch-g-erro').text('');
    $('#ch-gat-titulo').text(dados ? 'Editar gatilho' : 'Novo gatilho');
    $('#ch-g-id').val(dados ? dados.id : '');
    $('#ch-g-nome').val(dados ? dados.nome : '');
    $('#ch-g-tipo').val(dados ? dados.tipo : 'palavra_chave');
    $('#ch-g-padrao').val(dados ? (dados.padrao || '') : '');
    $('#ch-g-modo').val(dados ? dados.modo_match : 'contem');
    $('#ch-g-acao').val(dados ? dados.acao : 'fluxo');
    $('#ch-g-fluxo').val(dados ? (dados.fluxo_id || 0) : 0);
    $('#ch-g-msg').val(dados ? (dados.mensagem || '') : '');
    $('#ch-g-tag').val(dados ? (dados.tag_id || 0) : 0);
    $('#ch-g-prio').val(dados ? dados.prioridade : 50);
    $('#ch-g-sff').prop('checked', dados ? dados.so_fora_fluxo == 1 : true);
    $('#ch-g-ativo').prop('checked', dados ? dados.ativo == 1 : true);
    ajustarCampos();
    $('#ch-modal-gat').addClass('aberto');
  }

  $('#ch-novo').on('click', function () { abrirForm(null); });
  $('.ch-editar').on('click', function () { abrirForm($(this).data('json')); });

  $('#ch-g-salvar').on('click', function () {
    var $b = $(this).prop('disabled', true);
    $.post(BASE + '/admin/chat/gatilhos/salvar', $('#ch-form-gat').serialize(), function (r) {
      if (r.ok) location.reload();
      else $('#ch-g-erro').text(r.erro || 'Falha ao salvar.');
    }, 'json').fail(function () {
      $('#ch-g-erro').text('Erro de rede.');
    }).always(function () { $b.prop('disabled', false); });
  });

  $('.ch-toggle').on('click', function () {
    $.post(BASE + '/admin/chat/gatilhos/' + $(this).data('id') + '/ativo',
      { csrf_token: CSRF }, function () { location.reload(); }, 'json');
  });

  $('.ch-excluir').on('click', function () {
    if (!confirm('Excluir este gatilho?')) return;
    $.post(BASE + '/admin/chat/gatilhos/' + $(this).data('id') + '/excluir',
      { csrf_token: CSRF }, function () { location.reload(); }, 'json');
  });

  // Simulador
  $('#ch-simular').on('click', function () {
    $('#ch-sim-res').empty();
    $('#ch-modal-sim').addClass('aberto');
  });

  $('#ch-sim-run').on('click', function () {
    var txt = ($('#ch-sim-txt').val() || '').trim();
    if (!txt) return;
    $.post(BASE + '/admin/chat/gatilhos/simular', {
      csrf_token: CSRF, texto: txt,
      primeira_mensagem: $('#ch-sim-primeira').is(':checked') ? 1 : 0
    }, function (r) {
      if (!r.ok) { $('#ch-sim-res').html('<div class="ch-aviso ch-aviso--erro" style="margin:0;"><div>' + esc(r.erro) + '</div></div>'); return; }
      var d = r.resultado;
      if (d.acao === 'nenhuma') {
        $('#ch-sim-res').html('<div class="ch-aviso ch-aviso--aviso" style="margin:0;"><div>' +
          '<strong>Ninguém responderia</strong>Nenhum gatilho casa com essa frase. ' +
          'Considere criar uma resposta padrão.</div></div>');
      } else {
        $('#ch-sim-res').html('<div class="ch-aviso ch-aviso--ok" style="margin:0;"><div>' +
          '<strong>' + esc(d.gatilho ? d.gatilho.nome : 'Opt-out') + '</strong>' +
          'Motivo: ' + esc(d.motivo) + ' · Ação: <strong>' + esc(d.acao) + '</strong></div></div>');
      }
    }, 'json');
  });

  $('#ch-sim-txt').on('keydown', function (e) { if (e.key === 'Enter') $('#ch-sim-run').click(); });
})(jQuery);
</script>
