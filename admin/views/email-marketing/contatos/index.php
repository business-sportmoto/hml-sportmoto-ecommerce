<?php
/** @var array $resultado */
/** @var array $filtros */
$base = defined('BASE_URL') ? BASE_URL : '';
$itens = $resultado['itens'];
?>
<div class="em_wrapper" data-base="<?= htmlspecialchars($base) ?>">
    <div class="em_header">
        <h1>Contatos</h1>
        <button type="button" class="em_btn em_btn_primary" data-em-action="sincronizar-contatos">
            Sincronizar com clientes / newsletter
        </button>
    </div>

    <form class="em_filtros" method="get">
        <input type="text" name="busca" placeholder="Buscar email ou nome..." value="<?= htmlspecialchars($filtros['busca']) ?>">
        <select name="status">
            <option value="">Todos os status</option>
            <?php foreach (['ativo','descadastrado','bounce','complaint','bloqueado','pendente'] as $s): ?>
                <option value="<?= $s ?>" <?= $filtros['status']===$s?'selected':'' ?>><?= $s ?></option>
            <?php endforeach; ?>
        </select>
        <select name="origem">
            <option value="">Todas as origens</option>
            <?php foreach (['cliente','newsletter','checkout','importacao','admin','api','legado'] as $o): ?>
                <option value="<?= $o ?>" <?= $filtros['origem']===$o?'selected':'' ?>><?= $o ?></option>
            <?php endforeach; ?>
        </select>
        <button class="em_btn" type="submit">Filtrar</button>
    </form>

    <p class="em_meta">Total: <?= number_format($resultado['total'],0,',','.') ?> contatos</p>

    <table class="em_table">
        <thead><tr>
            <th>Email</th><th>Nome</th><th>Origem</th><th>Status</th>
            <th>Verificado</th><th>Cadastrado</th><th></th>
        </tr></thead>
        <tbody>
        <?php if (empty($itens)): ?>
            <tr><td colspan="7" class="em_empty">Nenhum contato encontrado.</td></tr>
        <?php else: foreach ($itens as $c): ?>
            <tr>
                <td><?= htmlspecialchars($c['email']) ?></td>
                <td><?= htmlspecialchars($c['nome'] ?: '—') ?></td>
                <td><span class="em_badge em_or_<?= htmlspecialchars($c['origem']) ?>"><?= htmlspecialchars($c['origem']) ?></span></td>
                <td><span class="em_badge em_st_<?= htmlspecialchars($c['status']) ?>"><?= htmlspecialchars($c['status']) ?></span></td>
                <td><?= $c['email_verificado'] ? 'Sim' : '—' ?></td>
                <td><?= date('d/m/Y', strtotime($c['criado_em'])) ?></td>
                <td>
                    <?php if ($c['status'] === 'bloqueado'): ?>
                        <button type="button" class="em_link" data-em-action="desbloquear-contato" data-id="<?= (int)$c['id'] ?>">Desbloquear</button>
                    <?php else: ?>
                        <button type="button" class="em_link" data-em-action="bloquear-contato" data-id="<?= (int)$c['id'] ?>">Bloquear</button>
                    <?php endif; ?>
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
