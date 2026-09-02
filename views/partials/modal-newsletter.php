<?php
/**
 * views/partials/modal-newsletter.php
 *
 * Confirmação da inscrição na newsletter. Fica sempre no DOM, oculto; o
 * newsletter.js abre depois que o código sai por e-mail.
 *
 * Duas etapas: informar nome + código, e depois o cupom. O e-mail não é
 * pedido de novo aqui — já foi digitado no rodapé, e repetir daria a impressão
 * de que a primeira digitação se perdeu.
 */
?>
<div class="pe-modal-backdrop modal-backdrop nlm_backdrop" id="modal-newsletter"
     style="display:none;" role="dialog" aria-modal="true" aria-labelledby="nlm-titulo">
  <div class="pe-modal modal nlm_box">
    <button type="button" class="nlm_fechar" id="nlm-fechar" aria-label="Fechar">&times;</button>

    <div class="pe-modal-body modal-body nlm_body">

      <!-- ── etapa 1: código ─────────────────────────────────────── -->
      <div id="nlm-etapa-codigo">
        <div class="nlm_icone" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
               stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 8 9 6 9-6"/>
          </svg>
        </div>

        <h3 class="nlm_titulo" id="nlm-titulo">Confirme seu e-mail</h3>
        <p class="nlm_texto">
          Enviamos um código para <strong id="nlm-email"></strong>.
          Ele vale por <span id="nlm-minutos">15</span> minutos.
        </p>

        <div class="nlm_campo">
          <label for="nlm-nome">Como podemos te chamar?</label>
          <input type="text" id="nlm-nome" class="form-control" maxlength="120"
                 placeholder="Seu nome" autocomplete="given-name">
        </div>

        <div class="nlm_campo">
          <label for="nlm-codigo">Código de 6 dígitos</label>
          <input type="text" id="nlm-codigo" class="form-control nlm_codigo"
                 inputmode="numeric" autocomplete="one-time-code"
                 maxlength="6" placeholder="000000">
        </div>

        <p class="nlm_erro" id="nlm-erro" role="alert" hidden></p>

        <button type="button" class="btn btn-primary nlm_botao" id="nlm-confirmar">
          Confirmar e receber cupom
        </button>

        <p class="nlm_rodape">
          Não chegou?
          <button type="button" class="nlm_link" id="nlm-reenviar">Enviar de novo</button>
        </p>
      </div>

      <!-- ── etapa 2: cupom ──────────────────────────────────────── -->
      <div id="nlm-etapa-cupom" hidden>
        <div class="nlm_icone nlm_icone--ok" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
               stroke-linecap="round" stroke-linejoin="round">
            <path d="M20 6 9 17l-5-5"/>
          </svg>
        </div>

        <h3 class="nlm_titulo">Pronto, <span id="nlm-nome-ok"></span>!</h3>
        <p class="nlm_texto" id="nlm-cupom-texto">Seu cupom está aqui embaixo.</p>

        <div class="nlm_cupom" id="nlm-cupom-bloco">
          <span class="nlm_cupom_codigo" id="nlm-cupom-codigo">—</span>
          <button type="button" class="nlm_cupom_copiar" id="nlm-copiar">Copiar</button>
        </div>

        <p class="nlm_cupom_regra" id="nlm-cupom-regra"></p>

        <button type="button" class="btn btn-primary nlm_botao" id="nlm-comprar">
          Começar a comprar
        </button>
      </div>

    </div>
  </div>
</div>
