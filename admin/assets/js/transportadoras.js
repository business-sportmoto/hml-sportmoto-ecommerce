/**
 * Transportadoras — UI administrativa (jQuery v4).
 *
 * Depende de: adminDrawer, Toast, window.CSRF_TOKEN, window.BASE_URL,
 * window.LOG_TRANSP_BASE e window.LOG_CATALOGO (injetados pela view).
 *
 * O formulário (novo/editar) é montado aqui a partir do catálogo: os
 * campos de credencial mudam conforme o adapter e os campos "secret"
 * nunca vêm preenchidos do servidor (mostram apenas se já há valor salvo).
 */
(function ($) {
    'use strict';

    var BASE = window.LOG_TRANSP_BASE || '/admin/logistica/transportadoras';
    var CAT  = window.LOG_CATALOGO || {};

    /* ------------------------------------------------------------ util */

    function api(method, path, data) {
        return $.ajax({
            url: BASE + path,
            method: method,
            dataType: 'json',
            data: data || {},
            headers: { 'X-CSRF-TOKEN': window.CSRF_TOKEN || '' }
        });
    }
    // Anexa o token CSRF ao corpo dos POSTs (nomes comuns cobrem verifyCsrf()).
    function comCsrf(obj) {
        obj = obj || {};
        obj.csrf_token = window.CSRF_TOKEN || '';
        obj._token = window.CSRF_TOKEN || '';
        return obj;
    }

    function esc(s) {
        return $('<i>').text(s == null ? '' : String(s)).html();
    }
    function attr(s) {
        return esc(s).replace(/"/g, '&quot;');
    }

    var STATUS = {
        ativo:   ['is-ok', 'Ativo'],
        pausado: ['is-warn', 'Pausado'],
        inativo: ['is-neutral', 'Inativo']
    };
    var AMB = {
        producao:    ['is-ok', 'Produção'],
        homologacao: ['is-info', 'Homologação'],
        sandbox:     ['is-warn', 'Sandbox']
    };

    /* ------------------------------------------------------ render lista */

    function rowHtml(t) {
        var st = STATUS[t.status] || STATUS.inativo;
        var am = AMB[t.ambiente] || AMB.sandbox;
        var logo = t.logo_url
            ? '<img class="log_transp_logo" src="' + attr(t.logo_url) + '" alt="">'
            : '<span class="log_transp_ph"><i class="bi bi-truck"></i></span>';
        var sync = t.ultima_sync ? formatarData(t.ultima_sync) : '—';
        var label = t.adapter_label || t.adapter;

        return '' +
        '<tr data-id="' + (t.id | 0) + '" data-status="' + attr(t.status) + '">' +
            '<td><div class="log_ordem">' +
                '<button type="button" class="log_btn log_btn--icon log_btn--xs js-mover" data-dir="cima" title="Subir prioridade"><i class="bi bi-chevron-up"></i></button>' +
                '<button type="button" class="log_btn log_btn--icon log_btn--xs js-mover" data-dir="baixo" title="Descer prioridade"><i class="bi bi-chevron-down"></i></button>' +
            '</div></td>' +
            '<td><div class="log_transp">' + logo +
                '<div class="log_transp_info"><strong>' + esc(t.nome) + '</strong>' +
                '<span class="log_muted">' + esc(label) + ' · <span class="log_mono">' + esc(t.slug) + '</span></span></div>' +
            '</div></td>' +
            '<td><span class="log_badge ' + am[0] + ' log_badge--plain">' + am[1] + '</span></td>' +
            '<td><span class="log_mono">' + (parseInt(t.servicos_qtd, 10) || 0) + '</span></td>' +
            '<td><label class="log_toggle" title="Ativar / pausar">' +
                '<input type="checkbox" class="js-status"' + (t.status === 'ativo' ? ' checked' : '') + '>' +
                '<span class="log_toggle_track"></span>' +
                '<span class="log_toggle_txt log_badge ' + st[0] + ' log_badge--plain js-status-txt">' + st[1] + '</span>' +
            '</label></td>' +
            '<td class="log_muted">' + esc(sync) + '</td>' +
            '<td class="log_col_acoes">' +
                '<button type="button" class="log_btn log_btn--icon js-testar" title="Testar conexão"><i class="bi bi-plug"></i></button>' +
                '<button type="button" class="log_btn log_btn--icon js-logs" title="Ver logs"><i class="bi bi-list-columns-reverse"></i></button>' +
                '<button type="button" class="log_btn log_btn--icon js-editar" title="Editar"><i class="bi bi-pencil"></i></button>' +
            '</td>' +
        '</tr>';
    }

    function formatarData(iso) {
        var d = new Date((iso || '').replace(' ', 'T'));
        if (isNaN(d.getTime())) return esc(iso);
        function p(n) { return (n < 10 ? '0' : '') + n; }
        return p(d.getDate()) + '/' + p(d.getMonth() + 1) + '/' + d.getFullYear() + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
    }

    function recarregarLista() {
        var filtros = {
            busca:  $('#logTranspFiltros [name=busca]').val() || '',
            status: $('#logTranspFiltros [name=status]').val() || ''
        };
        api('GET', '/dados', filtros).done(function (r) {
            if (!r || !r.ok) { return; }
            var $tb = $('#logTranspTabela tbody').empty();
            if (!r.transportadoras.length) {
                $tb.append('<tr><td colspan="7"><div class="log_state">' +
                    '<div class="log_state_ico"><i class="bi bi-truck"></i></div>' +
                    '<div class="log_state_title">Nenhuma transportadora encontrada</div>' +
                    '<div class="log_state_desc">Ajuste os filtros ou cadastre uma nova.</div></div></td></tr>');
                return;
            }
            r.transportadoras.forEach(function (t) { $tb.append(rowHtml(t)); });
        }).fail(function () { Toast.error('Falha ao atualizar a lista.'); });
    }

    /* ------------------------------------------------ formulário (drawer) */

    function opcoesAmbiente(adapter, atual) {
        var ambientes = (CAT[adapter] && CAT[adapter].ambientes) || ['sandbox'];
        var rotulos = { producao: 'Produção', homologacao: 'Homologação', sandbox: 'Sandbox' };
        if (ambientes.indexOf(atual) < 0) { atual = ambientes[0]; }
        return ambientes.map(function (a) {
            return '<option value="' + a + '"' + (a === atual ? ' selected' : '') + '>' + rotulos[a] + '</option>';
        }).join('');
    }

    function camposCredencial(adapter, preenchida) {
        var campos = (CAT[adapter] && CAT[adapter].campos) || [];
        preenchida = preenchida || {};
        if (!campos.length) {
            return '<p class="log_muted">Este adapter não requer credenciais.</p>';
        }
        return campos.map(function (c) {
            var tipo = c.tipo === 'secret' ? 'password' : 'text';
            var hint = '';
            if (c.tipo === 'secret' && preenchida[c.nome]) {
                hint = '<span class="log_secret_hint"><i class="bi bi-check-circle"></i> valor salvo — deixe em branco para manter</span>';
            }
            var ph = c.tipo === 'secret' && preenchida[c.nome] ? '••••••••' : '';
            return '<div class="log_field" id="fld-config-' + c.nome + '">' +
                '<label>' + esc(c.label) + (c.obrigatorio ? ' *' : '') + '</label>' +
                '<input type="' + tipo + '" class="log_input" name="config[' + c.nome + ']" autocomplete="off" placeholder="' + ph + '">' +
                hint +
            '</div>';
        }).join('');
    }

    function servicoRow(s) {
        s = s || {};
        return '<div class="log_svc">' +
            '<input class="log_input" data-k="codigo" placeholder="Código" value="' + attr(s.codigo || '') + '">' +
            '<input class="log_input" data-k="nome" placeholder="Nome" value="' + attr(s.nome || '') + '">' +
            '<input class="log_input" data-k="modalidade" placeholder="Modalidade" value="' + attr(s.modalidade || '') + '">' +
            '<input class="log_input log_svc_num" data-k="prazo_adicional" type="number" title="Prazo +dias" value="' + (parseInt(s.prazo_adicional, 10) || 0) + '">' +
            '<label class="log_check log_svc_chk"><input type="checkbox" data-k="habilitado"' + (s.habilitado == 1 || s.habilitado === true ? ' checked' : '') + '> ativo</label>' +
            '<button type="button" class="log_btn log_btn--icon log_btn--xs js-svc-rm" title="Remover"><i class="bi bi-x-lg"></i></button>' +
        '</div>';
    }

    function buildForm(t) {
        t = t || {};
        var adapter = t.adapter || Object.keys(CAT)[0] || 'TransportadoraSimulada';
        var preenchida = t.config_preenchida || {};
        var cfg = t.config || {};
        var isNovo = !t.id;

        var adapterOpts = Object.keys(CAT).map(function (k) {
            return '<option value="' + k + '"' + (k === adapter ? ' selected' : '') + '>' + esc(CAT[k].label) + '</option>';
        }).join('');

        var margens = { nenhum: 'Nenhuma', desconto: 'Desconto', acrescimo: 'Acréscimo' };
        var mtipo = t.margem_tipo || 'nenhum';
        var margemOpts = Object.keys(margens).map(function (k) {
            return '<option value="' + k + '"' + (k === mtipo ? ' selected' : '') + '>' + margens[k] + '</option>';
        }).join('');

        var statusOpts = ['ativo', 'pausado', 'inativo'].map(function (k) {
            var atual = t.status || (isNovo ? 'pausado' : 'inativo');
            return '<option value="' + k + '"' + (k === atual ? ' selected' : '') + '>' + STATUS[k][1] + '</option>';
        }).join('');

        var servicos = (t.servicos || []).map(servicoRow).join('');

        function chk(nome, on) {
            return '<label class="log_check"><input type="checkbox" name="' + nome + '"' + (on == 1 || on === true ? ' checked' : '') + '> ';
        }

        return '' +
        '<form class="log_form" id="logTranspForm">' +
            '<input type="hidden" name="id" value="' + (t.id | 0) + '">' +

            '<div class="log_fieldset"><h4>Identificação</h4>' +
                '<div class="log_form_grid">' +
                    '<div class="log_field" id="fld-nome"><label>Nome *</label><input class="log_input" name="nome" value="' + attr(t.nome || '') + '"></div>' +
                    '<div class="log_field" id="fld-adapter"><label>Adapter (integração) *</label><select class="log_select js-adapter" name="adapter">' + adapterOpts + '</select></div>' +
                    '<div class="log_field" id="fld-ambiente"><label>Ambiente *</label><select class="log_select js-ambiente" name="ambiente">' + opcoesAmbiente(adapter, t.ambiente) + '</select></div>' +
                    '<div class="log_field"><label>Logo (URL)</label><input class="log_input" name="logo_url" value="' + attr(t.logo_url || '') + '"></div>' +
                '</div>' +
                '<p class="log_muted js-adapter-desc">' + esc((CAT[adapter] && CAT[adapter].descricao) || '') + '</p>' +
            '</div>' +

            '<div class="log_fieldset"><h4>Credenciais</h4>' +
                '<div class="log_form_grid" id="logCredenciais">' + camposCredencial(adapter, preenchida) + '</div>' +
            '</div>' +

            '<div class="log_fieldset"><h4>Origem e comercial</h4>' +
                '<div class="log_form_grid">' +
                    '<div class="log_field"><label>CEP de origem</label><input class="log_input" name="cep_origem" value="' + attr(t.cep_origem || '') + '"></div>' +
                    '<div class="log_field"><label>Contrato</label><input class="log_input" name="contrato" value="' + attr(t.contrato || '') + '"></div>' +
                    '<div class="log_field"><label>Prazo de preparo (dias)</label><input class="log_input" type="number" name="prazo_preparo_dias" value="' + (parseInt(t.prazo_preparo_dias, 10) || 0) + '"></div>' +
                    '<div class="log_field" id="fld-margem_tipo"><label>Margem</label><select class="log_select" name="margem_tipo">' + margemOpts + '</select></div>' +
                    '<div class="log_field"><label>Margem (%)</label><input class="log_input" type="number" step="0.01" name="margem_percentual" value="' + (parseFloat(t.margem_percentual) || 0) + '"></div>' +
                    '<div class="log_field"><label>Margem (R$)</label><input class="log_input" type="number" step="0.01" name="margem_valor" value="' + (parseFloat(t.margem_valor) || 0) + '"></div>' +
                    '<div class="log_field"><label>Prioridade</label><input class="log_input" type="number" name="prioridade" value="' + (parseInt(t.prioridade, 10) || 100) + '"></div>' +
                    '<div class="log_field" id="fld-status"><label>Status</label><select class="log_select" name="status">' + statusOpts + '</select></div>' +
                '</div>' +
                '<div class="log_checks">' +
                    chk('seguro_padrao', t.seguro_padrao) + 'Seguro padrão</label>' +
                    chk('usa_valor_declarado', t.usa_valor_declarado) + 'Valor declarado</label>' +
                    chk('suporta_coleta', t.suporta_coleta) + 'Suporta coleta</label>' +
                    chk('suporta_postagem', (t.suporta_postagem == null ? 1 : t.suporta_postagem)) + 'Suporta postagem</label>' +
                '</div>' +
            '</div>' +

            '<div class="log_fieldset"><h4>Serviços <button type="button" class="log_btn log_btn--sm js-svc-add"><i class="bi bi-plus-lg"></i> Adicionar</button></h4>' +
                '<div id="logServicos">' + servicos + '</div>' +
            '</div>' +
        '</form>';
    }

    function limparErros($b) { $b.find('.log_err').remove(); $b.find('.log_field--erro').removeClass('log_field--erro'); }

    function mostrarErros($b, erros) {
        limparErros($b);
        Object.keys(erros).forEach(function (k) {
            var id = 'fld-' + k.replace('config_', 'config-');
            var $f = $b.find('#' + $.escapeSelector(id));
            if (!$f.length) { $f = $b.find('[name="' + k + '"]').closest('.log_field'); }
            $f.addClass('log_field--erro').append('<span class="log_err">' + esc(erros[k]) + '</span>');
        });
        var primeiro = Object.keys(erros)[0];
        if (primeiro) { Toast.error(erros[primeiro]); }
    }

    function coletar($b) {
        var d = {};
        $b.find('#logTranspForm').find('input, select').each(function () {
            var $i = $(this), name = $i.attr('name');
            if (!name) { return; }
            if ($i.attr('type') === 'checkbox') { d[name] = $i.is(':checked') ? 1 : 0; return; }
            d[name] = $i.val();
        });
        // Serviços (repeater) -> array
        d.servicos = [];
        $b.find('#logServicos .log_svc').each(function () {
            var $r = $(this), s = {};
            $r.find('[data-k]').each(function () {
                var $x = $(this), k = $x.data('k');
                s[k] = $x.attr('type') === 'checkbox' ? ($x.is(':checked') ? 1 : 0) : $x.val();
            });
            if ((s.codigo || '').trim() !== '') { d.servicos.push(s); }
        });
        return d;
    }

    function abrirForm(t) {
        var drawer = adminDrawer({
            titulo: t && t.id ? 'Editar transportadora' : 'Nova transportadora',
            subtitulo: t && t.id ? (t.nome || '') : 'Cadastro de integração',
            conteudo: buildForm(t),
            tamanho: 'lg',
            acoes: '<button type="button" class="log_btn log_btn--primary log_btn--sm js-salvar"><i class="bi bi-check-lg"></i> Salvar</button>'
        });

        // Troca de adapter -> recarrega credenciais + ambientes + descrição.
        drawer.escutar('change', '.js-adapter', function () {
            var $b = $(drawer.corpo());
            var adapter = $b.find('.js-adapter').val();
            $b.find('#logCredenciais').html(camposCredencial(adapter, {})); // adapter novo: sem segredos salvos
            $b.find('.js-ambiente').html(opcoesAmbiente(adapter, $b.find('.js-ambiente').val()));
            $b.find('.js-adapter-desc').text((CAT[adapter] && CAT[adapter].descricao) || '');
        });
        drawer.escutar('click', '.js-svc-add', function () {
            $(drawer.corpo()).find('#logServicos').append(servicoRow({ habilitado: 1 }));
        });
        drawer.escutar('click', '.js-svc-rm', function (ev) {
            $(ev.target).closest('.log_svc').remove();
        });
        drawer.escutar('click', '.js-salvar', function () {
            var $b = $(drawer.corpo());
            var dados = comCsrf(coletar($b));
            var tid = Toast.loading('Salvando...');
            api('POST', '/salvar', dados).done(function (r) {
                if (r && r.ok) {
                    Toast.update(tid, { type: 'success', message: 'Transportadora salva.', duration: 2500 });
                    drawer.fechar('salvo');
                    recarregarLista();
                } else if (r && r.erros) {
                    Toast.dismiss(tid);
                    mostrarErros($b, r.erros);
                } else {
                    Toast.update(tid, { type: 'error', message: (r && r.erro) || 'Não foi possível salvar.', duration: 4000 });
                }
            }).fail(function () {
                Toast.update(tid, { type: 'error', message: 'Erro de comunicação ao salvar.', duration: 4000 });
            });
        });

        return drawer;
    }

    /* --------------------------------------------------------- logs drawer */

    function abrirLogs(id, nome) {
        var drawer = adminDrawer({
            titulo: 'Logs de comunicação',
            subtitulo: nome || '',
            conteudo: '<div id="logLogsBox"></div>',
            tamanho: 'lg'
        });
        drawer.setCarregando('Carregando logs...');

        function carregar(pagina) {
            api('GET', '/logs', { id: id, pagina: pagina }).done(function (r) {
                if (!r || !r.ok) { $(drawer.corpo()).find('#logLogsBox').html('<p class="log_muted">Falha ao carregar.</p>'); return; }
                renderLogs($(drawer.corpo()), r, carregar);
            }).fail(function () {
                $(drawer.corpo()).find('#logLogsBox').html('<p class="log_muted">Erro de comunicação.</p>');
            });
        }
        drawer.escutar('click', '.js-log-pg', function (ev) {
            carregar(parseInt($(ev.target).closest('.js-log-pg').data('pg'), 10) || 1);
        });
        carregar(1);
        return drawer;
    }

    function renderLogs($b, r, carregar) {
        var itens = r.itens || [];
        var linhas = itens.map(function (l) {
            var okBadge = l.sucesso == 1
                ? '<span class="log_badge is-ok log_badge--plain">ok</span>'
                : '<span class="log_badge is-danger log_badge--plain">falha</span>';
            return '<tr>' +
                '<td class="log_muted">' + esc(formatarData(l.criado_em)) + '</td>' +
                '<td><span class="log_badge is-neutral log_badge--plain">' + esc(l.tipo) + '</span></td>' +
                '<td>' + okBadge + '</td>' +
                '<td class="log_mono">' + (l.status_http || '—') + '</td>' +
                '<td class="log_mono">' + (l.duracao_ms != null ? l.duracao_ms + ' ms' : '—') + '</td>' +
            '</tr>';
        }).join('');

        if (!itens.length) {
            linhas = '<tr><td colspan="5"><div class="log_state"><div class="log_state_ico"><i class="bi bi-inboxes"></i></div>' +
                '<div class="log_state_title">Sem registros</div>' +
                '<div class="log_state_desc">Ainda não houve comunicação com esta transportadora.</div></div></td></tr>';
        }

        var totalPg = Math.max(1, Math.ceil((r.total || 0) / (r.por_pagina || 30)));
        var pg = r.pagina || 1;
        var nav = totalPg > 1
            ? '<div class="log_pager">' +
                '<button class="log_btn log_btn--sm js-log-pg" data-pg="' + (pg - 1) + '"' + (pg <= 1 ? ' disabled' : '') + '>Anterior</button>' +
                '<span class="log_muted">Página ' + pg + ' de ' + totalPg + '</span>' +
                '<button class="log_btn log_btn--sm js-log-pg" data-pg="' + (pg + 1) + '"' + (pg >= totalPg ? ' disabled' : '') + '>Próxima</button>' +
              '</div>'
            : '';

        $b.find('#logLogsBox').html(
            '<div class="log_table_wrap"><table class="log_table"><thead><tr>' +
            '<th>Quando</th><th>Tipo</th><th>Resultado</th><th>HTTP</th><th>Duração</th>' +
            '</tr></thead><tbody>' + linhas + '</tbody></table></div>' + nav
        );
    }

    /* --------------------------------------------------------------- ações */

    function testar($tr) {
        var id = $tr.data('id');
        var tid = Toast.loading('Testando conexão...');
        api('POST', '/testar', comCsrf({ id: id })).done(function (r) {
            Toast.update(tid, {
                type: (r && r.ok) ? 'success' : 'error',
                message: (r && (r.mensagem || r.erro)) || (r && r.ok ? 'Conexão OK.' : 'Falha na conexão.'),
                duration: 5000
            });
            if (r && r.ok) { recarregarLista(); }
        }).fail(function () {
            Toast.update(tid, { type: 'error', message: 'Erro ao testar conexão.', duration: 4000 });
        });
    }

    function alternarStatus($tr, $chk) {
        var id = $tr.data('id');
        var novo = $chk.is(':checked') ? 'ativo' : 'pausado';
        api('POST', '/status', comCsrf({ id: id, status: novo })).done(function (r) {
            if (!r || !r.ok) {
                $chk.prop('checked', !$chk.is(':checked'));
                Toast.error((r && r.erro) || 'Não foi possível alterar o status.');
                return;
            }
            var st = STATUS[novo];
            $tr.attr('data-status', novo);
            $tr.find('.js-status-txt').attr('class', 'log_toggle_txt log_badge ' + st[0] + ' log_badge--plain js-status-txt').text(st[1]);
        }).fail(function () {
            $chk.prop('checked', !$chk.is(':checked'));
            Toast.error('Erro de comunicação.');
        });
    }

    function mover($tr, dir) {
        var $ref = dir === 'cima' ? $tr.prev('tr') : $tr.next('tr');
        if (!$ref.length || !$ref.data('id')) { return; }
        if (dir === 'cima') { $tr.insertBefore($ref); } else { $tr.insertAfter($ref); }
        var ordem = $('#logTranspTabela tbody tr').map(function () { return $(this).data('id'); }).get()
                     .filter(function (x) { return !!x; });
        api('POST', '/reordenar', comCsrf({ ordem: ordem })).done(function (r) {
            if (!r || !r.ok) { Toast.error('Falha ao reordenar.'); recarregarLista(); }
        }).fail(function () { Toast.error('Erro ao reordenar.'); recarregarLista(); });
    }

    /* --------------------------------------------------------------- bind */

    $(function () {
        // Nova
        $('#logTranspNova').on('click', function () { abrirForm(null); });

        // Editar (busca dados frescos, incl. quais segredos já existem)
        $('#logTranspTabela').on('click', '.js-editar', function () {
            var $tr = $(this).closest('tr'), id = $tr.data('id');
            var tid = Toast.loading('Abrindo...');
            api('GET', '/obter', { id: id }).done(function (r) {
                Toast.dismiss(tid);
                if (r && r.ok) { abrirForm(r.transportadora); }
                else { Toast.error((r && r.erro) || 'Não encontrada.'); }
            }).fail(function () { Toast.update(tid, { type: 'error', message: 'Erro ao abrir.', duration: 3000 }); });
        });

        $('#logTranspTabela').on('click', '.js-testar', function () { testar($(this).closest('tr')); });
        $('#logTranspTabela').on('click', '.js-logs', function () {
            var $tr = $(this).closest('tr');
            abrirLogs($tr.data('id'), $tr.find('.log_transp_info strong').text());
        });
        $('#logTranspTabela').on('change', '.js-status', function () { alternarStatus($(this).closest('tr'), $(this)); });
        $('#logTranspTabela').on('click', '.js-mover', function () { mover($(this).closest('tr'), $(this).data('dir')); });

        // Filtros
        var deb;
        $('#logTranspFiltros [name=busca]').on('input', function () { clearTimeout(deb); deb = setTimeout(recarregarLista, 350); });
        $('#logTranspFiltros [name=status]').on('change', recarregarLista);
    });

})(jQuery);
