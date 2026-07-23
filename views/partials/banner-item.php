<?php
// /views/partials/banner-item.php
/** Renderiza um único banner. Espera $b com todos os campos. */

$streamSvc  = new StreamService(
    getenv('CF_ACCOUNT_ID'),
    getenv('CF_STREAM_TOKEN'),
    getenv('CF_STREAM_CUSTOMER_CODE') ?? ''
);

$icones = [
  'flame'     => ['label'=>'Promoção',  'svg'=>'<path d="M12 2c0 0-5 5-5 10a5 5 0 0010 0C17 7 12 2 12 2z"/><path d="M12 12c0 0-2 2-2 4a2 2 0 004 0c0-2-2-4-2-4z"/>'],
  'lightning' => ['label'=>'Relâmpago', 'svg'=>'<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>'],
  'star'      => ['label'=>'Destaque',  'svg'=>'<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>'],
  'percent'   => ['label'=>'Desconto',  'svg'=>'<line x1="19" y1="5" x2="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>'],
  'tag'       => ['label'=>'Coleção',   'svg'=>'<path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>'],
  'mountain'  => ['label'=>'Adventure', 'svg'=>'<polygon points="3 17 8 7 13 12 16 8 21 17"/><polyline points="3 17 21 17"/>'],
  'gift'      => ['label'=>'Presente',  'svg'=>'<polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5" rx="1"/><path d="M12 22V7m0 0H7.5a2.5 2.5 0 010-5C11 2 12 7 12 7zm0 0h4.5a2.5 2.5 0 000-5C13 2 12 7 12 7z"/>'],
  'truck'     => ['label'=>'Entrega',   'svg'=>'<rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>'],
  'moto'      => ['label'=>'Moto',      'svg'=>'<circle cx="5.5" cy="17.5" r="3.5"/><circle cx="18.5" cy="17.5" r="3.5"/><path d="M15 6h-2l-3 8H5.5M15 6l3 5h1.5"/>'],
  'clock'     => ['label'=>'Tempo',     'svg'=>'<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>'],
  'none'      => ['label'=>'Sem ícone', 'svg'=>'<circle cx="12" cy="12" r="10" stroke-dasharray="4 4"/>'],
];

$isUid = fn($v) => is_string($v) && preg_match('/^[a-f0-9]{32}$/i', $v);
$uidDesktop = $isUid($b['arquivo_video'] ?? '')        ? $b['arquivo_video']        : null;
$uidMobile  = $isUid($b['arquivo_video_mobile'] ?? '') ? $b['arquivo_video_mobile'] : null;


$tipoMidia = $b['tipo_midia'] ?? 'imagem';
$temVideo  = !empty($b['arquivo_video']) || !empty($b['video_url_externo']);
$temImagem = !empty($b['arquivo_imagem']);

$imgDesktop = !empty($b['arquivo_imagem'])         ? View::uploadR2($b['arquivo_imagem'])         : null;
$imgMobile  = !empty($b['arquivo_imagem_mobile'])  ? View::uploadR2($b['arquivo_imagem_mobile'])  : $imgDesktop;
// $vidDesktop = !empty($b['arquivo_video'])          ? View::upload('banners/' . $b['arquivo_video'])          : null;
// $vidMobile  = !empty($b['arquivo_video_mobile'])   ? View::upload('banners/' . $b['arquivo_video_mobile'])   : $vidDesktop;

$vidDesktop = $uidDesktop ? $streamSvc->hlsUrl($uidDesktop) : null;
$vidMobile  = $uidMobile  ? $streamSvc->hlsUrl($uidMobile)  : $vidDesktop;

// var_dump($vidDesktop);

// Poster: usa a imagem do banner se houver; senão, o thumbnail do próprio vídeo.
$posterDesktop = $imgDesktop
    ?: ($uidDesktop ? $streamSvc->thumbnailUrl($uidDesktop, ['width'=>1920]) : null);
$posterMobile  = $imgMobile
    ?: ($uidMobile ? $streamSvc->thumbnailUrl($uidMobile, ['width'=>768]) : $posterDesktop);

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
    ? 'href="' . View::e($linkGeral) . '" target="' . View::e($b['link_target'] ?? '_self') . '" data-banner-click="' . (int)$b['id'] . '"'
    : '';

// ── Novos: badge e countdown ─────────────────────────
$temBadge     = !empty($b['nome_publico']) && $b['nome_publico'] !== 'none';
$temCountdown = !empty($b['data_fim']) && strtotime($b['data_fim']) > time();
$cdUid        = 'bn_cd_' . (int)$b['id'];
?>

<<?= $tag ?> class="bn-item trk-banner" style="<?= $wrapStyle ?>"
   data-banner-id="<?= (int)$b['id'] ?>"
   <?= $tagAttrs ?> data-peprare="true"> <!-- 0 -->

  <!-- ── Mídia ───────────────────────────────────────── -->
  <?php if ($tipoMidia === 'video' || $tipoMidia === 'video_com_imagem'): ?>

    <?php if (!empty($b['video_url_externo'])): ?>
    <div class="bn-item-video bn-item-video--external">
      <iframe src="<?= View::e($this->buildEmbedUrl($b['video_url_externo'], $b)) ?>"
              frameborder="0" allow="autoplay; encrypted-media"
              allowfullscreen loading="lazy"></iframe>
    </div>

     <?php elseif ($vidDesktop): ?>
    <!-- HLS via ClipsHls (hls.js). O src vai em data-hls; o JS anexa. -->
    <video class="bn-item-video bn-item-video--desktop"
           data-hls="<?= View::e($vidDesktop) ?>"
           <?= !empty($b['video_autoplay']) ? 'autoplay' : '' ?>
           <?= !empty($b['video_loop'])     ? 'loop'     : '' ?>
           muted
           playsinline preload="none"
           <?php if ($posterDesktop): ?>poster="<?= View::e($posterDesktop) ?>"<?php endif; ?>></video>

      <?php if ($vidMobile && $vidMobile !== $vidDesktop): ?>
      <video class="bn-item-video bn-item-video--mobile"
             data-hls="<?= View::e($vidMobile) ?>"
             <?= !empty($b['video_autoplay']) ? 'autoplay' : '' ?>
             <?= !empty($b['video_loop'])     ? 'loop'     : '' ?>
             muted
             playsinline preload="none"
             <?php if ($posterMobile): ?>poster="<?= View::e($posterMobile) ?>"<?php endif; ?>></video>
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
        <?= $icones[$b['nome_publico']]['svg']; ?>
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
           class="bn-cta bn-cta--<?= View::e($b['cta1_estilo'] ?? 'primary') ?> trk-banner"
           data-banner-click="<?= (int)$b['id'] ?>" data-banner-id="<?= (int)$b['id'] ?>"
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
           class="bn-cta bn-cta--<?= View::e($b['cta2_estilo'] ?? 'outline') ?> trk-banner"
           data-banner-click="<?= (int)$b['id'] ?>" data-banner-id="<?= (int)$b['id'] ?>"
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