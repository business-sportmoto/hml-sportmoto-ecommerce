<?php
/**
 * View: app/views/admin/help_faq/index.php
 * Lista categorias + navegação para perguntas.
 */

if (!function_exists('hf_icon_name')) {
    function hf_icon_name($icon): string
    {
        $icon = trim((string) $icon);
        $icon = preg_replace('/^bi\s+/', '', $icon);
        $icon = preg_replace('/^bi-/', '', $icon);
        return $icon !== '' ? $icon : 'question-circle';
    }
}

$categorias = $categorias ?? [];

$totalCategorias = count($categorias);
$totalAtivas     = 0;
$totalPerguntas  = 0;

foreach ($categorias as $cat) {
    if (!empty($cat['ativo'])) {
        $totalAtivas++;
    }

    $totalPerguntas += (int)($cat['total_perguntas'] ?? 0);
}
?>

<div class="container-fluid py-4 hf-admin-page hf-admin-page--wide">

    <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="hf-flash hf-flash--success" role="alert">
            <span class="hf-flash-icon"><?= IconLibrary::render('check-circle'); ?></span>
            <span><?= htmlspecialchars($_SESSION['flash_success']) ?></span>
            <button type="button" class="hf-flash-close" data-bs-dismiss="alert" aria-label="Fechar">&times;</button>
        </div>
        <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>

    <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="hf-flash hf-flash--error" role="alert">
            <span class="hf-flash-icon"><?= IconLibrary::render('alert-triangle'); ?></span>
            <span><?= htmlspecialchars($_SESSION['flash_error']) ?></span>
            <button type="button" class="hf-flash-close" data-bs-dismiss="alert" aria-label="Fechar">&times;</button>
        </div>
        <?php unset($_SESSION['flash_error']); ?>
    <?php endif; ?>

    <section class="hf-commandbar">
        <div class="hf-commandbar-main">
            <div class="hf-title-icon hf-title-icon--blue">
                <?= IconLibrary::render('help'); ?>
            </div>
            <div>
                <span class="hf-eyebrow">Central de conhecimento</span>
                <h1 class="hf-page-title">Central de Ajuda — FAQ</h1>
                <p class="hf-page-subtitle">
                    Gerencie categorias e perguntas frequentes exibidas em <code>/ajuda</code>.
                </p>
            </div>
        </div>

        <div class="hf-actions">
            <a href="/admin/help-faq/perguntas" class="hf-btn hf-btn--secondary">
                <span class="hf-btn-icon"><?= IconLibrary::render('list'); ?></span>
                Perguntas
            </a>
            <a href="/admin/help-faq/categoria/nova" class="hf-btn hf-btn--primary" id="btnNovaCategoria">
                <span class="hf-btn-icon"><?= IconLibrary::render('plus'); ?></span>
                Nova categoria
            </a>
            <a href="/ajuda" target="_blank" rel="noopener" class="hf-btn hf-btn--ghost">
                <span class="hf-btn-icon"><?= IconLibrary::render('external-link'); ?></span>
                Ver site
            </a>
        </div>
    </section>

    <section class="hf-kpi-grid" aria-label="Resumo da central de ajuda">
        <div class="hf-kpi-card">
            <span class="hf-kpi-label">Categorias</span>
            <strong><?= (int)$totalCategorias ?></strong>
            <small>Total cadastrado</small>
        </div>
        <div class="hf-kpi-card">
            <span class="hf-kpi-label">Ativas</span>
            <strong><?= (int)$totalAtivas ?></strong>
            <small>Disponíveis no site</small>
        </div>
        <div class="hf-kpi-card">
            <span class="hf-kpi-label">Perguntas</span>
            <strong><?= (int)$totalPerguntas ?></strong>
            <small>Conteúdo publicado</small>
        </div>
    </section>

    <section class="hf-panel">
        <div class="hf-panel-head">
            <div>
                <h2>Categorias cadastradas</h2>
                <p>Organize a navegação da central de ajuda por temas operacionais.</p>
            </div>
        </div>

        <div class="hf-table-wrap">
            <table class="hf-table">
                <thead>
                    <tr>
                        <th style="width:36px">#</th>
                        <th style="width:58px">Ícone</th>
                        <th>Categoria</th>
                        <th style="width:115px">Perguntas</th>
                        <th style="width:80px">Ordem</th>
                        <th style="width:92px">Status</th>
                        <th style="width:118px" class="text-end">Ações</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($categorias)): ?>
                    <tr>
                        <td colspan="7">
                            <div class="hf-empty-state">
                                <span class="hf-empty-icon"><?= IconLibrary::render('inbox'); ?></span>
                                <strong>Nenhuma categoria cadastrada</strong>
                                <p>Crie a primeira categoria para iniciar a estrutura da sua central de ajuda.</p>
                                <a href="/admin/help-faq/categoria/nova" class="hf-btn hf-btn--primary hf-btn--sm">
                                    <span class="hf-btn-icon"><?= IconLibrary::render('plus'); ?></span>
                                    Criar primeira categoria
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($categorias as $cat): ?>
                    <tr>
                        <td class="hf-muted"><?= (int)$cat['id'] ?></td>
                        <td>
                            <span class="hf-table-icon">
                                <?= IconLibrary::render(hf_icon_name($cat['icone'] ?? 'question-circle')); ?>
                            </span>
                        </td>
                        <td>
                            <div class="hf-row-title"><?= htmlspecialchars($cat['nome']) ?></div>

                            <?php if (!empty($cat['descricao'])): ?>
                                <div class="hf-row-desc"><?= htmlspecialchars($cat['descricao']) ?></div>
                            <?php endif; ?>

                            <code class="hf-row-code"><?= htmlspecialchars($cat['slug']) ?></code>
                        </td>
                        <td>
                            <a href="/admin/help-faq/perguntas?categoria_id=<?= (int)$cat['id'] ?>" class="hf-count-link">
                                <?= (int)$cat['total_perguntas'] ?>
                                <span><?= IconLibrary::render('arrow-right'); ?></span>
                            </a>
                        </td>
                        <td class="hf-muted"><?= (int)$cat['ordem'] ?></td>
                        <td>
                            <?php if (!empty($cat['ativo'])): ?>
                                <span class="hf-status hf-status--active">Ativo</span>
                            <?php else: ?>
                                <span class="hf-status hf-status--inactive">Inativo</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <div class="hf-row-actions">
                                <a href="/admin/help-faq/categoria/editar/<?= (int)$cat['id'] ?>" data-id="<?= (int)$cat['id'] ?>"
                                   class="hf-icon-action js-editar-cat"
                                   title="Editar"
                                   aria-label="Editar categoria">
                                    <?= IconLibrary::render('edit'); ?>
                                </a>
                                <button
                                    type="button"
                                    class="hf-icon-action hf-icon-action--danger js-excluir-cat js-excluir-cat"
                                    title="<?= (int)$cat['total_perguntas'] > 0 ? 'Remova as perguntas antes de excluir' : 'Excluir' ?>"
                                    aria-label="Excluir categoria"
                                    data-id="<?= (int)$cat['id'] ?>"
                                    data-nome="<?= htmlspecialchars($cat['nome']) ?>"
                                    <?= (int)$cat['total_perguntas'] > 0 ? 'disabled' : '' ?>
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



<form id="formExcluirCat" method="POST" action="/admin/help-faq/categoria/excluir" style="display:none">
    <?= SecurityHelper::csrfField() ?>
    <input type="hidden" name="id" id="inputExcluirCatId">
</form>

<script>
$(function () {
 
    // ─── helpers ──────────────────────────────────────────────────────────
 
    function removerLinhaVazio() {
        $('#trVazio').remove();
    }
 
    function adicionarLinhaTabela(cat) {
        removerLinhaVazio();
        var badgeClass = cat.ativo ? 'hf-badge-active' : 'hf-badge-inactive';
        var badgeLabel = cat.ativo ? 'Ativo' : 'Inativo';
        var descHtml   = cat.descricao
            ? '<div class="hf-td-name-sub">' + $('<div>').text(cat.descricao).html() + '</div>'
            : '';
 
        var $tr = $([
            '<tr data-cat-id="' + cat.id + '">',
            '  <td class="hf-td-id">' + cat.id + '</td>',
            '  <td><div class="hf-icon-cell"><i class="bi ' + cat.icone + '"></i></div></td>',
            '  <td>',
            '    <div class="hf-td-name-main">' + $('<div>').text(cat.nome).html() + '</div>',
            '    ' + descHtml,
            '    <span class="hf-td-slug">' + $('<div>').text(cat.slug).html() + '</span>',
            '  </td>',
            '  <td><a href="/admin/help-faq/perguntas?categoria_id=' + cat.id + '" class="hf-count-link">0 <i class="bi bi-arrow-right-short"></i></a></td>',
            '  <td style="font-size:.855rem;font-weight:600">' + cat.ordem + '</td>',
            '  <td><span class="hf-badge ' + badgeClass + '">' + badgeLabel + '</span></td>',
            '  <td class="text-end">',
            '    <button type="button" class="hf-btn hf-btn-ghost hf-btn-xs js-editar-cat" data-id="' + cat.id + '" title="Editar"><i class="bi bi-pencil"></i></button>',
            '    <button type="button" class="hf-btn hf-btn-danger hf-btn-xs js-excluir-cat" data-id="' + cat.id + '" data-nome="' + $('<div>').text(cat.nome).html() + '" data-tem-perguntas="0" title="Excluir"><i class="bi bi-trash"></i></button>',
            '  </td>',
            '</tr>',
        ].join(''));
 
        $('#tbodyCategorias').append($tr);
    }
 
    function atualizarLinhaTabela(cat) {
        var $tr = $('tr[data-cat-id="' + cat.id + '"]');
        if (!$tr.length) return;
 
        var badgeClass = cat.ativo ? 'hf-badge-active' : 'hf-badge-inactive';
        var badgeLabel = cat.ativo ? 'Ativo' : 'Inativo';
        var descHtml   = cat.descricao
            ? '<div class="hf-td-name-sub">' + $('<div>').text(cat.descricao).html() + '</div>'
            : '';
 
        $tr.find('td:nth-child(2) .hf-icon-cell i').attr('class', 'bi ' + cat.icone);
        $tr.find('td:nth-child(3)').html(
            '<div class="hf-td-name-main">' + $('<div>').text(cat.nome).html() + '</div>' +
            descHtml +
            '<span class="hf-td-slug">' + $('<div>').text(cat.slug).html() + '</span>'
        );
        $tr.find('td:nth-child(5)').text(cat.ordem);
        $tr.find('.hf-badge').attr('class', 'hf-badge ' + badgeClass).text(badgeLabel);
        $tr.find('.js-excluir-cat').data('nome', cat.nome);
 
        // Highlight visual
        $tr.addClass('hf-row-updated');
        setTimeout(function () { $tr.removeClass('hf-row-updated'); }, 1400);
    }
 
    // ─── Drawer: nova / editar categoria ──────────────────────────────────
 
    function abrirDrawerCategoria(catId) {
        console.log(catId);
        
        var drawer = adminDrawer({
            titulo:  catId ? 'Editar categoria' : 'Nova categoria',
            tamanho: 'md',
        });
 
        drawer.setConteudo('<div class="hf-drawer-loading"><i class="bi bi-arrow-repeat hf-spin"></i> Carregando…</div>');
 
        var url = catId
            ? BASE_URL + '/admin/help-faq/categoria/form?id=' + catId
            : BASE_URL + '/admin/help-faq/categoria/form';
 
        CK.get(url).done(function (res) {
            if (!res.ok) {
                drawer.setConteudo('<div class="hf-drawer-erro"><i class="bi bi-exclamation-triangle"></i> ' + (res.msg || 'Erro ao carregar.') + '</div>');
                return;
            }
            drawer.setConteudo(res.html);
            iniciarFormCategoria(drawer, catId);
        }).fail(function () {
            drawer.setConteudo('<div class="hf-drawer-erro"><i class="bi bi-wifi-off"></i> Erro de conexão.</div>');
        });
    }
 
    function iniciarFormCategoria(drawer, catId) {
        var $body = $(drawer.body());
 
        // Seletor de ícone
        $body.on('click', '.hf-icon-opt', function () {
            var cls = $(this).data('icone');
            $body.find('.hf-icon-opt').removeClass('is-selected');
            $(this).addClass('is-selected');
            $body.find('#drawerInputIcone').val(cls);
            $body.find('#drawerIconePreviewEl').attr('class', 'bi ' + cls);
            $body.find('#drawerIconeManual').val(cls);
        });
 
        $body.on('input', '#drawerIconeManual', function () {
            var v = $(this).val().trim();
            if (v.indexOf('bi-') === 0) {
                $body.find('.hf-icon-opt').removeClass('is-selected');
                $body.find('.hf-icon-opt[data-icone="' + v + '"]').addClass('is-selected');
                $body.find('#drawerInputIcone').val(v);
                $body.find('#drawerIconePreviewEl').attr('class', 'bi ' + v);
            }
        });
 
        // Submit
        $body.on('submit', '#formCategoriaDrawer', function (e) {
            e.preventDefault();
            var $btn = $body.find('.js-btn-salvar-cat');
            CK.btnLoading($btn);
 
            var data = {
                nome:      $body.find('[name="nome"]').val(),
                descricao: $body.find('[name="descricao"]').val(),
                icone:     $body.find('[name="icone"]').val(),
                ordem:     $body.find('[name="ordem"]').val(),
                ativo:     $body.find('[name="ativo"]').is(':checked') ? 1 : 0,
            };
            if (catId) data.id = catId;
 
            CK.post('/admin/help-faq/categoria/salvar', data)
                .done(function (res) {
                    CK.btnLoading($btn, false);
                    if (!res.ok) {
                        showToast(res.msg || 'Erro ao salvar.', 'error');
                        return;
                    }
                    showToast(res.msg || 'Categoria salva!', 'success');
                    drawer.close();
                    if (catId) {
                        atualizarLinhaTabela(res.categoria);
                    } else {
                        adicionarLinhaTabela(res.categoria);
                    }
                })
                .fail(function () {
                    CK.btnLoading($btn, false);
                    showToast('Erro de conexão.', 'error');
                });
        });
    }
 
    // ─── Drawer: confirmar exclusão ────────────────────────────────────────
 
    function abrirDrawerExcluirCat(id, nome, temPerguntas) {
        if (temPerguntas === '1') {
            showToast('Remova as perguntas desta categoria antes de excluí-la.', 'warning');
            return;
        }
 
        var drawer = adminDrawer({
            titulo:  'Excluir categoria',
            tamanho: 'sm',
        });
 
        drawer.setConteudo([
            '<div class="hf-drawer-confirm">',
            '  <div class="hf-drawer-confirm-icon danger">',
            '    <i class="bi bi-trash3"></i>',
            '  </div>',
            '  <p class="hf-drawer-confirm-msg">',
            '    Tem certeza que deseja excluir a categoria<br>',
            '    <strong>' + $('<div>').text(nome).html() + '</strong>?',
            '  </p>',
            '  <p class="hf-drawer-confirm-sub">Esta ação não pode ser desfeita.</p>',
            '  <div class="hf-drawer-confirm-actions">',
            '    <button type="button" class="hf-btn hf-btn-secondary js-cancelar-excluir">Cancelar</button>',
            '    <button type="button" class="hf-btn hf-btn-danger js-confirmar-excluir-cat" data-id="' + id + '">',
            '      <i class="bi bi-trash"></i> Excluir',
            '    </button>',
            '  </div>',
            '</div>',
        ].join(''));
 
        $(drawer.body()).on('click', '.js-cancelar-excluir', function () {
            drawer.close();
        });
 
        $(drawer.body()).on('click', '.js-confirmar-excluir-cat', function () {
            var $btn = $(this);
            CK.btnLoading($btn);
 
            CK.post('/admin/help-faq/categoria/excluir', { id: id })
                .done(function (res) {
                    CK.btnLoading($btn, false);
                    if (!res.ok) {
                        showToast(res.msg || 'Erro ao excluir.', 'error');
                        drawer.close();
                        return;
                    }
                    showToast('Categoria excluída.', 'success');
                    drawer.close();
                    var $tr = $('tr[data-cat-id="' + id + '"]');
                    $tr.addClass('hf-row-removing');
                    setTimeout(function () {
                        $tr.remove();
                        if ($('#tbodyCategorias tr').length === 0) {
                            $('#tbodyCategorias').html(
                                '<tr id="trVazio"><td colspan="7">' +
                                '<div class="hf-empty"><i class="bi bi-inbox"></i>' +
                                '<p>Nenhuma categoria cadastrada.</p>' +
                                '<button type="button" class="hf-btn hf-btn-primary hf-btn-sm" id="btnNovaCategoriaEmpty"><i class="bi bi-plus-lg"></i> Criar primeira</button>' +
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
 
    $(document).on('click', '#btnNovaCategoria, #btnNovaCategoriaEmpty', function (e) {
        e.preventDefault();
        abrirDrawerCategoria(null);
        return false;
    });
 
    $(document).on('click', '.js-editar-cat', function (e) {
        e.preventDefault();
        abrirDrawerCategoria($(this).attr('data-id'));
        return false;
    });
 
    $(document).on('click', '.js-excluir-cat', function (e) {
        e.preventDefault();
        abrirDrawerExcluirCat(
            $(this).data('id'),
            $(this).data('nome'),
            $(this).data('tem-perguntas')
        );
        return false;
    });
 
    // Auto-dismiss flash
    setTimeout(function () { $('.hf-flash-auto').fadeOut(400); }, 5000);
    $(document).on('click', '.hf-flash-close', function (e) {
        e.preventDefault();
        $(this).closest('.hf-flash').fadeOut(300);
    });
});
</script>
