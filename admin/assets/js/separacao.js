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
                var $linha = $conf.find('.sep_item[data-item="' + r.item_id + '"]');
                if (!$linha.length) { aviso('Item não está nesta tela.', 'error'); return; }
                marcar($linha);
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
       ═══════════════════════════════════════════════════════ */
    (function () {
        var $est = $('#sepEstacao');
        if (!$est.length) return;

        var $campo  = $('#estCodigo');
        var $status = $('#estStatus');
        var sessao  = [];            // memoria do turno, so no navegador

        function attr(s) { return esc(s).replace(/"/g, '&quot;'); }
        function foco()  { $campo.trigger('focus').trigger('select'); }

        function status(msg, tipo) {
            $status.attr('class', 'est_status is-' + (tipo || 'info')).text(msg || '');
        }

        function brl(v) {
            return 'R$ ' + (Number(v) || 0).toFixed(2).replace('.', ',');
        }

        function linhaItem(i) {
            var img = i.imagem
                ? '<img class="est_item_img" src="' + attr(i.imagem) + '" alt="">'
                : '<span class="est_item_img est_item_img--vazia"></span>';

            var meta = [];
            if (i.variacao) meta.push(esc(i.variacao));
            if (i.sku)      meta.push('SKU ' + esc(i.sku));
            if (i.ean)      meta.push('EAN ' + esc(i.ean));

            return '<div class="est_item">' + img +
                   '<div class="est_item_txt">' +
                     '<div class="est_item_nome">' + esc(i.nome) + '</div>' +
                     (meta.length ? '<div class="est_item_meta">' + meta.join(' · ') + '</div>' : '') +
                   '</div>' +
                   '<div class="est_item_qtd">' + (i.quantidade | 0) + '<small>un</small></div>' +
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

            h += '<a class="btn btn-secondary est_ir" href="' + attr(p.url_conferencia) + '">Abrir conferência</a>';

            $('#estVazio').prop('hidden', true);
            $('#estPedido').prop('hidden', false).html(h);
        }

        function registrar(p) {
            // Ja passou nesta sessao: avisa em vez de duplicar a linha. Bipar
            // duas vezes o mesmo pedido e o erro mais comum na bancada.
            var repetido = sessao.some(function (x) { return x.id === p.id; });
            if (repetido) {
                status('Pedido #' + p.id + ' já foi escaneado nesta sessão.', 'aviso');
                return;
            }
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
            // Mais recente no topo: e o que o operador acabou de fazer.
            sessao.slice().reverse().forEach(function (x, idx) {
                h += '<tr><td>' + (sessao.length - idx) + '</td>' +
                     '<td class="est_mono">' + esc(x.codigo) + '</td>' +
                     '<td class="est_mono">' + (x.rastreio ? esc(x.rastreio) : '—') + '</td>' +
                     '<td>' + (x.metodo ? esc(x.metodo) : '—') + '</td></tr>';
            });
            $('#estLista').html(h);
        }

        function buscar() {
            var codigo = String($campo.val() ?? '').trim();
            if (!codigo) { foco(); return; }

            status('Buscando...', 'info');

            $.ajax({
                url: window.SEP_BASE + '/estacao/buscar',
                method: 'POST',
                dataType: 'json',
                data: csrf({ codigo: codigo }),
                headers: { 'X-CSRF-TOKEN': window.CSRF_TOKEN || '' }
            }).done(function (r) {
                $campo.val('');
                if (!r || !r.ok) {
                    status((r && r.msg) || 'Não encontrado.', 'erro');
                    foco();
                    return;
                }

                var p = r.pedido;
                mostrar(p);

                // Filtro de metodo: pegar o pedido do transportador errado e o
                // tipo de engano que so aparece quando a saca ja foi lacrada.
                var filtro = String($('#estMetodo').val() || '');
                if (filtro && p.metodo_envio !== filtro) {
                    status('Atenção: este pedido é "' + (p.metodo_envio || 'sem método') +
                           '", diferente do filtro "' + filtro + '".', 'aviso');
                } else {
                    status('Encontrado por ' + (r.via || 'código') + '.', 'ok');
                }

                registrar(p);
                foco();
            }).fail(function () {
                status('Falha de rede na busca.', 'erro');
                foco();
            });
        }

        // O leitor digita e manda Enter sozinho.
        $campo.on('keydown', function (ev) {
            if (ev.key === 'Enter') { ev.preventDefault(); buscar(); }
        });
        $('#estBuscar').on('click', buscar);

        $('#estLimpar').on('click', function () {
            sessao = [];
            pintarSessao();
            $('#estPedido').prop('hidden', true).empty();
            $('#estVazio').prop('hidden', false);
            status('');
            foco();
        });

        // O foco tem que voltar sozinho: o operador bipa sem olhar para a tela,
        // e um clique perdido no fundo faria a proxima leitura sumir.
        $(document).on('click', function (ev) {
            if (!$(ev.target).is('input, select, textarea, button, a')) foco();
        });

        pintarSessao();
        foco();
    })();

})(jQuery);
