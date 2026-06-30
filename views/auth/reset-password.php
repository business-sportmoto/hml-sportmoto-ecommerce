<?php // views/auth/reset-password.php ?>
<div class="auth-page">
  <div class="auth-bg-overlay" aria-hidden="true"></div>

  <div class="auth-layout">
    <aside class="auth-brand-panel" aria-label="Criação de nova senha">
      <a href="<?= BASE_URL ?>" class="auth-brand-logo">
        <?= View::e($config['nome'] ?? ConfigHelper::get('site_nome', 'SportMoto')) ?>
      </a>

      <div class="auth-brand-content">
        <span class="auth-brand-kicker">Segurança da conta</span>
        <h2>Crie uma nova senha forte</h2>
        <p>
          Defina uma senha segura para proteger seus dados, pedidos e futuras compras na SportMoto.
        </p>

        <ul class="auth-benefit-list">
          <li>
            <span>
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="20 6 9 17 4 12"/>
              </svg>
            </span>
            Mais proteção para seus dados
          </li>
          <li>
            <span>
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="20 6 9 17 4 12"/>
              </svg>
            </span>
            Acesso seguro aos seus pedidos
          </li>
          <li>
            <span>
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="20 6 9 17 4 12"/>
              </svg>
            </span>
            Experiência de compra sem fricção
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
          <h1 class="auth-title">Nova senha</h1>
          <p class="auth-sub">Crie uma senha forte para sua conta.</p>
        </div>

        <?php View::partial('partials/flash-message') ?>

        <form id="form-reset" class="auth-form" novalidate>
          <?= SecurityHelper::csrfField() ?>
          <input type="hidden" name="token" value="<?= View::e($token) ?>">

          <div class="form-group">
            <label for="senha">Nova senha</label>
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

          <div class="form-group">
            <label for="confirmar_senha">Confirmar nova senha</label>
            <div class="input-password-wrapper">
              <input type="password" id="confirmar_senha" name="confirmar_senha" class="form-control form-control--lg"
                     placeholder="Repita a nova senha" required autocomplete="new-password">
              <button type="button" class="toggle-password" data-target="confirmar_senha" aria-label="Mostrar senha">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                </svg>
              </button>
            </div>
            <span class="field-error" id="err-confirmar"></span>
          </div>

          <button type="submit" class="btn btn-primary btn-full auth-btn">
            <span class="btn-text">Salvar nova senha</span>
            <span class="btn-loading" style="display:none;">Salvando...</span>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
              <polyline points="9 18 15 12 9 6"/>
            </svg>
          </button>
        </form>
      </div>
    </main>
  </div>
</div>
