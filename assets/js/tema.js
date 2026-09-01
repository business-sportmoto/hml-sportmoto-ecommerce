/* =====================================================================
   Tema da loja — claro / escuro / sistema.

   Quem aplica o tema na carga é o script inline do <head>: ele roda antes do
   CSS pintar, senão a página piscaria no tema anterior a cada navegação.
   Aqui fica só o controle da página de conta.

   A escolha vive em localStorage, por navegador. Não é dado da conta: a mesma
   pessoa pode querer escuro no celular e claro no desktop, e sincronizar isso
   pelo servidor tiraria essa liberdade sem ganhar nada.

   "sistema" é a ausência de escolha, e por isso REMOVE o atributo em vez de
   gravar um terceiro valor — é o que devolve o controle ao prefers-color-scheme.
   ===================================================================== */
(function () {
    'use strict';

    var CHAVE = 'loja-tema';
    var VALIDOS = ['claro', 'escuro', 'sistema'];
    var raiz = document.documentElement;

    function ler() {
        try {
            var t = localStorage.getItem(CHAVE);
            return VALIDOS.indexOf(t) !== -1 ? t : 'sistema';
        } catch (e) {
            return 'sistema';   // modo privativo / storage bloqueado
        }
    }

    function aplicar(tema, persistir) {
        if (VALIDOS.indexOf(tema) === -1) tema = 'sistema';

        if (tema === 'claro')       raiz.setAttribute('data-theme', 'light');
        else if (tema === 'escuro') raiz.setAttribute('data-theme', 'dark');
        else                        raiz.removeAttribute('data-theme');

        if (persistir) {
            try { localStorage.setItem(CHAVE, tema); } catch (e) { /* sem persistência */ }
        }

        marcar(tema);
    }

    function marcar(tema) {
        var botoes = document.querySelectorAll('.tema-opt');
        for (var i = 0; i < botoes.length; i++) {
            var ativo = botoes[i].getAttribute('data-tema') === tema;
            botoes[i].setAttribute('aria-checked', ativo ? 'true' : 'false');
            // Só o selecionado fica no ciclo do Tab; as setas percorrem o grupo.
            botoes[i].tabIndex = ativo ? 0 : -1;
        }
    }

    function iniciar() {
        var grupo = document.querySelector('.tema-seg');
        if (!grupo) return;

        marcar(ler());

        grupo.addEventListener('click', function (ev) {
            var btn = ev.target.closest ? ev.target.closest('.tema-opt') : null;
            if (btn) aplicar(btn.getAttribute('data-tema'), true);
        });

        // Navegação por seta dentro do radiogroup, como manda o padrão ARIA.
        grupo.addEventListener('keydown', function (ev) {
            if (['ArrowRight', 'ArrowDown', 'ArrowLeft', 'ArrowUp'].indexOf(ev.key) === -1) return;
            ev.preventDefault();

            var botoes = Array.prototype.slice.call(grupo.querySelectorAll('.tema-opt'));
            var atual = botoes.indexOf(document.activeElement);
            if (atual === -1) atual = 0;

            var passo = (ev.key === 'ArrowRight' || ev.key === 'ArrowDown') ? 1 : -1;
            var proximo = botoes[(atual + passo + botoes.length) % botoes.length];

            proximo.focus();
            aplicar(proximo.getAttribute('data-tema'), true);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }

    // Outra aba mudou o tema: acompanha, para as duas não ficarem divergentes.
    window.addEventListener('storage', function (e) {
        if (e.key === CHAVE) aplicar(e.newValue, false);
    });
})();
