<?php
/**
 * Drawer de detalhe da geração.
 * Variáveis: $g (linha completa com tipo_nome/produto_nome), $roteamento, $csrf
 */
if (!function_exists('ia_e')) {
    function ia_e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
}
$usd = fn($v) => ($v === null) ? '—' : 'US$ ' . number_format((float) $v, 4, ',', '.');
$contexto = json_decode((string) ($g['contexto'] ?? ''), true);
?>
<div class="ia_detalhe_grade">
  <div class="ia_detalhe_item"><b>Tipo</b><?= ia_e($g['tipo_nome']) ?></div>
  <div class="ia_detalhe_item"><b>Ângulo</b><?= !empty($g['angulo']) ? ia_e($g['angulo']) : '—' ?></div>
  <div class="ia_detalhe_item"><b>Produto</b>
    <?= !empty($g['produto_nome']) ? ia_e($g['produto_nome']) . ' <span class="ia_mono">#' . (int) $g['produto_id'] . '</span>' : 'removido' ?>
  </div>
  <div class="ia_detalhe_item"><b>Criada em</b><?= ia_e(date('d/m/Y H:i:s', strtotime($g['criado_em']))) ?></div>
  <div class="ia_detalhe_item"><b>Modelo</b>
    <?= !empty($g['modelo_codigo']) ? '<span class="ia_mono">' . ia_e($g['modelo_codigo']) . '</span> · ' . ia_e($g['provedor_codigo']) : '—' ?>
  </div>
  <div class="ia_detalhe_item"><b>Tokens</b>
    <?= ($g['tokens_in'] !== null || $g['tokens_out'] !== null)
        ? (int) $g['tokens_in'] . ' entrada · ' . (int) $g['tokens_out'] . ' saída'
        : '—' ?>
  </div>
  <div class="ia_detalhe_item"><b>Custo</b>
    <?= ia_e($usd($g['custo_real_usd'])) ?>
    <?php if ($g['custo_real_usd'] === null && $g['custo_estimado_usd'] !== null): ?>
      <span class="ia_celula_sub">estimado: <?= ia_e($usd($g['custo_estimado_usd'])) ?></span>
    <?php endif; ?>
  </div>
  <div class="ia_detalhe_item"><b>Tempo</b><?= !empty($g['tempo_ms']) ? number_format($g['tempo_ms'] / 1000, 2, ',', '') . 's' : '—' ?></div>
  <?php if (!empty($g['geracao_origem_id'])): ?>
    <div class="ia_detalhe_item"><b>Refação de</b><span class="ia_mono">#<?= (int) $g['geracao_origem_id'] ?></span></div>
  <?php endif; ?>
  <div class="ia_detalhe_item"><b>Tentativas</b><?= (int) $g['tentativas'] ?></div>
</div>

<?php if ($g['status'] === 'concluida' && ($g['capacidade'] ?? 'texto') === 'imagem' && !empty($arquivo_id)): ?>
  <p class="ia_card_titulo" style="margin-top:4px"><i class="bi bi-image"></i> Imagem gerada</p>
  <div class="ia_resultado_img" style="margin-bottom:10px">
    <img src="/admin/ia/arquivo?id=<?= (int) $arquivo_id ?>" alt="Imagem gerada" loading="lazy">
  </div>
  <div class="ia_resultado_acoes" style="margin-bottom:16px">
    <a class="ia_btn" href="/admin/ia/arquivo?id=<?= (int) $arquivo_id ?>&download=1" target="_blank" rel="noopener">
      <i class="bi bi-download"></i> Baixar
    </a>
  </div>
  <?php if (!empty($g['resultado_texto'])): ?>
    <p class="ia_ajuda" style="margin:-8px 0 16px">Prompt refinado pelo provedor: <?= ia_e(mb_strimwidth((string) $g['resultado_texto'], 0, 220, '…')) ?></p>
  <?php endif; ?>
<?php elseif ($g['status'] === 'concluida' && $g['resultado_texto'] !== null): ?>
  <p class="ia_card_titulo" style="margin-top:4px"><i class="bi bi-chat-square-text"></i> Resultado</p>
  <div class="ia_resultado_texto" id="ia_det_texto" style="margin-bottom:10px"><?= ia_e($g['resultado_texto']) ?></div>
  <div class="ia_resultado_acoes" style="margin-bottom:16px">
    <button type="button" class="ia_btn ia_ac_copiar_det"><i class="bi bi-clipboard"></i> Copiar</button>
  </div>
<?php elseif ($g['status'] === 'falhou'): ?>
  <div class="ia_resultado_erro" style="margin-bottom:16px"><?= ia_e($g['erro'] ?? 'Erro não informado.') ?></div>
<?php else: ?>
  <div class="ia_ajuda" style="margin-bottom:16px"><span class="ia_spin"></span> Ainda em processamento…</div>
<?php endif; ?>

<details class="ia_dobra">
  <summary>Prompt final enviado ao modelo</summary>
  <div class="ia_pre"><?= ia_e($g['prompt_final']) ?></div>
</details>

<?php if (is_array($contexto) && !empty($contexto['produto'])): ?>
<details class="ia_dobra">
  <summary>Snapshot do produto no momento da geração</summary>
  <div class="ia_pre"><?= ia_e(json_encode($contexto['produto'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></div>
</details>
<?php endif; ?>

<?php if (!empty($roteamento)): ?>
<details class="ia_dobra" open>
  <summary>Roteamento (<?= count($roteamento) ?> tentativa(s))</summary>
  <div class="ia_tabela_wrap">
    <table class="ia_tabela">
      <thead><tr><th>Modelo</th><th>Resultado</th><th>Erro</th><th class="ia_num">Tempo</th></tr></thead>
      <tbody>
        <?php foreach ($roteamento as $r): ?>
        <tr>
          <td><span class="ia_mono"><?= ia_e($r['modelo_codigo']) ?></span><span class="ia_celula_sub"><?= ia_e($r['provedor_codigo']) ?></span></td>
          <td>
            <?php if ($r['resultado'] === 'ok'): ?>
              <span class="ia_pill ia_pill_ok">ok</span>
            <?php elseif ($r['resultado'] === 'pulado'): ?>
              <span class="ia_pill ia_pill_off">pulado</span>
            <?php else: ?>
              <span class="ia_pill ia_pill_erro"><?= ia_e($r['resultado']) ?></span>
            <?php endif; ?>
          </td>
          <td class="ia_celula_sub" style="max-width:180px"><?= $r['erro'] !== null ? ia_e(mb_strimwidth((string) $r['erro'], 0, 90, '…')) : '—' ?></td>
          <td class="ia_num ia_mono"><?= (int) $r['tempo_ms'] ?>ms</td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</details>
<?php endif; ?>

<div class="ia_form_rodape">
  <button type="button" class="ia_btn ia_ac_curar" data-id="<?= (int) $g['id'] ?>" data-acao="reprovado"><i class="bi bi-hand-thumbs-down"></i> Reprovar</button>
  <button type="button" class="ia_btn ia_ac_curar" data-id="<?= (int) $g['id'] ?>" data-acao="arquivado"><i class="bi bi-archive"></i> Arquivar</button>
  <?php if ($g['status'] === 'concluida' || $g['status'] === 'falhou'): ?>
    <button type="button" class="ia_btn ia_ac_refazer_hist" data-id="<?= (int) $g['id'] ?>"><i class="bi bi-arrow-repeat"></i> Refazer</button>
  <?php endif; ?>
  <button type="button" class="ia_btn ia_btn_primario ia_ac_curar" data-id="<?= (int) $g['id'] ?>" data-acao="aprovado"><i class="bi bi-hand-thumbs-up"></i> Aprovar</button>
</div>
