/* =====================================================================
   Log de chamadas do webhook — filtro, paginação e detalhe em drawer.
   Tela: /admin/chat/config

   A tabela é sempre pintada aqui, inclusive na primeira carga: o servidor
   manda a página 1 já resolvida em CHAT_WH_LOG.inicial. Um template só —
   dois (um em PHP, outro em JS) divergem no primeiro ajuste.
   ===================================================================== */
(function ($) {
    'use strict';

    if (!window.CHAT_WH_LOG) return;

    var $corpo  = $('#ch-wh-corpo');
    var $pag    = $('#ch-wh-paginacao');
    var $total  = $('#ch-wh-total');
    var $resumo = $('#ch-wh-resumo');
    var $form   = $('#ch-wh-filtros');
    if (!$corpo.length) return;

    var carregando = false;

    /* O estado sai de três booleanos no servidor; aqui só a aparência.
       "sem ação" é âmbar e não vermelho de propósito: a coluna `erro` guarda
       tanto falha real quanto motivo legítimo de descarte ("nenhuma regra
       casou"), e pintar tudo de vermelho faz ninguém mais olhar o log. */
    var ESTADOS = {
        ok:       { rot: 'Processada',    cls: 'ok'     },
        sem_acao: { rot: 'Sem ação',      cls: 'aviso'  },
        ignorado: { rot: 'Só registrada', cls: 'neutro' },
        recusado: { rot: 'Recusada',      cls: 'erro'   }
    };

    var CANAIS = { whatsapp: 'WhatsApp', instagram: 'Instagram', entrada: 'Entrada' };

    function esc(v) { return $('<i>').text(v == null ? '' : String(v)).html(); }

    function filtros() {
        var f = {};
        $form.find('input, select').each(function () {
            var v = String(this.value || '').trim();
            if (v) f[this.name] = v;
        });
        return f;
    }

    /* ── resumo por estado ──────────────────────────────── */
    function pintarResumo(r) {
        if (!r) { $resumo.empty(); return; }

        var itens = Object.keys(ESTADOS).filter(function (k) { return (r[k] | 0) > 0; });
        if (!itens.length) { $resumo.empty(); return; }

        // Clicável: o resumo mostra a divisão, e clicar nele filtra por aquele
        // estado — é o gesto que a pessoa tenta assim que vê o número.
        $resumo.html(itens.map(function (k) {
            var e = ESTADOS[k];
            return '<button type="button" class="ch-wh-chip ch-wh-chip--' + e.cls + '" data-estado="' + k + '">' +
                   '<strong>' + (r[k] | 0) + '</strong> ' + esc(e.rot) + '</button>';
        }).join(''));
    }

    /* ── tabela ─────────────────────────────────────────── */
    function pintar(r) {
        $total.text(r.total + (r.total === 1 ? ' chamada' : ' chamadas'));
        pintarResumo(r.resumo);

        if (!r.itens.length) {
            $corpo.html('<tr><td colspan="5" class="ch-wh-vazio">' +
                'Nenhuma chamada com esses filtros.</td></tr>');
            $pag.empty();
            return;
        }

        $corpo.html(r.itens.map(function (i) {
            var e = ESTADOS[i.estado] || ESTADOS.ignorado;

            // "O que aconteceu": o motivo quando existe, senão o identificador
            // da mensagem. O log velho mostrava as duas coisas em colunas
            // separadas e quase sempre vazias.
            var oQue = i.erro
                ? '<span class="ch-wh-motivo">' + esc(i.erro) + '</span>'
                : (i.wamid ? '<code class="ch-wh-id">' + esc(i.wamid) + '</code>'
                           : '<span class="ch-mut">—</span>');

            return '<tr class="ch-wh-linha" data-id="' + (i.id | 0) + '" tabindex="0">' +
                '<td class="ch-sm ch-mut ch-wh-data">' + esc(i.criado_br) + '</td>' +
                '<td>' +
                  '<div class="ch-wh-evento">' + esc(i.rotulo) + '</div>' +
                  '<div class="ch-sm ch-mut">' + esc(CANAIS[i.canal] || i.canal) + '</div>' +
                '</td>' +
                '<td class="ch-sm">' + oQue +
                  (i.tam_legivel ? ' <span class="ch-wh-tam">' + esc(i.tam_legivel) + '</span>' : '') +
                '</td>' +
                '<td><span class="ch-badge ch-badge--' + e.cls + '">' + esc(e.rot) + '</span></td>' +
                '<td class="ch-wh-seta" aria-hidden="true">›</td>' +
                '</tr>';
        }).join(''));

        pintarPaginacao(r);
    }

    function pintarPaginacao(r) {
        if (r.paginas <= 1) {
            $pag.html('<span class="ch-wh-intervalo">' + r.de + '–' + r.ate + ' de ' + r.total + '</span>');
            return;
        }

        var b = function (rot, alvo, ativo, titulo) {
            return '<button type="button" class="ch-wh-pag' + (ativo ? '' : ' is-off') + '"' +
                   (ativo ? ' data-pagina="' + alvo + '"' : ' disabled') +
                   (titulo ? ' title="' + titulo + '"' : '') + '>' + rot + '</button>';
        };

        $pag.html(
            '<span class="ch-wh-intervalo">' + r.de + '–' + r.ate + ' de ' + r.total + '</span>' +
            '<span class="ch-wh-nav">' +
              b('«', 1, r.pagina > 1, 'Primeira') +
              b('‹', r.pagina - 1, r.pagina > 1, 'Anterior') +
              '<span class="ch-wh-atual">' + r.pagina + ' de ' + r.paginas + '</span>' +
              b('›', r.pagina + 1, r.pagina < r.paginas, 'Próxima') +
              b('»', r.paginas, r.pagina < r.paginas, 'Última') +
            '</span>'
        );
    }

    /* ── carga ──────────────────────────────────────────── */
    function carregar(p) {
        if (carregando) return;
        carregando = true;

        $corpo.addClass('is-carregando');

        var dados = filtros();
        dados.pagina = p || 1;

        $.getJSON(window.CHAT_WH_LOG.base, dados)
            .done(function (r) {
                if (!r || !r.ok) { showToast('Falha ao carregar o log.', 'error'); return; }
                pintar(r);
            })
            .fail(function () { showToast('Erro de rede ao carregar o log.', 'error'); })
            .always(function () {
                carregando = false;
                $corpo.removeClass('is-carregando');
            });
    }

    $('#ch-wh-filtrar').on('click', function () { carregar(1); });

    $('#ch-wh-limpar').on('click', function () {
        $form.find('input').val('');
        $form.find('select').val('');
        carregar(1);
    });

    // Enter em qualquer campo filtra — obrigar a achar o botão depois de
    // digitar é atrito à toa.
    $form.on('keydown', 'input', function (ev) {
        if (ev.key === 'Enter') { ev.preventDefault(); carregar(1); }
    });
    $form.on('change', 'select, input[type=date]', function () { carregar(1); });

    // Clicar no resumo filtra por aquele estado; clicar de novo desfaz.
    $resumo.on('click', '.ch-wh-chip', function () {
        var alvo = String($(this).data('estado'));
        var $sel = $('#ch-wh-estado');
        $sel.val($sel.val() === alvo ? '' : alvo);
        carregar(1);
    });

    $pag.on('click', '.ch-wh-pag[data-pagina]', function () {
        carregar(parseInt($(this).data('pagina'), 10) || 1);
    });

    /* ── detalhe ────────────────────────────────────────── */
    function linha(rot, valor, mono) {
        if (valor === null || valor === undefined || valor === '') return '';
        return '<div class="chwd_bloco">' +
               '<div class="chwd_rotulo">' + esc(rot) + '</div>' +
               (mono ? '<pre class="chwd_json">' + esc(valor) + '</pre>'
                     : '<div class="chwd_valor">' + esc(valor) + '</div>') +
               '</div>';
    }

    function conteudoDetalhe(l) {
        var e = ESTADOS[l.estado] || ESTADOS.ignorado;

        var h = '<div class="chwd_topo">' +
            '<span class="ch-badge ch-badge--' + e.cls + '">' + esc(e.rot) + '</span>' +
            '<span class="ch-badge ch-badge--neutro">' + esc(CANAIS[l.canal] || l.canal) + '</span>' +
            '<span class="chwd_data">' + esc(l.criado_br) + '</span>' +
            '</div>';

        h += '<div class="chwd_grade">' +
            linha('Evento', l.rotulo) +
            linha('Slug do evento', l.evento) +
            linha('Assinatura', (l.assinatura_ok | 0) ? 'válida' : 'inválida') +
            linha('Processada', (l.processado | 0) ? 'sim' : 'não') +
            linha('ID da mensagem', l.wamid) +
            linha('IP de origem', l.ip) +
            linha('Registro', '#' + l.id) +
            '</div>';

        // O motivo aparece na íntegra e sem cor de erro quando não é erro: a
        // mesma coluna guarda "nenhuma regra casou" e falha de verdade.
        if (l.erro) {
            h += '<div class="chwd_motivo' + (l.estado === 'recusado' ? ' is-erro' : '') + '">' +
                 '<div class="chwd_rotulo">Por que não virou ação</div>' +
                 '<div>' + esc(l.erro) + '</div></div>';
        }

        // Resumo antes do JSON: abrir 60 KB para descobrir quem mandou
        // "quanto custa?" é o tipo de trabalho que faz ninguém abrir o log.
        var chaves = l.resumo_dados ? Object.keys(l.resumo_dados) : [];
        if (chaves.length) {
            h += '<div class="chwd_resumo"><div class="chwd_rotulo">No conteúdo</div>' +
                 '<dl>' + chaves.map(function (k) {
                     return '<dt>' + esc(k) + '</dt><dd>' + esc(l.resumo_dados[k]) + '</dd>';
                 }).join('') + '</dl></div>';
        }

        h += linha('Conteúdo recebido' + (l.tam_legivel ? ' (' + l.tam_legivel + ')' : ''),
                   l.payload_fmt, true);

        if (!l.payload_fmt) {
            h += '<p class="ch-sm ch-mut">A Meta não mandou corpo nesta chamada.</p>';
        }

        return h;
    }

    function abrirDetalhe(id) {
        var drawer = adminDrawer({
            titulo: 'Chamada #' + id,
            subtitulo: 'Webhook do chat',
            tamanho: 'lg'
        });
        drawer.setCarregando('Carregando a chamada…');

        $.getJSON(window.CHAT_WH_LOG.base + '/' + id)
            .done(function (r) {
                if (!r || !r.ok) {
                    drawer.setTexto((r && r.erro) || 'Não foi possível carregar.');
                    return;
                }
                var l = r.log;
                drawer.setSubtitulo(l.rotulo + ' · ' + l.criado_br);
                drawer.setConteudo(conteudoDetalhe(l));
            })
            .fail(function () { drawer.setTexto('Erro de rede ao carregar a chamada.'); });
    }

    $corpo.on('click', '.ch-wh-linha', function () { abrirDetalhe($(this).data('id')); });

    // A linha é focável; sem isto o log só existe para quem usa mouse.
    $corpo.on('keydown', '.ch-wh-linha', function (ev) {
        if (ev.key === 'Enter' || ev.key === ' ') {
            ev.preventDefault();
            abrirDetalhe($(this).data('id'));
        }
    });

    /* ── início ─────────────────────────────────────────── */
    if (window.CHAT_WH_LOG.inicial) {
        var ini = window.CHAT_WH_LOG.inicial;
        ini.resumo = window.CHAT_WH_LOG.resumo;
        pintar(ini);
    }
})(jQuery);
