<?php
/**
 * View: Divergências + Alertas de produto (admin).
 * Recebe: $transportadoras, $filtros. Lógica em divergencias.js.
 */
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$ico = static fn($n, $s = 16) => '<span class="log_iw" style="font-size:' . (int)$s . 'px">' . (class_exists('IconLibrary') ? IconLibrary::render($n) : '') . '</span>';
$f = $filtros ?? [];
?>
<link rel="stylesheet" href="/assets/css/logistica.css">

<div class="log_shell" id="logDiv" data-base="/admin/logistica/divergencias">

    <div class="log_head">
        <div>
            <h1><?= $ico('divergencia', 22) ?> Divergências</h1>
            <p>Quebras entre o cotado e o cobrado, com alertas por produto para atacar a causa raiz.</p>
        </div>
        <div class="log_head_actions">
            <a href="/admin/logistica" class="log_btn log_btn--sm"><?= $ico('caminhao', 15) ?> Torre</a>
            <button type="button" class="log_btn log_btn--primary log_btn--sm" id="logDivNova"><i class="bi bi-plus-lg"></i> Nova divergência</button>
        </div>
    </div>

    <!-- KPIs -->
    <div class="logd_kpis" id="logDivKpis">
        <div class="logd_kpi"><div class="logd_kpi_l">Em aberto</div><div class="logd_kpi_v" id="kpiAbertas">—</div></div>
        <div class="logd_kpi"><div class="logd_kpi_l">Impacto acumulado</div><div class="logd_kpi_v" id="kpiImpacto">—</div></div>
        <div class="logd_kpi"><div class="logd_kpi_l">Alertas de produto</div><div class="logd_kpi_v" id="kpiAlertas">—</div></div>
    </div>

    <!-- controle segmentado -->
    <div class="logd_seg" role="tablist">
        <button type="button" class="logd_seg_btn is-active" data-pane="div"><?= $ico('divergencia', 14) ?> Divergências</button>
        <button type="button" class="logd_seg_btn" data-pane="ale"><?= $ico('alerta', 14) ?> Alertas de produto</button>
    </div>

    <!-- pane: divergências -->
    <div id="logDivPane">
        <form class="log_filters" id="logDivFiltros" onsubmit="return false;">
            <div class="log_field"><label>Buscar</label><input type="text" class="log_input" name="busca" value="<?= $e($f['busca'] ?? '') ?>" placeholder="Pedido ou motivo..."></div>
            <div class="log_field"><label>Status</label>
                <select class="log_select" name="status">
                    <option value="">Todos</option>
                    <?php foreach (['aberta' => 'Aberta', 'em_analise' => 'Em análise', 'resolvida' => 'Resolvida', 'ignorada' => 'Ignorada'] as $k => $v): ?>
                        <option value="<?= $k ?>"<?= (($f['status'] ?? '') === $k) ? ' selected' : '' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="log_field"><label>Impacto</label>
                <select class="log_select" name="nivel">
                    <option value="">Todos</option>
                    <option value="alto">Alto</option><option value="medio">Médio</option><option value="baixo">Baixo</option>
                </select>
            </div>
            <div class="log_filters_spacer"></div>
        </form>

        <div class="log_card">
            <div class="log_table_wrap">
                <table class="log_table" id="logDivTabela">
                    <thead><tr>
                        <th style="width:80px">Pedido</th>
                        <th>Transportadora</th>
                        <th style="width:150px">Diferença</th>
                        <th style="width:100px">Impacto</th>
                        <th style="width:120px">Status</th>
                        <th style="width:170px" class="log_col_acoes">Ações</th>
                    </tr></thead>
                    <tbody id="logDivBody"><tr><td colspan="6"><div class="log_state"><div class="log_spinner"></div></div></td></tr></tbody>
                </table>
            </div>
            <div class="log_pager" id="logDivPager"></div>
        </div>
    </div>

    <!-- pane: alertas -->
    <div id="logAlePane" style="display:none;">
        <form class="log_filters" id="logAleFiltros" onsubmit="return false;">
            <div class="log_field"><label>Produto (id)</label><input type="text" class="log_input" name="busca" placeholder="ID do produto..."></div>
            <div class="log_field"><label>Situação</label>
                <select class="log_select" name="status">
                    <option value="aberto">Abertos</option>
                    <option value="resolvido">Resolvidos</option>
                    <option value="todos">Todos</option>
                </select>
            </div>
            <div class="log_field"><label>Tipo</label>
                <select class="log_select" name="tipo">
                    <option value="">Todos</option>
                    <option value="peso">Peso</option><option value="dimensao">Dimensão</option>
                    <option value="embalagem">Embalagem</option><option value="misto">Misto</option>
                </select>
            </div>
            <div class="log_filters_spacer"></div>
        </form>

        <div class="log_card">
            <div class="log_table_wrap">
                <table class="log_table" id="logAleTabela">
                    <thead><tr>
                        <th style="width:100px">Produto</th>
                        <th style="width:130px">Tipo</th>
                        <th style="width:120px">Ocorrências</th>
                        <th>Impacto acumulado</th>
                        <th style="width:110px">Situação</th>
                        <th style="width:170px" class="log_col_acoes">Ações</th>
                    </tr></thead>
                    <tbody id="logAleBody"><tr><td colspan="6"><div class="log_state"><div class="log_spinner"></div></div></td></tr></tbody>
                </table>
            </div>
            <div class="log_pager" id="logAlePager"></div>
        </div>
    </div>
</div>

<script>
    window.LOG_DIV_BASE = '/admin/logistica/divergencias';
    window.LOG_DIV_TRANSPORTADORAS = <?= json_encode($transportadoras ?? [], JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="/assets/js/divergencias.js" defer></script>
