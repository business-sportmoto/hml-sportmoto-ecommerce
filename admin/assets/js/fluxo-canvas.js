/**
 * public/js/fluxo-canvas.js
 *
 * Canvas visual do Motor de Automação v2 (Fase 2), sobre Drawflow 0.0.60.
 *
 * Estrutura:
 *   1. FluxoConv  — conversor PURO Drawflow ⇄ formato do backend {nos, conexoes}
 *                   (testável em node; module.exports quando fora do browser)
 *   2. UI         — paleta, canvas, painel de config, salvar/publicar (jQuery)
 *
 * O backend da Fase 1 não muda: o canvas gera exatamente o mesmo JSON
 * que o editor textual gerava.
 */

/* ═══════════════════════════════════════════════════════════════════════════
   1. METADADOS DOS NÓS (apresentação + formulários)
   ═══════════════════════════════════════════════════════════════════════════ */
var FLUXO_UI = {
  categorias: {
    trigger:  { label: 'Triggers',  cor: '#16a34a' },
    condicao: { label: 'Condições', cor: '#f59e0b' },
    acao:     { label: 'Ações',     cor: '#0a66c2' },
    fluxo:    { label: 'Fluxo',     cor: '#71717a' }
  },
  nos: {
    trigger_evento: { cat: 'trigger', label: 'Evento do site', icone: 'bi-lightning-charge',
      campos: [
        { k: 'evento', label: 'Evento', tipo: 'select', ops: ['produto_visto','categoria_vista','catalogo_moto_visto','busca','banner_click','pagina_vista','pedido_criado', 'dica_cuidado_clicada'] },
        { k: 'entidade_tipo', label: 'Entidade (opcional)', tipo: 'select', ops: ['', 'produto', 'categoria', 'banner'] },
        { k: 'min_ocorrencias', label: 'Mín. ocorrências', tipo: 'number', def: 1 },
        { k: 'janela_dias', label: 'Janela (dias)', tipo: 'number', def: 7 },
        { k: 'apenas_logados', label: 'Apenas logados', tipo: 'checkbox', def: true }
      ] },
    trigger_manual: { cat: 'trigger', label: 'Disparo manual', icone: 'bi-hand-index', campos: [] },

    esperar: { cat: 'fluxo', label: 'Esperar', icone: 'bi-hourglass-split',
      campos: [
        { k: 'minutos', label: 'Minutos', tipo: 'number', def: 0 },
        { k: 'horas',   label: 'Horas',   tipo: 'number', def: 0 },
        { k: 'dias',    label: 'Dias',    tipo: 'number', def: 0 }
      ] },
    split_ab: { cat: 'fluxo', label: 'Split A/B', icone: 'bi-signpost-split',
      campos: [
        { k: 'peso_a', label: 'Peso A (%)', tipo: 'number', def: 50 },
        { k: 'peso_b', label: 'Peso B (%)', tipo: 'number', def: 50 }
      ] },
    encerrar: { cat: 'fluxo', label: 'Encerrar', icone: 'bi-stop-circle', campos: [] },

    cond_evento_ocorreu: { cat: 'condicao', label: 'Evento ocorreu?', icone: 'bi-activity',
      campos: [
        { k: 'evento', label: 'Evento', tipo: 'select', ops: ['produto_visto','categoria_vista','busca','pagina_vista','pedido_criado'] },
        { k: 'janela_dias', label: 'Janela (dias)', tipo: 'number', def: 7 },
        { k: 'min', label: 'Mín. vezes', tipo: 'number', def: 1 },
        { k: 'mesma_entidade', label: 'Mesmo produto do contexto', tipo: 'checkbox', def: false }
      ] },
    cond_total_gasto: { cat: 'condicao', label: 'Total gasto', icone: 'bi-cash-stack',
      campos: [
        { k: 'operador', label: 'Operador', tipo: 'select', ops: ['>=','>','<=','<','='] },
        { k: 'valor', label: 'Valor (R$)', tipo: 'number', def: 500 },
        { k: 'janela_dias', label: 'Janela (dias, vazio = sempre)', tipo: 'number', def: '' }
      ] },
    cond_tem_tag: { cat: 'condicao', label: 'Tem tag?', icone: 'bi-tag',
      campos: [ { k: 'tag', label: 'Tag', tipo: 'text', def: '' } ] },
    cond_aceita_marketing: { cat: 'condicao', label: 'Aceita marketing?', icone: 'bi-envelope-check',
      campos: [ { k: 'canal', label: 'Canal', tipo: 'select', ops: ['email','whatsapp','sms'] } ] },
    cond_tem_moto: { cat: 'condicao', label: 'Tem moto?', icone: 'bi-bicycle', campos: [] },

    acao_email: { cat: 'acao', label: 'Enviar email', icone: 'bi-envelope',
      campos: [
        { k: 'template_id', label: 'Template', tipo: 'select_template' },
        { k: 'quiet_hours', label: 'Respeitar horário (8h–21h)', tipo: 'checkbox', def: false }
      ] },
    acao_notificacao: { cat: 'acao', label: 'Notificação in-app', icone: 'bi-bell',
      campos: [
        { k: 'categoria', label: 'Categoria', tipo: 'select', ops: ['promocao','pedido','sistema','estoque','financeiro','conta'] },
        { k: 'titulo', label: 'Título (aceita {{vars}})', tipo: 'text', def: '' },
        { k: 'mensagem', label: 'Mensagem', tipo: 'textarea', def: '' },
        { k: 'url', label: 'Link (opcional)', tipo: 'text', def: '' }
      ] },
    acao_whatsapp: { cat: 'acao', label: 'WhatsApp (HSM)', icone: 'bi-whatsapp',
      campos: [
        { k: 'template', label: 'Nome do template HSM', tipo: 'text', def: '' },
        { k: 'body_params', label: 'Params do body (1 por linha, {{vars}})', tipo: 'textarea_lista' },
        { k: 'header_param', label: 'Param do header (opcional)', tipo: 'text', def: '' },
        { k: 'botao_url_param', label: 'Sufixo do botão URL (opcional)', tipo: 'text', def: '' },
        { k: 'quiet_hours', label: 'Respeitar horário (8h–21h)', tipo: 'checkbox', def: true }
      ] },
    acao_tag: { cat: 'acao', label: 'Adicionar/remover tag', icone: 'bi-tags',
      campos: [
        { k: 'acao', label: 'Ação', tipo: 'select', ops: ['adicionar','remover'] },
        { k: 'tag', label: 'Tag', tipo: 'text', def: '' }
      ] },
    esperar_evento: { cat: 'fluxo', label: 'Esperar evento', icone: 'bi-hourglass-bottom',
      campos: [
        { k: 'evento', label: 'Evento aguardado', tipo: 'select',
          ops: ['produto_visto','categoria_vista','busca','banner_click','pagina_vista','pedido_criado','email_aberto'] },
        { k: 'mesma_entidade', label: 'Mesmo produto do contexto', tipo: 'checkbox', def: false },
        { k: 'timeout_dias',    label: 'Timeout — dias',    tipo: 'number', def: 2 },
        { k: 'timeout_horas',   label: 'Timeout — horas',   tipo: 'number', def: 0 },
        { k: 'timeout_minutos', label: 'Timeout — minutos', tipo: 'number', def: 0 }
      ] },

    acao_webhook: { cat: 'acao', label: 'Webhook (POST)', icone: 'bi-hdd-network',
      campos: [
        { k: 'url', label: 'URL de destino', tipo: 'text', def: '' },
        { k: 'hmac_secret', label: 'Segredo HMAC (opcional)', tipo: 'text', def: '' },
        { k: 'parar_se_falhar', label: 'Parar a jornada se falhar', tipo: 'checkbox', def: false }
      ] },
    acao_cupom: { cat: 'acao', label: 'Gerar cupom', icone: 'bi-ticket-perforated',
      campos: [
        { k: 'pct',          label: 'Desconto (%)',            tipo: 'number', def: 10 },
        { k: 'dias_validade',label: 'Validade (dias)',         tipo: 'number', def: 15 },
        { k: 'prefixo',      label: 'Prefixo do código',       tipo: 'text',   def: 'VOLTA' },
        { k: 'nome',         label: 'Nome do cupom (pro cliente)', tipo: 'text', def: 'Cupom exclusivo' },
        { k: 'valor_minimo', label: 'Pedido mínimo (R$, 0 = sem)', tipo: 'number', def: 0 }
      ] },
    cond_veio_de_vendedor: { cat: 'condicao', label: 'Veio de vendedor?', icone: 'bi-person-badge',
      campos: [
        { k: 'escopo', label: 'Onde procurar', tipo: 'select',
          ops: ['auto', 'contexto', 'cliente_ultimo', 'cliente_primeiro'] },
        { k: 'codigo', label: 'Código do vendedor (vazio = qualquer)', tipo: 'text', def: '' }
      ] },

    acao_notificar_vendedor: { cat: 'acao', label: 'Avisar vendedor', icone: 'bi-megaphone',
      campos: [
        { k: 'canal',     label: 'Canal', tipo: 'select', ops: ['auto', 'notificacao', 'email'] },
        { k: 'categoria', label: 'Categoria', tipo: 'select',
          ops: ['sistema', 'pedido', 'promocao', 'financeiro'] },
        { k: 'titulo',    label: 'Título (aceita {{vars}})', tipo: 'text', def: '' },
        { k: 'mensagem',  label: 'Mensagem', tipo: 'textarea', def: '' },
        { k: 'url',       label: 'Link (opcional)', tipo: 'text', def: '' }
      ] },
  },

  /** Resumo curto exibido dentro do nó no canvas. */
  resumo: function (tipo, cfg) {
    cfg = cfg || {};
    switch (tipo) {
      case 'trigger_evento':   return (cfg.evento || '—') + (cfg.min_ocorrencias > 1 ? ' ×' + cfg.min_ocorrencias : '');
      case 'esperar': {
        var p = [];
        if (cfg.dias)    p.push(cfg.dias + 'd');
        if (cfg.horas)   p.push(cfg.horas + 'h');
        if (cfg.minutos) p.push(cfg.minutos + 'min');
        return p.join(' ') || 'imediato';
      }
      case 'esperar_evento': {
        var t = [];
        if (cfg.timeout_dias)    t.push(cfg.timeout_dias + 'd');
        if (cfg.timeout_horas)   t.push(cfg.timeout_horas + 'h');
        if (cfg.timeout_minutos) t.push(cfg.timeout_minutos + 'min');
        return (cfg.evento || '—') + ' · ≤' + (t.join(' ') || '24h');
      }
      case 'acao_cupom':
        return (cfg.pct || 10) + '% · ' + (cfg.dias_validade || 15) + 'd'
             + (cfg.prefixo ? ' · ' + cfg.prefixo : '');
      case 'acao_webhook':
        return cfg.url ? cfg.url.replace(/^https?:\/\//, '').substring(0, 24) : 'sem URL';
      case 'split_ab':         return (cfg.pesos ? cfg.pesos.join('/') : '50/50');
      case 'cond_evento_ocorreu': return (cfg.evento || '—') + ' em ' + (cfg.janela_dias || 7) + 'd';
      case 'cond_total_gasto': return (cfg.operador || '>=') + ' R$ ' + (cfg.valor || 0);
      case 'cond_tem_tag':     return cfg.tag ? '"' + cfg.tag + '"' : '—';
      case 'cond_aceita_marketing': return cfg.canal || 'email';
      case 'acao_email':       return cfg.template_id ? 'template #' + cfg.template_id : 'sem template';
      case 'acao_notificacao': return cfg.titulo ? cfg.titulo.substring(0, 26) : '—';
      case 'acao_whatsapp':    return cfg.template || '—';
      case 'acao_tag':         return (cfg.acao || 'adicionar') + ' "' + (cfg.tag || '') + '"';
      case 'cond_veio_de_vendedor':
        return cfg.codigo ? '= ' + cfg.codigo : 'qualquer vendedor';

      case 'acao_notificar_vendedor':
        return (cfg.canal || 'auto') + ' · ' +
               (cfg.titulo ? cfg.titulo.substring(0, 22) : 'sem título');
      default: return '';
    }
  }
};

/* ═══════════════════════════════════════════════════════════════════════════
   2. FluxoConv — conversor puro Drawflow ⇄ backend
   ═══════════════════════════════════════════════════════════════════════════ */
var FluxoConv = {

  /**
   * Drawflow export → grafo do backend.
   * @param  {object} dfExport  resultado de editor.export()
   * @param  {object} catalogo  tipo → {portas:[], trigger:bool}
   * @return {object} {grafo:{nos,conexoes}, avisos:[]}
   */
  paraGrafo: function (dfExport, catalogo) {
    var data = (((dfExport || {}).drawflow || {}).Home || {}).data || {};
    var nos = [], conexoes = [], avisos = [];
    var chavePorId = {};

    // 1ª passada: nós (chave estável vem do data; fallback n{id})
    Object.keys(data).forEach(function (id) {
      var n = data[id];
      var tipo  = (n.data && n.data.tipo) || n.name;
      var chave = (n.data && n.data.chave) || ('n' + id);
      chavePorId[String(id)] = chave;
      nos.push({
        chave:  chave,
        tipo:   tipo,
        config: (n.data && n.data.config) || {},
        pos:    [Math.round(n.pos_x || 0), Math.round(n.pos_y || 0)]
      });
    });

    // 2ª passada: conexões (output_N → porta pelo índice das portas do tipo)
    Object.keys(data).forEach(function (id) {
      var n = data[id];
      var tipo   = (n.data && n.data.tipo) || n.name;
      var portas = (catalogo[tipo] && catalogo[tipo].portas) || ['saida'];
      var outs   = n.outputs || {};

      Object.keys(outs).forEach(function (outKey) {
        var idx   = parseInt(outKey.replace('output_', ''), 10) - 1;
        var porta = portas[idx];
        if (porta === undefined) return; // output além das portas declaradas
        var conns = (outs[outKey].connections || []);
        if (conns.length > 1) {
          avisos.push('nó "' + chavePorId[String(id)] + '": porta "' + porta +
                      '" tinha ' + conns.length + ' conexões — mantida só a primeira');
        }
        if (conns.length >= 1) {
          conexoes.push({
            de:    chavePorId[String(id)],
            porta: porta,
            para:  chavePorId[String(conns[0].node)]
          });
        }
      });
    });

    return { grafo: { nos: nos, conexoes: conexoes }, avisos: avisos };
  },

  /**
   * Grafo do backend → formato de import do Drawflow.
   * @param  {object}   grafo      {nos, conexoes}
   * @param  {object}   catalogo   tipo → {portas, trigger}
   * @param  {function} renderHtml (tipo, config) → html interno do nó
   * @return {object} formato aceito por editor.import()
   */
  paraDrawflow: function (grafo, catalogo, renderHtml) {
    var data = {};
    var idPorChave = {};
    var nextId = 1;

    (grafo.nos || []).forEach(function (no) {
      var meta   = catalogo[no.tipo] || { portas: ['saida'], trigger: false };
      var nIn    = meta.trigger ? 0 : 1;
      var nOut   = (meta.portas || []).length;
      var id     = nextId++;
      idPorChave[no.chave] = id;

      var inputs = {};
      if (nIn > 0) inputs.input_1 = { connections: [] };
      var outputs = {};
      for (var i = 1; i <= nOut; i++) outputs['output_' + i] = { connections: [] };

      data[String(id)] = {
        id: id,
        name: no.tipo,
        data: { tipo: no.tipo, chave: no.chave, config: no.config || {} },
        class: 'fx-no fx-' + ((FLUXO_UI.nos[no.tipo] || {}).cat || 'fluxo'),
        html: renderHtml ? renderHtml(no.tipo, no.config || {}) : '',
        typenode: false,
        inputs: inputs,
        outputs: outputs,
        pos_x: (no.pos && no.pos[0]) || 0,
        pos_y: (no.pos && no.pos[1]) || 0
      };
    });

    (grafo.conexoes || []).forEach(function (c) {
      var origemId  = idPorChave[c.de];
      var destinoId = idPorChave[c.para];
      if (!origemId || !destinoId) return;
      var origem = data[String(origemId)];
      var tipo   = origem.data.tipo;
      var portas = (catalogo[tipo] && catalogo[tipo].portas) || ['saida'];
      var idx    = portas.indexOf(c.porta || 'saida');
      if (idx < 0) return;
      var outKey = 'output_' + (idx + 1);
      origem.outputs[outKey].connections.push({ node: String(destinoId), output: 'input_1' });
      var destino = data[String(destinoId)];
      if (destino.inputs.input_1) {
        destino.inputs.input_1.connections.push({ node: String(origemId), input: outKey });
      }
    });

    return { drawflow: { Home: { data: data } } };
  }
};

// Exporta para teste em node
if (typeof module !== 'undefined' && module.exports) {
  module.exports = { FluxoConv: FluxoConv, FLUXO_UI: FLUXO_UI };
}

/* ═══════════════════════════════════════════════════════════════════════════
   3. UI DO CANVAS (só no browser)
   ═══════════════════════════════════════════════════════════════════════════ */
if (typeof window !== 'undefined' && window.jQuery && window.Drawflow) {
(function ($) {
  'use strict';

  // Injetados pela view: FLUXO_ID, GRAFO_INICIAL, CATALOGO, EMAIL_TEMPLATES,
  //                      BASE_URL, CSRF_TOKEN, FLUXO_CONFIG_JSON
  var CAT = window.FX_CATALOGO || {};
  var ed  = null;
  var noSelecionado = null; // drawflow node id

  function esc(s) { return $('<span>').text(s == null ? '' : String(s)).html(); }

  /** HTML interno do nó no canvas. */
  function htmlNo(tipo, cfg) {
    var m = FLUXO_UI.nos[tipo] || { label: tipo, icone: 'bi-question', cat: 'fluxo' };
    var cor = FLUXO_UI.categorias[m.cat].cor;
    return '' +
      '<div class="fx-no-head" style="border-left:3px solid ' + cor + '">' +
        '<i class="bi ' + m.icone + '" style="color:' + cor + '"></i>' +
        '<span class="fx-no-titulo">' + esc(m.label) + '</span>' +
      '</div>' +
      '<div class="fx-no-resumo df-resumo">' + esc(FLUXO_UI.resumo(tipo, cfg)) + '</div>';
  }

  /** Normaliza config do painel (split usa peso_a/peso_b na UI, pesos[] no backend). */
  function configParaBackend(tipo, cfg) {
    if (tipo === 'split_ab') {
      return { pesos: [parseInt(cfg.peso_a || 50, 10), parseInt(cfg.peso_b || 50, 10)] };
    }
    if (tipo === 'esperar_evento') {
         return {
          evento: cfg.evento,
           mesma_entidade: !!cfg.mesma_entidade,
           timeout: {
             dias:    parseInt(cfg.timeout_dias    || 0, 10),
             horas:   parseInt(cfg.timeout_horas   || 0, 10),
             minutos: parseInt(cfg.timeout_minutos || 0, 10)
           }
         };
       }
    return cfg;
  }
  function configParaUi(tipo, cfg) {
    if (tipo === 'split_ab' && cfg && cfg.pesos) {
      return { peso_a: cfg.pesos[0], peso_b: cfg.pesos[1] };
    }
    if (tipo === 'esperar_evento' && cfg && cfg.timeout) {
         return {
           evento: cfg.evento,
           mesma_entidade: cfg.mesma_entidade,
           timeout_dias:    cfg.timeout.dias,
           timeout_horas:   cfg.timeout.horas,
           timeout_minutos: cfg.timeout.minutos
         };
       }
    return cfg || {};
  }

  // ── Inicialização ──────────────────────────────────────────────────────────
  $(function () {
    var container = document.getElementById('fx-canvas');
    ed = new Drawflow(container);
    ed.reroute = true;
    ed.start();

    // Carrega o rascunho existente
    var grafo = window.FX_GRAFO_INICIAL || { nos: [], conexoes: [] };
    if (grafo.nos && grafo.nos.length) {
      ed.import(FluxoConv.paraDrawflow(grafo, CAT, htmlNo));
    }

    montarPaleta();
    ligarEventos();
    carregarStats();
  });

  // ── Paleta (drag & drop) ───────────────────────────────────────────────────
  function montarPaleta() {
    var $p = $('#fx-paleta');
    Object.keys(FLUXO_UI.categorias).forEach(function (cat) {
      var grupo = FLUXO_UI.categorias[cat];
      var $sec = $('<div class="fx-pal-grupo">')
        .append($('<div class="fx-pal-titulo">').text(grupo.label));
      Object.keys(FLUXO_UI.nos).forEach(function (tipo) {
        var m = FLUXO_UI.nos[tipo];
        if (m.cat !== cat || !CAT[tipo]) return;
        $sec.append(
          $('<div class="fx-pal-item" draggable="true">')
            .attr('data-tipo', tipo)
            .append($('<i>').addClass('bi ' + m.icone).css('color', grupo.cor))
            .append($('<span>').text(m.label))
        );
      });
      $p.append($sec);
    });

    // HTML5 drag → drop no canvas (fórmula de posição do exemplo oficial)
    document.querySelectorAll('.fx-pal-item').forEach(function (el) {
      el.addEventListener('dragstart', function (ev) {
        ev.dataTransfer.setData('tipo', el.getAttribute('data-tipo'));
      });
    });
    var canvasEl = document.getElementById('fx-canvas');
    canvasEl.addEventListener('dragover', function (ev) { ev.preventDefault(); });
    canvasEl.addEventListener('drop', function (ev) {
      ev.preventDefault();
      var tipo = ev.dataTransfer.getData('tipo');
      if (!tipo || !CAT[tipo]) return;
      var pc = ed.precanvas;
      var zoom = pc.clientWidth / (pc.clientWidth * ed.zoom);
      var x = ev.clientX * zoom - pc.getBoundingClientRect().x * zoom;
      var y = ev.clientY * zoom - pc.getBoundingClientRect().y * zoom;
      adicionarNo(tipo, x, y);
    });
  }

  function adicionarNo(tipo, x, y) {
    var meta  = CAT[tipo];
    var nIn   = meta.trigger ? 0 : 1;
    var nOut  = (meta.portas || []).length;
    var cfg   = {};
    (FLUXO_UI.nos[tipo].campos || []).forEach(function (c) {
      if (c.def !== undefined && c.def !== '') cfg[c.k] = c.def;
    });
    var chave = tipo.replace(/[^a-z]/g, '').substring(0, 4) + '_' + Date.now().toString(36);
    ed.addNode(tipo, nIn, nOut, x, y,
      'fx-no fx-' + FLUXO_UI.nos[tipo].cat,
      { tipo: tipo, chave: chave, config: configParaBackend(tipo, cfg) },
      htmlNo(tipo, cfg));
  }

  // ── Eventos do editor ──────────────────────────────────────────────────────
  function ligarEventos() {
    ed.on('nodeSelected', function (id) { noSelecionado = id; abrirPainel(id); });
    ed.on('nodeUnselected', function () { noSelecionado = null; fecharPainel(); });
    ed.on('nodeRemoved', function () { noSelecionado = null; fecharPainel(); });

    // 1 porta → 1 destino: nova conexão no mesmo output remove a antiga
    ed.on('connectionCreated', function (info) {
      var no = ed.getNodeFromId(info.output_id);
      var conns = no.outputs[info.output_class].connections;
      if (conns.length > 1) {
        var antiga = conns[0]; // a recém-criada é a última
        ed.removeSingleConnection(info.output_id, antiga.node, info.output_class, antiga.output);
      }
    });

    $('#fx-zoom-in').on('click',  function () { ed.zoom_in(); });
    $('#fx-zoom-out').on('click', function () { ed.zoom_out(); });
    $('#fx-zoom-reset').on('click', function () { ed.zoom_reset(); });

    $('#fx-salvar').on('click', salvar);
    $('#fx-publicar').on('click', publicar);
    $('#fx-cfg-toggle').on('click', function () { $('#fx-cfg-box').slideToggle(150); });
    $('#fx-del-no').on('click', function () {
      if (noSelecionado) ed.removeNodeId('node-' + noSelecionado);
    });
  }

  // ── Painel de configuração ─────────────────────────────────────────────────
  function abrirPainel(id) {
    var no   = ed.getNodeFromId(id);
    var tipo = no.data.tipo;
    var meta = FLUXO_UI.nos[tipo] || { label: tipo, campos: [] };
    var cfg  = configParaUi(tipo, no.data.config);

    $('#fx-painel-titulo').text(meta.label);
    $('#fx-painel-chave').text(no.data.chave);
    var $f = $('#fx-painel-campos').empty();

    (meta.campos || []).forEach(function (c) {
      var $g = $('<div class="fx-campo">');
      var val = cfg[c.k] !== undefined ? cfg[c.k] : (c.def !== undefined ? c.def : '');

      if (c.tipo === 'checkbox') {
        $g.append($('<label class="fx-check">')
          .append($('<input type="checkbox">').attr('data-k', c.k).prop('checked', !!val))
          .append($('<span>').text(c.label)));
      } else {
        $g.append($('<label class="fx-label">').text(c.label));
        if (c.tipo === 'select') {
          var $s = $('<select class="fx-input">').attr('data-k', c.k);
          (c.ops || []).forEach(function (o) {
            $s.append($('<option>').val(o).text(o === '' ? '(nenhuma)' : o));
          });
          $s.val(String(val));
          $g.append($s);
        } else if (c.tipo === 'select_template') {
          var $st = $('<select class="fx-input">').attr('data-k', c.k);
          $st.append($('<option value="">').text('— escolha —'));
          (window.FX_EMAIL_TEMPLATES || []).forEach(function (t) {
            $st.append($('<option>').val(t.id).text('#' + t.id + ' — ' + t.nome));
          });
          $st.val(String(val));
          $g.append($st);
        } else if (c.tipo === 'textarea' || c.tipo === 'textarea_lista') {
          var v = c.tipo === 'textarea_lista' && Array.isArray(val) ? val.join('\n') : val;
          $g.append($('<textarea class="fx-input" rows="3">').attr('data-k', c.k)
            .attr('data-lista', c.tipo === 'textarea_lista' ? '1' : '')
            .val(v));
        } else {
          $g.append($('<input class="fx-input">')
            .attr('type', c.tipo === 'number' ? 'number' : 'text')
            .attr('data-k', c.k).val(val));
        }
      }
      $f.append($g);
    });

    if (!(meta.campos || []).length) {
      $f.append($('<p class="fx-sem-cfg">').text('Este nó não tem configurações.'));
    }
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
    
    $('#fx-painel').addClass('aberto');

    // Persistência ao editar
    $f.off('change input').on('change input', '[data-k]', function () {
      var novo = {};
      $f.find('[data-k]').each(function () {
        var $el = $(this), k = $el.data('k'), v;
        if ($el.attr('type') === 'checkbox') v = $el.prop('checked');
        else if ($el.data('lista')) {
          v = $el.val().split('\n').map(function (s) { return s.trim(); })
                       .filter(function (s) { return s !== ''; });
        }
        else if ($el.attr('type') === 'number') {
          v = $el.val() === '' ? '' : parseFloat($el.val());
        }
        else v = $el.val();
        if (v !== '' && v !== undefined) novo[k] = v;
      });
      var backendCfg = configParaBackend(tipo, novo);
      ed.updateNodeDataFromId(id, { tipo: tipo, chave: no.data.chave, config: backendCfg });
      // Atualiza o resumo dentro do nó
      $('#node-' + id + ' .df-resumo').text(FLUXO_UI.resumo(tipo, backendCfg));
    });
  }

  function fecharPainel() { $('#fx-painel').removeClass('aberto'); }

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

  // ── Salvar / Publicar ──────────────────────────────────────────────────────
  function exportarGrafo() {
    var r = FluxoConv.paraGrafo(ed.export(), CAT);
    if (r.avisos.length) {
      mostrarMsg('Avisos:\n• ' + r.avisos.join('\n• '), 'aviso');
    }
    return r.grafo;
  }

  function salvar(cb) {
    var grafo = exportarGrafo();
    $.post(window.BASE_URL + '/admin/fluxos/' + window.FX_FLUXO_ID + '/salvar', {
      fluxo_id:    window.FX_FLUXO_ID,
      grafo_json:  JSON.stringify(grafo),
      config_json: $('#fx-cfg-json').val(),
      csrf_token:  window.CSRF_TOKEN || ''
    }, function (r) {
      if (r.ok) {
        mostrarMsg(r.erros && r.erros.length
          ? 'Salvo com avisos:\n• ' + r.erros.join('\n• ')
          : 'Rascunho salvo.', r.erros && r.erros.length ? 'aviso' : 'ok');
        if (typeof cb === 'function') cb(true);
      } else {
        mostrarMsg('Erros:\n• ' + (r.erros || []).join('\n• '), 'erro');
        if (typeof cb === 'function') cb(false);
      }
    }, 'json').fail(function () {
      mostrarMsg('Erro de conexão ao salvar.', 'erro');
      if (typeof cb === 'function') cb(false);
    });
  }

  function publicar() {
    salvar(function (ok) {
      if (!ok) return;
      $.post(window.BASE_URL + '/admin/fluxos/' + window.FX_FLUXO_ID + '/publicar', {
        fluxo_id: window.FX_FLUXO_ID, csrf_token: window.CSRF_TOKEN || ''
      }, function (r) {
        if (r.ok) {
          mostrarMsg('Publicado como v' + r.versao + '!', 'ok');
          setTimeout(function () { location.reload(); }, 1200);
        } else {
          mostrarMsg('Não publicado:\n• ' + (r.erros || []).join('\n• '), 'erro');
        }
      }, 'json');
    });
  }

  function mostrarMsg(texto, nivel) {
    var cores = { ok: ['#dcfce7', '#166534'], erro: ['#fee2e2', '#991b1b'], aviso: ['#fef3c7', '#92400e'] };
    var c = cores[nivel] || cores.ok;
    $('#fx-msg').css({ display: 'block', background: c[0], color: c[1] }).text(texto);
    clearTimeout(window.__fxMsgT);
    window.__fxMsgT = setTimeout(function () { $('#fx-msg').fadeOut(300); }, nivel === 'ok' ? 3000 : 8000);
  }

})(jQuery);
}


/**
 * public/js/fluxo-atividade.js
 *
 * Timeline de atividade das automações. Polling adaptativo no padrão do
 * projeto: aba ativa 15s, background pausado, refresh ao voltar.
 */
(function ($) {
  'use strict';

  var BASE = window.BASE_URL || '';
  var cursorProximo = 0;   // id do último item carregado (paginação)
  var maisAntigo = false;  // true quando o botão "carregar mais" foi usado
  var timer = null;

  // Como cada tipo de linha aparece: [ícone bi-*, classe de cor, rótulo]
  var APRESENTACAO = {
    '__inicio':         ['bi-play-circle',        'fxa_ini',   'Jornada iniciada'],
    '__fim:concluido':  ['bi-check-circle',       'fxa_ok',    'Jornada concluída'],
    '__fim:saiu':       ['bi-box-arrow-right',    'fxa_neutro','Saiu (exit condition)'],
    '__fim:erro':       ['bi-x-circle',           'fxa_erro',  'Jornada com erro'],
    'acao_email':       ['bi-envelope',           'fxa_acao',  'Email'],
    'acao_whatsapp':    ['bi-whatsapp',           'fxa_acao',  'WhatsApp'],
    'acao_notificacao': ['bi-bell',               'fxa_acao',  'Notificação'],
    'acao_tag':         ['bi-tags',               'fxa_acao',  'Tag'],
    'acao_cupom':       ['bi-ticket-perforated',  'fxa_acao',  'Cupom gerado'],
    'acao_webhook':     ['bi-hdd-network',        'fxa_acao',  'Webhook'],
    'acao_notificar_vendedor': ['bi-megaphone',   'fxa_acao',  'Vendedor avisado'],
    'esperar':          ['bi-hourglass-split',    'fxa_neutro','Esperar'],
    'esperar_evento':   ['bi-hourglass-bottom',   'fxa_neutro','Esperar evento'],
    'split_ab':         ['bi-signpost-split',     'fxa_neutro','Split A/B'],
    'encerrar':         ['bi-stop-circle',        'fxa_neutro','Encerrar']
  };

  function apresentar(item) {
    var chaveFim = item.no_chave === '__fim' ? '__fim:' + item.porta : null;
    var ap = APRESENTACAO[chaveFim] || APRESENTACAO[item.no_chave] ||
             APRESENTACAO[item.tipo_no] || null;
    if (ap) return { icone: ap[0], classe: ap[1], rotulo: ap[2] };

    // Condições e triggers genéricos
    if (item.tipo_no.indexOf('cond_') === 0) {
      return { icone: 'bi-question-diamond', classe: 'fxa_cond', rotulo: 'Condição' };
    }
    if (item.tipo_no.indexOf('trigger') === 0) {
      return { icone: 'bi-lightning-charge', classe: 'fxa_ini', rotulo: 'Trigger' };
    }
    return { icone: 'bi-arrow-right-circle', classe: 'fxa_neutro', rotulo: item.tipo_no };
  }

  function descrever(item) {
    var partes = [];
    if (item.no_chave === '__inicio') {
      partes.push('via ' + (item.tipo_no || 'trigger'));
    } else if (item.no_chave === '__fim') {
      if (item.porta === 'erro' && item.detalhe) partes.push(item.detalhe);
    } else {
      partes.push('nó "' + item.no_chave + '"');
      if (item.porta === '__erro') partes.push(item.detalhe || 'erro');
      else if (item.porta === '__dormir') partes.push('dormiu');
      else if (item.porta === '__aguardar') partes.push('aguardando evento');
      else if (item.detalhe === 'cap') partes.push('envio pulado pelo teto semanal');
      else if (item.porta && item.porta !== 'saida') partes.push('porta ' + item.porta);
    }
    return partes.join(' · ');
  }

  function quando(iso) {
    var d = new Date(String(iso).replace(' ', 'T'));
    if (isNaN(d)) return iso;
    var diff = (Date.now() - d.getTime()) / 1000;
    if (diff < 60)    return 'agora';
    if (diff < 3600)  return Math.floor(diff / 60) + ' min atrás';
    if (diff < 86400) return Math.floor(diff / 3600) + ' h atrás';
    return d.toLocaleDateString('pt-BR') + ' ' +
           d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
  }

  function linha(item) {
    var ap  = apresentar(item);
    var $li = $('<div class="fxa_item">').attr('data-id', item.id);
    if (item.porta === '__erro' || (item.no_chave === '__fim' && item.porta === 'erro')) {
      $li.addClass('fxa_item_erro');
    }

    $li.append($('<div class="fxa_ic ' + ap.classe + '">')
      .append('<i class="bi ' + ap.icone + '"></i>'));

    var $c = $('<div class="fxa_corpo">');
    var $t = $('<div class="fxa_titulo">');
    $t.append($('<b>').text(ap.rotulo));
    if (item.fluxo_nome) $t.append($('<span class="fxa_fluxo">').text(item.fluxo_nome));
    $c.append($t);

    var desc = descrever(item);
    if (desc) $c.append($('<div class="fxa_desc">').text(desc));

    var $meta = $('<div class="fxa_meta">');
    $meta.append($('<span>').text(quando(item.criado_em)));
    if (item.cliente_nome)      $meta.append($('<span>').text(item.cliente_nome));
    else if (item.cliente_id)   $meta.append($('<span>').text('cliente #' + item.cliente_id));
    $meta.append($('<span>').text('exec #' + item.execucao_id));
    if (parseInt(item.duracao_ms, 10) > 500) {
      $meta.append($('<span class="fxa_lento">').text(item.duracao_ms + ' ms'));
    }
    $c.append($meta);

    return $li.append($c);
  }

  function filtros() {
    return {
      fluxo_id:   $('#fxa-f-fluxo').val() || '',
      cliente_id: $('#fxa-f-cliente').val() || '',
      so_erros:   $('#fxa-f-erros').prop('checked') ? 1 : ''
    };
  }

  function carregar(anexar) {
    var params = filtros();
    if (anexar) params.antes_de = cursorProximo;

    $.get(BASE + '/admin/fluxos/atividade/dados', params, function (r) {
      if (!r || !r.ok) return;

      var $l = $('#fxa-lista');
      if (!anexar) $l.empty();
      else $l.find('.fxa_load').remove();

      if (!r.itens.length && !anexar) {
        $l.append($('<div class="fxa_vazio">')
          .append('<i class="bi bi-moon-stars"></i>')
          .append($('<div class="fxa_vazio_t">').text('Nada por aqui ainda'))
          .append($('<p class="fxa_vazio_p">').text(
            'Assim que um fluxo publicado rodar, cada passo aparece nesta linha do tempo.')));
        $('#fxa-mais').hide();
        return;
      }

      $.each(r.itens, function (_, item) { $l.append(linha(item)); });
      cursorProximo = r.proximo || 0;
      $('#fxa-mais').toggle(r.itens.length >= 50);

      // KPIs vêm só na primeira página
      if (r.kpis) {
        $('#fxa-k-iniciadas').text(r.kpis.iniciadas_hoje);
        $('#fxa-k-envios').text(r.kpis.envios_hoje);
        $('#fxa-k-erros').text(r.kpis.erros_24h);
        var vivas = r.kpis.ativas + r.kpis.dormindo + r.kpis.aguardando;
        $('#fxa-k-vivas').text(vivas);
        $('#fxa-k-vivas-nota').text(
          r.kpis.dormindo + ' dormindo · ' + r.kpis.aguardando + ' aguardando evento');
      }
    }, 'json');
  }

  // ── Polling adaptativo (padrão do projeto: ativo 15s, background pausado) ──
  function ligarPolling() {
    pararPolling();
    timer = setInterval(function () {
      // Não recarrega se o usuário paginou para o passado (perderia a posição)
      if (!maisAntigo) carregar(false);
    }, 15000);
    $('#fxa-ao-vivo').addClass('ativo');
  }
  function pararPolling() {
    if (timer) { clearInterval(timer); timer = null; }
    $('#fxa-ao-vivo').removeClass('ativo');
  }

  $(function () {
    carregar(false);
    ligarPolling();

    document.addEventListener('visibilitychange', function () {
      if (document.hidden) pararPolling();
      else { carregar(false); maisAntigo = false; ligarPolling(); }
    });

    $('#fxa-f-fluxo, #fxa-f-erros').on('change', function () {
      maisAntigo = false; cursorProximo = 0; carregar(false);
    });
    var tCliente = null;
    $('#fxa-f-cliente').on('input', function () {
      clearTimeout(tCliente);
      tCliente = setTimeout(function () {
        maisAntigo = false; cursorProximo = 0; carregar(false);
      }, 400);
    });

    $('#fxa-mais').on('click', function () {
      maisAntigo = true;
      carregar(true);
    });
  });

})(jQuery);
