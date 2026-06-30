<?php
// views/customer/carrinhos-compartilhados/show.php
$agora    = new DateTime();
$expirado = new DateTime($compartilhado['expira_em']) < $agora;
$link     = BASE_URL . '/carrinho/compartilhado/' . $compartilhado['token'];

$acaoLabel = [
    'visualizou'       => ['label' => 'Visualizou',         'cor' => '#64748b', 'icon' => '👁'],
    'criou_carrinho'   => ['label' => 'Adicionou ao carrinho','cor'=> '#2563eb', 'icon' => '🛒'],
    'finalizou_pedido' => ['label' => 'Finalizou pedido',   'cor' => '#16a34a', 'icon' => '✅'],
];
?>
<div class="customer-page">
  <div class="customer-page-header">
    <div>
      <a href="<?= BASE_URL ?>/minha-conta/carrinhos-compartilhados" class="back-link">
        ← Carrinhos compartilhados
      </a>
      <h1>
        Compartilhamento de <?= date('d/m/Y', strtotime($compartilhado['criado_em'])) ?>
        <?php if ($expirado): ?>
          <span class="cc-badge cc-badge--expirado" style="font-size:13px;">Expirado</span>
        <?php else: ?>
          <span class="cc-badge cc-badge--ativo" style="font-size:13px;">Ativo</span>
        <?php endif; ?>
      </h1>
      <p class="customer-page-sub">
        <?= $expirado ? 'Expirou' : 'Expira' ?> em
        <?= date('d/m/Y \à\s H:i', strtotime($compartilhado['expira_em'])) ?>
      </p>
    </div>
    <?php if (!$expirado): ?>
    <div>
      <button type="button" class="btn btn-primary" id="btn-copiar-link"
              data-link="<?= View::e($link) ?>">
        🔗 Copiar link
      </button>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── Mini dashboard ───────────────────────────── -->
  <div class="cc-dashboard-grid">
    <div class="cc-dash-card">
      <div class="cc-dash-icon">👁</div>
      <div class="cc-dash-val"><?= (int)($compartilhado['total_visualizacoes_unicas'] ?? $compartilhado['visualizacoes']) ?></div>
      <div class="cc-dash-label">Visualizações únicas</div>
    </div>
    <div class="cc-dash-card">
      <div class="cc-dash-icon">🛒</div>
      <div class="cc-dash-val"><?= (int)$compartilhado['total_carrinhos_criados'] ?></div>
      <div class="cc-dash-label">Carrinhos criados</div>
    </div>
    <div class="cc-dash-card cc-dash-card--destaque">
      <div class="cc-dash-icon">📦</div>
      <div class="cc-dash-val"><?= (int)$compartilhado['total_pedidos'] ?></div>
      <div class="cc-dash-label">Pedidos realizados</div>
    </div>
    <div class="cc-dash-card cc-dash-card--receita">
      <div class="cc-dash-icon">💰</div>
      <div class="cc-dash-val"><?= PriceHelper::format((float)$compartilhado['receita_gerada']) ?></div>
      <div class="cc-dash-label">Receita gerada</div>
    </div>
    <div class="cc-dash-card">
      <div class="cc-dash-icon">👤</div>
      <div class="cc-dash-val"><?= (int)$compartilhado['clientes_unicos'] ?></div>
      <div class="cc-dash-label">Clientes únicos</div>
    </div>
  </div>

  <div class="od-grid" style="margin-top:20px;">
    <div class="od-main">

      <!-- ── Log de atividade ────────────────────── -->
      <div class="od-card">
        <div class="od-card-title-row" style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--c-border);">
          <h3 style="margin:0;font-size:14px;font-weight:800;">Log de atividade</h3>
          <span class="odh-count-badge"><?= count($log) ?> eventos</span>
        </div>

        <?php if (empty($log)): ?>
        <div style="padding:40px;text-align:center;color:#94a3b8;font-size:14px;">
          Nenhuma atividade ainda.
        </div>
        <?php else: ?>
        <div class="od-hist-list">
          <?php foreach ($log as $idx => $evento):
            $al = $acaoLabel[$evento['acao']] ?? ['label'=>$evento['acao'],'cor'=>'#94a3b8','icon'=>'•'];
            $isUltimo = $idx === count($log) - 1;
          ?>
          <div class="odh-event-wrap">
            <div class="odh-icon-col">
              <div class="odh-event-icon odh-icon--<?= $evento['acao'] === 'finalizou_pedido' ? 'success' : ($evento['acao'] === 'criou_carrinho' ? 'primary' : 'gray') ?>"
                   style="font-size:15px; line-height:36px; text-align:center;">
                <?= $al['icon'] ?>
              </div>
              <?php if (!$isUltimo): ?>
                <div class="odh-dashed-line"></div>
              <?php endif; ?>
            </div>
            <div class="odh-event-card <?= $idx===0?'odh-event-card--latest':'' ?>">
              <div class="odh-event-meta">
                <span class="odh-event-date"><?= date('d/m', strtotime($evento['criado_em'])) ?></span>
                <span class="odh-sep">·</span>
                <span class="odh-event-time"><?= date('H:i', strtotime($evento['criado_em'])) ?></span>
                <?php if ($evento['ip']): ?>
                  <span class="odh-sep">·</span>
                  <span style="font-size:11px;color:#cbd5e1;font-family:'SF Mono',monospace;">
                    <?= View::e($evento['ip']) ?>
                  </span>
                <?php endif; ?>
              </div>

              <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <strong style="color:<?= $al['cor'] ?>;"><?= $al['label'] ?></strong>
                <?php if (!empty($evento['cliente_nome'])): ?>
                  <span style="font-size:13px;color:#475569;">
                    por <strong><?= View::e($evento['cliente_nome']) ?></strong>
                    <small style="color:#94a3b8;">(<?= View::e($evento['cliente_email'] ?? '') ?>)</small>
                  </span>
                <?php else: ?>
                  <span style="font-size:13px;color:#94a3b8;">Visitante anônimo</span>
                <?php endif; ?>

                <?php if (!empty($evento['pedido_codigo'])): ?>
                  <a href="<?= BASE_URL ?>/minha-conta/pedido/<?= (int)$evento['pedido_id'] ?>"
                     class="link-subtle" style="margin-left:4px;">
                    Pedido #<?= View::e($evento['pedido_codigo']) ?>
                    — <?= PriceHelper::format((float)($evento['pedido_total'] ?? 0)) ?>
                  </a>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

    </div>

    <div class="od-aside">

      <!-- ── Itens do snapshot ─────────────────────── -->
      <div class="od-card od-card--sm">
        <h3 class="od-card-title">Itens compartilhados</h3>
        <?php if (empty($itens)): ?>
        <div style="padding:14px 18px;font-size:13.5px;color:#94a3b8;">
          Snapshot não disponível.
        </div>
        <?php else: ?>
        <?php foreach ($itens as $item):
          $imgUrl = !empty($item['imagem'])
            ? BASE_URL . '/uploads/products/' . $item['imagem']
            : BASE_URL . '/assets/images/placeholder.jpg';
        ?>
        <div class="od-item od-item--no-price">
          <div class="od-item-img">
            <img src="<?= View::e($imgUrl) ?>" alt="">
          </div>
          <div class="od-item-info">
            <div class="od-item-name">
              <?= View::e(mb_substr($item['nome_produto'] ?? $item['nome'] ?? '—', 0, 50)) ?>
            </div>
            <div class="od-item-qty-row">
              <span class="od-item-qty">Qtd: <?= (int)$item['quantidade'] ?></span>
              <span style="font-size:13px;color:#16a34a;font-weight:700;margin-left:8px;">
                <?= PriceHelper::format((float)($item['preco_unitario'] ?? $item['subtotal'] / $item['quantidade'])) ?>
              </span>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
        <div style="padding:12px 18px;border-top:1px solid var(--c-border);display:flex;justify-content:space-between;font-size:13.5px;">
          <span>Total do snapshot</span>
          <strong><?= PriceHelper::format((float)$compartilhado['total']) ?></strong>
        </div>
        <?php endif; ?>
      </div>

      <!-- ── Infos do link ─────────────────────────── -->
      <?php if (!$expirado): ?>
      <div class="od-card od-card--sm" style="margin-top:12px;padding:16px 18px;">
        <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#94a3b8;margin-bottom:10px;">
          Link para compartilhar
        </div>
        <input type="text" value="<?= View::e($link) ?>"
               class="cc-link-input" readonly
               style="width:100%;margin-bottom:8px;font-size:11.5px;"
               id="link-detalhe">
        <button type="button" class="btn btn-primary btn-full" id="btn-copiar-detalhe"
                data-link="<?= View::e($link) ?>">
          🔗 Copiar link
        </button>
        <?php if (!empty($compartilhado['vendedor_codigo'])): ?>
        <div style="margin-top:10px;font-size:12.5px;color:#64748b;">
          Vendedor vinculado: <strong><?= View::e($compartilhado['vendedor_nome'] ?? $compartilhado['vendedor_codigo']) ?></strong>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

    </div>
  </div>
</div>

<style>
/* ── Dashboard grid ────────────────────────────────────── */
.cc-dashboard-grid {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 12px;
  margin-bottom: 4px;
}
.cc-dash-card {
  background: #fff;
  border: 1.5px solid var(--c-border);
  border-radius: 14px;
  padding: 18px 16px;
  text-align: center;
  transition: transform .2s, box-shadow .2s;
}
.cc-dash-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(10,15,30,.07);
}
.cc-dash-card--destaque { border-color: #bfdbfe; background: #eff6ff; }
.cc-dash-card--receita  { border-color: #bbf7d0; background: #f0fdf4; }

.cc-dash-icon  { font-size: 22px; margin-bottom: 8px; }
.cc-dash-val   { font-size: 28px; font-weight: 900; color: #0f172a; line-height: 1; margin-bottom: 4px; }
.cc-dash-label { font-size: 11.5px; font-weight: 600; color: #94a3b8; text-transform: uppercase; letter-spacing: .4px; }

.cc-dash-card--destaque .cc-dash-val { color: #2563eb; }
.cc-dash-card--receita  .cc-dash-val { color: #16a34a; }

/* Badge inline ─────────────────────────────────────────── */
.cc-badge { font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 99px; }
.cc-badge--ativo    { background: #f0fdf4; color: #16a34a; }
.cc-badge--expirado { background: #f8fafc; color: #94a3b8; }

/* Histórico icons override ─────────────────────────────── */
.odh-icon--success { background: #f0fdf4; color: #16a34a; }
.odh-icon--primary { background: #eff6ff; color: #2563eb; }
.odh-icon--gray    { background: #f8fafc; color: #94a3b8; }

@media (max-width: 720px) {
  .cc-dashboard-grid { grid-template-columns: repeat(2, 1fr); }
  .cc-dash-card:last-child { grid-column: 1/-1; }
}
</style>
