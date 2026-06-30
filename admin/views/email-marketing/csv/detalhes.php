<?php
/** @var array $imp */
/** @var array $erros */
$base = defined('BASE_URL') ? BASE_URL : '';
$emProcessamento = in_array($imp['status'], ['fila','validando','processando'], true);
?>
<div class="em_wrapper" data-base="<?= htmlspecialchars($base) ?>"
     data-importacao="<?= (int)$imp['id'] ?>"
     data-em-processamento="<?= $emProcessamento ? '1' : '0' ?>">

    <div class="em_header">
        <h1>Importação #<?= (int)$imp['id'] ?></h1>
        <div class="em_actions">
            <a href="<?= $base ?>/admin/email-marketing/csv" class="em_btn">Voltar</a>
            <?php if ($emProcessamento): ?>
                <button type="button" class="em_btn em_warn" data-em-action="csv-cancelar" data-id="<?= (int)$imp['id'] ?>">Cancelar</button>
            <?php endif; ?>
            <?php if ($imp['invalidos'] > 0): ?>
                <a href="<?= $base ?>/admin/email-marketing/csv/<?= (int)$imp['id'] ?>/erros.csv" class="em_btn em_btn_primary">Baixar inválidos (CSV)</a>
            <?php endif; ?>
        </div>
    </div>

    <p>
        <strong>Arquivo:</strong> <?= htmlspecialchars($imp['arquivo']) ?> &nbsp;|&nbsp;
        <strong>Status:</strong> <span class="em_badge em_st_<?= htmlspecialchars($imp['status']) ?>"><?= htmlspecialchars($imp['status']) ?></span> &nbsp;|&nbsp;
        <strong>Criada:</strong> <?= date('d/m/Y H:i', strtotime($imp['criado_em'])) ?>
        <?php if (!empty($imp['concluido_em'])): ?>
            &nbsp;|&nbsp; <strong>Concluída:</strong> <?= date('d/m/Y H:i', strtotime($imp['concluido_em'])) ?>
        <?php endif; ?>
    </p>

    <?php if ($emProcessamento): ?>
        <div class="em_progress">
            <div class="em_progress_bar" id="em_progress_bar" style="width:<?= (int)$imp['progresso_pct'] ?>%;"></div>
        </div>
        <p class="em_meta" id="em_progress_text">
            <?= (int)$imp['linhas_processadas'] ?> / <?= (int)$imp['total_linhas'] ?> linhas
            (<?= (int)$imp['progresso_pct'] ?>%)
        </p>
    <?php endif; ?>

    <div class="em_kpi_grid">
        <div class="em_card">
            <span class="em_card_label">Total no arquivo</span>
            <span class="em_card_value"><?= number_format($imp['total_linhas'],0,',','.') ?></span>
        </div>
        <div class="em_card">
            <span class="em_card_label">Inseridos</span>
            <span class="em_card_value" id="em_cnt_ins"><?= number_format($imp['inseridos'],0,',','.') ?></span>
        </div>
        <div class="em_card">
            <span class="em_card_label">Atualizados</span>
            <span class="em_card_value" id="em_cnt_upd"><?= number_format($imp['atualizados'],0,',','.') ?></span>
        </div>
        <div class="em_card">
            <span class="em_card_label">Duplicados</span>
            <span class="em_card_value" id="em_cnt_dup"><?= number_format($imp['duplicados'],0,',','.') ?></span>
        </div>
        <div class="em_card">
            <span class="em_card_label em_warn">Inválidos</span>
            <span class="em_card_value em_warn" id="em_cnt_inv"><?= number_format($imp['invalidos'],0,',','.') ?></span>
        </div>
        <div class="em_card">
            <span class="em_card_label">Suprimidos</span>
            <span class="em_card_value" id="em_cnt_sup"><?= number_format($imp['suprimidos'],0,',','.') ?></span>
        </div>
    </div>

    <?php if ($erros['total'] > 0): ?>
    <div class="em_section">
        <h2>Erros de validação (<?= number_format($erros['total'],0,',','.') ?>)</h2>
        <table class="em_table">
            <thead><tr><th>Linha</th><th>Email</th><th>Motivo</th><th>Detalhe</th></tr></thead>
            <tbody>
                <?php foreach ($erros['itens'] as $e): ?>
                    <tr>
                        <td><?= (int)$e['linha'] ?></td>
                        <td><?= htmlspecialchars($e['email'] ?: '—') ?></td>
                        <td><span class="em_badge"><?= htmlspecialchars($e['motivo']) ?></span></td>
                        <td><?= htmlspecialchars($e['detalhe'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php
        $totalPag = max(1, (int)ceil($erros['total'] / $erros['por_pagina']));
        if ($totalPag > 1):
        ?>
        <div class="em_pag">
            <?php for ($i=1; $i<=$totalPag; $i++): if ($i > 10 && $i !== $totalPag && abs($i-$erros['pagina'])>3) continue; ?>
                <a href="?pagina_erros=<?= $i ?>" class="<?= $i===$erros['pagina']?'em_pag_atual':'' ?>"><?= $i ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
