<?php

MailHelper::sendSimples(
    'robert.q.junior@hotmail.com',
    'Robert Junior',
    'Produto disponível — ',
    'O produto <strong>teste</strong> que você salvou na lista de desejos voltou ao estoque!',
    [
        'botao_texto' => 'Comprar agora',
        'botao_url'   => BASE_URL . '/produto/',
        'preheader'   => 'Corre! Estoque limitado.',
    ]
);


// views/admin/clientes/show.php
$tierCores=['bronze'=>['bg'=>'#fef3c7','text'=>'#92400e','dot'=>'#d97706'],'silver'=>['bg'=>'#f1f5f9','text'=>'#475569','dot'=>'#94a3b8'],'gold'=>['bg'=>'#fef9c3','text'=>'#713f12','dot'=>'#ca8a04'],'platinum'=>['bg'=>'#eff6ff','text'=>'#1e3a8a','dot'=>'#2563eb']];
$tier = $scoreRow['tier'] ?? 'bronze';
$tc   = $tierCores[$tier];
$ultimoAcesso = count($sessoes) > 0 ? $sessoes[0]['criado_em'] : null;

$diasSemAcesso = $ultimoAcesso ? (int)((time()-strtotime($ultimoAcesso))/86400) : null;
$statusPagMap=['pendente'=>['cor'=>'warning','label'=>'Pendente'],'aprovado'=>['cor'=>'success','label'=>'Aprovado'],'recusado'=>['cor'=>'danger','label'=>'Recusado'],'estornado'=>['cor'=>'danger','label'=>'Estornado'],'reembolsado'=>['cor'=>'info','label'=>'Reembolsado']];
$statusPedMap=['aguardando_pagamento'=>['cor'=>'warning','label'=>'Aguardando pgto.'],'pagamento_aprovado'=>['cor'=>'info','label'=>'Pgto. aprovado'],'em_separacao'=>['cor'=>'info','label'=>'Em separação'],'enviado'=>['cor'=>'primary','label'=>'Enviado'],'entregue'=>['cor'=>'success','label'=>'Entregue'],'cancelado'=>['cor'=>'danger','label'=>'Cancelado'],'troca_devolucao'=>['cor'=>'warning','label'=>'Troca/Dev.']];
?>
<?php if ($aniversario['status'] === 'hoje'): ?>
<!-- Balões — canvas-confetti -->
<script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.2/dist/confetti.browser.min.js"></script>
<script>
(function(){
  function baloes(){
    confetti({particleCount:4,angle:60,spread:55,origin:{x:0},colors:['#ff6b6b','#ffd93d','#6bcb77','#4d96ff','#ff922b']});
    confetti({particleCount:4,angle:120,spread:55,origin:{x:1},colors:['#ff6b6b','#ffd93d','#6bcb77','#4d96ff','#ff922b']});
  }
  var end=Date.now()+5000;
  (function frame(){if(Date.now()<end){baloes();requestAnimationFrame(frame);}})();
})();
</script>
<?php endif; ?>

<div class="admin-page cfg-page">

<!-- ══ HEADER ══════════════════════════════════════════ -->
<div class="ac-header">
  <div class="ac-header-left">
    <?php if (!empty($cliente['avatar'])): ?>
      <img src="<?= BASE_URL ?>/uploads/avatars/<?= View::e($cliente['avatar']) ?>"
           class="ac-avatar" alt="">
    <?php else: ?>
      <div class="ac-avatar ac-avatar--initials">
        <?= mb_strtoupper(mb_substr($cliente['nome'],0,1)) ?>
      </div>
    <?php endif; ?>
    <div class="ac-header-info">
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <h1 class="admin-page-title" style="margin:0;">
          <?= View::e($cliente['nome']) ?>
          <?= $aniversario['status']==='hoje' ? ' 🎂' : ($aniversario['status']==='no_mes'?' 🎈':'') ?>
        </h1>
        <span style="background:<?= $tc['bg'] ?>;color:<?= $tc['text'] ?>;padding:4px 12px;border-radius:99px;font-size:12.5px;font-weight:800;display:inline-flex;align-items:center;gap:5px;">
          <span style="width:7px;height:7px;border-radius:50%;background:<?= $tc['dot'] ?>;"></span>
          <?= ucfirst($tier) ?>
        </span>
        <?php if (!$cliente['ativo']): ?>
          <span class="badge badge-danger">Conta bloqueada</span>
        <?php endif; ?>
      </div>
      <div style="font-size:13.5px;color:var(--c-text-muted);margin-top:4px;">
        <?= View::e($cliente['email']) ?>
        <?php if (!empty($cliente['cpf'])): ?>
          · CPF <?= View::e($cliente['cpf']) ?>
        <?php endif; ?>
        · Cliente desde <?= date('d/m/Y', strtotime($cliente['criado_em'])) ?>
      </div>
      <!-- Tags do cliente -->
      <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px;" id="ac-tags-display">
        <?php foreach ($tags as $tag): ?>
          <span style="background:<?= View::e($tag['cor']) ?>22;color:<?= View::e($tag['cor']) ?>;border:1px solid <?= View::e($tag['cor']) ?>55;font-size:12px;font-weight:700;padding:3px 10px;border-radius:99px;">
            <?= View::e($tag['nome']) ?>
          </span>
        <?php endforeach; ?>
        <button type="button" class="btn btn-ghost btn-xs" id="btn-editar-tags" title="Editar tags">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
          Editar tags
        </button>

        <?php
        /**
         * Botão "Ver logs" para o perfil do cliente.
         *
         * USO (no perfil, onde $cliente está disponível):
         *     <?php $clienteId = (int) $cliente['id'];
         *           include ADMIN_PATH . '/views/partials/btn-logs-cliente.php'; ?>
         *
         * PONTE CRÍTICA: logs.usuario_id guarda usuarios.id, NÃO clientes.id.
         * Resolve aqui, uma vez, em vez de espalhar a conversão pelas views.
         *
         * Só aparece para 'super' — o dashboard de logs é super-only; oferecer um
         * botão que leva a um 403 é UX ruim, e mostrar o histórico de um cliente a
         * qualquer papel é exposição de dado pessoal.
         */

        if (Session::get('admin_nivel') == 'super') {            
       ?>
        <a href="<?= ADMIN_URL ?>/logs?usuario_id=<?= $usuarioId ?>&status=todos&periodo=tudo"
          class="admin-btn admin-btn--ghost btn btn-ghost btn-xs"
          title="Ver atividade registrada deste cliente">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
              stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
            <polyline points="14 2 14 8 20 8"/>
            <line x1="8" y1="13" x2="16" y2="13"/>
            <line x1="8" y1="17" x2="13" y2="17"/>
          </svg>
          Ver logs
        </a>
        <?php } ?>
      </div>
    </div>
  </div>
  <div class="ac-header-actions">
    <button type="button" class="btn btn-outline btn-sm" id="btn-editar-dados">
      ✎ Editar dados
    </button>
    <button type="button" class="btn btn-outline btn-sm" id="btn-email-personalizado">
      ✉ Enviar e-mail
    </button>
    <button type="button"
            class="btn btn-sm <?= $cliente['ativo']?'btn-outline':'btn-primary' ?>"
            id="btn-toggle-ativo"
            data-ativo="<?= (int)$cliente['ativo'] ?>"
            style="<?= $cliente['ativo']?'color:#dc2626;border-color:#fca5a5;':'' ?>">
      <?= $cliente['ativo'] ? '⊘ Bloquear conta' : '✓ Ativar conta' ?>
    </button>
  </div>
</div>

<!-- ══ ALERTAS ═════════════════════════════════════════ -->
<?php if ($aniversario['status'] === 'hoje'): ?>
<div class="ac-alert ac-alert--birthday">
  🎂 <strong>Hoje é o aniversário de <?= View::e($cliente['nome']) ?>!</strong>
  <?= $aniversario['idade'] ?> anos · Envie uma mensagem especial!
  <button type="button" class="btn btn-primary btn-sm" id="btn-email-aniversario" style="margin-left:12px;">
    Enviar parabéns
  </button>
</div>
<?php elseif ($aniversario['status'] === 'no_mes'): ?>
<div class="ac-alert ac-alert--info">
  🎈 Aniversário de <?= View::e($cliente['nome']) ?> em <strong><?= (int)$aniversario['dias_ate'] ?> dia(s)</strong>
  (<?= $aniversario['data_fmt'] ?>)
</div>
<?php endif; ?>

<?php if (!empty($riscos)): ?>
<div class="ac-alert ac-alert--risco">
  <strong>⚠ Indicadores de atenção:</strong>
  <ul style="margin:6px 0 0;padding-left:18px;">
    <?php foreach ($riscos as $r): ?>
      <li style="color:<?= $r['tipo']==='danger'?'#dc2626':'#d97706' ?>;"><?= View::e($r['msg']) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<?php if ($diasSemAcesso !== null && $diasSemAcesso > 30): ?>
<div class="ac-alert ac-alert--warning">
  ⏱ Sem acesso há <strong><?= $diasSemAcesso ?> dias</strong>.
  <?= $diasSemAcesso>90?'Considere uma campanha de reativação.':'' ?>
</div>
<?php endif; ?>

<!-- ══ LAYOUT ══════════════════════════════════════════ -->
<div class="cfg-layout" style="margin-top:20px;">

  <!-- Nav lateral -->
  <nav class="cfg-nav">
    <?php
    $navItems=[
      ['id'=>'stats',      'label'=>'Dashboard',       'icon'=>'bar-chart-2'],
      ['id'=>'dados',      'label'=>'Dados pessoais',  'icon'=>'user'],
      ['id'=>'notas',      'label'=>'Notas internas',  'icon'=>'file-text'],
      ['id'=>'pedidos',    'label'=>'Pedidos',          'icon'=>'package'],
      ['id'=>'devolucoes', 'label'=>'Devoluções',       'icon'=>'refresh-cw'],
      ['id'=>'score',      'label'=>'Score & Crédito',  'icon'=>'star'],
      ['id'=>'carrinho',   'label'=>'Carrinho atual',   'icon'=>'shopping-cart'],
      ['id'=>'cupons',     'label'=>'Cupons usados',    'icon'=>'tag'],
      ['id'=>'avaliacoes', 'label'=>'Avaliações',       'icon'=>'message-square'],
      ['id'=>'wishlist',   'label'=>'Lista de desejos', 'icon'=>'heart'],
      ['id'=>'enderecos',  'label'=>'Endereços',        'icon'=>'map-pin'],
      ['id'=>'cartoes',    'label'=>'Cartões',          'icon'=>'credit-card'],
      ['id'=>'garagem',    'label'=>'Garagem',          'icon'=>'truck'],
      ['id'=>'sessoes',    'label'=>'Sessões',          'icon'=>'monitor'],
      ['id'=>'emails',     'label'=>'E-mails',          'icon'=>'send'],
      ['id'=>'timeline',   'label'=>'Timeline',         'icon'=>'clock'],
    ];
    // SVG icons inline map
    $navIcons=[
      'bar-chart-2'=>'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
      'user'=>'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
      'file-text'=>'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',
      'package'=>'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="16.5" y1="9.4" x2="7.5" y2="4.21"/><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/></svg>',
      'refresh-cw'=>'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/></svg>',
      'star'=>'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
      'shopping-cart'=>'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/></svg>',
      'tag'=>'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>',
      'message-square'=>'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>',
      'heart'=>'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/></svg>',
      'map-pin'=>'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>',
      'credit-card'=>'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>',
      'truck'=>'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>',
      'monitor'=>'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>',
      'send'=>'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>',
      'clock'=>'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
    ];
    foreach ($navItems as $ni):
    ?>
    <a href="#ac-<?= $ni['id'] ?>" class="cfg-nav-item" data-grupo="<?= $ni['id'] ?>">
      <span class="cfg-nav-icon"><?= $navIcons[$ni['icon']] ?? '' ?></span>
      <?= $ni['label'] ?>
    </a>
    <?php endforeach; ?>
    <div class="cfg-nav-divider"></div>
    <a href="<?= ADMIN_URL ?>/pedidos?q=<?= urlencode($cliente['email']) ?>"
       class="cfg-nav-item cfg-nav-item--link">
      <span class="cfg-nav-icon">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
      </span>
      Ver todos os pedidos
    </a>
  </nav>

  <!-- Conteúdo em scroll -->
  <div class="cfg-content">

    <?php $badges = ClienteBadges::para($cliente); ?>
    <section class="cfg-grupo" id="ac-stats">
      
      <div class="cfg-grupo-header"><?= IconLibrary::render('sync') ?><h2>Origem & Sincronização</h2></div>
      <div style="display:flex;flex-wrap:wrap;gap:20px;">

        <div>
          <div style="font-size:11px;color:var(--c-text-muted);text-transform:uppercase;
                      letter-spacing:.4px;margin-bottom:4px;">E-mail</div>
          <span style="font-weight:700;color:<?= $badges['verificado']['tipo']==='success'?'#16a34a':'#d97706' ?>">
            <?= $badges['verificado']['icone'] ?> <?= View::e($badges['verificado']['label']) ?>
          </span>
        </div>

        <div>
          <div style="font-size:11px;color:var(--c-text-muted);text-transform:uppercase;
                      letter-spacing:.4px;margin-bottom:4px;">Origem</div>
          <span style="font-weight:700;" title="<?= View::e($badges['origem']['titulo']) ?>">
            <?= $badges['origem']['icone'] ?> <?= View::e($badges['origem']['label']) ?>
          </span>
        </div>

        <div>
          <div style="font-size:11px;color:var(--c-text-muted);text-transform:uppercase;
                      letter-spacing:.4px;margin-bottom:4px;">Bling</div>
          <span style="font-weight:700;" title="<?= View::e($badges['bling']['titulo']) ?>">
            <?= $badges['bling']['icone'] ?> <?= View::e($badges['bling']['label']) ?>
          </span>
          <?php if (!empty($cliente['bling_id'])): ?>
          <div style="font-size:11px;color:var(--c-text-muted);margin-top:2px;">
            ID: <?= View::e($cliente['bling_id']) ?>
          </div>
          <?php endif; ?>
        </div>
        <div style="margin-top:14px; width:100%">
          <button type="button" class="btn btn-outline btn-sm" id="btn-sync-bling-cliente"
                  data-id="<?= (int)$cliente['cliente_id'] ?>">
            <?= !empty($cliente['bling_id']) ? 'Ressincronizar com Bling' : 'Sincronizar com Bling' ?>
          </button>
        </div>

        <?php if (!empty($cliente['bling_sync_erro'])): ?>
          <div style="margin-top:8px;padding:10px 12px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;">
            <div style="font-size:11px;font-weight:800;color:#dc2626;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px;">
              Falha na sincronização Bling
            </div>
            <div style="font-size:13px;color:#7f1d1d;">
              <?= View::e($cliente['bling_sync_erro']) ?>
            </div>
            <?php if (!empty($cliente['bling_sync_tentativas'])): ?>
            <div style="font-size:11px;color:#991b1b;margin-top:4px;">
              <?= (int)$cliente['bling_sync_tentativas'] ?> tentativa(s)
            </div>
            <?php endif; ?>
          </div>
          <?php endif; ?>
      </div>
      
    </section>

    <!-- 01 DASHBOARD ──────────────────────────────────── -->
    <section class="cfg-grupo" id="ac-stats">
      <div class="cfg-grupo-header"><?= $navIcons['bar-chart-2'] ?><h2>Dashboard</h2></div>
      <div class="stats-grid stats-grid--3" style="margin-bottom:0;">
        <?php
        $cards=[
          ['val'=>$stats['total_pedidos'],              'label'=>'Pedidos',             'cor'=>'blue'],
          ['val'=>PriceHelper::format($stats['ltv']),  'label'=>'LTV total',           'cor'=>'green'],
          ['val'=>PriceHelper::format($stats['ticket_medio']),'label'=>'Ticket médio', 'cor'=>'purple'],
          ['val'=>$stats['curtidas'],                   'label'=>'Curtidas',            'cor'=>'pink'],
          ['val'=>$stats['carrinhos_compart'],          'label'=>'Carrinhos compart.',  'cor'=>'orange'],
          ['val'=>PriceHelper::format($saldo),         'label'=>'Saldo crédito',       'cor'=>'green'],
        ];
        foreach ($cards as $card):
        ?>
        <div class="stat-card">
          <div class="stat-card-body">
            <span class="stat-card-value"><?= $card['val'] ?></span>
            <span class="stat-card-label"><?= $card['label'] ?></span>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <div style="margin-top:12px!important;display:flex;gap:16px;font-size:13px;color:var(--c-text-muted);">
        <span>Último login:
          <strong style="color:<?= ($diasSemAcesso&&$diasSemAcesso>30)?'#dc2626':'var(--c-dark)' ?>;">
            <?= $ultimoAcesso ? date('d/m/Y H:i', strtotime($ultimoAcesso)) : '—' ?> <?= ($diasSemAcesso&&$diasSemAcesso>30) ? " ({$diasSemAcesso}d atrás ⚠)" : '' ?>
          </strong>
        </span>
      </div>
    </section>
    

    <!-- 02 DADOS PESSOAIS ─────────────────────────────── -->
    <section class="cfg-grupo" id="ac-dados" style="margin-top:28px;">
      <div class="cfg-grupo-header"><?= $navIcons['user'] ?><h2>Dados pessoais</h2></div>
      <div class="cfg-card">
        <?php
        $rows=[
          ['label'=>'Nome',       'val'=>$cliente['nome']],
          ['label'=>'E-mail',     'val'=>$cliente['email']],
          ['label'=>'CPF',        'val'=>$cliente['cpf'] ?: '—'],
          ['label'=>'Telefone',   'val'=>$cliente['telefone'] ?: '—'],
          ['label'=>'Celular',    'val'=>$cliente['celular'] ?: '—'],
          ['label'=>'Nascimento', 'val'=>$cliente['nascimento'] && $cliente['nascimento']!='0000-00-00' ? date('d/m/Y',strtotime($cliente['nascimento'])).($aniversario['status']!='sem_data'?' ('. $aniversario['idade'].' anos)':'') : '—'],
          ['label'=>'Gênero',     'val'=>$cliente['genero'] ?: '—'],
          ['label'=>'Newsletter', 'val'=>$cliente['newsletter']?'<span class="badge badge-success">Inscrito</span>':'<span class="badge badge-warning">Não inscrito</span>'],
          ['label'=>'Instagram',  'val'=>!empty($cliente['insta_cliente'])?'<a href="https://instagram.com/'.View::e($cliente['insta_cliente']).'" target="_blank">@'.View::e($cliente['insta_cliente']).'</a>':'—'],
          ['label'=>'2FA',        'val'=>$twofa?'<span class="badge badge-success">Ativo</span>':'<span class="badge badge-warning">Inativo</span>'],
          ['label'=>'Documento',  'val'=>$docStatus?('<span class="badge badge-'.($docStatus['status']==='aprovado'?'success':($docStatus['status']==='pendente'?'warning':'danger')).'">'.ucfirst($docStatus['status']).'</span>'):'—'],
          ['label'=>'Conta',      'val'=>$cliente['ativo']?'<span class="badge badge-success">Ativa</span>':'<span class="badge badge-danger">Bloqueada</span>'],
        ];
        foreach ($rows as $i=>$r):
        ?>
        <div class="cfg-row <?= $i===count($rows)-1?'cfg-row--last':'' ?>">
          <div class="cfg-row-info">
            <span class="cfg-row-label"><?= $r['label'] ?></span>
          </div>
          <div class="cfg-row-valor"><?= $r['val'] ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </section>

    <!-- 03 NOTAS INTERNAS ─────────────────────────────── -->
    <section class="cfg-grupo" id="ac-notas" style="margin-top:28px;">
      <div class="cfg-grupo-header"><?= $navIcons['file-text'] ?><h2>Notas internas</h2></div>
      <div class="cfg-card">
        <div id="ac-notas-lista">
          <?php if (empty($notas)): ?>
          <div style="padding:20px;text-align:center;color:var(--c-text-muted);font-size:13.5px;">
            Nenhuma nota ainda.
          </div>
          <?php endif; ?>
          <?php foreach ($notas as $nota): ?>
          <div class="ac-nota" data-nota-id="<?= (int)$nota['id'] ?>">
            <div class="ac-nota-body">
              <p><?= nl2br(View::e($nota['texto'])) ?></p>
              <div class="ac-nota-meta">
                <strong><?= View::e($nota['admin_nome']) ?></strong>
                · <?= date('d/m/Y H:i', strtotime($nota['criado_em'])) ?>
              </div>
            </div>
            <button type="button" class="btn-icon btn-icon--danger ac-nota-del"
                    data-id="<?= (int)$nota['id'] ?>" title="Excluir nota">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
            </button>
          </div>
          <?php endforeach; ?>
        </div>
        <div style="padding:14px 18px;border-top:1px solid var(--c-border);">
          <textarea id="nova-nota-txt" class="form-control" rows="2"
                    placeholder="Adicionar nota interna…" style="resize:vertical;"></textarea>
          <button type="button" class="btn btn-outline btn-sm" id="btn-add-nota" style="margin-top:8px;">
            Adicionar nota
          </button>
        </div>
      </div>
    </section>

    <!-- 04 PEDIDOS ────────────────────────────────────── -->
    <section class="cfg-grupo" id="ac-pedidos" style="margin-top:28px;">
      <div style="display:flex;align-items:center;justify-content:space-between;" class="cfg-grupo-header">
        <div style="display:flex;align-items:center;gap:10px;"><?= $navIcons['package'] ?><h2>Pedidos <span class="odh-count-badge"><?= $totalPed ?></span></h2></div>
        <a href="<?= ADMIN_URL ?>/pedidos?q=<?= urlencode($cliente['email']) ?>" class="btn btn-ghost btn-sm">Ver todos →</a>
      </div>
      <div class="cfg-card">
        <?php if (empty($pedidos)): ?>
        <div style="padding:20px;text-align:center;color:var(--c-text-muted);">Sem pedidos.</div>
        <?php else: ?>
        <table class="admin-table" style="font-size:13px;">
          <thead><tr><th>Código</th><th>Status</th><th>Pgto.</th><th>Total</th><th>Data</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($pedidos as $p):
            $sp=$statusPedMap[$p['status_pedido']]??['cor'=>'info','label'=>$p['status_pedido']];
            $pp=$statusPagMap[$p['status_pagamento']]??['cor'=>'info','label'=>$p['status_pagamento']];
          ?>
          <tr>
            <td><a href="<?= ADMIN_URL ?>/pedidos/<?= (int)$p['id'] ?>" class="link-subtle"><code style="font-size:12px;">#<?= View::e($p['codigo']) ?></code></a></td>
            <td><span class="badge badge-<?= $sp['cor'] ?>"><?= $sp['label'] ?></span></td>
            <td><span class="badge badge-<?= $pp['cor'] ?>"><?= $pp['label'] ?></span></td>
            <td><strong><?= PriceHelper::format((float)$p['total']) ?></strong></td>
            <td><small><?= date('d/m/Y', strtotime($p['criado_em'])) ?></small></td>
            <td><a href="<?= ADMIN_URL ?>/pedidos/<?= (int)$p['id'] ?>" class="btn-icon"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="9 18 15 12 9 6"/></svg></a></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </section>

    <!-- 05 DEVOLUÇÕES ─────────────────────────────────── -->
    <section class="cfg-grupo" id="ac-devolucoes" style="margin-top:28px;">
      <div class="cfg-grupo-header"><?= $navIcons['refresh-cw'] ?><h2>Devoluções</h2></div>
      <div class="cfg-card">
        <?php if (empty($devolucoes)): ?>
        <div style="padding:20px;text-align:center;color:var(--c-text-muted);">Sem devoluções.</div>
        <?php else: ?>
        <table class="admin-table" style="font-size:13px;">
          <thead><tr><th>#</th><th>Tipo</th><th>Status</th><th>Motivo</th><th>Valor</th><th>Pedido</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($devolucoes as $d): ?>
          <tr>
            <td><?= (int)$d['id'] ?></td>
            <td><?= ucfirst($d['tipo']) ?></td>
            <td><span class="badge badge-info" style="font-size:11px;"><?= View::e($d['status']) ?></span></td>
            <td><small><?= View::e($d['motivo_label']) ?></small></td>
            <td><?= PriceHelper::format((float)$d['valor_solicitado']) ?></td>
            <td><a href="<?= ADMIN_URL ?>/pedidos/<?= (int)($d['pedido_id']??0) ?>" class="link-subtle"><code style="font-size:11px;">#<?= View::e($d['pedido_codigo']) ?></code></a></td>
            <td><a href="<?= ADMIN_URL ?>/devolucoes/<?= (int)$d['id'] ?>" class="btn-icon"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="9 18 15 12 9 6"/></svg></a></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </section>

    <!-- 06 SCORE & CRÉDITO ────────────────────────────── -->
    <section class="cfg-grupo" id="ac-score" style="margin-top:28px;">
      <div class="cfg-grupo-header"><?= $navIcons['star'] ?><h2>Score & Crédito</h2></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <!-- Score gauge -->
        <div class="cfg-card" style="padding:20px;">
          <div style="display:flex;align-items:center;gap:16px;margin-bottom:16px;">
            <div>
              <div style="font-size:44px;font-weight:900;color:var(--c-dark);line-height:1;"><?= (int)($scoreRow['score_total']??0) ?></div>
              <div style="font-size:11px;color:var(--c-text-muted);">/ 600</div>
            </div>
            <div style="flex:1;">
              <span style="background:<?= $tc['bg'] ?>;color:<?= $tc['text'] ?>;padding:4px 12px;border-radius:99px;font-size:13px;font-weight:800;">
                <?= ucfirst($tier) ?>
              </span>
              <div style="margin-top:10px;height:8px;background:#f1f5f9;border-radius:99px;overflow:hidden;">
                <div style="height:100%;width:<?= min(100,round(($scoreRow['score_total']??0)/600*100)) ?>%;background:linear-gradient(90deg,#16a34a,#22c55e);border-radius:99px;"></div>
              </div>
            </div>
          </div>
          <a href="<?= ADMIN_URL ?>/clientes/<?= (int)$cliente['cliente_id'] ?>/score-credito"
             class="btn btn-outline btn-sm btn-full">Gerenciar score e crédito →</a>
        </div>
        <!-- Crédito -->
        <div class="cfg-card" style="padding:20px;">
          <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--c-text-muted);margin-bottom:6px;">Saldo disponível</div>
          <div style="font-size:36px;font-weight:900;color:#16a34a;margin-bottom:12px;"><?= PriceHelper::format($saldo) ?></div>
          <div style="font-size:12.5px;color:var(--c-text-muted);">Últimas transações:</div>
          <?php foreach (array_slice($creditoHist,0,4) as $tx):
            $isC=str_starts_with($tx['tipo'],'credito');
          ?>
          <div style="display:flex;justify-content:space-between;font-size:12.5px;padding:4px 0;border-bottom:1px solid #f8fafc;">
            <span><?= View::e($tx['descricao']) ?></span>
            <strong style="color:<?= $isC?'#16a34a':'#dc2626' ?>;"><?= $isC?'+':'-' ?><?= PriceHelper::format((float)$tx['valor']) ?></strong>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

    <!-- 07 CARRINHO ATUAL ─────────────────────────────── -->
    <section class="cfg-grupo" id="ac-carrinho" style="margin-top:28px;">
      <div class="cfg-grupo-header"><?= $navIcons['shopping-cart'] ?><h2>Carrinho atual</h2></div>
      <div class="cfg-card">
        <?php if (empty($carrinho)): ?>
        <div style="padding:20px;text-align:center;color:var(--c-text-muted);">Carrinho vazio.</div>
        <?php else: ?>
        <?php foreach ($carrinho as $ci):
          // $img=!empty($ci['imagem'])?BASE_URL.'/uploads/produtos/'.$ci['imagem']:BASE_URL.'/assets/img/placeholder.png';
          $img = ImageHelper::getCartItemImage($ci['produto_id']);
        ?>
        <div class="ap-item">
          <img src="<?= View::e($img) ?>" class="ap-item-img">
          <div class="ap-item-info">
            <div class="ap-item-name"><?= View::e($ci['produto_nome']) ?></div>
            <div class="ap-item-var">Status do carrinho: <strong><?= View::e($ci['carrinho_status']) ?></strong></div>
            <div class="ap-item-var">Ultima atualização: <strong><?= date('d/m/Y H:i', strtotime($ci['ultima_atualizacao'])) ?></strong></div>
          </div>
          <div style="text-align:right;">
            <span style="font-size:13px;color:var(--c-text-muted);"><?= (int)$ci['quantidade'] ?>×</span>
            <strong style="display:block;"><?= PriceHelper::format((float)($ci['preco_promo']??$ci['preco'])) ?></strong>
          </div>
        </div>
        <?php endforeach; ?>
        <div style="padding:10px 18px;border-top:1px solid var(--c-border);font-size:12.5px;color:var(--c-text-muted);">
          Última atualização: <?= date('d/m/Y H:i', strtotime($carrinho[0]['carrinho_atualizado_em'])) ?>
        </div>
        <?php endif; ?>
      </div>
    </section>

    <!-- 08 CUPONS ─────────────────────────────────────── -->
    <section class="cfg-grupo" id="ac-cupons" style="margin-top:28px;">
      <div class="cfg-grupo-header"><?= $navIcons['tag'] ?><h2>Cupons utilizados</h2></div>
      <div class="cfg-card">
        <?php if (empty($cupons)): ?>
        <div style="padding:20px;text-align:center;color:var(--c-text-muted);">Nenhum cupom utilizado.</div>
        <?php else: ?>
        <table class="admin-table" style="font-size:13px;">
          <thead><tr><th>Cupom</th><th>Desconto</th><th>Pedido</th><th>Data</th></tr></thead>
          <tbody>
          <?php foreach ($cupons as $cp): ?>
          <tr>
            <td><code><?= View::e($cp['codigo']) ?></code></td>
            <td><strong style="color:#16a34a;">−<?= PriceHelper::format((float)$cp['desconto_valor']) ?></strong></td>
            <td><a href="<?= ADMIN_URL ?>/pedidos/<?= (int)($cp['pedido_id']??0) ?>" class="link-subtle"><code style="font-size:11px;">#<?= View::e($cp['pedido_codigo']) ?></code></a></td>
            <td><small><?= date('d/m/Y', strtotime($cp['criado_em'])) ?></small></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </section>

    <!-- 09 AVALIAÇÕES ─────────────────────────────────── -->
    <section class="cfg-grupo" id="ac-avaliacoes" style="margin-top:28px;">
      <div class="cfg-grupo-header"><?= $navIcons['message-square'] ?><h2>Avaliações</h2></div>
      <div class="cfg-card">
        <?php if (empty($avaliacoes)): ?>
        <div style="padding:20px;text-align:center;color:var(--c-text-muted);">Nenhuma avaliação.</div>
        <?php else: ?>
        <?php foreach ($avaliacoes as $av):
          // $img=!empty($av['imagem'])?BASE_URL.'/uploads/produtos/'.$av['imagem']:BASE_URL.'/assets/img/placeholder.png';
          $img=ImageHelper::getCartItemImage($av['produto_id']);
          $nota=(int)($av['nota']??0);
        ?>
        <div class="ap-item">
          <img src="<?= View::e($img) ?>" class="ap-item-img">
          <div class="ap-item-info">
            <div class="ap-item-name"><?= View::e($av['produto_nome']) ?></div>
            <div style="color:#f59e0b;font-size:14px;"><?= str_repeat('★',$nota).str_repeat('☆',5-$nota) ?></div>
            <?php if (!empty($av['comentario'])): ?>
              <small style="color:var(--c-text-muted);"><?= View::e(mb_substr($av['comentario'],0,100)) ?></small>
            <?php endif; ?>
          </div>
          <small class="txt-muted" style="flex-shrink:0;"><?= date('d/m/Y', strtotime($av['criado_em'])) ?></small>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </section>

    <!-- 10 WISHLIST ──────────────────────────────────── -->
    <!-- 10 WISHLIST ──────────────────────────────────── -->
    <section class="cfg-grupo" id="ac-wishlist" style="margin-top:28px;">
      <div class="cfg-grupo-header">
        <?= $navIcons['heart'] ?>
        <h2>Lista de desejos <span class="odh-count-badge"><?= count($wishlist) ?></span></h2>
      </div>
 
      <?php if (empty($wishlist)): ?>
      <div class="cfg-card" style="padding:40px 20px;text-align:center;color:var(--c-text-muted);">
        Nenhuma lista de desejos criada.
      </div>
      <?php else: ?>
      <div class="wl-grid">
        <?php foreach ($wishlist as $lista):
          $previews = $lista['preview_imgs'] ?? [];
        ?>
        <button type="button"
                class="wl-card"
                data-id="<?= (int)$lista['id'] ?>"
                data-nome="<?= View::e($lista['nome'] ?? 'Lista sem nome') ?>"
                data-cliente="<?= (int)$cliente['cliente_id'] ?>">
 
          <!-- Grid de fotos de fundo -->
          <div class="wl-photos">
            <?php
            $slots = 6;
            for ($s = 0; $s < $slots; $s++):
              $foto = $previews[$s] ?? null;
              
            ?>
            <div class="wl-photo-slot">
              <?php if ($foto): ?>
                <img src="<?= View::e(ImageHelper::getCartItemImage($foto)) ?>" alt="" loading="lazy">
              <?php else: ?>
                <div class="wl-photo-empty"></div>
              <?php endif; ?>
            </div>
            <?php endfor; ?>
          </div>
 
          <!-- Overlay com gradiente + info -->
          <div class="wl-overlay">
            <div class="wl-info">
              <div class="wl-nome"><?= View::e($lista['nome'] ?? 'Lista sem nome') ?></div>
              <div class="wl-count">
                <?= (int)$lista['total_itens'] ?>
                <?= (int)$lista['total_itens'] === 1 ? 'produto' : 'produtos' ?>
              </div>
            </div>
            <div class="wl-arrow">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                <polyline points="9 18 15 12 9 6"/>
              </svg>
            </div>
          </div>
 
        </button>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </section>

    <!-- 11 ENDEREÇOS ─────────────────────────────────── -->
    <section class="cfg-grupo" id="ac-enderecos" style="margin-top:28px;">
      <div class="cfg-grupo-header"><?= $navIcons['map-pin'] ?><h2>Endereços</h2></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <?php foreach ($enderecos as $end): ?>
        <div class="cfg-card" style="padding:16px;">
          <?php if ($end['principal']): ?><span class="badge badge-success" style="margin-bottom:8px;">Principal</span><?php endif; ?>
          <strong><?= View::e($end['nome_destinatario'] ?? '—') ?></strong>
          <p style="font-size:13px;color:var(--c-text);margin:4px 0;">
            <?= View::e($end['logradouro'].', '.$end['numero']) ?>
            <?= !empty($end['complemento'])?' — '.View::e($end['complemento']):'' ?>
          </p>
          <p style="font-size:13px;color:var(--c-text-muted);margin:0;"><?= View::e($end['bairro'].' — '.$end['cidade'].'/'.$end['estado']) ?></p>
          <small class="txt-muted">CEP <?= View::e($end['cep']) ?></small>
        </div>
        <?php endforeach; ?>
        <?php if (empty($enderecos)): ?>
        <div style="color:var(--c-text-muted);font-size:13.5px;">Sem endereços.</div>
        <?php endif; ?>
      </div>
    </section>

    <!-- 12 CARTÕES ───────────────────────────────────── -->
    <section class="cfg-grupo" id="ac-cartoes" style="margin-top:28px;">
      <div class="cfg-grupo-header"><?= $navIcons['credit-card'] ?><h2>Cartões salvos</h2></div>
      <div class="cfg-card">
        <?php if (empty($cartoes)): ?>
        <div style="padding:20px;text-align:center;color:var(--c-text-muted);">Nenhum cartão salvo.</div>
        <?php else: ?>
        <?php foreach ($cartoes as $cart): ?>
        <div style="display:flex;align-items:center;gap:14px;padding:14px 18px;border-bottom:1px solid #f8fafc;">
          <div style="width:44px;height:28px;background:#f1f5f9;border-radius:5px;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#64748b;">
            <?= strtoupper(View::e($cart['bandeira'] ?? '?')) ?>
          </div>
          <div>
            <strong style="font-size:13.5px;font-family:'SF Mono',monospace;">**** **** **** <?= View::e($cart['ultimos_4']) ?></strong>
            <div style="font-size:12px;color:var(--c-text-muted);">
              <?= View::e($cart['nome_titular']) ?> · Validade <?= View::e($cart['validade'] ?? '—') ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </section>

    <!-- 13 GARAGEM ───────────────────────────────────── -->
    <section class="cfg-grupo" id="ac-garagem" style="margin-top:28px;">
      <div class="cfg-grupo-header"><?= $navIcons['truck'] ?><h2>Garagem</h2></div>
      <?php if (empty($garagem)): ?>
      <div class="cfg-card" style="padding:20px;text-align:center;color:var(--c-text-muted);">Sem veículos cadastrados.</div>
      <?php else: ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;">
        <?php foreach ($garagem as $v):
          $foto=!empty($v['foto_capa'])?BASE_URL.'/uploads/garagem/'.$v['foto_capa']:BASE_URL.'/assets/img/placeholder.png';
        ?>
        <div style="background:#fff;border:1px solid var(--c-border);border-radius:12px;overflow:hidden;">
          <img src="<?= View::e($foto) ?>" style="width:100%;height:100px;object-fit:cover;">
          <div style="padding:10px 12px;">
            <div style="font-size:13px;font-weight:700;color:var(--c-dark);"><?= View::e($v['montadora_nome']) ?></div>
            <div style="font-size:12.5px;color:var(--c-text-muted);"><?= View::e($v['modelo'] ?? '') ?> <?= View::e($v['ano'] ?? '') ?></div>
            <?php if ($v['principal']): ?>
              <span class="badge badge-success" style="margin-top:4px;font-size:10px;">Principal</span>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </section>

    <!-- 14 SESSÕES ───────────────────────────────────── -->
    <section class="cfg-grupo" id="ac-sessoes" style="margin-top:28px;">
      <div class="cfg-grupo-header"><?= $navIcons['monitor'] ?><h2>Sessões ativas</h2></div>
      <div class="cfg-card">
        <?php if (empty($sessoes)): ?>
        <div style="padding:20px;text-align:center;color:var(--c-text-muted);">Nenhuma sessão ativa.</div>
        <?php else: ?>
        <?php foreach ($sessoes as $s): ?>
        <div style="display:flex;align-items:center;gap:14px;padding:12px 18px;border-bottom:1px solid #f8fafc;">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2" stroke-linecap="round"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
          <div style="flex:1;">
            <div style="font-size:13px;font-weight:600;"><?= View::e($s['dispositivo'] ?? $s['user_agent'] ?? 'Desconhecido') ?></div>
            <div style="font-size:12px;color:var(--c-text-muted);">IP: <?= View::e($s['ip'] ?? '—') ?> · <?= date('d/m/Y H:i', strtotime($s['criado_em'])) ?></div>
          </div>
          <button type="button" class="btn btn-outline btn-xs ac-btn-revogar"
                  data-sessao="<?= View::e($s['id'] ?? '') ?>" style="color:#dc2626;border-color:#fca5a5;">
            Revogar
          </button>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </section>

    <!-- 15 LOG DE E-MAILS ────────────────────────────── -->
    <section class="cfg-grupo" id="ac-emails" style="margin-top:28px;">
      <div class="cfg-grupo-header"><?= $navIcons['send'] ?><h2>Log de e-mails</h2></div>
      <div class="cfg-card">
        <?php if (empty($emailsLog)): ?>
        <div style="padding:20px;text-align:center;color:var(--c-text-muted);">Nenhum e-mail registrado.</div>
        <?php else: ?>
        <table class="admin-table" style="font-size:12.5px;">
          <thead><tr><th>Assunto</th><th>Template</th><th>Status</th><th>Data</th></tr></thead>
          <tbody>
          <?php foreach ($emailsLog as $em): ?>
          <tr>
            <td><?= View::e($em['assunto']) ?></td>
            <td><code style="font-size:11px;"><?= View::e($em['template'] ?? '—') ?></code></td>
            <td><span class="badge badge-<?= $em['status']==='enviado'?'success':'danger' ?>"><?= $em['status'] ?></span></td>
            <td><small><?= date('d/m/Y H:i', strtotime($em['enviado_em'])) ?></small></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </section>

    <!-- 16 TIMELINE ─────────────────────────────────── -->
    <section class="cfg-grupo" id="ac-timeline" style="margin-top:28px;">
      <div class="cfg-grupo-header"><?= $navIcons['clock'] ?><h2>Timeline de atividade</h2></div>
      <div class="cfg-card" style="padding:16px 20px;">
        <?php if (empty($timeline)): ?>
        <div style="text-align:center;color:var(--c-text-muted);">Sem atividade registrada.</div>
        <?php endif; ?>
        <?php
        $tipoIcon=['pedido'=>'📦','devolucao'=>'↩','nota'=>'📝','email'=>'✉'];
        $tipoCor= ['pedido'=>'#2563eb','devolucao'=>'#d97706','nota'=>'#7c3aed','email'=>'#16a34a'];
        foreach ($timeline as $tl):
          $tipo=$tl['tipo'];
        ?>
        <div class="ap-hist-item" style="padding-left:20px;">
          <div class="ap-hist-dot" style="background:<?= $tipoCor[$tipo]??'#94a3b8' ?>;left:-9px;top:12px;"></div>
          <div class="ap-hist-body">
            <div style="display:flex;align-items:center;gap:6px;">
              <span style="font-size:13px;"><?= $tipoIcon[$tipo]??'•' ?></span>
              <strong style="font-size:13px;"><?= View::e($tl['ref_label']) ?></strong>
              <?php if (!empty($tl['detalhe'])): ?>
                <small class="txt-muted">· <?= View::e($tl['detalhe']) ?></small>
              <?php endif; ?>
            </div>
            <time style="font-size:11.5px;color:#94a3b8;"><?= date('d/m/Y H:i', strtotime($tl['criado_em'])) ?></time>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </section>

  </div><!-- /cfg-content -->
</div><!-- /cfg-layout -->
</div><!-- /admin-page -->

<!-- ══ ESTILOS ════════════════════════════════════════ -->
<style>

</style>

<!-- ══ JS ═════════════════════════════════════════════ -->
<script>
var AC_ID  = <?= (int)$cliente['cliente_id'] ?>;
var AC_UID = <?= (int)$usuarioId ?>;
var BASE_A = '<?php echo ADMIN_URL; ?>/clientes/' + AC_ID;
var ADMIN_URL = '<?php echo ADMIN_URL; ?>';

// Scroll spy
(function(){
  var secs=document.querySelectorAll('.cfg-grupo[id]');
  var navs=document.querySelectorAll('.cfg-nav-item[data-grupo]');
  var obs=new IntersectionObserver(function(e){e.forEach(function(en){if(en.isIntersecting){var id=en.target.id.replace('ac-','');navs.forEach(function(n){n.classList.toggle('is-active',n.dataset.grupo===id);});}});},{threshold:0.2,rootMargin:'-60px 0px -60% 0px'});
  secs.forEach(function(s){obs.observe(s);});
  navs.forEach(function(el){el.addEventListener('click',function(e){e.preventDefault();var t=document.getElementById('ac-'+this.dataset.grupo);if(t)t.scrollIntoView({behavior:'smooth',block:'start'});});});
})();
 
// Editar dados
document.getElementById('btn-editar-dados').addEventListener('click',function(){
  window.adminDrawer({titulo:'Editar dados do cliente',tamanho:'md',conteudo:`
    <div style="display:flex;flex-direction:column;gap:12px;">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        <div><label class="form-label-xs">Nome</label><input type="text" id="ed-nome" class="form-control" value="<?= View::e(addslashes($cliente['nome'])) ?>"></div>
        <div><label class="form-label-xs">E-mail</label><input type="email" id="ed-email" class="form-control" value="<?= View::e(addslashes($cliente['email'])) ?>"></div>
        <div><label class="form-label-xs">CPF</label><input type="text" id="ed-cpf" class="form-control" value="<?= View::e(addslashes($cliente['cpf']??'')) ?>"></div>
        <div><label class="form-label-xs">Telefone</label><input type="text" id="ed-tel" class="form-control" value="<?= View::e(addslashes($cliente['telefone']??'')) ?>"></div>
        <div><label class="form-label-xs">Celular</label><input type="text" id="ed-cel" class="form-control" value="<?= View::e(addslashes($cliente['celular']??'')) ?>"></div>
        <div><label class="form-label-xs">Nascimento</label><input type="date" id="ed-nasc" class="form-control" value="<?= View::e($cliente['nascimento']??'') ?>"></div>
        <div><label class="form-label-xs">Gênero</label><select id="ed-genero" class="form-control"><option value="">—</option><option value="M" <?= ($cliente['genero']??'')==='M'?'selected':'' ?>>Masculino</option><option value="F" <?= ($cliente['genero']??'')==='F'?'selected':'' ?>>Feminino</option><option value="O" <?= ($cliente['genero']??'')==='O'?'selected':'' ?>>Outro</option></select></div>
        <div><label class="form-label-xs">Instagram</label><input type="text" id="ed-insta" class="form-control" value="<?= View::e(addslashes($cliente['insta_cliente']??'')) ?>" placeholder="@usuario"></div>
      </div>
      <label class="toggle-field"><input type="checkbox" id="ed-newsletter" <?= !empty($cliente['newsletter'])?'checked':'' ?>><span class="toggle-slider"></span><span>Newsletter</span></label>
      <div id="ed-msg" class="form-alert" style="display:none;"></div>
      <button type="button" class="btn btn-primary" id="btn-ed-salvar">Salvar alterações</button>
    </div>`
  });
  setTimeout(function(){
    document.getElementById('btn-ed-salvar').addEventListener('click',function(){
      CK.post(BASE_A+'/salvar-perfil',{nome:$('#ed-nome').val(),email:$('#ed-email').val(),cpf:$('#ed-cpf').val(),telefone:$('#ed-tel').val(),celular:$('#ed-cel').val(),nascimento:$('#ed-nasc').val(),genero:$('#ed-genero').val(),insta:$('#ed-insta').val(),newsletter:$('#ed-newsletter').is(':checked')?1:0})
      .done(function(r){if(r.ok){Toast.success('Perfil atualizado!');setTimeout(()=>location.reload(),600);}else $('#ed-msg').text(r.msg).show();});
    });
  },100);
});
 
// Toggle ativo
document.getElementById('btn-toggle-ativo').addEventListener('click',function(){
  var ativo=this.dataset.ativo==='1';
  if(!confirm(ativo?'Bloquear a conta deste cliente?':'Ativar a conta deste cliente?'))return;
  CK.post(BASE_A+'/toggle-ativo',{}).done(function(r){if(r.ok){Toast.success(r.msg);setTimeout(()=>location.reload(),600);}});
});
 
// Tags
document.getElementById('btn-editar-tags').addEventListener('click',function(){
  var tagIds=[<?= implode(',', array_column($tags, 'id')) ?>];
  var todas=<?= json_encode($todasTags) ?>;
  var html='<div style="display:flex;flex-direction:column;gap:8px;">';
  todas.forEach(function(t){
    var sel=tagIds.includes(t.id)?'checked':'';
    html+='<label class="toggle-field"><input type="checkbox" class="ac-tag-chk" value="'+t.id+'" '+sel+'><span class="toggle-slider" style="background:'+(sel?t.cor:'#d1d5db')+';"></span><span style="color:'+t.cor+';font-weight:700;">'+t.nome+'</span></label>';
  });
  html+='</div><button type="button" class="btn btn-primary" id="btn-tags-salvar" style="margin-top:14px;">Salvar tags</button>';
  var dr=window.adminDrawer({titulo:'Editar tags',tamanho:'sm',conteudo:html});
  setTimeout(function(){
    document.getElementById('btn-tags-salvar').addEventListener('click',function(){
      var ids=[].slice.call(document.querySelectorAll('.ac-tag-chk:checked')).map(function(el){return el.value;});
      CK.post(BASE_A+'/tags',{tags:ids}).done(function(r){if(r.ok){Toast.success('Tags atualizadas!');dr.close();setTimeout(()=>location.reload(),500);}});
    });
  },100);
});
 
// Notas
document.getElementById('btn-add-nota').addEventListener('click',function(){
  var txt=$('#nova-nota-txt').val().trim();
  if(!txt)return;
  CK.post(BASE_A+'/nota',{texto:txt}).done(function(r){
    if(r.ok){
      var html='<div class="ac-nota" data-nota-id="'+r.nota_id+'"><div class="ac-nota-body"><p>'+txt.replace(/\n/g,'<br>')+'</p><div class="ac-nota-meta"><strong>'+r.admin+'</strong> · '+r.criado_em+'</div></div><button type="button" class="btn-icon btn-icon--danger ac-nota-del" data-id="'+r.nota_id+'"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg></button></div>';
      $('#ac-notas-lista').prepend(html);
      $('#nova-nota-txt').val('');
      Toast.success('Nota adicionada!');
    }
  });
});
 
$(document).on('click','.ac-nota-del',function(){
  if(!confirm('Excluir esta nota?'))return;
  var id=$(this).data('id');
  CK.post(BASE_A+'/nota/'+id+'/del',{}).done(function(r){if(r.ok){$('[data-nota-id="'+id+'"]').fadeOut(200,function(){$(this).remove();});Toast.success('Nota excluída.');}});
});
 
// Sessões
$(document).on('click','.ac-btn-revogar',function(){
  if(!confirm('Revogar esta sessão?'))return;
  var sid=$(this).data('sessao');
  var row=$(this).closest('[style]');
  // Usa o endpoint existente do CustomerController ou SessionManager
  $.post(BASE_URL+'/minha-conta/revogar-sessao',{sessao_id:sid}).done(function(){row.fadeOut();Toast.success('Sessão revogada.');});
});
 
// E-mail personalizado
function abrirEmailPersonalizado(preAssunto){
  window.adminDrawer({titulo:'Enviar e-mail para '+<?= json_encode($cliente['nome']) ?>,tamanho:'md',conteudo:`
    <div style="display:flex;flex-direction:column;gap:12px;">
      <div><label class="form-label-xs">Assunto</label><input type="text" id="ep-assunto" class="form-control" value="${preAssunto||''}"></div>
      <div><label class="form-label-xs">Mensagem</label><textarea id="ep-msg" class="form-control" rows="8" style="resize:vertical;" placeholder="Escreva a mensagem personalizada…"></textarea></div>
      <div id="ep-err" class="form-alert" style="display:none;"></div>
      <button type="button" class="btn btn-primary" id="btn-ep-enviar">Enviar e-mail</button>
    </div>`
  });
  setTimeout(function(){
    document.getElementById('btn-ep-enviar').addEventListener('click',function(){
      CK.post(BASE_A+'/email-personalizado',{assunto:$('#ep-assunto').val(),mensagem:$('#ep-msg').val()})
      .done(function(r){if(r.ok){Toast.success(r.msg);document.dispatchEvent(new CustomEvent('drawerClose'));}else $('#ep-err').text(r.msg).show();});
    });
  },100);
}
document.getElementById('btn-email-personalizado').addEventListener('click',function(){abrirEmailPersonalizado('');});
document.getElementById('btn-email-aniversario')&&document.getElementById('btn-email-aniversario').addEventListener('click',function(){
  abrirEmailPersonalizado('Feliz Aniversário, <?= addslashes($cliente['nome']) ?>! 🎂');
});
 
// ── Wishlist drawer ───────────────────────────────────────
$(document).on('click', '.wl-card', function () {
  var wid      = $(this).data('id');
  var nome     = $(this).data('nome');
  var clienteId= $(this).data('cliente');
  var url      = ADMIN_URL + '/clientes/' + clienteId + '/wishlist/' + wid;
 
  var drawer = window.adminDrawer({
    titulo:   nome,
    tamanho:  'lg',
    conteudo: '<div class="wl-drawer-loading">' +
              '<div class="ac-skeleton" style="height:80px;border-radius:12px;margin-bottom:10px;"></div>'.repeat(4) +
              '</div>',
  });
 
  $.get(url).done(function (res) {
    if (!res.ok || !res.itens.length) {
      drawer.setConteudo('<div style="padding:40px;text-align:center;color:#94a3b8;">Lista vazia.</div>');
      return;
    }
    var html = '<div class="wl-drawer-grid">';
    res.itens.forEach(function (item) {
      var img     = item.imagem;
      var preco   = item.preco_promo || item.preco;
      var precoFmt= 'R$ ' + parseFloat(preco).toFixed(2).replace('.', ',');
      var promoTag= item.preco_promo
        ? '<span class="wl-item-promo">PROMO</span>'
        : '';
      html += '<a href="' + ADMIN_URL + '/produtos/' + item.produto_id + '/editar" ' +
              '   class="wl-drawer-item" target="_blank">' +
              '  <div class="wl-drawer-img"><img src="' + img + '" alt="" loading="lazy">' + promoTag + '</div>' +
              '  <div class="wl-drawer-info">' +
              '    <span class="wl-drawer-nome">' + item.nome + '</span>' +
              '    <span class="wl-drawer-preco">' + precoFmt + '</span>' +
              '  </div>' +
              '</a>';
    });
    html += '</div>';
    drawer.setConteudo(html);
  }).fail(function () {
    drawer.setConteudo('<div style="padding:40px;text-align:center;color:#ef4444;">Erro ao carregar.</div>');
  });
});

$('#btn-sync-bling-cliente').on('click', function() {
  var $btn = $(this), id = $btn.data('id');
  CK.btnLoading($btn);
  $.post(ADMIN_URL + '/clientes/' + id + '/sync-bling', { _token: CSRF_TOKEN })
  .done(function(r) {
    CK.btnLoading($btn, false);
    showToast(r.msg, r.ok ? 'success' : 'error');
    if (r.ok) setTimeout(function() { 
      location.reload();
     }, 1500);
  })
  .fail(function() { CK.btnLoading($btn, false); showToast('Erro de rede.', 'error'); });
});
</script>