<?php
// views/customer/order-detail.php — v3
// ════════════════════════════════════════════════════════

$statusMap = [
    'aguardando_pagamento' => ['cor'=>'warning', 'label'=>'Aguardando pagamento'],
    'pagamento_aprovado'   => ['cor'=>'info',    'label'=>'Pagamento aprovado'],
    'em_separacao'         => ['cor'=>'info',    'label'=>'Pedido faturado'],
    'enviado'              => ['cor'=>'primary', 'label'=>'Pedido enviado'],
    'entregue'             => ['cor'=>'success', 'label'=>'Pedido entregue'],
    'cancelado'            => ['cor'=>'danger',  'label'=>'Cancelado'],
    'troca_devolucao'      => ['cor'=>'warning', 'label'=>'Troca/Devolução'],
    'devolvido'            => ['cor'=>'warning', 'label'=>'Devolvido'],
];

$statusPedido    = $pedido['status_pedido']    ?? 'aguardando_pagamento';
$statusPagamento = $pedido['status_pagamento'] ?? 'pendente';
$metodo          = $pedido['forma_pagamento']  ?? 'cartao';
$aprovado        = $statusPagamento === 'aprovado';
$isCancelado     = $statusPedido === 'cancelado';
$isEntregue      = $statusPedido === 'entregue';
$isTroca         = $statusPedido === 'troca_devolucao' || $statusPedido === 'devolvido';
$isCartao        = $metodo === 'cartao';

$isDevolvido     = $statusPedido === 'devolvido';

$st = $statusMap[$statusPedido] ?? ['cor'=>'info','label'=>$statusPedido];

// ── Progressão ────────────────────────────────────────
$progressao = [
    'aguardando_pagamento' => 0,
    'pagamento_aprovado'   => 1,
    'em_separacao'         => 2,
    'enviado'              => 3,
    'entregue'             => 4,
    'troca_devolucao'      => 5,
    'devolvido'            => 6,
    'cancelado'            => -1,
];
$nivel = $progressao[$statusPedido] ?? 0;
if (!$aprovado && $nivel > 1) $nivel = 0;

$rastreio  = $pedido['codigo_rastreio'] ?? null;
$pagoEm    = !empty($pedido['pago_em'])
    ? date('d/m/Y H:i', strtotime($pedido['pago_em'])) : null;
$prazo     = $pedido['frete_prazo'] ?? $pedido['frete_prazo_dias'] ?? null;
$freteDesc = $pedido['frete_descricao'] ?? $pedido['frete_servico'] ?? null;

// ── Datas de cada evento (extraídas do histórico) ─────
$datasPorStatus = [];
foreach ($historico ?? [] as $h) {
    $datasPorStatus[$h['status_novo']] = date('d/m · H:i', strtotime($h['criado_em']));
}
// Pedido criado = criado_em do pedido
$dataCriado = date('d/m · H:i', strtotime($pedido['criado_em']));

// ── Steps da timeline ─────────────────────────────────
// 5 steps fixos + 1 opcional (troca)
$timelineSteps = [
    [
        'nivel'   => -1, // especial: sempre done se qualquer status >= 0
        'label'   => 'Pedido criado',
        'sub_done'=> $dataCriado,
        'sub_fut' => '—',
        'icon'    => '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2H7a1 1 0 00-1 1v1H5a1 1 0 00-1 1v12a1 1 0 001 1h10a1 1 0 001-1V5a1 1 0 00-1-1h-1V3a1 1 0 00-1-1z"/><polyline points="8,10 9.5,11.5 12,8.5"/></svg>',
    ],
    [
        'nivel'   => 1,
        'label'   => 'Pagamento aprovado',
        'sub_done'=> $datasPorStatus['pagamento_aprovado'] ?? $pagoEm ?? '—',
        'sub_fut' => 'Aguardando',
        'icon'    => '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10 2L3 6v4c0 4.4 3 8.4 7 9.9 4-1.5 7-5.5 7-9.9V6l-7-4z"/><polyline points="7,10 9,12 13,8"/></svg>',
    ],
    [
        'nivel'   => 2,
        'label'   => 'Pedido faturado',
        'sub_done'=> $datasPorStatus['em_separacao'] ?? '—',
        'sub_fut' => 'Em breve',
        'icon'    => '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2H7a1 1 0 00-1 1v14a1 1 0 001 1h6a1 1 0 001-1V3a1 1 0 00-1-1z"/><line x1="8" y1="7" x2="12" y2="7"/><line x1="8" y1="10" x2="12" y2="10"/><line x1="8" y1="13" x2="10" y2="13"/></svg>',
    ],
    [
        'nivel'   => 3,
        'label'   => 'Pedido enviado',
        'sub_done'=> $datasPorStatus['enviado'] ?? '—',
        'sub_fut' => $prazo ? "Prev. {$prazo}d úteis" : 'Aguardando',
        'icon'    => '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="12" height="10" rx="1"/><path d="M13 7h3l3 3v4h-6V7z"/><circle cx="5.5" cy="16" r="1.5"/><circle cx="15.5" cy="16" r="1.5"/></svg>',
    ],
    [
        'nivel'   => 4,
        'label'   => 'Pedido entregue',
        'sub_done'=> $datasPorStatus['entregue'] ?? '—',
        'sub_fut' => $prazo ? "Previsto {$prazo}d úteis" : 'Previsão',
        'icon'    => '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l7-6 7 6v9a1 1 0 01-1 1H4a1 1 0 01-1-1V9z"/><path d="M8 19V13h4v6"/></svg>',
    ],
];

// Step extra: Troca/Devolução (aparece somente quando isTroca)
if ($isTroca) {
    $timelineSteps[] = [
        'nivel'   => 5,
        'label'   => 'Troca / Devolução',
        'sub_done'=> $datasPorStatus['troca_devolucao'] ?? '—',
        'sub_fut' => '—',
        'icon'    => '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="13,1 17,5 13,9"/><path d="M3,11V9a4 4 0 014-4h10"/><polyline points="7,19 3,15 7,11"/><path d="M17,13v2a4 4 0 01-4 4H3"/></svg>',
    ];

    $timelineSteps[] = [
        'nivel'   => 6,
        'label'   => 'Devolvido',
        'sub_done'=> $datasPorStatus['devolvido'] ?? '—',
        'sub_fut' => '—',
        'icon'    => '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="13,1 17,5 13,9"/><path d="M3,11V9a4 4 0 014-4h10"/><polyline points="7,19 3,15 7,11"/><path d="M17,13v2a4 4 0 01-4 4H3"/></svg>',
    ];
}

// Ícones do histórico por status
$historicoIcons = [
    'aguardando_pagamento' => '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="10" cy="10" r="8"/><polyline points="10,6 10,10 13,12"/></svg>',
    'pagamento_aprovado'   => '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><rect x="2" y="4" width="16" height="12" rx="1.5"/><line x1="2" y1="8" x2="18" y2="8"/></svg>',
    'em_separacao'         => '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M13 2H7a1 1 0 00-1 1v14a1 1 0 001 1h6a1 1 0 001-1V3a1 1 0 00-1-1z"/><line x1="8" y1="7" x2="12" y2="7"/><line x1="8" y1="10" x2="12" y2="10"/></svg>',
    'enviado'              => '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><rect x="1" y="4" width="12" height="10" rx="1"/><path d="M13 7h3l3 3v4h-6V7z"/><circle cx="5.5" cy="16" r="1.5"/><circle cx="15.5" cy="16" r="1.5"/></svg>',
    'entregue'             => '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M3 9l7-6 7 6v9a1 1 0 01-1 1H4a1 1 0 01-1-1V9z"/><path d="M8 19V13h4v6"/></svg>',
    'cancelado'            => '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="10" cy="10" r="8"/><line x1="6.5" y1="6.5" x2="13.5" y2="13.5"/><line x1="13.5" y1="6.5" x2="6.5" y2="13.5"/></svg>',
    'devolvido'            => '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><polyline points="13,1 17,5 13,9"/><path d="M3,11V9a4 4 0 014-4h10"/><polyline points="7,19 3,15 7,11"/><path d="M17,13v2a4 4 0 01-4 4H3"/></svg>',
    'troca_devolucao'      => '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><polyline points="13,1 17,5 13,9"/><path d="M3,11V9a4 4 0 014-4h10"/><polyline points="7,19 3,15 7,11"/><path d="M17,13v2a4 4 0 01-4 4H3"/></svg>',
];

// ── Endereço ──────────────────────────────────────────
$end = !empty($pedido['endereco_entrega_snapshot'])
    ? json_decode($pedido['endereco_entrega_snapshot'], true) : null;
if (!$end) $end = [
    'nome_destinatario' => $pedido['ent_destinatario'] ?? '',
    'logradouro'  => $pedido['ent_logradouro']  ?? '',
    'numero'      => $pedido['ent_numero']       ?? '',
    'complemento' => $pedido['ent_complemento'] ?? '',
    'bairro'      => $pedido['ent_bairro']       ?? '',
    'cidade'      => $pedido['ent_cidade']       ?? '',
    'estado'      => $pedido['ent_estado']       ?? '',
    'cep'         => $pedido['ent_cep']          ?? '',
];

// ── FAQs ──────────────────────────────────────────────
$faqs = [
    ['id'=>'rastreio','title'=>'Como rastrear meu pedido?',
     'body'=> $rastreio
        ? '<p>Código de rastreio: <strong class="faq-tracking-code">' . View::e($rastreio) . '</strong></p><a href="https://rastreamento.correios.com.br/app/index.php?ot=' . urlencode($rastreio) . '" target="_blank" class="btn btn-primary btn-sm" style="margin-top:10px">Rastrear nos Correios</a>'
        : '<p>O código de rastreio estará disponível após o envio do pedido.</p>'],
    ['id'=>'cancelar','title'=>'Posso cancelar meu pedido?',
     'body'=> match(true) {
        $isCancelado => '<p>Este pedido já foi cancelado. Se houve cobrança, o estorno ocorre em até <strong>5 dias úteis</strong>.</p>',
        $isEntregue  => '<p>Como o pedido foi entregue, não é possível cancelar. Solicite uma troca/devolução em até 7 dias corridos.</p>',
        in_array($statusPedido,['enviado','em_separacao']) => '<p>O pedido já está em processo de envio. <a href="' . BASE_URL . '/contato">Entre em contato</a> o mais rápido possível informando o código <strong>#' . View::e($pedido['codigo']) . '</strong>.</p>',
        default => '<p>Entre em contato pelo <a href="' . BASE_URL . '/contato">formulário</a> informando o código <strong>#' . View::e($pedido['codigo']) . '</strong>.</p>',
     }],
    ['id'=>'nf','title'=>'Como obter a nota fiscal?',
     'body'=> '<p>A nota fiscal é enviada ao seu e-mail após a confirmação do pagamento. Caso não tenha recebido, entre em contato informando o código <strong>#' . View::e($pedido['codigo']) . '</strong>.</p>'],
    ['id'=>'troca','title'=>'Solicitar troca ou devolução',
     'body'=> $isEntregue || $isTroca
        ? '<p>Prazo de <strong>7 dias corridos</strong> após o recebimento (CDC, Art. 49).</p><button type="button" class="btn btn-primary btn-sm" style="margin-top:10px" onclick="abrirTrocaDevolucao(' . (int)$pedido['id'] . ')">Iniciar solicitação</button>'
        : '<p>Disponível após a <strong>confirmação de entrega</strong>. Status atual: <strong>' . View::e($st['label']) . '</strong>.</p>'],
];
?>

<div class="customer-page order-detail-page">

  <!-- ══ HEADER ════════════════════════════════════════ -->
  <div class="odh-header">
    <div class="odh-left">
      <a href="<?= BASE_URL ?>/minha-conta/pedidos" class="back-link">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="15 18 9 12 15 6"/></svg>
        Meus pedidos
      </a>
      <div class="odh-title-row">
        <h1>Pedido <strong class="odh-code">#<?= View::e($pedido['codigo']) ?></strong></h1>
        <span class="order-status-pill order-status-pill--<?= $st['cor'] ?> order-status-pill--lg">
          <?= $st['label'] ?>
        </span>
      </div>
      <p class="odh-date">
        Realizado em <?= date('d/m/Y \à\s H:i', strtotime($pedido['criado_em'])) ?>
      </p>
    </div>
  </div>

  <!-- ══ TIMELINE ══════════════════════════════════════ -->
  <div class="od-timeline-card <?= $isCancelado ? 'od-timeline-card--cancelled' : '' ?>">
    <div class="odtl-header">
      <h3>Linha do tempo</h3>
      <span class="odtl-etapas"><?= count($timelineSteps) ?> etapas</span>
    </div>
    <div class="odtl-track">
      <?php foreach ($timelineSteps as $i => $s):
        // "Pedido criado" (nivel=-1) está sempre done
        if ($s['nivel'] === -1) {
          $isDone = !$isCancelado;
          $isActive = false;
        } else {
          $isDone   = $isCancelado ? false : $nivel > $s['nivel'];
          $isActive = $isCancelado ? false : $nivel === $s['nivel'];
        }
        $isFut  = !$isDone && !$isActive;
        $isLast = $i === count($timelineSteps) - 1;
        $sub    = $isDone ? $s['sub_done'] : $s['sub_fut'];
        $cls    = $isDone ? 'odtls-done' : ($isActive ? 'odtls-active' : 'odtls-future');
      ?>
      <div class="odtls-step <?= $cls ?>">

        <!-- Ícone + linha conectora -->
        <div class="odtls-dot-wrap">
          <div class="odtls-icon">
            <?= $s['icon'] ?>
          </div>
          <?php if (!$isLast): ?>
            <div class="odtls-line <?= $isDone ? 'odtls-line--done' : '' ?>"></div>
          <?php endif; ?>
        </div>

        <!-- Label -->
        <div class="odtls-label">
          <strong><?= $s['label'] ?></strong>
          <?php if ($sub && $sub !== '—'): ?>
            <small><?= View::e($sub) ?></small>
          <?php endif; ?>
        </div>

      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ══ RASTREAMENTO PREMIUM ══════════════════════════ -->
  <?php if ($rastreio && in_array($statusPedido, ['enviado','entregue'])): ?>
  <div class="tck-card" id="tck-card" data-pedido="<?= (int)$pedido['id'] ?>">
    

    <!-- ── Coluna esquerda ────────────────────────────── -->
    <div class="tck-left">

      <!-- Meta linha -->
      <div class="tck-meta-line">
        <span class="tck-meta-text">PEDIDO #<?= View::e($pedido['codigo']) ?> · <?= View::e($pedido['cliente_nome'] ?? '') ?></span>
        <span class="tck-badge" id="tck-badge">
          <?= $isEntregue ? 'Entregue' : 'Em rota' ?>
        </span>
      </div>

      <!-- Ícone + título -->
      <div class="tck-title-row">
        <div class="tck-icon" id="tck-icon">
          <svg viewBox="0 0 28 28" fill="none" stroke="currentColor"
               stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <rect x="1" y="5" width="17" height="13" rx="1.5"/>
            <path d="M18 9h5l4 4v6h-9V9z"/>
            <circle cx="6.5" cy="20.5" r="2.5"/>
            <circle cx="21.5" cy="20.5" r="2.5"/>
          </svg>
        </div>
        <h2 class="tck-title" id="tck-title">
          <?= $isEntregue ? 'Pacote entregue!' : 'Pacote em movimentação' ?>
        </h2>
      </div>

      <!-- Descrição -->
      <p class="tck-desc" id="tck-desc">
        <?= $isEntregue
            ? 'Seu pedido foi entregue com sucesso. Obrigado pela compra!'
            : 'Seu pedido está a caminho e será entregue em breve.' ?>
      </p>

      <!-- Progresso -->
      <div class="tck-progress-wrap">
        <div class="tck-progress-header">
          <span>Progresso da entrega</span>
          <span class="tck-progress-pct" id="tck-pct">
            <?= $isEntregue ? '100%' : '65%' ?>
          </span>
        </div>
        <div class="tck-progress-track">
          <div class="tck-progress-fill" id="tck-fill"
               style="width:<?= $isEntregue ? '100' : '65' ?>%"></div>
        </div>
        <div class="tck-last-update" id="tck-last-update">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2.2" stroke-linecap="round">
            <circle cx="12" cy="12" r="10"/>
            <polyline points="12 6 12 12 16 14"/>
          </svg>
          Carregando atualização…
        </div>
      </div>

      <!-- Ações -->
      <div class="tck-actions">
        <!-- Notificações: botão fake por enquanto -->
        <button type="button" class="tck-btn" id="btn-tck-notify"
                onclick="Toast && Toast.info('Notificações em breve!')">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2.2" stroke-linecap="round">
            <path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/>
            <path d="M13.73 21a2 2 0 01-3.46 0"/>
          </svg>
          Receber notificações
        </button>

        <!-- Compartilhar -->
        <button type="button" class="tck-btn" id="btn-tck-share">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2.2" stroke-linecap="round">
            <circle cx="18" cy="5" r="3"/>
            <circle cx="6" cy="12" r="3"/>
            <circle cx="18" cy="19" r="3"/>
            <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/>
            <line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>
          </svg>
          Compartilhar
        </button>

        <!-- Nota fiscal -->
        <?php if (!empty($nf['url_pdf'] ?? $nf['linkPDF'] ?? null)): ?>
        <a href="<?= View::e($nf['url_pdf'] ?? $nf['linkPDF']) ?>"
           target="_blank" rel="noopener" class="tck-btn tck-btn--link">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2.2" stroke-linecap="round">
            <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>
            <polyline points="14 2 14 8 20 8"/>
            <line x1="16" y1="13" x2="8" y2="13"/>
          </svg>
          Nota fiscal
        </a>
        <?php else: ?>
        <button type="button" class="tck-btn tck-btn--disabled" disabled>
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2.2" stroke-linecap="round">
            <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>
            <polyline points="14 2 14 8 20 8"/>
          </svg>
          Nota fiscal
        </button>
        <?php endif; ?>
      </div>

    </div><!-- /.tck-left -->

    <!-- ── Coluna direita ──────────────────────────────── -->
    <div class="tck-right">

      <!-- Código de rastreio -->
      <div class="tck-code-section">
        <div class="tck-right-label">CÓDIGO DE RASTREIO</div>
        <div class="tck-code-row">
          <span class="tck-code"><?= View::e($rastreio) ?></span>
          <button type="button" class="tck-copy-btn" id="btn-copy-tck"
                  title="Copiar código">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2.5" stroke-linecap="round">
              <rect x="9" y="9" width="13" height="13" rx="2"/>
              <path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/>
            </svg>
          </button>
        </div>
      </div>

      <!-- Previsão de entrega -->
      <div class="tck-delivery-card" id="tck-delivery">
        <div class="tck-right-label">PREVISÃO DE ENTREGA</div>
        <?php if ($prazo && !$isEntregue): ?>
          <strong class="tck-delivery-date" id="tck-delivery-date">
            <?= $prazo ?> dia(s) úteis
          </strong>
          <small class="tck-delivery-window" id="tck-delivery-window"></small>
        <?php elseif ($isEntregue): ?>
          <strong class="tck-delivery-date">Pedido entregue</strong>
          <?php if ($pagoEm): ?>
            <small class="tck-delivery-window"><?= $pagoEm ?></small>
          <?php endif; ?>
        <?php else: ?>
          <strong class="tck-delivery-date">Em cálculo</strong>
        <?php endif; ?>
      </div>

      <!-- Localização atual (vindo da API) -->
      <div class="tck-location" id="tck-location" style="display:none;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.2" stroke-linecap="round">
          <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/>
          <circle cx="12" cy="10" r="3"/>
        </svg>
        <div>
          <strong id="tck-location-name"></strong>
          <small>Localização atual do pacote</small>
        </div>
      </div>

      <!-- Link Correios -->
      <a href="https://rastreamento.correios.com.br/app/index.php?ot=<?= urlencode($rastreio) ?>"
         target="_blank" rel="noopener" class="tck-correios-link">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.5" stroke-linecap="round">
          <path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/>
          <polyline points="15 3 21 3 21 9"/>
          <line x1="10" y1="14" x2="21" y2="3"/>
        </svg>
        Rastrear no site dos Correios
      </a>

    </div><!-- /.tck-right -->

  </div><!-- /.tck-card -->

  <script>
  (function () {
    var pedidoId = <?= (int)$pedido['id'] ?>;
    var card = document.getElementById('tck-card');
    if (!card) return;

    // ── Copia o código ──────────────────────────────────
    var btnCopy = document.getElementById('btn-copy-tck');
    if (btnCopy) {
      btnCopy.addEventListener('click', function () {
        navigator.clipboard && navigator.clipboard.writeText('<?= View::e($rastreio) ?>').then(function () {
          btnCopy.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>';
          setTimeout(function () {
            btnCopy.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>';
          }, 2000);
        });
      });
    }

    // ── Compartilhar ────────────────────────────────────
    document.getElementById('btn-tck-share').addEventListener('click', function () {
      if (navigator.share) {
        navigator.share({
          title: 'Rastreio do pedido #<?= View::e($pedido['codigo']) ?>',
          text:  'Código de rastreio: <?= View::e($rastreio) ?>',
          url:   'https://rastreamento.correios.com.br/app/index.php?ot=<?= urlencode($rastreio) ?>'
        });
      } else {
        navigator.clipboard && navigator.clipboard.writeText(
          'Pedido #<?= View::e($pedido['codigo']) ?> · Rastreio: <?= View::e($rastreio) ?>'
        );
        if (window.Toast) Toast.success('Link copiado para a área de transferência!');
      }
    });

    // ── Busca dados de rastreio via API ─────────────────
    fetch('<?= BASE_URL ?>/minha-conta/pedido/' + pedidoId + '/rastreio')
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.ok || !res.rastreio) return;
        var t = res.rastreio;

        // Título e descrição
        if (t.titulo)    document.getElementById('tck-title').textContent = t.titulo;
        if (t.descricao) document.getElementById('tck-desc').textContent  = t.descricao;

        // Badge
        if (t.status_label) document.getElementById('tck-badge').textContent = t.status_label;

        // Progresso
        if (typeof t.progresso === 'number') {
          var pct = Math.min(100, Math.max(0, t.progresso));
          document.getElementById('tck-pct').textContent  = pct + '%';
          document.getElementById('tck-fill').style.width = pct + '%';
        }

        // Última atualização
        if (t.ultima_atualizacao) {
          document.getElementById('tck-last-update').innerHTML =
            '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Última atualização: ' + t.ultima_atualizacao;
        }

        // Previsão de entrega
        if (t.previsao_entrega) {
          var pe = t.previsao_entrega;
          if (pe.data_formatada) {
            document.getElementById('tck-delivery-date').textContent = pe.data_formatada;
          }
          if (pe.janela_inicio && pe.janela_fim) {
            document.getElementById('tck-delivery-window').textContent =
              'entre ' + pe.janela_inicio + ' e ' + pe.janela_fim;
          }
        }

        // Localização atual
        if (t.localizacao_atual) {
          document.getElementById('tck-location-name').textContent = t.localizacao_atual;
          document.getElementById('tck-location').style.display = 'flex';
        }
      })
      .catch(function () {
        // API indisponível — mantém dados estáticos sem quebrar
        document.getElementById('tck-last-update').innerHTML =
          '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Dados indisponíveis no momento';
      });
  })();
  </script>
  <?php endif; 
  
  // var_dump($pedido);
  ?>

  <!-- ══ GRID PRINCIPAL ════════════════════════════════ -->
  <div class="od-grid">

    <!-- ── COLUNA PRINCIPAL ────────────────────────── -->
    <div class="od-main">
    
    <!-- Troca/Devolução -->
      <?php if ($isEntregue || $isTroca || $isDevolvido): ?>
      <div class="od-card od-returns-card">
        <?php if (($isTroca || $isDevolvido) && !empty($devolucao)): ?>
        <?php
          $sol = $devolucao['solicitacao'];
          $devFotos = $devolucao['fotos'] ?? [];
          $devHist  = $devolucao['historico'] ?? [];
          $devStatusMap = [
            'solicitado'            => ['cor'=>'warning','label'=>'Aguardando análise'],
            'pre_aprovado'          => ['cor'=>'success','label'=>'Pré-aprovado'],
            'aguardando_aprovacao'  => ['cor'=>'warning','label'=>'Em análise'],
            'aprovado'              => ['cor'=>'info',   'label'=>'Aprovado — aguardando postagem'],
            'negado'                => ['cor'=>'danger', 'label'=>'Negado'],
            'aguardando_postagem'   => ['cor'=>'warning','label'=>'Aguardando sua postagem'],
            'em_transito_reverso'   => ['cor'=>'primary','label'=>'Em trânsito reverso'],
            'item_recebido'         => ['cor'=>'info',   'label'=>'Item recebido — em inspeção'],
            'inspecionado_aprovado' => ['cor'=>'success','label'=>'Aprovado — reembolso em breve'],
            'inspecionado_reprovado'=> ['cor'=>'danger', 'label'=>'Reprovado na inspeção'],
            'concluido'             => ['cor'=>'success','label'=>'Concluído'],
            'cancelado'             => ['cor'=>'danger', 'label'=>'Cancelado'],
          ];
          $devSt = $devStatusMap[$sol['status']] ?? ['cor'=>'info','label'=>$sol['status']];
        ?>

        <!-- Header do card -->
        <div class="od-dev-header">
          <div class="od-dev-header-info">
            <h3 class="od-card-title" style="border:none;padding-bottom:0;margin-bottom:0;">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 014-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 01-4 4H3"/></svg>
              <?= ucfirst($sol['tipo']) ?> em andamento 
            </h3>
            <span class="order-status-pill order-status-pill--<?= $devSt['cor'] ?>">
              <?= $devSt['label'] ?>
            </span>
          </div>
          <a href="<?= BASE_URL ?>/minha-conta/devolucao/<?= (int)$sol['solicitacao_id'] ?>"
             class="od-dev-link-btn">
            Ver detalhes
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="9 18 15 12 9 6"/></svg>
          </a>
        </div>

        <!-- Infos rápidas: código postagem + rastreio reverso + valor aprovado -->
        <?php if (in_array($sol['status'], ['aguardando_postagem', 'pre_aprovado', 'aprovado']) && empty($sol['codigo_postagem_reversa'])): ?>          
          <div class="od-dev-processing">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#f59e0b"
                stroke-width="2.5" stroke-linecap="round" class="od-dev-spin">
              <path d="M21 12a9 9 0 11-6.219-8.56"/>
            </svg>
            Estamos processando seu código de devolução, por favor, aguarde…
          </div>        
        <?php endif; ?>
        <div class="od-dev-info-row">
          
          <?php if (!empty($sol['codigo_postagem_reversa'])): ?>
          <div class="od-dev-info-item">
            <span class="od-dev-info-label">Código de postagem</span>
            <code class="od-dev-code"><?= View::e($sol['codigo_postagem_reversa']) ?></code>
            <?php if (!empty($sol['codigo_validade_dias'])): ?>
              <small style="color:#94a3b8;font-size:11px;">Válido <?= (int)$sol['codigo_validade_dias'] ?> dias</small>
            <?php endif; ?>
          </div>
          <?php endif; ?>
          <?php if (!empty($sol['codigo_rastreio_reverso'])): ?>
          <div class="od-dev-info-item">
            <span class="od-dev-info-label">Rastreio reverso</span>
            <code class="od-dev-code"><?= View::e($sol['codigo_rastreio_reverso']) ?></code>
          </div>
          <?php endif; ?>
          <?php if (!empty($sol['valor_aprovado'])): ?>
          <div class="od-dev-info-item">
            <span class="od-dev-info-label">Valor aprovado</span>
            <strong style="font-size:16px;color:#16a34a;"><?= PriceHelper::format((float)$sol['valor_aprovado']) ?></strong>
          </div>
          <?php endif; ?>
        </div><?php if (!empty($sol['codigo_postagem_reversa']) || !empty($sol['codigo_rastreio_reverso']) || !empty($sol['valor_aprovado'])): ?>
        <?php endif; ?>

        <!-- Mini timeline de status da devolução -->
        <?php if (!empty($devHist)): ?>
        <div class="od-dev-timeline">
          <?php foreach (array_reverse(array_slice($devHist, 0, 4)) as $di => $dh):
            $dhSt = $devStatusMap[$dh['status_novo']] ?? ['cor'=>'info','label'=>$dh['status_novo']];
          ?>
          <div class="od-dev-tl-item">
            <div class="od-dev-tl-dot od-dev-tl-dot--<?= $dhSt['cor'] ?> <?= $di===0 ? 'od-dev-tl-dot--first' : '' ?>"></div>
            <div class="od-dev-tl-body">
              <strong><?= View::e($dhSt['label']) ?></strong>
              <time><?= date('d/m H:i', strtotime($dh['criado_em'])) ?></time>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Fotos e vídeos enviados -->
        <?php if (!empty($devFotos)): ?>
        <div class="od-dev-media">
          <span class="od-dev-media-label">Arquivos enviados (<?= count($devFotos) ?>)</span>
          <div class="od-dev-media-grid">
            <?php foreach ($devFotos as $foto):
              $ext     = strtolower(pathinfo($foto, PATHINFO_EXTENSION));
              $isVideo = in_array($ext, ['mp4','mov','m4v']);
              $fUrl    = BASE_URL . '/uploads/devolucoes/' . View::e($foto);
            ?>
            <?php if ($isVideo): ?>
              <a href="<?= $fUrl ?>" target="_blank" rel="noopener"
                 class="od-dev-media-item od-dev-media-item--video">
                <video src="<?= $fUrl ?>" muted preload="metadata"></video>
                <span class="od-dev-media-badge">
                  <svg width="9" height="9" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                  Vídeo
                </span>
              </a>
            <?php else: ?>
              <a href="<?= $fUrl ?>" target="_blank" rel="noopener" class="od-dev-media-item">
                <img src="<?= $fUrl ?>" alt="" loading="lazy">
              </a>
            <?php endif; ?>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <!-- Formulário de rastreio reverso (quando aguardando postagem) -->
        <?php if ($sol['status'] === 'aguardando_postagem'): ?>
        <div class="od-dev-rastreio-form">
          <p style="font-size:13.5px;color:#374151;margin:0 0 10px;">
            Poste o produto com o código acima e informe o rastreio abaixo.
          </p>
          <form method="POST" action="<?= BASE_URL ?>/minha-conta/devolucao/<?= (int)$sol['id'] ?>/rastreio">
            <?= SecurityHelper::csrfField() ?>
            <div style="display:flex;gap:8px;">
              <input type="text" name="codigo_rastreio" class="form-control"
                     placeholder="Ex: AA123456789BR" required
                     style="flex:1;text-transform:uppercase;font-family:'SF Mono',monospace;">
              <button type="submit" class="btn btn-primary" style="flex-shrink:0;">Confirmar</button>
            </div>
          </form>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <?php if(!$isDevolvido){ ?>
          <!-- Estado: entregue, sem devolução ativa — exibe botão de solicitação -->
          <div style="padding:14px 20px;">
            <p class="od-returns-p">Prazo de <strong>7 dias corridos</strong> após o recebimento (CDC, Art. 49).</p>
            <a href="<?= BASE_URL ?>/minha-conta/devolucao/nova/<?= (int)$pedido['id'] ?>"
              class="btn btn-outline btn-full">
              Solicitar troca ou devolução
            </a>
          </div>
          <?php }?>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Itens (sem preços — ficam no detalhe financeiro) -->
      <div class="od-card">
        <h3 class="od-card-title">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>
          <?= count($itens) ?> <?= count($itens)===1?'item':'itens' ?>
        </h3>

        <?php foreach ($itens as $item):
          $imgUrl = ImageHelper::getCartItemImage($item['produto_id']);
          $imgSrc = $item['imagem_url']
              ?? (!empty($item['imagem_snapshot'])
                  ? BASE_URL.'/uploads/produtos/'.$item['imagem_snapshot']
                  : (!empty($item['imagem'])
                      ? BASE_URL.'/uploads/produtos/'.$item['imagem']
                      : BASE_URL.'/assets/img/placeholder.png'));
          $opcoes = !empty($item['atributos']) ? $item['atributos']
              : (!empty($item['opcoes_snapshot'])
                  ? (is_array($item['opcoes_snapshot']) ? $item['opcoes_snapshot']
                     : json_decode($item['opcoes_snapshot'],true))
                  : []);
          $linkavel = !empty($item['produto_ativo']) && !empty($item['produto_slug']);
        ?>
        <div class="od-item od-item--no-price">
          <div class="od-item-img">
            <img src="<?= View::e($imgUrl) ?>"
                 alt="<?= View::e($item['nome_produto'] ?? '') ?>" loading="lazy">
          </div>
          <div class="od-item-info">
            <div class="od-item-name">
              <?php if ($linkavel): ?>
                <a href="<?= BASE_URL ?>/produto/<?= View::e($item['produto_slug']) ?>">
                  <?= View::e($item['nome_produto'] ?? '') ?>
                </a>
              <?php else: ?>
                <span><?= View::e($item['nome_produto'] ?? '') ?></span>
              <?php endif; ?>
            </div>
            <?php if (!empty($opcoes)): ?>
            <div class="od-item-opts">
              <?php foreach ($opcoes as $a):
                // suporta array indexado ou chave→valor
                $nome  = is_array($a) ? ($a['nome']  ?? '') : '';
                $valor = is_array($a) ? ($a['valor'] ?? '') : $a;
                if (!is_array($a)) { $nome = $a; $valor = ''; } // fallback
                if (!$nome && !$valor) continue;
              ?>
                <?php if (!empty($a['valor_hex'])): ?>
                  <span class="opt-cor" style="background:<?= View::e($a['valor_hex']) ?>"
                        title="<?= View::e($nome) ?>: <?= View::e($valor) ?>"></span>
                <?php else: ?>
                  <span class="opt-tag">
                    <?php if ($nome): ?><?= View::e($nome) ?>:<?php endif; ?>
                    <strong><?= View::e($valor) ?></strong>
                  </span>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div class="od-item-qty-row">
              <span class="od-item-qty">Qtd: <?= (int)$item['quantidade'] ?></span>
              <?php if (!empty($item['sku'])): ?>
                <span class="od-item-sku">SKU: <?= View::e($item['sku']) ?></span>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      

      <!-- Histórico detalhado -->
      <?php if (!empty($historico)): ?>
      <div class="od-card od-historico-card">
        <div class="od-card-title-row">
          <h3 class="od-card-title" style="border:none;margin-bottom:0;padding-bottom:0;">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M3 3v5h5"/><path d="M3.05 13A9 9 0 106 5.3L3 8"/></svg>
            Histórico detalhado
          </h3>
          <span class="odh-count-badge"><?= count($historico) ?> eventos</span>
        </div>

        <div class="od-hist-list">
          <?php foreach (array_slice($historico, 0, 5) as $idx => $h):
            $hSt     = $statusMap[$h['status_novo']] ?? ['cor'=>'info','label'=>$h['status_novo']];
            $icon    = $historicoIcons[$h['status_novo']] ?? $historicoIcons['aguardando_pagamento'];
            $isFirst = $idx === 0;
          ?>
          <div class="odh-event-wrap">

            <!-- Ícone + linha tracejada -->
            <div class="odh-icon-col">
              <div class="odh-event-icon odh-icon--<?= $hSt['cor'] ?> <?= $isFirst ? 'odh-icon--first' : '' ?>">
                <?= $icon ?>
              </div>
              <?php if ($idx < count($historico) - 1): ?>
                <div class="odh-dashed-line"></div>
              <?php endif; ?>
            </div>

            <!-- Conteúdo do evento -->
            <div class="odh-event-card <?= $isFirst ? 'odh-event-card--latest' : '' ?>">
              <div class="odh-event-meta">
                <span class="odh-event-date"><?= date('d/m', strtotime($h['criado_em'])) ?></span>
                <span class="odh-sep">·</span>
                <span class="odh-event-time"><?= date('H:i', strtotime($h['criado_em'])) ?></span>
                <?php if (!empty($h['localidade'])): ?>
                  <span class="odh-sep">·</span>
                  <span class="odh-event-local">
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                    <?= View::e($h['localidade']) ?>
                  </span>
                <?php endif; ?>
              </div>
              <strong><?= View::e($hSt['label']) ?></strong>
              <?php if (!empty($h['observacao'])): ?>
                <p><?= View::e($h['observacao']) ?></p>
              <?php endif; ?>
            </div>

          </div>
          <?php endforeach; ?>
        </div>

        <?php if (count($historico) > 5): ?>
        <div style="padding:4px 20px 16px;">
          <button type="button" class="od-ver-mais-hist" id="btn-ver-mais-hist">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2.5" stroke-linecap="round"><polyline points="6 9 12 15 18 9"/></svg>
            Ver mais <?= count($historico) - 5 ?> evento<?= (count($historico)-5) !== 1 ? 's' : '' ?>
          </button>
        </div>
        <script id="od-hist-data" type="application/json">
          <?= json_encode(array_map(function($h) use ($statusMap, $historicoIcons) {
            $hSt = $statusMap[$h['status_novo']] ?? ['cor'=>'info','label'=>$h['status_novo']];
            return [
              'cor'       => $hSt['cor'],
              'label'     => $hSt['label'],
              'data'      => date('d/m', strtotime($h['criado_em'])),
              'hora'      => date('H:i', strtotime($h['criado_em'])),
              'localidade'=> $h['localidade'] ?? '',
              'observacao'=> $h['observacao'] ?? '',
              'isFirst'   => false,
            ];
          }, $historico), JSON_UNESCAPED_UNICODE) ?>
        </script>
        <?php endif; ?>

        <!-- Documentos do pedido -->
        <div class="od-docs-section">
          <h4 class="od-docs-title">DOCUMENTOS DO PEDIDO</h4>
          <div class="od-docs-grid">

            <?php $nfPdf = $nf['url_pdf'] ?? null; ?>
            <div class="od-doc-card <?= empty($nfPdf) ? 'od-doc-card--disabled' : '' ?>">
              <div class="od-doc-icon-box od-doc-icon-box--green">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
              </div>
              <div class="od-doc-text">
                <strong>Nota fiscal</strong>
                <small>
                  <?php if ($nfPdf): ?>
                    NF-e <?= !empty($nf['numero']) ? '#'.$nf['numero'] : '' ?> · PDF disponível
                  <?php else: ?>
                    Disponível após faturamento
                  <?php endif; ?>
                </small>
              </div>
              <?php if ($nfPdf): ?>
                <a href="<?= View::e($nfPdf) ?>" target="_blank" rel="noopener" class="od-doc-dl-btn">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                </a>
              <?php else: ?>
                <span class="od-doc-dl-btn od-doc-dl-btn--off">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                </span>
              <?php endif; ?>
            </div>

            <div class="od-doc-card <?= !$isEntregue ? 'od-doc-card--disabled' : '' ?>">
              <div class="od-doc-icon-box <?= $isEntregue ? 'od-doc-icon-box--blue' : '' ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
              </div>
              <div class="od-doc-text">
                <strong>Comprovante de entrega</strong>
                <small><?= $isEntregue ? 'PDF disponível' : 'Disponível após entrega' ?></small>
              </div>
              <span class="od-doc-dl-btn <?= !$isEntregue ? 'od-doc-dl-btn--off' : '' ?>">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
              </span>
            </div>

          </div>
        </div>
      </div>
      <?php endif; ?>      

      <!-- Avaliação dos produtos -->
      <?php if ($isEntregue): ?>
      <div class="od-card od-rating-card">
        <h3 class="od-card-title">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
          O que você achou do produto?
        </h3>
        <?php foreach ($itens as $item):
          $jaAvaliou = !empty($item['avaliacao_id'] ?? null);
          // $imgSrc2 = $item['imagem_url'] ?? BASE_URL.'/assets/img/placeholder.png';
          $imgSrc2 = ImageHelper::getCartItemImage($item['produto_id']);
        ?>
        <div class="od-rating-item">
          <img src="<?= View::e($imgSrc2) ?>" alt="" class="od-rating-thumb">
          <div class="od-rating-info">
            <span class="od-rating-name"><?= View::e($item['nome_produto'] ?? '') ?></span>
            <?php if ($jaAvaliou): ?>
              <div class="od-stars od-stars--done">
                <?php for ($s = 1; $s <= 5; $s++): ?>
                  <svg class="od-star <?= $s <= (int)$item['avaliacao_nota'] ? 'od-star--on' : '' ?>"
                       viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
                  </svg>
                <?php endfor; ?>
                <span class="od-rating-done-label">Avaliado</span>
              </div>
            <?php else: ?>
              <div class="od-stars od-stars--interactive"
                   data-pedido="<?= (int)$pedido['id'] ?>"
                   data-produto="<?= (int)$item['produto_id'] ?>">
                <?php for ($s = 1; $s <= 5; $s++): ?>
                  <svg class="od-star" data-val="<?= $s ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
                  </svg>
                <?php endfor; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

    </div><!-- /.od-main -->

    <!-- ── COLUNA LATERAL ──────────────────────────── -->
    <div class="od-aside">

      <!-- Detalhe financeiro da compra -->
      <div class="od-card od-card--sm od-finance-card">
        <h3 class="od-card-title">Detalhe da compra</h3>
        <div class="od-finance-sub">
          <?= date('d \d\e F', strtotime($pedido['criado_em'])) ?>
          &nbsp;|&nbsp;
          <span class="od-finance-code"># <?= View::e($pedido['codigo']) ?></span>
        </div>

        <?php
          // ── Dados de reembolso ───────────────────────
          $bandeira    = $pedido['cartao_bandeira']  ?? null;
          $ultimos4    = $pedido['cartao_ultimos_4'] ?? null;
          $parcelas    = (int)($pedido['parcelas']   ?? 1);
          $total       = (float)$pedido['total'];

          // Reembolso total: status de pagamento estornado/reembolsado
          $temReembolsoTotal   = in_array($statusPagamento, ['estornado','reembolsado']);

          // Reembolso parcial: devolução aprovada com valor_aprovado
          $valorReembolsoParc  = 0;
          $temReembolsoParcial = false;
          if (!empty($devolucao['solicitacao']['valor_aprovado'])) {
            $vap = (float)$devolucao['solicitacao']['valor_aprovado'];
            if ($vap > 0 && $vap < $total) {
              $valorReembolsoParcial = $vap;
              $temReembolsoParcial   = true;
            }
          }

          $temQualquerReembolso = $temReembolsoTotal || $temReembolsoParcial;
          $valorReembolso       = $temReembolsoTotal ? $total : ($valorReembolsoParcial ?? 0);

          // Método de devolução legível
          $metodoReembolso = match($devolucao['solicitacao']['metodo_reembolso'] ?? '') {
            'gateway'      => ($bandeira ? (IconLibrary::name($bandeira) . ' **** ' . $ultimos4) : 'Cartão original'),
            'pix'          => 'PIX',
            'boleto_manual'=> 'Transferência bancária',
            'credito'      => 'Crédito na loja',
            default        => 'Estorno',
          };
        ?>

        <div class="od-finance-rows <?= $temQualquerReembolso ? 'odf--has-refund' : '' ?>">

          <!-- Produtos -->
          <div class="odf-row <?= $temReembolsoTotal ? 'odf-row--struck' : '' ?>">
            <span>Produtos (<?= count($itens) ?>)</span>
            <span><?= PriceHelper::format((float)$pedido['subtotal']) ?></span>
          </div>

          <!-- Desconto -->
          <?php if ((float)$pedido['desconto'] > 0): ?>
          <div class="odf-row odf-green <?= $temReembolsoTotal ? 'odf-row--struck' : '' ?>">
            <span>Desconto</span>
            <span>− <?= PriceHelper::format((float)$pedido['desconto']) ?></span>
          </div>
          <?php endif; ?>

          <!-- Frete -->
          <div class="odf-row <?= $temReembolsoTotal ? 'odf-row--struck' : '' ?>">
            <span>Frete</span>
            <span>
              <?= (float)$pedido['frete'] > 0
                  ? PriceHelper::format((float)$pedido['frete'])
                  : '<strong class="c-green">Grátis</strong>' ?>
            </span>
          </div>

          <div class="odf-divider"></div>

          <!-- Subtotal -->
          <div class="odf-row odf-subtotal <?= $temReembolsoTotal ? 'odf-row--struck' : '' ?>">
            <span>Subtotal</span>
            <span><?= PriceHelper::format(max(0, (float)$pedido['subtotal'] - (float)$pedido['desconto'] + (float)$pedido['frete'])) ?></span>
          </div>

          <!-- Pagamento(s) -->
          <div class="odf-row odf-label-section"><span>Pagamentos</span></div>

          <?php if ($metodo === 'cartao' && $ultimos4): ?>
            <div class="odf-payment-row <?= $temReembolsoTotal ? 'odf-row--struck' : '' ?>">
              <?php if ($bandeira): ?>
                <div class="odf-brand"><?= IconLibrary::logo($bandeira, 28, 18) ?></div>
              <?php endif; ?>
              <div class="odf-payment-detail">
                <?php if ($parcelas > 1): ?>
                  <span><?= $parcelas ?>x <?= PriceHelper::format($total / $parcelas) ?></span>
                <?php else: ?>
                  <span>À vista <?= PriceHelper::format($total) ?></span>
                <?php endif; ?>
                <span class="odf-card-last4"><?= $bandeira ? View::e(IconLibrary::name($bandeira)) : 'Cartão' ?> **** <?= View::e($ultimos4) ?></span>
              </div>
            </div>
          <?php elseif ($metodo === 'pix'): ?>
            <div class="odf-payment-row <?= $temReembolsoTotal ? 'odf-row--struck' : '' ?>">
              <div class="odf-pix-chip">PIX</div>
              <div class="odf-payment-detail">
                <span><?= PriceHelper::format($total) ?></span>
                <span class="odf-card-last4">Pix<?= $pagoEm ? ' · ' . $pagoEm : '' ?></span>
              </div>
            </div>
          <?php else: ?>
            <div class="odf-payment-row <?= $temReembolsoTotal ? 'odf-row--struck' : '' ?>">
              <div class="odf-boleto-chip">|||</div>
              <div class="odf-payment-detail">
                <span><?= PriceHelper::format($total) ?></span>
                <span class="odf-card-last4">Boleto bancário</span>
              </div>
            </div>
          <?php endif; ?>

          <div class="odf-divider"></div>

          <!-- Total -->
          <div class="odf-row odf-total <?= $temReembolsoTotal ? 'odf-row--struck' : '' ?>">
            <strong>Total pago</strong>
            <strong><?= PriceHelper::format($total) ?></strong>
          </div>

          <!-- ── Bloco de reembolso ─────────────────── -->
          <?php if ($temQualquerReembolso): ?>
          <div class="odf-refund-block">
            <div class="odf-refund-header">
              <span class="odf-refund-icon">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                  <polyline points="20 6 9 17 4 12"/>
                </svg>
              </span>
              <strong><?= $temReembolsoTotal ? 'Reembolso total' : 'Reembolso parcial' ?></strong>
            </div>

            <div class="odf-refund-value-row">
              <span><?= $temReembolsoTotal ? 'Valor devolvido' : 'Valor aprovado' ?></span>
              <span class="odf-refund-amount"><?= PriceHelper::format($valorReembolso) ?></span>
            </div>

            <?php if ($temReembolsoParcial): ?>
            <div class="odf-refund-net-row">
              <span>Saldo retido</span>
              <span><?= PriceHelper::format($total - $valorReembolso) ?></span>
            </div>
            <?php endif; ?>

            <div class="odf-refund-method">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <rect x="1" y="4" width="22" height="16" rx="2"/>
                <line x1="1" y1="10" x2="23" y2="10"/>
              </svg>
              <?= View::e($metodoReembolso) ?>
            </div>

            <?php if (!empty($devolucao['solicitacao']['concluido_em'])): ?>
            <div class="odf-refund-date">
              Em <?= date('d/m/Y', strtotime($devolucao['solicitacao']['concluido_em'])) ?>
            </div>
            <?php endif; ?>
          </div>
          <?php endif; ?>

        </div>
      </div>

      <!-- Endereço -->
      <?php if (!empty($end['logradouro'])): ?>
      <div class="od-card od-card--sm">
        <h3 class="od-card-title">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
          Endereço de entrega
        </h3>
        <div class="od-address">
          <strong><?= View::e($end['nome_destinatario'] ?? '') ?></strong>
          <p><?= View::e("{$end['logradouro']}, {$end['numero']}") ?>
            <?php if (!empty($end['complemento'])): ?> — <?= View::e($end['complemento']) ?><?php endif; ?>
          </p>
          <p><?= View::e("{$end['bairro']} — {$end['cidade']}/{$end['estado']}") ?></p>
          <p class="od-address-cep">CEP <?= View::e($end['cep'] ?? '') ?></p>
          <?php if ($freteDesc): ?>
            <span class="od-frete-tag">
              <?= View::e($freteDesc) ?>
              <?php if ($prazo): ?> · <?= (int)$prazo ?>d úteis<?php endif; ?>
            </span>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Informações da compra (NF) -->
      <?php if (!empty($nf)): ?>
      <div class="od-card od-card--sm od-nf-card">
        <h3 class="od-card-title">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2.2" stroke-linecap="round">
            <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>
            <polyline points="14 2 14 8 20 8"/>
            <line x1="16" y1="13" x2="8" y2="13"/>
            <line x1="16" y1="17" x2="8" y2="17"/>
          </svg>
          Nota fiscal
        </h3>

        <div class="od-nf-body">

          <!-- Número e série -->
          <div class="od-nf-number-row">
            <?php if (!empty($nf['numero'])): ?>
            <div class="od-nf-num-badge">
              <span class="od-nf-num-label">NF-e</span>
              <span class="od-nf-num-value"><?= View::e($nf['numero']) ?></span>
              <?php if (!empty($nf['serie'])): ?>
                <span class="od-nf-serie">Série <?= View::e($nf['serie']) ?></span>
              <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($nf['tipo'])): ?>
              <span class="od-nf-tipo"><?= View::e($nf['tipo']) ?></span>
            <?php endif; ?>
          </div>

          <!-- Dados da empresa emissora -->
          <?php if (!empty($nf['contato']) || !empty($nf['cnpj'])): ?>
          <div class="od-nf-emitente">
            <?php if (!empty($nf['contato'])): ?>
              <span class="od-nf-emitente-nome"><?= View::e($nf['contato']) ?></span>
            <?php endif; ?>
            <?php if (!empty($nf['cnpj'])): ?>
              <span class="od-nf-emitente-cnpj">CNPJ <?= View::e($nf['cnpj']) ?></span>
            <?php endif; ?>
          </div>
          <?php endif; ?>

          <!-- Datas e valor -->
          <div class="od-nf-meta-grid">
            <?php if (!empty($nf['dataEmissao'])): ?>
            <div class="od-nf-meta-item">
              <span class="od-nf-meta-label">Emissão</span>
              <span class="od-nf-meta-val"><?= date('d/m/Y', strtotime($nf['dataEmissao'])) ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($nf['dataSaidaEntrada'])): ?>
            <div class="od-nf-meta-item">
              <span class="od-nf-meta-label">Saída</span>
              <span class="od-nf-meta-val"><?= date('d/m/Y', strtotime($nf['dataSaidaEntrada'])) ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($nf['valorNota'])): ?>
            <div class="od-nf-meta-item">
              <span class="od-nf-meta-label">Valor da nota</span>
              <span class="od-nf-meta-val od-nf-meta-val--strong">
                <?= PriceHelper::format((float)$nf['valorNota']) ?>
              </span>
            </div>
            <?php endif; ?>
            <?php if (!empty($nf['valorFrete'])): ?>
            <div class="od-nf-meta-item">
              <span class="od-nf-meta-label">Frete NF</span>
              <span class="od-nf-meta-val"><?= PriceHelper::format((float)$nf['valorFrete']) ?></span>
            </div>
            <?php endif; ?>
          </div>

          <!-- Chave de acesso -->
          <?php if (!empty($nf['chaveAcesso_fmt'])): ?>
          <div class="od-nf-chave-wrap">
            <span class="od-nf-chave-label">Chave de acesso</span>
            <div class="od-nf-chave-box">
              <code class="od-nf-chave" id="nf-chave-val"><?= View::e($nf['chaveAcesso_fmt']) ?></code>
              <button type="button" class="od-nf-copy-btn" id="btn-copy-chave"
                      title="Copiar chave">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2.5" stroke-linecap="round">
                  <rect x="9" y="9" width="13" height="13" rx="2"/>
                  <path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/>
                </svg>
              </button>
            </div>
          </div>
          <?php endif; ?>

          <!-- Downloads -->
          <div class="od-nf-downloads">
            <?php if (!empty($nf['url_pdf'])): ?>
            <a href="<?= View::e($nf['url_pdf']) ?>" target="_blank" rel="noopener"
               class="od-nf-dl-btn od-nf-dl-btn--primary">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                   stroke-width="2.5" stroke-linecap="round">
                <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>
                <polyline points="7 10 12 15 17 10"/>
                <line x1="12" y1="15" x2="12" y2="3"/>
              </svg>
              Baixar PDF
            </a>
            <?php endif; ?>
            <?php if (!empty($nf['url_danfe']) && $nf['url_danfe'] !== $nf['url_pdf']): ?>
            <a href="<?= View::e($nf['url_danfe']) ?>" target="_blank" rel="noopener"
               class="od-nf-dl-btn">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                   stroke-width="2.5" stroke-linecap="round">
                <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>
                <polyline points="14 2 14 8 20 8"/>
              </svg>
              DANFE
            </a>
            <?php endif; ?>
            <?php if (!empty($nf['url_xml'])): ?>
            <a href="<?= View::e($nf['url_xml']) ?>" target="_blank" rel="noopener"
               class="od-nf-dl-btn">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                   stroke-width="2.5" stroke-linecap="round">
                <polyline points="16 18 22 12 16 6"/>
                <polyline points="8 6 2 12 8 18"/>
              </svg>
              XML
            </a>
            <?php endif; ?>
          </div>

        </div><!-- /.od-nf-body -->
      </div>
      <?php elseif ($aprovado): ?>
      <!-- NF aprovada mas ainda não emitida -->
      <div class="od-card od-card--sm">
        <h3 class="od-card-title">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2.2" stroke-linecap="round">
            <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>
            <polyline points="14 2 14 8 20 8"/>
          </svg>
          Nota fiscal
        </h3>
        <div style="padding:12px 20px;">
          <p style="font-size:13px;color:var(--c-text-muted);margin:0">
            A nota fiscal será emitida em breve e ficará disponível aqui para download.
          </p>
        </div>
      </div>
      <?php endif; ?>

      <!-- FAQs -->
      <div class="od-card od-card--sm od-faqs-card">
        <h3 class="od-card-title">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 015.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
          Dúvidas sobre o pedido
        </h3>
        <div class="od-faqs-list">
          <?php foreach ($faqs as $faq): ?>
          <button type="button" class="od-faq-btn"
                  data-title="<?= htmlspecialchars($faq['title'], ENT_QUOTES) ?>"
                  data-body="<?= htmlspecialchars($faq['body'], ENT_QUOTES) ?>">
            <span><?= View::e($faq['title']) ?></span>
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="9 18 15 12 9 6"/></svg>
          </button>
          <?php endforeach; ?>
        </div>
      </div>

      

    </div><!-- /.od-aside -->
  </div><!-- /.od-grid -->

</div>

<style>
/* ── Card de Devolução ───────────────────────────── */
.od-returns-card { border-left: 3px solid #f59e0b; }
.od-dev-header {
  display:flex;align-items:center;justify-content:space-between;
  padding:14px 20px;border-bottom:1px solid #f1f5f9;gap:10px;flex-wrap:wrap;
}
.od-dev-header-info { display:flex;align-items:center;gap:10px;flex-wrap:wrap;flex:1; }
.od-dev-link-btn {
  display:inline-flex;align-items:center;gap:5px;font-size:12.5px;font-weight:700;
  color:#2563eb;text-decoration:none;padding:5px 12px;border:1.5px solid #bfdbfe;
  border-radius:8px;background:#eff6ff;transition:background .15s;
  flex-shrink:0;white-space:nowrap;
}
.od-dev-link-btn:hover { background:#dbeafe; }
.od-dev-info-row { display:flex;flex-wrap:wrap;border-bottom:1px solid #f1f5f9; }
.od-dev-info-item {
  display:flex;flex-direction:column;gap:3px;
  padding:12px 20px;border-right:1px solid #f1f5f9;flex:1;min-width:130px;
}
.od-dev-info-item:last-child { border-right:none; }
.od-dev-info-label { font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#94a3b8; }
.od-dev-code { font-family:'SF Mono',Monaco,Consolas,monospace;font-size:14px;font-weight:900;color:#0f172a;letter-spacing:.5px; }
.od-dev-timeline {
  display:flex;gap:0;padding:14px 20px;border-bottom:1px solid #f1f5f9;overflow-x:auto;
}
.od-dev-tl-item { display:flex;align-items:flex-start;gap:8px;flex:1;min-width:110px;position:relative; }
.od-dev-tl-item:not(:last-child)::after {
  content:'';position:absolute;top:8px;left:20px;right:-10px;height:2px;background:#e2e8f0;z-index:0;
}
.od-dev-tl-dot { width:16px;height:16px;border-radius:50%;background:#e2e8f0;flex-shrink:0;margin-top:2px;position:relative;z-index:1; }
.od-dev-tl-dot--first   { background:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.15); }
.od-dev-tl-dot--success { background:#16a34a; }
.od-dev-tl-dot--warning { background:#f59e0b; }
.od-dev-tl-dot--danger  { background:#dc2626; }
.od-dev-tl-dot--info    { background:#0284c7; }
.od-dev-tl-dot--primary { background:#2563eb; }
.od-dev-tl-body strong  { display:block;font-size:12px;font-weight:700;color:#0f172a;line-height:1.3; }
.od-dev-tl-body time    { font-size:11px;color:#94a3b8; }
.od-dev-media { padding:14px 20px; }
.od-dev-media-label { display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#94a3b8;margin-bottom:8px; }
.od-dev-media-grid { display:grid;grid-template-columns:repeat(auto-fill,minmax(68px,1fr));gap:6px; }
.od-dev-media-item {
  display:block;position:relative;aspect-ratio:1;border-radius:8px;
  overflow:hidden;border:1px solid #e2e8f0;background:#f8fafc;
}
.od-dev-media-item img,.od-dev-media-item video { width:100%;height:100%;object-fit:cover;display:block;transition:transform .2s; }
.od-dev-media-item:hover img,.od-dev-media-item:hover video { transform:scale(1.05); }
.od-dev-media-item--video::before { content:'';position:absolute;inset:0;background:rgba(0,0,0,.2);z-index:1; }
.od-dev-media-badge {
  position:absolute;bottom:4px;left:4px;z-index:2;background:rgba(0,0,0,.6);
  color:#fff;font-size:9px;font-weight:800;padding:2px 5px;border-radius:4px;
  display:flex;align-items:center;gap:3px;text-transform:uppercase;
}
.od-dev-rastreio-form { padding:14px 20px;border-top:1px solid #f1f5f9;background:#fafbff; }
/* ── Ver mais histórico ────────────────────────────────── */
.od-ver-mais-hist {
  display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:700;
  color:#2563eb;background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:8px;
  padding:7px 14px;cursor:pointer;transition:background .15s;
}
.od-ver-mais-hist:hover { background:#dbeafe; }

.odf-refund-block{margin-top:10px;background:linear-gradient(135deg,#f0fdf4,#dcfce7);border:1.5px solid #86efac;border-radius:10px;padding:12px 14px;display:flex;flex-direction:column;gap:7px}
.odf-refund-header{display:flex;align-items:center;gap:7px;margin-bottom:2px}
.odf-refund-icon{width:20px;height:20px;background:#16a34a;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.odf-refund-icon svg{stroke:#fff}
.odf-refund-header strong{font-size:13px;font-weight:800;color:#14532d}
.odf-refund-value-row,.odf-refund-net-row{display:flex;justify-content:space-between;align-items:center;font-size:13px;color:#166534}
.odf-refund-amount{font-size:15px;font-weight:900;color:#15803d}
.odf-refund-net-row{font-size:12.5px;color:#4ade80;opacity:.8}
.odf-refund-method{display:flex;align-items:center;gap:6px;font-size:12px;color:#166534;margin-top:2px;opacity:.8}
.odf-refund-method svg{stroke:#16a34a}
.odf-refund-date{font-size:11.5px;color:#4ade80;opacity:.75}

.od-dev-processing {
  display: flex;
  align-items: center;
  gap: 9px;
  padding: 12px 20px;
  font-size: 13.5px;
  color: #92400e;
  background: #fffbeb;
  border-bottom: 1px solid #fde68a;
  width: 100%;
}
@keyframes od-spin { to { transform: rotate(360deg); } }
.od-dev-spin { animation: od-spin .9s linear infinite; flex-shrink: 0; }
</style>

<!-- ══ MODAL FAQ ════════════════════════════════════════ -->
<div id="faq-modal" class="od-modal-overlay" hidden role="dialog" aria-modal="true">
  <div class="od-modal-box">
    <div class="od-modal-header">
      <h4 id="faq-modal-title"></h4>
      <button type="button" class="od-modal-close" id="faq-modal-close" aria-label="Fechar">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div class="od-modal-body" id="faq-modal-body"></div>
  </div>
</div>

<!-- Modal de troca/devolução removido — usa /minha-conta/devolucao/nova/{id} -->
<script>

</script>