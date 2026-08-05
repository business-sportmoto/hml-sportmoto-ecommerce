/**
 * Divergências + Alertas de produto (admin) — jQuery v4.
 * Depende de adminDrawer, Toast, window.CSRF_TOKEN.
 */
(function ($) {
    'use strict';

    var BASE = window.LOG_DIV_BASE || '/admin/logistica/divergencias';
    var TRANSP = window.LOG_DIV_TRANSPORTADORAS || [];
    var pgDiv = 1, pgAle = 1, pane = 'div';

    var ST_DIV = { aberta: ['is-warn', 'Aberta'], em_analise: ['is-info', 'Em análise'], resolvida: ['is-ok', 'Resolvida'], ignorada: ['is-neutral', 'Ignorada'] };
    var NIVEL = { alto: ['is-danger', 'Alto'], medio: ['is-warn', 'Médio'], baixo: ['is-neutral', 'Baixo'] };
    var ST_ALE = { aberto: ['is-warn', 'Aberto'], resolvido: ['is-ok', 'Resolvido'] };
    var TIPO = { peso: 'Peso', dimensao: 'Dimensão', embalagem: 'Embalagem', misto: 'Misto' };

    function api(m, p, d) { return $.ajax({ url: BASE + p, method: m, dataType: 'json', data: d || {}, headers: { 'X-CSRF-TOKEN': window.CSRF_TOKEN || '' } }); }
    function comCsrf(o) { o = o || {}; o.csrf_token = window.CSRF_TOKEN || ''; o._token = window.CSRF_TOKEN || ''; return o; }
    function esc(s) { return $('<i>').text(s == null ? '' : String(s)).html(); }
    function attr(s) { return esc(s).replace(/"/g, '&quot;'); }
    function moeda(v) { return 'R$ ' + (parseFloat(v) || 0).toFixed(2).replace('.', ','); }
    function badge(map, key) { var c = map[key] || ['is-neutral', key]; return '<span class="log_badge ' + c[0] + ' log_badge--plain">' + esc(c[1]) + '</span>'; }

    /* =============================== segmentos =============================== */
    function bindSeg() {
        $('.logd_seg_btn').on('click', function () {
            $('.logd_seg_btn').removeClass('is-active');
            $(this).addClass('is-active');
            pane = $(this).data('pane');
            $('#logDivPane').toggle(pane === 'div');
            $('#logAlePane').toggle(pane === 'ale');
            pane === 'div' ? carregarDiv() : carregarAle();
        });
    }

    /* =============================== divergências =============================== */
    function carregarDiv() {
        var f = {
            busca: $('#logDivFiltros [name=busca]').val() || '',
            status: $('#logDivFiltros [name=status]').val() || '',
            nivel: $('#logDivFiltros [name=nivel]').val() || '',
            pagina: pgDiv
        };
        $('#logDivBody').html('<tr><td colspan="6"><div class="log_state"><div class="log_spinner"></div></div></td></tr>');
        api('GET', '/dados', f).done(function (r) {
            if (!r || !r.ok) { $('#logDivBody').html('<tr><td colspan="6"><div class="log_alert is-danger">Falha ao carregar.</div></td></tr>'); return; }
            if (r.resumo) kpis(r.resumo);
            renderDiv(r);
        }).fail(function () { $('#logDivBody').html('<tr><td colspan="6"><div class="log_alert is-danger">Erro.</div></td></tr>'); });
    }
    function kpis(r) {
        $('#kpiAbertas').text(r.abertas != null ? r.abertas : '—');
        $('#kpiImpacto').text(moeda(r.impacto_total || 0));
        $('#kpiAlertas').text(r.alertas_abertos != null ? r.alertas_abertos : '—');
    }
    function renderDiv(r) {
        var $b = $('#logDivBody').empty();
        if (!r.itens.length) { $b.html('<tr><td colspan="6"><div class="log_state"><div class="log_state_title">Nenhuma divergência</div><div class="log_state_desc">Registre quando a cobrança da transportadora vier acima do previsto.</div></div></td></tr>'); }
        else r.itens.forEach(function (it) { $b.append(linhaDiv(it)); });
        pager('#logDivPager', r, function (p) { pgDiv = p; carregarDiv(); });
    }
    function linhaDiv(it) {
        var dif = parseFloat(it.diferenca_valor) || 0;
        var difTxt = (dif > 0 ? '+' : '') + moeda(dif) + ' <span class="log_muted">(' + (parseFloat(it.diferenca_pct) || 0).toFixed(0) + '%)</span>';
        var acoes = it.acoes || [];
        var quick = '';
        if (acoes.indexOf('analisar') >= 0) quick += '<button type="button" class="log_btn log_btn--sm js-d-analisar"><i class="bi bi-search"></i></button> ';
        if (acoes.indexOf('resolver') >= 0) quick += '<button type="button" class="log_btn log_btn--icon js-d-resolver" title="Resolver"><i class="bi bi-check2-circle"></i></button> ';
        return '<tr data-id="' + (it.id | 0) + '">' +
            '<td><span class="log_mono">' + (it.pedido_id ? '#' + it.pedido_id : '—') + '</span></td>' +
            '<td>' + esc(it.transportadora_nome || '—') + '<div class="log_muted">' + esc(it.motivo || '') + '</div></td>' +
            '<td class="' + (dif > 0 ? 'logd_neg' : '') + '">' + difTxt + '</td>' +
            '<td>' + badge(NIVEL, it.nivel_impacto) + '</td>' +
            '<td>' + badge(ST_DIV, it.status) + '</td>' +
            '<td class="log_col_acoes">' + quick + '<button type="button" class="log_btn log_btn--icon js-d-det" title="Detalhes"><i class="bi bi-list-ul"></i></button></td>' +
        '</tr>';
    }

    function acaoDiv(id, path, msg) {
        var tid = Toast.loading('Processando...');
        api('POST', path, comCsrf({ id: id })).done(function (r) {
            if (r && r.ok) Toast.update(tid, { type: 'success', message: msg, duration: 2200 });
            else Toast.update(tid, { type: 'error', message: (r && r.erro) || 'Falha.', duration: 4000 });
            carregarDiv();
        }).fail(function () { Toast.update(tid, { type: 'error', message: 'Erro.', duration: 3000 }); });
    }

    function detalheDiv(id) {
        api('GET', '/obter', { id: id }).done(function (r) {
            if (!r || !r.ok) { Toast.error('Não encontrada.'); return; }
            var d = r.divergencia, di = d.dimensoes_informadas_json || {}, da = d.dimensoes_aferidas_json || {};
            function dim(o) { return o && (o.altura || o.largura || o.comprimento) ? ((o.altura || 0) + '×' + (o.largura || 0) + '×' + (o.comprimento || 0) + ' cm') : '—'; }
            var html = '<div class="log_form">' +
                '<div class="log_fieldset"><h4>Valores</h4>' +
                    '<table class="logd_kv"><tr><td>Cotado</td><td>' + moeda(d.valor_estimado) + '</td></tr>' +
                    '<tr><td>Cobrado pela transportadora</td><td>' + moeda(d.valor_transportadora) + '</td></tr>' +
                    '<tr><td>Pago pelo cliente</td><td>' + moeda(d.valor_cliente) + '</td></tr>' +
                    '<tr><td>Subsídio da loja</td><td>' + moeda(d.subsidio_loja) + '</td></tr>' +
                    '<tr class="logd_kv_hl"><td>Diferença</td><td>' + moeda(d.diferenca_valor) + ' (' + (parseFloat(d.diferenca_pct) || 0).toFixed(0) + '%)</td></tr></table>' +
                '</div>' +
                '<div class="log_fieldset"><h4>Aferição</h4>' +
                    '<table class="logd_kv"><tr><td>Peso informado</td><td>' + (d.peso_informado_g ? d.peso_informado_g + ' g' : '—') + '</td></tr>' +
                    '<tr><td>Peso aferido</td><td>' + (d.peso_aferido_g ? d.peso_aferido_g + ' g' : '—') + '</td></tr>' +
                    '<tr><td>Dim. informadas</td><td>' + dim(di) + '</td></tr>' +
                    '<tr><td>Dim. aferidas</td><td>' + dim(da) + '</td></tr></table>' +
                    (d.motivo ? '<p class="log_muted" style="margin-top:8px">' + esc(d.motivo) + '</p>' : '') +
                    (d.produtos && d.produtos.length ? '<p class="log_muted">Produtos: ' + d.produtos.map(function (p) { return '#' + p; }).join(', ') + '</p>' : '') +
                '</div>' +
                '<div class="log_fieldset"><h4>Observações</h4><textarea class="log_input" id="dObs" rows="3">' + esc(d.observacoes || '') + '</textarea>' +
                    '<button type="button" class="log_btn log_btn--sm js-d-obs" style="margin-top:8px"><i class="bi bi-save"></i> Salvar observações</button></div>' +
            '</div>';

            var acoes = d.acoes || [], acH = '';
            if (acoes.indexOf('analisar') >= 0) acH += '<button type="button" class="log_btn log_btn--sm js-a-analisar"><i class="bi bi-search"></i> Analisar</button> ';
            if (acoes.indexOf('resolver') >= 0) acH += '<button type="button" class="log_btn log_btn--primary log_btn--sm js-a-resolver"><i class="bi bi-check2-circle"></i> Resolver</button> ';
            if (acoes.indexOf('ignorar') >= 0) acH += '<button type="button" class="log_btn log_btn--sm js-a-ignorar">Ignorar</button> ';
            if (acoes.indexOf('reabrir') >= 0) acH += '<button type="button" class="log_btn log_btn--sm js-a-reabrir"><i class="bi bi-arrow-counterclockwise"></i> Reabrir</button>';

            var dr = adminDrawer({ titulo: 'Divergência #' + id, subtitulo: d.pedido_id ? 'Pedido #' + d.pedido_id : '', conteudo: html, acoes: acH, tamanho: 'md' });
            var fin = function () { dr.fechar(); carregarDiv(); };
            dr.escutar('click', '.js-d-obs', function () {
                api('POST', '/atualizar', comCsrf({ id: id, observacoes: $(dr.corpo()).find('#dObs').val() })).done(function (rr) { if (rr && rr.ok) Toast.success('Salvo.'); else Toast.error('Falha.'); });
            });
            dr.escutar('click', '.js-a-analisar', function () { acaoDiv(id, '/analisar', 'Em análise.'); fin(); });
            dr.escutar('click', '.js-a-resolver', function () { acaoDiv(id, '/resolver', 'Resolvida.'); fin(); });
            dr.escutar('click', '.js-a-ignorar', function () { acaoDiv(id, '/ignorar', 'Ignorada.'); fin(); });
            dr.escutar('click', '.js-a-reabrir', function () { acaoDiv(id, '/reabrir', 'Reaberta.'); fin(); });
        });
    }

    /* -------- nova divergência -------- */
    function abrirNova() {
        var transpOpts = '<option value="">—</option>' + TRANSP.map(function (t) { return '<option value="' + (t.id | 0) + '">' + esc(t.nome) + '</option>'; }).join('');
        function dimRow(pref, label) {
            return '<div class="log_field"><label>' + label + ' (A×L×C cm)</label><div class="log_item" style="grid-template-columns:1fr 1fr 1fr">' +
                '<input class="log_input" data-dim="' + pref + '.altura" type="number" step="0.1" placeholder="Alt">' +
                '<input class="log_input" data-dim="' + pref + '.largura" type="number" step="0.1" placeholder="Larg">' +
                '<input class="log_input" data-dim="' + pref + '.comprimento" type="number" step="0.1" placeholder="Comp"></div></div>';
        }
        var html = '<form class="log_form">' +
            '<div class="log_fieldset"><h4>Origem</h4><div class="log_form_grid">' +
                '<div class="log_field"><label>Etiqueta (id) <span class="log_muted">— preenche o resto</span></label><input class="log_input" id="dvEtq" type="number"></div>' +
                '<div class="log_field"><label>Pedido (id)</label><input class="log_input" name="pedido_id" type="number"></div>' +
                '<div class="log_field"><label>Transportadora</label><select class="log_select" name="transportadora_id">' + transpOpts + '</select></div>' +
                '<div class="log_field"><label>Produtos (ids, vírgula)</label><input class="log_input" name="produtos" placeholder="Ex.: 1201, 1202"></div>' +
            '</div></div>' +
            '<div class="log_fieldset"><h4>Valores</h4><div class="log_form_grid">' +
                '<div class="log_field"><label>Cotado (R$) *</label><input class="log_input" name="valor_estimado" type="number" step="0.01"></div>' +
                '<div class="log_field"><label>Cobrado pela transportadora (R$) *</label><input class="log_input" name="valor_transportadora" type="number" step="0.01"></div>' +
                '<div class="log_field"><label>Pago pelo cliente (R$)</label><input class="log_input" name="valor_cliente" type="number" step="0.01"></div>' +
                '<div class="log_field"><label>Subsídio da loja (R$)</label><input class="log_input" name="subsidio_loja" type="number" step="0.01"></div>' +
            '</div></div>' +
            '<div class="log_fieldset"><h4>Aferição <span class="log_muted">(opcional — ajuda a classificar)</span></h4><div class="log_form_grid">' +
                '<div class="log_field"><label>Peso informado (g)</label><input class="log_input" name="peso_informado_g" type="number"></div>' +
                '<div class="log_field"><label>Peso aferido (g)</label><input class="log_input" name="peso_aferido_g" type="number"></div>' +
            '</div><div class="log_form_grid">' + dimRow('dimensoes_informadas', 'Dim. informadas') + dimRow('dimensoes_aferidas', 'Dim. aferidas') + '</div></div>' +
            '<div class="log_fieldset"><h4>Detalhes</h4>' +
                '<div class="log_form_grid"><div class="log_field"><label>Impacto</label><select class="log_select" name="nivel_impacto"><option value="">Automático</option><option value="alto">Alto</option><option value="medio">Médio</option><option value="baixo">Baixo</option></select></div>' +
                '<div class="log_field"><label>Motivo</label><input class="log_input" name="motivo" placeholder="Automático se vazio"></div></div>' +
                '<div class="log_field"><label>Observações</label><textarea class="log_input" name="observacoes" rows="2"></textarea></div>' +
            '</div>' +
        '</form>';

        var dr = adminDrawer({ titulo: 'Nova divergência', tamanho: 'lg', conteudo: html,
            acoes: '<button type="button" class="log_btn log_btn--primary log_btn--sm js-salvar"><i class="bi bi-check-lg"></i> Registrar</button>' });

        // prefill via etiqueta
        dr.escutar('change', '#dvEtq', function (ev) {
            var etq = parseInt($(ev.target).val(), 10);
            if (!etq) return;
            api('GET', '/contexto-etiqueta', { etiqueta_id: etq }).done(function (r) {
                var c = (r && r.contexto) || {}, $b = $(dr.corpo());
                if (c.pedido_id) $b.find('[name=pedido_id]').val(c.pedido_id);
                if (c.transportadora_id) $b.find('[name=transportadora_id]').val(c.transportadora_id);
                if (c.valor_estimado) $b.find('[name=valor_estimado]').val(c.valor_estimado);
                if (c.peso_informado_g) $b.find('[name=peso_informado_g]').val(c.peso_informado_g);
                if (c.dimensoes_informadas) {
                    $b.find('[data-dim="dimensoes_informadas.altura"]').val(c.dimensoes_informadas.altura || '');
                    $b.find('[data-dim="dimensoes_informadas.largura"]').val(c.dimensoes_informadas.largura || '');
                    $b.find('[data-dim="dimensoes_informadas.comprimento"]').val(c.dimensoes_informadas.comprimento || '');
                }
                Toast.info('Dados da etiqueta preenchidos.');
            });
        });

        dr.escutar('click', '.js-salvar', function () {
            var $b = $(dr.corpo());
            var dims = { dimensoes_informadas: {}, dimensoes_aferidas: {} };
            $b.find('[data-dim]').each(function () { var pt = String($(this).data('dim')).split('.'); var val = $(this).val(); if (val !== '') dims[pt[0]][pt[1]] = val; });
            var produtos = ($b.find('[name=produtos]').val() || '').split(/[\s,]+/).map(function (s) { return parseInt(s, 10); }).filter(Boolean);
            var dados = comCsrf({
                etiqueta_id: $b.find('#dvEtq').val(),
                pedido_id: $b.find('[name=pedido_id]').val(),
                transportadora_id: $b.find('[name=transportadora_id]').val(),
                valor_estimado: $b.find('[name=valor_estimado]').val(),
                valor_transportadora: $b.find('[name=valor_transportadora]').val(),
                valor_cliente: $b.find('[name=valor_cliente]').val(),
                subsidio_loja: $b.find('[name=subsidio_loja]').val(),
                peso_informado_g: $b.find('[name=peso_informado_g]').val(),
                peso_aferido_g: $b.find('[name=peso_aferido_g]').val(),
                nivel_impacto: $b.find('[name=nivel_impacto]').val(),
                motivo: $b.find('[name=motivo]').val(),
                observacoes: $b.find('[name=observacoes]').val(),
                produtos: produtos
            });
            if (Object.keys(dims.dimensoes_informadas).length) dados.dimensoes_informadas = dims.dimensoes_informadas;
            if (Object.keys(dims.dimensoes_aferidas).length) dados.dimensoes_aferidas = dims.dimensoes_aferidas;
            if (!dados.valor_transportadora) { Toast.warning('Informe o valor cobrado pela transportadora.'); return; }
            var tid = Toast.loading('Registrando...');
            api('POST', '/registrar', dados).done(function (r) {
                if (r && r.ok) { Toast.update(tid, { type: 'success', message: r.existente ? 'Já havia divergência para esta etiqueta.' : ('Registrada — impacto ' + (NIVEL[r.nivel_impacto] ? NIVEL[r.nivel_impacto][1].toLowerCase() : '')), duration: 3000 }); dr.fechar('ok'); carregarDiv(); }
                else Toast.update(tid, { type: 'error', message: (r && r.erro) || 'Falha.', duration: 4000 });
            }).fail(function () { Toast.update(tid, { type: 'error', message: 'Erro.', duration: 3000 }); });
        });
    }

    /* =============================== alertas =============================== */
    function carregarAle() {
        var f = { status: $('#logAleFiltros [name=status]').val() || 'aberto', tipo: $('#logAleFiltros [name=tipo]').val() || '', busca: $('#logAleFiltros [name=busca]').val() || '', pagina: pgAle };
        $('#logAleBody').html('<tr><td colspan="6"><div class="log_state"><div class="log_spinner"></div></div></td></tr>');
        api('GET', '/alertas', f).done(function (r) {
            if (!r || !r.ok) { $('#logAleBody').html('<tr><td colspan="6"><div class="log_alert is-danger">Falha.</div></td></tr>'); return; }
            renderAle(r);
        }).fail(function () { $('#logAleBody').html('<tr><td colspan="6"><div class="log_alert is-danger">Erro.</div></td></tr>'); });
    }
    function renderAle(r) {
        var $b = $('#logAleBody').empty();
        if (!r.itens.length) { $b.html('<tr><td colspan="6"><div class="log_state"><div class="log_state_title">Nenhum alerta</div><div class="log_state_desc">Alertas nascem quando um produto acumula divergências.</div></div></td></tr>'); }
        else r.itens.forEach(function (it) { $b.append(linhaAle(it)); });
        pager('#logAlePager', r, function (p) { pgAle = p; carregarAle(); });
    }
    function linhaAle(it) {
        return '<tr data-id="' + (it.id | 0) + '">' +
            '<td><span class="log_mono">#' + (it.produto_id | 0) + '</span></td>' +
            '<td>' + esc(TIPO[it.tipo] || it.tipo) + '</td>' +
            '<td>' + (it.ocorrencias | 0) + '</td>' +
            '<td class="logd_neg">' + moeda(it.impacto_acumulado) + '</td>' +
            '<td>' + badge(ST_ALE, it.status) + '</td>' +
            '<td class="log_col_acoes">' +
                (it.status === 'aberto' ? '<button type="button" class="log_btn log_btn--icon js-al-resolver" title="Resolver"><i class="bi bi-check2-circle"></i></button> ' : '<button type="button" class="log_btn log_btn--icon js-al-reabrir" title="Reabrir"><i class="bi bi-arrow-counterclockwise"></i></button> ') +
                '<button type="button" class="log_btn log_btn--icon js-al-det" title="Detalhes"><i class="bi bi-list-ul"></i></button>' +
            '</td></tr>';
    }
    function detalheAle(id) {
        api('GET', '/alerta-obter', { id: id }).done(function (r) {
            if (!r || !r.ok) { Toast.error('Não encontrado.'); return; }
            var a = r.alerta;
            var linhas = (a.divergencias || []).map(function (d) {
                return '<li><div class="log_tl_acao">' + moeda(d.diferenca_valor) + ' · ' + esc(d.nivel_impacto) + (d.pedido_id ? ' · Pedido #' + d.pedido_id : '') + '</div>' +
                    '<div class="log_tl_meta">' + esc(d.criado_em) + '</div>' + (d.motivo ? '<div class="log_tl_det">' + esc(d.motivo) + '</div>' : '') + '</li>';
            }).join('') || '<li><div class="log_tl_meta">Sem divergências vinculadas.</div></li>';
            var html = '<div class="log_form"><div class="log_fieldset"><h4>Produto #' + (a.produto_id | 0) + '</h4>' +
                '<p>' + badge(ST_ALE, a.status) + ' · ' + esc(TIPO[a.tipo] || a.tipo) + ' · ' + (a.ocorrencias | 0) + ' ocorrência(s)</p>' +
                '<p class="logd_neg" style="font-weight:600">Impacto acumulado: ' + moeda(a.impacto_acumulado) + '</p>' +
                '<p class="log_muted">Revise o cadastro de peso/dimensões deste produto para estancar o prejuízo.</p></div>' +
                '<div class="log_fieldset"><h4>Divergências que alimentaram</h4><ul class="log_timeline">' + linhas + '</ul></div></div>';
            var acH = a.status === 'aberto'
                ? '<button type="button" class="log_btn log_btn--primary log_btn--sm js-r"><i class="bi bi-check2-circle"></i> Marcar resolvido</button>'
                : '<button type="button" class="log_btn log_btn--sm js-rb"><i class="bi bi-arrow-counterclockwise"></i> Reabrir</button>';
            var dr = adminDrawer({ titulo: 'Alerta de produto', conteudo: html, acoes: acH, tamanho: 'md' });
            dr.escutar('click', '.js-r', function () { api('POST', '/resolver-alerta', comCsrf({ id: id })).done(function (rr) { if (rr && rr.ok) { Toast.success('Alerta resolvido.'); dr.fechar(); carregarAle(); carregarDivSilenc(); } else Toast.error((rr && rr.erro) || 'Falha.'); }); });
            dr.escutar('click', '.js-rb', function () { api('POST', '/reabrir-alerta', comCsrf({ id: id })).done(function (rr) { if (rr && rr.ok) { Toast.success('Reaberto.'); dr.fechar(); carregarAle(); } else Toast.error((rr && rr.erro) || 'Falha.'); }); });
        });
    }
    function carregarDivSilenc() { api('GET', '/dados', { pagina: 1 }).done(function (r) { if (r && r.resumo) kpis(r.resumo); }); }

    /* =============================== util =============================== */
    function pager(sel, r, go) {
        var totalPag = Math.max(1, Math.ceil((r.total || 0) / (r.por_pagina || 30)));
        var cur = r.pagina || 1;
        if (totalPag <= 1) { $(sel).empty(); return; }
        $(sel).html('<button type="button" class="log_btn log_btn--sm js-pg" data-pg="' + (cur - 1) + '"' + (cur <= 1 ? ' disabled' : '') + '>Anterior</button>' +
            '<span class="log_muted">Página ' + cur + ' de ' + totalPag + '</span>' +
            '<button type="button" class="log_btn log_btn--sm js-pg" data-pg="' + (cur + 1) + '"' + (cur >= totalPag ? ' disabled' : '') + '>Próxima</button>')
            .off('click').on('click', '.js-pg', function () { go(parseInt($(this).data('pg'), 10) || 1); });
    }

    function bind() {
        bindSeg();
        var $dt = $('#logDivTabela');
        $dt.on('click', '.js-d-analisar', function () { acaoDiv($(this).closest('tr').data('id'), '/analisar', 'Em análise.'); });
        $dt.on('click', '.js-d-resolver', function () { acaoDiv($(this).closest('tr').data('id'), '/resolver', 'Resolvida.'); });
        $dt.on('click', '.js-d-det', function () { detalheDiv($(this).closest('tr').data('id')); });
        $('#logDivNova').on('click', abrirNova);

        var $at = $('#logAleTabela');
        $at.on('click', '.js-al-resolver', function () { var id = $(this).closest('tr').data('id'); api('POST', '/resolver-alerta', comCsrf({ id: id })).done(function (r) { if (r && r.ok) { Toast.success('Resolvido.'); carregarAle(); carregarDivSilenc(); } else Toast.error((r && r.erro) || 'Falha.'); }); });
        $at.on('click', '.js-al-reabrir', function () { var id = $(this).closest('tr').data('id'); api('POST', '/reabrir-alerta', comCsrf({ id: id })).done(function (r) { if (r && r.ok) { Toast.success('Reaberto.'); carregarAle(); } else Toast.error((r && r.erro) || 'Falha.'); }); });
        $at.on('click', '.js-al-det', function () { detalheAle($(this).closest('tr').data('id')); });

        var deb;
        $('#logDivFiltros [name=busca]').on('input', function () { clearTimeout(deb); deb = setTimeout(function () { pgDiv = 1; carregarDiv(); }, 350); });
        $('#logDivFiltros [name=status], #logDivFiltros [name=nivel]').on('change', function () { pgDiv = 1; carregarDiv(); });
        var deb2;
        $('#logAleFiltros [name=busca]').on('input', function () { clearTimeout(deb2); deb2 = setTimeout(function () { pgAle = 1; carregarAle(); }, 350); });
        $('#logAleFiltros [name=status], #logAleFiltros [name=tipo]').on('change', function () { pgAle = 1; carregarAle(); });
    }

    $(function () { if (!document.getElementById('logDiv')) return; bind(); carregarDiv(); });

})(jQuery);
