<?php
/** @var array $item */
/** @var array $versao */
$base = defined('BASE_URL') ? BASE_URL : '';
?>
<div class="em_wrapper" data-base="<?= htmlspecialchars($base) ?>">
    <div class="em_header">
        <div>
            <h1>v<?= (int)$versao['versao'] ?> · <?= htmlspecialchars($versao['nome']) ?></h1>
            <p class="em_meta">
                Criada em <?= date('d/m/Y H:i', strtotime($versao['criado_em'])) ?>
                <?= $versao['motivo'] ? ' · ' . htmlspecialchars($versao['motivo']) : '' ?>
            </p>
        </div>
        <div class="em_actions">
            <a href="<?= $base ?>/admin/email-marketing/templates/<?= (int)$item['id'] ?>/versoes" class="em_btn">Histórico</a>
            <button type="button" class="em_btn em_btn_primary"
                    data-em-action="tpl-restaurar" data-versao-id="<?= (int)$versao['id'] ?>"
                    data-versao="<?= (int)$versao['versao'] ?>">Restaurar esta versão</button>
        </div>
    </div>

    <div class="em_form_grid">
        <div>
            <h3>Assunto</h3>
            <p style="font-size:15px; color:var(--em-text); margin-top:4px;"><?= htmlspecialchars($versao['assunto']) ?></p>
            <?php if ($versao['preheader']): ?>
                <h3 style="margin-top:14px;">Preheader</h3>
                <p style="font-size:13px; color:var(--em-text-muted);"><?= htmlspecialchars($versao['preheader']) ?></p>
            <?php endif; ?>
            <h3 style="margin-top:14px;">Formato</h3>
            <p><span class="em_badge"><?= htmlspecialchars($versao['formato']) ?></span></p>
        </div>
    </div>

    <h2>Preview do HTML</h2>
    <iframe class="em_preview" id="em_versao_preview"></iframe>

    <script>
        (function () {
            var html = <?= json_encode($versao['html']) ?>;
            var iframe = document.getElementById('em_versao_preview');
            var doc = iframe.contentDocument || iframe.contentWindow.document;
            doc.open(); doc.write(html); doc.close();
        })();
    </script>
</div>
