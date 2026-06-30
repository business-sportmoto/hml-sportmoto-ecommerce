<?php
/** @var array|null $item */
$base = defined('BASE_URL') ? BASE_URL : '';
$t = $item ?: [
    'id' => 0, 'nome' => '', 'tipo' => 'marketing',
    'assunto' => '', 'preheader' => '', 'html' => '', 'texto' => '',
    'status' => 'rascunho',
];
?>
<div class="em_wrapper" data-base="<?= htmlspecialchars($base) ?>">
    <div class="em_header">
        <h1><?= $t['id'] ? 'Editar' : 'Novo' ?> template</h1>
        <a href="<?= $base ?>/admin/email-marketing/templates" class="em_btn">Voltar</a>
    </div>

    <form id="em_form_template" class="em_form">
        <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

        <div class="em_form_grid">
            <label>Nome <input type="text" name="nome" required maxlength="120" value="<?= htmlspecialchars($t['nome']) ?>"></label>
            <label>Tipo
                <select name="tipo">
                    <option value="marketing"    <?= $t['tipo']==='marketing'?'selected':'' ?>>Marketing</option>
                    <option value="transacional" <?= $t['tipo']==='transacional'?'selected':'' ?>>Transacional</option>
                </select>
            </label>
            <label>Status
                <select name="status">
                    <option value="rascunho" <?= $t['status']==='rascunho'?'selected':'' ?>>Rascunho</option>
                    <option value="ativo"    <?= $t['status']==='ativo'?'selected':'' ?>>Ativo</option>
                    <option value="arquivado"<?= $t['status']==='arquivado'?'selected':'' ?>>Arquivado</option>
                </select>
            </label>
        </div>

        <label>Assunto <input type="text" name="assunto" required maxlength="250" value="<?= htmlspecialchars($t['assunto']) ?>"></label>
        <label>Preheader <input type="text" name="preheader" maxlength="250" value="<?= htmlspecialchars($t['preheader'] ?? '') ?>"></label>

        <label>HTML
            <textarea name="html" id="html" rows="18" class="em_code" required
                autocorrect="off" 
                autocapitalize="off" 
                autocomplete="off" 
                spellcheck="false"
            ><?= htmlspecialchars($t['html']) ?></textarea>
            <small>Variáveis disponíveis: <code>{{nome}}</code>, <code>{{primeiro_nome}}</code>, <code>{{email}}</code>, <code>{{cupom}}</code>, <code>{{url_descadastro}}</code>, <code>{{url_site}}</code>, <code>{{site_nome}}</code>, <code>{{data_atual}}</code>.</small>
        </label>

        <label>Texto plano (opcional)
            <textarea name="texto" rows="6"><?= htmlspecialchars($t['texto'] ?? '') ?></textarea>
        </label>

        <div class="em_form_actions">
            <button type="button" class="em_btn" data-em-action="preview-template">Preview</button>
            <button type="submit" class="em_btn em_btn_primary">Salvar</button>
        </div>
    </form>

    <div class="em_section">
        <h2>Preview</h2>
        <iframe id="em_preview_iframe" class="em_preview"></iframe>
    </div>
</div>


<script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.32.2/ace.min.js"></script>

<script>
// Esconde o textarea original e substitui por uma div
var textarea = document.getElementById('html');
var div = document.createElement('div');
div.style.height = '700px';
textarea.parentNode.insertBefore(div, textarea);
textarea.style.display = 'none';

var aceEditor = ace.edit(div);

// Adiciona depois do ace.edit():
aceEditor.container.setAttribute('autocorrect', 'off');
aceEditor.container.setAttribute('autocapitalize', 'off');
aceEditor.container.setAttribute('autocomplete', 'off');
aceEditor.container.setAttribute('spellcheck', 'false');
aceEditor.setOption('useWorker', false); // desativa syntax worker que pode interferir

aceEditor.setTheme('ace/theme/monokai');
aceEditor.session.setMode('ace/mode/html');
aceEditor.setValue(textarea.value);

// Sincroniza antes de salvar
aceEditor.session.on('change', function() {
    textarea.value = aceEditor.getValue();
});
</script>