/**
 * admin/assets/js/pagamento-canvas.js
 *
 * Editor visual do fluxo de pagamento, sobre Drawflow.
 *
 * Separado do fluxo-canvas.js de propósito: aquele é do motor de marketing,
 * está em produção e tem endpoints, campos e overlay de estatísticas próprios.
 * Compartilhar os dois domínios num arquivo só faria toda mudança em pagamento
 * arriscar a automação de e-mail. Aqui reaproveitamos a biblioteca e o CSS.
 *
 * Depende de: Drawflow (CDN), jQuery, e as globais PG_* injetadas pela view.
 */
(function () {
  'use strict';

  var CAT   = window.PG_CATALOGO    || {};
  var GRAFO = window.PG_GRAFO       || { nos: [], conexoes: [] };
  var ADQ   = window.PG_ADQUIRENTES || [];
  var FLUXO = window.PG_FLUXO       || {};
  var BASE  = window.PG_ADMIN_URL   || '';
  var CSRF  = window.PG_CSRF        || '';
  var SOMENTE_LEITURA = !!window.PG_READONLY;

  var editor = null;
  // Drawflow numera nós internamente; precisamos do NOSSO no_ref estável,
  // que é o que vai para o banco e para pgto_tentativas.
  var refPorId = {};   // idDrawflow -> no_ref
  var idPorRef = {};   // no_ref -> idDrawflow
  var selecionado = null;

  function esc(s) {
    return String(s === null || s === undefined ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function novoRef(tipo) {
    return tipo + '_' + Math.random().toString(36).slice(2, 8);
  }

  // ════════════════════════════════════════════════════════════
  // RENDER DO NÓ
  // ════════════════════════════════════════════════════════════

  var COR_GRUPO = {
    fluxo:    '#0f766e',
    condicao: '#1d4ed8',
    acao:     '#7c3aed',
    fim:      '#475569'
  };

  /** Rótulo curto por porta — o que aparece ao lado de cada saída. */
  var ROTULO_PORTA = {
    saida: '→', sim: 'sim', nao: 'não',
    aprovado: 'aprovado', pendente: 'pendente',
    negado_saldo: 'sem saldo', negado_antifraude: 'antifraude',
    negado_dados: 'dados inválidos', negado_generico: 'negado',
    erro_tecnico: 'erro técnico', indisponivel: 'fora do ar'
  };

  function htmlNo(tipo, ref, config) {
    var meta = CAT[tipo] || { rotulo: tipo, grupo: 'acao', portas: [] };
    var cor  = COR_GRUPO[meta.grupo] || '#475569';
    var sub  = '';

    if (tipo === 'tentar_adquirente') {
      var a = (config && config.adquirente) || '';
      sub = a ? esc(a) : '<span style="color:#b91c1c;">escolha a adquirente</span>';
    } else if (tipo === 'cond_parcelas') {
      sub = esc((config && config.min) || 1) + 'x a ' + esc((config && config.max) || 1) + 'x';
    } else if (tipo === 'cond_valor') {
      var mi = ((config && config.min) || 0) / 100, ma = ((config && config.max) || 0) / 100;
      sub = 'R$ ' + mi.toFixed(2) + ' a R$ ' + ma.toFixed(2);
    } else if (tipo === 'cond_bandeira') {
      sub = ((config && config.bandeiras) || []).join(', ') || '—';
    }

    return ''
      + '<div class="pg-no" data-ref="' + esc(ref) + '" style="border-left:3px solid ' + cor + ';">'
      +   '<div class="pg-no-titulo" style="color:' + cor + ';">' + esc(meta.rotulo) + '</div>'
      +   (sub ? '<div class="pg-no-sub">' + sub + '</div>' : '')
      + '</div>';
  }

  /** Escreve o nome de cada porta ao lado do respectivo ponto de saída. */
  /**
   * Familia visual de cada porta.
   *
   * Espelha a leitura do motor (PagamentoClassificacao):
   *   ok      passou
   *   espera  instrumento criado, dinheiro ainda nao
   *   nega    decisao do emissor — NUNCA retenta
   *   tec     falha nossa ou da adquirente — pode cair para outra
   */
  function classeDaPorta(porta) {
    if (porta === 'aprovado') return 'pg-p-ok';
    if (porta === 'pendente' || porta === 'analise') return 'pg-p-espera';
    if (porta === 'erro_tecnico' || porta === 'indisponivel' || porta === 'erro') return 'pg-p-tec';
    if (porta.indexOf('negado') === 0 || porta === 'reprovado') return 'pg-p-nega';
    return 'pg-p-neutra';
  }

  function rotularPortas(idDrawflow, tipo) {
    var meta = CAT[tipo];
    if (!meta || !meta.portas || !meta.portas.length) return;

    var el = document.querySelector('#node-' + idDrawflow);
    if (!el) return;

    meta.portas.forEach(function (porta, i) {
      var ponto = el.querySelector('.output_' + (i + 1));
      if (!ponto || ponto.querySelector('.pg-porta-lbl')) return;

      // A cor da porta diz o que ela significa, e isso decide se o motor pode
      // retentar noutra adquirente. Vermelho e recusa do emissor — ligar
      // numa segunda adquirente e retentativa proibida pelas bandeiras.
      // Com tudo cinza, so se enxerga um fluxo mal ligado lendo rotulo a
      // rotulo; com cor, um vermelho apontando para adquirente salta.
      ponto.classList.add(classeDaPorta(porta));

      var lbl = document.createElement('span');
      lbl.className = 'pg-porta-lbl';
      lbl.textContent = ROTULO_PORTA[porta] || porta;
      ponto.appendChild(lbl);
    });
  }

  function adicionarNo(tipo, x, y, ref, config) {
    var meta = CAT[tipo];
    if (!meta) return null;

    ref    = ref || novoRef(tipo);
    config = config || padroesDe(tipo);

    var nEntradas = meta.entrada ? 0 : 1;
    var nSaidas   = (meta.portas || []).length;

    var id = editor.addNode(
      tipo, nEntradas, nSaidas, x, y, tipo,
      { ref: ref, tipo: tipo, config: config },
      htmlNo(tipo, ref, config)
    );

    refPorId[id]  = ref;
    idPorRef[ref] = id;
    rotularPortas(id, tipo);
    return id;
  }

  function padroesDe(tipo) {
    var meta = CAT[tipo];
    var out = {};
    ((meta && meta.campos) || []).forEach(function (c) {
      out[c.nome] = c.padrao !== undefined ? c.padrao : '';
    });
    return out;
  }

  // ════════════════════════════════════════════════════════════
  // PAINEL DE CONFIGURAÇÃO
  // ════════════════════════════════════════════════════════════

  function abrirPainel(idDrawflow) {
    selecionado = idDrawflow;
    var no   = editor.getNodeFromId(idDrawflow);
    var tipo = no.data.tipo;
    var meta = CAT[tipo] || {};
    var cfg  = no.data.config || {};

    document.getElementById('pg-painel-titulo').textContent = meta.rotulo || tipo;
    document.getElementById('pg-painel-desc').textContent   = meta.descricao || '';

    var campos = document.getElementById('pg-painel-campos');
    campos.innerHTML = '';

    ((meta.campos) || []).forEach(function (c) {
      var wrap = document.createElement('div');
      wrap.className = 'form-group';
      wrap.innerHTML = '<label>' + esc(c.rotulo) + '</label>';

      var input;
      if (c.tipo === 'adquirente') {
        input = document.createElement('select');
        input.className = 'form-control';
        input.innerHTML = '<option value="">— escolha —</option>' + ADQ.map(function (a) {
          var sel = (cfg[c.nome] === a.codigo) ? ' selected' : '';
          var av  = a.ativo ? '' : ' (inativa)';
          return '<option value="' + esc(a.codigo) + '"' + sel + '>' + esc(a.nome) + av + '</option>';
        }).join('');
      } else if (c.tipo === 'multi') {
        input = document.createElement('div');
        Object.keys(c.opcoes || {}).forEach(function (k) {
          var marcado = (cfg[c.nome] || []).indexOf(k) !== -1 ? ' checked' : '';
          input.innerHTML += '<label class="check-label" style="margin:3px 0;">'
            + '<input type="checkbox" value="' + esc(k) + '"' + marcado + '>'
            + '<span class="check-custom"></span> ' + esc(c.opcoes[k]) + '</label>';
        });
      } else {
        input = document.createElement('input');
        input.type = (c.tipo === 'numero') ? 'number' : 'text';
        input.className = 'form-control';
        input.value = cfg[c.nome] !== undefined ? cfg[c.nome] : '';
      }

      input.setAttribute('data-campo', c.nome);
      input.setAttribute('data-tipo-campo', c.tipo || 'texto');
      wrap.appendChild(input);
      campos.appendChild(wrap);
    });

    if (!((meta.campos) || []).length) {
      campos.innerHTML = '<p style="font-size:12px;color:#64748b;">Este nó não tem configuração.</p>';
    }

    document.getElementById('pg-painel').classList.add('aberto');
  }

  /** Lê o painel e devolve para o nó. Chamado a cada alteração. */
  function aplicarPainel() {
    if (selecionado === null) return;
    var no  = editor.getNodeFromId(selecionado);
    var cfg = {};

    document.querySelectorAll('#pg-painel-campos [data-campo]').forEach(function (el) {
      var nome = el.getAttribute('data-campo');
      var t    = el.getAttribute('data-tipo-campo');
      if (t === 'multi') {
        cfg[nome] = Array.prototype.slice
          .call(el.querySelectorAll('input[type=checkbox]:checked'))
          .map(function (i) { return i.value; });
      } else if (t === 'numero') {
        cfg[nome] = parseInt(el.value, 10) || 0;
      } else {
        cfg[nome] = el.value;
      }
    });

    no.data.config = cfg;
    editor.updateNodeDataFromId(selecionado, no.data);

    // Redesenha o corpo do nó para o resumo refletir a config.
    var el = document.querySelector('#node-' + selecionado + ' .drawflow_content_node');
    if (el) {
      el.innerHTML = htmlNo(no.data.tipo, no.data.ref, cfg);
      rotularPortas(selecionado, no.data.tipo);
    }
  }

  // ════════════════════════════════════════════════════════════
  // SERIALIZAÇÃO
  // ════════════════════════════════════════════════════════════

  function serializar() {
    var bruto = editor.export();
    var dados = bruto.drawflow.Home.data;
    var nos = [], conexoes = [];

    Object.keys(dados).forEach(function (id) {
      var n = dados[id];
      var ref = (n.data && n.data.ref) || refPorId[id] || novoRef(n.name);

      nos.push({
        no_ref: ref,
        tipo:   (n.data && n.data.tipo) || n.name,
        config: (n.data && n.data.config) || {},
        pos_x:  Math.round(n.pos_x),
        pos_y:  Math.round(n.pos_y)
      });

      // Saídas: output_1..N mapeiam para as portas do tipo, na ordem
      // declarada no catálogo. É esse índice que traduz Drawflow → domínio.
      var portas = (CAT[(n.data && n.data.tipo) || n.name] || {}).portas || [];
      Object.keys(n.outputs || {}).forEach(function (chave) {
        var idx   = parseInt(chave.replace('output_', ''), 10) - 1;
        var porta = portas[idx];
        if (porta === undefined) return;

        (n.outputs[chave].connections || []).forEach(function (c) {
          var destino = dados[c.node];
          if (!destino) return;
          conexoes.push({
            no_origem:    ref,
            porta_origem: porta,
            no_destino:   (destino.data && destino.data.ref) || refPorId[c.node]
          });
        });
      });
    });

    return { nos: nos, conexoes: conexoes };
  }

  function carregar() {
    (GRAFO.nos || []).forEach(function (n) {
      adicionarNo(n.tipo, n.pos_x || 60, n.pos_y || 60, n.no_ref, n.config || {});
    });

    (GRAFO.conexoes || []).forEach(function (c) {
      var origem  = idPorRef[c.no_origem];
      var destino = idPorRef[c.no_destino];
      if (!origem || !destino) return;

      var noOrigem = editor.getNodeFromId(origem);
      var portas   = (CAT[noOrigem.data.tipo] || {}).portas || [];
      var idx      = portas.indexOf(c.porta_origem);
      if (idx === -1) return;

      try {
        editor.addConnection(origem, destino, 'output_' + (idx + 1), 'input_1');
      } catch (e) { /* conexão inválida no dado — ignora em vez de quebrar a tela */ }
    });
  }

  // ════════════════════════════════════════════════════════════
  // PERSISTÊNCIA
  // ════════════════════════════════════════════════════════════

  function enviar(url, extra, cb) {
    var g  = serializar();
    var fd = new FormData();
    fd.append('_csrf_token', CSRF);
    fd.append('fluxo_id', FLUXO.id);
    fd.append('grafo', JSON.stringify(g));
    fd.append('canvas', JSON.stringify(editor.export()));
    Object.keys(extra || {}).forEach(function (k) { fd.append(k, extra[k]); });

    fetch(BASE + url, {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (r) { return r.json(); }).then(cb)
      .catch(function () { mostrarMensagens(['Erro de conexão.'], []); });
  }

  function mostrarMensagens(erros, avisos) {
    var box = document.getElementById('pg-msg');
    box.innerHTML = '';
    (erros || []).forEach(function (e) {
      box.innerHTML += '<div class="pg-alerta pg-erro">' + esc(e) + '</div>';
    });
    (avisos || []).forEach(function (a) {
      box.innerHTML += '<div class="pg-alerta pg-aviso">' + esc(a) + '</div>';
    });
  }

  // ════════════════════════════════════════════════════════════
  // BOOT
  // ════════════════════════════════════════════════════════════

  document.addEventListener('DOMContentLoaded', function () {
    var alvo = document.getElementById('pg-canvas');
    if (!alvo || typeof Drawflow === 'undefined') return;

    editor = new Drawflow(alvo);
    editor.reroute = true;
    editor.start();

    if (SOMENTE_LEITURA) editor.editor_mode = 'fixed';

    carregar();

    // ── Paleta ────────────────────────────────────────────────
    var paleta = document.getElementById('pg-paleta');
    var grupos = { fluxo: 'Fluxo', condicao: 'Condições', acao: 'Ações', fim: 'Desfecho' };

    Object.keys(grupos).forEach(function (g) {
      var titulo = document.createElement('div');
      titulo.className = 'pg-paleta-grupo';
      titulo.textContent = grupos[g];
      paleta.appendChild(titulo);

      Object.keys(CAT).forEach(function (tipo) {
        if (CAT[tipo].grupo !== g) return;
        var item = document.createElement('div');
        item.className = 'pg-paleta-item';
        item.draggable = !SOMENTE_LEITURA;
        item.textContent = CAT[tipo].rotulo;
        item.title = CAT[tipo].descricao || '';
        item.addEventListener('dragstart', function (e) {
          e.dataTransfer.setData('tipo', tipo);
        });
        paleta.appendChild(item);
      });
    });

    alvo.addEventListener('drop', function (e) {
      e.preventDefault();
      if (SOMENTE_LEITURA) return;
      var tipo = e.dataTransfer.getData('tipo');
      if (!tipo || !CAT[tipo]) return;

      // Só um nó de entrada — a regra também é validada no servidor.
      if (CAT[tipo].entrada) {
        var jaTem = Object.keys(editor.export().drawflow.Home.data).some(function (id) {
          return editor.getNodeFromId(id).data.tipo === 'entrada';
        });
        if (jaTem) { mostrarMensagens(['Já existe um nó de Início neste fluxo.'], []); return; }
      }

      var r = alvo.getBoundingClientRect();
      var z = editor.zoom;
      adicionarNo(tipo,
        (e.clientX - r.left) / z - editor.canvas_x / z,
        (e.clientY - r.top)  / z - editor.canvas_y / z);
    });
    alvo.addEventListener('dragover', function (e) { e.preventDefault(); });

    // ── Seleção e edição ──────────────────────────────────────
    editor.on('nodeSelected', abrirPainel);
    editor.on('nodeUnselected', function () {
      document.getElementById('pg-painel').classList.remove('aberto');
      selecionado = null;
    });
    document.getElementById('pg-painel-campos').addEventListener('input', aplicarPainel);
    document.getElementById('pg-painel-campos').addEventListener('change', aplicarPainel);

    document.getElementById('pg-del-no').addEventListener('click', function () {
      if (selecionado === null) return;
      editor.removeNodeId('node-' + selecionado);
      document.getElementById('pg-painel').classList.remove('aberto');
      selecionado = null;
    });

    // ── Zoom ──────────────────────────────────────────────────
    document.getElementById('pg-zoom-in').addEventListener('click', function () { editor.zoom_in(); });
    document.getElementById('pg-zoom-out').addEventListener('click', function () { editor.zoom_out(); });
    document.getElementById('pg-zoom-reset').addEventListener('click', function () { editor.zoom_reset(); });

    // ── Salvar / publicar ─────────────────────────────────────
    var btnSalvar = document.getElementById('pg-salvar');
    if (btnSalvar) {
      btnSalvar.addEventListener('click', function () {
        btnSalvar.disabled = true;
        enviar('/pagamentos/fluxos/salvar', {}, function (res) {
          btnSalvar.disabled = false;
          mostrarMensagens(res.erros, res.avisos);
          if (window.Toast) { res.ok ? Toast.success(res.msg) : Toast.error(res.msg); }
        });
      });
    }

    var btnPublicar = document.getElementById('pg-publicar');
    if (btnPublicar) {
      btnPublicar.addEventListener('click', function () {
        if (!confirm('Publicar este fluxo? Ele passa a valer nos próximos pagamentos.')) return;
        btnPublicar.disabled = true;
        enviar('/pagamentos/fluxos/publicar', {}, function (res) {
          btnPublicar.disabled = false;
          mostrarMensagens(res.erros, res.avisos);
          if (window.Toast) { res.ok ? Toast.success(res.msg) : Toast.error(res.msg); }
          if (res.ok) setTimeout(function () { location.href = BASE + '/pagamentos/fluxos'; }, 1200);
        });
      });
    }
  });
})();
