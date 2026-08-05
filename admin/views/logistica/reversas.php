<?php
/**
 * View: Reversas (lista admin).
 * Recebe: $transportadoras (com serviços), $filtros. Detalhe/ações em reversas.js.
 */
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$ico = static fn($n, $s = 16) => '<span class="log_iw" style="font-size:' . (int)$s . 'px">' . (class_exists('IconLibrary') ? IconLibrary::render($n) : '') . '</span>';
$f = $filtros ?? [];
?>
<link rel="stylesheet" href="/assets/css/logistica.css">

<div class="log_shell" id="logRev" data-base="/admin/logistica/reversas">

    <div class="log_head">
        <div>
            <h1><?= $ico('reversa', 22) ?> Reversas</h1>
            <p>Devoluções e trocas: autorização, etiqueta de retorno, instruções e vínculo com reembolso.</p>
        </div>
        <div class="log_head_actions">
            <a href="/admin/logistica" class="log_btn log_btn--sm"><?= $ico('caminhao', 15) ?> Torre</a>
            <button type="button" class="log_btn log_btn--primary log_btn--sm" id="logRevNova"><i class="bi bi-plus-lg"></i> Nova solicitação</button>
        </div>
    </div>

    <form class="log_filters" id="logRevFiltros" onsubmit="return false;">
        <div class="log_field">
            <label>Buscar</label>
            <input type="text" class="log_input" name="busca" value="<?= $e($f['busca'] ?? '') ?>" placeholder="Pedido ou código...">
        </div>
        <div class="log_field">
            <label>Status</label>
            <select class="log_select" name="status">
                <option value="">Todos</option>
                <?php foreach (['solicitada' => 'Solicitada', 'autorizada' => 'Autorizada', 'etiqueta_gerada' => 'Etiqueta gerada', 'em_transito' => 'Em trânsito', 'recebida' => 'Recebida', 'cancelada' => 'Cancelada'] as $k => $v): ?>
                    <option value="<?= $k ?>"<?= (($f['status'] ?? '') === $k) ? ' selected' : '' ?>><?= $v ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="log_field">
            <label>Motivo</label>
            <select class="log_select" name="motivo">
                <option value="">Todos</option>
                <?php foreach (['devolucao' => 'Devolução', 'troca' => 'Troca', 'defeito' => 'Defeito', 'arrependimento' => 'Arrependimento', 'avaria' => 'Avaria', 'outro' => 'Outro'] as $k => $v): ?>
                    <option value="<?= $k ?>"<?= (($f['motivo'] ?? '') === $k) ? ' selected' : '' ?>><?= $v ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="log_field">
            <label>Processo</label>
            <select class="log_select" name="processo">
                <option value="">Todos</option>
                <option value="nenhum"<?= (($f['processo'] ?? '') === 'nenhum') ? ' selected' : '' ?>>Nenhum</option>
                <option value="troca"<?= (($f['processo'] ?? '') === 'troca') ? ' selected' : '' ?>>Troca</option>
                <option value="reembolso"<?= (($f['processo'] ?? '') === 'reembolso') ? ' selected' : '' ?>>Reembolso</option>
            </select>
        </div>
        <div class="log_filters_spacer"></div>
    </form>

    <div class="log_card">
        <div class="log_table_wrap">
            <table class="log_table" id="logRevTabela">
                <thead>
                    <tr>
                        <th style="width:80px">Pedido</th>
                        <th style="width:140px">Motivo</th>
                        <th>Transportadora / código</th>
                        <th style="width:150px">Status</th>
                        <th style="width:120px">Processo</th>
                        <th style="width:170px" class="log_col_acoes">Ações</th>
                    </tr>
                </thead>
                <tbody id="logRevBody">
                    <tr><td colspan="6"><div class="log_state"><div class="log_spinner"></div><div class="log_state_desc">Carregando reversas...</div></div></td></tr>
                </tbody>
            </table>
        </div>
        <div class="log_pager" id="logRevPager"></div>
    </div>
</div>

<script>
    window.LOG_REV_BASE = '/admin/logistica/reversas';
    window.LOG_REV_PUBLICO = '/rastreio/';
    window.LOG_REV_TRANSPORTADORAS = <?= json_encode($transportadoras ?? [], JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="/assets/js/reversas.js" defer></script>
