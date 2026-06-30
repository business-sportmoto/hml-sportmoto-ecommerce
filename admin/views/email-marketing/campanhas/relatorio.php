<?php
/** @var array $item */
/** @var array $status */
/** @var array $links */
$base = defined('BASE_URL') ? BASE_URL : '';

$dest = (int)$item['total_destinatarios'];
$pct = function($a, $b) { return $b > 0 ? round(($a/$b)*100, 2) : 0; };
?>
<div class="em_wrapper" data-base="<?= htmlspecialchars($base) ?>" data-campanha="<?= (int)$item['id'] ?>">
    <div class="em_header">
        <h1>Relatório — <?= htmlspecialchars($item['nome']) ?></h1>
        <a href="<?= $base ?>/admin/email-marketing/campanhas" class="em_btn">Voltar</a>
    </div>

    <p>
        <strong>Status:</strong>
        <span class="em_badge em_st_<?= htmlspecialchars($item['status']) ?>"><?= htmlspecialchars($item['status']) ?></span>
        &nbsp;|&nbsp;
        <strong>Criada:</strong> <?= date('d/m/Y H:i', strtotime($item['criado_em'])) ?>
        <?php if ($item['agendada_para'] && $item['agendada_para'] !== '0000-00-00 00:00:00'): ?>
            &nbsp;|&nbsp; <strong>Agendada:</strong> <?= date('d/m/Y H:i', strtotime($item['agendada_para'])) ?>
        <?php endif; ?>
    </p>

    <div class="em_kpi_grid">
        <div class="em_card">
            <span class="em_card_label">Destinatários</span>
            <span class="em_card_value"><?= number_format($dest, 0, ',', '.') ?></span>
        </div>
        <div class="em_card">
            <span class="em_card_label">Enviados</span>
            <span class="em_card_value"><?= (int)$item['total_enviados'] ?></span>
            <small><?= $pct($item['total_enviados'], $dest) ?>%</small>
        </div>
        <div class="em_card">
            <span class="em_card_label">Entregues</span>
            <span class="em_card_value"><?= (int)$item['total_entregues'] ?></span>
            <small><?= $pct($item['total_entregues'], $item['total_enviados']) ?>%</small>
        </div>
        <div class="em_card">
            <span class="em_card_label">Aberturas</span>
            <span class="em_card_value"><?= (int)$item['total_aberturas'] ?></span>
            <small><?= $pct($item['total_aberturas'], $item['total_entregues']) ?>%</small>
        </div>
        <div class="em_card">
            <span class="em_card_label">Cliques</span>
            <span class="em_card_value"><?= (int)$item['total_cliques'] ?></span>
            <small><?= $pct($item['total_cliques'], $item['total_entregues']) ?>%</small>
        </div>
        <div class="em_card">
            <span class="em_card_label em_warn">Bounces</span>
            <span class="em_card_value em_warn"><?= (int)$item['total_bounces'] ?></span>
            <small><?= $pct($item['total_bounces'], $item['total_enviados']) ?>%</small>
        </div>
        <div class="em_card">
            <span class="em_card_label em_warn">Complaints</span>
            <span class="em_card_value em_warn"><?= (int)$item['total_complaints'] ?></span>
        </div>
        <div class="em_card">
            <span class="em_card_label">Descadastros</span>
            <span class="em_card_value"><?= (int)$item['total_descadastros'] ?></span>
        </div>
    </div>

    <div class="em_section">
        <h2>Distribuição da fila</h2>
        <table class="em_table">
            <thead><tr><th>Status</th><th>Quantidade</th></tr></thead>
            <tbody>
                <?php
                $ordem = ['fila','processando','enviado','entregue','aberto','clicado','bounce','complaint','descadastrado','falhou','ignorado'];
                foreach ($ordem as $s):
                    if (!isset($status[$s])) continue; ?>
                    <tr>
                        <td><span class="em_badge em_st_<?= $s ?>"><?= $s ?></span></td>
                        <td><?= number_format($status[$s], 0, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="em_section">
        <h2>Links mais clicados</h2>
        <table class="em_table">
            <thead><tr><th>URL</th><th>Cliques</th></tr></thead>
            <tbody>
                <?php if (empty($links)): ?>
                    <tr><td colspan="2" class="em_empty">Nenhum clique registrado ainda.</td></tr>
                <?php else: foreach ($links as $l): ?>
                    <tr>
                        <td class="em_url"><a href="<?= htmlspecialchars($l['url_destino']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($l['url_destino']) ?></a></td>
                        <td><?= number_format($l['total_cliques'], 0, ',', '.') ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
