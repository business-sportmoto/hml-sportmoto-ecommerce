<?php
// views/verify/mobile.php
// Página sem cabeçalho — acessada pelo celular via QR code.
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Verificar documento</title>
  <link rel="stylesheet" href="<?= View::asset('css/main.css') ?>">
  <style>
    body     { background:#f4f5f7; min-height:100vh; display:flex;
               align-items:center; justify-content:center; padding:20px; }
    .mobile-verify-card { background:#fff; border-radius:16px; padding:28px 24px;
                           max-width:420px; width:100%; box-shadow:0 4px 24px rgba(0,0,0,.1); }
    .mobile-verify-logo { text-align:center; margin-bottom:20px; font-size:22px;
                           font-weight:800; color:var(--c-primary); }
    .mobile-camera-btn  { width:100%; height:56px; font-size:16px; margin-top:8px; }
    .mobile-tips        { background:var(--c-bg); border-radius:10px; padding:14px;
                           font-size:13px; color:var(--c-text-muted); margin-top:16px; }
    .mobile-tips li     { margin-bottom:6px; }
    .mobile-preview     { width:100%; border-radius:10px; margin:12px 0;
                           max-height:280px; object-fit:contain; display:none; }
    .mobile-result      { text-align:center; padding:24px 0; }
    .result-icon-lg     { font-size:56px; margin-bottom:12px; }
  </style>
</head>
<body>
<div class="mobile-verify-card">
  <div class="mobile-verify-logo">
    <?= View::e(ConfigHelper::get('site_nome', 'Loja')) ?>
  </div>

  <div id="mobile-form-section">
    <h2 style="font-size:19px;font-weight:700;margin-bottom:6px;">Enviar documento</h2>
    <p style="font-size:14px;color:var(--c-text-muted);margin-bottom:20px;line-height:1.6;">
      Tire uma foto do seu RG ou CNH. O documento deve estar legível e bem iluminado.
    </p>

    <form id="mobile-upload-form" enctype="multipart/form-data" novalidate>
      <?= SecurityHelper::csrfField() ?>
      <input type="hidden" name="token" value="<?= View::e($token) ?>">

      <div class="form-group" style="margin-bottom:16px;">
        <label>Tipo de documento</label>
        <div style="display:flex;gap:10px;margin-top:8px;">
          <label style="flex:1;display:flex;align-items:center;gap:8px;padding:10px 14px;
                         border:2px solid var(--c-border);border-radius:8px;cursor:pointer;font-size:14px;">
            <input type="radio" name="tipo" value="rg" checked
                   style="accent-color:var(--c-primary);">
            RG
          </label>
          <label style="flex:1;display:flex;align-items:center;gap:8px;padding:10px 14px;
                         border:2px solid var(--c-border);border-radius:8px;cursor:pointer;font-size:14px;">
            <input type="radio" name="tipo" value="cnh"
                   style="accent-color:var(--c-primary);">
            CNH
          </label>
        </div>
      </div>

      <img id="mobile-preview" class="mobile-preview" src="" alt="Preview">

      <input type="file" id="mobile-file-input" name="documento"
             accept="image/jpeg,image/png,image/webp"
             capture="environment" style="display:none;">

      <button type="button" class="btn btn-primary mobile-camera-btn" id="btn-mobile-camera">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/>
          <circle cx="12" cy="13" r="4"/>
        </svg>
        Tirar foto do documento
      </button>

      <div id="mobile-submit-section" style="display:none;">
        <button type="submit" class="btn btn-primary mobile-camera-btn"
                id="btn-mobile-submit">
          Enviar documento
        </button>
        <button type="button" class="btn btn-outline mobile-camera-btn"
                id="btn-mobile-retake" style="margin-top:8px;">
          Tirar nova foto
        </button>
      </div>

      <div id="mobile-error" class="form-alert form-alert--error" style="display:none;margin-top:12px;"></div>
    </form>

    <div class="mobile-tips">
      <strong style="color:var(--c-dark);">Dicas para uma boa foto:</strong>
      <ul style="margin-top:8px;padding-left:16px;">
        <li>Fundo plano e claro</li>
        <li>Boa iluminação, sem reflexo</li>
        <li>Segure firme para não sair tremida</li>
        <li>Todo o documento deve aparecer</li>
      </ul>
    </div>
  </div>

  <!-- Resultado -->
  <div id="mobile-result" class="mobile-result" style="display:none;">
    <div class="result-icon-lg" id="mobile-result-icon"></div>
    <h3 id="mobile-result-title" style="font-size:20px;font-weight:700;margin-bottom:8px;"></h3>
    <p id="mobile-result-msg" style="font-size:14px;color:var(--c-text-muted);line-height:1.6;"></p>
    <p style="font-size:13px;color:var(--c-text-muted);margin-top:16px;">
      Você pode fechar esta página.
    </p>
  </div>
</div>

<script src="<?= View::asset('js/jquery.min.js') ?>"></script>
<script>
const BASE_URL   = '<?= BASE_URL ?>';
const CSRF_TOKEN = '<?= SecurityHelper::generateCsrf() ?>';

$(function () {
  $('#btn-mobile-camera').on('click', function () {
    $('#mobile-file-input').trigger('click');
  });

  $('#mobile-file-input').on('change', function () {
    const file = this.files[0];
    if (!file) return;

    if (file.size > 10 * 1024 * 1024) {
      $('#mobile-error').text('Arquivo muito grande. Máximo 10MB.').show();
      return;
    }

    const reader = new FileReader();
    reader.onload = function (e) {
      $('#mobile-preview').attr('src', e.target.result).show();
      $('#btn-mobile-camera').hide();
      $('#mobile-submit-section').show();
    };
    reader.readAsDataURL(file);
  });

  $('#btn-mobile-retake').on('click', function () {
    $('#mobile-file-input').val('').trigger('click');
    $('#mobile-preview').hide().attr('src', '');
    $('#btn-mobile-camera').show();
    $('#mobile-submit-section').hide();
    $('#mobile-error').hide();
  });

  $('#mobile-upload-form').on('submit', function (e) {
    e.preventDefault();
    const $btn = $('#btn-mobile-submit');
    $btn.prop('disabled', true).text('Enviando...');

    const fd = new FormData(this);

    $.ajax({
      url:         BASE_URL + '/verificar-documento/upload',
      type:        'POST',
      data:        fd,
      processData: false,
      contentType: false,
      dataType:    'json',
    }).done(function (res) {
      $('#mobile-form-section').hide();
      $('#mobile-result').show();

      if (res.ok && res.status === 'verificado') {
        $('#mobile-result-icon').text('✅');
        $('#mobile-result-title').text('Documento verificado!');
        $('#mobile-result-msg').text('Seu perfil agora está verificado. Pode fechar esta página.');
      } else if (res.status === 'rejeitado') {
        $('#mobile-result-icon').text('❌');
        $('#mobile-result-title').text('Documento não aprovado');
        $('#mobile-result-msg').text(res.msg);
      } else {
        $('#mobile-result-icon').text('⏳');
        $('#mobile-result-title').text('Em análise');
        $('#mobile-result-msg').text('Seu documento está sendo analisado.');
      }
    }).fail(function () {
      $('#mobile-error').text('Erro de conexão. Tente novamente.').show();
      $btn.prop('disabled', false).text('Enviar documento');
    });
  });
});
</script>
</body>
</html>