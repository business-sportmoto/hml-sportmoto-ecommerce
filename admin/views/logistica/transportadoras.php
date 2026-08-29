<?php
/**
 * View: Transportadoras (lista + integrações).
 * Recebe: $transportadoras, $catalogo, $filtros
 * O layout 'admin' envelopa esta saída. O formulário (novo/editar) e o
 * visualizador de logs são montados no cliente (logistica.js) a
 * partir do catálogo exposto abaixo — os campos de credencial mudam
 * conforme o adapter selecionado.
 */
$e   = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$int = static fn($v) => number_format((float)$v, 0, ',', '.');
$f   = $filtros ?? [];
$sel = static fn(string $k, string $v) => (($f[$k] ?? '') === $v) ? ' selected' : '';
$ico = static fn($n, $s = 16) => '<span class="log_iw" style="font-size:' . (int)$s . 'px">' . (class_exists('IconLibrary') ? IconLibrary::ref($n, '') : '') . '</span>';

$statusBadge = static function (string $s): array {
    return match ($s) {
        'ativo'   => ['is-ok', 'Ativo'],
        'pausado' => ['is-warn', 'Pausado'],
        default   => ['is-neutral', 'Inativo'],
    };
};
$ambBadge = static function (string $a): array {
    return match ($a) {
        'producao'    => ['is-ok', 'Produção'],
        'homologacao' => ['is-info', 'Homologação'],
        default       => ['is-warn', 'Sandbox'],
    };
};
?>


<div class="log_shell" id="logTransp" data-base="/admin/logistica/transportadoras">

    <div class="log_head">
        <div>
            <h1><?= $ico('caminhao', 15) ?> Transportadoras</h1>
            <p>Integrações, credenciais, serviços e prioridade de cotação.</p>
        </div>
        <div class="log_head_actions">
            <a href="/admin/logistica" class="log_btn log_btn--sm">
                <?= $ico('tower-control', 15) ?> Torre
            </a>
            <button type="button" class="log_btn log_btn--sm" id="logLimparCache" title="Limpa o cache de cotações (o CEP é preservado)">
                <?= $ico('ink-eraser', 15) ?> Limpar cache de frete
            </button>
            <button type="button" class="log_btn log_btn--primary log_btn--sm" id="logTranspNova">
                <?= $ico('add', 15) ?> Nova transportadora
            </button>
        </div>
    </div>

    <!-- Filtros -->
    <form class="log_filters" id="logTranspFiltros" onsubmit="return false;">
        <div class="log_field">
            <label>Buscar</label>
            <input type="text" class="log_input" name="busca" value="<?= $e($f['busca'] ?? '') ?>" placeholder="Nome ou slug...">
        </div>
        <div class="log_field">
            <label>Status</label>
            <select class="log_select" name="status">
                <option value="">Todos</option>
                <option value="ativo"<?= $sel('status','ativo') ?>>Ativo</option>
                <option value="pausado"<?= $sel('status','pausado') ?>>Pausado</option>
                <option value="inativo"<?= $sel('status','inativo') ?>>Inativo</option>
            </select>
        </div>
        <div class="log_filters_spacer"></div>
    </form>

    <!-- Lista -->
    <div class="log_card">
        <div class="log_table_wrap">
            <table class="log_table" id="logTranspTabela">
                <thead>
                    <tr>
                        <th style="width:64px">Ordem</th>
                        <th>Transportadora</th>
                        <th style="width:130px">Ambiente</th>
                        <th style="width:90px">Serviços</th>
                        <th style="width:150px">Status</th>
                        <th style="width:160px">Última sync</th>
                        <th style="width:150px" class="log_col_acoes">Ações</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($transportadoras)): ?>
                    <tr class="log_empty_row">
                        <td colspan="7">
                            <div class="log_state">
                                <div class="log_state_ico"><?= $ico('caminhao', 15) ?></div>
                                <div class="log_state_title">Nenhuma transportadora cadastrada</div>
                                <div class="log_state_desc">Cadastre a primeira para começar a cotar e emitir etiquetas.</div>
                            </div>
                        </td>
                    </tr>
                <?php else: foreach ($transportadoras as $t):
                    [$stCls, $stTxt] = $statusBadge((string)$t['status']);
                    [$amCls, $amTxt] = $ambBadge((string)$t['ambiente']);
                    $sync = !empty($t['ultima_sync']) ? date('d/m/Y H:i', strtotime((string)$t['ultima_sync'])) : '—';
                ?>
                    <tr data-id="<?= (int)$t['id'] ?>" data-status="<?= $e($t['status']) ?>">
                        <td>
                            <div class="log_ordem">
                                <button type="button" class="log_btn log_btn--icon log_btn--xs js-mover" data-dir="cima" title="Subir prioridade" aria-label="Subir prioridade"><?= $ico('arrow-up', 15) ?></button>
                                <button type="button" class="log_btn log_btn--icon log_btn--xs js-mover" data-dir="baixo" title="Descer prioridade" aria-label="Descer prioridade"><?= $ico('arrow-down', 15) ?></button>
                            </div>
                        </td>
                        <td>
                            <div class="log_transp">
                                <?php if (!empty($t['logo_url'])): ?>
                                    <img class="log_transp_logo" src="<?= $e($t['logo_url']) ?>" alt="">
                                <?php else: ?>
                                    <span class="log_transp_ph"><?= $ico('caminhao', 15) ?></span>
                                <?php endif; ?>
                                <div class="log_transp_info">
                                    <strong><?= $e($t['nome']) ?></strong>
                                    <span class="log_muted"><?= $e($t['adapter_label'] ?? $t['adapter']) ?> · <span class="log_mono"><?= $e($t['slug']) ?></span></span>
                                </div>
                            </div>
                        </td>
                        <td><span class="log_badge <?= $amCls ?> log_badge--plain"><?= $e($amTxt) ?></span></td>
                        <td><span class="log_mono"><?= $int($t['servicos_qtd'] ?? 0) ?></span></td>
                        <td>
                            <label class="log_toggle" title="Ativar / pausar">
                                <input type="checkbox" class="js-status" <?= ($t['status'] === 'ativo') ? 'checked' : '' ?>>
                                <span class="log_toggle_track"></span>
                                <span class="log_toggle_txt log_badge <?= $stCls ?> log_badge--plain js-status-txt"><?= $e($stTxt) ?></span>
                            </label>
                        </td>
                        <td class="log_muted"><?= $e($sync) ?></td>
                        <td class="log_col_acoes">
                            <button type="button" class="log_btn log_btn--icon js-testar" title="Testar conexão" aria-label="Testar conexão"><?= $ico('plug', 15) ?></button>
                            <button type="button" class="log_btn log_btn--icon js-logs" title="Ver logs" aria-label="Ver logs"><?= $ico('stacks', 15) ?></button>
                            <button type="button" class="log_btn log_btn--icon js-editar" title="Editar" aria-label="Editar"><?= $ico('pencil', 15) ?></button>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
    window.LOG_TRANSP_BASE = '/admin/logistica/transportadoras';
    window.LOG_CATALOGO    = <?= json_encode($catalogo ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
