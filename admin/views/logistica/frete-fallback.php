<?php
/**
 * View: Frete — tabela de fallback (admin). Lógica em frete-fallback.js.
 */
$ico = static fn($n, $s = 16) => '<span class="log_iw" style="font-size:' . (int)$s . 'px">' . (class_exists('IconLibrary') ? IconLibrary::render($n) : '') . '</span>';
?>
<link rel="stylesheet" href="/assets/css/logistica.css">

<div class="log_shell" id="logFall" data-base="/admin/logistica/frete-fallback">

    <div class="log_head">
        <div>
            <h1><?= $ico('calculadora', 22) ?> Frete — fallback</h1>
            <p>Estimativa usada quando todas as transportadoras caem. Região × faixa de peso; toda cotação daqui sai como estimativa.</p>
        </div>
        <div class="log_head_actions">
            <a href="/admin/logistica" class="log_btn log_btn--sm"><?= $ico('caminhao', 15) ?> Torre</a>
            <button type="button" class="log_btn log_btn--primary log_btn--sm" id="logFallNova"><i class="bi bi-plus-lg"></i> Nova linha</button>
        </div>
    </div>

    <div id="logFallSaude"></div>

    <div class="log_card">
        <div class="log_table_wrap">
            <table class="log_table" id="logFallTabela">
                <thead><tr>
                    <th style="width:120px">Local</th>
                    <th>Faixa de peso</th>
                    <th>Serviço</th>
                    <th style="width:90px">Prazo</th>
                    <th style="width:170px">Preço</th>
                    <th style="width:90px">Ativo</th>
                    <th style="width:150px" class="log_col_acoes">Ações</th>
                </tr></thead>
                <tbody id="logFallBody"><tr><td colspan="7"><div class="log_state"><div class="log_spinner"></div></div></td></tr></tbody>
            </table>
        </div>
    </div>
    <p class="log_muted" style="margin-top:12px">Preço estimado = <strong>base + (por kg × peso)</strong>. Régua de escolha: linha por UF &gt; por região &gt; regra geral (UF e região vazios).</p>
</div>

<script>
    window.LOG_FALL_BASE = '/admin/logistica/frete-fallback';
</script>
<script src="/assets/js/frete-fallback.js" defer></script>
