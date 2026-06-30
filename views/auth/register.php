<?php
// views/auth/register.php
$formData = Session::getFlash('form_data', []);
 
// Contexto de origem (persiste na sessão desde o redirect do checkout)
$origemCtx = Session::get('after_login_origem', '');
 
$ctxOrigem = [
    'checkout' => [
        'titulo' => 'Crie sua conta para finalizar',
        'sub'    => 'Falta pouco! Cadastre-se e conclua sua compra em seguida.',
        'badge'  => 'Seus itens continuam guardados no carrinho',
    ],
];
$ctx = $ctxOrigem[$origemCtx] ?? null;

?>
<div class="auth-page auth-page--register">
  <div class="auth-bg-overlay" aria-hidden="true"></div>

  <div class="auth-layout auth-layout--wide">
    <aside class="auth-brand-panel" aria-label="Cadastro na loja">
      <a href="<?= BASE_URL ?>" class="auth-brand-logo">
        <?= View::e($config['nome'] ?? ConfigHelper::get('site_nome', 'SportMoto')) ?>
      </a>

      <div class="auth-brand-content">
        <span class="auth-brand-kicker">Área do cliente</span>
        <h2>Crie sua conta SportMoto</h2>
        <p>
          Tenha uma experiência mais rápida para comprar, acompanhar pedidos e receber novidades selecionadas para motociclistas.
        </p>

        <ul class="auth-benefit-list">
          <li>
            <span>
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="20 6 9 17 4 12"/>
              </svg>
            </span>
            Checkout mais rápido nas próximas compras
          </li>
          <li>
            <span>
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="20 6 9 17 4 12"/>
              </svg>
            </span>
            Histórico de pedidos sempre à mão
          </li>
          <li>
            <span>
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="20 6 9 17 4 12"/>
              </svg>
            </span>
            Ofertas e novidades com curadoria
          </li>
        </ul>
      </div>
    </aside>

    <main class="auth-form-panel">
      <div class="auth-card auth-card--wide">
        <div class="auth-card-header">
          <a href="<?= BASE_URL ?>" class="auth-logo">
            <?= View::e($config['nome'] ?? ConfigHelper::get('site_nome', 'SportMoto')) ?>
          </a>
          <h1 class="auth-title">
            <?= $ctx ? View::e($ctx['titulo']) : 'Criar sua conta' ?>
          </h1>
          <p class="auth-sub">
            <?= $ctx ? View::e($ctx['sub']) : 'Preencha os dados abaixo para começar.' ?>
          </p>
          
          <?php if ($ctx && !empty($ctx['badge'])): ?>
          <div class="auth-context-badge">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2" stroke-linecap="round"
                stroke-linejoin="round">
              <circle cx="9" cy="21" r="1"/>
              <circle cx="20" cy="21" r="1"/>
              <path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/>
            </svg>
            <?= View::e($ctx['badge']) ?>
          </div>
          <?php endif; ?>
        </div>

        <?php View::partial('partials/flash-message') ?>

        <form id="form-register" class="auth-form" novalidate>
          <?= SecurityHelper::csrfField() ?>

          <?= View::partial('auth/partials/_google-btn', ['contexto'=>'cadastro']); ?>

          <div class="auth-divider">
            <span>ou cadastre-se com e-mail</span>
          </div>

          <div class="form-row">
            <div class="form-group form-col">
              <label for="nome">Nome completo <span class="required">*</span></label>
              <input type="text" id="nome" name="nome" class="form-control form-control--lg"
                     value="<?= View::e($formData['nome'] ?? '') ?>"
                     placeholder="Seu nome completo" required autocomplete="name">
              <span class="field-error" id="err-nome"></span>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group form-col">
              <label for="email">E-mail <span class="required">*</span></label>
              <input type="email" id="email" name="email" class="form-control form-control--lg"
                     value="<?= $email_pre ?>"
                     placeholder="seu@email.com" required autocomplete="email">
              <span class="field-error" id="err-email"></span>
              <span class="field-hint" id="hint-email" style="display:none;"></span>
            </div>
          </div>

          <div class="form-row form-row--2col">
            <div class="form-group form-col">
              <label for="cpf">CPF</label>
              <input type="text" id="cpf" name="cpf" class="form-control form-control--lg cpf-mask"
                     value="<?= $cpf_pre ?>"
                     placeholder="000.000.000-00" maxlength="14" autocomplete="off">
              <span class="field-error" id="err-cpf"></span>
            </div>
            <div class="form-group form-col">
              <label for="celular">Celular</label>
              <input type="tel" id="celular" name="celular" class="form-control form-control--lg phone-mask"
                     value="<?= View::e($formData['celular'] ?? '') ?>"
                     placeholder="(00) 00000-0000" autocomplete="tel">
            </div>
          </div>

          <div class="form-row form-row--2col">
            <div class="form-group form-col">
              <label for="senha">Senha <span class="required">*</span></label>
              <div class="input-password-wrapper">
                <input type="password" id="senha" name="senha" class="form-control form-control--lg"
                       placeholder="Mínimo 8 caracteres" required autocomplete="new-password">
                <button type="button" class="toggle-password" data-target="senha" aria-label="Mostrar senha">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                  </svg>
                </button>
              </div>
              <div class="password-strength" id="strength-bar" style="display:none;">
                <div class="strength-track"><div class="strength-fill" id="strength-fill"></div></div>
                <span class="strength-label" id="strength-label"></span>
              </div>
              <span class="field-error" id="err-senha"></span>
            </div>
            <div class="form-group form-col">
              <label for="confirmar_senha">Confirmar senha <span class="required">*</span></label>
              <div class="input-password-wrapper">
                <input type="password" id="confirmar_senha" name="confirmar_senha" class="form-control form-control--lg"
                       placeholder="Repita a senha" required autocomplete="new-password">
                <button type="button" class="toggle-password" data-target="confirmar_senha" aria-label="Mostrar senha">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                  </svg>
                </button>
              </div>
              <span class="field-error" id="err-confirmar"></span>
            </div>
          </div>

          <div class="form-group form-check">
            <label class="check-label">
              <input type="checkbox" name="newsletter" id="newsletter" value="1" checked>
              <span class="check-custom"></span>
              Quero receber promoções e novidades por e-mail
            </label>
          </div>

          <div class="form-group form-check">
            <label class="check-label">
              <input type="checkbox" name="termos" id="termos" value="1" required>
              <span class="check-custom"></span>
              Li e concordo com os <a href="<?= BASE_URL ?>/termos-de-uso" target="_blank">Termos de uso</a>
              e <a href="<?= BASE_URL ?>/politica-privacidade" target="_blank">Política de privacidade</a>
              <span class="required">*</span>
            </label>
            <span class="field-error" id="err-termos"></span>
          </div>

          <button type="submit" class="btn btn-primary btn-full auth-btn" id="btn-register">
            <span class="btn-text">Criar conta</span>
            <span class="btn-loading" style="display:none;">Criando conta...</span>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
              <polyline points="9 18 15 12 9 6"/>
            </svg>
          </button>
        </form>

        <p class="auth-back">Já tem uma conta? <a href="<?= BASE_URL ?>/login">Fazer login</a></p>
      </div>
    </main>
  </div>
</div>

<?= View::partial('auth/partials/_complete-profile-modal'); ?>
<script src="https://accounts.google.com/gsi/client" async defer></script>
<script>
window.AUTH_CONFIG = {
  baseUrl:   '<?= BASE_URL ?>',
  csrfToken: '<?= SecurityHelper::generateCsrf() ?>',
};
</script>
<script src="<?= BASE_URL ?>/assets/js/google-auth.js" defer></script>
