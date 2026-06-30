<?php
/**
 * admin/views/email-marketing/listas/index.php
 *
 * Versão ATUALIZADA: cada linha agora tem um link "Ver contatos" levando
 * para a tela de detalhes. Substitua o arquivo existente.
 */
/** @var array $itens */
$base = defined('BASE_URL') ? BASE_URL : '';
?>
<div class="em_wrapper" data-base="<?= htmlspecialchars($base) ?>">
    <div class="em_header">
        <h1>Listas</h1>
        <button type="button" class="em_btn em_btn_primary" data-em-action="nova-lista">Nova lista</button>
    </div>

    <table class="em_table">
        <thead><tr>
            <th>Nome</th><th>Descrição</th><th>Contatos</th><th>Ativo</th><th></th>
        </tr></thead>
        <tbody>
        <?php if (empty($itens)): ?>
            <tr><td colspan="5" class="em_empty">Nenhuma lista cadastrada.</td></tr>
        <?php else: foreach ($itens as $l): ?>
            <tr>
                <td>
                    <a href="<?= $base ?>/admin/email-marketing/listas/<?= (int)$l['id'] ?>" class="em_link" style="margin:0; padding:0;">
                        <?= htmlspecialchars($l['nome']) ?>
                    </a>
                </td>
                <td><?= htmlspecialchars($l['descricao'] ?: '—') ?></td>
                <td><?= number_format($l['total_contatos'], 0, ',', '.') ?></td>
                <td><?= $l['ativo'] ? 'Sim' : 'Não' ?></td>
                <td class="em_actions_cell">
                    <a href="<?= $base ?>/admin/email-marketing/listas/<?= (int)$l['id'] ?>" class="em_link">Ver contatos</a>
                    <button type="button" class="em_link" data-em-action="editar-lista"
                            data-json='<?= htmlspecialchars(json_encode($l), ENT_QUOTES) ?>'>Editar</button>
                    <button type="button" class="em_link em_warn" data-em-action="excluir-lista" data-id="<?= (int)$l['id'] ?>">Excluir</button>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<div id="em_modal_lista" class="em_modal" style="display:none;">
    <div class="em_modal_box">
        <h3 id="em_modal_titulo_lista">Nova lista</h3>
        <form id="em_form_lista">
            <input type="hidden" name="id" value="0">
            <?= SecurityHelper::csrfField() ?>
            <label>Nome <input type="text" name="nome" required maxlength="120"></label>
            <label>Descrição <textarea name="descricao" rows="3"></textarea></label>
            <label class="em_inline"><input type="checkbox" name="ativo" value="1" checked> Ativa</label>
            <div class="em_form_actions">
                <button type="button" class="em_btn" data-em-close>Cancelar</button>
                <button type="submit" class="em_btn em_btn_primary">Salvar</button>
            </div>
        </form>
    </div>
</div>
