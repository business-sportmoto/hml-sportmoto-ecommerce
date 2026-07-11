// assets/js/admin.js
// Interações globais do painel administrativo.

$(function () {
  

  // ── Sidebar toggle ───────────────────────────────────────
  $('#admin-menu-btn').on('click', function () {
    $('#admin-layout').toggleClass('sidebar-open');
  });
  $('#sidebar-toggle').on('click', function () {
    $('#admin-layout').removeClass('sidebar-open');
  });
  $(document).on('click', function (e) {
    if ($(window).width() < 1024) {
      if (!$(e.target).closest('#admin-sidebar, #admin-menu-btn').length) {
        $('#admin-layout').removeClass('sidebar-open');
      }
    }
  });

  // ── Toast admin ──────────────────────────────────────────
  window.adminToast = function (msg, type = 'success', duration = 4000) {
    const id   = 'at-' + Date.now();
    const icons = { success: '✓', error: '✕', info: 'ℹ', warning: '⚠' };
    const $t = $(`
      <div id="${id}" class="admin-toast admin-toast--${type}">
        <span>${icons[type]}</span>
        <span>${msg}</span>
        <button onclick="$(this).parent().remove()">×</button>
      </div>`);
    $('#admin-toast-container').append($t);
    setTimeout(() => $t.addClass('show'), 10);
    setTimeout(() => { $t.removeClass('show'); setTimeout(() => $t.remove(), 300); }, duration);
  };

  // ── CSRF nos formulários ajax ────────────────────────────
  $(document).ajaxSend(function (e, xhr, opts) {
    xhr.setRequestHeader('X-CSRF-Token', CSRF_TOKEN);
  });

  // ── Confirmação de exclusão ──────────────────────────────
  $(document).on('click', '[data-confirm]', function (e) {
    const msg = $(this).data('confirm') || 'Tem certeza?';
    if (!confirm(msg)) e.preventDefault();
  });

  // ── Toggle ativo/inativo (cupons, banners) ───────────────
  $(document).on('change', '.toggle-ativo', function () {
    const $toggle = $(this);
    const url     = $toggle.data('url');
    const id      = $toggle.data('id');

    $.post(url, { id, _csrf_token: CSRF_TOKEN }, function (res) {
      if (!res.ok) {
        $toggle.prop('checked', !$toggle.prop('checked'));
        adminToast(res.msg || 'Erro.', 'error');
      } else {
        adminToast('Status atualizado!', 'success');
      }
    }, 'json').fail(function () {
      $toggle.prop('checked', !$toggle.prop('checked'));
    });
  });

  // ── Formulários Ajax genéricos ───────────────────────────
  $(document).on('submit', '.admin-ajax-form', function (e) {
    e.preventDefault();
    const $form = $(this);
    const $btn  = $form.find('[type=submit]');
    const url   = $form.attr('action') || window.location.href;

    $btn.prop('disabled', true);
    const origText = $btn.text();
    $btn.text('Salvando...');

    const data = $form.is('[enctype="multipart/form-data"]')
                 ? new FormData($form[0])
                 : $form.serialize();

    const ajaxOpts = {
      url,
      type: 'POST',
      dataType: 'json',
    };

    if (data instanceof FormData) {
      ajaxOpts.data        = data;
      ajaxOpts.processData = false;
      ajaxOpts.contentType = false;
    } else {
      ajaxOpts.data = data;
    }

    $.ajax(ajaxOpts)
      .done(function (res) {
        if (res.ok) {
          adminToast(res.msg || 'Salvo!', 'success');
          if (res.redirect) {
            setTimeout(() => window.location.href = res.redirect, 800);
          }
        } else {
          const msg = res.errors ? res.errors.join('<br>') : res.msg;
          adminToast(msg, 'error');
        }
      })
      .fail(function () { adminToast('Erro de conexão.', 'error'); })
      .always(function () {
        $btn.prop('disabled', false).text(origText);
      });
  });

  // ── Status do pedido ─────────────────────────────────────
  $(document).on('submit', '#form-update-status', function (e) {
    e.preventDefault();
    $.post(ADMIN_URL + '/pedidos/status', $(this).serialize(), function (res) {
      if (res.ok) {
        adminToast(res.msg, 'success');
        // Atualiza o badge na página
        const badge = $(`.order-status-badge`);
        badge.attr('class', `order-status-badge admin-status-badge--${res.novo_status}`);
        badge.text(res.novo_status.replace(/_/g, ' '));
        setTimeout(() => location.reload(), 1200);
      } else {
        adminToast(res.msg, 'error');
      }
    }, 'json');
  });

  // ── Upload de imagem de produto ──────────────────────────
  const $uploadArea = $('#product-upload-area');
  if ($uploadArea.length) {

    $uploadArea.on('dragover', function (e) {
      e.preventDefault();
      $(this).addClass('dragover');
    }).on('dragleave drop', function () {
      $(this).removeClass('dragover');
    });

    $uploadArea.on('drop', function (e) {
      e.preventDefault();
      const files = e.originalEvent.dataTransfer.files;
      uploadProductImages(files);
    });

    $('#product-image-input').on('change', function () {
      uploadProductImages(this.files);
    });
  }

  function uploadProductImages(files) {
    const productId = $('#product-id').val();
    if (!productId) { adminToast('Salve o produto primeiro.', 'warning'); return; }

    Array.from(files).forEach(function (file) {
      const fd = new FormData();
      fd.append('produto_id', productId);
      fd.append('imagem', file);
      fd.append('_csrf_token', CSRF_TOKEN);

      $.ajax({
        url:         ADMIN_URL + '/produtos/imagem/upload',
        type:        'POST',
        data:        fd,
        processData: false,
        contentType: false,
        dataType:    'json',
      }).done(function (res) {
        if (res.ok) {
          addImageThumb(res);
          adminToast('Imagem adicionada!', 'success');
        } else {
          adminToast(res.msg, 'error');
        }
      });
    });
  }

  function addImageThumb(data) {
    const html = `
      <div class="product-img-thumb" id="img-thumb-${data.img_id}">
        <img src="${data.url}" alt="">
        <div class="img-thumb-actions">
          <button type="button" class="img-btn-principal" data-img-id="${data.img_id}"
                  title="Definir como principal">★</button>
          <button type="button" class="img-btn-delete" data-img-id="${data.img_id}"
                  title="Excluir">×</button>
        </div>
        ${data.principal ? '<span class="img-principal-badge">Principal</span>' : ''}
      </div>`;
    $('#product-images-grid').append(html);
  }

  // Excluir imagem
  $(document).on('click', '.img-btn-delete', function () {
    const imgId = $(this).data('img-id');
    if (!confirm('Excluir esta imagem?')) return;

    $.post(ADMIN_URL + '/produtos/imagem/excluir', {
      img_id: imgId, _csrf_token: CSRF_TOKEN
    }, function (res) {
      if (res.ok) {
        $(`#img-thumb-${imgId}`).slideUp(200, function () { $(this).remove(); });
        adminToast('Imagem removida.', 'success');
      }
    }, 'json');
  });

  // Definir como principal
  $(document).on('click', '.img-btn-principal', function () {
    const imgId     = $(this).data('img-id');
    const productId = $('#product-id').val();

    $.post(ADMIN_URL + '/produtos/imagem/principal', {
      img_id: imgId, produto_id: productId, _csrf_token: CSRF_TOKEN
    }, function (res) {
      if (res.ok) {
        $('.img-principal-badge').remove();
        $(`#img-thumb-${imgId}`).append('<span class="img-principal-badge">Principal</span>');
        adminToast('Imagem principal atualizada!', 'success');
      }
    }, 'json');
  });

  // ── Variações dinâmicas ──────────────────────────────────
  let varIndex = $('#product-variations .variation-block').length;

  $('#btn-add-variation').on('click', function () {
    const html = `
      <div class="variation-block" data-index="${varIndex}">
        <div class="variation-block-header">
          <input type="text" name="variacoes[${varIndex}][nome]" class="form-control"
                 placeholder="Ex: Cor, Tamanho" required>
          <button type="button" class="btn-remove-variation admin-btn-sm admin-btn--danger">
            Remover variação
          </button>
        </div>
        <div class="variation-options" id="var-opts-${varIndex}">
          <div class="variation-option-row" data-opt="0">
            <input type="text" name="variacoes[${varIndex}][opcoes][0][valor]"
                   class="form-control" placeholder="Ex: Azul" required>
            <input type="color" name="variacoes[${varIndex}][opcoes][0][cor_hex]"
                   title="Cor (opcional)" style="width:36px;height:36px;padding:2px;">
            <button type="button" class="btn-remove-option admin-btn-sm">−</button>
          </div>
        </div>
        <button type="button" class="btn-add-option admin-btn-sm" data-var="${varIndex}">
          + Adicionar opção
        </button>
      </div>`;
    $('#product-variations').append(html);
    varIndex++;
  });

  $(document).on('click', '.btn-remove-variation', function () {
    $(this).closest('.variation-block').remove();
  });

  $(document).on('click', '.btn-add-option', function () {
    const vi   = $(this).data('var');
    const $opts = $(`#var-opts-${vi}`);
    const oi   = $opts.find('.variation-option-row').length;
    $opts.append(`
      <div class="variation-option-row" data-opt="${oi}">
        <input type="text" name="variacoes[${vi}][opcoes][${oi}][valor]"
               class="form-control" placeholder="Opção" required>
        <input type="color" name="variacoes[${vi}][opcoes][${oi}][cor_hex]"
               style="width:36px;height:36px;padding:2px;">
        <button type="button" class="btn-remove-option admin-btn-sm">−</button>
      </div>`);
  });

  $(document).on('click', '.btn-remove-option', function () {
    if ($(this).closest('.variation-options').find('.variation-option-row').length > 1) {
      $(this).closest('.variation-option-row').remove();
    }
  });

  // ── Ficha técnica dinâmica ───────────────────────────────
  let fichaIdx = $('#ficha-rows .ficha-row').length;

  $('#btn-add-ficha').on('click', function () {
    $('#ficha-rows').append(`
      <div class="ficha-row">
        <input type="text" name="ficha_attr[]" class="form-control" placeholder="Atributo (ex: Peso)">
        <input type="text" name="ficha_val[]"  class="form-control" placeholder="Valor (ex: 500g)">
        <button type="button" class="btn-remove-ficha admin-btn-sm admin-btn--danger">×</button>
      </div>`);
    fichaIdx++;
  });

  $(document).on('click', '.btn-remove-ficha', function () {
    $(this).closest('.ficha-row').remove();
  });

  // ── Estoque por combinação ───────────────────────────────
  $(document).on('submit', '.form-estoque-row', function (e) {
    e.preventDefault();
    $.post(ADMIN_URL + '/api/estoque', $(this).serialize(), function (res) {
      adminToast(res.ok ? res.msg : (res.msg || 'Erro.'), res.ok ? 'success' : 'error');
    }, 'json');
  });

  // ── Drag and drop para ordenar banners ───────────────────
  if ($('#banners-sortable').length && typeof $.fn.sortable !== 'undefined') {
    $('#banners-sortable').sortable({
      update: function () {
        const ids = [];
        $(this).find('[data-banner-id]').each(function () {
          ids.push($(this).data('banner-id'));
        });
        $.post(ADMIN_URL + '/banners/ordenar', { ids, _csrf_token: CSRF_TOKEN }, function (res) {
          if (res.ok) adminToast('Ordem salva!', 'success');
        }, 'json');
      }
    });
  }

  // ── Filtros de pedidos com auto-submit ───────────────────
  $('#form-pedidos-filter select, #form-pedidos-filter input[type=date]').on('change', function () {
    $(this).closest('form').submit();
  });


  // ── Admin: Categorias ────────────────────────────────────
  (function () {
    if (!document.getElementById('cat-tbody') && !document.getElementById('form-categoria')) return;

    // ── Listagem ──────────────────────────────────────────

    // Busca em tempo real
    const $search = document.getElementById('cat-search');
    if ($search) {
      $search.addEventListener('input', function () {
        const q = this.value.toLowerCase().trim();
        document.querySelectorAll('.cat-row').forEach(tr => {
          const nome = tr.querySelector('.cat-nome')?.textContent.toLowerCase() || '';
          const slug = tr.querySelector('.cat-slug')?.textContent.toLowerCase() || '';
          tr.style.display = (!q || nome.includes(q) || slug.includes(q)) ? '' : 'none';
        });
      });
    }

    // Toggle ativo
    $(document).on('click', '.admin-toggle', function () {
      const $btn = $(this);
      const id   = $btn.data('id');

      $.post(BASE_URL + '/admin/categorias/toggle-ativo', {
        id          : id,
        _csrf_token : CSRF_TOKEN,
      }, function (res) {
        if (!res.ok) return;
        $btn.toggleClass('admin-toggle--on', res.ativo == 1);
        $btn.attr('title', res.ativo ? 'Ativo — clique para desativar' : 'Inativo — clique para ativar');
      }, 'json');
    });

    // Excluir
    $(document).on('click', '.btn-excluir-cat', function () {
      const id   = $(this).data('id');
      const nome = $(this).data('nome');

      if (!confirm('Excluir a categoria "' + nome + '"?\nEsta ação não pode ser desfeita.')) return;

      $.post(BASE_URL + '/admin/categorias/excluir', {
        id          : id,
        _csrf_token : CSRF_TOKEN,
      }, function (res) {
        if (res.ok) {
          $('#cat-row-' + id).fadeOut(250, function () { $(this).remove(); });
          showToast(res.msg, 'success');
        } else {
          showToast(res.msg, 'error');
        }
      }, 'json');
    });

    // Salvar ordem
    $('#btn-salvar-ordem').on('click', function () {
      const ordens = [];
      document.querySelectorAll('#cat-tbody tr[data-id]').forEach((tr, i) => {
        ordens.push(tr.dataset.id);
      });

      $.post(BASE_URL + '/admin/categorias/reordenar', {
        ordens      : ordens,
        _csrf_token : CSRF_TOKEN,
      }, function (res) {
        showToast(res.ok ? 'Ordem salva!' : 'Erro ao salvar.', res.ok ? 'success' : 'error');
      }, 'json');
    });

    // ── Formulário ────────────────────────────────────────

    const $form = document.getElementById('form-categoria');
    if (!$form) return;

    // Auto-slug pelo nome
    const $nome = document.getElementById('cat-nome');
    const $slug = document.getElementById('cat-slug');

    if ($nome && $slug && !$slug.value) {
      $nome.addEventListener('input', function () {
        $slug.value = slugify(this.value);
      });
    }

    function slugify(str) {
      return str.toLowerCase()
        .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9\s-]/g, '')
        .replace(/\s+/g, '-')
        .replace(/-+/g, '-')
        .trim();
    }

    // Contadores SEO
    document.querySelectorAll('.seo-counter').forEach(el => {
      const target = document.getElementById(el.dataset.target);
      const max    = parseInt(el.dataset.max);
      if (!target) return;
      const update = () => {
        const len = target.value.length;
        el.textContent = len + ' / ' + max + ' caracteres recomendados';
        el.style.color = len > max ? 'var(--c-primary)' : '';
      };
      target.addEventListener('input', update);
      update();
    });

    // Upload de imagem
    const uploadArea = document.getElementById('upload-area');
    const fileInput  = document.getElementById('cat-imagem');

    if (uploadArea && fileInput) {
      uploadArea.addEventListener('click', () => fileInput.click());
      uploadArea.addEventListener('dragover', e => {
        e.preventDefault();
        uploadArea.classList.add('upload-area--over');
      });
      uploadArea.addEventListener('dragleave', () => {
        uploadArea.classList.remove('upload-area--over');
      });
      uploadArea.addEventListener('drop', e => {
        e.preventDefault();
        uploadArea.classList.remove('upload-area--over');
        if (e.dataTransfer.files[0]) previewImagem(e.dataTransfer.files[0]);
      });
      fileInput.addEventListener('change', function () {
        if (this.files[0]) previewImagem(this.files[0]);
      });
    }

    function previewImagem(file) {
      if (!file.type.startsWith('image/')) return;
      const reader = new FileReader();
      reader.onload = e => {
        let wrap = document.getElementById('img-preview-wrap');
        if (!wrap) {
          wrap = document.createElement('div');
          wrap.id = 'img-preview-wrap';
          wrap.className = 'admin-img-preview';
          wrap.innerHTML = '<img id="img-preview" style="max-width:100%;border-radius:8px;">'
                        + '<button type="button" class="admin-img-remove" id="btn-remove-img">Remover imagem</button>';
          uploadArea.before(wrap);
        }
        document.getElementById('img-preview').src = e.target.result;
        uploadArea.style.display = 'none';
      };
      reader.readAsDataURL(file);
    }

    $(document).on('click', '#btn-remove-img', function () {
      $('#img-preview-wrap').remove();
      if (fileInput) fileInput.value = '';
      if (uploadArea) uploadArea.style.display = '';
    });

    // Submit
    $($form).on('submit', function (e) {
      e.preventDefault();
      const $btn = $('#btn-salvar-cat');
      $btn.prop('disabled', true).text('Salvando...');

      const fd = new FormData(this);

      fetch(BASE_URL + '/admin/categorias/salvar', {
        method : 'POST',
        body   : fd,
      })
      .then(r => r.json())
      .then(res => {
        $btn.prop('disabled', false).text($btn.data('original') || 'Salvar');
        if (res.ok) {
          showToast(res.msg, 'success');
          // setTimeout(() => window.location.href = BASE_URL + '/admin/categorias', 800);
        } else {
          showToast(res.msg, 'error');
        }
      })
      .catch(() => {
        $btn.prop('disabled', false);
        showToast('Erro de conexão.', 'error');
      });
    });

  })();

  // ── Reload de características ao trocar categoria ─────────
$(document).on('change', '#pe-categoria', function () {
  const catId = $(this).val();
  const card  = document.getElementById('pe-chars-card');
  if (!card) return;

  if (!catId) {
    card.innerHTML = '<p class="char-vazio-msg">Selecione uma categoria para ver as características.</p>';
    return;
  }

  card.innerHTML = '<div class="pe-loading">Carregando características...</div>';

  $.get(BASE_URL + '/admin/caracteristicas/por-categoria', {
    categoria_id: catId,
  }, function (res) {
    if (!res.ok || !res.caracteristicas.length) {
      card.innerHTML = `
        <div class="char-vazio">
          <p>Nenhuma característica configurada para esta categoria.</p>
          <a href="${BASE_URL}/admin/categorias" class="btn btn-ghost btn-sm" target="_blank">
            Configurar na categoria
          </a>
        </div>`;
      return;
    }

    // Monta os campos dinamicamente
    let html = '<div class="char-grid" id="prod-chars-grid">';
    res.caracteristicas.forEach(c => {
      const obrig     = c.cat_obrigatorio || c.obrigatorio;
      const opcoes    = c.opcoes ? JSON.parse(c.opcoes) : [];
      const unidade   = c.unidade ? `(${c.unidade})` : '';
      const req       = obrig ? 'required' : '';
      const reqBadge  = obrig ? '<span class="pe-required">*</span>' : '';
      const reqClass  = obrig ? 'char-field--required' : '';

      html += `<div class="char-field ${reqClass}">
        <label class="char-label">
          ${c.nome} <span class="char-unidade">${unidade}</span> ${reqBadge}
        </label>`;

      if (c.tipo === 'texto') {
        html += `<input type="text" name="caracteristicas[${c.id}]"
                        class="form-control" ${req}
                        placeholder="${c.placeholder || ''}">`;
      } else if (c.tipo === 'numero') {
        html += `<div class="char-numero-wrap">
          <input type="number" name="caracteristicas[${c.id}]"
                 class="form-control" step="any" ${req}
                 placeholder="${c.placeholder || '0'}">
          ${c.unidade ? `<span class="char-unidade-suffix">${c.unidade}</span>` : ''}
        </div>`;
      } else if (c.tipo === 'select') {
        let opts = '<option value="">— Selecione —</option>';
        opcoes.forEach(o => { opts += `<option value="${o}">${o}</option>`; });
        html += `<select name="caracteristicas[${c.id}]" class="form-control" ${req}>${opts}</select>`;
      } else if (c.tipo === 'boolean') {
        html += `<div class="char-boolean-group">
          <label class="char-bool-opt">
            <input type="radio" name="caracteristicas[${c.id}]" value="Sim"> <span>Sim</span>
          </label>
          <label class="char-bool-opt">
            <input type="radio" name="caracteristicas[${c.id}]" value="Não"> <span>Não</span>
          </label>
        </div>`;
      } else if (c.tipo === 'textarea') {
        html += `<textarea name="caracteristicas[${c.id}]"
                           class="form-control" rows="3" ${req}
                           placeholder="${c.placeholder || ''}"></textarea>`;
      } else if (c.tipo === 'url') {
        html += `<input type="url" name="caracteristicas[${c.id}]"
                        class="form-control" ${req} placeholder="https://">`;
      }

      html += '</div>';
    });

    html += '</div>';
    card.innerHTML = html;
  }, 'json');
});

// ── Admin de características ──────────────────────────────
(function () {
  if (!document.getElementById('chars-tbody')) return;

  // ── Abre drawer de criar/editar característica ──────────
  function abrirDrawerChar(dados = {}) {
    const isEdit  = !!dados.id;
    const opcoes  = dados.opcoes
                    ? (typeof dados.opcoes === 'string'
                        ? JSON.parse(dados.opcoes)
                        : dados.opcoes)
                    : [];

    const drawer = adminDrawer({
      titulo  : isEdit ? 'Editar característica' : 'Nova característica',
      tamanho : 'md',
      conteudo: buildCharForm(dados, opcoes),
    });

    // Inicializa toggle de opções
    toggleOpcoesDrawer(dados.tipo || 'texto');

    // Tipo muda → toggle opções
    $(drawer.body()).on('change', '#char-tipo', function () {
      toggleOpcoesDrawer(this.value);
    });

    // Adicionar opção
    $(drawer.body()).on('click', '#btn-add-opcao', () => {
      addOpcaoInput(drawer.body());
    });

    // Remover opção
    $(drawer.body()).on('click', '.char-opcao-del', function () {
      $(this).closest('.char-opcao-row').slideUp(150, function () {
        $(this).remove();
      });
    });

    // Salvar
    $(drawer.body()).on('click', '#btn-salvar-char', function () {
      const $btn = $(this);
      $btn.prop('disabled', true).text('Salvando...');

      // Coleta opções manualmente (não estão no form serializado corretamente)
      const opcoesColetadas = [];
      drawer.body().querySelectorAll('.char-opcao-input').forEach(el => {
        const v = el.value.trim();
        if (v) opcoesColetadas.push(v);
      });

      // Monta FormData
      const fd = new FormData(drawer.body().querySelector('#form-char'));
      // Remove opções antigas e readiciona as coletadas
      for (const key of [...fd.keys()].filter(k => k === 'opcoes[]')) {
        fd.delete(key);
      }
      opcoesColetadas.forEach(o => fd.append('opcoes[]', o));

      $.ajax({
        url        : BASE_URL + '/admin/caracteristicas/salvar',
        method     : 'POST',
        data       : fd,
        processData: false,
        contentType: false,
        success    : function (res) {
          $btn.prop('disabled', false).text('Salvar');
          if (!res.ok) { showToast(res.msg, 'error'); return; }
          showToast(res.msg, 'success');
          drawer.close();
          setTimeout(() => window.location.reload(), 500);
        },
        dataType: 'json',
      });
    });
  }

  // ── Constrói o HTML do formulário ───────────────────────
  function buildCharForm(dados, opcoes) {
    const tipoOptions = [
      ['texto',    'Texto curto'],
      ['numero',   'Número'],
      ['select',   'Seleção (lista)'],
      ['boolean',  'Sim / Não'],
      ['textarea', 'Texto longo'],
      ['url',      'URL / Link'],
    ].map(([v, l]) =>
      `<option value="${v}" ${dados.tipo === v ? 'selected' : ''}>${l}</option>`
    ).join('');

    const opcoesHtml = opcoes.map(o => buildOpcaoRow(o)).join('');

    return `
      <form id="form-char">
        <input type="hidden" name="_csrf_token" value="${CSRF_TOKEN}">
        <input type="hidden" name="id" value="${dados.id || 0}">

        <div class="pe-grid-2">
          <div class="form-group">
            <label class="pe-label">
              Nome <span class="pe-required">*</span>
            </label>
            <input type="text" name="nome" id="char-nome"
                   class="form-control"
                   value="${dados.nome || ''}"
                   placeholder="Ex: Peso, Voltagem, Material...">
          </div>
          <div class="form-group">
            <label class="pe-label">Tipo de campo</label>
            <select name="tipo" id="char-tipo" class="form-control">
              ${tipoOptions}
            </select>
          </div>
        </div>

        <div class="pe-grid-2">
          <div class="form-group">
            <label class="pe-label">Unidade</label>
            <input type="text" name="unidade" id="char-unidade"
                   class="form-control"
                   value="${dados.unidade || ''}"
                   placeholder="Ex: kg, cm, W, V">
            <p class="pe-field-hint">
              Exibida ao lado do valor.
            </p>
          </div>
          <div class="form-group">
            <label class="pe-label">Placeholder</label>
            <input type="text" name="placeholder" id="char-placeholder"
                   class="form-control"
                   value="${dados.placeholder || ''}"
                   placeholder="Ex: Digite o peso...">
          </div>
        </div>

        <!-- Opções (só para tipo select) -->
        <div class="form-group" id="char-opcoes-group">
          <label class="pe-label">Opções da lista</label>
          <p class="pe-field-hint">Uma opção por linha.</p>
          <div id="char-opcoes-list">${opcoesHtml}</div>
          <button type="button" class="pe-add-btn" id="btn-add-opcao">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
              <line x1="12" y1="5" x2="12" y2="19"/>
              <line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            Adicionar opção
          </button>
        </div>

        <div class="pe-grid-2">
          <div class="form-group">
            <label class="pe-label">Ordem de exibição</label>
            <input type="number" name="ordem" id="char-ordem"
                   class="form-control"
                   value="${dados.ordem || 0}" min="0"
                   style="max-width:100px;">
          </div>
          <div class="form-group">
            <label class="pe-label" style="margin-bottom:10px;">
              Configurações
            </label>
            <label class="pe-toggle-label">
              <div class="pe-toggle-switch">
                <input type="checkbox" name="obrigatorio" value="1"
                       ${dados.obrigatorio ? 'checked' : ''}>
                <span class="pe-toggle-track">
                  <span class="pe-toggle-thumb-inner"></span>
                </span>
              </div>
              <div>
                <span class="pe-toggle-title">Obrigatório por padrão</span>
                <span class="pe-toggle-desc">
                  Pode ser sobrescrito por categoria
                </span>
              </div>
            </label>
          </div>
        </div>

      </form>

      <!-- Footer fixo -->
      <div class="admin-drawer-footer">
        <button type="button" class="btn btn-ghost"
                onclick="this.closest('.admin-drawer').querySelector('.admin-drawer-close').click()">
          Cancelar
        </button>
        <button type="button" class="btn btn-primary" id="btn-salvar-char">
          ${dados.id ? 'Salvar alterações' : 'Criar característica'}
        </button>
      </div>`;
  }

  function buildOpcaoRow(valor = '') {
    return `
      <div class="char-opcao-row">
        <input type="text" class="form-control form-control--sm char-opcao-input"
               value="${valor}"
               placeholder="Ex: Vermelho, 110V, Algodão...">
        <button type="button" class="btn btn-xs btn-ghost char-opcao-del">×</button>
      </div>`;
  }

  function addOpcaoInput(container) {
    const list = container.querySelector('#char-opcoes-list');
    const div  = document.createElement('div');
    div.innerHTML = buildOpcaoRow();
    list.appendChild(div.firstElementChild);
    list.lastElementChild.querySelector('input').focus();
  }

  function toggleOpcoesDrawer(tipo) {
    const g = document.getElementById('char-opcoes-group');
    if (!g) return;
    g.style.display = tipo === 'select' ? '' : 'none';
  }

  // ── Event listeners ────────────────────────────────────
  document.getElementById('btn-nova-caracteristica')
    ?.addEventListener('click', () => abrirDrawerChar());

  $(document).on('click', '.btn-editar-char', function () {
    abrirDrawerChar({
      id         : $(this).data('id'),
      nome       : $(this).data('nome'),
      tipo       : $(this).data('tipo'),
      unidade    : $(this).data('unidade'),
      placeholder: $(this).data('placeholder'),
      obrigatorio: $(this).data('obrigatorio'),
      ordem      : $(this).data('ordem'),
      opcoes     : $(this).data('opcoes'),
    });
  });

  $(document).on('click', '.btn-excluir-char', async function () {
    const id   = $(this).data('id');
    const nome = $(this).data('nome');

    const ok = await adminConfirm({
      titulo   : `Excluir "${nome}"?`,
      mensagem : 'A característica será removida de todas as categorias vinculadas.',
      tipo     : 'danger',
      confirmar: 'Sim, excluir',
    });
    if (!ok) return;

    $.post(BASE_URL + '/admin/caracteristicas/excluir', {
      id, _csrf_token: CSRF_TOKEN,
    }, function (res) {
      if (res.ok) {
        $(`tr[data-id="${id}"]`).fadeOut(200, function () { $(this).remove(); });
        showToast(res.msg, 'success');
      } else {
        showToast(res.msg, 'error');
      }
    }, 'json');
  });

})();

// ═══════════════════════════════════════════════════════
// SEÇÃO DE CATEGORIAS DO PRODUTO
// admin/assets/js/admin.js — append
// ═══════════════════════════════════════════════════════

(function () {
  const list    = document.getElementById('prod-cats-list');
  const dropdown= document.getElementById('cats-dropdown');
  const trigger = document.getElementById('cats-search-trigger');
  const input   = document.getElementById('cats-search-input');
  const items   = document.querySelectorAll('.cats-dropdown-item');
  const empty   = document.getElementById('cats-dropdown-empty');
  const campoPrincipal = document.getElementById('pe-categoria-principal');

  if (!list || !dropdown) return;

  // ── Mapa das categorias disponíveis ──────────────────
  // Construído a partir dos itens do dropdown
  const catData = {};
  items.forEach(el => {
    catData[el.dataset.id] = {
      id      : el.dataset.id,
      nome    : el.dataset.nome,
      fullPath: el.dataset.fullPath,
    };
  });

  // ── Abre / fecha o dropdown ───────────────────────────
  trigger?.addEventListener('click', e => {
    e.stopPropagation();
    abrirDropdown();
  });

  document.getElementById('cats-dropdown-close')
    ?.addEventListener('click', fecharDropdown);

  document.addEventListener('click', e => {
    if (!dropdown.contains(e.target) && e.target !== trigger) {
      fecharDropdown();
    }
  });

  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && dropdown.classList.contains('open')) {
      fecharDropdown();
    }
  });

  // function abrirDropdown() {
  //   dropdown.classList.add('open');
  //   input?.focus();
  //   sincronizarCheckmarks();
  // }

  function fecharDropdown() {
    dropdown.classList.remove('open');
    if (input) {
      input.value = '';
      filtrarDropdown('');
    }
  }

  // admin/assets/js/admin.js — dentro do IIFE de categorias
// Substitua a função abrirDropdown() por:

  function abrirDropdown() {
    // Move o dropdown para o body na primeira abertura
    if (dropdown.parentElement !== document.body) {
      document.body.appendChild(dropdown);
    }

    posicionarDropdown();
    dropdown.classList.add('open');
    input?.focus();
    sincronizarCheckmarks();
  }

  function posicionarDropdown() {
    const rect = trigger.getBoundingClientRect();
    const scrollY = window.scrollY || document.documentElement.scrollTop;
    const scrollX = window.scrollX || document.documentElement.scrollLeft;

    // Posiciona abaixo do trigger usando coordenadas absolutas
    dropdown.style.position = 'fixed';
    dropdown.style.top      = (rect.bottom + 8) + 'px';
    dropdown.style.left     = rect.left + 'px';
    dropdown.style.width    = Math.max(rect.width, 440) + 'px';

    // Verifica se ultrapassa a borda inferior da tela
    const spaceBelow = window.innerHeight - rect.bottom - 8;
    const spaceAbove = rect.top - 8;

    if (spaceBelow < 280 && spaceAbove > spaceBelow) {
      // Abre para cima
      dropdown.style.top    = 'auto';
      dropdown.style.bottom = (window.innerHeight - rect.top + 8) + 'px';
    } else {
      dropdown.style.bottom = 'auto';
    }
  }

  // Reposiciona ao rolar a página
  window.addEventListener('scroll', () => {
    if (dropdown.classList.contains('open')) {
      posicionarDropdown();
    }
  }, { passive: true });

  // Reposiciona ao redimensionar
  window.addEventListener('resize', () => {
    if (dropdown.classList.contains('open')) {
      posicionarDropdown();
    }
  }, { passive: true });

  // ── Filtro de busca ───────────────────────────────────
  input?.addEventListener('input', function () {
    filtrarDropdown(this.value.trim().toLowerCase());
  });

  function filtrarDropdown(q) {
    let visiveis = 0;

    items.forEach(item => {
      const searchStr = item.dataset.search || '';
      const nome      = item.dataset.nome?.toLowerCase() || '';
      const match     = !q || searchStr.includes(q) || nome.includes(q);

      item.classList.toggle('is-hidden', !match);

      if (match) {
        visiveis++;
        // Destaca o termo no nome
        const nomeEl = item.querySelector('.cats-item-nome');
        if (nomeEl) {
          const texto = catData[item.dataset.id]?.nome || '';
          if (q) {
            const regex = new RegExp(`(${escapeRegex(q)})`, 'gi');
            nomeEl.innerHTML = texto.replace(regex, '<mark>$1</mark>');
          } else {
            nomeEl.textContent = texto;
          }
        }
      }
    });

    if (empty) empty.style.display = visiveis === 0 ? '' : 'none';
  }

  function escapeRegex(str) {
    return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  }

  // ── Sincroniza checkmarks com categorias já selecionadas ──
  function sincronizarCheckmarks() {
    const selecionados = new Set(
      [...document.querySelectorAll('.cat-selected-item')]
        .map(el => el.dataset.id)
    );

    items.forEach(item => {
      const id       = item.dataset.id;
      const isSel    = selecionados.has(id);
      const checkEl  = item.querySelector('.cats-item-check');

      item.classList.toggle('is-selected', isSel);
      if (checkEl) checkEl.style.display = isSel ? '' : 'none';
    });
  }

  // ── Clique em item do dropdown ────────────────────────
  items.forEach(item => {
    item.addEventListener('click', () => {
      const id       = item.dataset.id;
      const isSel    = item.classList.contains('is-selected');

      if (isSel) {
        // Já selecionado: remove
        removerCategoria(id);
      } else {
        // Adiciona
        adicionarCategoria(id);
      }

      sincronizarCheckmarks();
    });
  });

  // ── Adicionar categoria ────────────────────────────────
  function adicionarCategoria(id) {
    if (document.querySelector(`.cat-selected-item[data-id="${id}"]`)) return;

    const dados    = catData[id];
    if (!dados) return;

    const fullPath = dados.fullPath || dados.nome;
    const parts    = fullPath.split(' › ');
    const leaf     = parts.pop();
    const parents  = parts.join(' › ');

    // Se não há nenhuma → é a principal
    const temAlguma    = !!document.querySelector('.cat-selected-item');
    const isPrincipal  = !temAlguma;

    // Remove estado vazio
    const emptyState = document.getElementById('cats-empty-state');
    if (emptyState) emptyState.remove();

    const html = buildCatItemHTML(id, leaf, parents, isPrincipal);
    list.insertAdjacentHTML('beforeend', html);

    if (isPrincipal && campoPrincipal) {
      campoPrincipal.value = id;
    }

    // Recarrega características se necessário
    if (isPrincipal) {
      recarregarCaracteristicas(id);
    }
  }

  function buildCatItemHTML(id, leaf, parents, isPrincipal) {
    const fillStar   = isPrincipal ? '#f59e0b' : 'none';
    const strokeStar = isPrincipal ? '#f59e0b' : '#94a3b8';
    const starCls    = isPrincipal ? 'is-principal' : '';
    const badge      = isPrincipal
      ? '<span class="cat-principal-badge">Principal</span>'
      : '';
    const parentSpan = parents
      ? `<span class="cat-selected-path">${escHtml(parents)} ›</span>`
      : '';

    return `
      <div class="cat-selected-item" data-id="${id}">
        <button type="button"
                class="cat-star-btn ${starCls}"
                data-id="${id}"
                title="${isPrincipal ? 'Categoria principal' : 'Definir como principal'}">
          <svg width="15" height="15" viewBox="0 0 24 24"
               fill="${fillStar}" stroke="${strokeStar}"
               stroke-width="2" stroke-linecap="round">
            <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
          </svg>
        </button>
        <div class="cat-selected-label">
          ${parentSpan}
          <span class="cat-selected-leaf">${escHtml(leaf)}</span>
        </div>
        ${badge}
        <input type="hidden"
               name="categorias[${id}][id]" value="${id}">
        <input type="hidden"
               name="categorias[${id}][principal]"
               class="prod-cat-principal-input"
               value="${isPrincipal ? 1 : 0}">
        <button type="button"
                class="cat-remove-btn prod-cat-remove"
                data-id="${id}" title="Remover">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <line x1="18" y1="6" x2="6"  y2="18"/>
            <line x1="6"  y1="6" x2="18" y2="18"/>
          </svg>
        </button>
      </div>`;
  }

  function escHtml(str) {
    return str
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  // ── Remover categoria ─────────────────────────────────
  function removerCategoria(id) {
    const item = document.querySelector(`.cat-selected-item[data-id="${id}"]`);
    if (!item) return;

    const eraPrincipal = item.querySelector('.cat-star-btn')
                             ?.classList.contains('is-principal');

    item.style.animation = 'catItemOut .18s ease forwards';
    setTimeout(() => {
      item.remove();

      const restantes = document.querySelectorAll('.cat-selected-item');
      if (restantes.length === 0) {
        // Mostra estado vazio
        list.insertAdjacentHTML('beforeend', `
          <div class="cats-empty-state" id="cats-empty-state">
            <div class="cats-empty-icon">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
                <path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/>
              </svg>
            </div>
            <span>Nenhuma categoria selecionada</span>
          </div>`);
        if (campoPrincipal) campoPrincipal.value = '';
      } else if (eraPrincipal) {
        // Promove a primeira restante como principal
        const primeira = restantes[0];
        const primeiraId = primeira.dataset.id;
        definirPrincipal(primeiraId);
      }
    }, 180);
  }

  // Animação de saída
  const styleOut = document.createElement('style');
  styleOut.textContent = `
    @keyframes catItemOut {
      to { opacity: 0; transform: translateX(8px) scale(.97); max-height: 0; padding: 0; margin: 0; }
    }`;
  document.head.appendChild(styleOut);

  // ── Definir principal ─────────────────────────────────
  $(document).on('click', '.cat-star-btn', function () {
    const id = $(this).data('id');
    definirPrincipal(id);

    // Feedback
    const item = document.querySelector(`.cat-selected-item[data-id="${id}"]`);
    if (item) {
      item.style.transition = 'background .3s';
      item.style.background = 'rgba(245,158,11,.08)';
      setTimeout(() => { item.style.background = ''; }, 600);
    }
  });

  function definirPrincipal(id) {
    // Remove principal de todos
    document.querySelectorAll('.cat-star-btn').forEach(btn => {
      btn.classList.remove('is-principal');
      btn.querySelector('svg').setAttribute('fill', 'none');
      btn.querySelector('svg').setAttribute('stroke', '#94a3b8');
      btn.setAttribute('title', 'Definir como principal');
    });
    document.querySelectorAll('.prod-cat-principal-input')
      .forEach(inp => { inp.value = 0; });
    document.querySelectorAll('.cat-principal-badge').forEach(b => b.remove());

    // Marca o novo principal
    const btn = document.querySelector(`.cat-star-btn[data-id="${id}"]`);
    if (btn) {
      btn.classList.add('is-principal');
      btn.querySelector('svg').setAttribute('fill', '#f59e0b');
      btn.querySelector('svg').setAttribute('stroke', '#f59e0b');
      btn.setAttribute('title', 'Categoria principal');
    }

    const item = document.querySelector(`.cat-selected-item[data-id="${id}"]`);
    if (item) {
      item.querySelector('.prod-cat-principal-input').value = 1;

      // Adiciona badge se não existir
      if (!item.querySelector('.cat-principal-badge')) {
        const label = item.querySelector('.cat-selected-label');
        label.insertAdjacentHTML('afterend',
          '<span class="cat-principal-badge">Principal</span>');
      }
    }

    if (campoPrincipal) campoPrincipal.value = id;
    recarregarCaracteristicas(id);
  }

  // ── Event delegation: remover via botão X ────────────
  $(document).on('click', '.prod-cat-remove', function () {
    const id = $(this).data('id');
    removerCategoria(id);
    sincronizarCheckmarks();
  });

  // ── Recarrega características quando muda a principal ─
  function recarregarCaracteristicas(catId) {
    const card = document.getElementById('pe-chars-card');
    if (!card) return;

    card.innerHTML = '<div class="pe-loading">Carregando características...</div>';

    $.get(BASE_URL + '/admin/caracteristicas/por-categoria', {
      categoria_id: catId,
    }, function (res) {
      if (!res.ok || !res.caracteristicas.length) {
        card.innerHTML = `
          <div class="char-vazio">
            <p>Nenhuma característica para esta categoria.</p>
            <a href="${BASE_URL}/admin/categorias" class="btn btn-ghost btn-sm" target="_blank">
              Configurar na categoria
            </a>
          </div>`;
        return;
      }

      // Reconstrói campos (reutiliza a função existente)
      if (typeof buildCaracteristicasHTML === 'function') {
        card.innerHTML = buildCaracteristicasHTML(res.caracteristicas);
      }
    }, 'json');
  }

  // ── Teclado no input de busca: Enter adiciona primeiro ─
  input?.addEventListener('keydown', e => {
    if (e.key === 'Enter') {
      const primeiro = document.querySelector(
        '.cats-dropdown-item:not(.is-hidden):not(.is-selected)'
      );
      if (primeiro) {
        primeiro.click();
      }
      e.preventDefault();
    }

    if (e.key === 'ArrowDown') {
      const primeiro = document.querySelector(
        '.cats-dropdown-item:not(.is-hidden)'
      );
      primeiro?.focus();
      e.preventDefault();
    }
  });

  // Navegação por teclado na lista
  $(document).on('keydown', '.cats-dropdown-item', function (e) {
    if (e.key === 'Enter' || e.key === ' ') {
      $(this).trigger('click');
      input?.focus();
      e.preventDefault();
    }
    if (e.key === 'ArrowDown') {
      const next = $(this).nextAll('.cats-dropdown-item:not(.is-hidden)').first();
      if (next.length) next.focus();
      e.preventDefault();
    }
    if (e.key === 'ArrowUp') {
      const prev = $(this).prevAll('.cats-dropdown-item:not(.is-hidden)').first();
      if (prev.length) prev.focus();
      else input?.focus();
      e.preventDefault();
    }
  });

  // Faz os itens serem focáveis via teclado
  items.forEach(item => {
    if (!item.hasAttribute('tabindex')) item.setAttribute('tabindex', '0');
  });

  // ── Inicializa: marca checkmarks das categorias já salvas
    // sincronizarCheckmarks();

    // No final do IIFE, substitua o sincronizarCheckmarks() inicial por:

  // Inicializa: garante que só UMA é principal
  (function initPrincipal() {
      const items = document.querySelectorAll('.cat-selected-item');
      if (!items.length) return;

      // Conta quantas têm is-principal
      const principals = document.querySelectorAll('.cat-star-btn.is-principal');

      if (principals.length === 0) {
          // Nenhuma marcada → marca a primeira sem disparar eventos
          const primeiroId = items[0].dataset.id;
          definirPrincipalSilencioso(primeiroId);
      } else if (principals.length > 1) {
          // Mais de uma marcada (dado inconsistente) → mantém só a primeira
          const primeiroId = principals[0].dataset.id;
          principals.forEach((btn, i) => {
              if (i > 0) {
                  const id = btn.dataset.id;
                  // Desmarca sem animar
                  btn.classList.remove('is-principal');
                  btn.querySelector('svg').setAttribute('fill', 'none');
                  btn.querySelector('svg').setAttribute('stroke', '#94a3b8');
                  const item = document.querySelector(`.cat-selected-item[data-id="${id}"]`);
                  if (item) {
                      item.querySelector('.prod-cat-principal-input').value = 0;
                      item.querySelector('.cat-principal-badge')?.remove();
                  }
              }
          });
      }

      sincronizarCheckmarks();
  })();

  // Versão silenciosa (sem recarregar características no init)
  function definirPrincipalSilencioso(id) {
      document.querySelectorAll('.cat-star-btn').forEach(btn => {
          btn.classList.remove('is-principal');
          btn.querySelector('svg').setAttribute('fill', 'none');
          btn.querySelector('svg').setAttribute('stroke', '#94a3b8');
      });
      document.querySelectorAll('.prod-cat-principal-input')
          .forEach(inp => { inp.value = 0; });
      document.querySelectorAll('.cat-principal-badge').forEach(b => b.remove());

      const btn = document.querySelector(`.cat-star-btn[data-id="${id}"]`);
      if (btn) {
          btn.classList.add('is-principal');
          btn.querySelector('svg').setAttribute('fill', '#f59e0b');
          btn.querySelector('svg').setAttribute('stroke', '#f59e0b');
      }
      const item = document.querySelector(`.cat-selected-item[data-id="${id}"]`);
      if (item) {
          item.querySelector('.prod-cat-principal-input').value = 1;
          if (!item.querySelector('.cat-principal-badge')) {
              const label = item.querySelector('.cat-selected-label');
              label?.insertAdjacentHTML('afterend',
                  '<span class="cat-principal-badge">Principal</span>');
          }
      }
      if (campoPrincipal) campoPrincipal.value = id;
  }

})();


// ── Gerenciar características da categoria ────────────────
(function () {
  if (!document.getElementById('cat-chars-list')) return;

  // Adicionar característica
  $(document).on('change', '#cat-char-select', function () {
    const id   = $(this).val();
    const nome = $(this).find('option:selected').data('nome');
    const tipo = $(this).find('option:selected').data('tipo');
    if (!id) return;

    // Verifica duplicata
    if ($(`#cat-chars-list .cat-char-item[data-id="${id}"]`).length) {
      showToast('Esta característica já foi adicionada.', 'warning');
      $(this).val('');
      return;
    }

    const item = `
      <div class="cat-char-item" data-id="${id}">
        <span class="cat-char-drag">⠿</span>
        <span class="cat-char-nome">${nome}</span>
        <span class="admin-badge admin-badge--muted">${tipo}</span>
        <label class="cat-char-obrig-label">
          <input type="checkbox" class="cat-char-obrig"> Obrigatório
        </label>
        <button type="button" class="btn btn-xs btn-ghost cat-char-remove">×</button>
      </div>`;

    $('#cat-chars-list').append(item);
    $(this).val('');
  });

  // Remover característica
  $(document).on('click', '.cat-char-remove', function () {
    $(this).closest('.cat-char-item').slideUp(200, function () { $(this).remove(); });
  });

  // Salvar
  $(document).on('click', '#btn-salvar-cat-chars', function () {
    const catId   = $(this).data('categoria-id');
    const vinculos = [];

    $('#cat-chars-list .cat-char-item').each(function (i) {
      vinculos.push({
        id         : $(this).data('id'),
        obrigatorio: $(this).find('.cat-char-obrig').is(':checked') ? 1 : 0,
        ordem      : i,
      });
    });

    $.post(BASE_URL + '/admin/categorias/salvar-caracteristicas', {
      categoria_id: catId,
      vinculos    : vinculos,
      _csrf_token : CSRF_TOKEN,
    }, function (res) {
      if (res.ok) showToast(res.msg, 'success');
      else showToast(res.msg, 'error');
    }, 'json');
  });
})();

// ═══════════════════════════════════════════════════════
// LISTAGEM DE PRODUTOS — edição em massa + filtros
// admin/assets/js/admin.js — append
// ═══════════════════════════════════════════════════════

(function () {
  if (!document.getElementById('prod-table')) return;

  // ────────────────────────────────────────────────────
  // FILTROS AVANÇADOS
  // ────────────────────────────────────────────────────
  const btnToggle  = document.getElementById('btn-toggle-filters');
  const advFilters = document.getElementById('prod-filters-advanced');

  btnToggle?.addEventListener('click', () => {
    advFilters.classList.toggle('open');
    btnToggle.classList.toggle('has-filters', advFilters.classList.contains('open'));
  });

  // Submete ao pressionar Enter na busca
  document.querySelector('.prod-search-input')
    ?.addEventListener('keydown', e => {
      if (e.key === 'Enter') document.getElementById('form-filtros')?.submit();
    });

  // ────────────────────────────────────────────────────
  // MODO EDIÇÃO EM MASSA
  // ────────────────────────────────────────────────────
  const btnMassa   = document.getElementById('btn-editar-massa');
  const toolbar    = document.getElementById('prod-massa-toolbar');
  const massaCount = document.getElementById('massa-count');
  const checkAll   = document.getElementById('check-all-header');
  const labelSel   = document.getElementById('massa-selected-label');

  let massaMode    = false;
  let selecionados = new Set();

  btnMassa?.addEventListener('click', () => {
    massaMode = !massaMode;
    toggleMassaMode(massaMode);
  });

  function toggleMassaMode(ativo) {
    const rows      = document.querySelectorAll('.prod-row');
    const colChecks = document.querySelectorAll('.prod-col-check');
    const colHead   = document.querySelector('thead .prod-col-check');

    // Alterna visibilidade dos elementos
    toolbar.style.display  = ativo ? '' : 'none';
    colHead && (colHead.style.display = ativo ? '' : 'none');

    colChecks.forEach(td => {
      td.style.display = ativo ? '' : 'none';
    });

    rows.forEach(row => {
      row.classList.toggle('massa-mode', ativo);
    });

    btnMassa.classList.toggle('btn-primary', ativo);
    btnMassa.classList.toggle('btn-outline', !ativo);
    btnMassa.querySelector('span:not(.prod-massa-count)') &&
      (btnMassa.querySelector('.prod-massa-count').style.display = ativo && selecionados.size > 0 ? '' : 'none');

    if (!ativo) {
      // Limpa seleção e esconde campos
      selecionados.clear();
      document.querySelectorAll('.prod-row.is-selected').forEach(r => r.classList.remove('is-selected'));
      document.querySelectorAll('.prod-checkbox').forEach(c => c.checked = false);
      document.querySelectorAll('.prod-massa-fields').forEach(f => { f.style.display = ''; f.style.removeProperty('display'); });
      if (checkAll) checkAll.checked = false;
      atualizarContador();
    }
  }

  // Selecionar linha via clique
  $(document).on('click', '.prod-row.massa-mode', function (e) {
    // Não aciona se clicou em botão, link ou input
    if ($(e.target).closest('button, a, input, .admin-toggle').length) return;

    const id       = $(this).data('id');
    const checkbox = this.querySelector('.prod-checkbox');

    if (selecionados.has(id)) {
      selecionados.delete(id);
      $(this).removeClass('is-selected');
      if (checkbox) checkbox.checked = false;
    } else {
      selecionados.add(id);
      $(this).addClass('is-selected');
      if (checkbox) checkbox.checked = true;
    }

    atualizarContador();
    atualizarCamposMassa();
  });

  // Selecionar via checkbox
  $(document).on('change', '.prod-checkbox', function () {
    const id  = $(this).val();
    const row = $(this).closest('.prod-row');

    if (this.checked) {
      selecionados.add(parseInt(id));
      row.addClass('is-selected');
    } else {
      selecionados.delete(parseInt(id));
      row.removeClass('is-selected');
    }
    atualizarContador();
    atualizarCamposMassa();
  });

  // Selecionar todos
  checkAll?.addEventListener('change', function () {
    document.querySelectorAll('.prod-checkbox').forEach(cb => {
      cb.checked = this.checked;
      const id   = parseInt(cb.value);
      const row  = cb.closest('.prod-row');
      if (this.checked) {
        selecionados.add(id);
        row?.classList.add('is-selected');
      } else {
        selecionados.delete(id);
        row?.classList.remove('is-selected');
      }
    });
    atualizarContador();
    atualizarCamposMassa();
  });

  function atualizarContador() {
    const n = selecionados.size;
    if (labelSel) labelSel.textContent = n + ' selecionado' + (n !== 1 ? 's' : '');
    if (massaCount) {
      massaCount.textContent     = n;
      massaCount.style.display   = n > 0 ? '' : 'none';
    }
  }

  function atualizarCamposMassa() {
    document.querySelectorAll('.prod-massa-fields').forEach(fields => {
      const row = fields.closest('.prod-row');
      const id  = parseInt(row?.dataset.id);
      if (selecionados.has(id)) {
        fields.style.display = 'flex';
      } else {
        fields.style.display = 'none';
      }
    });
  }

  // ────────────────────────────────────────────────────
  // EDIÇÃO DE PREÇO
  // ────────────────────────────────────────────────────

  // Salvar preço ao pressionar Enter
  $(document).on('keydown', '.prod-preco-input', function (e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      $(this).closest('.prod-massa-input-group').find('.prod-preco-save').trigger('click');
    }
    if (e.key === 'Escape') {
      $(this).val($(this).data('original'));
    }
  });

  // Botão salvar preço
  $(document).on('click', '.prod-preco-save', async function () {
    const id        = $(this).data('id');
    const temVar    = parseInt($(this).data('tem-variacao')) === 1;
    const $input    = $(`.prod-preco-input[data-id="${id}"]`);
    const novoPreco = parseFloat($input.val()) || 0;
    const original  = parseFloat($input.data('original')) || 0;

    if (novoPreco <= 0) {
      showToast('O preço não pode ser zero.', 'error');
      $input.val(original);
      return;
    }
    if (novoPreco === original) return;

    if (temVar) {
      // Produto com variações — pergunta se aplica a todos os SKUs
      const ok = await adminConfirm({
        titulo   : 'Alterar preço em todas as variações?',
        mensagem : `Preço ${PriceHelper.format(original)} → ${PriceHelper.format(novoPreco)}. Aplicar em todos os SKUs deste produto?`,
        tipo     : 'warning',
        confirmar: 'Sim, alterar todos',
        cancelar : 'Apenas o produto pai',
      });

      await salvarPreco(id, novoPreco, ok ? 'todos' : 'pai');
    } else {
      await salvarPreco(id, novoPreco, 'simples');
    }
  });

  async function salvarPreco(id, preco, modo) {
    const $btn = $(`.prod-preco-save[data-id="${id}"]`);
    $btn.prop('disabled', true);

    try {
      const res = await $.post(BASE_URL + '/admin/produtos/alterar-preco', {
        produto_id  : id,
        preco       : preco,
        modo        : modo,    // 'simples' | 'pai' | 'todos'
        _csrf_token : CSRF_TOKEN,
      });

      if (!res.ok) { showToast(res.msg, 'error'); return; }

      const $input = $(`.prod-preco-input[data-id="${id}"]`);
      $input.data('original', preco).addClass('input-success');
      setTimeout(() => $input.removeClass('input-success'), 2000);

      showToast(
        modo === 'todos'
          ? `Preço atualizado em todas as variações.`
          : `Preço atualizado para R$ ${preco.toFixed(2).replace('.',',')}`,
        'success'
      );
    } catch {
      showToast('Erro ao salvar preço.', 'error');
    } finally {
      $btn.prop('disabled', false);
    }
  }

  // ────────────────────────────────────────────────────
  // EDIÇÃO DE ESTOQUE (produto simples)
  // ────────────────────────────────────────────────────

  $(document).on('keydown', '.prod-estoque-input', function (e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      $(this).closest('.prod-massa-input-group').find('.prod-estoque-save').trigger('click');
    }
    if (e.key === 'Escape') {
      $(this).val($(this).data('original'));
    }
  });

  $(document).on('click', '.prod-estoque-save', async function () {
    const id         = $(this).data('id');
    const $input     = $(`.prod-estoque-input[data-id="${id}"]`);
    const novoEst    = parseInt($input.val()) || 0;
    const original   = parseInt($input.data('original')) || 0;

    if (novoEst === original) return;

    const diferenca  = novoEst - original;
    const direcao    = diferenca > 0 ? 'entrada' : 'saída';

    const ok = await adminConfirm({
      titulo   : 'Confirmar ajuste de estoque?',
      mensagem : `Estoque: ${original} → ${novoEst} (${diferenca > 0 ? '+' : ''}${diferenca} unidades — ${direcao})`,
      tipo     : diferenca < 0 ? 'warning' : 'info',
      confirmar: 'Confirmar',
    });

    if (!ok) {
      $input.val(original);
      return;
    }

    const $btn = $(this);
    $btn.prop('disabled', true);

    try {
      const res = await $.post(BASE_URL + '/admin/estoque/ajustar', {
        produto_id  : id,
        operacao    : diferenca > 0 ? 'entrada' : 'saida',
        quantidade  : Math.abs(diferenca),
        observacao  : 'Ajuste em massa na listagem',
        _csrf_token : CSRF_TOKEN,
      });

      if (!res.ok) { showToast(res.msg, 'error'); return; }

      $input.data('original', novoEst).addClass('input-success');
      setTimeout(() => $input.removeClass('input-success'), 2000);

      // Atualiza badge de estoque
      const $badge = $(`#estoque-badge-${id}`);
      $badge.text(novoEst.toLocaleString('pt-BR'));
      $badge.removeClass('admin-badge--danger admin-badge--warning admin-badge--success');
      $badge.addClass(novoEst === 0 ? 'admin-badge--danger' : 'admin-badge--success');

      showToast(
        `Estoque ajustado: ${original} → ${novoEst}`,
        'success'
      );
    } catch {
      showToast('Erro ao ajustar estoque.', 'error');
    } finally {
      $btn.prop('disabled', false);
    }
  });

  // ────────────────────────────────────────────────────
  // EXPANDIR VARIAÇÕES (produto com variações)
  // ────────────────────────────────────────────────────

  $(document).on('click', '.prod-expand-skus-btn', function () {
    const id       = $(this).data('id');
    const loaded   = $(this).data('loaded');
    const $subrow  = $(`#skus-row-${id}`);
    const $td      = $subrow.find('td');
    const expanded = $subrow.is(':visible');

    $(this).toggleClass('expanded', !expanded);

    if (expanded) {
      $subrow.slideUp(200);
      return;
    }

    if (loaded) {
      $subrow.slideDown(200);
      return;
    }

    // Carrega os SKUs via Ajax
    $td.html(`<div class="prod-skus-loading">
      <div class="prod-sku-skeleton"></div>
      <div class="prod-sku-skeleton"></div>
      <div class="prod-sku-skeleton"></div>
    </div>`);
    $subrow.slideDown(200);

    $.get(BASE_URL + '/admin/produtos/skus-para-edicao', {
      produto_id: id,
    }, function (res) {
      if (!res.ok || !res.skus.length) {
        $td.html('<div class="prod-skus-container"><p style="color:var(--text-3);font-style:italic;font-size:13px;">Nenhuma variação encontrada.</p></div>');
        return;
      }

      $td.html(buildSkusHtml(id, res.skus));
      $(`#btn-expand-${id}`).data('loaded', 1);
    }, 'json');

    $(this).data('loaded', 1);
  });

  function buildSkusHtml(produtoId, skus) {
    let html = '<div class="prod-skus-container">';

    skus.forEach(sku => {
      const variacao = sku.atributos_str || '—';
      html += `
        <div class="prod-sku-item" id="sku-item-${sku.id}">
          <div class="prod-sku-codigo">${esc(sku.sku)}</div>
          <div class="prod-sku-variacao">${esc(variacao)}</div>

          <!-- Preço do SKU -->
          <div class="prod-sku-edit-wrap">
            <div class="prod-sku-preco-label">Preço (R$)</div>
            <div class="prod-sku-preco-input-group">
              <span class="prod-sku-prefix">R$</span>
              <input type="number"
                     class="prod-sku-field-input prod-sku-preco-input"
                     data-sku-id="${sku.id}"
                     data-produto-id="${produtoId}"
                     data-original="${parseFloat(sku.preco).toFixed(2)}"
                     value="${parseFloat(sku.preco).toFixed(2)}"
                     step="0.01" min="0">
              <button type="button"
                      class="prod-sku-field-btn prod-sku-preco-save"
                      data-sku-id="${sku.id}"
                      data-produto-id="${produtoId}"
                      title="Salvar preço (Enter)">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="3" stroke-linecap="round">
                  <polyline points="20 6 9 17 4 12"/>
                </svg>
              </button>
            </div>
          </div>

          <!-- Estoque do SKU -->
          <div class="prod-sku-edit-wrap">
            <div class="prod-sku-preco-label">Estoque</div>
            <div class="prod-sku-estoque-input-group">
              <input type="number"
                     class="prod-sku-field-input prod-sku-estoque-input"
                     data-sku-id="${sku.id}"
                     data-produto-id="${produtoId}"
                     data-original="${sku.estoque}"
                     value="${sku.estoque}"
                     min="0">
              <button type="button"
                      class="prod-sku-field-btn prod-sku-estoque-save"
                      data-sku-id="${sku.id}"
                      data-produto-id="${produtoId}"
                      title="Salvar estoque (Enter)">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="3" stroke-linecap="round">
                  <polyline points="20 6 9 17 4 12"/>
                </svg>
              </button>
            </div>
          </div>

        </div>`;
    });

    html += '</div>';
    return html;
  }

  // ── Preço do SKU ──────────────────────────────────────
  $(document).on('keydown', '.prod-sku-preco-input', function (e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      $(this).closest('.prod-sku-preco-input-group')
             .find('.prod-sku-preco-save').trigger('click');
    }
    if (e.key === 'Escape') $(this).val($(this).data('original'));
  });

  $(document).on('click', '.prod-sku-preco-save', async function () {
    const skuId      = $(this).data('sku-id');
    const produtoId  = $(this).data('produto-id');
    const $input     = $(`.prod-sku-preco-input[data-sku-id="${skuId}"]`);
    const novoPreco  = parseFloat($input.val()) || 0;
    const original   = parseFloat($input.data('original')) || 0;

    if (novoPreco <= 0) { showToast('Preço não pode ser zero.', 'error'); return; }
    if (novoPreco === original) return;

    // Pergunta se aplica a todas as variações
    const ok = await adminConfirm({
      titulo   : 'Alterar preço em todas as variações?',
      mensagem : `SKU ${skuId}: R$ ${original.toFixed(2)} → R$ ${novoPreco.toFixed(2)}. Aplicar este preço em todos os SKUs do produto?`,
      tipo     : 'info',
      confirmar: 'Sim, alterar todos',
      cancelar : 'Só este SKU',
    });

    const $btn = $(this).prop('disabled', true);

    try {
      const res = await $.post(BASE_URL + '/admin/produtos/alterar-preco-sku', {
        sku_id      : skuId,
        produto_id  : produtoId,
        preco       : novoPreco,
        todos       : ok ? 1 : 0,
        _csrf_token : CSRF_TOKEN,
      });

      if (!res.ok) { showToast(res.msg, 'error'); return; }

      if (ok) {
        // Atualiza todos os inputs de preço do SKU neste produto
        $(`.prod-sku-preco-input[data-produto-id="${produtoId}"]`).each(function () {
          $(this).val(novoPreco.toFixed(2)).data('original', novoPreco.toFixed(2));
          $(this).addClass('input-success');
          setTimeout(() => $(this).removeClass('input-success'), 2000);
        });
        showToast('Preço atualizado em todas as variações.', 'success');
      } else {
        $input.data('original', novoPreco.toFixed(2)).addClass('input-success');
        setTimeout(() => $input.removeClass('input-success'), 2000);
        showToast(`Preço do SKU atualizado.`, 'success');
      }
    } catch {
      showToast('Erro ao salvar preço.', 'error');
    } finally {
      $btn.prop('disabled', false);
    }
  });

  // ── Estoque do SKU ────────────────────────────────────
  $(document).on('keydown', '.prod-sku-estoque-input', function (e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      $(this).closest('.prod-sku-estoque-input-group')
             .find('.prod-sku-estoque-save').trigger('click');
    }
    if (e.key === 'Escape') $(this).val($(this).data('original'));
  });

  $(document).on('click', '.prod-sku-estoque-save', async function () {
    const skuId    = $(this).data('sku-id');
    const prodId   = $(this).data('produto-id');
    const $input   = $(`.prod-sku-estoque-input[data-sku-id="${skuId}"]`);
    const novoEst  = parseInt($input.val()) || 0;
    const original = parseInt($input.data('original')) || 0;
    const dif      = novoEst - original;

    if (dif === 0) return;

    const ok = await adminConfirm({
      titulo   : 'Confirmar ajuste de estoque?',
      mensagem : `Estoque do SKU: ${original} → ${novoEst} (${dif > 0 ? '+' : ''}${dif} un)`,
      tipo     : dif < 0 ? 'warning' : 'info',
      confirmar: 'Confirmar',
    });

    if (!ok) { $input.val(original); return; }

    const $btn = $(this).prop('disabled', true);

    try {
      const res = await $.post(BASE_URL + '/admin/estoque/ajustar-sku', {
        sku_id      : skuId,
        produto_id  : prodId,
        novo_valor  : novoEst,
        valor_antes : original,
        _csrf_token : CSRF_TOKEN,
      });

      if (!res.ok) { showToast(res.msg, 'error'); $input.val(original); return; }

      $input.data('original', novoEst).addClass('input-success');
      setTimeout(() => $input.removeClass('input-success'), 2000);

      // Atualiza badge de estoque total do produto
      const $badge = $(`#estoque-badge-${prodId}`);
      if ($badge.length) {
        // Recalcula total dos SKUs visíveis
        let total = 0;
        $(`.prod-sku-estoque-input[data-produto-id="${prodId}"]`).each(function () {
          total += parseInt($(this).val()) || 0;
        });
        $badge.text(total.toLocaleString('pt-BR'));
      }

      showToast(`Estoque ajustado: ${original} → ${novoEst}`, 'success');
    } catch {
      showToast('Erro ao ajustar estoque.', 'error');
    } finally {
      $btn.prop('disabled', false);
    }
  });

  // ── Helpers ───────────────────────────────────────────
  function esc(str) {
    return String(str)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;')
      .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  const PriceHelper = {
    format: (v) => 'R$ ' + parseFloat(v).toFixed(2).replace('.', ',').replace(/(\d)(?=(\d{3})+,)/g, '$1.')
  };

})();

// ═══ MODERAÇÃO DE FOTOS ════════════════════════════════
(function () {
  if (!document.querySelector('.mod-grid') && !document.querySelector('.mod-tabs')) return;

  // Lightbox
  const lb    = document.getElementById('mod-lightbox');
  const lbImg = lb?.querySelector('img');

  document.querySelectorAll('.mod-card-zoom').forEach(btn => {
    btn.addEventListener('click', () => {
      const card = btn.closest('.mod-card');
      const img  = card.querySelector('img');
      lbImg.src  = img.dataset.full || img.src;
      lb.hidden  = false;
      document.body.style.overflow = 'hidden';
    });
  });

  lb?.addEventListener('click', e => {
    if (e.target === lb || e.target.classList.contains('mod-lightbox-close')) {
      lb.hidden = true;
      document.body.style.overflow = '';
    }
  });

  // Aprovar
  document.querySelectorAll('.mod-btn--aprovar').forEach(btn => {
    btn.addEventListener('click', async function () {
      const id   = this.dataset.id;
      const fd   = new FormData();
      fd.append('id', id);
      fd.append('_csrf_token', CSRF_TOKEN);

      this.disabled = true;
      this.textContent = 'Aprovando...';

      try {
        const res = await fetch(BASE_URL + '/admin/moderacao/fotos/aprovar', {
          method: 'POST', body: fd,
        });
        const data = await res.json();
        if (data.ok) {
          this.closest('.mod-card').style.transition = 'all .3s';
          this.closest('.mod-card').style.opacity = '0';
          this.closest('.mod-card').style.transform = 'scale(.9)';
          setTimeout(() => {
            this.closest('.mod-card').remove();
            atualizarContadorPendentes();
          }, 300);
          showToast('Foto aprovada!', 'success');
        }
      } catch (e) {
        showToast('Erro ao aprovar.', 'error');
      }
    });
  });

  // Rejeitar — abre modal
  const modalRej     = document.getElementById('mod-modal-rejeitar');
  const inputMotivo  = document.getElementById('mod-motivo-input');
  let   fotoIdRejeitando = null;

  document.querySelectorAll('.mod-btn--rejeitar').forEach(btn => {
    btn.addEventListener('click', () => {
      fotoIdRejeitando = btn.dataset.id;
      inputMotivo.value = '';
      modalRej.hidden = false;
      document.body.style.overflow = 'hidden';
      inputMotivo.focus();
    });
  });

  document.querySelectorAll('.mod-motivos-presets button').forEach(btn => {
    btn.addEventListener('click', () => {
      inputMotivo.value = btn.dataset.motivo;
    });
  });

  document.getElementById('mod-cancel-reject')?.addEventListener('click', fecharModalRej);
  modalRej?.querySelector('.mod-modal-backdrop')?.addEventListener('click', fecharModalRej);

  function fecharModalRej() {
    modalRej.hidden = true;
    document.body.style.overflow = '';
    fotoIdRejeitando = null;
  }

  document.getElementById('mod-confirm-reject')?.addEventListener('click', async function () {
    const motivo = inputMotivo.value.trim();
    if (!motivo) {
      showToast('Informe o motivo da rejeição.', 'error');
      return;
    }
    if (!fotoIdRejeitando) return;

    const fd = new FormData();
    fd.append('id', fotoIdRejeitando);
    fd.append('motivo', motivo);
    fd.append('_csrf_token', CSRF_TOKEN);

    this.disabled = true;

    try {
      const res = await fetch(BASE_URL + '/admin/moderacao/fotos/rejeitar', {
        method: 'POST', body: fd,
      });
      const data = await res.json();
      if (data.ok) {
        const card = document.querySelector(`.mod-card[data-id="${fotoIdRejeitando}"]`);
        if (card) {
          card.style.transition = 'all .3s';
          card.style.opacity = '0';
          card.style.transform = 'scale(.9)';
          setTimeout(() => { card.remove(); atualizarContadorPendentes(); }, 300);
        }
        showToast('Foto rejeitada.', 'success');
        fecharModalRej();
      } else {
        showToast(data.msg || 'Erro.', 'error');
      }
    } catch (e) {
      showToast('Erro de conexão.', 'error');
    } finally {
      this.disabled = false;
    }
  });

  function atualizarContadorPendentes() {
    const restante = document.querySelectorAll('.mod-card').length;
    const badge    = document.querySelector('.mod-tab-badge');
    if (badge) {
      if (restante > 0) badge.textContent = restante;
      else badge.remove();
    }
  }
})();

// ── Compatibilidade por moto no editor de produto ─────────
(function () {
  if (!document.getElementById('compat-list')) return;

  let keyCounter = Date.now();

  // ── Adicionar novo vínculo via drawer ───────────────────
  document.getElementById('btn-add-compat')
    ?.addEventListener('click', () => {
      const key  = 'new_' + (++keyCounter);
      const html = buildCompatItemHTML(key);

      const empty = document.getElementById('compat-empty');
      if (empty) empty.remove();

      document.getElementById('compat-list')
        .insertAdjacentHTML('beforeend', html);
    });

  function buildCompatItemHTML(key) {
    const montOpts = (window.MOTO_MONTADORAS || []).map(m =>
      `<option value="${m.id}">${m.nome}</option>`
    ).join('');

    return `
      <div class="compat-item" data-key="${key}">
        <div class="compat-item-header" onclick="toggleCompatItem(this)">
          <div class="compat-item-moto">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round">
              <circle cx="5.5" cy="17.5" r="3.5"/><circle cx="18.5" cy="17.5" r="3.5"/>
              <path d="M15 6h-2l-3 8H5.5"/><path d="M15 6l3 5h1.5"/><path d="M9 6h4"/>
            </svg>
            <span class="compat-item-resumo">Nova compatibilidade</span>
          </div>
          
          <svg class="compat-item-chevron" width="14" height="14"
              viewBox="0 0 24 24" fill="none" stroke="currentColor"
              stroke-width="2.5" stroke-linecap="round">
            <polyline points="6 9 12 15 18 9"/>
          </svg>
          <button type="button" class="compat-item-del btn btn-xs btn-ghost">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
              <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
            </svg>
          </button>
        </div>
        <div class="compat-item-body open">
          <div class="compat-item-fields">
            <div class="form-group">
              <label class="pe-label">Montadora</label>
              <select name="compatibilidades[${key}][montadora_id]"
                      class="form-control form-control--sm compat-montadora-sel"
                      data-key="${key}" required>
                <option value="">— Selecione —</option>
                ${montOpts}
              </select>
            </div>
            <div class="form-group">
              <label class="pe-label">Modelo</label>
              <select name="compatibilidades[${key}][modelo_id]"
                      class="form-control form-control--sm compat-modelo-sel"
                      data-key="${key}" disabled>
                <option value="">Todos os modelos</option>
              </select>
            </div>
            <div class="form-group">
              <label class="pe-label">Ano início</label>
              <input type="number"
                    name="compatibilidades[${key}][ano_inicio]"
                    class="form-control form-control--sm compat-ano-ini"
                    data-key="${key}"
                    placeholder="Ex: 2015" min="1960" max="2030">
            </div>
            <div class="form-group">
              <label class="pe-label">Ano fim</label>
              <input type="number"
                    name="compatibilidades[${key}][ano_fim]"
                    class="form-control form-control--sm"
                    placeholder="Ex: 2023" min="1960" max="2030">
            </div>
            <div class="form-group compat-obs-group">
              <label class="pe-label">Observação</label>
              <input type="text"
                    name="compatibilidades[${key}][observacao]"
                    class="form-control form-control--sm"
                    placeholder="Ex: exceto modelos com ABS">
            </div>
          </div>
        </div>
      </div>`;
  }

  // ── Cascata: montadora → modelos ────────────────────────
  $(document).on('change', '.compat-montadora-sel', function () {
    const key          = $(this).data('key');
    const montadoraId  = $(this).val();
    const $modeloSel   = $(`.compat-modelo-sel[data-key="${key}"]`);

    $modeloSel.html('<option value="">Carregando...</option>').prop('disabled', true);

    if (!montadoraId) {
      $modeloSel.html('<option value="">Todos os modelos</option>').prop('disabled', true);
      return;
    }

    $.get(BASE_URL + '/ajax/moto/modelos', { montadora_id: montadoraId }, function (modelos) {
      let opts = '<option value="">Todos os modelos</option>';
      modelos.forEach(m => {
        opts += `<option value="${m.id}">${m.nome}</option>`;
      });
      $modeloSel.html(opts).prop('disabled', false);
    }, 'json');

    atualizarResumoCompat(key);
  });

  // Atualiza resumo ao mudar modelo ou ano
  $(document).on('change input', '.compat-modelo-sel, .compat-ano-ini', function () {
    const key = $(this).data('key');
    atualizarResumoCompat(key);
  });

  function atualizarResumoCompat(key) {
    const $item    = $(`.compat-item[data-key="${key}"]`);
    const $resumo  = $item.find('.compat-item-resumo');
    const montNome = $item.find('.compat-montadora-sel option:selected').text();
    const modNome  = $item.find('.compat-modelo-sel option:selected').text();
    const anoIni   = $item.find('[name$="[ano_inicio]"]').val();
    const anoFim   = $item.find('[name$="[ano_fim]"]').val();

    const partes = [montNome].filter(Boolean);
    if (modNome && modNome !== 'Todos os modelos') partes.push(modNome);
    if (anoIni) partes.push(anoIni + (anoFim && anoFim !== anoIni ? '–' + anoFim : ''));

    $resumo.text(partes.join(' › ') || 'Nova compatibilidade');
  }

  // ── Remover vínculo ─────────────────────────────────────
  $(document).on('click', '.compat-item-del', function () {
    $(this).closest('.compat-item').slideUp(200, function () {
      $(this).remove();
      if (!document.querySelectorAll('.compat-item').length) {
        document.getElementById('compat-list').insertAdjacentHTML('beforeend', `
          <div class="compat-empty" id="compat-empty">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="1.2" stroke-linecap="round">
              <circle cx="12" cy="12" r="10"/>
              <path d="M12 8v4M12 16h.01"/>
            </svg>
            <span>Sem compatibilidade configurada</span>
          </div>`);
      }
    });
  });

  // Atualiza o resumo no header ao mudar campos
  $(document).on('change input', '.compat-montadora-sel, .compat-modelo-sel, [name$="[ano_inicio]"], [name$="[ano_fim]"]', function () {
    const $item   = $(this).closest('.compat-item');
    const $resumo = $item.find('.compat-item-resumo');

    const montNome = $item.find('.compat-montadora-sel option:selected').text().trim();
    const modNome  = $item.find('.compat-modelo-sel option:selected').text().trim();
    const anoIni   = $item.find('[name$="[ano_inicio]"]').val();
    const anoFim   = $item.find('[name$="[ano_fim]"]').val();

    const partes = [];
    if (montNome && montNome !== '— Selecione —') partes.push(montNome);
    if (modNome  && modNome  !== 'Todos os modelos' && modNome !== 'Carregando...') {
      partes.push(modNome);
    }
    if (anoIni) {
      partes.push(anoFim && anoFim !== anoIni
        ? anoIni + '–' + anoFim
        : anoIni);
    }

    $resumo.text(partes.join(' › ') || 'Nova compatibilidade');
  });

})();

// ═══════════════════════════════════════════════════════
// BUSCA POR MOTO — frontend + admin
// ═══════════════════════════════════════════════════════

// ── Frontend: selects em cascata ──────────────────────────
(function () {
  const $montadora = $('#busca-montadora');
  const $modelo    = $('#busca-modelo');
  const $ano       = $('#busca-ano');
  const $btn       = $('#btn-busca-moto');

  if (!$montadora.length) return;

  // Montadora → carrega modelos
  $montadora.on('change', function () {
    const id   = $(this).val();
    const slug = $(this).find('option:selected').data('slug');

    $modelo.html('<option value="">Carregando...</option>').prop('disabled', true);
    $ano.html('<option value="">Selecione o ano</option>').prop('disabled', true);
    $btn.prop('disabled', true);

    if (!id) {
      $modelo.html('<option value="">Selecione o modelo</option>').prop('disabled', true);
      return;
    }

    $.get(BASE_URL + '/ajax/moto/modelos', { montadora_id: id }, function (modelos) {
      let opts = '<option value="">Todos os modelos</option>';
      modelos.forEach(m => {
        opts += `<option value="${m.id}" data-slug="${m.slug}">${m.nome}</option>`;
      });
      $modelo.html(opts).prop('disabled', false);
    }, 'json');
  });

  // Modelo → carrega anos
  $modelo.on('change', function () {
    const id = $(this).val();
    $ano.html('<option value="">Carregando...</option>').prop('disabled', true);
    $btn.prop('disabled', true);

    if (!id) {
      $ano.html('<option value="">Selecione o ano</option>').prop('disabled', true);
      $btn.prop('disabled', !$montadora.val());
      return;
    }

    $.get(BASE_URL + '/ajax/moto/anos', { modelo_id: id }, function (anos) {
      let opts = '<option value="">Todos os anos</option>';
      anos.forEach(a => {
        opts += `<option value="${a.ano}">${a.ano}</option>`;
      });
      $ano.html(opts).prop('disabled', false);
      $btn.prop('disabled', false);
    }, 'json');
  });

  // Ano selecionado → habilita busca
  $ano.on('change', function () {
    $btn.prop('disabled', !$montadora.val());
  });

  // Submit: monta URL amigável
  $('#form-busca-moto').on('submit', function (e) {
    e.preventDefault();

    const montSlug  = $montadora.find('option:selected').data('slug');
    const modSlug   = $modelo.find('option:selected').data('slug');
    const ano       = $ano.val();

    if (!montSlug) return;

    let url = BASE_URL + '/montadora/' + montSlug;

    if (modSlug) {
      url += '/' + modSlug;
      if (ano) url += '-' + ano;
    }

    window.location.href = url;
  });
})();

// ── Admin Motos: gerenciamento manual ─────────────────────
(function () {
  if (!document.getElementById('btn-nova-montadora') &&
      !document.getElementById('btn-novo-modelo')) return;

  // ── Formulário reutilizável via drawer ────────────────
  function buildMontadoraForm(dados = {}) {
    return `
      <form id="form-montadora">
        <input type="hidden" name="_csrf_token" value="${CSRF_TOKEN}">
        <input type="hidden" name="id" value="${dados.id || 0}">

        <div class="form-group">
          <label class="pe-label">
            Nome da montadora <span class="pe-required">*</span>
          </label>
          <input type="text" name="nome" id="mont-nome"
                 class="form-control"
                 value="${dados.nome || ''}"
                 placeholder="Ex: Honda, Yamaha, Kawasaki..."
                 autofocus>
        </div>

        <label class="pe-toggle-label" style="margin-top:12px;">
          <div class="pe-toggle-switch">
            <input type="checkbox" name="ativo" value="1"
                   ${dados.ativo !== 0 ? 'checked' : ''}>
            <span class="pe-toggle-track">
              <span class="pe-toggle-thumb-inner"></span>
            </span>
          </div>
          <div>
            <span class="pe-toggle-title">Ativa</span>
            <span class="pe-toggle-desc">Visível para filtros e buscas</span>
          </div>
        </label>
      </form>

      <div class="admin-drawer-footer">
        <button type="button" class="btn btn-ghost"
                onclick="this.closest('.admin-drawer')
                             .querySelector('.admin-drawer-close').click()">
          Cancelar
        </button>
        <button type="button" class="btn btn-primary" id="btn-salvar-montadora">
          ${dados.id ? 'Salvar alterações' : 'Criar montadora'}
        </button>
      </div>`;
  }

  function buildModeloForm(dados = {}) {
    return `
      <form id="form-modelo">
        <input type="hidden" name="_csrf_token" value="${CSRF_TOKEN}">
        <input type="hidden" name="id" value="${dados.id || 0}">
        <input type="hidden" name="montadora_id"
               value="${dados.montadora_id || window.MONTADORA_ID_ATUAL || 0}">

        <div class="form-group">
          <label class="pe-label">
            Nome do modelo <span class="pe-required">*</span>
          </label>
          <input type="text" name="nome" id="mod-nome"
                 class="form-control"
                 value="${dados.nome || ''}"
                 placeholder="Ex: CG 160, CB 300, MT-03..."
                 autofocus>
        </div>

        <label class="pe-toggle-label" style="margin-top:12px;">
          <div class="pe-toggle-switch">
            <input type="checkbox" name="ativo" value="1"
                   ${dados.ativo !== 0 ? 'checked' : ''}>
            <span class="pe-toggle-track">
              <span class="pe-toggle-thumb-inner"></span>
            </span>
          </div>
          <div>
            <span class="pe-toggle-title">Ativo</span>
            <span class="pe-toggle-desc">Visível para filtros e buscas</span>
          </div>
        </label>
      </form>

      <div class="admin-drawer-footer">
        <button type="button" class="btn btn-ghost"
                onclick="this.closest('.admin-drawer')
                             .querySelector('.admin-drawer-close').click()">
          Cancelar
        </button>
        <button type="button" class="btn btn-primary" id="btn-salvar-modelo">
          ${dados.id ? 'Salvar alterações' : 'Criar modelo'}
        </button>
      </div>`;
  }

  // ── Nova montadora ─────────────────────────────────────
  document.getElementById('btn-nova-montadora')
    ?.addEventListener('click', () => {
      const drawer = adminDrawer({
        titulo  : 'Nova montadora',
        tamanho : 'sm',
        conteudo: buildMontadoraForm(),
      });

      $(drawer.body()).on('click', '#btn-salvar-montadora', function () {
        const $btn = $(this).prop('disabled', true).text('Salvando...');
        $.post(BASE_URL + '/admin/motos/salvar-montadora',
          $(drawer.body().querySelector('#form-montadora')).serialize(),
          function (res) {
            $btn.prop('disabled', false).text('Criar montadora');
            if (!res.ok) { showToast(res.msg, 'error'); return; }
            showToast(res.msg, 'success');
            drawer.close();

            // Adiciona linha na tabela sem reload
            const tbody = document.getElementById('montadoras-tbody');
            if (tbody) {
              tbody.insertAdjacentHTML('beforeend', `
                <tr data-id="${res.id}">
                  <td>
                    <div class="motos-thumb-wrap" id="thumb-mont-${res.id}">
                      <div class="motos-thumb-empty">
                        ${res.nome.substring(0,2).toUpperCase()}
                      </div>
                      <button type="button" class="motos-thumb-upload-btn"
                              data-tipo="montadora" data-id="${res.id}" title="Upload thumb">
                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                          <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>
                          <polyline points="17 8 12 3 7 8"/>
                          <line x1="12" y1="3" x2="12" y2="15"/>
                        </svg>
                      </button>
                    </div>
                  </td>
                  <td>
                    <span class="cat-nome">${res.nome}</span>
                    <span class="cat-slug">${res.slug}</span>
                  </td>
                  <td class="text-center">
                    <a href="${BASE_URL}/admin/motos/modelos?montadora_id=${res.id}"
                       class="admin-badge admin-badge--muted">0 modelos</a>
                  </td>
                  <td class="text-center">
                    <button type="button"
                            class="admin-toggle admin-toggle--on"
                            data-id="${res.id}" data-type="montadora">
                      <span class="admin-toggle-track">
                        <span class="admin-toggle-thumb"></span>
                      </span>
                    </button>
                  </td>
                  <td>
                    <div class="admin-row-actions">
                      <a href="${BASE_URL}/montadora/${res.slug}" target="_blank"
                         class="btn btn-xs btn-ghost">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                          <path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/>
                          <polyline points="15 3 21 3 21 9"/>
                          <line x1="10" y1="14" x2="21" y2="3"/>
                        </svg>
                      </a>
                    </div>
                  </td>
                </tr>`);
            }
          }, 'json');
      });

      setTimeout(() => drawer.body().querySelector('#mont-nome')?.focus(), 200);
    });

  // ── Novo modelo ────────────────────────────────────────
  document.getElementById('btn-novo-modelo')
    ?.addEventListener('click', () => {
      const drawer = adminDrawer({
        titulo  : 'Novo modelo — ' + (window.MONTADORA_NOME || ''),
        tamanho : 'sm',
        conteudo: buildModeloForm(),
      });

      $(drawer.body()).on('click', '#btn-salvar-modelo', function () {
        const $btn = $(this).prop('disabled', true).text('Salvando...');
        $.post(BASE_URL + '/admin/motos/salvar-modelo',
          $(drawer.body().querySelector('#form-modelo')).serialize(),
          function (res) {
            $btn.prop('disabled', false).text('Criar modelo');
            if (!res.ok) { showToast(res.msg, 'error'); return; }
            showToast(res.msg, 'success');
            drawer.close();
            setTimeout(() => window.location.reload(), 500);
          }, 'json');
      });

      setTimeout(() => drawer.body().querySelector('#mod-nome')?.focus(), 200);
    });

  // ── Editar modelo ──────────────────────────────────────
  $(document).on('click', '.btn-editar-modelo', function () {
    const dados = {
      id           : $(this).data('id'),
      nome         : $(this).data('nome'),
      montadora_id : window.MONTADORA_ID_ATUAL,
      ativo        : 1,
    };
    const drawer = adminDrawer({
      titulo  : 'Editar modelo',
      tamanho : 'sm',
      conteudo: buildModeloForm(dados),
    });

    $(drawer.body()).on('click', '#btn-salvar-modelo', function () {
      const $btn = $(this).prop('disabled', true).text('Salvando...');
      $.post(BASE_URL + '/admin/motos/salvar-modelo',
        $(drawer.body().querySelector('#form-modelo')).serialize(),
        function (res) {
          $btn.prop('disabled', false).text('Salvar alterações');
          if (!res.ok) { showToast(res.msg, 'error'); return; }
          showToast(res.msg, 'success');
          drawer.close();

          // Atualiza o nome na tabela
          $(`tr[data-id="${dados.id}"] .cat-nome`).text(res.nome);
        }, 'json');
    });
  });

  // ── Toggle ativo (montadoras e modelos) ───────────────
  $(document).on('click', '.admin-toggle[data-type="montadora"], .admin-toggle[data-type="modelo"]', function () {
    const id   = $(this).data('id');
    const tipo = $(this).data('type');
    const $btn = $(this);

    $.post(BASE_URL + '/admin/motos/toggle-ativo', {
      id, tipo, _csrf_token: CSRF_TOKEN,
    }, function (res) {
      if (!res.ok) return;
      $btn.toggleClass('admin-toggle--on', !!res.ativo);
    }, 'json');
  });

})();

// ── Sincronização FIPE step-by-step ──────────────────────
(function () {
  const btnSinc    = document.getElementById('btn-sinc-fipe');
  const progressEl = document.getElementById('motos-sinc-progress');
  const fillEl     = document.getElementById('motos-progress-fill');
  const msgEl      = document.getElementById('motos-sinc-msg');
  const resultEl   = document.getElementById('motos-sinc-result');

  if (!btnSinc) return;

  // Estado global da sincronização
  let stats = { montadoras: 0, modelos: 0, anos: 0 };
  let abortado = false;

  // ── Helpers de UI ────────────────────────────────────
  function setProgresso(pct, msg) {
    if (fillEl) {
      // Remove animação indeterminada, usa progresso real
      fillEl.style.animation = 'none';
      fillEl.style.width     = Math.min(pct, 100) + '%';
      fillEl.style.transition= 'width .4s ease';
    }
    if (msgEl && msg) msgEl.textContent = msg;
  }

  function mostrarResultado(sucesso, statsFinais, erroMsg = '') {
    progressEl.style.display = 'none';
    btnSinc.disabled         = false;

    if (sucesso) {
      resultEl.innerHTML = `
        <div class="admin-card"
             style="background:var(--success-lt);border-color:var(--success);
                    margin-bottom:16px;">
          <div style="display:flex;align-items:center;gap:14px;">
            <div style="width:44px;height:44px;background:var(--success);
                 border-radius:50%;display:flex;align-items:center;
                 justify-content:center;flex-shrink:0;">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
                   stroke="white" stroke-width="3" stroke-linecap="round">
                <polyline points="20 6 9 17 4 12"/>
              </svg>
            </div>
            <div>
              <strong style="font-size:15px;color:var(--text);display:block;
                             margin-bottom:4px;">
                Sincronização concluída!
              </strong>
              <div style="display:flex;gap:18px;font-size:13px;color:var(--text-2);">
                <span>🏭 <strong>${statsFinais.montadoras}</strong> montadoras</span>
                <span>🏍 <strong>${statsFinais.modelos}</strong> modelos</span>
                <span>📅 <strong>${statsFinais.anos}</strong> anos</span>
              </div>
            </div>
          </div>
        </div>`;
      resultEl.style.display = '';
      showToast('Base FIPE sincronizada!', 'success');
      setTimeout(() => window.location.reload(), 2500);
    } else {
      resultEl.innerHTML = `
        <div class="admin-card"
             style="background:var(--danger-lt);border-color:var(--danger);
                    margin-bottom:16px;">
          <strong>Erro na sincronização:</strong>
          <p style="font-size:13px;margin-top:6px;">${erroMsg}</p>
        </div>`;
      resultEl.style.display = '';
      showToast('Erro na sincronização.', 'error');
    }
  }

  // ── Requisição POST com await ──────────────────────────
  async function post(url, dados) {
    const fd = new URLSearchParams({
      _csrf_token: CSRF_TOKEN,
      ...dados,
    });
    const res = await fetch(BASE_URL + url, {
      method : 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body   : fd.toString(),
    });
    return res.json();
  }

  // ── Fluxo principal ────────────────────────────────────
  async function executarSinc() {
    abortado = false;
    stats    = { montadoras: 0, modelos: 0, anos: 0 };

    btnSinc.disabled        = true;
    progressEl.style.display= '';
    resultEl.style.display  = 'none';

    // Modo indeterminado enquanto aguarda resposta inicial
    if (fillEl) {
      fillEl.style.animation = '';
      fillEl.style.width     = '0%';
    }

    try {
      // ── 1. Inicia log ──────────────────────────────────
      setProgresso(0, 'Iniciando sincronização...');
      await post('/admin/motos/sync/iniciar', {});

      // ── 2. Busca marcas ────────────────────────────────
      setProgresso(2, 'Buscando montadoras na FIPE...');
      const resMarcas = await post('/admin/motos/sync/marcas', {});

      if (!resMarcas.ok) throw new Error(resMarcas.msg || 'Erro ao buscar marcas.');

      const marcas = resMarcas.marcas;
      stats.montadoras = marcas.length;
      setProgresso(8, `${marcas.length} montadoras encontradas. Buscando modelos...`);

      // ── 3. Para cada marca: busca modelos e anos ───────
      // Cada marca = fatia de progresso entre 8% e 95%
      const fatiaMarca = 87 / Math.max(marcas.length, 1);

      for (let mi = 0; mi < marcas.length; mi++) {
        if (abortado) break;

        const marca   = marcas[mi];
        const pctBase = 8 + (mi * fatiaMarca);

        setProgresso(
          pctBase,
          `[${mi + 1}/${marcas.length}] ${marca.nome} — buscando modelos...`
        );

        // Busca modelos desta marca
        const resModelos = await post('/admin/motos/sync/modelos', {
          montadora_id: marca.id_local,
          fipe_code   : marca.fipe_code,
        });

        if (!resModelos.ok) {
          console.warn(`Erro em ${marca.nome}: ${resModelos.msg}`);
          continue; // Não interrompe — segue para a próxima marca
        }

        const modelos = resModelos.modelos;
        stats.modelos += modelos.length;

        // Busca anos de cada modelo
        const fatiaModelo = fatiaMarca / Math.max(modelos.length, 1);

        for (let moi = 0; moi < modelos.length; moi++) {
          if (abortado) break;

          const modelo = modelos[moi];
          const pct    = pctBase + ((moi + 1) * fatiaModelo);

          setProgresso(
            pct,
            `[${mi + 1}/${marcas.length}] ${marca.nome} › ${modelo.nome}`
          );

          const resAnos = await post('/admin/motos/sync/anos', {
            modelo_id        : modelo.id_local,
            fipe_code_marca  : marca.fipe_code,
            fipe_code_modelo : modelo.fipe_code_modelo,
          });

          if (resAnos.ok) {
            stats.anos += resAnos.total_anos || 0;
          }

          // Pequena pausa para não sobrecarregar
          await sleep(80);
        }

        await sleep(150); // pausa entre marcas
      }

      // ── 4. Finaliza ────────────────────────────────────
      setProgresso(98, 'Finalizando...');

      await post('/admin/motos/sync/finalizar', {
        montadoras: stats.montadoras,
        modelos   : stats.modelos,
        anos      : stats.anos,
        status    : abortado ? 'erro' : 'ok',
        erro      : abortado ? 'Abortado pelo usuário' : '',
      });

      setProgresso(100, 'Concluído!');
      await sleep(400);

      mostrarResultado(!abortado, stats, 'Abortado pelo usuário.');

    } catch (err) {
      await post('/admin/motos/sync/finalizar', {
        montadoras: stats.montadoras,
        modelos   : stats.modelos,
        anos      : stats.anos,
        status    : 'erro',
        erro      : err.message,
      }).catch(() => {});

      mostrarResultado(false, stats, err.message);
    }
  }

  function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
  }

  // ── Botão principal ────────────────────────────────────
  btnSinc.addEventListener('click', async () => {
    const ok = await adminConfirm({
      titulo   : 'Sincronizar base FIPE?',
      mensagem : 'O processo busca montadoras, modelos e anos em sequência. '
               + 'Pode levar alguns minutos.',
      tipo     : 'info',
      confirmar: 'Iniciar sincronização',
    });
    if (!ok) return;

    executarSinc();
  });

})();

// ── Admin: sincronização FIPE ─────────────────────────────
(function () {
  
  // ── Upload de thumb de montadora ────────────────────────
  let uploadTarget = null;
  const thumbInput = document.getElementById('motos-thumb-input');

  $(document).on('click', '.motos-thumb-upload-btn', function () {
    uploadTarget = {
      tipo: $(this).data('tipo'),
      id  : $(this).data('id'),
    };
    thumbInput.click();
  });

  thumbInput?.addEventListener('change', function () {
    if (!this.files[0] || !uploadTarget) return;

    const fd = new FormData();
    fd.append('tipo',        uploadTarget.tipo);
    fd.append('id',          uploadTarget.id);
    fd.append('thumb',       this.files[0]);
    fd.append('_csrf_token', CSRF_TOKEN);

    fetch(BASE_URL + '/admin/motos/thumb', {
      method: 'POST', body: fd,
    }).then(r => r.json()).then(res => {
      if (!res.ok) { showToast('Erro ao fazer upload.', 'error'); return; }

      const $wrap = $(`#thumb-mont-${uploadTarget.id}`);
      $wrap.find('.motos-thumb-img, .motos-thumb-empty').remove();
      $wrap.prepend(`<img src="${res.url}" alt="" class="motos-thumb-img">`);
      showToast('Imagem atualizada!', 'success');
      this.value = '';
    });
  });

  

})();
  
  // ── Toast notifications ───────────────────────────────────
  (function () {

    // Container de toasts
    function getContainer() {
      let el = document.getElementById('admin-toast-container');
      if (!el) {
        el = document.createElement('div');
        el.id = 'admin-toast-container';
        document.body.appendChild(el);
      }
      return el;
    }

    /**
     * Exibe uma notificação toast.
     * @param {string} msg     — Mensagem
     * @param {string} tipo    — 'success' | 'error' | 'warning' | 'info'
     * @param {object} acao    — { label, url } opcional
     * @param {number} duracao — ms (padrão: 4000)
     */
    window.showToast = function (msg, tipo = 'info', acao = null, duracao = 4000) {
      const container = getContainer();

      const cfg = {
        success : { icon: checkIcon(),   label: 'Sucesso'   },
        error   : { icon: xIcon(),       label: 'Erro'      },
        warning : { icon: warnIcon(),    label: 'Atenção'   },
        info    : { icon: infoIcon(),    label: 'Informação'},
      };
      const c = cfg[tipo] || cfg.info;

      const toast = document.createElement('div');
      toast.className = 'admin-toast admin-toast--' + tipo;

      toast.innerHTML = `
        <div class="admin-toast-icon">${c.icon}</div>
        <div class="admin-toast-body">
          <span class="admin-toast-label">${c.label}</span>
          <span class="admin-toast-msg">${msg}</span>
          ${acao ? `<a href="${acao.url}" class="admin-toast-action">${acao.label}</a>` : ''}
        </div>
        <button type="button" class="admin-toast-close" aria-label="Fechar">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
              stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <line x1="18" y1="6" x2="6" y2="18"/>
            <line x1="6"  y1="6" x2="18" y2="18"/>
          </svg>
        </button>
        <div class="admin-toast-progress"></div>
      `;

      container.appendChild(toast);

      // Anima entrada
      requestAnimationFrame(() => {
        requestAnimationFrame(() => toast.classList.add('visible'));
      });

      // Barra de progresso
      const progress = toast.querySelector('.admin-toast-progress');
      progress.style.transitionDuration = duracao + 'ms';
      setTimeout(() => progress.style.width = '0%', 50);

      // Fechar manual
      toast.querySelector('.admin-toast-close').addEventListener('click', () => {
        fechar(toast);
      });

      // Auto fechar
      const timer = setTimeout(() => fechar(toast), duracao);

      // Pausa no hover
      toast.addEventListener('mouseenter', () => {
        clearTimeout(timer);
        progress.style.animationPlayState = 'paused';
        progress.style.transitionDuration = '0ms';
      });

      return toast;
    };

    function fechar(toast) {
      toast.classList.remove('visible');
      toast.classList.add('hiding');
      setTimeout(() => toast.remove(), 350);
    }

    // ── SVG icons ─────────────────────────────────────────────
    function checkIcon() {
      return `<svg width="16" height="16" viewBox="0 0 24 24" fill="none"
        stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
        <polyline points="20 6 9 17 4 12"/></svg>`;
    }
    function xIcon() {
      return `<svg width="16" height="16" viewBox="0 0 24 24" fill="none"
        stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
        <line x1="18" y1="6" x2="6" y2="18"/>
        <line x1="6"  y1="6" x2="18" y2="18"/></svg>`;
    }
    function warnIcon() {
      return `<svg width="16" height="16" viewBox="0 0 24 24" fill="none"
        stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
        <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
        <line x1="12" y1="9" x2="12" y2="13"/>
        <line x1="12" y1="17" x2="12.01" y2="17"/></svg>`;
    }
    function infoIcon() {
      return `<svg width="16" height="16" viewBox="0 0 24 24" fill="none"
        stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
        <circle cx="12" cy="12" r="10"/>
        <line x1="12" y1="8"  x2="12" y2="12"/>
        <line x1="12" y1="16" x2="12.01" y2="16"/></svg>`;
    }

  })();

  // ── Admin: Marcas ────────────────────────────────────────
  (function () {
    if (!document.getElementById('marcas-table') && !document.getElementById('form-marca')) return;

    // Busca
    const $search = document.getElementById('marca-search');
    if ($search) {
      $search.addEventListener('input', function () {
        const q = this.value.toLowerCase();
        document.querySelectorAll('#marcas-table tbody tr').forEach(tr => {
          tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
        });
      });
    }

    // Toggle ativo
    $(document).on('click', '.admin-toggle[data-id]', function () {
      const id  = $(this).data('id');
      const url = window.location.href.includes('/marcas')
                  ? BASE_URL + '/admin/marcas/toggle-ativo'
                  : BASE_URL + '/admin/categorias/toggle-ativo';

      $.post(url, { id, _csrf_token: CSRF_TOKEN }, function (res) {
        if (!res.ok) return;
        const $btn = $(`.admin-toggle[data-id="${id}"]`);
        $btn.toggleClass('admin-toggle--on', res.ativo == 1);
      }, 'json');
    });

    // Excluir marca
    $(document).on('click', '.btn-excluir-marca', function () {
      const id   = $(this).data('id');
      const nome = $(this).data('nome');
      if (!confirm(`Excluir a marca "${nome}"?`)) return;

      $.post(BASE_URL + '/admin/marcas/excluir', {
        id, _csrf_token: CSRF_TOKEN,
      }, function (res) {
        if (res.ok) {
          $(`#marca-row-${id}`).fadeOut(250, function () { $(this).remove(); });
          showToast(res.msg, 'success');
        } else {
          showToast(res.msg, 'error');
        }
      }, 'json');
    });

    // ── Formulário ──────────────────────────────────────────
    const $form = document.getElementById('form-marca');
    if (!$form) return;

    // Auto slug
    const $nome = document.getElementById('marca-nome');
    const $slug = document.getElementById('marca-slug');
    if ($nome && $slug && !$slug.value) {
      $nome.addEventListener('input', function () {
        $slug.value = this.value.toLowerCase()
          .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
          .replace(/[^a-z0-9\s-]/g, '').replace(/\s+/g, '-').replace(/-+/g, '-').trim();
      });
    }

    // Contadores SEO
    document.querySelectorAll('.seo-counter').forEach(el => {
      const target = document.getElementById(el.dataset.target);
      const max    = parseInt(el.dataset.max);
      if (!target) return;
      const update = () => {
        const len = target.value.length;
        el.textContent = len + ' / ' + max + ' caracteres recomendados';
        el.style.color = len > max ? 'var(--danger)' : '';
      };
      target.addEventListener('input', update);
      update();
    });

    // Upload logo
    const uploadArea = document.getElementById('upload-area');
    const fileInput  = document.getElementById('marca-logo');
    if (uploadArea && fileInput) {
      uploadArea.addEventListener('click', () => fileInput.click());
      uploadArea.addEventListener('dragover', e => { e.preventDefault(); uploadArea.classList.add('upload-area--over'); });
      uploadArea.addEventListener('dragleave', () => uploadArea.classList.remove('upload-area--over'));
      uploadArea.addEventListener('drop', e => {
        e.preventDefault(); uploadArea.classList.remove('upload-area--over');
        if (e.dataTransfer.files[0]) previewLogo(e.dataTransfer.files[0]);
      });
      fileInput.addEventListener('change', function () {
        if (this.files[0]) previewLogo(this.files[0]);
      });
    }

    function previewLogo(file) {
      if (!file.type.startsWith('image/') && !file.name.endsWith('.svg')) return;
      const reader = new FileReader();
      reader.onload = e => {
        let wrap = document.getElementById('img-preview-wrap');
        if (!wrap) {
          wrap = document.createElement('div');
          wrap.id        = 'img-preview-wrap';
          wrap.className = 'admin-img-preview';
          wrap.innerHTML = `<img id="img-preview" style="max-width:100%;max-height:100px;object-fit:contain;display:block;margin:0 auto;border-radius:8px;">
            <button type="button" class="admin-img-remove" id="btn-remove-img">Remover logo</button>`;
          uploadArea.before(wrap);
        }
        document.getElementById('img-preview').src = e.target.result;
        uploadArea.style.display = 'none';
      };
      reader.readAsDataURL(file);
    }

    $(document).on('click', '#btn-remove-img', function () {
      $('#img-preview-wrap').remove();
      if (fileInput) fileInput.value = '';
      if (uploadArea) uploadArea.style.display = '';
    });

    // Submit
    $($form).on('submit', function (e) {
      e.preventDefault();
      const $btn = $('#btn-salvar');
      $btn.prop('disabled', true).text('Salvando...');

      fetch(BASE_URL + '/admin/marcas/salvar', {
        method : 'POST',
        body   : new FormData(this),
      })
      .then(r => r.json())
      .then(res => {
        $btn.prop('disabled', false).text('Salvar');
        if (res.ok) {
          showToast(res.msg, 'success');
          setTimeout(() => window.location.href = BASE_URL + '/admin/marcas', 800);
        } else {
          showToast(res.msg, 'error');
        }
      });
    });

    const pickerInput = document.getElementById('marca-bg-cor');
    const hexInput    = document.getElementById('marca-bg-cor-hex');
    const preview     = document.getElementById('color-preview');
    const btnClear    = document.getElementById('btn-clear-color');

    if (!pickerInput) return;

    // Color picker → hex input + preview
    pickerInput.addEventListener('input', function () {
      hexInput.value    = this.value.toUpperCase();
      preview.style.backgroundColor = this.value;
    });

    // Hex input → color picker + preview
    hexInput.addEventListener('input', function () {
      const val = this.value.trim();
      if (/^#[0-9a-fA-F]{6}$/.test(val)) {
        pickerInput.value             = val;
        preview.style.backgroundColor = val;
      }
    });

    // Limpar cor
    btnClear.addEventListener('click', function () {
      pickerInput.value             = '#ffffff';
      hexInput.value                = '#ffffff';
      preview.style.backgroundColor = '#ffffff';
    });

  })();

  // ═══════════════════════════════════════════════════════
  // PRODUCT EDITOR — admin/assets/js/admin.js
  // ═══════════════════════════════════════════════════════

  (function () {
    const $form = document.getElementById('form-produto');
    if (!$form) return;

    const produtoId = () => parseInt(document.getElementById('produto-id')?.value || 0);

    // ── Navegação lateral sticky ─────────────────────────
    const navItems  = document.querySelectorAll('.pe-nav-item[data-section]');
    const sections  = document.querySelectorAll('.pe-section');
    const topbar    = document.getElementById('peTopbar');

    function highlightNav() {
      let current = '';
      sections.forEach(sec => {
        const top = sec.getBoundingClientRect().top;
        if (top <= 100) current = sec.id.replace('pe-', '');
      });
      navItems.forEach(item => {
        item.classList.toggle('active', item.dataset.section === current);
      });
      if (topbar) {
        topbar.classList.toggle('scrolled', window.scrollY > 10);
      }
    }
    window.addEventListener('scroll', highlightNav, { passive: true });

    navItems.forEach(item => {
      item.addEventListener('click', e => {
        e.preventDefault();
        const target = document.getElementById('pe-' + item.dataset.section);
        if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    });

    // ── Status toggle (sidebar ↔ campo) ─────────────────
    const quickAtivo = document.getElementById('quick-ativo');
    const campoAtivo = document.getElementById('campo-ativo');
    const peAtivoConfig = document.getElementById('pe-ativo-config');

    function syncAtivo(val) {
      const isAtivo = !!val;
      if (quickAtivo)     quickAtivo.checked = isAtivo;
      if (campoAtivo)     campoAtivo.value   = isAtivo ? 1 : 0;
      if (peAtivoConfig)  peAtivoConfig.checked = isAtivo;
      const label = document.getElementById('quick-ativo-label');
      if (label) label.textContent = isAtivo ? 'Ativo' : 'Inativo';
    }

    quickAtivo?.addEventListener('change', () => syncAtivo(quickAtivo.checked));
    peAtivoConfig?.addEventListener('change', () => syncAtivo(peAtivoConfig.checked));

    // ── Nome → slug + preview topbar ────────────────────
    const nomeInput = document.getElementById('pe-nome');
    const slugInput = document.getElementById('pe-slug');
    const titlePreview = document.getElementById('pe-title-preview');
    const nomeCount  = document.getElementById('nome-count');

    function slugify(str) {
      return str.toLowerCase()
        .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9\s-]/g, '')
        .replace(/\s+/g, '-').replace(/-+/g, '-').trim();
    }

    nomeInput?.addEventListener('input', function () {
      if (titlePreview) titlePreview.textContent = this.value || 'Novo produto';
      if (nomeCount)    nomeCount.textContent = this.value.length + ' caracteres';

      // Só auto-gera slug se ainda não foi editado manualmente
      if (!slugInput._manuallyEdited && slugInput) {
        slugInput.value = slugify(this.value);
        updateSeoPreview();
      }

      // SEO preview
      const metaTitle = document.getElementById('pe-meta-title');
      if (metaTitle && !metaTitle.value) {
        document.getElementById('seo-preview-title').textContent = this.value;
      }
    });

    slugInput?.addEventListener('input', function () {
      this._manuallyEdited = true;
      updateSeoPreview();
    });

    document.getElementById('btn-regen-slug')?.addEventListener('click', () => {
      if (nomeInput && slugInput) {
        slugInput.value = slugify(nomeInput.value);
        slugInput._manuallyEdited = false;
        updateSeoPreview();
      }
    });

    // ── Desc curta contador ──────────────────────────────
    const descCurta = document.getElementById('pe-desc-curta');
    const descCount = document.getElementById('desc-curta-count');
    descCurta?.addEventListener('input', function () {
      if (descCount) descCount.textContent = this.value.length + ' / 300';
      const metaDesc = document.getElementById('pe-meta-desc');
      if (metaDesc && !metaDesc.value) {
        document.getElementById('seo-preview-desc').textContent = this.value;
      }
    });

    // ── Preço → desconto badge ───────────────────────────
    const precoInput = document.getElementById('pe-preco');
    const promoInput = document.getElementById('pe-preco-promo');
    const discBadge  = document.getElementById('pe-discount-val');
    const promoPer   = document.getElementById('pe-promo-periodo');

    function updateDesconto() {
      const preco = parseFloat(precoInput?.value) || 0;
      const promo = parseFloat(promoInput?.value) || 0;

      if (promo > 0 && promo < preco) {
        const pct = Math.round(((preco - promo) / preco) * 100);
        if (discBadge) discBadge.textContent = '-' + pct + '%';
        if (promoPer)  promoPer.style.display = '';
      } else {
        if (discBadge) discBadge.textContent = '—';
        if (promoPer)  promoPer.style.display = 'none';
      }
    }

    precoInput?.addEventListener('input', updateDesconto);
    promoInput?.addEventListener('input', updateDesconto);
    updateDesconto();

    // ── Estoque status ───────────────────────────────────
    const estoqueInput  = document.getElementById('pe-estoque');
    const estoqueMin    = document.getElementById('pe-estoque-min');
    const estoqueInd    = document.querySelector('.pe-estoque-indicator');
    const estoqueLabel  = document.getElementById('pe-estoque-label');

    function updateEstoque() {
      const total = parseInt(estoqueInput?.value) || 0;
      const min   = parseInt(estoqueMin?.value)   || 0;
      if (!estoqueInd) return;

      if (total === 0) {
        estoqueInd.className  = 'pe-estoque-indicator zerado';
        estoqueLabel.textContent = 'Sem estoque';
      } else if (total <= min) {
        estoqueInd.className  = 'pe-estoque-indicator baixo';
        estoqueLabel.textContent = 'Estoque baixo';
      } else {
        estoqueInd.className  = 'pe-estoque-indicator ok';
        estoqueLabel.textContent = 'Em estoque';
      }
    }

    estoqueInput?.addEventListener('input', updateEstoque);
    estoqueMin?.addEventListener('input', updateEstoque);
    updateEstoque();

    // Botões +/-
    document.querySelectorAll('.pe-estoque-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const op  = btn.dataset.op;
        const val = parseInt(estoqueInput?.value) || 0;
        if (estoqueInput) {
          estoqueInput.value = op === 'plus' ? val + 1 : Math.max(0, val - 1);
          updateEstoque();
        }
      });
    });

    // ── Dimensões → cubo volumétrico ────────────────────
    function updateFrete() {
      const peso = parseFloat(document.querySelector('[name="peso_kg"]')?.value)   || 0;
      const comp = parseFloat(document.querySelector('[name="comprimento_cm"]')?.value) || 0;
      const larg = parseFloat(document.querySelector('[name="largura_cm"]')?.value) || 0;
      const alt  = parseFloat(document.querySelector('[name="altura_cm"]')?.value)  || 0;
      const info = document.getElementById('pe-frete-info');
      if (!info) return;

      if (peso > 0 || (comp > 0 && larg > 0 && alt > 0)) {
        const cubo = (comp * larg * alt) / 6000;
        const pesoTaxado = Math.max(peso, cubo);
        info.textContent =
          'Peso real: ' + peso.toFixed(3) + 'kg | '
          + 'Cubo volumétrico: ' + cubo.toFixed(3) + 'kg | '
          + 'Peso taxado: ' + pesoTaxado.toFixed(3) + 'kg';
      } else {
        info.textContent = 'Preencha o peso e dimensões para ver o cubo volumétrico.';
      }
    }

    document.querySelectorAll('[name="peso_kg"],[name="comprimento_cm"],[name="largura_cm"],[name="altura_cm"]')
      .forEach(el => el.addEventListener('input', updateFrete));
    updateFrete();

    // ── SEO preview + barras ─────────────────────────────
    const metaTitleInput = document.getElementById('pe-meta-title');
    const metaDescInput  = document.getElementById('pe-meta-desc');

    function updateSeoBar(inputEl, barId, countId, max) {
      const bar   = document.getElementById(barId);
      const count = document.getElementById(countId);
      if (!bar || !count || !inputEl) return;
      const len = inputEl.value.length;
      const pct = Math.min((len / max) * 100, 100);
      bar.style.setProperty('--pct', pct + '%');
      bar.classList.toggle('warn', len > max);
      bar.classList.toggle('over', len > max * 1.1);
      count.textContent = len + ' / ' + max;
    }

    function updateSeoPreview() {
      const title = metaTitleInput?.value || nomeInput?.value || '';
      const desc  = metaDescInput?.value  || descCurta?.value || '';
      const slug  = slugInput?.value || '';

      const previewTitle = document.getElementById('seo-preview-title');
      const previewDesc  = document.getElementById('seo-preview-desc');
      const previewUrl   = document.getElementById('seo-preview-url');

      if (previewTitle) previewTitle.textContent = title || 'Título do produto';
      if (previewDesc)  previewDesc.textContent  = desc  || 'Descrição para resultados de busca...';
      if (previewUrl)   previewUrl.textContent   = BASE_URL + '/produto/' + (slug || 'slug');

      updateSeoBar(metaTitleInput, 'seo-bar-title', 'seo-count-title', 90);
      updateSeoBar(metaDescInput,  'seo-bar-desc',  'seo-count-desc',  256);
    }

    metaTitleInput?.addEventListener('input', updateSeoPreview);
    metaDescInput?.addEventListener('input', updateSeoPreview);
    updateSeoPreview();

    // ── Variações toggle ─────────────────────────────────
    const temVariacao = document.getElementById('pe-tem-variacao');
    const varPanel    = document.getElementById('pe-variacao-panel');

    temVariacao?.addEventListener('change', function () {
      if (varPanel) varPanel.style.display = this.checked ? '' : 'none';
    });

    // ── Upload de imagens ────────────────────────────────
    const uploadArea = document.getElementById('pe-upload-area');
    const imgInput   = document.getElementById('pe-img-input');
    const gallery    = document.getElementById('pe-gallery');

    uploadArea?.addEventListener('click', e => {
      if (!e.target.closest('button')) imgInput?.click();
    });

    uploadArea?.addEventListener('dragover', e => {
      e.preventDefault();
      uploadArea.classList.add('drag-over');
    });
    uploadArea?.addEventListener('dragleave', () => uploadArea.classList.remove('drag-over'));
    uploadArea?.addEventListener('drop', e => {
      e.preventDefault();
      uploadArea.classList.remove('drag-over');
      uploadFiles(Array.from(e.dataTransfer.files));
    });

    imgInput?.addEventListener('change', function () {
      uploadFiles(Array.from(this.files));
      this.value = '';
    });

    async function uploadFiles(files) {
      for (const file of files) {
        if (!file.type.startsWith('image/')) continue;
        if (file.size > 5 * 1024 * 1024) {
          showToast(file.name + ': máx. 5MB', 'error'); continue;
        }
        await uploadSingleFile(file);
      }
    }

    async function uploadSingleFile(file) {
      const skeleton = document.createElement('div');
      skeleton.className = 'pe-gallery-skeleton';
      gallery?.appendChild(skeleton);

      const fd = new FormData();
      fd.append('imagem',      file);
      fd.append('produto_id',  produtoId());
      fd.append('_csrf_token', CSRF_TOKEN);

      try {
        const res  = await fetch(BASE_URL + '/admin/produtos/upload-imagem', { method: 'POST', body: fd });
        const data = await res.json();
        skeleton.remove();

        if (!data.ok) { showToast(data.msg, 'error'); return; }

        addGalleryItem(data);
      } catch {
        skeleton.remove();
        showToast('Erro ao enviar imagem.', 'error');
      }
    }

    function addGalleryItem(data) {
      const div = document.createElement('div');
      div.className = 'pe-gallery-item' + (data.principal ? ' is-principal' : '');
      div.dataset.id = data.id;
      div.innerHTML = `
        <img src="${data.url}" alt="" loading="lazy">
        <div class="pe-gallery-overlay">
          ${!data.principal ? `<button type="button" class="pe-gallery-btn pe-set-principal"
                  data-id="${data.id}" title="Definir como principal">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2.5" stroke-linecap="round">
              <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
            </svg>
          </button>` : ''}
          <button type="button" class="pe-gallery-btn pe-gallery-btn--del pe-del-img"
                  data-id="${data.id}" title="Remover">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2.5" stroke-linecap="round">
              <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
            </svg>
          </button>
        </div>
        ${data.principal ? '<span class="pe-gallery-badge">Principal</span>' : ''}`;
      gallery?.appendChild(div);
    }

    // Definir principal
    $(document).on('click', '.pe-set-principal', function () {
      const imgId = $(this).data('id');
      $.post(BASE_URL + '/admin/produtos/set-principal', {
        imagem_id: imgId, produto_id: produtoId(), _csrf_token: CSRF_TOKEN,
      }, function (res) {
        if (!res.ok) return;
        document.querySelectorAll('.pe-gallery-item').forEach(el => {
          el.classList.remove('is-principal');
          el.querySelector('.pe-gallery-badge')?.remove();
        });
        const item = document.querySelector(`.pe-gallery-item[data-id="${imgId}"]`);
        if (item) {
          item.classList.add('is-principal');
          item.insertAdjacentHTML('beforeend', '<span class="pe-gallery-badge">Principal</span>');
          item.querySelector('.pe-set-principal')?.remove();
        }
        showToast('Imagem principal definida!', 'success');
      }, 'json');
    });

    // Remover imagem
    $(document).on('click', '.pe-del-img', function () {
      const imgId = $(this).data('id');
      const item  = document.querySelector(`.pe-gallery-item[data-id="${imgId}"]`);
      if (!confirm('Remover esta imagem?')) return;

      $.post(BASE_URL + '/admin/produtos/remover-imagem', {
        imagem_id: imgId, _csrf_token: CSRF_TOKEN,
      }, function (res) {
        if (res.ok) {
          item?.remove();
          showToast('Imagem removida.', 'info');
        }
      }, 'json');
    });

    


// ── Modal agrupador 
    
// ── Agrupadores ───────────────────────────────────────────

  if (document.getElementById('pe-agrupadores-list')){;

    const valoresPorTipo = window.ATRIBUTOS_VALORES  || {};
    // const tiposAg        = (window.ATRIBUTOS_VARIACAO || []).filter(t => t.papel === 'agrupador');
    const tiposAg = window.ATRIBUTOS_AGRUPADOR || [];

    // Estado do modal
    let agTipoIdAtual   = null;
    let agTipoNomeAtual = '';
    let agDisplayAtual  = 'button';
    let agModo          = 'novo'; // 'novo' | 'editar'

    const modal       = document.getElementById('modal-agrupador');
    const opcoesEl    = document.getElementById('modal-ag-opcoes');
    const textoEl     = document.getElementById('modal-ag-texto');
    const corGroup    = document.getElementById('modal-ag-cor-group');
    const valorArea   = document.getElementById('modal-ag-valor-area');
    const tipoGroup   = document.getElementById('modal-ag-tipo-group');
    const tipoPick    = document.getElementById('modal-tipo-atributo');

    // ── Abre modal para NOVO agrupador ─────────────────────
    document.getElementById('btn-add-agrupador')
      ?.addEventListener('click', () => {
        agModo = 'novo';
        agTipoIdAtual = null;
        if (tipoPick) tipoPick.value = '';
        if (tipoGroup) tipoGroup.style.display = '';
        if (valorArea) valorArea.style.display = 'none';
        if (textoEl)   textoEl.value = '';
        document.getElementById('modal-ag-titulo').textContent = 'Adicionar atributo';
        modal.style.display = 'flex';
        setTimeout(() => tipoPick?.focus(), 100);
      });

    // ── Abre modal para EDITAR agrupador existente ─────────
    $(document).on('click', '.pe-ag-edit-btn', function () {
      agModo          = 'editar';
      agTipoIdAtual   = $(this).data('tipo-id');
      agTipoNomeAtual = $(this).data('tipo-nome');
      agDisplayAtual  = $(this).data('tipo-display');

      const valorAtual = $(`#pe-agrupadores-list .pe-attr-row[data-tipo-id="${agTipoIdAtual}"]`)
                          .find('.pe-ag-hidden').val() || '';
      const hexAtual   = $(`#pe-agrupadores-list .pe-attr-row[data-tipo-id="${agTipoIdAtual}"]`)
                          .find('.pe-ag-hex').val() || '';

      // Esconde seletor de tipo — já está fixo
      if (tipoGroup) tipoGroup.style.display = 'none';

      document.getElementById('modal-ag-titulo').textContent =
        agTipoNomeAtual + ' — Selecionar valor';

      // Renderiza opções
      renderizarOpcoesModal(agTipoIdAtual, agDisplayAtual, valorAtual);

      // Preenche input texto e cor
      if (textoEl) textoEl.value = valorAtual;
      if (hexAtual) {
        document.getElementById('modal-ag-cor-picker').value = hexAtual;
        document.getElementById('modal-ag-cor-hex').value    = hexAtual.toUpperCase();
      }

      modal.style.display = 'flex';
      setTimeout(() => textoEl?.focus(), 100);
    });

    // ── Ao selecionar tipo no modo "novo" ──────────────────
    tipoPick?.addEventListener('change', function () {
      const opt = this.options[this.selectedIndex];
      agTipoIdAtual   = this.value;
      agTipoNomeAtual = opt?.dataset.nome    || '';
      agDisplayAtual  = opt?.dataset.display || 'button';

      if (!agTipoIdAtual) {
        valorArea.style.display = 'none';
        return;
      }

      // Verifica duplicata
      if (document.querySelector(
        `#pe-agrupadores-list .pe-attr-row[data-tipo-id="${agTipoIdAtual}"]`
      )) {
        showToast('Este atributo já foi adicionado.', 'warning');
        this.value = '';
        valorArea.style.display = 'none';
        return;
      }

      if (textoEl) textoEl.value = '';

      // Lê em runtime
      renderizarOpcoesModal(agTipoIdAtual, agDisplayAtual, '');
    });


    // ── Renderiza os botões/swatches no modal ──────────────
        // No IIFE de agrupadores, remova a linha do topo:
    // const valoresPorTipo = window.ATRIBUTOS_VALORES_AG || {};  ← REMOVER

    // E dentro de renderizarOpcoesModal, leia em tempo real:
    function renderizarOpcoesModal(tipoId, display, valorSel) {
      // Lê sempre no momento da chamada
      const valoresPorTipo = window.ATRIBUTOS_VALORES_AG || {};
      const valores = valoresPorTipo[tipoId] || [];

      valorArea.style.display = '';
      corGroup.style.display  = display === 'color_swatch' ? '' : 'none';

      if (valores.length === 0) {
        opcoesEl.style.display = 'none';
        opcoesEl.innerHTML     = '';

        // Mostra aviso de que não há valores pré-definidos
        document.getElementById('modal-ag-sem-valores').style.display = '';
      } else {
        document.getElementById('modal-ag-sem-valores').style.display = 'none';
        opcoesEl.style.display = '';
        opcoesEl.innerHTML = valores.map(v => {
          const isSel = v.valor === valorSel;
          if (display === 'color_swatch' && v.valor_hex) {
            return `<button type="button"
                            class="pe-sku-swatch-btn pe-modal-ag-opt ${isSel ? 'selected' : ''}"
                            data-valor="${v.valor}" data-hex="${v.valor_hex}"
                            style="background:${v.valor_hex}" title="${v.valor}">
              ${isSel ? checkSvg() : ''}
            </button>`;
          }
          return `<button type="button"
                          class="pe-sku-opt-btn pe-modal-ag-opt ${isSel ? 'selected' : ''}"
                          data-valor="${v.valor}" data-hex="">
            ${v.valor}
          </button>`;
        }).join('');
      }
    }

    function checkSvg() {
      return `<svg width="10" height="10" viewBox="0 0 24 24" fill="none"
                  stroke="white" stroke-width="3.5" stroke-linecap="round">
                <polyline points="20 6 9 17 4 12"/>
              </svg>`;
    }

    // ── Clique em opção no modal ───────────────────────────
    $(document).on('click', '.pe-modal-ag-opt', function () {
      const $btn    = $(this);
      const isSel   = $btn.hasClass('selected');
      const display = opcoesEl.dataset.display;

      // Limpa todos
      $('.pe-modal-ag-opt').each(function () {
        $(this).removeClass('selected');
        if ($(this).hasClass('pe-sku-swatch-btn')) $(this).html('');
      });

      if (isSel) { textoEl.value = ''; return; }

      const valor = $btn.data('valor');
      const hex   = $btn.data('hex') || '';

      $btn.addClass('selected');
      if (display === 'color_swatch') {
        $btn.html(checkSvg());
      }

      textoEl.value = valor;

      if (hex) {
        document.getElementById('modal-ag-cor-picker').value = hex;
        document.getElementById('modal-ag-cor-hex').value    = hex.toUpperCase();
      }
    });

    // Input livre → destaca botão correspondente
    textoEl?.addEventListener('input', function () {
      const val = this.value.trim();
      $('.pe-modal-ag-opt').each(function () {
        const isMatch = $(this).data('valor') === val;
        $(this).toggleClass('selected', isMatch);
        if ($(this).hasClass('pe-sku-swatch-btn')) {
          $(this).html(isMatch ? checkSvg() : '');
        }
      });
    });

    // Color picker sync
    document.getElementById('modal-ag-cor-picker')?.addEventListener('input', function () {
      document.getElementById('modal-ag-cor-hex').value = this.value.toUpperCase();
    });
    document.getElementById('modal-ag-cor-hex')?.addEventListener('input', function () {
      if (/^#[0-9a-fA-F]{6}$/.test(this.value)) {
        document.getElementById('modal-ag-cor-picker').value = this.value;
      }
    });

    // ── Fechar modal ───────────────────────────────────────
    $('#btn-close-modal-agrupador, #btn-cancelar-agrupador').on('click', () => {
      modal.style.display = 'none';
    });

    // ── Confirmar ──────────────────────────────────────────
    document.getElementById('btn-confirmar-agrupador')
      ?.addEventListener('click', () => {
        // Resolve tipoId dependendo do modo
        const tipoId = agModo === 'editar'
                      ? agTipoIdAtual
                      : (tipoPick?.value || null);

        if (!tipoId) { showToast('Selecione o tipo.', 'error'); return; }

        const valor = textoEl?.value.trim();
        if (!valor) { showToast('Selecione ou digite um valor.', 'error'); return; }

        const hex   = document.getElementById('modal-ag-cor-hex')?.value || '';
        const opt   = tipoPick?.options[tipoPick.selectedIndex];
        const nome  = agModo === 'editar' ? agTipoNomeAtual : (opt?.dataset.nome || '');
        const disp  = agModo === 'editar' ? agDisplayAtual  : (opt?.dataset.display || 'button');

        if (agModo === 'editar') {
          // Atualiza a linha existente
          const $row = $(`#pe-agrupadores-list .pe-attr-row[data-tipo-id="${tipoId}"]`);
          $row.find('.pe-ag-hidden').val(valor);
          $row.find('.pe-ag-hex').val(hex);
          atualizarBadge(tipoId, valor, hex, disp);

        } else {
          // Verifica duplicata
          if (document.querySelector(`#pe-agrupadores-list .pe-attr-row[data-tipo-id="${tipoId}"]`)) {
            showToast('Este atributo já foi adicionado.', 'warning');
            return;
          }
          // Cria nova linha
          adicionarLinhaAgrupador(tipoId, nome, disp, valor, hex);
        }

        modal.style.display = 'none';
        if (tipoPick) tipoPick.value = '';
      });

    // ── Atualiza badge na linha ────────────────────────────
    function atualizarBadge(tipoId, valor, hex, display) {
      const $wrap = $(`#ag-badge-${tipoId}`);
      let   inner = '';

      if (display === 'color_swatch' && hex) {
        inner = `<span class="pe-sku-badge-swatch" style="background:${hex}"></span>`;
      }
      inner += `<span class="pe-sku-badge-valor">${valor}</span>`;

      $wrap.html(`<span class="pe-sku-badge">${inner}</span>`);
    }

    // ── Cria linha de agrupador (modo novo) ────────────────
    function adicionarLinhaAgrupador(tipoId, nome, display, valor, hex) {
      let badgeInner = '';
      if (display === 'color_swatch' && hex) {
        badgeInner += `<span class="pe-sku-badge-swatch" style="background:${hex}"></span>`;
      }
      badgeInner += `<span class="pe-sku-badge-valor">${valor}</span>`;

      const row = document.createElement('div');
      row.className      = 'pe-attr-row';
      row.dataset.tipoId = tipoId;
      row.innerHTML = `
        <div class="pe-attr-tipo">
          <span>${nome}</span>
          <span class="pe-attr-display-type">${display}</span>
        </div>
        <div class="pe-attr-valor-wrap">
          <input type="hidden" class="pe-ag-hidden"
                data-tipo-id="${tipoId}" value="${valor}">
          <input type="hidden" class="pe-ag-hex"
                data-tipo-id="${tipoId}" value="${hex}">
          <div class="pe-ag-badge-wrap" id="ag-badge-${tipoId}">
            <span class="pe-sku-badge">${badgeInner}</span>
          </div>
          <button type="button"
                  class="pe-sku-attrs-btn pe-ag-edit-btn"
                  data-tipo-id="${tipoId}"
                  data-tipo-nome="${nome}"
                  data-tipo-display="${display}"
                  title="Editar valor">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
              <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
              <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
            </svg>
            Editar
          </button>
        </div>
        <button type="button" class="pe-attr-del">×</button>`;

      document.getElementById('pe-agrupadores-list')?.appendChild(row);
    }

    // ── Deletar agrupador ──────────────────────────────────
    $(document).on('click', '.pe-attr-del', function () {
      $(this).closest('.pe-attr-row').slideUp(200, function () {
        $(this).remove();
      });
    });
  }

    


    // const modalAg  = document.getElementById('modal-agrupador');
    // const tipoPick = document.getElementById('modal-tipo-atributo');
    // const corGroup = document.getElementById('modal-cor-group');
    // const corPicker= document.getElementById('modal-cor-picker');
    // const corHex   = document.getElementById('modal-cor-hex');

    // // document.getElementById('btn-add-agrupador')?.addEventListener('click', () => {
    // //   if (modalAg) modalAg.style.display = 'flex';
    // // });
    // document.getElementById('btn-close-modal-agrupador')?.addEventListener('click', () => {
    //   if (modalAg) modalAg.style.display = 'none';
    // });
    // document.getElementById('btn-cancelar-agrupador')?.addEventListener('click', () => {
    //   if (modalAg) modalAg.style.display = 'none';
    // });

    // tipoPick?.addEventListener('change', function () {
    //   const opt    = this.options[this.selectedIndex];
    //   const display = opt.dataset.display || '';
    //   corGroup.style.display = display === 'color_swatch' ? '' : 'none';
    // });

    // corPicker?.addEventListener('input', () => {
    //   corHex.value = corPicker.value.toUpperCase();
    // });
    // corHex?.addEventListener('input', () => {
    //   if (/^#[0-9a-fA-F]{6}$/.test(corHex.value)) corPicker.value = corHex.value;
    // });

    // document.getElementById('btn-confirmar-agrupador')?.addEventListener('click', () => {
    //   const tipoId = tipoPick?.value;
    //   const tipoNome = tipoPick?.options[tipoPick.selectedIndex]?.dataset.nome || '';
    //   const display  = tipoPick?.options[tipoPick.selectedIndex]?.dataset.display || '';
    //   const valor    = document.getElementById('modal-valor-input')?.value.trim();
    //   const hex      = corHex?.value;

    //   if (!tipoId || !valor) {
    //     showToast('Preencha o tipo e o valor.', 'error'); return;
    //   }

    //   const list = document.getElementById('pe-agrupadores-list');
    //   const row  = document.createElement('div');
    //   row.className   = 'pe-attr-row';
    //   row.dataset.tipoId = tipoId;
    //   row.innerHTML = `
    //     <div class="pe-attr-tipo">
    //       <span>${tipoNome}</span>
    //       <span class="pe-attr-display-type">${display}</span>
    //     </div>
    //     <div class="pe-attr-valor">
    //       <input type="text" class="form-control form-control--sm" value="${valor}">
    //       ${display === 'color_swatch' ? `<input type="color" class="pe-color-swatch-input" value="${hex}">` : ''}
    //     </div>
    //     <button type="button" class="pe-attr-del">×</button>`;
    //   list?.appendChild(row);

    //   modalAg.style.display = 'none';
    //   document.getElementById('modal-valor-input').value = '';
    //   tipoPick.value = '';
    // });

    // // Deletar agrupador
    // $(document).on('click', '.pe-attr-del', function () {
    //   $(this).closest('.pe-attr-row').remove();
    // });

    // ── Adicionar SKU ────────────────────────────────────
    

    // ── Salvar produto ───────────────────────────────────
    function salvarProduto(publicar = true) {
      const $btn = publicar
        ? document.getElementById('btn-salvar-produto')
        : document.getElementById('btn-salvar-rascunho');

      if ($btn) { $btn.disabled = true; $btn.textContent = 'Salvando...'; }

      // Sincroniza status
      campoAtivo.value = publicar ? (quickAtivo?.checked ? 1 : 0) : 0;

      const fd = new FormData($form);

      // Atributos agrupadores
      document.querySelectorAll('.pe-attr-row').forEach((row, i) => {
        fd.append('agrupadores[' + i + '][tipo_id]', row.dataset.tipoId);
        fd.append('agrupadores[' + i + '][valor]',
                  row.querySelector('input[type="text"]')?.value || '');
        const colorInput = row.querySelector('input[type="color"]');
        if (colorInput) fd.append('agrupadores[' + i + '][valor_hex]', colorInput.value);
      });

      // No salvarProduto(), substitua o bloco de agrupadores por:
      // Agrupadores — lê dos hiddens (fonte de verdade)
      document.querySelectorAll('#pe-agrupadores-list .pe-attr-row').forEach((row, i) => {
        const tipoId = row.dataset.tipoId;
        const valor  = row.querySelector('.pe-ag-hidden')?.value?.trim();
        const hex    = row.querySelector('.pe-ag-color')?.value || '';

        if (!tipoId || !valor) return;

        fd.append(`agrupadores[${i}][tipo_id]`,   tipoId);
        fd.append(`agrupadores[${i}][valor]`,      valor);
        if (hex) fd.append(`agrupadores[${i}][valor_hex]`, hex);
      });

      // ── Garante unicidade da categoria principal ──────────────
      const inputsPrincipal = document.querySelectorAll('.prod-cat-principal-input');
      const starPrincipal   = document.querySelector('.cat-star-btn.is-principal');
      const idPrincipal     = starPrincipal?.dataset.id || null;

      inputsPrincipal.forEach(inp => {
          // Extrai o id da categoria pelo name: categorias[{id}][principal]
          const match = inp.name.match(/categorias\[(\d+)\]\[principal\]/);
          const catId = match ? match[1] : null;
          inp.value   = (catId && catId === idPrincipal) ? 1 : 0;
      });

      // Se nenhuma tem estrela (situação de fallback), marca a primeira
      if (!idPrincipal && inputsPrincipal.length > 0) {
          inputsPrincipal[0].value = 1;
      }

      fetch(BASE_URL + '/admin/produtos/salvar', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
          if ($btn) {
            $btn.disabled = false;
            $btn.textContent = produtoId() > 0 ? 'Salvar alterações' : 'Publicar produto';
          }
          if (!res.ok) { showToast(res.msg, 'error');            
            // Destaca o campo de preço se for erro de preço
            if (res.msg.toLowerCase().includes('preço')) {
              const $preco = $('#pe-preco');
              $preco.addClass('input-error').focus();
              setTimeout(() => $preco.removeClass('input-error'), 3000);
            }
            return;; 
          }

          // Atualiza ID e slug se for criação
          if (res.id && !produtoId()) {
            document.getElementById('produto-id').value = res.id;
            window.history.replaceState({}, '', BASE_URL + '/admin/produtos/' + res.id + '/editar');
          }

          showToast(res.msg, 'success');

          // Atualiza preview
          if (res.slug && slugInput) slugInput.value = res.slug;
          const urlEl = document.getElementById('seo-preview-url');
          if (urlEl && res.slug) urlEl.textContent = BASE_URL + '/produto/' + res.slug;
        })
        .catch(() => {
          if ($btn) { $btn.disabled = false; }
          showToast('Erro de conexão.', 'error');
        });
    }

    document.getElementById('btn-salvar-produto')?.addEventListener('click', () => salvarProduto(true));
    document.getElementById('btn-salvar-rascunho')?.addEventListener('click', () => {
      if (quickAtivo) quickAtivo.checked = false;
      syncAtivo(false);
      salvarProduto(false);
    });

    // Ctrl+S / Cmd+S
    document.addEventListener('keydown', e => {
      if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        salvarProduto(true);
      }
    });

    // ── Excluir produto ──────────────────────────────────
    document.getElementById('btn-excluir-produto')?.addEventListener('click', function () {
      const id   = this.dataset.id;
      const nome = this.dataset.nome;
      if (!confirm('Excluir "' + nome + '"? Esta ação não pode ser desfeita.')) return;

      $.post(BASE_URL + '/admin/produtos/excluir', {
        id, _csrf_token: CSRF_TOKEN,
      }, function (res) {
        if (res.ok) {
          showToast(res.msg, 'success');
          setTimeout(() => window.location.href = BASE_URL + '/admin/produtos', 800);
        }
      }, 'json');
    });

    // ── Controle de estoque vs variações ─────────────────────

    function atualizarModoEstoque() {
      const temVar     = document.getElementById('pe-tem-variacao')?.checked;
      const manual     = document.getElementById('pe-estoque-manual');
      const skuInfo    = document.getElementById('pe-estoque-sku-info');
      const avisoVar   = document.getElementById('pe-estoque-variacao-aviso');
      const estoqueCard = document.getElementById('pe-estoque-card');

      if (temVar) {
        if (manual)   manual.style.opacity  = '.35';
        if (manual)   manual.style.pointerEvents = 'none';
        if (skuInfo)  skuInfo.style.display  = '';
        if (avisoVar) avisoVar.style.display = '';
        // somarEstoqueSKUs();
      } else {
        if (manual)   manual.style.opacity  = '1';
        if (manual)   manual.style.pointerEvents = '';
        if (skuInfo)  skuInfo.style.display  = 'none';
        if (avisoVar) avisoVar.style.display = 'none';
      }
    }

    function somarEstoqueSKUs() {
      let total = 0;
      document.querySelectorAll('.pe-sku-estoque').forEach(input => {
        total += parseInt(input.value) || 0;
      });
      const el = document.getElementById('pe-sku-estoque-total');
      if (el) el.textContent = total.toLocaleString('pt-BR');

      // Atualiza campo oculto de estoque_total
      const estoqueInput = document.getElementById('pe-estoque');
      if (estoqueInput) estoqueInput.value = total;
    }

    // Observa mudanças de estoque nos SKUs
    $(document).on('input', '.pe-sku-estoque', function () {
      if (document.getElementById('pe-tem-variacao')?.checked) {
        // somarEstoqueSKUs();
      }
    });

    // Observa toggle de variações
    document.getElementById('pe-tem-variacao')?.addEventListener('change', atualizarModoEstoque);
    atualizarModoEstoque(); // inicializa

    // ── SKU adicionado: recalcula estoque ────────────────────
    // No evento de adicionar SKU via botão, após append do TR, chame:
    // somarEstoqueSKUs();

    // ── Salvar: inclui atributos dos SKUs ────────────────────
    // No salvarProduto(), adicione antes do fetch:

    document.querySelectorAll('.pe-sku-card').forEach(card => {
      const skuKey = card.dataset.skuId || card.dataset.key;
      card.querySelectorAll('[name^="skus["]').forEach(input => {
        // fd.set(input.name, input.value);
      });
    });

    // ── Adicionar SKU (substitui versão antiga com table) ────
    // let skuCounter = Date.now();  

    // document.getElementById('btn-add-sku')?.addEventListener('click', () => {
    //   skuCounter++;
    //   const key = 'new_' + skuCounter;

    //   const empty = document.getElementById('pe-skus-empty');
    //   if (empty) empty.style.display = 'none';

    //   // Busca o template via Ajax para garantir valores atuais dos atributos
    //   fetch(BASE_URL + '/admin/produtos/sku-template?key=' + key)
    //     .then(r => r.text())
    //     .then(html => {
    //       document.getElementById('pe-skus-list')?.insertAdjacentHTML('beforeend', html);
    //       somarEstoqueSKUs();
    //     });
    // });

    // ── Fallback: cria SKU em branco via JS se o endpoint não existir ──
    // (use esse se preferir não criar o endpoint)
    

    // Substitui o handler do botão para usar o JS puro:
    // document.getElementById('btn-add-sku')?.addEventListener('click', () => {
    //   skuCounter++;
    //   const key   = 'new_' + skuCounter;
    //   const empty = document.getElementById('pe-skus-empty');
    //   if (empty) empty.style.display = 'none';

    //   document.getElementById('pe-skus-list')
    //     ?.insertAdjacentHTML('beforeend', buildSkuCardHTML(key));
    //   somarEstoqueSKUs();
    // });

    // ── Seleção de valor de atributo (botão/swatch) ──────────  
    

    // ── Remover SKU ───────────────────────────────────────────
    // $(document).on('click', '.pe-sku-del', function () {
    //   $(this).closest('.pe-sku-card').slideUp(200, function () {
    //     $(this).remove();
    //     somarEstoqueSKUs();
    //   });
    // });

    // ── Highlight inicial ────────────────────────────────
    highlightNav();
    updateEstoque();


    // ── Estoque: ajuste e histórico ───────────────────────────
    // ── Estoque: drawer de ajuste e histórico ─────────────────
  
})();
(function () {
    if (!document.getElementById('pe-estoque-card')) return;

    const getProdutoId = () => parseInt(
      document.getElementById('produto-id')?.value || 0
    );

    // ── Helper: abre drawer de ajuste ──────────────────────
    function abrirAjuste({ produtoId, skuId = null, skuCodigo = '', saldoAtual = 0 }) {
      const titulo = skuId
        ? `Ajustar estoque — ${skuCodigo}`
        : 'Ajustar estoque';

      const drawer = adminDrawer({
        titulo,
        tamanho : 'sm',
        conteudo: buildAjusteHTML(saldoAtual),
      });

      inicializarAjuste(drawer, { produtoId, skuId, skuCodigo, saldoAtual });
    }

    function buildAjusteHTML(saldoAtual) {
      return `
        <div class="estoque-operacao-tabs">
          <button type="button" class="estoque-op-tab active" data-op="entrada">
            + Entrada
          </button>
          <button type="button" class="estoque-op-tab" data-op="saida">
            − Saída
          </button>
          <button type="button" class="estoque-op-tab" data-op="corrigir">
            = Corrigir
          </button>
        </div>
        <input type="hidden" id="ajuste-operacao" value="entrada">

        <div class="form-group" style="margin-top:20px;">
          <label class="pe-label" id="ajuste-qtd-label">
            Quantidade a adicionar
          </label>
          <input type="number" id="ajuste-quantidade"
                class="form-control" min="1" value="1"
                style="font-size:22px;font-weight:800;text-align:center;">
        </div>

        <div class="form-group">
          <label class="pe-label">Observação</label>
          <input type="text" id="ajuste-observacao" class="form-control"
                placeholder="Ex: Recebimento NF 1234, Inventário...">
        </div>

        <div class="estoque-ajuste-preview">
          <span>Saldo atual:
            <strong id="preview-saldo-atual">${saldoAtual}</strong>
          </span>
          <span class="estoque-ajuste-arrow">→</span>
          <span>Novo saldo:
            <strong id="preview-saldo-novo">${saldoAtual}</strong>
          </span>
        </div>

        <div style="margin-top:20px;">
          <button type="button" class="btn btn-primary"
                  style="width:100%;" id="btn-confirmar-ajuste">
            Confirmar ajuste
          </button>
        </div>`;
    }

    function inicializarAjuste(drawer, { produtoId, skuId, skuCodigo, saldoAtual }) {
      let saldoRef = saldoAtual;

      function preview() {
        const op  = document.getElementById('ajuste-operacao')?.value;
        const qtd = parseInt(document.getElementById('ajuste-quantidade')?.value) || 0;
        let   novo;

        if (op === 'entrada')  novo = saldoRef + qtd;
        if (op === 'saida')    novo = Math.max(0, saldoRef - qtd);
        if (op === 'corrigir') novo = qtd;

        const $novo = document.getElementById('preview-saldo-novo');
        if ($novo) {
          $novo.textContent = novo;
          $novo.style.color = novo < saldoRef ? 'var(--danger)' : 'var(--success)';
        }
      }

      // Tabs
      $(drawer.body()).on('click', '.estoque-op-tab', function () {
        const op = $(this).data('op');
        document.getElementById('ajuste-operacao').value = op;
        $(drawer.body()).find('.estoque-op-tab').removeClass('active');
        $(this).addClass('active');

        const labels = {
          entrada : 'Quantidade a adicionar',
          saida   : 'Quantidade a remover',
          corrigir: 'Novo saldo absoluto',
        };
        document.getElementById('ajuste-qtd-label').textContent = labels[op];
        preview();
      });

      $(drawer.body()).on('input', '#ajuste-quantidade', preview);
      preview();

      // Confirmar
      $(drawer.body()).on('click', '#btn-confirmar-ajuste', async function () {
        const op         = document.getElementById('ajuste-operacao').value;
        const quantidade = parseInt(document.getElementById('ajuste-quantidade').value) || 0;
        const obs        = document.getElementById('ajuste-observacao').value.trim();

        if (quantidade <= 0) {
          showToast('Informe uma quantidade válida.', 'error');
          return;
        }

        const labelOp = { entrada: 'entrada', saida: 'saída', corrigir: 'correção' };
        // const ok = await adminConfirm({
        //   titulo   : 'Confirmar ajuste de estoque?',
        //   mensagem : `${skuId ? 'SKU: ' + skuCodigo + ' — ' : ''}Operação: ${labelOp[op]} de ${quantidade} unidade(s).`,
        //   tipo     : op === 'saida' ? 'warning' : 'info',
        //   confirmar: 'Confirmar',
        // });
        // if (!ok) return;

        $(this).prop('disabled', true).text('Salvando...');

        $.post(BASE_URL + '/admin/estoque/ajustar', {
          produto_id  : produtoId,
          sku_id      : skuId || '',
          operacao    : op,
          quantidade  : quantidade,
          observacao  : obs,
          _csrf_token : CSRF_TOKEN,
        }, function (res) {
          $('#btn-confirmar-ajuste').prop('disabled', false).text('Confirmar ajuste');

          if (!res.ok) { showToast(res.msg, 'error'); return; }

          drawer.close();
          showToast(
            `Estoque ajustado: ${res.saldo_anterior} → ${res.saldo_posterior}`,
            'success'
          );

          // Atualiza displays
          atualizarDisplayEstoque(produtoId, skuId);
        }, 'json');
      });
    }

    // ── Helper: abre drawer de histórico ───────────────────
    function abrirHistorico({ produtoId, skuId = null, skuCodigo = '' }) {
      const titulo = skuId
        ? `Histórico — ${skuCodigo}`
        : 'Histórico de estoque';

      const drawer = adminDrawer({
        titulo,
        tamanho : 'lg',
        conteudo: '<div class="pe-loading">Carregando...</div>',
      });

      carregarHistorico(drawer, produtoId, skuId, 1);
    }

    function carregarHistorico(drawer, produtoId, skuId, page) {
      $.get(BASE_URL + '/admin/estoque/historico', {
        produto_id: produtoId,
        sku_id    : skuId || '',
        page,
      }, function (res) {
        if (!res.ok || !res.logs.length) {
          drawer.setConteudo(
            '<p style="color:var(--text-3);text-align:center;padding:32px;font-style:italic;">Nenhuma movimentação registrada.</p>'
          );
          return;
        }

        drawer.setConteudo(buildHistoricoHTML(res, produtoId, skuId));

        // Paginação via event delegation
        $(drawer.body()).on('click', '.hist-pag-btn', function () {
          carregarHistorico(drawer, produtoId, skuId, parseInt($(this).data('page')));
        });
      }, 'json');
    }

    function buildHistoricoHTML(res, produtoId, skuId) {
      const tipoLabels = {
        entrada_manual    : '+ Entrada manual',
        entrada_nf        : '+ Entrada NF',
        entrada_int       : '+ Entrada Externa',
        entrada_devolucao : '+ Devolução',
        saida_venda       : '− Venda',
        saida_manual      : '− Saída manual',
        saida_ajuste      : '− Ajuste',
        saida_int         : '− Saida Externa',
        reserva           : '⊖ Reserva',
        reserva_cancelada : '⊕ Reserva liberada',
        correcao          : '= Correção',
      };
      const dirColor = {
        entrada_manual    : 'var(--success)', entrada_nf: 'var(--success)', entrada_int: 'var(--success)',
        entrada_devolucao : 'var(--success)',
        saida_venda       : 'var(--danger)',  saida_manual: 'var(--danger)', saida_int: 'var(--danger)',
        saida_ajuste      : 'var(--danger)',
        reserva           : 'var(--warning)', reserva_cancelada: 'var(--warning)',
        correcao          : 'var(--blue)',
      };

      let html = `<table class="admin-table" style="font-size:12px;">
        <thead>
          <tr>
            <th>Data</th>
            ${!skuId ? '<th>Variação</th>' : ''}
            <th>Tipo</th>
            <th style="text-align:center">Qtd</th>
            <th style="text-align:center">Anterior</th>
            <th style="text-align:center">Posterior</th>
            <th>Origem</th>
            <th>Obs</th>
          </tr>
        </thead>
        <tbody>`;

      res.logs.forEach(log => {
        const cor   = dirColor[log.tipo]   || 'var(--text-2)';
        const label = tipoLabels[log.tipo] || log.tipo;
        const data  = new Date(log.criado_em).toLocaleString('pt-BR');

        // Variação: código do SKU + atributos
        let variacaoCell = '';
        if (!skuId) {
          if (log.sku_id) {
            variacaoCell = `
              <td>
                <span style="font-family:var(--font-mono);font-size:11px;
                            font-weight:700;color:var(--text)">
                  ${log.sku_codigo || ''}
                </span>
                ${log.sku_variacao
                  ? `<span style="display:block;font-size:10.5px;color:var(--text-3)">
                      ${log.sku_variacao}
                    </span>`
                  : ''}
              </td>`;
          } else {
            variacaoCell = `
              <td style="font-size:11px;color:var(--text-3);font-style:italic;">
                Produto geral
              </td>`;
          }
        }

        html += `
          <tr>
            <td style="white-space:nowrap;font-size:11px;color:var(--text-3)">
              ${data}
            </td>
            ${variacaoCell}
            <td>
              <span style="color:${cor};font-weight:800">${label}</span>
            </td>
            <td style="text-align:center;font-weight:800;color:${cor}">
              ${log.quantidade}
            </td>
            <td style="text-align:center;color:var(--text-3)">
              ${log.saldo_anterior}
            </td>
            <td style="text-align:center;font-weight:700">
              ${log.saldo_posterior}
            </td>
            <td style="font-size:11px;color:var(--text-3)">
              ${log.origem}
              ${log.usuario_nome
                ? `<span style="display:block">${log.usuario_nome}</span>`
                : ''}
            </td>
            <td style="font-size:11px;color:var(--text-3);max-width:160px;
                      overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                title="${log.observacao || ''}">
              ${log.observacao || '—'}
            </td>
          </tr>`;
      });

      html += `</tbody></table>`;

      // Paginação
      if (res.pages > 1) {
        html += `<div style="display:flex;gap:6px;justify-content:center;
                              padding:16px 0;flex-wrap:wrap;">`;
        for (let i = 1; i <= res.pages; i++) {
          html += `<button type="button"
                          class="btn btn-sm hist-pag-btn
                                  ${i === res.page ? 'btn-primary' : 'btn-ghost'}"
                          data-page="${i}">${i}</button>`;
        }
        html += '</div>';
      }

      return html;
    }

    // ── Helper: atualiza displays após ajuste ───────────────
    function atualizarDisplayEstoque(produtoId, skuId) {
        $.get(BASE_URL + '/admin/estoque/saldo', {
            produto_id: produtoId,
            sku_id    : skuId || '',
        }, function (res) {
            if (!res.ok) return;

            if (skuId) {
                // Atualiza a linha individual do SKU no breakdown
                $(`#breakdown-sku-${skuId} .pe-estoque-sku-saldo`)
                    .text(res.saldo.toLocaleString('pt-BR'));
                $(`#breakdown-sku-${skuId} .pe-estoque-sku-reservado`)
                    .text(res.reservado.toLocaleString('pt-BR'));
                $(`#breakdown-sku-${skuId} .pe-estoque-sku-disponivel`)
                    .text(res.disponivel.toLocaleString('pt-BR'));

                // Atualiza data-saldo do botão de ajuste
                $(`[data-sku-id="${skuId}"].btn-ajustar-sku-estoque`)
                    .data('saldo', res.saldo);

                // Atualiza input inline da tabela de SKUs
                const $input = $(`.pe-sku-estoque[data-sku-id="${skuId}"]`);
                if ($input.length) {
                    $input.val(res.saldo).data('original', res.saldo);
                }
            }

            // Sempre atualiza os totalizadores do card com dados reais do servidor
            const elSaldo = document.getElementById('pe-saldo-atual');
            const elDisp  = document.getElementById('pe-saldo-disponivel');
            const elRes   = document.getElementById('pe-saldo-reservado');

            if (elSaldo) elSaldo.textContent =
                res.total_saldo.toLocaleString('pt-BR') + ' un';
            if (elDisp)  elDisp.textContent  =
                res.total_disponivel.toLocaleString('pt-BR') + ' un';
            if (elRes)   elRes.textContent   =
                res.total_reservado.toLocaleString('pt-BR') + ' un';

        }, 'json');
    }

    // ── Event listeners ────────────────────────────────────

    // Ajustar produto sem variação
    $(document).on('click', '#btn-ajustar-estoque', function () {
      abrirAjuste({
        produtoId  : $(this).data('produto-id'),
        saldoAtual : $(this).data('saldo'),
      });
    });

    // Ajustar SKU individual (breakdown)
    $(document).on('click', '.btn-ajustar-sku-estoque', function () {
      abrirAjuste({
        produtoId  : $(this).data('produto-id'),
        skuId      : $(this).data('sku-id'),
        skuCodigo  : $(this).data('sku-codigo'),
        saldoAtual : $(this).data('saldo'),
      });
    });

    // Histórico geral do produto
    $(document).on('click', '#btn-ver-historico-estoque', function () {
      abrirHistorico({ produtoId: $(this).data('produto-id') });
    });

    // Histórico de SKU individual (breakdown)
    $(document).on('click', '.btn-historico-sku-estoque', function () {
      abrirHistorico({
        produtoId  : $(this).data('produto-id'),
        skuId      : $(this).data('sku-id'),
        skuCodigo  : $(this).data('sku-codigo'),
      });
    });

    // Recalcular
    $(document).on('click', '#btn-recalcular-estoque', async function () {
      const produtoId = $(this).data('produto-id');
      $(this).prop('disabled', true);

      const res = await fetch(BASE_URL + '/admin/estoque/recalcular', {
        method : 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body   : `produto_id=${produtoId}&_csrf_token=${CSRF_TOKEN}`,
      }).then(r => r.json());

      $(this).prop('disabled', false);

      if (!res.ok) { showToast('Erro ao recalcular.', 'error'); return; }

      if (res.divergencia) {
        const ok = await adminConfirm({
          titulo   : 'Divergência encontrada!',
          mensagem : `Saldo atual: ${res.saldo_atual} | Log calculado: ${res.saldo_calculado}. Deseja corrigir?`,
          tipo     : 'warning',
          confirmar: 'Corrigir divergência',
        });
        if (ok) {
          await fetch(BASE_URL + '/admin/estoque/sincronizar', {
            method : 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body   : `produto_id=${produtoId}&_csrf_token=${CSRF_TOKEN}`,
          });
          showToast('Divergência corrigida!', 'success');
          setTimeout(() => window.location.reload(), 800);
        }
      } else {
        showToast('Estoque consistente. Nenhuma divergência encontrada.', 'success');
      }
    });

  // })();
// ── Estoque inline nos SKUs ───────────────────────────────
// (function () {
  if (!document.getElementById('pe-skus-tbody')) return;

  // Detecta alteração no input de estoque do SKU
  $(document).on('input', '.pe-sku-estoque', function () {
    const $input  = $(this);
    const skuId   = $input.data('sku-id');
    const original= parseInt($input.data('original')) || 0;
    const atual   = parseInt($input.val()) || 0;
    const $btns   = $(`#sku-est-btns-${skuId}`);

    if (atual !== original) {
      $btns.stop(true).fadeIn(150);
    } else {
      $btns.stop(true).fadeOut(150);
    }
  });

  // ── Confirmar alteração ───────────────────────────────
  $(document).on('click', '.pe-sku-est-confirm', async function () {
    const skuId     = $(this).data('sku-id');
    const $input    = $(`.pe-sku-estoque[data-sku-id="${skuId}"]`);
    const produtoId = $input.data('produto-id');
    const original  = parseInt($input.data('original')) || 0;
    const novoValor = parseInt($input.val()) || 0;
    const diferenca = novoValor - original;
    const $btns     = $(`#sku-est-btns-${skuId}`);
    const $btn      = $(this);

    if (diferenca === 0) { $btns.fadeOut(150); return; }

    // Validação básica
    if (novoValor < 0) {
      showToast('Estoque não pode ser negativo.', 'error');
      return;
    }

    // Confirmação personalizada
    const label   = diferenca > 0
      ? `Adicionar ${diferenca} unidade(s) ao estoque`
      : `Remover ${Math.abs(diferenca)} unidade(s) do estoque`;

    // const ok = await adminConfirm({
    //   titulo   : 'Confirmar ajuste?',
    //   mensagem : `${label}. Saldo atual: ${original} → Novo saldo: ${novoValor}`,
    //   tipo     : diferenca < 0 ? 'warning' : 'info',
    //   confirmar: 'Confirmar',
    // });

    // if (!ok) {
    //   // Restaura o valor original
    //   $input.val(original);
    //   $btns.fadeOut(150);
    //   return;
    // }

    $btn.prop('disabled', true);
    $btn.html('<svg class="spin" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M21 12a9 9 0 11-6.219-8.56"/></svg>');

    $.post(BASE_URL + '/admin/estoque/ajustar-sku', {
      sku_id      : skuId,
      produto_id  : produtoId,
      novo_valor  : novoValor,
      valor_antes : original,
      _csrf_token : CSRF_TOKEN,
    }, function (res) {
      $btn.prop('disabled', false);
      $btn.html('<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>');

      if (!res.ok) {
        showToast(res.msg, 'error');
        $input.val(original);
        $btns.fadeOut(150);
        return;
      }

      // Atualiza o valor de referência original
      $input.data('original', res.saldo_posterior);
      $input.val(res.saldo_posterior);
      $btns.fadeOut(150);

      // Feedback visual no input
      $input.addClass('input-success');
      setTimeout(() => $input.removeClass('input-success'), 2000);

      showToast(res.msg, 'success');
      // somarEstoqueSKUs();

      atualizarDisplayEstoque(produtoId, skuId);
    }, 'json').fail(() => {
      $btn.prop('disabled', false);
      showToast('Erro de conexão.', 'error');
      $input.val(original);
      $btns.fadeOut(150);
    });
  });

  // ── Cancelar alteração ───────────────────────────────
  $(document).on('click', '.pe-sku-est-cancel', function () {
    const skuId  = $(this).data('sku-id');
    const $input = $(`.pe-sku-estoque[data-sku-id="${skuId}"]`);
    const $btns  = $(`#sku-est-btns-${skuId}`);

    $input.val($input.data('original'));
    $btns.stop(true).fadeOut(200);
  });

  // Também cancela com ESC
  $(document).on('keydown', '.pe-sku-estoque', function (e) {
    if (e.key === 'Escape') {
      const skuId = $(this).data('sku-id');
      $(this).val($(this).data('original'));
      $(`#sku-est-btns-${skuId}`).stop(true).fadeOut(200);
    }
    if (e.key === 'Enter') {
      e.preventDefault();
      const skuId = $(this).data('sku-id');
      $(`#sku-est-btns-${skuId}`).find('.pe-sku-est-confirm').trigger('click');
    }
  });

})();


// ── Editor de banner ──────────────────────────────────────
(function () {
  if (!document.getElementById('form-banner')) return;

  // ── Tabs Desktop / Mobile ──────────────────────────────
  document.querySelectorAll('.banner-device-tab').forEach(btn => {
    btn.addEventListener('click', function () {
      const tab = this.dataset.tab;
      document.querySelectorAll('.banner-device-tab').forEach(t => t.classList.remove('is-active'));
      this.classList.add('is-active');
      document.querySelectorAll('.banner-device-panel').forEach(p => {
        p.hidden = p.dataset.panel !== tab;
      });
    });
  });

  // ── Tipo de mídia tabs ─────────────────────────────────
  document.querySelectorAll('.banner-tipo-tab input').forEach(input => {
    input.addEventListener('change', function () {
      document.querySelectorAll('.banner-tipo-tab').forEach(t => t.classList.remove('is-active'));
      this.closest('.banner-tipo-tab').classList.add('is-active');
      atualizarVisibilidadeUploads();
      atualizarPreview();
    });
  });

  function atualizarVisibilidadeUploads() {
    const tipo = document.querySelector('input[name="tipo_midia"]:checked')?.value || 'imagem';
    document.querySelectorAll('.banner-upload-group').forEach(g => {
      const t = g.dataset.tipo;
      const mostrar =
        (tipo === 'imagem'            && t === 'imagem') ||
        (tipo === 'video'             && t === 'video')  ||
        (tipo === 'video_com_imagem');
      g.style.display = mostrar ? '' : 'none';
    });
  }
  atualizarVisibilidadeUploads();

  // ── Posição do texto ───────────────────────────────────
  document.querySelectorAll('.banner-pos-cell input').forEach(input => {
    input.addEventListener('change', function () {
      document.querySelectorAll('.banner-pos-cell').forEach(c => c.classList.remove('is-active'));
      this.closest('.banner-pos-cell').classList.add('is-active');
      atualizarPreview();
    });
  });

  // ── Color inputs ───────────────────────────────────────
  document.querySelectorAll('.banner-color-input').forEach(wrap => {
    const colorInput = wrap.querySelector('input[type="color"]');
    const textInput  = wrap.querySelector('input[type="text"]');
    colorInput?.addEventListener('input', function () {
      if (textInput) textInput.value = this.value.toUpperCase();
      atualizarPreview();
    });
  });

  // ── Range de opacidade ─────────────────────────────────
  document.querySelectorAll('.banner-range').forEach(range => {
    const valEl = range.parentElement.querySelector('.banner-range-val span');
    range.addEventListener('input', function () {
      if (valEl) valEl.textContent = this.value + '%';
      atualizarPreview();
    });
  });

  // ══════════════════════════════════════════════════════
  // UPLOAD — FIX PRINCIPAL
  // Mantém o <input type="file"> SEMPRE no DOM.
  // Nunca recria via innerHTML — isso destrói files[].
  // ══════════════════════════════════════════════════════
  document.querySelectorAll('.banner-upload-area').forEach(area => {
    const input = area.querySelector('.banner-upload-input');
    if (!input) return;

    // Clique na área abre o file picker (ignora cliques nos botões internos)
    area.addEventListener('click', function (e) {
      if (e.target.closest('.banner-upload-remove')) return;
      if (e.target === input) return;
      input.click();
    });

    // Drag & drop
    area.addEventListener('dragover',  e => { e.preventDefault(); area.classList.add('is-dragover'); });
    area.addEventListener('dragleave', () => area.classList.remove('is-dragover'));
    area.addEventListener('drop', e => {
      e.preventDefault();
      area.classList.remove('is-dragover');
      const file = e.dataTransfer.files[0];
      if (file) {
        // Injeta o arquivo no input via DataTransfer
        const dt = new DataTransfer();
        dt.items.add(file);
        input.files = dt.files;
        mostrarPreview(area, input, file);
      }
    });

    // Mudança via file picker
    input.addEventListener('change', function () {
      if (this.files[0]) mostrarPreview(area, this, this.files[0]);
    });

    // Botão "Trocar" — delegação de evento
    area.addEventListener('click', function (e) {
      if (!e.target.closest('.banner-upload-remove')) return;
      e.stopPropagation();
      limparPreview(area, input);
    });
  });

  /**
   * Mostra preview da mídia SEM tocar no input.
   * O input permanece no DOM com files[] intacto.
   */
  function mostrarPreview(area, input, file) {
    const url   = URL.createObjectURL(file);
    const isVid = file.type.startsWith('video/');

    // Remove preview anterior se houver
    area.querySelector('.banner-upload-preview')?.remove();

    // Oculta o estado vazio (NÃO remove — só esconde)
    const empty = area.querySelector('.banner-upload-empty');
    if (empty) empty.style.display = 'none';

    // Cria preview via createElement (nunca via innerHTML sobre a área inteira)
    const preview = document.createElement('div');
    preview.className = 'banner-upload-preview';

    const media = document.createElement(isVid ? 'video' : 'img');
    media.src   = url;
    if (isVid) { media.controls = true; media.muted = true; }
    preview.appendChild(media);

    const removeBtn = document.createElement('button');
    removeBtn.type      = 'button';
    removeBtn.className = 'banner-upload-remove';
    removeBtn.textContent = isVid ? 'Trocar vídeo' : 'Trocar imagem';
    preview.appendChild(removeBtn);

    // Insere ANTES do input — input continua no DOM depois do preview
    area.insertBefore(preview, input);

    // Esconde o input visualmente (mas NUNCA remove do DOM/formulário)
    input.style.position   = 'absolute';
    input.style.opacity    = '0';
    input.style.pointerEvents = 'none';
    input.style.width      = '1px';
    input.style.height     = '1px';

    atualizarPreview();
  }

  /**
   * Limpa o preview e reseta o input para estado inicial.
   */
  function limparPreview(area, input) {
    area.querySelector('.banner-upload-preview')?.remove();

    // Restaura o estado vazio
    const empty = area.querySelector('.banner-upload-empty');
    if (empty) empty.style.display = '';

    // Limpa o valor do input E restaura estilo
    input.value          = '';
    input.style.position = '';
    input.style.opacity  = '';
    input.style.pointerEvents = '';
    input.style.width    = '';
    input.style.height   = '';

    atualizarPreview();
  }

  // ── Inputs de texto → atualizar preview ───────────────
  ['titulo_overlay','subtitulo_overlay','cta1_texto','cta2_texto'].forEach(name => {
    document.querySelector(`[name="${name}"]`)?.addEventListener('input', atualizarPreview);
  });

  // ── Preview Desktop/Mobile toggle ─────────────────────
  document.querySelectorAll('.banner-preview-btn').forEach(btn => {
    btn.addEventListener('click', function () {
      document.querySelectorAll('.banner-preview-btn').forEach(b => b.classList.remove('is-active'));
      this.classList.add('is-active');
      const frame = document.getElementById('banner-preview-frame');
      if (frame) frame.classList.toggle('is-mobile', this.dataset.device === 'mobile');
    });
  });

  // ── Atualiza preview ao vivo ───────────────────────────
  function atualizarPreview() {
    const frame = document.getElementById('banner-preview-frame');
    if (!frame) return;

    const titulo    = document.querySelector('[name="titulo_overlay"]')?.value    || '';
    const subtitulo = document.querySelector('[name="subtitulo_overlay"]')?.value || '';
    const cta1Txt   = document.querySelector('[name="cta1_texto"]')?.value        || '';
    const cta2Txt   = document.querySelector('[name="cta2_texto"]')?.value        || '';
    const cta1Est   = document.querySelector('[name="cta1_estilo"]')?.value       || 'primary';
    const cta2Est   = document.querySelector('[name="cta2_estilo"]')?.value       || 'outline';
    const corTxt    = document.querySelector('[name="cor_texto"]')?.value         || '#ffffff';
    const corOver   = document.querySelector('[name="cor_overlay"]')?.value       || '#000000';
    const opOver    = document.querySelector('[name="overlay_opacidade"]')?.value || 0;
    const pos       = document.querySelector('[name="posicao_texto"]:checked')?.value || 'center';

    // Pega a mídia do painel ativo
    const imgEl  = document.querySelector('[data-panel="desktop"] .banner-upload-preview img');
    const vidEl  = document.querySelector('[data-panel="desktop"] .banner-upload-preview .video-in');

    const positionMap = {
      'top-left':      'flex-start;align-items:flex-start;text-align:left',
      'top-center':    'center;align-items:flex-start;text-align:center',
      'top-right':     'flex-end;align-items:flex-start;text-align:right',
      'left':          'flex-start;align-items:center;text-align:left',
      'center':        'center;align-items:center;text-align:center',
      'right':         'flex-end;align-items:center;text-align:right',
      'bottom-left':   'flex-start;align-items:flex-end;text-align:left',
      'bottom-center': 'center;align-items:flex-end;text-align:center',
      'bottom-right':  'flex-end;align-items:flex-end;text-align:right',
    };

    const flexParts = (positionMap[pos] || positionMap['center']).split(';');
    const jc   = flexParts[0];
    const ai   = flexParts[1].split(':')[1];
    const ta   = flexParts[2].split(':')[1];

    let mediaHtml = '';
    if (vidEl) {
      mediaHtml = ` <iframe src="${vidEl.src}?autoplay=true&muted=true&loop=true" style="border:none;width:100%;height:100%" allowfullscreen frameborder="0" allow="autoplay; fullscreen; picture-in-picture"></iframe>`;
    } else if (imgEl) {
      mediaHtml = `<img src="${imgEl.src}" alt=""
                        style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;">`;
    }

    const ctaStyle = (est) => ({
      primary:   'background:#dc2626;color:#fff;border:1.5px solid #dc2626;',
      secondary: 'background:#1e293b;color:#fff;border:1.5px solid #1e293b;',
      outline:   'background:transparent;color:#fff;border:1.5px solid rgba(255,255,255,.8);',
      ghost:     'background:rgba(255,255,255,.15);color:#fff;border:1.5px solid transparent;',
    })[est] || '';

    const ctasHtml = (cta1Txt || cta2Txt) ? `
      <div style="display:flex;gap:10px;margin-top:14px;flex-wrap:wrap;justify-content:${ta === 'center' ? 'center' : ta === 'right' ? 'flex-end' : 'flex-start'};">
        ${cta1Txt ? `<span style="${ctaStyle(cta1Est)}padding:9px 20px;border-radius:8px;font-size:13px;font-weight:800;display:inline-block;">${esc(cta1Txt)}</span>` : ''}
        ${cta2Txt ? `<span style="${ctaStyle(cta2Est)}padding:9px 20px;border-radius:8px;font-size:13px;font-weight:800;display:inline-block;">${esc(cta2Txt)}</span>` : ''}
      </div>` : '';

    const placeholder = !mediaHtml
      ? `<div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#1e293b,#334155);color:rgba(255,255,255,.3);font-size:13px;font-weight:600;">Adicione uma mídia</div>`
      : '';

    frame.innerHTML = `
      ${mediaHtml}${placeholder}
      <div style="position:absolute;inset:0;background:${corOver};opacity:${opOver / 100};pointer-events:none;"></div>
      <div style="position:absolute;inset:0;padding:32px;display:flex;flex-direction:column;justify-content:${jc};align-items:${ai};text-align:${ta};color:${corTxt};">
        ${titulo    ? `<h3 style="font-size:clamp(18px,3vw,28px);font-weight:900;letter-spacing:-.5px;line-height:1.1;margin:0 0 8px;color:${corTxt};">${esc(titulo)}</h3>` : ''}
        ${subtitulo ? `<p style="font-size:13px;line-height:1.5;margin:0;max-width:60%;color:${corTxt};opacity:.9;">${esc(subtitulo)}</p>` : ''}
        ${ctasHtml}
      </div>
    `;
  }

  function esc(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  // Roda preview inicial
  atualizarPreview();

  // ── Verificar config do PHP antes de submeter ──────────
  async function verificarLimitesPHP() {
    // Estima tamanho dos arquivos selecionados
    let totalBytes = 0;
    document.querySelectorAll('.banner-upload-input').forEach(inp => {
      if (inp.files[0]) totalBytes += inp.files[0].size;
    });

    const MB = totalBytes / (1024 * 1024);
    if (MB > 48) { // margem de segurança em relação ao post_max_size padrão de 50MB
      showToast(`Total de arquivos (${MB.toFixed(1)}MB) pode exceder o limite do servidor.`, 'warning');
    }
  }

  // ── Submit ─────────────────────────────────────────────
  document.getElementById('btn-salvar-banner')?.addEventListener('click', async function () {
    const $btn  = this;
    const $form = document.getElementById('form-banner');

    // Validação básica
    const titulo = $form.querySelector('[name="titulo"]')?.value?.trim();
    const zona   = $form.querySelector('[name="zona_id"]')?.value;
    if (!titulo) { showToast('Informe o título interno.', 'error'); return; }
    if (!zona)   { showToast('Selecione uma zona.',       'error'); return; }

    await verificarLimitesPHP();

    $btn.disabled    = true;
    $btn.textContent = 'Salvando...';

    // FormData captura todos os inputs que estão no DOM, incluindo file inputs
    const fd = new FormData($form);

    // Debug (remover em produção):
    // for (let [k, v] of fd.entries()) console.log(k, v instanceof File ? `FILE:${v.name}(${v.size}b)` : v);

    try {
      const res  = await fetch(BASE_URL + '/admin/banners/salvar', { method: 'POST', body: fd });
      const data = await res.json();

      if (data.ok) {
        showToast(data.msg || 'Banner salvo!', 'success');
        setTimeout(() => window.location.href = BASE_URL + '/admin/banners', 600);
      } else {
        showToast(data.msg || 'Erro ao salvar.', 'error');
        $btn.disabled    = false;
        $btn.textContent = 'Salvar';
      }
    } catch (err) {
      console.error('Banner save error:', err);
      showToast('Erro de conexão.', 'error');
      $btn.disabled    = false;
      $btn.textContent = 'Salvar';
    }
  });

  // ── Seletor de ícone ─────────────────────────────────
  const svgDefs = {
    flame:     '<path d="M12 2c0 0-5 5-5 10a5 5 0 0010 0C17 7 12 2 12 2z"/><path d="M12 12c0 0-2 2-2 4a2 2 0 004 0c0-2-2-4-2-4z"/>',
    lightning: '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
    star:      '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
    percent:   '<line x1="19" y1="5" x2="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
    tag:       '<path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/>',
    mountain:  '<polygon points="3 17 8 7 13 12 16 8 21 17"/>',
    gift:      '<polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/>',
    truck:     '<rect x="1" y="3" width="15" height="13"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
    moto:      '<circle cx="5.5" cy="17.5" r="3.5"/><circle cx="18.5" cy="17.5" r="3.5"/><path d="M15 6h-2l-3 8H5.5"/>',
    clock:     '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
    none:      '',
  };

  function updateBadgePreview() {
    const icone = $('input[name="nome_publico"]:checked').val() || 'none';
    const texto = $('#bn-titulo-overlay').val().trim() || 'BADGE';
    $('#bn-badge-icon-preview').html(svgDefs[icone] || '');
    $('#bn-badge-text-preview').text(texto.toUpperCase());
    const hasText = !!$('#bn-titulo-overlay').val().trim();
    $('#bn-badge-preview').css('opacity', hasText ? 1 : 0.4);
  }

  $('input[name="nome_publico"]').on('change', function () {
    $('.bn-icon-opt').removeClass('is-selected');
    $(this).closest('.bn-icon-opt').addClass('is-selected');
    updateBadgePreview();
  });

  $('#bn-titulo-overlay').on('input', updateBadgePreview);
  updateBadgePreview();

  // ── Seletor de estilo do CTA ─────────────────────────
  $('input[name="cta1_estilo"], input[name="cta2_estilo"]').on('change', function () {
    $(this).closest('.bn-cta-style-grid').find('.bn-cta-style-opt').removeClass('is-selected');
    $(this).closest('.bn-cta-style-opt').addClass('is-selected');
  });

  // ── Toggle countdown ─────────────────────────────────
  $('#bn-tem-countdown').on('change', function () {
    $('#bn-countdown-fields').toggle(this.checked);
    if (!this.checked) $('#bn-data-fim').val('');
  });

  // ── Preview do countdown em tempo real ───────────────
  function pad(n) { return String(n).padStart(2, '0'); }

  function updateCountdownPreview() {
    const val = $('#bn-data-fim').val();
    if (!val) return;
    const fim  = new Date(val).getTime();
    const diff = fim - Date.now();
    if (diff <= 0) return;
    const dias  = Math.floor(diff / 86400000);
    const horas = Math.floor((diff % 86400000) / 3600000);
    const min   = Math.floor((diff % 3600000)  / 60000);
    const seg   = Math.floor((diff % 60000)    / 1000);
    $('#bn-prev-dias').text(pad(dias));
    $('#bn-prev-horas').text(pad(horas));
    $('#bn-prev-min').text(pad(min));
    $('#bn-prev-seg').text(pad(seg));
  }

  $('#bn-data-fim').on('change input', updateCountdownPreview);
  setInterval(updateCountdownPreview, 1000);
  updateCountdownPreview();
  
})();


(function initStreamUpload() {
  const form = document.getElementById('form-banner');
  if (!form) return;

  console.log('tete');
  

  const csrf = () => form.querySelector('[name="_csrf_token"]')?.value || '';

  // Para cada input de arquivo que seja de VÍDEO
  document.querySelectorAll('.banner-upload-input').forEach(input => {
    // Detecta se este input é de vídeo pelo grupo pai (data-tipo="video")
    const group = input.closest('.banner-upload-group');
    const isVideoSlot = group && group.dataset.tipo === 'video';
    if (!isVideoSlot) return;

    // slot: 'video' (desktop) ou 'video_mobile' — do data-attr ou do name
    const slot = input.dataset.videoSlot
              || (input.name && input.name.includes('mobile') ? 'video_mobile' : 'video');

    // Garante o hidden que carrega o UID para o POST (reusa arquivo_video)
    let hidden = form.querySelector(`input[type="hidden"][name="arquivo_${slot}"]`);
    if (!hidden) {
      hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = `arquivo_${slot}`;
      form.appendChild(hidden);
    }

    input.addEventListener('change', function () {
      const file = this.files[0];
      if (!file) return;

      // Validação client-side rápida (o backend valida de novo)
      if (!file.type.startsWith('video/')) {
        showToast('Selecione um arquivo de vídeo.', 'error');
        this.value = '';
        return;
      }
      const MAX_MB = 200;
      if (file.size > MAX_MB * 1024 * 1024) {
        showToast(`Vídeo excede ${MAX_MB}MB.`, 'error');
        this.value = '';
        return;
      }

      uploadVideoToStream(file, slot, input, hidden);
    });
  });

  /**
   * Sobe o vídeo para o Stream e preenche o hidden com o UID.
   */
  async function uploadVideoToStream(file, slot, fileInput, hidden) {
    const area = fileInput.closest('.banner-upload-area');
    const progress = ensureProgressUI(area);

    try {
      // 1. Pede a uploadURL ao backend
      progress.setLabel('Preparando envio...');
      const prep = await fetch(BASE_URL + '/admin/media/stream-upload-url', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `_csrf_token=${encodeURIComponent(csrf())}&slot=${encodeURIComponent(slot)}`,
      }).then(r => r.json());

      if (!prep.ok || !prep.uploadURL) {
        throw new Error(prep.msg || 'Falha ao preparar upload.');
      }

      const uid = prep.uid;

      // 2. Envia o arquivo DIRETO pro Stream com progresso (XHR)
      await new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', prep.uploadURL, true);

        const fd = new FormData();
        fd.append('file', file);

        xhr.upload.onprogress = (e) => {
          if (e.lengthComputable) {
            const pct = Math.round((e.loaded / e.total) * 100);
            progress.setProgress(pct);
            progress.setLabel(`Enviando... ${pct}%`);
          }
        };
        xhr.onload = () => (xhr.status >= 200 && xhr.status < 300)
          ? resolve()
          : reject(new Error('Falha no envio ao Stream (HTTP ' + xhr.status + ').'));
        xhr.onerror = () => reject(new Error('Erro de rede no envio.'));
        xhr.send(fd);
      });

      // 3. Guarda o UID no hidden (vai no POST do salvar)
      hidden.value = uid;

      // Limpa o file input (o arquivo já está no Stream; não precisa reenviar)
      fileInput.value = '';

      // 4. Polling do status até processar
      progress.setLabel('Processando vídeo...');
      progress.setIndeterminate(true);
      await pollStatus(uid, progress);

      progress.setDone('Vídeo pronto ✓');
      showToast('Vídeo enviado e processado.', 'success');

    } catch (err) {
      console.error('[stream]', err);
      progress.setError(err.message || 'Erro no upload do vídeo.');
      showToast(err.message || 'Erro no upload do vídeo.', 'error');
      hidden.value = '';
    }
  }

  /** Polling do status do vídeo até 'ready' (máx ~2 min). */
  async function pollStatus(uid, progress) {
    const maxTries = 40;      // 40 x 3s = 120s
    for (let i = 0; i < maxTries; i++) {
      await sleep(3000);
      try {
        const st = await fetch(
          `${BASE_URL}/admin/media/stream-status?uid=${encodeURIComponent(uid)}`
        ).then(r => r.json());
        if (st.ok && st.ready) return;
      } catch (_) { /* tenta de novo */ }
    }
    // Não travou o fluxo: o vídeo pode ficar pronto depois. Só avisa.
    progress.setLabel('Processando em segundo plano...');
  }

  // ── UI de progresso (cria sob a área de upload) ──────────────────────────
  function ensureProgressUI(area) {
    let el = area.querySelector('.stream-progress');
    if (!el) {
      el = document.createElement('div');
      el.className = 'stream-progress';
      el.innerHTML = `
        <div class="stream-progress__bar"><span></span></div>
        <div class="stream-progress__label"></div>`;
      el.style.cssText = 'margin-top:10px;font-size:12px;';
      const bar = el.querySelector('.stream-progress__bar');
      bar.style.cssText = 'height:6px;background:rgba(255,255,255,.1);border-radius:99px;overflow:hidden;';
      bar.firstElementChild.style.cssText = 'display:block;height:100%;width:0;background:#f14d5d;transition:width .2s;';
      area.appendChild(el);
    }
    const span = el.querySelector('.stream-progress__bar span');
    const label = el.querySelector('.stream-progress__label');
    return {
      setProgress: (p) => { span.style.width = p + '%'; },
      setIndeterminate: (on) => { span.style.width = on ? '100%' : span.style.width; span.style.opacity = on ? '.5' : '1'; },
      setLabel: (t) => { label.textContent = t; },
      setDone: (t) => { span.style.width = '100%'; span.style.background = '#34d399'; label.textContent = t; },
      setError: (t) => { span.style.background = '#f87171'; label.textContent = t; },
    };
  }

  const sleep = (ms) => new Promise(r => setTimeout(r, ms));
})();



// ── Listagem de banners ─────────────────────────────────
(function () {
  if (!document.querySelector('.banner-zonas-grid')) return;

  // Toggle ativo
  document.querySelectorAll('.admin-toggle[data-type="banner"]').forEach(btn => {
    btn.addEventListener('click', function () {
      const id = this.dataset.id;
      const fd = new FormData();
      fd.append('id', id);
      fd.append('_csrf_token', CSRF_TOKEN);

      fetch(BASE_URL + '/admin/banners/toggle-ativo', { method: 'POST', body: fd })
        .then(r => r.json()).then(res => {
          if (res.ok) this.classList.toggle('admin-toggle--on', res.ativo == 1);
        });
    });
  });

  // Excluir
  document.querySelectorAll('.btn-excluir-banner').forEach(btn => {
    btn.addEventListener('click', async function () {
      const id     = this.dataset.id;
      const titulo = this.dataset.titulo;

      const confirmar = await adminConfirm({
        titulo: 'Excluir banner?',
        mensagem: `O banner "${titulo}" será removido permanentemente. Esta ação não pode ser desfeita.`,
        tipo: 'danger',
        confirmar: 'Excluir',
      });
      if (!confirmar) return;

      const fd = new FormData();
      fd.append('id', id);
      fd.append('_csrf_token', CSRF_TOKEN);

      const res  = await fetch(BASE_URL + '/admin/banners/excluir', { method: 'POST', body: fd });
      const data = await res.json();

      if (data.ok) {
        this.closest('.banner-item').remove();
        showToast('Banner excluído.', 'success');
      } else {
        showToast(data.msg || 'Erro.', 'error');
      }
    });
  });

  // Drag to reorder (within same zone)
  if (typeof Sortable !== 'undefined') {
    document.querySelectorAll('.bz-banners-list').forEach(list => {
      new Sortable(list, {
        handle: '.banner-drag',
        animation: 150,
        onEnd: function () {
          const ordens = Array.from(list.querySelectorAll('.banner-item')).map(el => el.dataset.id);
          const fd    = new FormData();
          ordens.forEach((id, i) => fd.append(`ordens[${i}]`, id));
          fd.append('_csrf_token', CSRF_TOKEN);

          fetch(BASE_URL + '/admin/banners/reordenar', { method: 'POST', body: fd })
            .then(r => r.json()).then(res => {
              if (res.ok) showToast('Ordem salva!', 'success');
            });
        },
      });
    });
  }

})();



// ── SKU:  atributos ───────────────────────────────
(function () {

  if (!document.getElementById('atributos-tbody')) return;

  console.log('teste');
  

  const modal     = document.getElementById('modal-atributo');
  const formAttr  = document.getElementById('form-atributo');
  
  const nomeInput = document.getElementById('attr-nome');
  const slugInput = document.getElementById('attr-slug');

  function abrirModal(dados = {}) {
    document.getElementById('attr-id').value      = dados.id    || 0;
    nomeInput.value = dados.nome    || '';
    slugInput.value = dados.slug    || '';
    document.getElementById('attr-papel').value   = dados.papel   || 'variacao';
    document.getElementById('attr-display').value = dados.display || 'button';
    document.getElementById('attr-ordem').value   = dados.ordem   || 0;
    document.getElementById('modal-atributo-titulo').textContent =
      dados.id ? 'Editar atributo' : 'Novo atributo';
    atualizarPreview();
    modal.style.display = 'flex';
    setTimeout(() => nomeInput.focus(), 100);
  }

  function slugify(str) {
    return str.toLowerCase()
      .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
      .replace(/[^a-z0-9\s_-]/g, '').replace(/\s+/g, '_').trim();
  }

  nomeInput?.addEventListener('input', function () {
    if (!slugInput._edited) slugInput.value = slugify(this.value);
    atualizarPreview();
  });
  slugInput?.addEventListener('input', () => { slugInput._edited = true; });

  function atualizarPreview() {
    const display = document.getElementById('attr-display')?.value;
    const demo    = document.getElementById('attr-display-demo');
    if (!demo) return;

    const nome = nomeInput?.value || 'Valor';
    const exemplos = { 'P': '#e2e8f0', 'M': '#cbd5e1', 'G': '#94a3b8', 'GG': '#64748b' };

    if (display === 'button') {
      demo.innerHTML = ['P','M','G','GG'].map(v =>
        `<span class="attr-preview-btn">${v}</span>`
      ).join('');
    } else if (display === 'color_swatch') {
      demo.innerHTML = Object.entries(exemplos).map(([k,c]) =>
        `<span class="attr-preview-swatch" style="background:${c}" title="${k}"></span>`
      ).join('');
    } else if (display === 'text') {
      demo.innerHTML = `<input class="form-control form-control--sm" style="max-width:160px;pointer-events:none;" placeholder="${nome}">`;
    } else if (display === 'select') {
      demo.innerHTML = `<select class="form-control form-control--sm" style="max-width:160px;pointer-events:none;"><option>— ${nome} —</option></select>`;
    }
  }

  document.getElementById('attr-display')?.addEventListener('change', atualizarPreview);

  document.getElementById('btn-novo-atributo')?.addEventListener('click', () => abrirModal());
  document.getElementById('btn-close-modal-atributo')?.addEventListener('click', () => modal.style.display = 'none');
  document.getElementById('btn-cancelar-atributo')?.addEventListener('click', () => modal.style.display = 'none');

  $(document).on('click', '.btn-editar-atributo', function () {
    abrirModal({
      id     : $(this).data('id'),
      nome   : $(this).data('nome'),
      slug   : $(this).data('slug'),
      papel  : $(this).data('papel'),
      display: $(this).data('display'),
      ordem  : $(this).data('ordem'),
    });
  });

  document.getElementById('btn-salvar-atributo')?.addEventListener('click', function () {
    const $btn = $(this);
    $btn.prop('disabled', true).text('Salvando...');

    $.post(BASE_URL + '/admin/atributos/salvar',
      $(formAttr).serialize(), function (res) {
        $btn.prop('disabled', false).text('Salvar');
        if (!res.ok) { showToast(res.msg, 'error'); return; }
        showToast(res.msg, 'success');
        modal.style.display = 'none';
        setTimeout(() => window.location.reload(), 600);
      }, 'json'
    );
  });

  $(document).on('click', '.btn-excluir-atributo', function () {
    const id   = $(this).data('id');
    const nome = $(this).data('nome');
    if (!confirm(`Excluir o atributo "${nome}"?`)) return;

    $.post(BASE_URL + '/admin/atributos/excluir', {
      id, _csrf_token: CSRF_TOKEN,
    }, function (res) {
      if (res.ok) {
        $(`tr[data-id="${id}"]`).fadeOut(250, function () { $(this).remove(); });
        showToast(res.msg, 'success');
      } else {
        showToast(res.msg, 'error');
      }
    }, 'json');
  });

  // admin/assets/js/admin.js — adicionar junto com o bloco de atributos

  // Toggle painel de valores
  $(document).on('click', '.btn-toggle-valores', function () {
    const id  = $(this).data('id');
    const row = $(`#valores-row-${id}`);
    row.toggle();
    $(this).toggleClass('btn-primary btn-ghost');
  });

  // Abrir modal de novo valor
  $(document).on('click', '.btn-add-valor', function () {
    const tipoId      = $(this).data('tipo-id');
    const tipoNome    = $(this).data('tipo-nome');
    const tipoDisplay = $(this).data('tipo-display');

    $('#val-id').val(0);
    $('#val-tipo-id').val(tipoId);
    $('#val-valor').val('');
    $('#val-cor-hex').val('#FF0000');
    $('#val-cor-picker').val('#ff0000');
    $('#val-ordem').val(0);
    $('#modal-valor-titulo').text('Adicionar valor — ' + tipoNome);
    $('#val-cor-group').toggle(tipoDisplay === 'color_swatch');
    $('#modal-valor').show();
    setTimeout(() => $('#val-valor').focus(), 100);
  });

  // Editar valor
  $(document).on('click', '.btn-edit-valor', function () {
    const $btn = $(this);
    $('#val-id').val($btn.data('id'));
    $('#val-tipo-id').val($btn.data('tipo-id'));
    $('#val-valor').val($btn.data('valor'));
    const hex = $btn.data('hex') || '#ff0000';
    $('#val-cor-hex').val(hex.toUpperCase());
    $('#val-cor-picker').val(hex);
    $('#val-ordem').val($btn.data('ordem') || 0);
    $('#val-cor-group').toggle($btn.data('display') === 'color_swatch');
    $('#modal-valor-titulo').text('Editar valor');
    $('#modal-valor').show();
    setTimeout(() => $('#val-valor').focus(), 100);
  });

  // Sync color picker
  $(document).on('input', '#val-cor-picker', function () {
    $('#val-cor-hex').val(this.value.toUpperCase());
  });
  $(document).on('input', '#val-cor-hex', function () {
    if (/^#[0-9a-fA-F]{6}$/.test(this.value)) {
      $('#val-cor-picker').val(this.value);
    }
  });

  // Fechar modal valor
  $('#btn-close-modal-valor, #btn-cancelar-valor').on('click', () => {
    $('#modal-valor').hide();
  });

  // Salvar valor
  $('#btn-salvar-valor').on('click', function () {
    const $btn = $(this);
    $btn.prop('disabled', true).text('Salvando...');

    const tipoId = $('#val-tipo-id').val();
    const dados  = {
      id              : $('#val-id').val(),
      atributo_tipo_id: tipoId,
      valor           : $('#val-valor').val().trim(),
      valor_hex       : $('#val-cor-group').is(':visible') ? $('#val-cor-hex').val() : '',
      ordem           : $('#val-ordem').val(),
      _csrf_token     : CSRF_TOKEN,
    };

    if (!dados.valor) {
      showToast('Informe o valor.', 'error');
      $btn.prop('disabled', false).text('Salvar valor');
      return;
    }

    $.post(BASE_URL + '/admin/atributos/valor/salvar', dados, function (res) {
      $btn.prop('disabled', false).text('Salvar valor');
      if (!res.ok) { showToast(res.msg, 'error'); return; }

      $('#modal-valor').hide();
      showToast(res.msg, 'success');

      // Recarrega a lista de valores inline via reload parcial
      // (mais simples: recarrega a página)
      setTimeout(() => window.location.reload(), 500);
    }, 'json');
  });

  // Remover valor
  $(document).on('click', '.btn-del-valor', function () {
    const id = $(this).data('id');
    if (!confirm('Remover este valor?')) return;

    $.post(BASE_URL + '/admin/atributos/valor/excluir', {
      id, _csrf_token: CSRF_TOKEN,
    }, function (res) {
      if (res.ok) {
        $(`.attr-valor-item[data-id="${id}"]`).slideUp(200, function () {
          $(this).remove();
        });
        showToast(res.msg, 'info');
      }
    }, 'json');
  });

})();

// ── SKU: modal de atributos ───────────────────────────────
(function () {
  if (!document.getElementById('pe-skus-tbody')) return;

  let skuKeyAtual    = null;
  let selecaoAtual   = {}; // { tipoId: { valor, hex } }

  const modal        = document.getElementById('modal-sku-attrs');
  const modalBody    = document.getElementById('modal-attrs-body');
  const tiposVar     = (window.ATRIBUTOS_VARIACAO || [])
                         .filter(t => t.papel === 'variacao');
  // const valoresPorTipo = window.ATRIBUTOS_VALORES || {};
  const valoresPorTipo = window.ATRIBUTOS_VALORES  || {};

  // ── Abre modal de atributos do SKU ──────────────────────
  $(document).on('click', '.pe-sku-attrs-btn', function () {
    skuKeyAtual  = $(this).data('sku-key');
    selecaoAtual = lerSelecaoAtual(skuKeyAtual);

    modalBody.innerHTML = buildModalBody(selecaoAtual);
    modal.style.display = 'flex';
  });

  // Lê os hiddens atuais da linha como selecaoAtual
  function lerSelecaoAtual(skuKey) {
    const sel = {};
    const row = document.querySelector(
      `.pe-sku-row[data-sku-id="${skuKey}"], [data-sku-id="${skuKey}"]`
    );
    if (!row) return sel;

    row.querySelectorAll('input[type="hidden"][name*="[atributos]"]').forEach(input => {
      const match = input.name.match(/\[atributos\]\[(\d+)\]/);
      if (match && input.value) {
        const tipoId = match[1];
        const tipo   = tiposVar.find(t => String(t.id) === tipoId);
        const valores = valoresPorTipo[tipoId] || [];
        const vObj    = valores.find(v => v.valor === input.value);
        sel[tipoId] = {
          valor: input.value,
          hex  : vObj?.valor_hex || null,
          nome : tipo?.nome || '',
        };
      }
    });
    return sel;
  }

  // Constrói o body do modal
  function buildModalBody(selecao) {
    if (!tiposVar.length) {
      return '<p style="color:var(--text-3);font-size:13px;">Nenhum tipo de variação cadastrado.</p>';
    }

    let html = '';
    tiposVar.forEach(tipo => {
      const valores  = valoresPorTipo[tipo.id] || [];
      const selAtual = selecao[tipo.id]?.valor || '';
      const display  = tipo.tipo_display;

      html += `
        <div class="modal-attr-grupo" data-tipo-id="${tipo.id}">
          <div class="modal-attr-label">${tipo.nome}</div>
          <div class="modal-attr-opcoes">`;

      if (valores.length === 0) {
        // Sem valores pré-definidos: input livre
        html += `
            <input type="text"
                   class="form-control form-control--sm modal-attr-livre"
                   data-tipo-id="${tipo.id}"
                   value="${selAtual}"
                   placeholder="Digite o valor...">`;
      } else {
        // Com valores: botões ou swatches
        valores.forEach(v => {
          const isSel = v.valor === selAtual;

          if (display === 'color_swatch' && v.valor_hex) {
            html += `
              <button type="button"
                      class="pe-sku-swatch-btn modal-attr-opt ${isSel ? 'selected' : ''}"
                      data-tipo-id="${tipo.id}"
                      data-valor="${v.valor}"
                      data-hex="${v.valor_hex || ''}"
                      style="background:${v.valor_hex}"
                      title="${v.valor}">
                ${isSel ? `<svg width="10" height="10" viewBox="0 0 24 24" fill="none"
                  stroke="white" stroke-width="3.5" stroke-linecap="round">
                  <polyline points="20 6 9 17 4 12"/></svg>` : ''}
              </button>`;
          } else {
            html += `
              <button type="button"
                      class="pe-sku-opt-btn modal-attr-opt ${isSel ? 'selected' : ''}"
                      data-tipo-id="${tipo.id}"
                      data-valor="${v.valor}"
                      data-hex="">
                ${v.valor}
              </button>`;
          }
        });
      }

      html += `</div></div>`;
    });

    return html;
  }

  // ── Clique em opção dentro do modal ─────────────────────
  $(document).on('click', '.modal-attr-opt', function () {
    const $btn    = $(this);
    const tipoId  = $btn.data('tipo-id');
    const valor   = $btn.data('valor');
    const hex     = $btn.data('hex') || null;
    const isSel   = $btn.hasClass('selected');
    const isSwatch= $btn.hasClass('pe-sku-swatch-btn');

    console.log(isSel, isSwatch, hex);
    

    // Desselecionado ao clicar no mesmo: toggle off
    if (isSel) {
      $btn.removeClass('selected').html('');
      delete selecaoAtual[tipoId];
      return;
    }

    // Limpa outros do mesmo tipo
    $btn.closest('.modal-attr-opcoes').find('.modal-attr-opt').each(function () {
      $(this).removeClass('selected');
      if ($(this).hasClass('pe-sku-swatch-btn')) $(this).html('2');
    });

    // Seleciona
    $btn.addClass('selected');
    if (isSwatch) {
      $btn.html(`<svg width="10" height="10" viewBox="0 0 24 24" fill="none"
                  stroke="white" stroke-width="3.5" stroke-linecap="round">
                  <polyline points="20 6 9 17 4 12"/>
                </svg>`);
    }

    selecaoAtual[tipoId] = { valor, hex, nome: '' };
  });

  // Input livre
  $(document).on('input', '.modal-attr-livre', function () {
    const tipoId = $(this).data('tipo-id');
    const val    = $(this).val().trim();
    if (val) {
      selecaoAtual[tipoId] = { valor: val, hex: null, nome: '' };
    } else {
      delete selecaoAtual[tipoId];
    }
  });

  // ── Confirmar seleção → atualiza a linha ─────────────────
  document.getElementById('btn-confirmar-attrs')?.addEventListener('click', () => {
    if (!skuKeyAtual) return;

    const $badges = $(`#sku-badges-${skuKeyAtual}`);
    if (!$badges.length) return;

    // Remove badges e hiddens existentes (mantém o botão)
    $badges.find('.pe-sku-badge').remove();

    // Lê inputs livres do modal
    document.querySelectorAll('.modal-attr-livre').forEach(input => {
      const tipoId = input.dataset.tipoId;
      const val    = input.value.trim();
      if (val) selecaoAtual[tipoId] = { valor: val, hex: null, nome: '' };
      else     delete selecaoAtual[tipoId];
    });

    // Nome dos tipos
    tiposVar.forEach(t => {
      if (selecaoAtual[t.id]) selecaoAtual[t.id].nome = t.nome;
    });

    // Reconstrói badges
    const $btn = $badges.find('.pe-sku-attrs-btn');
    Object.entries(selecaoAtual).forEach(([tipoId, info]) => {
      const swatchHtml = info.hex
        ? `<span class="pe-sku-badge-swatch" style="background:${info.hex}"></span>`
        : '';
      
        const badge = `
          <span class="pe-sku-badge" data-tipo-id="${tipoId}">
            ${swatchHtml}
            <span class="pe-sku-badge-label">${info.nome}:</span>
            <span class="pe-sku-badge-valor">${info.valor}</span>
            <input type="hidden"
                  name="skus[${skuKeyAtual}][atributos][${tipoId}]"
                  value="${info.valor}">
          </span>`;
      $btn.before(badge);
    });

    modal.style.display = 'none';
    skuKeyAtual = null;
    // somarEstoqueSKUs();
  });

  // Fechar modal
  $('#btn-close-modal-attrs, #btn-cancelar-attrs').on('click', () => {
    modal.style.display = 'none';
    skuKeyAtual = null;
  });

  // ── Adicionar SKU ────────────────────────────────────────
  let skuCounter = Date.now();

  document.getElementById('btn-add-sku')?.addEventListener('click', () => {
    skuCounter++;
    const key   = 'new_' + skuCounter;
    const empty = document.getElementById('pe-skus-empty');
    if (empty) empty.style.display = 'none';

    const tr = document.createElement('tr');
    tr.className      = 'pe-sku-row';
    tr.dataset.skuId  = key;
    tr.innerHTML      = buildSkuRowHTML(key);
    document.getElementById('pe-skus-tbody')?.appendChild(tr);
    tr.querySelector('input[type="text"]')?.focus();
    somarEstoqueSKUs();
  });

  function buildSkuRowHTML(key) {
    return `
      <td>
        <input type="text" name="skus[${key}][sku]"
               class="form-control form-control--sm"
               placeholder="SKU-001"
               style="font-family:var(--font-mono);font-size:12px;">
      </td>
      <td>
        <div class="pe-sku-attrs-badges" id="sku-badges-${key}">
          <button type="button" class="pe-sku-attrs-btn"
                  data-sku-key="${key}" title="Selecionar atributos">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
              <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
              <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
            </svg>
            Editar
          </button>
        </div>
      </td>
      <td>
        <div class="pe-price-input-wrap">
          <span class="pe-price-prefix" style="font-size:11px;">R$</span>
          <input type="number" name="skus[${key}][preco]"
                 class="form-control pe-price-input"
                 value="" step="0.01" min="0" style="font-size:13px;">
        </div>
      </td>
      <td>
        <div class="pe-price-input-wrap">
          <span class="pe-price-prefix" style="font-size:11px;">R$</span>
          <input type="number" name="skus[${key}][preco_promo]"
                 class="form-control pe-price-input"
                 placeholder="—" step="0.01" min="0" style="font-size:13px;">
        </div>
      </td>
      <td>
        <input type="number" name="skus[${key}][estoque]"
               class="form-control form-control--sm pe-sku-estoque"
               value="0" min="0">
      </td>
      <td style="text-align:center;">
        <label class="pe-toggle-mini">
          <input type="checkbox" name="skus[${key}][ativo]" value="1" checked>
          <span class="pe-toggle-mini-track"></span>
        </label>
      </td>
      <td>
        <button type="button" class="pe-sku-del" title="Remover">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <line x1="18" y1="6" x2="6"  y2="18"/>
            <line x1="6"  y1="6" x2="18" y2="18"/>
          </svg>
        </button>
      </td>`;
  }

  // ── Remover SKU ──────────────────────────────────────────
  $(document).on('click', '.pe-sku-del', async function () {
    const ok = await adminConfirm({
      titulo   : 'Remover variação?',
      mensagem : 'A variação será excluida permanentemente, deseja mesmo excluir? <br><strong>*A confirmação será aplicada após salvar o produto.</strong>',
      tipo     : 'warning',
      confirmar: 'Excluir variação',
    });

    if (!ok) return;

    $(this).closest('.pe-sku-row').fadeOut(200, function () {
      $(this).remove();
      // somarEstoqueSKUs();
    });
  });

})();


// ── Agrupadores com valores pré-definidos ─────────────────
// ── Agrupadores ───────────────────────────────────────────
// (function () {
//   if (!document.getElementById('pe-agrupadores-list')) return;

//   const valoresPorTipo = window.ATRIBUTOS_VALORES  || {};
//   const tiposAg        = (window.ATRIBUTOS_VARIACAO || [])
//                            .filter(t => t.papel === 'agrupador');

//   // Estado do modal
//   let agTipoIdAtual   = null;
//   let agTipoNomeAtual = '';
//   let agDisplayAtual  = 'button';
//   let agModo          = 'novo'; // 'novo' | 'editar'

//   const modal       = document.getElementById('modal-agrupador');
//   const opcoesEl    = document.getElementById('modal-ag-opcoes');
//   const textoEl     = document.getElementById('modal-ag-texto');
//   const corGroup    = document.getElementById('modal-ag-cor-group');
//   const valorArea   = document.getElementById('modal-ag-valor-area');
//   const tipoGroup   = document.getElementById('modal-ag-tipo-group');
//   const tipoPick    = document.getElementById('modal-tipo-atributo');

//   // ── Abre modal para NOVO agrupador ─────────────────────
//   document.getElementById('btn-add-agrupador')
//     ?.addEventListener('click', () => {
//       agModo = 'novo';
//       agTipoIdAtual = null;
//       if (tipoPick) tipoPick.value = '';
//       if (tipoGroup) tipoGroup.style.display = '';
//       if (valorArea) valorArea.style.display = 'none';
//       if (textoEl)   textoEl.value = '';
//       document.getElementById('modal-ag-titulo').textContent = 'Adicionar atributo';
//       modal.style.display = 'flex';
//       setTimeout(() => tipoPick?.focus(), 100);
//     });

//   // ── Abre modal para EDITAR agrupador existente ─────────
//   $(document).on('click', '#pe-agrupadores-list .pe-ag-edit-btn', function () {
//     agModo          = 'editar';
//     agTipoIdAtual   = $(this).data('tipo-id');
//     agTipoNomeAtual = $(this).data('tipo-nome');
//     agDisplayAtual  = $(this).data('tipo-display');

//     const valorAtual = $(`#pe-agrupadores-list .pe-attr-row[data-tipo-id="${agTipoIdAtual}"]`)
//                         .find('.pe-ag-hidden').val() || '';
//     const hexAtual   = $(`#pe-agrupadores-list .pe-attr-row[data-tipo-id="${agTipoIdAtual}"]`)
//                         .find('.pe-ag-hex').val() || '';

//     // Esconde seletor de tipo — já está fixo
//     if (tipoGroup) tipoGroup.style.display = 'none';

//     document.getElementById('modal-ag-titulo').textContent =
//       agTipoNomeAtual + ' — Selecionar valor';

//     // Renderiza opções
//     renderizarOpcoesModal(agTipoIdAtual, agDisplayAtual, valorAtual);

//     // Preenche input texto e cor
//     if (textoEl) textoEl.value = valorAtual;
//     if (hexAtual) {
//       document.getElementById('modal-ag-cor-picker').value = hexAtual;
//       document.getElementById('modal-ag-cor-hex').value    = hexAtual.toUpperCase();
//     }

//     modal.style.display = 'flex';
//     setTimeout(() => textoEl?.focus(), 100);
//   });

//   // ── Ao selecionar tipo no modo "novo" ──────────────────
//   tipoPick?.addEventListener('change', function () {
//     const opt = this.options[this.selectedIndex];
//     agTipoIdAtual   = this.value;
//     agTipoNomeAtual = opt.dataset.nome    || '';
//     agDisplayAtual  = opt.dataset.display || 'button';

//     if (!agTipoIdAtual) { valorArea.style.display = 'none'; return; }

//     // Verifica se já existe na lista
//     if (document.querySelector(`#pe-agrupadores-list .pe-attr-row[data-tipo-id="${agTipoIdAtual}"]`)) {
//       showToast('Este atributo já foi adicionado.', 'warning');
//       this.value = '';
//       valorArea.style.display = 'none';
//       return;
//     }

//     if (textoEl) textoEl.value = '';
//     renderizarOpcoesModal(agTipoIdAtual, agDisplayAtual, '');
//   });

//   // ── Renderiza os botões/swatches no modal ──────────────
//   function renderizarOpcoesModal(tipoId, display, valorSel) {
//     const valores = valoresPorTipo[tipoId] || [];
//     valorArea.style.display = '';
//     corGroup.style.display  = display === 'color_swatch' ? '' : 'none';

//     if (valores.length === 0) {
//       opcoesEl.style.display = 'none';
//       opcoesEl.innerHTML = '';
//       return;
//     }

//     opcoesEl.style.display = '';
//     opcoesEl.dataset.display = display;

//     opcoesEl.innerHTML = valores.map(v => {
//       const isSel = v.valor === valorSel;

//       if (display === 'color_swatch' && v.valor_hex) {
//         return `<button type="button"
//                         class="pe-sku-swatch-btn pe-modal-ag-opt ${isSel ? 'selected' : ''}"
//                         data-valor="${v.valor}"
//                         data-hex="${v.valor_hex}"
//                         style="background:${v.valor_hex}"
//                         title="${v.valor}">
//           ${isSel ? checkSvg() : ''}
//         </button>`;
//       }

//       return `<button type="button"
//                       class="pe-sku-opt-btn pe-modal-ag-opt ${isSel ? 'selected' : ''}"
//                       data-valor="${v.valor}"
//                       data-hex="">
//         ${v.valor}
//       </button>`;
//     }).join('');
//   }

//   function checkSvg() {
//     return `<svg width="10" height="10" viewBox="0 0 24 24" fill="none"
//                 stroke="white" stroke-width="3.5" stroke-linecap="round">
//               <polyline points="20 6 9 17 4 12"/>
//             </svg>`;
//   }

//   // ── Clique em opção no modal ───────────────────────────
//   $(document).on('click', '#pe-agrupadores-list .pe-modal-ag-opt', function () {
//     const $btn    = $(this);
//     const isSel   = $btn.hasClass('selected');
//     const display = opcoesEl.dataset.display;

//     // Limpa todos
//     $('.pe-modal-ag-opt').each(function () {
//       $(this).removeClass('selected');
//       if ($(this).hasClass('pe-sku-swatch-btn')) $(this).html('');
//     });

//     if (isSel) { textoEl.value = ''; return; }

//     const valor = $btn.data('valor');
//     const hex   = $btn.data('hex') || '';

//     $btn.addClass('selected');
//     if (display === 'color_swatch') {
//       $btn.html(checkSvg());
//     }

//     textoEl.value = valor;

//     if (hex) {
//       document.getElementById('modal-ag-cor-picker').value = hex;
//       document.getElementById('modal-ag-cor-hex').value    = hex.toUpperCase();
//     }
//   });

//   // Input livre → destaca botão correspondente
//   textoEl?.addEventListener('input', function () {
//     const val = this.value.trim();
//     $('.pe-modal-ag-opt').each(function () {
//       const isMatch = $(this).data('valor') === val;
//       $(this).toggleClass('selected', isMatch);
//       if ($(this).hasClass('pe-sku-swatch-btn')) {
//         $(this).html(isMatch ? checkSvg() : '');
//       }
//     });
//   });

//   // Color picker sync
//   document.getElementById('modal-ag-cor-picker')?.addEventListener('input', function () {
//     document.getElementById('modal-ag-cor-hex').value = this.value.toUpperCase();
//   });
//   document.getElementById('modal-ag-cor-hex')?.addEventListener('input', function () {
//     if (/^#[0-9a-fA-F]{6}$/.test(this.value)) {
//       document.getElementById('modal-ag-cor-picker').value = this.value;
//     }
//   });

//   // ── Fechar modal ───────────────────────────────────────
//   $('#btn-close-modal-agrupador, #btn-cancelar-agrupador').on('click', () => {
//     modal.style.display = 'none';
//   });

//   // ── Confirmar ──────────────────────────────────────────
//   document.getElementById('btn-confirmar-agrupador')
//     ?.addEventListener('click', () => {
//       // Resolve tipoId dependendo do modo
//       const tipoId = agModo === 'editar'
//                      ? agTipoIdAtual
//                      : (tipoPick?.value || null);

//       if (!tipoId) { showToast('Selecione o tipo.', 'error'); return; }

//       const valor = textoEl?.value.trim();
//       if (!valor) { showToast('Selecione ou digite um valor.', 'error'); return; }

//       const hex   = document.getElementById('modal-ag-cor-hex')?.value || '';
//       const opt   = tipoPick?.options[tipoPick.selectedIndex];
//       const nome  = agModo === 'editar' ? agTipoNomeAtual : (opt?.dataset.nome || '');
//       const disp  = agModo === 'editar' ? agDisplayAtual  : (opt?.dataset.display || 'button');

//       if (agModo === 'editar') {
//         // Atualiza a linha existente
//         const $row = $(`#pe-agrupadores-list .pe-attr-row[data-tipo-id="${tipoId}"]`);
//         $row.find('.pe-ag-hidden').val(valor);
//         $row.find('.pe-ag-hex').val(hex);
//         atualizarBadge(tipoId, valor, hex, disp);

//       } else {
//         // Verifica duplicata
//         if (document.querySelector(`#pe-agrupadores-list .pe-attr-row[data-tipo-id="${tipoId}"]`)) {
//           showToast('Este atributo já foi adicionado.', 'warning');
//           return;
//         }
//         // Cria nova linha
//         adicionarLinhaAgrupador(tipoId, nome, disp, valor, hex);
//       }

//       modal.style.display = 'none';
//       if (tipoPick) tipoPick.value = '';
//     });

//   // ── Atualiza badge na linha ────────────────────────────
//   function atualizarBadge(tipoId, valor, hex, display) {
//     const $wrap = $(`#ag-badge-${tipoId}`);
//     let   inner = '';

//     if (display === 'color_swatch' && hex) {
//       inner = `<span class="pe-sku-badge-swatch" style="background:${hex}"></span>`;
//     }
//     inner += `<span class="pe-sku-badge-valor">${valor}</span>`;

//     $wrap.html(`<span class="pe-sku-badge">${inner}</span>`);
//   }

//   // ── Cria linha de agrupador (modo novo) ────────────────
//   function adicionarLinhaAgrupador(tipoId, nome, display, valor, hex) {
//     let badgeInner = '';
//     if (display === 'color_swatch' && hex) {
//       badgeInner += `<span class="pe-sku-badge-swatch" style="background:${hex}"></span>`;
//     }
//     badgeInner += `<span class="pe-sku-badge-valor">${valor}</span>`;

//     const row = document.createElement('div');
//     row.className      = 'pe-attr-row';
//     row.dataset.tipoId = tipoId;
//     row.innerHTML = `
//       <div class="pe-attr-tipo">
//         <span>${nome}</span>
//         <span class="pe-attr-display-type">${display}</span>
//       </div>
//       <div class="pe-attr-valor-wrap">
//         <input type="hidden" class="pe-ag-hidden"
//                data-tipo-id="${tipoId}" value="${valor}">
//         <input type="hidden" class="pe-ag-hex"
//                data-tipo-id="${tipoId}" value="${hex}">
//         <div class="pe-ag-badge-wrap" id="ag-badge-${tipoId}">
//           <span class="pe-sku-badge">${badgeInner}</span>
//         </div>
//         <button type="button"
//                 class="pe-sku-attrs-btn pe-ag-edit-btn"
//                 data-tipo-id="${tipoId}"
//                 data-tipo-nome="${nome}"
//                 data-tipo-display="${display}"
//                 title="Editar valor">
//           <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
//                stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
//             <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
//             <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
//           </svg>
//           Editar
//         </button>
//       </div>
//       <button type="button" class="pe-attr-del">×</button>`;

//     document.getElementById('pe-agrupadores-list')?.appendChild(row);
//   }

//   // ── Deletar agrupador ──────────────────────────────────
//   $(document).on('click', '.pe-attr-del', function () {
//     $(this).closest('.pe-attr-row').slideUp(200, function () {
//       $(this).remove();
//     });
//   });

// })();

// ── Card de família no editor de produto ─────────────────
(function () {
  if (!document.getElementById('pe-familia-card')) return;

  const produtoId = () => parseInt(document.getElementById('produto-id')?.value || 0);

  // ── Helpers de UI ──────────────────────────────────────

  function setFamiliaVinculada(familia) {
    const campoId = document.getElementById('campo-familia-id');
    if (campoId) campoId.value = familia.id;

    const card = document.getElementById('pe-familia-card');

    card.innerHTML = `
      <div class="pe-familia-info" id="pe-familia-info">
        <div class="pe-familia-icon">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
            <rect x="2" y="3" width="6" height="18" rx="2"/>
            <rect x="9" y="3" width="6" height="18" rx="2"/>
            <rect x="16" y="3" width="6" height="18" rx="2"/>
          </svg>
        </div>
        <div class="pe-familia-details">
          <span class="pe-familia-nome">${familia.nome}</span>
          <span class="pe-familia-meta">
            ${familia.total ?? '?'} produto(s) nesta família
          </span>
        </div>
        <div class="pe-familia-actions">
          <button type="button" class="btn btn-sm btn-ghost"
                  id="btn-trocar-familia">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
              <polyline points="17 1 21 5 17 9"/>
              <path d="M3 11V9a4 4 0 014-4h14"/>
              <polyline points="7 23 3 19 7 15"/>
              <path d="M21 13v2a4 4 0 01-4 4H3"/>
            </svg>
            Trocar
          </button>
          <button type="button" class="btn btn-sm btn-ghost"
                  id="btn-remover-familia">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
              <line x1="18" y1="6" x2="6"  y2="18"/>
              <line x1="6"  y1="6" x2="18" y2="18"/>
            </svg>
            Remover
          </button>
        </div>
      </div>
      <input type="hidden" name="familia_id" id="campo-familia-id"
             value="${familia.id}">`;
  }

  function setFamiliaVazia() {
    const campoId = document.getElementById('campo-familia-id');
    if (campoId) campoId.value = '';

    const card = document.getElementById('pe-familia-card');
    card.innerHTML = `
      <div class="pe-familia-vazia" id="pe-familia-vazia">
        <div class="pe-familia-vazia-icon">
          <svg width="28" height="28" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
            <rect x="2" y="3" width="6" height="18" rx="2"/>
            <rect x="9" y="3" width="6" height="18" rx="2"/>
            <rect x="16" y="3" width="6" height="18" rx="2"/>
          </svg>
        </div>
        <p class="pe-familia-vazia-msg">
          Este produto não pertence a nenhuma família.
        </p>
        <p class="pe-familia-vazia-hint">
          Famílias conectam produtos com variações visuais (cores, estampas)
          que possuem URLs diferentes.
        </p>
        <div class="pe-familia-vazia-actions">
          <button type="button" class="btn btn-outline btn-sm"
                  id="btn-buscar-familia">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
              <circle cx="11" cy="11" r="8"/>
              <line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            Vincular a família existente
          </button>
          <button type="button" class="btn btn-primary btn-sm"
                  id="btn-criar-familia">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
              <line x1="12" y1="5" x2="12" y2="19"/>
              <line x1="5"  y1="12" x2="19" y2="12"/>
            </svg>
            Criar nova família
          </button>
        </div>
      </div>
      <input type="hidden" name="familia_id" id="campo-familia-id" value="">`;
  }

  // ── Modal: buscar família ───────────────────────────────
  $(document).on('click', '#btn-buscar-familia, #btn-trocar-familia', function () {
    document.getElementById('familia-search-input').value = '';
    document.getElementById('familia-search-results').innerHTML =
      '<p class="pe-familia-results-hint">Digite para buscar famílias cadastradas.</p>';
    document.getElementById('modal-buscar-familia').style.display = 'flex';
    setTimeout(() => document.getElementById('familia-search-input')?.focus(), 100);
  });

  $('#btn-close-modal-buscar-familia, #btn-cancelar-buscar-familia').on('click', () => {
    document.getElementById('modal-buscar-familia').style.display = 'none';
  });

  // Busca com debounce
  let searchTimer;
  $(document).on('input', '#familia-search-input', function () {
    clearTimeout(searchTimer);
    const q = this.value.trim();
    const resultsEl = document.getElementById('familia-search-results');

    if (q.length < 1) {
      resultsEl.innerHTML = '<p class="pe-familia-results-hint">Digite para buscar.</p>';
      return;
    }

    resultsEl.innerHTML = '<p class="pe-familia-results-hint">Buscando...</p>';

    searchTimer = setTimeout(() => {
      $.get(BASE_URL + '/admin/familias/buscar', { q }, function (res) {
        if (!res.ok || !res.familias.length) {
          resultsEl.innerHTML =
            '<p class="pe-familia-results-hint">Nenhuma família encontrada.</p>';
          return;
        }

        resultsEl.innerHTML = res.familias.map(f => `
          <div class="pe-familia-result-item" data-id="${f.id}" data-nome="${f.nome}"
               data-total="${f.total_membros}">
            <div class="pe-familia-result-info">
              <span class="pe-familia-result-nome">${f.nome}</span>
              <span class="pe-familia-result-meta">
                ${f.total_membros} produto(s)
              </span>
            </div>
            <button type="button" class="btn btn-sm btn-primary btn-vincular-familia"
                    data-id="${f.id}" data-nome="${f.nome}"
                    data-total="${f.total_membros}">
              Vincular
            </button>
          </div>`).join('');
      }, 'json');
    }, 300);
  });

  // Vincular família existente
  $(document).on('click', '.btn-vincular-familia', function () {
    const id    = $(this).data('id');
    const nome  = $(this).data('nome');
    const total = $(this).data('total');
    const pid   = produtoId();

    if (!pid) {
      showToast('Salve o produto antes de vincular uma família.', 'warning');
      document.getElementById('modal-buscar-familia').style.display = 'none';
      return;
    }

    $.post(BASE_URL + '/admin/familias/vincular', {
      produto_id  : pid,
      familia_id  : id,
      _csrf_token : CSRF_TOKEN,
    }, function (res) {
      if (!res.ok) { showToast(res.msg, 'error'); return; }

      setFamiliaVinculada({ id: res.id, nome: res.nome, total: res.total });
      document.getElementById('modal-buscar-familia').style.display = 'none';
      showToast('Família vinculada!', 'success');
    }, 'json');
  });

  // ── Modal: criar família ────────────────────────────────
  $(document).on('click', '#btn-criar-familia', function () {
    document.getElementById('nova-familia-nome').value = '';
    document.getElementById('modal-criar-familia').style.display = 'flex';
    setTimeout(() => document.getElementById('nova-familia-nome')?.focus(), 100);
  });

  $('#btn-close-modal-criar-familia, #btn-cancelar-criar-familia').on('click', () => {
    document.getElementById('modal-criar-familia').style.display = 'none';
  });

  $(document).on('keydown', '#nova-familia-nome', e => {
    if (e.key === 'Enter') {
      e.preventDefault();
      document.getElementById('btn-salvar-nova-familia')?.click();
    }
  });

  document.getElementById('btn-salvar-nova-familia')?.addEventListener('click', function () {
    const nome = document.getElementById('nova-familia-nome')?.value.trim();
    if (!nome) {
      showToast('Digite o nome da família.', 'error');
      return;
    }

    const $btn = $(this);
    $btn.prop('disabled', true).text('Criando...');

    const pid = produtoId();

    // Cria a família
    $.post(BASE_URL + '/admin/familias/criar', {
      nome        : nome,
      _csrf_token : CSRF_TOKEN,
    }, function (res) {
      if (!res.ok) {
        $btn.prop('disabled', false).text('Criar e vincular');
        showToast(res.msg || 'Erro ao criar.', 'error');
        return;
      }

      // Se produto já existe, vincula imediatamente
      if (pid) {
        $.post(BASE_URL + '/admin/familias/vincular', {
          produto_id  : pid,
          familia_id  : res.id,
          _csrf_token : CSRF_TOKEN,
        }, function (r) {
          $btn.prop('disabled', false).text('Criar e vincular');
          document.getElementById('modal-criar-familia').style.display = 'none';

          if (r.ok) {
            setFamiliaVinculada({ id: r.id, nome: r.nome, total: r.total });
            showToast('Família criada e vinculada!', 'success');
          }
        }, 'json');
      } else {
        // Produto novo: apenas salva no hidden e atualiza a UI
        $btn.prop('disabled', false).text('Criar e vincular');
        document.getElementById('modal-criar-familia').style.display = 'none';
        setFamiliaVinculada({ id: res.id, nome: res.nome, total: 1 });
        showToast('Família criada! Salve o produto para confirmar o vínculo.', 'info');
      }
    }, 'json');
  });

  // ── Remover família (com adminConfirm) ──────────────────
  $(document).on('click', '#btn-remover-familia', async function () {
    const ok = await adminConfirm({
      titulo    : 'Remover da família?',
      mensagem  : 'Este produto será desvinculado da família, mas continuará publicado normalmente.',
      tipo      : 'warning',
      confirmar : 'Sim, remover',
      cancelar  : 'Cancelar',
    });

    if (!ok) return;

    const pid = produtoId();
    if (!pid) { setFamiliaVazia(); return; }

    $.post(BASE_URL + '/admin/familias/desvincular', {
      produto_id  : pid,
      _csrf_token : CSRF_TOKEN,
    }, function (res) {
      if (res.ok) {
        setFamiliaVazia();
        showToast('Produto removido da família.', 'info');
      }
    }, 'json');
  });

})();

(function () {
    // ── Upload de vídeo → Cloudflare Stream (direto do browser) ──────────
    const $inputVideo = $('#clip-input-video');
    const $hiddenUid  = $('#clip-video-uid');

    if ($inputVideo.length && typeof StreamUpload !== 'undefined') {
      StreamUpload.init({
        fileInput:   $inputVideo[0],
        hiddenInput: $hiddenUid[0],
        name:        'clip-' + ($('[name="id"]').val() || 'novo'),

        onProgress: (pct) => {
          $('#clip-video-progress').show();
          $('#clip-video-bar').css('width', pct + '%');
          $('#clip-video-status').text(`Enviando… ${pct}%`);
        },
        onReady: (uid) => {
          $('#clip-video-bar').css({width:'100%', background:'#34d399'});
          $('#clip-video-status').text('Vídeo pronto ✓');
          showToast('Vídeo enviado e processado.', 'success');
        },
        onError: (msg) => {
          $('#clip-video-bar').css('background', '#f87171');
          $('#clip-video-status').text(msg);
          showToast(msg, 'error');
        },
      });
    }

    // ── Preview/label dos OUTROS uploads (poster, se mantido) ────────────
    $('.clip-upload-input').not('#clip-input-video').on('change', function () {
      if (!this.files[0]) return;
      const file  = this.files[0];
      const $area = $(this).closest('.clip-upload-area');
      const $empty = $area.find('.clip-upload-empty');
      const sizeMB = (file.size / 1024 / 1024).toFixed(1);
      $empty.find('p').html(`<strong>✓ ${file.name}</strong>`);
      $empty.find('small').text(`${sizeMB}MB selecionado`);
    });
  // ── Upload de arquivos: preview e label ─────────────
  // $('.clip-upload-input').on('change', function () {
  //   if (!this.files[0]) return;
  //   const file = this.files[0];
  //   const $area = $(this).closest('.clip-upload-area');
  //   const $empty = $area.find('.clip-upload-empty');

  //   const sizeMB = (file.size / 1024 / 1024).toFixed(1);
  //   $empty.find('p').html(`<strong>✓ ${file.name}</strong>`);
  //   $empty.find('small').text(`${sizeMB}MB selecionado`);
  // });

  // Drag & drop
  $('.clip-upload-area').on('dragover', function (e) {
    e.preventDefault();
    $(this).addClass('is-dragover');
  }).on('dragleave drop', function (e) {
    e.preventDefault();
    $(this).removeClass('is-dragover');
    if (e.type === 'drop') {
      const files = e.originalEvent.dataTransfer.files;
      const $input = $(this).find('.clip-upload-input');
      const dt = new DataTransfer();
      dt.items.add(files[0]);
      $input[0].files = dt.files;
      $input.trigger('change');
    }
  });

  // ── Mostrar/ocultar CTA genérico baseado no produto ──
  $('#clip-produto').on('change', function () {
    if ($(this).val()) {
      $('#clip-cta-generico').slideUp(200);
    } else {
      $('#clip-cta-generico').slideDown(200);
    }
  });

  // ── Salvar clip ─────────────────────────────────────
  $('#btn-salvar-clip').on('click', async function () {
    const $btn  = $(this);
    const $form = $('#form-clip');

    // Validação básica
    const titulo = $form.find('[name="titulo"]').val().trim();
    if (!titulo) {
      showToast('Informe o título do clip.', 'error');
      return;
    }

    // Sem vídeo no cadastro: bloqueia
    // const isEdit = !!$form.find('[name="id"]').val();
    // if (!isEdit && !$('#clip-input-video')[0].files[0]) {
    //   showToast('Selecione um vídeo.', 'error');
    //   return;
    // }
    const isEdit = !!$form.find('[name="id"]').val();
    // Com upload direto, o vídeo já está no Stream e o UID está no hidden.
    if (!isEdit && !$('#clip-video-uid').val()) {
      showToast('Envie um vídeo antes de salvar.', 'error');
      return;
    }
    $btn.prop('disabled', true).text('Salvando...');

    const fd = new FormData($form[0]);

    try {
      const res = await fetch(BASE_URL + '/admin/clips/salvar', {
        method: 'POST',
        body: fd,
      });
      const data = await res.json();

      if (data.ok) {
        showToast(data.msg || 'Clip salvo!', 'success');
        setTimeout(() => {
          window.location.href = BASE_URL + '/admin/clips/form?id=' + data.id;
        }, 600);
      } else {
        showToast(data.msg || 'Erro ao salvar.', 'error');
        $btn.prop('disabled', false).html(
          '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg> Salvar clip'
        );
      }
    } catch (err) {
      console.error(err);
      showToast('Erro de conexão.', 'error');
      $btn.prop('disabled', false).text('Salvar clip');
    }
  });

  // ── Excluir ─────────────────────────────────────────
  $('#btn-excluir-clip').on('click', async function () {
    const id     = $(this).data('id');
    const titulo = $(this).data('titulo');

    const ok = window.adminConfirm
      ? await window.adminConfirm({
          titulo: 'Excluir clip?',
          mensagem: `O clip "${titulo}" e seus arquivos (vídeo, poster) serão removidos permanentemente.`,
          tipo: 'danger',
          confirmar: 'Excluir',
        })
      : confirm(`Excluir "${titulo}"?`);

    if (!ok) return;

    $.post(BASE_URL + '/admin/clips/excluir', {
      id, _csrf_token: CSRF_TOKEN,
    }, function (res) {
      if (res.ok) {
        showToast('Clip excluído.', 'success');
        setTimeout(() => window.location.href = BASE_URL + '/admin/clips', 600);
      }
    }, 'json');
  });
})();

// ── Confirm dialog universal ──────────────────────────────
(function () {

  let resolveConfirm = null;

  function getContainer() {
    let el = document.getElementById('admin-confirm-container');
    if (!el) {
      el = document.createElement('div');
      el.id = 'admin-confirm-container';
      document.body.appendChild(el);
    }
    return el;
  }

  /**
   * Exibe um diálogo de confirmação estilizado.
   * Retorna Promise<boolean> — true se confirmado, false se cancelado.
   *
   * @param {object} opts
   * @param {string} opts.titulo     — Título do diálogo
   * @param {string} opts.mensagem   — Mensagem explicativa
   * @param {string} opts.tipo       — 'danger' | 'warning' | 'info' (padrão: 'danger')
   * @param {string} opts.confirmar  — Label do botão de confirmar (padrão: 'Confirmar')
   * @param {string} opts.cancelar   — Label do botão de cancelar (padrão: 'Cancelar')
   */
  window.adminConfirm = function ({
    titulo    = 'Tem certeza?',
    mensagem  = 'Esta ação não pode ser desfeita.',
    tipo      = 'danger',
    confirmar = 'Confirmar',
    cancelar  = 'Cancelar',
  } = {}) {
    return new Promise(resolve => {
      resolveConfirm = resolve;

      const icons = {
        danger  : `<path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>`,
        warning : `<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>`,
        info    : `<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>`,
      };
      const btnClass = {
        danger  : 'admin-confirm-btn--danger',
        warning : 'admin-confirm-btn--warning',
        info    : 'admin-confirm-btn--info',
      };

      const dialog = document.createElement('div');
      dialog.className = 'admin-confirm-backdrop';
      dialog.id        = 'admin-confirm-dialog';
      dialog.innerHTML = `
        <div class="admin-confirm-box">
          <div class="admin-confirm-icon admin-confirm-icon--${tipo}">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round">
              ${icons[tipo] || icons.danger}
            </svg>
          </div>
          <div class="admin-confirm-content">
            <h4 class="admin-confirm-titulo">${titulo}</h4>
            <p  class="admin-confirm-msg">${mensagem}</p>
          </div>
          <div class="admin-confirm-actions">
            <button type="button"
                    class="btn btn-ghost btn-sm admin-confirm-cancel">
              ${cancelar}
            </button>
            <button type="button"
                    class="btn btn-sm ${btnClass[tipo] || btnClass.danger} admin-confirm-ok">
              ${confirmar}
            </button>
          </div>
        </div>`;

      getContainer().appendChild(dialog);

      // Anima entrada
      requestAnimationFrame(() => {
        requestAnimationFrame(() => dialog.classList.add('visible'));
      });

      // Handlers
      dialog.querySelector('.admin-confirm-ok').addEventListener('click', () => {
        fechar(true);
      });
      dialog.querySelector('.admin-confirm-cancel').addEventListener('click', () => {
        fechar(false);
      });
      dialog.addEventListener('click', e => {
        if (e.target === dialog) fechar(false);
      });

      // Keyboard
      const onKey = e => {
        if (e.key === 'Enter')  fechar(true);
        if (e.key === 'Escape') fechar(false);
        document.removeEventListener('keydown', onKey);
      };
      document.addEventListener('keydown', onKey);

      // Foca no botão de cancelar (segurança)
      setTimeout(() => dialog.querySelector('.admin-confirm-cancel')?.focus(), 50);
    });
  };

  function fechar(resultado) {
    const dialog = document.getElementById('admin-confirm-dialog');
    if (!dialog) return;
    dialog.classList.remove('visible');
    dialog.classList.add('hiding');
    setTimeout(() => dialog.remove(), 300);
    if (resolveConfirm) {
      resolveConfirm(resultado);
      resolveConfirm = null;
    }
  }

})();

(function () {
  $('.qa-admin-form').on('submit', function (e) {
    e.preventDefault();
    const $form = $(this);
    const id    = $form.data('id');
    const $btn  = $form.find('button[type="submit"]').prop('disabled', true).text('Enviando…');

    $.post(BASE_URL + '/admin/perguntas/responder', $form.serialize(), function (res) {
      if (res.ok) {
        showToast(res.msg || 'Resposta enviada!', 'success');
        setTimeout(() => location.reload(), 800);
      } else {
        $btn.prop('disabled', false).text('Responder e enviar e-mail');
        showToast(res.msg || 'Erro.', 'error');
      }
    }, 'json');
  });

  $('.qa-rejeitar').on('click', async function () {
    const id = $(this).data('id');
    const ok = window.adminConfirm
      ? await window.adminConfirm({
          titulo: 'Rejeitar pergunta?',
          mensagem: 'A pergunta ficará oculta no site e o cliente NÃO receberá e-mail.',
          tipo: 'danger',
          confirmar: 'Rejeitar',
        })
      : confirm('Rejeitar esta pergunta?');
    if (!ok) return;

    $.post(BASE_URL + '/admin/perguntas/rejeitar', {
      id, _csrf_token: CSRF_TOKEN,
    }, function (res) {
      if (res.ok) {
        $(`#qa-card-${id}`).fadeOut(300, function() { $(this).remove(); });
      }
    }, 'json');
  });
})();

(function () {

  const $grid    = $('#clips-admin-grid');
  if($grid.length === 0) return;

  const BASE  = BASE_URL   || '';
  const CSRF  = CSRF_TOKEN || '';
  // Estado
  let loading    = false;
  
  const $loader  = $('#clips-load-more');
  const $sentinel= $('#clips-sentinel');
  const $empty   = $('#clips-empty-state');

  // ── Intersection Observer (scroll infinito) ─────────
  const observer = new IntersectionObserver(entries => {
    if (entries[0].isIntersecting && !loading && hasMore) carregarMais();
  }, { rootMargin: '200px' });
  observer.observe($sentinel[0]);

  function carregarMais() {
    loading = true;
    $loader.prop('hidden', false);

    $.get(BASE + '/admin/clips', {
      page_clip_index:   page_clip_index + 1,
      q:      filtro.busca,
      status: filtro.status,
      ordem:  filtro.ordem,
      json:   1,
    }, function (res) {
      loading = false;
      $loader.prop('hidden', true);

      if (!res.ok) return;

      page_clip_index     = res.page;
      hasMore  = res.has_more;

      res.clips.forEach((c, idx) => {
        const $card = $(buildCard(c));
        $card.css('animation-delay', (idx * 40) + 'ms');
        $grid.append($card);
      });

      atualizarTotal(res.total);

      if (!hasMore) observer.disconnect();
    }, 'json').fail(() => { loading = false; $loader.prop('hidden', true); });
  }

  // ── Recarregar com filtros ─────────────────────────
  function recarregar() {
    page_clip_index    = 0;
    hasMore = true;
    $grid.empty();
    $empty.prop('hidden', true);
    $loader.prop('hidden', false);
    loading = true;

    $.get(BASE + '/admin/clips', {
      page:   1,
      q:      filtro.busca,
      status: filtro.status,
      ordem:  filtro.ordem,
      json:   1,
    }, function (res) {
      loading = false;
      $loader.prop('hidden', true);

      if (!res.ok) return;
      page_clip_index    = res.page;
      hasMore = res.has_more;

      if (!res.clips.length) {
        $empty.prop('hidden', false);
        atualizarTotal(0);
        return;
      }

      res.clips.forEach((c, idx) => {
        const $card = $(buildCard(c));
        $card.css('animation-delay', (idx * 30) + 'ms');
        $grid.append($card);
      });

      atualizarTotal(res.total);
      if (!hasMore) observer.disconnect();
      else if (!observer._connected) observer.observe($sentinel[0]);
    }, 'json').fail(() => { loading = false; $loader.prop('hidden', true); });
  }

  function atualizarTotal(n) {
    $('#clips-total-label').text(n + ' clip' + (n !== 1 ? 's' : '') + ' encontrado' + (n !== 1 ? 's' : ''));
  }

  // ── Filtros ────────────────────────────────────────
  let buscaDebounce;
  $('#clips-busca-input').on('input', function () {
    clearTimeout(buscaDebounce);
    buscaDebounce = setTimeout(() => {
      filtro.busca = this.value.trim();
      recarregar();
    }, 350);
  });

  $('#clips-busca-clear').on('click', function () {
    $('#clips-busca-input').val('');
    filtro.busca = '';
    recarregar();
  });

  $(document).on('click', '.clips-filter-btn', function () {
    $('.clips-filter-btn').removeClass('is-active');
    $(this).addClass('is-active');
    filtro.status = $(this).data('status');
    recarregar();
  });

  $('#clips-ordem-select').on('change', function () {
    filtro.ordem = $(this).val();
    recarregar();
  });

  // ── Toggle ativo (SEM recarregar a página) ─────────
  $(document).on('click', '.clip-action-ativo', function () {
    const id    = $(this).data('id');
    const $btn  = $(this);
    const $card = $btn.closest('.clip-admin-card');
    $btn.addClass('is-loading');

    $.post(BASE + '/admin/clips/toggle-ativo', { id, _csrf_token: CSRF }, function (res) {
      $btn.removeClass('is-loading');
      if (!res.ok) return;

      const ativo = res.ativo;

      // Atualiza ícone + tooltip + estado do card
      $card.toggleClass('is-inativo', !ativo);
      $btn.attr('title', ativo ? 'Desativar clip' : 'Ativar clip');
      $btn.toggleClass('clip-admin-action--ativo',   ativo);
      $btn.toggleClass('clip-admin-action--inativo', !ativo);

      // Atualiza badge de inativo
      const $badge = $card.find('.clip-admin-badge--inativo');
      if (ativo) $badge.remove();
      else if (!$badge.length) {
        $card.find('.clip-admin-badges').prepend(`
          <span class="clip-admin-badge clip-admin-badge--inativo">Inativo</span>`);
      }

      $btn.find('svg').replaceWith(ativo
        ? `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="10" y1="15" x2="10" y2="9"/><line x1="14" y1="15" x2="14" y2="9"/></svg>`
        : `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>`);

      $btn.find('.clip-action-label').text(ativo ? 'Pausar' : 'Ativar');
      showToast(ativo ? 'Clip ativado.' : 'Clip desativado.', 'success');
    }, 'json');
  });

  // ── Toggle destaque (SEM recarregar a página) ──────
  $(document).on('click', '.clip-action-destaque', function () {
    const id    = $(this).data('id');
    const $btn  = $(this);
    const $card = $btn.closest('.clip-admin-card');
    $btn.addClass('is-loading');

    $.post(BASE + '/admin/clips/toggle-destaque', { id, _csrf_token: CSRF }, function (res) {
      $btn.removeClass('is-loading');
      if (!res.ok) return;

      const dest = res.destaque;
      $btn.toggleClass('is-on', !!dest);
      $btn.attr('title', dest ? 'Remover destaque' : 'Adicionar destaque');
      $btn.find('.clip-action-label').text(dest ? '★ Destaque' : '☆ Destaque');

      const $badge = $card.find('.clip-admin-badge--destaque');
      if (dest && !$badge.length) {
        $card.find('.clip-admin-badges').prepend(`
          <span class="clip-admin-badge clip-admin-badge--destaque">⭐ Destaque</span>`);
      } else if (!dest) {
        $badge.remove();
      }

      showToast(dest ? 'Adicionado ao destaque.' : 'Removido do destaque.', 'success');
    }, 'json');
  });

  // ── Excluir ────────────────────────────────────────
  $(document).on('click', '.clip-action-del', async function () {
    const id     = $(this).data('id');
    const titulo = $(this).closest('.clip-admin-card').find('.clip-admin-titulo').text().trim();
    const $card  = $(this).closest('.clip-admin-card');

    const ok = window.adminConfirm
      ? await window.adminConfirm({
          titulo:    'Excluir clip?',
          mensagem:  `O clip "${titulo}" e seus arquivos serão removidos permanentemente.`,
          tipo:      'danger',
          confirmar: 'Excluir',
        })
      : confirm(`Excluir "${titulo}"?`);

    if (!ok) return;

    $.post(BASE + '/admin/clips/excluir', { id, _csrf_token: CSRF }, function (res) {
      if (res.ok) {
        $card.css({ transition: 'all .3s', opacity: 0, transform: 'scale(.9)' });
        setTimeout(() => $card.remove(), 300);
        showToast('Clip excluído.', 'success');
      }
    }, 'json');
  });

  // ── Gerar poster do vídeo ──────────────────────────
  $(document).on('click', '.clip-action-poster', function () {
    const id    = $(this).data('id');
    const $btn  = $(this);
    const $card = $btn.closest('.clip-admin-card');
    const $thumb= $card.find('.clip-admin-thumb');

    $btn.addClass('is-loading');

    // Overlay de "gerando"
    $thumb.append(`
      <div class="clip-thumb-generating">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none"
             stroke="white" stroke-width="2.5" stroke-linecap="round">
          <path d="M21 12a9 9 0 11-6.219-8.56"/>
        </svg>
        Gerando poster…
      </div>`);

    $.post(BASE + '/admin/clips/gerar-poster', { id, _csrf_token: CSRF }, function (res) {
      $btn.removeClass('is-loading');
      $thumb.find('.clip-thumb-generating').remove();

      if (res.ok && res.poster_url) {
        // Adiciona ou troca a imagem do thumb
        let $img = $thumb.find('img');
        if (!$img.length) {
          $img = $('<img>').prependTo($thumb);
        }
        $img.attr('src', res.poster_url + '?t=' + Date.now());

        // Remove badge de "sem poster"
        $thumb.find('.clip-admin-badge--sem-poster').remove();
        $btn.remove();
        showToast('Poster gerado com sucesso!', 'success');
      } else {
        showToast(res.msg || 'ffmpeg não disponível ou vídeo não encontrado.', 'error');
      }
    }, 'json').fail(() => {
      $btn.removeClass('is-loading');
      $thumb.find('.clip-thumb-generating').remove();
      showToast('Erro ao gerar poster.', 'error');
    });
  });

  // ── Construir HTML do card (para carregamento AJAX) ─
  function buildCard(c) {
    const poster    = c.poster_url;
    const semPoster = !poster;
    const inativo   = !parseInt(c.ativo);
    const destaque  = parseInt(c.destaque);

    const imgHtml = poster
      ? `<img src="${esc(poster)}" alt="" loading="lazy">`
      : `<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#1e293b,#0f172a)">
           <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,.3)" stroke-width="1.5" stroke-linecap="round">
             <polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/>
           </svg>
         </div>`;

    const badges = [
      destaque ? `<span class="clip-admin-badge clip-admin-badge--destaque">⭐ Destaque</span>` : '',
      inativo  ? `<span class="clip-admin-badge clip-admin-badge--inativo">Inativo</span>` : '',
      c.status === 'processando' ? `<span class="clip-admin-badge clip-admin-badge--process">Processando</span>` : '',
      semPoster ? `<span class="clip-admin-badge clip-admin-badge--sem-poster">Sem poster</span>` : '',
    ].join('');

    const views = fmt(c.total_views);
    const likes = fmt(c.total_likes);

    const prodTag = c._produto_nomes
      ? `<div class="clip-admin-produto">
           <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
             <path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/>
           </svg>
           ${esc(c._produto_nomes)}
         </div>` : '';

    const btnAtivoClass  = inativo ? 'clip-admin-action--inativo' : 'clip-admin-action--ativo';
    const btnAtivoTitle  = inativo ? 'Ativar clip' : 'Desativar clip';
    const btnAtivoLabel  = inativo ? 'Ativar' : 'Pausar';
    const btnAtivoIcon   = inativo
      ? `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>`
      : `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="10" y1="15" x2="10" y2="9"/><line x1="14" y1="15" x2="14" y2="9"/></svg>`;

    const btnPoster = semPoster
      ? `<button type="button"
           class="clip-admin-action clip-admin-action--poster clip-action-poster"
           data-id="${c.id}" title="Gerar poster do vídeo">
           <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
             <rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/>
             <polyline points="21 15 16 10 5 21"/>
           </svg>
           <span class="clip-action-label">Poster</span>
         </button>` : '';

    return `
    <div class="clip-admin-card ${inativo ? 'is-inativo' : ''}" data-id="${c.id}">
      <div class="clip-admin-thumb">
        ${imgHtml}
        <div class="clip-admin-badges">${badges}</div>
        <div class="clip-admin-stats-row">
          <span class="clip-admin-stat">
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            ${views}
          </span>
          <span class="clip-admin-stat">
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/></svg>
            ${likes}
          </span>
        </div>
        <div class="clip-admin-thumb-overlay">
          <a href="${BASE}/admin/clips/form?id=${c.id}" title="Editar">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
              <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
              <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
            </svg>
          </a>
        </div>
      </div>

      <div class="clip-admin-body">
        <div class="clip-admin-titulo">${esc(c.titulo)}</div>
        ${prodTag}
      </div>

      <div class="clip-admin-actions">
        <button type="button"
          class="clip-admin-action ${btnAtivoClass} clip-action-ativo"
          data-id="${c.id}" title="${btnAtivoTitle}">
          ${btnAtivoIcon}
          <span class="clip-action-label">${btnAtivoLabel}</span>
        </button>

        <button type="button"
          class="clip-admin-action clip-admin-action--destaque clip-action-destaque ${destaque ? 'is-on' : ''}"
          data-id="${c.id}" title="${destaque ? 'Remover destaque' : 'Adicionar destaque'}">
          
          <span class="clip-action-label">${destaque ? '★ Destaque' : '☆ Destaque'}</span>
        </button>

        ${btnPoster}

        <button type="button"
          class="clip-admin-action clip-admin-action--del clip-action-del"
          data-id="${c.id}" title="Excluir clip">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <polyline points="3 6 5 6 21 6"/>
            <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
          </svg>
        </button>
      </div>
    </div>`;
  }

  function esc(s) { const d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }
  function fmt(n) { n=parseInt(n)||0; return n>=1000?(n/1000).toFixed(1)+'k':String(n); }

  recarregar();
}());

  (function () {

  // Aprovar
  $(document).on('click', '.rv-btn--aprovar', function () {
    const id = $(this).data('id');
    $.post(BASE_URL + '/admin/avaliacoes/aprovar', {
      id, _csrf_token: CSRF_TOKEN
    }, function (res) {
      if (res.ok) {
        $(`#rv-card-${id}`).removeClass('rv-admin-card--pendente');
        $(`#rv-card-${id} .admin-badge--warning`).removeClass('admin-badge--warning').addClass('admin-badge--success').text('Aprovada');
        showToast('Avaliação aprovada!', 'success');
      }
    }, 'json');
  });

  // Rejeitar
  let _rejeitarId = null;
  const $modal = $('#rv-modal-rejeitar');

  $(document).on('click', '.rv-btn--rejeitar', function () {
    _rejeitarId = $(this).data('id');
    $('#rv-motivo-input').val('');
    $modal.prop('hidden', false);
  });

  $('.mod-motivos-presets button').on('click', function () {
    $('#rv-motivo-input').val($(this).data('motivo'));
  });

  $('#rv-cancel-rejeitar, .mod-modal-backdrop').on('click', function () {
    $modal.prop('hidden', true);
  });

  $('#rv-confirm-rejeitar').on('click', function () {
    if (!_rejeitarId) return;
    const motivo = $('#rv-motivo-input').val().trim();
    $.post(BASE_URL + '/admin/avaliacoes/rejeitar', {
      id: _rejeitarId, motivo, _csrf_token: CSRF_TOKEN
    }, function (res) {
      if (res.ok) {
        $(`#rv-card-${_rejeitarId}`).addClass('rv-admin-card--pendente');
        $(`#rv-card-${_rejeitarId} .admin-badge--success`).removeClass('admin-badge--success').addClass('admin-badge--warning').text('Pendente');
        $modal.prop('hidden', true);
        showToast('Avaliação rejeitada.', 'success');
      }
    }, 'json');
  });

  // Destaque
  $(document).on('click', '.rv-btn--destaque', function () {
    const id = $(this).data('id');
    const $btn = $(this);
    $.post(BASE_URL + '/admin/avaliacoes/toggle-destaque', {
      id, _csrf_token: CSRF_TOKEN
    }, function (res) {
      if (res.ok) {
        if (res.destaque) {
          $btn.text('★ Remover destaque');
          $(`#rv-card-${id} .rv-admin-card-header`).find('.admin-badge--info').show();
        } else {
          $btn.text('☆ Destacar');
        }
        showToast('Atualizado!', 'success');
      }
    }, 'json');
  });

  // Excluir
  $(document).on('click', '.rv-btn--excluir', async function () {
    const id   = $(this).data('id');
    const nome = $(this).data('nome');
    const ok   = window.adminConfirm
                 ? await window.adminConfirm({ titulo:'Excluir avaliação?', mensagem:`"${nome}…" será removida permanentemente.`, tipo:'danger', confirmar:'Excluir' })
                 : confirm(`Excluir esta avaliação?`);
    if (!ok) return;
    $.post(BASE_URL + '/admin/avaliacoes/excluir', {
      id, _csrf_token: CSRF_TOKEN
    }, function (res) {
      if (res.ok) {
        $(`#rv-card-${id}`).fadeOut(300, function() { $(this).remove(); });
        showToast('Avaliação excluída.', 'success');
      }
    }, 'json');
  });

})();

// ── Multi-produto selector ─────────────────────────────
(function () {
  const $tags     = $('#clip-produtos-tags');
  const $search   = $('#clip-produto-search');
  const $dropdown = $('#clip-produto-dropdown');
 
  // Todos os produtos disponíveis (vêm do PHP)  
 
  // IDs já selecionados
  const selecionados = new Map();
  $tags.find('.clip-produto-tag').each(function() {
    const id   = parseInt($(this).data('id'));
    const nome = $(this).find('svg').prev().length ? '' : $(this).text().replace('×','').trim();
    selecionados.set(id, $(this).clone().find('button').remove().end().text().trim());
  });
 
  // Filtra dropdown ao digitar
  let debounce;
  $search.on('input', function() {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
      const q = this.value.toLowerCase().trim();
      if (!q) { $dropdown.removeClass('is-open').empty(); return; }
 
      const filtrados = PRODUTOS.filter(p =>
        p.nome.toLowerCase().includes(q) && !selecionados.has(parseInt(p.id))
      ).slice(0, 10);
 
      if (!filtrados.length) {
        $dropdown.addClass('is-open').html('<div class="clip-produto-option" style="color:var(--text-3)">Nenhum encontrado</div>');
        return;
      }
 
      const html = filtrados.map(p => `
        <div class="clip-produto-option" data-id="${p.id}" data-nome="${$('<div>').text(p.nome).html()}">
          ${$('<div>').text(p.nome).html()}
        </div>`).join('');
      $dropdown.addClass('is-open').html(html);
    }, 200);
  });
 
  // Selecionar produto
  $dropdown.on('click', '.clip-produto-option', function() {
    const id   = parseInt($(this).data('id'));
    const nome = $(this).data('nome');
    adicionarProduto(id, nome);
    $search.val('').focus();
    $dropdown.removeClass('is-open').empty();
  });
 
  function adicionarProduto(id, nome) {
    if (selecionados.has(id)) return;
    selecionados.set(id, nome);
    const $tag = $(`
      <span class="clip-produto-tag" data-id="${id}">
        ${$('<div>').text(nome).html()}
        <button type="button" class="clip-produto-tag-remove" data-id="${id}">
          <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round">
            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
          </svg>
        </button>
      </span>
      <input type="hidden" name="produto_ids[]" value="${id}">`);
    $tags.append($tag);
    atualizarCTAVisibility();
  }
 
  // Remover produto
  $tags.on('click', '.clip-produto-tag-remove', function() {
    const id = parseInt($(this).data('id'));
    selecionados.delete(id);
    $(this).closest('.clip-produto-tag').remove();
    // Remove hidden input
    $tags.find(`input[value="${id}"]`).remove();
    atualizarCTAVisibility();
  });
 
  // Mostra/oculta CTA genérico
  function atualizarCTAVisibility() {
    if (selecionados.size > 0) {
      $('#clip-cta-generico').slideUp(200);
    } else {
      $('#clip-cta-generico').slideDown(200);
    }
  }
  atualizarCTAVisibility();
 
  // Fecha dropdown ao clicar fora
  $(document).on('click', function(e) {
    if (!$(e.target).closest('.clip-produto-search-wrap').length) {
      $dropdown.removeClass('is-open');
    }
  });

  $(document).on('click', '.btn-aprovar-com', function () {
    const id   = $(this).data('id');
    const $row = $(`#row-com-${id}`);

    $.post(BASE_URL + '/admin/clips/moderar-comentario', {
      id, status: 'aprovado', _csrf_token: CSRF_TOKEN,
    }, function (res) {
      if (res.ok) {
        $row.find('.admin-badge')
            .removeClass('admin-badge--warning admin-badge--danger')
            .addClass('admin-badge--success')
            .text('Aprovado');
        // Remove botão de aprovar, mantém o de rejeitar
        $row.find('.btn-aprovar-com').remove();
        showToast('Comentário aprovado!', 'success');
      }
    }, 'json');
  });

  // ── Rejeitar ─────────────────────────────────────────
  $(document).on('click', '.btn-rejeitar-com', function () {
    const id   = $(this).data('id');
    const $row = $(`#row-com-${id}`);

    $.post(BASE_URL + '/admin/clips/moderar-comentario', {
      id, status: 'rejeitado', _csrf_token: CSRF_TOKEN,
    }, function (res) {
      if (res.ok) {
        $row.find('.admin-badge')
            .removeClass('admin-badge--warning admin-badge--success')
            .addClass('admin-badge--danger')
            .text('Rejeitado');
        $row.find('.btn-rejeitar-com').remove();
        showToast('Comentário rejeitado.', 'success');
      }
    }, 'json');
  });

  // ── Excluir ───────────────────────────────────────────
  $(document).on('click', '.btn-excluir-com', async function () {
    const id   = $(this).data('id');
    const nome = $(this).data('nome');

    const ok = window.adminConfirm
      ? await window.adminConfirm({
          titulo:    'Excluir comentário?',
          mensagem:  `O comentário de "${nome}" será removido permanentemente.`,
          tipo:      'danger',
          confirmar: 'Excluir',
        })
      : confirm(`Excluir comentário de "${nome}"?`);

    if (!ok) return;

    $.post(BASE_URL + '/admin/clips/moderar-comentario', {
      id, status: 'excluir', _csrf_token: CSRF_TOKEN,
    }, function (res) {
      if (res.ok) {
        $(`#row-com-${id}`).fadeOut(300, function () { $(this).remove(); });
        showToast('Comentário excluído.', 'success');
      }
    }, 'json');
  });
})();

  // ── Admin: Benefícios slider ──────────────────────────────
  (function () {
    if (!document.getElementById('benefitAdminList')) return;

    const $list  = document.getElementById('benefitAdminList');
    const $form  = document.getElementById('form-beneficios');
    let   counter = $list.querySelectorAll('.benefit-admin-item').length;

    const ICONS_SVG = {
      truck  : '<path d="M1 3h15v13H1zm15 5h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
      shield : '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
      credit : '<rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>',
      headset: '<path d="M3 18v-6a9 9 0 0118 0v6"/><path d="M21 19a2 2 0 01-2 2h-1a2 2 0 01-2-2v-3a2 2 0 012-2h3z"/><path d="M3 19a2 2 0 002 2h1a2 2 0 002-2v-3a2 2 0 00-2-2H3z"/>',
      star   : '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
      gift   : '<polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 010-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 000-5C13 2 12 7 12 7z"/>',
      tag    : '<path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>',
      refresh: '<polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/>',
    };

    // ── Adicionar novo item ─────────────────────────────────
    document.getElementById('btnAddBenefit').addEventListener('click', function () {
      const i = counter++;
      const div = document.createElement('div');
      div.className = 'benefit-admin-item';
      div.dataset.index = i;
      div.innerHTML = buildItemHTML(i, {
        id: 0, icone: 'star', titulo: '', descricao: '',
        link: '', css_classe: '', ativo: 1,
      });
      $list.appendChild(div);
      div.querySelector('input[name*="titulo"]')?.focus();
      reindexar();
      initSortable();
    });

    // ── Remover item ────────────────────────────────────────
    $list.addEventListener('click', function (e) {
      const btn = e.target.closest('.benefit-admin-del');
      if (!btn) return;
      const item = btn.closest('.benefit-admin-item');
      item.style.opacity = '0';
      item.style.transform = 'translateX(10px)';
      setTimeout(() => { item.remove(); reindexar(); }, 200);
    });

    // ── Preview do ícone ao trocar o select ─────────────────
    $list.addEventListener('change', function (e) {
      const sel = e.target.closest('.benefit-icon-select');
      if (!sel) return;
      const i       = sel.dataset.index;
      const preview = document.getElementById('iconPreview' + i);
      if (!preview) return;
      preview.innerHTML = `<svg width="20" height="20" viewBox="0 0 24 24"
        fill="none" stroke="currentColor" stroke-width="1.8"
        stroke-linecap="round" stroke-linejoin="round">
        ${ICONS_SVG[sel.value] || ICONS_SVG.star}</svg>`;
    });

    

    // ── Drag & drop para reordenar ──────────────────────────
    function initSortable() {
      let dragEl = null;

      $list.querySelectorAll('.benefit-admin-item').forEach(item => {
        const handle = item.querySelector('.benefit-admin-drag');
        handle.setAttribute('draggable', true);

        handle.addEventListener('dragstart', function (e) {
          dragEl = item;
          item.classList.add('dragging');
          e.dataTransfer.effectAllowed = 'move';
        });
        handle.addEventListener('dragend', function () {
          dragEl = null;
          item.classList.remove('dragging');
          reindexar();
        });
      });

      $list.addEventListener('dragover', function (e) {
        e.preventDefault();
        if (!dragEl) return;
        const after = getDragAfterElement($list, e.clientY);
        if (!after) {
          $list.appendChild(dragEl);
        } else {
          $list.insertBefore(dragEl, after);
        }
      });
    }

    function getDragAfterElement(container, y) {
      const draggables = [...container.querySelectorAll('.benefit-admin-item:not(.dragging)')];
      return draggables.reduce((closest, child) => {
        const box    = child.getBoundingClientRect();
        const offset = y - box.top - box.height / 2;
        if (offset < 0 && offset > closest.offset) {
          return { offset, element: child };
        }
        return closest;
      }, { offset: Number.NEGATIVE_INFINITY }).element;
    }

    // ── Reindexar names após reordenar/remover ──────────────
    function reindexar() {
      $list.querySelectorAll('.benefit-admin-item').forEach((item, i) => {
        item.dataset.index = i;
        item.querySelectorAll('[name]').forEach(el => {
          el.name = el.name.replace(/items\[\d+\]/, `items[${i}]`);
        });
        // Atualiza data-index no select
        const sel = item.querySelector('.benefit-icon-select');
        if (sel) sel.dataset.index = i;
        // Atualiza id do preview
        const prev = item.querySelector('[id^="iconPreview"]');
        if (prev) prev.id = 'iconPreview' + i;
      });

      $($form).submit(); // Trigger para atualizar o estado do submit (opcional)
    }

    function buildItemHTML(i, b) {
      const iconOptions = Object.keys(ICONS_SVG).map(k =>
        `<option value="${k}" ${b.icone === k ? 'selected' : ''}>${k.charAt(0).toUpperCase() + k.slice(1)}</option>`
      ).join('');

      return `
        <div class="benefit-admin-drag" title="Arrastar" draggable="true">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
              stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/>
            <line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/>
            <line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>
          </svg>
        </div>
        <input type="hidden" name="items[${i}][id]" value="0">
        <div class="benefit-admin-icon-pick">
          <div class="benefit-icon-preview" id="iconPreview${i}">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
              ${ICONS_SVG.star}
            </svg>
          </div>
          <select name="items[${i}][icone]" class="benefit-icon-select form-control form-control--sm"
                  data-index="${i}">${iconOptions}</select>
        </div>
        <div class="benefit-admin-fields">
          <div class="benefit-admin-row">
            <div class="form-group">
              <label>Título</label>
              <input type="text" name="items[${i}][titulo]"
                    class="form-control form-control--sm"
                    value="${b.titulo}" placeholder="Título" maxlength="100" required>
            </div>
            <div class="form-group">
              <label>Descrição</label>
              <input type="text" name="items[${i}][descricao]"
                    class="form-control form-control--sm"
                    value="${b.descricao}" placeholder="Descrição" maxlength="200">
            </div>
          </div>
          <div class="benefit-admin-row">
            <div class="form-group">
              <label>Link <span class="field-hint">(opcional)</span></label>
              <input type="text" name="items[${i}][link]"
                    class="form-control form-control--sm"
                    value="${b.link}" placeholder="Ex: /busca?frete_gratis=1">
            </div>
            <div class="form-group">
              <label>Classe CSS extra <span class="field-hint">(opcional)</span></label>
              <input type="text" name="items[${i}][css_classe]"
                    class="form-control form-control--sm"
                    value="${b.css_classe}" placeholder="Ex: benefit-promo"
                    maxlength="100">
            </div>
          </div>
        </div>
        <div class="benefit-admin-actions">
          <label class="toggle-switch" title="Ativo">
            <input type="hidden" name="items[${i}][ativo]" value="0">
            <input type="checkbox" name="items[${i}][ativo]" value="1" checked>
            <span class="toggle-track"></span>
          </label>
          <button type="button" class="benefit-admin-del" title="Remover">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
              <polyline points="3 6 5 6 21 6"/>
              <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
            </svg>
          </button>
        </div>`;
    }

    // Inicializa
    initSortable();

    // ── Salvar via Ajax ─────────────────────────────────────
    $('#form-beneficios').on('submit', function (e) { 
      e.preventDefault();

      const $form = $(this);
      const $btn = $('#btnSaveBenefits');
      const $fb  = $('#benefitFeedback');

      $btn.prop('disabled', true).text('Salvando...');
      $fb.text('').attr('class', 'benefit-admin-feedback');

      $.ajax({
          url: BASE_URL + '/admin/beneficios/salvar',
          type: 'POST',
          data: new FormData(this),
          processData: false, // necessário para FormData
          contentType: false, // necessário para FormData
          dataType: 'json',

          success: function (res) {
              $btn.prop('disabled', false).text('Salvar alterações');
              $fb.text(res.msg)
                .attr('class', 'benefit-admin-feedback ' + (res.ok ? 'fb-ok' : 'fb-erro'));
          },

          error: function () {
              $btn.prop('disabled', false).text('Salvar alterações');
              $fb.text('Erro de conexão.')
                .attr('class', 'benefit-admin-feedback fb-erro');
          }
      });

      return false;
  });

  })();  

  
});