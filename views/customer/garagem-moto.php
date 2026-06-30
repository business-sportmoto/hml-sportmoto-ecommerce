<?php
$cor       = $moto['cor'] ?: '#1e293b';
$instaAtual = $cliente['insta_cliente'] ?? '';
?>

<?php $presets = ['#dc2626','#1e293b','#2563eb','#16a34a','#f59e0b','#7c3aed','#ec4899','#ffffff']; ?>

<div class="moto-page garagem-actions">

  <!-- ── Breadcrumb + back ─────────────────────────────── -->
  <nav class="moto-breadcrumb">
    <a href="<?= BASE_URL ?>/minha-conta/garagem">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
        <line x1="19" y1="12" x2="5" y2="12"/>
        <polyline points="12 19 5 12 12 5"/>
      </svg>
      Minha Garagem
    </a>
    <span>·</span>
    <span><?= View::e($pageTitle) ?></span>
  </nav>

  <!-- ── Hero da moto ──────────────────────────────────── -->
  <div class="moto-hero" style="--moto-cor:<?= View::e($cor) ?>">
    <div class="moto-hero-bg"></div>

    <div class="moto-hero-content">
      <div class="moto-hero-info">
        <?php if ($moto['principal']): ?>
        <span class="moto-hero-badge">
          <svg width="11" height="11" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="3" stroke-linecap="round">
            <polyline points="20 6 9 17 4 12"/>
          </svg>
          Moto ativa
        </span>
        <?php endif; ?>

        <h1 class="moto-hero-titulo"><?= View::e($moto['apelido'] ?: $moto['montadora_nome']) ?></h1>
        <p class="moto-hero-sub">
          <?= View::e($moto['montadora_nome']) ?>
          <?php if ($moto['modelo_nome']): ?>
            · <?= View::e($moto['modelo_nome']) ?>
          <?php endif; ?>
          <?php if ($moto['ano']): ?>
            · <?= $moto['ano'] ?>
          <?php endif; ?>
        </p>

        <?php if ($moto['placa']): ?>
        <div class="moto-hero-placa">
          <svg width="11" height="11" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <rect x="3" y="6" width="18" height="12" rx="2"/>
          </svg>
          <span><?= View::e(strtoupper($moto['placa'])) ?></span>
        </div>
        <?php endif; ?>

        <?php if (!empty($moto['observacoes'])): ?>
        <p class="moto-hero-obs"><?= nl2br(View::e($moto['observacoes'])) ?></p>
        <?php endif; ?>
      </div>

      <!-- Estatísticas rápidas -->
      <div class="moto-stats">
        <div class="moto-stat">
          <strong><?= $stats['total_fotos'] ?></strong>
          <span>Fotos</span>
        </div>
        <div class="moto-stat">
          <strong><?= $stats['fotos_publicas'] ?></strong>
          <span>Públicas</span>
        </div>
        <?php if ($stats['fotos_pendentes'] > 0): ?>
        <div class="moto-stat moto-stat--alert">
          <strong><?= $stats['fotos_pendentes'] ?></strong>
          <span>Em análise</span>
        </div>
        <?php endif; ?>
      </div>

      <!-- Ações -->
      <div class="moto-hero-actions">
        <?php if (!$moto['principal']): ?>
        <button type="button" class="moto-btn moto-btn--primary btn-ativar-moto"
                id="btn-tornar-ativa" data-id="<?= $moto['id'] ?>">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <polyline points="20 6 9 17 4 12"/>
          </svg>
          Tornar moto ativa
        </button>
        <?php endif; ?>
        <button type="button" class="moto-btn moto-btn--secondary btn-editar-moto" id="btn-editar-info"
                data-id="<?= $moto['id'] ?>"
                data-apelido="<?= View::e($moto['apelido'] ?? '') ?>"
                data-cor="<?= View::e($moto['cor'] ?? '') ?>"
                data-placa="<?= View::e($moto['placa'] ?? '') ?>"
                data-observacoes="<?= View::e($moto['observacoes'] ?? '') ?>">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
            <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
          </svg>
          Editar informações
        </button>
      </div>
    </div>
  </div>

  <!-- ── Card do Instagram (público) ──────────────────── -->
  <div class="moto-section moto-insta-card">
    <div class="moto-insta-header">
      <div class="moto-insta-icon">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <rect x="2" y="2" width="20" height="20" rx="5" ry="5"/>
          <path d="M16 11.37A4 4 0 1112.63 8 4 4 0 0116 11.37z"/>
          <line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/>
        </svg>
      </div>
      <div>
        <h2>Seu Instagram</h2>
        <p>Apareça nas fotos públicas — pessoas vão poder seguir você</p>
      </div>
    </div>

    <!-- Aviso de privacidade -->
    <div class="moto-insta-aviso">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
        <circle cx="12" cy="12" r="10"/>
        <line x1="12" y1="8" x2="12" y2="12"/>
        <line x1="12" y1="16" x2="12.01" y2="16"/>
      </svg>
      <div>
        <strong>Atenção: este campo é público</strong>
        <span>
          Seu @ ficará visível em todas as suas fotos aprovadas no feed do site.
          Não inclua se você não quer divulgar seu Instagram publicamente.
        </span>
      </div>
    </div>

    <form id="form-insta-cliente" class="moto-insta-form">
      <input type="hidden" name="_csrf_token" value="<?= SecurityHelper::generateCsrf() ?>">

      <div class="moto-insta-input-wrap">
        <span class="moto-insta-prefix">@</span>
        <input type="text"
               name="insta_cliente"
               id="input-insta"
               value="<?= View::e(ltrim($instaAtual, '@')) ?>"
               placeholder="seu_usuario"
               maxlength="30"
               pattern="[a-zA-Z0-9._]+"
               autocomplete="off">
        <button type="submit" class="moto-insta-save">Salvar</button>
      </div>
      <small class="moto-insta-help">
        Aceita: <code>@usuario</code>, <code>usuario</code> ou
        link completo do Instagram. Apenas letras, números, "." e "_".
      </small>

      <?php if ($instaAtual): ?>
      <div class="moto-insta-preview" id="moto-insta-preview">
        <span>Vai aparecer nas suas fotos como:</span>
        <a href="https://instagram.com/<?= View::e(ltrim($instaAtual, '@')) ?>"
           target="_blank" rel="noopener">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <rect x="2" y="2" width="20" height="20" rx="5" ry="5"/>
            <path d="M16 11.37A4 4 0 1112.63 8 4 4 0 0116 11.37z"/>
            <line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/>
          </svg>
          <?= View::e($instaAtual) ?>
        </a>
      </div>
      <?php endif; ?>
    </form>
  </div>

  <!-- ── Galeria ───────────────────────────────────────── -->
  <div class="moto-section">
    <div class="moto-section-header">
      <div>
        <h2>Galeria de fotos</h2>
        <p>
          <?= $stats['total_fotos'] ?> de 10 fotos
          <?php if ($stats['fotos_pendentes'] > 0): ?>
            · <span style="color:#f59e0b;font-weight:700;">
              <?= $stats['fotos_pendentes'] ?> em análise
            </span>
          <?php endif; ?>
        </p>
      </div>
      <button type="button" class="moto-btn moto-btn--primary btn-fotos-moto"
              id="btn-add-fotos" data-id="<?= $moto['id'] ?>"
                data-label="<?= View::e($moto['label']) ?>"
              <?= $stats['total_fotos'] >= 10 ? 'disabled' : '' ?>>
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <line x1="12" y1="5" x2="12" y2="19"/>
          <line x1="5"  y1="12" x2="19" y2="12"/>
        </svg>
        Adicionar fotos
      </button>
    </div>

    <input type="file" id="input-fotos-upload" accept="image/jpeg,image/png,image/webp" multiple hidden>

    <?php if (empty($fotos)): ?>
    <div class="moto-galeria-empty">
      <svg width="48" height="48" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="1.2" stroke-linecap="round">
        <rect x="3" y="3" width="18" height="18" rx="2"/>
        <circle cx="8.5" cy="8.5" r="1.5"/>
        <polyline points="21 15 16 10 5 21"/>
      </svg>
      <h3>Nenhuma foto ainda</h3>
      <p>Adicione fotos para mostrar sua moto. Você pode escolher se cada foto será privada ou pública.</p>
    </div>
    <?php else: ?>
    <div class="moto-galeria-grid" id="moto-galeria-grid"
         data-veiculo-id="<?= $moto['id'] ?>">
      <?php foreach ($fotos as $f): ?>
      <article class="galeria-foto"
               data-id="<?= $f['id'] ?>"
               data-vis="<?= $f['visibilidade'] ?>"
               data-status="<?= $f['status_moderacao'] ?>">
        <img src="<?= View::upload('garagem/' . $f['arquivo_medium']) ?>"
             alt="<?= View::e($f['legenda'] ?? '') ?>"
             loading="lazy">

        <!-- Badges -->
        <div class="galeria-foto-badges">
          <?php if ($f['capa']): ?>
          <span class="galeria-badge galeria-badge--capa">
            <svg width="9" height="9" viewBox="0 0 24 24" fill="currentColor">
              <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
            </svg>
            Capa
          </span>
          <?php endif; ?>

          <?php if ($f['visibilidade'] === 'publico'): ?>
            <?php if ($f['status_moderacao'] === 'aprovada'): ?>
            <span class="galeria-badge galeria-badge--publico">Pública</span>
            <?php elseif ($f['status_moderacao'] === 'pendente'): ?>
            <span class="galeria-badge galeria-badge--pendente">Em análise</span>
            <?php else: ?>
            <span class="galeria-badge galeria-badge--rejeitada"
                  title="<?= View::e($f['motivo_rejeicao'] ?? '') ?>">
              Rejeitada
            </span>
            <?php endif; ?>
          <?php else: ?>
          <span class="galeria-badge galeria-badge--privado">Privada</span>
          <?php endif; ?>
        </div>

        <!-- Hover actions -->
        <div class="galeria-foto-overlay">
          <?php if (!$f['capa']): ?>
          <button type="button" class="galeria-action"
                  data-action="capa" title="Tornar capa">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
              <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
            </svg>
          </button>
          <?php endif; ?>

          <button type="button" class="galeria-action"
                  data-action="vis"
                  title="<?= $f['visibilidade'] === 'privado' ? 'Tornar pública' : 'Tornar privada' ?>">
            <?php if ($f['visibilidade'] === 'privado'): ?>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
              <circle cx="12" cy="12" r="10"/>
              <line x1="2" y1="12" x2="22" y2="12"/>
              <path d="M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"/>
            </svg>
            <?php else: ?>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
              <rect x="3" y="11" width="18" height="11" rx="2"/>
              <path d="M7 11V7a5 5 0 0110 0v4"/>
            </svg>
            <?php endif; ?>
          </button>

          <button type="button" class="galeria-action galeria-action--del"
                  data-action="remover" title="Remover">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
              <polyline points="3 6 5 6 21 6"/>
              <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/>
            </svg>
          </button>
        </div>

        <?php if ($f['status_moderacao'] === 'rejeitada' && !empty($f['motivo_rejeicao'])): ?>
        <div class="galeria-foto-motivo">
          <strong>Motivo:</strong> <?= View::e($f['motivo_rejeicao']) ?>
        </div>
        <?php endif; ?>
      </article>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

</div>


<!-- ══ MODAL: Editar moto ═════════════════════════════════ -->
<div class="garagem-modal" id="modal-edit-moto">
  <div class="garagem-modal-backdrop"></div>
  <div class="garagem-modal-content">

    <div class="garagem-modal-header">
      <h2>Editar moto</h2>
      <button type="button" class="garagem-modal-close">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
          <line x1="18" y1="6" x2="6"  y2="18"/>
          <line x1="6"  y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>

    <form id="form-edit-moto" class="garagem-form">
      <input type="hidden" name="_csrf_token" value="<?= SecurityHelper::generateCsrf() ?>">
      <input type="hidden" name="id" id="edit-id">

      <div class="garagem-form-section">
        <div class="garagem-form-group">
          <label>Apelido</label>
          <input type="text" name="apelido" id="edit-apelido" maxlength="80">
        </div>

        <div class="garagem-form-row garagem-form-row--2">
          <div class="garagem-form-group">
            <label>Cor</label>
            <div class="garagem-color-picker">
              <input type="color" name="cor" id="edit-cor" value="#dc2626">
              <div class="garagem-color-presets">
                <?php foreach ($presets as $p): ?>
                <button type="button" class="garagem-color-preset"
                        style="background:<?= $p ?>"
                        data-cor="<?= $p ?>" data-target="#edit-cor"></button>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

          <div class="garagem-form-group">
            <label>Placa</label>
            <input type="text" name="placa" id="edit-placa" maxlength="10"
                   style="text-transform:uppercase">
          </div>
        </div>

        <div class="garagem-form-group">
          <label>Observações</label>
          <textarea name="observacoes" id="edit-observacoes" rows="2"
                    placeholder="Anotações sobre a moto (opcional)"></textarea>
        </div>
      </div>

      <div class="garagem-modal-footer">
        <button type="button" class="btn-cancelar garagem-modal-close">Cancelar</button>
        <button type="submit" class="btn-salvar-moto">Salvar alterações</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ DRAWER: Galeria da moto ════════════════════════════ -->
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