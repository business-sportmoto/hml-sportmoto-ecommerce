<?php
// views/auth/forgot-password.php
//
// `origem=tray` vem do AuthController::checkIdentity quando a conta veio da
// loja anterior (sem senha local). Serve só ao TEXTO da tela — não concede
// nada e não vale como identificação.
$migrado       = ($_GET['origem'] ?? '') === 'tray';
$identificador = trim((string) ($_GET['email'] ?? $_GET['login'] ?? ''));
?>
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
          <h1 class="auth-title"><?= $migrado ? 'Criar nova senha' : 'Recuperar senha' ?></h1>
          <p class="auth-sub">
            <?= $migrado
                ? 'Sua conta foi transferida da nossa loja anterior.'
                : 'Informe seu e-mail ou CPF e enviaremos as instruções.' ?>
          </p>
        </div>

        <?php View::partial('partials/flash-message') ?>

        <?php if ($migrado): ?>
        <?php
        // O cliente migrado não está "recuperando" nada: ele nunca teve senha
        // aqui. Sem esta explicação, a tela parece dizer que ele esqueceu uma
        // senha que nunca existiu.
        ?>
        <div class="auth-alert auth-alert--info" role="status">
          <strong>Você precisa criar uma senha nova.</strong>
          <span>
            Por segurança, as senhas da loja antiga não foram transferidas.
            Confirme seu e-mail ou CPF abaixo e enviaremos o link para você
            criar a sua.
          </span>
        </div>
        <?php endif; ?>

        <form id="form-forgot" class="auth-form" novalidate>
          <?= SecurityHelper::csrfField() ?>

          <div class="form-group">
            <label for="login">E-mail ou CPF</label>
            <input type="text" id="login" name="login" class="form-control form-control--lg"
                   value="<?= View::e($identificador) ?>"
                   placeholder="seu@email.com ou 000.000.000-00" required
                   autocomplete="username" autocapitalize="off" spellcheck="false">
            <span class="field-error" id="err-login"></span>
          </div>

          <button type="submit" class="btn btn-primary btn-full auth-btn">
            <span class="btn-text">Enviar instruções</span>
            <span class="btn-loading" style="display:none;">Enviando...</span>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
              <polyline points="9 18 15 12 9 6"/>
            </svg>
          </button>
        </form>

        <?php
        // Para onde o link foi. Sem isto o cliente não sabe se caiu num e-mail
        // antigo — o motivo mais comum de "não recebi nada".
        ?>
        <div class="auth-enviado" id="forgot-enviado" hidden>
          <div class="auth-enviado-ico" aria-hidden="true">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <rect x="2" y="4" width="20" height="16" rx="2"/><polyline points="2,6 12,13 22,6"/>
            </svg>
          </div>
          <strong id="forgot-enviado-titulo">Link enviado</strong>
          <p>
            Enviamos para <b id="forgot-enviado-email"></b>.
            O link vale <span id="forgot-enviado-validade">60</span> minutos.
          </p>
          <p class="auth-enviado-dica">
            Não chegou? Veja o spam ou
            <button type="button" class="auth-enviado-retry" id="forgot-retry">tente outro e-mail ou CPF</button>.
          </p>
        </div>

        <p class="auth-back"><a href="<?= BASE_URL ?>/login">&larr; Voltar ao login</a></p>
      </div>
    </main>
  </div>
</div>
