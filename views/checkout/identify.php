<?php
// ════════════════════════════════════════════════════════
// views/checkout/identify.php
//
// Página completa de identificação.
// Renderiza condicionalmente:
//   - Se há cadastro pendente em sessão → painel de verificação
//   - Senão → tabs de login + cadastro
//
// Nada de display:none — o servidor decide qual view mostrar.
// ════════════════════════════════════════════════════════

$pendingVerify = Session::get('checkout_pending_signup');
?>

<div class="checkout-section checkout-identify-page">

  <?php if ($pendingVerify): ?>

  <!-- ════════════════════════════════════════════════
       VERIFICAÇÃO DE E-MAIL
       ════════════════════════════════════════════════ -->
  <div class="verify-icon-wrap">
    <div class="verify-icon">
      <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
        <polyline points="22,6 12,13 2,6"/>
      </svg>
    </div>
  </div>

  <h2 class="verify-title">Confirme seu e-mail</h2>
  <p class="verify-text">
    Enviamos um código de 6 dígitos para
    <strong><?= View::e($pendingVerify['email']) ?></strong>.
    Essa etapa protege sua conta e garante que você receba atualizações do pedido.
  </p>

  <form id="form-checkout-verify" novalidate>
    <input type="hidden" name="acao"   value="verificar_codigo">
    <input type="hidden" name="codigo" id="verify-codigo-hidden">

    <div class="verify-code-wrap">
      <input type="text" class="verify-digit" maxlength="1" inputmode="numeric"
             pattern="[0-9]" autocomplete="one-time-code" data-index="0" autofocus>
      <input type="text" class="verify-digit" maxlength="1" inputmode="numeric"
             pattern="[0-9]" data-index="1">
      <input type="text" class="verify-digit" maxlength="1" inputmode="numeric"
             pattern="[0-9]" data-index="2">
      <span class="verify-sep">-</span>
      <input type="text" class="verify-digit" maxlength="1" inputmode="numeric"
             pattern="[0-9]" data-index="3">
      <input type="text" class="verify-digit" maxlength="1" inputmode="numeric"
             pattern="[0-9]" data-index="4">
      <input type="text" class="verify-digit" maxlength="1" inputmode="numeric"
             pattern="[0-9]" data-index="5">
    </div>

    <div id="verify-error" class="form-alert" style="display:none;"></div>

    <button type="submit" class="btn btn-primary btn-full" id="btn-verify-submit" disabled>
      Validar e continuar
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="2.5" stroke-linecap="round">
        <polyline points="20 6 9 17 4 12"/>
      </svg>
    </button>

    <div class="verify-actions">
      <button type="button" class="btn-link" id="btn-resend-code">
        Reenviar código
        <span id="resend-timer" style="display:none;">(em <span id="resend-seconds">30</span>s)</span>
      </button>
      <span class="verify-actions-sep">·</span>
      <button type="button" class="btn-link" id="btn-edit-email">
        Editar e-mail
      </button>
    </div>
  </form>

  <?php else: ?>

  <!-- ════════════════════════════════════════════════
       LOGIN + CADASTRO RÁPIDO
       ════════════════════════════════════════════════ -->
  <div class="section-head">
    <h2>
      <span class="section-num">1</span>
      Acesso à sua conta
    </h2>
    <p class="section-sub">Crie sua conta em segundos ou entre com seu e-mail. É rápido e seguro.</p>
  </div>

  <div class="ident-tabs">
    <button class="ident-tab active" data-tab="login">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="2.5" stroke-linecap="round">
        <path d="M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4"/>
        <polyline points="10 17 15 12 10 7"/>
        <line x1="15" y1="12" x2="3" y2="12"/>
      </svg>
      Já tenho conta
    </button>
    <button class="ident-tab" data-tab="cadastro">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="2.5" stroke-linecap="round">
        <path d="M16 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/>
        <circle cx="8.5" cy="7" r="4"/>
        <line x1="20" y1="8" x2="20" y2="14"/>
        <line x1="23" y1="11" x2="17" y2="11"/>
      </svg>
      Criar conta rápida
    </button>
  </div>

  <!-- LOGIN -->
  <div class="ident-panel" id="panel-login">
    <form id="form-checkout-login" novalidate>
      <input type="hidden" name="acao" value="login">

      <div class="form-group">
        <label for="login-email">E-mail</label>
        <input type="email" id="login-email" name="email" class="form-control"
               placeholder="seu@email.com" required autocomplete="email">
      </div>

      <div class="form-group">
        <label for="login-senha">
          Senha
          <a href="<?= BASE_URL ?>/recuperar-senha" class="label-link" target="_blank">Esqueceu?</a>
        </label>
        <div class="input-password-wrapper">
          <input type="password" id="login-senha" name="senha" class="form-control"
                 placeholder="Sua senha" required autocomplete="current-password">
          <button type="button" class="toggle-password" data-target="login-senha" aria-label="Mostrar">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2">
              <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
              <circle cx="12" cy="12" r="3"/>
            </svg>
          </button>
        </div>
      </div>

      <div id="login-error" class="form-alert" style="display:none;"></div>

      <button type="submit" class="btn btn-primary btn-full">
        Entrar e continuar
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.5" stroke-linecap="round">
          <line x1="5" y1="12" x2="19" y2="12"/>
          <polyline points="12 5 19 12 12 19"/>
        </svg>
      </button>
    </form>
  </div>

  <!-- CADASTRO RÁPIDO -->
  <div class="ident-panel" id="panel-cadastro" hidden>
    <div class="ident-info-box">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="2" stroke-linecap="round">
        <circle cx="12" cy="12" r="10"/>
        <line x1="12" y1="8"  x2="12" y2="12"/>
        <line x1="12" y1="16" x2="12.01" y2="16"/>
      </svg>
      Sem senha agora. Validamos pelo seu e-mail e você define depois.
    </div>

    <form id="form-checkout-cadastro" novalidate>
      <input type="hidden" name="acao" value="cadastro_rapido">

      <div class="form-group">
        <label for="cad-nome">Nome completo</label>
        <input type="text" id="cad-nome" name="nome" class="form-control"
               placeholder="Seu nome completo" required autocomplete="name">
      </div>

      <div class="form-group">
        <label for="cad-email">E-mail</label>
        <input type="email" id="cad-email" name="email" class="form-control"
               placeholder="seu@email.com" required autocomplete="email">
      </div>

      <div class="form-group">
        <label for="cad-whatsapp">WhatsApp</label>
        <input type="tel" id="cad-whatsapp" name="whatsapp"
               class="form-control phone-mask"
               placeholder="(00) 00000-0000" maxlength="15"
               required autocomplete="tel">
        <small class="form-help">Para acompanhar o status do pedido</small>
      </div>

      <div id="cad-error" class="form-alert" style="display:none;"></div>

      <button type="submit" class="btn btn-primary btn-full" id="btn-cad-submit">
        Continuar
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.5" stroke-linecap="round">
          <line x1="5" y1="12" x2="19" y2="12"/>
          <polyline points="12 5 19 12 12 19"/>
        </svg>
      </button>

      <p class="ident-legal">
        Ao criar sua conta, você aceita os
        <a href="<?= BASE_URL ?>/termos-de-uso" target="_blank">Termos</a> e a
        <a href="<?= BASE_URL ?>/politica-privacidade" target="_blank">Política de Privacidade</a>.
      </p>
    </form>
  </div>

  <?php endif; ?>
</div>

<style>
/* Tabs panel control via hidden attribute (sem display:none) */
.ident-panel[hidden] { display: none; }
.ident-panel { animation: panel-fade .2s ease; }
@keyframes panel-fade { from { opacity: 0; } to { opacity: 1; } }
.form-help {
  display: block;
  font-size: 11.5px;
  color: var(--c-text-muted);
  margin-top: 4px;
}
.checkout-identify-page { padding: 32px 28px; }
@media (max-width: 600px) { .checkout-identify-page { padding: 24px 18px; } }
</style>