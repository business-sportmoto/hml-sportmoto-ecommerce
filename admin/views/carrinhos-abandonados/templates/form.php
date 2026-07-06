<?php
// views/admin/carrinhos-abandonados/templates/form.php
// Variáveis: $template (null = novo), $erro (string|null)

$editando = !empty($template['id']);
$canal    = $template['canal'] ?? 'whatsapp';
$vars     = CarrinhoAbandonado::VARIAVEIS;
$varsMail = CarrinhoAbandonado::VARIAVEIS_EMAIL;
?>
<div class="ap-page-header" style="display:flex;align-items:center;gap:14px;">
  <a href="<?= ADMIN_URL ?>/carrinhos-abandonados/templates" class="btn">← Voltar</a>
  <h1 style="font-size:20px;font-weight:800;margin:0;">
    <?= $editando ? 'Editar template' : 'Novo template' ?>
  </h1>
</div>

<?php if (!empty($erro)): ?>
<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;
     padding:12px 16px;margin:14px 0;font-size:13.5px;color:#dc2626;">
  ⚠ <?= View::e($erro) ?>
</div>
<?php endif; ?>

<form method="post" id="form-tpl"
      action="<?= ADMIN_URL ?>/carrinhos-abandonados/templates<?= $editando ? '/' . (int)$template['id'] : '/novo' ?>">
  <?= SecurityHelper::csrfField() ?>

  <div style="display:grid;grid-template-columns:1fr 380px;gap:16px;align-items:start;margin-top:14px;">

    <!-- COLUNA ESQUERDA: formulário -->
    <div style="display:flex;flex-direction:column;gap:16px;">

      <div class="admin-card" style="padding:18px;display:grid;gap:16px;">

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
            <span class="badge" style="background:<?= $canal === 'whatsapp' ? '#f0fdf4' : '#eff6ff' ?>;
                  color:<?= $canal === 'whatsapp' ? '#16a34a' : '#1d4ed8' ?>;
                  font-size:12px;font-weight:800;padding:6px 14px;">
              <?= $canal === 'whatsapp' ? '💬 WhatsApp' : '✉ E-mail' ?> · travado
            </span>
            <span class="form-hint">O canal não pode ser alterado após a criação —
              crie um novo template se precisar do outro canal.</span>
          <?php else: ?>
            <div style="display:flex;gap:10px;">
              <label class="canal-card" data-canal="whatsapp"
                     style="flex:1;border:2px solid <?= $canal === 'whatsapp' ? '#16a34a' : 'var(--c-border)' ?>;
                            border-radius:10px;padding:14px;cursor:pointer;text-align:center;">
                <input type="radio" name="canal" value="whatsapp" style="display:none;"
                       <?= $canal === 'whatsapp' ? 'checked' : '' ?>>
                <div style="font-size:20px;">💬</div>
                <div style="font-weight:700;font-size:13px;">WhatsApp</div>
                <div style="font-size:11px;color:var(--c-text-muted);">Texto puro · wa.me</div>
              </label>
              <label class="canal-card" data-canal="email"
                     style="flex:1;border:2px solid <?= $canal === 'email' ? '#1d4ed8' : 'var(--c-border)' ?>;
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

        <div class="form-group" id="grupo-assunto"
             style="<?= $canal !== 'email' ? 'display:none;' : '' ?>">
          <label class="form-label">Assunto do e-mail *</label>
          <input type="text" name="assunto" class="form-control" maxlength="150"
                 placeholder="Ex: {primeiro_nome}, seu carrinho está salvo"
                 value="<?= View::e($template['assunto'] ?? '') ?>">
          <span class="form-hint">Aceita as mesmas variáveis do conteúdo.</span>
        </div>

        <div class="form-group">
          <label class="form-label">
            Conteúdo *
            <span id="cont-chars" style="float:right;font-weight:400;font-size:11.5px;
                  color:var(--c-text-muted);">0 / 10.000</span>
          </label>
          <textarea name="conteudo" id="inp-conteudo" class="form-control" rows="10"
                    maxlength="10000" required
                    style="font-family:ui-monospace,monospace;font-size:13px;line-height:1.5;"
            ><?= View::e($template['conteudo'] ?? '') ?></textarea>
          <span class="form-hint" id="hint-conteudo">
            <?= $canal === 'email'
                ? 'HTML permitido (<p>, <a>, <strong>…). A variável {link} é obrigatória.'
                : 'Texto puro — quebras de linha são preservadas no WhatsApp.' ?>
          </span>
        </div>

        <!-- Variáveis clicáveis -->
        <div class="form-group">
          <label class="form-label">Variáveis — clique para inserir no cursor</label>
          <div id="chips-vars" style="display:flex;flex-wrap:wrap;gap:6px;">
            <?php foreach ($vars as $v => $desc): ?>
            <button type="button" class="var-chip" data-var="<?= View::e($v) ?>"
                    title="<?= View::e($desc) ?>"
                    style="border:1px solid var(--c-border);background:#f8fafc;border-radius:99px;
                           padding:4px 12px;font-size:12px;font-weight:600;cursor:pointer;
                           font-family:ui-monospace,monospace;"><?= View::e($v) ?></button>
            <?php endforeach; ?>
            <?php foreach ($varsMail as $v => $desc): ?>
            <button type="button" class="var-chip var-chip-email" data-var="<?= View::e($v) ?>"
                    title="<?= View::e($desc) ?>"
                    style="border:1px solid #bfdbfe;background:#eff6ff;color:#1d4ed8;
                           border-radius:99px;padding:4px 12px;font-size:12px;font-weight:600;
                           cursor:pointer;font-family:ui-monospace,monospace;
                           <?= $canal !== 'email' ? 'display:none;' : '' ?>"><?= View::e($v) ?></button>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Melhor momento de uso</label>
          <input type="text" name="uso_recomendado" class="form-control" maxlength="150"
                 placeholder="Ex: Primeiro contato, até 24h após o abandono"
                 value="<?= View::e($template['uso_recomendado'] ?? '') ?>">
        </div>

        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:13.5px;">
          <input type="checkbox" name="ativo" value="1"
                 <?= !isset($template['ativo']) || (int)($template['ativo'] ?? 1) ? 'checked' : '' ?>>
          Template ativo (disponível para a equipe)
        </label>
      </div>

      <div style="display:flex;gap:10px;">
        <button type="submit" class="btn btn-primary" style="min-width:160px;">
          <?= $editando ? 'Salvar alterações' : 'Criar template' ?></button>
        <a href="<?= ADMIN_URL ?>/carrinhos-abandonados/templates" class="btn">Cancelar</a>
      </div>
    </div>

    <!-- COLUNA DIREITA: preview ao vivo -->
    <div class="admin-card" style="position:sticky;top:16px;">
      <h3 class="ap-card-title">Pré-visualização
        <span style="float:right;font-size:11px;font-weight:400;color:var(--c-text-muted);">
          dados fictícios</span>
      </h3>
      <div style="padding:16px 18px;">

        <!-- Preview WhatsApp: bolha, texto ESCAPADO via .text() -->
        <div id="preview-wpp" style="<?= $canal !== 'whatsapp' ? 'display:none;' : '' ?>">
          <div style="background:#e7fed8;border-radius:12px 12px 12px 2px;padding:12px 14px;
                      font-size:13.5px;line-height:1.5;white-space:pre-wrap;word-break:break-word;
                      box-shadow:0 1px 2px rgba(0,0,0,.08);" id="preview-wpp-texto"></div>
          <div style="font-size:10.5px;color:var(--c-text-muted);text-align:right;margin-top:4px;">
            <?= date('H:i') ?> ✓✓</div>
        </div>

        <!-- Preview e-mail: iframe SANDBOX (sem scripts/forms/navegação) -->
        <div id="preview-mail" style="<?= $canal !== 'email' ? 'display:none;' : '' ?>">
          <div style="border:1px solid var(--c-border);border-radius:8px 8px 0 0;
                      padding:8px 12px;background:#f8fafc;font-size:12px;">
            <strong>Assunto:</strong> <span id="preview-mail-assunto"></span>
          </div>
          <iframe id="preview-mail-frame" sandbox
                  style="width:100%;height:420px;border:1px solid var(--c-border);
                         border-top:none;border-radius:0 0 8px 8px;background:#fff;"></iframe>
        </div>
      </div>
    </div>
  </div>
</form>

<script>
jQuery(function ($) {
  // Dados fictícios CONSTANTES — sem input externo no preview
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
    var corpo = substituir($('#inp-conteudo').val());

    if (canal === 'whatsapp') {
      // .text() = escape total; white-space:pre-wrap preserva quebras
      $('#preview-wpp-texto').text(corpo);
    } else {
      $('#preview-mail-assunto').text(substituir($('input[name="assunto"]').val() || '(sem assunto)'));
      // iframe sandbox: HTML do template é trusted-admin, dados fictícios
      // são constantes — sandbox bloqueia scripts como 2ª camada
      var frame = document.getElementById('preview-mail-frame');
      frame.srcdoc = '<!DOCTYPE html><html><body style="margin:0;padding:0;' +
        'background:#f1f5f9;font-family:Arial,sans-serif;color:#1e293b;">' +
        '<div style="max-width:520px;margin:16px auto;background:#fff;' +
        'border-radius:12px;padding:28px;">' + corpo + '</div></body></html>';
    }
    $('#cont-chars').text($('#inp-conteudo').val().length.toLocaleString('pt-BR') + ' / 10.000');
  }

  /* Troca de canal (só na criação) */
  $('.canal-card').on('click', function () {
    var canal = $(this).data('canal');
    $(this).find('input').prop('checked', true);
    $('.canal-card').css('border-color', 'var(--c-border)');
    $(this).css('border-color', canal === 'whatsapp' ? '#16a34a' : '#1d4ed8');

    $('#grupo-assunto').toggle(canal === 'email');
    $('#preview-wpp').toggle(canal === 'whatsapp');
    $('#preview-mail').toggle(canal === 'email');
    $('.var-chip-email').toggle(canal === 'email');
    $('#hint-conteudo').text(canal === 'email'
      ? 'HTML permitido (<p>, <a>, <strong>…). A variável {link} é obrigatória.'
      : 'Texto puro — quebras de linha são preservadas no WhatsApp.');
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

  /* Validação client (server revalida tudo) */
  $('#form-tpl').on('submit', function (e) {
    if (canalAtual() === 'email' &&
        $('#inp-conteudo').val().indexOf('{link}') === -1) {
      e.preventDefault();
      alert('Templates de e-mail devem conter a variável {link}.');
    }
  });

  $('#inp-conteudo, input[name="assunto"]').on('input', atualizarPreview);
  atualizarPreview();
});
</script>