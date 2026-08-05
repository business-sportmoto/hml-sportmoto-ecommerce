/**
 * Reversas (admin) — jQuery v4. Depende de adminDrawer, Toast, window.CSRF_TOKEN.
 */
(function ($) {
    'use strict';

    var BASE = window.LOG_REV_BASE || '/admin/logistica/reversas';
    var PUB = window.LOG_REV_PUBLICO || '/rastreio/';
    var TRANSP = window.LOG_REV_TRANSPORTADORAS || [];
    var pagina = 1;

    var STATUS = {
        solicitada: ['is-neutral', 'Solicitada'],
        autorizada: ['is-info', 'Autorizada'],
        etiqueta_gerada: ['is-info', 'Etiqueta gerada'],
        em_transito: ['is-warn', 'Em trânsito'],
        recebida: ['is-ok', 'Recebida'],
        cancelada: ['is-danger', 'Cancelada']
    };

    function api(m, p, d) { return $.ajax({ url: BASE + p, method: m, dataType: 'json', data: d || {}, headers: { 'X-CSRF-TOKEN': window.CSRF_TOKEN || '' } }); }
    function comCsrf(o) { o = o || {}; o.csrf_token = window.CSRF_TOKEN || ''; o._token = window.CSRF_TOKEN || ''; return o; }
    function esc(s) { return $('<i>').text(s == null ? '' : String(s)).html(); }
    function attr(s) { return esc(s).replace(/"/g, '&quot;'); }
    function linkPublico(t) { return t ? (window.location.origin + PUB + t) : ''; }

    /* ------------------------------------------------ lista */
    function carregar() {
        var f = {
            busca: $('#logRevFiltros [name=busca]').val() || '',
            status: $('#logRevFiltros [name=status]').val() || '',
            motivo: $('#logRevFiltros [name=motivo]').val() || '',
            processo: $('#logRevFiltros [name=processo]').val() || '',
            pagina: pagina
        };
        $('#logRevBody').html('<tr><td colspan="6"><div class="log_state"><div class="log_spinner"></div></div></td></tr>');
        api('GET', '/dados', f).done(function (r) {
            if (!r || !r.ok) { $('#logRevBody').html('<tr><td colspan="6"><div class="log_alert is-danger">Falha ao carregar.</div></td></tr>'); return; }
            render(r);
        }).fail(function () { $('#logRevBody').html('<tr><td colspan="6"><div class="log_alert is-danger">Erro de comunicação.</div></td></tr>'); });
    }

    function render(r) {
        var $b = $('#logRevBody').empty();
        if (!r.itens.length) {
            $b.html('<tr><td colspan="6"><div class="log_state"><div class="log_state_title">Nenhuma reversa</div><div class="log_state_desc">Crie uma solicitação de devolução ou troca.</div></div></td></tr>');
        } else {
            r.itens.forEach(function (it) { $b.append(linha(it)); });
        }
        pager(r);
    }

    function linha(it) {
        var st = STATUS[it.status] || ['is-neutral', it.status];
        var proc = it.processo && it.processo !== 'nenhum'
            ? '<span class="log_badge ' + (it.processo === 'reembolso' ? 'is-warn' : 'is-info') + ' log_badge--plain">' + (it.processo === 'reembolso' ? 'Reembolso' : 'Troca') + '</span>'
            : '<span class="log_muted">—</span>';
        var acoes = it.acoes || [];
        var quick = '';
        if (acoes.indexOf('autorizar') >= 0) quick = '<button type="button" class="log_btn log_btn--sm js-autorizar"><i class="bi bi-check2-circle"></i> Autorizar</button> ';
        else if (acoes.indexOf('gerar_etiqueta') >= 0) quick = '<button type="button" class="log_btn log_btn--sm js-gerar"><i class="bi bi-tag"></i> Gerar etiqueta</button> ';

        return '<tr data-id="' + (it.id | 0) + '">' +
            '<td><span class="log_mono">' + (it.pedido_id ? '#' + it.pedido_id : '—') + '</span></td>' +
            '<td>' + esc(it.motivo_label || it.motivo) + '<div class="log_muted">' + (it.tipo === 'coleta' ? 'Coleta' : 'Postagem') + '</div></td>' +
            '<td>' + esc(it.transportadora_nome || '—') + '<div class="log_muted log_mono">' + esc(it.codigo_rastreio || '') + '</div></td>' +
            '<td><span class="log_badge ' + st[0] + ' log_badge--plain">' + esc(st[1]) + '</span></td>' +
            '<td>' + proc + '</td>' +
            '<td class="log_col_acoes">' + quick +
                '<button type="button" class="log_btn log_btn--icon js-detalhe" title="Detalhes"><i class="bi bi-list-ul"></i></button>' +
            '</td>' +
        '</tr>';
    }

    function pager(r) {
        var totalPag = Math.max(1, Math.ceil((r.total || 0) / (r.por_pagina || 30)));
        if (totalPag <= 1) { $('#logRevPager').empty(); return; }
        $('#logRevPager').html(
            '<button type="button" class="log_btn log_btn--sm js-pg" data-pg="' + (pagina - 1) + '"' + (pagina <= 1 ? ' disabled' : '') + '>Anterior</button>' +
            '<span class="log_muted">Página ' + pagina + ' de ' + totalPag + '</span>' +
            '<button type="button" class="log_btn log_btn--sm js-pg" data-pg="' + (pagina + 1) + '"' + (pagina >= totalPag ? ' disabled' : '') + '>Próxima</button>'
        );
    }

    /* ------------------------------------------------ ações simples */
    function acaoSimples(id, path, msg) {
        var tid = Toast.loading('Processando...');
        return api('POST', path, comCsrf({ id: id })).done(function (r) {
            if (r && r.ok) Toast.update(tid, { type: 'success', message: msg, duration: 2500 });
            else Toast.update(tid, { type: 'error', message: (r && r.erro) || 'Falha.', duration: 4000 });
            carregar();
        }).fail(function () { Toast.update(tid, { type: 'error', message: 'Erro de comunicação.', duration: 3500 }); });
    }

    function bind() {
        var $t = $('#logRevTabela');
        $t.on('click', '.js-autorizar', function () { acaoSimples($(this).closest('tr').data('id'), '/autorizar', 'Reversa autorizada.'); });
        $t.on('click', '.js-gerar', function () { abrirGerar($(this).closest('tr').data('id')); });
        $t.on('click', '.js-detalhe', function () { abrirDetalhe($(this).closest('tr').data('id')); });
        $('#logRevPager').on('click', '.js-pg', function () { pagina = parseInt($(this).data('pg'), 10) || 1; carregar(); });

        var deb;
        $('#logRevFiltros [name=busca]').on('input', function () { clearTimeout(deb); deb = setTimeout(function () { pagina = 1; carregar(); }, 350); });
        $('#logRevFiltros [name=status], #logRevFiltros [name=motivo], #logRevFiltros [name=processo]').on('change', function () { pagina = 1; carregar(); });
        $('#logRevNova').on('click', abrirNova);
    }

    /* ------------------------------------------------ endereço / volumes (compartilhado) */
    function endFields(pref) {
        function fld(k, label) { return '<div class="log_field"><label>' + label + '</label><input class="log_input" data-e="' + pref + '.' + k + '"></div>'; }
        return '<div class="log_form_grid">' + fld('nome', 'Nome') + fld('telefone', 'Telefone') + fld('cep', 'CEP') + fld('logradouro', 'Logradouro') + fld('numero', 'Número') + fld('complemento', 'Complemento') + fld('bairro', 'Bairro') + fld('cidade', 'Cidade') +
            '<div class="log_field"><label>UF</label><input class="log_input" data-e="' + pref + '.uf" maxlength="2"></div></div>';
    }
    function preencherEndereco($b, pref, dados) {
        Object.keys(dados || {}).forEach(function (k) { if (dados[k] != null) $b.find('[data-e="' + pref + '.' + k + '"]').val(dados[k]); });
    }
    function volRow() {
        return '<div class="log_item" data-vol="1">' +
            '<input class="log_input" data-k="altura_cm" type="number" step="0.1" placeholder="Alt (cm)">' +
            '<input class="log_input" data-k="largura_cm" type="number" step="0.1" placeholder="Larg (cm)">' +
            '<input class="log_input" data-k="comprimento_cm" type="number" step="0.1" placeholder="Comp (cm)">' +
            '<input class="log_input" data-k="peso_g" type="number" placeholder="Peso (g)">' +
            '<button type="button" class="log_btn log_btn--icon log_btn--xs js-vol-rm"><i class="bi bi-x-lg"></i></button></div>';
    }
    function coletarEnd($b, pref) { var o = {}; $b.find('[data-e^="' + pref + '."]').each(function () { o[String($(this).data('e')).split('.')[1]] = $(this).val(); }); return o; }
    function coletarVols($b) {
        var vs = [];
        $b.find('[data-vol]').each(function () { var v = {}; $(this).find('[data-k]').each(function () { v[$(this).data('k')] = $(this).val(); }); if (v.peso_g || v.altura_cm) vs.push(v); });
        return vs;
    }

    /* ------------------------------------------------ nova solicitação */
    function abrirNova() {
        var motivos = { devolucao: 'Devolução', troca: 'Troca', defeito: 'Defeito', arrependimento: 'Arrependimento', avaria: 'Avaria', outro: 'Outro' };
        var opts = Object.keys(motivos).map(function (k) { return '<option value="' + k + '">' + motivos[k] + '</option>'; }).join('');
        var html = '<form class="log_form" id="logRevForm">' +
            '<div class="log_fieldset"><h4>Cliente</h4>' +
                '<div class="log_field"><label>Buscar por CPF <span class="log_muted">— autopreenche o endereço</span></label>' +
                    '<input class="log_input" id="revCpf" autocomplete="off" inputmode="numeric" placeholder="Digite o CPF do cliente...">' +
                    '<div class="logrev_ac" id="revCpfRes"></div>' +
                '</div>' +
                '<input type="hidden" name="cliente_id" id="revClienteId">' +
                '<div class="logrev_chip" id="revClienteSel" style="display:none"></div>' +
                '<p class="log_muted">Sem cadastro? Deixe o CPF em branco e preencha o endereço abaixo (reversa 100% manual).</p>' +
            '</div>' +
            '<div class="log_fieldset"><h4>Solicitação</h4><div class="log_form_grid">' +
                '<div class="log_field"><label>Pedido (id) <span class="log_muted">— vazio = reversa avulsa</span></label><input class="log_input" name="pedido_id" type="number"></div>' +
                '<div class="log_field"><label>Motivo</label><select class="log_select" name="motivo">' + opts + '</select></div>' +
                '<div class="log_field"><label>Tipo</label><select class="log_select" name="tipo"><option value="postagem">Postagem (cliente posta)</option><option value="coleta">Coleta (retirar no cliente)</option></select></div>' +
                '<div class="log_field"><label>Processo</label><select class="log_select" name="processo"><option value="">Sugerir pelo motivo</option><option value="nenhum">Nenhum</option><option value="troca">Troca</option><option value="reembolso">Reembolso</option></select></div>' +
            '</div></div>' +
            '<div class="log_fieldset"><h4>Endereço do cliente <span class="log_muted">(coleta / remetente da volta)</span></h4>' + endFields('endereco_coleta') + '</div>' +
            '<div class="log_fieldset"><h4>Itens <span class="log_muted">(um por linha, opcional)</span></h4><textarea class="log_input" id="revItens" rows="3" placeholder="Ex.: Capacete tamanho M&#10;Par de luvas"></textarea></div>' +
        '</form>';

        var drawer = adminDrawer({ titulo: 'Nova solicitação de reversa', tamanho: 'lg', conteudo: html,
            acoes: '<button type="button" class="log_btn log_btn--primary log_btn--sm js-criar"><i class="bi bi-check-lg"></i> Registrar</button>' });

        // ---- busca por CPF (autocomplete) ----
        var cpfResultados = [];
        var debCpf;
        drawer.escutar('input', '#revCpf', function (ev) {
            var q = $(ev.target).val() || '';
            clearTimeout(debCpf);
            // trocou o CPF → limpa a seleção anterior
            $(drawer.corpo()).find('#revClienteId').val('');
            $(drawer.corpo()).find('#revClienteSel').hide().empty();
            debCpf = setTimeout(function () { buscarCpf(drawer, q); }, 300);
        });
        drawer.escutar('click', '.logrev_ac_item', function (ev) {
            var idx = parseInt($(ev.currentTarget || ev.target).closest('.logrev_ac_item').data('idx'), 10);
            var c = cpfResultados[idx];
            if (c) selecionarCliente(drawer, c);
        });
        drawer.escutar('click', '.logrev_chip_x', function () {
            var $b = $(drawer.corpo());
            $b.find('#revClienteId').val('');
            $b.find('#revClienteSel').hide().empty();
            $b.find('#revCpf').val('').focus();
        });

        function buscarCpf(dr, q) {
            var $b = $(dr.corpo()), $res = $b.find('#revCpfRes');
            var dig = (q || '').replace(/\D+/g, '');
            if (dig.length < 3) { $res.removeClass('show').empty(); return; }
            $res.addClass('show').html('<div class="logrev_ac_hint">Buscando...</div>');
            api('GET', '/buscar-cliente', { cpf: dig }).done(function (r) {
                cpfResultados = (r && r.clientes) || [];
                if (!cpfResultados.length) { $res.html('<div class="logrev_ac_hint">Nenhum cliente encontrado.</div>'); return; }
                $res.html(cpfResultados.map(function (c, i) {
                    return '<div class="logrev_ac_item" data-idx="' + i + '"><div>' + esc(c.nome || 'Sem nome') + '</div>' +
                        '<div class="cpf">' + esc(c.cpf_formatado || c.cpf || '') + (c.cidade ? ' · ' + esc(c.cidade) : '') + '</div></div>';
                }).join(''));
            }).fail(function () { $res.html('<div class="logrev_ac_hint">Erro na busca.</div>'); });
        }

        function selecionarCliente(dr, c) {
            var $b = $(dr.corpo());
            var end = c.endereco || {};
            preencherEndereco($b, 'endereco_coleta', {
                nome: c.nome, telefone: c.telefone,
                cep: end.cep, logradouro: end.logradouro, numero: end.numero,
                complemento: end.complemento, bairro: end.bairro, cidade: end.cidade, uf: end.uf
            });
            $b.find('#revClienteId').val(c.cliente_id || '');
            $b.find('#revCpfRes').removeClass('show').empty();
            $b.find('#revClienteSel').show().html(
                '<i class="bi bi-person-check"></i> <strong>' + esc(c.nome || '') + '</strong> · ' + esc(c.cpf_formatado || c.cpf || '') +
                '<button type="button" class="logrev_chip_x" title="Trocar"><i class="bi bi-x-lg"></i></button>'
            );
            Toast.success('Cliente selecionado.');
        }

        // ---- salvar ----
        drawer.escutar('click', '.js-criar', function () {
            var $b = $(drawer.corpo());
            var itens = ($b.find('#revItens').val() || '').split('\n').map(function (s) { return s.trim(); }).filter(Boolean).map(function (d) { return { descricao: d }; });
            var dados = comCsrf({
                pedido_id: $b.find('[name=pedido_id]').val(), cliente_id: $b.find('#revClienteId').val(),
                motivo: $b.find('[name=motivo]').val(), tipo: $b.find('[name=tipo]').val(), processo: $b.find('[name=processo]').val(),
                endereco_coleta: coletarEnd($b, 'endereco_coleta'), itens: itens
            });
            var tid = Toast.loading('Registrando...');
            api('POST', '/solicitar', dados).done(function (r) {
                if (r && r.ok) { Toast.update(tid, { type: 'success', message: r.existente ? 'Já existe uma reversa ativa para este pedido.' : 'Reversa registrada.', duration: 3000 }); drawer.fechar('ok'); carregar(); }
                else Toast.update(tid, { type: 'error', message: (r && r.erro) || 'Falha.', duration: 4000 });
            }).fail(function () { Toast.update(tid, { type: 'error', message: 'Erro.', duration: 3000 }); });
        });
    }

    /* ------------------------------------------------ gerar etiqueta reversa */
    function abrirGerar(id) {
        // pega o endereço do cliente já salvo, para pré-preencher o remetente
        api('GET', '/obter', { id: id }).done(function (pre) {
            var end = (pre && pre.ok && pre.reversa && pre.reversa.endereco_coleta_json) || {};
            var transpOpts = '<option value="">Selecione...</option>' + TRANSP.map(function (t) { return '<option value="' + (t.id | 0) + '">' + esc(t.nome) + '</option>'; }).join('');
            var html = '<form class="log_form">' +
                '<div class="log_fieldset"><h4>Transporte do retorno</h4><div class="log_form_grid">' +
                    '<div class="log_field"><label>Transportadora *</label><select class="log_select" id="revTransp">' + transpOpts + '</select></div>' +
                    '<div class="log_field"><label>Serviço *</label><select class="log_select" id="revServico"><option value="">—</option></select></div>' +
                    '<div class="log_field"><label>Valor declarado (R$)</label><input class="log_input" name="valor_declarado" type="number" step="0.01"></div>' +
                    '<div class="log_field"><label>Formato</label><select class="log_select" name="formato"><option value="pdf">PDF</option><option value="termica">Térmica</option><option value="a4">A4</option></select></div>' +
                '</div></div>' +
                '<div class="log_fieldset"><h4>Remetente (cliente) <span class="log_muted">— confira o endereço da volta</span></h4>' + endFields('remetente') + '</div>' +
                '<div class="log_fieldset"><h4>Volumes <button type="button" class="log_btn log_btn--sm js-vol-add"><i class="bi bi-plus-lg"></i> Adicionar</button></h4><div id="revVolumes">' + volRow() + '</div></div>' +
            '</form>';

            var drawer = adminDrawer({ titulo: 'Gerar etiqueta de retorno', subtitulo: 'Reversa #' + id, tamanho: 'lg', conteudo: html,
                acoes: '<button type="button" class="log_btn log_btn--primary log_btn--sm js-gerar-ok"><i class="bi bi-tag"></i> Gerar</button>' });

            // pré-preenche remetente com o endereço salvo
            var $b = $(drawer.corpo());
            Object.keys(end).forEach(function (k) { $b.find('[data-e="remetente.' + k + '"]').val(end[k]); });

            drawer.escutar('change', '#revTransp', function (ev) {
                var tid = parseInt($(ev.target).val(), 10);
                var t = TRANSP.filter(function (x) { return (x.id | 0) === tid; })[0];
                var svs = (t && t.servicos) || [];
                $(drawer.corpo()).find('#revServico').html('<option value="">—</option>' + svs.map(function (s) { return '<option value="' + attr(s.codigo) + '" data-nome="' + attr(s.nome) + '">' + esc(s.nome || s.codigo) + '</option>'; }).join(''));
            });
            drawer.escutar('click', '.js-vol-add', function () { $(drawer.corpo()).find('#revVolumes').append(volRow()); });
            drawer.escutar('click', '.js-vol-rm', function (ev) { $(ev.target).closest('[data-vol]').remove(); });
            drawer.escutar('click', '.js-gerar-ok', function () {
                var $c = $(drawer.corpo());
                var dados = comCsrf({
                    id: id,
                    transportadora_id: $c.find('#revTransp').val(),
                    servico_codigo: $c.find('#revServico').val(),
                    servico_nome: $c.find('#revServico option:selected').data('nome') || 'Reversa',
                    valor_declarado: $c.find('[name=valor_declarado]').val(),
                    formato: $c.find('[name=formato]').val(),
                    remetente: coletarEnd($c, 'remetente'),
                    volumes: coletarVols($c)
                });
                if (!dados.transportadora_id) { Toast.warning('Escolha a transportadora.'); return; }
                if (!dados.servico_codigo) { Toast.warning('Escolha o serviço.'); return; }
                if (!dados.volumes.length) { Toast.warning('Adicione ao menos um volume.'); return; }
                var tid = Toast.loading('Gerando etiqueta reversa...');
                api('POST', '/gerar', dados).done(function (r) {
                    if (r && r.ok) { Toast.update(tid, { type: 'success', message: 'Etiqueta reversa gerada.', duration: 2800 }); if (r.url_pdf) window.open(r.url_pdf, '_blank'); drawer.fechar('ok'); carregar(); }
                    else Toast.update(tid, { type: 'error', message: (r && r.erro) || 'Falha ao gerar.', duration: 4500 });
                }).fail(function () { Toast.update(tid, { type: 'error', message: 'Erro.', duration: 3000 }); });
            });
        });
    }

    /* ------------------------------------------------ detalhe */
    function abrirDetalhe(id) {
        var tid = Toast.loading('Abrindo...');
        api('GET', '/obter', { id: id }).done(function (r) {
            Toast.dismiss(tid);
            if (!r || !r.ok) { Toast.error('Reversa não encontrada.'); return; }
            var e = r.reversa, st = STATUS[e.status] || ['is-neutral', e.status], acoes = e.acoes || [];
            var trackUrl = linkPublico(e.rastreio_token);

            var linksHtml = '';
            if (e.etiqueta_url_pdf) linksHtml += '<a href="' + attr(e.etiqueta_url_pdf) + '" target="_blank" class="log_btn log_btn--sm"><i class="bi bi-printer"></i> Etiqueta (PDF)</a> ';
            if (trackUrl) linksHtml += '<a href="' + attr(trackUrl) + '" target="_blank" class="log_btn log_btn--sm"><i class="bi bi-geo-alt"></i> Rastreio</a>';

            var instr = e.instrucoes || '';
            var instrHtml = instr ? (
                '<div class="log_fieldset"><h4>Instruções para o cliente</h4>' +
                '<textarea class="log_input" id="revInstr" rows="7" readonly>' + esc(instr) + '</textarea>' +
                '<div class="log_copybox" style="margin-top:8px">' +
                    '<button type="button" class="log_btn log_btn--sm js-copy" data-txt="' + attr(instr) + '"><i class="bi bi-clipboard"></i> Copiar</button>' +
                    '<a class="log_btn log_btn--sm" target="_blank" href="https://wa.me/?text=' + encodeURIComponent(instr) + '"><i class="bi bi-whatsapp"></i> WhatsApp</a>' +
                    '<a class="log_btn log_btn--sm" href="mailto:?subject=' + encodeURIComponent('Instruções de devolução') + '&body=' + encodeURIComponent(instr) + '"><i class="bi bi-envelope"></i> E-mail</a>' +
                '</div></div>'
            ) : '';

            var procHtml = '<div class="log_fieldset"><h4>Processo (troca / reembolso)</h4>' +
                '<div class="log_copybox"><select class="log_select" id="revProc">' +
                    ['nenhum', 'troca', 'reembolso'].map(function (p) { return '<option value="' + p + '"' + (e.processo === p ? ' selected' : '') + '>' + (p === 'nenhum' ? 'Nenhum' : (p === 'troca' ? 'Troca' : 'Reembolso')) + '</option>'; }).join('') +
                '</select><button type="button" class="log_btn log_btn--sm js-proc"><i class="bi bi-check-lg"></i> Salvar</button></div></div>';

            var cli = e.endereco_coleta_json || {};
            var cliLinha = (cli.nome || cli.telefone) ? '<p class="log_muted"><i class="bi bi-person"></i> ' + esc(cli.nome || '') + (cli.telefone ? ' · ' + esc(cli.telefone) : '') + '</p>' : '';

            var html = '<div class="log_form">' +
                '<div class="log_fieldset"><h4>Situação</h4>' +
                    '<p><span class="log_badge ' + st[0] + ' log_badge--plain">' + esc(st[1]) + '</span> · ' + esc(e.motivo_label || e.motivo) + ' · ' + (e.tipo === 'coleta' ? 'Coleta' : 'Postagem') + '</p>' +
                    cliLinha +
                    (e.transportadora_nome ? '<p class="log_muted">' + esc(e.transportadora_nome) + (e.codigo_rastreio ? ' · ' + esc(e.codigo_rastreio) : '') + '</p>' : '') +
                    (e.validade_em ? '<p class="log_muted">Postar até: ' + esc(e.validade_em) + '</p>' : '') +
                    (linksHtml ? '<div style="margin-top:8px">' + linksHtml + '</div>' : '') +
                '</div>' +
                instrHtml + procHtml +
            '</div>';

            // ações contextuais no cabeçalho do drawer
            var acHtml = '';
            if (acoes.indexOf('gerar_etiqueta') >= 0) acHtml += '<button type="button" class="log_btn log_btn--primary log_btn--sm js-a-gerar"><i class="bi bi-tag"></i> Gerar etiqueta</button> ';
            if (acoes.indexOf('sincronizar') >= 0) acHtml += '<button type="button" class="log_btn log_btn--sm js-a-sinc"><i class="bi bi-arrow-repeat"></i> Sincronizar</button> ';
            if (acoes.indexOf('marcar_recebida') >= 0) acHtml += '<button type="button" class="log_btn log_btn--sm js-a-receber"><i class="bi bi-box-seam"></i> Recebida</button> ';
            if (acoes.indexOf('cancelar') >= 0) acHtml += '<button type="button" class="log_btn log_btn--sm js-a-cancelar" style="color:var(--log-danger)"><i class="bi bi-x-circle"></i></button>';

            var drawer = adminDrawer({ titulo: 'Reversa #' + id, subtitulo: e.pedido_id ? 'Pedido #' + e.pedido_id : '', conteudo: html, acoes: acHtml, tamanho: 'md' });
            var fecharE = function () { drawer.fechar(); carregar(); };
            drawer.escutar('click', '.js-copy', function (ev) { copiar($(ev.target).closest('[data-txt]').data('txt')); });
            drawer.escutar('click', '.js-proc', function () {
                api('POST', '/processo', comCsrf({ id: id, processo: $(drawer.corpo()).find('#revProc').val() })).done(function (rr) {
                    if (rr && rr.ok) { Toast.success('Processo atualizado.'); carregar(); } else Toast.error((rr && rr.erro) || 'Falha.');
                });
            });
            drawer.escutar('click', '.js-a-gerar', function () { drawer.fechar(); abrirGerar(id); });
            drawer.escutar('click', '.js-a-sinc', function () {
                var t = Toast.loading('Sincronizando com o rastreio...');
                api('POST', '/sincronizar', comCsrf({ id: id })).done(function (rr) {
                    if (rr && rr.ok) Toast.update(t, { type: 'success', message: 'Status: ' + (STATUS[rr.reversa_status] ? STATUS[rr.reversa_status][1] : rr.reversa_status), duration: 2800 });
                    else Toast.update(t, { type: 'error', message: (rr && rr.erro) || 'Falha.', duration: 3500 });
                    fecharE();
                });
            });
            drawer.escutar('click', '.js-a-receber', function () { acaoSimples(id, '/receber', 'Marcada como recebida.').done(function () { drawer.fechar(); }); });
            drawer.escutar('click', '.js-a-cancelar', function () {
                confirmar('Cancelar reversa', 'Cancelar esta reversa? Se já houver etiqueta emitida, ela também será cancelada.', 'Cancelar', function (d) {
                    api('POST', '/cancelar', comCsrf({ id: id })).done(function (rr) {
                        if (rr && rr.ok) { Toast.success('Reversa cancelada.'); d.fechar(); drawer.fechar(); carregar(); }
                        else Toast.error((rr && rr.erro) || 'Falha.');
                    });
                });
            });
        }).fail(function () { Toast.update(tid, { type: 'error', message: 'Erro ao abrir.', duration: 3000 }); });
    }

    /* ------------------------------------------------ util */
    function copiar(txt) {
        if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(txt).then(function () { Toast.success('Copiado.'); }, function () { fb(txt); });
        else fb(txt);
        function fb(t) { var $i = $('<textarea>').val(t).appendTo('body').select(); try { document.execCommand('copy'); Toast.success('Copiado.'); } catch (e) { Toast.info('Copie manualmente.'); } $i.remove(); }
    }
    function confirmar(titulo, texto, rotuloOk, onOk) {
        var d = adminDrawer({ titulo: titulo, tamanho: 'sm', conteudo: '<p>' + esc(texto) + '</p>',
            acoes: '<button type="button" class="log_btn log_btn--sm js-c">Voltar</button> <button type="button" class="log_btn log_btn--primary log_btn--sm js-o" style="background:var(--log-danger);border-color:var(--log-danger)">' + esc(rotuloOk) + '</button>' });
        d.escutar('click', '.js-c', function () { d.fechar(); });
        d.escutar('click', '.js-o', function () { onOk(d); });
    }

    $(function () { if (!document.getElementById('logRev')) return; bind(); carregar(); });

})(jQuery);
