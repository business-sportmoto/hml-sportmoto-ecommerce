<?php
// views/customer/devolucoes/show.php
$statusLabels = [
    'solicitado'           => ['cor'=>'warning','label'=>'Solicitado','desc'=>'Aguardando análise da nossa equipe.'],
    'pre_aprovado'         => ['cor'=>'success','label'=>'Pré-aprovado','desc'=>'Sua solicitação foi pré-aprovada! Aguarde o código de postagem por e-mail.'],
    'aguardando_aprovacao' => ['cor'=>'warning','label'=>'Em análise','desc'=>'Nossa equipe está analisando sua solicitação.'],
    'aprovado'             => ['cor'=>'info',  'label'=>'Aprovado','desc'=>'Solicitação aprovada! Estamos processando o código de postagem, por favor, aguarde.'],
    'negado'               => ['cor'=>'danger','label'=>'Negado','desc'=>$sol['negado_motivo']??''],
    'aguardando_postagem'  => ['cor'=>'warning','label'=>'Aguardando postagem','desc'=>'Poste o produto com o código abaixo e informe o rastreio.'],
    'em_transito_reverso'  => ['cor'=>'primary','label'=>'Em trânsito','desc'=>'Produto a caminho. Aguardaremos o recebimento.'],
    'item_recebido'        => ['cor'=>'info',  'label'=>'Item recebido','desc'=>'Produto recebido! Realizaremos a inspeção em até 2 dias úteis.'],
    'inspecionado_aprovado'=> ['cor'=>'success','label'=>'Inspeção aprovada','desc'=>'Produto inspecionado e aprovado. Seu reembolso será processado em breve.'],
    'inspecionado_reprovado'=>['cor'=>'danger','label'=>'Inspeção reprovada','desc'=>'Infelizmente o produto não passou na inspeção. Entraremos em contato.'],
    'concluido'            => ['cor'=>'success','label'=>'Concluído','desc'=>'Devolução concluída! Verifique seu e-mail ou saldo na conta.'],
    'concluido_reprovado'  => ['cor'=>'danger','label'=>'Encerrado','desc'=>'Solicitação encerrada após reprovação na inspeção.'],
    'cancelado'            => ['cor'=>'danger','label'=>'Cancelado','desc'=>'Solicitação cancelada.'],
];
$st = $statusLabels[$sol['status']] ?? ['cor'=>'info','label'=>$sol['status'],'desc'=>''];
$podeInformarRastreio = $sol['status'] === 'aguardando_postagem';
$podeCancelar = in_array($sol['status'],['solicitado','aguardando_aprovacao','pre_aprovado','aprovado','aguardando_postagem']);
?>
<div class="customer-page">
  <div class="customer-page-header">
    <div>
      <div>
        <a href="<?= BASE_URL ?>/minha-conta/pedido/<?= (int)$sol['pedido_id'] ?>" class="back-link">← Ver pedido</a> - 
        <a href="<?= BASE_URL ?>/minha-conta/pedidos" class="back-link">Meus pedidos</a>
      </div>
      <h1>Devolução #<?= (int)$sol['id'] ?></h1>
      <p class="customer-page-sub"><?= date('d/m/Y', strtotime($sol['criado_em'])) ?></p>
    </div>
    <span class="order-status-pill order-status-pill--<?= $st['cor'] ?> order-status-pill--lg">
      <?= $st['label'] ?>
    </span>
  </div>

  <!-- Status descritivo -->
  <div class="od-card" style="padding:18px 20px;margin-bottom:16px;background:#f8fafc;border:1.5px solid var(--c-border);border-radius:14px;">
    <strong style="display:block;font-size:15px;color:var(--c-dark);margin-bottom:4px;">
      <?= $st['label'] ?>
    </strong>
    <?php if (!empty($st['desc'])): ?>
      <p style="font-size:14px;color:var(--c-text-muted);margin:0;"><?= View::e($st['desc']) ?></p>
    <?php endif; ?>
  </div>

  <!-- Código de postagem reversa -->
  <?php if (!empty($sol['codigo_postagem_reversa'])): ?>
  <div class="od-tracking-card" style="margin-bottom:16px;">
    <div class="odtr-header">
      <div class="odtr-header-info"><h3>Código de postagem reversa</h3></div>
      <div class="odtr-code-box">
        <span class="odtr-code-label">Código</span>
        <code class="odtr-code-value"><?= View::e($sol['codigo_postagem_reversa']) ?></code>
      </div>
    </div>
    <p style="font-size:13.5px;color:var(--c-text-muted);padding:0 24px 16px;">
      Leve o produto a qualquer agência dos Correios e apresente este código para postar gratuitamente.
      <?php if (!empty($sol['codigo_validade_dias'])): ?>
        Válido por <strong><?= (int)$sol['codigo_validade_dias'] ?> dias</strong>.
      <?php endif; ?>
    </p>
    <?php if ($podeInformarRastreio): ?>
    <div style="padding:0 20px 18px;">
      <form method="POST" action="<?= BASE_URL ?>/minha-conta/devolucao/<?= (int)$sol['id'] ?>/rastreio">
        <?= SecurityHelper::csrfField() ?>
        <label class="dev-section-title" style="font-size:12.5px;margin-bottom:8px;">
          Informe o código de rastreio após postar
        </label>
        <div style="display:flex;gap:8px;">
          <input type="text" name="codigo_rastreio" class="form-control"
                 placeholder="Ex: AA123456789BR" required
                 style="text-transform:uppercase;font-family:'SF Mono',monospace;flex:1;">
          <button type="submit" class="btn btn-primary" style="flex-shrink:0;">Confirmar</button>
        </div>
      </form>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  
  

  <div class="od-grid">
    <div class="od-main">
      <!-- Itens -->
      <div class="od-card">
        <h3 class="od-card-title dev-section-title">Itens da solicitação</h3>
        <?php foreach ($itens as $item):
          // $img = !empty($item['imagem']) ? BASE_URL.'/uploads/produtos/'.$item['imagem'] : BASE_URL.'/assets/img/placeholder.png';
          $img = ImageHelper::getCartItemImage($item['produto_id']);
        ?>
        <div class="od-item od-item--no-price">
          <div class="od-item-img"><img src="<?= View::e($img) ?>" alt=""></div>
          <div class="od-item-info">
            <div class="od-item-name"><?= View::e($item['nome_produto']) ?></div>
            <div class="od-item-qty-row"><span class="od-item-qty">Qtd: <?= (int)$item['quantidade'] ?></span></div>
          </div>
          <div style="text-align:right;flex-shrink:0;">
            <strong><?= PriceHelper::format((float)$item['valor_final']) ?></strong>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Histórico -->
      <?php if (!empty($historico)): ?>
      <div class="od-card od-historico-card" style="margin-top:16px;">
        <div class="od-card-title-row">
          <h3 class="od-card-title" style="border:none;margin:0;padding-bottom:0;">Histórico</h3>
          <span class="odh-count-badge"><?= count($historico) ?> eventos</span>
        </div>
        <div class="od-hist-list">
          <?php foreach ($historico as $idx => $h):
            $hSt = $statusLabels[$h['status_novo']] ?? ['cor'=>'info','label'=>$h['status_novo']];
          ?>
          <div class="odh-event-wrap">
            <div class="odh-icon-col">
              <div class="odh-event-icon odh-icon--<?= $hSt['cor'] ?> <?= $idx===0?'odh-icon--first':'' ?>">
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="10" cy="10" r="7"/><polyline points="10,6 10,10 13,12"/></svg>
              </div>
              <?php if ($idx < count($historico)-1): ?>
                <div class="odh-dashed-line"></div>
              <?php endif; ?>
            </div>
            <div class="odh-event-card <?= $idx===0?'odh-event-card--latest':'' ?>">
              <div class="odh-event-meta">
                <span class="odh-event-date"><?= date('d/m',strtotime($h['criado_em'])) ?></span>
                <span class="odh-sep">·</span>
                <span class="odh-event-time"><?= date('H:i',strtotime($h['criado_em'])) ?></span>
              </div>
              <strong><?= View::e($hSt['label']) ?></strong>
              <?php if (!empty($h['observacao'])): ?>
                <p><?= View::e($h['observacao']) ?></p>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <div class="od-aside">
      <div class="od-card od-card--sm">
        <h3 class="od-card-title">Resumo</h3>
        <div style="padding:14px 18px;display:flex;flex-direction:column;gap:8px;font-size:13.5px;">
          <div style="display:flex;justify-content:space-between;">
            <span>Tipo</span><strong><?= ucfirst($sol['tipo']) ?></strong>
          </div>
          <div style="display:flex;justify-content:space-between;">
            <span>Motivo</span><strong><?= View::e($sol['motivo_label']) ?></strong>
          </div>
          <div style="display:flex;justify-content:space-between;">
            <span>Valor solicitado</span><strong><?= PriceHelper::format((float)$sol['valor_solicitado']) ?></strong>
          </div>
          <?php if (!empty($sol['valor_aprovado'])): ?>
          <div style="display:flex;justify-content:space-between;color:#16a34a;">
            <span>Valor aprovado</span><strong><?= PriceHelper::format((float)$sol['valor_aprovado']) ?></strong>
          </div>
          <?php endif; ?>
          <?php if (!empty($sol['tipo_reembolso'])): ?>
          <div style="display:flex;justify-content:space-between;">
            <span>Reembolso via</span><strong><?= ucfirst(str_replace('_',' ',$sol['tipo_reembolso'])) ?></strong>
          </div>
          <?php endif; ?>
        </div>
        <?php if ($podeCancelar): ?>
        <div style="padding:0 18px 14px;">
          <form method="POST" action="<?= BASE_URL ?>/minha-conta/devolucao/<?= (int)$sol['id'] ?>/cancelar"
                onsubmit="return confirm('Cancelar esta solicitação?');">
            <?= SecurityHelper::csrfField() ?>
            <button type="submit" class="btn btn-outline btn-full"
                    style="color:#dc2626;border-color:#fca5a5;">
              Cancelar solicitação
            </button>
          </form>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>