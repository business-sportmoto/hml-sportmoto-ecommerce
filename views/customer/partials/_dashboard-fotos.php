<?php
// ════════════════════════════════════════════════════════
// views/customer/partials/_dashboard-fotos.php
// Bloco de fotos da garagem na dashboard do cliente
// Uso: View::partial('customer/partials/_dashboard-fotos')
// ════════════════════════════════════════════════════════
$clienteId = (int)Session::get('cliente_id');

$db   = Database::getInstance()->getConnection();
$stmt = $db->prepare(
    "SELECT
        f.id,
        f.arquivo_thumb,
        f.arquivo_medium,
        f.visibilidade,
        f.status_moderacao,
        f.motivo_rejeicao,
        f.capa,
        f.legenda,
        f.criado_em,
        cv.id        AS veiculo_id,
        cv.apelido   AS moto_apelido,
        cv.ano       AS moto_ano,
        cv.cor       AS moto_cor,
        cv.principal AS moto_ativa,
        mm.nome      AS montadora_nome,
        mo.nome      AS modelo_nome
     FROM cliente_veiculo_fotos f
     JOIN cliente_veiculos cv  ON cv.id = f.veiculo_id
     JOIN moto_montadoras mm   ON mm.id = cv.montadora_id
     LEFT JOIN moto_modelos mo ON mo.id = cv.modelo_id
     WHERE f.cliente_id = ?
     ORDER BY f.capa DESC, f.criado_em DESC
     LIMIT 24"
);
$stmt->execute([$clienteId]);
$fotos = $stmt->fetchAll();

if (empty($fotos)) return;

// Agrupa fotos por moto pra exibição de totais
$totalFotos    = count($fotos);
$totalPublicas = count(array_filter($fotos, fn($f) => $f['visibilidade'] === 'publico' && $f['status_moderacao'] === 'aprovada'));
$totalPendentes= count(array_filter($fotos, fn($f) => $f['status_moderacao'] === 'pendente'));

// Monta label da moto
function monoLabel(array $f): string {
    if (!empty($f['moto_apelido'])) return $f['moto_apelido'];
    $parts = [$f['montadora_nome']];
    if ($f['modelo_nome']) $parts[] = $f['modelo_nome'];
    if ($f['moto_ano'])    $parts[] = $f['moto_ano'];
    return implode(' · ', array_filter($parts));
}
?>

<div class="dash-fotos-block">

  <!-- Header do bloco -->
  <div class="dash-fotos-header">
    <div class="dash-fotos-heading">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2" stroke-linecap="round">
        <rect x="3" y="3" width="18" height="18" rx="2"/>
        <circle cx="8.5" cy="8.5" r="1.5"/>
        <polyline points="21 15 16 10 5 21"/>
      </svg>
      <h2>Fotos da Garagem</h2>
    </div>

    <!-- Stats compactos -->
    <div class="dash-fotos-stats">
      <span class="dash-fotos-stat">
        <strong><?= $totalFotos ?></strong>
        total
      </span>
      <?php if ($totalPublicas > 0): ?>
      <span class="dash-fotos-stat dash-fotos-stat--green">
        <strong><?= $totalPublicas ?></strong>
        públic<?= $totalPublicas === 1 ? 'a' : 'as' ?>
      </span>
      <?php endif; ?>
      <?php if ($totalPendentes > 0): ?>
      <span class="dash-fotos-stat dash-fotos-stat--amber">
        <strong><?= $totalPendentes ?></strong>
        em análise
      </span>
      <?php endif; ?>
    </div>

    <a href="<?= BASE_URL ?>/minha-conta/garagem" class="dash-fotos-ver-mais">
      Ver garagem
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
        <line x1="5" y1="12" x2="19" y2="12"/>
        <polyline points="12 5 19 12 12 19"/>
      </svg>
    </a>
  </div>

  <!-- Grade de fotos -->
  <div class="dash-fotos-grid">
    <?php foreach ($fotos as $f):
      $thumb    = UPLOAD_URL . '/garagem/' . $f['arquivo_thumb'];
      $medium   = UPLOAD_URL . '/garagem/' . $f['arquivo_medium'];
      $label    = monoLabel($f);
      $cor      = $f['moto_cor'] ?: '#1e293b';
      $veicUrl  = BASE_URL . '/minha-conta/garagem/moto/' . $f['veiculo_id'];

      // Determina badge de status da foto
      $statusBadge = match (true) {
        $f['visibilidade'] === 'privado' => [
            'label' => 'Privada',
            'cls'   => 'dash-foto-badge--privada',
            'icon'  => '<path d="M17 11V7a5 5 0 00-10 0v4"/><rect x="3" y="11" width="18" height="11" rx="2"/>',
        ],
        $f['status_moderacao'] === 'pendente' => [
            'label' => 'Em análise',
            'cls'   => 'dash-foto-badge--pendente',
            'icon'  => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        ],
        $f['status_moderacao'] === 'rejeitada' => [
            'label' => 'Rejeitada',
            'cls'   => 'dash-foto-badge--rejeitada',
            'icon'  => '<circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>',
        ],
        default => [
            'label' => 'Pública',
            'cls'   => 'dash-foto-badge--publica',
            'icon'  => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"/>',
        ],
      };
    ?>

    <div class="dash-foto-item" tabindex="0" role="button"
         data-src="<?= View::e($medium) ?>"
         data-label="<?= View::e($label) ?>"
         aria-label="Ver foto: <?= View::e($label) ?>">

      <!-- Thumb -->
      <div class="dash-foto-thumb">
        <img src="<?= View::e($thumb) ?>"
             alt="<?= View::e($f['legenda'] ?: $label) ?>"
             loading="lazy">

        <!-- Capa badge -->
        <?php if ($f['capa']): ?>
        <span class="dash-foto-badge dash-foto-badge--capa" title="Foto de capa da moto">
          <svg width="9" height="9" viewBox="0 0 24 24" fill="currentColor">
            <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
          </svg>
          Capa
        </span>
        <?php endif; ?>

        <!-- Overlay de hover -->
        <div class="dash-foto-overlay">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
               stroke="white" stroke-width="2" stroke-linecap="round">
            <circle cx="11" cy="11" r="8"/>
            <line x1="21" y1="21" x2="16.65" y2="16.65"/>
          </svg>
        </div>
      </div>

      <!-- Badges de info abaixo da foto -->
      <div class="dash-foto-info">

        <!-- Nome da moto (badge com cor) -->
        <a href="<?= View::e($veicUrl) ?>"
           class="dash-foto-badge dash-foto-badge--moto"
           style="--moto-cor:<?= View::e($cor) ?>;"
           title="<?= View::e($label) ?>"
           onclick="event.stopPropagation()">
          <svg width="9" height="9" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <circle cx="5.5" cy="17.5" r="3.5"/>
            <circle cx="18.5" cy="17.5" r="3.5"/>
            <path d="M15 6h-2l-3 8H5.5"/>
            <path d="M15 6l3 5h1.5"/>
          </svg>
          <span><?= View::e(mb_substr($label, 0, 22)) ?></span>
          <?php if ($f['moto_ativa']): ?>
          <span class="dash-moto-ativa-dot" title="Moto ativa"></span>
          <?php endif; ?>
        </a>

        <!-- Badge de status da foto -->
        <span class="dash-foto-badge <?= $statusBadge['cls'] ?>"
              <?php if ($f['status_moderacao'] === 'rejeitada' && $f['motivo_rejeicao']): ?>
              title="Motivo: <?= View::e($f['motivo_rejeicao']) ?>"
              <?php endif; ?>>
          <svg width="9" height="9" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <?= $statusBadge['icon'] ?>
          </svg>
          <?= $statusBadge['label'] ?>
        </span>

      </div>
    </div>

    <?php endforeach; ?>
  </div>

  <!-- Ver todas (se tiver mais de 24) -->
  <?php
  $totalReal = (int)$db->prepare(
      "SELECT COUNT(*) FROM cliente_veiculo_fotos WHERE cliente_id = ?"
  )->execute([$clienteId]) + 0;
  $stmt2 = $db->prepare("SELECT COUNT(*) FROM cliente_veiculo_fotos WHERE cliente_id = ?");
  $stmt2->execute([$clienteId]);
  $totalReal = (int)$stmt2->fetchColumn();
  ?>
  <?php if ($totalReal > 24): ?>
  <div class="dash-fotos-footer">
    <a href="<?= BASE_URL ?>/minha-conta/garagem" class="dash-fotos-load-more">
      Ver todas as <?= $totalReal ?> fotos →
    </a>
  </div>
  <?php endif; ?>

</div>

<!-- Lightbox simples -->
<div id="dash-fotos-lightbox" class="dash-fotos-lightbox" hidden>
  <div class="dash-fotos-lb-backdrop"></div>
  <div class="dash-fotos-lb-content">
    <button type="button" class="dash-fotos-lb-close" id="dash-lb-close">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
        <line x1="18" y1="6" x2="6"  y2="18"/>
        <line x1="6"  y1="6" x2="18" y2="18"/>
      </svg>
    </button>
    <img id="dash-lb-img" src="" alt="">
    <p id="dash-lb-label" class="dash-lb-label"></p>
  </div>
</div>

