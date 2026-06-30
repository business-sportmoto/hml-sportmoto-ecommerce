(function ($) {
    const $modal = $('#iconFinderModal');
    const $grid = $('#iconFinderGrid');
    const $search = $('#iconFinderSearch');

    let isEditing = false;

    let activeTarget = null;

    function openFinder(targetSelector) {
        console.log($modal);
        
        activeTarget = targetSelector || null;
        $modal.attr('aria-hidden', 'false').addClass('is-open');
        $search.val('').trigger('input').focus();
    }

    function closeFinder() {
        $modal.attr('aria-hidden', 'true').removeClass('is-open');
        activeTarget = null;
    }

    function updatePreview($input) {
        const value = $input.val();
        const $wrap = $input.closest('.icon-picker-inline');
        const $preview = $wrap.find('.js-icon-preview');
        const $item = $grid.find('.icon-finder-item[data-key="' + value + '"]').first();

        if (!$preview.length) return;

        if ($item.length) {
            $preview.html($item.find('.icon-finder-item-svg').html());
        } else {
            $preview.html('');
        }
    }

    $(document).on('click', '.js-open-icon-finder', function () {
        const target = $(this).data('target') || null;
        openFinder(target);
    });

    $(document).on('click', '.js-close-icon-finder', function () {
        closeFinder();
    });

    $(document).on('keydown', function (e) {
        if (e.key === 'Escape' && $modal.hasClass('is-open')) {
            closeFinder();
        }
    });

    $search.on('input', function () {
        const term = ($(this).val() || '').toLowerCase().trim();

        $grid.find('.icon-finder-item').each(function () {
            const $item = $(this);
            const text = [
                $item.data('key') || '',
                $item.data('label') || '',
                $item.data('tags') || ''
            ].join(' ').toLowerCase();

            $item.toggle(text.indexOf(term) !== -1);
        });
    });

    $(document).on('click', '.icon-finder-item', function () {
        const $item = $(this);
        const key = $item.data('key');
        const svg = $item.attr('data-svg') || '';

        if (activeTarget) {
            const $input = $(activeTarget);
            if ($input.length) {
                $input.val(key).trigger('change');
                updatePreview($input);
            }
        }

        if (navigator.clipboard && key) {
            navigator.clipboard.writeText(key).catch(function () {});
        }

        closeFinder();
    });

    $(document).on('change', '.js-icon-select', function () {
        updatePreview($(this));
    });

    $(function () {
        $('.js-icon-select').each(function () {
            updatePreview($(this));
        });
    });

    let $activeBlocoIcon = null;

    $(document).on('click', '.btn-open-icon-finder', function () {
        $activeBlocoIcon = $(this).closest('.bloco-icon');

        $('#iconFinderModal')
            .attr('aria-hidden', 'false')
            .addClass('is-open');

        $('#iconFinderSearch')
            .val('')
            .trigger('input')
            .focus();
    });

    $(document).on('click', '.js-close-icon-finder', function () {
        closeIconFinder();
    });

    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') {
            closeIconFinder();
        }
    });

    $(document).on('input', '#iconFinderSearch', function () {
        const term = ($(this).val() || '').toLowerCase().trim();

        $('#iconFinderGrid .icon-finder-item').each(function () {
            const $item = $(this);

            const searchable = [
                $item.data('key') || '',
                $item.data('label') || '',
                $item.data('tags') || ''
            ].join(' ').toLowerCase();

            $item.toggle(searchable.indexOf(term) !== -1);
        });
    });

    $(document).on('click', '.icon-finder-item', function () {
        if (!$activeBlocoIcon || !$activeBlocoIcon.length) {
            closeIconFinder();
            return;
        }

        const $item = $(this);
        const iconKey = $item.data('key');
        const iconSvg = $item.find('.icon-finder-item-svg').html();

        $activeBlocoIcon.find('.preview-icon').html(iconSvg);

        const $input = $activeBlocoIcon.find('.input-icon-key');

        if ($input.length) {
            $input.val(iconKey).trigger('change');
        }

        closeIconFinder();
    });

    function closeIconFinder() {
        $('#iconFinderModal')
            .attr('aria-hidden', 'true')
            .removeClass('is-open');

        $activeBlocoIcon = null;
    }

    $(document).on('click', '.btn-add-icon-toggle', function () {
        $('.add-icon-box').slideToggle(180);
    });

    $(document).on('click', '#btnSaveNewIcon', function () {
        const $btn = $(this);
        const payload = {
            key: $('#newIconKey').val(),
            label: $('#newIconLabel').val(),
            tags: $('#newIconTags').val(),
            svg: $('#newIconSvg').val()
        };

        $btn.prop('disabled', true).text('Salvando...');
        $('#newIconFeedback').text('');

        const url = isEditing
        ? BASE_URL + '/admin/icons/update'
        : BASE_URL + '/admin/icons/salvar';

        $.ajax({
            url: url,
            type: 'POST',
            dataType: 'json',
            data: payload,
            success: function (res) {
                
                $btn.prop('disabled', false).text('Salvar ícone');

                if (!res.ok) {
                    $('#newIconFeedback').text(res.msg || 'Erro ao salvar.');
                    return;
                }

                $('.add-icon-box').slideToggle(180);

                $('#newIconFeedback').text('Ícone salvo com sucesso.');

                if(isEditing){
                    $('#iconFinderGrid').find('.icon-finder-item[data-key="' + payload.key + '"]').remove()
                }
                // adiciona no grid sem recarregar a página
                let addEl = isEditing ? 'prepend' : 'append';
                $('#iconFinderGrid')[addEl](`
                    <button
                        type="button"
                        class="icon-finder-item"
                        data-key="${res.icon.key}"
                        data-label="${res.icon.label}"
                        data-tags="${res.icon.tags.join(' ')}"
                    >
                        <span class="icon-finder-item-svg">${res.icon.svg}</span>
                        <span class="icon-finder-item-label">${res.icon.label}</span>
                        <span class="icon-finder-item-key">${res.icon.key}</span>
                    </button>
                `);

                isEditing = false;

                $('#newIconKey, #newIconLabel, #newIconTags, #newIconSvg').val('');

                
            },
            error: function () {
                $btn.prop('disabled', false).text('Salvar ícone');
                $('#newIconFeedback').text('Erro de conexão.');
            }
        });
    });


    let currentIcon = null;

    // abrir menu
    $(document).on('contextmenu', '.icon-finder-item', function (e) {
        e.preventDefault();

        currentIcon = {
            key: $(this).data('key'),
            svg: $(this).data('svg'),
            label: $(this).data('label'),
            tags: $(this).data('tags')
        };

        $('#iconContextMenu')
            .css({
                top: e.pageY + 'px',
                left: e.pageX + 'px'
            })
            .show();
    });

    // fechar ao clicar fora
    $(document).on('click', function () {
        $('#iconContextMenu').hide();
    });

    // ações
    $(document).on('click', '#iconContextMenu button', function () {

        const action = $(this).data('action');

        if (!currentIcon) return;

        if (action === 'copy') {
            navigator.clipboard.writeText(currentIcon.key);
        }

        if (action === 'edit') {

             isEditing = true;

            $('.add-icon-box').show();

            $('#newIconKey').val(currentIcon.key);
            $('#newIconLabel').val(currentIcon.label);
            $('#newIconTags').val(currentIcon.tags);
            $('#newIconSvg').val(currentIcon.svg);

        }

        if (action === 'delete') {
            if (!confirm('Excluir este ícone?')) return;

            $.post(BASE_URL + '/admin/icons/delete', {
                key: currentIcon.key
            }, function (res) {

                if (res.ok) {
                    $('.icon-finder-item[data-key="' + currentIcon.key + '"]').remove();
                }

            }, 'json');
        }

        $('#iconContextMenu').hide();
    });

    window.IconFinder = {
        open: openFinder,
        close: closeFinder
    };


})(jQuery);