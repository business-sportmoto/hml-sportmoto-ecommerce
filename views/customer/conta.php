<?php // views/customer/conta.php
$tiersLabels = [
    'bronze'   => ['label' => 'Bronze',   'cor' => '#92400e', 'bg' => '#fef3c7'],
    'silver'   => ['label' => 'Prata',    'cor' => '#374151', 'bg' => '#f3f4f6'],
    'gold'     => ['label' => 'Ouro',     'cor' => '#92400e', 'bg' => '#fef9c3'],
    'platinum' => ['label' => 'Platinum', 'cor' => '#1e3a5f', 'bg' => '#dbeafe'],
];
$tier     = $stats['tier']  ?? 'bronze';
$tierInfo = $tiersLabels[$tier] ?? $tiersLabels['bronze'];
$score    = (int)($stats['score'] ?? 0);
$saldo    = (float)($perfil['saldo_disponivel'] ?? 0);

$avatarUrl = !empty($perfil['avatar'])
    ? View::upload('avatars/' . $perfil['avatar'])
    : null;
?>

<div class="conta-hub">

  <!-- ── Header do perfil ────────────────────────────── -->
  <div class="chub-header">
    <div class="chub-header-inner">
      <!-- Avatar -->
      <div class="chub-avatar">
        <?php if ($avatarUrl): ?>
          <img src="<?= View::e($avatarUrl) ?>" alt="">
        <?php else: ?>
          <div class="chub-avatar-initial">
            <?= mb_strtoupper(mb_substr($perfil['nome'] ?? 'U', 0, 1)) ?>
          </div>
        <?php endif; ?>
      </div>
      <!-- Dados do usuário -->
      <div class="chub-user">
        <strong class="chub-name"><?= View::e($perfil['nome'] ?? '') ?></strong>
        <span class="chub-email"><?= View::e($perfil['email'] ?? '') ?></span>
        <div class="chub-tier-badge"
             style="color:<?= $tierInfo['cor'] ?>;background:<?= $tierInfo['bg'] ?>">
          <?= $tierInfo['label'] ?> · <?= number_format($score) ?> pts
        </div>
      </div>
      <!-- Link perfil -->
      <a href="<?= BASE_URL ?>/minha-conta/perfil" class="chub-edit-btn" aria-label="Editar perfil">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
          <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
        </svg>
      </a>
    </div>

    <!-- Cards de destaques -->
    <div class="chub-highlights">
      <?php if ($saldo > 0): ?>
      <a href="<?= BASE_URL ?>/minha-conta/historico" class="chub-highlight-card">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
             stroke="#16a34a" stroke-width="2" stroke-linecap="round">
          <line x1="12" y1="1" x2="12" y2="23"/>
          <path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/>
        </svg>
        <div>
          <span class="chub-highlight-label">Crédito disponível</span>
          <strong class="chub-highlight-value">
            <?= PriceHelper::format($saldo) ?>
          </strong>
        </div>
        <svg class="chub-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <polyline points="9 18 15 12 9 6"/>
        </svg>
      </a>
      <?php endif; ?>

      <a href="<?= BASE_URL ?>/minha-conta/perfil#score" class="chub-highlight-card">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
             stroke="#f59e0b" stroke-width="2" stroke-linecap="round">
          <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
        </svg>
        <div>
          <span class="chub-highlight-label">Score</span>
          <strong class="chub-highlight-value"><?= number_format($score) ?> pts</strong>
        </div>
        <svg class="chub-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <polyline points="9 18 15 12 9 6"/>
        </svg>
      </a>
    </div>
  </div>

  <!-- ── Grupos de menu ──────────────────────────────── -->
  <div class="chub-groups">

    <!-- Compras -->
    <div class="chub-group">
      <h2 class="chub-group-title">Compras</h2>
      <div class="chub-menu-card">

        <a href="<?= BASE_URL ?>/minha-conta/pedidos" class="chub-menu-item">
          <span class="chub-menu-ico">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/>
              <line x1="3" y1="6" x2="21" y2="6"/>
              <path d="M16 10a4 4 0 01-8 0"/>
            </svg>
          </span>
          <span class="chub-menu-label">Meus pedidos</span>
          <?php if ($pedidosAtivos > 0): ?>
          <span class="chub-menu-badge chub-menu-badge--blue"><?= $pedidosAtivos ?></span>
          <?php endif; ?>
          <svg class="chub-chevron" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <polyline points="9 18 15 12 9 6"/>
          </svg>
        </a>

        <a href="<?= BASE_URL ?>/minha-conta/devolucoes" class="chub-menu-item">
          <span class="chub-menu-ico">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polyline points="17 1 21 5 17 9"/>
              <path d="M3 11V9a4 4 0 014-4h14"/>
              <polyline points="7 23 3 19 7 15"/>
              <path d="M21 13v2a4 4 0 01-4 4H3"/>
            </svg>
          </span>
          <span class="chub-menu-label">Devoluções e trocas</span>
          <?php if ($devolucaosAtivas > 0): ?>
          <span class="chub-menu-badge chub-menu-badge--orange"><?= $devolucaosAtivas ?></span>
          <?php endif; ?>
          <svg class="chub-chevron" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <polyline points="9 18 15 12 9 6"/>
          </svg>
        </a>

        <a href="<?= BASE_URL ?>/minha-conta/carrinhos-compartilhados" class="chub-menu-item">
          <span class="chub-menu-ico">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
              <path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 001.95-1.54L23 6H6"/>
              <path d="M17 14l4-4-4-4"/>
            </svg>
          </span>
          <span class="chub-menu-label">Carrinhos compartilhados</span>
          <svg class="chub-chevron" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <polyline points="9 18 15 12 9 6"/>
          </svg>
        </a>

        <a href="<?= BASE_URL ?>/minha-conta/historico" class="chub-menu-item chub-menu-item--last">
          <span class="chub-menu-ico">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="12" cy="12" r="10"/>
              <polyline points="12 6 12 12 16 14"/>
            </svg>
          </span>
          <span class="chub-menu-label">Histórico</span>
          <svg class="chub-chevron" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <polyline points="9 18 15 12 9 6"/>
          </svg>
        </a>

        <a href="<?= BASE_URL ?>/minha-conta/avaliacoes" class="chub-menu-item chub-menu-item--last">
          <span class="chub-menu-ico">
            <?= IconLibrary::render('star') ?>
          </span>
          <span class="chub-menu-label">Minhas avaliações</span>
           <svg class="chub-chevron" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <polyline points="9 18 15 12 9 6"/>
          </svg>
          </svg>
        </a>

      </div>
    </div>

    <!-- Minha garagem -->
    <div class="chub-group">
      <h2 class="chub-group-title">Minha garagem</h2>
      <div class="chub-menu-card">

        <a href="<?= BASE_URL ?>/minha-conta/garagem" class="chub-menu-item">
          <span class="chub-menu-ico">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round">
              <path d="M5 17a3 3 0 106 0 3 3 0 00-6 0zm13.5 0a3.5 3.5 0 117-7 3.5 3.5 0 01-7 7zM13 10h-2l-3 8H5.5M15 6l3 5h1.5M9 6h4"/>
            </svg>
          </span>
          <span class="chub-menu-label">Garagem</span>
          <?php if ($totalMotos > 0): ?>
          <span class="chub-menu-badge chub-menu-badge--gray"><?= $totalMotos ?> moto<?= $totalMotos !== 1 ? 's' : '' ?></span>
          <?php endif; ?>
          <svg class="chub-chevron" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <polyline points="9 18 15 12 9 6"/>
          </svg>
        </a>

        <a href="<?= BASE_URL ?>/minha-conta/favoritos" class="chub-menu-item chub-menu-item--last">
          <span class="chub-menu-ico">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/>
            </svg>
          </span>
          <span class="chub-menu-label">Favoritos</span>
          <svg class="chub-chevron" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <polyline points="9 18 15 12 9 6"/>
          </svg>
        </a>

      </div>
    </div>

    <!-- Conta -->
    <div class="chub-group">
      <h2 class="chub-group-title">Conta</h2>
      <div class="chub-menu-card">

        <a href="<?= BASE_URL ?>/minha-conta/enderecos" class="chub-menu-item">
          <span class="chub-menu-ico">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/>
              <circle cx="12" cy="10" r="3"/>
            </svg>
          </span>
          <span class="chub-menu-label">Endereços</span>
          <svg class="chub-chevron" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <polyline points="9 18 15 12 9 6"/>
          </svg>
        </a>

        <a href="<?= BASE_URL ?>/minha-conta/cartoes" class="chub-menu-item">
          <span class="chub-menu-ico">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <rect x="1" y="4" width="22" height="16" rx="2"/>
              <line x1="1" y1="10" x2="23" y2="10"/>
            </svg>
          </span>
          <span class="chub-menu-label">Cartões</span>
          <svg class="chub-chevron" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <polyline points="9 18 15 12 9 6"/>
          </svg>
        </a>

        <a href="<?= BASE_URL ?>/minha-conta/perfil" class="chub-menu-item">
          <span class="chub-menu-ico">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/>
              <circle cx="12" cy="7" r="4"/>
            </svg>
          </span>
          <span class="chub-menu-label">Meu perfil</span>
          <svg class="chub-chevron" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <polyline points="9 18 15 12 9 6"/>
          </svg>
        </a>

        <a href="<?= BASE_URL ?>/minha-conta/sessoes" class="chub-menu-item chub-menu-item--last">
          <span class="chub-menu-ico">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
            </svg>
          </span>
          <span class="chub-menu-label">Segurança</span>
          <svg class="chub-chevron" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <polyline points="9 18 15 12 9 6"/>
          </svg>
        </a>

      </div>
    </div>

    <!-- Sair -->
    <div class="chub-group">
      <div class="chub-menu-card">
        <a href="<?= BASE_URL ?>/sair" class="chub-menu-item chub-menu-item--last chub-menu-item--danger">
          <span class="chub-menu-ico">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/>
              <polyline points="16 17 21 12 16 7"/>
              <line x1="21" y1="12" x2="9" y2="12"/>
            </svg>
          </span>
          <span class="chub-menu-label">Sair da conta</span>
          <svg class="chub-chevron" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <polyline points="9 18 15 12 9 6"/>
          </svg>
        </a>
      </div>
    </div>

  </div><!-- /.chub-groups -->
</div><!-- /.conta-hub -->

<style>
/* ── Wrapper ─────────────────────────────────────────── */
.conta-hub {
  background: #f4f5f7;
  min-height: calc(100vh - var(--bottom-nav-total, 60px));
  padding-bottom: 24px;
}

/* ── Header ─────────────────────────────────────────── */
.chub-header {
  background: linear-gradient(160deg, #0f172a 0%, #1e3a6e 100%);
  padding: 20px 20px 0;
}
.chub-header-inner {
  display: flex;
  align-items: center;
  gap: 14px;
  padding-bottom: 18px;
}
.chub-avatar {
  width: 58px; height: 58px;
  border-radius: 50%;
  overflow: hidden;
  flex-shrink: 0;
  border: 2.5px solid rgba(255,255,255,.25);
  box-shadow: 0 0 0 3px rgba(255,255,255,.08);
}
.chub-avatar img { width: 100%; height: 100%; object-fit: cover; }
.chub-avatar-initial {
  width: 100%; height: 100%;
  background: #2563eb;
  display: flex; align-items: center; justify-content: center;
  font-size: 22px; font-weight: 800;
  color: #fff;
}
.chub-user { flex: 1; min-width: 0; }
.chub-name {
  display: block;
  font-size: 17px; font-weight: 800;
  color: #fff;
  letter-spacing: -.2px;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.chub-email {
  display: block;
  font-size: 12px; color: rgba(255,255,255,.55);
  margin-top: 2px;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.chub-tier-badge {
  display: inline-flex;
  align-items: center;
  margin-top: 7px;
  font-size: 11px; font-weight: 700;
  padding: 3px 10px;
  border-radius: 99px;
  letter-spacing: .3px;
}
.chub-edit-btn {
  width: 36px; height: 36px;
  border-radius: 50%;
  background: rgba(255,255,255,.1);
  border: 1px solid rgba(255,255,255,.15);
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
  color: rgba(255,255,255,.7);
  transition: background .15s;
}
.chub-edit-btn:hover { background: rgba(255,255,255,.2); }

/* ── Highlights ──────────────────────────────────────── */
.chub-highlights {
  display: flex;
  flex-direction: column;
  gap: 1px;
  background: rgba(255,255,255,.07);
  border-radius: 14px 14px 0 0;
  overflow: hidden;
}
.chub-highlight-card {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 14px 16px;
  background: rgba(255,255,255,.06);
  text-decoration: none;
  transition: background .12s;
}
.chub-highlight-card:hover { background: rgba(255,255,255,.1); }
.chub-highlight-label {
  display: block;
  font-size: 12px; color: rgba(255,255,255,.55);
}
.chub-highlight-value {
  display: block;
  font-size: 16px; font-weight: 800;
  color: #fff;
}
.chub-chevron { stroke: rgba(255,255,255,.35); margin-left: auto; flex-shrink: 0; }

/* ── Grupos de menu ──────────────────────────────────── */
.chub-groups {
  padding: 18px 16px 0;
  display: flex; flex-direction: column; gap: 20px;
}
.chub-group-title {
  font-size: 12px; font-weight: 700;
  text-transform: uppercase; letter-spacing: .6px;
  color: #6b7280;
  margin: 0 4px 8px;
}
.chub-menu-card {
  background: #fff;
  border-radius: 14px;
  overflow: hidden;
  box-shadow: 0 1px 3px rgba(0,0,0,.06);
}
.chub-menu-item {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 15px 16px;
  text-decoration: none;
  color: #111827;
  border-bottom: 1px solid #f3f4f6;
  transition: background .1s;
  -webkit-tap-highlight-color: transparent;
}
.chub-menu-item:hover   { background: #f9fafb; }
.chub-menu-item:active  { background: #f3f4f6; }
.chub-menu-item--last   { border-bottom: none; }
.chub-menu-item--danger { color: #dc2626; }
.chub-menu-item--danger .chub-menu-ico svg { stroke: #dc2626; }

.chub-menu-ico {
  width: 36px; height: 36px;
  border-radius: 9px;
  background: #f3f4f6;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.chub-menu-ico svg { width: 18px; height: 18px; }
.chub-menu-label {
  flex: 1;
  font-size: 15px; font-weight: 500;
}
.chub-menu-badge {
  font-size: 11px; font-weight: 700;
  padding: 2px 8px; border-radius: 99px;
  flex-shrink: 0;
}
.chub-menu-badge--blue   { background: #dbeafe; color: #1d4ed8; }
.chub-menu-badge--orange { background: #ffedd5; color: #c2410c; }
.chub-menu-badge--gray   { background: #f3f4f6; color: #4b5563; }

.chub-menu-item .chub-chevron {
  width: 16px; height: 16px;
  stroke: #d1d5db; flex-shrink: 0;
}

/* Desktop: esconde o hub e usa o layout com sidebar */
@media (min-width: 769px) {
  .conta-hub {
    background: transparent;
    min-height: auto;
    padding-bottom: 0;
  }
  .chub-header {
    display: none;
  }
  .chub-groups {
    padding: 0;
  }
  .chub-group-title {
    font-size: 11px;
    color: #9ca3af;
    margin-bottom: 6px;
  }
}
</style>