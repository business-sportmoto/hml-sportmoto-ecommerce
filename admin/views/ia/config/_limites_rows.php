<?php
/** Linhas da tabela de limites. Variável esperada: $limites */
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

if (empty($limites)): ?>
  <tr class="ia_vazio"><td colspan="8">Nenhum limite configurado — rode a migration da Fase 0.</td></tr>
<?php else: ?>
  <?php foreach ($limites as $l):
      $ehGlobal = ($l['escopo'] === 'global');
      $nomeRef  = $ehGlobal
          ? '—'
          : (!empty($l['usuario_nome']) ? $l['usuario_nome'] : ('Usuário #' . (int) $l['referencia_id']));
  ?>
  <tr>
    <td>
      <?php if ($ehGlobal): ?>
        <span class="ia_pill ia_pill_ok"><?= IconLibrary::render('globe-location', 'ia_ico', ['aria-hidden' => 'true']) ?> Global</span>
      <?php else: ?>
        <span class="ia_pill ia_pill_off"><?= IconLibrary::render('person-circle', 'ia_ico', ['aria-hidden' => 'true']) ?> Usuário</span>
      <?php endif; ?>
    </td>
    <td><?= ia_e($nomeRef) ?></td>
    <td class="ia_num"><?= ia_e(ia_usd($l['limite_diario_usd'])) ?></td>
    <td class="ia_num"><?= ia_e(ia_usd($l['limite_mensal_usd'])) ?></td>
    <td class="ia_num"><?= ($l['limite_geracoes_minuto'] !== null) ? (int) $l['limite_geracoes_minuto'] : '—' ?></td>
    <td class="ia_num"><?= (int) $l['alerta_percentual'] ?>%</td>
    <td>
      <?php if ((int) $l['ativo'] === 1): ?>
        <span class="ia_pill ia_pill_ok"><?= IconLibrary::render('check-circle', 'ia_ico', ['aria-hidden' => 'true']) ?> Ativo</span>
      <?php else: ?>
        <span class="ia_pill ia_pill_off">Inativo</span>
      <?php endif; ?>
    </td>
    <td>
      <div class="ia_acoes">
        <button type="button" class="ia_btn ia_btn_icone ia_ac_lim_editar"
                data-id="<?= (int) $l['id'] ?>" title="Editar limite" aria-label="Editar limite">
          <?= IconLibrary::render('pencil', 'ia_ico', ['aria-hidden' => 'true']) ?>
        </button>
        <?php if (!$ehGlobal): ?>
        <button type="button" class="ia_btn ia_btn_icone ia_perigo ia_ac_lim_excluir"
                data-id="<?= (int) $l['id'] ?>" title="Excluir limite" aria-label="Excluir limite">
          <?= IconLibrary::render('trash', 'ia_ico', ['aria-hidden' => 'true']) ?>
        </button>
        <?php endif; ?>
      </div>
    </td>
  </tr>
  <?php endforeach; ?>
<?php endif; ?>
