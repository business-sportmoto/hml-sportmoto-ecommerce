<?php
/** @var array $itens */
/** @var array $mapa */
/** @var string $tipo */
$base = defined('BASE_URL') ? BASE_URL : '';
?>
<div class="em_wrapper" data-base="<?= htmlspecialchars($base) ?>">
    <div class="em_header">
        <h1>Log de Transacionais</h1>
        <a href="<?= $base ?>/admin/email-marketing/transacional" class="em_btn">Voltar</a>
    </div>

    <form method="get" style="margin-bottom:16px;">
        <select name="tipo" style="margin-right:8px;">
            <option value="">Todos os tipos</option>
            <?php foreach ($mapa as $k => $v): ?>
                <option value="<?= htmlspecialchars($k) ?>" <?= $tipo===$k?'selected':'' ?>>
                    <?= htmlspecialchars($k) ?> — <?= htmlspecialchars($v) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="em_btn">Filtrar</button>
    </form>

    <p class="em_meta">Últimos <?= count($itens) ?> registros.</p>

    <table class="em_table">
        <thead><tr>
            <th>Data</th><th>Tipo</th><th>Destinatário</th><th>Assunto</th>
            <th>Status</th><th>Template</th>
        </tr></thead>
        <tbody>
        <?php if (empty($itens)): ?>
            <tr><td colspan="6" class="em_empty">Nenhum envio registrado.</td></tr>
        <?php else: foreach ($itens as $i): ?>
            <tr>
                <td style="white-space:nowrap;"><?= date('d/m/Y H:i', strtotime($i['criado_em'])) ?></td>
                <td><code style="font-size:11px;"><?= htmlspecialchars($i['tipo']) ?></code></td>
                <td><?= htmlspecialchars($i['destinatario']) ?></td>
                <td><?= htmlspecialchars($i['assunto'] ?: '—') ?></td>
                <td>
                    <span class="em_badge em_st_<?= $i['status']==='enviado'?'ativo':($i['status']==='erro'?'falhou':'rascunho') ?>">
                        <?= htmlspecialchars($i['status']) ?>
                    </span>
                    <?php if ($i['status']==='erro' && $i['erro_detalhe']): ?>
                        <small style="color:var(--em-warn-text);display:block;font-size:11px;">
                            <?= htmlspecialchars(substr($i['erro_detalhe'], 0, 80)) ?>
                        </small>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($i['template_nome'] ?: '—') ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
