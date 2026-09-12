<?php
// admin/views/estoque/ponte.php
// Variáveis: $movimentos, $resumo, $pernas, $syscarPronto, $total, $page,
//            $totalPaginas, $filtros, $podeOperar

$statusCfg = [
    'pendente'   => ['Pendente',   'var(--warning)', 'var(--warning-lt)'],
    'enviando'   => ['Enviando',   'var(--blue)',    'var(--blue-lt)'],
    'confirmado' => ['Confirmado', 'var(--success)', 'var(--success-lt)'],
    'falhou'     => ['Falhou',     'var(--danger)',  'var(--danger-lt)'],
    'ignorado'   => ['Ignorado',   'var(--text-3)',  'var(--bg)'],
];
$opCfg = ['E' => 'Entrada', 'S' => 'Saída', 'B' => 'Balanço'];
$mov   = $resumo['movimentos'] ?? [];
$evt   = $resumo['eventos']    ?? [];
?>
<div class="ap-page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div>
    <h1 style="font-size:22px;font-weight:800;margin:0;">
      Ponte de estoque
      <span class="badge" style="background:var(--blue-lt);color:var(--blue);font-size:12px;
            vertical-align:middle;margin-left:6px;"><?= (int)$total ?></span>
    </h1>
    <p style="margin:4px 0 0;color:var(--text-2);font-size:13px;">
      Syscar &harr; Bling. O saldo é do Bling; esta tela mostra o que a ponte fez e o que travou.
    </p>
  </div>
  <div style="display:flex;gap:10px;">
    <a href="<?= ADMIN_URL ?>/estoque/ponte/canais" class="btn">⚙ Canais</a>
  </div>
</div>

<?php if (!$pernas['a'] || !$pernas['b']): ?>
<div style="margin:14px 0;padding:12px 14px;border-radius:8px;
            background:var(--warning-lt);border:1px solid var(--warning);font-size:13px;">
  <strong>A ponte está parcialmente desligada.</strong>
  Perna A (Syscar&nbsp;&rarr;&nbsp;Bling): <strong><?= $pernas['a'] ? 'ligada' : 'desligada' ?></strong> ·
  Perna B (Bling&nbsp;&rarr;&nbsp;Syscar): <strong><?= $pernas['b'] ? 'ligada' : 'desligada' ?></strong>.
  Com a perna desligada o movimento é registrado e fica na fila — nada é enviado.
  Os interruptores são <code>estoque_ponte_perna_a</code> e <code>_b</code> em <code>configuracoes</code>.
  <?php if (!$syscarPronto): ?>
    <br><span style="color:var(--danger);">O Syscar ainda não está configurado (URL/token ausentes) —
    ligar a perna B antes disso só produz falha.</span>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── Cards ─────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(165px,1fr));gap:12px;margin:16px 0;">
  <?php
  $cards = [
      ['Na fila',            (int)($mov['pendentes']       ?? 0), 'var(--warning)'],
      ['Enviando',           (int)($mov['enviando']        ?? 0), 'var(--blue)'],
      ['Confirmados 24h',    (int)($mov['confirmados_24h'] ?? 0), 'var(--success)'],
      ['Falhas 24h',         (int)($mov['falhas_24h']      ?? 0), 'var(--danger)'],
      ['Ignorados',          (int)($mov['ignorado']        ?? 0), 'var(--text-3)'],
      ['Eventos na fila',    (int)($evt['pendentes']       ?? 0), 'var(--purple)'],
  ];
  foreach ($cards as [$rotulo, $valor, $cor]): ?>
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:14px;">
      <div style="font-size:12px;color:var(--text-2);"><?= htmlspecialchars($rotulo) ?></div>
      <div style="font-size:26px;font-weight:800;color:<?= $cor ?>;"><?= $valor ?></div>
    </div>
  <?php endforeach; ?>
</div>

<?php if (!empty($mov['ultima_confirmacao'])): ?>
  <p style="font-size:12px;color:var(--text-2);margin:-6px 0 14px;">
    Última confirmação: <?= htmlspecialchars((string)$mov['ultima_confirmacao']) ?>
  </p>
<?php endif; ?>

<!-- ── Filtros ───────────────────────────────────────── -->
<form method="get" action="<?= ADMIN_URL ?>/estoque/ponte"
      style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;">
  <input type="text" name="q" value="<?= htmlspecialchars($filtros['q']) ?>"
         placeholder="SKU ou pedido do Bling" class="input" style="min-width:220px;">
  <select name="status" class="input">
    <option value="">Todos os status</option>
    <?php foreach ($statusCfg as $k => $c): ?>
      <option value="<?= $k ?>" <?= $filtros['status'] === $k ? 'selected' : '' ?>><?= $c[0] ?></option>
    <?php endforeach; ?>
  </select>
  <select name="direcao" class="input">
    <option value="">As duas pernas</option>
    <option value="para_bling"  <?= $filtros['direcao'] === 'para_bling'  ? 'selected' : '' ?>>Syscar → Bling</option>
    <option value="para_syscar" <?= $filtros['direcao'] === 'para_syscar' ? 'selected' : '' ?>>Bling → Syscar</option>
  </select>
  <button type="submit" class="btn btn-primary">Filtrar</button>
  <a href="<?= ADMIN_URL ?>/estoque/ponte" class="btn">Limpar</a>
</form>

<!-- ── Lista ─────────────────────────────────────────── -->
<div style="overflow-x:auto;">
<table class="table" style="width:100%;border-collapse:collapse;font-size:13px;">
  <thead>
    <tr style="text-align:left;border-bottom:1px solid var(--border);">
      <th style="padding:8px;">#</th>
      <th style="padding:8px;">Quando</th>
      <th style="padding:8px;">Direção</th>
      <th style="padding:8px;">SKU</th>
      <th style="padding:8px;">Operação</th>
      <th style="padding:8px;text-align:right;">Qtd</th>
      <th style="padding:8px;">Canal</th>
      <th style="padding:8px;">Status</th>
      <th style="padding:8px;"></th>
    </tr>
  </thead>
  <tbody>
  <?php if (!$movimentos): ?>
    <tr><td colspan="9" style="padding:28px;text-align:center;color:var(--text-2);">
      Nenhum movimento. Com as pernas desligadas, isso é o esperado.
    </td></tr>
  <?php endif; ?>

  <?php foreach ($movimentos as $m):
      $cfg = $statusCfg[$m['status']] ?? ['?', 'var(--text-2)', 'var(--bg)'];
  ?>
    <tr style="border-bottom:1px solid var(--border);">
      <td style="padding:8px;color:var(--text-2);"><?= (int)$m['id'] ?></td>
      <td style="padding:8px;white-space:nowrap;"><?= htmlspecialchars((string)$m['criado_em']) ?></td>
      <td style="padding:8px;white-space:nowrap;">
        <?= $m['direcao'] === 'para_bling' ? 'Syscar → Bling' : 'Bling → Syscar' ?>
      </td>
      <td style="padding:8px;">
        <code><?= htmlspecialchars((string)$m['sku_codigo']) ?></code>
        <?php if (!empty($m['produto_nome'])): ?>
          <div style="color:var(--text-2);font-size:11px;">
            <?= htmlspecialchars(mb_substr((string)$m['produto_nome'], 0, 42)) ?>
          </div>
        <?php endif; ?>
      </td>
      <td style="padding:8px;"><?= $opCfg[$m['operacao']] ?? $m['operacao'] ?></td>
      <td style="padding:8px;text-align:right;"><?= rtrim(rtrim(number_format((float)$m['quantidade'], 2, ',', '.'), '0'), ',') ?></td>
      <td style="padding:8px;color:var(--text-2);"><?= htmlspecialchars((string)($m['canal_nome'] ?? '—')) ?></td>
      <td style="padding:8px;">
        <span class="badge" style="background:<?= $cfg[2] ?>;color:<?= $cfg[1] ?>;padding:2px 8px;border-radius:20px;">
          <?= $cfg[0] ?>
        </span>
        <?php if ($m['status'] === 'ignorado' && !empty($m['motivo_ignorado'])): ?>
          <div style="color:var(--text-3);font-size:11px;"><?= htmlspecialchars((string)$m['motivo_ignorado']) ?></div>
        <?php endif; ?>
        <?php if (!empty($m['tentativas']) && (int)$m['tentativas'] > 0): ?>
          <div style="color:var(--text-3);font-size:11px;"><?= (int)$m['tentativas'] ?>ª tentativa</div>
        <?php endif; ?>
      </td>
      <td style="padding:8px;text-align:right;white-space:nowrap;">
        <button type="button" class="btn btn-sm js-ver-mov" data-id="<?= (int)$m['id'] ?>">Ver</button>
        <?php if ($podeOperar && in_array($m['status'], ['falhou','pendente'], true)): ?>
          <button type="button" class="btn btn-sm js-reenviar" data-id="<?= (int)$m['id'] ?>">Reenviar</button>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php if ($totalPaginas > 1): ?>
<div style="display:flex;gap:6px;margin-top:14px;flex-wrap:wrap;">
  <?php for ($i = 1; $i <= $totalPaginas; $i++):
      $qs = http_build_query(array_merge($filtros, ['page' => $i])); ?>
    <a href="<?= ADMIN_URL ?>/estoque/ponte?<?= $qs ?>"
       class="btn btn-sm <?= $i === $page ? 'btn-primary' : '' ?>"><?= $i ?></a>
  <?php endfor; ?>
</div>
<?php endif; ?>

<script>
(function () {
  // Ver: só leitura. Abrir a tela NUNCA envia nada — é a lição do admin.loja,
  // onde listar o log reenviava movimentos ao Bling a cada visualização.
  $(document).on('click', '.js-ver-mov', function () {
    var id = $(this).data('id');
    var drawer = adminDrawer({
      titulo: 'Movimento #' + id,
      tamanho: 'lg',
      conteudo: '<p>Carregando…</p>'
    });

    $.getJSON(ADMIN_URL + '/estoque/ponte/movimento/' + id, function (res) {
      if (!res.ok) { drawer.setTexto(res.msg || 'Erro.'); return; }
      var m = res.movimento;

      var linhas = [
        ['Direção',    m.direcao === 'para_bling' ? 'Syscar → Bling' : 'Bling → Syscar'],
        ['SKU',        m.sku_codigo],
        ['Produto',    m.produto_nome || '—'],
        ['Operação',   m.operacao],
        ['Quantidade', m.quantidade],
        ['Valor',      m.valor || '—'],
        ['Pedido Bling', m.pedido_bling_id || '—'],
        ['Canal',      m.canal_nome || '—'],
        ['Ciclo',      m.ciclo || '—'],
        ['Status',     m.status],
        ['Tentativas', m.tentativas],
        ['Protocolo',  m.protocolo],
        ['Criado em',  m.criado_em],
        ['Confirmado em', m.confirmado_em || '—'],
        ['Último erro', m.ultimo_erro || '—'],
        ['Motivo ignorado', m.motivo_ignorado || '—']
      ];

      var $tb = $('<table style="width:100%;font-size:13px;border-collapse:collapse;"></table>');
      linhas.forEach(function (l) {
        var $tr = $('<tr></tr>');
        $('<td style="padding:6px 8px;color:var(--text-2);white-space:nowrap;vertical-align:top;"></td>').text(l[0]).appendTo($tr);
        $('<td style="padding:6px 8px;word-break:break-all;"></td>').text(String(l[1] == null ? '—' : l[1])).appendTo($tr);
        $tb.append($tr);
      });

      var $wrap = $('<div></div>').append($tb);

      if (m.evento_payload) {
        $wrap.append($('<h4 style="margin:16px 0 6px;font-size:13px;"></h4>')
              .text('Evento de origem (' + (m.evento_origem || '?') + ' · ' + (m.evento_tipo || '?') + ')'));
        $wrap.append($('<pre style="background:var(--bg);padding:10px;border-radius:6px;overflow:auto;font-size:12px;"></pre>')
              .text(m.evento_payload));
      }
      if (m.resposta) {
        $wrap.append($('<h4 style="margin:16px 0 6px;font-size:13px;"></h4>').text('Resposta do destino'));
        $wrap.append($('<pre style="background:var(--bg);padding:10px;border-radius:6px;overflow:auto;font-size:12px;"></pre>')
              .text(m.resposta));
      }

      drawer.setConteudo($wrap);
    });
  });

  // Reenviar: POST explícito, com CSRF. Nunca efeito colateral de leitura.
  $(document).on('click', '.js-reenviar', function () {
    var $b = $(this), id = $b.data('id');
    if (!confirm('Devolver o movimento #' + id + ' para a fila?')) return;

    $b.prop('disabled', true);
    $.post(ADMIN_URL + '/estoque/ponte/reenviar',
      { id: id, _csrf_token: CSRF_TOKEN },
      function (res) {
        if (window.Toast) {
          window.Toast[res.ok ? 'success' : 'error'](res.msg);
        } else {
          alert(res.msg);
        }
        if (res.ok) setTimeout(function () { location.reload(); }, 900);
        else $b.prop('disabled', false);
      }, 'json'
    ).fail(function () {
      if (window.Toast) window.Toast.error('Falha ao falar com o servidor.');
      $b.prop('disabled', false);
    });
  });
})();
</script>
