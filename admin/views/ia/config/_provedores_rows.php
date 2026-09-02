<?php
/** Linhas da tabela de provedores. Variável esperada: $provedores */
if (!function_exists('ia_e')) {
    function ia_e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('ia_usd')) {
    function ia_usd($v): string {
        if ($v === null || $v === '') return '—';
        $f = (float) $v;
        return 'US$ ' . number_format($f, ($f > 0 && $f < 0.01) ? 4 : 2, ',', '.');
    }
}

if (empty($provedores)): ?>
  <tr class="ia_vazio"><td colspan="7">Nenhum provedor cadastrado — rode a migration da Fase 0.</td></tr>
<?php else: ?>
  <?php foreach ($provedores as $p): ?>
  <tr>
    <td>
      <span class="ia_celula_principal"><?= ia_e($p['nome']) ?></span>
      <span class="ia_celula_sub ia_mono"><?= ia_e($p['codigo']) ?> · <?= ia_e($p['base_url']) ?></span>
    </td>
    <td>
      <?php if ((int) $p['tem_chave'] === 1): ?>
        <span class="ia_mono" title="Somente os últimos 4 caracteres são armazenados em claro">••••&nbsp;<?= ia_e($p['api_key_last4']) ?></span>
      <?php else: ?>
        <span class="ia_pill ia_pill_aviso"><?= IconLibrary::render('alert-triangle', 'ia_ico', ['aria-hidden' => 'true']) ?> não configurada</span>
      <?php endif; ?>
    </td>
    <td class="ia_num"><?= (int) $p['modelos_ativos'] ?></td>
    <td class="ia_num"><?= ia_e(ia_usd($p['limite_diario_usd'])) ?></td>
    <td class="ia_num"><?= (int) $p['timeout_padrao_s'] ?>s</td>
    <td>
      <?php if ((int) $p['ativo'] === 1): ?>
        <span class="ia_pill ia_pill_ok"><?= IconLibrary::render('check-circle', 'ia_ico', ['aria-hidden' => 'true']) ?> Ativo</span>
      <?php else: ?>
        <span class="ia_pill ia_pill_off">Inativo</span>
      <?php endif; ?>
    </td>
    <td>
      <div class="ia_acoes">
        <button type="button" class="ia_btn ia_btn_icone ia_ac_prov_testar"
                data-id="<?= (int) $p['id'] ?>"
                aria-label="Testar conexão com <?= ia_e($p['nome']) ?>"
                <?= ((int) $p['tem_chave'] === 1) ? 'title="Testar conexão"' : 'disabled title="Configure a chave antes de testar"' ?>>
          <?= IconLibrary::render('lightning-charge', 'ia_ico', ['aria-hidden' => 'true']) ?>
        </button>
        <button type="button" class="ia_btn ia_btn_icone ia_ac_prov_editar"
                data-id="<?= (int) $p['id'] ?>" title="Editar provedor" aria-label="Editar provedor">
          <?= IconLibrary::render('pencil', 'ia_ico', ['aria-hidden' => 'true']) ?>
        </button>
        <button type="button" class="ia_btn ia_btn_icone ia_ac_prov_alternar"
                data-id="<?= (int) $p['id'] ?>"
                title="<?= ((int) $p['ativo'] === 1) ? 'Desativar' : 'Ativar' ?>" aria-label="<?= ((int) $p['ativo'] === 1) ? 'Desativar' : 'Ativar' ?>">
          <?= IconLibrary::render('power', 'ia_ico', ['aria-hidden' => 'true']) ?>
        </button>
      </div>
    </td>
  </tr>
  <?php endforeach; ?>
<?php endif; ?>
