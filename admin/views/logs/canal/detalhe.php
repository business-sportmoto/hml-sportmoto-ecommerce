<?php
/** @var array $log @var array $historico @var array $contexto */
$base   = defined('BASE_URL') ? BASE_URL : '';
$rotaVolta = match($log['canal']??'') {
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
[$lbl,$badgeCls,$ico] = $statusBadge[$log['status']??''] ?? [$log['status'],'cl-badge-pendente','ti-circle'];
?>
<div class="cl-scope">

  <nav class="cl-breadcrumb">
    <a href="<?= $base ?>/admin/configuracoes">Configurações</a>
    <span class="sep">›</span>
    <a href="<?= $base . $rotaVolta ?>">Logs</a>
    <span class="sep">›</span>
    <span class="current">Detalhe #<?= (int)$log['id'] ?></span>
    <div class="spacer">
      <a href="<?= $base . $rotaVolta ?>" class="cl-btn cl-btn-sm">
        <i class="ti ti-arrow-left" aria-hidden="true"></i> Voltar
      </a>
    </div>
  </nav>

  <div class="cl-detail-grid">

    <div class="cl-card">
      <div class="cl-card-header">
        <span><i class="ti ti-send" style="font-size:14px;margin-right:6px;" aria-hidden="true"></i>Dados do envio</span>
        <span class="cl-badge <?= $badgeCls ?>">
          <i class="ti <?= $ico ?>" aria-hidden="true"></i><?= $lbl ?>
        </span>
      </div>
      <div class="cl-card-body">
        <table class="cl-def-table">
          <?php
          $campos = [
            'ID'           => '#' . $log['id'],
            'Canal'        => ucfirst($log['canal'] ?? '—'),
            'Tipo'         => '<span class="cl-type-chip">' . htmlspecialchars($log['tipo']??'') . '</span>',
            'Destinatário' => htmlspecialchars($log['destinatario'] ?? '—'),
            'Pedido'       => htmlspecialchars($log['pedido_codigo'] ?? '—'),
            'Via'          => htmlspecialchars($log['via'] ?? '—'),
            'Provider ID'  => htmlspecialchars($log['provider_msg_id'] ?? '—'),
            'Data'         => date('d/m/Y H:i:s', strtotime($log['criado_em']??'now')),
          ];
          foreach ($campos as $k => $v): ?>
            <tr>
              <td class="key"><?= $k ?></td>
              <td class="val"><?= $v ?></td>
            </tr>
          <?php endforeach; ?>
        </table>

        <?php if (!empty($log['erro_detalhe'])): ?>
          <div class="cl-error-block">
            <div class="title">Detalhe do erro</div>
            <div class="body"><?= htmlspecialchars($log['erro_detalhe']) ?></div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div style="display:flex;flex-direction:column;gap:16px;">
      <?php if (!empty($log['preview'])): ?>
      <div class="cl-card">
        <div class="cl-card-header">
          <span><i class="ti ti-message" style="font-size:14px;margin-right:6px;" aria-hidden="true"></i>Preview da mensagem</span>
        </div>
        <div class="cl-card-body">
          <p class="cl-preview"><?= htmlspecialchars($log['preview']) ?></p>
        </div>
      </div>
      <?php endif; ?>

      <?php if (!empty($contexto)): ?>
      <div class="cl-card">
        <div class="cl-card-header">
          <span><i class="ti ti-code" style="font-size:14px;margin-right:6px;" aria-hidden="true"></i>Contexto</span>
        </div>
        <div class="cl-card-body">
          <table class="cl-def-table" style="font-size:12px;">
            <?php foreach ($contexto as $k => $v): ?>
            <tr>
              <td class="key mono" style="font-size:11px;"><?= htmlspecialchars($k) ?></td>
              <td class="val"><?= htmlspecialchars(is_array($v) ? json_encode($v,JSON_UNESCAPED_UNICODE) : (string)$v) ?></td>
            </tr>
            <?php endforeach; ?>
          </table>
        </div>
      </div>
      <?php endif; ?>
    </div>

  </div>

  <?php if (!empty($historico) && count($historico) > 1): ?>
  <div class="cl-card">
    <div class="cl-card-header">
      <span>
        <i class="ti ti-history" style="font-size:14px;margin-right:6px;" aria-hidden="true"></i>
        Todos os envios — pedido <span class="mono" style="font-size:12px;"><?= htmlspecialchars($log['pedido_codigo']??'') ?></span>
      </span>
      <span style="font-size:12px;color:var(--cl-text-muted);"><?= count($historico) ?> envio(s)</span>
    </div>
    <table class="cl-table">
      <thead><tr>
        <th>Data</th><th>Canal</th><th>Tipo</th><th>Destinatário</th><th>Status</th>
      </tr></thead>
      <tbody>
      <?php foreach ($historico as $h):
        [$hl,$hb,$hi] = $statusBadge[$h['status']??''] ?? [$h['status'],'cl-badge-pendente','ti-circle'];
        $isAtual = $h['id'] == $log['id'];
      ?>
        <tr <?= $isAtual ? 'class="cl-row-current"' : '' ?>>
          <td class="muted" style="font-size:12px;"><?= date('d/m H:i', strtotime($h['criado_em'])) ?></td>
          <td><?= htmlspecialchars(ucfirst($h['canal'])) ?></td>
          <td><span class="cl-type-chip"><?= htmlspecialchars($h['tipo']) ?></span></td>
          <td style="font-size:12px;"><?= htmlspecialchars($h['destinatario']) ?></td>
          <td>
            <span class="cl-badge <?= $hb ?>">
              <i class="ti <?= $hi ?>" aria-hidden="true"></i><?= $hl ?>
            </span>
            <?php if ($isAtual): ?>
              <span style="font-size:10px;color:var(--cl-text-hint);margin-left:4px;">← atual</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

</div>
