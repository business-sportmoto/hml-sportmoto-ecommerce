<?php
/** @var array $itens @var int $total @var int $pag @var int $paginas
 *  @var array $kpis @var array $canais @var array $tipos @var array $filtros
 *  @var string|null $canal_fixo @var string $titulo */
$base     = defined('BASE_URL') ? BASE_URL : '';
$rotaBase = match($canal_fixo) {
    'whatsapp' => '/admin/configuracoes/logs/whatsapp',
    'email'    => '/admin/configuracoes/logs/email-transacional',
    default    => '/admin/configuracoes/logs/canais',
};

$statusBadge = [
    'enviado'   => ['Enviado',    'cl-badge-enviado',   'ti-check'],
    'erro'      => ['Erro',       'cl-badge-erro',      'ti-x'],
    'sem_canal' => ['Sem canal',  'cl-badge-sem-canal', 'ti-alert-triangle'],
    'cancelado' => ['Cancelado',  'cl-badge-cancelado', 'ti-ban'],
    'pendente'  => ['Pendente',   'cl-badge-pendente',  'ti-clock'],
];

$canalIcone = ['whatsapp'=>'ti-brand-whatsapp','email'=>'ti-mail','sms'=>'ti-message','push'=>'ti-bell'];

function clFmtTel(string $t): string {
    $t = preg_replace('/\D/', '', $t);
    if (strlen($t) >= 12 && substr($t,0,2)==='55') {
        $d=substr($t,2,2); $n=substr($t,4);
        if (strlen($n)===9) return "({$d}) ".substr($n,0,5).'-'.substr($n,5);
        if (strlen($n)===8) return "({$d}) ".substr($n,0,4).'-'.substr($n,4);
    }
    return $t ?: '—';
}
?>
<div class="cl-scope">

  <nav class="cl-breadcrumb">
    <a href="<?= $base ?>/admin/configuracoes">Configurações</a>
    <span class="sep">›</span>
    <span>Logs</span>
    <span class="sep">›</span>
    <span class="current"><?= htmlspecialchars($titulo) ?></span>
    <div class="spacer">
      <a href="<?= $base ?>/admin/configuracoes/logs/canais"
         class="cl-btn cl-btn-tab cl-btn-sm <?= $canal_fixo===null?'active':'' ?>">Todos</a>
      <a href="<?= $base ?>/admin/configuracoes/logs/whatsapp"
         class="cl-btn cl-btn-tab cl-btn-sm <?= $canal_fixo==='whatsapp'?'active':'' ?>">
        <i class="ti ti-brand-whatsapp" aria-hidden="true"></i> WhatsApp</a>
      <a href="<?= $base ?>/admin/configuracoes/logs/email-transacional"
         class="cl-btn cl-btn-tab cl-btn-sm <?= $canal_fixo==='email'?'active':'' ?>">
        <i class="ti ti-mail" aria-hidden="true"></i> Email</a>
    </div>
  </nav>

  <div class="cl-kpi-grid">
    <div class="cl-kpi">
      <div class="cl-kpi-label">Total (30d)</div>
      <div class="cl-kpi-value"><?= number_format((int)($kpis['total']??0),0,',','.') ?></div>
    </div>
    <div class="cl-kpi">
      <div class="cl-kpi-label">Enviados</div>
      <div class="cl-kpi-value green"><?= number_format((int)($kpis['enviados']??0),0,',','.') ?></div>
    </div>
    <div class="cl-kpi">
      <div class="cl-kpi-label">Sem canal</div>
      <div class="cl-kpi-value amber"><?= number_format((int)($kpis['sem_canal']??0),0,',','.') ?></div>
    </div>
    <div class="cl-kpi">
      <div class="cl-kpi-label">Erros</div>
      <div class="cl-kpi-value red"><?= number_format((int)($kpis['erros']??0),0,',','.') ?></div>
    </div>
    <div class="cl-kpi">
      <div class="cl-kpi-label">Taxa sucesso</div>
      <div class="cl-kpi-value blue"><?= $kpis['taxa_sucesso'] ?? '—' ?>%</div>
    </div>
    <div class="cl-kpi">
      <div class="cl-kpi-label">Pedidos distintos</div>
      <div class="cl-kpi-value"><?= number_format((int)($kpis['pedidos_distintos']??0),0,',','.') ?></div>
    </div>
  </div>

  <div class="cl-search-box">
    <div class="cl-search-box-row">
      <i class="ti ti-package-search" style="font-size:16px;color:var(--cl-text-muted);" aria-hidden="true"></i>
      <span class="cl-search-box-label">Buscar por pedido</span>
      <input type="text" id="buscaPedidoInput" placeholder="Ex: SM-001" class="cl-input-short">
      <button type="button" class="cl-btn cl-btn-primary" id="btnBuscaPedido">
        <i class="ti ti-search" aria-hidden="true"></i> Buscar
      </button>
      <span class="cl-search-box-hint">Pesquisa em todos os canais</span>
    </div>
    <div class="cl-search-resultado" id="buscaPedidoResultado"></div>
  </div>

  <form method="get" class="cl-filters">
    <?php if ($canal_fixo): ?>
      <input type="hidden" name="canal" value="<?= htmlspecialchars($canal_fixo) ?>">
    <?php endif; ?>

    <input type="text" name="busca" class="cl-input-wide"
           value="<?= htmlspecialchars($filtros['busca']??'') ?>"
           placeholder="Buscar destinatário ou mensagem…">

    <?php if (!$canal_fixo): ?>
    <select name="canal" onchange="this.form.submit()">
      <option value="">Todos os canais</option>
      <?php foreach ($canais as $c): ?>
        <option value="<?= htmlspecialchars($c) ?>" <?= ($filtros['canal']??'')===$c?'selected':'' ?>>
          <?= htmlspecialchars(ucfirst($c)) ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>

    <select name="status" onchange="this.form.submit()">
      <option value="">Todos os status</option>
      <?php foreach ($statusBadge as $v=>[$lbl,,]): ?>
        <option value="<?= $v ?>" <?= ($filtros['status']??'')===$v?'selected':'' ?>><?= $lbl ?></option>
      <?php endforeach; ?>
    </select>

    <select name="tipo" onchange="this.form.submit()">
      <option value="">Todos os tipos</option>
      <?php foreach ($tipos as $t): ?>
        <option value="<?= htmlspecialchars($t) ?>" <?= ($filtros['tipo']??'')===$t?'selected':'' ?>>
          <?= htmlspecialchars($t) ?></option>
      <?php endforeach; ?>
    </select>

    <input type="date" name="data_inicio" class="cl-input-date"
           value="<?= htmlspecialchars($filtros['data_inicio']??'') ?>" title="De">
    <input type="date" name="data_fim" class="cl-input-date"
           value="<?= htmlspecialchars($filtros['data_fim']??'') ?>" title="Até">

    <button type="submit" class="cl-btn">
      <i class="ti ti-filter" aria-hidden="true"></i> Filtrar
    </button>
    <?php if (array_filter($filtros)): ?>
      <a href="<?= $base . $rotaBase ?>" class="cl-btn">Limpar</a>
    <?php endif; ?>

    <div class="cl-filters-spacer"></div>
    <a href="<?= htmlspecialchars($base . $rotaBase . '/exportar?' . http_build_query(array_filter($filtros))) ?>"
       class="cl-btn">
      <i class="ti ti-download" aria-hidden="true"></i> Exportar CSV
    </a>
  </form>

  <div class="cl-info-row">
    <span><?= number_format($total,0,',','.') ?> registro<?= $total!==1?'s':'' ?>
    <?php if (array_filter($filtros)): ?>
      — <a href="<?= $base . $rotaBase ?>">limpar filtros</a>
    <?php endif; ?>
    </span>
  </div>

  <div class="cl-table-wrap">
    <table class="cl-table fixed">
      <colgroup>
        <col class="col-id"><col class="col-canal"><col class="col-tipo">
        <col class="col-dest"><col class="col-pedido"><col class="col-status">
        <col class="col-auto"><col class="col-acoes">
      </colgroup>
      <thead><tr>
        <th class="col-id">ID</th>
        <th class="col-canal">Canal</th>
        <th class="col-tipo">Tipo</th>
        <th class="col-dest">Destinatário</th>
        <th class="col-pedido">Pedido</th>
        <th class="col-status">Status</th>
        <th class="col-auto">Preview</th>
        <th class="col-acoes">Ação</th>
      </tr></thead>
      <tbody>
      <?php if (empty($itens)): ?>
        <tr><td colspan="8" class="cl-table-empty">
          <i class="ti ti-inbox" style="font-size:24px;display:block;margin-bottom:8px;" aria-hidden="true"></i>
          Nenhum registro encontrado
        </td></tr>
      <?php else: foreach ($itens as $item):
        [$lbl,$badgeCls,$ico] = $statusBadge[$item['status']] ?? [$item['status'],'cl-badge-pendente','ti-circle'];
        $cIco = $canalIcone[$item['canal']] ?? 'ti-send';
        $preview = htmlspecialchars(str_replace(["\r","\n"],' ',$item['preview']??''));
      ?>
        <tr>
          <td class="col-id muted">#<?= (int)$item['id'] ?></td>
          <td class="col-canal">
            <span class="cl-canal-chip">
              <i class="ti <?= $cIco ?>" aria-hidden="true"></i>
              <?= htmlspecialchars($item['canal']) ?>
            </span>
          </td>
          <td class="col-tipo truncate">
            <span class="cl-type-chip" title="<?= htmlspecialchars($item['tipo']) ?>">
              <?= htmlspecialchars($item['tipo']) ?>
            </span>
          </td>
          <td class="col-dest truncate" style="font-size:12px;">
            <?= htmlspecialchars($item['canal']==='whatsapp' ? clFmtTel($item['destinatario']) : $item['destinatario']) ?>
          </td>
          <td class="col-pedido">
            <?php if (!empty($item['pedido_codigo'])): ?>
              <a href="<?= $base ?>/admin/configuracoes/logs/canais/detalhe?id=<?= (int)$item['id'] ?>"
                 class="cl-link mono"><?= htmlspecialchars($item['pedido_codigo']) ?></a>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
          <td class="col-status">
            <span class="cl-badge <?= $badgeCls ?>">
              <i class="ti <?= $ico ?>" aria-hidden="true"></i><?= $lbl ?>
            </span>
            <?php if ($item['status']==='erro' && !empty($item['erro_detalhe'])): ?>
              <div class="cl-err-hint" title="<?= htmlspecialchars($item['erro_detalhe']) ?>">
                <?= htmlspecialchars(mb_substr($item['erro_detalhe'],0,32)) ?>…
              </div>
            <?php endif; ?>
          </td>
          <td class="col-auto truncate muted" style="font-size:12px;"><?= $preview ?></td>
          <td class="col-acoes">
            <a href="<?= $base ?>/admin/configuracoes/logs/canais/detalhe?id=<?= (int)$item['id'] ?>"
               class="cl-btn cl-btn-sm">Ver</a>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>

    <?php if ($paginas > 1): ?>
    <div class="cl-pager">
      <span>Página <?= $pag ?> de <?= $paginas ?> · <?= number_format($total,0,',','.') ?> registros</span>
      <div class="cl-pager-btns">
        <?php for ($p = max(1,$pag-2); $p <= min($paginas,$pag+2); $p++): ?>
          <a href="?<?= http_build_query(array_filter($filtros)+['pag'=>$p]) ?>"
             class="cl-pager-btn <?= $p===$pag?'active':'' ?>"><?= $p ?></a>
        <?php endfor; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

</div>

<script>
(function($){
  var base = '<?= addslashes($base) ?>';
  $('#btnBuscaPedido').on('click', function(){
    var q = $.trim($('#buscaPedidoInput').val());
    if (!q) return;
    var $b = $(this).prop('disabled',true).html('<i class="ti ti-loader" aria-hidden="true"></i> Buscando…');
    $.getJSON(base+'/admin/configuracoes/logs/canais/busca-pedido', {q:q}, function(r){
      $b.prop('disabled',false).html('<i class="ti ti-search" aria-hidden="true"></i> Buscar');
      var $res = $('#buscaPedidoResultado').show();
      if (!r.ok || !r.itens.length){
        $res.html('<p style="font-size:13px;color:var(--cl-text-muted);padding:8px 0;">'+(r.erro||'Nenhum registro encontrado para "'+$('<span>').text(q).html()+'". ')+'</p>');
        return;
      }
      var sb={enviado:'cl-badge-enviado',erro:'cl-badge-erro',sem_canal:'cl-badge-sem-canal',cancelado:'cl-badge-cancelado',pendente:'cl-badge-pendente'};
      var sl={enviado:'Enviado',erro:'Erro',sem_canal:'Sem canal',cancelado:'Cancelado',pendente:'Pendente'};
      var h='<p style="font-size:12px;color:var(--cl-text-muted);margin-bottom:8px;">'+r.total+' resultado(s) para <strong>'+$('<span>').text(q).html()+'</strong></p>';
      h+='<table class="cl-table" style="font-size:12px;"><thead><tr><th>Data</th><th>Canal</th><th>Tipo</th><th>Destinatário</th><th>Pedido</th><th>Status</th><th></th></tr></thead><tbody>';
      $.each(r.itens,function(i,row){
        var st=sb[row.status]||'cl-badge-pendente', lbl=sl[row.status]||row.status;
        h+='<tr><td class="muted">'+row.criado_em.slice(0,16).replace('T',' ')+'</td>';
        h+='<td>'+row.canal+'</td>';
        h+='<td><span class="cl-type-chip">'+row.tipo+'</span></td>';
        h+='<td>'+row.destinatario+'</td>';
        h+='<td class="mono">'+(row.pedido_codigo||'—')+'</td>';
        h+='<td><span class="cl-badge '+st+'">'+lbl+'</span></td>';
        h+='<td><a href="'+base+'/admin/configuracoes/logs/canais/detalhe?id='+row.id+'" class="cl-btn cl-btn-sm">Ver</a></td></tr>';
      });
      h+='</tbody></table>';
      $res.html(h);
    }).fail(function(){ $b.prop('disabled',false).html('<i class="ti ti-search" aria-hidden="true"></i> Buscar'); });
  });
  $('#buscaPedidoInput').on('keydown',function(e){ if(e.key==='Enter'){e.preventDefault();$('#btnBuscaPedido').click();} });
})(jQuery);
</script>
