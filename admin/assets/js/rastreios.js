/**
 * Rastreios (admin) — jQuery v4. Depende de adminDrawer, Toast, window.CSRF_TOKEN.
 */
(function ($) {
    'use strict';

    var BASE = window.LOG_RAS_BASE || '/admin/logistica/rastreios';
    var PUB = window.LOG_RAS_PUBLICO || '/rastreio/';
    var pagina = 1;

    var STATUS = {
        aguardando_etiqueta: ['is-neutral', 'Aguardando etiqueta'],
        etiqueta_emitida: ['is-info', 'Etiqueta emitida'],
        postado: ['is-info', 'Postado'],
        em_transito: ['is-info', 'Em trânsito'],
        saiu_entrega: ['is-warn', 'Saiu para entrega'],
        entregue: ['is-ok', 'Entregue'],
        devolucao: ['is-danger', 'Em devolução'],
        ocorrencia: ['is-danger', 'Ocorrência']
    };

    function api(method, path, data) {
        return $.ajax({ url: BASE + path, method: method, dataType: 'json', data: data || {}, headers: { 'X-CSRF-TOKEN': window.CSRF_TOKEN || '' } });
    }
    function comCsrf(o) { o = o || {}; o.csrf_token = window.CSRF_TOKEN || ''; o._token = window.CSRF_TOKEN || ''; return o; }
    function esc(s) { return $('<i>').text(s == null ? '' : String(s)).html(); }
    function attr(s) { return esc(s).replace(/"/g, '&quot;'); }
    function linkPublico(token) { return window.location.origin + PUB + token; }

    /* ------------------------------------------------ lista */
    function carregar() {
        var f = {
            busca: $('#logRasFiltros [name=busca]').val() || '',
            status: $('#logRasFiltros [name=status]').val() || '',
            transportadora_id: $('#logRasFiltros [name=transportadora_id]').val() || '',
            atraso: $('#logRasFiltros [name=atraso]').is(':checked') ? 1 : '',
            ocorrencia: $('#logRasFiltros [name=ocorrencia]').is(':checked') ? 1 : '',
            pagina: pagina
        };
        $('#logRasBody').html('<tr><td colspan="6"><div class="log_state"><div class="log_spinner"></div></div></td></tr>');
        api('GET', '/dados', f).done(function (r) {
            if (!r || !r.ok) { $('#logRasBody').html('<tr><td colspan="6"><div class="log_alert is-danger">Falha ao carregar.</div></td></tr>'); return; }
            render(r);
        }).fail(function () { $('#logRasBody').html('<tr><td colspan="6"><div class="log_alert is-danger">Erro de comunicação.</div></td></tr>'); });
    }

    function render(r) {
        var $b = $('#logRasBody').empty();
        if (!r.itens.length) {
            $b.html('<tr><td colspan="6"><div class="log_state"><div class="log_state_title">Nenhum rastreio</div><div class="log_state_desc">Rastreios nascem automaticamente quando etiquetas são emitidas.</div></div></td></tr>');
        } else {
            r.itens.forEach(function (it) { $b.append(linha(it)); });
        }
        pager(r);
    }

    function linha(it) {
        var st = STATUS[it.status_interno] || ['is-neutral', it.status_interno];
        var flags = '';
        if (parseInt(it.atraso, 10)) flags += ' <span class="log_badge is-danger log_badge--plain">Atrasado</span>';
        if (parseInt(it.ocorrencia, 10)) flags += ' <span class="log_badge is-warn log_badge--plain">Ocorrência</span>';
        var destino = [it.destino_cidade, it.destino_uf].filter(Boolean).join(' / ') || '—';

        return '<tr data-id="' + (it.id | 0) + '" data-token="' + attr(it.token_publico || '') + '">' +
            '<td><span class="log_mono">' + (it.pedido_id ? '#' + it.pedido_id : '—') + '</span></td>' +
            '<td><span class="log_mono">' + esc(it.codigo_rastreio || '—') + '</span></td>' +
            '<td>' + esc(it.destinatario_nome || '') + '<div class="log_muted">' + esc(destino) + '</div></td>' +
            '<td><span class="log_badge ' + st[0] + ' log_badge--plain">' + esc(st[1]) + '</span>' + flags + '</td>' +
            '<td class="log_muted">' + esc(it.ultima_atualizacao || '—') + '</td>' +
            '<td class="log_col_acoes">' +
                '<button type="button" class="log_btn log_btn--icon js-atualizar" title="Atualizar agora"><span class="log_iw"><svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 0 24 24" width="24px" fill="currentColor"><path d="M0 0h24v24H0V0z" fill="none"/><path d="m19 8-4 4h3c0 3.31-2.69 6-6 6-1.01 0-1.97-.25-2.8-.7l-1.46 1.46C8.97 19.54 10.43 20 12 20c4.42 0 8-3.58 8-8h3l-4-4zM6 12c0-3.31 2.69-6 6-6 1.01 0 1.97.25 2.8.7l1.46-1.46C15.03 4.46 13.57 4 12 4c-4.42 0-8 3.58-8 8H1l4 4 4-4H6z"/></svg></span</button> ' +
                '<button type="button" class="log_btn log_btn--icon js-link" title="Copiar link público"><span class="log_iw"><svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#e3e3e3"><path d="M360-240q-33 0-56.5-23.5T280-320v-480q0-33 23.5-56.5T360-880h360q33 0 56.5 23.5T800-800v480q0 33-23.5 56.5T720-240H360Zm0-80h360v-480H360v480ZM200-80q-33 0-56.5-23.5T120-160v-560h80v560h440v80H200Zm160-240v-480 480Z"/></svg></span></button> ' +
                '<button type="button" class="log_btn log_btn--icon js-detalhe" title="Timeline"><span class="log_iw"><svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#e3e3e3"><path d="M120-240q-33 0-56.5-23.5T40-320q0-33 23.5-56.5T120-400h10.5q4.5 0 9.5 2l182-182q-2-5-2-9.5V-600q0-33 23.5-56.5T400-680q33 0 56.5 23.5T480-600q0 2-2 20l102 102q5-2 9.5-2h21q4.5 0 9.5 2l142-142q-2-5-2-9.5V-640q0-33 23.5-56.5T840-720q33 0 56.5 23.5T920-640q0 33-23.5 56.5T840-560h-10.5q-4.5 0-9.5-2L678-420q2 5 2 9.5v10.5q0 33-23.5 56.5T600-320q-33 0-56.5-23.5T520-400v-10.5q0-4.5 2-9.5L420-522q-5 2-9.5 2H400q-2 0-20-2L198-340q2 5 2 9.5v10.5q0 33-23.5 56.5T120-240Z"/></svg></span></button>' +
            '</td>' +
        '</tr>';
    }

    function pager(r) {
        var totalPag = Math.max(1, Math.ceil((r.total || 0) / (r.por_pagina || 30)));
        if (totalPag <= 1) { $('#logRasPager').empty(); return; }
        $('#logRasPager').html(
            '<button type="button" class="log_btn log_btn--sm js-pg" data-pg="' + (pagina - 1) + '"' + (pagina <= 1 ? ' disabled' : '') + '>Anterior</button>' +
            '<span class="log_muted">Página ' + pagina + ' de ' + totalPag + '</span>' +
            '<button type="button" class="log_btn log_btn--sm js-pg" data-pg="' + (pagina + 1) + '"' + (pagina >= totalPag ? ' disabled' : '') + '>Próxima</button>'
        );
    }

    /* ------------------------------------------------ ações */
    function copiar(txt) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(txt).then(function () { Toast.success('Link copiado.'); }, function () { fallbackCopiar(txt); });
        } else { fallbackCopiar(txt); }
    }
    function fallbackCopiar(txt) {
        var $t = $('<input>').val(txt).appendTo('body').select();
        try { document.execCommand('copy'); Toast.success('Link copiado.'); } catch (e) { Toast.info(txt); }
        $t.remove();
    }

    function atualizarLinha($tr) {
        var tid = Toast.loading('Consultando transportadora...');
        api('POST', '/atualizar', comCsrf({ id: $tr.data('id') })).done(function (r) {
            if (r && r.ok) Toast.update(tid, { type: 'success', message: r.novos_eventos ? (r.novos_eventos + ' novo(s) evento(s).') : 'Sem novidades.', duration: 2800 });
            else Toast.update(tid, { type: 'error', message: (r && r.erro) || 'Falha ao atualizar.', duration: 4000 });
            carregar();
        }).fail(function () { Toast.update(tid, { type: 'error', message: 'Erro de comunicação.', duration: 3500 }); });
    }

    function abrirDetalhe(id, token) {
        var tid = Toast.loading('Abrindo...');
        api('GET', '/obter', { id: id }).done(function (r) {
            Toast.dismiss(tid);
            if (!r || !r.ok) { Toast.error('Rastreio não encontrado.'); return; }
            var e = r.rastreio, st = STATUS[e.status_interno] || ['is-neutral', e.status_interno];
            var linha = (r.eventos || []).map(function (ev) {
                return '<li><div class="log_tl_acao">' + esc(ev.status_label || ev.status_interno) + '</div>' +
                    '<div class="log_tl_meta">' + esc(ev.data_evento) + (ev.local ? ' · ' + esc(ev.local) : '') + '</div>' +
                    (ev.descricao ? '<div class="log_tl_det">' + esc(ev.descricao) + '</div>' : '') + '</li>';
            }).join('') || '<li><div class="log_tl_meta">Ainda sem eventos. Clique em “Atualizar agora”.</div></li>';

            var url = linkPublico(token || e.token_publico || '');
            var html = '<div class="log_form">' +
                '<div class="log_fieldset"><h4>Situação</h4>' +
                    '<p><span class="log_badge ' + st[0] + ' log_badge--plain">' + esc(st[1]) + '</span> ' + esc(e.transportadora_nome || '') + '</p>' +
                    '<p class="log_muted">Código: <span class="log_mono">' + esc(e.codigo_rastreio || '—') + '</span>' +
                    (e.previsao_entrega ? ' · Previsão: ' + esc(e.previsao_entrega) : '') + '</p>' +
                    '<div class="log_copybox"><input class="log_input log_mono" readonly value="' + attr(url) + '"><button type="button" class="log_btn log_btn--sm js-copy" data-url="' + attr(url) + '"><span class="log_iw"><svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="currentColor"><path d="M360-240q-33 0-56.5-23.5T280-320v-480q0-33 23.5-56.5T360-880h360q33 0 56.5 23.5T800-800v480q0 33-23.5 56.5T720-240H360Zm0-80h360v-480H360v480ZM200-80q-33 0-56.5-23.5T120-160v-560h80v560h440v80H200Zm160-240v-480 480Z"/></svg></span> Copiar</button></div>' +
                '</div>' +
                '<div class="log_fieldset"><h4>Timeline</h4><ul class="log_timeline">' + linha + '</ul></div>' +
            '</div>';

            var drawer = adminDrawer({ titulo: 'Rastreio #' + id, subtitulo: e.pedido_id ? 'Pedido #' + e.pedido_id : '', conteudo: html, tamanho: 'md',
                acoes: '<a href="' + attr(url) + '" target="_blank" class="log_btn log_btn--sm"><span class="log_iw"><svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="currentColor"><path d="M200-120q-33 0-56.5-23.5T120-200v-560q0-33 23.5-56.5T200-840h280v80H200v560h560v-280h80v280q0 33-23.5 56.5T760-120H200Zm188-212-56-56 372-372H560v-80h280v280h-80v-144L388-332Z"/></svg></span> Abrir link</a> <button type="button" class="log_btn log_btn--primary log_btn--sm js-atu"><span class="log_iw"><svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 0 24 24" width="24px" fill="currentColor"><path d="M0 0h24v24H0V0z" fill="none"/><path d="m19 8-4 4h3c0 3.31-2.69 6-6 6-1.01 0-1.97-.25-2.8-.7l-1.46 1.46C8.97 19.54 10.43 20 12 20c4.42 0 8-3.58 8-8h3l-4-4zM6 12c0-3.31 2.69-6 6-6 1.01 0 1.97.25 2.8.7l1.46-1.46C15.03 4.46 13.57 4 12 4c-4.42 0-8 3.58-8 8H1l4 4 4-4H6z"/></svg></span> Atualizar</button>' });
            drawer.escutar('click', '.js-copy', function (ev) { copiar($(ev.target).closest('[data-url]').data('url')); });
            drawer.escutar('click', '.js-atu', function () {
                api('POST', '/atualizar', comCsrf({ id: id })).done(function (rr) {
                    if (rr && rr.ok) { Toast.success(rr.novos_eventos ? (rr.novos_eventos + ' novo(s) evento(s).') : 'Sem novidades.'); drawer.fechar(); carregar(); }
                    else Toast.error((rr && rr.erro) || 'Falha.');
                });
            });
        }).fail(function () { Toast.update(tid, { type: 'error', message: 'Erro ao abrir.', duration: 3000 }); });
    }

    function bind() {
        var $t = $('#logRasTabela');
        $t.on('click', '.js-atualizar', function () { atualizarLinha($(this).closest('tr')); });
        $t.on('click', '.js-link', function () { copiar(linkPublico($(this).closest('tr').data('token'))); });
        $t.on('click', '.js-detalhe', function () { var $tr = $(this).closest('tr'); abrirDetalhe($tr.data('id'), $tr.data('token')); });
        $('#logRasPager').on('click', '.js-pg', function () { pagina = parseInt($(this).data('pg'), 10) || 1; carregar(); });

        var deb;
        $('#logRasFiltros [name=busca]').on('input', function () { clearTimeout(deb); deb = setTimeout(function () { pagina = 1; carregar(); }, 350); });
        $('#logRasFiltros [name=status], #logRasFiltros [name=transportadora_id], #logRasFiltros [name=atraso], #logRasFiltros [name=ocorrencia]').on('change', function () { pagina = 1; carregar(); });
    }

    $(function () {
        if (!document.getElementById('logRas')) return;
        bind();
        carregar();
    });

})(jQuery);
