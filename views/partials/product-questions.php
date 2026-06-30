<?php
// ════════════════════════════════════════════════════════
// views/partials/product-questions.php — v2
// Lista 4 perguntas + botão "Ver mais" + modal infinito
// ════════════════════════════════════════════════════════
if (empty($produto_id)) return;
$logado = Session::isClienteLogado();
?>

<!-- ══ SEÇÃO NA PÁGINA DO PRODUTO ══════════════════════ -->
<section class="qa-section" id="qa-section" data-produto-id="<?= (int)$produto_id ?>">
  <div class="qa-header">
    <div>
      <h2 class="qa-title">Perguntas e respostas</h2>
      <p class="qa-sub">Tire suas dúvidas sobre este produto.</p>
    </div>
    <button type="button" class="qa-btn-ask" id="qa-btn-ask">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
        <line x1="12" y1="5" x2="12" y2="19"/>
        <line x1="5"  y1="12" x2="19" y2="12"/>
      </svg>
      Fazer pergunta
    </button>
  </div>

  <!-- Lista das 4 primeiras (carregada via JS) -->
  <div class="qa-list" id="qa-list">
    <div class="qa-loading">Carregando perguntas…</div>
  </div>

  <!-- Botão "Ver mais" — aparece via JS quando total > 4 -->
  <div class="qa-ver-mais-wrap" id="qa-ver-mais-wrap" style="display:none;">
    <button type="button" class="qa-ver-mais-btn" id="qa-btn-ver-mais">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
        <path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>
      </svg>
      Ver mais
      <span class="qa-ver-mais-count">0</span>
      perguntas
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
        <polyline points="9 18 15 12 9 6"/>
      </svg>
    </button>
  </div>
</section>


<!-- ══ MODAL "TODAS AS PERGUNTAS" ══════════════════════ -->
<div id="qa-all-modal" class="qa-all-modal" hidden>
  <div class="qa-all-backdrop"></div>

  <div class="qa-all-panel">

    <!-- Header fixo -->
    <div class="qa-all-header">
      <div>
        <h3 class="qa-all-title">Perguntas e respostas</h3>
        <p class="qa-all-sub" id="qa-all-sub">Tire suas dúvidas sobre este produto.</p>
      </div>
      <div class="qa-all-header-actions">
        <button type="button" class="qa-btn-ask-inline" id="qa-all-btn-ask">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <line x1="12" y1="5" x2="12" y2="19"/>
            <line x1="5"  y1="12" x2="19" y2="12"/>
          </svg>
          Perguntar
        </button>
        <button type="button" class="qa-all-close" id="qa-all-modal-close">
          <svg width="17" height="17" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <line x1="18" y1="6" x2="6"  y2="18"/>
            <line x1="6"  y1="6" x2="18" y2="18"/>
          </svg>
        </button>
      </div>
    </div>

    <!-- Scroll container -->
    <div class="qa-all-scroll" id="qa-all-scroll">
      <div class="qa-all-list" id="qa-all-list"></div>

      <!-- Loader e sentinel para scroll infinito -->
      <div class="qa-all-loading-row" id="qa-all-loading" style="display:none;">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
             class="qa-spinner">
          <path d="M21 12a9 9 0 11-6.219-8.56"/>
        </svg>
        <span>Carregando mais perguntas…</span>
      </div>

      <!-- Sentinel: observado pelo IntersectionObserver -->
      <div id="qa-all-sentinel" style="height:1px;"></div>

      <!-- Mensagem de fim -->
      <div class="qa-all-fim" id="qa-all-fim" hidden>
        Todas as perguntas foram carregadas.
      </div>
    </div>

  </div>
</div>


<!-- ══ MODAL FAZER PERGUNTA ════════════════════════════ -->
<div class="qa-modal" id="qa-modal" hidden>
  <div class="qa-modal-backdrop"></div>
  <div class="qa-modal-content">
    <div class="qa-modal-header">
      <h3>Faça sua pergunta</h3>
      <button type="button" class="qa-modal-close" id="qa-modal-close">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <line x1="18" y1="6" x2="6"  y2="18"/>
          <line x1="6"  y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>

    <form id="qa-form">
      <input type="hidden" name="_csrf_token" value="<?= SecurityHelper::generateCsrf() ?>">
      <input type="hidden" name="produto_id"  value="<?= (int)$produto_id ?>">

      <?php if (!$logado): ?>
      <div class="qa-anonimo-info">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <circle cx="12" cy="12" r="10"/>
          <line x1="12" y1="8" x2="12" y2="12"/>
          <line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        A resposta será enviada para o seu e-mail.
      </div>
      <div class="qa-row">
        <div class="qa-field">
          <label>Nome *</label>
          <input type="text" name="nome" required maxlength="120">
        </div>
        <div class="qa-field">
          <label>E-mail *</label>
          <input type="email" name="email" required>
        </div>
      </div>
      <div class="qa-field">
        <label>Telefone (opcional)</label>
        <input type="tel" name="telefone" maxlength="20">
      </div>
      <?php endif; ?>

      <div class="qa-field">
        <label>Sua pergunta *</label>
        <textarea name="pergunta" required minlength="10" maxlength="500"
                  rows="4" placeholder="Tente ser específico…"></textarea>
        <div class="qa-counter"><span id="qa-counter-num">0</span>/500</div>
      </div>

      <button type="submit" class="qa-submit" id="qa-submit">
        Enviar pergunta
      </button>
    </form>

    <div class="qa-result" id="qa-result" hidden>
      <div class="qa-result-icon" id="qa-result-icon"></div>
      <h4 id="qa-result-title"></h4>
      <p  id="qa-result-msg"></p>
      <div class="qa-result-answer" id="qa-result-answer" hidden></div>
      <button type="button" class="qa-result-close" id="qa-result-close">Fechar</button>
    </div>
  </div>
</div>

<script>
window.QA_CONFIG = {
  produtoId:      <?= (int)$produto_id ?>,
  baseUrl:        '<?= BASE_URL ?>',
  csrfToken:      '<?= SecurityHelper::generateCsrf() ?>',
  isLogado:       <?= $logado ? 'true' : 'false' ?>,
  perPageInicial: 4,
};
</script>