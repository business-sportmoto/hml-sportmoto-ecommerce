<?php
/** @var array $itens */
/** @var array $campos_permitidos */
$base = defined('BASE_URL') ? BASE_URL : '';
?>
<div class="em_wrapper" data-base="<?= htmlspecialchars($base) ?>">
    <div class="em_header">
        <h1>Segmentos</h1>
        <button type="button" class="em_btn em_btn_primary" data-em-action="novo-segmento">Novo segmento</button>
    </div>

    <table class="em_table">
        <thead><tr><th>Nome</th><th>Descrição</th><th>Estimativa</th><th>Ativo</th><th></th></tr></thead>
        <tbody>
        <?php if (empty($itens)): ?>
            <tr><td colspan="5" class="em_empty">Nenhum segmento cadastrado.</td></tr>
        <?php else: foreach ($itens as $s): ?>
            <tr>
                <td><?= htmlspecialchars($s['nome']) ?></td>
                <td><?= htmlspecialchars($s['descricao'] ?: '—') ?></td>
                <td><?= number_format($s['total_estimado'],0,',','.') ?></td>
                <td><?= $s['ativo'] ? 'Sim' : 'Não' ?></td>
                <td>
                    <button type="button" class="em_link" data-em-action="editar-segmento"
                            data-json='<?= htmlspecialchars(json_encode($s), ENT_QUOTES) ?>'>Editar</button>
                    <button type="button" class="em_link em_warn" data-em-action="excluir-segmento" data-id="<?= (int)$s['id'] ?>">Excluir</button>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<div id="em_modal_segmento" class="em_modal" style="display:none;">
    <div class="em_modal_box em_modal_grande">
        <h3 id="em_modal_titulo_seg">Novo segmento</h3>
        <form id="em_form_segmento">
            <input type="hidden" name="id" value="0">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

            <label>Nome <input type="text" name="nome" required maxlength="120"></label>
            <label>Descrição <input type="text" name="descricao" maxlength="250"></label>
            <label>Combinar regras com:
                <select name="match">
                    <option value="AND">TODAS (E)</option>
                    <option value="OR">QUALQUER (OU)</option>
                </select>
            </label>

            <fieldset>
                <legend>Regras</legend>
                <div id="em_regras_box"></div>
                <button type="button" class="em_btn" data-em-action="add-regra">+ Adicionar regra</button>
            </fieldset>

            <div class="em_form_meta">
                <button type="button" class="em_btn" data-em-action="preview-seg">Recalcular estimativa</button>
                <span>Estimativa: <strong id="em_estimativa">—</strong></span>
            </div>

            <label class="em_inline"><input type="checkbox" name="ativo" value="1" checked> Ativo</label>

            <div class="em_form_actions">
                <button type="button" class="em_btn" data-em-close>Cancelar</button>
                <button type="submit" class="em_btn em_btn_primary">Salvar</button>
            </div>
        </form>
    </div>
</div>

<script type="application/json" id="em_campos_permitidos">
<?= json_encode($campos_permitidos) ?>
</script>
