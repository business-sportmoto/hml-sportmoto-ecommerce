/**
 * admin/assets/js/var-chips.js
 *
 * Transforma um <textarea> num editor onde as variáveis viram fichas atômicas.
 *
 *   $('#meu-textarea').varChips({
 *     variaveis: { '{nome}': 'Nome do cliente', '{link}': 'Link do carrinho' }
 *   });
 *
 * O TEXTAREA CONTINUA SENDO O CAMPO. O editor é uma camada por cima; a cada
 * tecla o conteúdo é serializado de volta para o textarea e um evento `input`
 * é disparado nele. Quem já escutava o textarea — validação, contador, prévia —
 * continua funcionando sem saber que o plugin existe. É por isso que o textarea
 * não é substituído: substituí-lo obrigaria a reescrever tudo em volta.
 *
 * Variável desconhecida NÃO vira ficha: `{nome_do_cliete}` fica como texto cru,
 * visível e feio de propósito. Se o typo virasse ficha, o erro só apareceria na
 * mensagem que o cliente recebe.
 *
 * API:
 *   $(t).varChips(opts)            inicia
 *   $(t).varChips('inserir', '{x}') insere no cursor
 *   $(t).varChips('sincronizar')   relê o textarea (depois de mudar por código)
 *   $(t).varChips('destruir')      remove a camada e devolve o textarea
 *
 * Opções: variaveis, placeholder, altura, aoMudar
 *
 * jQuery 4: sem $.trim, sem $.isFunction, sem métodos de evento abreviados.
 */
(function ($) {
  'use strict';

  var CSS_ID = 'var-chips-estilo';
  var DADOS  = 'varChipsInst';

  function injetarEstilo() {
    if (document.getElementById(CSS_ID)) return;
    var css =
      '.vc-editor{min-height:180px;max-height:520px;overflow-y:auto;padding:11px 13px;' +
        'border:1px solid var(--c-border,#d7dbe0);border-radius:9px;background:var(--surface,#fff);' +
        'font:13px/1.65 ui-monospace,SFMono-Regular,Menlo,monospace;color:var(--c-text,#1e293b);' +
        'white-space:pre-wrap;word-break:break-word;outline:none;}' +
      '.vc-editor:focus{border-color:var(--c-primary,#2563eb);' +
        'box-shadow:0 0 0 3px rgba(37,99,235,.12);}' +
      '.vc-editor:empty::before{content:attr(data-placeholder);color:var(--c-text-muted,#94a3b8);}' +
      '.vc-chip{display:inline-flex;align-items:center;gap:5px;vertical-align:baseline;' +
        'background:var(--blue-lt,#eff6ff);border:1px solid #bfdbfe;color:#1d4ed8;' +
        'border-radius:99px;padding:1px 7px 1px 10px;margin:0 1px;' +
        'font:600 11.5px/1.7 system-ui,-apple-system,Segoe UI,sans-serif;' +
        'user-select:none;white-space:nowrap;}' +
      '.vc-chip[data-sel="1"]{background:#dbeafe;border-color:#60a5fa;' +
        'box-shadow:0 0 0 2px rgba(37,99,235,.25);}' +
      '.vc-x{cursor:pointer;opacity:.55;font-size:13px;line-height:1;padding:0 1px;}' +
      '.vc-x:hover{opacity:1;color:#b91c1c;}' +
      '@media (prefers-reduced-motion:no-preference){' +
        '.vc-chip{transition:background .12s ease,box-shadow .12s ease;}}';
    $('<style>').attr('id', CSS_ID).text(css).appendTo('head');
  }

  // ── Serialização ──────────────────────────────────────────────────────────

  /** Editor → texto. As fichas voltam a ser {variavel}. */
  function paraTexto(raiz) {
    var out = '';

    (function anda(no) {
      for (var i = 0; i < no.childNodes.length; i++) {
        var n = no.childNodes[i];

        if (n.nodeType === 3) {                       // texto
          out += n.nodeValue;
        } else if (n.nodeType === 1) {
          var tag = n.nodeName.toLowerCase();
          if (n.classList && n.classList.contains('vc-chip')) {
            out += n.getAttribute('data-var') || '';
          } else if (tag === 'br') {
            out += '\n';
          } else {
            // O navegador embrulha linha em <div>/<p> ao teclar Enter. Cada
            // bloco novo é uma quebra — menos o primeiro, que continua a linha
            // corrente e ganharia um \n a mais.
            if ((tag === 'div' || tag === 'p') && out !== '' && !/\n$/.test(out)) out += '\n';
            anda(n);
          }
        }
      }
    })(raiz);

    return out.replace(/ /g, ' ');   // nbsp que o contenteditable insere
  }

  /** Texto → editor. Só o que está no dicionário vira ficha. */
  function paraNos(texto, variaveis) {
    var frag  = document.createDocumentFragment();
    var re    = /\{[a-z_]+\}/g;
    var pos   = 0;
    var achou;

    function escreverTexto(t) {
      // Quebra de linha vira <br>: é o que o contenteditable entende
      var partes = t.split('\n');
      for (var i = 0; i < partes.length; i++) {
        if (i > 0) frag.appendChild(document.createElement('br'));
        if (partes[i] !== '') frag.appendChild(document.createTextNode(partes[i]));
      }
    }

    while ((achou = re.exec(texto)) !== null) {
      if (achou.index > pos) escreverTexto(texto.slice(pos, achou.index));

      if (Object.prototype.hasOwnProperty.call(variaveis, achou[0])) {
        frag.appendChild(criarChip(achou[0], variaveis[achou[0]]));
      } else {
        escreverTexto(achou[0]);       // desconhecida: fica crua, e à vista
      }
      pos = achou.index + achou[0].length;
    }
    if (pos < texto.length) escreverTexto(texto.slice(pos));

    return frag;
  }

  function criarChip(nome, rotulo) {
    var el = document.createElement('span');
    el.className = 'vc-chip';
    el.setAttribute('contenteditable', 'false');
    el.setAttribute('data-var', nome);
    el.setAttribute('title', (rotulo || nome) + ' — ' + nome);

    var t = document.createElement('span');
    t.textContent = rotulo || nome;
    el.appendChild(t);

    var x = document.createElement('span');
    x.className = 'vc-x';
    x.setAttribute('aria-hidden', 'true');
    x.textContent = '×';
    el.appendChild(x);

    return el;
  }

  // ── Instância ─────────────────────────────────────────────────────────────

  function VarChips($ta, opts) {
    this.$ta       = $ta;
    this.variaveis = opts.variaveis || {};
    this.aoMudar   = typeof opts.aoMudar === 'function' ? opts.aoMudar : null;
    this.gravando  = false;   // evita eco entre editor e textarea

    injetarEstilo();

    this.$ed = $('<div class="vc-editor" contenteditable="true" role="textbox" aria-multiline="true">')
      .attr('data-placeholder', opts.placeholder || $ta.attr('placeholder') || '')
      .css(opts.altura ? { minHeight: opts.altura } : {});

    $ta.after(this.$ed).hide();
    this.sincronizar();
    this.ligar();
  }

  VarChips.prototype.ligar = function () {
    var self = this;
    var ed   = this.$ed[0];

    this.$ed.on('input.vc', function () { self.gravar(); });

    // Colar SEMPRE como texto puro. HTML colado de fora traria estilo, e
    // pior, nós inteiros que a serialização não sabe ler.
    this.$ed.on('paste.vc', function (e) {
      e.preventDefault();
      var t = (e.originalEvent || e).clipboardData.getData('text/plain');
      self.inserirTexto(t);
    });

    // O × da ficha
    this.$ed.on('click.vc', '.vc-x', function (e) {
      e.preventDefault();
      $(this).closest('.vc-chip').remove();
      self.gravar();
      self.$ed.trigger('focus');
    });

    // Clicar na ficha seleciona ela inteira — aí Backspace/Delete apaga tudo,
    // que é o que se espera de algo que se comporta como uma peça só.
    this.$ed.on('click.vc', '.vc-chip', function (e) {
      if ($(e.target).hasClass('vc-x')) return;
      self.$ed.find('.vc-chip').removeAttr('data-sel');
      this.setAttribute('data-sel', '1');
      var r = document.createRange();
      r.selectNode(this);
      var s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
    });

    this.$ed.on('keydown.vc', function (e) {
      if (e.key !== 'Backspace' && e.key !== 'Delete') return;
      var sel = window.getSelection();
      if (!sel || !sel.rangeCount || !sel.isCollapsed) return;

      // Backspace colado a uma ficha remove a ficha inteira. Sem isto, alguns
      // navegadores deixam o span vazio para trás e a variável some do texto
      // sem nada mudar na tela.
      var r  = sel.getRangeAt(0);
      var no = e.key === 'Backspace' ? anterior(r, ed) : seguinte(r, ed);
      if (no && no.classList && no.classList.contains('vc-chip')) {
        e.preventDefault();
        no.parentNode.removeChild(no);
        self.gravar();
      }
    });

    this.$ed.on('blur.vc', function () {
      self.$ed.find('.vc-chip').removeAttr('data-sel');
    });

    // Mudança feita por código no textarea (preset, limpar) redesenha o editor
    this.$ta.on('input.vc change.vc', function () {
      if (self.gravando) return;
      self.sincronizar();
    });
  };

  function anterior(range, raiz) {
    var n = range.startContainer;
    if (n.nodeType === 3 && range.startOffset > 0) return null;
    if (n.nodeType === 1) return n.childNodes[range.startOffset - 1] || null;
    return n.previousSibling || (n.parentNode !== raiz ? n.parentNode.previousSibling : null);
  }

  function seguinte(range, raiz) {
    var n = range.startContainer;
    if (n.nodeType === 3 && range.startOffset < n.nodeValue.length) return null;
    if (n.nodeType === 1) return n.childNodes[range.startOffset] || null;
    return n.nextSibling || (n.parentNode !== raiz ? n.parentNode.nextSibling : null);
  }

  /** Editor → textarea, avisando quem escuta o textarea. */
  VarChips.prototype.gravar = function () {
    var texto = paraTexto(this.$ed[0]);

    // maxlength do textarea vale para o editor também
    var max = parseInt(this.$ta.attr('maxlength'), 10);
    if (max > 0 && texto.length > max) {
      texto = texto.slice(0, max);
      this.sincronizarCom(texto);
    }

    this.gravando = true;
    this.$ta.val(texto).trigger('input');
    this.gravando = false;

    if (this.aoMudar) this.aoMudar(texto);
  };

  /** Textarea → editor. */
  VarChips.prototype.sincronizar = function () {
    this.sincronizarCom(String(this.$ta.val() || ''));
  };

  VarChips.prototype.sincronizarCom = function (texto) {
    this.$ed.empty()[0].appendChild(paraNos(texto, this.variaveis));
  };

  /** Insere texto puro na posição do cursor. */
  VarChips.prototype.inserirTexto = function (texto) {
    this.inserirNo(document.createTextNode(texto));
  };

  /** Insere a ficha de uma variável na posição do cursor. */
  VarChips.prototype.inserir = function (nome) {
    if (!Object.prototype.hasOwnProperty.call(this.variaveis, nome)) {
      this.inserirTexto(nome);      // desconhecida entra como texto
      return;
    }
    this.inserirNo(criarChip(nome, this.variaveis[nome]));
  };

  VarChips.prototype.inserirNo = function (no) {
    var ed  = this.$ed[0];
    var sel = window.getSelection();
    var r;

    // Cursor fora do editor (o operador clicou num chip da paleta e o foco
    // saiu): o certo é o fim do texto, não o começo.
    if (sel && sel.rangeCount && ed.contains(sel.getRangeAt(0).commonAncestorContainer)) {
      r = sel.getRangeAt(0);
      r.deleteContents();
    } else {
      r = document.createRange();
      r.selectNodeContents(ed);
      r.collapse(false);
    }

    r.insertNode(no);
    r.setStartAfter(no);
    r.collapse(true);
    if (sel) { sel.removeAllRanges(); sel.addRange(r); }

    this.$ed.trigger('focus');
    this.gravar();
  };

  VarChips.prototype.destruir = function () {
    this.$ed.off('.vc').remove();
    this.$ta.off('.vc').show();
    this.$ta.removeData(DADOS);
  };

  // ── Ponte com o jQuery ────────────────────────────────────────────────────

  $.fn.varChips = function (arg) {
    var extra = Array.prototype.slice.call(arguments, 1);
    var saida;

    this.each(function () {
      var $t   = $(this);
      var inst = $t.data(DADOS);

      if (typeof arg === 'string') {
        if (!inst) return;
        if (arg === 'inserir')      inst.inserir(extra[0]);
        else if (arg === 'sincronizar') inst.sincronizar();
        else if (arg === 'destruir')    inst.destruir();
        else if (arg === 'valor')       saida = String($t.val() || '');
        return;
      }

      if (inst) { inst.destruir(); }
      $t.data(DADOS, new VarChips($t, arg || {}));
    });

    return saida !== undefined ? saida : this;
  };
})(jQuery);
