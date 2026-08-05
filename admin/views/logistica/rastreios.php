<?php
/**
 * View: Rastreios (lista admin).
 * Recebe: $transportadoras, $filtros. Detalhe/timeline em rastreios.js.
 */
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$ico = static fn($n, $s = 16) => '<span class="log_iw" style="font-size:' . (int)$s . 'px">' . (class_exists('IconLibrary') ? IconLibrary::render($n) : '') . '</span>';
$f = $filtros ?? [];
?>
<link rel="stylesheet" href="/assets/css/logistica.css">

<div class="log_shell" id="logRas" data-base="/admin/logistica/rastreios">

    <div class="log_head">
        <div>
            <h1><?= $ico('localizacao', 22) ?> Rastreios</h1>
            <p>Timeline normalizada dos envios, atualização automática e link público para o cliente.</p>
        </div>
        <div class="log_head_actions">
            <a href="/admin/logistica" class="log_btn log_btn--sm"><?= $ico('caminhao', 15) ?> Torre</a>
            <a href="/admin/logistica/etiquetas" class="log_btn log_btn--sm"><?= $ico('etiqueta', 15) ?> Etiquetas</a>
        </div>
    </div>

    <form class="log_filters" id="logRasFiltros" onsubmit="return false;">
        <div class="log_field">
            <label>Buscar</label>
            <input type="text" class="log_input" name="busca" value="<?= $e($f['busca'] ?? '') ?>" placeholder="Pedido, código ou destinatário...">
        </div>
        <div class="log_field">
            <label>Status</label>
            <select class="log_select" name="status">
                <option value="">Todos</option>
                <?php foreach (['etiqueta_emitida' => 'Etiqueta emitida', 'postado' => 'Postado', 'em_transito' => 'Em trânsito', 'saiu_entrega' => 'Saiu para entrega', 'entregue' => 'Entregue', 'devolucao' => 'Em devolução', 'ocorrencia' => 'Ocorrência'] as $k => $v): ?>
                    <option value="<?= $k ?>"<?= (($f['status'] ?? '') === $k) ? ' selected' : '' ?>><?= $v ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="log_field">
            <label>Transportadora</label>
            <select class="log_select" name="transportadora_id">
                <option value="">Todas</option>
                <?php foreach (($transportadoras ?? []) as $t): ?>
                    <option value="<?= (int)$t['id'] ?>"<?= (($f['transportadora_id'] ?? 0) === (int)$t['id']) ? ' selected' : '' ?>><?= $e($t['nome']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="log_field log_field--check">
            <label><input type="checkbox" name="atraso" value="1"<?= !empty($f['atraso']) ? ' checked' : '' ?>> Só atrasados</label>
            <label><input type="checkbox" name="ocorrencia" value="1"<?= !empty($f['ocorrencia']) ? ' checked' : '' ?>> Só com ocorrência</label>
        </div>
        <div class="log_filters_spacer"></div>
    </form>

    <div class="log_card">
        <div class="log_table_wrap">
            <table class="log_table" id="logRasTabela">
                <thead>
                    <tr>
                        <th style="width:80px">Pedido</th>
                        <th style="width:160px">Código</th>
                        <th>Destino</th>
                        <th style="width:170px">Status</th>
                        <th style="width:130px">Atualizado</th>
                        <th style="width:170px" class="log_col_acoes">Ações</th>
                    </tr>
                </thead>
                <tbody id="logRasBody">
                    <tr><td colspan="6"><div class="log_state"><div class="log_spinner"></div><div class="log_state_desc">Carregando rastreios...</div></div></td></tr>
                </tbody>
            </table>
        </div>
        <div class="log_pager" id="logRasPager"></div>
    </div>
</div>

<script>
    window.LOG_RAS_BASE = '/admin/logistica/rastreios';
    window.LOG_RAS_PUBLICO = '/rastreio/';
</script>
<script src="/assets/js/rastreios.js" defer></script>
