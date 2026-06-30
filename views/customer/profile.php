<?php
// views/customer/profile.php
?>
<?php
// Adicionar no topo de views/customer/profile.php, após o header da página
// ou passe como variável do controller: $doc_status
?>

<!-- Bloco de verificação de perfil -->
<div class="verification-card <?= $doc_status['verificado'] ? 'verification-card--verified' : 'verification-card--unverified' ?>">

  <div class="verification-icon">
    <?php if ($doc_status['verificado']): ?>
    <svg width="28" height="28" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="2" stroke-linecap="round">
      <path d="M22 11.08V12a10 10 0 11-5.93-9.14"/>
      <polyline points="22 4 12 14.01 9 11.01"/>
    </svg>
    <?php elseif ($doc_status['status'] === 'em_analise'): ?>
    <svg width="28" height="28" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="2" stroke-linecap="round">
      <circle cx="12" cy="12" r="10"/>
      <polyline points="12 6 12 12 16 14"/>
    </svg>
    <?php elseif ($doc_status['status'] === 'rejeitado'): ?>
    <svg width="28" height="28" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="2" stroke-linecap="round">
      <circle cx="12" cy="12" r="10"/>
      <line x1="15" y1="9" x2="9" y2="15"/>
      <line x1="9" y1="9" x2="15" y2="15"/>
    </svg>
    <?php else: ?>
    <svg width="28" height="28" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="2" stroke-linecap="round">
      <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/>
      <circle cx="12" cy="7" r="4"/>
    </svg>
    <?php endif; ?>
  </div>

  <div class="verification-info">
    <?php if ($doc_status['verificado']): ?>
      <strong class="verification-title">Perfil verificado</strong>
      <span class="verification-sub">
        Verificado em <?= date('d/m/Y', strtotime($doc_status['verificado_em'])) ?>
      </span>

    <?php elseif ($doc_status['status'] === 'em_analise'): ?>
      <strong class="verification-title">Documento em análise</strong>
      <span class="verification-sub">Aguarde — a análise é automática e leva poucos segundos.</span>
      <div class="verification-progress" id="verification-progress">
        <div class="verification-progress-bar"></div>
      </div>

    <?php elseif ($doc_status['status'] === 'rejeitado'): ?>
      <strong class="verification-title">Documento rejeitado</strong>
      <span class="verification-sub"><?= View::e($doc_status['motivo']) ?></span>

    <?php else: ?>
      <strong class="verification-title">Perfil não verificado</strong>
      <span class="verification-sub">
        Verifique sua identidade para mais segurança e acesso a recursos exclusivos.
      </span>
    <?php endif; ?>
  </div>

  <?php if (!$doc_status['verificado'] && $doc_status['status'] !== 'em_analise'): ?>
  <button type="button" class="btn btn-primary btn-sm" id="btn-open-verify">
    <?= $doc_status['status'] === 'rejeitado' ? 'Tentar novamente' : 'Verificar agora' ?>
  </button>
  <?php endif; ?>

</div>

<!-- Modal de verificação -->
<div class="modal-backdrop" id="modal-verify-backdrop" style="display:none;">
  <div class="modal" id="modal-verify" style="max-width:520px;">

    <div class="modal-header">
      <h3>Verificar identidade</h3>
      <button type="button" class="modal-close" id="btn-close-verify">×</button>
    </div>

    <div class="modal-body">

      <!-- Informações de privacidade -->
      <div class="verify-privacy-notice">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
        </svg>
        <div>
          <strong>Seus dados estão protegidos</strong>
          <p>
            A imagem do documento é <strong>encriptada com AES-256</strong> antes de ser armazenada
            e <strong>nunca é salva em disco</strong>. A análise é 100% automática e o arquivo
            original é descartado após o processamento. Apenas você pode solicitar a exclusão.
          </p>
        </div>
      </div>

      <!-- Escolha do método -->
      <div class="verify-method-tabs" id="verify-method-tabs">
        <button class="verify-tab active" data-method="desktop">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <rect x="2" y="3" width="20" height="14" rx="2"/>
            <line x1="8" y1="21" x2="16" y2="21"/>
            <line x1="12" y1="17" x2="12" y2="21"/>
          </svg>
          Enviar arquivo
        </button>
        <button class="verify-tab" data-method="mobile">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <rect x="5" y="2" width="14" height="20" rx="2" ry="2"/>
            <line x1="12" y1="18" x2="12.01" y2="18"/>
          </svg>
          Tirar foto com celular
        </button>
      </div>

      <!-- Método: Upload desktop -->
      <div class="verify-panel" id="verify-panel-desktop">
        <form id="form-verify-doc" enctype="multipart/form-data" novalidate>
          <?= SecurityHelper::csrfField() ?>

          <div class="form-group" style="margin-bottom:16px;">
            <label>Tipo de documento</label>
            <div class="doc-type-options">
              <label class="doc-type-option">
                <input type="radio" name="tipo" value="rg" checked>
                <span>RG</span>
              </label>
              <label class="doc-type-option">
                <input type="radio" name="tipo" value="cnh">
                <span>CNH</span>
              </label>
            </div>
          </div>

          <!-- Área de upload -->
          <div class="doc-upload-area" id="doc-upload-area">
            <input type="file" id="doc-file-input" name="documento"
                   accept="image/jpeg,image/png,image/webp"
                   style="display:none;" capture="environment">

            <div class="doc-upload-placeholder" id="doc-upload-placeholder">
              <svg width="40" height="40" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
                <path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/>
                <circle cx="12" cy="13" r="4"/>
              </svg>
              <p>Arraste ou <button type="button" id="btn-choose-doc">clique aqui</button></p>
              <span>JPG, PNG ou WEBP • Máximo 10MB</span>
            </div>

            <div class="doc-upload-preview" id="doc-upload-preview" style="display:none;">
              <img id="doc-preview-img" src="" alt="Preview do documento">
              <button type="button" class="doc-remove-btn" id="doc-remove-btn">×</button>
            </div>
          </div>

          <!-- Dicas de como fotografar -->
          <div class="doc-tips">
            <p class="doc-tips-title">Como garantir a aprovação:</p>
            <ul>
              <li>Fundo claro e sem reflexos</li>
              <li>Documento centralizado e sem cortes</li>
              <li>Imagem nítida e bem iluminada</li>
              <li>Resolução mínima de 600×400 pixels</li>
            </ul>
          </div>

          <div id="verify-error" class="form-alert form-alert--error" style="display:none;"></div>

          <div class="modal-footer" style="padding-top:16px;">
            <button type="button" class="btn btn-outline"
                    id="btn-cancel-verify">Cancelar</button>
            <button type="submit" class="btn btn-primary"
                    id="btn-submit-doc" disabled>
              Enviar e verificar
            </button>
          </div>
        </form>
      </div>

      <!-- Método: QR Code mobile -->
      <div class="verify-panel" id="verify-panel-mobile" style="display:none;">
        <div id="qr-loading" style="text-align:center;padding:24px 0;">
          <p style="color:var(--c-text-muted);font-size:14px;">Gerando QR Code...</p>
        </div>

        <div id="qr-content" style="display:none;">
          <p class="qr-desc">
            Escaneie o QR code abaixo com o seu celular para tirar a foto do documento.
            O link expira em <strong id="qr-expiry">30</strong> minutos.
          </p>

          <div class="qr-code-wrapper">
            <div id="qr-code-container"></div>
          </div>

          <div class="qr-url-copy">
            <input type="text" id="qr-url-input" class="form-control"
                   readonly style="font-size:12px;">
            <button type="button" class="btn btn-outline btn-sm" id="btn-copy-qr-url">
              Copiar link
            </button>
          </div>

          <div class="qr-waiting" id="qr-waiting">
            <div class="qr-waiting-spinner"></div>
            <span>Aguardando envio do documento pelo celular...</span>
          </div>

          <button type="button" class="btn btn-outline btn-sm btn-full" id="btn-regen-qr"
                  style="margin-top:12px;">
            Gerar novo QR Code
          </button>
        </div>
      </div>

      <!-- Estado de análise em progresso -->
      <div id="verify-analyzing" style="display:none;text-align:center;padding:32px 0;">
        <div class="analyzing-spinner"></div>
        <h4 style="margin:16px 0 8px;color:var(--c-dark);">Analisando documento...</h4>
        <p style="font-size:14px;color:var(--c-text-muted);">
          A análise é automática e leva apenas alguns segundos.
        </p>
      </div>

      <!-- Resultado -->
      <div id="verify-result" style="display:none;text-align:center;padding:24px 0;">
        <div id="result-icon"></div>
        <h4 id="result-title" style="margin:16px 0 8px;"></h4>
        <p id="result-msg" style="font-size:14px;color:var(--c-text-muted);"></p>
        <button type="button" class="btn btn-primary" id="btn-verify-done"
                style="margin-top:20px;">Concluir</button>
      </div>

    </div>
  </div>
</div>


<div class="customer-page">
  <div class="customer-page-header">
    <h1>Meu perfil</h1>
  </div>

  <div class="profile-layout">

    <!-- Avatar -->
    <div class="profile-avatar-section">
      <div class="profile-avatar-wrapper">
        <?php
        $avatarUrl = !empty($perfil['avatar'])
                     ? View::upload('avatars/' . $perfil['avatar'])
                     : null;
        ?>
        <?php if ($avatarUrl): ?>
          <img src="<?= $avatarUrl ?>" alt="" class="profile-avatar" id="avatar-preview">
        <?php else: ?>
          <div class="profile-avatar profile-avatar--initial" id="avatar-preview-initial">
            <?= strtoupper(mb_substr($perfil['nome'], 0, 1)) ?>
          </div>
        <?php endif; ?>
        <label class="avatar-upload-btn" for="avatar-input" title="Alterar foto">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/>
            <circle cx="12" cy="13" r="4"/>
          </svg>
        </label>
        <input type="file" id="avatar-input" name="avatar"
               accept="image/jpeg,image/png,image/webp" style="display:none;">
      </div>
      <p class="avatar-hint">JPG, PNG ou WEBP. Máx. 5MB.</p>
    </div>

    <!-- Dados -->
    <div class="profile-form-section">
      <form id="form-profile" novalidate enctype="multipart/form-data">
        <?= SecurityHelper::csrfField() ?>

        <div class="profile-form-group">
          <h3>Dados pessoais</h3>

          <div class="form-group">
            <label for="p-nome">Nome completo <span class="required">*</span></label>
            <input type="text" id="p-nome" name="nome" class="form-control"
                   value="<?= View::e($perfil['nome']) ?>" required>
            <span class="field-error" id="err-p-nome"></span>
          </div>

          <div class="form-row">
            <div class="form-group form-col">
              <label for="p-email">E-mail</label>
              <input type="email" id="p-email" class="form-control"
                     value="<?= View::e($perfil['email']) ?>" disabled>
              <span class="field-hint">Para alterar o e-mail, entre em contato com o suporte.</span>
            </div>
            <div class="form-group form-col">
              <label for="p-cpf">CPF</label>
              <input type="text" id="p-cpf" name="cpf" class="form-control cpf-mask"
                     value="<?= View::e($perfil['cpf'] ? preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $perfil['cpf']) : '') ?>"
                     placeholder="000.000.000-00" maxlength="14">
              <span class="field-error" id="err-p-cpf"></span>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group form-col">
              <label for="p-tel">Telefone</label>
              <input type="tel" id="p-tel" name="telefone" class="form-control phone-mask"
                     value="<?= View::e($perfil['telefone'] ?? '') ?>"
                     placeholder="(00) 0000-0000">
            </div>
            <div class="form-group form-col">
              <label for="p-cel">Celular</label>
              <input type="tel" id="p-cel" name="celular" class="form-control phone-mask"
                     value="<?= View::e($perfil['celular'] ?? '') ?>"
                     placeholder="(00) 00000-0000">
            </div>
          </div>

          <div class="form-row">
            <div class="form-group form-col">
              <label for="p-nasc">Data de nascimento</label>
              <input type="date" id="p-nasc" name="nascimento" class="form-control"
                     value="<?= View::e($perfil['nascimento'] ?? '') ?>"
                     max="<?= date('Y-m-d', strtotime('-18 years')) ?>">
            </div>
            <div class="form-group form-col">
              <label for="p-genero">Gênero</label>
              <select id="p-genero" name="genero" class="form-control">
                <option value="">Prefiro não informar</option>
                <option value="M" <?= ($perfil['genero'] ?? '') === 'M' ? 'selected' : '' ?>>Masculino</option>
                <option value="F" <?= ($perfil['genero'] ?? '') === 'F' ? 'selected' : '' ?>>Feminino</option>
                <option value="O" <?= ($perfil['genero'] ?? '') === 'O' ? 'selected' : '' ?>>Outro</option>
                <option value="N" <?= ($perfil['genero'] ?? '') === 'N' ? 'selected' : '' ?>>Prefiro não informar</option>
              </select>
            </div>
          </div>

          <label class="check-label" style="margin-top:4px;">
            <input type="checkbox" name="newsletter" value="1"
                   <?= !empty($perfil['newsletter']) ? 'checked' : '' ?>>
            <span class="check-custom"></span>
            Quero receber promoções e novidades por e-mail
          </label>
        </div>

        <div id="profile-error" class="form-alert form-alert--error" style="display:none;"></div>
        <button type="submit" class="btn btn-primary" id="btn-save-profile">
          Salvar alterações
        </button>
      </form>

      <!-- Alterar senha -->
      <div class="profile-form-group" style="margin-top:32px;">
        <h3>Alterar senha</h3>
        <form id="form-password" novalidate>
          <?= SecurityHelper::csrfField() ?>
          <div class="form-group">
            <label for="p-senha-atual">Senha atual <span class="required">*</span></label>
            <div class="input-password-wrapper">
              <input type="password" id="p-senha-atual" name="senha_atual"
                     class="form-control" required>
              <button type="button" class="toggle-password"
                      data-target="p-senha-atual" aria-label="Mostrar">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              </button>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group form-col">
              <label for="p-nova-senha">Nova senha <span class="required">*</span></label>
              <div class="input-password-wrapper">
                <input type="password" id="p-nova-senha" name="nova_senha"
                       class="form-control" placeholder="Mínimo 8 caracteres" required>
                <button type="button" class="toggle-password"
                        data-target="p-nova-senha" aria-label="Mostrar">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
              </div>
            </div>
            <div class="form-group form-col">
              <label for="p-conf-senha">Confirmar nova senha <span class="required">*</span></label>
              <div class="input-password-wrapper">
                <input type="password" id="p-conf-senha" name="confirmar_senha"
                       class="form-control" required>
                <button type="button" class="toggle-password"
                        data-target="p-conf-senha" aria-label="Mostrar">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
              </div>
            </div>
          </div>
          <div id="password-msg" style="display:none;" class="form-alert"></div>
          <button type="submit" class="btn btn-outline" id="btn-save-password">
            Alterar senha
          </button>
        </form>
      </div>
    </div>
  </div>
</div>


<!-- Modal 2FA -->
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
          <button type="submit" class="btn btn-primary" id="btn-submit-2fa">
            Verificar
          </button>
        </div>
      </form>
    </div>
  </div>
</div>