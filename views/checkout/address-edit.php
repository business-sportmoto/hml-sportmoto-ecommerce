<?php
// views/checkout/address-edit.php
// Edita um endereço existente. $endereco e $hash vêm do controller.
$e = $endereco;
?>
<div class="checkout-section">
  <div class="section-head">
    <div class="section-head-back">
      <a href="<?= BASE_URL ?>/checkout/address/update" class="back-link">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="15 18 9 12 15 6"/></svg>
        Meus endereços
      </a>
    </div>
    <h2><span class="section-num">2</span> Editar endereço</h2>
    <p class="section-sub">Atualize as informações do seu endereço.</p>
  </div>

  <form id="form-address-edit" novalidate>

    <!-- Hero CEP -->
    <div class="cep-hero">
      <div class="cep-hero-icon">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/>
        </svg>
      </div>
      <div class="cep-hero-body">
        <label for="end-cep" class="cep-hero-label">
          CEP
          <a href="https://buscacepinter.correios.com.br/app/endereco/index.php" target="_blank" rel="noopener" class="cep-hero-find">Não sei meu CEP</a>
        </label>
        <div class="cep-hero-input-wrap">
          <input type="text" id="end-cep" name="cep" class="form-control cep-mask cep-hero-input"
                 placeholder="00000-000" maxlength="9" inputmode="numeric" autocomplete="postal-code"
                 required value="<?= View::e($e['cep']) ?>">
          <span class="cep-loading" id="cep-loading" style="display:none;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="animation:ck-spin .8s linear infinite"><path d="M21 12a9 9 0 11-6.219-8.56"/></svg>
          </span>
          <span class="cep-success" id="cep-success">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
          </span>
        </div>
        <span class="field-error" id="err-end-cep"></span>
      </div>
    </div>

    <!-- Campos -->
    <div class="address-fields" id="address-fields">
      <div class="form-row">
        <div class="form-group form-col">
          <label for="end-nome">Nome do destinatário <span class="required">*</span></label>
          <input type="text" id="end-nome" name="nome_destinatario" class="form-control is-valid"
                 required autocomplete="name" value="<?= View::e($e['nome_destinatario']) ?>">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group" style="flex:2">
          <label for="end-logradouro">Endereço <span class="required">*</span></label>
          <input type="text" id="end-logradouro" name="logradouro" class="form-control is-valid"
                 required autocomplete="address-line1" value="<?= View::e($e['logradouro']) ?>">
        </div>
        <div class="form-group" style="flex:0 0 110px">
          <label for="end-numero">Número <span class="required">*</span></label>
          <input type="text" id="end-numero" name="numero" class="form-control is-valid"
                 required value="<?= View::e($e['numero']) ?>">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group form-col">
          <label for="end-comp">Complemento <span class="label-opt">opcional</span></label>
          <input type="text" id="end-comp" name="complemento" class="form-control"
                 autocomplete="address-line2" value="<?= View::e($e['complemento'] ?? '') ?>">
        </div>
        <div class="form-group form-col">
          <label for="end-bairro">Bairro <span class="required">*</span></label>
          <input type="text" id="end-bairro" name="bairro" class="form-control is-valid"
                 required value="<?= View::e($e['bairro']) ?>">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group" style="flex:2">
          <label for="end-cidade">Cidade <span class="required">*</span></label>
          <input type="text" id="end-cidade" name="cidade" class="form-control is-valid"
                 required value="<?= View::e($e['cidade']) ?>">
        </div>
        <div class="form-group" style="flex:0 0 90px">
          <label for="end-estado">UF <span class="required">*</span></label>
          <select id="end-estado" name="estado" class="form-control" required>
            <option value="">UF</option>
            <?php foreach (['AC','AL','AM','AP','BA','CE','DF','ES','GO','MA','MG','MS','MT','PA','PB','PE','PI','PR','RJ','RN','RO','RR','RS','SC','SE','SP','TO'] as $uf): ?>
            <option value="<?= $uf ?>" <?= $e['estado'] === $uf ? 'selected' : '' ?>><?= $uf ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group form-col">
          <label for="end-tel">Telefone <span class="label-opt">opcional</span></label>
          <input type="tel" id="end-tel" name="telefone" class="form-control phone-mask"
                 maxlength="15" autocomplete="tel" value="<?= View::e($e['telefone'] ?? '') ?>">
        </div>
        <div class="form-group form-col">
          <label for="end-apelido">Apelido <span class="label-opt">opcional</span></label>
          <input type="text" id="end-apelido" name="apelido" class="form-control"
                 placeholder="Ex: Casa, Trabalho" maxlength="40"
                 value="<?= View::e($e['apelido'] ?? '') ?>">
        </div>
      </div>
      <div class="form-group">
        <label for="end-obs">Observações para o entregador <span class="label-opt">opcional</span></label>
        <textarea id="end-obs" name="observacao_entrega" class="form-control" rows="2" maxlength="200"><?= View::e($e['observacao_entrega'] ?? '') ?></textarea>
      </div>
      <div class="address-trust">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
        </svg>
        Entrega segura para este endereço
      </div>
      <div id="address-error" class="form-alert" style="display:none;"></div>
      <div class="address-edit-actions">
        <a href="<?= BASE_URL ?>/checkout/address/update" class="btn btn-outline">Cancelar</a>
        <button type="submit" class="btn btn-primary" id="btn-save-address">
          Salvar alterações
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/>
            <polyline points="17 21 17 13 7 13 7 21"/>
            <polyline points="7 3 7 8 15 8"/>
          </svg>
        </button>
      </div>
    </div>
  </form>
</div>

