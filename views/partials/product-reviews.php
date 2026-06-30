<?php
// ════════════════════════════════════════════════════════
// views/partials/product-reviews.php
// Incluir na página do produto:
// <?php View::partial('partials/product-reviews', ['produto_id' => $product['id']]) 
// ════════════════════════════════════════════════════════
if (empty($produto_id)) return;

$isLogado  = Session::isClienteLogado();
$clienteId = $isLogado ? (int)Session::get('cliente_id') : null;
$nomeCliente = $isLogado ? (Session::get('cliente_nome') ?? '') : '';
?>

<!-- SVG Defs global para meia estrela -->
<svg width="0" height="0" style="position:absolute;overflow:hidden;">
  <defs>
    <linearGradient id="rv-half-grad" x1="0" x2="1" y1="0" y2="0">
      <stop offset="50%" stop-color="#d4830a"/>
      <stop offset="50%" stop-color="#e0d8cc"/>
    </linearGradient>
  </defs>
</svg>

<section class="sm-reviews-section" id="sm-reviews-section"
         data-produto-id="<?= (int)$produto_id ?>">

  <div class="sm-reviews-wrapper">

    <div class="sm-reviews-head">
      <h2 class="sm-reviews-head-title">Opiniões dos clientes</h2>
      <span class="sm-reviews-head-count" id="sm-reviews-head-count">Carregando…</span>
    </div>

    <div class="sm-reviews-layout">

      <!-- Painel esquerdo: resumo -->
      <aside class="sm-reviews-summary">
        <div class="sm-reviews-score" id="sm-score-wrap">
          <span class="sm-reviews-score-num" id="sm-score-num">—</span>
          <div class="sm-reviews-score-stars" id="sm-score-stars"></div>
          <span class="sm-reviews-score-total" id="sm-score-total"></span>
        </div>
        <div class="sm-reviews-bars" id="sm-reviews-bars">
          <?php for ($n=5;$n>=1;$n--): ?>
          <div class="sm-reviews-bar-row" data-filter="<?= $n ?>">
            <span class="sm-reviews-bar-label"><?= $n ?></span>
            <span class="sm-reviews-bar-star">
              <svg viewBox="0 0 24 24" width="12" height="12">
                <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2" fill="#e0d8cc"/>
              </svg>
            </span>
            <div class="sm-reviews-bar-track">
              <div class="sm-reviews-bar-fill" style="width:0%"></div>
            </div>
            <span class="sm-reviews-bar-count sm-reviews-bar-count-<?= $n ?>">0</span>
          </div>
          <?php endfor; ?>
        </div>
        <button class="sm-reviews-write-btn" id="sm-write-btn">
          Escrever avaliação
        </button>
      </aside>

      <!-- Painel direito: lista -->
      <div class="sm-reviews-main">

        <!-- Galeria global de mídias -->
        <div class="sm-reviews-media-strip" id="sm-media-strip" style="display:none;">
          <div class="sm-reviews-media-strip-title">Fotos e vídeos dos clientes</div>
          <div class="sm-reviews-media-scroll" id="sm-media-scroll"></div>
        </div>

        <!-- Filtros -->
        <div class="sm-reviews-controls">
          <div class="sm-reviews-filters" id="sm-filters"></div>
          <div class="sm-reviews-sort">
            <span class="sm-reviews-sort-label">Ordenar:</span>
            <select class="sm-reviews-sort-select" id="sm-sort">
              <option value="recentes">Mais recentes</option>
              <option value="uteis">Mais úteis</option>
              <option value="maior">Maior nota</option>
              <option value="menor">Menor nota</option>
            </select>
          </div>
        </div>

        <!-- Lista -->
        <div class="sm-reviews-list" id="sm-reviews-list">
          <div class="sm-reviews-empty">
            <div class="sm-reviews-loading-spinner"></div>
          </div>
        </div>

        <!-- Ver mais -->
        <div class="sm-reviews-load-more" id="sm-load-more" style="display:none;">
          <button class="sm-reviews-load-btn" id="sm-load-btn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
              <polyline points="1 4 1 10 7 10"/><polyline points="23 20 23 14 17 14"/>
              <path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 0 1 3.51 15"/>
            </svg>
            Mostrar todas as opiniões
          </button>
        </div>

      </div>
    </div>
  </div>
</section>

<!-- Lightbox -->
<div class="sm-lightbox" id="sm-lightbox">
  <div class="sm-lightbox-inner">
    <button class="sm-lightbox-close" id="sm-lb-close">
      <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-width="2.5" stroke="currentColor" fill="none">
        <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
      </svg>
    </button>
    <button class="sm-lightbox-nav sm-lightbox-nav--prev" id="sm-lb-prev">
      <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-width="2.5" stroke="currentColor" fill="none">
        <polyline points="15 18 9 12 15 6"/>
      </svg>
    </button>
    <img class="sm-lightbox-media" id="sm-lb-img" src="" alt="" style="display:none;">
    <video class="sm-lightbox-media" id="sm-lb-video" controls style="display:none;"></video>
    <button class="sm-lightbox-nav sm-lightbox-nav--next" id="sm-lb-next">
      <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-width="2.5" stroke="currentColor" fill="none">
        <polyline points="9 18 15 12 9 6"/>
      </svg>
    </button>
    <div class="sm-lightbox-counter" id="sm-lb-counter"></div>
  </div>
</div>

<!-- Modal escrever avaliação -->
<div class="sm-write-modal" id="sm-write-modal">
  <div class="sm-write-panel">
    <div class="sm-write-panel-header">
      <h3 class="sm-write-panel-title">Sua avaliação</h3>
      <button class="sm-write-close" id="sm-write-close">
        <svg viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" fill="none" stroke-linecap="round">
          <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>

    <form id="sm-write-form" novalidate>
      <input type="hidden" name="_csrf_token" value="<?= SecurityHelper::generateCsrf() ?>">
      <input type="hidden" name="produto_id"   value="<?= (int)$produto_id ?>">
      <input type="hidden" name="upload_token" id="sm-upload-token" value="">

      <!-- Star picker -->
      <div class="sm-star-picker">
        <div class="sm-star-picker-label">Sua nota *</div>
        <div class="sm-star-picker-stars" id="sm-star-picker">
          <?php for ($i=1;$i<=5;$i++): ?>
          <span class="sm-star-picker-star" data-val="<?= $i ?>">
            <svg viewBox="0 0 24 24">
              <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
            </svg>
          </span>
          <?php endfor; ?>
        </div>
        <div class="sm-star-picker-hint" id="sm-star-hint"></div>
        <input type="hidden" name="nota" id="sm-nota-val" value="0">
      </div>

      <?php if (!$isLogado): ?>
      <div class="sm-write-field">
        <label class="sm-write-field-label" for="sm-write-name">Seu nome *</label>
        <input class="sm-write-input" id="sm-write-name" name="nome" type="text"
               placeholder="Como quer aparecer?">
      </div>
      <?php else: ?>
      <input type="hidden" name="nome" value="<?= View::e($nomeCliente) ?>">
      <?php endif; ?>

      <div class="sm-write-field">
        <label class="sm-write-field-label" for="sm-write-title">Título</label>
        <input class="sm-write-input" id="sm-write-title" name="titulo" type="text"
               placeholder="Resuma em uma frase" maxlength="150">
      </div>

      <div class="sm-write-field">
        <label class="sm-write-field-label" for="sm-write-body">Comentário *</label>
        <textarea class="sm-write-textarea" id="sm-write-body" name="comentario"
                  placeholder="Conte sua experiência com o produto…"
                  maxlength="2000"></textarea>
      </div>

      <!-- Upload de mídias -->
      <div class="sm-write-field">
        <label class="sm-write-field-label">Fotos e vídeos (opcional)</label>
        <div class="sm-upload-zone" id="sm-upload-zone">
          <div class="sm-upload-zone-icon">
            <svg viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="1.5" stroke-linecap="round">
              <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>
              <polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>
            </svg>
          </div>
          <div class="sm-upload-zone-text">Clique ou arraste arquivos aqui</div>
          <div class="sm-upload-zone-btn">Selecionar arquivos</div>
          <div class="sm-upload-zone-hint">
            JPG, PNG, WEBP até 5MB · MP4, WEBM até 30MB · máx. 5 arquivos
          </div>
          <input type="file" class="sm-upload-zone-input" id="sm-upload-input"
                 multiple accept=".jpg,.jpeg,.png,.webp,.mp4,.webm,.mov">
        </div>
        <div class="sm-upload-previews" id="sm-upload-previews"></div>
        <div class="sm-upload-progress-wrap" id="sm-upload-progress" style="display:none;"></div>
      </div>

      <button type="submit" class="sm-write-submit" id="sm-write-submit">
        Enviar avaliação
      </button>
    </form>
  </div>
</div>

<!-- Toast -->
<div class="sm-toast" id="sm-toast">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
    <polyline points="20 6 9 17 4 12"/>
  </svg>
  <span id="sm-toast-text"></span>
</div>

<!-- Spinner CSS -->
<style>
.sm-reviews-loading-spinner {
  width:28px;height:28px;border:2.5px solid #e8e4dd;
  border-top-color:#d4830a;border-radius:50%;
  animation:sm-spin .8s linear infinite;margin:40px auto;
}
@keyframes sm-spin { to { transform:rotate(360deg); } }
</style>

<!-- Configura variáveis globais para o JS -->
<script>
window.SM_REVIEWS_CONFIG = {
  produtoId: <?= (int)$produto_id ?>,
  baseUrl:   '<?= BASE_URL ?>',
  csrfToken: '<?= SecurityHelper::generateCsrf() ?>',
  isLogado:  <?= $isLogado ? 'true' : 'false' ?>,
  nomeCliente: <?= json_encode($nomeCliente) ?>,
};
</script>