<?php
// views/customer/addresses.php
?>
<div class="customer-page">
  <div class="customer-page-header">
    <h1>Meus endereços</h1>
    <button type="button" class="btn btn-primary btn-sm" id="btn-new-address">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Novo endereço
    </button>
  </div>

  <!-- Modal de endereço -->
  <div class="modal-backdrop" id="address-modal-backdrop" style="display:none;">
    <div class="modal" id="address-modal">
      <div class="modal-header">
        <h3 id="modal-address-title">Novo endereço</h3>
        <button type="button" class="modal-close" id="btn-close-address-modal">×</button>
      </div>
      <div class="modal-body">
        <form id="form-address" novalidate>
          <?= SecurityHelper::csrfField() ?>
          <input type="hidden" id="edit-endereco-id" name="endereco_id" value="">

          <div class="form-row">
            <div class="form-group" style="flex:2">
              <label for="m-nome">Nome do destinatário <span class="required">*</span></label>
              <input type="text" id="m-nome" name="nome_destinatario" class="form-control" required>
            </div>
            <div class="form-group" style="flex:1;max-width:180px">
              <label for="m-cep">CEP <span class="required">*</span></label>
              <input type="text" id="m-cep" name="cep" class="form-control cep-mask"
                     placeholder="00000-000" maxlength="9" required>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group" style="flex:2">
              <label for="m-logradouro">Logradouro <span class="required">*</span></label>
              <input type="text" id="m-logradouro" name="logradouro" class="form-control" required>
            </div>
            <div class="form-group" style="flex:0 0 90px">
              <label for="m-numero">Número <span class="required">*</span></label>
              <input type="text" id="m-numero" name="numero" class="form-control" required>
            </div>
            <div class="form-group" style="flex:1">
              <label for="m-comp">Complemento</label>
              <input type="text" id="m-comp" name="complemento" class="form-control">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group form-col">
              <label for="m-bairro">Bairro <span class="required">*</span></label>
              <input type="text" id="m-bairro" name="bairro" class="form-control" required>
            </div>
            <div class="form-group form-col">
              <label for="m-cidade">Cidade <span class="required">*</span></label>
              <input type="text" id="m-cidade" name="cidade" class="form-control" required>
            </div>
            <div class="form-group" style="flex:0 0 80px">
              <label for="m-estado">UF <span class="required">*</span></label>
              <select id="m-estado" name="estado" class="form-control" required>
                <option value="">UF</option>
                <?php foreach (['AC','AL','AM','AP','BA','CE','DF','ES','GO','MA','MG','MS','MT','PA','PB','PE','PI','PR','RJ','RN','RO','RR','RS','SC','SE','SP','TO'] as $uf): ?>
                  <option value="<?= $uf ?>"><?= $uf ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label for="m-tel">Telefone de contato</label>
            <input type="tel" id="m-tel" name="telefone" class="form-control phone-mask"
                   placeholder="(00) 00000-0000">
          </div>
          <div id="address-form-error" class="form-alert form-alert--error" style="display:none;"></div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline" id="btn-cancel-address">Cancelar</button>
            <button type="submit" class="btn btn-primary" id="btn-save-address-modal">Salvar endereço</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <?php if (empty($enderecos)): ?>
  <div class="empty-state">
    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
      <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/>
    </svg>
    <p>Você não tem endereços cadastrados.</p>
  </div>
  <?php else: ?>
  <div class="addresses-grid" id="addresses-grid">
    <?php foreach ($enderecos as $end): ?>
    <div class="address-item" id="address-item-<?= (int)$end['id'] ?>">
      <?php if ($end['principal']): ?>
        <span class="address-principal-badge">Principal</span>
      <?php endif; ?>
      <div class="address-item-body">
        <strong><?= View::e($end['nome_destinatario']) ?></strong>
        <p><?= View::e("{$end['logradouro']}, {$end['numero']}") ?>
          <?php if ($end['complemento']): ?> — <?= View::e($end['complemento']) ?><?php endif; ?></p>
        <p><?= View::e($end['bairro']) ?></p>
        <p><?= View::e("{$end['cidade']}/{$end['estado']}") ?> — CEP <?= View::e($end['cep']) ?></p>
        <?php if ($end['telefone_contato']): ?>
          <p><?= View::e($end['telefone_contato']) ?></p>
        <?php endif; ?>
      </div>
      <div class="address-item-actions">
        <?php if (!$end['principal']): ?>
        <button type="button" class="btn-link btn-set-principal"
                data-id="<?= (int)$end['id'] ?>">
          Definir como principal
        </button>
        <?php endif; ?>
        <button type="button" class="btn-link btn-edit-address"
                data-id="<?= (int)$end['id'] ?>"
                data-nome="<?= View::e($end['nome_destinatario']) ?>"
                data-cep="<?= View::e($end['cep']) ?>"
                data-logradouro="<?= View::e($end['logradouro']) ?>"
                data-numero="<?= View::e($end['numero']) ?>"
                data-complemento="<?= View::e($end['complemento']) ?>"
                data-bairro="<?= View::e($end['bairro']) ?>"
                data-cidade="<?= View::e($end['cidade']) ?>"
                data-estado="<?= View::e($end['estado']) ?>"
                data-telefone="<?= View::e($end['telefone_contato']) ?>">
          Editar
        </button>
        <?php if (!$end['principal']): ?>
        <button type="button" class="btn-link btn-link--danger btn-delete-address"
                data-id="<?= (int)$end['id'] ?>">
          Excluir
        </button>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>