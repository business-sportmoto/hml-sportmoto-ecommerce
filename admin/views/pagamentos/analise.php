<?php
// admin/views/pagamentos/analise.php
// $pedidos, $total e os campos de paginação — do AdminAnaliseController

$tierCor = [
    'bronze'   => ['#92400e', '#fffbeb'],
    'silver'   => ['#475569', '#f8fafc'],
    'gold'     => ['#b45309', '#fffbeb'],
    'platinum' => ['#1d4ed8', '#eff6ff'],
];
?>

<div class="admin-page">

  <div class="admin-page-header">
    <div>
      <a href="<?= ADMIN_URL ?>/payment" class="back-link">← Painel de pagamentos</a>
      <h1 class="admin-page-title">
        Análise de pedidos
        <?php if ($total > 0): ?>
          <span style="background:#fffbeb;color:#b45309;padding:3px 12px;border-radius:20px;
                       font-size:13px;font-weight:700;margin-left:8px;"><?= (int) $total ?></span>
        <?php endif; ?>
      </h1>
      <p class="admin-page-sub">
        Pedidos retidos pelo antifraude, do mais antigo para o mais recente —
        cliente esperando é o que decide a ordem da fila.
      </p>
    </div>
  </div>

  <?php if (!$pedidos): ?>
    <div class="admin-card" style="padding:50px;text-align:center;color:var(--c-text-muted);">
      <div style="font-size:15px;font-weight:600;margin-bottom:6px;">Nenhum pedido aguardando</div>
      A fila está vazia.
    </div>
  <?php else: ?>

  <div class="admin-card" style="padding:0;">
    <table class="admin-table" style="margin:0;">
      <thead>
        <tr>
          <th>Pedido</th><th>Cliente</th><th>Motivo da retenção</th>
          <th class="num">Valor</th><th>Esperando</th><th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($pedidos as $p):
          $af    = $p['antifraude'] ?? null;
          $tier  = (string) ($p['tier'] ?? 'bronze');
          [$tc, $tb] = $tierCor[$tier] ?? $tierCor['bronze'];
          $horas = max(0, (int) round((time() - strtotime((string) $p['criado_em'])) / 3600));
      ?>
        <tr>
          <td>
            <strong><?= View::e($p['codigo']) ?></strong>
            <div style="font-size:11px;color:var(--c-text-muted);">
              <?= View::e($p['forma_pagamento'] ?? '—') ?>
            </div>
          </td>

          <td>
            <?= View::e($p['cliente_nome'] ?? 'Cliente removido') ?>
            <div style="font-size:11px;color:var(--c-text-muted);margin-top:2px;">
              <span style="background:<?= $tb ?>;color:<?= $tc ?>;padding:1px 7px;border-radius:20px;
                           font-size:10px;font-weight:700;"><?= strtoupper($tier) ?></span>
              <?= (int) ($p['score_total'] ?? 0) ?> pts
              <?php if ((int) ($p['penalidade_pontos'] ?? 0) > 0): ?>
                <span style="color:#b91c1c;">−<?= (int) $p['penalidade_pontos'] ?></span>
              <?php endif; ?>
              · <?= (int) ($p['total_pedidos'] ?? 0) ?> pedidos
              <?php if ((int) ($p['total_chargebacks'] ?? 0) > 0): ?>
                · <span style="color:#b91c1c;font-weight:700;"><?= (int) $p['total_chargebacks'] ?> CB</span>
              <?php endif; ?>
              <?php if (!empty($p['fraude_confirmada'])): ?>
                · <span style="color:#b91c1c;font-weight:700;">FRAUDE</span>
              <?php endif; ?>
            </div>
          </td>

          <td style="max-width:320px;">
            <?php if ($af): ?>
              <span style="font-size:11px;font-weight:700;color:#b45309;">
                <?= View::e($af['regra_aplicada'] ?? '—') ?>
              </span>
              <div style="font-size:12px;color:var(--c-text-muted);margin-top:2px;">
                <?= View::e(mb_substr((string) ($af['motivo_pre'] ?? ''), 0, 90)) ?>
              </div>
              <?php if (!empty($af['enviado_em'])): ?>
                <div style="font-size:11px;color:#1d4ed8;margin-top:3px;">
                  ClearSale: <?= View::e($af['recomendacao'] ?? '—') ?>
                  <?php if ($af['score'] !== null): ?>
                    · score <?= number_format((float) $af['score'], 0) ?>
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <div style="font-size:11px;color:var(--c-text-muted);margin-top:3px;">
                  sem consulta à ClearSale
                </div>
              <?php endif; ?>
            <?php else: ?>
              <span style="color:var(--c-text-muted);font-size:12px;">
                Sem registro de antifraude — retido manualmente?
              </span>
            <?php endif; ?>
          </td>

          <td class="num">R$ <?= number_format((float) $p['total'], 2, ',', '.') ?></td>

          <td>
            <span style="font-size:12px;<?= $horas >= 24 ? 'color:#b91c1c;font-weight:700;' : 'color:var(--c-text-muted);' ?>">
              <?= $horas < 24 ? $horas . 'h' : floor($horas / 24) . 'd' ?>
            </span>
          </td>

          <td class="num">
            <a href="<?= ADMIN_URL ?>/pagamentos/analise/<?= (int) $p['id'] ?>"
               class="btn btn-primary" style="padding:5px 14px;font-size:12px;">Analisar</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if (!empty($hasPages)): ?>
    <?= View::partial('partials/pagination', compact('pages', 'currentPage', 'totalPages')) ?>
  <?php endif; ?>

  <?php endif; ?>
</div>
