/* =====================================================================
   Logistica — front do modulo administrativo (jQuery v4).

   Arquivo unico, espelhando logistica.css: uma requisicao para todo o
   modulo, no lugar dos nove arquivos que o layout carregava em qualquer
   pagina /admin/logistica (mesmo usando so uma delas).

   Estrutura:
     1. LOG — nucleo compartilhado (ajax, escape, moeda, icones, csrf).
     2. Uma IIFE por tela, cada uma guardada pelo seu elemento raiz, de
        modo que so a tela presente na pagina se inicializa.

   Icones: o SVG vem do sprite <symbol> que o layout imprime via
   IconLibrary::sprite(). Aqui so se referencia por LOG.ico('chave') ou
   pelo markup <use href="#i-chave">. Nao ha dependencia de webfont.

   Dependencias: adminDrawer, Toast, window.CSRF_TOKEN, window.BASE_URL.
   ===================================================================== */
(function ($) {
    'use strict';

    /* ------------------------------------------------------------------
       Nucleo compartilhado. Antes cada arquivo redefinia esc/attr/comCsrf
       /api por conta propria (esc aparecia nove vezes); agora ha uma so
       implementacao e cada tela apenas delega.
       ------------------------------------------------------------------ */
    var LOG = {

        esc: function (s) {
            return $('<i>').text(s == null ? '' : String(s)).html();
        },

        attr: function (s) {
            return LOG.esc(s).replace(/"/g, '&quot;');
        },

        // Anexa o token CSRF ao corpo dos POSTs (os dois nomes cobrem verifyCsrf()).
        comCsrf: function (o) {
            o = o || {};
            o.csrf_token = window.CSRF_TOKEN || '';
            o._token = window.CSRF_TOKEN || '';
            return o;
        },

        moeda: function (v) {
            return 'R$ ' + (parseFloat(v) || 0).toFixed(2).replace('.', ',');
        },

        ajax: function (metodo, url, dados) {
            return $.ajax({
                url: url,
                method: metodo,
                dataType: 'json',
                data: dados || {},
                headers: { 'X-CSRF-TOKEN': window.CSRF_TOKEN || '' }
            });
        },

        /* Referencia um icone do sprite impresso pelo layout.
           Decorativo por padrao (aria-hidden): quem da nome ao controle e o
           texto do botao ou seu aria-label. */
        ico: function (nome, classe) {
            return '<svg class="' + (classe || 'log_ico') + '" aria-hidden="true" focusable="false">' +
                   '<use href="#i-' + String(nome).replace(/[^a-z0-9_-]/gi, '') + '"></use></svg>';
        }
    };


    /* ==================================================================
       TELA: Torre de Controle  (logistica.js)
    ================================================================== */
    (function () {

    var $shell = $('#logTorre');
    if (!$shell.length) return;

    var endpoint = ($shell.data('endpoint') || '/admin/logistica/torre/dados');
    var base     = (window.BASE_URL || '').replace(/\/$/, '');
    var POLL_MS  = 30000;     // aba ativa
    var timer    = null;

    /* ---------- helpers de formatação (PT-BR) ---------- */
    function nInt(v)  { return Number(v || 0).toLocaleString('pt-BR'); }
    function nDec(v)  { return Number(v || 0).toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }); }
    function nBRL(v)  { return 'R$ ' + Number(v || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function esc(s) { return LOG.esc(s); }

    /* ---------- coleta filtros do form ---------- */
    function filtros() {
        var out = {};
        $('#logFiltros').find('select, input').each(function () {
            var v = $(this).val();
            if (v !== '' && v != null) out[this.name] = v;
        });
        return out;
    }

    /* ---------- reconstrução dos KPIs (espelha a view PHP) ---------- */
    function cardKpi(ico, cls, val, lbl, accent) {
        return '<div class="log_kpi' + (accent ? ' log_kpi--accent' : '') + '">' +
               '<div class="log_kpi_ico ' + cls + '">' + LOG.ico(ico) + '</div>' +
               '<div class="log_kpi_val">' + val + '</div>' +
               '<div class="log_kpi_lbl">' + esc(lbl) + '</div></div>';
    }
    function cardBloqueado(lbl) {
        return '<div class="log_kpi"><div class="log_kpi_ico is-neutral"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-lock"></use></svg></div>' +
               '<div class="log_kpi_val log_muted" style="font-size:16px">restrito</div>' +
               '<div class="log_kpi_lbl">' + esc(lbl) + '</div></div>';
    }
    function renderKpis(k) {
        var h = '';
        h += cardKpi('package', '', nInt(k.total_envios), 'Total de envios', true);
        h += cardKpi('check-circle', 'is-ok', nInt(k.entregues), 'Entregues');
        h += cardKpi('caminhao', 'is-info', nInt(k.em_transito), 'Em trânsito');
        h += cardKpi('relogio', 'is-ok', nDec(k.no_prazo_pct) + '<small>%</small>', 'No prazo');
        h += cardKpi('alerta', 'is-danger', nInt(k.atrasados), 'Atrasados');
        h += cardKpi('flag', 'is-warn', nInt(k.ocorrencias), 'Com ocorrências');
        h += cardKpi('printer', 'is-neutral', nInt(k.etiquetas_aguardando), 'Etiquetas aguardando postagem');
        h += cardKpi('undo', '', nInt(k.reversas_abertas), 'Solicitações de reversa');
        h += cardKpi('calendar-today', 'is-neutral', nDec(k.prazo_medio) + '<small>d</small>', 'Prazo médio de entrega');
        h += cardKpi('wifi-off', 'is-warn', nInt(k.falhas_integracao), 'Falhas de integração');

        if (k.gasto_fretes === null || k.gasto_fretes === undefined) {
            h += cardBloqueado('Gasto com fretes');
            h += cardBloqueado('Divergências acumuladas');
        } else {
            h += cardKpi('cash', 'is-info', nBRL(k.gasto_fretes), 'Gasto com fretes');
            h += cardKpi('scale', 'is-danger', nBRL(k.divergencias_valor), 'Divergências acumuladas');
        }
        $('#logKpis').html(h);
    }

    /* ---------- distribuição ---------- */
    function renderDist(dist) {
        var max = 1, i;
        for (i = 0; i < dist.length; i++) max = Math.max(max, Number(dist[i].qtd || 0));
        var h = '';
        for (i = 0; i < dist.length; i++) {
            var d = dist[i];
            var alt = Math.max(4, Math.round((Number(d.qtd || 0) / max) * 100));
            h += '<div class="log_dist_col"><div class="log_dist_val">' + nInt(d.qtd) + '</div>' +
                 '<div class="log_dist_bar"' + (d.atraso ? ' data-late="1"' : '') + ' style="height:' + alt + '%"></div>' +
                 '<div class="log_dist_lbl">' + esc(d.rotulo) + '</div></div>';
        }
        $('#logDist').html(h);
    }

    /* ---------- alertas ---------- */
    function renderAlertas(alertas) {
        if (!alertas || !alertas.length) {
            $('#logAlertas').html(
                '<div class="log_state" style="padding:28px 12px">' +
                '<div class="log_state_ico"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-check-circle"></use></svg></div>' +
                '<div class="log_state_title">Tudo sob controle</div>' +
                '<div class="log_state_desc">Nenhum alerta operacional no momento.</div></div>'
            );
            return;
        }
        var h = '', i;
        for (i = 0; i < alertas.length; i++) {
            var a = alertas[i];
            h += '<div class="log_alert is-' + esc(a.nivel || 'info') + '">' +
                 '<div class="log_alert_ico">' + LOG.ico(a.icone || 'info') + '</div>' +
                 '<div class="log_alert_body"><div class="log_alert_title">' + esc(a.titulo) + '</div>' +
                 '<div class="log_alert_desc">' + esc(a.descricao) + '</div></div>' +
                 '<a href="' + esc(a.link || '#') + '" class="log_btn log_btn--sm"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-arrow-forward"></use></svg> Ver</a></div>';
        }
        $('#logAlertas').html(h);
    }

    /* ---------- busca ---------- */
    function carregar(mostrarSpinner) {
        if (mostrarSpinner) $shell.addClass('is-loading');
        $.ajax({
            url: base + endpoint,
            method: 'GET',
            data: filtros(),
            dataType: 'json'
        }).done(function (res) {
            if (!res || !res.ok || !res.dados) { falhou(); return; }
            var d = res.dados;
            renderKpis(d.kpis);
            renderDist(d.distribuicao);
            renderAlertas(d.alertas);
            if (d.periodo) $('#logAtualizado').text(d.periodo);
        }).fail(function () {
            falhou();
        }).always(function () {
            $shell.removeClass('is-loading');
        });
    }

    function falhou() {
        if (window.Toast) {
            Toast.error('Não foi possível atualizar a Torre. Tente novamente.');
        }
    }

    /* ---------- polling adaptativo (pausa em background) ---------- */
    function iniciarPolling() {
        pararPolling();
        if (document.visibilityState === 'visible') {
            timer = setInterval(function () { carregar(false); }, POLL_MS);
        }
    }
    function pararPolling() { if (timer) { clearInterval(timer); timer = null; } }

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            carregar(false);   // refresh imediato ao voltar
            iniciarPolling();
        } else {
            pararPolling();
        }
    });

    /* ---------- eventos de UI ---------- */
    $('#logAplicar').on('click', function () { carregar(true); iniciarPolling(); });
    $('#logRefresh').on('click', function () { carregar(true); });
    $('#logFiltros').on('change', 'select', function () { carregar(true); });

    // Primeira carga já veio renderizada do servidor; só arma o polling.
    iniciarPolling();
    })();

    /* ==================================================================
       TELA: Transportadoras  (transportadoras.js)
    ================================================================== */
    (function () {

    var BASE = window.LOG_TRANSP_BASE || '/admin/logistica/transportadoras';
    var CAT  = window.LOG_CATALOGO || {};

    /* ------------------------------------------------------------ util */

    function api(method, path, data) { return LOG.ajax(method, BASE + path, data); }
    // Anexa o token CSRF ao corpo dos POSTs (nomes comuns cobrem verifyCsrf()).
    function comCsrf(obj) { return LOG.comCsrf(obj); }

    function esc(s) { return LOG.esc(s); }
    function attr(s) { return LOG.attr(s); }

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
            : '<span class="log_transp_ph"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-caminhao"></use></svg></span>';
        var sync = t.ultima_sync ? formatarData(t.ultima_sync) : '—';
        var label = t.adapter_label || t.adapter;

        return '' +
        '<tr data-id="' + (t.id | 0) + '" data-status="' + attr(t.status) + '">' +
            '<td><div class="log_ordem">' +
                '<button type="button" class="log_btn log_btn--icon log_btn--xs js-mover" data-dir="cima" title="Subir" aria-label="Subir" title="Subir prioridade" aria-label="Subir prioridade"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-arrow-up"></use></svg></button>' +
                '<button type="button" class="log_btn log_btn--icon log_btn--xs js-mover" data-dir="baixo" title="Descer" aria-label="Descer" title="Descer prioridade" aria-label="Descer prioridade"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-arrow-down"></use></svg></button>' +
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
                '<button type="button" class="log_btn log_btn--icon js-testar" title="Testar conexão" aria-label="Testar conexão"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-plug"></use></svg></button>' +
                '<button type="button" class="log_btn log_btn--icon js-logs" title="Ver logs" aria-label="Ver logs"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-stacks"></use></svg></button>' +
                '<button type="button" class="log_btn log_btn--icon js-editar" title="Editar" aria-label="Editar"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-pencil"></use></svg></button>' +
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
                    '<div class="log_state_ico"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-caminhao"></use></svg></div>' +
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

    /* ---------------------------------------------------- credenciais

       Segredo já salvo NÃO nasce como input.

       Em 08/09/2026 as duas senhas dos Correios foram substituídas pela senha
       de login do painel: o Chrome ignora `autocomplete="off"` em campo
       `type=password` e autopreencheu os dois. Chegaram não-vazias no POST e a
       regra da época ("vazio mantém o salvo") deixou passar.

       Agora o campo guardado é um chip com o botão "Alterar". Sem input não há
       o que o gerenciador de senhas preencher — e quando o operador abre o
       campo de propósito, o nome entra em config_alterar[], que é o que
       autoriza o backend a gravar. Ausência passou a significar "mantém", e
       isso não depende de o navegador se comportar.

       O input aberto é type=text mascarado por CSS, não type=password, para
       não acordar o gerenciador de senhas. O olho revela — ver o que se digita
       é metade de não errar.                                                */

    var ANTI_AUTOFILL =
        ' autocomplete="new-password" autocorrect="off" autocapitalize="off"' +
        ' spellcheck="false" data-lpignore="true" data-1p-ignore data-bwignore' +
        ' data-form-type="other"';

    function inputSecreto(nome, valor) {
        return '<div class="log_secret_edit">' +
            '<input type="text" class="log_input log_input--mascarado"' +
                ' name="config[' + nome + ']" value="' + attr(valor || '') + '"' +
                ANTI_AUTOFILL + '>' +
            '<button type="button" class="log_btn log_btn--xs js-cred-ver"' +
                ' aria-pressed="false">Mostrar</button>' +
        '</div>';
    }

    /** Corpo do campo secreto conforme o estado: guardado | alterando | removendo. */
    function corpoSecreto(c, estado) {
        if (estado === 'alterando') {
            return inputSecreto(c.nome, '') +
                '<span class="log_secret_aviso">Vai substituir o valor salvo.' +
                ' <button type="button" class="log_link js-cred-cancelar">Cancelar</button></span>';
        }
        // .log_field é coluna flex: sem esta linha própria o chip e os botões
        // viram três blocos empilhados na largura toda.
        if (estado === 'removendo') {
            return '<div class="log_secret_acoes">' +
                '<span class="log_secret_chip is-remover">Será apagado ao salvar</span>' +
                '<button type="button" class="log_btn log_btn--xs js-cred-desfazer">Desfazer</button>' +
            '</div>';
        }
        return '<div class="log_secret_acoes">' +
            '<span class="log_secret_chip is-salvo">' +
                '<svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-check-circle"></use></svg>' +
                'Salvo' +
            '</span>' +
            '<button type="button" class="log_btn log_btn--xs js-cred-alterar">Alterar</button>' +
            (c.obrigatorio ? '' :
                '<button type="button" class="log_btn log_btn--xs log_btn--danger js-cred-remover">Remover</button>') +
        '</div>';
    }

    function campoHtml(c, temSalvo, valorTexto) {
        var secreto = c.tipo === 'secret';
        var col     = c.col === 'curta' ? ' log_field--curta' : (c.col === 'larga' ? ' log_field--larga' : '');
        var ajuda   = c.ajuda ? '<span class="log_hint">' + esc(c.ajuda) + '</span>' : '';

        var corpo;
        if (!secreto) {
            corpo = '<input type="text" class="log_input" name="config[' + c.nome + ']"' +
                    ' value="' + attr(valorTexto || '') + '" autocomplete="off">';
        } else if (temSalvo) {
            corpo = corpoSecreto(c, 'guardado');
        } else {
            corpo = inputSecreto(c.nome, '');
        }

        return '<div class="log_field' + col + (secreto ? ' log_field--secreto' : '') + '"' +
                ' id="fld-config-' + c.nome + '"' +
                ' data-campo="' + attr(c.nome) + '"' +
                ' data-secreto="' + (secreto ? 1 : 0) + '"' +
                ' data-tem-salvo="' + (temSalvo ? 1 : 0) + '"' +
                ' data-obrigatorio="' + (c.obrigatorio ? 1 : 0) + '">' +
            '<label>' + esc(c.label) + (c.obrigatorio ? ' <span class="log_req">*</span>' : '') + '</label>' +
            corpo + ajuda +
        '</div>';
    }

    function camposCredencial(adapter, preenchida, cfg) {
        var campos = (CAT[adapter] && CAT[adapter].campos) || [];
        var grupos = (CAT[adapter] && CAT[adapter].grupos) || {};
        preenchida = preenchida || {};
        cfg = cfg || {};

        if (!campos.length) {
            return '<p class="log_muted">Este adapter não requer credenciais.</p>';
        }

        // Sem grupos declarados, uma seção só e sem título. O wrapper .log_grupo
        // vem junto porque é ele que carrega o grid de larguras (curta/larga).
        if (!Object.keys(grupos).length) {
            return '<div class="log_grupo"><div class="log_form_grid">' + campos.map(function (c) {
                return campoHtml(c, !!preenchida[c.nome], cfg[c.nome]);
            }).join('') + '</div></div>';
        }

        // Com grupos: uma seção por grupo, na ordem declarada no catálogo.
        var ordem = Object.keys(grupos);
        var soltos = campos.filter(function (c) { return ordem.indexOf(c.grupo) < 0; });
        var html = ordem.map(function (g) {
            var doGrupo = campos.filter(function (c) { return c.grupo === g; });
            if (!doGrupo.length) { return ''; }
            return '<div class="log_grupo">' +
                '<h5 class="log_grupo_tit">' + esc(grupos[g]) + '</h5>' +
                '<div class="log_form_grid">' + doGrupo.map(function (c) {
                    return campoHtml(c, !!preenchida[c.nome], cfg[c.nome]);
                }).join('') + '</div>' +
            '</div>';
        }).join('');

        if (soltos.length) {
            html += '<div class="log_grupo"><div class="log_form_grid">' +
                soltos.map(function (c) { return campoHtml(c, !!preenchida[c.nome], cfg[c.nome]); }).join('') +
            '</div></div>';
        }
        return html;
    }

    function servicoRow(s) {
        s = s || {};
        return '<div class="log_svc">' +
            '<input class="log_input" data-k="codigo" placeholder="Código" value="' + attr(s.codigo || '') + '">' +
            '<input class="log_input" data-k="nome" placeholder="Nome" value="' + attr(s.nome || '') + '">' +
            '<input class="log_input" data-k="modalidade" placeholder="Modalidade" value="' + attr(s.modalidade || '') + '">' +
            '<input class="log_input log_svc_num" data-k="prazo_adicional" type="number" title="Prazo +dias" value="' + (parseInt(s.prazo_adicional, 10) || 0) + '">' +
            '<label class="log_check log_svc_chk"><input type="checkbox" data-k="habilitado"' + (s.habilitado == 1 || s.habilitado === true ? ' checked' : '') + '> ativo</label>' +
            '<button type="button" class="log_btn log_btn--icon log_btn--xs log_btn--danger js-svc-rm" title="Remover" aria-label="Remover"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-close"></use></svg></button>' +
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
                '<div id="logCredenciais">' + camposCredencial(adapter, preenchida, cfg) + '</div>' +
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

            '<div class="log_fieldset"><h4>Serviços <button type="button" class="log_btn log_btn--sm js-svc-add"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-add"></use></svg> Adicionar</button></h4>' +
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

        // Intenção explícita sobre cada segredo. O estado mora na classe do
        // campo, não num input escondido — hidden que espelha estado é hidden
        // que dessincroniza. Campo guardado não tem input, então nem aparece
        // no laço acima: para o backend ele simplesmente não veio, e não veio
        // significa "mantém o que está salvo".
        d.config_alterar = [];
        d.config_remover = [];
        $b.find('.log_field[data-secreto="1"]').each(function () {
            var $f = $(this), nome = String($f.data('campo') || '');
            if (!nome) { return; }
            if ($f.hasClass('is-alterando') || $f.data('tem-salvo') !== 1) { d.config_alterar.push(nome); }
            if ($f.hasClass('is-removendo')) { d.config_remover.push(nome); }
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
            acoes: '<button type="button" class="log_btn log_btn--primary log_btn--sm js-salvar"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-check"></use></svg> Salvar</button>'
        });

        // Troca de adapter -> recarrega credenciais + ambientes + descrição.
        // Voltar ao adapter original restaura o que estava salvo; qualquer
        // outro começa em branco, porque a credencial de um adapter não vale
        // para outro.
        var adapterOrig = (t && t.adapter) || null;
        drawer.escutar('change', '.js-adapter', function () {
            var $b = $(drawer.corpo());
            var adapter = $b.find('.js-adapter').val();
            var voltou  = adapter === adapterOrig;
            $b.find('#logCredenciais').html(camposCredencial(
                adapter,
                voltou ? ((t && t.config_preenchida) || {}) : {},
                voltou ? ((t && t.config) || {}) : {}
            ));
            $b.find('.js-ambiente').html(opcoesAmbiente(adapter, $b.find('.js-ambiente').val()));
            $b.find('.js-adapter-desc').text((CAT[adapter] && CAT[adapter].descricao) || '');
        });

        /* -------- intenção sobre segredos -------- */

        function trocarEstado($campo, estado) {
            var nome = String($campo.data('campo') || '');
            // O fallback lê data-obrigatorio do próprio campo: sem isso, um
            // catálogo que não resolvesse ofereceria "Remover" num segredo
            // obrigatório, que é o botão que não pode existir ali.
            var c = ((CAT[$(drawer.corpo()).find('.js-adapter').val()] || {}).campos || [])
                    .filter(function (x) { return x.nome === nome; })[0] ||
                    { nome: nome, obrigatorio: $campo.data('obrigatorio') === 1 };
            $campo.removeClass('is-alterando is-removendo')
                  .addClass(estado === 'guardado' ? '' : 'is-' + estado);
            $campo.find('.log_secret_edit, .log_secret_acoes, .log_secret_aviso').remove();
            $campo.find('label').after(corpoSecreto(c, estado));
            if (estado === 'alterando') { $campo.find('input').trigger('focus'); }
        }

        drawer.escutar('click', '.js-cred-alterar', function (ev) {
            trocarEstado($(ev.target).closest('.log_field'), 'alterando');
        });
        drawer.escutar('click', '.js-cred-cancelar', function (ev) {
            trocarEstado($(ev.target).closest('.log_field'), 'guardado');
        });
        drawer.escutar('click', '.js-cred-remover', function (ev) {
            trocarEstado($(ev.target).closest('.log_field'), 'removendo');
        });
        drawer.escutar('click', '.js-cred-desfazer', function (ev) {
            trocarEstado($(ev.target).closest('.log_field'), 'guardado');
        });

        // Olho: revela o que está sendo digitado. Ver o valor é metade de não
        // errar — e o campo só existe depois de um clique deliberado.
        drawer.escutar('click', '.js-cred-ver', function (ev) {
            var $btn = $(ev.target).closest('.js-cred-ver');
            var $inp = $btn.closest('.log_secret_edit').find('input');
            var oculto = $inp.hasClass('log_input--mascarado');
            $inp.toggleClass('log_input--mascarado', !oculto);
            $btn.text(oculto ? 'Ocultar' : 'Mostrar')
                .attr('aria-pressed', oculto ? 'true' : 'false');
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
            linhas = '<tr><td colspan="5"><div class="log_state"><div class="log_state_ico"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-inbox"></use></svg></div>' +
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

        // Limpar cache de frete (invalida cotações; CEP é preservado)
        $('#logLimparCache').on('click', function () {
            var $btn = $(this).prop('disabled', true);
            var tid = Toast.loading('Limpando cache de frete...');
            api('POST', '/limpar-cache', comCsrf({})).done(function (r) {
                if (r && r.ok) {
                    Toast.update(tid, { type: 'success', message: 'Cache de frete limpo (' + (r.removidos || 0) + ' cotação(ões) removida(s)).', duration: 3500 });
                } else {
                    Toast.update(tid, { type: 'error', message: (r && r.erro) || 'Falha ao limpar o cache.', duration: 4000 });
                }
            }).fail(function () {
                Toast.update(tid, { type: 'error', message: 'Erro ao limpar o cache.', duration: 3500 });
            }).always(function () { $btn.prop('disabled', false); });
        });

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
    })();

    /* ==================================================================
       TELA: Regras de frete e Simulador  (frete.js)
    ================================================================== */
    (function () {

    /* --------------------------------------------------------- util */
    function api(base, method, path, data) { return LOG.ajax(method, base + path, data); }
    function comCsrf(o) { return LOG.comCsrf(o); }
    function esc(s) { return LOG.esc(s); }
    function attr(s) { return LOG.attr(s); }
    function moeda(v) { return LOG.moeda(v); }

    /* =========================================================
       REGRAS
       ========================================================= */
    var RBASE   = window.LOG_REGRAS_BASE || '/admin/logistica/regras';
    var OPERS   = window.LOG_REGRAS_OPERS || [];
    var TRANSP  = window.LOG_REGRAS_TRANSP || [];

    // `transportadora` sai da lista genérica: virou o seletor de escopo no alto
    // do formulário. Deixar nos dois lugares seria dar duas fontes para a mesma
    // informação, e elas divergem no primeiro ajuste.
    var CAMPOS  = (window.LOG_REGRAS_CAMPOS || []).filter(function (k) { return k !== 'transportadora'; });

    function condRow(c) {
        c = c || {};
        var val = c.valor != null ? (Array.isArray(c.valor) ? c.valor.join(',') : c.valor) : '';
        var campos = CAMPOS.map(function (k) { return '<option value="' + k + '"' + (k === c.campo ? ' selected' : '') + '>' + k + '</option>'; }).join('');
        var opers = OPERS.map(function (o) { return '<option value="' + o + '"' + (o === c.operador ? ' selected' : '') + '>' + o + '</option>'; }).join('');
        return '<div class="log_cond">' +
            '<select class="log_select" data-k="campo">' + campos + '</select>' +
            '<select class="log_select" data-k="operador">' + opers + '</select>' +
            '<input class="log_input" data-k="valor" value="' + attr(val) + '" placeholder="ex.: 299 · SP,RJ · 90000000,91999999">' +
            '<button type="button" class="log_btn log_btn--icon log_btn--xs log_btn--danger js-cond-rm" title="Remover" aria-label="Remover"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-close"></use></svg></button>' +
        '</div>';
    }

    function acaoNum(nome, label, ph) {
        return '<div class="log_field"><label>' + label + '</label><input class="log_input" type="number" step="0.01" data-a="' + nome + '" placeholder="' + (ph || '') + '"></div>';
    }

    /**
     * Selo de escopo da linha da lista.
     *
     * Escopo vazio não é "sem configuração", é "vale para todas" — o estado
     * que mexe no preço de transportadora que cota por API. Por isso sai em
     * amarelo e por extenso, não como ausência.
     */
    function escopoChip(nomes) {
        if (nomes && nomes.length) {
            return '<span class="log_muted reg_escopo_chip">' + esc(nomes.join(', ')) + '</span>';
        }
        return '<span class="log_badge is-warn log_badge--plain reg_escopo_chip">todas as transportadoras</span>';
    }

    /** Ids/slugs de transportadora já no escopo da regra. */
    function escopoDe(r) {
        var out = [];
        ((r && r.condicoes) || []).forEach(function (c) {
            if (c.campo !== 'transportadora') return;
            var v = c.valor;
            (Array.isArray(v) ? v : String(v == null ? '' : v).split(','))
                .forEach(function (x) { x = String(x).trim(); if (x) out.push(x); });
        });
        return out;
    }

    /**
     * Seletor de escopo — quais transportadoras a regra atinge.
     *
     * Fica no alto e é obrigatório porque, sem ele, a regra vale para TODAS:
     * `MotorRegras::opcaoNoEscopo()` termina em "sem condição de escopo =>
     * aplica a todas". A única transportadora cujo preço é nosso é a
     * LogManager (preço fixo por área); nas outras o valor vem da API delas, e
     * um desconto global mexe na margem sem ninguém ter decidido isso.
     */
    function escopoFieldset(r) {
        var marcados = escopoDe(r);
        if (!TRANSP.length) {
            return '<div class="log_fieldset" id="fld-escopo"><h4>Transportadoras da regra</h4>' +
                   '<p class="log_muted">Nenhuma transportadora cadastrada.</p></div>';
        }
        return '<div class="log_fieldset" id="fld-escopo">' +
            '<h4>Selecionar transportadoras para a regra <span class="log_req">*</span></h4>' +
            '<p class="log_muted reg_escopo_nota">A regra só age nas marcadas. ' +
            'Sem marcar nenhuma ela valeria para todas — inclusive as que cotam por API, ' +
            'onde alterar o preço mexe na margem sem decisão.</p>' +
            '<div class="reg_escopo">' + TRANSP.map(function (t) {
                var id = String(t.id);
                var on = marcados.indexOf(id) >= 0 || marcados.indexOf(String(t.slug)) >= 0;
                var fixo = t.adapter === 'LogManagerAdapter';
                return '<label class="log_toggle log_toggle--sm reg_escopo_item' + (on ? ' is-on' : '') + '"' +
                    ' title="A regra age nesta transportadora?">' +
                    '<input type="checkbox" class="js-escopo" value="' + attr(id) + '"' + (on ? ' checked' : '') + '>' +
                    '<span class="log_toggle_track"></span>' +
                    '<span class="reg_escopo_nome">' + esc(t.nome) + '</span>' +
                    '<span class="log_badge ' + (fixo ? 'is-ok' : 'is-neutral') + ' log_badge--plain">' +
                        (fixo ? 'preço próprio' : 'preço da API') + '</span>' +
                    (String(t.status) !== 'ativo' ? '<span class="log_muted reg_escopo_st">' + esc(t.status) + '</span>' : '') +
                '</label>';
            }).join('') + '</div>' +
        '</div>';
    }

    function buildRegraForm(r) {
        r = r || {};
        var a = r.acoes || {};
        // As condições de escopo não entram na lista genérica — elas têm o
        // seletor acima.
        var conds = (r.condicoes || [])
            .filter(function (c) { return c.campo !== 'transportadora'; })
            .map(condRow).join('');
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

            escopoFieldset(r) +

            '<div class="log_fieldset"><h4>Condições <span class="log_muted">(todas precisam bater — AND)</span> <button type="button" class="log_btn log_btn--sm js-cond-add"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-add"></use></svg> Adicionar</button></h4>' +
                '<div id="logConds">' + conds + '</div>' +
                '<p class="log_muted" style="margin-top:6px">Quando disparar. O campo <code>modalidade</code> ' +
                'restringe o efeito (postagem/coleta) sem disparar a regra; a transportadora fica no seletor acima.</p>' +
            '</div>' +

            '<div class="log_fieldset" id="fld-acoes"><h4>Ações</h4>' +
                '<div class="log_checks">' +
                    chk('frete_gratis', !!a.frete_gratis, 'Frete grátis') +
                    chk('frete_gratis_mais_barato', !!a.frete_gratis_mais_barato, 'Frete grátis mais barato') +
                    chk('bloquear_frete_gratis', !!a.bloquear_frete_gratis, 'Bloquear frete grátis') +
                    // lia `bloquear_frete_gratis` por copia-e-cola: a caixa de
                    // "Bloquear frete" nascia marcada por causa da ação vizinha
                    chk('bloquear_frete', !!a.bloquear_frete, 'Bloquear frete') +
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
        // escopo -> vira uma condição `transportadora in [ids]`, que é o que o
        // MotorRegras já sabia ler. O seletor é só uma forma melhor de escrever
        // a mesma coisa; o motor não mudou.
        var ids = $b.find('.js-escopo:checked').map(function () { return this.value; }).get();
        if (ids.length) {
            d.condicoes.push({ campo: 'transportadora', operador: 'in', valor: ids.join(',') });
        }
        return d;
    }

    function abrirRegraForm(r) {
        var drawer = adminDrawer({
            titulo: r && r.id ? 'Editar regra' : 'Nova regra',
            subtitulo: r && r.id ? (r.nome || '') : 'Motor de frete',
            conteudo: buildRegraForm(r),
            tamanho: 'lg',
            acoes: '<button type="button" class="log_btn log_btn--primary log_btn--sm js-salvar"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-check"></use></svg> Salvar</button>'
        });
        var $b = $(drawer.corpo());
        preencherAcoes($b, (r && r.acoes) || {});

        drawer.escutar('click', '.js-cond-add', function () { $(drawer.corpo()).find('#logConds').append(condRow({})); });
        drawer.escutar('click', '.js-cond-rm', function (ev) { $(ev.target).closest('.log_cond').remove(); });
        drawer.escutar('change', '.js-escopo', function (ev) {
            var $i = $(ev.target);
            $i.closest('.reg_escopo_item').toggleClass('is-on', $i.is(':checked'));
            $(drawer.corpo()).find('#fld-escopo').removeClass('log_fieldset--erro');
        });
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
                    $bb.find('.log_field--erro, .log_fieldset--erro')
                       .removeClass('log_field--erro log_fieldset--erro');
                    if (k === 'nome') $bb.find('#fld-nome').addClass('log_field--erro');
                    if (k === 'acoes') $bb.find('#fld-acoes').addClass('log_fieldset--erro');
                    if (k === 'escopo') {
                        $bb.find('#fld-escopo').addClass('log_fieldset--erro')[0]
                           .scrollIntoView({ block: 'center', behavior: 'smooth' });
                    }
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
                '<button type="button" class="log_btn log_btn--icon log_btn--xs js-mover" data-dir="cima" title="Subir" aria-label="Subir"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-arrow-up"></use></svg></button>' +
                '<button type="button" class="log_btn log_btn--icon log_btn--xs js-mover" data-dir="baixo" title="Descer" aria-label="Descer"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-arrow-down"></use></svg></button>' +
            '</div></td>' +
            '<td><div class="log_transp_info"><strong>' + esc(r.nome) + acum + '</strong>' +
                (r.descricao ? '<span class="log_muted">' + esc(r.descricao) + '</span>' : '') +
                escopoChip(r.escopo_nomes) + '</div></td>' +
            '<td><span class="log_mono">' + (parseInt(r.condicoes_qtd, 10) || 0) + '</span></td>' +
            '<td><div class="log_chips">' + chips + '</div></td>' +
            '<td><label class="log_toggle"><input type="checkbox" class="js-status"' + (r.ativa == 1 ? ' checked' : '') + '>' +
                '<span class="log_toggle_track"></span>' +
                '<span class="log_toggle_txt log_badge ' + st[0] + ' log_badge--plain js-status-txt">' + st[1] + '</span></label></td>' +
            '<td class="log_col_acoes">' +
                '<button type="button" class="log_btn log_btn--icon js-editar" title="Editar" aria-label="Editar"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-pencil"></use></svg></button>' +
                '<button type="button" class="log_btn log_btn--icon log_btn--danger js-remover" title="Remover" aria-label="Remover"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-delete"></use></svg></button>' +
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
            '<button type="button" class="log_btn log_btn--icon log_btn--xs log_btn--danger js-item-rm" title="Remover item" aria-label="Remover item"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-close"></use></svg></button>' +
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
    })();

    /* ==================================================================
       TELA: Etiquetas  (etiquetas.js)
    ================================================================== */
    (function () {

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
    function api(method, path, data) { return LOG.ajax(method, BASE + path, data); }
    function comCsrf(o) { return LOG.comCsrf(o); }
    function esc(s) { return LOG.esc(s); }
    function attr(s) { return LOG.attr(s); }
    function moeda(v) { return LOG.moeda(v); }

    // Busca o AR Eletrônico e abre a imagem (ou PDF) numa modal do adminDrawer.
    function verAr(id) {
        var tid = Toast.loading('Buscando AR...');
        api('POST', '/ar', comCsrf({ id: id })).done(function (r) {
            Toast.dismiss(tid);
            if (!r || !r.ok || !r.imagem_base64) {
                Toast.error((r && r.erro) || 'AR não disponível para este objeto.');
                return;
            }
            var b64 = String(r.imagem_base64).replace(/\s/g, '');
            var mime = b64.indexOf('iVBOR') === 0 ? 'image/png'
                : (b64.indexOf('JVBER') === 0 ? 'application/pdf' : 'image/jpeg');
            var src = 'data:' + mime + ';base64,' + b64;
            var conteudo = mime === 'application/pdf'
                ? '<iframe src="' + src + '" style="width:100%;height:72vh;border:0;background:#525659;border-radius:8px;"></iframe>'
                : '<div style="text-align:center"><img src="' + src + '" alt="Aviso de Recebimento" style="max-width:100%;height:auto;border-radius:8px;box-shadow:0 2px 12px rgba(0,0,0,.15)"></div>';
            adminDrawer({
                titulo: 'AR — Aviso de Recebimento',
                subtitulo: r.codigo ? 'Objeto ' + r.codigo : '',
                conteudo: conteudo,
                tamanho: 'md'
            });
        }).fail(function () {
            Toast.update(tid, { type: 'error', message: 'Erro ao buscar o AR.', duration: 3500 });
        });
    }

    // Fluxo de 2 etapas do rótulo (Correios): solicita e depois baixa; abre no modal.
    function imprimirEtiqueta(id, tentativa) {
        var tid = Toast.loading(tentativa ? 'Gerando etiqueta...' : 'Solicitando rótulo...');
        api('POST', '/imprimir', comCsrf({ id: id })).done(function (r) {
            if (r && r.ok && r.url_pdf) { Toast.dismiss(tid); abrirPdfModal(r.url_pdf); carregar(); return; }
            if (r && r.processando) {
                if (tentativa < 6) {
                    Toast.update(tid, { type: 'loading', message: 'Rótulo em processamento... aguarde' });
                    setTimeout(function () { Toast.dismiss(tid); imprimirEtiqueta(id, tentativa + 1); }, 3000);
                } else {
                    Toast.update(tid, { type: 'warning', message: 'Rótulo ainda gerando. Clique em PDF de novo em instantes.', duration: 5000 });
                }
                return;
            }
            Toast.update(tid, { type: 'error', message: (r && r.erro) || 'Sem PDF disponível.', duration: 4000 });
        }).fail(function () { Toast.update(tid, { type: 'error', message: 'Erro ao imprimir.', duration: 3500 }); });
    }

    // Visualizador de PDF embutido no site (modal com iframe) — não abre nova aba.
    function abrirPdfModal(url) {
        var $m = $('#logPdfModal');
        if (!$m.length) {
            $m = $('<div id="logPdfModal" class="log_pdf_modal"><div class="log_pdf_box">' +
                '<div class="log_pdf_head"><span class="log_pdf_ttl">Etiqueta</span>' +
                '<span class="log_pdf_acts"><a class="log_pdf_open" target="_blank" rel="noopener" title="Abrir em nova aba"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-open-in-new"></use></svg></a>' +
                '<button type="button" class="log_pdf_x" title="Fechar"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-close"></use></svg></button></span></div>' +
                '<iframe class="log_pdf_frame" title="Etiqueta"></iframe></div></div>').appendTo('body');
            function fechar() { $m.removeClass('show'); $m.find('.log_pdf_frame').attr('src', 'about:blank'); }
            $m.on('click', '.log_pdf_x', fechar);
            $m.on('click', function (ev) { if (ev.target === $m[0]) fechar(); });
            $(document).on('keydown.logpdf', function (ev) { if (ev.key === 'Escape') fechar(); });
        }
        $m.find('.log_pdf_open').attr('href', url);
        $m.find('.log_pdf_frame').attr('src', url);
        $m.addClass('show');
    }

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
        if (acoes.indexOf('comprar') >= 0) btns += '<button type="button" class="log_btn log_btn--sm js-comprar"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-payments"></use></svg> Comprar</button> ';
        if (it.url_pdf || acoes.indexOf('imprimir') >= 0) btns += '<button type="button" class="log_btn log_btn--icon js-imprimir" title="Imprimir PDF" aria-label="Imprimir PDF"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-printer"></use></svg></button> ';
        if (acoes.indexOf('ver_ar') >= 0) btns += '<button type="button" class="log_btn log_btn--icon js-ar" title="Ver AR (Aviso de Recebimento)" aria-label="Ver AR (Aviso de Recebimento)"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-folder-check"></use></svg></button> ';
        if (acoes.indexOf('cancelar') >= 0) btns += '<button type="button" class="log_btn log_btn--icon log_btn--danger js-cancelar" title="Cancelar" aria-label="Cancelar"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-cancel"></use></svg></button> ';
        if (acoes.indexOf('remover') >= 0) btns += '<button type="button" class="log_btn log_btn--icon log_btn--danger js-remover" title="Remover" aria-label="Remover"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-delete"></use></svg></button> ';
        btns += '<button type="button" class="log_btn log_btn--icon js-detalhe" title="Detalhes" aria-label="Detalhes"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-format-list-bulleted"></use></svg></button>';

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
            imprimirEtiqueta($tr.data('id'), 0);
        });

        $t.on('click', '.js-ar', function () { verAr($(this).closest('tr').data('id')); });

        // Fluxo de 2 etapas do rótulo assíncrono (Correios): solicita e depois baixa.
        // (imprimirEtiqueta e abrirPdfModal estão no escopo do módulo, abaixo.)

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
            if (e.url_pdf || (e.acoes || []).indexOf('imprimir') >= 0) acoesHtml += '<button type="button" class="log_btn log_btn--sm js-d-pdf" data-id="' + id + '"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-printer"></use></svg> PDF</button> ';
            if ((e.acoes || []).indexOf('comprar') >= 0) acoesHtml += '<button type="button" class="log_btn log_btn--primary log_btn--sm js-d-comprar"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-payments"></use></svg> Comprar</button>';

            var drawer = adminDrawer({ titulo: 'Etiqueta #' + id, subtitulo: e.pedido_id ? 'Pedido #' + e.pedido_id : 'Avulsa', conteudo: html, acoes: acoesHtml, tamanho: 'md' });
            drawer.escutar('click', '.js-d-pdf', function () { imprimirEtiqueta(id, 0); });
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
            '<div class="log_field"><label>CEP</label><div class="log_cep_row"><input class="log_input" data-e="' + pref + '.cep"><button type="button" class="log_btn log_btn--sm" data-cep-buscar="' + pref + '"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-search"></use></svg> Buscar</button></div></div>' +
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
            '<button type="button" class="log_btn log_btn--icon log_btn--xs log_btn--danger js-vol-rm" title="Remover volume" aria-label="Remover volume"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-close"></use></svg></button>' +
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
            // O botão fica no destinatário porque o caso é a etiqueta de
            // retorno: quem recebe é a loja. O remetente não tem o botão — ele
            // já cai na config da transportadora quando fica vazio.
            '<div class="log_fieldset"><h4>Destinatário' +
                '<button type="button" class="log_btn log_btn--sm js-usar-loja" ' +
                    'title="Preenche com o endereço cadastrado em Configurações → Rodapé">' +
                    '<svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-globe-location"></use></svg>' +
                    ' Usar dados da loja</button>' +
            '</h4>' + endFields('destinatario', true) + '</div>' +
            '<div class="log_fieldset"><h4>Remetente <span class="log_muted">(opcional — usa a config se vazio)</span></h4>' + endFields('remetente', false) + '</div>' +
            '<div class="log_fieldset"><h4>Volumes <button type="button" class="log_btn log_btn--sm js-vol-add"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-add"></use></svg> Adicionar</button></h4><div id="etqVolumes">' + volRow() + '</div></div>' +
        '</form>';

        var drawer = adminDrawer({ titulo: 'Nova etiqueta', subtitulo: 'Emissão manual', conteudo: html, tamanho: 'lg',
            acoes: '<button type="button" class="log_btn log_btn--primary log_btn--sm js-criar"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-check"></use></svg> Criar</button>' });

        drawer.escutar('change', '#etqTransp', function (ev) {
            var id = parseInt($(ev.target).val(), 10);
            var t = TRANSP.filter(function (x) { return (x.id | 0) === id; })[0];
            var svs = (t && t.servicos) || [];
            var opts = '<option value="">—</option>' + svs.map(function (s) { return '<option value="' + attr(s.codigo) + '" data-nome="' + attr(s.nome) + '">' + esc(s.nome || s.codigo) + '</option>'; }).join('');
            $(drawer.corpo()).find('#etqServico').html(opts);
        });
        // --- preencher o destinatário com os dados da loja ---
        // `faltando` vem com as chaves internas do campo; o aviso é para gente.
        var ROTULO = {
            nome: 'nome', document: 'CPF/CNPJ', email: 'e-mail', telefone: 'telefone',
            cep: 'CEP', logradouro: 'logradouro', numero: 'número',
            complemento: 'complemento', bairro: 'bairro', cidade: 'cidade', uf: 'UF'
        };
        drawer.escutar('click', '.js-usar-loja', function (ev) {
            var $btn = $(ev.target).closest('.js-usar-loja').prop('disabled', true);
            var $b = $(drawer.corpo());
            var tid = Toast.loading('Buscando dados da loja...');
            api('GET', '/dados-loja').done(function (r) {
                if (!r || !r.ok) {
                    Toast.update(tid, { type: 'error', message: (r && r.erro) || 'Não foi possível ler os dados da loja.', duration: 4000 });
                    return;
                }
                var end = r.endereco || {}, n = 0;
                // Preenche, não limpa: campo sem valor na configuração deixa
                // intacto o que o operador já tinha digitado.
                Object.keys(end).forEach(function (k) {
                    if (String(end[k] || '') === '') return;
                    var $i = $b.find('[data-e="destinatario.' + k + '"]');
                    if ($i.length) { $i.val(end[k]); n++; }
                });
                if (!n) {
                    Toast.update(tid, { type: 'warning', duration: 5000,
                        message: 'A loja não tem endereço cadastrado. Preencha em Configurações → Rodapé.' });
                    return;
                }
                var falta = (r.faltando || []).map(function (k) { return ROTULO[k] || k; });
                Toast.update(tid, {
                    type: falta.length ? 'warning' : 'success',
                    duration: falta.length ? 6000 : 2500,
                    message: falta.length
                        ? 'Dados da loja preenchidos. Falta preencher à mão: ' + falta.join(', ') + '.'
                        : 'Dados da loja preenchidos.'
                });
            }).fail(function () {
                Toast.update(tid, { type: 'error', message: 'Erro ao buscar os dados da loja.', duration: 3500 });
            }).always(function () { $btn.prop('disabled', false); });
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
    })();

    /* ==================================================================
       TELA: Rastreios  (rastreios.js)
    ================================================================== */
    (function () {

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

    function api(method, path, data) { return LOG.ajax(method, BASE + path, data); }
    function comCsrf(o) { return LOG.comCsrf(o); }
    function esc(s) { return LOG.esc(s); }
    function attr(s) { return LOG.attr(s); }
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
                '<button type="button" class="log_btn log_btn--icon js-atualizar" title="Atualizar agora" aria-label="Atualizar agora"><span class="log_iw"><svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 0 24 24" width="24px" fill="currentColor"><path d="M0 0h24v24H0V0z" fill="none"/><path d="m19 8-4 4h3c0 3.31-2.69 6-6 6-1.01 0-1.97-.25-2.8-.7l-1.46 1.46C8.97 19.54 10.43 20 12 20c4.42 0 8-3.58 8-8h3l-4-4zM6 12c0-3.31 2.69-6 6-6 1.01 0 1.97.25 2.8.7l1.46-1.46C15.03 4.46 13.57 4 12 4c-4.42 0-8 3.58-8 8H1l4 4 4-4H6z"/></svg></span</button> ' +
                '<button type="button" class="log_btn log_btn--icon js-link" title="Copiar link público" aria-label="Copiar link público"><span class="log_iw"><svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#e3e3e3"><path d="M360-240q-33 0-56.5-23.5T280-320v-480q0-33 23.5-56.5T360-880h360q33 0 56.5 23.5T800-800v480q0 33-23.5 56.5T720-240H360Zm0-80h360v-480H360v480ZM200-80q-33 0-56.5-23.5T120-160v-560h80v560h440v80H200Zm160-240v-480 480Z"/></svg></span></button> ' +
                '<button type="button" class="log_btn log_btn--icon js-detalhe" title="Timeline" aria-label="Timeline"><span class="log_iw"><svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#e3e3e3"><path d="M120-240q-33 0-56.5-23.5T40-320q0-33 23.5-56.5T120-400h10.5q4.5 0 9.5 2l182-182q-2-5-2-9.5V-600q0-33 23.5-56.5T400-680q33 0 56.5 23.5T480-600q0 2-2 20l102 102q5-2 9.5-2h21q4.5 0 9.5 2l142-142q-2-5-2-9.5V-640q0-33 23.5-56.5T840-720q33 0 56.5 23.5T920-640q0 33-23.5 56.5T840-560h-10.5q-4.5 0-9.5-2L678-420q2 5 2 9.5v10.5q0 33-23.5 56.5T600-320q-33 0-56.5-23.5T520-400v-10.5q0-4.5 2-9.5L420-522q-5 2-9.5 2H400q-2 0-20-2L198-340q2 5 2 9.5v10.5q0 33-23.5 56.5T120-240Z"/></svg></span></button>' +
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

    /*  EXPOSTO GLOBALMENTE.
     *
     *  O drawer vivia preso a esta tela, mas a informação que ele mostra —
     *  situação, previsão e timeline — é a mesma que o operador procura na
     *  listagem de pedidos e dentro do pedido. Reescrever lá seria manter
     *  duas timelines que divergem na primeira mudança.
     *
     *  `logistica.js` já é carregado em TODAS as telas do admin (ver
     *  layouts/admin.php), então basta expor: nada novo é baixado.
     */
    window.LogRastreio = { abrir: function (id, token) { abrirDetalhe(id, token); } };

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
    })();

    /* ==================================================================
       TELA: Logistica reversa  (reversas.js)
    ================================================================== */
    (function () {

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

    function api(m, p, d) { return LOG.ajax(m, BASE + p, d); }
    function comCsrf(o) { return LOG.comCsrf(o); }
    function esc(s) { return LOG.esc(s); }
    function attr(s) { return LOG.attr(s); }
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
        if (acoes.indexOf('autorizar') >= 0) quick = '<button type="button" class="log_btn log_btn--sm js-autorizar"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-check-circle"></use></svg> Autorizar</button> ';
        else if (acoes.indexOf('gerar_etiqueta') >= 0) quick = '<button type="button" class="log_btn log_btn--sm js-gerar"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-etiqueta"></use></svg> Gerar etiqueta</button> ';

        return '<tr data-id="' + (it.id | 0) + '">' +
            '<td><span class="log_mono">' + (it.pedido_id ? '#' + it.pedido_id : '—') + '</span></td>' +
            '<td>' + esc(it.motivo_label || it.motivo) + '<div class="log_muted">' + (it.tipo === 'coleta' ? 'Coleta' : 'Postagem') + '</div></td>' +
            '<td>' + esc(it.transportadora_nome || '—') + '<div class="log_muted log_mono">' + esc(it.codigo_rastreio || '') + '</div></td>' +
            '<td><span class="log_badge ' + st[0] + ' log_badge--plain">' + esc(st[1]) + '</span></td>' +
            '<td>' + proc + '</td>' +
            '<td class="log_col_acoes">' + quick +
                '<button type="button" class="log_btn log_btn--icon js-detalhe" title="Detalhes" aria-label="Detalhes"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-format-list-bulleted"></use></svg></button>' +
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
        return '<div class="log_form_grid">' + fld('nome', 'Nome') + fld('cpf', 'CPF/CNPJ') + fld('email', 'E-mail') + fld('telefone', 'Telefone') + fld('cep', 'CEP') + fld('logradouro', 'Logradouro') + fld('numero', 'Número') + fld('complemento', 'Complemento') + fld('bairro', 'Bairro') + fld('cidade', 'Cidade') +
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
            '<button type="button" class="log_btn log_btn--icon log_btn--xs log_btn--danger js-vol-rm" title="Remover volume" aria-label="Remover volume"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-close"></use></svg></button></div>';
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
            acoes: '<button type="button" class="log_btn log_btn--primary log_btn--sm js-criar"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-check"></use></svg> Registrar</button>' });

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
                nome: c.nome, cpf: c.cpf_formatado || c.cpf, email: c.email, telefone: c.telefone,
                cep: end.cep, logradouro: end.logradouro, numero: end.numero,
                complemento: end.complemento, bairro: end.bairro, cidade: end.cidade, uf: end.uf
            });
            $b.find('#revClienteId').val(c.cliente_id || '');
            $b.find('#revCpfRes').removeClass('show').empty();
            $b.find('#revClienteSel').show().html(
                '<svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-person-circle"></use></svg> <strong>' + esc(c.nome || '') + '</strong> · ' + esc(c.cpf_formatado || c.cpf || '') +
                '<button type="button" class="logrev_chip_x" title="Trocar"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-close"></use></svg></button>'
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
                '<div class="log_fieldset"><h4>Remetente (cliente) <span class="log_muted">— confira/complete: CPF e e-mail são obrigatórios para os Correios</span></h4>' + endFields('remetente') + '</div>' +
                '<div class="log_fieldset"><h4>Volumes <button type="button" class="log_btn log_btn--sm js-vol-add"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-add"></use></svg> Adicionar</button></h4><div id="revVolumes">' + volRow() + '</div></div>' +
                '<div class="log_fieldset"><h4>Observação <span class="log_muted">(opcional)</span></h4><textarea class="log_input" name="observacao" rows="2" placeholder="Instruções à transportadora / motivo da devolução"></textarea></div>' +
            '</form>';

            var drawer = adminDrawer({ titulo: 'Gerar etiqueta de retorno', subtitulo: 'Reversa #' + id, tamanho: 'lg', conteudo: html,
                acoes: '<button type="button" class="log_btn log_btn--primary log_btn--sm js-gerar-ok"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-etiqueta"></use></svg> Gerar</button>' });

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
                    observacao: $c.find('[name=observacao]').val() || '',
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
            if (e.etiqueta_url_pdf) linksHtml += '<a href="' + attr(e.etiqueta_url_pdf) + '" target="_blank" class="log_btn log_btn--sm"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-printer"></use></svg> Etiqueta (PDF)</a> ';
            if (trackUrl) linksHtml += '<a href="' + attr(trackUrl) + '" target="_blank" class="log_btn log_btn--sm"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-globe-location"></use></svg> Rastreio</a>';

            var instr = e.instrucoes || '';
            var instrHtml = instr ? (
                '<div class="log_fieldset"><h4>Instruções para o cliente</h4>' +
                '<textarea class="log_input" id="revInstr" rows="7" readonly>' + esc(instr) + '</textarea>' +
                '<div class="log_copybox" style="margin-top:8px">' +
                    '<button type="button" class="log_btn log_btn--sm js-copy" data-txt="' + attr(instr) + '"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-copy"></use></svg> Copiar</button>' +
                    '<a class="log_btn log_btn--sm" target="_blank" href="https://wa.me/?text=' + encodeURIComponent(instr) + '"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-whatsapp"></use></svg> WhatsApp</a>' +
                    '<a class="log_btn log_btn--sm" href="mailto:?subject=' + encodeURIComponent('Instruções de devolução') + '&body=' + encodeURIComponent(instr) + '"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-mail"></use></svg> E-mail</a>' +
                '</div></div>'
            ) : '';

            var procHtml = '<div class="log_fieldset"><h4>Processo (troca / reembolso)</h4>' +
                '<div class="log_copybox"><select class="log_select" id="revProc">' +
                    ['nenhum', 'troca', 'reembolso'].map(function (p) { return '<option value="' + p + '"' + (e.processo === p ? ' selected' : '') + '>' + (p === 'nenhum' ? 'Nenhum' : (p === 'troca' ? 'Troca' : 'Reembolso')) + '</option>'; }).join('') +
                '</select><button type="button" class="log_btn log_btn--sm js-proc"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-check"></use></svg> Salvar</button></div></div>';

            var cli = e.endereco_coleta_json || {};
            var cliLinha = (cli.nome || cli.telefone) ? '<p class="log_muted"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-person-circle"></use></svg> ' + esc(cli.nome || '') + (cli.telefone ? ' · ' + esc(cli.telefone) : '') + '</p>' : '';

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
            if (acoes.indexOf('gerar_etiqueta') >= 0) acHtml += '<button type="button" class="log_btn log_btn--primary log_btn--sm js-a-gerar"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-etiqueta"></use></svg> Gerar etiqueta</button> ';
            if (acoes.indexOf('sincronizar') >= 0) acHtml += '<button type="button" class="log_btn log_btn--sm js-a-sinc"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-sync"></use></svg> Sincronizar</button> ';
            if (acoes.indexOf('marcar_recebida') >= 0) acHtml += '<button type="button" class="log_btn log_btn--sm js-a-receber"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-package"></use></svg> Recebida</button> ';
            if (acoes.indexOf('cancelar') >= 0) acHtml += '<button type="button" class="log_btn log_btn--sm log_btn--danger js-a-cancelar" title="Cancelar reversa" aria-label="Cancelar reversa"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-cancel"></use></svg></button>';

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
    })();

    /* ==================================================================
       TELA: Divergencias e alertas de produto  (divergencias.js)
    ================================================================== */
    (function () {

    var BASE = window.LOG_DIV_BASE || '/admin/logistica/divergencias';
    var TRANSP = window.LOG_DIV_TRANSPORTADORAS || [];
    var pgDiv = 1, pgAle = 1, pane = 'div';

    var ST_DIV = { aberta: ['is-warn', 'Aberta'], em_analise: ['is-info', 'Em análise'], resolvida: ['is-ok', 'Resolvida'], ignorada: ['is-neutral', 'Ignorada'] };
    var NIVEL = { alto: ['is-danger', 'Alto'], medio: ['is-warn', 'Médio'], baixo: ['is-neutral', 'Baixo'] };
    var ST_ALE = { aberto: ['is-warn', 'Aberto'], resolvido: ['is-ok', 'Resolvido'] };
    var TIPO = { peso: 'Peso', dimensao: 'Dimensão', embalagem: 'Embalagem', misto: 'Misto' };

    function api(m, p, d) { return LOG.ajax(m, BASE + p, d); }
    function comCsrf(o) { return LOG.comCsrf(o); }
    function esc(s) { return LOG.esc(s); }
    function attr(s) { return LOG.attr(s); }
    function moeda(v) { return LOG.moeda(v); }
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
        if (acoes.indexOf('analisar') >= 0) quick += '<button type="button" class="log_btn log_btn--sm js-d-analisar"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-search"></use></svg></button> ';
        if (acoes.indexOf('resolver') >= 0) quick += '<button type="button" class="log_btn log_btn--icon js-d-resolver" title="Resolver" aria-label="Resolver"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-check-circle"></use></svg></button> ';
        return '<tr data-id="' + (it.id | 0) + '">' +
            '<td><span class="log_mono">' + (it.pedido_id ? '#' + it.pedido_id : '—') + '</span></td>' +
            '<td>' + esc(it.transportadora_nome || '—') + '<div class="log_muted">' + esc(it.motivo || '') + '</div></td>' +
            '<td class="' + (dif > 0 ? 'logd_neg' : '') + '">' + difTxt + '</td>' +
            '<td>' + badge(NIVEL, it.nivel_impacto) + '</td>' +
            '<td>' + badge(ST_DIV, it.status) + '</td>' +
            '<td class="log_col_acoes">' + quick + '<button type="button" class="log_btn log_btn--icon js-d-det" title="Detalhes" aria-label="Detalhes"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-format-list-bulleted"></use></svg></button></td>' +
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
                    '<button type="button" class="log_btn log_btn--sm js-d-obs" style="margin-top:8px"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-save"></use></svg> Salvar observações</button></div>' +
            '</div>';

            var acoes = d.acoes || [], acH = '';
            if (acoes.indexOf('analisar') >= 0) acH += '<button type="button" class="log_btn log_btn--sm js-a-analisar"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-search"></use></svg> Analisar</button> ';
            if (acoes.indexOf('resolver') >= 0) acH += '<button type="button" class="log_btn log_btn--primary log_btn--sm js-a-resolver"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-check-circle"></use></svg> Resolver</button> ';
            if (acoes.indexOf('ignorar') >= 0) acH += '<button type="button" class="log_btn log_btn--sm js-a-ignorar">Ignorar</button> ';
            if (acoes.indexOf('reabrir') >= 0) acH += '<button type="button" class="log_btn log_btn--sm js-a-reabrir"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-rotate-left"></use></svg> Reabrir</button>';

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
            acoes: '<button type="button" class="log_btn log_btn--primary log_btn--sm js-salvar"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-check"></use></svg> Registrar</button>' });

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
                // Quando o cron pós-postagem já trouxe o real, não faz sentido
                // pedir que alguém abra a fatura e digite.
                if (c.valor_transportadora) $b.find('[name=valor_transportadora]').val(c.valor_transportadora);
                if (c.peso_aferido_g) $b.find('[name=peso_aferido_g]').val(c.peso_aferido_g);
                if (c.dimensoes_informadas) {
                    $b.find('[data-dim="dimensoes_informadas.altura"]').val(c.dimensoes_informadas.altura || '');
                    $b.find('[data-dim="dimensoes_informadas.largura"]').val(c.dimensoes_informadas.largura || '');
                    $b.find('[data-dim="dimensoes_informadas.comprimento"]').val(c.dimensoes_informadas.comprimento || '');
                }
                Toast.info(c.valor_transportadora
                    ? 'Dados da etiqueta preenchidos, incluindo o valor real da postagem.'
                    : 'Dados da etiqueta preenchidos.');
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
                (it.status === 'aberto' ? '<button type="button" class="log_btn log_btn--icon js-al-resolver" title="Resolver" aria-label="Resolver"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-check-circle"></use></svg></button> ' : '<button type="button" class="log_btn log_btn--icon js-al-reabrir" title="Reabrir" aria-label="Reabrir"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-rotate-left"></use></svg></button> ') +
                '<button type="button" class="log_btn log_btn--icon js-al-det" title="Detalhes" aria-label="Detalhes"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-format-list-bulleted"></use></svg></button>' +
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
                ? '<button type="button" class="log_btn log_btn--primary log_btn--sm js-r"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-check-circle"></use></svg> Marcar resolvido</button>'
                : '<button type="button" class="log_btn log_btn--sm js-rb"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-rotate-left"></use></svg> Reabrir</button>';
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
    })();

    /* ==================================================================
       TELA: Chaves de API  (api-keys.js)
    ================================================================== */
    (function () {

    var BASE = window.LOG_API_BASE || '/admin/logistica/api-keys';
    var ESCOPOS = window.LOG_API_ESCOPOS || ['cotar', 'etiquetas', 'rastreio', 'reversa', 'divergencias'];

    function api(m, p, d) { return LOG.ajax(m, BASE + p, d); }
    function comCsrf(o) { return LOG.comCsrf(o); }
    function esc(s) { return LOG.esc(s); }
    function attr(s) { return LOG.attr(s); }

    /* ---------------- lista ---------------- */
    function carregar() {
        $('#logApiBody').html('<tr><td colspan="7"><div class="log_state"><div class="log_spinner"></div></div></td></tr>');
        api('GET', '/dados').done(function (r) {
            if (!r || !r.ok) { $('#logApiBody').html('<tr><td colspan="7"><div class="log_alert is-danger">Falha ao carregar.</div></td></tr>'); return; }
            render(r.itens || []);
        }).fail(function () { $('#logApiBody').html('<tr><td colspan="7"><div class="log_alert is-danger">Erro.</div></td></tr>'); });
    }
    function render(itens) {
        var $b = $('#logApiBody').empty();
        if (!itens.length) { $b.html('<tr><td colspan="7"><div class="log_state"><div class="log_state_title">Nenhuma chave</div><div class="log_state_desc">Crie uma chave para liberar o acesso à API.</div></div></td></tr>'); return; }
        itens.forEach(function (k) {
            var escopos = (k.escopos_arr || []).map(function (s) { return '<span class="log_badge is-info log_badge--plain">' + esc(s) + '</span>'; }).join(' ') || '<span class="log_muted">—</span>';
            var sit = k.ativa == 1 ? '<span class="log_badge is-ok log_badge--plain">Ativa</span>' : '<span class="log_badge is-neutral log_badge--plain">Revogada</span>';
            $b.append('<tr data-id="' + (k.id | 0) + '">' +
                '<td>' + esc(k.nome) + (k.webhook_url ? '<div class="log_muted"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-webhook"></use></svg> webhook</div>' : '') + '</td>' +
                '<td><span class="log_mono">' + esc(k.prefixo) + '…</span></td>' +
                '<td>' + escopos + '</td>' +
                '<td>' + (k.rate_limit | 0) + '</td>' +
                '<td>' + (k.req_24h | 0) + '</td>' +
                '<td>' + sit + '</td>' +
                '<td class="log_col_acoes">' +
                    '<button type="button" class="log_btn log_btn--icon js-edit" title="Editar" aria-label="Editar"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-pencil"></use></svg></button> ' +
                    (k.ativa == 1 ? '<button type="button" class="log_btn log_btn--icon log_btn--danger js-revogar" title="Revogar" aria-label="Revogar"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-sync-disabled"></use></svg></button>' : '') +
                '</td></tr>');
        });
    }

    /* ---------------- form (nova/editar) ---------------- */
    function escoposCheck(sel) {
        sel = sel || [];
        return '<div class="log_field--check" style="flex-direction:row;flex-wrap:wrap;gap:14px">' +
            ESCOPOS.map(function (s) { return '<label><input type="checkbox" class="js-esc" value="' + s + '"' + (sel.indexOf(s) >= 0 ? ' checked' : '') + '> ' + s + '</label>'; }).join('') + '</div>';
    }

    function abrirForm(k) {
        var editar = !!k;
        var html = '<form class="log_form">' +
            '<div class="log_fieldset"><h4>Identificação</h4>' +
                '<div class="log_field"><label>Nome *</label><input class="log_input" name="nome" value="' + attr(editar ? k.nome : '') + '" placeholder="Ex.: Integração ERP"></div>' +
                '<div class="log_field"><label>Limite por minuto</label><input class="log_input" name="rate_limit" type="number" value="' + (editar ? (k.rate_limit | 0) : 120) + '"></div>' +
            '</div>' +
            '<div class="log_fieldset"><h4>Escopos</h4>' + escoposCheck(editar ? (k.escopos_arr || []) : ['cotar', 'rastreio']) + '</div>' +
            '<div class="log_fieldset"><h4>Webhook <span class="log_muted">(opcional)</span></h4>' +
                '<div class="log_field"><label>URL de notificação</label><input class="log_input" name="webhook_url" value="' + attr(editar && k.webhook_url ? k.webhook_url : '') + '" placeholder="https://seu-endpoint/webhook"></div>' +
                '<div class="log_field"><label>Secret <span class="log_muted">(gerado se vazio)</span></label><input class="log_input" name="webhook_secret" placeholder="opcional"></div>' +
                '<p class="log_muted">Eventos são assinados em <code>X-Logistica-Signature</code> (HMAC-SHA256).</p>' +
            '</div>' +
            (editar ? '<div class="log_fieldset"><h4>Situação</h4><label class="log_field--check"><input type="checkbox" id="apiAtiva"' + (k.ativa == 1 ? ' checked' : '') + '> Chave ativa</label></div>' : '') +
        '</form>';

        var dr = adminDrawer({ titulo: editar ? 'Editar chave' : 'Nova chave de API', tamanho: 'md', conteudo: html,
            acoes: '<button type="button" class="log_btn log_btn--primary log_btn--sm js-salvar"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-check"></use></svg> ' + (editar ? 'Salvar' : 'Criar') + '</button>' });

        dr.escutar('click', '.js-salvar', function () {
            var $b = $(dr.corpo());
            var escopos = $b.find('.js-esc:checked').map(function () { return this.value; }).get();
            if (!$b.find('[name=nome]').val()) { Toast.warning('Informe o nome.'); return; }
            if (!escopos.length) { Toast.warning('Selecione ao menos um escopo.'); return; }
            var dados = comCsrf({
                nome: $b.find('[name=nome]').val(),
                rate_limit: $b.find('[name=rate_limit]').val(),
                escopos: escopos,
                webhook_url: $b.find('[name=webhook_url]').val(),
                webhook_secret: $b.find('[name=webhook_secret]').val()
            });
            if (editar) {
                dados.id = k.id;
                dados.ativa = $b.find('#apiAtiva').is(':checked') ? 1 : 0;
                api('POST', '/atualizar', dados).done(function (r) {
                    if (r && r.ok) { Toast.success('Chave atualizada.'); dr.fechar(); carregar(); }
                    else Toast.error((r && r.erro) || 'Falha.');
                });
            } else {
                var tid = Toast.loading('Criando...');
                api('POST', '/criar', dados).done(function (r) {
                    if (r && r.ok && r.chave) { Toast.dismiss(tid); dr.fechar(); mostrarChave(r.chave, r.prefixo); carregar(); }
                    else Toast.update(tid, { type: 'error', message: (r && r.erro) || 'Falha.', duration: 4000 });
                }).fail(function () { Toast.update(tid, { type: 'error', message: 'Erro.', duration: 3000 }); });
            }
        });
    }

    /* chave em texto puro — exibida UMA vez */
    function mostrarChave(chave, prefixo) {
        var html = '<div class="log_form">' +
            '<div class="log_alert is-warn">Guarde esta chave agora. Por segurança, ela <strong>não será exibida novamente</strong>.</div>' +
            '<div class="log_copybox" style="margin-top:12px"><input class="log_input log_mono" readonly value="' + attr(chave) + '"><button type="button" class="log_btn log_btn--sm js-copy" data-txt="' + attr(chave) + '"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-copy"></use></svg> Copiar</button></div>' +
            '<p class="log_muted" style="margin-top:10px">Prefixo <span class="log_mono">' + esc(prefixo) + '</span> — use no header <code>Authorization: Bearer …</code>.</p>' +
        '</div>';
        var dr = adminDrawer({ titulo: 'Chave criada', tamanho: 'md', conteudo: html, acoes: '<button type="button" class="log_btn log_btn--primary log_btn--sm js-ok">Concluir</button>' });
        dr.escutar('click', '.js-copy', function (ev) {
            var t = $(ev.target).closest('[data-txt]').data('txt');
            if (navigator.clipboard) navigator.clipboard.writeText(t).then(function () { Toast.success('Copiada.'); });
            else { var $i = $('<textarea>').val(t).appendTo('body').select(); try { document.execCommand('copy'); Toast.success('Copiada.'); } catch (e) {} $i.remove(); }
        });
        dr.escutar('click', '.js-ok', function () { dr.fechar(); });
    }

    function bind() {
        var $t = $('#logApiTabela');
        $t.on('click', '.js-edit', function () {
            var id = $(this).closest('tr').data('id');
            api('GET', '/dados').done(function (r) {
                var k = (r.itens || []).filter(function (x) { return (x.id | 0) === (id | 0); })[0];
                if (k) abrirForm(k);
            });
        });
        $t.on('click', '.js-revogar', function () {
            var id = $(this).closest('tr').data('id');
            var d = adminDrawer({ titulo: 'Revogar chave', tamanho: 'sm', conteudo: '<p>Revogar esta chave? As integrações que a usam deixarão de funcionar imediatamente.</p>',
                acoes: '<button type="button" class="log_btn log_btn--sm js-c">Voltar</button> <button type="button" class="log_btn log_btn--primary log_btn--sm js-o" style="background:var(--log-danger);border-color:var(--log-danger)">Revogar</button>' });
            d.escutar('click', '.js-c', function () { d.fechar(); });
            d.escutar('click', '.js-o', function () {
                api('POST', '/revogar', comCsrf({ id: id })).done(function (r) {
                    if (r && r.ok) { Toast.success('Chave revogada.'); d.fechar(); carregar(); } else Toast.error((r && r.erro) || 'Falha.');
                });
            });
        });
        $('#logApiNova').on('click', function () { abrirForm(null); });
    }

    $(function () { if (!document.getElementById('logApi')) return; bind(); carregar(); });
    })();

    /* ==================================================================
       TELA: Frete fallback  (frete-fallback.js)
    ================================================================== */
    (function () {

    var BASE = window.LOG_FALL_BASE || '/admin/logistica/frete-fallback';
    var REGIOES = { N: 'Norte', NE: 'Nordeste', CO: 'Centro-Oeste', SE: 'Sudeste', S: 'Sul' };

    function api(m, p, d) { return LOG.ajax(m, BASE + p, d); }
    function comCsrf(o) { return LOG.comCsrf(o); }
    function esc(s) { return LOG.esc(s); }
    function attr(s) { return LOG.attr(s); }
    function reais(v) { return LOG.moeda(v); }
    function kg(g) { return (Number(g) / 1000).toFixed(g % 1000 ? 1 : 0).replace('.', ',') + ' kg'; }

    function local(r) {
        if (r.uf) return '<span class="log_badge is-info">UF ' + esc(r.uf) + '</span>';
        if (r.regiao) return '<span class="log_badge is-neutral">' + esc(REGIOES[r.regiao] || r.regiao) + '</span>';
        return '<span class="log_badge">Geral</span>';
    }

    function preco(r) {
        var base = reais(r.valor_base);
        var pk = Number(r.valor_por_kg) > 0 ? ' + ' + reais(r.valor_por_kg) + '/kg' : '';
        return base + pk;
    }

    function bannerSaude(s) {
        if (!s) { $('#logFallSaude').empty(); return; }
        var abertoAte = s.aberto_ate ? new Date(s.aberto_ate * 1000) : null;
        var aberto = abertoAte && abertoAte.getTime() > Date.now();
        if (aberto) {
            $('#logFallSaude').html(
                '<div class="log_fall_saude is-open"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-alerta"></use></svg> ' +
                'Circuito das transportadoras <strong>ABERTO</strong> — servindo estimativas até ' +
                esc(abertoAte.toLocaleTimeString('pt-BR')) + '. (' + (s.falhas || 0) + ' falhas)</div>'
            );
        } else if ((s.falhas || 0) > 0) {
            $('#logFallSaude').html('<div class="log_fall_saude is-warn"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-timeline"></use></svg> ' + s.falhas + ' falha(s) recente(s) nas transportadoras. Circuito fechado.</div>');
        } else {
            $('#logFallSaude').html('<div class="log_fall_saude is-ok"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-check-circle"></use></svg> Transportadoras operando normalmente.</div>');
        }
    }

    function linha(r) {
        return '<tr data-id="' + r.id + '">' +
            '<td>' + local(r) + '</td>' +
            '<td>' + kg(r.peso_min_g) + ' — ' + kg(r.peso_max_g) + '</td>' +
            '<td><strong>' + esc(r.servico) + '</strong><div class="log_muted">' + esc(r.servico_nome) + '</div></td>' +
            '<td>' + (r.prazo_dias | 0) + ' dias</td>' +
            '<td>' + preco(r) + '</td>' +
            '<td><span class="log_badge ' + (r.ativo == 1 ? 'is-ok' : 'is-neutral') + '">' + (r.ativo == 1 ? 'Ativa' : 'Inativa') + '</span></td>' +
            '<td class="log_col_acoes">' +
                '<button class="log_ib js-edit" title="Editar"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-pencil"></use></svg></button>' +
                '<button class="log_ib js-toggle" title="Ativar/Inativar"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-power"></use></svg></button>' +
                '<button class="log_ib log_ib--danger js-del" title="Remover"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-delete"></use></svg></button>' +
            '</td></tr>';
    }

    function carregar() {
        api('GET', '/dados').done(function (r) {
            bannerSaude(r && r.saude);
            var itens = (r && r.itens) || [];
            $('#logFallBody').html(itens.length ? itens.map(linha).join('') : '<tr><td colspan="7"><div class="log_state">Nenhuma linha de fallback. Crie ao menos uma regra geral.</div></td></tr>');
        }).fail(function () { $('#logFallBody').html('<tr><td colspan="7"><div class="log_state">Erro ao carregar.</div></td></tr>'); });
    }

    function formulario(r) {
        r = r || {};
        function opRegiao(v) {
            var o = '<option value="">— Região —</option>';
            Object.keys(REGIOES).forEach(function (k) { o += '<option value="' + k + '"' + (r.regiao === k ? ' selected' : '') + '>' + REGIOES[k] + '</option>'; });
            return o;
        }
        return '' +
            '<div class="log_form">' +
            '<div class="log_note"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-info"></use></svg> Preencha <strong>UF</strong> OU <strong>Região</strong> para uma regra específica. Deixe ambos vazios para a regra geral.</div>' +
            '<div class="log_grid2">' +
                '<label class="log_field"><span>UF (opcional)</span><input id="ffUf" maxlength="2" value="' + attr(r.uf || '') + '" placeholder="Ex.: SP" style="text-transform:uppercase"></label>' +
                '<label class="log_field"><span>Região (opcional)</span><select id="ffReg">' + opRegiao() + '</select></label>' +
            '</div>' +
            '<div class="log_grid2">' +
                '<label class="log_field"><span>Peso mín. (g)</span><input id="ffMin" type="number" min="0" value="' + (r.peso_min_g != null ? r.peso_min_g : 0) + '"></label>' +
                '<label class="log_field"><span>Peso máx. (g)</span><input id="ffMax" type="number" min="1" value="' + (r.peso_max_g != null ? r.peso_max_g : 30000) + '"></label>' +
            '</div>' +
            '<div class="log_grid2">' +
                '<label class="log_field"><span>Código do serviço</span><input id="ffServ" value="' + attr(r.servico || 'PAC') + '" placeholder="PAC / SEDEX"></label>' +
                '<label class="log_field"><span>Nome exibido</span><input id="ffServNome" value="' + attr(r.servico_nome || 'Econômico (estimativa)') + '"></label>' +
            '</div>' +
            '<div class="log_grid3">' +
                '<label class="log_field"><span>Prazo (dias)</span><input id="ffPrazo" type="number" min="0" value="' + (r.prazo_dias != null ? r.prazo_dias : 7) + '"></label>' +
                '<label class="log_field"><span>Valor base (R$)</span><input id="ffBase" type="number" min="0" step="0.01" value="' + (r.valor_base != null ? r.valor_base : 0) + '"></label>' +
                '<label class="log_field"><span>Por kg (R$)</span><input id="ffKg" type="number" min="0" step="0.01" value="' + (r.valor_por_kg != null ? r.valor_por_kg : 0) + '"></label>' +
            '</div>' +
            '<div class="log_grid2">' +
                '<label class="log_field"><span>Ordem</span><input id="ffOrdem" type="number" value="' + (r.ordem != null ? r.ordem : 0) + '"></label>' +
                '<label class="log_check"><input id="ffAtivo" type="checkbox"' + (r.ativo == 0 ? '' : ' checked') + '> <span>Ativa</span></label>' +
            '</div>' +
            '<div class="log_form_actions"><button type="button" class="log_btn log_btn--primary js-salvar"><svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-check"></use></svg> Salvar</button></div>' +
            '</div>';
    }

    function abrir(r) {
        var novo = !r;
        var drawer = adminDrawer({ titulo: novo ? 'Nova linha de fallback' : 'Editar fallback', tamanho: 'md', conteudo: formulario(r) });
        drawer.escutar('click', '.js-salvar', function () {
            var $b = $(drawer.corpo());
            var d = comCsrf({
                id: novo ? 0 : r.id,
                uf: $b.find('#ffUf').val().trim().toUpperCase(),
                regiao: $b.find('#ffReg').val(),
                peso_min_g: $b.find('#ffMin').val(),
                peso_max_g: $b.find('#ffMax').val(),
                servico: $b.find('#ffServ').val().trim(),
                servico_nome: $b.find('#ffServNome').val().trim(),
                prazo_dias: $b.find('#ffPrazo').val(),
                valor_base: $b.find('#ffBase').val(),
                valor_por_kg: $b.find('#ffKg').val(),
                ordem: $b.find('#ffOrdem').val(),
                ativo: $b.find('#ffAtivo').is(':checked') ? 1 : 0
            });
            if (Number(d.peso_max_g) < Number(d.peso_min_g)) { Toast.warning('Peso máx. deve ser maior que o mín.'); return; }
            if (!d.servico) { Toast.warning('Informe o código do serviço.'); return; }
            var tid = Toast.loading('Salvando...');
            api('POST', '/salvar', d).done(function (rr) {
                if (rr && rr.ok) { Toast.update(tid, { type: 'success', message: 'Linha salva.', duration: 2200 }); drawer.fechar('ok'); carregar(); }
                else Toast.update(tid, { type: 'error', message: (rr && rr.erro) || 'Falha.', duration: 4000 });
            }).fail(function () { Toast.update(tid, { type: 'error', message: 'Erro de comunicação.', duration: 3500 }); });
        });
    }

    $(function () {
        $('#logFallNova').on('click', function () { abrir(null); });

        $('#logFallTabela').on('click', '.js-edit', function () {
            var id = $(this).closest('tr').data('id');
            api('GET', '/dados').done(function (r) {
                var row = ((r && r.itens) || []).filter(function (x) { return String(x.id) === String(id); })[0];
                if (row) abrir(row); else Toast.error('Linha não encontrada.');
            });
        });

        $('#logFallTabela').on('click', '.js-toggle', function () {
            var id = $(this).closest('tr').data('id');
            api('POST', '/alternar', comCsrf({ id: id })).done(function (rr) {
                if (rr && rr.ok) carregar(); else Toast.error((rr && rr.erro) || 'Falha.');
            });
        });

        $('#logFallTabela').on('click', '.js-del', function () {
            var id = $(this).closest('tr').data('id');
            if (!window.confirm('Remover esta linha de fallback?')) return;
            api('POST', '/remover', comCsrf({ id: id })).done(function (rr) {
                if (rr && rr.ok) { Toast.success('Removida.'); carregar(); } else Toast.error((rr && rr.erro) || 'Falha.');
            });
        });

        carregar();
    });
    })();

    /* ==================================================================
       TELA: Configuração de envios com logística própria (cobertura.php)

       Pinta tudo a partir de window.COB.dados — o servidor manda a primeira
       carga já resolvida e o mesmo código repinta depois de salvar. Dois
       templates, um em PHP e outro em JS, divergem no primeiro ajuste.
    ================================================================== */
    (function () {

    var COB = window.COB;
    if (!COB || !document.getElementById('logCob')) return;

    var BASE  = COB.base;
    var transp = COB.transp | 0;
    var dados = COB.dados || null;
    var mapa = null, camadas = null;

    function api(metodo, caminho, dado) { return LOG.ajax(metodo, BASE + caminho, dado); }
    function esc(s) { return LOG.esc(s); }
    function attr(s) { return LOG.attr(s); }
    function moeda(v) { return 'R$ ' + (parseFloat(v) || 0).toFixed(2).replace('.', ','); }

    /* ------------------------------------------------------- resumo */

    function pintarResumo() {
        var r = (dados && dados.resumo) || {};
        var cls = r.exposicao === 'Chegará hoje' ? 'is-ok'
                : (r.exposicao === 'Sem entrega hoje' ? 'is-neutral' : 'is-info');

        var teto = (r.max_util == null && r.max_sabado == null)
            ? '<span class="log_muted">Sem teto</span>'
            : 'Seg–sex: ' + (r.max_util == null ? '—' : r.max_util) +
              ' &middot; Sáb: ' + (r.max_sabado == null ? '—' : r.max_sabado);

        // O contador de hoje só aparece quando existe teto: sem teto ele é
        // número solto, e número sem régua não informa nada.
        var usado = '';
        if (r.max_util != null || r.max_sabado != null) {
            usado = '<div class="cob_kpi_sub">' + (r.envios_hoje | 0) + ' hoje</div>';
        }

        $('#cobResumo').html(
            '<div class="cob_kpi">' +
                '<div class="cob_kpi_rot">Exposição atual</div>' +
                '<span class="log_badge ' + cls + '">' + esc(r.exposicao || '—') + '</span>' +
                (r.motivo ? '<div class="cob_kpi_sub">' + esc(r.motivo) + '</div>' : '') +
            '</div>' +
            '<div class="cob_kpi">' +
                '<div class="cob_kpi_rot">Cobertura</div>' +
                '<div class="cob_kpi_val">' + (r.areas_ativas | 0) + ' ' +
                    ((r.areas_ativas | 0) === 1 ? 'área ativa' : 'áreas ativas') + '</div>' +
                ((r.areas_total | 0) > (r.areas_ativas | 0)
                    ? '<div class="cob_kpi_sub">' + ((r.areas_total | 0) - (r.areas_ativas | 0)) + ' desativada(s)</div>' : '') +
            '</div>' +
            '<div class="cob_kpi">' +
                '<div class="cob_kpi_rot">Máximo de envios por dia</div>' +
                '<div class="cob_kpi_val">' + teto + '</div>' + usado +
            '</div>'
        );
    }

    /* -------------------------------------------------------- áreas */

    function pintarAreas() {
        var areas = (dados && dados.areas) || [];
        var bandas = (dados && dados.bandas) || {};

        if (!areas.length) {
            $('#cobListaAreas').html(
                '<div class="log_state cob_vazio">' +
                    '<div class="log_state_title">Nenhuma área cadastrada</div>' +
                    '<div class="log_state_desc">Enquanto não houver área, a cotação usa a lista de CEPs e o preço único ' +
                    'da transportadora. A primeira área já passa a valer.</div>' +
                '</div>');
            return;
        }

        var html = '';
        Object.keys(bandas).forEach(function (b) {
            var doGrupo = areas.filter(function (a) { return a.banda === b; });
            if (!doGrupo.length) return;
            html += '<div class="cob_banda"><div class="cob_banda_tit">' + esc(bandas[b]) + '</div>' +
                doGrupo.map(linhaArea).join('') + '</div>';
        });
        $('#cobListaAreas').html(html);
    }

    function linhaArea(a) {
        var extras = [];
        if (a.prazo_dias != null)  extras.push(parseInt(a.prazo_dias, 10) === 0 ? 'mesmo dia' : 'D+' + a.prazo_dias);
        if (a.cutoff_hora != null) extras.push('corte ' + a.cutoff_hora + 'h');
        if (a.max_envios_dia != null) extras.push('até ' + a.max_envios_dia + '/dia');

        return '<div class="cob_area' + (parseInt(a.ativa, 10) ? '' : ' is-off') + '" data-area="' + (a.id | 0) + '">' +
            '<label class="log_toggle log_toggle--sm cob_area_chk" title="Ativar / desativar a área">' +
                '<input type="checkbox" class="js-cob-ativa"' + (parseInt(a.ativa, 10) ? ' checked' : '') + '>' +
                '<span class="log_toggle_track"></span>' +
                '<span class="cob_area_nome">' + esc(a.nome) + '</span>' +
            '</label>' +
            '<div class="cob_area_meta">' +
                '<span class="cob_area_valor">' + moeda(a.valor_base) + '</span>' +
                (extras.length ? '<span class="log_muted"> &middot; ' + esc(extras.join(' · ')) + '</span>' : '') +
            '</div>' +
            '<div class="cob_area_cep log_mono">' + esc(a.faixas_cep) + '</div>' +
            '<div class="cob_area_acoes">' +
                '<button type="button" class="log_btn log_btn--icon log_btn--xs js-cob-editar" title="Editar" aria-label="Editar área">' +
                    '<svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-pencil"></use></svg></button>' +
                '<button type="button" class="log_btn log_btn--icon log_btn--xs log_btn--danger js-cob-excluir" title="Excluir" aria-label="Excluir área">' +
                    '<svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-trash"></use></svg></button>' +
            '</div>' +
        '</div>';
    }

    /* --------------------------------------------------------- mapa */

    var CENTRO_PADRAO = [-30.0346, -51.2177]; // Porto Alegre

    function iniciarMapa() {
        if (typeof L === 'undefined' || !document.getElementById('cobMapa')) return;
        if (mapa) { pintarMapa(); return; }

        mapa = L.map('cobMapa', { scrollWheelZoom: false }).setView(CENTRO_PADRAO, 10);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 18,
            attribution: '&copy; OpenStreetMap'
        }).addTo(mapa);
        camadas = L.layerGroup().addTo(mapa);

        // O Leaflet mede o container na hora do init. Quando a tela ainda está
        // assentando (fontes, grid), ele mede menor e as tiles não preenchem —
        // era o mapa cortado no canto. invalidateSize() remede depois do
        // layout, e o ResizeObserver cobre a coluna que muda de largura.
        setTimeout(function () { mapa.invalidateSize(); pintarMapa(); }, 120);
        if (window.ResizeObserver) {
            new ResizeObserver(function () { mapa.invalidateSize(); })
                .observe(document.getElementById('cobMapa'));
        }
        pintarMapa();
    }

    var COR_BANDA = { proxima: '#16a34a', media: '#2563eb', distante: '#9333ea' };

    function pintarMapa() {
        if (!mapa || !camadas) return;
        camadas.clearLayers();

        var comMapa = ((dados && dados.areas) || []).filter(function (a) {
            return a.mapa_lat != null && a.mapa_lng != null;
        });
        if (!comMapa.length) { mapa.setView(CENTRO_PADRAO, 10); return; }

        var limites = [];
        comMapa.forEach(function (a) {
            var raio = (parseFloat(a.mapa_raio_km) || 3) * 1000;
            var cor = COR_BANDA[a.banda] || '#2563eb';
            var ativa = parseInt(a.ativa, 10) === 1;
            var c = L.circle([parseFloat(a.mapa_lat), parseFloat(a.mapa_lng)], {
                radius: raio,
                color: cor, weight: 2,
                opacity: ativa ? 0.9 : 0.35,
                fillColor: cor, fillOpacity: ativa ? 0.15 : 0.05
            }).bindTooltip(a.nome + ' — ' + moeda(a.valor_base) + (ativa ? '' : ' (desativada)'));
            camadas.addLayer(c);
            limites.push(c.getBounds());
        });

        var todos = limites.reduce(function (acc, b) { return acc ? acc.extend(b) : b; }, null);
        if (todos) mapa.fitBounds(todos, { padding: [24, 24] });
    }

    /* ---------------------------------------------------- operação */

    // A tela mostra os 3 grupos do desenho; o banco guarda os 7 dias. Editar
    // "Segunda a Sexta" escreve nos cinco de uma vez.
    var GRUPOS = [
        { rot: 'Segunda a Sexta', dias: [1, 2, 3, 4, 5] },
        { rot: 'Sábados',         dias: [6] },
        { rot: 'Domingos',        dias: [0] }
    ];

    function opDo(dia) {
        return ((dados && dados.operacao) || []).filter(function (o) {
            return parseInt(o.dia_semana, 10) === dia;
        })[0] || {};
    }

    /** Os dias do grupo têm a mesma configuração? Se não, a tela avisa. */
    function uniforme(dias, campo) {
        var v = null;
        for (var i = 0; i < dias.length; i++) {
            var atual = opDo(dias[i])[campo];
            if (i === 0) { v = atual; continue; }
            if (String(atual) !== String(v)) return null;
        }
        return v;
    }

    function horas(sel) {
        var h = '<option value="">—</option>';
        for (var i = 0; i <= 23; i++) {
            h += '<option value="' + i + '"' + (String(sel) === String(i) ? ' selected' : '') + '>' +
                 (i < 10 ? '0' + i : i) + ':00 hs</option>';
        }
        return h;
    }

    function pintarOperacao() {
        $('#cobOperacao').html(GRUPOS.map(function (g) {
            var opera = uniforme(g.dias, 'opera');
            var misto = opera === null;
            var ligado = misto ? false : parseInt(opera, 10) === 1;
            var mesmoDia = uniforme(g.dias, 'mesmo_dia');
            var corte = uniforme(g.dias, 'cutoff_hora');
            var ini = uniforme(g.dias, 'entrega_inicio');
            var fim = uniforme(g.dias, 'entrega_fim');
            var max = uniforme(g.dias, 'max_envios');

            function hhmm(v) { return v ? String(v).slice(0, 5) : ''; }

            return '<tr data-grupo="' + g.dias.join(',') + '"' + (ligado ? '' : ' class="is-off"') + '>' +
                '<td><label class="log_toggle" title="Opera nestes dias?">' +
                    '<input type="checkbox" class="js-op-ativa"' + (ligado ? ' checked' : '') + '>' +
                    '<span class="log_toggle_track"></span>' +
                    '<span>' + esc(g.rot) + '</span>' +
                    (misto ? '<span class="log_badge is-warn log_badge--plain cob_misto">personalizado</span>' : '') +
                '</label></td>' +
                '<td><select class="log_select js-op-mesmodia"' + (ligado ? '' : ' disabled') + '>' +
                    '<option value="1"' + (String(mesmoDia) === '1' ? ' selected' : '') + '>Sim</option>' +
                    '<option value="0"' + (String(mesmoDia) === '0' ? ' selected' : '') + '>Não</option>' +
                '</select></td>' +
                '<td><select class="log_select js-op-corte"' + (ligado ? '' : ' disabled') + '>' + horas(corte) + '</select></td>' +
                '<td><div class="cob_janela">' +
                    '<input type="time" class="log_input js-op-ini" value="' + attr(hhmm(ini)) + '"' + (ligado ? '' : ' disabled') + '>' +
                    '<span>—</span>' +
                    '<input type="time" class="log_input js-op-fim" value="' + attr(hhmm(fim)) + '"' + (ligado ? '' : ' disabled') + '>' +
                '</div></td>' +
                '<td><input type="number" min="0" class="log_input js-op-max" placeholder="sem teto" value="' +
                    attr(max == null ? '' : max) + '"' + (ligado ? '' : ' disabled') + '></td>' +
            '</tr>';
        }).join(''));
    }

    /* --------------------------------------------------- feriados */

    function pintarFeriados() {
        var fs = (dados && dados.feriados) || [];
        if (!fs.length) { $('#cobFeriados').html('<p class="log_muted">Nenhum feriado nos próximos meses.</p>'); return; }

        $('#cobFeriados').html(fs.map(function (f) {
            var d = f.data.split('-');
            return '<label class="log_toggle log_toggle--sm cob_feriado" data-data="' + attr(f.data) + '"' +
                ' title="Opera neste feriado?">' +
                '<input type="checkbox" class="js-cob-feriado"' + (parseInt(f.opera, 10) ? ' checked' : '') + '>' +
                '<span class="log_toggle_track"></span>' +
                '<span>' + esc(f.dia_semana) + ', ' + d[2] + '/' + d[1] + ' — ' + esc(f.nome) + '</span>' +
                '<span class="log_muted cob_feriado_st">' + (parseInt(f.opera, 10) ? 'opera' : 'não opera') + '</span>' +
            '</label>';
        }).join(''));
    }

    function pintarLimites() {
        var l = (dados && dados.limites) || {};
        $('#cobLargura').val(l.largura_cm || '');
        $('#cobAltura').val(l.altura_cm || '');
        $('#cobProfundidade').val(l.profundidade_cm || '');
        $('#cobPeso').val(l.peso_kg || '');
    }

    function pintarTudo() {
        pintarResumo(); pintarAreas(); pintarOperacao(); pintarFeriados(); pintarLimites();
        iniciarMapa();
    }

    function recarregar() {
        return api('GET', '/dados', { transportadora: transp }).done(function (r) {
            if (!r || !r.ok) { Toast.error((r && r.erro) || 'Falha ao carregar.'); return; }
            dados = r;
            pintarTudo();
        }).fail(function () { Toast.error('Erro de comunicação.'); });
    }

    /* ------------------------------------------------ form da área */

    function formArea(a) {
        a = a || {};
        var bandas = (dados && dados.bandas) || {};
        var opts = Object.keys(bandas).map(function (b) {
            return '<option value="' + b + '"' + (a.banda === b ? ' selected' : '') + '>' + esc(bandas[b]) + '</option>';
        }).join('');

        return '<form class="log_form" id="cobAreaForm">' +
            '<input type="hidden" name="id" value="' + (a.id | 0) + '">' +
            '<div class="log_fieldset"><h4>Identificação</h4><div class="log_form_grid">' +
                '<div class="log_field" id="fld-nome"><label>Nome da área *</label>' +
                    '<input class="log_input" name="nome" value="' + attr(a.nome || '') + '" placeholder="Ex.: Centro, Zona Sul"></div>' +
                '<div class="log_field"><label>Agrupamento</label><select class="log_select" name="banda">' + opts + '</select></div>' +
                '<div class="log_field log_field--larga" id="fld-faixas_cep"><label>Faixas de CEP *</label>' +
                    '<input class="log_input" name="faixas_cep" value="' + attr(a.faixas_cep || '') + '" ' +
                    'placeholder="90000000-91999999, 92, 94900000-94999999">' +
                    '<span class="log_hint">Prefixos e/ou faixas, separados por vírgula. ' +
                    'Faixas podem se sobrepor — a área que aparece antes na lista vence.</span></div>' +
            '</div></div>' +

            '<div class="log_fieldset"><h4>Custo e prazo</h4><div class="log_form_grid">' +
                '<div class="log_field" id="fld-valor_base"><label>Custo base (R$) *</label>' +
                    '<input class="log_input" name="valor_base" value="' + attr(a.valor_base || '') + '">' +
                    '<span class="log_hint">É o custo da transportadora. Frete grátis e desconto ficam nas regras de frete.</span></div>' +
                '<div class="log_field log_field--curta"><label>Prazo (dias)</label>' +
                    '<input class="log_input" type="number" min="0" name="prazo_dias" value="' + attr(a.prazo_dias == null ? '' : a.prazo_dias) + '" placeholder="auto">' +
                    '<span class="log_hint">0 = mesmo dia. Vazio usa o cálculo pelo corte.</span></div>' +
                '<div class="log_field log_field--curta"><label>Corte (hora)</label>' +
                    '<input class="log_input" type="number" min="0" max="23" name="cutoff_hora" value="' + attr(a.cutoff_hora == null ? '' : a.cutoff_hora) + '" placeholder="herda"></div>' +
                '<div class="log_field log_field--curta"><label>Máx. envios/dia</label>' +
                    '<input class="log_input" type="number" min="0" name="max_envios_dia" value="' + attr(a.max_envios_dia == null ? '' : a.max_envios_dia) + '" placeholder="sem teto"></div>' +
                '<div class="log_field log_field--curta"><label>Ordem</label>' +
                    '<input class="log_input" type="number" name="ordem" value="' + attr(a.ordem == null ? 100 : a.ordem) + '">' +
                    '<span class="log_hint">Menor vence no empate.</span></div>' +
                '<div class="log_field"><label>&nbsp;</label><label class="log_toggle">' +
                    '<input type="checkbox" name="ativa"' + (a.id && !parseInt(a.ativa, 10) ? '' : ' checked') + '>' +
                    '<span class="log_toggle_track"></span><span>Área ativa</span></label></div>' +
            '</div></div>' +

            '<div class="log_fieldset"><h4>Desenho no mapa <span class="log_muted">(opcional)</span></h4>' +
                '<p class="log_muted cob_form_nota">Clique no mapa para posicionar o círculo. Ele é só ilustração — ' +
                'quem define a cobertura são as faixas de CEP acima.</p>' +
                '<div id="cobFormMapa" class="cob_form_mapa"></div>' +
                '<div class="log_form_grid cob_form_geo">' +
                    '<div class="log_field log_field--curta"><label>Raio (km)</label>' +
                        '<input class="log_input" type="number" step="0.5" min="0.5" name="mapa_raio_km" value="' + attr(a.mapa_raio_km || 3) + '"></div>' +
                    '<input type="hidden" name="mapa_lat" value="' + attr(a.mapa_lat || '') + '">' +
                    '<input type="hidden" name="mapa_lng" value="' + attr(a.mapa_lng || '') + '">' +
                    '<div class="log_field"><label>&nbsp;</label>' +
                        '<button type="button" class="log_btn log_btn--sm js-cob-limpar-geo">Remover do mapa</button></div>' +
                '</div>' +
            '</div>' +
        '</form>';
    }

    function abrirArea(a) {
        var drawer = adminDrawer({
            titulo: (a && a.id) ? 'Editar área' : 'Nova área',
            subtitulo: (a && a.nome) || 'Cobertura e custo',
            conteudo: formArea(a),
            tamanho: 'lg',
            acoes: '<button type="button" class="log_btn log_btn--primary log_btn--sm js-cob-salvar-area">' +
                   '<svg class="log_ico" aria-hidden="true" focusable="false"><use href="#i-check"></use></svg> Salvar</button>'
        });

        // mini-mapa do formulário
        setTimeout(function () {
            if (typeof L === 'undefined') return;
            var $b = $(drawer.corpo());
            var lat = parseFloat($b.find('[name=mapa_lat]').val());
            var lng = parseFloat($b.find('[name=mapa_lng]').val());
            var temGeo = !isNaN(lat) && !isNaN(lng);
            var m = L.map('cobFormMapa', { scrollWheelZoom: false })
                     .setView(temGeo ? [lat, lng] : CENTRO_PADRAO, temGeo ? 12 : 10);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 18, attribution: '&copy; OpenStreetMap'
            }).addTo(m);

            var circulo = null;
            function desenhar(la, ln) {
                var raio = (parseFloat($b.find('[name=mapa_raio_km]').val()) || 3) * 1000;
                if (circulo) m.removeLayer(circulo);
                circulo = L.circle([la, ln], { radius: raio, color: '#2563eb', fillColor: '#2563eb', fillOpacity: 0.15 }).addTo(m);
            }
            if (temGeo) desenhar(lat, lng);

            m.on('click', function (ev) {
                $b.find('[name=mapa_lat]').val(ev.latlng.lat.toFixed(7));
                $b.find('[name=mapa_lng]').val(ev.latlng.lng.toFixed(7));
                desenhar(ev.latlng.lat, ev.latlng.lng);
            });
            drawer.escutar('input', '[name=mapa_raio_km]', function () {
                var la = parseFloat($b.find('[name=mapa_lat]').val());
                var ln = parseFloat($b.find('[name=mapa_lng]').val());
                if (!isNaN(la) && !isNaN(ln)) desenhar(la, ln);
            });
            drawer.escutar('click', '.js-cob-limpar-geo', function () {
                $b.find('[name=mapa_lat], [name=mapa_lng]').val('');
                if (circulo) { m.removeLayer(circulo); circulo = null; }
                Toast.info('A área sai do mapa ao salvar.');
            });
            // o drawer abre com o container ainda sem tamanho final
            setTimeout(function () { m.invalidateSize(); }, 120);
        }, 60);

        drawer.escutar('click', '.js-cob-salvar-area', function () {
            var $b = $(drawer.corpo());
            var d = { transportadora_id: transp };
            $b.find('#cobAreaForm').find('input, select').each(function () {
                var $i = $(this), n = $i.attr('name');
                if (!n) return;
                d[n] = $i.attr('type') === 'checkbox' ? ($i.is(':checked') ? 1 : 0) : $i.val();
            });

            var tid = Toast.loading('Salvando área...');
            api('POST', '/area', LOG.comCsrf(d)).done(function (r) {
                if (r && r.ok) {
                    Toast.update(tid, { type: 'success', message: 'Área salva.', duration: 2200 });
                    drawer.fechar('salvo');
                    recarregar();
                } else if (r && r.erros) {
                    Toast.dismiss(tid);
                    $b.find('.log_err').remove();
                    $b.find('.log_field--erro').removeClass('log_field--erro');
                    Object.keys(r.erros).forEach(function (k) {
                        var $f = $b.find('#fld-' + $.escapeSelector(k));
                        if (!$f.length) $f = $b.find('[name="' + k + '"]').closest('.log_field');
                        $f.addClass('log_field--erro').append('<span class="log_err">' + esc(r.erros[k]) + '</span>');
                    });
                    Toast.error(r.erros[Object.keys(r.erros)[0]]);
                } else {
                    Toast.update(tid, { type: 'error', message: (r && r.erro) || 'Falha ao salvar.', duration: 3500 });
                }
            }).fail(function () {
                Toast.update(tid, { type: 'error', message: 'Erro de comunicação.', duration: 3500 });
            });
        });
    }

    // `confirmar` das outras telas vive dentro das IIFEs delas — esta tem a sua.
    function confirmar(titulo, texto, rotuloOk, onOk) {
        var d = adminDrawer({ titulo: titulo, tamanho: 'sm', conteudo: '<p>' + esc(texto) + '</p>',
            acoes: '<button type="button" class="log_btn log_btn--sm js-c">Voltar</button> <button type="button" class="log_btn log_btn--primary log_btn--sm js-o" style="background:var(--log-danger);border-color:var(--log-danger)">' + esc(rotuloOk) + '</button>' });
        d.escutar('click', '.js-c', function () { d.fechar(); });
        d.escutar('click', '.js-o', function () { onOk(d); });
    }

    /* ------------------------------------------------------- ações */

    $(function () {
        if (!dados) { pintarResumo(); return; }
        pintarTudo();

        $('#cobTransp').on('change', function () {
            window.location.search = '?transportadora=' + (parseInt($(this).val(), 10) || 0);
        });

        $('#cobNovaArea').on('click', function () { abrirArea(null); });

        $('#cobListaAreas').on('click', '.js-cob-editar', function () {
            var id = $(this).closest('[data-area]').data('area') | 0;
            var a = ((dados && dados.areas) || []).filter(function (x) { return (x.id | 0) === id; })[0];
            if (a) abrirArea(a);
        });

        $('#cobListaAreas').on('change', '.js-cob-ativa', function () {
            var $chk = $(this), $linha = $chk.closest('[data-area]');
            var id = $linha.data('area') | 0, ativa = $chk.is(':checked');
            api('POST', '/area/alternar', LOG.comCsrf({ id: id, ativa: ativa ? 1 : 0 })).done(function (r) {
                if (!r || !r.ok) { $chk.prop('checked', !ativa); Toast.error((r && r.erro) || 'Falha.'); return; }
                $linha.toggleClass('is-off', !ativa);
                recarregar();
            }).fail(function () { $chk.prop('checked', !ativa); Toast.error('Erro de comunicação.'); });
        });

        $('#cobListaAreas').on('click', '.js-cob-excluir', function () {
            var id = $(this).closest('[data-area]').data('area') | 0;
            var a = ((dados && dados.areas) || []).filter(function (x) { return (x.id | 0) === id; })[0] || {};
            confirmar('Excluir área', 'Remover "' + (a.nome || 'esta área') + '"? Os CEPs dela deixam de ser cobertos por esta configuração.', 'Excluir', function (d) {
                api('POST', '/area/excluir', LOG.comCsrf({ id: id })).done(function (r) {
                    if (r && r.ok) { Toast.success('Área excluída.'); d.fechar(); recarregar(); }
                    else Toast.error((r && r.erro) || 'Falha ao excluir.');
                }).fail(function () { Toast.error('Erro de comunicação.'); });
            });
        });

        // Ligar/desligar um grupo reabilita os campos na hora.
        $('#cobOperacao').on('change', '.js-op-ativa', function () {
            var $tr = $(this).closest('tr'), on = $(this).is(':checked');
            $tr.toggleClass('is-off', !on);
            $tr.find('select, input').not('.js-op-ativa').prop('disabled', !on);
            $tr.find('.cob_misto').remove();
        });

        $('#cobFeriados').on('change', '.js-cob-feriado', function () {
            var $l = $(this).closest('[data-data]'), opera = $(this).is(':checked');
            api('POST', '/feriado', LOG.comCsrf({
                transportadora_id: transp, data: $l.data('data'), opera: opera ? 1 : 0
            })).done(function (r) {
                if (!r || !r.ok) { Toast.error((r && r.erro) || 'Falha ao salvar o feriado.'); return; }
                $l.find('.cob_feriado_st').text(opera ? 'opera' : 'não opera');
                recarregar();
            }).fail(function () { Toast.error('Erro de comunicação.'); });
        });

        $('#cobSalvar').on('click', function () {
            var dias = [];
            $('#cobOperacao tr').each(function () {
                var $tr = $(this), on = $tr.find('.js-op-ativa').is(':checked');
                String($tr.data('grupo')).split(',').forEach(function (d) {
                    dias.push({
                        dia_semana: parseInt(d, 10),
                        opera: on ? 1 : 0,
                        mesmo_dia: $tr.find('.js-op-mesmodia').val(),
                        cutoff_hora: $tr.find('.js-op-corte').val(),
                        entrega_inicio: $tr.find('.js-op-ini').val(),
                        entrega_fim: $tr.find('.js-op-fim').val(),
                        max_envios: $tr.find('.js-op-max').val()
                    });
                });
            });

            var tid = Toast.loading('Salvando...');
            api('POST', '/operacao', LOG.comCsrf({
                transportadora_id: transp,
                dias: dias,
                limites: {
                    largura_cm: $('#cobLargura').val(),
                    altura_cm: $('#cobAltura').val(),
                    profundidade_cm: $('#cobProfundidade').val(),
                    peso_kg: $('#cobPeso').val()
                }
            })).done(function (r) {
                if (r && r.ok) {
                    Toast.update(tid, { type: 'success', message: 'Configuração salva.', duration: 2200 });
                    recarregar();
                } else {
                    Toast.update(tid, { type: 'error', message: (r && r.erro) || 'Falha ao salvar.', duration: 3500 });
                }
            }).fail(function () {
                Toast.update(tid, { type: 'error', message: 'Erro de comunicação.', duration: 3500 });
            });
        });

        // Teste de CEP: faixa sobreposta é normal, e sem isto o operador só
        // descobre qual área venceu quando um cliente reclama do preço.
        function testarCep() {
            var cep = String($('#cobCepTeste').val() || '').replace(/\D/g, '');
            var $res = $('#cobTesteRes');
            if (cep.length !== 8) { Toast.warning('Informe um CEP com 8 dígitos.'); return; }
            api('GET', '/testar-cep', { transportadora: transp, cep: cep }).done(function (r) {
                if (!r || !r.ok) { Toast.error((r && r.erro) || 'Falha no teste.'); return; }
                if (!r.area) {
                    $res.prop('hidden', false).html('<span class="log_badge is-neutral">Fora das áreas</span> ' +
                        '<span class="log_muted">Nenhuma área ativa cobre este CEP.</span>');
                    return;
                }
                var prazo = r.area.prazo_dias == null ? '' :
                    ' &middot; ' + (r.area.prazo_dias === 0 ? 'mesmo dia' : 'D+' + r.area.prazo_dias);
                $res.prop('hidden', false).html('<span class="log_badge is-ok">' + esc(r.area.nome) + '</span> ' +
                    '<strong>' + moeda(r.area.valor_base) + '</strong><span class="log_muted">' + prazo + '</span>');
            }).fail(function () { Toast.error('Erro de comunicação.'); });
        }
        $('#cobTestar').on('click', testarCep);
        $('#cobCepTeste').on('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); testarCep(); } });
    });

    })();

})(jQuery);
