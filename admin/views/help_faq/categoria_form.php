<?php
/**
 * View: app/views/admin/help_faq/categoria_form.php
 * $categoria — array ou null
 * $modo      — 'criar' | 'editar'
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
$modo = $modo ?? null;
$editando = $modo === 'editar' && $categoria;
$titulo   = $editando ? 'Editar categoria' : 'Nova categoria';

// Mantém compatibilidade com registros antigos salvos como "bi-*".
$iconesDisponiveis = [
    'bi-truck'            => 'Entrega',
    'bi-credit-card'      => 'Pagamento',
    'bi-arrow-left-right' => 'Troca',
    'bi-gear'             => 'Peças',
    'bi-person-circle'    => 'Conta',
    'bi-shield-check'     => 'Garantia',
    'bi-question-circle'  => 'Dúvidas',
    'bi-box-seam'         => 'Produto',
    'bi-bag-check'        => 'Compra',
    'bi-telephone'        => 'Contato',
    'bi-chat-dots'        => 'Chat',
    'bi-star'             => 'Avaliação',
    'bi-lock'             => 'Segurança',
    'bi-map'              => 'Endereço',
    'bi-wrench'           => 'Técnico',
    'bi-file-text'        => 'Documentos',
];

$iconeAtual = $editando ? ($categoria['icone'] ?? 'bi-question-circle') : 'bi-question-circle';
?>

<div class="container-fluid py-4 hf-admin-page hf-admin-page--form">

    <section class="hf-form-shell">
        <div class="hf-form-header">
            <a href="/admin/help-faq" class="hf-back-btn" aria-label="Voltar">
                <?= IconLibrary::render('arrow-back'); ?>
            </a>

            <div>
                <span class="hf-eyebrow">Categorias da central</span>
                <h1 class="hf-page-title"><?= $titulo ?></h1>
                <p class="hf-page-subtitle">Defina nome, descrição, ícone e visibilidade da categoria.</p>
            </div>
        </div>

        <form method="POST" action="/admin/help-faq/categoria/salvar" class="hf-form-card" novalidate>
            <?= SecurityHelper::csrfField() ?>

            <?php if ($editando): ?>
                <input type="hidden" name="id" value="<?= (int)$categoria['id'] ?>">
            <?php endif; ?>

            <div class="hf-form-section">
                <div class="hf-section-title">
                    <span><?= IconLibrary::render('help'); ?></span>
                    <div>
                        <strong>Informações principais</strong>
                        <small>Conteúdo exibido para o cliente na central de ajuda.</small>
                    </div>
                </div>

                <div class="hf-field">
                    <label class="hf-label" for="catNome">
                        Nome <span>*</span>
                    </label>
                    <input
                        type="text"
                        name="nome"
                        id="catNome"
                        class="form-control hf-input"
                        value="<?= htmlspecialchars($editando ? $categoria['nome'] : '') ?>"
                        placeholder="Ex: Pedidos e Entregas"
                        required
                        maxlength="120"
                        autofocus
                    >
                </div>

                <div class="hf-field">
                    <label class="hf-label" for="catDesc">Descrição curta</label>
                    <input
                        type="text"
                        name="descricao"
                        id="catDesc"
                        class="form-control hf-input"
                        value="<?= htmlspecialchars($editando ? ($categoria['descricao'] ?? '') : '') ?>"
                        placeholder="Exibida abaixo do título da categoria"
                        maxlength="255"
                    >
                </div>
            </div>

            <div class="hf-form-section">
                <div class="hf-section-title">
                    <span><?= IconLibrary::render('backlight'); ?></span>
                    <div>
                        <strong>Identidade visual</strong>
                        <small>Selecione um ícone administrado pela sua biblioteca SVG.</small>
                    </div>
                </div>

                <div class="hf-field">
                    <label class="hf-label">Ícone</label>
                    <input type="hidden" name="icone" id="inputIcone" value="<?= htmlspecialchars($iconeAtual) ?>">

                    <div class="hf-icon-picker" id="iconeGrid">
                        <?php foreach ($icones as $cls => $label): ?>
                            <button
                                type="button"
                                class="hf-icon-btn <?= $cls === $iconeAtual ? 'is-selected' : '' ?>"
                                data-icone="<?= htmlspecialchars($cls) ?>"
                                title="<?= htmlspecialchars($label) ?>"
                                aria-label="<?= htmlspecialchars($label) ?>"
                            >
                                <span class="hf-icon-svg">
                                    <?= IconLibrary::render(hf_icon_name($cls)); ?>
                                </span>
                                <small><?= htmlspecialchars($label) ?></small>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <div class="hf-icon-manual">
                        <span class="hf-icon-preview" id="iconePreviewIcon">
                            <?= IconLibrary::render(hf_icon_name($iconeAtual)); ?>
                        </span>
                        <input
                            type="text"
                            id="iconeManual"
                            class="form-control hf-input"
                            value="<?= htmlspecialchars($iconeAtual) ?>"
                            placeholder="truck, credit-card ou bi-truck"
                        >
                    </div>

                    <div class="hf-hint">
                        Você pode informar manualmente o nome do SVG. Valores antigos com <code>bi-</code> continuam compatíveis.
                    </div>
                </div>
            </div>

            <div class="hf-form-section">
                <div class="hf-section-title">
                    <span><?= IconLibrary::render('vertical-distribute'); ?></span>
                    <div>
                        <strong>Configurações</strong>
                        <small>Controle ordem de exibição e disponibilidade no site.</small>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-sm-4">
                        <div class="hf-field">
                            <label class="hf-label" for="catOrdem">Ordem</label>
                            <input
                                type="number"
                                name="ordem"
                                id="catOrdem"
                                class="form-control hf-input"
                                value="<?= $editando ? (int)$categoria['ordem'] : 0 ?>"
                                min="0"
                                max="255"
                            >
                            <div class="hf-hint">Menor número = aparece primeiro.</div>
                        </div>
                    </div>

                    <div class="col-sm-8">
                        <div class="hf-switch-card">
                            <div>
                                <strong>Categoria ativa</strong>
                                <small>Disponibilizar esta categoria para os clientes.</small>
                            </div>
                            <div class="form-check form-switch">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    name="ativo"
                                    id="chkAtivo"
                                    value="1"
                                    <?= (!$editando || !empty($categoria['ativo'])) ? 'checked' : '' ?>
                                >
                                <label class="form-check-label" for="chkAtivo">Ativa</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="hf-form-actions">
                <button type="submit" class="hf-btn hf-btn--primary">
                    <span class="hf-btn-icon"><?= IconLibrary::render('check'); ?></span>
                    <?= $editando ? 'Salvar alterações' : 'Criar categoria' ?>
                </button>
                <a href="/admin/help-faq" class="hf-btn hf-btn--secondary">Cancelar</a>
            </div>
        </form>
    </section>
</div>

<script>
$(function () {
    function setIcone(cls, svgHtml) {
        $('#inputIcone, #iconeManual').val(cls);
        $('.hf-icon-btn').removeClass('is-selected');
        $('.hf-icon-btn[data-icone="' + cls + '"]').addClass('is-selected');

        if (svgHtml) {
            $('#iconePreviewIcon').html(svgHtml);
        } else {
            $('#iconePreviewIcon').html('<span class="hf-icon-preview-text">' + cls.replace(/^bi-/, '') + '</span>');
        }
    }

    $(document).on('click', '.hf-icon-btn', function () {
        setIcone($(this).data('icone'), $(this).find('.hf-icon-svg').html());
    });

    $('#iconeManual').on('input', function () {
        var v = $(this).val().trim();

        if (v) {
            setIcone(v, null);
        }
    });
});
</script>
