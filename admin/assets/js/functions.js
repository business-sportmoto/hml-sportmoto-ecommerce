$(function(){
    
  // ── Drawer universal ──────────────────────────────────────
  (function () {

    let drawerStack = []; // suporta múltiplos drawers empilhados

    /**
     * Abre um drawer lateral.
     * @param {object} opts
     * @param {string} opts.titulo    — Título do drawer
     * @param {string} opts.conteudo  — HTML do corpo
     * @param {string} opts.tamanho   — 'sm' (420px) | 'md' (560px) | 'lg' (720px) | 'xl' (900px)
     * @param {Function} opts.onClose — Callback ao fechar
     * @returns {object} { close, setConteudo, setTitulo }
     */
    window.adminDrawer = function ({
      titulo   = '',
      conteudo = '',
      tamanho  = 'md',
      onClose  = null,
    } = {}) {

      // Overlay (só cria uma vez)
      let overlay = document.getElementById('admin-drawer-overlay');
      if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'admin-drawer-overlay';
        overlay.addEventListener('click', () => fecharTopo());
        document.body.appendChild(overlay);
      }
      overlay.classList.add('visible');

      // Cria o drawer
      const drawer = document.createElement('div');
      drawer.className = `admin-drawer admin-drawer--${tamanho}`;
      drawer.innerHTML = `
        <div class="admin-drawer-header">
          <h3 class="admin-drawer-titulo"></h3>
          <div class="admin-drawer-header-actions">
            <button type="button" class="admin-drawer-close" aria-label="Fechar">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                  stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                <line x1="18" y1="6" x2="6"  y2="18"/>
                <line x1="6"  y1="6" x2="18" y2="18"/>
              </svg>
            </button>
          </div>
        </div>
        <div class="admin-drawer-body"></div>`;

      drawer.querySelector('.admin-drawer-titulo').textContent = titulo;
      drawer.querySelector('.admin-drawer-body').innerHTML     = conteudo;
      drawer.querySelector('.admin-drawer-close')
            .addEventListener('click', () => fechar(drawer, onClose));

      document.body.appendChild(drawer);
      drawerStack.push({ drawer, onClose });

      // Anima entrada
      requestAnimationFrame(() => {
        requestAnimationFrame(() => drawer.classList.add('open'));
      });

      // Keyboard ESC
      const onKey = e => {
        if (e.key === 'Escape') { fechar(drawer, onClose); document.removeEventListener('keydown', onKey); }
      };
      document.addEventListener('keydown', onKey);
      drawer._removeKeyListener = () => document.removeEventListener('keydown', onKey);

      // API pública
      return {
        close: () => fechar(drawer, onClose),
        setConteudo: (html) => { drawer.querySelector('.admin-drawer-body').innerHTML = html; },
        setTitulo:   (txt)  => { drawer.querySelector('.admin-drawer-titulo').textContent = txt; },
        body: () => drawer.querySelector('.admin-drawer-body'),
      };
    };

    function fecharTopo() {
      if (!drawerStack.length) return;
      const { drawer, onClose } = drawerStack[drawerStack.length - 1];
      fechar(drawer, onClose);
    }

    function fechar(drawer, onClose) {
      drawer.classList.remove('open');
      drawer._removeKeyListener?.();

      setTimeout(() => {
        drawer.remove();
        drawerStack = drawerStack.filter(d => d.drawer !== drawer);

        // Remove overlay se não tiver mais drawers
        if (!drawerStack.length) {
          document.getElementById('admin-drawer-overlay')?.classList.remove('visible');
        }

        onClose?.();
      }, 320);
    }

  })();

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
  }) {
    const containerId = 'seoai-btn-' + tipo + '-' + Math.random().toString(36).slice(2, 7);

    // Injeta o botão no topo da seção SEO
    const $container = $(container);
    if (!$container.length) return;

    // Remove instância anterior se existir
    $container.find('.seoai-btn-wrap').remove();

    const $wrap = $(`
      <div class="seoai-btn-wrap" id="${containerId}">
        <button type="button" class="seoai-btn" id="seoai-trigger-${tipo}">
          <span class="seoai-btn-icon">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z"/>
            </svg>
          </span>
          <span class="seoai-btn-label">Gerar SEO com IA</span>
          <span class="seoai-btn-badge">Gemini</span>
        </button>
        <div class="seoai-status" id="seoai-status-${tipo}" style="display:none;"></div>
      </div>`);

    // Insere antes do primeiro .form-group dentro do container
    const $firstGroup = $container.find('.pe-card:first, .form-group:first');
    if ($firstGroup.length) {
      $firstGroup.first().before($wrap);
    } else {
      $container.prepend($wrap);
    }

    // Trigger
    $(`#seoai-trigger-${tipo}`).on('click', function () {
      const contexto = getContexto();

      if (!contexto.nome && !contexto.titulo) {
        adminToast('Preencha o nome antes de gerar o SEO.', 'warning');
        return;
      }

      gerarSeoIA({ tipo, contexto, campos, btnId: `seoai-trigger-${tipo}`, statusId: `seoai-status-${tipo}` });
    });
  };

  function gerarSeoIA({ tipo, contexto, campos, btnId, statusId }) {
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

        // Preview antes de aplicar
        mostrarPreviewSeoIA({
          seo      : res.seo,
          campos,
          statusId,
          btnId,
          tipo,
          contexto,
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

  function mostrarPreviewSeoIA({ seo, campos, statusId, tipo, contexto }) {
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
    });

    // Fechar
    $status.find('.seoai-preview-close').on('click', () => {
      $status.slideUp(200);
    });

    // Regenerar
    $status.find('.seoai-btn-regenerar').on('click', function () {
      $status.slideUp(150);
      const btnId    = `seoai-trigger-${tipo}`;
      const statusId2 = `seoai-status-${tipo}`;
      gerarSeoIA({ tipo, contexto, campos, btnId, statusId: statusId2 });
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