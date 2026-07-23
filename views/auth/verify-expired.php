<?php
// views/auth/verify-expired.php — layout 'minimal'
// Sem variáveis: o reenvio pede o e-mail ao usuário e reusa o
// endpoint resendVerification (CSRF + rate limit + resposta neutra).
//
// ⚠ Deliberadamente NÃO exibe o e-mail da conta: esta página é
// alcançável por qualquer um que tenha o link expirado (prints,
// e-mails encaminhados, referer). Mostrar o e-mail vinculado
// vazaria PII para o portador do link.
?>
<div style="min-height:70vh;display:flex;align-items:center;justify-content:center;padding:24px;">
  <div style="max-width:440px;width:100%;">

    <div style="text-align:center;">
      <div style="width:72px;height:72px;margin:0 auto 20px;border-radius:50%;
                  background:#fffbeb;display:flex;align-items:center;justify-content:center;
                  font-size:32px;">⏳</div>
      <h1 style="font-size:22px;font-weight:800;margin:0 0 10px;">
        Este link expirou
      </h1>
      <p style="color:var(--c-text-muted);font-size:14.5px;line-height:1.6;margin:0 0 24px;">
        Links de verificação valem por 24 horas. Informe seu e-mail que
        enviamos um novo agora mesmo.
      </p>
    </div>

    <form id="form-reenvio" style="display:flex;flex-direction:column;gap:12px;">
      <?= SecurityHelper::csrfField(); ?>

      <div class="form-group" style="margin:0;">
        <label class="form-label" for="reenvio-email">Seu e-mail</label>
        <input type="email" id="reenvio-email" name="login" class="form-control"
               placeholder="voce@email.com" required autocomplete="email">
      </div>

      <button type="submit" class="btn btn-primary" id="btn-reenvio">
        Enviar novo link</button>

      <div id="reenvio-msg" style="display:none;font-size:13.5px;padding:11px 14px;
           border-radius:9px;line-height:1.5;"></div>
    </form>

    <p style="margin:24px 0 0;text-align:center;font-size:12.5px;color:var(--c-text-muted);">
      Já verificou? <a href="<?= BASE_URL ?>/login"
         style="color:inherit;text-decoration:underline;">Fazer login</a>
    </p>
  </div>
</div>

<script>
jQuery(function ($) {
  $('#form-reenvio').on('submit', function (e) {
    e.preventDefault();

    var $btn = $('#btn-reenvio').prop('disabled', true).text('Enviando…');
    var $msg = $('#reenvio-msg').hide();

    $.post('<?= BASE_URL ?>/reenviar-verificacao', $(this).serialize(), null, 'json')
      .done(function (r) {
        // A resposta do servidor é NEUTRA por design (não revela se o
        // e-mail existe) — a UI apenas ecoa a mensagem, sem inferir nada.
        $msg.text(r.msg || 'Solicitação registrada.')
            .css(r.ok
              ? { background: '#f0fdf4', border: '1px solid #bbf7d0', color: '#15803d' }
              : { background: '#fef2f2', border: '1px solid #fecaca', color: '#dc2626' })
            .slideDown(150);
        if (r.ok) $('#form-reenvio')[0].reset();
      })
      .fail(function () {
        $msg.text('Não foi possível enviar agora. Tente novamente em instantes.')
            .css({ background: '#fef2f2', border: '1px solid #fecaca', color: '#dc2626' })
            .slideDown(150);
      })
      .always(function () {
        $btn.prop('disabled', false).text('Enviar novo link');
      });
  });
});
</script>