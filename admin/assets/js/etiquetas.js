/**
 * Etiquetas (jQuery v4). Depende de adminDrawer, Toast, window.CSRF_TOKEN.
 */
(function ($) {
    'use strict';

    var BASE = window.LOG_ETQ_BASE || '/admin/logistica/etiquetas';
    var TRANSP = window.LOG_ETQ_TRANSPORTADORAS || [];
    var pagina = 1, verCustos = false;

    var STATUS = {
        aguardando_postagem: ['is-warn', 'Aguardando'],
        emitida: ['is-ok', 'Emitida'],
        postada: ['is-info', 'Postada'],
        cancelada: ['is-neutral', 'Cancelada'],
        erro: ['is-danger', 'Erro']
    };

    /* ------------------------------------------------ util */
    function api(method, path, data) {
        return $.ajax({ url: BASE + path, method: method, dataType: 'json', data: data || {}, headers: { 'X-CSRF-TOKEN': window.CSRF_TOKEN || '' } });
    }
    function comCsrf(o) { o = o || {}; o.csrf_token = window.CSRF_TOKEN || ''; o._token = window.CSRF_TOKEN || ''; return o; }
    function esc(s) { return $('<i>').text(s == null ? '' : String(s)).html(); }
    function attr(s) { return esc(s).replace(/"/g, '&quot;'); }
    function moeda(v) { return 'R$ ' + (parseFloat(v) || 0).toFixed(2).replace('.', ','); }

    /* ------------------------------------------------ listagem */
    function carregar() {
        var f = {
            busca: $('#logEtqFiltros [name=busca]').val() || '',
            status: $('#logEtqFiltros [name=status]').val() || '',
            transportadora_id: $('#logEtqFiltros [name=transportadora_id]').val() || '',
            pagina: pagina
        };
        $('#logEtqBody').html('<tr><td colspan="6"><div class="log_state"><div class="log_spinner"></div></div></td></tr>');
        api('GET', '/dados', f).done(function (r) {
            if (!r || !r.ok) { $('#logEtqBody').html('<tr><td colspan="6"><div class="log_alert is-danger">Falha ao carregar.</div></td></tr>'); return; }
            verCustos = !!r.pode_ver_custos;
            render(r);
        }).fail(function () { $('#logEtqBody').html('<tr><td colspan="6"><div class="log_alert is-danger">Erro de comunicação.</div></td></tr>'); });
    }

    function render(r) {
        var $b = $('#logEtqBody').empty();
        if (!r.itens.length) {
            $b.html('<tr><td colspan="6"><div class="log_state"><div class="log_state_title">Nenhuma etiqueta</div><div class="log_state_desc">Emita a partir de um pedido ou crie manualmente.</div></div></td></tr>');
        } else {
            r.itens.forEach(function (it) { $b.append(linha(it)); });
        }
        pager(r);
        atualizarSelBar();
        $('#logEtqAll').prop('checked', false);
    }

    function linha(it) {
        var st = STATUS[it.status] || ['is-neutral', it.status];
        var acoes = it.acoes || [];
        var btns = '';
        if (acoes.indexOf('comprar') >= 0) btns += '<button type="button" class="log_btn log_btn--sm js-comprar"><span class="log_iw"><svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#e3e3e3"><path d="M444-200h70v-50q50-9 86-39t36-89q0-42-24-77t-96-61q-60-20-83-35t-23-41q0-26 18.5-41t53.5-15q32 0 50 15.5t26 38.5l64-26q-11-35-40.5-61T516-710v-50h-70v50q-50 11-78 44t-28 74q0 47 27.5 76t86.5 50q63 23 87.5 41t24.5 47q0 33-23.5 48.5T486-314q-33 0-58.5-20.5T390-396l-66 26q14 48 43.5 77.5T444-252v52Zm36 120q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Zm0-80q134 0 227-93t93-227q0-134-93-227t-227-93q-134 0-227 93t-93 227q0 134 93 227t227 93Zm0-320Z"/></svg></span> Comprar</button> ';
        if (it.url_pdf || acoes.indexOf('imprimir') >= 0) btns += '<button type="button" class="log_btn log_btn--icon js-imprimir" title="Imprimir PDF"><span class="log_iw"><svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#e3e3e3"><path d="M640-640v-120H320v120h-80v-200h480v200h-80Zm-480 80h640-640Zm560 100q17 0 28.5-11.5T760-500q0-17-11.5-28.5T720-540q-17 0-28.5 11.5T680-500q0 17 11.5 28.5T720-460Zm-80 260v-160H320v160h320Zm80 80H240v-160H80v-240q0-51 35-85.5t85-34.5h560q51 0 85.5 34.5T880-520v240H720v160Zm80-240v-160q0-17-11.5-28.5T760-560H200q-17 0-28.5 11.5T160-520v160h80v-80h480v80h80Z"/></svg></span></button> ';
        if (acoes.indexOf('cancelar') >= 0) btns += '<button type="button" class="log_btn log_btn--icon js-cancelar" title="Cancelar"><span class="log_iw"><svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#e3e3e3"><path d="m336-280 144-144 144 144 56-56-144-144 144-144-56-56-144 144-144-144-56 56 144 144-144 144 56 56ZM480-80q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Zm0-80q134 0 227-93t93-227q0-134-93-227t-227-93q-134 0-227 93t-93 227q0 134 93 227t227 93Zm0-320Z"/></svg></span></button> ';
        if (acoes.indexOf('remover') >= 0) btns += '<button type="button" class="log_btn log_btn--icon js-remover" title="Remover"><span class="log_iw"><svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#e3e3e3"><path d="M280-120q-33 0-56.5-23.5T200-200v-520h-40v-80h200v-40h240v40h200v80h-40v520q0 33-23.5 56.5T680-120H280Zm400-600H280v520h400v-520ZM360-280h80v-360h-80v360Zm160 0h80v-360h-80v360ZM280-720v520-520Z"/></svg></span></button> ';
        btns += '<button type="button" class="log_btn log_btn--icon js-detalhe" title="Detalhes"><span class="log_iw"><svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#e3e3e3"><path d="M360-200v-80h480v80H360Zm0-240v-80h480v80H360Zm0-240v-80h480v80H360ZM200-160q-33 0-56.5-23.5T120-240q0-33 23.5-56.5T200-320q33 0 56.5 23.5T280-240q0 33-23.5 56.5T200-160Zm0-240q-33 0-56.5-23.5T120-480q0-33 23.5-56.5T200-560q33 0 56.5 23.5T280-480q0 33-23.5 56.5T200-400Zm-56.5-263.5Q120-687 120-720t23.5-56.5Q167-800 200-800t56.5 23.5Q280-753 280-720t-23.5 56.5Q233-640 200-640t-56.5-23.5Z"/></svg></span></button>';

        var podeSelecionar = it.status === 'emitida' || it.status === 'aguardando_postagem';
        var custo = (verCustos && it.valor != null) ? '<span class="log_muted">' + moeda(it.valor) + '</span>' : '';

        return '<tr data-id="' + (it.id | 0) + '" data-status="' + esc(it.status) + '">' +
            '<td class="log_check_col">' + (podeSelecionar ? '<input type="checkbox" class="js-sel" data-status="' + esc(it.status) + '" data-tid="' + (it.transportadora_id | 0) + '">' : '') + '</td>' +
            '<td><span class="log_mono">' + (it.pedido_id ? '#' + it.pedido_id : '—') + '</span></td>' +
            '<td><div class="log_transp_info"><strong>' + esc(it.transportadora_nome || '—') + '</strong>' +
                '<span class="log_muted">' + esc(it.servico_nome || it.servico_codigo || '') + (custo ? ' · ' : '') + '</span>' + custo + '</div></td>' +
            '<td>' + (it.codigo_rastreio ? '<span class="log_mono">' + esc(it.codigo_rastreio) + '</span>' : '<span class="log_muted">—</span>') + '</td>' +
            '<td><span class="log_badge ' + st[0] + ' log_badge--plain">' + esc(st[1]) + '</span></td>' +
            '<td class="log_col_acoes">' + btns + '</td>' +
        '</tr>';
    }

    function pager(r) {
        var totalPag = Math.max(1, Math.ceil((r.total || 0) / (r.por_pagina || 30)));
        if (totalPag <= 1) { $('#logEtqPager').empty(); return; }
        var html = '<button type="button" class="log_btn log_btn--sm js-pg" data-pg="' + (pagina - 1) + '"' + (pagina <= 1 ? ' disabled' : '') + '>Anterior</button>' +
            '<span class="log_muted">Página ' + pagina + ' de ' + totalPag + '</span>' +
            '<button type="button" class="log_btn log_btn--sm js-pg" data-pg="' + (pagina + 1) + '"' + (pagina >= totalPag ? ' disabled' : '') + '>Próxima</button>';
        $('#logEtqPager').html(html);
    }

    /* ------------------------------------------------ seleção */
    function selecionadas() { return $('#logEtqBody .js-sel:checked'); }
    function atualizarSelBar() {
        var $s = selecionadas(), n = $s.length;
        $('#logEtqSelCount').text(n);
        $('#logEtqSelBar').toggle(n > 0);
    }

    /* ------------------------------------------------ ações individuais */
    function bindAcoes() {
        var $t = $('#logEtqTabela');

        $t.on('change', '.js-sel', atualizarSelBar);
        $('#logEtqAll').on('change', function () {
            $('#logEtqBody .js-sel').prop('checked', $(this).is(':checked'));
            atualizarSelBar();
        });

        $t.on('click', '.js-comprar', function () {
            var $tr = $(this).closest('tr'), $btn = $(this);
            $btn.prop('disabled', true);
            var tid = Toast.loading('Emitindo etiqueta...');
            api('POST', '/comprar', comCsrf({ id: $tr.data('id') })).done(function (r) {
                if (r && r.ok) { Toast.update(tid, { type: 'success', message: 'Etiqueta emitida.', duration: 2500 }); carregar(); }
                else { Toast.update(tid, { type: 'error', message: (r && r.erro) || 'Falha ao emitir.', duration: 4500 }); $btn.prop('disabled', false); }
            }).fail(function () { Toast.update(tid, { type: 'error', message: 'Erro de comunicação.', duration: 4000 }); $btn.prop('disabled', false); });
        });

        $t.on('click', '.js-imprimir', function () {
            var $tr = $(this).closest('tr');
            var tid = Toast.loading('Obtendo PDF...');
            api('POST', '/imprimir', comCsrf({ id: $tr.data('id') })).done(function (r) {
                if (r && r.ok && r.url_pdf) { Toast.dismiss(tid); window.open(r.url_pdf, '_blank'); }
                else Toast.update(tid, { type: 'error', message: (r && r.erro) || 'Sem PDF disponível.', duration: 4000 });
            }).fail(function () { Toast.update(tid, { type: 'error', message: 'Erro ao imprimir.', duration: 3500 }); });
        });

        $t.on('click', '.js-cancelar', function () {
            var $tr = $(this).closest('tr');
            confirmar('Cancelar etiqueta', 'Cancelar esta etiqueta na transportadora? A ação pode ser irreversível.', 'Cancelar etiqueta', function (d) {
                api('POST', '/cancelar', comCsrf({ id: $tr.data('id') })).done(function (r) {
                    if (r && r.ok) { Toast.success('Etiqueta cancelada.'); d.fechar(); carregar(); }
                    else Toast.error((r && r.erro) || 'Falha ao cancelar.');
                }).fail(function () { Toast.error('Erro.'); });
            });
        });

        $t.on('click', '.js-remover', function () {
            var $tr = $(this).closest('tr');
            confirmar('Remover etiqueta', 'Remover este registro de etiqueta? Só disponível para etiquetas com erro ou canceladas.', 'Remover', function (d) {
                api('POST', '/remover', comCsrf({ id: $tr.data('id') })).done(function (r) {
                    if (r && r.ok) { Toast.success('Removida.'); d.fechar(); carregar(); }
                    else Toast.error((r && r.erro) || 'Falha ao remover.');
                }).fail(function () { Toast.error('Erro.'); });
            });
        });

        $t.on('click', '.js-detalhe', function () { abrirDetalhe($(this).closest('tr').data('id')); });

        $('#logEtqPager').on('click', '.js-pg', function () { pagina = parseInt($(this).data('pg'), 10) || 1; carregar(); });

        // filtros
        var deb;
        $('#logEtqFiltros [name=busca]').on('input', function () { clearTimeout(deb); deb = setTimeout(function () { pagina = 1; carregar(); }, 350); });
        $('#logEtqFiltros [name=status], #logEtqFiltros [name=transportadora_id]').on('change', function () { pagina = 1; carregar(); });
    }

    /* ------------------------------------------------ lote / manifesto */
    function bindLote() {
        $('#logEtqSelBar').on('click', '.js-lote-comprar', function () {
            var ids = selecionadas().filter(function () { return $(this).data('status') === 'aguardando_postagem'; }).map(function () { return $(this).closest('tr').data('id'); }).get();
            if (!ids.length) { Toast.warning('Selecione etiquetas em "Aguardando".'); return; }
            var tid = Toast.loading('Emitindo ' + ids.length + ' etiqueta(s)...');
            api('POST', '/comprar-lote', comCsrf({ ids: ids })).done(function (r) {
                if (r) Toast.update(tid, { type: r.falhas ? 'warning' : 'success', message: r.compradas + ' emitida(s), ' + r.falhas + ' falha(s).', duration: 4000 });
                carregar();
            }).fail(function () { Toast.update(tid, { type: 'error', message: 'Erro no lote.', duration: 3500 }); });
        });

        $('#logEtqSelBar').on('click', '.js-manifesto', function () {
            var $sel = selecionadas().filter(function () { return $(this).data('status') === 'emitida'; });
            var ids = $sel.map(function () { return $(this).closest('tr').data('id'); }).get();
            var tids = {}; $sel.each(function () { tids[$(this).data('tid')] = 1; });
            if (!ids.length) { Toast.warning('Selecione etiquetas emitidas.'); return; }
            if (Object.keys(tids).length > 1) { Toast.warning('O manifesto deve ser de uma única transportadora.'); return; }
            var tid = Toast.loading('Gerando manifesto...');
            api('POST', '/manifesto', comCsrf({ ids: ids })).done(function (r) {
                if (r && r.ok) { Toast.update(tid, { type: 'success', message: 'Manifesto com ' + r.qtd + ' etiqueta(s).', duration: 3000 }); if (r.url_pdf) window.open(r.url_pdf, '_blank'); carregar(); }
                else Toast.update(tid, { type: 'error', message: (r && r.erro) || 'Falha no manifesto.', duration: 4000 });
            }).fail(function () { Toast.update(tid, { type: 'error', message: 'Erro.', duration: 3000 }); });
        });
    }

    /* ------------------------------------------------ detalhe */
    function abrirDetalhe(id) {
        var tid = Toast.loading('Abrindo...');
        api('GET', '/obter', { id: id }).done(function (r) {
            Toast.dismiss(tid);
            if (!r || !r.ok) { Toast.error('Etiqueta não encontrada.'); return; }
            var e = r.etiqueta, dest = e.destinatario_json || {}, st = STATUS[e.status] || ['is-neutral', e.status];
            var eventos = (r.eventos || []).map(function (ev) {
                return '<li><div class="log_tl_acao">' + esc(ev.acao) + '</div>' +
                    '<div class="log_tl_meta">' + esc(ev.criado_em) + '</div>' +
                    (ev.detalhe ? '<div class="log_tl_det">' + esc(ev.detalhe) + '</div>' : '') + '</li>';
            }).join('') || '<li><div class="log_tl_meta">Sem eventos.</div></li>';

            var html = '<div class="log_form">' +
                '<div class="log_fieldset"><h4>Situação</h4>' +
                    '<p><span class="log_badge ' + st[0] + ' log_badge--plain">' + esc(st[1]) + '</span> ' +
                    esc(e.transportadora_nome || '') + ' · ' + esc(e.servico_nome || e.servico_codigo || '') + '</p>' +
                    (e.codigo_rastreio ? '<p class="log_muted">Rastreio: <span class="log_mono">' + esc(e.codigo_rastreio) + '</span></p>' : '') +
                    (e.external_id ? '<p class="log_muted">ID externo: <span class="log_mono">' + esc(e.external_id) + '</span></p>' : '') +
                    (r.pode_ver_custos && e.valor != null ? '<p class="log_muted">Custo: ' + moeda(e.valor) + '</p>' : '') +
                '</div>' +
                '<div class="log_fieldset"><h4>Destinatário</h4>' +
                    '<p>' + esc(dest.nome || dest.name || '—') + '<br><span class="log_muted">' +
                    esc([dest.logradouro || dest.address, dest.numero || dest.number, dest.bairro || dest.district, (dest.cidade || dest.city), (dest.uf || dest.state_abbr)].filter(Boolean).join(', ')) + '</span></p>' +
                '</div>' +
                '<div class="log_fieldset"><h4>Histórico</h4><ul class="log_timeline">' + eventos + '</ul></div>' +
            '</div>';

            var acoesHtml = '';
            if (e.url_pdf) acoesHtml += '<a href="' + attr(e.url_pdf) + '" target="_blank" class="log_btn log_btn--sm"><span class="log_iw"><svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#e3e3e3"><path d="M640-640v-120H320v120h-80v-200h480v200h-80Zm-480 80h640-640Zm560 100q17 0 28.5-11.5T760-500q0-17-11.5-28.5T720-540q-17 0-28.5 11.5T680-500q0 17 11.5 28.5T720-460Zm-80 260v-160H320v160h320Zm80 80H240v-160H80v-240q0-51 35-85.5t85-34.5h560q51 0 85.5 34.5T880-520v240H720v160Zm80-240v-160q0-17-11.5-28.5T760-560H200q-17 0-28.5 11.5T160-520v160h80v-80h480v80h80Z"/></svg></span> PDF</a> ';
            if ((e.acoes || []).indexOf('comprar') >= 0) acoesHtml += '<button type="button" class="log_btn log_btn--primary log_btn--sm js-d-comprar"><span class="log_iw"><svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#e3e3e3"><path d="M444-200h70v-50q50-9 86-39t36-89q0-42-24-77t-96-61q-60-20-83-35t-23-41q0-26 18.5-41t53.5-15q32 0 50 15.5t26 38.5l64-26q-11-35-40.5-61T516-710v-50h-70v50q-50 11-78 44t-28 74q0 47 27.5 76t86.5 50q63 23 87.5 41t24.5 47q0 33-23.5 48.5T486-314q-33 0-58.5-20.5T390-396l-66 26q14 48 43.5 77.5T444-252v52Zm36 120q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Zm0-80q134 0 227-93t93-227q0-134-93-227t-227-93q-134 0-227 93t-93 227q0 134 93 227t227 93Zm0-320Z"/></svg></span> Comprar</button>';

            var drawer = adminDrawer({ titulo: 'Etiqueta #' + id, subtitulo: e.pedido_id ? 'Pedido #' + e.pedido_id : 'Avulsa', conteudo: html, acoes: acoesHtml, tamanho: 'md' });
            drawer.escutar('click', '.js-d-comprar', function () {
                api('POST', '/comprar', comCsrf({ id: id })).done(function (rr) {
                    if (rr && rr.ok) { Toast.success('Etiqueta emitida.'); drawer.fechar(); carregar(); }
                    else Toast.error((rr && rr.erro) || 'Falha ao emitir.');
                });
            });
        }).fail(function () { Toast.update(tid, { type: 'error', message: 'Erro ao abrir.', duration: 3000 }); });
    }

    /* ------------------------------------------------ nova etiqueta */
    function endFields(pref, req) {
        function fld(k, label, extra) { return '<div class="log_field"><label>' + label + (req && k === 'nome' ? ' *' : '') + '</label><input class="log_input" data-e="' + pref + '.' + k + '" ' + (extra || '') + '></div>'; }
        return '<div class="log_cli_busca"><input class="log_input" data-cli-busca="' + pref + '" placeholder="Buscar cliente por CPF e preencher"><div class="logrev_ac" data-cli-res="' + pref + '"></div></div>' +
            '<div class="log_form_grid">' +
            fld('nome', 'Nome') + fld('telefone', 'Telefone') +
            fld('document', 'CPF/CNPJ') + fld('email', 'E-mail') +
            '<div class="log_field"><label>CEP</label><div class="log_cep_row"><input class="log_input" data-e="' + pref + '.cep"><button type="button" class="log_btn log_btn--sm" data-cep-buscar="' + pref + '"><span class="log_iw"><svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#e3e3e3"><path d="M784-120 532-372q-30 24-69 38t-83 14q-109 0-184.5-75.5T120-580q0-109 75.5-184.5T380-840q109 0 184.5 75.5T640-580q0 44-14 83t-38 69l252 252-56 56ZM380-400q75 0 127.5-52.5T560-580q0-75-52.5-127.5T380-760q-75 0-127.5 52.5T200-580q0 75 52.5 127.5T380-400Z"/></svg></span> Buscar</button></div></div>' +
            fld('logradouro', 'Logradouro') +
            fld('numero', 'Número') + fld('complemento', 'Complemento') +
            fld('bairro', 'Bairro') + fld('cidade', 'Cidade') +
            '<div class="log_field"><label>UF</label><input class="log_input" data-e="' + pref + '.uf" maxlength="2"></div>' +
        '</div>';
    }
    function volRow() {
        return '<div class="log_item" data-vol="1">' +
            '<input class="log_input" data-k="altura_cm" type="number" step="0.1" placeholder="Alt (cm)">' +
            '<input class="log_input" data-k="largura_cm" type="number" step="0.1" placeholder="Larg (cm)">' +
            '<input class="log_input" data-k="comprimento_cm" type="number" step="0.1" placeholder="Comp (cm)">' +
            '<input class="log_input" data-k="peso_g" type="number" placeholder="Peso (g)">' +
            '<button type="button" class="log_btn log_btn--icon log_btn--xs js-vol-rm"><span class="log_iw"><svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#e3e3e3"><path d="m256-200-56-56 224-224-224-224 56-56 224 224 224-224 56 56-224 224 224 224-56 56-224-224-224 224Z"/></svg></span></button>' +
        '</div>';
    }
    function transpOptions() { return '<option value="">Selecione...</option>' + TRANSP.map(function (t) { return '<option value="' + (t.id | 0) + '">' + esc(t.nome) + '</option>'; }).join(''); }

    function abrirNova() {
        var html = '<form class="log_form" id="logEtqForm">' +
            '<div class="log_fieldset"><h4>Transporte</h4>' +
                '<div class="log_form_grid">' +
                    '<div class="log_field"><label>Transportadora *</label><select class="log_select" id="etqTransp">' + transpOptions() + '</select></div>' +
                    '<div class="log_field"><label>Serviço *</label><select class="log_select" id="etqServico"><option value="">—</option></select></div>' +
                    '<div class="log_field"><label>Pedido (id)</label><input class="log_input" name="pedido_id" type="number"></div>' +
                    '<div class="log_field"><label>Valor declarado (R$)</label><input class="log_input" name="valor_declarado" type="number" step="0.01"></div>' +
                    '<div class="log_field"><label>Formato</label><select class="log_select" name="formato"><option value="pdf">PDF</option><option value="termica">Térmica</option><option value="a4">A4</option></select></div>' +
                '</div>' +
            '</div>' +
            '<div class="log_fieldset"><h4>Destinatário</h4>' + endFields('destinatario', true) + '</div>' +
            '<div class="log_fieldset"><h4>Remetente <span class="log_muted">(opcional — usa a config se vazio)</span></h4>' + endFields('remetente', false) + '</div>' +
            '<div class="log_fieldset"><h4>Volumes <button type="button" class="log_btn log_btn--sm js-vol-add"><span class="log_iw"><svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="currentColor"><path d="M450-450H250q-12.75 0-21.37-8.63-8.63-8.63-8.63-21.38 0-12.76 8.63-21.37Q237.25-510 250-510h200v-200q0-12.75 8.63-21.37 8.63-8.63 21.38-8.63 12.76 0 21.37 8.63Q510-722.75 510-710v200h200q12.75 0 21.37 8.63 8.63 8.63 8.63 21.38 0 12.76-8.63 21.37Q722.75-450 710-450H510v200q0 12.75-8.63 21.37-8.63 8.63-21.38 8.63-12.76 0-21.37-8.63Q450-237.25 450-250v-200Z"/></svg></span> Adicionar</button></h4><div id="etqVolumes">' + volRow() + '</div></div>' +
        '</form>';

        var drawer = adminDrawer({ titulo: 'Nova etiqueta', subtitulo: 'Emissão manual', conteudo: html, tamanho: 'lg',
            acoes: '<button type="button" class="log_btn log_btn--primary log_btn--sm js-criar"><span class="log_iw"><svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="currentColor"><path d="M382-240 154-468l57-57 171 171 367-367 57 57-424 424Z"/></svg></span> Criar</button>' });

        drawer.escutar('change', '#etqTransp', function (ev) {
            var id = parseInt($(ev.target).val(), 10);
            var t = TRANSP.filter(function (x) { return (x.id | 0) === id; })[0];
            var svs = (t && t.servicos) || [];
            var opts = '<option value="">—</option>' + svs.map(function (s) { return '<option value="' + attr(s.codigo) + '" data-nome="' + attr(s.nome) + '">' + esc(s.nome || s.codigo) + '</option>'; }).join('');
            $(drawer.corpo()).find('#etqServico').html(opts);
        });
        drawer.escutar('click', '.js-vol-add', function () { $(drawer.corpo()).find('#etqVolumes').append(volRow()); });
        drawer.escutar('click', '.js-vol-rm', function (ev) { $(ev.target).closest('[data-vol]').remove(); });

        // --- busca de endereço por CEP (destinatário/remetente) ---
        drawer.escutar('click', '[data-cep-buscar]', function (ev) {
            var pref = $(ev.target).closest('[data-cep-buscar]').data('cep-buscar');
            var $b = $(drawer.corpo());
            var cep = String($b.find('[data-e="' + pref + '.cep"]').val() || '').replace(/\D/g, '');
            if (cep.length !== 8) { Toast.warning('Informe um CEP válido.'); return; }
            var tid = Toast.loading('Buscando CEP...');
            api('GET', '/buscar-cep', { cep: cep }).done(function (r) {
                Toast.dismiss(tid);
                if (!r || !r.ok) { Toast.error((r && r.erro) || 'CEP não encontrado.'); return; }
                var e = r.endereco || {};
                ['logradouro', 'bairro', 'cidade', 'uf'].forEach(function (k) { if (e[k]) $b.find('[data-e="' + pref + '.' + k + '"]').val(e[k]); });
                $b.find('[data-e="' + pref + '.numero"]').trigger('focus');
            }).fail(function () { Toast.update(tid, { type: 'error', message: 'Erro ao buscar CEP.', duration: 3000 }); });
        });

        // --- busca de cliente por CPF (preenche tudo) ---
        var debCli = {};
        drawer.escutar('input', '[data-cli-busca]', function (ev) {
            var $inp = $(ev.target), pref = $inp.data('cli-busca'), q = $inp.val();
            var $res = $(drawer.corpo()).find('[data-cli-res="' + pref + '"]');
            clearTimeout(debCli[pref]);
            if (String(q || '').replace(/\D/g, '').length < 3) { $res.removeClass('show').empty(); return; }
            debCli[pref] = setTimeout(function () {
                api('GET', '/buscar-cliente', { cpf: q }).done(function (r) {
                    var cs = (r && r.clientes) || [];
                    if (!cs.length) { $res.html('<div class="logrev_ac_item logrev_ac_empty">Nenhum cliente encontrado</div>').addClass('show'); return; }
                    $res.data('clientes', cs).html(cs.map(function (c, i) {
                        return '<div class="logrev_ac_item" data-cli-pick="' + pref + '" data-i="' + i + '"><strong>' + esc(c.nome || '') + '</strong> · ' + esc(c.cpf_formatado || c.cpf || '') + '</div>';
                    }).join('')).addClass('show');
                });
            }, 300);
        });
        drawer.escutar('click', '[data-cli-pick]', function (ev) {
            var $it = $(ev.target).closest('[data-cli-pick]'), pref = $it.data('cli-pick');
            var $res = $(drawer.corpo()).find('[data-cli-res="' + pref + '"]');
            var c = ($res.data('clientes') || [])[$it.data('i')]; if (!c) return;
            var $b = $(drawer.corpo()), end = c.endereco || {};
            var map = { nome: c.nome, document: c.cpf_formatado || c.cpf, email: c.email, telefone: c.telefone,
                cep: end.cep, logradouro: end.logradouro, numero: end.numero, complemento: end.complemento, bairro: end.bairro, cidade: end.cidade, uf: end.uf };
            Object.keys(map).forEach(function (k) { if (map[k] != null) $b.find('[data-e="' + pref + '.' + k + '"]').val(map[k]); });
            $res.removeClass('show').empty();
            Toast.success('Dados do cliente preenchidos.');
        });
        drawer.escutar('click', '.js-criar', function () {
            var $b = $(drawer.corpo());
            var dados = coletarNova($b);
            if (!dados.transportadora_id) { Toast.warning('Escolha a transportadora.'); return; }
            if (!dados.servico_codigo) { Toast.warning('Escolha o serviço.'); return; }
            if (!dados.volumes.length) { Toast.warning('Adicione ao menos um volume.'); return; }
            var tid = Toast.loading('Criando...');
            api('POST', '/criar', comCsrf(dados)).done(function (r) {
                if (r && r.ok) {
                    Toast.update(tid, { type: 'success', message: r.idempotente ? 'Etiqueta já existente reutilizada.' : 'Etiqueta criada.', duration: 2800 });
                    drawer.fechar('criado'); carregar();
                } else Toast.update(tid, { type: 'error', message: (r && r.erro) || 'Falha ao criar.', duration: 4000 });
            }).fail(function () { Toast.update(tid, { type: 'error', message: 'Erro de comunicação.', duration: 3500 }); });
        });
    }

    function coletarNova($b) {
        var d = { remetente: {}, destinatario: {}, volumes: [] };
        $b.find('[name]').each(function () { var $i = $(this); d[$i.attr('name')] = $i.val(); });
        d.transportadora_id = $b.find('#etqTransp').val();
        d.servico_codigo = $b.find('#etqServico').val();
        d.servico_nome = $b.find('#etqServico option:selected').data('nome') || '';
        $b.find('[data-e]').each(function () {
            var parts = String($(this).data('e')).split('.'); d[parts[0]][parts[1]] = $(this).val();
        });
        $b.find('#etqVolumes [data-vol]').each(function () {
            var v = {}; $(this).find('[data-k]').each(function () { v[$(this).data('k')] = $(this).val(); });
            if (v.peso_g || v.altura_cm) d.volumes.push(v);
        });
        return d;
    }

    /* ------------------------------------------------ util drawer confirm */
    function confirmar(titulo, texto, rotuloOk, onOk) {
        var d = adminDrawer({ titulo: titulo, tamanho: 'sm', conteudo: '<p>' + esc(texto) + '</p>',
            acoes: '<button type="button" class="log_btn log_btn--sm js-c">Voltar</button> <button type="button" class="log_btn log_btn--primary log_btn--sm js-o" style="background:var(--log-danger);border-color:var(--log-danger)">' + esc(rotuloOk) + '</button>' });
        d.escutar('click', '.js-c', function () { d.fechar(); });
        d.escutar('click', '.js-o', function () { onOk(d); });
    }

    /* ------------------------------------------------ boot */
    $(function () {
        if (!document.getElementById('logEtq')) return;
        bindAcoes(); bindLote();
        $('#logEtqNova').on('click', abrirNova);
        carregar();
    });

})(jQuery);