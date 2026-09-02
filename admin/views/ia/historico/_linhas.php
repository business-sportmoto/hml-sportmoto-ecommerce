<?php
/** Linhas da tabela do histórico. Variável esperada: $linhas */
if (!function_exists('ia_e')) {
    function ia_e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('ia_pill_status')) {
    function ia_pill_status(string $s): string {
        $mapa = [
            'na_fila'             => ['ia_pill_azul',  'Na fila'],
            'processando'         => ['ia_pill_azul',  'Processando'],
            'aguardando_provedor' => ['ia_pill_azul',  'No provedor'],
            'concluida'           => ['ia_pill_ok',    'Concluída'],
            'falhou'              => ['ia_pill_erro',  'Falhou'],
            'cancelada'           => ['ia_pill_off',   'Cancelada'],
        ];
        [$classe, $rotulo] = $mapa[$s] ?? ['ia_pill_off', $s];
        return '<span class="ia_pill ' . $classe . '">' . ia_e($rotulo) . '</span>';
    }
    function ia_pill_aprovacao(string $a): string {
        $mapa = [
            'pendente'  => ['ia_pill_off',   'Pendente'],
            'aprovado'  => ['ia_pill_ok',    'Aprovado'],
            'reprovado' => ['ia_pill_erro',  'Reprovado'],
            'arquivado' => ['ia_pill_off',   'Arquivado'],
        ];
        [$classe, $rotulo] = $mapa[$a] ?? ['ia_pill_off', $a];
        return '<span class="ia_pill ' . $classe . '">' . ia_e($rotulo) . '</span>';
    }
}

if (empty($linhas)): ?>
  <tr class="ia_vazio"><td colspan="10">Nenhuma geração encontrada com esses filtros.</td></tr>
<?php else: ?>
  <?php foreach ($linhas as $g): ?>
  <tr data-id="<?= (int) $g['id'] ?>">
    <td class="ia_mono">#<?= (int) $g['id'] ?><?= !empty($g['geracao_origem_id']) ? '<span class="ia_celula_sub" title="Refação da #' . (int) $g['geracao_origem_id'] . '">↺ ' . (int) $g['geracao_origem_id'] . '</span>' : '' ?></td>
    <td class="ia_mono" style="white-space:nowrap"><?= ia_e(date('d/m H:i', strtotime($g['criado_em']))) ?></td>
    <td>
      <span class="ia_celula_principal"><?= ia_e($g['tipo_nome']) ?></span>
      <?php if (!empty($g['angulo'])): ?><span class="ia_celula_sub">ângulo: <?= ia_e($g['angulo']) ?></span><?php endif; ?>
    </td>
    <td>
      <?php if (!empty($g['produto_nome'])): ?>
        <span class="ia_celula_principal"><?= ia_e(mb_strimwidth($g['produto_nome'], 0, 42, '…')) ?></span>
        <span class="ia_celula_sub">#<?= (int) $g['produto_id'] ?></span>
      <?php else: ?>
        <span class="ia_celula_sub">produto removido</span>
      <?php endif; ?>
    </td>
    <td>
      <?php if (!empty($g['modelo_codigo'])): ?>
        <span class="ia_mono"><?= ia_e($g['modelo_codigo']) ?></span>
        <span class="ia_celula_sub"><?= ia_e($g['provedor_codigo']) ?></span>
      <?php else: ?>
        <span class="ia_celula_sub">—</span>
      <?php endif; ?>
    </td>
    <td><?= ia_pill_status((string) $g['status']) ?></td>
    <td><?= ia_pill_aprovacao((string) $g['aprovacao']) ?></td>
    <td class="ia_num ia_mono">
      <?php if ($g['custo_real_usd'] !== null): ?>
        <?= 'US$ ' . number_format((float) $g['custo_real_usd'], 4, ',', '.') ?>
      <?php elseif ($g['custo_estimado_usd'] !== null): ?>
        <span class="ia_celula_sub" title="Estimado">~US$ <?= number_format((float) $g['custo_estimado_usd'], 4, ',', '.') ?></span>
      <?php else: ?>—<?php endif; ?>
    </td>
    <td class="ia_num ia_mono"><?= !empty($g['tempo_ms']) ? number_format($g['tempo_ms'] / 1000, 1, ',', '') . 's' : '—' ?></td>
    <td>
      <div class="ia_acoes">
        <button type="button" class="ia_btn ia_btn_icone ia_ac_ver" title="Ver detalhe" aria-label="Ver detalhe da geração"><?= IconLibrary::render('zoom-in', 'ia_ico', ['aria-hidden' => 'true']) ?></button>
      </div>
    </td>
  </tr>
  <?php endforeach; ?>
<?php endif; ?>
