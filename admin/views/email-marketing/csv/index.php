<?php
/** @var array $resultado */
/** @var array $filtros */
$base = defined('BASE_URL') ? BASE_URL : '';
$itens = $resultado['itens'];
?>
<div class="em_wrapper" data-base="<?= htmlspecialchars($base) ?>">
    <div class="em_header">
        <h1>Importações CSV</h1>
        <a href="<?= $base ?>/admin/email-marketing/csv/novo" class="em_btn em_btn_primary">+ Nova importação</a>
    </div>

    <form class="em_filtros" method="get">
        <select name="status">
            <option value="">Todos os status</option>
            <?php foreach (['fila','validando','processando','concluido','cancelada','erro'] as $s): ?>
                <option value="<?= $s ?>" <?= $filtros['status']===$s?'selected':'' ?>><?= $s ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="em_btn">Filtrar</button>
    </form>

    <p class="em_meta">Total: <?= number_format($resultado['total'],0,',','.') ?></p>

    <table class="em_table">
        <thead><tr>
            <th>#</th><th>Arquivo</th><th>Lista</th><th>Status</th>
            <th>Linhas</th><th>Inseridos</th><th>Atualizados</th>
            <th>Inválidos</th><th>Criado</th><th></th>
        </tr></thead>
        <tbody>
        <?php if (empty($itens)): ?>
            <tr><td colspan="10" class="em_empty">Nenhuma importação realizada ainda.</td></tr>
        <?php else: foreach ($itens as $i): ?>
            <tr>
                <td>#<?= (int)$i['id'] ?></td>
                <td><?= htmlspecialchars($i['arquivo'] ?: '—') ?></td>
                <td><?= htmlspecialchars($i['lista_nome'] ?: '—') ?></td>
                <td><span class="em_badge em_st_<?= htmlspecialchars($i['status']) ?>"><?= htmlspecialchars($i['status']) ?></span></td>
                <td><?= number_format($i['total_linhas'],0,',','.') ?></td>
                <td><?= number_format($i['inseridos'],0,',','.') ?></td>
                <td><?= number_format($i['atualizados'],0,',','.') ?></td>
                <td><?= number_format($i['invalidos'],0,',','.') ?></td>
                <td><?= date('d/m/Y H:i', strtotime($i['criado_em'])) ?></td>
                <td class="em_actions_cell">
                    <a href="<?= $base ?>/admin/email-marketing/csv/<?= (int)$i['id'] ?>" class="em_link">Detalhes</a>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>

    <?php
    $totalPag = max(1, (int)ceil($resultado['total'] / $resultado['por_pagina']));
    if ($totalPag > 1):
    ?>
    <div class="em_pag">
        <?php for ($i=1; $i<=$totalPag; $i++): if ($i > 10 && $i !== $totalPag && abs($i-$resultado['pagina'])>3) continue; ?>
            <a href="?<?= http_build_query(array_merge($filtros, ['pagina' => $i])) ?>" class="<?= $i===$resultado['pagina']?'em_pag_atual':'' ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>
