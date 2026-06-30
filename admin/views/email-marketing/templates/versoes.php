<?php
/** @var array $item */
/** @var array $versoes */
$base = defined('BASE_URL') ? BASE_URL : '';
?>
<div class="em_wrapper" data-base="<?= htmlspecialchars($base) ?>">
    <div class="em_header">
        <div>
            <h1>Histórico</h1>
            <p class="em_meta"><?= htmlspecialchars($item['nome']) ?> · versão atual: v<?= (int)$item['versao'] ?></p>
        </div>
        <a href="<?= $base ?>/admin/email-marketing/templates/<?= (int)$item['id'] ?>/editar" class="em_btn">Voltar</a>
    </div>

    <table class="em_table">
        <thead><tr>
            <th>Versão</th><th>Nome</th><th>Assunto</th><th>Formato</th>
            <th>Motivo</th><th>Autor</th><th>Criada</th><th></th>
        </tr></thead>
        <tbody>
        <?php if (empty($versoes)): ?>
            <tr><td colspan="8" class="em_empty">Nenhuma versão anterior — esta é a v1.</td></tr>
        <?php else: foreach ($versoes as $v): ?>
            <tr>
                <td><strong>v<?= (int)$v['versao'] ?></strong></td>
                <td><?= htmlspecialchars($v['nome']) ?></td>
                <td><?= htmlspecialchars($v['assunto']) ?></td>
                <td><span class="em_badge"><?= htmlspecialchars($v['formato']) ?></span></td>
                <td><?= htmlspecialchars($v['motivo'] ?: '—') ?></td>
                <td><?= htmlspecialchars($v['autor_nome'] ?: '—') ?></td>
                <td><?= date('d/m/Y H:i', strtotime($v['criado_em'])) ?></td>
                <td class="em_actions_cell">
                    <a href="<?= $base ?>/admin/email-marketing/templates/<?= (int)$item['id'] ?>/versoes/<?= (int)$v['id'] ?>" class="em_link">Ver</a>
                    <button type="button" class="em_link em_warn"
                            data-em-action="tpl-restaurar" data-versao-id="<?= (int)$v['id'] ?>"
                            data-versao="<?= (int)$v['versao'] ?>">Restaurar</button>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
