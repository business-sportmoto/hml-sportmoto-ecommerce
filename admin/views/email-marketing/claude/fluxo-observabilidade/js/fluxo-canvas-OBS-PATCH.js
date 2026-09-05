/* ═══════════════════════════════════════════════════════════════════════════
 *  OBSERVABILIDADE — patch de public/js/fluxo-canvas.js  (3 blocos)
 * ═══════════════════════════════════════════════════════════════════════════
 *  Números de uso em cada balão do canvas + seção "Atividade" no painel
 *  lateral do nó (portas, tempo médio, últimos erros).
 * ═══════════════════════════════════════════════════════════════════════════ */


/* ── BLOCO 1: carregar e aplicar os stats.
      Cole estas funções dentro da IIFE da UI (ex.: logo após fecharPainel). ── */

  var STATS = {};       // no_chave → {total, portas, ms_medio, erros}
  var STATS_VERSAO = 0;

  function carregarStats() {
    $.get(window.BASE_URL + '/admin/fluxos/' + window.FX_FLUXO_ID + '/stats', function (r) {
      if (!r || !r.ok) return;
      STATS = r.nos || {};
      STATS_VERSAO = r.versao || 0;
      aplicarStats();
    }, 'json');
  }

  /** Injeta o badge de contagem em cada nó do canvas. */
  function aplicarStats() {
    if (!ed) return;
    var data = ((ed.export() || {}).drawflow || {}).Home.data || {};
    Object.keys(data).forEach(function (id) {
      var chave = (data[id].data || {}).chave;
      var s = chave && STATS[chave];
      var $no = $('#node-' + id + ' .drawflow_content_node');
      $no.find('.fx-no-stats').remove();
      if (!s || !s.total) return;

      var $b = $('<div class="fx-no-stats">');
      $b.append($('<span class="fx-stat-total">')
        .attr('title', 'Execuções deste nó (v' + STATS_VERSAO + ')')
        .append('<i class="bi bi-activity"></i>')
        .append($('<span>').text(formatarN(s.total))));

      // Nós com 2+ portas ganham o racha (ex.: true 62% · false 38%)
      var portas = Object.keys(s.portas || {}).filter(function (p) { return p.indexOf('__') !== 0; });
      if (portas.length > 1) {
        var frag = portas.map(function (p) {
          return p + ' ' + Math.round((s.portas[p] / s.total) * 100) + '%';
        }).join(' · ');
        $b.append($('<span class="fx-stat-portas">').text(frag));
      }
      if ((s.portas || {})['__erro']) {
        $b.append($('<span class="fx-stat-erro">')
          .attr('title', 'Erros neste nó')
          .append('<i class="bi bi-exclamation-triangle"></i>')
          .append($('<span>').text(s.portas['__erro'])));
      }
      $no.append($b);
    });
  }

  function formatarN(n) {
    if (n >= 10000) return (n / 1000).toFixed(0) + 'k';
    if (n >= 1000)  return (n / 1000).toFixed(1).replace('.', ',') + 'k';
    return String(n);
  }


/* ── BLOCO 2: chamar ao abrir.
      No $(function () { ... }) de inicialização, DEPOIS de montarPaleta();
      ligarEventos();  ADICIONE: ── */

    carregarStats();


/* ── BLOCO 3: seção "Atividade" no painel do nó.
      Dentro de abrirPainel(id), logo APÓS o bloco
      `if (!(meta.campos || []).length) { ... }`  ADICIONE: ── */

    // ── Atividade do nó (observabilidade) ──
    var st = STATS[no.data.chave];
    if (st && st.total) {
      var $atv = $('<div class="fx-painel-atv">');
      $atv.append($('<div class="fx-atv-titulo">').text('Atividade · v' + STATS_VERSAO));
      $atv.append($('<div class="fx-atv-linha">')
        .append($('<span>').text('Execuções'))
        .append($('<b>').text(st.total.toLocaleString('pt-BR'))));
      Object.keys(st.portas || {}).forEach(function (p) {
        if (p.indexOf('__') === 0) return;
        $atv.append($('<div class="fx-atv-linha">')
          .append($('<span>').text('porta ' + p))
          .append($('<b>').text(st.portas[p].toLocaleString('pt-BR') +
            ' (' + Math.round(st.portas[p] / st.total * 100) + '%)')));
      });
      if (st.ms_medio > 0) {
        $atv.append($('<div class="fx-atv-linha">')
          .append($('<span>').text('tempo médio'))
          .append($('<b>').text(st.ms_medio + ' ms')));
      }
      (st.erros || []).forEach(function (e) {
        $atv.append($('<div class="fx-atv-erro">')
          .append($('<i class="bi bi-exclamation-triangle">'))
          .append($('<span>').text(e.detalhe || 'erro')));
      });
      $f.append($atv);
    }


/* ═══════════════════════════════════════════════════════════════════════════
 *  CSS — adicione ao public/css/fluxo-canvas.css:
 * ═══════════════════════════════════════════════════════════════════════════

.fx-no-stats {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 5px 12px 7px 15px;
  border-top: 0.5px dashed var(--em-border, #e5e7eb);
  font-size: 10px;
  font-variant-numeric: tabular-nums;
  color: var(--em-text-muted, #71717a);
  flex-wrap: wrap;
}
.fx-stat-total { display: inline-flex; align-items: center; gap: 3px; font-weight: 600; }
.fx-stat-total i { font-size: 10px; color: #0a66c2; }
.fx-stat-portas { white-space: nowrap; }
.fx-stat-erro { display: inline-flex; align-items: center; gap: 3px; color: #b45309; font-weight: 600; }
.fx-stat-erro i { font-size: 10px; }

.fx-painel-atv {
  margin-top: 16px;
  padding: 12px 13px;
  border: 0.5px solid var(--em-border, #e5e7eb);
  border-radius: 12px;
  background: var(--em-bg-card, #fff);
}
.fx-atv-titulo {
  font-size: 10.5px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .06em;
  color: var(--em-text-muted, #a1a1aa);
  margin-bottom: 8px;
}
.fx-atv-linha {
  display: flex;
  justify-content: space-between;
  font-size: 12px;
  padding: 3px 0;
  font-variant-numeric: tabular-nums;
}
.fx-atv-erro {
  display: flex;
  gap: 6px;
  align-items: flex-start;
  font-size: 11px;
  color: #b45309;
  margin-top: 7px;
  line-height: 1.4;
}
.fx-atv-erro i { flex-shrink: 0; margin-top: 1px; }

@media (prefers-color-scheme: dark) {
  .fx-no-stats { border-top-color: #3a3a3d; }
  .fx-painel-atv { background: #232326; border-color: #3a3a3d; }
}
 * ═══════════════════════════════════════════════════════════════════════════ */
