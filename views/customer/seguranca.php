<?php // views/customer/seguranca.php ?>
<div class="security-page">
  <div class="security-header">
    <h1>Segurança da conta</h1>
    <p>Gerencie como você confirma sua identidade ao entrar.</p>
  </div>

  <div class="security-card">
    <div class="security-card-header">
      <div class="security-card-icon">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <rect x="3" y="3" width="18" height="18" rx="3"/>
          <circle cx="12" cy="12" r="3"/>
          <line x1="12" y1="6" x2="12" y2="9"/>
        </svg>
      </div>
      <div class="security-card-info">
        <h2>App autenticador</h2>
        <p>Google Authenticator, Authy, Microsoft Authenticator ou similar.</p>
      </div>
      <span class="security-status security-status--<?= $totpAtivo ? 'on' : 'off' ?>">
        <?= $totpAtivo ? 'Ativo' : 'Inativo' ?>
      </span>
    </div>

    <?php if ($totpAtivo): ?>
    <!-- ── Estado: já ativo ───────────────────────────── -->
    <div class="security-card-body" id="totp-ativo-view">
      <p class="security-note">
        Você tem <strong id="codigos-restantes-count"><?= $codigosRestantes ?></strong>
        código(s) de backup não usados.
      </p>
      <div class="security-actions">
        <button type="button" class="btn btn-ghost" id="btn-regenerar-backup">
          Gerar novos códigos de backup
        </button>
        <button type="button" class="btn btn-danger-ghost" id="btn-abrir-desativar">
          Desativar
        </button>
      </div>
    </div>

    <!-- Confirmação de desativação (senha) -->
    <div class="security-inline-form" id="form-desativar" style="display:none;">
      <label class="pe-label">Confirme sua senha para desativar</label>
      <input type="password" id="desativar-senha" class="form-control" placeholder="Sua senha atual">
      <div class="security-actions">
        <button type="button" class="btn btn-ghost" id="btn-cancelar-desativar">Cancelar</button>
        <button type="button" class="btn btn-danger" id="btn-confirmar-desativar">Desativar</button>
      </div>
      <span class="field-error" id="err-desativar"></span>
    </div>

    <?php else: ?>
    <!-- ── Estado: inativo — fluxo de setup ──────────────── -->
    <div class="security-card-body" id="totp-setup-view">
      <p class="security-note">
        Adicione uma camada extra de segurança usando um app autenticador
        no seu celular.
      </p>
      <button type="button" class="btn btn-primary" id="btn-iniciar-setup">
        Ativar app autenticador
      </button>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ── Modal: setup (QR code + confirmação) ──────────────── -->
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

<style>
.security-page { max-width: 680px; }
.security-header { margin-bottom: 24px; }
.security-header h1 { font-size: 22px; margin: 0 0 4px; }
.security-header p { color: #64748b; font-size: 14px; margin: 0; }

.security-card {
  border: 1px solid var(--c-border, #e2e8f0);
  border-radius: 14px;
  overflow: hidden;
  background: #fff;
}
.security-card-header {
  display: flex; align-items: center; gap: 14px;
  padding: 18px 20px;
  border-bottom: 1px solid var(--c-border, #eef0f3);
}
.security-card-icon {
  width: 42px; height: 42px; border-radius: 10px;
  background: #eff6ff; color: #2563eb;
  display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.security-card-info { flex: 1; }
.security-card-info h2 { font-size: 15px; margin: 0 0 2px; }
.security-card-info p { font-size: 12.5px; color: #94a3b8; margin: 0; }

.security-status {
  font-size: 11px; font-weight: 700; text-transform: uppercase;
  padding: 4px 10px; border-radius: 99px; flex-shrink: 0;
}
.security-status--on  { background: #dcfce7; color: #16a34a; }
.security-status--off { background: #f1f5f9; color: #64748b; }

.security-card-body { padding: 18px 20px; }
.security-note { font-size: 13.5px; color: #64748b; margin: 0 0 14px; }
.security-note--warn { background: #fffbeb; border: 1px solid #fde68a; padding: 10px 14px; border-radius: 8px; color: #92400e; }

.security-actions { display: flex; gap: 10px; }
.security-inline-form { padding: 0 20px 20px; }

.btn-danger-ghost { color: #dc2626; background: none; border: 1px solid #fecaca; }
.btn-danger-ghost:hover { background: #fef2f2; }
.btn-danger { background: #dc2626; color: #fff; border: none; }

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

.totp-backup-grid {
  display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px;
  margin-top: 12px;
}
.totp-backup-grid span {
  font-family: monospace; font-size: 13.5px;
  background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px;
  padding: 8px 10px; text-align: center; letter-spacing: 0.5px;
}
</style>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
window.SECURITY_CONFIG = {
  csrfToken: '<?= SecurityHelper::generateCsrf() ?>',
};
</script>
