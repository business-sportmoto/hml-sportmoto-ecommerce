<?php
/**
 * admin/views/auth/login.php
 *
 * View de autenticação do painel administrativo — renderizada SEM layout.
 *
 * DESIGN: enterprise premium (dark), split-screen assimétrico, glassmorphism
 * com refração de borda, micro-interações via transform/opacity (GPU),
 * estados de loading/erro completos, acessibilidade AA.
 *
 * SEGURANÇA (ver "Análise de Segurança" no chat):
 * - CSP-compliant: tipografia self-hosted (nada de Google Fonts remoto),
 *   sem inline-script (o JS vai em arquivo externo admin/assets/js/login.js
 *   se você aplicar CSP script-src estrito; aqui deixo um bloco mínimo que
 *   pode ser movido pra nonce).
 * - Todos os ecos escapados com htmlspecialchars(ENT_QUOTES).
 * - CSRF token em campo hidden, escapado.
 * - autocomplete correto para gerenciadores de senha.
 * - Mensagem de erro genérica (anti-enumeração) — o texto vem do controller.
 */
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title><?= htmlspecialchars($pageTitle ?? 'Acesso — Painel Administrativo', ENT_QUOTES, 'UTF-8') ?></title>

  <link rel="stylesheet" href="<?= View::asset('css/admin-login.css') ?>">
</head>
<body class="login-body">

  <!-- Camada de ambiente: mesh gradient sutil + grão. pointer-events-none, fixa. -->
  <div class="login-ambient" aria-hidden="true">
    <span class="login-ambient__blob login-ambient__blob--1"></span>
    <span class="login-ambient__blob login-ambient__blob--2"></span>
    <span class="login-ambient__grain"></span>
  </div>

  <main class="login-shell">

    <!-- COLUNA A — narrativa de marca (colapsa no mobile) -->
    <aside class="login-brand" aria-hidden="true">
      <div class="login-brand__top">
        <span class="login-brand__mark">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"
               stroke-linecap="round" stroke-linejoin="round" width="26" height="26">
            <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
            <path d="M3.27 6.96 12 12.01l8.73-5.05"/><path d="M12 22.08V12"/>
          </svg>
        </span>
        <span class="login-brand__wordmark">Sportmoto</span>
      </div>

      <div class="login-brand__center">
        <h2 class="login-brand__headline">
          Controle total.<br>
          <span class="login-brand__headline-accent">Acesso restrito.</span>
        </h2>
        <p class="login-brand__desc">
          Painel de operação da plataforma. Ambiente monitorado, acesso auditado.
        </p>
      </div>

      <div class="login-brand__foot">
        <span class="login-brand__pill">
          <span class="login-brand__dot"></span> Sessão protegida
        </span>
        <span class="login-brand__meta">v2 · homologação</span>
      </div>
    </aside>

    <!-- COLUNA B — formulário -->
    <section class="login-panel">
      <div class="login-card">

        <header class="login-card__head">
          <span class="login-card__mark" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"
                 stroke-linecap="round" stroke-linejoin="round" width="22" height="22">
              <rect x="3" y="11" width="18" height="11" rx="2"/>
              <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
          </span>
          <h1 class="login-card__title">Painel Administrativo</h1>
          <p class="login-card__sub">Autentique-se para continuar</p>
        </header>

        <?php if (Session::hasFlash('error')): ?>
          <div class="login-alert login-alert--error" role="alert" aria-live="assertive">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" width="18" height="18" aria-hidden="true">
              <circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/>
            </svg>
            <span><?= htmlspecialchars(Session::getFlash('error'), ENT_QUOTES, 'UTF-8') ?></span>
          </div>
        <?php endif; ?>

        <?php if (Session::hasFlash('success')): ?>
          <div class="login-alert login-alert--success" role="status" aria-live="polite">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" width="18" height="18" aria-hidden="true">
              <path d="M20 6 9 17l-5-5"/>
            </svg>
            <span><?= htmlspecialchars(Session::getFlash('success'), ENT_QUOTES, 'UTF-8') ?></span>
          </div>
        <?php endif; ?>

        <form method="POST" action="<?= htmlspecialchars(ADMIN_URL, ENT_QUOTES, 'UTF-8') ?>/login"
              class="login-form" id="loginForm" novalidate>
          <input type="hidden" name="_csrf_token"
                 value="<?= htmlspecialchars($csrf_token ?? '', ENT_QUOTES, 'UTF-8') ?>">

          <div class="login-field">
            <label for="email" class="login-field__label">E-mail</label>
            <div class="login-field__wrap">
              <svg class="login-field__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                   stroke-width="1.75" stroke-linecap="round" width="18" height="18" aria-hidden="true">
                <rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 5L2 7"/>
              </svg>
              <input type="email" id="email" name="email" class="login-field__input"
                     required autocomplete="email" autofocus inputmode="email"
                     placeholder="voce@sportmoto.com.br"
                     value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>
          </div>

          <div class="login-field">
            <label for="senha" class="login-field__label">Senha</label>
            <div class="login-field__wrap">
              <svg class="login-field__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                   stroke-width="1.75" stroke-linecap="round" width="18" height="18" aria-hidden="true">
                <rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
              </svg>
              <input type="password" id="senha" name="senha" class="login-field__input"
                     required autocomplete="current-password" placeholder="••••••••••">
              <button type="button" class="login-field__toggle" id="togglePwd"
                      aria-label="Mostrar senha" tabindex="0">
                <svg class="icon-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.75" stroke-linecap="round" width="18" height="18" aria-hidden="true">
                  <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>
                </svg>
                <svg class="icon-eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.75" stroke-linecap="round" width="18" height="18" aria-hidden="true" hidden>
                  <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c6.5 0 10 7 10 7a13.2 13.2 0 0 1-1.67 2.68"/>
                  <path d="M6.61 6.61A13.5 13.5 0 0 0 2 12s3.5 7 10 7a9.1 9.1 0 0 0 5.39-1.61"/>
                  <path d="m2 2 20 20"/>
                </svg>
              </button>
            </div>
          </div>

          <button type="submit" class="login-submit" id="loginSubmit">
            <span class="login-submit__label">Entrar no painel</span>
            <span class="login-submit__spinner" aria-hidden="true"></span>
          </button>
        </form>

        <footer class="login-card__foot">
          <a href="<?= htmlspecialchars(defined('BASE_URL') ? BASE_URL : '/', ENT_QUOTES, 'UTF-8') ?>"
             class="login-card__back">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" width="15" height="15" aria-hidden="true">
              <path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>
            </svg>
            Voltar para a loja
          </a>
        </footer>

      </div>
    </section>
  </main>

  <script src="<?= View::asset('js/admin-login.js') ?>"></script>
</body>
</html>