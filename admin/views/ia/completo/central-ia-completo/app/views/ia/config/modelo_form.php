<?php
/** Formulário do modelo (drawer). Variáveis esperadas: $mod (ou null), $provedores, $capacidades, $csrf */
if (!function_exists('ia_e')) {
    function ia_e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
}
$novo = ($mod === null);
$val  = function (string $campo, $padrao = '') use ($mod) {
    return ia_e($mod[$campo] ?? $padrao);
};
?>
<form class="ia_form" data-acao="/admin/ia/config/modelo/salvar" data-recarregar="mod" autocomplete="off">
  <input type="hidden" name="csrf_token" value="<?= ia_e($csrf ?? '') ?>">
  <input type="hidden" name="id" value="<?= $novo ? 0 : (int) $mod['id'] ?>">

  <div class="ia_form_linha">
    <div class="ia_form_grupo">
      <label for="ia_mod_prov">Provedor</label>
      <select id="ia_mod_prov" name="provedor_id" class="ia_input" required>
        <?php foreach ($provedores as $p): ?>
          <option value="<?= (int) $p['id'] ?>"
            <?= (!$novo && (int) $mod['provedor_id'] === (int) $p['id']) ? 'selected' : '' ?>>
            <?= ia_e($p['nome']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="ia_form_grupo">
      <label for="ia_mod_cap">Capacidade</label>
      <select id="ia_mod_cap" name="capacidade" class="ia_input" required>
        <?php foreach ($capacidades as $chave => $rotulo): ?>
          <option value="<?= ia_e($chave) ?>"
            <?= (!$novo && $mod['capacidade'] === $chave) ? 'selected' : '' ?>>
            <?= ia_e($rotulo) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="ia_form_grupo">
    <label for="ia_mod_codigo">Código do modelo</label>
    <input type="text" id="ia_mod_codigo" name="codigo_modelo" class="ia_input ia_input_mono" required
           maxlength="150" spellcheck="false"
           placeholder="ex.: gpt-5.4-mini ou black-forest-labs/flux-2-dev"
           value="<?= $val('codigo_modelo') ?>">
    <p class="ia_ajuda">Identificador exato usado na chamada da API do provedor.</p>
  </div>

  <div class="ia_form_grupo">
    <label for="ia_mod_nome">Nome de exibição</label>
    <input type="text" id="ia_mod_nome" name="nome" class="ia_input" required
           minlength="2" maxlength="150" value="<?= $val('nome') ?>">
  </div>

  <div class="ia_form_linha">
    <div class="ia_form_grupo">
      <label for="ia_mod_prio">Prioridade</label>
      <input type="number" id="ia_mod_prio" name="prioridade" class="ia_input" required
             min="1" max="999" step="1" value="<?= $novo ? 100 : (int) $mod['prioridade'] ?>">
      <p class="ia_ajuda">Menor número = tentado primeiro no fallback da capacidade.</p>
    </div>
    <div class="ia_form_grupo">
      <label for="ia_mod_timeout">Timeout (s)</label>
      <input type="number" id="ia_mod_timeout" name="timeout_s" class="ia_input" required
             min="10" max="900" step="1" value="<?= $novo ? 120 : (int) $mod['timeout_s'] ?>">
    </div>
  </div>

  <div class="ia_form_grupo">
    <label for="ia_mod_custo">Custo (JSON)</label>
    <textarea id="ia_mod_custo" name="custo_config" class="ia_input ia_input_mono" rows="3"
              spellcheck="false"
              placeholder='{"tipo":"por_token","usd_in_1m":0.75,"usd_out_1m":4.5}'><?= $val('custo_config') ?></textarea>
    <p class="ia_ajuda">
      Formatos aceitos:
      <code>{"tipo":"por_token","usd_in_1m":..,"usd_out_1m":..}</code> ·
      <code>{"tipo":"por_imagem","usd_imagem":..}</code> ·
      <code>{"tipo":"por_execucao","usd_execucao":..}</code>.
      Vazio = custo não rastreado (não recomendado).
    </p>
  </div>

  <div class="ia_form_grupo">
    <label for="ia_mod_params">Parâmetros padrão (JSON)</label>
    <textarea id="ia_mod_params" name="params_padrao" class="ia_input ia_input_mono" rows="3"
              spellcheck="false"
              placeholder='{"temperature":0.8}'><?= $val('params_padrao') ?></textarea>
    <p class="ia_ajuda">Enviados em toda chamada deste modelo; o prompt pode sobrescrever.</p>
  </div>

  <div class="ia_form_grupo">
    <label class="ia_check">
      <input type="checkbox" name="ativo" value="1"
        <?= ($novo || (int) $mod['ativo'] === 1) ? 'checked' : '' ?>>
      Modelo ativo
    </label>
  </div>

  <div class="ia_form_rodape">
    <button type="submit" class="ia_btn ia_btn_primario">
      <i class="bi bi-check-lg"></i> <?= $novo ? 'Criar modelo' : 'Salvar' ?>
    </button>
  </div>
</form>
