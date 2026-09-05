/**
 * public/js/vida-util-admin.js
 *
 * Regras de dica de cuidado. jQuery + adminDrawer + Toast (componentes do painel).
 * Sem fetch, sem async/await.
 */
(function ($) {
  'use strict';

  var DADOS = window.VU_DADOS || { regras: [], funil: {}, categorias_livres: [] };
  var BASE  = window.BASE_URL || '';

  // Valores de exemplo do preview — os mesmos {{vars}} que o worker interpola
  var EXEMPLO = { produto_nome: 'Pneu Pirelli Angel', moto_apelido: 'Pretinha' };

  var CATEGORIAS_NOTIF = [
    ['sistema',    'Sistema'],
    ['pedido',     'Pedido'],
    ['promocao',   'Promoção'],
    ['estoque',    'Estoque'],
    ['financeiro', 'Financeiro'],
    ['conta',      'Conta']
  ];

  function post(rota, dados, cb) {
    dados.csrf_token = window.CSRF_TOKEN || '';
    $.post(BASE + '/admin/vida-util/' + rota, dados, cb, 'json')
     .fail(function () { Toast.error('Sem resposta do servidor. Tente de novo.'); });
  }

  function interpolar(texto) {
    return String(texto || '').replace(/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/g,
      function (_, k) { return EXEMPLO[k] !== undefined ? EXEMPLO[k] : ''; });
  }

  function plural(n, um, muitos) { return n === 1 ? um : muitos; }

  // ── Render ────────────────────────────────────────────────────────────────

  function render() {
    renderFunil();
    renderTabela();
  }

  function renderFunil() {
    var f = DADOS.funil || {};
    var enviadas = f.enviadas || 0;
    var taxa     = f.taxa || 0;

    $('#vu-n-agendadas').text(f.agendadas || 0);
    $('#vu-n-enviadas').text(enviadas);
    $('#vu-n-cliques').text(f.cliques || 0);
    $('#vu-taxa').text(
      enviadas > 0 ? taxa.toString().replace('.', ',') + '% de quem recebeu' : 'ainda sem envios'
    );
  }

  function renderTabela() {
    var $alvo = $('#vu-conteudo').empty();
    var regras = DADOS.regras || [];

    if (!regras.length) {
      $alvo.append(
        $('<div class="vu_vazio">')
          .append('<i class="bi bi-clipboard-check"></i>')
          .append($('<div class="vu_vazio_t">').text('Nenhuma regra ainda'))
          .append($('<p class="vu_vazio_p">').text(
            'Comece pelos itens que se desgastam: pneus, óleo, pastilhas de freio. ' +
            'Prazos conservadores funcionam melhor — é melhor lembrar cedo do que tarde.'))
          .append($('<button type="button" class="vu_btn vu_pri" id="vu-novo-vazio">')
            .append('<i class="bi bi-plus-lg"></i>')
            .append($('<span>').text('Criar a primeira regra')))
      );
      return;
    }

    var $tab = $('<table class="vu_tabela">');
    $tab.append(
      '<thead><tr>' +
        '<th>Categoria</th>' +
        '<th>Prazo</th>' +
        '<th class="vu_col_opcional">A dica</th>' +
        '<th class="vu_th_num vu_col_opcional">Agendadas</th>' +
        '<th class="vu_th_num vu_col_opcional">Enviadas</th>' +
        '<th class="vu_th_num">Cliques</th>' +
        '<th></th>' +
      '</tr></thead>'
    );

    var $tb = $('<tbody>');
    $.each(regras, function (_, r) {
      var $tr = $('<tr>').attr('data-id', r.id);
      if (!r.ativo) $tr.addClass('vu_pausada');

      var $cat = $('<div class="vu_cat">').append($('<span>').text(r.categoria_nome));
      if (!r.ativo) $cat.append($('<span class="vu_pill">').text('Pausada'));

      $tr.append($('<td>').append($cat));
      $tr.append($('<td>').append(
        $('<span class="vu_prazo">').text(r.meses).append($('<small>').text('meses'))
      ));

      $tr.append($('<td class="vu_col_opcional">')
        .append($('<span class="vu_titulo_dica">').text(interpolar(r.titulo)))
        .append($('<span class="vu_corpo_dica">').text(interpolar(r.dica))));

      $tr.append($('<td class="vu_num vu_col_opcional">').text(r.agendadas));
      $tr.append($('<td class="vu_num vu_col_opcional">').text(r.enviadas));

      var cliqueTxt = String(r.cliques);
      if (r.enviadas > 0) {
        cliqueTxt += '  (' + Math.round(r.cliques / r.enviadas * 100) + '%)';
      }
      $tr.append($('<td class="vu_num">').text(cliqueTxt));

      var $acoes = $('<div class="vu_acoes">')
        .append($('<button type="button" class="vu_btn_mini vu-editar">')
          .append('<i class="bi bi-pencil"></i>')
          .append($('<span>').text('Editar')))
        .append($('<button type="button" class="vu_ic vu-pausar">')
          .attr('title', r.ativo ? 'Pausar regra' : 'Ativar regra')
          .attr('aria-label', r.ativo ? 'Pausar regra' : 'Ativar regra')
          .append(r.ativo ? '<i class="bi bi-pause"></i>' : '<i class="bi bi-play"></i>'))
        .append($('<button type="button" class="vu_ic vu_perigo vu-excluir">')
          .attr('title', 'Excluir regra').attr('aria-label', 'Excluir regra')
          .append('<i class="bi bi-trash"></i>'));

      $tr.append($('<td>').append($acoes));
      $tb.append($tr);
    });

    $alvo.append($tab.append($tb));
  }

  function acharRegra(id) {
    var achada = null;
    $.each(DADOS.regras || [], function (_, r) { if (r.id === id) { achada = r; return false; } });
    return achada;
  }

  function recarregar(cb) {
    $.get(BASE + '/admin/vida-util/listar', function (r) {
      if (r && r.ok) {
        DADOS = { regras: r.regras, funil: r.funil, categorias_livres: r.categorias_livres };
        render();
      }
      if (typeof cb === 'function') cb();
    }, 'json');
  }

  // ── Drawer de edição ──────────────────────────────────────────────────────

  function abrirDrawer(regra) {
    var novo = !regra;

    if (novo && !(DADOS.categorias_livres || []).length) {
      Toast.warning('Todas as categorias já têm regra. Edite uma existente.');
      return;
    }

    var drawer = adminDrawer({
      titulo: novo ? 'Nova regra de cuidado' : 'Editar regra',
      subtitulo: novo
        ? 'Um lembrete de cuidado alguns meses depois da entrega'
        : regra.categoria_nome,
      conteudo: montarForm(regra),
      tamanho: 'md',
      acoes: '<button type="button" class="vu_btn vu_pri vu-salvar">' +
             '<i class="bi bi-check-lg"></i> Salvar regra</button>'
    });

    // Preenche os valores depois de montado (evita escapar HTML na mão)
    var $c = $(drawer.corpo());
    if (!novo) {
      $c.find('[name=id]').val(regra.id);
      $c.find('[name=categoria_id]').val(regra.categoria_id);
      $c.find('[name=meses]').val(regra.meses);
      $c.find('[name=titulo]').val(regra.titulo);
      $c.find('[name=dica]').val(regra.dica);
      $c.find('[name=categoria_notif]').val(regra.categoria_notif);
      $c.find('[name=ativo]').prop('checked', regra.ativo);
    }
    atualizarPreview($c);

    // Preview: liga direto no corpo (método público) — o formulário é estático
    // depois de montado, então não precisa de delegação do componente.
    $c.on('input change', '.vu_input', function () { atualizarPreview($c); });

    // Ações: delegação do próprio drawer (padrão documentado do componente)
    drawer.escutar('click', '.vu-salvar', function () { salvar(drawer); });

    if (!novo) {
      $c.find('.vu-drawer-pausar').html(
        regra.ativo ? '<i class="bi bi-pause"></i> Pausar regra'
                    : '<i class="bi bi-play"></i> Ativar regra');
      drawer.escutar('click', '.vu-drawer-pausar', function () {
        pausar(regra.id, !regra.ativo);
        drawer.fechar('pausada', { force: true });
      });
      drawer.escutar('click', '.vu-drawer-excluir', function () {
        drawer.fechar('excluindo', { force: true });
        excluir(regra);
      });
    }

    return drawer;
  }

  function montarForm(regra) {
    var novo = !regra;

    var opcoesCat = '';
    if (novo) {
      opcoesCat = '<option value="">— escolha a categoria —</option>';
      $.each(DADOS.categorias_livres || [], function (_, c) {
        opcoesCat += '<option value="' + c.id + '">' + $('<i>').text(c.nome).html() + '</option>';
      });
    } else {
      opcoesCat = '<option value="' + regra.categoria_id + '">' +
                  $('<i>').text(regra.categoria_nome).html() + '</option>';
    }

    var opcoesNotif = '';
    $.each(CATEGORIAS_NOTIF, function (_, c) {
      opcoesNotif += '<option value="' + c[0] + '">' + c[1] + '</option>';
    });

    return $('' +
      '<form class="vu_form" onsubmit="return false">' +
        '<input type="hidden" name="id" value="0">' +
        '<div class="vu_erros" style="display:none"></div>' +

        '<div class="vu_linha">' +
          '<div class="vu_campo">' +
            '<label class="vu_label" for="vu-cat">Categoria</label>' +
            '<select class="vu_input" id="vu-cat" name="categoria_id"' +
              (novo ? '' : ' disabled') + '>' + opcoesCat + '</select>' +
            (novo ? '' : '<p class="vu_ajuda">A categoria não muda: as dicas já agendadas apontam para ela.</p>') +
          '</div>' +
          '<div class="vu_campo" style="max-width:150px">' +
            '<label class="vu_label" for="vu-meses">Prazo (meses)</label>' +
            '<input class="vu_input" id="vu-meses" name="meses" type="number" min="1" max="600" value="12">' +
          '</div>' +
        '</div>' +
        '<p class="vu_ajuda vu-quando" style="margin:-8px 0 16px"></p>' +

        '<div class="vu_campo">' +
          '<label class="vu_label" for="vu-titulo">Título da dica</label>' +
          '<input class="vu_input" id="vu-titulo" name="titulo" type="text" maxlength="150"' +
            ' placeholder="Já olhou os pneus da {{moto_apelido}}?">' +
        '</div>' +

        '<div class="vu_campo">' +
          '<label class="vu_label" for="vu-dica">Texto da dica</label>' +
          '<textarea class="vu_input" id="vu-dica" name="dica" maxlength="2000"' +
            ' placeholder="Depois de um ano vale checar sulco e pressão do {{produto_nome}}."></textarea>' +
          '<p class="vu_ajuda">Escreva como quem dá um conselho, não como quem vende. ' +
            'A venda vem depois, só para quem clicar.</p>' +
        '</div>' +

        '<div class="vu_campo">' +
          '<label class="vu_label" for="vu-notif">Categoria no sino</label>' +
          '<select class="vu_input" id="vu-notif" name="categoria_notif">' + opcoesNotif + '</select>' +
          '<p class="vu_ajuda">Define o ícone e a cor da notificação.</p>' +
        '</div>' +

        '<div class="vu_campo">' +
          '<label class="vu_check">' +
            '<input type="checkbox" name="ativo" checked>' +
            '<span>Regra ativa</span>' +
          '</label>' +
        '</div>' +

        (novo ? '' :
          '<div class="vu_form_foot">' +
            '<button type="button" class="vu_btn_sec vu-drawer-pausar"></button>' +
            '<button type="button" class="vu_btn_sec vu_perigo_txt vu-drawer-excluir">' +
              '<i class="bi bi-trash"></i> Excluir regra</button>' +
          '</div>') +

        '<div class="vu_preview_wrap">' +
          '<div class="vu_preview_lbl">Como o cliente vai ver</div>' +
          '<div class="vu_notif">' +
            '<div class="vu_notif_ic"><i class="bi bi-tools"></i></div>' +
            '<div class="vu_notif_corpo">' +
              '<div class="vu_notif_t vu-pv-titulo"></div>' +
              '<div class="vu_notif_m vu-pv-dica"></div>' +
              '<div class="vu_notif_hora">agora</div>' +
            '</div>' +
          '</div>' +
          '<p class="vu_vars">Variáveis: <code>{{produto_nome}}</code> vira o nome da peça comprada · ' +
            '<code>{{moto_apelido}}</code> vira o apelido da moto principal (ou "sua moto").</p>' +
        '</div>' +
      '</form>');
  }

  function atualizarPreview($c) {
    var titulo = $c.find('[name=titulo]').val() || '';
    var dica   = $c.find('[name=dica]').val() || '';
    var meses  = parseInt($c.find('[name=meses]').val(), 10) || 0;

    $c.find('.vu-pv-titulo').text(interpolar(titulo) || 'O título aparece aqui');
    $c.find('.vu-pv-dica').text(interpolar(dica) || 'E o texto da dica, aqui.');

    if (meses > 0) {
      var d = new Date();
      d.setMonth(d.getMonth() + meses);
      $c.find('.vu-quando').text(
        'Entrega hoje, dica em ' + d.toLocaleDateString('pt-BR') +
        ' (' + meses + ' ' + plural(meses, 'mês', 'meses') + ' depois).'
      );
    } else {
      $c.find('.vu-quando').text('');
    }
  }

  // ── Ações ─────────────────────────────────────────────────────────────────

  function salvar(drawer) {
    var $c = $(drawer.corpo());
    var $err = $c.find('.vu_erros').hide().empty();

    var dados = {
      id:              $c.find('[name=id]').val() || 0,
      categoria_id:    $c.find('[name=categoria_id]').val() || 0,
      meses:           $c.find('[name=meses]').val() || 0,
      titulo:          $c.find('[name=titulo]').val() || '',
      dica:            $c.find('[name=dica]').val() || '',
      categoria_notif: $c.find('[name=categoria_notif]').val() || 'sistema',
      ativo:           $c.find('[name=ativo]').prop('checked') ? 1 : 0
    };

    post('salvar', dados, function (r) {
      if (r && r.ok) {
        Toast.success(r.msg || 'Regra salva.');
        drawer.fechar('regra-salva', { force: true });
        recarregar();
        return;
      }
      var $ul = $('<ul>');
      $.each((r && r.erros) || ['Não deu para salvar.'], function (_, e) {
        $ul.append($('<li>').text(e));
      });
      $err.append($ul).show();
    });
  }

  function pausar(id, ativar) {
    post('pausar', { id: id, ativo: ativar ? 1 : 0 }, function (r) {
      if (r && r.ok) { Toast.success(r.msg); recarregar(); }
      else Toast.error(((r && r.erros) || ['Não deu para alterar.'])[0]);
    });
  }

  function excluir(regra) {
    Toast.show({
      type: 'warning',
      title: 'Excluir a regra de ' + regra.categoria_nome + '?',
      message: 'Essa categoria deixa de gerar dicas novas.',
      duration: 0,
      actions: [
        { label: 'Cancelar', primary: false, action: function () {} },
        { label: 'Excluir',  primary: true,  action: function () {
            post('excluir', { id: regra.id }, function (r) {
              if (r && r.ok) { Toast.success(r.msg); recarregar(); }
              else Toast.error(((r && r.erros) || ['Não deu para excluir.'])[0]);
            });
          }
        }
      ]
    });
  }

  // ── Ligações ──────────────────────────────────────────────────────────────

  $(function () {
    render();

    $('#vu-novo').on('click', function () { abrirDrawer(null); });
    $('#vu-conteudo').on('click', '#vu-novo-vazio', function () { abrirDrawer(null); });

    $('#vu-conteudo').on('click', '.vu-editar', function (e) {
      e.stopPropagation();
      var r = acharRegra(parseInt($(this).closest('tr').data('id'), 10));
      if (r) abrirDrawer(r);
    });
    // A linha inteira abre a edição — área de clique generosa
    $('#vu-conteudo').on('click', 'tbody tr[data-id]', function (e) {
      if ($(e.target).closest('button').length) return;
      var r = acharRegra(parseInt($(this).data('id'), 10));
      if (r) abrirDrawer(r);
    });
    $('#vu-conteudo').on('click', '.vu-pausar', function (e) {
      e.stopPropagation();
      var r = acharRegra(parseInt($(this).closest('tr').data('id'), 10));
      if (r) pausar(r.id, !r.ativo);
    });
    $('#vu-conteudo').on('click', '.vu-excluir', function (e) {
      e.stopPropagation();
      var r = acharRegra(parseInt($(this).closest('tr').data('id'), 10));
      if (r) excluir(r);
    });
  });

})(jQuery);
