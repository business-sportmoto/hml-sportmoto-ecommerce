/**
 * admin/assets/js/chat-automacao.js
 *
 * Duas telas num arquivo só (mesmo padrão do logistica.js): cada bloco se
 * ativa pelo seu elemento raiz.
 *   · #ch-form-a    → editor com prévia ao vivo
 *   · .ch-aut-lista → listagem com pastas e menu de contexto
 */
(function ($) {
  'use strict';

  function esc(s) { return $('<i>').text(s == null ? '' : String(s)).html(); }

  // ═══════════════════════════════════════════════════════════════════════════
  // EDITOR
  // ═══════════════════════════════════════════════════════════════════════════
  (function editor() {
    var $form = $('#ch-form-a');
    if (!$form.length) return;

    var CFG  = window.CHED || {};
    var BASE = CFG.base || window.BASE_URL || '';
    var CSRF = CFG.csrf;
    var ID   = CFG.id;

    // ── Prévia ao vivo ─────────────────────────────────────────────────────
    function atualizarPreview() {
      // Comentário de exemplo: a primeira palavra-chave configurada
      var palavras = ($('#ch-a-palavras').val() || '').split(',');
      var exemplo  = (palavras[0] || '').trim() || 'quero';
      $('#ch-pv-coment').text(exemplo);

      // Resposta pública: mostra a primeira variação preenchida
      var respostas = [];
      $('.ch-a-var').each(function () {
        var v = ($(this).val() || '').trim();
        if (v) respostas.push(v);
      });
      var mostrarResp = $('#ch-a-rp').is(':checked') && respostas.length > 0;
      $('#ch-pv-resp-box').toggle(mostrarResp);
      if (mostrarResp) $('#ch-pv-resp').text(respostas[0]);

      // Direct: quando exige seguidor, a PRIMEIRA mensagem é o convite —
      // é isso que a pessoa realmente recebe primeiro
      var exigeSeg = $('#ch-a-seg').is(':checked');
      var txt = exigeSeg
        ? ($('#ch-a-segtxt').val() || '')
        : ($('#ch-a-dmtxt').val() || '');

      if (!exigeSeg) {
        var link = ($('#ch-a-link').val() || '').trim();
        if (link) {
          var rotulo = ($('#ch-a-ltxt').val() || '').trim() || 'Acessar';
          txt = txt.replace(/\s+$/, '') + '\n\n' + rotulo + ': ' + BASE + '/ir/xxxxxxx';
        }
      }

      $('#ch-pv-dmtxt').text(txt || 'Sua mensagem aparece aqui');
      $('#ch-pv-qr').toggle(exigeSeg);
    }

    $(document).on('input change',
      '#ch-a-palavras, #ch-a-dmtxt, #ch-a-segtxt, #ch-a-link, #ch-a-ltxt, .ch-a-var, #ch-a-rp, #ch-a-seg',
      atualizarPreview);

    $('.ch-fone-aba').on('click', function () {
      var alvo = $(this).data('pv');
      $('.ch-fone-aba').removeClass('ativa');
      $(this).addClass('ativa');
      $('#ch-pv-post').toggle(alvo === 'post');
      $('#ch-pv-dm').toggle(alvo === 'dm');
      $('#ch-pv-titulo').text(alvo === 'dm' ? 'Direct' : 'Publicação');
    });

    // ── Mostrar/esconder blocos ────────────────────────────────────────────
    $('input[name=escopo]').on('change', function () {
      $('.ch-ed-opcao').removeClass('ativa');
      $(this).closest('.ch-ed-opcao').addClass('ativa');
      $('#ch-a-posts').toggle($(this).val() === 'midia');
    });

    $('input[name=_modo_palavra]').on('change', function () {
      var especifica = $(this).val() === 'especifica';
      $(this).closest('.ch-ed-passo').find('.ch-ed-opcao').removeClass('ativa');
      $(this).closest('.ch-ed-opcao').addClass('ativa');
      // "qualquer palavra" = campo vazio, que é como o backend interpreta
      if (!especifica) $('#ch-a-palavras').val('');
      atualizarPreview();
    });

    $('#ch-a-rp').on('change', function () { $('#ch-a-rp-box').toggle(this.checked); });
    $('#ch-a-dm').on('change', function () { $('#ch-a-dm-box').toggle(this.checked); });
    $('#ch-a-seg').on('change', function () { $('#ch-a-seg-box').toggle(this.checked); });

    // ── Seleção de publicações ─────────────────────────────────────────────
    $(document).on('change', '.ch-ed-post input', function () {
      $(this).closest('.ch-ed-post').toggleClass('sel', this.checked);
      $('#ch-a-nsel').text($('.ch-ed-post input:checked').length);
    });

    // ── Palavras de exemplo ────────────────────────────────────────────────
    $('.ch-a-exemplo').on('click', function (e) {
      e.preventDefault();
      var atual = ($('#ch-a-palavras').val() || '').trim();
      var nova  = $(this).data('p');
      var lista = atual ? atual.split(',').map(function (x) { return x.trim(); }) : [];
      if (lista.indexOf(nova) < 0) lista.push(nova);
      $('#ch-a-palavras').val(lista.filter(Boolean).join(', '));
      $('input[name=_modo_palavra][value=especifica]').prop('checked', true).trigger('change');
    });

    // ── Variações de resposta pública ──────────────────────────────────────
    $('#ch-a-add-var').on('click', function () {
      $('#ch-a-variacoes').append(
        '<div class="ch-ed-var">' +
        '<input type="text" class="ch-input ch-a-var" placeholder="Outra forma de responder" maxlength="180">' +
        '<button type="button" class="ch-fx-lista-rm" data-rm-var>&times;</button></div>'
      );
    });
    $(document).on('click', '[data-rm-var]', function () {
      if ($('.ch-a-var').length <= 1) { $('.ch-a-var').val(''); atualizarPreview(); return; }
      $(this).closest('.ch-ed-var').remove();
      atualizarPreview();
    });

    // ── Salvar ─────────────────────────────────────────────────────────────
    function coletar() {
      var d = $form.serializeArray();

      // As variações viram uma string separada por | (formato do banco)
      var vars = [];
      $('.ch-a-var').each(function () {
        var v = ($(this).val() || '').trim();
        if (v) vars.push(v);
      });

      d.push({ name: 'resposta_publica', value: vars.join(' | ') });
      d.push({ name: 'nome',     value: $('#ch-a-nome').val() });
      d.push({ name: 'pasta_id', value: $('#ch-a-pasta').val() || 0 });
      return $.param(d);
    }

    function msg(texto, tipo) {
      $('#ch-a-msg').html('<div class="ch-aviso ch-aviso--' + tipo + '"><div>' + esc(texto) + '</div></div>');
      if (tipo === 'ok') setTimeout(function () { $('#ch-a-msg').empty(); }, 2500);
    }

    function salvar() {
      return $.post(BASE + '/admin/chat/automacoes/' + ID + '/salvar', coletar(), null, 'json');
    }

    $('#ch-a-salvar').on('click', function () {
      var $b = $(this).prop('disabled', true).text('Salvando...');
      salvar().done(function (r) {
        msg(r.ok ? 'Alterações salvas.' : (r.erro || 'Falha ao salvar.'), r.ok ? 'ok' : 'erro');
      }).fail(function () {
        msg('Erro de rede.', 'erro');
      }).always(function () { $b.prop('disabled', false).text('Salvar'); });
    });

    // Ativar sempre salva antes: ativar um rascunho desatualizado é a
    // pegadinha clássica desse tipo de editor
    $('.ch-a-status').on('click', function () {
      var st = $(this).data('status');
      var $b = $(this).prop('disabled', true);

      salvar().done(function (rs) {
        if (!rs.ok) { msg(rs.erro || 'Corrija antes de ativar.', 'erro'); $b.prop('disabled', false); return; }

        $.post(BASE + '/admin/chat/automacoes/' + ID + '/status',
          { csrf_token: CSRF, status: st }, function (r) {
            if (r.ok) location.reload();
            else { msg(r.erro || 'Falha.', 'erro'); $b.prop('disabled', false); }
          }, 'json').fail(function () { $b.prop('disabled', false); });
      }).fail(function () {
        msg('Erro de rede.', 'erro');
        $b.prop('disabled', false);
      });
    });

    atualizarPreview();
  })();

  // ═══════════════════════════════════════════════════════════════════════════
  // LISTAGEM
  // ═══════════════════════════════════════════════════════════════════════════
  (function lista() {
    var $lista = $('.ch-aut-lista');
    var $pastas = $('.ch-pastas');
    if (!$lista.length && !$pastas.length) return;

    var CFG  = window.CHAUT || {};
    var BASE = CFG.base || window.BASE_URL || '';
    var CSRF = CFG.csrf;
    var alvoId = 0;

    function post(rota, dados) {
      return $.post(BASE + rota, $.extend({ csrf_token: CSRF }, dados || {}), null, 'json');
    }

    // ── Ativar / parar ─────────────────────────────────────────────────────
    $('.ch-status').on('click', function () {
      var $b = $(this).prop('disabled', true);
      post('/admin/chat/automacoes/' + $(this).data('id') + '/status', { status: $(this).data('status') })
        .done(function (r) {
          if (r.ok) location.reload();
          else { alert(r.erro || 'Falha.'); $b.prop('disabled', false); }
        })
        .fail(function () { $b.prop('disabled', false); });
    });

    $('.ch-restaurar').on('click', function () {
      post('/admin/chat/automacoes/' + $(this).data('id') + '/restaurar')
        .done(function () { location.reload(); });
    });

    $('.ch-remover').on('click', function () {
      if (!confirm('Apagar "' + $(this).data('nome') + '" de vez?\n\nIsso não pode ser desfeito.')) return;
      post('/admin/chat/automacoes/' + $(this).data('id') + '/remover')
        .done(function () { location.reload(); });
    });

    // ── Menu de contexto ───────────────────────────────────────────────────
    $('.ch-menu-btn').on('click', function (e) {
      e.stopPropagation();
      alvoId = $(this).data('id');

      var r = this.getBoundingClientRect();
      $('#ch-menu').css({
        top:  (r.bottom + window.scrollY + 5) + 'px',
        left: (r.right + window.scrollX - 190) + 'px'
      }).addClass('aberto');
    });

    $(document).on('click', function () { $('#ch-menu').removeClass('aberto'); });
    $('#ch-menu').on('click', function (e) { e.stopPropagation(); });

    $('#ch-menu button').on('click', function () {
      var acao = $(this).data('acao');
      $('#ch-menu').removeClass('aberto');
      if (!alvoId) return;

      if (acao === 'duplicar') {
        post('/admin/chat/automacoes/' + alvoId + '/duplicar').done(function (r) {
          if (r.ok && r.redirect) window.location.href = r.redirect;
          else alert(r.erro || 'Falha ao duplicar.');
        });

      } else if (acao === 'excluir') {
        if (!confirm('Mover esta automação para a lixeira?')) return;
        post('/admin/chat/automacoes/' + alvoId + '/excluir').done(function () { location.reload(); });

      } else if (acao === 'rascunho') {
        post('/admin/chat/automacoes/' + alvoId + '/status', { status: 'rascunho' })
          .done(function (r) {
            if (r.ok) location.reload();
            else alert(r.erro || 'Falha.');
          });

      } else if (acao === 'mover') {
        $('#ch-mover-titulo').text('Mover para pasta');
        $('#ch-mover-pasta-box').show();
        $('#ch-mover-dono-box').hide();
        $('#ch-modal-mover').addClass('aberto').data('acao', 'mover');

      } else if (acao === 'transferir') {
        $('#ch-mover-titulo').text('Trocar dono');
        $('#ch-mover-pasta-box').hide();
        $('#ch-mover-dono-box').show();
        $('#ch-modal-mover').addClass('aberto').data('acao', 'transferir');
      }
    });

    $('#ch-mover-salvar').on('click', function () {
      var acao = $('#ch-modal-mover').data('acao');
      var $b = $(this).prop('disabled', true);

      var rota = acao === 'transferir'
        ? '/admin/chat/automacoes/' + alvoId + '/transferir'
        : '/admin/chat/automacoes/' + alvoId + '/pasta';

      var dados = acao === 'transferir'
        ? { usuario_id: $('#ch-mover-dono').val() }
        : { pasta_id: $('#ch-mover-pasta').val() };

      post(rota, dados).done(function (r) {
        if (r.ok) location.reload();
        else { alert(r.erro || 'Falha.'); $b.prop('disabled', false); }
      }).fail(function () { $b.prop('disabled', false); });
    });

    // ── Pastas ─────────────────────────────────────────────────────────────
    $('#ch-nova-pasta').on('click', function () {
      $('#ch-pasta-erro').text('');
      $('#ch-pasta-nome').val('');
      $('#ch-modal-pasta').addClass('aberto');
      setTimeout(function () { $('#ch-pasta-nome').focus(); }, 60);
    });

    $('#ch-pasta-salvar').on('click', function () {
      var nome = ($('#ch-pasta-nome').val() || '').trim();
      if (!nome) { $('#ch-pasta-erro').text('Informe o nome.'); return; }

      var $b = $(this).prop('disabled', true);
      post('/admin/chat/automacoes/pastas/salvar', { nome: nome, cor: $('#ch-pasta-cor').val() })
        .done(function (r) {
          if (r.ok) location.reload();
          else { $('#ch-pasta-erro').text(r.erro || 'Falha.'); $b.prop('disabled', false); }
        })
        .fail(function () { $('#ch-pasta-erro').text('Erro de rede.'); $b.prop('disabled', false); });
    });

    $('#ch-pasta-nome').on('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); $('#ch-pasta-salvar').click(); }
    });

    $('[data-excluir-pasta]').on('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      var id = $(this).data('excluir-pasta');
      if (!confirm('Excluir a pasta "' + $(this).data('nome') + '"?\n\nAs automações dentro dela não são apagadas — voltam para "sem pasta".')) return;
      post('/admin/chat/automacoes/pastas/' + id + '/excluir').done(function () { location.reload(); });
    });

    // ── Modais ─────────────────────────────────────────────────────────────
    $(document).on('click', '[data-fechar]', function () { $(this).closest('.ch-modal').removeClass('aberto'); });
    $(document).on('click', '.ch-modal', function (e) { if (e.target === this) $(this).removeClass('aberto'); });
    $(document).on('keydown', function (e) { if (e.key === 'Escape') $('.ch-modal').removeClass('aberto'); });
  })();

  // Filtro sem botão: escolher já aplica. O <noscript> da view cobre quem
  // chega aqui sem JS, e a busca continua indo no Enter.
  $(document).on('change', '.ch-filtros [data-auto]', function () {
    var f = this.form; if (f) f.submit();
  });

})(jQuery);
