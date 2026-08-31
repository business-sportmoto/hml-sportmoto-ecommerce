/**
 * admin-core.js
 *
 * Utilitários globais do painel administrativo.
 * Incluir no layout admin ANTES de qualquer outro script.
 *
 * Requer:
 *   - jQuery
 *   - window.BASE_URL   (ex: "http://ecommerce.test")
 *   - window.CSRF_TOKEN (gerado pelo PHP no layout)
 *   - toast.js (opcional — CK.toast() funciona sem ele com fallback)
 *
 * No layout admin (views/admin/layout.php ou similar), adicionar:
 *
 *   <script>
 *     window.BASE_URL   = '<?= BASE_URL ?>';
 *     window.CSRF_TOKEN = '<?= SecurityHelper::csrfToken() ?>';
 *   </script>
 *   <script src="<?= BASE_URL ?>/assets/js/toast.js"></script>
 *   <script src="<?= BASE_URL ?>/assets/js/admin-core.js"></script>
 */
;(function ($, window) {
  'use strict';

  var BASE = BASE_URL   || '';
  var CSRF = CSRF_TOKEN || '';

  Toast.configure({
    position:  'bottom-center',  // padrão do site inteiro
    duration:   8000,
    maxVisible: 1,
  });
  
//   Toast.success('teste');

  // ════════════════════════════════════════════════════
  // CK — namespace de utilitários
  // ════════════════════════════════════════════════════
  var CK = {

    // ── AJAX ──────────────────────────────────────────

    /**
     * POST com CSRF automático.
     * Aceita array, objeto ou FormData.
     */
    post: function (url, data) {
      var payload;
      if (data instanceof FormData) {
        data.append('_csrf_token', CSRF);
        payload = data;
        return $.ajax({
          url:         url.indexOf('http') === 0 ? url : BASE + url,
          type:        'POST',
          data:        payload,
          dataType:    'json',
          processData: false,
          contentType: false,
        });
      }

      payload = $.extend({}, data || {}, { _csrf_token: CSRF });
      return $.ajax({
        url:      url.indexOf('http') === 0 ? url : BASE + url,
        type:     'POST',
        data:     payload,
        dataType: 'json',
      });
    },

    /**
     * GET simples.
     */
    get: function (url, params) {
      return $.ajax({
        url:      url.indexOf('http') === 0 ? url : BASE + url,
        type:     'GET',
        data:     params || {},
        dataType: 'json',
      });
    },

    // ── Notificações ───────────────────────────────────

    /**
     * Exibe toast. Usa Toast.js se disponível,
     * senão usa fallback nativo simples.
     */
    toast: function (msg, type, config) {
    
      type = type || 'info';
      if (Toast && typeof Toast.show === 'function') {
        Toast.show({ type: type, message: msg });
        return;
      }
      // Fallback: mini toast inline
      var color = { success: '#16a34a', error: '#dc2626', warning: '#d97706', info: '#2563eb' };
      var $t = $('<div>')
        .text(msg)
        .css({
          position: 'fixed', top: '20px', right: '20px', zIndex: 99999,
          background: color[type] || '#1e293b', color: '#fff',
          padding: '12px 20px', borderRadius: '10px',
          fontSize: '13.5px', fontWeight: '600',
          boxShadow: '0 4px 16px rgba(0,0,0,.15)',
          maxWidth: '360px', wordBreak: 'break-word',
          opacity: 0, transition: 'opacity .25s ease',
        });
      $('body').append($t);
      setTimeout(function () { $t.css('opacity', 1); }, 20);
      setTimeout(function () {
        $t.css('opacity', 0);
        setTimeout(function () { $t.remove(); }, 300);
      }, 3500);
    },

    // ── Botão loading ──────────────────────────────────

    /**
     * Ativa/desativa estado de loading em um botão.
     * Preserva o HTML original para restaurar depois.
     */
    btnLoading: function ($btn, on) {
      if (on === undefined) on = true;
      if (!$btn || !$btn.length) return;

      if (on) {
        var original = $btn.html();
        $btn.data('ck-original', original)
            .prop('disabled', true)
            .addClass('is-loading')
            .html(
              '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"' +
              ' stroke-width="2.5" stroke-linecap="round"' +
              ' style="animation:ck-spin .8s linear infinite;vertical-align:middle;margin-right:6px">' +
              '<path d="M21 12a9 9 0 11-6.219-8.56"/></svg>' +
              'Aguarde…'
            );
      } else {
        var orig = $btn.data('ck-original');
        $btn.prop('disabled', false)
            .removeClass('is-loading');
        if (orig) $btn.html(orig);
      }
    },

    // ── Alertas inline ─────────────────────────────────

    /**
     * Exibe mensagem de erro/sucesso em um elemento.
     */
    formAlertSet: function ($el, msg, type) {
      if (!$el || !$el.length) return;
      type = type || 'error';
      $el.removeClass('form-alert--error form-alert--success')
         .addClass(type === 'success' ? 'form-alert--success' : 'form-alert--error')
         .html(msg)
         .show();
    },

    formAlertClear: function ($el) {
      if ($el && $el.length) $el.hide().empty();
    },

    // ── Máscara de moeda ───────────────────────────────

    maskMoeda: function ($input) {
      var v = $input.val().replace(/\D/g, '');
      v = (parseInt(v, 10) / 100).toFixed(2);
      $input.val(v.replace('.', ','));
    },

    // ── Confirmação simples ────────────────────────────

    confirm: function (msg, onConfirm) {
      if (window.confirm(msg)) onConfirm();
    },

    // ── Redirect ───────────────────────────────────────

    redirect: function (url) {
      window.location.href = url.indexOf('http') === 0 ? url : BASE + url;
    },
  };

  // ── Keyframe do spin (injeta uma vez) ────────────────
  if (!document.getElementById('ck-keyframes')) {
    var style = document.createElement('style');
    style.id  = 'ck-keyframes';
    style.textContent = '@keyframes ck-spin { to { transform: rotate(360deg); } }';
    document.head.appendChild(style);
  }

  // ── Bindings globais ─────────────────────────────────
  $(function () {

    // Toggle senha
    $(document).on('click', '.toggle-password', function () {
      var id   = $(this).data('target');
      var $inp = $('#' + id);
      $inp.attr('type', $inp.attr('type') === 'password' ? 'text' : 'password');
    });

    // Confirmar antes de deletar (data-confirm)
    $(document).on('click', '[data-confirm]', function (e) {
      var msg = $(this).data('confirm') || 'Tem certeza?';
      if (!window.confirm(msg)) e.preventDefault();
    });

    // Fechar alertas inline ao clicar no X
    $(document).on('click', '.alert-close', function () {
      $(this).closest('.form-alert').hide();
    });
  });

  // ── Expõe globalmente ─────────────────────────────────
  window.CK = CK;

}(jQuery, window));


// POST com CSRF automático
// CK.post('/admin/cupons/salvar', { nome: 'Promo' }).done(res => { ... });

// // GET
// CK.get('/admin/cupons/dados', { id: 5 }).done(res => { ... });

// // Toast
// CK.toast('Salvo com sucesso!', 'success');  // success | error | warning | info
// CK.toast('Erro ao salvar.',    'error');

// // Botão loading
// CK.btnLoading($('#btn-salvar'));        // ativa
// CK.btnLoading($('#btn-salvar'), false); // restaura

// // Alerta inline
// CK.formAlertSet($('#form-error'), 'Campo obrigatório.');
// CK.formAlertClear($('#form-error'));

// // Redirect
// CK.redirect('/admin/cupons');


/* ===========================================================================
   Tema claro/escuro do painel.

   O tema e aplicado por <html data-theme="dark|light">. Quem grava o atributo
   na carga e o script inline do <head> — ele roda antes do CSS pintar, senao a
   pagina piscaria no tema anterior a cada navegacao. Aqui so fica o botao.

   A escolha vive em localStorage (por navegador, nao por usuario). Padrao do
   painel: escuro, que era o unico tema existente antes do seletor.
   =========================================================================== */
(function () {
    'use strict';

    var CHAVE = 'admin-tema';
    var raiz = document.documentElement;

    function lerPreferencia() {
        try {
            var t = localStorage.getItem(CHAVE);
            return (t === 'claro' || t === 'escuro') ? t : 'escuro';
        } catch (e) {
            return 'escuro';   // modo privativo / storage bloqueado
        }
    }

    function aplicar(tema) {
        raiz.setAttribute('data-theme', tema === 'escuro' ? 'dark' : 'light');
        try { localStorage.setItem(CHAVE, tema); } catch (e) { /* sem persistencia */ }

        var btn = document.getElementById('adminTemaBtn');
        if (!btn) return;
        var escuro = tema === 'escuro';
        btn.setAttribute('aria-pressed', escuro ? 'true' : 'false');
        btn.setAttribute('title', escuro ? 'Mudar para tema claro' : 'Mudar para tema escuro');
        btn.setAttribute('aria-label', escuro ? 'Mudar para tema claro' : 'Mudar para tema escuro');
    }

    document.addEventListener('DOMContentLoaded', function () {
        var btn = document.getElementById('adminTemaBtn');
        if (!btn) return;

        aplicar(lerPreferencia());   // sincroniza o rotulo do botao com o que o <head> ja aplicou

        btn.addEventListener('click', function () {
            aplicar(lerPreferencia() === 'escuro' ? 'claro' : 'escuro');
        });
    });

    // Outra aba mudou o tema: acompanha, para nao ficarem divergentes.
    window.addEventListener('storage', function (e) {
        if (e.key === CHAVE && (e.newValue === 'claro' || e.newValue === 'escuro')) {
            aplicar(e.newValue);
        }
    });
})();
