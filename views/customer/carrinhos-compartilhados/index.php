<?php
// views/customer/carrinhos-compartilhados/index.php
$agora = new DateTime();

// $teste = WhatsappService::sendTemplate('5551994214617', 'alerta_pergunta', 'alerta_pergunta', [
//     // MetaCloudService::headerTexto('15421'),
//     MetaCloudService::body('João'),
//     MetaCloudService::botaoUrl(0, 'SM-001'), // sufixo do botão URL
// ]);
// var_dump($teste);
?>
<div class="customer-page">
  <div class="customer-page-header">
    <div>
      <h1>Carrinhos compartilhados</h1>
      <p class="customer-page-sub">Links de carrinho que você compartilhou com outras pessoas.</p>
    </div>
  </div>

  <?php if (empty($lista)): ?>
  <div class="od-card" style="padding:60px 20px;text-align:center;">
    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#cbd5e1"
         stroke-width="1.3" stroke-linecap="round" style="margin-bottom:14px;">
      <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
      <path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/>
    </svg>
    <p style="font-size:15px;font-weight:700;color:#475569;margin:0 0 6px;">Nenhum carrinho compartilhado ainda</p>
    <p style="font-size:14px;color:#94a3b8;margin:0;">
      Quando você compartilhar um carrinho, ele aparecerá aqui com os dados de acesso.
    </p>
  </div>
  <?php else: ?>

  <div style="display:flex;flex-direction:column;gap:12px;">
    <?php foreach ($lista as $cc):
      $expirado   = new DateTime($cc['expira_em']) < $agora;
      $expiraFmt  = date('d/m/Y H:i', strtotime($cc['expira_em']));
      $criadoFmt  = date('d/m/Y', strtotime($cc['criado_em']));
      $link       = BASE_URL . '/carrinho/compartilhado/' . $cc['token'];
    ?>
    <div class="cc-card <?= $expirado ? 'cc-card--expirado' : '' ?>">

      <!-- Header do card -->
      <div class="cc-card-header">
        <div class="cc-card-info">
          <div class="cc-card-titulo">
            Compartilhamento de <?= $criadoFmt ?>
            <?php if ($expirado): ?>
              <span class="cc-badge cc-badge--expirado">Expirado</span>
            <?php else: ?>
              <span class="cc-badge cc-badge--ativo">Ativo</span>
            <?php endif; ?>
          </div>
          <div class="cc-card-expira">
            <?= $expirado ? 'Expirou em' : 'Expira em' ?> <?= $expiraFmt ?>
          </div>
        </div>

        <!-- Métricas rápidas -->
        <div class="cc-metricas">
          <div class="cc-metrica">
            <span class="cc-metrica-val"><?= (int)($cc['total_visualizacoes_unicas'] ?? $cc['visualizacoes']) ?></span>
            <span class="cc-metrica-label">Vizualizações</span>
          </div>
          <div class="cc-metrica">
            <span class="cc-metrica-val"><?= (int)$cc['total_carrinhos_criados'] ?></span>
            <span class="cc-metrica-label">Carrinhos</span>
          </div>
          <div class="cc-metrica cc-metrica--destaque">
            <span class="cc-metrica-val"><?= (int)$cc['total_pedidos'] ?></span>
            <span class="cc-metrica-label">Pedidos</span>
          </div>
          <?php if ((float)$cc['receita_gerada'] > 0): ?>
          <div class="cc-metrica cc-metrica--receita">
            <span class="cc-metrica-val"><?= PriceHelper::format((float)$cc['receita_gerada']) ?></span>
            <span class="cc-metrica-label">Receita</span>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Ações -->
      <div class="cc-card-actions">
        <?php if (!$expirado): ?>
        <div class="cc-link-box">
          <input type="text" value="<?= View::e($link) ?>"
                 class="cc-link-input" readonly id="link-<?= View::e($cc['token']) ?>">
          <button type="button" class="btn btn-outline btn-sm cc-btn-copiar"
                  data-link="<?= View::e($link) ?>"
                  data-input="link-<?= View::e($cc['token']) ?>">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2.5" stroke-linecap="round">
              <rect x="9" y="9" width="13" height="13" rx="2"/>
              <path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/>
            </svg>
            Copiar link
          </button>
        </div>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/minha-conta/carrinhos-compartilhados/<?= View::e($cc['token']) ?>"
           class="btn btn-outline btn-sm">
          Ver detalhes
        </a>
      </div>

    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<style>
/* ── Card ──────────────────────────────────────────────── */
.cc-card {
  background: #fff;
  border: 1.5px solid var(--c-border);
  border-radius: 16px;
  overflow: hidden;
  transition: box-shadow .15s;
}
.cc-card:hover { box-shadow: 0 4px 20px rgba(10,15,30,.07); }
.cc-card--expirado { opacity: .7; }
.cc-card--expirado:hover { box-shadow: none; }

.cc-card-header {
  display: flex;
  align-items: center;
  gap: 20px;
  padding: 18px 20px;
  border-bottom: 1px solid #f8fafc;
  flex-wrap: wrap;
}
.cc-card-info { flex: 1; min-width: 0; }
.cc-card-titulo {
  font-size: 14.5px;
  font-weight: 700;
  color: #0f172a;
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 4px;
}
.cc-card-expira { font-size: 12.5px; color: #94a3b8; }

/* ── Badges ────────────────────────────────────────────── */
.cc-badge {
  font-size: 11px;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 99px;
}
.cc-badge--ativo    { background: #f0fdf4; color: #16a34a; }
.cc-badge--expirado { background: #f8fafc; color: #94a3b8; }

/* ── Métricas ──────────────────────────────────────────── */
.cc-metricas {
  display: flex;
  gap: 24px;
  flex-shrink: 0;
}
.cc-metrica { text-align: center; }
.cc-metrica-val {
  display: block;
  font-size: 22px;
  font-weight: 900;
  color: #0f172a;
  line-height: 1;
  margin-bottom: 3px;
}
.cc-metrica-label {
  font-size: 11px;
  font-weight: 600;
  color: #94a3b8;
  text-transform: uppercase;
  letter-spacing: .4px;
}
.cc-metrica--destaque .cc-metrica-val { color: #2563eb; }
.cc-metrica--receita  .cc-metrica-val { color: #16a34a; }

/* ── Ações / link ──────────────────────────────────────── */
.cc-card-actions {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 20px;
  background: #fafbff;
  flex-wrap: wrap;
}
.cc-link-box {
  display: flex;
  align-items: center;
  gap: 6px;
  flex: 1;
  min-width: 0;
}
.cc-link-input {
  flex: 1;
  font-family: 'SF Mono', monospace;
  font-size: 12px;
  padding: 7px 10px;
  border: 1.5px solid var(--c-border);
  border-radius: 8px;
  color: #64748b;
  background: #fff;
  min-width: 0;
  cursor: text;
}
.cc-btn-copiar svg { transition: color .15s; }
.cc-btn-copiar.copiado { color: #16a34a; border-color: #86efac; background: #f0fdf4; }

@media (max-width: 640px) {
  .cc-metricas { gap: 14px; }
  .cc-metrica-val { font-size: 18px; }
  .cc-metrica--receita { display: none; }
}
</style>
