/* =====================================================================
   Editor de texto rico do painel.

   Inicializa TODOS os .pe-rte da página, cada um ligado ao <textarea> que o
   data-target aponta. O textarea continua sendo o campo que o formulário
   envia; a área editável só escreve nele.

   ── Sobre a segurança ────────────────────────────────────────────────
   Nada aqui protege contra XSS, e não é para proteger. A limpeza de verdade
   é o HtmlHelper::sanitizeRich() (HTML Purifier) no servidor, na hora de
   gravar — quem quiser atacar manda POST direto e nem passa por este arquivo.
   O tratamento de colagem abaixo é higiene visual: evita herdar o HTML sujo
   do Word e do Google Docs.

   ── Sobre execCommand ────────────────────────────────────────────────
   Está marcado como obsoleto, mas continua sendo o que todos os navegadores
   implementam para edição em contenteditable, e não existe substituto padrão.
   O editor do formulário de produto já usava isto; manter os dois iguais vale
   mais do que trocar por uma abordagem própria e diferente.
   ===================================================================== */
(function () {
    'use strict';

    function iniciar(wrap) {
        var ta   = document.getElementById(wrap.dataset.target);
        var area = wrap.querySelector('.pe-rte-area');
        if (!ta || !area) return;

        // O textarea vira o depósito do valor; quem aparece é a área.
        ta.classList.add('pe-rte-oculto');
        area.innerHTML = ta.value || '';

        function sync() { ta.value = area.innerHTML; }

        area.addEventListener('input', sync);
        area.addEventListener('blur', sync);
        // Garante o sync no submit mesmo sem blur (Enter no formulário).
        if (ta.form) ta.form.addEventListener('submit', sync);

        function comando(btn) {
            var cmd = btn.dataset.cmd;
            area.focus();

            if (cmd === 'createLink') {
                var url = window.prompt('URL do link (https://…):', 'https://');
                if (!url) return;
                // javascript: num href é XSS clicável. A limpeza do servidor
                // também barra, mas rejeitar aqui evita o link quebrado
                // aparecer no editor e o autor achar que salvou.
                if (!/^(https?:|mailto:|tel:|\/)/i.test(url)) {
                    window.alert('Link inválido. Use http://, https://, mailto:, tel: ou /caminho.');
                    return;
                }
                document.execCommand('createLink', false, url);
                return;
            }

            if (cmd === 'formatBlock') {
                document.execCommand('formatBlock', false, btn.dataset.val);
                return;
            }

            document.execCommand(cmd, false, null);
        }

        wrap.querySelectorAll('.pe-rte-btn').forEach(function (btn) {
            // mousedown, não click: o click só chega depois de a área perder o
            // foco, e aí a seleção do texto já sumiu — o comando cairia no vazio.
            btn.addEventListener('mousedown', function (ev) { ev.preventDefault(); });
            btn.addEventListener('click', function () {
                comando(btn);
                sync();
                estado();
            });
        });

        // Cola como texto puro: HTML de Word/Docs traz <span style> em cada
        // palavra, e o Purifier tira tudo depois — o autor veria o texto mudar
        // sozinho ao salvar.
        area.addEventListener('paste', function (ev) {
            ev.preventDefault();
            var txt = (ev.clipboardData || window.clipboardData).getData('text/plain');
            document.execCommand('insertText', false, txt);
            sync();
        });

        function estado() {
            [['bold', 'bold'], ['italic', 'italic'], ['underline', 'underline']].forEach(function (par) {
                var b = wrap.querySelector('.pe-rte-btn[data-cmd="' + par[0] + '"]');
                if (!b) return;
                try { b.classList.toggle('is-active', document.queryCommandState(par[1])); }
                catch (e) { /* navegador sem queryCommandState para o comando */ }
            });
        }

        area.addEventListener('keyup', estado);
        area.addEventListener('mouseup', estado);
        area.addEventListener('focus', estado);
    }

    function iniciarTodos() {
        document.querySelectorAll('.pe-rte[data-target]').forEach(iniciar);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciarTodos);
    } else {
        iniciarTodos();
    }
})();
