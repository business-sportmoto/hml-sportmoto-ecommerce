<?php
/**
 * View: Etiquetas (lista + ações + lote/manifesto).
 * Recebe: $transportadoras (com serviços), $filtros
 * Ações por linha e formulário "nova etiqueta" montados em etiquetas.js.
 */
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$ico = static fn($n, $s = 16) => '<span class="log_iw" style="font-size:' . (int)$s . 'px">' . (class_exists('IconLibrary') ? IconLibrary::render($n) : '') . '</span>';
$f = $filtros ?? [];
?>


<div class="log_shell" id="logEtq" data-base="/admin/logistica/etiquetas">

    <div class="log_head">
        <div>
            <h1><?= $ico('etiqueta', 22) ?> Etiquetas</h1>
            <p>Emitir, imprimir, cancelar e gerar manifesto — com idempotência ponta a ponta.</p>
        </div>
        <div class="log_head_actions">
            <a href="/admin/logistica" class="log_btn log_btn--sm"><?= $ico('caminhao', 15) ?> Torre</a>
            <button type="button" class="log_btn log_btn--primary log_btn--sm" id="logEtqNova"><i class="bi bi-plus-lg"></i> Nova etiqueta</button>
        </div>
    </div>

    <form class="log_filters" id="logEtqFiltros" onsubmit="return false;">
        <div class="log_field">
            <label>Buscar</label>
            <input type="text" class="log_input" name="busca" value="<?= $e($f['busca'] ?? '') ?>" placeholder="Pedido, rastreio ou ID externo...">
        </div>
        <div class="log_field">
            <label>Status</label>
            <select class="log_select" name="status">
                <option value="">Todos</option>
                <?php foreach (['aguardando_postagem' => 'Aguardando', 'emitida' => 'Emitida', 'postada' => 'Postada', 'cancelada' => 'Cancelada', 'erro' => 'Erro'] as $k => $v): ?>
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
        <div class="log_filters_spacer"></div>
    </form>

    <!-- barra de seleção (lote / manifesto) -->
    <div class="log_sel_bar" id="logEtqSelBar" style="display:none;">
        <strong id="logEtqSelCount">0</strong> selecionada(s)
        <div class="log_sel_actions">
            <button type="button" class="log_btn log_btn--sm js-lote-comprar"><?= $ico('carrinho', 15) ?> Comprar em lote</button>
            <button type="button" class="log_btn log_btn--sm js-manifesto"><?= $ico('manifesto', 15) ?> Gerar manifesto</button>
        </div>
    </div>

    <div class="log_card">
        <div class="log_table_wrap">
            <table class="log_table" id="logEtqTabela">
                <thead>
                    <tr>
                        <th class="log_check_col"><input type="checkbox" id="logEtqAll"></th>
                        <th style="width:90px">Pedido</th>
                        <th>Transportadora / serviço</th>
                        <th style="width:150px">Rastreio</th>
                        <th style="width:120px">Status</th>
                        <th style="width:220px" class="log_col_acoes">Ações</th>
                    </tr>
                </thead>
                <tbody id="logEtqBody">
                    <tr><td colspan="6"><div class="log_state"><div class="log_spinner"></div><div class="log_state_desc">Carregando etiquetas...</div></div></td></tr>
                </tbody>
            </table>
        </div>
        <div class="log_pager" id="logEtqPager"></div>
    </div>
</div>

<script>
    window.LOG_ETQ_BASE = '/admin/logistica/etiquetas';
    window.LOG_ETQ_TRANSPORTADORAS = <?= json_encode($transportadoras ?? [], JSON_UNESCAPED_UNICODE) ?>;
</script>

