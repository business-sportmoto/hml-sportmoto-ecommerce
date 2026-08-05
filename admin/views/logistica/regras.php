<?php
/**
 * View: Regras de frete (lista).
 * Recebe: $regras, $campos, $opers, $filtros
 * Formulário (nova/editar) montado no cliente (frete.js) — condições e ações.
 */
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$ico = static fn($n, $s = 16) => '<span class="log_iw" style="font-size:' . (int)$s . 'px">' . (class_exists('IconLibrary') ? IconLibrary::render($n) : '') . '</span>';
$f = $filtros ?? [];
?>
<link rel="stylesheet" href="/assets/css/logistica.css">

<div class="log_shell" id="logRegras" data-base="/admin/logistica/regras">

    <div class="log_head">
        <div>
            <h1><?= $ico('regras', 22) ?> Regras de frete</h1>
            <p>Frete grátis, subsídio com teto, descontos, bloqueios e ocultação de serviços.</p>
        </div>
        <div class="log_head_actions">
            <a href="/admin/logistica/simulador" class="log_btn log_btn--sm"><?= $ico('simulador', 15) ?> Simulador</a>
            <a href="/admin/logistica" class="log_btn log_btn--sm"><?= $ico('caminhao', 15) ?> Torre</a>
            <button type="button" class="log_btn log_btn--primary log_btn--sm" id="logRegraNova"><i class="bi bi-plus-lg"></i> Nova regra</button>
        </div>
    </div>

    <form class="log_filters" id="logRegrasFiltros" onsubmit="return false;">
        <div class="log_field">
            <label>Buscar</label>
            <input type="text" class="log_input" name="busca" value="<?= $e($f['busca'] ?? '') ?>" placeholder="Nome da regra...">
        </div>
        <div class="log_field">
            <label>Situação</label>
            <select class="log_select" name="ativa">
                <option value="">Todas</option>
                <option value="1"<?= (($f['ativa'] ?? '') === 1 || ($f['ativa'] ?? '') === '1') ? ' selected' : '' ?>>Ativas</option>
                <option value="0"<?= (($f['ativa'] ?? '') === 0 || ($f['ativa'] ?? '') === '0') ? ' selected' : '' ?>>Inativas</option>
            </select>
        </div>
        <div class="log_filters_spacer"></div>
    </form>

    <div class="log_card">
        <div class="log_table_wrap">
            <table class="log_table" id="logRegrasTabela">
                <thead>
                    <tr>
                        <th style="width:64px">Ordem</th>
                        <th>Regra</th>
                        <th style="width:90px">Condições</th>
                        <th style="width:260px">Ações</th>
                        <th style="width:130px">Situação</th>
                        <th style="width:110px" class="log_col_acoes">Gerir</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($regras)): ?>
                    <tr><td colspan="6">
                        <div class="log_state">
                            <div class="log_state_ico"><?= $ico('regras', 22) ?></div>
                            <div class="log_state_title">Nenhuma regra cadastrada</div>
                            <div class="log_state_desc">Crie regras para controlar frete grátis, subsídios e descontos.</div>
                        </div>
                    </td></tr>
                <?php else: foreach ($regras as $r):
                    $ag = '';
                    if (!empty($r['inicio_em']) || !empty($r['fim_em'])) {
                        $ini = !empty($r['inicio_em']) ? date('d/m', strtotime((string)$r['inicio_em'])) : '…';
                        $fim = !empty($r['fim_em']) ? date('d/m', strtotime((string)$r['fim_em'])) : '…';
                        $ag = $ini . ' → ' . $fim;
                    }
                ?>
                    <tr data-id="<?= (int)$r['id'] ?>">
                        <td><div class="log_ordem">
                            <button type="button" class="log_btn log_btn--icon log_btn--xs js-mover" data-dir="cima" title="Subir"><i class="bi bi-chevron-up"></i></button>
                            <button type="button" class="log_btn log_btn--icon log_btn--xs js-mover" data-dir="baixo" title="Descer"><i class="bi bi-chevron-down"></i></button>
                        </div></td>
                        <td>
                            <div class="log_transp_info">
                                <strong><?= $e($r['nome']) ?><?= !empty($r['acumulativa']) ? ' <span class="log_badge is-info log_badge--plain">acumulativa</span>' : '' ?></strong>
                                <?php if (!empty($r['descricao'])): ?><span class="log_muted"><?= $e($r['descricao']) ?></span><?php endif; ?>
                                <?php if ($ag): ?><span class="log_muted"><i class="bi bi-calendar-event"></i> <?= $e($ag) ?></span><?php endif; ?>
                            </div>
                        </td>
                        <td><span class="log_mono"><?= (int)($r['condicoes_qtd'] ?? 0) ?></span></td>
                        <td>
                            <div class="log_chips">
                                <?php foreach (($r['resumo_acoes'] ?? []) as $chip): ?>
                                    <span class="log_chip"><?= $e($chip) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td>
                            <label class="log_toggle" title="Ativar / desativar">
                                <input type="checkbox" class="js-status" <?= !empty($r['ativa']) ? 'checked' : '' ?>>
                                <span class="log_toggle_track"></span>
                                <span class="log_toggle_txt log_badge <?= !empty($r['ativa']) ? 'is-ok' : 'is-neutral' ?> log_badge--plain js-status-txt"><?= !empty($r['ativa']) ? 'Ativa' : 'Inativa' ?></span>
                            </label>
                        </td>
                        <td class="log_col_acoes">
                            <button type="button" class="log_btn log_btn--icon js-editar" title="Editar"><i class="bi bi-pencil"></i></button>
                            <button type="button" class="log_btn log_btn--icon js-remover" title="Remover"><i class="bi bi-trash"></i></button>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    window.LOG_REGRAS_BASE   = '/admin/logistica/regras';
    window.LOG_REGRAS_CAMPOS = <?= json_encode($campos ?? [], JSON_UNESCAPED_UNICODE) ?>;
    window.LOG_REGRAS_OPERS  = <?= json_encode($opers ?? [], JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="/assets/js/frete.js" defer></script>
