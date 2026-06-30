<div class="garagem-modal" id="modal-fotos-moto">
  <div class="garagem-modal-backdrop"></div>
  <div class="garagem-modal-content garagem-modal-content--lg">

    <div class="garagem-modal-header">
      <div>
        <h2>Galeria da moto</h2>
        <span id="fotos-moto-label" class="garagem-modal-sub"></span>
      </div>
      <button type="button" class="garagem-modal-close">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <line x1="18" y1="6" x2="6"  y2="18"/>
          <line x1="6"  y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>

    <input type="hidden" id="fotos-veiculo-id">

    <!-- Upload zone -->
    <div class="fotos-upload-zone" id="fotos-upload-zone">
      <input type="file" id="fotos-input" accept="image/jpeg,image/png,image/webp" multiple hidden>

      <div class="fotos-upload-empty" id="fotos-upload-empty">
        <svg width="32" height="32" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
          <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>
          <polyline points="17 8 12 3 7 8"/>
          <line x1="12" y1="3" x2="12" y2="15"/>
        </svg>
        <p><strong>Clique ou arraste fotos aqui</strong></p>
        <small>JPG, PNG, WEBP · até 8MB · máx. 10 fotos</small>
      </div>

      <div class="fotos-upload-opts">
        <label class="fotos-vis-opt">
          <input type="radio" name="vis_default" value="privado" checked>
          <div>
            <span>🔒 Privadas</span>
            <small>Só você vê</small>
          </div>
        </label>
        <label class="fotos-vis-opt">
          <input type="radio" name="vis_default" value="publico">
          <div>
            <span>🌐 Públicas</span>
            <small>Após aprovação</small>
          </div>
        </label>
      </div>
    </div>

    <!-- Grid de fotos -->
    <div class="fotos-grid" id="fotos-grid"></div>

    <!-- Estado de carregamento -->
    <div class="fotos-loading" id="fotos-loading" hidden>
      <div class="spinner"></div>
      <span>Processando fotos...</span>
    </div>

  </div>
</div>