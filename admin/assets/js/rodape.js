/* =====================================================================
   Editor do rodapé da loja (jQuery v4).

   As listas do rodapé são compostas — colunas com links dentro, benefícios com
   ícone e link opcional. Montar isso com name="colunas[0][links][2][url]" faz a
   ordem depender de o PHP remontar índices esparsos depois de cada remoção, e
   basta apagar a coluna do meio para o resultado sair trocado.

   Aqui a lista vive em memória, o DOM é redesenhado a partir dela, e no salvar
   ela vira JSON num campo escondido. Uma direção só: estado → tela.
   ===================================================================== */
(function ($) {
    'use strict';

    var $form = $('#rodForm');
    if (!$form.length || !window.ROD) return;

    var dados = window.ROD.dados;

    function esc(s) { return $('<i>').text(s == null ? '' : String(s)).html(); }

    function aviso(msg, tipo) {
        if (window.adminToast) { adminToast(msg, tipo || 'info'); return; }
        if (window.Toast && Toast.show) { Toast.show({ message: msg, type: tipo || 'info' }); return; }
        alert(msg);
    }

    /* ── ícones ─────────────────────────────────────────── */
    function selectIcone(valor) {
        var h = '<select class="form-control rod_ico_sel" data-campo="icone">';
        $.each(window.ROD.icones, function (chave, rotulo) {
            h += '<option value="' + esc(chave) + '"' +
                 (chave === valor ? ' selected' : '') + '>' + esc(rotulo) + '</option>';
        });
        return h + '</select>';
    }

    function previaIcone(chave) {
        return '<span class="rod_ico_previa">' + (window.ROD.iconesSvg[chave] || '') + '</span>';
    }

    /* ── listas simples de objetos ──────────────────────── */

    // campos: [{ nome, rotulo, tipo }] — tipo 'icone' vira o select do catálogo.
    var ESQUEMAS = {
        beneficios: {
            alvo: '#rodBeneficios',
            campos: [
                { nome: 'icone',      rotulo: 'Ícone',          tipo: 'icone' },
                { nome: 'titulo',     rotulo: 'Título',         tipo: 'texto' },
                { nome: 'texto',      rotulo: 'Descrição',      tipo: 'texto' },
                { nome: 'link_texto', rotulo: 'Texto do link',  tipo: 'texto' },
                { nome: 'link_url',   rotulo: 'URL do link',    tipo: 'texto' }
            ]
        },
        selos: {
            alvo: '#rodSelos',
            campos: [
                { nome: 'icone',  rotulo: 'Ícone',     tipo: 'icone' },
                { nome: 'titulo', rotulo: 'Título',    tipo: 'texto' },
                { nome: 'texto',  rotulo: 'Descrição', tipo: 'texto' }
            ]
        },
        legais: {
            alvo: '#rodLegais',
            campos: [
                { nome: 'label', rotulo: 'Rótulo', tipo: 'texto' },
                { nome: 'url',   rotulo: 'URL',    tipo: 'texto' }
            ]
        }
    };

    function pintarLista(chave) {
        var esq = ESQUEMAS[chave];
        var h   = '';

        (dados[chave] || []).forEach(function (item, i) {
            h += '<div class="rod_linha" data-lista="' + chave + '" data-i="' + i + '">';
            h += '<span class="rod_linha_num">' + (i + 1) + '</span>';
            if (esq.campos[0].tipo === 'icone') h += previaIcone(item.icone);
            h += '<div class="rod_linha_campos">';

            esq.campos.forEach(function (c) {
                h += '<label class="rod_campo">';
                h += '<span>' + esc(c.rotulo) + '</span>';
                h += c.tipo === 'icone'
                    ? selectIcone(item[c.nome])
                    : '<input type="text" class="form-control" data-campo="' + c.nome +
                      '" value="' + esc(item[c.nome] || '') + '">';
                h += '</label>';
            });

            h += '</div>';
            h += '<button type="button" class="btn-icon btn-icon--danger js-rod-del" ' +
                 'title="Remover" aria-label="Remover item ' + (i + 1) + '">&times;</button>';
            h += '</div>';
        });

        if (!h) h = '<p class="rod_vazio">Nenhum item. Use “Adicionar”.</p>';
        $(esq.alvo).html(h);
    }

    /* ── colunas de links (lista dentro de lista) ───────── */
    function pintarColunas() {
        var h = '';

        (dados.colunas || []).forEach(function (col, ci) {
            h += '<div class="rod_coluna" data-i="' + ci + '">';
            h += '<div class="rod_coluna_topo">';
            h += '<input type="text" class="form-control rod_coluna_titulo" data-campo="titulo" ' +
                 'placeholder="Título da coluna" value="' + esc(col.titulo || '') + '">';
            h += '<button type="button" class="btn btn-secondary btn-sm js-rod-add-link">+ link</button>';
            h += '<button type="button" class="btn-icon btn-icon--danger js-rod-del-coluna" ' +
                 'title="Remover coluna" aria-label="Remover coluna ' + (ci + 1) + '">&times;</button>';
            h += '</div>';

            // Coluna automática: a loja preenche com as páginas de /pages, então
            // não há link para editar aqui — mostrar campos vazios sugeriria que
            // digitar neles muda alguma coisa.
            var auto = col.auto === 'paginas';
            h += '<label class="toggle-field rod_coluna_auto">' +
                 '<input type="checkbox" class="js-rod-auto"' + (auto ? ' checked' : '') + '>' +
                 '<span class="toggle-slider"></span>' +
                 '<span>Preencher com as páginas do site</span></label>';

            if (auto) {
                h += '<p class="rod_vazio">Os links vêm das páginas publicadas em ' +
                     '<strong>Páginas</strong>, na ordem definida lá.</p>';
                h += '</div>';
                return;
            }

            h += '<div class="rod_coluna_links">';
            (col.links || []).forEach(function (lk, li) {
                h += '<div class="rod_link" data-i="' + li + '">';
                h += '<input type="text" class="form-control" data-campo="label" ' +
                     'placeholder="Rótulo" value="' + esc(lk.label || '') + '">';
                h += '<input type="text" class="form-control" data-campo="url" ' +
                     'placeholder="/caminho ou https://" value="' + esc(lk.url || '') + '">';
                h += '<button type="button" class="btn-icon btn-icon--danger js-rod-del-link" ' +
                     'title="Remover link" aria-label="Remover link ' + (li + 1) + '">&times;</button>';
                h += '</div>';
            });
            if (!(col.links || []).length) {
                h += '<p class="rod_vazio">Coluna sem links.</p>';
            }
            h += '</div></div>';
        });

        if (!h) h = '<p class="rod_vazio">Nenhuma coluna. Use “Nova coluna”.</p>';
        $('#rodColunas').html(h);
    }

    /* ── listas de texto (chips) ────────────────────────── */
    function pintarTags(chave, seletor) {
        var $alvo = $(seletor);
        var h = '';

        (dados[chave] || []).forEach(function (t, i) {
            h += '<span class="rod_tag" data-i="' + i + '">' + esc(t) +
                 '<button type="button" class="js-rod-del-tag" aria-label="Remover ' +
                 esc(t) + '">&times;</button></span>';
        });

        h += '<input type="text" class="rod_tag_input js-rod-tag-novo" ' +
             'placeholder="' + esc($alvo.data('placeholder') || 'Adicionar') + '">';
        $alvo.html(h);
    }

    /* ── redesenho geral ────────────────────────────────── */
    function pintarTudo() {
        pintarLista('beneficios');
        pintarLista('selos');
        pintarLista('legais');
        pintarColunas();
        pintarTags('buscas', '#rodBuscas');
        pintarTags('logistica', '#rodLogistica');
    }

    /* ── edição: o campo escreve direto no estado ───────── */
    $form.on('input change', '.rod_linha [data-campo]', function () {
        var $l = $(this).closest('.rod_linha');
        var item = dados[$l.data('lista')][$l.data('i')];
        item[$(this).data('campo')] = $(this).val();

        // O select de ícone atualiza a prévia sem redesenhar a linha inteira —
        // redesenhar aqui tiraria o foco do campo que está sendo digitado.
        if ($(this).data('campo') === 'icone') {
            $l.find('.rod_ico_previa').html(window.ROD.iconesSvg[$(this).val()] || '');
        }
    });

    $form.on('input', '.rod_coluna_titulo', function () {
        dados.colunas[$(this).closest('.rod_coluna').data('i')].titulo = $(this).val();
    });

    $form.on('input', '.rod_link [data-campo]', function () {
        var ci = $(this).closest('.rod_coluna').data('i');
        var li = $(this).closest('.rod_link').data('i');
        dados.colunas[ci].links[li][$(this).data('campo')] = $(this).val();
    });

    /* ── adicionar / remover ────────────────────────────── */
    $('.js-rod-add').on('click', function () {
        var chave = $(this).data('lista');

        if (chave === 'colunas') {
            dados.colunas.push({ titulo: '', links: [{ label: '', url: '' }] });
            pintarColunas();
            $('#rodColunas .rod_coluna').last().find('.rod_coluna_titulo').trigger('focus');
            return;
        }

        var novo = {};
        ESQUEMAS[chave].campos.forEach(function (c) {
            novo[c.nome] = c.tipo === 'icone' ? Object.keys(window.ROD.icones)[0] : '';
        });
        dados[chave].push(novo);
        pintarLista(chave);
        $(ESQUEMAS[chave].alvo).find('.rod_linha').last()
            .find('input[type=text]').first().trigger('focus');
    });

    $form.on('click', '.js-rod-del', function () {
        var $l = $(this).closest('.rod_linha');
        var chave = $l.data('lista');
        dados[chave].splice($l.data('i'), 1);
        pintarLista(chave);
    });

    $form.on('click', '.js-rod-del-coluna', function () {
        dados.colunas.splice($(this).closest('.rod_coluna').data('i'), 1);
        pintarColunas();
    });

    $form.on('change', '.js-rod-auto', function () {
        var col = dados.colunas[$(this).closest('.rod_coluna').data('i')];
        if (this.checked) {
            col.auto = 'paginas';
        } else {
            delete col.auto;
            // Sai do automático sem link nenhum: uma linha em branco evita que a
            // coluna pareça quebrada até o primeiro "+ link".
            if (!(col.links || []).length) col.links = [{ label: '', url: '' }];
        }
        pintarColunas();
    });

    $form.on('click', '.js-rod-add-link', function () {
        var ci = $(this).closest('.rod_coluna').data('i');
        dados.colunas[ci].links.push({ label: '', url: '' });
        pintarColunas();
        $('#rodColunas .rod_coluna').eq(ci).find('.rod_link').last()
            .find('input').first().trigger('focus');
    });

    $form.on('click', '.js-rod-del-link', function () {
        var ci = $(this).closest('.rod_coluna').data('i');
        dados.colunas[ci].links.splice($(this).closest('.rod_link').data('i'), 1);
        pintarColunas();
    });

    /* ── chips ──────────────────────────────────────────── */
    function chaveDaTag($el) {
        return $el.closest('#rodBuscas').length ? 'buscas' : 'logistica';
    }

    $form.on('keydown', '.js-rod-tag-novo', function (ev) {
        if (ev.key !== 'Enter' && ev.key !== ',') return;
        ev.preventDefault();

        var v = String($(this).val() || '').trim();
        if (!v) return;

        var chave = chaveDaTag($(this));
        if (dados[chave].indexOf(v) !== -1) { aviso('“' + v + '” já está na lista.', 'warning'); return; }

        dados[chave].push(v);
        pintarTags(chave, chave === 'buscas' ? '#rodBuscas' : '#rodLogistica');
        $(chave === 'buscas' ? '#rodBuscas' : '#rodLogistica').find('.js-rod-tag-novo').trigger('focus');
    });

    $form.on('click', '.js-rod-del-tag', function () {
        var chave = chaveDaTag($(this));
        dados[chave].splice($(this).closest('.rod_tag').data('i'), 1);
        pintarTags(chave, chave === 'buscas' ? '#rodBuscas' : '#rodLogistica');
    });

    /* ── salvar ─────────────────────────────────────────── */
    function serializar() {
        $('#rodBeneficiosJson').val(JSON.stringify(dados.beneficios));
        $('#rodColunasJson').val(JSON.stringify(dados.colunas));
        $('#rodBuscasJson').val(JSON.stringify(dados.buscas));
        $('#rodLogisticaJson').val(JSON.stringify(dados.logistica));
        $('#rodSelosJson').val(JSON.stringify(dados.selos));
        $('#rodLegaisJson').val(JSON.stringify(dados.legais));

        $('#rodPagamentosJson').val(JSON.stringify(
            $('.js-rod-pag:checked').map(function () { return this.value; }).get()
        ));
    }

    $('#rodSalvar').on('click', function () {
        var $btn = $(this);
        serializar();

        // O checkbox desmarcado não vai no serialize(); sem isto, desligar a
        // newsletter não seria salvo — o campo simplesmente não chegaria.
        var payload = $form.serialize() +
            '&footer_newsletter_ativo=' + ($('#footer_newsletter_ativo').is(':checked') ? '1' : '0');

        if (window.CK && CK.btnLoading) CK.btnLoading($btn);
        $btn.prop('disabled', true);

        $.ajax({
            url: window.ROD.salvarUrl,
            method: 'POST',
            dataType: 'json',
            data: payload,
            headers: { 'X-CSRF-TOKEN': window.CSRF_TOKEN || '' }
        }).done(function (r) {
            aviso((r && r.msg) || 'Rodapé salvo.', r && r.ok ? 'success' : 'error');
        }).fail(function () {
            aviso('Falha de rede ao salvar.', 'error');
        }).always(function () {
            if (window.CK && CK.btnLoading) CK.btnLoading($btn, false);
            $btn.prop('disabled', false);
        });
    });

    pintarTudo();
})(jQuery);
