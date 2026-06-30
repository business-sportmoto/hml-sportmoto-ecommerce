<?php
// views/admin/pedidos/show.php
// Variáveis: $pedido, $itens, $historico, $nf,
//            $todosStatus, $statusMap, $statusDef, $podeEditarItens,
//            $blingMap, $blingLogs, $cupomUso (null se sem cupom),
//            $promocoesAplicadas ([] se sem promoção)

// ── Helpers de status ─────────────────────────────────
$statusPedido    = $pedido['status_pedido']    ?? 'aguardando_pagamento';
$statusPagamento = $pedido['status_pagamento'] ?? 'pendente';
$isCancelado     = $statusPedido === 'cancelado';
$aprovado        = $statusPagamento === 'aprovado';

$st  = $statusMap[$statusPedido] ?? ['label' => $statusPedido, 'cor' => 'info'];

// Mapa de pagamento (fixo — não tem configuração custom)
$pagMap = [
    'pendente'   => ['cor'=>'warning','label'=>'Pendente'],
    'aguardando' => ['cor'=>'warning','label'=>'Aguardando'],
    'aprovado'   => ['cor'=>'success','label'=>'Aprovado'],
    'recusado'   => ['cor'=>'danger', 'label'=>'Recusado'],
    'estornado'  => ['cor'=>'danger', 'label'=>'Estornado'],
    'reembolsado'=> ['cor'=>'info',   'label'=>'Reembolsado'],
];
$pag = $pagMap[$statusPagamento] ?? ['cor'=>'info','label'=>$statusPagamento];

// ── Timeline dinâmica (excluindo cancelado que é estado especial) ──
$timelineSteps = array_values(array_filter(
    $todosStatus,
    fn($s) => $s['slug'] !== 'cancelado' && $s['ativo']
));
// Ordena por ordenacao (já vem ordenado do model, mas garante)
usort($timelineSteps, fn($a,$b) => (int)$a['ordenacao'] <=> (int)$b['ordenacao']);

// Nível atual na timeline para cálculo done/active/future
$ordemAtual = (int)($statusDef['ordenacao'] ?? 0);
?>

<div class="admin-page">

  <!-- ══ HEADER ════════════════════════════════════════ -->
  <div class="admin-page-header">
    <div>
      <a href="<?= ADMIN_URL ?>/pedidos" class="back-link">← Pedidos</a>
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:4px;">
        <h1 class="admin-page-title" style="margin:0;">
          Pedido <span style="font-family:'SF Mono',monospace;">#<?= View::e($pedido['codigo']) ?></span>
        </h1>
        <span class="badge badge-<?= $st['cor'] ?> badge-lg"><?= View::e($st['label']) ?></span>
        <span class="badge badge-<?= $pag['cor'] ?>"><?= View::e($pag['label']) ?></span>
      </div>
      <p class="admin-page-sub">
        <?= date('d/m/Y \à\s H:i', strtotime($pedido['criado_em'])) ?>
        · <?= View::e($pedido['cliente_nome']) ?>
      </p>
    </div>
    <div style="display:flex;gap:8px;">
      <a href="<?= BASE_URL ?>/minha-conta/pedido/<?= $pedido['id'] ?>" target="_blank"
         class="btn btn-outline btn-sm">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
        Ver como cliente
      </a>
    </div>
  </div>

  <!-- ══ TIMELINE DINÂMICA ══════════════════════════════ -->
  <div class="od-timeline-card <?= $isCancelado ? 'od-timeline-card--cancelled' : '' ?>"
       style="margin-bottom:20px;">
    <div class="odtl-header">
      <h3>Linha do tempo</h3>
      <?php if ($isCancelado): ?>
        <span style="font-size:12px;color:#ef4444;font-weight:700;">Pedido cancelado</span>
      <?php else: ?>
        <span style="font-size:12px;color:var(--c-text-muted);"><?= count($timelineSteps) ?> etapas</span>
      <?php endif; ?>
    </div>
    <div class="odtl-track">
      <?php foreach ($timelineSteps as $i => $step):
        $ordemStep = (int)$step['ordenacao'];
        $isDone    = !$isCancelado && $ordemAtual > $ordemStep;
        $isActive  = !$isCancelado && $ordemAtual === $ordemStep;
        $isLast    = $i === count($timelineSteps) - 1;
        $cls       = $isDone ? 'odtls-done' : ($isActive ? 'odtls-active' : 'odtls-future');
      ?>
      <div class="odtls-step <?= $cls ?>">
        <div class="odtls-dot-wrap">
          <div class="odtls-icon">
            <?php if (!empty($step['icone_key'])): ?>
              <?= IconLibrary::render(View::e($step['icone_key']), 'icon') ?>
            <?php else: ?>
              <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <circle cx="10" cy="10" r="7"/>
              </svg>
            <?php endif; ?>
          </div>
          <?php if (!$isLast): ?>
            <div class="odtls-line <?= $isDone ? 'odtls-line--done' : '' ?>"></div>
          <?php endif; ?>
        </div>
        <div class="odtls-label">
          <strong><?= View::e($step['label']) ?></strong>
          <?php if ($isActive): ?>
            <small>Atual</small>
          <?php elseif ($isDone): ?>
            <small>Concluído</small>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ══ GRID PRINCIPAL ════════════════════════════════ -->
  <div class="ap-grid">

    <!-- ── COLUNA PRINCIPAL ────────────────────────── -->
    <div class="ap-main">

      <!-- ITENS -->
      <div class="admin-card" id="card-itens">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--c-border);">
          <h3 style="font-size:14px;font-weight:800;color:var(--c-dark);margin:0;">
            Itens do pedido
            <span class="odh-count-badge" style="margin-left:6px;"><?= count($itens) ?></span>
          </h3>
          <?php if ($podeEditarItens): ?>
          <button type="button" class="btn btn-outline btn-sm" id="btn-add-item">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Adicionar item
          </button>
          <?php endif; ?>
        </div>

        <?php foreach ($itens as $item):
          $img = !empty($item['imagem'])
               ? BASE_URL.'/uploads/produtos/'.$item['imagem']
               : BASE_URL.'/assets/img/placeholder.png';

               $imgUrl = ImageHelper::getCartItemImage($item['produto_id']);
        ?>
        <div class="ap-item" data-item-id="<?= (int)$item['id'] ?>">
          <img src="<?= View::e($imgUrl) ?>" class="ap-item-img">
          <div class="ap-item-info">
            <div class="ap-item-name"><?= View::e($item['nome_produto'] ?? '') ?></div>
            <?php if (!empty($item['variacao_label'])): ?>
              <div class="ap-item-var"><?= View::e($item['variacao_label']) ?></div>
            <?php endif; ?>
            <?php if (!empty($item['sku'])): ?>
              <span class="ap-item-sku">SKU: <?= View::e($item['sku']) ?></span>
            <?php endif; ?>
          </div>
          <div class="ap-item-nums">
            <?php if ($podeEditarItens): ?>
              <div class="ap-item-edit-row">
                <label>Qtd</label>
                <input type="number" class="form-control form-control--xs ap-item-qtd"
                       value="<?= (int)$item['quantidade'] ?>" min="1" style="width:64px;">
                <label>R$</label>
                <input type="text" class="form-control form-control--xs ap-item-preco"
                       value="<?= number_format((float)$item['preco_unitario'],2,',','.') ?>"
                       style="width:90px;">
                <button type="button" class="btn btn-outline btn-xs btn-save-item" title="Salvar">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
                </button>
                <button type="button" class="btn btn-ghost btn-xs btn-del-item" title="Remover">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
                </button>
              </div>
            <?php else: ?>
              <div style="text-align:right;">
                <span style="font-size:13px;color:var(--c-text-muted);"><?= (int)$item['quantidade'] ?>×</span>
                <strong style="display:block;font-size:14.5px;">
                  <?= PriceHelper::format((float)($item['valor_final_item'] ?? $item['preco_unitario'] * $item['quantidade'])) ?>
                </strong>
              </div>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>

        <!-- Totais -->
        <div id="ap-totals" style="border-top:1px solid var(--c-border);padding:14px 20px;">
          <div style="display:flex;justify-content:space-between;font-size:13.5px;margin-bottom:6px;">
            <span>Subtotal</span><span><?= PriceHelper::format((float)$pedido['subtotal']) ?></span>
          </div>
          <?php
            // Desconto no produto (pode ser 0 pra cupom de frete grátis)
            $temDescontoProduto = (float)$pedido['desconto'] > 0;
            // Desconto no frete via cupom
            $temDescontoFrete   = !empty($cupomUso) && (float)$cupomUso['valor_frete_desc'] > 0;
          ?>

          <?php if ($temDescontoProduto): ?>
          <div style="display:flex;justify-content:space-between;font-size:13.5px;color:#16a34a;margin-bottom:6px;align-items:center;">
            <span style="display:flex;align-items:center;gap:6px;">
              Desconto
              <?php if (!empty($cupomUso)): ?>
                <span style="font-family:'SF Mono',monospace;font-size:11.5px;font-weight:700;
                             background:#dcfce7;color:#15803d;padding:1px 7px;border-radius:99px;
                             letter-spacing:.5px;">
                  <?= View::e($cupomUso['codigo']) ?>
                </span>
              <?php endif; ?>
            </span>
            <span>−<?= PriceHelper::format((float)$pedido['desconto']) ?></span>
          </div>
          <?php endif; ?>

          <div style="display:flex;justify-content:space-between;font-size:13.5px;margin-bottom:<?= $temDescontoFrete ? '4' : '10' ?>px;">
            <span style="display:flex;align-items:center;gap:6px;">
              Frete
            <?php if ($temDescontoFrete && !$temDescontoProduto): ?>
                <span style="font-family:'SF Mono',monospace;font-size:11.5px;font-weight:700;
                             background:#dcfce7;color:#15803d;padding:1px 7px;border-radius:99px;
                             letter-spacing:.5px;">
                  <?= View::e($cupomUso['codigo']) ?>
                </span>
              <?php endif; ?>
            </span>
            <span><?= (float)$pedido['frete'] > 0 ? PriceHelper::format((float)$pedido['frete']) : 'GRÁTIS' ?></span>
          </div>

          <?php if ($temDescontoFrete): ?>
          <div style="display:flex;justify-content:space-between;font-size:12px;color:#16a34a;margin-bottom:10px;padding-left:12px;">
            <span>↳ Frete grátis via cupom</span>
            <span>−<?= PriceHelper::format((float)$cupomUso['valor_frete_desc']) ?></span>
          </div>
          <?php endif; ?>

          <?php
            // Desconto total de promoções automáticas aplicadas
            $totalDescontoPromo = array_sum(array_column($promocoesAplicadas ?? [], 'valor_desconto'));
          ?>
          <?php if ($totalDescontoPromo > 0): ?>
          <div style="display:flex;justify-content:space-between;font-size:13.5px;color:#16a34a;margin-bottom:6px;align-items:center;">
            <span style="display:flex;align-items:center;gap:6px;">
              Promoção
              <span style="font-size:11px;font-weight:700;background:#eff6ff;color:#1d4ed8;
                           padding:1px 7px;border-radius:99px;">
                AUTO
              </span>
            </span>
            <span>−<?= PriceHelper::format($totalDescontoPromo) ?></span>
          </div>
          <?php endif; ?>
          <div style="display:flex;justify-content:space-between;font-size:18px;font-weight:900;padding-top:10px;border-top:2px solid var(--c-border);">
            <strong>Total</strong><strong><?= PriceHelper::format((float)$pedido['total']) ?></strong>
          </div>
        </div>
      </div>

      <!-- HISTÓRICO -->
      <div class="admin-card" style="margin-top:16px;">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--c-border);">
          <h3 style="font-size:14px;font-weight:800;margin:0;">Histórico de status</h3>
          <span class="odh-count-badge" id="historico-count"><?= count($historico) ?> eventos</span>
        </div>
        <div style="padding:8px 20px 12px;" id="historico-list">
          <?php foreach ($historico as $idx => $h):
            $hSt = $statusMap[$h['status_novo']] ?? ['cor'=>'info','label'=>$h['status_novo']];
          ?>
          <div class="ap-hist-item">
            <div class="ap-hist-dot ap-hist-dot--<?= $hSt['cor'] ?> <?= $idx===0?'ap-hist-dot--first':'' ?>"></div>
            <div class="ap-hist-body">
              <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:2px;">
                <span class="badge badge-<?= $hSt['cor'] ?>"><?= View::e($hSt['label']) ?></span>
                <?php if (!empty($h['admin_nome'])): ?>
                  <small class="txt-muted">por <?= View::e($h['admin_nome']) ?></small>
                <?php endif; ?>
              </div>
              <?php if (!empty($h['observacao'])): ?>
                <p style="font-size:13px;color:var(--c-text-muted);margin:2px 0 0;"><?= View::e($h['observacao']) ?></p>
              <?php endif; ?>
              <time style="font-size:11.5px;color:#94a3b8;"><?= date('d/m/Y H:i', strtotime($h['criado_em'])) ?></time>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- Obs. interna -->
        <?php if (!empty($pedido['observacao_interna'])): ?>
        <div style="padding:12px 20px;background:#fafbfc;border-top:1px solid var(--c-border);">
          <div style="font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--c-text-muted);margin-bottom:6px;">Observações internas</div>
          <pre style="font-family:inherit;font-size:12.5px;color:#475569;white-space:pre-wrap;margin:0;"><?= View::e($pedido['observacao_interna']) ?></pre>
        </div>
        <?php endif; ?>

        <div style="padding:14px 20px;border-top:1px solid var(--c-border);">
          <textarea id="nova-obs" class="form-control" rows="2"
                    placeholder="Adicionar observação interna…" style="resize:vertical;"></textarea>
          <button type="button" class="btn btn-outline btn-sm" id="btn-add-obs" style="margin-top:8px;">
            Adicionar nota
          </button>
        </div>
      </div>

      <?php
      /**
       * SNIPPET — adicionar em views/admin/pedidos/show.php
       *
       * Colar onde fizer sentido no layout do show, por exemplo
       * após o card de NF-e ou no aside de ações do pedido.
       *
       * Variáveis necessárias (já passadas pelo show() patchado):
       *   $blingMap  = ['bling_id' => '...', 'criado_em' => '...'] | null
       *   $blingLogs = array de bling_sync_log
       *   $pedido    = array do pedido (id, codigo)
       */
      ?>

      <div class="admin-card" id="card-bling-sync" style="margin-bottom: 16px;">

        <!-- ── Header ─────────────────────────────────────── -->
        <div style="display:flex;align-items:center;justify-content:space-between;
                    padding:14px 20px;border-bottom:1px solid var(--c-border);">
          <div style="display:flex;align-items:center;gap:10px;">
            <!-- Logo Bling estilizado -->
            <div style="width:28px;height:28px;background:#0057b8;border-radius:6px;
                        display:flex;align-items:center;justify-content:center;
                        font-size:11px;font-weight:900;color:#fff;letter-spacing:-.3px;">B</div>
            <strong style="font-size:13px;">Bling ERP</strong>
          </div>

          <?php if ($blingMap): ?>
            <span class="badge badge-success" style="display:flex;align-items:center;gap:5px;">
              <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                  stroke-width="3" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
              Sincronizado
            </span>
          <?php else: ?>
            <span class="badge badge-warning">Não enviado</span>
          <?php endif; ?>
        </div>

        <!-- ── Status e ID ────────────────────────────────── -->
        <div style="padding:14px 20px;">
          <?php if ($blingMap): ?>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;
                        font-size:13px;margin-bottom:14px;">
              <div>
                <div style="font-size:11px;color:var(--c-text-muted);margin-bottom:2px;">ID no Bling</div>
                <code style="font-size:13px;"><?= View::e($blingMap['bling_id']) ?></code>
              </div>
              <div>
                <div style="font-size:11px;color:var(--c-text-muted);margin-bottom:2px;">Enviado em</div>
                <span><?= date('d/m/Y H:i', strtotime($blingMap['criado_em'])) ?></span>
              </div>
            </div>
          <?php else: ?>
            <p style="font-size:13px;color:var(--c-text-muted);margin:0 0 14px;">
              Este pedido ainda não foi enviado ao Bling.
            </p>
          <?php endif; ?>

          <!-- Botão de forçar sincronização -->
          <button type="button"
                  class="btn btn-outline btn-sm"
                  id="btn-bling-forcar"
                  data-pedido-id="<?= (int)$pedido['id'] ?>">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2.5" stroke-linecap="round" style="margin-right:4px;">
              <polyline points="23 4 23 10 17 10"/>
              <path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/>
            </svg>
            <?= $blingMap ? 'Reenviar ao Bling' : 'Enviar ao Bling' ?>
          </button>

          <!-- Resultado inline da última tentativa -->
          <div id="bling-sync-result" style="display:none;margin-top:12px;"></div>
        </div>

        <!-- ── Log de tentativas ──────────────────────────── -->
        <?php if (!empty($blingLogs)): ?>
        <div style="border-top:1px solid var(--c-border);">
          <div style="padding:10px 20px 6px;font-size:11px;font-weight:700;
                      text-transform:uppercase;letter-spacing:.5px;color:var(--c-text-muted);">
            Histórico de sincronização
          </div>
          <div class="table-wrap" style="max-height:180px;overflow-y:auto;">
            <table class="admin-table" style="font-size:12px;">
              <thead>
                <tr>
                  <th>Tipo</th><th>Status</th><th>Erro</th><th>Data</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($blingLogs as $log): ?>
                <tr>
                  <td><span class="badge"><?= View::e($log['tipo']) ?></span></td>
                  <td>
                    <span class="badge badge-<?= $log['status'] === 'ok'
                      ? 'success' : ($log['status'] === 'erro' ? 'danger' : 'warning') ?>">
                      <?= $log['status'] ?>
                    </span>
                  </td>
                  <td style="color:var(--c-danger);max-width:240px;
                            overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                      title="<?= View::e($log['msg_erro'] ?? '') ?>">
                    <?= View::e($log['msg_erro'] ?? '—') ?>
                  </td>
                  <td style="color:var(--c-text-muted);white-space:nowrap;">
                    <?= date('d/m H:i', strtotime($log['criado_em'])) ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        <?php endif; ?>

      </div>

      

    </div><!-- /.ap-main -->

    <!-- ── COLUNA LATERAL ─────────────────────────────── -->
    <div class="ap-aside">

      <!-- ALTERAR STATUS -->
      <div class="admin-card ap-action-card">
        <h3 class="ap-card-title">Alterar status do pedido</h3>
        <div style="padding:14px 18px 16px;display:flex;flex-direction:column;gap:10px;">

          <select id="sel-novo-status" class="form-control">
            <?php foreach ($todosStatus as $s): ?>
            <option value="<?= View::e($s['slug']) ?>"
                    data-estorna="<?= (int)$s['estorna_estoque'] ?>"
                    data-cancela="<?= (int)$s['cancela_cupom'] ?>"
                    data-notifica="<?= (int)$s['notifica_cliente'] ?>"
                    data-reserva="<?= (int)($s['reserva_estoque'] ?? 0) ?>"
                    <?= $statusPedido === $s['slug'] ? 'selected' : '' ?>>
              <?= View::e($s['label']) ?>
            </option>
            <?php endforeach; ?>
          </select>

          <!-- Aviso de flags destrutivos -->
          <div id="status-flags-aviso" style="display:none;"
               class="form-alert form-alert--warning" style="font-size:12.5px;">
          </div>

          <textarea id="obs-status" class="form-control" rows="2"
                    placeholder="Observação (aparece no histórico e no e-mail do cliente)…"
                    style="resize:vertical;"></textarea>

          <label class="toggle-field" id="toggle-notificar-wrap">
            <input type="checkbox" id="chk-notificar-status" checked>
            <span class="toggle-slider"></span>
            <span>Notificar cliente por e-mail</span>
          </label>

          <button type="button" class="btn btn-primary" id="btn-salvar-status">Salvar status</button>
          <div id="status-msg" class="form-alert" style="display:none;"></div>
        </div>
      </div>

      <!-- RASTREIO -->
      <div class="admin-card ap-action-card" style="margin-top:14px;">
        <h3 class="ap-card-title">Código de rastreio</h3>
        <div style="padding:14px 18px 16px;display:flex;flex-direction:column;gap:10px;">
          <input type="text" id="input-rastreio" class="form-control"
                 value="<?= View::e($pedido['codigo_rastreio'] ?? '') ?>"
                 placeholder="Ex: AA123456789BR"
                 style="text-transform:uppercase;font-family:'SF Mono',monospace;letter-spacing:.5px;">
          <label class="toggle-field">
            <input type="checkbox" id="chk-notificar-rastreio" checked>
            <span class="toggle-slider"></span>
            <span>Notificar cliente</span>
          </label>
          <button type="button" class="btn btn-outline" id="btn-salvar-rastreio">Salvar rastreio</button>
        </div>
      </div>

      <!-- PAGAMENTO -->
      <div class="admin-card ap-action-card" style="margin-top:14px;">
        <h3 class="ap-card-title">Dados de pagamento</h3>
        <div style="padding:14px 18px 16px;display:flex;flex-direction:column;gap:10px;">
          <select id="sel-status-pag" class="form-control">
            <?php foreach ($pagMap as $k => $v): ?>
            <option value="<?= $k ?>" <?= $statusPagamento===$k?'selected':'' ?>><?= $v['label'] ?></option>
            <?php endforeach; ?>
          </select>
          <select id="sel-forma-pag" class="form-control">
            <option value="">Forma de pagamento…</option>
            <?php foreach (['pix'=>'Pix','boleto'=>'Boleto','cartao'=>'Cartão de crédito','manual'=>'Manual/Outro'] as $k=>$v): ?>
            <option value="<?= $k ?>" <?= ($pedido['forma_pagamento']??'')===$k?'selected':'' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select>
          <div id="campos-cartao" <?= ($pedido['forma_pagamento']??'')==='cartao'?'':'style="display:none"' ?>>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
              <div>
                <label class="form-label-xs">Bandeira</label>
                <input type="text" id="input-bandeira" class="form-control"
                       placeholder="visa, master…"
                       value="<?= View::e($pedido['cartao_bandeira'] ?? '') ?>">
              </div>
              <div>
                <label class="form-label-xs">Últimos 4</label>
                <input type="text" id="input-ultimos4" class="form-control"
                       maxlength="4" placeholder="0000"
                       value="<?= View::e($pedido['cartao_ultimos_4'] ?? '') ?>">
              </div>
            </div>
          </div>
          <div>
            <label class="form-label-xs">Data do pagamento</label>
            <input type="datetime-local" id="input-pago-em" class="form-control"
                   value="<?= $pedido['pago_em'] ? date('Y-m-d\TH:i', strtotime($pedido['pago_em'])) : '' ?>">
          </div>
          <button type="button" class="btn btn-outline" id="btn-salvar-pag">Salvar pagamento</button>
        </div>
      </div>

      <!-- CLIENTE -->
      <div class="admin-card ap-action-card" style="margin-top:14px;">
        <h3 class="ap-card-title">Cliente</h3>
        <div style="padding:14px 18px;">
          <strong style="display:block;font-size:14px;"><?= View::e($pedido['cliente_nome']) ?></strong>
          <small><?= View::e($pedido['cliente_email']) ?></small>
          <?php if (!empty($pedido['cliente_cpf'])): ?>
            <div><small class="txt-muted">CPF: <?= View::e($pedido['cliente_cpf']) ?></small></div>
          <?php endif; ?>
          <?php if (!empty($pedido['cliente_telefone'])): ?>
            <div><small class="txt-muted">Tel: <?= View::e($pedido['cliente_telefone']) ?></small></div>
          <?php endif; ?>
          <a href="<?= ADMIN_URL ?>/clientes/<?= (int)$pedido['cliente_id'] ?>"
             class="btn btn-ghost btn-sm" style="margin-top:8px;padding-left:0;">
            Ver perfil do cliente →
          </a>
        </div>
      </div>

      <!-- ENDEREÇO -->
      <?php if (!empty($pedido['ent_logradouro'])): ?>
      <div class="admin-card ap-action-card" style="margin-top:14px;">
        <h3 class="ap-card-title">Endereço de entrega</h3>
        <div style="padding:14px 18px;">
          <strong><?= View::e($pedido['ent_destinatario'] ?? $pedido['cliente_nome']) ?></strong>
          <p style="margin:4px 0 2px;font-size:13.5px;">
            <?= View::e($pedido['ent_logradouro'].', '.$pedido['ent_numero']) ?>
            <?php if (!empty($pedido['ent_complemento'])): ?> — <?= View::e($pedido['ent_complemento']) ?><?php endif; ?>
          </p>
          <p style="font-size:13.5px;margin:0 0 2px;"><?= View::e($pedido['ent_bairro'].' — '.$pedido['ent_cidade'].'/'.$pedido['ent_estado']) ?></p>
          <small class="txt-muted">CEP <?= View::e($pedido['ent_cep'] ?? '') ?></small>
        </div>
      </div>
      <?php endif; ?>

      <!-- CUPOM -->
      <?php if (!empty($cupomUso)): ?>
      <?php
        $tipoLabels = [
          'percentual'         => 'Percentual',
          'fixo'               => 'Valor fixo',
          'frete_gratis'       => 'Frete grátis',
          'progressivo'        => 'Progressivo',
          'primeira_compra'    => 'Primeira compra',
          'automatico'         => 'Automático',
          'campanha'           => 'Campanha',
          'recuperacao_carrinho'=> 'Recuperação de carrinho',
          'exclusivo'          => 'Exclusivo',
        ];
        $usoStatusMap = [
          'reservado'  => ['cor' => 'warning', 'label' => 'Reservado (pag. pendente)'],
          'confirmado' => ['cor' => 'success',  'label' => 'Confirmado'],
          'estornado'  => ['cor' => 'danger',   'label' => 'Estornado'],
        ];
        $usoSt = $usoStatusMap[$cupomUso['uso_status']] ?? ['cor' => 'info', 'label' => $cupomUso['uso_status']];
      ?>
      <div class="admin-card ap-action-card" style="margin-top:14px;">
        <h3 class="ap-card-title" style="display:flex;align-items:center;justify-content:space-between;">
          <span>Cupom aplicado</span>
          <span class="badge badge-<?= $usoSt['cor'] ?>" style="font-size:10.5px;">
            <?= $usoSt['label'] ?>
          </span>
        </h3>
        <div style="padding:14px 18px;">

          <!-- Código -->
          <div style="font-family:'SF Mono',monospace;font-size:17px;font-weight:900;
                      letter-spacing:1px;color:var(--c-dark);margin-bottom:10px;">
            <?= View::e($cupomUso['codigo']) ?>
          </div>

          <!-- Nome e tipo -->
          <div style="font-size:13px;color:var(--c-text-muted);margin-bottom:12px;">
            <?php if (!empty($cupomUso['nome'])): ?>
              <div style="font-weight:600;color:var(--c-dark);margin-bottom:2px;">
                <?= View::e($cupomUso['nome']) ?>
              </div>
            <?php endif; ?>
            <span style="background:#f1f5f9;padding:2px 8px;border-radius:99px;font-size:11.5px;">
              <?= View::e($tipoLabels[$cupomUso['tipo']] ?? $cupomUso['tipo']) ?>
            </span>
          </div>

          <!-- Breakdown do desconto -->
          <div style="border-top:1px solid var(--c-border);padding-top:10px;font-size:13px;">
            <?php if ((float)$cupomUso['valor_desconto'] > 0): ?>
            <div style="display:flex;justify-content:space-between;margin-bottom:5px;">
              <span style="color:var(--c-text-muted);">Desconto no produto</span>
              <span style="color:#16a34a;font-weight:700;">
                −<?= PriceHelper::format((float)$cupomUso['valor_desconto']) ?>
              </span>
            </div>
            <?php endif; ?>
            <?php if ((float)$cupomUso['valor_frete_desc'] > 0): ?>
            <div style="display:flex;justify-content:space-between;margin-bottom:5px;">
              <span style="color:var(--c-text-muted);">Desconto no frete</span>
              <span style="color:#16a34a;font-weight:700;">
                −<?= PriceHelper::format((float)$cupomUso['valor_frete_desc']) ?>
              </span>
            </div>
            <?php endif; ?>
            <div style="display:flex;justify-content:space-between;border-top:1px solid var(--c-border);
                        padding-top:8px;margin-top:4px;font-weight:700;">
              <span>Total de desconto</span>
              <span style="color:#16a34a;">
                −<?= PriceHelper::format((float)$cupomUso['valor_desconto'] + (float)$cupomUso['valor_frete_desc']) ?>
              </span>
            </div>
          </div>

          <!-- Valor original -->
          <div style="margin-top:8px;font-size:12px;color:var(--c-text-muted);">
            Pedido sem cupom seria: <?= PriceHelper::format((float)$cupomUso['valor_original']) ?>
          </div>

          <!-- Link pro cupom no admin -->
          <a href="<?= ADMIN_URL ?>/cupons/<?= (int)$cupomUso['cupom_id'] ?>"
             class="btn btn-ghost btn-sm" style="margin-top:10px;padding-left:0;">
            Ver cupom <?= View::e($cupomUso['codigo']) ?> →
          </a>
        </div>
      </div>
      <?php endif; ?>

      <!-- NOTA FISCAL -->
<?php
// Labels e cores para tipos de promoção
$tipoPromoLabels = [
    'desconto_progressivo' => ['label' => 'Progressivo',  'cor' => '#3b82f6'],
    'brinde'               => ['label' => 'Brinde',       'cor' => '#8b5cf6'],
    'compre_ganhe'         => ['label' => 'Compre+Leve',  'cor' => '#06b6d4'],
    'frete_gratis'         => ['label' => 'Frete grátis', 'cor' => '#10b981'],
    'bundle'               => ['label' => 'Bundle',       'cor' => '#f59e0b'],
    'cashback'             => ['label' => 'Cashback',     'cor' => '#ec4899'],
    'relampago'            => ['label' => 'Relâmpago',    'cor' => '#ef4444'],
    'fidelidade'           => ['label' => 'Fidelidade',   'cor' => '#f97316'],
];
?>
      <?php if (!empty($promocoesAplicadas)): ?>
      <div class="admin-card ap-action-card" style="margin-top:14px;">
        <h3 class="ap-card-title">
          Promoções aplicadas
          <span class="badge" style="background:#eff6ff;color:#1d4ed8;font-size:10.5px;margin-left:6px;">
            AUTO
          </span>
        </h3>
        <div style="padding:4px 0;">
          <?php foreach ($promocoesAplicadas as $promo):
            $tipoCfg = $tipoPromoLabels[$promo['promocao_tipo']] ?? ['label' => $promo['promocao_tipo'], 'cor' => '#64748b'];
            $det     = $promo['detalhes'] ?? [];
            $descProduto = (float)($det['desconto_produto'] ?? 0);
            $descFrete   = (float)($det['desconto_frete']   ?? 0);
            $faixaLabel  = isset($det['faixa_aplicada']['pct'])
                ? $det['faixa_aplicada']['pct'] . '%'
                : null;
          ?>
          <div style="padding:12px 18px;border-bottom:1px solid var(--c-border);">

            <!-- Nome + tipo -->
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;margin-bottom:8px;">
              <div>
                <a href="<?= ADMIN_URL ?>/promocoes/<?= (int)$promo['promocao_id'] ?>"
                   style="font-weight:700;font-size:13.5px;color:var(--c-dark);text-decoration:none;">
                  <?= View::e($promo['promocao_nome']) ?>
                </a>
                <?php if ($faixaLabel): ?>
                <div style="font-size:11.5px;color:var(--c-text-muted);margin-top:2px;">
                  Faixa aplicada: <?= $faixaLabel ?> de desconto
                </div>
                <?php endif; ?>
              </div>
              <span class="badge" style="background:<?= $tipoCfg['cor'] ?>22;color:<?= $tipoCfg['cor'] ?>;
                                         font-size:10.5px;white-space:nowrap;flex-shrink:0;">
                <?= $tipoCfg['label'] ?>
              </span>
            </div>

            <!-- Breakdown do desconto -->
            <div style="font-size:12.5px;display:flex;flex-direction:column;gap:4px;">
              <?php if ($descProduto > 0): ?>
              <div style="display:flex;justify-content:space-between;">
                <span style="color:var(--c-text-muted);">Desconto produto</span>
                <span style="color:#16a34a;font-weight:700;">−<?= PriceHelper::format($descProduto) ?></span>
              </div>
              <?php endif; ?>
              <?php if ($descFrete > 0): ?>
              <div style="display:flex;justify-content:space-between;">
                <span style="color:var(--c-text-muted);">Desconto frete</span>
                <span style="color:#16a34a;font-weight:700;">−<?= PriceHelper::format($descFrete) ?></span>
              </div>
              <?php endif; ?>
              <?php if (!empty($promo['produto_brinde_id'])): ?>
              <div style="display:flex;justify-content:space-between;">
                <span style="color:var(--c-text-muted);">Brinde</span>
                <span style="color:#8b5cf6;font-weight:700;">
                  <?= (int)$promo['qtd_brinde'] ?>x produto #<?= (int)$promo['produto_brinde_id'] ?>
                </span>
              </div>
              <?php endif; ?>
              <?php if ((float)$promo['valor_desconto'] > 0): ?>
              <div style="display:flex;justify-content:space-between;
                          border-top:1px solid var(--c-border);padding-top:6px;margin-top:2px;">
                <span style="font-weight:700;">Total desconto</span>
                <span style="color:#16a34a;font-weight:700;">
                  −<?= PriceHelper::format((float)$promo['valor_desconto']) ?>
                </span>
              </div>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
      <div class="admin-card ap-action-card" style="margin-top:14px;">
        <h3 class="ap-card-title">Nota Fiscal (NF-e)</h3>
        <div style="padding:14px 18px 16px;display:flex;flex-direction:column;gap:10px;">
          <div style="display:grid;grid-template-columns:2fr 1fr;gap:8px;">
            <div>
              <label class="form-label-xs">Número</label>
              <input type="text" class="form-control" id="nf-numero"
                     value="<?= View::e($nf['numero'] ?? '') ?>" placeholder="000000000">
            </div>
            <div>
              <label class="form-label-xs">Série</label>
              <input type="text" class="form-control" id="nf-serie"
                     value="<?= View::e($nf['serie'] ?? '1') ?>">
            </div>
          </div>
          <div>
            <label class="form-label-xs">Chave de acesso (44 dígitos)</label>
            <input type="text" class="form-control" id="nf-chave" maxlength="44"
                   value="<?= View::e($nf['chaveAcesso'] ?? '') ?>"
                   placeholder="44 dígitos numéricos"
                   style="font-family:'SF Mono',monospace;font-size:11px;letter-spacing:.3px;">
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
            <div>
              <label class="form-label-xs">Valor da nota</label>
              <input type="text" class="form-control" id="nf-valor"
                     value="<?= $nf ? number_format((float)$nf['valorNota'],2,',','.') : '' ?>">
            </div>
            <div>
              <label class="form-label-xs">Data de emissão</label>
              <input type="datetime-local" class="form-control" id="nf-emissao"
                     value="<?= !empty($nf['dataEmissao']) ? date('Y-m-d\TH:i', strtotime($nf['dataEmissao'])) : '' ?>">
            </div>
          </div>
          <div>
            <label class="form-label-xs">CNPJ do emitente</label>
            <input type="text" class="form-control" id="nf-cnpj"
                   value="<?= View::e($nf['cnpj'] ?? '') ?>" placeholder="00.000.000/0001-00">
          </div>
          <div>
            <label class="form-label-xs">Link PDF / DANFE</label>
            <input type="url" class="form-control" id="nf-pdf"
                   value="<?= View::e($nf['linkPDF'] ?? $nf['linkDanfe'] ?? '') ?>"
                   placeholder="https://…">
          </div>
          <button type="button" class="btn btn-outline" id="btn-salvar-nfe">
            <?= $nf ? 'Atualizar NF-e' : 'Salvar NF-e' ?>
          </button>
          <div id="nfe-msg" class="form-alert" style="display:none;"></div>
          <?php if (!empty($nf['linkPDF'])): ?>
            <a href="<?= View::e($nf['linkPDF']) ?>" target="_blank" class="btn btn-ghost btn-sm">↓ Baixar PDF</a>
          <?php endif; ?>
        </div>
      </div>

    </div><!-- /.ap-aside -->
  </div><!-- /.ap-grid -->
</div>

<!-- Modal: Adicionar item -->
<div class="od-modal-overlay" id="modal-add-item" hidden>
  <div class="od-modal-box od-modal-box--wide">
    <div class="od-modal-header">
      <h4>Adicionar item ao pedido</h4>
      <button type="button" class="od-modal-close" onclick="fecharModal('modal-add-item')">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.5" stroke-linecap="round">
          <line x1="18" y1="6" x2="6" y2="18"/>
          <line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>
    <div class="od-modal-body">
      <div style="margin-bottom:12px;">
        <input type="text" id="busca-produto" class="form-control"
               placeholder="Buscar produto por nome ou SKU…" autocomplete="off">
      </div>
      <div id="resultados-produto" style="min-height:60px;"></div>
      <div id="form-add-item" style="display:none;margin-top:16px;padding-top:16px;border-top:1px solid var(--c-border);">
        <input type="hidden" id="add-produto-id">
        <input type="hidden" id="add-sku-id">
        <div style="display:grid;grid-template-columns:1fr 1fr 80px;gap:10px;align-items:end;">
          <div>
            <label class="form-label-xs">Produto selecionado</label>
            <input type="text" id="add-produto-nome" class="form-control" readonly>
          </div>
          <div>
            <label class="form-label-xs">Preço unitário</label>
            <input type="text" id="add-preco" class="form-control" placeholder="0,00">
          </div>
          <div>
            <label class="form-label-xs">Qtd</label>
            <input type="number" id="add-qtd" class="form-control" value="1" min="1">
          </div>
        </div>
        <div id="add-skus-wrap" style="margin-top:10px;"></div>
        <button type="button" class="btn btn-primary" id="btn-confirmar-add" style="margin-top:12px;">
          Adicionar item
        </button>
      </div>
    </div>
  </div>
</div>

<script>
var PEDIDO_ID = <?= (int)$pedido['id'] ?>;
var statusAtualEhCancelado = '<?= $isCancelado ? 'true' : 'false' ?>' === 'true';

</script>

<script>
(function () {
  var $btn = $('#btn-bling-forcar');
  if (!$btn.length) return;

  $btn.on('click', function () {
    var pedidoId = $btn.data('pedido-id');
    var $res     = $('#bling-sync-result');

    CK.btnLoading($btn);
    $res.hide();

    $.post('<?= ADMIN_URL ?>/configuracoes/bling/forcar-sync', {
      _token    : CSRF_TOKEN,
      pedido_id : pedidoId,
    })
    .done(function (r) {
      CK.btnLoading($btn, false);

      if (r.ok) {
        // Atualiza badge e botão
        $('#card-bling-sync .badge-warning').replaceWith(
          '<span class="badge badge-success" style="display:flex;align-items:center;gap:5px;">' +
            '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>' +
            'Sincronizado</span>'
        );
        $btn.html(
          '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="margin-right:4px;"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/></svg> Reenviar ao Bling'
        );
        $res.html(
          '<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:10px 14px;">' +
            '<strong style="color:#15803d;font-size:13px;">✓ ' + r.msg + '</strong>' +
          '</div>'
        ).show();
        adminToast(r.msg, 'success');

      } else {
        // Exibe erro com detalhe do response do Bling
        var detalhe = '';
        if (r.detalhe) {
          try {
            var d = typeof r.detalhe === 'string' ? JSON.parse(r.detalhe) : r.detalhe;
            detalhe = '<pre style="margin:8px 0 0;font-size:11px;color:#991b1b;' +
                      'background:#fff;padding:8px;border-radius:6px;overflow-x:auto;' +
                      'white-space:pre-wrap;">' + JSON.stringify(d, null, 2) + '</pre>';
          } catch (e) {
            detalhe = '<code style="font-size:11px;color:#991b1b;">' + r.detalhe + '</code>';
          }
        }
        $res.html(
          '<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:12px 14px;">' +
            '<strong style="color:#dc2626;font-size:13px;display:block;margin-bottom:4px;">✗ ' + r.msg + '</strong>' +
            detalhe +
          '</div>'
        ).show();
        adminToast('Erro ao sincronizar com o Bling.', 'error');
      }
    })
    .fail(function () {
      CK.btnLoading($btn, false);
      $res.html(
        '<div style="color:#dc2626;font-size:13px;">Erro de conexão. Tente novamente.</div>'
      ).show();
    });
  });
})();
</script>