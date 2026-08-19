<?php
// ════════════════════════════════════════════════════════
// BANNER DE CONSENTIMENTO (CMP custom) — v2 visual
// Banner escuro + modal com toggles (3 categorias).
// Mesma funcionalidade da versão anterior:
//  - Front: dispara os 4 sinais do Consent Mode v2 (Google)
//  - Back:  POST /consent/salvar → ConsentService::registrar()
// Incluir no layout (main.php), antes de </body>.
// ════════════════════════════════════════════════════════
?>
<!-- ── BANNER (barra inferior escura) ── -->
<div id="cookie-banner" class="ckb" role="dialog" aria-live="polite"
     aria-label="Aviso de privacidade e cookies">
  <div class="ckb__inner">
    <p class="ckb__text">
      Usamos cookies para melhorar sua experiência, personalizar anúncios e
      analisar nosso tráfego. Você pode aceitar todos os cookies ou ajustar
      suas preferências. Consulte nossa
      <a href="<?= BASE_URL ?>/politica-de-privacidade" class="ckb__link">Política de Privacidade</a>.
    </p>
    <div class="ckb__actions">
      <button type="button" class="ckb__btn ckb__btn--ghost" id="ck-config">Configurar cookies</button>
      <button type="button" class="ckb__btn ckb__btn--solid" id="ck-accept-all">Aceitar e continuar</button>
    </div>
  </div>
</div>

<!-- ── MODAL (configuração granular) ── -->
<div id="cookie-modal" class="ckm" role="dialog" aria-modal="true"
     aria-labelledby="ckm-title">
  <div class="ckm__overlay" data-ckm-close></div>
  <div class="ckm__box" role="document">
    <h2 id="ckm-title" class="ckm__title">Configurar cookies</h2>

    <div class="ckm__intro">
      Os cookies são utilizados para entender como você navega e melhorar sua
      experiência no site. Com essas informações, aprimoramos o funcionamento
      da loja e mostramos promoções de acordo com seus interesses.
      <a href="<?= BASE_URL ?>/politica-de-privacidade" class="ckm__link">Saiba mais na nossa Política de Privacidade.</a>
    </div>

    <!-- Essenciais (travado) -->
    <div class="ckm__cat">
      <div class="ckm__cat-head">
        <span class="ckm__cat-name">Cookies essenciais</span>
        <label class="ckt ckt--on ckt--locked">
          <input type="checkbox" checked disabled>
          <span class="ckt__slider"></span>
        </label>
      </div>
      <p class="ckm__cat-desc">
        São usados para reconhecer você, salvar preferências de configuração e
        proteger sua conta. Não podem ser desativados, pois são necessários
        para o funcionamento do site.
      </p>
    </div>

    <!-- Analíticos -->
    <div class="ckm__cat">
      <div class="ckm__cat-head">
        <span class="ckm__cat-name">Cookies analíticos</span>
        <label class="ckt">
          <input type="checkbox" id="ck-analytics">
          <span class="ckt__slider"></span>
        </label>
      </div>
      <p class="ckm__cat-desc">
        Permitem analisar a navegação e desempenho do site. Se você desativá-los,
        não poderemos melhorar sua experiência.
      </p>
    </div>

    <!-- Marketing / Publicidade -->
    <div class="ckm__cat">
      <div class="ckm__cat-head">
        <span class="ckm__cat-name">Cookies de publicidade personalizada</span>
        <label class="ckt">
          <input type="checkbox" id="ck-marketing">
          <span class="ckt__slider"></span>
        </label>
      </div>
      <p class="ckm__cat-desc">
        Permitem exibir anúncios e promoções relevantes. Se você desativá-los,
        verá conteúdos genéricos.
      </p>
    </div>

    <div class="ckm__foot">
      <button type="button" class="ckb__btn ckb__btn--solid" id="ck-save">Salvar</button>
      <button type="button" class="ckm__cancel" data-ckm-close>Cancelar</button>
      <button type="button" class="ckm__reject" id="ck-reject">Excluir cookies não essenciais</button>
    </div>
  </div>
</div>

<style>
/* ── BANNER escuro ── */
.ckb{position:fixed;left:0;right:0;bottom:0;z-index:99998;
  background:#141414;border-top:3px solid var(--c-primary);
  box-shadow:0 -6px 24px rgba(0,0,0,.3);display:none}
.ckb.is-open{display:block}
.ckb__inner{max-width:1200px;margin:0 auto;padding:18px 24px;
  display:flex;align-items:center;gap:24px;justify-content:space-between}
.ckb__text{margin:0;font-size:13.5px;line-height:1.6;color:#d4d4d4;max-width:640px}
.ckb__link{color:var(--c-primary);text-decoration:underline;font-weight:500}
.ckb__actions{display:flex;gap:12px;flex-shrink:0}
.ckb__btn{padding:12px 22px;border-radius:4px;font-size:13px;font-weight:700;
  cursor:pointer;border:none;white-space:nowrap;transition:all .15s;
  text-transform:uppercase;letter-spacing:.3px}
.ckb__btn--ghost{background:#3a3a3a;color:#fff}
.ckb__btn--ghost:hover{background:#4a4a4a}
.ckb__btn--solid{background:var(--c-primary);color:#fff}
.ckb__btn--solid:hover{background:var(--c-primary-d)}
.ckb__btn:focus-visible{outline:2px solid #fff;outline-offset:2px}

/* ── MODAL ── */
.ckm{position:fixed;inset:0;z-index:99999;display:none;align-items:center;
  justify-content:center;padding:20px}
.ckm.is-open{display:flex}
.ckm__overlay{position:absolute;inset:0;background:rgba(15,15,15,.6);
  backdrop-filter:blur(2px)}
.ckm__box{position:relative;background:#f7f8fa;border-radius:12px;
  max-width:640px;width:100%;max-height:90vh;overflow-y:auto;
  padding:28px 30px;box-shadow:0 24px 60px rgba(0,0,0,.35);
  animation:ckmIn .3s cubic-bezier(.16,1,.3,1)}
@keyframes ckmIn{from{opacity:0;transform:translateY(16px) scale(.98)}to{opacity:1;transform:none}}
.ckm__title{margin:0 0 6px;font-size:22px;font-weight:800;color:#1a1a1a;
  padding-bottom:14px;border-bottom:2px solid var(--c-primary)}
.ckm__intro{background:#eef1f5;border-left:4px solid var(--c-primary);border-radius:6px;
  padding:14px 16px;font-size:13px;line-height:1.6;color:#4a5568;margin:18px 0}
.ckm__link{color:var(--c-primary);text-decoration:underline;font-weight:600}
.ckm__cat{background:#fff;border:1px solid #e8ebf0;border-radius:10px;
  padding:16px 18px;margin-bottom:12px;box-shadow:0 1px 3px rgba(0,0,0,.04)}
.ckm__cat-head{display:flex;align-items:center;justify-content:space-between;gap:16px}
.ckm__cat-name{font-size:15px;font-weight:700;color:#1a1a1a}
.ckm__cat-desc{margin:8px 0 0;font-size:12.5px;line-height:1.55;color:#718096}

/* ── TOGGLE ── */
.ckt{position:relative;display:inline-block;width:46px;height:26px;flex-shrink:0;cursor:pointer}
.ckt input{opacity:0;width:0;height:0;position:absolute}
.ckt__slider{position:absolute;inset:0;background:#cbd5e0;border-radius:26px;transition:.25s}
.ckt__slider::before{content:"";position:absolute;height:20px;width:20px;left:3px;top:3px;
  background:#fff;border-radius:50%;transition:.25s;box-shadow:0 1px 3px rgba(0,0,0,.3)}
.ckt input:checked + .ckt__slider{background:var(--c-primary)}
.ckt input:checked + .ckt__slider::before{transform:translateX(20px)}
.ckt--locked .ckt__slider{background:var(--c-primary);opacity:.85;cursor:not-allowed}
.ckt input:focus-visible + .ckt__slider{outline:2px solid #2563eb;outline-offset:2px}

.ckm__foot{display:flex;align-items:center;gap:14px;margin-top:20px;flex-wrap:wrap}
.ckm__cancel{background:none;border:none;color:var(--c-primary);font-size:14px;
  font-weight:600;cursor:pointer;padding:8px 4px}
.ckm__cancel:hover{color:#1a1a1a}
.ckm__reject{margin-left:auto;background:#fff;border:1px solid var(--c-primary);
  color:var(--c-primary);padding:11px 18px;border-radius:6px;font-size:13px;
  font-weight:700;cursor:pointer;transition:all .15s}
.ckm__reject:hover{background:#fef2f2}

@media (max-width:680px){
  .ckb__inner{flex-direction:column;align-items:stretch;gap:14px}
  .ckb__actions{flex-direction:column}
  .ckb__btn{width:100%}
  .ckm__box{padding:22px 18px}
  .ckm__foot{flex-direction:column;align-items:stretch}
  .ckm__reject{margin-left:0;order:-1}
}
@media (prefers-reduced-motion:reduce){.ckm__box{animation:none}}
</style>

<script>
(function () {
  var banner = document.getElementById('cookie-banner');
  var modal  = document.getElementById('cookie-modal');
  if (!banner || !modal) return;

  function lerConsent() {
    var m = document.cookie.match(/(?:^|;\s*)sm_consent=([^;]+)/);
    if (!m) return null;
    try { return JSON.parse(decodeURIComponent(m[1])); } catch (e) { return null; }
  }

  // Dispara os 4 sinais do Consent Mode v2 pro Google
  function aplicarConsentMode(analytics, marketing) {
    if (typeof gtag !== 'function') return;
    gtag('consent', 'update', {
      'analytics_storage':  analytics ? 'granted' : 'denied',
      'ad_storage':         marketing ? 'granted' : 'denied',
      'ad_user_data':       marketing ? 'granted' : 'denied',
      'ad_personalization': marketing ? 'granted' : 'denied'
    });
  }

  // Já decidiu? Aplica e não mostra nada
  var atual = lerConsent();
  if (atual && typeof atual.a !== 'undefined') {
    aplicarConsentMode(!!atual.a, !!atual.m);
    return;
  }
  banner.classList.add("is-open");

  // Envia a escolha pro back (evidência LGPD + cookie)
  function salvar(analytics, marketing, acao) {
    var fd = new FormData();
    fd.append('analytics', analytics ? '1' : '0');
    fd.append('marketing', marketing ? '1' : '0');
    fd.append('acao', acao);
    fd.append('_csrf_token', (CSRF_TOKEN || ''));
    aplicarConsentMode(analytics, marketing);
    fetch(BASE_URL + '/consent/salvar', {
      method: 'POST', body: fd, credentials: 'same-origin'
    }).finally(function () {
      banner.classList.remove("is-open");
      modal.classList.remove("is-open");
    });
  }

  function abrirModal() {
    // Reflete o estado atual (se houver) nos toggles
    var a = document.getElementById('ck-analytics');
    var m = document.getElementById('ck-marketing');
    if (atual) { a.checked = !!atual.a; m.checked = !!atual.m; }
    modal.classList.add("is-open");
  }
  function fecharModal() { modal.classList.remove("is-open"); }

  // ── Banner ──
  document.getElementById('ck-accept-all').addEventListener('click', function () {
    salvar(true, true, 'aceitou_tudo');
  });
  document.getElementById('ck-config').addEventListener('click', abrirModal);

  // ── Modal ──
  document.getElementById('ck-save').addEventListener('click', function () {
    salvar(
      document.getElementById('ck-analytics').checked,
      document.getElementById('ck-marketing').checked,
      'personalizado'
    );
  });
  document.getElementById('ck-reject').addEventListener('click', function () {
    salvar(false, false, 'recusou_tudo');
  });
  modal.querySelectorAll('[data-ckm-close]').forEach(function (el) {
    el.addEventListener('click', fecharModal);
  });
  // Esc fecha o modal
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && modal.classList.contains('is-open')) fecharModal();
  });
})();
</script>