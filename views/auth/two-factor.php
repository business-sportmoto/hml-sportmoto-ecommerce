<?php // views/auth/two-factor.php
// Recebe $canais do controller: ['email'=>[...], 'whatsapp'=>[...], 'sms'=>[...]]
$canalIcons = [
    'totp'     => '<rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="12" cy="12" r="3"/><line x1="12" y1="6" x2="12" y2="9"/>',
    'email'    => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>',
    'whatsapp' => '<path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z"/>',
    'sms'      => '<path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>',
];
?>
<div class="auth-page">
  <div class="auth-bg-overlay" aria-hidden="true"></div>

  <div class="auth-layout">
    <aside class="auth-brand-panel" aria-label="Verificação de segurança">
      <a href="<?= BASE_URL ?>" class="auth-brand-logo">
        <?= View::e($config['nome'] ?? ConfigHelper::get('site_nome', 'SportMoto')) ?>
      </a>
      <div class="auth-brand-content">
        <span class="auth-brand-kicker">Proteção extra</span>
        <h2>Confirme que é você</h2>
        <p>Esta etapa protege sua conta contra acessos indevidos e mantém seus dados em segurança.</p>
        <ul class="auth-benefit-list">
          <li><span><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Você escolhe como receber o código</li>
          <li><span><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Mais segurança para sua conta</li>
          <li><span><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Autenticação rápida e objetiva</li>
        </ul>
      </div>
    </aside>

    <main class="auth-form-panel">
      <div class="auth-card">
        <div class="auth-card-header">
          <a href="<?= BASE_URL ?>" class="auth-logo">
            <?= View::e($config['nome'] ?? ConfigHelper::get('site_nome', 'SportMoto')) ?>
          </a>
          <h1 class="auth-title">Verificação em duas etapas</h1>
          <p class="auth-sub" id="2fa-subtitle">Como prefere receber seu código de verificação?</p>
        </div>

        <?php View::partial('partials/flash-message') ?>

        <!-- ── ETAPA A: escolha do canal ──────────────────── -->
        <div id="2fa-step-canal">
          <div class="twofa-channels">
            <?php foreach ($canais as $key => $canal): ?>
            <button type="button"
                    class="twofa-channel <?= $canal['habilitado'] ? '' : 'twofa-channel--disabled' ?>"
                    data-canal="<?= $key ?>"
                    <?= $canal['habilitado'] ? '' : 'disabled' ?>>
              <span class="twofa-channel-ico">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <?= $canalIcons[$key] ?? '' ?>
                </svg>
              </span>
              <span class="twofa-channel-info">
                <strong><?= View::e($canal['label']) ?>
                  <?php if (!empty($canal['em_breve'])): ?>
                  <span class="twofa-soon">em breve</span>
                  <?php endif; ?>
                </strong>
                <small><?= View::e($canal['destino']) ?></small>
              </span>
              <?php if ($canal['habilitado']): ?>
              <svg class="twofa-channel-arrow" width="16" height="16" viewBox="0 0 24 24"
                   fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                <polyline points="9 18 15 12 9 6"/>
              </svg>
              <?php endif; ?>
            </button>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- ── ETAPA B: digitar o código ──────────────────── -->
        <div id="2fa-step-codigo" style="display:none;">
          <div class="twofa-sent-banner" id="2fa-sent-banner"></div>

          <form id="form-2fa" class="auth-form" novalidate>
            <?= SecurityHelper::csrfField() ?>
            <div class="form-group">
              <label for="code">Código de verificação</label>
              <input type="text" id="code" name="code"
                     class="form-control form-control--lg otp-input"
                     placeholder="000000" maxlength="6" inputmode="numeric"
                     pattern="[0-9]{6}" autocomplete="one-time-code">
              <span class="field-error" id="err-code"></span>
            </div>
            <button type="submit" class="btn btn-primary btn-full auth-btn">
              <span class="btn-text">Verificar</span>
              <span class="btn-loading" style="display:none;">Verificando...</span>
            </button>
          </form>

          <div class="twofa-actions">
            <button type="button" class="auth-reenviar" id="btn-2fa-reenviar">Reenviar código</button>
            <span class="twofa-sep">·</span>
            <button type="button" class="auth-reenviar" id="btn-2fa-trocar">Trocar método</button>
          </div>
        </div>

        <p class="auth-back"><a href="<?= BASE_URL ?>/login">&larr; Usar outra conta</a></p>
      </div>
    </main>
  </div>
</div>

<style>
.twofa-channels { display: flex; flex-direction: column; gap: 10px; margin-bottom: 8px; }
.twofa-channel {
  display: flex; align-items: center; gap: 14px;
  padding: 14px 16px;
  border: 1.5px solid var(--c-border, #e2e8f0);
  border-radius: 12px;
  background: #fff; cursor: pointer; text-align: left;
  transition: border-color .15s, background .15s, transform .1s;
}
.twofa-channel:hover:not(:disabled) {
  border-color: var(--c-primary, #2563eb);
  background: #f8faff;
}
.twofa-channel:active:not(:disabled) { transform: scale(.99); }
.twofa-channel--disabled { opacity: .5; cursor: not-allowed; }

.twofa-channel-ico {
  width: 42px; height: 42px; border-radius: 10px;
  background: #f1f5f9; color: #475569;
  display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.twofa-channel-info { flex: 1; min-width: 0; }
.twofa-channel-info strong { display: block; font-size: 14.5px; color: #1e293b; }
.twofa-channel-info small { font-size: 12.5px; color: #94a3b8; }
.twofa-channel-arrow { color: #cbd5e1; flex-shrink: 0; }

.twofa-soon {
  font-size: 9.5px; font-weight: 700; text-transform: uppercase;
  letter-spacing: .4px; color: #92400e; background: #fef3c7;
  padding: 1px 6px; border-radius: 99px; margin-left: 6px; vertical-align: middle;
}

.twofa-sent-banner {
  display: flex; align-items: center; gap: 8px;
  background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 10px;
  padding: 10px 14px; margin-bottom: 18px;
  font-size: 13px; color: #15803d; font-weight: 600;
}

.twofa-actions {
  display: flex; align-items: center; justify-content: center;
  gap: 10px; margin-top: 16px;
}
.twofa-sep { color: #cbd5e1; }
</style>

<script>
window.AUTH_CONFIG = {
  baseUrl:   '<?= BASE_URL ?>',
  csrfToken: '<?= SecurityHelper::generateCsrf() ?>',
};
</script>
