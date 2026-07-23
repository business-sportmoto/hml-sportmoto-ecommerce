<?php
// views/auth/login.php
$preLogin = SecurityHelper::sanitizeString($_GET['login'] ?? '');



?>



<!-- <div id="header-spacer"></div> -->

<div class="auth-page">
  <div class="auth-bg-overlay" aria-hidden="true"></div>

  <div class="auth-layout">
    <aside class="auth-brand-panel" aria-label="Apresentação da loja">
      <a href="<?= BASE_URL ?>" class="auth-brand-logo">
        <?= View::e(ConfigHelper::get('site_nome', 'Loja')) ?>
      </a>

      <div class="auth-brand-content">
        <span class="auth-brand-kicker">Área do cliente</span>
        <h2>Entre na sua conta SportMoto</h2>
        <p>
          Acesse seus pedidos, acompanhe entregas, gerencie seus dados e continue sua jornada com mais velocidade.
        </p>

        <ul class="auth-benefit-list">
          <li>
            <span>
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="20 6 9 17 4 12"/>
              </svg>
            </span>
            Acompanhe seus pedidos em tempo real
          </li>
          <li>
            <span>
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="20 6 9 17 4 12"/>
              </svg>
            </span>
            Finalize compras com mais agilidade
          </li>
          <li>
            <span>
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="20 6 9 17 4 12"/>
              </svg>
            </span>
            Atendimento e histórico em um só lugar
          </li>
        </ul>
      </div>
    </aside>

    <main class="auth-form-panel">
      <div class="auth-card">

    <!-- Logo / título -->
    <div class="auth-card-header">
      <a href="<?= BASE_URL ?>" class="auth-logo">
        <?= View::e(ConfigHelper::get('site_nome', 'Loja')) ?>
      </a>
      <h1 class="auth-title" id="auth-title">Bem-vindo(a)</h1>
      <p class="auth-sub" id="auth-sub">
        Informe seu e-mail ou CPF para continuar
      </p>
    </div>

    <?php if (!empty($erro)): ?>
    <div class="auth-alert auth-alert--error"><?= View::e($erro) ?></div>
    <?php endif; ?>

    <!-- ── Etapa 1: identificação ──────────────────────────── -->
    <div id="etapa-identidade">
      <form id="form-identidade" novalidate>
        <?= View::partial('auth/partials/_google-btn', ['contexto'=>'login']); ?>

        <!-- <div class="auth-social-block">
          <div id="g_id_onload"
              data-client_id="<?= GOOGLE_CLIENT_ID ?>"
              data-context="signin"
              data-callback="onGoogleAuth"
              data-auto_prompt="false">
          </div>

          <div class="g_id_signin"
              data-type="standard"
              data-shape="rectangular"
              data-theme="outline"
              data-text="continue_with"
              data-size="large"
              data-logo_alignment="left"
              data-locale="pt-BR"
              data-width="360">
          </div>
        </div> -->

        <div class="auth-divider">
          <span>ou entre com seu e-mail</span>
        </div>

        <div class="form-group">
          <label for="input-login">E-mail ou CPF</label>
          <input type="text" id="input-login" name="login"
                 class="form-control form-control--lg"
                 placeholder="seu@email.com ou 000.000.000-00"
                 value="<?= View::e($preLogin) ?>"
                 autocomplete="email" autofocus required>
          <span class="field-error" id="err-login"></span>
        </div>
        <button type="submit" class="btn btn-primary btn-full auth-btn"
                id="btn-identidade">
          Continuar
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5">
            <polyline points="9 18 15 12 9 6"/>
          </svg>
        </button>
      </form>

      <p class="auth-divider"><span>Não tem conta?</span></p>
      <a href="<?= BASE_URL ?>/cadastro" class="btn btn-outline btn-full">
        Criar conta
      </a>
    </div>

    <!-- ── Etapa 2: senha ou código ────────────────────────── -->
    <div id="etapa-senha" style="display:none;">

      <!-- Perfil do usuário encontrado -->
      <div class="auth-user-found" id="auth-user-found">
        <div class="auth-user-avatar" id="auth-user-avatar">
          <!-- preenchido pelo JS -->
        </div>
        <div class="auth-user-info">
          <strong id="auth-user-nome"></strong>
          <span id="auth-user-email-mask"></span>
        </div>
        <button type="button" class="auth-trocar-conta" id="btn-trocar-conta"
                title="Trocar conta">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <line x1="18" y1="6" x2="6"  y2="18"/>
            <line x1="6"  y1="6" x2="18" y2="18"/>
          </svg>
        </button>
      </div>

      <!-- Tabs: Senha | Código -->
      <div class="auth-tabs" id="auth-tabs">
        <button type="button" class="auth-tab active" data-tab="senha">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
            <path d="M7 11V7a5 5 0 0110 0v4"/>
          </svg>
          Senha
        </button>
        <button type="button" class="auth-tab" data-tab="codigo">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
            <polyline points="22,6 12,13 2,6"/>
          </svg>
          Código por e-mail
        </button>
      </div>

      <!-- Painel: senha -->
      <div id="painel-senha">
        <form id="form-senha" novalidate>
          <?= SecurityHelper::csrfField() ?>
          <input type="hidden" name="login" id="hidden-login-senha">

          <div class="form-group">
            <div class="form-label-row">
              <label for="input-senha">Senha</label>
              <a href="<?= BASE_URL ?>/recuperar-senha"
                 class="auth-forgot" id="link-recuperar">
                Esqueci minha senha
              </a>
            </div>
            <div class="input-password-wrapper">
              <input type="password" id="input-senha" name="senha"
                     class="form-control form-control--lg"
                     placeholder="Sua senha" autocomplete="current-password"
                     required>
              <button type="button" class="toggle-password"
                      data-target="input-senha" aria-label="Mostrar senha">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round">
                  <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                  <circle cx="12" cy="12" r="3"/>
                </svg>
              </button>
            </div>
            <span class="field-error" id="err-senha"></span>
          </div>

          <label class="check-label" style="margin-bottom:16px;">
            <input type="checkbox" name="lembrar" value="1">
            <span class="check-custom"></span>
            Lembrar de mim por 30 dias
          </label>

          <button type="submit" class="btn btn-primary btn-full auth-btn"
                  id="btn-entrar-senha">
            Entrar
          </button>
        </form>
      </div>

      <!-- Painel: código por e-mail -->
      <div id="painel-codigo" style="display:none;">
        <div id="codigo-solicitacao">
          <p class="auth-code-desc">
            Vamos enviar um código de 6 dígitos para
            <strong id="codigo-email-dest"></strong>.
          </p>
          <button type="button" class="btn btn-primary btn-full auth-btn"
                  id="btn-enviar-codigo">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
              <polyline points="22,6 12,13 2,6"/>
            </svg>
            Enviar código
          </button>
        </div>

        <div id="codigo-validacao" style="display:none;">
          <p class="auth-code-desc" id="codigo-enviado-msg"></p>
          <form id="form-codigo" novalidate>
            <?= SecurityHelper::csrfField() ?>
            <input type="hidden" name="login" id="hidden-login-codigo">

            <div class="form-group">
              <label>Código de 6 dígitos</label>
              <input type="text" id="input-codigo" name="codigo"
                     class="form-control form-control--lg otp-input"
                     placeholder="000000" maxlength="6"
                     inputmode="numeric" pattern="[0-9]{6}"
                     autocomplete="one-time-code" required>
              <span class="field-error" id="err-codigo"></span>
            </div>

            <label class="check-label" style="margin-bottom:16px;">
              <input type="checkbox" name="lembrar" value="1">
              <span class="check-custom"></span>
              Lembrar de mim por 30 dias
            </label>

            <button type="submit" class="btn btn-primary btn-full auth-btn"
                    id="btn-validar-codigo">
              Verificar e entrar
            </button>
          </form>

          <button type="button" class="auth-reenviar" id="btn-reenviar-codigo">
            Não recebi o código — reenviar
          </button>
        </div>
      </div>

    </div>

    <!-- ── Etapa de verificação de e-mail pós-cadastro ────── -->
    <?= View::partial('auth/partials/_etapa-verificacao') ?>

      </div>
    </main>
  </div>
</div>


<!-- TReste -->
<?= View::partial('auth/partials/_complete-profile-modal'); ?>

<!-- Modal de confirmação de senha (cenário 2) -->
<div class="auth-link-modal" id="auth-link-modal" hidden>
  <div class="auth-link-backdrop"></div>
  <div class="auth-link-panel">
    <div class="auth-link-header">
      <div class="auth-link-icon">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <rect x="3" y="11" width="18" height="11" rx="2"/>
          <path d="M7 11V7a5 5 0 0110 0v4"/>
        </svg>
      </div>
      <h3>Conecte sua conta Google</h3>
      <p>
        Já existe uma conta com o e-mail <strong id="auth-link-email"></strong>.<br>
        Confirme sua senha para vincular sua conta Google.
      </p>
    </div>

    <form id="auth-link-form">
      <input type="hidden" name="_csrf_token" value="<?= SecurityHelper::generateCsrf() ?>">
      <div class="auth-link-field">
        <label>Sua senha</label>
        <input type="password" name="senha" id="auth-link-senha"
               required autocomplete="current-password">
      </div>
      <div id="auth-link-error" class="auth-link-error" hidden></div>
      <div class="auth-link-actions">
        <button type="button" class="auth-link-cancel" id="auth-link-cancel">
          Cancelar
        </button>
        <button type="submit" class="auth-link-confirm">
          Vincular contas
        </button>
      </div>
    </form>
  </div>
</div>

<script src="https://accounts.google.com/gsi/client" async defer></script>

<?php
// reCAPTCHA v3 — só carrega o script do Google se a Site Key estiver
// configurada. Mantém a tela funcional em ambientes sem captcha (dev
// local sem .env preenchido, por exemplo).
$recaptchaSiteKey = getenv('RECAPTCHA_SITE_KEY');
?>
<?php if ($recaptchaSiteKey): ?>
<script src="https://www.google.com/recaptcha/api.js?render=<?= View::e($recaptchaSiteKey) ?>"></script>
<?php endif; ?>

<script>
window.AUTH_CONFIG = {
  baseUrl:   '<?= BASE_URL ?>',
  csrfToken: '<?= SecurityHelper::generateCsrf() ?>',
};
window.RECAPTCHA_SITE_KEY = '<?= View::e($recaptchaSiteKey) ?>';
</script>
<script src="<?= BASE_URL ?>/assets/js/recaptcha.js" defer></script>
<script src="<?= BASE_URL ?>/assets/js/google-auth.js" defer></script>