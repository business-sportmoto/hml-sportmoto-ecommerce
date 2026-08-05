/* =====================================================================
   Módulo de Logística — Torre de Controle (front)
   Requer jQuery v4, window.BASE_URL e (opcional) window.Toast.
   Sem fetch/async-await: usa $.ajax, conforme padrão do projeto.
   ===================================================================== */
(function ($) {
    'use strict';

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
    function esc(s)   { return $('<div>').text(s == null ? '' : String(s)).html(); }

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
               '<div class="log_kpi_ico ' + cls + '"><i class="bi ' + ico + '"></i></div>' +
               '<div class="log_kpi_val">' + val + '</div>' +
               '<div class="log_kpi_lbl">' + esc(lbl) + '</div></div>';
    }
    function cardBloqueado(lbl) {
        return '<div class="log_kpi"><div class="log_kpi_ico is-neutral"><i class="bi bi-lock"></i></div>' +
               '<div class="log_kpi_val log_muted" style="font-size:16px">restrito</div>' +
               '<div class="log_kpi_lbl">' + esc(lbl) + '</div></div>';
    }
    function renderKpis(k) {
        var h = '';
        h += cardKpi('bi-box-seam', '', nInt(k.total_envios), 'Total de envios', true);
        h += cardKpi('bi-check2-circle', 'is-ok', nInt(k.entregues), 'Entregues');
        h += cardKpi('bi-truck', 'is-info', nInt(k.em_transito), 'Em trânsito');
        h += cardKpi('bi-clock-history', 'is-ok', nDec(k.no_prazo_pct) + '<small>%</small>', 'No prazo');
        h += cardKpi('bi-exclamation-triangle', 'is-danger', nInt(k.atrasados), 'Atrasados');
        h += cardKpi('bi-flag', 'is-warn', nInt(k.ocorrencias), 'Com ocorrências');
        h += cardKpi('bi-printer', 'is-neutral', nInt(k.etiquetas_aguardando), 'Etiquetas aguardando postagem');
        h += cardKpi('bi-arrow-return-left', '', nInt(k.reversas_abertas), 'Solicitações de reversa');
        h += cardKpi('bi-calendar-check', 'is-neutral', nDec(k.prazo_medio) + '<small>d</small>', 'Prazo médio de entrega');
        h += cardKpi('bi-wifi-off', 'is-warn', nInt(k.falhas_integracao), 'Falhas de integração');

        if (k.gasto_fretes === null || k.gasto_fretes === undefined) {
            h += cardBloqueado('Gasto com fretes');
            h += cardBloqueado('Divergências acumuladas');
        } else {
            h += cardKpi('bi-cash-stack', 'is-info', nBRL(k.gasto_fretes), 'Gasto com fretes');
            h += cardKpi('bi-scale', 'is-danger', nBRL(k.divergencias_valor), 'Divergências acumuladas');
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
                '<div class="log_state_ico"><i class="bi bi-check2-circle"></i></div>' +
                '<div class="log_state_title">Tudo sob controle</div>' +
                '<div class="log_state_desc">Nenhum alerta operacional no momento.</div></div>'
            );
            return;
        }
        var h = '', i;
        for (i = 0; i < alertas.length; i++) {
            var a = alertas[i];
            h += '<div class="log_alert is-' + esc(a.nivel || 'info') + '">' +
                 '<div class="log_alert_ico"><i class="bi ' + esc(a.icone || 'bi-info-circle') + '"></i></div>' +
                 '<div class="log_alert_body"><div class="log_alert_title">' + esc(a.titulo) + '</div>' +
                 '<div class="log_alert_desc">' + esc(a.descricao) + '</div></div>' +
                 '<a href="' + esc(a.link || '#') + '" class="log_btn log_btn--sm"><i class="bi bi-arrow-right"></i> Ver</a></div>';
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

})(jQuery);
