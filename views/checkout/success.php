<?php
// views/checkout/success.php — v4
// Hero compacto + timeline horizontal integrada
$metodo          = $pedido['forma_pagamento']  ?? 'cartao';
$statusPagamento = $pedido['status_pagamento'] ?? 'pendente';
$statusPedido    = $pedido['status_pedido']    ?? 'aguardando_pagamento';
$aprovado        = $statusPagamento === 'aprovado';
$isPix           = $metodo === 'pix';
$isBoleto        = $metodo === 'boleto';
$isCartao        = $metodo === 'cartao';
$isCancelado     = $statusPedido === 'cancelado';
$isTroca         = $statusPedido === 'troca_devolucao';

// ── Mapa de progressão numérica ────────────────────────
// Determina em qual "degrau" o pedido está agora.
// Regra: em_separacao e enviado só existem se status_pagamento = aprovado.
$progressao = [
    'aguardando_pagamento' => 0,
    'pagamento_aprovado'   => 1,
    'em_separacao'         => 2,
    'enviado'              => 3,
    'entregue'             => 4,
    'troca_devolucao'      => 5,
    'cancelado'            => -1,  // estado especial, fora da linha
];

// Se status_pagamento não for aprovado, trava no máximo no nível 1
$nivelAtual = $progressao[$statusPedido] ?? 0;
if (!$aprovado && $nivelAtual > 1) {
    $nivelAtual = 0; // pagamento não confirmado, volta ao início
}

// ── Dados que o controller injeta ─────────────────────
$beneficios = $beneficios ?? ['linhas' => [], 'brindes' => [], 'cashback' => 0.0,
                              'total' => 0.0, 'divergencia' => null];
$rastreioDados = $rastreio ?? null;   // vem do RastreioService
$historico     = $historico ?? [];    // status_novo => data
$podeTrocarPagamento = $podeTrocarPagamento ?? false;

// ── Labels e sublabels contextuais ────────────────────
$prazoLabel = $pedido['frete_prazo']        ? (int)$pedido['frete_prazo'] . 'd úteis' : 'Previsão';
$rastreio   = $pedido['codigo_rastreio']
           ?? ($rastreioDados['codigo_rastreio'] ?? null);
$pagoEm     = !empty($pedido['pago_em'])
    ? date('d/m/Y H:i', strtotime($pedido['pago_em']))
    : null;

/** Data em que o pedido ENTROU num status, se registrada. */
$dataDe = function (string ...$status) use ($historico): ?string {
    foreach ($status as $st) {
        if (!empty($historico[$st])) return date('d/m · H:i', strtotime($historico[$st]));
    }
    return null;
};

// Previsão de entrega: a da transportadora manda, porque é a que foi
// medida de verdade. O prazo do frete é estimativa da hora da compra.
$previsaoEntrega = null;
if (!empty($rastreioDados['previsao_entrega'])) {
    $previsaoEntrega = date('d/m/Y', strtotime((string) $rastreioDados['previsao_entrega']));
} elseif (!empty($pedido['frete_prazo']) && !empty($historico['enviado'])) {
    $previsaoEntrega = date('d/m/Y', strtotime(
        (string) $historico['enviado'] . ' +' . (int) $pedido['frete_prazo'] . ' weekdays'
    ));
}

// ── Define os steps da timeline ───────────────────────
// Cada step: [nivel, label_done, label_active, label_future, sub_done, sub_active, sub_future]
$stepDefs = [
    [
        'nivel'       => 0,
        'label_done'  => 'Pago',
        'label_act'   => 'Aguardando',
        'label_fut'   => 'Pagamento',
        'sub_done'    => $pagoEm ? 'Em ' . $pagoEm : ($dataDe('pagamento_aprovado') ?? 'Aprovado'),
        'sub_act'     => match($metodo) {
            'pix'    => 'Aguardando Pix',
            'boleto' => 'Aguardando boleto',
            default  => 'Cartão pendente',
        },
        'sub_fut'     => '—',
    ],
    [
        'nivel'       => 2,
        'label_done'  => 'Separado',
        'label_act'   => 'Em separação',
        'label_fut'   => 'Separação',
        'sub_done'    => $dataDe('em_separacao') ?? 'Pronto para envio',
        'sub_act'     => ($dataDe('em_separacao') ?? '') ?: 'Preparando o pedido',
        'sub_fut'     => $aprovado ? 'Em breve' : 'Aguardando pgto.',
    ],
    [
        'nivel'       => 3,
        'label_done'  => 'Enviado',
        'label_act'   => 'Enviando',
        'label_fut'   => 'Envio',
        'sub_done'    => $dataDe('enviado') ?? ($rastreio ? 'Rastreio: ' . $rastreio : 'Em transporte'),
        'sub_act'     => $dataDe('enviado') ?? 'Preparando envio',
        'sub_fut'     => 'Aguardando separação',
    ],
    [
        'nivel'       => 4,
        'label_done'  => 'Entregue',
        'label_act'   => 'A caminho',
        'label_fut'   => 'Entrega',
        'sub_done'    => $dataDe('entregue') ?? 'Pedido recebido!',
        // Em rota: a previsão vale mais que o prazo genérico — é a data que
        // a pessoa vai esperar em casa.
        'sub_act'     => $previsaoEntrega ? 'Até ' . $previsaoEntrega : $prazoLabel,
        'sub_fut'     => $previsaoEntrega ? 'Até ' . $previsaoEntrega : $prazoLabel,
    ],
];

// Se troca/devolução, adiciona um passo extra
if ($isTroca) {
    $stepDefs[] = [
        'nivel'       => 5,
        'label_done'  => 'Troca/Dev.',
        'label_act'   => 'Em processo',
        'label_fut'   => 'Troca/Dev.',
        'sub_done'    => 'Concluído',
        'sub_act'     => 'Em análise',
        'sub_fut'     => '—',
    ];
}

// ── Gera array final de steps para a view ─────────────
$steps = array_map(function ($def) use ($nivelAtual, $isCancelado) {
    $nivel  = $def['nivel'];
    // Passo 0 (pagamento) é especial: "done" começa no nível 1+
    $isDone   = $isCancelado ? false : ($nivel === 0 ? $nivelAtual >= 1 : $nivelAtual > $nivel);
    $isActive = $isCancelado ? false : ($nivel === 0 ? $nivelAtual === 0 : $nivelAtual === $nivel);
    $isFuture = !$isDone && !$isActive;

    return [
        'done'   => $isDone,
        'active' => $isActive,
        'label'  => $isDone ? $def['label_done'] : ($isActive ? $def['label_act'] : $def['label_fut']),
        'sub'    => $isDone ? $def['sub_done']   : ($isActive ? $def['sub_act']   : $def['sub_fut']),
    ];
}, $stepDefs);
?>

<div class="success-wrapper">

  <!-- ═══════════════════════════════════════════════
       HERO — ícone + texto + código + timeline
       ═══════════════════════════════════════════════ -->
  <?php
    $heroClass = 'sh--pending';
    if ($isCancelado)                        $heroClass = 'sh--cancelled';
    elseif ($statusPedido === 'entregue')    $heroClass = 'sh--approved sh-entregue';
    elseif ($aprovado)                       $heroClass = 'sh--approved';
  ?>
  <div class="success-hero <?= $heroClass ?>">

    <!-- Parte superior: ícone + texto + código -->
    <div class="sh-top">

      <!-- Ícone animado -->
      <div class="sh-icon-wrap">
        <div class="sh-icon">
          <?php if ($isCancelado): ?>
          <svg viewBox="0 0 52 52" fill="none">
            <circle cx="26" cy="26" r="23" stroke="currentColor" stroke-width="2.2"/>
            <line x1="17" y1="17" x2="35" y2="35" stroke="currentColor" stroke-width="2.8" stroke-linecap="round"/>
            <line x1="35" y1="17" x2="17" y2="35" stroke="currentColor" stroke-width="2.8" stroke-linecap="round"/>
          </svg>
          <?php elseif ($aprovado): ?>
          <svg viewBox="0 0 52 52" fill="none">
            <circle class="sh-circle" cx="26" cy="26" r="23"
                    stroke="currentColor" stroke-width="2.2"/>
            <polyline class="sh-check" points="14,27 22,35 39,17"
                      stroke="currentColor" stroke-width="2.8"
                      stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
          <?php else: ?>
          <svg viewBox="0 0 52 52" fill="none">
            <circle cx="26" cy="26" r="23" stroke="currentColor" stroke-width="2.2"/>
            <line x1="26" y1="14" x2="26" y2="26" stroke="currentColor" stroke-width="2.8" stroke-linecap="round"/>
            <line x1="26" y1="26" x2="34" y2="32" stroke="currentColor" stroke-width="2.8" stroke-linecap="round"/>
          </svg>
          <?php endif; ?>
        </div>
      </div>

      <!-- Título + subtítulo -->
      <h1 class="sh-title">
        <?php if ($isCancelado): ?>Pedido cancelado
        <?php elseif ($statusPedido === 'entregue'): ?>Pedido entregue!
        <?php elseif ($statusPedido === 'enviado'): ?>Pedido enviado!
        <?php elseif ($statusPedido === 'em_separacao'): ?>Em separação
        <?php elseif ($statusPedido === 'troca_devolucao'): ?>Em troca/devolução
        <?php elseif ($aprovado): ?>Pedido confirmado!
        <?php elseif ($isPix): ?>Aguardando Pix
        <?php elseif ($isBoleto): ?>Aguardando boleto
        <?php else: ?>Pedido recebido!<?php endif; ?>
      </h1>
      <p class="sh-sub">
        <?php if ($isCancelado): ?>
          Este pedido foi cancelado. Em caso de dúvidas, entre em contato com o suporte.
        <?php elseif ($statusPedido === 'entregue'): ?>
          Seu pedido foi entregue. Obrigado pela compra!
          <strong><?= View::e($pedido['cliente_email'] ?? '') ?></strong>
        <?php elseif ($statusPedido === 'enviado'): ?>
          Seu pedido está a caminho.<?= $rastreio ? ' Rastreio: <strong>' . View::e($rastreio) . '</strong>' : '' ?>
        <?php elseif ($aprovado): ?>
          Pagamento aprovado · E-mail enviado para
          <strong><?= View::e($pedido['cliente_email'] ?? '') ?></strong>
        <?php elseif ($isPix): ?>
          Efetue o pagamento abaixo · Confirmação em segundos
        <?php else: ?>
          Após compensação do boleto (1–2 dias úteis) seu pedido é separado
        <?php endif; ?>
      </p>

      <!-- Código do pedido + rastreio -->
      <div class="sh-codes">
        <a href="<?= View::url('minha-conta/pedido/'.$pedido['id']); ?>" class="sh-code">
          <span class="sh-code-label">Pedido</span>
          <span class="sh-code-value"><?= View::e($pedido['codigo']) ?></span>
        </a>

        <?php if ($rastreio): ?>
        <!-- Ao lado do pedido de propósito: depois do envio é este o número
             que a pessoa procura, e ficava enterrado na timeline. -->
        <button type="button" class="sh-code sh-code--rastreio" data-copiar="<?= View::e($rastreio) ?>">
          <span class="sh-code-label">Rastreio</span>
          <span class="sh-code-value"><?= View::e($rastreio) ?></span>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="9" y="9" width="13" height="13" rx="2"/>
            <path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/>
          </svg>
        </button>
        <?php endif; ?>
      </div>

    </div><!-- /.sh-top -->

    <!-- ───────────────────────────────────────────────
         TIMELINE HORIZONTAL
         ─────────────────────────────────────────────── -->
    <div class="sh-timeline">
      <?php foreach ($steps as $i => $step):
        $isLast = $i === count($steps) - 1;
        $cls    = $step['done'] ? 'tl-done' : ($step['active'] ? 'tl-active' : 'tl-future');
        // Linha entre passos: verde se passo atual está done, branca semitransparente se não
        $lineCls = $step['done'] ? 'tl-line--done' : '';
      ?>
      <?php
        $tlExtra = '';
        if ($step['done'] && $i === count($steps)-1 && $statusPedido === 'entregue') $tlExtra = 'tl-entregue';
        if ($step['active'] && $statusPedido === 'troca_devolucao') $tlExtra = 'tl-troca';
      ?>
      <div class="tl-step <?= $cls ?> <?= $tlExtra ?>">

        <!-- Ponto -->
        <div class="tl-dot">
          <?php if ($step['done']): ?>
            <svg viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
              <polyline points="2,7 5.5,10.5 12,3"/>
            </svg>
          <?php elseif ($step['active']): ?>
            <div class="tl-dot-inner"></div>
          <?php endif; ?>
        </div>

        <!-- Linha conectora (exceto último) -->
        <?php if (!$isLast): ?>
        <div class="tl-line <?= $lineCls ?>"></div>
        <?php endif; ?>

        <!-- Texto -->
        <div class="tl-label">
          <strong><?= $step['label'] ?></strong>
          <small><?= $step['sub'] ?></small>
        </div>

      </div>
      <?php endforeach; ?>
    </div><!-- /.sh-timeline -->

  </div><!-- /.success-hero -->

  <!-- ═══════════ BANNER CANCELADO ════════════════════ -->
  <?php if ($isCancelado): ?>
  <div class="success-cancelled-banner">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="2.5" stroke-linecap="round">
      <circle cx="12" cy="12" r="10"/>
      <line x1="15" y1="9" x2="9" y2="15"/>
      <line x1="9" y1="9" x2="15" y2="15"/>
    </svg>
    <div>
      <strong>Pedido cancelado</strong>
      <span>
        Se você foi cobrado, o estorno será processado em até 5 dias úteis.
        Dúvidas? <a href="<?= BASE_URL ?>/contato">Entre em contato</a>.
      </span>
    </div>
  </div>
  <?php endif; ?>

  <!-- ═══════════════ PIX ════════════════════════════ -->
  <?php if ($isPix && !empty($pixDados)): ?>
  <div class="success-pix-card">
    <div class="success-pix-info">
      <h3 class="success-section-title">
        <span class="pix-chip">PIX</span> Pague com Pix
      </h3>
      <p>Abra o app do banco, escolha <strong>Pix</strong> e escaneie o QR Code ou copie o código.</p>
      <div class="pix-copy-row">
        <input id="pix-copia-input" type="text" class="form-control"
               readonly value="<?= View::e($pixDados['copia_cola']) ?>">
        <button type="button" class="btn btn-primary" id="btn-copiar-pix">Copiar</button>
      </div>
      <?php if (!empty($pixDados['expirado'])): ?>
      <div class="pix-timer pix-timer--expirado" id="pix-timer">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        Este QR Code expirou. <a href="<?= BASE_URL ?>/checkout">Refazer o pagamento</a>
      </div>
      <?php elseif ($pixDados['expira_em']): ?>
      <div class="pix-timer" id="pix-timer">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        Expira em <strong id="pix-countdown">--:--</strong>
      </div>
      <?php endif; ?>
      <ol class="pix-steps">
        <li>Abra o app do seu banco ou carteira digital</li>
        <li>Escolha pagar com <strong>Pix</strong></li>
        <li>Escaneie o QR Code <em>ou</em> cole o código</li>
        <li>Confirme o pagamento de <strong><?= PriceHelper::format((float)$pedido['total']) ?></strong></li>
      </ol>
    </div>
    <?php
    // So e imagem se der para colocar num <img>. O adapter ja normaliza o
    // base64 cru do Mercado Pago em data: URI — antes disto a condicao dava
    // falso sempre e a tela mostrava um retangulo cinza no lugar do QR.
    $qr = (string) $pixDados['qr_code'];
    $temImagem = str_starts_with($qr, 'data:') || str_starts_with($qr, 'http');
    ?>
    <?php if ($temImagem && empty($pixDados['expirado'])): ?>
    <div class="success-pix-qr">
      <img src="<?= View::e($qr) ?>" alt="QR Code Pix" class="pix-qr-img">
      <small>Escaneie com a câmera do celular</small>
    </div>
    <?php elseif (empty($pixDados['expirado'])): ?>
    <div class="success-pix-qr success-pix-qr--sem-imagem">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"
           stroke-linecap="round" stroke-linejoin="round">
        <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/>
        <rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM18 18h3v3h-3z"/>
      </svg>
      <small>
        Esta operadora não gerou a imagem do QR Code.<br>
        Use o botão <strong>Copiar</strong> e cole o código no app do banco.
      </small>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- ═══════════════ BOLETO ═════════════════════════ -->
  <?php if ($isBoleto): ?>
  <div class="success-boleto-card">
    <strong class="success-boleto-icon">|||</strong>
    <div>
      <h3 class="success-section-title">Boleto bancário</h3>
      <p>Pague em qualquer banco ou lotérica. Vencimento em <strong>3 dias úteis</strong>.</p>
      <?php if (!empty($pedido['boleto_url'])): ?>
        <a href="<?= View::e($pedido['boleto_url']) ?>" target="_blank" rel="noopener"
           class="btn btn-primary" style="margin-top:12px;display:inline-flex;gap:6px;align-items:center;">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          Baixar boleto
        </a>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ═══════════════ RASTREIO ═══════════════════════ -->
  <?php if ($rastreioDados && !empty($rastreioDados['codigo_rastreio'])):
    $evs      = $rastreioDados['eventos'] ?? [];
    $ultimo   = $evs[0] ?? null;              // o service já ordena do mais novo
    $entregue = ($rastreioDados['status_interno'] ?? '') === 'entregue';
  ?>
  <div class="success-track-card <?= $entregue ? 'stc--entregue' : '' ?>">

    <div class="stc-head">
      <div class="stc-ico">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 3v5h-7V8z"/>
          <circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>
        </svg>
      </div>
      <div class="stc-resumo">
        <strong><?= View::e($rastreioDados['status_label'] ?? 'Em transporte') ?></strong>
        <small>
          <?php if ($ultimo): ?>
            <?= View::e($ultimo['descricao'] ?? '') ?>
            <?php if (!empty($ultimo['local'])): ?> · <?= View::e($ultimo['local']) ?><?php endif; ?>
            <?php if (!empty($ultimo['data_evento'])): ?>
              · <?= date('d/m · H:i', strtotime($ultimo['data_evento'])) ?>
            <?php endif; ?>
          <?php else: ?>
            Objeto postado. Os eventos aparecem conforme a transportadora atualiza.
          <?php endif; ?>
        </small>
      </div>
      <?php if (!empty($rastreioDados['atraso'])): ?>
        <span class="stc-flag stc-flag--atraso">Atrasado</span>
      <?php elseif (!empty($rastreioDados['ocorrencia'])): ?>
        <span class="stc-flag stc-flag--ocorrencia">Ocorrência</span>
      <?php endif; ?>
    </div>

    <div class="stc-meta">
      <?php if (!empty($rastreioDados['transportadora_nome'])): ?>
        <span><b>Transportadora</b> <?= View::e($rastreioDados['transportadora_nome']) ?></span>
      <?php endif; ?>
      <span><b>Código</b> <?= View::e($rastreioDados['codigo_rastreio']) ?></span>
      <?php if (!$entregue && $previsaoEntrega): ?>
        <span><b>Previsão</b> <?= $previsaoEntrega ?></span>
      <?php elseif ($entregue && !empty($rastreioDados['entregue_em'])): ?>
        <span><b>Entregue em</b> <?= date('d/m/Y · H:i', strtotime($rastreioDados['entregue_em'])) ?></span>
      <?php endif; ?>
    </div>

    <?php if ($evs): ?>
    <!-- Todos os eventos, na própria página: mandar para outra aba só para
         ler o histórico é perder o contexto do pedido. -->
    <button type="button" class="stc-toggle" aria-expanded="false" aria-controls="stc-linha">
      <span class="stc-toggle-abrir">Ver rastreamento completo (<?= count($evs) ?>)</span>
      <span class="stc-toggle-fechar">Recolher rastreamento</span>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="6 9 12 15 18 9"/></svg>
    </button>

    <ol class="stc-linha" id="stc-linha" hidden>
      <?php foreach ($evs as $i => $ev): ?>
      <li class="stc-ev <?= $i === 0 ? 'stc-ev--atual' : '' ?>">
        <span class="stc-ev-dot"></span>
        <div class="stc-ev-txt">
          <strong><?= View::e($ev['status_label'] ?? $ev['status_interno'] ?? '') ?></strong>
          <?php if (!empty($ev['descricao'])): ?>
            <span><?= View::e($ev['descricao']) ?></span>
          <?php endif; ?>
          <small>
            <?php if (!empty($ev['data_evento'])): ?>
              <?= date('d/m/Y · H:i', strtotime($ev['data_evento'])) ?>
            <?php endif; ?>
            <?php if (!empty($ev['local'])): ?> · <?= View::e($ev['local']) ?><?php endif; ?>
          </small>
        </div>
      </li>
      <?php endforeach; ?>
    </ol>
    <?php endif; ?>

    <?php if (!empty($rastreioDados['token_publico'])): ?>
    <a class="stc-publico" target="_blank" rel="noopener"
       href="<?= BASE_URL ?>/rastreio/<?= View::e($rastreioDados['token_publico']) ?>">
      Abrir página de rastreio
    </a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <!-- ═══════════════ GRID 2 COLUNAS ════════════════ -->
  <div class="success-grid">

    <!-- Itens + totais -->
    <div class="success-col-main">
      <div class="success-card">
        <h3 class="success-card-title">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>
          <?= count($itens) ?> <?= count($itens) === 1 ? 'item' : 'itens' ?>
        </h3>
        <?php foreach ($itens as $item):
          $imgUrl = $item['imagem'];
          // $imgUrl = ImageHelper::getCartItemImage($item['pro_id']);

          // $imgUrl  = !empty($item['imagem']) ? BASE_URL.'/uploads/produtos/'.$item['imagem'] : null;
          $vFinal  = (float)$item['valor_final_item'];
          $vOrig   = (float)$item['valor_original'] * (int)$item['quantidade'];
          $hasDesc = $vOrig > $vFinal + 0.01;
        ?>
        <div class="success-item">
          <div class="success-item-thumb">
            <?php if ($imgUrl): ?>
              <img src="<?= View::e($imgUrl) ?>" alt="<?= View::e($item['produto_nome']) ?>" loading="lazy">
            <?php else: ?>
              <div class="success-item-thumb-ph">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
              </div>
            <?php endif; ?>
          </div>
          <div class="success-item-info">
            <a href="<?= BASE_URL ?>/produto/<?= View::e($item['produto_slug']??'') ?>" class="success-item-name">
              <?= View::e($item['produto_nome']) ?>
            </a>
            <span class="success-item-qty">Qtd: <?= (int)$item['quantidade'] ?></span>
            <?php
            // A VARIAÇÃO VEM DO SNAPSHOT, NÃO DO CATÁLOGO.
            //
            // `atributos` é resolvido a partir do produto de hoje e volta
            // vazio para a maioria dos itens — era por isso que "Tamanho:
            // 64/XXL" sumia da tela. `opcoes_selecionadas` foi gravado no
            // momento da compra: é o que o cliente realmente escolheu, e
            // sobrevive a mudanças posteriores no cadastro do produto.
            $variacoes = [];
            foreach (['opcoes_selecionadas', 'opcoes_snapshot'] as $campo) {
                $bruto = $item[$campo] ?? null;
                $vals  = is_array($bruto) ? $bruto : json_decode((string) $bruto, true);
                if (is_array($vals) && $vals) { $variacoes = $vals; break; }
            }
            ?>
            <div class="var-iten">
              <?php if (!empty($item['atributos'])): ?>
              <div class="cart-item-attrs">
                <?php foreach ($item['atributos'] as $attr): ?>
                <span class="cart-attr-tag">
                  <?php if ($attr['tipo_display'] === 'color_swatch' && !empty($attr['valor_hex'])): ?>
                    <span class="cart-attr-swatch"
                          style="background:<?= View::e($attr['valor_hex']) ?>"></span>
                  <?php else: ?>
                    <span class="cart-attr-label"><?= View::e($attr['nome']) ?>:</span>
                  <?php endif; ?>
                  <span class="cart-attr-valor"><?= View::e($attr['valor']) ?></span>
                </span>
                <?php endforeach; ?>
              </div>
              <?php elseif ($variacoes): ?>
              <div class="cart-item-attrs">
                <?php foreach ($variacoes as $nome => $valor): ?>
                  <?php if (is_array($valor)) $valor = implode(', ', $valor); ?>
                <span class="cart-attr-tag">
                  <?php if (!is_int($nome)): ?>
                    <span class="cart-attr-label"><?= View::e((string) $nome) ?>:</span>
                  <?php endif; ?>
                  <span class="cart-attr-valor"><?= View::e((string) $valor) ?></span>
                </span>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>
              <?php if (!empty($item['sku'])): ?>
                <span class="success-item-sku">SKU <?= View::e($item['sku']) ?></span>
              <?php endif; ?>
              <?php if (!empty($item['is_brinde'])): ?>
                <span class="success-item-brinde">Brinde</span>
              <?php endif; ?>
            </div>
          </div>
          <div class="success-item-price">
            <?php if ($hasDesc): ?><s><?= PriceHelper::format($vOrig) ?></s><?php endif; ?>
            <strong><?= PriceHelper::format($vFinal) ?></strong>
          </div>
        </div>
        <?php endforeach; ?>

        <div class="success-totals">
          <div class="success-tr"><span>Subtotal</span><span><?= PriceHelper::format((float)$pedido['subtotal']) ?></span></div>
          <?php
          // Uma linha por origem. "Desconto R$ 55" não dizia se veio de
          // cupom, de promoção ou de crédito, e o cliente não tinha como
          // conferir nenhum dos dois.
          foreach ($beneficios['linhas'] as $ben):
            if ($ben['tipo'] === 'frete') continue;   // aparece na linha do frete
          ?>
          <div class="success-tr success-tr--green">
            <span class="success-tr-ben">
              <span class="ben-tag ben-tag--<?= View::e($ben['tipo']) ?>"><?= View::e($ben['rotulo']) ?></span>
              <?php if (!empty($ben['detalhe'])): ?>
                <small><?= View::e($ben['detalhe']) ?></small>
              <?php endif; ?>
            </span>
            <span>−<?= PriceHelper::format($ben['valor']) ?></span>
          </div>
          <?php endforeach; ?>

          <?php if ($beneficios['divergencia'] !== null && $beneficios['divergencia'] > 0): ?>
          <!-- O detalhado não fechou com o total gravado. Nomear a diferença
               é melhor do que deixar a soma errada na tela do cliente. -->
          <div class="success-tr success-tr--green">
            <span class="success-tr-ben"><span class="ben-tag ben-tag--outro">Outros descontos</span></span>
            <span>−<?= PriceHelper::format($beneficios['divergencia']) ?></span>
          </div>
          <?php endif; ?>
          <?php
          $linhaFrete = null;
          foreach ($beneficios['linhas'] as $b) if ($b['tipo'] === 'frete') $linhaFrete = $b;
          ?>
          <div class="success-tr">
            <span>
              Frete
              <?php if ($linhaFrete && !empty($linhaFrete['detalhe'])): ?>
                <small class="success-tr-fonte"><?= View::e($linhaFrete['detalhe']) ?></small>
              <?php endif; ?>
            </span>
            <span><?= (float)$pedido['frete']==0 ? '<strong class="c-green">GRÁTIS</strong>' : PriceHelper::format((float)$pedido['frete']) ?></span>
          </div>
          <div class="success-tr success-tr--total">
            <span>Total</span><strong><?= PriceHelper::format((float)$pedido['total']) ?></strong>
          </div>

          <?php if (!empty($beneficios['brindes'])): ?>
          <div class="success-tr success-tr--brinde">
            <span>Brindes inclusos</span>
            <span><?php foreach ($beneficios['brindes'] as $i => $br):
              echo ($i ? ', ' : '') . View::e($br['nome'])
                 . ($br['quantidade'] > 1 ? ' x' . (int) $br['quantidade'] : '');
            endforeach; ?></span>
          </div>
          <?php endif; ?>

          <?php if (($beneficios['cashback'] ?? 0) > 0): ?>
          <!-- Cashback não abate agora: vira crédito depois. Somar junto ao
               desconto prometeria um abatimento que não aconteceu. -->
          <div class="success-tr success-tr--cashback">
            <span>Cashback a receber</span>
            <strong><?= PriceHelper::format((float) $beneficios['cashback']) ?></strong>
          </div>
          <?php endif; ?>
          <?php if ($isCartao && (int)($pedido['parcelas']??1) > 1): ?>
          <div class="success-tr success-tr--parcelas">
            <span></span>
            <small><?= (int)$pedido['parcelas'] ?>x de <?= PriceHelper::format((float)$pedido['total']/(int)$pedido['parcelas']) ?></small>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Coluna lateral -->
    <div class="success-col-side">

      <!-- Endereço -->
      <div class="success-card">
        <h3 class="success-card-title">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
          Endereço de entrega
        </h3>
        <div class="success-address">
          <strong><?= View::e($pedido['nome_destinatario']??'') ?></strong>
          <p><?= View::e($pedido['logradouro']??'') ?>, <?= View::e($pedido['numero']??'') ?>
            <?php if (!empty($pedido['complemento'])): ?> — <?= View::e($pedido['complemento']) ?><?php endif; ?>
          </p>
          <p><?= View::e($pedido['bairro']??'') ?> — <?= View::e($pedido['cidade']??'') ?>/<?= View::e($pedido['estado']??'') ?></p>
          <p class="address-cep">CEP <?= View::e($pedido['cep']??'') ?></p>
          <?php if (!empty($pedido['frete_descricao'])): ?>
          <span class="frete-tag">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
            <?= View::e($pedido['frete_descricao']) ?>
            <?php if ($pedido['frete_prazo']): ?> · <?= (int)$pedido['frete_prazo'] ?> dia(s) útil(eis)<?php endif; ?>
          </span>
          <?php endif; ?>
        </div>
      </div>

      <!-- Pagamento -->
      <?php
      // O CARTÃO PENDENTE OU RECUSADO TAMBÉM PRECISA APARECER.
      //
      // A condição antiga era `$aprovado || $isPix || $isBoleto`: um pedido
      // no cartão que ainda não passou não mostrava card de pagamento nenhum
      // — nem a bandeira, nem o final, nem o motivo. Justo o caso em que o
      // cliente mais precisa ver com o que tentou pagar.
      ?>
      <?php if ($aprovado || $isPix || $isBoleto || $isCartao): ?>
      <div class="success-card">
        <h3 class="success-card-title">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
          <?php
          $recusado = in_array($statusPagamento, ['recusado', 'erro', 'falhou'], true);
          echo $aprovado ? 'Pagamento aprovado'
             : ($recusado ? 'Pagamento não autorizado' : 'Pagamento pendente');
          ?>
        </h3>

        <?php if ($isCartao): ?>
        <!-- Cartão: bandeira + últimos 4 + parcelas -->
        <div class="success-payment-row">
          <div class="success-payment-check <?= $aprovado ? '' : 'spr--pending' ?>">
            <?php if ($aprovado): ?>
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
            <?php else: ?>
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            <?php endif; ?>
          </div>
          <div class="spr-info">
            <?php
              $bandeira   = $pedido['cartao_bandeira']  ?? null;
              $ultimos4   = $pedido['cartao_ultimos_4'] ?? null;
              $parcelas   = (int)($pedido['parcelas'] ?? 1);
            ?>
            <?php if ($bandeira): ?>
              <div class="spr-brand-row">
                <span class="spr-brand-icon saved-card-brand-icon">
                  <?= IconLibrary::logo($bandeira, 36, 24) ?>
                </span>
                <strong><?= View::e(IconLibrary::name($bandeira)) ?></strong>
                <?php if ($ultimos4): ?>
                  <span class="spr-last4">•••• <?= View::e($ultimos4) ?></span>
                <?php endif; ?>
              </div>
            <?php else: ?>
              <strong>Cartão de crédito</strong>
            <?php endif; ?>
            <small>
              <?php if ($parcelas > 1): ?>
                <?= $parcelas ?>× de <?= PriceHelper::format((float) $pedido['total'] / $parcelas) ?>
              <?php else: ?>
                À vista
              <?php endif; ?>
              <?php if ($aprovado && $pedido['pago_em']): ?>
                · Aprovado em <?= date('d/m/Y H:i', strtotime($pedido['pago_em'])) ?>
              <?php elseif ($recusado): ?>
                · Não autorizado pelo emissor
              <?php else: ?>
                · Aguardando confirmação
              <?php endif; ?>
            </small>
          </div>
        </div>

        <?php elseif ($isPix): ?>
        <!-- Pix: chip + data de aprovação -->
        <div class="success-payment-row">          
          <div class="success-payment-check <?= $aprovado ? '' : 'spr--pending' ?>">
            <?php if ($aprovado): ?>
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
            <?php else: ?>
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            <?php endif; ?>
          </div>
          <div class="spr-info">
            <div class="spr-brand-row">
              <span class="spr-brand-icon saved-card-brand-icon">
                <?= IconLibrary::logo('pix', 36, 24) ?>
              </span>
              <strong><?= IconLibrary::name('pix') ?></strong>    
            </div>        
            <small>
              <?php if ($aprovado && $pedido['pago_em']): ?>
                Aprovado em <?= date('d/m/Y', strtotime($pedido['pago_em'])) ?>
                às <?= date('H:i', strtotime($pedido['pago_em'])) ?>
              <?php else: ?>
                Aguardando pagamento
              <?php endif; ?>
            </small>
          </div>
        </div>

        <?php elseif ($isBoleto): ?>
        <!-- Boleto: ícone + data de aprovação -->
        <div class="success-payment-row">
          <div class="spr-boleto-icon">|||</div>
          <div class="spr-info">
            <strong>Boleto bancário</strong>
            <small>
              <?php if ($aprovado && $pedido['pago_em']): ?>
                Compensado em <?= date('d/m/Y', strtotime($pedido['pago_em'])) ?>
                às <?= date('H:i', strtotime($pedido['pago_em'])) ?>
              <?php else: ?>
                Aguardando compensação
              <?php endif; ?>
            </small>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($podeTrocarPagamento): ?>
        <!-- SÓ ENQUANTO NÃO HÁ COBRANÇA ACEITA.
             Depois de aprovado, "trocar" seria uma segunda cobrança — o
             caminho ali é cancelamento, não troca. Quem decide é o
             controller, que olha o status_pagamento. -->
        <a href="<?= BASE_URL ?>/checkout/pagamento/trocar/<?= View::e($pedido['codigo']) ?>"
           class="success-trocar-pgto"
           data-confirmar="Isto cancela este pedido e devolve os itens ao carrinho para você escolher outra forma de pagamento. Continuar?">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
               stroke-linecap="round" stroke-linejoin="round">
            <polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 014-4h14"/>
            <polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 01-4 4H3"/>
          </svg>
          Mudar a forma de pagamento
        </a>
        <?php endif; ?>

      </div>
      <?php endif; ?>

    </div>
  </div><!-- /.success-grid -->

  <!-- CTAs -->
  <div class="success-ctas">
    <a href="<?= View::url('/minha-conta/pedidos') ?>" class="btn btn-outline success-cta-btn">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/></svg>
      Meus pedidos
    </a>
    <a href="<?= BASE_URL ?>" class="btn btn-primary success-cta-btn">
      Continuar comprando
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
    </a>
  </div>

</div><!-- /.success-wrapper -->


<?php if (!empty($purchasePixel)): ?>
<script>
  (function () {
    if (!window.smPixel) return;
    window.smPixel.track('Purchase', {
      value: <?= (float)$purchasePixel['value'] ?>,
      currency: 'BRL',
      content_type: 'product',
      num_items: <?= (int)$purchasePixel['num_items'] ?>,
      content_ids: <?= json_encode(array_map('strval', $purchasePixel['content_ids'])) ?>
    }, <?= json_encode($purchasePixel['event_id']) ?>); // ← codigo = dedup com CAPI
  })();
</script>
<?php endif; ?>



<script>
$(function () {

  // ── Animação de entrada ────────────────────────────────────────
  setTimeout(function () {
    $('.sh-circle').addClass('animated');
    setTimeout(function () { $('.sh-check').addClass('animated'); }, 420);
  }, 120);

  // ── Copiar PIX ────────────────────────────────────────────────
  $('#btn-copiar-pix').on('click', function () {
    var $b = $(this), code = $('#pix-copia-input').val();
    navigator.clipboard && navigator.clipboard.writeText(code).then(function () {
      $b.text('Copiado ✓').addClass('btn-copied');
      setTimeout(function () { $b.text('Copiar').removeClass('btn-copied'); }, 3000);
    });
  });

  // ── Countdown de expiração do PIX ────────────────────────────
  var expira = <?= !empty($pixDados['expira_em']) && empty($pixDados['expirado'])
                    ? json_encode($pixDados['expira_em']) : 'null' ?>;

  // Vem do MySQL como "2026-08-29 15:10:21". O Safari devolve Invalid Date
  // para esse formato; trocar o espaco por "T" faz os dois lerem igual.
  var fim = expira ? new Date(String(expira).replace(' ', 'T')).getTime() : NaN;

  function expirou() {
    $('#pix-timer')
      .addClass('pix-timer--expirado')
      .html('Este QR Code expirou. <a href="<?= BASE_URL ?>/checkout">Refazer o pagamento</a>');
  }

  if (expira && !isNaN(fim)) {
    var pinta = function () {
      var d = Math.max(0, Math.floor((fim - Date.now()) / 1000));

      $('#pix-countdown').text(
        String(Math.floor(d / 60)).padStart(2, '0') + ':' + String(d % 60).padStart(2, '0')
      );

      // Pinta ANTES de decidir parar. A versao anterior saia sem escrever,
      // entao o ultimo valor visivel era 00:01 — e a tela ficava parada ali,
      // oferecendo um QR que o banco ja tinha recusado.
      if (d <= 0) { clearInterval(iv); expirou(); }
    };
    var iv = setInterval(pinta, 1000);
    pinta();
  } else if (expira) {
    // Data ilegivel: melhor nao mostrar contagem nenhuma do que mostrar uma
    // errada. O cliente ainda consegue pagar pelo copia e cola.
    $('#pix-timer').remove();
  }

  // ── Confirma antes de refazer o pagamento ─────────────────────
  // A ação cancela o pedido atual; um clique sem querer não pode fazer isso.
  $(document).on('click', '[data-confirmar]', function (e) {
    if (!window.confirm($(this).data('confirmar'))) e.preventDefault();
  });

  // ── Copiar o código de rastreio ───────────────────────────────
  $(document).on('click', '[data-copiar]', function () {
    var $b = $(this), txt = $b.data('copiar');
    if (!navigator.clipboard) return;
    navigator.clipboard.writeText(String(txt)).then(function () {
      $b.addClass('is-copiado');
      setTimeout(function () { $b.removeClass('is-copiado'); }, 1800);
    });
  });

  // ── Abrir/fechar o rastreamento completo ──────────────────────
  // `hidden` em vez de display: o conteúdo continua no DOM e o leitor de
  // tela acompanha o aria-expanded do botão.
  $(document).on('click', '.stc-toggle', function () {
    var $b = $(this), aberto = $b.attr('aria-expanded') === 'true';
    $b.attr('aria-expanded', aberto ? 'false' : 'true');
    $('#' + $b.attr('aria-controls')).prop('hidden', aberto);
  });

  // ── Confete (cartão aprovado) ─────────────────────────────────
  <?php if ($aprovado && $isCartao): ?>
  (function () {
    var c = document.createElement('canvas');
    c.style.cssText = 'position:fixed;inset:0;width:100%;height:100%;pointer-events:none;z-index:9998';
    document.body.appendChild(c);
    var ctx = c.getContext('2d'); c.width = innerWidth; c.height = innerHeight;
    var P = [], cols = ['#2563eb','#4ade80','#f59e0b','#f472b6','#a78bfa','#34d399'];
    for (var i = 0; i < 140; i++) {
      P.push({ x: Math.random()*c.width, y: Math.random()*-c.height,
               w: 7+Math.random()*6, h: 4+Math.random()*4,
               vx: (Math.random()-.5)*4, vy: 2.5+Math.random()*3,
               col: cols[i % cols.length], a: Math.random()*Math.PI*2,
               s: (Math.random()-.5)*.15 });
    }
    var gone = false;
    (function draw() {
      if (gone) return;
      ctx.clearRect(0, 0, c.width, c.height);
      var all = true;
      P.forEach(function (p) {
        p.vy += .12; p.x += p.vx; p.y += p.vy; p.a += p.s;
        if (p.y < c.height + 10) all = false;
        ctx.save(); ctx.translate(p.x, p.y); ctx.rotate(p.a);
        ctx.fillStyle = p.col; ctx.fillRect(-p.w/2, -p.h/2, p.w, p.h);
        ctx.restore();
      });
      if (all) { c.remove(); gone = true; return; }
      requestAnimationFrame(draw);
    })();
    setTimeout(function () { gone = true; c.remove(); }, 5500);
  })();
  <?php endif; ?>

  // ════════════════════════════════════════════════════════════════
  // Polling de status PIX
  // Só roda quando o método é PIX e o pagamento ainda está pendente.
  // Para automaticamente quando aprovado ou quando o QR expira.
  // ════════════════════════════════════════════════════════════════
  <?php if ($isPix && !$aprovado): ?>
  (function () {
    var CODIGO       = <?= json_encode($pedido['codigo']) ?>;
    var BASE_URL     = <?= json_encode(BASE_URL) ?>;
    var INTERVALO_MS = 4000;   // consulta a cada 4s
    var MAX_TENTATIVAS = 150;  // para após ~10 minutos (150 × 4s)
    var tentativas   = 0;
    var timer        = null;

    function consultarStatus() {
      tentativas++;
      if (tentativas > MAX_TENTATIVAS) {
        clearInterval(timer);
        return;
      }

      $.ajax({
        url: BASE_URL + '/checkout/status/' + CODIGO,
        method: 'GET',
        dataType: 'json',
        timeout: 5000,
        success: function (resp) {
          if (resp.status === 'aprovado') {
            clearInterval(timer);
            onAprovado(resp.pago_em);
          }
          // status 'falhou' ou 'cancelado': para sem fazer nada
          if (resp.status === 'falhou' || resp.status === 'cancelado') {
            clearInterval(timer);
          }
        },
        error: function () {
          // timeout ou erro de rede — silencioso, tenta de novo no próximo ciclo
        }
      });
    }

    function onAprovado(pagoEm) {
      // 1. Esconde o card do QR Code com transição suave
      $('.success-pix-card').fadeOut(400, function () {
        // 2. Atualiza o hero de "Aguardando" pra "Aprovado"
        var $hero = $('.success-hero');
        $hero.removeClass('sh--pending').addClass('sh--approved');

        // Troca o ícone relógio pelo check animado
        $('.sh-icon').html(
          '<svg viewBox="0 0 52 52" fill="none">' +
          '<circle class="sh-circle animated" cx="26" cy="26" r="23" stroke="currentColor" stroke-width="2.2"/>' +
          '<polyline class="sh-check animated" points="14,27 22,35 39,17"' +
          ' stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"/>' +
          '</svg>'
        );

        // Atualiza título e subtítulo
        $('.sh-title').text('Pedido confirmado!');
        $('.sh-sub').html(
          'Pagamento aprovado · E-mail enviado para <strong><?= View::e($pedido['cliente_email'] ?? '') ?></strong>'
        );

        // Atualiza o primeiro passo da timeline
        var $primeiro = $('.tl-step').first();
        $primeiro.removeClass('tl-active').addClass('tl-done');
        $primeiro.find('.tl-dot').html(
          '<svg viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">' +
          '<polyline points="2,7 5.5,10.5 12,3"/></svg>'
        );
        $primeiro.find('strong').text('Pago');
        $primeiro.find('small').text('Aprovado');

        // Atualiza o segundo passo da timeline (Separação → active)
        $('.tl-step').eq(1).addClass('tl-active');

        // Atualiza o card de pagamento lateral
        var $sprInfo = $('.spr-info');
        if ($sprInfo.length && pagoEm) {
          var dt = new Date(pagoEm.replace(' ', 'T'));
          var fmt = dt.toLocaleDateString('pt-BR') + ' às ' +
                    dt.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
          $sprInfo.find('small').text('Aprovado em ' + fmt);
        }
        var $pagTitle = $('[class*="success-card-title"]').filter(function () {
          return $(this).text().indexOf('Pagamento') >= 0;
        });
        $pagTitle.text('Pagamento aprovado');

        // 3. Confete
        dispararConfete();

        // 4. Mostra o hero atualizado
        $hero.hide().fadeIn(600);
      });
    }

    function dispararConfete() {
      var c = document.createElement('canvas');
      c.style.cssText = 'position:fixed;inset:0;width:100%;height:100%;pointer-events:none;z-index:9998';
      document.body.appendChild(c);
      var ctx = c.getContext('2d'); c.width = innerWidth; c.height = innerHeight;
      var P = [], cols = ['#2563eb','#4ade80','#f59e0b','#f472b6','#a78bfa','#34d399'];
      for (var i = 0; i < 140; i++) {
        P.push({ x: Math.random()*c.width, y: Math.random()*-c.height,
                 w: 7+Math.random()*6, h: 4+Math.random()*4,
                 vx: (Math.random()-.5)*4, vy: 2.5+Math.random()*3,
                 col: cols[i % cols.length], a: Math.random()*Math.PI*2,
                 s: (Math.random()-.5)*.15 });
      }
      var gone = false;
      (function draw() {
        if (gone) return;
        ctx.clearRect(0, 0, c.width, c.height);
        var all = true;
        P.forEach(function (p) {
          p.vy += .12; p.x += p.vx; p.y += p.vy; p.a += p.s;
          if (p.y < c.height + 10) all = false;
          ctx.save(); ctx.translate(p.x, p.y); ctx.rotate(p.a);
          ctx.fillStyle = p.col; ctx.fillRect(-p.w/2, -p.h/2, p.w, p.h);
          ctx.restore();
        });
        if (all) { c.remove(); gone = true; return; }
        requestAnimationFrame(draw);
      })();
      setTimeout(function () { gone = true; c.remove(); }, 5500);
    }

    // Inicia polling
    timer = setInterval(consultarStatus, INTERVALO_MS);

    // Para o polling quando o QR expirar (já temos o countdown acima)
    if (expira) {
      var msAteExpirar = new Date(expira).getTime() - Date.now();
      if (msAteExpirar > 0) {
        setTimeout(function () { clearInterval(timer); }, msAteExpirar + 5000);
      }
    }

    // Para quando o usuário sai da aba (evita requests em background)
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) {
        clearInterval(timer);
      } else if (tentativas < MAX_TENTATIVAS) {
        // Retoma quando volta
        timer = setInterval(consultarStatus, INTERVALO_MS);
      }
    });
  })();
  <?php endif; ?>

});
</script>

