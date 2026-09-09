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

  window.META_PIXEL_ID = 4754718191415939;

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

    // Advanced Matching: window.SM_AM vem do partial
    // pixel-advanced-matching.php com a PII do cliente logado JÁ em
    // SHA-256 — os mesmos hashes que o CAPI manda, para a Meta casar
    // os dois lados. O partial só publica com consentimento de
    // marketing, então aqui não há gate extra a fazer.
    //
    // Valores hasheados são entregues como estão: o fbevents.js
    // reconhece SHA-256 (64 hex) e NÃO hasheia de novo — hash de hash
    // não casaria com nada.
    var am = window.SM_AM;
    if (am && typeof am === 'object' && Object.keys(am).length > 0) {
      fbq('init', window.META_PIXEL_ID, am);
    } else {
      fbq('init', window.META_PIXEL_ID);
    }

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

  // ── ID do PageView ────────────────────────────────────────────
  // Gerado UMA vez por carregamento e reaproveitado. A estabilidade é
  // o ponto: o pageView() pode ser chamado de novo quando o visitante
  // aceita o banner, e com IDs diferentes a Meta contaria duas visitas.
  // Com o mesmo ID ela colapsa as duas em uma.
  //
  // Diferente dos outros eventos, este ID nasce no NAVEGADOR: o
  // ConversionService não emite PageView, então não há um lado
  // servidor com quem casar. Serve para deduplicar repetições do
  // próprio navegador — não substitui o ID vindo do /beacon.
  var pageViewEventId = null;
  function idPageView() {
    if (pageViewEventId) return pageViewEventId;

    try {
      if (window.crypto && typeof window.crypto.randomUUID === 'function') {
        pageViewEventId = window.crypto.randomUUID();
        return pageViewEventId;
      }
      // randomUUID exige contexto seguro; getRandomValues é mais antigo
      if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
        var b = new Uint8Array(16);
        window.crypto.getRandomValues(b);
        b[6] = (b[6] & 0x0f) | 0x40; // versão 4
        b[8] = (b[8] & 0x3f) | 0x80; // variante RFC 4122
        var h = [];
        for (var i = 0; i < 16; i++) {
          h.push((b[i] + 0x100).toString(16).slice(1));
        }
        pageViewEventId = h[0]+h[1]+h[2]+h[3] + '-' + h[4]+h[5] + '-' +
                          h[6]+h[7] + '-' + h[8]+h[9] + '-' +
                          h[10]+h[11]+h[12]+h[13]+h[14]+h[15];
        return pageViewEventId;
      }
    } catch (e) { /* cai no fallback abaixo */ }

    // Último recurso (navegador sem crypto): não é criptográfico, mas
    // para deduplicar disparos da MESMA página basta ser único.
    pageViewEventId = 'pv-' + Date.now().toString(16) +
                      '-' + Math.random().toString(16).slice(2, 10);
    return pageViewEventId;
  }

  // ── Tipo da página ────────────────────────────────────────────
  // O event_id é opaco de propósito (é chave de dedup, não descrição).
  // Quem responde "de onde veio" é a URL, que a Meta já recebe sozinha
  // em todo evento. Este parâmetro existe para agrupar por TIPO sem
  // depender de casar string de URL — útil em Conversões
  // Personalizadas e em públicos ("quem viu qualquer página de moto").
  //
  // Os prefixos espelham config/routes.php. Mexeu na rota, revise aqui.
  function tipoDePagina() {
    var p = location.pathname;

    // A loja pode não estar na raiz do domínio (BASE_URL com subpasta);
    // sem descontar o prefixo, TODA página cairia em 'outra'.
    try {
      var base = new URL(window.BASE_URL || location.origin).pathname;
      if (base && base !== '/' && p.indexOf(base) === 0) {
        p = p.slice(base.length);
      }
    } catch (e) { /* BASE_URL ausente ou inválida: usa o path cru */ }

    if (p.charAt(0) !== '/') { p = '/' + p; }
    p = p.toLowerCase();

    if (p === '/')                        return 'home';
    if (p.indexOf('/produto/')   === 0)   return 'produto';
    if (p.indexOf('/categoria/') === 0)   return 'categoria';
    if (p.indexOf('/marca')      === 0)   return 'marca';   // /marca/{slug} e /marcas
    if (p.indexOf('/montadora/') === 0 ||
        p.indexOf('/motos')      === 0)   return 'moto';
    if (p.indexOf('/busca')      === 0)   return 'busca';
    if (p.indexOf('/carrinho')   === 0)   return 'carrinho';
    if (p.indexOf('/checkout')   === 0)   return 'checkout';
    if (p.indexOf('/minha-conta')=== 0)   return 'conta';
    if (p.indexOf('/clip')       === 0)   return 'clip';
    if (p.indexOf('/ajuda')      === 0)   return 'ajuda';
    return 'outra';
  }

  // ── PageView base (dispara ao carregar, se houver consentimento) ──
  function pageView() {
    // Passa pelo track() de propósito: mesmo gate de consentimento,
    // mesmo caminho de carregamento, eventID no 4º parâmetro.
    track('PageView', { page_type: tipoDePagina() }, idPageView());
  }

  // Expõe a API pública (as outras peças usam isto)
  window.smPixel = {
    track: track,
    pageView: pageView,
    tipoDePagina: tipoDePagina,   // exposto p/ conferir no console
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