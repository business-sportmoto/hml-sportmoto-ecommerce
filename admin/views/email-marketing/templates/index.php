<?php
/**
 * admin/views/email-marketing/templates/index.php (v2)
 * SUBSTITUI o existente — adiciona botão "Novo template visual"
 * e coluna de formato.
 */
/** @var array $itens */
$base = defined('BASE_URL') ? BASE_URL : '';
?>
<div class="em_wrapper" data-base="<?= htmlspecialchars($base) ?>">
    <div class="em_header">
        <h1>Templates</h1>
        <div class="em_actions">
            <a href="<?= $base ?>/admin/email-marketing/transacional" class="em_btn">Transasional</a>
            <a href="<?= $base ?>/admin/email-marketing/templates/criar" class="em_btn">+ HTML manual</a>
            <a href="<?= $base ?>/admin/email-marketing/templates/criar-visual" class="em_btn em_btn_primary">+ Editor visual</a>
        </div>
    </div>

    <table class="em_table">
        <thead><tr>
            <th>Nome</th><th>Assunto</th><th>Tipo</th><th>Formato</th>
            <th>Status</th><th>Versão</th><th>Render</th><th>Atualizado</th><th></th>
        </tr></thead>
        <tbody>
        <?php if (empty($itens)): ?>
            <tr><td colspan="9" class="em_empty">Nenhum template cadastrado.</td></tr>
        <?php else: foreach ($itens as $t): ?>
            <tr>
                <td><?= htmlspecialchars($t['nome']) ?></td>
                <td><?= htmlspecialchars($t['assunto']) ?></td>
                <td><?= htmlspecialchars($t['tipo']) ?></td>
                <td><span class="em_badge"><?= htmlspecialchars($t['formato'] ?? 'manual') ?></span></td>
                <td><span class="em_badge em_st_<?= htmlspecialchars($t['status']) ?>"><?= htmlspecialchars($t['status']) ?></span></td>
                <td>v<?= (int)$t['versao'] ?></td>
                <td>
                    <?php $rs = $t['render_status'] ?? 'ok'; ?>
                    <span class="em_badge em_st_<?= $rs==='ok' ? 'ativo' : ($rs==='warning'?'pendente':'falhou') ?>"><?= htmlspecialchars($rs) ?></span>
                </td>
                <td><?= date('d/m/Y H:i', strtotime($t['atualizado_em'])) ?></td>
                <td class="em_actions_cell">
                    <a href="<?= $base ?>/admin/email-marketing/templates/<?= (int)$t['id'] ?>/editar" class="em_link">Editar</a>
                    <a href="<?= $base ?>/admin/email-marketing/templates/<?= (int)$t['id'] ?>/versoes" class="em_link">Histórico</a>
                    <button type="button" class="em_link" data-em-action="tpl-duplicar" data-id="<?= (int)$t['id'] ?>">Duplicar</button>
                    <button type="button" class="em_link em_warn" data-em-action="excluir-template" data-id="<?= (int)$t['id'] ?>">Excluir</button>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
