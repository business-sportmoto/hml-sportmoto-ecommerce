<?php
/** @var array $resultado */
/** @var array $filtros */
$base = defined('BASE_URL') ? BASE_URL : '';
$itens = $resultado['itens'];
?>
<div class="em_wrapper" data-base="<?= htmlspecialchars($base) ?>">
    <div class="em_header">
        <h1>Supressões</h1>
        <button type="button" class="em_btn em_btn_primary" data-em-action="nova-supressao">Adicionar</button>
    </div>

    <form class="em_filtros" method="get">
        <input type="text" name="busca" placeholder="Buscar email..." value="<?= htmlspecialchars($filtros['busca']) ?>">
        <select name="motivo">
            <option value="">Todos os motivos</option>
            <?php foreach (['hard_bounce','soft_bounce_repetido','complaint','descadastro','manual','dominio_invalido','global'] as $m): ?>
                <option value="<?= $m ?>" <?= $filtros['motivo']===$m?'selected':'' ?>><?= $m ?></option>
            <?php endforeach; ?>
        </select>
        <button class="em_btn" type="submit">Filtrar</button>
    </form>

    <p class="em_meta">Total: <?= number_format($resultado['total'],0,',','.') ?></p>

    <table class="em_table">
        <thead><tr><th>Email</th><th>Motivo</th><th>Origem</th><th>Criado</th><th>Expira</th><th></th></tr></thead>
        <tbody>
        <?php if (empty($itens)): ?>
            <tr><td colspan="6" class="em_empty">Nenhuma supressão.</td></tr>
        <?php else: foreach ($itens as $s): ?>
            <tr>
                <td><?= htmlspecialchars($s['email']) ?></td>
                <td><span class="em_badge"><?= htmlspecialchars($s['motivo']) ?></span></td>
                <td><?= htmlspecialchars($s['origem']) ?></td>
                <td><?= date('d/m/Y H:i', strtotime($s['criado_em'])) ?></td>
                <td><?= $s['expira_em'] ? date('d/m/Y', strtotime($s['expira_em'])) : '—' ?></td>
                <td>
                    <button type="button" class="em_link em_warn" data-em-action="remover-supressao" data-email="<?= htmlspecialchars($s['email']) ?>">Remover</button>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<div id="em_modal_supressao" class="em_modal" style="display:none;">
    <div class="em_modal_box">
        <h3>Adicionar supressão</h3>
        <form id="em_form_supressao">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <label>Email <input type="email" name="email" required></label>
            <label>Motivo
                <select name="motivo">
                    <option value="manual">manual</option>
                    <option value="hard_bounce">hard_bounce</option>
                    <option value="complaint">complaint</option>
                    <option value="descadastro">descadastro</option>
                    <option value="dominio_invalido">dominio_invalido</option>
                    <option value="global">global</option>
                </select>
            </label>
            <label>Observação <textarea name="observacao" rows="2"></textarea></label>
            <div class="em_form_actions">
                <button type="button" class="em_btn" data-em-close>Cancelar</button>
                <button type="submit" class="em_btn em_btn_primary">Salvar</button>
            </div>
        </form>
    </div>
</div>
