<?php
/** Formulário de limite (drawer). Variáveis esperadas: $lim (ou null), $csrf */
if (!function_exists('ia_e')) {
    function ia_e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
}
$novo     = ($lim === null);
$escopo   = $novo ? 'usuario' : $lim['escopo'];
$ehGlobal = ($escopo === 'global');
$dec      = function ($v) {
    return ($v === null || $v === '') ? '' : number_format((float) $v, 2, '.', '');
};
?>
<form class="ia_form" data-acao="/admin/ia/config/limite/salvar" data-recarregar="lim" autocomplete="off">
  <input type="hidden" name="csrf_token" value="<?= ia_e($csrf ?? '') ?>">

  <div class="ia_form_grupo">
    <label for="ia_lim_escopo">Escopo</label>
    <?php if ($novo): ?>
      <select id="ia_lim_escopo" name="escopo" class="ia_input" required>
        <option value="usuario" selected>Por usuário</option>
        <option value="global">Global</option>
      </select>
      <p class="ia_ajuda">Já existe uma linha global? Selecionar "Global" atualiza os valores dela.</p>
    <?php else: ?>
      <input type="hidden" name="escopo" value="<?= ia_e($escopo) ?>">
      <input type="text" class="ia_input" value="<?= $ehGlobal ? 'Global' : 'Por usuário' ?>" disabled>
    <?php endif; ?>
  </div>

  <div class="ia_form_grupo" id="ia_lim_ref_wrap" <?= $ehGlobal ? 'style="display:none"' : '' ?>>
    <label for="ia_lim_ref">ID do usuário</label>
    <?php if ($novo): ?>
      <input type="number" id="ia_lim_ref" name="referencia_id" class="ia_input"
             min="1" step="1" placeholder="usuarios.id">
    <?php else: ?>
      <input type="hidden" name="referencia_id" value="<?= (int) $lim['referencia_id'] ?>">
      <input type="text" class="ia_input" value="<?= (int) $lim['referencia_id'] ?>" disabled>
    <?php endif; ?>
    <p class="ia_ajuda">Se o usuário já tiver limite, os valores dele serão atualizados.</p>
  </div>

  <div class="ia_form_linha">
    <div class="ia_form_grupo">
      <label for="ia_lim_diario">Limite diário (US$)</label>
      <input type="number" id="ia_lim_diario" name="limite_diario_usd" class="ia_input"
             min="0" max="999999" step="0.01" inputmode="decimal"
             placeholder="sem limite" value="<?= ia_e($dec($lim['limite_diario_usd'] ?? null)) ?>">
    </div>
    <div class="ia_form_grupo">
      <label for="ia_lim_mensal">Limite mensal (US$)</label>
      <input type="number" id="ia_lim_mensal" name="limite_mensal_usd" class="ia_input"
             min="0" max="999999" step="0.01" inputmode="decimal"
             placeholder="sem limite" value="<?= ia_e($dec($lim['limite_mensal_usd'] ?? null)) ?>">
    </div>
  </div>

  <div class="ia_form_linha">
    <div class="ia_form_grupo">
      <label for="ia_lim_minuto">Gerações por minuto</label>
      <input type="number" id="ia_lim_minuto" name="limite_geracoes_minuto" class="ia_input"
             min="1" max="600" step="1" placeholder="sem limite"
             value="<?= ($lim['limite_geracoes_minuto'] ?? null) !== null ? (int) $lim['limite_geracoes_minuto'] : '' ?>">
    </div>
    <div class="ia_form_grupo">
      <label for="ia_lim_alerta">Alerta em (%)</label>
      <input type="number" id="ia_lim_alerta" name="alerta_percentual" class="ia_input" required
             min="1" max="100" step="1" value="<?= $novo ? 70 : (int) $lim['alerta_percentual'] ?>">
      <p class="ia_ajuda">Percentual do limite que dispara alerta no dashboard.</p>
    </div>
  </div>

  <div class="ia_form_grupo">
    <label class="ia_check">
      <input type="checkbox" name="ativo" value="1"
        <?= ($novo || (int) $lim['ativo'] === 1) ? 'checked' : '' ?>>
      Limite ativo
    </label>
  </div>

  <div class="ia_form_rodape">
    <button type="submit" class="ia_btn ia_btn_primario">
      <i class="bi bi-check-lg"></i> <?= $novo ? 'Criar limite' : 'Salvar' ?>
    </button>
  </div>
</form>
