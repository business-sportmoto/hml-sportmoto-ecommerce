<?php
// views/admin/carrinhos-abandonados/templates/form.php
// Variáveis: $template (null = novo), $erro (string|null)
//
// Duas colunas: à esquerda o que se escreve, à direita o que o cliente recebe.
// A prévia é client-side e usa dados fictícios CONSTANTES — nenhum input
// externo entra nela.

$editando = !empty($template['id']);
$canal    = $template['canal'] ?? 'whatsapp';
$vars     = CarrinhoAbandonado::VARIAVEIS;
$varsMail = CarrinhoAbandonado::VARIAVEIS_EMAIL;
$presets  = CarrinhoAbandonado::PRESETS;
?>
<div class="ap-page-header" style="display:flex;align-items:center;gap:14px;">
  <a href="<?= ADMIN_URL ?>/carrinhos-abandonados/templates" class="btn">← Voltar</a>
  <h1 style="font-size:20px;font-weight:800;margin:0;">
    <?= $editando ? 'Editar template' : 'Novo template' ?>
  </h1>
  <?php if ($editando): ?>
    <span class="badge" style="background:<?= $canal === 'whatsapp' ? 'var(--success-lt)' : 'var(--blue-lt)' ?>;
          color:<?= $canal === 'whatsapp' ? 'var(--success)' : 'var(--blue)' ?>;
          font-size:11.5px;font-weight:800;padding:5px 12px;">
      <?= $canal === 'whatsapp' ? '💬 WhatsApp' : '✉ E-mail' ?>
    </span>
  <?php endif; ?>
</div>

<?php if (!empty($erro)): ?>
<div style="background:var(--danger-lt);border:1px solid var(--danger-bd);border-radius:10px;
     padding:12px 16px;margin:14px 0;font-size:13.5px;color:var(--danger);">
  ⚠ <?= View::e($erro) ?>
</div>
<?php endif; ?>

<style>
  .tpl-secao      { font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;
                    color:var(--c-text-muted);margin:0 0 4px; }
  .tpl-secao-sub  { font-size:12.5px;color:var(--c-text-muted);margin:0 0 14px; }
  .tpl-preset     { border:1px solid var(--c-border);background:var(--bg);border-radius:8px;
                    padding:8px 13px;font-size:12.5px;font-weight:600;cursor:pointer;
                    display:inline-flex;align-items:center;gap:7px;text-align:left; }
  .tpl-preset:hover      { border-color:var(--c-primary);color:var(--c-primary); }
  .tpl-preset[disabled]  { opacity:.45;cursor:not-allowed; }
  .var-chip       { border:1px solid var(--c-border);background:var(--bg);border-radius:99px;
                    padding:4px 12px;font-size:12px;font-weight:600;cursor:pointer;
                    font-family:ui-monospace,monospace; }
  .var-chip:hover { border-color:var(--c-primary);color:var(--c-primary); }
  /* Moldura de celular — o mesmo enquadramento que a pessoa vê no aparelho */
  .tpl-fone       { width:300px;margin:0 auto;background:#111b21;border-radius:26px;
                    padding:9px;box-shadow:0 8px 28px rgba(15,23,42,.22); }
  .tpl-fone-tela  { background:#0b141a;border-radius:19px;overflow:hidden; }
  .tpl-fone-topo  { background:#1f2c33;color:#e9edef;padding:9px 13px;display:flex;
                    align-items:center;gap:9px;font-size:12.5px;font-weight:600; }
  .tpl-fone-av    { width:26px;height:26px;border-radius:50%;background:#6a7175;
                    display:flex;align-items:center;justify-content:center;font-size:12px; }
  .tpl-fone-corpo { padding:14px 11px;min-height:230px;
                    background:#0b141a url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='40' height='40'%3E%3Ccircle cx='20' cy='20' r='1' fill='%23223'/%3E%3C/svg%3E"); }
  .tpl-bolha      { background:#005c4b;color:#e9edef;border-radius:9px 9px 2px 9px;
                    padding:8px 10px;font-size:13px;line-height:1.45;white-space:pre-wrap;
                    word-break:break-word;max-width:96%;margin-left:auto; }
  .tpl-bolha-hora { font-size:10px;color:#8696a0;text-align:right;margin-top:3px; }
  .tpl-aviso      { background:var(--warning-lt,#fef3c7);border:1px solid var(--warning-bd,#fde68a);
                    color:var(--warning,#92400e);border-radius:7px;padding:6px 11px;
                    font-size:11.5px;font-weight:600;display:none; }
</style>

<form method="post" id="form-tpl"
      action="<?= ADMIN_URL ?>/carrinhos-abandonados/templates<?= $editando ? '/' . (int)$template['id'] : '/novo' ?>">
  <?= SecurityHelper::csrfField() ?>

  <div style="display:grid;grid-template-columns:1fr 380px;gap:16px;align-items:start;margin-top:14px;">

    <!-- ══════════ COLUNA ESQUERDA ══════════ -->
    <div style="display:flex;flex-direction:column;gap:16px;">

      <!-- ── Criação: pontos de partida ── -->
      <div class="admin-card" style="padding:18px;">
        <p class="tpl-secao">Criação</p>
        <p class="tpl-secao-sub">
          Um ponto de partida para não começar da página em branco.
          <?= $editando ? 'Aplicar um preset <strong>substitui</strong> o que está escrito.' : '' ?>
        </p>
        <div style="display:flex;flex-wrap:wrap;gap:8px;" id="lista-presets">
          <?php foreach (['whatsapp', 'email'] as $cn): ?>
            <?php foreach ($presets[$cn] as $chave => $p): ?>
            <button type="button" class="tpl-preset preset-btn"
                    data-canal="<?= $cn ?>" data-preset="<?= View::e($chave) ?>"
                    style="<?= $cn !== $canal ? 'display:none;' : '' ?>">
              ✨ <?= View::e($p['rotulo']) ?>
            </button>
            <?php endforeach; ?>
          <?php endforeach; ?>
          <button type="button" class="tpl-preset" id="btn-branco">📄 Em branco</button>
        </div>
      </div>

      <!-- ── Configuração ── -->
      <div class="admin-card" style="padding:18px;display:grid;gap:16px;">
        <div>
          <p class="tpl-secao">Configuração</p>
          <p class="tpl-secao-sub" style="margin-bottom:0;">Como a equipe encontra e usa este template.</p>
        </div>

        <div class="form-group">
          <label class="form-label">Nome interno *</label>
          <input type="text" name="nome" class="form-control" maxlength="80" required
                 placeholder="Ex: Primeira abordagem amigável"
                 value="<?= View::e($template['nome'] ?? '') ?>">
          <span class="form-hint">Visível apenas para a equipe.</span>
        </div>

        <!-- Canal: escolha na criação, travado na edição -->
        <div class="form-group">
          <label class="form-label">Canal *</label>
          <?php if ($editando): ?>
            <input type="hidden" name="canal" value="<?= View::e($canal) ?>">
            <span class="form-hint">
              O canal não pode ser alterado após a criação — o conteúdo de e-mail é HTML
              e o de WhatsApp é texto puro. Crie um novo template para o outro canal.
            </span>
          <?php else: ?>
            <div style="display:flex;gap:10px;">
              <label class="canal-card" data-canal="whatsapp"
                     style="flex:1;border:2px solid <?= $canal === 'whatsapp' ? 'var(--success)' : 'var(--c-border)' ?>;
                            border-radius:10px;padding:14px;cursor:pointer;text-align:center;">
                <input type="radio" name="canal" value="whatsapp" style="display:none;"
                       <?= $canal === 'whatsapp' ? 'checked' : '' ?>>
                <div style="font-size:20px;">💬</div>
                <div style="font-weight:700;font-size:13px;">WhatsApp</div>
                <div style="font-size:11px;color:var(--c-text-muted);">Texto puro</div>
              </label>
              <label class="canal-card" data-canal="email"
                     style="flex:1;border:2px solid <?= $canal === 'email' ? 'var(--blue)' : 'var(--c-border)' ?>;
                            border-radius:10px;padding:14px;cursor:pointer;text-align:center;">
                <input type="radio" name="canal" value="email" style="display:none;"
                       <?= $canal === 'email' ? 'checked' : '' ?>>
                <div style="font-size:20px;">✉</div>
                <div style="font-weight:700;font-size:13px;">E-mail</div>
                <div style="font-size:11px;color:var(--c-text-muted);">HTML · Mailgun</div>
              </label>
            </div>
          <?php endif; ?>
        </div>

        <div class="form-group">
          <label class="form-label">Melhor momento de uso</label>
          <input type="text" name="uso_recomendado" class="form-control" maxlength="150"
                 placeholder="Ex: Primeiro contato, até 24h após o abandono"
                 value="<?= View::e($template['uso_recomendado'] ?? '') ?>">
          <span class="form-hint">Aparece na listagem e ajuda quem escolhe na hora do envio.</span>
        </div>

        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:13.5px;">
          <input type="checkbox" name="ativo" value="1"
                 <?= !isset($template['ativo']) || (int)($template['ativo'] ?? 1) ? 'checked' : '' ?>>
          Template ativo (disponível para a equipe e para os fluxos)
        </label>
      </div>

      <!-- ── Mensagem ── -->
      <div class="admin-card" style="padding:18px;display:grid;gap:16px;">
        <div>
          <p class="tpl-secao">Mensagem</p>
          <p class="tpl-secao-sub" style="margin-bottom:0;">O que o cliente vê.</p>
        </div>

        <div class="form-group" id="grupo-assunto"
             style="<?= $canal !== 'email' ? 'display:none;' : '' ?>">
          <label class="form-label">Assunto do e-mail *</label>
          <input type="text" name="assunto" class="form-control" maxlength="150"
                 placeholder="Ex: {primeiro_nome}, seu carrinho está salvo"
                 value="<?= View::e($template['assunto'] ?? '') ?>">
          <span class="form-hint">Aceita as mesmas variáveis do conteúdo.</span>
        </div>

        <div class="form-group">
          <label class="form-label">Variáveis — clique para inserir no cursor</label>
          <div id="chips-vars" style="display:flex;flex-wrap:wrap;gap:6px;">
            <?php foreach ($vars as $v => $desc): ?>
            <button type="button" class="var-chip" data-var="<?= View::e($v) ?>"
                    title="<?= View::e($desc) ?>"><?= View::e($v) ?></button>
            <?php endforeach; ?>
            <?php foreach ($varsMail as $v => $desc): ?>
            <button type="button" class="var-chip var-chip-email" data-var="<?= View::e($v) ?>"
                    title="<?= View::e($desc) ?>"
                    style="border-color:#bfdbfe;background:#eff6ff;color:#1d4ed8;
                           <?= $canal !== 'email' ? 'display:none;' : '' ?>"><?= View::e($v) ?></button>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">
            Corpo da mensagem *
            <span id="cont-chars" style="float:right;font-weight:400;font-size:11.5px;
                  color:var(--c-text-muted);">0 / 10.000</span>
          </label>
          <textarea name="conteudo" id="inp-conteudo" class="form-control" rows="12"
                    maxlength="10000" required
                    style="font-family:ui-monospace,monospace;font-size:13px;line-height:1.55;"
            ><?= View::e($template['conteudo'] ?? '') ?></textarea>
          <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:6px;">
            <span class="form-hint" id="hint-conteudo" style="margin:0;">
              <?= $canal === 'email'
                  ? 'HTML permitido (&lt;p&gt;, &lt;a&gt;, &lt;strong&gt;…). A variável {link} é obrigatória.'
                  : 'Texto puro — quebras de linha são preservadas no WhatsApp.' ?>
            </span>
            <span class="tpl-aviso" id="aviso-corte">⚠ Pode ser cortada depois de ~5 linhas</span>
          </div>
        </div>
      </div>

      <div style="display:flex;gap:10px;">
        <button type="submit" class="btn btn-primary" style="min-width:160px;">
          <?= $editando ? 'Salvar alterações' : 'Criar template' ?></button>
        <a href="<?= ADMIN_URL ?>/carrinhos-abandonados/templates" class="btn">Cancelar</a>
      </div>
    </div>

    <!-- ══════════ COLUNA DIREITA: como o cliente recebe ══════════ -->
    <div class="admin-card" style="position:sticky;top:16px;">
      <h3 class="ap-card-title">Como o cliente recebe
        <span style="float:right;font-size:11px;font-weight:400;color:var(--c-text-muted);">
          dados fictícios</span>
      </h3>
      <div style="padding:16px 14px;">

        <!-- WhatsApp: moldura de celular. Texto entra por .text() = escape total. -->
        <div id="preview-wpp" style="<?= $canal !== 'whatsapp' ? 'display:none;' : '' ?>">
          <div class="tpl-fone">
            <div class="tpl-fone-tela">
              <div class="tpl-fone-topo">
                <span style="color:#8696a0;">‹</span>
                <span class="tpl-fone-av">🏍️</span>
                <span>Sportmoto</span>
              </div>
              <div class="tpl-fone-corpo">
                <div class="tpl-bolha" id="preview-wpp-texto"></div>
                <div class="tpl-bolha-hora"><?= date('H:i') ?> ✓✓</div>
              </div>
            </div>
          </div>
        </div>

        <!-- E-mail: iframe SANDBOX (sem scripts, forms ou navegação) -->
        <div id="preview-mail" style="<?= $canal !== 'email' ? 'display:none;' : '' ?>">
          <div style="border:1px solid var(--c-border);border-radius:8px 8px 0 0;
                      padding:8px 12px;background:var(--bg);font-size:12px;">
            <strong>Assunto:</strong> <span id="preview-mail-assunto"></span>
          </div>
          <iframe id="preview-mail-frame" sandbox
                  style="width:100%;height:420px;border:1px solid var(--c-border);
                         border-top:none;border-radius:0 0 8px 8px;background:var(--surface);"></iframe>
        </div>
      </div>
    </div>
  </div>
</form>

<script>
jQuery(function ($) {
  // Presets vêm do servidor (CarrinhoAbandonado::PRESETS) — fonte única.
  var PRESETS = <?= json_encode($presets, JSON_UNESCAPED_UNICODE) ?>;

  // Dados fictícios CONSTANTES — nenhum input externo entra na prévia.
  var DADOS = {
    '{nome}': 'João da Silva',
    '{primeiro_nome}': 'João',
    '{loja}': 'Sportmoto',
    '{valor}': 'R$ 489,90',
    '{produtos}': 'Capacete Axxis Draken, Luva X11 Fit',
    '{link}': 'https://sportmoto.com.br/carrinho/recuperar/a1b2c3d4…',
    '{vendedor}': 'Equipe Sportmoto',
    '{telefone_loja}': '(41) 3333-0000',
    '{produtos_html}': '<table style="width:100%;border-collapse:collapse;margin:16px 0;">' +
      '<tr><td style="padding:10px 0;border-bottom:1px solid #e2e8f0;">Capacete Axxis Draken ' +
      '<span style="color:#64748b;">×1</span></td>' +
      '<td style="padding:10px 0;border-bottom:1px solid #e2e8f0;text-align:right;font-weight:700;">R$ 389,90</td></tr>' +
      '<tr><td style="padding:10px 0;">Luva X11 Fit <span style="color:#64748b;">×1</span></td>' +
      '<td style="padding:10px 0;text-align:right;font-weight:700;">R$ 100,00</td></tr></table>'
  };

  function canalAtual() {
    return $('input[name="canal"]:checked').val() ||
           $('input[name="canal"][type="hidden"]').val() || 'whatsapp';
  }

  function substituir(texto) {
    Object.keys(DADOS).forEach(function (k) {
      texto = texto.split(k).join(DADOS[k]);
    });
    return texto;
  }

  function atualizarPreview() {
    var canal = canalAtual();
    var bruto = $('#inp-conteudo').val();
    var corpo = substituir(bruto);

    if (canal === 'whatsapp') {
      // .text() = escape total; white-space:pre-wrap preserva as quebras
      $('#preview-wpp-texto').text(corpo);
      // O WhatsApp corta com "Ler mais" por volta da 5ª linha visível
      $('#aviso-corte').toggle(bruto.split('\n').length > 5);
    } else {
      $('#preview-mail-assunto').text(substituir($('input[name="assunto"]').val() || '(sem assunto)'));
      // HTML do template é trusted-admin e os dados são constantes; o sandbox
      // é a segunda camada, bloqueando script e navegação.
      document.getElementById('preview-mail-frame').srcdoc =
        '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f1f5f9;' +
        'font-family:Arial,sans-serif;color:#1e293b;">' +
        '<div style="max-width:520px;margin:16px auto;background:#fff;' +
        'border-radius:12px;padding:28px;">' + corpo + '</div></body></html>';
      $('#aviso-corte').hide();
    }
    $('#cont-chars').text(bruto.length.toLocaleString('pt-BR') + ' / 10.000');
  }

  function aplicarCanal(canal) {
    $('#grupo-assunto').toggle(canal === 'email');
    $('#preview-wpp').toggle(canal === 'whatsapp');
    $('#preview-mail').toggle(canal === 'email');
    $('.var-chip-email').toggle(canal === 'email');
    $('.preset-btn').each(function () {
      $(this).toggle($(this).data('canal') === canal);
    });
    $('#hint-conteudo').html(canal === 'email'
      ? 'HTML permitido (&lt;p&gt;, &lt;a&gt;, &lt;strong&gt;…). A variável {link} é obrigatória.'
      : 'Texto puro — quebras de linha são preservadas no WhatsApp.');
    atualizarPreview();
  }

  /* Troca de canal (só na criação) */
  $('.canal-card').on('click', function () {
    var canal = $(this).data('canal');
    $(this).find('input').prop('checked', true);
    $('.canal-card').css('border-color', 'var(--c-border)');
    $(this).css('border-color', canal === 'whatsapp' ? 'var(--success)' : 'var(--blue)');
    aplicarCanal(canal);
  });

  /* Preset: substitui o corpo. Confirma quando há texto para não apagar
     por engano o que alguém acabou de escrever. */
  $('.preset-btn').on('click', function () {
    var canal = $(this).data('canal');
    var p     = (PRESETS[canal] || {})[$(this).data('preset')];
    if (!p) return;
    if ($.trim($('#inp-conteudo').val()) !== '' &&
        !confirm('Isso substitui o texto atual. Continuar?')) return;

    $('#inp-conteudo').val(p.conteudo);
    if (p.assunto) $('input[name="assunto"]').val(p.assunto);
    if (p.uso && $.trim($('input[name="uso_recomendado"]').val()) === '') {
      $('input[name="uso_recomendado"]').val(p.uso);
    }
    atualizarPreview();
  });

  $('#btn-branco').on('click', function () {
    if ($.trim($('#inp-conteudo').val()) !== '' &&
        !confirm('Isso apaga o texto atual. Continuar?')) return;
    $('#inp-conteudo').val('');
    atualizarPreview();
  });

  /* Chips: insere a variável na posição do cursor */
  $('.var-chip').on('click', function () {
    var ta = document.getElementById('inp-conteudo');
    var v  = $(this).data('var');
    var s  = ta.selectionStart, e = ta.selectionEnd;
    ta.value = ta.value.substring(0, s) + v + ta.value.substring(e);
    ta.selectionStart = ta.selectionEnd = s + v.length;
    ta.focus();
    atualizarPreview();
  });

  /* Validação client (o servidor revalida tudo) */
  $('#form-tpl').on('submit', function (e) {
    if (canalAtual() === 'email' &&
        $('#inp-conteudo').val().indexOf('{link}') === -1) {
      e.preventDefault();
      alert('Templates de e-mail devem conter a variável {link}.');
    }
  });

  $('#inp-conteudo, input[name="assunto"]').on('input', atualizarPreview);
  aplicarCanal(canalAtual());
});
</script>
