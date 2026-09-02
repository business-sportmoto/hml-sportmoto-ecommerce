/* =====================================================================
   Newsletter do rodapé — e-mail, código e cupom.

   Fluxo:
     rodapé   → POST /newsletter            → abre a modal
     modal    → POST /newsletter/confirmar  → mostra o cupom

   O handler antigo vivia no main.js apontando para #footer-newsletter-form,
   id que sumiu quando o rodapé foi refeito. Aqui o seletor é o do formulário
   que existe hoje.
   ===================================================================== */
(function ($) {
    'use strict';

    var $form = $('#smf_newsletter_form');
    if (!$form.length) return;

    var $modal   = $('#modal-newsletter');
    var $msg     = $('#smf_newsletter_msg');
    var $email   = $form.find('input[name="email"]');
    var emailAtual = '';

    var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

    function token() {
        return $form.find('input[name="_csrf_token"]').val() || window.CSRF_TOKEN || '';
    }

    function recado(texto, tipo) {
        $msg.text(texto || '')
            .removeClass('msg-ok msg-error')
            .addClass(tipo === 'ok' ? 'msg-ok' : (tipo ? 'msg-error' : ''));
    }

    function erroModal(texto) {
        var $e = $('#nlm-erro');
        if (!texto) { $e.prop('hidden', true).text(''); return; }
        $e.text(texto).prop('hidden', false);
    }

    /* ── modal ──────────────────────────────────────────── */
    function abrir() {
        $modal.css('display', 'flex');
        // Trava o scroll de fundo: sem isso a página rola atrás da modal e o
        // usuário perde a referência de onde estava.
        $('body').addClass('nlm-aberta');
        setTimeout(function () { $('#nlm-nome').trigger('focus'); }, 60);
    }

    function fechar() {
        $modal.css('display', 'none');
        $('body').removeClass('nlm-aberta');
        erroModal('');
    }

    $('#nlm-fechar').on('click', fechar);
    $modal.on('click', function (ev) { if (ev.target === this) fechar(); });
    $(document).on('keydown', function (ev) {
        if (ev.key === 'Escape' && $modal.is(':visible')) fechar();
    });

    /* ── etapa 1: pedir o código ────────────────────────── */
    function pedirCodigo(reenvio) {
        var email = String($email.val() || '').trim();

        if (!EMAIL_RE.test(email)) {
            recado('Digite um e-mail válido.', 'erro');
            $email.trigger('focus');
            return;
        }

        recado(reenvio ? 'Reenviando…' : 'Enviando código…');
        if (reenvio) erroModal('');

        $.ajax({
            url: window.BASE_URL + '/newsletter',
            method: 'POST',
            dataType: 'json',
            data: { email: email, _csrf_token: token() }
        }).done(function (r) {
            if (!r || !r.ok) {
                var m = (r && r.msg) || 'Não foi possível enviar o código.';
                recado(m, 'erro');
                if (reenvio) erroModal(m);
                return;
            }

            emailAtual = r.email || email;

            // Quem já é assinante não passa pela etapa do código: mandar um
            // código para depois dizer "você já estava inscrito" é fazer a
            // pessoa trabalhar para receber uma recusa.
            if (r.etapa === 'ja_inscrito') {
                recado(r.msg, 'ok');
                if (r.cupom) { mostrarCupom(r.cupom, ''); abrir(); }
                return;
            }

            recado(r.msg, 'ok');
            $('#nlm-email').text(mascarar(emailAtual));
            $('#nlm-minutos').text(r.minutos || 15);
            $('#nlm-etapa-codigo').prop('hidden', false);
            $('#nlm-etapa-cupom').prop('hidden', true);
            abrir();
        }).fail(function () {
            recado('Falha de rede. Tente de novo.', 'erro');
        });
    }

    $form.on('submit', function (ev) {
        ev.preventDefault();
        pedirCodigo(false);
    });

    $('#nlm-reenviar').on('click', function () { pedirCodigo(true); });

    /* ── etapa 2: confirmar ─────────────────────────────── */
    function confirmar() {
        var nome   = String($('#nlm-nome').val() || '').trim();
        var codigo = String($('#nlm-codigo').val() || '').replace(/\D/g, '');

        if (!nome)              { erroModal('Informe seu nome.'); $('#nlm-nome').trigger('focus'); return; }
        if (codigo.length !== 6) { erroModal('O código tem 6 dígitos.'); $('#nlm-codigo').trigger('focus'); return; }

        erroModal('');
        var $btn = $('#nlm-confirmar');
        $btn.prop('disabled', true).text('Confirmando…');

        $.ajax({
            url: window.BASE_URL + '/newsletter/confirmar',
            method: 'POST',
            dataType: 'json',
            data: { email: emailAtual, nome: nome, codigo: codigo, _csrf_token: token() }
        }).done(function (r) {
            if (!r || !r.ok) { erroModal((r && r.msg) || 'Não foi possível confirmar.'); return; }

            mostrarCupom(r.cupom, r.nome || nome);
            recado('Inscrição confirmada!', 'ok');
            $email.val('');
        }).fail(function () {
            erroModal('Falha de rede. Tente de novo.');
        }).always(function () {
            $btn.prop('disabled', false).text('Confirmar e receber cupom');
        });
    }

    $('#nlm-confirmar').on('click', confirmar);
    $('#nlm-codigo').on('keydown', function (ev) { if (ev.key === 'Enter') confirmar(); });
    $('#nlm-nome').on('keydown', function (ev) { if (ev.key === 'Enter') $('#nlm-codigo').trigger('focus'); });

    // Só dígitos no campo do código — colar "123 456" não pode virar erro.
    $('#nlm-codigo').on('input', function () {
        this.value = this.value.replace(/\D/g, '').slice(0, 6);
    });

    /* ── cupom ──────────────────────────────────────────── */
    function mostrarCupom(cupom, nome) {
        $('#nlm-etapa-codigo').prop('hidden', true);
        $('#nlm-etapa-cupom').prop('hidden', false);
        $('#nlm-nome-ok').text(nome ? nome.split(' ')[0] : 'tudo certo');

        if (!cupom || !cupom.codigo) {
            // Cupom desligado nas configurações: a inscrição vale, e prometer
            // desconto que não existe seria pior do que não mencionar.
            $('#nlm-cupom-bloco').hide();
            $('#nlm-cupom-regra').text('');
            $('#nlm-cupom-texto').text('Sua inscrição está confirmada. Obrigado!');
            return;
        }

        $('#nlm-cupom-bloco').show();
        $('#nlm-cupom-codigo').text(cupom.codigo);
        $('#nlm-cupom-texto').text('Use este cupom e ganhe ' + (cupom.descricao || 'seu desconto') + '.');

        var regras = [];
        if (cupom.minimo && cupom.minimo > 0) {
            regras.push('Pedido mínimo de R$ ' + Number(cupom.minimo).toFixed(2).replace('.', ','));
        }
        if (cupom.validade) regras.push('Válido até ' + cupom.validade);
        regras.push('Uso único');
        $('#nlm-cupom-regra').text(regras.join(' · '));
    }

    $('#nlm-copiar').on('click', function () {
        var codigo = $('#nlm-cupom-codigo').text().trim();
        var $btn = $(this);

        var feito = function () {
            $btn.text('Copiado!');
            setTimeout(function () { $btn.text('Copiar'); }, 1800);
        };

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(codigo).then(feito).catch(function () { manual(codigo, feito); });
        } else {
            manual(codigo, feito);
        }
    });

    // A Clipboard API exige contexto seguro; em http a cópia precisa do
    // caminho antigo, senão o botão não faz nada e parece quebrado.
    function manual(texto, feito) {
        var ta = document.createElement('textarea');
        ta.value = texto;
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); feito(); } catch (e) { /* sem cópia */ }
        document.body.removeChild(ta);
    }

    $('#nlm-comprar').on('click', function () { fechar(); });

    /* ── util ───────────────────────────────────────────── */
    function mascarar(email) {
        var p = String(email).split('@');
        if (p.length !== 2) return email;
        return p[0].charAt(0) + new Array(Math.max(4, p[0].length)).join('*') + '@' + p[1];
    }
})(jQuery);
