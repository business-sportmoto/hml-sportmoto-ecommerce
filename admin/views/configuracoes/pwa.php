<?php // views/admin/configuracoes/pwa.php ?>

<div class="admin-page">

  <!-- ── Header ───────────────────────────────────────── -->
  <div class="admin-page-header">
    <div>
      <h1 class="admin-page-title">Progressive Web App</h1>
      <p class="admin-page-sub">Configure a identidade do app instalável no celular</p>
    </div>
    <div style="display:flex;gap:10px;align-items:center;">
      <span class="pwa-version-badge" id="pwa-version-badge">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.5" stroke-linecap="round"><polyline points="16 3 21 3 21 8"/>
          <line x1="4" y1="20" x2="21" y2="3"/>
        </svg>
        SW <?= View::e($config['cache_version']) ?>
      </span>
      <button type="button" class="btn btn-primary" id="btn-publicar"
              <?= !$config['icones_gerados'] ? 'disabled title="Gere os ícones primeiro"' : '' ?>>
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
        Publicar PWA
      </button>
    </div>
  </div>

  <div class="pwa-layout">

    <!-- ── Coluna esquerda: formulário ──────────────────── -->
    <div class="pwa-form-col">

      <!-- Identidade -->
      <div class="admin-card pwa-card">
        <div class="pwa-card-header">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2.2" stroke-linecap="round"><rect x="2" y="3" width="20" height="14" rx="2"/>
            <line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>
          </svg>
          Identidade do app
        </div>
        <div class="pwa-card-body">
          <div class="pwa-form-row">
            <div class="ap-form-group">
              <label class="ap-form-label">
                Nome completo
                <span class="pwa-hint">Aparece na splash screen e loja de apps</span>
              </label>
              <input type="text" id="pwa-app-name" name="app_name"
                     class="form-control"
                     value="<?= View::e($config['app_name']) ?>"
                     maxlength="80" required>
            </div>
            <div class="ap-form-group">
              <label class="ap-form-label">
                Nome curto
                <span class="pwa-hint">Embaixo do ícone na home screen — máx. 20 caracteres</span>
              </label>
              <input type="text" id="pwa-short-name" name="app_short_name"
                     class="form-control"
                     value="<?= View::e($config['app_short_name']) ?>"
                     maxlength="20" required>
              <span class="pwa-char-left"><span id="short-count"><?= strlen($config['app_short_name']) ?></span>/20</span>
            </div>
          </div>
          <div class="ap-form-group">
            <label class="ap-form-label">Descrição
              <span class="pwa-hint">Exibida no prompt de instalação</span>
            </label>
            <input type="text" id="pwa-desc" name="app_description"
                   class="form-control"
                   value="<?= View::e($config['app_description'] ?? '') ?>"
                   maxlength="255">
          </div>
        </div>
      </div>

      <!-- Cores -->
      <div class="admin-card pwa-card">
        <div class="pwa-card-header">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2.2" stroke-linecap="round"><circle cx="13.5" cy="6.5" r="2.5"/>
            <circle cx="17.5" cy="10.5" r="2.5"/><circle cx="8.5" cy="7.5" r="2.5"/>
            <circle cx="6.5" cy="12.5" r="2.5"/>
            <path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125..."/>
          </svg>
          Cores do tema
        </div>
        <div class="pwa-card-body">
          <div class="pwa-form-row">
            <div class="ap-form-group">
              <label class="ap-form-label">
                Cor do tema
                <span class="pwa-hint">Barra de status do celular</span>
              </label>
              <div class="pwa-color-wrap">
                <input type="color" id="pwa-theme-color" name="theme_color"
                       value="<?= View::e($config['theme_color']) ?>"
                       class="pwa-color-input">
                <input type="text" id="pwa-theme-hex" class="form-control pwa-hex-input"
                       value="<?= View::e($config['theme_color']) ?>"
                       maxlength="7" pattern="^#[0-9a-fA-F]{6}$">
              </div>
            </div>
            <div class="ap-form-group">
              <label class="ap-form-label">
                Cor do fundo
                <span class="pwa-hint">Splash screen ao abrir o app</span>
              </label>
              <div class="pwa-color-wrap">
                <input type="color" id="pwa-bg-color" name="background_color"
                       value="<?= View::e($config['background_color']) ?>"
                       class="pwa-color-input">
                <input type="text" id="pwa-bg-hex" class="form-control pwa-hex-input"
                       value="<?= View::e($config['background_color']) ?>"
                       maxlength="7" pattern="^#[0-9a-fA-F]{6}$">
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Upload de ícone -->
      <div class="admin-card pwa-card">
        <div class="pwa-card-header">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2.2" stroke-linecap="round"><rect x="3" y="3" width="18" height="18" rx="4"/>
            <circle cx="12" cy="12" r="4"/>
          </svg>
          Ícone do app
        </div>
        <div class="pwa-card-body">
          <p class="pwa-upload-tip">
            PNG quadrado, mínimo <strong>512×512 px</strong>, fundo sólido (sem transparência).
            Fundo transparente terá a cor de fundo aplicada automaticamente.
          </p>
          <div class="pwa-drop-zone" id="pwa-drop-zone">
            <input type="file" id="pwa-icone-input" accept="image/png,image/jpeg,image/webp"
                   style="display:none;">
            <div id="pwa-drop-idle">
              <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                   stroke-width="1.5" stroke-linecap="round">
                <polyline points="16 16 12 12 8 16"/>
                <line x1="12" y1="12" x2="12" y2="21"/>
                <path d="M20.39 18.39A5 5 0 0018 9h-1.26A8 8 0 103 16.3"/>
              </svg>
              <p>Arraste o logo aqui ou
                <button type="button" class="pwa-link-btn" id="pwa-select-btn">selecione o arquivo</button>
              </p>
            </div>
            <div id="pwa-drop-preview" style="display:none;">
              <img id="pwa-preview-img" src="" alt="">
              <div id="pwa-preview-info"></div>
            </div>
          </div>
          <div style="margin-top:12px;display:flex;gap:8px;align-items:center;">
            <button type="button" class="btn btn-primary btn-sm" id="btn-gerar-icones" disabled>
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                   stroke-width="2.5" stroke-linecap="round">
                <polyline points="23 4 23 10 17 10"/>
                <path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/>
              </svg>
              Gerar ícones
            </button>
            <span id="pwa-gerar-status" style="font-size:13px;color:var(--text-2);"></span>
          </div>
        </div>
      </div>

      <!-- Botão salvar config -->
      <div style="display:flex;justify-content:flex-end;">
        <button type="button" class="btn btn-outline" id="btn-salvar-config">
          Salvar configurações
        </button>
      </div>

    </div><!-- /.pwa-form-col -->

    <!-- ── Coluna direita: preview + ícones ─────────────── -->
    <div class="pwa-preview-col">

      <!-- Mockup do celular -->
      <div class="admin-card pwa-card pwa-preview-card">
        <div class="pwa-card-header">Prévia do app</div>
        <div class="pwa-card-body" style="display:flex;flex-direction:column;align-items:center;gap:20px;">

          <!-- Splash screen -->
          <div>
            <p class="pwa-preview-label">Splash screen</p>
            <div class="pwa-phone-mock">
              <div class="pwa-phone-screen pwa-splash" id="preview-splash"
                   style="background:<?= View::e($config['background_color']) ?>;">
                <div class="pwa-splash-icon" id="preview-splash-icon">
                  <?php if ($config['icones_gerados']): ?>
                    <img src="<?= BASE_URL ?>/assets/images/icon-192.png?t=<?= time() ?>" alt="">
                  <?php else: ?>
                    <div class="pwa-splash-placeholder">?</div>
                  <?php endif; ?>
                </div>
                <span class="pwa-splash-name" id="preview-splash-name">
                  <?= View::e($config['app_name']) ?>
                </span>
              </div>
            </div>
          </div>

          <!-- Home screen icon -->
          <div>
            <p class="pwa-preview-label">Ícone na home screen</p>
            <div class="pwa-homescreen-mock">
              <div class="pwa-hs-icon" id="preview-hs-icon"
                   style="background:<?= View::e($config['background_color']) ?>;">
                <?php if ($config['icones_gerados']): ?>
                  <img src="<?= BASE_URL ?>/assets/images/icon-192.png?t=<?= time() ?>" alt="">
                <?php else: ?>
                  <div class="pwa-splash-placeholder">?</div>
                <?php endif; ?>
              </div>
              <span class="pwa-hs-label" id="preview-hs-label">
                <?= View::e($config['app_short_name']) ?>
              </span>
            </div>
          </div>

          <!-- Status bar color -->
          <div class="pwa-statusbar-preview">
            <div class="pwa-statusbar" id="preview-statusbar"
                 style="background:<?= View::e($config['theme_color']) ?>;"></div>
            <span class="pwa-preview-label">Status bar</span>
          </div>

        </div>
      </div>

      <!-- Grid de ícones gerados -->
      <div class="admin-card pwa-card" id="pwa-icons-grid-card">
        <div class="pwa-card-header">Ícones gerados</div>
        <div class="pwa-icons-grid" id="pwa-icons-grid">
          <?php foreach ($icones as $ic): ?>
          <div class="pwa-icon-item <?= $ic['existe'] ? 'pwa-icon-item--ok' : 'pwa-icon-item--missing' ?>">
            <?php if ($ic['existe']): ?>
              <img src="<?= View::e($ic['url']) ?>" alt="">
            <?php else: ?>
              <div class="pwa-icon-empty">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.5" stroke-linecap="round"><rect x="3" y="3" width="18" height="18" rx="4"/>
                  <line x1="9" y1="9" x2="15" y2="15"/><line x1="15" y1="9" x2="9" y2="15"/>
                </svg>
              </div>
            <?php endif; ?>
            <span class="pwa-icon-label"><?= View::e($ic['label']) ?></span>
            <span class="pwa-icon-size"><?= View::e($ic['size']) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

    </div><!-- /.pwa-preview-col -->
  </div><!-- /.pwa-layout -->
</div>

<script>
(function ($) {
'use strict';

var ADMIN_URL = '<?= ADMIN_URL ?>';

// ── Sync color input ↔ hex text ──────────────────────
function syncColor($picker, $hex) {
  $picker.on('input', function () {
    $hex.val(this.value.toUpperCase());
    atualizarPreview();
  });
  $hex.on('input', function () {
    var v = this.value;
    if (/^#[0-9a-fA-F]{6}$/.test(v)) {
      $picker.val(v);
      atualizarPreview();
    }
  });
}
syncColor($('#pwa-theme-color'), $('#pwa-theme-hex'));
syncColor($('#pwa-bg-color'),    $('#pwa-bg-hex'));

// ── Contador nome curto ──────────────────────────────
$('#pwa-short-name').on('input', function () {
  $('#short-count').text(this.value.length);
  $('#preview-hs-label').text(this.value || '…');
});

// ── Preview ao vivo ───────────────────────────────────
$('#pwa-app-name').on('input', function () {
  $('#preview-splash-name').text(this.value || '…');
});

function atualizarPreview() {
  var theme = $('#pwa-theme-hex').val();
  var bg    = $('#pwa-bg-hex').val();
  if (/^#[0-9a-fA-F]{6}$/.test(bg)) {
    $('#preview-splash').css('background', bg);
    $('#preview-hs-icon').css('background', bg);
  }
  if (/^#[0-9a-fA-F]{6}$/.test(theme)) {
    $('#preview-statusbar').css('background', theme);
  }
}

// ── Upload do ícone ───────────────────────────────────
var _iconFile = null;

$('#pwa-select-btn').on('click', function (e) {
  e.stopPropagation();
  $('#pwa-icone-input').trigger('click');
});

const $dropZone = $('#pwa-drop-zone');
const $inputIcone = $('#pwa-icone-input');

$dropZone.on('click', function (e) {
  if ($(e.target).closest('#pwa-icone-input').length) {
    return;
  }

  if (!_iconFile && $inputIcone.length) {
    $inputIcone[0].click();
  }
});

$inputIcone.on('click', function (e) {
  e.stopPropagation();
});

$('#pwa-drop-zone').on('dragover', function (e) {
  e.preventDefault();
  $(this).addClass('drag-over');
}).on('dragleave drop', function (e) {
  e.preventDefault();
  $(this).removeClass('drag-over');
  if (e.type === 'drop') {
    var f = e.originalEvent.dataTransfer.files[0];
    if (f) selecionarArquivo(f);
  }
});

$('#pwa-icone-input').on('change', function () {
  if (this.files[0]) selecionarArquivo(this.files[0]);
  this.value = '';
});

function selecionarArquivo(f) {
  if (!f.type.startsWith('image/')) {
    adminToast('Selecione um arquivo de imagem.', 'error');
    return;
  }
  _iconFile = f;
  var url = URL.createObjectURL(f);
  $('#pwa-drop-idle').hide();
  $('#pwa-preview-img').attr('src', url);
  $('#pwa-preview-info').html(
    '<strong>' + f.name + '</strong><br>' +
    (f.size / 1024).toFixed(0) + ' KB'
  );
  $('#pwa-drop-preview').show();
  $('#pwa-drop-zone').addClass('has-file');
  $('#btn-gerar-icones').prop('disabled', false);

  // Atualiza o preview do splash com o logo selecionado
  $('#preview-splash-icon').html('<img src="' + url + '" alt="">');
  $('#preview-hs-icon').html('<img src="' + url + '" style="width:100%;height:100%;object-fit:cover;">');
}

// ── Gerar ícones ──────────────────────────────────────
$('#btn-gerar-icones').on('click', function () {
  if (!_iconFile) return;

  var $btn    = $(this);
  var $status = $('#pwa-gerar-status');
  var fd      = new FormData();
  fd.append('icone', _iconFile, _iconFile.name);
  fd.append('_token', CSRF_TOKEN);

  CK.btnLoading($btn);
  $status.text('Gerando ícones…');

  $.ajax({
    url        : ADMIN_URL + '/configuracoes/pwa/gerar-icones',
    method     : 'POST',
    data       : fd,
    processData: false,
    contentType: false,
  })
  .done(function (r) {
    CK.btnLoading($btn, false);
    if (r.ok) {
      $status.html('<span style="color:var(--success);">✓ ' + r.msg + '</span>');
      $('#btn-publicar').prop('disabled', false);
      renderIconsGrid(r.icones);
      adminToast(r.msg, 'success');
    } else {
      $status.html('<span style="color:var(--danger);">✗ ' + r.msg + '</span>');
    }
  })
  .fail(function () {
    CK.btnLoading($btn, false);
    $status.html('<span style="color:var(--danger);">✗ Erro de conexão.</span>');
  });
});

// ── Salvar configurações ──────────────────────────────
$('#btn-salvar-config').on('click', function () {
  var $btn = $(this);
  CK.btnLoading($btn);

  $.post(ADMIN_URL + '/configuracoes/pwa/salvar', {
    _token          : CSRF_TOKEN,
    app_name        : $('#pwa-app-name').val(),
    app_short_name  : $('#pwa-short-name').val(),
    app_description : $('#pwa-desc').val(),
    theme_color     : $('#pwa-theme-hex').val(),
    background_color: $('#pwa-bg-hex').val(),
  })
  .done(function (r) {
    CK.btnLoading($btn, false);
    adminToast(r.ok ? r.msg : r.msg, r.ok ? 'success' : 'error');
  })
  .fail(function () {
    CK.btnLoading($btn, false);
    adminToast('Erro de conexão.', 'error');
  });
});

// ── Publicar ─────────────────────────────────────────
$('#btn-publicar').on('click', function () {
  var $btn = $(this);
  CK.btnLoading($btn);

  $.post(ADMIN_URL + '/configuracoes/pwa/publicar', { _token: CSRF_TOKEN })
  .done(function (r) {
    CK.btnLoading($btn, false);
    if (r.ok) {
      $('#pwa-version-badge').html(
        '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor"' +
        ' stroke-width="2.5" stroke-linecap="round"><polyline points="16 3 21 3 21 8"/>' +
        '<line x1="4" y1="20" x2="21" y2="3"/></svg> SW ' + r.versao
      );
      adminToast(r.msg, 'success');
    } else {
      adminToast(r.msg, 'error');
    }
  })
  .fail(function () {
    CK.btnLoading($btn, false);
    adminToast('Erro de conexão.', 'error');
  });
});

// ── Atualiza grid de ícones após geração ──────────────
function renderIconsGrid(icones) {
  if (!icones || !icones.length) return;
  var html = '';
  icones.forEach(function (ic) {
    html += '<div class="pwa-icon-item ' + (ic.existe ? 'pwa-icon-item--ok' : 'pwa-icon-item--missing') + '">' +
      (ic.existe
        ? '<img src="' + ic.url + '" alt="">'
        : '<div class="pwa-icon-empty"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><rect x="3" y="3" width="18" height="18" rx="4"/><line x1="9" y1="9" x2="15" y2="15"/><line x1="15" y1="9" x2="9" y2="15"/></svg></div>') +
      '<span class="pwa-icon-label">' + ic.label + '</span>' +
      '<span class="pwa-icon-size">' + ic.size + '</span>' +
      '</div>';
  });
  $('#pwa-icons-grid').html(html);
}

}(jQuery));
</script>