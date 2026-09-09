<?php
/**
 * admin/views/email-marketing/templates/index.php
 *
 * Lista com resumo, filtro, busca e paginação. A lista cresce sozinha — cada
 * campanha montada pela Central de IA deixa um template novo —, então achar
 * um template é o problema real da tela, não cadastrá-lo.
 *
 * @var array $resultado  itens, total, pagina, por_pagina, resumo
 * @var array $filtros    busca, tipo, formato, status, origem, ordenar
 */
$base   = defined('BASE_URL') ? BASE_URL : '';
$itens  = $resultado['itens'];
$resumo = $resultado['resumo'];

/** Filtros sem os vazios — mantém a URL limpa e a paginação coerente. */
$ativos = array_filter($filtros, fn ($v) => $v !== '' && $v !== null);

$rotuloStatus = ['ativo' => 'ativo', 'rascunho' => 'rascunho', 'arquivado' => 'arquivado'];
?>
<div class="em_wrapper" data-base="<?= htmlspecialchars($base) ?>">
    <div class="em_header">
        <h1>Templates</h1>
        <div class="em_actions">
            <a href="<?= $base ?>/admin/email-marketing/transacional" class="em_btn">Transacional</a>
            <a href="<?= $base ?>/admin/ia/email-layout" class="em_btn">Gerar com IA</a>
            <a href="<?= $base ?>/admin/email-marketing/templates/criar" class="em_btn">+ HTML manual</a>
            <a href="<?= $base ?>/admin/email-marketing/templates/criar-visual" class="em_btn em_btn_primary">+ Editor visual</a>
        </div>
    </div>

    <!-- Leitura rápida antes de filtrar -->
    <div class="em_kpi_grid">
        <div class="em_card">
            <span class="em_card_label">Total</span>
            <span class="em_card_value"><?= (int) $resumo['total'] ?></span>
        </div>
        <div class="em_card">
            <span class="em_card_label">Marketing</span>
            <span class="em_card_value"><?= (int) $resumo['marketing'] ?></span>
        </div>
        <div class="em_card">
            <span class="em_card_label">Transacional</span>
            <span class="em_card_value"><?= (int) $resumo['transacional'] ?></span>
        </div>
        <div class="em_card">
            <span class="em_card_label">Ativos</span>
            <span class="em_card_value"><?= (int) $resumo['ativos'] ?></span>
        </div>
        <div class="em_card">
            <span class="em_card_label">Feitos por IA</span>
            <span class="em_card_value"><?= (int) $resumo['por_ia'] ?></span>
        </div>
    </div>

    <form class="em_filtros" method="get">
        <input type="text" name="busca" placeholder="Buscar por nome ou assunto..."
               value="<?= htmlspecialchars($filtros['busca']) ?>">

        <select name="tipo">
            <option value="">Todos os tipos</option>
            <?php foreach (EmailTemplate::TIPOS as $t): ?>
                <option value="<?= $t ?>" <?= $filtros['tipo'] === $t ? 'selected' : '' ?>><?= $t ?></option>
            <?php endforeach; ?>
        </select>

        <select name="formato">
            <option value="">Todos os formatos</option>
            <?php foreach (EmailTemplate::FORMATOS as $f): ?>
                <option value="<?= $f ?>" <?= $filtros['formato'] === $f ? 'selected' : '' ?>><?= $f ?></option>
            <?php endforeach; ?>
        </select>

        <select name="status">
            <option value="">Todos os status</option>
            <?php foreach (EmailTemplate::STATUS as $s): ?>
                <option value="<?= $s ?>" <?= $filtros['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
            <?php endforeach; ?>
        </select>

        <select name="origem">
            <option value="">Qualquer origem</option>
            <option value="ia"     <?= $filtros['origem'] === 'ia'     ? 'selected' : '' ?>>Feitos por IA</option>
            <option value="humano" <?= $filtros['origem'] === 'humano' ? 'selected' : '' ?>>Feitos à mão</option>
        </select>

        <select name="ordenar">
            <?php foreach ([
                ''        => 'Mais recentes',
                'antigos' => 'Mais antigos',
                'nome'    => 'Nome (A–Z)',
                'tipo'    => 'Agrupar por tipo',
                'formato' => 'Agrupar por formato',
                'status'  => 'Agrupar por status',
            ] as $v => $rot): ?>
                <option value="<?= $v ?>" <?= $filtros['ordenar'] === $v ? 'selected' : '' ?>><?= $rot ?></option>
            <?php endforeach; ?>
        </select>

        <button class="em_btn" type="submit">Filtrar</button>
        <?php if ($ativos !== []): ?>
            <a class="em_link" href="<?= $base ?>/admin/email-marketing/templates">Limpar</a>
        <?php endif; ?>
    </form>

    <p class="em_meta">
        <?= number_format($resultado['total'], 0, ',', '.') ?>
        <?= $resultado['total'] === 1 ? 'template' : 'templates' ?>
        <?= $ativos !== [] ? 'no filtro' : 'no total' ?>
    </p>

    <table class="em_table">
        <thead><tr>
            <th>Nome</th><th>Assunto</th><th>Tipo</th><th>Formato</th><th>Origem</th>
            <th>Status</th><th>Versão</th><th>Render</th><th>Atualizado</th><th></th>
        </tr></thead>
        <tbody>
        <?php if (empty($itens)): ?>
            <tr><td colspan="10" class="em_empty">
                <?= $ativos !== [] ? 'Nenhum template com esses filtros.' : 'Nenhum template cadastrado.' ?>
            </td></tr>
        <?php else: foreach ($itens as $t):
            $porIa = !empty($t['ia_layout']) || !empty($t['ia_conteudo']);
            // Layout = esqueleto (Fase 1). Campanha = esqueleto já preenchido
            // com vitrine e copy (Fase 2). São coisas diferentes na prática.
            $rotuloIa = !empty($t['ia_conteudo']) ? 'IA · campanha' : 'IA · layout';
            $rs = $t['render_status'] ?? 'ok';
        ?>
            <tr>
                <td><?= htmlspecialchars($t['nome']) ?></td>
                <td><?= htmlspecialchars($t['assunto']) ?></td>
                <td><span class="em_badge em_tp_<?= htmlspecialchars($t['tipo']) ?>"><?= htmlspecialchars($t['tipo']) ?></span></td>
                <td><span class="em_badge"><?= htmlspecialchars($t['formato'] ?? 'manual') ?></span></td>
                <td>
                    <?php if ($porIa): ?>
                        <span class="em_badge em_ia" title="Gerado pela Central de Marketing IA"><?= $rotuloIa ?></span>
                    <?php else: ?>
                        <span class="em_meta_inline">à mão</span>
                    <?php endif; ?>
                </td>
                <td><span class="em_badge em_st_<?= htmlspecialchars($t['status']) ?>"><?= htmlspecialchars($rotuloStatus[$t['status']] ?? $t['status']) ?></span></td>
                <td>v<?= (int) $t['versao'] ?></td>
                <td>
                    <span class="em_badge em_st_<?= $rs === 'ok' ? 'ativo' : ($rs === 'warning' ? 'pendente' : 'falhou') ?>"
                          <?= !empty($t['render_log']) ? 'title="' . htmlspecialchars($t['render_log']) . '"' : '' ?>><?= htmlspecialchars($rs) ?></span>
                </td>
                <td><?= date('d/m/Y H:i', strtotime($t['atualizado_em'])) ?></td>
                <td class="em_actions_cell">
                    <a href="<?= $base ?>/admin/email-marketing/templates/<?= (int) $t['id'] ?>/editar" class="em_link">Editar</a>
                    <a href="<?= $base ?>/admin/email-marketing/templates/<?= (int) $t['id'] ?>/versoes" class="em_link">Histórico</a>
                    <button type="button" class="em_link" data-em-action="tpl-duplicar" data-id="<?= (int) $t['id'] ?>">Duplicar</button>
                    <button type="button" class="em_link em_warn" data-em-action="excluir-template" data-id="<?= (int) $t['id'] ?>">Excluir</button>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>

    <?php
    $totalPag = max(1, (int) ceil($resultado['total'] / $resultado['por_pagina']));
    $atual    = $resultado['pagina'];
    if ($totalPag > 1):
    ?>
    <div class="em_pag">
        <?php if ($atual > 1): ?>
            <a href="?<?= http_build_query(array_merge($ativos, ['pagina' => $atual - 1])) ?>">‹</a>
        <?php endif; ?>

        <?php
        // Janela em volta da página atual, com as pontas sempre visíveis: com
        // 40 páginas, imprimir todas empurra a tabela para fora da tela.
        $mostrar = [];
        for ($i = 1; $i <= $totalPag; $i++) {
            if ($i === 1 || $i === $totalPag || abs($i - $atual) <= 2) { $mostrar[] = $i; }
        }
        $anterior = 0;
        foreach ($mostrar as $i):
            if ($anterior && $i - $anterior > 1): ?>
                <span class="em_pag_gap">…</span>
            <?php endif; $anterior = $i; ?>
            <a href="?<?= http_build_query(array_merge($ativos, ['pagina' => $i])) ?>"
               class="<?= $i === $atual ? 'em_pag_atual' : '' ?>"><?= $i ?></a>
        <?php endforeach; ?>

        <?php if ($atual < $totalPag): ?>
            <a href="?<?= http_build_query(array_merge($ativos, ['pagina' => $atual + 1])) ?>">›</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
