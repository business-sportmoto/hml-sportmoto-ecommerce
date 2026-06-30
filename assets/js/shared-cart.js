// assets/js/shared-cart.js
$(function () {

  let tokenAtual = null;

  // ── Iniciar cópia ─────────────────────────────────────────
  $('#btn-copiar-carrinho').on('click', function () {
    tokenAtual = $(this).data('token');

    // Verifica primeiro se já tem itens no carrinho
    $.get(BASE_URL + '/carrinho/mini', function (res) {
      if (res.ok && res.count > 0) {
        // Tem itens — mostra o modal de escolha
        $('#modal-carrinho-conflito').fadeIn(200);
      } else {
        // Carrinho vazio — adiciona direto
        executarCopia('adicionar');
      }
    }, 'json').fail(function () {
      // Erro ao verificar — adiciona direto como fallback
      executarCopia('adicionar');
    });
  });

  // ── Fechar modal ──────────────────────────────────────────
  $('#btn-fechar-conflito').on('click', fecharModal);
  $('#modal-carrinho-conflito').on('click', function (e) {
    if ($(e.target).is('#modal-carrinho-conflito')) fecharModal();
  });
  $(document).on('keydown', function (e) {
    if (e.key === 'Escape') fecharModal();
  });

  function fecharModal() {
    $('#modal-carrinho-conflito').fadeOut(200);
  }

  // ── Opções do modal ───────────────────────────────────────
  $('#btn-adicionar-junto').on('click', function () {
    fecharModal();
    executarCopia('adicionar');
  });

  $('#btn-substituir-carrinho').on('click', function () {
    fecharModal();
    executarCopia('substituir');
  });

  // ── Executar a cópia ──────────────────────────────────────
  function executarCopia(modo) {
    const $btn = $('#btn-copiar-carrinho');
    $btn.prop('disabled', true).html(`
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
           style="animation:spin .7s linear infinite">
        <polyline points="1 4 1 10 7 10"/>
        <path d="M3.51 15a9 9 0 102.13-9.36L1 10"/>
      </svg>
      Adicionando...`);

    $.post(BASE_URL + '/carrinho/copiar-compartilhado', {
      token:       tokenAtual,
      modo:        modo,
      _csrf_token: CSRF_TOKEN,
    }, function (res) {
      if (!res.ok) {
        $btn.prop('disabled', false).html(`
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <circle cx="9"  cy="21" r="1"/>
            <circle cx="20" cy="21" r="1"/>
            <path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/>
          </svg>
          Adicionar ao meu carrinho`);
        mostrarToast(res.msg, 'error');
        return;
      }

      // Sucesso — substitui o botão pelo resultado
      $('#btn-copiar-carrinho').replaceWith(`
        <div class="shared-copy-success">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <polyline points="20 6 9 17 4 12"/>
          </svg>
          ${res.msg}
        </div>`);

      // Atualiza o badge do carrinho no header
      if (res.count > 0) {
        $('#cart-count, #mc-badge').text(res.count).show();
      }

      mostrarToast(res.msg, 'success');

      // Redireciona após 1.5s
      setTimeout(function () {
        window.location.href = res.redirect;
      }, 1500);

    }, 'json').fail(function () {
      $btn.prop('disabled', false).text('Adicionar ao meu carrinho');
      mostrarToast('Erro de conexão. Tente novamente.', 'error');
    });
  }

  // ── Toast simples (independente do main.js) ───────────────
  function mostrarToast(msg, tipo) {
    if (typeof showToast === 'function') {
      showToast(msg, tipo);
      return;
    }
    // Fallback se o main.js não estiver carregado
    const cores = { success: '#059669', error: '#dc2626', info: '#2563eb' };
    const $t = $(`<div style="
      position:fixed; bottom:24px; right:24px; z-index:9999;
      background:${cores[tipo] || cores.info}; color:#fff;
      padding:12px 20px; border-radius:8px; font-size:14px; font-weight:600;
      box-shadow:0 4px 16px rgba(0,0,0,.15); opacity:0; transition:opacity .3s;
    ">${msg}</div>`);
    $('body').append($t);
    setTimeout(() => $t.css('opacity', 1), 10);
    setTimeout(() => { $t.css('opacity', 0); setTimeout(() => $t.remove(), 300); }, 3500);
  }

});