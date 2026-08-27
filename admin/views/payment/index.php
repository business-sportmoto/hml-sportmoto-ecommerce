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
      <?php
        $emAnalise = 0;
        try {
            $emAnalise = (int) Database::getInstance()->getConnection()
                ->query("SELECT COUNT(*) FROM pedidos WHERE status_pedido = 'em_analise'")
                ->fetchColumn();
        } catch (Throwable) {}
      ?>
      <a href="<?= $base ?>/admin/pagamentos/analise" class="pgto_btn"
         <?= $emAnalise > 0 ? 'style="background:#fffbeb;color:#b45309;font-weight:700;"' : '' ?>>
        Análise<?= $emAnalise > 0 ? ' (' . $emAnalise . ')' : '' ?>
      </a>
      <a href="<?= $base ?>/admin/pagamentos/fluxos"      class="pgto_btn">Fluxos</a>
      <a href="<?= $base ?>/admin/pagamentos/formas"      class="pgto_btn">Formas</a>
      <a href="<?= $base ?>/admin/pagamentos/adquirentes" class="pgto_btn">Adquirentes</a>
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

  <!-- ─────────── Parcelamento mais usado ─────────── -->
  <!-- Conta apenas tentativas APROVADAS: parcela escolhida numa transacao
       negada e tentativa frustrada, nao preferencia do cliente. -->
  <h2 class="pgto_section_title">Parcelamento mais usado</h2>
  <div class="pgto_card pgto_table_card">
    <table class="pgto_table">
      <thead>
        <tr><th>Parcelas</th><th class="num">Aprovações</th><th class="num">Participação</th><th class="num">Volume</th></tr>
      </thead>
      <tbody>
        <?php if (empty($dash['parcelas'])): ?>
          <tr><td colspan="4" class="pgto_empty">Sem dados no período.</td></tr>
        <?php else: foreach ($dash['parcelas'] as $p): ?>
          <tr>
            <td><strong><?= (int) $p['parcelas'] ?>x</strong></td>
            <td class="num"><?= pgto_int($p['total']) ?></td>
            <td class="num">
              <div style="display:flex;align-items:center;gap:8px;justify-content:flex-end;">
                <div style="flex:0 0 90px;height:6px;background:#f1f5f9;border-radius:3px;overflow:hidden;">
                  <div style="width:<?= (float) $p['percentual'] ?>%;height:100%;background:#2563eb;"></div>
                </div>
                <span><?= number_format((float) $p['percentual'], 1, ',', '.') ?>%</span>
              </div>
            </td>
            <td class="num"><?= pgto_money((int) $p['volume_centavos']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- ─────────── Eficácia do fallback ─────────── -->
  <?php if (!empty($dash['fallback']['pedidos_multi'])): ?>
  <h2 class="pgto_section_title">Fallback entre adquirentes</h2>
  <div class="pgto_cards">
    <div class="pgto_card">
      <span class="pgto_card_label">Pedidos com 2+ tentativas</span>
      <span class="pgto_card_value"><?= pgto_int($dash['fallback']['pedidos_multi']) ?></span>
      <span class="pgto_card_footer">a primeira adquirente falhou</span>
    </div>
    <div class="pgto_card">
      <span class="pgto_card_label">Resgatados pela seguinte</span>
      <span class="pgto_card_value"><?= pgto_int($dash['fallback']['salvos']) ?></span>
      <span class="pgto_card_footer">
        <strong class="pgto_taxa"><?= $dash['fallback']['taxa_resgate'] !== null
          ? number_format((float) $dash['fallback']['taxa_resgate'], 1, ',', '.') . '%' : '—' ?></strong>
        taxa de resgate
      </span>
    </div>
  </div>
  <?php endif; ?>

  <!-- ─────────── Log de erro por adquirente ─────────── -->
  <!-- Vem de pgto_tentativas, nao de pgto_transacoes: aqui aparecem tambem as
       tentativas que falharam e nunca viraram cobranca. -->
  <h2 class="pgto_section_title">Erros por adquirente</h2>
  <div class="pgto_card pgto_table_card">
    <table class="pgto_table">
      <thead>
        <tr>
          <th>Adquirente</th><th>Motivo</th><th>Código</th>
          <th class="num">Ocorrências</th><th>Último</th><th>Detalhe</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($dash['erros_adquirente'])): ?>
          <tr><td colspan="6" class="pgto_empty">Nenhuma falha no período.</td></tr>
        <?php else: foreach ($dash['erros_adquirente'] as $e): ?>
          <tr>
            <td><strong><?= htmlspecialchars($e['adquirente_codigo']) ?></strong></td>
            <td>
              <span style="padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700;
                    background:<?= $e['e_tecnico'] ? '#fef2f2' : '#f8fafc' ?>;
                    color:<?= $e['e_tecnico'] ? '#b91c1c' : '#475569' ?>;">
                <?= $e['e_tecnico'] ? 'TÉCNICO' : 'EMISSOR' ?>
              </span>
              <?= htmlspecialchars($e['classe_erro'] ?? '—') ?>
            </td>
            <td><?= htmlspecialchars($e['codigo_adquirente'] ?? '—') ?></td>
            <td class="num"><?= pgto_int($e['total']) ?></td>
            <td style="font-size:12px;color:#64748b;">
              <?= $e['ultimo'] ? date('d/m H:i', strtotime($e['ultimo'])) : '—' ?>
            </td>
            <td style="font-size:12px;color:#64748b;max-width:280px;">
              <?= htmlspecialchars(mb_substr((string) $e['ultima_mensagem'], 0, 90)) ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
    <p style="font-size:11.5px;color:#64748b;padding:10px 14px;margin:0;">
      <strong>TÉCNICO</strong> é falha nossa ou da adquirente — cai para outra e vale investigar.
      <strong>EMISSOR</strong> é decisão do banco do cliente — não é retentado, por regra das bandeiras.
    </p>
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
