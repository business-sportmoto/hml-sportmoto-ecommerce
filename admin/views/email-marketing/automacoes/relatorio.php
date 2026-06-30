<?php
/** @var array $fluxo */
/** @var array $grafico */
/** @var array $historico */
$base = defined('BASE_URL') ? BASE_URL : '';
$totalEnviados  = array_sum(array_column($grafico, 'enviados'));
$totalSuprimidos= array_sum(array_column($grafico, 'suprimidos'));
$totalErros     = array_sum(array_column($grafico, 'erros'));
?>
<div class="em_wrapper" data-base="<?= htmlspecialchars($base) ?>">
    <div class="em_header">
        <div>
            <h1>Relatório — <?= htmlspecialchars($fluxo['nome']) ?></h1>
            <p class="em_meta">Últimos 30 dias</p>
        </div>
        <div class="em_actions">
            <a href="<?= $base ?>/admin/email-marketing/automacoes/<?= (int)$fluxo['id'] ?>/editar" class="em_btn">Configurar</a>
            <a href="<?= $base ?>/admin/email-marketing/automacoes" class="em_btn">Voltar</a>
        </div>
    </div>

    <div class="em_kpi_grid">
        <div class="em_card">
            <span class="em_card_label">Enviados</span>
            <span class="em_card_value"><?= number_format($totalEnviados, 0, ',', '.') ?></span>
        </div>
        <div class="em_card">
            <span class="em_card_label">Suprimidos</span>
            <span class="em_card_value"><?= number_format($totalSuprimidos, 0, ',', '.') ?></span>
        </div>
        <div class="em_card">
            <span class="em_card_label em_warn">Erros</span>
            <span class="em_card_value"><?= number_format($totalErros, 0, ',', '.') ?></span>
        </div>
    </div>

    <h2>Últimos 50 envios</h2>
    <table class="em_table">
        <thead><tr>
            <th>Cliente</th><th>Email</th><th>Passo</th>
            <th>Resultado</th><th>Cupom</th><th>Data</th>
        </tr></thead>
        <tbody>
        <?php if (empty($historico)): ?>
            <tr><td colspan="6" class="em_empty">Nenhum envio registrado ainda.</td></tr>
        <?php else: foreach ($historico as $h): ?>
            <tr>
                <td><?= htmlspecialchars($h['cliente_nome']) ?></td>
                <td><?= htmlspecialchars($h['cliente_email']) ?></td>
                <td><?= htmlspecialchars($h['passo_nome']) ?></td>
                <td>
                    <span class="em_badge em_st_<?= $h['resultado']==='enviado'?'ativo':($h['resultado']==='erro'?'falhou':'pendente') ?>">
                        <?= htmlspecialchars($h['resultado']) ?>
                    </span>
                    <?php if ($h['detalhe']): ?>
                        <small style="color:var(--em-text-muted);"><?= htmlspecialchars($h['detalhe']) ?></small>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($h['cupom_codigo'] ?: '—') ?></td>
                <td><?= date('d/m/Y H:i', strtotime($h['criado_em'])) ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
