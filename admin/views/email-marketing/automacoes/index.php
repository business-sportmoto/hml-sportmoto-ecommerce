<?php
/** @var array $kpis */
/** @var array $fluxos */
$base = defined('BASE_URL') ? BASE_URL : '';
?>
<div class="em_wrapper" data-base="<?= htmlspecialchars($base) ?>">
    <div class="em_header">
        <h1>Automações de Email</h1>
    </div>

    <div class="em_aviso" style="margin-bottom:20px;">
        <strong>Worker:</strong> configure o cron a cada 5 minutos:
        <code>*/5 * * * * /usr/local/lsws/lsphp82/bin/php <?= htmlspecialchars(defined('ROOT_PATH') ? ROOT_PATH : '/caminho') ?>/cli/automacao-worker.php >> storage/logs/automacao-worker.log 2>&1</code>
    </div>

    <table class="em_table">
        <thead><tr>
            <th>Automação</th><th>Status</th>
            <th>Pendentes</th><th>Enviados (30d)</th>
            <th>Suprimidos</th><th>Erros</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($kpis as $k): ?>
            <tr>
                <td><strong><?= htmlspecialchars($k['nome']) ?></strong><br>
                    <small style="color:var(--em-text-muted);"><?= htmlspecialchars($k['tipo']) ?></small>
                </td>
                <td>
                    <span class="em_badge em_st_<?= $k['ativo'] ? 'ativo' : 'rascunho' ?>">
                        <?= $k['ativo'] ? 'Ativo' : 'Inativo' ?>
                    </span>
                </td>
                <td><?= number_format($k['pendentes'], 0, ',', '.') ?></td>
                <td><?= number_format($k['enviados'],  0, ',', '.') ?></td>
                <td><?= number_format($k['cancelados'],0, ',', '.') ?></td>
                <td><?= number_format($k['erros'],     0, ',', '.') ?></td>
                <td class="em_actions_cell">
                    <?php
                    // Encontra o fluxo correspondente para pegar o ID
                    $fluxoId = null;
                    foreach ($fluxos as $f) {
                        if ($f['tipo'] === $k['tipo']) { $fluxoId = $f['id']; break; }
                    }
                    ?>
                    <?php if ($fluxoId): ?>
                        <a href="<?= $base ?>/admin/email-marketing/automacoes/<?= $fluxoId ?>/editar" class="em_link">Configurar</a>
                        <a href="<?= $base ?>/admin/email-marketing/automacoes/<?= $fluxoId ?>/relatorio" class="em_link">Relatório</a>
                        <button type="button" class="em_link <?= $k['ativo'] ? 'em_warn' : '' ?>"
                                data-em-action="auto-toggle"
                                data-id="<?= $fluxoId ?>"
                                data-ativo="<?= $k['ativo'] ? 0 : 1 ?>">
                            <?= $k['ativo'] ? 'Desativar' : 'Ativar' ?>
                        </button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
