<?php
// ════════════════════════════════════════════════════════
// views/partials/clips-feed-overlay.php
// Feed fullscreen estilo Reels/TikTok
// Incluído uma vez no layout — controlado via JS
// ════════════════════════════════════════════════════════
?>

<!-- ══ Feed Fullscreen ═══════════════════════════════════ -->
<div id="clips-feed-overlay" class="clips-feed-overlay" hidden aria-modal="true" role="dialog">

  <!-- Header -->
  <div class="clips-feed-header">
    <button type="button" class="clips-feed-close" id="clips-feed-close" aria-label="Fechar">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
        <line x1="18" y1="6" x2="6"  y2="18"/>
        <line x1="6"  y1="6" x2="18" y2="18"/>
      </svg>
    </button>
    <span class="clips-feed-logo">Clips</span>
    <div style="width:40px;"></div>
  </div>

  <!-- Container de vídeos com scroll-snap vertical -->
  <div class="clips-feed-container" id="clips-feed-container">
    <!-- Itens gerados via JS -->
  </div>

  <!-- Indicador de carregamento -->
  <div class="clips-feed-loading" id="clips-feed-loading">
    <div class="clips-spinner"></div>
  </div>

  <!-- ── Drawer de comentários ──────────────────────────── -->
  <div class="clips-comments-drawer" id="clips-comments-drawer">
    <div class="clips-comments-backdrop" id="clips-comments-backdrop"></div>
    <div class="clips-comments-panel">
      <div class="clips-comments-header">
        <h3>Comentários</h3>
        <button type="button" class="clips-comments-close" id="clips-comments-close">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <line x1="18" y1="6" x2="6"  y2="18"/>
            <line x1="6"  y1="6" x2="18" y2="18"/>
          </svg>
        </button>
      </div>

      <!-- Lista de comentários -->
      <div class="clips-comments-list" id="clips-comments-list">
        <div class="clips-comments-empty">Seja o primeiro a comentar!</div>
      </div>

      <!-- Formulário -->
      <form class="clips-comments-form" id="clips-comments-form">
        <input type="hidden" name="_csrf_token"
               value="<?= SecurityHelper::generateCsrf() ?>">
        <input type="hidden" name="id" id="clips-comment-clip-id">

        <?php if (!Session::isClienteLogado()): ?>
        <input type="text" name="nome" class="clips-comments-input-nome"
               placeholder="Seu nome" required maxlength="80">
        <?php else: ?>
        <input type="hidden" name="nome"
               value="<?= View::e(Session::get('cliente_nome') ?? 'Usuário') ?>">
        <?php endif; ?>

        <div class="clips-comments-input-row">
          <textarea name="texto" class="clips-comments-textarea"
                    placeholder="Adicione um comentário..."
                    maxlength="500" rows="1" required></textarea>
          <button type="submit" class="clips-comments-send">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
              <line x1="22" y1="2" x2="11" y2="13"/>
              <polygon points="22 2 15 22 11 13 2 9 22 2"/>
            </svg>
          </button>
        </div>
      </form>
    </div>
  </div>

</div>

<!-- Template de item do feed (clonado via JS) -->
<template id="clip-item-template">
  <div class="clip-item" data-clip-id="" data-loaded="false">

    <!-- Wrapper do vídeo -->
    <div class="clip-item-video-wrap">
      <video class="clip-item-video"
             preload="none"
             playsinline
             loop
             muted></video>

      <!-- Poster antes do vídeo carregar -->
      <img class="clip-item-poster" src="" alt="" loading="lazy">

      <!-- Botão play/pause (toque) -->
      <div class="clip-item-tap-overlay">
        <div class="clip-item-play-icon">
          <svg width="48" height="48" viewBox="0 0 24 24" fill="white">
            <polygon points="5 3 19 12 5 21 5 3"/>
          </svg>
        </div>
      </div>

      <!-- Progress bar -->
      <div class="clip-item-progress">
        <div class="clip-item-progress-bar"></div>
      </div>
    </div>

    <!-- Overlay inferior: titulo + descricao + produto -->
    <div class="clip-item-overlay-bottom">
      <div class="clip-item-meta">
        <h3 class="clip-item-titulo"></h3>
        <p class="clip-item-descricao"></p>

        <!-- Card do produto -->
        <div class="clip-item-produto" style="display:none;">
          <div class="clip-produto-img-wrap">
            <img class="clip-produto-img" src="" alt="">
          </div>
          <div class="clip-produto-info">
            <span class="clip-produto-nome"></span>
            <div class="clip-produto-precos">
              <span class="clip-produto-preco-promo" style="display:none;"></span>
              <span class="clip-produto-preco"></span>
            </div>
          </div>
          <a class="clip-produto-cta" href="#" target="_blank">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
              <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
              <path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/>
            </svg>
            Comprar
          </a>
        </div>

        <!-- CTA genérico (sem produto) -->
        <div class="clip-item-cta-generic" style="display:none;">
          <a class="clip-item-cta-link" href="#"></a>
        </div>
      </div>
    </div>

    <!-- Botões laterais direitos -->
    <div class="clip-item-actions">

      <!-- Like -->
      <div class="clip-action">
        <button type="button" class="clip-action-btn clip-like-btn" aria-label="Curtir">
          <svg class="clip-action-icon clip-like-icon" width="24" height="24"
               viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round">
            <path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/>
          </svg>
        </button>
        <span class="clip-action-count clip-like-count">0</span>
      </div>

      <!-- Comentar -->
      <div class="clip-action">
        <button type="button" class="clip-action-btn clip-comment-btn" aria-label="Comentar">
          <svg class="clip-action-icon" width="24" height="24"
               viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round">
            <path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>
          </svg>
        </button>
        <span class="clip-action-count clip-comment-count">0</span>
      </div>

      <!-- Compartilhar -->
      <div class="clip-action">
        <button type="button" class="clip-action-btn clip-share-btn" aria-label="Compartilhar">
          <svg class="clip-action-icon" width="24" height="24"
               viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round">
            <circle cx="18" cy="5" r="3"/>
            <circle cx="6" cy="12" r="3"/>
            <circle cx="18" cy="19" r="3"/>
            <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/>
            <line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>
          </svg>
        </button>
        <span class="clip-action-count">Compartilhar</span>
      </div>

      <!-- Mute/unmute -->
      <div class="clip-action">
        <button type="button" class="clip-action-btn clip-mute-btn" aria-label="Som">
          <svg class="clip-action-icon clip-sound-icon" width="24" height="24"
               viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round">
            <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/>
            <path d="M19.07 4.93a10 10 0 010 14.14M15.54 8.46a5 5 0 010 7.07"/>
          </svg>
          <svg class="clip-action-icon clip-mute-icon" width="24" height="24"
               viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round" style="display:none">
            <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/>
            <line x1="23" y1="9" x2="17" y2="15"/>
            <line x1="17" y1="9" x2="23" y2="15"/>
          </svg>
        </button>
      </div>

    </div>

  </div>
</template>