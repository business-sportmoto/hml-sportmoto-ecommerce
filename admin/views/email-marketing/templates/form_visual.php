<?php
/**
 * admin/views/email-marketing/templates/form_visual.php
 *
 * Editor visual de email usando GrapesJS (newsletter preset).
 * Os assets do GrapesJS devem estar em /assets/vendor/grapesjs/.
 */
/** @var array|null $item */
$base = defined('BASE_URL') ? BASE_URL : '';
$t = $item ?: [
    'id' => 0, 'nome' => '', 'tipo' => 'marketing', 'formato' => 'visual',
    'assunto' => '', 'preheader' => '', 'html' => '', 'source_json' => null,
    'source_css' => null, 'texto' => '', 'status' => 'rascunho',
];
?>
<div class="em_wrapper em_template_visual" data-base="<?= htmlspecialchars($base) ?>">
    <div class="em_header">
        <div>
            <h1><?= $t['id'] ? 'Editar' : 'Novo' ?> template <span class="em_badge em_pv_brevo">visual</span></h1>
            <?php if ($t['id']): ?>
                <p class="em_meta">v<?= (int)$t['versao'] ?? 1 ?> ·
                    <a href="<?= $base ?>/admin/email-marketing/templates/<?= (int)$t['id'] ?>/versoes">ver histórico</a>
                </p>
            <?php endif; ?>
        </div>
        <div class="em_actions">
            <a href="<?= $base ?>/admin/email-marketing/templates" class="em_btn">Voltar</a>
            <button type="button" class="em_btn" data-em-action="tpl-preview-visual">Preview</button>
            <button type="button" class="em_btn em_btn_primary" data-em-action="tpl-salvar-visual"
                    data-id="<?= (int)$t['id'] ?>">Salvar</button>
        </div>
    </div>

    <!-- Linha de metadata -->
    <div class="em_form em_tpl_meta">
        <input type="hidden" id="em_tpl_id" value="<?= (int)$t['id'] ?>">
        <input type="hidden" id="em_csrf" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

        <div class="em_form_grid">
            <label>Nome
                <input type="text" id="em_tpl_nome" value="<?= htmlspecialchars($t['nome']) ?>" maxlength="120" required>
            </label>
            <label>Tipo
                <select id="em_tpl_tipo">
                    <option value="marketing"    <?= $t['tipo']==='marketing'?'selected':'' ?>>Marketing</option>
                    <option value="transacional" <?= $t['tipo']==='transacional'?'selected':'' ?>>Transacional</option>
                </select>
            </label>
            <label>Status
                <select id="em_tpl_status">
                    <option value="rascunho" <?= ($t['status']??'')==='rascunho'?'selected':'' ?>>Rascunho</option>
                    <option value="ativo"    <?= ($t['status']??'')==='ativo'?'selected':'' ?>>Ativo</option>
                    <option value="arquivado"<?= ($t['status']??'')==='arquivado'?'selected':'' ?>>Arquivado</option>
                </select>
            </label>
        </div>
        <label>Assunto
            <input type="text" id="em_tpl_assunto" value="<?= htmlspecialchars($t['assunto']) ?>" maxlength="250" required>
        </label>
        <label>Preheader (texto de prévia no inbox)
            <input type="text" id="em_tpl_preheader" value="<?= htmlspecialchars($t['preheader'] ?? '') ?>" maxlength="250">
        </label>
    </div>

    <div id="em_grapes_warn" class="em_aviso" style="margin-bottom:0; display:none;">
        <strong>GrapesJS não foi carregado.</strong> Verifique se os arquivos estão em
        <code>/assets/vendor/grapesjs/</code>. Veja o README do módulo para instruções.
    </div>

    <!-- Editor GrapesJS -->
    <div id="em_grapes_box" class="em_grapes_box">
        <div id="em_grapes_editor"></div>
    </div>

    <!-- Source JSON serializado pelo GrapesJS (escondido) -->
    <textarea id="em_source_json" style="display:none;"><?= htmlspecialchars($t['source_json'] ?? '') ?></textarea>
    <textarea id="em_source_css"  style="display:none;"><?= htmlspecialchars($t['source_css'] ?? '') ?></textarea>
    <textarea id="em_source_html" style="display:none;"><?= htmlspecialchars($t['html'] ?? '') ?></textarea>
</div>

<!-- GrapesJS assets — você precisa colocar os arquivos em /assets/vendor/grapesjs/ -->
<link rel="stylesheet" href="<?= $base ?>/assets/vendor/grapesjs/grapes.min.css">
<!-- <link rel="stylesheet" href="<?= $base ?>/assets/vendor/grapesjs/grapesjs-preset-newsletter.css"> -->
<script src="<?= $base ?>/assets/vendor/grapesjs/grapes.min.js"></script>
<script src="<?= $base ?>/assets/vendor/grapesjs/grapesjs-preset-newsletter.min.js"></script>
