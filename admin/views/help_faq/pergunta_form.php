<?php
/**
 * View: app/views/admin/help_faq/pergunta_form.php
 * $pergunta   — array ou null
 * $categorias — todas as categorias
 * $modo       — 'criar' | 'editar'
 */
$modo = $modo ?? null;
if (!function_exists('hf_icon_name')) {
    function hf_icon_name($icon): string
    {
        $icon = trim((string) $icon);
        $icon = preg_replace('/^bi\s+/', '', $icon);
        $icon = preg_replace('/^bi-/', '', $icon);
        return $icon !== '' ? $icon : 'question-circle';
    }
}

$editando = $modo === 'editar' && $pergunta;
$titulo   = $editando ? 'Editar pergunta' : 'Nova pergunta';
?>

<div class="container-fluid py-4 hf-admin-page hf-admin-page--form hf-admin-page--form-lg">

    <section class="hf-form-shell">
        <div class="hf-form-header">
            <a href="/admin/help-faq/perguntas" class="hf-back-btn" aria-label="Voltar">
                <?= IconLibrary::render('arrow-back'); ?>
            </a>

            <div>
                <span class="hf-eyebrow">Base de conhecimento</span>
                <h1 class="hf-page-title"><?= $titulo ?></h1>
                <p class="hf-page-subtitle">Cadastre respostas claras, úteis e fáceis de encontrar pelo cliente.</p>
            </div>
        </div>

        <?php if (!empty($_SESSION['flash_error'])): ?>
            <div class="hf-flash hf-flash--error" role="alert">
                <span class="hf-flash-icon"><?= IconLibrary::render('chat-info'); ?></span>
                <span><?= htmlspecialchars($_SESSION['flash_error']) ?></span>
                <button type="button" class="hf-flash-close" data-bs-dismiss="alert" aria-label="Fechar">&times;</button>
            </div>
            <?php unset($_SESSION['flash_error']); ?>
        <?php endif; ?>

        <form method="POST" action="/admin/help-faq/pergunta/salvar" class="hf-form-card" novalidate>
            <?= SecurityHelper::csrfField() ?>

            <?php if ($editando): ?>
                <input type="hidden" name="id" value="<?= (int)$pergunta['id'] ?>">
            <?php endif; ?>

            <div class="hf-form-section">
                <div class="hf-section-title">
                    <span><?= IconLibrary::render('folder-check'); ?></span>
                    <div>
                        <strong>Contexto da pergunta</strong>
                        <small>Vincule a pergunta à categoria correta da central.</small>
                    </div>
                </div>

                <div class="hf-field">
                    <label class="hf-label" for="pergCat">
                        Categoria <span>*</span>
                    </label>
                    <select name="categoria_id" id="pergCat" class="form-select hf-select" required>
                        <option value="">Selecione uma categoria…</option>
                        <?php foreach ($categorias as $cat): ?>
                            <option value="<?= (int)$cat['id'] ?>"
                                <?= ($editando && $pergunta['categoria_id'] == $cat['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['nome']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="hf-field">
                    <label class="hf-label" for="pergTexto">
                        Pergunta <span>*</span>
                    </label>
                    <input
                        type="text"
                        name="pergunta"
                        id="pergTexto"
                        class="form-control hf-input"
                        value="<?= htmlspecialchars($editando ? $pergunta['pergunta'] : '') ?>"
                        placeholder="Como o cliente perguntaria naturalmente"
                        required
                        maxlength="300"
                        autofocus
                    >
                    <div class="hf-hint">Escreva na perspectiva do cliente — ex: “Como faço para acompanhar meu pedido?”</div>
                </div>
            </div>

            <div class="hf-form-section">
                <div class="hf-section-title">
                    <span><?= IconLibrary::render('question-circle'); ?></span>
                    <div>
                        <strong>Resposta</strong>
                        <small>Use linguagem objetiva, com orientação prática e links quando necessário.</small>
                    </div>
                </div>

                <div class="hf-field">
                    <label class="hf-label" for="pergResposta">
                        Resposta <span>*</span>
                    </label>

                    <div class="hf-editor-toolbar">
                        <button type="button" class="hf-editor-btn" data-tag="strong" title="Negrito"><strong>B</strong></button>
                        <button type="button" class="hf-editor-btn" data-tag="em" title="Itálico"><em>I</em></button>
                        <button type="button" class="hf-editor-btn" data-tag="u" title="Sublinhado"><u>U</u></button>
                        <button type="button" class="hf-editor-btn" data-br title="Nova linha">↵ br</button>
                        <button type="button" class="hf-editor-btn" data-link title="Link">link</button>
                        <button type="button" class="hf-editor-btn" data-lista title="Lista com marcadores">• lista</button>
                    </div>

                    <textarea
                        name="resposta"
                        id="pergResposta"
                        class="form-control hf-editor-textarea"
                        rows="9"
                        required
                        placeholder="Resposta completa. HTML básico é permitido: <strong>, <em>, <br>, <a href=&quot;...&quot;>."
                    ><?= htmlspecialchars($editando ? $pergunta['resposta'] : '') ?></textarea>
                </div>

                <div class="hf-field">
                    <label class="hf-label">Pré-visualização</label>
                    <div class="hf-preview-box" id="hfPreview">
                        <?php if ($editando): ?>
                            <?= $pergunta['resposta'] ?>
                        <?php else: ?>
                            <span class="hf-preview-empty">
                                O preview aparece enquanto você digita…
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="hf-form-section">
                <div class="hf-section-title">
                    <span><?= IconLibrary::render('vertical-distribute'); ?></span>
                    <div>
                        <strong>Configurações</strong>
                        <small>Controle prioridade de exibição e publicação da pergunta.</small>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-sm-4">
                        <div class="hf-field">
                            <label class="hf-label" for="pergOrdem">Ordem</label>
                            <input
                                type="number"
                                name="ordem"
                                id="pergOrdem"
                                class="form-control hf-input"
                                value="<?= $editando ? (int)$pergunta['ordem'] : 0 ?>"
                                min="0"
                            >
                        </div>
                    </div>

                    <div class="col-sm-8">
                        <div class="hf-switch-card">
                            <div>
                                <strong>Pergunta ativa</strong>
                                <small>Exibir esta pergunta na central de ajuda.</small>
                            </div>
                            <div class="form-check form-switch">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    name="ativo"
                                    id="pergAtivo"
                                    value="1"
                                    <?= (!$editando || !empty($pergunta['ativo'])) ? 'checked' : '' ?>
                                >
                                <label class="form-check-label" for="pergAtivo">Ativa</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="hf-form-actions">
                <button type="submit" class="hf-btn hf-btn--primary">
                    <span class="hf-btn-icon"><?= IconLibrary::render('check'); ?></span>
                    <?= $editando ? 'Salvar alterações' : 'Criar pergunta' ?>
                </button>
                <a href="/admin/help-faq/perguntas" class="hf-btn hf-btn--secondary">Cancelar</a>
            </div>
        </form>
    </section>
</div>

<script>
$(function () {
    var $ta = $('#pergResposta');
    var $pv = $('#hfPreview');
    var placeholder = '<span class="hf-preview-empty">O preview aparece enquanto você digita…</span>';

    $ta.on('input', function () {
        var v = $(this).val();
        $pv.html(v.trim() ? v : placeholder);
    });

    $('.hf-editor-btn[data-tag]').on('click', function () {
        var tag = $(this).data('tag');
        var ta  = $ta[0];
        var s   = ta.selectionStart;
        var e   = ta.selectionEnd;
        var sel = ta.value.substring(s, e);
        var rep = '<' + tag + '>' + sel + '</' + tag + '>';

        ta.value = ta.value.substring(0, s) + rep + ta.value.substring(e);
        $ta.trigger('input');
        ta.focus();
        ta.setSelectionRange(s + tag.length + 2, s + tag.length + 2 + sel.length);
    });

    $('[data-br]').on('click', function () {
        var ta = $ta[0];
        var s  = ta.selectionStart;

        ta.value = ta.value.substring(0, s) + '<br>\n' + ta.value.substring(s);
        $ta.trigger('input');
        ta.focus();
        ta.setSelectionRange(s + 5, s + 5);
    });

    $('[data-link]').on('click', function () {
        var url = prompt('URL do link:', 'https://');

        if (!url) {
            return;
        }

        var ta  = $ta[0];
        var s   = ta.selectionStart;
        var e   = ta.selectionEnd;
        var sel = ta.value.substring(s, e) || 'clique aqui';
        var rep = '<a href="' + url + '" target="_blank">' + sel + '</a>';

        ta.value = ta.value.substring(0, s) + rep + ta.value.substring(e);
        $ta.trigger('input');
    });

    $('[data-lista]').on('click', function () {
        var ta  = $ta[0];
        var s   = ta.selectionStart;
        var ins = '<br>• Item 1<br>• Item 2<br>• Item 3';

        ta.value = ta.value.substring(0, s) + ins + ta.value.substring(s);
        $ta.trigger('input');
    });
});
</script>
