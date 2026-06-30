<?php
/**
 * View: app/views/admin/help_faq/perguntas.php
 *
 * Requer no layout admin:
 *   window.BASE_URL, window.CSRF_TOKEN, adminDrawer(), CK.*, showToast()
 *
 * Rotas AJAX esperadas no HelpFaqController:
 *   GET  /admin/help-faq/pergunta/form?id=X&categoria_id=Y  → json { ok, html }
 *   POST /admin/help-faq/pergunta/salvar                    → json { ok, msg, pergunta }
 *   POST /admin/help-faq/pergunta/excluir                   → json { ok, msg }
 */

$perguntas        = $perguntas ?? [];
$categorias       = $categorias ?? [];
$filtro_categoria = $filtro_categoria ?? '';
$totalPerguntas   = count($perguntas);

$nomeFiltro = 'Todas as categorias';
foreach ($categorias as $cat) {
    if ((string)($cat['id'] ?? '') === (string)$filtro_categoria) {
        $nomeFiltro = (string)($cat['nome'] ?? $nomeFiltro);
        break;
    }
}

$jsIcons = [
    'loader'         => IconLibrary::render('rotate-left'),
    'alert'          => IconLibrary::render('assignment-late'),
    'wifiOff'        => IconLibrary::render('wifi-off'),
    'trash'          => IconLibrary::render('trash'),
    'edit'           => IconLibrary::render('edit'),
    'plus'           => IconLibrary::render('plus'),
    'inbox'          => IconLibrary::render('inbox'),
    'helpCircle'     => IconLibrary::render('help'),
];

if (!function_exists('hf_admin_preview')) {
    function hf_admin_preview(?string $html, int $limit = 96): string
    {
        $text = trim(strip_tags((string)$html));
        if ($text === '') {
            return '';
        }

        if (mb_strlen($text, 'UTF-8') > $limit) {
            return mb_substr($text, 0, $limit, 'UTF-8') . '…';
        }

        return $text;
    }
}
?>

<div class="container-fluid py-4 hf-admin-page hf-admin-page--wide hf-admin-page--questions">

    <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="hf-flash hf-flash--success hf-flash-auto" role="alert">
            <span class="hf-flash-icon"><?= IconLibrary::render('check-circle'); ?></span>
            <span><?= htmlspecialchars($_SESSION['flash_success'], ENT_QUOTES, 'UTF-8') ?></span>
            <button type="button" class="hf-flash-close" aria-label="Fechar">&times;</button>
        </div>
        <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>

    <section class="hf-commandbar">
        <div class="hf-commandbar-main">
            <div class="hf-title-icon hf-title-icon--blue">
                <?= IconLibrary::render('chat-info'); ?>
            </div>
            <div>
                <span class="hf-eyebrow">Gestão de conteúdo</span>
                <h1 class="hf-page-title">Perguntas frequentes</h1>
                <p class="hf-page-subtitle" id="hfPergSubtitle">
                    <?= (int)$totalPerguntas ?> pergunta<?= $totalPerguntas !== 1 ? 's' : '' ?> cadastrada<?= $totalPerguntas !== 1 ? 's' : '' ?>
                    <?php if (!empty($filtro_categoria)): ?>
                        em <strong><?= htmlspecialchars($nomeFiltro, ENT_QUOTES, 'UTF-8') ?></strong>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <div class="hf-actions hf-actions--questions">
            <form method="GET" action="/admin/help-faq/perguntas" id="formFiltroCategoria" class="hf-filter-form">
                <label class="hf-filter-label" for="hfFiltroCategoria">Categoria</label>
                <select
                    name="categoria_id"
                    id="hfFiltroCategoria"
                    class="hf-filter-select"
                    onchange="this.form.submit()"
                    aria-label="Filtrar por categoria"
                >
                    <option value="">Todas as categorias</option>
                    <?php foreach ($categorias as $cat): ?>
                        <option value="<?= (int)$cat['id'] ?>" <?= (string)$filtro_categoria === (string)$cat['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat['nome'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>

            <button
                type="button"
                class="hf-btn hf-btn--primary hf-btn--sm"
                id="btnNovaPergunta"
                <?php if (!empty($filtro_categoria)): ?>
                    data-categoria-id="<?= (int)$filtro_categoria ?>"
                <?php endif; ?>
            >
                <span class="hf-btn-icon"><?= IconLibrary::render('plus'); ?></span>
                Nova pergunta
            </button>

            <a href="/admin/help-faq" class="hf-btn hf-btn--secondary hf-btn--sm">
                <span class="hf-btn-icon"><?= IconLibrary::render('grid'); ?></span>
                Categorias
            </a>
        </div>
    </section>

    <section class="hf-kpi-grid hf-kpi-grid--questions" aria-label="Resumo das perguntas frequentes">
        <div class="hf-kpi-card">
            <span class="hf-kpi-label">Perguntas</span>
            <strong id="hfPergTotalKpi"><?= (int)$totalPerguntas ?></strong>
            <small>Total listado</small>
        </div>
        <div class="hf-kpi-card">
            <span class="hf-kpi-label">Filtro atual</span>
            <strong class="hf-kpi-text"><?= htmlspecialchars($nomeFiltro, ENT_QUOTES, 'UTF-8') ?></strong>
            <small>Segmentação da base</small>
        </div>
        <div class="hf-kpi-card">
            <span class="hf-kpi-label">Categorias</span>
            <strong><?= (int)count($categorias) ?></strong>
            <small>Disponíveis para vínculo</small>
        </div>
    </section>

    <section class="hf-panel">
        <div class="hf-panel-head">
            <div>
                <h2>Base de perguntas</h2>
                <p>Organize respostas rápidas, objetivas e úteis para reduzir atrito no atendimento.</p>
            </div>
        </div>

        <div class="hf-table-wrap">
            <table class="hf-table hf-table--questions" id="tabelaPerguntas">
                <thead>
                    <tr>
                        <th style="width:36px">#</th>
                        <th style="width:155px">Categoria</th>
                        <th>Pergunta</th>
                        <th class="d-none d-xl-table-cell">Resposta</th>
                        <th style="width:70px">Ordem</th>
                        <th style="width:92px">Status</th>
                        <th style="width:96px" class="text-end">Ações</th>
                    </tr>
                </thead>
                <tbody id="tbodyPerguntas">
                <?php if (empty($perguntas)): ?>
                    <tr id="trVazioPergs">
                        <td colspan="7">
                            <div class="hf-empty-state">
                                <span class="hf-empty-icon"><?= IconLibrary::render('inbox'); ?></span>
                                <strong>Nenhuma pergunta cadastrada</strong>
                                <p>Crie a primeira pergunta para alimentar a Central de Ajuda.</p>
                                <button type="button" class="hf-btn hf-btn--primary hf-btn--sm" id="btnNovaPerguntaEmpty">
                                    <span class="hf-btn-icon"><?= IconLibrary::render('plus'); ?></span>
                                    Criar primeira pergunta
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($perguntas as $p): ?>
                    <tr data-perg-id="<?= (int)$p['id'] ?>">
                        <td class="hf-muted"><?= (int)$p['id'] ?></td>
                        <td>
                            <span class="hf-badge hf-badge--category">
                                <?= htmlspecialchars($p['categoria_nome'] ?? 'Sem categoria', ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </td>
                        <td>
                            <div class="hf-question-title">
                                <?= htmlspecialchars($p['pergunta'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        </td>
                        <td class="hf-question-preview d-none d-xl-table-cell">
                            <?= htmlspecialchars(hf_admin_preview($p['resposta'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                        </td>
                        <td>
                            <span class="hf-order-pill"><?= (int)($p['ordem'] ?? 0) ?></span>
                        </td>
                        <td>
                            <?php if (!empty($p['ativo'])): ?>
                                <span class="hf-status hf-status--active hf-js-status">Ativo</span>
                            <?php else: ?>
                                <span class="hf-status hf-status--inactive hf-js-status">Inativo</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <div class="hf-row-actions">
                                <button
                                    type="button"
                                    class="hf-icon-action js-editar-perg"
                                    data-id="<?= (int)$p['id'] ?>"
                                    title="Editar"
                                    aria-label="Editar pergunta"
                                >
                                    <?= IconLibrary::render('edit'); ?>
                                </button>
                                <button
                                    type="button"
                                    class="hf-icon-action hf-icon-action--danger js-excluir-perg"
                                    data-id="<?= (int)$p['id'] ?>"
                                    data-pergunta="<?= htmlspecialchars(mb_substr((string)($p['pergunta'] ?? ''), 0, 70, 'UTF-8'), ENT_QUOTES, 'UTF-8') ?>"
                                    title="Excluir"
                                    aria-label="Excluir pergunta"
                                >
                                    <?= IconLibrary::render('trash'); ?>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<script>
$(function () {
    var filtroCategoria = '<?= (int)$filtro_categoria ?>' || null;

    var HF_ICONS = <?= json_encode($jsIcons, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    // ─── Helpers ──────────────────────────────────────────────────────────

    function removerLinhaVazio() {
        $('#trVazioPergs').remove();
    }

    function atualizarContador() {
        var n = $('#tbodyPerguntas tr[data-perg-id]').length;
        var label = n + ' pergunta' + (n !== 1 ? 's' : '') + ' cadastrada' + (n !== 1 ? 's' : '');
        $('#hfPergSubtitle').text(label);
        $('#hfPergTotalKpi').text(n);
    }

    function escapeHtml(value) {
        return $('<div>').text(value || '').html();
    }

    function adicionarLinhaPergunta(p) {
        removerLinhaVazio();

        var statusClass = p.ativo ? 'hf-status--active' : 'hf-status--inactive';
        var statusLabel = p.ativo ? 'Ativo' : 'Inativo';
        var preview     = escapeHtml(p.resposta_preview || '');
        var pergText    = escapeHtml(p.pergunta || '');
        var catText     = escapeHtml(p.categoria_nome || 'Sem categoria');
        var perguntaCurta = escapeHtml((p.pergunta || '').substring(0, 70));

        var $tr = $([
            '<tr data-perg-id="' + p.id + '">',
            '  <td class="hf-muted">' + p.id + '</td>',
            '  <td><span class="hf-badge hf-badge--category">' + catText + '</span></td>',
            '  <td><div class="hf-question-title">' + pergText + '</div></td>',
            '  <td class="hf-question-preview d-none d-xl-table-cell">' + preview + '</td>',
            '  <td><span class="hf-order-pill">' + p.ordem + '</span></td>',
            '  <td><span class="hf-status ' + statusClass + ' hf-js-status">' + statusLabel + '</span></td>',
            '  <td class="text-end">',
            '    <div class="hf-row-actions">',
            '      <button type="button" class="hf-icon-action js-editar-perg" data-id="' + p.id + '" title="Editar" aria-label="Editar pergunta">' + HF_ICONS.edit + '</button>',
            '      <button type="button" class="hf-icon-action hf-icon-action--danger js-excluir-perg" data-id="' + p.id + '" data-pergunta="' + perguntaCurta + '" title="Excluir" aria-label="Excluir pergunta">' + HF_ICONS.trash + '</button>',
            '    </div>',
            '  </td>',
            '</tr>',
        ].join(''));

        $('#tbodyPerguntas').append($tr);
        atualizarContador();
    }

    function atualizarLinhaPergunta(p) {
        var $tr = $('tr[data-perg-id="' + p.id + '"]');
        if (!$tr.length) return;

        var statusClass = p.ativo ? 'hf-status--active' : 'hf-status--inactive';
        var statusLabel = p.ativo ? 'Ativo' : 'Inativo';

        $tr.find('td:nth-child(2) .hf-badge').text(p.categoria_nome || 'Sem categoria');
        $tr.find('.hf-question-title').text(p.pergunta || '');
        $tr.find('.hf-question-preview').text(p.resposta_preview || '');
        $tr.find('.hf-order-pill').text(p.ordem || 0);
        $tr.find('.hf-js-status').attr('class', 'hf-status ' + statusClass + ' hf-js-status').text(statusLabel);
        $tr.find('.js-excluir-perg').data('pergunta', (p.pergunta || '').substring(0, 70));

        $tr.addClass('hf-row-updated');
        setTimeout(function () { $tr.removeClass('hf-row-updated'); }, 1400);
    }

    // ─── Drawer: nova / editar pergunta ───────────────────────────────────

    function abrirDrawerPergunta(pergId, preselCategoria) {
        var drawer = adminDrawer({
            titulo:  pergId ? 'Editar pergunta' : 'Nova pergunta',
            tamanho: 'lg',
        });

        drawer.setConteudo(
            '<div class="hf-drawer-state hf-drawer-state--loading">' +
            '  <span class="hf-drawer-state-icon hf-spin">' + HF_ICONS.loader + '</span>' +
            '  <span>Carregando formulário…</span>' +
            '</div>'
        );

        var params = {};
        if (pergId)          params.id          = pergId;
        if (preselCategoria) params.categoria_id = preselCategoria;

        CK.get(BASE_URL + '/admin/help-faq/pergunta/form', params)
            .done(function (res) {
                if (!res.ok) {
                    drawer.setConteudo(
                        '<div class="hf-drawer-state hf-drawer-state--error">' +
                        '  <span class="hf-drawer-state-icon">' + HF_ICONS.alert + '</span>' +
                        '  <span>' + escapeHtml(res.msg || 'Erro ao carregar.') + '</span>' +
                        '</div>'
                    );
                    return;
                }
                drawer.setConteudo(res.html);
                iniciarFormPergunta(drawer, pergId);
            })
            .fail(function () {
                drawer.setConteudo(
                    '<div class="hf-drawer-state hf-drawer-state--error">' +
                    '  <span class="hf-drawer-state-icon">' + HF_ICONS.wifiOff + '</span>' +
                    '  <span>Erro de conexão.</span>' +
                    '</div>'
                );
            });
    }

    function iniciarFormPergunta(drawer, pergId) {
        var $body = $(drawer.body());
        var placeholder = '<span class="hf-preview-empty">O preview aparece enquanto você digita…</span>';

        function getRespostaField() {
            return $body.find('#drawerResposta, #pergResposta, [name="resposta"]').first();
        }

        function getPreviewBox() {
            return $body.find('#drawerPreview, #hfPreview, .hf-preview-box').first();
        }

        $body.on('input', '#drawerResposta, #pergResposta, [name="resposta"]', function () {
            var v = $(this).val();
            getPreviewBox().html(v.trim() ? v : placeholder);
        });

        $body.on('click', '.hf-editor-tool[data-tag], .hf-editor-btn[data-tag]', function () {
            var tag = $(this).data('tag');
            var ta  = getRespostaField()[0];
            if (!ta) return;

            var s   = ta.selectionStart;
            var e   = ta.selectionEnd;
            var sel = ta.value.substring(s, e);
            var rep = '<' + tag + '>' + sel + '</' + tag + '>';
            ta.value = ta.value.substring(0, s) + rep + ta.value.substring(e);
            $(ta).trigger('input');
            ta.focus();
            ta.setSelectionRange(s + tag.length + 2, s + tag.length + 2 + sel.length);
        });

        $body.on('click', '.hf-editor-tool[data-br], .hf-editor-btn[data-br]', function () {
            var ta = getRespostaField()[0];
            if (!ta) return;

            var s = ta.selectionStart;
            ta.value = ta.value.substring(0, s) + '<br>\n' + ta.value.substring(s);
            $(ta).trigger('input');
            ta.focus();
            ta.setSelectionRange(s + 5, s + 5);
        });

        $body.on('click', '.hf-editor-tool[data-link], .hf-editor-btn[data-link]', function () {
            var url = prompt('URL do link:', 'https://');
            if (!url) return;

            var ta = getRespostaField()[0];
            if (!ta) return;

            var s   = ta.selectionStart;
            var e   = ta.selectionEnd;
            var sel = ta.value.substring(s, e) || 'clique aqui';
            var rep = '<a href="' + url + '" target="_blank">' + sel + '</a>';
            ta.value = ta.value.substring(0, s) + rep + ta.value.substring(e);
            $(ta).trigger('input');
        });

        $body.on('click', '.hf-editor-tool[data-lista], .hf-editor-btn[data-lista]', function () {
            var ta = getRespostaField()[0];
            if (!ta) return;

            var s   = ta.selectionStart;
            var ins = '<br>• Item 1<br>• Item 2<br>• Item 3';
            ta.value = ta.value.substring(0, s) + ins + ta.value.substring(s);
            $(ta).trigger('input');
        });

        $body.on('submit', '#formPerguntaDrawer, form[action$="/pergunta/salvar"]', function (e) {
            e.preventDefault();

            var $btn = $body.find('.js-btn-salvar-perg, button[type="submit"]').first();
            CK.btnLoading($btn);

            var data = {
                categoria_id: $body.find('[name="categoria_id"]').val(),
                pergunta:     $body.find('[name="pergunta"]').val(),
                resposta:     $body.find('[name="resposta"]').val(),
                ordem:        $body.find('[name="ordem"]').val(),
                ativo:        $body.find('[name="ativo"]').is(':checked') ? 1 : 0,
            };
            if (pergId) data.id = pergId;

            CK.post('/admin/help-faq/pergunta/salvar', data)
                .done(function (res) {
                    CK.btnLoading($btn, false);
                    if (!res.ok) {
                        showToast(res.msg || 'Erro ao salvar.', 'error');
                        return;
                    }
                    showToast(res.msg || 'Pergunta salva!', 'success');
                    drawer.close();
                    if (pergId) {
                        atualizarLinhaPergunta(res.pergunta);
                    } else {
                        adicionarLinhaPergunta(res.pergunta);
                    }
                })
                .fail(function () {
                    CK.btnLoading($btn, false);
                    showToast('Erro de conexão.', 'error');
                });
        });
    }

    // ─── Drawer: confirmar exclusão de pergunta ────────────────────────────

    function abrirDrawerExcluirPerg(id, textoPerg) {
        var drawer = adminDrawer({
            titulo:  'Excluir pergunta',
            tamanho: 'sm',
        });

        drawer.setConteudo([
            '<div class="hf-drawer-confirm">',
            '  <div class="hf-drawer-confirm-icon hf-drawer-confirm-icon--danger">' + HF_ICONS.trash + '</div>',
            '  <p class="hf-drawer-confirm-msg">',
            '    Excluir a pergunta:<br>',
            '    <em>"' + escapeHtml(textoPerg || '') + '…"</em>',
            '  </p>',
            '  <p class="hf-drawer-confirm-sub">Esta ação não pode ser desfeita.</p>',
            '  <div class="hf-drawer-confirm-actions">',
            '    <button type="button" class="hf-btn hf-btn--secondary js-cancelar">Cancelar</button>',
            '    <button type="button" class="hf-btn hf-btn--danger js-confirmar-excluir-perg" data-id="' + id + '">',
            '      <span class="hf-btn-icon">' + HF_ICONS.trash + '</span> Excluir',
            '    </button>',
            '  </div>',
            '</div>',
        ].join(''));

        $(drawer.body()).on('click', '.js-cancelar', function () {
            drawer.close();
        });

        $(drawer.body()).on('click', '.js-confirmar-excluir-perg', function () {
            var $btn = $(this);
            CK.btnLoading($btn);

            CK.post('/admin/help-faq/pergunta/excluir', { id: id })
                .done(function (res) {
                    CK.btnLoading($btn, false);
                    if (!res.ok) {
                        showToast(res.msg || 'Erro ao excluir.', 'error');
                        drawer.close();
                        return;
                    }
                    showToast('Pergunta excluída.', 'success');
                    drawer.close();

                    var $tr = $('tr[data-perg-id="' + id + '"]');
                    $tr.addClass('hf-row-removing');

                    setTimeout(function () {
                        $tr.remove();
                        atualizarContador();

                        if ($('#tbodyPerguntas tr[data-perg-id]').length === 0) {
                            $('#tbodyPerguntas').html(
                                '<tr id="trVazioPergs"><td colspan="7">' +
                                '<div class="hf-empty-state">' +
                                '<span class="hf-empty-icon">' + HF_ICONS.inbox + '</span>' +
                                '<strong>Nenhuma pergunta cadastrada</strong>' +
                                '<p>Crie a primeira pergunta para alimentar a Central de Ajuda.</p>' +
                                '<button type="button" class="hf-btn hf-btn--primary hf-btn--sm" id="btnNovaPerguntaEmpty">' +
                                '<span class="hf-btn-icon">' + HF_ICONS.plus + '</span> Criar primeira pergunta' +
                                '</button>' +
                                '</div></td></tr>'
                            );
                        }
                    }, 320);
                })
                .fail(function () {
                    CK.btnLoading($btn, false);
                    showToast('Erro de conexão.', 'error');
                    drawer.close();
                });
        });
    }

    // ─── Bindings ──────────────────────────────────────────────────────────

    $(document).on('click', '#btnNovaPergunta', function () {
        abrirDrawerPergunta(null, $(this).data('categoria-id') || null);
    });

    $(document).on('click', '#btnNovaPerguntaEmpty', function () {
        abrirDrawerPergunta(null, filtroCategoria || null);
    });

    $(document).on('click', '.js-editar-perg', function () {
        abrirDrawerPergunta($(this).data('id'), null);
    });

    $(document).on('click', '.js-excluir-perg', function () {
        abrirDrawerExcluirPerg($(this).data('id'), $(this).data('pergunta'));
    });

    setTimeout(function () { $('.hf-flash-auto').fadeOut(400); }, 5000);

    $(document).on('click', '.hf-flash-close', function () {
        $(this).closest('.hf-flash').fadeOut(300);
    });
});
</script>
