<?php
/** Linhas da tabela de modelos. Variáveis esperadas: $modelos, $capacidades */
if (!function_exists('ia_e')) {
    function ia_e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
}

if (empty($modelos)): ?>
  <tr class="ia_vazio"><td colspan="7">Nenhum modelo no catálogo.</td></tr>
<?php else:
  // Posição na fila pela MESMA regra do orquestrador (ativo, provedor ativo
  // com chave; prioridade e depois id): o primeiro de cada capacidade é o
  // principal, os demais são reserva. Modelo que não roda não tem posição.
  $fila = array_values(array_filter($modelos, fn ($x) => (int) $x['ativo'] === 1
      && (int) $x['provedor_ativo'] === 1 && !empty($x['provedor_tem_chave'])));
  usort($fila, fn ($a, $b) => [$a['capacidade'], (int) $a['prioridade'], (int) $a['id']]
                          <=> [$b['capacidade'], (int) $b['prioridade'], (int) $b['id']]);
  $posicao = [];
  $vistos  = [];
  foreach ($fila as $x) {
      $posicao[(int) $x['id']] = isset($vistos[$x['capacidade']]) ? 'reserva' : 'principal';
      $vistos[$x['capacidade']] = true;
  }
?>
  <?php foreach ($modelos as $m):
      $usado        = ((int) $m['total_execucoes'] > 0);
      $provInativo  = ((int) $m['provedor_ativo'] !== 1);
      $rotuloCap    = $capacidades[$m['capacidade']] ?? $m['capacidade'];
      // Sem custo o modelo não entra no rollup nem nos tetos — e não pode
      // mais ser ativado. Mostrar por quê evita o toggle que não responde.
      $semCusto     = (trim((string) ($m['custo_config'] ?? '')) === '');
      // Ativo com provedor desligado ou sem chave: o orquestrador PULA. Sem
      // este estado a tabela mostrava "Ativo" para um modelo que nunca roda.
      $roda   = (int) $m['ativo'] === 1 && !$provInativo && !empty($m['provedor_tem_chave']);
      $status = (int) $m['ativo'] !== 1 ? 'inativo' : ($roda ? 'ativo' : 'bloqueado');
      $pos    = $posicao[(int) $m['id']] ?? '';
  ?>
  <tr data-cap="<?= ia_e($m['capacidade']) ?>" data-prov="<?= ia_e($m['provedor_codigo']) ?>" data-status="<?= $status ?>" data-prio="<?= (int) $m['prioridade'] ?>" data-pos="<?= $pos ?>">
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
    <td class="ia_num">
      <?= (int) $m['prioridade'] ?>
      <?php if ($pos !== ''): ?><span class="ia_celula_sub"><?= $pos === 'principal' ? 'principal' : 'reserva' ?></span><?php endif; ?>
    </td>
    <td>
      <?php if ($semCusto): ?>
        <span class="ia_pill ia_pill_aviso" title="Sem custo configurado: o gasto não entra no rollup nem nos tetos, e o modelo não pode ser ativado">
          <?= IconLibrary::render('alert-triangle', 'ia_ico', ['aria-hidden' => 'true']) ?> sem custo
        </span>
      <?php else: ?>
        <span class="ia_mono"><?= ia_e(IAModelo::resumoCusto($m['custo_config'])) ?></span>
      <?php endif; ?>
    </td>
    <td>
      <?php if ($status === 'bloqueado'): ?>
        <span class="ia_pill ia_pill_aviso" title="<?= $provInativo ? 'O provedor está desativado' : 'O provedor está sem chave' ?> — o orquestrador pula este modelo">
          <?= IconLibrary::render('alert-triangle', 'ia_ico', ['aria-hidden' => 'true']) ?> Ativo, não roda
        </span>
      <?php elseif ((int) $m['ativo'] === 1): ?>
        <span class="ia_pill ia_pill_ok"><?= IconLibrary::render('check-circle', 'ia_ico', ['aria-hidden' => 'true']) ?> Ativo</span>
      <?php else: ?>
        <span class="ia_pill ia_pill_off">Inativo</span>
      <?php endif; ?>
    </td>
    <td>
      <div class="ia_acoes">
        <button type="button" class="ia_btn ia_btn_icone ia_ac_mod_editar"
                data-id="<?= (int) $m['id'] ?>" title="Editar modelo" aria-label="Editar modelo">
          <?= IconLibrary::render('edit', 'ia_ico', ['aria-hidden' => 'true']) ?>
        </button>
        <?php
          $travado = ($semCusto && (int) $m['ativo'] !== 1);
          $rotuloToggle = (int) $m['ativo'] === 1 ? 'Desativar' : ($travado ? 'Configure o custo antes de ativar' : 'Ativar');
        ?>
        <button type="button" class="ia_btn ia_btn_icone ia_ac_mod_alternar"
                data-id="<?= (int) $m['id'] ?>" <?= $travado ? 'disabled' : '' ?>
                title="<?= ia_e($rotuloToggle) ?>" aria-label="<?= ia_e($rotuloToggle) ?>">
          <?= IconLibrary::render('radio_button_check', 'ia_ico', ['aria-hidden' => 'true']) ?>
        </button>
        <button type="button" class="ia_btn ia_btn_icone ia_perigo ia_ac_mod_excluir"
                data-id="<?= (int) $m['id'] ?>"
                aria-label="Excluir modelo <?= ia_e($m['codigo_modelo']) ?>"
                <?= $usado ? 'disabled title="Já utilizado em gerações — desative em vez de excluir"' : 'title="Excluir modelo"' ?>>
          <?= IconLibrary::render('delete', 'ia_ico', ['aria-hidden' => 'true']) ?>
        </button>
      </div>
    </td>
  </tr>
  <?php endforeach; ?>
<?php endif; ?>
