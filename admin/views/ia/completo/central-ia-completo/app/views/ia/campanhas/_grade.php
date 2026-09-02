<?php
/** Grade produto × tipo da campanha (renderizada pelo grade()). */
if (!function_exists('ia_e')) {
    function ia_e($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
}
?>
<table class="ia_grade">
  <thead>
    <tr>
      <th class="ia_grade_prod">Produto</th>
      <?php foreach ($tipos as $t): ?>
        <th><?= ia_e($t['nome']) ?></th>
      <?php endforeach; ?>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($produtos as $p): ?>
      <tr>
        <td class="ia_grade_prod"><?= ia_e($p['nome']) ?> <span class="ia_celula_sub">#<?= (int) $p['id'] ?></span></td>
        <?php foreach ($tipos as $t): ?>
          <?php $g = $mapa[$p['id'] . '_' . $t['id']] ?? null; ?>
          <td>
            <?php if ($g === null): ?>
              <span class="ia_pill ia_pill_neutra">—</span>
            <?php elseif ($g['status'] === 'concluida'): ?>
              <button type="button" class="ia_pill ia_pill_ok ia_grade_celula" data-gid="<?= (int) $g['id'] ?>">
                <i class="bi bi-check-circle"></i> OK<?= $g['aprovacao'] === 'aprovado' ? ' <i class="bi bi-patch-check-fill"></i>' : '' ?>
              </button>
            <?php elseif ($g['status'] === 'falhou'): ?>
              <button type="button" class="ia_pill ia_pill_erro ia_grade_celula" data-gid="<?= (int) $g['id'] ?>">
                <i class="bi bi-x-circle"></i> Falhou
              </button>
            <?php elseif ($g['status'] === 'na_fila'): ?>
              <button type="button" class="ia_pill ia_pill_neutra ia_grade_celula" data-gid="<?= (int) $g['id'] ?>">
                <i class="bi bi-hourglass"></i> Na fila
              </button>
            <?php else: ?>
              <button type="button" class="ia_pill ia_pill_azul ia_grade_celula" data-gid="<?= (int) $g['id'] ?>">
                <span class="ia_spin"></span> Gerando
              </button>
            <?php endif; ?>
          </td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
