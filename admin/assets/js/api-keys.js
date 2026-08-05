/**
 * Chaves de API (admin) — jQuery v4. Depende de adminDrawer, Toast, window.CSRF_TOKEN.
 */
(function ($) {
    'use strict';

    var BASE = window.LOG_API_BASE || '/admin/logistica/api-keys';
    var ESCOPOS = window.LOG_API_ESCOPOS || ['cotar', 'etiquetas', 'rastreio', 'reversa', 'divergencias'];

    function api(m, p, d) { return $.ajax({ url: BASE + p, method: m, dataType: 'json', data: d || {}, headers: { 'X-CSRF-TOKEN': window.CSRF_TOKEN || '' } }); }
    function comCsrf(o) { o = o || {}; o.csrf_token = window.CSRF_TOKEN || ''; o._token = window.CSRF_TOKEN || ''; return o; }
    function esc(s) { return $('<i>').text(s == null ? '' : String(s)).html(); }
    function attr(s) { return esc(s).replace(/"/g, '&quot;'); }

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
                '<td>' + esc(k.nome) + (k.webhook_url ? '<div class="log_muted"><i class="bi bi-broadcast"></i> webhook</div>' : '') + '</td>' +
                '<td><span class="log_mono">' + esc(k.prefixo) + '…</span></td>' +
                '<td>' + escopos + '</td>' +
                '<td>' + (k.rate_limit | 0) + '</td>' +
                '<td>' + (k.req_24h | 0) + '</td>' +
                '<td>' + sit + '</td>' +
                '<td class="log_col_acoes">' +
                    '<button type="button" class="log_btn log_btn--icon js-edit" title="Editar"><i class="bi bi-pencil"></i></button> ' +
                    (k.ativa == 1 ? '<button type="button" class="log_btn log_btn--icon js-revogar" title="Revogar"><i class="bi bi-slash-circle"></i></button>' : '') +
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
            acoes: '<button type="button" class="log_btn log_btn--primary log_btn--sm js-salvar"><i class="bi bi-check-lg"></i> ' + (editar ? 'Salvar' : 'Criar') + '</button>' });

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
            '<div class="log_copybox" style="margin-top:12px"><input class="log_input log_mono" readonly value="' + attr(chave) + '"><button type="button" class="log_btn log_btn--sm js-copy" data-txt="' + attr(chave) + '"><i class="bi bi-clipboard"></i> Copiar</button></div>' +
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

})(jQuery);
