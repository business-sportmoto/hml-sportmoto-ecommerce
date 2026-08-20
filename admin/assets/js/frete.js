/**
 * Frete — Regras e Simulador (jQuery v4).
 * Depende de: adminDrawer, Toast, window.CSRF_TOKEN.
 * A mesma base serve as duas telas; cada bloco só ativa se seu elemento existe.
 */
(function ($) {
    'use strict';

    /* --------------------------------------------------------- util */
    function api(base, method, path, data) {
        return $.ajax({
            url: base + path, method: method, dataType: 'json',
            data: data || {}, headers: { 'X-CSRF-TOKEN': window.CSRF_TOKEN || '' }
        });
    }
    function comCsrf(o) { o = o || {}; o.csrf_token = window.CSRF_TOKEN || ''; o._token = window.CSRF_TOKEN || ''; return o; }
    function esc(s) { return $('<i>').text(s == null ? '' : String(s)).html(); }
    function attr(s) { return esc(s).replace(/"/g, '&quot;'); }
    function moeda(v) { return 'R$ ' + (parseFloat(v) || 0).toFixed(2).replace('.', ','); }

    /* =========================================================
       REGRAS
       ========================================================= */
    var RBASE   = window.LOG_REGRAS_BASE || '/admin/logistica/regras';
    var CAMPOS  = window.LOG_REGRAS_CAMPOS || [];
    var OPERS   = window.LOG_REGRAS_OPERS || [];

    function condRow(c) {
        c = c || {};
        var val = c.valor != null ? (Array.isArray(c.valor) ? c.valor.join(',') : c.valor) : '';
        var campos = CAMPOS.map(function (k) { return '<option value="' + k + '"' + (k === c.campo ? ' selected' : '') + '>' + k + '</option>'; }).join('');
        var opers = OPERS.map(function (o) { return '<option value="' + o + '"' + (o === c.operador ? ' selected' : '') + '>' + o + '</option>'; }).join('');
        return '<div class="log_cond">' +
            '<select class="log_select" data-k="campo">' + campos + '</select>' +
            '<select class="log_select" data-k="operador">' + opers + '</select>' +
            '<input class="log_input" data-k="valor" value="' + attr(val) + '" placeholder="ex.: 299 · SP,RJ · 90000000,91999999">' +
            '<button type="button" class="log_btn log_btn--icon log_btn--xs js-cond-rm" title="Remover"><i class="bi bi-x-lg"></i></button>' +
        '</div>';
    }

    function acaoNum(nome, label, ph) {
        return '<div class="log_field"><label>' + label + '</label><input class="log_input" type="number" step="0.01" data-a="' + nome + '" placeholder="' + (ph || '') + '"></div>';
    }

    function buildRegraForm(r) {
        r = r || {};
        var a = r.acoes || {};
        var conds = (r.condicoes || []).map(condRow).join('');
        function chk(k, on, label) { return '<label class="log_check"><input type="checkbox" data-a="' + k + '"' + (on ? ' checked' : '') + '> ' + label + '</label>'; }
        function val(x) { return x != null && x !== '' ? attr(x) : ''; }

        return '<form class="log_form" id="logRegraForm">' +
            '<input type="hidden" name="id" value="' + (r.id | 0) + '">' +

            '<div class="log_fieldset"><h4>Identificação</h4>' +
                '<div class="log_form_grid">' +
                    '<div class="log_field" id="fld-nome"><label>Nome *</label><input class="log_input" name="nome" value="' + val(r.nome) + '"></div>' +
                    '<div class="log_field"><label>Prioridade</label><input class="log_input" type="number" name="prioridade" value="' + (parseInt(r.prioridade, 10) || 100) + '"></div>' +
                    '<div class="log_field" style="grid-column:1/-1"><label>Descrição</label><input class="log_input" name="descricao" value="' + val(r.descricao) + '"></div>' +
                    '<div class="log_field"><label>Início (agendamento)</label><input class="log_input" type="datetime-local" name="inicio_em" value="' + val((r.inicio_em || "").replace(" ", "T").slice(0, 16)) + '"></div>' +
                    '<div class="log_field"><label>Fim (agendamento)</label><input class="log_input" type="datetime-local" name="fim_em" value="' + val((r.fim_em || "").replace(" ", "T").slice(0, 16)) + '"></div>' +
                '</div>' +
                '<div class="log_checks">' +
                    chk('__ativa', r.id ? r.ativa == 1 : true, 'Ativa') +
                    chk('__acumulativa', r.acumulativa == 1, 'Acumulativa (não para no 1º match)') +
                '</div>' +
            '</div>' +

            '<div class="log_fieldset"><h4>Condições <span class="log_muted">(todas precisam bater — AND)</span> <button type="button" class="log_btn log_btn--sm js-cond-add"><i class="bi bi-plus-lg"></i> Adicionar</button></h4>' +
                '<div id="logConds">' + conds + '</div>' +
                '<p class="log_muted" style="margin-top:6px">Campos <code>transportadora</code>/<code>modalidade</code> definem o escopo (a quais opções o efeito se aplica), não disparam a regra.</p>' +
            '</div>' +

            '<div class="log_fieldset" id="fld-acoes"><h4>Ações</h4>' +
                '<div class="log_checks">' +
                    chk('frete_gratis', !!a.frete_gratis, 'Frete grátis') +
                    chk('frete_gratis_mais_barato', !!a.frete_gratis_mais_barato, 'Frete grátis mais barato') +
                    chk('bloquear_frete_gratis', !!a.bloquear_frete_gratis, 'Bloquear frete grátis') +
                    chk('bloquear_frete', !!a.bloquear_frete_gratis, 'Bloquear frete') +
                '</div>' +
                '<div class="log_form_grid" style="margin-top:10px">' +
                    acaoNum('subsidio_max_valor', 'Teto do subsídio (R$)', 'acima disso, cobra a diferença') +
                    acaoNum('subsidio_max_pct', 'Teto do subsídio (% da mercadoria)', '') +
                    acaoNum('desconto_pct', 'Desconto (%)', '') +
                    acaoNum('desconto_fixo', 'Desconto (R$)', '') +
                    acaoNum('acrescimo', 'Acréscimo (R$)', '') +
                    acaoNum('prazo_adicional', 'Prazo adicional (dias)', '') +
                    '<div class="log_field" style="grid-column:1/-1"><label>Ocultar serviços</label><input class="log_input" data-a="ocultar_servicos" placeholder="códigos de serviço ou slug da transportadora, separados por vírgula"></div>' +
                '</div>' +
            '</div>' +
        '</form>';
    }

    function preencherAcoes($b, a) {
        a = a || {};
        $b.find('[data-a]').each(function () {
            var $i = $(this), k = $i.data('a');
            if ($i.attr('type') === 'checkbox') { $i.prop('checked', !!a[k]); }
            else { $i.val(a[k] != null && a[k] !== '' ? a[k] : (Array.isArray(a[k]) ? a[k].join(',') : '')); }
        });
        if (Array.isArray(a.ocultar_servicos)) $b.find('[data-a="ocultar_servicos"]').val(a.ocultar_servicos.join(','));
    }

    function coletarRegra($b) {
        var d = { acoes: {}, condicoes: [] };
        $b.find('#logRegraForm > .log_fieldset input[name], #logRegraForm input[name], #logRegraForm [name]').each(function () {
            var $i = $(this), n = $i.attr('name');
            if (!n) return;
            d[n] = $i.attr('type') === 'checkbox' ? ($i.is(':checked') ? 1 : 0) : $i.val();
        });
        // flags ativa/acumulativa (data-a especiais)
        d.ativa = $b.find('[data-a="__ativa"]').is(':checked') ? 1 : 0;
        d.acumulativa = $b.find('[data-a="__acumulativa"]').is(':checked') ? 1 : 0;
        // ações
        $b.find('[data-a]').each(function () {
            var $i = $(this), k = $i.data('a');
            if (k === '__ativa' || k === '__acumulativa') return;
            if ($i.attr('type') === 'checkbox') d.acoes[k] = $i.is(':checked') ? 1 : 0;
            else if (($i.val() || '').trim() !== '') d.acoes[k] = $i.val();
        });
        // condições
        $b.find('#logConds .log_cond').each(function () {
            var $r = $(this), c = {};
            $r.find('[data-k]').each(function () { c[$(this).data('k')] = $(this).val(); });
            if ((c.campo || '').trim() !== '') d.condicoes.push(c);
        });
        return d;
    }

    function abrirRegraForm(r) {
        var drawer = adminDrawer({
            titulo: r && r.id ? 'Editar regra' : 'Nova regra',
            subtitulo: r && r.id ? (r.nome || '') : 'Motor de frete',
            conteudo: buildRegraForm(r),
            tamanho: 'lg',
            acoes: '<button type="button" class="log_btn log_btn--primary log_btn--sm js-salvar"><i class="bi bi-check-lg"></i> Salvar</button>'
        });
        var $b = $(drawer.corpo());
        preencherAcoes($b, (r && r.acoes) || {});

        drawer.escutar('click', '.js-cond-add', function () { $(drawer.corpo()).find('#logConds').append(condRow({})); });
        drawer.escutar('click', '.js-cond-rm', function (ev) { $(ev.target).closest('.log_cond').remove(); });
        drawer.escutar('click', '.js-salvar', function () {
            var $bb = $(drawer.corpo());
            var dados = comCsrf(coletarRegra($bb));
            var tid = Toast.loading('Salvando...');
            api(RBASE, 'POST', '/salvar', dados).done(function (res) {
                if (res && res.ok) {
                    Toast.update(tid, { type: 'success', message: 'Regra salva.', duration: 2500 });
                    drawer.fechar('salvo'); recarregarRegras();
                } else if (res && res.erros) {
                    Toast.dismiss(tid);
                    var k = Object.keys(res.erros)[0];
                    Toast.error(res.erros[k]);
                    $bb.find('.log_field--erro').removeClass('log_field--erro');
                    if (k === 'nome') $bb.find('#fld-nome').addClass('log_field--erro');
                    if (k === 'acoes') $bb.find('#fld-acoes').addClass('log_field--erro');
                } else {
                    Toast.update(tid, { type: 'error', message: (res && res.erro) || 'Falha ao salvar.', duration: 4000 });
                }
            }).fail(function () { Toast.update(tid, { type: 'error', message: 'Erro de comunicação.', duration: 4000 }); });
        });
        return drawer;
    }

    function recarregarRegras() {
        api(RBASE, 'GET', '/dados', {
            busca: $('#logRegrasFiltros [name=busca]').val() || '',
            ativa: $('#logRegrasFiltros [name=ativa]').val() || ''
        }).done(function (r) {
            if (!r || !r.ok) return;
            var $tb = $('#logRegrasTabela tbody').empty();
            if (!r.regras.length) {
                $tb.append('<tr><td colspan="6"><div class="log_state"><div class="log_state_title">Nenhuma regra encontrada</div></div></td></tr>');
                return;
            }
            r.regras.forEach(function (rg) { $tb.append(regraRow(rg)); });
        });
    }

    function regraRow(r) {
        var chips = (r.resumo_acoes || []).map(function (c) { return '<span class="log_chip">' + esc(c) + '</span>'; }).join('');
        var st = r.ativa == 1 ? ['is-ok', 'Ativa'] : ['is-neutral', 'Inativa'];
        var acum = r.acumulativa == 1 ? ' <span class="log_badge is-info log_badge--plain">acumulativa</span>' : '';
        return '<tr data-id="' + (r.id | 0) + '">' +
            '<td><div class="log_ordem">' +
                '<button type="button" class="log_btn log_btn--icon log_btn--xs js-mover" data-dir="cima"><i class="bi bi-chevron-up"></i></button>' +
                '<button type="button" class="log_btn log_btn--icon log_btn--xs js-mover" data-dir="baixo"><i class="bi bi-chevron-down"></i></button>' +
            '</div></td>' +
            '<td><div class="log_transp_info"><strong>' + esc(r.nome) + acum + '</strong>' +
                (r.descricao ? '<span class="log_muted">' + esc(r.descricao) + '</span>' : '') + '</div></td>' +
            '<td><span class="log_mono">' + (parseInt(r.condicoes_qtd, 10) || 0) + '</span></td>' +
            '<td><div class="log_chips">' + chips + '</div></td>' +
            '<td><label class="log_toggle"><input type="checkbox" class="js-status"' + (r.ativa == 1 ? ' checked' : '') + '>' +
                '<span class="log_toggle_track"></span>' +
                '<span class="log_toggle_txt log_badge ' + st[0] + ' log_badge--plain js-status-txt">' + st[1] + '</span></label></td>' +
            '<td class="log_col_acoes">' +
                '<button type="button" class="log_btn log_btn--icon js-editar" title="Editar"><i class="bi bi-pencil"></i></button>' +
                '<button type="button" class="log_btn log_btn--icon js-remover" title="Remover"><i class="bi bi-trash"></i></button>' +
            '</td></tr>';
    }

    function bindRegras() {
        $('#logRegraNova').on('click', function () { abrirRegraForm(null); });
        var $t = $('#logRegrasTabela');
        $t.on('click', '.js-editar', function () {
            var id = $(this).closest('tr').data('id'), tid = Toast.loading('Abrindo...');
            api(RBASE, 'GET', '/obter', { id: id }).done(function (r) {
                Toast.dismiss(tid);
                if (r && r.ok) abrirRegraForm(r.regra); else Toast.error('Regra não encontrada.');
            }).fail(function () { Toast.update(tid, { type: 'error', message: 'Erro ao abrir.', duration: 3000 }); });
        });
        $t.on('change', '.js-status', function () {
            var $tr = $(this).closest('tr'), $c = $(this), ativa = $c.is(':checked');
            api(RBASE, 'POST', '/status', comCsrf({ id: $tr.data('id'), ativa: ativa ? 1 : 0 })).done(function (r) {
                if (!r || !r.ok) { $c.prop('checked', !ativa); Toast.error('Falha ao alterar.'); return; }
                var st = ativa ? ['is-ok', 'Ativa'] : ['is-neutral', 'Inativa'];
                $tr.find('.js-status-txt').attr('class', 'log_toggle_txt log_badge ' + st[0] + ' log_badge--plain js-status-txt').text(st[1]);
            }).fail(function () { $c.prop('checked', !ativa); Toast.error('Erro.'); });
        });
        $t.on('click', '.js-remover', function () {
            var $tr = $(this).closest('tr'), nome = $tr.find('strong').text();
            var d = adminDrawer({
                titulo: 'Remover regra', tamanho: 'sm',
                conteudo: '<p>Remover a regra <strong>' + esc(nome) + '</strong>? Esta ação não pode ser desfeita.</p>',
                acoes: '<button type="button" class="log_btn log_btn--sm js-cancel">Cancelar</button> <button type="button" class="log_btn log_btn--primary log_btn--sm js-ok" style="background:var(--log-danger);border-color:var(--log-danger)">Remover</button>'
            });
            d.escutar('click', '.js-cancel', function () { d.fechar(); });
            d.escutar('click', '.js-ok', function () {
                api(RBASE, 'POST', '/remover', comCsrf({ id: $tr.data('id') })).done(function (r) {
                    if (r && r.ok) { Toast.success('Regra removida.'); d.fechar(); recarregarRegras(); }
                    else Toast.error((r && r.erro) || 'Falha ao remover.');
                }).fail(function () { Toast.error('Erro.'); });
            });
        });
        $t.on('click', '.js-mover', function () {
            var $tr = $(this).closest('tr'), dir = $(this).data('dir');
            var $ref = dir === 'cima' ? $tr.prev('tr') : $tr.next('tr');
            if (!$ref.length || !$ref.data('id')) return;
            if (dir === 'cima') $tr.insertBefore($ref); else $tr.insertAfter($ref);
            var ordem = $('#logRegrasTabela tbody tr').map(function () { return $(this).data('id'); }).get().filter(Boolean);
            api(RBASE, 'POST', '/reordenar', comCsrf({ ordem: ordem })).done(function (r) { if (!r || !r.ok) { Toast.error('Falha ao reordenar.'); recarregarRegras(); } });
        });
        var deb;
        $('#logRegrasFiltros [name=busca]').on('input', function () { clearTimeout(deb); deb = setTimeout(recarregarRegras, 350); });
        $('#logRegrasFiltros [name=ativa]').on('change', recarregarRegras);
    }

    /* =========================================================
       SIMULADOR
       ========================================================= */
    var SBASE = window.LOG_SIM_BASE || '/admin/logistica/simulador';

    function itemRow(it) {
        it = it || {};
        function v(x) { return x != null ? attr(x) : ''; }
        return '<div class="log_item">' +
            '<input class="log_input" data-k="peso_g" type="number" placeholder="Peso (g)" value="' + v(it.peso_g) + '">' +
            '<input class="log_input" data-k="altura_cm" type="number" step="0.1" placeholder="Alt (cm)" value="' + v(it.altura_cm) + '">' +
            '<input class="log_input" data-k="largura_cm" type="number" step="0.1" placeholder="Larg (cm)" value="' + v(it.largura_cm) + '">' +
            '<input class="log_input" data-k="comprimento_cm" type="number" step="0.1" placeholder="Comp (cm)" value="' + v(it.comprimento_cm) + '">' +
            '<input class="log_input" data-k="valor" type="number" step="0.01" placeholder="Valor (R$)" value="' + v(it.valor) + '">' +
            '<input class="log_input log_item_qtd" data-k="quantidade" type="number" placeholder="Qtd" value="' + (parseInt(it.quantidade, 10) || 1) + '">' +
            '<input class="log_input" data-k="categoria_id" placeholder="Categoria (id)" value="' + v(it.categoria_id) + '">' +
            '<button type="button" class="log_btn log_btn--icon log_btn--xs js-item-rm"><i class="bi bi-x-lg"></i></button>' +
        '</div>';
    }

    function coletarSim() {
        var $f = $('#logSimForm'), d = {};
        $f.find('input[name], select[name]').each(function () {
            var $i = $(this), n = $i.attr('name');
            if ($i.attr('type') === 'checkbox') d[n] = $i.is(':checked') ? 1 : 0;
            else d[n] = $i.val();
        });
        d.itens = [];
        $('#logSimItens .log_item').each(function () {
            var $r = $(this), it = {};
            $r.find('[data-k]').each(function () { it[$(this).data('k')] = $(this).val(); });
            if ((it.peso_g || it.valor || it.altura_cm)) d.itens.push(it);
        });
        return d;
    }

    function renderResultado(res) {
        var $box = $('#logSimResultado');
        if (!res || !res.ok) {
            $box.html('<div class="log_alert is-danger">' + esc((res && res.erro) || 'Falha na cotação.') + '</div>');
            $('#logSimResumo').text('');
            return;
        }
        var pk = res.empacotamento || {};
        var resumo = (pk.qtd_volumes || 0) + ' volume(s) · real ' + ((pk.peso_real_g || 0) / 1000).toFixed(2) + 'kg · cúbico ' + ((pk.peso_cubico_g || 0) / 1000).toFixed(2) + 'kg · cobrança ' + ((pk.peso_cobranca_g || 0) / 1000).toFixed(2) + 'kg';
        $('#logSimResumo').text(resumo);

        var ops = res.opcoes || [];
        if (!ops.length) {
            $box.html('<div class="log_state"><div class="log_state_title">Nenhuma opção retornada</div><div class="log_state_desc">Verifique se há transportadora ativa e configurada.</div></div>');
            return;
        }
        var verCusto = !!res.pode_ver_custos;
        var html = '<div class="log_ops">';
        ops.forEach(function (o) {
            if (o.oculto) {
                html += '<div class="log_op log_op--oculto"><div class="log_op_main"><strong>' + esc(o.transportadora_nome) + ' · ' + esc(o.servico_nome) + '</strong>' +
                    '<span class="log_muted">' + esc(o.erro || 'Ocultado por regra') + '</span></div></div>';
                return;
            }
            var badges = '';
            if (o.mais_barato) badges += '<span class="log_badge is-ok log_badge--plain">mais barato</span> ';
            if (o.mais_rapido) badges += '<span class="log_badge is-info log_badge--plain">mais rápido</span> ';
            if (o.frete_gratis) badges += '<span class="log_badge is-brand log_badge--plain">frete grátis</span> ';
            var ajuste = '';
            if (verCusto && o.valor_ajuste && Math.abs(o.valor_ajuste) > 0.001) {
                var sinal = o.valor_ajuste < 0 ? '' : '+';
                ajuste = '<span class="log_muted">(base ' + moeda(o.valor_original) + ' · ' + sinal + moeda(o.valor_ajuste) + ')</span>';
            }
            html += '<div class="log_op">' +
                '<div class="log_op_main">' +
                    '<strong>' + esc(o.transportadora_nome) + ' · ' + esc(o.servico_nome) + '</strong>' +
                    '<span class="log_muted">' + (o.prazo_dias ? o.prazo_dias + ' dia(s) útil(eis)' : 'prazo n/d') + (o.regra_id ? ' · regra #' + o.regra_id : '') + '</span>' +
                '</div>' +
                '<div class="log_op_side">' + badges +
                    '<span class="log_op_valor">' + moeda(o.valor_final) + '</span>' + ajuste +
                '</div>' +
            '</div>';
        });
        html += '</div>';
        if (res.cotacao_id) html += '<p class="log_muted" style="margin-top:10px">Cotação registrada #' + res.cotacao_id + '</p>';
        $box.html(html);
    }

    function bindSimulador() {
        $('#logSimItens').append(itemRow({ quantidade: 1 }));
        $('#logSim').on('click', '.js-item-add', function () { $('#logSimItens').append(itemRow({ quantidade: 1 })); });
        $('#logSim').on('click', '.js-item-rm', function () { $(this).closest('.log_item').remove(); });
        $('#logSim').on('click', '.js-limpar', function () {
            $('#logSimForm')[0].reset(); $('#logSimItens').html(itemRow({ quantidade: 1 }));
            $('#logSimResultado').html('<div class="log_state"><div class="log_state_title">Preencha e clique em Cotar</div></div>');
            $('#logSimResumo').text('');
        });
        $('#logSim').on('click', '.js-cotar', function () {
            var dados = comCsrf(coletarSim());
            if (!(dados.cep_destino || '').trim()) { Toast.warning('Informe o CEP de destino.'); return; }
            $('#logSimResultado').html('<div class="log_state"><div class="log_spinner"></div><div class="log_state_desc">Cotando transportadoras...</div></div>');
            api(SBASE, 'POST', '/cotar', dados).done(renderResultado).fail(function () {
                $('#logSimResultado').html('<div class="log_alert is-danger">Erro de comunicação ao cotar.</div>');
            });
        });
    }

    /* --------------------------------------------------------- boot */
    $(function () {
        if (document.getElementById('logRegras')) bindRegras();
        if (document.getElementById('logSim')) bindSimulador();
    });

})(jQuery);
