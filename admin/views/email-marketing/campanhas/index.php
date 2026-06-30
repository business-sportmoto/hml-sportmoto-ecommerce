<?php
/** @var array $resultado */
/** @var array $filtros */
$base = defined('BASE_URL') ? BASE_URL : '';
$itens = $resultado['itens'];
?>
<div class="em_wrapper" data-base="<?= htmlspecialchars($base) ?>">
    <div class="em_header">
        <h1>Campanhas</h1>
        <a href="<?= $base ?>/admin/email-marketing/campanhas/criar" class="em_btn em_btn_primary">Nova campanha</a>
    </div>

    <form class="em_filtros" method="get">
        <input type="text" name="busca" placeholder="Buscar por nome..." value="<?= htmlspecialchars($filtros['busca']) ?>">
        <select name="status">
            <option value="">Todos os status</option>
            <?php foreach (['rascunho','agendada','enfileirando','enviando','pausada','concluida','cancelada','erro'] as $s): ?>
                <option value="<?= $s ?>" <?= $filtros['status']===$s?'selected':'' ?>><?= $s ?></option>
            <?php endforeach; ?>
        </select>
        <button class="em_btn" type="submit">Filtrar</button>
    </form>

    <table class="em_table">
        <thead><tr>
            <th>Nome</th><th>Status</th><th>Provedor</th><th>Template</th>
            <th>Dest.</th><th>Enviados</th><th>Aberturas</th><th>Cliques</th>
            <th>Agendada</th><th></th>
        </tr></thead>
        <tbody>
        <?php if (empty($itens)): ?>
            <tr><td colspan="10" class="em_empty">Nenhuma campanha encontrada.</td></tr>
        <?php else: foreach ($itens as $c): ?>
            <tr>
                <td><?= htmlspecialchars($c['nome']) ?></td>
                <td><span class="em_badge em_st_<?= htmlspecialchars($c['status']) ?>"><?= htmlspecialchars($c['status']) ?></span></td>
                <td><?= htmlspecialchars($c['provedor_nome'] ?: '—') ?></td>
                <td><?= htmlspecialchars($c['template_nome'] ?: '—') ?></td>
                <td><?= (int)$c['total_destinatarios'] ?></td>
                <td><?= (int)$c['total_enviados'] ?></td>
                <td><?= (int)$c['total_aberturas'] ?></td>
                <td><?= (int)$c['total_cliques'] ?></td>
                <td><?= $c['agendada_para'] ? date('d/m/Y H:i', strtotime($c['agendada_para'])) : '—' ?></td>
                <td class="em_actions_cell">
                    <a href="<?= $base ?>/admin/email-marketing/campanhas/<?= (int)$c['id'] ?>/relatorio" class="em_link">Relatório</a>
                    <a href="<?= $base ?>/admin/email-marketing/campanhas/<?= (int)$c['id'] ?>/editar" class="em_link">Editar</a>
                    <?php if ($c['status'] === 'rascunho'): ?>
                        <button class="em_link em_warn" data-em-action="enfileirar-campanha" data-id="<?= (int)$c['id'] ?>">Enfileirar</button>
                    <?php endif; ?>
                    <?php if (in_array($c['status'], ['agendada','enviando','enfileirando'], true)): ?>
                        <button class="em_link" data-em-action="pausar-campanha" data-id="<?= (int)$c['id'] ?>">Pausar</button>
                    <?php endif; ?>
                    <?php if ($c['status'] === 'pausada'): ?>
                        <button class="em_link" data-em-action="continuar-campanha" data-id="<?= (int)$c['id'] ?>">Continuar</button>
                    <?php endif; ?>
                    <?php if (!in_array($c['status'], ['concluida','cancelada'], true)): ?>
                        <button class="em_link em_warn" data-em-action="cancelar-campanha" data-id="<?= (int)$c['id'] ?>">Cancelar</button>
                    <?php endif; ?>
                    <button class="em_link" data-em-action="duplicar-campanha" data-id="<?= (int)$c['id'] ?>">Duplicar</button>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
