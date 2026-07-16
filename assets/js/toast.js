
// Toast.js — Plugin universal de notificações
// Versão: 1.0.0

// Zero dependências. Funciona com ou sem jQuery.
// Incluir globalmente no layout principal.

// ── USO BÁSICO ──────────────────────────────────────────
// Toast.success('Pedido criado com sucesso!');
// Toast.error('Erro ao salvar. Tente novamente.');
// Toast.warning('Estoque baixo para este produto.');
// Toast.info('Frete grátis acima de R$ 299.');
// Toast.loading('Processando pagamento...');

// ── USO COMPLETO ────────────────────────────────────────
// Toast.show({
//   type:     'success',        // success | error | warning | info | loading | neutral
//   message:  'Salvo!',
//   title:    'Sucesso',        // opcional
//   duration:  4000,            // ms (0 = não fecha sozinho)
//   position: 'top-right',      // top-right | top-left | top-center
//                               // bottom-right | bottom-left | bottom-center
//   closable:  true,            // mostra botão X
//   progress:  true,            // barra de progresso
//   icon:     '<svg>...</svg>', // ícone customizado
//   actions: [                  // botões de ação
//     { label: 'Desfazer', primary: false, action: () => desfazer() },
//     { label: 'OK',       primary: true,  action: () => {} },
//   ],
//   onClose: () => {},          // callback ao fechar
// });

// ── ATUALIZAR TOAST EXISTENTE ───────────────────────────
// const id = Toast.loading('Enviando...');
// Toast.update(id, { type: 'success', message: 'Enviado!', duration: 3000 });

// ── FECHAR PROGRAMATICAMENTE ────────────────────────────
// const id = Toast.show({ ... });
// Toast.dismiss(id);
// Toast.dismissAll();
 

(function (window) {
  'use strict';

  // ════════════════════════════════════════════════════
  // CONFIGURAÇÃO PADRÃO
  // ════════════════════════════════════════════════════
  const DEFAULTS = {
    type:      'info',
    message:   '',
    title:     null,
    duration:   4000,
    position:  'top-right',
    closable:   true,
    progress:   true,
    icon:       null,
    actions:    [],
    onClose:    null,
    maxVisible: 5,
  };

  // Ícones SVG por tipo
  const ICONS = {
    success: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 15.01 9 12.01"/></svg>',
    error:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
    warning: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
    info:    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
    loading: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" class="toast-spin"><path d="M21 12a9 9 0 11-6.219-8.56"/></svg>',
    neutral: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/></svg>',
  };

  // ════════════════════════════════════════════════════
  // ESTADO INTERNO
  // ════════════════════════════════════════════════════
  let _counter    = 0;
  const _active   = {};   // { id: { el, timer, opts } }
  const _stacks   = {};   // { position: HTMLElement }

  // ════════════════════════════════════════════════════
  // STACK MANAGER
  // ════════════════════════════════════════════════════
  function getStack(position) {
    if (_stacks[position]) return _stacks[position];

    const el = document.createElement('div');
    el.className = 'toast-stack toast-stack--' + position;
    el.setAttribute('aria-live', position.includes('error') ? 'assertive' : 'polite');
    el.setAttribute('aria-atomic', 'false');
    document.body.appendChild(el);
    _stacks[position] = el;
    return el;
  }

  // ════════════════════════════════════════════════════
  // CRIAR TOAST
  // ════════════════════════════════════════════════════
  function create(userOpts) {
    const opts = Object.assign({}, DEFAULTS, userOpts);
    const id   = 'toast-' + (++_counter);

    // Limite de toasts visíveis
    const stack     = getStack(opts.position);
    const visible   = stack.querySelectorAll('.toast:not(.toast--leaving)');
    if (visible.length >= opts.maxVisible) {
      const oldest = visible[0];
      const oldId  = oldest.dataset.toastId;
      if (oldId) dismiss(oldId, true);
    }

    // ── Elemento ──────────────────────────────────────
    const el    = document.createElement('div');
    el.className = 'toast toast--' + opts.type;
    el.dataset.toastId = id;
    el.setAttribute('role', opts.type === 'error' ? 'alert' : 'status');

    const iconHtml = opts.icon
      ? `<span class="toast-icon toast-icon--custom">${opts.icon}</span>`
      : `<span class="toast-icon">${ICONS[opts.type] || ICONS.info}</span>`;

    const closeBtn = opts.closable
      ? `<button type="button" class="toast-close" aria-label="Fechar">
           <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/>
             <line x1="6" y1="6" x2="18" y2="18"/></svg>
         </button>`
      : '';

    const titleHtml = opts.title
      ? `<strong class="toast-title">${escHtml(opts.title)}</strong>`
      : '';

    const actionsHtml = opts.actions.length
      ? `<div class="toast-actions">${
          opts.actions.map((a, i) =>
            `<button type="button" class="toast-action-btn ${a.primary ? 'toast-action-btn--primary' : ''}"
                     data-action-index="${i}">${escHtml(a.label)}</button>`
          ).join('')
        }</div>`
      : '';

    const progressHtml = (opts.progress && opts.duration > 0 && opts.type !== 'loading')
      ? `<div class="toast-progress"><div class="toast-progress-bar"
              style="animation-duration:${opts.duration}ms"></div></div>`
      : '';

    el.innerHTML = `
      <div class="toast-inner">
        ${iconHtml}
        <div class="toast-body">
          ${titleHtml}
          <p class="toast-message">${escHtml(opts.message)}</p>
          ${actionsHtml}
        </div>
        ${closeBtn}
      </div>
      ${progressHtml}
    `;

    // ── Eventos ────────────────────────────────────────
    // Fechar
    const closeEl = el.querySelector('.toast-close');
    if (closeEl) closeEl.addEventListener('click', () => dismiss(id));

    // Ações
    el.querySelectorAll('.toast-action-btn').forEach(btn => {
      btn.addEventListener('click', function () {
        const idx = parseInt(this.dataset.actionIndex, 10);
        const action = opts.actions[idx];
        if (action && typeof action.action === 'function') {
          action.action();
        }
        if (!action || action.dismiss !== false) dismiss(id);
      });
    });

    // Pausar progress no hover
    if (opts.progress && opts.duration > 0) {
      el.addEventListener('mouseenter', () => {
        const bar = el.querySelector('.toast-progress-bar');
        if (bar) bar.style.animationPlayState = 'paused';
        clearTimeout(_active[id]?.timer);
      });
      el.addEventListener('mouseleave', () => {
        const bar = el.querySelector('.toast-progress-bar');
        if (bar) bar.style.animationPlayState = 'running';
        scheduleClose(id, opts);
      });
    }

    // Swipe para fechar (mobile)
    let touchStartX = 0;
    el.addEventListener('touchstart', e => { touchStartX = e.changedTouches[0].clientX; }, { passive: true });
    el.addEventListener('touchend', e => {
      const dx = e.changedTouches[0].clientX - touchStartX;
      if (Math.abs(dx) > 80) dismiss(id);
    }, { passive: true });

    // ── Montar ─────────────────────────────────────────
    stack.appendChild(el);
    _active[id] = { el, opts, timer: null };

    // Trigger reflow para animação
    void el.offsetHeight;
    el.classList.add('toast--visible');

    // Auto-close
    if (opts.duration > 0 && opts.type !== 'loading') {
      scheduleClose(id, opts);
    }

    return id;
  }

  function scheduleClose(id, opts) {
    if (_active[id]) {
      clearTimeout(_active[id].timer);
      _active[id].timer = setTimeout(() => dismiss(id), opts.duration);
    }
  }

  // ════════════════════════════════════════════════════
  // FECHAR TOAST
  // ════════════════════════════════════════════════════
  function dismiss(id, immediate) {
    const entry = _active[id];
    if (!entry) return;

    clearTimeout(entry.timer);
    delete _active[id];

    const el = entry.el;
    if (typeof entry.opts.onClose === 'function') {
      entry.opts.onClose();
    }

    if (immediate) {
      el.remove();
      return;
    }

    el.classList.add('toast--leaving');
    const duration = parseFloat(
      getComputedStyle(el).getPropertyValue('--toast-leave-duration') || '0.3'
    ) * 1000;
    setTimeout(() => el.remove(), duration);
  }

  function dismissAll() {
    Object.keys(_active).forEach(id => dismiss(id));
  }

  // ════════════════════════════════════════════════════
  // ATUALIZAR TOAST EXISTENTE
  // ════════════════════════════════════════════════════
  function update(id, changes) {
    const entry = _active[id];
    if (!entry) return;

    const opts = Object.assign(entry.opts, changes);
    clearTimeout(entry.timer);

    const el = entry.el;

    // Atualiza tipo
    el.className = 'toast toast--' + opts.type + ' toast--visible';

    // Atualiza ícone
    const iconEl = el.querySelector('.toast-icon');
    if (iconEl && !opts.icon) iconEl.innerHTML = ICONS[opts.type] || ICONS.info;

    // Atualiza mensagem
    const msgEl = el.querySelector('.toast-message');
    if (msgEl) msgEl.textContent = opts.message;

    // Atualiza título
    const titleEl = el.querySelector('.toast-title');
    if (titleEl) titleEl.textContent = opts.title || '';

    // Reinicia auto-close
    if (opts.duration > 0 && opts.type !== 'loading') {
      scheduleClose(id, opts);
    }
  }

  // ════════════════════════════════════════════════════
  // HELPERS
  // ════════════════════════════════════════════════════
  function escHtml(str) {
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(String(str)));
    return div.innerHTML;
  }

  // ════════════════════════════════════════════════════
  // API PÚBLICA
  // ════════════════════════════════════════════════════
  const Toast = {
    show:       (opts)          => create(opts),
    update:     (id, changes)   => update(id, changes),
    dismiss:    (id)            => dismiss(id),
    dismissAll: ()              => dismissAll(),

    success: (message, opts = {}) => create({ ...opts, type: 'success', message }),
    error:   (message, opts = {}) => create({ ...opts, type: 'error',   message, duration: opts.duration ?? 6000 }),
    warning: (message, opts = {}) => create({ ...opts, type: 'warning', message }),
    info:    (message, opts = {}) => create({ ...opts, type: 'info',    message }),
    loading: (message, opts = {}) => create({ ...opts, type: 'loading', message, duration: 0, progress: false }),

    /** Toast com botões de ação */
    action:  (message, actions, opts = {}) => create({
      ...opts,
      type: opts.type || 'neutral',
      message,
      actions,
      duration: opts.duration ?? 8000,
    }),

    /** Configura padrões globais */
    configure: (overrides) => Object.assign(DEFAULTS, overrides),
  };

  window.Toast = Toast;

  // Compatibilidade com o CK.toast() do checkout
  if (window.CK) {
    window.CK.toast = (msg, type = 'info') => Toast.show({ type, message: msg });
  }
  // Se CK ainda não existe, ele pegará o Toast.show ao ser carregado depois
  document.addEventListener('DOMContentLoaded', function () {
    if (window.CK && !window.CK._toastPatched) {
      window.CK.toast = (msg, type = 'info') => Toast.show({ type, message: msg });
      window.CK._toastPatched = true;
    }
  });

}(window));