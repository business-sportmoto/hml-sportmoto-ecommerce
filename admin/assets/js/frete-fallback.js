/**
 * Frete — fallback (admin) — jQuery v4. Depende de adminDrawer, Toast, window.CSRF_TOKEN.
 */
(function ($) {
    'use strict';

    var BASE = window.LOG_FALL_BASE || '/admin/logistica/frete-fallback';
    var REGIOES = { N: 'Norte', NE: 'Nordeste', CO: 'Centro-Oeste', SE: 'Sudeste', S: 'Sul' };

    function api(m, p, d) { return $.ajax({ url: BASE + p, method: m, dataType: 'json', data: d || {}, headers: { 'X-CSRF-TOKEN': window.CSRF_TOKEN || '' } }); }
    function comCsrf(o) { o = o || {}; o.csrf_token = window.CSRF_TOKEN || ''; o._token = window.CSRF_TOKEN || ''; return o; }
    function esc(s) { return $('<i>').text(s == null ? '' : String(s)).html(); }
    function attr(s) { return esc(s).replace(/"/g, '&quot;'); }
    function reais(v) { return 'R$ ' + (Number(v) || 0).toFixed(2).replace('.', ','); }
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
                '<div class="log_fall_saude is-open"><i class="bi bi-exclamation-octagon-fill"></i> ' +
                'Circuito das transportadoras <strong>ABERTO</strong> — servindo estimativas até ' +
                esc(abertoAte.toLocaleTimeString('pt-BR')) + '. (' + (s.falhas || 0) + ' falhas)</div>'
            );
        } else if ((s.falhas || 0) > 0) {
            $('#logFallSaude').html('<div class="log_fall_saude is-warn"><i class="bi bi-activity"></i> ' + s.falhas + ' falha(s) recente(s) nas transportadoras. Circuito fechado.</div>');
        } else {
            $('#logFallSaude').html('<div class="log_fall_saude is-ok"><i class="bi bi-check-circle"></i> Transportadoras operando normalmente.</div>');
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
                '<button class="log_ib js-edit" title="Editar"><i class="bi bi-pencil"></i></button>' +
                '<button class="log_ib js-toggle" title="Ativar/Inativar"><i class="bi bi-power"></i></button>' +
                '<button class="log_ib log_ib--danger js-del" title="Remover"><i class="bi bi-trash"></i></button>' +
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
            '<div class="log_note"><i class="bi bi-info-circle"></i> Preencha <strong>UF</strong> OU <strong>Região</strong> para uma regra específica. Deixe ambos vazios para a regra geral.</div>' +
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
            '<div class="log_form_actions"><button type="button" class="log_btn log_btn--primary js-salvar"><i class="bi bi-check-lg"></i> Salvar</button></div>' +
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
})(jQuery);
