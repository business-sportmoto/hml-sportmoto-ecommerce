/* =====================================================================
   Separação / checkout de expedição (jQuery v4).

   Duas telas, um arquivo, cada bloco guardado pelo seu elemento raiz:
     #sepPainel — a fila e a impressão
     #sepConf   — a conferência de um pedido

   A conferência acontece só no navegador: bipar não grava nada. O operador
   está batendo a caixa contra o pedido, e o que persiste é o resultado —
   a etiqueta emitida no fim.
   ===================================================================== */
(function ($) {
    'use strict';

    function esc(s) { return $('<i>').text(s == null ? '' : String(s)).html(); }
    function csrf(o) {
        o = o || {};
        o.csrf_token = window.CSRF_TOKEN || '';
        o._token = window.CSRF_TOKEN || '';
        return o;
    }
    function aviso(msg, tipo) {
        if (window.Toast && Toast.show) { Toast.show({ message: msg, type: tipo || 'info' }); return; }
        if (window.CK && CK.toast) { CK.toast(msg, tipo || 'info'); return; }
        alert(msg);
    }

    /* ── impressao direta ───────────────────────────────────────────────
       Nenhuma pagina web escolhe a impressora nem pula a caixa de dialogo:
       nao existe API para isso, e nao ha truque que contorne. O que existe e
       o Chrome aberto com --kiosk-printing — ali window.print() imprime direto
       na impressora padrao, sem dialogo.

       O codigo abaixo e o mesmo nos dois casos. A flag na maquina da expedicao
       decide se aparece dialogo; nada aqui muda.

       Cada documento vai num iframe oculto, e a fila espera um terminar antes
       de comecar o proximo: dois print() simultaneos se atropelam e um dos
       jobs some sem aviso nenhum.
       ------------------------------------------------------------------ */
    var Impressao = (function () {

        function documento(url) {
            return new Promise(function (resolve) {
                var $if = $('<iframe>', { 'aria-hidden': 'true' }).css({
                    position: 'fixed', left: '-10000px', top: 0,
                    width: '1px', height: '1px', border: 0
                }).appendTo('body');

                var feito = false;

                function encerrar() {
                    // O iframe so sai depois: removido antes de o spool aceitar,
                    // o job e cancelado no meio e nao sai papel.
                    setTimeout(function () { $if.remove(); }, 10000);
                    resolve();
                }

                $if.on('load', function () {
                    if (feito) return;
                    feito = true;
                    // O visualizador de PDF do Chrome precisa de um instante
                    // depois do load antes de aceitar print().
                    setTimeout(function () {
                        try {
                            $if[0].contentWindow.focus();
                            $if[0].contentWindow.print();
                        } catch (e) {
                            window.open(url, '_blank', 'noopener');   // outra origem
                        }
                        encerrar();
                    }, 400);
                });

                // Documento que nao carrega nao pode travar a fila.
                setTimeout(function () {
                    if (feito) return;
                    feito = true;
                    $if.remove();
                    aviso('Nao foi possivel carregar um documento para impressao.', 'warning');
                    resolve();
                }, 20000);

                $if.attr('src', url);
            });
        }

        return {
            /** Imprime na ordem recebida, um esperando o outro. */
            fila: function (urls) {
                return (urls || []).filter(Boolean).reduce(function (p, u) {
                    return p.then(function () { return documento(u); });
                }, Promise.resolve());
            }
        };
    })();

    /* ═══════════════════════════════════════════════════════
       TELA: fila de separação
       ═══════════════════════════════════════════════════════ */
    (function () {
        var $painel = $('#sepPainel');
        if (!$painel.length) return;

        function selecionados() {
            return $painel.find('.sep_check:checked').map(function () { return this.value; }).get();
        }

        function atualizarContador() {
            var n = selecionados().length;
            $('#sepCount').text(n);
            $('#sepImprimirTodos').prop('disabled', n === 0);
        }

        // A impressão abre em outra aba: a fila continua aberta para o operador
        // seguir de onde parou, e a aba de impressão fecha sozinha depois.
        function imprimir(ids) {
            if (!ids.length) { aviso('Selecione ao menos um pedido.', 'warning'); return; }
            window.open(window.SEP_BASE + '/imprimir?ids=' + ids.join(','), '_blank', 'noopener');
            // Os pedidos saem da fila ao imprimir; recarrega para refletir.
            setTimeout(function () { window.location.reload(); }, 1200);
        }

        $painel.on('change', '#sepTodos', function () {
            $painel.find('.sep_check').prop('checked', this.checked);
            atualizarContador();
        });

        $painel.on('change', '.sep_check', function () {
            var todos = $painel.find('.sep_check').length;
            var marcados = $painel.find('.sep_check:checked').length;
            $('#sepTodos').prop('checked', todos === marcados);
            atualizarContador();
        });

        $painel.on('click', '#sepImprimirTodos', function () { imprimir(selecionados()); });

        $painel.on('click', '.js-imprimir-um', function () {
            imprimir([String($(this).closest('tr').data('id'))]);
        });

        atualizarContador();
    })();

    /* ═══════════════════════════════════════════════════════
       TELA: conferência de um pedido
       ═══════════════════════════════════════════════════════ */
    (function () {
        var $conf = $('#sepConf');
        if (!$conf.length) return;

        var pedidoId = parseInt($conf.data('pedido'), 10) || 0;
        var totalPecas = 0;
        $conf.find('.sep_item').each(function () { totalPecas += parseInt($(this).data('qtd'), 10) || 0; });

        function conferidas() {
            var n = 0;
            $conf.find('.sep_item .sep_c').each(function () { n += parseInt($(this).text(), 10) || 0; });
            return n;
        }

        function pintar() {
            var n = conferidas();
            $('#sepConferidas').text(n);
            var pct = totalPecas ? Math.round((n / totalPecas) * 100) : 0;
            $('#sepFill').css('width', pct + '%');
            $conf.toggleClass('is-completo', totalPecas > 0 && n >= totalPecas);
            $('#sepGerarEtiqueta').prop('disabled', !(totalPecas > 0 && n >= totalPecas) || !$('#sepTransp').val());
        }

        function marcar($linha) {
            var qtd  = parseInt($linha.data('qtd'), 10) || 0;
            var $c   = $linha.find('.sep_c');
            var atual = parseInt($c.text(), 10) || 0;
            if (atual >= qtd) {
                aviso('Este item já está completo.', 'warning');
                return false;
            }
            $c.text(atual + 1);
            $linha.toggleClass('is-ok', (atual + 1) >= qtd);
            // Piscada curta: o operador não olha a tela enquanto bipa, olha depois.
            $linha.addClass('is-bipado');
            setTimeout(function () { $linha.removeClass('is-bipado'); }, 600);
            pintar();
            return true;
        }

        // ── bipagem ─────────────────────────────────────────
        var $campo = $('#sepCodigo');

        $campo.on('keydown', function (ev) {
            if (ev.key !== 'Enter') return;
            ev.preventDefault();

            var codigo = String($campo.val() ?? '').trim();
            if (!codigo) return;
            $campo.val('').trigger('focus');

            $.ajax({
                url: window.SEP_BASE + '/bipar',
                method: 'POST',
                dataType: 'json',
                data: csrf({ pedido_id: pedidoId, codigo: codigo }),
                headers: { 'X-CSRF-TOKEN': window.CSRF_TOKEN || '' }
            }).done(function (r) {
                if (!r || !r.ok) {
                    aviso((r && r.msg) || 'Código não reconhecido.', 'error');
                    return;
                }
                // Um codigo de produto (sku_legado) pode casar com mais de uma
                // variacao do mesmo pedido. O servidor manda todas; aqui vale a
                // que ainda tem peca faltando, senao a primeira sempre estouraria.
                var ids = (r.item_ids && r.item_ids.length) ? r.item_ids : [r.item_id];
                var $alvo = null, $primeira = null;

                ids.forEach(function (id) {
                    var $l = $conf.find('.sep_item[data-item="' + id + '"]');
                    if (!$l.length) return;
                    if (!$primeira) $primeira = $l;
                    if (!$alvo && (parseInt($l.find('.sep_c').text(), 10) || 0)
                                < (parseInt($l.data('qtd'), 10) || 0)) $alvo = $l;
                });

                $alvo = $alvo || $primeira;
                if (!$alvo) { aviso('Item não está nesta tela.', 'error'); return; }
                marcar($alvo);
            }).fail(function () {
                aviso('Falha ao consultar o código.', 'error');
            });
        });

        // Item sem EAN: confere no clique, uma peça por vez.
        $conf.on('click', '.js-conferir-manual', function () {
            marcar($(this).closest('.sep_item'));
        });

        // ── transportadoras para a etiqueta ─────────────────
        var $sel = $('#sepTransp');
        if ($sel.length) {
            $.getJSON(window.SEP_OPCOES).done(function (r) {
                $sel.empty().append($('<option>', { value: '', text: 'Selecione...' }));
                ((r && r.transportadoras) || []).forEach(function (t) {
                    (t.servicos || []).forEach(function (s) {
                        $sel.append($('<option>', {
                            value: t.id + '|' + s.codigo,
                            text: t.nome + ' — ' + s.nome
                        }));
                    });
                });
                if ($sel.find('option').length <= 1) {
                    $sel.empty().append($('<option>', { value: '', text: 'Nenhum serviço de envio cadastrado' }));
                }
            }).fail(function () {
                $sel.empty().append($('<option>', { value: '', text: 'Falha ao carregar transportadoras' }));
            });

            $sel.on('change', pintar);
        }

        // ── emitir etiqueta ─────────────────────────────────
        $('#sepGerarEtiqueta').on('click', function () {
            var val = String($sel.val() || '');
            if (!val) { aviso('Selecione a transportadora.', 'warning'); return; }

            var partes = val.split('|');
            var $btn = $(this);
            if (!window.confirm('Emitir a etiqueta agora? A transportadora cobra por isso.')) return;

            $btn.prop('disabled', true).text('Emitindo...');
            $.ajax({
                url: window.SEP_BASE + '/' + pedidoId + '/etiqueta',
                method: 'POST',
                dataType: 'json',
                data: csrf({
                    transportadora_id: partes[0],
                    servico_codigo: partes[1],
                    servico_nome: $sel.find('option:selected').text()
                }),
                headers: { 'X-CSRF-TOKEN': window.CSRF_TOKEN || '' }
            }).done(function (r) {
                if (!r || !r.ok) {
                    aviso((r && r.msg) || 'Falha ao emitir a etiqueta.', 'error');
                    $btn.prop('disabled', false).text('Gerar etiqueta');
                    return;
                }
                aviso('Etiqueta emitida' + (r.codigo_rastreio ? ' — ' + r.codigo_rastreio : '') + '.', 'success');
                setTimeout(function () { window.location.reload(); }, 900);
            }).fail(function () {
                aviso('Falha de rede ao emitir a etiqueta.', 'error');
                $btn.prop('disabled', false).text('Gerar etiqueta');
            });
        });

        pintar();
        $campo.trigger('focus');
    })();


    /* ═══════════════════════════════════════════════════════
       TELA: estação de bipagem

       O ciclo inteiro é feito com o leitor, sem mouse nem teclado:

         bipa o pedido  → foco vai para o campo de produtos
         bipa os itens  → ao fechar a conta, o botão aparece e recebe foco
         Enter          → imprime NF + etiqueta e volta para o campo do pedido

       A conferência dos itens é resolvida no cliente: os dados já vieram na
       leitura do pedido, e uma ida ao servidor por bipada colocaria latência
       no meio de um gesto que precisa ser instantâneo.
       ═══════════════════════════════════════════════════════ */
    (function () {
        var $est = $('#sepEstacao');
        if (!$est.length) return;

        var $campo    = $('#estCodigo');
        var $produto  = $('#estProduto');
        var $status   = $('#estStatus');
        var $statusP  = $('#estStatusProd');

        var sessao  = [];      // memoria do turno, so no navegador
        var atual   = null;    // pedido carregado
        var lidos   = {};      // item_id -> pecas ja bipadas

        function attr(s) { return esc(s).replace(/"/g, '&quot;'); }
        function foco($el) { if ($el && $el.length) $el.trigger('focus').trigger('select'); }

        function status($alvo, msg, tipo) {
            $alvo.attr('class', ($alvo.is($statusP) ? 'est_status_prod' : 'est_status') + ' is-' + (tipo || 'info'))
                 .text(msg || '');
        }

        function brl(v) { return 'R$ ' + (Number(v) || 0).toFixed(2).replace('.', ','); }

        function totalPecas() {
            if (!atual) return 0;
            return (atual.itens || []).reduce(function (n, i) { return n + (i.quantidade | 0); }, 0);
        }
        function totalLidas() {
            return Object.keys(lidos).reduce(function (n, k) { return n + lidos[k]; }, 0);
        }

        /* ── render do pedido ──────────────────────────── */
        function linhaItem(i) {
            var img = i.imagem
                ? '<img class="est_item_img" src="' + attr(i.imagem) + '" alt="">'
                : '<span class="est_item_img est_item_img--vazia"></span>';

            var meta = [];
            if (i.variacao) meta.push(esc(i.variacao));
            if (i.sku)      meta.push('SKU ' + esc(i.sku));
            // Sem EAN o operador precisa ver QUAL codigo serve, senao fica
            // sabendo apenas que nao da para bipar — que era o caso antes.
            if (i.ean)             meta.push('EAN ' + esc(i.ean));
            else if (i.sku_legado) meta.push('REF ' + esc(i.sku_legado));
            else                   meta.push('<b>sem código</b>');

            return '<div class="est_item" data-item="' + (i.id | 0) + '" data-qtd="' + (i.quantidade | 0) + '">' +
                   img +
                   '<div class="est_item_txt">' +
                     '<div class="est_item_nome">' + esc(i.nome) + '</div>' +
                     '<div class="est_item_meta">' + meta.join(' · ') + '</div>' +
                   '</div>' +
                   '<div class="est_item_conta"><span class="est_lido">0</span>/' + (i.quantidade | 0) + '</div>' +
                   '<div class="est_item_preco">' + brl(i.preco) + '</div>' +
                   '</div>';
        }

        function mostrar(p) {
            var h = '<div class="est_campos">' +
                '<div><span>Nº do pedido</span><strong>#' + (p.id | 0) + '</strong>' +
                  '<div class="est_mono">' + esc(p.codigo) + '</div></div>' +
                '<div><span>Nº de rastreio</span><strong class="est_mono">' +
                  (p.codigo_rastreio ? esc(p.codigo_rastreio) : '—') + '</strong></div>' +
                '<div><span>Método de envio</span><strong>' +
                  (p.metodo_envio ? esc(p.metodo_envio) : '—') + '</strong></div>' +
                '<div><span>Destinatário</span><strong>' + esc(p.destinatario) + '</strong>' +
                  '<div class="est_mono">' + esc(p.cidade_uf) + '</div></div>' +
                '</div>';

            h += '<div class="est_selos">' +
                 '<span class="sep_tag ' + (p.nfe_ok ? 'sep_tag--ok' : 'sep_tag--espera') + '">' +
                   (p.nfe_ok ? 'NF ' + esc(p.nfe_numero) : 'sem NF-e') + '</span>' +
                 '<span class="sep_tag sep_tag--neutro">' + (p.pecas | 0) + ' peça(s)</span>' +
                 '<span class="sep_tag sep_tag--neutro">' + brl(p.total) + '</span>' +
                 '</div>';

            h += '<div class="est_secao">SKU / Variação</div><div class="est_itens">';
            (p.itens || []).forEach(function (i) { h += linhaItem(i); });
            h += '</div>';

            h += '<a class="btn btn-secondary est_ir" href="' + attr(p.url_conferencia) + '">Abrir conferência detalhada</a>';

            $('#estVazio').prop('hidden', true);
            $('#estPedido').prop('hidden', false).html(h);
        }

        /* ── conferência dos itens ─────────────────────── */
        function pintarProgresso() {
            var t = totalPecas(), n = totalLidas();
            $('#estConferidas').text(n);
            $('#estTotalPecas').text(t);
            $('#estFill').css('width', (t ? Math.round((n / t) * 100) : 0) + '%');

            var completo = t > 0 && n >= t;
            $('#estImprimirBox').prop('hidden', !completo);

            if (completo) {
                // O botao recebe o foco: fecha o ciclo com Enter, sem mouse.
                $('#estProdutoBox').addClass('is-completo');
                foco($('#estImprimir'));
                status($statusP, 'Todos os itens conferidos.', 'ok');
            } else {
                $('#estProdutoBox').removeClass('is-completo');
            }
        }

        function marcarItem(item) {
            var id  = item.id | 0;
            var qtd = item.quantidade | 0;
            lidos[id] = lidos[id] || 0;

            if (lidos[id] >= qtd) {
                status($statusP, 'Este item já está completo (' + qtd + ').', 'aviso');
                return;
            }
            lidos[id] += 1;

            var $linha = $('#estPedido').find('.est_item[data-item="' + id + '"]');
            $linha.find('.est_lido').text(lidos[id]);
            $linha.toggleClass('is-ok', lidos[id] >= qtd);
            $linha.addClass('is-bipado');
            setTimeout(function () { $linha.removeClass('is-bipado'); }, 500);

            status($statusP, esc(item.nome) + ' — ' + lidos[id] + '/' + qtd, 'ok');
            pintarProgresso();
        }

        function acharItem(codigo) {
            if (!atual) return null;
            var alvo = String(codigo).trim();
            var igual = function (v) { return v && String(v).toLowerCase() === alvo.toLowerCase(); };
            // sku_legado e do produto, nao da variacao — casa com todas as
            // variacoes dele que estiverem no pedido. O desempate abaixo resolve.
            var achados = (atual.itens || []).filter(function (i) {
                return (i.ean && i.ean === alvo) || igual(i.sku) || igual(i.sku_legado);
            });
            if (!achados.length) return null;
            // Havendo mais de um com o mesmo codigo, prefere o que ainda falta.
            return achados.find(function (i) { return (lidos[i.id] || 0) < (i.quantidade | 0); }) || achados[0];
        }

        /* ── sessão ────────────────────────────────────── */
        function registrar(p) {
            if (sessao.some(function (x) { return x.id === p.id; })) return;
            sessao.push({ id: p.id, codigo: p.codigo, rastreio: p.codigo_rastreio, metodo: p.metodo_envio });
            pintarSessao();
        }

        function pintarSessao() {
            $('#estTotal').text(sessao.length);
            if (!sessao.length) {
                $('#estLista').html('<tr class="est_lista_vazia"><td colspan="4">Nada escaneado nesta sessão.</td></tr>');
                return;
            }
            var h = '';
            sessao.slice().reverse().forEach(function (x, idx) {
                h += '<tr><td>' + (sessao.length - idx) + '</td>' +
                     '<td class="est_mono">' + esc(x.codigo) + '</td>' +
                     '<td class="est_mono">' + (x.rastreio ? esc(x.rastreio) : '—') + '</td>' +
                     '<td>' + (x.metodo ? esc(x.metodo) : '—') + '</td></tr>';
            });
            $('#estLista').html(h);
        }

        /* ── estado ────────────────────────────────────── */
        function limparPedido() {
            atual = null;
            lidos = {};
            $('#estPedido').prop('hidden', true).empty();
            $('#estProdutoBox').prop('hidden', true).removeClass('is-completo');
            $('#estImprimirBox').prop('hidden', true);
            $('#estVazio').prop('hidden', false);
            $produto.val('');
            status($statusP, '');
            $('#estNotaEtiqueta').text('');
        }

        function carregar(p, via) {
            atual = p;
            lidos = {};
            mostrar(p);
            registrar(p);

            $('#estProdutoBox').prop('hidden', false);
            $('#estImprimirBox').prop('hidden', true);
            $('#estImprimirDanfe').prop('disabled', !p.nfe_danfe);
            $('#estNotaEtiqueta').text(
                p.etiqueta && p.etiqueta.url_pdf
                    ? 'Saem dois trabalhos: NF simplificada e etiqueta.'
                    : 'Sem etiqueta emitida: sai só a NF simplificada.'
            );
            pintarProgresso();
            status($status, 'Encontrado por ' + (via || 'código') + '.', 'ok');
            foco($produto);            // o operador segue bipando, sem tocar em nada
        }

        /* ── busca do pedido ───────────────────────────── */
        function buscar() {
            var codigo = String($campo.val() ?? '').trim();
            if (!codigo) { foco($campo); return; }

            status($status, 'Buscando...', 'info');

            $.ajax({
                url: window.SEP_BASE + '/estacao/buscar',
                method: 'POST',
                dataType: 'json',
                data: csrf({ codigo: codigo }),
                headers: { 'X-CSRF-TOKEN': window.CSRF_TOKEN || '' }
            }).done(function (r) {
                $campo.val('');
                if (!r || !r.ok) {
                    status($status, (r && r.msg) || 'Não encontrado.', 'erro');
                    foco($campo);
                    return;
                }

                var p = r.pedido;

                if (sessao.some(function (x) { return x.id === p.id; })) {
                    status($status, 'Pedido #' + p.id + ' já foi escaneado nesta sessão.', 'aviso');
                }

                carregar(p, r.via);

                var filtro = String($('#estMetodo').val() || '');
                if (filtro && p.metodo_envio !== filtro) {
                    status($status, 'Atenção: este pedido é "' + (p.metodo_envio || 'sem método') +
                           '", diferente do filtro "' + filtro + '".', 'aviso');
                }
            }).fail(function () {
                status($status, 'Falha de rede na busca.', 'erro');
                foco($campo);
            });
        }

        /* ── impressão ─────────────────────────────────── */
        function urlNf()       { return window.SEP_BASE + '/' + atual.id + '/nf'; }
        function urlEtiqueta() { return (atual.etiqueta && atual.etiqueta.url_pdf) || ''; }

        // Imprime e libera a estacao para o proximo pedido.
        //
        // So o botao principal conclui. Os outros dois imprimem e deixam o
        // pedido na tela: quem pediu so a DANFE ainda vai precisar da etiqueta,
        // e limpar ali obrigaria a bipar o pedido de novo.
        function concluir(urls) {
            if (!atual) return;
            var id = atual.id;
            Impressao.fila(urls);      // segue em background; a fila espera sozinha
            status($status, 'Pedido #' + id + ' concluído. Próximo.', 'ok');
            limparPedido();
            foco($campo);              // pronto para o proximo, sem mouse
        }

        function imprimir() {
            if (!atual) return;
            // NF simplificada sempre; a etiqueta so existe depois de emitida.
            concluir([urlNf(), urlEtiqueta()]);
        }

        /* ── eventos ───────────────────────────────────── */
        $campo.on('keydown', function (ev) {
            if (ev.key === 'Enter') { ev.preventDefault(); buscar(); }
        });
        $('#estBuscar').on('click', buscar);

        $produto.on('keydown', function (ev) {
            if (ev.key !== 'Enter') return;
            ev.preventDefault();

            var codigo = String($produto.val() ?? '').trim();
            $produto.val('');
            if (!codigo) return;

            var item = acharItem(codigo);
            if (!item) {
                status($statusP, 'Código "' + codigo + '" não pertence a este pedido.', 'erro');
                return;
            }
            marcarItem(item);
        });

        $('#estImprimir').on('click', imprimir);

        $('#estImprimirNf').on('click', function () {
            if (!atual) return;
            Impressao.fila([urlNf()]);
            status($statusP, 'NF simplificada enviada para a impressora.', 'ok');
        });

        // A DANFE completa vem do Bling: outro dominio, e atras de uma
        // validacao por JavaScript ("Validacao de Acesso"). Nao carrega em
        // iframe nem pode ser buscada pelo servidor — abre em aba, e a
        // impressao ali e manual.
        $('#estImprimirDanfe').on('click', function () {
            if (!atual) return;
            if (!atual.nfe_danfe) {
                status($statusP, 'Este pedido ainda nao tem DANFE no Bling.', 'aviso');
                return;
            }
            window.open(atual.nfe_danfe, '_blank', 'noopener');
        });

        $('#estLimpar').on('click', function () {
            sessao = [];
            pintarSessao();
            limparPedido();
            status($status, '');
            foco($campo);
        });

        // O foco volta sozinho para o campo em uso: o operador bipa sem olhar,
        // e um clique perdido no fundo faria a próxima leitura sumir.
        $(document).on('click', function (ev) {
            if ($(ev.target).is('input, select, textarea, button, a')) return;
            foco(atual && $('#estImprimirBox').prop('hidden') === false ? $('#estImprimir')
               : atual ? $produto
               : $campo);
        });

        pintarSessao();
        foco($campo);
    })();
})(jQuery);
