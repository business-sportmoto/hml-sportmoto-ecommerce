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
        { k: 'evento', label: 'Evento', tipo: 'select', ops: ['produto_visto','categoria_vista','catalogo_moto_visto','busca','banner_click','pagina_vista','pedido_criado'] },
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
