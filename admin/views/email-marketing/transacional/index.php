<?php
/** @var array $templates */
/** @var array $mapa */
/** @var array $kpis */
/** @var array $por_tipo */
$base = defined('BASE_URL') ? BASE_URL : '';
?>
<div class="em_wrapper" data-base="<?= htmlspecialchars($base) ?>">
    <div class="em_header">
        <h1>Emails Transacionais</h1>
        <div class="em_actions">
            <a href="<?= $base ?>/admin/email-marketing/transacional/log" class="em_btn">Ver log</a>
            <a href="<?= $base ?>/admin/email-marketing/templates/criar" class="em_btn em_btn_primary">+ Novo template</a>
        </div>
    </div>

    <div class="em_aviso" style="margin-bottom:20px;">
        Emails transacionais usam o <strong>mesmo provedor padrão</strong> configurado no Email Marketing.
        Para editar o layout de qualquer email, clique em <strong>Editar template</strong>.
        Para ver se estão chegando, clique em <strong>Ver log</strong>.
    </div>

    <!-- KPIs 30 dias -->
    <div class="em_kpi_grid" style="margin-bottom:24px;">
        <div class="em_card">
            <span class="em_card_label">Enviados (30d)</span>
            <span class="em_card_value"><?= number_format((int)($kpis['enviados']??0), 0, ',', '.') ?></span>
        </div>
        <div class="em_card">
            <span class="em_card_label em_warn">Erros (30d)</span>
            <span class="em_card_value"><?= number_format((int)($kpis['erros']??0), 0, ',', '.') ?></span>
        </div>
        <div class="em_card">
            <span class="em_card_label">Total (30d)</span>
            <span class="em_card_value"><?= number_format((int)($kpis['total']??0), 0, ',', '.') ?></span>
        </div>
    </div>

    <!-- Templates mapeados -->
    <h2>Templates configurados</h2>
    <table class="em_table">
        <thead><tr>
            <th>Tipo</th><th>Template</th><th>Status</th><th>Enviados (30d)</th><th>Erros (30d)</th><th>Último envio</th><th></th>
        </tr></thead>
        <tbody>
        <?php
        // Indexa por nome para cruzar com mapa
        $tplPorNome = [];
        foreach ($templates as $t) $tplPorNome[$t['nome']] = $t;

        // Indexa KPIs por tipo
        $kpiTipo = [];
        foreach ($por_tipo as $k) $kpiTipo[$k['tipo']] = $k;

        foreach ($mapa as $tipo => $nomeTemplate):
            $tpl = $tplPorNome[$nomeTemplate] ?? null;
            $k   = $kpiTipo[$tipo] ?? [];
        ?>
            <tr>
                <td>
                    <code style="font-size:12px;"><?= htmlspecialchars($tipo) ?></code>
                </td>
                <td>
                    <?php if ($tpl): ?>
                        <strong><?= htmlspecialchars($tpl['nome']) ?></strong>
                    <?php else: ?>
                        <span style="color:var(--em-warn-text);">— não encontrado —</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($tpl): ?>
                        <span class="em_badge em_st_<?= htmlspecialchars($tpl['status']) ?>"><?= htmlspecialchars($tpl['status']) ?></span>
                    <?php else: ?>
                        <span class="em_badge em_st_falhou">sem template</span>
                    <?php endif; ?>
                </td>
                <td><?= number_format((int)($k['enviados']??0), 0, ',', '.') ?></td>
                <td><?= (int)($k['erros']??0) > 0 ? '<span style="color:var(--em-warn-text);">' . (int)$k['erros'] . '</span>' : '0' ?></td>
                <td><?= !empty($k['ultimo_envio']) ? date('d/m H:i', strtotime($k['ultimo_envio'])) : '—' ?></td>
                <td class="em_actions_cell">
                    <?php if ($tpl): ?>
                        <a href="<?= $base ?>/admin/email-marketing/templates/<?= (int)$tpl['id'] ?>/editar" class="em_link">Editar template</a>
                    <?php else: ?>
                        <a href="<?= $base ?>/admin/email-marketing/templates/criar" class="em_link">Criar template</a>
                    <?php endif; ?>
                    <button type="button" class="em_link"
                            data-em-action="transacional-testar"
                            data-tipo="<?= htmlspecialchars($tipo) ?>">
                        Testar
                    </button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Modal de teste -->
    <div id="em_modal_transacional_teste" class="em_modal" style="display:none;">
        <div class="em_modal_box">
            <div class="em_modal_header">
                <h3>Enviar email de teste</h3>
                <button type="button" class="em_modal_close">✕</button>
            </div>
            <div class="em_form">
                <input type="hidden" id="em_teste_tipo" value="">
                <label>Tipo: <strong id="em_teste_tipo_label"></strong></label>
                <label>Email para receber o teste
                    <input type="email" id="em_teste_email" placeholder="seu@email.com">
                </label>
                <div class="em_form_actions">
                    <button type="button" class="em_btn em_btn_primary" data-em-action="transacional-testar-enviar">Enviar teste</button>
                </div>
            </div>
        </div>
    </div>
</div>
