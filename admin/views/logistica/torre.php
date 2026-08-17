<?php
/**
 * View: Torre de Controle.
 * Recebe: $kpis, $distribuicao, $alertas, $periodo, $opcoes, $filtros, $podeVerCustos
 * O layout 'admin' envelopa esta saída. Os assets abaixo podem ser movidos
 * para o <head> do layout admin, se preferir enfileirar globalmente.
 */
$e   = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$int = static fn($v) => number_format((float)$v, 0, ',', '.');
$brl = static fn($v) => 'R$ ' . number_format((float)$v, 2, ',', '.');
$pct = static fn($v) => number_format((float)$v, 1, ',', '.');
$f   = $filtros ?? [];
$sel = static fn(string $k, string $v) => (($f[$k] ?? '') === $v) ? ' selected' : '';

// Escala das barras de distribuição
$maxDist = 1;
foreach (($distribuicao ?? []) as $d) { $maxDist = max($maxDist, (int)$d['qtd']); }
?>

<?php
/* -----------------------------------------------------------------
   Fragmentos reutilizados no primeiro render E no refresh via JS
   (o endpoint devolve JSON; o JS reconstrói o mesmo HTML no cliente).
   Mantidos aqui para uma única fonte de marcação server-side.
   Guardados contra redeclaração caso o layout inclua a view 2x.
   ----------------------------------------------------------------- */

if (!function_exists('view_logistica_kpis')):

function view_logistica_kpis(array $k, bool $podeCustos, callable $int, callable $brl, callable $pct): string
{
    $cards = [
        ['torre-pack',            '',          $int($k['total_envios']),          'Total de envios',                 true],
        ['check-circle',       'is-ok',     $int($k['entregues']),             'Entregues',                       false],
        ['truck',               'is-info',   $int($k['em_transito']),           'Em trânsito',                     false],
        ['relogio',       'is-ok',     $pct($k['no_prazo_pct']) . '<small>%</small>', 'No prazo',            false],
        ['alert-triangle','is-danger', $int($k['atrasados']),             'Atrasados',                       false],
        ['flag',                'is-warn',   $int($k['ocorrencias']),           'Com ocorrências',                 false],
        ['printer',             'is-neutral',$int($k['etiquetas_aguardando']),  'Etiquetas aguardando postagem',   false],
        ['reversa',   '',          $int($k['reversas_abertas']),      'Solicitações de reversa',         false],
        ['calendar-today',      'is-neutral',$pct($k['prazo_medio']) . '<small>d</small>', 'Prazo médio de entrega', false],
        ['wifi-off',            'is-warn',   $int($k['falhas_integracao']),     'Falhas de integração',            false],
    ];

    $html = '';
    foreach ($cards as [$ico, $cls, $val, $lbl, $accent]) {
        $html .= '<div class="log_kpi' . ($accent ? ' log_kpi--accent' : '') . '">'
               . '<div class="log_kpi_ico ' . $cls . '"><span class="log_iw">'.IconLibrary::render($ico).'</span></div>'
               . '<div class="log_kpi_val">' . $val . '</div>'
               . '<div class="log_kpi_lbl">' . htmlspecialchars($lbl, ENT_QUOTES, 'UTF-8') . '</div>'
               . '</div>';
    }

    // Cards de custo — só para autorizados
    if ($podeCustos) {
        $html .= view_logistica_kpi_custo('price-check', 'is-info', $brl($k['gasto_fretes']), 'Gasto com fretes');
        $html .= view_logistica_kpi_custo('scale-check', 'is-danger', $brl($k['divergencias_valor']), 'Divergências acumuladas');
    } else {
        $html .= view_logistica_kpi_bloqueado('Gasto com fretes');
        $html .= view_logistica_kpi_bloqueado('Divergências acumuladas');
    }
    return $html;
}

function view_logistica_kpi_custo(string $ico, string $cls, string $val, string $lbl): string
{
    return '<div class="log_kpi"><div class="log_kpi_ico ' . $cls . '"><span class="log_iw">'.IconLibrary::render($ico).'</span></div>'
         . '<div class="log_kpi_val">' . $val . '</div>'
         . '<div class="log_kpi_lbl">' . htmlspecialchars($lbl, ENT_QUOTES, 'UTF-8') . '</div></div>';
}

function view_logistica_kpi_bloqueado(string $lbl): string
{
    return '<div class="log_kpi"><div class="log_kpi_ico is-neutral"><span class="log_iw">'.IconLibrary::render('lock').'</span</div>'
         . '<div class="log_kpi_val log_muted" style="font-size:16px">restrito</div>'
         . '<div class="log_kpi_lbl">' . htmlspecialchars($lbl, ENT_QUOTES, 'UTF-8') . '</div></div>';
}

function view_logistica_alertas(array $alertas): string
{
    if (empty($alertas)) {
        return '<div class="log_state" style="padding:28px 12px">'
             . '<div class="log_state_ico"><span class="log_iw">'.IconLibrary::render('check-circle').'</span</div>'
             . '<div class="log_state_title">Tudo sob controle</div>'
             . '<div class="log_state_desc">Nenhum alerta operacional no momento.</div></div>';
    }
    $html = '';
    foreach ($alertas as $a) {
        $nivel = htmlspecialchars($a['nivel'] ?? 'info', ENT_QUOTES, 'UTF-8');
        $html .= '<div class="log_alert is-' . $nivel . '">'
               . '<div class="log_alert_ico"><span class="log_iw">'.IconLibrary::render('info').'</span></div>'
               . '<div class="log_alert_body">'
               . '<div class="log_alert_title">' . htmlspecialchars($a['titulo'] ?? '', ENT_QUOTES, 'UTF-8') . '</div>'
               . '<div class="log_alert_desc">' . htmlspecialchars($a['descricao'] ?? '', ENT_QUOTES, 'UTF-8') . '</div>'
               . '</div>'
               . '<a href="' . htmlspecialchars($a['link'] ?? '#', ENT_QUOTES, 'UTF-8') . '" class="log_btn log_btn--sm"><span class="log_iw">'.IconLibrary::render('arrow-up').'</span> Ver</a>'
               . '</div>';
    }
    return $html;
}

endif; // function_exists guard

$ico = static fn($n, $s = 16) => '<span class="log_iw" style="font-size:' . (int)$s . 'px">' . (class_exists('IconLibrary') ? IconLibrary::render($n) : '') . '</span>';
?>

<div class="log_shell" id="logTorre" data-endpoint="/admin/logistica/torre/dados">

    <div class="log_head">
        <div>
            <h1><?= $ico('tower-control', 22) ?> Torre de Controle</h1>
            <p>Visão geral da operação logística · <span id="logAtualizado"><?= $e($periodo ?? '') ?></span></p>
        </div>
        <div class="log_head_actions">
            <button type="button" class="log_btn log_btn--icon" id="logRefresh" title="Atualizar">
                <?= $ico('reload', 20) ?>
            </button>
            <a href="/admin/logistica/rastreios" class="log_btn log_btn--sm">
                <?= $ico('globe-location', 20) ?> Rastreios
            </a>
            <a href="/admin/logistica/etiquetas" class="log_btn log_btn--primary log_btn--sm">
                <?= $ico('etiqueta', 20) ?> Etiquetas
            </a>
        </div>
    </div>

    <!-- Filtros -->
    <form class="log_filters" id="logFiltros" onsubmit="return false;">
        <div class="log_field">
            <label>Período</label>
            <select class="log_select" name="periodo">
                <option value="7d"<?= $sel('periodo','7d') ?>>Últimos 7 dias</option>
                <option value="30d"<?= $sel('periodo','30d') ?>>Últimos 30 dias</option>
                <option value="mes"<?= $sel('periodo','mes') ?>>Este mês</option>
                <option value="hoje"<?= $sel('periodo','hoje') ?>>Hoje</option>
            </select>
        </div>
        <div class="log_field">
            <label>Transportadora</label>
            <select class="log_select" name="transportadora_id">
                <option value="">Todas</option>
                <?php foreach (($opcoes['transportadoras'] ?? []) as $t): ?>
                    <option value="<?= (int)$t['id'] ?>"<?= $sel('transportadora_id', (string)$t['id']) ?>><?= $e($t['nome']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="log_field">
            <label>Status</label>
            <select class="log_select" name="status">
                <option value="">Todos</option>
                <?php foreach (($opcoes['status'] ?? []) as $k => $rot): ?>
                    <option value="<?= $e($k) ?>"<?= $sel('status', (string)$k) ?>><?= $e($rot) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="log_field">
            <label>UF</label>
            <select class="log_select" name="uf">
                <option value="">Todas</option>
                <?php foreach (($opcoes['ufs'] ?? []) as $uf): ?>
                    <option value="<?= $e($uf) ?>"<?= $sel('uf', (string)$uf) ?>><?= $e($uf) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="log_field">
            <label>Canal</label>
            <select class="log_select" name="canal">
                <option value="">Todos</option>
                <?php foreach (($opcoes['canais'] ?? []) as $c): ?>
                    <option value="<?= $e($c) ?>"<?= $sel('canal', (string)$c) ?>><?= $e(ucfirst($c)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="log_filters_spacer"></div>
        <button type="button" class="log_btn log_btn--primary" id="logAplicar" style="align-self:flex-end">
            <?= $ico('check', 22) ?> Aplicar
        </button>
    </form>

    <div class="log_dynamic">

        <!-- KPIs -->
        <div class="log_kpi_grid" id="logKpis">
            <?= view_logistica_kpis($kpis, $podeVerCustos ?? true, $int, $brl, $pct) ?>
        </div>

        <div class="log_grid_2">
            <!-- Distribuição -->
            <div class="log_card">
                <div class="log_card_head">
                    <h2>Distribuição das entregas por prazo</h2>
                    <span class="log_badge is-brand log_badge--plain"><?= $ico('torre-pack', 22) ?> <?= $int($kpis['entregues']) ?> entregues</span>
                </div>
                <div class="log_card_body">
                    <div class="log_dist" id="logDist">
                        <?php foreach (($distribuicao ?? []) as $d):
                            $h = max(4, round(((int)$d['qtd'] / $maxDist) * 100)); ?>
                            <div class="log_dist_col">
                                <div class="log_dist_val"><?= $int($d['qtd']) ?></div>
                                <div class="log_dist_bar"<?= !empty($d['atraso']) ? ' data-late="1"' : '' ?> style="height:<?= $h ?>%"></div>
                                <div class="log_dist_lbl"><?= $e($d['rotulo']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Alertas -->
            <div class="log_card">
                <div class="log_card_head">
                    <h2>Alertas operacionais</h2>
                    <span class="log_badge <?= empty($alertas) ? 'is-ok' : 'is-danger' ?> log_badge--plain"><?= count($alertas ?? []) ?></span>
                </div>
                <div class="log_card_body" id="logAlertas">
                    <?= view_logistica_alertas($alertas ?? []) ?>
                </div>
            </div>
        </div>

    </div><!-- /log_dynamic -->
</div>

