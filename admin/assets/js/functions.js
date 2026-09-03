$(function(){
    
  // ── Drawer universal ──────────────────────────────────────
  /**
 * Admin Drawer
 *
 * Componente universal de drawer lateral.
 *
 * Recursos:
 * - múltiplos drawers empilhados;
 * - controle individual pela instância retornada;
 * - atualização de título, conteúdo, tamanho e ações;
 * - fechamento por botão, overlay, ESC ou API;
 * - proteção contra fechamento duplicado;
 * - beforeClose síncrono ou assíncrono;
 * - bloqueio de scroll;
 * - focus trap;
 * - restauração de foco;
 * - eventos delegados;
 * - AbortSignal para fetch/AJAX;
 * - suporte a string, Node, DocumentFragment e jQuery.
 */

(function (window, document) {
    'use strict';

    const TAMANHOS = new Set(['sm', 'md', 'lg', 'xl']);

    const SELETOR_FOCAVEL = [
        'a[href]',
        'button:not([disabled])',
        'input:not([disabled]):not([type="hidden"])',
        'select:not([disabled])',
        'textarea:not([disabled])',
        '[contenteditable="true"]',
        '[tabindex]:not([tabindex="-1"])',
    ].join(',');

    const manager = {
        stack: [],
        overlay: null,
        contador: 0,
        bodyStyleOriginal: null,
    };

    class AdminDrawerInstance {
        constructor(options = {}) {
            this.options = normalizarOpcoes(options);

            this.id = `admin-drawer-${++manager.contador}`;
            this.estado = 'abrindo';
            this.closePromise = null;

            this.elementoAnterior =
                document.activeElement instanceof HTMLElement
                    ? document.activeElement
                    : null;

            this.ultimoElementoFocado = null;
            this.abortController = new AbortController();

            this.criarElementos();
            this.api = this.criarApi();

            this.setTitulo(this.options.titulo);
            this.setConteudo(this.options.conteudo);
            this.setAcoes(this.options.acoes);

            document.body.appendChild(this.element);
            manager.stack.push(this);

            bloquearScroll();
            sincronizarPilha();

            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    if (
                        this.estado !== 'abrindo' ||
                        !this.element.isConnected
                    ) {
                        return;
                    }

                    this.estado = 'aberto';
                    this.element.classList.add('is-open');

                    this.focarElementoInicial();

                    executarCallback(
                        this.options.onOpen,
                        {
                            drawer: this.api,
                        }
                    );
                });
            });
        }

        criarElementos() {
            const tituloId = `${this.id}-titulo`;

            this.element = document.createElement('aside');
            this.element.id = this.id;
            this.element.className =
                `admin-drawer admin-drawer--${this.options.tamanho}`;

            this.element.tabIndex = -1;
            this.element.setAttribute('role', 'dialog');
            this.element.setAttribute('aria-modal', 'true');
            this.element.setAttribute('aria-labelledby', tituloId);
            this.element.setAttribute('aria-hidden', 'true');

            if (this.options.classe) {
                this.element.classList.add(
                    ...String(this.options.classe)
                        .split(/\s+/)
                        .filter(Boolean)
                );
            }

            this.element.innerHTML = `
                <header class="admin-drawer-header">
                    <div class="admin-drawer-heading">
                        <h3
                            id="${tituloId}"
                            class="admin-drawer-titulo"
                        ></h3>

                        <p
                            class="admin-drawer-subtitulo"
                            hidden
                        ></p>
                    </div>

                    <div class="admin-drawer-header-actions">
                        <div class="admin-drawer-custom-actions"></div>

                        <button
                            type="button"
                            class="admin-drawer-close"
                            aria-label="Fechar"
                        >
                            <svg
                                width="20"
                                height="20"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2.25"
                                stroke-linecap="round"
                                aria-hidden="true"
                                focusable="false"
                            >
                                <line x1="18" y1="6" x2="6" y2="18"></line>
                                <line x1="6" y1="6" x2="18" y2="18"></line>
                            </svg>
                        </button>
                    </div>
                </header>

                <div class="admin-drawer-body"></div>
            `;

            this.titleElement = this.element.querySelector(
                '.admin-drawer-titulo'
            );

            this.subtitleElement = this.element.querySelector(
                '.admin-drawer-subtitulo'
            );

            this.actionsElement = this.element.querySelector(
                '.admin-drawer-custom-actions'
            );

            this.bodyElement = this.element.querySelector(
                '.admin-drawer-body'
            );

            this.closeButton = this.element.querySelector(
                '.admin-drawer-close'
            );

            this.closeButton.addEventListener(
                'click',
                () => this.fechar('botao'),
                {
                    signal: this.abortController.signal,
                }
            );
        }

        criarApi() {
            return {
                id: this.id,

                fechar: (motivo = 'api', options = {}) =>
                    this.fechar(motivo, options),

                close: (motivo = 'api', options = {}) =>
                    this.fechar(motivo, options),

                setTitulo: titulo => {
                    this.setTitulo(titulo);
                    return this.api;
                },

                setSubtitulo: subtitulo => {
                    this.setSubtitulo(subtitulo);
                    return this.api;
                },

                setConteudo: conteudo => {
                    this.setConteudo(conteudo);
                    return this.api;
                },

                setTexto: texto => {
                    this.setTexto(texto);
                    return this.api;
                },

                appendConteudo: conteudo => {
                    this.appendConteudo(conteudo);
                    return this.api;
                },

                prependConteudo: conteudo => {
                    this.prependConteudo(conteudo);
                    return this.api;
                },

                limparConteudo: () => {
                    this.bodyElement.replaceChildren();
                    return this.api;
                },

                setAcoes: acoes => {
                    this.setAcoes(acoes);
                    return this.api;
                },

                setTamanho: tamanho => {
                    this.setTamanho(tamanho);
                    return this.api;
                },

                setCarregando: mensagem => {
                    this.setCarregando(mensagem);
                    return this.api;
                },

                atualizar: dados => {
                    this.atualizar(dados);
                    return this.api;
                },

                escutar: (
                    evento,
                    seletor,
                    handler,
                    options = {}
                ) => {
                    this.escutar(
                        evento,
                        seletor,
                        handler,
                        options
                    );

                    return this.api;
                },

                focar: alvo => {
                    this.focar(alvo);
                    return this.api;
                },

                corpo: () => this.bodyElement,
                body: () => this.bodyElement,

                elemento: () => this.element,
                element: () => this.element,

                sinal: () => this.abortController.signal,
                signal: () => this.abortController.signal,

                estaAberto: () =>
                    this.estado === 'abrindo' ||
                    this.estado === 'aberto',

                estaNoTopo: () =>
                    obterDrawerTopo() === this,
            };
        }

        setTitulo(titulo = '') {
            this.titleElement.textContent = String(titulo ?? '');
        }

        setSubtitulo(subtitulo = '') {
            const texto = String(subtitulo ?? '').trim();

            this.subtitleElement.textContent = texto;
            this.subtitleElement.hidden = texto === '';
        }

        setConteudo(conteudo = '') {
            renderizarConteudo(
                this.bodyElement,
                conteudo,
                'replace'
            );
        }

        setTexto(texto = '') {
            this.bodyElement.textContent = String(texto ?? '');
        }

        appendConteudo(conteudo = '') {
            renderizarConteudo(
                this.bodyElement,
                conteudo,
                'append'
            );
        }

        prependConteudo(conteudo = '') {
            renderizarConteudo(
                this.bodyElement,
                conteudo,
                'prepend'
            );
        }

        setAcoes(acoes = '') {
            renderizarConteudo(
                this.actionsElement,
                acoes,
                'replace'
            );

            this.actionsElement.hidden =
                this.actionsElement.childNodes.length === 0;
        }

        setTamanho(tamanho = 'md') {
            const tamanhoFinal = TAMANHOS.has(tamanho)
                ? tamanho
                : 'md';

            TAMANHOS.forEach(item => {
                this.element.classList.remove(
                    `admin-drawer--${item}`
                );
            });

            this.element.classList.add(
                `admin-drawer--${tamanhoFinal}`
            );

            this.options.tamanho = tamanhoFinal;
        }

        setCarregando(mensagem = 'Carregando...') {
            const container = document.createElement('div');
            container.className = 'admin-drawer-loading';
            container.setAttribute('role', 'status');
            container.setAttribute('aria-live', 'polite');

            const spinner = document.createElement('span');
            spinner.className = 'admin-drawer-spinner';
            spinner.setAttribute('aria-hidden', 'true');

            const texto = document.createElement('span');
            texto.textContent = String(mensagem ?? 'Carregando...');

            container.append(spinner, texto);
            this.bodyElement.replaceChildren(container);
        }

        atualizar(dados = {}) {
            if (
                Object.prototype.hasOwnProperty.call(
                    dados,
                    'titulo'
                )
            ) {
                this.setTitulo(dados.titulo);
            }

            if (
                Object.prototype.hasOwnProperty.call(
                    dados,
                    'subtitulo'
                )
            ) {
                this.setSubtitulo(dados.subtitulo);
            }

            if (
                Object.prototype.hasOwnProperty.call(
                    dados,
                    'conteudo'
                )
            ) {
                this.setConteudo(dados.conteudo);
            }

            if (
                Object.prototype.hasOwnProperty.call(
                    dados,
                    'acoes'
                )
            ) {
                this.setAcoes(dados.acoes);
            }

            if (
                Object.prototype.hasOwnProperty.call(
                    dados,
                    'tamanho'
                )
            ) {
                this.setTamanho(dados.tamanho);
            }
        }

        escutar(evento, seletor, handler, options = {}) {
            if (typeof seletor === 'function') {
                options = handler ?? {};
                handler = seletor;
                seletor = null;
            }

            if (typeof handler !== 'function') {
                throw new TypeError(
                    'O handler do evento deve ser uma função.'
                );
            }

            // Escuta o drawer inteiro, não apenas o body
            const container = this.element;

            const listener = event => {
                if (!seletor) {
                    handler.call(
                        container,
                        event,
                        this.api
                    );

                    return;
                }

                const target =
                    event.target instanceof Element
                        ? event.target
                        : event.target?.parentElement;

                if (!target) {
                    return;
                }

                const elemento = target.closest(seletor);

                if (
                    !elemento ||
                    !container.contains(elemento)
                ) {
                    return;
                }

                handler.call(
                    elemento,
                    event,
                    this.api
                );
            };

            const eventOptions =
                typeof options === 'boolean'
                    ? { capture: options }
                    : { ...options };

            eventOptions.signal =
                this.abortController.signal;

            container.addEventListener(
                evento,
                listener,
                eventOptions
            );

            return this.api;
        }

        focar(alvo = null) {
            const elemento = resolverElemento(
                alvo,
                this.element
            );

            if (elemento instanceof HTMLElement) {
                elemento.focus({
                    preventScroll: true,
                });
            }
        }

        focarElementoInicial() {
            const elementoInicial =
                resolverElemento(
                    this.options.focoInicial,
                    this.element
                ) ||
                this.element.querySelector('[autofocus]') ||
                obterElementosFocaveis(this.element)[0] ||
                this.closeButton ||
                this.element;

            elementoInicial.focus({
                preventScroll: true,
            });
        }

        async fechar(
            motivo = 'api',
            { force = false } = {}
        ) {
            if (this.estado === 'fechado') {
                return true;
            }

            if (this.closePromise) {
                return this.closePromise;
            }

            this.closePromise = this.executarFechamento(
                motivo,
                force
            );

            try {
                return await this.closePromise;
            } finally {
                if (this.estado !== 'fechado') {
                    this.closePromise = null;
                }
            }
        }

        async executarFechamento(motivo, force) {
            if (
                !force &&
                typeof this.options.beforeClose === 'function'
            ) {
                const permitido =
                    await this.options.beforeClose({
                        motivo,
                        drawer: this.api,
                    });

                if (permitido === false) {
                    return false;
                }
            }

            this.estado = 'fechando';

            /*
             * Cancela automaticamente:
             * - eventos registrados pelo drawer;
             * - fetch usando drawer.sinal();
             */
            this.abortController.abort();

            this.element.classList.remove('is-open');
            this.element.setAttribute(
                'aria-hidden',
                'true'
            );

            await aguardarTransicao(this.element);

            this.element.remove();

            const index = manager.stack.indexOf(this);

            if (index !== -1) {
                manager.stack.splice(index, 1);
            }

            this.estado = 'fechado';

            sincronizarPilha();

            if (manager.stack.length === 0) {
                desbloquearScroll();
            }

            restaurarFoco(this);

            executarCallback(
                this.options.onClose,
                {
                    motivo,
                    drawer: this.api,
                }
            );

            return true;
        }
    }

    function normalizarOpcoes(options) {
        const tamanho = TAMANHOS.has(options.tamanho)
            ? options.tamanho
            : 'md';

        return {
            titulo: options.titulo ?? '',
            subtitulo: options.subtitulo ?? '',
            conteudo: options.conteudo ?? '',
            acoes: options.acoes ?? '',
            tamanho,
            classe: options.classe ?? '',
            fecharNoEsc:
                options.fecharNoEsc !== false,
            fecharNoOverlay:
                options.fecharNoOverlay !== false,
            focoInicial:
                options.focoInicial ?? null,
            beforeClose:
                options.beforeClose ?? null,
            onOpen:
                options.onOpen ?? null,
            onClose:
                options.onClose ?? null,
        };
    }

    function renderizarConteudo(
        container,
        conteudo,
        modo = 'replace'
    ) {
        const fragment = criarFragmento(conteudo);

        if (modo === 'append') {
            container.append(fragment);
            return;
        }

        if (modo === 'prepend') {
            container.prepend(fragment);
            return;
        }

        container.replaceChildren(fragment);
    }

    function criarFragmento(conteudo) {
        const fragment = document.createDocumentFragment();

        if (
            conteudo === null ||
            conteudo === undefined
        ) {
            return fragment;
        }

        if (typeof conteudo === 'string') {
            const template = document.createElement('template');
            template.innerHTML = conteudo;

            fragment.append(
                template.content.cloneNode(true)
            );

            return fragment;
        }

        if (conteudo instanceof Node) {
            fragment.append(conteudo);
            return fragment;
        }

        /*
         * Suporte a objetos jQuery sem tornar
         * o componente dependente de jQuery.
         */
        if (
            conteudo &&
            typeof conteudo === 'object' &&
            conteudo.jquery &&
            typeof conteudo.toArray === 'function'
        ) {
            conteudo.toArray().forEach(node => {
                if (node instanceof Node) {
                    fragment.append(node);
                }
            });

            return fragment;
        }

        fragment.append(
            document.createTextNode(String(conteudo))
        );

        return fragment;
    }

    function garantirOverlay() {
        if (
            manager.overlay &&
            manager.overlay.isConnected
        ) {
            return manager.overlay;
        }

        manager.overlay =
            document.getElementById(
                'admin-drawer-overlay'
            );

        if (!manager.overlay) {
            manager.overlay =
                document.createElement('div');

            manager.overlay.id =
                'admin-drawer-overlay';

            manager.overlay.setAttribute(
                'aria-hidden',
                'true'
            );

            document.body.appendChild(
                manager.overlay
            );
        }

        if (
            !manager.overlay.dataset
                .adminDrawerInitialized
        ) {
            manager.overlay.addEventListener(
                'click',
                () => {
                    const topo = obterDrawerTopo();

                    if (
                        topo &&
                        topo.options.fecharNoOverlay
                    ) {
                        topo.fechar('overlay');
                    }
                }
            );

            manager.overlay.dataset
                .adminDrawerInitialized = 'true';
        }

        return manager.overlay;
    }

    function obterDrawerTopo() {
        return manager.stack.at(-1) ?? null;
    }

    function sincronizarPilha() {
        const overlay = garantirOverlay();
        const quantidade = manager.stack.length;
        const possuiDrawer = quantidade > 0;

        overlay.classList.toggle(
            'is-visible',
            possuiDrawer
        );

        overlay.setAttribute(
            'aria-hidden',
            possuiDrawer ? 'false' : 'true'
        );

        manager.stack.forEach((drawer, index) => {
            const estaNoTopo =
                index === quantidade - 1;

            const zIndex = 1010 + index * 20;

            drawer.element.style.zIndex =
                String(zIndex);

            drawer.element.setAttribute(
                'aria-hidden',
                estaNoTopo ? 'false' : 'true'
            );

            drawer.element.classList.toggle(
                'is-behind',
                !estaNoTopo
            );

            if ('inert' in drawer.element) {
                drawer.element.inert =
                    !estaNoTopo;
            } else {
                drawer.element.toggleAttribute(
                    'data-inert',
                    !estaNoTopo
                );
            }
        });

        if (possuiDrawer) {
            const topo = obterDrawerTopo();
            const zIndexTopo = Number(
                topo.element.style.zIndex
            );

            /*
             * O overlay fica:
             * - acima dos drawers anteriores;
             * - abaixo do drawer atual.
             */
            overlay.style.zIndex =
                String(zIndexTopo - 1);
        }
    }

    function bloquearScroll() {
        if (manager.bodyStyleOriginal) {
            return;
        }

        const body = document.body;
        const style = window.getComputedStyle(body);

        const larguraScrollbar =
            window.innerWidth -
            document.documentElement.clientWidth;

        manager.bodyStyleOriginal = {
            overflow: body.style.overflow,
            paddingRight: body.style.paddingRight,
        };

        body.style.overflow = 'hidden';

        if (larguraScrollbar > 0) {
            const paddingAtual =
                parseFloat(style.paddingRight) || 0;

            body.style.paddingRight =
                `${paddingAtual + larguraScrollbar}px`;
        }

        body.classList.add(
            'admin-drawer-open'
        );
    }

    function desbloquearScroll() {
        if (!manager.bodyStyleOriginal) {
            return;
        }

        const body = document.body;

        body.style.overflow =
            manager.bodyStyleOriginal.overflow;

        body.style.paddingRight =
            manager.bodyStyleOriginal.paddingRight;

        body.classList.remove(
            'admin-drawer-open'
        );

        manager.bodyStyleOriginal = null;
    }

    function obterElementosFocaveis(container) {
        return Array.from(
            container.querySelectorAll(
                SELETOR_FOCAVEL
            )
        ).filter(elemento => {
            return (
                elemento instanceof HTMLElement &&
                !elemento.hidden &&
                elemento.getClientRects().length > 0
            );
        });
    }

    function resolverElemento(alvo, contexto) {
        if (!alvo) {
            return null;
        }

        if (typeof alvo === 'function') {
            return resolverElemento(
                alvo(),
                contexto
            );
        }

        if (typeof alvo === 'string') {
            return contexto.querySelector(alvo);
        }

        if (alvo instanceof HTMLElement) {
            return alvo;
        }

        return null;
    }

    function restaurarFoco(drawerFechado) {
        const topo = obterDrawerTopo();

        if (topo) {
            const elemento =
                topo.ultimoElementoFocado;

            if (
                elemento instanceof HTMLElement &&
                elemento.isConnected
            ) {
                elemento.focus({
                    preventScroll: true,
                });

                return;
            }

            topo.focarElementoInicial();
            return;
        }

        if (
            drawerFechado.elementoAnterior instanceof
                HTMLElement &&
            drawerFechado.elementoAnterior.isConnected
        ) {
            drawerFechado.elementoAnterior.focus({
                preventScroll: true,
            });
        }
    }

    function executarCallback(callback, payload) {
        if (typeof callback !== 'function') {
            return;
        }

        try {
            callback(payload);
        } catch (error) {
            console.error(
                '[AdminDrawer] Erro no callback:',
                error
            );
        }
    }

    function aguardarTransicao(elemento) {
        const duracao = obterDuracaoTransicao(
            elemento
        );

        if (duracao <= 0) {
            return Promise.resolve();
        }

        return new Promise(resolve => {
            let finalizado = false;

            const finalizar = () => {
                if (finalizado) {
                    return;
                }

                finalizado = true;

                elemento.removeEventListener(
                    'transitionend',
                    aoFinalizar
                );

                resolve();
            };

            const aoFinalizar = event => {
                if (event.target !== elemento) {
                    return;
                }

                finalizar();
            };

            elemento.addEventListener(
                'transitionend',
                aoFinalizar
            );

            window.setTimeout(
                finalizar,
                duracao + 80
            );
        });
    }

    function obterDuracaoTransicao(elemento) {
        const style =
            window.getComputedStyle(elemento);

        const durations =
            style.transitionDuration
                .split(',')
                .map(converterTempoMs);

        const delays =
            style.transitionDelay
                .split(',')
                .map(converterTempoMs);

        const quantidade = Math.max(
            durations.length,
            delays.length
        );

        let maiorTempo = 0;

        for (let index = 0; index < quantidade; index++) {
            const duration =
                durations[index % durations.length] || 0;

            const delay =
                delays[index % delays.length] || 0;

            maiorTempo = Math.max(
                maiorTempo,
                duration + delay
            );
        }

        return maiorTempo;
    }

    function converterTempoMs(valor) {
        const tempo = String(valor).trim();

        if (tempo.endsWith('ms')) {
            return parseFloat(tempo) || 0;
        }

        if (tempo.endsWith('s')) {
            return (parseFloat(tempo) || 0) * 1000;
        }

        return 0;
    }

    document.addEventListener(
        'keydown',
        event => {
            const topo = obterDrawerTopo();

            if (!topo) {
                return;
            }

            if (
                event.key === 'Escape' &&
                topo.options.fecharNoEsc
            ) {
                event.preventDefault();
                topo.fechar('escape');
                return;
            }

            if (event.key !== 'Tab') {
                return;
            }

            const focaveis =
                obterElementosFocaveis(
                    topo.element
                );

            if (focaveis.length === 0) {
                event.preventDefault();
                topo.element.focus();
                return;
            }

            const primeiro = focaveis[0];
            const ultimo =
                focaveis[focaveis.length - 1];

            const focoAtual =
                document.activeElement;

            if (
                event.shiftKey &&
                (
                    focoAtual === primeiro ||
                    !topo.element.contains(focoAtual)
                )
            ) {
                event.preventDefault();
                ultimo.focus();
                return;
            }

            if (
                !event.shiftKey &&
                (
                    focoAtual === ultimo ||
                    !topo.element.contains(focoAtual)
                )
            ) {
                event.preventDefault();
                primeiro.focus();
            }
        },
        true
    );

    document.addEventListener(
        'focusin',
        event => {
            const topo = obterDrawerTopo();

            if (!topo) {
                return;
            }

            if (topo.element.contains(event.target)) {
                topo.ultimoElementoFocado =
                    event.target;

                return;
            }

            /*
             * Impede o foco de escapar para a página
             * enquanto existe um drawer aberto.
             */
            topo.focarElementoInicial();
        }
    );

    function adminDrawer(options = {}) {
        return new AdminDrawerInstance(options).api;
    }

    adminDrawer.fecharTopo = function (
        motivo = 'api-global',
        options = {}
    ) {
        const topo = obterDrawerTopo();

        return topo
            ? topo.fechar(motivo, options)
            : Promise.resolve(true);
    };

    adminDrawer.fecharTodos = async function ({
        force = false,
    } = {}) {
        let quantidadeFechada = 0;

        while (manager.stack.length > 0) {
            const topo = obterDrawerTopo();

            const fechado = await topo.fechar(
                'fechar-todos',
                { force }
            );

            if (!fechado) {
                break;
            }

            quantidadeFechada++;
        }

        return quantidadeFechada;
    };

    adminDrawer.quantidade = function () {
        return manager.stack.length;
    };

    adminDrawer.topo = function () {
        return obterDrawerTopo()?.api ?? null;
    };

    adminDrawer.versao = '2.0.0';

    window.adminDrawer = adminDrawer;

})(window, document);

  // ── SEO IA — gerador plugável ─────────────────────────────
  /**
   * Inicializa o gerador de SEO com IA em qualquer formulário.
   *
   * @param {object} config
   * @param {string}   config.tipo         — 'produto' | 'categoria' | 'marca' | 'pagina'
   * @param {Function} config.getContexto  — função que retorna os dados do formulário
   * @param {object}   config.campos       — mapeamento { campo_seo: '#id_do_input' }
   * @param {string}   config.container    — selector do container onde injetar o botão
   *
   * Exemplo de uso:
   *   adminSeoIA({
   *     tipo: 'produto',
   *     getContexto: () => ({
   *       nome     : $('#pe-nome').val(),
   *       descricao: $('#pe-descricao').val(),
   *       categoria: $('#pe-categoria option:selected').text(),
   *       marca    : $('#pe-marca option:selected').text(),
   *       preco    : $('#pe-preco').val(),
   *     }),
   *     campos: {
   *       meta_title      : '#pe-meta-title',
   *       meta_description: '#pe-meta-desc',
   *       keywords        : '[name="meta_keywords"]',
   *       google_category : '[name="google_category"]',
   *     },
   *     container: '#pe-seo',
   *   });
   */
  window.adminSeoIA = function ({
    tipo,
    getContexto,
    campos = {},
    container = 'body',
    alvoId = 0,          // id da entidade: sem ele, gera e aplica, mas não há
                         // onde ancorar a procedência (cadastro ainda não salvo)
  }) {
    const containerId = 'seoai-btn-' + tipo + '-' + Math.random().toString(36).slice(2, 7);

    // Injeta o botão no topo da seção SEO
    const $container = $(container);
    if (!$container.length) return;

    // Remove instância anterior se existir
    $container.find('.seoai-btn-wrap').remove();

    // Estado da instância. `modeloId` null = usa o modelo padrão do tipo
    // seo_pacote, definido na Central de IA.
    const estado = { modeloId: null, modelos: [], rotuloPadrao: 'Central de IA' };

    const $wrap = $(`
      <div class="seoai-btn-wrap" id="${containerId}">
        <div class="seoai-acoes">
          <button type="button" class="seoai-btn" id="seoai-trigger-${tipo}">
            <span class="seoai-btn-icon">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                  stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z"/>
              </svg>
            </span>
            <span class="seoai-btn-label">Gerar SEO com IA</span>
            <span class="seoai-btn-badge" id="seoai-badge-${tipo}">Central de IA</span>
          </button>
          <div class="seoai-modelo">
            <button type="button" class="seoai-modelo-toggle" id="seoai-modelo-${tipo}"
                    aria-haspopup="listbox" aria-expanded="false" title="Escolher a IA e o modelo">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                  stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M6 9l6 6 6-6"/>
              </svg>
            </button>
            <div class="seoai-modelo-menu" id="seoai-modelo-menu-${tipo}" role="listbox" hidden></div>
          </div>
        </div>
        <div class="seoai-procedencia" id="seoai-proc-${tipo}" hidden></div>
        <div class="seoai-status" id="seoai-status-${tipo}" style="display:none;"></div>
      </div>`);

    // Insere antes do primeiro .form-group dentro do container
    const $firstGroup = $container.find('.pe-card:first, .form-group:first');
    if ($firstGroup.length) {
      $firstGroup.first().before($wrap);
    } else {
      $container.prepend($wrap);
    }

    const $badge = $(`#seoai-badge-${tipo}`);
    const $menu  = $(`#seoai-modelo-menu-${tipo}`);
    const $toggle = $(`#seoai-modelo-${tipo}`);

    // Catálogo + procedência numa chamada só, ao abrir a tela.
    $.getJSON(BASE_URL + '/admin/seo-ia/modelos', { tipo, alvo_id: alvoId || 0 })
      .done(function (res) {
        if (!res || !res.ok) return;

        estado.modelos = res.modelos || [];
        const padrao = estado.modelos.find(m => m.padrao);
        if (padrao) {
          estado.rotuloPadrao = padrao.rotulo;
          $badge.text(padrao.rotulo);
        }
        montarMenuModelos({ estado, $menu, $toggle, $badge, tipo });

        if (res.procedencia) {
          mostrarProcedencia(tipo, res.procedencia);
        }
      })
      .fail(function () {
        // Catálogo indisponível não impede gerar — o backend usa o padrão.
        $toggle.prop('disabled', true).attr('title', 'Catálogo de modelos indisponível');
      });

    // Trigger
    $(`#seoai-trigger-${tipo}`).on('click', function () {
      const contexto = getContexto();

      if (!contexto.nome && !contexto.titulo) {
        adminToast('Preencha o nome antes de gerar o SEO.', 'warning');
        return;
      }

      gerarSeoIA({
        tipo, contexto, campos, alvoId,
        modeloId: estado.modeloId,
        btnId: `seoai-trigger-${tipo}`,
        statusId: `seoai-status-${tipo}`,
      });
    });
  };

  /** Preenche o dropdown de modelos e liga a seleção. */
  function montarMenuModelos({ estado, $menu, $toggle, $badge, tipo }) {
    if (!estado.modelos.length) {
      $toggle.prop('disabled', true).attr('title', 'Nenhum modelo de texto ativo na Central de IA');
      return;
    }

    const itens = estado.modelos.map(m => `
      <button type="button" class="seoai-modelo-item${m.padrao ? ' ativo' : ''}"
              role="option" aria-selected="${m.padrao ? 'true' : 'false'}" data-id="${m.id}">
        <span class="seoai-modelo-nome">${escapeHtml(m.rotulo)}</span>
        ${m.padrao ? '<span class="seoai-modelo-tag">padrão</span>' : ''}
      </button>`).join('');

    $menu.html(`
      <div class="seoai-modelo-head">Gerar com</div>
      ${itens}
      <div class="seoai-modelo-rodape">Definido na Central de IA &rsaquo; Configurações</div>`);

    $toggle.on('click', function (e) {
      e.stopPropagation();
      const abrindo = $menu.prop('hidden');
      $('.seoai-modelo-menu').prop('hidden', true);
      $('.seoai-modelo-toggle').attr('aria-expanded', 'false');
      $menu.prop('hidden', !abrindo);
      $toggle.attr('aria-expanded', abrindo ? 'true' : 'false');
    });

    $menu.on('click', '.seoai-modelo-item', function () {
      const id = Number($(this).data('id'));
      const m  = estado.modelos.find(x => x.id === id);
      if (!m) return;

      // Escolher o padrão volta ao comportamento "deixe a Central decidir",
      // que preserva o fallback configurado.
      estado.modeloId = m.padrao ? null : m.id;
      $badge.text(m.rotulo);
      $menu.find('.seoai-modelo-item').removeClass('ativo').attr('aria-selected', 'false');
      $(this).addClass('ativo').attr('aria-selected', 'true');
      $menu.prop('hidden', true);
      $toggle.attr('aria-expanded', 'false');
    });

    // Fecha ao clicar fora ou com Escape
    $(document).on('click', () => { $menu.prop('hidden', true); $toggle.attr('aria-expanded', 'false'); });
    $menu.on('click', e => e.stopPropagation());
    $(document).on('keydown', e => {
      if (e.key === 'Escape') { $menu.prop('hidden', true); $toggle.attr('aria-expanded', 'false'); }
    });
  }

  /** Faixa "este SEO foi escrito por IA" acima dos campos. */
  function mostrarProcedencia(tipo, p) {
    const $p = $(`#seoai-proc-${tipo}`);
    if (!$p.length || !p) return;

    const quando = p.aplicado_em ? String(p.aplicado_em).slice(0, 16).replace('T', ' ') : '';
    $p.html(`
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
          stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z"/>
      </svg>
      <span>SEO gerado por IA — <strong>${escapeHtml(p.rotulo || '')}</strong>${quando ? ' · ' + escapeHtml(quando) : ''}${p.por ? ' · por ' + escapeHtml(p.por) : ''}</span>
    `).prop('hidden', false);
  }

  function escapeHtml(t) {
    return $('<div>').text(t == null ? '' : String(t)).html();
  }

  function gerarSeoIA({ tipo, contexto, campos, btnId, statusId, alvoId = 0, modeloId = null }) {
    const $btn    = $(`#${btnId}`);
    const $status = $(`#${statusId}`);

    // Estado: carregando
    $btn.prop('disabled', true).find('.seoai-btn-label').text('Gerando...');
    $btn.find('.seoai-btn-icon').html(`
      <svg class="spin" width="16" height="16" viewBox="0 0 24 24" fill="none"
          stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
        <path d="M21 12a9 9 0 11-6.219-8.56"/>
      </svg>`);

    $status.stop(true).hide();

    // Monta o payload
    const payload = new URLSearchParams();
    payload.append('tipo',        tipo);
    payload.append('_csrf_token', CSRF_TOKEN);
    if (alvoId)   payload.append('alvo_id',   alvoId);
    if (modeloId) payload.append('modelo_id', modeloId);
    Object.entries(contexto).forEach(([k, v]) => {
      payload.append(`contexto[${k}]`, v);
    });

    $.ajax({
      url    : BASE_URL + '/admin/seo-ia/gerar',
      method : 'POST',
      data   : payload.toString(),
      success: function (res) {
        resetBtn($btn);

        if (!res.ok) {
          adminToast(res.msg, 'error');
          return;
        }

        // O badge passa a mostrar quem REALMENTE respondeu: se o modelo
        // escolhido falhar, a Central cai no próximo da cadeia, e o rótulo
        // tem de acompanhar em vez de mentir.
        const ia = res.seo && res.seo._ia ? res.seo._ia : null;
        if (ia && ia.rotulo) {
          $(`#seoai-badge-${tipo}`).text(ia.rotulo);
          if (ia.trocou) {
            adminToast('O modelo escolhido falhou; a Central usou ' + ia.rotulo + '.', 'warning');
          }
        }

        // Preview antes de aplicar
        mostrarPreviewSeoIA({
          seo      : res.seo,
          campos,
          statusId,
          btnId,
          tipo,
          contexto,
          alvoId,
          modeloId,
        });
      },
      error: function () {
        resetBtn($btn);
        adminToast('Erro de conexão com a API.', 'error');
      },
      dataType: 'json',
    });
  }

  function resetBtn($btn) {
    $btn.prop('disabled', false).find('.seoai-btn-label').text('Gerar SEO com IA');
    $btn.find('.seoai-btn-icon').html(`
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
          stroke="currentColor" stroke-width="2" stroke-linecap="round">
        <path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z"/>
      </svg>`);
  }

  function mostrarPreviewSeoIA({ seo, campos, statusId, tipo, contexto, alvoId = 0, modeloId = null }) {
    const $status = $(`#${statusId}`);

    const linhas = [
      { label: 'Meta title',       valor: seo.meta_title,       chars: seo.meta_title?.length,       max: 90  },
      { label: 'Meta description', valor: seo.meta_description, chars: seo.meta_description?.length, max: 256 },
      { label: 'Keywords',         valor: seo.keywords,         chars: null,                          max: null },
      { label: 'Google category',  valor: seo.google_category,  chars: null,                          max: null },
    ];

    let html = `
      <div class="seoai-preview">
        <div class="seoai-preview-header">
          <span class="seoai-preview-title">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z"/>
            </svg>
            Sugestão gerada pela IA
          </span>
          <button type="button" class="seoai-preview-close">×</button>
        </div>
        <div class="seoai-preview-fields">`;

    linhas.forEach(l => {
      if (!l.valor) return;
      const charInfo = l.chars !== null
        ? `<span class="seoai-char-count ${l.chars > l.max ? 'over' : ''}">${l.chars}/${l.max}</span>`
        : '';
      html += `
        <div class="seoai-preview-field">
          <div class="seoai-preview-field-label">
            ${l.label} ${charInfo}
          </div>
          <div class="seoai-preview-field-valor">${l.valor}</div>
        </div>`;
    });

    html += `
        </div>
        <div class="seoai-preview-actions">
          <button type="button" class="btn btn-ghost btn-sm seoai-btn-regenerar"
                  data-tipo="${tipo}">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
              <polyline points="23 4 23 10 17 10"/>
              <path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/>
            </svg>
            Gerar novamente
          </button>
          <button type="button" class="btn btn-primary btn-sm seoai-btn-aplicar">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
              <polyline points="20 6 9 17 4 12"/>
            </svg>
            Aplicar nos campos
          </button>
        </div>
      </div>`;

    $status.html(html).slideDown(200);

    // Aplicar
    $status.find('.seoai-btn-aplicar').on('click', function () {
      aplicarSeoIA(seo, campos);
      $status.slideUp(200);
      adminToast('Campos SEO preenchidos!', 'success');

      // Só AQUI o texto da IA vira o conteúdo da loja — é este o momento que
      // a procedência registra. Sem id (cadastro novo), o backend responde ok
      // e não registra; salvar e regerar resolve.
      const gid = seo && seo._ia ? seo._ia.geracao_id : 0;
      if (!gid || !alvoId) return;

      $.post(BASE_URL + '/admin/seo-ia/aplicado', {
        _csrf_token: CSRF_TOKEN,
        tipo,
        alvo_id: alvoId,
        geracao_id: gid,
      }, null, 'json').done(function (res) {
        if (res && res.ok && res.procedencia) {
          mostrarProcedencia(tipo, res.procedencia);
        }
      });
    });

    // Fechar
    $status.find('.seoai-preview-close').on('click', () => {
      $status.slideUp(200);
    });

    // Regenerar — mantém o modelo escolhido e o alvo da rodada anterior
    $status.find('.seoai-btn-regenerar').on('click', function () {
      $status.slideUp(150);
      const btnId    = `seoai-trigger-${tipo}`;
      const statusId2 = `seoai-status-${tipo}`;
      gerarSeoIA({ tipo, contexto, campos, btnId, statusId: statusId2, alvoId, modeloId });
    });
  }

  function aplicarSeoIA(seo, campos) {
    const mapa = {
      meta_title      : campos.meta_title       || '#pe-meta-title',
      meta_description: campos.meta_description || '#pe-meta-desc',
      keywords        : campos.keywords         || '[name="meta_keywords"]',
      google_category : campos.google_category  || '[name="google_category"]',
    };

    Object.entries(mapa).forEach(([campo, selector]) => {
      const $el = $(selector);
      if (!$el.length || !seo[campo]) return;

      $el.val(seo[campo]).trigger('input');

      // Feedback visual
      $el.addClass('input-success');
      setTimeout(() => $el.removeClass('input-success'), 2500);
    });
  }

}())


/**
 * PEÇA 3 — Upload de vídeo de banner para Cloudflare Stream.
 *
 * Fluxo (upload AO SELECIONAR):
 *   1. Admin escolhe vídeo -> pede uploadURL ao backend
 *   2. Envia o arquivo DIRETO pro Stream (XHR + barra de progresso)
 *   3. Guarda o UID num hidden (name do input file vira o UID via campo oculto)
 *   4. Faz polling do status até 'ready'
 *
 * Integração: adicione este bloco DENTRO da IIFE do teu banners.js,
 * logo após o bloco de upload existente (document.querySelectorAll('.banner-upload-area')...).
 *
 * REQUISITOS no HTML do form (por painel desktop/mobile de vídeo):
 *   - o <input type="file"> de vídeo deve ter data-video-slot="video" ou "video_mobile"
 *   - um <input type="hidden" name="arquivo_video"> (e arquivo_video_mobile) no form
 *     para carregar o UID  ← reusa a coluna arquivo_video
 *   - CSRF token acessível: [name="_csrf_token"]
 */


/**
 * assets/js/stream-upload.js
 *
 * Uploader de video Cloudflare Stream REUTILIZAVEL (banners, clips, ...).
 * Single source of truth do fluxo: pede URL -> envia direto -> polling.
 *
 * USO (em qualquer form):
 *   StreamUpload.init({
 *     fileInput: element,          // <input type="file"> de video
 *     hiddenInput: element,        // <input type="hidden"> que recebe o UID
 *     onProgress: (pct) => {},     // opcional
 *     onReady: (uid) => {},        // opcional: video pronto
 *     onError: (msg) => {},        // opcional
 *     name: 'clip-123',            // opcional: rotulo no CF
 *   });
 *
 * Requisitos globais: BASE_URL, e um [name="_csrf_token"] no documento.
 */
window.StreamUpload = (function () {
  'use strict';

  const ENDPOINT_URL    = () => BASE_URL + '/admin/media/stream-upload-url';
  const ENDPOINT_STATUS = (uid) => `${BASE_URL}/admin/media/stream-status?uid=${encodeURIComponent(uid)}`;
  const MAX_MB = 200;

  function csrf() {
    return document.querySelector('[name="_csrf_token"]')?.value || '';
  }

  function init(opts) {
    const { fileInput, hiddenInput } = opts;
    if (!fileInput || !hiddenInput) {
      console.warn('[StreamUpload] fileInput e hiddenInput sao obrigatorios');
      return;
    }

    fileInput.addEventListener('change', function () {
      const file = this.files[0];
      if (!file) return;

      if (!file.type.startsWith('video/')) {
        fail(opts, 'Selecione um arquivo de video.');
        this.value = '';
        return;
      }
      if (file.size > MAX_MB * 1024 * 1024) {
        fail(opts, `Video excede ${MAX_MB}MB.`);
        this.value = '';
        return;
      }

      upload(file, opts);
    });
  }

  async function upload(file, opts) {
    const { fileInput, hiddenInput } = opts;
    try {
      // 1. pede uploadURL
      const prep = await fetch(ENDPOINT_URL(), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `_csrf_token=${encodeURIComponent(csrf())}&name=${encodeURIComponent(opts.name || 'media')}`,
      }).then(r => r.json());

      if (!prep.ok || !prep.uploadURL) throw new Error(prep.msg || 'Falha ao preparar upload.');
      const uid = prep.uid;

      // 2. envia direto pro Stream com progresso
      await new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', prep.uploadURL, true);
        const fd = new FormData();
        fd.append('file', file);
        xhr.upload.onprogress = (e) => {
          if (e.lengthComputable && typeof opts.onProgress === 'function') {
            opts.onProgress(Math.round((e.loaded / e.total) * 100));
          }
        };
        xhr.onload = () => (xhr.status >= 200 && xhr.status < 300)
          ? resolve() : reject(new Error('Falha no envio (HTTP ' + xhr.status + ').'));
        xhr.onerror = () => reject(new Error('Erro de rede no envio.'));
        xhr.send(fd);
      });

      // 3. guarda UID e limpa o file input
      hiddenInput.value = uid;
      fileInput.value = '';

      // 4. polling ate ready
      await pollStatus(uid);

      if (typeof opts.onReady === 'function') opts.onReady(uid);

    } catch (err) {
      console.error('[StreamUpload]', err);
      hiddenInput.value = '';
      fail(opts, err.message || 'Erro no upload do video.');
    }
  }

  async function pollStatus(uid) {
    const maxTries = 40; // 40 x 3s = 120s
    for (let i = 0; i < maxTries; i++) {
      await sleep(3000);
      try {
        const st = await fetch(ENDPOINT_STATUS(uid)).then(r => r.json());
        if (st.ok && st.ready) return;
      } catch (_) { /* retry */ }
    }
    // nao trava: pode ficar pronto depois
  }

  function fail(opts, msg) {
    if (typeof opts.onError === 'function') opts.onError(msg);
    else if (typeof showToast === 'function') showToast(msg, 'error');
  }

  const sleep = (ms) => new Promise(r => setTimeout(r, ms));

  return { init };
})();