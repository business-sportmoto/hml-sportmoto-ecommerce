<?php
// views/customer/conta.php — visão geral da conta.
//
// Esta página era o MENU do celular: no desktop o cabeçalho ficava escondido
// e sobrava uma lista de links duplicando a barra lateral. O menu virou a
// navegação do layout (views/layouts/customer.php) e aqui ficou o que a home
// da conta devia mostrar desde o início — o estado da conta, não o índice.

$tiers = [
    'bronze'   => ['label' => 'Bronze',   'cor' => '#b45309', 'proximo' => 500],
    'silver'   => ['label' => 'Prata',    'cor' => '#64748b', 'proximo' => 1500],
    'gold'     => ['label' => 'Ouro',     'cor' => '#b45309', 'proximo' => 3000],
    'platinum' => ['label' => 'Platinum', 'cor' => '#1e3a5f', 'proximo' => null],
];

$tier     = $stats['tier'] ?? 'bronze';
$tierInfo = $tiers[$tier] ?? $tiers['bronze'];
$score    = (int)   ($stats['score'] ?? 0);
$saldo    = (float) ($perfil['saldo_disponivel'] ?? 0);
$gasto    = (float) ($stats['gasto_total'] ?? 0);
$badges   = $badges ?? [];

// Quanto falta para o próximo nível. Sem meta (platinum) a barra fica cheia:
// é o topo, não um progresso indefinido.
$meta      = $tierInfo['proximo'];
$progresso = $meta === null ? 100 : min(100, (int) round($score / max(1, $meta) * 100));

$primeiroNome = trim(explode(' ', trim((string) ($perfil['nome'] ?? '')))[0] ?? '');
$hora         = (int) date('H');
$saudacao     = $hora < 12 ? 'Bom dia' : ($hora < 18 ? 'Boa tarde' : 'Boa noite');

$statusMap = [
    'aguardando_pagamento' => ['cor' => 'warning', 'label' => 'Aguardando pagamento'],
    'aguardando'           => ['cor' => 'warning', 'label' => 'Aguardando'],
    'pagamento_aprovado'   => ['cor' => 'info',    'label' => 'Pagamento aprovado'],
    'em_separacao'         => ['cor' => 'info',    'label' => 'Em separação'],
    'enviado'              => ['cor' => 'primary', 'label' => 'Enviado'],
    'entregue'             => ['cor' => 'success', 'label' => 'Entregue'],
    'cancelado'            => ['cor' => 'danger',  'label' => 'Cancelado'],
    'devolvido'            => ['cor' => 'danger',  'label' => 'Devolvido'],
    'troca_devolucao'      => ['cor' => 'warning', 'label' => 'Troca/Devolução'],
];
?>

<div class="dash">

  <!-- ── Hero ────────────────────────────────────────────── -->
  <section class="dash-hero">
    <div class="dash-hero-top">
      <div>
        <p class="dash-hello"><?= $saudacao ?><?= $primeiroNome !== '' ? ',' : '' ?></p>
        <h1 class="dash-name"><?= View::e($primeiroNome !== '' ? $primeiroNome : 'tudo certo por aqui') ?></h1>
      </div>
      <span class="dash-tier" style="--tier:<?= $tierInfo['cor'] ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round">
          <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
        </svg>
        <?= $tierInfo['label'] ?>
      </span>
    </div>

    <div class="dash-score">
      <div class="dash-score-head">
        <span><strong><?= number_format($score) ?></strong> pontos</span>
        <?php if ($meta !== null): ?>
          <span class="dash-score-meta"><?= number_format(max(0, $meta - $score)) ?> para o próximo nível</span>
        <?php else: ?>
          <span class="dash-score-meta">Nível máximo</span>
        <?php endif; ?>
      </div>
      <div class="dash-score-bar"><i style="width:<?= $progresso ?>%"></i></div>
    </div>
  </section>

  <!-- ── Números ─────────────────────────────────────────── -->
  <section class="dash-tiles">

    <a href="<?= BASE_URL ?>/minha-conta/pedidos" class="dash-tile">
      <span class="dash-tile-ico dash-tile-ico--blue">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round">
          <path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4zM3 6h18"/>
          <path d="M16 10a4 4 0 01-8 0"/>
        </svg>
      </span>
      <span class="dash-tile-val"><?= number_format((int) ($badges['pedidos'] ?? 0)) ?></span>
      <span class="dash-tile-lbl">Pedidos em andamento</span>
    </a>

    <a href="<?= BASE_URL ?>/minha-conta/historico" class="dash-tile">
      <span class="dash-tile-ico dash-tile-ico--green">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round">
          <line x1="12" y1="1" x2="12" y2="23"/>
          <path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/>
        </svg>
      </span>
      <span class="dash-tile-val">R$ <?= number_format($saldo, 2, ',', '.') ?></span>
      <span class="dash-tile-lbl">Crédito disponível</span>
    </a>

    <a href="<?= BASE_URL ?>/minha-conta/garagem" class="dash-tile">
      <span class="dash-tile-ico dash-tile-ico--amber">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round">
          <path d="M5 17a3 3 0 106 0 3 3 0 00-6 0zm13.5 0a3.5 3.5 0 117-7 3.5 3.5 0 01-7 7zM13 10h-2l-3 8H5.5M15 6l3 5h1.5M9 6h4"/>
        </svg>
      </span>
      <span class="dash-tile-val"><?= number_format((int) ($badges['motos'] ?? 0)) ?></span>
      <span class="dash-tile-lbl">Motos na garagem</span>
    </a>

    <a href="<?= BASE_URL ?>/minha-conta/favoritos" class="dash-tile">
      <span class="dash-tile-ico dash-tile-ico--rose">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round">
          <path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/>
        </svg>
      </span>
      <span class="dash-tile-val"><?= number_format((int) ($badges['favoritos'] ?? 0)) ?></span>
      <span class="dash-tile-lbl">Favoritos</span>
    </a>

  </section>

  <!-- ── Últimos pedidos ─────────────────────────────────── -->
  <section class="dash-block">
    <div class="dash-block-head">
      <h2>Últimos pedidos</h2>
      <a href="<?= BASE_URL ?>/minha-conta/pedidos" class="dash-link">
        Ver todos
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
             stroke-linecap="round"><polyline points="9 18 15 12 9 6"/></svg>
      </a>
    </div>

    <?php if (empty($pedidos)): ?>
      <div class="dash-vazio">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"
             stroke-linecap="round" stroke-linejoin="round">
          <path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4zM3 6h18"/>
          <path d="M16 10a4 4 0 01-8 0"/>
        </svg>
        <p>Você ainda não fez nenhum pedido.</p>
        <a href="<?= BASE_URL ?>" class="btn btn-primary btn-sm">Ver produtos</a>
      </div>
    <?php else: ?>
      <div class="dash-pedidos">
        <?php foreach ($pedidos as $p):
          $st = $statusMap[$p['status_pedido']] ?? ['cor' => 'info', 'label' => $p['status_pedido']];
        ?>
        <a href="<?= BASE_URL ?>/minha-conta/pedidos/<?= (int) $p['id'] ?>" class="dash-pedido">
          <span class="dash-pedido-cod">
            #<?= View::e($p['codigo']) ?>
            <em><?= date('d/m/Y', strtotime($p['criado_em'])) ?></em>
          </span>
          <span class="dash-pedido-itens">
            <?= (int) $p['total_itens'] ?> <?= (int) $p['total_itens'] === 1 ? 'item' : 'itens' ?>
          </span>
          <span class="dash-tag dash-tag--<?= $st['cor'] ?>"><?= $st['label'] ?></span>
          <span class="dash-pedido-total">R$ <?= number_format((float) $p['total'], 2, ',', '.') ?></span>
          <svg class="dash-pedido-go" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2.5" stroke-linecap="round"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <!-- ── Atalhos ─────────────────────────────────────────── -->
  <section class="dash-block">
    <div class="dash-block-head"><h2>Atalhos</h2></div>
    <div class="dash-atalhos">

      <a href="<?= BASE_URL ?>/minha-conta/enderecos" class="dash-atalho">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round">
          <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/>
        </svg>
        <strong>Endereços</strong>
        <em><?= (int) ($badges['enderecos'] ?? 0) ?> cadastrado<?= (int) ($badges['enderecos'] ?? 0) === 1 ? '' : 's' ?></em>
      </a>

      <a href="<?= BASE_URL ?>/minha-conta/cartoes" class="dash-atalho">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round">
          <rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>
        </svg>
        <strong>Cartões</strong>
        <em><?= (int) ($badges['cartoes'] ?? 0) ?> salvo<?= (int) ($badges['cartoes'] ?? 0) === 1 ? '' : 's' ?></em>
      </a>

      <a href="<?= BASE_URL ?>/minha-conta/devolucoes" class="dash-atalho">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round">
          <polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 014-4h14"/>
          <polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 01-4 4H3"/>
        </svg>
        <strong>Devoluções</strong>
        <em><?= (int) ($badges['devolucoes'] ?? 0) ?> em aberto</em>
      </a>

      <a href="<?= BASE_URL ?>/minha-conta/sessoes" class="dash-atalho">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round">
          <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
        </svg>
        <strong>Segurança</strong>
        <em>Sessões e senha</em>
      </a>

    </div>
  </section>

  <?php if ($gasto > 0): ?>
  <p class="dash-rodape">
    Você já comprou <strong>R$ <?= number_format($gasto, 2, ',', '.') ?></strong> na SportMoto<?php
      if (!empty($stats['ultimo_pedido'])): ?> · último pedido em <?= date('d/m/Y', strtotime($stats['ultimo_pedido'])) ?><?php endif; ?>.
  </p>
  <?php endif; ?>

</div>
