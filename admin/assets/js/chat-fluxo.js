/**
 * admin/assets/js/chat-fluxo.js
 *
 * Construtor visual de fluxos conversacionais, sobre Drawflow 0.0.60.
 *
 * Três partes:
 *   1. CHFX_UI  — metadados de apresentação e formulário de cada bloco
 *   2. Conv     — conversor puro Drawflow ⇄ {nos, conexoes} do backend
 *   3. UI       — paleta, canvas, painel de configuração, salvar/publicar
 *
 * O backend não conhece o Drawflow: recebe e devolve sempre o mesmo formato
 * {nos:[{chave,tipo,config,pos}], conexoes:[{de,porta,para}]}. Manter o
 * conversor puro é o que permite testar a serialização sem abrir o navegador.
 */
(function ($) {
  'use strict';

  var $canvas = $('#ch-fx-canvas');
  if (!$canvas.length || typeof Drawflow === 'undefined') return;

  var CFG  = window.CHFX || {};
  var BASE = CFG.base || window.BASE_URL || '';
  var CSRF = CFG.csrf || window.CSRF_TOKEN || '';

  // ═══════════════════════════════════════════════════════════════════════════
  // 1. METADADOS DOS BLOCOS
  //    cat: agrupamento na paleta · campos: formulário do painel
  //    tipos de campo: text, textarea, number, select, checkbox, tags, template,
  //                    fluxo, agente, campo, botoes, secoes, tempo, params
  // ═══════════════════════════════════════════════════════════════════════════
  var CATS = {
    trigger:  { label: 'Gatilhos de entrada', cor: '#16a34a' },
    mensagem: { label: 'Mensagens',           cor: '#0a66c2' },
    logica:   { label: 'Lógica',              cor: '#71717a' },
    condicao: { label: 'Condições',           cor: '#f59e0b' },
    acao:     { label: 'Ações',               cor: '#8b5cf6' }
  };

  var UI = {
    // ── Gatilhos ────────────────────────────────────────────────────────────
    gatilho_palavra: {
      cat: 'trigger', label: 'Palavra-chave', ico: '🔑',
      desc: 'Dispara quando a mensagem contém uma das palavras.',
      campos: [
        { k: 'palavras', label: 'Palavras (separadas por vírgula)', tipo: 'text', def: '',
          ajuda: 'Ex.: oi, ola, menu, bom dia' },
        { k: 'modo', label: 'Como comparar', tipo: 'select', def: 'contem',
          ops: [['contem','Contém'], ['exato','Exatamente igual'], ['comeca','Começa com'], ['regex','Expressão regular']] }
      ],
      resumo: function (c) { return c.palavras || 'sem palavras'; }
    },
    gatilho_boas_vindas: {
      cat: 'trigger', label: 'Primeira mensagem', ico: '👋',
      desc: 'Dispara só na primeira vez que a pessoa escreve.', campos: []
    },
    gatilho_padrao: {
      cat: 'trigger', label: 'Resposta padrão', ico: '💬',
      desc: 'Quando nada mais casou. A rede de segurança da conversa.', campos: []
    },
    gatilho_referencia: {
      cat: 'trigger', label: 'Link com código', ico: '🔗',
      desc: 'Veio de um link wa.me com código ou de um anúncio.',
      campos: [{ k: 'codigo', label: 'Código de referência', tipo: 'text', def: '',
                 ajuda: 'O mesmo valor cadastrado no gatilho de referência.' }],
      resumo: function (c) { return c.codigo || 'sem código'; }
    },
    gatilho_manual: {
      cat: 'trigger', label: 'Disparo manual', ico: '▶️',
      desc: 'Iniciado pelo atendimento, por campanha ou por API.', campos: []
    },
    gatilho_evento_loja: {
      cat: 'trigger', label: 'Evento da loja', ico: '🛒',
      desc: 'Ligado a um evento do site (pedido, carrinho...).',
      campos: [{ k: 'evento', label: 'Evento', tipo: 'select', def: 'pedido_criado',
                 ops: [['pedido_criado','Pedido criado'], ['carrinho_abandonado','Carrinho abandonado'],
                       ['produto_visto','Produto visto'], ['pedido_entregue','Pedido entregue']] }],
      resumo: function (c) { return c.evento || ''; }
    },

    // ── Mensagens ───────────────────────────────────────────────────────────
    msg_texto: {
      cat: 'mensagem', label: 'Texto', ico: '📝',
      desc: 'Manda uma mensagem de texto.',
      campos: [
        { k: 'texto', label: 'Mensagem', tipo: 'textarea', def: '',
          ajuda: 'Aceita {{primeiro_nome}}, {{saudacao}} e qualquer campo do contato. *negrito*, _itálico_.' },
        { k: 'preview_url', label: 'Mostrar prévia de links', tipo: 'checkbox', def: true }
      ],
      resumo: function (c) { return c.texto || ''; }
    },
    msg_midia: {
      cat: 'mensagem', label: 'Imagem / arquivo', ico: '🖼️',
      desc: 'Envia foto, vídeo, áudio ou documento por URL.',
      campos: [
        { k: 'tipo_midia', label: 'Tipo', tipo: 'select', def: 'image',
          ops: [['image','Imagem'], ['video','Vídeo'], ['audio','Áudio'], ['document','Documento']] },
        { k: 'url', label: 'URL pública do arquivo', tipo: 'text', def: '',
          ajuda: 'A Meta busca o arquivo neste endereço — precisa estar acessível pela internet.' },
        { k: 'legenda', label: 'Legenda (opcional)', tipo: 'textarea', def: '' }
      ],
      resumo: function (c) { return c.url || ''; }
    },
    msg_botoes: {
      cat: 'mensagem', label: 'Pergunta com botões', ico: '🔘',
      desc: 'Até 3 botões. O fluxo espera o clique e ramifica.',
      campos: [
        { k: 'corpo', label: 'Pergunta', tipo: 'textarea', def: 'Escolha uma opção:' },
        { k: 'botoes', label: 'Botões (máx. 3, até 20 caracteres cada)', tipo: 'botoes' },
        { k: 'rodape', label: 'Rodapé (opcional)', tipo: 'text', def: '' },
        { k: 'salvar_em', label: 'Salvar escolha no campo', tipo: 'campo', def: '' },
        { k: 'timeout', label: 'Esperar resposta por', tipo: 'tempo', def: { horas: 24 } }
      ],
      resumo: function (c) {
        var b = (c.botoes || []).map(function (x) { return x.titulo; }).filter(Boolean);
        return (c.corpo || '') + (b.length ? ' [' + b.join(' | ') + ']' : '');
      }
    },
    msg_lista: {
      cat: 'mensagem', label: 'Menu em lista', ico: '📋',
      desc: 'Até 10 opções em menu. Ramifica pela escolha.',
      campos: [
        { k: 'corpo', label: 'Texto', tipo: 'textarea', def: 'Escolha uma opção:' },
        { k: 'texto_botao', label: 'Texto do botão que abre a lista', tipo: 'text', def: 'Ver opções' },
        { k: 'secoes', label: 'Opções (máx. 10 no total)', tipo: 'secoes' },
        { k: 'salvar_em', label: 'Salvar escolha no campo', tipo: 'campo', def: '' },
        { k: 'timeout', label: 'Esperar resposta por', tipo: 'tempo', def: { horas: 24 } }
      ],
      resumo: function (c) {
        var n = 0;
        (c.secoes || []).forEach(function (s) { n += (s.linhas || []).length; });
        return (c.corpo || '') + ' (' + n + ' opção(ões))';
      }
    },
    msg_template: {
      cat: 'mensagem', label: 'Template aprovado', ico: '📨',
      desc: 'Único formato aceito fora da janela de 24h.',
      campos: [
        { k: 'nome', label: 'Template', tipo: 'template', def: '' },
        { k: 'params_body', label: 'Variáveis do corpo (uma por linha)', tipo: 'params' },
        { k: 'param_header', label: 'Variável do cabeçalho (se houver)', tipo: 'text', def: '' },
        { k: 'param_botao', label: 'Complemento da URL do botão (se houver)', tipo: 'text', def: '' }
      ],
      resumo: function (c) { return c.nome || 'nenhum template'; }
    },
    msg_botao_url: {
      cat: 'mensagem', label: 'Botão com link', ico: '🔗',
      desc: 'Mensagem com um botão que abre uma URL.',
      campos: [
        { k: 'corpo', label: 'Texto', tipo: 'textarea', def: '' },
        { k: 'texto_botao', label: 'Texto do botão', tipo: 'text', def: 'Abrir' },
        { k: 'url', label: 'URL', tipo: 'text', def: '' },
        { k: 'rodape', label: 'Rodapé (opcional)', tipo: 'text', def: '' }
      ],
      resumo: function (c) { return c.url || ''; }
    },

    // ── Lógica ──────────────────────────────────────────────────────────────
    esperar: {
      cat: 'logica', label: 'Esperar', ico: '⏳',
      desc: 'Pausa a jornada por um tempo.',
      campos: [{ k: '', label: 'Esperar', tipo: 'tempo_raiz', def: { horas: 1 } }],
      resumo: function (c) {
        var p = [];
        if (c.dias)    p.push(c.dias + 'd');
        if (c.horas)   p.push(c.horas + 'h');
        if (c.minutos) p.push(c.minutos + 'min');
        return p.join(' ') || '1h';
      }
    },
    esperar_resposta: {
      cat: 'logica', label: 'Perguntar', ico: '❓',
      desc: 'Faz uma pergunta aberta, valida e guarda a resposta.',
      campos: [
        { k: 'pergunta', label: 'Pergunta', tipo: 'textarea', def: '' },
        { k: 'salvar_em', label: 'Salvar resposta no campo', tipo: 'campo', def: 'resposta' },
        { k: 'validacao', label: 'Formato esperado', tipo: 'select', def: 'texto',
          ops: [['texto','Qualquer texto'], ['numero','Número'], ['email','E-mail'],
                ['telefone','Telefone'], ['cep','CEP'], ['cpf','CPF']] },
        { k: 'mensagem_invalida', label: 'O que dizer se não entender', tipo: 'text',
          def: 'Não entendi. Pode tentar de novo?' },
        { k: 'max_tentativas', label: 'Tentativas antes de desistir', tipo: 'number', def: 3 },
        { k: 'timeout', label: 'Esperar resposta por', tipo: 'tempo', def: { horas: 24 } }
      ],
      resumo: function (c) { return c.pergunta || ''; }
    },
    split_ab: {
      cat: 'logica', label: 'Teste A/B', ico: '🔀',
      desc: 'Divide o público em dois caminhos.',
      campos: [{ k: 'peso_a', label: 'Percentual no caminho A', tipo: 'number', def: 50 }],
      resumo: function (c) { var a = c.peso_a == null ? 50 : c.peso_a; return a + '% / ' + (100 - a) + '%'; }
    },
    encerrar: {
      cat: 'logica', label: 'Encerrar', ico: '⏹️',
      desc: 'Termina a jornada aqui.', campos: []
    },
    ir_para_fluxo: {
      cat: 'logica', label: 'Ir para outro fluxo', ico: '↪️',
      desc: 'Encerra este e começa outro fluxo.',
      campos: [{ k: 'fluxo_id', label: 'Fluxo de destino', tipo: 'fluxo', def: 0 }],
      resumo: function (c) { return nomeFluxo(c.fluxo_id); }
    },

    // ── Condições ───────────────────────────────────────────────────────────
    cond_tem_tag: {
      cat: 'condicao', label: 'Tem a tag?', ico: '🏷️',
      desc: 'Ramifica se o contato tem determinada tag.',
      campos: [{ k: 'tag_id', label: 'Tag', tipo: 'tag', def: 0 }],
      resumo: function (c) { return nomeTag(c.tag_id); }
    },
    cond_campo: {
      cat: 'condicao', label: 'Valor do campo', ico: '🔍',
      desc: 'Compara um campo do contato com um valor.',
      campos: [
        { k: 'campo', label: 'Campo', tipo: 'campo', def: '' },
        { k: 'operador', label: 'Comparação', tipo: 'select', def: '=',
          ops: [['=','é igual a'], ['!=','é diferente de'], ['contem','contém'], ['comeca','começa com'],
                ['>','maior que'], ['>=','maior ou igual'], ['<','menor que'], ['<=','menor ou igual'],
                ['existe','está preenchido'], ['nao_existe','está vazio']] },
        { k: 'valor', label: 'Valor', tipo: 'text', def: '' }
      ],
      resumo: function (c) { return (c.campo || '?') + ' ' + (c.operador || '=') + ' ' + (c.valor || ''); }
    },
    cond_na_janela: {
      cat: 'condicao', label: 'Janela 24h aberta?', ico: '⏱️',
      desc: 'Decide entre texto livre e template.', campos: []
    },
    cond_eh_cliente: {
      cat: 'condicao', label: 'É cliente da loja?', ico: '👤',
      desc: 'O contato está vinculado a um cadastro.', campos: []
    },
    cond_comprou: {
      cat: 'condicao', label: 'Quanto já comprou', ico: '💰',
      desc: 'Compara o total gasto na loja.',
      campos: [
        { k: 'operador', label: 'Comparação', tipo: 'select', def: '>=',
          ops: [['>=','maior ou igual a'], ['>','maior que'], ['<=','menor ou igual a'], ['<','menor que'], ['=','igual a']] },
        { k: 'valor', label: 'Valor (R$)', tipo: 'number', def: 500 },
        { k: 'janela_dias', label: 'Nos últimos X dias (0 = sempre)', tipo: 'number', def: 0 }
      ],
      resumo: function (c) { return 'total ' + (c.operador || '>=') + ' R$ ' + (c.valor || 0); }
    },
    cond_horario: {
      cat: 'condicao', label: 'Horário / dia', ico: '🕐',
      desc: 'Ramifica por horário comercial ou dia da semana.',
      campos: [
        { k: 'de', label: 'Das', tipo: 'number', def: 8 },
        { k: 'ate', label: 'Até', tipo: 'number', def: 18 },
        { k: 'dias', label: 'Dias da semana', tipo: 'dias', def: [1,2,3,4,5] }
      ],
      resumo: function (c) { return (c.de || 0) + 'h–' + (c.ate || 24) + 'h'; }
    },

    // ── Ações ───────────────────────────────────────────────────────────────
    acao_tag: {
      cat: 'acao', label: 'Marcar com tag', ico: '🏷️',
      desc: 'Adiciona ou remove uma tag do contato.',
      campos: [
        { k: 'acao', label: 'O que fazer', tipo: 'select', def: 'adicionar',
          ops: [['adicionar','Adicionar tag'], ['remover','Remover tag']] },
        { k: 'tag_id', label: 'Tag', tipo: 'tag', def: 0 }
      ],
      resumo: function (c) { return (c.acao === 'remover' ? '− ' : '+ ') + nomeTag(c.tag_id); }
    },
    acao_campo: {
      cat: 'acao', label: 'Gravar campo', ico: '💾',
      desc: 'Guarda um valor no cadastro do contato.',
      campos: [
        { k: 'campo', label: 'Campo', tipo: 'campo', def: '' },
        { k: 'operacao', label: 'Operação', tipo: 'select', def: 'set',
          ops: [['set','Definir valor'], ['incrementar','Somar'], ['limpar','Limpar']] },
        { k: 'valor', label: 'Valor', tipo: 'text', def: '' }
      ],
      resumo: function (c) { return (c.campo || '?') + ' = ' + (c.valor || ''); }
    },
    acao_humano: {
      cat: 'acao', label: 'Chamar atendente', ico: '🙋',
      desc: 'Pausa o bot e coloca a conversa na fila humana.',
      campos: [
        { k: 'mensagem', label: 'Aviso para o cliente', tipo: 'textarea',
          def: 'Só um momento, já vou te transferir para um atendente.' },
        { k: 'atribuir_a', label: 'Atribuir a', tipo: 'agente', def: 0 },
        { k: 'pausar_minutos', label: 'Pausar o bot por (min, 0 = até resolver)', tipo: 'number', def: 60 },
        { k: 'status', label: 'Marcar conversa como', tipo: 'select', def: 'pendente',
          ops: [['pendente','Pendente'], ['aberta','Aberta']] }
      ],
      resumo: function (c) { return c.mensagem || 'transferir'; }
    },
    acao_webhook: {
      cat: 'acao', label: 'Chamar webhook', ico: '🌐',
      desc: 'Envia os dados para um sistema externo.',
      campos: [
        { k: 'url', label: 'URL', tipo: 'text', def: '' },
        { k: 'metodo', label: 'Método', tipo: 'select', def: 'POST', ops: [['POST','POST'], ['PUT','PUT']] },
        { k: 'enviar_contexto', label: 'Enviar os dados coletados no fluxo', tipo: 'checkbox', def: true }
      ],
      resumo: function (c) { return c.url || ''; }
    },
    acao_notificar_admin: {
      cat: 'acao', label: 'Avisar a equipe', ico: '🔔',
      desc: 'Notificação no painel para todos os admins.',
      campos: [
        { k: 'titulo', label: 'Título', tipo: 'text', def: '' },
        { k: 'mensagem', label: 'Mensagem', tipo: 'textarea', def: '' },
        { k: 'url', label: 'Link (opcional)', tipo: 'text', def: '' }
      ],
      resumo: function (c) { return c.titulo || ''; }
    },
    acao_cupom: {
      cat: 'acao', label: 'Gerar cupom', ico: '🎟️',
      desc: 'Cria um cupom exclusivo. Use {{cupom_codigo}} depois.',
      campos: [
        { k: 'pct', label: 'Desconto (%)', tipo: 'number', def: 10 },
        { k: 'dias_validade', label: 'Validade (dias)', tipo: 'number', def: 15 },
        { k: 'prefixo', label: 'Prefixo do código', tipo: 'text', def: 'CHAT' },
        { k: 'valor_minimo', label: 'Valor mínimo do pedido (R$)', tipo: 'number', def: 0 }
      ],
      resumo: function (c) { return (c.pct || 10) + '% por ' + (c.dias_validade || 15) + ' dias'; }
    },
    acao_optout: {
      cat: 'acao', label: 'Descadastrar', ico: '🚫',
      desc: 'Registra opt-out e encerra a jornada.',
      campos: [{ k: 'mensagem', label: 'Mensagem de despedida', tipo: 'textarea',
                 def: 'Pronto! Você não vai mais receber nossas mensagens.' }],
      resumo: function (c) { return c.mensagem || ''; }
    },

    // ── Instagram ───────────────────────────────────────────────────────────
    cond_canal: {
      cat: 'condicao', label: 'Qual canal?', ico: '🔱',
      desc: 'Ramifica entre WhatsApp e Instagram. O mesmo fluxo atende os dois.',
      campos: []
    },
    cond_ig_segue: {
      cat: 'condicao', label: 'Segue o perfil?', ico: '➕',
      desc: 'Instagram: o contato segue a conta. Só se sabe depois que a pessoa manda DM.',
      campos: []
    },
    msg_ig_card: {
      cat: 'mensagem', label: 'Card com botão', ico: '🪧',
      desc: 'Cartão com imagem e botão. No WhatsApp vira mensagem com botão de link.',
      campos: [
        { k: 'titulo', label: 'Título', tipo: 'text', def: '' },
        { k: 'imagem', label: 'URL da imagem (opcional)', tipo: 'text', def: '' },
        { k: 'botao_titulo', label: 'Texto do botão', tipo: 'text', def: 'Ver' },
        { k: 'botao_url', label: 'URL do botão', tipo: 'text', def: '' }
      ],
      resumo: function (c) { return c.titulo || ''; }
    },
    acao_ig_responder_comentario: {
      cat: 'acao', label: 'Responder comentário', ico: '💬',
      desc: 'Responde em público o comentário que iniciou este fluxo. Ignorado se o fluxo veio de outro lugar.',
      campos: [
        { k: 'texto', label: 'Resposta', tipo: 'textarea', def: '',
          ajuda: 'Separe variações com | para o perfil não ficar com respostas idênticas. Ex.: Te chamei no direct! | Mandei no seu direct 😉' }
      ],
      resumo: function (c) { return c.texto || ''; }
    }
  };

  // Rótulos amigáveis para as portas
  var PORTAS = {
    saida: 'segue', true: 'sim', false: 'não', a: 'A', b: 'B',
    timeout: 'sem resposta', resposta: 'respondeu', invalido: 'inválido',
    sucesso: 'ok', erro: 'erro', sem_cliente: 'não é cliente',
    btn_1: 'botão 1', btn_2: 'botão 2', btn_3: 'botão 3',
    whatsapp: 'WhatsApp', instagram: 'Instagram'
  };
  for (var i = 1; i <= 10; i++) PORTAS['op_' + i] = 'opção ' + i;

  function rotuloPorta(p) { return PORTAS[p] || p; }
  function nomeTag(id) {
    var t = (CFG.tags || []).filter(function (x) { return String(x.id) === String(id); })[0];
    return t ? t.nome : 'nenhuma tag';
  }
  function nomeFluxo(id) {
    var f = (CFG.fluxos || []).filter(function (x) { return String(x.id) === String(id); })[0];
    return f ? f.nome : 'nenhum fluxo';
  }
  function esc(s) { return $('<i>').text(s == null ? '' : String(s)).html(); }

  // ═══════════════════════════════════════════════════════════════════════════
  // 2. CONVERSOR Drawflow ⇄ backend
  // ═══════════════════════════════════════════════════════════════════════════
  var Conv = {
    /** Drawflow export → {nos, conexoes} */
    paraBackend: function (dfExport) {
      var dados = (dfExport.drawflow && dfExport.drawflow.Home && dfExport.drawflow.Home.data) || {};
      var nos = [], conexoes = [];

      Object.keys(dados).forEach(function (idDf) {
        var n = dados[idDf];
        var d = n.data || {};
        if (!d.chave || !d.tipo) return;

        nos.push({
          chave: d.chave,
          tipo: d.tipo,
          config: d.config || {},
          pos: [Math.round(n.pos_x || 0), Math.round(n.pos_y || 0)]
        });

        // outputs: { output_1: { connections: [{node, output}] } }
        var portas = (UI[d.tipo] && portasDe(d.tipo)) || [];
        Object.keys(n.outputs || {}).forEach(function (chaveSaida) {
          var idx = parseInt(chaveSaida.replace('output_', ''), 10) - 1;
          var porta = portas[idx];
          if (!porta) return;

          ((n.outputs[chaveSaida] || {}).connections || []).forEach(function (c) {
            var destino = dados[c.node];
            if (destino && destino.data && destino.data.chave) {
              conexoes.push({ de: d.chave, porta: porta, para: destino.data.chave });
            }
          });
        });
      });

      return { nos: nos, conexoes: conexoes };
    }
  };

  function portasDe(tipo) {
    return (CFG.catalogo[tipo] && CFG.catalogo[tipo].portas) || [];
  }
  function ehTrigger(tipo) {
    return !!(CFG.catalogo[tipo] && CFG.catalogo[tipo].trigger);
  }

  // ═══════════════════════════════════════════════════════════════════════════
  // 3. EDITOR
  // ═══════════════════════════════════════════════════════════════════════════
  var editor = new Drawflow($canvas[0]);
  editor.reroute = true;
  editor.reroute_fix_curvature = true;
  editor.force_first_input = false;
  editor.start();

  var mapaChaveParaDf = {};   // chave lógica → id do nó no Drawflow
  var noSelecionado = null;   // id do Drawflow
  var contadorChave = 0;

  function novaChave(tipo) {
    contadorChave++;
    return tipo.split('_')[0].substring(0, 4) + contadorChave + Math.random().toString(36).substring(2, 5);
  }

  // ── Renderização do nó no canvas ────────────────────────────────────────
  function htmlNo(chave, tipo, config) {
    var meta = UI[tipo] || { label: tipo, ico: '?', cat: 'acao' };
    var cor  = CATS[meta.cat] ? meta.cat : 'acao';
    var resumo = '';

    try { resumo = meta.resumo ? meta.resumo(config || {}) : ''; } catch (e) { resumo = ''; }
    resumo = String(resumo || '').substring(0, 90);

    return '' +
      '<div class="ch-fx-no" data-chave="' + esc(chave) + '">' +
        '<div class="ch-fx-no-head ch-fx-c-' + cor + '">' +
          '<span>' + meta.ico + '</span><span>' + esc(meta.label) + '</span>' +
        '</div>' +
        '<div class="ch-fx-no-corpo">' +
          (resumo ? esc(resumo) : '<span class="ch-fx-no-vazio">clique para configurar</span>') +
        '</div>' +
        '<div class="ch-fx-no-stat" data-stat="' + esc(chave) + '" style="display:none;"></div>' +
      '</div>';
  }

  function adicionarNo(tipo, x, y, chave, config) {
    chave  = chave || novaChave(tipo);
    config = config || {};

    var portas  = portasDe(tipo);
    var entradas = ehTrigger(tipo) ? 0 : 1;
    var saidas   = portas.length;

    var id = editor.addNode(
      chave, entradas, saidas, x, y, 'ch-fx-node',
      { chave: chave, tipo: tipo, config: config },
      htmlNo(chave, tipo, config), false
    );

    mapaChaveParaDf[chave] = id;
    setTimeout(function () { rotularPortas(id, portas); }, 30);
    return id;
  }

  /** Escreve o nome da porta ao lado de cada bolinha de saída. */
  function rotularPortas(idDf, portas) {
    var $no = $('#node-' + idDf);
    $no.find('.output').each(function (i) {
      if (!portas[i]) return;
      $(this).attr('title', rotuloPorta(portas[i]));
      if (!$(this).find('.ch-fx-porta').length) {
        $(this).append('<span class="ch-fx-porta">' + esc(rotuloPorta(portas[i])) + '</span>');
      }
    });
  }

  // ── Paleta ──────────────────────────────────────────────────────────────
  function montarPaleta() {
    var html = '';
    Object.keys(CATS).forEach(function (cat) {
      var tipos = Object.keys(UI).filter(function (t) { return UI[t].cat === cat && CFG.catalogo[t]; });
      if (!tipos.length) return;

      html += '<div class="ch-fx-cat">' + esc(CATS[cat].label) + '</div>';
      tipos.forEach(function (t) {
        html += '<div class="ch-fx-item" draggable="true" data-tipo="' + t + '" title="' + esc(UI[t].desc || '') + '">' +
                  '<span class="ch-fx-item-ico" style="background:' + CATS[cat].cor + '">' + UI[t].ico + '</span>' +
                  '<span>' + esc(UI[t].label) + '</span>' +
                '</div>';
      });
    });
    $('#ch-fx-paleta').html(html);
  }

  $(document).on('dragstart', '.ch-fx-item', function (e) {
    e.originalEvent.dataTransfer.setData('tipo', $(this).data('tipo'));
  });
  $canvas.on('dragover', function (e) { e.preventDefault(); });
  $canvas.on('drop', function (e) {
    e.preventDefault();
    var tipo = e.originalEvent.dataTransfer.getData('tipo');
    if (!tipo || !UI[tipo]) return;

    // Converte a posição do mouse para o sistema de coordenadas do canvas,
    // compensando zoom e pan — senão o bloco cai longe do cursor.
    var rect = $canvas[0].getBoundingClientRect();
    var z = editor.zoom;
    var x = (e.originalEvent.clientX - rect.left - editor.canvas_x) / z;
    var y = (e.originalEvent.clientY - rect.top  - editor.canvas_y) / z;

    adicionarNo(tipo, x, y);
  });

  // ── Seleção e painel ────────────────────────────────────────────────────
  editor.on('nodeSelected', function (id) {
    noSelecionado = id;
    abrirPainel(id);
  });
  editor.on('nodeUnselected', function () {
    noSelecionado = null;
    fecharPainel();
  });
  editor.on('nodeRemoved', function (id) {
    Object.keys(mapaChaveParaDf).forEach(function (k) {
      if (String(mapaChaveParaDf[k]) === String(id)) delete mapaChaveParaDf[k];
    });
    if (String(noSelecionado) === String(id)) { noSelecionado = null; fecharPainel(); }
  });

  function fecharPainel() {
    $('#ch-fx-p-titulo').text('Nenhum bloco selecionado');
    $('#ch-fx-p-chave').text('');
    $('#ch-fx-p-campos').html('<div class="ch-vazio" style="padding:24px 6px;">Clique num bloco para configurar.</div>');
    $('#ch-fx-p-pe').hide();
  }

  function abrirPainel(id) {
    var no = editor.getNodeFromId(id);
    if (!no || !no.data || !no.data.tipo) return;

    var tipo = no.data.tipo;
    var meta = UI[tipo] || { label: tipo, campos: [] };
    var cfg  = no.data.config || {};

    $('#ch-fx-p-titulo').text(meta.label);
    $('#ch-fx-p-chave').text(no.data.chave + ' · ' + tipo);
    $('#ch-fx-p-pe').show();

    var html = meta.desc ? '<p class="ch-sm ch-mut" style="margin:0 0 14px;">' + esc(meta.desc) + '</p>' : '';

    if (!meta.campos.length) {
      html += '<div class="ch-sm ch-mut">Este bloco não precisa de configuração.</div>';
    }

    meta.campos.forEach(function (campo) {
      html += renderCampo(campo, cfg);
    });

    // Mostra para onde cada saída vai — evita ter que seguir a linha no olho
    var portas = portasDe(tipo);
    if (portas.length) {
      html += '<div class="ch-fx-cat" style="margin-top:18px;">Saídas deste bloco</div>';
      portas.forEach(function (p) {
        html += '<div class="ch-dado"><dt>' + esc(rotuloPorta(p)) + '</dt><dd class="ch-sm ch-mut">' +
                esc(destinoDaPorta(id, portas.indexOf(p))) + '</dd></div>';
      });
    }

    $('#ch-fx-p-campos').html(html);
  }

  function destinoDaPorta(idDf, idx) {
    var no = editor.getNodeFromId(idDf);
    var saida = (no.outputs || {})['output_' + (idx + 1)];
    var cons = (saida && saida.connections) || [];
    if (!cons.length) return 'não ligada';
    var alvo = editor.getNodeFromId(cons[0].node);
    var tipoAlvo = alvo && alvo.data ? alvo.data.tipo : '';
    return (UI[tipoAlvo] && UI[tipoAlvo].label) || 'bloco';
  }

  // ── Campos do formulário ────────────────────────────────────────────────
  function renderCampo(campo, cfg) {
    var k = campo.k;
    var v = k === '' ? cfg : (cfg[k] !== undefined ? cfg[k] : campo.def);
    var id = 'chf_' + (k || 'raiz');
    var ajuda = campo.ajuda ? '<div class="ch-ajuda">' + esc(campo.ajuda) + '</div>' : '';
    var lbl = '<label class="ch-label">' + esc(campo.label) + '</label>';

    switch (campo.tipo) {
      case 'textarea':
        return '<div class="ch-campo">' + lbl +
               '<textarea class="ch-textarea ch-fx-c" data-k="' + k + '" rows="4">' + esc(v || '') + '</textarea>' +
               ajuda + '</div>';

      case 'number':
        return '<div class="ch-campo">' + lbl +
               '<input type="number" class="ch-input ch-fx-c" data-k="' + k + '" value="' + esc(v == null ? '' : v) + '">' +
               ajuda + '</div>';

      case 'checkbox':
        return '<div class="ch-campo"><label class="ch-check">' +
               '<input type="checkbox" class="ch-fx-c" data-k="' + k + '" ' + (v ? 'checked' : '') + '>' +
               '<span>' + esc(campo.label) + '</span></label>' + ajuda + '</div>';

      case 'select':
        var ops = (campo.ops || []).map(function (o) {
          var val = Array.isArray(o) ? o[0] : o, txt = Array.isArray(o) ? o[1] : o;
          return '<option value="' + esc(val) + '"' + (String(v) === String(val) ? ' selected' : '') + '>' + esc(txt) + '</option>';
        }).join('');
        return '<div class="ch-campo">' + lbl +
               '<select class="ch-select ch-fx-c" data-k="' + k + '">' + ops + '</select>' + ajuda + '</div>';

      case 'tag':
        var otags = '<option value="0">— selecione —</option>' + (CFG.tags || []).map(function (t) {
          return '<option value="' + t.id + '"' + (String(v) === String(t.id) ? ' selected' : '') + '>' + esc(t.nome) + '</option>';
        }).join('');
        return '<div class="ch-campo">' + lbl +
               '<select class="ch-select ch-fx-c" data-k="' + k + '" data-num="1">' + otags + '</select>' +
               (CFG.tags.length ? '' : '<div class="ch-ajuda">Nenhuma tag criada ainda.</div>') + '</div>';

      case 'fluxo':
        var oflx = '<option value="0">— selecione —</option>' + (CFG.fluxos || []).map(function (f) {
          return '<option value="' + f.id + '"' + (String(v) === String(f.id) ? ' selected' : '') + '>' + esc(f.nome) + '</option>';
        }).join('');
        return '<div class="ch-campo">' + lbl +
               '<select class="ch-select ch-fx-c" data-k="' + k + '" data-num="1">' + oflx + '</select>' +
               (CFG.fluxos.length ? '' : '<div class="ch-ajuda">Nenhum outro fluxo publicado.</div>') + '</div>';

      case 'agente':
        var oag = '<option value="0">Ninguém (fica na fila geral)</option>' + (CFG.agentes || []).map(function (a) {
          return '<option value="' + a.id + '"' + (String(v) === String(a.id) ? ' selected' : '') + '>' + esc(a.nome) + '</option>';
        }).join('');
        return '<div class="ch-campo">' + lbl +
               '<select class="ch-select ch-fx-c" data-k="' + k + '" data-num="1">' + oag + '</select></div>';

      case 'template':
        var otpl = '<option value="">— selecione —</option>' + (CFG.templates || []).map(function (t) {
          return '<option value="' + esc(t.nome) + '"' + (v === t.nome ? ' selected' : '') +
                 ' data-vars="' + t.vars_body + '">' + esc(t.nome) + ' (' + t.vars_body + ' var.)</option>';
        }).join('');
        return '<div class="ch-campo">' + lbl +
               '<select class="ch-select ch-fx-c" data-k="' + k + '">' + otpl + '</select>' +
               '<div class="ch-ajuda">Só aparecem templates aprovados. Sincronize em Chat → Templates.</div></div>';

      case 'campo':
        var lista = (CFG.campos || []).map(function (c) {
          return '<option value="' + esc(c) + '"></option>';
        }).join('');
        return '<div class="ch-campo">' + lbl +
               '<input type="text" class="ch-input ch-fx-c" data-k="' + k + '" list="' + id + '_lst" value="' + esc(v || '') + '">' +
               '<datalist id="' + id + '_lst">' + lista + '</datalist>' +
               '<div class="ch-ajuda">Letras, números e _ apenas. Usável depois como {{' + esc(v || 'campo') + '}}.</div></div>';

      case 'tempo':
        var t = v || {};
        return '<div class="ch-campo">' + lbl +
               '<div class="ch-grid-3">' +
                 numTempo(k, 'dias', 'Dias', t.dias) +
                 numTempo(k, 'horas', 'Horas', t.horas) +
                 numTempo(k, 'minutos', 'Min', t.minutos) +
               '</div>' + ajuda + '</div>';

      case 'tempo_raiz':
        var tr = cfg || {};
        return '<div class="ch-campo">' + lbl +
               '<div class="ch-grid-3">' +
                 numTempo('', 'dias', 'Dias', tr.dias) +
                 numTempo('', 'horas', 'Horas', tr.horas) +
                 numTempo('', 'minutos', 'Min', tr.minutos) +
               '</div></div>';

      case 'dias':
        var sel = Array.isArray(v) ? v : [1,2,3,4,5];
        var nomes = ['Seg','Ter','Qua','Qui','Sex','Sáb','Dom'];
        var chips = nomes.map(function (n, i) {
          var d = i + 1;
          return '<button type="button" class="ch-pill ch-fx-dia' + (sel.indexOf(d) >= 0 ? ' ativa' : '') +
                 '" data-k="' + k + '" data-dia="' + d + '">' + n + '</button>';
        }).join('');
        return '<div class="ch-campo">' + lbl + '<div class="ch-lista-filtros">' + chips + '</div></div>';

      case 'botoes':
        var bts = Array.isArray(v) ? v : [];
        if (!bts.length) bts = [{ titulo: '' }];
        return '<div class="ch-campo">' + lbl +
               '<div id="ch-fx-botoes">' + bts.map(function (b, i) { return linhaBotao(b.titulo, i); }).join('') + '</div>' +
               (bts.length < 3 ? '<button type="button" class="ch-btn ch-btn--sm" id="ch-fx-add-botao">+ botão</button>' : '') +
               '<div class="ch-ajuda">Limite da Meta: 3 botões, 20 caracteres cada.</div></div>';

      case 'secoes':
        var linhas = [];
        (Array.isArray(v) ? v : []).forEach(function (s) {
          (s.linhas || []).forEach(function (l) { linhas.push(l); });
        });
        if (!linhas.length) linhas = [{ titulo: '', descricao: '' }];
        return '<div class="ch-campo">' + lbl +
               '<div id="ch-fx-opcoes">' + linhas.map(function (l, i) { return linhaOpcao(l, i); }).join('') + '</div>' +
               (linhas.length < 10 ? '<button type="button" class="ch-btn ch-btn--sm" id="ch-fx-add-opcao">+ opção</button>' : '') +
               '<div class="ch-ajuda">Limite da Meta: 10 opções, título de 24 caracteres.</div></div>';

      case 'params':
        var ps = Array.isArray(v) ? v : [];
        return '<div class="ch-campo">' + lbl +
               '<textarea class="ch-textarea ch-fx-c" data-k="' + k + '" data-lista="1" rows="3">' +
               esc(ps.join('\n')) + '</textarea>' +
               '<div class="ch-ajuda">Uma por linha, na ordem {{1}}, {{2}}… Aceita {{primeiro_nome}}.</div></div>';

      default: // text
        return '<div class="ch-campo">' + lbl +
               '<input type="text" class="ch-input ch-fx-c" data-k="' + k + '" value="' + esc(v || '') + '">' +
               ajuda + '</div>';
    }
  }

  function numTempo(k, parte, rot, val) {
    return '<div><label class="ch-label" style="font-size:11px;">' + rot + '</label>' +
           '<input type="number" min="0" class="ch-input ch-fx-tempo" data-k="' + k + '" data-parte="' + parte + '" value="' +
           (val || 0) + '"></div>';
  }
  function linhaBotao(titulo, i) {
    return '<div class="ch-fx-lista-item">' +
           '<input type="text" class="ch-input ch-fx-botao" maxlength="20" placeholder="Texto do botão" value="' + esc(titulo || '') + '">' +
           '<button type="button" class="ch-fx-lista-rm" data-rm="botao">×</button></div>';
  }
  function linhaOpcao(l, i) {
    return '<div class="ch-fx-lista-item" style="flex-wrap:wrap;">' +
           '<input type="text" class="ch-input ch-fx-op-titulo" maxlength="24" placeholder="Título" value="' + esc(l.titulo || '') + '">' +
           '<button type="button" class="ch-fx-lista-rm" data-rm="opcao">×</button>' +
           '<input type="text" class="ch-input ch-fx-op-desc" maxlength="72" placeholder="Descrição (opcional)" ' +
           'style="flex-basis:100%;margin-top:4px;" value="' + esc(l.descricao || '') + '"></div>';
  }

  // ── Coleta do painel para o nó ──────────────────────────────────────────
  function salvarPainel() {
    if (noSelecionado === null) return;
    var no = editor.getNodeFromId(noSelecionado);
    if (!no || !no.data) return;

    var cfg = $.extend({}, no.data.config || {});

    $('#ch-fx-p-campos .ch-fx-c').each(function () {
      var $e = $(this), k = $e.data('k'), val;

      if ($e.is(':checkbox'))       val = $e.is(':checked');
      else if ($e.data('lista'))    val = ($e.val() || '').split('\n').map(function (x) { return x.trim(); }).filter(Boolean);
      else if ($e.data('num'))      val = parseInt($e.val(), 10) || 0;
      else if ($e.attr('type') === 'number') val = $e.val() === '' ? null : Number($e.val());
      else                          val = $e.val();

      if (k === '') { /* campos de raiz são tratados abaixo */ }
      else cfg[k] = val;
    });

    // Tempo: agrupa dias/horas/minutos
    var temposPorChave = {};
    $('#ch-fx-p-campos .ch-fx-tempo').each(function () {
      var k = String($(this).data('k') || '');
      var parte = $(this).data('parte');
      var v = parseInt($(this).val(), 10) || 0;
      temposPorChave[k] = temposPorChave[k] || {};
      temposPorChave[k][parte] = v;
    });
    Object.keys(temposPorChave).forEach(function (k) {
      if (k === '') $.extend(cfg, temposPorChave[k]);   // nó "esperar": vai na raiz
      else cfg[k] = temposPorChave[k];
    });

    // Dias da semana
    var $dias = $('#ch-fx-p-campos .ch-fx-dia');
    if ($dias.length) {
      var kd = $dias.first().data('k');
      cfg[kd] = $dias.filter('.ativa').map(function () { return parseInt($(this).data('dia'), 10); }).get();
    }

    // Botões
    var $bts = $('#ch-fx-botoes .ch-fx-botao');
    if ($bts.length) {
      cfg.botoes = $bts.map(function () { return { titulo: ($(this).val() || '').trim() }; }).get()
                        .filter(function (b) { return b.titulo !== ''; });
    }

    // Opções da lista (uma seção só — mais que isso confunde mais que ajuda)
    var $ops = $('#ch-fx-opcoes .ch-fx-lista-item');
    if ($ops.length) {
      var linhas = $ops.map(function () {
        return {
          titulo: ($(this).find('.ch-fx-op-titulo').val() || '').trim(),
          descricao: ($(this).find('.ch-fx-op-desc').val() || '').trim()
        };
      }).get().filter(function (l) { return l.titulo !== ''; });
      cfg.secoes = linhas.length ? [{ titulo: 'Opções', linhas: linhas }] : [];
    }

    no.data.config = cfg;
    editor.updateNodeDataFromId(noSelecionado, no.data);

    // Redesenha o corpo do nó para o resumo refletir a mudança
    var $corpo = $('#node-' + noSelecionado + ' .ch-fx-no-corpo');
    var meta = UI[no.data.tipo];
    var resumo = '';
    try { resumo = meta && meta.resumo ? String(meta.resumo(cfg) || '') : ''; } catch (e) {}
    $corpo.html(resumo ? esc(resumo.substring(0, 90)) : '<span class="ch-fx-no-vazio">clique para configurar</span>');
  }

  $(document).on('change blur', '#ch-fx-p-campos .ch-fx-c, #ch-fx-p-campos .ch-fx-tempo, ' +
                 '#ch-fx-botoes input, #ch-fx-opcoes input', salvarPainel);

  $(document).on('click', '.ch-fx-dia', function () { $(this).toggleClass('ativa'); salvarPainel(); });

  $(document).on('click', '#ch-fx-add-botao', function () {
    if ($('#ch-fx-botoes .ch-fx-lista-item').length >= 3) return;
    $('#ch-fx-botoes').append(linhaBotao('', 0));
    if ($('#ch-fx-botoes .ch-fx-lista-item').length >= 3) $(this).remove();
  });
  $(document).on('click', '#ch-fx-add-opcao', function () {
    if ($('#ch-fx-opcoes .ch-fx-lista-item').length >= 10) return;
    $('#ch-fx-opcoes').append(linhaOpcao({}, 0));
    if ($('#ch-fx-opcoes .ch-fx-lista-item').length >= 10) $(this).remove();
  });
  $(document).on('click', '.ch-fx-lista-rm', function () {
    $(this).closest('.ch-fx-lista-item').remove();
    salvarPainel();
  });

  $('#ch-fx-excluir-no').on('click', function () {
    if (noSelecionado === null) return;
    if (!confirm('Excluir este bloco e todas as ligações dele?')) return;
    editor.removeNodeId('node-' + noSelecionado);
  });

  // ── Zoom ────────────────────────────────────────────────────────────────
  $('#ch-fx-zoom-in').on('click',    function () { editor.zoom_in(); });
  $('#ch-fx-zoom-out').on('click',   function () { editor.zoom_out(); });
  $('#ch-fx-zoom-reset').on('click', function () { editor.zoom_reset(); });

  // ── Regras ──────────────────────────────────────────────────────────────
  $('#ch-fx-cfg').on('click', function () { $('#ch-fx-cfg-box').toggle(); });

  // ── Salvar / publicar ───────────────────────────────────────────────────
  function grafoAtual() {
    salvarPainel();
    return Conv.paraBackend(editor.export());
  }

  function msg(html, tipo) {
    $('#ch-fx-msg').html(
      '<div class="ch-aviso ch-aviso--' + (tipo || 'info') + '"><div>' + html + '</div></div>'
    );
    if (tipo === 'ok') setTimeout(function () { $('#ch-fx-msg').empty(); }, 3500);
  }

  function listaErros(erros) {
    return '<strong>Corrija antes de publicar</strong><ul>' +
           erros.map(function (e) { return '<li>' + esc(e) + '</li>'; }).join('') + '</ul>';
  }

  $('#ch-fx-salvar').on('click', function () {
    var $b = $(this).prop('disabled', true).text('Salvando...');
    $.post(BASE + '/admin/chat/fluxos/' + CFG.fluxoId + '/salvar', {
      csrf_token: CSRF,
      grafo_json: JSON.stringify(grafoAtual()),
      nome: $('#ch-fx-nome').val(),
      config_json: JSON.stringify({ reentrada: $('#ch-fx-reentrada').val() })
    }, function (r) {
      if (r.ok) {
        msg(r.erros && r.erros.length
              ? '<strong>Rascunho salvo</strong> Ainda há pendências: ' + esc(r.erros.join(' · '))
              : '<strong>Rascunho salvo.</strong>',
            r.erros && r.erros.length ? 'aviso' : 'ok');
      } else {
        msg(listaErros(r.erros || ['Falha ao salvar.']), 'erro');
      }
    }, 'json').fail(function () {
      msg('Erro de rede ao salvar.', 'erro');
    }).always(function () {
      $b.prop('disabled', false).text('Salvar rascunho');
    });
  });

  $('#ch-fx-publicar').on('click', function () {
    var $b = $(this).prop('disabled', true).text('Publicando...');

    // Publicar sempre salva antes: publicar um rascunho desatualizado é a
    // pegadinha clássica desse tipo de editor.
    $.post(BASE + '/admin/chat/fluxos/' + CFG.fluxoId + '/salvar', {
      csrf_token: CSRF,
      grafo_json: JSON.stringify(grafoAtual()),
      nome: $('#ch-fx-nome').val(),
      config_json: JSON.stringify({ reentrada: $('#ch-fx-reentrada').val() })
    }, function (rs) {
      if (!rs.ok) {
        msg(listaErros(rs.erros || []), 'erro');
        $b.prop('disabled', false).text('Publicar');
        return;
      }
      $.post(BASE + '/admin/chat/fluxos/' + CFG.fluxoId + '/publicar', { csrf_token: CSRF }, function (r) {
        if (r.ok) {
          msg('<strong>Publicado como v' + r.versao + '.</strong> O fluxo já está no ar.', 'ok');
          setTimeout(function () { location.reload(); }, 1200);
        } else {
          msg(listaErros(r.erros || []), 'erro');
          $b.prop('disabled', false).text('Publicar');
        }
      }, 'json').fail(function () {
        msg('Erro de rede ao publicar.', 'erro');
        $b.prop('disabled', false).text('Publicar');
      });
    }, 'json').fail(function () {
      msg('Erro de rede.', 'erro');
      $b.prop('disabled', false).text('Publicar');
    });
  });

  $('.ch-fx-status').on('click', function () {
    var st = $(this).data('status');
    $.post(BASE + '/admin/chat/fluxos/' + CFG.fluxoId + '/status',
      { csrf_token: CSRF, status: st },
      function (r) {
        if (r.ok) location.reload();
        else msg(esc(r.erro || 'Falha.'), 'erro');
      }, 'json');
  });

  // Delete apaga o nó selecionado — mas não enquanto se digita num campo
  $(document).on('keydown', function (e) {
    if (e.key !== 'Delete' && e.key !== 'Backspace') return;
    if ($(e.target).is('input, textarea, select')) return;
    if (noSelecionado === null) return;
    if (e.key === 'Backspace') return;   // Backspace fora de campo é "voltar" no browser
    editor.removeNodeId('node-' + noSelecionado);
  });

  // ── Estatísticas por nó ─────────────────────────────────────────────────
  function carregarStats() {
    if (!CFG.publicado) return;
    $.get(BASE + '/admin/chat/fluxos/' + CFG.fluxoId + '/stats', function (r) {
      if (!r || !r.ok || !r.nos) return;
      Object.keys(r.nos).forEach(function (chave) {
        var d = r.nos[chave];
        var $s = $('[data-stat="' + chave + '"]');
        if (!$s.length) return;

        var partes = ['▸ ' + d.total];
        Object.keys(d.portas || {}).forEach(function (p) {
          if (p.indexOf('__') === 0) return;
          partes.push(rotuloPorta(p) + ': ' + d.portas[p]);
        });
        $s.text(partes.join(' · ')).show();
      });
    }, 'json');
  }

  // ── Carga inicial ───────────────────────────────────────────────────────
  montarPaleta();

  (function carregarGrafo() {
    var g = CFG.grafo || { nos: [], conexoes: [] };

    (g.nos || []).forEach(function (n) {
      var pos = n.pos || [0, 0];
      var cfgNo = n.config;
      // PHP serializa objeto vazio como [] — normaliza para {}
      if (Array.isArray(cfgNo)) cfgNo = {};
      adicionarNo(n.tipo, pos[0], pos[1], n.chave, cfgNo || {});
    });

    // Conexões só depois de todos os nós existirem
    setTimeout(function () {
      (g.conexoes || []).forEach(function (c) {
        var idOrigem = mapaChaveParaDf[c.de];
        var idDest   = mapaChaveParaDf[c.para];
        if (!idOrigem || !idDest) return;

        var noOrigem = editor.getNodeFromId(idOrigem);
        var portas = portasDe(noOrigem.data.tipo);
        var idx = portas.indexOf(c.porta);
        if (idx < 0) return;

        try {
          editor.addConnection(idOrigem, idDest, 'output_' + (idx + 1), 'input_1');
        } catch (e) { /* conexão inválida no dado antigo — ignora */ }
      });

      carregarStats();
    }, 120);
  })();

})(jQuery);
