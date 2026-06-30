<?php
// ════════════════════════════════════════════════════════
// views/partials/nossos-clientes.php
// Section "Nossos Clientes" — universal e configurável
//
// PARÂMETROS (todos opcionais):
//   $titulo     string  'Nossos clientes'
//   $subtitulo  string  'Veja quem já está com a gente'
//   $limite     int     12
//   $dark       bool    false  (fundo escuro)
//   $mostrarCta bool    false  (exibe botão ao final)
//   $ctaLabel   string  'Envie sua foto'
//   $ctaUrl     string  BASE_URL . '/minha-conta/garagem'
//   $soApenasComInsta bool false (filtra apenas clientes com Instagram)
//   $group      string  'nc-fotos' (nome do grupo para o lightbox)
//
// INCLUSÃO:
//   View::partial('partials/nossos-clientes')
//   View::partial('partials/nossos-clientes', ['dark' => true, 'limite' => 8])
// ════════════════════════════════════════════════════════

$titulo          = $titulo        ?? 'Nossos clientes';
$subtitulo       = $subtitulo     ?? 'Quem já está com a gente';
$limite          = (int)($limite  ?? 12);
$dark            = (bool)($dark   ?? false);
$mostrarCta      = (bool)($mostrarCta ?? false);
$ctaLabel        = $ctaLabel      ?? 'Envie sua foto também';
$ctaUrl          = $ctaUrl        ?? (BASE_URL . '/minha-conta/garagem');
$soComInsta      = (bool)($soApenasComInsta ?? false);
$group           = $group         ?? 'nc-fotos';

// ── Query ─────────────────────────────────────────────────
$db   = Database::getInstance()->getConnection();

$whereInsta = $soComInsta
    ? "AND c.insta_cliente IS NOT NULL AND c.insta_cliente != ''"
    : '';

$stmt = $db->prepare(
    "SELECT
        f.id,
        f.arquivo_medium,
        f.legenda,
        c.insta_cliente,
        cv.apelido            AS moto_apelido,
        cv.ano                AS moto_ano,
        mm.nome               AS montadora,
        mo.nome               AS modelo
     FROM cliente_veiculo_fotos f
     JOIN cliente_veiculos cv ON cv.id = f.veiculo_id
     JOIN clientes c          ON c.id  = f.cliente_id
     JOIN moto_montadoras mm  ON mm.id = cv.montadora_id
     LEFT JOIN moto_modelos mo ON mo.id = cv.modelo_id
     WHERE f.visibilidade     = 'publico'
       AND f.status_moderacao = 'aprovada'
       {$whereInsta}
     ORDER BY RAND()
     LIMIT ?"
);
$stmt->bindValue(1, $limite, PDO::PARAM_INT);
$stmt->execute();
$fotos = $stmt->fetchAll();

if (empty($fotos)) return;

if (!function_exists('nc_moto_label2')) {
// Monta label de moto
function nc_moto_label2(array $f): string {
    return false;
    $parts = [];
    if ($f['moto_apelido']) return $f['moto_apelido'];
    if ($f['montadora'])    $parts[] = $f['montadora'];
    if ($f['modelo'])       $parts[] = $f['modelo'];
    if ($f['moto_ano'])     $parts[] = $f['moto_ano'];
    return implode(' ', $parts);
}
}
?>

<section class="nossos-clientes<?= $dark ? ' nossos-clientes--dark' : '' ?>">
  <div class="container">

    <!-- Header -->
    <div class="nc-header">
      <p class="nc-kicker">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.5" stroke-linecap="round">
          <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/>
          <circle cx="9" cy="7" r="4"/>
          <path d="M23 21v-2a4 4 0 00-3-3.87"/>
          <path d="M16 3.13a4 4 0 010 7.75"/>
        </svg>
        Comunidade
      </p>
      <h2 class="nc-title"><?= View::e($titulo) ?></h2>
      <?php if ($subtitulo): ?>
      <p class="nc-sub"><?= View::e($subtitulo) ?></p>
      <?php endif; ?>
    </div>

    <!-- Grade de fotos -->
    <div class="nc-grid">
      <?php foreach ($fotos as $i => $f):
        $imgMedium = UPLOAD_URL . '/garagem/' . $f['arquivo_medium'];
        $insta     = $f['insta_cliente'] ? ltrim($f['insta_cliente'], '@') : null;
        $instaUrl  = $insta ? 'https://instagram.com/' . urlencode($insta) : null;
        $motoLabel = nc_moto_label2($f);
        $caption   = $f['legenda'] ?: ($insta ? '@' . $insta : $motoLabel);
      ?>

      <div class="nc-card"
           data-lightbox="<?= View::e($group) ?>"
           data-lightbox-src="<?= View::e($imgMedium) ?>"
           data-lightbox-caption="<?= View::e($caption) ?>"
           tabindex="0"
           role="button"
           aria-label="Ver foto<?= $insta ? ' de @' . View::e($insta) : '' ?>">

        <img class="nc-card-img"
             src="<?= View::e($imgMedium) ?>"
             alt="<?= View::e($caption) ?>"
             loading="<?= $i < 4 ? 'eager' : 'lazy' ?>">

        <div class="nc-card-overlay">
          <div class="nc-card-info">

            <?php if ($instaUrl): ?>
            <a class="nc-insta"
               href="<?= View::e($instaUrl) ?>"
               target="_blank"
               rel="noopener noreferrer"
               onclick="event.stopPropagation()"
               title="Ver no Instagram">
              <!-- Ícone Instagram -->
              <svg viewBox="0 0 24 24" fill="currentColor">
                <path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/>
              </svg>
              @<?= View::e($insta) ?>
            </a>
            <?php endif; ?>

          </div>

          <?php if ($motoLabel): ?>
          <p class="nc-moto-badge"><?= View::e(mb_substr($motoLabel, 0, 32)) ?></p>
          <?php endif; ?>
        </div>

      </div>
      <?php endforeach; ?>
    </div>

    <!-- CTA opcional -->
    <?php if ($mostrarCta): ?>
    <div class="nc-footer">
      <a href="<?= View::e($ctaUrl) ?>" class="nc-cta">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.5" stroke-linecap="round">
          <rect x="3" y="3" width="18" height="18" rx="2"/>
          <circle cx="8.5" cy="8.5" r="1.5"/>
          <polyline points="21 15 16 10 5 21"/>
        </svg>
        <?= View::e($ctaLabel) ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2.5" stroke-linecap="round">
          <line x1="5" y1="12" x2="19" y2="12"/>
          <polyline points="12 5 19 12 12 19"/>
        </svg>
      </a>
    </div>
    <?php endif; ?>

  </div>
</section>