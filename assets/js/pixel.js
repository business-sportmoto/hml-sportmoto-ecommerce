/* ════════════════════════════════════════════════════════
   pixel.js — Meta Pixel com Consent Mode + dedup por eventID
   Peça 1 de 5 (a fundação). As outras peças usam window.smPixel.

   Incluir no layout (main.php), no <head>, DEPOIS do consent-init
   (o Consent Mode já tem que estar definido). Requer:
   - window.META_PIXEL_ID (o Dataset ID / Pixel ID)
   - o cookie sm_consent (pra checar consentimento de marketing)

   REGRAS (doc Meta 2026):
   - eventID vai no 4º parâmetro do fbq: fbq('track','X',{dados},{eventID})
   - só dispara com consentimento de marketing
   - o mesmo eventID tem que ir pro CAPI (servidor) pra deduplicar
   ════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  // ── Checa consentimento de marketing (lê o cookie sm_consent) ──
  function temConsentimentoMarketing() {
    var m = document.cookie.match(/(?:^|;\s*)sm_consent=([^;]+)/);
    if (!m) return false;
    try {
      var d = JSON.parse(decodeURIComponent(m[1]));
      return d && d.m === 1; // 'm' = marketing (mesmo formato do ConsentService)
    } catch (e) { return false; }
  }

  // ── Carrega o Pixel base (só uma vez, e só com consentimento) ──
  var pixelCarregado = false;
  function carregarPixel() {
    
    if (pixelCarregado) return true;
    if (!window.META_PIXEL_ID) return false;
    if (!temConsentimentoMarketing()) return false;
    
    // Snippet oficial do Pixel
    !function(f,b,e,v,n,t,s){
      if(f.fbq)return;n=f.fbq=function(){n.callMethod?
      n.callMethod.apply(n,arguments):n.queue.push(arguments)};
      if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
      n.queue=[];t=b.createElement(e);t.async=!0;
      t.src=v;s=b.getElementsByTagName(e)[0];
      s.parentNode.insertBefore(t,s)}(window,document,'script',
      'https://connect.facebook.net/en_US/fbevents.js');

    fbq('init', window.META_PIXEL_ID);
    pixelCarregado = true;
    return true;
  }

  // ── Dispara um evento com eventID (a chave da dedup) ──
  // @param nome  string  ex: 'ViewContent', 'AddToCart', 'Purchase'
  // @param dados object  custom_data (value, currency, content_ids...)
  // @param eventId string O MESMO id que o servidor manda pro CAPI
  function track(nome, dados, eventId) {
    // Sem consentimento → não dispara (fail-closed, igual ao servidor)
    if (!temConsentimentoMarketing()) return;
    if (!carregarPixel()) return;

    dados = dados || {};
    // eventID no 4º parâmetro (options), NÃO no custom_data — regra Meta
    if (eventId) {
      fbq('track', nome, dados, { eventID: eventId });
    } else {
      fbq('track', nome, dados);
    }
    
  }

  // ── PageView base (dispara ao carregar, se houver consentimento) ──
  function pageView() {
    if (!temConsentimentoMarketing()) return;
    if (!carregarPixel()) return;
    fbq('track', 'PageView');
  }

  // Expõe a API pública (as outras peças usam isto)
  window.smPixel = {
    track: track,
    pageView: pageView,
    temConsentimento: temConsentimentoMarketing,
    carregar: carregarPixel
  };

  // Dispara o PageView automático no load (se consentido)
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', pageView);
  } else {
    pageView();
  }

  // Se o consentimento for dado DEPOIS (banner), re-tenta carregar.
  // O banner dispara este evento custom quando o usuário aceita.
  window.addEventListener('sm:consent-updated', function () {
    if (temConsentimentoMarketing()) {
      carregarPixel();
      pageView();
    }
  });
})();