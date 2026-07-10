<?php
// /views/partials/banner-item.php
/** Renderiza um único banner. Espera $b com todos os campos. */

$tipoMidia = $b['tipo_midia'] ?? 'imagem';
$temVideo  = !empty($b['arquivo_video']) || !empty($b['video_url_externo']);
$temImagem = !empty($b['arquivo_imagem']);

$imgDesktop = !empty($b['arquivo_imagem'])         ? View::uploadR2($b['arquivo_imagem'])         : null;
$imgMobile  = !empty($b['arquivo_imagem_mobile'])  ? View::uploadR2($b['arquivo_imagem_mobile'])  : $imgDesktop;
$vidDesktop = !empty($b['arquivo_video'])          ? View::upload('banners/' . $b['arquivo_video'])          : null;
$vidMobile  = !empty($b['arquivo_video_mobile'])   ? View::upload('banners/' . $b['arquivo_video_mobile'])   : $vidDesktop;

$posStyle = [
    'top-left'      => 'justify-content:flex-start;align-items:flex-start;text-align:left',
    'top-center'    => 'justify-content:center;align-items:flex-start;text-align:center',
    'top-right'     => 'justify-content:flex-end;align-items:flex-start;text-align:right',
    'left'          => 'justify-content:flex-start;align-items:center;text-align:left',
    'center'        => 'justify-content:center;align-items:center;text-align:center',
    'right'         => 'justify-content:flex-end;align-items:center;text-align:right',
    'bottom-left'   => 'justify-content:flex-end;align-items:flex-start;text-align:left',
    'bottom-center' => 'justify-content:center;align-items:flex-end;text-align:center',
    'bottom-right'  => 'justify-content:flex-end;align-items:flex-end;text-align:right',
][$b['posicao_texto'] ?? 'center'];

$wrapStyle = '';
if (!empty($b['cor_fundo'])) $wrapStyle .= "background:{$b['cor_fundo']};";

$linkGeral = $b['link_geral'] ?? null;
$tag       = $linkGeral ? 'a' : 'div';
$tagAttrs  = $linkGeral
    ? 'href="' . View::e($linkGeral) . '"
       target="' . View::e($b['link_target'] ?? '_self') . '"
       data-banner-click="' . (int)$b['id'] . '"'
    : '';

// ── Novos: badge e countdown ─────────────────────────
$temBadge     = !empty($b['nome_publico']);
$temCountdown = !empty($b['data_fim']) && strtotime($b['data_fim']) > time();
$cdUid        = 'bn_cd_' . (int)$b['id'];
?>

<<?= $tag ?> class="bn-item" style="<?= $wrapStyle ?>"
   data-banner-id="<?= (int)$b['id'] ?>"
   <?= $tagAttrs ?>>

  <!-- ── Mídia ───────────────────────────────────────── -->
  <?php if ($tipoMidia === 'video' || $tipoMidia === 'video_com_imagem'): ?>

    <?php if (!empty($b['video_url_externo'])): ?>
    <div class="bn-item-video bn-item-video--external">
      <iframe src="<?= View::e($this->buildEmbedUrl($b['video_url_externo'], $b)) ?>"
              frameborder="0" allow="autoplay; encrypted-media"
              allowfullscreen loading="lazy"></iframe>
    </div>

    <?php elseif ($vidDesktop): ?>
    <video class="bn-item-video bn-item-video--desktop"
           <?= $b['video_autoplay'] ? 'autoplay' : '' ?>
           <?= $b['video_loop']     ? 'loop'     : '' ?>
           <?= $b['video_mute']     ? 'muted'    : '' ?>
           playsinline preload="metadata"
           <?php if ($tipoMidia === 'video_com_imagem' && $imgDesktop): ?>
           poster="<?= $imgDesktop ?>"
           <?php endif; ?>>
      <source src="<?= $vidDesktop ?>" type="video/mp4">
    </video>

      <?php if ($vidMobile && $vidMobile !== $vidDesktop): ?>
      <video class="bn-item-video bn-item-video--mobile"
             <?= $b['video_autoplay'] ? 'autoplay' : '' ?>
             <?= $b['video_loop']     ? 'loop'     : '' ?>
             <?= $b['video_mute']     ? 'muted'    : '' ?>
             playsinline preload="metadata">
        <source src="<?= $vidMobile ?>" type="video/mp4">
      </video>
      <?php endif; ?>

    <?php endif; ?>

  <?php elseif ($imgDesktop): ?>
    <picture>
      <?php if ($imgMobile && $imgMobile !== $imgDesktop): ?>
      <source media="(max-width: 768px)" srcset="<?= $imgMobile ?>">
      <?php endif; ?>
      <img src="<?= $imgDesktop ?>"
           alt="<?= View::e($b['alt_text'] ?? $b['titulo']) ?>"
           class="bn-item-img" loading="lazy">
    </picture>
  <?php endif; ?>

  <!-- ── Overlay de cor ──────────────────────────────── -->
  <?php if (!empty($b['cor_overlay']) && (int)($b['overlay_opacidade'] ?? 0) > 0): ?>
  <div class="bn-item-overlay"
       style="background:<?= View::e($b['cor_overlay']) ?>;
              opacity:<?= ((int)$b['overlay_opacidade']) / 100 ?>;"></div>
  <?php endif; ?>

  <!-- ── Conteúdo (texto + CTAs) ─────────────────────── -->
  <?php if ($temBadge || !empty($b['titulo_overlay']) || !empty($b['subtitulo_overlay']) || !empty($b['cta1_texto']) || $temCountdown): ?>
  <div class="bn-item-content <?= $temCountdown ? 'bn-item-content--has-countdown' : '' ?> container"
       style="<?= $posStyle ?>;color:<?= View::e($b['cor_texto'] ?? '#fff') ?>;">

    <!-- Coluna de texto -->
    <div class="bn-item-text-col">

      <!-- ① BADGE — só quando nome_publico estiver preenchido -->
      <?php if ($temBadge): ?>
      <div class="bn-badge">
        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
          <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
        </svg>
        <?= View::e($b['titulo_overlay']) ?>
      </div>
      <?php endif; ?>

      <?php if (!empty($b['titulo_overlay'])): ?>
      <h3 class="bn-item-title" style="color:inherit;">
        <?= nl2br(View::e($b['titulo_overlay'])) ?>
      </h3>
      <?php endif; ?>

      <?php if (!empty($b['subtitulo_overlay'])): ?>
      <p class="bn-item-subtitle" style="color:inherit;opacity:.92;">
        <?= nl2br(View::e($b['subtitulo_overlay'])) ?>
      </p>
      <?php endif; ?>

      <?php if (!empty($b['cta1_texto']) || !empty($b['cta2_texto'])): ?>
      <div class="bn-item-ctas">

        <?php if (!empty($b['cta1_texto']) && !empty($b['cta1_link'])): ?>
        <a href="<?= View::e($b['cta1_link']) ?>"
           target="<?= View::e($b['cta1_target'] ?? '_self') ?>"
           class="bn-cta bn-cta--<?= View::e($b['cta1_estilo'] ?? 'primary') ?>"
           data-banner-click="<?= (int)$b['id'] ?>"
           <?= $linkGeral ? 'onclick="event.stopPropagation();"' : '' ?>>
          <?= View::e($b['cta1_texto']) ?>
          <!-- ② SETA — ícone no botão primário -->
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
               aria-hidden="true">
            <line x1="5" y1="12" x2="19" y2="12"/>
            <polyline points="12 5 19 12 12 19"/>
          </svg>
        </a>
        <?php endif; ?>

        <?php if (!empty($b['cta2_texto']) && !empty($b['cta2_link'])): ?>
        <a href="<?= View::e($b['cta2_link']) ?>"
           target="<?= View::e($b['cta2_target'] ?? '_self') ?>"
           class="bn-cta bn-cta--<?= View::e($b['cta2_estilo'] ?? 'outline') ?>"
           data-banner-click="<?= (int)$b['id'] ?>"
           <?= $linkGeral ? 'onclick="event.stopPropagation();"' : '' ?>>
          <?= View::e($b['cta2_texto']) ?>
          <!-- ② SETA — ícone no botão secundário -->
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
               aria-hidden="true">
            <line x1="5" y1="12" x2="19" y2="12"/>
            <polyline points="12 5 19 12 12 19"/>
          </svg>
        </a>
        <?php endif; ?>

      </div>
      <?php endif; ?>

    </div><!-- /.bn-item-text-col -->

    <!-- ③ COUNTDOWN — só quando data_fim estiver no futuro -->
    <?php if ($temCountdown): ?>
    <div class="bn-countdown" id="<?= $cdUid ?>"
         data-fim="<?= date('Y-m-d\TH:i:s', strtotime($b['data_fim'])) ?>">
      <div class="bn-countdown-label">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" aria-hidden="true">
          <circle cx="12" cy="12" r="10"/>
          <polyline points="12 6 12 12 16 14"/>
        </svg>
        TERMINA EM
      </div>
      <div class="bn-countdown-units">
        <div class="bn-unit">
          <span class="bn-unit-val" data-unit="dias">--</span>
          <span class="bn-unit-lbl">DIAS</span>
        </div>
        <div class="bn-unit-sep">:</div>
        <div class="bn-unit">
          <span class="bn-unit-val" data-unit="horas">--</span>
          <span class="bn-unit-lbl">HORAS</span>
        </div>
        <div class="bn-unit-sep">:</div>
        <div class="bn-unit">
          <span class="bn-unit-val" data-unit="min">--</span>
          <span class="bn-unit-lbl">MIN</span>
        </div>
        <div class="bn-unit-sep">:</div>
        <div class="bn-unit">
          <span class="bn-unit-val" data-unit="seg">--</span>
          <span class="bn-unit-lbl">SEG</span>
        </div>
      </div>
    </div>
    <?php endif; ?>

  </div>
  <?php endif; ?>

</<?= $tag ?>>