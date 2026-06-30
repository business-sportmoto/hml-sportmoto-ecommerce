<?php // views/auth/forgot-password.php ?>
<div class="auth-page">
  <div class="auth-bg-overlay" aria-hidden="true"></div>

  <div class="auth-layout">
    <aside class="auth-brand-panel" aria-label="Recuperação de acesso">
      <a href="<?= BASE_URL ?>" class="auth-brand-logo">
        <?= View::e($config['nome'] ?? ConfigHelper::get('site_nome', 'SportMoto')) ?>
      </a>

      <div class="auth-brand-content">
        <span class="auth-brand-kicker">Recuperação de senha</span>
        <h2>Volte para sua conta com segurança</h2>
        <p>
          Informe seu e-mail e enviaremos as instruções para você recuperar o acesso sem complicação.
        </p>

        <ul class="auth-benefit-list">
          <li>
            <span>
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="20 6 9 17 4 12"/>
              </svg>
            </span>
            Link seguro enviado por e-mail
          </li>
          <li>
            <span>
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="20 6 9 17 4 12"/>
              </svg>
            </span>
            Proteção dos dados da sua conta
          </li>
          <li>
            <span>
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="20 6 9 17 4 12"/>
              </svg>
            </span>
            Acesso rápido ao histórico de pedidos
          </li>
        </ul>
      </div>
    </aside>

    <main class="auth-form-panel">
      <div class="auth-card">
        <div class="auth-card-header">
          <a href="<?= BASE_URL ?>" class="auth-logo">
            <?= View::e($config['nome'] ?? ConfigHelper::get('site_nome', 'SportMoto')) ?>
          </a>
          <h1 class="auth-title">Recuperar senha</h1>
          <p class="auth-sub">Informe seu e-mail e enviaremos as instruções.</p>
        </div>

        <?php View::partial('partials/flash-message') ?>

        <form id="form-forgot" class="auth-form" novalidate>
          <?= SecurityHelper::csrfField() ?>

          <div class="form-group">
            <label for="email">E-mail da conta</label>
            <input type="email" id="email" name="email" class="form-control form-control--lg" value="<?= View::e($_GET['email'] ?? '') ?>"
                   placeholder="seu@email.com" required autocomplete="email">
            <span class="field-error" id="err-email"></span>
          </div>

          <button type="submit" class="btn btn-primary btn-full auth-btn">
            <span class="btn-text">Enviar instruções</span>
            <span class="btn-loading" style="display:none;">Enviando...</span>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
              <polyline points="9 18 15 12 9 6"/>
            </svg>
          </button>
        </form>

        <p class="auth-back"><a href="<?= BASE_URL ?>/login">&larr; Voltar ao login</a></p>
      </div>
    </main>
  </div>
</div>
