<?php
// views/customer/sessions.php
// Sessões ativas + métodos de login + últimos acessos
// Mantém os nomes/variáveis já existentes: $sessions, $perfil, $twofa_ativo

AuthHelper::requireCustomer();
$usuarioId = (int)Session::get('usuario_id');
$clienteId = (int)Session::get('cliente_id');
$db        = Database::getInstance()->getConnection();

// ── Vínculos sociais (Google) ─────────────────────────────
$vinculos  = (new GoogleAuthService())->listarVinculos($usuarioId);
$temGoogle = !empty(array_filter($vinculos, fn($v) => $v['provider'] === 'google'));
$stmt2     = $db->prepare("SELECT senha_definida FROM usuarios WHERE id = ? LIMIT 1");
$stmt2->execute([$usuarioId]);
$temSenha  = (bool)$stmt2->fetchColumn();

// ── TOTP (app autenticador) ───────────────────────────────
$totpService      = new TotpService();
$totpAtivo        = $totpService->isAtivo($usuarioId);
$codigosRestantes = $totpAtivo ? $totpService->contarCodigosBackupRestantes($usuarioId) : 0;

// ── Últimos acessos (login_attempts — Sprint 2/4) ─────────
$emailHash = hash('sha256', mb_strtolower(trim($perfil['email'])));
$stmtAc = $db->prepare(
    "SELECT ip, tipo, sucesso, user_agent, criado_em
     FROM login_attempts
     WHERE email_hash = ?
     ORDER BY criado_em DESC
     LIMIT 15"
);
$stmtAc->execute([$emailHash]);
$acessos = array_map(function ($a) {
    $a['ip_str']      = @inet_ntop($a['ip']) ?: '—';
    $a['dispositivo'] = SessionManager::parseUserAgent($a['user_agent'] ?? '');
    return $a;
}, $stmtAc->fetchAll());

$tipoLabels = [
    'senha'        => 'Senha',
    'codigo_email' => 'Código por e-mail',
    '2fa'          => 'Verificação 2FA',
    'google'       => 'Google',
];
?>
<div class="customer-page">
  <div class="customer-page-header">
    <h1>Sessões e segurança</h1>
  </div>

  <!-- ── 2FA Card ──────────────────────────────────────── -->
  <div class="customer-section" style="margin-bottom:16px;">
    <div class="twofa-row">
      <div class="twofa-info">
        <div class="twofa-icon <?= $twofa_ativo ? 'twofa-icon--on' : 'twofa-icon--off' ?>">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
          </svg>
        </div>
        <div>
          <strong>Autenticação em dois fatores (2FA)</strong>
          <p>
            <?= $twofa_ativo
                ? 'Ativado. Ações sensíveis e o login exigem verificação por e-mail.'
                : 'Desativado. Recomendamos ativar para maior segurança.' ?>
          </p>
        </div>
      </div>
      <button type="button" id="btn-toggle-2fa"
              class="btn <?= $twofa_ativo ? 'btn-outline' : 'btn-primary' ?> btn-sm">
        <?= $twofa_ativo ? 'Desativar 2FA' : 'Ativar 2FA' ?>
      </button>
    </div>
  </div>

  <!-- ── Métodos de login ──────────────────────────────── -->
  <section class="sess-section">
    <h2 class="sess-section-title">Métodos de login</h2>
    <div class="sess-methods">
      <div class="sess-method-card">
        <div class="sess-method-icon sess-method-icon--local">
          <?= IconLibrary::render('person-shield', 'icon icon--md') ?>
        </div>
        <div class="sess-method-info">
          <strong>E-mail e senha</strong>
          <span><?= htmlspecialchars(Session::get('cliente_email')) ?></span>
        </div>
        <div class="sess-method-status">
          <?php if ($temSenha): ?>
            <span class="sess-badge sess-badge--ok">Ativo</span>
          <?php else: ?>
            <a href="<?= BASE_URL ?>/recuperar-senha" class="sess-badge sess-badge--warn">Definir senha</a>
          <?php endif; ?>
        </div>
      </div>

      <div class="sess-method-card" id="sess-google-card">
        <div class="sess-method-icon sess-method-icon--google">
          <?= IconLibrary::render('google-main', 'icon icon--md') ?>
        </div>
        <div class="sess-method-info">
          <strong>Google</strong>
          <?php if ($temGoogle):
            $gv = current(array_filter($vinculos, fn($v) => $v['provider'] === 'google')); ?>
            <span><?= htmlspecialchars($gv['provider_email']) ?></span>
            <small>Conectado em <?= date('d/m/Y', strtotime($gv['criado_em'])) ?></small>
          <?php else: ?>
            <span class="sess-not-connected">Não conectado</span>
          <?php endif; ?>
        </div>
        <div class="sess-method-status">
          <?php if ($temGoogle): ?>
            <button type="button" class="sess-btn-desvincular" id="btn-desvincular-google">Desconectar</button>
          <?php else: ?>
            <button type="button" class="sess-btn-vincular" id="btn-vincular-google">Conectar Google</button>
          <?php endif; ?>
        </div>
      </div>

      <div class="sess-method-card" id="sess-totp-card">
        <div class="sess-method-icon sess-method-icon--totp">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="3" width="18" height="18" rx="3"/>
            <circle cx="12" cy="12" r="3"/>
            <line x1="12" y1="6" x2="12" y2="9"/>
          </svg>
        </div>
        <div class="sess-method-info">
          <strong>App autenticador</strong>
          <?php if ($totpAtivo): ?>
            <span>Google Authenticator, Authy, etc.</span>
            <small><?= $codigosRestantes ?> código(s) de backup restantes</small>
          <?php else: ?>
            <span class="sess-not-connected">Não configurado</span>
          <?php endif; ?>
        </div>
        <div class="sess-method-status">
          <?php if ($totpAtivo): ?>
            <button type="button" class="sess-btn-vincular" id="btn-regenerar-backup">Novos códigos</button>
            <button type="button" class="sess-btn-desvincular" id="btn-abrir-desativar-totp">Desativar</button>
          <?php else: ?>
            <button type="button" class="sess-btn-vincular" id="btn-iniciar-setup-totp">Ativar</button>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php if (!$temGoogle): ?>
    <div id="g_id_onload"
         data-client_id="<?= GOOGLE_CLIENT_ID ?>"
         data-context="use"
         data-callback="onGoogleVincular"
         data-auto_prompt="false"
         style="display:none;"></div>
    <?php endif; ?>

    <p class="sess-security-note">
      Para conectar o Google a esta conta você deve estar logado com e-mail e senha.
      Se fizer login com o Google usando outro e-mail uma conta separada será criada.
    </p>
  </section>

  <!-- ── Sessões ativas ────────────────────────────────── -->
  <div class="customer-section">
    <div class="customer-section-header">
      <h2>Sessões ativas</h2>
      <?php if (count($sessions) > 1): ?>
      <button type="button" id="btn-revoke-all" class="btn btn-outline btn-sm">
        Encerrar outras sessões
      </button>
      <?php endif; ?>
    </div>

    <?php if (empty($sessions)): ?>
    <div class="empty-state" style="padding:32px 0">
      <p>Nenhuma sessão ativa encontrada.</p>
      <span style="font-size:13px;color:var(--c-text-muted)">
        Sessões são criadas quando você usa "lembrar de mim" no login.
      </span>
    </div>
    <?php else: ?>
    <div class="sessions-list" id="sessions-list">
      <?php foreach ($sessions as $s): ?>
      <div class="session-item <?= $s['atual'] ? 'session-item--current' : '' ?>"
           id="session-item-<?= (int)$s['id'] ?>">
        <div class="session-icon">
          <?php
          $ua = strtolower($s['user_agent'] ?? '');
          $isMobile = str_contains($ua, 'mobile') || str_contains($ua, 'android') || str_contains($ua, 'iphone');
          ?>
          <?php if ($isMobile): ?>
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <rect x="5" y="2" width="14" height="20" rx="2" ry="2"/>
            <line x1="12" y1="18" x2="12.01" y2="18"/>
          </svg>
          <?php else: ?>
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/>
            <line x1="8" y1="21" x2="16" y2="21"/>
            <line x1="12" y1="17" x2="12" y2="21"/>
          </svg>
          <?php endif; ?>
        </div>

        <div class="session-info">
          <strong class="session-device"><?= View::e($s['dispositivo']) ?></strong>
          <span class="session-meta">
            <span>IP: <?= View::e($s['ip'] ?? 'Desconhecido') ?></span>
            <span>Última atividade: <?= View::e($s['ultima_fmt']) ?></span>
            <span>Criada em: <?= date('d/m/Y H:i', strtotime($s['criado_em'])) ?></span>
          </span>
        </div>

        <div class="session-actions">
          <?php if ($s['atual']): ?>
            <span class="session-current-badge">Esta sessão</span>
          <?php else: ?>
            <button type="button" class="btn-link btn-link--danger btn-revoke-session"
                    data-id="<?= (int)$s['id'] ?>">
              Encerrar
            </button>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <p class="sessions-hint">
      Se você não reconhece alguma sessão, encerre-a imediatamente e
      <a href="<?= BASE_URL ?>/minha-conta/perfil">altere sua senha</a>.
    </p>
  </div>

  <!-- ── Últimos acessos ───────────────────────────────── -->
  <div class="customer-section">
    <div class="customer-section-header">
      <h2>Últimos acessos</h2>
    </div>

    <?php if (empty($acessos)): ?>
    <div class="empty-state" style="padding:24px 0">
      <p>Nenhum acesso registrado ainda.</p>
    </div>
    <?php else: ?>
    <div class="access-list">
      <?php foreach ($acessos as $a): ?>
      <div class="access-item">
        <div class="access-status <?= $a['sucesso'] ? 'is-ok' : 'is-fail' ?>">
          <?php if ($a['sucesso']): ?>
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="3" stroke-linecap="round">
            <polyline points="20 6 9 17 4 12"/>
          </svg>
          <?php else: ?>
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="3" stroke-linecap="round">
            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
          </svg>
          <?php endif; ?>
        </div>
        <div class="access-info">
          <strong><?= $a['sucesso'] ? 'Acesso realizado' : 'Tentativa falhou' ?></strong>
          <span class="access-meta">
            <span class="access-tipo"><?= $tipoLabels[$a['tipo']] ?? $a['tipo'] ?></span>
            <span><?= View::e($a['dispositivo'] ?: 'Desconhecido') ?></span>
            <span>IP: <?= View::e($a['ip_str']) ?></span>
          </span>
        </div>
        <div class="access-data">
          <?= date('d/m H:i', strtotime($a['criado_em'])) ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ── Modal: setup TOTP (QR code + confirmação) ─────────── -->
<div class="pe-modal-backdrop" id="modal-totp-setup" style="display:none;">
  <div class="pe-modal">
    <div class="pe-modal-header">
      <h3>Configurar app autenticador</h3>
      <button type="button" class="pe-modal-close" id="btn-close-totp-setup">×</button>
    </div>
    <div class="pe-modal-body">

      <!-- Etapa 1: escanear QR code -->
      <div id="totp-step-qr">
        <ol class="totp-steps">
          <li>Abra seu app autenticador (Google Authenticator, Authy, etc.)</li>
          <li>Escaneie o código abaixo ou digite a chave manualmente</li>
          <li>Digite o código de 6 dígitos gerado pelo app para confirmar</li>
        </ol>

        <div class="totp-qr-wrap" id="totp-qr-canvas"></div>

        <details class="totp-manual">
          <summary>Não consigo escanear, quero digitar a chave</summary>
          <code id="totp-secret-text"></code>
        </details>

        <div class="form-group" style="margin-top:16px;">
          <label class="pe-label">Código do app</label>
          <input type="text" id="totp-confirm-code" class="form-control form-control--lg otp-input"
                 placeholder="000000" maxlength="6" inputmode="numeric" pattern="[0-9]{6}">
          <span class="field-error" id="err-totp-confirm"></span>
        </div>

        <button type="button" class="btn btn-primary btn-full" id="btn-confirmar-totp">
          Confirmar e ativar
        </button>
      </div>

      <!-- Etapa 2: códigos de backup (mostrados uma única vez) -->
      <div id="totp-step-backup" style="display:none;">
        <div class="totp-success-banner">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
          App autenticador ativado com sucesso!
        </div>

        <p class="security-note security-note--warn">
          Guarde estes códigos em um lugar seguro. Cada um pode ser usado
          <strong>uma única vez</strong> para entrar caso você perca acesso
          ao seu celular. Eles não serão exibidos novamente.
        </p>

        <div class="totp-backup-grid" id="totp-backup-codes"></div>

        <button type="button" class="btn btn-ghost btn-full" id="btn-copiar-backup" style="margin-top:12px;">
          Copiar todos os códigos
        </button>
        <button type="button" class="btn btn-primary btn-full" id="btn-fechar-backup" style="margin-top:8px;">
          Já guardei, concluir
        </button>
      </div>

    </div>
  </div>
</div>

<!-- ── Modal: desativar TOTP (confirmação por código e-mail) ── -->
<div class="pe-modal-backdrop" id="modal-totp-desativar" style="display:none;">
  <div class="pe-modal" style="max-width:400px;">
    <div class="pe-modal-header">
      <h3>Desativar app autenticador</h3>
      <button type="button" class="pe-modal-close" id="btn-close-totp-desativar">×</button>
    </div>
    <div class="pe-modal-body">

      <!-- Etapa 1: aviso + envio do código -->
      <div id="totp-desativar-step-aviso">
        <p style="font-size:13.5px;color:var(--c-text-muted);margin-bottom:18px;">
          Para desativar o app autenticador, vamos enviar um código de
          verificação para o seu e-mail. Os códigos de backup atuais
          também serão invalidados.
        </p>
        <div class="security-actions">
          <button type="button" class="btn btn-ghost" id="btn-cancelar-totp-desativar">Cancelar</button>
          <button type="button" class="btn btn-primary" id="btn-enviar-codigo-desativar">Enviar código</button>
        </div>
      </div>

      <!-- Etapa 2: digitar o código recebido -->
      <div id="totp-desativar-step-codigo" style="display:none;">
        <p style="font-size:13.5px;color:var(--c-text-muted);margin-bottom:16px;">
          Enviamos um código de 6 dígitos para o seu e-mail.
        </p>
        <div class="form-group">
          <label class="pe-label">Código de verificação</label>
          <input type="text" id="totp-desativar-codigo" class="form-control otp-input"
                 placeholder="000000" maxlength="6" inputmode="numeric" pattern="[0-9]{6}">
          <span class="field-error" id="err-totp-desativar"></span>
        </div>
        <div class="security-actions" style="margin-top:14px;">
          <button type="button" class="btn btn-ghost" id="btn-voltar-totp-desativar">Voltar</button>
          <button type="button" class="btn btn-danger" id="btn-confirmar-totp-desativar">Desativar</button>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- Modal 2FA (mantido do original) -->
<div class="modal-backdrop" id="modal-2fa-backdrop" style="display:none;">
  <div class="modal" id="modal-2fa" style="max-width:400px;">
    <div class="modal-header">
      <h3>Verificação de segurança</h3>
      <button type="button" class="modal-close" id="btn-close-2fa">×</button>
    </div>
    <div class="modal-body">
      <p style="font-size:14px;color:var(--c-text-muted);margin-bottom:20px;line-height:1.6;">
        Enviamos um código de 6 dígitos para o seu e-mail.
        Insira o código para confirmar a ação.
      </p>
      <form id="form-2fa-verify" novalidate>
        <?= SecurityHelper::csrfField() ?>
        <div class="form-group" style="margin-bottom:20px">
          <label for="input-2fa-code">Código de verificação</label>
          <input type="text" id="input-2fa-code" name="code"
                 class="form-control otp-input"
                 placeholder="000000" maxlength="6"
                 inputmode="numeric" pattern="[0-9]{6}"
                 autocomplete="one-time-code" required>
          <span class="field-error" id="err-2fa-code"></span>
        </div>
        <div id="2fa-msg" class="form-alert" style="display:none;"></div>
        <div class="modal-footer" style="padding-top:0">
          <button type="button" class="btn btn-outline" id="btn-cancel-2fa">Cancelar</button>
          <button type="submit" class="btn btn-primary" id="btn-submit-2fa">Verificar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<style>
/* ── Últimos acessos ───────────────────────────────────── */
.access-list { display: flex; flex-direction: column; }
.access-item {
  display: flex; align-items: center; gap: 12px;
  padding: 12px 0;
  border-bottom: 1px solid var(--c-border, #eef0f3);
}
.access-item:last-child { border-bottom: none; }
.access-status {
  width: 26px; height: 26px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.access-status.is-ok   { background: #dcfce7; color: #16a34a; }
.access-status.is-fail { background: #fee2e2; color: #dc2626; }
.access-info { flex: 1; min-width: 0; }
.access-info strong { display: block; font-size: 13.5px; }
.access-meta {
  display: flex; gap: 10px; flex-wrap: wrap;
  font-size: 12px; color: var(--c-text-muted, #9ca3af); margin-top: 2px;
}
.access-tipo {
  font-weight: 700; color: #6b7280;
  background: #f3f4f6; padding: 1px 7px; border-radius: 99px;
}
.access-data {
  font-size: 12px; color: var(--c-text-muted, #9ca3af);
  white-space: nowrap; flex-shrink: 0;
}

/* ── App autenticador (TOTP) ──────────────────────────── */
.sess-method-icon--totp { background: #eff6ff; color: #2563eb; }

.totp-steps { font-size: 13px; color: #475569; padding-left: 20px; margin: 0 0 16px; }
.totp-steps li { margin-bottom: 4px; }

.totp-qr-wrap {
  display: flex; justify-content: center;
  padding: 16px; background: #f8fafc; border-radius: 12px; margin-bottom: 12px;
}

.totp-manual { font-size: 12.5px; color: #64748b; margin-bottom: 8px; }
.totp-manual summary { cursor: pointer; }
.totp-manual code {
  display: block; margin-top: 8px; padding: 8px 10px;
  background: #f1f5f9; border-radius: 6px; font-size: 13px;
  word-break: break-all; user-select: all;
}

.totp-success-banner {
  display: flex; align-items: center; gap: 8px;
  background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 10px;
  padding: 10px 14px; margin-bottom: 14px;
  font-size: 13px; color: #15803d; font-weight: 600;
}

.security-note { font-size: 13.5px; color: #64748b; margin: 0 0 14px; }
.security-note--warn { background: #fffbeb; border: 1px solid #fde68a; padding: 10px 14px; border-radius: 8px; color: #92400e; }
.security-actions { display: flex; gap: 10px; }

.totp-backup-grid {
  display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px;
  margin-top: 12px;
}
.totp-backup-grid span {
  font-family: monospace; font-size: 13.5px;
  background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px;
  padding: 8px 10px; text-align: center; letter-spacing: 0.5px;
}

.btn-danger { background: #dc2626; color: #fff; border: none; }

/* ── Modais (backdrop + container) ────────────────────── */
/* Cobre os dois padrões usados nesta página: .pe-modal-* (TOTP) e
   .modal-* (2FA por e-mail, já existente antes desta mudança) —
   nenhum dos dois tinha CSS definido em lugar algum do projeto. */
.pe-modal-backdrop,
.modal-backdrop {
  position: fixed; top: 0; left: 0; right: 0; bottom: 0;
  background: rgba(15, 23, 42, 0.55);
  display: flex; align-items: center; justify-content: center;
  padding: 20px;
  z-index: 1000;
}

.pe-modal,
.modal {
  background: #fff;
  border-radius: 16px;
  width: 100%;
  max-width: 480px;
  max-height: 90vh;
  overflow-y: auto;
  box-shadow: 0 20px 60px rgba(15, 23, 42, 0.25);
  animation: modal-pop-in 0.18s ease-out;
}

@keyframes modal-pop-in {
  from { opacity: 0; transform: scale(0.96) translateY(8px); }
  to   { opacity: 1; transform: scale(1) translateY(0); }
}

.pe-modal-header,
.modal-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 18px 22px;
  border-bottom: 1px solid var(--c-border, #eef0f3);
}
.pe-modal-header h3,
.modal-header h3 {
  font-size: 16px; font-weight: 700; margin: 0;
  color: var(--c-heading, #1e293b);
}

.pe-modal-close,
.modal-close {
  width: 28px; height: 28px; border-radius: 8px;
  border: none; background: none;
  font-size: 20px; line-height: 1; color: #94a3b8;
  cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  transition: background 0.15s, color 0.15s;
}
.pe-modal-close:hover,
.modal-close:hover { background: #f1f5f9; color: #475569; }

.pe-modal-body,
.modal-body {
  padding: 22px;
}

.modal-footer {
  display: flex; justify-content: flex-end; gap: 10px;
  padding: 18px 22px;
  border-top: 1px solid var(--c-border, #eef0f3);
}

.form-alert {
  padding: 10px 14px;
  border-radius: 8px;
  font-size: 13px;
  background: #fef2f2;
  color: #dc2626;
  border: 1px solid #fecaca;
  margin-bottom: 14px;
}
</style>

<script>
window.SESS_CONFIG = {
  base:      '<?= BASE_URL ?>',
  csrf:      '<?= SecurityHelper::generateCsrf() ?>',
  temGoogle: <?= $temGoogle ? 'true' : 'false' ?>,
  temTotp:   <?= $totpAtivo ? 'true' : 'false' ?>,
};
</script>
<script src="https://accounts.google.com/gsi/client" async defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<!-- <script src="<?= BASE_URL ?>/assets/js/google-auth.js" defer></script> -->
<!-- <script src="<?= BASE_URL ?>/assets/js/totp-sessions.js" defer></script> -->