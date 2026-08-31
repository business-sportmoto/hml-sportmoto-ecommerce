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

            var codigo = $.trim($campo.val());
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

})(jQuery);
