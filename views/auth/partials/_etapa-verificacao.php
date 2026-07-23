<?php
// views/auth/partials/_etapa-verificacao.php
//
// Etapa de confirmação de e-mail por código de 6 dígitos.
// Usada por DUAS telas:
//   - login.php    → quando o login responde email_pendente = true
//   - register.php → logo após o cadastro (verificacao = true)
//
// Fonte ÚNICA do markup: duplicar em cada view faria os dois
// fluxos divergirem no primeiro ajuste (foi o motivo de extrair).
//
// Depende de auth.js: mostrarEtapaVerificacao() exibe este bloco,
// e o submit vai para /login/validar-codigo (escopado por usuário,
// rate-limited 5/10min).
?>
<div id="etapa-verificacao" style="display:none;">
  <div class="auth-verify-icon">
    <svg width="40" height="40" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
      <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
      <polyline points="22,6 12,13 2,6"/>
    </svg>
  </div>

  <h2 class="auth-verify-title">Confirme seu e-mail</h2>
  <p class="auth-verify-desc">
    Enviamos um código de 6 dígitos para
    <strong id="verify-email-dest"></strong>.
    Insira o código abaixo para ativar sua conta.
  </p>

  <form id="form-verify-email" novalidate>
    <?= SecurityHelper::csrfField() ?>
    <input type="hidden" name="login" id="hidden-login-verify">

    <div class="form-group">
      <input type="text" id="input-verify-codigo" name="codigo"
             class="form-control form-control--lg otp-input"
             placeholder="000000" maxlength="6"
             inputmode="numeric" pattern="[0-9]{6}"
             autocomplete="one-time-code" required>
      <span class="field-error" id="err-verify-codigo"></span>
    </div>

    <button type="submit" class="btn btn-primary btn-full auth-btn">
      Verificar e-mail
    </button>
  </form>

  <button type="button" class="auth-reenviar" id="btn-reenviar-verify">
    Reenviar código
  </button>
</div>