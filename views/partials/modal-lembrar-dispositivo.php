<?php // views/partials/modal-lembrar-dispositivo.php
// Incluir no layout principal (header/footer global), FORA do checkout.
// O JS decide se mostra ou não, consultando o servidor — este HTML fica
// sempre presente no DOM, oculto por padrão. ?>
<div class="pe-modal-backdrop modal-backdrop" id="modal-lembrar-dispositivo" style="display:none;">
  <div class="pe-modal modal" style="max-width:420px;">
    <div class="pe-modal-body modal-body" style="padding-top:28px;">

      <!-- Etapa 1: pergunta -->
      <div id="lembrar-step-pergunta">
        <div class="lembrar-icon">
          <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="2" y="4" width="20" height="16" rx="2"/>
            <path d="M2 8h20"/>
          </svg>
        </div>
        <h3 class="lembrar-title">Continuar conectado aqui?</h3>
        <p class="lembrar-text">
          Reconhecemos este navegador. Você pode continuar conectado por
          30 dias, sem precisar fazer login novamente neste dispositivo.
        </p>
        <div class="lembrar-actions">
          <button type="button" class="btn btn-ghost" id="btn-lembrar-nao">Não, obrigado</button>
          <button type="button" class="btn btn-primary" id="btn-lembrar-sim">Sim, continuar conectado</button>
        </div>
      </div>

      <!-- Etapa 2: confirmação por senha -->
      <div id="lembrar-step-senha" style="display:none;">
        <h3 class="lembrar-title">Confirme sua senha</h3>
        <p class="lembrar-text">
          Por segurança, confirme sua senha para manter esta sessão ativa.
        </p>
        <div class="form-group">
          <input type="password" id="lembrar-senha-input" class="form-control"
                 placeholder="Sua senha atual" autocomplete="current-password">
          <span class="field-error" id="err-lembrar-senha"></span>
        </div>
        <div class="lembrar-actions">
          <button type="button" class="btn btn-ghost" id="btn-lembrar-voltar">Voltar</button>
          <button type="button" class="btn btn-primary" id="btn-lembrar-confirmar">Confirmar</button>
        </div>
      </div>

    </div>
  </div>
</div>

<style>
.lembrar-icon {
  width: 52px; height: 52px; border-radius: 14px;
  background: #eff6ff; color: #2563eb;
  display: flex; align-items: center; justify-content: center;
  margin: 0 auto 16px;
}
.lembrar-title {
  font-size: 17px; font-weight: 700; text-align: center;
  margin: 0 0 8px; color: var(--c-heading, #1e293b);
}
.lembrar-text {
  font-size: 13.5px; color: #64748b; text-align: center;
  line-height: 1.5; margin: 0 0 22px;
}
.lembrar-actions {
  display: flex; flex-direction: column; gap: 8px;
}
.lembrar-actions .btn { width: 100%; }
</style>

