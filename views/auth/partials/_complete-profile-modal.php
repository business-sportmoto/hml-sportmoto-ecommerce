
<?php
// ============================================================
// ARQUIVO 2: views/auth/partials/_complete-profile-modal.php
// Modal pos-Google: coleta CPF e WhatsApp antes de criar a conta
// Incluir em login.php E register.php
// ============================================================
?>

<div id="google-complete-modal" class="auth-modal" hidden>
  <div class="auth-modal-backdrop"></div>
  <div class="auth-modal-panel">

    <!-- Avatar + nome do Google -->
    <div class="gcm-identity" id="gcm-identity">
      <div class="gcm-avatar" id="gcm-avatar-wrap">
        <img id="gcm-avatar-img" src="" alt="" hidden>
        <span id="gcm-avatar-initials"></span>
      </div>
      <div>
        <p class="gcm-nome" id="gcm-nome"></p>
        <p class="gcm-email" id="gcm-email"></p>
      </div>
    </div>

    <div class="gcm-divider"></div>

    <h3 class="gcm-title">Quase pronto!</h3>
    <p class="gcm-sub">
      Para finalizar sua conta, precisamos de mais duas informacoes.
    </p>

    <form id="google-complete-form" novalidate>
      <input type="hidden" name="_csrf_token"
             value="<?php echo SecurityHelper::generateCsrf(); ?>">

      <!-- CPF -->
      <div class="gcm-field">
        <label for="gcm-cpf">CPF</label>
        <input type="text" id="gcm-cpf" name="cpf"
               inputmode="numeric" maxlength="14"
               placeholder="000.000.000-00"
               autocomplete="off">
        <span class="gcm-field-hint">Opcional, mas necessario para nota fiscal</span>
        <span class="gcm-field-error" id="gcm-cpf-err" hidden></span>
      </div>

      <!-- WhatsApp -->
      <div class="gcm-field">
        <label for="gcm-tel">
          WhatsApp *
          <svg width="13" height="13" viewBox="0 0 24 24" fill="#25D366">
            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
          </svg>
        </label>
        <input type="tel" id="gcm-tel" name="telefone"
               inputmode="numeric" maxlength="15"
               placeholder="(11) 91234-5678"
               autocomplete="tel" required>
        <span class="gcm-field-error" id="gcm-tel-err" hidden></span>
      </div>

      <div class="gcm-global-error" id="gcm-global-err" hidden></div>

      <button type="submit" class="gcm-submit" id="gcm-submit">
        Criar minha conta
      </button>

      <button type="button" class="gcm-cancel" id="gcm-cancel">
        Cancelar
      </button>
    </form>

  </div>
</div>