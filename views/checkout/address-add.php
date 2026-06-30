<?php
// ════════════════════════════════════════════════════════
// views/checkout/address-add.php
//
// Página de adicionar novo endereço.
// Após salvar, redireciona para /checkout/payment.
// ════════════════════════════════════════════════════════
?>

<div class="checkout-section">
  <div class="section-head">
    <div class="section-head-back">
      <a href="<?= BASE_URL ?>/checkout/address" class="back-link">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.5" stroke-linecap="round">
          <polyline points="15 18 9 12 15 6"/>
        </svg>
        Voltar
      </a>
    </div>
    <h2>
      <span class="section-num">2</span>
      Novo endereço de entrega
    </h2>
    <p class="section-sub">Comece pelo CEP que o resto a gente preenche.</p>
  </div>

  <form id="form-address-add" novalidate>
    <input type="hidden" name="principal" value="0" id="principal-flag">

    <!-- CEP em destaque -->
    <div class="cep-hero">
      <div class="cep-hero-icon">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/>
          <circle cx="12" cy="10" r="3"/>
        </svg>
      </div>
      <div class="cep-hero-body">
        <label for="end-cep" class="cep-hero-label">
          Comece pelo seu CEP
          <a href="https://buscacepinter.correios.com.br/app/endereco/index.php"
             target="_blank" rel="noopener" class="cep-hero-find">Não sei meu CEP</a>
        </label>
        <div class="cep-hero-input-wrap">
          <input type="text" id="end-cep" name="cep"
                 class="form-control cep-mask cep-hero-input"
                 placeholder="00000-000" maxlength="9"
                 inputmode="numeric" autocomplete="postal-code" required autofocus>
          <span class="cep-loading" id="cep-loading" style="display:none;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2.5" stroke-linecap="round"
                 style="animation:ck-spin .8s linear infinite">
              <path d="M21 12a9 9 0 11-6.219-8.56"/>
            </svg>
          </span>
          <span class="cep-success" id="cep-success" style="display:none;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="3" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
          </span>
        </div>
        <div class="cep-found" id="cep-found" style="display:none;">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
          <span>Endereço encontrado · <strong id="cep-found-summary"></strong></span>
        </div>
        <span class="field-error" id="err-end-cep"></span>
      </div>
    </div>

    <div class="address-fields" id="address-fields">

      <div class="form-row">
        <div class="form-group form-col">
          <label for="end-nome">Nome do destinatário <span class="required">*</span></label>
          <input type="text" id="end-nome" name="nome_destinatario" class="form-control"
                 placeholder="Quem vai receber" required autocomplete="name"
                 value="<?= View::e(Session::get('cliente_nome') ?? '') ?>">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group" style="flex:2;">
          <label for="end-logradouro">Endereço <span class="required">*</span></label>
          <input type="text" id="end-logradouro" name="logradouro" class="form-control"
                 placeholder="Rua, Av., Alameda..." required autocomplete="address-line1">
        </div>
        <div class="form-group" style="flex:0 0 110px;">
          <label for="end-numero">Número <span class="required">*</span></label>
          <input type="text" id="end-numero" name="numero" class="form-control"
                 placeholder="Nº" required>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group form-col">
          <label for="end-comp">
            Complemento
            <span class="label-opt">opcional</span>
          </label>
          <input type="text" id="end-comp" name="complemento" class="form-control"
                 placeholder="Apto, bloco, casa..." autocomplete="address-line2">
        </div>
        <div class="form-group form-col">
          <label for="end-bairro">Bairro <span class="required">*</span></label>
          <input type="text" id="end-bairro" name="bairro" class="form-control" required>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group" style="flex:2;">
          <label for="end-cidade">Cidade <span class="required">*</span></label>
          <input type="text" id="end-cidade" name="cidade" class="form-control" required>
        </div>
        <div class="form-group" style="flex:0 0 90px;">
          <label for="end-estado">UF <span class="required">*</span></label>
          <select id="end-estado" name="estado" class="form-control" required>
            <option value="">UF</option>
            <?php foreach (['AC','AL','AM','AP','BA','CE','DF','ES','GO','MA','MG','MS','MT','PA','PB','PE','PI','PR','RJ','RN','RO','RR','RS','SC','SE','SP','TO'] as $uf): ?>
              <option value="<?= $uf ?>"><?= $uf ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group form-col">
          <label for="end-tel">
            Telefone de contato
            <span class="label-opt">opcional · ajuda na entrega</span>
          </label>
          <input type="tel" id="end-tel" name="telefone" class="form-control phone-mask"
                 placeholder="(00) 00000-0000" maxlength="15" autocomplete="tel">
        </div>
        <div class="form-group form-col">
          <label for="end-apelido">
            Apelido
            <span class="label-opt">opcional</span>
          </label>
          <input type="text" id="end-apelido" name="apelido" class="form-control"
                 placeholder="Ex: Casa, Trabalho" maxlength="40">
        </div>
      </div>

      <div class="form-group">
        <label for="end-obs">
          Observações para o entregador
          <span class="label-opt">opcional</span>
        </label>
        <textarea id="end-obs" name="observacao_entrega" class="form-control"
                  rows="2" maxlength="200"
                  placeholder="Ex: portão azul, deixar com porteiro, tocar interfone..."></textarea>
      </div>

      <!-- Marcar como principal -->
      <label class="address-principal-toggle">
        <input type="checkbox" id="chk-principal" value="1">
        <span class="address-principal-box">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="3" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
        </span>
        <span class="address-principal-text">
          <strong>Tornar este meu endereço principal</strong>
          <small>Será usado por padrão nas próximas compras</small>
        </span>
      </label>

      <!-- Trust badge -->
      <div class="address-trust">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
        </svg>
        Entrega segura para este endereço
      </div>

      <div id="address-error" class="form-alert" style="display:none;"></div>

      <button type="submit" class="btn btn-primary btn-full" id="btn-save-address">
        Salvar endereço e continuar
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.5" stroke-linecap="round">
          <line x1="5" y1="12" x2="19" y2="12"/>
          <polyline points="12 5 19 12 12 19"/>
        </svg>
      </button>
    </div>
  </form>
</div>
