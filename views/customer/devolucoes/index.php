<?php
// views/customer/devolucoes/index.php
$statusMap = [
    'solicitado'             => ['cor'=>'warning', 'label'=>'Aguardando análise',   'icon'=>'clock'],
    'pre_aprovado'           => ['cor'=>'success', 'label'=>'Pré-aprovado',         'icon'=>'check-circle'],
    'aguardando_aprovacao'   => ['cor'=>'warning', 'label'=>'Em análise',           'icon'=>'clock'],
    'aprovado'               => ['cor'=>'info',    'label'=>'Aprovado',             'icon'=>'check-circle'],
    'negado'                 => ['cor'=>'danger',  'label'=>'Negado',               'icon'=>'x-circle'],
    'aguardando_postagem'    => ['cor'=>'warning', 'label'=>'Postar produto',       'icon'=>'package'],
    'em_transito_reverso'    => ['cor'=>'primary', 'label'=>'Em trânsito',          'icon'=>'truck'],
    'item_recebido'          => ['cor'=>'info',    'label'=>'Item recebido',        'icon'=>'inbox'],
    'inspecionado_aprovado'  => ['cor'=>'success', 'label'=>'Inspeção aprovada',   'icon'=>'check-circle'],
    'inspecionado_reprovado' => ['cor'=>'danger',  'label'=>'Inspeção reprovada',  'icon'=>'x-circle'],
    'concluido'              => ['cor'=>'success', 'label'=>'Concluído',            'icon'=>'check-circle'],
    'concluido_reprovado'    => ['cor'=>'danger',  'label'=>'Encerrado',            'icon'=>'x-circle'],
    'cancelado'              => ['cor'=>'danger',  'label'=>'Cancelado',            'icon'=>'x-circle'],
    'expirado'               => ['cor'=>'gray',    'label'=>'Expirado',             'icon'=>'clock'],
];
?>

<div class="customer-page">
  <div class="customer-page-header">
    <div>
      <h1>Minhas devoluções</h1>
      <p class="customer-page-sub">Acompanhe suas solicitações de devolução e troca.</p>
    </div>
  </div>

  <?php if (empty($lista)): ?>
  <div class="empty-state od-card" style="padding:60px 20px;">
    <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round">
      <polyline points="17 1 21 5 17 9"/>
      <path d="M3 11V9a4 4 0 014-4h14"/>
      <polyline points="7 23 3 19 7 15"/>
      <path d="M21 13v2a4 4 0 01-4 4H3"/>
    </svg>
    <strong>Nenhuma solicitação ainda</strong>
    <p style="font-size:14px;color:var(--c-text-muted);margin:0;">
      Para solicitar uma devolução, acesse o detalhe do pedido.
    </p>
    <a href="<?= BASE_URL ?>/minha-conta/pedidos" class="btn btn-primary" style="margin-top:16px;">
      Ver meus pedidos
    </a>
  </div>
  <?php else: ?>

  <div style="display:flex;flex-direction:column;gap:12px;">
    <?php foreach ($lista as $s):
      $st    = $statusMap[$s['status']] ?? ['cor'=>'info','label'=>$s['status'],'icon'=>'circle'];
      $ativa = !in_array($s['status'], ['concluido','concluido_reprovado','cancelado','expirado','negado']);
    ?>
    <a href="<?= BASE_URL ?>/minha-conta/devolucao/<?= (int)$s['id'] ?>"
       class="dev-card <?= $ativa ? 'dev-card--ativa' : '' ?>">

      <!-- Ícone de tipo -->
      <div class="dev-card-tipo dev-card-tipo--<?= $s['tipo'] ?>">
        <?php if ($s['tipo'] === 'troca'): ?>
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="17 1 21 5 17 9"/>
            <path d="M3 11V9a4 4 0 014-4h14"/>
            <polyline points="7 23 3 19 7 15"/>
            <path d="M21 13v2a4 4 0 01-4 4H3"/>
          </svg>
        <?php else: ?>
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round">
            <line x1="12" y1="1" x2="12" y2="23"/>
            <path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/>
          </svg>
        <?php endif; ?>
      </div>

      <!-- Info principal -->
      <div class="dev-card-info">
        <div class="dev-card-titulo">
          <?= ucfirst($s['tipo']) ?> — Pedido
          <span style="font-family:'SF Mono',monospace;">#<?= View::e($s['pedido_codigo'] ?? '') ?></span>
        </div>
        <div class="dev-card-motivo"><?= View::e($s['motivo_label']) ?></div>
        <div class="dev-card-data"><?= date('d/m/Y', strtotime($s['criado_em'])) ?></div>
      </div>

      <!-- Status -->
      <div class="dev-card-status">
        <span class="order-status-pill order-status-pill--<?= $st['cor'] ?>">
          <?= $st['label'] ?>
        </span>
        <?php if ($s['status'] === 'aguardando_postagem' && !empty($s['codigo_postagem_reversa'])): ?>
          <div class="dev-card-action-hint">Código disponível →</div>
        <?php elseif ($s['status'] === 'inspecionado_aprovado'): ?>
          <div class="dev-card-action-hint">Reembolso em processamento</div>
        <?php endif; ?>
      </div>

      <!-- Valor -->
      <div class="dev-card-valor">
        <?php if (!empty($s['valor_aprovado'])): ?>
          <strong style="color:#16a34a;"><?= PriceHelper::format((float)$s['valor_aprovado']) ?></strong>
          <small class="txt-muted">aprovado</small>
        <?php else: ?>
          <strong><?= PriceHelper::format((float)$s['valor_solicitado']) ?></strong>
          <small class="txt-muted">solicitado</small>
        <?php endif; ?>
      </div>

      <!-- Chevron -->
      <div class="dev-card-chevron">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.5" stroke-linecap="round">
          <polyline points="9 18 15 12 9 6"/>
        </svg>
      </div>

    </a>
    <?php endforeach; ?>
  </div>

  <!-- Paginação simples -->
  <?php
    $totalPages = (int)ceil($total / 10);
    if ($totalPages > 1):
  ?>
  <div class="pagination" style="margin-top:20px;">
    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
      <a href="?pagina=<?= $i ?>"
         class="pagination-item <?= $page===$i?'is-active':'' ?>"><?= $i ?></a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<style>
.dev-card {
  display: flex; align-items: center; gap: 14px;
  background: #fff; border: 1.5px solid var(--c-border);
  border-radius: 14px; padding: 16px 18px;
  text-decoration: none; color: inherit;
  transition: border-color .15s, box-shadow .15s;
}
.dev-card:hover { border-color: var(--c-primary); box-shadow: 0 4px 16px rgba(37,99,235,.08); }
.dev-card--ativa { border-left: 3px solid var(--c-primary); }

.dev-card-tipo {
  width: 44px; height: 44px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.dev-card-tipo--devolucao { background: #eff6ff; color: #2563eb; }
.dev-card-tipo--devolucao svg { stroke: #2563eb; }
.dev-card-tipo--troca { background: #f5f3ff; color: #7c3aed; }
.dev-card-tipo--troca svg { stroke: #7c3aed; }

.dev-card-info { flex: 1; min-width: 0; }
.dev-card-titulo {
  font-size: 14.5px; font-weight: 700; color: var(--c-dark);
  margin-bottom: 3px;
}
.dev-card-motivo { font-size: 13px; color: var(--c-text-muted); }
.dev-card-data   { font-size: 12px; color: #94a3b8; margin-top: 2px; }

.dev-card-status { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; flex-shrink: 0; }
.dev-card-action-hint { font-size: 11.5px; color: var(--c-primary); font-weight: 600; }

.dev-card-valor {
  text-align: right; flex-shrink: 0;
  display: flex; flex-direction: column; align-items: flex-end; gap: 2px;
}
.dev-card-valor strong { font-size: 15px; font-weight: 800; color: var(--c-dark); }

.dev-card-chevron { color: #cbd5e1; flex-shrink: 0; }
.dev-card:hover .dev-card-chevron { color: var(--c-primary); }

@media (max-width: 600px) {
  .dev-card { flex-wrap: wrap; }
  .dev-card-valor, .dev-card-chevron { display: none; }
}
</style>