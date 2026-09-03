/* =====================================================================
   Log de operações do Bling — filtro, paginação e detalhe em drawer.

   A tabela é sempre pintada aqui, inclusive na primeira carga: o servidor
   manda a página 1 já resolvida em BLING_LOG.inicial. Um template só — em
   PHP e em JS, dois templates divergem no primeiro ajuste.
   ===================================================================== */
(function ($) {
    'use strict';

    if (!window.BLING_LOG) return;

    var $corpo  = $('#blgCorpo');
    var $pag    = $('#blgPaginacao');
    var $total  = $('#blgTotal');
    var $form   = $('#blgFiltros');
    if (!$corpo.length) return;

    var pagina = 1;
    var carregando = false;

    function esc(v) { return $('<i>').text(v == null ? '' : String(v)).html(); }

    function filtros() {
        var f = {};
        $form.find('input, select').each(function () {
            var v = String(this.value || '').trim();
            if (v) f[this.name] = v;
        });
        return f;
    }

    /* ── tabela ─────────────────────────────────────────── */
    function classeStatus(s) {
        return s === 'ok' ? 'success' : (s === 'erro' ? 'danger' : 'warning');
    }

    function pintar(r) {
        $total.text(r.total + (r.total === 1 ? ' operação' : ' operações'));

        if (!r.itens.length) {
            $corpo.html('<tr><td colspan="6" class="blg_vazio">' +
                'Nenhuma operação com esses filtros.</td></tr>');
            $pag.empty();
            return;
        }

        $corpo.html(r.itens.map(function (i) {
            // A referência crua ("/estoques/saldos/148…") não responde à
            // pergunta que se faz olhando um log, então vem o resumo embaixo.
            return '<tr class="blg_linha" data-id="' + (i.id | 0) + '" tabindex="0">' +
                '<td class="blg_data">' + esc(i.criado_br) + '</td>' +
                '<td><span class="badge">' + esc(i.tipo) + '</span></td>' +
                '<td class="blg_dir">' + esc(i.direcao) + '</td>' +
                '<td>' +
                  '<code class="blg_ref">' + esc(i.referencia_id || '—') + '</code>' +
                  '<div class="blg_resumo' + (i.status === 'erro' ? ' is-erro' : '') + '">' +
                    esc(i.resumo) + '</div>' +
                '</td>' +
                '<td><span class="badge badge-' + classeStatus(i.status) + '">' +
                  esc(i.status) + '</span></td>' +
                '<td class="blg_seta" aria-hidden="true">›</td>' +
                '</tr>';
        }).join(''));

        pintarPaginacao(r);
    }

    function pintarPaginacao(r) {
        if (r.paginas <= 1) {
            $pag.html('<span class="blg_intervalo">' + r.de + '–' + r.ate + ' de ' + r.total + '</span>');
            return;
        }

        var b = function (rot, alvo, ativo, titulo) {
            return '<button type="button" class="blg_pag_btn' + (ativo ? '' : ' is-off') + '"' +
                   (ativo ? ' data-pagina="' + alvo + '"' : ' disabled') +
                   (titulo ? ' title="' + titulo + '"' : '') + '>' + rot + '</button>';
        };

        $pag.html(
            '<span class="blg_intervalo">' + r.de + '–' + r.ate + ' de ' + r.total + '</span>' +
            '<span class="blg_pag_nav">' +
              b('«', 1, r.pagina > 1, 'Primeira') +
              b('‹', r.pagina - 1, r.pagina > 1, 'Anterior') +
              '<span class="blg_pag_atual">' + r.pagina + ' de ' + r.paginas + '</span>' +
              b('›', r.pagina + 1, r.pagina < r.paginas, 'Próxima') +
              b('»', r.paginas, r.pagina < r.paginas, 'Última') +
            '</span>'
        );
    }

    /* ── carga ──────────────────────────────────────────── */
    function carregar(p) {
        if (carregando) return;
        carregando = true;
        pagina = p || 1;

        $corpo.addClass('is-carregando');

        var dados = filtros();
        dados.pagina = pagina;

        $('#blgNotaProduto').prop('hidden', !dados.produto_id && !dados.sku_legado);

        $.getJSON(window.BLING_LOG.base, dados)
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

    $('#blgFiltrar').on('click', function () { carregar(1); });

    $('#blgLimpar').on('click', function () {
        $form.find('input').val('');
        $form.find('select').val('');
        carregar(1);
    });

    // Enter em qualquer campo filtra — o formulário tem 8 campos e obrigar a
    // achar o botão depois de digitar um SKU é atrito à toa.
    $form.on('keydown', 'input', function (ev) {
        if (ev.key === 'Enter') { ev.preventDefault(); carregar(1); }
    });
    $form.on('change', 'select', function () { carregar(1); });

    $pag.on('click', '.blg_pag_btn[data-pagina]', function () {
        carregar(parseInt($(this).data('pagina'), 10) || 1);
    });

    /* ── detalhe ────────────────────────────────────────── */
    function bloco(titulo, conteudo, mono) {
        if (!conteudo) return '';
        return '<div class="blgd_bloco">' +
               '<div class="blgd_rotulo">' + esc(titulo) + '</div>' +
               (mono ? '<pre class="blgd_json">' + esc(conteudo) + '</pre>'
                     : '<div class="blgd_valor">' + esc(conteudo) + '</div>') +
               '</div>';
    }

    function conteudoDetalhe(l) {
        var h = '<div class="blgd_topo">' +
            '<span class="badge badge-' + classeStatus(l.status) + '">' + esc(l.status) + '</span>' +
            '<span class="badge">' + esc(l.tipo) + '</span>' +
            '<span class="blgd_dir">' + esc(l.direcao) + '</span>' +
            '<span class="blgd_data">' + esc(l.criado_br) + '</span>' +
            '</div>';

        h += '<div class="blgd_grade">' +
            bloco('Referência', l.referencia_id || '—') +
            bloco('Recurso', l.recurso || '—') +
            bloco('ID no Bling', l.bling_id || '—') +
            bloco('Registro', '#' + l.id) +
            '</div>';

        // Só aparece quando a referência casou com um produto nosso; para
        // pedido e contato não há o que resolver, e um campo vazio ali só
        // levantaria a dúvida de por que está vazio.
        if (l.produto) {
            h += '<div class="blgd_produto">' +
                '<div class="blgd_rotulo">Produto</div>' +
                '<a href="' + ADMIN_URL + '/produtos/editar/' + (l.produto.id | 0) + '" target="_blank" rel="noopener">' +
                  esc(l.produto.nome) + '</a>' +
                '<div class="blgd_produto_meta">#' + (l.produto.id | 0) +
                  (l.produto.sku_legado ? ' · SKU ' + esc(l.produto.sku_legado) : '') +
                  ' · vinculado pelo ' + esc(l.produto.via) + '</div>' +
                '</div>';
        }

        if (l.msg_erro) {
            h += '<div class="blgd_erro"><div class="blgd_rotulo">Erro</div>' +
                 '<pre>' + esc(l.msg_erro) + '</pre></div>';
        }

        h += bloco('Enviado (payload)', l.payload_fmt, true);
        h += bloco('Resposta do Bling', l.resposta_fmt, true);

        return h;
    }

    function abrirDetalhe(id) {
        var drawer = adminDrawer({
            titulo: 'Operação #' + id,
            subtitulo: 'Log do Bling',
            tamanho: 'lg'
        });
        drawer.setCarregando('Carregando a operação…');

        $.getJSON(window.BLING_LOG.base + '/' + id)
            .done(function (r) {
                if (!r || !r.ok) {
                    drawer.setTexto((r && r.msg) || 'Não foi possível carregar.');
                    return;
                }
                var l = r.log;
                drawer.setSubtitulo(l.tipo + ' · ' + l.direcao + ' · ' + l.criado_br);
                drawer.setConteudo(conteudoDetalhe(l));
            })
            .fail(function () { drawer.setTexto('Erro de rede ao carregar a operação.'); });
    }

    $corpo.on('click', '.blg_linha', function () { abrirDetalhe($(this).data('id')); });

    // A linha é focável; sem isto o log só existe para quem usa mouse.
    $corpo.on('keydown', '.blg_linha', function (ev) {
        if (ev.key === 'Enter' || ev.key === ' ') {
            ev.preventDefault();
            abrirDetalhe($(this).data('id'));
        }
    });

    /* ── início ─────────────────────────────────────────── */
    if (window.BLING_LOG.inicial) pintar(window.BLING_LOG.inicial);
})(jQuery);
