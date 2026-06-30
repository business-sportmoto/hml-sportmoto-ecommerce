<?php
/** @var array $campanha */
/** @var array $variacoes */
/** @var array $status_ciclo */
/** @var array|null $taxas */
$base = defined('BASE_URL') ? BASE_URL : '';

function fmt($n) { return number_format((int)$n, 0, ',', '.'); }
function pct($v, $sobre) {
    if ($sobre <= 0) return '0%';
    return number_format(($v/$sobre)*100, 2, ',', '.') . '%';
}
?>
<div class="em_wrapper em_ab_relatorio" data-base="<?= htmlspecialchars($base) ?>" data-campanha="<?= (int)$campanha['id'] ?>">
    <div class="em_header">
        <div>
            <h1>Relatório A/B</h1>
            <p class="em_meta">
                <?= htmlspecialchars($campanha['nome']) ?>
                <span class="em_badge em_st_<?= htmlspecialchars($campanha['status']) ?>"><?= htmlspecialchars($campanha['status']) ?></span>
                <?php if (!empty($campanha['ab_fase'])): ?>
                    <span class="em_badge">A/B: <?= htmlspecialchars($campanha['ab_fase']) ?></span>
                <?php endif; ?>
                <?php if (!empty($campanha['ab_vencedor'])): ?>
                    <span class="em_badge em_st_ativo">vencedor: <?= strtoupper($campanha['ab_vencedor']) ?></span>
                <?php endif; ?>
            </p>
        </div>
        <div class="em_actions">
            <a href="<?= $base ?>/admin/email-marketing/campanhas/<?= (int)$campanha['id'] ?>/ab" class="em_btn">Variações</a>
            <a href="<?= $base ?>/admin/email-marketing/campanhas/<?= (int)$campanha['id'] ?>/editar" class="em_btn">Campanha</a>
        </div>
    </div>

    <input type="hidden" id="em_csrf" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

    <!-- Status do ciclo -->
    <div class="em_form" style="margin-bottom: 16px;">
        <h2 style="margin-top:0;">Status do teste</h2>
        <?php
        $sc = $status_ciclo;
        if ($sc['decidir']):
        ?>
            <p style="color: var(--em-green-text);"><strong>✓ Pronto para decisão.</strong></p>
        <?php elseif (!empty($campanha['ab_vencedor'])): ?>
            <p style="color: var(--em-green-text);">
                <strong>✓ Vencedor decidido:</strong> variação <strong><?= strtoupper($campanha['ab_vencedor']) ?></strong>
                em <?= date('d/m/Y H:i', strtotime($campanha['ab_decidida_em'])) ?>
                (<?= htmlspecialchars($campanha['ab_decidida_por'] ?? '—') ?>)
            </p>
        <?php else: ?>
            <p>
                Motivo: <code><?= htmlspecialchars($sc['motivo']) ?></code>
                <?php if (!empty($sc['info'])): ?>
                    — <?= htmlspecialchars(json_encode($sc['info'], JSON_UNESCAPED_UNICODE)) ?>
                <?php endif; ?>
            </p>
        <?php endif; ?>

        <?php if (!empty($campanha['ab_amostra_iniciada_em'])): ?>
            <p class="em_meta">Amostra iniciada em <?= date('d/m/Y H:i', strtotime($campanha['ab_amostra_iniciada_em'])) ?></p>
        <?php endif; ?>
    </div>

    <!-- Comparação lado a lado -->
    <div class="em_ab_grid">
        <?php foreach (['a','b'] as $letra): $v = $variacoes[$letra] ?? null; if (!$v) continue;
            $isWinner = ($campanha['ab_vencedor'] ?? '') === $letra;
        ?>
        <div class="em_form em_ab_card <?= $isWinner ? 'em_ab_winner' : '' ?>">
            <h2>
                Variação <?= strtoupper($letra) ?>
                <?php if ($isWinner): ?> <span class="em_badge em_st_ativo">vencedor</span><?php endif; ?>
            </h2>
            <p class="em_meta"><?= htmlspecialchars($v['assunto'] ?: '—') ?></p>

            <div class="em_kpi_grid_compact">
                <div class="em_card">
                    <span class="em_card_label">Destinatários</span>
                    <span class="em_card_value"><?= fmt($v['total_destinatarios']) ?></span>
                </div>
                <div class="em_card">
                    <span class="em_card_label">Enviados</span>
                    <span class="em_card_value"><?= fmt($v['total_enviados']) ?></span>
                </div>
                <div class="em_card">
                    <span class="em_card_label">Entregues</span>
                    <span class="em_card_value"><?= fmt($v['total_entregues']) ?></span>
                </div>
                <div class="em_card em_card_hl">
                    <span class="em_card_label">Aberturas</span>
                    <span class="em_card_value"><?= fmt($v['total_aberturas']) ?></span>
                    <span class="em_card_sub"><?= pct($v['total_aberturas'], max(1, $v['total_entregues'])) ?></span>
                </div>
                <div class="em_card em_card_hl">
                    <span class="em_card_label">Cliques</span>
                    <span class="em_card_value"><?= fmt($v['total_cliques']) ?></span>
                    <span class="em_card_sub"><?= pct($v['total_cliques'], max(1, $v['total_entregues'])) ?></span>
                </div>
                <div class="em_card">
                    <span class="em_card_label em_warn">Bounces</span>
                    <span class="em_card_value"><?= fmt($v['total_bounces']) ?></span>
                </div>
                <div class="em_card">
                    <span class="em_card_label em_warn">Complaints</span>
                    <span class="em_card_value"><?= fmt($v['total_complaints']) ?></span>
                </div>
                <div class="em_card">
                    <span class="em_card_label em_warn">Descadastros</span>
                    <span class="em_card_value"><?= fmt($v['total_descadastros']) ?></span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Ação manual -->
    <?php
    $podeEscolher = empty($campanha['ab_vencedor'])
                 && in_array($campanha['ab_fase'] ?? '', ['amostra', 'aguardando_vencedor'], true);
    if ($podeEscolher):
    ?>
    <div class="em_form" style="margin-top:16px;">
        <h2 style="margin-top:0;">Escolha manual de vencedor</h2>
        <?php if ($taxas): ?>
            <p class="em_meta">
                Métrica: <strong><?= htmlspecialchars($taxas['taxas']['metrica_usada'] ?? '—') ?></strong> ·
                Score A: <strong><?= number_format($taxas['taxas']['scores']['a'] ?? 0, 2, ',', '.') ?></strong> ·
                Score B: <strong><?= number_format($taxas['taxas']['scores']['b'] ?? 0, 2, ',', '.') ?></strong>
                <?php if (!empty($taxas['empate'])): ?>
                    · <span style="color:var(--em-warn-text);">empate técnico</span>
                <?php elseif ($taxas['vencedor']): ?>
                    · sugestão: <strong><?= strtoupper($taxas['vencedor']) ?></strong>
                <?php endif; ?>
            </p>
        <?php endif; ?>

        <div class="em_form_actions">
            <button type="button" class="em_btn em_btn_primary" data-em-action="ab-escolher" data-vencedor="a">Aplicar variação A</button>
            <button type="button" class="em_btn em_btn_primary" data-em-action="ab-escolher" data-vencedor="b">Aplicar variação B</button>
        </div>
    </div>
    <?php endif; ?>
</div>
