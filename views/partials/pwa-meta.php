<?php
/**
 * views/partials/pwa-meta.php
 * Incluir dentro do <head> de TODOS os layouts.
 * ⚠️ Remove qualquer outra <meta name="viewport"> dos layouts.
 */

// Carrega nome do app do banco (com cache simples em sessão)
if (!isset($_SESSION['_pwa_name'])) {
    try {
        $db  = Database::getInstance()->getConnection();
        $cfg = $db->query("SELECT app_name, app_short_name, theme_color FROM pwa_config WHERE id=1 LIMIT 1")->fetch();
        $_SESSION['_pwa_name']  = $cfg['app_name']       ?? 'Minha Loja';
        $_SESSION['_pwa_short'] = $cfg['app_short_name'] ?? 'Loja';
        $_SESSION['_pwa_theme'] = $cfg['theme_color']    ?? '#0f172a';
    } catch (\Throwable) {
        $_SESSION['_pwa_name']  = 'Minha Loja';
        $_SESSION['_pwa_short'] = 'Loja';
        $_SESSION['_pwa_theme'] = '#0f172a';
    }
}
$_pwaName  = htmlspecialchars($_SESSION['_pwa_name'],  ENT_QUOTES, 'UTF-8');
$_pwaShort = htmlspecialchars($_SESSION['_pwa_short'], ENT_QUOTES, 'UTF-8');
$_pwaTheme = htmlspecialchars($_SESSION['_pwa_theme'], ENT_QUOTES, 'UTF-8');
?>

<!-- ══ PWA Meta Tags ════════════════════════════════════ -->

<!-- Viewport — viewport-fit=cover para notch/home bar iOS -->
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

<!-- Manifest -->
<link rel="manifest" href="<?= BASE_URL ?>/manifest.json">

<!-- Theme color (barra de status no Android/Chrome) -->
<meta name="theme-color" content="<?= $_pwaTheme ?>">

<!-- Android standalone -->
<meta name="mobile-web-app-capable" content="yes">

<!-- iOS standalone — OBRIGATÓRIO para funcionar como app no iPhone -->
<meta name="apple-mobile-web-app-capable" content="yes">

<!-- 
  black          = barra preta sólida, conteúdo começa abaixo da barra
  black-translucent = barra transparente, conteúdo passa POR BAIXO (requer safe-area-inset)
  default        = barra branca
  Use "black" se não quiser lidar com safe areas; "black-translucent" se quiser tela cheia real
-->
<meta name="apple-mobile-web-app-status-bar-style" content="black">

<meta name="apple-mobile-web-app-title" content="<?= $_pwaShort ?>">

<!-- Ícone iOS (home screen) -->
<link rel="apple-touch-icon" href="<?= BASE_URL ?>/icons/apple-touch-icon.png">

<!-- Favicon -->
<link rel="icon" type="image/png" sizes="192x192" href="<?= BASE_URL ?>/icons/icon-192.png">

<!-- ══ Splash screens iOS ═══════════════════════════════
     OBRIGATÓRIO para não mostrar tela branca ao abrir o app.
     O iOS exige um arquivo por resolução de dispositivo.
     Gerados pelo PwaConfigService::publicar()
     ════════════════════════════════════════════════════ -->

<!-- iPhone SE (1st/2nd gen) — 320×568 @2x -->
<link rel="apple-touch-startup-image"
      href="<?= BASE_URL ?>/icons/splash-640x1136.png"
      media="(device-width:320px) and (device-height:568px) and (-webkit-device-pixel-ratio:2)">

<!-- iPhone 8 — 375×667 @2x -->
<link rel="apple-touch-startup-image"
      href="<?= BASE_URL ?>/icons/splash-750x1334.png"
      media="(device-width:375px) and (device-height:667px) and (-webkit-device-pixel-ratio:2)">

<!-- iPhone 8 Plus — 414×736 @3x -->
<link rel="apple-touch-startup-image"
      href="<?= BASE_URL ?>/icons/splash-1242x2208.png"
      media="(device-width:414px) and (device-height:736px) and (-webkit-device-pixel-ratio:3)">

<!-- iPhone X / XS / 11 Pro — 375×812 @3x -->
<link rel="apple-touch-startup-image"
      href="<?= BASE_URL ?>/icons/splash-1125x2436.png"
      media="(device-width:375px) and (device-height:812px) and (-webkit-device-pixel-ratio:3)">

<!-- iPhone XR / 11 — 414×896 @2x -->
<link rel="apple-touch-startup-image"
      href="<?= BASE_URL ?>/icons/splash-828x1792.png"
      media="(device-width:414px) and (device-height:896px) and (-webkit-device-pixel-ratio:2)">

<!-- iPhone XS Max / 11 Pro Max — 414×896 @3x -->
<link rel="apple-touch-startup-image"
      href="<?= BASE_URL ?>/icons/splash-1242x2688.png"
      media="(device-width:414px) and (device-height:896px) and (-webkit-device-pixel-ratio:3)">

<!-- iPhone 12 / 13 / 14 — 390×844 @3x -->
<link rel="apple-touch-startup-image"
      href="<?= BASE_URL ?>/icons/splash-1170x2532.png"
      media="(device-width:390px) and (device-height:844px) and (-webkit-device-pixel-ratio:3)">

<!-- iPhone 12 Pro Max / 13 Pro Max / 14 Plus — 428×926 @3x -->
<link rel="apple-touch-startup-image"
      href="<?= BASE_URL ?>/icons/splash-1284x2778.png"
      media="(device-width:428px) and (device-height:926px) and (-webkit-device-pixel-ratio:3)">

<!-- iPhone 15 / 15 Pro — 393×852 @3x -->
<link rel="apple-touch-startup-image"
      href="<?= BASE_URL ?>/icons/splash-1179x2556.png"
      media="(device-width:393px) and (device-height:852px) and (-webkit-device-pixel-ratio:3)">

<!-- iPhone 15 Plus / 15 Pro Max — 430×932 @3x -->
<link rel="apple-touch-startup-image"
      href="<?= BASE_URL ?>/icons/splash-1290x2796.png"
      media="(device-width:430px) and (device-height:932px) and (-webkit-device-pixel-ratio:3)">

<!-- ══ Registro do Service Worker ═══════════════════════ -->
<script>
(function () {
  if (!('serviceWorker' in navigator)) return;

  window.addEventListener('load', function () {
    navigator.serviceWorker.register('<?= BASE_URL ?>/sw.js', { scope: '/' })
      .then(function (reg) {
        reg.addEventListener('updatefound', function () {
          var novo = reg.installing;
          if (!novo) return;
          novo.addEventListener('statechange', function () {
            if (novo.state === 'installed' && navigator.serviceWorker.controller) {
              novo.postMessage('SKIP_WAITING');
            }
          });
        });
      })
      .catch(function (err) {
        console.warn('[PWA] SW não registrado:', err);
      });

    var refreshing = false;
    navigator.serviceWorker.addEventListener('controllerchange', function () {
      if (refreshing) return;
      refreshing = true;
      window.location.reload();
    });
  });
})();
</script>