<?php
/** Formulário do provedor (drawer). Variáveis esperadas: $prov, $csrf */
if (!function_exists('ia_e')) {
    function ia_e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
}
$temChave = ((int) ($prov['tem_chave'] ?? 0) === 1);
$limite   = ($prov['limite_diario_usd'] !== null && $prov['limite_diario_usd'] !== '')
    ? number_format((float) $prov['limite_diario_usd'], 2, '.', '')
    : '';
?>
<form class="ia_form" data-acao="/admin/ia/config/provedor/salvar" data-recarregar="prov" autocomplete="off">
  <?= SecurityHelper::csrfField() ?>
  <input type="hidden" name="id" value="<?= (int) $prov['id'] ?>">

  <div class="ia_form_grupo">
    <label for="ia_prov_nome">Nome</label>
    <input type="text" id="ia_prov_nome" name="nome" class="ia_input" required
           minlength="2" maxlength="100" value="<?= ia_e($prov['nome']) ?>">
  </div>

  <div class="ia_form_grupo">
    <label for="ia_prov_url">Base URL</label>
    <input type="url" id="ia_prov_url" name="base_url" class="ia_input ia_input_mono" required
           maxlength="255" value="<?= ia_e($prov['base_url']) ?>">
    <p class="ia_ajuda">Endpoint raiz da API — deve começar com https://.</p>
  </div>

  <div class="ia_form_grupo">
    <label for="ia_prov_chave">Chave de API</label>
    <input type="password" id="ia_prov_chave" name="api_key" class="ia_input ia_input_mono"
           autocomplete="new-password" spellcheck="false"
           placeholder="<?= $temChave
               ? 'Deixe em branco para manter a atual (•••• ' . ia_e($prov['api_key_last4']) . ')'
               : 'Cole aqui a chave do provedor' ?>">
    <div class="ia_aviso_seguro" style="margin-top:8px">
      <?= IconLibrary::render('shield-locked', 'ia_ico', ['aria-hidden' => 'true']) ?>
      <span>Cifrada com AES-256-GCM no banco. Nunca é reexibida nem enviada ao navegador — apenas os
      últimos 4 caracteres ficam visíveis. Toda alteração é registrada na auditoria.</span>
    </div>
  </div>

  <div class="ia_form_linha">
    <div class="ia_form_grupo">
      <label for="ia_prov_limite">Limite diário (US$)</label>
      <input type="number" id="ia_prov_limite" name="limite_diario_usd" class="ia_input"
             min="0" max="999999" step="0.01" inputmode="decimal"
             placeholder="sem teto próprio" value="<?= ia_e($limite) ?>">
      <p class="ia_ajuda">Teto específico do provedor. O limite global continua valendo.</p>
    </div>
    <div class="ia_form_grupo">
      <label for="ia_prov_timeout">Timeout padrão (s)</label>
      <input type="number" id="ia_prov_timeout" name="timeout_padrao_s" class="ia_input" required
             min="5" max="900" step="1" value="<?= (int) $prov['timeout_padrao_s'] ?>">
    </div>
  </div>

  <div class="ia_form_grupo">
    <label class="ia_check">
      <input type="checkbox" name="ativo" value="1" <?= ((int) $prov['ativo'] === 1) ? 'checked' : '' ?>>
      Provedor ativo
    </label>
    <?php if (!$temChave): ?>
      <p class="ia_ajuda">Só é possível ativar depois de configurar a chave de API.</p>
    <?php endif; ?>
  </div>

  <div class="ia_form_rodape">
    <button type="submit" class="ia_btn ia_btn_primario"><?= IconLibrary::render('check', 'ia_ico', ['aria-hidden' => 'true']) ?> Salvar</button>
  </div>
</form>
