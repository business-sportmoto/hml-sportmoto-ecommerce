/**
 * admin/assets/js/chat.js
 *
 * Comportamento do inbox (live chat).
 *
 * Só roda se #ch-inbox existir — o mesmo arquivo é carregado em todas as telas
 * do módulo, como já acontece com logistica.js.
 *
 * POLLING EM DUAS VELOCIDADES (mesmo padrão do sino de notificações):
 *   · lista de conversas ....... 12s
 *   · thread aberta ............ 5s
 *   · aba em segundo plano ..... pausado
 *   · volta para a aba ......... atualiza na hora
 * Pausar em background é o que impede uma aba esquecida de bater no servidor
 * a noite inteira.
 *
 * TODO texto vindo do servidor é inserido com .text() ou via esc(). Nunca
 * concatenar conteúdo de contato em HTML sem escapar: o nome do perfil do
 * WhatsApp é digitado pelo próprio cliente.
 */
(function ($) {
  'use strict';

  var $inbox = $('#ch-inbox');
  if (!$inbox.length) return;

  var CFG = window.CH || {};
  var BASE = CFG.base || window.BASE_URL || '';
  var CSRF = CFG.csrf || window.CSRF_TOKEN || '';

  var estado = {
    conversaId: 0,
    ultimoMsgId: 0,
    ultimoTs: null,
    filtros: { status: '', agente: '', nao_lidas: '', canal: '', q: '' },
    carregandoThread: false,
    contato: null,
    conversa: null,
    temMais: false
  };

  var timerLista = null;
  var timerThread = null;

  // ── Utilidades ────────────────────────────────────────────────────────────
  function esc(s) {
    return $('<i>').text(s == null ? '' : String(s)).html();
  }

  function iniciais(nome) {
    var n = (nome || '?').trim();
    return n.charAt(0).toUpperCase() || '?';
  }

  function post(url, dados) {
    return $.post(BASE + url, $.extend({ csrf_token: CSRF }, dados || {}), null, 'json');
  }

  function toast(msg, tipo) {
    if (window.adminToast) { window.adminToast(msg, tipo || 'info'); return; }
    if (window.Toast && window.Toast.show) { window.Toast.show(msg, tipo || 'info'); return; }
    if (tipo === 'erro') alert(msg);
  }

  // ── Lista de conversas ────────────────────────────────────────────────────
  function carregarLista() {
    var p = {
      status: estado.filtros.status,
      agente: estado.filtros.agente,
      nao_lidas: estado.filtros.nao_lidas,
      canal: estado.filtros.canal,
      q: estado.filtros.q
    };

    $.get(BASE + '/admin/chat/inbox/conversas', p, function (r) {
      if (!r || !r.ok) return;
      renderLista(r.itens || []);
      atualizarContadores(r.contadores || {}, r.total || 0);
    }, 'json');
  }

  function atualizarContadores(c, total) {
    $('[data-cont=total]').text(total || '');
    $('[data-cont=nao_lidas]').text(c.nao_lidas || 0);
    $('[data-cont=minhas]').text(c.minhas || 0);
    $('[data-cont=sem_agente]').text(c.sem_agente || 0);
  }

  function renderLista(itens) {
    var $l = $('#ch-lista');

    if (!itens.length) {
      $l.html('<div class="ch-vazio" style="padding:30px 16px;">' +
              '<strong>Nenhuma conversa</strong>Ajuste os filtros ou aguarde uma mensagem.</div>');
      return;
    }

    var html = itens.map(function (c) {
      var tags = (c.tags || []).slice(0, 3).map(function (t) {
        return '<span class="ch-tag" style="color:' + esc(t.cor) + ';background:' +
               esc(t.cor) + '22;">' + esc(t.nome) + '</span>';
      }).join('');

      var seta = c.direcao === 'saida'
        ? '<span class="ch-mut" style="flex:none;">↗</span>' : '';

      // Marca do canal no avatar: a caixa é unificada, mas quem atende
      // precisa saber onde está falando antes de escrever
      var ehIg = c.canal === 'instagram';
      var marca = '<span class="ch-conv-canal" title="' + esc(c.canal_rotulo || '') + '" ' +
                  'style="background:' + (ehIg ? '#e1306c' : '#25d366') + '">' +
                  (ehIg ? 'ig' : 'wa') + '</span>';

      return '' +
        '<div class="ch-conv' + (c.id === estado.conversaId ? ' ativa' : '') + '" data-id="' + c.id + '">' +
          '<div class="ch-avatar-wrap">' +
            '<div class="ch-avatar">' + esc(iniciais(c.nome)) + '</div>' + marca +
          '</div>' +
          '<div class="ch-conv-corpo">' +
            '<div class="ch-conv-topo">' +
              '<span class="ch-conv-nome">' + esc(c.nome) + '</span>' +
              '<span class="ch-conv-hora">' + esc(c.quando) + '</span>' +
            '</div>' +
            '<div class="ch-conv-prev">' + seta + esc(c.preview) + '</div>' +
            '<div class="ch-conv-meta">' +
              '<span class="ch-ponto-janela ch-ponto-janela--' + (c.na_janela ? 'aberta' : 'fechada') + '" ' +
                'title="' + (c.na_janela ? 'Janela aberta: ' + esc(c.janela_restante || '') : 'Janela fechada — só template') + '"></span>' +
              tags +
              (c.agente ? '<span class="ch-tag ch-badge--neutro">' + esc(c.agente) + '</span>' : '') +
              (!c.bot_ativo ? '<span class="ch-tag ch-badge--aviso">bot pausado</span>' : '') +
              (c.nao_lidas > 0 ? '<span class="ch-naolidas" style="margin-left:auto;">' + c.nao_lidas + '</span>' : '') +
            '</div>' +
          '</div>' +
        '</div>';
    }).join('');

    $l.html(html);
  }

  $(document).on('click', '.ch-conv', function () {
    abrirConversa(parseInt($(this).data('id'), 10));
  });

  // ── Thread ────────────────────────────────────────────────────────────────
  function abrirConversa(id) {
    if (!id || estado.carregandoThread) return;

    estado.conversaId = id;
    estado.ultimoMsgId = 0;
    estado.carregandoThread = true;

    $('.ch-conv').removeClass('ativa');
    $('.ch-conv[data-id=' + id + ']').addClass('ativa').find('.ch-naolidas').remove();

    $('#ch-sem-conversa').hide();
    $('#ch-thread').css('display', 'flex');
    $('#ch-msgs').html('<div class="ch-carregando">Carregando mensagens...</div>');
    $inbox.addClass('ver-thread');

    $.get(BASE + '/admin/chat/inbox/' + id + '/thread', function (r) {
      estado.carregandoThread = false;
      if (!r || !r.ok) { toast('Não foi possível abrir a conversa.', 'erro'); return; }

      estado.conversa = r.conversa;
      estado.contato = r.contato;
      estado.temMais = !!r.tem_mais;

      renderCabecalho(r.conversa);
      renderMensagens(r.mensagens || [], true);
      renderPainel(r.contato, r.notas || []);
      ajustarCompositor(r.conversa);

      reiniciarPollThread();
    }, 'json').fail(function () {
      estado.carregandoThread = false;
      $('#ch-msgs').html('<div class="ch-vazio">Erro ao carregar.</div>');
    });
  }

  function renderCabecalho(c) {
    $('#ch-t-avatar').text(iniciais(c.nome));
    $('#ch-t-nome').text(c.nome);

    var sub = [];
    sub.push('<span style="color:' + (c.canal === 'instagram' ? '#e1306c' : '#25d366') + ';font-weight:700;">' +
             esc(c.canal_rotulo || '') + '</span>');
    sub.push(esc(c.telefone));

    if (c.na_janela) {
      sub.push('<span style="color:var(--success)">● janela aberta' +
               (c.janela_restante ? ' · ' + esc(c.janela_restante) : '') + '</span>');
    } else if (c.janela_humana) {
      // Instagram fora das 24h mas dentro dos 7 dias da tag humana
      sub.push('<span style="color:var(--warning)">● atendimento humano (7 dias)</span>');
    } else {
      sub.push('<span style="color:var(--text-3)">● janela fechada</span>');
    }

    if (c.cliente_id) sub.push('<span style="color:var(--blue)">cliente #' + c.cliente_id + '</span>');
    $('#ch-t-sub').html(sub.join(' · '));

    $('#ch-t-agente').val(c.agente_id || 0);
    $('#ch-t-bot')
      .text(c.bot_ativo ? 'Pausar bot' : 'Retomar bot')
      .toggleClass('ch-btn--pri', !c.bot_ativo);
    $('#ch-t-resolver').text(c.status === 'resolvida' ? 'Reabrir' : 'Resolver');
  }

  function renderMensagens(msgs, limpar) {
    var $m = $('#ch-msgs');
    if (limpar) $m.empty();

    if (!msgs.length && limpar) {
      $m.html('<div class="ch-vazio">Nenhuma mensagem ainda.</div>');
      return;
    }

    var ultimoDia = $m.data('ultimoDia') || '';
    var html = '';

    msgs.forEach(function (m) {
      if (m.dia !== ultimoDia) {
        html += '<div class="ch-dia">' + esc(rotuloDia(m.dia)) + '</div>';
        ultimoDia = m.dia;
      }
      html += bolha(m);
      if (m.id > estado.ultimoMsgId) estado.ultimoMsgId = m.id;
    });

    $m.data('ultimoDia', ultimoDia);
    if (limpar) $m.html(html); else $m.append(html);
    rolarFim();
  }

  function rotuloDia(dia) {
    var hoje = new Date(), d = new Date(dia + 'T00:00:00');
    var difDias = Math.round((hoje.setHours(0,0,0,0) - d.getTime()) / 86400000);
    if (difDias === 0) return 'Hoje';
    if (difDias === 1) return 'Ontem';
    return d.toLocaleDateString('pt-BR');
  }

  function bolha(m) {
    var out = m.direcao === 'saida';
    var falhou = m.status === 'falhou';
    var corpo = '';

    // Mídia
    if (m.midia_url) {
      var u = esc(m.midia_url);
      var nome = m.midia_nome ? esc(m.midia_nome) : '';

      if (m.tipo === 'image' || m.tipo === 'sticker') {
        // data-lightbox: o plugin do painel escuta no document, então imagem
        // inserida depois pelo polling também abre ampliada.
        corpo += '<img src="' + u + '" alt="' + nome + '" loading="lazy"' +
                 ' data-lightbox="ch-thread" data-lightbox-src="' + u + '"' +
                 (nome ? ' data-lightbox-caption="' + nome + '"' : '') + '>';
      } else if (m.tipo === 'video') {
        corpo += '<video src="' + u + '" controls preload="metadata"></video>';
        if (nome) corpo += '<div class="ch-bolha-arq">' + nome + '</div>';
      } else if (m.tipo === 'audio') {
        // Áudio de música tem nome; áudio de voz não — só mostra quando existe
        if (nome) corpo += '<div class="ch-bolha-arq">🎵 ' + nome + '</div>';
        corpo += '<audio src="' + u + '" controls preload="none"></audio>';
      } else {
        corpo += '<a class="ch-bolha-doc" href="' + u + '" target="_blank" rel="noopener" download>' +
                 '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' +
                 '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6"/></svg>' +
                 '<span class="ch-bolha-doc-id">' +
                   '<span class="ch-bolha-doc-nome">' + (nome || 'Documento') + '</span>' +
                   (m.midia_tamanho ? '<span class="ch-bolha-doc-tam">' + tamanho(m.midia_tamanho) + '</span>' : '') +
                 '</span></a>';
      }
    } else if (m.tipo === 'image' || m.tipo === 'video' || m.tipo === 'audio' || m.tipo === 'document') {
      // Mídia recebida mas não baixada (config desligada ou download falhou)
      corpo += '<div class="ch-bolha-doc"><span class="ch-mut">Anexo não disponível</span></div>';
    }

    if (m.texto) corpo += '<div>' + esc(m.texto).replace(/\n/g, '<br>') + '</div>';

    // Botões / lista que a loja enviou
    if (m.payload && (m.payload.botoes || m.payload.secoes)) {
      var itens = [];
      (m.payload.botoes || []).forEach(function (b) { itens.push(b.titulo); });
      (m.payload.secoes || []).forEach(function (s) {
        (s.linhas || []).forEach(function (l) { itens.push(l.titulo); });
      });
      if (itens.length) {
        corpo += '<div class="ch-bolha-botoes">' + itens.map(function (t) {
          return '<span class="ch-bolha-botao">' + esc(t) + '</span>';
        }).join('') + '</div>';
      }
    }

    if (!corpo) corpo = '<span class="ch-mut">(' + esc(m.tipo) + ')</span>';

    // Rodapé: autor, hora, tiques
    var pe = '';
    if (out) {
      var tique = '';
      if (m.status === 'lido')     tique = '<span class="ch-tique--lido">✓✓</span>';
      else if (m.status === 'entregue') tique = '✓✓';
      else if (m.status === 'enviado')  tique = '✓';
      else if (falhou)             tique = '<span style="color:var(--danger)">!</span>';
      pe = tique;
    }

    // Mensagem assinada já leva o nome no corpo — repetir aqui só polui
    var assinada = !!(m.payload && m.payload.assinatura);

    var autor = '';
    if (out && m.autor && !assinada) {
      autor = '<div class="ch-bolha-autor">' + esc(m.autor) + '</div>';
    } else if (out && m.origem && m.origem !== 'inbox') {
      autor = '<div class="ch-bolha-autor">' + esc(rotuloOrigem(m.origem)) + '</div>';
    }

    var erro = falhou && m.erro
      ? '<div class="ch-sm" style="color:var(--danger);margin-top:4px;">' + esc(m.erro) + '</div>' : '';

    return '<div class="ch-msg ch-msg--' + (out ? 'out' : 'in') + (falhou ? ' ch-msg--erro' : '') + '" data-id="' + m.id + '">' +
             '<div class="ch-bolha">' + autor + corpo + erro +
               '<div class="ch-bolha-pe">' + esc(m.hora) + ' ' + pe + '</div>' +
             '</div>' +
           '</div>';
  }

  function rotuloOrigem(o) {
    return { fluxo: 'Automação', campanha: 'Campanha', gatilho: 'Gatilho', sistema: 'Sistema' }[o] || o;
  }

  function rolarFim() {
    var el = document.getElementById('ch-msgs');
    if (el) el.scrollTop = el.scrollHeight;
  }

  // ── Painel do contato ─────────────────────────────────────────────────────
  function renderPainel(c, notas) {
    if (!c) { $('#ch-painel').html('<div class="ch-vazio">Sem dados.</div>'); return; }

    var tags = (c.tags || []).map(function (t) {
      return '<span class="ch-tag" style="color:' + esc(t.cor) + ';background:' + esc(t.cor) + '22;">' +
             esc(t.nome) + '</span>';
    }).join('') || '<span class="ch-sm ch-mut">Sem tags</span>';

    var campos = '';
    var chaves = Object.keys(c.campos || {});
    if (chaves.length) {
      campos = chaves.map(function (k) {
        return '<div class="ch-dado"><dt>' + esc(k) + '</dt><dd>' + esc(c.campos[k]) + '</dd></div>';
      }).join('');
    } else {
      campos = '<span class="ch-sm ch-mut">Nenhum campo preenchido</span>';
    }

    var listaNotas = (notas || []).map(function (n) {
      return '<div class="ch-nota">' + esc(n.nota).replace(/\n/g, '<br>') +
             '<div class="ch-nota-meta">' + esc(n.autor || 'Sistema') + ' · ' +
             esc(new Date(n.criado_em.replace(' ', 'T')).toLocaleString('pt-BR')) + '</div></div>';
    }).join('') || '<span class="ch-sm ch-mut">Nenhuma nota</span>';

    $('#ch-painel').html('' +
      '<div class="ch-painel-topo">' +
        '<div class="ch-avatar">' + esc(iniciais(c.nome)) + '</div>' +
        '<div class="ch-painel-nome">' + esc(c.nome) + '</div>' +
        '<div class="ch-painel-tel">' + esc(c.telefone || c.wa_id) + '</div>' +
        '<div style="margin-top:10px;display:flex;gap:6px;justify-content:center;flex-wrap:wrap;">' +
          '<a class="ch-btn ch-btn--sm" href="' + BASE + '/admin/chat/contatos/' + c.id + '">Ficha completa</a>' +
          (c.cliente_id ? '<a class="ch-btn ch-btn--sm" href="' + BASE + '/admin/clientes/' + c.cliente_id + '">Cliente</a>' : '') +
        '</div>' +
      '</div>' +

      '<div class="ch-painel-sec">' +
        '<div class="ch-painel-tit">Situação</div>' +
        '<div class="ch-dado"><dt>Janela 24h</dt><dd>' +
          (c.na_janela ? '<span style="color:var(--success)">aberta</span>' : '<span class="ch-mut">fechada</span>') + '</dd></div>' +
        '<div class="ch-dado"><dt>Recebe mensagens</dt><dd>' +
          (c.optin ? 'sim' : '<span style="color:var(--danger)">opt-out</span>') + '</dd></div>' +
        (c.bloqueado ? '<div class="ch-dado"><dt>Bloqueado</dt><dd style="color:var(--danger)">sim</dd></div>' : '') +
        '<div class="ch-dado"><dt>Recebidas</dt><dd>' + (c.total_entrada || 0) + '</dd></div>' +
        '<div class="ch-dado"><dt>Enviadas</dt><dd>' + (c.total_saida || 0) + '</dd></div>' +
      '</div>' +

      '<div class="ch-painel-sec">' +
        '<div class="ch-painel-tit">Tags</div>' +
        '<div class="ch-tags-linha">' + tags + '</div>' +
      '</div>' +

      '<div class="ch-painel-sec">' +
        '<div class="ch-painel-tit">Campos</div>' + campos +
      '</div>' +

      '<div class="ch-painel-sec">' +
        '<div class="ch-painel-tit">Notas internas</div>' +
        '<textarea class="ch-textarea" id="ch-nota-txt" rows="2" placeholder="Anotação visível só para a equipe"></textarea>' +
        '<button type="button" class="ch-btn ch-btn--sm" id="ch-nota-add" style="margin:6px 0 12px;">Adicionar nota</button>' +
        '<div id="ch-notas">' + listaNotas + '</div>' +
      '</div>'
    );
  }

  // ── Compositor: o que é permitido depende do canal ───────────────────────
  function ajustarCompositor(c) {
    var ehIg = c.canal === 'instagram';

    // `pode_texto` já embute a regra de cada canal: 24h no WhatsApp,
    // 24h + 7 dias da tag humana no Instagram.
    var podeTexto = (c.pode_texto !== undefined ? c.pode_texto : c.na_janela) && CFG.envioOk;

    $('#ch-comp-livre').toggle(!!podeTexto);
    $('#ch-comp-fechado').toggle(!podeTexto);

    if (!podeTexto) {
      if (!CFG.envioOk) {
        $('#ch-comp-fechado').html(
          '<div class="ch-comp-bloqueado"><strong>Envio indisponível</strong>' +
          'O canal não está configurado. Veja a tela de configuração.</div>'
        );
      } else if (ehIg) {
        // No Instagram não existe template: passados os 7 dias, acabou
        $('#ch-comp-fechado').html(
          '<div class="ch-comp-bloqueado"><strong>Janela do Instagram encerrada</strong>' +
          'Passaram-se mais de 7 dias desde a última mensagem desta pessoa. ' +
          'O Instagram não tem template para reabrir conversa — é preciso que ela escreva de novo.</div>'
        );
      } else {
        $('#ch-comp-fechado').html(
          '<div class="ch-comp-bloqueado">' +
          '<strong>Janela de 24 horas fechada</strong>' +
          'Esta pessoa não escreve para a loja há mais de 24h. A Meta só permite ' +
          'retomar o contato com um <strong>template aprovado</strong>.' +
          '<div style="margin-top:9px;">' +
          '<button type="button" class="ch-btn ch-btn--pri ch-btn--sm" id="ch-abrir-template">' +
          'Enviar template</button></div></div>'
        );
      }
    }

    var dica = c.bot_ativo
      ? 'Ao responder, a automação é pausada automaticamente.'
      : 'A automação está pausada nesta conversa.';
    if (ehIg && c.janela_humana && !c.na_janela) {
      dica += ' Fora das 24h — a resposta vai com a tag de atendimento humano.';
    }
    if (CH.assinatura) {
      // O IG não interpreta markdown; o servidor já tira os asteriscos de lá
      var pre = ehIg ? CH.assinatura.replace(/[*_~]/g, '') : CH.assinatura;
      dica += ' Suas mensagens saem assinadas como “' + pre + '”.';
    }
    $('#ch-comp-dica').text(dica);
  }

  // ── Anexo ─────────────────────────────────────────────────────────────────
  var anexo = null;   // File escolhido, ainda não enviado

  function tamanho(bytes) {
    bytes = Number(bytes) || 0;
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes / 1024).toFixed(0) + ' KB';
    return (bytes / 1048576).toFixed(1).replace('.', ',') + ' MB';
  }

  function iconeDe(mime) {
    if (mime.indexOf('audio/') === 0) return '🎵';
    if (mime.indexOf('video/') === 0) return '🎬';
    if (mime.indexOf('image/') === 0) return '🖼️';
    return '📄';
  }

  function mostrarAnexo(f) {
    anexo = f;
    var $m = $('#ch-anexo-mini').empty();

    // Miniatura de verdade para imagem; ícone para o resto
    if (f.type.indexOf('image/') === 0) {
      var url = URL.createObjectURL(f);
      $m.append($('<img>').attr('src', url).addClass('ch-comp-anexo-mini')
                          .on('load', function () { URL.revokeObjectURL(url); }));
    } else {
      $m.text(iconeDe(f.type));
    }

    $('#ch-anexo-nome').text(f.name);
    $('#ch-anexo-meta').text(tamanho(f.size));
    $('#ch-comp-anexo').addClass('ativo');
    $('#ch-texto').attr('placeholder', 'Legenda (opcional) — Enter envia').focus();
  }

  function limparAnexo() {
    anexo = null;
    $('#ch-comp-anexo').removeClass('ativo');
    $('#ch-anexo-mini').empty();
    $('#ch-arquivo').val('');
    $('#ch-texto').attr('placeholder', 'Escreva uma mensagem... (Enter envia, Shift+Enter quebra linha)');
  }

  $('#ch-anexar').on('click', function () { $('#ch-arquivo').click(); });
  $('#ch-anexo-x').on('click', limparAnexo);

  $('#ch-arquivo').on('change', function () {
    var f = this.files && this.files[0];
    if (!f || !estado.conversaId) return;
    mostrarAnexo(f);
  });

  function enviarAnexo() {
    var f = anexo;
    if (!f || !estado.conversaId) return;

    var fd = new FormData();
    fd.append('arquivo', f);
    fd.append('csrf_token', CSRF);
    fd.append('legenda', ($('#ch-texto').val() || '').trim());

    var $t = $('#ch-texto').prop('disabled', true);
    $('#ch-enviar, #ch-anexar').prop('disabled', true);

    $.ajax({
      url: BASE + '/admin/chat/inbox/' + estado.conversaId + '/upload',
      type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json'
    }).done(function (r) {
      if (r.ok) {
        $t.val('').css('height', 'auto');
        limparAnexo();
        if (r.mensagem) renderMensagens([r.mensagem], false);
        carregarLista();
      } else if (r.fora_janela) {
        toast('A janela de 24h fechou. Use um template.', 'erro');
      } else {
        toast(r.erro || 'Falha no envio do arquivo.', 'erro');
      }
    }).fail(function () {
      toast('Erro de rede no upload.', 'erro');
    }).always(function () {
      $t.prop('disabled', false).focus();
      // O botão nunca fica preso desabilitado, dê no que der no upload
      $('#ch-enviar, #ch-anexar').prop('disabled', false);
    });
  }

  // ── Envio ─────────────────────────────────────────────────────────────────
  function enviarTexto() {
    // Com anexo escolhido, o mesmo botão manda o arquivo e usa o texto como
    // legenda — não são dois fluxos de envio para o atendente decorar.
    if (anexo) { enviarAnexo(); return; }

    var $t = $('#ch-texto');
    var texto = ($t.val() || '').trim();
    if (!texto || !estado.conversaId) return;

    $t.prop('disabled', true);
    $('#ch-enviar').prop('disabled', true);

    post('/admin/chat/inbox/' + estado.conversaId + '/enviar', { texto: texto })
      .done(function (r) {
        if (r.ok) {
          $t.val('').css('height', 'auto');
          if (r.mensagem) renderMensagens([r.mensagem], false);
          carregarLista();
        } else if (r.fora_janela) {
          // A janela fechou entre abrir a conversa e apertar enviar
          toast('A janela de 24h fechou. Use um template.', 'erro');
          $('#ch-comp-livre').hide();
          $('#ch-comp-fechado').show();
        } else {
          toast(r.erro || 'Falha ao enviar.', 'erro');
        }
      })
      .fail(function () { toast('Erro de rede ao enviar.', 'erro'); })
      .always(function () {
        $t.prop('disabled', false).focus();
        $('#ch-enviar').prop('disabled', false);
      });
  }

  $('#ch-enviar').on('click', enviarTexto);

  $('#ch-texto').on('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); enviarTexto(); }
  }).on('input', function () {
    this.style.height = 'auto';
    this.style.height = Math.min(this.scrollHeight, 150) + 'px';
  });

  // ── Ações da conversa ─────────────────────────────────────────────────────
  $('#ch-t-resolver').on('click', function () {
    if (!estado.conversaId) return;
    var novo = (estado.conversa && estado.conversa.status === 'resolvida') ? 'aberta' : 'resolvida';
    post('/admin/chat/inbox/' + estado.conversaId + '/status', { status: novo }).done(function (r) {
      if (r.ok) {
        estado.conversa.status = novo;
        $('#ch-t-resolver').text(novo === 'resolvida' ? 'Reabrir' : 'Resolver');
        atualizarContadores(r.contadores || {}, null);
        carregarLista();
      }
    });
  });

  $('#ch-t-agente').on('change', function () {
    if (!estado.conversaId) return;
    post('/admin/chat/inbox/' + estado.conversaId + '/atribuir', { agente_id: $(this).val() })
      .done(function (r) { if (r.ok) { atualizarContadores(r.contadores || {}, null); carregarLista(); } });
  });

  $('#ch-t-bot').on('click', function () {
    if (!estado.conversaId) return;
    var ativo = estado.conversa && estado.conversa.bot_ativo;
    post('/admin/chat/inbox/' + estado.conversaId + '/bot', { acao: ativo ? 'pausar' : 'retomar' })
      .done(function (r) {
        if (!r.ok) return;
        estado.conversa.bot_ativo = r.bot_ativo;
        $('#ch-t-bot').text(r.bot_ativo ? 'Pausar bot' : 'Retomar bot').toggleClass('ch-btn--pri', !r.bot_ativo);
        ajustarCompositor(estado.conversa);
        carregarLista();
      });
  });

  $(document).on('click', '#ch-nota-add', function () {
    var txt = ($('#ch-nota-txt').val() || '').trim();
    if (!txt || !estado.conversaId) return;
    post('/admin/chat/inbox/' + estado.conversaId + '/nota', { nota: txt }).done(function (r) {
      if (r.ok) { $('#ch-nota-txt').val(''); renderPainel(estado.contato, r.notas || []); }
    });
  });

  // ── Modais ────────────────────────────────────────────────────────────────
  function abrirModal(id) { $('#' + id).addClass('aberto'); }
  function fecharModal(id) { $('#' + id).removeClass('aberto'); }

  $(document).on('click', '[data-fechar]', function () { $(this).closest('.ch-modal').removeClass('aberto'); });
  $(document).on('click', '.ch-modal', function (e) { if (e.target === this) $(this).removeClass('aberto'); });
  $(document).on('keydown', function (e) { if (e.key === 'Escape') $('.ch-modal').removeClass('aberto'); });

  // Template — delegado: o botão é redesenhado pelo ajustarCompositor
  $(document).on('click', '#ch-abrir-template', function () { abrirModal('ch-modal-template'); });

  $('#ch-tpl-nome').on('change', function () {
    var $o = $(this).find('option:selected');
    var nVars = parseInt($o.data('vars'), 10) || 0;
    var headerTipo = $o.data('header') || '';
    var varsHeader = parseInt($o.data('varsheader'), 10) || 0;
    var btnUrl = parseInt($o.data('btnurl'), 10) || 0;

    var html = '';
    for (var i = 1; i <= nVars; i++) {
      html += '<div class="ch-campo"><label class="ch-label">Variável {{' + i + '}} do corpo</label>' +
              '<input type="text" class="ch-input ch-tpl-var" data-i="' + i + '" ' +
              'placeholder="texto ou {{primeiro_nome}}"></div>';
    }
    if (headerTipo === 'TEXT' && varsHeader > 0) {
      html += '<div class="ch-campo"><label class="ch-label">Variável do cabeçalho</label>' +
              '<input type="text" class="ch-input" id="ch-tpl-header"></div>';
    }
    if (btnUrl > 0) {
      html += '<div class="ch-campo"><label class="ch-label">Complemento da URL do botão</label>' +
              '<input type="text" class="ch-input" id="ch-tpl-botao"></div>';
    }
    $('#ch-tpl-campos').html(html);
    atualizarPreviewTpl();
  });

  $(document).on('input', '.ch-tpl-var', atualizarPreviewTpl);

  function atualizarPreviewTpl() {
    var corpo = $('#ch-tpl-nome option:selected').data('corpo') || '';
    if (!corpo) { $('#ch-tpl-preview').hide(); return; }

    $('.ch-tpl-var').each(function () {
      var i = $(this).data('i');
      var v = ($(this).val() || '').trim() || '{{' + i + '}}';
      corpo = corpo.split('{{' + i + '}}').join(v);
    });

    $('#ch-tpl-preview-txt').html(esc(corpo).replace(/\n/g, '<br>'));
    $('#ch-tpl-preview').show();
  }

  $('#ch-tpl-enviar').on('click', function () {
    var nome = $('#ch-tpl-nome').val();
    if (!nome || !estado.conversaId) { toast('Selecione um template.', 'erro'); return; }

    var vars = [];
    $('.ch-tpl-var').each(function () { vars.push($(this).val() || ''); });

    var $b = $(this).prop('disabled', true).text('Enviando...');

    post('/admin/chat/inbox/' + estado.conversaId + '/template', {
      template: nome,
      idioma: $('#ch-tpl-nome option:selected').data('idioma') || 'pt_BR',
      vars: vars,
      var_header: $('#ch-tpl-header').val() || '',
      var_botao: $('#ch-tpl-botao').val() || ''
    }).done(function (r) {
      if (r.ok) {
        fecharModal('ch-modal-template');
        if (r.mensagem) renderMensagens([r.mensagem], false);
        carregarLista();
        toast('Template enviado.', 'sucesso');
      } else {
        toast(r.erro || 'Falha ao enviar o template.', 'erro');
      }
    }).fail(function () {
      toast('Erro de rede.', 'erro');
    }).always(function () {
      $b.prop('disabled', false).text('Enviar');
    });
  });

  // Fluxo
  $('#ch-t-fluxo').on('click', function () { abrirModal('ch-modal-fluxo'); });

  $('#ch-fluxo-iniciar').on('click', function () {
    var id = $('#ch-fluxo-id').val();
    if (!id || !estado.conversaId) return;
    var $b = $(this).prop('disabled', true);

    post('/admin/chat/inbox/' + estado.conversaId + '/iniciar-fluxo', { fluxo_id: id })
      .done(function (r) {
        if (r.ok) {
          fecharModal('ch-modal-fluxo');
          toast('Fluxo iniciado.', 'sucesso');
          setTimeout(function () { abrirConversa(estado.conversaId); }, 1200);
        } else {
          toast(r.erro || 'Falha ao iniciar.', 'erro');
        }
      })
      .always(function () { $b.prop('disabled', false); });
  });

  // Novo contato
  $('#ch-novo-contato').on('click', function () { abrirModal('ch-modal-contato'); });

  $('#ch-nc-criar').on('click', function () {
    var tel = ($('#ch-nc-tel').val() || '').trim();
    if (!tel) { $('#ch-nc-msg').text('Informe o telefone.').css('color', 'var(--danger)'); return; }

    var $b = $(this).prop('disabled', true);
    post('/admin/chat/contatos/criar', { telefone: tel, nome: $('#ch-nc-nome').val() || '' })
      .done(function (r) {
        if (r.ok) {
          $('#ch-nc-msg').text(r.aviso || 'Criado.').css('color', 'var(--text-2)');
          setTimeout(function () { window.location.href = BASE + '/admin/chat/contatos/' + r.id; }, 1400);
        } else {
          $('#ch-nc-msg').text(r.erro || 'Falha.').css('color', 'var(--danger)');
        }
      })
      .always(function () { $b.prop('disabled', false); });
  });

  // ── Filtros ───────────────────────────────────────────────────────────────
  $('#ch-filtros').on('click', '.ch-pill', function () {
    var $p = $(this);
    var campo = $p.data('filtro');
    var valor = String($p.data('valor'));

    // Clicar de novo no mesmo filtro limpa
    if (estado.filtros[campo] === valor) {
      estado.filtros[campo] = '';
      $p.removeClass('ativa');
    } else if (campo === 'canal') {
      // Canal é ortogonal aos demais: "Instagram + não lidas" faz sentido.
      // Só um canal por vez, mas sem derrubar o filtro de estado.
      estado.filtros.canal = valor;
      $('#ch-filtros .ch-pill[data-filtro=canal]').removeClass('ativa');
      $p.addClass('ativa');
    } else {
      // Estado/responsável são exclusivos entre si: dois ativos confundem
      // mais do que ajudam. O canal escolhido sobrevive.
      var canal = estado.filtros.canal;
      estado.filtros = { status: '', agente: '', nao_lidas: '', canal: canal, q: estado.filtros.q };
      estado.filtros[campo] = valor;
      $('#ch-filtros .ch-pill').not('[data-filtro=canal]').removeClass('ativa');
      $p.addClass('ativa');
    }
    carregarLista();
  });

  var timerBusca = null;
  $('#ch-busca').on('input', function () {
    var v = $(this).val();
    clearTimeout(timerBusca);
    timerBusca = setTimeout(function () {
      estado.filtros.q = v;
      carregarLista();
    }, 350);
  });

  // Voltar (mobile)
  $('#ch-voltar').on('click', function () { $inbox.removeClass('ver-thread'); });

  // ── Polling ───────────────────────────────────────────────────────────────
  function pollThread() {
    if (!estado.conversaId || document.hidden) return;

    $.get(BASE + '/admin/chat/inbox/' + estado.conversaId + '/novas', {
      desde: estado.ultimoMsgId,
      desde_ts: estado.ultimoTs || ''
    }, function (r) {
      if (!r || !r.ok) return;
      estado.ultimoTs = r.agora;

      if (r.mensagens && r.mensagens.length) {
        renderMensagens(r.mensagens, false);
        carregarLista();
      }

      // Tiques mudam sem mensagem nova (entregue → lido)
      (r.status || []).forEach(function (s) {
        var $b = $('.ch-msg[data-id=' + s.id + '] .ch-bolha-pe');
        if (!$b.length) return;
        var t = s.status === 'lido' ? '<span class="ch-tique--lido">✓✓</span>'
              : s.status === 'entregue' ? '✓✓'
              : s.status === 'falhou' ? '<span style="color:var(--danger)">!</span>' : '✓';
        $b.html($b.text().trim().split(' ')[0] + ' ' + t);
      });

      if (r.conversa) {
        var eraJanela = estado.conversa && estado.conversa.na_janela;
        estado.conversa = r.conversa;
        // A janela pode ter reaberto (cliente respondeu) ou fechado
        if (eraJanela !== r.conversa.na_janela) {
          ajustarCompositor(r.conversa);
          renderCabecalho(r.conversa);
        }
      }
    }, 'json');
  }

  function reiniciarPollThread() {
    clearInterval(timerThread);
    timerThread = setInterval(pollThread, 5000);
  }

  function iniciarPolling() {
    clearInterval(timerLista);
    timerLista = setInterval(function () {
      if (!document.hidden) carregarLista();
    }, 12000);
  }

  document.addEventListener('visibilitychange', function () {
    if (document.hidden) return;
    carregarLista();
    if (estado.conversaId) pollThread();
  });

  // ── Início ────────────────────────────────────────────────────────────────
  // Chegou de /admin/chat?canal=instagram → já entra filtrado
  if (CFG.canal) {
    estado.filtros.canal = CFG.canal;
    $('#ch-filtros .ch-pill[data-filtro=canal][data-valor="' + CFG.canal + '"]').addClass('ativa');
  }

  carregarLista();
  iniciarPolling();

  if (CFG.abrir > 0) {
    abrirConversa(CFG.abrir);
  }

})(jQuery);
