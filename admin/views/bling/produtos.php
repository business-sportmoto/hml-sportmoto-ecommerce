<?php // admin/views/bling/produtos.php ?>

<div class="admin-page">
  <div class="admin-page-header">
    <div>
      <h1 class="admin-page-title">Catálogo do Bling</h1>
      <p class="admin-page-sub">Veja o que existe no Bling e traga para o site o que for vender online</p>
    </div>
    <a href="<?= ADMIN_URL ?>/configuracoes/bling" class="btn btn-outline btn-sm">
      Configurações da integração
    </a>
  </div>

  <?php if (!$conectado): ?>
  <div class="admin-alert admin-alert--danger" style="margin-bottom:16px;">
    Conta Bling não conectada.
    <a href="<?= ADMIN_URL ?>/configuracoes/bling">Conecte a integração</a> para ver o catálogo.
  </div>
  <?php else: ?>

  <div class="admin-card" style="margin-bottom:14px;">
    <div style="padding:16px 20px;">
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
        <div style="flex:1;min-width:260px;">
          <label class="pe-label" style="font-size:11px;">Buscar</label>
          <input type="text" id="bp-termo" class="form-control"
                 placeholder="Nome do produto, ou código exato…">
        </div>
        <div style="width:170px;">
          <label class="pe-label" style="font-size:11px;">Buscar por</label>
          <select id="bp-campo" class="form-control">
            <option value="nome">Nome (parcial)</option>
            <option value="codigo">Código (exato)</option>
            <option value="ean">EAN</option>
          </select>
        </div>
        <button type="button" class="btn btn-primary" id="bp-buscar">Buscar</button>
        <button type="button" class="btn btn-ghost" id="bp-limpar">Limpar</button>
      </div>

      <p style="font-size:12px;color:var(--c-text-muted);margin:12px 0 0;line-height:1.6;">
        A lista mostra <strong>produtos e produtos-pai</strong>. Variações não aparecem
        sozinhas — importar o pai já traz todas junto, com código, EAN e custo de cada uma.
      </p>
      <p id="bp-aviso-ean" style="display:none;font-size:12px;color:var(--warning,#b45309);margin:8px 0 0;line-height:1.6;">
        A API do Bling não permite buscar por EAN. Esta busca só encontra produtos
        <strong>já importados</strong> — para os demais, use o código ou o nome.
      </p>
    </div>
  </div>

  <div class="admin-card">
    <div style="padding:12px 20px;border-bottom:1px solid var(--c-border);display:flex;align-items:center;justify-content:space-between;">
      <h3 style="margin:0;font-size:13px;font-weight:800;">Produtos no Bling</h3>
      <span id="bp-contagem" class="odh-count-badge">—</span>
    </div>

    <!-- Barra de ação em lote: só aparece com algo marcado -->
    <div id="bp-barra-lote"
         style="display:none;padding:11px 20px;background:#eff6ff;border-bottom:1px solid #bfdbfe;
                align-items:center;justify-content:space-between;gap:12px;">
      <span style="font-size:13px;font-weight:700;">
        <span id="bp-sel-contagem">0</span> selecionado(s)
      </span>
      <div style="display:flex;align-items:center;gap:10px;">
        <span id="bp-lote-progresso" style="font-size:12.5px;color:var(--c-text-muted);"></span>
        <button type="button" class="btn btn-ghost btn-sm" id="bp-desmarcar">Desmarcar</button>
        <button type="button" class="btn btn-primary btn-sm" id="bp-importar-lote">
          Importar selecionados
        </button>
      </div>
    </div>

    <div class="table-wrap">
      <table class="admin-table" id="bp-tabela">
        <thead>
          <tr>
            <th style="width:34px;text-align:center;">
              <input type="checkbox" id="bp-marcar-todos" title="Marcar todos os importáveis desta página">
            </th>
            <th style="width:120px;">Código</th>
            <th>Produto</th>
            <th style="width:70px;">Tipo</th>
            <th style="width:105px;">Preço</th>
            <th style="width:105px;">Custo</th>
            <th style="width:70px;">Saldo</th>
            <th style="width:190px;">Situação no site</th>
          </tr>
        </thead>
        <tbody>
          <tr><td colspan="8" style="text-align:center;padding:28px;color:var(--c-text-muted);">Carregando…</td></tr>
        </tbody>
      </table>
    </div>

    <div style="padding:14px 20px;border-top:1px solid var(--c-border);display:flex;align-items:center;justify-content:space-between;">
      <button type="button" class="btn btn-outline btn-sm" id="bp-anterior" disabled>← Anterior</button>
      <span id="bp-pagina" style="font-size:13px;color:var(--c-text-muted);">Página 1</span>
      <button type="button" class="btn btn-outline btn-sm" id="bp-proxima" disabled>Próxima →</button>
    </div>
  </div>
  <?php endif; ?>
</div>

<script>
(function () {
  var ADMIN = '<?= ADMIN_URL ?>';
  var pagina = 1, temProxima = false, carregando = false;

  function esc(s) { return $('<i>').text(s == null ? '' : s).html(); }
  function brl(v) { return 'R$ ' + Number(v || 0).toFixed(2).replace('.', ','); }

  // Marca de "já está no site". Usada tanto na primeira renderização
  // quanto ao atualizar a linha após importar — por isso é uma função só:
  // duas versões do mesmo HTML divergiriam na primeira mudança.
  function badgeNoSite(produtoId, ativo) {
    var badge = ativo
      ? '<span style="color:var(--success);font-weight:800;font-size:12px;">✓ Publicado</span>'
      : '<span style="color:var(--warning,#b45309);font-weight:800;font-size:12px;">● Rascunho</span>';
    return badge + ' <a href="' + ADMIN + '/produtos/' + produtoId
         + '/editar" target="_blank" class="link-subtle" style="font-size:12px;margin-left:6px;">abrir →</a>';
  }

  function situacao(i) {
    if (!i.no_site) {
      return '<button type="button" class="btn btn-primary btn-sm bp-importar" '
           + 'data-bling-id="' + esc(i.bling_id) + '">Importar para o site</button>';
    }
    return badgeNoSite(i.produto_id, i.produto_ativo);
  }

  // Atualiza a linha no lugar, sem recarregar a página: com muitos produtos
  // o reload custa uma consulta nova ao Bling e joga o admin de volta ao topo.
  function marcarLinhaImportada($tr, r) {
    $tr.find('.bp-sel').remove();
    $tr.find('td:last').html(badgeNoSite(r.produto_id, r.produto_ativo));
    $tr.css('background', 'var(--c-bg-alt)');
    atualizarSelecao();
  }

  function marcarLinhaErro($tr, msg) {
    $tr.find('td:last').html(
      '<span style="color:var(--danger);font-size:12px;" title="' + esc(msg) + '">Falhou</span> '
      + '<button type="button" class="btn btn-ghost btn-sm bp-importar" data-bling-id="'
      + esc($tr.data('bling-id')) + '">tentar de novo</button>'
    );
  }

  function atualizarSelecao() {
    var n = $('.bp-sel:checked').length;
    $('#bp-sel-contagem').text(n);
    $('#bp-barra-lote').css('display', n > 0 ? 'flex' : 'none');
    var total = $('.bp-sel').length;
    $('#bp-marcar-todos').prop('checked', total > 0 && n === total)
                         .prop('indeterminate', n > 0 && n < total);
  }

  function carregar() {
    if (carregando) return;
    carregando = true;
    $('#bp-tabela tbody').html('<tr><td colspan="8" style="text-align:center;padding:28px;color:var(--c-text-muted);">Carregando…</td></tr>');

    $.get(ADMIN + '/bling/produtos/listar', {
      pagina: pagina,
      termo : $('#bp-termo').val().trim(),
      campo : $('#bp-campo').val()
    })
    .done(function (r) {
      carregando = false;
      if (!r.ok) {
        $('#bp-tabela tbody').html('<tr><td colspan="8" style="text-align:center;padding:24px;color:var(--danger);">'
          + esc(r.msg || 'Erro ao consultar o Bling.') + '</td></tr>');
        return;
      }

      if (r.aviso) showToast(r.aviso, 'info');

      var itens = r.itens || [];
      temProxima = !!r.tem_proxima;

      $('#bp-contagem').text(itens.length + ' produto(s) nesta página');
      $('#bp-pagina').text('Página ' + r.pagina);
      $('#bp-anterior').prop('disabled', r.pagina <= 1);
      $('#bp-proxima').prop('disabled', !temProxima);

      if (!itens.length) {
        $('#bp-tabela tbody').html('<tr><td colspan="8" style="text-align:center;padding:28px;color:var(--c-text-muted);">Nenhum produto encontrado.</td></tr>');
        atualizarSelecao();
        return;
      }

      var html = '';
      itens.forEach(function (i) {
        html += '<tr data-bling-id="' + esc(i.bling_id) + '"'
          + (i.no_site ? ' style="background:var(--c-bg-alt);"' : '') + '>'
          + '<td style="text-align:center;">'
            + (i.no_site ? '' : '<input type="checkbox" class="bp-sel" value="' + esc(i.bling_id) + '">')
            + '</td>'
          + '<td><code style="font-size:11.5px;">' + esc(i.codigo || '—') + '</code></td>'
          + '<td style="font-size:13px;">' + esc(i.nome)
            + (i.situacao !== 'A' ? ' <span style="font-size:10.5px;color:var(--c-text-muted);">(inativo no Bling)</span>' : '')
            + '</td>'
          + '<td style="font-size:12px;color:var(--c-text-muted);">' + (i.tem_variacao ? 'Variações' : 'Simples') + '</td>'
          + '<td style="font-size:12.5px;">' + brl(i.preco) + '</td>'
          + '<td style="font-size:12.5px;color:var(--c-text-muted);">' + (i.preco_custo > 0 ? brl(i.preco_custo) : '—') + '</td>'
          + '<td style="font-size:12.5px;">' + i.saldo + '</td>'
          + '<td>' + situacao(i) + '</td>'
          + '</tr>';
      });
      $('#bp-tabela tbody').html(html);
    })
    .fail(function () {
      carregando = false;
      $('#bp-tabela tbody').html('<tr><td colspan="8" style="text-align:center;padding:24px;color:var(--danger);">Erro de rede.</td></tr>');
    });
  }

  $('#bp-buscar').on('click', function () { pagina = 1; carregar(); });
  $('#bp-limpar').on('click', function () { $('#bp-termo').val(''); pagina = 1; carregar(); });
  $('#bp-termo').on('keydown', function (e) { if (e.key === 'Enter') { pagina = 1; carregar(); } });
  $('#bp-campo').on('change', function () { $('#bp-aviso-ean').toggle($(this).val() === 'ean'); });
  $('#bp-anterior').on('click', function () { if (pagina > 1) { pagina--; carregar(); } });
  $('#bp-proxima').on('click', function () { if (temProxima) { pagina++; carregar(); } });

  // ── Seleção ──────────────────────────────────────────
  $(document).on('change', '.bp-sel', atualizarSelecao);
  $('#bp-marcar-todos').on('change', function () {
    $('.bp-sel').prop('checked', this.checked);
    atualizarSelecao();
  });
  $('#bp-desmarcar').on('click', function () {
    $('.bp-sel, #bp-marcar-todos').prop('checked', false);
    atualizarSelecao();
  });

  /**
   * Importa UM produto e atualiza a linha. Devolve uma promise que
   * NUNCA rejeita — no lote, uma falha não pode interromper o resto.
   */
  function importarUm(blingId) {
    var $tr = $('tr[data-bling-id="' + blingId + '"]');
    var $btn = $tr.find('.bp-importar').prop('disabled', true).text('Importando…');

    return $.post(ADMIN + '/bling/produtos/importar', {
      _token: CSRF_TOKEN, bling_id: blingId
    })
    .then(function (r) {
      // ok=false com produto_id (já existia / foi vinculado) também é
      // "resolvido": a linha passa a mostrar o produto do site.
      if (r.produto_id) { marcarLinhaImportada($tr, r); return { ok: true, r: r }; }
      marcarLinhaErro($tr, r.msg || 'Falha');
      return { ok: false, r: r };
    }, function () {
      $btn.prop('disabled', false).text('Importar para o site');
      return $.Deferred().resolve({ ok: false, r: { msg: 'Erro de rede.' } }).promise();
    });
  }

  // ── Importação individual ────────────────────────────
  $(document).on('click', '.bp-importar', function () {
    var id = $(this).closest('tr').data('bling-id');
    importarUm(id).then(function (res) {
      showToast(res.r.msg, res.ok ? 'success' : 'error');
    });
  });

  // ── Importação em lote ───────────────────────────────
  // SEQUENCIAL, de propósito: cada importação é uma chamada ao Bling, e
  // o teto é de 3 req/s. Disparar tudo em paralelo levaria a 429 e a
  // metade dos produtos falhando. Sequencial ainda deixa cada linha
  // atualizar assim que termina, então o progresso é visível.
  $('#bp-importar-lote').on('click', function () {
    var ids = $('.bp-sel:checked').map(function () { return this.value; }).get();
    if (!ids.length) return;
    if (!confirm('Importar ' + ids.length + ' produto(s) para o site como rascunho?')) return;

    var $btn = $(this).prop('disabled', true);
    var $desm = $('#bp-desmarcar').prop('disabled', true);
    var okN = 0, falhaN = 0, i = 0;

    function proximo() {
      if (i >= ids.length) {
        $btn.prop('disabled', false);
        $desm.prop('disabled', false);
        $('#bp-lote-progresso').text('');
        atualizarSelecao();
        var msg = okN + ' importado(s)';
        if (falhaN) msg += ', ' + falhaN + ' com falha';
        showToast(msg + '.', falhaN ? 'warning' : 'success');
        return;
      }
      $('#bp-lote-progresso').text((i + 1) + ' de ' + ids.length + '…');
      importarUm(ids[i]).then(function (res) {
        res.ok ? okN++ : falhaN++;
        i++;
        proximo();
      });
    }
    proximo();
  });

  carregar();
})();
</script>
