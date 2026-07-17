<?php
/** Linhas da tabela de modelos. Variáveis esperadas: $modelos, $capacidades */
if (!function_exists('ia_e')) {
    function ia_e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
}

if (empty($modelos)): ?>
  <tr class="ia_vazio"><td colspan="7">Nenhum modelo no catálogo.</td></tr>
<?php else: ?>
  <?php foreach ($modelos as $m):
      $usado        = ((int) $m['total_execucoes'] > 0);
      $provInativo  = ((int) $m['provedor_ativo'] !== 1);
      $rotuloCap    = $capacidades[$m['capacidade']] ?? $m['capacidade'];
  ?>
  <tr>
    <td><span class="ia_cap"><?= ia_e($rotuloCap) ?></span></td>
    <td>
      <span class="ia_celula_principal"><?= ia_e($m['nome']) ?></span>
      <span class="ia_celula_sub ia_mono"><?= ia_e($m['codigo_modelo']) ?></span>
    </td>
    <td>
      <?= ia_e($m['provedor_nome']) ?>
      <?php if ($provInativo): ?>
        <span class="ia_pill ia_pill_aviso" title="O provedor deste modelo está desativado">provedor inativo</span>
      <?php endif; ?>
    </td>
    <td class="ia_num"><?= (int) $m['prioridade'] ?></td>
    <td><span class="ia_mono"><?= ia_e(IAModelo::resumoCusto($m['custo_config'])) ?></span></td>
    <td>
      <?php if ((int) $m['ativo'] === 1): ?>
        <span class="ia_pill ia_pill_ok"><i class="bi bi-check-circle"></i> Ativo</span>
      <?php else: ?>
        <span class="ia_pill ia_pill_off">Inativo</span>
      <?php endif; ?>
    </td>
    <td>
      <div class="ia_acoes">
        <button type="button" class="ia_btn ia_btn_icone ia_ac_mod_editar"
                data-id="<?= (int) $m['id'] ?>" title="Editar modelo">
          <i class="bi bi-pencil"><?= IconLibrary::render('edit') ?></i>
        </button>
        <button type="button" class="ia_btn ia_btn_icone ia_ac_mod_alternar"
                data-id="<?= (int) $m['id'] ?>"
                title="<?= ((int) $m['ativo'] === 1) ? 'Desativar' : 'Ativar' ?>">
          <i class="bi bi-power"><?= IconLibrary::render('radio_button_check') ?></i>
        </button>
        <button type="button" class="ia_btn ia_btn_icone ia_perigo ia_ac_mod_excluir"
                data-id="<?= (int) $m['id'] ?>"
                <?= $usado ? 'disabled title="Já utilizado em gerações — desative em vez de excluir"' : 'title="Excluir modelo"' ?>>
          <i class="bi bi-trash"><?= IconLibrary::render('delete') ?></i>
        </button>
      </div>
    </td>
  </tr>
  <?php endforeach; ?>
<?php endif; ?>
