<?php
/**
 * admin/views/admin/payment/index.php — Dashboard de Pagamentos
 *
 * Variáveis disponíveis:
 *   $dash → array retornado por PaymentDashboardService::coletar()
 *
 * Padrão visual: iOS × SaaS premium (idêntico ao dashboard de email-marketing
 * v2). Prefixo .pgto_ pra não colidir com .em_.
 */
$base = defined('BASE_URL') ? BASE_URL : '';
require_once __DIR__ . '/_helpers.php';

$janela = (int) ($dash['janela_dias'] ?? 30);
?>

<div class="pgto_wrapper pgto_dashboard" data-base="<?= htmlspecialchars($base) ?>">

  <!-- ─────────── Cabeçalho ─────────── -->
  <div class="pgto_header">
    <div>
      <h1>Painel de Pagamentos</h1>
      <p class="pgto_sub">Visão geral de transações, webhooks e saúde do gateway</p>
    </div>
    <div class="pgto_actions">
      <form method="get" class="pgto_janela_form">
        <label for="janela">Janela:</label>
        <select name="janela" id="janela" onchange="this.form.submit()">
          <?php foreach ([7, 14, 30, 60, 90] as $opt): ?>
            <option value="<?= $opt ?>" <?= $janela === $opt ? 'selected' : '' ?>>
              Últimos <?= $opt ?> dias
            </option>
          <?php endforeach; ?>
        </select>
      </form>
      <a href="<?= $base ?>/admin/payment/transacoes" class="pgto_btn">Transações</a>
      <a href="<?= $base ?>/admin/payment/webhooks"   class="pgto_btn">Webhooks</a>
    </div>
  </div>

  <!-- ─────────── Alertas ─────────── -->
  <?php if (!empty($dash['alertas'])): ?>
    <div class="pgto_alertas_box">
      <?php foreach ($dash['alertas'] as $a): ?>
        <div class="pgto_alerta pgto_alerta_<?= htmlspecialchars($a['nivel']) ?>">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <?php if ($a['nivel'] === 'erro'): ?>
              <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/>
              <line x1="12" y1="16" x2="12.01" y2="16"/>
            <?php else: ?>
              <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
              <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
            <?php endif; ?>
          </svg>
          <div>
            <strong><?= htmlspecialchars($a['titulo']) ?>:</strong>
            <?= htmlspecialchars($a['mensagem']) ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- ─────────── KPIs principais ─────────── -->
  <h2 class="pgto_section_title">Visão geral</h2>
  <div class="pgto_kpi_grid">
    <div class="pgto_card pgto_kpi">
      <span class="pgto_card_label">Total de transações</span>
      <span class="pgto_card_value"><?= pgto_int($dash['kpis']['total']) ?></span>
      <span class="pgto_card_footer"><?= pgto_variacao($dash['kpis']['variacao_total']) ?> vs período anterior</span>
    </div>
    <div class="pgto_card pgto_kpi">
      <span class="pgto_card_label">Aprovadas</span>
      <span class="pgto_card_value"><?= pgto_int($dash['kpis']['aprovadas']) ?></span>
      <span class="pgto_card_footer">
        <strong class="pgto_taxa"><?= pgto_pct($dash['kpis']['taxa_aprovacao']) ?></strong> taxa de aprovação
      </span>
    </div>
    <div class="pgto_card pgto_kpi">
      <span class="pgto_card_label">Volume aprovado</span>
      <span class="pgto_card_value"><?= pgto_money($dash['kpis']['volume_centavos']) ?></span>
      <span class="pgto_card_footer"><?= pgto_variacao($dash['kpis']['variacao_volume']) ?> vs período anterior</span>
    </div>
    <div class="pgto_card pgto_kpi">
      <span class="pgto_card_label">Pendentes</span>
      <span class="pgto_card_value"><?= pgto_int($dash['kpis']['pendentes']) ?></span>
      <span class="pgto_card_footer">
        Aguardando confirmação<?php if ($dash['kpis']['pendentes'] > 0): ?>
          · <a href="<?= $base ?>/admin/payment/transacoes?status=pendente">ver</a>
        <?php endif; ?>
      </span>
    </div>
    <div class="pgto_card pgto_kpi">
      <span class="pgto_card_label">Falhas / recusadas</span>
      <span class="pgto_card_value"><?= pgto_int($dash['kpis']['falhas']) ?></span>
      <span class="pgto_card_footer">
        <strong class="pgto_taxa"><?= pgto_pct($dash['kpis']['taxa_falha']) ?></strong> taxa de falha
      </span>
    </div>
    <div class="pgto_card pgto_kpi">
      <span class="pgto_card_label">Estornado / chargeback</span>
      <span class="pgto_card_value"><?= pgto_money($dash['kpis']['estornado_centavos']) ?></span>
      <span class="pgto_card_footer"><?= pgto_int($dash['kpis']['estornadas']) ?> transação(ões)</span>
    </div>
  </div>

  <!-- ─────────── Por método ─────────── -->
  <h2 class="pgto_section_title">Por método de pagamento</h2>
  <div class="pgto_card pgto_table_card">
    <table class="pgto_table">
      <thead>
        <tr>
          <th>Método</th>
          <th class="num">Total</th>
          <th class="num">Aprovadas</th>
          <th class="num">Taxa</th>
          <th class="num">Volume</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($dash['por_metodo'])): ?>
          <tr><td colspan="5" class="pgto_empty">Sem dados na janela selecionada.</td></tr>
        <?php else: ?>
          <?php foreach ($dash['por_metodo'] as $m): ?>
            <tr>
              <td><strong><?= htmlspecialchars(ucfirst($m['metodo'])) ?></strong></td>
              <td class="num"><?= pgto_int($m['total']) ?></td>
              <td class="num"><?= pgto_int($m['aprovadas']) ?></td>
              <td class="num">
                <span class="pgto_taxa_badge pgto_taxa_<?= $m['taxa_aprovacao'] >= 80 ? 'alta' : ($m['taxa_aprovacao'] >= 50 ? 'media' : 'baixa') ?>">
                  <?= pgto_pct($m['taxa_aprovacao']) ?>
                </span>
              </td>
              <td class="num"><?= pgto_money($m['volume_centavos']) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- ─────────── Por provedor ─────────── -->
  <h2 class="pgto_section_title">Por provedor</h2>
  <div class="pgto_card pgto_table_card">
    <table class="pgto_table">
      <thead>
        <tr>
          <th>Provedor</th>
          <th class="num">Total</th>
          <th class="num">Aprovadas</th>
          <th class="num">Taxa</th>
          <th class="num">Volume</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($dash['por_provedor'])): ?>
          <tr><td colspan="5" class="pgto_empty">Sem dados.</td></tr>
        <?php else: ?>
          <?php foreach ($dash['por_provedor'] as $p): ?>
            <tr>
              <td><strong><?= htmlspecialchars($p['provedor']) ?></strong></td>
              <td class="num"><?= pgto_int($p['total']) ?></td>
              <td class="num"><?= pgto_int($p['aprovadas']) ?></td>
              <td class="num">
                <span class="pgto_taxa_badge pgto_taxa_<?= $p['taxa_aprovacao'] >= 80 ? 'alta' : ($p['taxa_aprovacao'] >= 50 ? 'media' : 'baixa') ?>">
                  <?= pgto_pct($p['taxa_aprovacao']) ?>
                </span>
              </td>
              <td class="num"><?= pgto_money($p['volume_centavos']) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- ─────────── Série diária (sparkline) ─────────── -->
  <h2 class="pgto_section_title">Atividade diária</h2>
  <div class="pgto_card pgto_chart_card">
    <div class="pgto_chart_header">
      <div>
        <span class="pgto_card_label">Transações por dia</span>
        <span class="pgto_chart_legend">
          <span class="pgto_legend_dot pgto_legend_total"></span> Total
          <span class="pgto_legend_dot pgto_legend_aprovadas"></span> Aprovadas
        </span>
      </div>
    </div>
    <svg id="pgto-chart" class="pgto_chart_svg" viewBox="0 0 800 220" preserveAspectRatio="none"></svg>
    <script>
      (function() {
        var serie = <?= json_encode($dash['serie_diaria'], JSON_NUMERIC_CHECK) ?>;
        if (!serie || !serie.length) return;
        var maxTotal = Math.max.apply(null, serie.map(function(d) { return d.total; })) || 1;
        var w = 800, h = 200, pad = 16;
        var stepX = (w - pad * 2) / Math.max(1, serie.length - 1);
        var scaleY = function(v) { return h - pad - (v / maxTotal) * (h - pad * 2); };

        function path(getValue) {
          return serie.map(function(d, i) {
            var x = pad + i * stepX;
            var y = scaleY(getValue(d));
            return (i === 0 ? 'M' : 'L') + x.toFixed(1) + ',' + y.toFixed(1);
          }).join(' ');
        }

        var pathTotal = path(function(d){ return d.total; });
        var pathAprovadas = path(function(d){ return d.aprovadas; });
        var areaTotal = pathTotal + ' L' + (pad + stepX * (serie.length - 1)) + ',' + (h - pad) + ' L' + pad + ',' + (h - pad) + ' Z';

        var svg = document.getElementById('pgto-chart');
        if (!svg) return;
        svg.innerHTML =
          '<defs><linearGradient id="pgto-grad" x1="0" x2="0" y1="0" y2="1">' +
          '<stop offset="0%" stop-color="var(--pgto-blue, #0a66c2)" stop-opacity="0.12"/>' +
          '<stop offset="100%" stop-color="var(--pgto-blue, #0a66c2)" stop-opacity="0"/>' +
          '</linearGradient></defs>' +
          '<path d="' + areaTotal + '" fill="url(#pgto-grad)"/>' +
          '<path d="' + pathTotal + '" fill="none" stroke="var(--pgto-blue, #0a66c2)" stroke-width="2"/>' +
          '<path d="' + pathAprovadas + '" fill="none" stroke="var(--pgto-green, #16a34a)" stroke-width="2" stroke-dasharray="2,2"/>';
      })();
    </script>
    <div class="pgto_chart_footer">
      <?php
        $primeiro = $dash['serie_diaria'][0] ?? null;
        $ultimo   = end($dash['serie_diaria']);
        if ($primeiro && $ultimo):
      ?>
        <small><?= date('d/m', strtotime($primeiro['dia'])) ?> — <?= date('d/m', strtotime($ultimo['dia'])) ?></small>
      <?php endif; ?>
    </div>
  </div>

  <!-- ─────────── Saúde dos webhooks ─────────── -->
  <h2 class="pgto_section_title">Saúde dos webhooks</h2>
  <div class="pgto_webhooks_health">
    <div class="pgto_card pgto_kpi">
      <span class="pgto_card_label">Recebidos (24h)</span>
      <span class="pgto_card_value"><?= pgto_int($dash['webhooks_saude']['ultimas_24h']) ?></span>
      <span class="pgto_card_footer">
        <?= pgto_int($dash['webhooks_saude']['ok_24h']) ?> processados ·
        <?= pgto_int($dash['webhooks_saude']['pendentes_24h']) ?> pendentes
      </span>
    </div>
    <div class="pgto_card pgto_kpi">
      <span class="pgto_card_label">Travados há +1h</span>
      <span class="pgto_card_value <?= $dash['webhooks_saude']['travados_long'] > 0 ? 'pgto_warning' : '' ?>">
        <?= pgto_int($dash['webhooks_saude']['travados_long']) ?>
      </span>
      <span class="pgto_card_footer">
        <?php if ($dash['webhooks_saude']['travados_long'] > 0): ?>
          <a href="<?= $base ?>/admin/payment/webhooks?processado=0">investigar</a>
        <?php else: ?>
          Tudo certo
        <?php endif; ?>
      </span>
    </div>
    <div class="pgto_card pgto_kpi">
      <span class="pgto_card_label">Assinatura inválida (24h)</span>
      <span class="pgto_card_value <?= $dash['webhooks_saude']['assinatura_invalida'] > 0 ? 'pgto_warning' : '' ?>">
        <?= pgto_int($dash['webhooks_saude']['assinatura_invalida']) ?>
      </span>
      <span class="pgto_card_footer">Tentativas rejeitadas</span>
    </div>
  </div>

  <!-- ─────────── Inbox de últimas transações ─────────── -->
  <h2 class="pgto_section_title">Últimas transações</h2>
  <div class="pgto_card pgto_table_card">
    <table class="pgto_table">
      <thead>
        <tr>
          <th>Pedido</th>
          <th>Método</th>
          <th>Status</th>
          <th class="num">Valor</th>
          <th>Quando</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($dash['ultimas_acoes'])): ?>
          <tr><td colspan="6" class="pgto_empty">Nenhuma transação ainda.</td></tr>
        <?php else: ?>
          <?php foreach ($dash['ultimas_acoes'] as $u): ?>
            <tr>
              <td><code><?= htmlspecialchars($u['order_id_loja']) ?></code></td>
              <td><?= htmlspecialchars(ucfirst($u['metodo'])) ?></td>
              <td>
                <span class="pgto_status pgto_status_<?= htmlspecialchars($u['status']) ?>">
                  <?= htmlspecialchars($u['status']) ?>
                </span>
              </td>
              <td class="num"><?= pgto_money((int) $u['valor_centavos']) ?></td>
              <td>
                <span title="<?= htmlspecialchars($u['criado_em']) ?>">
                  <?= date('d/m H:i', strtotime($u['criado_em'])) ?>
                </span>
              </td>
              <td class="actions">
                <a href="<?= $base ?>/admin/payment/transacoes/<?= (int) $u['id'] ?>" class="pgto_link_chevron">
                  detalhes
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                       stroke-width="2.5" stroke-linecap="round"><polyline points="9 18 15 12 9 6"/></svg>
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

</div>
