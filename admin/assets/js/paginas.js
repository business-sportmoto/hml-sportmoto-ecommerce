/* =====================================================================
   Criador de páginas (jQuery v4).

   Dois blocos num arquivo, cada um guardado pelo seu elemento raiz:
     .pg_tabela  — a lista
     #pgForm     — o formulário

   O editor de texto rico é o rte.js; aqui só o resto do formulário.
   ===================================================================== */
(function ($) {
    'use strict';

    if (!window.PG) return;

    function aviso(msg, tipo) {
        if (window.adminToast) { adminToast(msg, tipo || 'info'); return; }
        if (window.Toast && Toast.show) { Toast.show({ message: msg, type: tipo || 'info' }); return; }
        alert(msg);
    }

    function post(url, dados) {
        return $.ajax({
            url: url,
            method: 'POST',
            dataType: 'json',
            data: $.extend({ _csrf_token: window.CSRF_TOKEN || '' }, dados || {}),
            headers: { 'X-CSRF-TOKEN': window.CSRF_TOKEN || '' }
        });
    }

    /* ═══════════════════════════════════════════════════════
       LISTA
       ═══════════════════════════════════════════════════════ */
    (function () {
        var $tabela = $('.pg_tabela');
        if (!$tabela.length) return;

        $tabela.on('click', '.js-pg-alternar', function () {
            var $tr = $(this).closest('tr');

            post(window.PG.base + '/alternar', { id: $tr.data('id') }).done(function (r) {
                if (!r || !r.ok) { aviso((r && r.msg) || 'Falha ao alterar.', 'error'); return; }
                aviso(r.msg, 'success');
                // Recarrega: publicar muda status, ações e o link "ver na loja"
                // — atualizar tudo na mão daria três estados para manter em dia.
                setTimeout(function () { window.location.reload(); }, 600);
            }).fail(function () { aviso('Falha de rede.', 'error'); });
        });

        $tabela.on('click', '.js-pg-excluir', function () {
            var $tr    = $(this).closest('tr');
            var titulo = $tr.find('.pg_titulo').text().trim();

            if (!window.confirm('Excluir “' + titulo + '”?\n\nQuem tiver o link vai receber 404.')) return;

            post(window.PG.base + '/excluir', { id: $tr.data('id') }).done(function (r) {
                if (!r || !r.ok) { aviso((r && r.msg) || 'Falha ao excluir.', 'error'); return; }
                $tr.fadeOut(200, function () { $(this).remove(); });
                aviso(r.msg, 'success');
            }).fail(function () { aviso('Falha de rede.', 'error'); });
        });
    })();

    /* ═══════════════════════════════════════════════════════
       FORMULÁRIO
       ═══════════════════════════════════════════════════════ */
    (function () {
        var $form = $('#pgForm');
        if (!$form.length) return;

        var $titulo = $('#pg-titulo');
        var $slug   = $('#pg-slug');

        /* ── slug ──────────────────────────────────────────
           Espelha o PaginaService::slugify(): acento vira a letra sem acento,
           não some. "Trocas e devoluções" tem que virar "trocas-e-devolucoes",
           e não "trocas-e-devolues". A normalização NFD separa a letra do
           acento e o range ̀-ͯ remove só o acento. */
        function slugify(txt) {
            return String(txt || '')
                .normalize('NFD').replace(/[̀-ͯ]/g, '')
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '');
        }

        // Só preenche sozinho enquanto o autor não mexeu no endereço: reescrever
        // o slug de uma página publicada quebraria os links já espalhados.
        var slugManual = !window.PG.novo || $slug.val().trim() !== '';

        $slug.on('input', function () { slugManual = true; });
        $titulo.on('input', function () {
            if (slugManual) return;
            $slug.val(slugify($titulo.val()));
        });
        $slug.on('blur', function () {
            var v = slugify($slug.val());
            if (v !== $slug.val()) $slug.val(v);
        });

        /* ── contador da meta description ─────────────────── */
        var $meta = $('#pg-meta-desc');
        var $cont = $('#pg-meta-contagem');
        function contar() { $cont.text(String($meta.val() || '').length); }
        $meta.on('input', contar);
        contar();

        /* ── excluir de dentro do formulário ──────────────── */
        $('.js-pg-excluir-form').on('click', function () {
            if (!window.confirm('Excluir esta página?\n\nQuem tiver o link vai receber 404.')) return;

            post(window.PG.base + '/excluir', { id: $(this).data('id') }).done(function (r) {
                if (!r || !r.ok) { aviso((r && r.msg) || 'Falha ao excluir.', 'error'); return; }
                window.location.href = window.PG.base;
            }).fail(function () { aviso('Falha de rede.', 'error'); });
        });

        /* ── salvar ───────────────────────────────────────── */
        $('#pgSalvar').on('click', function () {
            var $btn = $(this);

            if (!String($titulo.val() || '').trim()) {
                aviso('Dê um título para a página.', 'warning');
                $titulo.trigger('focus');
                return;
            }

            // O rte.js sincroniza no submit do formulário, e aqui não há submit:
            // o disparo manual é o que garante o conteúdo dentro do textarea.
            $form.trigger('submit');

            if (window.CK && CK.btnLoading) CK.btnLoading($btn);
            $btn.prop('disabled', true);

            $.ajax({
                url: window.PG.base + '/salvar',
                method: 'POST',
                dataType: 'json',
                data: $form.serialize(),
                headers: { 'X-CSRF-TOKEN': window.CSRF_TOKEN || '' }
            }).done(function (r) {
                if (!r || !r.ok) {
                    aviso((r && r.msg) || 'Falha ao salvar.', 'error');
                    return;
                }
                aviso(r.msg, 'success');

                // Página nova ganha id: sem o redirecionamento, salvar de novo
                // criaria uma segunda página em vez de atualizar a primeira.
                if (window.PG.novo && r.redirect) {
                    setTimeout(function () { window.location.href = r.redirect; }, 500);
                }
            }).fail(function () {
                aviso('Falha de rede ao salvar.', 'error');
            }).always(function () {
                if (window.CK && CK.btnLoading) CK.btnLoading($btn, false);
                $btn.prop('disabled', false);
            });
        });
    })();
})(jQuery);
